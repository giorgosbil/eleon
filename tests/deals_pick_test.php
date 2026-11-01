<?php declare(strict_types=1);
/**
 * Deals → "Add a document from an email" (lib/deals.php ecw_deal_doc_read): a person clicks one
 * email of the case; with a stage it is added as that, without one the AI reads that one email
 * (the rest of the case as context), the rules when the AI is off or fails. And what the AI
 * is told: our domains, who each email went to, automatic replies left out.
 *
 *   php tests/deals_pick_test.php
 *
 * A fake AI; runs in a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/deals.php';
require_once dirname(__DIR__) . '/lib/ai.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Deals pick']);
    $u = $ins('users', ['name' => 'Picker', 'email' => 'p' . $org . '@pick.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $u, 'role' => 'admin']);
    $us = 'support@inqpick' . $org . '.test';
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'pick-' . $org, 'email_address' => $us, 'sync_status' => 'connected']);
    $folder = $ins('mail_folders', ['mailbox_id' => $mb, 'provider_folder_id' => 'inbox', 'name' => 'Inbox', 'folder_type' => 'inbox']);
    $case = $ins('cases', ['organization_id' => $org, 'case_number' => 'PK-' . $org, 'name' => 'ΡΟΛΟΠΑΚ training', 'status' => 'open']);
    $th = $ins('email_threads', ['mailbox_id' => $mb, 'provider_conversation_id' => 'pk-' . $org, 'normalized_subject' => 'training']);
    $ins('case_threads', ['case_id' => $case, 'email_thread_id' => $th, 'party_role' => 'customer']);
    $n = 0;
    $msg = static function (string $dir, string $subject, string $text) use ($ins, $mb, $th, $folder, $us, &$n): int {
        $n++;
        $at = gmdate('Y-m-d H:i:s', time() - (20 - $n) * 3600);
        $from = $dir === 'outgoing' ? $us : 'buyer@rolopak.test';
        return $ins('email_messages', ['mailbox_id' => $mb, 'email_thread_id' => $th, 'mail_folder_id' => $folder, 'provider_message_id' => 'pk' . $n . uniqid(), 'subject' => $subject,
            'from_email' => $from, 'from_name' => $dir === 'outgoing' ? 'Us' : 'Buyer', 'to_json' => json_encode([['name' => 'To ' . $n, 'email' => $dir === 'outgoing' ? 'buyer@rolopak.test' : $us]]),
            'cc_json' => '[]', 'direction' => $dir, ($dir === 'outgoing' ? 'sent_at' : 'received_at') => $at, 'body_text' => $text, 'body_fetch_state' => 'fetched']);
    };
    $ask = $msg('incoming', 'ΡΟΛΟΠΑΚ ΑΒΕΕ', 'Καλησπέρα, θα θέλαμε να μάθουμε πώς και πότε μπορεί να γίνει μια εκπαίδευση στο προσωπικό μας.');
    $auto = $msg('outgoing', 'Automatic reply: ΡΟΛΟΠΑΚ ΑΒΕΕ', 'I am out of the office until Monday.');
    $offer = $msg('outgoing', 'Re: ΡΟΛΟΠΑΚ ΑΒΕΕ', 'Σας επισυνάπτουμε την προσφορά μας για την εκπαίδευση.');
    $agree = $msg('incoming', 'Re: ΡΟΛΟΠΑΚ ΑΒΕΕ', 'Είμαστε σύμφωνοι, ας την κάνουμε στις 20/10.');
    $order = $msg('incoming', 'Purchase Order 4500123', 'We are pleased to place our order for the items in your quotation. Total amount: 1.200,00 EUR');
    $lunch = $msg('incoming', 'Lunch?', 'Are you free on Friday?');

    $GLOBALS['ecw_ai_test_config'] = ['key' => 'fake', 'daily_requests' => 50];
    $payloads = []; $answer = null; $broken = false;
    $GLOBALS['ecw_ai_transport'] = static function ($p) use (&$payloads, &$answer, &$broken) {
        if ($broken) throw new RuntimeException('The AI endpoint did not answer.');
        $payloads[] = $p;
        return ['text' => json_encode($answer, JSON_UNESCAPED_UNICODE), 'usage' => [], 'truncated' => false];
    };
    $docs = static function () use ($pdo, $case): array {
        $st = $pdo->prepare('SELECT message_id, stage, side, source, locked, dismissed, summary FROM `' . ecw_table('deal_docs') . '` WHERE case_id = ? ORDER BY message_id, stage');
        $st->execute([$case]);
        return $st->fetchAll();
    };
    $find = static fn(array $all, int $mid, string $stage) => array_values(array_filter($all, static fn($d) => (int)$d['message_id'] === $mid && $d['stage'] === $stage))[0] ?? null;

    echo "\n=== what the AI is told ===\n";
    $answer = ['documents' => [['source' => 'M' . $ask, 'stage' => 'rfq', 'side' => 'sell', 'summary' => 'Εκπαίδευση προσωπικού']], 'note' => 'x'];
    ecw_deals_ai($org, $u, $case, true);
    $sys = (string)$payloads[0]['system'];
    $sent = json_decode($payloads[0]['messages'][0]['content'], true)['emails'];
    $ids = array_column($sent, 'id');
    check(str_contains($sys, 'inqpick' . $org . '.test'), 'the AI knows which domains are ours');
    check(str_contains($sys, 'training') && str_contains($sys, 'είμαστε σύμφωνοι') && str_contains($sys, 'price LIST'), 'a training request is an RFQ, an acceptance a PO, a price list not an offer');
    check(!in_array('M' . $auto, $ids, true) && in_array('M' . $ask, $ids, true), 'automatic replies are left out');
    check(str_contains((string)($sent[0]['to'] ?? ''), $us), 'who each email went to is sent', json_encode($sent[0], JSON_UNESCAPED_UNICODE));

    echo "\n=== choose the email: the AI decides ===\n";
    $payloads = [];
    // The AI says more than it was asked: only the chosen email is taken.
    $answer = ['documents' => [['source' => 'M' . $agree, 'stage' => 'po', 'side' => 'sell', 'summary' => 'Αποδοχή'], ['source' => 'M' . $offer, 'stage' => 'offer', 'side' => 'sell', 'summary' => 'Προσφορά εκπαίδευσης']]];
    $r = ecw_deal_doc_read($org, $case, $offer, $u, '', null, true);
    $all = $docs();
    check($r['stage'] === 'offer' && $r['by'] === 'ai' && $r['doc_id'] > 0, 'the chosen email is read by the AI', json_encode($r));
    check(str_contains((string)$payloads[0]['system'], 'chose email M' . $offer), 'the AI is told which one email to read');
    check(($d = $find($all, $offer, 'offer')) && (int)$d['locked'] === 1, 'what it found is kept as the person\'s');
    check($find($all, $agree, 'po') === null, 'the AI\'s other readings are not taken');
    $r = ecw_deal_doc_read($org, $case, $offer, $u, '', null, true);
    check($r['existed'] === true && $r['stage'] === 'offer', 'choosing an email already in the deal says so (nothing new appears)', json_encode($r));

    echo "\n=== the stage chosen by the person ===\n";
    $r = ecw_deal_doc_read($org, $case, $agree, $u, 'po', null, true);
    check($r['by'] === 'user' && ($d = $find($docs(), $agree, 'po')) && $d['side'] === 'sell' && (int)$d['locked'] === 1, 'an acceptance placed as PO by the person: selling, kept');
    check($r['existed'] === false, 'a document that was not there is said to be new');

    echo "\n=== without the AI, or when it fails ===\n";
    $broken = true;
    $r = ecw_deal_doc_read($org, $case, $order, $u, '', null, true);
    check($r['by'] === 'rules' && $r['stage'] === 'po', 'the AI failing: the rules read it', json_encode($r));
    $broken = false;
    $threw = '';
    try { ecw_deal_doc_read($org, $case, $lunch, $u, '', null, false); } catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
    check(str_contains($threw, 'Choose what it is'), 'an email nothing can tell: the person is asked to say what it is');
    $threw = '';
    try { ecw_deal_doc_read($org, $case, 999999999, $u, 'po'); } catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
    check($threw !== '', 'an email of another case is refused');
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    unset($GLOBALS['ecw_ai_transport'], $GLOBALS['ecw_ai_test_config']);
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
