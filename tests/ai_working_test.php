<?php declare(strict_types=1);
/**
 * "The AI is reading this email" (lib/agents.php ecw_agents_reading): which new
 * emails the triage agent has still to read, and that the spinner can never
 * stay forever.
 *
 *   php tests/ai_working_test.php
 *
 * Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/agents.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'AI working']);
    $admin = $ins('users', ['name' => 'Ada', 'email' => 'ada' . $org . '@work.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $admin, 'role' => 'admin']);
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-w' . $org, 'email_address' => 'w' . $org . '@work.test', 'sync_status' => 'connected', 'connected_by_user_id' => $admin]);
    $inbox = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'IN' . $org, 'name' => 'Inbox', 'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $junk = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'JUNK' . $org, 'name' => 'Junk', 'folder_type' => 'junk', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $thread = static fn(string $s) => $ins('email_threads', ['mailbox_id' => $mb, 'provider_conversation_id' => 'c' . $s . $org, 'normalized_subject' => $s]);
    $msg = static function (int $t, int $folder, string $dir = 'incoming', ?string $created = null) use ($ins, $mb): int {
        static $n = 0; $n++;
        $row = ['mailbox_id' => $mb, 'email_thread_id' => $t, 'mail_folder_id' => $folder, 'provider_message_id' => 'aw' . $mb . '-' . $n, 'subject' => 'S', 'direction' => $dir,
            $dir === 'incoming' ? 'received_at' : 'sent_at' => gmdate('Y-m-d H:i:s'), 'body_text' => 'Hello', 'body_fetch_state' => 'fetched'];
        if ($created) $row['created_at'] = $created;
        return $ins('email_messages', $row);
    };
    $conn = ecw_ai_connection_save($org, ['name' => 'AI', 'kind' => 'openai', 'base_url' => 'https://ai.example.test/v1', 'model' => 'm', 'api_key' => 'k']);
    ecw_ai_jobs_save($org, ['main' => ['assistant' => $conn, 'agents' => $conn, 'drafts' => $conn], 'backup' => ['assistant' => '', 'agents' => '', 'drafts' => '']]);
    ecw_agents_settings_save($org, ['on' => array_map(fn($a) => $a[2], ECW_AGENTS)], false);

    $tNew = $thread('new'); $mNew = $msg($tNew, $inbox);
    $tSent = $thread('sent'); $msg($tSent, $inbox, 'outgoing');
    $tJunk = $thread('junk'); $msg($tJunk, $junk);
    $tOld = $thread('old'); $msg($tOld, $inbox, 'incoming', gmdate('Y-m-d H:i:s', time() - 3600));
    $tRead = $thread('read'); $mRead = $msg($tRead, $inbox);
    ecw_agent_mark($org, 'triage', 'message', $mRead);

    $r = ecw_agents_reading($org, $mb);
    check($r['threads'] === [$tNew] && $r['messages'] === [$mNew], 'only the new incoming email the AI has not read yet', json_encode($r));
    ecw_agent_mark($org, 'triage', 'message', $mNew, 'failed');
    check(ecw_agents_reading($org, $mb)['threads'] === [], 'read (or failed) emails stop showing at once');

    $tNew2 = $thread('new2'); $msg($tNew2, $inbox);
    ecw_ai_pause($org, true, $admin);
    check(ecw_agents_reading($org, $mb)['threads'] === [], 'Pause all AI: nothing shows as being read');
    ecw_ai_pause($org, false, $admin);
    $s = ecw_agents_settings($org); $s['on']['triage'] = false;
    ecw_agents_settings_save($org, $s, false);
    check(ecw_agents_reading($org, $mb)['threads'] === [], 'triage switched off: nothing shows as being read');
    $s['on']['triage'] = true;
    ecw_agents_settings_save($org, $s, false);
    check(ecw_agents_reading($org, $mb)['threads'] === [$tNew2], 'switched back on: the waiting email shows again');
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
