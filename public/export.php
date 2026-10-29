<?php
/**
 * export.php — XUẤT KHO MỘT BẢN SNAPSHOT RA TỆP HTML ĐỘC LẬP (P-LIB).
 *
 * GET export.php?id=<snapshot id>
 *
 * Trang này KHÔNG vẽ giao diện của ứng dụng: nó trả về một TỆP HTML tải xuống
 * được (Content-Disposition: attachment) mở được hoàn toàn ngoại tuyến — không
 * cần máy chủ, không cần mạng.
 *
 * ── YÊU CẦU PHÂN QUYỀN (giống hệt trình đọc) ─────────────────────────────────
 *   - Khách                -> chuyển hướng login.php (require_login).
 *   - Đã đăng nhập, không phải thành viên thư viện chứa snapshot
 *                          -> trang 403 tiếng Việt.
 *   - Snapshot không tồn tại / không phải bản thành công -> 404.
 *
 * ── NỘI DUNG TỆP XUẤT ───────────────────────────────────────────────────────
 *   1. ĐẦU NGUỒN GỐC (provenance): tiêu đề bài, URL nguồn, tên thư viện,
 *      "Phiên bản N", thời điểm tải (fetched_at), người xuất + thời điểm xuất.
 *   2. NỘI DUNG BÀI (content_html) với MỌI ảnh cục bộ (/uploads/...) được NHÚNG
 *      thẳng dưới dạng data URI base64 — đọc tệp và nhận diện MIME bằng finfo.
 *   3. Một khối <style> tối giản INLINE để tệp vẫn dễ đọc.
 *
 * ── ĐẢM BẢO OFFLINE THẬT SỰ ─────────────────────────────────────────────────
 *   Tệp xuất KHÔNG chứa tham chiếu tài nguyên ngoài nào: không <img src="http…">,
 *   không <link rel="stylesheet">, không <script src>. Liên kết bài viết bên
 *   trong thân vẫn giữ dạng http(s) tuyệt đối vì đó là NỘI DUNG chứ không phải
 *   tài nguyên được tải. Hàm wa_export_html_is_standalone() tự kiểm lại điều này
 *   sau khi dựng xong; nếu lọt bất kỳ tham chiếu ngoài nào thì không xuất.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

$snapshotId = (int) ($_GET['id'] ?? 0);

/** Nhãn tiếng Việt của vai trò trong thư viện (dùng cho dòng "Người xuất"). */
$roleLabels = wa_member_role_labels();

/* ---------------------------------------------------------------------------
 * 404 — không có id, không tồn tại, hoặc không phải bản lưu THÀNH CÔNG.
 * ------------------------------------------------------------------------- */
if ($snapshotId <= 0) {
    http_response_code(404);
    $pageTitle = 'Không tìm thấy bản lưu';
    require __DIR__ . '/../templates/header_app.php';
    ?>
    <div class="empty">
      <span class="ms ms-32">search_off</span>
      <h3>Không tìm thấy bản lưu</h3>
      <p>Không có bản lưu nào với mã này để xuất kho.</p>
      <div class="row" style="justify-content:center;margin-top:16px">
        <a class="btn btn--primary" href="<?= wa_e(wa_url('libraries.php')) ?>">Về thư viện của tôi</a>
      </div>
    </div>
    <?php
    require __DIR__ . '/../templates/footer_app.php';
    exit;
}

/* ---------------------------------------------------------------------------
 * Nạp bản snapshot + kiểm tra truy cập theo đúng quy tắc của trình đọc.
 *
 * Phân biệt rõ hai trường hợp (sau khi require_login nên chắc chắn đã đăng nhập):
 *   - snapshot tồn tại và người dùng KHÔNG thuộc thư viện  -> 403 tiếng Việt.
 *   - snapshot không tồn tại, không phải bản thành công,
 *     hoặc người dùng không đủ quyền đọc                  -> 404.
 * ------------------------------------------------------------------------- */
$article = wa_find_snapshot($snapshotId);

if ($article === null) {
    $scope = wa_snapshot_scope($snapshotId);

    if ($scope !== null && !wa_can_read_library($scope['library_id'])) {
        http_response_code(403);
        $waDeniedContext = [
            'title' => 'Bản lưu này thuộc thư viện khác',
            'intro' => 'Bản lưu bạn muốn xuất nằm trong một thư viện mà bạn không phải là thành viên. '
                     . 'Chỉ chủ sở hữu, biên tập viên, người xem của thư viện đó hoặc quản trị viên mới xuất được.',
        ];
        require __DIR__ . '/../templates/error_no_permission.php';
        exit;
    }

    // Bản ghi lỗi (fetch_status <> 'success') không bao giờ xuất được — cũng 404.
    http_response_code(404);
    $pageTitle = 'Không tìm thấy bản lưu';
    require __DIR__ . '/../templates/header_app.php';
    ?>
    <div class="empty">
      <span class="ms ms-32">search_off</span>
      <h3>Không tìm thấy bản lưu</h3>
      <p>Bản lưu này không tồn tại, không phải bản lưu thành công, hoặc bạn không có quyền truy cập.</p>
      <div class="row" style="justify-content:center;margin-top:16px">
        <a class="btn btn--primary" href="<?= wa_e(wa_url('libraries.php')) ?>">Về thư viện của tôi</a>
      </div>
    </div>
    <?php
    require __DIR__ . '/../templates/footer_app.php';
    exit;
}

/* ---------------------------------------------------------------------------
 * Dựng tệp HTML độc lập.
 * ------------------------------------------------------------------------- */
$currentUser = current_user();
$role        = (string) (wa_library_role((int) $article['library_id']) ?? 'viewer');

$exportedAt  = date('Y-m-d H:i:s');
$filename    = wa_export_filename((string) $article['title'], (int) $article['version_no']);
$bodyHtml    = wa_export_inline_images((string) $article['content_html']);

$document    = wa_export_build_document($article, [
    'exported_by' => (string) ($currentUser['name'] ?? 'Không rõ'),
    'exported_at' => $exportedAt,
    'role'        => $roleLabels[$role] ?? $role,
    'body_html'   => $bodyHtml,
]);

/* ---------------------------------------------------------------------------
 * Chặn cuối: chỉ xuất khi tệp thật sự độc lập (không còn tài nguyên ngoài).
 * ------------------------------------------------------------------------- */
if (!wa_export_html_is_standalone($document)) {
    http_response_code(500);
    $pageTitle = 'Không xuất được';
    require __DIR__ . '/../templates/header_app.php';
    ?>
    <div class="empty">
      <span class="ms ms-32">error_outline</span>
      <h3>Không xuất được bản lưu này</h3>
      <p>Nội dung bài vẫn còn tham chiếu tài nguyên ngoài, nên không thể tạo tệp độc lập.</p>
    </div>
    <?php
    require __DIR__ . '/../templates/footer_app.php';
    exit;
}

/* ---------------------------------------------------------------------------
 * Trả về tệp tải xuống.
 * ------------------------------------------------------------------------- */
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($document));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

echo $document;