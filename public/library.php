<?php
/**
 * library.php — Chi tiết thư viện.
 *
 * Bám theo bản tham chiếu (code2.html): breadcrumb, số liệu thư viện, bảng
 * thành viên & nhãn, thẻ bài viết, phân trang và bảng lưu URL hiển thị TRẠNG
 * THÁI THÀNH CÔNG.
 *
 * ── P-LIB: ba khối quản lý MỚI, đều POST + CSRF sang library_action.php ────
 *   1. CÀI ĐẶT THƯ VIỆN (chủ sở hữu / quản trị viên): đổi tên + đổi mức hiển
 *      thị, và XOÁ thư viện (bước xác nhận hai lần, xoá cả dòng CSDL lẫn thư mục
 *      ảnh trên đĩa). Người không phải chủ sở hữu KHÔNG thấy các nút này và nếu
 *      gửi POST thẳng bằng tay thì bị chặn ở tầng SQL (và 403 nếu không thuộc
 *      thư viện).
 *   2. QUẢN LÝ THÀNH VIÊN (chủ sở hữu / quản trị viên): thêm theo email với
 *      vai trò biên tập viên / người xem, đổi vai trò, gỡ thành viên. Dòng chủ
 *      sở hữu không bao giờ bị hạ hoặc gỡ — điều kiện `role <> 'owner'` nằm
 *      TRONG CÂU UPDATE/DELETE.
 *   3. SỬA NHÃN TỪNG BÀI: mỗi thẻ bài viết có ô nhập nhãn phân tách bằng dấu
 *      phẩy cho người có quyền sửa (chủ sở hữu / biên tập viên / quản trị viên);
 *      người xem chỉ xem nhãn.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
// P-FIX-UI (6): số liệu thật từ CSDL (số thành viên theo vai trò, ảnh lỗi…).
require_once __DIR__ . '/../templates/wa_metrics.php';

require_login();

// P-FIX-UI (5): KHÔNG hardcode slug thư viện nào. Trước đây trang mở mặc
// định về một thư viện không còn tồn tại trong CSDL, nên
// mọi lần mở trang mà không truyền ?slug= đều dẫn tới "Không tìm thấy thư viện".
// Nay: thiếu slug -> chuyển hẳn về trang danh sách thư viện.
$slug = trim((string) ($_GET['slug'] ?? ''));

if ($slug === '') {
    header('Location: ' . wa_url('libraries.php'), true, 302);
    exit;
}

$library = wa_find_library($slug);

if ($library === null) {
    http_response_code(404);
    $pageTitle = 'Không tìm thấy thư viện';
    require __DIR__ . '/../templates/header_app.php';
    ?>
    <div class="empty">
      <span class="ms ms-32">search_off</span>
      <h3>Không tìm thấy thư viện</h3>
      <p>Thư viện “<?= wa_e($slug) ?>” không tồn tại hoặc bạn không có quyền truy cập.</p>
      <div class="row" style="justify-content:center;margin-top:16px">
        <a class="btn btn--primary" href="<?= wa_e(wa_url('libraries.php')) ?>">Về thư viện của tôi</a>
      </div>
    </div>
    <?php
    require __DIR__ . '/../templates/footer_app.php';
    exit;
}

$libraryId = (int) $library['id'];

$articles = wa_library_articles($libraryId);
$members  = wa_library_members($libraryId);

/* ---------------------------------------------------------------------------
 * P-LIB / Deliverable 3 — chuẩn bị dữ liệu cho ô SỬA NHÃN trên từng thẻ bài viết.
 * Mỗi bài mang theo:
 *   can_edit     — người xem hiện tại có quyền sửa nhãn bài này không (SQL tự ép).
 *   tag_input    — chuỗi nhãn sẵn để nhét vào ô nhập, phân tách bằng dấu phẩy.
 *   tags_display — danh sách nhãn để hiển thị read-only cho người xem.
 * Quy ước nhãn (đổi từ 27/10): người dùng chỉ cần gõ chữ, dấu '#' tự thêm
 * khi lưu và khi hiển thị (`wa_page_tags()` trả tên kèm '#' kể cả nhãn cũ
 * lưu chưa có '#'), nên không còn tình trạng cái có '#' cái không.
 * ------------------------------------------------------------------------- */
foreach ($articles as &$article) {
    $pageId = (int) $article['id'];

    $article['can_edit']     = wa_page_can_edit_tags($pageId);
    $article['tags_display'] = $article['tags'];
    $article['tag_count']    = count($article['tags']);
    $article['tag_input']    = implode(', ', $article['tags']);
}
unset($article);

/* ---------------------------------------------------------------------------
 * Phân quyền P-LIB (quyết định cái gì hiện, cái gì cho phép gửi):
 *   $canManage  — chủ sở hữu hoặc quản trị viên: đổi tên / đổi hiển thị / xoá /
 *                 quản lý thành viên.
 *   $canEditLib — chủ sở hữu / biên tập viên / quản trị viên: sửa nhãn bài viết
 *                 và xem mục "Lưu thất bại gần đây".
 * Các hàm trong src/queries.php ép lại đúng các điều kiện này TRONG SQL, nên
 * ẩn/hiện nút trên giao diện chỉ là lớp trình bày, không phải hàng rào bảo mật.
 * ------------------------------------------------------------------------- */
$canManage   = wa_can_manage_library($libraryId);
$canEditLib  = wa_can_write_library($libraryId) || wa_is_admin();
$isOwnerHere = ((int) $library['owner_id'] === (int) (wa_actor()['id'] ?? -1));

// P-READ: những trang CHƯA từng có bản thành công không hiện trong danh sách bài
// viết; chúng hiện ở mục "Lưu thất bại gần đây" bên dưới, chỉ dành cho người có
// quyền biên tập (chủ sở hữu / biên tập viên / quản trị viên).
$failedAttempts = $canEditLib
    ? wa_library_failed_attempts($libraryId, 5)
    : [];

// Thông báo flash do library_action.php gửi lại sau khi chuyển hướng.
$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

