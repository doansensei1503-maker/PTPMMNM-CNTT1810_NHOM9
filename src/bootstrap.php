<?php
/**
 * bootstrap.php
 * -----------------------------------------------------------------------------
 * Điểm khởi động chung cho mọi trang trong public/.
 *
 * Nhiệm vụ:
 *   - Xác định đường dẫn gốc của ứng dụng (để trang trong public/admin/ vẫn
 *     trỏ đúng về tài nguyên và trang khác).
 *   - Nạp các tệp dùng chung: db.php, auth.php, queries.php, capture.php.
 *   - Cung cấp các hàm trợ giúp: wa_e() (escape), wa_url(), wa_asset().
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

/* ---------------------------------------------------------------------------
   Polyfill cho các hàm multibyte.
   ----------------------------------------------------------------------------
   mbstring là extension TUỲ CHỌN của PHP và có thể chưa được bật trên một số
   máy Windows khi cài PHP từ file nén (bản winget mặc định chưa có php.ini).
   Vì toàn bộ giao diện là tiếng Việt nên các hàm này là bắt buộc. Bên dưới ta
   cung cấp lại chúng bằng iconv và bảng tra ký tự hợp lệ, để trang không bao giờ
   báo lỗi "Call to undefined function".
   --------------------------------------------------------------------------- */

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        $length = @iconv_strlen($string, 'UTF-8');
        return $length === false ? strlen($string) : $length;
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        $result = $length === null
            ? @iconv_substr($string, $start, null, 'UTF-8')
            : @iconv_substr($string, $start, $length, 'UTF-8');

        return $result === false ? '' : $result;
    }
}

/** Tách chuỗi UTF-8 thành mảng ký tự (tương thích khi thiếu mbstring). */
if (!function_exists('wa_str_split')) {
    function wa_str_split(string $string): array
    {
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? [] : $chars;
    }
}

/**
 * BẢNG CHỮ CÁI TIẾNG VIỆT — NGUỒN DUY NHẤT CHO MỌI BẢNG TRA KÝ TỰ.
 *
 * Danh sách chữ tiếng Việt của dự án chỉ tồn tại ở ĐÂY, dưới dạng nhóm theo
 * "chữ gốc không dấu". Mỗi nhóm có ba chuỗi SONG SONG — cùng số phần tử, cùng
 * thứ tự, và phần tử đầu luôn là chữ gốc:
 *
 *     fold  — chữ không dấu thay thế (ô→o, đ→d, ư→u)
 *     upper — các biến thể viết hoa:  A À Á Ả Ã Ạ Ă Ằ ...
 *     lower — các biến thể viết thường: a à á ả ã ạ ă ằ ...
 *
 * Từ đây dựng ra HAI bảng tra dùng chung của cả dự án:
 *     wa_vi_case_map() — hạ chữ hoa (chỉ khi máy thiếu mbstring)
 *     wa_vi_fold_map() — BỎ DẤU tiếng Việt (tìm kiếm P-SEARCH)
 *
 * Gom về một chỗ là để hai bảng không thể lệch nhau. Lệch chính là nguyên nhân
 * của lỗi dạng "bỏ dấu sai": nếu cột hoa và cột thường lệch vị trí, ông `à`
 * sẽ được gán nhầm sang dấu của chữ khác (ví dụ `bài` → `bơi`).
 *
 * @return array<int, array{fold:string, upper:string, lower:string}>
 */
if (!function_exists('wa_vi_alphabet')) {
    /**
     * @return array<int, array{fold:string, upper:string, lower:string}>
     */
    function wa_vi_alphabet(): array
    {
        return [
            [
                'fold'  => 'a',
                'upper' => 'AÀÁẢÃẠĂẰẮẲẴẶÂẦẤẨẪẬ',
                'lower' => 'aàáảãạăằắẳẵặâầấẩẫậ',
            ],
            [
                'fold'  => 'e',
                'upper' => 'EÈÉẺẼẸÊỀẾỂỄỆ',
                'lower' => 'eèéẻẽẹêềếểễệ',
            ],
            [
                'fold'  => 'i',
                'upper' => 'IÌÍỈĨỊ',
                'lower' => 'iìíỉĩị',
            ],
            [
                'fold'  => 'o',
                'upper' => 'OÒÓỎÕỌÔỒỐỔỖỘƠỜỚỞỠỢ',
                'lower' => 'oòóỏõọôồốổỗộơờớởỡợ',
            ],
            [
                'fold'  => 'u',
                'upper' => 'UÙÚỦŨỤƯỪỨỬỮỰ',
                'lower' => 'uùúủũụưừứửữự',
            ],
            [
                'fold'  => 'y',
                'upper' => 'YỲÝỶỸỴ',
                'lower' => 'yỳýỷỹỵ',
            ],
            [
                'fold'  => 'd',
                'upper' => 'DĐ',
                'lower' => 'dđ',
            ],
        ];
    }
}

/**
 * Bảng tra chữ thường ↔ chữ hoa (dạng precomposed) cho tiếng Việt.
 * Chỉ dùng khi máy không có mbstring.
 */
