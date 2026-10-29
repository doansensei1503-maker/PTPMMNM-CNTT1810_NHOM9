<?php
/**
 * scripts/reindex_search.php
 * -----------------------------------------------------------------------------
 * LẬP LẠI TOÀN BỘ CHỈ MỤC ĐẢO TÌM KIẾM (P-SEARCH) — chạy lại được nhiều lần.
 *
 * CÁCH CHẠY (PowerShell, từ thư mục gốc dự án):
 *
 *     php scripts/migrate_search.php      (chỉ cần chạy một lần, để có bảng)
 *     php scripts/reindex_search.php
 *
 * CÁCH HOẠT ĐỘNG:
 *   1. XOÁ SẠCH bảng `search_terms` (TRUNCATE — bảng không có khoá ngoại tới nó).
 *   2. Lập chỉ mục lại cho MỌI snapshot có `fetch_status = 'success'`.
 *      Snapshot lưu thất bại không bao giờ được lập chỉ mục.
 *   3. In ra ba con số: số snapshot đã lập chỉ mục, tổng số dòng từ, và số từ
 *      đã bỏ dấu khác nhau trong toàn kho.
 *
 * IDEMPOTENT: vì bước 2 xoá dòng cũ của từng snapshot trước khi ghi lại, chạy
 * lần thứ N cho ra ĐÚNG kết quả như lần thứ nhất — không nhân bản dữ liệu.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/reindex_search.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

/** @param string $message */
function out(string $message): void
{
    echo $message . PHP_EOL;
}

try {
    $pdo = db();

    out('=====================================================================');
    out(' P-SEARCH — LẬP LẠI CHỈ MỤC ĐẢO search_terms');
    out('=====================================================================');

    // --- (1) Xoá sạch chỉ mục cũ ------------------------------------------------
    $pdo->exec('DELETE FROM `search_terms`');
    $cleared = (int) $pdo->query('SELECT ROW_COUNT()')->fetchColumn();
    out(sprintf('  · Đã xoá sạch chỉ mục cũ : %d dòng', $cleared));

    // --- (2) Lập chỉ mục từng snapshot thành công -----------------------------
    $stmt = $pdo->query(
        "SELECT id FROM `snapshots` WHERE `fetch_status` = 'success' ORDER BY id"
    );
    $snapshotIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

    $indexedSnapshots = 0;
    $totalTerms       = 0;
    $skipped          = 0;

    foreach ($snapshotIds as $snapshotId) {
        $written = wa_search_index_snapshot($snapshotId);

        if ($written > 0) {
            $indexedSnapshots++;
            $totalTerms += $written;
        } else {
            $skipped++;
        }
    }

    // --- (3) Tổng hợp ----------------------------------------------------------
    $distinctFolded = (int) $pdo->query(
        'SELECT COUNT(DISTINCT `term_folded`) FROM `search_terms`'
    )->fetchColumn();

    $rowsInTable = (int) $pdo->query('SELECT COUNT(*) FROM `search_terms`')->fetchColumn();

    out('');
    out('  Kết quả lập chỉ mục:');
    out('  ' . str_repeat('-', 68));
    out(sprintf('  %-46s %8d', 'Snapshots indexed (fetch_status = success)', $indexedSnapshots));
    out(sprintf('  %-46s %8d', 'Total term rows', $totalTerms));
    out(sprintf('  %-46s %8d', 'Distinct folded terms', $distinctFolded));
    out('  ' . str_repeat('-', 68));

    // Kiểm tra tự thân: tổng số snapshot thành công trong CSDL phải khớp với số
    // snapshot đã lập chỉ mục. Nếu lệch thì có snapshot rỗng không tạo được từ nào.
    $successTotal = (int) $pdo->query(
        "SELECT COUNT(*) FROM `snapshots` WHERE `fetch_status` = 'success'"
    )->fetchColumn();

    $failedTotal = (int) $pdo->query(
        "SELECT COUNT(*) FROM `snapshots` WHERE `fetch_status` <> 'success'"
    )->fetchColumn();

    out('');
    out(sprintf(
        '  · Snapshot thành công trong CSDL : %d (lập chỉ mục được %d, không có từ nào %d)',
        $successTotal,
        $indexedSnapshots,
        $skipped
    ));
    out(sprintf('  · Snapshot lỗi (KHÔNG lập chỉ mục) : %d', $failedTotal));
    out(sprintf('  · Tổng dòng thực tế trong bảng    : %d', $rowsInTable));

    if ($indexedSnapshots !== $successTotal) {
        out('');
        out('  [CẢNH BÁO] Số snapshot đã lập chỉ mục KHÔNG khớp tổng snapshot thành công.');
    }

    out('');
    out('  HOÀN TẤT. Có thể mở /search.php để tìm kiếm trên chỉ mục vừa dựng.');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . '[LỖI] ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Nếu báo "Table ... doesn\'t exist", hãy chạy: php scripts/migrate_search.php' . PHP_EOL);
    exit(1);
}