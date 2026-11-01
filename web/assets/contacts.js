/**
 * Contacts page: list of companies / people on the left, a card on the right
 * with stats, cases, conversations, people (for a company) and editable
 * details. URL keeps the selection: contacts.php?company=acme.com or ?email=…
 * All text is written with textContent.
 */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const icon = (name) => { const i = el('i', 'fa ' + name); i.setAttribute('aria-hidden', 'true'); return i; };
  const csrf = document.body.dataset.csrf;
  let tab = 'companies';
  let searchTimer = null;
  let current = null;   // {kind, key}

  async function api(action, params, body) {
    const url = new URL('api/index.php', location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    const r = await fetch(url, body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf }, body: JSON.stringify(body) });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Request failed');
    return d;
  }
  const when = (utc) => {
    if (!utc) return '—';
    const d = new Date(String(utc).replace(' ', 'T') + 'Z');
    const days = Math.floor((Date.now() - d.getTime()) / 86400000);
    if (days < 1) return 'today';
    if (days < 2) return 'yesterday';
    if (days < 30) return days + ' days ago';
    return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: days > 330 ? 'numeric' : undefined });
  };
  const initials = (name, email) => ((name || email || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('') || '?').toUpperCase();

  // ------------------------------------------------------------------ list

  async function loadList() {
    const list = $('contactList');
    const q = $('contactSearch').value.trim();
    list.setAttribute('aria-busy', 'true');
    try {
      const d = tab === 'companies' ? await api('contacts_companies', { q }) : await api('contacts_people', { q });
      const rows = tab === 'companies' ? d.companies : d.people;
      list.textContent = '';
      if (!rows.length) list.appendChild(el('li', 'contacts-none', q ? 'Nothing matches “' + q + '”.' : 'Nobody yet — they appear as mail arrives.'));
      rows.forEach((r) => {
        const li = el('li');
        const b = el('button', 'contact-row');
        b.type = 'button';
        const key = tab === 'companies' ? r.domain : r.email;
        b.dataset.key = key;
        if (current && current.key === key) b.classList.add('active');
        const badge = el('span', 'contact-badge' + (tab === 'companies' ? ' company' : ''), tab === 'companies' ? initials(r.name) : initials(r.name, r.email));
        const text = el('span', 'contact-row-text');
        text.append(el('strong', '', r.name || r.email), el('span', 'muted', tab === 'companies'
          ? r.domain + ' · ' + r.people + (r.people === 1 ? ' person' : ' people')
          : (r.name ? r.email : (r.job_title || r.domain))));
        const meta = el('span', 'contact-row-meta', when(r.last_at));
        b.append(badge, text, meta);
        b.addEventListener('click', () => open(tab === 'companies' ? 'company' : 'person', key));
        li.appendChild(b);
        list.appendChild(li);
      });
    } catch (e) {
      list.textContent = '';
      list.appendChild(el('li', 'contacts-none', e.message));
    } finally { list.removeAttribute('aria-busy'); }
  }
  document.querySelectorAll('.contacts-tabs [data-tab]').forEach((b) => b.addEventListener('click', () => {
    tab = b.dataset.tab;
    document.querySelectorAll('.contacts-tabs [data-tab]').forEach((x) => { const on = x === b; x.classList.toggle('active', on); x.setAttribute('aria-selected', String(on)); });
    loadList();
  }));
  $('contactSearch').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(loadList, 250); });

  // ------------------------------------------------------------------ card

  async function open(kind, key, push) {
    current = { kind, key };
    document.querySelectorAll('.contact-row').forEach((r) => r.classList.toggle('active', r.dataset.key === key));
    if (push !== false) history.replaceState(null, '', 'contacts.php?' + (kind === 'company' ? 'company=' : 'email=') + encodeURIComponent(key));
    const main = $('contactMain');
    main.setAttribute('aria-busy', 'true');
    document.body.classList.add('contact-open');
    try {
      const d = kind === 'company' ? await api('contact_company', { domain: key }) : await api('contact_person', { email: key });
      draw(kind, d);
    } catch (e) {
      main.textContent = '';
      main.appendChild(el('div', 'contacts-empty', e.message === 'not found' ? 'Nothing here you can see.' : e.message));
    } finally { main.removeAttribute('aria-busy'); }
  }

  function stat(label, value) {
    const s = el('div', 'contact-stat');
    s.append(el('strong', '', String(value)), el('span', '', label));
    return s;
  }

  function draw(kind, d) {
    const main = $('contactMain');
    main.textContent = '';
    const back = el('button', 'contacts-back');
    back.type = 'button';
    back.append(icon('fa-arrow-left'), el('span', '', 'Contacts'));
    back.addEventListener('click', () => document.body.classList.remove('contact-open'));
    main.appendChild(back);

    // Head
    const head = el('header', 'contact-head');
    const badge = el('span', 'contact-badge big' + (kind === 'company' ? ' company' : ''), kind === 'company' ? initials(d.name) : initials(d.name, d.email));
    const title = el('div', 'contact-title');
    title.appendChild(el('h1', '', d.name || d.email));
    const sub = el('p', 'muted');
    if (kind === 'company') {
      sub.append(document.createTextNode(d.domain));
      if (d.website) { const a = el('a', '', ' · ' + d.website.replace(/^https?:\/\//, '')); a.href = d.website; a.target = '_blank'; a.rel = 'noopener noreferrer'; sub.appendChild(a); }
    } else {
      const mail = el('a', '', d.email); mail.href = 'mailto:' + d.email; sub.appendChild(mail);
      if (d.job_title) sub.append(document.createTextNode(' · ' + d.job_title));
      if (d.company) {
        sub.append(document.createTextNode(' · '));
        const c = el('button', 'link-btn', d.company.name);
        c.type = 'button';
        c.addEventListener('click', () => open('company', d.company.domain));
        sub.appendChild(c);
      }
    }
    if (d.phone) { sub.append(document.createTextNode(' · ')); const t = el('a', '', d.phone); t.href = 'tel:' + d.phone.replace(/[^\d+]/g, ''); sub.appendChild(t); }
    title.appendChild(sub);
    const edit = el('button', 'contact-edit-btn');
    edit.type = 'button';
    edit.append(icon('fa-pen'), el('span', '', 'Edit details'));
    head.append(badge, title, edit);
    main.appendChild(head);

    // Stats
    const stats = el('div', 'contact-stats');
    stats.append(stat('conversations', d.conversations.length + (d.conversations.length >= 40 ? '+' : '')), stat('open cases', d.stats.open_cases),
      stat('emails from them', d.stats.received), stat('emails to them', d.stats.sent),
      stat('last from them', when(d.stats.last_from_them)), stat('last from us', when(d.stats.last_to_them)));
    main.appendChild(stats);

    // Notes / edit form
    const form = editForm(kind, d);
    if (d.notes) {
      const n = el('div', 'contact-notes');
      n.append(icon('fa-note-sticky'), el('p', '', d.notes));
      main.appendChild(n);
    }
    main.appendChild(form);
    edit.addEventListener('click', () => { form.hidden = !form.hidden; if (!form.hidden) form.querySelector('input').focus(); });

    const grid = el('div', 'contact-grid');
    // Cases
    const cases = el('section', 'contact-section');
    cases.appendChild(el('h2', '', 'Cases'));
    if (!d.cases.length) cases.appendChild(el('p', 'muted', 'No case yet.'));
    d.cases.forEach((c) => {
      const a = el('a', 'contact-case' + (c.status === 'closed' ? ' closed' : ''));
      a.href = 'case.php?id=' + c.id;
      a.append(el('span', 'case-badge', c.number), el('span', 'contact-case-name', c.name), el('span', 'contact-status ' + c.status, c.status));
      cases.appendChild(a);
    });
    grid.appendChild(cases);
    // People (company)
    if (kind === 'company') {
      const people = el('section', 'contact-section');
      people.appendChild(el('h2', '', 'People'));
      d.people.forEach((p) => {
        const b = el('button', 'contact-person');
        b.type = 'button';
        b.append(el('span', 'contact-badge small', initials(p.name, p.email)), el('span', 'contact-row-text'), el('span', 'contact-row-meta', when(p.last_at)));
        b.children[1].append(el('strong', '', p.name || p.email), el('span', 'muted', p.job_title || p.email));
        b.addEventListener('click', () => open('person', p.email));
        people.appendChild(b);
      });
      grid.appendChild(people);
    }
    main.appendChild(grid);
    // Conversations
    const conv = el('section', 'contact-section wide');
    conv.appendChild(el('h2', '', 'Conversations'));
    d.conversations.forEach((t) => {
      const a = el('a', 'contact-conv');
      a.href = 'app.php?thread=' + t.id + '&mailbox=' + t.mailbox_id;
      a.append(icon('fa-envelope'), el('span', 'contact-conv-subject', t.subject || '(no subject)'));
      if (t.case) a.appendChild(el('span', 'case-badge', t.case.number));
      a.append(el('span', 'muted', t.emails + (t.emails === 1 ? ' email' : ' emails')), el('span', 'contact-row-meta', when(t.last_at)));
      conv.appendChild(a);
    });
    main.appendChild(conv);
    main.scrollTop = 0;
  }

  function editForm(kind, d) {
    const form = el('form', 'contact-form');
    form.hidden = true;
    form.autocomplete = 'off';
    const field = (label, name, value, type) => {
      const l = el('label', '', label);
      const i = el(type === 'textarea' ? 'textarea' : 'input');
      if (type !== 'textarea') i.type = type || 'text';
      i.name = name; i.value = value || '';
      if (type === 'textarea') i.rows = 3;
      l.appendChild(i);
      return l;
    };
    form.appendChild(field(kind === 'company' ? 'Company name' : 'Name', 'name', kind === 'company' ? (d.named ? d.name : '') : d.name));
    if (kind === 'person') form.appendChild(field('Job title', 'job_title', d.job_title));
    form.appendChild(field('Phone', 'phone', d.phone, 'tel'));
    if (kind === 'company') form.appendChild(field('Website', 'website', d.website, 'url'));
    form.appendChild(field('Notes (everyone in the workspace sees them)', 'notes', d.notes, 'textarea'));
    const row = el('div', 'contact-form-row');
    const save = el('button', 'primary', 'Save');
    save.type = 'submit';
    const status = el('span', 'muted');
    row.append(save, status);
    form.appendChild(row);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(form).entries());
      save.disabled = true;
      try {
        await api(kind === 'company' ? 'contact_company_save' : 'contact_person_save', kind === 'company' ? { domain: d.domain } : { email: d.email }, body);
        await open(kind, current.key, false);
        loadList();
      } catch (err) { status.textContent = err.message; }
      finally { save.disabled = false; }
    });
    return form;
  }

  // ------------------------------------------------------------------ start
  const params = new URLSearchParams(location.search);
  if (params.get('email')) { tab = 'people'; document.querySelector('.contacts-tabs [data-tab="people"]').click(); open('person', params.get('email'), false); }
  else { loadList(); if (params.get('company')) open('company', params.get('company'), false); }
})();
