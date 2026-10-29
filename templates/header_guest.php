<?php
/**
 * header_guest.php
 * -----------------------------------------------------------------------------
 * LAYOUT CHUNG CHO KHÁCH (guest) — dùng cho index.php, login.php, register.php.
 *
 * ⚠ LƯU Ý QUAN TRỌNG (defect đã sửa so với bản tham chiếu):
 *   Bản tham chiếu dùng NHẦM header đã đăng nhập cho trang chủ (có user chip,
 *   nút "Đăng xuất" và menu workspace) — trang chủ là trang dành cho KHÁCH.
 *   Header dưới đây chỉ có điều hướng công khai + HAI hành động RIÊNG BIỆT:
 *   "Đăng nhập" (ghost) và "Đăng ký" (primary).
 *   TUYỆT ĐỐI không trộn hai loại header này.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$pageTitle = $pageTitle ?? 'WebArchive — Lưu tri thức, đọc lại bất cứ lúc nào';
$bodyClass = $bodyClass ?? 'landing';
// Biến tuỳ chọn: ẩn thanh trên cùng (dùng cho trang đăng nhập / đăng ký với
// thẻ xác thực độc lập) và ẩn chân trang công khai.
$hideTopbar = $hideTopbar ?? false;
$hideFooter = $hideFooter ?? false;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="WebArchive — lưu nội dung bài viết từ trang Web được phép, sắp xếp vào thư viện cá nhân và đọc lại ngoại tuyến.">
<title><?= wa_e($pageTitle) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= wa_e(wa_asset('img/logo-mark.svg')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Merriweather:ital,wght@0,300;0,400;0,700;1,400&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link rel="stylesheet" href="<?= wa_e(wa_asset('css/app.css')) ?>">
</head>
<body class="<?= wa_e($bodyClass) ?>">

<?php if (!$hideTopbar): ?>
<header class="topbar">
  <div class="topbar__accent"></div>
  <div class="topbar__inner">
    <div class="row" style="gap:24px">
      <a class="brand" href="<?= wa_e(wa_url('index.php')) ?>">
        <img class="brand__mark" src="<?= wa_e(wa_asset('img/logo-mark.svg')) ?>" alt="Biểu trưng WebArchive" width="32" height="32">
        <span class="brand__text">
          <span class="brand__name">WebArchive</span>
          <span class="brand__tag">Thư viện Web cá nhân</span>
        </span>
      </a>

      <nav class="topnav" aria-label="Điều hướng chính">
        <a href="<?= wa_e(wa_url('index.php')) ?>" class="is-active">Trang chủ</a>
        <a href="<?= wa_e(wa_url('index.php#tinh-nang')) ?>">Tính năng</a>
        <a href="<?= wa_e(wa_url('index.php#quy-trinh')) ?>">Cách hoạt động</a>
        <a href="<?= wa_e(wa_url('index.php#khong-gian-doc')) ?>">Không gian đọc</a>
      </nav>
    </div>

    <!-- Header KHÁCH: hai hành động RIÊNG BIỆT — "Đăng nhập" (ghost) cho người
         đã có tài khoản và "Đăng ký" (primary) cho người mới. Trước đây chỉ có
         một nút "Tạo tài khoản" nên người dùng quay lại không có lối đăng nhập. -->
    <div class="guest-cta">
      <a class="btn btn--ghost btn--login" href="<?= wa_e(wa_url('login.php')) ?>">Đăng nhập</a>
      <a class="btn btn--primary btn--register" href="<?= wa_e(wa_url('register.php')) ?>">Đăng ký</a>
    </div>
  </div>
</header>
<?php endif; ?>

<main>
