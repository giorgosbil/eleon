/**
 * Mail: shows the AI working on new email.
 *   - In the list, a new email the AI has not read yet gets a moving "AI reading"
 *     chip, then "Read by AI" for a moment when it is done.
 *   - Above an open conversation, a bar says what the AI is doing; when it is
 *     done its suggestion cards appear (suggestions.js) or "Read by AI" shows.
 * Driven by the live-update poll (app.js → ecw:updates, api "updates" →
 * ai_reading from lib/agents.php ecw_agents_reading).
 */
(function () {
  'use strict';
  const list = document.getElementById('threadList');
  const sugg = document.getElementById('threadSuggestions');
  if (!list) return;
  let reading = new Set();
  const justDone = new Map();   // thread id -> time it finished
  const DONE_MS = 5000;

  let bar = null;
  if (sugg) {
    bar = document.createElement('div');
    bar.className = 'ai-working-bar';
    bar.setAttribute('role', 'status');
    bar.hidden = true;
    sugg.before(bar);
  }

  function chipFor(row) {
    let chip = row.querySelector('.ai-chip');
    if (!chip) {
      chip = document.createElement('span');
      chip.className = 'ai-chip';
      (row.querySelector('.row-markers') || row).appendChild(chip);
    }
    return chip;
  }

  function paintRows() {
    const now = Date.now();
    list.querySelectorAll('.thread-row[data-thread-id]').forEach((row) => {
      const id = Number(row.dataset.threadId);
      const done = justDone.has(id) && now - justDone.get(id) < DONE_MS;
      row.classList.toggle('ai-reading', reading.has(id));
      if (reading.has(id)) {
        const chip = chipFor(row);
        chip.className = 'ai-chip is-reading';
        chip.innerHTML = '<span class="ai-spin" aria-hidden="true"></span> AI reading';
        chip.title = 'The AI is reading this new email: what it is, how urgent, any deadline, which case it belongs to.';
      } else if (done) {
        const chip = chipFor(row);
        chip.className = 'ai-chip is-done';
        chip.textContent = '✓ Read by AI';
        chip.title = '';
      } else {
        row.querySelector('.ai-chip')?.remove();
      }
    });
  }

  function openId() { return window.ecwMail?.state?.thread?.id || 0; }

  function paintBar() {
    if (!bar) return;
    const id = openId();
    if (id && reading.has(id)) {
      bar.className = 'ai-working-bar is-reading';
      bar.innerHTML = '<span class="ai-spin" aria-hidden="true"></span><span><strong>The AI is reading this email</strong> — what it is, how urgent, any deadline, and which case it belongs to. Its suggestions appear here.</span>';
      bar.hidden = false;
    } else if (id && justDone.has(id) && Date.now() - justDone.get(id) < DONE_MS && sugg?.hidden) {
      bar.className = 'ai-working-bar is-done';
      bar.textContent = '✓ The AI read this email. Nothing needs your attention from it.';
      bar.hidden = false;
    } else {
      bar.hidden = true;
    }
  }

  document.addEventListener('ecw:updates', async (e) => {
    const r = e.detail?.ai_reading;
    if (!r) return;
    const next = new Set(r.threads || []);
    const finished = [...reading].filter((id) => !next.has(id));
    reading = next;
    const now = Date.now();
    finished.forEach((id) => justDone.set(id, now));
    paintRows();
    // The open conversation was just read: show what the AI found.
    if (finished.includes(openId()) && window.ecwSuggestionsReload) await window.ecwSuggestionsReload(openId());
    paintBar();
    if (finished.length) setTimeout(() => { paintRows(); paintBar(); }, DONE_MS + 50);
  });

  // The list is redrawn when mail arrives: paint the chips again.
  new MutationObserver(() => { if (reading.size || justDone.size) paintRows(); }).observe(list, { childList: true });
  document.addEventListener('ecw:thread-open', () => setTimeout(paintBar, 0));
})();
