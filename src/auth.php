<?php
/**
 * auth.php
 * -----------------------------------------------------------------------------
 * Lớp xác thực phiên (session-based) cho WebArchive.
 *
 * Tài khoản nằm trong bảng `users` của CSDL MySQL. Mật khẩu được băm bằng
 * bcrypt (password_hash) và kiểm tra bằng password_verify().
 * Phiên chỉ lưu ID người dùng — mọi thông tin khác được nạp lại từ CSDL.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Khởi động phiên làm việc (idempotent).
 */
function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (PHP_SAPI === 'cli') {
        // Khi chạy kiểm tra bằng dòng lệnh, không dùng cookie phiên.
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'path'     => '/',
    ]);
    session_start();
}

/**
 * Tài khoản đang đăng nhập, hoặc null nếu là khách.
 *
 * @return array<string, mixed>|null
 */
function current_user(): ?array
{
    auth_start_session();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $user = auth_find_user_by_id((int) $_SESSION['user_id']);

    if ($user === null) {
        return null;
    }

    // Tài khoản bị khoá trong CSDL thì phiên cũng mất hiệu lực.
    if ($user['status'] !== 'active') {
        return null;
    }

    return $user;
}

/**
 * Nạp một tài khoản từ bảng `users` theo id.
 *
 * @return array<string, mixed>|null
 */
function auth_find_user_by_id(int $id): ?array
{
    try {
        $stmt = db()->prepare(
            'SELECT id, full_name, email, password_hash, role, status, created_at
               FROM `users`
              WHERE id = ?
              LIMIT 1'
        );
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        db_fatal('Không đọc được dữ liệu tài khoản từ cơ sở dữ liệu.');
    }

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    return auth_shape_user($row);
}

/**
 * Nạp một tài khoản từ bảng `users` theo email (không phân biệt hoa thường).
 *
 * @return array<string, mixed>|null
 */
function auth_find_user_by_email(string $email): ?array
{
    $email = trim($email);

    if ($email === '') {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, full_name, email, password_hash, role, status, created_at
               FROM `users`
              WHERE email = ?
              LIMIT 1'
        );
        $stmt->execute([$email]);
    } catch (Throwable $e) {
        db_fatal('Không đọc được dữ liệu tài khoản từ cơ sở dữ liệu.');
    }

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    return auth_shape_user($row);
}

/**
 * Chuẩn hoá một bản ghi `users` thành mảng dùng trong giao diện.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function auth_shape_user(array $row): array
{
    $roleLabels   = ['admin' => 'Quản trị viên', 'user' => 'Người dùng'];
    $statusLabels = ['active' => 'Hoạt động', 'locked' => 'Bị khóa'];

    $fullName = (string) $row['full_name'];
    $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
    $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    $initials = count($parts) > 1
        ? mb_strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8') . mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8'), 'UTF-8')
        : mb_strtoupper(mb_substr($fullName, 0, 2, 'UTF-8'), 'UTF-8');

    return [
        'id'            => (int) $row['id'],
        'email'         => (string) $row['email'],
        'name'          => $fullName,
        'full_name'     => $fullName,
        'password_hash' => (string) $row['password_hash'],
        'role'          => (string) $row['role'],
        'role_label'    => $roleLabels[(string) $row['role']] ?? 'Người dùng',
        'status'        => (string) $row['status'],
        'status_label'  => $statusLabels[(string) $row['status']] ?? 'Hoạt động',
        'initials'      => $initials !== '' ? $initials : 'KH',
        'created_at'    => (string) $row['created_at'],
    ];
}

/**
 * Đăng nhập bằng email + mật khẩu.
 *
 * @return array{ok: bool, message: string}
 */
function login_attempt(string $email, string $password): array
{
    auth_start_session();

    $email = trim($email);

    if ($email === '' || $password === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập đầy đủ email và mật khẩu.'];
    }

    $user = auth_find_user_by_email($email);

    if ($user === null) {
        return ['ok' => false, 'message' => 'Không tìm thấy tài khoản với email này.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'message' => 'Mật khẩu không đúng. Vui lòng thử lại.'];
    }

    if ($user['status'] !== 'active') {
        return ['ok' => false, 'message' => 'Tài khoản của bạn đang bị khoá. Vui lòng liên hệ quản trị viên.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_role']  = $user['role'];

    return ['ok' => true, 'message' => 'Đăng nhập thành công.'];
}

/**
 * Đăng ký tài khoản mới — ghi thật vào bảng `users`.
 *
 * @return array{ok:bool, message:string, user_id?:int}
 */
function register_user(string $fullName, string $email, string $password): array
{
    $fullName = trim($fullName);
    $email    = trim($email);

    if ($fullName === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập họ và tên của bạn.'];
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Email không đúng định dạng. Ví dụ: ban@example.com'];
    }

    if ($password === '' || mb_strlen($password) < 6) {
        return ['ok' => false, 'message' => 'Mật khẩu phải có ít nhất 6 ký tự.'];
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `status`)
             VALUES (?, ?, ?, \'user\', \'active\')'
        );
        $stmt->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return [
                'ok'      => false,
                'message' => 'Email "' . $email . '" đã được đăng ký. Vui lòng đăng nhập bằng tài khoản này.',
            ];
        }

        throw $e;
    }

    return [
        'ok'      => true,
        'message' => 'Đăng ký thành công.',
        'user_id' => (int) db()->lastInsertId(),
    ];
}

