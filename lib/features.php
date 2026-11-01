<?php declare(strict_types=1);
/**
 * Email Case Workspace — which optional features a person gets.
 *
 * Three layers, all must say yes:
 *   1. the package this installation has (lib/license.php)   — what was bought
 *   2. Feature access (Settings, per organization)            — what the admin lets members use
 *                                                              (admins always pass this layer)
 *   3. the person's view profile (Settings → My view)         — what they want to see
 *
 * Enforcement is server-side — pages, API actions and the navigation all ask
 * ecw_feature_allowed() — and the browser only mirrors it (window.ecwFeatures)
 * to hide buttons. Hiding a button is never the control.
 *
 * Mail, cases, notes, follow-ups and sharing are the core product and cannot
 * be switched off.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/license.php';
require_once __DIR__ . '/modules.php';

const ECW_FEATURES = [
    'calendar'    => ['Calendar', 'The Calendar tab with the person\'s Outlook calendar (view, create, move and edit events).'],
    'teams'       => ['Microsoft Teams', 'Teams tab, the Teams launcher and chat windows, "Teams" buttons, and Teams chats inside cases.'],
    'reports'     => ['Reports', 'The Reports tab with workload and response-time figures.'],
    'ai'          => ['AI assistant', 'Ask AI (it searches and reads your mail and cases, and opens emails and cases for you), Draft with AI, the AI case summary, translation, and the AI agents\' suggestions (Settings → AI).'],
    'ai_actions'  => ['AI assistant: actions and files', 'Ask AI can make Excel and CSV files, and prepare changes that happen only when you press Confirm: a new case, emails added to a case, a status, due date or Responsible, a note, a reminder, a reply for you to check and send. Needs the AI assistant.'],
    'templates'   => ['Reply templates', 'Inserting and managing reply templates.'],
    'rules'       => ['Mail colour rules', 'Personal colour rules in Settings → My rules.'],
    'case_groups' => ['Case groups', 'Creating, renaming and deleting case groups, and auto-assigning their cases (everyone can still pick a group on a case).'],
    'export'      => ['Case export', 'Export a whole case as a .zip: its emails (they open in Outlook), files, notes, checklist and history.'],
    'spelling'    => ['Spelling and word suggestions', 'While writing an email: Greek and English spelling mistakes underlined (click for the right word, or add it to your words), and the next word suggested from your own sent mail (Tab accepts).'],
    'case_extras' => ['Case tags, due dates and merge', 'Tags and due dates on cases (with reminders), and merging two cases into one.'],
    'auto_reply'  => ['Automatic replies', 'Setting out-of-office replies from the app.'],
    'sla'         => ['Response-time targets', 'Reply-by clocks on open cases (business hours) and the response-time report.'],
    'contacts'    => ['Contacts and companies', 'The Contacts tab: companies and people with all their cases and conversations, and the contact-card links in mail.'],
    'automation'  => ['Automation rules', 'Admins’ rules that act on new mail: open or file cases, assign, tag, move, notify, reply automatically.'],
    'shared_drafts' => ['Writing drafts together', 'Sharing a reply draft so colleagues can open and edit it, one at a time.'],
    'checklists'  => ['Case checklists', 'The Checklist tab of a case (steps with people and dates, reminded on the day) and the "3/5" progress in the case list.'],
    'next_actions' => ['Next steps and references', 'On every case: what happens next, who does it, who it waits on (customer, supplier, a colleague) and when to look again; its reference numbers (order, ticket, serial, claim); whose each conversation is. The case list shows it, with Waiting on supplier, Look at today and No next step. With the AI assistant it is filled in from the emails by itself.'],
    'deals'       => ['Deals: sales and purchasing', 'The Deals tab of a case and the Deal section in mail: RFQ, offers with their revisions, PO, order confirmation, delivery and invoice, for what you sell and what you buy, found in the emails and their files (smart matching, and the AI assistant when it is on), each with Go to email, and alerts (no offer yet, offer expired, PO not matching the latest offer, delivery late).'],
    'case_templates' => ['Case templates', 'Ready-made cases for work that repeats: checklist steps with dates and people, tags, due date and group (Settings → Case templates). Checklists themselves are part of every case.'],
];

/**
 * Every switchable feature: the core's (ECW_FEATURES) and those of the
 * installed modules (lib/modules.php). feature => [label, description].
 */
function ecw_feature_catalog(): array
{
    static $catalog = null;
    if ($catalog === null) {
        $catalog = ECW_FEATURES;
        foreach (ecw_module_features() as $key => [$label, $description]) $catalog[$key] ??= [$label, $description];
    }
    return $catalog;
}

/**
 * View profiles: what a person sees. null = everything they are allowed.
 * They only ever hide; they never unlock what the package or the admin does not allow.
 */
const ECW_PROFILES = [
    'simple'     => ['Simple', 'Mail, cases, notes and follow-ups. Nothing else on screen.', []],
    'standard'   => ['Standard', 'Simple, plus calendar, templates, tags and due dates, reply targets, reports, case export and spelling.', ['calendar', 'templates', 'case_extras', 'reports', 'export', 'auto_reply', 'sla', 'contacts', 'checklists', 'case_templates', 'next_actions', 'spelling']],
    'everything' => ['Everything', 'Every feature your package and your admin allow.', null],
    'custom'     => ['Custom', 'Pick each feature yourself.', null],
];

