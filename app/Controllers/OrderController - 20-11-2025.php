<?php
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Config.php';

/**
 * OrderController
 * - Daily per-branch numbering handled via daily_counters table (branch_id + counter_date)
 * - order_no pattern: BR{branch}-{YYYYMMDD}-{zero-padded seq}
 * - day_seq column (INT) stores the simple number shown in listing (1,2,3...)
 * - Reset (force) renames existing same-day orders to fallback -R{id} and clears counter so next order starts at 1.
 */
class OrderController {

    /**
     * POST /api/orders/reset-sequence
     * Body: { branch_id, force:0|1 }
     * force=0: if there are existing orders today -> 409 returned
     * force=1: rename today's existing orders + delete counter row
     */
    public static function resetSequence(): void {
        $user = Auth::requireAuth(['ADMIN','MANAGER']);
        $b = body_json();
        $branch_id = (int)($b['branch_id'] ?? ($user['branch_id'] ?? 0));
        $force = !empty($b['force']) ? 1 : 0;
        if ($branch_id <= 0) json_error('branch_id required', 422);

        $pdo = Database::conn();
        $today = $pdo->query("SELECT CURDATE()")->fetchColumn();

        // Count today's orders
        $cntSt = $pdo->prepare("SELECT COUNT(*) AS c FROM orders WHERE branch_id=? AND DATE(created_at)=?");
        $cntSt->execute([$branch_id, $today]);
        $cnt = (int)$cntSt->fetch()['c'];

        if ($cnt > 0 && !$force) {
            header('Content-Type: application/json', true, 409);
            echo json_encode([
                'error' => 'existing_orders',
                'message' => 'Existing orders found',
                'count' => $cnt,
                'branch_id' => $branch_id,
                'date' => $today
            ]);
            return;
        }

        $pdo->beginTransaction();
        try {
            if ($cnt > 0 && $force) {
                // Rename old today's orders to avoid order_no clash with -0001
                $hasDaySeq = self::columnExists($pdo, 'orders', 'day_seq');
                $sql = "
                  UPDATE orders
                  SET order_no = CONCAT('BR', LPAD(branch_id,2,'0'), '-', DATE_FORMAT(created_at,'%Y%m%d'), '-R', id)
                ";
                if ($hasDaySeq) {
                    $sql .= ", day_seq = 0";
                }
                $sql .= " WHERE branch_id=? AND DATE(created_at)=?";
                $ren = $pdo->prepare($sql);
                $ren->execute([$branch_id, $today]);
            }

            // Ensure daily_counters table exists then delete today's counter row
            $pdo->exec("
              CREATE TABLE IF NOT EXISTS daily_counters (
                branch_id INT NOT NULL,
                counter_date DATE NOT NULL,
                last_seq INT NOT NULL DEFAULT 0,
                PRIMARY KEY (branch_id, counter_date)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            $del = $pdo->prepare("DELETE FROM daily_counters WHERE branch_id=? AND counter_date=?");
            $del->execute([$branch_id, $today]);

            $pdo->commit();
            json_response([
                'reset' => true,
                'branch_id' => $branch_id,
                'date' => $today,
                'renamed_existing' => ($cnt > 0 && $force),
                'existing_count' => $cnt
            ]);
        } catch (Throwable $e) {
            try { $pdo->rollBack(); } catch (Throwable $_) {}
            json_error('failed to reset sequence', 500, $e->getMessage());
        }
    }

    /**
     * POST /api/orders
     * Create new order with atomic daily sequence.
     */
    public static function createOrder(): void {
        $user = Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $b = body_json();

        $branch_id = (int)($b['branch_id'] ?? ($user['branch_id'] ?? 0));
        if ($branch_id <= 0) json_error('branch_id required', 422);

        $order_type = strtoupper(trim((string)($b['order_type'] ?? 'TAKEAWAY')));
        if (!in_array($order_type, ['TAKEAWAY','DELIVERY','DINEIN','PICKUP'], true)) {
            $order_type = 'TAKEAWAY';
        }

        $customer_phone = isset($b['customer_phone']) ? preg_replace('/[^0-9+]/', '', trim($b['customer_phone'])) : '';
        $notes = isset($b['notes']) ? trim((string)$b['notes']) : null;

        $pdo = Database::conn();

        // Ensure daily_counters table exists (safe if already made)
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS daily_counters (
            branch_id INT NOT NULL,
            counter_date DATE NOT NULL,
            last_seq INT NOT NULL DEFAULT 0,
            PRIMARY KEY (branch_id, counter_date)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Optional customer upsert
        $customer_id = null;
        if ($customer_phone !== '') {
            $c = $pdo->prepare("SELECT id FROM customers WHERE phone=? LIMIT 1");
            $c->execute([$customer_phone]);
            $row = $c->fetch();
            if ($row) {
                $customer_id = (int)$row['id'];
            } else {
                $insC = $pdo->prepare("INSERT INTO customers (name, phone) VALUES (?, ?)");
                $insC->execute([$customer_phone, $customer_phone]);
                $customer_id = (int)$pdo->lastInsertId();
            }
        }

        try {
            $pdo->beginTransaction();

            // Atomic next sequence for (branch_id, today)
            // IMPORTANT FIX: use LAST_INSERT_ID(1) in INSERT path so PDO::lastInsertId() returns 1 on first row of the day
            $seqStmt = $pdo->prepare("
              INSERT INTO daily_counters (branch_id, counter_date, last_seq)
              VALUES (?, CURDATE(), LAST_INSERT_ID(1))
              ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)
            ");
            $seqStmt->execute([$branch_id]);
            $daySeq = (int)$pdo->lastInsertId(); // 1,2,3,...

            $dateStr = $pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y%m%d')")->fetchColumn();
            $order_no = sprintf('BR%02d-%s-%04d', $branch_id, $dateStr, $daySeq);

            // Rare duplicate guard (manual tampering scenario)
            $dupCheck = $pdo->prepare("SELECT 1 FROM orders WHERE order_no=? LIMIT 1");
            $dupCheck->execute([$order_no]);
            if ($dupCheck->fetch()) {
                // Force one more increment
                $seqStmt->execute([$branch_id]);
                $daySeq = (int)$pdo->lastInsertId();
                $order_no = sprintf('BR%02d-%s-%04d', $branch_id, $dateStr, $daySeq);
            }

            // Ensure day_seq column exists (in case migration not run)
            self::ensureDaySeqColumn($pdo);

            $ins = $pdo->prepare("
              INSERT INTO orders
                (branch_id, order_no, day_seq, order_type, status, customer_id, customer_phone, cashier_id, created_by, notes,
                 subtotal, discount_total, service_charge, tax_total, delivery_fee, grand_total,
                 opened_at, created_at)
              VALUES
                (?, ?, ?, ?, 'OPEN', ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, NOW(), NOW())
            ");
            $ins->execute([
                $branch_id,
                $order_no,
                $daySeq,
                $order_type,
                $customer_id,
                ($customer_phone ?: null),
                $user['id'],
                $user['id'],
                $notes
            ]);

            $order_id = (int)$pdo->lastInsertId();
            $pdo->commit();

            json_response([
                'order_id' => $order_id,
                'order_no' => $order_no,
                'day_seq' => $daySeq
            ]);
        } catch (Throwable $e) {
            try { $pdo->rollBack(); } catch (Throwable $_) {}
            json_error('failed to create order', 500, $e->getMessage());
        }
    }

    /**
     * GET /api/orders
     * Optional query params: status, branch_id, limit
     */
    public static function list(): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $pdo = Database::conn();
        $status   = isset($_GET['status']) ? strtoupper(trim((string)$_GET['status'])) : null;
        $branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
        $limit    = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;

        $where = [];
        $args  = [];

        if ($status && in_array($status, ['OPEN','KITCHEN','READY','PAID','PARTIAL','VOID','CANCELLED','REFUNDED'], true)) {
            $where[] = "o.status = ?";
            $args[]  = $status;
        }
        if ($branchId > 0) {
            $where[] = "o.branch_id = ?";
            $args[] = $branchId;
        }

        $sql = "
          SELECT
            o.id, o.order_no, o.day_seq, o.order_type, o.status, o.branch_id,
            o.created_at, o.opened_at, o.closed_at,
            o.subtotal, o.discount_total, o.service_charge, o.tax_total, o.delivery_fee, o.grand_total,
            o.customer_id,
            COALESCE(o.customer_phone, c.phone) AS customer_phone
          FROM orders o
          LEFT JOIN customers c ON c.id = o.customer_id
          " . (count($where) ? "WHERE " . implode(" AND ", $where) : "") . "
          ORDER BY o.id DESC
          LIMIT $limit
        ";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        json_response(['data' => $st->fetchAll()]);
    }

    /**
     * GET /api/orders/{id}
     */
    public static function getOrder($orderId): void {
        Auth::requireAuth();
        $orderId = (int)$orderId;
        $pdo = Database::conn();

        $o = $pdo->prepare("SELECT * FROM orders WHERE id=? LIMIT 1");
        $o->execute([$orderId]);
        $order = $o->fetch();
        if (!$order) json_error('not found', 404);

        $it = $pdo->prepare("SELECT * FROM order_items WHERE order_id=? ORDER BY id");
        $it->execute([$orderId]);
        $items = $it->fetchAll();

        $pay = $pdo->prepare("SELECT * FROM payments WHERE order_id=? ORDER BY id");
        $pay->execute([$orderId]);
        $payments = $pay->fetchAll();

        json_response(['order' => $order, 'items' => $items, 'payments' => $payments]);
    }

    /**
     * POST /api/orders/{id}/items
     */
    public static function addItem($orderId): void {
        $user = Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $orderId = (int)$orderId;
        $b = body_json();
        $item_id = (int)($b['item_id'] ?? 0);
        $qty = (float)($b['qty'] ?? 1);
        $line_discount = decimal($b['line_discount'] ?? 0);

        if ($orderId <= 0 || $item_id <= 0 || $qty <= 0) json_error('order_id, item_id, qty required', 422);

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $o = $pdo->prepare("SELECT * FROM orders WHERE id=? AND status='OPEN' LIMIT 1");
            $o->execute([$orderId]);
            $order = $o->fetch();
            if (!$order) json_error('Order not open or not found', 422);

            $mi = $pdo->prepare("SELECT id, name, price FROM menu_items WHERE id=? AND active=1 LIMIT 1");
            $mi->execute([$item_id]);
            $item = $mi->fetch();
            if (!$item) json_error('Item not found/active', 422);

            $unit_price = decimal($item['price']);

            // Try merge logic
            $find = $pdo->prepare("
              SELECT id, qty, unit_price, line_discount
              FROM order_items
              WHERE order_id=? AND item_id=? AND line_discount=0
                AND ABS(unit_price - ?) < 0.00001
              ORDER BY id LIMIT 1
            ");
            $find->execute([$orderId, $item_id, $unit_price]);
            $existing = $find->fetch();

            $merged = false;
            if ($existing && $line_discount == 0.0) {
                $newQty = (float)$existing['qty'] + $qty;
                $newLineTotal = decimal(($newQty * (float)$unit_price) - (float)$existing['line_discount']);
                $upd = $pdo->prepare("UPDATE order_items SET qty=?, unit_price=?, line_total=? WHERE id=?");
                $upd->execute([$newQty, $unit_price, $newLineTotal, (int)$existing['id']]);
                $merged = true;
            } else {
                $line_total = decimal(($qty * $unit_price) - $line_discount);
                $ins = $pdo->prepare("
                  INSERT INTO order_items (order_id,item_id,is_deal,deal_id,item_name_snapshot,qty,unit_price,line_discount,line_total)
                  VALUES (?,?,0,NULL,?,?,?,?,?)
                ");
                $ins->execute([$orderId, $item_id, $item['name'], $qty, $unit_price, $line_discount, $line_total]);
            }

            self::recalcTotalsInternal($pdo, $orderId, [
                'delivery_fee' => (float)$order['delivery_fee'],
                'service_charge_percent' => (float)Config::SERVICE_CHARGE_PERCENT,
                'tax_rate_percent' => (float)Config::TAX_RATE_PERCENT,
            ]);

            $pdo->commit();
            json_response(['added' => true, 'merged' => $merged]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('failed to add item', 500, $e->getMessage());
        }
    }

    /**
     * PATCH /api/orders/{orderId}/items/{orderItemId}
     */
    public static function updateOrderItem($orderId, $orderItemId): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $orderId = (int)$orderId;
        $orderItemId = (int)$orderItemId;

        $b = body_json();
        $qty = isset($b['qty']) ? (float)$b['qty'] : null;
        $line_discount = isset($b['line_discount']) ? decimal($b['line_discount']) : null;

        if ($orderId <= 0 || $orderItemId <= 0) json_error('order_id and order_item_id required', 422);
        if ($qty === null && $line_discount === null) json_error('nothing to update', 422);
        if ($qty !== null && $qty <= 0) json_error('qty must be > 0', 422);
        if ($line_discount !== null && $line_discount < 0) json_error('line_discount must be >= 0', 422);

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $o = $pdo->prepare("SELECT * FROM orders WHERE id=? AND status='OPEN' LIMIT 1");
            $o->execute([$orderId]);
            $order = $o->fetch();
            if (!$order) json_error('Order not open or not found', 422);

            $li = $pdo->prepare("SELECT * FROM order_items WHERE id=? AND order_id=? LIMIT 1");
            $li->execute([$orderItemId, $orderId]);
            $row = $li->fetch();
            if (!$row) json_error('Order item not found', 404);

            $newQty  = ($qty !== null) ? $qty : (float)$row['qty'];
            $newDisc = ($line_discount !== null) ? (float)$line_discount : (float)$row['line_discount'];
            $unit    = (float)$row['unit_price'];
            $gross   = $newQty * $unit;
            if ($newDisc > $gross) $newDisc = $gross;
            $newLineTotal = decimal($gross - $newDisc);

            $u = $pdo->prepare("UPDATE order_items SET qty=?, line_discount=?, line_total=? WHERE id=? AND order_id=?");
            $u->execute([$newQty, decimal($newDisc), $newLineTotal, $orderItemId, $orderId]);

            self::recalcTotalsInternal($pdo, $orderId, [
                'delivery_fee' => (float)$order['delivery_fee'],
                'service_charge_percent' => (float)Config::SERVICE_CHARGE_PERCENT,
                'tax_rate_percent' => (float)Config::TAX_RATE_PERCENT,
            ]);

            $pdo->commit();
            json_response(['updated' => true]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('failed to update item', 500, $e->getMessage());
        }
    }

    /**
     * DELETE /api/orders/{orderId}/items/{orderItemId}
     */
    public static function removeItem($orderId, $orderItemId): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $orderId = (int)$orderId;
        $orderItemId = (int)$orderItemId;

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $o = $pdo->prepare("SELECT * FROM orders WHERE id=? AND status='OPEN' LIMIT 1");
            $o->execute([$orderId]);
            $order = $o->fetch();
            if (!$order) json_error('Order not open or not found', 422);

            $del = $pdo->prepare("DELETE FROM order_items WHERE id=? AND order_id=?");
            $del->execute([$orderItemId, $orderId]);

            self::recalcTotalsInternal($pdo, $orderId, [
                'delivery_fee' => (float)$order['delivery_fee'],
                'service_charge_percent' => (float)Config::SERVICE_CHARGE_PERCENT,
                'tax_rate_percent' => (float)Config::TAX_RATE_PERCENT,
            ]);

            $pdo->commit();
            json_response(['removed' => true]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('failed to remove item', 500, $e->getMessage());
        }
    }

    /**
     * POST /api/orders/{id}/charges
     */
    public static function setCharges($orderId): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $orderId = (int)$orderId;
        $b = body_json();
        $servicePct = isset($b['service_charge_percent']) ? (float)$b['service_charge_percent'] : (float)Config::SERVICE_CHARGE_PERCENT;
        $taxPct     = isset($b['tax_rate_percent']) ? (float)$b['tax_rate_percent'] : (float)Config::TAX_RATE_PERCENT;
        $deliveryFee = decimal($b['delivery_fee'] ?? 0);

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $u = $pdo->prepare("UPDATE orders SET delivery_fee=? WHERE id=? AND status='OPEN'");
            $u->execute([$deliveryFee, $orderId]);

            self::recalcTotalsInternal($pdo, $orderId, [
                'delivery_fee' => (float)$deliveryFee,
                'service_charge_percent' => $servicePct,
                'tax_rate_percent' => $taxPct,
            ]);
            $pdo->commit();
            json_response(['updated' => true]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('failed to set charges', 500, $e->getMessage());
        }
    }

    /**
     * POST /api/orders/{id}/send-to-kitchen
     */
    public static function sendToKitchen($orderId): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $orderId = (int)$orderId;

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $o = $pdo->prepare("SELECT id, status FROM orders WHERE id=? LIMIT 1");
            $o->execute([$orderId]);
            $order = $o->fetch();
            if (!$order) json_error('Order not found', 404);

            $status = strtoupper((string)$order['status']);
            if ($status === 'KITCHEN') {
                $pdo->commit();
                json_response(['sent' => true, 'already' => true]);
                return;
            }
            if (in_array($status, ['READY','PAID','VOID','CANCELLED'], true)) {
                json_error('Order cannot be sent to kitchen in current status: ' . $status, 422);
            }

            // Must have items
            $cnt = $pdo->prepare("SELECT COUNT(*) AS c FROM order_items WHERE order_id=?");
            $cnt->execute([$orderId]);
            if ((int)$cnt->fetch()['c'] <= 0) {
                $pdo->rollBack();
                json_error('Order has no items', 422);
            }

            $sql = "UPDATE orders SET status='KITCHEN'";
            $col = $pdo->query("SHOW COLUMNS FROM orders LIKE 'kitchen_at'")->fetch();
            if ($col) $sql .= ", kitchen_at=NOW()";
            $sql .= " WHERE id=?";

            $u = $pdo->prepare($sql);
            $u->execute([$orderId]);

            $pdo->commit();
            json_response(['sent' => true]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('failed to send to kitchen', 500, $e->getMessage());
        }
    }

    /**
     * POST /api/orders/{id}/ready
     */
    public static function markReady($orderId): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $orderId = (int)$orderId;

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $o = $pdo->prepare("SELECT id, status FROM orders WHERE id=? LIMIT 1");
            $o->execute([$orderId]);
            $order = $o->fetch();
            if (!$order) json_error('Order not found', 404);

            $status = strtoupper((string)$order['status']);
            if ($status === 'READY') {
                $pdo->commit();
                json_response(['ready' => true, 'already' => true]);
                return;
            }
            if (!in_array($status, ['KITCHEN','PARTIAL','OPEN'], true)) {
                json_error('Order cannot be marked READY from status: ' . $status, 422);
            }

            $sql = "UPDATE orders SET status='READY'";
            $col = $pdo->query("SHOW COLUMNS FROM orders LIKE 'ready_at'")->fetch();
            if ($col) $sql .= ", ready_at=NOW()";
            $sql .= " WHERE id=?";

            $u = $pdo->prepare($sql);
            $u->execute([$orderId]);

            $pdo->commit();
            json_response(['ready' => true]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('failed to mark ready', 500, $e->getMessage());
        }
    }