/**
 * Đăng xuất — xoá toàn bộ dữ liệu phiên và quay về trang chủ.
 */
function logout(): void
{
    auth_start_session();

    $_SESSION = [];

    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }
}

/**
 * Bắt buộc đăng nhập — khách chuyển hướng sang login.php.
 */
function require_login(): void
{
    auth_start_session();

    if (current_user() === null) {
        $target = $_SERVER['REQUEST_URI'] ?? 'libraries.php';
        header('Location: login.php?next=' . rawurlencode($target));
        exit;
    }
}

/**
 * Bắt buộc quyền quản trị — người dùng thường nhận trang thông báo tiếng Việt.
 */
function require_admin(): void
{
    require_login();

    $user = current_user();
    if ($user === null || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        require __DIR__ . '/../templates/error_no_permission.php';
        exit;
    }
}

/**
 * Tên hiển thị của tài khoản đang đăng nhập (dùng trong header).
 */
function current_user_name(): string
{
    $user = current_user();
    return $user['name'] ?? 'Khách';
}

/**
 * Chữ viết tắt để hiển thị trong avatar tròn.
 */
function current_user_initials(): string
{
    $user = current_user();
    return $user['initials'] ?? 'KH';
}

/**
 * Vai trò hiển thị (Quản trị viên / Người dùng).
 */
function current_user_role_label(): string
{
    $user = current_user();
    return $user['role_label'] ?? 'Khách';
}

/**
 * Đường dẫn tương đối của trang hiện tại, dùng để đánh dấu menu đang hoạt động.
 */
function current_page(): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? '');
}

/* =============================================================================
    CSRF — TOKEN PHIÊN CHO CÁC BIỂU MẪU POST
    -----------------------------------------------------------------------------
    Mọi biểu mẫu POST mới (tải lại phiên bản, thêm/sửa/xoá ghi chú) phải kèm
    token. Token sinh một lần cho mỗi phiên, lưu trong $_SESSION và so khớp
    bằng hash_equals() nên không bị đoán dần từ phản hồi thời gian.
    -------------------------------------------------------------------------- */

/** Tên trường ẩn chứa token trong các biểu mẫu POST. */
const WA_CSRF_FIELD = 'wa_csrf';

/** Khoá lưu token trong phiên. */
const WA_CSRF_SESSION_KEY = 'wa_csrf_token';

/**
 * Lấy token CSRF của phiên, sinh mới nếu chưa có.
 */
function wa_csrf_token(): string
{
    auth_start_session();

    if (
        isset($_SESSION[WA_CSRF_SESSION_KEY])
        && is_string($_SESSION[WA_CSRF_SESSION_KEY])
        && $_SESSION[WA_CSRF_SESSION_KEY] !== ''
    ) {
        return $_SESSION[WA_CSRF_SESSION_KEY];
    }

    $token = bin2hex(random_bytes(32));
    $_SESSION[WA_CSRF_SESSION_KEY] = $token;

    return $token;
}

/**
 * Kiểm tra token CSRF gửi lên.
 *
 * So khớp bằng hash_equals() (hằng thời gian) và so sánh kiểu rút gọn, nên một
 * yêu cầu không có token — hoặc sai token — luôn bị từ chối.
 */
function wa_csrf_check(?string $token = null): bool
{
    auth_start_session();

    $token ??= (string) ($_POST[WA_CSRF_FIELD] ?? '');

    $expected = $_SESSION[WA_CSRF_SESSION_KEY] ?? '';

    if (!is_string($expected) || $expected === '' || $token === '') {
        return false;
    }

    return hash_equals($expected, $token);
}

/**
 * Chuỗi thẻ <input type="hidden"> chứa token CSRF — dùng trong mọi biểu mẫu POST.
 */
function wa_csrf_field(): string
{
    return '<input type="hidden" name="' . WA_CSRF_FIELD . '" value="'
        . wa_e(wa_csrf_token()) . '">';
}
