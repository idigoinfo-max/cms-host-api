<?php
declare(strict_types=1);

/**
 * CMS Host Gateway — API tối giản cho Matbao Vibe Host.
 *
 * GET  ?action=ping
 * GET  ?action=queue[&due=1][&limit=50]
 * GET  ?action=get&id=...
 * GET  ?action=bundle&id=...
 * POST ?action=upload          — multipart zip | JSON zip_base64
 * POST ?action=mark_dang_dang
 * POST ?action=mark_da_dang    — đánh dấu xong rồi XÓA data/queue/{id}
 * POST ?action=mark_loi
 * POST ?action=mark_cho_dang
 *
 * Auth: X-Api-Key | Authorization: Bearer | ?key=
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

host_ensure_dir(host_queue_root());
host_ensure_dir(host_logs_root());
host_require_api_key();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

try {
    if ($method === 'GET') {
        switch ($action) {
            case 'ping':
                host_json(200, [
                    'ok' => true,
                    'service' => 'cms-host-api',
                    'phase' => 'host-gateway',
                    'time' => host_now_iso(),
                    'public_base_url' => host_public_base_url(),
                ]);

            case 'queue':
                $dueOnly = !isset($_GET['due']) || $_GET['due'] === '1' || $_GET['due'] === 'true';
                $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
                $items = host_queue_list($dueOnly, $limit);
                host_json(200, [
                    'ok' => true,
                    'count' => count($items),
                    'due_only' => $dueOnly,
                    'posts' => $items,
                ]);

            case 'get':
            case 'bundle':
                $id = (string) ($_GET['id'] ?? '');
                $manifest = host_load_manifest($id);
                if ($manifest === null) {
                    host_json(404, ['ok' => false, 'error' => 'Không tìm thấy bài trên host queue.']);
                }
                $publish = host_build_publish_bundle($manifest);
                if ($action === 'bundle') {
                    host_json(200, [
                        'ok' => true,
                        'publish' => $publish,
                    ]);
                }
                host_json(200, [
                    'ok' => true,
                    'post' => $manifest,
                    'publish' => $publish,
                    'paths' => $publish['paths'],
                    'public_base_url' => host_public_base_url(),
                ]);

            default:
                host_json(400, [
                    'ok' => false,
                    'error' => 'action không hợp lệ',
                    'hint' => 'ping | queue | get | bundle | upload | mark_dang_dang | mark_da_dang | mark_loi | mark_cho_dang',
                ]);
        }
    }

    if ($method === 'POST') {
        if ($action === 'upload') {
            $result = host_handle_upload();
            host_append_log(host_now_iso() . "\tupload\t" . $result['id'] . "\tfiles=" . $result['files']);
            host_json(200, [
                'ok' => true,
                'id' => $result['id'],
                'files' => $result['files'],
                'queue_path' => 'data/queue/' . $result['id'],
                'message' => 'Đã lưu bundle lên host queue.',
            ]);
        }

        $body = host_read_json_body();
        $id = (string) ($body['id'] ?? $_GET['id'] ?? $_POST['id'] ?? '');
        $manifest = host_load_manifest($id);
        if ($manifest === null) {
            host_json(404, ['ok' => false, 'error' => 'Không tìm thấy bài trên host queue.']);
        }

        switch ($action) {
            case 'mark_cho_dang':
                $manifest['trang_thai'] = 'cho_dang';
                if (!empty($body['lich_dang']) && is_string($body['lich_dang'])) {
                    $manifest['lich_dang'] = $body['lich_dang'];
                }
                unset($manifest['loi_message'], $manifest['loi_at']);
                host_save_manifest($manifest);
                host_append_log(host_now_iso() . "\tmark_cho_dang\t" . $id);
                host_json(200, ['ok' => true, 'trang_thai' => 'cho_dang', 'id' => $id]);

            case 'mark_dang_dang':
                $manifest['trang_thai'] = 'dang_dang';
                $manifest['dang_dang_at'] = host_now_iso();
                unset($manifest['loi_message'], $manifest['loi_at']);
                host_save_manifest($manifest);
                host_append_log(host_now_iso() . "\tmark_dang_dang\t" . $id);
                host_json(200, ['ok' => true, 'trang_thai' => 'dang_dang', 'id' => $id]);

            case 'mark_da_dang':
                $fbPostId = !empty($body['fb_post_id']) ? (string) $body['fb_post_id'] : null;
                $fbComments = !empty($body['fb_comment_ids']) && is_array($body['fb_comment_ids'])
                    ? array_values($body['fb_comment_ids'])
                    : null;
                host_append_log(
                    host_now_iso()
                    . "\tmark_da_dang\t" . $id
                    . "\tfb_post_id=" . ($fbPostId ?? '')
                    . "\tdeleted=1"
                );
                // Host cleanup: xóa bundle sau khi đăng xong (local CMS vẫn giữ bài).
                host_rrmdir(host_queue_dir($id));
                host_json(200, [
                    'ok' => true,
                    'trang_thai' => 'da_dang',
                    'id' => $id,
                    'fb_post_id' => $fbPostId,
                    'fb_comment_ids' => $fbComments,
                    'deleted_from_host' => true,
                    'note' => 'Đã xóa data/queue/' . $id . ' trên host. CMS local vẫn giữ bài (đồng bộ trạng thái riêng).',
                ]);

            case 'mark_loi':
                $manifest['trang_thai'] = 'loi';
                $manifest['loi_message'] = (string) ($body['message'] ?? $body['error'] ?? 'Lỗi không xác định');
                $manifest['loi_at'] = host_now_iso();
                host_save_manifest($manifest);
                host_append_log(host_now_iso() . "\tmark_loi\t" . $id . "\t" . $manifest['loi_message']);
                host_json(200, [
                    'ok' => true,
                    'trang_thai' => 'loi',
                    'id' => $id,
                    'loi_message' => $manifest['loi_message'],
                ]);

            default:
                host_json(400, ['ok' => false, 'error' => 'POST action không hợp lệ']);
        }
    }

    host_json(405, ['ok' => false, 'error' => 'Method không hỗ trợ']);
} catch (Throwable $e) {
    host_json(500, ['ok' => false, 'error' => $e->getMessage()]);
}

/**
 * @return array{id: string, files: int}
 */
