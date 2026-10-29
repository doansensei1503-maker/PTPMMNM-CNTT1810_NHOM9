<?php
/**
 * src/db.php
 * -----------------------------------------------------------------------------
 * Lớp truy cập cơ sở dữ liệu (PDO singleton) cho WebArchive.
 *
 * - Kết nối bằng tài khoản riêng của ứng dụng (config/db.local.php),
 *   KHÔNG dùng tài khoản quản trị MySQL.
 * - utf8mb4, ERRMODE_EXCEPTION, prepares thật (không nội suy chuỗi).
 * - Nếu CSDL không truy cập được, hiển thị trang lỗi tiếng Việt
 *   (không in stack trace ra màn hình).
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/**
 * Lấy (hoặc tạo) kết nối PDO dùng chung cho toàn ứng dụng.
 *
 * Nếu không kết nối được, gọi db_fatal() để hiện trang lỗi tiếng Việt —
 * không để lỗi PDO nổi lên dưới dạng stack trace.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $config = wa_db_config();

        $pdo = new PDO(
            $config['dsn'],
            $config['user'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );
    } catch (Throwable $e) {
        // Thiếu tệp cấu hình hoặc không kết nối được MySQL -> trang lỗi tiếng Việt.
        db_fatal(db_diagnose($e));
    }

    return $pdo;
}

/**
 * Chuỗi kết nối PDO (dùng cho thông báo lỗi và cho scripts/setup_db.php).
 */
function db_dsn(): string
{
    return wa_db_config()['dsn'];
}

/**
 * Lấy mô tả ngắn gợi ý nguyên nhân lỗi mà KHÔNG lộ chi tiết nhạy cảm.
 *
 * Không bao giờ trả về thông báo gốc của PDO (có thể chứa DSN, tên máy chủ,
 * tên tài khoản hoặc cảnh báo nội bộ của MySQL) cho người dùng cuối.
 */
function db_diagnose(Throwable $e): string
{
    $raw = $e->getMessage();

    if (str_contains($raw, 'Access denied')) {
        return 'Tài khoản hoặc mật khẩu MySQL của ứng dụng không đúng.';
    }

    if (str_contains($raw, 'Unknown database')) {
        return 'Không tìm thấy database "webarchive". Hãy xác minh tên database và kiểm tra schema trước khi cài đặt.';
    }

    if (str_contains($raw, 'No such file')) {
        return 'Chưa có tệp cấu hình config/db.local.php. Hãy tạo tệp bằng thông tin MySQL hiện có.';
    }

    if (str_contains($raw, 'Connection refused') || str_contains($raw, 'timed out')) {
        return 'Máy chủ MySQL không phản hồi. Hãy kiểm tra dịch vụ MySQL và host/port trong config/db.local.php.';
    }

    if (str_contains($raw, 'db.local.php')) {
        return 'Chưa có hoặc cấu hình config/db.local.php không hợp lệ. Hãy kiểm tra tệp cấu hình local.';
    }

    return 'Máy chủ chưa liên lạc được với cơ sở dữ liệu MySQL.';
}

/**
 * Hiển thị trang lỗi tiếng Việt khi CSDL không truy cập được, rồi dừng chương
 * trình. Không bao giờ in stack trace / thông tin nhạy cảm ra màn hình.
 */
function db_fatal(string $reason = ''): never
{
    // Giới hạn thông điệp hiển thị: chỉ nhận câu tiếng Việt ngắn, không có
    // dấu xuống dòng (tránh lộ chi tiết kỹ thuật) và giới hạn 300 ký tự.
    $safe = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
    $safe = $safe === '' ? '' : mb_substr($safe, 0, 300, 'UTF-8');

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "[WebArchive] Không kết nối được cơ sở dữ liệu.\n");
        if ($safe !== '') {
            fwrite(STDERR, '[WebArchive] ' . $safe . "\n");
        }
        fwrite(STDERR, "[WebArchive] Hãy kiểm tra config/db.local.php và dịch vụ MySQL.\n");
        exit(1);
    }

    http_response_code(503);
    $detail = $safe !== '' ? $safe : 'Máy chủ chưa liên lạc được với cơ sở dữ liệu MySQL.';

    require __DIR__ . '/../templates/error_database.php';
    exit;
}

/**
 * Kiểm tra kết nối CSDL. Trả về thông báo lỗi tiếng Việt, hoặc null nếu OK.
 */
function db_check(): ?string
{
    try {
        db()->query('SELECT 1');
        return null;
    } catch (Throwable $e) {
        return db_diagnose($e);
    }
}
