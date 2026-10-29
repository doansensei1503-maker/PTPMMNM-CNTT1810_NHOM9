<?php
/**
 * src/search.php
 * -----------------------------------------------------------------------------
 * TÌM KIẾM TỰ XÂY DỰNG TRÊN CHỈ MỤC ĐẢO (P-SEARCH) — Topic 16.
 *
 * ── VÌ SAO TỰ LÀM, KHÔNG DÙNG FULLTEXT? ──────────────────────────────────────
 * Đề tài yêu cầu phần lưu trữ, phiên bản, tìm kiếm và phân quyền phải TỰ XÂY
 * và phải giải thích được trong phần vấn đáp. Vì vậy dự án KHÔNG tạo FULLTEXT
 * index và KHÔNG dùng MATCH ... AGAINST của MySQL. Thay vào đó:
 *
 *     1. LẬP CHỈ MỤC: mỗi từ trong (tiêu đề + thân) của một bản snapshot được
 *        tách ra, hạ chữ thường, bỏ dấu tiếng Việt, rồi ghi thành MỘT DÒNG
 *        (từ đã bỏ dấu, snapshot) kèm số lần xuất hiện trong tiêu đề
 *        (count_title) và trong thân (count_body).
 *
 *     2. TÌM KIẾM: câu truy vấn của người dùng được tách và bỏ dấu y hệt, rồi
 *        đối chiếu với cột `term_folded`. Ngữ nghĩa là AND: một bản snapshot
 *        chỉ được trả về khi chứa TẤT CẢ các từ của câu truy vấn.
 *
 *     3. XẾP HẠNG:  score = 3 × SUM(count_title) + SUM(count_body)
 *        (trọng số 3 cho tiêu đề vì tiêu đề đáng tin hơn phần thân), sắp theo
 *        score giảm dần rồi fetched_at giảm dần.
 *
 * ── VÌ SAO BỎ DẤU? ───────────────────────────────────────────────────────────
 * Người Việt gõ "luu trang" (phím không dấu) nhưng trong bài viết lưu được là
 * "lưu trang". Chỉ có thể so khớp được hai bên nếu cả hai cùng được bỏ dấu.
 * Bảng bỏ dấu DÙNG CHUNG nằm trong src/bootstrap.php (wa_vi_fold_map) — dự án
 * chỉ có MỘT bảng bỏ dấu, không chép thêm bản thứ hai ở đây.
 *
 * ── PHÂN QUYỀN ────────────────────────────────────────────────────────────────
 * Bộ lọc quyền truy cập nằm TRONG CÂU SQL (wa_library_access_sql): chỉ thấy
 * bản snapshot thuộc thư viện mà người dùng sở hữu, là thành viên, hoặc là
 * quản trị viên. Việc lọc này không phụ thuộc vào giao diện.
 * -------------------------------------------------------------------------- */

declare(strict_types=1);

require_once __DIR__ . '/queries.php';

/* =============================================================================
    PHẦN 1 — TOKENIZER
    ============================================================================= */

/** Từ khoá bị bỏ vì quá ngắn (không đủ 2 ký tự). */
const WA_SEARCH_MIN_TOKEN_LEN = 2;

/** Độ dài tối đa của một token (khớp VARCHAR(100) của bảng search_terms). */
const WA_SEARCH_MAX_TOKEN_LEN = 100;

/**
 * Tách văn bản thành danh sách token kèm số lần xuất hiện.
 *
 * LUẬT (đúng đặc tả P-SEARCH):
 *   1. Hạ chữ thường bằng mb_strtolower().
 *   2. Tách ở MỌI ký tự không phải chữ cái hoặc chữ số Unicode — tức là dấu
 *      chấm, gạch ngang, gạch dưới, dấu phẩy, hai chấm, ngoặc, xuống dòng...
 *      đều là ranh giới. Vì vậy "khoa_tu_ban_than_webarchive" và "khoa-tu-ban-than"
 *      đều ra cùng các token.
 *   3. Bỏ token ngắn hơn 2 ký tự (số "1" hay dấu câu lọt vào không có nghĩa).
 *   4. Cắt token dài hơn 100 ký tự.
 *
 * Tiếng Việt KHÔNG dùng bộ tách từ theo từ điển — tách theo khoảng trắng là đủ,
 * và đó chính là ưu điểm của cách làm này: không cần thêm gói tiếng Việt nào.
 *
 * @return array<string,int> token (nguyên bản đã hạ chữ thường) => số lần xuất hiện
 */
