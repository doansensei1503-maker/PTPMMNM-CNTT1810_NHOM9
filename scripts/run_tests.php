<?php
/**
 * scripts/run_tests.php — BỘ KIỂM THỬ TỰ ĐỘNG CHO 6 BÀI KIỂM THỬ BẮT BUỘC (P-FINAL).
 *
 * Bộ kiểm thử này TỰ khởi động máy chủ riêng bằng proc_open():
 *   - Ứng dụng WebArchive : http://localhost:18080  (public/)
 *   - Máy chủ thư mẫu     : http://localhost:18091  (tests/samples/)
 *
 * Mọi thao tác đều đi qua HTTP THẬT bằng cURL với cookie jar trong thư mục tạm
 * của hệ điều hành — không gọi thẳng hàm PHP, không sửa bảng trực tiếp (trừ
 * dòng tài khoản rác, vốn xoá bằng tài khoản CSDL của ứng dụng).
 *
 * Sáu bài kiểm thử bắt buộc:
 *   T1  Nguồn đã chết   — tắt máy chủ nguồn, bản lưu vẫn đọc được (kể cả ảnh).
 *   T2  Tìm theo thân    — cụm chỉ có trong thân bài vẫn ra kết quả, có <mark>.
 *   T3  Ba lỗi khác nhau — error_404 / error_timeout / error_blocked.
 *   T4  Script không chạy được — content_html sạch, không script/onclick/iframe.
 *   T5  Phiên bản + ghi chú sống sót — tải lại tạo v2, v1 giữ nguyên + ghi chú.
 *   T6  Người ngoài không vào được — reader/export trả 403, chủ sở hữu 200.
 *
 * QUY TẮC CỨNG
 * ------------
 *   - KHÔNG chạy scripts/setup_db.php, KHÔNG hỏi mật khẩu quản trị MySQL.
 *   - KHÔNG xoá tay bất kỳ tệp nào trong public/uploads/. Dọn ảnh kiểm thử
 *     đi qua hành động xoá thư viện của chính ứng dụng (library_action.php),
 *     hàm này tự dọn cả thư mục ảnh.
 *   - Tên thư viện và tài khoản rác có hậu tố ngẫu nhiên theo PID + thời gian,
 *     nên chạy lại nhiều lần không đụng dữ liệu cũ.
 *
 * Cách chạy:
 *   php scripts/run_tests.php
 *
 * Mã thoát: 0 khi cả 6 bài PASS, 1 khi có bài FAIL.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Script này chỉ chạy được từ dòng lệnh.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';

const WA_T_APP_PORT     = 18080;
const WA_T_SAMPLE_PORT  = 18091;
const WA_T_APP_BASE     = 'http://localhost:' . WA_T_APP_PORT;
const WA_T_SAMPLE_BASE  = 'http://localhost:' . WA_T_SAMPLE_PORT;

const WA_T_OWNER_EMAIL = 'user@demo.vn';
const WA_T_OWNER_PASS  = 'demo123';

/**
 * Cụm chỉ có trong THÂN bài 1 — dùng để chứng minh bản lưu tự đủ nội dung.
 * Đây đúng là tên token đã soạn trong tests/samples/bai-01.html (dùng chung cả
 * bài 7, nên T1 kiểm tra chuỗi hiện diện chứ không kiểm tra từ khoá riêng của bài).
 */
const WA_T1_BODY_PHRASE = 'hopdongAPI';

/** Cụm chỉ có trong THÂN bài 2 — dùng cho bài tìm kiếm T2. */
const WA_T2_BODY_PHRASE = 'chuan bi that';

/** Cụm chỉ có trong THÂN bài 11 — dùng cho T4. */
const WA_T4_BODY_PHRASE = 'mau11webarchive';

/* =============================================================================
    PHẦN 0 — TIỆN ÍCH CHUNG
    ============================================================================= */

/** Mã duy nhất cho lần chạy này (tên thư viện / email rác / tệp tạm). */
$runId = date('ymdHis') . '-' . getmypid();

/** @var array<int, resource> $servers */
$servers = [];

/** @var array<int, string> $tempFiles */
$tempFiles = [];

/** @var array<int, string> $cookieJars */
$cookieJars = [];

/** @var array<int, string> $logFiles */
$logFiles = [];

/** @var array<string, mixed> $ctx Dữ liệu dùng chung cho các bài kiểm thử. */
$ctx = [
    'library_id'   => 0,
    'library_slug' => '',
    'outsider_id'  => 0,
    'outsider_email' => '',
    't1_snapshot'  => 0,
    't1_image_url'  => '',
    't5_file'      => '',
    't5_snapshot1' => 0,
    't5_snapshot2' => 0,
    't5_note_text' => '',
    't5_marker'    => '',
];

function t_out(string $line = ''): void
{
    echo $line, PHP_EOL;
}

function t_say(string $line): void
{
    echo $line, PHP_EOL;
}

