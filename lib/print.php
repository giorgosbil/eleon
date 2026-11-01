<?php declare(strict_types=1);
/**
 * Printable conversation document (case export and single-email print).
 *
 * Laid out like the chat view on screen: received mail on the left, sent mail
 * on the right, internal notes and Teams messages in the middle, a date line
 * when the day changes and a quiet "3 h later" between messages that are far
 * apart. Remote images are never loaded. Bodies are the already-sanitized HTML
 * from ecw_case_timeline() / ecw_thread_detail(); everything else is escaped.
 */

require_once __DIR__ . '/db.php';

/**
 * Download bodies that were never fetched, so the print is not full of empty
 * bubbles. At most $limit messages, failures are ignored (the bubble then says so).
 *
 * @param array<int,array{id:int,mailbox_id:int}> $messages
 */
function ecw_print_fetch_missing(array $messages, int $limit = 40): bool
{
    require_once __DIR__ . '/sync/messages.php';
    $fetched = false;
    $st = ecw_db()->prepare('SELECT provider_message_id FROM `' . ecw_table('email_messages') . "` WHERE id = ? AND mailbox_id = ? AND provider_removed_at IS NULL AND body_fetch_state <> 'fetched'");
    foreach (array_slice($messages, 0, $limit) as $m) {
        $st->execute([(int)$m['id'], (int)$m['mailbox_id']]);
        $provider = $st->fetchColumn();
        if ($provider === false) continue;
        try { ecw_store_full_message((int)$m['mailbox_id'], (int)$m['id'], (string)$provider); $fetched = true; }
        catch (Throwable $e) { ecw_log('print: body ' . $m['id'] . ' not fetched: ' . $e->getMessage()); }
    }
    return $fetched;
}

/** "3 h later", "2 days later" — or '' when the messages are close together. */
function ecw_print_gap(int $seconds): string
{
    if ($seconds < 3600) return '';
    if ($seconds < 86400) { $h = (int)round($seconds / 3600); return $h . ' h later'; }
    $d = (int)round($seconds / 86400);
    if ($d < 14) return $d . ' day' . ($d === 1 ? '' : 's') . ' later';
    $w = (int)round($d / 7);
    if ($d < 60) return $w . ' weeks later';
    return (int)round($d / 30) . ' months later';
}

/**
 * @param array $doc {
 *   title, heading, subheading?, facts: [[label, value]], tz: DateTimeZone,
 *   items: [ {t, kind: in|out|note|teams|chat, label? (chat: "WhatsApp"…), who, to?, cc?, subject?, thread?, html, attachments?: string[], about?, removed?, empty?} ],
 *   links?: [[href, label]], footer, note? }
 */
