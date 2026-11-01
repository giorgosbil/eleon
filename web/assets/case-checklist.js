/**
 * Case page → Checklist tab (lib/checklists.php): the steps of the case.
 * Tick, give a step to someone, set its date, rename it (click the text),
 * drag to reorder, remove; "Use a template…" adds a template's steps.
 * Everything is written with textContent.
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const list = $('clList');
  if (!list) return;
  const caseId = Number(document.body.dataset.case);
  const csrf = document.body.dataset.csrf;
  let items = [];
  let people = [];

  async function api(action, body) {
    const url = new URL('api/index.php', location.href);
    url.searchParams.set('action', action);
    url.searchParams.set('case_id', String(caseId));
    const r = await fetch(url, body === undefined ? { credentials: 'same-origin' } : {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf }, body: JSON.stringify(body),
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Request failed');
    return d;
  }
  const toast = (m, err) => (window.ecwPageToast ? window.ecwPageToast(m, err) : null);
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const day = (ymd) => { if (!ymd) return ''; const d = new Date(ymd + 'T12:00:00'); return d.toLocaleDateString([], { day: 'numeric', month: 'short' }); };

  function render() {
    list.textContent = '';
    const done = items.filter((i) => i.done).length;
    $('checklistCount').textContent = items.length ? done + '/' + items.length : '';
    $('clProgress').hidden = !items.length;
    $('clBar').style.width = items.length ? Math.round(done / items.length * 100) + '%' : '0';
    $('clProgressText').textContent = items.length ? (done === items.length ? 'All ' + items.length + ' done' : done + ' of ' + items.length + ' done') : '';
    if (!items.length) {
      list.appendChild(el('li', 'cl-empty muted', 'No steps yet. Add the first one below' + ($('clTemplateRow').hidden ? '.' : ', or use a template.')));
      return;
    }
    items.forEach((it) => list.appendChild(row(it)));
  }

  function row(it) {
    const li = el('li', 'cl-item' + (it.done ? ' done' : '') + (it.late ? ' late' : ''));
    li.dataset.id = it.id;
    li.draggable = true;
    const box = el('input'); box.type = 'checkbox'; box.checked = it.done; box.className = 'cl-check';
    box.setAttribute('aria-label', (it.done ? 'Untick: ' : 'Tick: ') + it.text);
    box.addEventListener('change', () => save('case_checklist_toggle', { item_id: it.id, done: box.checked }));
    const main = el('div', 'cl-main');
    const text = el('span', 'cl-text', it.text);
    text.tabIndex = 0; text.title = 'Click to change';
    text.addEventListener('click', () => editText(it, text));
    text.addEventListener('keydown', (e) => { if (e.key === 'Enter') editText(it, text); });
    const meta = el('div', 'cl-meta');
    // who
    const who = el('select', 'cl-who');
    who.setAttribute('aria-label', 'Who does it');
    who.appendChild(new Option('Nobody yet', ''));
    people.forEach((p) => who.appendChild(new Option(p.name || p.email, p.id)));
    who.value = it.assignee_id ? String(it.assignee_id) : '';
    who.addEventListener('change', () => save('case_checklist_update', { item_id: it.id, assignee_id: who.value ? Number(who.value) : null }));
    // when
    const when = el('input', 'cl-when'); when.type = 'date'; when.value = it.due_date || '';
    when.setAttribute('aria-label', 'Due date');
    when.addEventListener('change', () => save('case_checklist_update', { item_id: it.id, due_date: when.value || null }));
    meta.append(who, when);
    if (it.done && it.done_by) meta.appendChild(el('span', 'cl-doneby muted small', '✓ ' + it.done_by));
    else if (it.late) meta.appendChild(el('span', 'cl-late small', 'overdue since ' + day(it.due_date)));
    main.append(text, meta);
    const del = el('button', 'cl-del'); del.type = 'button'; del.title = 'Remove step'; del.setAttribute('aria-label', 'Remove step: ' + it.text);
    const i = el('i', 'fa fa-xmark'); i.setAttribute('aria-hidden', 'true'); del.appendChild(i);
    del.addEventListener('click', () => { if (!window.ecwArm || window.ecwArm(del, 'Again to remove')) save('case_checklist_delete', { item_id: it.id }); });
    li.append(box, main, del);
    return li;
  }

  function editText(it, span) {
    const input = el('input', 'cl-edit'); input.type = 'text'; input.value = it.text; input.maxLength = 255;
    span.replaceWith(input); input.focus(); input.select();
    let done = false;
    const finish = (keep) => {
      if (done) return; done = true;
      const v = input.value.trim();
      if (keep && v && v !== it.text) save('case_checklist_update', { item_id: it.id, text: v }); else render();
    };
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') finish(true); if (e.key === 'Escape') finish(false); });
    input.addEventListener('blur', () => finish(true));
  }

  async function save(action, body) {
    try { items = (await api(action, body)).items || []; render(); }
    catch (e) { toast(e.message, true); load(); }
  }
  async function load() {
    try { items = (await api('case_checklist')).items || []; render(); } catch (_) {}
  }

  // ------------------------------------------------------------ add a step
  $('clAddForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const v = $('clAddText').value.trim();
    if (!v) return;
    $('clAddText').value = '';
    await save('case_checklist_add', { text: v });
    $('clAddText').focus();
  });

  // ------------------------------------------------------------ reorder (drag)
  let dragged = null;
  list.addEventListener('dragstart', (e) => { dragged = e.target.closest('.cl-item'); if (dragged) { dragged.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; } });
  list.addEventListener('dragover', (e) => {
    if (!dragged) return;
    e.preventDefault();
    const over = e.target.closest('.cl-item');
    if (!over || over === dragged) return;
    const r = over.getBoundingClientRect();
    list.insertBefore(dragged, e.clientY < r.top + r.height / 2 ? over : over.nextSibling);
  });
  list.addEventListener('dragend', () => {
    if (!dragged) return;
    dragged.classList.remove('dragging'); dragged = null;
    const ids = [...list.querySelectorAll('.cl-item')].map((li) => Number(li.dataset.id));
    if (ids.join() !== items.map((i) => i.id).join()) save('case_checklist_reorder', { ids });
  });

  // ------------------------------------------------------------ templates
  async function loadTemplates() {
    try {
      const { templates } = await api('case_templates');
      const sel = $('clTemplate');
      (templates || []).forEach((t) => sel.appendChild(new Option(t.name + ' (' + t.steps + ' steps)', t.id)));
      $('clTemplateRow').hidden = !(templates || []).length;
      if (!items.length) render();
    } catch (_) {}
  }
  $('clTemplateApply').addEventListener('click', async () => {
    const id = Number($('clTemplate').value);
    if (!id) { toast('Choose a template first', true); return; }
    try {
      const r = await api('case_template_apply', { template_id: id });
      items = r.items || []; render();
      const a = r.applied || {};
      toast(a.steps ? a.steps + ' step' + (a.steps === 1 ? '' : 's') + ' added' + (a.due ? ', due ' + day(a.due) : '') : 'Its steps were already here');
      $('clTemplate').value = '';
    } catch (e) { toast(e.message, true); }
  });

  // People to give steps to (the same list notes use for @mentions).
  api('org_users').then((d) => { people = d.users || []; render(); }).catch(() => {});
  load();
  loadTemplates();

  // A link to …#checklist (a reminder, "A step for you") opens the tab.
  if (location.hash === '#checklist') {
    setTimeout(() => {
      const panel = $('casePanel');
      if (panel && panel.hidden) $('casePanelToggle')?.click();
      document.querySelector('#casePanel [data-tab="checklist"]')?.click();
    }, 300);
  }
})();
