<?php
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../Helpers.php';

class CashierController {
    public static function open(): void {
        $user = Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $b = body_json();
        $branch_id = (int)($b['branch_id'] ?? ($user['branch_id'] ?? 0));
        $opening_float = decimal($b['opening_float'] ?? 0);
        if ($branch_id <= 0) json_error('branch_id required', 422);

        $pdo = Database::conn();
        $chk = $pdo->prepare("SELECT id FROM cashier_sessions WHERE cashier_id = ? AND status = 'OPEN' LIMIT 1");
        $chk->execute([$user['id']]);
        if ($chk->fetch()) json_error('You already have an open session', 409);

        $ins = $pdo->prepare("
            INSERT INTO cashier_sessions (branch_id, cashier_id, opened_at, opening_float, status)
            VALUES (?, ?, NOW(), ?, 'OPEN')
        ");
        $ins->execute([$branch_id, $user['id'], $opening_float]);

        json_response(['session_id' => (int)$pdo->lastInsertId()]);
    }

    public static function close(): void {
        $user = Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $b = body_json();
        $payouts = decimal($b['payouts'] ?? 0);

        $pdo = Database::conn();
        $s = $pdo->prepare("SELECT * FROM cashier_sessions WHERE cashier_id = ? AND status = 'OPEN' ORDER BY id DESC LIMIT 1");
        $s->execute([$user['id']]);
        $session = $s->fetch();
        if (!$session) json_error('No open session', 404);

        $sum = $pdo->prepare("
            SELECT COALESCE(SUM(p.amount),0) AS cash_sales
            FROM payments p
            JOIN orders o ON o.id = p.order_id
            WHERE p.method = 'CASH'
              AND o.cashier_id = ?
              AND p.created_at >= ?
        ");
        $sum->execute([$user['id'], $session['opened_at']]);
        $cashSales = (float)$sum->fetch()['cash_sales'];

        $closing_balance = (float)$session['opening_float'] + $cashSales - (float)$payouts;

        $upd = $pdo->prepare("
            UPDATE cashier_sessions
            SET closed_at = NOW(), cash_sales = ?, payouts = ?, closing_balance = ?, status = 'CLOSED'
            WHERE id = ?
        ");
        $upd->execute([decimal($cashSales), $payouts, decimal($closing_balance), (int)$session['id']]);

        json_response([
            'closed' => true,
            'session_id' => (int)$session['id'],
            'cash_sales' => decimal($cashSales),
            'opening_float' => decimal($session['opening_float']),
            'payouts' => decimal($payouts),
            'closing_balance' => decimal($closing_balance),
        ]);
    }

    // NEW: Get current open session + live cash sales
    public static function current(): void {
        $user = Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $pdo = Database::conn();

        $s = $pdo->prepare("SELECT * FROM cashier_sessions WHERE cashier_id = ? AND status = 'OPEN' ORDER BY id DESC LIMIT 1");
        $s->execute([$user['id']]);
        $session = $s->fetch();

        if (!$session) {
            json_response(['session' => null, 'metrics' => null]);
            return;
        }

        $sum = $pdo->prepare("
            SELECT COALESCE(SUM(p.amount),0) AS cash_sales
            FROM payments p
            JOIN orders o ON o.id = p.order_id
            WHERE p.method = 'CASH'
              AND o.cashier_id = ?
              AND p.created_at >= ?
        ");
        $sum->execute([$user['id'], $session['opened_at']]);
        $cashSales = (float)$sum->fetch()['cash_sales'];

        $metrics = [
            'opening_float' => decimal($session['opening_float']),
            'cash_sales' => decimal($cashSales),
            // Payouts live track nahi hotay; close time par hi set hotay
            'estimated_closing' => decimal(((float)$session['opening_float']) + $cashSales),
        ];

        json_response(['session' => $session, 'metrics' => $metrics]);
    }
}