/** feature => visible to non-admin members (layer 2) */
function ecw_features(int $orgId, bool $fresh = false): array
{
    static $cache = [];
    if ($fresh || !isset($cache[$orgId])) {
        $st = ecw_db()->prepare('SELECT features_json FROM `' . ecw_table('organizations') . '` WHERE id = ?');
        $st->execute([$orgId]);
        $stored = json_decode((string)$st->fetchColumn(), true);
        $cache[$orgId] = [];
        foreach (array_keys(ecw_feature_catalog()) as $f) {
            $cache[$orgId][$f] = is_array($stored) && array_key_exists($f, $stored) ? (bool)$stored[$f] : true;
        }
    }
    return $cache[$orgId];
}

function ecw_features_save(int $orgId, array $enabled): array
{
    $out = [];
    foreach (array_keys(ecw_feature_catalog()) as $f) {
        $out[$f] = in_array($f, array_map('strval', $enabled), true);
    }
    ecw_db()->prepare('UPDATE `' . ecw_table('organizations') . '` SET features_json = ? WHERE id = ?')->execute([json_encode($out), $orgId]);
    ecw_features($orgId, true);   // this request sees the change too
    require_once __DIR__ . '/audit.php';
    ecw_audit('features.save', null, null, ['off' => array_keys(array_filter($out, static fn($on) => !$on))], $orgId);
    return $out;
}

// ------------------------------------------------------------ view profiles

/** The organization's default profile for people who never chose one. */
function ecw_org_default_profile(int $orgId): string
{
    try {
        $st = ecw_db()->prepare('SELECT default_view_profile FROM `' . ecw_table('organizations') . '` WHERE id = ?');
        $st->execute([$orgId]);
        $p = (string)$st->fetchColumn();
    } catch (Throwable $e) { $p = ''; }   // before migration 023
    return isset(ECW_PROFILES[$p]) && $p !== 'custom' ? $p : 'everything';
}

function ecw_org_default_profile_save(int $orgId, string $profile): void
{
    if (!isset(ECW_PROFILES[$profile]) || $profile === 'custom') throw new InvalidArgumentException('Unknown profile.');
    ecw_db()->prepare('UPDATE `' . ecw_table('organizations') . '` SET default_view_profile = ? WHERE id = ?')->execute([$profile, $orgId]);
}

/**
 * The person's profile: {profile, chosen (false = organization default), features (custom picks)}.
 * @return array{profile:string, chosen:bool, features:array<string,bool>}
 */
function ecw_user_view(int $userId, int $orgId, bool $fresh = false): array
{
    static $cache = [];
    if (!$fresh && isset($cache[$userId])) return $cache[$userId];
    $row = false;
    try {
        $st = ecw_db()->prepare('SELECT view_profile, view_features_json FROM `' . ecw_table('users') . '` WHERE id = ?');
        $st->execute([$userId]);
        $row = $st->fetch();
    } catch (Throwable $e) {}
    $profile = $row && isset(ECW_PROFILES[(string)$row['view_profile']]) ? (string)$row['view_profile'] : null;
    $picked = $row ? (json_decode((string)$row['view_features_json'], true) ?: []) : [];
    $features = [];
    foreach (array_keys(ecw_feature_catalog()) as $f) $features[$f] = !array_key_exists($f, $picked) || (bool)$picked[$f];
    return $cache[$userId] = ['profile' => $profile ?? ecw_org_default_profile($orgId), 'chosen' => $profile !== null, 'features' => $features];
}

/** Save a person's profile. $profile null = follow the organization default. */
function ecw_user_view_save(int $userId, ?string $profile, array $customOn = []): void
{
    if ($profile !== null && !isset(ECW_PROFILES[$profile])) throw new InvalidArgumentException('Unknown profile.');
    $json = null;
    if ($profile === 'custom') {
        $json = [];
        foreach (array_keys(ecw_feature_catalog()) as $f) $json[$f] = in_array($f, array_map('strval', $customOn), true);
        $json = json_encode($json);
    }
    ecw_db()->prepare('UPDATE `' . ecw_table('users') . '` SET view_profile = ?, view_features_json = ? WHERE id = ?')->execute([$profile, $json, $userId]);
}

/** Does the person's profile show $feature? (layer 3) */
function ecw_profile_shows(string $feature, array $view): bool
{
    if ($view['profile'] === 'custom') return $view['features'][$feature] ?? true;
    $list = ECW_PROFILES[$view['profile']][2] ?? null;
    return $list === null || in_array($feature, $list, true);
}

// ------------------------------------------------------------ all three layers

/** Layers 1 and 2 only: is $feature available to this person at all (whatever their profile)? */
function ecw_feature_available_for(string $feature, array $context): bool
{
    if (!ecw_package_has($feature)) return false;
    if (($context['role'] ?? '') === 'admin') return true;
    return ecw_features((int)$context['organization_id'])[$feature] ?? true;
}

/**
 * May the user described by $context use $feature? (ecw_feature_allowed() in
 * lib/session.php fills in the current context.)
 */
function ecw_feature_allowed_for(string $feature, array $context): bool
{
    if (!ecw_feature_available_for($feature, $context)) return false;
    $userId = (int)($context['user']['id'] ?? 0);
    if ($userId <= 0 || !isset(ecw_feature_catalog()[$feature])) return true;
    return ecw_profile_shows($feature, ecw_user_view($userId, (int)$context['organization_id']));
}

/** The user's effective feature map, for window.ecwFeatures. */
function ecw_features_for(array $context): array
{
    $out = [];
    foreach (array_keys(ecw_feature_catalog()) as $f) {
        $out[$f] = ecw_feature_allowed_for($f, $context);
    }
    return $out;
}
