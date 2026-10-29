<?php
/**
 * scripts/seed_samples.php — NẠP BỘ DỮ LIỆU MẪU (P-FINAL, Deliverable 2).
 *
 * Mục đích
 * --------
 * Nạp 15 trang mẫu trong tests/samples/ vào thư viện "Kho dữ liệu mẫu" của
 * tài khoản user@demo.vn, đi qua ĐÚNG đường lưu trang thật của ứng dụng:
 * wa_store_page_capture() -> capture_url() (cURL) -> bóc tách DOM -> tải ảnh
 * về local -> ghi snapshot -> lập chỉ mục tìm kiếm. KHÔNG chép thẳng vào
 * CSDL, KHÔNG giả lập kết quả.
 *
 * Cách chạy
 * ---------
 *   1) Khởi động máy chủ phục vụ thư mẫu (một tiến trình riêng):
 *        php -S localhost:8091 -t tests/samples
 *   2) Nạp dữ liệu mẫu:
 *        php scripts/seed_samples.php
 *
 * Tuỳ chọn
 * --------
 *   --base=http://localhost:8091   Địa chỉ máy chủ phục vụ thư mẫu.
 *   --library="Kho dữ liệu mẫu"    Tên thư viện đích.
 *   --email=user@demo.vn           Tài khoản chủ sở hữu thư viện đích.
 *   --force                        Nạp lại từ đầu: XOÁ thư viện cũ (qua hàm xoá
 *                                  của ứng dụng, tự dọn cả thư mục ảnh) rồi
 *                                  tạo lại và nạp 15 trang từ đầu.
 *
 * Tính IDEMPOTENT
 * ---------------
 * Nếu thư viện đích đã tồn tại và đã chứa đủ 15 trang mẫu, script in ra thông
 * báo "đã nạp sẵn" và thoát với mã 0 — KHÔNG tạo bản ghi trùng.
 *
 * QUY TẮC CỨNG
 * ------------
 *   - KHÔNG chạy scripts/setup_db.php, KHÔNG hỏi mật khẩu quản trị MySQL.
 *   - KHÔNG xoá tay file trong public/uploads/ — dọn dẹp đi qua hàm xoá
 *     thư viện của chính ứng dụng (wa_library_delete), hàm này tự dọn thư
 *     mục ảnh.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Script này chỉ chạy được từ dòng lệnh.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';

/* =============================================================================
    PHẦN 1 — THAM SỐ DÒNG LỆNH
    ============================================================================= */

/** @var array<string, string|bool> $opt */
$opt = getopt('', ['base::', 'library::', 'email::', 'force', 'help']);

if (isset($opt['help'])) {
    echo "Dùng: php scripts/seed_samples.php [--base=URL] [--library=\"Tên\"] [--email=email] [--force]\n";
    exit(0);
}

$baseUrl    = rtrim((string) ($opt['base'] ?? 'http://localhost:8091'), '/');
$libraryName = trim((string) ($opt['library'] ?? 'Kho dữ liệu mẫu'));
$ownerEmail = trim((string) ($opt['email'] ?? 'user@demo.vn'));
$force      = isset($opt['force']);

/* =============================================================================
    PHẦN 2 — DANH SÁCH 15 TRANG MẪU
    ============================================================================= */

/**
 * Mỗi mục mô tả MỘT trang mẫu: tệp trong tests/samples/, nhãn gắn vào trang,
 * và kết quả MONG ĐỢI (dùng để in bảng tổng hợp khớp với manifest trong
 * tests/samples/README.md).
 *
 * 'expect' là kết quả mong đợi đã viết ra từ nội dung trang, KHÔNG phải từ
 * kết quả chạy thử.
 */
