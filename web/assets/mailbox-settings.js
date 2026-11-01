/**
 * Settings → Mailbox: Automatic replies (out of office) and Blocked senders,
 * per mailbox you connected. Talks to api auto_reply_get / auto_reply_save and
 * blocked_senders / sender_block / sender_unblock. Text is written with
 * textContent; the reply message is plain text turned into simple HTML.
 */
(function () {
  'use strict';
  const base = window.ecwWebBase || '';
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  async function api(action, mailboxId, body) {
    const r = await fetch(base + 'api/index.php?action=' + action + '&mailbox_id=' + mailboxId, body === undefined ? {} : {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': window.ecwCsrf || '' }, body: JSON.stringify(body),
    });
    const d = await r.json().catch(() => ({ error: 'Unexpected answer from the server.' }));
    if (!r.ok) { const e = new Error(d.error || 'Request failed'); e.data = d; throw e; }
    return d;
  }
  const htmlToText = (html) => {
    const doc = new DOMParser().parseFromString(String(html || '').replace(/<br\s*\/?>/gi, '\n').replace(/<\/(p|div)>/gi, '\n'), 'text/html');
    return (doc.body.textContent || '').replace(/\n{3,}/g, '\n\n').trim();
  };
  const textToHtml = (text) => String(text || '').split(/\n{2,}/).map((p) => '<p>' + p.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])).replace(/\n/g, '<br>') + '</p>').join('');
  const local = (utc) => { if (!utc) return ''; const d = new Date(String(utc).replace(' ', 'T') + 'Z'); const p = (n) => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()); };

  // ---------------------------------------------------------- automatic replies
  async function autoReply(box) {
    const id = box.dataset.mailbox, google = box.dataset.provider === 'google';
    const body = box.querySelector('[data-ar]'), badge = box.querySelector('[data-ar-badge]');
    if (window.ecwFeatures?.auto_reply === false) return;   // not in the package / switched off / hidden by the view profile
    let s;
    try { s = (await api('auto_reply_get', id)).auto_reply; }
    catch (e) { return consentOrError(body, e); }
    badge.hidden = s.status === 'off';
    badge.textContent = s.status === 'scheduled' ? 'Scheduled' : 'On';

    const form = el('form', 'ar-form');
    const modes = el('div', 'ar-modes');
    [['off', 'Off'], ['on', 'On'], ['scheduled', 'Only between these times']].forEach(([v, label]) => {
      const l = el('label', 'ar-mode'); const r = el('input'); r.type = 'radio'; r.name = 'status-' + id; r.value = v; r.checked = s.status === v;
      l.append(r, el('span', '', label)); modes.appendChild(l);
    });
    const times = el('div', 'ar-times');
    const start = el('input'); start.type = 'datetime-local'; start.value = local(s.start);
    const end = el('input'); end.type = 'datetime-local'; end.value = local(s.end);
    const ls = el('label', '', 'From'); ls.appendChild(start);
    const le = el('label', '', 'Until'); le.appendChild(end);
    times.append(ls, le);
    const subject = el('input'); subject.type = 'text'; subject.maxLength = 200; subject.value = s.subject || 'Out of office';
    const lsub = el('label', '', 'Subject'); lsub.appendChild(subject);
    const internal = el('textarea'); internal.rows = 5; internal.value = htmlToText(s.internal);
    internal.placeholder = 'Thank you for your email. I am out of the office until … and will reply when I am back.';
    const lin = el('label', '', google ? 'Message' : 'Message to people in your organisation'); lin.appendChild(internal);
    const external = el('textarea'); external.rows = 5; external.value = htmlToText(s.external);
    const lex = el('label', '', 'Message to people outside your organisation'); lex.appendChild(external);
    const audience = el('select', 'ot-select');
    [['all', 'Everyone who writes'], ['contacts', 'Only my contacts'], ['none', google ? 'Only people in my domain' : 'Nobody outside the organisation']].forEach(([v, t]) => { const o = el('option', '', t); o.value = v; o.selected = s.audience === v; audience.appendChild(o); });
    const lau = el('label', '', google ? 'Reply to' : 'Reply outside the organisation to'); lau.appendChild(audience);
    const save = el('button', 'primary', 'Save'); save.type = 'submit';
    const status = el('span', 'muted small'); status.setAttribute('role', 'status');
    const row = el('div', 'ar-row'); row.append(save, status);
    form.append(modes, times, ...(google ? [lsub, lin, lau] : [lin, lau, lex]), row);
    const paint = () => {
      const mode = form.querySelector('input[type=radio]:checked')?.value || 'off';
      times.hidden = mode !== 'scheduled';
      form.querySelectorAll('textarea, select, input[type=text]').forEach((x) => { x.disabled = mode === 'off'; });
      lex.hidden = google || audience.value === 'none';
    };
    form.addEventListener('change', paint);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      save.disabled = true; status.textContent = 'Saving…';
      const mode = form.querySelector('input[type=radio]:checked')?.value || 'off';
      try {
        const r = await api('auto_reply_save', id, {
          status: mode, start: start.value ? new Date(start.value).toISOString() : '', end: end.value ? new Date(end.value).toISOString() : '',
          internal: textToHtml(internal.value), external: textToHtml(external.value || internal.value), audience: audience.value, subject: subject.value,
        });
        badge.hidden = r.auto_reply.status === 'off';
        badge.textContent = r.auto_reply.status === 'scheduled' ? 'Scheduled' : 'On';
        status.textContent = mode === 'off' ? 'Automatic replies are off.' : 'Saved — people who write get your reply.';
      } catch (err) { if (err.data?.needs_consent) return consentOrError(body, err); status.textContent = err.message; }
      finally { save.disabled = false; }
    });
    body.replaceChildren(form);
    paint();
  }
  function consentOrError(body, e) {
    body.replaceChildren(el('p', '', e.message));
    if (e.data?.needs_consent && e.data.connect_url) {
      const a = el('a', 'button primary', 'Allow automatic replies');
      a.href = e.data.connect_url;
      body.appendChild(a);
    }
  }

  // ---------------------------------------------------------- blocked senders
  async function blocked(box) {
    const id = box.dataset.mailbox, body = box.querySelector('[data-bl]'), count = box.querySelector('[data-bl-count]');
    const draw = (list) => {
      count.textContent = list.length ? '(' + list.length + ')' : '';
      const ul = el('ul', 'bl-list');
      list.forEach((b) => {
        const li = el('li'); li.append(el('span', '', b.pattern));
        const x = el('button', 'link-btn', 'Unblock'); x.type = 'button';
        x.addEventListener('click', async () => { try { draw((await api('sender_unblock', id, { pattern: b.pattern })).blocked); } catch (e) { alert(e.message); } });
        li.appendChild(x); ul.appendChild(li);
      });
      if (!list.length) ul.appendChild(el('li', 'muted', 'Nobody is blocked. Use “Block sender” on an email, or add one here.'));
      const add = el('form', 'bl-add');
      const input = el('input'); input.type = 'text'; input.placeholder = 'name@example.com or @example.com'; input.setAttribute('aria-label', 'Address or domain to block');
      const b = el('button', '', 'Block'); b.type = 'submit';
      const err = el('span', 'muted small');
      add.append(input, b, err);
      add.addEventListener('submit', async (e) => { e.preventDefault(); try { draw((await api('sender_block', id, { pattern: input.value })).blocked); } catch (ex) { err.textContent = ex.message; } });
      body.replaceChildren(el('p', 'muted small', 'New mail from these addresses is moved to Junk while the app is running.'), ul, add);
    };
    try { draw((await api('blocked_senders', id)).blocked); } catch (e) { body.replaceChildren(el('p', '', e.message)); }
  }

  document.querySelectorAll('.mailbox-extra').forEach((box) => { autoReply(box); blocked(box); });
})();
