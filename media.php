<?php
declare(strict_types=1);

/**
 * Serve media từ data/queue/{id}/...
 *
 * Auth (một trong các cách):
 * - X-Api-Key / Authorization: Bearer / ?key=  (api_key)
 * - ?token=  (publish token per bundle — dùng cho Facebook Graph fetch)
 *
 * Query: media.php?id=POST_ID&f=png/01.png&token=...
 */

require_once __DIR__ . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');

$id = (string) ($_GET['id'] ?? '');
$f = (string) ($_GET['f'] ?? '');
$token = (string) ($_GET['token'] ?? '');

if (!host_is_valid_id($id) || $f === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Thiếu id hoặc f'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = host_config();
$expectedKey = (string) ($cfg['api_key'] ?? '');
$providedKey = host_extract_key();
$keyOk = $expectedKey !== ''
    && $expectedKey !== 'doi-thanh-chuoi-bi-mat'
    && $providedKey !== ''
    && hash_equals($expectedKey, $providedKey);

$tokenOk = false;
if ($token !== '') {
    $storedPath = host_token_path($id);
    if (is_file($storedPath)) {
        $stored = trim((string) file_get_contents($storedPath));
        $tokenOk = $stored !== '' && hash_equals($stored, $token);
    }
}

if (!$keyOk && !$tokenOk) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized media'], JSON_UNESCAPED_UNICODE);
    exit;
}

$path = host_resolve_media_file($id, $f);
if ($path === null) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Không tìm thấy file'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'json' => 'application/json',
    'txt' => 'text/plain; charset=utf-8',
];
$ctype = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $ctype);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, max-age=300');
readfile($path);
exit;
