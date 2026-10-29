<?php
/**
 * admin/users.php — Bảng người dùng (chỉ tài khoản Quản trị viên).
 * -----------------------------------------------------------------------------
 * P-FIX-ADMIN — Trước đây trang này CHỈ CÓ GIAO DIỆN: các nút "Sửa tài khoản",
 * "Khoá", "Thao tác khác" và hộp thoại "Mời người dùng" đều không làm gì (chính
 * trang cũ cũng ghi rõ: "các thao tác trên bảng chỉ hiển thị, chưa thực hiện
 * thay đổi trên cơ sở dữ liệu").
 *
 * Nay MỌI nút đều có hành động thật, đi qua POST + CSRF, và được ghi vào
 * `admin_logs`:
 *   - Thêm tài khoản mới (mời người dùng).
 *   - Đổi vai trò (Người dùng <-> Quản trị viên).
 *   - Khoá / mở khoá tài khoản.
 *   - Lọc theo vai trò / trạng thái và tìm theo tên hoặc email.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

require_admin();

$user   = current_user();
$userId = (int) ($user['id'] ?? 0);

$notice = null;

/* ---------------------------------------------------------------------------
 * XỬ LÝ POST — mọi hành động thay đổi dữ liệu.
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wa_csrf_check()) {
        $notice = [
            'ok'   => false,
            'text' => 'Yêu cầu không hợp lệ hoặc phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.',
        ];
    } else {
        $action = (string) ($_POST['action'] ?? '');

        switch ($action) {
            /* --- Tạo tài khoản mới ---------------------------------------- */
            case 'create':
                $result = wa_admin_create_user(
                    (string) ($_POST['full_name'] ?? ''),
                    (string) ($_POST['email'] ?? ''),
                    (string) ($_POST['password'] ?? ''),
                    (string) ($_POST['role'] ?? 'user'),
                    $userId
                );

                $notice = ['ok' => $result['ok'], 'text' => $result['message']];
                break;

            /* --- Đổi vai trò ----------------------------------------------- */
            case 'set_role':
                $result = wa_admin_set_user_role(
                    (int) ($_POST['user_id'] ?? 0),
                    (string) ($_POST['role'] ?? 'user'),
                    $userId
                );

                $notice = ['ok' => $result['ok'], 'text' => $result['message']];
                break;

            /* --- Khoá / mở khoá -------------------------------------------- */
            case 'set_status':
                $result = wa_admin_set_user_status(
                    (int) ($_POST['user_id'] ?? 0),
                    (string) ($_POST['status'] ?? 'active'),
                    $userId
                );

                $notice = ['ok' => $result['ok'], 'text' => $result['message']];
                break;

            /* --- Xoá tài khoản -------------------------------------------- */
            case 'delete':
                $result = wa_admin_delete_user(
                    (int) ($_POST['user_id'] ?? 0),
                    $userId
                );

                $notice = ['ok' => $result['ok'], 'text' => $result['message']];
                break;

            default:
                $notice = ['ok' => false, 'text' => 'Hành động không hợp lệ.'];
        }
    }
}

/* ---------------------------------------------------------------------------
 * Dữ liệu + lọc (thật, dựa trên tham số GET).
 * ------------------------------------------------------------------------- */
$users = wa_users();

$filter = (string) ($_GET['filter'] ?? 'all');
$search = trim((string) ($_GET['q'] ?? ''));

if (!in_array($filter, ['all', 'admin', 'user', 'locked'], true)) {
    $filter = 'all';
}

