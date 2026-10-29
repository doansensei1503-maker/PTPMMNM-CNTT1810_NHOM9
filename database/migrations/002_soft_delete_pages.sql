/**
 * database/migrations/002_soft_delete_pages.sql — XOÁ MỀM BÀI VIẾT (P-DELETE).
 * -----------------------------------------------------------------------------
 * Vấn đề: `saved_pages.status` là ENUM('active','error') — không có trạng thái
 * "đã xoá". Chức năng xoá bài cần một trạng thái riêng để ẨN bài mà KHÔNG xoá
 * dòng, KHÔNG xoá snapshot/notes (khôi phục được).
 *
 * Thay đổi DUY NHẤT: mở rộng ENUM thành ('active','error','deleted').
 *   - Dữ liệu cũ giữ nguyên (active/error không đổi nghĩa).
 *   - Các dòng đang 'active'/'error' không bị ảnh hưởng.
 *   - ROLLBACK: ALTER TABLE ... MODIFY ... ENUM('active','error') — chỉ chạy
 *     được khi không còn dòng nào mang status='deleted'.
 *
 * KHÔNG chạy trực tiếp trên database thật. Quy trình an toàn:
 *   1. Sao lưu: mysqldump ...
 *   2. Duyệt tệp này.
 *   3. Chạy: mysql < database/migrations/002_soft_delete_pages.sql
 *      (hoặc: php scripts/migrate_soft_delete.php)
 */

ALTER TABLE `saved_pages`
    MODIFY `status` ENUM('active', 'error', 'deleted')
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    NOT NULL DEFAULT 'active';
