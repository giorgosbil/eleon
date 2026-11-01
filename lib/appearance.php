<?php declare(strict_types=1);
/**
 * Personal appearance (Settings → Appearance), stored in users.preferences_json
 * and applied by assets/appearance.js. Every value is checked here; anything
 * unknown falls back to the default, so a stored preference can never inject CSS.
 */
require_once __DIR__ . '/db.php';

const ECW_APPEARANCE_THEMES = ['light' => 'Light', 'dark' => 'Dark', 'mild' => 'Mild · soft colors', 'mist' => 'Mist · soft blue-grey', 'sand' => 'Sand · warm', 'graphite' => 'Graphite · dark grey'];
const ECW_APPEARANCE_BACKGROUNDS = ['plain' => 'Plain', 'dots' => 'Soft dots', 'lines' => 'Fine diagonal lines', 'doodles' => 'Doodles'];
/** Fonts for writing emails (default in Settings, picker in the composer) => the CSS sent, with fallbacks for recipients without the font. */
const ECW_COMPOSE_FONTS = [
    'Segoe UI' => "'Segoe UI',Helvetica,Arial,sans-serif",
    'Aptos' => 'Aptos,Calibri,Helvetica,Arial,sans-serif',
    'Calibri' => 'Calibri,Carlito,Arial,sans-serif',
    'Roboto' => 'Roboto,Arial,Helvetica,sans-serif',
    'Arial' => 'Arial,Helvetica,sans-serif',
    'Verdana' => 'Verdana,Geneva,sans-serif',
    'Tahoma' => 'Tahoma,Verdana,sans-serif',
    'Georgia' => 'Georgia,serif',
    'Times New Roman' => "'Times New Roman',Times,serif",
    'Courier New' => "'Courier New',Courier,monospace",
];
/**
 * Writing sizes are points, the unit Outlook and Word show: 11 here is 11 there.
 * They were pixels once (12 px showed as 9 in Outlook); a size saved then is
 * converted on load (ecw_appearance), see compose_unit.
 */
const ECW_COMPOSE_SIZES = [8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 36];   // pt: offered in the pickers
const ECW_COMPOSE_SIZE_DEFAULT = 11;
/** Any whole size in this range can be typed in Settings → Email & writing (pt). */
const ECW_COMPOSE_SIZE_MIN = 8;
const ECW_COMPOSE_SIZE_MAX = 36;
/** Fonts for the letters of the whole app and for reading emails (appearance.js adds the fallbacks). Empty = the app's own. */
const ECW_APPEARANCE_FONTS = ['Segoe UI', 'Aptos', 'Calibri', 'Roboto', 'Arial', 'Verdana', 'Tahoma', 'Trebuchet MS', 'Georgia', 'Times New Roman', 'Courier New'];
/** Colours with an "Own colour" box: empty = the theme's (ecw_appearance_merge empties an unticked one). */
const ECW_APPEARANCE_OWN_COLORS = ['accent', 'doodle_color', 'compose_color', 'color_page', 'color_panel', 'color_text', 'color_text2',
    'color_border', 'color_topbar', 'color_topbar_text', 'color_link', 'sent_text', 'received_text', 'button_color'];
const ECW_APPEARANCE_LOOKS_MAX = 12;
const ECW_APPEARANCE_LOOK_NAME_MAX = 40;
/** Ready-made starting points on Settings → Appearance; picking one only fills the form. */
const ECW_APPEARANCE_STARTER_LOOKS = [
    'Classic light' => [],
    'Night' => ['theme' => 'dark'],
    'Soft green' => ['theme' => 'mild', 'background' => 'dots', 'corners' => 'round', 'button_shape' => 'pill', 'bubble_edge' => 'shadow'],
    'Warm paper' => ['theme' => 'sand', 'mail_family' => 'Georgia', 'mail_line' => 160, 'button_shape' => 'rounded'],
    'High contrast' => ['theme' => 'light', 'canvas' => '#ffffff', 'sent' => '#e8efff', 'received' => '#ffffff', 'accent' => '#0033cc',
        'color_page' => '#ffffff', 'color_panel' => '#ffffff', 'color_text' => '#000000', 'color_text2' => '#1a1a1a', 'color_border' => '#000000',
        'color_link' => '#0033cc', 'color_topbar' => '#000000', 'color_topbar_text' => '#ffffff', 'bubble_edge' => 'border', 'ui_scale' => 110],
];