function wa_search_tokenize(string $text): array
{
    $text = mb_strtolower($text, 'UTF-8');

    // \P{L} = chữ cái Unicode, \P{N} = chữ số Unicode. Cả hai đều khớp tiếng Việt
    // có dấu (vì chúng là chữ Latin dạng precomposed, vẫn thuộc \p{L}).
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

    if ($parts === false || $parts === []) {
        return [];
    }

    $counts = [];

    foreach ($parts as $part) {
        $length = mb_strlen($part, 'UTF-8');

        // Bỏ token quá ngắn (1 ký tự) và token quá dài (> 100 ký tự).
        if ($length < WA_SEARCH_MIN_TOKEN_LEN) {
            continue;
        }

        if ($length > WA_SEARCH_MAX_TOKEN_LEN) {
            $part   = mb_substr($part, 0, WA_SEARCH_MAX_TOKEN_LEN, 'UTF-8');
            $length = WA_SEARCH_MAX_TOKEN_LEN;
        }

        $counts[$part] = ($counts[$part] ?? 0) + 1;
    }

    return $counts;
}

/* =============================================================================
    PHẦN 2 — BỎ DẤU TIẾNG VIỆT
    ============================================================================= */

/**
 * Bỏ dấu tiếng Việt trong một token.
 *
 *   a/ă/â → a      e/ê → e      i → i      o/ô/ơ → o      u/ư → u
 *   y → y          đ → d        và mọi dấu thanh đều bị xoá.
 *
 * Hàm này CHỈ làm một việc: gọi bảng bỏ dấu dùng chung `wa_vi_fold_map()`
 * khai báo trong src/bootstrap.php. Toàn dự án chỉ có MỘT bảng bỏ dấu.
 */
function wa_search_fold(string $token): string
{
    // Hạ chữ hoa trước — bảng bỏ dấu nhận cả chữ hoa lẫn chữ thường, nhưng
    // token đưa vào thường đã hạ chữ thường rồi (qua wa_search_tokenize).
    $token = mb_strtolower($token, 'UTF-8');

    return strtr($token, wa_vi_fold_map());
}

/**
 * Danh sách từ đã bỏ dấu của một câu truy vấn, giữ thứ tự và KHÔNG trùng lặp.
 *
 * @return string[]
 */
function wa_search_query_terms(string $query): array
{
    $terms = [];

    foreach (array_keys(wa_search_tokenize($query)) as $token) {
        $folded = wa_search_fold((string) $token);

        if ($folded !== '' && !in_array($folded, $terms, true)) {
            $terms[] = $folded;
        }
    }

    return $terms;
}

/* =============================================================================
    PHẦN 3 — LẬP CHỈ MỤC
    ============================================================================= */

/**
 * Lập lại chỉ mục tìm kiếm cho MỘT bản snapshot.
 *
 * Quy trình:
 *   1. Xoá toàn bộ dòng cũ của snapshot này (idempotent — gọi lại nhiều lần
 *      cho cùng một snapshot vẫn cho kết quả giống hệt nhau).
 *   2. Tách token của TIÊU ĐỀ và của THÂN BÀI riêng biệt.
 *   3. Gộp theo dạng ĐÃ BỎ DẤU: một mục (từ gốc đầu tiên, tần suất).
 *      Vì bảng có UNIQUE (term_folded, snapshot_id), nên "lưu" và "Lưu" và
 *      "lƯU" của cùng một bản snapshot chỉ tạo ra MỘT dòng với số lần cộng dồn.
 *   4. INSERT từng dòng.
 *
 * CHỈ gọi cho snapshot có fetch_status = 'success' — một lần lưu thất bại không
 * bao giờ có dòng chỉ mục, nên tìm kiếm không thể trả về một lần lưu lỗi.
 *
 * @return int Số dòng chỉ mục đã ghi cho snapshot này.
 */
