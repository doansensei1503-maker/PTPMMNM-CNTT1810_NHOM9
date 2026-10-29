<?php
/**
 * library_action.php — Điểm nhận DUY NHẤT cho mọi hành động quản lý thư viện
 * (P-LIB): tạo thư viện, đổi tên / đổi hiển thị, xoá thư viện, thêm / đổi vai
 * trò / gỡ thành viên, và sửa nhãn của một trang đã lưu.
 *
 * Trang này KHÔNG vẽ giao diện: nó chỉ nhận POST, kiểm tra bảo mật rồi chuyển
 * hướng lại đúng trang đã gọi, kèm thông báo flash tiếng Việt. Tách kiểu này
 * giống hệt `refetch.php` và `note.php` của P-READ.
 *
 * THỨ TỰ KIỂM TRA (bắt buộc, giống mọi POST khác của dự án):
 *   1. require_login()                 — khách -> chuyển về login.php.
 *   2. wa_csrf_check()                  — token sai -> từ chối, KHÔNG ghi gì.
 *   3. Phân quyền trong chính hàm gọi   — được ép lẦN NỮA trong SQL.
 *
 * Quyền: mọi hành động ngoài "tạo thư viện" đều cần quyền quản lý thư viện
 * (chủ sở hữu hoặc quản trị viên). Người gửi POST trực tiếp (không qua giao
 * diện) mà không có quyền vẫn bị từ chối — và nếu họ không thuộc thư viện thì
 * nhận trang 403 tiếng Việt.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$action = (string) ($_POST['action'] ?? '');

/* ---------------------------------------------------------------------------
 * Chỉ nhận POST. GET vô nghĩa — đưa về trang phù hợp.
 * ------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . wa_url($action === 'create' ? 'libraries.php' : 'libraries.php'));
    exit;
}

/** Chuyển hướng về `libraries.php` kèm thông báo flash. */
$backToList = static function (array $flash): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('libraries.php'));
    exit;
};

/** Chuyển hướng về trang chi tiết của một thư viện kèm thông báo flash. */
$backToLibrary = static function (string $slug, array $flash): never {
    $_SESSION['wa_flash'] = $flash;
    header('Location: ' . wa_url('library.php?slug=' . urlencode($slug)));
    exit;
};

/**
 * Người gọi có quyền quản lý thư viện không — nếu KHÔNG thì trả 403 tiếng Việt.
 *
 * Trả về slug để nơi gọi chuyển hướng. `http_response_code(403)` + template là
 * đúng như yêu cầu "direct POSTs từ người không phải chủ sở hữu bị từ chối bằng
 * trang 403 tiếng Việt".
 */
$requireManager = static function (int $libraryId): string {
    $slug = '';

    if (wa_can_read_library($libraryId)) {
        $row = wa_library_by_id($libraryId);
        $slug = (string) ($row['slug'] ?? '');
    }

    if (!wa_can_manage_library($libraryId)) {
        http_response_code(403);
        $waDeniedContext = [
            'title' => 'Không có quyền quản lý thư viện này',
            'intro' => 'Chỉ chủ sở hữu của thư viện (hoặc quản trị viên hệ thống) mới đổi tên, '
                     . 'đổi mức hiển thị, xoá thư viện, quản lý thành viên hoặc sửa nhãn bài viết. '
                     . 'Tài khoản của bạn không có quyền này.',
        ];
        require __DIR__ . '/../templates/error_no_permission.php';
        exit;
    }

    return $slug;
};

/* ---------------------------------------------------------------------------
 * CSRF — kiểm tra TRƯỚC khi đọc/ghi bất cứ thứ gì vào CSDL.
 * ------------------------------------------------------------------------- */
if (!wa_csrf_check()) {
    $backToList([
        'type' => 'error',
        'text' => 'Yêu cầu không hợp lệ hoặc phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.',
    ]);
}

$user   = current_user();
$userId = (int) ($user['id'] ?? 0);

/* ===========================================================================
 * 1) TẠO THƯ VIỆN — libraries.php
 * ========================================================================= */
if ($action === 'create') {
    $result = wa_library_create(
        trim((string) ($_POST['name'] ?? '')),
        trim((string) ($_POST['description'] ?? '')),
        (string) ($_POST['visibility'] ?? 'private'),
        (string) ($_POST['color'] ?? 'a'),
        (string) ($_POST['icon'] ?? 'folder_special'),
        $userId
    );

    if ($result['ok'] === true) {
        // Thông báo thành công bằng tiếng Việt rồi mở thẳng trang chi tiết
        // thư viện vừa tạo.
        $_SESSION['wa_flash'] = [
            'type' => 'success',
            'text' => (string) $result['message'],
        ];

        header('Location: ' . wa_url('library.php?slug=' . urlencode((string) $result['slug'])));
        exit;
    }

    // Lỗi: quay lại modal tạo thư viện kèm thông báo.
    $backToList(['type' => 'error', 'text' => (string) $result['message']]);
}

