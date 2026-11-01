<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/body.php';
$checks = 0;
function check(bool $ok, string $name): void {
    global $checks; $checks++;
    if (!$ok) { fwrite(STDERR, "FAIL $name\n"); exit(1); }
    echo "PASS $name\n";
}
foreach (['id="Signature"', 'class="gmail_signature"', 'class="moz-signature"', 'data-smartmail="gmail_signature"'] as $marker) {
    $parts = ecw_split_signature_html('<p>Decision stays visible.</p><div ' . $marker . '><p>Sender contact</p></div><p>Important postscript.</p>');
    check(str_contains($parts['body_html'], 'Decision stays visible.') && str_contains($parts['body_html'], 'Important postscript.')
        && !str_contains($parts['body_html'], 'Sender contact') && str_contains($parts['signature_html'], 'Sender contact'), 'marked signature collapses without hiding neighboring message content: ' . $marker);
}
$original = '<p>Best regards is part of the discussion, not necessarily a signature.</p>';
check(ecw_split_signature_html($original) === ['body_html'=>$original, 'signature_html'=>''], 'unmarked content remains untouched');
$parts = ecw_split_signature_html('<p>Thanks, see below.</p><div id="Signature"><div class="gmail_signature">Nested signature</div></div>');
check(substr_count($parts['signature_html'], 'Nested signature') === 1, 'nested signature rendered once');
$parts = ecw_split_signature_html('<p>Καλημέρα</p><div id="Signature">Γιώργος</div>');
check(str_contains($parts['body_html'], 'Καλημέρα') && str_contains($parts['signature_html'], 'Γιώργος'), 'Unicode remains intact');
// The whole message written inside the signature box (Outlook's id="Signature", Gmail's
// gmail_signature): nothing is folded, the email shows whole.
foreach (['id="signature"', 'class="gmail_signature"'] as $marker) {
    $whole = '<p><br></p><div ' . $marker . '><p>Dear George,</p><p>Please send the offer for the calibration by Friday.</p><p>Best regards, Maria</p></div>';
    $parts = ecw_split_signature_html($whole);
    check($parts['signature_html'] === '' && str_contains($parts['body_html'], 'Please send the offer'), 'a message written inside the signature box is shown, not folded away: ' . $marker);
}
// Only style rules besides the signature box: nothing visible would be left, so nothing is folded.
$parts = ecw_split_signature_html('<style>p{margin:0}</style><div id="Signature"><p>The whole reply is here, inside the box.</p></div>');
check($parts['signature_html'] === '', 'style rules are not counted as message text');
// The signature box above a whole message (some programs put it at the top): not a signature.
$long = str_repeat('This is the actual message text that follows. ', 8);
$parts = ecw_split_signature_html('<div id="Signature"><p>Maria Pappa · Sales</p></div><p>' . $long . '</p>');
check($parts['signature_html'] === '' && str_contains($parts['body_html'], 'Maria Pappa'), 'a signature box with a whole message after it is left in place');
echo "$checks passed\n";
