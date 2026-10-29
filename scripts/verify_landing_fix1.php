<?php
/**
 * scripts/verify_landing_fix1.php — kiểm tra 3 tiêu chí nghiệm thu P-FIX-LANDING-1.
 *
 * Chỉ ĐỌC: tải trang chủ qua HTTP (GET) và đối chiếu HTML trả về + CSS tĩnh.
 * KHÔNG ghi CSDL, KHÔNG chạy setup_db.php.
 *
 * Cách dùng:
 *   php -S 127.0.0.1:8899 -t public
 *   php scripts/verify_landing_fix1.php http://127.0.0.1:8899/index.php
 */
declare(strict_types=1);

$url = $argv[1] ?? 'http://127.0.0.1:8899/index.php';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
]);
$html = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($html === false) {
    fwrite(STDERR, "LỖI tải trang: $err\n");
    exit(1);
}

$css = (string) @file_get_contents(__DIR__ . '/../public/assets/css/app.css');

$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail;
    if ($ok) {
        echo '[PASS] ' . $label . PHP_EOL;
        return;
    }
    $fail++;
    echo '[FAIL] ' . $label . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
}

/**
 * Cắt CHÍNH XÁC một khối <section> theo id (không tràn sang section kế tiếp).
 */
function wa_section_by_id(string $html, string $id): string
{
    $open = strpos($html, 'id="' . $id . '"');
    if ($open === false) {
        return '';
    }
    $tagStart = strrpos(substr($html, 0, $open), '<section');
    if ($tagStart === false) {
        return '';
    }
    $depth = 0;
    $len = strlen($html);
    for ($i = $tagStart; $i < $len; $i++) {
        if (str_starts_with(substr($html, $i, 9), '<section')) {
            $depth++;
            $i += 8;
            continue;
        }
        if (str_starts_with(substr($html, $i, 10), '</section>')) {
            $depth--;
            if ($depth === 0) {
                return substr($html, $tagStart, $i + 10 - $tagStart);
            }
            $i += 9;
        }
    }
    return '';
}

echo "=== HTTP ===\n";
check('Trang chủ trả về status 200', $status === 200, "status thực tế = $status");

// --- Hero: section đầu tiên, cắt riêng theo class. ---
$hStart = strpos($html, '<section class="hero">');
$hEnd = $hStart !== false ? strpos($html, '</section>', $hStart) : false;
$hero = ($hStart !== false && $hEnd !== false)
    ? substr($html, $hStart, $hEnd - $hStart + 10)
    : '';

echo "\n=== ITEM 1 — bỏ nhãn phiên bản khỏi hero ===\n";
check('Hero có markup', $hero !== '', 'không tìm thấy thẻ <section class="hero">');
check(
    'Hero KHÔNG chứa chuỗi "Phiên bản"',
    stripos($hero, 'phiên bản') === false && stripos($hero, 'phien ban') === false,
    'còn sót chuỗi phiên bản trong hero'
);
check(
    'Hero KHÔNG chứa số phiên bản (vN.N[.N] hoặc N.N.N)',
    preg_match('/\bv\d+(?:\.\d+)+/i', $hero) === 0 && preg_match('/\b\d+\.\d+\.\d+\b/', $hero) === 0,
    'còn sót số phiên bản trong hero'
);
check(
    'Hero VẪN giữ pill lợi ích (chỉ tagline)',
    str_contains($hero, 'hero__badge') && str_contains($hero, 'Đọc ngoại tuyến an toàn'),
    'thiếu pill tagline'
);
check(
    'Toàn trang KHÔNG còn chuỗi "Phiên bản v1.0.0"',
    str_contains($html, 'Phiên bản v1.0.0') === false,
    'còn sót chuỗi Phiên bản v1.0.0'
);

echo "\n=== ITEM 2 — tiêu đề 'Giá trị cốt lõi' một khối căn trái ===\n";
$sec2 = wa_section_by_id($html, 'tinh-nang');
check('Tìm thấy section tính năng', $sec2 !== '', 'không tìm thấy id="tinh-nang"');
check(
    'Không còn lớp hai cột section-head--split',
    str_contains($sec2, 'section-head--split') === false,
    'còn sót section-head--split'
);
check(
    'Tiêu đề dùng khối .section-head đơn',
    str_contains($sec2, 'class="section-head"'),
    'không thấy class="section-head"'
);
check('Có kicker "Giá trị cốt lõi"', str_contains($sec2, 'Giá trị cốt lõi'));
check(
    'Có heading "Được thiết kế cho người nghiên cứu sâu"',
    str_contains($sec2, 'Được thiết kế cho người nghiên cứu sâu')
);
check(
    'Có đoạn giới thiệu (nội dung giữ nguyên)',
    str_contains($sec2, 'Không chỉ đánh dấu trang (bookmark). WebArchive lưu trữ nguyên vẹn văn bản và tệp hình ảnh')
);
check(
    'Không còn <p class="section-sub"> mồ côi ngoài khối .section-head',
    preg_match('/<\/div>\s*<p class="section-sub"/', $sec2) === 0,
    'còn đoạn section-sub nằm ngoài khối header'
);
$headStart = strpos($sec2, '<div class="section-head">');
$headEnd = $headStart !== false ? strpos($sec2, '</div>', $headStart) : false;
$headBlock = ($headStart !== false && $headEnd !== false)
    ? substr($sec2, $headStart, $headEnd - $headStart)
    : '';
