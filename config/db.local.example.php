<?php
/**
 * config/db.local.example.php — MẪU CẤU HÌNH (ĐƯỢC COMMIT, KHÔNG CHỨA BÍ MẬT).
 *
 * Cách dùng:
 *   1. Copy tệp này thành config/db.local.php
 *   2. Điền thông tin MySQL thật vào bản copy.
 *   3. config/db.local.php đã có trong .gitignore — KHÔNG BAO GIỜ commit.
 */

declare(strict_types=1);

return [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'dbname'   => 'webarchive',
    'user'     => 'webarchive',
    'password' => 'DOI_MAT_KHAU_O_DAY',
    'charset'  => 'utf8mb4',
];
