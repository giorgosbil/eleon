<?php declare(strict_types=1);
/**
 * Settings → Appearance: how it looks. Theme and saved looks, colours, fonts and text size,
 * buttons, messages, the background behind the emails, spacing and the email list. The font
 * emails are written in and where replies open are in Email & writing (email.php); the
 * taskbar bubble in Notifications.
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/appearance.php';
$context = ecw_require_login();
$userId = (int)$context['user']['id'];
$base = ecw_web_base();
$navActive = 'settings';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Invalid session token. Reload and try again.'); }
    $lookAction = (string)($_POST['look_action'] ?? '');
    $lookName = (string)($_POST['look_name'] ?? '');
    if ($lookAction === 'delete') {
        ecw_appearance_looks_save($userId, 'delete', $lookName);
        header('Location: appearance.php?look_deleted=1'); exit;
    }
    if ($lookAction === 'save') {
        // Saved as a look and in use straight away, so the page shows what was just saved.
        try { ecw_appearance_looks_save($userId, 'save', $lookName, $_POST); }
        catch (InvalidArgumentException | LengthException $e) { header('Location: appearance.php?look_error=' . urlencode($e->getMessage())); exit; }
        ecw_appearance_save($userId, 'look', $_POST);
        header('Location: appearance.php?look_saved=' . urlencode(ecw_appearance_look_name($lookName))); exit;
    }
    ecw_appearance_save($userId, 'look', $_POST);
    header('Location: appearance.php?saved=1'); exit;
}
$a = ecw_load_appearance($userId);
$looks = ecw_appearance_looks_load($userId);
$defaults = ecw_appearance_look([]);
// Each theme's colours: [behind the emails, sent, received, panels, page, accent]. The pickers start from these.
$palettes = ['light' => ['#f7f8fa', '#edf4ff', '#ffffff', '#ffffff', '#f7f8fa', '#0057ff'], 'dark' => ['#151c27', '#243b55', '#25303e', '#1d2633', '#151c27', '#8ab7ff'],
    'mild' => ['#eeeae2', '#e1e9dc', '#fffdf7', '#fffdf7', '#eeeae2', '#526f46'], 'mist' => ['#e9eef4', '#dde7f1', '#fbfcfe', '#fbfcfe', '#e9eef4', '#4d6f94'],
    'sand' => ['#f3eadb', '#f6e6d2', '#fffaf2', '#fffaf2', '#f3eadb', '#a0612b'], 'graphite' => ['#1a1b1d', '#2f3a33', '#2a2b2f', '#232427', '#1a1b1d', '#9fc2a8']];
$p = $palettes[$a['theme']];
$range = static fn(string $name, int $min, int $max, int $step, int $value, string $unit, string $label) =>
    '<label class="ap-range">' . $label . ' <output data-for="' . $name . '" data-unit="' . $unit . '"></output>'
    . '<input type="range" name="' . $name . '" min="' . $min . '" max="' . $max . '" step="' . $step . '" value="' . $value . '"></label>';
// A colour with an "Own colour" box: unticked, the theme's colour is used (appearance.js shows it in the picker).
$own = static fn(string $key, string $label, string $hint, string $start) =>
    '<div class="ap-optional-color"><label class="checkbox-label"><input type="checkbox" name="' . $key . '_custom" value="1" ' . ($a[$key] !== '' ? 'checked' : '') . '> '
    . $h($label) . ($hint !== '' ? ' <span class="muted small">(' . $h($hint) . ')</span>' : '') . '</label>'
    . '<input type="color" name="' . $key . '" value="' . $h($a[$key] ?: $start) . '" aria-label="' . $h($label) . '"></div>';
$select = static function (string $name, array $options) use ($a, $h): string {
    $html = '<select name="' . $name . '" class="ot-select">';
    foreach ($options as $v => $l) $html .= '<option value="' . $h($v) . '"' . ((string)$a[$name] === (string)$v ? ' selected' : '') . '>' . $h($l) . '</option>';
    return $html . '</select>';
};
$fonts = static function (string $name, string $default) use ($a, $h): string {
    $html = '<select name="' . $name . '" class="ot-select"><option value="">' . $h($default) . '</option>';
    foreach (ECW_APPEARANCE_FONTS as $f) $html .= '<option value="' . $h($f) . '" style="font-family:\'' . $h($f) . '\'"' . ($a[$name] === $f ? ' selected' : '') . '>' . $h($f) . '</option>';
    return $html . '</select>';
};
// A group opens by itself when something in it is not the default.
$changed = static fn(array $keys): bool => (bool)array_filter($keys, static fn($k) => $a[$k] !== $defaults[$k]);
$fold = static fn(string $icon, string $title, string $hint, array $keys): string =>
    '<details class="st-fold ap-fold"' . ($changed($keys) ? ' open' : '') . '><summary><span class="st-fold-title"><i class="fa ' . $icon . '" aria-hidden="true"></i> '
    . $h($title) . '</span><span class="st-fold-hint">' . $h($hint) . '</span></summary><div class="ap-fold-body">';
// A small picture of a look for its card.
$swatch = static function (array $look) use ($palettes, $h): string {
    $t = $palettes[$look['theme']] ?? $palettes['light'];
    $vars = ['page' => $look['color_page'] ?: $t[4], 'panel' => $look['color_panel'] ?: $t[3], 'canvas' => $look['canvas'] ?: $t[0], 'sent' => $look['sent'] ?: $t[1],
        'received' => $look['received'] ?: $t[2], 'accent' => $look['button_color'] ?: ($look['accent'] ?: $t[5]), 'bar' => $look['color_topbar'] ?: ($look['color_panel'] ?: $t[3])];
    $style = '';
    foreach ($vars as $k => $v) $style .= '--s-' . $k . ':' . $v . ';';
    return '<span class="ap-look-swatch" style="' . $h($style) . '" aria-hidden="true"><i class="s-bar"></i><i class="s-canvas"><i class="s-recv"></i><i class="s-sent"></i></i><i class="s-btn"></i></span>';
};
$lookJson = static fn(array $look): string => $h(json_encode($look, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
$lookErrors = ['noname' => 'Give the look a name first.', 'full' => 'You have ' . ECW_APPEARANCE_LOOKS_MAX . ' saved looks, the most there can be. Delete one, or save under the name of one you have to replace it.'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Appearance — Case Workspace</title><link rel="stylesheet" href="<?= $h($base) ?>assets/app.css"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide"><h1>Appearance</h1>
<p class="muted">How Mail and Cases look: colours, letters, buttons, messages and more. Changes show straight away in the preview; Save keeps them on your account. The font you write in and where replies open are in <a href="email.php">Email &amp; writing</a>.</p>
<?php if (isset($_GET['saved'])): ?><div class="flash ok">Appearance saved.</div><?php endif; ?>
<?php if (isset($_GET['look_saved'])): ?><div class="flash ok">Saved as “<?= $h($_GET['look_saved']) ?>” and in use now.</div><?php endif; ?>
<?php if (isset($_GET['look_deleted'])): ?><div class="flash ok">Look deleted.</div><?php endif; ?>
<?php if (isset($lookErrors[$_GET['look_error'] ?? ''])): ?><div class="flash err"><?= $h($lookErrors[$_GET['look_error']]) ?></div><?php endif; ?>
<form method="post" id="lookDeleteForm"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>"><input type="hidden" name="look_action" value="delete"></form>
<form method="post" id="appearanceForm" data-defaults="<?= $lookJson($defaults) ?>" data-looks-max="<?= ECW_APPEARANCE_LOOKS_MAX ?>"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>">
<input type="hidden" name="look_action" value="">
<div class="appearance-layout">
  <div class="appearance-fields">
    <fieldset class="ap-group"><legend>Theme and saved looks</legend>
      <label>Theme<?= $select('theme', ECW_APPEARANCE_THEMES) ?></label>
      <?= $own('accent', 'Own accent colour', 'buttons, links, selection', '#3e6b4a') ?>
      <div class="ap-looks-block">
        <span class="ap-looks-title">Starting points <span class="muted small">(fills in the choices below; Save keeps them)</span></span>
        <div class="ap-looks">
          <?php foreach (ECW_APPEARANCE_STARTER_LOOKS as $name => $look): $look = ecw_appearance_look($look); ?>
            <div class="ap-look"><button type="button" class="ap-look-card" data-look="<?= $lookJson($look) ?>" title="Use “<?= $h($name) ?>”"><?= $swatch($look) ?><span class="ap-look-name"><?= $h($name) ?></span></button></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="ap-looks-block">
        <span class="ap-looks-title">My looks <span class="muted small">(<?= count($looks) ?> of <?= ECW_APPEARANCE_LOOKS_MAX ?>)</span></span>
        <?php if ($looks): ?>
          <div class="ap-looks" id="myLooks">
            <?php foreach ($looks as $item): ?>
              <div class="ap-look"><button type="button" class="ap-look-card" data-look="<?= $lookJson($item['look']) ?>" data-look-name="<?= $h($item['name']) ?>" title="Use “<?= $h($item['name']) ?>”"><?= $swatch($item['look']) ?><span class="ap-look-name"><?= $h($item['name']) ?></span></button>
                <button type="submit" form="lookDeleteForm" name="look_name" value="<?= $h($item['name']) ?>" class="ap-look-del" title="Delete “<?= $h($item['name']) ?>”" aria-label="Delete “<?= $h($item['name']) ?>”" onclick="if (window.ecwArm && !window.ecwArm(this, 'Click again to delete')) return false;"><i class="fa fa-trash-can" aria-hidden="true"></i></button></div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted small ap-looks-empty">None yet. Make the look you like below, give it a name here and save it, to come back to it any time.</p>
        <?php endif; ?>
        <div class="ap-look-save">
          <input type="text" name="look_name" maxlength="<?= ECW_APPEARANCE_LOOK_NAME_MAX ?>" placeholder="Name, e.g. My dark blue" aria-label="Name of the look" class="ot-input" list="myLookNames" autocomplete="off">
          <datalist id="myLookNames"><?php foreach ($looks as $item): ?><option value="<?= $h($item['name']) ?>"><?php endforeach; ?></datalist>
          <button type="button" id="lookSave"><i class="fa fa-bookmark" aria-hidden="true"></i> Save as a look</button>
        </div>
        <p class="muted small ap-look-note" id="lookNote">The same name again replaces that look.</p>
      </div>
    </fieldset>

    <?= $fold('fa-palette', 'Colours', 'page, panels, text, borders, top bar, links', ['color_page', 'color_panel', 'color_text', 'color_text2', 'color_border', 'color_topbar', 'color_topbar_text', 'color_link']) ?>
      <p class="muted small ap-note">Unticked, a colour follows the theme. When you pick a background and leave its text colour unticked, the text turns dark or light by itself so it stays readable.</p>
      <?= $own('color_page', 'Page background', 'behind the panels', $p[4]) ?>
      <?= $own('color_panel', 'Panels and cards', 'boxes, lists, menus', $p[3]) ?>
      <?= $own('color_text', 'Main text', '', '#0d0d0d') ?>
      <?= $own('color_text2', 'Secondary text', 'hints, dates, labels', '#4b5563') ?>
      <?= $own('color_border', 'Borders and lines', '', '#e5e7eb') ?>
      <?= $own('color_topbar', 'Top bar background', '', $p[3]) ?>
      <?= $own('color_topbar_text', 'Top bar text', '', '#4b5563') ?>
      <?= $own('color_link', 'Links', 'otherwise the accent colour', $p[5]) ?>
    </div></details>

    <?= $fold('fa-font', 'Text and fonts', 'letters of the app, size, the font emails are read in', ['ui_font', 'ui_scale', 'mail_family', 'mail_font', 'mail_line']) ?>
      <label>Letters of the app<?= $fonts('ui_font', 'App default (Segoe UI or your system’s)') ?></label>
      <?= $range('ui_scale', 90, 130, 5, $a['ui_scale'], '%', 'Size of everything') ?>
      <label>Font emails are read in<?= $fonts('mail_family', 'As the email was written') ?></label>
      <?= $range('mail_font', 12, 20, 1, $a['mail_font'], ' px', 'Email text size') ?>
      <label>Line spacing of email text<?= $select('mail_line', [0 => 'As the email was written', 120 => 'Tight', 140 => 'Normal', 160 => 'Relaxed', 180 => 'Wide', 200 => 'Double']) ?></label>
    </div></details>

    <?= $fold('fa-hand-pointer', 'Buttons', 'shape, filled or outline, colour', ['button_shape', 'button_style', 'button_color']) ?>
      <div class="ap-two">
        <label>Shape<?= $select('button_shape', ['' => 'As the app has them', 'square' => 'Square', 'rounded' => 'Rounded', 'pill' => 'Pill · fully round']) ?></label>
        <label>Main buttons<?= $select('button_style', ['filled' => 'Filled with colour', 'outline' => 'Outline only']) ?></label>
      </div>
      <?= $own('button_color', 'Own button colour', 'otherwise the accent colour', $p[5]) ?>
    </div></details>

    <?= $fold('fa-comments', 'Messages', 'colours of sent and received, text, corners, edges', ['sent_text', 'received_text', 'corners', 'bubble_edge']) ?>
      <div class="ap-colors ap-colors-2">
        <?php foreach (['sent' => 'Sent messages', 'received' => 'Received messages'] as $key => $label): $i = $key === 'sent' ? 1 : 2; ?>
          <label class="ap-color"><input type="color" name="<?= $key ?>" value="<?= $h($a[$key] ?: $p[$i]) ?>"><span><?= $h($label) ?></span></label>
        <?php endforeach; ?>
      </div>
      <?= $own('sent_text', 'Own text colour of sent messages', 'otherwise dark or light to suit', '#17202c') ?>
      <?= $own('received_text', 'Own text colour of received messages', 'otherwise dark or light to suit', '#17202c') ?>
      <div class="ap-two">
        <label>Message corners<?= $select('corners', ['square' => 'Square', 'rounded' => 'Rounded', 'round' => 'Round · bubbles']) ?></label>
        <label>Message edges<?= $select('bubble_edge', ['' => 'As the app has them', 'flat' => 'Flat · no line or shadow', 'border' => 'A clear line around', 'shadow' => 'A soft shadow']) ?></label>
      </div>
    </div></details>

    <?= $fold('fa-image', 'Background behind the emails', 'colour, dots, lines or doodles', ['background', 'doodle_color']) ?>
      <label class="ap-color ap-canvas"><input type="color" name="canvas" value="<?= $h($a['canvas'] ?: $p[0]) ?>"><span>Colour behind the emails</span></label>
      <input type="hidden" name="background" value="<?= $h($a['background']) ?>">
      <div class="ap-bg-tiles">
        <?php foreach (ECW_APPEARANCE_BACKGROUNDS as $value => $label): ?>
          <button type="button" class="ap-bg-tile ap-bg-<?= $value ?><?= $a['background'] === $value ? ' active' : '' ?>" data-preset-bg="<?= $value ?>"><span class="ap-bg-swatch" aria-hidden="true"></span><?= $h($label) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="ap-doodle-opts" data-show-when="background=doodles">
        <?= $range('doodle_opacity', 3, 30, 1, $a['doodle_opacity'], '%', 'Strength') ?>
        <?= $range('doodle_size', 60, 160, 5, $a['doodle_size'], '%', 'Size') ?>
        <?= $own('doodle_color', 'Own doodle colour', 'otherwise it follows the theme', '#3e6b4a') ?>
      </div>
    </div></details>

    <?= $fold('fa-table-columns', 'Layout', 'spacing, the email list, colour rules', ['density', 'list_preview', 'rule_style']) ?>
      <label>Spacing<?= $select('density', ['comfortable' => 'Comfortable', 'compact' => 'Compact · more on screen']) ?></label>
      <input type="hidden" name="list_preview" value="0">
      <label class="checkbox-label ap-list-preview"><input type="checkbox" name="list_preview" value="1" <?= $a['list_preview'] ? 'checked' : '' ?>> Show the first words of each email in the email list <span class="muted small">(unticked: thinner rows with the sender and the subject)</span></label>
      <label>Colour rules in the mail list<select name="rule_style" class="ot-select" title="How a colour rule (Settings → My rules) marks a conversation">
        <option value="row" <?= $a['rule_style'] === 'row' ? 'selected' : '' ?>>Whole row · tinted, with a bar at the side (default)</option>
        <option value="text" <?= $a['rule_style'] === 'text' ? 'selected' : '' ?>>Text only · sender and subject in the colour</option>
      </select></label>
    </div></details>

  </div>

  <div class="appearance-preview" aria-label="Preview">
    <div class="ap-sample" aria-hidden="true">
      <div class="ap-sample-bar"><b>Case Workspace</b><span>Mail</span><span class="on">Cases</span><span>Settings</span></div>
      <div class="ap-sample-page">
        <div class="ap-sample-panel"><strong>A panel</strong><span class="ap-sample-sub">Secondary text · 31 Oct, 10:24</span><span>Main text with <a href="#" onclick="return false">a link</a>.</span>
          <span class="ap-sample-btns"><button type="button" class="primary" tabindex="-1">Main button</button><button type="button" tabindex="-1">Other button</button></span></div>
      </div>
    </div>
    <p class="preview-received"><strong>Nikos Papadopoulos</strong> · Received<br>Could you send us the quote for 50 parts of GLO-1234 by Friday?</p>
    <p class="preview-sent"><strong>You</strong> · Sent<br>Of course — the quote and drawing are attached. Delivery in 3 weeks.</p>
    <p class="preview-received"><strong>Nikos Papadopoulos</strong> · Received<br>Perfect, thank you! <a href="#" onclick="return false">Order confirmation</a> follows today.</p>
  </div>
</div>
<p class="ap-actions"><button type="submit" class="primary">Save appearance</button> <button type="button" id="appearanceReset">Reset to defaults</button></p></form></main>
</div></body></html>