function ecw_appearance(array $input): array
{
    $hex = static fn($v): string => is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : '';
    $int = static fn($v, int $min, int $max, int $dflt): int => is_numeric($v) ? max($min, min($max, (int)$v)) : $dflt;
    $pick = static fn($v, array $allowed, string $dflt): string => is_string($v) && in_array($v, $allowed, true) ? $v : $dflt;
    $background = (string)($input['background'] ?? '');
    if ($background === 'doodles2') $background = 'doodles';   // the old second doodle set is gone
    $out = [
        'theme' => $pick($input['theme'] ?? '', array_keys(ECW_APPEARANCE_THEMES), 'light'),
        'background' => $pick($background, array_keys(ECW_APPEARANCE_BACKGROUNDS), 'plain'),
    ];
    foreach (['canvas', 'sent', 'received', 'accent', 'doodle_color', 'compose_color'] as $key) $out[$key] = $hex($input[$key] ?? '');
    $out['doodle_opacity'] = $int($input['doodle_opacity'] ?? null, 3, 30, 9);     // % strength of the doodles
    $out['doodle_size'] = $int($input['doodle_size'] ?? null, 60, 160, 100);        // % of the tile's size
    $out['mail_font'] = $int($input['mail_font'] ?? null, 12, 20, 14);              // px, email text
    $out['density'] = $pick($input['density'] ?? '', ['comfortable', 'compact'], 'comfortable');
    $out['corners'] = $pick($input['corners'] ?? '', ['square', 'rounded', 'round'], 'rounded');
    // The preview line (the first words of the text) in the email list; off: thinner rows, sender and subject.
    $out['list_preview'] = !in_array($input['list_preview'] ?? true, [false, '0', 0], true);
    // The installed app's taskbar icon (Settings → Notifications): the unread count, only a dot, or nothing (nav-badges.js).
    // Its colour is the system's own (Windows / macOS draw it): an app cannot choose one.
    $out['badge_mode'] = $pick($input['badge_mode'] ?? '', ['number', 'dot', 'off'], 'number');
    // Quiet hours (Settings -> Notifications, lib/notifications.php ecw_notify_quiet_now): no push or
    // desktop pop-up then, the bell still collects. Off by default; HH:MM in the person's time zone.
    $on = static fn($v): bool => in_array($v, [true, 1, '1', 'on'], true);
    $hm = static fn($v, string $dflt): string => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $dflt;
    $out['quiet'] = $on($input['quiet'] ?? false);
    $out['quiet_from'] = $hm($input['quiet_from'] ?? '', '19:00');
    $out['quiet_to'] = $hm($input['quiet_to'] ?? '', '08:00');
    $out['quiet_weekend'] = $on($input['quiet_weekend'] ?? false);
    $out['compose_font'] = $pick($input['compose_font'] ?? '', array_keys(ECW_COMPOSE_FONTS), 'Segoe UI');
    $size = $input['compose_size'] ?? null;
    if (is_numeric($size) && ($input['compose_unit'] ?? '') !== 'pt') $size = round((float)$size * 0.75);   // saved in px before
    $out['compose_size'] = $int($size, ECW_COMPOSE_SIZE_MIN, ECW_COMPOSE_SIZE_MAX, ECW_COMPOSE_SIZE_DEFAULT);   // pt, a listed size or one typed
    $out['compose_unit'] = 'pt';
    // Where Reply and New email open: a floating window, or in the reading pane (app.js placeComposer).
    $out['reply_mode'] = $pick($input['reply_mode'] ?? '', ['window', 'inline'], 'window');
    // How a colour rule (Settings → Rules) marks a conversation in the list: the whole row
    // tinted with a bar at the side, or only its sender and subject written in the colour.
    $out['rule_style'] = $pick($input['rule_style'] ?? '', ['row', 'text'], 'row');
    // Full customisation (Settings → Appearance). Each default keeps the look of before: '' = the theme's own.
    foreach (ECW_APPEARANCE_OWN_COLORS as $key) $out[$key] ??= $hex($input[$key] ?? '');
    $out['ui_font'] = $pick($input['ui_font'] ?? '', ECW_APPEARANCE_FONTS, '');
    $out['ui_scale'] = $int($input['ui_scale'] ?? null, 90, 130, 100);              // % size of the whole app
    $out['mail_family'] = $pick($input['mail_family'] ?? '', ECW_APPEARANCE_FONTS, '');
    $line = $input['mail_line'] ?? 0;                                               // % line spacing of email text, 0 = as written
    $out['mail_line'] = is_numeric($line) && (int)$line >= 120 && (int)$line <= 200 ? (int)$line : 0;
    $out['button_shape'] = $pick($input['button_shape'] ?? '', ['square', 'rounded', 'pill'], '');
    $out['button_style'] = $pick($input['button_style'] ?? '', ['filled', 'outline'], 'filled');
    $out['bubble_edge'] = $pick($input['bubble_edge'] ?? '', ['flat', 'border', 'shadow'], '');
    return $out;
}

