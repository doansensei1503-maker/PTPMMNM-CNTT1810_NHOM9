<?php
/**
 * tests/samples/cham.php
 * -----------------------------------------------------------------------------
 * CA THỬ NGHIỆM "HẾT THỜI GIAN CHỜ" (P-FINAL).
 *
 * Trang này NGỦ 15 GIÂY trước khi in ra nội dung bài viết. Công cụ lưu trữ
 * giới hạn thời gian chờ (kết nối 5 giây, tổng 12 giây), nên lần lưu này phải
 * thất bại với mã trạng thái `error_timeout` — KHÔNG được lưu thành bài viết.
 *
 * Lưu ý: giới hạn thời gian của PHP mặc định trên máy chủ thử nghiệm không
 * tính vào thời gian chờ của công cụ tải trang, nên kịch bản này chỉ dùng để
 * kiểm thử tự động (T3), không dùng trong luồng thông thường.
 * --------------------------------------------------------------------------- */

declare(strict_types=1);

sleep(15);

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8">'
   . '<title>Bài chậm — trang phản hồi quá lâu</title></head><body>'
   . '<article><h1>Bài chậm</h1>'
   . '<p>Trang này đã ngủ 15 giây trước khi phản hồi, vượt quá giới hạn thời gian '
   . 'chờ của công cụ lưu trữ. Vì vậy nó phải bị ghi nhận là lỗi hết thời gian chờ '
   . 'chứ không phải là một bài viết hợp lệ.</p>'
   . '<p>Mã bài mẫu của trang này là <code>mauChamWebarchive</code>, nhưng vì lần '
   . 'lưu phải thất bại nên nó không bao giờ xuất hiện trong kết quả tìm kiếm.</p>'
   . '</article></body></html>';
