# WebArchive — Lưu trang Web & đọc lại ngoại tuyến (Đề tài 16)

Ứng dụng lưu nội dung (snapshot) của trang web để đọc lại và tìm kiếm về sau,
tổ chức trong thư viện theo chủ đề, gắn nhãn và phân quyền thành viên.
Toàn bộ giao diện bằng **tiếng Việt**, viết bằng **PHP thuần** — không framework,
không Composer, không bước build.

## Tóm tắt sản phẩm

| Năng lực | Mô tả ngắn |
|----------|-----------|
| **Lưu trang thật** | cURL + `DOMDocument`; kiểm tra allowlist **trước mọi** byte gửi đi và **lại ở từng bước chuyển hướng** |
| **Ảnh về máy chủ nội bộ** | mọi ảnh trong bài được tải về `public/uploads/snapshots/…`, tên đặt theo SHA256 |
| **Làm sạch HTML** | whitelist thẻ/thuộc tính — `<script>`, `onclick`, `<iframe>` không thể chạy trong bản lưu |
| **Lịch sử phiên bản** | mỗi lần lưu là một bản `snapshots` với `version_no` riêng; nút “Lấy phiên bản mới” |
| **Ghi chú theo từng phiên bản** | ghi chú thuộc **đúng một phiên bản**, tải lại không làm mất ghi chú cũ |
| **Tìm kiếm tự xây** | chỉ mục đảo do dự án tự dựng — **không** `LIKE`, **không** FULLTEXT; ngữ nghĩa AND, bỏ dấu tiếng Việt |
| **Thư viện & thành viên** | tạo/đổi tên/xoá thư viện, thêm–đổi vai trò–gỡ thành viên, nhãn theo từng bài |
| **Xuất bản lưu** | tải về một tệp HTML **độc lập** (ảnh nhúng base64), mở được ngoại tuyến |
| **Phân quyền** | khách → login; đã đăng nhập nhưng ngoài thư viện → **403 tiếng Việt**; không có quyền sửa → bị từ chối ngay trong SQL |

Dữ liệu nằm trong **MySQL** (database `webarchive`). Mọi thao tác ghi đi qua **một**
hàm `wa_store_page_capture()` được `save.php` và `refetch.php` dùng chung, nên
quy tắc phiên bản, lập chỉ mục tìm kiếm và tải ảnh không bao giờ lệch nhau.

### Tài liệu liên quan

| Tài liệu | Nội dung |
|----------|----------|
| [`docs/DEMO.md`](docs/DEMO.md) | **Hai kịch bản trình diễn chính thức**, viết click-by-click |
| [`docs/SEARCH_QUERIES.md`](docs/SEARCH_QUERIES.md) | **10 câu hỏi tìm kiếm** kèm kết quả mong đợi và thực tế |
| [`tests/samples/README.md`](tests/samples/README.md) | **Bảng kê 15 trang mẫu** và kết quả lưu trang mong đợi của từng trang |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Quy ước dự án và cách đóng góp |
| [`PROJECT_CONTEXT.md`](PROJECT_CONTEXT.md) | Bối cảnh dự án và trạng thái từng giai đoạn |

### Bằng chứng cho đề tài

```bash
php scripts/run_tests.php     # 6 bài kiểm thử bắt buộc, tự bật máy chủ riêng
```

---

## 0. Thông tin bài nộp

### 0.1 Tên đề tài

> **WebArchive — Lưu trang Web & đọc lại ngoại tuyến**
> Đề tài số **16** của môn **Lập trình** — Hệ thống lưu trữ nội dung trang web
> theo thư viện, có phiên bản, tìm kiếm và phân quyền thành viên.

Tên đề tài trong `README.md` này và trong mã nguồn là **"WebArchive — Lưu trang Web &
đọc lại ngoại tuyến"**. Nếu giảng viên yêu cầu một tên khác trên file nộp, chỉ cần
sửa dòng tiêu đề đầu tiên của `README.md` (và dòng `<title>` trong `templates/`).

### 0.2 Thành viên nhóm
### 0.3 Phạm vi chức năng — ánh xạ 5 chức năng của đề tài vào mã nguồn

Năm chức năng nghiệp vụ của đề tài số 16 và **chỗ cài đặt thực tế** của từng chức năng:

| # | Chức năng của đề tài | Mã nguồn cài đặt (tệp → hàm / route) |
|---|----------------------|------------------------------------------|
| 1 | **Thư viện, nhãn, phân quyền**; chỉ URL thuộc allowlist mới được lưu | `src/queries.php` → `wa_library_create()` (dòng 607), `wa_library_update()` (698), `wa_library_delete()` (807), `wa_member_add()` (936), `wa_member_update_role()` (1002), `wa_member_remove()` (1040); `public/library_action.php` (bộ điều khiển POST: `create`/`update`/`delete`/`member_add`/`member_role`/`member_remove`/`tags`); `src/capture.php` → `wa_capture_is_allowlisted()` (dòng 225 — kiểm tra allowlist **trước mọi** request); `public/admin/allowlist.php` (bảng `allowlist_domains`) |
| 2 | **Tải HTML + bóc tách tiêu đề/nội dung, phân biệt lỗi** | `src/capture.php` → `capture_url()` (dòng 987), `wa_capture_http_get()` (dòng 273, tự đi tối đa 5 redirect và **kiểm tra lại allowlist ở từng đích**), `wa_capture_extract_title()` (587), `wa_capture_find_content_node()` (656); mã lỗi `error_404` / `error_timeout` / `error_blocked` / `error_no_content` / `error_http` / `error_invalid_url`; `src/queries.php` → `wa_store_page_capture()` (dòng 2103) ghi bản ghi lỗi với `fetch_status <> 'success'` |
| 3 | **Bản đọc lại sạch, ảnh lưu cục bộ** | `src/capture.php` → `wa_capture_sanitize()` (dòng 779, whitelist thẻ/thuộc tính), `wa_capture_localize_images()` (1277 — tải về `public/uploads/snapshots/<id>/<sha256>.<ext>`, đổi `src` thành đường dẫn cục bộ), `wa_capture_assert_clean()` (945); `src/queries.php` → `wa_insert_assets()` (dòng 1987); `public/reader.php` (trình đọc nền giấy ngà) |
| 4 | **Tìm kiếm tự xây trên nội dung đã lưu**, trả đoạn trích, gắn đúng phiên bản | `src/search.php` → `wa_search_index_snapshot()` (dòng 164), `wa_search_snapshots()` (dòng 258, ngữ nghĩa AND, `score = 3×count_title + count_body`, lọc theo **thẻ** và **tên miền nguồn**); `public/search.php` (route GET `q`/`tag`/`domain`); bảng `search_terms` (`database/schema.sql` dòng 164) |
| 5 | **Tải lại (refetch) + ghi chú + xuất kho có phân quyền** | `public/refetch.php` (POST, gọi CHUNG `wa_store_page_capture()`); `public/note.php` (POST, ghi chú theo từng phiên bản); `public/export.php` (GET `id`, `wa_export_build_document()` — `src/queries.php` dòng 2935 — nhúng ảnh base64 data URI); `src/queries.php` → `wa_page_versions()` (dòng 2247, chỉ lấy `fetch_status = 'success'`) |

**Hai yêu cầu kỹ thuật cốt lõi:**

| Yêu cầu | Cài đặt |
|---------|---------|
| Không bao giờ coi trang lỗi là bài hợp lệ; luôn đọc từ snapshot đã lưu | `src/queries.php`: `wa_library_articles()` (dòng 1289), `wa_find_snapshot()` (1437), `wa_page_versions()` (2247) đều có điều kiện `fetch_status = 'success'` **ngay trong SQL**; `public/export.php` trả 404 cho bản ghi lỗi |
| Snapshot không chứa script/tài nguyên ngoài; mọi đích tải về (kể cả sau redirect) phải qua allowlist | `src/capture.php`: whitelist thẻ/thuộc tính khi sanitize; `wa_capture_http_get()` kiểm tra allowlist **trước khi mở kết nối** và **lại ở từng bước chuyển hướng**; cURL dùng **không** `CURLOPT_FOLLOWLOCATION` |

