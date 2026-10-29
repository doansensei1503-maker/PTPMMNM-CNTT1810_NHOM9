-- database/migrations/003_capture_attempts.sql — LỊCH SỬ LẦN THỬ TẢI (FIX-09).
-- -----------------------------------------------------------------------------
-- Bảng `capture_attempts` lưu MỌI lần thử tải (thành công hay thất bại) mà
-- KHÔNG bao giờ bị xoá — khác với bản ghi lỗi version_no=0 trong `snapshots`
-- (chỉ giữ 1 bản mới nhất để hiển thị).
--
-- AN TOÀN: CREATE TABLE IF NOT EXISTS, không đụng dữ liệu cũ.
-- Quy trình: sao lưu -> phê duyệt -> mysql < tệp này.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `capture_attempts` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `page_id`     INT UNSIGNED NOT NULL,
    `library_id`  INT UNSIGNED NOT NULL,
    `source_url`  VARCHAR(1000) NOT NULL,
    `ok`          TINYINT(1) NOT NULL DEFAULT 0,
    `status_code` VARCHAR(60) NOT NULL DEFAULT '',
    `http_status` VARCHAR(10) NOT NULL DEFAULT '',
    `detail`      VARCHAR(500) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_capture_attempts_page` (`page_id`),
    KEY `ix_capture_attempts_library` (`library_id`),
    CONSTRAINT `fk_capture_attempts_page`
        FOREIGN KEY (`page_id`) REFERENCES `saved_pages` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_capture_attempts_library`
        FOREIGN KEY (`library_id`) REFERENCES `libraries` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
