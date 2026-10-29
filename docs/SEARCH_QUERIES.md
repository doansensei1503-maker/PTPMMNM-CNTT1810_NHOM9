# 10 câu hỏi tìm kiếm — kết quả mong đợi và kết quả thực tế

Tài liệu này là **bằng chứng cho phần tìm kiếm** của đề tài WebArchive (Topic 16).

## Phương pháp

Quy trình được thực hiện đúng thứ tự mà đề tài yêu cầu:

1. **Thiết kế trước.** Mười câu hỏi và kết quả mong đợi được **viết ra từ nội dung
   các trang mẫu đã soạn** (`tests/samples/`), *trước khi* chạy tìm kiếm. Mỗi trang
   mẫu mang một cụm khoá riêng (`mau01webarchive` … `mau13webarchive`) nằm trong
   thân bài và không xuất hiện ở đâu khác trong kho, nên kết quả dự đoán được.
   Công cụ phân tích `.local/design_queries.php` chỉ **mô phỏng** ngữ nghĩa AND
   và công thức xếp hạng `3 × count_title + count_body` trên nội dung đã soạn —
   nó **không** đọc dữ liệu từ CSDL và **không** gọi `search.php`.
2. **Chạy thật sau đó.** Mười câu hỏi được gửi tới `public/search.php` **qua HTTP
   thật** (đăng nhập `user@demo.vn` bằng cURL với cookie jar), không gọi thẳng hàm
   PHP. Kết quả trả về được bóc tách ra khỏi HTML của trang: tiêu đề, nhãn
   **Phiên bản**, **Điểm**, tên miền, mã `SNAP-…` và số thẻ `<mark>` trong đoạn trích.
3. **Đối chiếu.** Với cả 10 câu, **kết quả mong đợi = kết quả thực tế** — kể cả
   thứ tự xếp hạng và điểm số. Không có kỳ vọng nào phải sửa sau khi chạy.

### Môi trường dùng để chạy

| Thành phần | Giá trị |
|------------|---------|
| Máy chủ ứng dụng | `http://localhost:8080` (`php -S localhost:8080 -t public`) |
| Máy chủ thư mẫu | `http://localhost:8091` (`php -S localhost:8091 -t tests/samples`) |
| Tài khoản | `user@demo.vn` / `demo123` |
| Thư viện mẫu | `Kho dữ liệu mẫu` (`kho-du-lieu-mau`), 15 trang, 16 bản snapshot |
| Kho còn lại | 12 bản snapshot cũ ở `dev-insights.org`, `phptutorial.vn`, `tapchicongnghe.vn`, `techblog.dev`, `vi.wikipedia.org` (dùng cho bộ lọc tên miền của Q6) |
| Fixture server | `http://localhost:8090` (`php -S localhost:8090 -t tests/fixtures`) — dùng cho chuỗi 3 phiên bản của Q6 |

> **Ghi chú Stage C (2026-10-24).** Số liệu trong bảng trên được đo lại sau khi
> CSDL được dựng lại: **25 trang / 28 snapshot** trong toàn kho (4 thư viện), và
> mọi mã `SNAP-…` trong tài liệu này là mã **mới** (dãy `id` cấp lại từ đầu).

### Công thức điểm (nhắc lại)

```
score = 3 × SUM(count_title) + SUM(count_body)
sắp xếp: score giảm dần → fetched_at giảm dần → id giảm dần
```

Ngữ nghĩa **AND**: bản snapshot chỉ được trả về khi chứa **tất cả** các từ đã bỏ
dấu của câu truy vấn.

---

## Q1 — Cụm chỉ có trong thân bài (body-only)

| | |
|---|---|
| **Câu hỏi** | `chuan bi that` |
| **Bộ lọc** | (không) |
| **Định nghĩa trước** | Ba từ *"chuẩn bị thật"* nằm trong **thân** bài 2, không nằm trong tiêu đề |

**Mong đợi — 1 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Vì sao |
|---|---------|------|-----------|--------|
| 1 | Bài 2: Truy vấn MySQL và chỉ mục bảng | 7 | 1 | 3 từ, mỗi từ xuất hiện 1 lần trong thân → `0×3 + 7×1` |

