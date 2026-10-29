<?php
/**
 * src/queries.php
 * -----------------------------------------------------------------------------
 * Lớp truy vấn dữ liệu cho WebArchive — thay thế src/sample_data.php (UI phase).
 *
 * Mọi truy vấn đều dùng prepared statement; KHÔNG có nối chuỗi SQL với dữ liệu
 * do người dùng nhập.
 *
 * Quy tắc truy cập cơ bản (giai đoạn này):
 *   - Người dùng thường chỉ thấy các thư viện mà họ SỞ HỮU hoặc là THÀNH VIÊN.
 *   - Quản trị viên thấy tất cả.
 *   - Việc siết chặt phân quyền chi tiết (editor/viewer) sẽ làm ở phase sau.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Thư mục public/ — nơi lưu ảnh đã tải về (dùng bởi phần xoá thư viện P-LIB). */
if (!defined('WA_PUBLIC')) {
    define('WA_PUBLIC', dirname(__DIR__) . '/public');
}

/* =============================================================================
    SLUG THƯ VIỆN — P-LIB
    -----------------------------------------------------------------------------
    Slug sinh từ tên thư viện: hạ chữ thường -> BỎ DẤU bằng bảng tra dùng chung
    wa_vi_fold_map() của src/bootstrap.php (dự án chỉ có MỘT bảng bỏ dấu) ->
    khoảng trắng thành dấu gạch ngang -> các ký tự không phù hợp bị bỏ.

    Nếu slug đã tồn tại, nối thêm -2, -3, ... cho tới khi duy nhất (cột
    `libraries`.`slug` có UNIQUE).
    ============================================================================= */

/** Số ký tự tối đa của slug (khớp VARCHAR(150) của bảng libraries). */
const WA_LIBRARY_SLUG_MAX = 120;

/**
 * Bỏ dấu + hạ chữ thường + thay khoảng trắng bằng dấu gạch ngang.
 *
 * Dùng ĐÚNG bảng tra dùng chung `wa_vi_fold_map()` — không chép thêm bảng thứ hai.
 */
function wa_slugify(string $text): string
{
    $folded = strtr(mb_strtolower(trim($text), 'UTF-8'), wa_vi_fold_map());

    // Mọi ký tự không phải chữ/số ASCII thành gạch ngang, rồi gộp dấu gạch.
    $slug = (string) preg_replace('/[^a-z0-9]+/u', '-', $folded);
    $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');

    if ($slug === '') {
        return 'thu-vien';
    }

    if (strlen($slug) > WA_LIBRARY_SLUG_MAX) {
        $slug = rtrim(substr($slug, 0, WA_LIBRARY_SLUG_MAX), '-');
    }

    return $slug;
}

/**
 * Sinh slug DUY NHẤT cho một tên thư viện.
 *
 * Nếu slug gốc đã có trong bảng `libraries`, nối tiếp -2, -3, ... cho tới khi
 * không còn trùng. `$excludeId` cho phép giữ nguyên slug khi ĐỔI TÊN một thư viện
 * đang có (bỏ qua chính dòng đó khi kiểm tra trùng).
 *
 * @return string{slug:string, suffix:int} slug cuối cùng và số hậu tố đã dùng (0 = không thêm).
 */
function wa_library_unique_slug(string $name, int $excludeId = 0): array
{
    $base = wa_slugify($name);

    $exists = static function (string $slug) use ($excludeId): bool {
        $stmt = db()->prepare('SELECT 1 FROM `libraries` WHERE `slug` = ? AND `id` <> ? LIMIT 1');
        $stmt->execute([$slug, $excludeId]);

        return $stmt->fetchColumn() !== false;
    };

    if (!$exists($base)) {
        return ['slug' => $base, 'suffix' => 0];
    }

    // -2, -3, ... cho tới khi duy nhất. `slug` UNIQUE nên số thứ tự này là duy nhất.
    for ($suffix = 2; $suffix <= 999; $suffix++) {
        $candidate = $base . '-' . $suffix;

        if (!$exists($candidate)) {
            return ['slug' => $candidate, 'suffix' => $suffix];
        }
    }

    // Trường hợp cực hiểm: thêm dấu thời gian để vẫn ra slug hợp lệ.
    return ['slug' => $base . '-' . time(), 'suffix' => 999];
}

/**
 * Ngữ cảnh người dùng đang đăng nhập (id + vai trò) dùng để lọc truy cập.
 *
 * @return array{id:int, role:string}|null
 */
function wa_actor(): ?array
{
    $user = current_user();

    if ($user === null || !isset($user['id'])) {
        return null;
    }

    return [
        'id'   => (int) $user['id'],
        'role' => (string) ($user['role'] ?? 'user'),
    ];
}

/**
 * Người dùng hiện tại có quyền quản trị không.
 */
function wa_is_admin(): bool
{
    $actor = wa_actor();

    return $actor !== null && $actor['role'] === 'admin';
}

/**
 * Đoạn SQL lọc các thư viện mà người dùng được phép thấy.
 *
 * Lưu ý kỹ thuật: PDO đang dùng prepares THẬT (ATTR_EMULATE_PREPARES = false),
 * nên MySQL không cho phép một tham số có tên được lặp lại nhiều lần trong cùng
 * câu truy vấn. Vì vậy mỗi chỗ cần so sánh với id người dùng phải dùng một tên
 * tham số KHÁC NHAU (:actor_owner và :actor_member), dù giá trị truyền vào là
 * giống nhau. Dùng chung một tên sẽ gây lỗi SQLSTATE[HY093] Invalid parameter number.
 *
 * @return array{0:string,1:array<int|string,mixed>}
 */
function wa_library_access_sql(?array $actor): array
{
    if ($actor !== null && $actor['role'] === 'admin') {
        return ['1 = 1', []];
    }

    if ($actor === null) {
        return ['1 = 0', []];
    }

    $actorId = (int) $actor['id'];

    return [
        '(l.owner_id = :actor_owner OR EXISTS (
              SELECT 1 FROM `library_members` lm
              WHERE lm.library_id = l.id AND lm.user_id = :actor_member
          ))',
        [
            ':actor_owner'  => $actorId,
            ':actor_member' => $actorId,
        ],
    ];
}

/**
 * Nhãn tiếng Việt của vai trò thành viên.
 *
 * @return array{owner:string,editor:string,viewer:string}
 */
function wa_member_role_labels(): array
{
    return [
        'owner'  => 'Chủ sở hữu',
        'editor' => 'Biên tập viên',
        'viewer' => 'Người xem',
    ];
}

/**
 * Nhãn tiếng Việt của mức độ hiển thị thư viện.
 *
 * @return array{private:string,shared:string,public:string}
 */
function wa_visibility_labels(): array
{
    return [
        'private' => 'Chỉ mình tôi',
        'shared'  => 'Nội bộ nhóm',
        'public'  => 'Công khai',
    ];
}

/**
 * Nhãn tiếng Việt của trạng thái tài khoản.
 *
 * @return array{active:string,locked:string}
 */
function wa_user_status_labels(): array
{
    return [
        'active' => 'Hoạt động',
        'locked' => 'Bị khóa',
    ];
}

/**
 * Nhãn tiếng Việt của vai trò tài khoản.
 *
 * @return array{admin:string,user:string}
 */
function wa_user_role_labels(): array
{
    return [
        'admin' => 'Quản trị viên',
        'user'  => 'Người dùng',
    ];
}

/**
 * Tên biến thể avatar (màu) theo vai trò thành viên.
 */
function wa_avatar_variant(string $role): string
{
    return match ($role) {
        'owner'  => 'navy',
        'editor' => 'blue',
        default  => 'light',
    };
}

/**
 * Chữ viết tắt (2 ký tự đầu) của một họ tên tiếng Việt.
 */
function wa_initials(string $fullName): string
{
    $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
    $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));

    if ($parts === []) {
        return 'KH';
    }

    $first = mb_substr($parts[0], 0, 1, 'UTF-8');
    $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : '';

    return mb_strtoupper($first . $last, 'UTF-8');
}

/**
 * Định dạng ngày giờ kiểu Việt Nam: d/m/Y, H:i.
 */
function wa_datetime(string $value): string
{
    $ts = strtotime($value);

    if ($ts === false) {
        return $value;
    }

    return date('d/m/Y, H:i', $ts);
}

/**
 * Định dạng ngày kiểu Việt Nam: d/m/Y.
 */
function wa_date(string $value): string
{
    $ts = strtotime($value);

    if ($ts === false) {
        return $value;
    }

    return date('d/m/Y', $ts);
}

/**
 * Định dạng dung lượng (byte -> KB / MB).
 */
function wa_format_bytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2, ',', '.') . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }

    return $bytes . ' B';
}

/**
 * Định dạng dung lượng gọn (dùng cho thẻ thư viện): "142 MB".
 */
function wa_format_mb(int $bytes): string
{
    $mb = $bytes / (1024 * 1024);

    return ($mb >= 10 ? number_format($mb, 0, ',', '.') : number_format($mb, 1, ',', '.')) . ' MB';
}

/* =============================================================================
    THƯ VIỆN
    ============================================================================= */

/**
 * Danh sách thư viện mà người dùng hiện tại được thấy, kèm số liệu tổng hợp.
 *
 * @return array<int, array<string, mixed>>
 */
function wa_libraries(?array $actor = null): array
{
    $actor = $actor ?? wa_actor();
    [$accessSql, $params] = wa_library_access_sql($actor);

    $sql = "
        SELECT
            l.id, l.owner_id, l.name, l.slug, l.description, l.visibility,
            l.color, l.icon, l.created_at,
            u.full_name AS owner_name,
            lm.role     AS member_role,
            (SELECT COUNT(*) FROM `saved_pages` sp
              WHERE sp.library_id = l.id AND sp.status <> 'deleted')                                 AS article_count,
            (SELECT COUNT(*) FROM `assets` a
                JOIN `snapshots` s ON s.id = a.snapshot_id
                JOIN `saved_pages` sp2 ON sp2.id = s.page_id
              WHERE sp2.library_id = l.id AND a.mime_type LIKE 'image/%')                          AS image_count,
            (SELECT COALESCE(SUM(a.size_bytes), 0) FROM `assets` a
                JOIN `snapshots` s ON s.id = a.snapshot_id
                JOIN `saved_pages` sp2 ON sp2.id = s.page_id
              WHERE sp2.library_id = l.id)                                                         AS storage_bytes,
            (SELECT COUNT(*) FROM `library_members` lm2 WHERE lm2.library_id = l.id)                AS member_count,
            (SELECT COUNT(DISTINCT pt.tag_id) FROM `page_tags` pt
                JOIN `saved_pages` sp3 ON sp3.id = pt.page_id
              WHERE sp3.library_id = l.id)                                                          AS tag_count
        FROM `libraries` l
        JOIN `users` u ON u.id = l.owner_id
        LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :actor_user
        WHERE {$accessSql}
        ORDER BY l.id
    ";

    // Nếu không phải admin, câu truy vấn cần cả :actor_user; nếu admin thì
    // left join vẫn dùng :actor_user nên phải truyền giá trị (null khi khách).
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([':actor_user' => $actor['id'] ?? 0], $params));

    $roleLabels = wa_member_role_labels();
    $visLabels  = wa_visibility_labels();
    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']             = (int) $row['id'];
        $row['owner_id']       = (int) $row['owner_id'];
        $row['article_count']  = (int) $row['article_count'];
        $row['image_count']    = (int) $row['image_count'];
        $row['storage_bytes']  = (int) $row['storage_bytes'];
        $row['member_count']   = (int) $row['member_count'];
        $row['tag_count']      = (int) $row['tag_count'];

        // Vai trò: chủ sở hữu nếu là owner, ngược lại lấy vai trò thành viên.
        if ((int) $row['owner_id'] === ($actor['id'] ?? -1)) {
            $row['role'] = 'owner';
        } else {
            $row['role'] = (string) ($row['member_role'] ?? 'viewer');
        }
        if (!isset($roleLabels[$row['role']])) {
            $row['role'] = 'viewer';
        }
        $row['role_label'] = $roleLabels[$row['role']];

        $row['visibility_label'] = $visLabels[(string) $row['visibility']] ?? $row['visibility'];
        $row['strip']    = (string) $row['color'];
        // Màu tự chọn (hex): không có class strip, template dùng style
        // inline; màu chữ trên nền hex tính theo độ sáng.
        $row['color_hex'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $row['color']) === 1
            ? (string) $row['color'] : '';
        $row['color_ink'] = $row['color_hex'] !== '' && wa_hex_is_light($row['color_hex'])
            ? '#1F2937' : '#FFFFFF';
        $row['category'] = $row['visibility'] === 'private' ? 'personal' : 'shared';
        $row['storage_label'] = wa_format_mb($row['storage_bytes']);
        $row['updated']  = 'Cập nhật ' . wa_datetime((string) $row['created_at']);
        $row['tags']     = wa_library_tags($row['id']);
        $row['starred']  = false;
        $row['broken_images'] = 0;
        $row['integrity'] = '100% nguyên vẹn';

        $rows[] = $row;
    }

    return $rows;
}

/**
 * Danh sách nhãn của một thư viện.
 *
 * Không dùng SELECT DISTINCT ... ORDER BY t.id: với chế độ ONLY_FULL_GROUP_BY của
 * MySQL 8, cột trong ORDER BY phải nằm trong SELECT list, nên sẽ sinh lỗi 3065.
 * Thay vào đó GROUP BY chính tên nhãn rồi sắp xếp theo tên nhãn.
 *
 * @return array<int, string>
 */
function wa_library_tags(int $libraryId): array
{
    $stmt = db()->prepare(
        "SELECT t.name
           FROM `tags` t
           JOIN `page_tags` pt ON pt.tag_id = t.id
           JOIN `saved_pages` sp ON sp.id = pt.page_id
          WHERE sp.library_id = ?
          GROUP BY t.name
          ORDER BY t.name"
    );
    $stmt->execute([$libraryId]);

    return wa_display_tag_names(array_map(static fn (array $r): string => (string) $r['name'], $stmt->fetchAll()));
}

/**
 * Tìm một thư viện theo slug mà người dùng được phép xem.
 *
 * Kết quả gồm luôn các số liệu tổng hợp mà trang library.php hiển thị
 * (article_count, image_count, asset_size, member_count, tag_count, tags, ...).
 *
 * @return array<string, mixed>|null
 */
function wa_find_library(string $slug, ?array $actor = null): ?array
{
    $actor = $actor ?? wa_actor();
    [$accessSql, $params] = wa_library_access_sql($actor);

    $sql = "
        SELECT
            l.*,
            u.full_name AS owner_name,
            lm.role     AS member_role,
            (SELECT COUNT(*) FROM `saved_pages` sp
              WHERE sp.library_id = l.id AND sp.status <> 'deleted')                             AS article_count,
            (SELECT COUNT(*) FROM `assets` a
                JOIN `snapshots` s  ON s.id = a.snapshot_id
                JOIN `saved_pages` sp2 ON sp2.id = s.page_id
              WHERE sp2.library_id = l.id AND a.mime_type LIKE 'image/%')                     AS image_count,
            (SELECT COALESCE(SUM(a.size_bytes), 0) FROM `assets` a
                JOIN `snapshots` s  ON s.id = a.snapshot_id
                JOIN `saved_pages` sp2 ON sp2.id = s.page_id
              WHERE sp2.library_id = l.id)                                                    AS storage_bytes,
            (SELECT COUNT(*) FROM `library_members` lm2 WHERE lm2.library_id = l.id)           AS member_count,
            (SELECT COUNT(DISTINCT pt.tag_id) FROM `page_tags` pt
                JOIN `saved_pages` sp3 ON sp3.id = pt.page_id
              WHERE sp3.library_id = l.id)                                                   AS tag_count
          FROM `libraries` l
          JOIN `users` u ON u.id = l.owner_id
          LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :actor_user
         WHERE l.slug = :slug AND {$accessSql}
         LIMIT 1
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge(
        [':actor_user' => $actor['id'] ?? 0, ':slug' => $slug],
        $params
    ));

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    $row['id']             = (int) $row['id'];
    $row['owner_id']       = (int) $row['owner_id'];
    $row['article_count']  = (int) $row['article_count'];
    $row['image_count']    = (int) $row['image_count'];
    $row['storage_bytes']  = (int) $row['storage_bytes'];
    $row['member_count']   = (int) $row['member_count'];
    $row['tag_count']      = (int) $row['tag_count'];

    if ($row['owner_id'] === ($actor['id'] ?? -1)) {
        $row['role'] = 'owner';
    } else {
        $row['role'] = (string) ($row['member_role'] ?? 'viewer');
    }

    $row['role_label'] = wa_member_role_labels()[$row['role']] ?? 'Người xem';
    $row['visibility_label'] = wa_visibility_labels()[(string) $row['visibility']] ?? $row['visibility'];

    // Các nhãn phục vụ giao diện.
    $row['asset_size'] = wa_format_mb($row['storage_bytes']);
    $row['tags']       = wa_library_tags($row['id']);
    $row['integrity']  = '100% nguyên vẹn';

    return $row;
}

/**
 * Thành viên của một thư viện.
 *
 * Mỗi phần tử có cả 'name' (tên hiển thị) và 'full_name' (tên gốc trong CSDL)
 * để khớp với giao diện, vốn hiển thị $member['name'].
 *
 * @return array<int, array<string, mixed>>
 */
function wa_library_members(int $libraryId): array
{
    $stmt = db()->prepare(
        "SELECT lm.role, u.id, u.full_name, u.email
           FROM `library_members` lm
           JOIN `users` u ON u.id = lm.user_id
          WHERE lm.library_id = ?
          ORDER BY FIELD(lm.role, 'owner', 'editor', 'viewer'), u.id"
    );
    $stmt->execute([$libraryId]);

    $roleLabels = wa_member_role_labels();
    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']    = (int) $row['id'];
        $row['name']  = (string) $row['full_name'];
        $row['role_label'] = $roleLabels[(string) $row['role']] ?? 'Người xem';
        $row['initials']   = wa_initials((string) $row['full_name']);
        $row['variant']    = wa_avatar_variant((string) $row['role']);
        $rows[] = $row;
    }

    return $rows;
}

/* =============================================================================
    QUẢN LÝ THƯ VIỆN — P-LIB
    -----------------------------------------------------------------------------
    Toàn bộ hành động ghi của giai đoạn này nằm ở đây. Quy tắc bất di bất dịch:

      1. Mọi câu lệnh dùng prepared statement; KHÔNG lặp lại một tên tham số
         trong cùng câu (PDO đang bật prepares thật -> SQLSTATE[HY093]).
      2. Người gọi đã kiểm tra quyền (chủ sở hữu / quản trị viên); các hàm ở
         đây vẫn ép điều kiện sở hữu NGAY TRONG SQL để POST thẳng bằng tay cũng
         không vượt được.
      3. Xoá thư viện xoá cả dòng dữ liệu (qua khoá ngoại CASCADE) lẫn thư mục
         ảnh trên đĩa public/uploads/snapshots/<id>/.
    ============================================================================= */

/** @return array<int, array{key:string, label:string, color:string, icon:string}> */
function wa_library_visibility_options(): array
{
    return [
        ['key' => 'private', 'label' => 'Chỉ mình tôi',  'color' => 'a', 'icon' => 'lock'],
        ['key' => 'shared',  'label' => 'Nội bộ nhóm', 'color' => 'b', 'icon' => 'group'],
        ['key' => 'public',  'label' => 'Công khai',   'color' => 'c', 'icon' => 'public'],
    ];
}

/**
 * Hex #rrggbb có phải màu SÁNG không (để chọn màu chữ đặt lên trên nền đó).
 * Công thức độ sáng tương đối đơn giản, đủ cho việc chọn chữ trắng/đen.
 */
function wa_hex_is_light(string $hex): bool
{
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));

    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150;
}

/** @return array<int, array{key:string, label:string, color:string}> */
function wa_library_color_options(): array
{
    // a/b/c giữ nguyên để thư viện cũ không đổi màu. Bộ chọn màu ở hộp
    // thoại tạo thư viện chỉ gợi ý a/d/e/f + ô tự chọn; b/c vẫn hợp lệ
    // khi dữ liệu cũ gửi lên.
    return [
        ['key' => 'a', 'label' => 'Xanh dương', 'color' => 'a'],
        ['key' => 'b', 'label' => 'Xanh ngọc',  'color' => 'b'],
        ['key' => 'c', 'label' => 'Xanh bạc hà', 'color' => 'c'],
        ['key' => 'd', 'label' => 'Xanh lá',    'color' => 'd'],
        ['key' => 'e', 'label' => 'Vàng',       'color' => 'e'],
        ['key' => 'f', 'label' => 'Đỏ',         'color' => 'f'],
    ];
}

/** @return array<int, array{key:string, label:string}> */
function wa_library_icon_options(): array
{
    return [
        ['key' => 'folder_special', 'label' => 'Thư mục đặc biệt'],
        ['key' => 'terminal',       'label' => 'Terminal'],
        ['key' => 'school',         'label' => 'Trường học'],
        ['key' => 'hub',            'label' => 'Trung tâm'],
        ['key' => 'science',        'label' => 'Khoa học'],
        ['key' => 'library_books',  'label' => 'Giáo sách'],
        ['key' => 'public',         'label' => 'Công khai'],
        ['key' => 'menu_book',      'label' => 'Sách mở'],
        ['key' => 'article',        'label' => 'Bài viết'],
        ['key' => 'newspaper',      'label' => 'Báo chí'],
        ['key' => 'code',           'label' => 'Lập trình'],
        ['key' => 'history',        'label' => 'Lịch sử'],
        ['key' => 'lightbulb',      'label' => 'Ý tưởng'],
        ['key' => 'star',           'label' => 'Yêu thích'],
        ['key' => 'palette',        'label' => 'Mỹ thuật'],
        ['key' => 'music_note',     'label' => 'Âm nhạc'],
        ['key' => 'movie',          'label' => 'Điện ảnh'],
        ['key' => 'flight',         'label' => 'Du lịch'],
        ['key' => 'book',           'label' => 'Sách'],
        ['key' => 'bookmark',       'label' => 'Đánh dấu'],
        ['key' => 'favorite',       'label' => 'Tim'],
        ['key' => 'home',           'label' => 'Trang chủ'],
        ['key' => 'work',           'label' => 'Công việc'],
        ['key' => 'biotech',        'label' => 'Công nghệ sinh học'],
        ['key' => 'psychology',     'label' => 'Tâm lý'],
        ['key' => 'language',       'label' => 'Ngôn ngữ'],
        ['key' => 'sports_esports', 'label' => 'Trò chơi'],
        ['key' => 'restaurant',     'label' => 'Ẩm thực'],
        ['key' => 'auto_stories',   'label' => 'Truyện'],
        ['key' => 'description',    'label' => 'Tài liệu'],
        ['key' => 'calendar_month', 'label' => 'Lịch'],
        ['key' => 'build',          'label' => 'Công cụ'],
        ['key' => 'eco',            'label' => 'Môi trường'],
        ['key' => 'sports_soccer',  'label' => 'Bóng đá'],
        ['key' => 'camera_alt',     'label' => 'Nhiếp ảnh'],
        ['key' => 'brush',          'label' => 'Hội họa'],
        ['key' => 'computer',       'label' => 'Máy tính'],
        ['key' => 'smartphone',     'label' => 'Di động'],
        ['key' => 'health_and_safety', 'label' => 'Sức khỏe'],
        ['key' => 'business_center', 'label' => 'Kinh doanh'],
        ['key' => 'gavel',          'label' => 'Pháp luật'],
        ['key' => 'landscape',      'label' => 'Phong cảnh'],
        ['key' => 'rocket_launch',  'label' => 'Vũ trụ'],
        ['key' => 'anchor',         'label' => 'Hàng hải'],
        ['key' => 'pets',           'label' => 'Thú cưng'],
        ['key' => 'cake',           'label' => 'Bánh ngọt'],
        ['key' => 'fitness_center', 'label' => 'Thể hình'],
        ['key' => 'theater_comedy', 'label' => 'Sân khấu'],
    ];
}

