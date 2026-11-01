<?php declare(strict_types=1);
/**
 * Email Case Workspace — search.
 *
 * Searches the LOCAL store. Microsoft Graph is never called for a user search:
 * it is rate-limited, it is slow, and it cannot see the case links that half
 * these filters are about.
 *
 * SYNTAX
 * ------
 *   from:support@hexagon.com   to:  cc:  participant:
 *   subject:scirocco
 *   case:CASE-00142            folder:Inbox
 *   after:2026-09-01           before:2026-09-18
 *   has:attachment             is:unread | is:read | is:linked | is:unlinked
 *   "RTune calibration"        bare keywords
 *
 * Unprefixed words and quoted phrases go to full text; everything else becomes
 * an indexed predicate. A query with no keywords at all is a pure filter and
 * touches no full-text index, which is what makes "is:unlinked in this folder"
 * cheap.
 *
 * THE FULL-TEXT FLOOR, AND WHY THERE IS A LIKE FALLBACK
 * -----------------------------------------------------
 * InnoDB will not index a token shorter than innodb_ft_min_token_size (3 on
 * this server, so two-character terms are invisible) and drops its stopword
 * list (with, from, this, ...). MATCH ... AGAINST then returns NOTHING for such
 * a term — "silently" being the problem: the user gets an empty result list and
 * concludes the mail is not there.
 *
 * Tokens that full text cannot serve are therefore routed to a LIKE predicate
 * instead. LIKE '%term%' cannot use an index and is the slow path, which is
 * precisely why it is used only for the tokens that would otherwise return a
 * wrong answer rather than for all of them.
 *
 * V1 IS MySQL, ON PURPOSE
 * -----------------------
 * The step forbids introducing Meilisearch/OpenSearch before profiling proves
 * MySQL insufficient. When that day comes, ecw_search_parse() is reusable as-is
 * — only ecw_search_conditions() is MySQL-shaped.
 */

require_once __DIR__ . '/db.php';

/** InnoDB's floor on this server. Tokens shorter than this need the LIKE path. */
const ECW_FT_MIN_TOKEN = 3;

/** InnoDB's default stopwords — full text silently ignores every one of them. */
const ECW_FT_STOPWORDS = [
    'a','about','an','are','as','at','be','by','com','de','en','for','from','how',
    'i','in','is','it','la','of','on','or','that','the','this','to','was','what',
    'when','where','who','will','with','und','the','www',
];

/**
 * @return array parsed filters; every key always present so callers need no isset()
 */
