<?php
// Keep these paths consistent with your project layout.
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../Helpers.php';

class DealsController {

    // GET /api/deals?active=1
    public static function list(): void {
        Auth::requireAuth();
        $pdo = Database::conn();
        $active = isset($_GET['active']) ? (int)$_GET['active'] : null;

        $sql = "SELECT d.*,
                   (SELECT COUNT(*) FROM deal_items di WHERE di.deal_id = d.id) AS items_count
                FROM deals d";
        if ($active !== null) $sql .= " WHERE d.active = ".($active?1:0);
        $sql .= " ORDER BY d.id DESC";
        $rows = $pdo->query($sql)->fetchAll();
        json_response(['data' => $rows]);
    }

    // GET /api/deals/{id}
    public static function get($id): void {
        Auth::requireAuth();
        $pdo = Database::conn();
        $id = (int)$id;

        $hdr = $pdo->prepare("SELECT * FROM deals WHERE id = ? LIMIT 1");
        $hdr->execute([$id]);
        $deal = $hdr->fetch();
        if (!$deal) json_error('not found', 404);

        $it = $pdo->prepare("SELECT di.*, mi.name AS item_name, mi.price AS item_price
                             FROM deal_items di
                             JOIN menu_items mi ON mi.id = di.menu_item_id
                             WHERE di.deal_id = ?
                             ORDER BY di.id");
        $it->execute([$id]);
        $items = $it->fetchAll();

        json_response(['deal' => $deal, 'items' => $items]);
    }

    // POST /api/deals
    // Allow CASHIER so POS can build/save deals without admin panel
    public static function create(): void {
        Auth::requireAuth(['ADMIN','MANAGER','CASHIER']);
        $b = body_json();
        $name = trim((string)($b['name'] ?? ''));
        $price= decimal($b['price'] ?? 0);
        $active = (int)($b['active'] ?? 1);
        $notes = isset($b['notes']) ? trim((string)$b['notes']) : null;

        if ($name === '' || $price <= 0) json_error('name and price required', 422);

        $items = is_array($b['items'] ?? null) ? $b['items'] : [];

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try{
            $st = $pdo->prepare("INSERT INTO deals (name, price, active, notes) VALUES (?, ?, ?, ?)");
            $st->execute([$name, $price, $active?1:0, $notes]);
            $id = (int)$pdo->lastInsertId();
            if ($id <= 0) throw new RuntimeException('Failed to obtain deal id');

            if (!empty($items)) {
                $di = $pdo->prepare("INSERT INTO deal_items (deal_id, menu_item_id, qty) VALUES (?, ?, ?)");
                foreach ($items as $it) {
                    $menu_item_id = (int)($it['menu_item_id'] ?? 0);
                    $qty = (float)($it['qty'] ?? 1);
                    if ($menu_item_id <= 0 || $qty <= 0) continue;
                    $di->execute([$id, $menu_item_id, $qty]);
                }
            }
            $pdo->commit();
            json_response(['id' => $id]);
        }catch(Throwable $e){
            $pdo->rollBack();
            json_error('failed to create deal', 500, $e->getMessage());
        }
    }

    // PATCH /api/deals/{id}
    public static function update($id): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $pdo = Database::conn();
        $id = (int)$id;
        $b = body_json();

        $fields = []; $args = [];
        if (isset($b['name']))  { $fields[] = "name = ?";  $args[] = trim((string)$b['name']); }
        if (isset($b['price'])) { $fields[] = "price = ?"; $args[] = decimal($b['price']); }
        if (isset($b['active'])){ $fields[] = "active = ?";$args[] = (int)$b['active'] ? 1 : 0; }
        if (isset($b['notes'])) { $fields[] = "notes = ?"; $args[] = trim((string)$b['notes']); }
        if (!$fields) json_error('nothing to update', 422);
        $args[] = $id;

        $st = $pdo->prepare("UPDATE deals SET ".implode(',', $fields)." WHERE id = ?");
        $st->execute($args);
        json_response(['updated' => true]);
    }

    // DELETE /api/deals/{id}
    public static function delete($id): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $pdo = Database::conn();
        $id = (int)$id;

        $st = $pdo->prepare("DELETE FROM deals WHERE id = ?");
        try{
            $st->execute([$id]);
            json_response(['deleted' => true]);
        }catch(Throwable $e){
            json_error('cannot delete deal', 409, $e->Message());
        }
    }

    // POST /api/deals/{dealId}/items
    public static function addItem($dealId): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $pdo = Database::conn();
        $dealId = (int)$dealId;
        $b = body_json();
        $menu_item_id = (int)($b['menu_item_id'] ?? 0);
        $qty = (float)($b['qty'] ?? 1);
        if ($dealId<=0 || $menu_item_id<=0 || $qty<=0) json_error('deal_id, menu_item_id, qty required', 422);

        $st = $pdo->prepare("INSERT INTO deal_items (deal_id, menu_item_id, qty) VALUES (?,?,?)");
        $st->execute([$dealId, $menu_item_id, $qty]);
        json_response(['id' => (int)$pdo->lastInsertId()]);
    }

    // PATCH /api/deals/{dealId}/items/{dealItemId}
    public static function updateItem($dealId, $dealItemId): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $pdo = Database::conn();
        $dealId = (int)$dealId;
        $dealItemId = (int)$dealItemId;
        $b = body_json();
        $qty = isset($b['qty']) ? (float)$b['qty'] : null;
        if ($qty === null || $qty <= 0) json_error('qty must be > 0', 422);

        $st = $pdo->prepare("UPDATE deal_items SET qty = ? WHERE id = ? AND deal_id = ?");
        $st->execute([$qty, $dealItemId, $dealId]);
        json_response(['updated' => true]);
    }

    // DELETE /api/deals/{dealId}/items/{dealItemId}
    public static function deleteItem($dealId, $dealItemId): void {
        Auth::requireAuth(['ADMIN','MANAGER']);
        $pdo = Database::conn();
        $dealId = (int)$dealId;
        $dealItemId = (int)$dealItemId;

        $st = $pdo->prepare("DELETE FROM deal_items WHERE id = ? AND deal_id = ?");
        $st->execute([$dealItemId, $dealId]);
        json_response(['deleted' => true]);
    }
}