<?php declare(strict_types=1);
/**
 * Step 05A tests — compact message rendering.
 *
 *   php tests/render_density_test.php
 *
 * ecw_collapse_display_whitespace() (lib/sanitize.php) is the one place that
 * caps <br> pile-ups and stacks of empty <p>/<div>, on the display copy only.
 * Every caller of ecw_sanitize_email_html() — the reading pane, the case
 * timeline, quoted history, signatures — runs through it, so these are the
 * eight scenarios the step's acceptance list asks for, exercised once.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/log.php';
require_once dirname(__DIR__) . '/lib/sanitize.php';
require_once dirname(__DIR__) . '/lib/body.php';

$pass = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass;
    if (!$ok) { fwrite(STDERR, "FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"); exit(1); }
    $pass++;
    echo "PASS {$label}\n";
}

// 1. Multiple <p> tags — real paragraphs are content, never candidates for removal.
$multiP = ecw_sanitize_email_html('<p>First line.</p><p>Second line.</p><p>Third line.</p>');
check(substr_count($multiP, '<p>') === 3, 'three real paragraphs all survive', $multiP);
check(str_contains($multiP, 'First line.') && str_contains($multiP, 'Third line.'), 'their content is untouched');

// 2. 5 consecutive <br> collapses to at most 2.
$fiveBr = ecw_sanitize_email_html('<p>Hi</p><br><br><br><br><br><p>Bye</p>');
check((bool)preg_match('/^<p>Hi<\/p>(<br>){1,2}<p>Bye<\/p>$/', $fiveBr),
    'five consecutive <br> collapse to at most two', $fiveBr);

// 3. Empty <div><br></div> — a lone one survives, a stack of them does not.
$loneDiv = ecw_sanitize_email_html('<p>Hi</p><div><br></div><p>Bye</p>');
check(str_contains($loneDiv, '<div class="ecw-gap"><br></div>'), 'a single blank div stays, drawn as a small gap', $loneDiv);
$divStack = ecw_sanitize_email_html('<p>Hi</p><p>&nbsp;</p><p><br></p><div><br></div><div><br></div><p>Bye</p>');
check(substr_count($divStack, '<div>') + substr_count($divStack, '<p>') <= 4,
    'a stack of blank paragraphs/divs is capped, not left to pile up', $divStack);
check(str_contains($divStack, 'Hi') && str_contains($divStack, 'Bye'), 'the real content around the stack survives');

// 4. HTML signature — same collapsing applies to the signature field.
$signature = ecw_sanitize_email_html('<p>Best regards,</p><br><br><br><br><p>Jane Doe — Acme Corp</p>');
check(!preg_match('/(<br>\s*){3,}/', $signature), 'a padded signature is compacted the same way as a body', $signature);
check(str_contains($signature, 'Jane Doe'), 'and its content is intact');

// 5. Plain text with repeated blank lines.
$plain = ecw_message_display_parts(['body_html' => '', 'body_text' => "Hi team,\n\n\n\n\nSee attached.\n\nBest,\nJane"]);
$plainRendered = ecw_sanitize_email_html($plain['body_html']);
check(!preg_match('/(<br>\s*){3,}/', $plainRendered), 'plain-text blank-line runs are capped once converted for display', $plainRendered);
check(str_contains($plainRendered, 'Hi team,') && str_contains($plainRendered, 'See attached.') && str_contains($plainRendered, 'Jane'),
    'and every line of actual text is still there');

// 6. Lists are untouched.
$list = ecw_sanitize_email_html('<ul><li>Alpha</li><li>Beta</li><li>Gamma</li></ul>');
check($list === '<ul><li>Alpha</li><li>Beta</li><li>Gamma</li></ul>', 'a list survives byte-for-byte', $list);

// 7. Tables are untouched, including a blank run nested inside a cell.
$table = ecw_sanitize_email_html('<table><tr><td>Qty</td><td>10</td></tr></table>');
check($table === '<table><tr><td>Qty</td><td>10</td></tr></table>', 'a table survives byte-for-byte', $table);
$tableWithPadding = ecw_sanitize_email_html('<table><tr><td>Note<br><br><br><br>End</td></tr></table>');
check((bool)preg_match('/^<table><tr><td>Note(<br>){1,2}End<\/td><\/tr><\/table>$/', $tableWithPadding),
    'a blank run nested inside a table cell is capped without breaking the table', $tableWithPadding);

// 8. Quoted replies — the quoted-history field goes through the exact same
// function as the body, so padding inside quoted history is capped too, and
// the split itself (current vs. quoted) is unaffected by any of the above.
$withHistory = ecw_message_display_parts([
    'body_html' => '<p>Current reply</p><blockquote type="cite"><p>Older text</p><br><br><br><br><p>End of quote</p></blockquote>',
]);
check(str_contains($withHistory['body_html'], 'Current reply') && $withHistory['has_quoted'],
    'quoted history still splits off the current reply');
$quotedRendered = ecw_sanitize_email_html($withHistory['quoted_html']);
check(!preg_match('/(<br>\s*){3,}/', $quotedRendered) && str_contains($quotedRendered, 'Older text') && str_contains($quotedRendered, 'End of quote'),
    'and padding inside the quoted history is capped the same way as a live message', $quotedRendered);

// 9. Outlook's empty lines: <p><span>&nbsp;</span></p>, one per blank line.
$outlook = ecw_sanitize_email_html('<div class="WordSection1"><p><span>Γεια σου,</span></p><p><span>Θέλω να μετρήσω.</span></p>'
    . '<p><span>&nbsp;</span></p><p><span>&nbsp;</span></p><p><span style="font-size:11pt">&nbsp;</span></p><p><span><o:p>&nbsp;</o:p></span></p>'
    . '<p><span>Best regards,</span></p><p><span>&nbsp;</span></p><p><span>Georgios</span></p></div>');
check(substr_count($outlook, 'ecw-gap') === 2, 'Outlook empty lines count as empty: four in a row become one small gap, a single one stays a gap', $outlook);
check(str_contains($outlook, 'Θέλω να μετρήσω.') && str_contains($outlook, 'Best regards,') && str_contains($outlook, 'Georgios'), 'every line of text is still there');
$withPicture = ecw_sanitize_email_html('<p><span><img src="cid:x" alt="drawing"></span></p><p><span>&nbsp;</span></p>');
check(str_contains($withPicture, 'alt="drawing"'), 'a paragraph holding only a picture is not empty', $withPicture);
// 10. A line break inside a paragraph is kept; two make one empty line.
$br = ecw_sanitize_email_html('<p>Kind regards,<br>Nik</p><p>One<br><br><br>Two</p>');
check(str_contains($br, 'Kind regards,<br>Nik') && (bool)preg_match('/One(<br>){2}Two/', $br), 'single line breaks stay, a pile becomes one empty line', $br);

echo "\n{$pass} passed\n";