function ecw_search_parse(string $query): array
{
    $filters = [
        'keywords' => [], 'phrases' => [],
        'from' => [], 'to' => [], 'cc' => [], 'participant' => [], 'subject' => [],
        'case' => [], 'folder' => [], 'filename' => [],
        'after' => null, 'before' => null,
        'has_attachment' => null, 'unread' => null, 'linked' => null, 'flagged' => null,
        'larger' => null, 'smaller' => null, 'direction' => null,
        'raw' => trim($query),
    ];

    // field:"quoted value" | field:value | "bare phrase" | bare-word
    preg_match_all('/(\w+):"([^"]*)"|(\w+):(\S+)|"([^"]*)"|(\S+)/u', $query, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $field = strtolower($m[1] ?? '') ?: strtolower($m[3] ?? '');
        $value = $m[2] ?? '';
        if ($value === '' && ($m[4] ?? '') !== '') {
            $value = $m[4];
        }

        if ($field !== '' && $value !== '') {
            switch ($field) {
                case 'from':        $filters['from'][] = strtolower($value); break;
                case 'to':          $filters['to'][] = strtolower($value); break;
                case 'cc':          $filters['cc'][] = strtolower($value); break;
                case 'participant': $filters['participant'][] = strtolower($value); break;
                case 'filename':
                case 'attachment':  $filters['filename'][] = $value; break;
                // A file type: ext:step, filetype:pdf (= filename:*.step).
                case 'ext':
                case 'filetype':    $filters['filename'][] = '*.' . ltrim($value, '*.'); break;
                case 'subject':     $filters['subject'][] = $value; break;
                case 'case':        $filters['case'][] = $value; break;
                case 'in':
                case 'folder':      $filters['folder'][] = $value; break;
                // Relative dates: newer_than:7d, older_than:2w (d, w, m, y).
                case 'newer_than':  $filters['after'] = ecw_search_relative_date($value) ?? $filters['after']; break;
                case 'older_than':  $filters['before'] = ecw_search_relative_date($value) ?? $filters['before']; break;
                // An attachment at least / at most this big: larger:5mb, smaller:200kb.
                case 'larger':      $filters['larger'] = ecw_search_size($value); break;
                case 'smaller':     $filters['smaller'] = ecw_search_size($value); break;
                case 'after':
                case 'since':       $filters['after'] = ecw_search_date($value, false); break;
                case 'before':
                case 'until':       $filters['before'] = ecw_search_date($value, true); break;
                case 'has':
                    if (str_starts_with(strtolower($value), 'attach')) {
                        $filters['has_attachment'] = true;
                    }
                    break;
                case 'is':
                    switch (strtolower($value)) {
                        case 'unread':   $filters['unread'] = true;  break;
                        case 'read':     $filters['unread'] = false; break;
                        case 'linked':   $filters['linked'] = true;  break;
                        case 'unlinked': $filters['linked'] = false; break;
                        case 'flagged':
                        case 'starred':  $filters['flagged'] = true; break;
                        case 'sent':     $filters['direction'] = 'outgoing'; break;
                        case 'received': $filters['direction'] = 'incoming'; break;
                    }
                    break;
                default:
                    // An unknown prefix is far more likely a typo or a literal
                    // "note:" in a subject than a filter. Treat it as text
                    // rather than silently dropping what the user typed.
                    $filters['keywords'][] = $field . ':' . $value;
            }
            continue;
        }

        if (($m[5] ?? '') !== '') {
            $filters['phrases'][] = $m[5];
            continue;
        }

        $bare = trim($m[6] ?? '');
        // "*.step" or ".step" alone: files of that type, not the text ".step".
        if (preg_match('/^\*?\.([\p{L}\p{N}_-]{1,15})$/u', $bare, $ext)) {
            $filters['filename'][] = '*.' . $ext[1];
            continue;
        }
        if (strpbrk($bare, '*?') !== false && preg_match('/\.[\p{L}\p{N}_-]{1,15}$/u', $bare)) {
            $filters['filename'][] = $bare;   // drawing*.pdf
            continue;
        }
        if ($bare !== '') {
            $filters['keywords'][] = $bare;
        }
    }

    return $filters;
}

/** Accepts 2026-09-01 and 2026-09-01T14:00, as the viewer's day and clock. `before:` means end of that day. */
function ecw_search_date(string $value, bool $endOfDay): ?string
{
    require_once __DIR__ . '/timezone.php';
    $value = trim($value);
    if (strtotime($value . ' UTC') === false) {
        return null;
    }
    if ($endOfDay && !str_contains($value, ':')) {
        $value .= ' 23:59:59';
    }
    return ecw_tz_to_utc(str_replace('T', ' ', $value), ecw_tz_for_user(null));
}

/** newer_than:7d / older_than:2w → the UTC moment that long ago (d, w, m, y). */
function ecw_search_relative_date(string $value): ?string
{
    if (!preg_match('/^(\d{1,4})\s*([dwmy])$/i', trim($value), $m)) return null;
    $unit = ['d' => 'day', 'w' => 'week', 'm' => 'month', 'y' => 'year'][strtolower($m[2])];
    $ts = strtotime('-' . (int)$m[1] . ' ' . $unit);
    return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
}

/** 5mb, 200kb, 1.5m, 300000 → bytes. */
function ecw_search_size(string $value): ?int
{
    if (!preg_match('/^(\d+(?:[.,]\d+)?)\s*(k|kb|m|mb|g|gb|b)?$/i', trim($value), $m)) return null;
    $n = (float)str_replace(',', '.', $m[1]);
    $mult = ['' => 1, 'b' => 1, 'k' => 1024, 'kb' => 1024, 'm' => 1048576, 'mb' => 1048576, 'g' => 1073741824, 'gb' => 1073741824][strtolower($m[2] ?? '')];
    return (int)round($n * $mult);
}

