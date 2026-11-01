<?php declare(strict_types=1);
/**
 * Getting your data out: a portable copy that opens without this app.
 *
 *   - one case (case page → Export): a .zip with every email as a .eml file
 *     (opens in Outlook, Thunderbird, Apple Mail), the attachments, a readable
 *     case.html, and CSV / JSON of the notes, checklist, follow-ups, next step,
 *     references and history;
 *   - the whole workspace (Settings → Backups → Export everything, admins):
 *     every case like that, plus the people, groups, contacts, templates and
 *     the case list as a spreadsheet. Built in the background by the worker
 *     (ecw_export_due) and kept for ECW_EXPORT_KEEP_DAYS.
 *
 * A backup is for this app to restore; an export is for people and other
 * programs to read. Mail that is in no case stays in Outlook / Gmail, where it
 * already is, and is not repeated here.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/license.php';   // ecw_app_setting

const ECW_EXPORT_KEEP_DAYS = 7;
const ECW_EXPORT_DIR = __DIR__ . '/../storage/exports';

/** A name that is safe as one part of a path in a zip, on every system. */
function ecw_export_name(string $s, int $max = 60): string
{
    $s = trim((string)preg_replace('/[\x00-\x1F\x7F\\\\\/:*?"<>|]+/u', '_', $s), ' ._');
    return mb_substr($s, 0, $max) ?: 'untitled';
}

