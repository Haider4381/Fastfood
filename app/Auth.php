<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Response.php';

class Auth {
    public static function start(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
    }

    public static function login(string $username, string $pin): array {
        $pdo = Database::conn();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user) {
            json_error('Invalid credentials', 401);
        }
        if (!password_verify($pin, $user['pin_hash'])) {
            json_error('Invalid credentials', 401);
        }
        self::start();
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'role' => $user['role'],
            'branch_id' => $user['branch_id'] ? (int)$user['branch_id'] : null,
        ];
        return $_SESSION['user'];
    }

    public static function requireAuth(array $roles = []): array {
        self::start();
        if (empty($_SESSION['user'])) {
            json_error('Unauthorized', 401);
        }
        if ($roles && !in_array($_SESSION['user']['role'], $roles, true)) {
            json_error('Forbidden', 403);
        }
        return $_SESSION['user'];
    }

    public static function me(): ?array {
        self::start();
        return $_SESSION['user'] ?? null;
    }

    public static function logout(): void {
        self::start();
        $_SESSION = [];
        session_destroy();
    }
}