<?php

function body_json(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function generate_order_no(int $branchId): string {
    // e.g. BR01-20251009-153045-XYZ
    $rand = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 3);
    return sprintf('BR%02d-%s-%s', $branchId, date('Ymd-His'), $rand);
}

function decimal($val, int $scale = 2): string {
    return number_format((float)$val, $scale, '.', '');
}