// Số liệu tổng hợp của thư viện (lấy từ CSDL).
$library['article_count'] = count($articles);
$library['image_count']   = array_sum(array_column($articles, 'image_count'));
$library['size_bytes']    = array_sum(array_column($articles, 'size_bytes'));
$library['member_count']  = count($members);
$library['tags']          = wa_library_tags($libraryId);
$library['tag_count']     = count($library['tags']);
$library['storage_label'] = wa_format_mb($library['size_bytes']);
$library['asset_size']    = $library['storage_label'];
$library['updated']       = 'Cập nhật ' . wa_datetime((string) $library['created_at']);

/* ---------------------------------------------------------------------------
 * P-FIX-UI (6): mọi con số còn lại của trang này phải TÍNH TỪ CSDL.
 *
 *  - "Ảnh lỗi" (broken_images): đếm tài nguyên ảnh mà bảng `assets` ghi nhưng
 *    tệp KHÔNG còn trên đĩa. Trước đây gán cứng 0 và kèm dòng
 *    "Bảo toàn vĩnh viễn 100%" — nói dối khi ảnh đã bị dọn khỏi đĩa.
 *  - "100% nguyên vẹn" (integrity): chỉ hiện khi thật sự không có ảnh nào
 *    hỏng; nếu có thì nói đúng số ảnh hỏng.
 *  - Phân rã vai trò thành viên: đếm trực tiếp từ danh sách thành viên
 *    (wa_library_members) thay vì một dòng tĩnh liệt kê sẵn số thành viên
 *    theo từng vai trò (thực tế thư viện này chỉ có 1 thành viên).
 * ------------------------------------------------------------------------- */
$libMetrics = wa_metrics_for_libraries([$libraryId]);

$library['broken_images'] = $libMetrics['image_missing'];
$library['integrity']     = $libMetrics['snapshots'] === 0
    ? 'Chưa có bản lưu'
    : ($libMetrics['snapshots_ok'] === $libMetrics['snapshots']
        ? 'Bản lưu thành công 100%'
        : $libMetrics['snapshots_ok'] . '/' . $libMetrics['snapshots'] . ' bản lưu thành công');
$library['image_integrity'] = $libMetrics['image_total'] === 0
    ? 'Chưa có ảnh cục bộ'
    : ($libMetrics['image_missing'] === 0
        ? 'Ảnh cục bộ nguyên vẹn 100%'
        : $libMetrics['image_missing'] . ' ảnh cục bộ không tìm thấy');

// Phân rã số thành viên theo vai trò — tính từ danh sách thành viên thật.
$roleLabels = wa_member_role_labels();
$memberBreakdown = ['owner' => 0, 'editor' => 0, 'viewer' => 0];

foreach ($members as $member) {
    $role = (string) $member['role'];

    if (!isset($memberBreakdown[$role])) {
        $memberBreakdown[$role] = 0;
    }

    $memberBreakdown[$role]++;
}

$memberBreakdownParts = [];

foreach ($memberBreakdown as $role => $count) {
    if ($count > 0) {
        $memberBreakdownParts[] = $count . ' ' . ($roleLabels[$role] ?? $role);
    }
}

$memberBreakdownText = $memberBreakdownParts === []
    ? 'Chưa có thành viên'
    : implode(' • ', $memberBreakdownParts);

// Phân trang: 6 bài mỗi trang.
$perPage = 6;
$totalPages = max(1, (int) ceil(count($articles) / $perPage));
$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$pagedArticles = array_slice($articles, ($page - 1) * $perPage, $perPage);

// Thùng rác: bài đã xoá mềm của thư viện này, chỉ người có quyền sửa mới thấy.
// Mỗi bài có nút Khôi phục (POST + CSRF), giữ nguyên giao diện thẻ hiện tại.
$trashedArticles = $canEditLib ? wa_library_trashed_articles($libraryId) : [];

$pageTitle = $library['name'];
require __DIR__ . '/../templates/header_app.php';
?>

<!-- ===================== A. BREADCRUMB ===================== -->
<div class="breadcrumb">
  <div class="breadcrumb__path">
    <a href="<?= wa_e(wa_url('libraries.php')) ?>">
      <span class="ms ms-14" style="color:var(--wa-action)">arrow_back</span>
      Thư viện của tôi
    </a>
    <span class="sep">/</span>
    <b><?= wa_e($library['name']) ?></b>
  </div>
  <div class="breadcrumb__meta">
    <span class="chip chip--plain">
      <span class="dot dot--teal"></span>
      Thư viện mở • <?= $library['article_count'] ?> bài viết
    </span>
    <span class="chip chip--plain">
      <span class="ms ms-14" style="color:var(--wa-action)">lock_open</span>
      <?= wa_e($library['visibility']) ?>
    </span>
  </div>
</div>

<!-- ===================== B. TIÊU ĐỀ & HÀNH ĐỘNG ===================== -->
<div class="pagehead">
  <div>
    <div class="pagehead__eyebrow">
      <span class="t-label-sm t-upper t-accent" style="font-weight:600">Bộ sưu tập chuyên đề</span>
      <span class="t-muted" style="font-weight:300">•</span>
      <span class="t-label-sm" style="color:#74777E">ID: LIB-<?= (int) $library['id'] ?></span>
    </div>
    <h1 class="pagehead__title"><?= wa_e($library['name']) ?></h1>
    <p class="pagehead__desc"><?= wa_e($library['description']) ?> Bảo tồn snapshot ngoại tuyến độc lập với máy chủ gốc.</p>
  </div>

  <div class="pagehead__actions">
    <!-- P-LIB: các nút quản lý chỉ hiện với chủ sở hữu / quản trị viên. Người
         khác không thấy; nếu gửi POST thẳng bằng tay vẫn bị chặn ở tầng SQL. -->
    <?php if ($canManage): ?>
      <button class="btn btn--outline" type="button" data-modal-open="modal-members">
        <span class="ms ms-18">group</span>
        Quản lý thành viên (<?= $library['member_count'] ?>)
      </button>
      <button class="btn btn--outline" type="button" data-modal-open="modal-settings">
        <span class="ms ms-18">settings</span>
        Cài đặt thư viện
      </button>
    <?php else: ?>
      <span class="chip chip--plain">
        <span class="ms ms-14">visibility</span>
        Bạn xem với vai trò <?= wa_e($library['role_label']) ?>
      </span>
    <?php endif; ?>
    <a class="btn btn--dark" href="#quick-capture">
      <span class="ms ms-18">add_link</span>
      Lưu trang Web mới
    </a>
  </div>