/**
 * Người dùng có quyền QUẢN LÝ (đổi tên / đổi hiển thị / xoá) thư viện này không?
 *
 * Chỉ CHỦ SỞ HỮU (libraries.owner_id) hoặc quản trị viên. Biên tập viên và
 * người xem KHÔNG được — điều này ép ở tầng SQL, không chỉ ẩn nút trên giao diện.
 */
function wa_can_manage_library(int $libraryId, ?array $actor = null): bool
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return false;
    }

    // Hai vị trí so sánh -> hai tên tham số khác nhau (prepares thật của PDO).
    $stmt = db()->prepare(
        'SELECT 1 FROM `libraries` WHERE `id` = :lib_id AND `owner_id` = :owner_id LIMIT 1'
    );
    $stmt->execute([':lib_id' => $libraryId, ':owner_id' => (int) $actor['id']]);

    if ($stmt->fetchColumn() !== false) {
        return true;
    }

    return $actor['role'] === 'admin';
}

/**
 * Tạo một thư viện mới (libraries.php — nút "Tạo thư viện").
 *
 * Ba bước đúng thứ tự đặc tả:
 *   1. INSERT `libraries` với owner = người đang đăng nhập, slug sinh từ tên
 *      (bỏ dấu bằng bảng tra dùng chung, có hậu tố -2, -3 ... nếu trùng).
 *   2. INSERT dòng chủ sở hữu vào `library_members` với role 'owner'.
 *   3. Trả về slug + id để nơi gọi chuyển hướng tới trang chi tiết.
 *
 * @return array{ok:bool, message:string, library_id?:int, slug?:string}
 */
function wa_library_create(
    string $name,
    string $description,
    string $visibility,
    string $color,
    string $icon,
    int $ownerId
): array {
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

    if ($name === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập tên thư viện lưu trữ.'];
    }

    if (mb_strlen($name) > 150) {
        return ['ok' => false, 'message' => 'Tên thư viện không được vượt quá 150 ký tự.'];
    }

    $visibility = in_array($visibility, ['private', 'shared', 'public'], true) ? $visibility : 'private';
    $colors     = array_column(wa_library_color_options(), 'key');
    $icons      = array_column(wa_library_icon_options(), 'key');
    // Màu hợp lệ: 1 trong các key gợi ý, HOẶC hex #rrggbb từ ô tự chọn.
    $color      = (in_array($color, $colors, true) || preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1)
        ? $color : 'a';
    $icon       = in_array($icon, $icons, true) ? $icon : 'folder_special';

    $description = trim($description);

    $slugInfo = wa_library_unique_slug($name);

    // Bọc trong transaction: thư viện và dòng chủ sở hữu phải cùng thành
    // công — không bao giờ có thư viện mà không có chủ sở hữu trong library_members.
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO `libraries` (`owner_id`, `name`, `slug`, `description`, `visibility`, `color`, `icon`)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $ownerId,
            $name,
            $slugInfo['slug'],
            $description !== '' ? $description : null,
            $visibility,
            $color,
            $icon,
        ]);

        $libraryId = (int) $pdo->lastInsertId();

        // DÒNG CHỦ SỞ HỮU — vai trò 'owner' (đặc tả yêu cầu rõ).
        $owner = $pdo->prepare(
            "INSERT INTO `library_members` (`library_id`, `user_id`, `role`) VALUES (?, ?, 'owner')"
        );
        $owner->execute([$libraryId, $ownerId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();

        // Trùng slug do hai lần tạo chạy cùng lúc -> thử lại một lần với
        // hậu tố kế tiếp (wa_library_unique_slug tự dò slug còn trống).
        if (str_contains($e->getMessage(), 'uq_libraries_slug')) {
            return wa_library_create($name, $description, $visibility, $color, $icon, $ownerId);
        }

        error_log('[WebArchive] tao thu vien that bai: ' . $e->getMessage());

        return [
            'ok'      => false,
            'message' => 'Không tạo được thư viện. Vui lòng thử lại.',
        ];
    }

    return [
        'ok'          => true,
        'message'     => 'Đã tạo thư viện “' . $name . '”. Bạn là chủ sở hữu của thư viện này.',
        'library_id'  => $libraryId,
        'slug'        => $slugInfo['slug'],
    ];
}

/**
 * Đổi tên + đổi mức hiển thị của một thư viện (chủ sở hữu / quản trị viên).
 *
 * Quy tắc slug: nếu TÊN KHÔNG ĐỔI thì GIỮ NGUYÊN slug cũ (để các liên kết đã
 * chia sẻ không hỏng); chỉ sinh slug mới — kèm hậu tố -2, -3 ... — khi tên thực
 * sự thay đổi.
 *
 * @return array{ok:bool, message:string, slug?:string}
 */
function wa_library_update(
    int $libraryId,
    string $name,
    string $description,
    string $visibility,
    ?array $actor = null
): array {
    $actor = $actor ?? wa_actor();

    if ($actor === null || !wa_can_manage_library($libraryId, $actor)) {
        return ['ok' => false, 'message' => 'Bạn không có quyền thay đổi thư viện này.'];
    }

    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

    if ($name === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập tên thư viện lưu trữ.'];
    }

    if (mb_strlen($name) > 150) {
        return ['ok' => false, 'message' => 'Tên thư viện không được vượt quá 150 ký tự.'];
    }

    $visibility = in_array($visibility, ['private', 'shared', 'public'], true)
        ? $visibility
        : 'private';

    $current = wa_library_by_id($libraryId);

    if ($current === null) {
        return ['ok' => false, 'message' => 'Không tìm thấy thư viện cần sửa.'];
    }

    // Tên không đổi -> giữ slug cũ.
    $nameUnchanged = wa_search_fold((string) $current['name']) === wa_search_fold($name);
    $slug           = (string) $current['slug'];

    if (!$nameUnchanged) {
        $slug = wa_library_unique_slug($name, $libraryId)['slug'];
    }

    $description = trim($description);

    try {
        $stmt = db()->prepare(
            'UPDATE `libraries`
                SET `name` = ?, `slug` = ?, `description` = ?, `visibility` = ?
              WHERE `id` = ?'
        );
        $stmt->execute([
            $name,
            $slug,
            $description !== '' ? $description : null,
            $visibility,
            $libraryId,
        ]);
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'uq_libraries_slug')) {
            return ['ok' => false, 'message' => 'Tên thư viện đã tồn tại với đường dẫn tương ứng. Vui lòng chọn tên khác.'];
        }

        error_log('[WebArchive] doi ten thu vien that bai: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Không lưu được thay đổi. Vui lòng thử lại.'];
    }

    $visLabel = wa_visibility_labels()[$visibility] ?? $visibility;

    return [
        'ok'      => true,
        'message' => 'Đã cập nhật thư viện “' . $name . '” — mức hiển thị: ' . $visLabel . '.',
        'slug'    => $slug,
    ];
}

/**
 * Nạp một thư viện theo id mà KHÔNG lọc quyền (dùng nội bộ trong chính các hàm
 * quản lý đã kiểm tra quyền trước đó).
 *
 * @return array<string,mixed>|null
 */
function wa_library_by_id(int $libraryId): ?array
{
    $stmt = db()->prepare('SELECT * FROM `libraries` WHERE `id` = ? LIMIT 1');
    $stmt->execute([$libraryId]);

    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Xoá một thư viện: dòng CSDL (qua khoá ngoại) + thư mục ảnh trên đĩa.
 *
 * CHỈ CHỦ SỞ HỮU được xoá. Quyền được ép HAI LẦP:
 *   - ở tầng gọi (library_action.php kiểm tra wa_can_manage_library), và
 *   - tại đây bằng điều kiện `owner_id = ?` NẰM TRONG CÂU DELETE.
 *
 * Điều kiện owner_id trong chính câu DELETE là điểm mấu chốt: kể cả khi ai đó
 * sửa được mã nguồn hoặc gửi POST bằng tay, câu lệnh vẫn chỉ xoá đúng thư viện
 * mà họ sở hữu — không bao giờ xoá nhầm thư viện của người khác.
 *
 * Bảng `libraries` đã có khoá ngoại ON DELETE CASCADE, nên xoá một dòng thư viện
 * sẽ tự xoá: library_members, saved_pages -> page_tags, snapshots -> search_terms,
 * assets, notes. Dòng dữ liệu đã xoá trước khi ghi tên thư mục ảnh (cần id
 * snapshot để biết đường dẫn trên đĩa).
 *
 * @return array{ok:bool, message:string, pages:int, snapshots:int, dirs:int, files:int}
 */
function wa_library_delete(int $libraryId, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return [
            'ok' => false, 'message' => 'Bạn chưa đăng nhập.',
            'pages' => 0, 'snapshots' => 0, 'dirs' => 0, 'files' => 0,
        ];
    }

    // Quyền xoá là của CHỦ SỞ HỮU — quản trị viên KHÔNG xoá được từ đây (đặc tả:
    // "Delete library: owner only"), nên kiểm tra bằng owner_id thật.
    $stmt = db()->prepare('SELECT `name`, `slug`, `owner_id` FROM `libraries` WHERE `id` = ? LIMIT 1');
    $stmt->execute([$libraryId]);
    $library = $stmt->fetch();

    if ($library === false) {
        return [
            'ok' => false, 'message' => 'Không tìm thấy thư viện cần xoá.',
            'pages' => 0, 'snapshots' => 0, 'dirs' => 0, 'files' => 0,
        ];
    }

    if ((int) $library['owner_id'] !== (int) $actor['id']) {
        return [
            'ok' => false, 'message' => 'Chỉ chủ sở hữu thư viện mới có thể xoá thư viện này.',
            'pages' => 0, 'snapshots' => 0, 'dirs' => 0, 'files' => 0,
        ];
    }

    // Đếm trước để báo cáo + biết danh sách thư mục ảnh phải dọn.
    $countStmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM `saved_pages` WHERE `library_id` = :lib_count) AS pages,
                (SELECT COUNT(*) FROM `snapshots` s
                   JOIN `saved_pages` sp ON sp.id = s.page_id
                  WHERE sp.library_id = :lib_snap_count) AS snapshots'
    );
    // Hai vị trí so sánh -> hai tên tham số KHÁC NHAU (prepares thật của PDO
    // không cho phép lặp lại một tên tham số trong cùng câu -> SQLSTATE[HY093]).
    $countStmt->execute([
        ':lib_count'       => $libraryId,
        ':lib_snap_count'  => $libraryId,
    ]);
    $counts      = $countStmt->fetch() ?: ['pages' => 0, 'snapshots' => 0];
    $pageCount   = (int) ($counts['pages'] ?? 0);
    $snapCount   = (int) ($counts['snapshots'] ?? 0);

    $idsStmt = db()->prepare(
        'SELECT s.id
           FROM `snapshots` s
           JOIN `saved_pages` sp ON sp.id = s.page_id
          WHERE sp.library_id = ?'
    );
    $idsStmt->execute([$libraryId]);
    $snapshotIds = array_map('intval', array_column($idsStmt->fetchAll(), 'id'));

    // --- Xoá dòng CSDL (CASCADE lo phần còn lại) ---
    $delete = db()->prepare('DELETE FROM `libraries` WHERE `id` = ? AND `owner_id` = ?');
    $delete->execute([$libraryId, (int) $actor['id']]);

    if ($delete->rowCount() === 0) {
        return [
            'ok' => false, 'message' => 'Không thể xoá thư viện này.',
            'pages' => $pageCount, 'snapshots' => $snapCount, 'dirs' => 0, 'files' => 0,
        ];
    }

    // --- Dọn thư mục ảnh trên đĩa: public/uploads/snapshots/<snapshot_id>/ ---
    $removedDirs  = 0;
    $removedFiles = 0;

    foreach ($snapshotIds as $snapshotId) {
        $dir = WA_PUBLIC . '/uploads/snapshots/' . $snapshotId;

        if (!is_dir($dir)) {
            continue;
        }

        $entries = @scandir($dir);

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $dir . '/' . $entry;

                if (is_file($path)) {
                    if (@unlink($path)) {
                        $removedFiles++;
                    }
                }
            }
        }

        if (@rmdir($dir)) {
            $removedDirs++;
        }
    }

    return [
        'ok'        => true,
        'message'   => 'Đã xoá thư viện “' . (string) $library['name'] . '” cùng '
                     . $pageCount . ' bài viết và ' . $snapCount . ' bản snapshot.',
        'pages'     => $pageCount,
        'snapshots' => $snapCount,
        'dirs'      => $removedDirs,
        'files'     => $removedFiles,
    ];
}

/* =============================================================================
    QUẢN LÝ THÀNH VIÊN — P-LIB
    -----------------------------------------------------------------------------
    Ba hành động: thêm theo email, đổi vai trò, gỡ thành viên.

    NGUYÊN TẮC BẢO VỆ DÒNG CHỦ SỞ HỮU được ép TRONG SQL: mệnh đề WHERE của cả
    ba lệnh UPDATE/DELETE đều có `role <> 'owner'`, nên kể cả khi gửi POST bằng
    tay cũng không hạ hay gỡ được dòng vai trò 'owner'.
    ============================================================================= */

/**
 * Thêm một thành viên vào thư viện theo email.
 *
 * @param string $role Chỉ nhận 'editor' hoặc 'viewer' — không bao giờ nhận 'owner'.
 * @return array{ok:bool, message:string, user_id?:int}
 */
function wa_member_add(int $libraryId, string $email, string $role, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if ($actor === null || !wa_can_manage_library($libraryId, $actor)) {
        return ['ok' => false, 'message' => 'Bạn không có quyền thêm thành viên cho thư viện này.'];
    }

    $email = trim($email);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Email không đúng định dạng. Ví dụ: ban@example.com'];
    }

    // Vai trò khi thêm: chỉ editor hoặc viewer — KHÔNG BAO GIỜ owner.
    if (!in_array($role, ['editor', 'viewer'], true)) {
        $role = 'viewer';
    }

    // Email phải thuộc một tài khoản đã tồn tại.
    $user = auth_find_user_by_email($email);

    if ($user === null) {
        return ['ok' => false, 'message' => 'Không tìm thấy tài khoản với email này.'];
    }

    $userId = (int) $user['id'];

    // Không thêm trùng (đã là thành viên rồi).
    $exists = db()->prepare('SELECT 1 FROM `library_members` WHERE `library_id` = ? AND `user_id` = ? LIMIT 1');
    $exists->execute([$libraryId, $userId]);

    if ($exists->fetchColumn() !== false) {
        return ['ok' => false, 'message' => 'Tài khoản này đã là thành viên của thư viện.'];
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO `library_members` (`library_id`, `user_id`, `role`) VALUES (?, ?, ?)'
        );
        $stmt->execute([$libraryId, $userId, $role]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'message' => 'Tài khoản này đã là thành viên của thư viện.'];
        }

        throw $e;
    }

    $roleLabel = wa_member_role_labels()[$role] ?? $role;

    return [
        'ok'      => true,
        'message' => 'Đã thêm ' . (string) $user['name'] . ' (' . $email . ') với vai trò ' . $roleLabel . '.',
        'user_id' => $userId,
    ];
}

/**
 * Đổi vai trò của một thành viên giữa 'editor' và 'viewer'.
 *
 * `role <> 'owner'` TRONG CÂU UPDATE: dòng chủ sở hữu không bao giờ bị hạ xuống
 * vai trò thấp hơn, dù có gửi POST bằng tay.
 *
 * @return array{ok:bool, message:string}
 */
function wa_member_update_role(int $libraryId, int $userId, string $role, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if ($actor === null || !wa_can_manage_library($libraryId, $actor)) {
        return ['ok' => false, 'message' => 'Bạn không có quyền thay đổi vai trò thành viên trong thư viện này.'];
    }

    if (!in_array($role, ['editor', 'viewer'], true)) {
        return ['ok' => false, 'message' => 'Vai trò không hợp lệ. Chỉ nhận biên tập viên hoặc người xem.'];
    }

    $stmt = db()->prepare(
        "UPDATE `library_members`
            SET `role` = ?
          WHERE `library_id` = ?
            AND `user_id` = ?
            AND `role` <> 'owner'"
    );
    $stmt->execute([$role, $libraryId, $userId]);

    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Không thay đổi được vai trò (thành viên không tồn tại hoặc là chủ sở hữu).'];
    }

    $roleLabel = wa_member_role_labels()[$role] ?? $role;

    return ['ok' => true, 'message' => 'Đã đổi vai trò thành viên sang ' . $roleLabel . '.'];
}

/**
 * Gỡ một thành viên khỏi thư viện.
 *
 * `role <> 'owner'` TRONG CÂU DELETE: dòng chủ sở hữu không bao giờ bị gỡ —
 * kể cả khi chủ sở hữu tự bấm nút xoá chính mình.
 *
 * @return array{ok:bool, message:string}
 */
function wa_member_remove(int $libraryId, int $userId, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if ($actor === null || !wa_can_manage_library($libraryId, $actor)) {
        return ['ok' => false, 'message' => 'Bạn không có quyền gỡ thành viên khỏi thư viện này.'];
    }

    $stmt = db()->prepare(
        "DELETE FROM `library_members`
          WHERE `library_id` = ?
            AND `user_id` = ?
            AND `role` <> 'owner'"
    );
    $stmt->execute([$libraryId, $userId]);

    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Không gỡ được thành viên này (không tồn tại hoặc là chủ sở hữu).'];
    }

    return ['ok' => true, 'message' => 'Đã gỡ thành viên khỏi thư viện.'];
}

/* =============================================================================
    SỬA NHÃN CỦA MỘT TRANG ĐÃ LƯU — P-LIB
    ============================================================================= */

/**
 * Chuẩn hoá tên nhãn: mọi nhãn đều mang tiền tố '#'.
 *
 * Người dùng chỉ cần gõ chữ (vd 'php'), dấu '#' tự thêm khi lưu — gõ sẵn '#'
 * cũng được, không bị thêm hai lần. Hàm này:
 *   - gộp khoảng trắng, bỏ ký tự phân tách ( , ; ) ở hai đầu,
 *   - bỏ qua tên rỗng hoặc chỉ có một dấu '#',
 *   - thêm '#' vào đầu nếu chưa có, rồi cắt 64 ký tự (khớp VARCHAR(64)
 *     của bảng tags, tính cả dấu '#').
 *
 * Quy ước (đổi từ 27/10, thay quy ước cũ coi '#php' và 'php' là hai nhãn
 * khác nhau): mọi nhãn lưu và hiển thị đều bắt đầu bằng '#', nên không còn
 * tình trạng cái có '#' cái không. Nhãn cũ lưu chưa có '#' vẫn hiển thị
 * kèm '#' nhờ wa_display_tag_names(), và sẽ thành '#...' thật trong CSDL
 * ở lần lưu nhãn tiếp theo của trang đó.
 *
 * @return array<string,string> khoá đã gộp => tên nhãn đã chuẩn hoá (loại trùng)
 */
function wa_normalize_tag_names(array $names): array
{
    $result = [];

    foreach ($names as $name) {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
        $name = trim($name, ",; \t\n\r\0\x0B");

        if ($name === '') {
            continue;
        }

        // Dấu '#' tự thêm: gõ 'php' thành '#php'; gõ sẵn '#' thì giữ nguyên.
        // Tên chỉ có một dấu '#' thì không phải nhãn thật nên bỏ qua.
        if ($name === '#') {
            continue;
        }

        if (!str_starts_with($name, '#')) {
            $name = '#' . $name;
        }

        // Cắt còn 64 ký tự (khớp VARCHAR(64) của bảng `tags`, tính cả '#').
        if (mb_strlen($name) > 64) {
            $name = rtrim(mb_substr($name, 0, 64, 'UTF-8'));
        }

        if ($name === '') {
            continue;
        }

        // Khoá so sánh KHÔNG phân biệt hoa/thường và dấu (utf8mb4_unicode_ci
        // của MySQL cũng bỏ dấu khi so khớp) — tránh tạo "#PHP" và "#php" cùng lúc.
        $key = wa_search_fold($name);

        if (!isset($result[$key])) {
            $result[$key] = $name;
        }
    }

    return $result;
}

/**
 * Tên nhãn để HIỂN THỊ: bảo đảm mọi nhãn đều có tiền tố '#'.
 *
 * Các hàm đọc (wa_page_tags, wa_library_tags, wa_all_tags) dùng hàm này để
 * nhãn cũ lưu chưa có '#' vẫn hiển thị đồng bộ '#...' với nhãn mới, không
 * cần chạy migration trên CSDL. Loại trùng theo tên đã bỏ dấu '#' và gộp
 * dấu/hoa-thường, để một trang vừa có 'php' vừa có '#php' không hiện
 * '#php' hai lần.
 *
 * @param string[] $names
 * @return string[]
 */
function wa_display_tag_names(array $names): array
{
    $result = [];

    foreach ($names as $name) {
        $name = trim((string) $name);

        if ($name === '' || $name === '#') {
            continue;
        }

        if (!str_starts_with($name, '#')) {
            $name = '#' . $name;
        }

        $key = wa_search_fold(ltrim($name, '#'));

        if (!isset($result[$key])) {
            $result[$key] = $name;
        }
    }

    return array_values($result);
}

/**
 * THAY THẾ TOÀN BỘ bộ nhãn của một trang đã lưu.
 *
 *   - Tên mới  -> tạo dòng `tags` nếu chưa có, rồi INSERT `page_tags`.
 *   - Tên bị bỏ -> DELETE dòng `page_tags` tương ứng.
 *   - Còn giữ   -> giữ nguyên (đã có sẵn trong DB, không đụng tới).
 *
 * Không bao giờ xoá dòng khỏi bảng `tags`: thẻ có thể còn dùng cho trang khác,
 * và bảng `page_tags` đã có khoá ngoại ON DELETE CASCADE nên xoá `tags` sẽ âm
 * thầm xoá nhãn của các trang khác.
 *
 * @param string[] $tagNames Danh sách nhãn MỚI (thay thế hoàn toàn bộ nhãn cũ).
 * @return array{ok:bool, message:string, added:int, removed:int}
 */
