<?php declare(strict_types=1);
/**
 * AI suggestions that no longer apply go by themselves (lib/agents.php
 * ecw_suggestions_settle): the case was closed, the conversation was
 * answered, it is in a case now, the prepared draft was sent or deleted. An
 * untouched AI draft goes with its suggestion; a changed one stays.
 *
 *   php tests/suggestions_settle_test.php
 *
 * Runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/agents.php';
require_once dirname(__DIR__) . '/lib/compose.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Settle']);
    $u = $ins('users', ['name' => 'Ada', 'email' => 'ada' . $org . '@settle.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $u, 'role' => 'admin']);
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-s' . $org, 'email_address' => 's' . $org . '@settle.test', 'sync_status' => 'connected', 'connected_by_user_id' => $u]);
    $thread = static function () use ($ins, $mb, $org): int { static $n = 0; $n++; return $ins('email_threads', ['mailbox_id' => $mb, 'provider_conversation_id' => 'st' . $org . '-' . $n, 'normalized_subject' => 's']); };
    $msg = static function (int $t, string $dir, int $ago) use ($ins, $mb, $org): int {
        static $k = 0; $k++;
        return $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $t, 'provider_message_id' => 'sm' . $org . '-' . $k, 'subject' => 'S', 'direction' => $dir,
            $dir === 'incoming' ? 'received_at' : 'sent_at' => gmdate('Y-m-d H:i:s', time() - $ago), 'body_text' => 'x']);
    };
    $sug = static function (string $kind, int $t, ?int $m, ?int $case, array $actions) use ($ins, $org, $u): int {
        return $ins('ai_suggestions', ['organization_id' => $org, 'user_id' => $u, 'agent' => $kind === 'draft' ? 'drafter' : 'triage', 'kind' => $kind, 'thread_id' => $t, 'message_id' => $m,
            'case_id' => $case, 'title' => 'T', 'actions_json' => json_encode($actions), 'state' => 'open', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)]);
    };
    $state = static fn(int $id) => $pdo->query('SELECT state, result, actions_json FROM `' . ecw_table('ai_suggestions') . '` WHERE id = ' . $id)->fetch();

    // 1. Answered: the prepared draft and "Draft a reply" go; the untouched AI draft is removed.
    $t1 = $thread(); $m1 = $msg($t1, 'incoming', 3600);
    $draft = ecw_draft_save($org, $mb, $u, ['mode' => 'reply', 'in_reply_to_message_id' => $m1, 'subject' => 'Re: S', 'body_html' => '<p>AI text</p>', 'to' => [['email' => 'c@client.test']]]);
    $body = (string)$pdo->query('SELECT body_html FROM `' . ecw_table('drafts') . '` WHERE id = ' . $draft)->fetchColumn();
    $sDraft = $sug('draft', $t1, $m1, null, [['type' => 'open_draft', 'label' => 'Open the draft', 'draft_id' => $draft, 'body_sha1' => sha1($body)]]);
    $sTriage = $sug('triage', $t1, $m1, null, [['type' => 'draft_reply', 'label' => 'Draft a reply'], ['type' => 'note', 'label' => 'Save these facts', 'text' => 'x']]);
    $before = ecw_suggestions_for($org, $u, $t1);
    check(count($before) === 2, 'before answering: both suggestions show');
    $msg($t1, 'outgoing', 60);
    $after = ecw_suggestions_for($org, $u, $t1);
    check($after === [], 'after we answered: neither shows', json_encode($after));
    check($state($sDraft)['result'] === 'the conversation was answered' && $state($sTriage)['state'] === 'expired', 'both settled, with the reason');
    check(ecw_draft_load($org, $draft) === null, 'the untouched AI draft is removed too');

    // 2. A draft someone changed is kept, even though its suggestion goes.
    $t2 = $thread(); $m2 = $msg($t2, 'incoming', 3600);
    $d2 = ecw_draft_save($org, $mb, $u, ['mode' => 'reply', 'in_reply_to_message_id' => $m2, 'subject' => 'Re: S', 'body_html' => '<p>AI text</p>', 'to' => [['email' => 'c@client.test']]]);
    $s2 = $sug('draft', $t2, $m2, null, [['type' => 'open_draft', 'label' => 'Open', 'draft_id' => $d2, 'body_sha1' => sha1('<p>something else</p>')]]);
    $msg($t2, 'outgoing', 60);
    ecw_suggestions_for($org, $u);
    check($state($s2)['state'] === 'expired' && ecw_draft_load($org, $d2) !== null, 'a changed draft stays in Drafts');

    // 3. A closed case: all of its suggestions go.
    $case = ecw_case_create($org, 'Closed one', $u);
    $t3 = $thread(); $m3 = $msg($t3, 'incoming', 3600);
    ecw_case_link_thread($case, $t3);
    $s3 = $sug('triage', $t3, $m3, $case, [['type' => 'set_due', 'label' => 'Set due', 'case_id' => $case, 'date' => '2026-12-01']]);
    check(count(ecw_suggestions_for($org, $u, null, $case)) === 1, 'an open case shows its suggestion');
    $pdo->exec('UPDATE `' . ecw_table('cases') . "` SET status = 'closed' WHERE id = {$case}");
    check(ecw_suggestions_for($org, $u, null, $case) === [] && $state($s3)['result'] === 'the case is closed', 'closing the case settles it');

    // 4. In a case now: "Start a case" is dropped, a useful action stays.
    $case2 = ecw_case_create($org, 'Open one', $u);
    $t4 = $thread(); $m4 = $msg($t4, 'incoming', 3600);
    $s4 = $sug('triage', $t4, $m4, null, [['type' => 'new_case', 'label' => 'Start a case', 'name' => 'x'], ['type' => 'remind', 'label' => 'Remind me', 'at' => gmdate(DATE_ATOM, time() + 86400), 'note' => 'n']]);
    ecw_case_link_thread($case2, $t4);
    ecw_suggestions_for($org, $u);
    $r4 = $state($s4);
    check($r4['state'] === 'open' && array_column(json_decode($r4['actions_json'], true), 'type') === ['remind'], 'filed into a case: "Start a case" goes, the reminder stays', json_encode($r4));

    // 5. The prepared draft was sent or deleted elsewhere: its suggestion goes.
    $t5 = $thread(); $m5 = $msg($t5, 'incoming', 3600);
    $s5 = $sug('draft', $t5, $m5, null, [['type' => 'open_draft', 'label' => 'Open', 'draft_id' => 999999999]]);
    ecw_suggestions_for($org, $u);
    check($state($s5)['result'] === 'the draft was sent or deleted', 'a draft that is gone takes its suggestion with it');

    // 6. Not answered, nothing changed: stays.
    $t6 = $thread(); $m6 = $msg($t6, 'incoming', 3600);
    $s6 = $sug('triage', $t6, $m6, null, [['type' => 'draft_reply', 'label' => 'Draft a reply']]);
    ecw_suggestions_for($org, $u);
    check($state($s6)['state'] === 'open', 'a suggestion that still applies stays');
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);