</div>

<?php if ($flash !== null): ?>
  <div class="state <?= (string) ($flash['type'] ?? '') === 'success' ? 'state--success' : 'state--error' ?>" role="status" style="margin-bottom:20px">
    <div class="state__body">
      <span class="state__icon">
        <span class="ms ms-18 ms-fill">
          <?= (string) ($flash['type'] ?? '') === 'success' ? 'check_circle' : 'error_outline' ?>
        </span>
      </span>
      <div>
        <span class="state__title"><?= (string) ($flash['type'] ?? '') === 'success' ? 'Thành công' : 'Không thực hiện được' ?></span>
        <p class="state__text"><?= wa_e((string) ($flash['text'] ?? '')) ?></p>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ===================== C. SỐ LIỆU ===================== -->
<div class="stat-cards" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Bài lưu trữ</span>
      <span class="ms ms-18" style="color:var(--wa-action)">menu_book</span>
    </div>
    <div class="stat-card__value"><b><?= $library['article_count'] ?></b><span>snapshot</span></div>
    <div class="stat-card__note" style="color:var(--wa-action)">
      <span class="dot dot--teal"></span> <?= $library['integrity'] ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Ảnh cục bộ</span>
      <span class="ms ms-18" style="color:var(--wa-action)">photo_library</span>
    </div>
    <div class="stat-card__value"><b><?= $library['image_count'] ?></b><span>tập tin</span></div>
    <div class="stat-card__note">Dung lượng ~<?= wa_e($library['asset_size']) ?></div>
  </div>

  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Nhãn nội dung</span>
      <span class="ms ms-18" style="color:var(--wa-action)">local_offer</span>
    </div>
    <div class="stat-card__value"><b><?= $library['tag_count'] ?></b><span>phân mục</span></div>
    <div class="stat-card__note" style="color:var(--wa-action)">Phổ biến: <?= wa_e($library['tags'][0]) ?> <?= wa_e($library['tags'][1] ?? '') ?></div>
  </div>

  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Thành viên</span>
      <span class="ms ms-18" style="color:var(--wa-action)">supervised_user_circle</span>
    </div>
    <div class="stat-card__value"><b><?= $library['member_count'] ?></b><span>tài khoản</span></div>
    <div class="stat-card__note"><?= wa_e($memberBreakdownText) ?></div>
  </div>

  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Toàn vẹn tệp</span>
      <span class="ms ms-18" style="color:var(--wa-teal-deep)">verified</span>
    </div>
    <div class="stat-card__value"><b><?= $library['broken_images'] ?></b><span>Ảnh lỗi</span></div>
    <div class="stat-card__note" style="color:var(--wa-teal-deep)"><?= wa_e($library['image_integrity']) ?></div>
  </div>
</div>

<!-- ===================== D. BẢNG LƯU URL (LUÔN THÀNH CÔNG) ===================== -->
<div class="capture" id="quick-capture">
  <div class="capture__head">
    <div class="capture__title">
      <span class="capture__title-icon"><span class="ms ms-22">archive</span></span>
      <div>
        <h2 class="t-headline-sm" style="color:var(--wa-primary)">Lưu bài viết vào thư viện này</h2>
        <p class="t-body-sm t-muted">Tự động trích xuất nội dung văn bản, chuyển đổi hình ảnh về lưu trữ máy chủ cục bộ.</p>
      </div>
    </div>
    <span class="chip chip--plain">
      <span class="ms ms-14" style="color:var(--wa-teal-deep)">bolt</span> cURL + DOMDocument
    </span>
  </div>

  <form class="capture__form" method="post" action="<?= wa_e(wa_url('save.php')) ?>">
    <?= wa_csrf_field() ?>
    <!-- Thư viện đích chính là thư viện hiện tại nên chỉ cần gửi id. -->
    <input type="hidden" name="library_id" value="<?= (int) $library['id'] ?>">
    <div class="capture__input">
      <span class="ms ms-18">link</span>
      <input class="input input--icon" type="url" name="url" required
             value=""
             placeholder="https://example.com/bai-viet-lap-trinh-web"
             aria-label="Địa chỉ trang Web cần lưu">
    </div>
    <button class="btn btn--dark" type="submit">
      <span class="ms ms-18">download_for_offline</span>
      <span>Lưu trang Web</span>
    </button>
  </form>

  <div class="capture__hint">
    <p>Dán liên kết bài viết thuộc website được phép lưu (HTML tĩnh công khai).</p>
    <button class="linkish" type="button" id="guidance-toggle" aria-expanded="false" aria-controls="guidance-details">
      <span class="ms ms-16">help_outline</span>
      Các URL được phép lưu là gì?
    </button>
  </div>

  <div class="guidance" id="guidance-details" hidden>
    <div class="row" style="align-items:flex-start;gap:12px">
      <span class="ms ms-18" style="color:var(--wa-action);flex:0 0 18px;margin-top:2px">info</span>
      <div>
        <strong>Quy chuẩn bóc tách snapshot độc lập:</strong>
        Hệ thống hỗ trợ hoàn hảo các trang tin tức, blog kỹ thuật, bài nghiên cứu công nghệ không yêu cầu
        bức tường phí (paywall) hoặc xác thực 2 lớp. Trình bóc tách sẽ tải mã HTML gốc, bóc tách thẻ chứa
        nội dung chính và ánh xạ toàn bộ thẻ <code>&lt;img&gt;</code> sang định dạng cục bộ.
      </div>
    </div>
  </div>

  <!-- Trạng thái THÀNH CÔNG (đúng như bản tham chiếu) -->
  <div class="state state--success">
    <div class="state__body">
      <span class="state__icon"><span class="ms ms-18 ms-fill">check_circle</span></span>
      <div>
        <div class="row" style="gap:8px">
          <span class="state__title">Bản đọc ngoại tuyến đã sẵn sàng</span>
          <span class="state__text"><?= (int) $library['image_count'] ?> ảnh đã tải về máy chủ nội bộ • Dung lượng lưu trữ: <?= wa_e($library['asset_size']) ?></span>
        </div>
        <p class="state__note">
          Mỗi lần lưu tạo một phiên bản snapshot mới; kết quả được ghi vào bảng
          <code>snapshots</code> và <code>assets</code> của cơ sở dữ liệu.
        </p>
      </div>
    </div>
    <div class="state__actions">
      <?php $latestSnapshotId = (int) ($articles[0]['snapshot_id'] ?? 0); ?>
      <?php if ($latestSnapshotId > 0): ?>
        <a class="btn btn--light" href="<?= wa_e(wa_url('reader.php?id=' . $latestSnapshotId)) ?>">
          <span class="ms ms-16" style="color:var(--wa-teal-deep)">chrome_reader_mode</span>
          Mở xem ngay
        </a>
      <?php else: ?>
        <a class="btn btn--light" href="<?= wa_e(wa_url('search.php?q=')) ?>">
          <span class="ms ms-16" style="color:var(--wa-teal-deep)">search</span>
          Xem kho đã lưu
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ===================== G. THÀNH VIÊN & NHÃN =====================
     Bảng thành viên PHẢN ÁNH NGAY CSDL sau mỗi hành động: nó được nạp lại từ
     wa_library_members() ở đầu trang, còn mọi POST đều chuyển hướng (PRG) nên
     không bao giờ hiển thị trạng thái cũ. -->
