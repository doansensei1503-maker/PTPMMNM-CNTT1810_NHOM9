<?php
/**
 * scripts/setup_db.php
 * -----------------------------------------------------------------------------
 * CÀI ĐẶT CƠ SỞ DỮ LIỆU MySQL CHO WEBARCHIVE (CLI) — CHỈ DÙNG CHO CSDL MỚI.
 *
 * CÁCH CHẠY (PowerShell):
 *
 *     $env:WA_ADMIN_USER = 'root'
 *     $env:WA_ADMIN_PASS = '<mat khau quan tri MySQL>'
 *     php scripts/setup_db.php
 *
 * Tài khoản quản trị CHỈ được dùng ở bước tạo CSDL và tài khoản riêng của
 * ứng dụng. Ứng dụng sau đó kết nối bằng tài khoản hạn chế 'webarchive'@'localhost'
 * với mật khẩu ngẫu nhiên lưu trong config/db.local.php (tệp này được
 * .gitignore và KHÔNG BAO GIỜ commit).
 *
 * CẢNH BÁO: schema.sql DROP các bảng hiện có trong 'webarchive' và seed.sql
 * nạp lại dữ liệu mẫu. Chỉ chạy trên database mới/trống hoặc sau khi đã sao lưu
 * và xác nhận được phép thay thế dữ liệu. Không chạy lại trên database đang dùng.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/setup_db.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

const WA_DB_NAME    = 'webarchive';
const WA_APP_USER   = 'webarchive';
const WA_APP_HOST   = 'localhost';
const WA_DB_HOST    = '127.0.0.1';
const WA_DB_PORT    = 3306;

// -----------------------------------------------------------------------------
// 1. Đọc thông tin đăng nhập quản trị từ biến môi trường
// -----------------------------------------------------------------------------
$adminUser = (string) (getenv('WA_ADMIN_USER') ?: '');
$adminPass = (string) (getenv('WA_ADMIN_PASS') ?: '');

if ($adminUser === '' || $adminPass === '') {
    fwrite(STDERR, "=====================================================================\n");
    fwrite(STDERR, " THIẾU THÔNG TIN ĐĂNG NHẬP QUẢN TRỊ MySQL\n");
    fwrite(STDERR, "=====================================================================\n");
    fwrite(STDERR, "Hãy đặt hai biến môi trường sau rồi chạy lại lệnh này:\n\n");
    fwrite(STDERR, "    \$env:WA_ADMIN_USER = 'root'\n");
    fwrite(STDERR, "    \$env:WA_ADMIN_PASS = '<mat khau quan tri MySQL>'\n");
    fwrite(STDERR, "    php scripts/setup_db.php\n\n");
    fwrite(STDERR, "Không ghi mật khẩu vào bất kỳ tệp nào trong dự án.\n");
    exit(1);
}

/** @param string $message */
function out(string $message): void
{
    echo $message . PHP_EOL;
}

/**
 * Chạy một tệp SQL (nhiều câu lệnh) qua kết nối PDO đã cho.
 */
function apply_sql_file(PDO $pdo, string $file): void
{
    if (!is_file($file)) {
        throw new RuntimeException('Không tìm thấy tệp SQL: ' . $file);
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Không đọc được tệp SQL: ' . $file);
    }

    // Tách câu lệnh theo dấu ";" ở cuối dòng.
    $statements = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
    $count = 0;

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
        $count++;
    }

    out(sprintf('  · Đã áp dụng %s (%d câu lệnh)', basename($file), $count));
}