function wa_page_tags_replace(int $pageId, array $tagNames, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return ['ok' => false, 'message' => 'Bạn chưa đăng nhập.', 'added' => 0, 'removed' => 0];
    }

    if (!wa_page_can_edit_tags($pageId, $actor)) {
        return [
            'ok' => false,
            'message' => 'Bạn không có quyền sửa nhãn của trang này.',
            'added' => 0,
            'removed' => 0,
        ];
    }

    // 1) Chuẩn hoá tên nhãn mới + loại trùng.
    $wanted = wa_normalize_tag_names($tagNames);

    // 2) Tên nhãn HIỆN TẠI của trang (kèm id để xoá link).
    $currentStmt = db()->prepare(
        'SELECT t.id, t.name
           FROM `tags` t
           JOIN `page_tags` pt ON pt.tag_id = t.id
          WHERE pt.page_id = ?
          ORDER BY t.id'
    );
    $currentStmt->execute([$pageId]);
    $current = $currentStmt->fetchAll();

    $currentIds = [];
    foreach ($current as $row) {
        $currentIds[wa_search_fold((string) $row['name'])] = (int) $row['id'];
    }

    $findTag   = db()->prepare('SELECT id FROM `tags` WHERE name = ? LIMIT 1');
    $insertTag = db()->prepare('INSERT IGNORE INTO `tags` (`name`) VALUES (?)');
    $getTagId  = db()->prepare('SELECT id FROM `tags` WHERE name = ? LIMIT 1');
    $attachTag = db()->prepare(
        'INSERT IGNORE INTO `page_tags` (`page_id`, `tag_id`) VALUES (?, ?)'
    );
    $detachTag = db()->prepare('DELETE FROM `page_tags` WHERE `page_id` = ? AND `tag_id` = ?');

    $added   = 0;
    $removed = 0;

    $pdo = db();
    $pdo->beginTransaction();

    try {
        // 3) Gắn các nhãn mới.
        foreach ($wanted as $name) {
            if (isset($currentIds[wa_search_fold($name)])) {
                continue;   // Trang đang có nhãn này rồi -> giữ nguyên.
            }

            $findTag->execute([$name]);

            if ($findTag->fetchColumn() === false) {
                $insertTag->execute([$name]);
            }

            $getTagId->execute([$name]);
            $tagId = $getTagId->fetchColumn();

            if ($tagId !== false) {
                $attachTag->execute([$pageId, (int) $tagId]);
                $added++;
            }
        }

        // 4) Gỡ các nhãn không còn trong danh sách mới.
        $wantedKeys = array_flip(array_keys($wanted));

        foreach ($currentIds as $key => $tagId) {
            if (!isset($wantedKeys[$key])) {
                $detachTag->execute([$pageId, $tagId]);
                $removed++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();

        error_log('[WebArchive] sua nhan that bai: ' . $e->getMessage());

        return [
            'ok' => false,
            'message' => 'Không lưu được nhãn. Vui lòng thử lại.',
            'added' => 0,
            'removed' => 0,
        ];
    }

    return [
        'ok'      => true,
        'message' => $wanted === []
            ? 'Đã xoá hết nhãn của trang này.'
            : 'Đã cập nhật nhãn: thêm ' . $added . ', gỡ ' . $removed . '.',
        'added'   => $added,
        'removed' => $removed,
    ];
}

/**
 * Người dùng có quyền sửa nhãn của một trang đã lưu không?
 *
 * Chủ sở hữu / biên tập viên của thư viện chứa trang, hoặc quản trị viên.
 * Người xem chỉ được XEM nhãn.
 */
/**
 * XÁC ĐỊNH NGƯỜI DÙNG CÓ QUYỀN SỬA / XOÁ BÀI VIẾT KHÔNG (owner / editor / admin).
 *
 * Chung cho: sửa nhãn, XOÁ MỀM, khôi phục. Viewer và người ngoài KHÔNG được.
 * Điều kiện cũng được ép lại trong các hàm ghi, nên ẩn nút chỉ là lớp trình bày.
 */
function wa_page_can_edit_tags(int $pageId, ?array $actor = null): bool
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return false;
    }

    if ($actor['role'] === 'admin') {
        return true;
    }

    $stmt = db()->prepare(
        "SELECT 1
           FROM `saved_pages` sp
           JOIN `libraries` l ON l.id = sp.library_id
           LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :actor
          WHERE sp.id = :page_id
            AND sp.status <> 'deleted'
            AND (l.owner_id = :owner_id OR lm.role IN ('owner', 'editor'))
          LIMIT 1"
    );
    $stmt->execute([
        ':actor'    => (int) $actor['id'],
        ':page_id'  => $pageId,
        ':owner_id' => (int) $actor['id'],
    ]);

    return $stmt->fetchColumn() !== false;
}

/**
 * XOÁ MỀM một bài viết đã lưu (soft delete — KHÔNG xoá dòng, KHÔNG xoá snapshot).
 *
 * Cơ chế: đặt `saved_pages.status = 'deleted'`. Toàn bộ snapshots, assets và
 * notes của bài được giữ nguyên, nên KHÔI PHỤC sau này vẫn thấy đủ v1/v2/v3
 * và các ghi chú. Bài đã xoá:
 *   - không hiện trong danh sách thư viện (wa_library_articles lọc status);
 *   - không mở được trong trình đọc (wa_find_snapshot / wa_reader_context lọc);
 *   - không xuất hiện trong tìm kiếm (wa_search_snapshots lọc JOIN);
 *   - số lượng bài viết tự cập nhật vì mọi đếm đều qua cùng bộ lọc.
 *
 * Phân quyền (ép trong SQL, không chỉ ẩn nút):
 *   - owner / editor / admin được xoá; viewer và người ngoài bị từ chối.
 *   - gửi POST trùng (bài đã xoá) trả thông báo thay vì lỗi SQL.
 *
 * @return array{ok:bool, message:string}
 */
function wa_page_soft_delete(int $pageId, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return ['ok' => false, 'message' => 'Bạn chưa đăng nhập.'];
    }

    if ($pageId <= 0) {
        return ['ok' => false, 'message' => 'Không xác định được bài viết cần xoá.'];
    }

    // Bài đã xoá rồi -> báo idempotent, không ghi gì thêm.
    $check = db()->prepare('SELECT id, title, status FROM `saved_pages` WHERE id = ? LIMIT 1');
    $check->execute([$pageId]);

    $row = $check->fetch();

    if ($row === false) {
        return ['ok' => false, 'message' => 'Không tìm thấy bài viết cần xoá.'];
    }

    if ((string) $row['status'] === 'deleted') {
        return ['ok' => true, 'message' => 'Bài viết này đã được xoá trước đó.'];
    }

    // Quyền: owner / editor của thư viện, hoặc admin.
    if (!wa_page_can_edit_tags($pageId, $actor)) {
        return ['ok' => false, 'message' => 'Bạn không có quyền xoá bài viết này.'];
    }

    $update = db()->prepare(
        "UPDATE `saved_pages` SET status = 'deleted', updated_at = CURRENT_TIMESTAMP
          WHERE id = ? AND status <> 'deleted'"
    );
    $update->execute([$pageId]);

    if ($update->rowCount() === 0) {
        // Race: bài vừa bị xoá bởi phiên khác giữa chừng.
        return ['ok' => true, 'message' => 'Bài viết này đã được xoá trước đó.'];
    }

    return [
        'ok'      => true,
        'message' => 'Đã xoá bài viết “' . (string) $row['title'] . '” khỏi thư viện. '
                   . 'Các phiên bản và ghi chú vẫn được giữ để khôi phục.',
    ];
}

/**
 * KHÔI PHỤC một bài viết đã xoá mềm.
 *
 * Đặt `status` về 'active' — mọi snapshot (v1/v2/v3), ảnh và ghi chú hiện lại
 * nguyên vẹn vì chúng chưa từng bị đụng tới. Cùng chính sách quyền như khi xoá.
 *
 * @return array{ok:bool, message:string}
 */
function wa_page_restore(int $pageId, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return ['ok' => false, 'message' => 'Bạn chưa đăng nhập.'];
    }

    if ($pageId <= 0) {
        return ['ok' => false, 'message' => 'Không xác định được bài viết cần khôi phục.'];
    }

    $check = db()->prepare('SELECT id, title, status, library_id FROM `saved_pages` WHERE id = ? LIMIT 1');
    $check->execute([$pageId]);

    $row = $check->fetch();

    if ($row === false) {
        return ['ok' => false, 'message' => 'Không tìm thấy bài viết cần khôi phục.'];
    }

    if ((string) $row['status'] !== 'deleted') {
        return ['ok' => true, 'message' => 'Bài viết này không ở trong thùng rác.'];
    }

    // Quyền: owner / editor của thư viện, hoặc admin. Dùng kiểm tra trực tiếp
    // vì wa_page_can_edit_tags() bỏ qua bài đã xoá.
    $allowed = false;

    if ($actor['role'] === 'admin') {
        $allowed = true;
    } else {
        $perm = db()->prepare(
            "SELECT 1
               FROM `saved_pages` sp
               JOIN `libraries` l ON l.id = sp.library_id
               LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :actor
              WHERE sp.id = :page_id
                AND (l.owner_id = :owner_id OR lm.role IN ('owner', 'editor'))
              LIMIT 1"
        );
        $perm->execute([
            ':actor'    => (int) $actor['id'],
            ':page_id'  => $pageId,
            ':owner_id' => (int) $actor['id'],
        ]);

        $allowed = $perm->fetchColumn() !== false;
    }

    if (!$allowed) {
        return ['ok' => false, 'message' => 'Bạn không có quyền khôi phục bài viết này.'];
    }

    $update = db()->prepare(
        "UPDATE `saved_pages` SET status = 'active', updated_at = CURRENT_TIMESTAMP
          WHERE id = ? AND status = 'deleted'"
    );
    $update->execute([$pageId]);

    if ($update->rowCount() === 0) {
        return ['ok' => true, 'message' => 'Bài viết này không ở trong thùng rác.'];
    }

    return [
        'ok'      => true,
        'message' => 'Đã khôi phục bài viết “' . (string) $row['title'] . '” '
                   . 'cùng toàn bộ phiên bản và ghi chú.',
    ];
}

/* =============================================================================
    BÀI VIẾT / SNAPSHOT
    ============================================================================= */

/**
 * Các bài viết (saved_pages) của một thư viện, kèm phiên bản snapshot mới nhất.
 *
 * QUY TẮC: danh sách bài viết chỉ gồm những trang CÓ ÍT NHẤT một snapshot
 * fetch_status = 'success'. Một lần lưu thất bại (blocked / 404 / timeout /
 * http / no_content) vẫn được ghi vào CSDL để thống kê quản trị đếm lỗi, nhưng
 * trang đó không bao giờ hiện ra trong danh sách bài viết hay mở được trong trình đọc.
 *
 * P-READ: mỗi thẻ bài viết mang thêm `version_count` (số bản THÀNH CÔNG) để
 * hiển thị huy hiệu "N phiên bản", và tiêu đề lấy từ bản thành công mới nhất.
 *
 * @return array<int, array<string, mixed>>
 */
function wa_library_articles(int $libraryId): array
{
    $stmt = db()->prepare(
        "SELECT
            sp.id, sp.source_url, sp.domain, sp.status, sp.created_at, sp.updated_at,
            s.id AS snapshot_id, s.version_no, s.title, s.fetched_at, s.http_status, s.fetch_status,
            s.content_text,
            (SELECT COUNT(*) FROM `snapshots` s3
              WHERE s3.page_id = sp.id AND s3.fetch_status = 'success')                  AS version_count,
            (SELECT COUNT(*) FROM `assets` a
               JOIN `snapshots` s2 ON s2.id = a.snapshot_id
              WHERE s2.page_id = sp.id AND a.mime_type LIKE 'image/%')                    AS image_count,
            (SELECT COALESCE(SUM(a.size_bytes), 0) FROM `assets` a
               JOIN `snapshots` s2 ON s2.id = a.snapshot_id
              WHERE s2.page_id = sp.id)                                                   AS size_bytes
          FROM `saved_pages` sp
          JOIN `snapshots` s ON s.id = (
                SELECT s2.id FROM `snapshots` s2
                 WHERE s2.page_id = sp.id
                   AND s2.fetch_status = 'success'
                 ORDER BY s2.version_no DESC
                 LIMIT 1
          )
         WHERE sp.library_id = ?
           AND sp.status <> 'deleted'
         ORDER BY sp.created_at DESC, sp.id DESC"
    );
    $stmt->execute([$libraryId]);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']          = (int) $row['id'];
        $row['snapshot_id'] = $row['snapshot_id'] !== null ? (int) $row['snapshot_id'] : null;
        $row['version_no']  = $row['version_no'] !== null ? (int) $row['version_no'] : 1;
        $row['version_count'] = (int) $row['version_count'];
        $row['image_count'] = (int) $row['image_count'];
        $row['size_bytes']  = (int) $row['size_bytes'];
        $row['size_label']  = wa_format_bytes($row['size_bytes']);
        // P-READ: thời điểm tải của bản thành công mới nhất (không phải ngày
        // tạo dòng saved_pages) — đúng ý nghĩa "lần cập nhật gần nhất".
        $row['saved_at']    = wa_datetime((string) $row['fetched_at']);
        $row['title']       = (string) $row['title'];
        $row['tags']        = wa_page_tags((int) $row['id']);
        $row['version']     = 'v' . $row['version_no'] . '.0';
        $row['version_label'] = $row['version_count'] . ' phiên bản';

        // Đoạn trích hiển thị trên thẻ bài viết, lấy từ nội dung snapshot đã lưu.
        $row['excerpt'] = wa_trim((string) ($row['content_text'] ?? ''), 180);

        // Nhãn + mô tả kỹ thuật cho ô xem trước (thumbnail).
        [$thumbLabel, $thumbCode] = wa_thumb_preview((string) $row['domain'], (int) $row['version_no']);
        $row['thumb_label'] = $thumbLabel;
        $row['thumb_code']  = $thumbCode;

        $rows[] = $row;
    }

    return $rows;
}

/**
 * Các bài viết ĐÃ XOÁ MỀM của một thư viện (thùng rác), để khôi phục.
 *
 * Chỉ trả tiêu đề + thời điểm xoá (updated_at) — không cần snapshot vì bài đã
 * xoá không mở được trong trình đọc. Toàn bộ snapshots/notes vẫn còn trong
 * CSDL nên khôi phục hiện lại nguyên vẹn.
 *
 * @return array<int, array{id:int, title:string, source_url:string, domain:string,
 *                          deleted_at:string, deleted_label:string, version_count:int}>
 */
function wa_library_trashed_articles(int $libraryId): array
{
    $stmt = db()->prepare(
        "SELECT sp.id, sp.title, sp.source_url, sp.domain, sp.updated_at,
                (SELECT COUNT(*) FROM `snapshots` s3
                  WHERE s3.page_id = sp.id AND s3.fetch_status = 'success') AS version_count
           FROM `saved_pages` sp
          WHERE sp.library_id = ?
            AND sp.status = 'deleted'
       ORDER BY sp.updated_at DESC, sp.id DESC"
    );
    $stmt->execute([$libraryId]);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $deletedAt = (string) $row['updated_at'];

        $rows[] = [
            'id'            => (int) $row['id'],
            'title'         => (string) $row['title'],
            'source_url'    => (string) $row['source_url'],
            'domain'        => (string) $row['domain'],
            'deleted_at'    => $deletedAt,
            'deleted_label' => wa_datetime($deletedAt),
            'version_count' => (int) $row['version_count'],
        ];
    }

    return $rows;
}

/**
 * Nhãn và đoạn mã kỹ thuật hiển thị trong ô xem trước của thẻ bài viết.
 *
 * @return array{0:string,1:string}
 */
function wa_thumb_preview(string $domain, int $versionNo): array
{
    $labels = [
        'phptutorial.vn'   => 'HTML DOM Snapshot',
        'techblog.dev'      => 'MySQL Query Engine',
        'tapchicongnghe.vn' => 'Security Sandbox',
        'dev-insights.org'  => 'Fulltext Index',
    ];

    $codes = [
        'phptutorial.vn'   => '<article class="markdown-table">' . "\n" . '  <table data-preserved="true">...</table>',
        'techblog.dev'      => 'INDEX (library_id, created_at)' . "\n" . 'EXPLAIN SELECT * FROM snapshots...',
        'tapchicongnghe.vn' => 'CRAWLER :: DOMPurify + Regex' . "\n" . 'assets/images/2026/10/img_921.webp',
        'dev-insights.org'  => 'FULLTEXT (title, body)' . "\n" . "MATCH(title, body) AGAINST('...')",
    ];

    return [
        $labels[$domain] ?? 'HTML Snapshot',
        $codes[$domain] ?? 'snapshot v' . $versionNo . ' · ' . $domain,
    ];
}

/**
 * Nhãn của một trang đã lưu.
 *
 * Tên trả về luôn kèm tiền tố '#' (xem wa_display_tag_names) nên nhãn cũ
 * lưu chưa có '#' vẫn hiển thị đồng bộ với nhãn mới.
 *
 * @return array<int, string>
 */
function wa_page_tags(int $pageId): array
{
    $stmt = db()->prepare(
        "SELECT t.name
           FROM `tags` t
           JOIN `page_tags` pt ON pt.tag_id = t.id
          WHERE pt.page_id = ?
          ORDER BY t.id"
    );
    $stmt->execute([$pageId]);

    return wa_display_tag_names(array_map(static fn (array $r): string => (string) $r['name'], $stmt->fetchAll()));
}

/**
 * Phạm vi của một snapshot: thư viện chứa nó + trạng thái bản lưu.
 *
 * Dùng khi cần phân biệt "snapshot không tồn tại" (404) với "snapshot tồn tại
 * nhưng bạn không có quyền" (403) — hàm này KHÔNG lọc theo quyền nên trả về
 * thông tin cho mọi snapshot đang có trong CSDL.
 *
 * @return array{library_id:int, fetch_status:string, version_no:int}|null
 */
function wa_snapshot_scope(int $snapshotId): ?array
{
    $stmt = db()->prepare(
        'SELECT s.fetch_status, s.version_no, sp.library_id
           FROM `snapshots` s
           JOIN `saved_pages` sp ON sp.id = s.page_id
          WHERE s.id = ?
          LIMIT 1'
    );
    $stmt->execute([$snapshotId]);

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    return [
        'library_id'   => (int) $row['library_id'],
        'fetch_status' => (string) $row['fetch_status'],
        'version_no'   => (int) $row['version_no'],
    ];
}

/**
 * Lấy một phiên bản snapshot theo id, kèm thông tin trang và thư viện.
 * Tôn trọng quy tắc truy cập của người dùng hiện tại.
 *
 * QUY TẮC: chỉ trả về snapshot có fetch_status = 'success'. Snapshot lỗi vẫn còn
 * trong CSDL để thống kê quản trị, nhưng không bao giờ mở được như bài viết hợp lệ.
 *
 * @return array<string, mixed>|null
 */
function wa_find_snapshot(int $snapshotId, ?array $actor = null): ?array
{    $actor = $actor ?? wa_actor();
    [$accessSql, $params] = wa_library_access_sql($actor);

    $sql = "
        SELECT
            s.id, s.page_id, s.version_no, s.title, s.content_html, s.content_text,
            s.source_url, s.fetched_at, s.http_status, s.fetch_status, s.error_message,
            sp.library_id, sp.domain, sp.status AS page_status,
            l.name AS library_name, l.slug AS library_slug,
            u.full_name AS creator_name,
            (SELECT COUNT(*) FROM `assets` a WHERE a.snapshot_id = s.id AND a.mime_type LIKE 'image/%') AS image_count,
            (SELECT COALESCE(SUM(a.size_bytes), 0) FROM `assets` a WHERE a.snapshot_id = s.id)         AS size_bytes
          FROM `snapshots` s
          JOIN `saved_pages` sp ON sp.id = s.page_id
          JOIN `libraries` l ON l.id = sp.library_id
          JOIN `users` u ON u.id = sp.created_by
         WHERE s.id = :snapshot_id AND s.fetch_status = 'success'
           AND sp.status <> 'deleted' AND {$accessSql}
         LIMIT 1
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([':snapshot_id' => $snapshotId], $params));

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    $row['id']          = (int) $row['id'];
    $row['page_id']     = (int) $row['page_id'];
    $row['library_id']  = (int) $row['library_id'];
    $row['version_no']  = (int) $row['version_no'];
    $row['image_count'] = (int) $row['image_count'];
    $row['size_bytes']  = (int) $row['size_bytes'];
    $row['size_label']  = wa_format_bytes($row['size_bytes']);
    $row['saved_at']    = wa_datetime((string) $row['fetched_at']);
    $row['tags']        = wa_page_tags((int) $row['page_id']);

    return $row;
}

/* =============================================================================
    TÌM KIẾM — P-SEARCH
    -----------------------------------------------------------------------------
    Hàm tìm kiếm KHÔNG còn nằm ở đây nữa. Nó đã chuyển sang src/search.php và
    chạy trên bảng chỉ mục đảo `search_terms` do dự án tự dựng:
        wa_search_tokenize() / wa_search_fold()   — tách từ + bỏ dấu
        wa_search_index_snapshot()                 — lập chỉ mục một snapshot
        wa_search_snapshots()                      — truy vấn ngữ nghĩa AND + xếp hạng
        wa_search_snippet()                        — đoạn trích có <mark>
    Các hàm dưới đây chỉ còn phần phục vụ giao diện: lấy danh sách tên miền và
    danh sách thẻ để đổ vào hai ô lọc của public/search.php.

    KHÔNG còn truy vấn nào trong dự án quét `snapshots.title` / `content_text`
    bằng LIKE để tìm kiếm — việc so khớp diễn ra hoàn toàn trên `term_folded`.
    ============================================================================= */

/**
 * Danh sách tên miền có trong kho (để lọc tìm kiếm).
 *
 * @return array<int, string>
 */
function wa_snapshot_domains(?array $actor = null): array
{
    $actor = $actor ?? wa_actor();
    [$accessSql, $params] = wa_library_access_sql($actor);

    // Chỉ lấy những domain CÓ BẢN ĐỌC ĐƯỢC (snapshot thành công).
    //
    // P-FIX-CN4: nếu lấy cả domain chỉ từng lỗi, bộ lọc "theo tên miền" sẽ
    // hiện những mục mà bấm vào luôn ra 0 kết quả — gây ấn tượng sai là
    // tìm kiếm hỏng. Chỉ domain có nội dung đã lưu mới đáng lọc.
    $sql = "SELECT DISTINCT sp.domain
              FROM `saved_pages` sp
              JOIN `libraries` l ON l.id = sp.library_id
             WHERE {$accessSql}
               AND EXISTS (SELECT 1 FROM `snapshots` s
                            WHERE s.page_id = sp.id AND s.fetch_status = 'success')
             ORDER BY sp.domain";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map(static fn (array $r): string => (string) $r['domain'], $stmt->fetchAll());
}

/**
 * Toàn bộ thẻ đang có trong hệ thống.
 *
 * @return array<int, string>
 */
function wa_all_tags(): array
{
    $stmt = db()->query('SELECT name FROM `tags` ORDER BY id');

    return wa_display_tag_names(array_map(static fn (array $r): string => (string) $r['name'], $stmt->fetchAll()));
}

/* =============================================================================
    NGƯỜI DÙNG & QUẢN TRỊ
    ============================================================================= */

/**
 * Danh sách tài khoản kèm số thư viện (quản trị).
 *
 * @return array<int, array<string, mixed>>
 */