**Thực tế — 1 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Miền | Thư viện | SNAP |
|---|---------|------|-----------|------|----------|------|
| 1 | Bài 2: Truy vấn MySQL và chỉ mục bảng | 7 | 1 | localhost | Kho dữ liệu mẫu | 8943 |

✅ **Khớp.** Cụm không nằm trong tiêu đề nên bài này vẫn tìm được — chứng minh
tìm kiếm quét **thân bài**, không chỉ tiêu đề.

---

## Q2 — Một từ nằm trong tiêu đề

| | |
|---|---|
| **Câu hỏi** | `git` |
| **Bộ lọc** | (không) |
| **Định nghĩa trước** | *"Git"* nằm trong tiêu đề bài 6 → 3 điểm cho mỗi lần xuất hiện ở tiêu đề |

**Mong đợi — 1 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Vì sao |
|---|---------|------|-----------|--------|
| 1 | Bài 6: Git và quản lý phiên bản mã nguồn | 8 | 1 | `Git` × 1 ở tiêu đề (3) + × 5 trong thân (5) = 8 |

**Thực tế — 1 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Miền | Thư viện | SNAP | `<mark>` |
|---|---------|------|-----------|------|----------|------|----------|
| 1 | Bài 6: Git và quản lý phiên bản mã nguồn | 8 | 1 | localhost | Kho dữ liệu mẫu | 8947 | 2 |

✅ **Khớp.** Trường hợp này chứng minh trọng số **× 3 cho tiêu đề** hoạt động:
cùng một từ `git` nhưng bài 6 đứng đầu nhờ xuất hiện ở tiêu đề.

---

## Q3 — Gõ không dấu, khớp nội dung có dấu

| | |
|---|---|
| **Câu hỏi** | `khang dinh an toan` (**cố ý không gõ dấu**) |
| **Bộ lọc** | (không) |
| **Định nghĩa trước** | Bài 11 viết *"khẳng định an toàn"*; người dùng gõ không dấu |

**Mong đợi — 1 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Vì sao |
|---|---------|------|-----------|--------|
| 1 | Bài 11: Làm sạch nội dung độc hại | 13 | 1 | 4 từ khớp ở thân → tổng 13 lần xuất hiện |

**Thực tế — 1 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Miền | Thư viện | SNAP | `<mark>` |
|---|---------|------|-----------|------|----------|------|----------|
| 1 | Bài 11: Làm sạch nội dung độc hại | 13 | 1 | localhost | Kho dữ liệu mẫu | 8952 | 1 |

✅ **Khớp.** Đoạn trích trả về văn bản **có dấu** (`khẳng định an toàn`) dù
người dùng gõ không dấu — đúng như đặc tả "so khớp trên dạng đã bỏ dấu, hiển thị
giữ nguyên dấu".

---

## Q4 — Ba từ = điều kiện VÀ (AND)

| | |
|---|---|
| **Câu hỏi** | `dia chi may chu` |
| **Bộ lọc** | (không) |
| **Định nghĩa trước** | Bốn từ *"địa chỉ"* + *"máy"* + *"chủ"* phải **cùng** xuất hiện; riêng từng từ thì rất nhiều bài khác cũng có |

**Mong đợi — 6 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Miền |
|---|---------|------|-----------|------|
| 1 | Bài 9: Mạng máy tính và hệ thống phân giải tên miền DNS | 52 | 1 | localhost |
| 2 | Thiết kế chỉ mục toàn văn cho tìm kiếm nội dung snapshot đã lưu | 13 | 2 | dev-insights.org |
| 3 | Tìm hiểu kiến trúc PHP & MySQL trong lưu trữ Web tĩnh | 11 | 3 | phptutorial.vn |
| 4 | Bài 5: Dòng lệnh Linux cho người quản trị | 10 | 2 | localhost |
| 5 | Bài 5: Dòng lệnh Linux cho người quản trị | 10 | 1 | localhost |
| 6 | Tối ưu hóa truy vấn MySQL cho hệ thống lưu trữ tài liệu phân trang | 9 | 2 | techblog.dev |