<div class="two-col" style="margin-bottom:24px">
  <div class="two-col__main panel">
    <div class="panel__head">
      <span class="panel__label">
        <span class="ms ms-16">badge</span>
        Thành viên &amp; Phân quyền thư viện (<?= $library['member_count'] ?>)
      </span>
      <?php if ($canManage): ?>
        <button class="t-label-sm t-accent" type="button" data-modal-open="modal-members"
                style="font-weight:500;background:none;border:0;cursor:pointer">+ Mời thêm</button>
      <?php endif; ?>
    </div>

    <div class="table-wrap" style="box-shadow:none;border-radius:var(--wa-radius-lg)">
      <table class="table" style="min-width:520px">
        <thead>
          <tr>
            <th>Tài khoản</th>
            <th>Email</th>
            <th>Vai trò</th>
            <?php if ($canManage): ?><th style="text-align:right">Thao tác</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $member): ?>
            <tr>
              <td>
                <span class="row" style="gap:8px;flex-wrap:nowrap">
                  <span class="avatar avatar--<?= wa_e($member['variant']) ?>" style="width:30px;height:30px;flex:0 0 30px">
                    <?= wa_e($member['initials']) ?>
                  </span>
                  <span>
                    <span class="cell-main" style="display:block"><?= wa_e($member['name']) ?></span>
                    <?php if ((string) $member['role'] === 'owner'): ?>
                      <span class="cell-sub">Chủ sở hữu thư viện</span>
                    <?php endif; ?>
                  </span>
                </span>
              </td>
              <td class="t-muted"><?= wa_e($member['email']) ?></td>
              <td>
                <?php if ($canManage && (string) $member['role'] !== 'owner'): ?>
                  <!-- Đổi vai trò: chỉ chuyển qua lại giữa biên tập viên / người xem.
                       Dòng chủ sở hữu KHÔNG có nút này (và SQL cũng chặn). -->
                  <form method="post" action="<?= wa_e(wa_url('library_action.php')) ?>" style="display:inline">
                    <?= wa_csrf_field() ?>
                    <input type="hidden" name="action" value="member_role">
                    <input type="hidden" name="library_id" value="<?= $libraryId ?>">
                    <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                    <input type="hidden" name="role"
                           value="<?= (string) $member['role'] === 'editor' ? 'viewer' : 'editor' ?>">
                    <button class="btn btn--outline btn--sm" type="submit"
                            title="Đổi vai trò của thành viên này">
                      <span class="member-pill__role member-pill__role--<?= wa_e($member['role']) ?>">
                        <?= wa_e($member['role_label']) ?>
                      </span>
                    </button>
                  </form>
                <?php else: ?>
                  <span class="member-pill__role member-pill__role--<?= wa_e($member['role']) ?>">
                    <?= wa_e($member['role_label']) ?>
                  </span>
                <?php endif; ?>
              </td>

              <?php if ($canManage): ?>
                <td style="text-align:right">
                  <?php if ((string) $member['role'] === 'owner'): ?>
                    <!-- Dòng chủ sở hữu: KHÔNG có nút gỡ — và SQL chặn `role <> 'owner'`
                         nên gửi thẳng POST cũng không xoá được. -->
                    <span class="t-label-sm t-muted">Không thể gỡ</span>
                  <?php else: ?>
                    <form method="post" action="<?= wa_e(wa_url('library_action.php')) ?>" style="display:inline"
                          onsubmit="return confirm('Gỡ <<?= wa_e($member['name']) ?> khỏi thư viện?');">
                      <?= wa_csrf_field() ?>
                      <input type="hidden" name="action" value="member_remove">
                      <input type="hidden" name="library_id" value="<?= $libraryId ?>">
                      <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                      <button class="btn btn--danger-ghost btn--sm" type="submit" title="Gỡ khỏi thư viện">
                        <span class="ms ms-14">person_remove</span> Gỡ
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!$canManage): ?>
      <p class="t-label-sm t-muted" style="margin-top:10px">
        Bạn đang xem với vai trò <strong><?= wa_e($library['role_label']) ?></strong>.
        Chỉ chủ sở hữu thư viện mới thêm, đổi vai trò hoặc gỡ thành viên.
      </p>
    <?php endif; ?>
  </div>

  <div class="two-col__side panel">
    <div class="panel__head">
      <span class="panel__label">
        <span class="ms ms-16">sell</span>
        Nhãn phân loại (<?= $library['tag_count'] ?>)
      </span>
      <span class="t-label-sm t-muted">Lọc nhanh</span>
    </div>
    <div class="row" style="gap:6px">
      <?php foreach ($library['tags'] as $tagIndex => $tag): ?>
        <a class="tag <?= $tagIndex === 0 ? 'tag--active' : 'tag--btn' ?>"
           href="<?= wa_e(wa_url('search.php?tag=' . urlencode($tag))) ?>"
           title="Mở Tìm kiếm lọc theo nhãn <?= wa_e($tag) ?>"><?= wa_e($tag) ?></a>
      <?php endforeach; ?>
    </div>
    <p class="t-label-sm t-muted" style="margin-top:12px">
      Bấm vào một nhãn để mở <a href="<?= wa_e(wa_url('search.php')) ?>">Tìm kiếm</a> đã lọc theo nhãn đó.
      Sửa nhãn của từng bài viết tại ô <b>Nhãn</b> trên mỗi thẻ bài viết bên dưới.
    </p>
  </div>