function wa_users(): array
{
    $stmt = db()->query(
        "SELECT u.id, u.full_name, u.email, u.role, u.status, u.created_at,
                (SELECT COUNT(*) FROM `libraries` l WHERE l.owner_id = u.id) AS library_count
           FROM `users` u
          ORDER BY u.id"
    );

    $roleLabels  = wa_user_role_labels();
    $statusLabels = wa_user_status_labels();
    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']      = (int) $row['id'];
        $row['name']    = (string) $row['full_name'];
        $row['libraries'] = (int) $row['library_count'];
        $row['role_label']  = $roleLabels[(string) $row['role']] ?? 'Người dùng';
        $row['status_label'] = $statusLabels[(string) $row['status']] ?? 'Hoạt động';
        $row['joined']  = wa_date((string) $row['created_at']);
        $row['initials'] = wa_initials((string) $row['full_name']);
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Toàn bộ thư viện trên hệ thống (quản trị), kèm chủ sở hữu.
 *
 * @return array<int, array<string, mixed>>
 */
function wa_all_libraries(): array
{
    $stmt = db()->query(
        "SELECT l.id, l.name, l.slug, l.visibility, l.color, l.icon, l.description,
                u.full_name AS owner_name,
                (SELECT COUNT(*) FROM `saved_pages` sp WHERE sp.library_id = l.id) AS article_count,
                (SELECT COUNT(*) FROM `assets` a
                   JOIN `snapshots` s ON s.id = a.snapshot_id
                   JOIN `saved_pages` sp2 ON sp2.id = s.page_id
                  WHERE sp2.library_id = l.id AND a.mime_type LIKE 'image/%') AS image_count,
                (SELECT COALESCE(SUM(a.size_bytes), 0) FROM `assets` a
                   JOIN `snapshots` s ON s.id = a.snapshot_id
                   JOIN `saved_pages` sp2 ON sp2.id = s.page_id
                  WHERE sp2.library_id = l.id) AS storage_bytes,
                (SELECT COUNT(*) FROM `library_members` lm WHERE lm.library_id = l.id) AS member_count
           FROM `libraries` l
           JOIN `users` u ON u.id = l.owner_id
          ORDER BY l.id"
    );

    $visLabels = wa_visibility_labels();
    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']            = (int) $row['id'];
        $row['article_count'] = (int) $row['article_count'];
        $row['image_count']   = (int) $row['image_count'];
        $row['storage_bytes'] = (int) $row['storage_bytes'];
        $row['member_count']  = (int) $row['member_count'];
        $row['visibility_label'] = $visLabels[(string) $row['visibility']] ?? $row['visibility'];
        $row['visibility_code']  = (string) $row['visibility'];
        $row['strip']  = (string) $row['color'];
        $row['color_hex'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $row['color']) === 1
            ? (string) $row['color'] : '';
        $row['color_ink'] = $row['color_hex'] !== '' && wa_hex_is_light($row['color_hex'])
            ? '#1F2937' : '#FFFFFF';
        $row['storage_label'] = wa_format_mb($row['storage_bytes']);
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Danh sách tên miền được duyệt (quản trị).
 *
 * @return array<int, array<string, mixed>>
 */
function wa_allowlist(): array
{
    $stmt = db()->query(
        "SELECT a.id, a.domain, a.note, a.created_at, u.full_name AS added_by
           FROM `allowlist_domains` a
           JOIN `users` u ON u.id = a.added_by
          ORDER BY a.id"
    );

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']      = (int) $row['id'];
        $row['date']    = wa_date((string) $row['created_at']);
        $row['status']  = 'active';
        $row['status_label'] = 'Đang hoạt động';
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Thêm một tên miền vào allowlist.
 *
 * Chấp nhận cả tên miền nhiều nhãn ("tenmien.vn") và tên máy chủ một nhãn
 * ("localhost") — máy chủ kiểm thử cục bộ của dự án dùng dạng một nhãn.
 *
 * @return array{ok:bool, message:string}
 */
function wa_allowlist_add(string $domain, string $note, int $addedBy): array
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    $note   = trim($note);

    // Một nhãn: localhost. Nhiều nhãn: tenmien.vn, a.b.example.org.
    $valid = (bool) preg_match(
        '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i',
        $domain
    );

    if (!$valid) {
        return [
            'ok'      => false,
            'message' => 'Tên miền không hợp lệ. Vui lòng nhập một tên miền dạng "tenmien.vn" hoặc "localhost".',
        ];
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO `allowlist_domains` (`domain`, `note`, `added_by`) VALUES (?, ?, ?)'
        );
        $stmt->execute([$domain, $note !== '' ? $note : null, $addedBy]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return [
                'ok'      => false,
                'message' => 'Tên miền "' . $domain . '" đã có trong danh sách được duyệt.',
            ];
        }

        throw $e;
    }

    return [
        'ok'      => true,
        'message' => 'Đã thêm tên miền "' . $domain . '" vào danh sách được duyệt.',
    ];
}

/* =============================================================================
    QUẢN LÝ TÀI KHOẢN — P-ADMIN (P-FIX-ADMIN)
    -----------------------------------------------------------------------------
    Trước đây trang admin/users.php CHỈ CÓ GIAO DIỆN: các nút "Sửa", "Khoá",
    "Thao tác khác" và hộp thoại "Mời người dùng" đều không làm gì (chính trang
    đó cũng ghi rõ: "các thao tác trên bảng chỉ hiển thị, chưa thực hiện thay
    đổi trên cơ sở dữ liệu"). Nay mỗi nút đều có hành động thật.
    ============================================================================= */

/**
 * Đổi vai trò của một tài khoản (user <-> admin).
 *
 * @return array{ok:bool, message:string}
 */
function wa_admin_set_user_role(int $userId, string $role, int $actorId): array
{
    if (!in_array($role, ['user', 'admin'], true)) {
        return ['ok' => false, 'message' => 'Vai trò không hợp lệ.'];
    }

    // Không cho tự hạ quyền của chính mình — tránh hệ thống mất quản trị.
    if ($userId === $actorId && $role !== 'admin') {
        return [
            'ok'      => false,
            'message' => 'Bạn không thể tự hạ quyền của tài khoản đang đăng nhập.',
        ];
    }

    $stmt = db()->prepare('SELECT full_name, role FROM `users` WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);

    $row = $stmt->fetch();

    if ($row === false) {
        return ['ok' => false, 'message' => 'Không tìm thấy tài khoản cần thay đổi.'];
    }

    if ((string) $row['role'] === $role) {
        return [
            'ok'      => false,
            'message' => 'Tài khoản “' . (string) $row['full_name'] . '” đang có vai trò này rồi.',
        ];
    }

    $update = db()->prepare('UPDATE `users` SET role = ? WHERE id = ?');
    $update->execute([$role, $userId]);

    wa_admin_log(
        $actorId,
        'user.role',
        'user',
        (string) $userId,
        $role === 'admin'
            ? 'Nâng quyền lên Quản trị viên'
            : 'Hạ quyền về Người dùng'
    );

    return [
        'ok'      => true,
        'message' => 'Đã đổi vai trò của “' . (string) $row['full_name'] . '” thành '
                   . ($role === 'admin' ? 'Quản trị viên.' : 'Người dùng.'),
    ];
}

/**
 * Khoá / mở khoá một tài khoản.
 *
 * @return array{ok:bool, message:string}
 */
function wa_admin_set_user_status(int $userId, string $status, int $actorId): array
{
    if (!in_array($status, ['active', 'locked'], true)) {
        return ['ok' => false, 'message' => 'Trạng thái không hợp lệ.'];
    }

    // Không cho tự khoá chính mình.
    if ($userId === $actorId && $status === 'locked') {
        return [
            'ok'      => false,
            'message' => 'Bạn không thể tự khoá tài khoản đang đăng nhập.',
        ];
    }

    $stmt = db()->prepare('SELECT full_name, status FROM `users` WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);

    $row = $stmt->fetch();

    if ($row === false) {
        return ['ok' => false, 'message' => 'Không tìm thấy tài khoản cần thay đổi.'];
    }

    if ((string) $row['status'] === $status) {
        return [
            'ok'      => false,
            'message' => 'Tài khoản “' . (string) $row['full_name'] . '” đang ở trạng thái này rồi.',
        ];
    }

    $update = db()->prepare('UPDATE `users` SET status = ? WHERE id = ?');
    $update->execute([$status, $userId]);

    wa_admin_log(
        $actorId,
        'user.status',
        'user',
        (string) $userId,
        $status === 'locked' ? 'Khoá tài khoản' : 'Mở khoá tài khoản'
    );

    return [
        'ok'      => true,
        'message' => $status === 'locked'
            ? 'Đã khoá tài khoản “' . (string) $row['full_name'] . '”.'
            : 'Đã mở khoá tài khoản “' . (string) $row['full_name'] . '”.',
    ];
}

/**
 * Tạo một tài khoản mới (quản trị viên thêm từ giao diện).
 *
 * @return array{ok:bool, message:string, user_id?:int}
 */
function wa_admin_create_user(
    string $fullName,
    string $email,
    string $password,
    string $role,
    int $actorId
): array {
    $fullName = trim($fullName);
    $email    = strtolower(trim($email));
    $password = (string) $password;

    if ($fullName === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập họ và tên.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Email không đúng định dạng. Ví dụ: ban@example.com'];
    }

    if (strlen($password) < 8) {
        return ['ok' => false, 'message' => 'Mật khẩu phải có ít nhất 8 ký tự.'];
    }

    if (!in_array($role, ['user', 'admin'], true)) {
        $role = 'user';
    }

    $exists = db()->prepare('SELECT COUNT(*) FROM `users` WHERE email = ?');
    $exists->execute([$email]);

    if ((int) $exists->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'Email “' . $email . '” đã được dùng cho một tài khoản khác.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($hash === false) {
        return ['ok' => false, 'message' => 'Không tạo được mật khẩu cho tài khoản này.'];
    }

    $insert = db()->prepare(
        'INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `status`)
         VALUES (?, ?, ?, ?, \'active\')'
    );
    $insert->execute([$fullName, $email, $hash, $role]);

    $newId = (int) db()->lastInsertId();

    wa_admin_log($actorId, 'user.create', 'user', (string) $newId, 'Tạo tài khoản ' . $email);

    return [
        'ok'      => true,
        'message' => 'Đã tạo tài khoản “' . $fullName . '” với email ' . $email . '.',
        'user_id' => $newId,
    ];
}

/**
 * Xoá một tài khoản (quản trị viên).
 *
 * P-FIX-ADMIN: hàm này CHƯA tồn tại trong khi nhãn nhật ký 'user.delete' đã có
 * sẵn — nghĩa là nút xoá tài khoản trên trang quản trị chưa có hành động thật.
 *
 * BẢO VỆ (ép trong SQL, không chỉ ở giao diện):
 *   - Không cho xoá chính mình (tránh mất quản trị đang đăng nhập).
 *   - Không cho xoá tài khoản Quản trị viên cuối cùng của hệ thống.
 *   - `saved_pages.created_by` có ON DELETE CASCADE -> xoá tài khoản sẽ xoá luôn
 *     các bài viết của tài khoản đó (kèm snapshot, ảnh, ghi chú theo CASCADE).
 *     Vì vậy hàm trả về số bài bị xoá để thông báo minh bạch cho người dùng.
 *
 * @return array{ok:bool, message:string, pages_deleted?:int}
 */
function wa_admin_delete_user(int $userId, int $actorId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'message' => 'Không xác định được tài khoản cần xoá.'];
    }

    // Không cho tự xoá chính mình.
    if ($userId === $actorId) {
        return [
            'ok'      => false,
            'message' => 'Bạn không thể xoá tài khoản đang đăng nhập.',
        ];
    }

    $stmt = db()->prepare('SELECT id, full_name, email, role FROM `users` WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);

    $row = $stmt->fetch();

    if ($row === false) {
        return ['ok' => false, 'message' => 'Không tìm thấy tài khoản cần xoá.'];
    }

    // Không cho xoá tài khoản quản trị cuối cùng.
    if ((string) $row['role'] === 'admin') {
        $adminCount = (int) db()->query("SELECT COUNT(*) FROM `users` WHERE role = 'admin'")->fetchColumn();

        if ($adminCount <= 1) {
            return [
                'ok'      => false,
                'message' => 'Không thể xoá tài khoản Quản trị viên cuối cùng của hệ thống.',
            ];
        }
    }

    // Đếm bài viết sẽ bị xoá theo (CASCADE) để báo minh bạch.
    $countStmt = db()->prepare('SELECT COUNT(*) FROM `saved_pages` WHERE created_by = ?');
    $countStmt->execute([$userId]);
    $pageCount = (int) $countStmt->fetchColumn();

    $delete = db()->prepare('DELETE FROM `users` WHERE id = ?');
    $delete->execute([$userId]);

    if ($delete->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Không xoá được tài khoản này.'];
    }

    wa_admin_log(
        $actorId,
        'user.delete',
        'user',
        (string) $userId,
        'Xoá tài khoản ' . (string) $row['email'] . ' và ' . $pageCount . ' bài viết của tài khoản đó'
    );

    return [
        'ok'            => true,
        'message'       => 'Đã xoá tài khoản “' . (string) $row['full_name'] . '”'
                         . ($pageCount > 0
                             ? ' cùng ' . $pageCount . ' bài viết của tài khoản đó.'
                             : '.'),
        'pages_deleted' => $pageCount,
    ];
}

/* =============================================================================
    DUYỆT THEO TÊN MIỀN — P-ADMIN (P-FIX-ADMIN)
    -----------------------------------------------------------------------------
    Yêu cầu của đề tài: "Một domain được duyệt một lần. Các URL hợp lệ khác trên
    cùng domain có thể được lưu mà không cần duyệt lại."

    Cơ chế: bảng `domain_requests` là hàng đợi yêu cầu duyệt. Mỗi tên miền
    mới mà người dùng muốn lưu sẽ được đưa vào đây (gộp các yêu cầu trùng
    domain thành MỘT dòng). Admin duyệt -> domain được thêm vào
    `allowlist_domains` -> mọi URL trên domain đó lưu được ngay.
    ============================================================================= */

/**
 * Tên miền đã được duyệt hay chưa (dùng để kiểm tra nhanh, không cần DB).
 */
function wa_allowlist_is_approved(string $domain): bool
{
    $domain = strtolower(rtrim(trim($domain), '.'));

    if ($domain === '') {
        return false;
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM `allowlist_domains` WHERE domain = ?');
    $stmt->execute([$domain]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Gửi yêu cầu duyệt một tên miền (hoặc gộp vào yêu cầu đang chờ).
 *
 * P-FIX-ADMIN: các yêu cầu TRÙNG DOMAIN được GỘP lại — nếu domain đã có một
 * dòng 'pending' thì ta chỉ tăng số người yêu cầu và cập nhật ghi chú, không
 * tạo dòng mới. Điều này giúp Admin không bị ngập bởi hàng loạt yêu cầu giống
 * nhau.
 *
 * @return array{ok:bool, message:string, request_id?:int, status?:string}
 */
function wa_request_domain_approval(string $domain, string $note, int $requestedBy): array
{
    $domain = strtolower(rtrim(trim($domain), '.'));

    if ($domain === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập tên miền cần duyệt.'];
    }

    if (!wa_is_valid_domain($domain)) {
        return [
            'ok'      => false,
            'message' => 'Tên miền không hợp lệ. Ví dụ hợp lệ: "tenmien.vn" hoặc "blog.tdm.vn".',
        ];
    }

    // Đã được duyệt rồi -> không cần xin nữa.
    if (wa_allowlist_is_approved($domain)) {
        return [
            'ok'      => false,
            'message' => 'Tên miền "' . $domain . '" đã được duyệt — bạn có thể lưu các URL trên domain này ngay.',
        ];
    }

    // Đã có yêu cầu đang chờ -> GỘP.
    $existing = db()->prepare(
        "SELECT id, status FROM `domain_requests`
          WHERE domain = ? AND status = 'pending'
          LIMIT 1"
    );
    $existing->execute([$domain]);

    $row = $existing->fetch();

    if ($row !== false) {
        $update = db()->prepare(
            "UPDATE `domain_requests`
                SET note = COALESCE(NULLIF(?, ''), note),
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ?"
        );
        $update->execute([trim($note), (int) $row['id']]);

        return [
            'ok'         => true,
            'message'    => 'Yêu cầu duyệt tên miền "' . $domain . '" đã có trong hàng đợi — '
                         . 'đã gộp vào yêu cầu đang chờ.',
            'request_id' => (int) $row['id'],
            'status'     => 'pending',
        ];
    }

    // Yêu cầu cũ đã bị từ chối HOẶC đã bị vô hiệu hoá -> mở lại thành 'pending'
    // thay vì tạo dòng mới.
    //
    // P-FIX-ADMIN: bảng `domain_requests` có UNIQUE(domain), nên nếu ta cố
    // INSERT một domain đã tồn tại (đã từ chối / đã vô hiệu hoá) thì sẽ nhận
    // lỗi SQL 23000 và hỏng cả lần lưu trang. Vì vậy ta MỞ LẠI dòng cũ.
    $reopenable = db()->prepare(
        "SELECT id FROM `domain_requests`
          WHERE domain = ? AND status IN ('rejected', 'disabled')
          LIMIT 1"
    );
    $reopenable->execute([$domain]);

    if ($reopenable->fetch() !== false) {
        $reopen = db()->prepare(
            "UPDATE `domain_requests`
                SET status = 'pending', note = ?, requested_by = ?,
                    reviewed_by = NULL, reviewed_at = NULL, updated_at = CURRENT_TIMESTAMP
              WHERE domain = ? AND status IN ('rejected', 'disabled')"
        );
        $reopen->execute([trim($note) !== '' ? trim($note) : null, $requestedBy, $domain]);

        return [
            'ok'      => true,
            'message' => 'Yêu cầu duyệt tên miền "' . $domain . '" đã được mở lại.',
        ];
    }

    try {
        $insert = db()->prepare(
            "INSERT INTO `domain_requests` (`domain`, `status`, `note`, `requested_by`)
             VALUES (?, 'pending', ?, ?)"
        );
        $insert->execute([$domain, trim($note) !== '' ? trim($note) : null, $requestedBy]);
    } catch (PDOException $e) {
        // UNIQUE(domain) bị chạm (race condition giữa hai phiên) -> coi như đã có.
        return [
            'ok'         => true,
            'message'    => 'Yêu cầu duyệt tên miền "' . $domain . '" đã có trong hàng đợi.',
            'status'     => 'pending',
        ];
    }

    return [
        'ok'         => true,
        'message'    => 'Đã gửi yêu cầu duyệt tên miền "' . $domain . '". '
                     . 'Quản trị viên sẽ xét duyệt; mọi URL trên domain này sẽ lưu được '
                     . 'ngay khi được duyệt.',
        'request_id' => (int) db()->lastInsertId(),
        'status'     => 'pending',
    ];
}

/**
 * Tên miền có đúng định dạng không? (dùng chung cho cả duyệt và kiểm tra)
 */
function wa_is_valid_domain(string $domain): bool
{
    $domain = strtolower(rtrim(trim($domain), '.'));

    if ($domain === '' || strlen($domain) > 253) {
        return false;
    }

    // Một nhãn: "localhost" (máy chủ kiểm thử cục bộ của dự án).
    // Nhiều nhãn: "tenmien.vn", "a.b.example.org".
    return (bool) preg_match(
        '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i',
        $domain
    );
}

/**
 * Danh sách yêu cầu duyệt tên miền, mới nhất trước.
 *
 * @param string $status Lọc theo trạng thái ('pending', 'approved', ...); rỗng = tất cả.
 * @return array<int, array<string,mixed>>
 */
function wa_domain_requests(string $status = 'pending'): array
{
    $params = [];
    $where  = '';

    if ($status !== '' && $status !== 'all') {
        $where = 'WHERE r.status = :status';
        $params[':status'] = $status;
    }

    $stmt = db()->prepare(
        "SELECT r.id, r.domain, r.status, r.note, r.created_at, r.updated_at,
                r.reviewed_at,
                u.full_name AS requested_by_name,
                rv.full_name AS reviewed_by_name
           FROM `domain_requests` r
           JOIN `users` u ON u.id = r.requested_by
           LEFT JOIN `users` rv ON rv.id = r.reviewed_by
           {$where}
       ORDER BY FIELD(r.status, 'pending', 'approved', 'rejected', 'disabled'),
                r.created_at DESC, r.id DESC"
    );
    $stmt->execute($params);

    $statusLabels = [
        'pending'  => 'Đang chờ',
        'approved' => 'Đã duyệt',
        'rejected' => 'Đã từ chối',
        'disabled' => 'Đã vô hiệu hoá',
    ];

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']           = (int) $row['id'];
        $row['status']       = (string) $row['status'];
        $row['status_label'] = $statusLabels[$row['status']] ?? $row['status'];
        $row['created_label'] = wa_datetime((string) $row['created_at']);
        $row['requested_by_name'] = (string) ($row['requested_by_name'] ?? '—');
        $row['reviewed_by_name']  = (string) ($row['reviewed_by_name'] ?? '');
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Số yêu cầu duyệt đang chờ.
 */
function wa_domain_request_pending_count(): int
{
    return (int) (db()->query(
        "SELECT COUNT(*) FROM `domain_requests` WHERE status = 'pending'"
    )->fetchColumn() ?: 0);
}

/**
 * Admin duyệt / từ chối / vô hiệu hoá một yêu cầu tên miền.
 *
 * P-FIX-ADMIN:
 *   - Duyệt -> thêm domain vào `allowlist_domains` (nếu chưa có) và đánh dấu
 *     yêu cầu là 'approved'.
 *   - Từ chối -> đánh dấu 'rejected', KHÔNG thêm vào allowlist.
 *   - Vô hiệu hoá -> đánh dấu 'disabled' VÀ gỡ domain khỏi allowlist để chặn
 *     việc lưu trang từ domain đó trở đi.
 *
 * @param string $action 'approve' | 'reject' | 'disable'
 * @return array{ok:bool, message:string}
 */
function wa_domain_request_review(int $requestId, string $action, int $reviewerId, string $note = ''): array
{
    if (!in_array($action, ['approve', 'reject', 'disable'], true)) {
        return ['ok' => false, 'message' => 'Hành động không hợp lệ.'];
    }

    $stmt = db()->prepare(
        'SELECT id, domain, status FROM `domain_requests` WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$requestId]);

    $row = $stmt->fetch();

    if ($row === false) {
        return ['ok' => false, 'message' => 'Không tìm thấy yêu cầu duyệt cần xử lý.'];
    }

    $domain = (string) $row['domain'];

    $update = db()->prepare(
        'UPDATE `domain_requests`
            SET status = :status, reviewed_by = :reviewer, reviewed_at = CURRENT_TIMESTAMP,
                note = COALESCE(NULLIF(:note, \'\'), note)
          WHERE id = :id'
    );
    $update->execute([
        ':status'   => $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'disabled'),
        ':reviewer' => $reviewerId,
        ':note'     => trim($note),
        ':id'       => $requestId,
    ]);

    if ($action === 'approve') {
        // Thêm vào allowlist nếu chưa có.
        $exists = db()->prepare('SELECT COUNT(*) FROM `allowlist_domains` WHERE domain = ?');
        $exists->execute([$domain]);

        if ((int) $exists->fetchColumn() === 0) {
            $insert = db()->prepare(
                'INSERT INTO `allowlist_domains` (`domain`, `note`, `added_by`) VALUES (?, ?, ?)'
            );
            $insert->execute([
                $domain,
                trim($note) !== '' ? trim($note) : 'Được duyệt qua yêu cầu tên miền',
                $reviewerId,
            ]);
        }

        return [
            'ok'      => true,
            'message' => 'Đã duyệt tên miền "' . $domain . '". Mọi URL hợp lệ trên domain này '
                       . 'giờ có thể lưu mà không cần duyệt lại.',
        ];
    }

    if ($action === 'reject') {
        return [
            'ok'      => true,
            'message' => 'Đã từ chối yêu cầu duyệt tên miền "' . $domain . '".',
        ];
    }

    // Vô hiệu hoá -> gỡ domain khỏi allowlist để chặn lưu trang từ domain đó.
    $remove = db()->prepare('DELETE FROM `allowlist_domains` WHERE domain = ?');
    $remove->execute([$domain]);

    return [
        'ok'      => true,
        'message' => 'Đã vô hiệu hoá tên miền "' . $domain . '" và gỡ khỏi danh sách được duyệt.',
    ];
}

/**
 * Gỡ một tên miền khỏi danh sách được duyệt (Admin bấm "Gỡ").
 *
 * @return array{ok:bool, message:string}
 */
function wa_allowlist_remove(string $domain, int $actorId): array
{
    $domain = strtolower(rtrim(trim($domain), '.'));

    $stmt = db()->prepare('DELETE FROM `allowlist_domains` WHERE domain = ?');
    $stmt->execute([$domain]);

    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Không tìm thấy tên miền cần gỡ.'];
    }

    // Cập nhật yêu cầu tương ứng thành 'disabled' để lịch sử nhất quán.
    $sync = db()->prepare(
        "UPDATE `domain_requests`
            SET status = 'disabled', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
          WHERE domain = ? AND status <> 'disabled'"
    );
    $sync->execute([$actorId, $domain]);

    return [
        'ok'      => true,
        'message' => 'Đã gỡ tên miền "' . $domain . '" khỏi danh sách được duyệt. '
                   . 'Các URL trên domain này sẽ không lưu được nữa.',
    ];
}

/* =============================================================================
    NHẬT KÝ THAO TÁC QUẢN TRỊ — P-ADMIN
    ============================================================================= */

/**
 * Ghi một dòng nhật ký thao tác quản trị.
 *
 * @param string $action      Mã hành động, ví dụ 'domain.approve'.
 * @param string $targetType  Loại đối tượng, ví dụ 'domain'.
 * @param string $targetId    Định danh đối tượng.
 */
function wa_admin_log(int $actorId, string $action, string $targetType = '', string $targetId = '', string $detail = ''): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO `admin_logs` (`actor_id`, `action`, `target_type`, `target_id`, `detail`)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $actorId > 0 ? $actorId : null,
            $action,
            $targetType !== '' ? $targetType : null,
            $targetId !== '' ? $targetId : null,
            trim($detail) !== '' ? mb_substr(trim($detail), 0, 500, 'UTF-8') : null,
        ]);
    } catch (Throwable $e) {
        // Nhật ký KHÔNG được làm hỏng hành động chính — nuốt lỗi.
    }
}

/**
 * Nhật ký thao tác quản trị, mới nhất trước.
 *
 * @return array<int, array<string,mixed>>
 */
function wa_admin_logs(int $limit = 30): array
{
    $limit = max(1, min(200, $limit));

    $stmt = db()->prepare(
        "SELECT l.id, l.action, l.target_type, l.target_id, l.detail, l.created_at,
                u.full_name AS actor_name, u.role AS actor_role
           FROM `admin_logs` l
           LEFT JOIN `users` u ON u.id = l.actor_id
       ORDER BY l.created_at DESC, l.id DESC
          LIMIT {$limit}"
    );
    $stmt->execute();

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']          = (int) $row['id'];
        $row['created_label'] = wa_datetime((string) $row['created_at']);
        $row['actor_name']  = (string) ($row['actor_name'] ?? 'Hệ thống');
        $row['action_label'] = wa_admin_log_action_label((string) $row['action']);
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Nhãn tiếng Việt của mã hành động trong nhật ký quản trị.
 */
function wa_admin_log_action_label(string $action): string
{
    return match ($action) {
        'domain.approve'  => 'Duyệt tên miền',
        'domain.reject'   => 'Từ chối tên miền',
        'domain.disable'  => 'Vô hiệu hoá tên miền',
        'domain.add'      => 'Thêm tên miền',
        'domain.remove'   => 'Gỡ tên miền',
        'domain.request'  => 'Gửi yêu cầu duyệt',
        'user.role'       => 'Đổi vai trò người dùng',
        'user.status'     => 'Đổi trạng thái người dùng',
        'user.delete'     => 'Xoá người dùng',
        default           => $action,
    };
}

/**
 * Tổng quan hệ thống cho trang quản trị.
 *
 * @return array<string, mixed>
 */
function wa_admin_stats(): array
{
    $pdo = db();

    $count = static function (string $sql) use ($pdo): int {
        return (int) $pdo->query($sql)->fetchColumn();
    };

    $storageBytes = (int) $pdo->query('SELECT COALESCE(SUM(size_bytes), 0) FROM `assets`')->fetchColumn();

    return [
        'user_count'       => $count('SELECT COUNT(*) FROM `users`'),
        'admin_count'      => $count("SELECT COUNT(*) FROM `users` WHERE role = 'admin'"),
        'locked_count'     => $count("SELECT COUNT(*) FROM `users` WHERE status = 'locked'"),
        'library_count'    => $count('SELECT COUNT(*) FROM `libraries`'),
        'shared_count'     => $count("SELECT COUNT(*) FROM `libraries` WHERE visibility <> 'private'"),
        'personal_count'   => $count("SELECT COUNT(*) FROM `libraries` WHERE visibility = 'private'"),
        'snapshot_count'   => $count('SELECT COUNT(*) FROM `snapshots`'),
        'error_count'      => $count("SELECT COUNT(*) FROM `snapshots` WHERE fetch_status <> 'success'"),
        'asset_count'      => $count("SELECT COUNT(*) FROM `assets` WHERE mime_type LIKE 'image/%'"),
        'image_count'      => $count("SELECT COUNT(*) FROM `assets` WHERE mime_type LIKE 'image/%'"),
        'page_count'       => $count('SELECT COUNT(*) FROM `saved_pages`'),
        'storage_bytes'    => $storageBytes,
        'storage_label'    => $storageBytes >= 1024 * 1024 * 1024
            ? number_format($storageBytes / (1024 * 1024 * 1024), 2, ',', '.') . ' GB'
            : wa_format_mb($storageBytes),
        'allowlist_count'  => $count('SELECT COUNT(*) FROM `allowlist_domains`'),

        /* --- P-FIX-ADMIN: các số liệu mà dashboard cần --- */
        'pending_requests' => $count("SELECT COUNT(*) FROM `domain_requests` WHERE status = 'pending'"),
        'rejected_requests'=> $count("SELECT COUNT(*) FROM `domain_requests` WHERE status = 'rejected'"),
        'approved_requests'=> $count("SELECT COUNT(*) FROM `domain_requests` WHERE status = 'approved'"),

        // Lỗi lưu URL: các lần lưu thất bại (bản ghi lỗi trong snapshots).
        'save_error_count' => $count("SELECT COUNT(*) FROM `snapshots` WHERE fetch_status <> 'success'"),

        // Lỗi cập nhật CN5: lỗi xảy ra ở các bản ghi thuộc phiên bản > 1,
        // tức là những lần "Lấy phiên bản mới" không thành công.
        //
        // P-FIX-CN5: bản ghi lỗi dùng version_no = 0 (số DÀNH RIÊNG cho lần
        // thử thất bại, không phải một phiên bản thật). Vì vậy ta đếm lỗi CN5
        // bằng cách xem các trang ĐÃ CÓ ít nhất một bản thành công (tức là
        // trang đã từng lưu được rồi) — đó chính là các trang mà lần "Lấy
        // phiên bản mới" có thể thất bại.
        'refetch_error_count' => $count(
            "SELECT COUNT(*)
               FROM `snapshots` s
              WHERE s.fetch_status <> 'success'
                AND EXISTS (SELECT 1 FROM `snapshots` s2
                             WHERE s2.page_id = s.page_id AND s2.fetch_status = 'success')"
        ),

        // Số trang đã có từ 2 phiên bản trở lên -> biết CN5 có hoạt động không.
        'multi_version_pages' => $count(
            "SELECT COUNT(*) FROM (
                SELECT page_id FROM `snapshots`
                 WHERE fetch_status = 'success'
                 GROUP BY page_id
                HAVING COUNT(*) > 1
             ) t"
        ),

        // Nhật ký quản trị.
        'admin_log_count' => $count('SELECT COUNT(*) FROM `admin_logs`'),
    ];
}

/**
 * Thống kê lỗi lưu URL theo từng mã lỗi (dashboard quản trị).
 *
 * @return array<int, array{code:string, label:string, count:int}>
 */
function wa_admin_error_breakdown(): array
{
    $stmt = db()->query(
        "SELECT fetch_status, COUNT(*) AS total
           FROM `snapshots`
          WHERE fetch_status <> 'success'
       GROUP BY fetch_status
       ORDER BY total DESC"
    );

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $code  = (string) $row['fetch_status'];
        $text  = wa_capture_error_text($code);
        $rows[] = [
            'code'  => $code,
            'label' => $text['title'],
            'count' => (int) $row['total'],
        ];
    }

    return $rows;
}

/**
 * Hoạt động gần đây, ghép từ dữ liệu thật trong CSDL (trang quản trị).
 *
 * Gồm các snapshot mới nhất và các tên miền vừa được thêm vào allowlist.
 *
 * @return array<int, array{icon:string, text:string, time:string}>
 */
function wa_recent_activity(int $limit = 6): array
{
    $limit = max(1, $limit);
    $items = [];

    // 1) Các bản snapshot mới nhất.
    $stmt = db()->prepare(
        "SELECT s.title, s.version_no, s.fetched_at, u.full_name AS creator_name, l.name AS library_name
           FROM `snapshots` s
           JOIN `saved_pages` sp ON sp.id = s.page_id
           JOIN `libraries` l     ON l.id = sp.library_id
           JOIN `users` u         ON u.id = sp.created_by
          ORDER BY s.fetched_at DESC, s.id DESC
          LIMIT {$limit}"
    );
    $stmt->execute();

    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'icon' => 'archive',
            'text' => 'Lưu snapshot v' . (int) $row['version_no'] . ' “' . wa_trim((string) $row['title'], 60) . '” vào thư viện ' . (string) $row['library_name'],
            'time' => wa_datetime((string) $row['fetched_at']) . ' • ' . (string) $row['creator_name'],
        ];
    }

    // 2) Các tên miền vừa được duyệt.
    $stmt = db()->prepare(
        "SELECT a.domain, a.created_at, u.full_name AS added_by
           FROM `allowlist_domains` a
           JOIN `users` u ON u.id = a.added_by
          ORDER BY a.created_at DESC, a.id DESC
          LIMIT {$limit}"
    );
    $stmt->execute();

    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'icon' => 'public',
            'text' => 'Thêm tên miền "' . (string) $row['domain'] . '" vào danh sách được duyệt',
            'time' => wa_datetime((string) $row['created_at']) . ' • ' . (string) $row['added_by'],
        ];
    }

    // Sắp xếp theo thời gian giảm dần rồi cắt lấy $limit sự kiện.
    usort($items, static function (array $a, array $b): int {
        return strcmp((string) $b['time'], (string) $a['time']);
    });

    return array_slice($items, 0, $limit);
}

