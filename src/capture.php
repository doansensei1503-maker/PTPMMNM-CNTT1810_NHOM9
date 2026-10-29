<?php
/**
 * src/capture.php
 * -----------------------------------------------------------------------------
 * ENGINE TẢI TRANG THẬT (P-CAPTURE) — thay thế hoàn toàn src/mock_capture.php.
 *
 * PHP thuần 8.3, không framework, không package Composer. Chỉ dùng extension
 * cURL (mạng) và DOM/DOMDocument (bóc tách HTML).
 *
 * ── BA NGUYÊN TẮC BẮT BUỘC CỦA HỆ THỐNG ────────────────────────────────────
 * 1. KHÔNG BAO GIỜ lưu trang lỗi (404, 5xx, timeout, ngoài allowlist, không có
 *    nội dung) như một bài viết hợp lệ — mỗi nguyên nhân có error_code riêng.
 * 2. LUÔN đọc nội dung từ bản snapshot đã lưu, không đọc lại trang gốc.
 * 3. Mọi ĐÍCH tải về — kể cả sau khi chuyển hướng và cả ảnh — đều phải được
 *    kiểm tra với allowlist_domains. Không có ngoại lệ.
 *
 * ── CẤU HÌNH ─────────────────────────────────────────────────────────────────
 *   Kết nối 5 s • tổng 12 s • tối đa 5 lần chuyển hướng • tối đa 5 MB mỗi tài
 *   nguyên • tối đa 20 ảnh mỗi bài viết • tối thiểu 200 ký tự văn bản.
 *
 * Luồng của capture_url() (đúng thứ tự trong đặc tả):
 *   1. Kiểm tra URL                                   → error_invalid_url
 *   2. Allowlist TRƯỚC khi gọi mạng                  → error_blocked
 *   3. Tải bằng cURL, TỰ chuyển hướng, kiểm tra
 *      allowlist LẠI trên mọi đích chuyển hướng      → error_blocked
 *      timeout: kết nối 5 s / tổng 12 s              → error_timeout
 *   4. 404 → error_404; 4xx/5xx khác → error_http;
 *      Content-Type không phải HTML                  → error_no_content
 *   5. Bóc tách bằng DOMDocument (tiêu đề + vùng nội dung chính)
 *   6. Văn bản < 200 ký tự                          → error_no_content
 *   7. Sanitizer whitelist tự viết
 *   8. Ảnh tải về public/uploads/snapshots/<id>/ (xem wa_capture_localize_images)
 *   9. content_text: bỏ thẻ + giải thực thể + gộp khoảng trắng
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Thư mục public/ — nơi lưu ảnh đã tải về. */
if (!defined('WA_PUBLIC')) {
    define('WA_PUBLIC', dirname(__DIR__) . '/public');
}

/* =============================================================================
    HẰNG SỐ CẤU HÌNH
    ============================================================================= */

/** User-Agent rõ ràng (nhiều máy chủ chặn UA mặc định của cURL). */
const WA_CAPTURE_USER_AGENT = 'WebArchive/1.0 (offline snapshot reader; +https://example.org/webarchive)';

/** Thời gian chờ kết nối (giây). */
const WA_CAPTURE_CONNECT_TIMEOUT = 5;
/** Thời gian chờ tổng thể cho một lần tải (giây). */
const WA_CAPTURE_TIMEOUT = 12;
/** Số lần chuyển hướng tối đa. */
const WA_CAPTURE_MAX_REDIRECTS = 5;
/** Giới hạn dung lượng cho MỘT tài nguyên tải về (5 MB). */
const WA_CAPTURE_MAX_BYTES = 5 * 1024 * 1024;
/** Số ảnh tối đa lấy về mỗi bài viết. */
const WA_CAPTURE_MAX_IMAGES = 20;
/** Ngưỡng độ dài văn bản tối thiểu để coi là bài viết hợp lệ. */
const WA_CAPTURE_MIN_TEXT = 200;

/* =============================================================================
    CA BUNDLE CHO HTTPS (FIX-06) — ĐA NỀN TẢNG, KHÔNG HARDCODE
    ============================================================================= */

/**
 * Đường dẫn CA bundle đáng tin cậy cho cURL/OpenSSL.
 *
 * Thứ tự ưu tiên (dừng ở chỗ đầu tiên tồn tại và đọc được):
 *   1. WA_CA_BUNDLE (biến môi trường) — cho máy chủ tự quản.
 *   2. curl.cainfo / openssl.cafile trong php.ini — cấu hình của quản trị viên.
 *   3. Các vị trí hệ thống phổ biến (Linux/macOS/Windows).
 *   4. null — để cURL dùng kho mặc định của nó.
 *
 * KHÔNG BAO GIỜ trả về đường dẫn không tồn tại, KHÔNG tắt VERIFYPEER/HOST.
 *
 * @return string|null Đường dẫn tuyệt đối tới tệp PEM, hoặc null.
 */
function wa_capture_ca_bundle(): ?string
{
    static $resolved = false;
    static $cached = null;

    if ($resolved) {
        return $cached;
    }

    $resolved = true;

    $candidates = [];

    $env = getenv('WA_CA_BUNDLE');
    if (is_string($env) && $env !== '') {
        $candidates[] = $env;
    }

    foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $iniVal) {
        if (is_string($iniVal) && $iniVal !== '') {
            $candidates[] = $iniVal;
        }
    }

    $candidates = array_merge($candidates, [
        'C:\\php\\cacert.pem',
        'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt',
        'C:\\xampp\\php\\extras\\ssl\\cacert.pem',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/usr/local/etc/openssl/cert.pem',
    ]);

    foreach ($candidates as $path) {
        if (is_string($path) && $path !== '' && is_file($path) && is_readable($path)) {
            $cached = $path;

            return $cached;
        }
    }

    return $cached;
}

/**
 * Áp dụng CA bundle (nếu có) cho một handle cURL — giữ VERIFYPEER/HOST luôn bật.
 */
function wa_capture_apply_ssl($ch): void
{
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $ca = wa_capture_ca_bundle();

    if ($ca !== null) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
}

/* =============================================================================
    DANH SÁCH MÃ LỖI
    ============================================================================= */

const WA_ERR_INVALID_URL = 'error_invalid_url';
const WA_ERR_BLOCKED     = 'error_blocked';
const WA_ERR_TIMEOUT     = 'error_timeout';
const WA_ERR_404         = 'error_404';
const WA_ERR_HTTP        = 'error_http';
const WA_ERR_NO_CONTENT  = 'error_no_content';

/* --- P-FIX-CN2: phân biệt rõ các nguyên nhân lỗi mà người dùng cần biết --- */
const WA_ERR_FORBIDDEN    = 'error_403';       // 403 — bị máy chủ nguồn từ chối
const WA_ERR_RATE_LIMIT   = 'error_429';       // 429 — bị giới hạn tần suất
const WA_ERR_SERVER_ERROR = 'error_5xx';       // 5xx — máy chủ nguồn lỗi
const WA_ERR_SSL          = 'error_ssl';       // lỗi chứng thư SSL
const WA_ERR_LOGIN        = 'error_login';     // trang đăng nhập, không phải bài đọc
const WA_ERR_CAPTCHA      = 'error_captcha';   // trang có CAPTCHA, không phải bài đọc
const WA_ERR_UNSAFE_URL   = 'error_unsafe';    // URL trỏ vào mạng nội bộ (SSRF)

/**
 * Nhãn + thông điệp tiếng Việt RIÊNG BIỆT cho từng mã lỗi.
 *
 * public/save.php dùng hàm này để hiện thông báo khác nhau theo nguyên nhân, và
 * ghi vào snapshots.error_message để thống kê quản trị đếm được lỗi.
 *
 * @return array{title:string, message:string}
 */
function wa_capture_error_text(string $code, string $detail = ''): array
{
    $texts = [
        WA_ERR_INVALID_URL => [
            'title'   => 'URL không hợp lệ',
            'message' => 'Địa chỉ URL không hợp lệ. Vui lòng nhập một đường dẫn http:// hoặc https:// đầy đủ.',
        ],
        WA_ERR_BLOCKED => [
            'title'   => 'URL không thuộc danh sách được duyệt',
            'message' => 'Tên miền này chưa được quản trị viên duyệt. Mọi đích tải về — kể cả sau khi chuyển hướng — đều phải nằm trong danh sách được duyệt.',
        ],
        WA_ERR_TIMEOUT => [
            'title'   => 'Hết thời gian chờ khi tải trang',
            'message' => 'Máy chủ nguồn không phản hồi trong thời gian cho phép (kết nối 5 giây, tổng 12 giây). Bạn có thể thử lại sau.',
        ],
        WA_ERR_404 => [
            'title'   => 'Trang gốc trả về 404',
            'message' => 'Máy chủ nguồn trả về mã lỗi 404 (không tìm thấy nội dung). Trang lỗi sẽ không bao giờ được lưu thành một bài viết hợp lệ.',
        ],
        WA_ERR_HTTP => [
            'title'   => 'Máy chủ nguồn trả về lỗi',
            'message' => 'Máy chủ nguồn trả về mã lỗi HTTP nên nội dung chưa tải được đầy đủ. Bài viết không được lưu.',
        ],
        WA_ERR_NO_CONTENT => [
            'title'   => 'Trang không có nội dung đọc được',
            'message' => 'Trang tải về nhưng không có phần nội dung chính đủ dài để đọc (dưới 200 ký tự), hoặc không phải HTML. Trang thông báo và trang rỗng không được lưu.',
        ],

        /* --- P-FIX-CN2: các nguyên nhân được tách riêng --- */
        WA_ERR_FORBIDDEN => [
            'title'   => 'Máy chủ nguồn từ chối truy cập (403)',
            'message' => 'Máy chủ nguồn trả về mã 403 — nội dung này không được phép xem công khai (có thể cần đăng nhập hoặc bị chặn theo quyền). Bài viết không được lưu.',
        ],
        WA_ERR_RATE_LIMIT => [
            'title'   => 'Máy chủ nguồn giới hạn tần suất (429)',
            'message' => 'Máy chủ nguồn trả về mã 429 — đã có quá nhiều yêu cầu trong thời gian ngắn. Đây là lỗi TẠM THỜI, bạn có thể thử lại sau ít phút.',
        ],
        WA_ERR_SERVER_ERROR => [
            'title'   => 'Máy chủ nguồn đang lỗi (5xx)',
            'message' => 'Máy chủ nguồn trả về mã lỗi 5xx — lỗi phía máy chủ nguồn, không phải lỗi của hệ thống. Đây là lỗi TẠM THỜI, bạn có thể thử lại sau.',
        ],
        WA_ERR_SSL => [
            'title'   => 'Lỗi chứng thư SSL',
            'message' => 'Không xác minh được chứng thư SSL của máy chủ nguồn. '
                       . 'Nếu chứng thư của trang vẫn hợp lệ trong trình duyệt, nguyên nhân thường nằm ở máy chủ '
                       . 'lưu trữ (thiếu CA bundle, proxy/antivirus chặn, đồng hồ hệ thống sai) — hãy kiểm tra '
                       . 'lại cấu hình SSL của máy chủ. Hệ thống KHÔNG bỏ qua kiểm tra SSL.',
        ],
        WA_ERR_LOGIN => [
            'title'   => 'Trang yêu cầu đăng nhập',
            'message' => 'Trang nguồn thực chất là trang đăng nhập, không phải nội dung để đọc. Trang đăng nhập không được lưu thành bài viết.',
        ],
        WA_ERR_CAPTCHA => [
            'title'   => 'Trang có bắt buộc xác minh CAPTCHA',
            'message' => 'Trang nguồn yêu cầu giải CAPTCHA nên không thể lấy nội dung tự động. Trang này không được lưu thành bài viết.',
        ],
        WA_ERR_UNSAFE_URL => [
            'title'   => 'URL không an toàn',
            'message' => 'URL này trỏ tới mạng nội bộ hoặc địa chỉ dùng riêng của máy chủ. Hệ thống chặn những URL như vậy để tránh tấn công SSRF.',
        ],
    ];

    $base = $texts[$code] ?? [
        'title'   => 'Không thể lưu trang',
        'message' => 'Không thể lưu trang này. Vui lòng thử lại với một đường dẫn khác.',
    ];

    $detail = trim(preg_replace('/\s+/u', ' ', $detail) ?? '');
    if ($detail !== '') {
        $base['message'] .= ' Chi tiết: ' . $detail . '.';
    }

    return $base;
}

