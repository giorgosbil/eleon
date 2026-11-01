/**
 * Email Case Workspace — case group, sharing and people pickers.
 *
 * Driven by two events from case.js: `ecw:case-summary` (every time the
 * summary is (re)rendered) and `ecw:org-users` (once, the active members with
 * their Outlook photos). Responsible / Handled by stay real <select>s — case.js
 * still reads and writes them — this file only hides them behind a picker that
 * can show photos, and fires `change` on the select when someone is chosen.
 *
 * XSS: every name and email is written with textContent.
 */
(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const caseId = Number(document.body.dataset.case);
  const csrf = document.body.dataset.csrf;
  let summary = null;
  let users = [];
  let groups = [];

  async function api(action, params, body) {
    const url = new URL('api/index.php', window.location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    const init = body === undefined ? {} : {
      method: 'POST',
      headers: { 'X-ECW-CSRF': csrf, 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    };
    const response = await fetch(url.toString(), init);
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'request failed (' + response.status + ')');
    return data;
  }

  function toast(message, isError) {
    const el = $('toast');
    if (!el) return;
    $('toastMsg').textContent = message;
    el.classList.toggle('error', !!isError);
    el.hidden = false;
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => { el.hidden = true; }, 3000);
  }

  const avatar = (person, size) => window.ecwAvatar(person, size);   // assets/avatars.js

  const byId = (id) => users.find((u) => u.id === Number(id));

  // ------------------------------------------- Responsible / Handled by picker

  const pickers = [];

  function enhanceSelect(select) {
    if (!select || select.dataset.enhanced) return;
    select.dataset.enhanced = '1';
    select.hidden = true;

    const root = document.createElement('div');
    root.className = 'person-picker';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'person-picker-btn';
    button.setAttribute('aria-haspopup', 'listbox');
    const menu = document.createElement('ul');
    menu.className = 'person-picker-menu';
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;
    root.append(button, menu);
    select.after(root);

    function option(person) {
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.tabIndex = 0;
      const label = document.createElement('span');
      label.className = 'person-label';
      if (person) {
        li.appendChild(avatar(person, 24));
        label.textContent = person.name || person.email;
        li.title = person.email;
      } else {
        const blank = document.createElement('span');
        blank.className = 'avatar avatar-empty';
        blank.style.width = blank.style.height = '24px';
        li.appendChild(blank);
        label.textContent = 'Unassigned';
      }
      li.appendChild(label);
      const choose = () => {
        menu.hidden = true;
        const value = person ? String(person.id) : '';
        if (select.value !== value) {
          select.value = value;
          select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        draw();
      };
      li.addEventListener('click', choose);
      li.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(); } });
      return li;
    }

    function draw() {
      button.textContent = '';
      const person = byId(select.value);
      if (person) {
        button.appendChild(avatar(person, 22));
        const name = document.createElement('span');
        name.textContent = person.name || person.email;
        button.appendChild(name);
      } else {
        button.appendChild(document.createTextNode('Unassigned'));
      }
      const caret = document.createElement('i');
      caret.className = 'fa fa-chevron-down';
      caret.setAttribute('aria-hidden', 'true');
      button.appendChild(caret);
      menu.textContent = '';
      menu.appendChild(option(null));
      users.forEach((u) => menu.appendChild(option(u)));
    }

    button.addEventListener('click', () => { menu.hidden = !menu.hidden; });
    document.addEventListener('click', (e) => { if (!root.contains(e.target)) menu.hidden = true; });
    root.addEventListener('keydown', (e) => { if (e.key === 'Escape') { menu.hidden = true; button.focus(); } });
    pickers.push(draw);
    draw();
  }

  // ------------------------------------------------------------ group select

  function drawGroupSelect() {
    const select = $('caseGroup');
    if (!select) return;
    const current = summary?.sharing?.group?.id || '';
    select.textContent = '';
    select.appendChild(new Option('No group', ''));
    groups.forEach((g) => select.appendChild(new Option(g.name, g.id)));
    if (window.ecwFeatures?.case_groups !== false) {
      const manage = new Option('Manage groups…', '__manage');
      manage.className = 'select-action';
      select.appendChild(manage);
    }
    select.value = String(current);
  }

  // ---------------------------------------------------------------- sharing

  function sharingLabel() {
    const btn = $('caseSharingBtn');
    if (!btn || !summary) return;
    const sharing = summary.sharing || { visibility: 'organization', members: [] };
    btn.textContent = '';
    if (sharing.visibility === 'organization') {
      const icon = document.createElement('i');
      icon.className = 'fa fa-building';
      icon.setAttribute('aria-hidden', 'true');
      btn.append(icon, document.createTextNode(' Everyone'));
      btn.title = 'Everyone in the workspace can see this case';
      return;
    }
    const people = accessList();
    const stack = document.createElement('span');
    stack.className = 'avatar-stack';
    people.slice(0, 5).forEach((p) => stack.appendChild(avatar(p, 22)));
    btn.appendChild(stack);
    const lock = document.createElement('i');
    lock.className = 'fa fa-lock';
    lock.setAttribute('aria-hidden', 'true');
    btn.append(lock, document.createTextNode(' ' + people.length + (people.length === 1 ? ' person' : ' people')
      + (sharing.group ? ' + ' + sharing.group.name : '')));
    btn.title = 'Only these people can see this case: ' + people.map((p) => p.name || p.email).join(', ');
  }

  /** Everyone with access to a members-only case, de-duplicated. */
  function accessList() {
    const ids = new Set();
    const out = [];
    const add = (p) => { if (p && !ids.has(p.id)) { ids.add(p.id); out.push(p); } };
    add(byId(summary.created_by_user_id));
    add(byId(summary.owner_user_id));
    add(byId(summary.handler_user_id));
    (summary.sharing?.members || []).forEach((m) => add(byId(m.id) || m));
    const group = groups.find((g) => g.id === summary.sharing?.group?.id);
    (group?.members || []).forEach(add);
    return out;
  }

  function roleOf(userId) {
    const roles = [];
    if (userId === summary.created_by_user_id) roles.push('Creator');
    if (userId === summary.owner_user_id) roles.push('Responsible');
    if (userId === summary.handler_user_id) roles.push('Handled by');
    const group = groups.find((g) => g.id === summary.sharing?.group?.id);
    if (group && group.members.some((m) => m.id === userId)) roles.push(group.name);
    return roles;
  }

  function openSharing() {
    if (!summary) return;
    const sharing = summary.sharing || { visibility: 'organization', members: [] };
    document.querySelectorAll('input[name="caseVisibility"]').forEach((r) => { r.checked = r.value === sharing.visibility; });
    const selected = new Set((sharing.members || []).map((m) => m.id));
    const list = $('sharingPeople');
    list.textContent = '';
    users.forEach((u) => {
      const li = document.createElement('li');
      li.dataset.search = ((u.name || '') + ' ' + (u.email || '')).toLowerCase();
      const label = document.createElement('label');
      const box = document.createElement('input');
      box.type = 'checkbox';
      box.value = u.id;
      const roles = roleOf(u.id);
      box.checked = selected.has(u.id) || roles.length > 0;
      box.disabled = roles.length > 0;   // already inside through their role
      const text = document.createElement('span');
      text.className = 'person-label';
      const name = document.createElement('span');
      name.textContent = u.name || u.email;
      const sub = document.createElement('span');
      sub.className = 'muted';
      sub.textContent = roles.length ? roles.join(', ') : u.email;
      text.append(name, sub);
      label.append(box, avatar(u, 28), text);
      li.appendChild(label);
      list.appendChild(li);
    });
    $('sharingSearch').value = '';
    const group = summary.sharing?.group;
    $('sharingGroupNote').hidden = !group;
    if (group) $('sharingGroupNote').textContent = 'Members of the group “' + group.name + '” also have access. Change the group next to “Group”.';
    syncSharingBox();
    $('sharingOverlay').hidden = false;
  }

  function syncSharingBox() {
    const members = document.querySelector('input[name="caseVisibility"]:checked')?.value === 'members';
    $('sharingPeopleBox').classList.toggle('disabled', !members);
    $('sharingPeople').querySelectorAll('input').forEach((box) => {
      if (!roleOf(Number(box.value)).length) box.disabled = !members;
    });
  }

  async function saveSharing(changes) {
    const current = summary.sharing || { visibility: 'organization', members: [], group: null };
    const body = Object.assign({
      visibility: current.visibility,
      group_id: current.group ? current.group.id : null,
      member_ids: (current.members || []).map((m) => m.id),
    }, changes);
    const data = await api('case_sharing_save', { case_id: caseId }, body);
    summary.sharing = data.sharing;
    render();
  }

  // ------------------------------------------------------------------ render

  function render() {
    pickers.forEach((draw) => draw());
    drawGroupSelect();
    sharingLabel();
  }

  document.addEventListener('ecw:case-summary', (e) => {
    summary = e.detail;
    render();
  });

  document.addEventListener('ecw:org-users', (e) => {
    users = e.detail || [];
    enhanceSelect($('caseOwner'));
    enhanceSelect($('caseHandler'));
    render();
  });

  function wire() {
    api('case_groups', {}).then((d) => { groups = d.groups || []; render(); }).catch(() => {});

    $('caseGroup')?.addEventListener('change', async (e) => {
      if (e.target.value === '__manage') {
        drawGroupSelect();   // put the real group back before leaving
        window.location.href = 'settings/case-groups.php';
        return;
      }
      try {
        await saveSharing({ group_id: e.target.value ? Number(e.target.value) : null });
        toast('Group updated');
      } catch (err) { toast(err.message, true); drawGroupSelect(); }
    });

    $('caseSharingBtn')?.addEventListener('click', openSharing);
    // Teams: the colleagues on this case (creator, Responsible, Handled by, members, group).
    $('caseTeamsBtn')?.addEventListener('click', (e) => {
      if (!summary || !window.ecwTeams) return;
      const team = accessList();
      const people = team.length > 1 ? team : team.concat(users);   // no team yet: offer any colleague
      window.ecwTeams.menu(e.currentTarget, people, summary.number + ' ' + summary.name);
    });
    $('sharingCancel').addEventListener('click', () => { $('sharingOverlay').hidden = true; });
    document.querySelectorAll('input[name="caseVisibility"]').forEach((r) => r.addEventListener('change', syncSharingBox));
    $('sharingSearch').addEventListener('input', (e) => {
      const q = e.target.value.trim().toLowerCase();
      $('sharingPeople').querySelectorAll('li').forEach((li) => { li.hidden = q !== '' && !li.dataset.search.includes(q); });
    });
    $('sharingSave').addEventListener('click', async () => {
      const visibility = document.querySelector('input[name="caseVisibility"]:checked')?.value || 'organization';
      const memberIds = [...$('sharingPeople').querySelectorAll('input:checked')]
        .filter((box) => !roleOf(Number(box.value)).length)
        .map((box) => Number(box.value));
      $('sharingSave').disabled = true;
      try {
        await saveSharing({ visibility, member_ids: memberIds });
        $('sharingOverlay').hidden = true;
        toast(visibility === 'members' ? 'Only the selected people can see this case now' : 'Everyone in the workspace can see this case');
      } catch (err) { toast(err.message, true); }
      finally { $('sharingSave').disabled = false; }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wire); else wire();
})();