/* =============================================================================
    CN5 — HÀM PHỤ: SỐ PHIÊN BẢN AN TOÀN, SO SÁNH NỘI DUNG, CHẠM TRANG
    -----------------------------------------------------------------------------
    Ba hàm dưới đây phục vụ cho yêu cầu CN5 của đề tài 16 ("cập nhật nội dung
    đã lưu"): mỗi bài phải có page_id cố định, các lần cập nhật phải gắn với
    page_id gốc, lưu snapshot v1/v2/v3 theo thứ tự, không ghi đè phiên bản cũ,
    không trùng số phiên bản khi cập nhật đồng thời, và hiển thị "Nội dung chưa
    thay đổi" khi nội dung không đổi.
    ============================================================================= */

/**
 * Số phiên bản TIẾP THEO của một trang — an toàn khi cập nhật đồng thời.
 *
 * P-FIX-CN5: trước đây dùng `wa_max_snapshot_version($pageId) + 1`. Hai người
 * bấm "Lấy phiên bản mới" cùng lúc sẽ CÙNG tính ra cùng một số -> INSERT vi
 * phạm UNIQUE(page_id, version_no) -> lỗi SQL và mất cả hai lần cập nhật.
 *
 * Cách sửa: chạy trong một giao dịch với `SELECT ... FOR UPDATE` trên bảng
 * `saved_pages` của trang đó. Khoá tới khi giao dịch kết thúc nên hai phiên
 * cập nhật phải xếp hàng, mỗi phiên thấy số phiên bản của phiên trước đã ghi.
 *
 * @return int Số phiên bản tiếp theo (>= 1).
 */
function wa_next_snapshot_version(int $pageId): int
{
    $pdo = db();
    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        // Khoá dòng trang để chặn hai phiên cập nhật chạy đồng thời.
        $lock = $pdo->prepare('SELECT id FROM `saved_pages` WHERE id = ? FOR UPDATE');
        $lock->execute([$pageId]);

        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(version_no), 0) FROM `snapshots`
              WHERE page_id = ? AND fetch_status = 'success'"
        );
        $stmt->execute([$pageId]);

        $next = (int) $stmt->fetchColumn() + 1;

        if ($startedTransaction) {
            $pdo->commit();
        }

        return max(1, $next);
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Không khoá được (bảng không phải InnoDB, v.v.) -> rơi về cách cũ.
        // Vẫn chỉ tính trên các bản THÀNH CÔNG để nhất quán với đường chính.
        return wa_max_success_snapshot_version($pageId) + 1;
    }
}

/**
 * So sánh nội dung mới với phiên bản thành công GẦN NHẤT của trang.
 *
 * P-FIX-CN5 — yêu cầu của đề tài: "So sánh nội dung mới với phiên bản trước.
 * Nếu nội dung thay đổi, tạo snapshot mới. Nếu không thay đổi, hiển thị
 * 'Nội dung chưa thay đổi'."
 *
 * FIX-05 — chính sách "thay đổi" gồm 3 tín hiệu:
 *   1. content_text (văn bản thuần đã bỏ thẻ và gộp khoảng trắng) khác, HOẶC
 *   2. title (chuẩn hoá: gộp khoảng trắng, hạ chữ) khác, HOẶC
 *   3. tập ảnh/tài nguyên có ý nghĩa khác (so sánh URL gốc đã sắp xếp — bỏ
 *      qua thứ tự xuất hiện và ảnh trùng).
 *
 * Khoảng trắng thừa, thay đổi thứ tự thuộc tính HTML, timestamp không liên
 * quan trong văn bản đã chuẩn hoá đều KHÔNG tạo phiên bản mới.
 *
 * @return array{changed:bool, snapshot:array<string,mixed>}|null
 *         null nghĩa là trang chưa có bản thành công nào để so sánh.
 */
function wa_compare_with_previous_snapshot(
    int $pageId,
    string $newTitle = '',
    string $newContentText = '',
    string $newContentHtml = ''
): ?array {
    // Tương thích ngược: nơi gọi cũ chỉ truyền content_text ở vị trí $newTitle.
    if ($newContentText === '' && $newContentHtml === '' && $newTitle !== '') {
        $newContentText = $newTitle;
        $newTitle = '';
    }

    $stmt = db()->prepare(
        "SELECT s.id, s.version_no, s.title, s.content_text, s.content_html, s.fetched_at,
                (SELECT COUNT(*) FROM `assets` a WHERE a.snapshot_id = s.id AND a.mime_type LIKE 'image/%') AS image_count
           FROM `snapshots` s
          WHERE s.page_id = ? AND s.fetch_status = 'success'
       ORDER BY s.version_no DESC, s.id DESC
          LIMIT 1"
    );
    $stmt->execute([$pageId]);

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    $changed = wa_snapshot_fingerprint_changed(
        (string) ($row['title'] ?? ''),
        (string) ($row['content_text'] ?? ''),
        (string) ($row['content_html'] ?? ''),
        (int) $row['id'],
        $newTitle,
        $newContentText,
        $newContentHtml
    );

    return [
        'changed'  => $changed,
        'snapshot' => [
            'id'          => (int) $row['id'],
            'version_no'  => (int) $row['version_no'],
            'title'       => (string) $row['title'],
            'fetched_at'  => (string) $row['fetched_at'],
            'image_count' => (int) $row['image_count'],
        ],
    ];
}

/**
 * FIX-05: fingerprint ổn định của một snapshot — true nếu CÓ thay đổi.
 *
 * So sánh chuẩn hoá title + text + tập URL ảnh gốc (lấy từ bảng assets cho
 * bản cũ, từ HTML mới cho bản mới). Bỏ qua khoảng trắng/thứ tự không nghĩa.
 */
function wa_snapshot_fingerprint_changed(
    string $oldTitle,
    string $oldText,
    string $oldHtml,
    int $oldSnapshotId,
    string $newTitle,
    string $newText,
    string $newHtml
): bool {
    $normTitle = static function (string $t): string {
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');

        return mb_strtolower($t, 'UTF-8');
    };

    if ($normTitle($oldTitle) !== $normTitle($newTitle)) {
        return true;
    }

    $oldClean = wa_capture_clean_text($oldText);
    $newClean = wa_capture_clean_text($newText);

    if ($oldClean !== $newClean) {
        return true;
    }

    // Tập ảnh cũ: URL gốc trong bảng assets (đã sắp xếp, loại trùng).
    $oldImgs = [];

    if ($oldSnapshotId > 0) {
        try {
            $st = db()->prepare(
                "SELECT DISTINCT original_url FROM `assets`
                  WHERE snapshot_id = ? AND mime_type LIKE 'image/%' ORDER BY original_url"
            );
            $st->execute([$oldSnapshotId]);
            $oldImgs = array_column($st->fetchAll(), 'original_url');
        } catch (Throwable $e) {
            $oldImgs = [];
        }
    }

    // Tập ảnh mới: src http(s) trong HTML mới (đã sắp xếp, loại trùng).
    $newImgs = [];

    if ($newHtml !== '') {
        $doc = wa_capture_dom('<html><body>' . $newHtml . '</body></html>');

        if ($doc !== null) {
            $nodes = (new DOMXPath($doc))->query('//img[@src]');

            if ($nodes !== false) {
                foreach (iterator_to_array($nodes) as $img) {
                    if ($img instanceof DOMElement) {
                        $src = trim($img->getAttribute('src'));

                        if ($src !== '' && wa_capture_is_http_url($src)) {
                            $newImgs[$src] = true;
                        }
                    }
                }

                $newImgs = array_keys($newImgs);
                sort($newImgs);
            }
        }
    }

    sort($oldImgs);

    return $oldImgs !== $newImgs;
}

/**
 * Cập nhật thời điểm chạm (updated_at) của một trang đã lưu.
 *
 * Dùng khi một lần kiểm tra nội dung KHÔNG làm thay đổi nội dung — người dùng
 * vẫn thấy trang vừa được kiểm tra gần đây.
 */
function wa_touch_page(int $pageId): void
{
    $stmt = db()->prepare('UPDATE `saved_pages` SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$pageId]);
}

/**
 * Bản snapshot THÀNH CÔNG mới nhất của một trang.
 *
 * Dùng để mở thẳng bài đã lưu khi phát hiện URL trùng lặp (CN1) — thay vì bắt
 * người dùng tự tìm trong danh sách.
 *
 * @return array<string,mixed>|null
 */
function wa_latest_successful_snapshot(int $pageId): ?array
{
    $stmt = db()->prepare(
        "SELECT id, version_no, title, fetched_at
           FROM `snapshots`
          WHERE page_id = ? AND fetch_status = 'success'
       ORDER BY version_no DESC, id DESC
          LIMIT 1"
    );
    $stmt->execute([$pageId]);

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    return [
        'id'         => (int) $row['id'],
        'version_no' => (int) $row['version_no'],
        'title'      => (string) $row['title'],
        'fetched_at' => (string) $row['fetched_at'],
    ];
}

/* =============================================================================
    LƯU TRANG — dữ liệu cho public/save.php (P-CAPTURE)
    ============================================================================= */

/**
 * Các thư viện mà người dùng được PHÉP LƯU TRANG vào (chủ sở hữu hoặc biên tập viên).
 *
 * Người xem (viewer) không thấy thư viện trong danh sách này.
 *
 * @return array<int, array{id:int, name:string, slug:string, role:string}>
 */
function wa_writable_libraries(?array $actor = null): array
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return [];
    }

    $userId = (int) $actor['id'];

    $stmt = db()->prepare(
        "SELECT l.id, l.name, l.slug,
                CASE WHEN l.owner_id = :owner_check THEN 'owner' ELSE lm.role END AS role
           FROM `libraries` l
           LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :member_check
          WHERE l.owner_id = :owner_where
             OR lm.role IN ('owner', 'editor')
          ORDER BY l.name"
    );

    // Ba vị trí so sánh cần BA tên tham số khác nhau (prepares thật của PDO không
    // cho phép lặp lại một tên tham số trong cùng câu lệnh — lỗi SQLSTATE[HY093]).
    $stmt->execute([
        ':owner_check'  => $userId,
        ':member_check' => $userId,
        ':owner_where'  => $userId,
    ]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id'   => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'role' => (string) $row['role'],
        ];
    }

    return $rows;
}

/**
 * Người dùng có quyền lưu trang vào thư viện này không (chủ sở hữu hoặc biên tập viên)?
 */
function wa_can_write_library(int $libraryId, ?array $actor = null): bool
{
    foreach (wa_writable_libraries($actor) as $library) {
        if ($library['id'] === $libraryId) {
            return true;
        }
    }

    return false;
}

/**
 * Chuẩn hoá URL về dạng KHOÁ so khớp ổn định.
 *
 * Vì sao cần hàm này (P-FIX-CN5): trước đây việc tìm "trang đã lưu" so khớp
 * bằng `source_url = ?` trên đúng chuỗi người dùng gõ. Điều đó sinh ra ba lỗi:
 *
 *   1. `http://x.vn/bai-viet` và `http://x.vn/bai-viet/` là HAI trang khác nhau.
 *   2. `https://www.x.vn/a` và `http://www.x.vn/a` là HAI trang khác nhau.
 *   3. Quan trọng nhất: khi URL NGUỒN chuyển hướng, `saved_pages.source_url`
 *      lưu URL ĐÍCH, còn lần tìm lại lại dùng URL GÕ — nên "Lấy phiên bản mới"
 *      không tìm thấy trang cũ và tạo ra một saved_pages MỚI. Đó chính là lỗi
 *      CN5 mà đề tài yêu cầu sửa.
 *
 * Hàm này tạo ra một "khoá" duy nhất cho mỗi trang:
 *   - hạ chữ thường toàn bộ scheme + host;
 *   - bỏ tiền tố "www.";
 *   - giữ nguyên đường dẫn (kể cả dấu "/" cuối — xem ghi chú bên dưới);
 *   - bỏ fragment (#...);
 *   - giữ query string (vì "?id=1" và "?id=2" là hai trang KHÁC nhau);
 *   - KHÔNG hợp nhất http/https — xem ghi chú bên dưới.
 *
 * VỀ VIỆC KHÔNG HỢP NHẤT http/https: hai lược chọn đều có lý do. Ta CHỌN KHÔNG
 * hợp nhất, vì một trang http và một trang https có thể phục vụ nội dung khác
 * nhau (đặc biệt với các trang đăng nhập). Thay vào đó, việc "URL đã tồn tại"
 * được kiểm tra bằng CẢ domain lẫn URL đầy đủ — xem wa_find_page_by_url().
 *
 * @return string Khoá so khớp (rỗng nếu URL không dùng được).
 */
function wa_normalize_page_url(string $url): string
{
    $url = trim($url);

    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);

    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));

    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }

    $host = strtolower((string) $parts['host']);
    $host = (string) preg_replace('/^www\./i', '', $host);

    if ($host === '') {
        return '';
    }

    // Cổng: bỏ cổng mặc định của từng scheme để http://x.vn:80/ == http://x.vn/
    $port = isset($parts['port']) ? (int) $parts['port'] : 0;

    if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
        $port = 0;
    }

    $authority = $host . ($port > 0 ? ':' . $port : '');

    $path = (string) ($parts['path'] ?? '');

    if ($path === '') {
        $path = '/';
    }

    $query = (string) ($parts['query'] ?? '');

    // Fragment (#mục-neo) KHÔNG phải nội dung khác nhau -> bỏ.
    return $scheme . '://' . $authority . $path . ($query !== '' ? '?' . $query : '');
}

