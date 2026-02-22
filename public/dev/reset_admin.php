<?php
// Usage (example):
//   http://localhost/fastfoodpos/public/dev/reset_admin.php?pin=1234
// Optional params:
//   &username=admin&name=Admin
// NOTE: Ye sirf local dev ke liye rakhein; production me hata dein.

require_once __DIR__ . '/../../app/Config.php';

$pin = $_GET['pin'] ?? '';
$username = $_GET['username'] ?? 'admin';
$name = $_GET['name'] ?? 'Admin';

if ($pin === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing ?pin=XXXX";
    exit;
}

try {
    $dsn = 'mysql:host=' . Config::DB_HOST . ';dbname=' . Config::DB_NAME . ';charset=' . Config::DB_CHARSET;
    $pdo = new PDO($dsn, Config::DB_USER, Config::DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $hash = password_hash($pin, PASSWORD_BCRYPT);

    // Ensure a user with username=admin exists and is active ADMIN
    $sql = "INSERT INTO users (branch_id, name, username, pin_hash, role, active)
            VALUES (NULL, :name, :username, :hash, 'ADMIN', 1)
            ON DUPLICATE KEY UPDATE
              name = VALUES(name),
              pin_hash = VALUES(pin_hash),
              role = VALUES(role),
              active = VALUES(active),
              branch_id = VALUES(branch_id)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':name' => $name,
        ':username' => $username,
        ':hash' => $hash,
    ]);

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'username' => $username,
        'pin_set' => true,
        'tip' => 'Ab /public/ par jaa kar POST /api/login se login try karein'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error: " . $e->getMessage();
}