function ecw_print_render(array $doc): void
{
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $tz = $doc['tz'];
    $fmt = static fn(int $t, string $f): string => (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format($f);
    $norm = static fn(?string $s): string => mb_strtolower(trim((string)preg_replace('/^\s*((re|fw|fwd|aw|wg|σχετ|πρθ)\s*:\s*)+/iu', '', (string)$s)));
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($doc['title']) ?></title>
<style>
  :root { --in:#f1f3f6; --in-b:#dfe3e8; --out:#e7f0ff; --out-b:#c9dcfb; --note:#fff7d6; --note-b:#ecd98a; --teams:#efeefb; --teams-b:#cfcdf0; --ink:#1f2328; --muted:#5f6b78; }
  * { box-sizing:border-box; }
  body { font:13px/1.5 "Segoe UI", Helvetica, Arial, sans-serif; color:var(--ink); max-width:860px; margin:0 auto; padding:0 22px 40px; background:#fff; }
  .toolbar { position:sticky; top:0; z-index:2; background:#fff; padding:10px 0; margin-bottom:10px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; border-bottom:1px solid #d0d7de; }
  .toolbar button, .toolbar a { font:inherit; padding:6px 12px; border:1px solid #d0d7de; border-radius:7px; background:#f6f8fa; color:var(--ink); text-decoration:none; cursor:pointer; }
  .toolbar button.primary { background:#0057ff; border-color:#0057ff; color:#fff; font-weight:600; }
  .toolbar .hint { color:var(--muted); font-size:12px; }
  header.doc { border-bottom:2px solid var(--ink); padding:6px 0 12px; margin-bottom:6px; }
  header.doc h1 { margin:0 0 2px; font-size:21px; letter-spacing:-.01em; }
  header.doc .sub { color:var(--muted); margin:0 0 8px; }
  dl { display:grid; grid-template-columns:max-content 1fr max-content 1fr; gap:2px 16px; margin:6px 0 0; font-size:12px; }
  dt { color:var(--muted); } dd { margin:0; }
  .chat { display:flex; flex-direction:column; gap:14px; padding-top:8px; }
  .day { display:flex; align-items:center; gap:12px; margin:14px 0 2px; color:var(--muted); font-size:11.5px; font-weight:600; letter-spacing:.02em; break-after:avoid; }
  .day::before, .day::after { content:""; flex:1; height:1px; background:#d8dde3; }
  .gap { align-self:center; padding:1px 10px; border:1px dashed #cfd5dc; border-radius:999px; color:var(--muted); font-size:10.5px; margin:-4px 0; break-after:avoid; }
  .msg { max-width:80%; border:1px solid; border-radius:14px; padding:9px 13px 10px; break-inside:avoid; page-break-inside:avoid; }
  .msg.in { align-self:flex-start; background:var(--in); border-color:var(--in-b); border-top-left-radius:4px; }
  .msg.out { align-self:flex-end; background:var(--out); border-color:var(--out-b); border-top-right-radius:4px; }
  .msg.note, .msg.teams, .msg.chat { align-self:center; max-width:72%; border-radius:10px; }
  .msg.chat { background:#eefaf3; border-color:#cfe9da; }
  .msg.note { background:var(--note); border-color:var(--note-b); }
  .msg.teams { background:var(--teams); border-color:var(--teams-b); }
  .head { display:flex; justify-content:space-between; align-items:baseline; gap:12px; margin-bottom:2px; }
  .head strong { font-size:12.5px; }
  .head time { color:var(--muted); font-size:11px; white-space:nowrap; }
  .meta { color:var(--muted); font-size:11px; margin-bottom:6px; overflow-wrap:anywhere; }
  .kind { text-transform:uppercase; letter-spacing:.06em; font-size:9.5px; font-weight:700; color:var(--muted); margin-right:6px; }
  .subject { font-weight:600; font-size:12px; margin:0 0 6px; }
  .thread { display:inline-block; margin-left:6px; padding:0 7px; border-radius:999px; background:#fff; border:1px solid #d8dde3; font-size:10px; color:var(--muted); }
  .body { overflow-wrap:anywhere; }
  .body p { margin:0 0 6px; } .body p:last-child { margin-bottom:0; }
  .body img { max-width:100%; height:auto; }
  .body table { max-width:100%; }
  .body blockquote { margin:6px 0 0 8px; padding-left:8px; border-left:2px solid #c9ced6; color:var(--muted); }
  .sig { margin-top:8px; padding-top:6px; border-top:1px solid rgba(0,0,0,.08); font-size:12px; color:#3d4650; }
  .files { margin-top:8px; font-size:11.5px; color:#3d4650; }
  .about { font-size:11px; color:var(--muted); border-left:2px solid var(--note-b); padding-left:7px; margin-bottom:5px; }
  .empty { color:var(--muted); font-style:italic; }
  .mention { font-weight:600; }
  footer.doc { margin-top:26px; padding-top:8px; border-top:1px solid #d0d7de; font-size:11px; color:var(--muted); }
  @media print {
    .toolbar { display:none; }
    body { margin:0; max-width:none; padding:0; }
    * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    a { color:inherit; text-decoration:none; }
    @page { margin:14mm 12mm; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <button type="button" class="primary" onclick="window.print()">Print / Save as PDF</button>
  <?php foreach ($doc['links'] ?? [] as [$href, $label]): ?><a href="<?= $h($href) ?>"><?= $h($label) ?></a><?php endforeach; ?>
  <span class="hint">Choose “Save as PDF” as the printer to get a PDF file.</span>
</div>
<header class="doc">
  <h1><?= $h($doc['heading']) ?></h1>
  <?php if (!empty($doc['subheading'])): ?><p class="sub"><?= $h($doc['subheading']) ?></p><?php endif; ?>
  <?php if (!empty($doc['facts'])): ?><dl><?php foreach ($doc['facts'] as [$label, $value]): ?><dt><?= $h($label) ?></dt><dd><?= $h($value) ?></dd><?php endforeach; ?></dl><?php endif; ?>
</header>
<main class="chat">
<?php if ($doc['items'] === []): ?><p class="empty">Nothing to print yet.</p><?php endif; ?>
<?php $prevT = null; $prevDay = null; $prevSubject = []; ?>
<?php foreach ($doc['items'] as $it):
    $day = $fmt($it['t'], 'Y-m-d');
    if ($day !== $prevDay): ?>
  <div class="day"><?= $h($fmt($it['t'], 'l j F Y')) ?><?= $prevT !== null && ($g = ecw_print_gap($it['t'] - $prevT)) !== '' && $it['t'] - $prevT >= 86400 ? ' · ' . $h($g) : '' ?></div>
<?php elseif ($prevT !== null && ($g = ecw_print_gap($it['t'] - $prevT)) !== ''): ?>
  <div class="gap"><?= $h($g) ?></div>
<?php endif; $prevT = $it['t']; $prevDay = $day; ?>
  <article class="msg <?= $h($it['kind']) ?>">
    <div class="head">
      <strong><?php if ($it['kind'] === 'note'): ?><span class="kind">Internal note</span><?php elseif ($it['kind'] === 'teams'): ?><span class="kind">Teams</span><?php elseif ($it['kind'] === 'chat'): ?><span class="kind"><?= $h($it['label'] ?? 'Chat') ?></span><?php endif; ?><?= $h($it['who']) ?></strong>
      <time><?= $h($fmt($it['t'], 'H:i')) ?></time>
    </div>
    <?php if ($it['kind'] === 'in' || $it['kind'] === 'out'): ?>
      <div class="meta">to <?= $h($it['to'] ?? '') ?><?= !empty($it['cc']) ? ' · cc ' . $h($it['cc']) : '' ?><?php if (!empty($it['thread'])): ?><span class="thread"><?= $h($it['thread']) ?></span><?php endif; ?></div>
      <?php $key = (string)($it['thread'] ?? ''); $subj = $norm($it['subject'] ?? ''); if (($it['subject'] ?? '') !== '' && ($prevSubject[$key] ?? null) !== $subj): $prevSubject[$key] = $subj; ?>
        <p class="subject"><?= $h($it['subject']) ?></p>
      <?php endif; ?>
    <?php elseif (!empty($it['thread'])): ?>
      <div class="meta"><span class="thread"><?= $h($it['thread']) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($it['about'])): ?><div class="about">About <?= $h($it['about']) ?></div><?php endif; ?>
    <div class="body"><?php if (trim(strip_tags((string)$it['html'], '<img>')) === ''): ?><span class="empty"><?= !empty($it['removed']) ? 'Deleted in the mailbox before its content was downloaded.' : '(No text in this message.)' ?></span><?php else: ?><?= $it['html'] /* sanitized by the caller's source */ ?><?php endif; ?></div>
    <?php if (!empty($it['signature'])): ?><div class="sig"><?= $it['signature'] /* sanitized */ ?></div><?php endif; ?>
    <?php if (!empty($it['attachments'])): ?><div class="files">📎 <?= $h(implode(' · ', $it['attachments'])) ?></div><?php endif; ?>
  </article>
<?php endforeach; ?>
</main>
<?php if (!empty($doc['note'])): ?><p><em><?= $h($doc['note']) ?></em></p><?php endif; ?>
<footer class="doc"><?= $h($doc['footer']) ?></footer>
</body>
</html>
<?php
}