function wa_search_index_snapshot(int $snapshotId): int
{
    $stmt = db()->prepare('SELECT title, content_text, fetch_status FROM `snapshots` WHERE id = ? LIMIT 1');
    $stmt->execute([$snapshotId]);
    $snapshot = $stmt->fetch();

    if ($snapshot === false || (string) $snapshot['fetch_status'] !== 'success') {
        // Không phải bản đọc hợp lệ → bảo đảm không còn dòng chỉ mục nào của nó.
        $delete = db()->prepare('DELETE FROM `search_terms` WHERE snapshot_id = ?');
        $delete->execute([$snapshotId]);

        return 0;
    }

    // (1) Xoá dòng cũ trước — đây là chỗ biến "lập chỉ mục" thành idempotent.
    $delete = db()->prepare('DELETE FROM `search_terms` WHERE snapshot_id = ?');
    $delete->execute([$snapshotId]);

    // (2) Tách token riêng cho tiêu đề và thân bài.
    $titleTokens = wa_search_tokenize((string) ($snapshot['title'] ?? ''));
    $bodyTokens  = wa_search_tokenize((string) ($snapshot['content_text'] ?? ''));

    // (3) Gộp theo dạng đã bỏ dấu.
    //     $merged[folded] = ['term' => token gốc đầu tiên, 'title' => n, 'body' => n]
    $merged = [];

    foreach ([$titleTokens, $bodyTokens] as $position => $tokens) {
        foreach ($tokens as $token => $count) {
            $token  = (string) $token;
            $folded = wa_search_fold($token);

            if ($folded === '') {
                continue;
            }

            if (!isset($merged[$folded])) {
                $merged[$folded] = [
                    'term'   => mb_substr($token, 0, WA_SEARCH_MAX_TOKEN_LEN, 'UTF-8'),
                    'title'  => 0,
                    'body'   => 0,
                ];
            }

            if ($position === 0) {
                $merged[$folded]['title'] += $count;
            } else {
                $merged[$folded]['body'] += $count;
            }
        }
    }

    if ($merged === []) {
        return 0;
    }

    // (4) Ghi vào bảng.
    $insert = db()->prepare(
        'INSERT INTO `search_terms` (`term`, `term_folded`, `snapshot_id`, `count_title`, `count_body`)
         VALUES (?, ?, ?, ?, ?)'
    );

    $written = 0;

    foreach ($merged as $folded => $data) {
        $insert->execute([
            $data['term'],
            $folded,
            $snapshotId,
            $data['title'],
            $data['body'],
        ]);
        $written++;
    }

    return $written;
}

/* =============================================================================
    PHẦN 4 — TRUY VẤN TÌM KIẾM (NGỮ NGHĨA AND)
    ============================================================================= */

/**
 * Tìm kiếm trên CHỈ MỤC ĐẢO tự xây dựng.
 *
 * Ngữ nghĩa AND: mỗi từ của câu truy vấn được đối chiếu riêng với
 * `search_terms.term_folded`. Một snapshot chỉ được trả về khi có đủ TẤT CẢ
 * các từ đó (đếm bằng số dòng khớp phải bằng số từ truy vấn).
 *
 * Bộ lọc quyền truy cập và điều kiện `fetch_status = 'success'` nằm TRONG SQL —
 * không kiểm tra ở PHP, nên không thể vòng qua bằng cách sửa tham số GET.
 *
 * @param array{q?:string,tag?:string,domain?:string,library?:string} $filters
 * @return array<int, array<string, mixed>>
 */