check(
    'Thứ tự trong khối header: kicker → heading → đoạn giới thiệu',
    $headBlock !== ''
        && strpos($headBlock, 'Giá trị cốt lõi') < strpos($headBlock, 'section-title')
        && strpos($headBlock, 'section-title') < strpos($headBlock, 'section-sub'),
    'thứ tự phần tử trong khối header không đúng'
);
check(
    'CSS không còn quy tắc .section-head--split',
    str_contains($css, 'section-head--split') === false,
    'app.css còn quy tắc section-head--split'
);
check(
    'Ba thẻ tính năng còn nguyên',
    str_contains($sec2, 'Thư viện ngăn nắp')
        && str_contains($sec2, 'Lưu nội dung an toàn')
        && str_contains($sec2, 'Đọc lại độc lập')
);

echo "\n=== ITEM 3 — sơ đồ quy trình KHÔNG quay lại 'Không gian tập trung' ===\n";
$sec3 = wa_section_by_id($html, 'khong-gian-doc');
check('Tìm thấy section không gian đọc', $sec3 !== '', 'không tìm thấy id="khong-gian-doc"');
check(
    'KHÔNG còn tham chiếu reader-diagram.svg',
    str_contains($sec3, 'reader-diagram.svg') === false,
    'còn sót tham chiếu reader-diagram.svg'
);
check(
    'KHÔNG còn ảnh nào trong section (không có sơ đồ thay thế)',
    preg_match('/<img\b/i', $sec3) === 0,
    'còn sót thẻ <img> trong section'
);
foreach (['Trang Web', 'HTML tinh', 'HTML tĩnh', 'MySQL', 'Doc lai', 'Đọc lại ngoại tuyến', 'allowlist', 'danh sach ten mien', 'danh sách tên miền', 'snapshot'] as $needle) {
    check(
        "KHÔNG còn chuỗi sơ đồ '$needle'",
        stripos($sec3, $needle) === false,
        "còn sót chuỗi '$needle'"
    );
}
check(
    'Giữ nguyên tiêu đề cột chữ',
    str_contains($sec3, 'Đọc theo cách mà mắt bạn xứng đáng được trải nghiệm')
);
check(
    'Giữ nguyên mô tả',
    str_contains($sec3, 'Được thiết kế riêng cho những ai dành hàng giờ đắm mình trong các bài luận dài.')
);
check(
    'Giữ nguyên 2 mục checklist',
    str_contains($sec3, 'Tự chủ dữ liệu hoàn toàn')
        && str_contains($sec3, 'Xuất dữ liệu mở định dạng tiêu chuẩn')
);
check(
    'Giữ nguyên liên kết "Trải nghiệm không gian đọc mẫu"',
    str_contains($sec3, 'Trải nghiệm không gian đọc mẫu')
);

echo "\n=== KHÔNG đụng phần khác của trang chủ ===\n";
check('Hero headline còn nguyên', str_contains($html, 'Lưu tri thức hôm nay.'));
check('CTA hero còn nguyên', str_contains($html, 'Bắt đầu lưu bài viết'));
check('Mock bản lưu mẫu còn nguyên', str_contains($html, 'Mã kiểm chứng: sha256:'));
check('Khối 3 bước còn nguyên', str_contains($html, 'Cách thức hoạt động trong 3 bước'));
check('CTA cuối còn nguyên', str_contains($html, 'Khởi tạo kho tàng số'));
check('Footer còn nguyên', str_contains($html, 'Đồ án môn Lập trình Nhóm 9'));

echo "\n" . ($fail === 0
    ? 'KẾT QUẢ: TẤT CẢ KIỂM TRA ĐẠT.'
    : "KẾT QUẢ: CÓ $fail KIỂM TRA KHÔNG ĐẠT.") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