/**
 * Mã lỗi nào là LỖI TẠM THỜI (có thể thử lại)?
 *
 * Dùng để giao diện hiện nút "Thử lại" thay vì chỉ báo lỗi vĩnh viễn.
 *
 * @return string[]
 */
function wa_capture_retryable_errors(): array
{
    return [
        WA_ERR_TIMEOUT,
        WA_ERR_RATE_LIMIT,
        WA_ERR_SERVER_ERROR,
        WA_ERR_HTTP,
    ];
}

/* =============================================================================
    HÀM TIỆN ÍCH
    ============================================================================= */

/**
 * Chuẩn hoá host từ URL: hạ chữ thường, bỏ dấu chấm cuối và tiền tố "www.".
 */
function wa_capture_host(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host)) {
        return '';
    }

    return wa_capture_normalize_domain($host);
}

/**
 * Chuẩn hoá một tên miền về dạng so khớp (bỏ "www." và dấu chấm cuối).
 */
function wa_capture_normalize_domain(string $domain): string
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    $domain = (string) preg_replace('/^www\./i', '', $domain);

    return $domain;
}

/**
 * URL tuyệt đối có phải http/https không?
 */
function wa_capture_is_http_url(string $url): bool
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    return $scheme === 'http' || $scheme === 'https';
}

/**
 * PHÒNG CHỐNG SSRF — URL có trỏ tới mạng nội bộ / địa chỉ dùng riêng không?
 *
 * P-FIX-CN2: allowlist đã chặn phần lớn tấn công SSRF (chỉ tên miền đã duyệt mới
 * tải được), nhưng một tên miền đã duyệt vẫn có thể trỏ về 127.0.0.1, ::1, hay
 * một IP nội bộ qua DNS rebinding. Vì vậy ta kiểm tra thêm địa chỉ mà host
 * thực sự phân giải ra, và CHẶN mọi địa chỉ dùng riêng.
 *
 * LƯU Ý AN TOÀN: hàm này KHÔNG tắt SSL, KHÔNG tắt allowlist, KHÔNG nới lỏng
 * bất kỳ biện pháp bảo vệ nào. Nó chỉ BỔ SUNG thêm một lớp kiểm tra.
 *
 * @return bool true nếu URL AN TOÀN (không trỏ vào mạng nội bộ).
 */
function wa_capture_is_safe_public_url(string $url): bool
{
    $host = parse_url($url, PHP_URL_HOST);

    if (!is_string($host) || $host === '') {
        return false;
    }

    $host = trim($host, '[]');

    // 1) Địa chỉ IP dạng chữ: kiểm tra trực tiếp.
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        // FIX-10: metadata của cloud (169.254.169.254, fd00:ec2::254, …) LUÔN
        // bị chặn — kể cả khi quản trị viên đã duyệt IP đó trong allowlist
        // (allowlist chỉ mở cho máy chủ thử nghiệm cục bộ, không cho metadata).
        if (wa_capture_is_cloud_metadata_ip($host)) {
            return false;
        }

        if (!wa_capture_is_private_ip($host)) {
            return true;
        }

        // Địa chỉ nội bộ: chỉ được phép khi quản trị viên đã duyệt CHÍNH địa
        // chỉ này trong allowlist_domains (máy chủ thử nghiệm cục bộ).
        return wa_capture_is_allowlisted($host);
    }

    // 2) "localhost" và các biến thể cục bộ.
    //
    // P-FIX-CN2: mạng nội bộ vẫn bị chặn, TRỪ khi quản trị viên đã duyệt chính
    // host đó trong allowlist_domains. Đây là quyết định CÓ CHỦ ĐÍCH của quản
    // trị viên (máy chủ thử nghiệm cục bộ của dự án), không phải lỗ hổng:
    // host CHƯA được duyệt thì vẫn bị chặn hoàn toàn.
    $lower = strtolower($host);

    // FIX-10: host metadata cloud dạng tên miền — chặn tuyệt đối.
    if (wa_capture_is_cloud_metadata_host($lower)) {
        return false;
    }

    if ($lower === 'localhost' || str_ends_with($lower, '.localhost') || $lower === 'localhost.localdomain') {
        return wa_capture_is_allowlisted($lower);
    }

    // 3) Tên miền: phân giải DNS và kiểm tra MỌI địa chỉ trả về.
    //    (Nếu không phân giải được, cho qua — allowlist vẫn là lớp bảo vệ chính,
    //     và cURL sẽ báo lỗi mạng nếu thật sự không tồn tại.)
    $addresses = @gethostbynamel($host);

    if ($addresses === false || $addresses === []) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = (string) $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }
    }

    // CHẶN nếu CÓ BẤT KỲ địa chỉ nào thuộc mạng nội bộ / dùng riêng.
    //
    // P-FIX-CN2: điều kiện ở đây TRƯỚC đây bị đảo ngược (`!private` -> trả về
    // false), khiến HÀNH ĐỘNG của hàm là "được phép tải" thì ra "bị chặn" và
    // ngược lại. Hậu quả: MỌI tên miền công khai (vi.wikipedia.org…) đều bị
    // chặn, còn mạng nội bộ thì lọt qua. Đúng định nghĩa phải là:
    //   - không có địa chỉ nào -> cho qua (allowlist vẫn là lớp bảo vệ chính);
    //   - có ít nhất MỘT địa chỉ nội bộ -> CHẶN.
    foreach ($addresses as $address) {
        // FIX-10: metadata cloud và địa chỉ nội bộ đều chặn — không có ngoại lệ
        // allowlist ở nhánh tên miền (DNS rebinding có thể trỏ tên miền đã
        // duyệt về 169.254.169.254; allowlist chỉ mở cho IP chữ đã duyệt).
        if (wa_capture_is_private_ip($address) || wa_capture_is_cloud_metadata_ip($address)) {
            return false;
        }
    }

    return true;
}

/**
 * FIX-10: địa chỉ metadata của các nhà cung cấp cloud?
 *
 * Các endpoint này cho thuê token/secret của máy chủ (AWS 169.254.169.254,
 * GCP metadata.google.internal, Azure 169.254.169.254, …). LUÔN chặn, kể cả
 * khi IP/host đã được duyệt trong allowlist.
 */
function wa_capture_is_cloud_metadata_ip(string $ip): bool
{
    $lower = strtolower(trim($ip));

    // IPv4 metadata.
    if ($lower === '169.254.169.254') {
        return true;
    }

    // IPv6 metadata (AWS fece::/…).
    if ($lower === 'fd00:ec2::254' || $lower === '[fd00:ec2::254]') {
        return true;
    }

    return false;
}

/**
 * FIX-10: host metadata dạng tên miền (GCP/Azure)?
 */
function wa_capture_is_cloud_metadata_host(string $host): bool
{
    $lower = strtolower(trim(trim($host), '[]'));

    return $lower === 'metadata.google.internal'
        || $lower === 'metadata.google'
        || str_ends_with($lower, '.metadata.google.internal');
}

/**
 * FIX-10: chuỗi ghim DNS cho CURLOPT_RESOLVE ("host:port:ip").
 *
 * Trả null khi không nên ghim (IP chữ, localhost đã duyệt, nhiều IP, hoặc
 * phân giải thất bại) — khi đó cURL phân giải bình thường, các lớp allowlist
 * + kiểm tra IP ở MỖI redirect vẫn bảo vệ.
 */
function wa_capture_dns_pin(string $url): ?string
{
    $parts = parse_url($url);

    if (!is_array($parts)) {
        return null;
    }

    $host = (string) ($parts['host'] ?? '');

    if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
        return null;
    }

    $lower = strtolower($host);

    if ($lower === 'localhost' || str_ends_with($lower, '.localhost') || $lower === 'localhost.localdomain') {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

    $addresses = @gethostbynamel($host);

    if (!is_array($addresses) || count($addresses) !== 1) {
        return null;
    }

    $ip = (string) $addresses[0];

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return null;
    }

    if (wa_capture_is_private_ip($ip) || wa_capture_is_cloud_metadata_ip($ip)) {
        return null;
    }

    return $host . ':' . $port . ':' . $ip;
}

/**
 * Địa chỉ IP này có thuộc mạng nội bộ / dùng riêng / đặc biệt không?
 *
 * Bao gồm: loopback, private (10/8, 172.16/12, 192.168/16), link-local,
 * carrier-grade NAT (100.64/10), địa chỉ dùng riêng IPv6 (fc00::/7), và các
 * dải đặc biệt khác.
 */
