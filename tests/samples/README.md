# Bộ dữ liệu mẫu — `tests/samples/`

Bộ **15 trang mẫu** dùng làm bằng chứng cho đề tài WebArchive (Topic 16):
mỗi trang được phục vụ bởi máy chủ tĩnh riêng, nạp vào kho nội dung qua
**đúng đường lưu trang thật** (`wa_store_page_capture()` → cURL → bóc tách DOM
→ tải ảnh về local → ghi snapshot → lập chỉ mục tìm kiếm), và dùng làm đầu
vào cho bộ kiểm thử tự động `scripts/run_tests.php`.

## Chạy máy chủ thư mẫu

```bash
php -S localhost:8091 -t tests/samples
```

Ứng dụng chính chạy ở cổng khác, ví dụ:

```bash
php -S localhost:8090 -t public
```

Nạp bộ mẫu vào thư viện **"Kho dữ liệu mẫu"** (chủ sở hữu `user@demo.vn`):

```bash
php scripts/seed_samples.php
```

Script là **idempotent**: chạy lần hai sẽ in `already seeded` và thoát với mã 0
mà không tạo bản ghi trùng.

Muốn nạp lại từ đầu (xoá thư viện mẫu cũ rồi tạo lại — việc xoá đi qua đúng
hàm xoá của ứng dụng, nên thư mục ảnh cũng được dọn theo):

```bash
php scripts/seed_samples.php --force
```

## Quy ước đặc biệt trong nội dung mẫu

Mỗi trang nội dung mang một **cụm khoá riêng** (`mau01webarchive` …
`mau13webarchive`) nằm trong **thân bài**, không nằm trong tiêu đề. Cụm này
không xuất hiện trong bất kỳ dữ liệu nào khác của kho, nên khi tìm kiếm nó
luôn trả về **đúng một** trang mẫu — đó là cơ sở để câu hỏi tìm kiếm trong
[`docs/SEARCH_QUERIES.md`](../../docs/SEARCH_QUERIES.md) có kết quả xác định.

Ngoài ra:

- `bai-01.html` và `bai-07.html` có **cùng một `<title>`** (trường hợp trùng tiêu đề).
- Cả hai cùng chứa cụm `hopdongAPI` — dùng để chứng minh hai trang khác nhau
  cùng dùng một tiêu đề vẫn tìm ra được bằng nhau.
- `bai-05.html` được nạp **hai lần** trong `seed_samples.php` để sinh
  **phiên bản 1 và phiên bản 2** (dùng cho câu hỏi Q8 và cho bài kiểm thử T5).

## Manifest — 15 mục

| # | Tệp | Mục đích | Kết quả lưu trang mong đợi | Tiêu đề trích ra / thông điệp lỗi | Ảnh |
|---|------|----------|----------------------------|----------------------------------|-----|
| 1 | `bai-01.html` | Bài viết nền tảng PHP; 1 ảnh `anh-01.png` | `success` | `Bài 1: Nền tảng PHP cho lập trình viên` | 1 |
| 2 | `bai-02.html` | Bài viết MySQL, câu lệnh chuẩn bị thật; 1 ảnh `anh-02.png` | `success` | `Bài 2: Truy vấn MySQL và chỉ mục bảng` | 1 |
| 3 | `bai-03.html` | Bài viết HTML/CSS về bố cục trang | `success` | `Bài 3: HTML và CSS trong bố cục trang` | 0 |
| 4 | `bai-04.html` | Bài viết JavaScript và thao tác DOM | `success` | `Bài 4: JavaScript và thao tác với DOM` | 0 |
| 5 | `bai-05.html` | Bài viết dòng lệnh Linux; nạp **hai lần** → v1 và v2 | `success` | `Bài 5: Dòng lệnh Linux cho người quản trị` | 0 |
| 6 | `bai-06.html` | Bài viết Git và quản lý phiên bản mã nguồn | `success` | `Bài 6: Git và quản lý phiên bản mã nguồn` | 0 |
| 7 | `bai-07.html` | **Cùng `<title>` với bài 1** nhưng nội dung khác hẳn | `success` | `Bài 1: Nền tảng PHP cho lập trình viên` | 0 |
| 8 | `bai-08.html` | Bài viết có **ba ảnh** (`anh-03/04/05.png`) | `success` | `Bài 8: Bài viết nhiều hình minh hoạ` | 3 |
| 9 | `bai-09.html` | Bài viết dài về mạng máy tính và phân giải tên miền `DNS` | `success` | `Bài 9: Mạng máy tính và hệ thống phân giải tên miền DNS` | 0 |
| 10 | `bai-10.html` | Bài viết dựng quanh **bảng** và **danh sách** | `success` | `Bài 10: Bảng dữ liệu và danh sách trong bài viết` | 0 |
| 11 | `bai-11.html` | Trang có `<script>`, `onclick`, `<iframe>` — **trường hợp làm sạch** | `success` | `Bài 11: Làm sạch nội dung độc hại` | 0 |
| 12 | `bai-12.html` | Trang **chỉ có thông báo**, dưới 200 ký tự | `error_no_content` | `Trang không có nội dung đọc được` | 0 |
| 13 | `bai-13.html` | Bố cục dùng `main` + `div` thay vì `article` | `success` | `Bài 13: Bố cục dùng thẻ main và div` | 0 |
| 14 | `chuyen-huong-ngoai.php` | **Chuyển hướng 302** ra host ngoài danh sách cho phép | `error_blocked` | `URL không thuộc danh sách được duyệt` | 0 |
| 15 | `cham.php` | **Ngủ 15 giây** rồi mới phản hồi (giới hạn chờ 12 giây) | `error_timeout` | `Hết thời gian chờ khi tải trang` | 0 |

### Trường hợp 404 — không cần tệp riêng

`/khong-ton-tai.html` trên máy chủ thư mẫu trả về **HTTP 404**, nên đường lưu
trang sẽ ghi `error_404`. Đây là cách thứ 16 (không nằm trong bộ 15 mục) để
kiểm thử đủ ba mã lỗi khác nhau.

## Tệp ảnh

Năm ảnh PNG 64×64 (`anh-01.png` … `anh-05.png`) được sinh **bằng mã** bởi
[`scripts/make_sample_images.php`](../../scripts/make_sample_images.php) —
không cần thư viện GD, chỉ dùng `gzcompress()` và khối PNG chuẩn (IHDR, IDAT,
IEND). Mỗi ảnh một màu khác nhau:

| Tệp | Màu | Dùng ở |
|-----|-----|---------|
| `anh-01.png` | xanh dương | `bai-01.html` |
| `anh-02.png` | xanh lá | `bai-02.html` |
| `anh-03.png` | vàng | `bai-08.html` |
| `anh-04.png` | đỏ | `bai-08.html` |
| `anh-05.png` | tím | `bai-08.html` |

Khi lưu trang, ảnh được **tải về máy chủ nội bộ** (`public/uploads/snapshots/…`)
và đường dẫn trong bản lưu được trỏ lại về bản local — nhờ đó bản lưu vẫn đọc
được sau khi trang nguồn chết.

## Kiểm tra nhanh

```bash
# Hai trang nội dung trả về 200
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8091/bai-01.html   # 200
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8091/bai-13.html   # 200

# Chuyển hướng ra host ngoài
curl -s -i http://localhost:8091/chuyen-huong-ngoai.php | head -5           # 302 -> example.com

# 404
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8091/khong-ton-tai.html  # 404
```