/**
 * Tìm trang đã lưu trong một thư viện theo URL nguồn.
 *
 * P-FIX-CN5 — tìm theo CẢ ba dạng, theo thứ tự ưu tiên:
 *   1. URL đã chuẩn hoá (khoá ổn định).
 *   2. URL có/không dấu "/" ở cuối (giữ tương thích với dữ liệu cũ đã lưu
 *      trước khi có hàm chuẩn hoá).
 *   3. URL GỐC và URL ĐÍCH sau chuyển hướng — đây là mấu chốt sửa lỗi CN5:
 *      một lần lưu ghi `source_url` = URL ĐÍCH (sau redirect), nên khi người dùng
 *      bấm "Lấy phiên bản mới" với URL GỐC, ta phải vẫn tìm thấy đúng trang đó.
 *
 * Hàm này chỉ ĐỌC — không tạo, không sửa bất kỳ dòng nào.
 *
 * @param string[] $extraUrls Các URL bổ sung cần thử (ví dụ URL đích redirect).
 * @return array<string, mixed>|null
 */
function wa_find_page_by_url(int $libraryId, string $sourceUrl, array $extraUrls = []): ?array
{
    $candidates = [];

    $push = static function (string $url) use (&$candidates): void {
        $url = trim($url);

        if ($url === '') {
            return;
        }

        // Chuẩn hoá trước, rồi thêm cả bản gốc (chưa chuẩn hoá) để khớp với
        // dữ liệu cũ đã ghi trước đây.
        $normalized = wa_normalize_page_url($url);

        if ($normalized !== '' && !in_array($normalized, $candidates, true)) {
            $candidates[] = $normalized;
        }

        if (!in_array($url, $candidates, true)) {
            $candidates[] = $url;
        }

        $trimmed = rtrim($url, '/');

        if ($trimmed !== '' && !in_array($trimmed, $candidates, true)) {
            $candidates[] = $trimmed;
        }
    };

    $push($sourceUrl);

    foreach ($extraUrls as $extra) {
        $push((string) $extra);
    }

    if ($candidates === []) {
        return null;
    }

    // Truy vấn một lần với danh sách IN — nhanh hơn nhiều lần prepare().
    $placeholders = [];
    $params       = [];

    foreach (array_values($candidates) as $index => $candidate) {
        $placeholders[] = ':c' . $index;
        $params[':c' . $index] = $candidate;
    }

    $stmt = db()->prepare(
        'SELECT id, library_id, source_url, domain, title, status, created_by
           FROM `saved_pages`
          WHERE library_id = :lib_id
            AND source_url IN (' . implode(', ', $placeholders) . ')
          ORDER BY id
          LIMIT 1'
    );
    $params[':lib_id'] = $libraryId;
    $stmt->execute($params);

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    $row['id'] = (int) $row['id'];

    return $row;
}

/**
 * Tạo mới một dòng saved_pages.
 *
 * @return int id của trang vừa tạo
 */
function wa_create_page(
    int $libraryId,
    string $sourceUrl,
    string $domain,
    string $title,
    string $status,
    int $createdBy
): int {
    $stmt = db()->prepare(
        "INSERT INTO `saved_pages` (`library_id`, `source_url`, `domain`, `title`, `status`, `created_by`)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $libraryId,
        $sourceUrl,
        $domain,
        $title !== '' ? $title : $domain,
        $status,
        $createdBy,
    ]);

    return (int) db()->lastInsertId();
}

/**
 * Cập nhật tiêu đề và trạng thái của một trang đã lưu.
 */
function wa_update_page(int $pageId, string $title, string $status): void
{
    $stmt = db()->prepare(
        'UPDATE `saved_pages` SET `title` = ?, `status` = ? WHERE id = ?'
    );
    $stmt->execute([$title !== '' ? $title : 'Trang chưa có tiêu đề', $status, $pageId]);
}

/**
 * Cập nhật URL nguồn + tên miền của một trang đã lưu, GIỮ NGUYÊN page_id.
 *
 * P-FIX-CN5: dùng khi trang nguồn chuyển hướng sang địa chỉ khác (ví dụ
 * `a.vn/x` -> `b.vn/y`). Bản lưu phải tiếp tục thuộc CÙNG một trang để
 * chuỗi phiên bản v1 -> v2 -> v3 được liên tục và ghi chú cũ không bị mất.
 *
 * @return bool true nếu dòng trang được cập nhật.
 */
