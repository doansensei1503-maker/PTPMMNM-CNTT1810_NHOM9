<?php
/**
 * save.php — Trang "Lưu trang Web" (P-READ: dùng đường lưu dùng chung).
 *
 * Biểu mẫu gồm: chọn thư viện (chỉ thư viện mà người dùng là chủ sở hữu hoặc
 * biên tập viên), ô URL, và ô nhãn tuỳ chọn (phân tách bằng dấu phẩy).
 *
 * Toàn bộ phần GHI CSDL (tìm/tạo saved_pages, tạo snapshot, tải và nối lại ảnh
 * cục bộ, ghi assets, cập nhật trang, gắn nhãn, ghi bản ghi lỗi) đã được gom về
 * HÀM DUY NHẤT wa_store_page_capture() trong src/queries.php — cùng hàm mà nút
 * "Lấy phiên bản mới" của public/refetch.php gọi. Hành vi của trang giữ nguyên.
 *
 * Thành công: chuyển hướng tới reader.php?id=<snapshot id> kèm thông báo
 * tiếng Việt có số phiên bản.
 *
 * Thất bại: vẫn ghi lại lần thử (dòng snapshot với fetch_status là mã lỗi và
 * error_message đầy đủ) để thống kê quản trị đếm được lỗi, đồng thời hiện
 * thông báo tiếng Việt RIÊNG cho từng mã lỗi. Snapshot lỗi không bao giờ hiện
 * trong danh sách bài viết và không mở được trong trình đọc.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$user     = current_user();
$userId   = (int) ($user['id'] ?? 0);
$libraries = wa_writable_libraries();

/** Thông báo trả về sau khi xử lý: @var array{type:string, text:string}|null */
$notice = null;
/** Dữ liệu form để hiển thị lại sau khi POST. */
$form = [
    'url'      => '',
    'library'  => '',
    'tags'     => '',
];

