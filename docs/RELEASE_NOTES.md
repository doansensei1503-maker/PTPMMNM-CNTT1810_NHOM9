# Ghi chú phát hành — WebArchive `v1.0.0`

> **Trạng thái phát hành:** thẻ `v1.0.0` đã được tạo (annotated) và đẩy lên
> `origin`. GitHub **Release** chưa được tạo tự động vì máy này không có `gh` đã đăng nhập
> — xem [“Phát hành còn lại cho ai”](#phát-hành-còn-lại-cho-ai) ở cuối tài liệu.

Đây là bản phát hành **đầu tiên** của WebArchive (Đề tài 16), đánh dấu trạng thái
**hoàn thành toàn bộ năm chức năng nghiệp vụ** của đề tài.

---

## 1. WebArchive là gì

Ứng dụng Web lưu nội dung (snapshot) của một trang để **đọc lại và tìm kiếm về sau**:
nội dung được tổ chức trong **thư viện** theo chủ đề, gắn **nhãn**, và có **phân quyền
riêng cho từng thư viện**.

- **PHP thuần** — không framework, không Composer, không bước build.
- Giao diện bằng **tiếng Việt**, một stylesheet cục bộ duy nhất.
- Lưu trữ: **MySQL** qua PDO, prepared statement, không đụng FULLTEXT.

---

## 2. Năm chức năng nghiệp vụ — trạng thái DONE

| # | Chức năng | Bằng chứng |
|---|-----------|-----------|
| 1 | **Thư viện, nhãn, phân quyền** | Tạo/đổi tên/xoá thư viện (xoá kèm thư mục ảnh), thêm–đổi vai trò–gỡ thành viên, sửa nhãn theo từng bài, allowlist tên miền trước mọi kết nối |
| 2 | **Tải HTML + bóc tách + phân biệt lỗi** | `capture_url()` thật (cURL + `DOMDocument`), **6 mã lỗi riêng biệt**: `error_invalid_url`, `error_blocked`, `error_404`, `error_http`, `error_timeout`, `error_no_content` |
| 3 | **Bản đọc lại sạch, ảnh lưu cục bộ** | Sanitizer whitelist tự viết; mọi ảnh tải về `public/uploads/snapshots/<id>/`, `src` đổi thành đường dẫn nội bộ |
| 4 | **Tìm kiếm tự xây** trên nội dung đã lưu | Chỉ mục đảo `search_terms`, **không** `LIKE`, **không** FULLTEXT; AND, bỏ dấu tiếng Việt, đoạn trích `<mark>`, lọc quyền ngay trong SQL |
| 5 | **Tải lại + ghi chú + xuất kho** | `refetch.php` tạo phiên bản mới giữ bản cũ; ghi chú gắn với **đúng một phiên bản**; `export.php` xuất HTML độc lập (ảnh base64) |

---

## 3. Những gì có trong bản phát hành này

### 3.1 Bộ dữ liệu mẫu — `tests/samples/`

15 trang mẫu tiếng Việt phục vụ bằng `php -S localhost:8091 -t tests/samples`, phủ đủ
trường hợp biên của engine: 13 bài viết (gồm cặp **trùng tiêu đề** `bai-01` / `bai-07`,
bài nhiều ảnh, bài bảng + danh sách, bài có `<script>`/`onclick`/`<iframe>`, trang
không có nội dung), trang chuyển hướng ra ngoài allowlist, trang chậm gây timeout, và 5
ảnh PNG sinh bằng tay (không cần GD). Bảng kê 15 mục nằm ở
[`tests/samples/README.md`](../tests/samples/README.md).

### 3.2 Nạp mẫu — `scripts/seed_samples.php`

Nạp 15 trang vào thư viện **“Kho dữ liệu mẫu”** của `user@demo.vn` qua **đường lưu trang
thật** `wa_store_page_capture()` — không chèn SQL tay. Chạy lần hai in `already seeded`
và thoát mã `0`.

### 3.3 Mười câu hỏi tìm kiếm — `docs/SEARCH_QUERIES.md`

Kỳ vọng được thiết kế **từ nội dung mẫu đã soạn và viết ra trước**, sau đó mới chạy qua
`public/search.php` bằng HTTP thật. **10/10 câu khớp mong đợi = thực tế.**

### 3.4 Sáu bài kiểm thử bắt buộc — `scripts/run_tests.php`

Tự bật hai máy chủ riêng (18080 / 18091), chạy sáu bài kiểm thử end-to-end qua HTTP thật,
tự dọn dữ liệu chỉ qua hành động của chính ứng dụng:

| Mã | Bài kiểm thử |
|----|-------------|
| `T1` | Nguồn đã chết — bản lưu vẫn đọc được kèm ảnh cục bộ |
| `T2` | Tìm theo thân — cụm chỉ có trong thân, có nhãn Phiên bản và `<mark>` |
| `T3` | Ba lỗi khác nhau — `error_404`, `error_timeout`, `error_blocked` |
| `T4` | Mã độc không chạy được — không còn `<script>`, `onclick`, `<iframe` |
| `T5` | Phiên bản + ghi chú sống sót — v1 giữ nguyên nội dung và ghi chú sau khi refetch |
| `T6` | Người ngoài không vào được — `reader`/`export` trả 403, chủ sở hữu trả 200 |

Chạy: `php scripts/run_tests.php` → **6/6 PASS, thoát mã 0.**

### 3.5 Gói mã nguồn mở

- [`LICENSE`](../LICENSE) — MIT, bên giữ bản quyền “the WebArchive contributors”.
- [`CONTRIBUTING.md`](../CONTRIBUTING.md) — cách cài, quy ước dự án, quy trình PR/issue.
- [`docs/DEMO.md`](DEMO.md) — hai kịch bản trình diễn chính thức, click-by-click.
- [`README.md`](../README.md) — tóm tắt sản phẩm, cài đặt từ đầu, bản đồ trang.

---

## 5. Ba defect thật do kiểm thử phát hiện

**Defect 1 — đoạn trích tìm kiếm lệch cửa sổ với tiếng Việt có dấu.** Bài `T2` làm lộ lỗi
trong `wa_search_snippet()`: `PREG_OFFSET_CAPTURE` trả về vị trí tính bằng **byte**, còn đoạn
trích cắt bằng `mb_substr()` (tính theo **ký tự**). Với tiếng Việt có dấu, cửa sổ đoạn trích
lệch đủ xa để `<mark>` không bọc quanh từ khoá. Đã sửa bằng cách quy đổi byte → ký tự tường
minh. Không có thay đổi schema nào đi kèm.

**Defect 2 — máy chủ của bộ kiểm thử bị sót lại trên Windows.** `proc_open()` với lệnh dạng
**chuỗi** chạy qua `cmd.exe`, nên `proc_terminate()` chỉ giết vỏ `cmd` mà **không** giết được
`php.exe`: mỗi lần chạy để lại hai tiến trình `php -S` mồ côi trên cổng 18080/18091. Tệ hơn
nữa, phép thăm dò cổng thấy cổng *đã* mở nên bộ kiểm thử âm thầm chạy trên **máy chủ của
lần chạy trước** — bằng chứng "tự bật máy chủ riêng" khi đó là sai. Đã sửa: truyền lệnh dạng
**mảng** cho `proc_open()` (PHP tự chạy tiến trình trực tiếp, không qua `cmd.exe`), thêm
`taskkill /F` dự phòng trên Windows, và **từ chối chạy** nếu cổng đã bị chiếm sẵn kèm
thông báo tiếng Việt. Sau khi sửa, hai lần chạy liên tiếp **không còn tiến trình nào sót
lại** và CSDL trở về đúng trạng thái trước khi chạy.

**Defect 3 — `seed_samples.php --force` không nạp lại từ đầu.** Cờ `--force` được tài liệu mô
tả là *"nạp lại từ đầu (xoá thư viện cũ trước)"*, nhưng thực tế nó chỉ **nạp chồng lên**. Mỗi
lần chạy lại `--force` lại sinh thêm phiên bản mới, và `bai-05.html` (vốn cố ý có 2 phiên bản)
dần biến thành 3, 4, 5… — dữ liệu mẫu lệch dần khỏi bảng kê. Đã sửa: `--force` giờ **xoá thư
viện cũ qua đúng hàm xoá của ứng dụng** (`wa_library_delete()`, hàm này tự dọn cả thư mục ảnh
— không bao giờ xoá tay tệp trong `public/uploads/`) rồi tạo lại thư viện từ đầu. Sau khi sửa,
mọi trang về `v1` và chỉ `bai-05.html` là `v2`, đúng như bảng kê.

---

## 5. Yêu cầu để chạy

- PHP 8.0+ (khuyến nghị 8.3) với `mbstring`, `pdo_mysql`, `curl`, `dom`, `fileinfo`.
- MySQL 8.0.
- Cài một lần: `php scripts/setup_db.php` → `php scripts/migrate_search.php` →
  `php scripts/reindex_search.php`.
- Chạy: `php -S localhost:8080 -t public` (ứng dụng), cộng thêm
  `php -S localhost:8091 -t tests/samples` nếu dùng bộ mẫu.

Chi tiết đầy đủ: [`README.md`](../README.md) mục 1 và mục 7.

---

## Phát hành còn lại cho ai

Thẻ `v1.0.0` đã có trên `origin`. **GitHub Release chưa được tạo** vì máy build không có
`gh` đã đăng nhập. Người dùng có thể tạo Release từ thẻ đó bằng cách:

```bash
gh release create v1.0.0 --title "WebArchive v1.0.0" --notes-file docs/RELEASE_NOTES.md
```

hoặc tạo thủ công từ giao diện GitHub (Releases → Draft a new release → chọn thẻ
`v1.0.0`).