$samplePlan = [
    ['file' => 'bai-01.html', 'tags' => ['mau-webarchive', 'php'], 'expect' => 'success', 'images' => 1],
    ['file' => 'bai-02.html', 'tags' => ['mau-webarchive', 'mysql'], 'expect' => 'success', 'images' => 1],
    ['file' => 'bai-03.html', 'tags' => ['mau-webarchive', 'html-css'], 'expect' => 'success', 'images' => 0],
    ['file' => 'bai-04.html', 'tags' => ['mau-webarchive', 'javascript'], 'expect' => 'success', 'images' => 0],
    ['file' => 'bai-05.html', 'tags' => ['mau-webarchive', 'linux'], 'expect' => 'success', 'images' => 0],
    ['file' => 'bai-06.html', 'tags' => ['mau-webarchive', 'git'], 'expect' => 'success', 'images' => 0],
    ['file' => 'bai-07.html', 'tags' => ['mau-webarchive', 'php'], 'expect' => 'success', 'images' => 0],
    ['file' => 'bai-08.html', 'tags' => ['mau-webarchive', 'hinh-anh'], 'expect' => 'success', 'images' => 3],
    ['file' => 'bai-09.html', 'tags' => ['mau-webarchive', 'mang'], 'expect' => 'success', 'images' => 0],
    ['file' => 'bai-10.html', 'tags' => ['mau-webarchive', 'html-css'], 'expect' => 'success', 'images' => 0],
    ['file' => 'bai-11.html', 'tags' => ['mau-webarchive', 'security'], 'expect' => 'success', 'images' => 0],
    ['file' => 'bai-12.html', 'tags' => ['mau-webarchive'], 'expect' => 'error_no_content', 'images' => 0],
    ['file' => 'bai-13.html', 'tags' => ['mau-webarchive', 'html-css'], 'expect' => 'success', 'images' => 0],
    ['file' => 'chuyen-huong-ngoai.php', 'tags' => ['mau-webarchive'], 'expect' => 'error_blocked', 'images' => 0],
    ['file' => 'cham.php', 'tags' => ['mau-webarchive'], 'expect' => 'error_timeout', 'images' => 0],
];

/* =============================================================================
    PHẦN 3 — TIỆN ÍCH IN BÁO CÁO
    ============================================================================= */

function seed_out(string $line): void
{
    echo $line, PHP_EOL;
}

function seed_fail(string $line): never
{
    fwrite(STDERR, $line . PHP_EOL);
    exit(1);
}

/* =============================================================================
    PHẦN 4 — TÌM TÀI KHOẢN CHỦ SỞ HỮU
    ============================================================================= */

$stmt = db()->prepare('SELECT id, email, full_name FROM `users` WHERE email = ? LIMIT 1');
$stmt->execute([$ownerEmail]);
$owner = $stmt->fetch();

if ($owner === false) {
    seed_fail("[LỖI] Không tìm thấy tài khoản «{$ownerEmail}» trong CSDL.");
}

$ownerId = (int) $owner['id'];

seed_out("Tài khoản chủ sở hữu: {$owner['email']} (id={$ownerId})");
seed_out("Máy chủ thư mẫu:      {$baseUrl}");

/* =============================================================================
    PHẦN 5 — KIỂM TRA MÁY CHỦ THƯ MẪU CÓ SỐNG KHÔNG
    ============================================================================= */

$probe = @file_get_contents($baseUrl . '/bai-01.html', false, stream_context_create([
    'http' => ['timeout' => 5, 'ignore_errors' => true],
]));

if ($probe === false) {
    seed_fail(
        "[LỖI] Không gọi được {$baseUrl}/bai-01.html.\n"
        . "       Hãy khởi động máy chủ thư mẫu trước:\n"
        . "         php -S localhost:8091 -t tests/samples"
    );
}

seed_out("Máy chủ thư mẫu phản hồi OK.");

/* --- Bảo đảm host thử nghiệm đã được duyệt (chỉ INSERT, idempotent) ----- */
$seedHost = (string) (parse_url($baseUrl, PHP_URL_HOST) ?? '');

if ($seedHost !== '') {
    $seedCheck = db()->prepare('SELECT COUNT(*) FROM `allowlist_domains` WHERE domain = ?');
    $seedCheck->execute([$seedHost]);

    if ((int) $seedCheck->fetchColumn() === 0) {
        $seedAdminId = (int) db()->query(
            "SELECT id FROM `users` WHERE role = 'admin' ORDER BY id LIMIT 1"
        )->fetchColumn();

        if ($seedAdminId > 0) {
            $seedInsert = db()->prepare(
                'INSERT INTO `allowlist_domains` (`domain`, `note`, `added_by`) VALUES (?, ?, ?)'
            );
            $seedInsert->execute([
                $seedHost,
                'Máy chủ thử nghiệm cục bộ (scripts/seed_samples.php)',
                $seedAdminId,
            ]);

            seed_out("[+] Đã duyệt host thử nghiệm: {$seedHost}");
        }
    }
}

