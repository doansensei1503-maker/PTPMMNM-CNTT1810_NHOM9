<?php
/**
 * logout.php — Xoá phiên và quay về trang chủ (trang dành cho khách).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

logout();

header('Location: ' . wa_url('index.php'));
exit;