/**
 * Dừng một tiến trình con và CHẮC CHẮN nó đã chết.
 *
 * Trên Windows `proc_terminate()` đôi khi không kịp, nên ta kiểm tra lại bằng
 * `proc_get_status()` và, nếu tiến trình vẫn còn, gọi `taskkill /F` theo PID.
 */
function t_terminate_proc($proc): void
{
    if (!is_resource($proc)) {
        return;
    }

    $status = proc_get_status($proc);

    if ($status['running'] === true) {
        // -1 = SIGTERM trên POSIX, trên Windows PHP dùng TerminateProcess.
        @proc_terminate($proc, 1);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(50000);
            $status = proc_get_status($proc);

            if ($status['running'] !== true) {
                break;
            }
        }

        if ($status['running'] === true && PHP_OS_FAMILY === 'Windows') {
            // Dự phòng: buộc giết theo PID (taskkill trả về 0 khi đã thành công).
            @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>nul');
        }
    }

    @proc_close($proc);
}

/** Dừng toàn bộ tiến trình con (gọi ở shutdown). */
function t_kill_servers(): void
{
    global $servers;

    foreach ($servers as $proc) {
        t_terminate_proc($proc);
    }

    $servers = [];
}

/** Dọn tệp tạm, cookie jar và tệp thư mẫu tạm của lần chạy này. */
function t_cleanup_files(): void
{
    global $tempFiles, $cookieJars;

    foreach ([...$tempFiles, ...$cookieJars] as $file) {
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }

    $tempFiles = [];
    $cookieJars = [];
}

register_shutdown_function(static function (): void {
    t_kill_servers();
    t_cleanup_files();
});

/* =============================================================================
    PHẦN 1 — KHỞI ĐỘNG MÁY CHỦ RIÊNG
    ============================================================================= */

/**
 * Tìm đường dẫn tới php.exe (trên Windows cần tệp thực thi đầy đủ).
 */
function t_php_binary(): string
{
    $binary = PHP_BINARY;

    if ($binary !== '' && is_file($binary)) {
        return $binary;
    }

    return 'php';
}

/**
 * Khởi động máy chủ `php -S` và chờ nó sẵn sàng.
 */
function t_start_server(int $port, string $docroot, string $label): void
{
    global $servers, $logFiles;

    $logFile = sys_get_temp_dir() . '/wa-tests-' . getmypid() . '-' . $label . '.log';
    $logFiles[] = $logFile;

    // Cổng đã bị chiếm từ trước? KHÔNG được âm thầm dùng lại máy chủ của
    // lần chạy trước — nếu không, bài kiểm thử sẽ chạy trên máy chủ lạ và
    // bằng chứng "tự bật máy chủ riêng" là sai.
    if (t_port_is_busy($port)) {
        t_say('[LỖI] Cổng ' . $port . ' đang bị chiếm bởi một tiến trình khác.');
        t_say('       Đây thường là máy chủ sót lại từ một lần chạy trước bị ngắt giữa chừng.');
        t_say('       Hãy dừng tiến trình đó rồi chạy lại (xem tài liệu: tìm và kết thúc');
        t_say('       php.exe đang lắng nghe cổng ' . $port . ').');
        exit(1);
    }

    // proc_open nhận MẢNG lệnh thì PHP tự chạy tiến trình trực tiếp, KHÔNG đi
    // qua cmd.exe. Nhờ vậy proc_terminate() giết đúng php.exe thay vì chỉ giết
    // vỏ cmd — nếu truyền CHUỖI lệnh trên Windows thì máy chủ sẽ bị sót lại.
    $args = [t_php_binary(), '-S', 'localhost:' . $port, '-t', $docroot];

    $pipes   = [];
    $process = proc_open(
        $args,
        [1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes,
        dirname(__DIR__)
    );

    if (!is_resource($process)) {
        t_say('[LỖI] Không khởi động được máy chủ ' . $label . '.');
        exit(1);
    }

    $servers[$port] = $process;

    // Chờ tối đa 10 giây cho tới khi cổng phản hồi.
    for ($attempt = 0; $attempt < 100; $attempt++) {
        usleep(100000);

        // Nếu tiến trình đã chết thì không có chờ vô ích nữa.
        $status = proc_get_status($process);

        if ($status['running'] !== true) {
            t_say('[LỖI] Máy chủ ' . $label . ' thoát ngay sau khi khởi động. Log:');
            t_say((string) @file_get_contents($logFile));
            exit(1);
        }

        $probe = @fsockopen('localhost', $port, $errno, $errstr, 0.3);

        if (is_resource($probe)) {
            fclose($probe);
            t_say('  ✓ máy chủ ' . $label . ' sẵn sàng trên cổng ' . $port);
            return;
        }
    }

    t_say('[LỖI] Máy chủ ' . $label . ' không khởi động được. Log:');
    t_say((string) @file_get_contents($logFile));
    exit(1);
}

/** Cổng này đang có tiến trình nào lắng nghe không? */
function t_port_is_busy(int $port): bool
{
    $probe = @fsockopen('localhost', $port, $errno, $errstr, 0.3);

    if (is_resource($probe)) {
        fclose($probe);
        return true;
    }

    return false;
}

/** Dừng máy chủ theo cổng (dùng cho T1 — tắt nguồn để chứng minh bản lưu tự đủ). */
function t_stop_server(int $port): void
{
    global $servers;

    if (!isset($servers[$port])) {
        return;
    }

    $proc = $servers[$port];

    if (is_resource($proc)) {
        t_terminate_proc($proc);
    }

    unset($servers[$port]);

    // Chờ cổng thực sự đóng lại.
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(100000);

        $probe = @fsockopen('localhost', $port, $errno, $errstr, 0.2);

        if (!is_resource($probe)) {
            break;
        }

        fclose($probe);
    }
}