/* ---------------------------------------------------------------------------
 * XỬ LÝ POST — lưu trang thật
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['url']     = trim((string) ($_POST['url'] ?? ''));
    $form['library'] = trim((string) ($_POST['library_id'] ?? ''));
    $form['tags']    = trim((string) ($_POST['tags'] ?? ''));

    // P-FIX-ADMIN: hành động "gửi yêu cầu duyệt tên miền" — độc lập với việc
    // lưu trang. Chỉ cần CSRF hợp lệ là được.
    if ((string) ($_POST['action'] ?? '') === 'request_domain') {
        if (!wa_csrf_check()) {
            $notice = ['type' => 'error', 'text' => 'Phiên làm việc đã thay đổi. Vui lòng tải lại trang và thử lại.'];
        } else {
            $domain = trim((string) ($_POST['domain'] ?? ''));

            $requestResult = wa_request_domain_approval(
                $domain,
                'Yêu cầu từ người dùng khi lưu trang: ' . ($form['url'] !== '' ? $form['url'] : $domain),
                $userId
            );

            $notice = [
                'type'  => $requestResult['ok'] ? 'success' : 'error',
                'title' => $requestResult['ok'] ? 'Đã gửi yêu cầu duyệt' : 'Không gửi được yêu cầu',
                'text'  => (string) $requestResult['message'],
            ];

            if ($requestResult['ok'] === true) {
                wa_admin_log($userId, 'domain.request', 'domain', $domain);
            }
        }
    } else {
        $libraryId = (int) $form['library'];
        $tagNames  = wa_parse_tag_input($form['tags']);

    // --- Kiểm tra quyền ghi vào thư viện (chủ sở hữu / biên tập viên) ---
    if ($libraryId <= 0) {
        $notice = ['type' => 'error', 'text' => 'Vui lòng chọn thư viện để lưu trang.'];
    } elseif (!wa_can_write_library($libraryId)) {
        $notice = ['type' => 'error', 'text' => 'Bạn không có quyền lưu trang vào thư viện này.'];
    } elseif (!wa_csrf_check()) {
        $notice = ['type' => 'error', 'text' => 'Phiên làm việc đã thay đổi. Vui lòng tải lại trang và thử lại.'];
    } else {
        /* -----------------------------------------------------------------------
         * P-FIX-CN1/CN5 — PHÁT HIỆN BÀI ĐÃ TỒN TẠI TRONG THƯ VIỆN.
         *
         * Trước khi tải trang (tốn thời gian và mạng), ta tra cứu xem URL này đã
         * có trong thư viện chưa. Nếu có, ta KHÔNG tạo bài trùng — mà báo cho
         * người dùng biết và đưa họ tới đúng bản đang tồn tại, kèm tuỳ chọn
         * "tạo phiên bản mới" nếu họ thực sự muốn cập nhật.
         * --------------------------------------------------------------------- */
        $existing = null;

        if ($form['url'] !== '') {
            $existing = wa_find_page_by_url($libraryId, $form['url']);
        }

        // `force=1` nghĩa là người dùng đã bấm "Tạo phiên bản mới" ở cảnh báo
        // trùng lặp -> ta đi thẳng vào đường lưu.
        $force = (string) ($_POST['force'] ?? '') === '1';

        if ($existing !== null && !$force) {
            $existingSnapshot = wa_latest_successful_snapshot((int) $existing['id']);

            $notice = [
                'type'  => 'warning',
                'title' => 'Bài này đã có trong thư viện',
                'text'  => 'URL “' . $form['url'] . '” đã được lưu trong thư viện này. '
                         . 'Bấm “Tạo phiên bản mới” để tải lại nội dung và lưu thành '
                         . 'phiên bản tiếp theo, hoặc mở bản đang có để đọc.',
                'existing_page_id' => (int) $existing['id'],
                'existing_snapshot_id' => $existingSnapshot !== null ? (int) $existingSnapshot['id'] : 0,
                'existing_version'    => $existingSnapshot !== null ? (int) $existingSnapshot['version_no'] : 0,
                'duplicate' => true,
            ];
        } else {
            /* -------------------------------------------------------------------
             * ĐƯỜNG LƯU DÙNG CHUNG — cùng hàm với public/refetch.php.
             * Hàm tự tìm/tạo trang, tự tính version_no an toàn, tự tải ảnh cục
             * bộ, tự ghi assets, và tự ghi bản ghi lỗi khi thất bại.
             *
             * P-FIX-CN5: khi người dùng bấm "Tạo phiên bản mới" cho một URL
             * ĐÃ CÓ trong thư viện, ta truyền kèm page_id của trang đó. Nhờ vậy
             * bản mới CHẮC CHẮN gắn với CÙNG page_id (chuỗi v1 -> v2 -> v3 liên
             * tục), kể cả khi URL nguồn đã chuyển hướng sang địa chỉ khác.
             * ----------------------------------------------------------------- */
            $expectedPageId = $force && $existing !== null ? (int) $existing['id'] : 0;

            $result = wa_store_page_capture(
                $libraryId,
                $form['url'],
                $tagNames,
                $userId,
                $expectedPageId
            );

            if ($result['ok'] === true) {
                /* ---------------- THÀNH CÔNG ---------------- */
                $imageCount = (int) $result['image_count'];

                // Nội dung KHÔNG thay đổi (P-FIX-CN5) -> báo rõ, không tạo bản mới.
                if (($result['unchanged'] ?? false) === true) {
                    $_SESSION['wa_flash'] = [
                        'type'        => 'success',
                        'text'        => 'Nội dung chưa thay đổi — “'
                                         . (string) $result['title'] . '” vẫn là phiên bản '
                                         . (int) $result['version'] . '.',
                        'snapshot_id' => (int) $result['snapshot_id'],
                    ];

                    header('Location: ' . wa_url('reader.php?id=' . (int) $result['snapshot_id']));
                    exit;
                }

                $_SESSION['wa_flash'] = [
                    'type'        => 'success',
                    'text'        => 'Lưu thành công phiên bản ' . (int) $result['version'] . ' của “'
                                     . (string) $result['title'] . '”'
                                     . ($imageCount > 0
                                         ? ' — đã tải ' . $imageCount . ' ảnh về máy chủ nội bộ.'
                                         : '.'),
                    'snapshot_id' => (int) $result['snapshot_id'],
                ];

                header('Location: ' . wa_url('reader.php?id=' . (int) $result['snapshot_id']));
                exit;
            }

            /* ---------------- THẤT BẠI ---------------- */
            $notice = [
                'type'  => 'error',
                'text'  => (string) $result['error_message'],
                'title' => (string) $result['error_title'],
                'code'  => (string) $result['error_code'],
                // P-FIX-CN2: lỗi TẠM THỜI -> cho nút "Thử lại".
                'retryable' => in_array((string) $result['error_code'], wa_capture_retryable_errors(), true),
            ];

            // P-FIX-ADMIN: nếu nguyên nhân là "tên miền chưa được duyệt", ta cho
            // người dùng GỬI YÊU CẦU DUYỆT ngay tại đây, kèm nút bấm một lần.
            if ((string) $result['error_code'] === WA_ERR_BLOCKED) {
                $blockedHost = wa_capture_host((string) $result['source_url']);
                $blockedHost = $blockedHost !== '' ? $blockedHost : wa_capture_host($form['url']);

                if ($blockedHost !== '') {
                    $notice['blocked_domain'] = $blockedHost;
                    $notice['domain_requested'] = wa_allowlist_is_approved($blockedHost);

                    // Tự động đưa tên miền vào hàng đợi duyệt NGAY TRONG LẦN LƯU
                    // (không bắt người dùng bấm thêm một nút). Các yêu cầu trùng
                    // tên miền được GỘP lại thành MỘT dòng (xem
                    // wa_request_domain_approval), nên gọi lại nhiều lần vẫn không
                    // làm đầy hàng đợi.
                    if (wa_allowlist_is_approved($blockedHost) === false) {
                        wa_request_domain_approval(
                            $blockedHost,
                            'Tự động ghi nhận khi lưu URL: ' . $form['url'],
                            $userId
                        );
                    }
                }
            }
        }
    }
    }
}

