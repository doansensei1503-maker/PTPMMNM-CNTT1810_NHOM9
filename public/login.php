<?php
/**
 * login.php — Trang đăng nhập.
 *
 * Giao diện: thẻ xác thực độc lập đặt trên nền workspace canvas, có panel
 * thương hiệu bên trái và biểu mẫu bên phải (theo hệ thiết kế WebArchive).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

auth_start_session();

// Đã đăng nhập thì đưa thẳng vào workspace.
if (current_user() !== null) {
    header('Location: ' . wa_url('libraries.php'));
    exit;
}

$errors = [];
$email = '';
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $next = (string) ($_POST['next'] ?? $next);

    if ($email === '') {
        $errors['email'] = 'Vui lòng nhập email của bạn.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Email không đúng định dạng. Ví dụ: ban@example.com';
    }

    if ($password === '') {
        $errors['password'] = 'Vui lòng nhập mật khẩu.';
    }

    if ($errors === []) {
        $result = login_attempt($email, $password);
        if ($result['ok']) {
            $target = $next !== '' ? $next : wa_url('libraries.php');
            // Chỉ cho chuyển hướng nội bộ.
            if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
                $target = wa_url('libraries.php');
            }
            header('Location: ' . $target);
            exit;
        }
        $errors['form'] = $result['message'];
    }
}

$pageTitle = 'Đăng nhập — WebArchive';
$bodyClass = 'landing';
// P-FIX-UI (1): trang đăng nhập vẫn dùng layout KHÁCH, nên thanh trên (chứa
// "Đăng nhập" + "Đăng ký") hiện cùng với trang chủ. Trước đây $hideTopbar = true
// khiến nút "Đăng ký" biến mất khỏi mọi trang khách ngoài trang chủ.
$hideFooter = true;

require __DIR__ . '/../templates/header_guest.php';
?>

<div class="auth">
  <div class="auth__card">
    <!-- Panel thương hiệu -->
    <div class="auth__panel">
      <div class="auth__brand">
        <img class="brand__mark" src="<?= wa_e(wa_asset('img/logo-mark.svg')) ?>" alt="Biểu trưng WebArchive" width="32" height="32">
        <span class="brand__text">
          <span class="brand__name">WebArchive</span>
          <span class="brand__tag">Thư viện Web cá nhân</span>
        </span>
      </div>

      <div>
        <h2>Chào mừng trở lại</h2>
        <p>Đăng nhập để tiếp tục sắp xếp kho tài liệu và đọc lại những bài viết đã lưu ngoại tuyến.</p>
      </div>

      <ul class="auth__list">
        <li><span class="ms ms-16 ms-fill">check_circle</span><span>Đọc lại nội dung đã lưu ngay cả khi trang gốc không còn hoạt động.</span></li>
        <li><span class="ms ms-16 ms-fill">check_circle</span><span>Tổ chức thư viện theo chủ đề, gắn nhãn và phân quyền thành viên.</span></li>
        <li><span class="ms ms-16 ms-fill">check_circle</span><span>Tìm kiếm trên nội dung đã lưu, luôn trả về đúng phiên bản snapshot.</span></li>
      </ul>
    </div>

    <!-- Biểu mẫu -->
    <div class="auth__form">
      <div>
        <h1>Đăng nhập</h1>
        <p class="t-body-md t-muted" style="margin-top:4px">Nhập thông tin tài khoản của bạn để tiếp tục.</p>
      </div>

      <?php if (isset($errors['form'])): ?>
        <div class="form-alert" role="alert">
          <span class="ms ms-18">error_outline</span>
          <span><?= wa_e($errors['form']) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= wa_e(wa_url('login.php')) ?>" novalidate>
        <input type="hidden" name="next" value="<?= wa_e($next) ?>">

        <div class="stack">
          <div class="field">
            <label for="email">Email <span class="t-error">*</span></label>
            <input class="input" type="email" id="email" name="email" value="<?= wa_e($email) ?>"
                   placeholder="ban@example.com" autocomplete="username" required>
            <?php if (isset($errors['email'])): ?>
              <span class="field-error"><span class="ms ms-14">error</span><?= wa_e($errors['email']) ?></span>
            <?php else: ?>
              <span class="field-hint">Sử dụng email bạn đã đăng ký.</span>
            <?php endif; ?>
          </div>

          <div class="field">
            <label for="password">Mật khẩu <span class="t-error">*</span></label>
            <input class="input" type="password" id="password" name="password"
                   placeholder="••••••••" autocomplete="current-password" required>
            <?php if (isset($errors['password'])): ?>
              <span class="field-error"><span class="ms ms-14">error</span><?= wa_e($errors['password']) ?></span>
            <?php else: ?>
              <span class="field-hint">Mật khẩu gồm tối thiểu 6 ký tự.</span>
            <?php endif; ?>
          </div>

          <button class="btn btn--primary btn--block" type="submit">
            <span class="ms ms-18">login</span>
            <span>Đăng nhập</span>
          </button>
        </div>
      </form>

      <div class="auth__links">
        <span class="t-body-sm t-muted">Chưa có tài khoản?</span>
        <a class="t-body-sm" style="font-weight:600" href="<?= wa_e(wa_url('register.php')) ?>">Tạo tài khoản miễn phí</a>
      </div>

      <div class="auth__demo">
        <b>Tài khoản demo:</b>
        <div style="margin-top:6px">Người dùng: <code>user@demo.vn</code> / <code>demo123</code></div>
        <div>Quản trị viên: <code>admin@demo.vn</code> / <code>admin123</code></div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../templates/footer_guest.php'; ?>
