<?php declare(strict_types=1);
/**
 * Optional modules: features that live outside the core, in modules/<id>/.
 *
 * The core works with no module at all. A module is a folder with a
 * module.php that returns its manifest, a plain array, and is read here
 * without running anything else of the module:
 *
 *   id          the folder name (a-z, 0-9, _)
 *   name        shown on Settings → License and in errors
 *   package     the cheapest package that has the module's features
 *   features    feature key => [label, description], joined to the catalogue (lib/features.php)
 *   tables      spec name => physical name, joined to ecw_table() (lib/schema.php)
 *   migrations  true: modules/<id>/migrations/*.sql run with the core's, in filename order
 *   api         ['file' => 'api.php', 'actions' => [action => options]]
 *               options: feature (403 without it), post (POST only), read_only (allowed to
 *               read-only users), case_scoped (case_id must be a case the user can see).
 *               The file runs inside web/api/index.php after sign-in, CSRF, mailbox
 *               ownership and those checks, with $action, $user, $orgId and $mailbox set.
 *   pages       page => file, opened at web/module.php?m=<id>&p=<page>
 *   settings    page => [file, icon, label, feature, admin], opened at web/settings/module.php
 *   nav         key => [label, icon, page, feature, after, show]: a tab in the top navigation,
 *               after the tab named by after (else before Settings).
 *               show: optional [file, function]; function(int $orgId, ?int $accountId): bool
 *   everywhere  ['feature' => ..., 'css' => [asset, ...], 'js' => [asset, ...]]: loaded on every
 *               signed-in page (web/partials/nav.php) for people with the feature
 *   backup_dirs folders under storage/ kept in backups and DataPush (lib/backup.php), e.g. "telegram/media"
 *   case_tabs   key => [label, icon, feature]: a tab in the case page's side panel (web/case.php),
 *               empty; the module's 'everywhere' script fills it on the "ecw:case-tab" event
 *   hooks       hook => [[file, function], ...] (see ecw_hook_*). Hooks the core calls:
 *               case.event_label ($type, $meta) → ?string    how a module's case event reads
 *               case.chat_material ($caseId, $limit) → list   chat messages linked to a case, for the AI
 *                   (ecw_case_chat_material: [ref, channel, from, date, direction, text])
 *               case.timeline_columns ($orgId, $caseId, ?$viewerUserId) → list   more columns on the case
 *                   timeline, next to the email threads (ecw_timeline_module_columns)
 *               case.timeline_events ($orgId, $caseId, $keys, ?$viewerUserId) → list   their rows
 *                   (ecw_timeline_module_events; lib/timeline.php merges them into the one time axis)
 *               case.merged ($sourceId, $targetId)   a case was merged into another (lib/case_extras.php,
 *                   inside its transaction: a failure undoes the merge)
 *               sync.tick ()   once a minute, from the sync daemon (bin/sync_daemon.php)
 *               chat.message_in (['source', 'organization_id', 'user_id', 'chat', 'from', 'text', 'link'])
 *                   a chat module stored a new incoming message (WhatsApp, Telegram)
 *
 * Files are relative to the module's folder. Assets (modules/<id>/assets/) are
 * served by web/module.php, since only web/ is reachable from a browser.
 */

const ECW_MODULE_ASSET_TYPES = [
    'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'svg' => 'image/svg+xml',
    'png' => 'image/png', 'webp' => 'image/webp', 'woff2' => 'font/woff2', 'json' => 'application/json',
];

function ecw_modules_dir(): string
{
    return dirname(__DIR__) . '/modules';
}

/** @return array<string, array> id => manifest (with 'dir'), in folder-name order. */
function ecw_modules(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $cache = [];
    foreach (glob(ecw_modules_dir() . '/*/module.php') ?: [] as $file) {
        $id = basename(dirname($file));
        if (!preg_match('/^[a-z0-9_]{1,40}$/D', $id)) continue;
        $m = (static fn(string $f) => require $f)($file);
        if (!is_array($m) || ($m['id'] ?? null) !== $id) continue;
        $cache[$id] = $m + ['dir' => dirname($file)];
    }
    ksort($cache, SORT_STRING);
    return $cache;
}

