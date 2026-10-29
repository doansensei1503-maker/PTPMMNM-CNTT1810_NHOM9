<?php
/**
 * config/db.php
 * -----------------------------------------------------------------------------
 * CẤU HÌNH CƠ SỞ DỮ LIỆU (tệp được commit).
 *
 * Tệp này KHÔNG chứa mật khẩu. Thông tin kết nối thật nằm trong
 * config/db.local.php — tệp đó được .gitignore và KHÔNG BAO GIỜ commit.
 * Tệp db.local.php có thể được tạo thủ công hoặc sinh bởi scripts/setup_db.php.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

/**
 * Nạp cấu hình kết nối MySQL từ config/db.local.php.
 *
 * @return array{dsn:string,user:string,password:string,host:string,port:int,dbname:string}
 * @throws RuntimeException Khi thiếu tệp cấu hình hoặc cấu hình không hợp lệ.
 */
function wa_db_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $localFile = __DIR__ . '/db.local.php';

    if (!is_file($localFile)) {
        throw new RuntimeException(
            'Chưa có tệp cấu hình config/db.local.php. '
            . 'Hãy tạo tệp này bằng thông tin tài khoản MySQL hiện có; '
            . 'không chạy scripts/setup_db.php trên database đang có dữ liệu.'
        );
    }

    /** @var mixed $local */
    $local = require $localFile;

    if (!is_array($local)) {
        throw new RuntimeException('Tệp cấu hình config/db.local.php không hợp lệ.');
    }

    foreach (['host', 'port', 'dbname', 'user', 'password'] as $key) {
        if (!array_key_exists($key, $local)) {
            throw new RuntimeException('Tệp cấu hình config/db.local.php thiếu khoá "' . $key . '".');
        }
    }

    $host    = (string) $local['host'];
    $port    = (int) $local['port'];
    $dbname  = (string) $local['dbname'];
    $charset = (string) ($local['charset'] ?? 'utf8mb4');

    $config = [
        'host'     => $host,
        'port'     => $port,
        'dbname'   => $dbname,
        'user'     => (string) $local['user'],
        'password' => (string) $local['password'],
        'charset'  => $charset,
        'dsn'      => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset),
    ];

    return $config;
}
