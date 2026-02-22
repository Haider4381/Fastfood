<?php

// Put at very top of public/index.php during debugging ONLY:
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/../app/Config.php';
require_once __DIR__ . '/../app/Response.php';
require_once __DIR__ . '/../app/Router.php';
require_once __DIR__ . '/../app/Helpers.php';

// Controllers
require_once __DIR__ . '/../app/Controllers/AuthController.php';
require_once __DIR__ . '/../app/Controllers/MenuController.php';
require_once __DIR__ . '/../app/Controllers/OrderController.php';
require_once __DIR__ . '/../app/Controllers/CashierController.php';
require_once __DIR__ . '/../app/Controllers/DeliveryController.php';
require_once __DIR__ . '/../app/Controllers/ExpenseController.php';
require_once __DIR__ . '/../app/Controllers/DealsController.php';
if (is_file(__DIR__ . '/../app/Controllers/ReportsController.php')) {
    require_once __DIR__ . '/../app/Controllers/ReportsController.php';
}

function trimmed_path(): string {
    $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'); // e.g. /fastfoodpos/public
    if ($scriptDir !== '' && $scriptDir !== '/') {
        if (strpos($rawPath, $scriptDir) === 0) {
            $rawPath = substr($rawPath, strlen($scriptDir));
        }
    }
    if ($rawPath === '' || $rawPath === false) $rawPath = '/';
    return $rawPath;
}

function serve_static_if_exists(string $path): bool {
    if (strpos($path, '/app/') !== 0) return false;

    $local = realpath(__DIR__ . $path);
    $base  = realpath(__DIR__ . '/app');
    if (!$local || !$base || strpos($local, $base) !== 0 || !is_file($local)) {
        return false;
    }

    $ext = strtolower(pathinfo($local, PATHINFO_EXTENSION));
    if ($ext === 'php') return false;

    $types = [
        'html'=> 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js'  => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg'=> 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'webp'=> 'image/webp',
        'woff'=> 'font/woff',
        'woff2'=>'font/woff2',
        'ttf' => 'font/ttf',
        'map' => 'application/json; charset=utf-8',
        'json'=> 'application/json; charset=utf-8',
    ];
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=3600');
    readfile($local);
    return true;
}

function serve_ui(): void {
    $uiFile = __DIR__ . '/app/index.html';
    if (!is_file($uiFile)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "UI file missing: public/app/index.html";
        exit;
    }
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: text/html; charset=utf-8');
    readfile($uiFile);
    exit;
}

$path = trimmed_path();

if (serve_static_if_exists($path)) {
    exit;
}

