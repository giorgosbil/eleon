<?php declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/calendar.php';
$context = ecw_require_login(); ecw_require_feature('calendar');
$mailbox = ecw_calendar_mailbox($context['organization_id'], (int)$context['user']['id']);
$navActive = 'calendar';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Calendar — <?= htmlspecialchars(ecw_product_brand()['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<link rel="stylesheet" href="assets/calendar.css?v=<?= filemtime(__DIR__ . '/assets/calendar.css') ?>">
</head>
<body class="calendar-body" data-connected="<?= $mailbox ? '1' : '0' ?>">
<?php require __DIR__ . '/partials/nav.php'; ?>
<header class="topbar calendar-toolbar">
  <button class="primary" id="calendarNew" type="button"><i class="fa fa-plus" aria-hidden="true"></i> New event</button>
  <button id="calendarToday" type="button">Today</button>
  <div class="calendar-arrows"><button id="calendarPrev" aria-label="Previous period" title="Previous period"><i class="fa fa-chevron-left" aria-hidden="true"></i></button><button id="calendarNext" aria-label="Next period" title="Next period"><i class="fa fa-chevron-right" aria-hidden="true"></i></button></div>
  <h1 id="calendarTitle"></h1>
  <div class="topbar-right">
    <input type="search" id="calendarSearch" placeholder="Filter this view" aria-label="Filter events in the current view">
    <select id="calendarView" class="ot-select" aria-label="Calendar view"><option value="month">Month</option><option value="week">Week</option><option value="day">Day</option><option value="agenda">Agenda</option></select>
    <button id="calendarRefresh" title="Refresh calendar" aria-label="Refresh calendar"><i class="fa fa-rotate-right" aria-hidden="true"></i></button>
  </div>
</header>
<div class="calendar-notice" id="calendarNotice" role="status" hidden></div>
<main class="calendar-layout">
  <aside class="calendar-sidebar">
    <div class="calendar-mini-head"><strong id="miniTitle"></strong><button id="miniPrev" class="calendar-icon" aria-label="Previous month"><i class="fa fa-chevron-left" aria-hidden="true"></i></button><button id="miniNext" class="calendar-icon" aria-label="Next month"><i class="fa fa-chevron-right" aria-hidden="true"></i></button></div>
    <div id="calendarMini" class="calendar-mini" aria-label="Choose a date"></div>
    <div class="calendar-account"><span class="calendar-dot"></span><div><strong>My Outlook calendar</strong><small><?= $h($mailbox['email_address'] ?? 'Connect your Microsoft account') ?></small></div></div>
    <p class="calendar-zone" id="calendarZone"></p>
    <div class="calendar-day-summary"><h2 id="selectedDayTitle"></h2><div id="selectedDayEvents"></div></div>
  </aside>
  <section class="calendar-content" aria-label="Calendar"><div id="calendarGrid"></div></section>
</main>
<dialog id="calendarDialog" class="dialog calendar-dialog" aria-labelledby="eventDialogTitle">
  <form id="calendarForm">
    <header class="calendar-dialog-head"><h2 id="eventDialogTitle">New event</h2><button type="button" data-close aria-label="Close event"><i class="fa fa-xmark" aria-hidden="true"></i></button></header>
    <p class="calendar-event-notice" id="eventReadOnly" hidden>Manage this meeting or recurring event in Outlook.</p>
    <label>Title<input name="title" type="text" maxlength="255" required placeholder="Add a title"></label>
    <label class="calendar-check"><input name="allDay" type="checkbox"> All day</label>
    <div class="calendar-form-row"><label>Starts<input name="start" type="datetime-local" required></label><label><span id="eventEndLabel">Ends</span><input name="end" type="datetime-local" required></label></div>
    <label>Location<input name="location" type="text" maxlength="255" placeholder="Add a location"></label>
    <label>Show as<select name="busy" class="ot-select"><option value="busy">Busy</option><option value="free">Free</option><option value="tentative">Tentative</option><option value="oof">Out of office</option><option value="workingElsewhere">Working elsewhere</option></select></label>
    <label>Notes<textarea name="notes" rows="4" maxlength="10000" placeholder="Add notes"></textarea></label>
    <p id="calendarFormError" class="calendar-form-error" role="alert" hidden></p>
    <div class="calendar-event-links"><a id="eventJoin" class="button" target="_blank" rel="noopener noreferrer" hidden>Join meeting</a><a id="eventOutlook" class="button" target="_blank" rel="noopener noreferrer" hidden>Open in Outlook</a></div>
    <footer><button type="button" id="eventDelete" class="danger" hidden>Delete</button><button type="button" data-close>Cancel</button><button type="submit" id="eventSave" class="primary">Save event</button></footer>
  </form>
</dialog>
<script src="assets/calendar.js?v=<?= filemtime(__DIR__ . '/assets/calendar.js') ?>" defer></script>
</body>
</html>
