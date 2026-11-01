<?php declare(strict_types=1);
/**
 * Fold the quoted history of emails already downloaded, after lib/body.php
 * learned new formats. Only touches emails with no history split yet; the
 * split is a plain cut (body + quoted = the original), so nothing is lost.
 *
 *   php bin/resplit_quotes.php            dry run: counts and shows samples
 *   php bin/resplit_quotes.php --apply    writes the new split
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/body.php';

$apply = in_array('--apply', $argv, true);
$db = ecw_db();
$table = ecw_table('email_messages');
$update = $db->prepare("UPDATE `{$table}` SET body_html = ?, quoted_html = ? WHERE id = ? AND quoted_html IS NULL");
$last = 0; $seen = 0; $split = 0; $samples = 0;
do {
    $st = $db->prepare("SELECT id, subject, body_html FROM `{$table}` WHERE id > ? AND quoted_html IS NULL AND body_html IS NOT NULL AND body_html <> '' ORDER BY id LIMIT 500");
    $st->execute([$last]);
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
        $last = (int)$r['id']; $seen++;
        $cut = ecw_find_html_quote_boundary((string)$r['body_html']);
        if ($cut === null || $cut <= 0) continue;
        $body = substr((string)$r['body_html'], 0, $cut);
        $quoted = substr((string)$r['body_html'], $cut);
        // Never leave an email looking empty: some text must stay above the fold.
        if (trim(ecw_quote_plain($body)) === '') continue;
        $split++;
        if ($samples < 8) {
            $samples++;
            printf("M%d %s\n   keeps: %s\n   folds: %s\n", $last, mb_substr((string)$r['subject'], 0, 60),
                mb_substr(trim((string)preg_replace('/\s+/u', ' ', ecw_quote_plain($body))), -90),
                mb_substr(trim((string)preg_replace('/\s+/u', ' ', ecw_quote_plain($quoted))), 0, 90));
        }
        if ($apply) $update->execute([$body, $quoted, $last]);
    }
} while ($rows);
printf("\n%d emails checked, %d %s.\n", $seen, $split, $apply ? 'now fold their history' : 'would fold their history (dry run; add --apply)');