/* ---------------------------------------------------------------------------
 * Biến cho giao diện
 * ------------------------------------------------------------------------- */

// Thông báo thành công do reader.php gửi lại sau khi chuyển hướng.
$flash = null;
if (isset($_SESSION['wa_flash']) && is_array($_SESSION['wa_flash'])) {
    $flash = $_SESSION['wa_flash'];
    unset($_SESSION['wa_flash']);
}

$pageTitle = 'Lưu trang Web';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Bảo tồn nội dung</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Engine tải trang thật</span>
      </div>
      <h1 class="ribbon__title">Lưu trang Web</h1>
      <p class="ribbon__sub">
        Dán liên kết bài viết để tạo một bản snapshot tĩnh, đọc lại được ngoại tuyến và tìm kiếm nội dung đã lưu.
      </p>
    </div>

    <div class="row" style="flex-shrink:0">
      <a class="btn btn--outline" href="<?= wa_e(wa_url('libraries.php')) ?>">
        <span class="ms ms-18">arrow_back</span>
        <span>Về thư viện</span>
      </a>
    </div>
  </div>
</div>

<!-- ===================== BẢNG NHẬP URL ===================== -->
<div class="capture">
  <div class="capture__head">
    <div class="capture__title">
      <span class="capture__title-icon"><span class="ms ms-22">archive</span></span>
      <div>
        <h2 class="t-headline-sm" style="color:var(--wa-primary)">Lưu một trang Web vào kho của bạn</h2>
        <p class="t-body-sm t-muted">Trích xuất nội dung văn bản, tải ảnh về máy chủ nội bộ và tạo bản đọc ngoại tuyến.</p>
      </div>
    </div>
    <span class="chip chip--plain">
      <span class="ms ms-14" style="color:var(--wa-teal-deep)">bolt</span>
      cURL + DOMDocument
    </span>
  </div>

  <form class="capture__form" method="post" action="<?= wa_e(wa_url('save.php')) ?>" id="capture-form">
    <?= wa_csrf_field() ?>

    <!-- Chọn thư viện: chỉ hiện thư viện người dùng có quyền ghi -->
    <div class="capture__input">
      <span class="ms ms-18">folder_special</span>
      <select class="input input--icon" name="library_id" required aria-label="Thư viện để lưu trang">
        <option value="">— Chọn thư viện —</option>
        <?php foreach ($libraries as $library): ?>
          <option value="<?= (int) $library['id'] ?>"
            <?= (string) $form['library'] === (string) $library['id'] ? 'selected' : '' ?>>
            <?= wa_e($library['name']) ?>
            (<?= wa_e($library['role'] === 'owner' ? 'chủ sở hữu' : 'biên tập viên') ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="capture__input">
      <span class="ms ms-18">link</span>
      <input class="input input--icon" type="url" name="url" required
             value="<?= wa_e($form['url']) ?>"
             placeholder="https://example.com/bai-viet-lap-trinh-web"
             aria-label="Địa chỉ trang Web cần lưu">
    </div>

    <div class="capture__input">
      <span class="ms ms-18">sell</span>
      <input class="input input--icon" type="text" name="tags"
             value="<?= wa_e($form['tags']) ?>"
             placeholder="nhãn 1, nhãn 2"
             aria-label="Nhãn phân loại, phân tách bằng dấu phẩy">
    </div>

    <!-- P-FIX-CN1: khi phát hiện URL trùng lặp, ta gửi kèm force=1 để thực
         sự tạo phiên bản mới thay vì tạo bài trùng. -->
    <?php if (!empty($notice['duplicate'])): ?>
      <input type="hidden" name="force" value="1">
    <?php endif; ?>

    <button class="btn btn--dark" type="submit" id="capture-submit">
      <span class="ms ms-18">download_for_offline</span>
      <span class="capture__submit-label">
        <?= !empty($notice['duplicate']) ? 'Tạo phiên bản mới' : 'Lưu trang Web' ?>
      </span>
    </button>
  </form>

  <!-- P-FIX-UI: trạng thái loading + chống gửi form nhiều lần khi đang tải. -->
  <div class="capture__loading" id="capture-loading" hidden aria-live="polite">
    <span class="capture__spinner" aria-hidden="true"></span>
    <span>Đang tải trang và tạo bản lưu ngoại tuyến…</span>
  </div>

  <div class="capture__hint">
    <p>Chỉ những URL thuộc danh sách tên miền được duyệt mới có thể lưu. Mọi đích chuyển hướng và cả ảnh trong bài cũng được kiểm tra lại.</p>
    <a class="linkish" href="<?= wa_e(wa_url('admin/allowlist.php')) ?>">
      <span class="ms ms-16">verified_user</span>
      Xem danh sách tên miền được duyệt
    </a>
  </div>

  <?php if ($flash !== null): ?>
    <div class="state state--success">
      <div class="state__body">
        <span class="state__icon"><span class="ms ms-18 ms-fill">check_circle</span></span>
        <div>
          <span class="state__title">Lưu thành công:</span>
          <p class="state__text"><?= wa_e((string) $flash['text']) ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($notice !== null): ?>
    <?php
    // P-FIX-UI: chọn đúng lớp CSS theo mức độ (error / warning).
    $noticeState = ($notice['type'] ?? 'error') === 'warning' ? 'state--warn' : 'state--error';
    $noticeIcon  = ($notice['type'] ?? 'error') === 'warning' ? 'warning' : 'error_outline';
    ?>
    <div class="state <?= $noticeState ?>">
      <div class="state__body">
        <span class="state__icon"><span class="ms ms-18 ms-fill"><?= $noticeIcon ?></span></span>
        <div>
          <span class="state__title"><?= wa_e((string) ($notice['title'] ?? 'Không thể lưu trang')) ?></span>
          <p class="state__text"><?= wa_e((string) $notice['text']) ?></p>
          <?php if (isset($notice['code'])): ?>
            <p class="state__note">Mã lỗi lưu vào hệ thống: <code><?= wa_e((string) $notice['code']) ?></code></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="state__actions">
        <?php if (!empty($notice['duplicate'])): ?>
          <?php if (!empty($notice['existing_snapshot_id'])): ?>
            <a class="btn btn--primary" href="<?= wa_e(wa_url('reader.php?id=' . (int) $notice['existing_snapshot_id'])) ?>">
              <span class="ms ms-18">menu_book</span>
              Mở bản đang có<?= !empty($notice['existing_version']) ? ' (v' . (int) $notice['existing_version'] . ')' : '' ?>
            </a>
          <?php endif; ?>
          <a class="btn btn--outline" href="<?= wa_e(wa_url('save.php')) ?>">Nhập URL khác</a>
        <?php elseif (!empty($notice['retryable'])): ?>
          <!-- P-FIX-CN2: lỗi TẠM THỜI -> cho nút Thử lại ngay trên trang. -->
          <button class="btn btn--primary" type="submit" form="capture-form">
            <span class="ms ms-18">refresh</span>
            Thử lại
          </button>
          <a class="btn btn--outline" href="<?= wa_e(wa_url('admin/allowlist.php')) ?>">Xem danh sách được duyệt</a>
        <?php elseif (!empty($notice['blocked_domain'])): ?>
          <!-- P-FIX-ADMIN: gửi yêu cầu duyệt tên miền ngay tại trang lưu URL.
               Yêu cầu đã được TỰ ĐỘNG đưa vào hàng đợi khi lưu, nên nút bấm ở
               đây chỉ để xác nhận/ghi chú thêm — không tạo dòng trùng. -->
          <?php if (!empty($notice['domain_requested'])): ?>
            <span class="t-label-sm t-muted">
              Yêu cầu duyệt “<?= wa_e((string) $notice['blocked_domain']) ?>” đã được gửi —
              quản trị viên sẽ duyệt và mọi URL trên tên miền đó sẽ lưu được ngay.
            </span>
          <?php else: ?>
            <form method="post" action="<?= wa_e(wa_url('save.php')) ?>" style="display:inline">
              <?= wa_csrf_field() ?>
              <input type="hidden" name="action" value="request_domain">
              <input type="hidden" name="domain" value="<?= wa_e((string) $notice['blocked_domain']) ?>">
              <button class="btn btn--primary" type="submit">
                <span class="ms ms-18">forward_to_inbox</span>
                Gửi yêu cầu duyệt “<?= wa_e((string) $notice['blocked_domain']) ?>”
              </button>
            </form>
          <?php endif; ?>
          <a class="btn btn--outline" href="<?= wa_e(wa_url('admin/allowlist.php')) ?>">Xem danh sách được duyệt</a>
        <?php else: ?>
          <a class="btn btn--outline" href="<?= wa_e(wa_url('admin/allowlist.php')) ?>">Xem danh sách được duyệt</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
  // P-FIX-UI: chống gửi form nhiều lần + hiện trạng thái loading khi lưu URL.
  (function () {
    var form = document.getElementById('capture-form');
    var loading = document.getElementById('capture-loading');
    var submit = document.getElementById('capture-submit');
    if (!form || !loading || !submit) { return; }

    form.addEventListener('submit', function () {
      if (form.dataset.submitted === '1') { return; }
      form.dataset.submitted = '1';
      loading.hidden = false;
      submit.disabled = true;
      var label = submit.querySelector('.capture__submit-label');
      if (label) { label.textContent = 'Đang lưu…'; }
    });
  })();
</script>

<!-- ===================== QUY TẮC LƯU TRANG ===================== -->
<div class="pagehead" style="margin-bottom:16px">
  <div>
    <div class="pagehead__eyebrow">
      <span class="t-label-sm t-upper t-accent" style="font-weight:600">Nguyên tắc hệ thống</span>
    </div>
    <h2 class="t-headline-md" style="color:var(--wa-primary)">Bảy quy tắc khi lưu một trang</h2>
    <p class="pagehead__desc">
      Mỗi lần lưu đều đi qua engine tải trang thật trong <code>src/capture.php</code> và
      ghi CSDL qua một đường dùng chung <code>wa_store_page_capture()</code>.
      Trang lỗi không bao giờ trở thành một bài viết hợp lệ.
    </p>
  </div>
</div>

<div class="state-list">
  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">gpp_bad</span>Kiểm tra danh sách được duyệt trước</span>
      <span class="chip chip--teal">error_blocked</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Tên miền phải khớp một mục trong danh sách được duyệt (cho phép cả subdomain).
      Việc kiểm tra diễn ra <b>trước khi</b> gửi bất kỳ yêu cầu mạng nào, và được kiểm tra
      lại ở mỗi lần chuyển hướng cũng như với từng ảnh trong bài.
    </p>
  </div>

  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">timer_off</span>Giới hạn thời gian chờ</span>
      <span class="chip chip--warn">error_timeout</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Chờ kết nối tối đa 5 giây và tổng thời gian tải tối đa 12 giây; tối đa 5 lần chuyển
      hướng và 5 MB cho mỗi tài nguyên.
    </p>
  </div>

  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">error_outline</span>Phân biệt từng nguyên nhân lỗi</span>
      <span class="chip chip--error">error_404 · error_http · error_no_content</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Mỗi nguyên nhân có một mã trạng thái riêng và một thông báo tiếng Việt riêng.
      Lần lưu thất bại vẫn được ghi lại để thống kê quản trị đếm được lỗi.
    </p>
  </div>

  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">image</span>Ảnh tải về máy chủ nội bộ</span>
      <span class="chip chip--teal">assets</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Tối đa 20 ảnh mỗi bài, chỉ nhận JPEG/PNG/GIF/WebP đã kiểm tra nội dung thật, lưu vào
      <code>public/uploads/snapshots/&lt;id&gt;/</code> và được nối lại bằng đường dẫn cục bộ.
    </p>
  </div>

  <div class="state-item">
    <div class="state-item__head">
      <span class="state-item__name"><span class="ms ms-18">history</span>Mỗi lần lưu là một phiên bản</span>
      <span class="chip chip--plain">version_no</span>
    </div>
    <p class="t-body-sm t-muted" style="margin-top:8px">
      Lưu lại cùng một URL trong cùng thư viện sẽ tạo phiên bản tiếp theo; các phiên bản
      cũ vẫn được giữ nguyên. Nút <b>Lấy phiên bản mới</b> trong trình đọc dùng chung
      đúng đường lưu này.
    </p>
  </div>
</div>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>