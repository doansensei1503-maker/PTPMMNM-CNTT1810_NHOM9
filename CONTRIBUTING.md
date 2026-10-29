# Đóng góp cho WebArchive

Cảm ơn bạn đã quan tâm tới WebArchive! Tài liệu này nói rõ cách dựng môi trường
để chạy thử, các quy ước bắt buộc của dự án, và cách nhóm làm việc với issue
và pull request trên GitHub.

---

## 1. Yêu cầu

| Thành phần | Phiên bản |
|------------|-----------|
| PHP | **8.3** (khuyến nghị), chạy được từ 8.0 |
| MySQL | **Server 8.0** trở lên |

Extension PHP bắt buộc (bật trong `php.ini`):

```
pdo_mysql, mysqli, curl, dom, fileinfo, openssl, mbstring
```

> `mbstring` **rất quan trọng** với tiếng Việt. Dự án có sẵn polyfill nhỏ cho
> `mb_*` khi thiếu extension, nhưng tìm kiếm và bỏ dấu sẽ chậm và dễ sai hơn —
> hãy bật `mbstring` thật.

**Không dùng Composer.** Dự án là PHP thuần: không `vendor/`, không build step,
không framework. Đổ bao nhiêu tệp cũng chạy được ngay.

---

## 2. Dựng từ đầu

```bash
git clone <url-của-repo> webarchive
cd webarchive

# 1) Tạo CSDL + tài khoản MySQL riêng cho ứng dụng (chạy MỘT LẦN)
export WA_ADMIN_USER='root'
export WA_ADMIN_PASS='<mat-khau-quan-tri-mysql-cua-ban>'
php scripts/setup_db.php

# 2) Tạo bảng chỉ mục tìm kiếm (idempotent)
php scripts/migrate_search.php

# 3) Lập chỉ mục cho các snapshot có sẵn trong seed (idempotent)
php scripts/reindex_search.php

# 4) Chạy ứng dụng
php -S localhost:8080 -t public
```

Mở <http://localhost:8080> và đăng nhập bằng `user@demo.vn` / `demo123`.

> `setup_db.php` là script **duy nhất** được phép chạy `CREATE DATABASE` và nó
> cần mật khẩu quản trị MySQL qua biến môi trường — **đừng ghi mật khẩu đó vào
> mã nguồn, tin nhắn hay issue**. Mọi thao tác khác (lưu trang, tìm kiếm, dọn
> dữ liệu) đều dùng tài khoản ứng dụng trong `config/db.local.php`, tệp này
> được `.gitignore` và **không bao giờ commit**.

### Máy chủ phụ để thử lưu trang

```bash
# Thử các trường hợp lỗi (redirect, timeout, 404, nội dung rỗng)
php -S localhost:8090 -t tests/fixtures

# Bộ 15 trang mẫu tiếng Việt (dùng cho tìm kiếm và cho kịch bản demo)
php -S localhost:8091 -t tests/samples
```

Nạp bộ mẫu vào thư viện “Kho dữ liệu mẫu”:

```bash
php scripts/seed_samples.php     # idempotent: chạy lại sẽ in "already seeded"
```

### Chạy bộ kiểm thử bắt buộc

```bash
php scripts/run_tests.php
```

Bộ kiểm thử **tự khởi động máy chủ riêng** của nó (cổng `18080` và `18091`) nên
không xung đột với các máy chủ bạn đang chạy. Nó tự dọn thư viện và tài khoản
tạm bằng chính hành động của ứng dụng. Mã thoát `0` nghĩa là cả 6 bài PASS.

---

## 3. Quy ước của dự án

### 3.1 PHP thuần, không phụ thuộc

- Không thêm thư viện ngoài, không thêm `composer.json`.
- Không thêm tính năng mới ngoài phạm vi đề tài. Sửa lỗi thì được, nhưng phải
  nói rõ trong pull request.

### 3.2 Chỉ dùng prepared statement

```php
// Đúng
$stmt = db()->prepare('SELECT id FROM `users` WHERE email = ? AND id = ?');
$stmt->execute([$email, $id]);

// Sai — không bao giờ
$rows = db()->query("SELECT * FROM users WHERE email = '$email'");
```

> **Lưu ý quan trọng:** PDO được cấu hình
> `PDO::ATTR_EMULATE_PREPARES = false`, nên MySQL thật sự kiểm tra. Một tham số
> có tên **không được xuất hiện hai lần trong cùng một câu lệnh** (sẽ sinh
> `SQLSTATE[HY093]`). Khi cùng một giá trị cần dùng ở hai chỗ, hãy tạo **hai tên
> tham số khác nhau**:

```php
// Sai — :term xuất hiện 2 lần
'... WHERE t IN (:term) ... 3 * SUM(CASE WHEN x = :term THEN 1 END) ...'

// Đúng
'... WHERE t IN (:match_term_0) ... 3 * SUM(CASE WHEN x = :score_term_0 THEN 1 END) ...'
$bind[':match_term_0'] = $term;
$bind[':score_term_0'] = $term;
```

Có ví dụ thật trong `wa_search_snapshots()` (`src/search.php`).

### 3.3 Mọi biến vào CSDL phải có hành động thật

Không `UPDATE`/`DELETE` bảng trực tiếp từ trang web. Mọi thay đổi đi qua một
điểm nhận POST duy nhất theo mẫu `library_action.php` / `refetch.php` / `note.php`:

1. `require_login()` — khách thì chuyển về `login.php`.
2. `wa_csrf_check()` — token sai thì từ chối **trước khi** đọc/ghi bất cứ thứ gì.
3. Phân quyền **ép lần nữa trong SQL**, không chỉ ở giao diện.

Sau khi xử lý, trang điểm nhận này **không vẽ giao diện**: đặt `$_SESSION['wa_flash']`
rồi `header('Location: …')` về đúng trang đã gọi, kèm thông báo tiếng Việt.

### 3.4 CSRF trên mọi biểu mẫu POST

```php
<form method="post" action="<?= wa_e(wa_url('note.php')) ?>">
    <?= wa_csrf_field() ?>
    …
</form>
```

Tải tệp qua **GET** (`export.php?id=…`) không cần CSRF vì không thay đổi dữ liệu.

### 3.5 Toàn bộ chữ giao diện bằng tiếng Việt

- Mọi thông báo, nhãn nút, thông báo lỗi và trang lỗi đều **tiếng Việt**.
- Comment trong mã cũng viết bằng tiếng Việt, giải thích **vì sao** chứ không
  phải **cái gì** — đọc code phải hiểu được quyết định.
- Không để chuỗi tiếng Anh lọt vào giao diện.

### 3.6 Escape mọi thứ đọc từ CSDL

```php
<?= wa_e($article['title']) ?>
```

Chỉ có ngoại lệ: đoạn trích tìm kiếm đã được `wa_search_snippet()` escape và
bọc `<mark>` sẵn, nên in thẳng, tuyệt đối không escape lần hai.

### 3.7 Một đường lưu trữ dùng chung

Mọi thao tác ghi vào kho đi qua **một** hàm: `wa_store_page_capture()` trong
`src/queries.php`. Cả `save.php` lẫn `refetch.php` đều gọi hàm này, nên quy tắc
phiên bản, lập chỉ mục tìm kiếm và tải ảnh không bao giờ lệch nhau.

### 3.8 Tuyệt đối không xoá tay tệp trong `public/uploads/`

Đã có một lần cleanup trong P-LIB xoá nhầm ảnh thật. Từ đó:

- **Không** dùng `rm`, `del`, hay `unlink()` lên bất kỳ tệp nào trong
  `public/uploads/`.
- Muốn dọn ảnh thì gọi hành động **xoá thư viện** của ứng dụng — hàm đó tự dọn
  cả thư mục ảnh theo đúng phạm vi.
- Dữ liệu kiểm thử phải được dọn **qua chính hành động của ứng dụng**.

### 3.9 Allowlist kiểm trước, kiểm lại sau mỗi lần chuyển hướng

Không gửi byte nào tới host ngoài allowlist. cURL không tự đi theo redirect; ứng
dụng tự đi tối đa 5 bước và **kiểm tra lại allowlist ở từng đích** trước khi tải.
Lệch allowlist → `error_blocked`, không gửi request nào.

### 3.10 Bỏ dấu tiếng Việt và byte/ký tự

Hai chỗ dễ sai, đã từng gây lỗi thật — hãy đọc kỹ:

- `preg_match_all(..., PREG_OFFSET_CAPTURE)` trả về vị trí **byte**.
  `mb_substr()` nhận vị trí **ký tự**. Quy đổi bằng
  `mb_strlen(substr($text, 0, $byteOffset), 'UTF-8')`, **đúng một lần**.
- Khi cắt chuỗi theo ký tự rồi cần quy đổi ngược về byte để so khớp, dùng
  `strlen(mb_substr($text, 0, $startCh, 'UTF-8'))`.

Bảng bỏ dấu dùng chung là `wa_vi_fold_map()` — **đừng tạo bảng thứ hai**.

### 3.11 Thêm tính năng mới thì kèm bằng chứng

Mỗi thay đổi hành vi phải kèm một trong hai:

- Cập nhật `tests/samples/README.md` hoặc `docs/SEARCH_QUERIES.md` nếu đổi dữ liệu mẫu.
- Chạy `php scripts/run_tests.php` và dán nguyên văn kết quả vào pull request.