function wa_update_page_source_url(int $pageId, string $sourceUrl, string $domain): bool
{
    $stmt = db()->prepare(
        'UPDATE `saved_pages` SET `source_url` = ?, `domain` = ? WHERE id = ?'
    );
    $stmt->execute([
        $sourceUrl,
        $domain !== '' ? $domain : 'khong-ro',
        $pageId,
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * Số phiên bản lớn nhất đã có của một trang (0 nghĩa là chưa có phiên bản nào).
 */
function wa_max_snapshot_version(int $pageId): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(MAX(version_no), 0) FROM `snapshots` WHERE page_id = ?'
    );
    $stmt->execute([$pageId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Số phiên bản THÀNH CÔNG lớn nhất của một trang (0 nghĩa là chưa có bản
 * thành công nào).
 *
 * P-FIX-CN5: hàm này là "số phiên bản thật" của một trang — bản ghi lỗi KHÔNG
 * được tính vào đây. Số phiên bản tiếp theo luôn = hàm này + 1, nên vòng lặp
 * v1 -> v2 -> v3 tăng đúng và liên tục, và một lần tải lại thất bại không
 * làm "dịch chuyển" số phiên bản.
 */
function wa_max_success_snapshot_version(int $pageId): int
{
    $stmt = db()->prepare(
        "SELECT COALESCE(MAX(version_no), 0) FROM `snapshots`
          WHERE page_id = ? AND fetch_status = 'success'"
    );
    $stmt->execute([$pageId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Tạo mới một dòng snapshots.
 *
 * Hàm này được gọi TRƯỚC khi tải ảnh, vì thư mục lưu ảnh là
 * public/uploads/snapshots/<snapshot_id>/ nên phải có id trước.
 *
 * @return int id của snapshot vừa tạo
 */
function wa_create_snapshot(
    int $pageId,
    int $versionNo,
    string $title,
    string $contentHtml,
    string $contentText,
    string $sourceUrl,
    string $fetchedAt,
    int $httpStatus,
    string $fetchStatus,
    ?string $errorMessage
): int {
    $stmt = db()->prepare(
        "INSERT INTO `snapshots`
            (`page_id`, `version_no`, `title`, `content_html`, `content_text`,
             `source_url`, `fetched_at`, `http_status`, `fetch_status`, `error_message`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $pageId,
        $versionNo,
        $title !== '' ? $title : 'Trang chưa có tiêu đề',
        $contentHtml,
        $contentText,
        $sourceUrl,
        $fetchedAt,
        $httpStatus,
        $fetchStatus,
        $errorMessage,
    ]);

    return (int) db()->lastInsertId();
}

/**
 * Cập nhật nội dung của một snapshot sau khi ảnh đã được nối lại đường dẫn cục bộ.
 */
function wa_update_snapshot_content(int $snapshotId, string $contentHtml, string $contentText): void
{
    $stmt = db()->prepare(
        'UPDATE `snapshots` SET `content_html` = ?, `content_text` = ? WHERE id = ?'
    );
    $stmt->execute([$contentHtml, $contentText, $snapshotId]);
}

/**
 * Ghi các dòng assets cho một snapshot (ảnh đã tải về máy chủ nội bộ).
 *
 * @param array<int, array{original_url:string, local_path:string, mime_type:string, size_bytes:int}> $images
 */
function wa_insert_assets(int $snapshotId, array $images): void
{
    if ($images === []) {
        return;
    }

    $stmt = db()->prepare(
        "INSERT INTO `assets` (`snapshot_id`, `original_url`, `local_path`, `mime_type`, `size_bytes`)
         VALUES (?, ?, ?, ?, ?)"
    );

    foreach ($images as $image) {
        $stmt->execute([
            $snapshotId,
            (string) $image['original_url'],
            (string) $image['local_path'],
            (string) ($image['mime_type'] !== '' ? $image['mime_type'] : 'application/octet-stream'),
            (int) $image['size_bytes'],
        ]);
    }
}

/**
 * Gắn nhãn cho một trang đã lưu (tạo nhãn nếu chưa có).
 *
 * Tên nhãn đi qua chuẩn hoá chung (wa_normalize_tag_names) nên luôn kèm
 * tiền tố '#' — nhãn lưu từ form 'Lưu trang' đồng bộ với nhãn sửa trên thẻ.
 *
 * @param string[] $tagNames
 */
function wa_apply_page_tags(int $pageId, array $tagNames): void
{
    $names = wa_normalize_tag_names($tagNames);

    if ($names === []) {
        return;
    }

    $findTag   = db()->prepare('SELECT id FROM `tags` WHERE name = ? LIMIT 1');
    $insertTag = db()->prepare('INSERT IGNORE INTO `tags` (`name`) VALUES (?)');
    $getTagId  = db()->prepare('SELECT id FROM `tags` WHERE name = ? LIMIT 1');
    $attachTag = db()->prepare(
        'INSERT IGNORE INTO `page_tags` (`page_id`, `tag_id`) VALUES (?, ?)'
    );

    foreach ($names as $name) {
        $findTag->execute([$name]);

        if ($findTag->fetchColumn() === false) {
            $insertTag->execute([$name]);
        }

        $getTagId->execute([$name]);
        $tagId = $getTagId->fetchColumn();

        if ($tagId !== false) {
            $attachTag->execute([$pageId, (int) $tagId]);
        }
    }
}

/**
 * Tách chuỗi nhãn người dùng nhập (phân tách bằng dấu phẩy) thành mảng tên.
 *
 * @return string[]
 */
function wa_parse_tag_input(string $input): array
{
    $parts = preg_split('/[,;\r\n]+/u', $input) ?: [];

    return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
}

/* =============================================================================
    ĐƯỜNG LƯU DÙNG CHUNG — P-READ
    -----------------------------------------------------------------------------
    Hàm DUY NHẤT ghi một lần lưu trang xong CSDL. Cả hai nơi gọi đều dùng chung:
      1. public/save.php    — lưu trang mới / thêm phiên bản tiếp theo.
      2. public/refetch.php — nút "Lấy phiên bản mới" trong trình đọc.
    Việc gom về một hàm bảo đảm hai nơi không thể lệch nhau: find-or-create
    saved_pages, version_no = MAX + 1, INSERT snapshots, tải + nối lại ảnh cục bộ,
    INSERT assets, cập nhật trang + gắn nhãn, và ghi bản ghi lỗi.
    -------------------------------------------------------------------------- */

/**
 * Lưu một lần tải trang vào CSDL theo đúng một đường duy nhất.
 *
 * Luồng (khác với capture_url() ở chỗ đây là ghi CSDL):
 *   1. capture_url($url)                        — tải + bóc tách + sanitize
 *   2. tìm hoặc tạo saved_pages theo (library_id, source_url)
 *   3. version_no = MAX(version_no của các bản THÀNH CÔNG) + 1
 *   4. THÀNH CÔNG: INSERT snapshots -> tải ảnh về máy chủ nội bộ -> UPDATE
 *      content_html/content_text -> INSERT assets -> UPDATE saved_pages -> gắn nhãn
 *      -> LẬP CHỈ MỤC TÌM KIẾM (P-SEARCH) cho đúng snapshot vừa tạo
 *   5. THẤT BẠI: INSERT snapshots với fetch_status = mã lỗi và error_message
 *      tiếng Việt riêng cho từng mã. Bản ghi lỗi dùng version_no = 0 (số DÀNH
 *      RIÊNG cho lần thử thất bại) nên KHÔNG bao giờ chiếm mất số phiên bản
 *      thật, KHÔNG vi phạm UNIQUE(page_id, version_no), và bản lưu cũ (kể cả
 *      ghi chú của nó) luôn được giữ nguyên. Bản ghi lỗi cũ của cùng trang bị
 *      dọn đi vì nó chỉ mang giá trị thống kê.
 *      Bản ghi lỗi KHÔNG bao giờ mở được để đọc, và cũng không bao giờ có dòng
 *      chỉ mục tìm kiếm.
 *
 * Refetch dùng ĐÚNG hàm này nên: phiên bản cũ (kể cả ghi chú của chúng) không
 * bị đụng tới — hàm chỉ INSERT thêm một dòng snapshots mới.
 *
 * @param int      $libraryId Thư viện đích (đã kiểm tra quyền ở nơi gọi).
 * @param string   $url       URL nguồn (dùng đúng URL này để tìm trang đã lưu).
 * @param string[] $tagNames  Danh sách tên nhãn tuỳ chọn.
 * @param int      $userId    Tài khoản thực hiện lưu (saved_pages.created_by).
 * @param int      $expectedPageId page_id gốc (nút "Lấy phiên bản mới") — luôn
 *                               dùng lại page_id này để chuỗi phiên bản liên tục.
 *
 * @return array{
 *   ok:bool, error_code:?string, error_title:string, error_message:string,
 *   page_id:int, version:int, snapshot_id:?int, title:string, source_url:string,
 *   domain:string, http_status:int, fetched_at:string, image_count:int,
 *   indexed_terms:int
 * }
 */
function wa_store_page_capture(
    int $libraryId,
    string $url,
    array $tagNames,
    int $userId,
    int $expectedPageId = 0
): array {
    $url = trim($url);

    /* ---------- BƯỚC 1: chạy engine tải trang thật ---------- */
    $result = capture_url($url);

    $sourceUrl = (string) $result['final_url'] !== '' ? (string) $result['final_url'] : $url;
    $domain    = wa_capture_host($sourceUrl);

    /* ---------- BƯỚC 2: tìm hoặc tạo saved_pages (P-FIX-CN5) ----------
     *
     * MẤU CHỐT CỦA BẢN SỬA CN5:
     *
     * Trước đây ta gọi `wa_find_page_by_url($libraryId, $url)` — chỉ với URL
     * NGƯỜI DÙNG GÕ. Nhưng `saved_pages.source_url` lại lưu URL ĐÍCH sau khi
     * chuyển hướng ($sourceUrl ở trên). Nên khi URL nguồn có chuyển hướng:
     *
     *   Lần 1: gõ "http://a.vn/x" -> a.vn chuyển hướng -> "http://b.vn/y"
     *          -> tạo saved_pages với source_url = "http://b.vn/y"
     *   Lần 2 (bấm "Lấy phiên bản mới"): refetch.php lấy source_url của
     *          snapshot = "http://b.vn/y", gọi lại hàm này với "http://b.vn/y"
     *          -> tìm thấy trang cũ -> OK.
     *
     * NHƯNG nếu trang gốc BỎ chuyển hướng (hoặc đổi đích), hoặc nếu người dùng
     * gõ lại URL GỐC, thì lần tìm dùng "http://a.vn/x" không khớp với
     * "http://b.vn/y" đã lưu -> tạo RA MỘT saved_pages MỚI. Đó chính là lỗi
     * "tạo sai bài" mà đề tài nêu.
     *
     * LỐI GIẢI: ta truyền CẢ URL GÕ lẫn URL ĐÍCH vào hàm tìm kiếm, nên một
     * trang luôn được nhận diện bằng MỘT page_id duy nhất bất kể nó có chuyển
     * hướng hay không. Ngoài ra, nếu tên miền của URL đích khác URL gõ, ta
     * cũng thử tra cứu theo tên miền + đường dẫn để không bỏ sót trang đã lưu.
     */
    $page = wa_find_page_by_url($libraryId, $url, [$sourceUrl]);

    // P-FIX-CN5: nếu người gọi biết trước page_id (trường hợp "Lấy phiên bản
    // mới" từ trình đọc), ta ƯU TIÊN dùng chính page_id đó — đây là cam kết
    // mạnh nhất rằng mọi lần cập nhật đều gắn với MỘT trang gốc duy nhất.
    // Chỉ dùng lại page_id khi nó thực sự thuộc đúng thư viện này.
    if ($expectedPageId > 0) {
        $check = db()->prepare(
            'SELECT id, library_id, source_url, domain, title, status, created_by
               FROM `saved_pages`
              WHERE id = ? AND library_id = ?
              LIMIT 1'
        );
        $check->execute([$expectedPageId, $libraryId]);

        $expectedRow = $check->fetch();

        if ($expectedRow !== false) {
            $expectedRow['id'] = (int) $expectedRow['id'];
            $page = $expectedRow;
        }
    }

    // Dự phòng: tra cứu theo tên miền + đường dẫn khi hai URL (gõ / đích)
    // không khớp nhau do khác scheme hoặc khác "www.".
    if ($page === null) {
        $normalizedInput = wa_normalize_page_url($url);
        $normalizedFinal  = wa_normalize_page_url($sourceUrl);

        if ($normalizedInput !== '' && $normalizedFinal !== '' && $normalizedInput !== $normalizedFinal) {
            // Cùng tên miền + cùng đường dẫn => chắc chắn là cùng một trang.
            $path  = (string) (parse_url($normalizedFinal, PHP_URL_PATH) ?? '');
            $query = (string) (parse_url($normalizedFinal, PHP_URL_QUERY) ?? '');
            $suffix = $path . ($query !== '' ? '?' . $query : '');

            $stmt = db()->prepare(
                'SELECT id, library_id, source_url, domain, title, status, created_by
                   FROM `saved_pages`
                  WHERE library_id = :lib_id
                    AND domain = :domain
                    AND (source_url = :exact
                         OR source_url LIKE :like)
                  ORDER BY id
                  LIMIT 1'
            );
            $stmt->execute([
                ':lib_id' => $libraryId,
                ':domain' => $domain !== '' ? $domain : 'khong-ro',
                ':exact'  => $normalizedFinal,
                ':like'   => '%' . $suffix,
            ]);

            $row = $stmt->fetch();

            if ($row !== false) {
                $row['id'] = (int) $row['id'];
                $page = $row;
            }
        }
    }

    if ($page === null) {
        $pageId = wa_create_page(
            $libraryId,
            $url,
            $domain !== '' ? $domain : 'khong-ro',
            (string) $result['title'],
            $result['ok'] === true ? 'active' : 'error',
            $userId
        );
        $version = 1;
    } else {
        $pageId  = (int) $page['id'];
        $version = wa_next_snapshot_version($pageId);

        // Bài đã xoá mềm + người dùng lưu lại CÙNG URL -> TỰ KHÔI PHỤC bài cũ
        // thay vì tạo một saved_pages mới. Nhờ vậy chuỗi v1/v2/v3 và ghi chú
        // được giữ nguyên, và không sinh hai dòng trang trùng URL.
        //
        // Nơi gọi đã kiểm tra quyền ghi thư viện (owner/editor/admin) nên việc
        // khôi phục ở đây là hợp lệ — đúng người được phép lưu lại bài này.
        if ((string) ($page['status'] ?? 'active') === 'deleted') {
            db()->prepare(
                "UPDATE `saved_pages` SET status = 'active', updated_at = CURRENT_TIMESTAMP
                  WHERE id = ? AND status = 'deleted'"
            )->execute([$pageId]);
        }

        // P-FIX-CN5: nếu URL ĐÍCH (sau chuyển hướng) khác URL đang lưu, ta
        // cập nhật source_url của TRANG — nhưng GIỮ NGUYÊN page_id.
        //
        // Đây là mắt xích quan trọng cho yêu cầu "cập nhật URL cũ, giữ page_id":
        // nếu nguồn chuyển hướng sang địa chỉ mới, bản lưu vẫn phải thuộc
        // CÙNG một trang (v1 -> v2 -> v3 liên tục), không được tách thành hai
        // saved_pages khác nhau.
        if ($sourceUrl !== '' && $sourceUrl !== (string) ($page['source_url'] ?? '')) {
            wa_update_page_source_url($pageId, $sourceUrl, $domain);
        }
    }

    /* ---------- BƯỚC 3 + 4: ghi snapshot theo kết quả tải ---------- */
    if ($result['ok'] === true) {
        // FIX-04: KHÔNG đánh dấu success khi nội dung rỗng/thiếu. Engine đã
        // lọc ngưỡng 200 ký tự, nhưng phòng vệ thêm ở đây: nếu HTML và TEXT
        // đều rỗng thì coi như lỗi no_content thay vì tạo snapshot success
        // rỗng (bản success rỗng sẽ hiện trong thư viện nhưng không đọc được).
        if (trim((string) $result['content_html']) === ''
            && trim((string) $result['content_text']) === ''
        ) {
            $result = array_merge($result, [
                'ok' => false,
                'error_code' => WA_ERR_NO_CONTENT,
                'error_message' => 'trang nguồn không trả về nội dung đọc được',
            ]);
        }
    }

    if ($result['ok'] === true) {
        /* --- 4a) SO SÁNH VỚI PHIÊN BẢN TRƯỚC (P-FIX-CN5 + FIX-05) ---------
         *
         * Nếu nội dung KHÔNG đổi, ta KHÔNG tạo phiên bản mới — vì mục tiêu của
         * CN5 là "theo dõi sự thay đổi của nội dung", và một bản sao y hệt bản
         * cũ chỉ là dữ liệu thừa. Hàm này trả về:
         *   - null            : chưa có phiên bản thành công nào (chỉ có lần lỗi)
         *   - ['changed'=>..] : so sánh với bản thành công gần nhất
         */
        $comparison = wa_compare_with_previous_snapshot(
            $pageId,
            (string) $result['title'],
            (string) $result['content_text'],
            (string) $result['content_html']
        );

        if ($comparison !== null && $comparison['changed'] === false) {
            /* --- NỘI DUNG KHÔNG THAY ĐỔI ------------------------------------
             * KHÔNG tạo snapshot mới (nếu tạo, số phiên bản sẽ tăng vô ích và
             * người dùng sẽ thấy N bản giống hệt nhau). Chỉ cập nhật thời điểm
             * chạm của trang để người dùng thấy "đã kiểm tra lúc ...".
             */
            wa_touch_page($pageId);

            $last = $comparison['snapshot'];

            return [
                'ok'            => true,
                'error_code'    => null,
                'error_title'   => '',
                'error_message' => '',
                'page_id'       => $pageId,
                'version'       => (int) $last['version_no'],
                'snapshot_id'   => (int) $last['id'],
                'title'         => (string) $result['title'],
                'source_url'    => $sourceUrl,
                'domain'        => $domain,
                'http_status'   => (int) $result['http_status'],
                'fetched_at'    => (string) $result['fetched_at'],
                'image_count'   => (int) $last['image_count'],
                'indexed_terms' => 0,
                'unchanged'     => true,
                'changed'       => false,
            ];
        }

        // FIX-03/FIX-04: ghi snapshot trong MỘT transaction ngắn.
        //
        //   - BEGIN + SELECT saved_pages FOR UPDATE (giữ page_id, khoá dòng).
        //   - Tính version_no TIẾP THEO trong transaction (không dùng số đã
        //     tính từ TRƯỚC khi tải HTTP — khoá cũ đã nhả nên hai request
        //     đồng thời có thể trùng số).
        //   - INSERT snapshot với nội dung ĐẦY ĐỦ ngay từ đầu (không còn bước
        //     "tạo dòng rỗng rồi UPDATE sau" — nếu lỗi giữa chừng thì rollback,
        //     không để lại snapshot success rỗng).
        //   - Tải ảnh HTTP đã xong TRƯỚC transaction (không giữ transaction
        //     trong lúc tải mạng). Trong transaction chỉ còn INSERT/UPDATE DB.
        //   - UNIQUE(page_id, version_no) là chốt chặn cuối: nếu vẫn xung đột
        //     (deadlock/retry), bắt 23000 và thử lại tối đa 3 lần.
        // 4b) Tải + nối ảnh TRƯỚC (ngoài transaction — không giữ khoá khi tải).
        // Dùng snapshotId tạm 0: ảnh ghi vào thư mục tạm rồi chuyển về đúng
        // thư mục sau khi có id thật.
        $localized = wa_capture_localize_images_staged((string) $result['content_html']);
        $finalHtml = (string) $localized['html'];
        $images    = $localized['images'];

        // Bỏ qua nếu còn sót ảnh ngoài — không bao giờ xảy ra (phòng vệ).
        if (!wa_capture_localize_images_ok($finalHtml)) {
            $finalHtml = (string) $result['content_html'];
            $images    = [];
        }

        $finalText = wa_capture_clean_text(wa_capture_text_only($finalHtml));

        // FIX-04: nội dung cuối vẫn rỗng -> không tạo success rỗng.
        if (trim($finalHtml) === '' && trim($finalText) === '') {
            $result = array_merge($result, [
                'ok' => false,
                'error_code' => WA_ERR_NO_CONTENT,
                'error_message' => 'trang nguồn không trả về nội dung đọc được',
            ]);
        } else {
            $snapshotId = 0;
            $indexedTerms = 0;
            $attempt = 0;

            while (true) {
                $attempt++;
                $pdo = db();

                try {
                    $pdo->beginTransaction();

                    // Khoá dòng trang + xác nhận page_id vẫn thuộc thư viện.
                    $lock = $pdo->prepare(
                        'SELECT id FROM `saved_pages` WHERE id = ? AND library_id = ? FOR UPDATE'
                    );
                    $lock->execute([$pageId, $libraryId]);

                    if ($lock->fetchColumn() === false) {
                        $pdo->rollBack();

                        return [
                            'ok' => false,
                            'error_code' => WA_ERR_HTTP,
                            'error_title' => 'Không thể lưu trang',
                            'error_message' => 'Trang đã bị xoá hoặc chuyển khỏi thư viện trong lúc tải.',
                            'page_id' => $pageId,
                            'version' => 0,
                            'snapshot_id' => 0,
                            'title' => (string) $result['title'],
                            'source_url' => $sourceUrl,
                            'domain' => $domain,
                            'http_status' => (int) $result['http_status'],
                            'fetched_at' => (string) $result['fetched_at'],
                            'image_count' => 0,
                            'indexed_terms' => 0,
                        ];
                    }

                    // Tính version_no trong transaction (sau khoá).
                    $vstmt = $pdo->prepare(
                        "SELECT COALESCE(MAX(version_no), 0) FROM `snapshots`
                          WHERE page_id = ? AND fetch_status = 'success'"
                    );
                    $vstmt->execute([$pageId]);
                    $version = (int) $vstmt->fetchColumn() + 1;

                    // INSERT snapshot với nội dung ĐẦY ĐỦ ngay từ đầu.
                    $snapshotId = wa_create_snapshot(
                        $pageId,
                        $version,
                        (string) $result['title'],
                        $finalHtml,
                        $finalText,
                        $sourceUrl,
                        (string) $result['fetched_at'],
                        (int) $result['http_status'],
                        'success',
                        null
                    );

                    // Ghi assets + cập nhật trang + gắn nhãn — cùng transaction.
                    wa_insert_assets($snapshotId, $images);
                    wa_update_page($pageId, (string) $result['title'], 'active');
                    wa_apply_page_tags($pageId, $tagNames);

                    $pdo->commit();
                    break;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    // Xung đột UNIQUE hoặc deadlock -> thử lại (tối đa 3).
                    if ($attempt < 3 && (
                        str_contains($e->getMessage(), '23000')
                        || str_contains($e->getMessage(), '1213')
                        || str_contains($e->getMessage(), '1205')
                    )) {
                        usleep(100000 * $attempt);
                        continue;
                    }

                    throw $e;
                }
            }

            // Chuyển ảnh từ thư mục tạm về thư mục snapshot thật.
            wa_capture_commit_staged_images($snapshotId, $finalHtml, $images);

            // Ghi lại content_html CUỐI (URL ảnh đích) — đè lên nội dung đã
            // INSERT (lúc đó còn prefix tạm).
            wa_update_snapshot_content($snapshotId, $finalHtml, $finalText);

            // 4g) P-SEARCH: lập chỉ mục tìm kiếm cho đúng BẢN SNAPSHOT NÀY.
            //      Chỉ bản thành công mới có dòng chỉ mục; mỗi phiên bản có chỉ mục
            //      riêng, nên kết quả tìm kiếm luôn gắn với đúng phiên bản đã lưu.
            $indexedTerms = wa_search_index_snapshot($snapshotId);

            // FIX-09: ghi lần thử thành công vào lịch sử attempts.
            wa_capture_log_attempt($pageId, $libraryId, $sourceUrl, true, 'success',
                (string) $result['http_status'], '');

            return [
                'ok'            => true,
                'error_code'    => null,
                'error_title'   => '',
                'error_message' => '',
                'page_id'       => $pageId,
                'version'       => $version,
                'snapshot_id'   => $snapshotId,
                'title'         => (string) $result['title'],
                'source_url'    => $sourceUrl,
                'domain'        => $domain,
                'http_status'   => (int) $result['http_status'],
                'fetched_at'    => (string) $result['fetched_at'],
                'image_count'   => count($images),
                'indexed_terms' => $indexedTerms,
                'unchanged'     => false,
                'changed'       => true,
            ];
        }
    }

    /* ---------- THẤT BẠI — ghi lần thử vào capture_attempts ---------- */
    // FIX-09: KHÔNG xoá lịch sử lỗi cũ, KHÔNG tạo snapshot success giả.
    // Mọi lần thử thất bại ghi vào `capture_attempts` (page_id, thời gian,
    // mã lỗi, nguyên nhân đã làm sạch — không mật khẩu/cookie). Bảng
    // `snapshots` chỉ giữ các phiên bản thành công + 1 bản ghi lỗi mới nhất
    // để trang thư viện hiển thị (tương thích giao diện cũ).
    $errorCode = (string) ($result['error_code'] ?? WA_ERR_HTTP);
    $errorText = wa_capture_error_text($errorCode, (string) ($result['error_message'] ?? ''));

    // P-FIX-CN5 — SỐ PHIÊN BẢN CỦA BẢN GHI LỖI.
    //
    // Nguyên tắc: PHIÊN BẢN chỉ dành cho bản lưu THÀNH CÔNG. Một lần tải lại
    // thất bại KHÔNG được:
    //   (a) chiếm mất số phiên bản của một bản thành công đang tồn tại — vì
    //       UNIQUE(page_id, version_no) sẽ ném lỗi SQL 23000 và làm HỎNG cả
    //       lần tải lại hợp lệ (lỗi này xảy ra trước đây: bản lưu v1 thành
    //       công + lần tải lại lỗi -> ghi version_no=1 -> vi phạm UNIQUE);
    //   (b) nhảy số phiên bản lên, vì lần tải lại THÀNH CÔNG kế tiếp tính
    //       số = MAX(success)+1 sẽ đụng số mà bản ghi lỗi đã chiếm.
    //
    // LỐI GIẢI: bản ghi lỗi dùng version_no = 0 — một số DÀNH RIÊNG cho lần
    // thử thất bại, không bao giờ trùng với số phiên bản thật (bắt đầu từ 1).
    // Trước khi ghi bản ghi lỗi mới, ta XOÁ bản ghi lỗi CŨ của cùng trang (nó
    // chỉ mang giá trị thống kê) để không vi phạm UNIQUE. Các bản lưu THÀNH
    // CÔNG và ghi chú của chúng KHÔNG BAO GIỜ bị đụng tới.
    //
    // FIX-09: đồng thời ghi MỌI lần thử vào `capture_attempts` (lịch sử đầy
    // đủ, không bị xoá) — chi tiết đã làm sạch, không mật khẩu/cookie.
    wa_capture_log_attempt($pageId, $libraryId, $sourceUrl, false, $errorCode,
        (string) $result['http_status'], $errorText['message']);

    $cleanup = db()->prepare(
        "DELETE FROM `snapshots`
          WHERE page_id = ? AND fetch_status <> 'success'"
    );
    $cleanup->execute([$pageId]);

    $failedSnapshotId = wa_create_snapshot(
        $pageId,
        0,
        $errorText['title'],
        '',
        '',
        $sourceUrl,
        (string) $result['fetched_at'],
        (int) $result['http_status'],
        $errorCode,
        $errorText['message']
    );

    if ($tagNames !== []) {
        wa_apply_page_tags($pageId, $tagNames);
    }

    // P-SEARCH: bản ghi lỗi KHÔNG bao giờ có dòng chỉ mục tìm kiếm. Xoá sạch
    // phòng vệ để một lần lưu thất bại không bao giờ lọt vào kết quả tìm kiếm.
    wa_search_index_snapshot($failedSnapshotId);

    return [
        'ok'            => false,
        'error_code'    => $errorCode,
        'error_title'   => $errorText['title'],
        'error_message' => $errorText['message'],
        'page_id'       => $pageId,
        // P-FIX-CN5: khi lần tải lại THẤT BẠI, KHÔNG có phiên bản mới — nên
        // trả về số phiên bản đang có (0 nếu chưa có bản thành công nào), để
        // giao diện nói đúng "vẫn là phiên bản N" thay vì nói một số chưa có.
        'version'       => wa_max_success_snapshot_version($pageId),
        'snapshot_id'   => $failedSnapshotId,
        'title'         => $errorText['title'],
        'source_url'    => $sourceUrl,
        'domain'        => $domain,
        'http_status'   => (int) $result['http_status'],
        'fetched_at'    => (string) $result['fetched_at'],
        'image_count'   => 0,
        'indexed_terms' => 0,
    ];
}

/* =============================================================================
    PHIÊN BẢN & GHI CHÚ THEO TỪNG SNAPSHOT — P-READ
    ============================================================================= */

/**
 * Các phiên bản THÀNH CÔNG của một trang, version_no giảm dần.
 *
 * Chỉ lấy fetch_status = 'success': một lần tải lại thất bại vẫn còn trong CSDL
 * để đếm lỗi, nhưng không bao giờ xuất hiện trong bộ chuyển phiên bản.
 *
 * @return array<int, array{id:int, version_no:int, fetched_at:string, fetched_label:string,
 *                          http_status:int, title:string, is_current:bool}>
 */
function wa_page_versions(int $pageId, int $currentSnapshotId = 0): array
{
    $stmt = db()->prepare(
        "SELECT id, version_no, fetched_at, http_status, title
           FROM `snapshots`
          WHERE page_id = ? AND fetch_status = 'success'
       ORDER BY version_no DESC, id DESC"
    );
    $stmt->execute([$pageId]);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $id        = (int) $row['id'];
        $fetchedAt = (string) $row['fetched_at'];

        $rows[] = [
            'id'            => $id,
            'version_no'    => (int) $row['version_no'],
            'fetched_at'    => $fetchedAt,
            'fetched_label' => wa_datetime($fetchedAt),
            'http_status'   => (int) $row['http_status'],
            'title'         => (string) $row['title'],
            'is_current'    => $id === $currentSnapshotId,
        ];
    }

    return $rows;
}

/**
 * Phiên bản liền trước / liền sau của snapshot đang xem (theo version_no).
 *
 * @return array{prev:?array<string,mixed>, next:?array<string,mixed>}
 */
function wa_page_neighbour_versions(int $pageId, int $currentSnapshotId): array
{
    $versions = wa_page_versions($pageId, $currentSnapshotId);

    $index = null;
    foreach ($versions as $position => $version) {
        if ($version['id'] === $currentSnapshotId) {
            $index = $position;
            break;
        }
    }

    if ($index === null) {
        return ['prev' => null, 'next' => null];
    }

    return [
        // Danh sách đang sắp xếp GIẢM DẦN: phần tử sau là phiên bản cũ hơn.
        'prev' => $versions[$index - 1] ?? null,
        'next' => $versions[$index + 1] ?? null,
    ];
}

/**
 * Ghi chú của MỘT phiên bản snapshot, cùng tên tác giả, cũ nhất trước.
 *
 * `edited` = true khi updated_at muộn hơn created_at, để giao diện đánh dấu.
 *
 * @return array<int, array{id:int, user_id:int, author_name:string, content:string,
 *                          created_at:string, created_label:string, edited:bool, edited_label:string}>
 */
function wa_notes_for_snapshot(int $snapshotId): array
{
    $stmt = db()->prepare(
        'SELECT n.id, n.user_id, n.content, n.created_at, n.updated_at, u.full_name
           FROM `notes` n
           JOIN `users` u ON u.id = n.user_id
          WHERE n.snapshot_id = ?
       ORDER BY n.created_at, n.id'
    );
    $stmt->execute([$snapshotId]);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $createdAt = (string) $row['created_at'];
        $updatedAt = (string) $row['updated_at'];

        $rows[] = [
            'id'            => (int) $row['id'],
            'user_id'       => (int) $row['user_id'],
            'author_name'   => (string) $row['full_name'],
            'content'       => (string) $row['content'],
            'created_at'    => $createdAt,
            'created_label' => wa_datetime($createdAt),
            'edited'        => $updatedAt > $createdAt,
            'edited_label'  => wa_datetime($updatedAt),
        ];
    }

    return $rows;
}

/**
 * FIX-08: phạm vi thật của một ghi chú — snapshot_id + library_id của nó.
 *
 * @return array{snapshot_id:int, library_id:int, user_id:int}|null
 */
function wa_note_scope(int $noteId): ?array
{
    $stmt = db()->prepare(
        'SELECT n.snapshot_id, n.user_id, sp.library_id
           FROM `notes` n
           JOIN `snapshots` s ON s.id = n.snapshot_id
           JOIN `saved_pages` sp ON sp.id = s.page_id
          WHERE n.id = ?
          LIMIT 1'
    );
    $stmt->execute([$noteId]);

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    return [
        'snapshot_id' => (int) $row['snapshot_id'],
        'library_id'  => (int) $row['library_id'],
        'user_id'     => (int) $row['user_id'],
    ];
}

/**
 * FIX-09: ghi một lần thử tải (thành công hay thất bại) vào `capture_attempts`.
 *
 * Chi tiết lỗi được làm sạch: gộp khoảng trắng, cắt 500 ký tự, loại bỏ mọi
 * chuỗi trông như mật khẩu/cookie/token. Không bao giờ ném lỗi ra ngoài
 * (bảng có thể chưa được migrate) — thất bại thì bỏ qua lặng lẽ.
 */
function wa_capture_log_attempt(
    int $pageId,
    int $libraryId,
    string $sourceUrl,
    bool $ok,
    string $statusCode,
    string $httpStatus,
    string $detail
): void {
    try {
        $clean = trim(preg_replace('/\s+/u', ' ', $detail) ?? '');
        // Loại chuỗi nhạy cảm nếu vô tình lọt vào.
        $clean = preg_replace('/(password|passwd|pwd|cookie|token|secret|api[_-]?key)\s*[:=]\s*\S+/i', '$1=***', $clean) ?? $clean;
        $clean = mb_substr($clean, 0, 500, 'UTF-8');

        db()->prepare(
            'INSERT INTO `capture_attempts`
                (`page_id`, `library_id`, `source_url`, `ok`, `status_code`, `http_status`, `detail`)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $pageId,
            $libraryId,
            mb_substr($sourceUrl, 0, 1000, 'UTF-8'),
            $ok ? 1 : 0,
            mb_substr($statusCode, 0, 60, 'UTF-8'),
            mb_substr($httpStatus, 0, 10, 'UTF-8'),
            $clean !== '' ? $clean : null,
        ]);
    } catch (Throwable $e) {
        // Bảng chưa tồn tại (chưa migrate) -> bỏ qua, không làm hỏng lần lưu.
    }
}

/**
 * FIX-09: lịch sử các lần thử tải của một trang (mới nhất trước).
 *
 * @return array<int, array<string,mixed>>
 */
function wa_capture_attempts(int $pageId, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));

    try {
        $stmt = db()->prepare(
            'SELECT id, ok, status_code, http_status, detail, created_at
               FROM `capture_attempts`
              WHERE page_id = ?
           ORDER BY id DESC
              LIMIT ' . $limit
        );
        $stmt->execute([$pageId]);

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Thêm một ghi chú vào một snapshot.
 *
 * @return array{ok:bool, message:string, note_id?:int}
 */
function wa_note_add(int $snapshotId, int $userId, string $content): array
{
    $content = trim(preg_replace('/[ \t]+/u', ' ', $content) ?? '');

    if ($content === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập nội dung ghi chú.'];
    }

    if (mb_strlen($content, 'UTF-8') > 2000) {
        return ['ok' => false, 'message' => 'Ghi chú không được vượt quá 2000 ký tự.'];
    }

    $stmt = db()->prepare('INSERT INTO `notes` (`snapshot_id`, `user_id`, `content`) VALUES (?, ?, ?)');
    $stmt->execute([$snapshotId, $userId, $content]);

    return [
        'ok'      => true,
        'message' => 'Đã lưu ghi chú cho phiên bản này.',
        'note_id' => (int) db()->lastInsertId(),
    ];
}

/**
 * Sửa một ghi chú — CHỈ tác giả của chính ghi chú đó mới sửa được.
 *
 * Điều kiện `user_id = ?` nằm TRONG CÂU UPDATE (không chỉ kiểm tra ở giao diện):
 * nếu ai đó gửi thẳng POST bằng tay, câu lệnh vẫn không đụng tới dòng dữ liệu
 * của người khác và hàm trả về ok = false.
 *
 * @return array{ok:bool, message:string}
 */
function wa_note_update(int $noteId, int $userId, string $content): array
{
    $content = trim(preg_replace('/[ \t]+/u', ' ', $content) ?? '');

    if ($content === '') {
        return ['ok' => false, 'message' => 'Vui lòng nhập nội dung ghi chú.'];
    }

    if (mb_strlen($content, 'UTF-8') > 2000) {
        return ['ok' => false, 'message' => 'Ghi chú không được vượt quá 2000 ký tự.'];
    }

    $stmt = db()->prepare('UPDATE `notes` SET `content` = ? WHERE `id` = ? AND `user_id` = ?');
    $stmt->execute([$content, $noteId, $userId]);

    if ($stmt->rowCount() === 0) {
        return [
            'ok'      => false,
            'message' => 'Bạn chỉ có thể sửa ghi chú do chính mình viết.',
        ];
    }

    return ['ok' => true, 'message' => 'Đã cập nhật ghi chú.'];
}

/**
 * Xoá ghi chú — CHỈ tác giả của chính ghi chú đó mới xoá được.
 *
 * Điều kiện `user_id = ?` nằm trong câu DELETE; quyền xoá của quản trị viên là
 * một đường riêng (wa_note_delete_by_admin) chứ không đi qua đây.
 *
 * @return array{ok:bool, message:string}
 */
function wa_note_delete(int $noteId, int $userId): array
{
    $stmt = db()->prepare('DELETE FROM `notes` WHERE `id` = ? AND `user_id` = ?');
    $stmt->execute([$noteId, $userId]);

    if ($stmt->rowCount() === 0) {
        return [
            'ok'      => false,
            'message' => 'Bạn chỉ có thể xoá ghi chú do chính mình viết.',
        ];
    }

    return ['ok' => true, 'message' => 'Đã xoá ghi chú.'];
}

/**
 * Quản trị viên xoá bất kỳ ghi chú nào (đường xoá riêng, không kiểm tra tác giả).
 *
 * @return array{ok:bool, message:string}
 */
function wa_note_delete_by_admin(int $noteId): array
{
    $stmt = db()->prepare('DELETE FROM `notes` WHERE `id` = ?');
    $stmt->execute([$noteId]);

    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Không tìm thấy ghi chú cần xoá.'];
    }

    return ['ok' => true, 'message' => 'Quản trị viên đã xoá ghi chú.'];
}

/**
 * Các lần lưu THẤT BẠI gần đây của một thư viện (mới nhất trước).
 *
 * Những trang chưa từng có snapshot thành công không xuất hiện trong danh sách
 * bài viết, nên trang library.php hiển thị chúng ở mục riêng cho người có
 * quyền biên tập.
 *
 * @return array<int, array<string, mixed>>
 */
function wa_library_failed_attempts(int $libraryId, int $limit = 5): array
{
    $limit = max(1, min(20, $limit));

    // $limit là int đã ép kiểu ở trên nên nối thẳng được (không phải dữ liệu người
    // dùng); các điều kiện khác đều dùng tham số có tên.
    //
    // P-FIX-CN5: hiện BẤT KỲ lần lưu thất bại nào, kể cả lần "Lấy phiên bản
    // mới" thất bại trên một trang ĐÃ có bản thành công. Trước đây hàm này loại
    // bỏ các trang đã có bản thành công, khiến người dùng KHÔNG THẤY được
    // lần tải lại lỗi — đúng lúc họ cần biết vì sao bản mới không sinh ra.
    // Cột `has_success` cho biết trang đó còn bản đọc được hay không.
    $stmt = db()->prepare(
        "SELECT p.id AS page_id, p.source_url, p.domain, p.title,
                s.id AS snapshot_id, s.version_no, s.fetch_status, s.http_status,
                s.fetched_at, s.error_message,
                EXISTS (SELECT 1 FROM `snapshots` s2
                         WHERE s2.page_id = p.id AND s2.fetch_status = 'success') AS has_success
           FROM `snapshots` s
           JOIN `saved_pages` p ON p.id = s.page_id
          WHERE p.library_id = ?
            AND s.fetch_status <> 'success'
       ORDER BY s.fetched_at DESC, s.id DESC
          LIMIT {$limit}"
    );
    $stmt->execute([$libraryId]);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $fetchedAt = (string) $row['fetched_at'];

        $rows[] = [
            'page_id'       => (int) $row['page_id'],
            'snapshot_id'   => (int) $row['snapshot_id'],
            'version_no'    => (int) $row['version_no'],
            'source_url'    => (string) $row['source_url'],
            'domain'        => (string) $row['domain'],
            'title'         => (string) $row['title'],
            'fetch_status'  => (string) $row['fetch_status'],
            'status_label'  => wa_fetch_status_label((string) $row['fetch_status']),
            'http_status'   => (int) $row['http_status'],
            'fetched_at'    => $fetchedAt,
            'fetched_label' => wa_datetime($fetchedAt),
            'error_message' => (string) ($row['error_message'] ?? ''),
            'has_success'   => (int) $row['has_success'] === 1,
        ];
    }

    return $rows;
}

/**
 * Nhãn tiếng Việt của một mã trạng thái tải trang (dùng cho snapshot lỗi).
 */
function wa_fetch_status_label(string $status): string
{
    return match ($status) {
        'success'           => 'Thành công',
        'error_404'         => 'Trang gốc trả về 404',
        'error_timeout'     => 'Hết thời gian chờ',
        'error_blocked'     => 'Ngoài danh sách được duyệt',
        'error_no_content'  => 'Không có nội dung đọc được',
        'error_http'        => 'Máy chủ nguồn trả về lỗi',
        'error_invalid_url' => 'URL không hợp lệ',
        'error_403'         => 'Máy chủ nguồn từ chối truy cập (403)',
        'error_429'         => 'Bị giới hạn tần suất yêu cầu (429)',
        'error_5xx'         => 'Máy chủ nguồn đang lỗi (5xx)',
        'error_ssl'         => 'Lỗi chứng thư SSL',
        'error_login'       => 'Trang yêu cầu đăng nhập',
        'error_captcha'     => 'Trang có bắt buộc xác minh CAPTCHA',
        'error_unsafe'      => 'URL trỏ vào mạng nội bộ (bị chặn SSRF)',
        default             => 'Lỗi không xác định',
    };
}

/**
 * Người dùng có được tải lại (refetch) trang trong thư viện này không?
 *
 * Chủ sở hữu và biên tập viên được tải lại; người xem thì không.
 * Quản trị viên luôn được phép (đã kiểm tra trước ở nơi gọi).
 */
function wa_can_refetch_library(int $libraryId, ?array $actor = null): bool
{
    return wa_can_write_library($libraryId, $actor);
}

/**
 * Người dùng có quyền XEM thư viện này không (chủ sở hữu, thành viên, hoặc quản trị)?
 */
function wa_can_read_library(int $libraryId, ?array $actor = null): bool
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return false;
    }

    if ($actor['role'] === 'admin') {
        return true;
    }

    // Ba vị trí so sánh dùng BA tên tham số khác nhau (prepares thật của PDO không
    // cho phép lặp lại một tên tham số trong cùng câu lệnh — SQLSTATE[HY093]).
    $stmt = db()->prepare(
        'SELECT 1
           FROM `libraries` l
           LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :actor
          WHERE l.id = :library_id
            AND (l.owner_id = :owner_id OR lm.user_id IS NOT NULL)
          LIMIT 1'
    );
    $stmt->execute([
        ':actor'      => (int) $actor['id'],
        ':owner_id'   => (int) $actor['id'],
        ':library_id' => $libraryId,
    ]);

    return $stmt->fetchColumn() !== false;
}

