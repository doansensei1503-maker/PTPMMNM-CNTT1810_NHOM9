<?php
/**
 * public/img.php — PHỤC VỤ ẢNH SNAPSHOT CÓ XÁC THỰC (FIX-01).
 *
 * GET img.php?id=<snapshot id>&f=<tên tệp>
 *
 *   - Bắt buộc đăng nhập; kiểm tra quyền ĐỌC thư viện chứa snapshot
 *     (chủ sở hữu / thành viên / quản trị viên) qua wa_reader_context().
 *   - Chỉ phục vụ tệp nằm trong public/uploads/snapshots/<id>/ (snapshot cũ
 *     vẫn đọc được — cùng thư mục hiện tại, không di dời ảnh).
 *   - Ngăn path traversal: basename + realpath phải nằm trong thư mục snapshot.
 *   - Chỉ trả đúng MIME raster (jpeg/png/gif/webp, nhận diện bằng finfo từ
 *     bytes thật); sai loại -> 404.
 *   - Khách chưa đăng nhập hoặc ngoài thư viện -> 403/404 (không lộ ảnh).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$snapshotId = (int) ($_GET['id'] ?? 0);
$file = (string) ($_GET['f'] ?? '');

$deny = static function (int $code): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $code === 403 ? 'Khong co quyen xem anh nay.' : 'Khong tim thay anh.';
    exit;
};

if ($snapshotId <= 0 || $file === '' || str_contains($file, "\0")) {
    $deny(404);
}

// Chỉ tên tệp đơn (không thư mục, không ../).
$base = basename($file);

if ($base === '' || $base === '.' || $base === '..' || $base !== $file) {
    $deny(404);
}

if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,90}\.(jpg|jpeg|png|gif|webp)$/i', $base)) {
    $deny(404);
}

// Quyền đọc snapshot (đăng nhập + thuộc thư viện + bản success).
$context = wa_reader_context($snapshotId);

if ($context['status'] !== 'ok') {
    $deny($context['status'] === 'denied' ? 403 : 404);
}

$snapDir = realpath(WA_PUBLIC . '/uploads/snapshots/' . $snapshotId);

if ($snapDir === false || !is_dir($snapDir)) {
    $deny(404);
}

$fullPath = realpath($snapDir . '/' . $base);

if ($fullPath === false || !is_file($fullPath)) {
    $deny(404);
}

// realpath phải nằm trong thư mục snapshot (chặn ../, symlink thoát ra).
$normFull = str_replace('\\', '/', $fullPath);
$normDir = str_replace('\\', '/', $snapDir);

if (!str_starts_with($normFull, $normDir . '/')) {
    $deny(404);
}

$bytes = @file_get_contents($fullPath);

if ($bytes === false || $bytes === '') {
    $deny(404);
}

// MIME từ nội dung thật — chỉ raster hợp lệ.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) ($finfo->buffer($bytes) ?: '');

$allowed = [
    'image/jpeg' => 'jpeg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

if (!isset($allowed[$mime])) {
    $mimeCheck = wa_capture_verify_image($bytes);

    if ($mimeCheck === null || !isset($allowed[$mimeCheck])) {
        $deny(404);
    }

    $mime = $mimeCheck;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');

echo $bytes;
exit;
