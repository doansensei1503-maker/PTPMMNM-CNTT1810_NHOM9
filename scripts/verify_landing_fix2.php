<?php
/**
 * scripts/verify_landing_fix2.php — kiểm tra tiêu chí nghiệm thu P-FIX-LANDING-2.
 *
 * Phạm vi: CHỈ khối "Không gian tập trung" (id="khong-gian-doc") trên trang chủ.
 *   1. Khối này CĂN TRÁI, đồng bộ mép trái + nhịp tiêu đề/đoạn dẫn với các
 *      khối phía trên ("Giá trị cốt lõi", "Cách thức hoạt động trong 3 bước").
 *   2. Bên phải có MỘT minh hoạ TRẢI NGHIỆM ĐỌC (không phải sơ đồ quy trình).
 *   3. Mọi chữ copy cũ giữ nguyên; KHÔNG có sơ đồ quy trình hay thuật ngữ
 *      kỹ thuật trong minh hoạ.
 *
 * Chỉ ĐỌC: tải trang chủ qua HTTP (GET) và đối chiếu HTML trả về + CSS tĩnh.
 * KHÔNG ghi CSDL, KHÔNG chạy setup_db.php.
 *
 * Cách dùng:
 *   php -S 127.0.0.1:8899 -t public
 *   php scripts/verify_landing_fix2.php http://127.0.0.1:8899/index.php
 */
declare(strict_types=1);

$url = $argv[1] ?? 'http://127.0.0.1:8899/index.php';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
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

$sec = wa_section_by_id($html, 'khong-gian-doc');
check('Tìm thấy section "khong-gian-doc"', $sec !== '', 'không tìm thấy id="khong-gian-doc"');

if ($sec === '') {
    echo "\nKẾT QUẢ: CÓ KIỂM TRA KHÔNG ĐẠT.\n";
    exit(1);
}

/* ---------------------------------------------------------------------------
 * 1. BỐ CỤC HAI CỘT + CĂN TRÁI
 * ------------------------------------------------------------------------- */
echo "\n=== 1 — Bố cục hai cột, căn trái ===\n";

check(
    'Có cột chữ .showcase__text',
    substr_count($sec, 'showcase__text') === 1,
    'số lần xuất hiện showcase__text = ' . substr_count($sec, 'showcase__text')
);
check(
    'Có cột minh hoạ .showcase__art',
    substr_count($sec, 'showcase__art') === 1,
    'số lần xuất hiện showcase__art = ' . substr_count($sec, 'showcase__art')
);
check(
    'CSS định nghĩa lưới 12 cột cho .showcase',
    preg_match('/\.showcase\s*\{[^}]*grid-template-columns:\s*repeat\(12,/s', $css) === 1,
    'không thấy .showcase dùng repeat(12, ...)'
);
check(
    'Cột chữ chiếm span 5, cột minh hoạ span 7',
    preg_match('/\.showcase__text\s*\{[^}]*grid-column:\s*span 5/s', $css) === 1
        && preg_match('/\.showcase__art\s*\{[^}]*grid-column:\s*span 7/s', $css) === 1,
    'thiếu quy tắc span 5 / span 7'
);

// Không được dùng max-width + margin:auto để căn giữa cột chữ nữa.
check(
    'Cột chữ KHÔNG còn bị căn giữa bằng margin:auto',
    preg_match('/\.showcase__text\s*\{[^}]*margin:\s*0\s+auto/s', $css) !== 1,
    'cột chữ vẫn bị căn giữa'
);

// Không có thuộc tính text-align:center nào trong section.
$centered = preg_match_all('/text-align\s*:\s*center/i', $sec);
check(
    'KHÔNG có style text-align:center trong section',
    $centered === 0,
    "tìm thấy $centered khai báo text-align:center"
);
check(
    'CSS khối section không còn đặt text-align:center',
    preg_match('/\.showcase__text\s*\{[^}]*text-align\s*:\s*center/s', $css) !== 1,
    '.showcase__text vẫn căn giữa trong CSS'
);

