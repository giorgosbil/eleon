<?php declare(strict_types=1);
/**
 * Every settings page, sorted by what it changes: For me, Cases, Team, Company, System.
 * One list for the settings sidebar (partials/settings-nav.php), its search, and the
 * overview (settings/index.php). Each person gets only the pages they may open.
 *
 * Where a new setting goes: how it looks → Appearance; how I work with emails → Email &
 * writing; when and how the app tells me something → Notifications; something that
 * affects everyone → Team or Company.
 *
 * An item: href (from the web root, may carry a ?query), icon, label, desc (shown in the
 * overview and in search results), find (more words the search knows it by), and
 * optionally also (other pages it is the sidebar entry for) and search_only (found by
 * the search, not listed: a page reached from inside another one).
 */
require_once __DIR__ . '/getting_started.php';
require_once __DIR__ . '/modules.php';

function ecw_settings_menu(?array $ctx): array
{
    $isAdmin = ($ctx['role'] ?? '') === 'admin';
    $can = static fn(string $f): bool => $ctx !== null && ecw_feature_allowed($f, $ctx);
    $item = static fn(string $href, string $icon, string $label, string $desc, string $find = '', array $more = []): array
        => ['href' => $href, 'icon' => $icon, 'label' => $label, 'desc' => $desc, 'find' => $find] + $more;

    $me = [
        // The first-day checklist, until its person hides it (lib/getting_started.php).
        ($ctx !== null && !ecw_getting_started_hidden((int)$ctx['user']['id']))
            ? $item('get-started.php', 'fa-flag-checkered', 'Get started', 'The first steps, and how to install the app on your computer', 'checklist welcome install app desktop shortcut') : null,
        $item('account.php', 'fa-user', 'Account', 'Your name, password, language, two-factor sign-in and holiday cover',
            'display name password language greek english ελληνικά two-factor 2fa code authenticator microsoft sign-in trusted computers holiday cover away vacation'),
        $item('settings/mailbox.php', 'fa-envelope', 'Mailbox', 'Connect a Microsoft 365 (Outlook) or Gmail mailbox, and how far the import is',
            'outlook gmail google microsoft 365 office connect sync synchronization import account folders reconnect'),
        $item('settings/appearance.php', 'fa-palette', 'Appearance', 'How it looks: theme, background, colours, text size, spacing, the email list',
            'theme dark light mild mist sand graphite colour color background doodles dots lines accent text size spacing compact comfortable density message corners square rounded row preview first words thinner rows colour rules style'),
        $item('settings/email.php', 'fa-pen-to-square', 'Email & writing', 'Where replies open, the order of a conversation, the font you write in, your signatures',
            'reply window in the email inline compose new email font size colour writing conversation order newest first oldest first thread order signature',
            ['also' => ['settings/signatures.php']]),
        $item('settings/signatures.php', 'fa-pen', 'Signatures', 'Save your signatures and choose the default one', 'signature default picture logo', ['search_only' => true]),
        $item('settings/notifications.php', 'fa-bell', 'Notifications', 'Alerts for new email and mentions, a test alert, the bubble on the taskbar icon',
            'alerts bell desktop notification pop-up popup bottom right corner push test taskbar badge bubble dot number unread icon windows'),
        $item('settings/view.php', 'fa-sliders', 'My view', 'How much of the app you see, and the AI suggestions',
            'simple standard everything custom features hide show menu ai suggestions ideas'),
        $can('rules') ? $item('settings/rules.php?part=colors', 'fa-wand-sparkles', 'My rules', 'Colour your emails in the list, e.g. my name in To in blue',
            'colour color rules conditional formatting highlight mark') : null,
        $can('ai') ? $item('settings/copilot.php', 'fa-link', 'Connected apps', 'Use the workspace from Copilot, ChatGPT, Claude and other AI apps',
            'copilot chatgpt claude ai apps mcp connector token')
            : ($can('teams') ? $item('teams.php', 'ecw-teams-icon', 'Connected apps', 'Chat in Teams from the workspace', 'teams chat microsoft') : null),
        ($can('ai') && $can('teams')) ? $item('teams.php', 'ecw-teams-icon', 'Teams chat', 'Chat in Teams from the workspace', 'teams chat microsoft', ['search_only' => true]) : null,
    ];
    $cases = [
        $isAdmin ? $item('settings/cases.php', 'fa-folder-tree', 'Case settings', 'Statuses, overdue timers, conversation roles, case numbers and tags',
            'status statuses waiting closed open custom overdue timer due date days grace response time roles customer supplier internal whose conversation case number format prefix year tags rename merge colour colors') : null,
        $can('case_groups') ? $item('settings/case-groups.php', 'fa-layer-group', 'Case groups', 'Support, Sales… Group cases, for you, chosen people or the whole company',
            'groups support sales members personal company who sees cases') : null,
        $can('templates') ? $item('settings/templates.php', 'fa-file-lines', 'Reply templates', 'Ready-made answers, your own or shared with the team',
            'templates answers canned quick text shared') : null,
        ($isAdmin && $can('case_templates')) ? $item('settings/case-templates.php', 'fa-list-check', 'Case templates', 'Ready-made checklists and steps for new cases',
            'checklist steps tasks template') : null,
    ];
    $team = !$isAdmin ? [] : [
        $item('settings/users.php', 'fa-users', 'Users', 'Add people, invitations, roles, read only', 'people members invite invitation role admin read only password reset remove'),
        $item('settings/features.php', 'fa-toggle-on', 'Feature access', 'Which features normal members can see, and the workspace default view',
            'features access members permissions hide teams reports ai templates default view'),
        $can('sla') ? $item('settings/sla.php', 'fa-stopwatch', 'Response times', 'How fast a new email or case should get an answer', 'sla response time target overdue hours') : null,
        $can('automation') ? $item('settings/rules.php?part=automation', 'fa-diagram-project', 'Case rules', 'New email that matches: open a case, set the Responsible, tag, move, notify, reply',
            'automation routing route rules intake support address auto reply webhook') : null,
    ];
    $company = !$isAdmin ? [] : [
        $item('settings/branding.php', 'fa-image', 'Branding', 'Your business logo and name in the top bar', 'logo name brand colours'),
        $item('settings/license.php', 'fa-certificate', ECW_COMMUNITY_EDITION ? 'Community' : 'License', ECW_COMMUNITY_EDITION ? 'Open-source software, all installed features and optional services' : 'Your package, users and mailboxes, the license key', 'community open source license users mailboxes support'),
        $can('ai') ? $item('settings/ai.php', 'ecw-ai-icon', 'AI', 'Which AI is used and for what, the agents, and what it used', 'ai model local openai claude agents usage cost') : null,
        $item('settings/integrations.php', 'fa-plug', 'Integrations', 'Send case events to your CRM, ERP or Zapier', 'crm erp zapier webhook api integration'),
        $can('teams') ? $item('settings/teams.php', 'fa-bullhorn', 'Teams notifications', 'Post case events to a Teams channel', 'teams channel post notifications') : null,
    ];
    $system = !$isAdmin ? [] : [
        $item('settings/health.php', 'fa-heart-pulse', 'System health', 'Is everything running: import, worker, disk', 'health status worker queue disk errors running'),
        $item('settings/security.php', 'fa-shield-halved', 'Security', 'Sign-in rules for everyone: two-factor, Microsoft sign-in', 'security two-factor 2fa sso microsoft sign-in password policy'),
        $item('settings/audit.php', 'fa-clipboard-list', 'Audit log', 'Who did what, and when', 'audit log history who changed'),
        $item('settings/backups.php', 'fa-floppy-disk', 'Backups', 'Backups of everything, and restoring one', 'backup restore copy export'),
    ];

    // Settings pages of optional modules (lib/modules.php): a personal one with the connected
    // apps, an admin one with the integrations.
    foreach (ecw_module_settings() as $m) {
        if (($m['admin'] && !$isAdmin) || ($m['feature'] !== null && !$can($m['feature']))) continue;
        $entry = $item(ecw_module_settings_url('', $m['module'], $m['page']), $m['icon'], $m['label'], $m['label'], $m['module'] . ' module');
        if ($m['admin']) $company[] = $entry; else $me[] = $entry;
    }

    return array_filter([
        'For me' => array_values(array_filter($me)),
        'Cases' => array_values(array_filter($cases)),
        'Team' => array_values(array_filter($team)),
        'Company' => array_values(array_filter($company)),
        'System' => array_values(array_filter($system)),
    ]);
}