function ecw_module(string $id): ?array
{
    return ecw_modules()[$id] ?? null;
}

/** A file inside a module, or null when it is not there. */
function ecw_module_file(string $id, string $relative): ?string
{
    $m = ecw_module($id);
    if ($m === null || $relative === '' || str_contains($relative, '..')) return null;
    $path = $m['dir'] . '/' . ltrim($relative, '/');
    return is_file($path) ? $path : null;
}

/** feature => [label, description, module id, package] from every module. */
function ecw_module_features(): array
{
    $out = [];
    foreach (ecw_modules() as $id => $m) {
        foreach ($m['features'] ?? [] as $key => [$label, $description]) {
            $out[$key] = [$label, $description, $id, (string)($m['package'] ?? 'pro')];
        }
    }
    return $out;
}

/** spec name => physical table name from every module. */
function ecw_module_tables(): array
{
    $out = [];
    foreach (ecw_modules() as $m) $out += $m['tables'] ?? [];
    return $out;
}

/** Every migration, core and modules, in the order they run (by file name). */
function ecw_migration_files(): array
{
    $files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
    foreach (ecw_modules() as $id => $m) {
        if (!empty($m['migrations'])) $files = array_merge($files, glob($m['dir'] . '/migrations/*.sql') ?: []);
    }
    usort($files, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)));
    return $files;
}

/** The module API action $action, as options + 'module' and 'file', or null. */
function ecw_module_api_action(string $action): ?array
{
    if ($action === '') return null;
    foreach (ecw_modules() as $id => $m) {
        if (!isset($m['api']['actions'][$action])) continue;
        $file = ecw_module_file($id, (string)($m['api']['file'] ?? ''));
        return $file === null ? null : ((array)$m['api']['actions'][$action] + ['module' => $id, 'file' => $file]);
    }
    return null;
}

/** The file of a module page ('pages') or settings page ('settings'), or null. */
function ecw_module_page_file(string $id, string $page, string $kind = 'pages'): ?string
{
    $entry = ecw_module($id)[$kind][$page] ?? null;
    $relative = is_array($entry) ? (string)($entry['file'] ?? '') : (string)$entry;
    return $entry === null ? null : ecw_module_file($id, $relative);
}

/** Link to a module page, from the web root. */
function ecw_module_page_url(string $base, string $id, string $page): string
{
    return $base . 'module.php?m=' . rawurlencode($id) . '&p=' . rawurlencode($page);
}

function ecw_module_settings_url(string $base, string $id, string $page): string
{
    return $base . 'settings/module.php?m=' . rawurlencode($id) . '&p=' . rawurlencode($page);
}

/** Link to a module asset, with its modification time so browsers fetch a new version. */
function ecw_module_asset_url(string $base, string $id, string $asset): string
{
    $file = ecw_module_file($id, 'assets/' . $asset);
    return $base . 'module.php?m=' . rawurlencode($id) . '&asset=' . rawurlencode($asset) . '&v=' . ($file ? filemtime($file) : 0);
}

/** Send a module asset (web/module.php). Only modules/<id>/assets/, only known file types. */
function ecw_module_serve_asset(string $id, string $asset): never
{
    $ext = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
    $file = preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*\.[a-z0-9]+$#D', $asset) && isset(ECW_MODULE_ASSET_TYPES[$ext])
        ? ecw_module_file($id, 'assets/' . $asset) : null;
    if ($file === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . ECW_MODULE_ASSET_TYPES[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string)filesize($file));
    header(isset($_GET['v']) ? 'Cache-Control: public, max-age=31536000, immutable' : 'Cache-Control: no-cache');
    readfile($file);
    exit;
}

