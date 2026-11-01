/**
 * window.ecwMentionInput(textarea, menu, options) — "@" autocomplete for note boxes.
 *
 * The textarea shows readable "@Name"; serialize() turns each picked name into
 * the stored token @[Name](user:ID) (longest names first, so "@Ann Marie" is
 * never consumed by "@Ann"). options: { users: () => [...], me: id,
 * onPick(user) }. Ctrl/Cmd+Enter calls options.submit. Text is written with
 * textContent only.
 */
(function () {
  'use strict';
  window.ecwMentionInput = function (input, menu, options) {
    const chosen = new Map();
    let index = 0;
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
    const query = () => {
      const m = input.value.slice(0, input.selectionStart).match(/(?:^|\s)@([^\s@]{0,30})$/);
      return m ? m[1].toLowerCase() : null;
    };
    const close = () => { menu.hidden = true; menu.textContent = ''; };
    function pick(u) {
      const pos = input.selectionStart;
      const upto = input.value.slice(0, pos).replace(/@([^\s@]{0,30})$/, '@' + u.name + ' ');
      input.value = upto + input.value.slice(pos);
      input.setSelectionRange(upto.length, upto.length);
      chosen.set(u.name, u.id);
      close();
      input.focus();
      options.onPick?.(u);
    }
    input.addEventListener('input', () => {
      const q = query();
      if (q === null) { close(); return; }
      const others = (options.users() || []).filter((u) => u.id !== options.me);
      const matches = others.filter((u) => (u.name || '').toLowerCase().includes(q) || (u.email || '').toLowerCase().includes(q)).slice(0, 8);
      menu.textContent = '';
      index = 0;
      if (!matches.length) {
        // Say why nothing is offered, instead of silently showing nothing.
        const li = el('li', 'mention-empty');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-disabled', 'true');
        if (!others.length) {
          li.append(el('span', '', 'No colleagues to mention yet.'));
          const a = el('a', '', 'Invite people in Settings → Users');
          a.href = (window.ecwWebBase || '/') + 'settings/users.php';
          li.appendChild(a);
        } else {
          li.textContent = 'No colleague matches “' + q + '”.';
        }
        menu.appendChild(li);
        menu.hidden = false;
        return;
      }
      matches.forEach((u, i) => {
        const li = el('li', i === 0 ? 'active' : '');
        li.setAttribute('role', 'option');
        if (window.ecwAvatar) li.appendChild(window.ecwAvatar(u, 22));
        li.append(el('span', '', u.name), el('span', 'muted', u.email));
        li.addEventListener('mousedown', (e) => { e.preventDefault(); pick(u); });
        li._user = u;
        menu.appendChild(li);
      });
      menu.hidden = false;
    });
    input.addEventListener('keydown', (e) => {
      if (!menu.hidden) {
        const items = [...menu.children].filter((li) => li._user);
        if (!items.length) { if (e.key === 'Escape') close(); return; }   // only the "nobody to mention" hint is showing
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          items[index]?.classList.remove('active');
          index = (index + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
          items[index].classList.add('active');
          return;
        }
        if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); pick(items[index]._user); return; }
        if (e.key === 'Escape') { close(); return; }
      }
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); options.submit?.(); }
    });
    input.addEventListener('blur', () => setTimeout(close, 150));
    return {
      serialize() {
        let body = input.value.trim();
        [...chosen.keys()].sort((a, b) => b.length - a.length).forEach((name) => {
          body = body.split('@' + name).join('@[' + name + '](user:' + chosen.get(name) + ')');
        });
        return body;
      },
      reset() { input.value = ''; chosen.clear(); close(); },
    };
  };
})();