function wa_capture_is_private_ip(string $ip): bool
{
    // IPv4
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        // 0.0.0.0/8, 127.0.0.0/8, 10/8, 172.16/12, 192.168/16, 169.254/16,
        // 100.64/10 (CGNAT), 192.0.0/24, 192.0.2/24, 198.18/15, 198.51.100/24,
        // 203.0.113/24, 224/4 (multicast), 240/4 (dự trữ), 255.255.255.255
        $blocked = [
            ['0.0.0.0', 8],
            ['10.0.0.0', 8],
            ['100.64.0.0', 10],
            ['127.0.0.0', 8],
            ['169.254.0.0', 16],
            ['172.16.0.0', 12],
            ['192.0.0.0', 24],
            ['192.0.2.0', 24],
            ['192.168.0.0', 16],
            ['198.18.0.0', 15],
            ['198.51.100.0', 24],
            ['203.0.113.0', 24],
            ['224.0.0.0', 4],
            ['240.0.0.0', 4],
        ];

        $long = ip2long($ip);

        foreach ($blocked as [$network, $bits]) {
            $netLong = ip2long($network);

            if ($netLong === false || $long === false) {
                continue;
            }

            $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

            if (($long & $mask) === ($netLong & $mask)) {
                return true;
            }
        }

        return false;
    }

    // IPv6
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return true;   // không phân giải được -> coi như KHÔNG an toàn
        }

        // ::1 (loopback), :: (unspecified)
        if ($packed === str_repeat("\0", 15) . "\1" || $packed === str_repeat("\0", 16)) {
            return true;
        }

        // fc00::/7 (unique local), fe80::/10 (link-local), ff00::/8 (multicast)
        $first = ord($packed[0]);

        if (($first & 0xFE) === 0xFC) {
            return true;
        }

        if ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) {
            return true;
        }

        if ($first === 0xFF) {
            return true;
        }

        // ::ffff:x.x.x.x — IPv4 được bọc trong IPv6: kiểm tra phần IPv4.
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $v4 = inet_ntop(substr($packed, 12));

            if ($v4 !== false) {
                return wa_capture_is_private_ip($v4);
            }
        }

        return false;
    }

    // Không phải IP -> không chặn ở đây (đã xử lý ở nhánh tên miền).
    return false;
}

/**
 * Làm sạch văn bản: giải thực thể HTML rồi gộp khoảng trắng.
 */
function wa_capture_clean_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

/**
 * Trang tải về có phải trang ĐĂNG NHẬP hoặc trang CAPTCHA không?
 *
 * P-FIX-CN2: một trang đăng nhập hay trang CAPTCHA có vẻ "đọc được" (đủ 200
 * ký tự văn bản) nên nếu không kiểm tra, nó sẽ bị lưu thành một bài viết
 * "thành công" — đúng thứ mà đề tài cấm ("không lưu trang đăng nhập thành bài
 * thành công").
 *
 * Dấu hiệu nhận dạng (đủ MỘT trong số đó):
 *   - Biểu mẫu đăng nhập: <form> có input[type=password].
 *   - CAPTCHA: thẻ/class chứa "captcha", hoặc ảnh có tên "captcha".
 *   - Thông điệp đăng nhập tiếng Việt/Anh điển hình.
 *
 * @return string|null 'login' | 'captcha' | null (không phải trang đăng nhập)
 */
function wa_capture_detect_gate_page(DOMDocument $doc): ?string
{
    $xpath = new DOMXPath($doc);

    // 1) Ô nhập mật khẩu -> chắc chắn là trang đăng nhập.
    $passwords = $xpath->query('//input[@type="password" or @name="password" or @id="password" or @name="passwd"]');

    if ($passwords !== false && $passwords->length > 0) {
        return 'login';
    }

    // 2) CAPTCHA.
    $captcha = $xpath->query(
        '//*[contains(translate(@class,"CAPTCHA","captcha"),"captcha")'
        . ' or contains(translate(@id,"CAPTCHA","captcha"),"captcha")'
        . ' or contains(translate(@name,"CAPTCHA","captcha"),"captcha")'
        . ' or contains(translate(@src,"CAPTCHA","captcha"),"captcha")'
        . ' or contains(translate(@alt,"CAPTCHA","captcha"),"captcha")]'
    );

    if ($captcha !== false && $captcha->length > 0) {
        return 'captcha';
    }

    // 3) Thông điệp "đăng nhập" / "sign in" trong vùng nội dung chính.
    $text = mb_strtolower(wa_capture_clean_text($doc->textContent), 'UTF-8');

    $loginPhrases = [
        'vui lòng đăng nhập',
        'đăng nhập để tiếp tục',
        'bạn chưa đăng nhập',
        'hãy đăng nhập',
        'sign in to continue',
        'please log in',
        'you must log in',
    ];

    foreach ($loginPhrases as $phrase) {
        if (str_contains($text, $phrase)) {
            return 'login';
        }
    }

    return null;
}

/**
 * Bỏ mọi thẻ khỏi chuỗi HTML để lấy văn bản thuần.
 */