/* =============================================================================
    PHẦN 2 — HTTP BẰNG CURL
    ============================================================================= */

/** Cookie jar mới cho một vai diễn (chủ sở hữu / người ngoài). */
function t_cookie_jar(string $who): string
{
    global $cookieJars;

    $jar = sys_get_temp_dir() . '/wa-tests-' . getmypid() . '-' . $who . '.cookies';
    $cookieJars[] = $jar;

    return $jar;
}

/**
 * Gọi HTTP bằng cURL, giữ cookie trong $jar.
 *
 * @param array<string,string>|null $post
 * @return array{status:int, body:string, headers:string, effective_url:string}
 */
function t_http(string $url, ?array $post = null, string $jar = '', bool $follow = true): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 40,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $raw = curl_exec($ch);

    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'body' => '', 'headers' => '', 'effective_url' => $url, 'error' => $error];
    }

    $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $effective  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return [
        'status'        => $status,
        'headers'       => substr((string) $raw, 0, $headerSize),
        'body'          => substr((string) $raw, $headerSize),
        'effective_url' => $effective,
        'error'         => '',
    ];
}

/** Lấy token CSRF từ HTML một trang bất kỳ của phiên đã đăng nhập. */
function t_csrf(string $url, string $jar): string
{
    $res = t_http($url, null, $jar);

    if (preg_match('#name="wa_csrf"\s+value="([^"]+)"#', $res['body'], $m)) {
        return $m[1];
    }

    return '';
}

/** Đăng nhập một tài khoản trên ứng dụng; trả về true nếu thành công. */
function t_login(string $email, string $password, string $jar): bool
{
    t_http(WA_T_APP_BASE . '/login.php', null, $jar);

    t_http($jar === '' ? '' : WA_T_APP_BASE . '/login.php', [
        'email'    => $email,
        'password' => $password,
        'next'     => '/libraries.php',
    ], $jar);

    $check = t_http(WA_T_APP_BASE . '/libraries.php', null, $jar);

    // Trang đăng nhập có trường mật khẩu -> phiên chưa vào.
    return !str_contains($check['body'], 'name="password"');
}

/** Mã của bản snapshot trả về từ URL trình đọc (reader.php?id=NNN). */
function t_snapshot_id_from(string $url): int
{
    if (preg_match('#reader\.php\?id=(\d+)#', $url, $m)) {
        return (int) $m[1];
    }

    return 0;
}

/** Mã bản lưu trong thẻ "Snapshot: SNAP-NNNN" của trang kết quả tìm kiếm. */
function t_snapshot_id_from_search(string $html): int
{
    if (preg_match('#SNAP-(\d+)#', $html, $m)) {
        return (int) $m[1];
    }

    return 0;
}

/* =============================================================================
    PHẦN 3 — KHỞI TẠO
    ============================================================================= */

t_say('=== WebArchive — 6 bài kiểm thử bắt buộc (P-FINAL) ===');
t_say('');
t_say('Mã lần chạy: ' . $runId);
t_say('');

t_say('[0] Khởi động máy chủ riêng của bộ kiểm thử');
t_start_server(WA_T_APP_PORT, dirname(__DIR__) . '/public', 'ung-dung');
t_start_server(WA_T_SAMPLE_PORT, dirname(__DIR__) . '/tests/samples', 'thu-mau');
t_say('');

/* ---------------------------------------------------------------------------
 * BẢO ĐẢM MÁY CHỦ THỬ NGHIỆM CỤC BỘ ĐÃ ĐƯỢC DUYỆT.
 *
 * Trang mẫu được phục vụ ở http://localhost:<port> — tên miền "localhost" là
 * địa chỉ mạng nội bộ nên engine tải trang chặn (bảo vệ SSRF). Quản trị viên
 * phải duyệt host này trong allowlist_domains, đúng cơ chế "duyệt một lần cho
 * cả domain". Script này CHỈ INSERT nếu chưa có (idempotent, không xoá dữ
 * liệu) — không sửa/xoá bất kỳ dòng dữ liệu người dùng nào.
 * ------------------------------------------------------------------------- */
