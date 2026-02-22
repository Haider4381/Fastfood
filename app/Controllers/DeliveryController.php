<?php
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../Helpers.php';

class DeliveryController {
    // Create/update delivery details for an order
    public static function upsert(int $orderId): void {
        $user = Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $pdo = Database::conn();

        // Ensure order exists and is DELIVERY type
        $ord = $pdo->prepare("SELECT id, order_type FROM orders WHERE id = ? LIMIT 1");
        $ord->execute([$orderId]);
        $order = $ord->fetch();
        if (!$order) json_error('Order not found', 404);
        if (strtoupper($order['order_type']) !== 'DELIVERY') {
            // allow converting to delivery silently if needed
            $updType = $pdo->prepare("UPDATE orders SET order_type='DELIVERY' WHERE id = ?");
            $updType->execute([$orderId]);
        }

        $b = body_json();

        // Allowlist of columns we accept
        $allowed = [
            'customer_name','customer_phone',
            'address_line1','address_line2','area','city','notes',
            'rider_name','rider_phone',
            'status','delivery_fee'
        ];

        // Build data array from allowed keys
        $data = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $b)) {
                $data[$k] = ($k === 'delivery_fee' && $b[$k] !== null && $b[$k] !== '') ? decimal($b[$k]) : ($b[$k] ?? null);
            }
        }

        // Check if a delivery row exists
        $chk = $pdo->prepare("SELECT order_id FROM deliveries WHERE order_id = ? LIMIT 1");
        $chk->execute([$orderId]);
        $exists = (bool)$chk->fetch();

        if ($exists) {
            // UPDATE dynamic
            if (empty($data)) {
                json_response(['order_id' => (int)$orderId, 'updated' => false]);
                return;
            }
            $cols = [];
            $vals = [];
            foreach ($data as $k => $v) {
                $cols[] = "$k = ?";
                $vals[] = $v;
            }
            $sql = "UPDATE deliveries SET " . implode(', ', $cols) . ", updated_at = NOW() WHERE order_id = ?";
            $vals[] = $orderId;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($vals);
            json_response(['order_id' => (int)$orderId, 'updated' => true]);
        } else {
            // INSERT dynamic
            $cols = array_keys($data);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $vals = array_values($data);

            $sql = "INSERT INTO deliveries (order_id" . (count($cols) ? ',' . implode(',', $cols) : '') . ", created_at, updated_at)
                    VALUES (? " . (count($cols) ? ',' . $placeholders : '') . ", NOW(), NOW())";
            array_unshift($vals, $orderId);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($vals);
            json_response(['order_id' => (int)$orderId, 'created' => true]);
        }
    }

    // Fetch delivery details for an order
    public static function get(int $orderId): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $pdo = Database::conn();
        $stmt = $pdo->prepare("SELECT * FROM deliveries WHERE order_id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        if (!$row) json_error('Delivery not found', 404);
        json_response(['delivery' => $row]);
    }

    // List recent delivery orders (joined with orders)
    public static function list(): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $pdo = Database::conn();
        $rows = $pdo->query("
            SELECT
              o.id,
              o.order_no,
              o.status AS order_status,
              o.created_at,
              d.customer_name,
              d.area,
              d.delivery_fee
            FROM orders o
            LEFT JOIN deliveries d ON d.order_id = o.id
            WHERE o.order_type = 'DELIVERY'
            ORDER BY o.id DESC
            LIMIT 200
        ")->fetchAll();
        json_response(['data' => $rows]);
    }
}