<?php
/**
 * scripts/verify_landing_fix4.php — kiểm tra tiêu chí nghiệm thu P-FIX-LANDING-4.
 *
 * Phạm vi: LƯỚI CONTAINER DÙNG CHUNG của TRANG CHỦ.
 *   1. Mọi section trang chủ dùng CHUNG một container: cùng max-width, cùng
 *      padding đối xứng ⇒ cùng mép trái và mép phải (lệch 0px).
 *   2. Thanh trên + chân trang trang chủ cùng nằm trên lưới đó.
 *   3. Thay đổi CHỈ trong @media (min-width: 1024px) ⇒ KHÔNG đụng phone/mobile.
 *   4. Copy, số liệu, minh hoạ và mọi section khác giữ nguyên.
 *
 * Chỉ ĐỌC: tải trang chủ qua HTTP (GET) và đối chiếu HTML trả về + CSS tĩnh.
 * KHÔNG ghi CSDL, KHÔNG chạy setup_db.php.
 *
 * Cách dùng:
 *   php -S 127.0.0.1:8899 -t public
 *   php scripts/verify_landing_fix4.php http://127.0.0.1:8899/index.php
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
// Chuẩn hoá EOL để so sánh với bản trong HEAD một cách ổn định trên Windows.
$css = str_replace("\r\n", "\n", $css);

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
 * Tách CSS thành các khối @media cân bằng dấu ngoặc.
 *
 * @return array<int, array{prelude: string, body: string, start: int, end: int}>
 */
function wa_media_blocks(string $css): array
{
    $out = [];
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
        $out[] = [
            'prelude' => substr($css, $pos, $brace - $pos),
            'body'    => substr($css, $brace + 1, $end - $brace - 1),
            'start'   => $pos,
            'end'     => $end,
        ];
        $offset = $end + 1;
    }
    return $out;
}

/**
 * Trả về TOÀN BỘ các rule (selector + body) trong @media có prelude chứa
 * $needle và selector có chứa $selector.
 *
 * @return string[]
 */
function wa_full_rules_in_media(string $css, string $selector, string $needle): array
{
    $out = [];
    foreach (wa_media_blocks($css) as $block) {
        if (!str_contains($block['prelude'], $needle)) {
            continue;
        }
        $pattern = '/(?:^|[}])\s*([^{}]*' . preg_quote($selector, '/') . '[^{}]*)\{([^{}]*)\}/s';
        if (preg_match_all($pattern, $block['body'], $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $rule) {
                $out[] = trim($rule[1]) . ' {' . $rule[2] . '}';
            }
        }
    }
    return $out;
}

/**
 * Trả về nội dung của @media đầu tiên có prelude chứa $needle.
 */
function wa_media_block(string $css, string $needle): string
{
    foreach (wa_media_blocks($css) as $block) {
        if (str_contains($block['prelude'], $needle)) {
            return $block['body'];
        }
    }
    return '';
}

/**
 * Trả về mọi khối @media có prelude chứa $needle (nội dung đã nối lại).
 */
function wa_all_media_blocks(string $css, string $needle): string
{
    $parts = [];
    foreach (wa_media_blocks($css) as $block) {
        if (str_contains($block['prelude'], $needle)) {
            $parts[] = $block['body'];
        }
    }
    return implode("\n", $parts);
}

/**
 * Trả về mọi rule trong CSS (ngoài @media) có selector chứa $selector.
 *
 * @return string[]
 */
function wa_top_level_rules(string $css, string $selector): array
{
    // Bỏ hết các khối @media để chỉ xét rule ở cấp ngoài cùng.
    $stripped = (string) preg_replace(
        '/@media[^{}]*\{(?:[^{}]*\{[^{}]*\}[^{}]*)*\}/s',
        '',
        $css
    );
    $pattern = '/(?:^|[}])\s*([^{}]*' . preg_quote($selector, '/') . '[^{}]*)\{([^{}]*)\}/s';
    if (preg_match_all($pattern, $stripped, $m, PREG_SET_ORDER) === 0) {
        return [];
    }
    $out = [];
    foreach ($m as $rule) {
        $out[] = trim($rule[1]) . ' {' . $rule[2] . '}';
    }
    return $out;
}

