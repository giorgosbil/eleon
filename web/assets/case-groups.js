/** Settings → Case groups: create, edit, delete groups and pick their members. */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const csrf = document.body.dataset.csrf;
  const me = Number(document.body.dataset.user);
  const isAdmin = document.body.dataset.admin === '1';
  let groups = [];
  let users = [];
  let accounts = [];
  let editing = null;   // group id, or null for a new group

  async function api(action, params, body) {
    const url = new URL('../api/index.php', window.location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    const init = body === undefined ? {} : {
      method: 'POST', headers: { 'X-ECW-CSRF': csrf, 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    };
    const response = await fetch(url.toString(), init);
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'request failed (' + response.status + ')');
    return data;
  }

  const canEdit = (g) => !g || isAdmin || g.created_by_user_id === me;

  function renderList() {
    const list = $('groupList');
    list.textContent = '';
    if (!groups.length) {
      const li = document.createElement('li');
      li.className = 'muted';
      li.textContent = 'No groups yet.';
      list.appendChild(li);
    }
    groups.forEach((g) => {
      const li = document.createElement('li');
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'case-group-item' + (g.id === editing ? ' active' : '');
      const badge = document.createElement('span');
      badge.className = 'group-badge';
      badge.style.setProperty('--group-color', g.color || '#94a3b8');
      badge.textContent = g.name;
      const stack = document.createElement('span');
      stack.className = 'avatar-stack';
      g.members.slice(0, 6).forEach((m) => stack.appendChild(window.ecwAvatar(m, 22)));
      const count = document.createElement('span');
      count.className = 'muted';
      count.textContent = g.members.length + (g.members.length === 1 ? ' member' : ' members');
      // Its kind and its owner: "Only me · yours" or "Company · by Maria".
      const share = document.createElement('span');
      share.className = 'cg-share muted small';
      const kind = document.createElement('i');
      kind.className = 'fa ' + ({ personal: 'fa-lock', members: 'fa-user-group', company: 'fa-building' })[g.scope];
      kind.setAttribute('aria-hidden', 'true');
      share.append(kind, ' ' + ({ personal: 'Only me', members: 'Members', company: 'Company' })[g.scope] + ' · ' + (g.created_by_user_id === me ? 'yours' : 'by ' + (g.owner_name || 'someone who left'))
        + (({ suggest: ' · suggests incoming email', auto: ' · files incoming email' })[g.rules?.action] || ''));
      const top = document.createElement('span');
      top.className = 'cg-item-top';
      top.append(badge, g.scope === 'members' ? count : document.createTextNode(''));
      btn.append(top);
      if (g.scope === 'members') btn.append(stack);
      btn.appendChild(share);
      btn.addEventListener('click', () => edit(g.id));
      li.appendChild(btn);
      list.appendChild(li);
    });
  }

  function renderPeople(selectedIds, disabled) {
    const list = $('groupPeople');
    list.textContent = '';
    users.forEach((u) => {
      const li = document.createElement('li');
      li.dataset.search = ((u.name || '') + ' ' + (u.email || '')).toLowerCase();
      const label = document.createElement('label');
      const box = document.createElement('input');
      box.type = 'checkbox';
      box.value = u.id;
      box.checked = selectedIds.has(u.id);
      box.disabled = disabled;
      const text = document.createElement('span');
      text.className = 'person-label';
      const name = document.createElement('span');
      name.textContent = u.name || u.email;
      const mail = document.createElement('span');
      mail.className = 'muted';
      mail.textContent = u.email;
      text.append(name, mail);
      label.append(box, window.ecwAvatar(u, 28), text);
      li.appendChild(label);
      list.appendChild(li);
    });
  }

  function edit(id) {
    editing = id;
    const g = groups.find((x) => x.id === id) || null;
    const editable = canEdit(g);
    $('groupFormTitle').textContent = g ? g.name : 'New group';
    $('groupName').value = g ? g.name : '';
    $('groupColor').value = g?.color || '#4f7cff';
    $('groupAutoAssign').checked = !!g?.auto_assign;
    const rules = g?.rules || { ai: '', words: '', mailbox_id: null, action: 'off' };
    $('groupRuleAi').value = rules.ai;
    // What the AI learned for this group.
    const l = g?.learned;
    $('groupLearned').hidden = !l;
    if (l) {
      $('groupLearned').textContent = 'Learned from ' + l.cases + (l.cases === 1 ? ' case' : ' cases') + ' in this group'
        + (l.yes ? ', ' + l.yes + ' suggestion' + (l.yes === 1 ? '' : 's') + ' you accepted' : '')
        + (l.no ? ', and ' + l.no + ' email' + (l.no === 1 ? '' : 's') + ' you said were not for it' + (l.not_for.length ? ' (latest: “' + l.not_for[0] + '”)' : '') : '') + '.';
    }
    $('groupRuleWords').value = rules.words;
    $('groupRuleMailbox').value = rules.mailbox_id ? String(rules.mailbox_id) : '';
    document.querySelectorAll('input[name="groupRuleAction"]').forEach((r) => { r.checked = r.value === rules.action; r.disabled = !editable; });
    $('groupRuleAi').disabled = $('groupRuleWords').disabled = $('groupRuleMailbox').disabled = !editable;
    document.querySelectorAll('input[name="groupScope"]').forEach((r) => { r.checked = r.value === (g?.scope || 'company'); r.disabled = !editable; });
    $('groupFormTitle').textContent = g ? g.name + (g.created_by_user_id === me ? '' : ' · by ' + (g.owner_name || 'someone who left')) : 'New group';
    syncScope();
    $('groupAutoAssign').disabled = !editable;
    $('groupName').disabled = $('groupColor').disabled = !editable;
    $('groupSave').textContent = g ? 'Save changes' : 'Create group';
    $('groupSave').disabled = !editable;
    $('groupDelete').hidden = !g || !editable;
    $('groupStatus').textContent = editable ? '' : 'Only the person who created this group or an admin can change it.';
    renderPeople(new Set((g?.members || []).map((m) => m.id)), !editable);
    renderList();
  }

  $('groupForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = {
      id: editing || undefined,
      name: $('groupName').value,
      color: $('groupColor').value,
      auto_assign: $('groupAutoAssign').checked,
      scope: document.querySelector('input[name="groupScope"]:checked')?.value || 'company',
      member_ids: [...$('groupPeople').querySelectorAll('input:checked')].map((b) => Number(b.value)),
      rules: {
        ai: $('groupRuleAi').value,
        words: $('groupRuleWords').value,
        mailbox_id: Number($('groupRuleMailbox').value) || null,
        action: document.querySelector('input[name="groupRuleAction"]:checked')?.value || 'off',
      },
    };
    try {
      const data = await api('case_group_save', {}, body);
      groups = (await api('case_groups')).groups;   // with what each group learned
      edit(data.group_id);
      $('groupStatus').textContent = 'Saved.';
    } catch (err) { $('groupStatus').textContent = err.message; }
  });

  $('groupNew').addEventListener('click', () => { edit(null); $('groupName').focus(); });

  /** The people list belongs to "Members" only (Company: everyone; Only me: nobody else). */
  function syncScope() {
    $('groupMembersBox').hidden = document.querySelector('input[name="groupScope"]:checked')?.value !== 'members';
  }
  document.querySelectorAll('input[name="groupScope"]').forEach((r) => r.addEventListener('change', syncScope));

  $('groupDelete').addEventListener('click', async () => {
    const g = groups.find((x) => x.id === editing);
    if (!g) return;
    if ($('groupDelete').dataset.confirm !== '1') {
      $('groupDelete').dataset.confirm = '1';
      $('groupDelete').textContent = 'Click again to delete “' + g.name + '”';
      setTimeout(() => { $('groupDelete').dataset.confirm = ''; $('groupDelete').textContent = 'Delete group'; }, 4000);
      return;
    }
    try {
      const data = await api('case_group_delete', { group_id: g.id }, {});
      groups = data.groups;
      edit(null);
      $('groupStatus').textContent = 'Group deleted. Its cases are kept, without a group.';
    } catch (err) { $('groupStatus').textContent = err.message; }
    $('groupDelete').dataset.confirm = '';
    $('groupDelete').textContent = 'Delete group';
  });

  $('groupSearch').addEventListener('input', (e) => {
    const q = e.target.value.trim().toLowerCase();
    $('groupPeople').querySelectorAll('li').forEach((li) => { li.hidden = q !== '' && !li.dataset.search.includes(q); });
  });

  Promise.all([api('case_groups'), api('org_users')])
    .then(([g, u]) => {
      groups = g.groups; users = u.users; accounts = g.accounts || [];
      accounts.forEach((a) => { const o = document.createElement('option'); o.value = String(a.id); o.textContent = a.email; $('groupRuleMailbox').appendChild(o); });
      edit(null);
    })
    .catch((err) => { $('groupStatus').textContent = err.message; });
})();