/* ===========================================================================
 * 2) ĐỔI TÊN + ĐỔI MỨC HIỂN THỊ — library.php (chủ sở hữu / quản trị viên)
 * ========================================================================= */
if ($action === 'update') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);
    $slug      = $requireManager($libraryId);

    $result = wa_library_update(
        $libraryId,
        trim((string) ($_POST['name'] ?? '')),
        trim((string) ($_POST['description'] ?? '')),
        (string) ($_POST['visibility'] ?? 'private')
    );

    // Sau khi đổi tên, slug có thể đã đổi theo -> dùng slug MỚI trả về.
    $targetSlug = (string) ($result['slug'] ?? $slug);

    $backToLibrary($targetSlug !== '' ? $targetSlug : $slug, [
        'type' => $result['ok'] === true ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * 3) XOÁ THƯ VIỆN — CHỈ CHỦ SỞ HỮU, có bước xác nhận riêng (confirm=1)
 *    Xoá cả dòng CSDL lẫn thư mục ảnh public/uploads/snapshots/<id>/.
 * ========================================================================= */
if ($action === 'delete') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);
    $slug      = $requireManager($libraryId);

    $row = wa_library_by_id($libraryId);

    if ($row === null) {
        $backToList(['type' => 'error', 'text' => 'Không tìm thấy thư viện cần xoá.']);
    }

    // BƯỚC XÁC NHẬN RÕ RÀNG: bấm nút lần đầu chỉ hiện câu hỏi xác nhận
    // (client-side). Lần POST thứ hai phải kèm confirm=1 mới xoá thật —
    // nên không thể vô tình xoá nhầm thư viện chỉ với một cú bấm.
    if ((string) ($_POST['confirm'] ?? '') !== '1') {
        $backToLibrary((string) $row['slug'], [
            'type' => 'error',
            'text' => 'Chưa xác nhận xoá. Bấm “Xoá thư viện” một lần nữa và xác nhận để tiếp tục.',
        ]);
    }

    $result = wa_library_delete($libraryId);

    if ($result['ok'] === true) {
        // Thư viện đã mất -> về danh sách kèm thông báo (nêu cả phần dọn ảnh).
        $backToList([
            'type' => 'success',
            'text' => (string) $result['message']
                . ' Đã dọn ' . (int) $result['dirs'] . ' thư mục ảnh ('
                . (int) $result['files'] . ' tệp) trên máy chủ.',
        ]);
    }

    // Không xoá được (ví dụ không phải chủ sở hữu) -> quay lại trang thư viện.
    $backToLibrary((string) $row['slug'], [
        'type' => 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * 4) THÊM THÀNH VIÊN THEO EMAIL — vai trò editor hoặc viewer
 * ========================================================================= */
if ($action === 'member_add') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);
    $slug      = $requireManager($libraryId);

    $result = wa_member_add(
        $libraryId,
        trim((string) ($_POST['email'] ?? '')),
        (string) ($_POST['role'] ?? 'viewer')
    );

    $backToLibrary($slug, [
        'type' => $result['ok'] === true ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * 5) ĐỔI VAI TRÒ THÀNH VIÊN — editor <-> viewer (dòng owner bị chặn trong SQL)
 * ========================================================================= */
if ($action === 'member_role') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);
    $slug      = $requireManager($libraryId);

    $result = wa_member_update_role(
        $libraryId,
        (int) ($_POST['user_id'] ?? 0),
        (string) ($_POST['role'] ?? 'viewer')
    );

    $backToLibrary($slug, [
        'type' => $result['ok'] === true ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * 6) GỠ THÀNH VIÊN (dòng owner bị chặn trong SQL)
 * ========================================================================= */
if ($action === 'member_remove') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);
    $slug      = $requireManager($libraryId);

    $result = wa_member_remove($libraryId, (int) ($_POST['user_id'] ?? 0));

    $backToLibrary($slug, [
        'type' => $result['ok'] === true ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * 7) SỬA NHÃN CỦA MỘT TRANG ĐÃ LƯU (thay toàn bộ bộ nhãn của trang đó)
 * ========================================================================= */
if ($action === 'tags') {
    $libraryId = (int) ($_POST['library_id'] ?? 0);
    $slug      = $requireManager($libraryId);

    $pageId = (int) ($_POST['page_id'] ?? 0);

    // Tách chuỗi dấu phẩy rồi chuẩn hoá tên + bỏ dấu # thừa.
    $result = wa_page_tags_replace(
        $pageId,
        wa_parse_tag_input((string) ($_POST['tags'] ?? ''))
    );

    $backToLibrary($slug, [
        'type' => $result['ok'] === true ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * 8) XOÁ MỀM MỘT BÀI VIẾT ĐÃ LƯU (status = 'deleted', giữ snapshots + notes)
 *
 * Phân quyền: chủ sở hữu / biên tập viên / quản trị viên (wa_page_soft_delete
 * ép trong SQL). Viewer gửi POST thẳng bằng tay bị từ chối — và nhận 403 nếu
 * họ không thuộc thư viện.
 *
 * Chống gửi trùng: bài đã xoá trả thông báo thành công idempotent, không lỗi SQL.
 * ========================================================================= */
if ($action === 'delete_page') {
    $pageId = (int) ($_POST['page_id'] ?? 0);

    if ($pageId <= 0) {
        $backToList(['type' => 'error', 'text' => 'Không xác định được bài viết cần xoá.']);
    }

    // Xác định thư viện chứa bài để chuyển hướng về đúng trang.
    $pageRow = db()->prepare('SELECT id, library_id FROM `saved_pages` WHERE id = ? LIMIT 1');

    try {
        $pageRow->execute([$pageId]);
        $page = $pageRow->fetch();
    } catch (Throwable $e) {
        $page = false;
    }

    if ($page === false) {
        $backToList(['type' => 'error', 'text' => 'Không tìm thấy bài viết cần xoá.']);
    }

    $libraryId = (int) $page['library_id'];

    // Người không xem được thư viện -> 403 tiếng Việt (giống $requireManager).
    if (!wa_can_read_library($libraryId)) {
        http_response_code(403);
        $waDeniedContext = [
            'title' => 'Không có quyền xoá bài viết này',
            'intro' => 'Chỉ chủ sở hữu, biên tập viên của thư viện (hoặc quản trị viên hệ thống) '
                     . 'mới xoá được bài viết. Tài khoản của bạn không có quyền này.',
        ];
        require __DIR__ . '/../templates/error_no_permission.php';
        exit;
    }

    $row  = wa_library_by_id($libraryId);
    $slug = (string) ($row['slug'] ?? '');

    $result = wa_page_soft_delete($pageId);

    $backToLibrary($slug, [
        'type' => $result['ok'] === true ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ===========================================================================
 * 9) KHÔI PHỤC MỘT BÀI VIẾT ĐÃ XOÁ MỀM (status về 'active')
 *
 * Cùng chính sách quyền như khi xoá. Bài không ở thùng rác trả thông báo
 * idempotent.
 * ========================================================================= */
if ($action === 'restore_page') {
    $pageId = (int) ($_POST['page_id'] ?? 0);

    if ($pageId <= 0) {
        $backToList(['type' => 'error', 'text' => 'Không xác định được bài viết cần khôi phục.']);
    }

    $pageRow = db()->prepare('SELECT id, library_id FROM `saved_pages` WHERE id = ? LIMIT 1');

    try {
        $pageRow->execute([$pageId]);
        $page = $pageRow->fetch();
    } catch (Throwable $e) {
        $page = false;
    }

    if ($page === false) {
        $backToList(['type' => 'error', 'text' => 'Không tìm thấy bài viết cần khôi phục.']);
    }

    $libraryId = (int) $page['library_id'];

    if (!wa_can_read_library($libraryId)) {
        http_response_code(403);
        $waDeniedContext = [
            'title' => 'Không có quyền khôi phục bài viết này',
            'intro' => 'Chỉ chủ sở hữu, biên tập viên của thư viện (hoặc quản trị viên hệ thống) '
                     . 'mới khôi phục được bài viết. Tài khoản của bạn không có quyền này.',
        ];
        require __DIR__ . '/../templates/error_no_permission.php';
        exit;
    }

    $row  = wa_library_by_id($libraryId);
    $slug = (string) ($row['slug'] ?? '');

    $result = wa_page_restore($pageId);

    $backToLibrary($slug, [
        'type' => $result['ok'] === true ? 'success' : 'error',
        'text' => (string) $result['message'],
    ]);
}

/* ---------------------------------------------------------------------------
 * Hành động không hợp lệ.
 * ------------------------------------------------------------------------- */
$backToList(['type' => 'error', 'text' => 'Hành động không hợp lệ.']);