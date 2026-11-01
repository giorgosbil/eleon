/*
 * The AI agents' suggestions (lib/agents.php): cards with one-click actions.
 *   Mail:  above an open conversation (#threadSuggestions), and all of them
 *          from "Suggestions" in the folder list.
 *   Cases: in the Summary tab (#caseSuggestions), with the living summary.
 * An action first says exactly what it will change (lib/agents.php,
 * ecw_suggestion_action_preview) and runs only after "Do it"; the server then
 * runs it with the usual permission checks. × dismisses. In the full list,
 * suggestions about the same case or conversation are grouped.
 * All text is written with textContent.
 */
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const csrf = document.body.dataset.csrf;
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined && text !== null) n.textContent = text; return n; };
  const icon = (cls) => { const i = el('i', cls); i.setAttribute('aria-hidden', 'true'); return i; };
  async function api(action, params, body) {
    const url = new URL('api/index.php', location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v)); });
    const r = await fetch(url, body === undefined ? { credentials: 'same-origin' } : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf }, body: JSON.stringify(body) });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) { const e = new Error(d.error || 'Request failed'); e.status = r.status; throw e; }
    return d;
  }
  const toast = (msg, err) => (window.ecwMail?.toast ? window.ecwMail.toast(msg, err) : window.ecwPageToast?.(msg, err));
  const ICON = { triage: 'fa-inbox', promises: 'fa-handshake', chaser: 'fa-clock-rotate-left', unanswered: 'fa-reply', drafter: 'fa-pen-nib', patterns: 'fa-chart-line', summary: 'fa-file-lines' };
  let disabled = false;

  /** One suggestion card. onGone() runs after it is accepted or dismissed. */
  function card(s, onGone, opts = {}) {
    const c = el('article', 'ai-sugg ai-sugg-' + s.agent);
    const head = el('div', 'ai-sugg-head');
    const badge = el('span', 'ai-sugg-icon'); badge.appendChild(icon('fa ' + (ICON[s.agent] || 'fa-lightbulb')));
    const title = el('strong', 'ai-sugg-title', s.title);
    // Triage: "Complaint · urgent · reply by Thu 31 Dec" as the kind, then tags.
    if (s.agent === 'triage' && String(s.title || '').includes(' · ')) {
      const [kind, ...rest] = String(s.title).split(' · ');
      title.replaceChildren(document.createTextNode(kind));
      const tags = el('span', 'ai-sugg-tags');
      rest.forEach((t) => tags.appendChild(el('span', 'ai-sugg-tag' + (/^urgent$/i.test(t) ? ' urgent' : /^reply by|^due/i.test(t) ? ' due' : ''), t)));
      title.appendChild(tags);
    }
    const x = el('button', 'ai-sugg-x'); x.type = 'button'; x.title = 'Dismiss'; x.setAttribute('aria-label', 'Dismiss this suggestion');
    x.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
    head.append(badge, title, x);
    c.appendChild(head);
    if (opts.showEmail && (s.subject || s.from)) {
      const src = el('a', 'ai-sugg-src', [s.from, s.subject].filter(Boolean).join(' · '));
      if (s.thread_id && s.mailbox_id) src.href = 'app.php?mailbox=' + s.mailbox_id + '&thread=' + s.thread_id + (s.message_id ? '#msg-' + s.message_id : '');
      c.appendChild(src);
    }
    if (s.body) c.appendChild(el('p', 'ai-sugg-body', s.body));
    if (s.facts && s.facts.length) {
      const f = el('div', 'ai-sugg-facts');
      s.facts.forEach((x2) => { const chip = el('span', 'ai-sugg-fact'); chip.append(el('span', '', x2.label), document.createTextNode(' ' + x2.value)); f.appendChild(chip); });
      c.appendChild(f);
    }
    const acts = el('div', 'ai-sugg-acts');
    // "This will …" before anything changes; Do it / Cancel.
    const confirmBox = el('div', 'ai-sugg-confirm');
    confirmBox.hidden = true;
    (s.actions || []).forEach((a, i) => {
      const b = el('button', 'ai-sugg-act' + (i === 0 ? ' primary' : ''), a.label); b.type = 'button';
      b.addEventListener('click', () => {
        if (!a.preview) { run(); return; }   // only opens something
        confirmBox.replaceChildren();
        const what = el('p', '');
        what.append(el('strong', '', 'This will: '), document.createTextNode(a.preview));
        const go = el('button', 'ai-sugg-act primary', 'Do it'); go.type = 'button';
        const no = el('button', 'ai-sugg-act', 'Cancel'); no.type = 'button';
        go.addEventListener('click', () => { confirmBox.hidden = true; acts.hidden = false; run(); });
        no.addEventListener('click', () => { confirmBox.hidden = true; acts.hidden = false; b.focus(); });
        const row = el('div', 'ai-sugg-acts'); row.append(go, no);
        confirmBox.append(what, row);
        confirmBox.hidden = false; acts.hidden = true;
        go.focus();
      });
      const run = async () => {
        acts.querySelectorAll('button').forEach((x3) => { x3.disabled = true; });
        const was = b.textContent;
        if (a.type === 'draft_reply') b.textContent = 'Writing…';
        try {
          const r = await api('suggestion_act', {}, { id: s.id, action_index: i });
          toast(r.done || 'Done.');
          c.classList.add('gone');
          setTimeout(() => { c.remove(); onGone?.(); }, 250);
          // In the mail: a case started or joined shows on the conversation and its row at once,
          // and the email stays open (Open Case, also in a new tab, goes there).
          const inMail = !!window.ecwMail?.threadCaseChanged && s.thread_id && ['new_case', 'link_case', 'unlink', 'undo_case'].includes(a.type);
          if (inMail) window.ecwMail.threadCaseChanged(s.thread_id);
          if (r.url && !inMail && !['remind', 'note', 'set_due', 'unlink'].includes(a.type)) {
            const here = location.pathname.split('/').pop() + location.search;
            if (!r.url.endsWith(here)) location.href = r.url;
          }
        } catch (e) {
          toast(e.message, true);
          b.textContent = was;
          acts.querySelectorAll('button').forEach((x3) => { x3.disabled = false; });
        }
      };
      acts.appendChild(b);
    });
    if (acts.children.length) c.append(acts, confirmBox);
    x.addEventListener('click', async () => {
      c.classList.add('gone');
      try { await api('suggestion_dismiss', {}, { id: s.id }); } catch (_) {}
      setTimeout(() => { c.remove(); onGone?.(); }, 250);
    });
    return c;
  }

  function fill(host, list, opts) {
    host.replaceChildren();
    list.forEach((s) => host.appendChild(card(s, () => { if (!host.children.length) host.hidden = true; refreshCount(); }, opts)));
    host.hidden = !list.length;
  }

  // ---------------------------------------------------------------- mail
  const threadHost = $('threadSuggestions');
  if (threadHost) {
    const loadThread = async (id) => {
      if (disabled || !id) return 0;
      try {
        const { suggestions } = await api('suggestions', { thread_id: id });
        if (window.ecwMail?.state?.thread?.id === id || !window.ecwMail) fill(threadHost, suggestions.slice(0, 3));
        return suggestions.length;
      } catch (err) { if (err.status === 403) disabled = true; return 0; }
    };
    document.addEventListener('ecw:thread-open', (e) => {
      threadHost.hidden = true; threadHost.replaceChildren();
      loadThread((e.detail || {}).id);
    });
    // ai-working.js: the AI just finished reading this conversation's new email.
    window.ecwSuggestionsReload = (id) => { refreshCount(); return loadThread(id); };
  }

  // All of them, from "Suggestions" in the folder list.
  const navBtn = $('suggestionsBtn');
  let count = 0;
  async function refreshCount() {
    if (!navBtn || disabled) return;
    try {
      const { suggestions } = await api('suggestions');
      count = suggestions.length;
      $('suggestionsCount').textContent = count ? String(count) : '';
      navBtn.hidden = count === 0 && !navBtn.dataset.everShown;
      if (count) navBtn.dataset.everShown = '1';
    } catch (err) { if (err.status === 403) { disabled = true; navBtn.hidden = true; } }
  }
  if (navBtn) {
    refreshCount();
    setInterval(() => { if (!document.hidden) refreshCount(); }, 60000);
    navBtn.addEventListener('click', openPanel);
  }
  let panel = null;
  async function openPanel() {
    if (!panel) {
      panel = el('section', 'ai-sugg-panel');
      panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Suggestions');
      const head = el('header', 'ai-sugg-panel-head');
      const mark = el('span', 'ai-mark ai-mark-sm'); mark.appendChild(icon('ecw-ai-icon'));
      const close = el('button', 'ai-head-btn ai-sugg-panel-x'); close.type = 'button'; close.setAttribute('aria-label', 'Close'); close.title = 'Close';
      close.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
      close.addEventListener('click', () => { panel.hidden = true; });
      const t = el('div', 'ai-titles'); t.append(el('strong', '', 'Suggestions'), el('span', 'ai-sub', 'From the AI agents · accept or dismiss'));
      head.append(mark, t, close);
      const list = el('div', 'ai-sugg-panel-list');
      panel.append(head, list);
      panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') panel.hidden = true; });
      document.body.appendChild(panel);
    }
    const list = panel.querySelector('.ai-sugg-panel-list');
    list.replaceChildren(el('p', 'muted', 'Loading…'));
    panel.hidden = false;
    try {
      const { suggestions } = await api('suggestions');
      list.replaceChildren();
      if (!suggestions.length) list.appendChild(el('p', 'ai-sugg-empty', 'Nothing to look at right now. New suggestions appear here as mail arrives.'));
      // One group per case (or per conversation outside a case): the newest suggestion
      // shows, the others fold under "N more about this".
      const groups = new Map();
      suggestions.forEach((s) => {
        const key = s.case_id ? 'c' + s.case_id : (s.thread_id ? 't' + s.thread_id : 's' + s.id);
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(s);
      });
      const done = () => { refreshCount(); if (!list.querySelector('.ai-sugg')) { list.replaceChildren(el('p', 'ai-sugg-empty', 'All done.')); } };
      groups.forEach((items) => {
        const g = el('div', 'ai-sugg-group');
        const first = items[0];
        if (items.length > 1) g.appendChild(el('div', 'ai-sugg-group-head', (first.case_number ? first.case_number + ' · ' : '') + (first.subject || first.title) + ' — ' + items.length + ' suggestions'));
        const rest = el('div', 'ai-sugg-group-rest');
        items.forEach((s, i) => (i === 0 ? g : rest).appendChild(card(s, () => { if (!g.querySelector('.ai-sugg')) g.remove(); done(); }, { showEmail: i === 0 })));
        if (items.length > 1) {
          rest.hidden = true;
          const more = el('button', 'ai-sugg-more', (items.length - 1) + ' more about this'); more.type = 'button';
          more.addEventListener('click', () => { rest.hidden = !rest.hidden; more.textContent = rest.hidden ? (items.length - 1) + ' more about this' : 'Show fewer'; });
          g.append(more, rest);
        }
        list.appendChild(g);
      });
    } catch (e) { list.replaceChildren(el('p', 'muted', e.message)); }
  }

  // --------------------------------------------------------------- cases
  const caseHost = $('caseSuggestions');
  const caseId = Number(document.body.dataset.case || 0);
  if (caseHost && caseId) {
    api('suggestions', { case_id: caseId }).then(({ suggestions }) => {
      fill(caseHost, suggestions);
      const tab = document.querySelector('.case-panel-tabs [data-tab="summary"]');
      if (tab && suggestions.length) { const b = el('span', 'tab-count', String(suggestions.length)); tab.appendChild(b); }
    }).catch(() => {});
    api('case_living_summary', { case_id: caseId }).then(({ summary }) => {
      const box = $('livingSummary');
      if (!box || !summary) return;
      box.replaceChildren();
      const h = el('div', 'living-summary-head');
      h.append(icon('ecw-ai-icon'), el('strong', '', 'Where it stands'), el('span', 'muted small', ' · updated ' + ago(summary.updated_at) + (summary.model ? ' · ' + summary.model : '')));
      box.appendChild(h);
      String(summary.summary).split('\n').forEach((line) => {
        const t = line.trim();
        if (!t) return;
        const m = t.match(/^(Status|Open|Waiting|Next|Κατάσταση|Ανοιχτά|Αναμονή|Επόμενο)\s*:\s*(.*)$/i);
        if (m) { const p = el('p'); p.append(el('strong', '', m[1] + ': '), document.createTextNode(m[2])); box.appendChild(p); }
        else box.appendChild(el('p', t.startsWith('-') ? 'living-bullet' : '', t.replace(/^-\s*/, '• ')));
      });
      box.hidden = false;
    }).catch(() => {});
  }
  function ago(utc) {
    const s = Math.max(0, (Date.now() - new Date(String(utc).replace(' ', 'T') + 'Z').getTime()) / 1000);
    return s < 90 ? 'just now' : s < 3600 ? Math.round(s / 60) + ' min ago' : s < 86400 ? Math.round(s / 3600) + ' h ago' : Math.round(s / 86400) + ' d ago';
  }
})();
