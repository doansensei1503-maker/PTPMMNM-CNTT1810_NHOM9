# Kịch bản trình diễn — WebArchive

Hai kịch bản chính thức của đề tài, viết theo dạng **click-by-click** để người
thuyết trình làm theo từng bước trên màn hình, hoặc để nhóm người khác tự tay
làm lại và thấy đúng những gì đang mô tả.

**Trước khi bắt đầu** — dựng môi trường:

```bash
# cửa sổ 1 — máy chủ ứng dụng
php -S localhost:8080 -t public

# cửa sổ 2 — máy chủ bộ trang mẫu tiếng Việt
php -S localhost:8091 -t tests/samples
```

```bash
# cửa sổ 3 — nạp dữ liệu mẫu (idempotent, chạy lại nhiều lần đều được)
php scripts/seed_samples.php
```

Trang mẫu phục vụ ở `http://localhost:8091`, trong đó nằm đủ các trường hợp
mà kịch bản dùng tới:

| Tệp | Vai trò trong demo |
|-----|--------------------|
| `bai-01.html` | Bài 1 — bài viết có ảnh, dùng cho cả hai kịch bản |
| `bai-02.html` | Bài 2 — chứa cụm **chỉ có trong thân bài** (dùng để tìm kiếm) |
| `bai-11.html` | Bài 11 — có `<script>`, `onclick`, `<iframe>` (để thấy bộ lọc làm việc) |

Bảng kỳ vọng đầy đủ cho từng tệp nằm ở
[`tests/samples/README.md`](../tests/samples/README.md).

---

## KỊCH BẢN 1 — Lưu một URL, gắn nhãn, đọc bản đã lưu, tìm bằng từ khoá trong thân

**Mục tiêu:** chứng minh quy trình lưu trang là **thật** — ảnh tải về máy chủ
nội bộ, nội dung đọc được ngay, và tìm kiếm quét **thân bài** chứ không chỉ
tiêu đề.

**Chuẩn bị:** đăng nhập `user@demo.vn` / `demo123`.
Tài khoản này đã có sẵn thư viện **“Kho dữ liệu mẫu”**.

---

### Bước 1 — Mở trang lưu trang

1. Mở <http://localhost:8080>
2. Bấm **Đăng nhập** ở góc trên bên phải.
3. Nhập `user@demo.vn` / `demo123`, bấm **Đăng nhập**.

**Màn hình mong đợi:** bạn ở `/libraries.php`, thấy thẻ thư viện
**“Kho dữ liệu mẫu”**.

---

### Bước 2 — Vào thư viện và bấm “Lưu trang Web”

1. Bấm vào thẻ **Kho dữ liệu mẫu** để mở `/library.php?slug=kho-du-lieu-mau`.
2. Bấm nút **Lưu trang Web** trên thanh công cụ.

**Màn hình mong đợi:** biểu mẫu **Lưu trang Web** — có ô **URL**, danh sách
thư viện (chọn sẵn “Kho dữ liệu mẫu”) và ô **Nhãn**.

---

### Bước 3 — Dán URL và gắn nhãn

1. Xoá sẵn nội dung ô URL, dán:
   ```
   http://localhost:8091/bai-01.html
   ```
2. Bấm vào ô **Nhãn**, gõ:
   ```
   php, bai-hoc
   ```
   (mỗi nhãn cách nhau bằng dấu phẩy)
3. Bấm **Lưu trang**.

**Màn hình mong đợi:** chuyển thẳng sang **trình đọc ngoại tuyến**
`/reader.php?id=…`.

---

### Bước 4 — Kiểm tra bản đã lưu là “tự đủ”

Trên màn hình trình đọc, kiểm tra từng điểm:

| Chỗ cần nhìn | Mong đợi thấy |
|---------------|--------------|
| Khối **Tiêu đề bài viết** | `Bài 1: Nền tảng PHP cho lập trình viên` |
| Dòng **URL nguồn** | `http://localhost:8091/bai-01.html` |
| Dòng **Nhãn Phiên bản** | `Phiên bản 1 · SNAP-…` |
| Dòng **Mã HTTP lần tải** | `200` |
| Dòng **Dung lượng** | có ghi số **ảnh đã tải về máy chủ** (bài này có 1 ảnh) |
| Khối **Lịch sử phiên bản** | `1 bản thành công` |
| Thân bài viết | tiêu đề, các đoạn văn, **ảnh hiển thị bằng** |
| Cuối bài | khối **Ghi chú của phiên bản 1** với ô ghi chú trống |

