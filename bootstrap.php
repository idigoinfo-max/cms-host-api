<?php
declare(strict_types=1);

/**
 * Bootstrap tối giản cho Host Gateway (Matbao Vibe Host).
 * Không phụ thuộc CMS local / Composer.
 */

define('HOST_API_ROOT', __DIR__);

date_default_timezone_set('Asia/Ho_Chi_Minh');

/**
 * @return array<string,mixed>
 */
function host_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }
    $local = HOST_API_ROOT . DIRECTORY_SEPARATOR . 'config.local.php';
    $example = HOST_API_ROOT . DIRECTORY_SEPARATOR . 'config.example.php';
    if (is_file($local)) {
        $loaded = require $local;
    } elseif (is_file($example)) {
        $loaded = require $example;
    } else {
        $loaded = [];
    }
    $cfg = is_array($loaded) ? $loaded : [];
    $tz = (string) ($cfg['timezone'] ?? 'Asia/Ho_Chi_Minh');
    if ($tz !== '') {
        date_default_timezone_set($tz);
    }
    return $cfg;
}

function host_storage_root(): string
{
    static $resolved = null;
    if (is_string($resolved)) {
        return $resolved;
    }

    $cfg = host_config();
    $candidates = [];

    $env = trim((string) (getenv('CMS_HOST_STORAGE') ?: getenv('STORAGE_PATH') ?: ''));
    if ($env !== '') {
        $candidates[] = $env;
    }

    $path = trim((string) ($cfg['storage_path'] ?? 'data'));
    if ($path === '') {
        $path = 'data';
    }
    if (preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $path) === 1) {
        $candidates[] = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    } else {
        $candidates[] = HOST_API_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    // Vibe Host / container thường chỉ ghi được /tmp
    $candidates[] = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cms-host-api-data';
    $candidates[] = '/tmp/cms-host-api-data';

    foreach ($candidates as $candidate) {
        $candidate = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate), DIRECTORY_SEPARATOR);
        if ($candidate === '') {
            continue;
        }
        if (!is_dir($candidate)) {
            @mkdir($candidate, 0775, true);
        }
        if (is_dir($candidate) && is_writable($candidate)) {
            $resolved = $candidate;
            return $resolved;
        }
    }

    // Giữ path config dù không ghi được — lỗi sẽ rõ ở host_ensure_dir
    $resolved = $candidates[0] ?? (HOST_API_ROOT . DIRECTORY_SEPARATOR . 'data');
    return $resolved;
}

function host_queue_root(): string
{
    return host_storage_root() . DIRECTORY_SEPARATOR . 'queue';
}

function host_logs_root(): string
{
    return host_storage_root() . DIRECTORY_SEPARATOR . 'logs';
}

function host_now_iso(): string
{
    return (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
}

function host_is_valid_id(string $id): bool
{
    return $id !== '' && (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,190}$/', $id);
}

function host_queue_dir(string $id): string
{
    return host_queue_root() . DIRECTORY_SEPARATOR . $id;
}

function host_manifest_path(string $id): string
{
    return host_queue_dir($id) . DIRECTORY_SEPARATOR . 'manifest.json';
}

function host_token_path(string $id): string
{
    return host_queue_dir($id) . DIRECTORY_SEPARATOR . '.publish_token';
}

function host_ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Không tạo được thư mục: ' . $dir);
        }
    }
}