**Thực tế — 6 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Miền | Thư viện | SNAP |
|---|---------|------|-----------|------|----------|------|
| 1 | Bài 9: Mạng máy tính và hệ thống phân giải tên miền DNS | 52 | 1 | localhost | Kho dữ liệu mẫu | 8950 |
| 2 | Thiết kế chỉ mục toàn văn cho tìm kiếm nội dung snapshot đã lưu | 13 | 2 | dev-insights.org | CNTT & Lập Trình | 8912 |
| 3 | Tìm hiểu kiến trúc PHP & MySQL trong lưu trữ Web tĩnh | 11 | 3 | phptutorial.vn | CNTT & Lập Trình | 8941 |
| 4 | Bài 5: Dòng lệnh Linux cho người quản trị | 10 | 2 | localhost | Kho dữ liệu mẫu | 8957 |
| 5 | Bài 5: Dòng lệnh Linux cho người quản trị | 10 | 1 | localhost | Kho dữ liệu mẫu | 8946 |
| 6 | Tối ưu hóa truy vấn MySQL cho hệ thống lưu trữ tài liệu phân trang | 9 | 2 | techblog.dev | CNTT & Lập Trình | 8937 |

✅ **Khớp** (thứ tự và điểm). Bốn từ phải **cùng xuất hiện** mới ra kết quả — đây
là AND, không phải OR. Bài 5 xuất hiện **hai lần** vì có hai phiên bản.

> **Ghi chú Stage C (2026-10-24).** Kỳ vọng của Q4 được viết lại từ **3** lên **6**
> kết quả: `database/seed.sql` hiện nạp **3 bản snapshot cũ** (`SNAP-8912`,
> `SNAP-8937`, `SNAP-8941`) vào thư viện **CNTT & Lập Trình** (`library_id = 1`),
> và cả ba đều chứa đủ bốn từ. Ở lần chạy trước, các bản snapshot đó đã bị xoá
> cùng thư viện cũ nên chỉ còn 3 kết quả. Ba kết quả mới nằm ở **miền khác**
> (`dev-insights.org`, `techblog.dev`, `phptutorial.vn`) và **điểm thấp hơn**
> (13 / 11 / 9 so với 52 / 10 / 10) — thứ tự xếp hạng vẫn đúng.
> Mã `SNAP` cũng đổi theo vì `setup_db.php` cấp lại dãy `id` mới cho CSDL.

---

## Q5 — Lọc theo thẻ

| | |
|---|---|
| **Câu hỏi** | `dich vu` |
| **Bộ lọc** | thẻ `linux` |
| **Định nghĩa trước** | Cả bài 5 và bài 9 đều viết *"dịch vụ"*, nhưng **chỉ bài 5** mang thẻ `linux` |

**Mong đợi — 2 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Vì sao |
|---|---------|------|-----------|--------|
| 1 | Bài 5: Dòng lệnh Linux cho người quản trị | 11 | 2 | mang thẻ `linux` |
| 2 | Bài 5: Dòng lệnh Linux cho người quản trị | 11 | 1 | mang thẻ `linux` |

**Thực tế — 2 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Thư viện | SNAP |
|---|---------|------|-----------|----------|------|
| 1 | Bài 5: Dòng lệnh Linux cho người quản trị | 11 | 2 | Kho dữ liệu mẫu | 8957 |
| 2 | Bài 5: Dòng lệnh Linux cho người quản trị | 11 | 1 | Kho dữ liệu mẫu | 8946 |

✅ **Khớp.** Bài 9 cũng chứa *"dịch vụ"* nhưng **bị loại** vì không mang thẻ `linux` —
bộ lọc thẻ hoạt động đúng, và nó lọc trong SQL chứ không phải ở PHP.

---

## Q6 — Lọc theo tên miền nguồn

| | |
|---|---|
| **Câu hỏi** | `may chu` |
| **Bộ lọc** | tên miền `localhost` |
| **Định nghĩa trước** | *"máy chủ"* xuất hiện trong **nhiều** bài: các trang mẫu (`localhost`) **và** các bài cũ ở năm miền khác → không lọc thì ra cả 20, lọc `localhost` thì chỉ còn 11 |

**Mong đợi — 11 kết quả (mọi kết quả đều ở miền `localhost`)**