function wa_search_snapshots(array $filters, ?array $actor = null): array
{
    $actor = $actor ?? wa_actor();
    [$accessSql, $accessParams] = wa_library_access_sql($actor);

    $query  = trim((string) ($filters['q'] ?? ''));
    $tag    = trim((string) ($filters['tag'] ?? ''));
    $domain = trim((string) ($filters['domain'] ?? ''));

    // Danh sách từ của câu truy vấn. Rỗng khi người dùng chỉ lọc theo nhãn
    // hoặc tên miền mà không gõ từ khoá.
    $terms = wa_search_query_terms($query);

    // Không có từ khoá VÀ không có bộ lọc nào -> không có gì để tra.
    if ($terms === [] && $tag === '' && $domain === '') {
        return [];
    }

    $termCount = count($terms);
    $bind      = $accessParams;

    // Danh sách IN: MỘT giá trị một tham số riêng.
    //
    // LƯU Ý KỸ THUẬT: prepares thật của PDO (ATTR_EMULATE_PREPARES = false)
    // không cho phép một tham số có tên xuất hiện HAI LẦN trong cùng câu lệnh
    // (sẽ sinh SQLSTATE[HY093]). Danh sách từ truy vấn xuất hiện ở HAI chỗ
    // (subquery điều kiện AND và JOIN tính điểm), nên phải sinh HAI bộ tên
    // tham số khác nhau cho cùng các giá trị đó.
    $matchNames  = [];
    $scoreNames  = [];

    foreach ($terms as $index => $term) {
        $matchName          = ':match_term_' . $index;
        $scoreName          = ':score_term_' . $index;
        $matchNames[$index] = $matchName;
        $scoreNames[$index] = $scoreName;

        $bind[$matchName] = $term;
        $bind[$scoreName] = $term;
    }

    $where = [
        "s.fetch_status = 'success'",
        // Bài đã xoá mềm không bao giờ xuất hiện trong kết quả tìm kiếm.
        "sp.status <> 'deleted'",
        $accessSql,
    ];

    // Điều kiện quyết định ngữ nghĩa AND: số từ ĐÃ KHỚP phải đúng bằng số
    // từ trong câu truy vấn. Vì UNIQUE (term_folded, snapshot_id), mỗi từ
    // chỉ có tối đa một dòng cho mỗi snapshot, nên COUNT(DISTINCT term_folded)
    // ở đây chính là số từ của truy vấn mà bản snapshot đó có thực sự chứa.
    //
    // P-FIX-CN4: dùng COUNT(DISTINCT st.term_folded) chứ không phải COUNT(*).
    // Lý do: truy vấn có thể sinh ra hai từ truy vấn khác nhau nhưng CÙNG gấp
    // dấu (ví dụ "công nghệ" và "cong nghe" đều fold thành "cong nghe") — khi
    // đó COUNT(*) đếm 2 dòng cho 1 từ khớp thật, khiến điều kiện = :match_total
    // KHÔNG BAO GIỜ đúng và bài hợp lệ bị loại khỏi kết quả.
    if ($terms !== []) {
        $where[] = '(SELECT COUNT(DISTINCT st.term_folded)
            FROM `search_terms` st
           WHERE st.snapshot_id = s.id
             AND st.term_folded IN (' . implode(', ', $matchNames) . ')) = :match_total';
        $bind[':match_total'] = $termCount;
    }

    if ($tag !== '') {
        // Khớp cả hai hình thức '#php' và 'php' để nhãn cũ lưu chưa có '#'
        // vẫn lọc được khi giao diện hiển thị '#php' (và ngược lại).
        $tagAlt = str_starts_with($tag, '#') ? substr($tag, 1) : ('#' . $tag);
        $where[] = 'EXISTS (SELECT 1 FROM `page_tags` pt JOIN `tags` t ON t.id = pt.tag_id
                             WHERE pt.page_id = sp.id AND t.name IN (:tag_filter, :tag_filter_alt))';
        $bind[':tag_filter']     = $tag;
        $bind[':tag_filter_alt'] = $tagAlt;
    }

    if ($domain !== '') {
        $where[]                = 'sp.domain = :domain_filter';
        $bind[':domain_filter'] = $domain;
    }

    // XẾP HẠNG — công thức đúng đặc tả:
    //     score = 3 × SUM(count_title) + SUM(count_body)
    // JOIN chỉ nối đúng các dòng chỉ mục thuộc tập từ truy vấn, nên SUM ở đây
    // cộng dồn đúng các từ đã khớp (không tính nhầm từ khác trong bài).
    // count_title được nhân 3 vì tiêu đề đáng tin hơn phần thân.
    //
    // Khi KHÔNG có từ khoá (chỉ lọc theo nhãn / tên miền) thì không cần JOIN
    // bảng chỉ mục đảo — điểm đánh giá bằng 0 và sắp xếp theo thời điểm tải.
    $joinIndex = $terms === []
        ? ''
        : 'JOIN `search_terms` st ON st.snapshot_id = s.id
                              AND st.term_folded IN (' . implode(', ', $scoreNames) . ')';

    $scoreExpr = $terms === []
        ? '0'
        : '(3 * COALESCE(SUM(st.count_title), 0) + COALESCE(SUM(st.count_body), 0))';

    $groupBy = $terms === []
        ? ''
        : 'GROUP BY s.id, s.page_id, s.version_no, s.title, s.content_text, s.fetched_at,
                sp.domain, sp.library_id, l.name, l.slug';

    $sql = "
        SELECT
            s.id, s.page_id, s.version_no, s.title, s.content_text, s.fetched_at,
            sp.domain, sp.library_id, l.name AS library_name, l.slug AS library_slug,
            {$scoreExpr} AS score,
            (SELECT COUNT(*) FROM `assets` a WHERE a.snapshot_id = s.id AND a.mime_type LIKE 'image/%') AS image_count
          FROM `snapshots` s
          JOIN `saved_pages` sp ON sp.id = s.page_id
          JOIN `libraries` l    ON l.id = sp.library_id
          {$joinIndex}
         WHERE " . implode(' AND ', $where) . "
         {$groupBy}
       ORDER BY score DESC, s.fetched_at DESC, s.id DESC
       LIMIT 200
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute($bind);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $row['id']           = (int) $row['id'];
        $row['page_id']      = (int) $row['page_id'];
        $row['version_no']   = (int) $row['version_no'];
        $row['score']        = (int) $row['score'];
        $row['image_count']  = (int) $row['image_count'];
        $row['saved_at']     = wa_datetime((string) $row['fetched_at']);
        $row['tags']         = wa_page_tags((int) $row['page_id']);
        $row['snippet']      = wa_search_snippet((string) $row['content_text'], $terms);

        $rows[] = $row;
    }

    return $rows;
}

