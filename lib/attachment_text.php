<?php declare(strict_types=1);
/**
 * Reading the text inside attachments, so search and the AI can see what a
 * PDF invoice, a Word letter or an Excel price list says.
 *
 *   PDF                 pdftotext (poppler-utils, in the Docker image)
 *   DOCX / XLSX / PPTX  the XML inside the zip
 *   TXT, CSV, MD, EML…  as text
 *
 * Run by the background worker (bin/worker.php), newest emails first, a few
 * files per round. Files are read from the attachment cache, or downloaded
 * once from Outlook / Gmail and cached exactly as opening them in the app does.
 * Limits: 25 MB per file, 300,000 characters of text, 25 s per PDF. Attachment
 * text is untrusted data, like email text.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

const ECW_ATTACH_TEXT_MAX_BYTES = 26214400;
const ECW_ATTACH_TEXT_MAX_CHARS = 300000;
const ECW_ATTACH_TEXT_TYPES = ['pdf', 'docx', 'xlsx', 'pptx', 'txt', 'csv', 'md', 'log', 'eml', 'json', 'xml', 'html', 'htm', 'rtf'];

function ecw_attach_ext(string $filename): string
{
    return strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * The file's bytes: from the cache, or downloaded. Null when unavailable.
 * $cache false (reading the text, exports): the download is not kept on disk,
 * only what people open is (lib/storage.php clears that cache too, later).
 */
function ecw_attachment_bytes(array $a, bool $cache = true): ?string
{
    $path = (string)($a['local_path'] ?? '');
    if ($path !== '' && is_file($path)) {
        $b = @file_get_contents($path);
        return $b === false ? null : $b;
    }
    require_once __DIR__ . '/mailbox.php';
    try {
        $bytes = ecw_download_attachment($a);
    } catch (Throwable $e) {
        ecw_log('attachment text: download failed for attachment ' . (int)$a['id'] . ': ' . get_class($e));
        return null;
    }
    if (!$cache) return $bytes;
    $dir = dirname(__DIR__) . '/storage/attachments';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $path = $dir . '/' . (int)$a['id'] . '_' . bin2hex(random_bytes(4));
    if (is_dir($dir) && is_writable($dir) && @file_put_contents($path, $bytes) !== false) {
        ecw_db()->prepare('UPDATE `' . ecw_table('email_attachments') . "` SET local_path = ?, provider_download_state = 'downloaded' WHERE id = ?")->execute([$path, (int)$a['id']]);
    }
    return $bytes;
}

/** Text of a file's bytes by type; '' when there is none; throws when the file cannot be read. */
function ecw_extract_text(string $bytes, string $ext): string
{
    switch ($ext) {
        case 'pdf':  return ecw_extract_pdf($bytes);
        case 'docx': return ecw_extract_zip_xml($bytes, ['#^word/(document|header\d*|footer\d*|footnotes)\.xml$#'], 'w:p');
        case 'pptx': return ecw_extract_zip_xml($bytes, ['#^ppt/slides/slide\d+\.xml$#'], 'a:p');
        case 'xlsx': return ecw_extract_xlsx($bytes);
        case 'html': case 'htm': case 'eml':
            require_once __DIR__ . '/body.php';
            return ecw_html_plain(ecw_to_utf8($bytes));
        case 'rtf':
            $t = (string)preg_replace(['/\\\\par[d]?/', '/\{\\\\\*[^{}]*\}/', '/\\\\[a-z]+-?\d* ?/i', '/[{}]/'], ["\n", '', '', ''], $bytes);
            return trim(ecw_to_utf8($t));
        default:     return trim(ecw_to_utf8($bytes));
    }
}

function ecw_to_utf8(string $s): string
{
    if (str_starts_with($s, "\xEF\xBB\xBF")) $s = substr($s, 3);
    if (str_starts_with($s, "\xFF\xFE") || str_starts_with($s, "\xFE\xFF")) return (string)mb_convert_encoding($s, 'UTF-8', 'UTF-16');
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    // Greek Windows text first (the common case here), then Western. iconv knows CP1253; mbstring does not.
    foreach (['CP1253', 'ISO-8859-7', 'CP1252'] as $enc) {
        $c = function_exists('iconv') ? @iconv($enc, 'UTF-8//IGNORE', $s) : false;
        if (is_string($c) && $c !== '') return $c;
    }
    return (string)mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
}

