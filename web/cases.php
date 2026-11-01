<?php declare(strict_types=1);
/**
 * Email Case Workspace — the case list.
 *
 * Task 06: filters. Server-rendered first paint calls the exact same
 * ecw_case_list() the case_list API action calls (lib/cases.php), so a
 * no-JS request and a live re-filter from assets/caseList.js never
 * disagree about what matches. JS progressively enhances this page into a
 * debounced, URL-synced filter bar; without it, the filter bar still works
 * as a plain GET form.
 */

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/sla.php';   // ecw_sla_label() in the first paint
require_once dirname(__DIR__) . '/lib/timezone.php';   // times in the viewer's zone

$context = ecw_require_login();
$orgId   = $context['organization_id'];
$userId  = (int)$context['user']['id'];
$csrf    = ecw_csrf_token();

require_once dirname(__DIR__) . '/lib/account.php';
$accountId  = ecw_active_account_id($orgId, $userId);   // the account chosen at the top
$opts       = ecw_case_list_opts_from_get();
$result     = ecw_case_list($orgId, ['user_id' => $userId, 'account_id' => $accountId] + $opts);
$groups     = ecw_case_groups($orgId, $userId);   // not other people's personal groups
$cases      = $result['cases'];
$total      = $result['total'];
$page       = $result['page'];
$perPage    = $result['per_page'];
$totalPages = max(1, (int)ceil($total / $perPage));

$mailboxes = [];   // the account picker at the top (lib/account.php) took the place of the Mailbox filter

$counts = [];
$countParams = [$orgId];
$st = ecw_db()->prepare(
    'SELECT c.status, COUNT(*) AS n FROM `' . ecw_table('cases') . '` c WHERE c.organization_id = ? AND c.deleted_at IS NULL AND '
    . ecw_case_visible_sql('c', $userId, $countParams) . ' GROUP BY c.status'
);
$st->execute($countParams);
foreach ($st->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['n'];
}

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$navActive = 'cases';

// Task 07 — row settings menu (the case page itself uses a pencil and a delete icon)
// in assets/case.js: one array, so a third action is one line in both places.
$caseRowActions = ['rename' => 'Rename', 'delete' => 'Delete'];

// The three groups, each followed by the company's own statuses (Settings → Case settings).
require_once dirname(__DIR__) . '/lib/case_settings.php';
$statusLabels = [];
foreach (ecw_case_status_options((int)$orgId) as $opt) $statusLabels[$opt['value']] = ($opt['custom'] ? "\u{2003}" : '') . $opt['label'];
$statusLabels['all'] = 'All';
$sortLabels   = ['last_activity' => 'Last activity', 'created' => 'Created date', 'name' => 'Name', 'threads' => 'Thread count',
                 'number' => 'Case number', 'status' => 'Status', 'group' => 'Group', 'owner' => 'Responsible', 'handler' => 'Handled by', 'due' => 'Due date', 'next' => 'Next step'];