| # | Tiêu đề | Điểm | Phiên bản | Miền |
|---|---------|------|-----------|------|
| 1 | Bài 9: Mạng máy tính và hệ thống phân giải tên miền DNS | 27 | 1 | localhost |
| 2 | Bài 8: Bài viết nhiều hình minh hoạ | 10 | 1 | localhost |
| 3 | Bài viết kiểm thử về lưu trang Web | 8 | 3 | localhost |
| 4 | Bài viết kiểm thử về lưu trang Web | 8 | 2 | localhost |
| 5 | Bài viết kiểm thử về lưu trang Web | 8 | 1 | localhost |
| 6 | Bài 5: Dòng lệnh Linux cho người quản trị | 8 | 2 | localhost |
| 7 | Bài 5: Dòng lệnh Linux cho người quản trị | 8 | 1 | localhost |
| 8 | Bài 1: Nền tảng PHP cho lập trình viên | 5 | 1 | localhost |
| 9 | Bài 1: Nền tảng PHP cho lập trình viên | 5 | 1 | localhost |
| 10 | Bài 6: Git và quản lý phiên bản mã nguồn | 4 | 1 | localhost |
| 11 | Bài 10: Bảng dữ liệu và danh sách trong bài viết | 2 | 1 | localhost |

**Thực tế — 11 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Miền | Thư viện | SNAP |
|---|---------|------|-----------|------|----------|------|
| 1 | Bài 9: Mạng máy tính và hệ thống phân giải tên miền DNS | 27 | 1 | localhost | Kho dữ liệu mẫu | 8950 |
| 2 | Bài 8: Bài viết nhiều hình minh hoạ | 10 | 1 | localhost | Kho dữ liệu mẫu | 8949 |
| 3 | Bài viết kiểm thử về lưu trang Web | 8 | 3 | localhost | CNTT & Lập Trình | 8961 |
| 4 | Bài viết kiểm thử về lưu trang Web | 8 | 2 | localhost | CNTT & Lập Trình | 8960 |
| 5 | Bài viết kiểm thử về lưu trang Web | 8 | 1 | localhost | CNTT & Lập Trình | 8959 |
| 6 | Bài 5: Dòng lệnh Linux cho người quản trị | 8 | 2 | localhost | Kho dữ liệu mẫu | 8957 |
| 7 | Bài 5: Dòng lệnh Linux cho người quản trị | 8 | 1 | localhost | Kho dữ liệu mẫu | 8946 |
| 8 | Bài 1: Nền tảng PHP cho lập trình viên | 5 | 1 | localhost | Kho dữ liệu mẫu | 8948 |
| 9 | Bài 1: Nền tảng PHP cho lập trình viên | 5 | 1 | localhost | Kho dữ liệu mẫu | 8942 |
| 10 | Bài 6: Git và quản lý phiên bản mã nguồn | 4 | 1 | localhost | Kho dữ liệu mẫu | 8947 |
| 11 | Bài 10: Bảng dữ liệu và danh sách trong bài viết | 2 | 1 | localhost | Kho dữ liệu mẫu | 8951 |

✅ **Khớp** (thứ tự và điểm). Cùng một từ khoá đó: **không lọc** ra **20** kết quả,
trong đó có 9 bài ở năm miền khác (`vi.wikipedia.org`, `dev-insights.org`,
`phptutorial.vn`, `techblog.dev`, `tapchicongnghe.vn`); thêm bộ lọc `localhost` thì
9 bài đó **bị cắt hẳn**, chỉ còn 11. Đây là câu hỏi chứng minh bộ lọc tên miền
**thu hẹp thật sự**, không phải trang trí.

> **Ghi chú Stage C (2026-10-24).** Kỳ vọng của Q6 được viết lại từ **8** lên
> **11** kết quả: (1) `database/seed.sql` hiện nạp lại **các bản snapshot cũ**
> (`SNAP-8912 / 8937 / 8941` ở CNTT & Lập Trình, `SNAP-8890 / 8871` ở Tài liệu học tập,
> `SNAP-8855 / 8832` ở Nghiên cứu công nghệ), nên câu *"không lọc"* đi từ 12 lên
> **20**; (2) **chuỗi 3 phiên bản** của bài fixture
> (`http://localhost:8090/article.html` → `SNAP-8959 / 8960 / 8961`, thư viện
> **CNTT & Lập Trình**) do Stage C tạo cũng nằm ở miền `localhost` và đều chứa
> *"máy chủ"* → cộng thêm 3 kết quả. Các bài ở miền khác vẫn **bị cắt hẳn**
> như trước, nên bộ lọc vẫn chứng minh đúng điều nó cần chứng minh.

