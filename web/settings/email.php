<?php declare(strict_types=1);
/**
 * Settings → Email & writing: how this person works with emails. The font new emails are
 * written in, where replies open, the order of a conversation. Signatures are its second
 * tab (signatures.php). The writing choices are part of lib/appearance.php (page 'writing').
 */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/appearance.php';
$context = ecw_require_login();
$userId = (int)$context['user']['id'];
$base = ecw_web_base();
$navActive = 'settings';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$prefsOf = static function (int $userId): array {
    $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    return json_decode((string)$st->fetchColumn(), true) ?: [];
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Invalid session token. Reload and try again.'); }
    ecw_appearance_save($userId, 'writing', $_POST);
    // The order of a conversation: the same preference as Mail's settings menu (api prefs_save).
    if (in_array($_POST['thread_order'] ?? '', ['asc', 'desc'], true)) {
        $prefs = $prefsOf($userId);
        $prefs['thread_order'] = $_POST['thread_order'];
        ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET preferences_json = ? WHERE id = ?')->execute([json_encode($prefs), $userId]);
    }
    header('Location: email.php?saved=1'); exit;
}
$a = ecw_load_appearance($userId);
$order = ($prefsOf($userId)['thread_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Email &amp; writing — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="<?= $h($base) ?>assets/app.css"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide"><h1>Email &amp; writing</h1>
<nav class="settings-tabs" aria-label="Email &amp; writing"><a href="email.php" class="active" aria-current="page">Writing and replies</a><a href="signatures.php">Signatures</a></nav>
<?php if (isset($_GET['saved'])): ?><div class="flash ok">Saved.</div><?php endif; ?>
<form method="post" id="appearanceForm" data-page="writing"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>">
  <div class="appearance-fields">
    <fieldset class="ap-group"><legend>Replies</legend>
      <div class="ap-reply-mode" role="radiogroup" aria-label="Where replies open"><span>Where replies and new emails open</span>
        <label class="checkbox-label"><input type="radio" name="reply_mode" value="window" <?= $a['reply_mode'] === 'window' ? 'checked' : '' ?>> In a window <span class="muted small">Floats over the mail, bottom right; can be moved and resized</span></label>
        <label class="checkbox-label"><input type="radio" name="reply_mode" value="inline" <?= $a['reply_mode'] === 'inline' ? 'checked' : '' ?>> In the email <span class="muted small">A reply opens inside the conversation, next to the newest email; a new email in its place</span></label>
      </div>
      <label>Order of a conversation<select name="thread_order" class="ot-select">
        <option value="asc" <?= $order === 'asc' ? 'selected' : '' ?>>Oldest first · the newest email at the bottom</option>
        <option value="desc" <?= $order === 'desc' ? 'selected' : '' ?>>Newest first · the newest email at the top</option>
      </select></label>
    </fieldset>

    <fieldset class="ap-group"><legend>The font you write in</legend>
      <p class="muted small" style="margin:0 0 8px">The font your new emails and replies start in, as the recipient sees it. Part of an email can still be changed from the composer's toolbar.</p>
      <div class="ap-two ap-font-row">
        <label>Font<select name="compose_font" class="ot-select"><?php foreach (ECW_COMPOSE_FONTS as $name => $stack): ?><option value="<?= $h($name) ?>" style="font-family:<?= $h($stack) ?>" <?= $a['compose_font'] === $name ? 'selected' : '' ?>><?= $h($name) ?></option><?php endforeach; ?></select></label>
        <label title="Pick one, or type any size from <?= ECW_COMPOSE_SIZE_MIN ?> to <?= ECW_COMPOSE_SIZE_MAX ?> pt, as in Outlook (<?= ECW_COMPOSE_SIZE_DEFAULT ?> is the default)">Size (pt)<input type="hidden" name="compose_unit" value="pt"><input type="number" name="compose_size" class="ot-input ap-size-input" list="composeSizeList" min="<?= ECW_COMPOSE_SIZE_MIN ?>" max="<?= ECW_COMPOSE_SIZE_MAX ?>" step="1" value="<?= (int)$a['compose_size'] ?>" inputmode="numeric"></label>
        <datalist id="composeSizeList"><?php foreach (ECW_COMPOSE_SIZES as $px): ?><option value="<?= $px ?>"><?= $px === ECW_COMPOSE_SIZE_DEFAULT ? 'default' : '' ?></option><?php endforeach; ?></datalist>
        <div class="ap-text-color"><span>Colour</span>
          <span class="ap-text-color-row" title="Unticked: black, as every email client shows by default">
            <input type="color" name="compose_color" value="<?= $h($a['compose_color'] ?: '#1f2937') ?>" aria-label="Text colour of new emails">
            <label class="checkbox-label"><input type="checkbox" name="compose_color_custom" value="1" <?= $a['compose_color'] !== '' ? 'checked' : '' ?>> Own</label>
          </span>
        </div>
      </div>
      <p class="ap-compose-preview">Dear Nikos,<br><br>The quote for 50 parts of GLO-1234 is attached.</p>
    </fieldset>
  </div>
<p class="ap-actions"><button type="submit" class="primary">Save</button> <button type="button" id="appearanceReset">Reset to defaults</button></p></form></main>
</div></body></html>