/** Top navigation tabs from modules: key => [label, icon, href, feature, after, show]. */
function ecw_module_nav(string $base): array
{
    $out = [];
    foreach (ecw_modules() as $id => $m) {
        foreach ($m['nav'] ?? [] as $key => $n) {
            $out[$key] = ['label' => (string)$n['label'], 'icon' => (string)($n['icon'] ?? 'fa-puzzle-piece'), 'href' => ecw_module_page_url($base, $id, (string)$n['page']),
                          'feature' => $n['feature'] ?? null, 'after' => $n['after'] ?? null, 'show' => isset($n['show']) ? [$id, $n['show']] : null];
        }
    }
    return $out;
}

/** Settings pages from modules: [id, page, file, icon, label, feature, admin]. */
function ecw_module_settings(): array
{
    $out = [];
    foreach (ecw_modules() as $id => $m) {
        foreach ($m['settings'] ?? [] as $page => $s) {
            $out[] = ['module' => $id, 'page' => $page, 'icon' => (string)($s['icon'] ?? 'fa-puzzle-piece'), 'label' => (string)$s['label'],
                      'feature' => $s['feature'] ?? null, 'admin' => !empty($s['admin'])];
        }
    }
    return $out;
}

/** Styles and scripts for every page: [['css'|'js', url], ...] for the features in $allowed (feature => bool). */
function ecw_module_everywhere(string $base, array $allowed): array
{
    $out = [];
    foreach (ecw_modules() as $id => $m) {
        $e = $m['everywhere'] ?? null;
        if (!is_array($e) || (isset($e['feature']) && empty($allowed[$e['feature']]))) continue;
        foreach (['css', 'js'] as $kind) foreach ($e[$kind] ?? [] as $asset) $out[] = [$kind, ecw_module_asset_url($base, $id, (string)$asset)];
    }
    return $out;
}

/** Case page tabs from modules: [key, module, label, icon, feature]. */
function ecw_module_case_tabs(): array
{
    $out = [];
    foreach (ecw_modules() as $id => $m) {
        foreach ($m['case_tabs'] ?? [] as $key => $t) {
            $out[] = ['key' => (string)$key, 'module' => $id, 'label' => (string)$t['label'], 'icon' => (string)($t['icon'] ?? 'fa-puzzle-piece'), 'feature' => $t['feature'] ?? null];
        }
    }
    return $out;
}

/**
 * Chat messages linked to a case (Teams, WhatsApp, Telegram… by modules, hook
 * case.chat_material), for the AI to read with the emails: oldest first, the
 * newest $limit. Each: ['ref' => 'C12', 'channel', 'from', 'date', 'direction', 'text'].
 */
function ecw_case_chat_material(int $caseId, int $limit = 30): array
{
    $out = [];
    foreach (ecw_hook_functions('case.chat_material') as $fn) {
        try { $r = $fn($caseId, $limit); }
        catch (Throwable $e) { if (function_exists('ecw_log')) ecw_log("hook case.chat_material ({$fn}) failed: " . $e->getMessage()); continue; }
        if (is_array($r)) $out = array_merge($out, array_values($r));
    }
    usort($out, static fn(array $a, array $b): int => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));
    return array_slice($out, -$limit);
}

/**
 * Columns modules add to a case's timeline (hook case.timeline_columns), e.g.
 * a chat whose messages are linked to the case. Each column is like an email
 * thread's (lib/timeline.php ecw_case_columns) but:
 *   thread_id  a text key that is never a number (e.g. "chat:12"): the column's id on the page
 *   kind       what it is (not "email"); the page draws its rows with window.ecwTimelineRenderers[<event type>]
 *   update_action / unlink_action   the API actions for rename, hide, role / unlink
 * plus label, subject, subtitle, icon, message_count, latest_at, is_hidden, sort_order, party_role, open_url.
 */
