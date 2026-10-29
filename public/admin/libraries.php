<?php
/**
 * admin/libraries.php — Toàn bộ thư viện trên hệ thống (chỉ tài khoản Quản trị viên).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
// P-FIX-UI (6): số liệu thật từ CSDL thay cho các con số bịa đặt trong mock.
require_once __DIR__ . '/../../templates/wa_metrics.php';

require_admin();

// Toàn bộ thư viện trên hệ thống; tên chủ sở hữu đã được JOIN sẵn trong truy vấn.
$libraries = wa_all_libraries();

$allMetrics = wa_metrics_for_libraries(array_column($libraries, 'id'));

$personalCount = count(array_filter($libraries, static fn (array $l): bool => $l['visibility_code'] === 'private'));
$sharedCount   = count($libraries) - $personalCount;

$pageTitle = 'Tất cả thư viện';
require __DIR__ . '/../../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Quản trị hệ thống / Thư viện</span>
      </div>
      <h1 class="ribbon__title">Tất cả thư viện</h1>
      <p class="ribbon__sub">Toàn bộ thư viện của mọi tài khoản, chủ sở hữu và mức độ hiển thị.</p>
    </div>

    <div class="row" style="flex-shrink:0">
      <a class="btn btn--outline" href="<?= wa_e(wa_url('admin/users.php')) ?>">
        <span class="ms ms-18">group</span>
        <span>Quản lý người dùng</span>
      </a>
    </div>
  </div>
</div>

<div class="stats-row" style="margin-top:0">
  <div class="stats-grid">
    <div class="stat">
      <span class="stat__icon"><span class="ms ms-18">folder_special</span></span>
      <span>
        <span class="stat__value"><?= count($libraries) ?> Thư viện</span>
        <span class="stat__note">Trên toàn hệ thống</span>
      </span>
    </div>
    <div class="stat">
      <span class="stat__icon stat__icon--blue"><span class="ms ms-18">article</span></span>
      <span>
        <span class="stat__value"><?= $allMetrics['pages'] ?> Bài viết</span>
        <span class="stat__note"><?= $allMetrics['pages_ok'] ?> có bản lưu thành công</span>
      </span>
    </div>
    <div class="stat">
      <span class="stat__icon stat__icon--teal"><span class="ms ms-18">perm_media</span></span>
      <span>
        <span class="stat__value"><?= $allMetrics['image_total'] ?> Ảnh cục bộ</span>
        <span class="stat__note">Dung lượng <?= wa_e(wa_format_mb($allMetrics['bytes_total'])) ?></span>
      </span>
    </div>
    <div class="stat">
      <span class="stat__icon stat__icon--mint"><span class="ms ms-18">lock_open</span></span>
      <span>
        <span class="stat__value"><?= $sharedCount ?> Được chia sẻ</span>
        <span class="stat__note"><?= $personalCount ?> Chỉ mình tôi</span>
      </span>
    </div>
  </div>
</div>

<!-- ===================== LỌC + TÌM KIẾM (thật, dựa trên GET) ===================== -->
<?php
// P-FIX-UI: các tab lọc và ô tìm kiếm trước đây là nút "trang trí" (bấm không
// làm gì). Nay chúng lọc thật bằng tham số GET và được render lại từ CSDL.
$filter = (string) ($_GET['filter'] ?? 'all');
$search = trim((string) ($_GET['q'] ?? ''));

if (!in_array($filter, ['all', 'private', 'shared'], true)) {
    $filter = 'all';
}

$filteredLibraries = array_values(array_filter(
    $allLibraries,
    static function (array $lib) use ($filter, $search): bool {
        if ($filter === 'private' && $lib['visibility_code'] !== 'private') {
            return false;
        }

        if ($filter === 'shared' && $lib['visibility_code'] !== 'shared') {
            return false;
        }

        if ($search !== '') {
            $needle = mb_strtolower($search, 'UTF-8');
            $haystack = mb_strtolower(
                (string) $lib['name'] . ' ' . (string) $lib['owner_name'] . ' ' . (string) $lib['slug'],
                'UTF-8'
            );

            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }
));
?>
<div class="toolbar" style="margin-top:24px">
  <div class="tabs" role="tablist" aria-label="Lọc thư viện">
    <a class="<?= $filter === 'all' ? 'is-active' : '' ?>"
       href="<?= wa_e(wa_url('admin/libraries.php?filter=all&q=' . urlencode($search))) ?>">
      Tất cả (<?= count($allLibraries) ?>)
    </a>
    <a class="<?= $filter === 'private' ? 'is-active' : '' ?>"
       href="<?= wa_e(wa_url('admin/libraries.php?filter=private&q=' . urlencode($search))) ?>">
      Cá nhân (<?= $personalCount ?>)
    </a>
    <a class="<?= $filter === 'shared' ? 'is-active' : '' ?>"
       href="<?= wa_e(wa_url('admin/libraries.php?filter=shared&q=' . urlencode($search))) ?>">
      Được chia sẻ (<?= $sharedCount ?>)
    </a>
  </div>

  <div class="toolbar__right">
    <form class="searchbox" method="get" action="<?= wa_e(wa_url('admin/libraries.php')) ?>">
      <span class="ms">search</span>
      <input class="input input--flush input--sm" type="search" name="q"
             value="<?= wa_e($search) ?>" placeholder="Tìm thư viện..." aria-label="Tìm thư viện">
      <input type="hidden" name="filter" value="<?= wa_e($filter) ?>">
    </form>
  </div>
</div>

<?php if ($filteredLibraries === []): ?>
  <div class="empty" style="padding:36px 0">
    <span class="ms ms-32">search_off</span>
    <h3>Không có thư viện nào khớp</h3>
    <p>Thử xoá từ khoá tìm kiếm hoặc chọn bộ lọc khác.</p>
  </div>
<?php else: ?>
<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Tên thư viện</th>
        <th>Chủ sở hữu</th>
        <th>Hiển thị</th>
        <th>Bài viết</th>
        <th>Ảnh cục bộ</th>
        <th>Dung lượng</th>
        <th>Thành viên</th>
        <th>Thao tác</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($filteredLibraries as $lib): ?>
        <tr>
          <td>
            <span class="row" style="gap:8px">
              <span class="lib-card__icon lib-card__icon--<?= wa_e($lib['strip']) ?>" style="width:32px;height:32px;flex:0 0 32px;border-radius:8px">
                <span class="ms ms-18"><?= wa_e($lib['icon']) ?></span>
              </span>
              <span>
                <span class="cell-main" style="display:block"><?= wa_e($lib['name']) ?></span>
                <span class="cell-sub">Mã #<?= wa_e((string) $lib['id']) ?></span>
              </span>
            </span>
          </td>
          <td class="t-muted"><?= wa_e((string) $lib['owner_name']) ?></td>
          <td>
            <span class="chip <?= $lib['visibility_code'] === 'shared' ? 'chip--blue' : 'chip--plain' ?>">
              <?= wa_e($lib['visibility_label']) ?>
            </span>
          </td>
          <td><?= $lib['article_count'] ?></td>
          <td><?= $lib['image_count'] ?></td>
          <td class="t-muted"><?= wa_e($lib['storage_label']) ?></td>
          <td><?= $lib['member_count'] ?></td>
          <td>
            <div class="table__actions">
              <?php /* Mở thư viện để xem/sửa nội dung. */ ?>
              <a class="btn btn--outline btn--sm" href="<?= wa_e(wa_url('library.php?slug=' . urlencode($lib['slug']))) ?>"
                 title="Mở thư viện" aria-label="Mở thư viện">
                <span class="ms ms-16">open_in_new</span>
              </a>

              <?php /* Sửa tên / mô tả / mức hiển thị — nút THẬT (POST + CSRF). */ ?>
              <form method="post" action="<?= wa_e(wa_url('library_action.php')) ?>" style="display:inline">
                <?= wa_csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="library_id" value="<?= (int) $lib['id'] ?>">
                <input type="hidden" name="name" value="<?= wa_e((string) $lib['name']) ?>">
                <input type="hidden" name="description" value="<?= wa_e((string) ($lib['description'] ?? '')) ?>">
                <input type="hidden" name="visibility" value="<?= wa_e((string) $lib['visibility_code']) ?>">
                <button class="btn btn--outline btn--sm" type="submit"
                        title="Đổi tên / mức hiển thị của thư viện">
                  <span class="ms ms-16">edit</span>
                </button>
              </form>

              <?php /* Xoá thư viện — nút THẬT, có bước xác nhận confirm=1. */ ?>
              <form method="post" action="<?= wa_e(wa_url('library_action.php')) ?>" style="display:inline"
                    onsubmit="return confirm('Xoá thư viện “<?= wa_e((string) $lib['name']) ?>”? Toàn bộ bài viết và ảnh trong thư viện này sẽ bị xoá. Bạn không thể hoàn tác.');">
                <?= wa_csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="library_id" value="<?= (int) $lib['id'] ?>">
                <input type="hidden" name="confirm" value="1">
                <button class="btn btn--outline btn--sm" type="submit"
                        title="Xoá thư viện (kèm bài viết và ảnh)">
                  <span class="ms ms-16">delete</span>
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../templates/footer_app.php'; ?>