/**
 * The choices of lib/appearance.php are on three settings pages. Each page saves only
 * its own; the others' stay as they were (ecw_appearance_save).
 */
const ECW_APPEARANCE_PAGES = [
    'look' => ['theme', 'background', 'canvas', 'sent', 'received', 'accent', 'doodle_color', 'doodle_opacity', 'doodle_size',
               'mail_font', 'density', 'corners', 'list_preview', 'rule_style',
               'color_page', 'color_panel', 'color_text', 'color_text2', 'color_border', 'color_topbar', 'color_topbar_text', 'color_link',
               'sent_text', 'received_text', 'button_color', 'ui_font', 'ui_scale', 'mail_family', 'mail_line',
               'button_shape', 'button_style', 'bubble_edge'],                                    // Settings → Appearance
    'writing' => ['compose_font', 'compose_size', 'compose_unit', 'compose_color', 'reply_mode'],  // Settings → Email & writing
    'alerts' => ['badge_mode', 'quiet', 'quiet_from', 'quiet_to', 'quiet_weekend'],   // Settings → Notifications
];

/** The appearance after one settings page (ECW_APPEARANCE_PAGES) is saved: its choices from the form, every other one as it was. */
function ecw_appearance_merge(array $stored, string $page, array $posted): array
{
    $fields = ECW_APPEARANCE_PAGES[$page];
    // A colour counts only with its "Own" box ticked (the picker itself is always sent).
    foreach (ECW_APPEARANCE_OWN_COLORS as $k) if (in_array($k, $fields, true) && empty($posted[$k . '_custom'])) $posted[$k] = '';
    return ecw_appearance(array_intersect_key($posted, array_flip($fields)) + ecw_appearance($stored));
}

/** Changes a person's preferences_json under a row lock; $change gets the decoded preferences and returns them changed. */
function ecw_appearance_update_prefs(int $userId, callable $change): void
{
    $pdo = ecw_db();
    $own = !$pdo->inTransaction();   // inside a caller's transaction (tests): join it
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ? FOR UPDATE');
        $st->execute([$userId]);
        $prefs = $change(json_decode((string)$st->fetchColumn(), true) ?: []);
        $pdo->prepare('UPDATE `' . ecw_table('users') . '` SET preferences_json = ? WHERE id = ?')->execute([json_encode($prefs), $userId]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own) $pdo->rollBack(); throw $e; }
}

/** Saves the choices of one settings page from a posted form, keeping every other one (ecw_appearance_merge). */
function ecw_appearance_save(int $userId, string $page, array $posted): void
{
    ecw_appearance_update_prefs($userId, static function (array $prefs) use ($page, $posted): array {
        $prefs['appearance'] = ecw_appearance_merge((array)($prefs['appearance'] ?? []), $page, $posted);
        return $prefs;
    });
}

// ------------------------------------------------------------ saved looks
// A person's own named looks (Settings → Appearance), in preferences_json['appearance_looks']:
// [['name' => 'My dark blue', 'look' => the Settings → Appearance choices only], …], at most 12.

/** A look's name: one line, spaces tidied, at most 40 characters. */
function ecw_appearance_look_name($name): string
{
    if (!is_string($name)) return '';
    $name = trim((string)preg_replace('/[\p{C}\s]+/u', ' ', $name));
    return mb_substr($name, 0, ECW_APPEARANCE_LOOK_NAME_MAX);
}