**Chứng minh ảnh đã tải về máy chủ nội bộ:** nhấp chuột phải vào ảnh trong bài
→ **Mở ảnh trong tab mới**. Địa chỉ ảnh phải là đường dẫn **nội bộ** của
WebArchive (`/uploads/snapshots/<id>/…`), **không phải** URL ở `localhost:8091`.

> Nếu vẫn là URL gốc, nghĩa là ảnh chưa được tải về — báo lại, đó là lỗi.

---

### Bước 5 — Thử điều khiển của trình đọc

1. Bấm **A+** vài lần → chữ lớn dần. Bấm **A-** → chữ nhỏ lại.
2. Đổi nền đọc: **Trắng tinh**, rồi **Banêm**.

**Màn hình mong đợi:** cột đọc đổi nền, cỡ chữ đổi theo — bản lưu đọc được
thoải mái **không cần mạng**.

---

### Bước 6 — Tìm bằng từ khoá **chỉ có trong thân bài**

Đây là điểm mấu chốt của kịch bản: từ khoá dưới đây **không hề xuất hiện trong
tiêu đề** của bài nào.

1. Quay lại `/libraries.php` (nút **Quay lại thư viện**).
2. Vào **Tìm kiếm** ở thanh bên (hoặc mở thẳng `/search.php`).
3. Gõ vào ô tìm kiếm:
   ```
   chuan bi that
   ```
4. Bấm **Tìm**.

**Màn hình mong đợi:**

- Dòng đếm: **Tìm thấy 1 kết quả**.
- Một thẻ kết quả:
  - tiêu đề **Bài 2: Truy vấn MySQL và chỉ mục bảng**
  - thẻ chip **Phiên bản 1**
  - thẻ chip miền nguồn **localhost**
  - thẻ **Điểm 7**
  - đoạn trích có **`<mark>` bọc quanh “chuẩn bị thật”** — văn bản hiển thị
    **giữ nguyên dấu** dù bạn gõ không dấu.

> Hai bài 2 (`bai-02.html`) đã được `seed_samples.php` nạp sẵn, nên không cần
> lưu lại ở bước này. Nếu thư viện của bạn chưa có, quay lại **Bước 3** và
> lưu `http://localhost:8091/bai-02.html`.

---

### Bước 7 — Xem các từ khoá khác hoạt động

Gõ thử lần lượt và ghi nhận:

| Gõ vào ô tìm kiếm | Mong đợi |
|--------------------|---------|
| `git` | 1 kết quả — **Bài 6: Git…**, điểm 8 (khớp tiêu đề được nhân 3) |
| `dns` | 3 kết quả — **Bài 9** (có `DNS` trong **tiêu đề**) đứng trước **Bài 5** (`DNS` chỉ trong thân) |
| `hopdongapi` | 2 kết quả — hai trang có **cùng một tiêu đề** (`bai-01.html` và `bai-07.html`) |
| `cong nghe luong` | **0 kết quả**, kèm câu giải thích đúng nghĩa VÀ |

Mười câu hỏi đầy đủ kèm kết quả mong đợi **và** kết quả thực tế nằm ở
[`docs/SEARCH_QUERIES.md`](SEARCH_QUERIES.md).

---

### Bước 8 — Thấy bộ lọc nội dung chạy đúng

Lưu trang chứa mã độc rồi mở ra đọc:

1. Vào **Lưu trang Web**, dán `http://localhost:8091/bai-11.html`, gắn nhãn
   `security`, bấm **Lưu trang**.
2. Trình đọc mở ra.

**Màn hình mong đợi:** bài hiển thị bình thường, **không có** hiệu tượng gì lạ.
Trong bài gốc có `<script>`, một `onclick` và một `<iframe>` — nhưng trong bản lưu
**cả ba đều đã bị gỡ bằng bộ lọc whitelist**.

> Nếu bản lưu còn `<script>` hoặc `onclick`, đó là lỗi nghiêm trọng — báo lại
> ngay (xem `T4` trong `scripts/run_tests.php`).

---

## KỊCH BẢN 2 — Nguồn đã đổi hoặc mất, đọc bản cũ, lấy phiên bản mới, so sánh, xuất

**Mục tiêu:** chứng minh bản lưu **độc lập với máy chủ gốc**, có **lịch sử
phiên bản** và **ghi chú riêng cho từng phiên bản**, rồi **xuất** ra tệp HTML
độc lập.