$filtered = array_values(array_filter(
    $users,
    static function (array $row) use ($filter, $search): bool {
        if ($filter === 'admin' && $row['role'] !== 'admin') {
            return false;
        }

        if ($filter === 'user' && $row['role'] !== 'user') {
            return false;
        }

        if ($filter === 'locked' && $row['status'] !== 'locked') {
            return false;
        }

        if ($search !== '') {
            $needle = mb_strtolower($search, 'UTF-8');
            $haystack = mb_strtolower((string) $row['name'] . ' ' . (string) $row['email'], 'UTF-8');

            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }
));

$adminCount  = count(array_filter($users, static fn (array $u): bool => $u['role'] === 'admin'));
$plainCount  = count(array_filter($users, static fn (array $u): bool => $u['role'] !== 'admin'));
$lockedCount = count(array_filter($users, static fn (array $u): bool => $u['status'] === 'locked'));

$pageTitle = 'Quản lý người dùng';
require __DIR__ . '/../../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:640px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Quản trị hệ thống / Người dùng</span>
      </div>
      <h1 class="ribbon__title">Quản lý người dùng</h1>
      <p class="ribbon__sub">Danh sách tài khoản, vai trò và trạng thái hoạt động trên hệ thống.</p>
    </div>

    <div class="row" style="flex-shrink:0">
      <button class="btn btn--dark" type="button" data-modal-open="modal-invite">
        <span class="ms ms-18">person_add</span>
        <span>Thêm người dùng</span>
      </button>
    </div>
  </div>
</div>

<?php if ($notice !== null): ?>
  <div class="form-alert <?= $notice['ok'] ? 'form-alert--ok' : '' ?>" style="margin-bottom:20px" role="status">
    <span class="ms ms-18"><?= $notice['ok'] ? 'check_circle' : 'error_outline' ?></span>
    <span><?= wa_e((string) $notice['text']) ?></span>
  </div>
<?php endif; ?>

<div class="toolbar">
  <div class="tabs" role="tablist" aria-label="Lọc người dùng">
    <a class="<?= $filter === 'all' ? 'is-active' : '' ?>"
       href="<?= wa_e(wa_url('admin/users.php?filter=all&q=' . urlencode($search))) ?>">
      Tất cả (<?= count($users) ?>)
    </a>
    <a class="<?= $filter === 'admin' ? 'is-active' : '' ?>"
       href="<?= wa_e(wa_url('admin/users.php?filter=admin&q=' . urlencode($search))) ?>">
      Quản trị viên (<?= $adminCount ?>)
    </a>
    <a class="<?= $filter === 'user' ? 'is-active' : '' ?>"
       href="<?= wa_e(wa_url('admin/users.php?filter=user&q=' . urlencode($search))) ?>">
      Người dùng (<?= $plainCount ?>)
    </a>
    <a class="<?= $filter === 'locked' ? 'is-active' : '' ?>"
       href="<?= wa_e(wa_url('admin/users.php?filter=locked&q=' . urlencode($search))) ?>">
      Bị khóa (<?= $lockedCount ?>)
    </a>
  </div>

  <div class="toolbar__right">
    <form class="searchbox" method="get" action="<?= wa_e(wa_url('admin/users.php')) ?>">
      <span class="ms">search</span>
      <input class="input input--flush input--sm" type="search" name="q"
             value="<?= wa_e($search) ?>" placeholder="Tìm theo tên hoặc email..." aria-label="Tìm người dùng">
      <input type="hidden" name="filter" value="<?= wa_e($filter) ?>">
    </form>
  </div>
</div>

<?php if ($filtered === []): ?>
  <div class="empty" style="padding:36px 0">
    <span class="ms ms-32">person_search</span>
    <h3>Không có tài khoản nào khớp</h3>
    <p>Thử xoá từ khoá tìm kiếm hoặc chọn bộ lọc khác.</p>
  </div>
<?php else: ?>
<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Họ và tên</th>
        <th>Email</th>
        <th>Vai trò</th>
        <th>Trạng thái</th>
        <th>Số thư viện</th>
        <th>Ngày tham gia</th>
        <th style="width:190px">Thao tác</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($filtered as $row): ?>
        <tr>
          <td>
            <div class="row" style="gap:8px">
              <span class="avatar avatar--<?= $row['role'] === 'admin' ? 'alt' : 'light' ?>" style="width:28px;height:28px;flex:0 0 28px;font-size:11px">
                <?= wa_e(mb_strtoupper(mb_substr($row['name'], 0, 2))) ?>
              </span>
              <span class="cell-main"><?= wa_e($row['name']) ?></span>
              <?php if ((int) $row['id'] === $userId): ?>
                <span class="chip chip--plain">Bạn</span>
              <?php endif; ?>
            </div>
          </td>
          <td class="t-muted"><?= wa_e($row['email']) ?></td>
          <td>
            <span class="chip <?= $row['role'] === 'admin' ? 'chip--navy' : 'chip--plain' ?>">
              <?= wa_e($row['role_label']) ?>
            </span>
          </td>
          <td>
            <span class="chip <?= $row['status'] === 'active' ? 'chip--teal' : 'chip--error' ?>">
              <span class="dot" style="background:<?= $row['status'] === 'active' ? 'var(--wa-success)' : 'var(--wa-error)' ?>"></span>
              <?= wa_e($row['status_label']) ?>
            </span>
          </td>
          <td><?= (int) $row['libraries'] ?></td>
          <td class="t-muted"><?= wa_e($row['joined']) ?></td>
          <td>
            <div class="table__actions">
              <?php /* Đổi vai trò — nút thật, có CSRF. */ ?>
              <form method="post" action="<?= wa_e(wa_url('admin/users.php')) ?>" style="display:inline">
                <?= wa_csrf_field() ?>
                <input type="hidden" name="action" value="set_role">
                <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="role" value="<?= $row['role'] === 'admin' ? 'user' : 'admin' ?>">
                <button class="btn btn--outline btn--sm" type="submit"
                        title="<?= $row['role'] === 'admin' ? 'Hạ quyền về Người dùng' : 'Nâng quyền lên Quản trị viên' ?>">
                  <span class="ms ms-16"><?= $row['role'] === 'admin' ? 'person' : 'shield_person' ?></span>
                  <?= $row['role'] === 'admin' ? 'Hạ quyền' : 'Nâng quyền' ?>
                </button>
              </form>

              <?php /* Khoá / mở khoá — nút thật, có CSRF. */ ?>
              <form method="post" action="<?= wa_e(wa_url('admin/users.php')) ?>" style="display:inline">
                <?= wa_csrf_field() ?>
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="status" value="<?= $row['status'] === 'active' ? 'locked' : 'active' ?>">
                <button class="btn btn--outline btn--sm" type="submit"
                        title="<?= $row['status'] === 'active' ? 'Khoá tài khoản' : 'Mở khoá tài khoản' ?>">
                  <span class="ms ms-16"><?= $row['status'] === 'active' ? 'lock' : 'lock_open' ?></span>
                  <?= $row['status'] === 'active' ? 'Khoá' : 'Mở khoá' ?>
                </button>
              </form>

              <?php /* Xoá tài khoản — nút thật, có CSRF + xác nhận.
                     Không hiện với chính mình (không tự xoá tài khoản đang đăng nhập). */ ?>
              <?php if ((int) $row['id'] !== $userId): ?>
                <form method="post" action="<?= wa_e(wa_url('admin/users.php')) ?>" style="display:inline"
                      onsubmit="return confirm('Xoá tài khoản “<?= wa_e($row['name']) ?>”? Các bài viết của tài khoản này cũng sẽ bị xoá. Bạn không thể hoàn tác.');">
                  <?= wa_csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                  <button class="btn btn--outline btn--sm" type="submit"
                          title="Xoá tài khoản này (kèm bài viết của tài khoản)">
                    <span class="ms ms-16">delete</span>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ===================== HỘP THOẠI THÊM NGƯỜI DÙNG ===================== -->
<div class="modal-backdrop" id="modal-invite" hidden>
  <div class="modal">
    <div class="modal__head">
      <div class="row" style="gap:8px">
        <span class="ms ms-24" style="color:var(--wa-primary)">person_add</span>
        <h2 class="t-headline-sm" style="font-weight:700">Thêm người dùng mới</h2>
      </div>
      <button class="btn btn--ghost" type="button" data-modal-close aria-label="Đóng">
        <span class="ms ms-18">close</span>
      </button>
    </div>

    <form class="stack" method="post" action="<?= wa_e(wa_url('admin/users.php')) ?>">
      <?= wa_csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <div class="field">
        <label for="invite-name">Họ và tên <span class="t-error">*</span></label>
        <input class="input" id="invite-name" type="text" name="full_name" placeholder="Nguyễn Văn A" required>
      </div>
      <div class="field">
        <label for="invite-email">Email <span class="t-error">*</span></label>
        <input class="input" id="invite-email" type="email" name="email" placeholder="ban@example.com" required>
      </div>
      <div class="field">
        <label for="invite-password">Mật khẩu khởi tạo <span class="t-error">*</span></label>
        <input class="input" id="invite-password" type="text" name="password" minlength="8" required
               placeholder="Tối thiểu 8 ký tự">
        <p class="t-label-sm t-muted" style="margin-top:6px">
          Tài khoản sẽ đăng nhập được ngay với mật khẩu này.
        </p>
      </div>
      <div class="field">
        <label for="invite-role">Vai trò</label>
        <select class="select" id="invite-role" name="role">
          <option value="user">Người dùng</option>
          <option value="admin">Quản trị viên</option>
        </select>
      </div>
      <div class="row" style="justify-content:flex-end;gap:8px;padding-top:8px">
        <button class="btn btn--outline" type="button" data-modal-close>Hủy</button>
        <button class="btn btn--dark" type="submit">Tạo tài khoản</button>
      </div>
    </form>
  </div>
</div>

<script>
  var modal = document.getElementById('modal-invite');
  document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', function () { modal.removeAttribute('hidden'); });
  });
  document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', function () { modal.setAttribute('hidden', ''); });
  });
  modal.addEventListener('click', function (e) {
    if (e.target === modal) { modal.setAttribute('hidden', ''); }
  });
</script>

<?php require __DIR__ . '/../../templates/footer_app.php'; ?>
