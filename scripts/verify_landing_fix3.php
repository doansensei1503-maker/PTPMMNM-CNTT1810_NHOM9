<?php
/**
 * scripts/verify_landing_fix3.php — kiểm tra tiêu chí nghiệm thu P-FIX-LANDING-3.
 *
 * Phạm vi: CHỈ khối "Không gian tập trung" (id="khong-gian-doc") trên trang chủ.
 *   1. Khe hở giữa cột chữ và thẻ minh hoạ RỘNG HƠN rõ rệt (trước: 24px).
 *   2. Hai cột THẲNG MÉP TRÊN (align-items: start, không còn căn giữa theo trục dọc).
 *   3. Mép ngoài của khối không vượt quá mép của các khối phía trên; mép trái
 *      vẫn thẳng với khối "Giá trị cốt lõi" và "Cách thức hoạt động trong 3 bước".
 *   4. Cả HAI cột tự thu hẹp (lưới 12 cột chia đều + khe hở lớn hơn).
 *   5. Copy, minh hoạ trình đọc, số liệu và mọi section khác giữ nguyên.
 *
 * Chỉ ĐỌC: tải trang chủ qua HTTP (GET) và đối chiếu HTML trả về + CSS tĩnh.
 * KHÔNG ghi CSDL, KHÔNG chạy setup_db.php.
 *
 * Cách dùng:
 *   php -S 127.0.0.1:8899 -t public
 *   php scripts/verify_landing_fix3.php http://127.0.0.1:8899/index.php
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

/**
 * Lấy nội dung TẤT CẢ các rule CSS có selector chứa $selector.
 * Trả về mảng các khối nội dung rule (theo thứ tự xuất hiện trong file).
 */
function wa_css_rules(string $css, string $selector): array
{
    $pattern = '/(?:^|[}\s])[^{}]*' . preg_quote($selector, '/') . '[^{}]*\{([^}]*)\}/s';
    if (preg_match_all($pattern, $css, $m) !== 1 && $m[1] === []) {
        return [];
    }
    return $m[1];
}

/**
 * Lấy nội dung rule CSS có selector chứa $selector, nằm trong @media có
 * điều kiện chứa $needle. Duyệt TẤT CẢ các @media khớp (không chỉ cái đầu).
 */
