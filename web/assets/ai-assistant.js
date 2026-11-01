/*
 * The email assistant panel (Mail and Cases): a small chat. The server
 * returns plain text (with **bold**, "- " bullets and [M42] email labels),
 * which is drawn here with textContent only, never as HTML. Only the explicit
 * "Insert into reply" button touches a draft.
 */
(() => {
  let panel, ui, context = {}, sequence = 0, modelLabel = '', lastKey = '';
  let turns = [];   // this conversation's questions and answers, so follow-ups make sense
  const make = (tag, cls, text) => { const el = document.createElement(tag); if (cls) el.className = cls; if (text !== undefined && text !== null) el.textContent = text; return el; };
  const icon = (cls) => { const i = make('i', cls); i.setAttribute('aria-hidden', 'true'); return i; };
  const api = async (action, data) => {
    const response = await fetch('api/index.php?action=' + action, data === undefined ? { credentials: 'same-origin' } : {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': document.body.dataset.csrf }, body: JSON.stringify(data) });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.error || 'The assistant could not complete this request.');
    return result;
  };

  // ------------------------------------------------------------------ build
  function build() {
    panel = make('section', 'ai-assistant');
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Email assistant');

    const head = make('header', 'ai-head');
    const mark = make('span', 'ai-mark'); mark.appendChild(icon('ecw-ai-icon'));
    const titles = make('div', 'ai-titles');
    const sub = make('span', 'ai-sub', '');
    titles.append(make('strong', '', 'Assistant'), sub);
    const fresh = make('button', 'ai-head-btn'); fresh.type = 'button'; fresh.title = 'New conversation'; fresh.setAttribute('aria-label', 'New conversation');
    fresh.innerHTML = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.4 3.6a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
    fresh.addEventListener('click', () => { sequence++; drawEmpty(); ui.prompt.focus(); });
    const close = make('button', 'ai-head-btn'); close.type = 'button'; close.setAttribute('aria-label', 'Close assistant'); close.title = 'Close';
    close.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
    close.addEventListener('click', hide);
    head.append(mark, titles, fresh, close);

    const thread = make('div', 'ai-thread');
    thread.setAttribute('aria-live', 'polite');

    const foot = make('footer', 'ai-compose');
    const narrow = make('details', 'ai-narrow');
    const narrowSum = make('summary', '', 'Narrow the search');
    const search = make('input'); search.type = 'search'; search.maxLength = 300;
    search.placeholder = 'from:Nikos  subject:quote  filename:.pdf  case:CASE-00003';
    search.setAttribute('aria-label', 'Search terms');
    narrow.append(narrowSum, search);
    const box = make('div', 'ai-input');
    const prompt = make('textarea'); prompt.rows = 1; prompt.maxLength = 1000; prompt.placeholder = 'Ask about your emails…';
    prompt.setAttribute('aria-label', 'Your question');
    const send = make('button', 'ai-send'); send.type = 'button'; send.setAttribute('aria-label', 'Send'); send.title = 'Send (Enter)';
    send.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
    box.append(prompt, send);
    const hint = make('p', 'ai-hint', 'The assistant can make mistakes — check before you use an answer.');
    foot.append(narrow, box, hint);

    panel.append(head, thread, foot);
    document.body.appendChild(panel);
    ui = { sub, thread, search, narrow, prompt, send };

    const grow = () => { prompt.style.height = 'auto'; prompt.style.height = Math.min(160, prompt.scrollHeight) + 'px'; };
    prompt.addEventListener('input', grow);
    prompt.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); ask(); }
    });
    send.addEventListener('click', () => ask());
    panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); hide(); } });

    api('ai_status').then((s) => {
      modelLabel = s.provider === 'openai' ? (s.model || 'Your AI') : 'Claude Haiku 4.5';
      ui.sub.textContent = (s.configured ? modelLabel : 'Not set up yet') + (s.provider === 'openai' ? ' · on your server' : '');
    }).catch(() => {});
  }

  function hide() { panel.hidden = true; sequence++; }

  // ---------------------------------------------------------- empty state
  function suggestions() {
    if (context.mode === 'reply') return ['Draft a short, friendly reply', 'Reply and confirm we will send the quote by Friday', 'Reply in Greek'];
    const c = (context.query || '').match(/case:(\S+)/i);
    if (c) return ['Which files were exchanged in ' + c[1] + '?', 'Where does ' + c[1] + ' stand and what is still open?', 'What is the customer waiting for in ' + c[1] + '?'];
    return ['Find all files from the last emails with attachments', 'What did the customer ask for in the latest quote request?', 'Which emails are waiting for our reply?'];
  }
  function drawEmpty() {
    turns = [];
    ui.thread.replaceChildren();
    const empty = make('div', 'ai-empty');
    const big = make('span', 'ai-mark ai-mark-big'); big.appendChild(icon('ecw-ai-icon'));
    empty.append(big,
      make('h3', '', context.mode === 'reply' ? 'Draft a reply' : 'Ask about your emails'),
      make('p', 'muted', context.mode === 'reply'
        ? 'Uses this email and up to five earlier ones in the conversation. You review and send it yourself.'
        : 'Ask about a case, a person, a quote or a file. Only the matching emails are read.'));
    const chips = make('div', 'ai-suggest');
    suggestions().forEach((s) => {
      const b = make('button', 'ai-chip', s); b.type = 'button';
      b.addEventListener('click', () => { ui.prompt.value = s; ask(); });
      chips.appendChild(b);
    });
    empty.appendChild(chips);
    ui.thread.appendChild(empty);
  }

  // --------------------------------------------------------------- asking
  const STEPS = ['Understanding the question', 'Searching your emails', 'Reading the emails', 'Writing the answer'];
  async function ask() {
    const question = ui.prompt.value.trim();
    if (!question) { ui.prompt.focus(); return; }
    if (ui.send.disabled) return;
    ui.thread.querySelector('.ai-empty')?.remove();
    const request = ++sequence;
    const snapshot = { ...context, query: ui.search.value.trim() };

    const mine = make('div', 'ai-msg ai-user'); mine.appendChild(make('div', 'ai-bubble', question));
    const bot = make('div', 'ai-msg ai-bot');
    const avatar = make('span', 'ai-mark ai-mark-sm'); avatar.appendChild(icon('ecw-ai-icon'));
    const card = make('div', 'ai-card');
    bot.append(avatar, card);
    ui.thread.append(mine, bot);
    ui.prompt.value = ''; ui.prompt.style.height = 'auto';
    ui.send.disabled = true;

    // Thinking: questions stream the agent's real steps (searching, counting,
    // reading…); a reply draft is one request, so its steps just advance.
    const think = make('div', 'ai-thinking');
    const list = make('ol', 'ai-steps');
    const clock = make('span', 'ai-clock', '0 s');
    const thinkHead = make('div', 'ai-think-head');
    thinkHead.append(make('span', 'ai-shimmer', 'Thinking'), clock);
    think.append(thinkHead, list);
    card.appendChild(think);
    bot.classList.add('is-busy');
    scroll();
    const started = performance.now();
    const live = snapshot.mode !== 'reply';
    const items = live ? [] : STEPS.map((s) => { const li = make('li', '', s); list.appendChild(li); return li; });
    let at = 0;
    const tick = setInterval(() => {
      const s = (performance.now() - started) / 1000;
      clock.textContent = Math.floor(s) + ' s';
      if (live) return;
      const want = s < 0.6 ? 0 : s < 1.6 ? 1 : s < 3 ? 2 : 3;
      while (at < want) { items[at].className = 'done'; at++; }
      items[at].className = 'active';
    }, 150);
    if (!live) items[0].className = 'active';
    const stepEls = new Map();
    const onStep = (ev) => {
      let li = stepEls.get(ev.id);
      if (ev.state === 'hide') { li?.remove(); stepEls.delete(ev.id); return; }
      if (!li) { li = make('li'); stepEls.set(ev.id, li); list.appendChild(li); }
      li.className = ev.state === 'done' ? 'done' : 'active';
      li.replaceChildren(document.createTextNode(ev.label));
      if (ev.detail) li.appendChild(make('span', 'ai-step-detail', ev.detail));
      scroll();
    };

    try {
      const result = live
        ? await agent({ mailbox_id: snapshot.mailboxId, question, query: snapshot.query, history: turns.slice(-3) }, onStep, () => request === sequence)
        : await api('ai_assist', { mailbox_id: snapshot.mailboxId, message_id: snapshot.messageId || 0, mode: snapshot.mode, question, query: snapshot.query });
      if (request !== sequence) return;
      clearInterval(tick);
      const secs = Math.max(1, Math.round((performance.now() - started) / 1000));
      card.replaceChildren(trace(result, secs), answer(result, snapshot));
      turns.push({ q: question, a: (result.text || '') + (result.files ? '\nFiles: ' + result.files.slice(0, 20).map((f) => f.filename).join(', ') : '')
        + (result.actions ? '\nProposed (waiting for the person to confirm): ' + result.actions.map((a) => a.summary).join(' | ') : '') });
    } catch (e) {
      clearInterval(tick);
      if (request !== sequence) return;
      card.replaceChildren();
      const err = make('div', 'ai-error');
      err.append(icon('fa fa-circle-exclamation'), make('span', '', e.message));
      const retry = make('button', 'ai-act', 'Try again'); retry.type = 'button';
      retry.addEventListener('click', () => { bot.remove(); mine.remove(); ui.prompt.value = question; ask(); });
      err.appendChild(retry);
      card.appendChild(err);
    } finally {
      if (request === sequence) {
        ui.send.disabled = false; bot.classList.remove('is-busy');
        // Show the question and the start of the answer, not the end of a long list.
        ui.thread.scrollTop = Math.max(0, mine.offsetTop - ui.thread.offsetTop - 8);
        ui.prompt.focus();
      }
    }
  }
  const scroll = () => { ui.thread.scrollTop = ui.thread.scrollHeight; };

  /** POST to the agent and read its NDJSON stream: step events, then the result. */
  async function agent(body, onStep, stillWanted) {
    const res = await fetch('api/index.php?action=ai_agent&mailbox_id=' + encodeURIComponent(body.mailbox_id), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': document.body.dataset.csrf }, body: JSON.stringify(body) });
    if (!res.ok || !res.body) { const d = await res.json().catch(() => ({})); throw new Error(d.error || 'The assistant could not complete this request.'); }
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '', result = null;
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let nl;
      while ((nl = buffer.indexOf('\n')) >= 0) {
        const line = buffer.slice(0, nl).trim();
        buffer = buffer.slice(nl + 1);
        if (!line) continue;
        let ev;
        try { ev = JSON.parse(line); } catch (_) { continue; }
        if (!stillWanted()) { reader.cancel().catch(() => {}); return {}; }
        if (ev.type === 'step') onStep(ev);
        else if (ev.type === 'done') result = ev.result;
        else if (ev.type === 'error') throw new Error(ev.error || 'The assistant could not complete this request.');
      }
    }
    if (!result) throw new Error('The answer was interrupted. Try again.');
    return result;
  }

  // "Thought for 4 s" with what was actually done, collapsed.
  function trace(result, secs) {
    const d = make('details', 'ai-trace');
    const s = make('summary');
    s.append(icon('ecw-ai-icon'), document.createTextNode(result.charged === false && result.kind === 'files' ? ' Looked it up in ' + secs + ' s' : ' Thought for ' + secs + ' s'));
    d.appendChild(s);
    const ol = make('ol', 'ai-steps');
    (result.steps || []).forEach((t) => {
      const li = make('li', 'done', typeof t === 'string' ? t : t.label);
      if (t && t.detail) li.appendChild(make('span', 'ai-step-detail', t.detail));
      ol.appendChild(li);
    });
    if (result.charged === false && result.kind === 'files') ol.appendChild(make('li', 'done', 'Exact list from the database — no AI request used'));
    d.appendChild(ol);
    return d;
  }

  // ------------------------------------------- what the assistant can do (lib/ai_actions.php)

  /** Excel / CSV files it made: download cards. */
  function downloadList(downloads) {
    const box = make('div', 'ai-downloads');
    downloads.forEach((d) => {
      const a = make('a', 'ai-download');
      a.href = d.url;
      a.setAttribute('download', d.name);
      a.append(icon('fa ' + (d.format === 'csv' ? 'fa-file-csv' : 'fa-file-excel')), make('span', 'ai-dl-name', d.name),
        make('span', 'ai-dl-meta', d.rows + (d.rows === 1 ? ' row' : ' rows')), icon('fa fa-download'));
      box.appendChild(a);
    });
    return box;
  }

  /** A change it proposes: says exactly what will happen, nothing happens before Confirm. */
  function actionCard(action) {
    const card = make('div', 'ai-proposal');
    const ICON = { create_case: 'fa-folder-plus', add_to_case: 'fa-folder-open', update_case: 'fa-pen-to-square', add_note: 'fa-note-sticky', remind_me: 'fa-bell', reply: 'fa-reply' };
    const head = make('div', 'ai-proposal-text');
    head.append(icon('fa ' + (ICON[action.type] || 'fa-wand-magic-sparkles')), make('span', '', action.summary));
    const row = make('div', 'ai-proposal-acts');
    const ok = make('button', 'ai-act primary', action.type === 'reply' ? 'Open the reply' : 'Confirm'); ok.type = 'button';
    const no = make('button', 'ai-act', 'Cancel'); no.type = 'button';
    const status = make('span', 'ai-proposal-status');
    row.append(ok, no, status);
    card.append(head, row);
    no.addEventListener('click', () => { card.classList.add('is-cancelled'); row.replaceChildren(make('span', 'ai-proposal-status', 'Cancelled — nothing changed.')); });
    ok.addEventListener('click', async () => {
      if (action.type === 'reply') { openReply(action.params); row.replaceChildren(make('span', 'ai-proposal-status', 'Opened in the composer. Check it and send it yourself.')); return; }
      ok.disabled = true; no.disabled = true; status.textContent = 'Doing it…';
      try {
        const r = await api('ai_action_run', { action: { type: action.type, params: action.params } });
        card.classList.add('is-done');
        row.replaceChildren(make('span', 'ai-proposal-status done', '✓ ' + r.message));
        if (r.link) { const go = make('a', 'ai-act', 'Open'); go.href = base() + r.link; row.appendChild(go); }
        window.dispatchEvent(new CustomEvent('ecw-ai-changed', { detail: action }));
      } catch (e) {
        ok.disabled = false; no.disabled = false; status.textContent = e.message;
      }
    });
    return card;
  }

  const base = () => window.ecwWebBase || '';

  /** Open what it was asked to open: in Mail itself, a new tab, or a window over the page. */
  function openTarget(t) {
    const url = t.kind === 'case' ? base() + 'case.php?id=' + encodeURIComponent(t.case_id)
      : base() + 'app.php?mailbox=' + encodeURIComponent(t.mailbox_id) + '&thread=' + encodeURIComponent(t.thread_id);
    if (t.where === 'new_tab') { window.open(url, '_blank', 'noopener'); return; }
    if (t.where === 'window' && t.kind === 'thread' && window.ecwOpenThreadPopup) { window.ecwOpenThreadPopup({ threadId: t.thread_id, mailboxId: t.mailbox_id, subject: t.label }); return; }
    if (t.where === 'window') { window.open(url, 'ecw-open-' + (t.thread_id || t.case_id), 'popup,width=1100,height=820'); return; }
    // Here: in Mail the conversation opens beside the panel; elsewhere the page goes there.
    const mail = window.ecwMail;
    if (t.kind === 'thread' && mail && Number(mail.state.mailboxId) === Number(t.mailbox_id)) { mail.openThread(Number(t.thread_id)); return; }
    location.href = url;
  }

  /** "Open the reply": the composer with the assistant's text, to check and send (nothing is sent). */
  async function openReply(p) {
    const mail = window.ecwMail;
    if (mail && Number(mail.state.mailboxId) === Number(p.mailbox_id) && mail.openComposer) {
      if (Number(mail.state.threadId) !== Number(p.thread_id)) await mail.openThread(Number(p.thread_id));
      await mail.openComposer(p.mode || 'reply', Number(p.message_id));
      if (p.text) { const q = mail.editor(); q.insertText(0, p.text + '\n\n', 'user'); q.setSelection(0, 0, 'user'); }
      return;
    }
    // Mail puts it in the reply it opens (app.js handleEntryParams, the same as the case review's texts).
    try { sessionStorage.setItem('ecw.composePrefill', JSON.stringify({ reply: Number(p.message_id), text: p.text || '', at: Date.now() })); } catch (_) {}
    location.href = base() + 'app.php?mailbox=' + encodeURIComponent(p.mailbox_id) + '&thread=' + encodeURIComponent(p.thread_id) + '&reply=' + encodeURIComponent(p.message_id) + '&mode=' + encodeURIComponent(p.mode || 'reply');
  }

  // ----------------------------------------------------------------- answer
  function answer(result, snapshot) {
    const wrap = make('div', 'ai-answer');
    const byLabel = new Map((result.sources || []).map((s) => [s.label, s]));
    wrap.appendChild(rich(result.text || '', byLabel, snapshot));
    if (result.truncated) wrap.appendChild(make('p', 'ai-note', 'The answer was cut short. Ask for a shorter answer.'));
    if (result.files) wrap.appendChild(fileList(result.files));
    if ((result.sources || []).length) wrap.appendChild(sourceList(result.sources, snapshot));
    if (result.downloads) wrap.appendChild(downloadList(result.downloads));
    if (result.actions) result.actions.forEach((a) => wrap.appendChild(actionCard(a)));
    if (result.open) setTimeout(() => result.open.forEach(openTarget), 60);

    const acts = make('div', 'ai-acts');
    const copy = make('button', 'ai-act'); copy.type = 'button'; copy.append(icon('fa fa-copy'), document.createTextNode(' Copy'));
    copy.addEventListener('click', async () => {
      const text = (result.text || '') + (result.files ? '\n' + result.files.map((f) => '- ' + f.filename).join('\n') : '');
      try { await navigator.clipboard.writeText(text.replace(/\*\*/g, '')); copy.lastChild.textContent = ' Copied'; setTimeout(() => { copy.lastChild.textContent = ' Copy'; }, 1500); } catch (_) {}
    });
    acts.appendChild(copy);
    if (snapshot.mode === 'reply' && result.context_count) {
      const ins = make('button', 'ai-act primary'); ins.type = 'button'; ins.append(icon('fa fa-arrow-turn-down'), document.createTextNode(' Insert into reply'));
      ins.addEventListener('click', () => {
        window.dispatchEvent(new CustomEvent('ecw-ai-insert', { detail: { ...snapshot, text: (result.text || '').replace(/\*\*/g, '') } }));
        ins.lastChild.textContent = ' Inserted';
      });
      acts.appendChild(ins);
    }
    wrap.appendChild(acts);
    return wrap;
  }

  /** Paragraphs, "- " bullets, "1." lists, **bold** and [M42] chips — all as text nodes. */
  function rich(text, byLabel, snapshot) {
    const frag = document.createDocumentFragment();
    let list = null, listType = '';
    const inline = (parent, line) => {
      line.split(/(\*\*[^*]+\*\*|\[M\d+(?:\s*,\s*M\d+)*\])/g).forEach((part) => {
        if (!part) return;
        if (/^\*\*[^*]+\*\*$/.test(part)) { parent.appendChild(make('strong', '', part.slice(2, -2))); return; }
        if (/^\[M\d+/.test(part)) {
          part.slice(1, -1).split(/\s*,\s*/).forEach((label) => {
            const src = byLabel.get(label);
            if (!src) return;
            const a = make('a', 'ai-cite', String(1 + [...byLabel.keys()].indexOf(label)));
            a.href = 'app.php?mailbox=' + (src.mailbox_id || snapshot.mailboxId) + '&thread=' + src.thread_id + '#msg-' + src.id;
            a.target = '_blank'; a.rel = 'noopener';
            a.title = (src.from ? src.from + ' · ' : '') + (src.subject || '') + ' · ' + String(src.date || '').slice(0, 10);
            parent.appendChild(a);
          });
          return;
        }
        parent.appendChild(document.createTextNode(part));
      });
    };
    // Models sometimes cite "[case]" or a tool name ("[count_emails]") instead of an email: not links, so drop them.
    text.replace(/\r/g, '').replace(/\s?\[(?:case|CASE|[a-z]+_[a-z_]+)\]/g, '').split('\n').forEach((raw) => {
      const line = raw.replace(/^#{1,6}\s+/, '').trimEnd();
      const bullet = line.match(/^\s*[-*•]\s+(.*)$/);
      const num = line.match(/^\s*\d+[.)]\s+(.*)$/);
      if (bullet || num) {
        const type = bullet ? 'ul' : 'ol';
        if (!list || listType !== type) { list = make(type); listType = type; frag.appendChild(list); }
        const li = make('li'); inline(li, (bullet || num)[1]); list.appendChild(li);
        return;
      }
      list = null;
      if (!line.trim()) return;
      const p = make('p'); inline(p, line); frag.appendChild(p);
    });
    return frag;
  }

  function fileIcon(name) {
    const ext = (name.split('.').pop() || '').toLowerCase();
    if (ext === 'pdf') return ['fa-file-pdf', 'pdf'];
    if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'heic'].includes(ext)) return ['fa-file-image', 'img'];
    if (['xls', 'xlsx', 'csv', 'ods'].includes(ext)) return ['fa-file-excel', 'xls'];
    if (['doc', 'docx', 'odt', 'rtf', 'txt'].includes(ext)) return ['fa-file-word', 'doc'];
    if (['ppt', 'pptx', 'odp', 'key'].includes(ext)) return ['fa-file-powerpoint', 'ppt'];
    if (['zip', 'rar', '7z', 'gz', 'tar'].includes(ext)) return ['fa-file-zipper', 'zip'];
    if (['dxf', 'dwg', 'igs', 'iges', 'step', 'stp', 'stl', 'sldprt', 'x_t', 'obj', '3mf'].includes(ext)) return ['fa-cube', 'cad'];
    return ['fa-file', 'other'];
  }
  const size = (b) => !b ? '' : b < 1024 ? b + ' B' : b < 1048576 ? Math.round(b / 1024) + ' KB' : (b / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB';
  const day = (d) => { const t = new Date(String(d).replace(' ', 'T') + 'Z'); return isNaN(t) ? '' : t.toLocaleDateString([], { day: 'numeric', month: 'short', year: t.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' }); };

  function fileList(files) {
    const box = make('div', 'ai-files');
    if (!files.length) return box;
    const shown = 8;
    files.forEach((f, i) => {
      const [ic, kind] = fileIcon(f.filename);
      const row = make('div', 'ai-file');
      if (i >= shown) row.hidden = true;
      const badge = make('span', 'ai-file-ic ' + kind); badge.appendChild(icon('fa ' + ic));
      const main = make('div', 'ai-file-main');
      const name = make('a', 'ai-file-name', f.filename);
      name.href = 'api/index.php?action=attachment&id=' + f.id; name.title = 'Download ' + f.filename;
      const meta = make('span', 'ai-file-meta');
      meta.textContent = [size(f.size), (f.direction === 'outgoing' ? '↑ sent' : '↓ from ' + f.from), day(f.date), f.times > 1 ? '×' + f.times : ''].filter(Boolean).join(' · ');
      main.append(name, meta);
      const open = make('a', 'ai-file-open'); open.href = 'app.php?mailbox=' + f.mailbox_id + '&thread=' + f.thread_id + '#msg-' + f.message_id;
      open.target = '_blank'; open.rel = 'noopener'; open.title = 'Open the email: ' + (f.subject || ''); open.setAttribute('aria-label', 'Open the email');
      open.appendChild(icon('fa fa-arrow-up-right-from-square'));
      const dl = make('a', 'ai-file-open'); dl.href = name.href; dl.title = 'Download'; dl.setAttribute('aria-label', 'Download ' + f.filename);
      dl.appendChild(icon('fa fa-download'));
      row.append(badge, main, dl, open);
      box.appendChild(row);
    });
    if (files.length > shown) {
      const more = make('button', 'ai-more', 'Show all ' + files.length + ' files'); more.type = 'button';
      more.addEventListener('click', () => { box.querySelectorAll('.ai-file[hidden]').forEach((r) => { r.hidden = false; }); more.remove(); });
      box.appendChild(more);
    }
    return box;
  }

  function sourceList(sources, snapshot) {
    const d = make('details', 'ai-sources');
    d.appendChild(make('summary', '', 'Based on ' + sources.length + ' email' + (sources.length === 1 ? '' : 's')));
    const ol = make('ol');
    sources.forEach((s) => {
      const li = make('li');
      const a = make('a'); a.href = 'app.php?mailbox=' + (s.mailbox_id || snapshot.mailboxId) + '&thread=' + s.thread_id + '#msg-' + s.id; a.target = '_blank'; a.rel = 'noopener';
      a.append(make('span', 'ai-src-subj', s.subject || '(no subject)'), make('span', 'ai-src-meta', [s.from, day(s.date)].filter(Boolean).join(' · ')));
      li.appendChild(a); ol.appendChild(li);
    });
    d.appendChild(ol);
    return d;
  }

  // ------------------------------------------------------------------ open
  window.ecwOpenAssistant = (options) => {
    if (!options.mailboxId) return;
    if (!panel) build();
    const key = [options.mode, options.mailboxId, options.messageId || 0, options.query || ''].join('|');
    context = { ...options };
    ui.narrow.hidden = options.mode === 'reply';
    if (key !== lastKey) {
      // A different email or case: a fresh conversation.
      lastKey = key; sequence++;
      ui.search.value = options.query || '';
      ui.narrow.open = !!options.query;
      ui.prompt.value = '';
      drawEmpty();
    }
    ui.prompt.placeholder = options.mode === 'reply' ? 'How should the reply go?' : 'Ask about your emails…';
    panel.hidden = false;
    ui.prompt.focus();
  };
})();
