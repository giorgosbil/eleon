<?php declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/appearance.php';
$bad = ecw_appearance(['theme'=>'<script>', 'background'=>'url(https://invalid)', 'sent'=>'red; color:white', 'received'=>['x']]);
if ($bad['theme'] !== 'light' || $bad['background'] !== 'plain' || $bad['sent'] !== '' || $bad['received'] !== '') throw new RuntimeException('Unsafe appearance accepted');
$good = ecw_appearance(['theme'=>'dark','background'=>'dots','canvas'=>'#ABCDEF','sent'=>'#123456']);
if ($good['theme'] !== 'dark' || $good['canvas'] !== '#abcdef' || $good['sent'] !== '#123456') throw new RuntimeException('Valid appearance lost');
echo "PASS appearance validation and color normalization\n";

// Customisation: doodles, accent, text size, spacing, corners.
$c = ecw_appearance(['theme' => 'graphite', 'background' => 'doodles', 'accent' => '#FF8800', 'doodle_color' => '#112233',
    'doodle_opacity' => '14', 'doodle_size' => '130', 'mail_font' => '17', 'density' => 'compact', 'corners' => 'round']);
if ($c['theme'] !== 'graphite' || $c['background'] !== 'doodles' || $c['accent'] !== '#ff8800' || $c['doodle_color'] !== '#112233'
    || $c['doodle_opacity'] !== 14 || $c['doodle_size'] !== 130 || $c['mail_font'] !== 17 || $c['density'] !== 'compact' || $c['corners'] !== 'round') {
    throw new RuntimeException('Customisation lost: ' . json_encode($c));
}
$x = ecw_appearance(['doodle_opacity' => '999', 'doodle_size' => '-5', 'mail_font' => 'huge', 'density' => 'x;', 'corners' => '<b>', 'accent' => 'red', 'background' => 'doodles2']);
if ($x['doodle_opacity'] !== 30 || $x['doodle_size'] !== 60 || $x['mail_font'] !== 14 || $x['density'] !== 'comfortable' || $x['corners'] !== 'rounded' || $x['accent'] !== '' || $x['background'] !== 'doodles') {
    throw new RuntimeException('Out-of-range customisation accepted: ' . json_encode($x));
}
echo "PASS appearance customisation is kept in range, and the old second doodle set maps to the new doodles\n";

