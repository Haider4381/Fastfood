<?php
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../Helpers.php';

class ExpenseController {

    // GET /api/expense-categories
    public static function listCategories(): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $pdo = Database::conn();

        // Ensure table exists (local safety)
        $pdo->exec("CREATE TABLE IF NOT EXISTS expense_categories (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $st = $pdo->query("SELECT id, name, active FROM expense_categories ORDER BY name");
        $rows = $st ? $st->fetchAll() : [];
        json_response(['data' => $rows]);
    }

    // POST /api/expense-categories
    public static function createCategory(): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $b = body_json();
        $name = trim((string)($b['name'] ?? ''));
        $active = isset($b['active']) ? (int)$b['active'] : 1;
        if ($name === '') json_error('name required', 422);

        $pdo = Database::conn();
        $st = $pdo->prepare("INSERT INTO expense_categories (name, active) VALUES (?, ?)");
        $st->execute([$name, $active ? 1 : 0]);

        json_response(['id' => (int)$pdo->lastInsertId()]);
    }

public static function update($expenseId): void {
    $me = Auth::requireAuth(['ADMIN','MANAGER']);
    $expenseId = (int)$expenseId;
    $b = body_json();

    $branch_id = (int)($b['branch_id'] ?? 0);
    $category_id = (int)($b['category_id'] ?? 0);
    $amount = decimal($b['amount'] ?? 0);
    $vendor = isset($b['vendor']) ? trim($b['vendor']) : null;
    $notes  = isset($b['notes']) ? trim($b['notes']) : null;
    $attachment_url = isset($b['attachment_url']) ? trim($b['attachment_url']) : null;

    if ($branch_id <= 0 || $category_id <= 0 || $amount <= 0) {
        json_error('branch_id, category_id, amount required', 422);
    }

    $pdo = Database::conn();

    try {
        // Check if the expense exists
        $chk = $pdo->prepare("SELECT id FROM expenses WHERE id = ? LIMIT 1");
        $chk->execute([$expenseId]);
        if (!$chk->fetch()) {
            json_error('Expense not found', 404);
        }

        // Update expense record
        $upd = $pdo->prepare("UPDATE expenses SET branch_id = ?, category_id = ?, amount = ?, vendor = ?, notes = ?, attachment_url = ? WHERE id = ?");
        $upd->execute([$branch_id, $category_id, $amount, $vendor, $notes, $attachment_url, $expenseId]);

        json_response(['updated' => true]);
    } catch (Throwable $e) {
        json_error('failed to update expense', 500, $e->getMessage());
    }
}

    // POST /api/expenses
    public static function create(): void {
        // Get current user (Auth::user() nahi, yehi sahi tareeqa hai)
        $me = Auth::requireAuth(['ADMIN','MANAGER']);
        $b = body_json();

        $branch_id = (int)($b['branch_id'] ?? 0);
        $category_id = (int)($b['category_id'] ?? 0);
        $amount = decimal($b['amount'] ?? 0);
        $vendor = isset($b['vendor']) ? trim($b['vendor']) : null;
        $notes  = isset($b['notes']) ? trim($b['notes']) : null;
        $attachment_url = isset($b['attachment_url']) ? trim($b['attachment_url']) : null;

        if ($branch_id <= 0 || $category_id <= 0 || $amount <= 0) {
            json_error('branch_id, category_id, amount required', 422);
        }

        $pdo = Database::conn();

        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            branch_id BIGINT NOT NULL,
            category_id BIGINT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            vendor VARCHAR(120) NULL,
            notes VARCHAR(255) NULL,
            attachment_url VARCHAR(255) NULL,
            created_by BIGINT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_expenses_branch_date (branch_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            // FK validations with clear messages
            $chk = $pdo->prepare("SELECT 1 FROM branches WHERE id = ? LIMIT 1");
            $chk->execute([$branch_id]);
            if (!$chk->fetch()) json_error('branch not found (invalid branch_id)', 422);

            $chk = $pdo->prepare("SELECT 1 FROM expense_categories WHERE id = ? LIMIT 1");
            $chk->execute([$category_id]);
            if (!$chk->fetch()) json_error('expense category not found (invalid category_id)', 422);

            // created_by safe: user row exist na ho to NULL rakh do (FK safe)
            $created_by = isset($me['id']) ? (int)$me['id'] : null;
            if ($created_by) {
                $chk = $pdo->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
                $chk->execute([$created_by]);
                if (!$chk->fetch()) $created_by = null;
            }

            $st = $pdo->prepare("INSERT INTO expenses
                (branch_id, category_id, amount, vendor, notes, attachment_url, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $st->execute([$branch_id, $category_id, $amount, $vendor, $notes, $attachment_url, $created_by]);

            json_response(['id' => (int)$pdo->lastInsertId()]);
        } catch (Throwable $e) {
            // Surface exact DB error to Network panel
            header('X-Error: '.substr($e->getMessage(), 0, 512));
            json_error('failed to save expense', 500, $e->getMessage());
        }
    }

    // GET /api/expenses
    public static function list(): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $pdo = Database::conn();

        $sql = "SELECT e.*,
                       ec.name AS category_name,
                       b.name  AS branch_name,
                       u.name  AS created_by_name
                FROM expenses e
                LEFT JOIN expense_categories ec ON ec.id = e.category_id
                LEFT JOIN branches b ON b.id = e.branch_id
                LEFT JOIN users u ON u.id = e.created_by
                ORDER BY e.id DESC
                LIMIT 500";
        $rows = $pdo->query($sql)->fetchAll();

        json_response(['data' => $rows]);
    }
}