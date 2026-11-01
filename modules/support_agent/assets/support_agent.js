/**
 * Support agent (modules/support_agent), on every page for people with the feature:
 * a small robot button that sends the open conversation (mail UI) or the case
 * (case page) to the support agent. Shown only to admins, once Settings →
 * Support agent is connected; for everyone else this script draws nothing.
 */
(function () {
  'use strict';
  const base = window.ecwWebBase || '';
  const csrf = () => document.body.dataset.csrf || '';
  const caseId = Number(document.body.dataset.case || 0);
  const threadToolbar = () => document.querySelector('#threadView .thread-actions .mail-action-group');
  const caseToolbar = document.querySelector('.case-top-actions');
  if (!caseToolbar && !document.getElementById('threadView')) return;

  async function api(action, params, body) {
    const url = new URL(base + 'api/index.php', location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    const res = await fetch(url, body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf() }, body: JSON.stringify(body) });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Request failed (' + res.status + ')');
    return data;
  }

  function toast(text, link, bad) {
    let t = document.querySelector('.sa-toast');
    if (!t) { t = document.createElement('div'); t.className = 'sa-toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.textContent = text;
    t.classList.toggle('bad', !!bad);
    if (link) { const a = document.createElement('a'); a.textContent = 'Open in the agent'; a.href = link; a.target = '_blank'; a.rel = 'noopener'; t.append(' ', a); }
    t.hidden = false;
    clearTimeout(t._t); t._t = setTimeout(() => { t.hidden = true; }, 9000);
  }

  function makeButton(cls) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = cls + ' sa-send';
    b.title = 'Send to the support agent: it drafts the problem and its solution, and learns it after you approve it there';
    b.setAttribute('aria-label', 'Send to the support agent');
    b.innerHTML = '<i class="fa fa-robot" aria-hidden="true"></i>';
    return b;
  }

  let connected = false;

  async function send(button, action, params) {
    if (button.disabled) return;
    if (!connected) {
      toast('The support agent is not connected yet. Connect it in Settings → Support agent.', null, true);
      const t = document.querySelector('.sa-toast');
      const a = document.createElement('a'); a.textContent = 'Open settings'; a.href = base + 'settings/module.php?m=support_agent&p=support_agent';
      t.append(' ', a);
      return;
    }
    button.disabled = true;
    button.classList.add('busy');
    try {
      const r = await api(action, params, {});
      toast(r.message || 'Sent to the support agent.', r.url);
      button.classList.add('done');
    } catch (e) {
      toast(e.message, null, true);
    } finally {
      button.disabled = false;
      button.classList.remove('busy');
    }
  }

  api('sa_state').then((s) => {
    // Admins always see it (so they can find it); until it is connected, it says where to connect.
    if (!s.admin) return;
    connected = !!s.configured;
    if (caseToolbar && caseId) {
      const b = makeButton('case-icon-btn');
      b.addEventListener('click', () => send(b, 'sa_send_case', { case_id: caseId }));
      caseToolbar.insertBefore(b, document.getElementById('caseDeleteBtn') || null);
    }
    // The mail UI: one button in the conversation's toolbar, following whichever conversation is open.
    let current = 0;
    const place = () => {
      const bar = threadToolbar();
      if (!bar || bar.querySelector('.sa-send')) return;
      const b = makeButton('thread-icon-btn');
      b.addEventListener('click', () => { if (current) send(b, 'sa_send_thread', { thread_id: current }); });
      bar.appendChild(b);
    };
    document.addEventListener('ecw:thread-open', (e) => {
      current = Number(e.detail && e.detail.id || 0);
      place();
      document.querySelector('#threadView .sa-send')?.classList.remove('done');
    });
  }).catch(() => {});
})();
