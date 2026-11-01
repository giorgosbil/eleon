<?php declare(strict_types=1);
/** Settings → Branding (admins): the name in the top bar, or none, and the business logo. */
require_once dirname(__DIR__, 2) . '/lib/session.php';
require_once dirname(__DIR__, 2) . '/lib/branding.php';
$context = ecw_require_admin();
$orgId = (int)$context['organization_id'];
$base = ecw_web_base();
$navActive = 'settings';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ecw_csrf_valid((string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Invalid session token. Reload and try again.'); }
    $msg = 'saved';
    try {
        ecw_branding_set_name($orgId, ($_POST['name_mode'] ?? '') === 'none' ? '' : (string)($_POST['name'] ?? ''));
        if (!empty($_POST['remove_logo'])) ecw_branding_remove_logo($orgId);
        elseif (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) ecw_branding_set_logo($orgId, (string)$_FILES['logo']['tmp_name']);
        elseif (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('The picture could not be uploaded (up to 1 MB).');
    } catch (InvalidArgumentException $e) { $_SESSION['ecw_brand_error'] = $e->getMessage(); $msg = 'error'; }
    header('Location: branding.php?' . $msg . '=1'); exit;
}
$b = ecw_branding($orgId);
$error = $_SESSION['ecw_brand_error'] ?? null;
unset($_SESSION['ecw_brand_error']);
$logoUrl = $b['logo_ext'] ? $base . 'brand-logo.php?v=' . rawurlencode((string)$b['logo_v']) : '';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Branding — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="<?= $h($base) ?>assets/app.css"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide"><h1>Branding</h1>
<p class="muted">Your business in the top bar, for everyone in this workspace. The selected product mark stays on the corner of your logo.</p>
<?php if (isset($_GET['saved'])): ?><div class="flash ok">Branding saved.</div><?php endif; ?>
<?php if ($error): ?><div class="flash err"><?= $h($error) ?></div><?php endif; ?>
<p class="muted small">Product profile on this installation: <strong><?= $h(ecw_product_brand()['name']) ?></strong>. Run <code>Branding.cmd</code> to switch between Original and Eleon on this PC. This choice is kept when pulling code or transferring workspace data. <a href="?brand_preview=eleon">Preview Eleon on this page</a>.</p>
<form method="post" enctype="multipart/form-data" id="brandForm" class="brand-form"><input type="hidden" name="csrf" value="<?= $h(ecw_csrf_token()) ?>">
  <fieldset class="ap-group"><legend>Name in the top bar</legend>
    <label class="checkbox-label"><input type="radio" name="name_mode" value="name" <?= $b['name'] !== '' ? 'checked' : '' ?>> Show a name</label>
    <input type="text" name="name" id="brandName" maxlength="60" value="<?= $h($b['name'] !== '' ? $b['name'] : ecw_product_brand()['name']) ?>" aria-label="Name in the top bar" placeholder="<?= $h(ecw_product_brand()['name']) ?>">
    <label class="checkbox-label"><input type="radio" name="name_mode" value="none" <?= $b['name'] === '' ? 'checked' : '' ?>> No name, the logo only</label>
  </fieldset>
  <fieldset class="ap-group"><legend>Logo</legend>
    <p class="muted small">PNG, JPEG, WebP or GIF, up to 1 MB. It is shown about 28 pixels high, so a wide logo on a transparent background works best.</p>
    <input type="file" name="logo" id="brandLogo" accept="image/png,image/jpeg,image/webp,image/gif">
    <?php if ($logoUrl): ?><label class="checkbox-label"><input type="checkbox" name="remove_logo" value="1"> Remove the logo</label><?php endif; ?>
  </fieldset>
  <fieldset class="ap-group"><legend>Preview</legend>
    <div class="brand-preview"><span class="appnav-brand" id="brandPreview" data-product="<?= $h($base . ecw_product_brand()['icons']) ?>favicon-32.png"></span></div>
  </fieldset>
  <p class="ap-actions"><button type="submit" class="primary">Save branding</button></p>
</form></main></div>
<script>
(() => {
  // Live preview of the top bar's corner, drawn like partials/nav.php does.
  const form = document.getElementById('brandForm'), out = document.getElementById('brandPreview');
  let logo = <?= json_encode($logoUrl) ?>, picked = '';
  const draw = () => {
    const showName = form.elements.name_mode.value !== 'none';
    const name = form.elements.name.value.trim();
    const src = form.elements.remove_logo?.checked ? '' : (picked || logo);
    out.replaceChildren();
    const mark = Object.assign(document.createElement('img'), { src: out.dataset.product, alt: '', className: 'brand-mark' });
    if (src) {
      const wrap = document.createElement('span'); wrap.className = 'brand-logo-wrap';
      wrap.append(Object.assign(document.createElement('img'), { src, alt: '', className: 'brand-logo' }), mark);
      out.append(wrap);
    } else {
      mark.classList.add('solo'); out.append(mark);
    }
    if (showName && name) out.append(Object.assign(document.createElement('span'), { className: 'brand-name', textContent: name }));
    form.elements.name.disabled = !showName;
  };
  form.addEventListener('input', draw);
  form.addEventListener('change', draw);
  form.elements.logo.addEventListener('change', () => {
    const f = form.elements.logo.files[0];
    if (picked) URL.revokeObjectURL(picked);
    picked = f ? URL.createObjectURL(f) : '';
    draw();
  });
  draw();
})();
</script>
</body></html>