function wa_capture_text_only(string $html): string
{
    // Gỡ nốt script/style (phòng vệ) rồi bỏ thẻ và giải thực thể.
    $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
    $html = strip_tags($html);

    return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Dựng một kết quả lỗi với đúng các khoá của capture_url().
 *
 * @return array<string, mixed>
 */
function wa_capture_failure(string $errorCode, string $detail, int $httpStatus = 0, string $finalUrl = ''): array
{
    return [
        'ok'             => false,
        'error_code'     => $errorCode,
        'error_message'  => $detail,
        'http_status'    => $httpStatus,
        'final_url'      => $finalUrl,
        'title'          => '',
        'content_html'   => '',
        'content_text'   => '',
        'images'         => [],
        'images_skipped' => 0,
        'fetched_at'     => date('Y-m-d H:i:s'),
    ];
}

/* =============================================================================
    BƯỚC 2 — KIỂM TRA ALLOWLIST (chạy TRƯỚC mọi lệnh gọi mạng)
    ============================================================================= */

/**
 * Tên miền có nằm trong bảng `allowlist_domains` không?
 *
 * Quy tắc so khớp (không phân biệt hoa/thường):
 *   - host BẰNG ĐÚNG một tên miền trong danh sách, HOẶC
 *   - host KẾT THÚC bằng "." + tên miền trong danh sách (chấp nhận subdomain).
 *
 * Ví dụ có `example.org` trong danh sách thì `example.org`, `www.example.org`
 * và `blog.example.org` đều được phép, còn `notexample.org` thì KHÔNG.
 *
 * Nếu không đọc được CSDL thì coi như KHÔNG được duyệt — an toàn theo nguyên tắc.
 */
function wa_capture_is_allowlisted(string $host): bool
{
    $host = wa_capture_normalize_domain($host);

    if ($host === '') {
        return false;
    }

    try {
        // Mỗi chỗ dùng MỘT tên tham số khác nhau: PDO đang bật prepares thật
        // (ATTR_EMULATE_PREPARES = false) nên lặp lại một tên tham số trong
        // cùng câu lệnh sẽ gây lỗi SQLSTATE[HY093].
        //
        // Dùng TRIM(LEADING '.' FROM domain) chứ không phải RTRIM/TRIM hai tham số:
        // tham số thứ hai của RTRIM trong MySQL là SỐ LẦN lặp, và bản dựng
        // MySQL của máy này từ chối dạng TRIM(..., <danh sách ký tự>) trong câu
        // truy vấn chuẩn bị. Dạng LEADING ... FROM chạy được và cho cùng kết quả.
        $stmt = db()->prepare(
            "SELECT 1
               FROM `allowlist_domains`
              WHERE LOWER(TRIM(LEADING '.' FROM `domain`)) = :host_exact
                 OR :host_subdomain LIKE CONCAT('%.', LOWER(TRIM(LEADING '.' FROM `domain`)))
              LIMIT 1"
        );
        $stmt->execute([':host_exact' => $host, ':host_subdomain' => $host]);

        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        // Không đọc được allowlist thì coi như KHÔNG được duyệt.
        error_log('[WebArchive] allowlist query failed: ' . $e->getMessage());
        return false;
    }
}

/* =============================================================================
    BƯỚC 3 — TẢI BẰNG cURL (tự chuyển hướng, kiểm tra allowlist mỗi lần)
    ============================================================================= */

/**
 * Tải một tài nguyên, tự xử lý chuyển hướng và kiểm tra allowlist ở MỌI đích.
 *
 * cURL được dùng KHÔNG kèm CURLOPT_FOLLOWLOCATION. Mỗi lần gặp 3xx, ta lấy
 * địa chỉ đích từ CURLINFO_REDIRECT_URL, chạy lại hàm kiểm tra allowlist, rồi
 * mới tải tiếp. Nhờ vậy không có đường nào để một redirect lách khỏi allowlist.
 *
 * @return array{ok:bool, error_code?:string, detail?:string, body?:string,
 *              status:int, final_url:string, content_type:string}
 */
function wa_capture_http_get(string $url): array
{
    $current  = $url;
    $redirect = 0;

    while (true) {
        // --- Allowlist TRƯỚC khi mở kết nối (nguyên tắc số 3) ---
        $host = wa_capture_host($current);
        if (!wa_capture_is_allowlisted($host)) {
            return [
                'ok'           => false,
                'error_code'   => WA_ERR_BLOCKED,
                'detail'       => 'tên miền "' . $host . '" không nằm trong danh sách được duyệt',
                'status'       => 0,
                'final_url'    => $current,
                'content_type' => '',
            ];
        }

        // --- SSRF: chặn địa chỉ nội bộ / dùng riêng (P-FIX-CN2) ---
        // Kiểm tra NÀY ở MỌI bước của chuỗi chuyển hướng, ngay trước khi mở
        // kết nối — cùng vị trí với kiểm tra allowlist.
        if (!wa_capture_is_safe_public_url($current)) {
            return [
                'ok'           => false,
                'error_code'   => WA_ERR_UNSAFE_URL,
                'detail'       => 'địa chỉ "' . $host . '" phân giải tới mạng nội bộ hoặc địa chỉ dùng riêng',
                'status'       => 0,
                'final_url'    => $current,
                'content_type' => '',
            ];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL             => $current,
            CURLOPT_RETURNTRANSFER  => true,
            // KHÔNG dùng CURLOPT_FOLLOWLOCATION — ta đi từng bước để kiểm tra allowlist.
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_CONNECTTIMEOUT  => WA_CAPTURE_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT         => WA_CAPTURE_TIMEOUT,
            CURLOPT_USERAGENT       => WA_CAPTURE_USER_AGENT,
            CURLOPT_ACCEPT_ENCODING => '',
            // FIX-06: VERIFYPEER/HOST luôn bật; CA bundle resolve đa nền tảng
            // (php.ini -> vị trí hệ thống -> mặc định cURL). KHÔNG hardcode
            // một đường dẫn duy nhất, KHÔNG tắt kiểm tra SSL.
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_BUFFERSIZE      => 65536,
        ]);

        // Áp CA bundle đã resolve (nếu có) — giữ nguyên VERIFYPEER/HOST ở trên.
        $caBundle = wa_capture_ca_bundle();

        if ($caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }

        // FIX-10: ghim DNS — ép cURL kết nối tới đúng IP đã kiểm tra ở trên
        // (chống DNS rebinding: DNS đổi đáp án giữa lúc kiểm tra và lúc nối).
        // Chỉ ghim khi host là tên miền (không phải IP chữ) và phân giải ra
        // ĐÚNG MỘT địa chỉ công khai duy nhất; hostname/SNI/host-header giữ
        // nguyên nên xác minh chứng thư SSL không đổi.
        $pin = wa_capture_dns_pin($current);

        if ($pin !== null) {
            curl_setopt($ch, CURLOPT_RESOLVE, [$pin]);
        }

        $body      = curl_exec($ch);
        $errno     = curl_errno($ch);
        $errstr    = curl_error($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype     = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $redirectTo = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        // --- Lỗi mạng / hết thời gian ---
        if ($body === false || $errno !== 0) {
            // P-FIX-CN2: dùng hàm wa_curl_errno_equals() thay cho so sánh trực
            // tiếp hằng CURLE_* — bản dựng cURL của máy (Windows) có thể KHÔNG
            // có sẵn một số hằng số này, và truy cập trực tiếp sẽ ném Error
            // "Undefined constant", làm SẬP cả lần lưu (đúng triệu chứng trang
            // cham.php của T3 không ghi được bản ghi lỗi).
            $isTimeout = wa_curl_errno_equals($errno, [
                'CURLE_OPERATION_TIMEOUTED' => 28,
                'CURLE_COULDNT_CONNECT'     => 7,
                'CURLE_GOT_NOTHING'         => 52,
            ]);

            // P-FIX-CN2: lỗi SSL có mã riêng để người dùng biết đây là vấn đề
            // chứng thư, KHÔNG phải mạng chậm. Hệ thống KHÔNG hạ chuẩn SSL.
            $isSsl = wa_capture_is_ssl_error($errno);

            return [
                'ok'           => false,
                'error_code'   => $isSsl
                    ? WA_ERR_SSL
                    : ($isTimeout ? WA_ERR_TIMEOUT : WA_ERR_HTTP),
                'detail'       => $isSsl
                    ? 'lỗi SSL cURL ' . $errno . ': ' . $errstr
                    : ($isTimeout
                        ? 'không có phản hồi trong ' . WA_CAPTURE_CONNECT_TIMEOUT . ' s kết nối / '
                            . WA_CAPTURE_TIMEOUT . ' s tổng thời gian'
                        : 'lỗi mạng cURL ' . $errno . ': ' . $errstr),
                'status'       => 0,
                'final_url'    => $current,
                'content_type' => '',
            ];
        }

        // --- Chuyển hướng: allowlist được kiểm tra LẠI ở vòng lặp kế tiếp ---
        if ($status >= 300 && $status < 400) {
            if ($redirectTo === '') {
                return [
                    'ok'           => false,
                    'error_code'   => WA_ERR_HTTP,
                    'detail'       => 'máy chủ trả về ' . $status . ' nhưng không có địa chỉ chuyển hướng',
                    'status'       => $status,
                    'final_url'    => $current,
                    'content_type' => '',
                ];
            }

            $redirect++;
            if ($redirect > WA_CAPTURE_MAX_REDIRECTS) {
                return [
                    'ok'           => false,
                    'error_code'   => WA_ERR_HTTP,
                    'detail'       => 'quá nhiều lần chuyển hướng (tối đa ' . WA_CAPTURE_MAX_REDIRECTS . ')',
                    'status'       => $status,
                    'final_url'    => $current,
                    'content_type' => '',
                ];
            }

            $next = wa_capture_resolve_url($current, $redirectTo);
            if ($next === '' || !wa_capture_is_http_url($next)) {
                return [
                    'ok'           => false,
                    'error_code'   => WA_ERR_INVALID_URL,
                    'detail'       => 'địa chỉ chuyển hướng không hợp lệ',
                    'status'       => $status,
                    'final_url'    => $current,
                    'content_type' => '',
                ];
            }

            $current = $next;
            continue;
        }

        // --- Giới hạn 5 MB cho một tài nguyên ---
        $body = (string) $body;
        if (strlen($body) > WA_CAPTURE_MAX_BYTES) {
            return [
                'ok'           => false,
                'error_code'   => WA_ERR_HTTP,
                'detail'       => 'nội dung ' . strlen($body) . ' byte vượt quá giới hạn '
                    . WA_CAPTURE_MAX_BYTES . ' byte',
                'status'       => $status,
                'final_url'    => $current,
                'content_type' => $ctype,
            ];
        }

        return [
            'ok'           => true,
            'body'         => $body,
            'status'       => $status,
            'final_url'    => $current,
            'content_type' => $ctype,
        ];
    }
}

/* =============================================================================
    BƯỚC 4 — ÁNH XẠ MÃ HTTP
    ============================================================================= */

/**
 * Ánh xạ mã trạng thái HTTP sang mã lỗi; null nghĩa là 2xx, đi tiếp.
 *
 * P-FIX-CN2: tách rõ 403 / 404 / 429 / 5xx / lỗi SSL để người dùng biết
 * chính xác vì sao bài viết không được lưu, và để biết lỗi nào CÓ THỬ LẠI.
 */
function wa_capture_status_error(int $status): ?string
{
    if ($status === 403) {
        return WA_ERR_FORBIDDEN;
    }

    if ($status === 404) {
        return WA_ERR_404;
    }

    if ($status === 429) {
        return WA_ERR_RATE_LIMIT;
    }

    // 5xx: lỗi máy chủ nguồn — tạm thời, có thể thử lại.
    if ($status >= 500) {
        return WA_ERR_SERVER_ERROR;
    }

    // 408 (Request Timeout) và 504 (Gateway Timeout) là lỗi hết thời gian.
    if ($status === 408 || $status === 504) {
        return WA_ERR_TIMEOUT;
    }

    if ($status >= 400) {
        return WA_ERR_HTTP;
    }

    return null;
}

/**
 * So sánh mã lỗi cURL với một tập tên hằng + giá trị dự phòng.
 *
 * P-FIX-CN2: một số bản dựng cURL (Windows, cURL 8.x) không định nghĩa mọi
 * hằng CURLE_* — truy cập trực tiếp sẽ ném Error "Undefined constant" và làm
 * sập cả lần lưu. Hàm này ưu tiên hằng thật nếu có (đúng trong mọi bản dựng),
 * ngược lại dùng giá trị dự phòng chuẩn của libcurl.
 *
 * @param array<string, int> $candidates tên hằng => giá trị dự phòng
 */
function wa_curl_errno_equals(int $errno, array $candidates): bool
{
    foreach ($candidates as $name => $fallback) {
        $code = defined($name) ? (int) constant($name) : (int) $fallback;

        if ($errno === $code) {
            return true;
        }
    }

    return false;
}

/**
 * Mã lỗi cURL của SSL — dùng để báo đúng nguyên nhân "lỗi SSL" (P-FIX-CN2).
 *
 * P-FIX-CN2: bản dựng cURL của máy (Windows + cURL 8.x) KHÔNG có sẵn mọi
 * hằng số CURLE_SSL_* mới (ví dụ CURLE_SSL_ISSUER_ERROR, CURLE_SSL_CRL_BADFILE).
 * Gọi trực tiếp sẽ ném Error "Undefined constant" và làm SẬP cả lần lưu —
 * đúng triệu chứng T3 FAIL ở trang cham.php (CURLE_OPERATION_TIMEOUTED=28
 * được phát hiện là timeout TRƯỚC khi kiểm tra SSL, nhưng lỗi kiểm tra SSL đã
 * ném Error trước đó). Vì vậy ta tra theo GIÁ TRỊ SỐ qua hằng đã defined().
 */
function wa_capture_is_ssl_error(int $errno): bool
{
    $sslCodes = [];

    foreach ([
        'CURLE_SSL_CONNECT_ERROR'     => 35,
        'CURLE_SSL_CERTPROBLEM'       => 58,
        'CURLE_SSL_CIPHER'            => 59,
        'CURLE_SSL_CACERT'            => 60,
        'CURLE_SSL_CACERT_BADFILE'    => 77,
        'CURLE_PEER_FAILED_VERIFICATION' => 60,
        'CURLE_SSL_ENGINE_NOTFOUND'   => 53,
        'CURLE_SSL_ENGINE_SETFAILED'  => 54,
        'CURLE_SSL_PINNEDPUBKEYNOTMATCH' => 90,
        'CURLE_SSL_INVALIDCERTSTATUS' => 91,
    ] as $name => $fallback) {
        $sslCodes[] = defined($name) ? (int) constant($name) : $fallback;
    }

    if (defined('CURLE_SSL_ISSUER_ERROR')) {
        $sslCodes[] = (int) constant('CURLE_SSL_ISSUER_ERROR');
    } else {
        $sslCodes[] = 83;
    }

    if (defined('CURLE_SSL_CRL_BADFILE')) {
        $sslCodes[] = (int) constant('CURLE_SSL_CRL_BADFILE');
    } else {
        $sslCodes[] = 82;
    }

    return in_array($errno, $sslCodes, true);
}

/**
 * Content-Type có phải HTML (hoặc XHTML) không?
 */
function wa_capture_is_html(string $contentType): bool
{
    $ct = strtolower(trim($contentType));
    if ($ct === '') {
        return false;
    }

    // Bỏ tham số charset=... phía sau dấu chấm phẩy.
    $mime = trim(explode(';', $ct)[0]);

    return $mime === 'text/html' || $mime === 'application/xhtml+xml';
}

/* =============================================================================
    PHÂN GIẢI URL
    ============================================================================= */

/**
 * Phân giải một URL (tuyệt đối hoặc tương đối) so với URL cơ sở.
 *
 * Xử lý: URL tuyệt đối, dạng protocol-relative (//host/x), đường dẫn gốc
 * (/a/b), đường dẫn tương đối (a/b, ./a, ../a), query và fragment.
 */
function wa_capture_resolve_url(string $base, string $relative): string
{
    $relative = trim(html_entity_decode($relative, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if ($relative === '') {
        return '';
    }

    // URL tuyệt đối có scheme.
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $relative) === 1) {
        return $relative;
    }

    $baseParts = parse_url($base);
    if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
        return '';
    }

    $scheme = strtolower((string) $baseParts['scheme']);
    $host   = (string) $baseParts['host'];
    $port   = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

    // Protocol-relative.
    if (str_starts_with($relative, '//')) {
        return $scheme . ':' . $relative;
    }

    // Tách fragment và query của URL tương đối.
    $relFragment = '';
    $relQuery    = '';
    if (str_contains($relative, '#')) {
        [$relative, $relFragment] = explode('#', $relative, 2);
    }
    if (str_contains($relative, '?')) {
        [$relative, $relQuery] = explode('?', $relative, 2);
    }

    if ($relative === '') {
        $path = (string) ($baseParts['path'] ?? '/');
        if ($relQuery === '') {
            $relQuery = (string) ($baseParts['query'] ?? '');
        }
    } elseif (str_starts_with($relative, '/')) {
        $path = $relative;
    } else {
        $basePath = (string) ($baseParts['path'] ?? '/');
        $slashPos = strrpos($basePath, '/');
        $dir      = $slashPos === false ? '/' : substr($basePath, 0, $slashPos + 1);
        $path     = $dir . $relative;
    }

    // Chuẩn hoá các đoạn "." và "..".
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '.' || $segment === '') {
            if ($segment === '' && count($segments) === 0) {
                $segments[] = '';
            }
            continue;
        }
        if ($segment === '..') {
            if (count($segments) > 1) {
                array_pop($segments);
            }
            continue;
        }
        $segments[] = $segment;
    }

    $normalized = implode('/', $segments);
    if ($normalized === '' || $normalized[0] !== '/') {
        $normalized = '/' . $normalized;
    }

    $url = $scheme . '://' . $host . $port . $normalized;

    if ($relQuery !== '') {
        $url .= '?' . $relQuery;
    }
    if ($relFragment !== '') {
        $url .= '#' . $relFragment;
    }

    return $url;
}