function host_public_base_url(): string
{
    $cfg = host_config();
    $base = trim((string) ($cfg['public_base_url'] ?? ''));
    if ($base !== '') {
        return rtrim($base, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return '';
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        $dir = '';
    }
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . $host . $dir;
}

function host_absolute_url(string $relativeOrAbsolute): string
{
    if (preg_match('#^https?://#i', $relativeOrAbsolute) === 1) {
        return $relativeOrAbsolute;
    }
    $base = host_public_base_url();
    $rel = ltrim(str_replace('\\', '/', $relativeOrAbsolute), '/');
    if ($base === '') {
        return $rel;
    }
    return $base . '/' . $rel;
}

function host_extract_key(): string
{
    $header = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (is_string($header) && $header !== '') {
        return $header;
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (is_string($auth) && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
        return trim($m[1]);
    }
    if (isset($_GET['key']) && is_string($_GET['key'])) {
        return $_GET['key'];
    }
    return '';
}

/**
 * Ưu tiên: biến môi trường CMS_HOST_API_KEY / API_KEY (Vibe Host),
 * rồi config.local.php, rồi config.example.php.
 */
function host_expected_api_key(): string
{
    foreach (['CMS_HOST_API_KEY', 'API_KEY'] as $envName) {
        $env = getenv($envName);
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
    }
    $cfg = host_config();
    return trim((string) ($cfg['api_key'] ?? ''));
}

function host_require_api_key(): void
{
    $expected = host_expected_api_key();
    if ($expected === '' || $expected === 'doi-thanh-chuoi-bi-mat') {
        host_json(503, [
            'ok' => false,
            'error' => 'Chưa cấu hình api_key an toàn. Đặt env CMS_HOST_API_KEY trên Vibe Host, hoặc tạo config.local.php từ config.example.php.',
        ]);
    }
    $provided = host_extract_key();
    if ($provided === '' || !hash_equals($expected, $provided)) {
        host_json(401, ['ok' => false, 'error' => 'API key không hợp lệ.']);
    }
}

/**
 * @return array<string,mixed>
 */
function host_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return is_array($_POST) ? $_POST : [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * @param array<string,mixed> $payload
 */
function host_json(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * @return array<string,mixed>|null
 */
function host_load_manifest(string $id): ?array
{
    if (!host_is_valid_id($id)) {
        return null;
    }
    $path = host_manifest_path($id);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * @param array<string,mixed> $manifest
 */
function host_save_manifest(array $manifest): void
{
    $id = (string) ($manifest['id'] ?? '');
    if (!host_is_valid_id($id)) {
        throw new InvalidArgumentException('ID không hợp lệ.');
    }
    $dir = host_queue_dir($id);
    host_ensure_dir($dir);
    $manifest['updated_at'] = host_now_iso();
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Không encode được manifest.');
    }
    file_put_contents(host_manifest_path($id), $json . "\n");
}

function host_get_or_create_token(string $id): string
{
    $path = host_token_path($id);
    if (is_file($path)) {
        $tok = trim((string) file_get_contents($path));
        if ($tok !== '') {
            return $tok;
        }
    }
    $tok = bin2hex(random_bytes(24));
    host_ensure_dir(host_queue_dir($id));
    file_put_contents($path, $tok . "\n");
    return $tok;
}

function host_media_public_url(string $id, string $relPath, string $token): string
{
    $f = ltrim(str_replace('\\', '/', $relPath), '/');
    $q = http_build_query([
        'id' => $id,
        'f' => $f,
        'token' => $token,
    ]);
    return host_absolute_url('media.php?' . $q);
}

/**
 * Resolve file path under queue/{id}/ — chặn path traversal.
 */
function host_resolve_media_file(string $id, string $relPath): ?string
{
    if (!host_is_valid_id($id)) {
        return null;
    }
    $rel = str_replace('\\', '/', $relPath);
    $rel = ltrim($rel, '/');
    if ($rel === '' || str_contains($rel, '..')) {
        return null;
    }
    if (preg_match('#^[a-zA-Z0-9._/-]+$#', $rel) !== 1) {
        return null;
    }
    $base = realpath(host_queue_dir($id));
    if ($base === false || !is_dir($base)) {
        return null;
    }
    $full = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $real = realpath($full);
    if ($real === false || !is_file($real)) {
        return null;
    }
    $baseN = strtolower(str_replace('\\', '/', $base));
    $realN = strtolower(str_replace('\\', '/', $real));
    if ($realN !== $baseN && !str_starts_with($realN, $baseN . '/')) {
        return null;
    }
    return $real;
}

/**
 * @param array<string,mixed>|null $media
 * @return array<string,mixed>|null
 */
function host_media_ref_for_publish(string $id, ?array $media, string $token): ?array
{
    if ($media === null) {
        return null;
    }
    $svg = !empty($media['svg']) && is_string($media['svg']) ? $media['svg'] : null;
    $png = !empty($media['png']) && is_string($media['png']) ? $media['png'] : null;

    // Fallback export flatten: media/png_01.png
    if ($png === null && $svg === null) {
        return null;
    }

    // Nếu path gốc thiếu trên disk, thử map export media/*
    $pngResolved = $png !== null ? host_pick_existing_media($id, $png) : null;
    $svgResolved = $svg !== null ? host_pick_existing_media($id, $svg) : null;

    if ($pngResolved === null && $svgResolved === null) {
        // Vẫn trả metadata; needs_png_convert nếu chỉ có svg
        $preferred = $png ?? $svg;
        $preferredKind = $png !== null ? 'png' : 'svg';
        return [
            'svg' => $svg,
            'png' => $png,
            'preferred' => $preferredKind,
            'preferred_path' => $preferred,
            'url' => $preferred !== null ? host_media_public_url($id, (string) $preferred, $token) : null,
            'url_relative' => 'media.php?id=' . rawurlencode($id) . '&f=' . rawurlencode((string) $preferred),
            'png_url' => $png !== null ? host_media_public_url($id, $png, $token) : null,
            'svg_url' => $svg !== null ? host_media_public_url($id, $svg, $token) : null,
            'needs_png_convert' => $png === null && $svg !== null,
        ];
    }

    $preferredPath = $pngResolved ?? $svgResolved;
    $preferredKind = $pngResolved !== null ? 'png' : 'svg';

    return [
        'svg' => $svg,
        'png' => $png,
        'preferred' => $preferredKind,
        'preferred_path' => $preferredPath,
        'url' => host_media_public_url($id, (string) $preferredPath, $token),
        'url_relative' => 'media.php?id=' . rawurlencode($id) . '&f=' . rawurlencode((string) $preferredPath),
        'png_url' => $pngResolved !== null ? host_media_public_url($id, $pngResolved, $token) : null,
        'svg_url' => $svgResolved !== null ? host_media_public_url($id, $svgResolved, $token) : null,
        'needs_png_convert' => $pngResolved === null && $svgResolved !== null,
    ];
}

/**
 * Chọn path tồn tại: ưu tiên path gốc, rồi media/{flat}.
 */
function host_pick_existing_media(string $id, string $rel): ?string
{
    if (host_resolve_media_file($id, $rel) !== null) {
        return $rel;
    }
    $flat = 'media/' . str_replace(['/', '\\'], '_', $rel);
    if (host_resolve_media_file($id, $flat) !== null) {
        return $flat;
    }
    return null;
}

/**
 * @param array<string,mixed> $manifest
 * @return array<string,mixed>
 */
function host_build_publish_bundle(array $manifest): array
{
    $id = (string) ($manifest['id'] ?? '');
    $token = host_get_or_create_token($id);
    $postMedia = is_array($manifest['post']['media'] ?? null) ? $manifest['post']['media'] : null;
    $postMediaOut = host_media_ref_for_publish($id, $postMedia, $token);

    $commentsOut = [];
    $comments = $manifest['comments'] ?? [];
    if (!is_array($comments)) {
        $comments = [];
    }
    usort($comments, static fn($a, $b) => ((int) ($a['thu_tu'] ?? 0)) <=> ((int) ($b['thu_tu'] ?? 0)));
    foreach ($comments as $c) {
        if (!is_array($c)) {
            continue;
        }
        $cMedia = is_array($c['media'] ?? null) ? $c['media'] : null;
        $commentsOut[] = [
            'thu_tu' => (int) ($c['thu_tu'] ?? 0),
            'noi_dung' => (string) ($c['noi_dung'] ?? ''),
            'media' => host_media_ref_for_publish($id, $cMedia, $token),
        ];
    }

    return [
        'id' => $id,
        'tieu_de' => (string) ($manifest['tieu_de'] ?? ''),
        'trang_thai' => (string) ($manifest['trang_thai'] ?? ''),
        'lich_dang' => $manifest['lich_dang'] ?? null,
        'caption' => (string) ($manifest['post']['noi_dung'] ?? ''),
        'media' => $postMediaOut,
        'comments' => $commentsOut,
        'paths' => [
            'queue_dir' => 'data/queue/' . $id,
        ],
        'fb_post_id' => $manifest['fb_post_id'] ?? null,
        'hint' => 'Ưu tiên media.png_url. URL media dùng token (Facebook fetch được, không cần X-Api-Key).',
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function host_queue_list(bool $dueOnly, int $limit): array
{
    $root = host_queue_root();
    if (!is_dir($root)) {
        return [];
    }
    $entries = scandir($root);
    if ($entries === false) {
        return [];
    }
    $now = new DateTimeImmutable('now');
    $out = [];
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..' || !host_is_valid_id($name)) {
            continue;
        }
        $manifest = host_load_manifest($name);
        if ($manifest === null) {
            continue;
        }
        $st = (string) ($manifest['trang_thai'] ?? '');
        if (!in_array($st, ['da_duyet', 'cho_dang'], true)) {
            continue;
        }
        $lich = $manifest['lich_dang'] ?? null;
        if ($lich === null || $lich === '') {
            continue;
        }
        if ($dueOnly) {
            try {
                $lichDt = new DateTimeImmutable((string) $lich);
                if ($lichDt > $now) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
        }
        $out[] = [
            'id' => (string) ($manifest['id'] ?? $name),
            'tieu_de' => (string) ($manifest['tieu_de'] ?? ''),
            'trang_thai' => $st,
            'lich_dang' => $lich,
            'uu_tien' => (int) ($manifest['uu_tien'] ?? 0),
            'loai_bai' => (string) ($manifest['loai_bai'] ?? ''),
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    usort($out, static function ($a, $b) {
        $ua = (int) ($a['uu_tien'] ?? 0);
        $ub = (int) ($b['uu_tien'] ?? 0);
        if ($ua !== $ub) {
            return $ub <=> $ua;
        }
        return strcmp((string) ($a['lich_dang'] ?? ''), (string) ($b['lich_dang'] ?? ''));
    });
    return array_slice($out, 0, $limit);
}

function host_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            host_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function host_append_log(string $line): void
{
    host_ensure_dir(host_logs_root());
    $path = host_logs_root() . DIRECTORY_SEPARATOR . 'events.log';
    file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Giải nén ZIP vào queue/{id}/. ZIP có thể có root = id/ hoặc file ở gốc.
 *
 * @return array{id: string, files: int}
 */
function host_extract_zip_to_queue(string $zipPath, ?string $forcedId = null): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP thiếu extension ZipArchive.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Không mở được file ZIP.');
    }

    $tmp = host_storage_root() . DIRECTORY_SEPARATOR . '_tmp_' . bin2hex(random_bytes(8));
    host_ensure_dir($tmp);
    if (!$zip->extractTo($tmp)) {
        $zip->close();
        host_rrmdir($tmp);
        throw new RuntimeException('Giải nén ZIP thất bại.');
    }
    $zip->close();

    try {
        $manifestRel = host_find_manifest_in_tree($tmp);
        if ($manifestRel === null) {
            throw new RuntimeException('ZIP thiếu manifest.json.');
        }
        $manifestFull = $tmp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $manifestRel);
        $raw = file_get_contents($manifestFull);
        if ($raw === false) {
            throw new RuntimeException('Không đọc được manifest.json trong ZIP.');
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            throw new RuntimeException('manifest.json không hợp lệ.');
        }
        $id = $forcedId !== null && $forcedId !== ''
            ? $forcedId
            : (string) ($manifest['id'] ?? '');
        if (!host_is_valid_id($id)) {
            throw new InvalidArgumentException('ID bài không hợp lệ.');
        }
        $manifest['id'] = $id;
        if (empty($manifest['trang_thai'])) {
            $manifest['trang_thai'] = 'cho_dang';
        }

        $bundleRoot = dirname($manifestFull);
        $dest = host_queue_dir($id);
        if (is_dir($dest)) {
            host_rrmdir($dest);
        }
        host_ensure_dir($dest);
        $fileCount = host_copy_tree($bundleRoot, $dest);
        host_save_manifest($manifest);
        host_get_or_create_token($id);

        return ['id' => $id, 'files' => $fileCount];
    } finally {
        host_rrmdir($tmp);
    }
}

function host_find_manifest_in_tree(string $root): ?string
{
    $direct = $root . DIRECTORY_SEPARATOR . 'manifest.json';
    if (is_file($direct)) {
        return 'manifest.json';
    }
    $entries = scandir($root);
    if ($entries === false) {
        return null;
    }
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $root . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path) && is_file($path . DIRECTORY_SEPARATOR . 'manifest.json')) {
            return $name . '/manifest.json';
        }
    }
    // depth-2 fallback
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $root . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($path)) {
            continue;
        }
        $sub = scandir($path);
        if ($sub === false) {
            continue;
        }
        foreach ($sub as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $childPath = $path . DIRECTORY_SEPARATOR . $child;
            if (is_dir($childPath) && is_file($childPath . DIRECTORY_SEPARATOR . 'manifest.json')) {
                return $name . '/' . $child . '/manifest.json';
            }
        }
    }
    return null;
}

function host_copy_tree(string $src, string $dest): int
{
    host_ensure_dir($dest);
    $count = 0;
    $items = scandir($src);
    if ($items === false) {
        return 0;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $from = $src . DIRECTORY_SEPARATOR . $item;
        $to = $dest . DIRECTORY_SEPARATOR . $item;
        if (is_dir($from)) {
            $count += host_copy_tree($from, $to);
        } else {
            if (!copy($from, $to)) {
                throw new RuntimeException('Không copy được: ' . $item);
            }
            $count++;
        }
    }
    return $count;
}