if (!function_exists('wa_vi_case_map')) {
    function wa_vi_case_map(): array
    {
        static $map = null;

        if ($map === null) {
            $map = [];

            foreach (wa_vi_alphabet() as $group) {
                $uppers = wa_str_split($group['upper']);
                $lowers = wa_str_split($group['lower']);

                foreach ($uppers as $index => $upperChar) {
                    $map[$upperChar] = $lowers[$index] ?? $upperChar;
                }
            }

            // Chữ Latin ASCII không dấu — cần để hạ chữ hoa xuống.
            foreach (range('A', 'Z') as $asciiUpper) {
                $map[$asciiUpper] = strtolower($asciiUpper);
            }
        }

        return $map;
    }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string
    {
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($string, MB_CASE_LOWER, 'UTF-8');
        }

        return strtr($string, wa_vi_case_map());
    }
}

/* ---------------------------------------------------------------------------
   BẢNG BỎ DẤU TIẾNG VIỆT — NGUỒN DUY NHẤT CỦA CẢ DỰ ÁN (P-SEARCH)
   ----------------------------------------------------------------------------
   Bảng dựng MỘT LẦN từ wa_vi_alphabet() rồi sắp xếp theo thứ tự byte tăng dần
   để strtr() khớp từ chuỗi dài nhất trước — nhờ vậy "ươ" được bỏ thành "uo"
   chứ không bị cắt thành "u"+"ơ".

   Toàn bộ cơ chế bỏ dấu của dự án (tìm kiếm, so khớp nội dung...) đều gọi
   wa_vi_fold_map(); KHÔNG chép thêm bảng tra ra chỗ khác.
   --------------------------------------------------------------------------- */

if (!function_exists('wa_vi_fold_map')) {
    /**
     * Bảng tra: ký tự tiếng Việt (cả hoa lẫn thường) → ký tự không dấu.
     *
     * @return array<string,string>
     */
    function wa_vi_fold_map(): array
    {
        static $map = null;

        if ($map === null) {
            $map = [];

            foreach (wa_vi_alphabet() as $group) {
                foreach (wa_str_split($group['lower']) as $char) {
                    $map[$char] = $group['fold'];
                }

                foreach (wa_str_split($group['upper']) as $char) {
                    $map[$char] = $group['fold'];
                }
            }

            // Cụm hai chữ "ươ"/"uơ" (cả bản hoa). LƯU Ý: phải ra "uo", KHÔNG
            // phải "u" — vì ư -> u và ơ -> o, nên "hương" bỏ dấu ra "huong"
            // chứ không phải "hung". Cụm cũng được sắp lên trước các ký tự đơn
            // bằng uksort bên dưới (độ dài lớn hơn), để strtr() không khớp
            // "ư" trước rồi mới xử lý "ơ".
            $map['ươ'] = 'uo';
            $map['ƯƠ'] = 'uo';
            $map['uơ'] = 'uo';
            $map['UƠ'] = 'uo';

            // Sắp xếp theo độ dài giảm dần để strtr() thử chuỗi dài trước —
            // nhờ vậy "ươ" ra "uo" chứ không bị cắt thành "u" + "ơ".
            uksort($map, static function (string $a, string $b): int {
                $byLength = mb_strlen($b) <=> mb_strlen($a);

                return $byLength !== 0 ? $byLength : strcmp($a, $b);
            });
        }

        return $map;
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding = null): string
    {
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($string, MB_CASE_UPPER, 'UTF-8');
        }

        return strtr($string, array_flip(wa_vi_case_map()));
    }
}

define('WA_ROOT', dirname(__DIR__));
define('WA_SRC', WA_ROOT . '/src');
define('WA_TEMPLATES', WA_ROOT . '/templates');
define('WA_PUBLIC', WA_ROOT . '/public');

require_once WA_SRC . '/db.php';
require_once WA_SRC . '/auth.php';
require_once WA_SRC . '/queries.php';
require_once WA_SRC . '/capture.php';
require_once WA_SRC . '/search.php';

/**
 * Đường dẫn gốc của ứng dụng trên web.
 *
 * public/ là web root, nên mọi tài nguyên và trang đều được trỏ bằng đường dẫn
 * tuyệt đối tính từ web root (ví dụ: /assets/css/app.css, /libraries.php,
 * /admin/users.php). Cách này giúp các trang trong public/admin/ không bị sai
 * đường dẫn tài nguyên.
 */
function wa_base_path(): string
{
    return '';
}

/**
 * Dựng URL nội bộ của ứng dụng.
 */
function wa_url(string $path = ''): string
{
    if ($path === '') {
        return '/';
    }

    return '/' . ltrim($path, '/');
}

/**
 * Dựng URL của tài nguyên tĩnh trong public/assets.
 */
function wa_asset(string $path): string
{
    return wa_url('assets/' . ltrim($path, '/'));
}

/**
 * Escape HTML an toàn.
 */
function wa_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Rút gọn văn bản.
 */
function wa_trim(string $value, int $limit = 120): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (mb_strlen($value) <= $limit) {
        return $value;
    }

    return rtrim(mb_substr($value, 0, $limit - 1, 'UTF-8')) . '…';
}