$ensureHosts = dirname(__DIR__) . '/scripts/ensure_local_hosts.php';

if (is_file($ensureHosts)) {
    // Dùng proc_open() (đúng cách bộ kiểm thử đã dùng để khởi động máy chủ)
    // thay vì exec() — không phụ thuộc vào việc exec bị bật/tắt trong php.ini.
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ensureHosts);

    $pipes   = [];
    $process = @proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__)
    );

    if (is_resource($process)) {
        $ensureOutput = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $ensureExit = proc_close($process);

        foreach (preg_split('/\R/', (string) $ensureOutput) ?: [] as $line) {
            if (trim($line) !== '') {
                t_say('  · ' . trim($line));
            }
        }

        if ($ensureExit !== 0) {
            t_say('  ! Không bảo đảm được host thử nghiệm (mã thoát ' . $ensureExit . ').');
        }
    } else {
        t_say('  ! Không chạy được scripts/ensure_local_hosts.php — bỏ qua bước bảo đảm host.');
    }
}

$ownerJar = t_cookie_jar('chu-so-hu');
$outsiderJar = t_cookie_jar('nguoi-ngoai');

t_say('[1] Đăng nhập ' . WA_T_OWNER_EMAIL);

if (!t_login(WA_T_OWNER_EMAIL, WA_T_OWNER_PASS, $ownerJar)) {
    t_say('[LỖI] Không đăng nhập được chủ sở hữu.');
    exit(1);
}

t_say('  ✓ đăng nhập thành công');

t_say('[2] Tạo thư viện tạm qua library_action.php (hành động thật của ứng dụng)');

$libraryName = 'Thư viện KIỂM THỬ ' . $runId;
$csrf        = t_csrf(WA_T_APP_BASE . '/libraries.php', $ownerJar);

if ($csrf === '') {
    t_say('[LỖI] Không lấy được token CSRF.');
    exit(1);
}

$createRes = t_http(WA_T_APP_BASE . '/library_action.php', [
    'wa_csrf'    => $csrf,
    'action'     => 'create',
    'name'       => $libraryName,
    'description'=> 'Thư viện tạm cho bộ kiểm thử P-FINAL, sẽ bị xoá khi kết thúc.',
    'visibility' => 'private',
    'color'      => 'a',
    'icon'       => 'terminal',
], $ownerJar);

// library_action.php chuyển hướng thẳng sang library.php?slug=... khi tạo
// thành công — đọc slug ngay từ URL đích đó, khỏi phải dò HTML thẻ thẻ.
if (preg_match('#library\.php\?slug=([^&"\']+)#', $createRes['effective_url'], $m)) {
    $ctx['library_slug'] = urldecode($m[1]);
}

// Dự phòng: nếu URL đích không rõ, dò danh sách thư viện theo tên.
if ($ctx['library_slug'] === '') {
    $libraryPage = t_http(WA_T_APP_BASE . '/libraries.php', null, $ownerJar);

    if (preg_match_all('#href="[^"]*library\.php\?slug=([^"]+)"#', $libraryPage['body'], $matches)) {
        foreach ($matches[1] as $candidate) {
            $slugCandidate = urldecode($candidate);
            $probe = db()->prepare('SELECT name FROM `libraries` WHERE slug = ? LIMIT 1');
            $probe->execute([$slugCandidate]);

            if ((string) $probe->fetchColumn() === $libraryName) {
                $ctx['library_slug'] = $slugCandidate;
                break;
            }
        }
    }
}

if ($ctx['library_slug'] === '') {
    t_say('[LỖI] Không tạo được thư viện tạm.');
    exit(1);
}

$stmt = db()->prepare('SELECT id FROM `libraries` WHERE slug = ? LIMIT 1');
$stmt->execute([$ctx['library_slug']]);
$libraryId = (int) $stmt->fetchColumn();

if ($libraryId <= 0) {
    t_say('[LỖI] Không xác định được id thư viện tạm.');
    exit(1);
}

$ctx['library_id'] = $libraryId;
t_say('  ✓ thư viện «' . $libraryName . '» (id=' . $libraryId . ', slug=' . $ctx['library_slug'] . ')');

t_say('[3] Đăng ký tài khoản người ngoài qua register.php');

$ctx['outsider_email'] = 'ngoai-' . $runId . '@test.local';
$outsiderPassword    = 'Ngoai' . getmypid() . '123';

t_http(WA_T_APP_BASE . '/register.php', [
    'name'            => 'Người Ngoài Kiểm Thử',
    'email'           => $ctx['outsider_email'],
    'password'        => $outsiderPassword,
    'confirm_password'=> $outsiderPassword,
], $outsiderJar);

$stmt = db()->prepare('SELECT id FROM `users` WHERE email = ? LIMIT 1');
$stmt->execute([$ctx['outsider_email']]);
$ctx['outsider_id'] = (int) $stmt->fetchColumn();

if ($ctx['outsider_id'] <= 0) {
    t_say('[LỖI] Không đăng ký được tài khoản người ngoài.');
    exit(1);
}