function ecw_timeline_module_columns(int $orgId, int $caseId, ?int $viewerUserId): array
{
    $out = [];
    foreach (ecw_hook_functions('case.timeline_columns') as $fn) {
        try { $r = $fn($orgId, $caseId, $viewerUserId); }
        catch (Throwable $e) { if (function_exists('ecw_log')) ecw_log("hook case.timeline_columns ({$fn}) failed: " . $e->getMessage()); continue; }
        foreach ((array)$r as $c) {
            if (!is_array($c) || !isset($c['thread_id']) || is_numeric($c['thread_id'])) continue;   // never mistaken for an email thread
            $out[] = $c + ['kind' => 'module', 'label' => '', 'message_count' => 0, 'latest_at' => null, 'is_hidden' => false, 'sort_order' => 1000,
                           'party_role' => null, 'unread_count' => 0, 'mailbox_id' => null, 'participants' => []];
        }
    }
    return $out;
}

/**
 * The rows of those columns (hook case.timeline_events), for the visible
 * column keys only. Each: ['type' => …, 'thread_id' => the column key,
 * 'occurred_at' => UTC, 'item' => what the page draws, 'group' => bool].
 * group: rows of the same column that follow each other on the time axis
 * within 10 minutes, with nothing else in between, share one row
 * (a burst of chat messages costs about as much room as one email).
 */
function ecw_timeline_module_events(int $orgId, int $caseId, array $keys, ?int $viewerUserId): array
{
    if ($keys === []) return [];
    $want = array_flip(array_map('strval', $keys));
    $out = [];
    foreach (ecw_hook_functions('case.timeline_events') as $fn) {
        try { $r = $fn($orgId, $caseId, array_keys($want), $viewerUserId); }
        catch (Throwable $e) { if (function_exists('ecw_log')) ecw_log("hook case.timeline_events ({$fn}) failed: " . $e->getMessage()); continue; }
        foreach ((array)$r as $ev) {
            if (is_array($ev) && isset($ev['thread_id'], $ev['occurred_at'], $ev['type']) && isset($want[(string)$ev['thread_id']]) && $ev['type'] !== 'email') $out[] = $ev;
        }
    }
    return $out;
}

// ------------------------------------------------------------ hooks

/** Load a module file and return its function, or null. $ref = [module id, [file, function]]. */
function ecw_module_callable(string $id, array $fileAndFunction): ?string
{
    [$relative, $function] = $fileAndFunction + [null, null];
    $file = ecw_module_file($id, (string)$relative);
    if ($file === null || !is_string($function)) return null;
    include_once $file;   // is there: checked above
    return function_exists($function) ? $function : null;
}

/** @return list<string> the functions registered for $hook, their files loaded. */
function ecw_hook_functions(string $hook): array
{
    $out = [];
    foreach (ecw_modules() as $id => $m) {
        foreach ($m['hooks'][$hook] ?? [] as $ref) {
            $fn = ecw_module_callable($id, (array)$ref);
            if ($fn !== null) $out[] = $fn;
        }
    }
    return $out;
}

/** Run every function of $hook; a failing module is logged and never stops the core. */
function ecw_hook_run(string $hook, mixed ...$args): void
{
    foreach (ecw_hook_functions($hook) as $fn) {
        try { $fn(...$args); }
        catch (Throwable $e) { if (function_exists('ecw_log')) ecw_log("hook {$hook} ({$fn}) failed: " . $e->getMessage()); }
    }
}

/** The first non-null answer to $hook, or null. */
function ecw_hook_first(string $hook, mixed ...$args): mixed
{
    foreach (ecw_hook_functions($hook) as $fn) {
        try { $r = $fn(...$args); }
        catch (Throwable $e) { if (function_exists('ecw_log')) ecw_log("hook {$hook} ({$fn}) failed: " . $e->getMessage()); continue; }
        if ($r !== null) return $r;
    }
    return null;
}