/* =============================================================================
    PHẦN 5 — ĐOẠN TRÍCH + LÀM NỔI BẬT <mark> THEO DẠNG ĐÃ BỎ DẤU
    ============================================================================= */

/** Độ dài cửa sổ đoạn trích (ký tự), tính cả hai bên từ khoá. */
const WA_SEARCH_SNIPPET_WINDOW = 200;

/**
 * Dựng đoạn trích quanh từ khoá khớp đầu tiên, kèm <mark> bọc quanh các từ
 * khớp. Kết quả đã được ESCAPE HTML — gọi vào mẫu là in thẳng, không escape
 * lần hai.
 *
 * Cách làm — vì sao KHÔNG dùng `str_ireplace`:
 *   Người dùng gõ "luu trang" nhưng bài viết chứa "lưu trang". So khớp phải
 *   chạy trên DẠNG ĐÃ BỎ DẤU, còn phần hiển thị phải GIỮ NGUYÊN DẤU. Nên
 *   ở đây ta tách nội dung thành các token kèm vị trí, so khớp trên bản bỏ
 *   dấu của từng token, rồi mới ghép lại văn bản gốc (nên vẫn là "lưu trang"
 *   có dấu) và bọc <mark> quanh đúng khoảng ký tự đó.
 *
 * @param string[] $terms Danh sách từ đã bỏ dấu của câu truy vấn.
 */
