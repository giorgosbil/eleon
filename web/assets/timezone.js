/**
 * Travelling, like Outlook: every time on screen follows this computer's time
 * zone (Mail, Cases and the calendar already do). This file:
 *   - tells the server the zone this computer is in (cookie ecw_tz), so what it
 *     writes for you (the "Sent:" line of a reply…) uses it too;
 *   - away from your home time zone, shows a clock in the top bar ("London ·
 *     −2 h") that explains it, with "Make this my home time zone" and the
 *     calendar's second time zone (your home time beside the local one);
 *   - window.ecwSecondZone: the zone the calendar shows as a second column.
 * Text is written with textContent.
 */
(function () {
  'use strict';
  const base = window.ecwWebBase || '/';
  let here = 'UTC';
  try { here = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (_) {}
  try { document.cookie = 'ecw_tz=' + encodeURIComponent(here) + '; path=/; max-age=31536000; samesite=lax'; } catch (_) {}
  // Home: the person's own (Settings), else the workspace's, else the zone this browser first saw.
  let home = window.ecwHomeTz || '';
  if (!home) {
    try { home = localStorage.getItem('ecw.homeTz') || ''; if (!home) { home = here; localStorage.setItem('ecw.homeTz', here); } } catch (_) { home = here; }
  }
  const city = (z) => String(z).split('/').pop().replace(/_/g, ' ');
  /** Minutes zone b is ahead of zone a, now. */
  const offset = (z) => { const d = new Date(); const s = d.toLocaleString('en-US', { timeZone: z, hour12: false }); return Math.round((new Date(s) - new Date(d.toLocaleString('en-US', { timeZone: 'UTC', hour12: false }))) / 60000); };
  const diff = (offset(here) - offset(home)) / 60;   // hours this computer is ahead of home
  window.ecwSecondZone = here !== home && window.ecwShowHomeZone !== false && diff !== 0 ? home : null;
  window.ecwHereZone = here;
  if (here === home || diff === 0) return;

  const slot = document.getElementById('tzSlot');
  if (!slot) return;
  const fmt = (h) => (h > 0 ? '+' : '−') + (Math.abs(h) % 1 ? Math.abs(h).toFixed(1) : Math.abs(h)) + ' h';
  const chip = document.createElement('button');
  chip.type = 'button';
  chip.className = 'tz-chip';
  chip.setAttribute('aria-haspopup', 'true');
  chip.title = 'Times follow this computer: ' + city(here) + ' (' + fmt(diff) + ' from ' + city(home) + ')';
  const ic = document.createElement('i'); ic.className = 'fa fa-clock'; ic.setAttribute('aria-hidden', 'true');
  chip.append(ic, document.createTextNode(' ' + city(here) + ' · ' + fmt(diff)));
  slot.appendChild(chip);
  let pop = null;
  const close = () => { pop?.remove(); pop = null; };
  async function save(body) {
    await fetch(base + 'api/index.php?action=timezone_save', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': window.ecwCsrf || '' }, body: JSON.stringify(body) });
    location.reload();
  }
  chip.addEventListener('click', (e) => {
    e.stopPropagation();
    if (pop) { close(); return; }
    pop = document.createElement('div');
    pop.className = 'tz-pop';
    const now = (z) => new Date().toLocaleTimeString([], { timeZone: z, hour: '2-digit', minute: '2-digit' });
    const p1 = document.createElement('p');
    p1.textContent = 'This computer is in ' + city(here) + ' (now ' + now(here) + '), ' + fmt(diff).replace('+', '').replace('−', '') + (diff > 0 ? ' ahead of ' : ' behind ') + city(home) + ' (now ' + now(home) + '). Every time here follows this computer, like Outlook.';
    const b1 = document.createElement('button'); b1.type = 'button'; b1.className = 'primary';
    b1.textContent = 'Make ' + city(here) + ' my home time zone';
    b1.addEventListener('click', () => save({ home_tz: here }));
    const b2 = document.createElement('button'); b2.type = 'button';
    b2.textContent = window.ecwShowHomeZone === false ? 'Show ' + city(home) + ' time in the calendar' : 'Hide ' + city(home) + ' time in the calendar';
    b2.addEventListener('click', () => save({ show_home_zone: window.ecwShowHomeZone === false }));
    const row = document.createElement('div'); row.className = 'tz-pop-row'; row.append(b1, b2);
    pop.append(p1, row);
    slot.appendChild(pop);
  });
  document.addEventListener('click', (e) => { if (pop && !pop.contains(e.target)) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
})();
