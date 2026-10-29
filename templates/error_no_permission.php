<?php
/**
 * error_no_permission.php
 * -----------------------------------------------------------------------------
 * Trang thông báo tiếng Việt khi người dùng đã đăng nhập nhưng không có quyền.
 *
 * Mặc định dành cho khu vực quản trị. Trình đọc (P-READ) gọi template này khi
 * người đọc KHÔNG phải thành viên của thư viện chứa bản lưu, nên đặt trước
 * $waDeniedContext = [...] để câu chữ đúng ngữ cảnh.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

http_response_code(403);

// Ngữ cảnh tuỳ biến (do trình đọc đặt trước khi require). Mặc định: quản trị.
$waDeniedContext = is_array($waDeniedContext ?? null) ? $waDeniedContext : [];

$waDeniedTitle = (string) ($waDeniedContext['title'] ?? 'Không có quyền truy cập');
$waDeniedIntro = (string) ($waDeniedContext['intro'] ?? '');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= wa_e($waDeniedTitle) ?> — WebArchive</title>
<link rel="icon" type="image/svg+xml" href="<?= wa_e(wa_asset('img/logo-mark.svg')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Merriweather:ital,wght@0,300;0,400;0,700;1,400&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link rel="stylesheet" href="<?= wa_e(wa_asset('css/app.css')) ?>">
</head>
<body>
<div class="error-page">
  <div class="error-page__box">
    <span class="ms ms-32" style="font-size:40px;color:var(--wa-error)">lock</span>
    <h1><?= wa_e($waDeniedTitle) ?></h1>
    <p>
      <?php if ($waDeniedIntro !== ''): ?>
        <?= wa_e($waDeniedIntro) ?>
        Tài khoản của bạn hiện đang đăng nhập với vai trò
        <strong><?= wa_e(current_user_role_label()) ?></strong> và không được mở bản lưu này.
      <?php else: ?>
      Khu vực <strong>Quản trị hệ thống</strong> chỉ dành cho tài khoản có vai trò
      <strong>Quản trị viên</strong>. Tài khoản của bạn hiện đang đăng nhập với vai trò
      <strong><?= wa_e(current_user_role_label()) ?></strong> và không được phép xem trang này.
      <?php endif; ?>
    </p>
    <div class="row">
      <a class="btn btn--primary" href="<?= wa_e(wa_url('libraries.php')) ?>">
        <span class="ms ms-18">arrow_back</span>
        <span>Về thư viện của tôi</span>
      </a>
      <a class="btn btn--outline" href="<?= wa_e(wa_url('logout.php')) ?>">Đăng xuất</a>
    </div>
  </div>
</div>
</body>
</html>