/* =============================================================================
    NGỮ CẢNH ĐỌC MỘT SNAPSHOT — P-READ
    -----------------------------------------------------------------------------
    reader.php cần biết trước khi vẽ gì: ai đang đọc, họ có quyền xem thư viện
    không, họ có quyền tải lại không, và các phiên bản + ghi chú của đúng snapshot
    đang mở. Hàm này gộp phần giải quyết đó lại một chỗ.
    ============================================================================= */

/**
 * Giải quyết ngữ cảnh đọc của một snapshot id.
 *
 * @return array{status:string, article?:array<string,mixed>, library_id?:int,
 *               page_id?:int, role?:string, can_refetch?:bool, versions?:array,
 *               prev?:?array, next?:?array, notes?:array}
 *
 * status:
 *   - 'ok'       : đọc được.
 *   - 'denied'   : đã đăng nhập nhưng không thuộc thư viện của snapshot -> hiện 403.
 *   - 'not_found': không tồn tại, không phải success, hoặc không có quyền xem
 *                  -> hiện 404 (đúng quy tắc: bản ghi lỗi không mở được).
 */
function wa_reader_context(int $snapshotId): array
{
    $actor = wa_actor();

    // --- Bước 1: chỉ lấy snapshot có fetch_status = 'success' (bản ghi lỗi -> 404),
    //     và bài CHƯA bị xoá mềm (bài đã xoá -> 404 như không tồn tại).
    $stmt = db()->prepare(
        'SELECT s.id, s.page_id, s.fetch_status, p.library_id, p.status AS page_status
           FROM `snapshots` s
           JOIN `saved_pages` p ON p.id = s.page_id
          WHERE s.id = ?
          LIMIT 1'
    );
    $stmt->execute([$snapshotId]);
    $row = $stmt->fetch();

    if ($row === false || (string) $row['fetch_status'] !== 'success') {
        return ['status' => 'not_found'];
    }

    if ((string) ($row['page_status'] ?? 'active') === 'deleted') {
        return ['status' => 'not_found'];
    }

    // Khách: require_login() đã chuyển hướng, nhưng để phòng thủ vẫn trả not_found.
    if ($actor === null) {
        return ['status' => 'not_found'];
    }

    $pageId    = (int) $row['page_id'];
    $libraryId = (int) $row['library_id'];

    // --- Bước 2: nạp đầy đủ bài + kiểm tra truy cập ở tầng SQL (wa_find_snapshot).
    $article = wa_find_snapshot($snapshotId, $actor);

    if ($article === null) {
        // Không nạp được dù có quyền (chặn bởi access sql) hoặc dữ liệu lệch.
        return wa_can_read_library($libraryId, $actor)
            ? ['status' => 'not_found']
            : ['status' => 'denied'];
    }

    // --- Bước 3: vai trò người dùng trong thư viện + quyền tải lại.
    $role = wa_library_role($libraryId, $actor);

    $canRefetch = $actor['role'] === 'admin' || in_array($role, ['owner', 'editor'], true);

    // --- Bước 4: phiên bản + ghi chú của ĐÚNG snapshot đang mở.
    $versions = wa_page_versions($pageId, $snapshotId);
    $neighbours = wa_page_neighbour_versions($pageId, $snapshotId);

    return [
        'status'       => 'ok',
        'article'      => $article,
        'library_id'   => $libraryId,
        'page_id'      => $pageId,
        'role'         => $role,
        'can_refetch'  => $canRefetch,
        'versions'     => $versions,
        'prev'         => $neighbours['prev'],
        'next'         => $neighbours['next'],
        'notes'        => wa_notes_for_snapshot($snapshotId),
    ];
}

/**
 * Vai trò của người dùng trong một thư viện: owner | editor | viewer | null.
 *
 * Chủ sở hữu (libraries.owner_id) luôn là 'owner' kể cả khi không có dòng
 * trong library_members.
 */
function wa_library_role(int $libraryId, ?array $actor = null): ?string
{
    $actor = $actor ?? wa_actor();

    if ($actor === null) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT l.owner_id, lm.role
           FROM `libraries` l
           LEFT JOIN `library_members` lm ON lm.library_id = l.id AND lm.user_id = :actor
          WHERE l.id = :library_id
          LIMIT 1'
    );
    $stmt->execute([
        ':actor'      => (int) $actor['id'],
        ':library_id' => $libraryId,
    ]);

    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    if ((int) $row['owner_id'] === (int) $actor['id']) {
        return 'owner';
    }

    return $row['role'] !== null ? (string) $row['role'] : null;
}

/* =============================================================================
    XUẤT KHO ĐỘC LẬP — P-LIB
    -----------------------------------------------------------------------------
    Các hàm dựng tệp HTML tải xuống được cho public/export.php. Ba nguyên tắc:

      1. MỌI ảnh cục bộ (/uploads/snapshots/<id>/…) được NHÚNG thẳng thành
         data URI base64, MIME nhận diện bằng finfo từ nội dung thật của tệp.
      2. Tệp xuất KHÔNG được tham chiếu tài nguyên ngoài: không <img src="http…">,
         không <link rel="stylesheet">, không <script src>. Liên kết bài viết bên
         trong thân vẫn là http(s) tuyệt đối — đó là nội dung, không phải tải về.
      3. Có đầu nguồn gốc (provenance) đầy đủ để tệp tự giải thích nguồn gốc.
    ============================================================================= */

/** Kích thước tối đa của tệp xuất (phòng vệ: 64 MB). */
const WA_EXPORT_MAX_BYTES = 64 * 1024 * 1024;

/**
 * Tên tệp tải xuống: <slug-tiêu-đề>-v<version_no>.html
 *
 * Dùng CHUNG hàm bỏ dấu của dự án (wa_slugify) nên tiêu đề tiếng Việt ra slug
 * tiếng Việt không dấu, đúng kiểu tên tệp mà người dùng mong đợi.
 */
function wa_export_filename(string $title, int $versionNo): string
{
    $slug = wa_slugify($title);

    return $slug . '-v' . $versionNo . '.html';
}

/**
 * Chuyển MỌI đường dẫn ảnh cục bộ trong content_html thành data URI base64.
 *
 * - Xét MỌI `src` BẮT ĐẦU bằng `/` (đường dẫn nội bộ của ứng dụng), không chỉ
 *   `/uploads/`: ảnh trong bài có thể trỏ tới `/assets/img/…` (sơ đồ của chính
 *   ứng dụng) và cũng phải được nhúng để tệp mở được ngoại tuyến.
 * - Đọc tệp từ WA_PUBLIC + đường dẫn, kiểm tra không vượt ra ngoài WA_PUBLIC
 *   (chặn ../), nhận diện MIME bằng finfo từ bytes thật.
 * - Nếu đọc tệp thất bại thì GỠ luôn thẻ <img> — không bao giờ để lọt
 *   `src="/uploads/…"` trong tệp xuất, vì như vậy tệp không còn độc lập.
 * - Ảnh http(s) bên ngoài (không nên có sau bước tải ảnh) cũng bị gỡ.
 *
 * @return string HTML đã nhúng ảnh
 */
function wa_export_inline_images(string $contentHtml): string
{
    if ($contentHtml === '') {
        return '';
    }

    $doc = wa_capture_dom('<html><body>' . $contentHtml . '</body></html>');

    if ($doc === null) {
        return $contentHtml;
    }

    $nodes = (new DOMXPath($doc))->query('//img');

    if ($nodes === false) {
        return $contentHtml;
    }

    $publicRoot = realpath(WA_PUBLIC);

    foreach (iterator_to_array($nodes) as $img) {
        if (!$img instanceof DOMElement || $img->parentNode === null) {
            continue;
        }

        $src = trim($img->getAttribute('src'));

        if ($src === '') {
            $img->parentNode->removeChild($img);
            continue;
        }

        // FIX-01: src dạng img.php?id=..&f=.. (mới) hoặc /uploads/.. (cũ) đều
        // nhúng được. Hàm resolve tự chặn traversal và kiểm tra MIME.
        $dataUri = wa_export_resolve_image_data_uri($src, $publicRoot === false ? null : $publicRoot);

        if ($dataUri === null) {
            // Không đọc được tệp -> gỡ ảnh để tệp xuất luôn độc lập.
            $img->parentNode->removeChild($img);
            continue;
        }

        $img->setAttribute('src', $dataUri);
    }

    $body = $doc->getElementsByTagName('body')->item(0);

    if ($body === null) {
        return $contentHtml;
    }

    $html = (string) $doc->saveHTML($body);
    $html = (string) preg_replace('#^<body[^>]*>#i', '', $html);
    $html = (string) preg_replace('#</body>$#i', '', $html);

    return trim($html);
}

/**
 * FIX-01: resolve mọi dạng src ảnh nội bộ thành data URI.
 *
 * Chấp nhận:
 *   - `img.php?id=<snap>&f=<file>` (mới, có xác thực lúc hiển thị);
 *   - `/uploads/snapshots/<id>/<file>` (cũ, tương thích snapshot đã lưu);
 *   - `/assets/...` (tài nguyên tĩnh của ứng dụng).
 * Từ chối: http(s) ngoài, data:, src lạ. Chặn traversal + kiểm tra MIME.
 */
function wa_export_resolve_image_data_uri(string $src, ?string $publicRoot): ?string
{
    $src = trim($src);

    if ($src === '') {
        return null;
    }

    // Dạng mới: img.php?id=..&f=..
    if (preg_match('#^img\.php\?([^#]*)$#', $src, $m) === 1) {
        parse_str($m[1], $q);

        $snapId = (int) ($q['id'] ?? 0);
        $file = (string) ($q['f'] ?? '');

        if ($snapId <= 0) {
            return null;
        }

        $base = basename($file);

        if ($base === '' || $base !== $file || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,90}\.(jpg|jpeg|png|gif|webp)$/i', $base)) {
            return null;
        }

        return wa_export_read_image_data_uri('/uploads/snapshots/' . $snapId . '/' . $base, $publicRoot);
    }

    // Dạng cũ + assets: đường dẫn nội bộ bắt đầu bằng "/".
    if (!str_starts_with($src, '/')) {
        return null;
    }

    return wa_export_read_image_data_uri($src, $publicRoot);
}

/**
 * Đọc một ảnh cục bộ và trả về data URI base64 ("data:<mime>;base64,…").
 *
 * `$publicRoot` là đường dẫn thật của public/ dùng để chặn đường dẫn bay ra
 * ngoài (..\/..\/etc\/passwd). Trả null khi không đọc được hoặc không phải ảnh.
 */
function wa_export_read_image_data_uri(string $webPath, ?string $publicRoot): ?string
{
    $relative = ltrim(substr($webPath, 1), '/');

    if ($relative === '' || str_contains($relative, "\0")) {
        return null;
    }

    $fullPath = realpath(WA_PUBLIC . '/' . $relative);

    if ($fullPath === false || !is_file($fullPath)) {
        return null;
    }

    // Chặn đường dẫn thoát khỏi public/.
    if ($publicRoot !== null && !str_starts_with(str_replace('\\', '/', $fullPath), str_replace('\\', '/', $publicRoot) . '/')) {
        return null;
    }

    $size = @filesize($fullPath);

    if ($size === false || $size <= 0 || $size > WA_EXPORT_MAX_BYTES) {
        return null;
    }

    $bytes = @file_get_contents($fullPath);

    if ($bytes === false || $bytes === '') {
        return null;
    }

    // MIME nhận diện từ NỘI DUNG THẬT (finfo), không tin phần mở rộng tệp.
    $mime = '';

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo !== false) {
            $detected = finfo_buffer($finfo, $bytes);
            finfo_close($finfo);

            if (is_string($detected)) {
                $mime = strtolower(trim($detected));
            }
        }
    }

    // Chỉ nhận 4 loại ảnh raster — SVG không được nhúng vì có thể chứa mã thực thi.
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        return null;
    }

    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

/**
 * Tệp HTML đã dựng có thật sự ĐỘC LẬP không?
 *
 * Kiểm tra bằng DOM (KHÔNG phải regex trên chuỗi): regex sẽ báo nhầm khi nội
 * dung bài chứa HTML đã escape — đó chỉ là TEXT trong bài, không phải tài nguyên
 * được tải.
 *   - không <img src="http://…" / "https://…"
 *   - không còn đường dẫn cục bộ trong thuộc tính tải tài nguyên
 *   - không <link> stylesheet/script, không <script src>, không <iframe>
 *
 * Liên kết <a href="http…"> là NỘI DUNG, không phải tài nguyên tải về nên vẫn
 * được giữ nguyên — đúng như yêu cầu của đặc tả.
 */
function wa_export_html_is_standalone(string $html): bool
{
    if ($html === '') {
        return false;
    }

    $doc = wa_capture_dom('<html><body>' . $html . '</body></html>');

    if ($doc === null) {
        return false;
    }

    $xpath = new DOMXPath($doc);

    // 1) Anh: moi src phai la data URI.
    $imgs = $xpath->query('//img');

    if ($imgs !== false) {
        foreach ($imgs as $img) {
            if (!$img instanceof DOMElement) {
                continue;
            }

            if (!str_starts_with(trim($img->getAttribute('src')), 'data:')) {
                return false;
            }
        }
    }

    // 2) Moi thuoc tinh tai tai nguyen khac (poster, data) cung phai la data URI
    //    hoac rong — neu khong, tep van can tai nguyen ngoai.
    $els = $xpath->query('//*[@src or @poster or @data]');

    if ($els !== false) {
        foreach ($els as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }

            foreach (['src', 'poster', 'data'] as $attr) {
                $value = trim($el->getAttribute($attr));

                if ($value !== '' && !str_starts_with($value, 'data:')) {
                    return false;
                }
            }
        }
    }

    // 3) Tai nguyen ngoai: stylesheet / script / iframe / object / embed.
    foreach (['link', 'script', 'iframe', 'object', 'embed', 'audio', 'video', 'source'] as $tag) {
        $found = $xpath->query('//' . $tag);

        if ($found !== false && $found->length > 0) {
            return false;
        }
    }

    // 4) @import trong CSS.
    if (preg_match('/@import\b/i', $html) === 1) {
        return false;
    }

    return true;
}

/**
 * Dựng toàn bộ tệp HTML độc lập: đầu nguồn gốc + <style> tối giản + nội dung.
 *
 * @param array<string,mixed>  $article Dòng từ wa_find_snapshot().
 * @param array{exported_by:string, exported_at:string, role:string, body_html:string} $meta
 */
function wa_export_build_document(array $article, array $meta): string
{
    $title        = (string) $article['title'];
    $sourceUrl    = (string) $article['source_url'];
    $libraryName  = (string) $article['library_name'];
    $librarySlug  = (string) $article['library_slug'];
    $versionNo    = (int) $article['version_no'];
    $fetchedAt    = (string) $article['fetched_at'];
    $snapshotId   = (int) $article['id'];

    $fetchedLabel  = wa_datetime($fetchedAt);
    $exportedLabel = wa_datetime((string) $meta['exported_at']);

    $titleRows = '';

    if (is_array($article['tags'] ?? null)) {
        foreach ($article['tags'] as $tag) {
            $titleRows .= '<span class="pill">' . wa_e((string) $tag) . '</span>';
        }
    }

    return '<!DOCTYPE html>' . "\n"
        . '<html lang="vi">' . "\n"
        . '<head>' . "\n"
        . '<meta charset="utf-8">' . "\n"
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n"
        . '<title>' . wa_e($title) . ' — bản lưu ngoại tuyến (v' . $versionNo . ')</title>' . "\n"
        . '<meta name="generator" content="WebArchive">' . "\n"
        . '<meta name="wa-snapshot-id" content="SNAP-' . $snapshotId . '">' . "\n"
        . '<meta name="wa-version" content="' . $versionNo . '">' . "\n"
        . '<meta name="wa-source-url" content="' . wa_e($sourceUrl) . '">' . "\n"
        . '<meta name="wa-fetched-at" content="' . wa_e($fetchedAt) . '">' . "\n"
        . '<meta name="wa-exported-at" content="' . wa_e((string) $meta['exported_at']) . '">' . "\n"
        . wa_export_inline_style() . "\n"
        . '</head>' . "\n"
        . '<body>' . "\n"
        . wa_export_provenance_header([
            'title'           => $title,
            'source_url'      => $sourceUrl,
            'library_name'    => $libraryName,
            'library_slug'    => $librarySlug,
            'version_no'      => $versionNo,
            'snapshot_id'     => $snapshotId,
            'fetched_at'      => $fetchedAt,
            'fetched_label'   => $fetchedLabel,
            'exported_by'     => (string) $meta['exported_by'],
            'exported_at'     => (string) $meta['exported_at'],
            'exported_label'  => $exportedLabel,
            'role'            => (string) $meta['role'],
            'tags'            => (string) $article['tags'][0] ?? '',
            'tags_html'       => $titleRows,
        ]) . "\n"
        . '<main class="article">' . "\n"
        . '<h1>' . wa_e($title) . '</h1>' . "\n"
        . (string) $meta['body_html'] . "\n"
        . '</main>' . "\n"
        . '<footer class="foot">'
        . 'Bản lưu ngoại tuyến của WebArchive · ' . wa_e($libraryName)
        . ' · Phiên bản ' . $versionNo . ' · Xuất lúc ' . wa_e($exportedLabel)
        . '</footer>' . "\n"
        . '</body>' . "\n"
        . '</html>' . "\n";
}

/**
 * Đầu nguồn gốc (provenance) — khối thông tin bắt buộc ở đầu tệp xuất.
 *
 * Tất cả giá trị đều được escape; tệp này đọc ngoại tuyến nên không được phép
 * có bất kỳ tham chiếu tài nguyên ngoài nào (kể cả logo hay stylesheet).
 *
 * @param array<string,string> $d
 */
function wa_export_provenance_header(array $d): string
{
    return '<header class="prov">' . "\n"
        . '  <p class="prov__kicker">Bản lưu ngoại tuyến — WebArchive</p>' . "\n"
        . '  <dl class="prov__grid">' . "\n"
        . wa_export_row('Tiêu đề bài viết', wa_e($d['title'])) . "\n"
        . wa_export_row('URL nguồn', '<a href="' . wa_e($d['source_url']) . '" rel="noopener noreferrer">' . wa_e($d['source_url']) . '</a>') . "\n"
        . wa_export_row('Thư viện', wa_e($d['library_name']) . ' <span class="prov__dim">(library.php?slug=' . wa_e($d['library_slug']) . ')</span>') . "\n"
        . wa_export_row('Phiên bản', '<strong>Phiên bản ' . (int) $d['version_no'] . '</strong> · SNAP-' . (int) $d['snapshot_id']) . "\n"
        . wa_export_row('Thời điểm tải', wa_e($d['fetched_label']) . ' <span class="prov__dim">(' . wa_e($d['fetched_at']) . ')</span>') . "\n"
        . wa_export_row('Người xuất', wa_e($d['exported_by']) . ' · ' . wa_e($d['role']) . ' · ' . wa_e($d['exported_label'])) . "\n"
        . '  </dl>' . "\n"
        . '  <p class="prov__note">Tệp này đã nhúng toàn bộ hình ảnh dưới dạng dữ liệu nhúng (base64) nên mở được hoàn toàn ngoại tuyến.</p>' . "\n"
        . '</header>';
}

/**
 * Một dòng <dt>/<dd> của khối đầu nguồn gốc. $value đã escape sẵn từ nơi gọi.
 */
function wa_export_row(string $key, string $value): string
{
    return '    <div class="prov__row"><dt>' . wa_e($key) . '</dt><dd>' . $value . '</dd></div>';
}

/**
 * Khối CSS TỐI GIẢN nằm INLINE trong <head> — không dùng stylesheet ngoài.
 *
 * Chỉ dùng phông hệ thống (không tải Google Fonts) để tệp không cần mạng.
 */
function wa_export_inline_style(): string
{
    return '<style>' . "\n"
        . '  :root { color-scheme: light; }' . "\n"
        . '  * { box-sizing: border-box; }' . "\n"
        . '  body { margin: 0; padding: 32px 16px 64px; background: #F5F7F9; color: #1F2937;'
        . ' font: 16px/1.75 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }' . "\n"
        . '  .prov { max-width: 780px; margin: 0 auto 28px; padding: 18px 20px; background: #fff;'
        . ' border: 1px solid #E2E8F0; border-radius: 12px; }' . "\n"
        . '  .prov__kicker { margin: 0 0 12px; font-size: 11px; font-weight: 700; letter-spacing: .09em;'
        . ' text-transform: uppercase; color: #289C91; }' . "\n"
        . '  .prov__grid { margin: 0; display: grid; gap: 8px; }' . "\n"
        . '  .prov__row { display: grid; grid-template-columns: 150px 1fr; gap: 12px; font-size: 14px; }' . "\n"
        . '  .prov__row dt { margin: 0; color: #74777E; font-weight: 600; }' . "\n"
        . '  .prov__row dd { margin: 0; word-break: break-word; }' . "\n"
        . '  .prov__dim { color: #74777E; font-size: 12px; }' . "\n"
        . '  .prov__note { margin: 14px 0 0; padding-top: 12px; border-top: 1px solid #E2E8F0;'
        . ' font-size: 13px; color: #74777E; }' . "\n"
        . '  .article { max-width: 780px; margin: 0 auto; padding: 8px 4px; }' . "\n"
        . '  .article h1 { margin: 0 0 18px; font-size: 30px; line-height: 1.3; color: #152F4A; }' . "\n"
        . '  .article h2 { margin: 28px 0 10px; font-size: 22px; line-height: 1.35; color: #152F4A; }' . "\n"
        . '  .article h3 { margin: 22px 0 8px; font-size: 18px; color: #24476B; }' . "\n"
        . '  .article p, .article li { margin: 0 0 14px; }' . "\n"
        . '  .article img { max-width: 100%; height: auto; margin: 8px 0 14px; border-radius: 8px; }' . "\n"
        . '  .article figure { margin: 20px 0; }' . "\n"
        . '  .article figcaption { font-size: 13px; color: #74777E; }' . "\n"
        . '  .article blockquote { margin: 18px 0; padding: 10px 16px; border-left: 3px solid #315E91;'
        . ' background: #FAFBFD; color: #24476B; font-style: italic; }' . "\n"
        . '  .article pre { overflow-x: auto; padding: 14px; border-radius: 8px; background: #101F30;'
        . ' color: #D7E3F2; font-size: 13px; }' . "\n"
        . '  .article table { width: 100%; border-collapse: collapse; margin: 18px 0; font-size: 14px; }' . "\n"
        . '  .article th, .article td { padding: 8px 10px; border: 1px solid #E2E8F0; text-align: left; }' . "\n"
        . '  .article a { color: #315E91; }' . "\n"
        . '  .foot { max-width: 780px; margin: 32px auto 0; padding-top: 14px;'
        . ' border-top: 1px solid #E2E8F0; font-size: 12px; color: #74777E; }' . "\n"
        . '</style>';
}
