<?php
/**
 * index.php — TRANG CHỦ DÀNH CHO KHÁCH (guest landing).
 *
 * Nội dung bám theo bản tham chiếu code4.html: hero, mockup kho lưu trữ,
 * 3 khối tính năng, quy trình 3 bước, không gian đọc, banner kêu gọi hành động.
 *
 * LƯU Ý: dùng header_guest.php (KHÔNG có user chip / Đăng xuất / menu workspace).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
// P-FIX-UI (6): số liệu thật từ CSDL cho khối minh hoạ bản lưu mẫu.
require_once __DIR__ . '/../templates/wa_metrics.php';

$pageTitle = 'WebArchive — Lưu tri thức hôm nay. Đọc lại bất cứ lúc nào.';

// P-FIX-UI (6): bản thật mới nhất trong CSDL để minh hoạ (có ảnh cục bộ còn
// trên đĩa). Mọi con số hiển thị trong khối mock đều lấy từ đây.
$showcase = wa_metrics_showcase_snapshot();

// P-FIX-LANDING-1 (3): biến $readingContrast đã bị gỡ cùng panel sơ đồ quy
// trình ở khối "Không gian tập trung" — không còn chỗ hiển thị nên không tính.

// P-FIX-UI (6): tỉ lệ bản lưu thành công + số trang hỏng, đo trên toàn hệ
// thống — thay cho "100% Tĩnh" và "0 liên kết lỗi" của bản mock.
$landingMetrics = wa_metrics_for_libraries(wa_metrics_all_library_ids());
$landingBrokenLinks = $landingMetrics['pages_failed'];
$landingIntegrityLabel = $landingMetrics['snapshots'] === 0
    ? 'Chưa có bản lưu'
    : ($landingMetrics['snapshots_ok'] === $landingMetrics['snapshots']
        ? '100% Tĩnh'
        : $landingMetrics['snapshots_ok'] . '/' . $landingMetrics['snapshots'] . ' bản lưu');

// P-FIX-UI (6): độ dài dòng của cột đọc tính từ --wa-reader-col (780px) và
// cỡ chữ 18px — thay cho khẩu hiệu "65-75" ghi cứng trong bản mock.
$readerMeasure = wa_metrics_reader_measure();

// A6: tổng thời gian đọc của toàn bộ nội dung đã lưu (tính từ CSDL) — dùng
// cho thẻ tính năng "Đọc lại độc lập" để mọi dòng số liệu đều có nguồn thật.
$landingReading = wa_metrics_reading_total(wa_metrics_all_library_ids());

// A1: các thumbnail cục bộ THẬT của bản lưu đang minh hoạ (kèm kích thước
// pixel đọc từ tệp, để không phóng to ảnh nhỏ).
$showcaseThumbs = $showcase !== null
    ? wa_metrics_snapshot_thumbs((int) $showcase['snapshot_id'], 3)
    : [];

require __DIR__ . '/../templates/header_guest.php';
?>

<!-- ===================== HERO ===================== -->
<section class="hero">
  <div class="hero__inner">
    <!-- P-FIX-LANDING-1 (1): gỡ nhãn mã số ra khỏi hero — người đọc không
         cần biết mã số; pill chỉ giữ lại thông điệp lợi ích. -->
    <div class="hero__badge">
      <span class="dot dot--live"></span>
      <span>Đọc ngoại tuyến an toàn &amp; lưu trữ tĩnh HTML</span>
    </div>

    <h1 class="hero__title">
      Lưu tri thức hôm nay.<br>
      <span>Đọc lại bất cứ lúc nào.</span>
    </h1>

    <p class="hero__lead">
      Lưu nội dung bài viết từ những trang Web được hỗ trợ, sắp xếp vào thư viện cá nhân
      và đọc lại từ bản lưu ngay cả khi trang gốc không còn hoạt động.
    </p>

    <div class="hero__cta">
      <a class="btn btn--lg btn--dark" href="<?= wa_e(wa_url('register.php')) ?>">
        <span>Bắt đầu lưu bài viết</span>
        <span class="ms ms-18">arrow_forward</span>
      </a>
      <a class="btn btn--lg btn--light" href="#ban-luu-mau">
        <span class="ms ms-18" style="color:var(--wa-action)">visibility</span>
        <span>Khám phá bản lưu mẫu</span>
      </a>
    </div>

    <div class="hero__micro">
      <span><span class="ms ms-14 ms-fill" style="color:var(--wa-teal-deep)">check_circle</span> Không quảng cáo bám đuổi</span>
      <span><span class="ms ms-14 ms-fill" style="color:var(--wa-teal-deep)">check_circle</span> Bản snapshot vĩnh cửu</span>
      <span><span class="ms ms-14 ms-fill" style="color:var(--wa-teal-deep)">check_circle</span> Tối ưu đọc ban đêm</span>
    </div>
  </div>
</section>

<!-- ===================== MOCKUP BẢN LƯU MẪU =====================
     P-FIX-UI (6): khối này minh hoạ MỘT BẢN LƯU THẬT trong CSDL — mọi con
     số (mã snapshot, mã kiểm chứng, dung lượng, số ảnh, tác giả, thời gian
     đọc) đều lấy từ dữ liệu thật, không phải số bịa của bản mock. -->
<section class="page page--plain" style="padding-top:0" id="ban-luu-mau">
  <div class="mock">
    <div class="mock__bar">
      <div class="mock__dots">
        <span class="dot" style="width:12px;height:12px;background:rgba(186,26,26,.7)"></span>
        <span class="dot" style="width:12px;height:12px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="dot" style="width:12px;height:12px;background:rgba(53,165,154,.6)"></span>
        <span class="mock__url">
          <span class="ms ms-14">lock</span>
          <span>archive.local/snapshot/view?id=<?= $showcase !== null ? 'SNAP-' . $showcase['snapshot_id'] : 'SNAP-0' ?></span>
        </span>
      </div>
      <div class="chip chip--plain">
        <span class="ms ms-14" style="color:var(--wa-teal-deep)">verified</span>
        <span>Mã kiểm chứng: sha256:<?= $showcase !== null ? substr($showcase['checksum'], 0, 6) : '—' ?></span>
      </div>
    </div>

    <div class="mock__body">
      <div class="row" style="gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <span class="chip chip--blue"><?= $showcase !== null ? wa_e($showcase['library_name']) : 'Thư viện Web tĩnh' ?></span>
        <span class="chip chip--plain"><?= $showcase !== null ? wa_e($showcase['html_size_label']) : '—' ?> HTML</span>
        <span class="chip chip--teal">
          <span class="dot dot--teal"></span> HTML sạch đã tách
        </span>
        <span class="chip chip--plain"><?= $showcase !== null ? $showcase['image_present'] : 0 ?> ảnh đã tải về máy chủ</span>
      </div>

      <div class="mock__doc">
        <div class="row t-body-sm t-muted" style="gap:8px;margin-bottom:8px">
          <span class="ms ms-16">public</span>
          <span>nguồn: <?= $showcase !== null ? wa_e($showcase['source_url']) : '—' ?></span>
          <span>•</span>
          <span>Đã sao lưu: <?= $showcase !== null ? wa_e($showcase['fetched_label']) : '—' ?></span>
        </div>

        <h2 class="t-reader-title" style="color:var(--wa-primary);font-size:26px;line-height:36px"><?= $showcase !== null ? wa_e($showcase['title']) : 'Bản lưu Web tĩnh' ?></h2>

        <div class="row t-body-sm t-muted" style="gap:16px;margin-top:8px;padding-bottom:16px">
          <span>Tác giả: <?= $showcase !== null ? wa_e($showcase['creator']) : '—' ?></span>
          <span>•</span>
          <span>Thời gian đọc: <?= $showcase !== null ? wa_e($showcase['reading_label']) : '—' ?></span>
          <span class="chip chip--teal" style="gap:4px">
            <span class="ms ms-14">offline_pin</span> Sẵn sàng đọc ngoại tuyến
          </span>
        </div>

        <!-- A1: khối minh hoạ ảnh.
             Ô "ảnh minh hoạ" cũ chỉ là một hình chữ nhật xanh nhạt trống, trông
             như ảnh hỏng. Nay khối này là một MẪU THIẾT KẾ có nội dung thật:
             tiêu đề ảnh, các dòng chữ mờ (mô phỏng bài viết), và các THUMBNAIL
             THẬT đã tải về máy chủ (nằm trong public/uploads/…) hiển thị ở kích
             thước gốc — không phóng to từ ảnh 64x64 làm méo. -->
        <div class="mock__figure">
          <div class="mock__figure-head">
            <span class="ms ms-18 ms-fill" style="color:var(--wa-action)">image</span>
            <span class="mock__figure-title">
              <?= $showcase !== null
                  ? 'Ảnh đã tải về máy chủ nội bộ — bản lưu #' . (int) $showcase['snapshot_id']
                  : 'Ảnh đã tải về máy chủ nội bộ' ?>
            </span>
          </div>

          <div class="mock__figure-lines" aria-hidden="true">
            <span style="width:92%"></span>
            <span style="width:78%"></span>
            <span style="width:86%"></span>
            <span style="width:54%"></span>
          </div>

          <div class="mock__figure-thumbs">
            <?php foreach ($showcaseThumbs as $thumb): ?>
              <img class="mock__figure-thumb"
                   src="<?= wa_e($thumb['src']) ?>"
                   alt="Ảnh cục bộ đã tải về (<?= (int) $thumb['width'] ?>&times;<?= (int) $thumb['height'] ?>)"
                   loading="lazy">
            <?php endforeach; ?>
            <div class="mock__figure-note">
              <?= $showcase !== null ? (int) $showcase['image_present'] : 0 ?> ảnh trong bản lưu này —
              tải về <code>public/uploads/</code> nên đọc được khi mất mạng
            </div>
          </div>
        </div>

        <p class="t-reader-body" style="margin-top:16px;font-size:16px;line-height:28px;color:var(--wa-text-soft)">
          Khi xây dựng các thư viện lưu trữ ngoại tuyến độc lập, nguyên tắc cốt lõi là bóc tách toàn bộ
          tài nguyên động (JavaScript theo dõi, biểu mẫu bên ngoài, quảng cáo) và chỉ giữ lại cấu trúc
          ngữ nghĩa DOM thuần túy. Quá trình chuyển hóa các thẻ ảnh và stylesheet thành đường dẫn cục bộ
          giúp bài viết hiển thị lại được mà không phụ thuộc vào tên miền gốc.
        </p>

        <!-- A9: thay hai chip phông chữ giả (trình đọc thật KHÔNG có chuyển
             phông) bằng các điều khiển CÓ THẬT trong reader.php, kèm cỡ chữ
             mặc định đọc từ app.css. -->
        <div class="row row--between" style="gap:8px;margin-top:16px;padding:8px 16px;border-radius:8px;background:var(--wa-surface-low)">
          <div class="row" style="gap:8px">
            <span class="chip chip--plain">A- / A+ đổi cỡ chữ</span>
            <span class="chip chip--plain">Nền sáng / ban đêm</span>
            <span class="t-label-sm t-muted">Cỡ chữ mặc định: 18px</span>
          </div>
          <div class="row t-label-sm t-muted" style="gap:6px">
            <span class="ms ms-16">download_for_offline</span>
            <span>Đã nén ZIP — tổng <?= $showcase !== null ? wa_e($showcase['total_size_label']) : '—' ?> (HTML <?= $showcase !== null ? wa_e($showcase['html_size_label']) : '—' ?> + ảnh <?= $showcase !== null ? wa_e($showcase['size_label']) : '—' ?>)</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===================== 3 TÍNH NĂNG ===================== -->
<section class="page page--plain" id="tinh-nang">
  <!-- P-FIX-LANDING-1 (2): tiêu đề khối nay là MỘT khối căn trái duy nhất
       (kicker → heading → đoạn giới thiệu xếp dọc). Trước đây đoạn giới thiệu
       nằm lơ lửng bên phải, trông như lỗi bố cục. -->
  <div class="section-head">
    <span class="t-label-md t-upper t-accent">Giá trị cốt lõi</span>
    <h2 class="section-title" style="margin-top:4px">Được thiết kế cho người nghiên cứu sâu</h2>
    <p class="section-sub" style="margin-top:16px;max-width:640px">
      Không chỉ đánh dấu trang (bookmark). WebArchive lưu trữ nguyên vẹn văn bản và tệp hình ảnh
      vào hệ sinh thái của bạn.
    </p>
  </div>

  <div class="feature-grid">
    <div class="feature">
      <div class="feature__body">
        <div class="feature__icon"><span class="ms ms-24">folder_special</span></div>
        <span class="feature__eyebrow">Tổ chức khoa học</span>
        <h3>Thư viện ngăn nắp</h3>
        <p>
          Phân loại bài viết theo chủ đề chuyên sâu, gán nhãn đa tầng linh hoạt và đồng bộ mục lục
          tra cứu giúp việc truy xuất tài liệu nghiên cứu diễn ra tức thì.
        </p>
      </div>
      <div class="feature__foot">
        <div class="feature__foot-top">
          <span>Dung lượng thư viện</span>
          <!-- A6: mẫu thống nhất cho cả ba thẻ — một nhãn nhỏ + MỘT dòng số
               liệu thật tính từ CSDL. -->
          <b><?= wa_e(wa_format_mb($landingMetrics['bytes_total'])) ?></b>
        </div>
        <div class="feature__tags">
          <span class="chip chip--plain"><?= (int) $landingMetrics['pages'] ?> trang đã lưu</span>
          <span class="chip chip--plain"><?= (int) $landingMetrics['image_present'] ?> ảnh trên đĩa</span>
        </div>
      </div>
    </div>

    <div class="feature">
      <div class="feature__body">
        <div class="feature__icon feature__icon--blue"><span class="ms ms-24">save_as</span></div>
        <span class="feature__eyebrow">Miễn nhiễm biến động</span>
        <h3>Lưu nội dung an toàn</h3>
        <p>
          Bóc tách mã HTML tĩnh chuẩn xác, tự động tải và liên kết hình ảnh cục bộ độc lập. An tâm tuyệt đối
          ngay cả khi trang gốc đổi cấu trúc, xuất hiện tường thu phí hoặc biến mất hoàn toàn (Lỗi 404).
        </p>
      </div>
      <div class="feature__foot">
        <div class="feature__foot-top">
          <span>Bản lưu thành công</span>
          <!-- P-FIX-UI (6): "100% Tĩnh" và "0 liên kết lỗi" là số bịa — thay bằng
               tỉ lệ bản lưu thành công và số trang hỏng đo từ CSDL. -->
          <b class="t-teal"><?= $landingIntegrityLabel ?></b>
        </div>
        <div class="feature__tags">
          <span class="chip chip--teal">SHA-256 xác thực</span>
          <span class="chip chip--teal"><?= $landingBrokenLinks ?> liên kết lỗi</span>
        </div>
      </div>
    </div>

    <div class="feature">
      <div class="feature__body">
        <div class="feature__icon feature__icon--mint"><span class="ms ms-24">menu_book</span></div>
        <span class="feature__eyebrow">Không gian thiền định</span>
        <h3>Đọc lại độc lập</h3>
        <p>
          Trình đọc tối giản (Distraction-free Reader) lược bỏ toàn bộ cửa sổ pop-up phiền toái,
          font chữ Merriweather chuẩn mực xuất bản báo chí quốc tế, hoạt động trơn tru ngay cả khi
          không có kết nối mạng Internet.
        </p>
      </div>
      <div class="feature__foot">
        <div class="feature__foot-top">
          <span>Tổng thời gian đọc</span>
          <!-- A6: dòng số liệu thật — tổng phút đọc của toàn bộ bản lưu
               thành công trong CSDL (200 từ/phút, 5 ký tự/từ). -->
          <b><?= (int) $landingReading['minutes'] ?> phút</b>
        </div>
        <div class="feature__tags">
          <span class="chip chip--plain"><?= (int) $landingReading['articles'] ?> bản lưu đọc được</span>
          <span class="chip chip--plain">Cột đọc <?= (int) $readerMeasure['column_px'] ?>px</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===================== QUY TRÌNH 3 BƯỚC ===================== -->
<section class="page page--plain" id="quy-trinh">
  <div class="steps-panel">
    <div class="steps-head">
      <span class="t-label-md t-upper t-accent">Quy trình đơn giản</span>
      <h2 class="section-title" style="margin-top:4px">Cách thức hoạt động trong 3 bước</h2>
      <p class="t-body-md t-soft" style="margin-top:8px;max-width:640px">
        Không yêu cầu cài đặt tiện ích mở rộng phức tạp. Mọi quy trình xử lý nội dung được thực hiện
        chuẩn chỉ trên máy chủ cá nhân.
      </p>
    </div>

    <div class="steps-grid">
      <div class="step">
        <div class="step__top">
          <span class="step__num">01</span>
          <span class="step__icon"><span class="ms ms-18">link</span></span>
        </div>
        <h3>Dán liên kết bài viết</h3>
        <p>
          Hệ thống tự động phân tích và kiểm tra tính hợp lệ của URL, xác nhận cấu trúc nguồn từ
          trang báo điện tử hoặc tài liệu công nghệ.
        </p>
        <!-- A9: ví dụ URL này lấy từ một trang ĐÃ LƯU THẬT trong CSDL (bản ghi
             mới nhất), không phải đường dẫn bịa đặt. -->
        <div class="step__note"><?= $showcase !== null ? wa_e($showcase['source_url']) : '—' ?></div>
      </div>

      <div class="step">
        <div class="step__top">
          <span class="step__num">02</span>
          <span class="step__icon"><span class="ms ms-18">data_object</span></span>
        </div>
        <h3>Bóc tách HTML &amp; lưu hình ảnh</h3>
        <p>
          Máy chủ tách lọc tiêu đề, nội dung chính, loại bỏ kịch bản theo dõi và tải toàn bộ ảnh đính kèm
          lưu về bộ nhớ độc lập.
        </p>
        <div class="step__note" style="color:var(--wa-teal-deep)">
          <span class="ms ms-14">tune</span> Chuẩn hóa DOM • Nén tài nguyên tĩnh
        </div>
      </div>

      <div class="step">
        <div class="step__top">
          <span class="step__num">03</span>
          <span class="step__icon"><span class="ms ms-18">chrome_reader_mode</span></span>
        </div>
        <h3>Đọc lại trọn vẹn ngoại tuyến</h3>
        <p>
          Mở bài viết trong không gian đọc ấm áp, chỉnh cỡ chữ tùy ý và thưởng thức văn bản thuần khiết
          mà không cần kết nối mạng.
        </p>
        <div class="step__note" style="color:var(--wa-primary)">
          <span class="ms ms-14">auto_stories</span> Không xao nhãng • Tự do biên mục
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===================== KHÔNG GIAN ĐỌC ===================== -->
<section class="page page--plain" id="khong-gian-doc">
  <div class="showcase">
    <div class="showcase__text">
      <span class="t-label-md t-upper t-accent">Không gian tập trung</span>
      <h2 class="section-title" style="margin-top:4px">Đọc theo cách mà mắt bạn xứng đáng được trải nghiệm</h2>
      <p class="section-sub" style="margin-top:16px;line-height:1.7">
        Được thiết kế riêng cho những ai dành hàng giờ đắm mình trong các bài luận dài. Tùy chỉnh màu
        giấy ngà dịu mắt, phông chữ báo in kinh điển và theo dõi tiến độ đọc theo thời gian thực.
      </p>

      <ul class="checklist">
        <li>
          <span class="ms ms-18 ms-fill">check_box</span>
          <div>
            <b>Tự chủ dữ liệu hoàn toàn</b>
            <p>Toàn bộ dữ liệu nằm trên máy chủ nội bộ hoặc cơ sở dữ liệu riêng của bạn.</p>
          </div>
        </li>
        <li>
          <span class="ms ms-18 ms-fill">check_box</span>
          <div>
            <b>Xuất dữ liệu mở định dạng tiêu chuẩn</b>
            <p>Dễ dàng tải về bản nén ZIP chứa file HTML đơn lập và thư mục ảnh đính kèm.</p>
          </div>
        </li>
      </ul>

      <div style="margin-top:40px">
        <a class="row" style="gap:6px;color:var(--wa-primary);font-size:18px;font-weight:600" href="<?= wa_e(wa_url('login.php')) ?>">
          <span>Trải nghiệm không gian đọc mẫu</span>
          <span class="ms ms-16">arrow_forward</span>
        </a>
      </div>
    </div>

    <!-- P-FIX-LANDING-2: minh hoạ TRẢI NGHIỆM ĐỌC ở cột phải — dựng lại từ
         chính bộ điều khiển có thật của trình đọc (nền giấy ngà, thanh tiến độ
         đọc, nút A- / A+, nút đổi nền đọc). TUYỆT ĐỐI không có sơ đồ quy trình
         và không có thuật ngữ kỹ thuật: trang giới thiệu bán TRẢI NGHIỆM đọc,
         không bán kiến trúc hệ thống. -->
    <div class="showcase__art">
      <figure class="readpreview" aria-label="Minh hoạ trải nghiệm đọc: nền giấy ngà, phông chữ báo in, thanh tiến độ đọc và nút đổi cỡ chữ.">
        <!-- Thanh tiến độ đọc (giống thanh trên cùng của trình đọc thật). -->
        <div class="readpreview__progress" aria-hidden="true"><i></i></div>

        <div class="readpreview__bar">
          <span class="readpreview__back">
            <span class="ms ms-14" aria-hidden="true">arrow_back</span>
            Quay lại thư viện
          </span>
          <!-- Nút A- / A+: đúng bộ điều khiển có thật trong reader.php. -->
          <div class="readpreview__seggroup">
            <span class="readpreview__seg">A-</span>
            <span class="readpreview__sep" aria-hidden="true"></span>
            <span class="readpreview__seg">A+</span>
          </div>
        </div>

        <!-- Mẫu trang đọc: nền giấy ngà + phông chữ báo in. -->
        <div class="readpreview__paper">
          <h3 class="readpreview__title">Đọc chậm, đọc sâu</h3>
          <p class="readpreview__text">
            Một trang giấy ngà dịu mắt, cỡ chữ vừa phải và khoảng trắng thoải mái
            giúp bạn đọc được những bài dài mà không mỏi mắt. Tiến độ đọc luôn
            nằm trong tầm mắt để bạn biết mình đang ở đâu.
          </p>
          <div class="readpreview__row">
            <span class="readpreview__track" aria-hidden="true"><i></i></span>
            <span class="readpreview__rowlabel">Tiến độ đọc</span>
          </div>
        </div>

        <!-- Nút đổi nền đọc: đúng bộ điều khiển có thật trong reader.php. -->
        <div class="readpreview__foot">
          <div class="readpreview__seggroup">
            <span class="readpreview__seg is-active">Ấm áp</span>
            <span class="readpreview__sep" aria-hidden="true"></span>
            <span class="readpreview__seg">Trắng tinh</span>
            <span class="readpreview__sep" aria-hidden="true"></span>
            <span class="readpreview__seg">Ban đêm</span>
          </div>
          <span class="t-label-sm t-muted">Nền giấy ngà · Phông chữ báo in</span>
        </div>
      </figure>
    </div>
  </div>
</section>

<!-- ===================== CTA ===================== -->
<section class="page page--plain">
  <div class="cta-banner">
    <div class="cta-banner__inner">
      <div class="cta-banner__copy">
        <span class="t-label-md t-upper" style="color:var(--wa-primary-fixed)">Khởi tạo kho tàng số</span>
        <h2 style="margin-top:4px">Xây dựng thư viện tri thức trường tồn cùng thời gian.</h2>
        <p>
          Bắt đầu lưu trữ những bài viết giá trị nhất của bạn ngay bây giờ. Hoàn toàn bảo mật, độc lập
          và luôn sẵn sàng mỗi khi bạn cần tra cứu.
        </p>
        <div class="cta-banner__actions">
          <a class="btn btn--lg btn--light" href="<?= wa_e(wa_url('register.php')) ?>">
            <span>Bắt đầu lưu bài viết đầu tiên</span>
            <span class="ms ms-16">arrow_forward</span>
          </a>
          <a class="btn btn--lg" style="background:var(--wa-primary);color:var(--wa-on-primary)" href="<?= wa_e(wa_url('login.php')) ?>">
            <span>Xem thư viện mẫu</span>
          </a>
        </div>
      </div>

      <!-- A3: khối số liệu thật ở NỬA PHẢI của panel tối, lấy trực tiếp từ
           CSDL (không phải số bịa của bản mock). -->
      <div class="cta-banner__metrics" aria-label="Số liệu hệ thống">
        <div class="cta-banner__metric">
          <span class="cta-banner__metric-value"><?= (int) $landingMetrics['libraries'] ?></span>
          <span class="cta-banner__metric-label">Thư viện đang có</span>
        </div>
        <div class="cta-banner__metric">
          <span class="cta-banner__metric-value"><?= (int) $landingMetrics['pages'] ?></span>
          <span class="cta-banner__metric-label">Trang đã lưu</span>
        </div>
        <div class="cta-banner__metric">
          <span class="cta-banner__metric-value"><?= (int) $landingMetrics['snapshots_ok'] ?>/<?= (int) $landingMetrics['snapshots'] ?></span>
          <span class="cta-banner__metric-label">Bản lưu thành công</span>
        </div>
        <div class="cta-banner__metric">
          <span class="cta-banner__metric-value"><?= wa_e(wa_format_mb($landingMetrics['bytes_total'])) ?></span>
          <span class="cta-banner__metric-label">Dung lượng tài nguyên</span>
        </div>
      </div>
    </div>
  </div>

  <div class="landing-foot__note">
    <span class="chip chip--plain">
      <span class="ms ms-14" style="color:var(--wa-teal-deep)">memory</span>
      Hệ thống hỗ trợ lưu trữ cục bộ HTML &amp; hình ảnh an toàn • Tối ưu cho PHP &amp; MySQL
    </span>
  </div>
</section>

<?php require __DIR__ . '/../templates/footer_guest.php'; ?>
