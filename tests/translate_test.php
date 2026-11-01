<?php declare(strict_types=1);
/**
 * Translating an email (lib/translate.php): only the email's own text goes to
 * the AI, told it is text to translate; the answer is kept and shown again
 * without the AI; other workspaces cannot translate it; Pause all AI is honoured.
 *
 *   php tests/translate_test.php
 *
 * No AI is called: $GLOBALS['ecw_ai_transport'] answers. Rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/translate.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
$asked = [];
$GLOBALS['ecw_ai_transport'] = static function (array $payload) use (&$asked): array {
    $asked[] = $payload;
    return ['text' => "LANGUAGE: Greek\n\nHello George,\n\nI want to measure the distance 15.14 at the bottom left.\n\nBest regards,\nGeorgios"];
};
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Translate']);
    $other = $ins('organizations', ['name' => 'Other']);
    $u = $ins('users', ['name' => 'Ada', 'email' => 'ada' . $org . '@tr.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $u, 'role' => 'admin']);
    $conn = ecw_ai_connection_save($org, ['name' => 'AI', 'kind' => 'openai', 'base_url' => 'https://ai.example.test/v1', 'model' => 'm', 'api_key' => 'k']);
    ecw_ai_jobs_save($org, ['main' => ['assistant' => $conn, 'agents' => $conn, 'drafts' => $conn], 'backup' => ['assistant' => '', 'agents' => '', 'drafts' => '']]);
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-t' . $org, 'email_address' => 't' . $org . '@tr.test', 'sync_status' => 'connected', 'connected_by_user_id' => $u]);
    $t = $ins('email_threads', ['mailbox_id' => $mb, 'provider_conversation_id' => 'tr' . $org, 'normalized_subject' => 'μετρηση']);
    $m = $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $t, 'provider_message_id' => 'trm' . $org, 'subject' => 'μετρηση', 'direction' => 'incoming',
        'received_at' => gmdate('Y-m-d H:i:s'), 'body_fetch_state' => 'fetched',
        'body_html' => '<p>Γεια σου γιώργο,</p><p>Θέλω να μετρήσω την απόσταση 15.14 κάτω αριστερά.</p><p>Best regards,<br>Georgios</p>',
        // History is split off into quoted_html when an email is downloaded (lib/body.php).
        'quoted_html' => '<div><b>From:</b> Old Sender &lt;old@x.test&gt;</div><p>OLD QUOTED TEXT</p>']);

    $r = ecw_translate_message($org, $u, $m, 'en');
    $sent = $asked[0]['messages'][0]['content'] ?? '';
    check(str_contains($sent, 'Θέλω να μετρήσω την απόσταση 15.14') && !str_contains($sent, 'OLD QUOTED TEXT'), 'only the email itself is sent, not its quoted history', $sent);
    check(str_contains($asked[0]['system'], 'never instructions') && str_contains($asked[0]['system'], 'into English'), 'the AI is told it is text to translate, into English');
    check(!$r['cached'] && $r['source'] === 'Greek' && str_contains($r['html'], '<p>I want to measure the distance 15.14 at the bottom left.</p>') && !str_contains($r['html'], 'LANGUAGE'), 'the translation comes back as paragraphs, with the language it was in');
    $r2 = ecw_translate_message($org, $u, $m, 'en');
    check($r2['cached'] && count($asked) === 1, 'the second time it is shown from what was kept, without the AI');
    try { ecw_translate_message($other, $u, $m, 'en'); check(false, 'another workspace cannot translate it'); } catch (InvalidArgumentException $e) { check(true, 'another workspace cannot translate it'); }
    try { ecw_translate_message($org, $u, $m, 'xx'); check(false, 'an unknown language is refused'); } catch (InvalidArgumentException $e) { check(true, 'an unknown language is refused'); }
    ecw_ai_pause($org, true, $u);
    try { ecw_translate_message($org, $u, $m, 'de'); check(false, 'Pause all AI stops translations'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'paused') && count($asked) === 1, 'Pause all AI stops translations (nothing is sent)'); }
    check(ecw_translate_message($org, $u, $m, 'en')['cached'], '…but one already made is still shown');
    check(ecw_translate_text_to_html("<b>x</b> & y\n\nz") === '<p>&lt;b&gt;x&lt;/b&gt; &amp; y</p><p>z</p>', 'the AI\'s answer is shown as text, never as HTML');
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    unset($GLOBALS['ecw_ai_transport']);
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