/* ---------------------------------------------------------------------------
 * 2. MINH HOẠ TRẢI NGHIỆM ĐỌC Ở CỘT PHẢI
 * ------------------------------------------------------------------------- */
echo "\n=== 2 — Minh hoạ trải nghiệm đọc ===\n";

check(
    'Có thẻ minh hoạ .readpreview',
    str_contains($sec, 'class="readpreview"'),
    'không thấy .readpreview'
);
foreach ([
    'Thanh tiến độ đọc'     => 'readpreview__progress',
    'Mẫu trang đọc giấy ngà' => 'readpreview__paper',
    'Nút A- / A+'          => 'A-',
    'Nút A+ '              => 'A+',
    'Đổi nền đọc'          => 'readpreview__foot',
    'Nhãn tiến độ đọc'     => 'Tiến độ đọc',
] as $label => $needle) {
    check(
        "Minh hoạ có $label",
        str_contains($sec, $needle),
        "không thấy '$needle'"
    );
}

// Dùng phông chữ báo in (Merriweather) như trình đọc thật.
check(
    'Mẫu trang đọc dùng phông chữ báo in (--wa-font-reader)',
    preg_match('/\.readpreview__(text|title)[^{]*\{[^}]*font-family:\s*var\(--wa-font-reader\)/s', $css) === 1,
    'không thấy font-family: var(--wa-font-reader) cho .readpreview'
);
// Nền giấy ngà.
check(
    'Minh hoạ dùng nền giấy ngà (--wa-ivory)',
    preg_match('/\.readpreview\s*\{[^}]*background:\s*var\(--wa-ivory\)/s', $css) === 1,
    'không thấy background: var(--wa-ivory)'
);
// Không dùng ảnh ngoài / ảnh sinh bằng AI.
check(
    'Minh hoạ KHÔNG dùng ảnh ngoài',
    preg_match('/<img\b/i', $sec) === 0,
    'còn sót thẻ <img> trong section'
);
check(
    'Minh hoạ KHÔNG tham chiếu tệp SVG ảnh',
    preg_match('/<svg\b/i', $sec) === 0,
    'còn sót thẻ <svg> trong section'
);

/* ---------------------------------------------------------------------------
 * 3. KHÔNG CÓ SƠ ĐỒ QUY TRÌNH / THUẬT NGỮ KỸ THUẬT
 * ------------------------------------------------------------------------- */
echo "\n=== 3 — Không có sơ đồ quy trình, không có thuật ngữ kỹ thuật ===\n";

