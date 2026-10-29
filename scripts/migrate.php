<?php
/**
 * scripts/migrate.php — MIGRATION AN TOÀN cho CSDL WebArchive đang có dữ liệu.
 * -----------------------------------------------------------------------------
 * Mục đích: nâng cấp lược đồ của một database ĐANG CHẠY mà KHÔNG xoá, KHÔNG
 * reset, KHÔNG chạy lại database/schema.sql (vì schema.sql có DROP TABLE).
 *
 * Migration trong tệp này:
 *   M1) Mở rộng ENUM `snapshots.fetch_status` để chứa các mã lỗi mới
 *       (error_403, error_429, error_5xx, error_ssl, error_login,
 *        error_captcha, error_unsafe, error_invalid_url).
 *   M2) Thêm bảng `domain_requests` — hàng đợi yêu cầu duyệt TÊN MIỀN
 *       (thay cho việc Admin phải duyệt từng URL).
 *   M3) Thêm bảng `admin_logs` — nhật ký thao tác quản trị.
 *   M4) Thêm cột `updated_at` cho `saved_pages` nếu thiếu.
 *   M5) Tạo chỉ mục phụ trợ cho việc tra cứu nhanh (page_id + fetch_status).
 *   M6) Thêm bảng `capture_attempts` — lịch sử lần thử tải (FIX-09).
 *
 * QUY TẮC AN TOÀN:
 *   - Tệp này KHÔNG BAO GIỜ chạy DROP TABLE / TRUNCATE / DELETE.
 *   - Mọi câu lệnh đều là ALTER TABLE ADD / CREATE TABLE IF NOT EXISTS.
 *   - Chạy được nhiều lần (idempotent): dùng IF NOT EXISTS và kiểm tra trước.
 *   - Tạo bản sao lưu cấu trúc (SHOW CREATE TABLE) trước khi sửa ENUM.
 *
 * Cách chạy:
 *   php scripts/migrate.php
 *
 * Mã thoát: 0 khi thành công, 1 khi lỗi.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Script này chỉ chạy được từ dòng lệnh.\n");
    exit(1);
}

require_once __DIR__ . '/../src/db.php';

/** Ghi một dòng ra màn hình. */
function mig_out(string $line): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

/** Ghi một dòng cảnh báo. */
function mig_warn(string $line): void
{
    fwrite(STDERR, '[CẢNH BÁO] ' . $line . PHP_EOL);
}

