/**
 * Minimized drafts as tabs along the bottom of every page, like Outlook.
 *
 *   window.ecwDraftDock.add({id, mailbox_id, subject, to})
 *   window.ecwDraftDock.remove(id)
 *   window.ecwDraftDock.setActive(id|null)   — hide the tab of the draft open in the composer
 *
 * The list lives in localStorage (per workspace user), so tabs survive moving
 * between Mail, Cases, Teams and Settings, reloads and other browser tabs. Only
 * ids and a subject/recipient label are kept here; the draft itself is on the
 * server. Clicking a tab reopens the draft (on the mail page directly, from any
 * other page via app.php?draft=ID). Text is written with textContent.
 */
(function () {
  'use strict';
  if (window.ecwDraftDock) return;
  const base = window.ecwWebBase || '/';
  const KEY = 'ecw.drafttabs.' + String(window.ecwUserId || document.body?.dataset.user || '0');
  const MAX = 6;
  let active = null;
  let bar = null;

  const read = () => { try { return JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (_) { return []; } };
  const write = (tabs) => { try { localStorage.setItem(KEY, JSON.stringify(tabs.slice(-MAX))); } catch (_) {} };

  function render() {
    if (window.parent !== window) return;   // the case page's composer frame: its parent page shows the tabs
    // Only the drafts of the account chosen at the top (partials/nav.php sets ecwAccountId).
    const acct = Number(window.ecwAccountId || 0);
    const tabs = read().filter((t) => t.id !== active && (!acct || !t.mailbox_id || t.mailbox_id === acct));
    if (!bar) {
      bar = document.createElement('nav');
      bar.className = 'draft-dock';
      bar.setAttribute('aria-label', 'Minimized drafts');
      document.body.appendChild(bar);
    }
    bar.textContent = '';
    bar.hidden = tabs.length === 0;
    tabs.forEach((t) => {
      const tab = document.createElement('div');
      tab.className = 'draft-tab';
      const open = document.createElement('button');
      open.type = 'button';
      open.className = 'draft-tab-open';
      open.title = 'Continue writing' + (t.to ? ' to ' + t.to : '');
      const icon = document.createElement('i');
      icon.className = 'fa fa-pen-to-square';
      icon.setAttribute('aria-hidden', 'true');
      const label = document.createElement('span');
      label.className = 'draft-tab-label';
      label.textContent = t.subject || (t.to ? 'To ' + t.to : '(no subject)');
      open.append(icon, label);
      open.addEventListener('click', () => {
        if (window.ecwMail && window.ecwMail.openDraft) window.ecwMail.openDraft(t.id);
        else location.href = base + 'app.php?draft=' + encodeURIComponent(t.id) + '&mailbox=' + encodeURIComponent(t.mailbox_id || '')
          + (t.thread_id ? '&thread=' + encodeURIComponent(t.thread_id) : '');   // a reply opens with its conversation
      });
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'draft-tab-close';
      close.title = 'Remove the tab (the draft stays in Drafts)';
      close.setAttribute('aria-label', 'Remove tab ' + (t.subject || 'draft'));
      close.textContent = '×';
      close.addEventListener('click', () => api.remove(t.id));
      tab.append(open, close);
      bar.appendChild(tab);
    });
  }

  const api = {
    add(d) {
      if (!d || !d.id) return;
      const tabs = read().filter((t) => t.id !== Number(d.id));
      tabs.push({ id: Number(d.id), mailbox_id: Number(d.mailbox_id) || null, thread_id: Number(d.thread_id) || null, subject: String(d.subject || '').slice(0, 120), to: String(d.to || '').slice(0, 120) });
      write(tabs);
      render();
    },
    remove(id) { write(read().filter((t) => t.id !== Number(id))); render(); },
    setActive(id) { active = id === null ? null : Number(id); render(); },
    list: read,
  };
  window.ecwDraftDock = api;

  // Another browser tab (or the case page's composer frame) changed the tabs.
  window.addEventListener('storage', (e) => { if (e.key === KEY) render(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', render); else render();
})();