/**
 * Marks the item of the page being shown (key 'active'): the same file, and the same
 * ?query when the item has one. A page reached without that query (an old link to
 * rules.php) belongs to its first item.
 */
function ecw_settings_menu_mark(array $menu, string $scriptName, array $get): array
{
    // settings/teams.php and teams.php are two pages: the folder counts too.
    $inSettings = basename(dirname($scriptName)) === 'settings';
    $here = static function (string $href) use ($scriptName, $inSettings): bool {
        $p = (string)parse_url($href, PHP_URL_PATH);
        return basename($p) === basename($scriptName) && (basename(dirname($p)) === 'settings') === $inSettings;
    };
    $queryMatches = static function (string $href) use ($get): bool {
        parse_str((string)parse_url($href, PHP_URL_QUERY), $q);
        foreach ($q as $k => $v) if ((string)($get[$k] ?? '') !== (string)$v) return false;
        return true;
    };
    $found = null; $fallback = null;
    foreach ($menu as $s => $items) foreach ($items as $i => $it) {
        if (!empty($it['search_only'])) continue;
        $hrefs = array_merge([$it['href']], $it['also'] ?? []);
        foreach ($hrefs as $href) {
            if (!$here($href)) continue;
            if ($found === null && $queryMatches($href)) $found = [$s, $i];
            if ($fallback === null) $fallback = [$s, $i];
        }
    }
    $at = $found ?? $fallback;
    if ($at !== null) $menu[$at[0]][$at[1]]['active'] = true;
    return $menu;
}