</div>

<!-- ===================== E. TAB + LỌC ===================== -->
<div class="toolbar">
  <div class="tabs" role="tablist" aria-label="Điều hướng trong thư viện">
    <button type="button" class="is-active"><span class="ms ms-16">article</span> Tất cả bài viết (<?= $library['article_count'] ?>)</button>
    <button type="button"><span class="ms ms-16">label</span> Nhãn phân loại (<?= $library['tag_count'] ?>)</button>
    <button type="button"><span class="ms ms-16">manage_accounts</span> Thành viên &amp; phân quyền (<?= $library['member_count'] ?>)</button>
  </div>

  <div class="toolbar__right">
    <div class="searchbox">
      <span class="ms">search</span>
      <input class="input input--flush input--sm" type="search" placeholder="Lọc trong thư viện này..." aria-label="Lọc trong thư viện này">
    </div>
    <div class="view-toggle" role="group" aria-label="Kiểu hiển thị">
      <button type="button" data-view="grid" class="is-active" aria-label="Hiển thị dạng lưới"><span class="ms ms-16">grid_view</span></button>
      <button type="button" data-view="list" aria-label="Hiển thị dạng danh sách"><span class="ms ms-16">format_list_bulleted</span></button>
    </div>
    <!-- FIX sort-thua: nút Sắp xếp thừa đã gỡ (chưa từng gắn chức năng),
         chỉ còn ô lọc + nút chuyển lưới/danh sách. -->
  </div>
</div>

<!-- ===================== F. THẺ BÀI VIẾT ===================== -->
<div class="article-grid">
  <?php foreach ($pagedArticles as $article): ?>
    <div class="article-card">
      <div>
        <div class="article-card__thumb">
          <div class="article-card__thumb-top">
            <span class="chip"><?= wa_e($article['thumb_label']) ?></span>
            <?php if ($article['image_count'] > 0): ?>
              <span class="chip chip--teal"><span class="dot dot--teal"></span><?= $article['image_count'] ?> ảnh cục bộ</span>
            <?php else: ?>
              <span class="chip chip--plain"><span class="ms ms-14">storage</span><?= wa_e($article['size_label']) ?></span>
            <?php endif; ?>
          </div>
          <div class="article-card__thumb-code"><?= wa_e($article['thumb_code']) ?></div>
        </div>

        <div class="article-card__body">
          <div class="article-card__meta">
            <span class="article-card__domain">
              <span class="ms ms-14">public</span><?= wa_e($article['domain']) ?>
            </span>
            <span>Lưu ngày: <?= wa_e($article['saved_at']) ?></span>
          </div>

          <h3 class="t-clamp-2"><?= wa_e($article['title']) ?></h3>
          <p class="article-card__excerpt t-clamp-2"><?= wa_e($article['excerpt']) ?></p>

          <!-- ===== P-LIB / Deliverable 3: SỬA NHÃN TẠI CHỖ =====
               Người có quyền sửa (chủ sở hữu / biên tập viên / quản trị viên) thấy
               hàng chip nhãn; bấm vào hàng chip để mở ô nhập (bấm Hủy hoặc Esc
               để đóng). Người xem chỉ xem nhãn ở dạng liên kết tới Tìm kiếm
               đã lọc. Cả hai đều lấy dữ liệu từ CSDL. -->
          <div class="article-card__tags">
            <?php if ($article['can_edit']): ?>
              <form method="post" action="<?= wa_e(wa_url('library_action.php')) ?>" class="article-tags-form">
                <?= wa_csrf_field() ?>
                <input type="hidden" name="action" value="tags">
                <input type="hidden" name="library_id" value="<?= $libraryId ?>">
                <input type="hidden" name="page_id" value="<?= (int) $article['id'] ?>">
                <!-- Click-to-edit: bình thường chỉ hiện MỘT hàng chip như khi xem;
                     bấm vào hàng chip để mở ô nhập, bấm Hủy hoặc Esc để đóng. -->
                <button type="button" class="article-tags-form__chips article-tags-display"
                        title="Bấm để sửa nhãn">
                  <?php if ($article['tag_count'] > 0): ?>
                    <?php foreach ($article['tags_display'] as $tag): ?>
                      <span class="tag tag--btn"><?= wa_e($tag) ?></span>
                    <?php endforeach; ?>
                    <span class="ms ms-14 t-muted">edit</span>
                  <?php else: ?>
                    <span class="ms ms-14 t-muted">add</span>
                    <span class="t-label-sm t-muted">Thêm nhãn</span>
                  <?php endif; ?>
                </button>
                <div class="article-tags-editor">
                  <label class="article-tags-form__label" for="tags-<?= (int) $article['id'] ?>">
                    <span class="ms ms-14">sell</span> Nhãn
                    <span class="t-muted" style="font-weight:400">— cách nhau bằng dấu phẩy, dấu # tự thêm</span>
                  </label>
                  <div class="article-tags-form__row">
                    <input class="input input--sm" id="tags-<?= (int) $article['id'] ?>" type="text" name="tags"
                           value="<?= wa_e($article['tag_input']) ?>" maxlength="500"
                           placeholder="nhãn-1, nhãn-2, ..."
                           style="flex:1;min-width:0">
                    <button class="btn btn--primary btn--sm" type="submit">
                      <span class="ms ms-14">save</span> Lưu nhãn
                    </button>
                    <button class="btn btn--outline btn--sm article-tags-cancel" type="button">
                      <span class="ms ms-14">close</span> Hủy
                    </button>
                  </div>
                </div>
              </form>
            <?php else: ?>
              <?php foreach ($article['tags_display'] as $tag): ?>
                <a class="tag tag--btn"
                   href="<?= wa_e(wa_url('search.php?tag=' . urlencode($tag))) ?>"><?= wa_e($tag) ?></a>
              <?php endforeach; ?>
              <?php if ($article['tag_count'] === 0): ?>
                <span class="t-label-sm t-muted">chưa có nhãn</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <span class="article-card__status">
            <span class="ms ms-16">offline_pin</span>
            Bản đọc ngoại tuyến sẵn sàng
            <!-- P-READ: số phiên bản thành công của trang này -->
            <span class="chip chip--teal" style="margin-left:auto">
              <span class="ms ms-14">history</span>
              <?= (int) $article['version_count'] ?> phiên bản
            </span>
          </span>
        </div>
      </div>

      <div class="article-card__foot">
        <a class="btn btn--dark" href="<?= wa_e(wa_url('reader.php?id=' . (int) ($article['snapshot_id'] ?? 0))) ?>">
          <span class="ms ms-18">chrome_reader_mode</span>
          Đọc bản đã lưu
        </a>
        <button class="btn btn--outline" type="button" title="Chi tiết bản lưu" aria-label="Chi tiết bản lưu">
          <span class="ms ms-18">info</span>
        </button>
        <?php /* XOÁ MỀM bài viết — POST + CSRF sang library_action.php, có hộp
               thoại xác nhận. Chỉ hiện với người có quyền sửa (chủ sở hữu /
               biên tập viên / quản trị viên); người xem không thấy nút, và gửi
               POST thẳng bằng tay cũng bị từ chối ở backend. */ ?>
        <?php if ($article['can_edit']): ?>
          <form method="post" action="<?= wa_e(wa_url('library_action.php')) ?>" style="display:inline"
                onsubmit="return confirm('Bạn có chắc muốn xóa bài viết này khỏi thư viện?');">
            <?= wa_csrf_field() ?>
            <input type="hidden" name="action" value="delete_page">
            <input type="hidden" name="library_id" value="<?= $libraryId ?>">
            <input type="hidden" name="page_id" value="<?= (int) $article['id'] ?>">
            <button class="btn btn--danger-ghost btn--sm" type="submit"
                    title="Xóa bài viết này khỏi thư viện" aria-label="Xóa bài viết">
              <span class="ms ms-16">delete</span> Xóa
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- ===================== F2. THÙNG RÁC (KHÔI PHỤC) =====================
     Bài đã xoá mềm không hiện trong lưới ở trên. Mục gọn này chỉ hiện với
     người có quyền sửa, mỗi bài có nút Khôi phục (POST + CSRF). -->