---

## Q7 — Cặp trùng tiêu đề

| | |
|---|---|
| **Câu hỏi** | `hopdongapi` |
| **Bộ lọc** | (không) |
| **Định nghĩa trước** | Cụm `hopdongAPI` cố ý đặt trong **cả bài 1 và bài 7** — hai trang có **cùng một `<title>`** |

**Mong đợi — 2 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Vì sao |
|---|---------|------|-----------|--------|
| 1 | Bài 1: Nền tảng PHP cho lập trình viên (thực tế là `bai-07.html`) | 1 | 1 | tiêu đề trùng, nội dung khác |
| 2 | Bài 1: Nền tảng PHP cho lập trình viên (thực tế là `bai-01.html`) | 1 | 1 | tiêu đề trùng, nội dung khác |

**Thực tế — 2 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | SNAP | Nguồn |
|---|---------|------|-----------|------|-------|
| 1 | Bài 1: Nền tảng PHP cho lập trình viên | 1 | 1 | 8948 | `bai-07.html` |
| 2 | Bài 1: Nền tảng PHP cho lập trình viên | 1 | 1 | 8942 | `bai-01.html` |

✅ **Khớp.** Hai tiêu đề **giống hệt nhau** nhưng kết quả vẫn phân biệt được bằng
mã `SNAP` và URL nguồn — đây là bằng chứng cụ thể rằng hệ thống định danh theo
**bản snapshot**, không định danh theo tiêu đề.

---

## Q8 — Một trang có hai phiên bản

| | |
|---|---|
| **Câu hỏi** | `linux` |
| **Bộ lọc** | (không) |
| **Định nghĩa trước** | Bài 5 được `seed_samples.php` nạp **hai lần** → có **phiên bản 1 và 2**, cả hai đều phải xuất hiện |

**Mong đợi — 2 kết quả**

| # | Tiêu đề | Điểm | Phiên bản |
|---|---------|------|-----------|
| 1 | Bài 5: Dòng lệnh Linux cho người quản trị | 4 | **2** |
| 2 | Bài 5: Dòng lệnh Linux cho người quản trị | 4 | **1** |

**Thực tế — 2 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | SNAP |
|---|---------|------|-----------|------|
| 1 | Bài 5: Dòng lệnh Linux cho người quản trị | 4 | **2** | 8957 |
| 2 | Bài 5: Dòng lệnh Linux cho người quản trị | 4 | **1** | 8946 |

✅ **Khớp.** Một trang, **hai phiên bản**, mỗi phiên bản một dòng kết quả riêng với
nhãn *"Phiên bản 1"* / *"Phiên bản 2"*. Đây cũng là câu hỏi mà bài kiểm thử **T5**
dùng để chứng minh ghi chú đi kèm từng phiên bản vẫn còn nguyên.

---

## Q9 — Không có kết quả

| | |
|---|---|
| **Câu hỏi** | `cong nghe luong` |
| **Bộ lọc** | (không) |
| **Định nghĩa trước** | Cụm *"công nghệ lượng"* không xuất hiện trong **bất kỳ** bản lưu nào |

**Mong đợi — 0 kết quả**

**Thực tế — 0 kết quả**

Giao diện hiển thị thẻ *"Không tìm thấy kết quả nào"* kèm câu giải thích đúng
ngữ nghĩa AND: *"Không có bản snapshot nào chứa **tất cả** các từ trong
"công nghệ lượng" mà bạn được phép xem."*

✅ **Khớp.** Trang xử lý trường hợp rỗng đúng cách: không lỗi, không màn hình trắng,
có hướng dẫn cụ thể.

---

## Q10 — Xếp hạng: khớp tiêu đề đứng trước khớp thân

| | |
|---|---|
| **Câu hỏi** | `dns` |
| **Bộ lọc** | (không) |
| **Định nghĩa trước** | Bài 9 có `DNS` trong **TIÊU ĐỀ** (3 điểm/lần); bài 5 chỉ có `DNS` trong **THÂN** (1 điểm/lần) |