function wa_search_snippet(string $text, array $terms, int $window = WA_SEARCH_SNIPPET_WINDOW): string
{
    // content_text đã là văn bản thuần, nhưng vẫn bỏ thẻ + gộp khoảng trắng
    // để đoạn trích luôn gọn một dòng.
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

    if ($text === '' || $terms === []) {
        return '';
    }

    // 1) Tách token kèm vị trí (byte) trong văn bản gốc.
    $matches = preg_match_all('/[\p{L}\p{N}]+/u', $text, $hits, PREG_OFFSET_CAPTURE);

    if ($matches === false || $matches === 0) {
        return wa_search_escape(wa_trim($text, $window));
    }

    $want = array_flip(array_map('strval', $terms));

    // 2) So khớp trên dạng bỏ dấu, gom thành các CHÙM TỪ KHỚP.
    //
    //    "Chùm" là một dãy từ khớp liên tiếp, trong đó các từ nối nhau chỉ bằng
    //    KÝ TỰ PHÂN CÁCH (gạch dưới, gạch ngang, dấu chấm, khoảng trắng...). Nhờ vậy
    //    "khoa_tu_ban_than_webarchive" — vốn là MỘT từ khoá nhưng bị tokenizer tách
    //    thành 5 token vì dấu gạch dưới không phải chữ/số — vẫn được coi là một
    //    chùm duy nhất và được bọc <mark> TRỌN VẸN.
    //
    //    Đoạn trích neo vào chùm DÀI NHẤT chứ không phải chùm đầu tiên: với từ
    //    khoá trên, từ khớp đầu tiên trong bài là "webarchive" ở câu giới thiệu;
    //    bám vào đó thì đoạn trích không hiện dấu hiệu của từ khoá.
    $runs = [];

    $runStart  = null;
    $runEnd    = 0;
    $runTokens = 0;

    foreach ($hits[0] as $hit) {
        $token = (string) $hit[0];
        $start = (int) $hit[1];
        $end   = $start + strlen($token);

        if (!isset($want[wa_search_fold($token)])) {
            // Từ KHÔNG khớp nằm giữa chừng -> chùm đang xét bị đứt tại đây.
            if ($runStart !== null) {
                $runs[] = [$runStart, $runEnd, $runTokens];
                $runStart  = null;
                $runTokens = 0;
            }

            continue;
        }

        if ($runStart === null) {
            $runStart  = $start;
            $runEnd    = $end;
            $runTokens = 1;
            continue;
        }

        // Nối vào chùm hiện tại. Khoảng cách byte giữa cuối chùm và đầu token này
        // CHÍNH LÀ dấu phân cách — ta giữ nguyên nó trong khoảng <mark> để cụm từ
        // khoá hiện ra liền mạch, kể cả khi có gạch dưới bên trong.
        $gap = substr($text, $runEnd, $start - $runEnd);

        if (trim($gap, " \t\n\r\0\x0B_-.,:;/\\|()[]{}<>\"'") === '') {
            $runEnd    = $end;
            $runTokens++;
            continue;
        }

        // Có ký tự thực sự khác xen giữa (chẳng hạn "trang WebArchive") -> chùm mới.
        $runs[]    = [$runStart, $runEnd, $runTokens];
        $runStart  = $start;
        $runEnd    = $end;
        $runTokens = 1;
    }

    if ($runStart !== null) {
        $runs[] = [$runStart, $runEnd, $runTokens];
    }

    // 3) Không từ nào khớp trong thân → lấy đầu nội dung.
    if ($runs === []) {
        return wa_search_escape(wa_trim($text, $window));
    }

    // 4) Mốc neo = chùm dài nhất (đồng thời là chùm sớm nhất trong số các
    //    chùm dài nhất, nên kết quả ổn định không phụ thuộc thứ tự duyệt).
    $anchor = $runs[0];

    foreach ($runs as $run) {
        if ($run[2] > $anchor[2]) {
            $anchor = $run;
        }
    }

    $firstPos = $anchor[0];

    // 4) Cắt cửa sổ ~200 ký tự quanh chùm từ khớp đã chọn. Vị trí byte do
    //    preg trả về (PREG_OFFSET_CAPTURE) phải quy đổi sang vị trí KÝ TỰ
    //    đúng một lần bằng mb_strlen(substr(...)). mb_substr() nhận tham số
    //    theo KÝ TỰ, nên truyền thẳng vị trí byte sẽ dịch cửa sổ lệch (văn bản
    //    tiếng Việt có nhiều byte trên mỗi ký tự) và đoạn trích có thể trượt
    //    khỏi từ khoá — khiến <mark> không bao giờ hiện.
    $charLen  = mb_strlen($text, 'UTF-8');
    $anchorCh = mb_strlen(substr($text, 0, $firstPos), 'UTF-8');
    $startCh  = max(0, $anchorCh - intdiv($window, 2));
    $startCh  = min($startCh, max(0, $charLen - 1));
    $endCh    = min($charLen, $startCh + $window);
    $startCh  = max(0, $endCh - $window);

    $snippet = mb_substr($text, $startCh, $endCh - $startCh, 'UTF-8');
    $base    = strlen(mb_substr($text, 0, $startCh, 'UTF-8'));   // byte của điểm bắt đầu

    // 5) Dịch các chùm từ khớp sang vị trí TƯƠNG ĐỐI trong đoạn đã cắt.
    $relative = [];
    $snippetB = strlen($snippet);

    foreach ($runs as [$from, $to]) {
        if ($to <= $base) {
            continue;   // nằm trước cửa sổ
        }

        if ($from >= $base + $snippetB) {
            break;      // nằm sau cửa sổ (đã sắp theo thứ tự tăng dần)
        }

        $range = [max(0, $from - $base), min($snippetB, $to - $base)];

        // Gộp hai chùm bị cắt cụt ở mép cửa sổ thành một khoảng liền mạch,
        // tránh sinh hai thẻ <mark> chồng lên nhau.
        $lastIndex = count($relative) - 1;

        if ($lastIndex >= 0 && $relative[$lastIndex][1] >= $range[0]) {
            $relative[$lastIndex][1] = max($relative[$lastIndex][1], $range[1]);
            continue;
        }

        $relative[] = $range;
    }

    // 6) Ghép lại: escape từng đoạn, bọc <mark> quanh khoảng khớp.
    $html = wa_search_wrap_marks($snippet, $relative);

    // 7) Dấu "…" ở hai đầu cho biết đây là trích đoạn, không phải cả bài.
    if ($startCh > 0) {
        $html = '…' . $html;
    }

    if ($endCh < $charLen) {
        $html .= '…';
    }

    return $html;
}

