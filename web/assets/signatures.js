// Settings → Signatures: a small editor for HTML signatures (tables, pictures, links).
// Not Quill: pasted signatures are tables (logo | text), which Quill would flatten.
// Everything saved or pasted is cleaned on the server (lib/sanitize.php ecw_sanitize_signature_html).
(() => {
  const $ = (id) => document.getElementById(id);
  const root = $('profileEditor');
  const bar = $('profileToolbar');
  const source = $('profileSource');
  const imageBar = $('profileImageBar');
  const PICTURE_WIDTH = 600;     // px: bigger pictures are scaled down before they go in
  const PICTURE_SHOWN = 300;     // px: the width a new picture starts at; resize it after
  let selection = null, editorVersion = 0, dirty = false, currentId = '', selectedImg = null;

  document.addEventListener('selectionchange', () => {
    const s = window.getSelection();
    if (s.rangeCount && root.contains(s.anchorNode)) selection = s.getRangeAt(0).cloneRange();
  });
  function restoreSelection() {
    root.focus();
    if (!selection) return;
    const s = window.getSelection(); s.removeAllRanges(); s.addRange(selection);
  }
  function command(name, value) { restoreSelection(); document.execCommand(name, false, value || null); changed(); }
  function changed() { dirty = true; }
  function say(text, isError) { $('profileStatus').textContent = ''; if (window.ecwPageToast) window.ecwPageToast(text, isError); else $('profileStatus').textContent = text; }

  // ---- Toolbar -------------------------------------------------------------------------------------
  function button(icon, label, onClick, text) {
    const b = document.createElement('button');
    b.type = 'button'; b.title = label; b.setAttribute('aria-label', label); b.className = 'sig-tool';
    b.innerHTML = `<i class="fa fa-${icon}" aria-hidden="true"></i>` + (text ? ` <span>${text}</span>` : '');
    b.addEventListener('mousedown', (e) => e.preventDefault());   // keep the text selection
    b.addEventListener('click', onClick);
    return b;
  }
  function group(...items) { const g = document.createElement('span'); g.className = 'sig-tool-group'; g.append(...items); bar.appendChild(g); return g; }

  // Font and size of the selected text, from the lists in Settings → Email & writing (lib/appearance.php).
  // An inline style on a span (execCommand's fontSize only knows 1–7); "Default" removes it.
  function styleSelection(prop, value) {
    if (!selection || selection.collapsed || !root.contains(selection.commonAncestorContainer)) { say('Select some text first.', true); return; }
    const part = selection.extractContents();
    part.querySelectorAll('[style]').forEach((el) => el.style.removeProperty(prop));
    let node = part;
    if (value) { node = document.createElement('span'); node.style.setProperty(prop, value); node.appendChild(part); }
    const first = node.firstChild, last = node.lastChild;
    selection.insertNode(node);
    if (value) selection.selectNodeContents(node); else if (first) { selection.setStartBefore(first); selection.setEndAfter(last); }
    const s = window.getSelection(); s.removeAllRanges(); s.addRange(selection);
    changed();
  }
  const dflt = window.ecwComposeDefault || { font: 'Segoe UI', size: 11 };
  const pickers = [];
  for (const [label, prop, items] of [
    ['Font', 'font-family', (window.ecwComposeFontNames || []).map((n) => [n, n])],
    ['Size', 'font-size', (window.ecwComposeSizes || []).map((n) => [n + ' pt', n + 'pt'])],
  ]) {
    const pick = document.createElement('select');
    pick.title = label; pick.setAttribute('aria-label', label);
    pick.add(new Option(label, '', true, true));
    pick.add(new Option('Default (' + (prop === 'font-family' ? dflt.font : dflt.size + ' pt') + ')', 'default'));
    items.forEach(([text, value]) => { const o = new Option(text, value); if (prop === 'font-family') o.style.fontFamily = value; pick.add(o); });
    pick.addEventListener('change', () => { styleSelection(prop, pick.value === 'default' ? '' : pick.value); pick.value = ''; });
    pickers.push(pick);
  }
  group(...pickers);
  // The two boxes show the font and size of the text under the cursor, as it is drawn (also a
  // font the list does not have, from a pasted signature): their first entry, "Font" / "Size" before.
  document.addEventListener('selectionchange', () => {
    const s = window.getSelection();
    let node = s.rangeCount ? s.anchorNode : null;
    if (!node || !root.contains(node)) return;
    if (node.nodeType === 3) node = node.parentElement;
    const cs = getComputedStyle(node);
    const now = [cs.fontFamily.split(',')[0].replace(/["']/g, '').trim(), (Math.round(parseFloat(cs.fontSize) * 0.75 * 2) / 2) + ' pt'];
    pickers.forEach((pick, i) => { pick.options[0].text = now[i]; pick.title = now[i]; });
  });
  group(
    button('bold', 'Bold (Ctrl+B)', () => command('bold')),
    button('italic', 'Italic (Ctrl+I)', () => command('italic')),
    button('underline', 'Underline (Ctrl+U)', () => command('underline')),
  );
  const color = document.createElement('input');
  color.type = 'color'; color.title = 'Text colour'; color.setAttribute('aria-label', 'Text colour');
  color.addEventListener('input', () => command('foreColor', color.value));
  const colorDot = document.createElement('label'); colorDot.className = 'color-dot'; colorDot.title = 'Text colour'; colorDot.appendChild(color);
  group(colorDot);
  group(
    button('align-left', 'Align left', () => command('justifyLeft')),
    button('align-center', 'Centre', () => command('justifyCenter')),
    button('align-right', 'Align right', () => command('justifyRight')),
  );

  // Link: a small inline form, not a prompt().
  const linkForm = document.createElement('span'); linkForm.className = 'sig-link-form'; linkForm.hidden = true;
  const linkInput = document.createElement('input');
  linkInput.type = 'text'; linkInput.className = 'ot-input'; linkInput.placeholder = 'https://example.com or name@example.com'; linkInput.setAttribute('aria-label', 'Link address');
  const linkOk = document.createElement('button'); linkOk.type = 'button'; linkOk.textContent = 'Apply';
  const linkCancel = document.createElement('button'); linkCancel.type = 'button'; linkCancel.textContent = 'Cancel';
  linkForm.append(linkInput, linkOk, linkCancel);
  function normalizeUrl(v) {
    v = v.trim();
    if (!v) return '';
    if (/^(https?:|mailto:|tel:)/i.test(v)) return v;
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'mailto:' + v;
    if (/^[a-z][a-z0-9+.-]*:/i.test(v)) return '';
    return 'https://' + v;
  }
  function applyLink() {
    const url = normalizeUrl(linkInput.value);
    if (!url) { say('Enter a web address (http/https) or an email address.', true); return; }
    if (selection && selection.collapsed) {
      const a = document.createElement('a'); a.href = url; a.textContent = linkInput.value.trim();
      selection.insertNode(a); selection.setStartAfter(a); selection.collapse(true); changed();
    } else command('createLink', url);
    linkForm.hidden = true; linkInput.value = '';
  }
  const linkBtn = button('link', 'Link', () => {
    const node = selection?.commonAncestorContainer;
    const a = (node?.nodeType === 1 ? node : node?.parentElement)?.closest('a');
    linkInput.value = a ? a.getAttribute('href') : '';
    linkForm.hidden = false; linkInput.focus();
  });
  linkOk.addEventListener('click', applyLink);
  linkCancel.addEventListener('click', () => { linkForm.hidden = true; linkInput.value = ''; });
  linkInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); applyLink(); } else if (e.key === 'Escape') linkCancel.click(); });

  const pictureFile = document.createElement('input');
  pictureFile.type = 'file'; pictureFile.accept = 'image/png,image/jpeg,image/gif,image/webp'; pictureFile.hidden = true;
  pictureFile.addEventListener('change', async () => { const files = [...pictureFile.files]; pictureFile.value = ''; await insertPictures(files); });
  group(
    linkBtn,
    button('link-slash', 'Remove link', () => command('unlink')),
    button('image', 'Add a picture (or paste / drop one)', () => pictureFile.click(), 'Picture'),
    pictureFile,
  );
  group(button('eraser', 'Clear formatting of the selected text', () => command('removeFormat')));
  const sourceBtn = button('code', 'Edit the HTML', () => toggleSource(), 'HTML');
  group(sourceBtn);
  bar.appendChild(linkForm);

  // ---- Pictures ------------------------------------------------------------------------------------
  // A picture goes in as a data: URI, scaled to at most 600 px wide; sending turns it into an
  // inline attachment (lib/compose.php ecw_extract_inline_images), so recipients always see it.
  function readFile(file) {
    return new Promise((ok, bad) => { const r = new FileReader(); r.onload = () => ok(r.result); r.onerror = () => bad(Error('Could not read the picture.')); r.readAsDataURL(file); });
  }
  async function pictureData(file) {
    if (!/^image\/(png|jpeg|gif|webp)$/.test(file.type)) throw Error('Only PNG, JPG, GIF or WebP pictures can go in a signature.');
    const src = await readFile(file);
    const img = new Image();
    await new Promise((ok, bad) => { img.onload = ok; img.onerror = () => bad(Error('Could not read the picture.')); img.src = src; });
    const w = img.naturalWidth || 1, h = img.naturalHeight || 1;
    if (file.type === 'image/gif' || w <= PICTURE_WIDTH) return { src, width: Math.min(w, PICTURE_SHOWN) };   // a GIF keeps its animation
    const scale = PICTURE_WIDTH / w;
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(w * scale); canvas.height = Math.round(h * scale);
    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
    // A logo keeps its transparent background as PNG; a photo becomes a smaller JPG.
    const out = file.type === 'image/jpeg' ? canvas.toDataURL('image/jpeg', 0.88) : canvas.toDataURL('image/png');
    return { src: out, width: Math.min(canvas.width, PICTURE_SHOWN) };
  }
  function pictureHtml(p) { return `<img src="${p.src}" width="${p.width}" style="width:${p.width}px;height:auto" alt="">`; }
  async function insertPictures(files) {
    if (!files.length) return;
    try {
      const html = (await Promise.all(files.map(pictureData))).map(pictureHtml).join(' ');
      command('insertHTML', html);
    } catch (e) { say(e.message, true); }
  }

  // Click a picture: change its width or remove it.
  const widthInput = $('profileImageWidth');
  function selectImage(img) {
    if (selectedImg) selectedImg.classList.remove('sig-img-selected');
    selectedImg = img;
    imageBar.hidden = !img;
    if (!img) return;
    img.classList.add('sig-img-selected');
    widthInput.value = Math.round(img.getBoundingClientRect().width) || img.width || '';
  }
  function setImageWidth(w) {
    if (!selectedImg) return;
    w = Math.max(16, Math.min(PICTURE_WIDTH, Math.round(Number(w) || 0)));
    selectedImg.setAttribute('width', w); selectedImg.removeAttribute('height');
    selectedImg.style.width = w + 'px'; selectedImg.style.height = 'auto';
    widthInput.value = w; changed();
  }
  root.addEventListener('click', (e) => selectImage(e.target instanceof HTMLImageElement ? e.target : null));
  widthInput.addEventListener('change', () => setImageWidth(widthInput.value));
  $('profileImageSmaller').addEventListener('click', () => setImageWidth((Number(widthInput.value) || 100) * 0.9));
  $('profileImageBigger').addEventListener('click', () => setImageWidth((Number(widthInput.value) || 100) * 1.1));
  $('profileImageRemove').addEventListener('click', () => { if (selectedImg) { selectedImg.remove(); selectImage(null); changed(); } });

  // ---- Paste and drop ------------------------------------------------------------------------------
  // Word and Outlook put large style blocks, comments and Office tags (<o:p>) on the clipboard:
  // they go here, so a signature never runs into the size limit for nothing.
  // Their pictures are file:/// paths on the PC, which a web page cannot read: they are replaced
  // by picture files on the same clipboard when there are any, and otherwise reported.
  function cleanClipboardHtml(html, files) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('style,script,meta,link,title,xml').forEach((n) => n.remove());
    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_COMMENT);
    const comments = []; while (walker.nextNode()) comments.push(walker.currentNode);
    comments.forEach((c) => c.remove());
    doc.body.querySelectorAll('*').forEach((el) => { if (el.tagName.includes(':')) el.replaceWith(...el.childNodes); });
    let lost = 0;
    doc.body.querySelectorAll('img').forEach((img) => {
      if (!/^(file:|blob:|cid:|webkit-fake-url:)/i.test(img.getAttribute('src') || '')) return;
      const file = files.shift();
      if (file) img.setAttribute('data-sig-file', file.index); else { img.remove(); lost++; }
    });
    return { doc, lost };
  }
  root.addEventListener('paste', async (event) => {
    event.preventDefault();
    const version = editorVersion;
    const data = event.clipboardData;
    const html = data.getData('text/html');
    const text = data.getData('text/plain');
    const files = [...data.files].filter((f) => f.type.startsWith('image/'));
    const range = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0).cloneRange() : null;
    if (!html) {
      if (files.length) { selection = range; await insertPictures(files); return; }
      command('insertText', text);
      return;
    }
    try {
      const queue = files.map((f, index) => Object.assign(f, { index }));
      const { doc, lost } = cleanClipboardHtml(html, queue);
      // A picture copied from a web page or an email: its file, not a link to someone's server.
      if (files.length && !doc.body.textContent.trim() && doc.body.querySelectorAll('img').length <= files.length) { selection = range; await insertPictures(files); return; }
      for (const img of doc.body.querySelectorAll('img[data-sig-file]')) {
        const p = await pictureData(files[Number(img.getAttribute('data-sig-file'))]);
        img.setAttribute('src', p.src); img.removeAttribute('data-sig-file');
        if (!img.getAttribute('width')) img.setAttribute('width', p.width);
      }
      const result = await request('signature_sanitize', { html: doc.body.innerHTML });
      if (version !== editorVersion) return;
      selection = range;
      command('insertHTML', result.html);
      if (lost) say(`${lost === 1 ? 'A picture' : lost + ' pictures'} could not come along: Outlook keeps them on this PC. Add ${lost === 1 ? 'it' : 'them'} with the Picture button (save the logo as a file first, or copy the picture on its own and paste it).`, true);
    } catch (e) { say(e.message, true); }
  });
  root.addEventListener('dragover', (e) => { if ([...e.dataTransfer.types].includes('Files')) e.preventDefault(); });
  root.addEventListener('drop', async (e) => {
    e.preventDefault();
    const files = [...e.dataTransfer.files].filter((f) => f.type.startsWith('image/'));
    if (!files.length) return;
    if (document.caretRangeFromPoint) selection = document.caretRangeFromPoint(e.clientX, e.clientY);
    await insertPictures(files);
  });
  root.addEventListener('input', changed);

  // ---- HTML view -----------------------------------------------------------------------------------
  // For a signature from a designer or a generator: paste or tweak the HTML itself.
  async function toggleSource(force) {
    const toSource = force ?? source.hidden;
    if (toSource) {
      selectImage(null);
      source.value = root.innerHTML;
      source.hidden = false; root.hidden = true; imageBar.hidden = true;
      sourceBtn.classList.add('is-on'); sourceBtn.setAttribute('aria-pressed', 'true');
      source.focus();
      return;
    }
    try {
      const result = await request('signature_sanitize', { html: source.value });
      root.innerHTML = result.html;
    } catch (e) { say(e.message, true); return; }
    source.hidden = true; root.hidden = false;
    sourceBtn.classList.remove('is-on'); sourceBtn.setAttribute('aria-pressed', 'false');
  }
  source.addEventListener('input', changed);
  function editorHtml() {
    if (!source.hidden) return source.value;
    const copy = root.cloneNode(true);
    copy.querySelectorAll('.sig-img-selected').forEach((img) => { img.classList.remove('sig-img-selected'); if (!img.className) img.removeAttribute('class'); });
    return copy.innerHTML;
  }

  // ---- Saved signatures ----------------------------------------------------------------------------
  let profiles = { profiles: [], default_id: null };
  async function request(action, data) {
    const response = await fetch('../api/index.php?action=' + action, data ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': document.body.dataset.csrf }, body: JSON.stringify(data) } : {});
    const json = await response.json().catch(() => ({}));
    if (!response.ok) throw Error(json.error || 'Could not save the signature.');
    return json;
  }
  function populate(id = '') {
    $('profileSelect').replaceChildren(new Option('New signature', ''));
    profiles.profiles.forEach((p) => $('profileSelect').add(new Option(p.name + (p.id === profiles.default_id ? ' · Default' : ''), p.id)));
    $('profileSelect').value = id;
    select(true);
  }
  function select(force) {
    if (!force && dirty && !window.confirm('This signature has changes that are not saved. Leave them?')) { $('profileSelect').value = currentId; return; }
    editorVersion++; selection = null; selectImage(null);
    if (!source.hidden) { source.hidden = true; root.hidden = false; sourceBtn.classList.remove('is-on'); sourceBtn.setAttribute('aria-pressed', 'false'); }
    currentId = $('profileSelect').value;
    const p = profiles.profiles.find((x) => x.id === currentId);
    $('profileName').value = p?.name || '';
    root.innerHTML = p?.html || '';
    $('profileDefault').checked = p ? p.id === profiles.default_id : !profiles.default_id;
    $('profileDelete').disabled = !p;
    dirty = false;
  }
  async function save(operation) {
    const buttons = $('signatureProfilesForm').querySelectorAll('button');
    buttons.forEach((b) => (b.disabled = true));
    try {
      profiles = await request('signature_profiles_save', { operation, id: $('profileSelect').value, name: $('profileName').value, html: editorHtml(), is_default: $('profileDefault').checked });
      dirty = false;
      populate(operation === 'delete' ? '' : profiles.saved_id);
      say(operation === 'delete' ? 'Signature deleted.' : 'Signature saved.');
    } catch (e) { say(e.message, true); }
    finally { buttons.forEach((b) => (b.disabled = false)); $('profileDelete').disabled = !$('profileSelect').value; }
  }
  $('profileName').addEventListener('input', changed);
  $('profileSelect').addEventListener('change', () => select(false));
  $('signatureProfilesForm').addEventListener('submit', (e) => { e.preventDefault(); void save('save'); });
  $('profileDelete').addEventListener('click', () => { if (window.ecwArm && !window.ecwArm($('profileDelete'), 'Click again to delete')) return; save('delete'); });
  window.addEventListener('beforeunload', (e) => { if (dirty) e.preventDefault(); });
  request('signature_profiles_get').then((data) => { profiles = data; populate(data.default_id || ''); }).catch((e) => say(e.message, true));
})();
