<?php
/**
 * admin/allowlist.php — Duyệt THEO TÊN MIỀN (chỉ tài khoản Quản trị viên).
 * -----------------------------------------------------------------------------
 * P-FIX-ADMIN — Đề tài yêu cầu: "Một domain được duyệt một lần. Các URL hợp lệ
 * khác trên cùng domain có thể được lưu mà không cần duyệt lại. Domain mới được
 * đưa vào danh sách chờ duyệt. Người dùng có thể gửi yêu cầu duyệt. Gộp những
 * yêu cầu trùng domain. Admin có thể duyệt, từ chối hoặc vô hiệu hoá domain.
 * Admin có thể xử lý nhiều yêu cầu đã kiểm tra cùng lúc. Có nhật ký duyệt và
 * người thực hiện."
 *
 * Trang này gồm 4 khối:
 *   1) Hàng đợi yêu cầu duyệt tên miền (duyệt / từ chối / vô hiệu hoá, có chọn
 *      nhiều để xử lý cùng lúc).
 *   2) Danh sách tên miền ĐÃ ĐƯỢT DUYỆT (thêm / gỡ).
 *   3) Lịch sử các yêu cầu đã xử lý (có người thực hiện và thời điểm).
 *   4) Nhật ký thao tác quản trị.
 *
 * Mọi thao tác thay đổi dữ liệu đều qua POST + CSRF, và đều được ghi vào
 * `admin_logs`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

require_admin();

$user   = current_user();
$userId = (int) ($user['id'] ?? 0);

$notice = null;

/* ---------------------------------------------------------------------------
 * XỬ LÝ POST — mọi hành động thay đổi dữ liệu.
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) CSRF trước khi đọc/ghi bất kỳ thứ gì vào CSDL.
    if (!wa_csrf_check()) {
        $notice = [
            'ok'   => false,
            'text' => 'Yêu cầu không hợp lệ hoặc phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.',
        ];
    } else {
        $action = (string) ($_POST['action'] ?? '');

        // Nút trên từng dòng gửi `single_approve` hoặc `single_reject`; nếu có,
        // ta đổi action sang 'review' để xử lý đúng MỘT yêu cầu đó.
        $singleId     = 0;
        $singleAction = '';

        if (isset($_POST['single_approve'])) {
            $singleId     = (int) $_POST['single_approve'];
            $singleAction = 'approve';
        } elseif (isset($_POST['single_reject'])) {
            $singleId     = (int) $_POST['single_reject'];
            $singleAction = 'reject';
        } elseif (isset($_POST['single_disable'])) {
            $singleId     = (int) $_POST['single_disable'];
            $singleAction = 'disable';
        }

        if ($singleId > 0) {
            $action = 'review';
        }

        switch ($action) {
            /* --- Thêm tên miền trực tiếp vào danh sách được duyệt --------- */
            case 'add':
                $result = wa_allowlist_add(
                    (string) ($_POST['domain'] ?? ''),
                    (string) ($_POST['note'] ?? ''),
                    $userId
                );

                if ($result['ok'] === true) {
                    wa_admin_log($userId, 'domain.add', 'domain', (string) ($_POST['domain'] ?? ''));
                }

                $notice = ['ok' => $result['ok'], 'text' => $result['message']];
                break;

            /* --- Gỡ tên miền khỏi danh sách được duyệt ---------------------- */
            case 'remove':
                $domain = (string) ($_POST['domain'] ?? '');

                $result = wa_allowlist_remove($domain, $userId);

                if ($result['ok'] === true) {
                    wa_admin_log($userId, 'domain.remove', 'domain', $domain);
                }

                $notice = ['ok' => $result['ok'], 'text' => $result['message']];
                break;

            /* --- Duyệt / từ chối / vô hiệu hoá MỘT yêu cầu ----------------
             *
             * Nút trên mỗi dòng gửi `single_id` + `decision`. Nút ở cuối bảng
             * (xử lý nhiều yêu cầu) gửi `request_ids[]` + `decision`. Ta xử lý
             * riêng hai đường này để không nhầm lẫn "một yêu cầu" với "nhiều".
             */
            case 'review':
                $requestId = $singleId > 0 ? $singleId : (int) ($_POST['request_id'] ?? 0);
                $decision  = $singleAction !== '' ? $singleAction : (string) ($_POST['decision'] ?? '');

                $result = wa_domain_request_review(
                    $requestId,
                    $decision,
                    $userId,
                    (string) ($_POST['note'] ?? '')
                );

                if ($result['ok'] === true) {
                    $logAction = 'domain.' . ($decision === 'approve' ? 'approve'
                        : ($decision === 'reject' ? 'reject' : 'disable'));

                    // Lấy lại domain để ghi log.
                    $reqRows = wa_domain_requests('all');
                    $reqDomain = '';
                    foreach ($reqRows as $reqRow) {
                        if ((int) $reqRow['id'] === $requestId) {
                            $reqDomain = (string) $reqRow['domain'];
                            break;
                        }
                    }

                    wa_admin_log($userId, $logAction, 'domain', $reqDomain);
                }

                $notice = ['ok' => $result['ok'], 'text' => $result['message']];
                break;

            /* --- Xử lý NHIỀU yêu cầu đã chọn cùng lúc --------------------- */
            case 'review_bulk':
                $ids     = array_values(array_filter(array_map('intval', (array) ($_POST['request_ids'] ?? []))));
                $decision = (string) ($_POST['decision'] ?? '');

                if ($ids === []) {
                    $notice = [
                        'ok'   => false,
                        'text' => 'Chưa chọn yêu cầu nào. Hãy tick vào ô cạnh các yêu cầu muốn xử lý.',
                    ];
                    break;
                }

                // Tra cứu domain của từng id để ghi log và báo cáo.
                $domainById = [];
                foreach (wa_domain_requests('all') as $reqRow) {
                    $domainById[(int) $reqRow['id']] = (string) $reqRow['domain'];
                }

                $okCount   = 0;
                $failCount = 0;
                $logAction = 'domain.' . ($decision === 'approve' ? 'approve'
                    : ($decision === 'reject' ? 'reject' : 'disable'));

                foreach ($ids as $id) {
                    $result = wa_domain_request_review(
                        $id,
                        $decision,
                        $userId,
                        (string) ($_POST['note'] ?? '')
                    );

                    if ($result['ok'] === true) {
                        $okCount++;
                        wa_admin_log($userId, $logAction, 'domain', $domainById[$id] ?? (string) $id);
                    } else {
                        $failCount++;
                    }
                }

                $notice = [
                    'ok'   => $okCount > 0,
                    'text' => 'Đã xử lý ' . $okCount . ' yêu cầu'
                           . ($failCount > 0 ? ', ' . $failCount . ' yêu cầu không xử lý được' : '')
                           . '.',
                ];
                break;

            default:
                $notice = ['ok' => false, 'text' => 'Hành động không hợp lệ.'];
        }
    }
}