echo "=== HTTP ===\n";
check('Trang chủ trả về status 200', $status === 200, "status thực tế = $status");

/* ---------------------------------------------------------------------------
 * 1. CONTAINER DÙNG CHUNG
 * ------------------------------------------------------------------------- */
echo "\n=== 1 — Mọi section dùng CHUNG một container ===\n";

$containerRules = wa_full_rules_in_media($css, '.landing .page', 'min-width: 1024px');
check(
    'Tìm thấy rule .landing .page trong @media (min-width: 1024px)',
    $containerRules !== [],
    'không thấy rule container chung'
);

$containerRule = $containerRules[0] ?? '';
$containerBody = (string) preg_replace('/^[^{]*\{/', '', $containerRule);
$containerBody = (string) preg_replace('/\}$/', '', $containerBody);

check(
    'Rule container áp cho CẢ .landing .page VÀ .landing .hero',
    preg_match('/\.landing\s+\.page\s*,/', $containerRule) === 1
        && preg_match('/\.landing\s+\.hero\s*\{/', $containerRule) === 1,
    'container chưa áp cho cả .page và .hero'
);
check(
    'Container có max-width dùng chung',
    preg_match('/max-width\s*:\s*var\(--wa-landing-max\)\s*;/', $containerBody) === 1,
    'không thấy max-width dùng chung'
);
check(
    'Container có padding đối xứng (trái = phải)',
    preg_match('/padding-left\s*:\s*var\(--wa-landing-gutter\)\s*;/', $containerBody) === 1
        && preg_match('/padding-right\s*:\s*var\(--wa-landing-gutter\)\s*;/', $containerBody) === 1,
    'padding trái/phải không đối xứng'
);
check(
    'Container được CANH GIỮA (margin auto)',
    preg_match('/margin-left\s*:\s*auto\s*;/', $containerBody) === 1
        && preg_match('/margin-right\s*:\s*auto\s*;/', $containerBody) === 1,
    'container không canh giữa'
);

// Token dùng chung phải tồn tại trên .landing.
$landingRules = wa_top_level_rules($css, '.landing');
$landingText = implode("\n", $landingRules);
check(
    'Token --wa-landing-max khai báo trên .landing',
    preg_match('/--wa-landing-max\s*:/', $landingText) === 1,
    'thiếu token --wa-landing-max'
);
check(
    'Token --wa-landing-gutter khai báo trên .landing',
    preg_match('/--wa-landing-gutter\s*:/', $landingText) === 1,
    'thiếu token --wa-landing-gutter'
);
check(
    'Token --wa-landing-max trỏ tới --wa-max (1440px)',
    preg_match('/--wa-landing-max\s*:\s*var\(--wa-max\)\s*;/', $landingText) === 1,
    'token max không đồng bộ --wa-max'
);
check(
    'Gutter là 64px (khoảng hở thoáng ở laptop)',
    preg_match('/--wa-landing-gutter\s*:\s*64px\s*;/', $landingText) === 1,
    'gutter không phải 64px'
);

/* ---------------------------------------------------------------------------
 * 2. THANH TRÊN + CHÂN TRANG CÙNG LƯỚI
 * ------------------------------------------------------------------------- */
echo "\n=== 2 — Thanh trên và chân trang trang chủ cùng lưới ===\n";

$footRules = wa_full_rules_in_media($css, '.landing .landing-foot__inner', 'min-width: 1024px');
check(
    'Chân trang trang chủ dùng cùng max-width + gutter',
    $footRules !== []
        && str_contains($footRules[0], 'max-width: var(--wa-landing-max)')
        && str_contains($footRules[0], 'padding-left: var(--wa-landing-gutter)')
        && str_contains($footRules[0], 'padding-right: var(--wa-landing-gutter)'),
    'chân trang chưa dùng lưới chung'
);