<?php if ($canEditLib && $trashedArticles !== []): ?>
  <div class="panel" style="margin-top:24px">
    <div class="panel__head">
      <span class="panel__label">
        <span class="ms ms-16">delete</span>
        Thùng rác (<?= count($trashedArticles) ?>)
      </span>
      <span class="t-label-sm t-muted">Bài đã xoá — khôi phục giữ nguyên phiên bản và ghi chú</span>
    </div>

    <div class="state-list" style="margin-top:4px">
      <?php foreach ($trashedArticles as $trashed): ?>
        <div class="state-item">
          <div class="state-item__head">
            <span class="state-item__name">
              <span class="ms ms-18">article</span>
              <span style="font-weight:500"><?= wa_e($trashed['title']) ?></span>
            </span>
            <span class="chip chip--plain"><?= (int) $trashed['version_count'] ?> phiên bản</span>
          </div>
          <p class="t-label-sm t-muted" style="margin-top:6px">
            <?= wa_e($trashed['domain']) ?> · xoá lúc <?= wa_e($trashed['deleted_label']) ?>
          </p>
          <div class="row" style="gap:8px;margin-top:8px">
            <form method="post" action="<?= wa_e(wa_url('library_action.php')) ?>" style="display:inline">
              <?= wa_csrf_field() ?>
              <input type="hidden" name="action" value="restore_page">
              <input type="hidden" name="library_id" value="<?= $libraryId ?>">
              <input type="hidden" name="page_id" value="<?= (int) $trashed['id'] ?>">
              <button class="btn btn--outline btn--sm" type="submit" title="Khôi phục bài viết này">
                <span class="ms ms-16">restore_from_trash</span> Khôi phục
              </button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<!-- ===================== PHÂN TRANG ===================== -->
<div class="pagination">
  <button type="button" disabled aria-label="Trang trước"><span class="ms ms-16">chevron_left</span></button>
  <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <button type="button" class="<?= $i === $page ? 'is-active' : '' ?>"
            onclick="window.location.href='<?= wa_e(wa_url('library.php?slug=' . urlencode($slug) . '&page=' . $i)) ?>'"><?= $i ?></button>
  <?php endfor; ?>
  <button type="button" <?= $page >= $totalPages ? 'disabled' : '' ?> aria-label="Trang sau"
          onclick="window.location.href='<?= wa_e(wa_url('library.php?slug=' . urlencode($slug) . '&page=' . ($page + 1))) ?>'">
    <span class="ms ms-16">chevron_right</span>
  </button>
  <span class="pagination__info">
    Trang <?= $page ?>/<?= $totalPages ?> • Hiển thị <?= count($pagedArticles) ?> trong tổng <?= $library['article_count'] ?> bài viết
  </span>
</div>

<!-- ===================== LƯU THẤT BẠI GẦN ĐÂY (P-READ) =====================
     Các trang chưa từng có bản thành công KHÔNG nằm trong lưới bài viết ở trên
     (nguyên tắc: trang lỗi không bao giờ thành bài đọc được). Chúng hiện ở mục
     riêng này, tối đa 5 lần mới nhất, chỉ với người có quyền biên tập thư viện. -->
