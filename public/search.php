<?php
/**
 * search.php — Trang kết quả tìm kiếm nội dung đã lưu (P-SEARCH).
 *
 * Thể hiện giao diện của chức năng số 4 trong đặc tả: tìm kiếm dựa trên NỘI DUNG
 * ĐÃ LƯU, mỗi kết quả hiển thị đoạn trích có từ khoá được làm nổi bật và luôn
 * gắn với đúng thư viện + đúng PHIÊN BẢN snapshot.
 *
 * Trang này KHÔNG tự quét `snapshots.title` / `content_text`. Nó gọi
 * wa_search_snapshots() trong src/search.php, hàm chạy trên bảng chỉ mục đảo
 * `search_terms` do dự án tự dựng: ngữ nghĩa AND, xếp hạng
 * score = 3 × SUM(count_title) + SUM(count_body), quyền truy cập lọc trong SQL.
 *
 * Tham số GET (không đổi so với trước):
 *   q       — từ khoá (nhiều từ = ngữ nghĩa AND)
 *   tag     — lọc theo nhãn
 *   domain  — lọc theo tên miền nguồn
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$query        = trim((string) ($_GET['q'] ?? ''));
$tagFilter    = trim((string) ($_GET['tag'] ?? ''));

// Hiển thị và so khớp lựa chọn luôn ở dạng '#...' (gõ tay 'php' trên URL
// vẫn chọn đúng mục '#php' trong danh sách lọc).
if ($tagFilter !== '' && !str_starts_with($tagFilter, '#')) {
    $tagFilter = '#' . $tagFilter;
}
$domainFilter = trim((string) ($_GET['domain'] ?? ''));

// Truy vấn CHỈ trên chỉ mục đảo. Từ khoá rỗng (hoặc chỉ gõ dấu câu) thì hàm
// trả về rỗng và trang hiện đúng gợi ý tiếng Việt bên dưới.
$results = wa_search_snapshots([
    'q'      => $query,
    'tag'    => $tagFilter,
    'domain' => $domainFilter,
]);

// Danh sách tên miền có trong kho (để lọc) + toàn bộ thẻ đang có.
$domains = wa_snapshot_domains();
$allTags = wa_all_tags();

// Điều kiện "đã thực sự tìm" để quyết định hiện thông báo kết quả hay gợi ý.
$hasQuery = wa_search_query_terms($query) !== [];
$hasFilter = $tagFilter !== '' || $domainFilter !== '';
$pageTitle = 'Tìm kiếm nội dung';
require __DIR__ . '/../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Workspace / Truy xuất tri thức</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Chỉ mục đảo tự xây</span>
      </div>
      <h1 class="ribbon__title">Tìm kiếm nội dung đã lưu</h1>
      <p class="ribbon__sub">
        Tìm kiếm chạy trên <b>chỉ mục đảo tự xây dựng</b> của nội dung bản snapshot đã lưu —
        không phân biệt dấu tiếng Việt, nhiều từ là <b>và</b> (AND), và kết quả luôn trỏ về đúng phiên bản bạn đang đọc.
      </p>
    </div>

    <div class="row" style="flex-shrink:0">
      <span class="chip chip--teal">
        <span class="ms ms-14">offline_pin</span>
        <?= count($results) ?> kết quả trong kho nội dung
      </span>
    </div>
  </div>
</div>

<!-- ===================== Ô TÌM KIẾM ===================== -->
<form class="filter-bar" method="get" action="<?= wa_e(wa_url('search.php')) ?>">
  <div class="field" style="flex:1 1 320px">
    <label for="q">Từ khoá</label>
    <div class="searchbox" style="flex:1">
      <span class="ms">search</span>
      <input class="input" type="search" id="q" name="q" value="<?= wa_e($query) ?>"
             placeholder="Ví dụ: lưu trang, mã hoá, snapshot..." aria-label="Từ khoá tìm kiếm">
    </div>
  </div>

  <div class="field">
    <label for="tag">Lọc theo thẻ</label>
    <select class="select" id="tag" name="tag">
      <option value="">Tất cả thẻ</option>
      <?php foreach ($allTags as $tag): ?>
        <option value="<?= wa_e($tag) ?>" <?= $tagFilter === $tag ? 'selected' : '' ?>><?= wa_e($tag) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="domain">Lọc theo tên miền nguồn</label>
    <select class="select" id="domain" name="domain">
      <option value="">Tất cả tên miền</option>
      <?php foreach ($domains as $domain): ?>
        <option value="<?= wa_e($domain) ?>" <?= $domainFilter === $domain ? 'selected' : '' ?>><?= wa_e($domain) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <button class="btn btn--primary" type="submit">
    <span class="ms ms-18">search</span>
    <span>Tìm kiếm</span>
  </button>

  <?php if ($query !== '' || $hasFilter): ?>
    <a class="btn btn--ghost" href="<?= wa_e(wa_url('search.php')) ?>">Xoá bộ lọc</a>
  <?php endif; ?>
</form>

<?php if (!$hasQuery): ?>
  <div class="note-box">
    <b>Gợi ý:</b> nhập ít nhất một từ có nghĩa. Từ khoá được <b>bỏ dấu</b> trước khi
    so khớp, nên gõ <code>luu trang</code> vẫn ra nội dung có dấu là
    <code>lưu trang</code>. Nhiều từ được hiểu là <b>và</b> (AND): bài viết phải chứa
    <i>tất cả</i> các từ đó. Thử với các từ khoá như <code>snapshot</code>,
    <code>mã hoá</code>, <code>lưu trang</code>, <code>ảnh cục bộ</code> hoặc
    <code>allowlist</code>.
  </div>
<?php endif; ?>

<!-- ===================== KẾT QUẢ ===================== -->
<div style="margin-top:24px">
  <?php if ($hasQuery || $hasFilter): ?>
    <p class="t-body-md t-muted" style="margin-bottom:16px">
      Tìm thấy <strong class="t-primary"><?= count($results) ?></strong> kết quả
      <?php if ($hasQuery): ?>
        cho “<strong class="t-primary"><?= wa_e($query) ?></strong>”
      <?php endif; ?>
      <?php if ($tagFilter !== ''): ?>
        với nhãn <strong class="t-primary"><?= wa_e($tagFilter) ?></strong>
      <?php endif; ?>
      <?php if ($domainFilter !== ''): ?>
        từ miền <strong class="t-primary"><?= wa_e($domainFilter) ?></strong>
      <?php endif; ?>
      trong nội dung đã lưu.
      <?php if ($hasQuery): ?>
        Xếp theo điểm <code>3 × (số lần trong tiêu đề) + (số lần trong thân)</code>,
        sau đó tới bản lưu mới nhất.
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php foreach ($results as $hit): ?>
    <?php
    // Điều kiện so khớp dùng chung cho cả tiêu đề lẫn đoạn trích.
    $hitTerms = wa_search_query_terms($query);
    ?>
    <div class="result">
      <div class="result__top">
        <span class="chip chip--blue">
          <span class="ms ms-14">folder_special</span><?= wa_e($hit['library_name']) ?>
        </span>
        <span class="chip chip--plain">
          <span class="ms ms-14">history</span>Phiên bản <?= (int) $hit['version_no'] ?>
        </span>
        <span class="chip chip--plain">
          <span class="ms ms-14">public</span><?= wa_e($hit['domain']) ?>
        </span>
        <span class="chip chip--plain">
          <span class="ms ms-14">tag</span>Điểm <?= (int) $hit['score'] ?>
        </span>
      </div>

      <h3><?= wa_search_highlight_title((string) $hit['title'], $hitTerms) ?></h3>

      <p class="result__snippet">
        <?= $hit['snippet'] /* đã escape + bọc <mark> sẵn trong wa_search_snippet() */ ?>
      </p>

      <div class="row" style="gap:6px;margin-top:12px">
        <?php foreach ($hit['tags'] as $tag): ?>
          <span class="tag"><?= wa_e($tag) ?></span>
        <?php endforeach; ?>
      </div>

      <div class="result__foot">
        <span><span class="ms ms-14">description</span>Snapshot: <strong>SNAP-<?= (int) $hit['id'] ?></strong></span>
        <span><span class="ms ms-14">public</span>Nguồn: <?= wa_e($hit['domain']) ?></span>
        <span><span class="ms ms-14">schedule</span>Lưu lúc: <?= wa_e($hit['saved_at']) ?></span>
        <span><span class="ms ms-14">image</span><?= (int) $hit['image_count'] ?> ảnh</span>
        <a style="margin-left:auto;font-weight:600" href="<?= wa_e(wa_url('library.php?slug=' . urlencode((string) $hit['library_slug']))) ?>">
          Mở thư viện →
        </a>
        <a style="font-weight:600" href="<?= wa_e(wa_url('reader.php?id=' . (int) $hit['id'])) ?>">
          Đọc bản đã lưu →
        </a>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (($hasQuery || $hasFilter) && $results === []): ?>
    <div class="empty">
      <span class="ms ms-32">search_off</span>
      <h3>Không tìm thấy kết quả nào</h3>
      <p>
        <?php if ($hasQuery): ?>
          Không có bản snapshot nào chứa <b>tất cả</b> các từ trong
          “<strong><?= wa_e($query) ?></strong>” mà bạn được phép xem.
        <?php else: ?>
          Không có bản snapshot nào khớp với bộ lọc mà bạn đang chọn.
        <?php endif; ?>
        Hãy thử một từ khoá khác, bỏ bớt từ (vì nhiều từ là điều kiện <b>và</b>),
        hoặc xoá bớt điều kiện lọc.
      </p>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../templates/footer_app.php'; ?>