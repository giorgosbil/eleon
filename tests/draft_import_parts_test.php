<?php declare(strict_types=1);
/**
 * A draft written in Outlook, opened in the app (lib/drafts_sync.php ecw_draft_import_parts):
 * what was written goes to the editor, the signature and the earlier messages are kept apart,
 * exactly, so the editor never turns a signature table into a black grid.
 *
 *   php tests/draft_import_parts_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/drafts_sync.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$sigTable = '<table><tr><td>Georgios</td><td><img src="https://example.test/logo.png" width="200"></td></tr></table>';
$quote = '<div id="appendonsend"></div><hr><div id="divRplyFwdMsg"><b>From:</b> Someone<br><b>Subject:</b> Offer</div><div>Earlier message text <table><tr><td>a</td></tr></table></div>';

echo "\n=== New Outlook: the signature and the reply marked ===\n";
$html = '<html><head><style>p{margin:0}</style></head><body><div class="elementToProof">Thank you for your understanding.</div>'
    . '<div id="Signature"><p>With kind regards,</p>' . $sigTable . '</div>' . $quote . '</body></html>';
$p = ecw_draft_import_parts($html);
check(str_contains($p['answer'], 'Thank you for your understanding') && stripos($p['answer'], '<table') === false, 'the text written goes to the editor, without the signature table');
check(str_contains($p['signature'], 'With kind regards') && str_contains($p['signature'], 'logo.png'), 'the signature is kept apart, with its picture');
check(str_contains($p['quote'], 'Earlier message text') && !str_contains($p['answer'], 'Earlier message'), 'the earlier messages are kept apart');
check($p['quote_changed'] === false, 'the earlier messages are sent as they were');

echo "\n=== Classic Outlook: nothing marked ===\n";
$html = '<html><body><div class="WordSection1"><p class="MsoNormal">Dear Savvas,</p><p class="MsoNormal">We will send the offer next week.</p>'
    . '<p class="MsoNormal">With kind regards,</p>' . $sigTable . '<p>after the table</p></div></body></html>';
$p = ecw_draft_import_parts($html);
check(str_contains($p['answer'], 'We will send the offer next week') && stripos($p['answer'], '<table') === false, 'the text before the first table goes to the editor');
check(str_starts_with(ltrim($p['signature']), '<table') && str_contains($p['signature'], 'after the table'), 'from the first table on, everything is kept exactly with the signature');

echo "\n=== A plain draft ===\n";
$p = ecw_draft_import_parts('<p>Just a line.</p>');
check($p['answer'] === '<p>Just a line.</p>' && $p['signature'] === '' && $p['quote'] === '', 'a draft without a signature or earlier messages stays as it is');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
