<?php
/**
 * scripts/ensure_local_hosts.php — BẢO ĐẢM CÓ MÁY CHỦ THỬ NGHIỆM CỤC BỘ.
 * -----------------------------------------------------------------------------
 * Vì sao cần tệp này?
 *
 *   Bộ kiểm thử (scripts/run_tests.php) và bộ dữ liệu mẫu (scripts/seed_samples.php)
 *   phục vụ trang mẫu bằng `php -S localhost:<port>`. Vì vậy các URL mẫu có tên
 *   miền "localhost" — nhưng "localhost" là địa chỉ MẠNG NỘI BỘ, mà engine
 *   tải trang chặn (bảo vệ SSRF). Để máy chủ thử nghiệm cục bộ hoạt động,
 *   quản trị viên phải DUYỆT host đó trong `allowlist_domains` — đúng cơ chế
 *   "duyệt một lần cho cả domain" của đề tài.
 *
 *   Tệp này thêm `localhost` (và `127.0.0.1`) vào danh sách được duyệt NẾU CHƯA
 *   CÓ. Nó KHÔNG đụng tới dữ liệu nào khác và chạy được nhiều lần (idempotent).
 *
 * AN TOÀN:
 *   - Chỉ INSERT vào `allowlist_domains` (bảng tra cứu), KHÔNG sửa/xoá dữ liệu
 *     người dùng, KHÔNG chạy DDL, KHÔNG reset CSDL.
 *   - Ghi một dòng vào `admin_logs` để có dấu vết.
 *
 * Cách chạy:
 *   php scripts/ensure_local_hosts.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Script này chỉ chạy được từ dòng lệnh.\n");
    exit(1);
}

require_once __DIR__ . '/../src/db.php';

try {
    $pdo = db();

    // Tài khoản quản trị viên dùng làm người ghi nhật (đã có sẵn trong CSDL).
    $adminId = (int) $pdo->query(
        "SELECT id FROM `users` WHERE role = 'admin' ORDER BY id LIMIT 1"
    )->fetchColumn();

    if ($adminId <= 0) {
        $adminId = (int) $pdo->query('SELECT id FROM `users` ORDER BY id LIMIT 1')->fetchColumn();
    }

    if ($adminId <= 0) {
        fwrite(STDERR, "[LỖI] Không có tài khoản nào để ghi nhận. Hãy chạy scripts/setup_db.php trước.\n");
        exit(1);
    }

    $hosts = [
        'localhost' => 'Máy chủ thử nghiệm cục bộ của dự án (bộ kiểm thử + dữ liệu mẫu)',
        '127.0.0.1' => 'Loopback — máy chủ thử nghiệm cục bộ của dự án',
    ];

    $insert = $pdo->prepare(
        'INSERT INTO `allowlist_domains` (`domain`, `note`, `added_by`) VALUES (?, ?, ?)'
    );

    $added = 0;
    $skipped = 0;

    foreach ($hosts as $host => $note) {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM `allowlist_domains` WHERE domain = ?');
        $exists->execute([$host]);

        if ((int) $exists->fetchColumn() > 0) {
            $skipped++;
            continue;
        }

        $insert->execute([$host, $note, $adminId]);
        $added++;

        echo "[+] Đã duyệt host: {$host}", PHP_EOL;
    }

    // Ghi nhật vào nhật ký quản trị (nếu bảng tồn tại).
    try {
        $log = $pdo->prepare(
            'INSERT INTO `admin_logs` (`actor_id`, `action`, `target_type`, `target_id`, `detail`)
             VALUES (?, ?, ?, ?, ?)'
        );
        $log->execute([
            $adminId,
            'domain.add',
            'domain',
            'localhost',
            'Bảo đảm máy chủ thử nghiệm cục bộ được duyệt (script ensure_local_hosts.php)',
        ]);
    } catch (Throwable $e) {
        // Không có bảng admin_logs -> bỏ qua, không làm hỏng việc chính.
    }

    echo "[i] Thêm mới: {$added} · Đã có sẵn: {$skipped}", PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[LỖI] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
