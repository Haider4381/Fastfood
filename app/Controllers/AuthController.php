<?php
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../Helpers.php';

class AuthController {
    public static function login(): void {
        $b = body_json();
        $username = trim($b['username'] ?? '');
        $pin = trim($b['pin'] ?? '');
        if ($username === '' || $pin === '') {
            json_error('username and pin required', 422);
        }
        $user = Auth::login($username, $pin);
        json_response(['user' => $user]);
    }

    public static function me(): void {
        $u = Auth::requireAuth();
        json_response(['user' => $u]);
    }

    public static function logout(): void {
        Auth::logout();
        json_response(['message' => 'Logged out']);
    }
}