function host_handle_upload(): array
{
    // 1) Multipart file field: bundle | zip | file
    $fileKey = null;
    foreach (['bundle', 'zip', 'file'] as $k) {
        if (isset($_FILES[$k]) && is_array($_FILES[$k]) && (int) ($_FILES[$k]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $fileKey = $k;
            break;
        }
    }

    $forcedId = null;
    if (isset($_POST['id']) && is_string($_POST['id']) && $_POST['id'] !== '') {
        $forcedId = $_POST['id'];
    }

    if ($fileKey !== null) {
        $tmp = (string) ($_FILES[$fileKey]['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('Upload file rỗng.');
        }
        return host_extract_zip_to_queue($tmp, $forcedId);
    }

    // 2) JSON: zip_base64 (+ optional id)
    $body = host_read_json_body();
    if (!empty($body['id']) && is_string($body['id'])) {
        $forcedId = $body['id'];
    }

    if (!empty($body['zip_base64']) && is_string($body['zip_base64'])) {
        $b64 = $body['zip_base64'];
        if (str_contains($b64, ',')) {
            $b64 = substr($b64, (int) strpos($b64, ',') + 1);
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') {
            throw new InvalidArgumentException('zip_base64 không hợp lệ.');
        }
        host_ensure_dir(host_storage_root());
        $tmpZip = host_storage_root() . DIRECTORY_SEPARATOR . '_upload_' . bin2hex(random_bytes(8)) . '.zip';
        file_put_contents($tmpZip, $bin);
        try {
            return host_extract_zip_to_queue($tmpZip, $forcedId);
        } finally {
            @unlink($tmpZip);
        }
    }

    // 3) JSON: manifest + files map (path => base64) — gọn cho sync nhỏ
    if (!empty($body['manifest']) && is_array($body['manifest'])) {
        $manifest = $body['manifest'];
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
        $dest = host_queue_dir($id);
        if (is_dir($dest)) {
            host_rrmdir($dest);
        }
        host_ensure_dir($dest);
        $filesWritten = 0;
        $files = $body['files'] ?? [];
        if (is_array($files)) {
            foreach ($files as $rel => $contentB64) {
                if (!is_string($rel) || !is_string($contentB64)) {
                    continue;
                }
                $rel = ltrim(str_replace('\\', '/', $rel), '/');
                if ($rel === '' || str_contains($rel, '..') || preg_match('#^[a-zA-Z0-9._/-]+$#', $rel) !== 1) {
                    continue;
                }
                $bin = base64_decode($contentB64, true);
                if ($bin === false) {
                    continue;
                }
                $full = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                host_ensure_dir(dirname($full));
                file_put_contents($full, $bin);
                $filesWritten++;
            }
        }
        host_save_manifest($manifest);
        host_get_or_create_token($id);
        return ['id' => $id, 'files' => $filesWritten + 1];
    }

    throw new InvalidArgumentException(
        'Upload cần multipart field bundle|zip|file, hoặc JSON zip_base64, hoặc JSON manifest+files.'
    );
}
