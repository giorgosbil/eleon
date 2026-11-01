<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/body.php';
$pass = 0;
function check(bool $ok, string $label): void {
    global $pass;
    if (!$ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
    $pass++; echo "PASS $label\n";
}
foreach ([
    '<div style="color:black" id="divRplyFwdMsg">Old reply</div>',
    "<div class='extra gmail_quote more'>Old reply</div>",
    '<blockquote style="margin:0" type="cite">Old reply</blockquote>',
    '<div class="yahoo_quoted">Old reply</div>',
    '<div style="border:none;border-top:solid #eee 1pt"><p><b>From:</b> sender@example.test<br><b>Sent:</b> Monday<br><b>To:</b> recipient@example.test<br><b>Subject:</b> Previous email</p></div><p>Old reply</p>',
] as $history) {
    $parts = ecw_message_display_parts(['body_html'=>'<p>Current reply</p>' . $history]);
    check(str_contains($parts['body_html'], 'Current reply') && !str_contains($parts['body_html'], 'Old reply')
        && str_contains($parts['quoted_html'], 'Old reply') && $parts['has_quoted'], 'client history marker collapses at display time');
}
$parts = ecw_message_display_parts(['body_html'=>'<p>Current</p><div id="divRplyFwdMsg">Recent history</div>', 'quoted_html'=>'<p>Older history</p>']);
check(str_contains($parts['quoted_html'], 'Recent history') && str_contains($parts['quoted_html'], 'Older history'), 'newly detected and already stored history are both retained');
$parts = ecw_message_display_parts(['body_html'=>'<blockquote type="cite">Only history</blockquote>', 'body_text'=>'Only history']);
check($parts['body_html'] === '' && $parts['has_quoted'], 'quote-only message does not leak history through text fallback');
$parts = ecw_message_display_parts(['body_html'=>'<p>Current</p><div style="border-top:1px solid">Important current content</div>']);
check(str_contains($parts['body_html'], 'Important current content') && !$parts['has_quoted'], 'decorative border is not mistaken for history');
$parts = ecw_message_display_parts(['body_text'=>"Current\n-----Original Message-----\nPrevious"]);
check(!str_contains($parts['body_html'], 'Previous') && str_contains($parts['quoted_html'], 'Previous'), 'plain text history collapses');
echo "$pass passed\n";