/* =============================================================================
    BƯỚC 5 — BÓC TÁCH BẰNG DOMDocument
    ============================================================================= */

/**
 * Phân tích chuỗi HTML thành DOMDocument (mã hoá UTF-8, không gọi mạng).
 */
function wa_capture_dom(string $html): ?DOMDocument
{
    $doc = new DOMDocument();

    // HTML thực tế thường không đúng chuẩn: tắt cảnh báo libxml để không rò
    // thông báo kỹ thuật ra ngoài, nhưng không dùng @ để nuốt lỗi.
    $previous = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML(
        '<?xml encoding="UTF-8">' . $html,
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return ($ok && $doc->hasChildNodes()) ? $doc : null;
}

/**
 * Gỡ toàn bộ các phần tử có tên nằm trong danh sách.
 *
 * @param string[] $tags
 */
function wa_capture_remove_elements(DOMDocument $doc, array $tags): void
{
    $xpath = new DOMXPath($doc);

    foreach ($tags as $tag) {
        $nodes = $xpath->query('//' . $tag);
        if ($nodes === false) {
            continue;
        }

        // Sao chép trước vì NodeList thay đổi khi phần tử bị xoá.
        $list = iterator_to_array($nodes);
        foreach ($list as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }
}

/**
 * Tiêu đề theo thứ tự ưu tiên: og:title → thẻ <title> → <h1> đầu tiên.
 */
function wa_capture_extract_title(DOMDocument $doc, string $url): string
{
    $xpath = new DOMXPath($doc);

    $og = $xpath->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "og:title"]/@content');
    if ($og !== false && $og->length > 0) {
        $value = wa_capture_clean_text((string) $og->item(0)->nodeValue);
        if ($value !== '') {
            return $value;
        }
    }

    $title = $xpath->query('//title');
    if ($title !== false && $title->length > 0) {
        $value = wa_capture_clean_text((string) $title->item(0)->textContent);
        if ($value !== '') {
            return $value;
        }
    }

    $h1 = $xpath->query('//h1');
    if ($h1 !== false && $h1->length > 0) {
        $value = wa_capture_clean_text((string) $h1->item(0)->textContent);
        if ($value !== '') {
            return $value;
        }
    }

    $host = wa_capture_host($url);

    return $host !== '' ? $host : 'Trang không có tiêu đề';
}

/**
 * Chấm điểm mật độ văn bản kiểu Readability đơn giản.
 *
 * Điểm = độ dài văn bản trong thẻ <p> (phần lớn giá trị của bài viết) trừ
 * văn bản nằm trong liên kết — liên kết thường là menu/điều hướng rác.
 *
 * @return array{score:float, text_length:int}
 */
function wa_capture_density(DOMNode $node): array
{
    if (!$node instanceof DOMElement) {
        return ['score' => 0.0, 'text_length' => 0];
    }

    $paragraphText = 0;
    foreach (wa_capture_descendants($node, 'p') as $p) {
        $paragraphText += mb_strlen(wa_capture_clean_text($p->textContent), 'UTF-8');
    }

    $linkText = 0;
    foreach (wa_capture_descendants($node, 'a') as $a) {
        $linkText += mb_strlen(wa_capture_clean_text($a->textContent), 'UTF-8');
    }

    $allText = mb_strlen(wa_capture_clean_text($node->textContent), 'UTF-8');

    $base  = $paragraphText > 0 ? $paragraphText : $allText;
    $score = $base - ($linkText * 0.9);

    return ['score' => max(0.0, (float) $score), 'text_length' => $allText];
}

/**
 * Tìm phần tử chứa nội dung chính: <article> → <main> → div/section có mật độ
 * văn bản cao nhất → <body>.
 */
function wa_capture_find_content_node(DOMDocument $doc): ?DOMElement
{
    $xpath = new DOMXPath($doc);

    $article = $xpath->query('//article');
    if ($article !== false && $article->length > 0 && $article->item(0) instanceof DOMElement) {
        return $article->item(0);
    }

    $main = $xpath->query('//main');
    if ($main !== false && $main->length > 0 && $main->item(0) instanceof DOMElement) {
        return $main->item(0);
    }

    $best      = null;
    $bestScore = 0.0;
    $candidates = $xpath->query('//div | //section');

    if ($candidates !== false) {
        foreach ($candidates as $node) {
            $density = wa_capture_density($node);
            if ($density['score'] > $bestScore) {
                $bestScore = $density['score'];
                $best      = $node;
            }
        }
    }

    if ($best instanceof DOMElement) {
        return $best;
    }

    $body = $doc->getElementsByTagName('body');

    return $body->length > 0 ? $body->item(0) : null;
}

/**
 * Các phần tử cháu có tên thẻ cho trước, nằm trong cây con của $node.
 *
 * @return DOMElement[]
 */
function wa_capture_descendants(DOMNode $node, string $tag): array
{
    $doc = $node instanceof DOMDocument ? $node : $node->ownerDocument;
    if ($doc === null) {
        return [];
    }

    $nodes = (new DOMXPath($doc))->query('.//' . $tag, $node);
    if ($nodes === false) {
        return [];
    }

    $result = [];
    foreach ($nodes as $child) {
        if ($child instanceof DOMElement) {
            $result[] = $child;
        }
    }

    return $result;
}

/* =============================================================================
    BƯỚC 7 — SANITIZER WHITELIST (tự viết, không dùng thư viện ngoài)
    ============================================================================= */

/** Thẻ được phép giữ trong content_html. */
const WA_ALLOWED_TAGS = [
    'p', 'br', 'hr',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'ul', 'ol', 'li',
    'blockquote', 'pre', 'code',
    'strong', 'b', 'em', 'i', 'u',
    'a', 'img',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    'figure', 'figcaption', 'div', 'span', 'sup', 'sub',
];

/**
 * Thuộc tính được phép giữ, theo từng thẻ.
 *
 * Mọi thuộc tính khác — kể cả on*, style, class, id, srcset, data-* — đều bị gỡ.
 */
const WA_ALLOWED_ATTRS = [
    'a'   => ['href', 'title'],
    'img' => ['src', 'alt', 'title', 'width', 'height'],
    'td'  => ['colspan', 'rowspan'],
    'th'  => ['colspan', 'rowspan'],
];

/**
 * Thẻ bị gỡ khỏi cây DOM TRƯỚC khi trích xuất nội dung.
 */
const WA_STRIP_TAGS = [
    'script', 'style', 'noscript', 'iframe', 'object', 'embed',
    'form', 'nav', 'header', 'footer', 'aside',
    'svg', 'button', 'input', 'select', 'textarea', 'link', 'meta', 'base',
];

/**
 * Thẻ bị gỡ HOÀN TOÀN (kể cả nội dung con) khi sanitizer gặp.
 *
 * Đây là lưới an toàn thứ hai bên dưới bước gỡ trước: nếu vì lý do nào đó
 * một thẻ nguy hiểm lọt vào cây, nó vẫn biến mất cùng con.
 */
const WA_DROP_TAGS = [
    'script', 'style', 'noscript', 'iframe', 'object', 'embed',
    'svg', 'math', 'form', 'button', 'input', 'select', 'textarea',
];

/**
 * Làm sạch một nhánh DOM theo whitelist và trả về HTML đã vệ sinh.
 *
 * - Thẻ trong WA_DROP_TAGS: gỡ cả phần tử lẫn con.
 * - Thẻ không có trong whitelist: tháo thẻ, giữ lại phần tử con.
 * - Thuộc tính không được phép: gỡ khỏi thẻ.
 * - <a>: chỉ giữ href http/https (đã phân giải tuyệt đối) và thêm
 *   rel="noopener noreferrer"; href khác (javascript:, mailto:, #) → tháo
 *   thẻ <a>, giữ nguyên phần chữ.
 * - <img>: src phân giải tuyệt đối và phải là http/https, nếu không thì gỡ ảnh.
 */
function wa_capture_sanitize(DOMNode $node, string $baseUrl): string
{
    $doc = $node instanceof DOMDocument ? $node : $node->ownerDocument;
    if ($doc === null) {
        return '';
    }

    // Sử lý các phần tử con trước, để khi tháo/gỡ phần tử cha không bỏ sót con.
    wa_capture_walk_sanitize($node, $baseUrl);

    // Chính phần tử gốc (ví dụ <article>) cũng phải qua whitelist. Nếu không
    // được phép thì bọc nó vào một <div> tạm để giữ nguyên cây DOM, rồi lấy
    // HTML BÊN TRONG thẻ đó. (Không tháo node gốc: sau khi tháo, node mất liên
    // kết với tài liệu và saveHTML($node) sẽ trả về chuỗi rỗng.)
    $rootIsAllowed = $node instanceof DOMDocument
        || in_array(strtolower($node->nodeName), WA_ALLOWED_TAGS, true);

    if ($rootIsAllowed) {
        $html = $doc->saveHTML($node);
    } else {
        $wrapper = $doc->createElement('div');
        $parent  = $node->parentNode;

        if ($parent === null) {
            return '';
        }

        $parent->insertBefore($wrapper, $node);
        $wrapper->appendChild($node);

        $html = $doc->saveHTML($wrapper);
        $html = $html === false ? '' : trim((string) preg_replace(
            '#^<div>|</div>$#',
            '',
            $html
        ));

        // Trả cây DOM về nguyên trạng để không ảnh hưởng phần xử lý sau đó.
        $parent->insertBefore($node, $wrapper);
        $parent->removeChild($wrapper);
    }

    if ($html === false) {
        return '';
    }

    // Bỏ bọc <body> nếu node gốc là body.
    $html = (string) preg_replace('#^<body[^>]*>#i', '', (string) $html);
    $html = (string) preg_replace('#</body>$#i', '', $html);

    return trim($html);
}

/**
 * Duyệt cây DOM, áp dụng whitelist cho từng phần tử.
 */
function wa_capture_walk_sanitize(DOMNode $node, string $baseUrl): void
{
    $children = iterator_to_array($node->childNodes);

    foreach ($children as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->nodeName);

        // --- Thẻ nguy hiểm: gỡ cả phần tử lẫn nội dung con ---
        if (in_array($tag, WA_DROP_TAGS, true)) {
            if ($child->parentNode !== null) {
                $child->parentNode->removeChild($child);
            }
            continue;
        }

        // --- Thẻ không nằm trong whitelist: tháo thẻ, giữ lại con ---
        if (!in_array($tag, WA_ALLOWED_TAGS, true)) {
            wa_capture_walk_sanitize($child, $baseUrl);
            wa_capture_unwrap($child);
            continue;
        }

        // Đệ quy vào con TRƯỚC, để thuộc tính của con được lọc trước khi cha
        // có thể bị tháo (khi đó con được giữ lại đúng đã lọc).
        wa_capture_walk_sanitize($child, $baseUrl);

        wa_capture_sanitize_attributes($child, $tag, $baseUrl);

        if ($tag === 'img' && !$child->hasAttribute('src')) {
            if ($child->parentNode !== null) {
                $child->parentNode->removeChild($child);
            }
        }
    }
}

