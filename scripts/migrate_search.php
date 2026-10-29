<?php
/**
 * scripts/migrate_search.php
 * -----------------------------------------------------------------------------
 * BỔ SUNG BẢNG CHỈ MỤC ĐẢO CHO TÌM KIẾM (P-SEARCH) — chạy được nhiều lần.
 *
 * CÁCH CHẠY (PowerShell, từ thư mục gốc dự án):
 *
 *     php scripts/migrate_search.php
 *
 * Kịch bản này dùng TÀI KHOẢN ỨNG DỤNG trong config/db.local.php
 * ('webarchive'@'localhost'), vốn có quyền DDL bên trong database `webarchive`.
 * Nó KHÔNG cần và KHÔNG hỏi mật khẩu quản trị MySQL, và KHÔNG đụng tới bất kỳ
 * database nào khác.
 *
 * Nó chỉ tạo MỘT bảng mới: `search_terms`. Không xoá, không sửa dữ liệu bài viết.
 * Nhờ `CREATE TABLE IF NOT EXISTS` nên chạy lại bao nhiêu lần cũng an toàn.
 *
 * Sau khi chạy kịch bản này, chạy tiếp:
 *
 *     php scripts/reindex_search.php
 *
 * để lập chỉ mục cho các snapshot đã lưu sẵn.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/migrate_search.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once dirname(__DIR__) . '/src/db.php';

/** @param string $message */
function out(string $message): void
{
    echo $message . PHP_EOL;
}

// DDL của bảng search_terms — GIỐNG HỆT khối trong database/schema.sql, nên cài
// mới (schema.sql) và nâng cấp (kịch bản này) luôn cho cùng một cấu trúc.
$createSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `search_terms` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `term`        VARCHAR(100)   NOT NULL,
    `term_folded` VARCHAR(100)   NOT NULL,
    `snapshot_id` INT UNSIGNED  NOT NULL,
    `count_title` INT UNSIGNED   NOT NULL DEFAULT 0,
    `count_body`  INT UNSIGNED   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_search_terms_folded_snapshot` (`term_folded`, `snapshot_id`),
    KEY `ix_search_terms_folded` (`term_folded`),
    CONSTRAINT `fk_search_terms_snapshot`
        FOREIGN KEY (`snapshot_id`) REFERENCES `snapshots` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

try {
    $pdo = db();
    $cfg = wa_db_config();

    out('=====================================================================');
    out(' P-SEARCH — BỔ SUNG BẢNG CHỈ MỤC ĐẢO search_terms');
    out('=====================================================================');
    out(sprintf(
        '  · Database      : %s (máy chủ %s:%d)',
        $cfg['dbname'],
        $cfg['host'],
        $cfg['port']
    ));
    out(sprintf('  · Tài khoản    : %s (tài khoản ứng dụng — không cần mật khẩu quản trị)', $cfg['user']));

    // --- Bảng đã có chưa? (để in ra thông báo đúng trước/sau khi tạo) ---------
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = 'search_terms'"
    );
    $check->execute([':schema' => $cfg['dbname']]);
    $existed = (int) $check->fetchColumn() > 0;

    out(sprintf(
        '  · Trạng thái    : %s',
        $existed ? 'bảng search_terms đã tồn tại — CREATE TABLE IF NOT EXISTS sẽ bỏ qua'
                 : 'chưa có bảng search_terms — sẽ tạo mới'
    ));

    $pdo->exec($createSql);
    out('  · Đã áp dụng DDL: CREATE TABLE IF NOT EXISTS `search_terms`');

    // --- In cấu trúc thực tế để kiểm chứng ------------------------------------
    $cols = $pdo->query(
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = " . $pdo->quote($cfg['dbname']) . "
            AND TABLE_NAME = 'search_terms'
       ORDER BY ORDINAL_POSITION"
    )->fetchAll();

    out('');
    out('  Cấu trúc bảng `search_terms`:');
    out('  ' . str_repeat('-', 68));
    out(sprintf('  %-14s %-22s %-8s %-9s %s', 'CỘT', 'KIỂU', 'NULL', 'KHOÁ', 'THÊM'));
    out('  ' . str_repeat('-', 68));

    foreach ($cols as $c) {
        out(sprintf(
            '  %-14s %-22s %-8s %-9s %s',
            (string) $c['COLUMN_NAME'],
            (string) $c['COLUMN_TYPE'],
            (string) $c['IS_NULLABLE'],
            (string) $c['COLUMN_KEY'],
            (string) $c['EXTRA']
        ));
    }

    $idx = $pdo->query(
        "SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = " . $pdo->quote($cfg['dbname']) . "
            AND TABLE_NAME = 'search_terms'
       GROUP BY INDEX_NAME, NON_UNIQUE
       ORDER BY INDEX_NAME"
    )->fetchAll();

    out('  ' . str_repeat('-', 68));
    out('  Chỉ mục:');
    foreach ($idx as $i) {
        out(sprintf(
            '    · %-32s %-9s (%s)',
            (string) $i['INDEX_NAME'],
            ((int) $i['NON_UNIQUE'] === 0 ? 'UNIQUE' : 'INDEX'),
            (string) $i['cols']
        ));
    }

    $fk = $pdo->query(
        "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = " . $pdo->quote($cfg['dbname']) . "
            AND TABLE_NAME = 'search_terms'
            AND REFERENCED_TABLE_NAME IS NOT NULL"
    )->fetchAll();

    out('  Khoá ngoại:');
    foreach ($fk as $f) {
        out(sprintf(
            '    · %-32s %s → %s.%s (ON DELETE CASCADE)',
            (string) $f['CONSTRAINT_NAME'],
            (string) $f['COLUMN_NAME'],
            (string) $f['REFERENCED_TABLE_NAME'],
            (string) $f['REFERENCED_COLUMN_NAME']
        ));
    }

    $rows = (int) $pdo->query('SELECT COUNT(*) FROM `search_terms`')->fetchColumn();

    out('  ' . str_repeat('-', 68));
    out('');
    out(sprintf('  · Số dòng hiện có trong search_terms: %d', $rows));
    out('');
    out('  DDL đã sẵn sàng. Bước tiếp theo: php scripts/reindex_search.php');
    out('  (lập chỉ mục cho các snapshot fetch_status = \'success\').');
    out('');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . '[LỖI] ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Nếu là lỗi quyền (1044/1045/1142), tài khoản ứng dụng cần có quyền' . PHP_EOL);
    fwrite(STDERR, 'CREATE/ALTER trên database webarchive.' . PHP_EOL);
    exit(1);
}