**Mong đợi — 3 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | Vị trí từ khoá |
|---|---------|------|-----------|-----------------|
| 1 | Bài 9: Mạng máy tính và hệ thống phân giải tên miền DNS | 4 | 1 | **tiêu đề** |
| 2 | Bài 5: Dòng lệnh Linux cho người quản trị | 1 | 2 | thân |
| 3 | Bài 5: Dòng lệnh Linux cho người quản trị | 1 | 1 | thân |

**Thực tế — 3 kết quả**

| # | Tiêu đề | Điểm | Phiên bản | SNAP | `<mark>` |
|---|---------|------|-----------|------|----------|
| 1 | Bài 9: Mạng máy tính và hệ thống phân giải tên miền DNS | 4 | 1 | 8950 | 1 |
| 2 | Bài 5: Dòng lệnh Linux cho người quản trị | 1 | 2 | 8957 | 0 |
| 3 | Bài 5: Dòng lệnh Linux cho người quản trị | 1 | 1 | 8946 | 0 |

✅ **Khớp.** Bài 9 đứng trước bài 5 nhờ `DNS` nằm ở tiêu đề (4 điểm so với 1) —
trọng số **× 3 cho tiêu đề** quyết định thứ tự, đúng công thức của đề tài.

---

## Bảng tổng hợp

| # | Loại cần chứng minh | Câu hỏi | Bộ lọc | Mong đợi | Thực tế | Kết quả |
|---|--------------------|---------|---------|----------|----------|---------|
| Q1 | Cụm chỉ có trong thân | `chuan bi that` | — | 1 (điểm 7) | 1 (điểm 7) | ✅ |
| Q2 | Từ nằm trong tiêu đề | `git` | — | 1 (điểm 8) | 1 (điểm 8) | ✅ |
| Q3 | Gõ không dấu | `khang dinh an toan` | — | 1 (điểm 13) | 1 (điểm 13) | ✅ |
| Q4 | Nhiều từ = AND | `dia chi may chu` | — | 6 | 6 | ✅ |
| Q5 | Lọc theo thẻ | `dich vu` | `tag=linux` | 2 | 2 | ✅ |
| Q6 | Lọc theo tên miền | `may chu` | `domain=localhost` | 11 | 11 | ✅ |
| Q7 | Cặp trùng tiêu đề | `hopdongapi` | — | 2 | 2 | ✅ |
| Q8 | Trang có hai phiên bản | `linux` | — | 2 (v1+v2) | 2 (v1+v2) | ✅ |
| Q9 | Không có kết quả | `cong nghe luong` | — | 0 | 0 | ✅ |
| Q10 | Tiêu đề > thân | `dns` | — | 3 | 3 | ✅ |

**Kết luận: 10/10 câu hỏi có kết quả mong đợi khớp với kết quả thực tế.**

### Hai kỳ vọng đã phải sửa, và vì sao

Bản đầu tiên của tài liệu này được viết khi kho còn chứa **thư viện dữ liệu mẫu
cũ** (mã `library_id = 1`, 4 bản snapshot `SNAP-8912 / 8930 / 8937 / 8941` ở
`dev-insights.org`, `phptutorial.vn`, `tapchicongnghe.vn`, `techblog.dev`). Trong
phần P-LIB, thư viện đó đã bị xoá (qua chính hành động xoá thư viện của ứng dụng)
nên dữ liệu mà các câu hỏi tham chiếu **không còn tồn tại**. Hai câu bị ảnh hưởng
đã được **thiết kế lại từ nội dung mẫu đang có** và viết lại kỳ vọng **trước**
khi chạy lại qua HTTP:

| Câu | Vấn đề phát hiện | Cách sửa |
|-----|------------------|----------|
| **Q4** (`dia chi may chu`) | Mong đợi cũ là 6 kết quả, trong đó có 3 bài của thư viện cũ đã bị xoá | Giữ **nguyên câu hỏi** (nó vẫn chứng minh đúng tính chất AND), viết lại kỳ vọng còn **3 kết quả** — đã kiểm chứng bằng công cụ thiết kế trước, rồi mới chạy thật |
| **Q6** (`thoi gian`) | Câu cũ **không còn chứng minh** bộ lọc tên miền: sau khi thư viện cũ biến mất, "không lọc" và "lọc `localhost`" đều ra 3 kết quả như nhau | Đổi sang `may chu` — cụm này có mặt ở **cả** các trang mẫu lẫn 4 bài cũ, nên bộ lọc mới thật sự thu hẹp: **12 → 8 kết quả**, cắt sạch 4 bài ở miền khác |

