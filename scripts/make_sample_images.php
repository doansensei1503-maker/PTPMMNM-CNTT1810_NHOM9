<?php
/**
 * scripts/make_sample_images.php
 * -----------------------------------------------------------------------------
 * SINH ẢNH PNG MẪU CHO BỘ DỮ LIỆU tests/samples/ (P-FINAL).
 *
 * Mỗi ảnh là một PNG thật, kích thước 64×64, màu khác nhau. Tệp được sinh bằng
 * TAY (không dùng thư viện GD — máy cài PHP tối giểu thường không có GD):
 *     IHDR + IDAT (dữ liệu pixel nén zlib) + IEND, mỗi khối có CRC32 riêng.
 *
 * CÁCH CHẠY (từ thư viện gốc dự án):
 *
 *     php scripts/make_sample_images.php
 *
 * Kịch bản idempotent: chạy lại chỉ ghi đè các tệp PNG trong tests/samples/.
 * --------------------------------------------------------------------------- */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'scripts/make_sample_images.php chỉ chạy được từ dòng lệnh (CLI).';
    exit(1);
}

$targetDir = dirname(__DIR__) . '/tests/samples';

if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
    fwrite(STDERR, "[LỖI] Không tạo được thư mục {$targetDir}\n");
    exit(1);
}

/**
 * Dựng một khối PNG (length + type + data + CRC).
 */
function wa_sample_png_chunk(string $type, string $data): string
{
    $crc = crc32($type . $data) & 0xFFFFFFFF;

    return pack('N', strlen($data)) . $type . $data . pack('N', $crc);
}

/**
 * Dựng một tệp PNG RGB 8-bit, kích thước $width × $height, màu nền $rgb.
 *
 * Mỗi dòng pixel bắt đầu bằng byte "filter type" = 0 (None) — đây là cách
 * đơn giản nhất và vẫn tạo ra tệp PNG hoàn toàn hợp lệ.
 */
function wa_sample_png(int $width, int $height, array $rgb): string
{
    $raw = '';

    for ($y = 0; $y < $height; $y++) {
        $raw .= "\x00";                                   // filter: None
        $raw .= str_repeat(chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]), $width);
    }

    $png  = "\x89PNG\r\n\x1a\n";
    $png .= wa_sample_png_chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0));
    $png .= wa_sample_png_chunk('IDAT', (string) gzcompress($raw, 9));
    $png .= wa_sample_png_chunk('IEND', '');

    return $png;
}

/**
 * Danh sách ảnh cần sinh: tên tệp => màu nền RGB.
 *
 * Mỗi ảnh có một màu KHÁC HẲN nhau để khi kiểm thử có thể phân biệt được
 * ảnh nào đã được tải về máy chủ nội bộ.
 */
$images = [
    'anh-01.png' => [ 32,  86, 140],   // xanh dương đậm  — bai-01
    'anh-02.png' => [ 40, 156, 145],   // xanh ngọc        — bai-02
    'anh-03.png' => [198,  92,  60],   // cam              — bai-08 (ảnh 1)
    'anh-04.png' => [124,  62, 148],   // tím              — bai-08 (ảnh 2)
    'anh-05.png' => [ 92, 128,  56],   // xanh lá          — bai-08 (ảnh 3)
];

foreach ($images as $fileName => $rgb) {
    $path = $targetDir . '/' . $fileName;
    $bytes = wa_sample_png(64, 64, $rgb);

    if (@file_put_contents($path, $bytes) === false) {
        fwrite(STDERR, "[LỖI] Không ghi được {$path}\n");
        exit(1);
    }

    // Kiểm tra lại bằng chính PHP: tệp phải là ảnh raster hợp lệ.
    $info = @getimagesizefromstring($bytes);

    if ($info === false) {
        fwrite(STDERR, "[LỖI] {$fileName} không phải là ảnh hợp lệ.\n");
        exit(1);
    }

    printf("  · %-12s %d×%d  %s  %d byte\n", $fileName, $info[0], $info[1], $info['mime'], strlen($bytes));
}

echo "\nĐã sinh xong " . count($images) . " ảnh PNG trong tests/samples/.\n";
exit(0);
