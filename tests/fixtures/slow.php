<?php
/**
 * slow.php — ngủ 15 giây rồi mới in ra một bài viết hợp lệ.
 *
 * Mục đích kiểm thử: vượt quá thời gian chờ tổng thể của engine (12 giây) nên
 * phải trả về mã lỗi error_timeout. Bài viết in ra sau đó sẽ không bao giờ được
 * dùng — engine đã dừng chờ trước đó.
 */

declare(strict_types=1);

sleep(15);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>Trang phản hồi chậm</title>
</head>
<body>
<article>
  <h1>Trang phản hồi chậm</h1>
  <p>Nội dung này được in ra sau 15 giây chờ. Engine tải trang chỉ cho phép chờ 12
  giây cho toàn bộ quá trình, nên lần lấy này phải bị ghi nhận là hết thời gian chờ.</p>
  <p>Đoạn văn bổ sung để bảo đảm nếu engine không bị giới hạn thời gian thì trang này
  vẫn dài trên 200 ký tự và sẽ bị coi là một bài viết hợp lệ — đó chính là điều mà
  bài kiểm thử này không muốn thấy.</p>
</article>
</body>
</html>