if (!t_login($ctx['outsider_email'], $outsiderPassword, $outsiderJar)) {
    t_say('[LỖI] Không đăng nhập được tài khoản người ngoài.');
    exit(1);
}

t_say('  ✓ tài khoản ' . $ctx['outsider_email'] . ' (id=' . $ctx['outsider_id'] . ')');
t_say('');

/* =============================================================================
    PHẦN 4 — SÁU BÀI KIỂM THỬ
    ============================================================================= */

$results = [];

/**
 * Ghi kết quả một bài kiểm thử.
 *
 * @param string $evidence Bằng chứng ngắn gọn để in ra dòng kết quả.
 */
function t_record(string $id, bool $passed, string $evidence): void
{
    global $results;

    $results[$id] = ['pass' => $passed, 'evidence' => $evidence];

    printf(
        "%s %s — %s\n",
        $id,
        $passed ? 'PASS' : 'FAIL',
        $evidence
    );
}

/* ---------------------------------------------------------------------------
 * T1 — NGUỒN ĐÃ CHẾT: bản lưu vẫn đọc được sau khi tắt máy chủ nguồn.
 * ------------------------------------------------------------------------- */
$savePage = WA_T_APP_BASE . '/save.php';
$csrf     = t_csrf($savePage, $ownerJar);

$save1 = t_http($savePage, [
    'wa_csrf'   => $csrf,
    'url'       => WA_T_SAMPLE_BASE . '/bai-01.html',
    'library_id'=> (string) $ctx['library_id'],
    'tags'      => 'kiem-thu, t1',
], $ownerJar);

$t1Snapshot = t_snapshot_id_from($save1['effective_url']);
$ctx['t1_snapshot'] = $t1Snapshot;

$t1Ok = $t1Snapshot > 0;

if (!$t1Ok) {
    t_record('T1', false, 'không lưu được bai-01.html vào thư viện tạm');
} else {
    // Đường dẫn ảnh đã tải về máy chủ nội bộ (lấy từ bản lưu).
    $stmt = db()->prepare(
        "SELECT s.content_html FROM `snapshots` s WHERE s.id = ? LIMIT 1"
    );
    $stmt->execute([$t1Snapshot]);
    $contentHtml = (string) $stmt->fetchColumn();

    if (preg_match('#<img[^>]+src="([^"]+)"#i', $contentHtml, $m)) {
        $src = $m[1];

        if (str_starts_with($src, '/')) {
            $ctx['t1_image_url'] = WA_T_APP_BASE . $src;
        } elseif (str_starts_with($src, 'http')) {
            $ctx['t1_image_url'] = $src;
        } elseif (str_starts_with($src, 'img.php?')) {
            // FIX-01: ảnh phục vụ qua endpoint có xác thực (URL tương đối).
            $ctx['t1_image_url'] = WA_T_APP_BASE . '/' . $src;
        }
    }

    // TẮT máy chủ nguồn.
    t_stop_server(WA_T_SAMPLE_PORT);

    $readerRes = t_http(WA_T_APP_BASE . '/reader.php?id=' . $t1Snapshot, null, $ownerJar);
    $hasPhrase = str_contains($readerRes['body'], WA_T1_BODY_PHRASE);

    $imageStatus = 0;

    if ($ctx['t1_image_url'] !== '') {
        $imageRes = t_http($ctx['t1_image_url'], null, $ownerJar);
        $imageStatus = $imageRes['status'];
    }

    $t1Ok = $readerRes['status'] === 200
        && $hasPhrase
        && $imageStatus === 200;

    $t1Detail = sprintf(
        'nguồn đã tắt — reader=%d, có cụm "%s"=%s, ảnh cục bộ=%d',
        $readerRes['status'],
        WA_T1_BODY_PHRASE,
        $hasPhrase ? 'có' : 'KHÔNG',
        $imageStatus
    );

    // Bật lại máy chủ nguồn cho các bài sau.
    t_start_server(WA_T_SAMPLE_PORT, dirname(__DIR__) . '/tests/samples', 'thu-mau');

    t_record('T1', $t1Ok, $t1Detail);
}

/* ---------------------------------------------------------------------------
 * T2 — TÌM THEO CỤM CHỈ CÓ TRONG THÂN BÀI.
 * ------------------------------------------------------------------------- */
$csrf = t_csrf($savePage, $ownerJar);

t_http($savePage, [
    'wa_csrf'   => $csrf,
    'url'       => WA_T_SAMPLE_BASE . '/bai-02.html',
    'library_id'=> (string) $ctx['library_id'],
    'tags'      => 'kiem-thu, t2',
], $ownerJar);

$searchUrl = WA_T_APP_BASE . '/search.php?q=' . rawurlencode(WA_T2_BODY_PHRASE);
$searchRes = t_http($searchUrl, null, $ownerJar);

$t2Snapshot = t_snapshot_id_from_search($searchRes['body']);

