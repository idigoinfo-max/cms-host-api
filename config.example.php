<?php
declare(strict_types=1);

/**
 * Sao chép thành config.local.php và điền giá trị thật.
 * Không commit config.local.php (đã có trong .gitignore).
 */
return [
    // Khóa API cho CMS sync + n8n (Header: X-Api-Key hoặc Authorization: Bearer)
    'api_key' => 'doi-thanh-chuoi-bi-mat',

    // Múi giờ khi so sánh lich_dang
    'timezone' => 'Asia/Ho_Chi_Minh',

    // URL công khai của host API (không slash cuối) — dùng trong publish.media.*_url
    // Ví dụ Vibe Host: 'https://cms-api.your-domain.com'
    // Để trống = tự suy từ request hiện tại
    'public_base_url' => '',

    // Thư mục lưu queue (tương đối so với thư mục app, hoặc đường dẫn tuyệt đối)
    'storage_path' => 'data',
];