try {
    // -------------------------------------------------------------------------
    // 2. Kết nối bằng tài khoản quản trị
    // -------------------------------------------------------------------------
    $adminDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', WA_DB_HOST, WA_DB_PORT);

    $pdo = new PDO($adminDsn, $adminUser, $adminPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    out('[1/6] Đã kết nối MySQL bằng tài khoản quản trị "' . $adminUser . '" tại '
        . WA_DB_HOST . ':' . WA_DB_PORT);

    // -------------------------------------------------------------------------
    // 3. Tạo database webarchive nếu chưa có
    // -------------------------------------------------------------------------
    $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
    $stmt->execute([WA_DB_NAME]);

    if ($stmt->fetchColumn() === false) {
        $pdo->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            WA_DB_NAME
        ));
        out('[2/6] Đã tạo database `' . WA_DB_NAME . '` (utf8mb4 / utf8mb4_unicode_ci)');
    } else {
        out('[2/6] Database `' . WA_DB_NAME . '` đã tồn tại — dùng lại');
    }

    // -------------------------------------------------------------------------
    // 4. Tài khoản riêng cho ứng dụng + config/db.local.php
    // -------------------------------------------------------------------------
    $configDir  = dirname(__DIR__) . '/config';
    $configFile = $configDir . '/db.local.php';

    if (is_file($configFile)) {
        /** @var mixed $existing */
        $existing = require $configFile;
        if (!is_array($existing) || !isset($existing['user'], $existing['password'])) {
            throw new RuntimeException('Tệp config/db.local.php không hợp lệ — hãy xoá nó rồi chạy lại.');
        }
        $appUser     = (string) $existing['user'];
        $appPassword = (string) $existing['password'];
        out('[3/6] Đã có config/db.local.php — tái sử dụng tài khoản ứng dụng "' . $appUser . '"');
    } else {
        if (!is_dir($configDir) && !mkdir($configDir, 0o775, true) && !is_dir($configDir)) {
            throw new RuntimeException('Không tạo được thư mục config/.');
        }

        // Mật khẩu ngẫu nhiên mạnh (chỉ ký tự an toàn cho DSN/MySQL).
        $appPassword = bin2hex(random_bytes(24));
        $appUser     = WA_APP_USER;

        $pdo->exec(sprintf(
            "CREATE USER IF NOT EXISTS '%s'@'%s' IDENTIFIED BY '%s'",
            $appUser,
            WA_APP_HOST,
            str_replace("'", "''", $appPassword)
        ));
        $pdo->exec(sprintf(
            "GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%s'",
            WA_DB_NAME,
            $appUser,
            WA_APP_HOST
        ));
        $pdo->exec('FLUSH PRIVILEGES');

        $php = "<?php\n"
            . "/**\n"
            . " * config/db.local.php — THÔNG TIN KẾT NỐI CỤC BỘ (KHÔNG COMMIT).\n"
            . " *\n"
            . " * Tệp này được sinh tự động bởi scripts/setup_db.php và đã được .gitignore.\n"
            . " * Chứa mật khẩu của tài khoản MySQL riêng cho ứng dụng.\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "return [\n"
            . "    'host'     => '" . WA_DB_HOST . "',\n"
            . "    'port'     => " . WA_DB_PORT . ",\n"
            . "    'dbname'   => '" . WA_DB_NAME . "',\n"
            . "    'user'     => '" . $appUser . "',\n"
            . "    'password' => '" . $appPassword . "',\n"
            . "    'charset'  => 'utf8mb4',\n"
            . "];\n";

        if (file_put_contents($configFile, $php) === false) {
            throw new RuntimeException('Không ghi được config/db.local.php.');
        }

        out('[3/6] Đã tạo tài khoản "' . $appUser . "'@'" . WA_APP_HOST
            . "' (chỉ có quyền trên " . WA_DB_NAME . ".*) và ghi config/db.local.php");
    }

    // -------------------------------------------------------------------------
    // 5. Áp dụng schema.sql + seed.sql (chỉ trong database webarchive)
    // -------------------------------------------------------------------------
    $appDsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        WA_DB_HOST,
        WA_DB_PORT,
        WA_DB_NAME
    );
    $app = new PDO($appDsn, $appUser, $appPassword, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    out('[4/6] Đang áp dụng lược đồ và dữ liệu mẫu...');
    apply_sql_file($app, dirname(__DIR__) . '/database/schema.sql');
    apply_sql_file($app, dirname(__DIR__) . '/database/seed.sql');

    // -------------------------------------------------------------------------
    // 5b. Vật chất hoá tài nguyên cục bộ của dữ liệu mẫu (P-FINAL-2 / B5)
    //
    // Dữ liệu mẫu khai báo mỗi dòng `assets` với đường dẫn
    // '/uploads/snapshots/<snapshot_id>/…'. Ở bước này ta TẠO THẬT các tệp
    // đó trên đĩa để các bộ đếm "ảnh cục bộ còn trên đĩa" báo thiếu ~ 0:
    //   - ảnh  : sao chép từ tests/samples/anh-<NN>.png (tệp PNG đã commit)
    //   - HTML : ghi content_html của chính snapshot đó
    // size_bytes được cập nhật lại theo kích thước tệp thật.
    // -------------------------------------------------------------------------
    $assetRows = $app->query(
        "SELECT a.id, a.snapshot_id, a.local_path, a.mime_type, s.content_html
           FROM `assets` a
           JOIN `snapshots` s ON s.id = a.snapshot_id
          ORDER BY a.id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $uploadsRoot = dirname(__DIR__) . '/public/uploads/snapshots';
    $samplePngs  = glob(dirname(__DIR__) . '/tests/samples/anh-*.png') ?: [];
    $written     = 0;
    $updated     = 0;

    $updateAsset = $app->prepare(
        'UPDATE `assets` SET `size_bytes` = ? WHERE `id` = ?'
    );

    foreach ($assetRows as $asset) {
        $relative = ltrim(str_replace('\\', '/', (string) $asset['local_path']), '/');

        if (!str_starts_with($relative, 'uploads/snapshots/')) {
            continue;
        }

        $target = dirname(__DIR__) . '/public/' . $relative;

        if (!is_dir($dir = dirname($target)) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Không tạo được thư mục ' . $dir);
        }

        $isHtml = str_starts_with((string) $asset['mime_type'], 'text/html');
        $bytes  = null;

        if ($isHtml) {
            $html = (string) $asset['content_html'];

            if ($html !== '') {
                $bytes = $html;
            }
        } else {
            $index = ((int) $asset['id'] % 5);

            if (isset($samplePngs[$index])) {
                $bytes = (string) file_get_contents($samplePngs[$index]);
            }
        }

        if ($bytes === null) {
            continue;
        }

        if (@file_put_contents($target, $bytes) === false) {
            throw new RuntimeException('Không ghi được tệp tài nguyên ' . $target);
        }

        $written++;

        $updateAsset->execute([strlen($bytes), (int) $asset['id']]);
        $updated++;
    }

    out('  · Đã tạo ' . $written . ' tệp tài nguyên cục bộ và cập nhật '
        . $updated . ' dòng assets (size_bytes theo tệp thật)');

    // -------------------------------------------------------------------------
    // 6. In số dòng của từng bảng
    // -------------------------------------------------------------------------
    $tables = [
        'users', 'libraries', 'library_members', 'tags', 'saved_pages',
        'page_tags', 'snapshots', 'assets', 'notes', 'allowlist_domains',
    ];

    out('');
    out('[6/6] Số dòng sau khi cài đặt (database `' . WA_DB_NAME . '`):');
    out(str_repeat('-', 58));

    foreach ($tables as $table) {
        $count = $app->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        out(sprintf('  %-20s %8d dòng', $table, (int) $count));
    }

    out(str_repeat('-', 58));
    out('');
    out('HOÀN TẤT. Ứng dụng sẽ dùng tài khoản "' . $appUser . '" qua config/db.local.php.');
    out('Chạy ứng dụng:  php -S localhost:8080 -t public');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '');
    fwrite(STDERR, '[LỖI] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