/**
 * Tháo phần tử nhưng giữ lại các phần tử con.
 */
function wa_capture_unwrap(DOMElement $element): void
{
    $parent = $element->parentNode;
    if ($parent === null) {
        return;
    }

    while ($element->firstChild !== null) {
        $parent->insertBefore($element->firstChild, $element);
    }

    $parent->removeChild($element);
}

/**
 * Lọc thuộc tính của một phần tử theo whitelist.
 */
function wa_capture_sanitize_attributes(DOMElement $element, string $tag, string $baseUrl): void
{
    $allowed = WA_ALLOWED_ATTRS[$tag] ?? [];

    // --- Gỡ mọi thuộc tính không nằm trong danh sách cho phép ---
    if ($element->hasAttributes()) {
        foreach (iterator_to_array($element->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attr->nodeName);
            }
        }
    }

    // --- <a href>: chỉ giữ http/https ---
    if ($tag === 'a') {
        $href     = trim($element->getAttribute('href'));
        $absolute = $href === '' ? '' : wa_capture_resolve_url($baseUrl, $href);

        if ($absolute !== '' && wa_capture_is_http_url($absolute)) {
            $element->setAttribute('href', $absolute);
            // rel do sanitizer tự thêm sau khi đã lọc thuộc tính.
            $element->setAttribute('rel', 'noopener noreferrer');
        } else {
            // javascript:, mailto:, rỗng... → bỏ thẻ <a>, giữ lại phần chữ.
            wa_capture_unwrap($element);
        }

        return;
    }

    // --- <img src>: phân giải tuyệt đối, chỉ giữ http/https ---
    if ($tag === 'img') {
        $src      = trim($element->getAttribute('src'));
        $absolute = $src === '' ? '' : wa_capture_resolve_url($baseUrl, $src);

        if ($absolute !== '' && wa_capture_is_http_url($absolute)) {
            $element->setAttribute('src', $absolute);
        } else {
            $element->removeAttribute('src');
        }
    }
}

/**
 * KIỂM TRA CUỐI — khẳng định HTML đã sanitize không còn nội dung nguy hiểm.
 *
 * Sanitizer đã loại bỏ theo cấu trúc, nhưng ta vẫn kiểm tra lại ở cuối để không
 * bao giờ lọt một trường hợp biên nào vào content_html.
 */
function wa_capture_assert_clean(string $contentHtml): bool
{
    $lower = strtolower($contentHtml);

    // 1) Không còn thẻ nguy hiểm.
    foreach (['script', 'iframe', 'object', 'embed', 'form', 'style', 'noscript', 'svg'] as $tag) {
        if (str_contains($lower, '<' . $tag)) {
            return false;
        }
    }

    // 2) Không còn URL thực thi.
    if (str_contains($lower, 'javascript:') || str_contains($lower, 'vbscript:')) {
        return false;
    }

    // 3) Không còn thuộc tính sự kiện on* (onclick, onerror, onload...).
    if (preg_match('/\son[a-z]+\s*=/i', $lower) === 1) {
        return false;
    }

    return true;
}

/* =============================================================================
    HÀM CHÍNH
    ============================================================================= */

/**
 * Tải và bóc tách MỌT trang web.
 *
 * Ảnh CHƯA được tải về ở đây (cần snapshot_id trước); xem
 * wa_capture_localize_images(). Danh sách ảnh tìm thấy được trả về trong
 * khoá "images" với local_path rỗng.
 *
 * @return array{
 *   ok:bool, error_code:?string, error_message:string, http_status:int,
 *   final_url:string, title:string, content_html:string, content_text:string,
 *   images:array<int,array{original_url:string,local_path:string,mime_type:string,size_bytes:int}>,
 *   images_skipped:int, fetched_at:string
 * }
 */
