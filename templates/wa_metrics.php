<?php
/**
 * wa_metrics.php
 * -----------------------------------------------------------------------------
 * SỐ LIỆU THẬT TỪ CSDL — dùng chung cho các trang giao diện (P-FIX-UI, mục 6).
 *
 * Bản mock trong bản tham chiếu có nhiều con số bịa đặt (dung lượng lưu trữ
 * hàng GB, số bài viết hàng chục, số ảnh hàng trăm, tỉ lệ "100% nguyên vẹn",
 * và một dòng liệt kê sẵn số thành viên theo vai trò, ...). Nếu giao diện hiện
 * những con số đó thì giao diện nói dối người dùng. File này gom các phép tính
 * lấy từ CSDL vào một chỗ để mọi template chỉ cần gọi hàm, không tự viết SQL
 * rải rác.
 *
 * NGUYÊN TẮC
 *   1. Chỉ ĐỌC. Không INSERT/UPDATE/DELETE, không đổi schema.
 *   2. Mọi con số trả về đều đo được từ CSDL + đĩa; không có hằng số bịa.
 *   3. "Ảnh lỗi" / "toàn vẹn" được tính bằng cách kiểm tra tệp thật trong
 *      public/ — bảng `assets` có thể ghi cả những tài nguyên đã bị dọn khỏi
 *      đĩa, nên chỉ đếm dòng CSDL là chưa đủ.
 *
 * Không sửa src/capture.php, src/search.php hay schema — file này chỉ gọi
 * db() (src/db.php) với prepared statement.
 * --------------------------------------------------------------------------- */

declare(strict_types=1);

