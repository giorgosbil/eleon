/**
 * Pop-up windows for email conversations.
 *
 * window.ecwOpenThreadPopup({ threadId, mailboxId, subject }) opens a floating,
 * draggable, resizable window over the page with the whole conversation in it
 * (app.php?thread_only=1 in a same-origin iframe), so Reply, Forward, notes and
 * attachment previews all work inside it. Several can be open at once; opening
 * one that is already open brings it to the front. "New window" moves it into
 * a real browser window (for a second screen).
 *
 * Used by the right-click menu and the "Pop out" button of an open email.
 */
(function () {
  'use strict';
  const base = window.ecwWebBase || '';
  const open = new Map();   // threadId -> window element
  let z = 260, count = 0;
  const $ = (id) => document.getElementById(id);

  function urlFor(threadId, mailboxId) {
    return base + 'app.php?thread_only=1&thread=' + encodeURIComponent(threadId) + '&mailbox=' + encodeURIComponent(mailboxId);
  }

  function front(win) { win.style.zIndex = String(++z); }

  function button(icon, label, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'thread-popup-btn';
    b.title = label;
    b.setAttribute('aria-label', label);
    const i = document.createElement('i');
    i.className = 'fa ' + icon;
    i.setAttribute('aria-hidden', 'true');
    b.appendChild(i);
    b.addEventListener('click', onClick);
    return b;
  }

  function openPopup(opts) {
    const threadId = Number(opts.threadId), mailboxId = Number(opts.mailboxId);
    if (!threadId || !mailboxId) return;
    if (open.has(threadId)) { const w = open.get(threadId); w.hidden = false; front(w); return; }

    const win = document.createElement('div');
    win.className = 'thread-popup';
    win.setAttribute('role', 'dialog');
    win.setAttribute('aria-label', 'Email: ' + (opts.subject || 'conversation'));
    const head = document.createElement('header');
    head.className = 'thread-popup-head';
    const icon = document.createElement('i');
    icon.className = 'fa fa-envelope-open-text';
    icon.setAttribute('aria-hidden', 'true');
    const title = document.createElement('span');
    title.className = 'thread-popup-title';
    title.textContent = opts.subject || 'Conversation';
    const actions = document.createElement('span');
    actions.className = 'thread-popup-actions';

    const frame = document.createElement('iframe');
    frame.className = 'thread-popup-frame';
    frame.title = opts.subject || 'Conversation';
    frame.src = urlFor(threadId, mailboxId);

    let normal = null;
    const close = () => { win.remove(); open.delete(threadId); };
    const maximize = button('fa-expand', 'Maximize', () => {
      if (!normal) {
        normal = { left: win.style.left, top: win.style.top, width: win.style.width, height: win.style.height };
        Object.assign(win.style, { left: '8px', top: '8px', width: (window.innerWidth - 16) + 'px', height: (window.innerHeight - 16) + 'px' });
        win.dataset.expanded = 'true';
        maximize.querySelector('i').className = 'fa fa-compress';
        maximize.title = 'Restore';
      } else {
        Object.assign(win.style, normal);
        normal = null;
        delete win.dataset.expanded;
        maximize.querySelector('i').className = 'fa fa-expand';
        maximize.title = 'Maximize';
      }
    });
    actions.append(
      button('fa-inbox', 'Show in Mail', () => { location.href = base + 'app.php?thread=' + threadId + '&mailbox=' + mailboxId; }),
      button('fa-window-restore', 'Open in a new browser window', () => {
        const w = window.open(urlFor(threadId, mailboxId), 'ecw-thread-' + threadId, 'popup,width=900,height=820');
        if (w) close();
      }),
      maximize,
      button('fa-xmark', 'Close', close)
    );
    head.append(icon, title, actions);
    // While dragging or resizing, the iframe must not swallow the pointer.
    const shield = document.createElement('div');
    shield.className = 'thread-popup-shield';
    win.append(head, frame, shield);
    document.body.appendChild(win);

    const offset = (count++ % 6) * 30;
    const w = Math.min(860, window.innerWidth - 40), h = Math.min(760, window.innerHeight - 90);
    Object.assign(win.style, {
      width: w + 'px', height: h + 'px',
      left: Math.max(10, window.innerWidth - w - 40 - offset) + 'px', top: (70 + offset) + 'px',
    });
    front(win);
    win.addEventListener('pointerdown', () => front(win));
    head.addEventListener('dblclick', (e) => { if (!e.target.closest('button')) maximize.click(); });
    if (window.ecwFloatingWindow) window.ecwFloatingWindow(win, head);
    head.addEventListener('pointerdown', (e) => { if (!e.target.closest('button')) win.classList.add('dragging'); });
    window.addEventListener('pointerup', () => win.classList.remove('dragging'));
    // The native resize grip: shield the iframe while the corner is held.
    win.addEventListener('pointerdown', (e) => {
      const r = win.getBoundingClientRect();
      if (e.clientX > r.right - 18 && e.clientY > r.bottom - 18) win.classList.add('dragging');
    });

    // The subject arrives once the conversation loads inside.
    frame.addEventListener('load', () => {
      try {
        const subject = frame.contentDocument.getElementById('threadSubject');
        const sync = () => { if (subject.textContent.trim()) { title.textContent = subject.textContent.trim(); frame.title = title.textContent; } };
        new MutationObserver(sync).observe(subject, { childList: true, characterData: true, subtree: true });
        sync();
      } catch (_) {}
    });
    open.set(threadId, win);
  }

  // Read inside a pop-up: the list row behind it follows straight away.
  window.addEventListener('message', (e) => {
    if (e.origin !== location.origin || !e.data || e.data.type !== 'ecw-thread-read') return;
    const row = document.querySelector('.thread-row[data-thread-id="' + Number(e.data.thread_id) + '"]');
    row?.classList.toggle('unread', !e.data.is_read);
    if (row?._thread) row._thread.unread = !e.data.is_read;
    document.dispatchEvent(new CustomEvent('ecw:unread-changed', { detail: e.data }));
  });

  window.ecwOpenThreadPopup = openPopup;
  /** Print / Save as PDF: the chat-style print page in a new tab. */
  window.ecwPrintThread = (threadId, mailboxId) => {
    window.open(base + 'thread-print.php?thread=' + encodeURIComponent(threadId) + '&mailbox=' + encodeURIComponent(mailboxId), '_blank', 'noopener');
  };
  $('threadPrintBtn')?.addEventListener('click', () => {
    if (M && M.state.threadId) window.ecwPrintThread(M.state.threadId, M.state.mailboxId);
  });

  // "Pop out" on an open email.
  const M = window.ecwMail;
  $('threadPopoutBtn')?.addEventListener('click', () => {
    if (!M || !M.state.threadId) return;
    openPopup({ threadId: M.state.threadId, mailboxId: M.state.mailboxId, subject: M.state.thread?.subject });
  });
})();
