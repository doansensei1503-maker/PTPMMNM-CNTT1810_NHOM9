<?php
/**
 * tests/samples/chuyen-huong-ngoai.php
 * -----------------------------------------------------------------------------
 * CA THỬ NGHIỆM "CHUYỂN HƯỚNG RA NGOÀI" (P-FINAL).
 *
 * Trang này trả về lệnh chuyển hướng HTTP 302 tới một tên miền KHÔNG nằm
 * trong danh sách tên miền được duyệt (`example.com` cố ý không có trong
 * allowlist).
 *
 * Kỳ vọng của công cụ lưu trữ:
 *   - Mỗi đích chuyển hướng đều phải được kiểm tra lại với danh sách tên miền
 *     được duyệt, KHÔNG chỉ địa chỉ ban đầu.
 *   - Vì vậy lần lưu này phải thất bại với mã trạng thái `error_blocked`
 *     (URL không thuộc danh sách được duyệt), chứ KHÔNG phải thành công.
 *
 * Không có nội dung bài viết nào ở đây — mục đích chỉ là tạo ra một lệnh
 * chuyển hướng.
 * --------------------------------------------------------------------------- */

declare(strict_types=1);

header('Location: https://example.com/khong-duoc-duyet', true, 302);
header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8">'
   . '<title>Đang chuyển hướng ra ngoài</title></head><body>'
   . '<p>Trang này đang chuyển hướng tới một tên miền chưa được duyệt.</p>'
   . '</body></html>';