<?php if ($canEditLib && $failedAttempts !== []): ?>
  <div class="two-col__side panel" style="margin-top:24px">
    <div class="panel__head">
      <span class="panel__label">
        <span class="ms ms-16">error_outline</span>
        Lưu thất bại gần đây (<?= count($failedAttempts) ?>)
      </span>
      <span class="t-label-sm t-muted">5 lần gần nhất</span>
    </div>

    <div class="state-list" style="margin-top:4px" id="wa-failed-list">
      <?php foreach ($failedAttempts as $failed): ?>
        <div class="state-item wa-failed__row">
          <div class="state-item__head">
            <span class="state-item__name">
              <span class="ms ms-18">link</span>
              <span class="t-mono wa-failed__url" style="font-weight:500"><?= wa_e($failed['source_url']) ?></span>
            </span>
            <span class="chip chip--error"><?= wa_e($failed['status_label']) ?></span>
          </div>
          <p class="t-body-sm t-muted" style="margin-top:8px">
            <?= wa_e($failed['error_message']) ?>
          </p>
          <p class="t-label-sm t-muted" style="margin-top:6px">
            <?php if ((int) $failed['version_no'] > 0): ?>
              Lần thử thứ <?= (int) $failed['version_no'] ?> ·
            <?php else: ?>
              Lần thử lưu lỗi ·
            <?php endif; ?>
            mã lỗi
            <code><?= wa_e($failed['fetch_status']) ?></code>
            · HTTP <?= (int) $failed['http_status'] ?>
            · <?= wa_e($failed['fetched_label']) ?>
            <?php if (!empty($failed['has_success'])): ?>
              · <b>bản đọc trước vẫn còn nguyên</b>
            <?php endif; ?>
          </p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<!-- ===================== MODAL: CÀI ĐẶT THƯ VIỆN (Deliverable 1) =====================
     Đổi tên / đổi mức hiển thị / xoá thư viện. Cả ba đều là action="update" hoặc
     action="delete" của library_action.php và đều có ô CSRF. -->
<?php if ($canManage): ?>
  <div class="modal-backdrop" id="modal-settings" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-settings-title">
      <div class="modal__head">
        <h2 class="t-headline-sm" style="font-weight:700" id="modal-settings-title">
          <span class="ms ms-18" style="color:var(--wa-primary)">settings</span> Cài đặt thư viện
        </h2>
        <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
          <span class="ms ms-18">close</span>
        </button>
      </div>

      <div class="stack">
        <!-- ---- 1a. ĐỔI TÊN (và mô tả) ---- -->
        <form method="post" action="<?= wa_e(wa_url('library_action.php')) ?>">
          <?= wa_csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="library_id" value="<?= $libraryId ?>">

          <div class="field">
            <label for="settings-name">Tên thư viện <span class="t-error">*</span></label>
            <input class="input" id="settings-name" type="text" name="name" required maxlength="150"
                   value="<?= wa_e($library['name']) ?>">
            <p class="t-label-sm t-muted" style="margin-top:6px">
              Đường dẫn hiện tại: <code><?= wa_e(wa_url('library.php?slug=' . $library['slug'])) ?></code>.<br>
              Đổi tên sẽ sinh slug mới từ tên đã bỏ dấu (bỏ dấu tiếng Việt);
              <b>giữ nguyên slug cũ nếu tên không đổi</b> để không phá liên kết đã chia sẻ.
              Nếu slug mới đã có, hệ thống tự thêm hậu tố <code>-2</code>, <code>-3</code>…
            </p>
          </div>

          <div class="field">
            <label for="settings-desc">Mô tả</label>
            <textarea class="input" id="settings-desc" name="description" rows="3"
                      maxlength="500"><?= wa_e($library['description']) ?></textarea>
          </div>

          <div class="field">
            <span style="font-size:12px;line-height:16px;font-weight:600;color:var(--wa-text)">Mức hiển thị</span>
            <div class="row" style="gap:8px;flex-wrap:wrap">
              <?php foreach (wa_library_visibility_options() as $visOption): ?>
                <label class="row" style="gap:6px;flex-wrap:nowrap;cursor:pointer">
                  <input type="radio" name="visibility" value="<?= wa_e($visOption['key']) ?>"
                         <?= (string) $library['visibility'] === (string) $visOption['key'] ? 'checked' : '' ?>>
                  <span style="font-size:13px"><?= wa_e($visOption['label']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="t-label-sm t-muted" style="margin-top:6px">
              <b>Riêng tư</b>: chỉ thành viên. <b>Dùng chung</b>: thành viên + mời được.
              <b>Công khai</b>: ai có đường dẫn cũng xem được.
            </p>
          </div>

          <div class="row row--between">
            <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
            <button class="btn btn--primary" type="submit">
              <span class="ms ms-18">save</span> Lưu cài đặt
            </button>
          </div>
        </form>

        <!-- ---- 1b. XOÁ THƯ VIỆN (chủ sở hữu; hai bước xác nhận) ---- -->
        <div class="divider"></div>

        <?php if ($isOwnerHere): ?>
          <form class="stack stack--sm" method="post" action="<?= wa_e(wa_url('library_action.php')) ?>"
                onsubmit="return wa_confirm_delete_library(this);">
            <?= wa_csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="library_id" value="<?= $libraryId ?>">

            <div class="note-box">
              <b>Xoá thư viện</b><br>
              Xoá vĩnh viễn thư viện <b><?= wa_e($library['name']) ?></b> cùng
              <?= (int) $library['article_count'] ?> bài đã lưu, toàn bộ nhãn và
              <b>các thư mục ảnh trên đĩa</b> trong <code>public/uploads/snapshots/<?= $libraryId ?>/</code>.
              <b>Không thể hoàn tác.</b>
            </div>

            <div class="field">
              <label for="settings-confirm">Bước 2/2 — gõ <code>XOA</code> để xác nhận</label>
              <input class="input" id="settings-confirm" type="text" name="confirm" required
                     autocomplete="off" placeholder="XOA" style="max-width:200px">
            </div>

            <div class="row row--between">
              <button class="btn btn--outline" type="button" data-modal-close>Giữ lại</button>
              <button class="btn btn--danger-ghost" type="submit">
                <span class="ms ms-18">delete_forever</span> Xoá thư viện
              </button>
            </div>
          </form>
        <?php else: ?>
          <p class="t-body-sm t-muted">
            Chỉ <b>chủ sở hữu</b> thư viện mới xoá được thư viện này
            (quản trị viên chỉ đổi được tên và mức hiển thị).
          </p>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ===================== MODAL: THÊM THÀNH VIÊN (Deliverable 2) ===================== -->
<?php if ($canManage): ?>
  <div class="modal-backdrop" id="modal-members" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-members-title">
      <div class="modal__head">
        <h2 class="t-headline-sm" style="font-weight:700" id="modal-members-title">
          <span class="ms ms-18" style="color:var(--wa-primary)">person_add</span>
          Thêm thành viên vào thư viện
        </h2>
        <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
          <span class="ms ms-18">close</span>
</button>
      </div>

      <div class="stack">
        <form class="stack" method="post" action="<?= wa_e(wa_url('library_action.php')) ?>">
          <?= wa_csrf_field() ?>
          <input type="hidden" name="action" value="member_add">
          <input type="hidden" name="library_id" value="<?= $libraryId ?>">

          <div class="field">
            <label for="member-email">Email của tài khoản đã có trong hệ thống</label>
            <input class="input" id="member-email" type="email" name="email" required
                   maxlength="180" placeholder="nguoidung@example.com"
                   autocomplete="off">
            <p class="t-label-sm t-muted" style="margin-top:6px">
              Chỉ thêm được tài khoản <b>đã đăng ký</b> (tồn tại trong bảng <code>users</code>).
              Email không tồn tại hoặc đã là thành viên sẽ bị từ chối — không tạo tài khoản mới.
            </p>
          </div>

          <div class="field">
            <span style="font-size:12px;line-height:16px;font-weight:600;color:var(--wa-text)">Vai trò</span>
            <div class="row" style="gap:8px;flex-wrap:wrap">
              <label class="row" style="gap:6px;flex-wrap:nowrap;cursor:pointer">
                <input type="radio" name="role" value="editor" checked>
                <span style="font-size:13px">Biên tập viên — sửa nhãn, xem danh sách lưu thất bại</span>
              </label>
              <label class="row" style="gap:6px;flex-wrap:nowrap;cursor:pointer">
                <input type="radio" name="role" value="viewer">
                <span style="font-size:13px">Người xem — chỉ đọc, không sửa</span>
              </label>
            </div>
          </div>

          <div class="row row--between">
            <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
            <button class="btn btn--primary" type="submit">
              <span class="ms ms-18">group_add</span> Thêm thành viên
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
  var guidanceToggle = document.getElementById('guidance-toggle');
  var guidance = document.getElementById('guidance-details');
  if (guidanceToggle && guidance) {
    guidanceToggle.addEventListener('click', function () {
      var hidden = guidance.hasAttribute('hidden');
      if (hidden) { guidance.removeAttribute('hidden'); } else { guidance.setAttribute('hidden', ''); }
      guidanceToggle.setAttribute('aria-expanded', hidden ? 'true' : 'false');
    });
  }

  /* Hộp thoại chung: nút [data-modal-open="<id>"] mở #<id>, [data-modal-close] đóng,
     bấm ra ngoài (vùng nền tối) cũng đóng. Dùng chung cho modal tạo thư viện ở
     libraries.php và hai modal quản lý ở đây. */
  document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = document.getElementById(btn.getAttribute('data-modal-open'));
      if (target) { target.removeAttribute('hidden'); }
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var box = btn.closest('.modal-backdrop');
      if (box) { box.setAttribute('hidden', ''); }
    });
  });
  document.querySelectorAll('.modal-backdrop').forEach(function (box) {
    box.addEventListener('click', function (e) {
      if (e.target === box) { box.setAttribute('hidden', ''); }
    });
  });

  /* Xác nhận hai bước khi xoá thư viện:
     bước 1 — hộp thoại "chắc chứ?";
     bước 2 — người dùng phải gõ đúng "XOA" vào ô xác nhận (bắt buộc trong HTML,
                kiểm lại ở đây để không gửi nhầm khi gõ sai).
     Huỷ ở bất kỳ bước nào thì form KHÔNG được gửi đi. */
  function wa_confirm_delete_library(form) {
    var box = form.querySelector('input[name="confirm"]');

    if ((box.value || '').trim().toUpperCase() !== 'XOA') {
      window.alert('Bạn phải gõ đúng XOA để xác nhận xoá thư viện.');
      box.focus();
      return false;
    }

    return window.confirm(
      'Xoá thư viện "' + <?= json_encode((string) $library['name'], JSON_UNESCAPED_UNICODE) ?> + '"?\n' +
      'Mọi bài đã lưu, nhãn và thư mục ảnh trên đĩa sẽ bị xoá vĩnh viễn.'
    );
  }
