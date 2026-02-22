<?php
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../Helpers.php';

class MenuController {

    public static function listCategories(): void {
        Auth::requireAuth();
        $pdo = Database::conn();
        $rows = $pdo->query("SELECT id, name, sort_order, active FROM menu_categories ORDER BY sort_order, id")->fetchAll();
        json_response(['data' => $rows]);
    }

    public static function createCategory(): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $b = body_json();
        $name = trim((string)($b['name'] ?? ''));
        $sort = (int)($b['sort_order'] ?? 0);
        $active = (int)($b['active'] ?? 1);
        if ($name === '') json_error('name required', 422);
        $pdo = Database::conn();
        $st = $pdo->prepare("INSERT INTO menu_categories (name, sort_order, active) VALUES (?, ?, ?)");
        $st->execute([$name, $sort, $active]);
        json_response(['id' => (int)$pdo->lastInsertId()]);
    }

    public static function updateCategory($id): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $id = (int)$id;
        $b = body_json();
        $name = isset($b['name']) ? trim((string)$b['name']) : null;
        $sort = isset($b['sort_order']) ? (int)$b['sort_order'] : null;
        $active = isset($b['active']) ? (int)$b['active'] : null;

        $pdo = Database::conn();
        $fields = [];
        $args = [];
        if ($name !== null){ $fields[] = "name = ?"; $args[] = $name; }
        if ($sort !== null){ $fields[] = "sort_order = ?"; $args[] = $sort; }
        if ($active !== null){ $fields[] = "active = ?"; $args[] = $active; }
        if (!$fields) json_error('nothing to update', 422);
        $args[] = $id;

        $st = $pdo->prepare("UPDATE menu_categories SET ".implode(',', $fields)." WHERE id = ?");
        $st->execute($args);
        json_response(['updated' => true]);
    }

    // NEW: delete category (block if items exist)
    public static function deleteCategory($id): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $id = (int)$id;
        $pdo = Database::conn();

        $cnt = $pdo->prepare("SELECT COUNT(*) AS c FROM menu_items WHERE category_id = ?");
        $cnt->execute([$id]);
        if ((int)$cnt->fetch()['c'] > 0) {
            json_error('category has items, move or delete items first', 409);
        }

        $st = $pdo->prepare("DELETE FROM menu_categories WHERE id = ?");
        $st->execute([$id]);
        json_response(['deleted' => true]);
    }

    public static function listItems(): void {
        Auth::requireAuth();
        $pdo = Database::conn();
        $sql = "SELECT i.id, i.name, i.sku, i.price, i.active, i.category_id, c.name AS category_name
                FROM menu_items i
                LEFT JOIN menu_categories c ON c.id = i.category_id
                ORDER BY i.id DESC";
        $rows = $pdo->query($sql)->fetchAll();
        json_response(['data' => $rows]);
    }

    public static function createItem(): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $b = body_json();
        $name = trim((string)($b['name'] ?? ''));
        $sku  = isset($b['sku']) ? trim((string)$b['sku']) : null;
        $price= decimal($b['price'] ?? 0);
        $cat  = (int)($b['category_id'] ?? 0);
        $active = (int)($b['active'] ?? 1);
        if ($name === '' || $cat <= 0) json_error('name and category required', 422);

        $pdo = Database::conn();
        $st = $pdo->prepare("INSERT INTO menu_items (name, sku, price, category_id, active) VALUES (?, ?, ?, ?, ?)");
        $st->execute([$name, $sku ?: null, $price, $cat, $active ? 1 : 0]);
        json_response(['id' => (int)$pdo->lastInsertId()]);
    }

    public static function updateItem($id): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $id = (int)$id;
        $b = body_json();
        $name = isset($b['name']) ? trim((string)$b['name']) : null;
        $sku  = array_key_exists('sku', $b) ? (is_null($b['sku']) ? null : trim((string)$b['sku'])) : '__NA__';
        $price= isset($b['price']) ? decimal($b['price']) : null;
        $active = isset($b['active']) ? (int)$b['active'] : null;

        $pdo = Database::conn();
        $fields = [];
        $args = [];
        if ($name !== null){ $fields[] = "name = ?"; $args[] = $name; }
        if ($sku !== '__NA__'){ $fields[] = "sku = ?"; $args[] = $sku; }
        if ($price !== null){ $fields[] = "price = ?"; $args[] = $price; }
        if ($active !== null){ $fields[] = "active = ?"; $args[] = $active; }
        if (!$fields) json_error('nothing to update', 422);
        $args[] = $id;

        $st = $pdo->prepare("UPDATE menu_items SET ".implode(',', $fields)." WHERE id = ?");
        $st->execute($args);
        json_response(['updated' => true]);
    }

    // NEW: delete item (FK-safe)
    public static function deleteItem($id): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $id = (int)$id;
        $pdo = Database::conn();
        try{
            $st = $pdo->prepare("DELETE FROM menu_items WHERE id = ?");
            $st->execute([$id]);
            json_response(['deleted' => true]);
        }catch(Throwable $e){
            // FK violation ya koi aur SQL error
            json_error('cannot delete item (maybe referenced in orders)', 409, $e->getMessage());
        }
    }

    // Toggle helpers from your previous build (keep as-is)
    public static function toggleActive($id): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $id = (int)$id;
        $pdo = Database::conn();
        $cur = $pdo->prepare("SELECT active FROM menu_items WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch();
        if (!$row) json_error('not found', 404);
        $new = (int)!((int)$row['active']);
        $u = $pdo->prepare("UPDATE menu_items SET active = ? WHERE id = ?");
        $u->execute([$new, $id]);
        json_response(['item' => ['id' => $id, 'active' => $new]]);
    }
    public static function activateItem($id): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $id = (int)$id; $pdo = Database::conn();
        $u = $pdo->prepare("UPDATE menu_items SET active = 1 WHERE id = ?");
        $u->execute([$id]);
        json_response(['item' => ['id'=>$id, 'active'=>1]]);
    }
    public static function deactivateItem($id): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $id = (int)$id; $pdo = Database::conn();
        $u = $pdo->prepare("UPDATE menu_items SET active = 0 WHERE id = ?");
        $u->execute([$id]);
        json_response(['item' => ['id'=>$id, 'active'=>0]]);
    }
    public static function setActiveGet($id, $a): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $id = (int)$id; $a = (int)$a; $pdo = Database::conn();
        $u = $pdo->prepare("UPDATE menu_items SET active = ? WHERE id = ?");
        $u->execute([$a?1:0, $id]);
        json_response(['item' => ['id'=>$id, 'active'=>$a?1:0]]);
    }
}