**Chuẩn bị:** đăng nhập `user@demo.vn` / `demo123`.

---

### Bước 1 — Tạo thư viện riêng cho demo

1. Vào `/libraries.php`.
2. Bấm **Tạo thư viện**.
3. Nhập:
   - Tên: `Demo nguồn đã đổi`
   - Mô tả: `Kịch bản 2 — bản lưu sống sót khi trang gốc thay đổi`
   - Mức hiển thị: **Chỉ mình tôi**
4. Bấm **Tạo**.

**Màn hình mong đợi:** thông báo xanh *"Đã tạo thư viện…"* và bạn ở
trang chi tiết thư viện vừa tạo.

---

### Bước 2 — Lưu phiên bản thứ nhất

1. Bấm **Lưu trang Web**.
2. Dán URL:
   ```
   http://localhost:8091/bai-05.html
   ```
3. Gắn nhãn: `linux, quan-tri`
4. Bấm **Lưu trang**.

**Màn hình mong đợi:** trình đọc mở ra, khối **Lịch sử phiên bản** ghi
**`1 bản thành công`**, nhãn **Phiên bản 1**.

---

### Bước 3 — Ghi chú cho phiên bản 1

1. Kéo xuống cuối bài, tới khối **Ghi chú của phiên bản 1**.
2. Gõ:
   ```
   Bản lưu lúc 9h sáng — còn đầy đủ, dùng làm căn cứ đối chiếu.
   ```
3. Bấm **Ghi chú**.

**Màn hình mong đợi:** dòng thông báo xanh ở đầu trang, và ghi chú của bạn hiện
trong danh sách, ghi rõ tên tác giả và thời điểm.

> Ghi chú thuộc **đúng phiên bản này**. Sang phiên bản 2, ghi chú này sẽ
> không xuất hiện — và đó là chủ ý.

---

### Bước 4 — Thay đổi ở trang gốc (mô phỏng nguồn đã đổi)

Mở **cửa sổ terminal thứ hai** tại thư viện `tests/samples`:

```bash
notepad tests\samples\bai-05.html
```

Thêm một câu đánh dấu vào giữa phần `<article>` (ngay trước thẻ `</article>`):

```html
  <p>CauHienThiBanMoi — nguồn đã được cập nhật lúc 10h.</p>
```

Lưu tệp (`Ctrl+S`) rồi đóng. **Không cần khởi động lại** máy chủ mẫu — PHP
đọc tệp ở mỗi lần có yêu cầu.

---

### Bước 5 — Đọc bản lưu khi **nguồn đã chết**

Trước khi tải phiên bản mới, hãy chứng minh bản lưu tự đủ. **Tắt máy chủ mẫu**:

1. Chuyển sang cửa sổ terminal đang chạy `php -S localhost:8091`.
2. Nhấn `Ctrl + C`.
3. Quay lại trình duyệt, bấm **Tải lại trang** (`F5`).

**Màn hình mong đợi:** trang báo **không kết nối được** — nhưng đó là bản gốc.
Nếu bạn **gõ lại** URL `http://localhost:8091/bai-05.html` vào thanh địa chỉ
thì trình duyệt báo lỗi mạng. Còn bản đã lưu thì:

1. Quay lại `/libraries.php` → thư viện **Demo nguồn đã đổi** → bấm vào bài
   **Bài 5: Dòng lệnh Linux…**

**Màn hình mong đợi:** **trình đọc vẫn mở ra bình thường** — đầy đủ tiêu đề,
thân bài, ảnh, bảng. Ảnh cũng hiện, vì ảnh đã nằm trên máy chủ nội bộ.

> Đây chính là ý nghĩa của “lưu trang”: bản đọc được **không phụ thuộc nguồn**.

---

### Bước 6 — Lấy phiên bản mới

1. Khởi động lại máy chủ mẫu:
   ```bash
   php -S localhost:8091 -t tests/samples
   ```
2. Trong trình đọc, bấm nút **Lấy phiên bản mới** (ở khối *Lịch sử phiên bản*).

**Màn hình mong đợi:**

- Thông báo xanh: đã lấy được phiên bản mới.
- Nhãn chip đổi thành **Phiên bản 2 · SNAP-…** (mã SNAP khác hẳn).
- Khối **Lịch sử phiên bản** giờ ghi **`2 bản thành công`**, liệt kê cả hai,
  **bản 2 được tô đậm** là “đang xem”.
