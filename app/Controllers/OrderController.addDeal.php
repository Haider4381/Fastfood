<?php
// Add to your existing OrderController class

public static function addDeal($orderId): void {
    Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
    $orderId = (int)$orderId;
    $b = body_json();

    $pdo = Database::conn();
    $pdo->beginTransaction();
    try{
        // Verify order OPEN
        $o = $pdo->prepare("SELECT * FROM orders WHERE id=? AND status='OPEN' LIMIT 1");
        $o->execute([$orderId]);
        $order = $o->fetch();
        if (!$order) json_error('Order not open or not found', 422);

        $qtyDeal = (float)($b['qty'] ?? 1);
        if ($qtyDeal <= 0) $qtyDeal = 1;

        $dealName = null;
        $dealId = null;
        $dealPrice = null;
        $components = [];

        if (!empty($b['deal_id'])) {
            // predefined deal
            $dealId = (int)$b['deal_id'];
            $d = $pdo->prepare("SELECT * FROM deals WHERE id=? AND active=1 LIMIT 1");
            $d->execute([$dealId]);
            $hdr = $d->fetch();
            if (!$hdr) json_error('deal not found/active', 422);
            $dealName = (string)$hdr['name'];
            $dealPrice = (float)$hdr['price'];

            $it = $pdo->prepare("SELECT di.qty, mi.id AS menu_item_id, mi.name AS item_name
                                 FROM deal_items di
                                 JOIN menu_items mi ON mi.id = di.menu_item_id
                                 WHERE di.deal_id = ?
                                 ORDER BY di.id");
            $it->execute([$dealId]);
            $components = $it->fetchAll();
        } else {
            // ad-hoc deal from POS
            $dealName = trim((string)($b['name'] ?? 'Custom Deal'));
            $dealPrice = decimal($b['price'] ?? 0);
            if ($dealPrice <= 0) json_error('deal price required', 422);
            $items = is_array($b['items'] ?? null) ? $b['items'] : [];
            if (!$items) json_error('deal items required', 422);

            // hydrate names for snapshot
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

        // Compose a compact snapshot
        $listTxt = implode(', ', array_map(function($c){
            $n = isset($c['item_name']) ? $c['item_name'] : (isset($c['item_name_snapshot'])?$c['item_name_snapshot']:'Item');
            $q = (float)($c['qty'] ?? 1);
            return "{$n} x{$q}";
        }, $components));
        $title = "Deal: {$dealName}";
        $nameSnapshot = $listTxt ? ($title . " — " . $listTxt) : $title;

        // JSON snapshot
        $jsonSnapshot = json_encode([
            'name' => $dealName,
            'price' => (float)$dealPrice,
            'items' => array_map(function($c){
                return [
                    'menu_item_id' => (int)($c['menu_item_id'] ?? 0),
                    'item_name'    => (string)($c['item_name'] ?? ''),
                    'qty'          => (float)($c['qty'] ?? 1)
                ];
            }, $components)
        ], JSON_UNESCAPED_UNICODE);

        // Insert single line in order_items as the deal
        $unit = decimal($dealPrice);
        $line_total = decimal($unit * $qtyDeal);

        // If order_items.deal_snapshot does not exist, fallback without it
        $hasSnapshot = (function() use ($pdo){
            $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_items' AND COLUMN_NAME='deal_snapshot'");
            $st->execute();
            return ((int)$st->fetchColumn()) > 0;
        })();

        if ($hasSnapshot) {
            $ins = $pdo->prepare("
                INSERT INTO order_items
                    (order_id, item_id, is_deal, deal_id, item_name_snapshot, deal_snapshot, qty, unit_price, line_discount, line_total)
                VALUES (?, NULL, 1, ?, ?, ?, ?, ?, 0, ?)
            ");
            $ins->execute([$orderId, $dealId ?: null, $nameSnapshot, $jsonSnapshot, $qtyDeal, $unit, $line_total]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO order_items
                    (order_id, item_id, is_deal, deal_id, item_name_snapshot, qty, unit_price, line_discount, line_total)
                VALUES (?, NULL, 1, ?, ?, ?, ?, 0, ?)
            ");
            $ins->execute([$orderId, $dealId ?: null, $nameSnapshot, $qtyDeal, $unit, $line_total]);
        }

        // Recalc totals
        self::recalcTotalsInternal($pdo, $orderId, [
            'delivery_fee' => isset($order['delivery_fee']) ? (float)$order['delivery_fee'] : 0,
            'service_charge_percent' => (float)Config::SERVICE_CHARGE_PERCENT,
            'tax_rate_percent' => (float)Config::TAX_RATE_PERCENT,
        ]);

        $pdo->commit();
        json_response(['added' => true, 'deal' => ['name'=>$dealName, 'price'=>$unit]]);
    }catch(Throwable $e){
        $pdo->rollBack();
        json_error('failed to add deal', 500, $e->getMessage());
    }
}