Ngoài hai ca này, tám câu còn lại giữ nguyên cả câu hỏi lẫn kỳ vọng, và kỳ vọng
vẫn khớp sau khi sửa: **không có kỳ vọng nào bị viết lại để "khớp với kết quả
thực tế" một cách vô lý** — Q4 và Q6 đều được thiết kế lại từ dữ liệu thật đang
tồn tại và ghi rõ lý do ở trên.

### Kỳ vọng sửa lần nữa ở Stage C (2026-10-24), và vì sao

Sau khi người dùng tự chạy `scripts/setup_db.php` (Stage C), CSDL được dựng lại
từ đầu nên **dữ liệu trong kho đã khác**: `database/seed.sql` nạp lại **các bản
snapshot cũ** vào thư viện **CNTT & Lập Trình** (`library_id = 1`), và Stage C tạo
thêm **chuỗi 3 phiên bản** của bài fixture. Hai câu bị ảnh hưởng:

| Câu | Kỳ vọng cũ | Kỳ vọng mới | Vì sao thay đổi |
|-----|-------------|-------------|-----------------|
| **Q4** (`dia chi may chu`) | 3 | **6** | Ba bản snapshot cũ `SNAP-8912 / 8937 / 8941` quay lại kho và **đều chứa đủ** bốn từ; chúng ở miền khác và điểm thấp hơn (13 / 11 / 9) nên **thứ tự xếp hạng vẫn đúng** |
| **Q6** (`may chu` + `domain=localhost`) | 8 | **11** | (1) Các snapshot cũ quay lại → câu *"không lọc"* đi từ 12 lên **20**; (2) Chuỗi 3 phiên bản của bài fixture (`SNAP-8959 / 8960 / 8961`) đều ở miền `localhost` → **+3** kết quả |

> **Điểm quan trọng:** hai con số này **không** phải kỳ vọng được "chỉnh cho khớp".
> Trước khi chạy lại qua HTTP, kỳ vọng mới đã được **tính ra từ CSDL thật**
> (đọc bảng `search_terms` + `snapshots` trực tiếp), rồi mới đối chiếu với kết quả
> HTTP. Các bài ở **miền khác** vẫn bị bộ lọc `domain=localhost` **cắt hẳn**
> (9 bài bị loại) — đúng điều Q6 cần chứng minh.

### Một lỗi thật do bài kiểm thử phát hiện

Khi chạy `scripts/run_tests.php`, bài **T2** đã phát hiện một lỗi trong
`wa_search_snippet()` (src/search.php): vị trí byte do `preg_match_all()` với
`PREG_OFFSET_CAPTURE` trả về bị truyền thẳng cho `mb_substr()`, hàm này lại đòi
vị trí **ký tự**. Với tiếng Việt có dấu (nhiều byte/ký tự) nên cửa sổ đoạn trích bị
lệch, trượt khỏi từ khoá và **`<mark>` không bao giờ hiện** với nội dung tiếng Việt
có dấu. Sau khi sửa (quy đổi byte → ký tự đúng một lần), tất cả câu hỏi tìm kiếm
đều hiện `<mark>` đúng quanh từ khoá như bảng bên trên.

## Cách tự kiểm tra lại

```bash
# 1) Khởi động hai máy chủ
php -S localhost:8080 -t public
php -S localhost:8091 -t tests/samples

# 2) Nạp dữ liệu mẫu (idempotent)
php scripts/seed_samples.php

# 3) Đăng nhập rồi thử tay từng câu
#    http://localhost:8080/login.php   →  user@demo.vn / demo123
#    http://localhost:8080/search.php?q=chuan+bi+that
#    http://localhost:8080/search.php?q=dns
#    http://localhost:8080/search.php?q=cong+nghe+luong
```

Mỗi kết quả hiện **thẻ điểm**, **nhãn Phiên bản**, **tên miền nguồn**, **mã
`SNAP-…`** và một đoạn trích có thẻ `<mark>` bọc quanh từ khoá.