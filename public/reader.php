<?php
/**
 * reader.php — Trình đọc ngoại tuyến, không gian làm việc theo phiên bản (P-READ).
 *
 * Bám theo bản tham chiếu (code3.html): nền giấy ngà ivory, cột đọc tối đa 780px,
 * thanh công cụ có nút A- / A+ (JavaScript thuần), tiến độ đọc, hộp nguồn gốc,
 * một bài viết tiếng Việt đầy đủ và bảng tổng hợp phương pháp làm sạch.
 *
 * PHẦN THÊM Ở P-READ (mọi dữ liệu đều lấy từ CSDL, không có gì hard-code):
 *   - Đầu trang nguồn gốc (provenance): tiêu đề bài, tên thư viện + link quay lại,
 *     URL nguồn (hiện dạng chữ VÀ dạng liên kết ngoài), tên miền, nhãn
 *     "Phiên bản N" cùng mã SNAP-<id>, thời điểm tải định dạng kiểu Việt Nam và
 *     mã HTTP của lần tải đó.
 *   - Bộ chuyển phiên bản: danh sách TẤT CẢ bản thành công của cùng trang, sắp
 *     xếp version_no giảm dần, kèm thời điểm tải; bản đang xem được tô sáng.
 *     Có thêm liên kết phiên bản trước / sau khi tồn tại.
 *   - Nút "Lấy phiên bản mới": CHỈ hiện với chủ sở hữu / biên tập viên của thư
 *     viện (quản trị viên luôn thấy). POST sang public/refetch.php.
 *   - Bảng ghi chú của ĐÚNG snapshot này, kèm biểu mẫu thêm cho mọi người xem
 *     được thư viện; nút sửa/xoá chỉ hiện với tác giả (quản trị viên xoá được
 *     mọi ghi chú).
 *
 * PHÂN QUYỀN:
 *   - Khách            -> chuyển hướng login.php (require_login).
 *   - Đã đăng nhập nhưng không thuộc thư viện -> trang 403 tiếng Việt.
 *   - Snapshot không tồn tại hoặc fetch_status <> 'success' -> 404.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
// A9: bộ đếm tài nguyên cục bộ còn trên đĩa (thay nhãn "Ngoại tuyến 100%").
require_once __DIR__ . '/../templates/wa_metrics.php';

require_login();

// reader.php nhận id snapshot dạng số: ?id=<snapshot id>
$snapshotId = (int) ($_GET['id'] ?? 0);

/** Nhãn + mã lỗi tiếng Việt riêng cho mỗi nguyên nhân. */
$readerErrorTitles = [
    'error_invalid_url' => 'URL không hợp lệ',
    'error_blocked'     => 'URL không thuộc danh sách được duyệt',
    'error_404'         => 'Trang gốc trả về 404',
    'error_http'        => 'Máy chủ nguồn trả về lỗi',
    'error_timeout'     => 'Hết thời gian chờ khi tải trang',
    'error_no_content'  => 'Trang không có nội dung đọc được',
    'error_403'         => 'Máy chủ nguồn từ chối truy cập (403)',
    'error_429'         => 'Bị giới hạn tần suất yêu cầu (429)',
    'error_5xx'         => 'Máy chủ nguồn đang lỗi (5xx)',
    'error_ssl'         => 'Lỗi chứng thư SSL',
    'error_login'       => 'Trang yêu cầu đăng nhập',
    'error_captcha'     => 'Trang có bắt buộc xác minh CAPTCHA',
    'error_unsafe'      => 'URL trỏ vào mạng nội bộ (bị chặn SSRF)',
];

/** Flash do refetch.php / note.php gửi lại sau khi chuyển hướng. */
$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

// --- Giải quyết ngữ cảnh: snapshot -> trang -> thư viện, kiểm tra quyền ---
$context = $snapshotId > 0 ? wa_reader_context($snapshotId) : ['status' => 'not_found'];

/* ---------------------------------------------------------------------------
 * 403 — đã đăng nhập nhưng KHÔNG phải thành viên của thư viện chứa snapshot.
 * ------------------------------------------------------------------------- */