/**
 * Bọc <mark> quanh các từ khớp trong TIÊU ĐỀ bài viết, theo cùng cách so khớp
 * trên dạng đã bỏ dấu như đoạn trích thân bài.
 *
 * Kết quả đã escape HTML — in thẳng vào mẫu được.
 *
 * @param string[] $terms Danh sách từ đã bỏ dấu của câu truy vấn.
 */
function wa_search_highlight_title(string $title, array $terms): string
{
    if ($terms === []) {
        return wa_search_escape($title);
    }

    $matches = preg_match_all('/[\p{L}\p{N}]+/u', $title, $hits, PREG_OFFSET_CAPTURE);

    if ($matches === false || $matches === 0) {
        return wa_search_escape($title);
    }

    $want       = array_flip(array_map('strval', $terms));
    $ranges     = [];
    $titleBytes = strlen($title);

    foreach ($hits[0] as $hit) {
        $token = (string) $hit[0];
        $start = (int) $hit[1];

        if (!isset($want[wa_search_fold($token)])) {
            continue;
        }

        $ranges[] = [$start, min($titleBytes, $start + strlen($token))];
    }

    return wa_search_wrap_marks($title, $ranges);
}

/**
 * Ghép văn bản gốc thành HTML đã escape, bọc <mark> quanh các khoảng byte
 * cho trước (đã sắp theo thứ tự tăng dần).
 *
 * @param array<int, array{0:int,1:int}> $ranges
 */
function wa_search_wrap_marks(string $text, array $ranges): string
{
    if ($ranges === []) {
        return wa_search_escape($text);
    }

    $html   = '';
    $cursor = 0;
    $length = strlen($text);

    foreach ($ranges as [$from, $to]) {
        $from = max($from, $cursor);
        $to   = min($to, $length);

        if ($to <= $from) {
            continue;
        }

        $html .= wa_search_escape(substr($text, $cursor, $from - $cursor));
        $html .= '<mark>' . wa_search_escape(substr($text, $from, $to - $from)) . '</mark>';
        $cursor = $to;
    }

    return $html . wa_search_escape(substr($text, $cursor));
}

/**
 * Escape HTML cho đoạn trích tìm kiếm.
 *
 * Tách riêng khỏi wa_e() của src/bootstrap.php để người đọc thấy rõ: mọi
 * thứ lấy từ `content_text` đều đi qua escape trước khi chạm vào HTML —
 * kể cả đoạn đã được bọc <mark>.
 */
function wa_search_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}