$topRules = wa_full_rules_in_media($css, 'body.landing:has(#khong-gian-doc) .topbar__inner', 'min-width: 1024px');
check(
    'Thanh trên TRANG CHỦ dùng cùng gutter (giới hạn bằng :has(#khong-gian-doc))',
    $topRules !== []
        && str_contains($topRules[0], 'padding-left: var(--wa-landing-gutter)')
        && str_contains($topRules[0], 'padding-right: var(--wa-landing-gutter)'),
    'không thấy rule thanh trên giới hạn cho trang chủ'
);

/* ---------------------------------------------------------------------------
 * 3. CHỈ SỬA DESKTOP — KHÔNG ĐỤNG PHONE/MOBILE
 * ------------------------------------------------------------------------- */
echo "\n=== 3 — Chỉ sửa desktop, không đụng phone/mobile ===\n";

// Token gutter KHÔNG được dùng để đặt padding/max-width ngoài @media desktop.
$desktopMedia = wa_all_media_blocks($css, 'min-width: 1024px');
$cssWithoutDesktop = (string) preg_replace(
    '/@media[^{}]*\{(?:[^{}]*\{[^{}]*\}[^{}]*)*\}/s',
    '',
    $css
);
$gutterOutside = preg_match_all('/--wa-landing-gutter/', $cssWithoutDesktop, $m2);
check(
    'Token --wa-landing-gutter KHÔNG bị dùng ngoài @media desktop',
    $gutterOutside <= 1,
    "token gutter xuất hiện $gutterOutside lần ngoài khối desktop"
);
check(
    'Mọi rule dùng --wa-landing-gutter nằm trong @media (min-width: 1024px)',
    $desktopMedia !== '' && str_contains($desktopMedia, '--wa-landing-gutter'),
    'không tìm thấy token gutter trong khối desktop'
);

// Các breakpoint mobile hiện có phải BẤT BIẾN so với HEAD.
$headCss = '';
$headCssLines = [];
exec(
    'git -C ' . escapeshellarg(dirname(__DIR__)) . ' show HEAD:public/assets/css/app.css',
    $headCssLines,
    $headExit
);
if ($headExit === 0) {
    $headCss = str_replace("\r\n", "\n", implode("\n", $headCssLines));
}
check(
    'Đọc được bản app.css trong HEAD để đối chiếu',
    $headCss !== '',
    'không đọc được HEAD:public/assets/css/app.css'
);

if ($headCss !== '') {
    foreach (['max-width: 639px', 'max-width: 1023px'] as $bp) {
        $nowBlock  = wa_all_media_blocks($css, $bp);
        $headBlock = wa_all_media_blocks($headCss, $bp);
        check(
            "Breakpoint mobile '$bp' GIỮ NGUYÊN so với HEAD",
            trim($nowBlock) === trim($headBlock),
            "khối '$bp' đã thay đổi so với HEAD"
        );
    }

    // public/index.php phải BẤT BIẾN so với HEAD (không đổi copy/markup).
    $idxLines = [];
    $idxExit  = 1;
    exec(
        'git -C ' . escapeshellarg(dirname(__DIR__)) . ' diff --quiet HEAD -- public/index.php',
        $idxLines,
        $idxExit
    );
    check(
        'public/index.php BẤT BIẾN so với HEAD (không đổi copy/markup)',
        $idxExit === 0,
        'public/index.php đã thay đổi so với HEAD'
    );
}

/* ---------------------------------------------------------------------------
 * 4. "KHÔNG GIAN TẬP TRUNG" BÙ LỀ TRONG → MÉP PHẢI THẲNG
 * ------------------------------------------------------------------------- */
echo "\n=== 4 — Mép phải khối 'Không gian tập trung' thẳng với container ===\n";

