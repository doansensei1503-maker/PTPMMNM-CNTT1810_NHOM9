<?php
/**
 * footer_guest.php — LAYOUT CHUNG CHO KHÁCH (guest).
 * Đóng khối <main> và thêm chân trang công khai (trừ khi $hideFooter = true).
 */

declare(strict_types=1);

$hideFooter = $hideFooter ?? false;
?>
</main>

<?php if (!$hideFooter): ?>
<footer class="landing-foot">
  <div class="landing-foot__inner">
    <div class="row" style="gap:8px">
      <img src="<?= wa_e(wa_asset('img/logo-mark.svg')) ?>" alt="Biểu trưng WebArchive" width="24" height="24">
      <span class="t-body-md" style="font-weight:600;color:var(--wa-text)">WebArchive</span>
      <span class="t-body-sm t-muted">— Nền tảng lưu trữ snapshot và đọc offline cá nhân</span>
    </div>
    <div class="t-label-sm t-muted">© 2026 WebArchive — Đồ án môn Lập trình Nhóm 9. Mọi bản quyền được bảo hộ.</div>
  </div>
</footer>
<?php endif; ?>

</body>
</html>
