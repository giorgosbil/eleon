<?php declare(strict_types=1);
/**
 * Every entry point must be able to call every function it calls.
 *
 *   php tests/wiring_test.php
 *
 * PHP resolves function names at call time, so a missing require_once is
 * invisible to the linter, invisible to a test that never walks that branch,
 * and shows up in production as "Call to undefined function" on the one code
 * path nobody exercised. This test found exactly that: sync_cron.php loaded
 * subscriptions.php without messages.php, and the subscription-renewal branch
 * called ecw_folder_row(), which lived in messages.php.
 *
 * For each file under bin/ and web/, it loads only that file's own requires in
 * a fresh process, then asserts that every ecw_* function the file calls is
 * defined. No branch has to run for the check to hold.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

$entryPoints = array_merge(
    glob($root . '/bin/*.php') ?: [],
    glob($root . '/web/*.php') ?: [],
    glob($root . '/web/*/*.php') ?: [],
    glob($root . '/lib/*.php') ?: [],
    glob($root . '/lib/sync/*.php') ?: [],
    // Tests are entry points too: one of them called ecw_split_quoted() without
    // requiring lib/body.php, and nothing but running it would have said so.
    glob($root . '/tests/*.php') ?: [],
    // Optional modules (lib/modules.php): their pages, libraries and tests.
    glob($root . '/modules/*/*.php') ?: [],
    glob($root . '/modules/*/*/*.php') ?: []
);

foreach ($entryPoints as $file) {
    // This file cannot scan itself: its own source contains the require_once
    // REGEX as a string literal, which the scan then treats as a require.
    if (basename($file) === basename(__FILE__)) {
        continue;
    }
    $source = (string)file_get_contents($file);
    $label  = str_replace($root . '/', '', $file);

    // A module's API file runs inside web/api/index.php: what that loads and defines, it has.
    $host = preg_match('#/modules/[a-z0-9_]+/api\.php$#', $file) ? $root . '/web/api/index.php' : null;
    $hostSource = $host !== null ? (string)file_get_contents($host) : '';

    // Functions this file defines itself do not need to come from a require.
    preg_match_all('/function\s+(ecw_[a-z0-9_]+)\s*\(/i', $source . $hostSource, $defined);
    $definedHere = array_map('strtolower', $defined[1] ?? []);

    // Ignore comments and string literals: SQL table names are not function calls.
    $callSource = implode('', array_map(static function ($token): string {
        if (!is_array($token)) return $token;
        return in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true) ? ' ' : $token[1];
    }, token_get_all($source)));

    // Functions it calls. Excludes "function foo(" declarations and ->method().
    preg_match_all('/(?<![>\$:a-z0-9_])(ecw_[a-z0-9_]+)\s*\(/i', $callSource, $called);
    $calls = array_values(array_unique(array_map('strtolower', $called[1] ?? [])));
    $calls = array_values(array_diff($calls, $definedHere));

    if ($calls === []) {
        continue;
    }

    // Load exactly what the file loads — no more.
    preg_match_all('/require_once\s+([^;]+);/', $source, $requires);
    $requireLines = $requires[0] ?? [];
    if ($host !== null) {
        preg_match_all('/require_once\s+([^;]+);/', $hostSource, $hostRequires);
        $requireLines = array_merge(array_map(static fn(string $l): string => str_replace('__DIR__', var_export(dirname($host), true), $l), $hostRequires[0] ?? []), $requireLines);
    }
    if ($requireLines === []) {
        continue;
    }

    $probe = '$__file = ' . var_export($file, true) . '; '
           . 'define("ECW_TESTING", true); '
           . implode(' ', array_map(
               static fn(string $line): string => str_replace('__DIR__', var_export(dirname($file), true), $line),
               $requireLines
           ))
           . ' $missing = []; foreach (' . var_export($calls, true) . ' as $fn) { '
           . 'if (!function_exists($fn)) { $missing[] = $fn; } } '
           . 'echo implode(",", $missing);';

    $output = trim((string)shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($probe) . ' 2>&1'));

    if ($output === '') {
        $pass++;
        printf("  ok    %-34s %d call(s) resolve\n", $label, count($calls));
    } else {
        $fail++;
        printf("  FAIL  %-34s %s\n", $label, $output);
    }
}

echo "\n{$pass} file(s) wired correctly, {$fail} broken\n";
exit($fail === 0 ? 0 : 1);