function wa_css_rule_in_media(string $css, string $selector, string $needle): string
{
    // Cắt CSS thành từng khối @media cân bằng dấu ngoặc.
    $offset = 0;
    $len = strlen($css);
    while (($pos = strpos($css, '@media', $offset)) !== false) {
        $brace = strpos($css, '{', $pos);
        if ($brace === false) {
            break;
        }
        $depth = 0;
        $end = $brace;
        for ($i = $brace; $i < $len; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $prelude = substr($css, $pos, $brace - $pos);
        $block = substr($css, $brace + 1, $end - $brace - 1);
        if (str_contains($prelude, $needle)) {
            foreach (wa_css_rules($block, $selector) as $rule) {
                return $rule;
            }
        }
        $offset = $end + 1;
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
 * 1. HAI CỘT THẲNG MÉP TRÊN
 * ------------------------------------------------------------------------- */
echo "\n=== 1 — Hai cột thẳng mép trên ===\n";

$baseRule = implode("\n", wa_css_rules($css, '.showcase'));
check(
    '.showcase dùng align-items: start (không còn căn giữa theo trục dọc)',
    preg_match('/align-items\s*:\s*start\s*;/', $baseRule) === 1,
    'không thấy align-items: start trong rule .showcase'
);
check(
    '.showcase KHÔNG còn align-items: center',
    preg_match('/align-items\s*:\s*center\s*;/', $baseRule) !== 1,
    '.showcase vẫn căn giữa theo trục dọc'
);

/* ---------------------------------------------------------------------------
 * 2. KHE HỞ GIỮA HAI CỘT RỘNG HƠN RÕ RỆT
 * ------------------------------------------------------------------------- */
echo "\n=== 2 — Khe hở giữa hai cột rộng hơn ===\n";

$desktopRule = wa_css_rule_in_media($css, '.showcase', 'min-width: 1024px');
check(
    'Tìm thấy rule .showcase trong @media (min-width: 1024px)',
    $desktopRule !== '',
    'không thấy rule .showcase trong media query 1024px'
);

$gap = null;
if (preg_match('/column-gap\s*:\s*clamp\(\s*(\d+)px\s*,\s*[\d.]+vw\s*,\s*(\d+)px\s*\)/', $desktopRule, $m) === 1) {
    $gap = (int) $m[2];
}
check(
    'Khe hở cột dùng clamp() với trần >= 40px',
    $gap !== null && $gap >= 40,
    'không tìm thấy column-gap dạng clamp() hợp lệ'
);
check(
    'Khe hở cột RỘNG HƠN nhiều so với 24px trước đây',
    $gap !== null && $gap >= 40,
    "khe hở tối đa = " . ($gap ?? 'không rõ') . 'px'
);
check(
    'Rule .showcase cũ không còn khai báo column-gap: 24px cứng',
    preg_match('/column-gap\s*:\s*24px\s*;/', $css) !== 1,
    'vẫn còn column-gap: 24px cứng'
);

/* ---------------------------------------------------------------------------
 * 3. HAI CỘT TỰ THU HẸP + MÉP NGOÀI KHÔNG VƯỢT
 * ------------------------------------------------------------------------- */
echo "\n=== 3 — Cột thu hẹp, mép ngoài đúng lưới ===\n";

check(
    'Lưới vẫn là 12 cột chia đều (repeat(12, minmax(0, 1fr)))',
    preg_match('/grid-template-columns\s*:\s*repeat\(12,\s*minmax\(0,\s*1fr\)\)/', $desktopRule) === 1,
    'không thấy lưới 12 cột chia đều'
);
check(
    'Cột chữ vẫn span 5, cột minh hoạ vẫn span 7',
    preg_match('/\.showcase__text\s*\{[^}]*grid-column:\s*span 5/s', $css) === 1
        && preg_match('/\.showcase__art\s*\{[^}]*grid-column:\s*span 7/s', $css) === 1,
    'thiếu quy tắc span 5 / span 7'
);

$padRight = null;
if (preg_match('/padding-right\s*:\s*(\d+)px\s*;/', $desktopRule, $m) === 1) {
    $padRight = (int) $m[1];
}
check(
    'Mép phải của lưới lùi vào trong (padding-right >= 16px)',
    $padRight !== null && $padRight >= 16,
    'không thấy padding-right trên .showcase'
);
check(
    'Mép trái KHÔNG bị dịch (không có padding-left trên .showcase)',
    preg_match('/padding-left\s*:/', $desktopRule) !== 1,
    '.showcase bị thêm padding-left — mép trái phải thẳng với khối phía trên'
);

check(
    'Cột chữ KHÔNG bị căn giữa bằng margin:auto',
    preg_match('/\.showcase__text\s*\{[^}]*margin:\s*0\s+auto/s', $css) !== 1,
    'cột chữ vẫn bị căn giữa'
);
check(
    'Cột chữ KHÔNG bị đặt max-width (phải tự thu hẹp theo lưới)',
    preg_match('/\.showcase__text\s*\{[^}]*max-width\s*:/s', $css) !== 1,
    'cột chữ vẫn bị giới hạn bằng max-width'
);

/* ---------------------------------------------------------------------------
 * 4. MINH HOẠ TRÌNH ĐỌC GIỮ NGUYÊN
 * ------------------------------------------------------------------------- */
echo "\n=== 4 — Minh hoạ trình đọc giữ nguyên ===\n";

check(
    'Thẻ minh hoạ .readpreview còn nguyên',
    str_contains($sec, 'class="readpreview"'),
    'không thấy .readpreview'
);
foreach ([
    'Thanh tiến độ đọc'     => 'readpreview__progress',
    'Mẫu trang đọc giấy ngà' => 'readpreview__paper',
    'Nút A- / A+'          => 'A-',
    'Đổi nền đọc'          => 'readpreview__foot',
    'Tiêu đề mẫu'          => 'Đọc chậm, đọc sâu',
    'Nhãn tiến độ đọc'     => 'Tiến độ đọc',
] as $label => $needle) {
    check(
        "Minh hoạ còn $label",
        str_contains($sec, $needle),
        "không thấy '$needle'"
    );
}
check(
    'Minh hoạ KHÔNG dùng ảnh ngoài',
    preg_match('/<img\b/i', $sec) === 0,
    'còn sót thẻ <img> trong section'
);
check(
    'Minh hoạ KHÔNG tham chiếu tệp SVG',
    preg_match('/<svg\b/i', $sec) === 0,
    'còn sót thẻ <svg> trong section'
);
// Các rule CSS riêng của minh hoạ PHẢI GIỮ NGUYÊN SO VỚI HEAD — vòng 3 chỉ
// được đụng rule .showcase. Đối chiếu trực tiếp với bản trong git.
$headCss = '';
$headCssLines = [];
// Không dùng chuyển hướng "2>/dev/null" (không hợp lệ trên Windows).
$headCssCmd = 'git -C ' . escapeshellarg(dirname(__DIR__)) . ' show HEAD:public/assets/css/app.css';
exec($headCssCmd, $headCssLines, $headExit);
if ($headExit === 0) {
    $headCss = implode("\n", $headCssLines);
}
check(
    'Đọc được bản app.css trong HEAD để đối chiếu',
    $headCss !== '',
    'không đọc được HEAD:public/assets/css/app.css'
);
if ($headCss !== '') {
    $headPreview = implode("\n", wa_css_rules($headCss, '.readpreview'));
    $nowPreview  = implode("\n", wa_css_rules($css, '.readpreview'));
    check(
        'Toàn bộ rule .readpreview GIỮ NGUYÊN so với HEAD (không đụng minh hoạ)',
        trim($headPreview) === trim($nowPreview),
        'rule .readpreview đã thay đổi so với HEAD'
    );

    // public/index.php phải BẤT BIẾN so với HEAD (copy + markup của section
    // không được đổi một ký tự nào trong vòng này).
    $idxLines = [];
    $idxExit = 1;
    exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' diff --quiet HEAD -- public/index.php', $idxLines, $idxExit);
    check(
        'public/index.php BẤT BIẾN so với HEAD (không đổi copy/markup)',
        $idxExit === 0,
        'public/index.php đã thay đổi so với HEAD'
    );
}
check(
    'Minh hoạ vẫn dùng nền giấy ngà (--wa-ivory)',
    preg_match('/\.readpreview\s*\{[^}]*background:\s*var\(--wa-ivory\)/s', $css) === 1,
    'không thấy background: var(--wa-ivory)'
);

/* ---------------------------------------------------------------------------
 * 5. COPY GIỮ NGUYÊN TUYỆT ĐỐI
 * ------------------------------------------------------------------------- */
echo "\n=== 5 — Copy giữ nguyên tuyệt đối ===\n";

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
$last  = -1;
$ordered = true;
foreach ($order as $needle) {
    $at = strpos($sec, $needle);
    if ($at === false || $at < $last) {
        $ordered = false;
        break;
    }
    $last = $at;
}
check(
    'Thứ tự cột chữ: kicker → tiêu đề → mô tả → checklist → liên kết',
    $ordered,
    'thứ tự phần tử trong cột chữ không đúng'
);

/* ---------------------------------------------------------------------------
 * 6. KHÔNG ĐỤNG PHẦN KHÁC CỦA TRANG CHỦ
 * ------------------------------------------------------------------------- */
echo "\n=== 6 — Không đụng phần khác của trang chủ ===\n";

check('Hero headline còn nguyên', str_contains($html, 'Lưu tri thức hôm nay.'));
check('CTA hero còn nguyên', str_contains($html, 'Bắt đầu lưu bài viết'));
check('Mock bản lưu mẫu còn nguyên', str_contains($html, 'Mã kiểm chứng: sha256:'));
check('Khối "Giá trị cốt lõi" còn nguyên', str_contains($html, 'Giá trị cốt lõi'));
check('Khối 3 bước còn nguyên', str_contains($html, 'Cách thức hoạt động trong 3 bước'));
check('CTA cuối còn nguyên', str_contains($html, 'Khởi tạo kho tàng số'));
check('Footer còn nguyên', str_contains($html, 'Đồ án môn Lập trình Nhóm 9'));

// Lưới của các section KHÁC phải giữ nguyên (vòng 3 chỉ đụng .showcase).
check(
    'Lưới 3 thẻ tính năng giữ nguyên repeat(3, ...)',
    preg_match('/\.feature-grid\s*\{[^}]*repeat\(3,/s', $css) === 1,
    'lưới .feature-grid đã bị đổi'
);
check(
    'Lưới 3 bước giữ nguyên repeat(3, ...)',
    preg_match('/\.steps-grid\s*\{[^}]*repeat\(3,/s', $css) === 1,
    'lưới .steps-grid đã bị đổi'
);
check(
    'Panel 3 bước giữ nguyên padding gốc',
    preg_match('/\.steps-panel\s*\{[^}]*padding:\s*var\(--wa-space-lg\)/s', $css) === 1,
    '.steps-panel đã bị đổi'
);
check(
    'Khối tiêu đề "Giá trị cốt lõi" giữ nguyên (không còn split)',
    str_contains($html, 'section-head--split') === false,
    'còn sót section-head--split'
);

echo "\n" . ($fail === 0
    ? 'KẾT QUẢ: TẤT CẢ KIỂM TRA ĐẠT.'
    : "KẾT QUẢ: CÓ $fail KIỂM TRA KHÔNG ĐẠT.") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