**Năm bài kiểm thử bắt buộc** (đề tài yêu cầu 5 bài; dự án có 6 vì tách riêng "nguồn
đã chết" và "mã độc") — bản đồ chi tiết ở mục 5.3.3, tự động chạy bằng
`php scripts/run_tests.php`:

| Mã | Bài kiểm thử bắt buộc | Dấu hiệu PASS |
|----|----------------------|----------------|
| T1 | Nguồn đã chết — bản lưu vẫn đọc được kèm ảnh cục bộ | `reader=200`, ảnh cục bộ `200` |
| T2 | Tìm theo **thân** bài | `SNAP-…` + thẻ "Phiên bản" + `<mark>` |
| T3 | Phân biệt lỗi | `error_404`, `error_timeout`, `error_blocked` (3 mã khác nhau) |
| T4 | Mã độc không chạy được | `content_html` không còn `script` / `onclick` / `iframe` |
| T5 | Phiên bản + ghi chú sống sót | `v1=1`, `v2=2`, ghi chú ở v1 còn nguyên |
| T6 | Người ngoài không vào được | `reader=403`, `export=403`; chủ sở hữu `export=200` |

### 0.4 Tự xây dựng vs. tái sử dụng

**Nhóm TỰ XÂY (không dùng thư viện ngoài, không dùng sẵn tính năng của framework):**

| Phần | Tệp / hàm |
|------|-----------|
| Engine tải trang thật (cURL + DOMDocument, tự đi redirect) | `src/capture.php` → `capture_url()`, `wa_capture_http_get()` |
| Sanitizer HTML whitelist (loại `script`/`iframe`/`onclick`/style inline) | `src/capture.php` → `wa_capture_sanitize()` (779), `wa_capture_assert_clean()` (945) |
| Bản đọc lại sạch + nối đường dẫn ảnh cục bộ | `src/capture.php` → `wa_capture_localize_images()` (1277) |
| Chỉ mục đảo tìm kiếm (tokenizer, bỏ dấu tiếng Việt, AND, xếp hạng, đoạn trích `<mark>`) | `src/search.php` — **không** `LIKE`, **không** FULLTEXT |
| Quản lý phiên bản (mỗi lần lưu là một `version_no` riêng) | `src/queries.php` → `wa_store_page_capture()`, `wa_snapshot_versions()` |
| Ghi chú theo từng phiên bản (ép quyền sở hữu bằng `user_id` trong SQL) | `public/note.php`, `src/queries.php` |
| Phân quyền nhiều vai trò (owner / editor / viewer / admin) | `src/queries.php` → `wa_can_read_library()` (2533), `wa_can_write_library()` (1833), `wa_can_manage_library()` (575), `wa_library_access_sql()` (140) |
| Quản lý thư viện / thành viên / nhãn | `src/queries.php` (`wa_library_create/update/delete`, `wa_member_add/update_role/remove`, `wa_page_tags_replace()` dòng 1130), `public/library_action.php` |
| Xuất bản lưu HTML độc lập (nhúng base64, kiểm tra bằng DOMXPath) | `src/queries.php` → `wa_export_build_document()`, `wa_export_inline_images()`, `wa_export_html_is_standalone()` |
| Chống giả mạo: CSRF (`random_bytes()` + `hash_equals()`), escape output | `src/bootstrap.php`, `src/auth.php` |
| Bộ kiểm thử tự động T1–T6 (tự bật hai máy chủ riêng, HTTP thật) | `scripts/run_tests.php` |
| Bộ dữ liệu mẫu 15 trang tiếng Việt + 5 ảnh PNG | `tests/samples/`, `scripts/seed_samples.php`, `scripts/make_sample_images.php` |
| Toàn bộ giao diện (design token + component, tiếng Việt) | `public/assets/css/app.css`, `templates/` |

**TÁI SỬ DỤNG (ngoài ra, không tự viết):**

| Thứ | Nguồn | Ghi chú |
|-----|-------|---------|
| PHP 8.3 core — extension `curl` | extension chính thức của PHP | Dùng để tải trang; **không** dùng `CURLOPT_FOLLOWLOCATION` (tự đi redirect để kiểm allowlist) |
| PHP 8.3 core — extension `dom` (`DOMDocument`, `DOMXPath`) | extension chính thức | Bóc tách nội dung + kiểm tra tệp xuất bằng XPath (không regex) |
| PHP 8.3 core — extension `mbstring` | extension chính thức | `mb_strtolower()`, cắt chuỗi đa byte |
| PHP 8.3 core — extension `fileinfo` (`finfo_buffer`) | extension chính thức | Nhận diện MIME ảnh **theo nội dung thật**, không tin phần mở rộng |
| PHP 8.3 core — extension `openssl` (`random_bytes`) | extension chính thức | Sinh token CSRF, mật khẩu ngẫu nhiên tài khoản CSDL |
| PHP 8.3 core — `password_hash()` / `password_verify()` (bcrypt) | hàm chuẩn PHP | Lưu mật khẩu trong bảng `users` |
| PDO MySQL (`pdo_mysql`) | extension chính thức | Mọi truy vấn có tham số đều là prepared statement |
| MySQL Server 8.0 | máy chủ CSDL | Chỉ dùng làm nơi lưu trữ; **không** dùng `MATCH … AGAINST` / FULLTEXT |
| Font **Inter** + **Merriweather** | Google Fonts (`fonts.googleapis.com`) | Chỉ tải phông chữ; có phông hệ thống dự phòng nên demo offline vẫn hiển thị tốt |
| Biểu tượng **Material Symbols Outlined** | Google Fonts (`fonts.googleapis.com`) | Luôn đi kèm nhãn chữ tiếng Việt, nên không phụ thuộc hiển thị biểu tượng |
| Hình minh hoạ logo / sơ đồ | `public/assets/img/*.svg` — **tự vẽ** bằng SVG | Không dùng thư viện icon ngoài |

> **Không dùng:** framework PHP, Composer, Tailwind/CSS framework, thư viện tìm kiếm,
> thư viện HTML sanitizer, thư viện quản lý ảnh. Không có `package.json`,
> `composer.json` hay thư mục `vendor/` trong dự án.

---

## 1. Chạy dự án trên Windows

Yêu cầu: **PHP 8.0 trở lên** (khuyến nghị 8.3) và **MySQL Server 8.0**.

Bật các extension PHP cần thiết trong `php.ini`: `pdo_mysql`, `mbstring`, `curl`, `dom`, `fileinfo`, `openssl`. Ứng dụng dùng PDO;
`mysqli` không được dùng trong mã nguồn và không bắt buộc.

> Trên Windows, nếu HTTPS báo lỗi CA, đặt
> `curl.cainfo = "C:\Program Files\Git\usr\ssl\certs\ca-bundle.crt"` trong `php.ini`.

### 1.1. Kết nối database MySQL hiện có

Với database đang có dữ liệu, **không chạy** `scripts/setup_db.php` và không import
`database/schema.sql` hoặc `database/seed.sql`. Tạo `config/db.local.php` (đã được
`.gitignore`) bằng tài khoản MySQL hiện có được cấp quyền trên `webarchive`:

```php
<?php
declare(strict_types=1);

return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'dbname' => 'webarchive',
    'user' => '<tai khoan MySQL hien co>',
    'password' => '<mat khau — chi ghi trong tep local nay>',
    'charset' => 'utf8mb4',
];
```

Thay các giá trị phù hợp với máy của bạn. Không commit tệp local này hoặc đưa mật
khẩu vào bất kỳ tệp nào được theo dõi bởi Git. Trước khi dùng ứng dụng, xác minh
database và các bảng cần thiết đã tồn tại; chỉ cài schema khi đã xác nhận database
chưa có dữ liệu cần giữ.

### 1.2. Cài đặt database mới (CÓ THỂ XOÁ dữ liệu)

Chỉ dùng quy trình dưới đây khi bạn chủ động cài mới database `webarchive`. Mở
PowerShell tại thư mục gốc dự án và đặt thông tin quản trị MySQL:

Mở **PowerShell** tại thư mục gốc dự án và đặt thông tin quản trị MySQL:

```powershell
$env:WA_ADMIN_USER = '<tai khoan MySQL quan tri>'
$env:WA_ADMIN_PASS = '<mat khau MySQL cua ban>'
php scripts/setup_db.php
```

Kịch bản này sẽ:

1. Tạo database `webarchive` (utf8mb4 / utf8mb4_unicode_ci) nếu chưa có.
2. Tạo tài khoản MySQL riêng `'webarchive'@'localhost'` chỉ có quyền trên `webarchive.*`,
   với mật khẩu ngẫu nhiên, rồi ghi vào `config/db.local.php`.
3. Áp dụng `database/schema.sql` rồi `database/seed.sql`. Schema xoá và tạo lại
   toàn bộ bảng trong `webarchive`, sau đó nạp dữ liệu mẫu.
4. In ra số dòng của từng bảng.

> **Bí mật:** `config/db.local.php` đã được `.gitignore` và **không bao giờ commit**.
> Mật khẩu quản trị `WA_ADMIN_PASS` chỉ tồn tại trong phiên PowerShell, không ghi ra tệp.
> Ứng dụng **luôn** kết nối bằng tài khoản riêng `webarchive`, không dùng tài khoản quản trị.

**Không chạy lại** `setup_db.php` trên database đang sử dụng: thao tác này sẽ xoá
các bảng/dữ liệu trong `webarchive` và nạp lại dữ liệu mẫu.

### 1.3. Chạy máy chủ web

```powershell
php -S localhost:8080 -t public
```

Sau đó mở trình duyệt tại: **http://localhost:8080**

> Lệnh phải chạy từ **thư mục gốc dự án** (`D:\PTPMMNM-NHOM9`) vì các trang nạp
> `src/`, `templates/`, `config/` nằm ngoài `public/` (public là web root).
>
> Nếu `php` chưa có trong PATH: mở PowerShell mới sau khi cài, hoặc dùng đường dẫn đầy đủ,
> ví dụ `"C:\Program Files\PHP\php.exe" -S localhost:8080 -t public`.

Nhấn `Ctrl + C` để dừng máy chủ.

---

## 2. Tài khoản demo

Đây là **tài khoản thật trong bảng `users`** của MySQL, mật khẩu lưu bằng
`password_hash()` (bcrypt), kiểm tra bằng `password_verify()`.

| Email | Mật khẩu | Vai trò | Quyền |
|-------|----------|---------|-------|
| `user@demo.vn` | `demo123` | Người dùng | Thư viện, lưu trang, tìm kiếm, đọc offline |
| `admin@demo.vn` | `admin123` | Quản trị viên | Như trên **+ khu vực Quản trị hệ thống** |

Dữ liệu mẫu còn có thêm 3 tài khoản phụ (`ha.nguyen@demo.vn`, `minh.vu@demo.vn` — đang bị
khoá, `linh.pham@demo.vn`) dùng để minh hoạ màn hình quản trị.

**Khi đăng nhập bằng tài khoản thường và mở trang quản trị** sẽ thấy trang tiếng Việt
“Không có quyền truy cập”. Khi là **khách** và mở bất kỳ trang workspace nào, hệ thống
**chuyển hướng** về `login.php`.

---

## 3. Bản đồ trang

| Trang | Đường dẫn | Quyền |
|-------|-----------|-------|
| Trang chủ | `/index.php` | Khách |
| Đăng nhập | `/login.php` | Khách |
| Đăng ký | `/register.php` | Khách |
| Đăng xuất | `/logout.php` | Đã đăng nhập |
| Thư viện của tôi | `/libraries.php` | Đăng nhập |
| Chi tiết thư viện | `/library.php?slug=cntt-lap-trinh` | Đăng nhập |
| Quản lý thư viện (POST) | `/library_action.php` | Chủ sở hữu / biên tập viên / quản trị (chỉ POST) |
| Lưu trang Web | `/save.php` | Chủ sở hữu / biên tập viên |
| Tải lại phiên bản | `/refetch.php` | Chủ sở hữu / biên tập viên (chỉ POST) |
| Ghi chú theo phiên bản | `/note.php` | Đăng nhập (chỉ POST) |
| Trình đọc ngoại tuyến | `/reader.php?id=8943` | Đăng nhập + thành viên thư viện |
| **Xuất bản lưu (HTML độc lập)** | `/export.php?id=8943` | Đăng nhập + thành viên thư viện |
| Tìm kiếm nội dung | `/search.php?q=snapshot` | Đăng nhập |
| Tổng quan hệ thống | `/admin/index.php` | **Quản trị** |
| Người dùng | `/admin/users.php` | **Quản trị** |
| Tên miền được duyệt | `/admin/allowlist.php` | **Quản trị** |
| Tất cả thư viện | `/admin/libraries.php` | **Quản trị** |

---

## 4. Cấu trúc dự án

```
prompt.md                      Đặc tả giai đoạn này
PROJECT_CONTEXT.md             Tóm tắt đặc tả, bản đồ trang, cách các phase sau cắm vào
database/schema.sql            Lược đồ MySQL (11 bảng, InnoDB, utf8mb4, khoá ngoại)
database/seed.sql              Dữ liệu mẫu (INSERT) — chạy lại được nhiều lần
scripts/setup_db.php           Cài đặt CSDL + tài khoản riêng + nạp seed + tạo tệp tài nguyên cục bộ (CLI, idempotent)
scripts/migrate_search.php     Tạo bảng chỉ mục `search_terms` (CLI, idempotent, tài khoản ứng dụng)
scripts/reindex_search.php     Lập lại toàn bộ chỉ mục tìm kiếm (CLI, idempotent)
scripts/make_sample_images.php Sinh 5 tệp PNG nhỏ hợp lệ cho bộ trang mẫu (không cần GD)
scripts/seed_samples.php       Nạp 15 trang mẫu vào "Kho dữ liệu mẫu" qua engine thật (idempotent)
scripts/run_tests.php          Sáu bài kiểm thử bắt buộc T1–T6 (tự bật máy chủ riêng)
config/db.php                  Nạp cấu hình CSDL, dựng DSN (KHÔNG chứa mật khẩu)
config/db.local.php            Thông tin kết nối thật — .gitignore, KHÔNG commit
src/db.php                     PDO singleton + trang lỗi tiếng Việt khi CSDL lỗi
src/auth.php                   Session + require_login() / require_admin() / current_user()
src/bootstrap.php              Đường dẫn, nạp src, hàm trợ giúp, polyfill mbstring
src/queries.php                Toàn bộ truy vấn CSDL (thay thế src/sample_data.php)
src/search.php                 Chỉ mục đảo tìm kiếm tự xây: tokenizer, bỏ dấu, lập chỉ mục, truy vấn AND, đoạn trích <mark>
src/capture.php                Engine lưu trang THẬT (cURL + DOMDocument + sanitizer + tải ảnh)
templates/                     2 layout dùng chung + trang lỗi 403 + trang lỗi CSDL
public/                        WEB ROOT
  assets/css/app.css           Toàn bộ design token + component (không framework)
  assets/img/                  Logo & ảnh minh hoạ cục bộ (SVG)
  uploads/snapshots/<id>/      Ảnh đã tải của từng snapshot (.gitignore, chỉ giữ .gitkeep)
public/library_action.php      Bộ điều khiển POST cho quản lý thư viện / thành viên / nhãn (CSRF + phân quyền)
public/export.php              Xuất bản lưu: tải về một tệp HTML độc lập (ảnh nhúng base64)
tests/fixtures/                Trang thử (article.html, pic.png, redir-*.php, slow.php, notice.html)
tests/samples/                 15 trang mẫu tiếng Việt + 5 ảnh PNG + README.md làm bảng kê
docs/DEMO.md                   Hai kịch bản trình diễn chính thức, click-by-click
docs/SEARCH_QUERIES.md         10 câu hỏi tìm kiếm: kỳ vọng trước, thực tế sau
docs/design-reference/         Nguồn thiết kế: DESIGN.md + 4 màn hình tham chiếu
LICENSE                        Giấy phép MIT
CONTRIBUTING.md                Quy ước dự án và cách đóng góp
```

---

## 5. Engine lưu trang thật

`src/capture.php` cung cấp `capture_url(string $url): array` — không dùng thư viện ngoài,
chỉ `cURL` + `DOMDocument`. Pipeline theo thứ tự:

1. Kiểm tra URL: chỉ `http`/`https`, bắt buộc có host, từ chối URL chứa thông tin đăng nhập.
2. **Allowlist trước khi gửi bất kỳ byte nào** — host khớp đúng một domain trong
   `allowlist_domains`, hoặc kết thúc bằng `.` + domain (không phân biệt hoa thường).
   Lệch allowlist → `error_blocked`, **không gửi HTTP request nào**.
3. cURL **không** tự đi theo redirect; ứng dụng tự đi tối đa **5** bước và **kiểm tra lại
   allowlist ở từng đích redirect** trước khi tải. Kết nối **5 s**, tổng **12 s**.
   Dừng tải nếu vượt **5 MB**.
4. 404 → `error_404`; 4xx/5xx khác → `error_http`; `Content-Type` không phải HTML → `error_no_content`.
5. Bóc tách bằng `DOMDocument`: tiêu đề `og:title` → `<title>` → `<h1>`; thân lấy `<article>` →
   `<main>` → div/section có điểm mật độ chữ cao nhất; gỡ `script/style/noscript/iframe/
   form/nav/header/footer/aside/svg/button/input` trước khi trích.
6. Text dưới **200 ký tự** → `error_no_content`.
7. **Sanitizer whitelist tự viết** — chỉ giữ tag/thuộc tính được duyệt, mọi link thêm
   `rel="noopener noreferrer"`, link không phải http/https bị bỏ nhưng giữ chữ.
8. **Ảnh tải về máy chủ**: tối đa 20 ảnh, `src` giải phân giải thành URL tuyệt đối, kiểm tra
   allowlist host, cap 5 MB, chỉ nhận JPEG/PNG/GIF/WebP (kiểm bằng `finfo`, **không tin
   phần mở rộng**, không SVG). Lưu vào `public/uploads/snapshots/<snapshot_id>/<hash>.<ext>`,
   ghi bảng `assets`, và `img src` được đổi thành đường dẫn cục bộ — sau bước này nội dung
   **không còn URL ảnh ngoài**.

Lỗi vẫn được **ghi lại** vào CSDL (snapshot với `fetch_status = error_code`) để thống kê quản
trị đếm được, nhưng danh sách thư viện, tìm kiếm và trình đọc **chỉ lấy snapshot có
`fetch_status = 'success'`** — trang lỗi không bao giờ hiện như bài đọc được.

**Ghi chú:** `localhost` được giữ trong `allowlist_domains` làm **host thử nghiệm cục bộ**
cho fixture server (`php -S localhost:8090 -t tests/fixtures`). Nếu không muốn thử nghiệm
trang nội bộ thì xoá dòng đó trong `/admin/allowlist.php`.

---

## 5.1 Tìm kiếm tự xây (P-SEARCH) — chỉ mục đảo không dùng FULLTEXT

**Vì sao tự làm?** Đề tài yêu cầu phần lưu trữ – phiên bản – tìm kiếm – phân quyền phải **tự
xây** và **giải thích được trong phần vấn đáp**. Vì vậy dự án **không** tạo FULLTEXT index và
**không** dùng `MATCH ... AGAINST` của MySQL. Thay vào đó dự án dựng chỉ mục đảo của riêng mình.

### Bảng `search_terms`

| Cột | Kiểu | Ý nghĩa |
|-----|------|---------|
| `id` | `BIGINT UNSIGNED` PK | khoá chính |
| `term` | `VARCHAR(100)` | token gốc đã hạ chữ thường, **giữ nguyên dấu tiếng Việt** |
| `term_folded` | `VARCHAR(100)` | token **đã bỏ dấu** — đây là khoá so khớp |
| `snapshot_id` | `INT UNSIGNED` FK → `snapshots(id)` `ON DELETE CASCADE` | bản snapshot chủ sở hữu dòng này |
| `count_title` | `INT UNSIGNED` | số lần từ xuất hiện trong **tiêu đề** |
| `count_body` | `INT UNSIGNED` | số lần từ xuất hiện trong **thân bài** |

Chỉ mục: `UNIQUE (term_folded, snapshot_id)` + `INDEX (term_folded)`.

> **Vì sao UNIQUE lại quan trọng?** Vì mỗi cặp (từ, snapshot) chỉ được có **một** dòng, nên:
> (1) số lần xuất hiện được **cộng dồn lúc lập chỉ mục** thay vì phải quét lại `content_text`
> mỗi lần tìm; (2) khi viết điều kiện AND ta đếm được chính xác bao nhiêu từ của truy vấn mà
> bản snapshot đó có thực sự chứa.

### Tách từ (`wa_search_tokenize`)

1. Hạ chữ thường bằng `mb_strtolower()`.
2. Tách ở **mọi ký tự không phải chữ cái hoặc chữ số Unicode** (`/[^\p{L}\p{N}]+/u`) — dấu chấm,
   gạch ngang, gạch dưới, dấu phẩy, xuống dòng… đều là ranh giới. Vì vậy
   `khoa_tu_ban_than_webarchive` và `khoa-tu-ban-than` ra cùng các token.
3. Bỏ token ngắn hơn **2 ký tự** (số "1", dấu câu lọt vào không có nghĩa).
4. Cắt token dài hơn **100 ký tự**.

Tiếng Việt **không** dùng bộ tách từ theo từ điển — tách theo khoảng trắng là đủ, nên không
cần thêm gói tiếng Việt nào (đây cũng là ưu điểm để giải thích khi hỏi).

### Bỏ dấu tiếng Việt (`wa_search_fold`)

`a/ă/â → a`, `e/ê → e`, `i → i`, `o/ô/ơ → o`, `u/ư → u`, `y → y`, `đ → d`, mọi dấu thanh bị
xoá (các cặp hai chữ như `ươ`, `uô`, `iê`… cũng được xử lý). Hàm này **chỉ gọi** bảng bỏ dấu
dùng chung `wa_vi_fold_map()` khai báo trong `src/bootstrap.php` — **toàn dự án chỉ có MỘT bảng
bỏ dấu**, không chép thêm bản thứ hai trong `src/search.php`.

> **Vì sao phải bỏ dấu?** Người Việt gõ "luu trang" (phím không dấu) nhưng bài đã lưu chứa
> "lưu trang". Chỉ so khớp được hai bên khi cả hai cùng được bỏ dấu.

### Khi nào việc lập chỉ mục chạy

| Thời điểm | Cơ chế |
|-----------|--------|
| Ngay sau khi lưu trang thành công | `wa_store_page_capture()` gọi `wa_search_index_snapshot()` — tự động, người dùng không phải làm gì |
| Lần lưu thất bại | **không** tạo dòng chỉ mục nào (và xoá sạch nếu có) → tìm kiếm không bao giờ trả về một lần lưu lỗi |
| Cần dựng lại toàn bộ | `php scripts/reindex_search.php` (xoá sạch rồi lập lại, chạy lại được) |
| Cài mới / nâng cấp CSDL | `php scripts/migrate_search.php` (chỉ tạo bảng, `CREATE TABLE IF NOT EXISTS`) |

Hàm lập chỉ mục **xoá dòng cũ của snapshot trước khi ghi**, nên gọi lại nhiều lần vẫn cho kết
quả giống hệt nhau (**idempotent**). Mỗi phiên bản giữ **riêng** bộ dòng chỉ mục của nó, nên kết
quả tìm kiếm gắn với **đúng phiên bản** người đang đọc, không bị phiên bản mới "đè" mất.

### Ngữ nghĩa AND và xếp hạng

- **AND**: một bản snapshot chỉ được trả về khi chứa **TẤT CẢ** từ của câu truy vấn. Điều kiện
  nằm ngay trong SQL: số từ **đã khớp** phải bằng số từ trong truy vấn.
- **Xếp hạng**: `score = 3 × SUM(count_title) + SUM(count_body)` — hệ số 3 cho tiêu đề vì tiêu đề
  đáng tin hơn phần thân. Sắp theo `score` giảm dần, rồi `fetched_at` giảm dần.
- **Chỉ** `fetch_status = 'success'`, và **chỉ** trong thư viện người dùng sở hữu / là thành viên /
  là quản trị — bộ lọc quyền **nằm trong SQL** (`wa_library_access_sql()`), nên không thể lách
  bằng cách sửa tham số `GET`.
- Bộ lọc **thẻ** và **tên miền** cộng thêm vào điều kiện, vẫn giữ ngữ nghĩa AND.

### Đoạn trích và làm nổi bật `<mark>`

So khớp để tìm đoạn trích chạy trên **dạng đã bỏ dấu**, còn phần **hiển thị giữ nguyên dấu**:
nội dung được tách thành token kèm vị trí, so khớp `wa_search_fold()` từng token, rồi mới ghép lại
**văn bản gốc** và bọc `<mark>` quanh đúng khoảng ký tự đó. Vì vậy gõ "luu trang" vẫn bắt được
"lưu trang" và hiển thị đúng chữ có dấu.

Các từ khớp liền nhau (nối bằng dấu gạch dưới/dấu chấm/khoảng trắng) được gom thành **một chùm**
và bọc `<mark>` trọn vẹn — nên `khoa_tu_ban_than_webarchive` hiện nguyên vẹn chứ không bị cắt vụn.
Đoạn trích neo vào **chùm dài nhất** và dài khoảng 200 ký tự. Mọi thứ lấy từ `content_text` đều được
**escape HTML** trước khi in; không có chỗ nào in thẳng `content_text` vào mẫu.

---

## 5.2 Quản lý thư viện, thành viên, nhãn và xuất bản lưu (P-LIB)

Tất cả bốn mục dưới đây **ghi thật vào MySQL** (trước đây chỉ hiển thị giao diện).

### 5.2.1 Tạo / sửa / xoá thư viện

- Nút **“+ Tạo thư viện mới”** ở `libraries.php` mở form thật (tên bắt buộc, mô tả tuỳ chọn,
  hiển thị `private` / `shared` / `public`, màu và biểu tượng). Gửi `POST` tới
  `public/library_action.php` với token CSRF.
- **Slug** sinh từ tên: hạ chữ thường → bỏ dấu bằng bảng bỏ dấu **dùng chung** `wa_vi_fold_map()`
  (`src/bootstrap.php`) → khoảng trắng thành gạch ngang. Nếu slug đã tồn tại thì nối `-2`, `-3`, …
  cho tới khi duy nhất.
- Chủ sở hữu được thêm vào `library_members` với `role = 'owner'` ngay khi tạo.
- **Đổi tên / đổi hiển thị** (chủ sở hữu hoặc quản trị): dùng lại đúng quy tắc slug ở trên, nhưng
  **giữ nguyên slug cũ nếu tên không đổi**.
- **Xoá thư viện** — **chỉ chủ sở hữu**, có bước xác nhận riêng (phải gõ lại mã xác nhận):
  xoá các dòng CSDL qua khoá ngoại `ON DELETE CASCADE` (`library_members`, `saved_pages`,
  `snapshots`, `assets`, `page_tags`) **và** xoá thư mục ảnh trên đĩa
  `public/uploads/snapshots/<snapshot_id>/` của **mọi** snapshot thuộc thư viện.
- Người không phải chủ sở hữu **không thấy** nút điều khiển, và gửi thẳng `POST` cũng bị từ chối
  bằng **trang 403 tiếng Việt** (`templates/error_no_permission.php`).

### 5.2.2 Quản lý thành viên (trong `library.php`)

Hiện cho **chủ sở hữu** và **quản trị viên**; mọi thao tác là `POST` + CSRF:

| Thao tác | Quy tắc |
|----------|---------|
| Thêm thành viên theo email | Email phải **tồn tại** trong `users`, nếu không → *"Không tìm thấy tài khoản với email này"*; đã là thành viên → *"Tài khoản này đã là thành viên của thư viện."*; vai trò chọn lúc thêm là `editor` hoặc `viewer` (**không bao giờ** `owner`) |
| Đổi vai trò | Chỉ giữa `editor` ↔ `viewer` |
| Gỡ thành viên | Xoá dòng `library_members` |

> **Dòng của chủ sở hữu được bảo vệ trong SQL**, không chỉ ở giao diện: mọi câu `UPDATE`/`DELETE`
> đổi vai trò hoặc gỡ thành viên đều có điều kiện `AND role <> 'owner'` trong `WHERE`. Gửi thẳng
> `POST` bằng tay cũng **không** hạ cấp hay xoá được chủ sở hữu.

Bảng thành viên hiển thị **ngay sau mỗi thao tác** (tên, email, nhãn vai trò tiếng Việt, thứ tự
tham gia).

### 5.2.3 Sửa nhãn của một bài trong thư viện

Mỗi thẻ bài viết trong `library.php` có một ô sửa nhãn cho người có quyền sửa
(`owner` / `editor` / `admin`), giá trị là các nhãn hiện tại **phân tách bằng dấu phẩy**. Gửi đi sẽ
**thay thế** toàn bộ tập `page_tags` của trang đó: nhãn mới được tạo trong bảng `tags` nếu chưa có,
liên kết không còn được liệt kê thì bị xoá. Quy ước `#` của dữ liệu mẫu được **giữ nguyên** —
nhãn nhập vào mà không có `#` vẫn được giữ đúng cách viết của dữ liệu sẵn có.
Người xem (`viewer`) chỉ thấy nhãn ở chế độ **chỉ đọc**, không có ô sửa.

### 5.2.4 Xuất bản lưu — HTML độc lập (`public/export.php`)

`GET export.php?id=<snapshot_id>`:

- Yêu cầu đăng nhập; quyền **giống trình đọc** (quản trị hoặc thành viên thư viện), nếu không →
  **403 tiếng Việt**; snapshot lỗi hoặc không tồn tại → **404**; khách → chuyển hướng `login.php`.
- Trả về **một tệp HTML tải về** (`Content-Disposition: attachment`), tên tệp
  `<slug-tiêu-de-boc-dau>-v<version_no>.html`.
- Nội dung gồm: **khối nguồn gốc** (tiêu đề bài, URL nguồn, tên thư viện, *"Phiên bản N"*,
  `fetched_at`, người xuất + thời điểm xuất) → phần **`<style>` tối giản** nhúng trong tệp →
  `content_html` của snapshot.
- **Mọi ảnh cục bộ** (`/uploads/…`) được nhúng thành **data URI base64**: đọc tệp, nhận diện MIME
  bằng `finfo_buffer()` (không tin phần mở rộng), chỉ nhận JPEG/PNG/GIF/WebP.
- Tệp xuất **không tham chiếu tài nguyên ngoài**: không có `<img src="http…">`, không còn
  `/uploads/` nào, không có `<link>` / `<script>` / `<iframe>`. Liên kết `<a href="http…">` **được
  giữ nguyên** vì đó là *nội dung*, không phải tài nguyên phải tải.
- Kiểm tra độc lập bằng **DOM** (`DOMXPath`), **không** dùng regex trên chuỗi — nếu dùng regex thì
  HTML đã escape trong bài (ví dụ `<img src="…">` nằm trong khối code) sẽ bị báo nhầm.

Trong trình đọc có nút **“Xuất bản lưu”** ngay cạnh nút **“Xuất PDF”** (nút in vẫn hoạt động).

---

## 5.3 Bộ mẫu, tài liệu và bằng chứng (P-FINAL)

Ba tệp này là **bằng chứng cho đề tài** — dùng khi nộp, khi thuyết trình và khi
đánh giá.

### 5.3.1 Bộ 15 trang mẫu — `tests/samples/`

Các trang tiếng Việt phục vụ bằng `php -S localhost:8091 -t tests/samples`, mỗi trang
mang một **cụm khoá riêng** (`mau01webarchive` … `mau13webarchive`) nằm trong thân
bài để câu hỏi tìm kiếm có kết quả xác định. Bọn trang mẫu phủ đủ mọi trường hợp
biên của engine: bài thường, trùng tiêu đề, nhiều ảnh, bài dài, bảng + danh sách,
trang có mã độc, trang không có nội dung, chuyển hướng ra ngoài allowlist, và
trang chậm gây timeout.

Bảng kê đầy đủ 15 mục kèm **kết quả lưu trang mong đợi** nằm ở
[`tests/samples/README.md`](tests/samples/README.md).

Nạp vào kho bằng đúng đường lưu trang thật:

```bash
php scripts/seed_samples.php
```

Script dùng tài khoản ứng dụng, bảo đảm thư viện **“Kho dữ liệu mẫu”** của
`user@demo.vn` tồn tại, rồi lưu từng trang qua `wa_store_page_capture()` — **không**
chèn thẳng bằng SQL — và in một dòng cho mỗi mục. Chạy lần hai sẽ in
`already seeded` và thoát với mã `0` mà không tạo bản ghi trùng.

### 5.3.2 Mười câu hỏi tìm kiếm — `docs/SEARCH_QUERIES.md`

Mười câu hỏi phủ đủ mọi yêu cầu: cụm chỉ có trong thân, từ nằm ở tiêu đề, gõ
không dấu khớp nội dung có dấu, điều kiện VÀ nhiều từ, lọc theo thẻ, lọc theo tên
miền, cặp trùng tiêu đề, một trang có hai phiên bản, câu không có kết quả, và câu
xếp hạng tiêu đề đứng trước thân.

**Cách làm:** kỳ vọng được **thiết kế và viết ra từ nội dung mẫu trước**, bằng
một công cụ mô phỏng ngữ nghĩa AND và công thức xếp hạng trên chính nội dung đã
soạn; **sau đó mới** chạy qua `public/search.php` bằng HTTP thật. Tài liệu ghi
rõ cả hai cột và nêu tên mọi kỳ vọng phải sửa, kèm lý do.

### 5.3.3 Sáu bài kiểm thử bắt buộc — `scripts/run_tests.php`

```bash
php scripts/run_tests.php     # thoát 0 khi cả 6 bài PASS
```

Bộ kiểm thử **tự bật hai máy chủ riêng** bằng `proc_open()` (ứng dụng ở cổng
`18080`, trang mẫu ở cổng `18091`), nên không đụng tới các máy chủ đang chạy. Mọi
thao tác đi qua **HTTP thật** với cookie jar, không gọi thẳng hàm PHP.

| Mã | Bài kiểm thử |
|----|-------------|
| `T1` | **Nguồn đã chết** — tắt máy chủ nguồn, bản lưu vẫn đọc được kèm ảnh cục bộ |
| `T2` | **Tìm theo thân** — cụm chỉ có trong thân vẫn ra kết quả, có nhãn Phiên bản và `<mark>` |
| `T3` | **Ba lỗi khác nhau** — `error_404`, `error_timeout`, `error_blocked` |
| `T4` | **Mã độc không chạy được** — `content_html` không còn `<script>`, `onclick`, `<iframe` |
| `T5` | **Phiên bản + ghi chú sống sót** — tải lại tạo v2; v1 giữ nguyên nội dung và ghi chú |
| `T6` | **Người ngoài không vào được** — `reader`/`export` trả 403, chủ sở hữu trả 200 |

Dọn dẹp **chỉ qua hành động của chính ứng dụng**: thư viện tạm bị xoá bằng
`library_action.php` (hàm này tự dọn cả thư mục ảnh — **không bao giờ xoá tay tệp
trong `public/uploads/`**), tài khoản rác bị xoá bằng tài khoản CSDL của ứng dụng.
Tên thư viện và tài khoản rác có hậu tố ngẫu nhiên nên **chạy lại an toàn**.

---

## 6. Tính năng — trạng thái DONE

Mọi tính năng của đề tài đã **hoàn thành**. Danh sách dưới đây là phần tính
năng đầy đủ, không phải phạm vi của một giai đoạn.

- **CSDL thật:** MySQL qua PDO; **11 bảng** InnoDB utf8mb4 có khoá ngoại và UNIQUE
  (`users`, `libraries`, `library_members`, `tags`, `saved_pages`, `page_tags`, `snapshots`,
  `search_terms`, `assets`, `notes`, `allowlist_domains`).
  Mọi truy vấn có tham số đều là **prepared statement**.
- **Tài khoản:** đăng nhập/đăng ký/đăng xuất đối chiếu bảng `users`; đăng ký **ghi thật**
  vào CSDL, mật khẩu băm bằng `password_hash()`.
- **Engine lưu trang thật** (mục 5): allowlist trước mọi kết nối, tự đi tối đa 5 bước
  chuyển hướng và **kiểm tra lại allowlist ở từng đích**, bóc tách `DOMDocument`,
  sanitize whitelist, tải ảnh về `public/uploads/snapshots/…`, ghi vào bảng `assets`.
- **Sáu mã lỗi** phân biệt rõ: `error_404`, `error_timeout`, `error_blocked`,
  `error_no_content`, `error_http`, `error_invalid_url`.
- **Phiên bản & ghi chú:** mỗi lần lưu là một bản `snapshots` với `version_no` riêng;
  trình đọc có **đầu nguồn gốc**, **bộ chuyển phiên bản**, nút **“Lấy phiên bản mới”**,
  và **ghi chú riêng theo từng phiên bản** (thêm: ai cũng được; sửa/xoá bị ép trong SQL
  theo `user_id`).
- **Tìm kiếm tự xây** (mục 5.1): chỉ mục đảo `search_terms`, **không** `LIKE`,
  **không** FULLTEXT. Ngữ nghĩa **AND**, `score = 3 × count_title + count_body`,
  bỏ dấu tiếng Việt, đoạn trích bọc `<mark>`, lọc thẻ/tên miền, **lọc quyền ngay trong SQL**.
- **Quản lý thư viện** (mục 5.2): tạo/đổi tên/đổi hiển thị/xoá thư viện; thêm–đổi vai trò–gỡ
  thành viên; sửa nhãn của từng bài; **xuất bản lưu** ra tệp HTML độc lập.
- **Phân quyền:** khách → chuyển hướng `login.php`; đã đăng nhập nhưng không thuộc thư viện
  → **403 tiếng Việt**; snapshot lỗi / không tồn tại → **404**; quản trị thấy tất cả.
- **Chống giả mạo:** mọi form POST có token CSRF sinh bằng `random_bytes()` trong session,
  kiểm tra bằng `hash_equals()`, và **kiểm tra trước khi** đọc/ghi CSDL.
- **Quản trị:** tổng quan hệ thống, quản lý người dùng, **tên miền được duyệt** (ghi thật),
  danh sách toàn bộ thư viện.
- **Bộ 15 trang mẫu tiếng Việt** trong `tests/samples/`, nạp vào kho bằng
  `scripts/seed_samples.php` (idempotent).
- **Bộ kiểm thử tự động** `scripts/run_tests.php` chạy sáu bài kiểm thử bắt buộc
  (T1–T6) trên hai máy chủ riêng do chính nó bật lên, và tự dọn dữ liệu qua hành động
  của chính ứng dụng.

> Các mục sau đây từng là phạm vi của các giai đoạn trước và **đều đã xong**:
> `mock_capture.php` → engine thật (P-CAPTURE) · tải lại + ghi chú (P-READ) ·
> `LIKE` → chỉ mục đảo (P-SEARCH) · nút quản lý thư viện chỉ hiển thị giao diện → ghi thật
> (P-LIB) · bộ mẫu + kiểm thử + tài liệu OSS (P-FINAL).

---

## 6.1 Trạng thái dữ liệu cuối (đo trên CSDL thật — P-FINAL-2 Stage C)

> Mọi con số dưới đây lấy từ **CSDL MySQL thật** sau khi chạy lại toàn bộ pipeline
> (`reindex_search.php` → `seed_samples.php` → lưu trang thật → `run_tests.php`).
> Không có con số nào ở đây là số bịa đặt.

### Số dòng từng bảng

| Bảng | Số dòng |
|------|---------|
| `users` | 5 |
| `libraries` | 4 |
| `library_members` | 8 |
| `tags` | 32 |
| `saved_pages` | 25 |
| `page_tags` | 53 |
| `snapshots` | 28 |
| `search_terms` | 4944 |
| `assets` | 111 |
| `notes` | 1 |
| `allowlist_domains` | 9 |

### Số trang / snapshot theo thư viện

| Thư viện | slug | Trang | Snapshot | Thành công | Lỗi | `version_no` cao nhất |
|----------|------|------|----------|-----------|-----|----------------------|
| CNTT & Lập Trình | `cntt-lap-trinh` | 6 | 8 | 8 | 0 | 3 |
| Tài liệu học tập | `tai-lieu-hoc-tap` | 2 | 2 | 2 | 0 | 1 |
| Nghiên cứu công nghệ | `nghien-cuu-cong-nghe` | 2 | 2 | 2 | 0 | 3 |
| Kho dữ liệu mẫu | `kho-du-lieu-mau` | 15 | 16 | 13 | 3 | 2 |

### Bộ đếm metrics của ứng dụng (`wa_metrics_for_libraries()`)

| Chỉ số | Giá trị |
|--------|---------|
| Thư viện | 4 |
| Trang | 25 (thành công 22, lỗi 3) |
| Snapshot | 28 (thành công 25, lỗi 3) |
| Tài nguyên cục bộ | 111 — **có trên đĩa 111, thiếu 0** |
| Ảnh cục bộ | 103 — **có trên đĩa 103, thiếu 0** |
| Tổng dung lượng | 107 005 byte |

> **Ảnh cục bộ thiếu = 0** — đây là con số đã sửa ở Stage B (B5): 99 dòng `assets`
> trong `database/seed.sql` trỏ tới tệp không tồn tại; nay `scripts/setup_db.php`
> (bước 5b) **tạo thật** từng tệp trên đĩa rồi cập nhật `size_bytes`.
> Riêng thư viện `cntt-lap-trinh`: 6 trang, 8 snapshot, **36 ảnh cục bộ — thiếu 0**,
> 40 tài nguyên — thiếu 0.

### Snapshot theo trạng thái

| `fetch_status` | Số |
|----------------|-----|
| `success` | 25 |
| `error_timeout` | 1 |
| `error_blocked` | 1 |
| `error_no_content` | 1 |

> Ba snapshot lỗi là **các ca lỗi có chủ đích** của bộ mẫu (`cham.php` → timeout,
> `chuyen-huong-ngoai.php` → chuyển hướng ra ngoài allowlist, `bai-12.html` → nội dung
> quá ngắn). Chúng **không** hiện trong danh sách bài viết, kết quả tìm kiếm hay trình đọc
> (điều kiện `fetch_status = 'success'` nằm ngay trong SQL) — nhưng vẫn được đếm trong
> bảng quản trị.

### Chuỗi phiên bản (kịch bản trình diễn 2)

| Trang | Thư viện | Số bản | Phiên bản | Nguồn |
|-------|----------|--------|-----------|-------|
| 13 | `kho-du-lieu-mau` | 2 | 1, 2 | `http://localhost:8091/bai-05.html` |
| 25 | `cntt-lap-trinh` | 3 | 1, 2, 3 | `http://localhost:8090/article.html` |

Chuỗi 3 bản của trang 25 được tạo **qua engine thật**: lưu lần 1 (nội dung gốc chưa có
dấu hiệu phiên bản) → bấm **Lấy phiên bản mới** lần 2 (thêm dấu hiệu bản 2) → lần 3
(thêm dấu hiệu bản 3), rồi **ghi chú 1** gắn riêng với phiên bản 1.

---

## 6.2 Cách thử phiên bản & ghi chú (P-READ)

1. Đăng nhập `user@demo.vn` / `demo123`, mở **Lưu trang Web** và lưu
   `http://localhost:8090/article.html` (nhớ bật fixture server ở bước 7 mục 7).
2. Bấm **Đọc bản đã lưu** → thấy header nguồn gốc và bộ chuyển phiên bản.
3. Bấm **Lấy phiên bản mới** → trang chuyển sang `SNAP-…` mới, `version_no` tăng 1,
   danh sách phiên bản bên dưới liệt kê đủ hai bản và bản cũ **giữ nguyên nội dung**.
4. Ghi chú: viết ghi chú ở bản 1, sang bản 2 thì ghi chú **không còn** — mỗi phiên bản một
   bộ ghi chú riêng. Sửa ghi chú của mình sẽ hiện nhãn **"đã sửa"**.
5. Thử bằng tài khoản khác: gửi thẳng form sửa/xoá ghi chú của người khác → bị từ chối,
   dữ liệu trong CSDL **không đổi**.

## 6.3 Cách thử tìm kiếm tự xây (P-SEARCH)

> Chạy `php scripts/migrate_search.php` rồi `php scripts/reindex_search.php` **một lần** nếu
> vừa cài lại CSDL. Từ lần lưu trang thành công trở đi, mọi bản snapshot mới đều được lập chỉ mục
> tự động nên không cần chạy lại kịch bản.

1. Đăng nhập `user@demo.vn` / `demo123`, vào **Tìm kiếm**, gõ `luu trang` (cố tình **không gõ dấu**)
   → vẫn ra các bài có chữ "lưu trang" **có dấu**, và đoạn trích bọc `<mark>` đúng chữ có dấu.
2. Gõ nhiều từ `viet truyen` → chỉ ra bài có **cả hai** từ (AND), không phải bài có "hoặc".
3. Lọc theo **thẻ** `#MySQL` rồi theo **tên miền** `techblog.dev` → kết quả thu hẹp dần.
4. Lưu thêm `http://localhost:8090/article.html` (bật fixture server ở bước 7 mục 7), **không** chạy
   reindex → tìm `khoa_tu_ban_than_webarchive` là thấy ngay bản vừa lưu: việc lập chỉ mục đã
   chạy ngay trong lúc lưu.
5. Gõ `nguong` (chỉ có ở các bài mới) → vẫn thấy bản cũ nhất; gõ `refetch` (chỉ có ở bản từ v2 trở
   đi) → không thấy bản cũ nhất. Chứng minh kết quả gắn với **từng phiên bản một**.
6. Gõ một từ chỉ có trong `tests/fixtures/notice.html` (trang này chưa bao giờ lưu thành công) như
   `doctype` hay `charset` → **0 kết quả**.

## 6.4 Cách thử quản lý thư viện & xuất bản lưu (P-LIB)

1. Đăng nhập `user@demo.vn` / `demo123` → **Thư viện của tôi** → bấm **“+ Tạo thư viện mới”** →
   đặt tên → **Tạo**. Trang chi tiết thư viện mới mở ra, và tên thư viện xuất hiện trong danh sách.
2. Tạo một thư viện thứ hai **trùng tên** → hai thẻ trong danh sách có **slug khác nhau** (cái thứ hai
   có đuôi `-2`).
3. Mở thư viện vừa tạo → **Thành viên** → thêm một tài khoản có thật (ví dụ `linh.pham@demo.vn`)
   với vai trò **Người xem** → bảng thành viên hiện ngay dòng đó. Thử lại với chính email đó →
   báo *"đã là thành viên"*; thử với email không tồn tại → *"Không tìm thấy tài khoản với email này"*.
4. Đổi vai trò người đó thành **Biên tập viên** → dòng trong bảng đổi ngay. Gỡ hẳn dòng đó →
   dòng biến mất. (Dòng của chính bạn — chủ sở hữu — **không** bị hạ cấp hay xoá được.)
5. **Nhãn:** ở một thẻ bài viết, sửa ô nhãn (vd `#kiem-thu, snapshot`) → lưu → nhãn hiển thị lại ngay.
   Vào **Tìm kiếm** và lọc theo nhãn vừa đặt → bài đó xuất hiện trong kết quả.
6. **Xuất bản lưu:** mở một bài đã lưu (có ảnh) → bấm **“Xuất bản lưu”** → trình duyệt tải về một
   tệp `.html`. **Tắt mạng** rồi mở tệp đó: nội dung, ảnh và khối nguồn gốc đều hiện đầy đủ.
   (Bấm **“Xuất PDF”** vẫn in bình thường.)
7. **Đổi tên / xoá:** mở **Cài đặt thư viện** của thư viện do bạn làm chủ → đổi tên (slug đổi theo),
   đổi hiển thị, hoặc **Xoá thư viện** (phải gõ lại mã xác nhận). Sau khi xoá, thư viện biến mất
   khỏi danh sách và mọi bài trong đó không còn mở được nữa.

## 7. Cách trải nghiệm nhanh

> Muốn có **kịch bản trình diễn đầy đủ** (từng cú bấm, từng màn hình mong đợi)
> thì đọc [`docs/DEMO.md`](docs/DEMO.md).

1. Chạy `php scripts/setup_db.php` một lần để tạo CSDL (xem mục 1.1), rồi
   `php scripts/migrate_search.php` và `php scripts/reindex_search.php` để có chỉ mục tìm kiếm
   (mục 5.1).
2. Mở `http://localhost:8080` — trang chủ dành cho **khách**.
3. Bấm **Đăng nhập**, dùng `user@demo.vn` / `demo123` → vào `libraries.php`.
4. Mở thư viện **Kho dữ liệu mẫu** → xem thẻ bài viết, bảng thành viên, bảng lưu URL.
5. **Đọc bản đã lưu** → trình đọc nền giấy ngà, thử **A- / A+** và đổi nền đọc.
6. Vào **Tìm kiếm** (`/search.php?q=snapshot`) → xem đoạn trích có từ khoá được làm nổi bật.
7. Mở terminal thứ hai tại thư mục gốc và chạy hai máy chủ phụ:
   ```bash
   php -S localhost:8090 -t tests/fixtures     # các trường hợp lỗi
   php -S localhost:8091 -t tests/samples      # bộ 15 trang mẫu tiếng Việt
   php scripts/seed_samples.php                # nạp bộ mẫu vào kho (idempotent)
   ```
8. Vào **Lưu trang Web** → chọn thư viện và dán URL để thử từng trường hợp:
   - `http://localhost:8090/article.html` → thành công, ảnh tải về `/uploads/snapshots/…`
   - `http://localhost:8090/redir-ok.php` → thành công (đi qua chuyển hướng trong allowlist)
   - `http://localhost:8090/redir-escape.php` → chuyển hướng ra ngoài allowlist → bị chặn
   - `http://localhost:8090/khong-ton-tai.html` → trang gốc trả về 404
   - `http://localhost:8090/slow.php` → hết thời gian chờ (giới hạn 12 s)
   - `http://localhost:8090/notice.html` → nội dung quá ngắn
   - `https://example.com/` → không thuộc danh sách được duyệt
9. Chạy bằng chứng tự động:
   ```bash
   php scripts/run_tests.php                   # 6/6 PASS, mã thoát 0
   ```
10. Đăng xuất, đăng nhập bằng `admin@demo.vn` / `admin123` → thấy mục **Quản trị hệ thống** trong sidebar,
   thêm một tên miền ở `/admin/allowlist.php` và tải lại trang để thấy nó vẫn còn.

---

## 8. Ghi chú thiết kế

- Nguồn thiết kế: `docs/design-reference/DESIGN.md` và 4 màn hình tham chiếu trong cùng thư mục
  (các tệp `code*.html` / `screen*.png` được commit để tiện tra cứu).
- Giao diện dùng **một** stylesheet cục bộ `public/assets/css/app.css` với CSS variables —
  **không dùng** Tailwind CDN hay framework CSS nào.
- Chữ **Inter** (giao diện) và **Merriweather** (trình đọc) tải từ Google Fonts, có phông
  hệ thống dự phòng nên demo vẫn hiển thị tốt khi offline.
- Biểu tượng **Material Symbols** luôn đi kèm nhãn chữ tiếng Việt.
- **Defect đã sửa:** trang chủ trong bộ tham chiếu dùng nhầm header đã đăng nhập. Trang chủ
  là trang dành cho khách, nên dự án này dùng header khách (chỉ có “Đăng nhập” / “Tạo tài khoản”).
  Hai kiểu header không bao giờ trộn lẫn.

---

## 9. Đóng gói bài nộp (Release)

### 9.1 Quy trình tạo Release trên GitHub

Bài nộp lấy từ **Release** của kho Git, không phải từ ZIP tải trực tiếp branch.
Các bước (người dùng tự làm trên website):

1. Đẩy code lên `main` (đã xong ở từng stage):
   ```powershell
   git push origin main
   ```
2. Trên website GitHub → vào kho → tab **Releases** → **Draft a new release**.
3. **Choose a tag**: chọn tag `v1.0.1` (đã tạo sẵn ở bước 9.2). Tên tag khác theo
   hướng dẫn đề tài cũng được, ví dụ `btl-nop-cuoi-ky`.
4. **Target**: `main` (bắt buộc — commit cuối cùng của nhóm).
5. **Release title**: ví dụ `WebArchive — Đề tài 16 (Nhóm 9) — v1.0.1`.
6. **Describe**: dán nội dung tóm tắt, danh sách 5 chức năng, 6 bài kiểm thử T1–T6,
   và các bước cài đặt (mục 1 của README này).
7. Bấm **Publish release**.

### 9.2 Tạo tag `v1.0.1` (KHÔNG dời tag `v1.0.0` cũ)

> Tag `v1.0.0` đang trỏ tới commit cũ `6795727` — **giữ nguyên**, không dời.
> Tag mới `v1.0.1` đặt ở commit cuối cùng sau khi hoàn tất Stage C.

```powershell
git tag -a v1.0.1 -m "WebArchive — Đề tài 16 — bản nộp BTL"
git push origin v1.0.1
```

Kiểm tra:
```powershell
git tag -l -n1              # liệt kê tag
git rev-parse v1.0.0 v1.0.1 # xem hash từng tag
```

### 9.3 Tải và đặt tên tệp ZIP theo đúng quy ước

1. Vào tab **Releases** → bấm **Source code (zip)** của release `v1.0.1`.
2. Tải tệp `WebArchive-1.0.1.zip` về máy.
3. **Đổi tên** thành `BTL_Nhom09.zip` (nhóm 9, theo mẫu tên trong hướng dẫn nộp bài):
   ```powershell
   Rename-Item .\WebArchive-1.0.1.zip BTL_Nhom09.zip
   ```
4. Kiểm tra nội dung ZIP **không** chứa bí mật:
   ```powershell
   # Liệt kê tệp trong ZIP
   Add-Type -AssemblyName System.IO.Compression.FileSystem
   [IO.Compression.ZipFile]::OpenRead("$PWD\BTL_Nhom09.zip").Entries | Select-Object -ExpandProperty FullName
   ```
   Không được có `config/db.local.php`, không có thư mục `.local/`, không có tệp
   `*:Zone.Identifier`, không có `vendor/` hay `node_modules/`.

### 9.4 Kiểm tra gói phát hành bằng `git archive` (không cần mạng)

Lệnh này tạo đúng nội dung mà GitHub sẽ đóng gói cho tag đã chọn:

```powershell
# Tạo gói từ HEAD
git archive --format=zip --output="$env:TEMP\BTL_Nhom09.zip" HEAD

# Liệt kê nội dung
Add-Type -AssemblyName System.IO.Compression.FileSystem
[IO.Compression.ZipFile]::OpenRead("$env:TEMP\BTL_Nhom09.zip").Entries |
  Select-Object -ExpandProperty FullName | Sort-Object
```

Gói phát hành phải **có**: `README.md`, `LICENSE`, `CONTRIBUTING.md`, `docs/`,
`tests/`, `scripts/`, `src/`, `templates/`, `public/`, `database/`.
Gói phát hành phải **không có**: `config/db.local.php`, `.local/`, `*Zone.Identifier`.

### 9.5 Checklist nộp bài

| Mục | Giá trị cần điền |
|-----|-------------------|
| Tên nhóm | `TODO — điền tên nhóm (Nhóm 9)` |
| Tên đề tài | `WebArchive — Lưu trang Web & đọc lại ngoại tuyến (Đề tài 16)` |
| Link Release | `TODO — dán link trang Release trên GitHub` |
| Commit hash đầy đủ | `TODO — dán hash từ lệnh `git rev-parse HEAD` (40 ký tự)` |
| Tệp nộp | `BTL_Nhom09.zip` |
| Thành viên | `TODO — điền ở mục 0.2 (tên + MSSV + phần việc)` |

Lấy hash đầy đủ:
```powershell
git rev-parse HEAD
```
