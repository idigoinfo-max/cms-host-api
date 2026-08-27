# CMS Host Gateway (Matbao Vibe Host)

API PHP tối giản — **không** phải full CMS UI. CMS local vẫn soạn bài; khi đồng bộ, bundle bài đã duyệt/lên lịch được đẩy lên host này. n8n trên Vibe Host đọc queue từ đây; sau `mark_da_dang` host **xóa** `data/queue/{id}` (CMS local vẫn giữ bài).

## Kiến trúc

```
CMS local (soạn / duyệt / lịch)
    │  sync (bước sau — chưa wire)
    ▼
Host Gateway (repo này)  ←  n8n poll queue / get / mark_*
    data/queue/{id}/manifest.json + png/ + …
```

## Deploy lên Vibe Host

1. Đẩy **folder này** lên GitHub thành repo riêng (khuyến nghị), ví dụ `cms-host-api`.
2. Trên Matbao Vibe Host: tạo site, subdomain kiểu `cms-api`, chọn **GitHub** hoặc **Git URL** trỏ repo đó.
3. Nixpacks nhận PHP qua `index.php` — **không cần** Composer / Dockerfile.
4. Sau deploy: SSH/file manager (hoặc env + mount) tạo `config.local.php` từ `config.example.php`, đổi `api_key`, điền `public_base_url` = URL site (vd. `https://cms-api.xxx`).
5. Kiểm tra: `GET /?action=ping` kèm header `X-Api-Key`.

### Đẩy lên GitHub (PowerShell)

Trong thư mục app:

```powershell
cd c:\xampp\htdocs\Xaydungnoidung\tools\cms-host-api
git init
git add .
git commit -m "Initial CMS host gateway for Vibe Host"
# Tạo repo trống trên GitHub rồi:
git remote add origin https://github.com/YOUR_USER/cms-host-api.git
git branch -M main
git push -u origin main
```

Hoặc từ monorepo: tách subtree / copy folder này sang repo riêng rồi push.

## Cấu hình

```text
config.example.php  →  copy thành config.local.php
```

| Key | Ý nghĩa |
|-----|---------|
| `api_key` | CMS sync + n8n (`X-Api-Key` / Bearer) |
| `public_base_url` | URL công khai host (để `publish.media.png_url` đúng) |
| `storage_path` | Mặc định `data` → `data/queue/`, `data/logs/` |
| `timezone` | So sánh `lich_dang` (mặc định `Asia/Ho_Chi_Minh`) |

## Storage

```text
data/queue/{id}/
  manifest.json      # cùng field CMS: id, lich_dang, post, comments, trang_thai…
  png/01.png         # hoặc media/png_01.png (bundle export)
  svg/…              # tuỳ bundle
  .publish_token     # token cho media.php (Facebook fetch)
data/logs/events.log
```

## Endpoints

Auth: `X-Api-Key: <key>` hoặc `Authorization: Bearer <key>` (tránh `?key=` trên production).

| Method | Action | Mô tả |
|--------|--------|--------|
| GET | `ping` | Health |
| GET | `queue&due=1` | Bài `da_duyet`/`cho_dang`, có `lich_dang` ≤ now |
| GET | `get&id=` | Manifest + `publish` (URL media trên host) |
| GET | `bundle&id=` | Chỉ `{ ok, publish }` |
| POST | `upload` | Multipart zip / JSON `zip_base64` / `manifest`+`files` |
| POST | `mark_dang_dang` | Claim đang đăng |
| POST | `mark_da_dang` | Xong → **xóa** `data/queue/{id}` + ghi log |
| POST | `mark_loi` | Giữ bundle, `trang_thai=loi` |
| POST | `mark_cho_dang` | Đưa lại chờ đăng |

### Ví dụ

```bash
curl -s -H "X-Api-Key: YOUR_KEY" "https://cms-api.example.com/?action=ping"

curl -s -H "X-Api-Key: YOUR_KEY" \
  "https://cms-api.example.com/?action=queue&due=1"

curl -s -X POST -H "X-Api-Key: YOUR_KEY" \
  -F "bundle=@post-id.zip" \
  "https://cms-api.example.com/?action=upload"

curl -s -H "X-Api-Key: YOUR_KEY" \
  "https://cms-api.example.com/?action=get&id=POST_ID"

curl -s -X POST -H "X-Api-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d "{\"id\":\"POST_ID\",\"fb_post_id\":\"123\"}" \
  "https://cms-api.example.com/?action=mark_da_dang"
```

### Media

`publish.media.png_url` trỏ tới:

```text
https://cms-api.example.com/media.php?id=POST_ID&f=png/01.png&token=...
```

- n8n / CMS: có thể dùng API key.
- Facebook Graph (`url` / `attachment_url`): dùng `token` trong query (không cần header).

## Field `publish` (khớp CMS api.php)

- `id`, `tieu_de`, `lich_dang`, `caption`
- `media`: `png_url`, `svg_url`, `url`, `preferred`, `needs_png_convert`
- `comments[]`: `thu_tu`, `noi_dung`, `media` (cùng shape)

## Bước tiếp theo (chưa làm trong lần này)

1. **Deploy** repo này lên Vibe Host (Git URL / GitHub) → subdomain `cms-api`.
2. Trên CMS local: thêm cấu hình `host_api_url` + gọi `POST ?action=upload` khi Đồng bộ bài đã duyệt/lịch.
3. Trỏ n8n `CMS_BASE_URL` sang host gateway (thay vì CMS local).

## Lưu ý

- Đây **không** thay CMS — chỉ hàng đợi + media công khai tạm thời.
- Sau `da_dang`, host xóa bundle; cập nhật `trang_thai=da_dang` trên CMS local là việc sync ngược / webhook (phase sau).
- Không commit `config.local.php` hay nội dung `data/queue/`.
