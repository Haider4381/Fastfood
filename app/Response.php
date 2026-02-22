<?php
require_once __DIR__ . '/Config.php';

function json_response($data = null, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (is_array($data) || is_object($data)) {
        echo json_encode($data);
    } else {
        echo json_encode(['message' => $data]);
    }
    exit;
}

function json_error(string $message, int $status = 400, $errors = null): void {
    $payload = ['error' => $message];
    if ($errors) $payload['details'] = $errors;
    json_response($payload, $status);
}