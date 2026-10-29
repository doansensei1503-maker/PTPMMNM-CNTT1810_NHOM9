<?php
/**
 * header_app.php
 * -----------------------------------------------------------------------------
 * LAYOUT CHUNG CHO ỨNG DỤNG (đã đăng nhập) — thanh trên cùng + sidebar.
 *
 * Khác hoàn toàn với header_guest.php: đây là header đã đăng nhập (user chip,
 * nút Đăng xuất, menu workspace) và KHÔNG được dùng cho trang chủ khách.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
// A8: sidebar cần bộ đếm "ảnh cục bộ còn trên đĩa" (đối chiếu tệp thật).
require_once __DIR__ . '/wa_metrics.php';

$pageTitle = $pageTitle ?? 'WebArchive';
$user = current_user();
$active = current_page();

// ---------------------------------------------------------------------------
// P-FIX-UI (5): DANH SÁCH THƯ VIỆN ĐỘNG của chính người dùng đang đăng nhập.
//
// Trước đây sidebar có một mục cống định trỏ tới một thư viện cụ thể
// không tồn tại trong CSDL hiện tại, nên mục đó dẫn tới trang "Không tìm thấy
// thư viện" và vẫn bị tô sáng "đang hoạt động" khi người dùng mở một thư viện
// khác.
// Nay danh sách thư viện được nạp từ CSDL qua lớp truy vấn sẵn có
// (wa_libraries() trong src/queries.php) — cùng thứ tự với trang libraries.php
// (ORDER BY l.id) — nên điều hướng không còn hardcode bất kỳ thư viện nào.
//
// Lưu ý: wa_libraries() lọc theo bộ lọc quyền sẵn có, nên người dùng chỉ thấy
// đúng những thư viện mình sở hữu hoặc là thành viên.
// ---------------------------------------------------------------------------
$sidebarLibraries = $user !== null ? wa_libraries() : [];

// Slug của thư viện đang mở (chỉ có ý nghĩa trên trang library.php).
// Dùng để tô sáng đúng mục trong danh sách thư viện động.
$openLibrarySlug = '';
if ($active === 'library.php') {
    $openLibrarySlug = trim((string) ($_GET['slug'] ?? ''));
}

$navMain = [
    ['file' => 'libraries.php', 'label' => 'Thư viện của tôi', 'icon' => 'folder'],
    ['file' => 'search.php', 'label' => 'Tìm kiếm nội dung', 'icon' => 'search'],
    ['file' => 'save.php', 'label' => 'Lưu trang Web', 'icon' => 'add_link'],
];

$navAdmin = [
    ['file' => 'admin/index.php', 'label' => 'Tổng quan hệ thống', 'icon' => 'dashboard'],
    ['file' => 'admin/users.php', 'label' => 'Người dùng', 'icon' => 'group'],
    ['file' => 'admin/libraries.php', 'label' => 'Tất cả thư viện', 'icon' => 'folder_special'],
    ['file' => 'admin/allowlist.php', 'label' => 'Tên miền được duyệt', 'icon' => 'verified_user'],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= wa_e($pageTitle) ?> — WebArchive</title>
<link rel="icon" type="image/svg+xml" href="<?= wa_e(wa_asset('img/logo-mark.svg')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Merriweather:ital,wght@0,300;0,400;0,700;1,400&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link rel="stylesheet" href="<?= wa_e(wa_asset('css/app.css')) ?>">
</head>
<body>

<header class="topbar">
  <div class="topbar__accent"></div>
  <div class="topbar__inner">
    <div class="row" style="gap:24px">
      <button class="sidebar__toggle" type="button" id="sidebar-toggle" aria-label="Mở menu">
        <span class="ms ms-18">menu</span>
      </button>

      <a class="brand" href="<?= wa_e(wa_url('libraries.php')) ?>">
        <img class="brand__mark" src="<?= wa_e(wa_asset('img/logo-mark.svg')) ?>" alt="Biểu trưng WebArchive" width="32" height="32">
        <span class="brand__text">
          <span class="brand__name">WebArchive</span>
          <span class="brand__tag">Thư viện Web cá nhân</span>
        </span>
      </a>

      <!-- A7: nhãn ngữ cảnh workspace để thanh trong ứng dụng ĐỌC KHÁC thanh
           khách (header_guest.php không có nhãn này). -->
      <span class="topbar__context">
        <span class="ms ms-16">workspaces</span>
        <span>Workspace</span>
      </span>

      <nav class="topnav" aria-label="Điều hướng chính">
        <a href="<?= wa_e(wa_url('libraries.php')) ?>" class="<?= $active === 'libraries.php' ? 'is-active' : '' ?>">Thư viện của tôi</a>
        <a href="<?= wa_e(wa_url('search.php')) ?>" class="<?= $active === 'search.php' ? 'is-active' : '' ?>">Tìm kiếm</a>
        <a href="<?= wa_e(wa_url('save.php')) ?>" class="<?= $active === 'save.php' ? 'is-active' : '' ?>">Lưu trang Web</a>
      </nav>
    </div>

    <!-- P-FIX-UI (3): cụm tài khoản được chuẩn hoá — avatar, tên hiển thị và
         nút "Đăng xuất" cùng nằm trên trục giữa của thanh trên, chiều cao
         thanh thống nhất (--wa-header-h) trên mọi trang workspace.
         A7: thanh workspace phải khác rõ thanh khách:
           - nhãn ngữ cảnh "Workspace" ngay cạnh thương hiệu;
           - nút "Đăng xuất" là GHOST NAVY (không phải hồng) để không lệch bảng
             màu navy/teal của giao diện. -->
    <div class="topbar__right">
      <div class="offline-pill">
        <span class="dot dot--live"></span>
        <span>Chế độ offline sẵn sàng</span>
      </div>

      <div class="user-chip">
        <span class="avatar" aria-hidden="true"><?= wa_e($user['initials'] ?? 'KH') ?></span>
        <span class="user-chip__meta">
          <span class="user-chip__name"><?= wa_e($user['name'] ?? 'Khách') ?></span>
          <span class="user-chip__role"><?= wa_e($user['role_label'] ?? 'Khách') ?></span>
        </span>
      </div>

      <a class="btn btn--logout topbar__logout" href="<?= wa_e(wa_url('logout.php')) ?>">
        <span class="ms ms-16">logout</span>
        <span>Đăng xuất</span>
      </a>
    </div>
  </div>
</header>

<aside class="sidebar" id="sidebar">
  <div class="sidebar__group">
    <div class="sidebar__label">Bộ sưu tập lưu trữ</div>
    <nav aria-label="Bộ sưu tập lưu trữ">
      <?php foreach ($navMain as $item): ?>
        <a href="<?= wa_e(wa_url($item['file'])) ?>" class="<?= $active === $item['file'] ? 'is-active' : '' ?>">
          <span class="ms ms-18"><?= wa_e($item['icon']) ?></span>
          <span><?= wa_e($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <!-- P-FIX-UI (5): danh sách thư viện ĐỘNG của người dùng đang đăng nhập.
         Không hardcode thư viện nào; mục đang mở được tô sáng theo slug.
         A8: tiêu đề khối này được đổi thành "Danh sách thư viện" vì bản cũ
         ("Thư viện của tôi") TRÙNG với mục điều hướng ngay phía trên. -->
    <?php if ($sidebarLibraries !== []): ?>
      <div class="sidebar__label">Danh sách thư viện</div>
      <nav aria-label="Danh sách thư viện" class="sidebar__libs">
        <?php foreach ($sidebarLibraries as $lib): ?>
          <?php $isOpen = $openLibrarySlug !== '' && $openLibrarySlug === (string) $lib['slug']; ?>
          <a href="<?= wa_e(wa_url('library.php?slug=' . urlencode((string) $lib['slug']))) ?>"
             class="sidebar__lib <?= $isOpen ? 'is-active' : '' ?>"
             <?= $isOpen ? ' aria-current="page"' : '' ?>>
            <span class="ms ms-18"><?= wa_e($lib['icon']) ?></span>
            <span class="sidebar__lib-name"><?= wa_e($lib['name']) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <?php if (($user['role'] ?? '') === 'admin'): ?>
      <div class="sidebar__label">Quản trị hệ thống</div>
      <nav aria-label="Quản trị hệ thống">
        <?php foreach ($navAdmin as $item): ?>
          <a href="<?= wa_e(wa_url($item['file'])) ?>" class="<?= $active === $item['file'] ? 'is-active' : '' ?>">
            <span class="ms ms-18"><?= wa_e($item['icon']) ?></span>
            <span><?= wa_e($item['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

  </div>

  <!-- Khối snapshot ở ĐÁY sidebar, không còn nhãn "Công cụ bảo tồn".
       .sidebar là flex column với justify-content: space-between nên
       nhóm thứ hai này tự dính xuống đáy. -->
  <div class="sidebar__group">
    <div class="stack--sm">
      <a class="btn btn--outline btn--block" href="<?= wa_e(wa_url('save.php')) ?>">
        <span class="ms ms-18">add_link</span>
        <span>Lưu snapshot URL</span>
      </a>
      <!--
        A8: ô "Kho lưu trữ … / N thư viện · M bài viết" ở đáy sidebar đã bị GỠ
        bỏ vì lặp lại y hệt dải số liệu chính ở đầu trang (số thư viện, số bài
        viết, dung lượng) — không thêm thông tin mới nào. Số liệu duy nhất còn
        lại ở đáy sidebar là "ảnh cục bộ còn trên đĩa", vốn KHÔNG có trong dải
        số liệu, nên được giữ lại dưới dạng ghi chú nhỏ.
      -->
      <?php
        // Số ảnh cục bộ CÒN trên đĩa (đối chiếu tệp thật trong public/uploads),
        // tính bằng bộ đếm dùng chung của giao diện.
        $sidebarImagePresent = 0;
        $sidebarImageTotal   = 0;

        if ($sidebarLibraries !== []) {
            $sidebarMetrics = wa_metrics_for_libraries(array_column($sidebarLibraries, 'id'));
            $sidebarImagePresent = (int) $sidebarMetrics['image_present'];
            $sidebarImageTotal   = (int) $sidebarMetrics['image_total'];
        }
        ?>
      <?php if ($sidebarImageTotal > 0): ?>
        <div class="sidebar__note">
          <span class="ms ms-16" style="color:var(--wa-action)">image</span>
          <span><?= $sidebarImagePresent ?>/<?= $sidebarImageTotal ?> ảnh cục bộ còn trên đĩa</span>
        </div>
      <?php endif; ?>
    </div>
  </div>
</aside>

<div class="app-shell">
<main class="page">
  