// Lấy phần CHỮ của minh hoạ (bỏ qua markup/comment) để dò thuật ngữ.
$artStart = strpos($sec, 'showcase__art');
$artHtml  = $artStart !== false ? substr($sec, $artStart) : '';
$artText  = html_entity_decode(strip_tags($artHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');

foreach ([
    'Trang Web', 'HTML tĩnh', 'HTML tinh', 'MySQL', 'Đọc lại ngoại tuyến',
    'allowlist', 'danh sách tên miền', 'snapshot', 'SNAP-', 'pipeline',
    'workflow', 'sơ đồ', 'kiến trúc', 'máy chủ', 'cơ sở dữ liệu', 'CSDL',
] as $needle) {
    check(
        "Minh hoạ KHÔNG chứa thuật ngữ '$needle'",
        stripos($artText, $needle) === false,
        "còn sót '$needle'"
    );
}
check(
    'Minh hoạ KHÔNG có từ khoá HTML',
    stripos($artText, 'HTML') === false,
    'còn sót chữ HTML trong minh hoạ'
);
check(
    'Minh hoạ KHÔNG có từ khoá "lưu trữ"/"bản lưu"/"trích xuất"',
    stripos($artText, 'lưu trữ') === false
        && stripos($artText, 'bản lưu') === false
        && stripos($artText, 'trích xuất') === false,
    'minh hoạ nói về lưu trữ/bản lưu/trích xuất thay vì trải nghiệm đọc'
);

/* ---------------------------------------------------------------------------
 * 4. GIỮ NGUYÊN TOÀN BỘ COPY CŨ
 * ------------------------------------------------------------------------- */
echo "\n=== 4 — Giữ nguyên copy cũ của section ===\n";

$copy = [
    'Kicker'                 => 'Không gian tập trung',
    'Tiêu đề'               => 'Đọc theo cách mà mắt bạn xứng đáng được trải nghiệm',
    'Mở đầu mô tả'          => 'Được thiết kế riêng cho những ai dành hàng giờ đắm mình trong các bài luận dài.',
    'Mô tả (vế sau)'        => 'Tùy chỉnh màu giấy ngà dịu mắt, phông chữ báo in kinh điển và theo dõi tiến độ đọc theo thời gian thực.',
    'Checklist 1'           => 'Tự chủ dữ liệu hoàn toàn',
    'Checklist 1 (vế sau)'  => 'Toàn bộ dữ liệu nằm trên máy chủ nội bộ hoặc cơ sở dữ liệu riêng của bạn.',
    'Checklist 2'           => 'Xuất dữ liệu mở định dạng tiêu chuẩn',
    'Checklist 2 (vế sau)'  => 'Dễ dàng tải về bản nén ZIP chứa file HTML đơn lập và thư mục ảnh đính kèm.',
    'Liên kết'              => 'Trải nghiệm không gian đọc mẫu',
];

// Bản thân copy nằm trên nhiều dòng trong mã nguồn, nên so khớp trên bản
// đã GỘP KHOẢNG TRẮNG — nếu không, câu văn bị ngắt dòng sẽ bị coi là mất copy.
$secFlat = preg_replace('/\s+/u', ' ', $sec);

foreach ($copy as $label => $needle) {
    check(
        "Giữ nguyên $label",
        str_contains($secFlat, $needle),
        "không thấy '$needle'"
    );
}

// Thứ tự trong cột chữ: kicker → heading → mô tả → checklist → liên kết.
$order = ['Không gian tập trung', 'section-title', 'section-sub', 'checklist', 'Trải nghiệm không gian đọc mẫu'];
$pos   = [];
$last  = -1;
$ordered = true;
foreach ($order as $needle) {
    $at = strpos($sec, $needle);
    if ($at === false || $at < $last) {
        $ordered = false;
        break;
    }
    $pos[]  = $at;
    $last   = $at;
}
check(
    'Thứ tự cột chữ: kicker → tiêu đề → mô tả → checklist → liên kết',
    $ordered,
    'thứ tự phần tử trong cột chữ không đúng'
);

/* ---------------------------------------------------------------------------
 * 5. KHÔNG ĐỤNG PHẦN KHÁC CỦA TRANG CHỦ
 * ------------------------------------------------------------------------- */
echo "\n=== 5 — Không đụng phần khác của trang chủ ===\n";
check('Hero headline còn nguyên', str_contains($html, 'Lưu tri thức hôm nay.'));
check('CTA hero còn nguyên', str_contains($html, 'Bắt đầu lưu bài viết'));
check('Mock bản lưu mẫu còn nguyên', str_contains($html, 'Mã kiểm chứng: sha256:'));
check('Khối "Giá trị cốt lõi" còn nguyên', str_contains($html, 'Giá trị cốt lõi'));
check('Khối 3 bước còn nguyên', str_contains($html, 'Cách thức hoạt động trong 3 bước'));
check('CTA cuối còn nguyên', str_contains($html, 'Khởi tạo kho tàng số'));
check('Footer còn nguyên', str_contains($html, 'Đồ án môn Lập trình Nhóm 9'));

echo "\n" . ($fail === 0
    ? 'KẾT QUẢ: TẤT CẢ KIỂM TRA ĐẠT.'
    : "KẾT QUẢ: CÓ $fail KIỂM TRA KHÔNG ĐẠT.") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