/** Only the Settings → Appearance choices of $choices, each checked (ecw_appearance). */
function ecw_appearance_look(array $choices): array
{
    return array_intersect_key(ecw_appearance($choices), array_flip(ECW_APPEARANCE_PAGES['look']));
}

/** Stored looks made safe: valid names, no repeats, every choice checked, at most 12. */
function ecw_appearance_looks(array $stored): array
{
    $out = [];
    foreach ($stored as $item) {
        if (!is_array($item) || count($out) >= ECW_APPEARANCE_LOOKS_MAX) continue;
        $name = ecw_appearance_look_name($item['name'] ?? '');
        if ($name === '' || isset($out[mb_strtolower($name)])) continue;
        $out[mb_strtolower($name)] = ['name' => $name, 'look' => ecw_appearance_look((array)($item['look'] ?? []))];
    }
    return array_values($out);
}

/** $looks with $look kept under $name: the same name (any capitals) is replaced, a new one added if there is room. */
function ecw_appearance_looks_put(array $looks, string $name, array $look): array
{
    $name = ecw_appearance_look_name($name);
    if ($name === '') throw new InvalidArgumentException('noname');
    $looks = ecw_appearance_looks($looks);
    foreach ($looks as $i => $item) {
        if (mb_strtolower($item['name']) === mb_strtolower($name)) { $looks[$i] = ['name' => $name, 'look' => ecw_appearance_look($look)]; return $looks; }
    }
    if (count($looks) >= ECW_APPEARANCE_LOOKS_MAX) throw new LengthException('full');
    $looks[] = ['name' => $name, 'look' => ecw_appearance_look($look)];
    return $looks;
}

function ecw_appearance_looks_remove(array $looks, string $name): array
{
    $name = mb_strtolower(ecw_appearance_look_name($name));
    return array_values(array_filter(ecw_appearance_looks($looks), static fn($item) => mb_strtolower($item['name']) !== $name));
}

function ecw_appearance_looks_load(int $userId): array
{
    $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $prefs = json_decode((string)$st->fetchColumn(), true) ?: [];
    return ecw_appearance_looks((array)($prefs['appearance_looks'] ?? []));
}

/**
 * Settings → Appearance "Save as a look" ($action 'save': the posted form under $name) or the
 * bin on a look ('delete'). Throws InvalidArgumentException('noname') / LengthException('full').
 */
function ecw_appearance_looks_save(int $userId, string $action, string $name, array $posted = []): void
{
    $look = ecw_appearance_look(ecw_appearance_merge([], 'look', $posted));
    ecw_appearance_update_prefs($userId, static function (array $prefs) use ($action, $name, $look): array {
        $looks = (array)($prefs['appearance_looks'] ?? []);
        $prefs['appearance_looks'] = $action === 'delete' ? ecw_appearance_looks_remove($looks, $name) : ecw_appearance_looks_put($looks, $name, $look);
        return $prefs;
    });
}

/** The CSS a person's new emails are written and sent in (font, size, line height, and their text colour if they chose one). */
function ecw_compose_font_css(?int $userId): string
{
    $a = $userId ? ecw_load_appearance($userId) : ecw_appearance([]);
    return 'font-family:' . ECW_COMPOSE_FONTS[$a['compose_font']] . ';font-size:' . $a['compose_size'] . 'pt;line-height:1.4'
        . ($a['compose_color'] !== '' ? ';color:' . $a['compose_color'] : '');
}

/**
 * The composer's font picker writes a bare name (font-family:Aptos). On the way
 * out each known name gets its fallbacks, so a recipient without that font sees
 * a similar one rather than their client's default (often Times New Roman).
 */
function ecw_compose_font_fallbacks(string $html): string
{
    return (string)preg_replace_callback(
        '~font-family:\s*([^;"]+)~i',
        static function (array $m): string {
            $name = trim(str_replace(['&quot;', '&#39;', "'", '"'], '', $m[1]));
            return isset(ECW_COMPOSE_FONTS[$name]) ? 'font-family:' . ECW_COMPOSE_FONTS[$name] : $m[0];
        },
        $html
    );
}