/* ---------------------------------------------------------------------------
 * Dữ liệu hiển thị.
 * ------------------------------------------------------------------------- */
$allowlist      = wa_allowlist();
$pendingList    = wa_domain_requests('pending');
$historyList    = wa_domain_requests('all');
$adminLogs      = wa_admin_logs(20);
$pendingCount   = wa_domain_request_pending_count();

$pageTitle = 'Duyệt tên miền';
require __DIR__ . '/../../templates/header_app.php';
?>

<div class="ribbon">
  <div class="ribbon__row">
    <div style="max-width:680px">
      <div class="ribbon__eyebrow">
        <span class="t-label-sm t-upper t-accent" style="font-weight:600">Quản trị hệ thống / Duyệt tên miền</span>
        <span class="dot" style="width:6px;height:6px;background:var(--wa-secondary-fixed-dim)"></span>
        <span class="t-label-sm" style="color:#74777E">Một lần duyệt cho cả domain</span>
      </div>
      <h1 class="ribbon__title">Duyệt tên miền</h1>
      <p class="ribbon__sub">
        Chỉ cần duyệt <b>một lần</b> mỗi tên miền — sau đó mọi URL hợp lệ trên domain đó
        đều lưu được mà không phải xin duyệt lại. Kiểm tra này được áp dụng ở <b>mỗi</b>
        lần tải và cả ở mỗi bước chuyển hướng.
      </p>
    </div>

    <div class="row" style="flex-shrink:0">
      <span class="chip chip--teal">
        <span class="ms ms-14">verified_user</span>
        <?= count($allowlist) ?> tên miền đã duyệt
      </span>
      <?php if ($pendingCount > 0): ?>
        <span class="chip chip--warn">
          <span class="ms ms-14">pending</span>
          <?= $pendingCount ?> yêu cầu đang chờ
        </span>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($notice !== null): ?>
  <div class="form-alert <?= $notice['ok'] ? 'form-alert--ok' : '' ?>" style="margin-bottom:20px" role="status">
    <span class="ms ms-18"><?= $notice['ok'] ? 'check_circle' : 'error_outline' ?></span>
    <span><?= wa_e((string) $notice['text']) ?></span>
  </div>
<?php endif; ?>