</script>

<script>
  /* Nhãn click-to-edit (P-LIB): bình thường chỉ hiện hàng chip; bấm vào
     hàng chip để mở ô nhập, bấm Hủy hoặc Esc để đóng lại (Hủy trả ô nhập
     về đúng nội dung ban đầu). Không có JS thì ô nhập vẫn hiện như cũ nên
     vẫn sửa được nhãn. */
  (function () {
    document.querySelectorAll('.article-tags-form').forEach(function (form) {
      var display = form.querySelector('.article-tags-display');
      var editor = form.querySelector('.article-tags-editor');
      var cancel = form.querySelector('.article-tags-cancel');
      var input = form.querySelector('input[name="tags"]');

      if (!display || !editor || !input) {
        return;
      }

      var original = input.value;
      editor.hidden = true;

      display.addEventListener('click', function () {
        display.hidden = true;
        editor.hidden = false;
        input.focus();
        try {
          input.setSelectionRange(input.value.length, input.value.length);
        } catch (e) {
          /* Bỏ qua: ô nhập vẫn dùng được bình thường. */
        }
      });

      function closeEditor() {
        input.value = original;
        editor.hidden = true;
        display.hidden = false;
        display.focus();
      }

      if (cancel) {
        cancel.addEventListener('click', closeEditor);
      }

      input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          closeEditor();
        }
      });
    });
  })();
</script>

<script>
  // Chuyển đổi kiểu hiển thị lưới / danh sách cho thẻ bài viết, nhớ lựa chọn đã chọn.
  (function () {
    var grid = document.querySelector('.article-grid');
    var buttons = document.querySelectorAll('.view-toggle button[data-view]');
    if (!grid || buttons.length === 0) { return; }
    function apply(view) {
      var list = view === 'list';
      grid.classList.toggle('article-grid--list', list);
      buttons.forEach(function (btn) {
        btn.classList.toggle('is-active', btn.getAttribute('data-view') === view);
      });
      try { localStorage.setItem('wa-article-view', view); } catch (e) {}
    }
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        apply(btn.getAttribute('data-view'));
      });
    });
    var saved = null;
    try { saved = localStorage.getItem('wa-article-view'); } catch (e) {}
    if (saved === 'list' || saved === 'grid') { apply(saved); }
  })();
</script>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>