function capture_url(string $url): array
{
    $url = trim($url);

    /* ---------- BƯỚC 1: KIỂM TRA URL ---------- */
    $parts = parse_url($url);

    if (!is_array($parts)) {
        return wa_capture_failure(WA_ERR_INVALID_URL, 'không phân tích được địa chỉ URL');
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host   = (string) ($parts['host'] ?? '');

    if ($scheme !== 'http' && $scheme !== 'https') {
        return wa_capture_failure(
            WA_ERR_INVALID_URL,
            'giao thức phải là http:// hoặc https://, nhận được "' . $scheme . '"'
        );
    }

    if ($host === '') {
        return wa_capture_failure(WA_ERR_INVALID_URL, 'URL không có tên miền');
    }

    // URL mang thông tin đăng nhập (user:pass@) thì từ chối.
    if (isset($parts['user']) || isset($parts['pass'])) {
        return wa_capture_failure(WA_ERR_INVALID_URL, 'URL không được chứa thông tin đăng nhập (user:password)');
    }

    /* ---------- BƯỚC 2: ALLOWLIST + SSRF TRƯỚC KHI GỌI MẠNG ---------- */
    if (!wa_capture_is_allowlisted($host)) {
        return wa_capture_failure(
            WA_ERR_BLOCKED,
            'tên miền "' . $host . '" không nằm trong danh sách được duyệt',
            0,
            $url
        );
    }

    // Chặn URL trỏ tới mạng nội bộ / địa chỉ dùng riêng (SSRF).
    if (!wa_capture_is_safe_public_url($url)) {
        return wa_capture_failure(
            WA_ERR_UNSAFE_URL,
            'địa chỉ "' . $host . '" phân giải tới mạng nội bộ hoặc địa chỉ dùng riêng',
            0,
            $url
        );
    }

    /* ---------- BƯỚC 3: TẢI BẰNG cURL ---------- */
    $response = wa_capture_http_get($url);

    if ($response['ok'] !== true) {
        return wa_capture_failure(
            (string) ($response['error_code'] ?? WA_ERR_HTTP),
            (string) ($response['detail'] ?? ''),
            (int) ($response['status'] ?? 0),
            (string) ($response['final_url'] ?? $url)
        );
    }

    $httpStatus  = (int) $response['status'];
    $finalUrl    = (string) $response['final_url'];
    $html        = (string) $response['body'];
    $contentType = (string) $response['content_type'];

    /* ---------- BƯỚC 4: MÃ HTTP + CONTENT-TYPE ---------- */
    $statusError = wa_capture_status_error($httpStatus);

    if ($statusError !== null) {
        $detail = match ($statusError) {
            WA_ERR_404         => 'mã 404 (không tìm thấy nội dung)',
            WA_ERR_FORBIDDEN    => 'mã 403 (bị từ chối truy cập)',
            WA_ERR_RATE_LIMIT   => 'mã 429 (bị giới hạn tần suất yêu cầu)',
            WA_ERR_SERVER_ERROR => 'mã lỗi máy chủ ' . $httpStatus,
            WA_ERR_TIMEOUT      => 'mã lỗi hết thời gian ' . $httpStatus,
            default             => 'mã lỗi HTTP ' . $httpStatus,
        };

        return wa_capture_failure($statusError, $detail, $httpStatus, $finalUrl);
    }

    if (!wa_capture_is_html($contentType)) {
        return wa_capture_failure(
            WA_ERR_NO_CONTENT,
            'Content-Type là "' . ($contentType !== '' ? $contentType : 'trống') . '" chứ không phải HTML',
            $httpStatus,
            $finalUrl
        );
    }

    if (trim($html) === '') {
        return wa_capture_failure(WA_ERR_NO_CONTENT, 'phản hồi rỗng', $httpStatus, $finalUrl);
    }

    /* ---------- BƯỚC 5: BÓC TÁCH BẰNG DOMDocument ---------- */
    $doc = wa_capture_dom($html);

    if ($doc === null) {
        return wa_capture_failure(WA_ERR_NO_CONTENT, 'không phân tích được HTML', $httpStatus, $finalUrl);
    }

    $title = wa_capture_extract_title($doc, $finalUrl);

    /* ---------- BƯỚC 4b: TRANG ĐĂNG NHẬP / CAPTCHA (P-FIX-CN2) ---------- */
    // Kiểm tra TRƯỚC khi bóc tách nội dung, vì một trang đăng nhập thường có
    // đủ văn bản để vượt ngưỡng 200 ký tự và sẽ bị lưu nhầm thành bài viết.
    $gatePage = wa_capture_detect_gate_page($doc);

    if ($gatePage === 'login') {
        return wa_capture_failure(
            WA_ERR_LOGIN,
            'trang nguồn là trang đăng nhập, không phải nội dung bài viết',
            $httpStatus,
            $finalUrl
        );
    }

    if ($gatePage === 'captcha') {
        return wa_capture_failure(
            WA_ERR_CAPTCHA,
            'trang nguồn yêu cầu giải CAPTCHA nên không lấy được nội dung',
            $httpStatus,
            $finalUrl
        );
    }

    // Gỡ phần tử rác TRƯỚC khi tìm vùng nội dung, để chúng không làm nhiễu
    // phép tính mật độ văn bản.
    wa_capture_remove_elements($doc, WA_STRIP_TAGS);

    $contentNode = wa_capture_find_content_node($doc);

    if ($contentNode === null) {
        return wa_capture_failure(WA_ERR_NO_CONTENT, 'không tìm thấy phần nội dung chính', $httpStatus, $finalUrl);
    }

    /* ---------- BƯỚC 6: ĐỘ DÀI VĂN BẢN ---------- */
    $rawHtml   = $doc->saveHTML($contentNode) ?: '';
    $rawText   = wa_capture_clean_text(wa_capture_text_only($rawHtml));
    $textChars = mb_strlen($rawText, 'UTF-8');

    if ($textChars < WA_CAPTURE_MIN_TEXT) {
        return wa_capture_failure(
            WA_ERR_NO_CONTENT,
            'phần nội dung chính chỉ ' . $textChars . ' ký tự, dưới ngưỡng ' . WA_CAPTURE_MIN_TEXT,
            $httpStatus,
            $finalUrl
        );
    }

    /* ---------- BƯỚC 7: SANITIZE ---------- */
    $contentHtml = wa_capture_sanitize($contentNode, $finalUrl);

    // Khẳng định an toàn trước khi tiếp tục.
    if (!wa_capture_assert_clean($contentHtml)) {
        return wa_capture_failure(
            WA_ERR_NO_CONTENT,
            'nội dung sau khi làm sạch vẫn chứa thẻ hoặc thuộc tính không an toàn',
            $httpStatus,
            $finalUrl
        );
    }

    /* ---------- BƯỚC 8 (một phần): GOM DANH SÁCH ẢNH ---------- */
    $images = wa_capture_collect_images($contentHtml);

    /* ---------- BƯỚC 9: content_text ---------- */
    $contentText = wa_capture_clean_text(wa_capture_text_only($contentHtml));

    return [
        'ok'             => true,
        'error_code'     => null,
        'error_message'  => '',
        'http_status'    => $httpStatus,
        'final_url'      => $finalUrl,
        'title'          => $title,
        'content_html'   => $contentHtml,
        'content_text'   => $contentText,
        'images'         => $images,
        'images_skipped' => 0,
        'fetched_at'     => date('Y-m-d H:i:s'),
    ];
}

/**
 * Gom danh sách URL ảnh (src) trong content_html đã sanitize, tối đa 20 ảnh.
 *
 * @return array<int, array{original_url:string, local_path:string, mime_type:string, size_bytes:int}>
 */
function wa_capture_collect_images(string $contentHtml): array
{
    $doc = wa_capture_dom('<html><body>' . $contentHtml . '</body></html>');

    if ($doc === null) {
        return [];
    }

    $nodes = (new DOMXPath($doc))->query('//img');
    if ($nodes === false) {
        return [];
    }

    $images = [];
    $seen   = [];

    foreach ($nodes as $img) {
        /** @var DOMElement $img */
        $src = trim($img->getAttribute('src'));
        if ($src === '' || isset($seen[$src])) {
            continue;
        }
        $seen[$src] = true;

        $images[] = [
            'original_url' => $src,
            'local_path'   => '',
            'mime_type'    => '',
            'size_bytes'   => 0,
        ];
    }

    return array_slice($images, 0, WA_CAPTURE_MAX_IMAGES);
}

/* =============================================================================
    BƯỚC 8 — TẢI ẢNH VỀ MÁY CHỦ NỘI BỘ
    ============================================================================= */

/**
 * Kiểm tra dữ liệu bytes có phải ảnh raster hợp lệ không.
 *
 * Chỉ chấp nhận image/jpeg, image/png, image/gif, image/webp — xác minh bằng
 * finfo và/hoặc getimagesizefromstring, KHÔNG tin vào phần mở rộng tệp.
 * SVG luôn bị từ chối vì có thể chứa mã thực thi.
 *
 * @return string|null mime type hợp lệ hoặc null
 */
function wa_capture_verify_image(string $bytes): ?string
{
    if ($bytes === '') {
        return null;
    }

    // SVG / XML -> từ chối ngay trước khi kiểm tra.
    $head = strtolower(substr($bytes, 0, 512));
    if (str_contains($head, '<svg') || str_contains($head, '<?xml')) {
        return null;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    // (1) finfo: đọc chữ ký nhị phân thật sự của nội dung.
    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_buffer($finfo, $bytes);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                $mime = strtolower($detected);
            }
        }
    }

    // (2) getimagesizefromstring: xác nhận đây đúng là ảnh, kèm kích thước.
    $info = @getimagesizefromstring($bytes);

    if ($mime !== null && in_array($mime, $allowed, true)) {
        // finfo nói đúng loại được phép.
        if ($info !== false) {
            return $mime;
        }
        // Một số bản PHP không đọc được kích thước webp nhưng vẫn xác định
        // đúng mime -> vẫn chấp nhận vì finfo đã xác nhận chữ ký.
        if ($mime === 'image/webp') {
            return $mime;
        }
    }

    // (3) Một số bản dựng không có webp trong bảng mime -> thử theo thông tin ảnh.
    if ($info !== false) {
        // getimagesizefromstring trả kích thước ở khoá số (0, 1) và mime ở
        // khoá chữ 'mime' — dùng đúng kiểu dữ liệu thực tế của PHP 8.3.
        $infoMime = strtolower((string) ($info['mime'] ?? ''));
        if (in_array($infoMime, $allowed, true)) {
            return $infoMime;
        }
    }

    return null;
}

/**
 * Đuôi tệp theo mime (chỉ 4 loại ảnh được phép).
 */
function wa_capture_extension_for_mime(string $mime): string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'bin',
    };
}

/**
 * Tải ảnh về public/uploads/snapshots/<snapshot_id>/ và nối lại bằng đường dẫn
 * cục bộ.
 *
 * Vì snapshot_id chỉ có sau khi INSERT, luồng ở public/save.php là:
 *   1) INSERT dòng snapshot (nội dung tạm rỗng)  -> có id
 *   2) gọi hàm này để tải ảnh xuống thư mục theo id
 *   3) UPDATE content_html với src đã trỏ về đường dẫn cục bộ
 *   4) INSERT các dòng assets
 *
 * Mọi ảnh được kiểm tra allowlist GIỐNG HỆT URL trang (kể cả từng bước chuyển
 * hướng). Ảnh ngoài allowlist, ảnh không phải raster hợp lệ, ảnh tải lỗi hoặc
 * vượt giới hạn đều bị GỎ khỏi HTML và đếm vào images_skipped — sau bước này
 * content_html không còn URL ảnh bên ngoài nào.
 *
 * @return array{html:string, images:array<int,array<string,mixed>>, skipped:int}
 */
function wa_capture_localize_images(string $contentHtml, int $snapshotId): array
{
    return wa_capture_localize_images_to($contentHtml, WA_PUBLIC . '/uploads/snapshots/' . $snapshotId, '/uploads/snapshots/' . $snapshotId . '/');
}

/**
 * FIX-03/FIX-04: tải ảnh vào THƯ MỤC TẠM (staged) trước khi có snapshot id.
 *
 * Trả về cùng cấu trúc như hàm chính, nhưng local_path trỏ tới thư mục tạm
 * `public/uploads/staged/<token>/`. Sau khi INSERT snapshot thành công, gọi
 * wa_capture_commit_staged_images() để chuyển tệp về thư mục thật và sửa lại
 * local_path trong mảng images (đã trỏ đúng URL đích).
 */
function wa_capture_localize_images_staged(string $contentHtml): array
{
    $token = bin2hex(random_bytes(8));
    $stagedDir = WA_PUBLIC . '/uploads/staged/' . $token;

    $result = wa_capture_localize_images_to($contentHtml, $stagedDir, '__WA_STAGED__/' . $token . '/');
    $result['staged_token'] = $token;
    $result['staged_dir'] = $stagedDir;

    return $result;
}

/**
 * FIX-03/FIX-04: chuyển ảnh từ thư mục tạm về thư mục snapshot thật.
 *
 * Sửa trực tiếp $finalHtml (tham chiếu): thay prefix tạm
 * `__WA_STAGED__/<token>/` bằng `/uploads/snapshots/<id>/`.
 * Sửa trực tiếp mảng $images (tham chiếu): local_path thành URL đích.
 * Dọn thư mục tạm sau khi chuyển.
 *
 * @param string $finalHtml HTML (tham chiếu) — được sửa tại chỗ.
 */