seed_out('');

/* =============================================================================
    PHẦN 6 — TÌM / TẠO THƯ VIỆN ĐÍCH
    ============================================================================= */

/**
 * Tìm thư viện đã tồn tại theo tên (bỏ qua quyền truy cập — đây là CLI của
 * chính dự án, dùng tài khoản ứng dụng).
 */
function seed_find_library_by_name(string $name, int $ownerId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, name, slug, owner_id FROM `libraries`
          WHERE name = ? AND owner_id = ? ORDER BY id LIMIT 1'
    );
    $stmt->execute([$name, $ownerId]);

    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Đếm số trang mẫu đã có trong thư viện (đánh giá theo tên tệp trong URL).
 */
function seed_count_sample_pages(int $libraryId, array $files): int
{
    $stmt = db()->prepare(
        'SELECT source_url FROM `saved_pages` WHERE library_id = ?'
    );
    $stmt->execute([$libraryId]);

    $urls = array_column($stmt->fetchAll(), 'source_url');
    $count = 0;

    foreach ($files as $file) {
        foreach ($urls as $url) {
            if (str_ends_with((string) $url, '/' . $file)) {
                $count++;
                break;
            }
        }
    }

    return $count;
}

$existing = seed_find_library_by_name($libraryName, $ownerId);

if ($existing !== null) {
    $libraryId = (int) $existing['id'];
    $slug      = (string) $existing['slug'];

    $present = seed_count_sample_pages($libraryId, array_column($samplePlan, 'file'));

    if (!$force && $present >= count($samplePlan)) {
        seed_out("Thư viện «{$libraryName}» đã tồn tại (id={$libraryId}, slug={$slug}).");
        seed_out("Đã có sẵn {$present}/" . count($samplePlan) . ' trang mẫu — KHÔNG nạp lại.');
        seed_out('already seeded');
        exit(0);
    }

    if ($force) {
        // --force nghĩa là NẠP LẠI TỪ ĐẦU: xoá thư viện cũ (kèm thư mục ảnh)
        // rồi tạo lại. Việc xoá đi qua đúng hàm xoá của ứng dụng
        // (wa_library_delete) — TUYỆT ĐỐI không xoá tay tệp trong public/uploads/.
        // Nếu chỉ nạp chồng lên, mỗi lần chạy lại sẽ sinh thêm phiên bản mới
        // (bai-05 vốn đã có 2 phiên bản sẽ thành 3, 4...) và dữ liệu mẫu sẽ
        // không còn khớp với bảng kê.
        seed_out("Chế độ --force: xoá thư viện «{$libraryName}» cũ (id={$libraryId}) rồi nạp lại.");

        // Diễn giả cho hàm xoá của ứng dụng: chính chủ sở hữu (owner_id khớp
        // $ownerId) nên hàm sẽ cho phép xoá.
        $deleted = wa_library_delete($libraryId, [
            'id'       => $ownerId,
            'role'     => 'user',
            'is_admin' => false,
        ]);

        if ($deleted['ok'] !== true) {
            seed_fail('[LỖI] Không xoá được thư viện cũ: ' . $deleted['message']);
        }

        seed_out(sprintf(
            'Đã xoá: %d trang, %d bản snapshot, %d thư mục ảnh, %d tệp.',
            (int) $deleted['pages'],
            (int) $deleted['snapshots'],
            (int) $deleted['dirs'],
            (int) $deleted['files']
        ));

        // Tạo lại thư viện từ đầu.
        /** @var array{ok:bool, message:string, library_id?:int, slug?:string} $library */
        $library = wa_library_create(
            $libraryName,
            'Bộ 15 trang mẫu dùng để trình diễn và kiểm thử tự động (P-FINAL).',
            'shared',
            'b',
            'science',
            $ownerId
        );

        if ($library['ok'] !== true) {
            seed_fail('[LỖI] Không tạo lại được thư viện đích: ' . $library['message']);
        }

        $libraryId = (int) $library['library_id'];
        $slug      = (string) $library['slug'];

        seed_out("Đã tạo lại thư viện «{$libraryName}» (id={$libraryId}, slug={$slug}).");
    } else {
        seed_out("Thư viện «{$libraryName}» đã tồn tại (id={$libraryId}, slug={$slug}), hiện có {$present}/" . count($samplePlan) . ' trang mẫu.');
        seed_out('Tiếp tục nạp các trang mẫu còn thiếu.');
    }
} else {
    /** @var array{ok:bool, message:string, library_id?:int, slug?:string} $library */
    $library = wa_library_create(
        $libraryName,
        'Bộ 15 trang mẫu dùng để trình diễn và kiểm thử tự động (P-FINAL).',
        'shared',
        'b',
        'science',
        $ownerId
    );

    if ($library['ok'] !== true) {
        seed_fail('[LỖI] Không tạo được thư viện đích: ' . $library['message']);
    }

    $libraryId = (int) $library['library_id'];
    $slug      = (string) $library['slug'];

    seed_out("Đã tạo thư viện «{$libraryName}» (id={$libraryId}, slug={$slug}).");
}

