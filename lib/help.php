<?php declare(strict_types=1);
/**
 * In-app help: short how-tos, shown in the help panel ("?" in the top bar, or
 * the ? key) for the page someone is on, searchable, and all together on
 * web/help.php.
 *
 * Each article: title, topic, the pages it belongs to (script names, e.g.
 * "app", "cases", "settings/mailbox"), the feature it needs (hidden when the
 * person does not have it), admin-only or not, search words, and the text.
 * The text is written here, by us — trusted HTML, never user input.
 * A page opens one article directly with a data-help attribute naming its slug.
 */

require_once __DIR__ . '/features.php';
require_once __DIR__ . '/session.php';   // ecw_web_base(), ecw_feature_allowed()

const ECW_HELP_TOPICS = [
    'start'    => ['Getting started', 'fa-flag-checkered'],
    'mail'     => ['Mail', 'fa-envelope'],
    'cases'    => ['Cases', 'fa-folder-open'],
    'ai'       => ['AI assistant and suggestions', 'ecw-ai-icon'],
    'team'     => ['Working as a team', 'fa-users'],
    'admin'    => ['For admins', 'fa-shield-halved'],
];

const ECW_HELP = [
    // ------------------------------------------------------------- getting started
    'what-is' => ['start', 'What Case Workspace is', ['app', 'cases', 'get-started'], null, false, 'about overview case workspace',
        '<p>Your Outlook or Gmail, with <strong>cases</strong> on top. A case groups everything about one matter — a customer\'s complaint, a project, a claim — even when it spans many conversations and many people.</p>
         <ul><li><strong>Mail</strong> works like your mail client: read, reply, search. Nothing in your mailbox changes until you act.</li>
         <li><strong>Cases</strong> hold the conversations, internal notes, a checklist, reminders and the history of who did what.</li>
         <li>Your colleagues see the cases, never your private mailbox — unless a conversation is in a case they can see.</li></ul>'],
    'get-started' => ['start', 'Setting up on the first day', ['get-started', 'settings/index'], null, false, 'setup first day wizard checklist',
        '<p><strong>Settings → Get started</strong> lists what to do, and ticks each step as it is done: connect your mailbox, your signature, a safe sign-in. Admins also set the company name and time zone, invite the team, add ready-made case templates and make the backups safe.</p>
         <p>Hide the list with <em>I\'m done</em>; it can be shown again from the same page.</p>'],
    'shortcuts' => ['start', 'Keyboard shortcuts', ['app'], null, false, 'keyboard keys shortcut',
        '<ul><li><kbd>?</kbd> opens this help.</li><li><kbd>Esc</kbd> closes a window, a menu or this panel.</li>
         <li><kbd>Ctrl</kbd>+<kbd>F</kbd> in a conversation or a case finds words in its emails.</li><li><kbd>Alt</kbd>+<kbd>↑</kbd>/<kbd>↓</kbd> moves a folder in the folder list.</li></ul>'],

    // ------------------------------------------------------------- mail
    'search' => ['mail', 'Searching your mail', ['app'], null, false, 'search find filter from to subject attachment date',
        '<p>Type words to find emails that contain them — in the text, the subject, the people, and inside attached PDFs and Office files. Put several words in "quotes" for an exact phrase.</p>
         <p>Narrow it down with these (combine as many as you like):</p>
         <table class="help-table"><tr><td><code>from:maria</code></td><td>sent by someone (name or address)</td></tr>
         <tr><td><code>to:</code> <code>cc:</code></td><td>sent to someone</td></tr>
         <tr><td><code>subject:offer</code></td><td>in the subject</td></tr>
         <tr><td><code>has:attachment</code></td><td>with files</td></tr>
         <tr><td><code>filename:invoice</code></td><td>a file with that name</td></tr>
         <tr><td><code>is:unread</code> <code>is:flagged</code></td><td>unread / flagged</td></tr>
         <tr><td><code>is:linked</code> <code>is:unlinked</code></td><td>in a case / not in a case</td></tr>
         <tr><td><code>case:CASE-00012</code></td><td>in that case</td></tr>
         <tr><td><code>after:2026-09-01</code> <code>before:2026-10-01</code></td><td>between dates</td></tr></table>
         <p>The filter button next to the search box builds these for you. Opening a result jumps to the email that matches, highlights the words, and marks the attachments that contain them.</p>'],
    'folders' => ['mail', 'Folders, and which ones are synced', ['app', 'settings/mailbox'], null, false, 'folders sync junk deleted register outlook',
        '<p>Every folder you have in Outlook or Gmail is brought in, including your own folders (where your Outlook rules move mail), Archive, Junk and Deleted Items. Outlook\'s bookkeeping folders (Sync Issues, Conflicts, Outbox) are left out.</p>
         <p>To leave a folder out: <strong>Settings → Mailbox → Folders to sync</strong>. Drag folders in the folder list to put them in your own order.</p>'],
    'link-case' => ['mail', 'Putting an email into a case', ['app'], null, false, 'link add to case conversation create',
        '<p>Open the conversation and press <strong>Add to case</strong>. The app suggests cases with the same people; or search for one; or name a new case (and pick a template) and press <em>Create &amp; link</em>.</p>
         <p>The whole conversation goes into the case, including replies that come later. Everyone who can see the case can then read it.</p>
         <p><strong>By itself:</strong> an email that mentions a case number (CASE-00012) in its subject or text, or one of a case’s reference numbers (an RMA, an order, a serial number), joins that case — only when it points to exactly one case. The case’s History says why.</p>'],
    'snooze' => ['mail', 'Snooze and reminders', ['app', 'case'], null, false, 'snooze remind later follow up',
        '<p><strong>Snooze</strong> hides a conversation until a time you choose; it comes back by itself (or earlier when a reply arrives). It also moves in Outlook, unless switched off in Settings → Mailbox.</p>
         <p>A <strong>reminder</strong> (follow-up) tells you at a time you choose — and with "only if nobody has replied", it stays quiet when the customer answers first.</p>'],
    'sending' => ['mail', 'Sending: undo, send later, drafts', ['app'], null, false, 'send undo later schedule draft delete',
        '<p>After <em>Send</em> you have a few seconds to <strong>Undo</strong>. <em>Send later</em> picks a time. Drafts are saved as you type and also appear in Outlook\'s Drafts; Outlook drafts appear under Drafts here too.</p>
         <p>Deleting a draft here deletes it in Outlook as well.</p>'],
    'shared-mailbox' => ['mail', 'Shared mailboxes (info@, sales@)', ['app', 'settings/mailbox'], null, false, 'shared mailbox info sales claim assign team inbox',
        '<p>A shared mailbox is one the team works from together. It shows with 👥 in the mailbox list.</p>
         <ul><li><strong>Claim</strong> a conversation ("Unassigned — claim it") so colleagues see you are on it; or give it to someone. Replying claims it for you.</li>
         <li>Use <strong>Mine / Unassigned / Others</strong> above the list to see what is yours and what nobody has taken.</li></ul>
         <p>Admins connect a Microsoft 365 shared mailbox in Settings → Mailbox (their account needs Full Access in Exchange), or mark any mailbox as shared there.</p>'],

    // ------------------------------------------------------------- cases
    'cases-list' => ['cases', 'The case list: views, many at once', ['cases'], null, false, 'cases list views my overdue unassigned bulk select close assign',
        '<p>The chips at the top are quick views: <strong>All, Open, My cases</strong> (you are Responsible or Handled by), <strong>Unassigned, Overdue, Due this week, Waiting for the customer</strong>.</p>
         <p>The <strong>⋮</strong> menu on a row closes or reopens the case, changes the people, the due date or the group — without opening it. Tick several cases to do the same to all of them at once.</p>'],
    'new-case' => ['cases', 'Starting a case', ['cases', 'app'], null, false, 'new case create start template',
        '<p>From an email: <em>Add to case</em> and name a new one. Without an email (a phone call, a visit, a task): <strong>Cases → New case</strong>. Choose who is Responsible and who handles it, a group and a due date — and, if your admin made some, a <strong>template</strong> that brings its steps with it.</p>'],
    'next-step' => ['cases', 'Next step, waiting on, look again', ['case', 'cases'], 'next_actions', false, 'next step action waiting on supplier manufacturer customer look again review reference serial order ticket rma claim',
        '<p>Under the case name, one line says <strong>what happens next</strong> — click it to change it: <strong>who</strong> does it, who the case is <strong>waiting on</strong> (us, the customer, the supplier, a colleague) and the day to <strong>look again</strong> — that person is reminded on the day.</p>
         <ul><li>It keeps itself up to date: when you email the supplier’s conversation the case waits on the supplier; when they answer it is our turn again. Mark each conversation as Customer, Supplier or Internal in its header.</li>
         <li>With the AI assistant it is filled in from the emails by itself, references too (order, RMA, serial, claim numbers). It shows <em>AI</em> and the email it read; <em>Undo</em> puts it back. Your own change stays until a newer email arrives.</li>
         <li>In the case list: <em>Look at today</em>, <em>Waiting on supplier</em> and <em>No next step</em> (cases nobody is driving). Typing a reference number in the search finds its case.</li></ul>'],
    'people-on-case' => ['cases', 'Responsible and Handled by', ['case', 'cases'], null, false, 'responsible handled by owner assign',
        '<p><strong>Responsible</strong> answers for the case (the customer\'s contact person). <strong>Handled by</strong> does the work, when that is someone else. Both are told when they are given a case, and see it under <em>My cases</em>.</p>'],
    'checklist' => ['cases', 'Checklists', ['case'], null, false, 'checklist steps tasks todo',
        '<p>The <strong>Checklist</strong> tab of a case holds its steps. Give a step a person and a date: they are told, and reminded on the day. Tick it when done — the history shows who did it. Click a step\'s text to change it; drag to reorder.</p>
         <p>"3/5" next to a case in the list shows how far it is.</p>'],
    'templates' => ['cases', 'Case templates', ['case', 'cases', 'settings/case-templates'], 'case_templates', false, 'template ready made complaint warranty steps',
        '<p>For work that repeats. A template brings its steps (each with "+N days" and who does it), tags, a due date and a group. Pick one when starting a case, or apply it in the case\'s Checklist tab — steps already there are not added twice.</p>
         <p>Admins make and change them in <strong>Settings → Case templates</strong>, starting from the examples if they like.</p>'],
    'notes' => ['cases', 'Internal notes and @mentions', ['case', 'app'], null, false, 'notes comments mention internal',
        '<p>Notes are internal — customers never see them. Write one on a single email (the 💬 on it) or on the whole case. Type <strong>@</strong> and a name to bring someone in: they are told, and added to the case if they could not see it.</p>'],
    'due-sla' => ['cases', 'Due dates and reply times', ['case', 'cases', 'settings/sla'], null, false, 'due date overdue sla reply time target',
        '<p>A case\'s <strong>due date</strong> reminds Responsible and Handled by on the day, and the case shows as overdue after it.</p>
         <p>The <strong>reply clock</strong> (when your admin set reply targets) counts business hours since the customer wrote: amber when time is nearly up, red when the target was missed.</p>'],
    'sharing' => ['cases', 'Who can see a case', ['case'], null, false, 'sharing visibility private members group',
        '<p>By default everyone in the workspace sees a case. Under <strong>Shared with</strong> it can be limited to chosen people and a group. Everyone who can see the case can read its conversations — even those from someone else\'s mailbox.</p>'],
    'legal-hold' => ['cases', 'Legal hold', ['case', 'settings/security'], null, true, 'legal hold dispute claim keep delete',
        '<p>For a dispute or a claim: the scales button on a case puts it on hold. It cannot be deleted, its emails cannot be taken out of it or deleted for good, and retention never touches it — even emails deleted in Outlook stay here. Admins only.</p>'],

    // ------------------------------------------------------------- AI
    'ask-ai' => ['ai', 'Asking the AI', ['app', 'case'], 'ai', false, 'ai assistant ask question summarize',
        '<p><strong>Ask AI</strong> answers questions about your mail and cases — "what did we agree with Maria about delivery?", "find the invoice from Kostas", "summarize this case" — and shows the emails and files it used, so you can check.</p>
         <p>It only sees what you can see. Check important facts before you rely on them.</p>'],
    'suggestions' => ['ai', 'Suggestions from the AI agents', ['app', 'case', 'settings/ai'], 'ai', false, 'suggestions agents cards draft reply triage',
        '<p>The agents read new mail in the background and leave <strong>suggestions</strong>: under Mail → Suggestions, above an email, and in a case\'s Summary tab. Each card has one-click actions — start a case, save the facts, set a reminder, <strong>draft a reply</strong>. Press one, or ✕ to dismiss. Nothing happens without your click.</p>
         <p>They never read Junk or Deleted Items. Switch them off for yourself in Settings → My view.</p>'],
    'draft-reply' => ['ai', 'Drafted replies', ['app'], 'ai', false, 'draft reply ai write placeholder',
        '<p>"Draft a reply" writes a reply in the customer\'s language and saves it as a draft — it is never sent by itself. Words in [brackets] are what only you know (a price, a date): fill them in, change what you like, send.</p>'],

    // ------------------------------------------------------------- team
    'holiday' => ['team', 'Going on holiday', ['account', 'settings/users', 'settings/handover'], null, false, 'holiday away vacation cover leave',
        '<p><strong>Account → Holiday cover</strong>: the first and last day and a colleague. While you are away they see your cases and get a copy of your alerts and reminders. Nothing is moved; it ends by itself. (Your Outlook out-of-office reply is under Settings → Mailbox.)</p>'],
    'handover' => ['team', 'When someone leaves', ['settings/users', 'settings/handover'], null, true, 'leave handover transfer disable employee',
        '<p><strong>Settings → Users → Cover / hand over</strong>: their open cases, reminders and claimed conversations go to a colleague in one step, and the colleague can read their mailbox so nothing is lost. Switching a person off offers this by itself.</p>'],
    'roles' => ['team', 'Roles: admin, user, read only', ['settings/users'], null, true, 'roles admin user read only permissions',
        '<p><strong>Admins</strong> manage users, settings and security. <strong>Users</strong> do the daily work. <strong>Read only</strong> can look at everything they may see, but change nothing — for a manager, an auditor or a trainee.</p>'],

    // ------------------------------------------------------------- admin
    'backups' => ['admin', 'Backups that are really safe', ['settings/backups', 'settings/security'], null, true, 'backup restore offsite nas onedrive s3 password',
        '<p>A backup is made every day. To survive a broken disk or a stolen computer, it also needs to be <strong>somewhere else</strong> — Settings → Backups → Off-site copy: a NAS or OneDrive folder, S3 storage or WebDAV — and <strong>encrypted</strong> with a backup password (Settings → Security). Keep that password safe: without it a backup cannot be restored.</p>
         <p>Restoring is done on the server: <code>.\scripts\restore.ps1 &lt;backup file&gt;</code>.</p>'],
    'health' => ['admin', 'System health and alerts', ['settings/health'], null, true, 'health alerts monitoring sync worker broken',
        '<p>Settings → System health shows whether everything that runs by itself is running: mail sync, the background worker, every mailbox, backups, disk space, license. When something stays broken for 10 minutes, admins are told — in the app and by email — and again when it is fixed.</p>
         <p><strong>Problems</strong> lists errors on the server and in people’s browsers, grouped by kind, with the details to send your vendor. Admins are told the first time each kind appears; <em>Mark as fixed</em> clears one, and it comes back if it happens again.</p>'],
    'updates' => ['admin', 'Updating to a new version', ['settings/health'], null, true, 'update upgrade version rollback',
        '<p>On the server, in the app folder: <code>.\scripts\update.ps1</code>. It checks that the release is signed by the vendor, makes a backup, installs, and checks that the app works — and puts the previous version back by itself if it does not. <code>.\scripts\rollback.ps1</code> goes back by hand.</p>'],
    'security' => ['admin', 'Security: Microsoft sign-in, retention', ['settings/security', 'account'], null, true, 'security sso microsoft sign in retention two factor',
        '<p><strong>Settings → Security</strong>: let people sign in with their Microsoft account (and make it required), decide how long deleted cases, old email copies and the audit log are kept, and protect the backups with a password.</p>
         <p>Everyone can turn on <strong>two-factor sign-in</strong> under Account.</p>'],
    'export' => ['admin', 'Getting everything out (export)', ['case', 'settings/backups'], null, false, 'export download zip eml pdf leave move data portability',
        '<p><strong>One case:</strong> the zip button on the case gives every email as a file Outlook opens (with its attachments), the case on one page to open in a browser, and the notes, checklist and history as spreadsheets.</p>
         <p><strong>Everything</strong> (admins): Settings → Backups → Export everything. It is made in the background; you are told when it is ready, and it is kept for 7 days. A backup is for restoring this app; an export is for people and other programs.</p>'],
    'audit' => ['admin', 'The audit log', ['settings/audit'], null, true, 'audit log who did what compliance',
        '<p>Settings → Audit log records who did what — sign-ins, changes to users and settings, deleted cases, exports, downloads, AI questions. Filter by person, kind of action and date, and export it.</p>'],
];

/**
 * The short answer shown first (people read one line, not a page), and where
 * "Show me" goes: [page, element to point at or null, what to say there].
 * Without an element the page is just opened.
 */
const ECW_HELP_ANSWERS = [
    'what-is'        => ['Your mailbox, with cases on top: everything about one matter in one place.', null],
    'get-started'    => ['Settings → Get started ticks off the setup steps one by one.', ['get-started.php', null, null]],
    'shortcuts'      => ['? opens help, Esc closes things, Ctrl+F finds words in a conversation.', null],
    'search'         => ['Type words, or narrow it down: from:maria, has:attachment, is:unread…', ['app.php', '#searchInput', 'Type here. Try from:a-name or has:attachment.']],
    'folders'        => ['All your Outlook folders come in; leave some out in Settings → Mailbox.', ['app.php', '#navPane', 'Your folders. Drag one to move it; the number is unread mail.']],
    'link-case'      => ['Open the conversation, press Add to case, pick or name the case.', ['app.php', '#threadList', 'Open a conversation, then press “Add to case” above it.']],
    'snooze'         => ['Later → hide a conversation until a time, or get reminded if nobody replies.', ['app.php', '#threadList', 'Open a conversation and press “Later” above it.']],
    'sending'        => ['You can undo a send for a few seconds, or pick a time to send later.', ['app.php', '#composeBtn', 'Write a new email here. Drafts save as you type.']],
    'shared-mailbox' => ['Claim a conversation so the team sees you are on it; replying claims it too.', ['app.php', '#mailboxSelect', 'Switch to the shared mailbox here.']],
    'cases-list'     => ['One click for My cases, Unassigned or Overdue; tick rows to change many at once.', ['cases.php', '#caseViews', 'Quick views: one click each.']],
    'new-case'       => ['Cases → New case, or Add to case on an email.', ['cases.php', '#newCaseBtn', 'Start a case here — for a call, a visit or a task.']],
    'next-step'      => ['Every case says what happens next, who does it, who it waits on and when to look again.', ['cases.php', '#caseViews', 'Look at today, Waiting on supplier, No next step: one click each.']],
    'people-on-case' => ['Responsible answers for the case; Handled by does the work.', ['cases.php', '#caseTable', 'Change them from a row’s ⋮ menu, without opening the case.']],
    'checklist'      => ['A case’s Checklist tab: steps with a person and a date, reminded on the day.', null],
    'templates'      => ['Ready-made cases with their steps, picked when a case starts.', ['settings/case-templates.php', null, null]],
    'notes'          => ['Internal notes customers never see; type @ and a name to bring someone in.', null],
    'due-sla'        => ['A due date reminds on the day; the reply clock counts business hours.', null],
    'sharing'        => ['Everyone sees a case, unless you limit it under Shared with.', null],
    'legal-hold'     => ['A case on hold cannot be deleted, and its emails stay even if deleted in Outlook.', null],
    'ask-ai'         => ['Ask questions about your mail and cases; it shows the emails it used.', ['app.php', '#mailAssistantBtn', 'Ask the AI about your mail here.']],
    'suggestions'    => ['The agents suggest next steps on new mail; nothing happens until you click.', ['app.php', '#suggestionsBtn', 'Suggestions from the agents collect here.']],
    'draft-reply'    => ['Draft a reply writes one in the customer’s language; fill in the [brackets], send.', ['app.php', '#suggestionsBtn', 'Open a suggestion and press “Draft a reply”.']],
    'holiday'        => ['Account → Holiday cover: dates and a colleague who sees your cases meanwhile.', ['account.php', '#away', 'Your days away and who covers.']],
    'handover'       => ['Settings → Users → Cover / hand over moves someone’s work in one step.', ['settings/users.php', null, null]],
    'roles'          => ['Admins manage, users work, read-only people look but change nothing.', ['settings/users.php', null, null]],
    'backups'        => ['Daily backups — make them safe with an off-site copy and a password.', ['settings/backups.php', null, null]],
    'health'         => ['One page shows if everything is running; admins are told when it is not.', ['settings/health.php', null, null]],
    'updates'        => ['Run scripts\update.ps1 on the server; it backs up and rolls back by itself.', ['settings/health.php', null, null]],
    'security'       => ['Microsoft sign-in, how long things are kept, backup password.', ['settings/security.php', null, null]],
    'export'         => ['One case or everything as a zip that opens without this app.', ['settings/backups.php', '#export', 'Export everything: made in the background, kept 7 days.']],
    'audit'          => ['Who did what, filterable and exportable.', ['settings/audit.php', null, null]],
];

/**
 * Guided tours: the real page, one thing at a time (assets/help.js). A step
 * whose element is not on the screen right now is skipped; a step tied to a
 * feature is left out for people who do not have it.
 * page key => [title, page (null: only on that page), [[selector, title, text, feature|null], …]]
 */
const ECW_HELP_TOURS = [
    'app' => ['Mail in 30 seconds', 'app.php', [
        ['#navPane', 'Your folders', 'Everything from Outlook, your own folders too. Drag to reorder; the number is unread mail.', null],
        ['#searchInput', 'Search everything', 'Words, people, even text inside PDFs. Narrow it down with from:, has:attachment, is:unread.', null],
        ['#threadList', 'Conversations', 'Newest first. Right-click one (or press and hold) for quick actions.', null],
        ['#addToCaseBtn', 'Put it in a case', 'The whole conversation goes into the case, replies that come later too.', null],
        ['#suggestionsBtn', 'Suggestions', 'The AI reads new mail and suggests the next step: start a case, a reminder, a drafted reply.', 'ai'],
        ['#composeBtn', 'Write', 'A new email. Drafts save as you type, and a sent email can be undone for a few seconds.', null],
        ['#helpBtn', 'Help is always here', 'Press ? on any page for short answers, and to take this tour again.', null],
    ]],
    'cases' => ['Cases in 30 seconds', 'cases.php', [
        ['#newCaseBtn', 'Start a case', 'For a call, a visit or a task — no email needed. Pick a template and its steps come along.', null],
        ['#caseViews', 'Quick views', 'My cases, Look at today, Waiting on supplier, No next step, Overdue: one click each.', null],
        ['#caseFilterBar', 'Find a case', 'By name, number, customer or a person on it. Filters are next to the search.', null],
        ['#caseTable', 'Many at once', 'Tick cases to close, assign or move them together. ⋮ on a row changes one without opening it.', null],
        ['#helpBtn', 'Help is always here', 'Press ? on any page for short answers.', null],
    ]],
    'case' => ['A case in 30 seconds', null, [   // only from inside a case
        ['#caseNext', 'What happens next', 'One line: the next step, who does it, who the case waits on and when to look again. Click it to change it; emails keep it up to date.', 'next_actions'],
        ['#caseStatus', 'Status', 'Open, Waiting for the customer, or Closed.', null],
        ['#caseOwner', 'Who is on it', 'Responsible answers for the case; Handled by does the work. Both are told.', null],
        ['#timeline', 'Everything in one place', 'Every email of every conversation in the case, in order.', null],
        ['#addThreadBtn', 'Add a conversation', 'Bring in another email thread, or start a new one from here.', null],
        ['[data-tab="checklist"]', 'Steps', 'The checklist: each step with a person and a date, reminded on the day.', 'checklists'],
        ['[data-tab="notes"]', 'Internal notes', 'Customers never see them. Type @ and a name to bring a colleague in.', null],
        ['#caseAssistantBtn', 'Ask about this case', 'The AI summarizes the case or answers questions about it.', 'ai'],
    ]],
];

/** The articles this person may see (feature they have, admin-only ones for admins). */
function ecw_help_articles(array $context): array
{
    $isAdmin = ($context['role'] ?? '') === 'admin';
    $base = function_exists('ecw_web_base') ? ecw_web_base() : '/';
    $out = [];
    foreach (ECW_HELP as $slug => [$topic, $title, $pages, $feature, $adminOnly, $words, $body]) {
        if ($adminOnly && !$isAdmin) continue;
        if ($feature !== null && !ecw_feature_allowed($feature, $context)) continue;
        [$summary, $show] = ECW_HELP_ANSWERS[$slug] ?? ['', null];
        $out[] = ['slug' => $slug, 'topic' => $topic, 'title' => $title, 'pages' => $pages, 'words' => $words, 'summary' => $summary,
            'show' => $show === null ? null : ['href' => $base . $show[0], 'page' => ecw_help_page_key('/' . $show[0]), 'target' => $show[1], 'say' => $show[2]],
            'body' => trim((string)preg_replace('/\s+/', ' ', $body))];
    }
    return $out;
}

/** The tours this person can take, with the steps of features they do not have left out. */
function ecw_help_tours(array $context): array
{
    $base = function_exists('ecw_web_base') ? ecw_web_base() : '/';
    $out = [];
    foreach (ECW_HELP_TOURS as $key => [$title, $page, $steps]) {
        $keep = [];
        foreach ($steps as [$sel, $stepTitle, $text, $feature]) {
            if ($feature !== null && !ecw_feature_allowed($feature, $context)) continue;
            $keep[] = ['target' => $sel, 'title' => $stepTitle, 'text' => $text];
        }
        if ($keep) $out[$key] = ['key' => $key, 'title' => $title, 'href' => $page === null ? null : $base . $page, 'steps' => $keep];
    }
    return $out;
}

/** "settings/mailbox", "case", "app"… for the page a script serves. */
function ecw_help_page_key(string $scriptName): string
{
    $file = basename($scriptName, '.php');
    return basename(dirname($scriptName)) === 'settings' ? 'settings/' . $file : $file;
}