function wa_capture_commit_staged_images(int $snapshotId, string &$finalHtml, array &$images): void
{
    if ($images === []) {
        return;
    }

    $destDir = WA_PUBLIC . '/uploads/snapshots/' . $snapshotId;

    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return;
    }

    $tokens = [];

    foreach ($images as &$img) {
        $stagedPath = (string) ($img['staged_file'] ?? '');

        if ($stagedPath !== '' && is_file($stagedPath)) {
            $fileName = basename($stagedPath);
            $dest = $destDir . '/' . $fileName;

            if (@rename($stagedPath, $dest) || @copy($stagedPath, $dest)) {
                @unlink($stagedPath);
            }
        } else {
            $fileName = basename((string) ($img['local_path'] ?? ''));
        }

        // Tìm token tạm từ local_path cũ.
        if (preg_match('#__WA_STAGED__/([^/]+)/#', (string) ($img['local_path'] ?? ''), $m) === 1) {
            $tokens[$m[1]] = true;
        }

        $img['local_path'] = '/uploads/snapshots/' . $snapshotId . '/' . $fileName;
        unset($img['staged_file']);
    }

    unset($img);

    // Thay prefix tạm trong HTML bằng URL img.php đích có xác thực.
    foreach (array_keys($tokens) as $token) {
        $finalHtml = str_replace(
            '__WA_STAGED__/' . $token . '/',
            'img.php?id=' . $snapshotId . '&f=',
            $finalHtml
        );
        @rmdir(WA_PUBLIC . '/uploads/staged/' . $token);
    }
}

/**
 * Core tải ảnh dùng chung: ghi vào $targetDir, URL trong HTML dùng $urlPrefix.
 */
function wa_capture_localize_images_to(string $contentHtml, string $targetDir, string $urlPrefix): array
{
    $doc = wa_capture_dom('<html><body>' . $contentHtml . '</body></html>');

    if ($doc === null) {
        return ['html' => $contentHtml, 'images' => [], 'skipped' => 0];
    }

    $imgNodes = (new DOMXPath($doc))->query('//img');
    if ($imgNodes === false) {
        return ['html' => $contentHtml, 'images' => [], 'skipped' => 0];
    }

    $images  = [];
    $skipped = 0;
    $seen    = [];
    $handled = 0;

    foreach (iterator_to_array($imgNodes) as $img) {
        /** @var DOMElement $img */
        if ($img->parentNode === null) {
            continue;
        }

        $remove = static function () use ($img, &$skipped): void {
            if ($img->parentNode !== null) {
                $img->parentNode->removeChild($img);
                $skipped++;
            }
        };

        // Vượt giới hạn 20 ảnh -> gỡ, không được để lọt URL bên ngoài.
        if ($handled >= WA_CAPTURE_MAX_IMAGES) {
            $remove();
            continue;
        }
        $handled++;

        $original = trim($img->getAttribute('src'));
        if ($original === '') {
            $remove();
            continue;
        }

        // Trùng ảnh đã tải -> dùng lại tệp đã lưu.
        if (isset($seen[$original])) {
            $img->setAttribute('src', $seen[$original]['local_path']);
            continue;
        }

        // --- Ảnh ngoài allowlist -> bỏ và đếm ---
        if (!wa_capture_is_http_url($original) || !wa_capture_is_allowlisted(wa_capture_host($original))) {
            $remove();
            continue;
        }

        // --- Tải ảnh (tự chuyển hướng + allowlist ở mỗi bước) ---
        $response = wa_capture_http_get($original);
        if ($response['ok'] !== true) {
            $remove();
            continue;
        }

        $bytes = (string) $response['body'];

        // Chỉ nhận ảnh raster hợp lệ — kiểm tra nội dung thật, không tin đuôi tệp.
        $mime = wa_capture_verify_image($bytes);
        if ($mime === null) {
            $remove();
            continue;
        }

        // --- Ghi tệp với tên băm SHA-256 vào thư mục đích ---
        $fileName = substr(hash('sha256', $original . '|' . $bytes), 0, 32)
            . '.' . wa_capture_extension_for_mime($mime);
        $dir = $targetDir;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $remove();
            continue;
        }

        if (@file_put_contents($dir . '/' . $fileName, $bytes) === false) {
            $remove();
            continue;
        }

        $localUrl = $urlPrefix . $fileName;

        // FIX-01: HTML luôn trỏ qua endpoint img.php có xác thực.
        //  - Thư mục thật: wa_snapshot_image_url(<id>, <file>) -> img.php?id=..&f=..
        //  - Staged (id chưa có): giữ prefix tạm __WA_STAGED__/<token>/ trong
        //    HTML; commit thay bằng URL img.php đích sau khi có id thật.
        $isStaged = str_contains($urlPrefix, '__WA_STAGED__');

        if ($isStaged) {
            $img->setAttribute('src', $localUrl);
        } else {
            $snapIdForUrl = 0;

            if (preg_match('#/uploads/snapshots/(\d+)/#', $urlPrefix, $m) === 1) {
                $snapIdForUrl = (int) $m[1];
            }

            $img->setAttribute('src', wa_snapshot_image_url($snapIdForUrl, $fileName));
            $localUrl = wa_snapshot_image_url($snapIdForUrl, $fileName);
        }

        // Kích thước nằm ở khoá SỐ (0 = width, 1 = height); bản PHP này không
        // trả khoá chữ "width"/"height", nên phải đọc theo chỉ số.
        $size = @getimagesizefromstring($bytes);
        if ($size !== false) {
            $img->setAttribute('width', (string) (int) ($size[0] ?? 0));
            $img->setAttribute('height', (string) (int) ($size[1] ?? 0));
        }

        $seen[$original] = [
            'original_url' => $original,
            'local_path'   => $localUrl,
            'mime_type'    => $mime,
            'size_bytes'   => strlen($bytes),
            'staged_file'  => $dir . '/' . $fileName,
        ];
        $images[] = $seen[$original];
    }

    $body  = $doc->getElementsByTagName('body')->item(0);
    $html  = $body === null ? '' : (string) $doc->saveHTML($body);
    $html  = (string) preg_replace('#^<body[^>]*>#i', '', $html);
    $html  = (string) preg_replace('#</body>$#i', '', $html);
    $html  = trim($html);

    // Với staged: thay prefix tạm bằng URL đích `/uploads/snapshots/<id>/`
    // NGAY trong HTML (id chưa có nên giữ placeholder, commit sau sẽ thay
    // bằng id thật — thực tế commit thay trong DB images; HTML dùng hàm
    // wa_capture_staged_prefix_replace ở nơi gọi). Đơn giản hơn: giữ nguyên
    // prefix tạm, nơi gọi thay chuỗi sau khi có id.
    return ['html' => $html, 'images' => $images, 'skipped' => $skipped];
}

/**
 * FIX-01: URL ảnh snapshot qua endpoint có xác thực.
 *
 * Dạng: img.php?id=<snapshotId>&f=<tên tệp> (đường tương đối, giữ nguyên khi
 * ứng dụng chạy trong thư mục con nhờ wa_url() ở nơi hiển thị).
 */
function wa_snapshot_image_url(int $snapshotId, string $fileName): string
{
    return 'img.php?id=' . $snapshotId . '&f=' . rawurlencode(basename($fileName));
}

/**
 * FIX-01: chuẩn hoá src ảnh trong content_html cũ về dạng img.php.
 *
 * Snapshot cũ lưu `/uploads/snapshots/<id>/<file>` — hàm này chuyển thành
 * `img.php?id=<id>&f=<file>` để ảnh cũ cũng đi qua xác thực. src đã là
 * img.php thì giữ nguyên. Ngoài ra trả về null khi src lạ (để nơi gọi gỡ).
 */
function wa_snapshot_image_src_fixed(string $src, int $snapshotId): ?string
{
    $src = trim($src);

    if ($src === '') {
        return null;
    }

    if (str_starts_with($src, 'img.php?')) {
        return $src;
    }

    if (preg_match('#^/uploads/snapshots/(\d+)/([^/]+)$#', $src, $m) === 1) {
        return wa_snapshot_image_url((int) $m[1] > 0 ? (int) $m[1] : $snapshotId, $m[2]);
    }

    if (preg_match('#^uploads/snapshots/(\d+)/([^/]+)$#', $src, $m) === 1) {
        return wa_snapshot_image_url((int) $m[1] > 0 ? (int) $m[1] : $snapshotId, $m[2]);
    }

    return null;
}

/**
 * Kiểm tra cuối: content_html sau khi tải ảnh KHÔNG còn URL ảnh bên ngoài.
 *
 * Mọi <img src> phải là đường dẫn cục bộ (img.php? hoặc /uploads/ cũ tương
 * thích); nếu không thì ảnh đó sẽ bị gỡ.
 */
function wa_capture_localize_images_ok(string $contentHtml): bool
{
    $doc = wa_capture_dom('<html><body>' . $contentHtml . '</body></html>');

    if ($doc === null) {
        return true;
    }

    $nodes = (new DOMXPath($doc))->query('//img');
    if ($nodes === false) {
        return true;
    }

    foreach ($nodes as $img) {
        /** @var DOMElement $img */
        $src = trim($img->getAttribute('src'));
        if ($src === '' || wa_capture_is_http_url($src)) {
            return false;
        }
    }

    return true;
}

/**
 * FIX-01: viết lại mọi src ảnh trong HTML hiển thị về dạng img.php có xác thực.
 *
 * Dùng ở reader (hiển thị) để snapshot CŨ (src /uploads/…) cũng đi qua xác
 * thực mà không cần sửa dữ liệu đã lưu. src lạ (http ngoài, data:, …) giữ
 * nguyên để các lớp kiểm tra khác xử lý.
 */
function wa_snapshot_html_auth(string $contentHtml, int $snapshotId): string
{
    if ($contentHtml === '' || str_contains($contentHtml, '<img') === false) {
        return $contentHtml;
    }

    $doc = wa_capture_dom('<html><body>' . $contentHtml . '</body></html>');

    if ($doc === null) {
        return $contentHtml;
    }

    $nodes = (new DOMXPath($doc))->query('//img[@src]');

    if ($nodes === false) {
        return $contentHtml;
    }

    $changed = false;

    foreach (iterator_to_array($nodes) as $img) {
        if (!$img instanceof DOMElement) {
            continue;
        }

        $fixed = wa_snapshot_image_src_fixed($img->getAttribute('src'), $snapshotId);

        if ($fixed !== null && $fixed !== $img->getAttribute('src')) {
            $img->setAttribute('src', $fixed);
            $changed = true;
        }
    }

    if (!$changed) {
        return $contentHtml;
    }

    $body = $doc->getElementsByTagName('body')->item(0);
    $html = $body === null ? '' : (string) $doc->saveHTML($body);
    $html = (string) preg_replace('#^<body[^>]*>#i', '', $html);
    $html = (string) preg_replace('#</body>$#i', '', $html);

    return trim($html);
}