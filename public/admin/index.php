<?php
/**
 * admin/index.php — Tổng quan hệ thống (chỉ tài khoản Quản trị viên).
 * -----------------------------------------------------------------------------
 * P-FIX-ADMIN — Dashboard đáp ứng đủ 10 mục mà đề tài liệt kê:
 *   1. Tổng số người dùng.
 *   2. Tổng thư viện.
 *   3. Tổng bài đã lưu.
 *   4. Tổng snapshot.
 *   5. Yêu cầu duyệt đang chờ.
 *   6. Lỗi lưu URL.
 *   7. Lỗi cập nhật CN5.
 *   8. Danh sách domain đã duyệt.
 *   9. Quản lý người dùng và phân quyền.
 *  10. Nhật ký thao tác Admin.
 *
 * Mọi con số đều đo từ CSDL (không có số bịa), và mọi nút trên trang đều trỏ
 * tới một trang có chức năng thật — không còn nút "trang trí".
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
// P-FIX-UI (6): số liệu thật từ CSDL thay cho các con số bịa đặt trong mock.
require_once __DIR__ . '/../../templates/wa_metrics.php';

require_admin();

$stats       = wa_admin_stats();
$users       = wa_users();
$activity    = wa_recent_activity(6);
$allowlist   = wa_allowlist();
$errorStats  = wa_admin_error_breakdown();
$pendingList = wa_domain_requests('pending');
$adminLogs   = wa_admin_logs(8);

$activeAllowlist = 0;
foreach ($allowlist as $entry) {
    if ($entry['status'] === 'active') {
        $activeAllowlist++;
    }
}

$allLibraries = wa_all_libraries();
$allMetrics   = wa_metrics_for_libraries(array_column($allLibraries, 'id'));

$pageTitle = 'Tổng quan hệ thống';
require __DIR__ . '/../../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:660px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Quản trị hệ thống / Tổng quan</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Số liệu đo trực tiếp từ CSDL</span>
      </div>
      <h1 class="ribbon__title">Tổng quan hệ thống</h1>
      <p class="ribbon__sub">
        Theo dõi toàn bộ hoạt động lưu trữ, tài khoản, yêu cầu duyệt tên miền và
        các lỗi của hệ thống.
      </p>
    </div>

    <div class="row" style="flex-shrink:0">
      <a class="btn btn--outline" href="<?= wa_e(wa_url('admin/allowlist.php')) ?>">
        <span class="ms ms-18">verified_user</span>
        <span>Duyệt tên miền<?= (int) $stats['pending_requests'] > 0 ? ' (' . (int) $stats['pending_requests'] . ')' : '' ?></span>
      </a>
      <a class="btn btn--dark" href="<?= wa_e(wa_url('admin/users.php')) ?>">
        <span class="ms ms-18">group</span>
        <span>Quản lý người dùng</span>
      </a>
    </div>
  </div>
</div>

<!-- ===================== 1-4: SỐ LIỆU CHÍNH ===================== -->
<div class="stats-row" style="margin-top:0">
  <div class="stats-grid">
    <!-- 1) Tổng người dùng -->
    <div class="stat">
      <span class="stat__icon"><span class="ms ms-18">group</span></span>
      <span>
        <span class="stat__value"><?= (int) $stats['user_count'] ?> Người dùng</span>
        <span class="stat__note"><?= (int) $stats['admin_count'] ?> quản trị · <?= (int) $stats['locked_count'] ?> bị khoá</span>
      </span>
    </div>

    <!-- 2) Tổng thư viện -->
    <div class="stat">
      <span class="stat__icon stat__icon--blue"><span class="ms ms-18">folder_special</span></span>
      <span>
        <span class="stat__value"><?= (int) $stats['library_count'] ?> Thư viện</span>
        <span class="stat__note"><?= (int) $stats['shared_count'] ?> chia sẻ · <?= (int) $stats['personal_count'] ?> riêng tư</span>
      </span>
    </div>

    <!-- 3) Tổng bài đã lưu -->
    <div class="stat">
      <span class="stat__icon stat__icon--teal"><span class="ms ms-18">article</span></span>
      <span>
        <span class="stat__value"><?= (int) $stats['page_count'] ?> Bài đã lưu</span>
        <span class="stat__note"><?= (int) $stats['multi_version_pages'] ?> bài có nhiều phiên bản</span>
      </span>
    </div>

    <!-- 4) Tổng snapshot -->
    <div class="stat">
      <span class="stat__icon stat__icon--mint"><span class="ms ms-18">archive</span></span>
      <span>
        <span class="stat__value"><?= (int) $stats['snapshot_count'] ?> Snapshot</span>
        <span class="stat__note"><?= $allMetrics['snapshots_ok'] ?>/<?= $allMetrics['snapshots'] ?> lưu thành công</span>
      </span>
    </div>
  </div>
</div>

<!-- ===================== 5-7: YÊU CẦU DUYỆT & LỖI ===================== -->
<div class="stat-cards" style="margin-top:24px">
  <!-- 5) Yêu cầu duyệt đang chờ -->
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Yêu cầu duyệt đang chờ</span>
      <span class="ms ms-18" style="color:var(--wa-action)">pending_actions</span>
    </div>
    <div class="stat-card__value">
      <b><?= (int) $stats['pending_requests'] ?></b><span>yêu cầu</span>
    </div>
    <div class="stat-card__note">
      <?= (int) $stats['approved_requests'] ?> đã duyệt · <?= (int) $stats['rejected_requests'] ?> bị từ chối
    </div>
    <?php if ($pendingList !== []): ?>
      <div class="stack--sm" style="margin-top:12px">
        <?php foreach (array_slice($pendingList, 0, 3) as $req): ?>
          <div class="row row--between" style="gap:8px">
            <span class="t-body-md" style="font-weight:500"><?= wa_e((string) $req['domain']) ?></span>
            <span class="t-label-sm t-muted"><?= wa_e((string) $req['requested_by_name']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <a class="btn btn--outline btn--sm btn--block" style="margin-top:12px"
         href="<?= wa_e(wa_url('admin/allowlist.php')) ?>">
        <span class="ms ms-16">checklist</span> Xử lý yêu cầu
      </a>
    <?php else: ?>
      <div class="stat-card__note" style="color:var(--wa-success)">
        <span class="dot dot--live"></span> Không còn yêu cầu nào chờ
      </div>
    <?php endif; ?>
  </div>

  <!-- 6) Lỗi lưu URL -->
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Lỗi lưu URL</span>
      <span class="ms ms-18" style="color:var(--wa-error)">report</span>
    </div>
    <div class="stat-card__value">
      <b><?= (int) $stats['save_error_count'] ?></b><span>lần thất bại</span>
    </div>
    <div class="stat-card__note">Toàn bộ lần lưu không thành công trên toàn hệ thống</div>
    <?php if ($errorStats !== []): ?>
      <div class="stack--sm" style="margin-top:12px">
        <?php foreach (array_slice($errorStats, 0, 4) as $err): ?>
          <div class="row row--between" style="gap:8px">
            <span class="t-body-md"><?= wa_e((string) $err['label']) ?></span>
            <span class="chip chip--plain"><?= (int) $err['count'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- 7) Lỗi cập nhật CN5 -->
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Lỗi cập nhật phiên bản</span>
      <span class="ms ms-18" style="color:var(--wa-warning)">update</span>
    </div>
    <div class="stat-card__value">
      <b><?= (int) $stats['refetch_error_count'] ?></b><span>lần lỗi</span>
    </div>
    <div class="stat-card__note">
      Lần "Lấy phiên bản mới" không thành công — bản lưu cũ vẫn được giữ nguyên
    </div>
  </div>

  <!-- 8) Danh sách domain đã duyệt -->
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Tên miền đã duyệt</span>
      <span class="ms ms-18" style="color:var(--wa-teal-deep)">verified_user</span>
    </div>
    <div class="stat-card__value">
      <b><?= $activeAllowlist ?></b><span>đang hoạt động</span>
    </div>
    <div class="stat-card__note">Tổng <?= count($allowlist) ?> mục trong danh sách</div>
    <a class="btn btn--outline btn--sm btn--block" style="margin-top:12px"
       href="<?= wa_e(wa_url('admin/allowlist.php')) ?>">
      <span class="ms ms-16">manage_search</span> Quản lý danh sách
    </a>
  </div>

  <!-- Tài nguyên cục bộ -->
  <div class="stat-card">
    <div class="stat-card__top">
      <span class="stat-card__label">Tài nguyên cục bộ</span>
      <span class="ms ms-18" style="color:var(--wa-action)">photo_library</span>
    </div>
    <div class="stat-card__value">
      <b><?= (int) $stats['asset_count'] ?></b><span>tập tin</span>
    </div>
    <div class="stat-card__note">Dung lượng <?= wa_e((string) $stats['storage_label']) ?> tổng cộng</div>
  </div>
</div>

<!-- ===================== 9-10: NGƯỜI DÙNG + NHẬT KÝ ===================== -->
<div class="two-col" style="margin-top:24px">
  <div class="two-col__main panel">
    <div class="panel__head">
      <span class="panel__label">
        <span class="ms ms-16">history</span>
        Nhật ký thao tác quản trị
      </span>
      <a class="t-label-sm t-accent" href="<?= wa_e(wa_url('admin/allowlist.php')) ?>" style="font-weight:500">
        Xem nhật ký đầy đủ
      </a>
    </div>

    <div class="activity">
      <?php if ($adminLogs === []): ?>
        <div class="activity__item">
          <span class="activity__icon"><span class="ms ms-16">info</span></span>
          <div>
            <p class="activity__text">Chưa có thao tác quản trị nào được ghi nhận.</p>
            <p class="activity__time">Nhật ký ghi lại mỗi lần duyệt, từ chối, gỡ tên miền
              và thay đổi quyền người dùng.</p>
          </div>
        </div>
      <?php endif; ?>
      <?php foreach ($adminLogs as $log): ?>
        <div class="activity__item">
          <span class="activity__icon"><span class="ms ms-16">receipt_long</span></span>
          <div>
            <p class="activity__text">
              <?= wa_e((string) $log['action_label']) ?>
              <?php if (!empty($log['target_id'])): ?>
                — <b><?= wa_e((string) $log['target_id']) ?></b>
              <?php endif; ?>
            </p>
            <p class="activity__time">
              <?= wa_e((string) $log['created_label']) ?> • <?= wa_e((string) $log['actor_name']) ?>
            </p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="two-col__side panel">
    <div class="panel__head">
      <span class="panel__label">
        <span class="ms ms-16">group</span>
        Quản lý người dùng
      </span>
      <a class="t-label-sm t-accent" href="<?= wa_e(wa_url('admin/users.php')) ?>" style="font-weight:500">Xem tất cả</a>
    </div>

    <div class="stack--sm">
      <?php foreach (array_slice($users, 0, 6) as $row): ?>
        <div class="row row--between" style="gap:8px">
          <span class="row" style="gap:8px">
            <span class="avatar" aria-hidden="true" style="width:28px;height:28px;font-size:12px">
              <?= wa_e((string) ($row['initials'] ?? '?')) ?>
            </span>
            <span>
              <span class="t-body-md" style="font-weight:500;display:block"><?= wa_e((string) $row['name']) ?></span>
              <span class="t-label-sm t-muted"><?= wa_e((string) ($row['email'] ?? '')) ?></span>
            </span>
          </span>
          <span class="chip <?= ($row['role'] ?? '') === 'admin' ? 'chip--blue' : 'chip--plain' ?>">
            <?= wa_e((string) ($row['role_label'] ?? 'Thành viên')) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

    <a class="btn btn--outline btn--sm btn--block" style="margin-top:16px"
       href="<?= wa_e(wa_url('admin/users.php')) ?>">
      <span class="ms ms-16">manage_accounts</span> Phân quyền người dùng
    </a>

    <div class="note-box" style="margin-top:16px">
      <b>Lưu ý:</b> mọi yêu cầu lưu trang — kể cả sau khi chuyển hướng — đều được kiểm tra lại với
      danh sách tên miền đã duyệt trước khi tải về.
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../templates/footer_app.php'; ?>