    /**
     * POST /api/orders/{id}/pay
     */
    public static function pay($orderId): void {
        $user = Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $orderId = (int)$orderId;
        $b = body_json();
        $method = strtoupper(trim((string)($b['method'] ?? 'CASH')));
        $amount = (float)($b['amount'] ?? 0);
        $reference = isset($b['reference']) ? trim((string)$b['reference']) : null;

        if (!in_array($method, ['CASH','CARD','WALLET','QR','SPLIT'], true)) json_error('invalid method', 422);
        if ($amount <= 0) json_error('amount required', 422);

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $o = $pdo->prepare("SELECT status, grand_total FROM orders WHERE id=? LIMIT 1");
            $o->execute([$orderId]);
            $order = $o->fetch();
            if (!$order) json_error('Order not found', 404);

            $status = strtoupper((string)$order['status']);
            if (in_array($status, ['PAID','VOID','CANCELLED'], true)) {
                json_error('Order not payable', 422);
            }

            $ins = $pdo->prepare("INSERT INTO payments (order_id, method, amount, reference, created_at) VALUES (?,?,?,?,NOW())");
            $ins->execute([$orderId, $method, decimal($amount), $reference]);

            $sum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE order_id=?");
            $sum->execute([$orderId]);
            $paid = (float)$sum->fetch()['paid'];

            $grand = (float)$order['grand_total'];
            if ($paid + 0.0001 >= $grand) {
                $upd = $pdo->prepare("UPDATE orders SET status='PAID', closed_at=NOW(), cashier_id=? WHERE id=?");
                $upd->execute([$user['id'], $orderId]);
            } else if ($paid > 0 && $status !== 'PARTIAL') {
                $upd = $pdo->prepare("UPDATE orders SET status='PARTIAL', cashier_id=? WHERE id=?");
                $upd->execute([$user['id'], $orderId]);
            }

            $pdo->commit();
            json_response(['paid_total' => decimal($paid)]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('payment failed', 500, $e->getMessage());
        }
    }