if ($context['status'] === 'denied') {
    http_response_code(403);
    $waDeniedContext = [
        'title' => 'Bản lưu này thuộc thư viện khác',
        'intro' => 'Bản lưu bạn muốn mở nằm trong một thư viện mà bạn không phải là thành viên. '
                  . 'Chỉ chủ sở hữu, biên tập viên, người xem của thư viện đó hoặc quản trị viên mới đọc được.',
    ];
    require __DIR__ . '/../templates/error_no_permission.php';
    exit;
}

/* ---------------------------------------------------------------------------
 * 404 — không tồn tại, không phải bản thành công, hoặc không đủ quyền đọc.
 * ------------------------------------------------------------------------- */
if ($context['status'] !== 'ok') {
    http_response_code(404);
    $pageTitle = 'Không tìm thấy bản lưu';
    require __DIR__ . '/../templates/header_app.php';
    ?>
    <div class="empty">
      <span class="ms ms-32">search_off</span>
      <h3>Không tìm thấy bản lưu</h3>
      <p>Bản lưu này không tồn tại hoặc bạn không có quyền truy cập.</p>
      <div class="row" style="justify-content:center;margin-top:16px">
        <a class="btn btn--primary" href="<?= wa_e(wa_url('libraries.php')) ?>">Về thư viện của tôi</a>
      </div>
    </div>
    <?php
    require __DIR__ . '/../templates/footer_app.php';
    exit;
}

/** @var array<string,mixed> $article */
$article       = $context['article'];
$librarySlug  = (string) $article['library_slug'];
$libraryName  = (string) $article['library_name'];
$libraryUrl   = wa_url('library.php?slug=' . urlencode($librarySlug));
$versions     = $context['versions'];
$prevVersion  = $context['prev'];
$nextVersion  = $context['next'];
$notes        = $context['notes'];
$canRefetch   = (bool) $context['can_refetch'];
$versionNo    = (int) $article['version_no'];
$httpStatus   = (int) $article['http_status'];
$currentUser  = current_user();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$isAdmin      = wa_is_admin();
$roleLabels   = wa_member_role_labels();
$role         = (string) $context['role'];

