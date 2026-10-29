<?php
/**
 * scripts/migrate_soft_delete.php — ÁP DỤNG MIGRATION XOÁ MỀM (P-DELETE).
 * -----------------------------------------------------------------------------
 * Mở rộng `saved_pages.status` từ ENUM('active','error') thành
 * ENUM('active','error','deleted') để hỗ trợ xoá mềm bài viết.
 *
 * AN TOÀN:
 *   - KHÔNG xoá, KHÔNG sửa bất kỳ dòng dữ liệu nào.
 *   - Chỉ ALTER một cột ENUM (thêm giá trị), các giá trị cũ giữ nguyên.
 *   - Idempotent: chạy lại nhiều lần không lỗi (kiểm tra ENUM trước).
 *   - ROLLBACK: scripts/migrate_soft_delete.php --rollback (chỉ khi không còn
 *     dòng nào mang status='deleted').
 *
 * QUY TRÌNH TRƯỚC KHI CHẠY TRÊN DATABASE THẬT:
 *   1. Sao lưu: mysqldump -u ... webarchive > backup.sql
 *   2. Được phê duyệt.
 *   3. Chạy: php scripts/migrate_soft_delete.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Script này chỉ chạy được từ dòng lệnh.\n");
    exit(1);
}

require_once __DIR__ . '/../src/db.php';

$rollback = in_array('--rollback', $argv ?? [], true);

try {
    $pdo = db();

    $row = $pdo->query('SHOW CREATE TABLE `saved_pages`')->fetch();
    $ddl = (string) ($row['Create Table'] ?? '');

    if (!$rollback) {
        if (str_contains($ddl, "'deleted'")) {
            echo "[i] Cột status đã có giá trị 'deleted' — không cần migration.", PHP_EOL;
            exit(0);
        }

        echo "[*] Đang mở rộng ENUM status ('active','error') -> ('active','error','deleted') ...", PHP_EOL;

        $pdo->exec(
            "ALTER TABLE `saved_pages`
                MODIFY `status` ENUM('active', 'error', 'deleted')
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
                NOT NULL DEFAULT 'active'"
        );

        echo "[+] Migration xong. Không có dòng dữ liệu nào bị thay đổi.", PHP_EOL;
        exit(0);
    }

    // --- ROLLBACK ---
    $left = (int) $pdo->query(
        "SELECT COUNT(*) FROM `saved_pages` WHERE status = 'deleted'"
    )->fetchColumn();

    if ($left > 0) {
        fwrite(STDERR, "[LỖI] Còn {$left} bài đang ở trạng thái 'deleted'. "
            . "Hãy khôi phục hoặc xoá hẳn chúng trước khi rollback.\n");
        exit(1);
    }

    $pdo->exec(
        "ALTER TABLE `saved_pages`
            MODIFY `status` ENUM('active', 'error')
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
            NOT NULL DEFAULT 'active'"
    );

    echo "[+] Đã rollback về ENUM('active','error').", PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[LỖI] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