try {
    $pdo = db();

    mig_out('=== WebArchive — migration lược đồ (an toàn, không xoá dữ liệu) ===');
    mig_out('');

    /* -----------------------------------------------------------------------
     * M0) Kiểm tra kết nối.
     * --------------------------------------------------------------------- */
    $pdo->query('SELECT 1');
    mig_out('[0/5] Kết nối CSDL thành công.');

    /* -----------------------------------------------------------------------
     * M1) Mở rộng ENUM snapshots.fetch_status.
     *
     * MySQL không có "ADD VALUE IF NOT EXISTS" cho ENUM, nên ta đọc định nghĩa
     * ENUM hiện tại, ghép thêm các giá trị còn thiếu, rồi ALTER.
     * --------------------------------------------------------------------- */
    $current = $pdo->query("SHOW COLUMNS FROM `snapshots` LIKE 'fetch_status'")->fetch(PDO::FETCH_ASSOC);

    if ($current === false) {
        mig_warn('Bảng `snapshots` không tồn tại — bỏ qua M1.');
    } else {
        $type = (string) $current['Type'];

        $wanted = [
            'success', 'error_invalid_url', 'error_blocked', 'error_timeout',
            'error_404', 'error_403', 'error_429', 'error_5xx', 'error_ssl',
            'error_login', 'error_captcha', 'error_unsafe',
            'error_no_content', 'error_http',
        ];

        // Lấy danh sách giá trị hiện có.
        preg_match_all("/'([^']+)'/", $type, $matches);
        $existing = $matches[1] ?? [];

        $missing = array_values(array_diff($wanted, $existing));

        if ($missing === []) {
            mig_out('[1/5] ENUM `snapshots.fetch_status` đã có đủ mã lỗi — bỏ qua.');
        } else {
            $merged = array_values(array_unique(array_merge($existing, $wanted)));
            $newType = "ENUM('" . implode("','", $merged) . "')";

            // Bản sao lưu cấu trúc cũ (in ra tệp log, không ghi vào CSDL).
            $backup = $pdo->query('SHOW CREATE TABLE `snapshots`')->fetch(PDO::FETCH_NUM);
            mig_out('      Cấu trúc cũ (tham chiếu): ' . (string) ($backup[1] ?? ''));

            $pdo->exec('ALTER TABLE `snapshots` MODIFY COLUMN `fetch_status` ' . $newType
                . ' NOT NULL DEFAULT \'success\'');

            mig_out('[1/5] Đã mở rộng ENUM `snapshots.fetch_status` với: '
                . implode(', ', $missing));
        }
    }

    /* -----------------------------------------------------------------------
     * M2) Bảng `domain_requests` — yêu cầu duyệt tên miền.
     * --------------------------------------------------------------------- */
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `domain_requests` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `domain`       VARCHAR(255) NOT NULL,
            `status`       ENUM('pending','approved','rejected','disabled') NOT NULL DEFAULT 'pending',
            `note`         VARCHAR(500) NULL,
            `requested_by` INT UNSIGNED NOT NULL,
            `reviewed_by`  INT UNSIGNED NULL,
            `reviewed_at`  DATETIME     NULL,
            `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_domain_requests_domain` (`domain`),
            KEY `ix_domain_requests_status` (`status`),
            KEY `ix_domain_requests_requester` (`requested_by`),
            CONSTRAINT `fk_domain_requests_requester`
                FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_domain_requests_reviewer`
                FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    mig_out('[2/5] Bảng `domain_requests` đã sẵn sàng.');

    /* -----------------------------------------------------------------------
     * M3) Bảng `admin_logs` — nhật ký thao tác quản trị.
     * --------------------------------------------------------------------- */
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `admin_logs` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `actor_id`   INT UNSIGNED NULL,
            `action`     VARCHAR(100) NOT NULL,
            `target_type` VARCHAR(60) NULL,
            `target_id`  VARCHAR(120) NULL,
            `detail`     VARCHAR(500) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_admin_logs_created` (`created_at`),
            KEY `ix_admin_logs_actor` (`actor_id`),
            CONSTRAINT `fk_admin_logs_actor`
                FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    mig_out('[3/5] Bảng `admin_logs` đã sẵn sàng.');

    /* -----------------------------------------------------------------------
     * M4) Cột `updated_at` cho `saved_pages` (nếu thiếu).
     * --------------------------------------------------------------------- */
    $cols = $pdo->query("SHOW COLUMNS FROM `saved_pages` LIKE 'updated_at'")->fetch(PDO::FETCH_ASSOC);

    if ($cols === false) {
        $pdo->exec(
            'ALTER TABLE `saved_pages` ADD COLUMN `updated_at` DATETIME NOT NULL '
            . 'DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
        mig_out('[4/5] Đã thêm cột `saved_pages.updated_at`.');
    } else {
        mig_out('[4/5] Cột `saved_pages.updated_at` đã tồn tại — bỏ qua.');
    }

    /* -----------------------------------------------------------------------
     * M5) Chỉ mục phụ trợ cho tra cứu nhanh.
     * --------------------------------------------------------------------- */
    $indexes = $pdo->query("SHOW INDEX FROM `snapshots`")->fetchAll(PDO::FETCH_ASSOC);
    $names   = array_column($indexes, 'Key_name');

    if (!in_array('ix_snapshots_page_status', $names, true)) {
        $pdo->exec('CREATE INDEX `ix_snapshots_page_status` ON `snapshots` (`page_id`, `fetch_status`)');
        mig_out('[5/6] Đã tạo chỉ mục `ix_snapshots_page_status`.');
    } else {
        mig_out('[5/6] Chỉ mục `ix_snapshots_page_status` đã tồn tại — bỏ qua.');
    }

    /* -----------------------------------------------------------------------
     * M6) Bảng `capture_attempts` — lịch sử lần thử tải (FIX-09).
     * --------------------------------------------------------------------- */
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `capture_attempts` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `page_id`     INT UNSIGNED NOT NULL,
            `library_id`  INT UNSIGNED NOT NULL,
            `source_url`  VARCHAR(1000) NOT NULL,
            `ok`          TINYINT(1) NOT NULL DEFAULT 0,
            `status_code` VARCHAR(60) NOT NULL DEFAULT '',
            `http_status` VARCHAR(10) NOT NULL DEFAULT '',
            `detail`      VARCHAR(500) NULL,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_capture_attempts_page` (`page_id`),
            KEY `ix_capture_attempts_library` (`library_id`),
            CONSTRAINT `fk_capture_attempts_page`
                FOREIGN KEY (`page_id`) REFERENCES `saved_pages` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_capture_attempts_library`
                FOREIGN KEY (`library_id`) REFERENCES `libraries` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    mig_out('[6/6] Bảng `capture_attempts` đã sẵn sàng.');

    /* -----------------------------------------------------------------------
     * Tổng kết: in số dòng từng bảng (để đối chiếu dữ liệu không bị mất).
     * --------------------------------------------------------------------- */
    mig_out('');
    mig_out('Số dòng sau migration:');
    mig_out(str_repeat('-', 58));

    foreach (['users', 'libraries', 'saved_pages', 'snapshots', 'notes',
              'allowlist_domains', 'domain_requests', 'admin_logs'] as $table) {
        try {
            $count = $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
            mig_out(sprintf('  %-20s %8d dòng', $table, (int) $count));
        } catch (Throwable $e) {
            mig_out(sprintf('  %-20s %8s', $table, '(không có)'));
        }
    }

    mig_out(str_repeat('-', 58));
    mig_out('');
    mig_out('HOÀN TẤT. Không có dữ liệu nào bị xoá hay thay đổi.');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '');
    fwrite(STDERR, '[LỖI] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
