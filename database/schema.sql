-- =============================================================================
-- database/schema.sql — Lược đồ cơ sở dữ liệu WebArchive (đề tài 16)
-- -----------------------------------------------------------------------------
-- MySQL 8.0 / InnoDB / utf8mb4 / utf8mb4_unicode_ci
-- CẢNH BÁO: tệp DROP và tạo lại toàn bộ bảng; chạy sẽ xoá dữ liệu hiện có.
-- Chỉ dùng để cài mới hoặc sau khi đã sao lưu và xác nhận được phép thay thế.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- users — tài khoản người dùng
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name`     VARCHAR(150)  NOT NULL,
    `email`         VARCHAR(190)  NOT NULL,
    `password_hash` VARCHAR(255)  NOT NULL,
    `role`          ENUM('admin','user')         NOT NULL DEFAULT 'user',
    `status`        ENUM('active','locked')      NOT NULL DEFAULT 'active',
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- libraries — thư viện chuyên đề
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `libraries`;
CREATE TABLE `libraries` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner_id`    INT UNSIGNED NOT NULL,
    `name`        VARCHAR(150)  NOT NULL,
    `slug`        VARCHAR(150)  NOT NULL,
    `description` TEXT          NULL,
    `visibility`  ENUM('private','shared','public') NOT NULL DEFAULT 'private',
    `color`       VARCHAR(32)   NOT NULL DEFAULT 'a',
    `icon`        VARCHAR(64)   NOT NULL DEFAULT 'folder_special',
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_libraries_slug` (`slug`),
    KEY `ix_libraries_owner` (`owner_id`),
    CONSTRAINT `fk_libraries_owner`
        FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- library_members — thành viên & phân quyền trong thư viện
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `library_members`;
CREATE TABLE `library_members` (
    `library_id` INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NOT NULL,
    `role`       ENUM('owner','editor','viewer') NOT NULL DEFAULT 'viewer',
    PRIMARY KEY (`library_id`, `user_id`),
    KEY `ix_library_members_user` (`user_id`),
    CONSTRAINT `fk_library_members_library`
        FOREIGN KEY (`library_id`) REFERENCES `libraries` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_library_members_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- tags — nhãn phân loại
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `tags`;
CREATE TABLE `tags` (
    `id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64)  NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tags_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- saved_pages — các URL đã lưu trong thư viện
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `saved_pages`;
CREATE TABLE `saved_pages` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `library_id` INT UNSIGNED NOT NULL,
    `source_url` VARCHAR(1000) NOT NULL,
    `domain`     VARCHAR(255)  NOT NULL,
    `title`      VARCHAR(500)  NOT NULL,
    `status`     ENUM('active','error','deleted') NOT NULL DEFAULT 'active',
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_saved_pages_library` (`library_id`),
    KEY `ix_saved_pages_domain` (`domain`),
    KEY `ix_saved_pages_created_by` (`created_by`),
    CONSTRAINT `fk_saved_pages_library`
        FOREIGN KEY (`library_id`) REFERENCES `libraries` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_saved_pages_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- page_tags — liên kết thẻ với trang đã lưu
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `page_tags`;
CREATE TABLE `page_tags` (
    `page_id` INT UNSIGNED NOT NULL,
    `tag_id`  INT UNSIGNED NOT NULL,
    PRIMARY KEY (`page_id`, `tag_id`),
    KEY `ix_page_tags_tag` (`tag_id`),
    CONSTRAINT `fk_page_tags_page`
        FOREIGN KEY (`page_id`) REFERENCES `saved_pages` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_page_tags_tag`
        FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- snapshots — các phiên bản nội dung đã lưu của một trang
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `snapshots`;
CREATE TABLE `snapshots` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `page_id`       INT UNSIGNED NOT NULL,
    `version_no`    INT UNSIGNED NOT NULL DEFAULT 1,
    `title`         VARCHAR(500)  NOT NULL,
    `content_html`  MEDIUMTEXT    NULL,
    `content_text`  MEDIUMTEXT    NULL,
    `source_url`    VARCHAR(1000) NOT NULL,
    `fetched_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `http_status`   SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    `fetch_status`  ENUM('success','error_invalid_url','error_blocked','error_timeout',
                          'error_404','error_403','error_429','error_5xx','error_ssl',
                          'error_login','error_captcha','error_unsafe',
                          'error_no_content','error_http') NOT NULL DEFAULT 'success',
    `error_message` VARCHAR(500)  NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_snapshots_page_version` (`page_id`, `version_no`),
    KEY `ix_snapshots_page` (`page_id`),
    CONSTRAINT `fk_snapshots_page`
        FOREIGN KEY (`page_id`) REFERENCES `saved_pages` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- search_terms — CHỈ MỤC ĐẢO (INVERTED INDEX) TỰ XÂY DỰNG cho tìm kiếm (P-SEARCH)
