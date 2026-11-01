<?php declare(strict_types=1);
// Settings → Signatures: what a pasted signature keeps, and the editor page has what signatures.js uses.
require_once dirname(__DIR__) . '/lib/sanitize.php';
$checks = 0;
function check(bool $ok, string $what): void { global $checks; if (!$ok) throw new RuntimeException('FAIL ' . $what); $checks++; echo "PASS $what\n"; }

$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
// What signatures.js sends after cleaning an Outlook copy: the logo already swapped for a data: picture.
$pasted = '<table class="MsoNormalTable" border="0" cellspacing="0" cellpadding="0" style="border-collapse:collapse"><tr>'
    . '<td width="120" valign="top" style="width:90pt;padding:0cm 5.4pt"><p class="MsoNormal"><img width="100" src="' . $png . '" alt="logo"></p></td>'
    . '<td><p class="MsoNormal"><b><span style="font-size:10.0pt;font-family:&quot;Arial&quot;,sans-serif;color:#1F3864">George Bilalis</span></b></p>'
    . '<p class="MsoNormal"><a href="tel:+302100000000"><span style="font-size:9.0pt">+30 210 0000000</span></a></p>'
    . '<p class="MsoNormal"><a href="javascript:alert(1)">bad</a> <a href="https://inquality.gr">www.inquality.gr</a></p></td></tr></table>';
$out = ecw_sanitize_signature_html($pasted);
check(str_contains($out, '<table') && str_contains($out, '<td width="120"'), 'a pasted signature keeps its table layout');
check(str_contains($out, 'src="' . $png . '"') && str_contains($out, 'width="100"'), 'a pasted or added picture is kept with its width');
check(str_contains($out, 'font-size:10pt') && str_contains($out, 'color:#1F3864'), 'text keeps its size and colour');
check(str_contains($out, 'href="tel:+302100000000"'), 'a phone link (tel:) is kept');
check(!str_contains($out, 'javascript') && str_contains($out, 'href="https://inquality.gr"'), 'script links are dropped, web links kept');
check(!str_contains($out, 'MsoNormal') && !str_contains($out, '<p'), 'Office classes go, paragraphs become tight lines');

$page = (string)file_get_contents(dirname(__DIR__) . '/web/settings/signatures.php');
$js = (string)file_get_contents(dirname(__DIR__) . '/web/assets/signatures.js');
preg_match_all("~\\$\('([A-Za-z]+)'\)~", $js, $ids);
$missing = array_values(array_filter(array_unique($ids[1]), static fn($id) => !str_contains($page, 'id="' . $id . '"')));
check($missing === [], 'every element signatures.js uses is on the page' . ($missing ? ': missing ' . implode(', ', $missing) : ''));
echo "$checks passed\n";
