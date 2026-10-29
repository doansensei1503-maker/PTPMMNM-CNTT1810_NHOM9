<?php
/**
 * register.php — Trang đăng ký tài khoản.
 *
 * Biểu mẫu ghi tài khoản THẬT vào bảng `users` của MySQL (vai trò 'user',
 * trạng thái 'active'). Mật khẩu được băm bằng password_hash() (bcrypt).
 * Email trùng sẽ báo lỗi tiếng Việt.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

auth_start_session();

if (current_user() !== null) {
    header('Location: ' . wa_url('libraries.php'));
    exit;
}

$errors = [];
$success = false;
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if ($name === '') {
        $errors['name'] = 'Vui lòng nhập họ và tên của bạn.';
    } elseif (mb_strlen($name) < 3) {
        $errors['name'] = 'Họ và tên phải có ít nhất 3 ký tự.';
    }

    if ($email === '') {
        $errors['email'] = 'Vui lòng nhập email của bạn.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Email không đúng định dạng. Ví dụ: ban@example.com';
    }

    if ($password === '') {
        $errors['password'] = 'Vui lòng nhặt mật khẩu.';
    } elseif (mb_strlen($password) < 6) {
        $errors['password'] = 'Mật khẩu phải có ít nhất 6 ký tự.';
    }

    if ($confirm === '') {
        $errors['confirm_password'] = 'Vui lòng xác nhận lại mật khẩu.';
    } elseif ($password !== $confirm) {
        $errors['confirm_password'] = 'Hai mật khẩu không khớp. Vui lòng nhập lại.';
    }

    if ($errors === []) {
        // Ghi tài khoản thật vào bảng `users`.
        $result = register_user($name, $email, $password);

        if ($result['ok']) {
            $success = true;
        } else {
            $errors['email'] = $result['message'];
        }
    }
}

$pageTitle = 'Tạo tài khoản — WebArchive';
$bodyClass = 'landing';
// P-FIX-UI (1): giữ thanh trên của layout khách để "Đăng nhập" / "Đăng ký"
// xuất hiện đồng nhất trên mọi trang khách.
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
        <h2>Bắt đầu kho tàng tri thức của bạn</h2>
        <p>Tạo tài khoản miễn phí để lưu trữ bài viết, sắp xếp thư viện và tra cứu nội dung đã lưu.</p>
      </div>

      <ul class="auth__list">
        <li><span class="ms ms-16 ms-fill">folder_special</span><span>Không giới hạn số thư viện và nhãn phân loại.</span></li>
        <li><span class="ms ms-16 ms-fill">group</span><span>Mời thành viên và phân quyền Chủ sở hữu, Biên tập viên, Người xem.</span></li>
        <li><span class="ms ms-16 ms-fill">verified_user</span><span>Chỉ lưu những URL thuộc danh sách tên miền được duyệt.</span></li>
      </ul>
    </div>

    <!-- Biểu mẫu -->
    <div class="auth__form">
      <div>
        <h1>Tạo tài khoản</h1>
        <p class="t-body-md t-muted" style="margin-top:4px">Điền đầy đủ thông tin bên dưới để đăng ký.</p>
      </div>

      <?php if ($success): ?>
        <div class="form-alert form-alert--ok" role="status">
          <span class="ms ms-18 ms-fill">check_circle</span>
          <span>
            Đăng ký thành công! Tài khoản <strong><?= wa_e($email) ?></strong> đã sẵn sàng.
            Hãy chuyển sang trang <a href="<?= wa_e(wa_url('login.php')) ?>">đăng nhập</a> để bắt đầu sử dụng.
          </span>
        </div>
        <div class="row" style="justify-content:center">
          <a class="btn btn--primary" href="<?= wa_e(wa_url('login.php')) ?>">
            <span class="ms ms-18">login</span>
            <span>Đi đến trang đăng nhập</span>
          </a>
        </div>
      <?php else: ?>
        <form method="post" action="<?= wa_e(wa_url('register.php')) ?>" novalidate>
          <div class="stack">
            <div class="field">
              <label for="name">Họ và tên <span class="t-error">*</span></label>
              <input class="input" type="text" id="name" name="name" value="<?= wa_e($name) ?>"
                     placeholder="Nguyễn Văn A" autocomplete="name" required>
              <?php if (isset($errors['name'])): ?>
                <span class="field-error"><span class="ms ms-14">error</span><?= wa_e($errors['name']) ?></span>
              <?php endif; ?>
            </div>

            <div class="field">
              <label for="email">Email <span class="t-error">*</span></label>
              <input class="input" type="email" id="email" name="email" value="<?= wa_e($email) ?>"
                     placeholder="ban@example.com" autocomplete="username" required>
              <?php if (isset($errors['email'])): ?>
                <span class="field-error"><span class="ms ms-14">error</span><?= wa_e($errors['email']) ?></span>
              <?php endif; ?>
            </div>

            <div class="field">
              <label for="password">Mật khẩu <span class="t-error">*</span></label>
              <input class="input" type="password" id="password" name="password"
                     placeholder="Tối thiểu 6 ký tự" autocomplete="new-password" required>
              <?php if (isset($errors['password'])): ?>
                <span class="field-error"><span class="ms ms-14">error</span><?= wa_e($errors['password']) ?></span>
              <?php endif; ?>
            </div>

            <div class="field">
              <label for="confirm_password">Xác nhận mật khẩu <span class="t-error">*</span></label>
              <input class="input" type="password" id="confirm_password" name="confirm_password"
                     placeholder="Nhập lại mật khẩu" autocomplete="new-password" required>
              <?php if (isset($errors['confirm_password'])): ?>
                <span class="field-error"><span class="ms ms-14">error</span><?= wa_e($errors['confirm_password']) ?></span>
              <?php endif; ?>
            </div>

            <button class="btn btn--primary btn--block" type="submit">
              <span class="ms ms-18">person_add</span>
              <span>Tạo tài khoản</span>
            </button>
          </div>
        </form>

        <div class="auth__links">
          <span class="t-body-sm t-muted">Đã có tài khoản?</span>
          <a class="t-body-sm" style="font-weight:600" href="<?= wa_e(wa_url('login.php')) ?>">Đăng nhập ngay</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../templates/footer_guest.php'; ?>