/**
 * A file name to look for, as a LIKE pattern (ESCAPE '='): "*.step" ends in .step,
 * "drawing*" starts with drawing, "?" is one character; without * or ? anywhere in the name.
 */
function ecw_search_filename_pattern(string $value): string
{
    $value = trim($value);
    $escaped = strtr($value, ['=' => '==', '%' => '=%', '_' => '=_']);
    if (strpbrk($value, '*?') === false) return '%' . $escaped . '%';
    return strtr($escaped, ['*' => '%', '?' => '_']);
}

/** Can full text actually serve this token? */
function ecw_ft_indexable(string $token): bool
{
    $clean = trim($token, "*+-\"'()");
    if (mb_strlen($clean) < ECW_FT_MIN_TOKEN) {
        return false;
    }
    if (in_array(mb_strtolower($clean), ECW_FT_STOPWORDS, true)) {
        return false;
    }
    // Anything with punctuation inside gets split by the tokenizer into pieces
    // that no longer mean what the user typed.
    return (bool)preg_match('/^[\p{L}\p{N}_]+$/u', $clean);
}

/** A phrase the index can find as a phrase: two to six words, each one the index holds. */
function ecw_search_phrase_ft(string $phrase): bool
{
    $words = preg_split('/\s+/u', trim($phrase)) ?: [];
    if (count($words) < 2 || count($words) > 6) return false;
    foreach ($words as $w) if (!ecw_search_ft_term($w)) return false;
    return true;
}

/** A term full text can find as typed (no punctuation inside, long enough, not a stopword). */
function ecw_search_ft_term(string $term): bool
{
    return ecw_ft_indexable($term) && !preg_match('/[^\p{L}\p{N}_]/u', $term);
}

/**
 * Which participant rows (alias p) are this person: a full address is one
 * index seek; a whole word ("hexagon", "maria") goes through the people
 * full-text index, which finds it in names and in every part of an address
 * (support@hexagon.com); anything else falls back to LIKE, which reads every
 * participant row.
 *
 * @return array{0: string, 1: array} SQL condition on p, its parameters
 */
function ecw_search_people_where(string $value, ?string $role = null): array
{
    $value = trim($value);
    $roleSql = $role === null ? '' : 'p.role = ? AND ';
    $roleParam = $role === null ? [] : [$role];
    if (str_contains($value, '@') && !str_starts_with($value, '@') && !str_contains($value, ' ')) {
        return ["{$roleSql}p.email = ?", array_merge($roleParam, [$value])];
    }
    if (ecw_search_ft_term($value)) {
        return ["{$roleSql}MATCH(p.name, p.email) AGAINST (? IN BOOLEAN MODE)", array_merge($roleParam, ['+' . $value . '*'])];
    }
    return ["{$roleSql}(p.email LIKE ? OR p.name LIKE ?)", array_merge($roleParam, [ecw_search_address_pattern($value), '%' . $value . '%'])];
}

/** More full-text hits than this make a word "common" (see ecw_search_conditions()). */
const ECW_SEARCH_COMMON_HITS = 5000;

/**
 * The words of a search that appear in so many emails that fetching all their
 * full-text hits would be the slow part. Counting is cheap (the index answers
 * it without reading emails, ~30 ms for 45,000 hits); fetching them is not.
 *
 * @return string[]
 */
function ecw_search_common_terms(array $f): array
{
    static $known = [];
    $common = [];
    foreach (array_merge($f['keywords'], $f['phrases']) as $term) {
        if (!ecw_search_ft_term($term)) continue;
        $key = ecw_table('email_messages') . '|' . mb_strtolower($term);
        if (!isset($known[$key])) {
            $st = ecw_db()->prepare('SELECT COUNT(*) FROM `' . ecw_table('email_messages') . '` WHERE MATCH(subject, body_text) AGAINST (? IN BOOLEAN MODE)');
            $st->execute(['+' . $term . '*']);
            $known[$key] = (int)$st->fetchColumn() > ECW_SEARCH_COMMON_HITS;
        }
        if ($known[$key]) $common[] = $term;
    }
    return $common;
}