// A9: số tài nguyên cục bộ CÒN trên đĩa của bản lưu đang mở (đo thật).
$snapshotAssets = wa_metrics_snapshot_assets((int) $article['id']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= wa_e($article['title']) ?> — Trình đọc WebArchive</title>
<link rel="icon" type="image/svg+xml" href="<?= wa_e(wa_asset('img/logo-mark.svg')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Merriweather:ital,wght@0,300;0,400;0,700;1,400&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link rel="stylesheet" href="<?= wa_e(wa_asset('css/app.css')) ?>">
<style>
    /* ---- Bố cục P-READ: đầu nguồn gốc, chuyển phiên bản, bảng ghi chú ---- */
    .reader__col { max-width: 960px; }

    .wa-prov { border:1px solid var(--wa-border); border-radius:12px; background:#fff; overflow:hidden; margin-bottom:20px; }
    .wa-prov__head { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between;
                     padding:10px 14px; background:linear-gradient(180deg,#F7FAFC,#EEF3F8); border-bottom:1px solid var(--wa-border); }
    .wa-prov__lib  { display:inline-flex; align-items:center; gap:6px; font-weight:600; color:var(--wa-primary); text-decoration:none; font-size:13px; }
    .wa-prov__lib:hover { text-decoration:underline; }
    .wa-prov__body { padding:14px; display:grid; gap:10px; }
    .wa-prov__row  { display:grid; grid-template-columns:150px 1fr; gap:10px; align-items:start; font-size:13px; }
    .wa-prov__key  { color:#74777E; font-weight:500; }
    .wa-prov__val  { color:var(--wa-text); word-break:break-word; }
    .wa-prov__url  { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px; color:var(--wa-text-soft); }
    .wa-prov__ext  { display:inline-flex; align-items:center; gap:4px; font-size:12px; color:var(--wa-action); text-decoration:none; font-weight:500; }
    .wa-prov__ext:hover { text-decoration:underline; }

    .wa-ver__chip { display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px;
                    background:var(--wa-teal-soft,#E3F4F1); color:var(--wa-teal-deep); font-weight:600; font-size:12px; }

    .wa-ver__list { list-style:none; margin:12px 0 0; padding:0; border:1px solid var(--wa-border); border-radius:10px; overflow:hidden; }
    .wa-ver__item + .wa-ver__item { border-top:1px solid var(--wa-border); }
    .wa-ver__link { display:flex; gap:10px; align-items:center; justify-content:space-between; padding:9px 12px;
                    font-size:13px; color:var(--wa-text); text-decoration:none; background:#fff; }
    .wa-ver__link:hover { background:#F5F7F9; }
    .wa-ver__link.is-current { background:#EAF0F8; font-weight:600; box-shadow:inset 3px 0 0 var(--wa-action); }
    .wa-ver__num  { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; color:var(--wa-action); }
    .wa-ver__meta { color:#74777E; font-size:12px; }
    .wa-ver__nav  { display:flex; gap:8px; margin-top:10px; }

    .wa-notes { border:1px solid var(--wa-border); border-radius:12px; background:#fff; padding:14px; margin-top:24px; }
    .wa-notes__item { border:1px solid var(--wa-border); border-radius:10px; padding:10px 12px; background:#FCFDFE; }
    .wa-notes__item + .wa-notes__item { margin-top:10px; }
    .wa-notes__meta { display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:space-between; font-size:12px; }
    .wa-notes__author { font-weight:600; color:var(--wa-primary); }
    .wa-notes__time   { color:#74777E; }
    .wa-notes__text   { margin:8px 0 0; font-size:14px; line-height:1.7; color:var(--wa-text); white-space:pre-wrap; }
    .wa-notes__acts   { display:flex; gap:6px; margin-top:8px; }
    .wa-notes form { display:inline; }

    .wa-editor { display:none; margin-top:10px; }
    .wa-editor.is-open { display:block; }
    .wa-editor textarea { width:100%; min-height:88px; font:inherit; font-size:14px; padding:8px 10px;
                          border:1px solid var(--wa-border); border-radius:8px; resize:vertical; }
    .wa-editor__row { display:flex; gap:8px; margin-top:8px; }

    .wa-flash { border-radius:10px; padding:10px 12px; font-size:13px; margin-bottom:16px;
                display:flex; gap:8px; align-items:flex-start; }
    .wa-flash--ok   { background:#E7F6F3; border:1px solid #BFE5DE; color:#14655C; }
    .wa-flash--err  { background:#FDECEC; border:1px solid #F3C9C9; color:#8A2B2B; }
</style>
</head>
<body class="reader" id="reader-body">

<div class="reader__progress" id="reader-progress"></div>

<!-- ===================== THANH CÔNG CỤ ===================== -->
<header class="reader__bar">
  <a class="reader__back" href="<?= wa_e($libraryUrl) ?>">
    <span class="ms ms-16">arrow_back</span>
    <span class="reader__back-label">Quay lại thư viện</span>
  </a>

  <div class="reader__status">
    <span class="dot dot--live"></span>
    <span>Bài viết đã lưu trữ an toàn</span>
    <span class="t-muted">/</span>
    <span>Snapshot:</span>
    <strong class="t-mono">SNAP-<?= (int) $article['id'] ?></strong>
  </div>

  <div class="reader__tools">
    <!-- Điều khiển cỡ chữ -->
    <div class="font-toggle" title="Thay đổi kích thước chữ">
      <button type="button" id="btn-font-decrease" aria-label="Giảm kích thước phông chữ">A-</button>
      <span></span>
      <button type="button" id="btn-font-increase" aria-label="Tăng kích thước phông chữ">A+</button>
    </div>

    <!-- Nhạc nền đọc -->
    <div class="font-toggle" title="Nền đọc">
      <button type="button" id="btn-theme-warm" class="is-active" style="width:auto;padding:0 8px">Ấm áp</button>
      <span></span>
      <button type="button" id="btn-theme-white" style="width:auto;padding:0 8px">Trắng tinh</button>
      <span></span>
      <button type="button" id="btn-theme-night" style="width:auto;padding:0 8px">Ban đêm</button>
    </div>

    <button class="btn btn--outline btn--sm" type="button" onclick="window.print()" title="Xuất tài liệu bảo lưu PDF">
      <span class="ms ms-16">picture_as_pdf</span>
      <span class="reader__export-label">Xuất PDF</span>
    </button>

    <!-- P-LIB: xuất bản lưu ra TỆP HTML ĐỘC LẬP (ảnh nhúng base64, mở offline).
         Đây là liên kết tải xuống (GET), không phải hành động thay đổi dữ liệu,
         nên không cần token CSRF. Nút "Xuất PDF" ở trên vẫn hoạt động như cũ. -->
    <a class="btn btn--dark btn--sm"
       href="<?= wa_e(wa_url('export.php?id=' . (int) $article['id'])) ?>"
       title="Tải bản lưu này thành tệp HTML độc lập, mở được ngoại tuyến">
      <span class="ms ms-16">download</span>
      <span class="reader__export-label">Xuất bản lưu</span>
    </a>

    <!-- A9: nhãn "Ngoại tuyến 100%" là một con số BỊA. Thay bằng số đo thật:
         bao nhiêu tài nguyên cục bộ của bản lưu này còn trên đĩa. -->
    <span class="chip chip--teal">
      <span class="ms ms-14">shield</span>
      <?= $snapshotAssets['total'] > 0
          ? $snapshotAssets['present'] . '/' . $snapshotAssets['total'] . ' tài nguyên cục bộ'
          : 'Đọc ngoại tuyến' ?>
    </span>
  </div>
</header>

<!-- ===================== CỘT ĐỌC ===================== -->
<article class="reader__col">
  <!-- Flash sau khi tải lại hoặc thao tác ghi chú -->
  <?php if ($flash !== null): ?>
    <div class="wa-flash <?= (string) ($flash['type'] ?? '') === 'success' ? 'wa-flash--ok' : 'wa-flash--err' ?>" role="status">
      <span class="ms ms-18 <?= (string) ($flash['type'] ?? '') === 'success' ? 'ms-fill' : '' ?>">
        <?= (string) ($flash['type'] ?? '') === 'success' ? 'check_circle' : 'error_outline' ?>
      </span>
      <span>
        <?php if (isset($flash['title']) && (string) $flash['title'] !== ''): ?>
          <strong><?= wa_e((string) $flash['title']) ?>.</strong>
        <?php endif; ?>
        <?= wa_e((string) ($flash['text'] ?? '')) ?>
        <?php if (isset($flash['code']) && (string) $flash['code'] !== ''): ?>
          <br><span class="t-mono">Mã lỗi: <?= wa_e((string) $flash['code']) ?>
            — <?= wa_e($readerErrorTitles[(string) $flash['code']] ?? 'Lỗi') ?></span>
        <?php endif; ?>
      </span>
    </div>
  <?php endif; ?>

  <header class="reader__head">
    <div class="row row--between" style="gap:8px">
      <span class="chip chip--blue">
        <span class="ms ms-14">terminal</span>
        <?= wa_e(strtoupper($libraryName)) ?>
      </span>
      <span class="wa-ver__chip">
        <span class="ms ms-14">history</span>
        Phiên bản <?= $versionNo ?> · SNAP-<?= (int) $article['id'] ?>
      </span>
    </div>

    <h1 class="reader__title"><?= wa_e($article['title']) ?></h1>

    <!-- ===================== ĐẦU NGUỒN GỐC (PROVENANCE) ===================== -->
    <div class="wa-prov">
      <div class="wa-prov__head">
        <a class="wa-prov__lib" href="<?= wa_e($libraryUrl) ?>">
          <span class="ms ms-16">folder</span>
          Thư viện: <?= wa_e($libraryName) ?>
        </a>
        <span class="t-label-sm t-muted">Vai trò của bạn:
          <strong><?= wa_e($roleLabels[$role] ?? 'Người xem') ?></strong></span>
      </div>

      <div class="wa-prov__body">
        <div class="wa-prov__row">
          <span class="wa-prov__key">Tiêu đề bài viết</span>
          <span class="wa-prov__val"><?= wa_e($article['title']) ?></span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Thư viện</span>
          <span class="wa-prov__val">
            <a href="<?= wa_e($libraryUrl) ?>"><?= wa_e($libraryName) ?></a>
            <span class="t-muted">(mở lại library.php?slug=<?= wa_e($librarySlug) ?>)</span>
          </span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">URL nguồn</span>
          <span class="wa-prov__val">
            <div class="wa-prov__url"><?= wa_e($article['source_url']) ?></div>
            <a class="wa-prov__ext" href="<?= wa_e($article['source_url']) ?>"
               target="_blank" rel="noopener noreferrer">
              <span class="ms ms-14">open_in_new</span>
              Mở trang gốc trong tab mới
            </a>
          </span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Tên miền</span>
          <span class="wa-prov__val"><?= wa_e($article['domain']) ?></span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Phiên bản</span>
          <span class="wa-prov__val">
            <strong>Phiên bản <?= $versionNo ?></strong>
            <span class="t-mono">· SNAP-<?= (int) $article['id'] ?></span>
            <span class="t-muted">· mã trang #<?= (int) $article['page_id'] ?></span>
          </span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Thời điểm tải</span>
          <span class="wa-prov__val"><?= wa_e($article['saved_at']) ?></span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Mã HTTP lần tải</span>
          <span class="wa-prov__val">
            <strong><?= $httpStatus ?></strong>
            <span class="t-muted">— tải thành công, nội dung đã lưu về máy chủ nội bộ</span>
          </span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Người lưu</span>
          <span class="wa-prov__val"><?= wa_e($article['creator_name']) ?></span>
        </div>

        <div class="wa-prov__row">
          <span class="wa-prov__key">Dung lượng</span>
          <span class="wa-prov__val">
            <?= wa_e($article['size_label']) ?>
            <span class="t-muted">(<?= (int) $article['image_count'] ?> ảnh đã tải về máy chủ)</span>
          </span>
        </div>
      </div>
    </div>

    <!-- ===================== CHUYỂN PHIÊN BẢN ===================== -->
    <div class="wa-prov">
      <div class="wa-prov__head">
        <span class="wa-prov__lib" style="cursor:default">
          <span class="ms ms-16">history</span>
          Lịch sử phiên bản (<?= count($versions) ?> bản thành công)
        </span>
        <?php if ($canRefetch): ?>
          <form method="post" action="<?= wa_e(wa_url('refetch.php')) ?>">
            <?= wa_csrf_field() ?>
            <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">
            <button class="btn btn--dark btn--sm" type="submit" data-wa-refetch>
              <span class="ms ms-16">cloud_download</span>
              <span>Lấy phiên bản mới</span>
            </button>
          </form>
        <?php endif; ?>
      </div>

      <div class="wa-prov__body">
        <ul class="wa-ver__list">
          <?php foreach ($versions as $version): ?>
            <li class="wa-ver__item">
              <a class="wa-ver__link <?= $version['is_current'] ? 'is-current' : '' ?>"
                 href="<?= wa_e(wa_url('reader.php?id=' . $version['id'])) ?>"
                 <?= $version['is_current'] ? 'aria-current="true"' : '' ?>>
                <span>
                  <span class="wa-ver__num">Phiên bản <?= (int) $version['version_no'] ?></span>
                  <?php if ($version['is_current']): ?>
                    <span class="chip chip--plain" style="margin-left:6px">đang xem</span>
                  <?php endif; ?>
                  <span class="wa-ver__meta">· SNAP-<?= (int) $version['id'] ?></span>
                </span>
                <span class="wa-ver__meta">
                  <?= wa_e($version['fetched_label']) ?> · HTTP <?= (int) $version['http_status'] ?>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php if ($prevVersion !== null || $nextVersion !== null): ?>
          <div class="wa-ver__nav">
            <?php if ($nextVersion !== null): ?>
              <a class="btn btn--outline btn--sm" href="<?= wa_e(wa_url('reader.php?id=' . (int) $nextVersion['id'])) ?>">
                <span class="ms ms-16">chevron_left</span>
                Bản cũ hơn (v<?= (int) $nextVersion['version_no'] ?>)
              </a>
            <?php endif; ?>
            <?php if ($prevVersion !== null): ?>
              <a class="btn btn--outline btn--sm" href="<?= wa_e(wa_url('reader.php?id=' . (int) $prevVersion['id'])) ?>">
                Bản mới hơn (v<?= (int) $prevVersion['version_no'] ?>)
                <span class="ms ms-16">chevron_right</span>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="reader__divider"></div>
  </header>

  <!-- ===================== NỘI DUNG BÀI VIẾT (từ bảng snapshots) ===================== -->
  <div class="reader__body" id="reading-content">
<?= wa_snapshot_html_auth((string) $article['content_html'], $snapshotId) ?>
  </div>

  <!-- ===================== GHI CHÚ CỦA PHIÊN BẢN NÀY ===================== -->
  <section class="wa-notes" aria-labelledby="wa-notes-title">
    <div class="wa-prov__head" style="margin:-14px -14px 14px">
      <span class="wa-prov__lib" style="cursor:default">
        <span class="ms ms-16">sticky_note_2</span>
        <span id="wa-notes-title">Ghi chú của phiên bản <?= $versionNo ?></span>
      </span>
      <span class="t-label-sm t-muted"><?= count($notes) ?> ghi chú · chỉ thuộc phiên bản này</span>
    </div>

    <?php if ($notes === []): ?>
      <p class="t-body-sm t-muted" style="margin:0 0 12px">
        Chưa có ghi chú nào cho phiên bản <?= $versionNo ?>.
        Ghi chú của một phiên bản khác không hiện ở đây.
      </p>
    <?php else: ?>
      <?php foreach ($notes as $note): ?>
        <?php
        // Chỉ tác giả được sửa/xoá; quản trị viên chỉ được XOÁ (đường riêng).
        $isAuthor = ((int) $note['user_id'] === $currentUserId);
        $canEdit  = $isAuthor;
        $canDel   = $isAuthor || $isAdmin;
        ?>
        <div class="wa-notes__item" id="note-<?= (int) $note['id'] ?>">
          <div class="wa-notes__meta">
            <span>
              <span class="wa-notes__author"><?= wa_e($note['author_name']) ?></span>
              <?php if ($note['edited']): ?>
                <span class="chip chip--plain" style="margin-left:6px" title="Sửa lần cuối: <?= wa_e($note['edited_label']) ?>">đã sửa</span>
              <?php endif; ?>
            </span>
            <span class="wa-notes__time"><?= wa_e($note['created_label']) ?></span>
          </div>

          <p class="wa-notes__text"><?= wa_e($note['content']) ?></p>

          <?php if ($canEdit || $canDel): ?>
            <div class="wa-notes__acts">
              <?php if ($canEdit): ?>
                <button class="btn btn--outline btn--sm" type="button"
                        onclick="waNoteEdit(<?= (int) $note['id'] ?>)">
                  <span class="ms ms-16">edit</span> Sửa
                </button>
              <?php endif; ?>

              <?php if ($canDel): ?>
                <form method="post" action="<?= wa_e(wa_url('note.php')) ?>"
                      onsubmit="return confirm('Xoá ghi chú này?');">
                  <?= wa_csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">
                  <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                  <button class="btn btn--outline btn--sm" type="submit">
                    <span class="ms ms-16">delete</span> Xoá
                  </button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($canEdit): ?>
            <form class="wa-editor" id="wa-note-edit-<?= (int) $note['id'] ?>" method="post"
                  action="<?= wa_e(wa_url('note.php')) ?>">
              <?= wa_csrf_field() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">
              <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
              <textarea name="content" aria-label="Nội dung ghi chú"><?= wa_e($note['content']) ?></textarea>
              <div class="wa-editor__row">
                <button class="btn btn--dark btn--sm" type="submit">Lưu thay đổi</button>
                <button class="btn btn--outline btn--sm" type="button"
                        onclick="waNoteCancel(<?= (int) $note['id'] ?>)">Huỷ</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Mọi người xem được thư viện đều thêm được ghi chú -->
    <form method="post" action="<?= wa_e(wa_url('note.php')) ?>" style="margin-top:14px">
      <?= wa_csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="snapshot_id" value="<?= (int) $article['id'] ?>">
      <div class="field">
        <label for="wa-note-content">Thêm ghi chú cho phiên bản <?= $versionNo ?></label>
        <textarea class="input" id="wa-note-content" name="content" rows="3"
                  placeholder="Nhận xét về nội dung của đúng phiên bản này…" style="width:100%"></textarea>
      </div>
      <div class="row" style="margin-top:8px;gap:8px">
        <button class="btn btn--dark" type="submit">
          <span class="ms ms-18">add_comment</span>
          <span>Ghi chú</span>
        </button>
        <span class="t-label-sm t-muted">Ghi chú chỉ thuộc phiên bản <?= $versionNo ?>, không dùng chung với các bản khác.</span>
      </div>
    </form>
  </section>

  <!-- ===================== CHÂN TRANG ĐỌC ===================== -->
  <div class="reader__divider" style="margin-top:40px"></div>

  <footer class="reader__foot">
    <div class="row t-label-md" style="gap:8px;color:var(--wa-text-muted)">
      <span class="ms ms-16 ms-fill" style="color:var(--wa-teal-deep)">check_circle</span>
      <span>Đã đọc hết bài viết • Lưu trữ bởi WebArchive</span>
    </div>
    <a class="btn btn--primary" href="<?= wa_e($libraryUrl) ?>">
      <span class="ms ms-16">arrow_back</span>
      <span>Trở về Thư viện <?= wa_e($libraryName) ?></span>
    </a>
  </footer>
</article>

<script>
  // ---- Cỡ chữ A- / A+ (JavaScript thuần) ----
  (function () {
    var content = document.getElementById('reading-content');
    var dec = document.getElementById('btn-font-decrease');
    var inc = document.getElementById('btn-font-increase');
    var steps = [16, 17, 18, 19, 20, 22];
    var index = 2;

    function apply() {
      content.style.fontSize = steps[index] + 'px';
      content.style.lineHeight = '1.78';
    }

    if (dec) {
      dec.addEventListener('click', function () {
        if (index > 0) { index--; apply(); }
      });
    }
    if (inc) {
      inc.addEventListener('click', function () {
        if (index < steps.length - 1) { index++; apply(); }
      });
    }
  })();

  // ---- Nền đọc ----
  (function () {
    var body = document.getElementById('reader-body');
    var themes = {
      'btn-theme-warm': '',
      'btn-theme-white': 'reader--white',
      'btn-theme-night': 'reader--night'
    };

    Object.keys(themes).forEach(function (id) {
      var btn = document.getElementById(id);
      if (!btn) { return; }
      btn.addEventListener('click', function () {
        Object.keys(themes).forEach(function (otherId) {
          var other = document.getElementById(otherId);
          if (other) { other.classList.remove('is-active'); }
        });
        btn.classList.add('is-active');
        body.className = 'reader ' + themes[id];
      });
    });
  })();

  // ---- Tiến độ đọc ----
  (function () {
    var bar = document.getElementById('reader-progress');
    function update() {
      var doc = document.documentElement;
      var max = doc.scrollHeight - doc.clientHeight;
      var pct = max > 0 ? (doc.scrollTop / max) * 100 : 0;
      bar.style.width = Math.min(100, Math.max(0, pct)) + '%';
    }
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
  })();

  // ---- Sửa / huỷ ghi chú (JavaScript thuần) ----
  function waNoteEdit(noteId) {
    var box = document.getElementById('wa-note-edit-' + noteId);
    if (!box) { return; }
    box.classList.add('is-open');
    var area = box.querySelector('textarea');
    if (area) { area.focus(); area.setSelectionRange(area.value.length, area.value.length); }
  }

  function waNoteCancel(noteId) {
    var box = document.getElementById('wa-note-edit-' + noteId);
    if (box) { box.classList.remove('is-open'); }
  }

  // ---- Nút tải lại: khoá nút khi form ĐÃ bắt đầu gửi đi ----
  // KHÔNG khoá nút trong sự kiện click: trình duyệt thấy nút submit
  // bị disabled trước lúc gửi thì huỷ luôn việc gửi form (nút kẹt ở
  // "Đang tải lại…" mà server không nhận được POST nào). Khoá trong
  // sự kiện submit thì việc gửi đã bắt đầu nên không bị huỷ, vẫn
  // chống được bấm đúp.
  (function () {
    var btn = document.querySelector('[data-wa-refetch]');
    if (!btn || !btn.form) { return; }
    btn.form.addEventListener('submit', function () {
      btn.setAttribute('disabled', 'disabled');
      var label = btn.querySelector('span:last-child');
      if (label) { label.textContent = 'Đang tải lại…'; }
    });
  })();
</script>
</body>
</html>