-- -----------------------------------------------------------------------------
-- Mỗi dòng là một (từ đã bỏ dấu, snapshot) kèm số lần xuất hiện trong tiêu đề
-- và trong thân bài. Cơ chế này do dự án TỰ LÀM — KHÔNG dùng FULLTEXT /
-- MATCH ... AGAINST của MySQL.
--   * term        : token gốc đã hạ chữ thường (giữ nguyên dấu tiếng Việt).
--   * term_folded : token sau khi bỏ dấu tiếng Việt — đây là khoá so khớp.
--   * UNIQUE (term_folded, snapshot_id): một từ chỉ có MỘT dòng cho mỗi bản
--     snapshot, nên đếm tần suất ngay trong lúc lập chỉ mục (không cần quét lại
--     content_text lúc tìm).
--   * FK ON DELETE CASCADE: xoá snapshot thì dòng chỉ mục của nó tự biến mất.
-- LƯU Ý kiểu dữ liệu: `id` dùng BIGINT UNSIGNED (bảng chỉ mục có thể rất lớn),
-- còn `snapshot_id` phải là INT UNSIGNED để khớp đúng kiểu cột được tham chiếu
-- `snapshots.id` — MySQL không cho khoá ngoại giữa hai kiểu khác nhau.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `search_terms`;
CREATE TABLE `search_terms` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `term`        VARCHAR(100)   NOT NULL,
    `term_folded` VARCHAR(100)   NOT NULL,
    `snapshot_id` INT UNSIGNED  NOT NULL,
    `count_title` INT UNSIGNED   NOT NULL DEFAULT 0,
    `count_body`  INT UNSIGNED   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_search_terms_folded_snapshot` (`term_folded`, `snapshot_id`),
    KEY `ix_search_terms_folded` (`term_folded`),
    CONSTRAINT `fk_search_terms_snapshot`
        FOREIGN KEY (`snapshot_id`) REFERENCES `snapshots` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- assets — tài nguyên (ảnh) tải về máy chủ nội bộ
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `assets`;
CREATE TABLE `assets` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `snapshot_id`  INT UNSIGNED NOT NULL,
    `original_url` VARCHAR(1000) NOT NULL,
    `local_path`   VARCHAR(500)  NOT NULL,
    `mime_type`    VARCHAR(120)  NOT NULL DEFAULT 'application/octet-stream',
    `size_bytes`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `ix_assets_snapshot` (`snapshot_id`),
    CONSTRAINT `fk_assets_snapshot`
        FOREIGN KEY (`snapshot_id`) REFERENCES `snapshots` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- notes — ghi chú của người dùng lên một phiên bản snapshot
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `notes`;
CREATE TABLE `notes` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `snapshot_id` INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `content`     TEXT          NOT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_notes_snapshot` (`snapshot_id`),
    KEY `ix_notes_user` (`user_id`),
    CONSTRAINT `fk_notes_snapshot`
        FOREIGN KEY (`snapshot_id`) REFERENCES `snapshots` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notes_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- allowlist_domains — các tên miền được duyệt để lưu trang
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `allowlist_domains`;
CREATE TABLE `allowlist_domains` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain`     VARCHAR(255)  NOT NULL,
    `note`       VARCHAR(255)  NULL,
    `added_by`   INT UNSIGNED NOT NULL,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_allowlist_domain` (`domain`),
    KEY `ix_allowlist_added_by` (`added_by`),
    CONSTRAINT `fk_allowlist_added_by`
        FOREIGN KEY (`added_by`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- domain_requests — hàng đợi yêu cầu duyệt tên miền (đồng bộ với scripts/migrate.php M2)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `domain_requests` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain`       VARCHAR(255) NOT NULL,
    `status`       ENUM('pending','approved','rejected','disabled') NOT NULL DEFAULT 'pending',
    `note`         VARCHAR(500) NULL,
    `requested_by` INT UNSIGNED NOT NULL,
    `reviewed_by`  INT UNSIGNED NULL,
    `reviewed_at`  DATETIME     NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_domain_requests_domain` (`domain`),
    KEY `ix_domain_requests_status` (`status`),
    KEY `ix_domain_requests_requester` (`requested_by`),
    CONSTRAINT `fk_domain_requests_requester`
        FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_domain_requests_reviewer`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- admin_logs — nhật ký thao tác quản trị (đồng bộ với scripts/migrate.php M3)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_logs` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_id`    INT UNSIGNED NULL,
    `action`      VARCHAR(100) NOT NULL,
    `target_type` VARCHAR(60) NULL,
    `target_id`   VARCHAR(120) NULL,
    `detail`      VARCHAR(500) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_admin_logs_created` (`created_at`),
    KEY `ix_admin_logs_actor` (`actor_id`),
    CONSTRAINT `fk_admin_logs_actor`
        FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- capture_attempts — lịch sử mọi lần thử tải (FIX-09: không xoá lịch sử lỗi cũ)
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

SET FOREIGN_KEY_CHECKS = 1;