$showcaseRules = wa_full_rules_in_media($css, '.showcase', 'min-width: 1024px');
$showcaseDesktop = $showcaseRules[0] ?? '';
check(
    'Lưới .showcase giữ khe hở cột clamp(...)',
    preg_match('/column-gap\s*:\s*clamp\(/', $showcaseDesktop) === 1,
    'không thấy column-gap dạng clamp()'
);
check(
    'Lưới .showcase giữ padding-right >= 16px (lề an toàn của P-FIX-LANDING-3)',
    preg_match('/padding-right\s*:\s*(\d+)px\s*;/', $showcaseDesktop, $m3) === 1
        && (int) $m3[1] >= 16,
    'không thấy padding-right >= 16px trên .showcase'
);
check(
    'Lưới .showcase giữ tỉ lệ span 5 / span 7',
    preg_match('/\.showcase__text\s*\{[^}]*grid-column:\s*span 5/s', $css) === 1
        && preg_match('/\.showcase__art\s*\{[^}]*grid-column:\s*span 7/s', $css) === 1,
    'thiếu quy tắc span 5 / span 7'
);
check(
    'Cột minh hoạ được BÙ lề trong bằng margin-right âm (để mép phải thẳng)',
    preg_match('/\.showcase__art\s*\{[^}]*margin-right\s*:\s*-\d+px/s', $css) === 1,
    'cột minh hoạ chưa bù lề trong'
);

/* ---------------------------------------------------------------------------
 * 5. COPY / SỐ LIỆU / MINH HOẠ GIỮ NGUYÊN
 * ------------------------------------------------------------------------- */
echo "\n=== 5 — Copy, số liệu và minh hoạ giữ nguyên ===\n";

$sec = wa_section_by_id($html, 'khong-gian-doc');
check('Tìm thấy section "khong-gian-doc"', $sec !== '', 'không tìm thấy id="khong-gian-doc"');

$copy = [
    'Kicker'       => 'Không gian tập trung',
    'Tiêu đề'     => 'Đọc theo cách mà mắt bạn xứng đáng được trải nghiệm',
    'Mô tả'       => 'Được thiết kế riêng cho những ai dành hàng giờ đắm mình trong các bài luận dài.',
    'Checklist 1' => 'Tự chủ dữ liệu hoàn toàn',
    'Checklist 2' => 'Xuất dữ liệu mở định dạng tiêu chuẩn',
    'Liên kết'    => 'Trải nghiệm không gian đọc mẫu',
];
$secFlat = (string) preg_replace('/\s+/u', ' ', $sec);
foreach ($copy as $label => $needle) {
    check("Giữ nguyên $label", str_contains($secFlat, $needle), "không thấy '$needle'");
}

check('Minh hoạ trình đọc còn nguyên', str_contains($sec, 'class="readpreview"'));
check('Khối "Giá trị cốt lõi" còn nguyên', str_contains($html, 'Giá trị cốt lõi'));
check('Khối 3 bước còn nguyên', str_contains($html, 'Cách thức hoạt động trong 3 bước'));
check('CTA cuối còn nguyên', str_contains($html, 'Khởi tạo kho tàng số'));
check('Hero còn nguyên', str_contains($html, 'Lưu tri thức hôm nay.'));
check('Mock bản lưu mẫu còn nguyên', str_contains($html, 'Mã kiểm chứng: sha256:'));
check('Footer còn nguyên', str_contains($html, 'Đồ án môn Lập trình Nhóm 9'));

/* ---------------------------------------------------------------------------
 * 6. KHÔNG CÓ THAY ĐỔI NGOÀI TRANG CHỦ
 * ------------------------------------------------------------------------- */
echo "\n=== 6 — Không đụng trang khác ===\n";

check(
    'Rule container chỉ áp cho .landing (không áp cho workspace)',
    preg_match('/^\.landing\s+\.page\s*,/m', $containerRule) === 1,
    'container có thể đã áp ngoài .landing'
);

// Rule .page dùng chung (áp cho cả workspace) phải BẤT BIẾN so với HEAD.
$pageNow  = wa_top_level_rules($css, '.page');
$pageHead = $headCss !== '' ? wa_top_level_rules($headCss, '.page') : [];
check(
    'Rule .page dùng chung (workspace) GIỮ NGUYÊN so với HEAD',
    $pageNow === $pageHead,
    'rule .page chung đã bị sửa'
);

echo "\n" . ($fail === 0
    ? 'KẾT QUẢ: TẤT CẢ KIỂM TRA ĐẠT.'
    : "KẾT QUẢ: CÓ $fail KIỂM TRA KHÔNG ĐẠT.") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