/**
 * Turn parsed filters into SQL.
 *
 * Predicates are written against `m` (ecw_email_messages) and `t`
 * (ecw_email_threads), which every caller aliases the same way.
 *
 * @param string[] $likeTerms common words: their email text is checked with LIKE (see below)
 * @return array{where: string[], params: array, uses_fulltext: bool, walk: bool}
 *         walk: no term narrows the search down; read newest-first in batches
 */
function ecw_search_conditions(array $f, array $likeTerms = []): array
{
    $where  = [];
    $params = [];
    $participants = ecw_table('message_participants');

    $people = static function (string $value, ?string $role) use ($participants): array {
        [$sql, $p] = ecw_search_people_where($value, $role);
        return ["m.id IN (SELECT p.email_message_id FROM `{$participants}` p WHERE {$sql})", $p];
    };
    foreach (['from' => 'from', 'to' => 'to', 'cc' => 'cc'] as $key => $role) {
        foreach ($f[$key] as $value) {
            [$sql, $p] = $people((string)$value, $role);
            $where[] = $sql;
            $params = array_merge($params, $p);
        }
    }

    foreach ($f['participant'] as $value) {
        [$sql, $p] = $people((string)$value, null);
        $where[] = $sql;
        $params = array_merge($params, $p);
    }

    foreach ($f['subject'] as $value) {
        $where[]  = 'm.subject LIKE ?';
        $params[] = '%' . $value . '%';
    }

    foreach ($f['filename'] ?? [] as $value) {
        $where[] = "m.id IN (SELECT a.email_message_id FROM `" . ecw_table('email_attachments') . "` a WHERE a.is_inline = 0 AND a.filename LIKE ? ESCAPE '=')";
        $params[] = ecw_search_filename_pattern((string)$value);
    }
    if ($f['larger'] !== null || $f['smaller'] !== null) {
        $size = [];
        if ($f['larger'] !== null)  { $size[] = 'sa.size_bytes >= ?'; $params[] = $f['larger']; }
        if ($f['smaller'] !== null) { $size[] = 'sa.size_bytes <= ?'; $params[] = $f['smaller']; }
        $where[] = "m.id IN (SELECT sa.email_message_id FROM `" . ecw_table('email_attachments') . "` sa WHERE sa.is_inline = 0 AND " . implode(' AND ', $size) . ')';
    }
    if ($f['direction'] !== null) {
        $where[] = 'm.direction = ?';
        $params[] = $f['direction'];
    }

    // About the conversation, not one email: kept apart so the thread list can
    // test them on the conversation without reading its emails.
    $threadWhere = [];
    $threadParams = [];
    foreach ($f['case'] as $value) {
        $threadWhere[] = 'EXISTS (SELECT 1 FROM `' . ecw_table('case_threads') . '` ct
                              JOIN `' . ecw_table('cases') . '` c ON c.id = ct.case_id
                             WHERE ct.email_thread_id = t.id AND c.deleted_at IS NULL
                               AND (c.case_number = ? OR c.name LIKE ?))';
        $threadParams[] = $value;
        $threadParams[] = '%' . $value . '%';
    }

    foreach ($f['folder'] as $value) {
        $where[] = 'm.mail_folder_id IN (SELECT id FROM `' . ecw_table('mail_folders') . '`
                                          WHERE mailbox_id = m.mailbox_id AND (name = ? OR folder_type = ?))';
        $params[] = $value;
        $params[] = strtolower($value);
    }

    if ($f['after'] !== null) {
        $where[]  = 'm.occurred_at >= ?';
        $params[] = $f['after'];
    }
    if ($f['before'] !== null) {
        $where[]  = 'm.occurred_at <= ?';
        $params[] = $f['before'];
    }
    if ($f['has_attachment'] === true) {
        $where[] = 'm.has_attachments = 1';
    }
    if ($f['unread'] === true) {
        $where[] = "m.is_read = 0 AND m.direction = 'incoming'";
    } elseif ($f['unread'] === false) {
        $where[] = 'm.is_read = 1';
    }
    if (($f['flagged'] ?? null) === true) {
        $where[] = 'm.is_flagged = 1';
    }
    if ($f['linked'] === true) {
        $threadWhere[] = 'EXISTS (SELECT 1 FROM `' . ecw_table('case_threads') . '` ct WHERE ct.email_thread_id = t.id)';
    } elseif ($f['linked'] === false) {
        $threadWhere[] = 'NOT EXISTS (SELECT 1 FROM `' . ecw_table('case_threads') . '` ct WHERE ct.email_thread_id = t.id)';
    }

    // Each term is looked for in the email text, the file names, the words
    // inside files (PDF, Word, Excel… read by bin/worker.php) and the people.
    //
    // A word full text can serve, and that is not common: one set of matching
    // email ids, worked out once (a derived table, so MySQL materialises it and
    // drives the search from it). Written as EXISTS (… = m.id) the full-text
    // search re-ran for every conversation: seconds on a 60,000-email mailbox
    // (tools/perf/bench.php).
    //
    // A common word ($likeTerms, from ecw_search_common_terms()): fetching its
    // tens of thousands of hits is itself the slow part, so the email text is
    // checked with LIKE on the emails being looked at, and the caller reads
    // newest-first in batches until a page is full ('walk', ecw_thread_list()).
    $usesFulltext = false;
    $selective = false;
    $terms = array_merge($f['keywords'], $f['phrases']);
    $messages = ecw_table('email_messages');
    $attachments = ecw_table('email_attachments');
    $likeTerms = array_map('mb_strtolower', $likeTerms);
    foreach ($terms as $term) {
        $pattern = ecw_search_literal_pattern($term);
        // A word: its start ("calibr" finds calibration). A phrase whose words the index holds:
        // the index finds the words side by side ("basic fast"), instead of reading every email.
        $phraseFt = !ecw_search_ft_term($term) && in_array($term, $f['phrases'], true) && ecw_search_phrase_ft($term);
        $ft = ecw_search_ft_term($term) || $phraseFt;
        $ftq = $phraseFt ? '+"' . str_replace('"', '', $term) . '"' : '+' . $term . '*';
        // Inside attached files whole words only: their vocabulary is huge and word starts cost 10x.
        $ftFiles = $phraseFt ? $ftq : '+' . $term;
        $byText = $ft && !in_array(mb_strtolower($term), $likeTerms, true);
        $sets = [];
        $setParams = [];
        if ($byText) {
            $sets[] = "SELECT sm.id FROM `{$messages}` sm WHERE MATCH(sm.subject, sm.body_text) AGAINST (? IN BOOLEAN MODE)";
            $setParams[] = $ftq;
        }
        // Real files only (not the pictures inside emails, nine in ten attachments), via idx_ecw_attachments_files.
        $sets[] = "SELECT a.email_message_id AS id FROM `{$attachments}` a WHERE a.is_inline = 0 AND a.filename LIKE ? ESCAPE '='";
        $setParams[] = $pattern;
        if ($ft) {
            $sets[] = "SELECT ax.email_message_id FROM `{$attachments}` ax WHERE MATCH(ax.filename, ax.text_content) AGAINST (? IN BOOLEAN MODE)";
            $sets[] = "SELECT p.email_message_id FROM `{$participants}` p WHERE MATCH(p.name, p.email) AGAINST (? IN BOOLEAN MODE)";
            array_push($setParams, $ftFiles, $ftq);
        } else {
            $sets[] = "SELECT p.email_message_id FROM `{$participants}` p WHERE p.email LIKE ? ESCAPE '=' OR p.name LIKE ? ESCAPE '='";
            array_push($setParams, $pattern, $pattern);
        }
        $inSets = 'm.id IN (SELECT u.id FROM (' . implode(' UNION ', $sets) . ') u)';
        if ($byText) {
            $where[] = $inSets;
            $params = array_merge($params, $setParams);
            $usesFulltext = $selective = true;
        } else {
            $where[] = "(m.subject LIKE ? ESCAPE '=' OR m.body_text LIKE ? ESCAPE '=' OR {$inSets})";
            $params = array_merge($params, [$pattern, $pattern], $setParams);
        }
    }
    // 'where'/'params': everything, for callers that join m and t (lib/ai.php).
    // 'message_*' / 'thread_*': the same, split by what they are about.
    // Placeholders: message conditions come before thread ones in 'params'.
    return ['where' => array_merge($where, $threadWhere), 'params' => array_merge($params, $threadParams),
            'message_where' => $where, 'message_params' => $params, 'thread_where' => $threadWhere, 'thread_params' => $threadParams,
            'uses_fulltext' => $usesFulltext, 'walk' => $terms !== [] && !$selective];
}