$t2Ok = $searchRes['status'] === 200
    && $t2Snapshot > 0
    && str_contains($searchRes['body'], '<mark>')
    && str_contains($searchRes['body'], 'Phiên bản')
    && str_contains($searchRes['body'], 'Bài 2');

t_record('T2', $t2Ok, sprintf(
    'q="%s" -> SNAP-%d, có thẻ "Phiên bản" và đoạn trích <mark>',
    WA_T2_BODY_PHRASE,
    $t2Snapshot
));

/* ---------------------------------------------------------------------------
 * T3 — BA TRƯỜNG HỢP LỖI KHÁC NHAU.
 * ------------------------------------------------------------------------- */
$errorUrls = [
    'error_404'    => WA_T_SAMPLE_BASE . '/khong-ton-tai.html',
    'error_timeout'=> WA_T_SAMPLE_BASE . '/cham.php',
    'error_blocked'=> 'https://example.com/',
];

$foundStatuses = [];
$errorEvidence = [];

foreach ($errorUrls as $expectedCode => $url) {
    $csrf = t_csrf($savePage, $ownerJar);

    t_http($savePage, [
        'wa_csrf'   => $csrf,
        'url'       => $url,
        'library_id'=> (string) $ctx['library_id'],
        'tags'      => 'kiem-thu, t3',
    ], $ownerJar);

    // Đọc mã lỗi của bản snapshot MỚI NHẤT trong thư viện tạm.
    $stmt = db()->prepare(
        'SELECT s.fetch_status FROM `snapshots` s
           JOIN `saved_pages` sp ON sp.id = s.page_id
          WHERE sp.library_id = ? AND sp.source_url = ?
          ORDER BY s.id DESC LIMIT 1'
    );
    $stmt->execute([$ctx['library_id'], $url]);

    $actual = (string) $stmt->fetchColumn();

    if ($actual !== '') {
        $foundStatuses[$actual] = ($foundStatuses[$actual] ?? 0) + 1;
    }

    $errorEvidence[] = basename(parse_url($url, PHP_URL_PATH) ?: $url) . '=' . $actual;
}

$t3Ok = count(array_keys($foundStatuses)) === 3
    && ($foundStatuses['error_404'] ?? 0) >= 1
    && ($foundStatuses['error_timeout'] ?? 0) >= 1
    && ($foundStatuses['error_blocked'] ?? 0) >= 1;

t_record('T3', $t3Ok, 'ba trang luu co ba ma loi KHAC NHAU: ' . implode(', ', $errorEvidence));

/* ---------------------------------------------------------------------------
 * T4 — MÃ ĐỘC KHÔNG THỂ CHẠY ĐƯỢC TRONG BẢN LƯU.
 * ------------------------------------------------------------------------- */
$csrf = t_csrf($savePage, $ownerJar);

$save4 = t_http($savePage, [
    'wa_csrf'   => $csrf,
    'url'       => WA_T_SAMPLE_BASE . '/bai-11.html',
    'library_id'=> (string) $ctx['library_id'],
    'tags'      => 'kiem-thu, t4',
], $ownerJar);

$t4Snapshot = t_snapshot_id_from($save4['effective_url']);

$t4Ok = false;
$t4Detail = 'không lưu được bai-11.html';