- Thân bài **có** câu `CauHienThiBanMoi — nguồn đã được cập nhật lúc 10h.`
- Khối ghi chú bên dưới ghi **“Ghi chú của phiên bản 2”** và báo **chưa có ghi
  chú nào** — ghi chú của bài 1 **không** nhảy sang đây.

---

### Bước 7 — So sánh hai phiên bản

1. Bấm **Phiên bản 1** trong khối *Lịch sử phiên bản*.

**Màn hình mong đợi:**

- Nhãn chip đổi về **Phiên bản 1**, dòng “đang xem” chuyển sang bản 1.
- Thân bài **không có** câu `CauHienThiBanMoi` — bản 1 là bản **trước khi** sửa.
- Ghi chú **“Bản lưu lúc 9h sáng…”** của bài 1 **vẫn còn nguyên**.
- Khối *Lịch sử phiên bản* vẫn liệt kê đủ hai bản — bạn đang ở bản 1, đã lưu
  được cả hai nơi.

| So sánh | Nơi xem |
|---------|---------|
| Số phiên bản | Khối *Lịch sử phiên bản* |
| Mã `SNAP-…` | Chip *Phiên bản N* và dòng “Nhãn Phiên bản” trong khối *Đầu nguồn gốc* |
| Thời điểm tải | Cột phải mỗi dòng phiên bản + dòng *Thời điểm tải* |
| Mã HTTP | Dòng *Mã HTTP lần tải* |
| Nội dung | Thân bài |
| Ghi chú | Khối *Ghi chú của phiên bản N* |

---

### Bước 8 — Xóa dấu thay đổi ở nguồn (không bắt buộc)

Mở lại `notepad tests\samples\bai-05.html`, **xoá** dòng
`<p>CauHienThiBanMoi …</p>` rồi lưu. Bản lưu trong WebArchive **không bị ảnh
hưởng** — đó cũng là điểm: bản đã lưu là một bản sao cố định theo thời điểm.

---

### Bước 9 — Xuất bản lưu ra tệp độc lập

1. Ở trình đọc, bấm nút **Xuất bản lưu** (góc trên bên phải).
2. Trình duyệt tải về một tệp `.html`.

**Mong đợi:** mở tệp vừa tải bằng **File Explorer** (nhấp đúp, không cần máy
chủ).

- Bài hiển thị đầy đủ, **ảnh vẫn hiện** vì đã được nhúng thành `base64` ngay
  trong tệp.
- Tắt mạng / mở tệp bằng trình soạn văn bản → nội dung vẫn còn nguyên.

Đây là bằng chứng rõ nhất cho câu “đọc ngoại tuyến”: một tệp HTML đơn lẻ, tự
chứa toàn bộ.

---

### Bước 10 — Dọn dẹp (tuỳ chọn)

Vào thư viện **Demo nguồn đã đổi** → bấm **Xoá thư viện** → xác nhận.

**Màn hình mong đợi:** thư viện biến mất khỏi danh sách, và **thư mục ảnh** của
thư viện đó cũng bị dọn theo. Hành động xoá thư viện của ứng dụng luôn dọn cả
thư mục ảnh — **không bao giờ xoá tay tệp trong `public/uploads/`**.

---

## Phụ lục — Chạy lại bằng bộ kiểm thử tự động

Hai kịch bản trên được **đóng gói thành sáu bài kiểm thử tự động**:

```bash
php scripts/run_tests.php
```

| Mã | Bài kiểm thử | Gắn với kịch bản |
|----|---------------|-------------------|
| `T1` | Nguồn đã chết — bản lưu vẫn đọc được kèm ảnh | Kịch bản 2, bước 5 |
| `T2` | Tìm bằng cụm chỉ có trong thân, có `<mark>` | Kịch bản 1, bước 6 |
| `T3` | Ba trường hợp lỗi có ba mã lỗi khác nhau | Nền |
| `T4` | Mã độc không chạy được trong bản lưu | Kịch bản 1, bước 8 |
| `T5` | Tải lại tạo v2, v1 giữ nguyên + ghi chú còn | Kịch bản 2, bước 6–7 |
| `T6` | Người ngoài không mở được, chủ sở hữu thì được | Nền |

Bộ kiểm thử **tự bật máy chủ riêng** của nó, tự dọn sạch dữ liệu tạm bằng chính
hành động của ứng dụng, và trả về mã thoát `0` khi cả sáu bài PASS.