<?php
/**
 * footer_app.php — LAYOUT CHUNG CHO ỨNG DỤNG (đã đăng nhập).
 * Đóng <main>, khối app-shell và thêm script nhỏ cho sidebar thu gọn.
 */

declare(strict_types=1);
?>
</main>
</div>

<script>
  // Sidebar thu gọn trên màn hình hẹp
  (function () {
    var toggle = document.getElementById('sidebar-toggle');
    if (!toggle) { return; }
    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      document.body.classList.toggle('sidebar-open');
    });
    document.addEventListener('click', function (e) {
      if (document.body.classList.contains('sidebar-open') && !e.target.closest('#sidebar')) {
        document.body.classList.remove('sidebar-open');
      }
    });
  })();
</script>
</body>
</html>