// Writing emails: the font comes from the list only; the size is any whole pt from 8 to 36
// (listed or typed), points as Outlook shows them; the sent mail carries fallbacks.
$pt = static fn(array $in): array => ecw_appearance($in + ['compose_unit' => 'pt']);
$f = $pt(['compose_font' => 'Georgia', 'compose_size' => '18']);
$d = $pt(['compose_font' => 'Comic Sans; color:red', 'compose_size' => '17px; color:red']);
if ($f['compose_font'] !== 'Georgia' || $f['compose_size'] !== 18 || $d['compose_font'] !== 'Segoe UI' || $d['compose_size'] !== 11) {
    throw new RuntimeException('Compose font not validated: ' . json_encode([$f, $d]));
}
$sizes = array_map(static fn($v) => $pt(['compose_size' => $v])['compose_size'], ['10', '17', '4', '99', '']);
if ($sizes !== [10, 17, 8, 36, 11]) throw new RuntimeException('Compose size not kept in range: ' . json_encode($sizes));
echo "PASS writing size: smaller than 12, a typed size, kept between 8 and 36 pt
";
// A size saved in px (before points) reads as the same size in pt: 12px is 9pt, 14px 11pt.
$old = array_map(static fn($v) => ecw_appearance(['compose_size' => $v])['compose_size'], ['12', '14', '16', '11']);
if ($old !== [9, 11, 12, 8]) throw new RuntimeException('Old px size not converted: ' . json_encode($old));
$again = ecw_appearance(ecw_appearance(['compose_size' => '16']));
if ($again['compose_size'] !== 12) throw new RuntimeException('A converted size changed again on the next load: ' . json_encode($again));
echo "PASS writing size saved in px becomes the same size in pt, once
";
if (ecw_compose_font_css(null) !== "font-family:'Segoe UI',Helvetica,Arial,sans-serif;font-size:11pt;line-height:1.4") throw new RuntimeException('Default compose font changed');
$out = ecw_compose_font_fallbacks('<span style="font-family:\'Times New Roman\'">a</span><span style="font-family:Aptos;color:red">b</span><span style="font-family:Wingdings">c</span><span style="font-family:Roboto">d</span>');
if (!str_contains($out, 'font-family:Roboto,Arial,Helvetica,sans-serif')) throw new RuntimeException('Roboto has no fallbacks: ' . $out);
if (!str_contains($out, "font-family:'Times New Roman',Times,serif\"") || !str_contains($out, 'font-family:Aptos,Calibri,Helvetica,Arial,sans-serif;color:red') || !str_contains($out, 'font-family:Wingdings')) {
    throw new RuntimeException('Font fallbacks wrong: ' . $out);
}
$cc = ecw_appearance(['compose_color' => '#1A2B3C']);
$bad = ecw_appearance(['compose_color' => 'red;background:url(x)']);
if ($cc['compose_color'] !== '#1a2b3c' || $bad['compose_color'] !== '') throw new RuntimeException('Compose colour not validated');
echo "PASS compose font, size and text colour are validated, and picked fonts get fallbacks on the way out\n";
if (ecw_appearance([])['reply_mode'] !== 'window' || ecw_appearance(['reply_mode' => 'inline'])['reply_mode'] !== 'inline' || ecw_appearance(['reply_mode' => 'x'])['reply_mode'] !== 'window') throw new RuntimeException('Reply mode not validated');
echo "PASS where replies open: a window by default, \"in the email\" when chosen, nothing else\n";
if (ecw_appearance([])['rule_style'] !== 'row' || ecw_appearance(['rule_style' => 'text'])['rule_style'] !== 'text' || ecw_appearance(['rule_style' => 'x'])['rule_style'] !== 'row') throw new RuntimeException('Rule style not validated');
echo "PASS colour rules: the whole row by default, the text only when chosen, nothing else\n";
if (ecw_appearance([])['badge_mode'] !== 'number' || ecw_appearance(['badge_mode' => 'dot'])['badge_mode'] !== 'dot' || ecw_appearance(['badge_mode' => 'red'])['badge_mode'] !== 'number') throw new RuntimeException('Badge mode not validated');
echo "PASS the taskbar badge: the number by default, a dot or nothing when chosen\n";
// Three settings pages each save their own choices: the others stay as they were.
$stored = ecw_appearance(['theme' => 'dark', 'accent' => '#112233', 'compose_font' => 'Georgia', 'compose_size' => 14, 'compose_unit' => 'pt', 'compose_color' => '#445566', 'reply_mode' => 'inline', 'badge_mode' => 'dot']);
$w = ecw_appearance_merge($stored, 'writing', ['compose_font' => 'Arial', 'compose_size' => '12', 'compose_unit' => 'pt', 'reply_mode' => 'window', 'theme' => 'light']);
if ($w['theme'] !== 'dark' || $w['accent'] !== '#112233' || $w['badge_mode'] !== 'dot' || $w['compose_font'] !== 'Arial' || $w['compose_size'] !== 12 || $w['compose_color'] !== '' || $w['reply_mode'] !== 'window') {
    throw new RuntimeException('Email & writing changed other choices: ' . json_encode($w));
}
$l = ecw_appearance_merge($stored, 'look', ['theme' => 'sand', 'accent_custom' => '1', 'accent' => '#abcdef', 'list_preview' => '0']);
if ($l['theme'] !== 'sand' || $l['accent'] !== '#abcdef' || $l['list_preview'] !== false || $l['compose_font'] !== 'Georgia' || $l['compose_size'] !== 14 || $l['compose_color'] !== '#445566' || $l['reply_mode'] !== 'inline' || $l['badge_mode'] !== 'dot') {
    throw new RuntimeException('Appearance changed other choices: ' . json_encode($l));
}
$n = ecw_appearance_merge($stored, 'alerts', ['badge_mode' => 'off']);
if ($n['badge_mode'] !== 'off' || $n['compose_size'] !== 14 || $n['theme'] !== 'dark' || $n['accent'] !== '#112233') throw new RuntimeException('Notifications changed other choices: ' . json_encode($n));
echo "PASS Appearance, Email & writing and Notifications each save only their own choices\n";
// Classic Outlook: a first font not on every Windows PC keeps its name (shown where installed)
// and gets a Windows font for the PCs without it, in [if mso].
$in = '<div style="font-family:Aptos,sans-serif"><span style="font-family:Roboto,Arial,Helvetica,sans-serif">a</span>'
    . '<span class="x" style="color:red;font-family:&quot;Lato&quot;,serif">b</span><span style="font-family:Comfortaa">c</span><span style="font-family:Roboto">d</span></div>';
$o = ecw_outlook_font_fallbacks($in);
if (!str_starts_with($o, '<!--[if mso]><style type="text/css">') || !str_ends_with($o, $in)
    || !str_contains($o, '@font-face{font-family:"Roboto";mso-font-alt:"Arial"}') || !str_contains($o, '@font-face{font-family:"Lato";mso-font-alt:"Georgia"}')
    || !str_contains($o, '@font-face{font-family:"Comfortaa";mso-font-alt:"Arial"}') || substr_count($o, '"Roboto"') !== 1 || str_contains($o, 'ecw-mso')) {
    throw new RuntimeException('Outlook font fallback wrong: ' . $o);
}
if (ecw_outlook_font_fallbacks('<p style="font-family:Calibri">x</p>') !== '<p style="font-family:Calibri">x</p>') throw new RuntimeException('Outlook fallback added for a Windows font');
echo "PASS classic Outlook shows Roboto where installed, a Windows font where not (no Times New Roman)\n";
// A signature in a table: classic Outlook does not pass the font into cells, so each cell gets it.
$c = ecw_outgoing_cell_fonts('<table><tr><td>a</td><td style="width:200px">b</td><td style="font-family:Georgia">c</td><th valign="top">d</th></tr></table>', 'font-family:Roboto,Arial;font-size:11pt');
if (!str_contains($c, '<td style="font-family:Roboto,Arial;font-size:11pt">a') || !str_contains($c, '<td style="width:200px;font-family:Roboto,Arial;font-size:11pt">b')
    || !str_contains($c, '<td style="font-family:Georgia">c') || !str_contains($c, '<th valign="top" style="font-family:Roboto,Arial;font-size:11pt">d')) {
    throw new RuntimeException('Cell fonts wrong: ' . $c);
}
require_once dirname(__DIR__) . '/lib/compose.php';
$out = ecw_outgoing_paragraphs('<p>Hi</p><table><tr><td>Georgios</td></tr></table>');
if (!preg_match('~<td style="font-family:[^"]*font-size:\d+pt">Georgios~', $out)) throw new RuntimeException('Outgoing signature cell has no font: ' . $out);
echo "PASS a signature laid out in a table carries the writer's font in every cell (no Times New Roman)\n";