    /**
     * POST /api/orders/{id}/deals
     * Add a saved/custom deal to order
     */
    public static function addDeal($orderId): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $orderId = (int)$orderId;
        $b = body_json();

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            $o = $pdo->prepare("SELECT * FROM orders WHERE id=? AND status='OPEN' LIMIT 1");
            $o->execute([$orderId]);
            $order = $o->fetch();
            if (!$order) json_error('Order not open or not found', 422);

            $qtyDeal = (float)($b['qty'] ?? 1);
            if ($qtyDeal <= 0) $qtyDeal = 1;

            $dealId = isset($b['deal_id']) ? (int)$b['deal_id'] : 0;
            $dealName = null;
            $dealPrice = null;
            $components = [];

            if ($dealId > 0) {
                $d = $pdo->prepare("SELECT * FROM deals WHERE id=? AND active=1 LIMIT 1");
                $d->execute([$dealId]);
                $hdr = $d->fetch();
                if (!$hdr) json_error('deal not found/active', 422);
                $dealName = (string)$hdr['name'];
                $dealPrice = (float)$hdr['price'];

                $it = $pdo->prepare("
                  SELECT di.qty, mi.id AS menu_item_id, mi.name AS item_name
                  FROM deal_items di
                  JOIN menu_items mi ON mi.id = di.menu_item_id
                  WHERE di.deal_id = ?
                  ORDER BY di.id
                ");
                $it->execute([$dealId]);
                $components = $it->fetchAll();
            } else {
                $dealName = trim((string)($b['name'] ?? 'Custom Deal'));
                $dealPrice = decimal($b['price'] ?? 0);
                if ($dealPrice <= 0) json_error('deal price required', 422);
                $items = is_array($b['items'] ?? null) ? $b['items'] : [];
                if (!$items) json_error('deal items required', 422);

                foreach ($items as $it) {
                    $menu_item_id = (int)($it['menu_item_id'] ?? 0);
                    $qty = (float)($it['qty'] ?? 1);
                    if ($menu_item_id <= 0 || $qty <= 0) continue;
                    $mi = $pdo->prepare("SELECT name FROM menu_items WHERE id=? LIMIT 1");
                    $mi->execute([$menu_item_id]);
                    $row = $mi->fetch();
                    if ($row) {
                        $components[] = [
                            'menu_item_id' => $menu_item_id,
                            'item_name' => $row['name'],
                            'qty' => $qty
                        ];
                    }
                }
                if (!$components) json_error('invalid deal items', 422);
            }

            // Snapshot building
            $title = "Deal: {$dealName}";
            $listTxt = implode(', ', array_map(function ($c) {
                return ($c['item_name'] ?? 'Item') . " x" . (float)$c['qty'];
            }, $components));
            $item_name_snapshot = $listTxt ? ($title . " — " . $listTxt) : $title;

            $snapshot = json_encode([
                'name' => $dealName,
                'price' => (float)$dealPrice,
                'items' => array_map(function ($c) {
                    return [
                        'menu_item_id' => (int)$c['menu_item_id'],
                        'item_name' => (string)$c['item_name'],
                        'qty' => (float)$c['qty']
                    ];
                }, $components)
            ], JSON_UNESCAPED_UNICODE);

            $unit = decimal($dealPrice);
            $line_total = decimal($unit * $qtyDeal);
            $hasSnapshot = self::columnExists($pdo, 'order_items', 'deal_snapshot');

            if ($hasSnapshot) {
                $ins = $pdo->prepare("
                  INSERT INTO order_items
                    (order_id,item_id,is_deal,deal_id,item_name_snapshot,deal_snapshot,qty,unit_price,line_discount,line_total)
                  VALUES (?,?,?,?,?,?,?,?,0,?)
                ");
                $ins->execute([$orderId, NULL, 1, $dealId ?: null, $item_name_snapshot, $snapshot, $qtyDeal, $unit, $line_total]);
            } else {
                $ins = $pdo->prepare("
                  INSERT INTO order_items
                    (order_id,item_id,is_deal,deal_id,item_name_snapshot,qty,unit_price,line_discount,line_total)
                  VALUES (?,?,?,?,?,?,?,0,?)
                ");
                $ins->execute([$orderId, NULL, 1, $dealId ?: null, $item_name_snapshot, $qtyDeal, $unit, $line_total]);
            }

            self::recalcTotalsInternal($pdo, $orderId, [
                'delivery_fee' => (float)$order['delivery_fee'],
                'service_charge_percent' => (float)Config::SERVICE_CHARGE_PERCENT,
                'tax_rate_percent' => (float)Config::TAX_RATE_PERCENT,
            ]);

            $pdo->commit();
            json_response(['added' => true]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('failed to add deal', 500, $e->getMessage());
        }
    }

    /* ---------- Helper Methods ---------- */

    private static function ensureDaySeqColumn(PDO $pdo): void {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        $st = $pdo->prepare("
          SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'orders'
            AND COLUMN_NAME = 'day_seq'
        ");
        $st->execute();
        if ((int)$st->fetchColumn() === 0) {
            // Attempt to add on the fly (safe)
            try {
                $pdo->exec("ALTER TABLE orders ADD COLUMN day_seq INT NOT NULL DEFAULT 0 AFTER order_no");
            } catch (Throwable $e) {
                // Ignore if fails (permissions); numbering will still work but list may not show day_seq
            }
        }
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool {
        $st = $pdo->prepare("
          SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
        ");
        $st->execute([$table, $column]);
        return ((int)$st->fetchColumn()) > 0;
    }

    private static function recalcTotalsInternal(PDO $pdo, int $orderId, array $opts): void {
        $items = $pdo->prepare("SELECT qty, unit_price, line_discount FROM order_items WHERE order_id=?");
        $items->execute([$orderId]);
        $rows = $items->fetchAll();

        $subtotal = 0.0;
        $discount_total = 0.0;
        foreach ($rows as $r) {
            $subtotal += ((float)$r['qty'] * (float)$r['unit_price']);
            $discount_total += (float)$r['line_discount'];
        }

        $delivery_fee = (float)($opts['delivery_fee'] ?? 0);
        $service_base = max(0, $subtotal - $discount_total);
        $service_charge = ((float)($opts['service_charge_percent'] ?? 0) / 100.0) * $service_base;
        $tax_base = max(0, $service_base + $service_charge);
        $tax_total = ((float)($opts['tax_rate_percent'] ?? 0) / 100.0) * $tax_base;
        $grand = max(0, $service_base + $service_charge + $tax_total + $delivery_fee);

        $upd = $pdo->prepare("
          UPDATE orders
          SET subtotal=?, discount_total=?, tax_total=?, service_charge=?, delivery_fee=?, grand_total=?
          WHERE id=?
        ");
        $upd->execute([
            decimal($subtotal),
            decimal($discount_total),
            decimal($tax_total),
            decimal($service_charge),
            decimal($delivery_fee),
            decimal($grand),
            $orderId
        ]);
    }
}