if (strpos($path, '/api') === 0) {
    header('Access-Control-Allow-Origin: ' . Config::CORS_ALLOWED_ORIGIN);
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $router = new Router();

    // Health
    $router->add('GET', '/api/health', fn() => json_response(['ok' => true]));

    // Auth
    $router->add('POST', '/api/login', fn() => AuthController::login());
    $router->add('GET',  '/api/me', fn() => AuthController::me());
    $router->add('POST', '/api/logout', fn() => AuthController::logout());

    // Menu
    $router->add('GET',   '/api/menu/categories', fn() => MenuController::listCategories());
    $router->add('POST',  '/api/menu/categories', fn() => MenuController::createCategory());
    $router->add('PATCH', '/api/menu/categories/(\d+)', fn($id) => MenuController::updateCategory($id));
    // NEW:
    $router->add('DELETE','/api/menu/categories/(\d+)', fn($id) => MenuController::deleteCategory($id));

    $router->add('GET',   '/api/menu/items', fn() => MenuController::listItems());
    $router->add('POST',  '/api/menu/items', fn() => MenuController::createItem());
    $router->add('PATCH', '/api/menu/items/(\d+)', fn($id) => MenuController::updateItem($id));
    // Toggle helpers (as you had)
    $router->add('POST',  '/api/menu/items/(\d+)/toggle', fn($id) => MenuController::toggleActive($id));
    $router->add('POST',  '/api/menu/items/(\d+)/activate', fn($id) => MenuController::activateItem($id));
    $router->add('POST',  '/api/menu/items/(\d+)/deactivate', fn($id) => MenuController::deactivateItem($id));
    $router->add('GET',   '/api/menu/items/(\d+)/active/(0|1)', fn($id, $a) => MenuController::setActiveGet($id, $a));
    // NEW:
    $router->add('DELETE','/api/menu/items/(\d+)', fn($id) => MenuController::deleteItem($id));
    // Simple, reliable toggling routes
    $router->add('POST',  '/api/menu/items/(\d+)/toggle', fn($id) => MenuController::toggleActive($id));
    $router->add('POST',  '/api/menu/items/(\d+)/activate', fn($id) => MenuController::activateItem($id));
    $router->add('POST',  '/api/menu/items/(\d+)/deactivate', fn($id) => MenuController::deactivateItem($id));
    $router->add('GET',   '/api/menu/items/(\d+)/active/(0|1)', fn($id, $a) => MenuController::setActiveGet($id, $a));

   
// ... aapka existing bootstrap/require code yahan hai ...

// Orders routes (ensure these exist)
$router->add('POST',   '/api/orders', fn() => OrderController::createOrder());
$router->add('GET',    '/api/orders', fn() => OrderController::list());
$router->add('GET',    '/api/orders/(\d+)', fn($id) => OrderController::getOrder($id));
$router->add('POST',   '/api/orders/(\d+)/items', fn($id) => OrderController::addItem($id));
$router->add('PATCH',  '/api/orders/(\d+)/items/(\d+)', fn($orderId, $orderItemId) => OrderController::updateOrderItem($orderId, $orderItemId));
$router->add('DELETE', '/api/orders/(\d+)/items/(\d+)', fn($orderId, $orderItemId) => OrderController::removeItem($orderId, $orderItemId));
$router->add('POST',   '/api/orders/(\d+)/charges', fn($id) => OrderController::setCharges($id));
$router->add('POST',   '/api/orders/(\d+)/send-to-kitchen', fn($id) => OrderController::sendToKitchen($id));
$router->add('POST',   '/api/orders/(\d+)/ready', fn($id) => OrderController::markReady($id));
$router->add('POST',   '/api/orders/reset-sequence', fn() => OrderController::resetSequence());
$router->add('POST',   '/api/orders/(\d+)/deals', fn($orderId) => OrderController::addDeal($orderId));
// NEW: Reopen for edit (ADMIN/MANAGER only)
$router->add('POST',   '/api/orders/(\d+)/reopen', fn($id) => OrderController::reopen($id));

// ... baqi routes (cashier, deals, reports, etc.) ...
// add this near other order routes
$router->add('POST', '/api/orders/reset-sequence', fn() => OrderController::resetSequence());
// NEW: update qty/discount of a line
$router->add('PATCH',  '/api/orders/(\d+)/items/(\d+)', fn($orderId, $orderItemId) => OrderController::updateOrderItem($orderId, $orderItemId));

$router->add('POST',   '/api/orders/(\d+)/charges', fn($id) => OrderController::setCharges($id));
$router->add('POST',   '/api/orders/(\d+)/pay', fn($id) => OrderController::pay($id));
$router->add('POST',   '/api/orders/(\d+)/send-to-kitchen', fn($id) => OrderController::sendToKitchen($id));
$router->add('POST',   '/api/orders/(\d+)/ready', fn($id) => OrderController::markReady($id));
$router->add('POST',   '/api/orders/(\d+)/cancel', fn($id) => OrderController::cancel($id));
    // Cashier
    $router->add('POST', '/api/cashier/open', fn() => CashierController::open());
    $router->add('POST', '/api/cashier/close', fn() => CashierController::close());
    $router->add('GET',  '/api/cashier/session', fn() => CashierController::current());

    // Ensure DealsController is loaded (already done earlier for saving/listing)
require_once __DIR__ . '/../app/Controllers/DealsController.php';

// ... aapke existing routes ...

// Deals (already saved/listing work ke liye)
$router->add('GET',   '/api/deals', fn() => DealsController::list());
$router->add('POST',  '/api/deals', fn() => DealsController::create());
$router->add('GET',   '/api/deals/(\d+)', fn($id) => DealsController::get($id));
$router->add('PATCH', '/api/deals/(\d+)', fn($id) => DealsController::update($id));
$router->add('DELETE','/api/deals/(\d+)', fn($id) => DealsController::delete($id));
$router->add('POST',  '/api/deals/(\d+)/items', fn($dealId) => DealsController::addItem($dealId));
$router->add('PATCH', '/api/deals/(\d+)/items/(\d+)', fn($dealId, $dealItemId) => DealsController::updateItem($dealId, $dealItemId));
$router->add('DELETE','/api/deals/(\d+)/items/(\d+)', fn($dealId, $dealItemId) => DealsController::deleteItem($dealId, $dealItemId));

// Orders: ADD DEAL TO CART (CRITICAL for add to cart)
$router->add('POST',  '/api/orders/(\d+)/deals', fn($orderId) => OrderController::addDeal($orderId));
    // Expenses
$router->add('POST', '/api/expenses', fn() => ExpenseController::create());
$router->add('GET',  '/api/expenses', fn() => ExpenseController::list());
$router->add('PATCH', '/api/expenses/(\d+)', fn($id) => ExpenseController::update($id)); // <-- Yeh line add karein
$router->add('GET',  '/api/expense-categories', fn() => ExpenseController::listCategories());
$router->add('POST', '/api/expense-categories', fn() => ExpenseController::createCategory());

    // Deliveries
    $router->add('POST', '/api/deliveries/(\d+)', fn($orderId) => DeliveryController::upsert($orderId));
    $router->add('GET',  '/api/deliveries/(\d+)', fn($orderId) => DeliveryController::get($orderId));
    $router->add('GET',  '/api/deliveries', fn() => DeliveryController::list());

    // Reports (optional)
    if (class_exists('ReportsController')) {
        $router->add('GET', '/api/reports/sales-summary', fn() => ReportsController::salesSummary());
        $router->add('GET', '/api/reports/items', fn() => ReportsController::items());
        $router->add('GET', '/api/reports/categories', fn() => ReportsController::categories());
        // Add this line with other Reports routes:
$router->add('GET', '/api/reports/profit-loss', fn() => ReportsController::profitLoss());
// add inside the ReportsController routes block (where other reports routes are defined)
$router->add('GET', '/api/reports/sales', fn() => ReportsController::sales());
    }

    $router->dispatch($_SERVER['REQUEST_METHOD'], $path);
    exit;
}

serve_ui();