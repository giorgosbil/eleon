<?php declare(strict_types=1);
/** Settings → Templates: reply templates (canned replies). */
require_once dirname(__DIR__, 2) . '/lib/session.php';
$context = ecw_require_login(); ecw_require_feature('templates'); $navActive = 'settings'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Templates — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/app.css') ?>"></head>
<body class="page-body" data-csrf="<?= $h(ecw_csrf_token()) ?>" data-user="<?= (int)$context['user']['id'] ?>" data-admin="<?= ecw_is_admin() ? '1' : '0' ?>"><?php require dirname(__DIR__) . '/partials/nav.php'; ?>
<div class="settings-shell btn-scope"><?php require dirname(__DIR__) . '/partials/settings-nav.php'; ?>
<main class="page settings-wide btn-scope">
  <h1>Reply templates</h1>
  <p class="muted">Insert ready-made text while writing an email (<strong>Templates</strong> button in the composer). Shared templates are available to everyone in the workspace.</p>
  <div id="tplList"></div>
  <button type="button" id="tplNew">+ New template</button>

  <form class="rule-editor" id="tplEditor" hidden autocomplete="off">
    <label>Name <input type="text" id="tplName" maxlength="120" required placeholder="e.g. Quote received"></label>
    <label>Subject (optional, used when the email has none) <input type="text" id="tplSubject" maxlength="255"></label>
    <div class="quote-edit-toolbar" id="tplToolbar">
      <button type="button" data-cmd="bold"><b>B</b></button>
      <button type="button" data-cmd="italic"><i>I</i></button>
      <button type="button" data-cmd="underline"><u>U</u></button>
      <button type="button" data-cmd="insertUnorderedList">• List</button>
      <span class="muted small" style="align-self:center">Insert:</span>
      <span id="tplPlaceholders"></span>
    </div>
    <div id="tplBody" class="quote-editable" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Template text" style="background:var(--bg);border:1px solid var(--border);border-radius:8px;min-height:180px"></div>
    <label class="checkbox-label"><input type="checkbox" id="tplShared"> Share with the whole workspace</label>
    <div><button type="submit" class="primary">Save template</button> <button type="button" id="tplCancel">Cancel</button> <button type="button" id="tplDelete" class="danger" hidden>Delete</button></div>
    <p id="tplStatus" class="muted" role="status"></p>
  </form>
</main>
</div>
<script>
(() => {
  const $ = (id) => document.getElementById(id);
  const me = Number(document.body.dataset.user), admin = document.body.dataset.admin === '1';
  let templates = [], editing = null;
  const api = async (action, body) => {
    const r = await fetch('../api/index.php?action=' + action, body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': document.body.dataset.csrf }, body: JSON.stringify(body) });
    const d = await r.json().catch(() => ({})); if (!r.ok) throw new Error(d.error || 'Request failed'); return d;
  };
  const canEdit = (t) => !t.shared || admin || t.created_by === me;
  function list() {
    const box = $('tplList'); box.textContent = '';
    if (!templates.length) { const p = document.createElement('p'); p.className = 'muted'; p.textContent = 'No templates yet.'; box.appendChild(p); }
    templates.forEach((t) => {
      const card = document.createElement('div'); card.className = 'tpl-card';
      const text = document.createElement('span'); text.className = 'tpl-text';
      const name = document.createElement('strong'); name.textContent = t.name;
      const sub = document.createElement('span'); sub.className = 'muted';
      const tmp = document.createElement('div'); tmp.innerHTML = t.body_html; // sanitized on save (lib/templates.php); used only to extract text
      sub.textContent = (t.shared ? 'Shared' + (t.creator ? ' by ' + t.creator : '') : 'Personal') + ' · ' + tmp.textContent.slice(0, 90);
      text.append(name, sub); card.appendChild(text);
      if (canEdit(t)) { const b = document.createElement('button'); b.type = 'button'; b.className = 'link-btn'; b.textContent = 'Edit'; b.addEventListener('click', () => open(t)); card.appendChild(b); }
      box.appendChild(card);
    });
  }
  function open(t) {
    editing = t;
    $('tplName').value = t?.name || ''; $('tplSubject').value = t?.subject || '';
    $('tplBody').innerHTML = t?.body_html || ''; // sanitized on save
    $('tplShared').checked = !!t?.shared; $('tplDelete').hidden = !t; $('tplStatus').textContent = '';
    $('tplEditor').hidden = false; $('tplName').focus();
  }
  document.querySelectorAll('#tplToolbar [data-cmd]').forEach((b) => { b.addEventListener('mousedown', (e) => e.preventDefault()); b.addEventListener('click', () => { $('tplBody').focus(); document.execCommand(b.dataset.cmd); }); });
  $('tplNew').addEventListener('click', () => open(null));
  $('tplCancel').addEventListener('click', () => { $('tplEditor').hidden = true; });
  $('tplEditor').addEventListener('submit', async (e) => {
    e.preventDefault();
    try { templates = (await api('template_save', { id: editing?.id, name: $('tplName').value, subject: $('tplSubject').value, body_html: $('tplBody').innerHTML, shared: $('tplShared').checked })).templates; $('tplEditor').hidden = true; list(); }
    catch (err) { $('tplStatus').textContent = err.message; }
  });
  $('tplDelete').addEventListener('click', async () => {
    const b = $('tplDelete');
    if (b.dataset.confirm !== '1') { b.dataset.confirm = '1'; b.textContent = 'Click again to delete'; return; }
    try { templates = (await api('template_delete', { id: editing.id })).templates; $('tplEditor').hidden = true; list(); } catch (err) { $('tplStatus').textContent = err.message; }
    b.dataset.confirm = ''; b.textContent = 'Delete';
  });
  api('templates').then((d) => {
    templates = d.templates; list();
    Object.entries(d.placeholders).forEach(([token, label]) => {
      const b = document.createElement('button'); b.type = 'button'; b.textContent = token; b.title = label;
      b.addEventListener('mousedown', (e) => e.preventDefault());
      b.addEventListener('click', () => { $('tplBody').focus(); document.execCommand('insertText', false, token); });
      $('tplPlaceholders').appendChild(b);
    });
  }).catch((e) => { $('tplList').textContent = e.message; });
})();
</script>
</body></html>
