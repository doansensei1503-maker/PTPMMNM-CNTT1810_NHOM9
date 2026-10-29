<?php
/**
 * redir-escape.php — chuyển hướng (302) ra ngoài allowlist (https://example.org/).
 *
 * Mục đích kiểm thử: redirect rời khỏi danh sách được duyệt phải bị CHẶN, và
 * engine không được gửi bất kỳ yêu cầu nào tới example.org.
 */

declare(strict_types=1);

http_response_code(302);
header('Location: https://example.org/');