/** Rows as CSV text (UTF-8 with a BOM, so Excel shows Greek right). */
function ecw_export_csv(array $rows, ?array $header = null): string
{
    $h = fopen('php://temp', 'w+');
    fwrite($h, "\xEF\xBB\xBF");
    $header ??= $rows ? array_keys($rows[0]) : [];
    if ($header) fputcsv($h, $header, ',', '"', '');
    foreach ($rows as $r) fputcsv($h, array_map(static fn($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v, array_values($r)), ',', '"', '');
    rewind($h);
    $out = (string)stream_get_contents($h);
    fclose($h);
    return $out;
}

function ecw_export_json(mixed $data): string
{
    return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** One stored email as a standard .eml (RFC 5322 / MIME), with its attachments inside when $files is given. */
function ecw_export_eml(array $m, array $files = []): string
{
    $enc = static fn(string $s): string => preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    $addr = static function ($list) use ($enc): string {
        $out = [];
        foreach ((array)$list as $a) {
            $email = (string)($a['email'] ?? $a['address'] ?? '');
            if ($email === '') continue;
            $name = trim((string)($a['name'] ?? ''));
            $out[] = $name !== '' && $name !== $email ? $enc($name) . ' <' . $email . '>' : $email;
        }
        return implode(', ', $out);
    };
    $date = (string)($m['sent_at'] ?? $m['received_at'] ?? $m['occurred_at'] ?? '');
    $head = [
        'From: ' . $addr([['email' => $m['from_email'] ?? '', 'name' => $m['from_name'] ?? '']]),
        'To: ' . $addr(json_decode((string)($m['to_json'] ?? '[]'), true) ?: []),
    ];
    $cc = $addr(json_decode((string)($m['cc_json'] ?? '[]'), true) ?: []);
    if ($cc !== '') $head[] = 'Cc: ' . $cc;
    $head[] = 'Subject: ' . $enc((string)($m['subject'] ?? ''));
    if ($date !== '') $head[] = 'Date: ' . gmdate('D, d M Y H:i:s', (int)strtotime($date . ' UTC')) . ' +0000';
    if (!empty($m['internet_message_id'])) $head[] = 'Message-ID: ' . $m['internet_message_id'];
    if (!empty($m['in_reply_to_message_id'])) $head[] = 'In-Reply-To: ' . $m['in_reply_to_message_id'];
    $head[] = 'MIME-Version: 1.0';
    $html = trim((string)($m['body_html'] ?? '') . (string)($m['quoted_html'] ?? ''));
    $text = trim((string)($m['body_text'] ?? '') . ((string)($m['quoted_text'] ?? '') !== '' ? "\n\n" . $m['quoted_text'] : ''));
    if ($text === '' && $html !== '') $text = trim(html_entity_decode(strip_tags((string)preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
    $b1 = 'alt_' . bin2hex(random_bytes(8));
    $alt = "Content-Type: multipart/alternative; boundary=\"{$b1}\"\r\n\r\n"
        . "--{$b1}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
        . ($html !== '' ? "--{$b1}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n" : '')
        . "--{$b1}--\r\n";
    if (!$files) return implode("\r\n", $head) . "\r\n" . $alt;
    $b2 = 'mix_' . bin2hex(random_bytes(8));
    $body = "--{$b2}\r\n" . $alt;
    foreach ($files as [$name, $mime, $bytes]) {
        $body .= "--{$b2}\r\nContent-Type: " . ($mime ?: 'application/octet-stream') . "; name=\"" . $enc($name) . "\"\r\nContent-Disposition: attachment; filename=\"" . $enc($name) . "\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($bytes)) . "\r\n";
    }
    $body .= "--{$b2}--\r\n";
    return implode("\r\n", $head) . "\r\nContent-Type: multipart/mixed; boundary=\"{$b2}\"\r\n\r\n" . $body;
}

/**
 * The look of the exported pages: full width, plain, readable, prints well.
 * Inline in every page so each one opens on its own, from a zip, offline.
 */
const ECW_EXPORT_CSS = '
*{box-sizing:border-box}
body{margin:0;background:#f4f6f9;color:#16202e;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
.wrap{max-width:1500px;margin:0 auto;padding:24px clamp(16px,3vw,40px) 60px}
a{color:#1f5bd6} h1{font-size:26px;margin:4px 0 6px;line-height:1.2} h2{font-size:18px;margin:34px 0 12px}
.top{display:flex;flex-wrap:wrap;align-items:center;gap:8px 14px} .muted{color:#667085} .small{font-size:13px}
.badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12.5px;font-weight:600;background:#e8edf5;color:#344054}
.b-open{background:#e0ecff;color:#1d4ed8}.b-waiting{background:#fef3c7;color:#92400e}.b-closed{background:#e5e7eb;color:#374151}
.b-customer{background:#fff1e0;color:#b45309}.b-supplier{background:#f1e8ff;color:#6d28d9}.b-internal{background:#e0f7fa;color:#0e7490}.b-ours{background:#e0ecff;color:#1d4ed8}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px}
.fact{background:#fff;border:1px solid #e3e8ef;border-radius:10px;padding:10px 14px}.fact b{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#667085;font-weight:600}
.next{background:#fff;border:1px solid #e3e8ef;border-left:5px solid #1f5bd6;border-radius:10px;padding:14px 18px;margin-top:14px}
.next .what{font-size:18px;font-weight:700}.chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.chip{border:1px solid #d5dbe5;border-radius:999px;padding:2px 10px;font-size:13px;background:#fff}.chip code{font-weight:700}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px}
.card{display:block;background:#fff;border:1px solid #e3e8ef;border-radius:12px;padding:14px 16px;text-decoration:none;color:inherit}
.card:hover{border-color:#1f5bd6;box-shadow:0 4px 14px rgba(31,91,214,.12)}.card .t{font-weight:700;font-size:16px;margin:6px 0 4px;color:#16202e}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e3e8ef;border-radius:10px;overflow:hidden;font-size:14px}
th,td{text-align:left;padding:8px 12px;border-bottom:1px solid #edf0f5;vertical-align:top}th{background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#667085}
tr.company td{background:#f8fafc;font-weight:700}.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.tablebox{overflow-x:auto}
.t-emails td:nth-child(1),.t-emails td:nth-child(3){white-space:nowrap}.t-emails td:nth-child(2){min-width:200px}.t-emails td:nth-child(4){max-width:260px}.t-emails td:nth-child(6){min-width:240px}
.mail{background:#fff;border:1px solid #e3e8ef;border-radius:14px;padding:14px 18px;margin:14px 0;max-width:min(1100px,88%)}
.mail.ours{margin-left:auto;background:#f2f7ff;border-color:#cfdcf5}
.mail .head{display:flex;flex-wrap:wrap;justify-content:space-between;gap:4px 14px;margin-bottom:6px}.mail .who{font-weight:700}
.mail .to{font-size:13px;color:#667085;margin-bottom:8px}.mail .subj{font-weight:600;margin-bottom:6px}
.body{white-space:pre-wrap;overflow-wrap:anywhere}
details.quoted{margin-top:10px;font-size:13.5px}details.quoted summary{cursor:pointer;color:#667085}details.quoted .body{color:#475467;border-left:3px solid #e3e8ef;padding-left:12px;margin-top:6px}
.files{display:flex;flex-wrap:wrap;align-items:flex-start;gap:8px;margin-top:12px}
.file{display:inline-flex;align-items:center;gap:6px;border:1px solid #d5dbe5;border-radius:8px;padding:5px 10px;background:#fff;text-decoration:none;font-size:13.5px}
.file img{max-height:120px;max-width:200px;border-radius:6px;display:block}.file.pic{flex-direction:column;align-items:flex-start}
.file.missing{color:#98a2b3;border-style:dashed}
.nav{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;margin-bottom:10px}
ul.plain{list-style:none;padding:0;margin:0}ul.plain li{background:#fff;border:1px solid #e3e8ef;border-radius:8px;padding:8px 12px;margin:6px 0}
@media print{body{background:#fff}.mail{break-inside:avoid;max-width:100%}.card:hover{box-shadow:none}}
@media (max-width:700px){.mail{max-width:100%}}
';

/** A whole exported page. */
function ecw_export_page(string $title, string $body): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>' . ECW_EXPORT_CSS . '</style></head><body><div class="wrap">' . $body . '</div></body></html>';
}

/** A link to a file inside the export (spaces, Greek…): each part URL-encoded. */
function ecw_export_href(string $path): string
{
    return implode('/', array_map(static fn($p) => $p === '..' ? '..' : rawurlencode($p), explode('/', $path)));
}

/** Readable text of an email: its own text, and the quoted earlier emails apart. */
function ecw_export_text(array $m): array
{
    $plain = static fn(string $html): string => trim(html_entity_decode(strip_tags((string)preg_replace(['/<(script|style)\b.*?<\/\1>/is', '/<(br|\/p|\/div|\/tr|\/li|\/h\d)[^>]*>/i'], ['', "\n"], $html)), ENT_QUOTES, 'UTF-8'));
    $own = trim((string)($m['body_text'] ?? ''));
    if ($own === '') $own = $plain((string)($m['body_html'] ?? ''));
    $quoted = trim((string)($m['quoted_text'] ?? ''));
    if ($quoted === '' && !empty($m['quoted_html'])) $quoted = $plain((string)$m['quoted_html']);
    return [(string)preg_replace("/\n{3,}/", "\n\n", $own), (string)preg_replace("/\n{3,}/", "\n\n", $quoted)];
}

/**
 * Write one case into $zip under $prefix ("" or "cases/CASE-00012 Name/"), as
 * a small website: index.html (the overview: next step, conversations, people,
 * timeline, checklist, notes, history), one folder per conversation with its
 * page, its attachments as plain files and its emails as .eml, and data/.
 * Returns ['emails' => n, 'files' => n, 'missing' => [files that could not be fetched]].
 */
function ecw_export_case_into(ZipArchive $zip, string $prefix, int $orgId, int $caseId, bool $withFiles = true, ?string $tz = null): array
{
    // Times in the zone of the person exporting (lib/timezone.php); the pages say which.
    require_once __DIR__ . '/timezone.php';
    $tz ??= ecw_tz_for_user(null, $orgId);
    require_once __DIR__ . '/next_actions.php';
    require_once __DIR__ . '/case_extras.php';
    require_once __DIR__ . '/attachment_text.php';
    $pdo = ecw_db();
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $q = static function (string $sql, array $p) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); };
    $case = $q('SELECT c.*, o.name AS owner_name, hu.name AS handler_name, g.name AS group_name FROM `' . ecw_table('cases') . '` c
                 LEFT JOIN `' . ecw_table('users') . '` o ON o.id = c.owner_user_id LEFT JOIN `' . ecw_table('users') . '` hu ON hu.id = c.handler_user_id
                 LEFT JOIN `' . ecw_table('case_groups') . '` g ON g.id = c.group_id WHERE c.id = ? AND c.organization_id = ?', [$caseId, $orgId])[0] ?? null;
    if (!$case) throw new InvalidArgumentException('Case not found.');
    $out = ['emails' => 0, 'files' => 0, 'missing' => []];
    $roleLabel = ['customer' => 'Customer', 'supplier' => 'Supplier', 'internal' => 'Internal'];
    // Stored UTC ('Y-m-d H:i:s'), or ISO with its own offset (chat messages).
    $utc = static function ($ts): ?string { if (!$ts) return null; try { return (new DateTimeImmutable((string)$ts, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); } catch (Throwable $e) { return null; } };
    $when = static fn($ts): string => ecw_tz_format($utc($ts), $tz, 'j M Y, H:i');
    $day = static fn($ts): string => ecw_tz_format($utc($ts), $tz, 'j M Y');

    // Who is "us": the workspace's mailboxes and people.
    $ours = [];
    foreach ($q('SELECT email_address AS e FROM `' . ecw_table('mailboxes') . '` WHERE organization_id = ?
                 UNION SELECT u.email FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id WHERE ou.organization_id = ?', [$orgId, $orgId]) as $r) {
        $ours[mb_strtolower((string)$r['e'])] = true;
    }
    $companyNames = [];
    try { foreach ($q('SELECT domain, name FROM `' . ecw_table('companies') . '` WHERE organization_id = ?', [$orgId]) as $r) if ($r['name']) $companyNames[mb_strtolower($r['domain'])] = $r['name']; } catch (Throwable $e) {}

    $threads = $q('SELECT ct.email_thread_id, ct.display_name, ct.party_role, t.normalized_subject, mb.email_address FROM `' . ecw_table('case_threads') . '` ct
                    JOIN `' . ecw_table('email_threads') . '` t ON t.id = ct.email_thread_id JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = t.mailbox_id
                   WHERE ct.case_id = ? ORDER BY ct.sort_order, ct.id', [$caseId]);
    $people = [];       // email => [name, sent, received, first, last, roles[]]
    $seen = static function (array &$people, string $email, string $name, string $at, string $how, ?string $role): void {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) return;
        $p = $people[$email] ?? ['name' => '', 'sent' => 0, 'received' => 0, 'first' => $at, 'last' => $at, 'roles' => []];
        if ($p['name'] === '' && trim($name) !== '' && mb_strtolower(trim($name)) !== $email) $p['name'] = trim($name);
        $p[$how]++;
        if ($at !== '' && ($p['first'] === '' || $at < $p['first'])) $p['first'] = $at;
        if ($at > $p['last']) $p['last'] = $at;
        if ($role) $p['roles'][$role] = ($p['roles'][$role] ?? 0) + 1;
        $people[$email] = $p;
    };
    $convCards = [];
    $timeline = [];
    $emailRows = [];
    foreach ($threads as $i => $t) {
        $label = (string)($t['display_name'] ?: $t['normalized_subject'] ?: '(no subject)');
        $role = $t['party_role'];
        // Short names: Windows cannot unzip paths over 260 characters, and Downloads\…\ is already part of it.
        $folder = sprintf('conversations/%d %s%s/', $i + 1, $role ? $roleLabel[$role] . ' - ' : '', ecw_export_name($label, 32));
        $msgs = $q('SELECT m.* FROM `' . ecw_table('email_messages') . '` m WHERE m.email_thread_id = ? AND m.provider_removed_at IS NULL ORDER BY m.occurred_at, m.id', [(int)$t['email_thread_id']]);
        $used = [];
        $articles = '';
        $fileCount = 0;
        $who = [];
        foreach ($msgs as $n => $m) {
            $num = $n + 1;
            $at = (string)$m['occurred_at'];
            $to = json_decode((string)$m['to_json'], true) ?: [];
            $cc = json_decode((string)$m['cc_json'], true) ?: [];
            $seen($people, (string)$m['from_email'], (string)$m['from_name'], $at, 'sent', $role);
            foreach (array_merge($to, $cc) as $a) $seen($people, (string)($a['email'] ?? $a['address'] ?? ''), (string)($a['name'] ?? ''), $at, 'received', $role);
            $who[mb_strtolower((string)$m['from_email'])] = (string)($m['from_name'] ?: $m['from_email']);
            $mine = $m['direction'] === 'outgoing' || isset($ours[mb_strtolower((string)$m['from_email'])]);

            // Its files: as plain files in files/, and inside the .eml.
            $files = [];
            $chips = '';
            $fileList = [];   // for the overview: [name, path in the export or null, size text]
            $atts = $q('SELECT a.*, mm.provider_message_id, mm.mailbox_id FROM `' . ecw_table('email_attachments') . '` a JOIN `' . ecw_table('email_messages') . '` mm ON mm.id = a.email_message_id
                         WHERE a.email_message_id = ? AND a.is_inline = 0 ORDER BY a.id', [(int)$m['id']]);
            foreach ($atts as $att) {
                $name = ecw_export_name((string)$att['filename'], 70) ?: 'file';
                $base = pathinfo($name, PATHINFO_FILENAME); $ext = pathinfo($name, PATHINFO_EXTENSION);
                for ($k = 2; isset($used[mb_strtolower($name)]); $k++) $name = $base . ' (' . $k . ')' . ($ext !== '' ? '.' . $ext : '');
                $used[mb_strtolower($name)] = true;
                $size = (int)$att['size_bytes'];
                $sizeText = $size >= 1048576 ? round($size / 1048576, 1) . ' MB' : max(1, (int)round($size / 1024)) . ' KB';
                $bytes = $withFiles ? ecw_attachment_bytes($att, false) : null;
                if ($bytes === null) {
                    if ($withFiles) $out['missing'][] = $name . ' (' . $label . ')';
                    $fileList[] = [$name, null, $sizeText];
                    $chips .= '<span class="file missing" title="' . ($withFiles ? 'Could not be fetched from the mailbox' : 'Exported without files') . '">📎 ' . $h($name) . ' · ' . $sizeText . '</span>';
                    continue;
                }
                $zip->addFromString($prefix . $folder . 'files/' . $name, $bytes);
                $fileList[] = [$name, $folder . 'files/' . $name, $sizeText];
                $files[] = [$name, (string)$att['mime_type'], $bytes];
                $out['files']++;
                $fileCount++;
                $isPic = preg_match('#^image/(png|jpe?g|gif|webp)$#i', (string)$att['mime_type']) === 1;
                $chips .= '<a class="file' . ($isPic ? ' pic' : '') . '" href="' . $h(ecw_export_href('files/' . $name)) . '">'
                    . ($isPic ? '<img src="' . $h(ecw_export_href('files/' . $name)) . '" alt="" loading="lazy">' : '') . '📎 ' . $h($name) . ' <span class="muted">' . $sizeText . '</span></a>';
            }
            $eml = sprintf('emails/%03d %s %s.eml', $num, substr($at, 0, 10), ecw_export_name(ecw_export_normalize_subject((string)$m['subject']) !== '' ? (string)preg_replace('/^\s*((re|fw|fwd|aw|wg|σχετ|πρθ)\s*:\s*)+/iu', '', (string)$m['subject']) : 'email', 28));
            $zip->addFromString($prefix . $folder . $eml, ecw_export_eml($m, $files));
            $out['emails']++;

            [$own, $quoted] = ecw_export_text($m);
            $addr = static fn(array $list): string => implode(', ', array_map(static function ($a): string {
                $email = (string)($a['email'] ?? $a['address'] ?? '');
                $name = trim((string)($a['name'] ?? ''));
                return $name !== '' && mb_strtolower($name) !== mb_strtolower($email) ? $name . ' <' . $email . '>' : $email;   // never "x@y <x@y>"
            }, $list));
            $articles .= '<article class="mail ' . ($mine ? 'ours' : 'theirs') . '" id="email-' . $num . '">'
                . '<div class="head"><span class="who">' . $h($m['from_name'] ?: $m['from_email']) . ' <span class="muted small">&lt;' . $h($m['from_email']) . '&gt;</span></span>'
                . '<span class="muted small">#' . $num . ' · ' . $h($when($at)) . '</span></div>'
                . '<div class="to">To: ' . $h($addr($to)) . ($cc ? '<br>Cc: ' . $h($addr($cc)) : '') . '</div>'
                . (trim((string)$m['subject']) !== '' && ecw_export_normalize_subject((string)$m['subject']) !== ecw_export_normalize_subject($label) ? '<div class="subj">' . $h($m['subject']) . '</div>' : '')
                . '<div class="body">' . ($own !== '' ? $h($own) : '<span class="muted">(no text)</span>') . '</div>'
                . ($quoted !== '' ? '<details class="quoted"><summary>Earlier emails quoted in this one (' . (substr_count($quoted, "\n") + 1) . ' lines)</summary><div class="body">' . $h($quoted) . '</div></details>' : '')
                . ($chips !== '' ? '<div class="files">' . $chips . '</div>' : '')
                . '<div class="small" style="margin-top:8px"><a href="' . $h(ecw_export_href($eml)) . '">Original email (.eml, opens in Outlook)</a></div>'
                . '</article>';
            $timeline[] = ['at' => $at, 'num' => $num, 'folder' => $folder, 'conv' => $label, 'role' => $role, 'mine' => $mine,
                'from' => (string)($m['from_name'] ?: $m['from_email']), 'to' => $addr($to), 'subject' => (string)$m['subject'], 'files' => $fileList];
            $emailRows[] = ['date' => $at, 'conversation' => $label, 'whose' => $role ? $roleLabel[$role] : '', 'direction' => $mine ? 'we sent' : 'we received',
                'from' => trim($m['from_name'] . ' <' . $m['from_email'] . '>'), 'to' => $addr($to), 'cc' => $addr($cc), 'subject' => $m['subject'],
                'attachments' => implode(' | ', array_map(static fn($a) => (string)$a['filename'], $atts)), 'file' => $folder . $eml];
        }
        $first = $msgs ? $day($msgs[0]['occurred_at']) : '';
        $last = $msgs ? $day($msgs[count($msgs) - 1]['occurred_at']) : '';
        $whoText = implode(', ', array_slice(array_values($who), 0, 6)) . (count($who) > 6 ? ' and ' . (count($who) - 6) . ' more' : '');
        $page = '<div class="nav"><a href="../../index.html">← Back to the case overview</a><span class="muted small">' . $h($case['case_number'] . ' · ' . $case['name']) . '</span></div>'
            . '<div class="top">' . ($role ? '<span class="badge b-' . $h($role) . '">' . $h($roleLabel[$role]) . '</span>' : '') . '<h1>' . $h($label) . '</h1></div>'
            . '<p class="muted">' . count($msgs) . ' email' . (count($msgs) === 1 ? '' : 's') . ($first ? ' · ' . $h($first) . ($last !== $first ? ' – ' . $h($last) : '') : '')
            . ' · mailbox ' . $h($t['email_address']) . ($fileCount ? ' · ' . $fileCount . ' file' . ($fileCount === 1 ? '' : 's') . ' in <a href="files/">files/</a>' : '') . '<br>Writing: ' . $h($whoText) . '</p>'
            . '<p class="small muted">Our emails are on the right, theirs on the left. Text quoted from earlier emails is folded away under each one.</p>'
            . $articles;
        $zip->addFromString($prefix . $folder . 'conversation.html', ecw_export_page($label . ' — ' . $case['case_number'], $page));
        $convCards[] = '<a class="card" href="' . $h(ecw_export_href($folder . 'conversation.html')) . '">'
            . ($role ? '<span class="badge b-' . $h($role) . '">' . $h($roleLabel[$role]) . '</span>' : '<span class="badge">Conversation</span>')
            . '<div class="t">' . $h($label) . '</div><div class="muted small">' . count($msgs) . ' email' . (count($msgs) === 1 ? '' : 's')
            . ($first ? ' · ' . $h($first) . ($last !== $first ? ' – ' . $h($last) : '') : '') . ($fileCount ? ' · ' . $fileCount . ' file' . ($fileCount === 1 ? '' : 's') : '') . '</div>'
            . '<div class="small" style="margin-top:6px">' . $h($whoText) . '</div></a>';
    }
    usort($timeline, static fn($a, $b) => [$a['at'], $a['num']] <=> [$b['at'], $b['num']]);

    // ---------------------------------------------------------------- the overview
    $next = ecw_case_next_get($caseId);
    $tags = array_column($q('SELECT tag FROM `' . ecw_table('case_tags') . '` WHERE case_id = ? ORDER BY tag', [$caseId]), 'tag');
    $notes = $q('SELECT n.created_at, u.name AS by_name, n.body, n.message_id, n.resolved_at FROM `' . ecw_table('case_notes') . '` n LEFT JOIN `' . ecw_table('users') . '` u ON u.id = n.user_id
                  WHERE n.deleted_at IS NULL AND (n.case_id = ? OR n.thread_id IN (SELECT email_thread_id FROM `' . ecw_table('case_threads') . '` WHERE case_id = ?)) ORDER BY n.created_at', [$caseId, $caseId]);
    $notes = array_map(static fn($r) => ['date' => $r['created_at'], 'by' => $r['by_name'], 'note' => trim(html_entity_decode(strip_tags((string)$r['body']), ENT_QUOTES, 'UTF-8')), 'resolved' => $r['resolved_at']], $notes);
    $checklist = $q('SELECT cl.text, a.name AS assignee, cl.due_date, cl.done_at, d.name AS done_by FROM `' . ecw_table('case_checklist') . '` cl
                      LEFT JOIN `' . ecw_table('users') . '` a ON a.id = cl.assignee_user_id LEFT JOIN `' . ecw_table('users') . '` d ON d.id = cl.done_by_user_id WHERE cl.case_id = ? ORDER BY cl.position, cl.id', [$caseId]);
    $followups = $q('SELECT f.due_at, u.name AS for_name, f.note, f.if_no_reply, f.done_at, f.outcome FROM `' . ecw_table('followups') . '` f LEFT JOIN `' . ecw_table('users') . '` u ON u.id = f.user_id WHERE f.case_id = ? ORDER BY f.due_at', [$caseId]);
    $history = array_map(static fn($r) => ['date' => $r['at'], 'who' => $r['who'], 'what' => $r['text']], array_reverse(ecw_case_history($caseId, 2000)));
    $waiting = ['us' => 'Us', 'customer' => 'the customer', 'supplier' => 'the supplier', 'internal' => 'a colleague'];

    $b = '<div class="top"><span class="badge">' . $h($case['case_number']) . '</span><span class="badge b-' . $h($case['status']) . '">' . $h(ucfirst((string)$case['status'])) . '</span>'
        . ($case['legal_hold_at'] ? '<span class="badge">Legal hold</span>' : '') . '</div><h1>' . $h($case['name']) . '</h1>'
        . '<p class="muted small">Exported from Email Case Workspace on ' . $h(ecw_tz_format(gmdate('Y-m-d H:i:s'), $tz)) . '. Times are ' . $h($tz) . ' time. Everything opens without the app: the conversations below, their files and the original emails.</p>';
    $facts = ['Responsible' => $case['owner_name'], 'Handled by' => $case['handler_name'], 'Group' => $case['group_name'], 'Created' => $day($case['created_at']),
        'Closed' => $day($case['closed_at']), 'Due' => $case['due_at'], 'Tags' => implode(', ', $tags), 'Emails' => count($timeline), 'Conversations' => count($threads)];
    $b .= '<div class="grid">';
    foreach ($facts as $k => $v) if ((string)$v !== '') $b .= '<div class="fact"><b>' . $h($k) . '</b>' . $h($v) . '</div>';
    $b .= '</div>';
    if ($next['next_action'] || $next['waiting_on'] || $next['refs']) {
        $b .= '<div class="next"><b class="muted small">NEXT STEP</b><div class="what">' . $h($next['next_action'] ?: '—') . '</div><div class="muted">'
            . implode(' · ', array_filter([$next['next_user_name'] ? 'by ' . $next['next_user_name'] : null, $next['waiting_on'] ? 'waiting on ' . ($waiting[$next['waiting_on']] ?? $next['waiting_on']) : null,
                $next['review_on'] ? 'look again on ' . $next['review_on'] : null])) . '</div>';
        if ($next['refs']) {
            $b .= '<div class="chips">';
            foreach ($next['refs'] as $r) $b .= '<span class="chip">' . $h($r['label']) . ' <code>' . $h($r['value']) . '</code></span>';
            $b .= '</div>';
        }
        $b .= '</div>';
    }
    $b .= '<h2>Conversations</h2>' . ($convCards ? '<div class="cards">' . implode('', $convCards) . '</div>' : '<p class="muted">No emails in this case.</p>');

    // People, by company.
    if ($people) {
        $byCompany = [];
        foreach ($people as $email => $p) {
            $domain = substr((string)strrchr($email, '@'), 1);
            $side = isset($ours[$email]) ? 'ours' : ($p['roles'] ? array_search(max($p['roles']), $p['roles'], true) : '');
            $key = isset($ours[$email]) ? '~ours' : $domain;
            $byCompany[$key][] = ['email' => $email, 'side' => $side] + $p;
        }
        uksort($byCompany, static fn($a, $b) => [$a === '~ours', $a] <=> [$b === '~ours', $b]);
        $b .= '<h2>People we were in contact with</h2><div class="tablebox"><table><tr><th>Person</th><th>Email</th><th>Side</th><th class="num">Sent</th><th class="num">Received</th><th>First</th><th>Last</th></tr>';
        foreach ($byCompany as $key => $list) {
            usort($list, static fn($x, $y) => ($y['sent'] + $y['received']) <=> ($x['sent'] + $x['received']));
            $b .= '<tr class="company"><td colspan="7">' . ($key === '~ours' ? 'Our team' : $h(($companyNames[$key] ?? '') !== '' ? $companyNames[$key] . ' (' . $key . ')' : $key)) . '</td></tr>';
            foreach ($list as $p) {
                $sideText = $p['side'] === 'ours' ? 'Our team' : ($roleLabel[$p['side']] ?? '');
                $b .= '<tr><td>' . $h($p['name'] ?: '—') . '</td><td>' . $h($p['email']) . '</td><td>' . ($sideText !== '' ? '<span class="badge b-' . $h($p['side']) . '">' . $h($sideText) . '</span>' : '') . '</td>'
                    . '<td class="num">' . (int)$p['sent'] . '</td><td class="num">' . (int)$p['received'] . '</td><td>' . $h($day($p['first'])) . '</td><td>' . $h($day($p['last'])) . '</td></tr>';
            }
        }
        $b .= '</table></div>';
    }

    if ($timeline) {
        $fileLink = static fn(array $fl): string => $fl[1] !== null
            ? '<a class="file" href="' . $h(ecw_export_href($fl[1])) . '">📎 ' . $h($fl[0]) . ' <span class="muted">' . $h($fl[2]) . '</span></a>'
            : '<span class="file missing">📎 ' . $h($fl[0]) . ' <span class="muted">' . $h($fl[2]) . '</span></span>';
        $b .= '<h2>All emails in order</h2><div class="tablebox"><table class="t-emails"><tr><th>When</th><th>Conversation</th><th>From</th><th>To</th><th>Subject</th><th>Files</th></tr>';
        foreach ($timeline as $e) {
            $b .= '<tr><td style="white-space:nowrap">' . $h($when($e['at'])) . '</td><td>' . ($e['role'] ? '<span class="badge b-' . $h($e['role']) . '">' . $h($roleLabel[$e['role']]) . '</span> ' : '') . $h($e['conv']) . '</td>'
                . '<td>' . ($e['mine'] ? '<span class="badge b-ours">Us</span> ' : '') . $h($e['from']) . '</td><td class="small">' . $h($e['to']) . '</td>'
                . '<td><a href="' . $h(ecw_export_href($e['folder'] . 'conversation.html')) . '#email-' . $e['num'] . '">' . $h($e['subject'] ?: '(no subject)') . '</a></td>'
                . '<td>' . ($e['files'] ? '<div class="files" style="margin:0">' . implode('', array_map($fileLink, $e['files'])) . '</div>' : '') . '</td></tr>';
        }
        $b .= '</table></div>';
        // Chat messages linked to the case by modules (Teams, WhatsApp, Telegram…).
        require_once __DIR__ . '/modules.php';
        $chats = ecw_case_chat_material($caseId, 5000);
        if ($chats) {
            $b .= '<h2>Chat messages (' . count($chats) . ')</h2><div class="tablebox"><table><tr><th>When</th><th>Chat</th><th>From</th><th>Message</th></tr>';
            foreach ($chats as $c) {
                $b .= '<tr><td style="white-space:nowrap">' . $h($when($c['date'])) . '</td><td>' . $h($c['channel']) . '</td><td>'
                    . ($c['direction'] === 'sent by our team' ? '<span class="badge b-ours">Us</span> ' : '') . $h($c['from']) . '</td><td style="white-space:pre-wrap">' . $h($c['text']) . '</td></tr>';
            }
            $b .= '</table></div>';
            $zip->addFromString($prefix . 'chat-messages.csv', ecw_export_csv(array_map(static fn($c) => [ecw_tz_format($utc($c['date']), $tz, 'Y-m-d H:i'), $c['channel'], $c['from'], $c['direction'], $c['text']], $chats),
                ['When (' . $tz . ')', 'Chat', 'From', 'Direction', 'Message']));
        }
        // Every file of the case in one place.
        $allFiles = array_merge([], ...array_map(static fn($e) => array_map(static fn($fl) => $fl + [3 => $e], $e['files']), $timeline));
        if ($allFiles) {
            $b .= '<h2>All files (' . count($allFiles) . ')</h2><div class="tablebox"><table><tr><th>File</th><th>From</th><th>When</th><th>Conversation</th></tr>';
            foreach ($allFiles as $fl) {
                $e = $fl[3];
                $b .= '<tr><td>' . $fileLink($fl) . '</td><td>' . ($e['mine'] ? '<span class="badge b-ours">Us</span> ' : '') . $h($e['from']) . '</td><td style="white-space:nowrap">' . $h($when($e['at'])) . '</td>'
                    . '<td><a href="' . $h(ecw_export_href($e['folder'] . 'conversation.html')) . '#email-' . $e['num'] . '">' . $h($e['conv']) . '</a></td></tr>';
            }
            $b .= '</table></div>';
        }
    }
    if ($checklist) {
        $b .= '<h2>Checklist</h2><ul class="plain">';
        foreach ($checklist as $c) $b .= '<li>' . ($c['done_at'] ? '☑ ' : '☐ ') . $h($c['text']) . '<span class="muted small">' . ($c['assignee'] ? ' · ' . $h($c['assignee']) : '') . ($c['due_date'] ? ' · by ' . $h($c['due_date']) : '')
            . ($c['done_at'] ? ' · done ' . $h($day($c['done_at'])) . ($c['done_by'] ? ' by ' . $h($c['done_by']) : '') : '') . '</span></li>';
        $b .= '</ul>';
    }
    if ($notes) {
        $b .= '<h2>Internal notes</h2><ul class="plain">';
        foreach ($notes as $n) $b .= '<li><span class="muted small">' . $h($when($n['date'])) . ' · ' . $h($n['by']) . '</span><div class="body">' . $h($n['note']) . '</div></li>';
        $b .= '</ul>';
    }
    if ($history) {
        $b .= '<h2>History</h2><details><summary class="muted">Who did what (' . count($history) . ')</summary><div class="tablebox" style="margin-top:8px"><table><tr><th>When</th><th>Who</th><th>What</th></tr>';
        foreach ($history as $r) $b .= '<tr><td style="white-space:nowrap">' . $h($when($r['date'])) . '</td><td>' . $h($r['who']) . '</td><td>' . $h($r['what']) . '</td></tr>';
        $b .= '</table></div></details>';
    }
    $b .= '<h2>Data</h2><p class="small">For spreadsheets and other programs: <a href="data/emails.csv">emails.csv</a> · <a href="data/people.csv">people.csv</a>'
        . ($notes ? ' · <a href="data/notes.csv">notes.csv</a>' : '') . ($checklist ? ' · <a href="data/checklist.csv">checklist.csv</a>' : '')
        . ' · <a href="data/history.csv">history.csv</a> · <a href="data/case.json">case.json</a></p>';
    $zip->addFromString($prefix . 'index.html', ecw_export_page($case['case_number'] . ' ' . $case['name'], $b));

    // ---------------------------------------------------------------- data/
    $peopleRows = [];
    foreach ($people as $email => $p) $peopleRows[] = ['name' => $p['name'], 'email' => $email, 'side' => isset($ours[$email]) ? 'our team' : implode('/', array_keys($p['roles'])),
        'sent' => $p['sent'], 'received' => $p['received'], 'first' => $p['first'], 'last' => $p['last']];
    $summary = [
        'case' => $case['case_number'], 'name' => $case['name'], 'status' => $case['status'], 'group' => $case['group_name'],
        'responsible' => $case['owner_name'], 'handled_by' => $case['handler_name'], 'created' => $case['created_at'], 'closed' => $case['closed_at'],
        'due' => $case['due_at'], 'tags' => $tags, 'legal_hold' => $case['legal_hold_at'] !== null,
        'next_step' => ['action' => $next['next_action'], 'who' => $next['next_user_name'], 'waiting_on' => $next['waiting_on'], 'look_again' => $next['review_on'], 'set_by' => $next['by']],
        'references' => array_map(static fn($r) => ['kind' => $r['label'], 'value' => $r['value']], $next['refs']),
        'conversations' => array_map(static fn($t) => ['subject' => $t['display_name'] ?: $t['normalized_subject'], 'whose' => $t['party_role'], 'mailbox' => $t['email_address']], $threads),
        'exported_at' => gmdate('Y-m-d H:i:s') . ' UTC',
    ];
    $zip->addFromString($prefix . 'data/case.json', ecw_export_json($summary + ['people' => $peopleRows, 'notes' => $notes, 'checklist' => $checklist, 'followups' => $followups, 'history' => $history]));
    $zip->addFromString($prefix . 'data/emails.csv', ecw_export_csv($emailRows, ['date', 'conversation', 'whose', 'direction', 'from', 'to', 'cc', 'subject', 'attachments', 'file']));
    $zip->addFromString($prefix . 'data/people.csv', ecw_export_csv($peopleRows, ['name', 'email', 'side', 'sent', 'received', 'first', 'last']));
    if ($notes) $zip->addFromString($prefix . 'data/notes.csv', ecw_export_csv($notes));
    if ($checklist) $zip->addFromString($prefix . 'data/checklist.csv', ecw_export_csv($checklist));
    if ($followups) $zip->addFromString($prefix . 'data/followups.csv', ecw_export_csv($followups));
    $zip->addFromString($prefix . 'data/history.csv', ecw_export_csv($history, ['date', 'who', 'what']));
    if ($out['missing']) $zip->addFromString($prefix . 'NOT INCLUDED.txt', "These attachments could not be fetched from the mailbox:\r\n" . implode("\r\n", $out['missing']) . "\r\n");
    return $out;
}

/** A subject without Re:/Fw: prefixes, lower case — to show an email's subject only when it differs from its conversation's. */
function ecw_export_normalize_subject(string $s): string
{
    return mb_strtolower(trim((string)preg_replace('/^\s*((re|fw|fwd|aw|wg|σχετ|πρθ)\s*:\s*)+/iu', '', $s)));
}

/** One case as a zip file (a temporary path; the caller sends and deletes it). */
function ecw_export_case_zip(int $orgId, int $caseId, int $userId): string
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Exporting needs the PHP zip extension on the server.');
    $path = tempnam(sys_get_temp_dir(), 'ecwcase');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('The zip file could not be created.');
    $zip->addFromString('README.txt', "One case exported from Email Case Workspace. Unzip it first, then open index.html.\r\n\r\n"
        . "index.html                the case: next step, conversations, people, all emails in order, checklist, notes, history\r\n"
        . "conversations/<n> .../    one folder per conversation:\r\n"
        . "   conversation.html      its emails, ours on the right, theirs on the left\r\n"
        . "   files/                 its attachments, as normal files\r\n"
        . "   emails/*.eml           the original emails with their attachments (open in Outlook, Thunderbird, Apple Mail)\r\n"
        . "data/                     the same as spreadsheets (Excel) and JSON\r\n");
    ecw_export_case_into($zip, '', $orgId, $caseId, true);
    $zip->close();
    require_once __DIR__ . '/audit.php';
    ecw_audit('case.export', 'case', $caseId, [], $orgId);
    return $path;
}

// --------------------------------------------------------------- the whole workspace

function ecw_export_state(int $orgId): array
{
    $s = json_decode((string)(ecw_app_setting('export_' . $orgId) ?? ''), true) ?: [];
    if (($s['state'] ?? '') === 'ready' && (empty($s['file']) || !is_file($s['file']))) $s = [];   // cleaned up
    return $s;
}

/** An admin asks for everything: the worker builds it (ecw_export_due) and tells them when it is ready. */
function ecw_export_workspace_start(int $orgId, int $userId, bool $withFiles = true): array
{
    $s = ecw_export_state($orgId);
    if (in_array($s['state'] ?? '', ['queued', 'running'], true) && strtotime((string)$s['at'] . ' UTC') > time() - 6 * 3600) return $s;
    $s = ['state' => 'queued', 'by' => $userId, 'at' => gmdate('Y-m-d H:i:s'), 'files' => $withFiles];
    ecw_app_setting_set('export_' . $orgId, (string)json_encode($s));
    require_once __DIR__ . '/audit.php';
    ecw_audit('workspace.export', null, null, ['files' => $withFiles], $orgId);
    return $s;
}

/** From the worker: build waiting exports, remove old ones. Returns how many were built. */
function ecw_export_due(): int
{
    $n = 0;
    $dir = ECW_EXPORT_DIR;
    foreach (glob($dir . '/*.zip') ?: [] as $f) if (filemtime($f) < time() - ECW_EXPORT_KEEP_DAYS * 86400) @unlink($f);
    foreach (ecw_db()->query('SELECT id FROM `' . ecw_table('organizations') . '`')->fetchAll(PDO::FETCH_COLUMN) as $orgId) {
        $s = ecw_export_state((int)$orgId);
        if (($s['state'] ?? '') !== 'queued') continue;
        $s['state'] = 'running';
        ecw_app_setting_set('export_' . $orgId, (string)json_encode($s));
        try {
            $s = ecw_export_workspace_build((int)$orgId, $s);
            $n++;
            require_once __DIR__ . '/notifications.php';
            ecw_notify((int)$orgId, (int)$s['by'], null, 'system', 'Your export of everything is ready to download (' . round($s['size'] / 1048576, 1) . ' MB, kept ' . ECW_EXPORT_KEEP_DAYS . ' days).', 'settings/backups.php#export');
        } catch (Throwable $e) {
            ecw_log('workspace export failed: ' . $e->getMessage());
            $s = ['state' => 'failed', 'error' => $e->getMessage(), 'at' => gmdate('Y-m-d H:i:s'), 'by' => $s['by'] ?? null];
        }
        ecw_app_setting_set('export_' . $orgId, (string)json_encode($s));
    }
    return $n;
}

function ecw_export_workspace_build(int $orgId, array $s): array
{
    // Times in the zone of the person who asked for it (it is built later, by the worker).
    require_once __DIR__ . '/timezone.php';
    $tzW = ecw_tz_for_user(isset($s['by']) ? (int)$s['by'] : null, $orgId);
    if (!class_exists('ZipArchive')) throw new RuntimeException('Exporting needs the PHP zip extension on the server.');
    @set_time_limit(0);
    if (!is_dir(ECW_EXPORT_DIR)) @mkdir(ECW_EXPORT_DIR, 0770, true);
    $path = ECW_EXPORT_DIR . '/ecw-export-' . $orgId . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('The export file could not be created in storage/exports.');
    $pdo = ecw_db();
    $q = static function (string $sql, array $p = []) use ($pdo): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(); };
    $zip->addFromString('README.txt', "Everything from Email Case Workspace, exported " . ecw_tz_format(gmdate('Y-m-d H:i:s'), $tzW, 'Y-m-d H:i') . " ({$tzW} time). Unzip it first, then open index.html.\r\n\r\n"
        . "index.html     every case, with its status and next step: click one to open it\r\n"
        . "cases/         one folder per case, each with its own index.html, a folder per conversation\r\n"
        . "               (its emails as a page, its files, the original .eml emails) and data/ (spreadsheets)\r\n"
        . "cases.csv, people.csv, groups.csv, companies.csv, case-templates.json\r\n\r\n"
        . "Emails that are in no case are not repeated here: they are in your Outlook or Gmail mailbox.\r\n");
    $cases = $q('SELECT c.id, c.case_number, c.name, c.status, g.name AS group_name, o.name AS responsible, h.name AS handled_by, c.created_at, c.closed_at, c.due_at,
                        c.next_action, c.waiting_on, c.review_on, c.deleted_at
                   FROM `' . ecw_table('cases') . '` c LEFT JOIN `' . ecw_table('case_groups') . '` g ON g.id = c.group_id
                   LEFT JOIN `' . ecw_table('users') . '` o ON o.id = c.owner_user_id LEFT JOIN `' . ecw_table('users') . '` h ON h.id = c.handler_user_id
                  WHERE c.organization_id = ? AND (c.deleted_at IS NULL OR c.legal_hold_at IS NOT NULL) ORDER BY c.id', [$orgId]);
    $rows = [];
    $missing = 0;
    foreach ($cases as $c) {
        $folder = 'cases/' . ecw_export_name($c['case_number'] . ' ' . $c['name'], 70) . '/';
        $r = ecw_export_case_into($zip, $folder, $orgId, (int)$c['id'], !empty($s['files']), $tzW);
        // Written to disk after every case: a big workspace never has to fit in memory.
        $zip->close();
        if ($zip->open($path) !== true) throw new RuntimeException('The export file could not be reopened.');
        $missing += count($r['missing']);
        unset($c['id'], $c['deleted_at']);
        $rows[] = $c + ['emails' => $r['emails'], 'files' => $r['files'], 'folder' => $folder];
    }
    $zip->addFromString('cases.csv', ecw_export_csv($rows));
    // The front page: every case, newest first, each opening its own overview.
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $org = (string)($q('SELECT name FROM `' . ecw_table('organizations') . '` WHERE id = ?', [$orgId])[0]['name'] ?? '');
    $list = '<h1>' . $h($org !== '' ? $org . ' — all cases' : 'All cases') . '</h1><p class="muted small">Exported from Email Case Workspace on ' . $h(ecw_tz_format(gmdate('Y-m-d H:i:s'), $tzW)) . ' (' . $h($tzW) . ' time) · '
        . count($rows) . ' cases. Click a case to open it; everything opens without the app.</p>'
        . '<div class="tablebox"><table><tr><th>Case</th><th>Name</th><th>Status</th><th>Next step</th><th>Responsible</th><th>Created</th><th class="num">Emails</th><th class="num">Files</th></tr>';
    foreach (array_reverse($rows) as $r) {
        $list .= '<tr><td><a href="' . $h(ecw_export_href($r['folder'] . 'index.html')) . '">' . $h($r['case_number']) . '</a></td><td>' . $h($r['name']) . '</td>'
            . '<td><span class="badge b-' . $h($r['status']) . '">' . $h(ucfirst((string)$r['status'])) . '</span></td><td>' . $h($r['next_action'] ?? '') . '</td>'
            . '<td>' . $h($r['responsible'] ?? '') . '</td><td style="white-space:nowrap">' . $h(substr((string)$r['created_at'], 0, 10)) . '</td>'
            . '<td class="num">' . (int)$r['emails'] . '</td><td class="num">' . (int)$r['files'] . '</td></tr>';
    }
    $zip->addFromString('index.html', ecw_export_page('All cases', $list . '</table></div>'));
    $zip->addFromString('people.csv', ecw_export_csv($q('SELECT u.name, u.email, ou.role, u.disabled_at FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id WHERE ou.organization_id = ? ORDER BY u.name', [$orgId])));
    $zip->addFromString('groups.csv', ecw_export_csv($q('SELECT g.name, GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ", ") AS members FROM `' . ecw_table('case_groups') . '` g
        LEFT JOIN `' . ecw_table('case_group_members') . '` gm ON gm.group_id = g.id LEFT JOIN `' . ecw_table('users') . '` u ON u.id = gm.user_id WHERE g.organization_id = ? GROUP BY g.id ORDER BY g.name', [$orgId]), ['name', 'members']));
    $zip->addFromString('companies.csv', ecw_export_csv($q('SELECT name, domain, phone, website, notes FROM `' . ecw_table('companies') . '` WHERE organization_id = ? ORDER BY name', [$orgId]), ['name', 'domain', 'phone', 'website', 'notes']));
    try { $zip->addFromString('case-templates.json', ecw_export_json($q('SELECT * FROM `' . ecw_table('case_templates') . '` WHERE organization_id = ?', [$orgId]))); } catch (Throwable $e) {}
    $zip->close();
    return ['state' => 'ready', 'file' => $path, 'size' => (int)filesize($path), 'cases' => count($rows), 'missing' => $missing, 'at' => gmdate('Y-m-d H:i:s'), 'by' => $s['by'] ?? null];
}