function ecw_extract_pdf(string $bytes): string
{
    $bin = trim((string)shell_exec('command -v pdftotext 2>/dev/null'));
    if ($bin === '') throw new RuntimeException('pdftotext is not installed');
    $in = tempnam(sys_get_temp_dir(), 'ecwpdf');
    file_put_contents($in, $bytes);
    try {
        $proc = proc_open([$bin, '-layout', '-enc', 'UTF-8', '-q', $in, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('pdftotext could not start');
        stream_set_blocking($pipes[1], false);
        $out = ''; $start = microtime(true);
        while (true) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') { $out .= $chunk; if (strlen($out) > ECW_ATTACH_TEXT_MAX_CHARS * 4) break; continue; }
            $status = proc_get_status($proc);
            if (!$status['running']) { $out .= (string)stream_get_contents($pipes[1]); break; }
            if (microtime(true) - $start > 25) { proc_terminate($proc, 9); throw new RuntimeException('pdftotext took too long'); }
            usleep(20000);
        }
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($proc);
        return trim((string)preg_replace("/[ \t]+\n/", "\n", (string)preg_replace('/\f/', "\n\n", $out)));
    } finally {
        @unlink($in);
    }
}

/** Text of the XML parts of an Office file, one line per paragraph ($para: w:p / a:p). */
function ecw_extract_zip_xml(string $bytes, array $patterns, string $para): string
{
    $zip = ecw_open_zip($bytes, $tmp);
    try {
        $text = [];
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            foreach ($patterns as $p) if (preg_match($p, $n)) $names[] = $n;
        }
        natsort($names);
        foreach ($names as $n) {
            $xml = (string)$zip->getFromName($n);
            if (strlen($xml) > 30000000) continue;
            $xml = str_replace(['</' . $para . '>', '<w:tab/>', '<w:br/>'], ["\n", "\t", "\n"], $xml);
            $text[] = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return trim(implode("\n\n", $text));
    } finally {
        $zip->close(); @unlink($tmp);
    }
}

function ecw_extract_xlsx(string $bytes): string
{
    $zip = ecw_open_zip($bytes, $tmp);
    try {
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false && preg_match_all('#<si>(.*?)</si>#s', $ss, $m)) {
            foreach ($m[1] as $si) $shared[] = html_entity_decode(strip_tags($si), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        $lines = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) continue;
            $xml = (string)$zip->getFromName($n);
            if (!preg_match_all('#<row\b[^>]*>(.*?)</row>#s', $xml, $rows)) continue;
            foreach ($rows[1] as $row) {
                $cells = [];
                if (preg_match_all('#<c\b([^>]*)>(?:.*?<v>(.*?)</v>)?(?:.*?<is>(.*?)</is>)?.*?</c>#s', $row, $cs, PREG_SET_ORDER)) {
                    foreach ($cs as $c) {
                        $v = $c[2] ?? '';
                        if (str_contains($c[1], 't="s"') && $v !== '') $v = $shared[(int)$v] ?? '';
                        elseif (!empty($c[3])) $v = strip_tags($c[3]);
                        $v = trim(html_entity_decode($v, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        if ($v !== '') $cells[] = $v;
                    }
                }
                if ($cells) $lines[] = implode("\t", $cells);
                if (count($lines) > 20000) break 2;
            }
        }
        return trim(implode("\n", $lines));
    } finally {
        $zip->close(); @unlink($tmp);
    }
}

function ecw_open_zip(string $bytes, ?string &$tmp): ZipArchive
{
    $tmp = tempnam(sys_get_temp_dir(), 'ecwzip');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); throw new RuntimeException('not a valid Office file'); }
    return $zip;
}

/** Read one attachment and store its text and state. Returns the state. */
function ecw_attachment_extract(int $attachmentId): string
{
    $st = ecw_db()->prepare('SELECT a.*, m.provider_message_id, m.mailbox_id FROM `' . ecw_table('email_attachments') . '` a
                               JOIN `' . ecw_table('email_messages') . '` m ON m.id = a.email_message_id WHERE a.id = ?');
    $st->execute([$attachmentId]);
    $a = $st->fetch();
    if (!$a) return 'missing';
    $ext = ecw_attach_ext((string)$a['filename']);
    $save = static function (string $state, ?string $text = null) use ($attachmentId): string {
        ecw_db()->prepare('UPDATE `' . ecw_table('email_attachments') . '` SET text_state = ?, text_content = ?, text_extracted_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$state, $text, $attachmentId]);
        return $state;
    };
    if ((int)$a['is_inline'] === 1 || !in_array($ext, ECW_ATTACH_TEXT_TYPES, true)) return $save('skipped');
    if ((int)($a['size_bytes'] ?? 0) > ECW_ATTACH_TEXT_MAX_BYTES) return $save('too_big');
    $bytes = ecw_attachment_bytes($a, false);   // read, keep the text, not the file
    if ($bytes === null) return $save('failed');
    if (strlen($bytes) > ECW_ATTACH_TEXT_MAX_BYTES) return $save('too_big');
    try {
        $text = ecw_extract_text($bytes, $ext);
    } catch (Throwable $e) {
        ecw_log('attachment text: could not read attachment ' . $attachmentId . ' (' . $ext . '): ' . $e->getMessage());
        return $save('failed');
    }
    $text = trim((string)preg_replace('/[ \t]{3,}/', '  ', (string)preg_replace("/\n{3,}/", "\n\n", $text)));
    if (!mb_check_encoding($text, 'UTF-8')) $text = (string)mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    if ($text === '') return $save('empty');
    return $save('done', mb_substr($text, 0, ECW_ATTACH_TEXT_MAX_CHARS));
}

/** One worker round: read up to $limit files that were never read, newest emails first. */
function ecw_attachment_text_due(int $limit = 5): int
{
    $like =implode(' OR ', array_map(static fn($e) => 'LOWER(a.filename) LIKE ' . ecw_db()->quote('%.' . $e), ECW_ATTACH_TEXT_TYPES));
    $rows = ecw_db()->query('SELECT a.id FROM `' . ecw_table('email_attachments') . '` a
                               JOIN `' . ecw_table('email_messages') . '` m ON m.id = a.email_message_id
                              WHERE a.text_state IS NULL AND a.is_inline = 0 AND m.provider_removed_at IS NULL AND (' . $like . ')
                              ORDER BY m.occurred_at DESC LIMIT ' . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $id) ecw_attachment_extract((int)$id);
    return count($rows);
}

/** Text of an email's files for the AI, each cut to $perFile characters. */
function ecw_attachment_texts(int $messageId, int $perFile = 4000, int $maxFiles = 3): array
{
    $st = ecw_db()->prepare('SELECT id, filename, text_content FROM `' . ecw_table('email_attachments') . "` WHERE email_message_id = ? AND is_inline = 0 AND text_state = 'done' ORDER BY id LIMIT " . max(1, $maxFiles));
    $st->execute([$messageId]);
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['filename'], 'text' => mb_substr((string)$r['text_content'], 0, $perFile)], $st->fetchAll());
}
