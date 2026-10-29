<?php
/**
 * redir-ok.php — chuyển hướng (302) tới article.html trên CÙNG một máy chủ.
 *
 * Mục đích kiểm thử: redirect hợp lệ phải được theo đúng 1 bước và vẫn thành công,
 * vì tên miền đích vẫn nằm trong allowlist.
 */

declare(strict_types=1);

http_response_code(302);
header('Location: /article.html');