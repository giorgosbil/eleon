<?php declare(strict_types=1);
/**
 * Settings sidebar: every settings page on the left, the chosen one on the
 * right (like the folder list in Mail). Included by each settings page inside
 * <div class="settings-shell">. The pages and their groups are in
 * lib/settings_menu.php; each person sees only what they may open.
 *
 * The search box at the top filters the list by name, description and the words
 * each page is known by ("bubble" finds Notifications). Opening a result passes
 * ?find=, and the page scrolls to the first heading or label with that word.
 */
require_once dirname(__DIR__, 2) . '/lib/settings_menu.php';
$sBase = ecw_web_base();
$sE = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$sMenu = ecw_settings_menu_mark(ecw_settings_menu(ecw_context()), (string)($_SERVER['SCRIPT_NAME'] ?? ''), $_GET);
?>
<aside class="settings-side" aria-label="Settings">
  <div class="settings-search" role="search">
    <i class="fa fa-magnifying-glass" aria-hidden="true"></i>
    <input type="search" id="settingsSearch" placeholder="Search settings" aria-label="Search settings" autocomplete="off" spellcheck="false">
  </div>
  <p class="settings-search-empty muted small" id="settingsSearchEmpty" hidden>Nothing found. Try another word.</p>
  <?php foreach ($sMenu as $label => $items): ?>
    <div class="settings-side-section"><?= $sE($label) ?></div>
    <ul>
      <?php foreach ($items as $it): $active = !empty($it['active']); ?>
        <li<?= !empty($it['search_only']) ? ' class="search-only" hidden' : '' ?> data-find="<?= $sE(mb_strtolower($it['label'] . ' ' . $it['desc'] . ' ' . $it['find'])) ?>"><a href="<?= $sE($sBase . $it['href']) ?>"<?= $active ? ' class="active" aria-current="page"' : '' ?>><i class="ui-icon fa <?= $sE($it['icon']) ?>" aria-hidden="true"></i><span class="settings-side-text"><?= $sE($it['label']) ?><small class="settings-side-desc"><?= $sE($it['desc']) ?></small></span></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>
</aside>
<script>
(() => {
  const side = document.currentScript.previousElementSibling;
  // On narrow screens the list is a sideways strip: bring the current page into view.
  const a = side.querySelector('a.active'); if (a && window.innerWidth <= 860) a.scrollIntoView({ block: 'nearest', inline: 'center' });

  // ------------------------------------------------------------ search
  const input = side.querySelector('#settingsSearch'), empty = side.querySelector('#settingsSearchEmpty');
  const fold = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const items = [...side.querySelectorAll('li[data-find]')].map((li) => ({
    li, text: fold(li.dataset.find), link: li.querySelector('a'),
    name: fold(li.querySelector('.settings-side-text').firstChild.textContent), desc: fold(li.querySelector('.settings-side-desc').textContent),
  }));
  // Enter opens the best result: a word in the name counts most, then in the description.
  const best = () => {
    const w = words();
    let top = null, topScore = -1;
    for (const it of items) {
      if (it.li.hidden) continue;
      const score = w.reduce((n, x) => n + (it.name.includes(x) ? 3 : it.desc.includes(x) ? 2 : 1), 0);
      if (score > topScore) { top = it; topScore = score; }
    }
    return top;
  };
  const words = () => fold(input.value).split(/\s+/).filter(Boolean);
  function filter() {
    const w = words();
    side.classList.toggle('searching', w.length > 0);
    let shown = 0;
    for (const it of items) {
      // Every word typed is in its name, description or search words (part of a word is enough).
      const hit = !w.length ? !it.li.classList.contains('search-only') : w.every((x) => it.text.includes(x));
      it.li.hidden = !hit; if (hit && w.length) shown++;
      const href = it.link.getAttribute('href').replace(/([?&])find=[^&]*&?/, '$1').replace(/[?&]$/, '');
      it.link.setAttribute('href', w.length ? href + (href.includes('?') ? '&' : '?') + 'find=' + encodeURIComponent(input.value.trim()) : href);
    }
    side.querySelectorAll('ul').forEach((ul) => { ul.hidden = ![...ul.children].some((li) => !li.hidden); });
    side.querySelectorAll('.settings-side-section').forEach((s) => { s.hidden = s.nextElementSibling.hidden; });
    empty.hidden = !w.length || shown > 0;
  }
  input.addEventListener('input', filter);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { const it = best(); if (it) location.href = it.link.href; e.preventDefault(); }
    if (e.key === 'Escape') { input.value = ''; filter(); }
    if (e.key === 'ArrowDown') { const first = items.find((it) => !it.li.hidden); if (first) { first.link.focus(); e.preventDefault(); } }
  });
  // "/" anywhere on a settings page (not while typing) jumps to the search.
  document.addEventListener('keydown', (e) => {
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || e.target.closest('input, textarea, select, [contenteditable="true"]')) return;
    e.preventDefault(); input.focus();
  });

  // ------------------------------------------- arriving from a search: show the setting
  const find = new URLSearchParams(location.search).get('find');
  if (!find) return;
  input.value = find; filter();
  document.addEventListener('DOMContentLoaded', () => {
    const main = side.parentElement.querySelector('main') || document.body;
    const want = fold(find).split(/\s+/).filter(Boolean);
    const places = [...main.querySelectorAll('h1, h2, h3, legend, label, .checkbox-label, summary, strong, .ap-reply-mode > span, p')]
      .filter((el) => el.offsetParent !== null);
    // The most words found, the first such place on the page.
    let best = null, bestScore = 0;
    for (const el of places) {
      const t = fold(el.textContent || '');
      const score = want.filter((x) => t.includes(x)).length;
      if (score > bestScore) { best = el; bestScore = score; }
    }
    if (!best || best.tagName === 'H1') return;
    const box = best.closest('fieldset, section, .panel, label') || best;
    box.scrollIntoView({ block: 'center', behavior: 'smooth' });
    box.classList.add('settings-found');
    setTimeout(() => box.classList.remove('settings-found'), 2600);
  });
})();
</script>
