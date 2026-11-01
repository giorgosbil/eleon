<?php declare(strict_types=1);
/**
 * Settings → Appearance, full customisation and saved looks: the new choices are checked
 * (a bad colour is dropped, a size out of range or an unknown font falls back), the defaults
 * keep the look of before, a saved look holds only the Appearance page's choices, at most 12,
 * the same name replaces a look, and a look can be deleted.
 *
 *   php tests/appearance_looks_test.php
 *
 * Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/appearance.php';

$pass = 0; $fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    group('the new choices are checked');
    $colors = ['color_page', 'color_panel', 'color_text', 'color_text2', 'color_border', 'color_topbar', 'color_topbar_text', 'color_link', 'sent_text', 'received_text', 'button_color'];
    $good = ecw_appearance(array_fill_keys($colors, '#AABBCC'));
    check(!array_diff_assoc(array_intersect_key($good, array_flip($colors)), array_fill_keys($colors, '#aabbcc')), 'a valid colour is kept, in small letters');
    $bad = ecw_appearance(array_fill_keys($colors, 'red;background:url(x)') + ['color_page' => '#12345', 'color_text' => ['#000000']]);
    check(!array_filter(array_intersect_key($bad, array_flip($colors))), 'a colour that is not #rrggbb becomes empty (the theme\'s)', json_encode(array_intersect_key($bad, array_flip($colors))));
    $x = ecw_appearance(['ui_scale' => '300', 'mail_line' => '999', 'ui_font' => 'Comic Sans; color:red', 'mail_family' => 'Wingdings',
        'button_shape' => 'star', 'button_style' => '<b>', 'bubble_edge' => 'glow']);
    check($x['ui_scale'] === 130 && ecw_appearance(['ui_scale' => '10'])['ui_scale'] === 90 && ecw_appearance(['ui_scale' => 'big'])['ui_scale'] === 100, 'size of everything stays between 90 and 130 %, 100 when not a number');
    check($x['mail_line'] === 0 && ecw_appearance(['mail_line' => '160'])['mail_line'] === 160 && ecw_appearance(['mail_line' => '50'])['mail_line'] === 0, 'line spacing 120–200 %, anything else "as written"');
    check($x['ui_font'] === '' && $x['mail_family'] === '', 'an unknown font falls back to the default');
    $f = ecw_appearance(['ui_font' => 'Georgia', 'mail_family' => 'Trebuchet MS']);
    check($f['ui_font'] === 'Georgia' && $f['mail_family'] === 'Trebuchet MS', 'a listed font is kept');
    check($x['button_shape'] === '' && $x['button_style'] === 'filled' && $x['bubble_edge'] === '', 'unknown button shape, style and message edge fall back');
    $b = ecw_appearance(['button_shape' => 'pill', 'button_style' => 'outline', 'bubble_edge' => 'shadow']);
    check($b['button_shape'] === 'pill' && $b['button_style'] === 'outline' && $b['bubble_edge'] === 'shadow', 'listed button shape, style and message edge are kept');

    group('untouched, it looks as before');
    $d = ecw_appearance([]);
    check(!array_filter(array_intersect_key($d, array_flip($colors))), 'every new colour is empty (the theme\'s)');
    check($d['ui_font'] === '' && $d['ui_scale'] === 100 && $d['mail_family'] === '' && $d['mail_line'] === 0, 'fonts the app\'s own, size 100 %, email text as written');
    check($d['button_shape'] === '' && $d['button_style'] === 'filled' && $d['bubble_edge'] === '', 'buttons and message edges as the app has them');
    check($d['theme'] === 'light' && $d['corners'] === 'rounded' && $d['mail_font'] === 14, 'the old defaults are unchanged');

    group('Appearance saves only its own choices');
    $stored = ecw_appearance(['theme' => 'dark', 'compose_font' => 'Georgia', 'compose_size' => 14, 'compose_unit' => 'pt', 'badge_mode' => 'dot', 'color_text' => '#111111']);
    $m = ecw_appearance_merge($stored, 'look', ['theme' => 'sand', 'color_panel' => '#223344', 'color_panel_custom' => '1', 'color_text' => '#999999', 'ui_font' => 'Arial', 'compose_font' => 'Arial', 'badge_mode' => 'off']);
    check($m['theme'] === 'sand' && $m['color_panel'] === '#223344' && $m['ui_font'] === 'Arial', 'its choices are saved');
    check($m['color_text'] === '', 'a colour without its "Own colour" box ticked is the theme\'s');
    check($m['compose_font'] === 'Georgia' && $m['badge_mode'] === 'dot', 'the other pages\' choices stay as they were');
    $w = ecw_appearance_merge($stored, 'writing', ['ui_font' => 'Arial', 'color_text' => '#999999', 'color_text_custom' => '1']);
    check($w['ui_font'] === '' && $w['color_text'] === '#111111', 'Email & writing does not touch the new Appearance choices');

    group('saved looks');
    $me = (static function () use ($pdo): int {
        $pdo->prepare('INSERT INTO `' . ecw_table('users') . '` (name, email, password_hash, preferences_json) VALUES (?, ?, ?, ?)')
            ->execute(['Maria', 'maria' . bin2hex(random_bytes(4)) . '@looks.test', 'x', json_encode(['thread_order' => 'desc', 'appearance' => ecw_appearance(['compose_font' => 'Georgia', 'compose_unit' => 'pt'])])]);
        return (int)$pdo->lastInsertId();
    })();
    $prefs = static function () use ($pdo, $me): array {
        $st = $pdo->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
        $st->execute([$me]);
        return json_decode((string)$st->fetchColumn(), true) ?: [];
    };
    ecw_appearance_looks_save($me, 'save', '  My   dark blue  ', ['theme' => 'dark', 'accent' => '#3366ff', 'accent_custom' => '1', 'color_panel' => '#101a2a', 'color_panel_custom' => '1',
        'compose_font' => 'Arial', 'badge_mode' => 'off', 'csrf' => 'x', 'look_name' => 'My dark blue', 'look_action' => 'save']);
    $p = $prefs();
    $saved = $p['appearance_looks'][0] ?? [];
    check(count($p['appearance_looks'] ?? []) === 1 && ($saved['name'] ?? '') === 'My dark blue', 'saved under its name, spaces tidied', json_encode($saved['name'] ?? null));
    $keys = array_keys($saved['look'] ?? []); $want = ECW_APPEARANCE_PAGES['look']; sort($keys); sort($want);
    check($keys === $want, 'the look holds exactly the Appearance page\'s choices', implode(',', $keys));
    check(!isset($saved['look']['compose_font']) && !isset($saved['look']['badge_mode']) && !isset($saved['look']['csrf']), 'nothing of the other pages or of the form itself');
    check(($saved['look']['theme'] ?? '') === 'dark' && ($saved['look']['accent'] ?? '') === '#3366ff' && ($saved['look']['color_panel'] ?? '') === '#101a2a', 'its choices are kept');
    check(($p['thread_order'] ?? '') === 'desc' && ($p['appearance']['compose_font'] ?? '') === 'Georgia', 'the other preferences stay');

    ecw_appearance_looks_save($me, 'save', 'MY DARK BLUE', ['theme' => 'graphite']);
    $p = $prefs();
    check(count($p['appearance_looks']) === 1 && $p['appearance_looks'][0]['name'] === 'MY DARK BLUE' && $p['appearance_looks'][0]['look']['theme'] === 'graphite' && $p['appearance_looks'][0]['look']['accent'] === '',
        'the same name (any capitals) replaces the look');

    for ($i = 2; $i <= ECW_APPEARANCE_LOOKS_MAX; $i++) ecw_appearance_looks_save($me, 'save', 'Look ' . $i, ['theme' => 'mist']);
    check(count($prefs()['appearance_looks']) === 12, 'twelve looks can be saved');
    $full = '';
    try { ecw_appearance_looks_save($me, 'save', 'One too many', []); } catch (LengthException $e) { $full = $e->getMessage(); }
    check($full === 'full' && count($prefs()['appearance_looks']) === 12, 'a thirteenth is refused');
    ecw_appearance_looks_save($me, 'save', 'Look 5', ['theme' => 'sand']);
    $byName = array_column($prefs()['appearance_looks'], 'look', 'name');
    check(count($byName) === 12 && $byName['Look 5']['theme'] === 'sand', 'with twelve, one can still be replaced by its name');
    $noname = '';
    try { ecw_appearance_looks_save($me, 'save', "  \t ", []); } catch (InvalidArgumentException $e) { $noname = $e->getMessage(); }
    check($noname === 'noname', 'a look needs a name');
    check(mb_strlen(ecw_appearance_look_name(str_repeat('ä', 60))) === 40, 'a name is cut at 40 characters');

    ecw_appearance_looks_save($me, 'delete', 'look 5');
    $names = array_column($prefs()['appearance_looks'], 'name');
    check(count($names) === 11 && !in_array('Look 5', $names, true) && in_array('Look 6', $names, true), 'delete removes that look only');
    ecw_appearance_looks_save($me, 'delete', 'Not there');
    check(count($prefs()['appearance_looks']) === 11, 'deleting a name that is not there changes nothing');

    group('stored looks are made safe on load');
    $clean = ecw_appearance_looks([['name' => 'A', 'look' => ['theme' => '<script>', 'color_text' => 'red', 'compose_font' => 'Arial']], 'junk', ['name' => '', 'look' => []], ['name' => 'a', 'look' => []]]);
    check(count($clean) === 1 && $clean[0]['look']['theme'] === 'light' && $clean[0]['look']['color_text'] === '' && !isset($clean[0]['look']['compose_font']), 'bad values, nameless and repeated looks are dropped');
    check(count(ecw_appearance_looks(array_merge(array_fill(0, 5, null), array_map(static fn($i) => ['name' => 'L' . $i, 'look' => []], range(0, 19))))) === 12, 'never more than twelve');
    foreach (ECW_APPEARANCE_STARTER_LOOKS as $name => $look) check(array_diff_key($look, array_flip(ECW_APPEARANCE_PAGES['look'])) === [] && ecw_appearance_look($look) === array_intersect_key(ecw_appearance($look), array_flip(ECW_APPEARANCE_PAGES['look'])), "starting point \"{$name}\" uses only Appearance choices");
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
