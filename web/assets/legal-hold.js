/**
 * Case page, admins: put the case on legal hold or release it (lib/security.php).
 * The page reloads afterwards so every button and badge shows the new state.
 */
(function () {
  'use strict';
  const btn = document.getElementById('caseHoldBtn');
  const overlay = document.getElementById('holdOverlay');
  if (!btn || !overlay) return;
  const caseId = document.body.dataset.case;
  const csrf = document.body.dataset.csrf;
  const held = btn.dataset.held === '1';
  const toast = (msg, err) => (window.ecwPageToast ? window.ecwPageToast(msg, err) : alert(msg));

  btn.addEventListener('click', () => { overlay.hidden = false; (held ? document.getElementById('holdConfirm') : document.getElementById('holdNote')).focus(); });
  document.getElementById('holdCancel').addEventListener('click', () => { overlay.hidden = true; btn.focus(); });
  overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') { overlay.hidden = true; btn.focus(); } });
  document.getElementById('holdConfirm').addEventListener('click', async (e) => {
    const b = e.currentTarget;
    b.disabled = true;
    try {
      const r = await fetch('api/index.php?action=case_hold&case_id=' + encodeURIComponent(caseId), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-ECW-CSRF': csrf },
        body: JSON.stringify({ case_id: Number(caseId), on: !held, note: (document.getElementById('holdNote') || {}).value || '' }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(d.error || 'The hold could not be changed.');
      location.reload();
    } catch (err) {
      b.disabled = false;
      overlay.hidden = true;
      toast(err.message, true);
    }
  });
})();