/* =============================================================================
    PHẦN 7 — NẠP 15 TRANG MẪU QUA ĐƯỜNG LƯU THẬT
    ============================================================================= */

seed_out('');
seed_out('Nạp 15 trang mẫu qua đường lưu trang thật (wa_store_page_capture):');
seed_out('');

$rows = [];

foreach ($samplePlan as $item) {
    $file = (string) $item['file'];
    $url  = $baseUrl . '/' . $file;
    $expect = (string) $item['expect'];

    $result = wa_store_page_capture($libraryId, $url, (array) $item['tags'], $ownerId);

    $ok       = (bool) $result['ok'];
    $status   = $ok ? 'success' : (string) $result['error_code'];
    $snapshot = (int) $result['snapshot_id'];
    $version  = (int) $result['version'];
    $imgCount = (int) $result['image_count'];
    $indexed  = (int) $result['indexed_terms'];

    $matches = ($status === $expect);

    $rows[] = [
        'file'    => $file,
        'expect'  => $expect,
        'actual'  => $status,
        'matches' => $matches,
        'version' => $version,
        'images'  => $imgCount,
        'terms'   => $indexed,
        'title'   => (string) $result['title'],
    ];

    printf(
        "  %-22s mong doi=%-16s thuc te=%-16s %s  v%d  %d anh  %d tu\n",
        $file,
        $expect,
        $status,
        $matches ? 'KHOP' : 'LECH ',
        $version,
        $imgCount,
        $indexed
    );
}

/* =============================================================================
    PHẦN 8 — TẠO PHIÊN BẢN 2 CHO BÀI 5 (demo lịch sử phiên bản)
    ============================================================================= */

seed_out('');
seed_out('Tạo phiên bản 2 cho bai-05.html (demo lịch sử phiên bản):');

$bai05Url   = $baseUrl . '/bai-05.html';
$secondPass = wa_store_page_capture($libraryId, $bai05Url, ['mau-webarchive', 'linux'], $ownerId);

printf(
    "  %-22s v%d  trang thái=%s  %d anh  %d tu\n",
    'bai-05.html',
    (int) $secondPass['version'],
    $secondPass['ok'] === true ? 'success' : (string) $secondPass['error_code'],
    (int) $secondPass['image_count'],
    (int) $secondPass['indexed_terms']
);

/* =============================================================================
    PHẦN 9 — TỔNG KẾT
    ============================================================================= */

$matched = 0;
$failed  = 0;

foreach ($rows as $row) {
    if ($row['matches']) {
        $matched++;
    } else {
        $failed++;
    }
}

seed_out('');
seed_out('─' . str_repeat('─', 78));
seed_out(sprintf('Tổng kết: %d/%d trang mẫu khớp với kết quả mong đợi.', $matched, count($rows)));
seed_out(sprintf('Thư viện: «%s» (id=%d, slug=%s)', $libraryName, $libraryId, $slug));
seed_out(sprintf('Mở tại:  /library.php?slug=%s', $slug));
seed_out('─' . str_repeat('─', 78));

if ($failed > 0) {
    seed_fail("[LỖI] Có {$failed} trang mẫu không khớp kết quả mong đợi.");
}

exit(0);
