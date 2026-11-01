/**
 * Shared mailboxes (info@, sales@): who handles each conversation.
 *
 *   - "All / Mine / Unassigned / Others" above the list (shared mailboxes only)
 *   - in the open conversation: "Claim", or "Handled by Maria", with
 *     Take it / Give to… / Release
 *
 * Replying from an unclaimed conversation claims it on the server
 * (lib/shared_mailboxes.php), so two people never answer the same customer.
 * Everything is written with textContent.
 */
(function () {
  'use strict';
  const mail = window.ecwMail;
  const filters = document.getElementById('claimFilters');
  const holder = document.getElementById('threadClaim');
  if (!mail || !filters || !holder) return;
  const me = Number(document.body.dataset.user || 0);

  // ------------------------------------------------------------ list filters
  document.addEventListener('ecw:mailbox-changed', (e) => {
    const shared = !!(e.detail && e.detail.is_shared);
    filters.hidden = !shared;
    filters.querySelectorAll('[data-claim]').forEach((b) => b.classList.toggle('active', b.dataset.claim === ''));
    if (!shared) holder.hidden = true;
  });
  filters.addEventListener('click', (e) => {
    const b = e.target.closest('[data-claim]');
    if (!b) return;
    filters.querySelectorAll('[data-claim]').forEach((x) => x.classList.toggle('active', x === b));
    mail.state.claim = b.dataset.claim;
    mail.resetList();
  });

  // ------------------------------------------------------- open conversation
  let current = null;
  document.addEventListener('ecw:thread-open', (e) => {
    current = e.detail;
    render();
  });

  function el(tag, cls, text) { const x = document.createElement(tag); if (cls) x.className = cls; if (text) x.textContent = text; return x; }

  function render() {
    holder.textContent = '';
    if (!current || !current.shared_mailbox) { holder.hidden = true; return; }
    holder.hidden = false;
    const c = current.claimed;
    const btn = el('button', 'claim-btn' + (c ? (c.id === me ? ' mine' : ' other') : ' free'));
    btn.type = 'button';
    btn.setAttribute('aria-haspopup', 'menu');
    const icon = el('i', 'fa ' + (c ? 'fa-user-check' : 'fa-hand'));
    icon.setAttribute('aria-hidden', 'true');
    btn.append(icon, ' ' + (c ? (c.id === me ? 'You handle this' : 'Handled by ' + c.name) : 'Unassigned — claim it'));
    holder.appendChild(btn);
    btn.addEventListener('click', (ev) => {
      ev.stopPropagation();
      if (!c) { void claim(me); return; }   // one click to take a free one
      openMenu(btn);
    });
    if (!c) {
      const more = el('button', 'claim-more');
      more.type = 'button';
      more.setAttribute('aria-label', 'Give to a colleague');
      more.title = 'Give to a colleague';
      const i = el('i', 'fa fa-caret-down'); i.setAttribute('aria-hidden', 'true');
      more.appendChild(i);
      more.addEventListener('click', (ev) => { ev.stopPropagation(); openMenu(more); });
      holder.appendChild(more);
    }
  }

  let menu = null;
  function closeMenu() { if (menu) { menu.remove(); menu = null; } }
  document.addEventListener('click', closeMenu);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });

  function openMenu(anchor) {
    closeMenu();
    const c = current.claimed;
    menu = el('div', 'claim-menu');
    menu.setAttribute('role', 'menu');
    const add = (label, fn, cls) => {
      const b = el('button', cls || '', label);
      b.type = 'button'; b.setAttribute('role', 'menuitem');
      b.addEventListener('click', (ev) => { ev.stopPropagation(); closeMenu(); fn(); });
      menu.appendChild(b);
    };
    if (!c || c.id !== me) add('Take it', () => claim(me));
    const people = (current.claim_people || []).filter((p) => p.id !== me && (!c || p.id !== c.id));
    if (people.length) {
      menu.appendChild(el('div', 'claim-menu-title', 'Give to'));
      people.forEach((p) => add(p.name, () => claim(p.id)));
    }
    if (c) add('Release (back to unassigned)', () => claim(null), 'claim-release');
    document.body.appendChild(menu);
    const r = anchor.getBoundingClientRect();
    menu.style.top = (r.bottom + window.scrollY + 4) + 'px';
    menu.style.left = Math.max(8, Math.min(r.left + window.scrollX, window.innerWidth - menu.offsetWidth - 8)) + 'px';
    menu.querySelector('button')?.focus();
  }

  async function claim(userId) {
    const threadId = current.id;
    try {
      const body = userId === null ? { thread_id: threadId, release: true } : { thread_id: threadId, user_id: userId };
      const r = await mail.api('thread_claim', {}, { method: 'POST', body });
      if (!current || current.id !== threadId) return;
      current.claimed = r.claimed;
      render();
      updateRow(threadId, r.claimed);
      mail.toast(r.claimed ? (r.claimed.id === me ? 'It is yours now.' : 'Given to ' + r.claimed.name + '.') : 'Back to unassigned.');
    } catch (e) {
      mail.toast(e.message, true);
    }
  }

  /** Keep the list row's "handled by" badge in step without reloading the list. */
  function updateRow(threadId, claimed) {
    const row = document.querySelector('.thread-row[data-thread-id="' + threadId + '"]');
    if (!row) return;
    if (row._thread) row._thread.claimed = claimed;
    row.querySelector('.claim-badge')?.remove();
    if (!claimed) return;
    let markers = row.querySelector('.row-markers');
    if (!markers) { markers = el('div', 'row-markers'); row.appendChild(markers); }
    const b = el('span', 'claim-badge' + (claimed.id === me ? ' mine' : ''));
    b.title = 'Handled by ' + claimed.name;
    const i = el('i', 'fa fa-user-check'); i.setAttribute('aria-hidden', 'true');
    b.append(i, ' ' + (claimed.id === me ? 'You' : claimed.name.split(' ')[0]));
    markers.appendChild(b);
  }
})();
