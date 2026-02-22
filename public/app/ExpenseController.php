<?php
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../Helpers.php';

class ExpenseController {
    public static function create(): void {
        $user = Auth::requireAuth(['ADMIN','MANAGER']);
        $b = body_json();
        $branch_id = (int)($b['branch_id'] ?? ($user['branch_id'] ?? 0));
        $category_id = (int)($b['category_id'] ?? 0);
        $amount = decimal($b['amount'] ?? 0);
        $vendor = isset($b['vendor']) ? trim($b['vendor']) : null;
        $notes = isset($b['notes']) ? trim($b['notes']) : null;
        $attachment_url = isset($b['attachment_url']) ? trim($b['attachment_url']) : null;

        if ($branch_id <= 0 || $category_id <= 0 || $amount <= 0) {
            json_error('branch_id, category_id, amount required', 422);
        }

        $pdo = Database::conn();
        $stmt = $pdo->prepare("
            INSERT INTO expenses (branch_id, category_id, amount, vendor, notes, attachment_url, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$branch_id, $category_id, $amount, $vendor, $notes, $attachment_url, $user['id']]);
        json_response(['id' => (int)$pdo->lastInsertId()]);
    }

    public static function list(): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $pdo = Database::conn();
        $rows = $pdo->query("
            SELECT e.*, ec.name AS category_name, u.name AS created_by_name, b.name AS branch_name
            FROM expenses e
            JOIN expense_categories ec ON ec.id = e.category_id
            JOIN users u ON u.id = e.created_by
            JOIN branches b ON b.id = e.branch_id
            ORDER BY e.id DESC
            LIMIT 200
        ")->fetchAll();
        json_response(['data' => $rows]);
    }

    // NEW: list expense categories
    public static function listCategories(): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $pdo = Database::conn();
        $rows = $pdo->query("SELECT id, name, active FROM expense_categories ORDER BY name ASC")->fetchAll();
        json_response(['data' => $rows]);
    }
}