/**
 * Fonts classic Outlook (Word draws its emails) has on any Windows PC. Word uses only the
 * first font of a font-family list: when that one is missing (Roboto, or a web font from a
 * pasted signature) it shows Times New Roman and never looks at the fallbacks.
 * Aptos is Outlook's own default, so it is there.
 */
const ECW_OUTLOOK_FONTS = ['arial', 'arial black', 'aptos', 'calibri', 'calibri light', 'cambria', 'candara', 'century gothic',
    'comic sans ms', 'consolas', 'constantia', 'corbel', 'courier new', 'franklin gothic medium', 'garamond', 'georgia', 'impact',
    'lucida console', 'lucida sans unicode', 'palatino linotype', 'segoe ui', 'segoe ui semibold', 'segoe ui light', 'tahoma',
    'times new roman', 'trebuchet ms', 'verdana', 'helvetica', 'book antiqua', 'bookman old style', 'symbol', 'wingdings'];

/**
 * For classic Outlook only (Word draws its emails and uses only the first font of a list):
 * each first font that is not on every Windows PC (Roboto, a web font from a pasted signature)
 * gets an @font-face with mso-font-alt, in a style block in [if mso] that Outlook on Windows
 * alone reads. A PC that has the font shows it; one without it shows the first Windows font of
 * the list (Roboto,Arial,… → Arial), or Arial / Georgia / Courier New, not Times New Roman.
 * (Replacing the font outright made the text Arial even where Roboto is installed.)
 */
function ecw_outlook_font_fallbacks(string $html): string
{
    $used = [];
    preg_match_all('~font-family:\s*((?:&[a-z0-9#]+;|[^;"])+)~i', $html, $all);   // &quot; is part of a name
    foreach ($all[1] as $list) {
        $raw = array_values(array_filter(array_map(static fn($f) => trim(str_replace(['&quot;', '&#39;', '&#039;', "'", '"'], '', $f)), explode(',', $list))));
        $fonts = array_map('strtolower', $raw);
        if ($fonts === [] || in_array($fonts[0], ECW_OUTLOOK_FONTS, true) || in_array($fonts[0], ['sans-serif', 'serif', 'monospace', 'inherit'], true)) continue;
        if (!preg_match('~^[\p{L}\p{N} ._-]+$~u', $raw[0])) continue;
        $alt = null;
        foreach ($fonts as $f) if (in_array($f, ECW_OUTLOOK_FONTS, true)) { $alt = $f; break; }
        $alt ??= in_array('monospace', $fonts, true) ? 'courier new' : (in_array('serif', $fonts, true) ? 'georgia' : 'arial');
        $used[$fonts[0]] ??= [$raw[0], ucwords($alt)];
    }
    if ($used === []) return $html;
    $css = '';
    foreach ($used as [$name, $alt]) $css .= '@font-face{font-family:"' . $name . '";mso-font-alt:"' . $alt . '"}';
    return '<!--[if mso]><style type="text/css">' . $css . '</style><![endif]-->' . $html;
}

/**
 * Classic Outlook does not pass the email's font into table cells: a signature laid out in a
 * table showed in Times New Roman. Each cell without a font of its own gets the writer's font
 * and size ($font: "font-family:…;font-size:…"); text with its own font inside keeps it.
 */
function ecw_outgoing_cell_fonts(string $html, string $font): string
{
    if ($font === '' || stripos($html, '<t') === false) return $html;
    return (string)preg_replace_callback(
        '~<(td|th)\b([^>]*)>~i',
        static function (array $m) use ($font): string {
            $attrs = $m[2];
            if (!preg_match('~\sstyle="([^"]*)"~i', $attrs, $s)) return '<' . $m[1] . $attrs . ' style="' . $font . '">';
            if (stripos($s[1], 'font-family') !== false) return $m[0];
            $style = trim(rtrim($s[1], '; ')) === '' ? $font : rtrim($s[1], '; ') . ';' . $font;
            return '<' . $m[1] . preg_replace('~\sstyle="[^"]*"~i', ' style="' . $style . '"', $attrs, 1) . '>';
        },
        $html
    );
}

function ecw_load_appearance(int $userId): array
{
    $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $prefs = json_decode((string)$st->fetchColumn(), true) ?: [];
    return ecw_appearance((array)($prefs['appearance'] ?? []));
}
