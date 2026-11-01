<?php declare(strict_types=1);
/**
 * Case groups sort incoming email (lib/group_rules.php), and the assistant's cards suggest a
 * case only when it is clear (lib/agents.php ecw_agent_triage_one): an existing case only for
 * 2+ outside people in common or the AI being sure, a new case only for the first email of a
 * conversation or a group's rules.
 *
 *   php tests/group_rules_test.php
 *
 * Runs in a transaction that is rolled back. No real mailbox, no AI: a fake AI answers.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/group_rules.php';
require_once dirname(__DIR__) . '/lib/ingest.php';
require_once dirname(__DIR__) . '/lib/agents.php';
require_once dirname(__DIR__) . '/lib/access.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Group rules test']);
    $ann = $ins('users', ['name' => 'Ann', 'email' => 'ann' . $org . '@rules.test', 'password_hash' => 'x']);
    $bob = $ins('users', ['name' => 'Bob', 'email' => 'bob' . $org . '@rules.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $ann, 'role' => 'admin']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $bob, 'role' => 'user']);
    $mbEmail = 'service' . $org . '@rules.test';
    $mb = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 'r' . $org, 'email_address' => $mbEmail, 'sync_status' => 'connected', 'connected_by_user_id' => $ann]);
    $mb2 = $ins('mailboxes', ['organization_id' => $org, 'provider' => 'microsoft', 'provider_account_id' => 's' . $org, 'email_address' => 'sales' . $org . '@rules.test', 'sync_status' => 'connected', 'connected_by_user_id' => $ann]);
    $mailbox = $pdo->query('SELECT * FROM `' . ecw_table('mailboxes') . '` WHERE id = ' . $mb)->fetch();
    $ingest = static function (string $subject, string $from, ?string $conv = null, array $cc = []) use ($mb, $mbEmail): array {
        $when = gmdate('Y-m-d H:i:s');
        return ecw_ingest_message($mb, null, 'inbox', [
            'provider_message_id' => 'p' . bin2hex(random_bytes(6)), 'internet_message_id' => '<' . uniqid() . '@x>',
            'provider_conversation_id' => $conv ?? 'c' . uniqid(), 'subject' => $subject,
            'from_email' => $from, 'from_name' => 'Customer', 'to' => [['email' => $mbEmail, 'name' => 'Service']], 'cc' => $cc, 'bcc' => [],
            'received_at' => $when, 'sent_at' => $when, 'is_read' => false, 'has_attachments' => false, 'references' => [],
        ]);
    };

    echo "\n=== the rules ===\n";
    $bad = false; try { ecw_group_rules_clean($org, ['action' => 'auto']); } catch (InvalidArgumentException $e) { $bad = true; }
    check($bad, 'switched on with nothing to match: refused');
    $bad = false; try { ecw_group_rules_clean($org, ['ai' => str_repeat('x', 1600), 'action' => 'suggest']); } catch (InvalidArgumentException $e) { $bad = true; }
    check($bad, 'a description for the AI over 1500 characters is refused');
    $c = ecw_group_rules_clean($org, ['ai' => "  Calibration   requests  ", 'words' => "calibration\ncalibration\n\n", 'mailbox_id' => 999999999, 'action' => 'suggest']);
    check($c['ai'] === 'Calibration requests' && $c['words'] === 'calibration' && $c['mailbox_id'] === null, 'cleaned: one per line, no repeats, an account of another workspace dropped', json_encode($c));
    $w = ['words' => "βαθμονόμ\nRMA", 'mailbox_id' => null];
    check(ecw_group_rule_matches($w, $mb, 'ΑΙΤΗΜΑ ΒΑΘΜΟΝΟΜΗΣΗΣ', '') !== null && ecw_group_rule_matches($w, $mb, 'Hello', 'please open an rma-55') !== null,
        'words: capitals and Greek accents do not matter, the start of a word is enough, the text counts too');
    check(ecw_group_rule_matches($w, $mb, 'More information', 'see the form') === null, 'a word is not found inside another word (“rma” in “information”)');
    $inBox = ['words' => 'calibration', 'mailbox_id' => $mb];
    check(ecw_group_rule_matches($inBox, $mb, 'Calibration due', '') !== null && ecw_group_rule_matches($inBox, $mb2, 'Calibration due', '') === null, 'with an account chosen, only emails arriving there');
    check(ecw_group_rule_matches(['words' => '', 'ai' => 'anything', 'mailbox_id' => null], $mb, 'anything', '') === null, 'a group with only a description is left to the AI');

    echo "\n=== words: no AI ===\n";
    $cal = ecw_case_group_save($org, $ann, true, ['name' => 'Calibration', 'scope' => 'company', 'rules' => ['words' => "calibration\nβαθμονόμ", 'action' => 'auto']]);
    $sales = ecw_case_group_save($org, $ann, true, ['name' => 'Sales', 'scope' => 'company', 'rules' => ['words' => 'quotation', 'action' => 'suggest']]);
    $mine = ecw_case_group_save($org, $bob, false, ['name' => 'Bob only', 'scope' => 'personal', 'rules' => ['words' => 'secretword', 'action' => 'auto']]);
    $gs = array_column(ecw_case_groups($org, $ann), null, 'id');
    check($gs[$cal]['rules']['action'] === 'auto' && $gs[$sales]['rules']['words'] === 'quotation', 'the rules are saved with the group');
    ecw_case_group_save($org, $ann, true, ['id' => $sales, 'name' => 'Sales', 'scope' => 'company']);
    check(array_column(ecw_case_groups($org, $ann), null, 'id')[$sales]['rules']['words'] === 'quotation', 'saving a group without its rules keeps them');

    $a = $ingest('Βαθμονόμηση ζυγού', 'lab@somewhere' . $org . '.test');
    $case = ecw_case_for_thread((int)$a['thread_id']);
    $row = $case ? $pdo->query('SELECT group_id, owner_user_id, mailbox_id, name FROM `' . ecw_table('cases') . '` WHERE id = ' . (int)$case['id'])->fetch() : null;
    check($row && (int)$row['group_id'] === $cal && (int)$row['owner_user_id'] === $ann && (int)$row['mailbox_id'] === $mb && $row['name'] === 'Βαθμονόμηση ζυγού',
        '"start the case by itself" on a word: a case in the group, the account\'s owner Responsible, named after the subject', json_encode($row));
    $cards = array_values(array_filter(ecw_suggestions_for($org, $ann), static fn($s) => $s['kind'] === 'group'));
    check(count($cards) === 1 && str_contains($cards[0]['title'], 'Calibration') && array_column($cards[0]['actions'], 'type') === ['open_url', 'undo_case'], 'and a card tells them, with the case to open and Undo');

    $b = $ingest('Quotation for 20 units', 'joe@buyer' . $org . '.test');
    check(ecw_case_for_thread((int)$b['thread_id']) === null, '"suggest": nothing happens by itself');
    $sc = array_values(array_filter(ecw_suggestions_for($org, $ann, (int)$b['thread_id']), static fn($s) => $s['kind'] === 'group'));
    check(count($sc) === 1 && str_starts_with((string)$sc[0]['actions'][0]['label'], 'Start a case in Sales') && str_contains((string)$sc[0]['actions'][0]['preview'], 'in the group'), 'a card offers "Start a case in Sales"', json_encode($sc));
    ecw_suggestion_act($org, $ann, $sc[0]['id'], 0);
    $bc = ecw_case_for_thread((int)$b['thread_id']);
    check($bc !== null && (int)$pdo->query('SELECT group_id FROM `' . ecw_table('cases') . '` WHERE id = ' . (int)$bc['id'])->fetchColumn() === $sales, 'accepting it starts the case in the group');
    check(ecw_group_rules_learned($sales)['yes'] === 1, 'and the group learns: that email was for it');

    $p = $ingest('About the secretword', 'x@somewhere' . $org . '.test');
    check(ecw_case_for_thread((int)$p['thread_id']) === null, 'someone else\'s personal group never takes the account owner\'s email');

    echo "\n=== the assistant: clear suggestions only ===\n";
    $GLOBALS['ecw_ai_test_config'] = ['provider' => 'openai', 'model' => 'm', 'base_url' => 'http://ollama:11434/v1', 'key' => '', 'configured' => true, 'no_think' => true, 'daily_requests' => 100];
    $aiReply = '';
    $aiCalls = [];
    $GLOBALS['ecw_ai_http_transport'] = static function (string $path, array $body) use (&$aiReply, &$aiCalls) { $aiCalls[] = json_encode($body, JSON_UNESCAPED_UNICODE); return ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $aiReply]]]]; };
    $ag = ecw_agents_settings_save($org, ['on' => ['triage' => 1]]);
    $triage = static function (array $msg, string $type, array $extra = [], bool $full = false) use ($pdo, $org, $ag, &$aiReply): array {
        $pdo->prepare("UPDATE `" . ecw_table('email_messages') . "` SET body_text = 'Hello, see below.', body_fetch_state = 'fetched' WHERE id = ?")->execute([$msg['message_id']]);
        $aiReply = json_encode(['type' => $type, 'urgency' => 'normal', 'needs_reply' => false, 'reply_by' => null, 'summary' => 'They write.', 'facts' => [], 'new_case_name' => 'New matter'] + $extra);
        $row = $pdo->query('SELECT m.*, mb.email_address AS mailbox_address FROM `' . ecw_table('email_messages') . '` m JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id WHERE m.id = ' . (int)$msg['message_id'])->fetch();
        ecw_agent_triage_one($org, $ag, ecw_ai_config($org), $row);
        $s = array_values(array_filter(ecw_suggestions_for($org, $row ? (int)ecw_agent_owner_of_mailbox((int)$row['mailbox_id']) : 0, (int)$msg['thread_id']), static fn($x) => $x['kind'] === 'triage'));
        if ($full) return $s ? $s[0] : [];
        return $s ? array_column($s[0]['actions'], 'type') : [];
    };
    // An open case with one outside person (and a colleague) in it.
    $old = $ingest('Pump order', 'maria@customer' . $org . '.test', null, [['email' => 'bob' . $org . '@rules.test', 'name' => 'Bob']]);
    $oc = ecw_case_create($org, 'Pump order', $ann, 'open', $mb);
    ecw_case_link_thread($oc, (int)$old['thread_id'], null, $ann);
    // A new email from the same person, colleague in copy too, about something else: no "Add to".
    $n1 = $ingest('Different question', 'maria@customer' . $org . '.test', null, [['email' => 'bob' . $org . '@rules.test', 'name' => 'Bob']]);
    $aiReply = '';
    check(!in_array('link_case', $triage($n1, 'question'), true), 'one person in common (plus a colleague) is not enough to suggest their case');
    // The first email of a new conversation, a quote request: "Start a case".
    $n2 = $ingest('Quote for valves', 'newco@other' . $org . '.test');
    check(in_array('new_case', $triage($n2, 'quote_request'), true), 'a brand-new quote request: a card with "Start a case"');
    // A reply in an old conversation, not in a case: no "Start a case" (and no card for it alone).
    $conv = 'conv' . uniqid();
    $ingest('Valves', 'newco2@other' . $org . '.test', $conv);
    $n3 = $ingest('RE: Valves', 'newco2@other' . $org . '.test', $conv);
    check(!in_array('new_case', $triage($n3, 'quote_request'), true), 'a reply inside an older conversation does not offer a new case');
    // Two outside people in common, in one recent case: "Add to" it.
    $c2 = [['email' => 'peter@customer' . $org . '.test', 'name' => 'Peter']];
    $o2 = $ingest('Gearbox', 'maria@customer' . $org . '.test', null, $c2);
    $gc = ecw_case_create($org, 'Gearbox', $ann, 'open', $mb);
    ecw_case_link_thread($gc, (int)$o2['thread_id'], null, $ann);
    $n4 = $ingest('Next step', 'maria@customer' . $org . '.test', null, $c2);
    $t4 = $triage($n4, 'question');
    check(in_array('link_case', $t4, true) || in_array('unlink', $t4, true), 'two outside people in common: their case is suggested (or filed, with undo)', json_encode($t4));

    echo "\n=== the AI reads the groups' descriptions ===\n";
    $svc = ecw_case_group_save($org, $ann, true, ['name' => 'Service', 'scope' => 'company', 'rules' => ['ai' => 'A customer reports a broken device and wants it repaired. Not: sales offers.', 'action' => 'suggest']]);
    $ret = ecw_case_group_save($org, $ann, true, ['name' => 'Returns', 'scope' => 'company', 'rules' => ['ai' => 'A customer wants to send goods back for a refund.', 'action' => 'auto']]);
    $e1 = $ingest('Our pump stopped', 'plant@factory' . $org . '.test');
    $aiCalls = [];
    $card = $triage($e1, 'complaint', ['group' => 'G' . $svc, 'group_confidence' => 80], true);
    $labels = array_column($card['actions'] ?? [], 'label');
    check((bool)array_filter($labels, static fn($l) => str_starts_with((string)$l, 'Start a case in Service')), 'the AI says "Service" (80% sure): a card offers to start the case there', json_encode($labels));
    check(str_contains($aiCalls[0] ?? '', 'broken device') && str_contains($aiCalls[0] ?? '', 'G' . $ret . '\\"'), 'the AI was given the groups\' descriptions, in the same request as the rest of the reading');
    check(ecw_case_for_thread((int)$e1['thread_id']) === null, 'a "suggest" group never starts a case by itself');
    ecw_suggestion_dismiss($org, $ann, (int)$card['id']);
    check(ecw_group_rules_learned($svc)['no'] === 1, 'dismissing that card teaches the group: not for it');

    $e2 = $ingest('Price list please', 'buyer2@factory' . $org . '.test');
    $card2 = $triage($e2, 'question', ['group' => 'G' . $svc, 'group_confidence' => 50], true);
    check(!array_filter(array_column($card2['actions'] ?? [], 'label'), static fn($l) => str_contains((string)$l, 'Service')), 'not sure enough (50%): no group suggestion');

    $e3 = $ingest('Sending the boxes back', 'shop@retail' . $org . '.test');
    $triage($e3, 'question', ['group' => 'G' . $ret, 'group_confidence' => 92]);
    $c3 = ecw_case_for_thread((int)$e3['thread_id']);
    check($c3 !== null && (int)$pdo->query('SELECT group_id FROM `' . ecw_table('cases') . '` WHERE id = ' . (int)$c3['id'])->fetchColumn() === $ret, 'very sure (92%) and the group starts cases by itself: the case is started in Returns');
    $gcard = array_values(array_filter(ecw_suggestions_for($org, $ann, (int)$e3['thread_id']), static fn($s) => $s['kind'] === 'group'))[0] ?? null;
    $undo = $gcard ? array_search('undo_case', array_column($gcard['actions'], 'type'), true) : false;
    check($undo !== false, 'its card has Undo');
    ecw_suggestion_act($org, $ann, (int)$gcard['id'], (int)$undo);
    check(ecw_case_for_thread((int)$e3['thread_id']) === null && $pdo->query('SELECT deleted_at FROM `' . ecw_table('cases') . '` WHERE id = ' . (int)$c3['id'])->fetchColumn() !== null, 'Undo removes the case and frees the email');
    $l = ecw_group_rules_learned($ret);
    check($l['no'] === 1 && str_contains($l['not_for'][0] ?? '', 'Sending the boxes back'), 'and the group remembers that email was not for it');
    $e4 = $ingest('Refund for order 77', 'shop2@retail' . $org . '.test');
    $aiCalls = [];
    $triage($e4, 'question', ['group' => null]);
    check(str_contains($aiCalls[0] ?? '', 'Sending the boxes back'), 'the next time, the AI is told which emails were not for the group');
    $e5 = $ingest('Βαθμονόμηση θερμομέτρου', 'lab2@somewhere' . $org . '.test');
    $aiCalls = [];
    $triage($e5, 'question');
    check(!str_contains($aiCalls[0] ?? '', 'case_groups'), 'an email a group\'s words already filed is not asked about again');
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
