<?php declare(strict_types=1);
/**
 * Help (lib/help.php): every how-to, by topic — or, with ?format=json, the
 * articles for the help panel (assets/help.js), fetched only when it opens.
 */
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/help.php';
$context = ecw_require_login();
$articles = ecw_help_articles($context);

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    echo json_encode(['topics' => array_map(static fn($t) => ['title' => $t[0], 'icon' => $t[1]], ECW_HELP_TOPICS), 'articles' => $articles, 'tours' => (object)ecw_help_tours($context)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    exit;
}

$navActive = 'settings'; $base = ecw_web_base();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$tours = array_filter(ecw_help_tours($context), static fn($t) => $t['href'] !== null);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Help — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= $h($base) ?>assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>"><link rel="stylesheet" href="<?= $h($base) ?>assets/auth.css"></head>
<body class="page-body"><?php require __DIR__ . '/partials/nav.php'; ?>
<main class="page help-page">
  <section class="help-hero">
    <h1>How can we help?</h1>
    <p>Short answers, and tours that show you around the real screens. Press <kbd>?</kbd> on any page for the answers about that page.</p>
    <label class="help-searchbox"><i class="fa fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" id="helpPageSearch" class="help-search" placeholder="How do I…?" aria-label="Search the help" autofocus></label>
  </section>

  <?php if ($tours): ?>
  <section class="help-page-section" id="helpTours">
    <h2><i class="fa fa-play" aria-hidden="true"></i> Take a tour</h2>
    <div class="help-tours">
      <?php foreach ($tours as $tour): ?>
        <button type="button" class="help-tour" data-help-tour="<?= $h($tour['key']) ?>">
          <span class="help-tour-icon"><i class="fa fa-play" aria-hidden="true"></i></span>
          <span class="help-tour-text"><strong><?= $h($tour['title']) ?></strong><span>30 seconds, on the real page</span></span>
        </button>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="help-page-section">
    <h2><i class="fa fa-lightbulb" aria-hidden="true"></i> Answers</h2>
    <div class="help-chips" id="helpPageTopics">
      <button type="button" class="help-chip active" data-topic="">All</button>
      <?php foreach (ECW_HELP_TOPICS as $key => [$title, $icon]): if (!array_filter($articles, static fn($a) => $a['topic'] === $key)) continue; ?>
        <button type="button" class="help-chip" data-topic="<?= $h($key) ?>"><i class="<?= str_starts_with($icon, 'ecw-') ? $h($icon) : 'fa ' . $h($icon) ?>" aria-hidden="true"></i> <?= $h($title) ?></button>
      <?php endforeach; ?>
    </div>
    <div id="helpPageCards">
      <?php foreach ($articles as $a): ?>
        <div class="help-card" id="<?= $h($a['slug']) ?>" data-topic="<?= $h($a['topic']) ?>" data-words="<?= $h(mb_strtolower($a['title'] . ' ' . $a['summary'] . ' ' . $a['words'] . ' ' . strip_tags($a['body']))) ?>">
          <button type="button" class="help-card-head" aria-expanded="false">
            <span class="help-card-text"><strong><?= $h($a['title']) ?></strong><span class="help-card-sum"><?= $h($a['summary']) ?></span></span>
            <i class="fa fa-chevron-down" aria-hidden="true"></i>
          </button>
          <div class="help-card-more">
            <div class="help-body"><?= $a['body'] /* trusted: written in lib/help.php */ ?></div>
            <?php if ($a['show']): ?>
              <button type="button" class="help-show" data-help-show="<?= $h($a['slug']) ?>"><i class="fa fa-<?= $a['show']['target'] ? 'hand-pointer' : 'arrow-right' ?>" aria-hidden="true"></i> <?= $a['show']['target'] ? 'Show me' : 'Go there' ?></button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="help-empty" id="helpNone" hidden><i class="fa fa-circle-question" aria-hidden="true"></i><p>Nothing found. Try another word, or take a tour.</p></div>
  </section>
</main>
<script>
(() => {
  const cards = [...document.querySelectorAll('#helpPageCards .help-card')];
  const toggle = (card, open) => {
    card.classList.toggle('open', open);
    card.querySelector('.help-card-head').setAttribute('aria-expanded', String(open));
  };
  cards.forEach((c) => c.querySelector('.help-card-head').addEventListener('click', () => toggle(c, !c.classList.contains('open'))));
  // #slug opens that answer.
  const open = () => {
    const c = location.hash && document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if (c && c.classList.contains('help-card')) { toggle(c, true); c.classList.add('flash'); c.scrollIntoView({ block: 'center' }); }
  };
  open(); window.addEventListener('hashchange', open);
  const q = document.getElementById('helpPageSearch');
  let topic = '';
  const filter = () => {
    const words = q.value.toLowerCase().split(/\s+/).filter(Boolean);
    let shown = 0;
    cards.forEach((c) => {
      const hit = (!topic || c.dataset.topic === topic) && words.every((w) => c.dataset.words.includes(w));
      c.hidden = !hit;
      if (hit) shown++;
    });
    document.getElementById('helpNone').hidden = shown > 0;
    const tours = document.getElementById('helpTours');
    if (tours) tours.hidden = words.length > 0;
    if (words.length && shown === 1) cards.filter((c) => !c.hidden).forEach((c) => toggle(c, true));
  };
  q.addEventListener('input', filter);
  document.getElementById('helpPageTopics').addEventListener('click', (e) => {
    const chip = e.target.closest('.help-chip');
    if (!chip) return;
    topic = chip.dataset.topic;
    document.querySelectorAll('#helpPageTopics .help-chip').forEach((b) => b.classList.toggle('active', b === chip));
    filter();
  });
})();
</script>
</body></html>