<!-- ===================== 1) HÀNG ĐỢI YÊU CẦU DUYỆT ===================== -->
<div class="panel">
  <div class="panel__head">
    <span class="panel__label">
      <span class="ms ms-16">pending_actions</span>
      Yêu cầu duyệt tên miền (<?= $pendingCount ?>)
    </span>
  </div>

  <?php if ($pendingList === []): ?>
    <div class="empty" style="border:0;padding:28px 0">
      <span class="ms ms-32">task_alt</span>
      <h3>Không có yêu cầu nào đang chờ</h3>
      <p>
        Khi người dùng dán một URL thuộc tên miền chưa được duyệt, hệ thống sẽ tự động
        đưa tên miền đó vào hàng đợi này để bạn xét duyệt.
      </p>
    </div>
  <?php else: ?>
    <form method="post" action="<?= wa_e(wa_url('admin/allowlist.php')) ?>">
      <?= wa_csrf_field() ?>
      <input type="hidden" name="action" value="review_bulk">

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:40px">
                <input type="checkbox" id="check-all-requests" aria-label="Chọn tất cả yêu cầu">
              </th>
              <th>Tên miền</th>
              <th>Người yêu cầu</th>
              <th>Ghi chú</th>
              <th>Thời điểm</th>
              <th style="width:210px">Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingList as $req): ?>
              <tr>
                <td>
                  <input type="checkbox" name="request_ids[]" value="<?= (int) $req['id'] ?>"
                         class="request-check" aria-label="Chọn yêu cầu <?= wa_e((string) $req['domain']) ?>">
                </td>
                <td>
                  <span class="row" style="gap:6px">
                    <span class="ms ms-16" style="color:var(--wa-action)">public</span>
                    <span class="cell-main"><?= wa_e((string) $req['domain']) ?></span>
                  </span>
                </td>
                <td class="t-muted"><?= wa_e((string) $req['requested_by_name']) ?></td>
                <td class="t-muted"><?= wa_e((string) ($req['note'] ?? '—')) ?></td>
                <td class="t-muted"><?= wa_e((string) $req['created_label']) ?></td>
                <td>
                  <div class="table__actions">
                    <button class="btn btn--primary btn--sm" type="submit" name="single_approve"
                            value="<?= (int) $req['id'] ?>" formnovalidate>
                      <span class="ms ms-16">check</span> Duyệt
                    </button>
                    <button class="btn btn--outline btn--sm" type="submit" name="single_reject"
                            value="<?= (int) $req['id'] ?>" formnovalidate>
                      <span class="ms ms-16">close</span> Từ chối
                    </button>
                    <button class="btn btn--outline btn--sm" type="submit" name="single_disable"
                            value="<?= (int) $req['id'] ?>" formnovalidate
                            title="Vô hiệu hoá tên miền này (gỡ khỏi danh sách được duyệt)">
                      <span class="ms ms-16">block</span> Vô hiệu hoá
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="toolbar" style="margin-top:16px;border:0;padding-top:16px;border-top:1px solid var(--wa-border)">
        <div class="toolbar__left">
          <span class="t-label-sm t-muted">Xử lý nhanh các yêu cầu đã chọn:</span>
        </div>
        <div class="toolbar__right">
          <button class="btn btn--primary btn--sm" type="submit" name="decision" value="approve">
            <span class="ms ms-16">check</span> Duyệt đã chọn
          </button>
          <button class="btn btn--outline btn--sm" type="submit" name="decision" value="reject">
            <span class="ms ms-16">close</span> Từ chối đã chọn
          </button>
          <button class="btn btn--outline btn--sm" type="submit" name="decision" value="disable">
            <span class="ms ms-16">block</span> Vô hiệu hoá đã chọn
          </button>
        </div>
      </div>
    </form>
  <?php endif; ?>
</div>

<!-- ===================== 2) TÊN MIỀN ĐÃ ĐƯỢC DUYỆT ===================== -->
<div class="panel" style="margin-top:24px">
  <div class="panel__head">
    <span class="panel__label">
      <span class="ms ms-16">verified_user</span>
      Danh sách tên miền được duyệt (<?= count($allowlist) ?>)
    </span>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Tên miền</th>
          <th>Người thêm</th>
          <th>Ngày thêm</th>
          <th>Ghi chú</th>
          <th style="width:120px">Thao tác</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allowlist as $entry): ?>
          <tr>
            <td>
              <span class="row" style="gap:6px">
                <span class="ms ms-16" style="color:var(--wa-action)">public</span>
                <span class="cell-main"><?= wa_e((string) $entry['domain']) ?></span>
              </span>
            </td>
            <td class="t-muted"><?= wa_e((string) $entry['added_by']) ?></td>
            <td class="t-muted"><?= wa_e((string) $entry['date']) ?></td>
            <td class="t-muted"><?= wa_e((string) ($entry['note'] ?? '—')) ?></td>
          <td>
            <div class="table__actions">
              <?php /* Gỡ tên miền khỏi danh sách được duyệt (vô hiệu hoá). */ ?>
              <form method="post" action="<?= wa_e(wa_url('admin/allowlist.php')) ?>"
                    onsubmit="return confirm('Gỡ tên miền <?= wa_e((string) $entry['domain']) ?> khỏi danh sách được duyệt? Các URL trên domain này sẽ không lưu được nữa.');">
                <?= wa_csrf_field() ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="domain" value="<?= wa_e((string) $entry['domain']) ?>">
                <button class="btn btn--outline btn--sm" type="submit" title="Gỡ tên miền khỏi danh sách">
                  <span class="ms ms-16">delete</span>
                </button>
              </form>
            </div>
          </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===================== 3) BIỂU MẪU THÊM TÊN MIỀN ===================== -->