if ($t4Snapshot > 0) {
    $stmt = db()->prepare(
        "SELECT fetch_status, content_html FROM `snapshots` WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$t4Snapshot]);
    $snap4 = $stmt->fetch();

    $status  = (string) ($snap4['fetch_status'] ?? '');
    $html    = (string) ($snap4['content_html'] ?? '');
    $lower   = strtolower($html);

    $hasScript  = str_contains($lower, '<script');
    $hasOnclick = str_contains($lower, 'onclick');
    $hasIframe  = str_contains($lower, '<iframe');
    $hasPhrase  = str_contains($html, WA_T4_BODY_PHRASE);

    $t4Ok = $status === 'success' && !$hasScript && !$hasOnclick && !$hasIframe && $hasPhrase;

    $t4Detail = sprintf(
        'SNAP-%d %s, script=%s, onclick=%s, iframe=%s, cụm "%s"=%s',
        $t4Snapshot,
        $status,
        $hasScript ? 'CÓ' : 'không',
        $hasOnclick ? 'CÓ' : 'không',
        $hasIframe ? 'CÓ' : 'không',
        WA_T4_BODY_PHRASE,
        $hasPhrase ? 'có' : 'KHÔNG'
    );
}

t_record('T4', $t4Ok, $t4Detail);

/* ---------------------------------------------------------------------------
 * T5 — PHIÊN BẢN + GHI CHÚ SỐNG SÓT QUA LẦN TẢI LẠI.
 * ------------------------------------------------------------------------- */
$ctx['t5_marker']    = 'CauHienThi' . date('His');
$ctx['t5_note_text'] = 'Ghi chú kiểm thử ' . $runId;
$t5FileName          = 'kiem-thu-t5-' . $runId . '.html';
$t5Path              = dirname(__DIR__) . '/tests/samples/' . $t5FileName;

$t5Ok        = false;
$t5Detail    = 'không tạo được tệp thư mẫu tạm';

$t5Original = <<<'HTML'
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>Bài kiểm thử T5</title>
<meta property="og:title" content="Bài kiểm thử T5">
</head>
<body>
<main class="noi-dung">
  <article>
    <h1>Bài kiểm thử T5</h1>
    <p>Đây là nội dung bản đầu tiên của bài kiểm thử T5, được ghi bởi bộ kiểm thử
       tự động của dự án. Nội dung này đủ dài để vượt qua ngưỡng hai trăm ký tự mà
       engine dùng để quyết định một trang có đáng lưu hay không, nhờ vậy bài kiểm thử
       này có thể kiểm tra một cách đầy đủ chuỗi công việc gồm lưu trang, ghi chú,
       tải lại trang và so sánh hai phiên bản với nhau. Cụm khoá độc đáo của bài này
       là t5khoaBienDich.</p>
    <p>Phiên bản này không có câu đánh dấu phiên bản mới, vì vậy khi tải lại trang mà
       thêm câu đánh dấu thì câu đó chỉ xuất hiện ở bản lưu thứ hai chứ không xuất
       hiện ở bản lưu thứ nhất, đúng như tên gọi của bài kiểm thử này là các phiên
       bản và ghi chú sống sót cùng nhau qua mọi lần tải lại trang.</p>
  </article>
</main>
</body>
</html>
HTML;

if (file_put_contents($t5Path, $t5Original) !== false) {
    $GLOBALS['tempFiles'][] = $t5Path;

    $csrf = t_csrf($savePage, $ownerJar);

    $save5 = t_http($savePage, [
        'wa_csrf'   => $csrf,
        'url'       => WA_T_SAMPLE_BASE . '/' . $t5FileName,
        'library_id'=> (string) $ctx['library_id'],
        'tags'      => 'kiem-thu, t5',
    ], $ownerJar);

    $snap1 = t_snapshot_id_from($save5['effective_url']);
    $ctx['t5_snapshot1'] = $snap1;

    if ($snap1 <= 0) {
        $t5Detail = 'không lưu được tệp thư mẫu tạm';
    } else {
        // Thêm ghi chú vào phiên bản 1.
        $csrf = t_csrf(WA_T_APP_BASE . '/reader.php?id=' . $snap1, $ownerJar);

        t_http(WA_T_APP_BASE . '/note.php', [
            'wa_csrf'    => $csrf,
            'action'     => 'add',
            'snapshot_id'=> (string) $snap1,
            'content'    => $ctx['t5_note_text'],
        ], $ownerJar);

        // Sửa tệp thư mẫu: thêm câu đánh dấu phiên bản 2.
        $edited = str_replace(
            '    <p>Phiên bản này không có câu đánh dấu phiên bản mới,',
            '    <p>' . $ctx['t5_marker'] . ' — câu đánh dấu chỉ có ở bản lưu thứ hai. '
            . 'Phiên bản này không có câu đánh dấu phiên bản mới,',
            $t5Original
        );

        file_put_contents($t5Path, $edited);

        // Tải lại trang -> sinh phiên bản 2.
        $csrf = t_csrf(WA_T_APP_BASE . '/reader.php?id=' . $snap1, $ownerJar);

        $refetch = t_http(WA_T_APP_BASE . '/refetch.php', [
            'wa_csrf'    => $csrf,
            'snapshot_id'=> (string) $snap1,
        ], $ownerJar);

        $snap2 = t_snapshot_id_from($refetch['effective_url']);
        $ctx['t5_snapshot2'] = $snap2;

        if ($snap2 <= 0) {
            $t5Detail = 'tải lại không sinh ra phiên bản 2';
        } else {
            $stmt = db()->prepare(
                'SELECT version_no, content_text FROM `snapshots` WHERE id = ? LIMIT 1'
            );

            $stmt->execute([$snap1]);
            $v1 = $stmt->fetch();
            $stmt->execute([$snap2]);
            $v2 = $stmt->fetch();

            $versionOk = (int) $v1['version_no'] === 1 && (int) $v2['version_no'] === 2;

            $markerInV2 = str_contains((string) $v2['content_text'], $ctx['t5_marker']);
            $markerInV1 = str_contains((string) $v1['content_text'], $ctx['t5_marker']);

            // Ghi chú của phiên bản 1 còn nguyên?
            $noteStmt = db()->prepare('SELECT content FROM `notes` WHERE snapshot_id = ? LIMIT 1');
            $noteStmt->execute([$snap1]);
            $note = $noteStmt->fetch();

            $noteAlive = $note !== false && str_contains((string) $note['content'], $ctx['t5_note_text']);

            $t5Ok = $versionOk && $markerInV2 && !$markerInV1 && $noteAlive;

            $t5Detail = sprintf(
                'v1=%d, v2=%d, đánh dấu ở v2=%s, ở v1=%s, ghi chú v1=%s',
                (int) $v1['version_no'],
                (int) $v2['version_no'],
                $markerInV2 ? 'có' : 'KHÔNG',
                $markerInV1 ? 'CÓ (sai)' : 'không',
                $noteAlive ? 'còn' : 'MẤT'
            );
        }
    }
}

t_record('T5', $t5Ok, $t5Detail);

/* ---------------------------------------------------------------------------
 * T6 — NGƯỜI NGOÀI KHÔNG VÀO ĐƯỢC.
 * ------------------------------------------------------------------------- */
$t6Ok     = false;
$t6Detail = 'không có bản lưu của T1 để thử quyền';

if ($ctx['t1_snapshot'] > 0) {
    $id = $ctx['t1_snapshot'];

    $outsiderReader = t_http(WA_T_APP_BASE . '/reader.php?id=' . $id, null, $outsiderJar);
    $outsiderExport = t_http(WA_T_APP_BASE . '/export.php?id=' . $id, null, $outsiderJar);
    $ownerExport    = t_http(WA_T_APP_BASE . '/export.php?id=' . $id, null, $ownerJar);

    $t6Ok = $outsiderReader['status'] === 403
        && $outsiderExport['status'] === 403
        && $ownerExport['status'] === 200;

    $t6Detail = sprintf(
        'người ngoài reader=%d, export=%d; chủ sở hữu export=%d',
        $outsiderReader['status'],
        $outsiderExport['status'],
        $ownerExport['status']
    );
}

t_record('T6', $t6Ok, $t6Detail);

/* =============================================================================
    PHẦN 5 — DỌN DẸP (chỉ qua hành động của chính ứng dụng)
    ============================================================================= */

t_say('');
t_say('[4] Dọn dẹp');

/* 4a) Xoá thư viện tạm qua library_action.php — hàm này tự xoá cả thư mục ảnh. */
$csrf = t_csrf(WA_T_APP_BASE . '/library.php?slug=' . urldecode($ctx['library_slug']), $ownerJar);

t_http(WA_T_APP_BASE . '/library_action.php', [
    'wa_csrf'    => $csrf,
    'action'     => 'delete',
    'library_id' => (string) $ctx['library_id'],
    'confirm'    => '1',
], $ownerJar);

$stmt = db()->prepare('SELECT COUNT(*) FROM `libraries` WHERE id = ?');
$stmt->execute([$ctx['library_id']]);

$libraryGone = (int) $stmt->fetchColumn() === 0;

t_say('  ' . ($libraryGone ? '✓' : '✗') . ' xoá thư viện tạm (kèm thư mục ảnh) — '
    . ($libraryGone ? 'còn 0 dòng trong CSDL' : 'vẫn còn dòng!'));

/* 4b) Xoá tài khoản người ngoài bằng tài khoản CSDL của ứng dụng.
       Không xoá tay tệp nào trong public/uploads/. */
if ($ctx['outsider_id'] > 0) {
    // Khoá trước để không ai đăng nhập được trong lúc dọn dẹp.
    $lock = db()->prepare("UPDATE `users` SET `status` = 'locked' WHERE id = ?");
    $lock->execute([$ctx['outsider_id']]);

    $del = db()->prepare('DELETE FROM `users` WHERE id = ?');
    $del->execute([$ctx['outsider_id']]);

    $stmt = db()->prepare('SELECT COUNT(*) FROM `users` WHERE id = ?');
    $stmt->execute([$ctx['outsider_id']]);

    $userGone = (int) $stmt->fetchColumn() === 0;

    t_say('  ' . ($userGone ? '✓' : '✗') . ' xoá tài khoản người ngoài '
        . $ctx['outsider_email']);
}

/* 4c) Tệp thư mẫu tạm và cookie jar — xoá trong shutdown handler. */
t_say('  ✓ xoá tệp thư mẫu tạm và cookie jar');

t_kill_servers();
t_say('  ✓ dừng cả hai máy chủ của bộ kiểm thử');

/* =============================================================================
    PHẦN 6 — TỔNG KẾT
    ============================================================================= */

t_say('');
t_say('=' . str_repeat('=', 66));
t_say('TỔNG KẾT');
t_say('=' . str_repeat('=', 66));

$passed = 0;

foreach (['T1', 'T2', 'T3', 'T4', 'T5', 'T6'] as $id) {
    $entry = $results[$id] ?? ['pass' => false, 'evidence' => 'không chạy'];

    printf("  %s  %-4s  %s\n", $entry['pass'] ? 'PASS' : 'FAIL', $id, $entry['evidence']);

    if ($entry['pass']) {
        $passed++;
    }
}

t_say(str_repeat('-', 70));
t_say(sprintf('Kết quả: %d/6 bài kiểm thử PASS.', $passed));
t_say(str_repeat('-', 70));

exit($passed === 6 ? 0 : 1);