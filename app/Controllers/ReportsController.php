<?php
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../Helpers.php';

class ReportsController {

    // NEW: GET /api/reports/sales?from=YYYY-MM-DD&to=YYYY-MM-DD&branch_id=1&group=day|month
    public static function sales(): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);

        $pdo = Database::conn();

        $from = isset($_GET['from']) && trim($_GET['from']) !== '' ? trim($_GET['from']) : date('Y-m-d');
        $to   = isset($_GET['to']) && trim($_GET['to']) !== '' ? trim($_GET['to']) : $from;
        $branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
        $group = isset($_GET['group']) && strtolower(trim($_GET['group'])) === 'month' ? 'month' : 'day';

        $from_dt = $from . ' 00:00:00';
        $to_dt   = $to . ' 23:59:59';

        // Where clause (for orders)
        $where = "o.created_at BETWEEN ? AND ?";
        $args = [$from_dt, $to_dt];
        if ($branchId > 0) {
            $where .= " AND o.branch_id = ?";
            $args[] = $branchId;
        }

        // grouping expression
        if ($group === 'month') {
            $groupExpr = "DATE_FORMAT(o.created_at, '%Y-%m')";
        } else {
            $groupExpr = "DATE(o.created_at)";
        }

        try {
            // 1) Period rows
            $sql = "
                SELECT
                  {$groupExpr} AS period,
                  COUNT(*) AS orders_count,
                  COALESCE(SUM(o.grand_total),0) AS sales_total,
                  COALESCE(SUM(CASE WHEN o.status = 'PAID' THEN o.grand_total ELSE 0 END),0) AS paid_total,
                  COALESCE(SUM(CASE WHEN o.status = 'PARTIAL' THEN o.grand_total ELSE 0 END),0) AS partial_total
                FROM orders o
                WHERE {$where}
                GROUP BY {$groupExpr}
                ORDER BY {$groupExpr} ASC
            ";
            $st = $pdo->prepare($sql);
            $st->execute($args);
            $rows = $st->fetchAll();

            // 2) Totals for the range
            $totSql = "
                SELECT
                  COUNT(*) AS orders_count,
                  COALESCE(SUM(o.subtotal),0)        AS subtotal,
                  COALESCE(SUM(o.discount_total),0)  AS discount_total,
                  COALESCE(SUM(o.service_charge),0)  AS service_charge,
                  COALESCE(SUM(o.tax_total),0)       AS tax_total,
                  COALESCE(SUM(o.delivery_fee),0)    AS delivery_fee,
                  COALESCE(SUM(o.grand_total),0)     AS grand_total
                FROM orders o
                WHERE {$where}
            ";
            $st2 = $pdo->prepare($totSql);
            $st2->execute($args);
            $totals = $st2->fetch();

            if (!$totals) {
                $totals = [
                    'orders_count' => 0,
                    'subtotal' => 0,
                    'discount_total' => 0,
                    'service_charge' => 0,
                    'tax_total' => 0,
                    'delivery_fee' => 0,
                    'grand_total' => 0
                ];
            }

            // 3) Payment method breakdown (payments for orders in range)
            $paySql = "
                SELECT p.method, COALESCE(SUM(p.amount),0) AS amount
                FROM payments p
                JOIN orders o ON o.id = p.order_id
                WHERE o.created_at BETWEEN ? AND ?
            ";
            $payArgs = [$from_dt, $to_dt];
            if ($branchId > 0) {
                $paySql .= " AND o.branch_id = ?";
                $payArgs[] = $branchId;
            }
            $paySql .= " GROUP BY p.method ORDER BY amount DESC";
            $st3 = $pdo->prepare($paySql);
            $st3->execute($payArgs);
            $payments = $st3->fetchAll();

            // 4) Top selling items (qty and sales)
            $topSql = "
                SELECT
                  COALESCE(oi.item_id, 0) AS item_id,
                  COALESCE(oi.item_name_snapshot, mi.name) AS item_name,
                  COALESCE(mc.name, '') AS category_name,
                  COALESCE(SUM(oi.qty),0) AS qty,
                  COALESCE(SUM(oi.line_total),0) AS sales
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                LEFT JOIN menu_items mi ON mi.id = oi.item_id
                LEFT JOIN menu_categories mc ON mc.id = mi.category_id
                WHERE o.created_at BETWEEN ? AND ?
                " . ($branchId > 0 ? " AND o.branch_id = ? " : "") . "
                GROUP BY COALESCE(oi.item_id,0), COALESCE(oi.item_name_snapshot, mi.name), COALESCE(mc.name,'')
                ORDER BY sales DESC
                LIMIT 50
            ";
            $topArgs = [$from_dt, $to_dt];
            if ($branchId > 0) $topArgs[] = $branchId;
            $st4 = $pdo->prepare($topSql);
            $st4->execute($topArgs);
            $top_items = $st4->fetchAll();

            json_response([
                'meta' => ['from' => $from, 'to' => $to, 'branch_id' => $branchId ?: null, 'group' => $group],
                'rows' => $rows,
                'totals' => $totals,
                'payments' => $payments,
                'top_items' => $top_items
            ]);
        } catch (Throwable $e) {
            json_error('Failed to generate sales report', 500, $e->getMessage());
        }
    }

    // GET /api/reports/sales-summary?from=YYYY-MM-DD&to=YYYY-MM-DD&branch_id=1
    public static function salesSummary(): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);

        $from = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-d');
        $to   = isset($_GET['to'])   ? trim($_GET['to'])   : $from;
        $branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

        $start = $from . ' 00:00:00';
        $end   = $to   . ' 23:59:59';

        $pdo = Database::conn();

        // Orders totals (all statuses to match dashboard summary)
        $where = "o.created_at BETWEEN ? AND ?";
        $params = [$start, $end];
        if ($branchId > 0) {
            $where .= " AND o.branch_id = ?";
            $params[] = $branchId;
        }

        $sqlTotals = "
            SELECT
              COUNT(*) AS orders_count,
              COALESCE(SUM(o.subtotal),0)        AS subtotal,
              COALESCE(SUM(o.discount_total),0)  AS discount_total,
              COALESCE(SUM(o.service_charge),0)  AS service_charge,
              COALESCE(SUM(o.tax_total),0)       AS tax_total,
              COALESCE(SUM(o.delivery_fee),0)    AS delivery_fee,
              COALESCE(SUM(o.grand_total),0)     AS grand_total
            FROM orders o
            WHERE $where
        ";
        $st = $pdo->prepare($sqlTotals);
        $st->execute($params);
        $tot = $st->fetch() ?: [
            'orders_count'=>0,'subtotal'=>0,'discount_total'=>0,'service_charge'=>0,
            'tax_total'=>0,'delivery_fee'=>0,'grand_total'=>0
        ];

        // Payments by method
        $wherePay = "p.created_at BETWEEN ? AND ?";
        $paramsPay = [$start, $end];
        if ($branchId > 0) {
            $wherePay .= " AND o.branch_id = ?";
            $paramsPay[] = $branchId;
        }
        $sqlPay = "
            SELECT p.method, COALESCE(SUM(p.amount),0) AS total
            FROM payments p
            JOIN orders o ON o.id = p.order_id
            WHERE $wherePay
            GROUP BY p.method
            ORDER BY p.method
        ";
        $pm = $pdo->prepare($sqlPay);
        $pm->execute($paramsPay);
        $payments = $pm->fetchAll() ?: [];

        $paid_total = 0.0;
        foreach ($payments as $row) { $paid_total += (float)$row['total']; }
        $outstanding = max(0, (float)$tot['grand_total'] - $paid_total);
        $avg_order = ((int)$tot['orders_count'] > 0) ? ((float)$tot['grand_total'] / (int)$tot['orders_count']) : 0.0;

        json_response([
            'range' => ['from' => $from, 'to' => $to, 'branch_id' => $branchId ?: null],
            'totals' => [
                'orders_count'   => (int)$tot['orders_count'],
                'subtotal'       => decimal($tot['subtotal']),
                'discount_total' => decimal($tot['discount_total']),
                'service_charge' => decimal($tot['service_charge']),
                'tax_total'      => decimal($tot['tax_total']),
                'delivery_fee'   => decimal($tot['delivery_fee']),
                'grand_total'    => decimal($tot['grand_total']),
                'paid_total'     => decimal($paid_total),
                'outstanding'    => decimal($outstanding),
                'avg_order'      => decimal($avg_order),
            ],
            'payments_by_method' => $payments,
        ]);
    }

    // GET /api/reports/items?from=YYYY-MM-DD&to=YYYY-MM-DD&branch_id=1&limit=200
    public static function items(): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);

        $from = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-d');
        $to   = isset($_GET['to'])   ? trim($_GET['to'])   : $from;
        $branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
        $limit = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 200;

        $start = $from . ' 00:00:00';
        $end   = $to   . ' 23:59:59';

        $pdo = Database::conn();

        // Only completed/partial sales; exclude deal bundle parent rows
        $where = "o.created_at BETWEEN ? AND ? AND o.status IN ('PAID','PARTIAL') AND oi.is_deal = 0";
        $params = [$start, $end];
        if ($branchId > 0) {
            $where .= " AND o.branch_id = ?";
            $params[] = $branchId;
        }

        $sql = "
            SELECT
              oi.item_id,
              COALESCE(oi.item_name_snapshot, mi.name) AS item_name,
              COALESCE(mc.name, '') AS category_name,
              SUM(oi.qty) AS qty,
              SUM(oi.line_total) AS amount
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN menu_items mi ON mi.id = oi.item_id
            LEFT JOIN menu_categories mc ON mc.id = mi.category_id
            WHERE $where
            GROUP BY oi.item_id, COALESCE(oi.item_name_snapshot, mi.name), COALESCE(mc.name, '')
            ORDER BY SUM(oi.qty) DESC, COALESCE(oi.item_name_snapshot, mi.name) ASC
            LIMIT $limit
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rowsQty = $st->fetchAll() ?: [];

        $sql2 = "
            SELECT
              oi.item_id,
              COALESCE(oi.item_name_snapshot, mi.name) AS item_name,
              COALESCE(mc.name, '') AS category_name,
              SUM(oi.qty) AS qty,
              SUM(oi.line_total) AS amount
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN menu_items mi ON mi.id = oi.item_id
            LEFT JOIN menu_categories mc ON mc.id = mi.category_id
            WHERE $where
            GROUP BY oi.item_id, COALESCE(oi.item_name_snapshot, mi.name), COALESCE(mc.name, '')
            ORDER BY SUM(oi.line_total) DESC, COALESCE(oi.item_name_snapshot, mi.name) ASC
            LIMIT $limit
        ";
        $st2 = $pdo->prepare($sql2);
        $st2->execute($params);
        $rowsAmt = $st2->fetchAll() ?: [];

        json_response([
            'range' => ['from' => $from, 'to' => $to, 'branch_id' => $branchId ?: null],
            'top_by_qty' => $rowsQty,
            'top_by_amount' => $rowsAmt,
        ]);
    }

    // GET /api/reports/categories?from=YYYY-MM-DD&to=YYYY-MM-DD&branch_id=1
    public static function categories(): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);

        $from = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-d');
        $to   = isset($_GET['to'])   ? trim($_GET['to'])   : $from;
        $branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

        $start = $from . ' 00:00:00';
        $end   = $to   . ' 23:59:59';

        $pdo = Database::conn();

        $where = "o.created_at BETWEEN ? AND ? AND o.status IN ('PAID','PARTIAL') AND oi.is_deal = 0";
        $params = [$start, $end];
        if ($branchId > 0) {
            $where .= " AND o.branch_id = ?";
            $params[] = $branchId;
        }

        $sql = "
            SELECT
              COALESCE(mc.name, 'Uncategorized') AS category_name,
              SUM(oi.qty) AS qty,
              SUM(oi.line_total) AS amount
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN menu_items mi ON mi.id = oi.item_id
            LEFT JOIN menu_categories mc ON mc.id = mi.category_id
            WHERE $where
            GROUP BY COALESCE(mc.name, 'Uncategorized')
            ORDER BY amount DESC, category_name ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];

        json_response([
            'range' => ['from' => $from, 'to' => $to, 'branch_id' => $branchId ?: null],
            'categories' => $rows,
        ]);
    }

    // NEW: GET /api/reports/profit-loss?from=YYYY-MM-DD&to=YYYY-MM-DD&branch_id=1
    public static function profitLoss(): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);

        $from = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-d');
        $to   = isset($_GET['to'])   ? trim($_GET['to'])   : $from;
        $branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

        $start = $from . ' 00:00:00';
        $end   = $to   . ' 23:59:59';

        $pdo = Database::conn();

        // Sales: sum of grand_total for PAID + PARTIAL orders in range
        $whereSales = "o.created_at BETWEEN ? AND ? AND o.status IN ('PAID','PARTIAL')";
        $argsSales = [$start, $end];
        if ($branchId > 0) { $whereSales .= " AND o.branch_id = ?"; $argsSales[] = $branchId; }

        $sqlSales = "SELECT COALESCE(SUM(o.grand_total),0) FROM orders o WHERE $whereSales";
        $stS = $pdo->prepare($sqlSales);
        $stS->execute($argsSales);
        $sales = (float)$stS->fetchColumn();

        // Expenses: sum of expenses.amount in range
        $whereExp = "e.created_at BETWEEN ? AND ?";
        $argsExp = [$start, $end];
        if ($branchId > 0) { $whereExp .= " AND e.branch_id = ?"; $argsExp[] = $branchId; }

        $sqlExp = "SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE $whereExp";
        $stE = $pdo->prepare($sqlExp);
        $stE->execute($argsExp);
        $expenses = (float)$stE->fetchColumn();

        $profit = $sales - $expenses;

        json_response([
            'range'    => ['from' => $from, 'to' => $to, 'branch_id' => $branchId ?: null],
            'sales'    => decimal($sales),
            'expenses' => decimal($expenses),
            'profit'   => decimal($profit),
        ]);
    }
}