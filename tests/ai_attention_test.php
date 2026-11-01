<?php declare(strict_types=1);
/**
 * The AI looks only at what is addressed to the person, and points out what
 * they left unanswered (lib/agents.php); Greek and English spelling and the
 * person's own words for suggestions (lib/spelling.php).
 *
 *   php tests/ai_attention_test.php
 *
 * Runs in a transaction that is rolled back. No AI is called.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/agents.php';
require_once dirname(__DIR__) . '/lib/spelling.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Attention']);
    $u = $ins('users', ['name' => 'George', 'email' => 'george' . $org . '@att.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $u, 'role' => 'admin']);
    $me = 'george' . $org . '@inq.test';
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'oid-att' . $org, 'email_address' => $me, 'sync_status' => 'connected', 'connected_by_user_id' => $u]);
    $inbox = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'IN-' . $mb, 'name' => 'Inbox', 'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $n = 0;
    $email = static function (string $from, array $to, array $cc, string $text, int $daysAgo, string $dir = 'incoming', ?int $thread = null) use ($ins, $mb, $inbox, &$n, $pdo): array {
        $n++;
        $thread ??= $ins('email_threads', ['mailbox_id' => $mb, 'normalized_subject' => 'Subject ' . $n]);
        $at = gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400 - 3600);
        $id = $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $thread, 'mail_folder_id' => $inbox, 'provider_message_id' => 'P' . $n . '-' . $mb,
            'subject' => 'Subject ' . $n, 'from_email' => $from, 'from_name' => ucfirst(strtok($from, '@')), 'to_json' => json_encode(array_map(fn($e) => ['email' => $e], $to)),
            'cc_json' => json_encode(array_map(fn($e) => ['email' => $e], $cc)), 'body_text' => $text, 'body_fetch_state' => 'fetched', 'direction' => $dir,
            'received_at' => $at, 'sent_at' => $at]);
        foreach ($to as $e) $ins('message_participants', ['email_message_id' => $id, 'role' => 'to', 'email' => $e]);
        foreach ($cc as $e) $ins('message_participants', ['email_message_id' => $id, 'role' => 'cc', 'email' => $e]);
        $row = $pdo->query("SELECT m.*, '" . $GLOBALS['me'] . "' AS mailbox_address FROM ecw_email_messages m WHERE m.id = {$id}")->fetch();
        return $row;
    };
    $GLOBALS['me'] = $me;

    echo "\n=== who an email is for ===\n";
    $toMe = $email('client@acme.test', [$me], [], 'Could you send us a quotation for the battery?', 3);
    $ccMe = $email('savvas@inq.test', ['buyer@madarco.test'], [$me], 'Dear all, please find our offer for ASCONA attached. Can you confirm?', 3);
    check(ecw_agent_addressed($toMe, $u) === 'to', 'an email sent to the person is for them');
    check(ecw_agent_addressed($ccMe, $u) === 'cc', 'a colleague writing to a customer with them in Cc is a copy');
    check(ecw_agent_from_colleague($ccMe) && !ecw_agent_from_colleague($toMe), 'a colleague is someone of the own domain');
    check(!ecw_agent_from_colleague(['from_email' => 'noreply@inq.test', 'mailbox_address' => $me]), 'a website form sending from the own domain is not a colleague');

    echo "\n=== left unanswered ===\n";
    $s = ecw_agents_settings($org);
    $s['since'] = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $answered = $email('other@acme.test', [$me], [], 'Can you call me?', 3);
    $email($me, ['other@acme.test'], [], 'Sure, calling now.', 2, 'outgoing', (int)$answered['email_thread_id']);
    $byColleague = $email('third@acme.test', [$me], [], 'Can you help?', 3);
    $email('savvas@inq.test', ['third@acme.test'], [$me], 'I will take this one.', 2, 'incoming', (int)$byColleague['email_thread_id']);
    $fresh = $email('new@acme.test', [$me], [], 'Can you send the price?', 0);
    $plain = $email('fyi@acme.test', [$me], [], 'Thank you for the visit yesterday.', 3);
    ecw_agent_unanswered($org, $s);
    $cards = $pdo->query("SELECT message_id FROM ecw_ai_suggestions WHERE organization_id = {$org} AND agent = 'unanswered' AND state = 'open'")->fetchAll(PDO::FETCH_COLUMN);
    $cards = array_map('intval', $cards);
    check($cards === [(int)$toMe['id']], 'only the email sent to them, that asks, that nobody answered for a working day', json_encode($cards));
    check(!in_array((int)$ccMe['id'], $cards, true), 'not the one they were only copied on');
    check(!in_array((int)$answered['id'], $cards, true) && !in_array((int)$byColleague['id'], $cards, true), 'not one they or a colleague answered');
    check(!in_array((int)$fresh['id'], $cards, true) && !in_array((int)$plain['id'], $cards, true), 'not one that came today, nor one that asks nothing');
    ecw_agent_unanswered($org, $s);
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_ai_suggestions WHERE organization_id = {$org} AND agent = 'unanswered'")->fetchColumn() === 1, 'each email is pointed out once');

    echo "\n=== spelling ===\n";
    $wrong = ecw_spell_check(['Καλημέρα', 'καλημερα', 'προσφορά', 'προσφρά', 'Thank', 'recieve', 'SCIAPS', 'Exalco'], ['Exalco']);
    check(isset($wrong['καλημερα']) && in_array('καλημέρα', $wrong['καλημερα'], true), 'Greek without its accent is marked, with the correction', json_encode($wrong, JSON_UNESCAPED_UNICODE));
    check(isset($wrong['προσφρά']) && in_array('προσφορά', $wrong['προσφρά'], true) && isset($wrong['recieve']), 'Greek and English typos are found');
    check(!isset($wrong['Καλημέρα']) && !isset($wrong['προσφορά']) && !isset($wrong['Thank']), 'correct words are fine');
    check(!isset($wrong['SCIAPS']) && !isset($wrong['Exalco']), 'acronyms and the person own words are never marked');
    check(ecw_spell_fold('Καλημέρα') === 'καλημερα' && ecw_spell_fold('ΕΥΧΑΡΙΣΤΟΎΜΕ') === 'ευχαριστουμε', 'suggestions match without accents');
    ecw_spell_learn($u, 'Ascona');
    check(in_array('Ascona', ecw_spell_own_words($u), true), 'Add to dictionary keeps the word for the person');
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