<div class="panel" style="margin-top:24px">
  <div class="panel__head">
    <span class="panel__label">
      <span class="ms ms-16">add_link</span>
      Thêm tên miền được duyệt
    </span>
  </div>

  <form class="capture__form" method="post" action="<?= wa_e(wa_url('admin/allowlist.php')) ?>">
    <?= wa_csrf_field() ?>
    <input type="hidden" name="action" value="add">

    <div class="capture__input">
      <span class="ms ms-18">public</span>
      <input class="input input--icon" type="text" name="domain" placeholder="tenmien.vn" required
             aria-label="Tên miền cần thêm">
    </div>
    <div class="capture__input">
      <span class="ms ms-18">notes</span>
      <input class="input input--icon" type="text" name="note" placeholder="Ghi chú (không bắt buộc)"
             aria-label="Ghi chú cho tên miền">
    </div>
    <button class="btn btn--dark" type="submit">
      <span class="ms ms-18">add</span>
      <span>Thêm tên miền</span>
    </button>
  </form>

  <p class="t-label-sm t-muted" style="margin-top:8px">
    Tên miền được ghi thật vào bảng <code>allowlist_domains</code> của cơ sở dữ liệu MySQL
    và vẫn còn sau khi tải lại trang này.
  </p>
</div>

<!-- ===================== 4) LỊCH SỬ YÊU CẦU + NHẬT KÝ ===================== -->
<div class="panel" style="margin-top:24px">
  <div class="panel__head">
    <span class="panel__label">
      <span class="ms ms-16">history</span>
      Lịch sử yêu cầu duyệt tên miền
    </span>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Tên miền</th>
          <th>Trạng thái</th>
          <th>Người yêu cầu</th>
          <th>Người xử lý</th>
          <th>Thời điểm xử lý</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($historyList === []): ?>
          <tr>
            <td colspan="5" class="t-muted" style="text-align:center;padding:22px">
              Chưa có yêu cầu nào được ghi nhận.
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($historyList as $req): ?>
          <tr>
            <td class="cell-main"><?= wa_e((string) $req['domain']) ?></td>
            <td>
              <span class="chip <?= $req['status'] === 'approved' ? 'chip--teal' : ($req['status'] === 'pending' ? 'chip--warn' : 'chip--plain') ?>">
                <?= wa_e((string) $req['status_label']) ?>
              </span>
            </td>
            <td class="t-muted"><?= wa_e((string) $req['requested_by_name']) ?></td>
            <td class="t-muted"><?= wa_e((string) ($req['reviewed_by_name'] !== '' ? $req['reviewed_by_name'] : '—')) ?></td>
            <td class="t-muted"><?= wa_e((string) ($req['reviewed_at'] !== null ? wa_datetime((string) $req['reviewed_at']) : '—')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel" style="margin-top:24px">
  <div class="panel__head">
    <span class="panel__label">
      <span class="ms ms-16">receipt_long</span>
      Nhật ký thao tác quản trị
    </span>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Thời điểm</th>
          <th>Người thực hiện</th>
          <th>Hành động</th>
          <th>Đối tượng</th>
          <th>Ghi chú</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($adminLogs === []): ?>
          <tr>
            <td colspan="5" class="t-muted" style="text-align:center;padding:22px">
              Chưa có thao tác quản trị nào được ghi nhận.
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($adminLogs as $log): ?>
          <tr>
            <td class="t-muted"><?= wa_e((string) $log['created_label']) ?></td>
            <td class="cell-main"><?= wa_e((string) $log['actor_name']) ?></td>
            <td><span class="chip chip--plain"><?= wa_e((string) $log['action_label']) ?></span></td>
            <td class="t-muted"><?= wa_e((string) ($log['target_id'] ?? '—')) ?></td>
            <td class="t-muted"><?= wa_e((string) ($log['detail'] ?? '—')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  // Chọn / bỏ chọn tất cả yêu cầu trong hàng đợi.
  (function () {
    var all = document.getElementById('check-all-requests');
    if (!all) { return; }
    all.addEventListener('change', function () {
      document.querySelectorAll('.request-check').forEach(function (box) {
        box.checked = all.checked;
      });
    });
  })();
</script>

<?php require __DIR__ . '/../../templates/footer_app.php'; ?>