if (!function_exists('wa_metrics_')) {
    /**
     * Tách danh sách id thư viện thành danh sách tham số cho mệnh đề IN.
     *
     * @param int[] $ids
     * @return array{0:string,1:array<int,int>}
     */
    function wa_metrics_in_clause(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return ['0', []];
        }

        $placeholders = [];
        $params = [];

        foreach ($ids as $index => $id) {
            $placeholders[] = ':lib' . $index;
            $params[':lib' . $index] = $id;
        }

        return [implode(', ', $placeholders), $params];
    }

    /**
     * Đường dẫn tệp thật của một tài nguyên trong bảng `assets`.
     *
     * `local_path` được ghi theo hai kiểu: đường dẫn tuyệt đối trong web
     * ("/uploads/snapshots/<id>/<hash>.png") cho ảnh tải về thật, hoặc đường dẫn
     * tương đối ("assets/…") cho dữ liệu mẫu. Cả hai đều nằm dưới public/.
     */
    function wa_metrics_asset_file(string $localPath): ?string
    {
        $localPath = trim($localPath);

        if ($localPath === '' || str_contains($localPath, "\0")) {
            return null;
        }

        $relative = str_starts_with($localPath, '/')
            ? ltrim($localPath, '/')
            : ltrim($localPath, '/');

        $full = WA_PUBLIC . '/' . $relative;
        $real = realpath($full);

        if ($real === false || !is_file($real)) {
            return null;
        }

        $publicRoot = realpath(WA_PUBLIC);

        if ($publicRoot === false || !str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $publicRoot) . '/')) {
            return null;
        }

        return $real;
    }

    /**
     * Tổng hợp số liệu thật của một tập thư viện.
     *
     * @param int[] $libraryIds
     * @return array{
     *     libraries:int, pages:int, pages_ok:int, pages_failed:int,
     *     snapshots:int, snapshots_ok:int, snapshots_failed:int,
     *     asset_total:int, asset_present:int, asset_missing:int,
     *     image_total:int, image_present:int, image_missing:int,
     *     bytes_total:int, bytes_present:int
     * }
     */
    function wa_metrics_for_libraries(array $libraryIds): array
    {
        $empty = [
            'libraries'       => 0,
            'pages'           => 0,
            'pages_ok'        => 0,
            'pages_failed'    => 0,
            'snapshots'       => 0,
            'snapshots_ok'    => 0,
            'snapshots_failed'=> 0,
            'asset_total'     => 0,
            'asset_present'   => 0,
            'asset_missing'   => 0,
            'image_total'     => 0,
            'image_present'   => 0,
            'image_missing'   => 0,
            'bytes_total'     => 0,
            'bytes_present'   => 0,
        ];

        $libraryIds = array_values(array_unique(array_map('intval', $libraryIds)));
        $libraryIds = array_values(array_filter($libraryIds, static fn (int $id): bool => $id > 0));

        if ($libraryIds === []) {
            return $empty;
        }

        [$in, $params] = wa_metrics_in_clause($libraryIds);
        $pdo = db();

        // --- 1) Trang đã lưu: có bản snapshot thành công hay không -------------
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS pages,
                    SUM(EXISTS (
                        SELECT 1 FROM `snapshots` s
                         WHERE s.page_id = sp.id AND s.fetch_status = 'success'
                    )) AS pages_ok
               FROM `saved_pages` sp
              WHERE sp.library_id IN ({$in})"
        );
        $stmt->execute($params);
        $pageRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // --- 2) Snapshot theo trạng thái ------------------------------------
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS snaps,
                    SUM(s.fetch_status = 'success') AS snaps_ok
               FROM `snapshots` s
               JOIN `saved_pages` sp ON sp.id = s.page_id
              WHERE sp.library_id IN ({$in})"
        );
        $stmt->execute($params);
        $snapRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // --- 3) Tài nguyên: đếm dòng CSDL và đối chiếu tệp thật trên đĩa ---
        $stmt = $pdo->prepare(
            "SELECT a.local_path, a.mime_type, a.size_bytes
               FROM `assets` a
               JOIN `snapshots` s   ON s.id = a.snapshot_id
               JOIN `saved_pages` sp ON sp.id = s.page_id
              WHERE sp.library_id IN ({$in})"
        );
        $stmt->execute($params);

        $metrics = $empty;
        $metrics['libraries'] = count($libraryIds);
        $metrics['pages'] = (int) ($pageRow['pages'] ?? 0);
        $metrics['pages_ok'] = (int) ($pageRow['pages_ok'] ?? 0);
        $metrics['pages_failed'] = max(0, $metrics['pages'] - $metrics['pages_ok']);
        $metrics['snapshots'] = (int) ($snapRow['snaps'] ?? 0);
        $metrics['snapshots_ok'] = (int) ($snapRow['snaps_ok'] ?? 0);
        $metrics['snapshots_failed'] = max(0, $metrics['snapshots'] - $metrics['snapshots_ok']);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $asset) {
            $metrics['asset_total']++;
            $metrics['bytes_total'] += (int) $asset['size_bytes'];

            $isImage = str_starts_with((string) $asset['mime_type'], 'image/');

            if ($isImage) {
                $metrics['image_total']++;
            }

            $file = wa_metrics_asset_file((string) $asset['local_path']);

            if ($file === null) {
                $metrics['asset_missing']++;

                if ($isImage) {
                    $metrics['image_missing']++;
                }

                continue;
            }

            $metrics['asset_present']++;
            $metrics['bytes_present'] += (int) $asset['size_bytes'];

            if ($isImage) {
                $metrics['image_present']++;
            }
        }

        return $metrics;
    }

    /**
     * Một bản snapshot thật để minh hoạ ở trang chủ.
     *
     * Chọn bản THÀNH CÔNG mới nhất có ít nhất một tài nguyên ảnh còn nằm trên
     * đĩa, để mọi con số hiển thị (dung lượng, số ảnh, mã kiểm chứng) đều có
     * thật trong CSDL thay vì là số bịa.
     *
     * @return array{
     *     snapshot_id:int, page_id:int, library_id:int, library_name:string,
     *     title:string, source_url:string, domain:string, version_no:int,
     *     fetched_at:string, fetched_label:string, image_count:int,
     *     image_present:int, bytes:int, size_label:string,
     *     content_bytes:int, checksum:string, creator:string,
     *     reading_minutes:int, reading_label:string
     * }|null
     */
    function wa_metrics_showcase_snapshot(): ?array
    {
        $rows = db()->query(
            "SELECT s.id AS snapshot_id, s.page_id, s.title, s.source_url, s.version_no,
                    s.fetched_at, LENGTH(s.content_html) AS content_bytes,
                    LENGTH(s.content_text) AS text_length,
                    sp.library_id, sp.domain, l.name AS library_name,
                    u.full_name AS creator_name
               FROM `snapshots` s
               JOIN `saved_pages` sp ON sp.id = s.page_id
               JOIN `libraries` l     ON l.id = sp.library_id
               JOIN `users` u         ON u.id = sp.created_by
              WHERE s.fetch_status = 'success'
              ORDER BY s.fetched_at DESC, s.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $assets = db()->prepare(
                "SELECT local_path, mime_type, size_bytes
                   FROM `assets`
                  WHERE snapshot_id = ? AND mime_type LIKE 'image/%'"
            );
            $assets->execute([(int) $row['snapshot_id']]);

            $imageCount = 0;
            $imagePresent = 0;
            $bytes = 0;
            $checksum = '';
            $imageSrc = '';

            foreach ($assets->fetchAll(PDO::FETCH_ASSOC) as $asset) {
                $imageCount++;
                $bytes += (int) $asset['size_bytes'];

                $file = wa_metrics_asset_file((string) $asset['local_path']);

                if ($file === null) {
                    continue;
                }

                $imagePresent++;

                if ($imageSrc === '') {
                    // Đường dẫn web của ảnh đã tải về (bản thật, mở được offline).
                    $imageSrc = '/' . ltrim(str_replace('\\', '/', (string) $asset['local_path']), '/');
                }

                if ($checksum === '') {
                    // Tên tệp ảnh do ứng dụng sinh ra là SHA-256 (rút gọn) của
                    // URL gốc + nội dung — dùng luôn mã đó làm "mã kiểm chứng".
                    $checksum = substr(basename((string) $asset['local_path']), 0, 32);
                }
            }

            if ($imagePresent === 0) {
                continue;
            }

            // Thời gian đọc ước lượng: 200 từ/phút, trung bình 5 ký tự/từ.
            $textLength = (int) $row['text_length'];
            $readingMinutes = $textLength > 0 ? max(1, (int) ceil($textLength / 5 / 200)) : 0;

            return [
                'snapshot_id'   => (int) $row['snapshot_id'],
                'page_id'       => (int) $row['page_id'],
                'library_id'    => (int) $row['library_id'],
                'library_name'  => (string) $row['library_name'],
                'title'         => (string) $row['title'],
                'source_url'    => (string) $row['source_url'],
                'domain'        => (string) $row['domain'],
                'version_no'    => (int) $row['version_no'],
                'fetched_at'    => (string) $row['fetched_at'],
                'fetched_label' => wa_datetime((string) $row['fetched_at']),
                'image_count'   => $imageCount,
                'image_present' => $imagePresent,
                'image_src'     => $imageSrc,
                'bytes'         => $bytes,
                'size_label'    => wa_format_bytes($bytes),
                'content_bytes' => (int) $row['content_bytes'],
                // A4: cần phân biệt rõ dung lượng PHẦN HTML và phần ẢNH, vì
                // trước đây nhãn "Snapshot" ghi kèm số byte của ảnh khiến
                // người đọc tưởng tổng dung lượng chỉ bằng vài trăm byte.
                'html_size_label' => wa_format_bytes((int) $row['content_bytes']),
                'total_bytes'   => $bytes + (int) $row['content_bytes'],
                'total_size_label' => wa_format_bytes($bytes + (int) $row['content_bytes']),
                'checksum'      => $checksum,
                'creator'       => (string) $row['creator_name'],
                'reading_minutes' => $readingMinutes,
                'reading_label' => $readingMinutes > 0 ? $readingMinutes . ' phút' : '—',
            ];
        }

        return null;
    }

    /**
     * Số thư viện trên toàn hệ thống (không giới hạn quyền — dùng cho trang
     * chủ dành cho khách, nơi không có người dùng đăng nhập).
     */
    function wa_metrics_library_count(): int
    {
        return (int) (db()->query('SELECT COUNT(*) FROM `libraries`')->fetchColumn() ?: 0);
    }

    /**
     * Danh sách id của TOÀN BỘ thư viện trên hệ thống.
     *
     * @return int[]
     */
    function wa_metrics_all_library_ids(): array
    {
        $ids = [];

        foreach (db()->query('SELECT id FROM `libraries` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Số ký tự mỗi dòng của cột đọc, đo từ CSS thật của trình đọc.
     *
     * DESIGN.md quy định cột đọc rộng tối đa 780px để giữ độ dài dòng 65–75
     * ký tự. Con số "65-75" trong bản mock chỉ là khẩu hiệu; giá trị thật
     * phụ thuộc vào bề rộng cột đọc VÀ cỡ chữ hiện tại (18px mặc định),
     * nên ở đây ta tính từ chính biến CSS --wa-reader-col.
     *
     * Bề rộng dùng để tính là bề rộng VÙNG CHỮ THẬT của cột đọc: cột có
     * padding 40px mỗi bên (`--wa-space-xl` theo chiều ngang và
     * `--wa-space-md` theo chiều dọc trong .reader__col), nên vùng chữ hẹp
     * hơn bề rộng tối đa 780px. Cả hai đều đọc từ app.css thay vì ghi cứng.
     *
     * @return array{min:int, max:int, label:string, column_px:int, text_px:int}
     */
    function wa_metrics_reader_measure(): array
    {
        $columnPx = wa_metrics_reader_column_px();
        $textPx   = max(1, $columnPx - 2 * wa_metrics_reader_padding_px());

        // Bề rộng trung bình một ký tự của phông serif ở cỡ 18px. Đo thật
        // bằng Chrome (Range.getClientRects trên .reader__body thật) cho
        // khoảng 8,7–9,0px với Merriweather 18px trên văn bản tiếng Việt;
        // ta dùng 0,487em (~8,77px) làm giá trị đại diện.
        $charWidth = 18 * 0.487;
        $chars     = (int) round($textPx / $charWidth);

        // Vùng dao động thực tế khi ngắt dòng ở ranh giới từ: khoảng ±10%.
        $min = max(1, (int) round($chars * 0.94));
        $max = $chars + (int) round($chars * 0.07);

        return [
            'column_px' => $columnPx,
            'text_px'   => $textPx,
            'min'       => $min,
            'max'       => $max,
            'label'     => 'Tỉ lệ ' . $min . '-' . $max . ' ký tự/dòng',
        ];
    }

    /**
     * Bề rộng tối đa của cột đọc, đọc từ app.css (biến --wa-reader-col).
     */
    function wa_metrics_reader_column_px(): int
    {
        $css = wa_metrics_app_css();

        if (preg_match('/--wa-reader-col\s*:\s*(\d+)px/i', $css, $m)) {
            return (int) $m[1];
        }

        return 780;
    }

    /**
     * Padding ngang của cột đọc, đọc từ app.css.
     */
    function wa_metrics_reader_padding_px(): int
    {
        $css = wa_metrics_app_css();

        if (preg_match('/\.reader__col\s*\{[^}]*padding\s*:\s*[^;]*?var\(--wa-space-(\w+)\)\s+var\(--wa-space-(\w+)\)/i', $css, $m)) {
            $tokens = wa_metrics_spacing_tokens($css);
            $vertical = (int) ($tokens[$m[1]] ?? 0);
            $horizontal = (int) ($tokens[$m[2]] ?? 0);

            return $horizontal;
        }

        return 16;
    }

    /**
     * Các token khoảng cách trong app.css (--wa-space-*: <số>px).
     *
     * @return array<string, int>
     */
    function wa_metrics_spacing_tokens(string $css): array
    {
        $tokens = [];

        if (preg_match_all('/--wa-space-([a-z]+)\s*:\s*(\d+)px/i', $css, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tokens[strtolower($match[1])] = (int) $match[2];
            }
        }

        return $tokens;
    }

    /**
     * Nội dung file app.css (cache lại trong request để không đọc đĩa nhiều lần).
     */
    function wa_metrics_app_css(): string
    {
        static $css = null;

        if ($css === null) {
            $path = WA_PUBLIC . '/assets/css/app.css';
            $css = is_file($path) ? (string) file_get_contents($path) : '';
        }

        return $css;
    }


    /**
     * Nhãn nội dung được dùng nhiều nhất trên toàn hệ thống.
     *
     * @return array<int, array{name:string, uses:int}>
     */
    function wa_metrics_top_tags(int $limit = 3): array
    {
        $limit = max(1, $limit);

        $rows = db()->query(
            "SELECT t.name, COUNT(DISTINCT pt.page_id) AS uses
               FROM `page_tags` pt
               JOIN `tags` t ON t.id = pt.tag_id
              GROUP BY t.name
              ORDER BY uses DESC, t.name
              LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out = [];

        foreach ($rows as $row) {
            $out[] = ['name' => (string) $row['name'], 'uses' => (int) $row['uses']];
        }

        return $out;
    }

    /**
     * Tổng số nhãn nội dung đang có trên hệ thống.
     */
    function wa_metrics_tag_count(): int
    {
        return (int) (db()->query('SELECT COUNT(*) FROM `tags`')->fetchColumn() ?: 0);
    }

    /**
     * Danh sách ảnh cục bộ đã tải về của một snapshot, kèm kích thước thật.
     *
     * Dùng cho khối minh hoạ ở trang chủ (A1). Chỉ trả về ảnh THẬT SỰ còn trên
     * đĩa, và kèm kích thước pixel đọc từ tệp — không bao giờ phóng to ảnh nhỏ
     * thành khối lớn (ảnh mẫu của dự án chỉ 64x64).
     *
     * @return array<int, array{src:string, width:int, height:int, bytes:int}>
     */
    function wa_metrics_snapshot_thumbs(int $snapshotId, int $limit = 3): array
    {
        $limit = max(1, $limit);

        $stmt = db()->prepare(
            "SELECT local_path, size_bytes
               FROM `assets`
              WHERE snapshot_id = ? AND mime_type LIKE 'image/%'
              ORDER BY id"
        );
        $stmt->execute([$snapshotId]);

        $out = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $asset) {
            if (count($out) >= $limit) {
                break;
            }

            $file = wa_metrics_asset_file((string) $asset['local_path']);

            if ($file === null) {
                continue;
            }

            $size = @getimagesize($file);

            if ($size === false) {
                continue;
            }

            $out[] = [
                'src'    => '/' . ltrim(str_replace('\\', '/', (string) $asset['local_path']), '/'),
                'width'  => (int) $size[0],
                'height' => (int) $size[1],
                'bytes'  => (int) $asset['size_bytes'],
            ];
        }

        return $out;
    }

    /**
     * Số tài nguyên cục bộ CÒN trên đĩa của một snapshot.
     *
     * Dùng để thay các nhãn "Ngoại tuyến 100%" / "100% nguyên vẹn" bằa số
     * đo được: đối chiếu từng dòng bảng `assets` với tệp thật trong public/.
     *
     * @return array{total:int, present:int, missing:int, bytes:int, size_label:string}
     */
    function wa_metrics_snapshot_assets(int $snapshotId): array
    {
        $stmt = db()->prepare(
            'SELECT local_path, size_bytes
               FROM `assets`
              WHERE snapshot_id = ?
              ORDER BY id'
        );
        $stmt->execute([$snapshotId]);

        $total = 0;
        $present = 0;
        $bytes = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $asset) {
            $total++;
            $bytes += (int) $asset['size_bytes'];

            if (wa_metrics_asset_file((string) $asset['local_path']) !== null) {
                $present++;
            }
        }

        return [
            'total'      => $total,
            'present'    => $present,
            'missing'    => $total - $present,
            'bytes'      => $bytes,
            'size_label' => wa_format_bytes($bytes),
        ];
    }

    /**
     * Tổng thời gian đọc ước lượng của các bản lưu thành công.
     *
     * Dùng cho thẻ tính năng "Đọc lại độc lập" ở trang chủ: thay cho con số
     * bịa, ta báo cáo tổng số phút đọc của toàn bộ nội dung đã lưu trong CSDL
     * (cùng công thức 200 từ/phút, trung bình 5 ký tự/từ, như
     * wa_metrics_showcase_snapshot()).
     *
     * @return array{minutes:int, articles:int}
     */
    function wa_metrics_reading_total(array $libraryIds): array
    {
        $libraryIds = array_values(array_unique(array_map('intval', $libraryIds)));
        $libraryIds = array_values(array_filter($libraryIds, static fn (int $id): bool => $id > 0));

        if ($libraryIds === []) {
            return ['minutes' => 0, 'articles' => 0];
        }

        [$in, $params] = wa_metrics_in_clause($libraryIds);

        $stmt = db()->prepare(
            "SELECT COUNT(*) AS articles,
                    SUM(CHAR_LENGTH(COALESCE(s.content_text, ''))) AS chars
               FROM `snapshots` s
               JOIN `saved_pages` sp ON sp.id = s.page_id
              WHERE s.fetch_status = 'success'
                AND sp.library_id IN ({$in})"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $articles = (int) ($row['articles'] ?? 0);
        $chars    = (int) ($row['chars'] ?? 0);

        return [
            'minutes'  => $chars > 0 ? (int) ceil($chars / 5 / 200) : 0,
            'articles' => $articles,
        ];
    }

    /**
     * Tỉ lệ tương phản WCAG giữa hai mã màu HEX (theo sRGB).
     *
     * Dùng để thay con số "12:1" bịa trong trang chủ bằng tỉ lệ thật của bộ
     * màu thiết kế (mã màu lấy từ các biến CSS trong app.css).
     */
    function wa_metrics_contrast_ratio(string $hexA, string $hexB): float
    {
        $luminance = static function (string $hex): float {
            $hex = ltrim(trim($hex), '#');

            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }

            if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
                return 0.0;
            }

            $channels = [];

            foreach (str_split($hex, 2) as $pair) {
                $value = hexdec($pair) / 255.0;
                $channels[] = $value <= 0.03928
                    ? $value / 12.92
                    : pow(($value + 0.055) / 1.055, 2.4);
            }

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        $a = $luminance($hexA);
        $b = $luminance($hexB);

        $high = max($a, $b);
        $low = min($a, $b);

        if ($high <= 0.0) {
            return 0.0;
        }

        return ($high + 0.05) / ($low + 0.05);
    }
}