### 3.12 Tiến trình con trong kịch bản CLI (Windows)

`scripts/run_tests.php` bật hai máy chủ `php -S` riêng. Trên **Windows**, `proc_open()`
với lệnh dạng **chuỗi** sẽ chạy qua `cmd.exe`, nên `proc_terminate()` chỉ giết vỏ `cmd`
mà **không** giết được `php.exe` — mỗi lần chạy để lại tiến trình mồ côi.

Quy ước bắt buộc khi viết kịch bản CLI:

- Truyền lệnh dạng **mảng** cho `proc_open()`: `proc_open([$php, '-S', $port, ...], ...)`.
  PHP tự chạy tiến trình trực tiếp, không qua vỏ, nên `proc_terminate()` giết đúng tiến trình.
- Sau khi `proc_terminate()`, **kiểm tra lại** bằng `proc_get_status()`; nếu tiến trình
  vẫn còn thì dự phòng bằng `taskkill /F /T /PID <pid>` (chỉ trên Windows).
- **Không được âm thầm dùng lại** một cổng đã bị chiếm: nếu cổng 18080/18091 đang có
  tiến trình khác lắng nghe thì báo lỗi tiếng Việt và dừng. Thăm dò cổng thành công
  **không** đồng nghĩa với "máy chủ của tôi đã sẵn sàng".
- Luôn đặt `register_shutdown_function()` để dọn tiến trình con, kể cả khi script bị
  ngắt giữa chừng.

---

## 4. Quy trình làm việc với GitHub

### Issue

Trước khi mở issue, tìm xem đã có ai hỏi chưa. Một issue tốt có:

1. **Tiêu đề** mô tả đúng triệu chứng.
2. **Bước tái hiện** — từng bước, có thể copy chạy lại được.
3. **Kết quả thực tế vs. kết quả mong đợi**.
4. **Môi trường**: hệ điều hành, `php -v`, phiên bản MySQL.
5. Nếu có lỗi trình duyệt, ghi kèm ảnh chụp màn hình.

Nhóm sẽ phản hồi trong khoảng một tuần. Lỗi bảo mật: **đừng** mở issue công
khai — báo riêng cho nhóm trước.

### Pull request

1. **Một PR = một việc.** Đừng gộp sửa lỗi với đổi giao diện.
2. Tạo nhánh từ `main`:
   ```bash
   git checkout -b fix/mo-ta-tieu-de-tim-kiem
   ```
3. Tiêu đề PR viết bằng tiếng Việt, theo mẫu: `sửa: …`, `thêm: …`, `tài liệu: …`,
   `dọn: …`.
4. Mô tả PR phải có:
   - **Vấn đề** — bối cảnh và vì sao cần sửa.
   - **Cách sửa** — ngắn gọn.
   - **Bằng chứng** — ảnh chụp màn hình hoặc dán output của `run_tests.php`.
   - **Mức độ rủi ro** — có đụng CSDL hay hành động xoá tệp không.
5. Tự kiểm trước khi mở PR:
   ```bash
   php -l <các-tệp-đã-sửa>
   php scripts/run_tests.php     # phải 6/6 PASS, mã thoát 0
   ```
6. Không commit `config/db.local.php`, ảnh trong `public/uploads/`, hay tệp trong `.local/`.

### Về Pull Request

- Ít nhất **một** người trong nhóm review và approve trước khi merge.
- Nhận xét phải nêu **lý do**, không chỉ "sửa đi". Nếu bị từ chối, giải thích
  được bằng lý do thì đó là một trao đổi bình thường.
- Thay đổi **UI** cần kèm ảnh chụp trước/sau.
- Thay đổi **CSDL** phải kèm câu lệnh `ALTER TABLE` (hoặc script migration mới)
  trong mô tả PR.
- Merge bằng **squash** để lịch sử gọn.

### Quy tắc nhánh

| Nhánh | Nội dung |
|-------|----------|
| `main` | Luôn chạy được, luôn 6/6 PASS |
| `fix/<mô-tả>` | Sửa lỗi |
| `feat/<mô-tả>` | Thêm tính năng trong phạm vi đề tài |
| `docs/<mô-tả>` | Chỉ tài liệu |

---

## 5. Báo cáo lỗi bảo mật

Nếu bạn phát hiện lỗi bảo mật — đặc biệt là lỗi liên quan đến việc một người
đọc được bản lưu của thư viện không thuộc về họ — **đừng mở issue công khai**.
Hãy báo cho nhóm qua kênh riêng. Nhóm sẽ ghi nhận, vá và công bố sau khi đã
sửa xong.

Cảm ơn bạn đã đóng góp!