$dateLabels   = ['' => 'Any time', 'today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'custom' => 'Custom range'];
$attachLabels = ['' => 'Any', 'yes' => 'Has attachments', 'no' => 'No attachments'];
// Filters panel: people (Responsible / Handled by), waiting on, due, and the tags in use.
$filterPeople = array_values(array_filter(ecw_organization_users($orgId), static fn(array $u): bool => $u['disabled_at'] === null));
$waitingLabels = ['' => 'Anyone or nobody', 'us' => 'Us', 'customer' => 'Customer', 'supplier' => 'Supplier', 'internal' => 'Internal', 'none' => 'Nobody set'];
$dueLabels = ['' => 'Any', 'overdue' => 'Overdue', 'today' => 'Today', 'week' => 'Next 7 days', 'set' => 'Has a due date', 'none' => 'No due date'];
$tagSt = ecw_db()->prepare('SELECT DISTINCT tg.tag FROM `' . ecw_table('case_tags') . '` tg JOIN `' . ecw_table('cases') . '` c ON c.id = tg.case_id WHERE c.organization_id = ? AND c.deleted_at IS NULL ORDER BY tg.tag LIMIT 200');
$tagSt->execute([$orgId]);
$filterTags = $tagSt->fetchAll(PDO::FETCH_COLUMN);

// Active filter chips — mirrored in assets/caseList.js (renderChips) for the
// live-updated view. Each chip's data-chip-key names what "Clear all minus
// this one" looks like; the JS click handler resets exactly that slice of
// state back to its neutral value.
$chips = [];
if ($opts['status'] !== '' && $opts['status'] !== 'all') {
    $chips[] = ['key' => 'status', 'label' => 'Status: ' . preg_replace('/^[\s\x{2003}]+/u', '', $statusLabels[$opts['status']] ?? $opts['status'])];
}
foreach ($opts['mailbox_ids'] as $mid) {
    foreach ($mailboxes as $mb) {
        if ((int)$mb['id'] === $mid) {
            $chips[] = ['key' => 'mailbox:' . $mid, 'label' => 'Mailbox: ' . ($mb['display_name'] ?: $mb['email_address'])];
        }
    }
}
if ($opts['participant'] !== '') {
    $chips[] = ['key' => 'participant', 'label' => 'Participant: ' . $opts['participant']];
}
if ($opts['date_preset'] !== '') {
    $dateChipLabel = $opts['date_preset'] === 'custom'
        ? 'Last activity: ' . ($opts['date_from'] ?: '…') . ' – ' . ($opts['date_to'] ?: '…')
        : 'Last activity: ' . ($dateLabels[$opts['date_preset']] ?? $opts['date_preset']);
    $chips[] = ['key' => 'date', 'label' => $dateChipLabel];
}
if ($opts['has_attachments'] !== '') {
    $chips[] = ['key' => 'has_attachments', 'label' => $attachLabels[$opts['has_attachments']] ?? $opts['has_attachments']];
}
if ($opts['q'] !== '') {
    $chips[] = ['key' => 'q', 'label' => 'Search: ' . $opts['q']];
}

// Everything the list script needs to draw the rows, the quick views, the
// bulk bar and the New case dialog (assets/caseList.js draws the table).
require_once dirname(__DIR__) . '/lib/checklists.php';
$canTemplates = ecw_feature_allowed('case_templates');
$views = ecw_case_view_counts($orgId, $userId, $accountId);
$bootstrap = [
    'filters'  => $opts,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
    'cases'    => ecw_feature_allowed('sla') ? $cases : array_map(static fn($c) => ['sla' => null] + $c, $cases),
    'views'    => $views,
    'me'       => $userId,
    'people'   => array_map(static fn($p) => ['id' => (int)$p['id'], 'name' => (string)($p['name'] ?: $p['email']), 'email' => (string)$p['email'], 'avatar_url' => $p['avatar_url'] ?? null], ecw_org_users($orgId)),
    'groups'   => array_map(static fn($g) => ['id' => (int)$g['id'], 'name' => $g['name'], 'color' => $g['color']], $groups),
    'templates' => $canTemplates ? array_map(static fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'steps' => count($t['steps']), 'description' => $t['description']], ecw_case_templates($orgId)) : [],
    'handler'  => ecw_local_features(),
    'due'      => ecw_feature_allowed('case_extras'),
    'next'     => ecw_feature_allowed('next_actions'),
];
// The Split layout (list + preview, assets/case-preview.js) is switched off: people found
// it harder to read than the list. Its script, styles (.cases-split) and api case_preview
// are kept; to bring it back, restore the List/Split switch, the preview aside and the
// script tag here (see CHANGELOG, 1.1.0).
if (!$bootstrap['next']) $bootstrap['cases'] = array_map(static fn($c) => ['next' => null] + $c, $bootstrap['cases']);
$viewLabels = ['all' => 'All', 'open' => 'Open', 'mine' => 'My cases', 'review' => 'Look at today', 'supplier' => 'Waiting on supplier', 'nonext' => 'No next step',
    'unassigned' => 'Unassigned', 'overdue' => 'Overdue', 'week' => 'Due this week', 'waiting' => 'Waiting for the customer'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cases — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="assets/app.css">
<link rel="stylesheet" href="assets/auth.css">
</head>
<body class="page-body" data-csrf="<?= $h($csrf) ?>">
<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="page wide cases-page">
  <div class="cases-head btn-scope">
    <h1>Cases</h1>

    <button type="button" class="primary" id="newCaseBtn"><i class="fa fa-plus" aria-hidden="true"></i> New case</button>
  </div>

  <nav class="case-views" id="caseViews" aria-label="Quick views">
    <a href="#" class="help-link case-views-help" data-help="cases-list"><i class="fa fa-circle-question" aria-hidden="true"></i> How this works</a>
    <?php foreach ($viewLabels as $key => $label): if (in_array($key, ['overdue', 'week'], true) && !$bootstrap['due']) continue;
      if (in_array($key, ['review', 'supplier', 'nonext'], true) && !$bootstrap['next']) continue;
      $active = in_array($key, ['all', 'open', 'waiting'], true) ? ($opts['view'] === '' && $opts['status'] === $key) : $opts['view'] === $key; ?>
      <button type="button" class="case-view<?= $active ? ' active' : '' ?><?= in_array($key, ['overdue', 'review'], true) && ($views[$key] ?? 0) > 0 ? ' alert' : '' ?>" data-view="<?= $h($key) ?>" aria-pressed="<?= $active ? 'true' : 'false' ?>">
        <?= $h($label) ?><?php if (isset($views[$key])): ?> <span class="case-view-count"><?= (int)$views[$key] ?></span><?php endif; ?>
      </button>
    <?php endforeach; ?>
  </nav>

  <nav class="case-group-tabs" aria-label="Case groups">
    <button type="button" class="case-group-tab<?= $opts['group_ids'] === [] ? ' active' : '' ?>" data-group-id="0" aria-pressed="<?= $opts['group_ids'] === [] ? 'true' : 'false' ?>">All groups</button>
    <?php foreach ($groups as $g): $on = in_array((int)$g['id'], $opts['group_ids'], true); ?>
      <button type="button" class="case-group-tab<?= $on ? ' active' : '' ?>" data-group-id="<?= (int)$g['id'] ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>" title="Click to add or remove this group" style="--group-color:<?= $h($g['color'] ?: '#94a3b8') ?>"><?= $h($g['name']) ?></button>
    <?php endforeach; ?>
    <a class="case-group-manage" href="settings/case-groups.php"><i class="fa fa-gear" aria-hidden="true"></i> Manage groups</a>
  </nav>

  <div class="case-filter-bar" id="caseFilterBar">
    <div class="case-filter-row">
      <input type="search" id="caseSearchInput" class="case-search-input"
             value="<?= $h($opts['q']) ?>" placeholder="Case name or thread subject" autocomplete="off">

      <details class="case-filters" id="caseFiltersPopover">
        <summary aria-label="Filters" title="Filters">Filters</summary>
        <div class="case-filter-panel">
          <div class="saved-filters" id="savedFilters" aria-label="Saved filters" hidden></div>

          <?php if (count($mailboxes) > 1): ?>
          <fieldset class="case-filter-group">
            <legend>Mailbox</legend>
            <?php foreach ($mailboxes as $mb): ?>
              <label class="case-filter-check">
                <input type="checkbox" class="case-filter-mailbox" value="<?= (int)$mb['id'] ?>"
                       <?= in_array((int)$mb['id'], $opts['mailbox_ids'], true) ? 'checked' : '' ?>>
                <?= $h($mb['display_name'] ?: $mb['email_address']) ?>
              </label>
            <?php endforeach; ?>
          </fieldset>
          <?php endif; ?>

          <label class="case-filter-group">
            <span>Participant</span>
            <input type="text" id="caseFilterParticipant" value="<?= $h($opts['participant']) ?>" placeholder="Name or email">
          </label>

          <fieldset class="case-filter-group">
            <legend>Last activity</legend>
            <select id="caseFilterDatePreset" class="ot-select">
              <?php foreach ($dateLabels as $val => $label): ?>
                <option value="<?= $h($val) ?>" <?= $opts['date_preset'] === $val ? 'selected' : '' ?>><?= $h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="case-filter-daterange" id="caseFilterDateRange" <?= $opts['date_preset'] === 'custom' ? '' : 'hidden' ?>>
              <input type="date" id="caseFilterDateFrom" value="<?= $h($opts['date_from']) ?>">
              <input type="date" id="caseFilterDateTo" value="<?= $h($opts['date_to']) ?>">
            </div>
          </fieldset>

          <?php $personOptions = static function (int $current) use ($filterPeople, $h): string {
              $out = '<option value="0">Anyone</option><option value="-1"' . ($current === -1 ? ' selected' : '') . '>Nobody</option>';
              foreach ($filterPeople as $u) $out .= '<option value="' . (int)$u['id'] . '"' . ((int)$u['id'] === $current ? ' selected' : '') . '>' . $h($u['name'] ?: $u['email']) . '</option>';
              return $out;
          }; ?>
          <div class="case-filter-two">
            <label class="case-filter-group"><span>Responsible</span>
              <select id="caseFilterOwner" class="ot-select" data-filter-key="owner" data-filter-label="Responsible"><?= $personOptions((int)$opts['owner']) ?></select></label>
            <label class="case-filter-group"><span>Handled by</span>
              <select id="caseFilterHandler" class="ot-select" data-filter-key="handler" data-filter-label="Handled by"><?= $personOptions((int)$opts['handler']) ?></select></label>
            <label class="case-filter-group"><span>Waiting on</span>
              <select id="caseFilterWaiting" class="ot-select" data-filter-key="waiting" data-filter-label="Waiting on"><?php foreach ($waitingLabels as $v => $l): ?><option value="<?= $h($v) ?>" <?= $opts['waiting'] === $v ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select></label>
            <label class="case-filter-group"><span>Tag</span>
              <select id="caseFilterTag" class="ot-select" data-filter-key="tag" data-filter-label="Tag"><option value="">Any</option><?php foreach ($filterTags as $t): ?><option value="<?= $h($t) ?>" <?= $opts['tag'] === $t ? 'selected' : '' ?>><?= $h($t) ?></option><?php endforeach; ?></select></label>
          </div>

          <fieldset class="case-filter-group">
            <legend>Due</legend>
            <select id="caseFilterDue" class="ot-select" data-filter-key="due" data-filter-label="Due"><?php foreach ($dueLabels as $v => $l): ?><option value="<?= $h($v) ?>" <?= $opts['due'] === $v ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select>
            <div class="case-filter-daterange">
              <input type="date" id="caseFilterDueFrom" data-filter-key="due_from" data-filter-label="Due from" value="<?= $h($opts['due_from']) ?>" aria-label="Due from">
              <input type="date" id="caseFilterDueTo" data-filter-key="due_to" data-filter-label="Due until" value="<?= $h($opts['due_to']) ?>" aria-label="Due until">
            </div>
          </fieldset>

          <fieldset class="case-filter-group">
            <legend>Created</legend>
            <div class="case-filter-daterange">
              <input type="date" id="caseFilterCreatedFrom" data-filter-key="created_from" data-filter-label="Created from" value="<?= $h($opts['created_from']) ?>" aria-label="Created from">
              <input type="date" id="caseFilterCreatedTo" data-filter-key="created_to" data-filter-label="Created until" value="<?= $h($opts['created_to']) ?>" aria-label="Created until">
            </div>
          </fieldset>

          <label class="case-filter-group">
            <span>Attachment name</span>
            <input type="text" id="caseFilterFile" data-filter-key="file" data-filter-label="Attachment" value="<?= $h($opts['file']) ?>" placeholder="offer, *.step, drawing*.pdf">
          </label>

          <label class="case-filter-check">
            <input type="checkbox" id="caseFilterUnread" data-filter-key="unread" data-filter-label="Unread emails" value="yes" <?= $opts['unread'] === 'yes' ? 'checked' : '' ?>> Only cases with unread emails
          </label>

          <label class="case-filter-group">
            <span>Attachments</span>
            <select id="caseFilterAttachments" class="ot-select">
              <?php foreach ($attachLabels as $val => $label): ?>
                <option value="<?= $h($val) ?>" <?= $opts['has_attachments'] === $val ? 'selected' : '' ?>><?= $h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="case-filter-group">
            <span>Status</span>
            <select id="caseFilterStatus" class="ot-select">
              <?php foreach ($statusLabels as $val => $label): ?>
                <option value="<?= $h($val) ?>" <?= $opts['status'] === $val ? 'selected' : '' ?>><?= $h($label) ?><?= isset($counts[$val]) ? ' (' . (int)$counts[$val] . ')' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="case-filter-group">
            <span>Sort by</span>
            <select id="caseFilterSort" class="ot-select">
              <?php foreach ($sortLabels as $val => $label): ?>
                <option value="<?= $h($val) ?>" <?= $opts['sort'] === $val ? 'selected' : '' ?>><?= $h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <div class="case-filter-actions">
            <button type="button" id="savedFilterAdd" class="saved-filter-add" hidden><i class="fa fa-bookmark" aria-hidden="true"></i> Save this filter</button>
            <button type="button" id="caseFiltersApply" class="button primary">Apply</button>
          </div>
        </div>
      </details>

      <button type="button" id="caseFiltersClearAll" class="button">Clear all</button>
    </div>

    <?php if ($chips !== []): ?>
    <div class="case-filter-chips" id="caseFilterChips">
      <?php foreach ($chips as $chip): ?>
        <span class="chip case-filter-chip" data-chip-key="<?= $h($chip['key']) ?>"><?= $h($chip['label']) ?>
          <button type="button" class="chip-remove" aria-label="Remove filter">&times;</button></span>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="case-filter-chips" id="caseFilterChips" hidden></div>
    <?php endif; ?>

    <div class="case-filter-count muted" id="caseFilterCount"><?= (int)$total ?> case<?= $total === 1 ? '' : 's' ?></div>
  </div>

  <div class="case-bulk btn-scope" id="caseBulk" hidden>
    <strong id="caseBulkCount">0 selected</strong>
    <button type="button" data-bulk="close"><i class="fa fa-check" aria-hidden="true"></i> Close</button>
    <button type="button" data-bulk="reopen"><i class="fa fa-rotate-left" aria-hidden="true"></i> Reopen</button>
    <button type="button" data-bulk="waiting"><i class="fa fa-hourglass-half" aria-hidden="true"></i> Waiting</button>
    <button type="button" data-bulk="owner"><i class="fa fa-user" aria-hidden="true"></i> Responsible…</button>
    <?php if ($bootstrap['handler']): ?><button type="button" data-bulk="handler"><i class="fa fa-user-gear" aria-hidden="true"></i> Handled by…</button><?php endif; ?>
    <?php if ($groups): ?><button type="button" data-bulk="group"><i class="fa fa-layer-group" aria-hidden="true"></i> Group…</button><?php endif; ?>
    <?php if ($bootstrap['due']): ?><button type="button" data-bulk="due"><i class="fa fa-calendar" aria-hidden="true"></i> Due date…</button><?php endif; ?>
    <button type="button" data-bulk="clear" class="case-bulk-clear">Clear selection</button>
  </div>

  <div id="caseListContainer">
    <?php if ($cases === []): ?>
      <p class="muted" id="caseEmptyState">No cases match these filters. <button type="button" id="caseEmptyClear" class="button">Clear filters</button></p>
    <?php else: ?>
      <table class="grid" id="caseTable" data-i18n-ctx="status">
        <thead>
          <tr><th>Case</th><th>Name</th><th>Group</th><th>Status</th><th>Responsible</th><th>Due</th><th>Emails</th><th>Created</th><th>Last activity</th><th></th></tr>
        </thead>
        <tbody id="caseTableBody">
          <?php foreach ($cases as $case): ?>
            <?php $unread = (int)($case['unread_count'] ?? 0); ?>
            <tr data-case-id="<?= (int)$case['id'] ?>" data-case-name="<?= $h($case['name']) ?>" data-case-threads="<?= (int)$case['thread_count'] ?>"<?= $unread > 0 ? ' class="has-unread"' : '' ?>>
              <td><a class="case-badge" href="case.php?id=<?= (int)$case['id'] ?>"><?= $h($case['case_number']) ?></a><?php if ($unread > 0): ?> <span class="unread-pill" title="<?= $unread ?> unread email<?= $unread === 1 ? '' : 's' ?>"><?= $unread ?></span><?php endif; ?></td>
              <td><a href="case.php?id=<?= (int)$case['id'] ?>"><?= $h($case['name']) ?></a><?php if (!empty($case['checklist'])): $cl = $case['checklist']; ?><a class="cl-chip<?= $cl['done'] === $cl['total'] ? ' all' : '' ?>" href="case.php?id=<?= (int)$case['id'] ?>#checklist" title="Checklist: <?= (int)$cl['done'] ?> of <?= (int)$cl['total'] ?> steps done"><i class="fa fa-list-check" aria-hidden="true"></i> <?= (int)$cl['done'] ?>/<?= (int)$cl['total'] ?></a><?php endif; ?><?php foreach ($case['tags'] ?? [] as $tg): ?><a class="tag-chip" href="cases.php?tag=<?= $h(rawurlencode($tg)) ?>&amp;status=all"><?= $h($tg) ?></a><?php endforeach; ?></td>
              <td><?php if ($case['group_name']): ?><span class="group-badge" style="--group-color:<?= $h($case['group_color'] ?: '#94a3b8') ?>"><?= $h($case['group_name']) ?></span><?php endif; ?><?php if ($case['visibility'] === 'members'): ?> <i class="fa fa-lock muted" title="Only selected people can see this case"></i><?php endif; ?></td>
              <td><?= $h($case['status']) ?><?php if (!empty($case['sla']) && ecw_feature_allowed('sla')): ?> <span class="sla-pill <?= $h($case['sla']['state']) ?>" title="Waiting for our reply since <?= $h(ecw_tz_local($case['sla']['waiting_since'], 'j M, H:i')) ?> · target <?= $h($case['sla']['target_hours']) ?> business hours"><i class="fa fa-stopwatch" aria-hidden="true"></i> <?= $h(ecw_sla_label($case['sla'])) ?></span><?php endif; ?></td>
              <td data-label="Responsible"><?= $h($case['owner_name'] ?? '—') ?></td>
              <?php $due = $case['due_at'] ?? null; $today ??= ecw_tz_company_today((int)$orgId); $dueClass = !$due || $case['status'] === 'closed' ? '' : ($due < $today ? 'due-late' : ($due === $today ? 'due-today' : '')); ?>
              <td class="<?= $due ? $dueClass : 'muted' ?>" data-label="Due"><?= $due ? $h(date('j M', strtotime($due))) : '—' ?></td>
              <td data-label="Emails"><?= (int)$case['thread_count'] ?></td>
              <td data-label="Created"><?= $h(ecw_tz_local($case['created_at'], 'j M')) ?></td>
              <td data-label="Last activity"<?= $case['last_activity'] ? '' : ' class="muted"' ?>><?= $h($case['last_activity'] ? ecw_tz_local($case['last_activity'], 'j M, H:i') : '—') ?></td>
              <td class="case-actions-col">
                <details class="menu case-settings-menu">
                  <summary aria-label="Case settings" title="Case settings">&#8942;</summary>
                  <div class="menu-body">
                    <?php foreach ($caseRowActions as $key => $label): ?>
                      <button type="button" class="menu-item<?= $key === 'delete' ? ' danger' : '' ?>" data-case-action="<?= $h($key) ?>"><?= $h($label) ?></button>
                    <?php endforeach; ?>
                  </div>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="case-pagination" id="casePagination">
    <?php if ($totalPages > 1): ?>
      <button type="button" id="casePagePrev" <?= $page <= 1 ? 'disabled' : '' ?>>&lsaquo; Prev</button>
      <span class="muted" id="casePaginationLabel">Page <?= (int)$page ?> of <?= (int)$totalPages ?></span>
      <button type="button" id="casePageNext" <?= $page >= $totalPages ? 'disabled' : '' ?>>Next &rsaquo;</button>
    <?php endif; ?>
  </div>
</main>

<!-- Delete case (task 07) -->
<div class="overlay" id="deleteCaseOverlay" hidden>
  <div class="dialog" role="dialog" aria-modal="true" aria-label="Delete case">
    <h2>Delete this case?</h2>
    <p id="deleteCaseBody"></p>
    <p class="muted">This removes the case grouping only. Emails stay in the mailbox and are never deleted or moved.</p>
    <footer>
      <button type="button" id="deleteCaseCancel">Cancel</button>
      <button type="button" id="deleteCaseConfirm" class="danger">Delete case</button>
    </footer>
  </div>
</div>

<!-- New case -->
<div class="overlay" id="newCaseOverlay" hidden>
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="newCaseTitle">
    <h2 id="newCaseTitle">New case</h2>
    <p class="muted small">For work that did not start with an email — a phone call, a visit, a task. Emails can be added to it later.</p>
    <form id="newCaseForm" class="new-case-form" autocomplete="off">
      <label>Name <input type="text" id="ncName" maxlength="120" required placeholder="e.g. Call from Maria about the delivery"></label>
      <?php if ($bootstrap['templates']): ?>
      <label>Start from a template <select id="ncTemplate" class="ot-select"><option value="">No template</option>
        <?php foreach ($bootstrap['templates'] as $t): ?><option value="<?= (int)$t['id'] ?>" title="<?= $h($t['description'] ?? '') ?>"><?= $h($t['name']) ?> (<?= (int)$t['steps'] ?> steps)</option><?php endforeach; ?></select></label>
      <?php endif; ?>
      <div class="new-case-two">
        <div class="nc-field"><span class="nc-label" id="ncOwnerLabel">Responsible</span>
          <input type="hidden" id="ncOwner" value="<?= (int)$userId ?>">
          <button type="button" class="person-pick" data-pick="ncOwner" data-title="Responsible" aria-labelledby="ncOwnerLabel"></button></div>
        <?php if ($bootstrap['handler']): ?>
        <div class="nc-field"><span class="nc-label" id="ncHandlerLabel">Handled by</span>
          <input type="hidden" id="ncHandler" value="">
          <button type="button" class="person-pick" data-pick="ncHandler" data-title="Handled by" aria-labelledby="ncHandlerLabel"></button></div>
        <?php endif; ?>
      </div>
      <div class="new-case-two">
        <?php if ($groups): ?>
        <label>Group <select id="ncGroup" class="ot-select"><option value="">No group</option>
          <?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= $opts['group_id'] === (int)$g['id'] ? 'selected' : '' ?>><?= $h($g['name']) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <?php if ($bootstrap['due']): ?><label>Due <input type="date" id="ncDue"></label><?php endif; ?>
      </div>
      <footer>
        <button type="button" id="ncCancel">Cancel</button>
        <button type="submit" class="primary" id="ncCreate">Create case</button>
      </footer>
    </form>
  </div>
</div>

<div class="toast" id="toast" hidden>
  <span class="toast-msg" id="toastMsg"></span>
  <button type="button" class="toast-action" id="toastAction" hidden></button>
</div>

<script type="application/json" id="caseListBootstrap"><?= json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php if (ecw_feature_allowed('next_actions') && ecw_feature_allowed('ai')): ?><script src="assets/case-review.js?v=<?= filemtime(__DIR__ . '/assets/case-review.js') ?>"></script><?php endif; ?>
<script src="assets/caseList.js?v=<?= filemtime(__DIR__ . '/assets/caseList.js') ?>"></script>
<script src="assets/saved-filters.js?v=<?= filemtime(__DIR__ . '/assets/saved-filters.js') ?>"></script>
</body>
</html>
