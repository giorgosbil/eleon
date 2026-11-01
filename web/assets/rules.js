/**
 * Settings → Rules editor: personal colour rules and (admins) automation rules
 * with several actions each. All text is written with textContent.
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const csrf = document.body.dataset.csrf;
  let meta = null;
  let editing = null;   // {kind, id?}
  let color = '';
  let lookups = { groups: [], users: [], cases: [], templates: [], mailboxes: [], folders: [] };

  async function api(action, params, body) {
    const url = new URL('../api/index.php', location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    const r = await fetch(url, body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf }, body: JSON.stringify(body) });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Request failed');
    return d;
  }
  const groupOf = (field) => (['subject', 'body', 'attachment', 'mailbox', 'folder'].includes(field) ? field : 'people');
  const userName = (id) => { const u = lookups.users.find((x) => x.id === id); return u ? (u.name || u.email) : 'someone'; };
  const ago = (utc) => {
    if (!utc) return '';
    const s = (Date.now() - new Date(String(utc).replace(' ', 'T') + 'Z').getTime()) / 1000;
    if (s < 3600) return Math.max(1, Math.round(s / 60)) + ' min ago';
    if (s < 86400) return Math.round(s / 3600) + ' h ago';
    return Math.round(s / 86400) + ' d ago';
  };

  function describe(rule) {
    const parts = (rule.conditions.items || []).map((c) => {
      if (c.field === 'attachment') return 'it has attachments';
      if (c.field === 'mailbox') return 'mailbox is ' + (lookups.mailboxes.find((m) => String(m.id) === String(c.value))?.email_address || '#' + c.value);
      if (c.field === 'folder') return 'it is in ' + (lookups.folders.find((f) => String(f.id) === String(c.value))?.label || 'a folder');
      return meta.fields[c.field] + ' ' + meta.ops[c.op] + (c.op === 'me' ? '' : ' “' + c.value + '”');
    });
    return parts.join(rule.conditions.match === 'any' ? ' or ' : ' and ');
  }
  function actionLabel(a) {
    switch (a.type) {
      case 'add_to_case': return 'add to ' + (lookups.cases.find((c) => c.id === a.case_id)?.case_number || 'case #' + a.case_id);
      case 'create_case': {
        const g = lookups.groups.find((x) => x.id === a.group_id)?.name;
        return 'new case' + (g ? ' in ' + g : '') + (a.owner_user_id ? ', Responsible ' + userName(a.owner_user_id) : '');
      }
      case 'set_owner': return 'Responsible ' + userName(a.user_id);
      case 'tag': return 'tag “' + a.tag + '”';
      case 'move': return 'move to ' + (lookups.folders.find((f) => f.id === a.folder_id)?.label || 'a folder');
      case 'snooze': return 'snooze ' + (a.hours % 24 === 0 ? a.hours / 24 + ' d' : a.hours + ' h');
      case 'notify': return 'notify ' + userName(a.user_id);
      case 'auto_reply': return 'auto-reply “' + (lookups.templates.find((t) => t.id === a.template_id)?.name || 'template') + '”';
      default: return (meta.actions[a.type] || a.type).toLowerCase();
    }
  }
  function actionText(rule) {
    if (rule.kind === 'color') return '';
    const acts = rule.actions.list || [];
    return '→ ' + acts.map(actionLabel).join(', ') + (rule.actions.first_only ? ' · first email only' : '') + (rule.actions.stop ? '' : ' · then continue');
  }

  function renderList(kind, rules) {
    const box = $(kind === 'color' ? 'colorRules' : 'routeRules');
    box.textContent = '';
    if (!rules.length) box.appendChild(el('p', 'muted small', 'No rules yet.'));
    rules.forEach((r) => {
      const card = el('div', 'rule-card');
      const sw = el('span', 'rule-swatch');
      if (kind === 'color') sw.style.setProperty('--rule-color', r.actions.color);
      else { sw.style.background = 'none'; const i = el('i', 'fa fa-wand-magic-sparkles'); i.setAttribute('aria-hidden', 'true'); sw.appendChild(i); }
      const text = el('span', 'rule-text');
      text.append(el('strong', '', r.name + (r.enabled ? '' : ' (paused)')), el('span', 'muted', describe(r) + ' ' + actionText(r)));
      if (kind === 'route') text.appendChild(el('span', 'rule-runs', r.runs?.count ? 'Ran ' + r.runs.count + ' time' + (r.runs.count === 1 ? '' : 's') + ' · last ' + ago(r.runs.last_at) : 'Has not run yet'));
      const edit = el('button', 'link-btn', 'Edit');
      edit.type = 'button';
      edit.addEventListener('click', () => openEditor(kind, r));
      card.append(sw, text);
      if (kind === 'route') {
        const earlier = el('button', 'link-btn', 'Run on earlier emails…');
        earlier.type = 'button';
        earlier.addEventListener('click', () => toggleEarlier(card, r));
        card.append(earlier);
      }
      card.append(edit);
      box.appendChild(card);
      if (kind === 'route' && r.earlier_running) toggleEarlier(card, r);
    });
  }

  // ------------------------------------------------- run on earlier emails

  /**
   * The panel under an automation rule: choose dates, see what matches, run it.
   * The background worker does the work (lib/rule_backfill.php); this polls the progress.
   */
  function toggleEarlier(card, rule) {
    if (card.nextElementSibling?.classList.contains('rule-earlier')) { card.nextElementSibling._stop?.(); card.nextElementSibling.remove(); return; }
    const box = el('div', 'rule-earlier');
    const day = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');   // the date here, not in UTC
    const ago = new Date(); ago.setMonth(ago.getMonth() - 3);
    const from = el('input'); from.type = 'date'; from.value = day(ago); from.max = day(new Date());
    const to = el('input'); to.type = 'date'; to.value = day(new Date()); to.max = day(new Date());
    const dates = el('div', 'rule-earlier-dates');
    const lf = el('label', '', 'From '); lf.append(from);
    const lt = el('label', '', 'Until '); lt.append(to);
    const check = el('button', '', 'Show what matches'); check.type = 'button';
    dates.append(lf, lt, check);
    const result = el('div', 'rule-earlier-result');
    const close = el('label', 'checkbox-label'); const closeBox = el('input'); closeBox.type = 'checkbox';
    close.append(closeBox, ' Open the cases as closed (history only: closed on the email’s date)');
    close.hidden = true;
    const run = el('button', 'primary', 'Run'); run.type = 'button'; run.hidden = true;
    const status = el('p', 'muted small'); status.setAttribute('role', 'status');
    box.append(el('p', 'muted small', 'Runs this rule on emails you already have, in every folder (Junk and Deleted Items only when the rule names them). '
      + 'One run per conversation; conversations it already handled are skipped. Nobody is emailed or notified about old mail.'), dates, result, close, run, status);
    card.after(box);

    let timer = null;
    box._stop = () => clearTimeout(timer);
    const progress = (job) => {
      if (!job) return;
      const pct = job.total ? Math.round(job.done / job.total * 100) : 0;
      result.textContent = '';
      const bar = el('div', 'rule-earlier-bar'); const fill = el('span'); fill.style.width = pct + '%'; bar.append(fill);
      const line = job.status === 'running'
        ? 'Running… ' + job.done + ' of ' + job.total + ' conversations'
        : (job.status === 'cancelled' ? 'Stopped after ' : 'Done: ') + job.done + ' of ' + job.total + ' conversations, ' + job.acted + ' changed' + (job.failed ? ', ' + job.failed + ' could not be done' : '') + '.';
      result.append(bar, el('p', job.status === 'done' ? 'rule-earlier-ok' : 'muted small', line));
      if (job.status === 'running') {
        const stop = el('button', 'link-btn', 'Stop'); stop.type = 'button';
        stop.addEventListener('click', async () => { clearTimeout(timer); try { progress((await api('rule_backfill_cancel', {}, { rule_id: rule.id })).run); } catch (e) { status.textContent = e.message; } });
        result.append(stop);
        timer = setTimeout(poll, 2000);
      }
    };
    const poll = async () => { if (!box.isConnected) return; try { progress((await api('rule_backfill_status', { rule_id: rule.id })).run); } catch (e) { status.textContent = e.message; } };
    if (rule.earlier_running) poll();

    check.addEventListener('click', async () => {
      check.disabled = true; status.textContent = 'Looking through your emails…'; run.hidden = true; close.hidden = true; result.textContent = '';
      try {
        const p = await api('rule_backfill_preview', { rule_id: rule.id, from: from.value, to: to.value });
        status.textContent = '';
        result.append(el('p', '', p.emails + (p.emails === 1 ? ' email' : ' emails') + ' in ' + p.conversations + (p.conversations === 1 ? ' conversation' : ' conversations') + ' match'
          + (p.already ? ' · ' + p.already + ' already handled by this rule' : '') + '.'));
        if (p.sample.length) {
          const ul = el('ul', 'rule-earlier-sample');
          p.sample.forEach((x) => {
            const li = el('li');
            li.append(el('strong', '', x.subject || '(no subject)'), el('span', 'muted small', ' · ' + x.from + ' · ' + String(x.at).slice(0, 10) + (x.folder ? ' · ' + x.folder : '')));
            ul.append(li);
          });
          result.append(ul);
        }
        if (p.left_out.length) result.append(el('p', 'muted small', 'Left out for old mail: ' + p.left_out.map((t) => (meta.actions[t] || t).toLowerCase()).join(', ') + '.'));
        if (p.too_many) result.append(el('p', 'muted small', 'Only the first ' + p.to_run + ' conversations run now. Run it again for the rest.'));
        if (p.capped) result.append(el('p', 'muted small', 'Very many emails in these dates: choose a shorter period to be sure every one is looked at.'));
        if (p.to_run > 0) {
          close.hidden = !p.opens_cases;
          run.hidden = false;
          run.textContent = 'Run on ' + p.to_run + (p.to_run === 1 ? ' conversation' : ' conversations');
        } else if (p.conversations) {
          result.append(el('p', 'muted small', 'Nothing left to do: this rule already handled all of them.'));
        }
      } catch (e) { status.textContent = e.message; }
      finally { check.disabled = false; }
    });
    run.addEventListener('click', async () => {
      run.disabled = true;
      try {
        const r = await api('rule_backfill_start', {}, { rule_id: rule.id, from: from.value, to: to.value, close_cases: closeBox.checked });
        run.hidden = true; close.hidden = true;
        progress(r.run);
      } catch (e) { status.textContent = e.message; }
      finally { run.disabled = false; }
    });
  }

  // ------------------------------------------------------------ conditions

  function condRow(c) {
    const row = el('div', 'cond-row');
    const field = el('select');
    Object.entries(meta.fields).forEach(([k, v]) => {
      // Colour rules see the list, not the message text, mailbox or folder.
      if (editing.kind === 'color' && ['body', 'attachment', 'mailbox', 'folder'].includes(k)) return;
      field.appendChild(new Option(v, k));
    });
    const op = el('select');
    const value = el('input');
    value.type = 'text'; value.maxLength = 200; value.placeholder = 'text, address or domain';
    const mailbox = el('select');
    lookups.mailboxes.forEach((m) => mailbox.appendChild(new Option(m.email_address, m.id)));
    // "Folder is": any folder of any mailbox, Sent and Junk included (not Drafts).
    const folder = el('select');
    lookups.folders.filter((f) => f.type !== 'drafts').forEach((f) => folder.appendChild(new Option(f.label, f.id)));
    const del = el('button', 'link-btn', 'Remove');
    del.type = 'button';
    del.addEventListener('click', () => row.remove());
    const fillOps = () => {
      const allowed = meta.field_ops[groupOf(field.value)] || Object.keys(meta.ops);
      const keep = op.value;
      op.textContent = '';
      allowed.forEach((k) => op.appendChild(new Option(meta.ops[k], k)));
      if (allowed.includes(keep)) op.value = keep;
    };
    const sync = () => {
      value.hidden = ['me', 'exists'].includes(op.value) || field.value === 'mailbox' || field.value === 'folder';
      mailbox.hidden = field.value !== 'mailbox';
      folder.hidden = field.value !== 'folder';
      if (field.value === 'body') value.placeholder = 'words in the message';
    };
    field.value = c?.field || 'to';
    fillOps();
    op.value = c?.op || (c ? 'contains' : 'me');
    if (!op.value) op.selectedIndex = 0;
    value.value = c?.value || '';
    if (c?.field === 'mailbox') mailbox.value = c.value;
    if (c?.field === 'folder') folder.value = c.value;
    field.addEventListener('change', () => { fillOps(); sync(); });
    op.addEventListener('change', sync);
    sync();
    row.append(field, op, value, mailbox, folder, del);
    row._get = () => ({ field: field.value, op: op.value, value: field.value === 'mailbox' ? mailbox.value : field.value === 'folder' ? folder.value : value.value });
    return row;
  }

  // ------------------------------------------------------------ actions

  function select(options, current, empty) {
    const s = el('select');
    if (empty) s.appendChild(new Option(empty, ''));
    options.forEach(([v, t]) => s.appendChild(new Option(t, v)));
    if (current !== undefined && current !== null) s.value = String(current);
    return s;
  }
  function actionRow(a) {
    const row = el('div', 'cond-row action-row');
    const type = select(Object.entries(meta.actions), a?.type || 'create_case');
    const opts = el('span', 'action-opts');
    const del = el('button', 'link-btn', 'Remove');
    del.type = 'button';
    del.addEventListener('click', () => row.remove());
    let get = () => ({});
    const people = lookups.users.map((u) => [u.id, u.name || u.email]);
    const draw = () => {
      opts.textContent = '';
      const t = type.value;
      if (t === 'create_case') {
        const g = select(lookups.groups.map((x) => [x.id, x.name]), a?.group_id, 'No group');
        const o = select(people, a?.owner_user_id, 'No Responsible (or the group picks)');
        opts.append(g, o);
        get = () => ({ group_id: Number(g.value) || null, owner_user_id: Number(o.value) || null });
      } else if (t === 'add_to_case') {
        const c = select(lookups.cases.map((x) => [x.id, x.case_number + ' · ' + x.name]), a?.case_id);
        opts.append(c);
        get = () => ({ case_id: Number(c.value) || null });
      } else if (t === 'set_owner' || t === 'notify') {
        const u = select(people, a?.user_id);
        opts.append(u);
        get = () => ({ user_id: Number(u.value) || null });
      } else if (t === 'tag') {
        const i = el('input'); i.type = 'text'; i.maxLength = 40; i.placeholder = 'tag'; i.value = a?.tag || '';
        opts.append(i);
        get = () => ({ tag: i.value });
      } else if (t === 'move') {
        const f = select(lookups.folders.filter((x) => !['sent', 'drafts'].includes(x.type)).map((x) => [x.id, x.label]), a?.folder_id);
        opts.append(f);
        get = () => ({ folder_id: Number(f.value) || null });
      } else if (t === 'snooze') {
        const s = select([[3, '3 hours'], [24, '1 day'], [72, '3 days'], [168, '1 week']], a?.hours || 24);
        opts.append(s);
        get = () => ({ hours: Number(s.value) });
      } else if (t === 'auto_reply') {
        const shared = lookups.templates.filter((x) => x.shared);
        if (!shared.length) opts.append(el('span', 'muted small', 'Make a shared reply template first (Settings → Reply templates).'));
        const s = select(shared.map((x) => [x.id, x.name]), a?.template_id);
        s.hidden = !shared.length;
        opts.append(s, el('span', 'muted small', 'Never to no-reply addresses; once per conversation and once a day per sender.'));
        get = () => ({ template_id: Number(s.value) || null });
      } else {
        get = () => ({});
      }
      a = null;   // the saved values only fill the first draw
    };
    type.addEventListener('change', draw);
    draw();
    row.append(type, opts, del);
    row._get = () => Object.assign({ type: type.value }, get());
    return row;
  }

  // ------------------------------------------------------------ editor

  function openEditor(kind, rule, preset) {
    editing = { kind, id: rule?.id };
    // Open the editor inside the section it belongs to, right under its rule list.
    $(kind === 'color' ? 'addColor' : 'addRoute').after($('ruleEditor'));
    // A rule the AI wrote has no id yet: it is a new rule to check and save.
    $('ruleEditorTitle').textContent = (rule?.id ? 'Edit ' : 'New ') + (kind === 'color' ? 'colour rule' : 'automation rule') + (rule && !rule.id && !preset ? ' · written by AI, check it' : '');
    $('ruleName').value = rule?.name || '';
    $('ruleMatch').value = rule?.conditions.match || 'all';
    $('ruleConds').textContent = '';
    (rule?.conditions.items || [null]).forEach((c) => $('ruleConds').appendChild(condRow(c)));
    $('ruleDisabled').checked = rule ? !rule.enabled : false;
    $('ruleDelete').hidden = !rule?.id;
    $('colorAction').hidden = kind !== 'color';
    $('routeAction').hidden = kind !== 'route';
    color = rule?.actions.color || meta.colors[0];
    drawSwatches();
    if (kind === 'route') {
      $('ruleActions').textContent = '';
      (rule?.actions.list?.length ? rule.actions.list : [{ type: 'create_case' }]).forEach((a) => $('ruleActions').appendChild(actionRow(a)));
      $('ruleStop').checked = rule ? rule.actions.stop !== false : true;
      $('ruleFirstOnly').checked = !!rule?.actions.first_only;
    }
    $('ruleStatus').textContent = '';
    $('ruleEditor').hidden = false;
    const presetInput = preset && $('ruleConds').querySelector('input[type="text"], input:not([type])');
    if (presetInput) presetInput.placeholder = 'e.g. support@yourcompany.com';
    (presetInput || $('ruleName')).focus();
    $('ruleEditor').scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function drawSwatches() {
    const box = $('colorAction');
    box.textContent = '';
    meta.colors.forEach((c) => {
      const b = el('button', c === color ? 'on' : '');
      b.type = 'button'; b.style.background = c; b.title = c; b.setAttribute('aria-label', 'Colour ' + c);
      b.addEventListener('click', () => { color = c; drawSwatches(); });
      box.appendChild(b);
    });
  }
  $('addCond').addEventListener('click', () => $('ruleConds').appendChild(condRow({ field: 'from', op: 'contains', value: '' })));
  $('addAction').addEventListener('click', () => {
    if ($('ruleActions').children.length >= (meta.max_actions || 8)) { $('ruleStatus').textContent = 'That is the most actions one rule can have.'; return; }
    $('ruleActions').appendChild(actionRow({ type: 'notify' }));
  });
  $('ruleCancel').addEventListener('click', () => { $('ruleEditor').hidden = true; });
  $('addColor').addEventListener('click', () => openEditor('color', null));
  $('addRoute').addEventListener('click', () => openEditor('route', null));
  // Preset: every new email to one address (support@…) opens a case. Mail already in a case
  // (a reply, or one naming its case number) is left alone by the create_case action.
  $('addIntake')?.addEventListener('click', () => openEditor('route', {
    name: 'New emails to support become cases',
    enabled: true,
    conditions: { match: 'all', items: [{ field: 'to_cc', op: 'is', value: '' }] },
    actions: { list: [{ type: 'create_case' }], stop: true, first_only: false },
  }, true));

  // "Describe it": the AI writes the rule (lib/agents.php), the editor opens with it to check.
  const wish = $('ruleWish');
  if (wish) {
    const go = async () => {
      const text = $('ruleWishText').value.trim();
      if (!text) { $('ruleWishText').focus(); return; }
      const btn = $('ruleWishGo');
      btn.disabled = true; $('ruleWishStatus').textContent = 'Writing the rule…';
      try {
        if (!lookups.loaded) await loadLookups();
        const { rule } = await api('rule_from_text', {}, { text });
        $('ruleWishStatus').textContent = rule.explanation ? '✓ ' + rule.explanation : '✓ Check it below and save.';
        openEditor(rule.kind, rule);
      } catch (err) { $('ruleWishStatus').textContent = err.message; }
      finally { btn.disabled = false; }
    };
    $('ruleWishGo').addEventListener('click', go);
    $('ruleWishText').addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); go(); } });
  }

  $('ruleEditor').addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = {
      id: editing.id, kind: editing.kind, name: $('ruleName').value, disabled: $('ruleDisabled').checked,
      conditions: { match: $('ruleMatch').value, items: [...$('ruleConds').children].map((r) => r._get()) },
      actions: editing.kind === 'color' ? { color }
        : { list: [...$('ruleActions').children].map((r) => r._get()), stop: $('ruleStop').checked, first_only: $('ruleFirstOnly').checked },
    };
    try { await api('rule_save', {}, body); $('ruleEditor').hidden = true; load(); }
    catch (err) { $('ruleStatus').textContent = err.message; }
  });
  $('ruleDelete').addEventListener('click', async () => {
    if ($('ruleDelete').dataset.confirm !== '1') { $('ruleDelete').dataset.confirm = '1'; $('ruleDelete').textContent = 'Click again to delete'; return; }
    try { await api('rule_delete', {}, { id: editing.id }); $('ruleEditor').hidden = true; load(); }
    catch (err) { $('ruleStatus').textContent = err.message; }
    $('ruleDelete').dataset.confirm = ''; $('ruleDelete').textContent = 'Delete';
  });

  async function loadLookups() {
    const [g, u, c, t, m] = await Promise.all([
      api('case_groups').catch(() => ({ groups: [] })), api('org_users'), api('cases', { status: 'all' }).catch(() => ({ cases: [] })),
      api('templates').catch(() => ({ templates: [] })), api('mailboxes').catch(() => ({ mailboxes: [] })),
    ]);
    lookups = {
      loaded: true, groups: g.groups || [], users: u.users || [],
      cases: (c.cases || []).map((x) => ({ id: Number(x.id), case_number: x.case_number, name: x.name })),
      templates: t.templates || [], mailboxes: m.mailboxes || [], folders: [],
    };
    // Folders of every mailbox, for "Move to a folder" (named "mailbox › folder" when there are several).
    const many = lookups.mailboxes.length > 1;
    for (const mb of lookups.mailboxes) {
      try {
        const f = await api('folders', { mailbox_id: mb.id });
        const walk = (list, prefix) => (list || []).forEach((x) => {
          lookups.folders.push({ id: Number(x.id), type: x.folder_type, label: (many ? mb.email_address + ' › ' : '') + prefix + x.name });
          walk(x.children, prefix + x.name + ' › ');
        });
        walk(f.folders, '');
      } catch (_) { /* a mailbox this admin cannot open */ }
    }
  }

  async function load() {
    meta = await api('rules');
    if (meta.can_route && !lookups.loaded) await loadLookups();
    $('routeSection').hidden = !meta.can_route;
    $('colorSection').hidden = meta.can_color === false;
    renderList('color', meta.color);
    if (meta.can_route) renderList('route', meta.route);
  }
  load().catch((e) => { $('colorRules').textContent = e.message; });
})();