/**
 * A full address is matched exactly (index seek); a fragment is matched as a
 * substring so that `from:hexagon` finds support@hexagon.com.
 */
function ecw_search_address_pattern(string $value): string
{
    $value = trim($value);
    if (str_contains($value, '@') && !str_starts_with($value, '@')) {
        return $value;
    }
    return '%' . $value . '%';
}

/** True when the query asks for nothing at all. */
function ecw_search_is_empty(array $f): bool
{
    foreach (['keywords', 'phrases', 'from', 'to', 'cc', 'participant', 'subject', 'case', 'folder', 'filename'] as $k) {
        if (($f[$k] ?? []) !== []) {
            return false;
        }
    }
    return $f['after'] === null && $f['before'] === null
        && $f['has_attachment'] === null && $f['unread'] === null && $f['linked'] === null && ($f['flagged'] ?? null) === null
        && ($f['larger'] ?? null) === null && ($f['smaller'] ?? null) === null && ($f['direction'] ?? null) === null;
}

/** Escape SQL wildcard characters so literal filenames stay literal. */
function ecw_search_literal_pattern(string $value): string
{
    return '%' . strtr($value, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
}

/**
 * Inside one conversation opened from a search: which emails match, and which
 * attachments hold the words (by name or inside the file). The reading pane
 * opens those emails and marks those files, so a search lands on the evidence
 * instead of the top of the conversation. Text is checked with LIKE: one
 * conversation is small, and the full-text index cannot be limited to it.
 *
 * @return array{message_ids: int[], attachments: array<int,string>} attachment id => the word found
 */
function ecw_search_in_thread(int $threadId, string $query): array
{
    $out = ['message_ids' => [], 'attachments' => []];
    $f = ecw_search_parse($query);
    if (ecw_search_is_empty($f)) return $out;
    $terms = array_values(array_filter(array_merge($f['keywords'], $f['phrases']), static fn($w) => mb_strlen(trim((string)$w)) >= 2));
    $c = ecw_search_conditions($f, $terms);
    if ($c['message_where'] !== []) {
        $st = ecw_db()->prepare('SELECT m.id FROM `' . ecw_table('email_messages') . '` m JOIN `' . ecw_table('email_threads') . '` t ON t.id = m.email_thread_id
                                  WHERE m.email_thread_id = ? AND m.provider_removed_at IS NULL AND ' . implode(' AND ', $c['message_where']) . ' ORDER BY m.occurred_at, m.id');
        $st->execute(array_merge([$threadId], $c['message_params']));
        $out['message_ids'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    $fileMessages = [];
    $words = array_merge($terms, array_map('strval', $f['filename'] ?? []));
    if ($words) {
        $st = ecw_db()->prepare('SELECT a.id, a.email_message_id, a.filename, a.text_content FROM `' . ecw_table('email_attachments') . '` a
                                   JOIN `' . ecw_table('email_messages') . '` m ON m.id = a.email_message_id
                                  WHERE m.email_thread_id = ? AND m.provider_removed_at IS NULL AND a.is_inline = 0');
        $st->execute([$threadId]);
        foreach ($st->fetchAll() as $a) {
            foreach ($words as $w) {
                if (mb_stripos((string)$a['filename'], $w) !== false || mb_stripos((string)$a['text_content'], $w) !== false) {
                    $out['attachments'][(int)$a['id']] = $w;
                    $fileMessages[] = (int)$a['email_message_id'];
                    break;
                }
            }
        }
    }
    // An email whose file holds the words matches too, in the conversation's order.
    if ($fileMessages && array_diff($fileMessages, $out['message_ids'])) {
        $st = ecw_db()->prepare('SELECT id FROM `' . ecw_table('email_messages') . '` WHERE email_thread_id = ? AND provider_removed_at IS NULL ORDER BY occurred_at, id');
        $st->execute([$threadId]);
        $want = array_flip(array_merge($out['message_ids'], $fileMessages));
        $out['message_ids'] = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), static fn($id) => isset($want[$id])));
    }
    return $out;
}