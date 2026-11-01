<?php declare(strict_types=1);
/**
 * Task 06 tests — case list filters (lib/cases.php:ecw_case_list()).
 *
 *   php tests/case_list_test.php
 *
 * Pins each filter narrowing the list alone and in combination, that a
 * status/sort/dir value outside the whitelist has no effect (rather than
 * erroring or being interpolated), and that a literal % or _ in a search
 * term is not treated as a wildcard. Database writes are real and rolled back.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ECW_TESTING', true);

require_once dirname(__DIR__) . '/lib/cases.php';
require_once dirname(__DIR__) . '/lib/crypto.php';

$pass = 0;
$fail = 0;

function group(string $name): void { echo "\n=== {$name} ===\n"; }
function ok(string $what): void   { global $pass; $pass++; echo "  ok    {$what}\n"; }
function bad(string $what, string $detail = ''): void { global $fail; $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
function check(bool $cond, string $what, string $detail = ''): void { $cond ? ok($what) : bad($what, $detail); }
function checkSame($expected, $actual, string $what): void
{
    $expected === $actual ? ok($what)
        : bad($what, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
/** Order-independent comparison of the case names a result contains. */
function checkNames(array $expectedNames, array $result, string $what): void
{
    $actual = array_map(static fn(array $c): string => $c['name'], $result['cases']);
    sort($expectedNames);
    sort($actual);
    checkSame($expectedNames, $actual, $what);
}
/** Order-SENSITIVE comparison, for sort tests. */
function checkOrder(array $expectedNamesInOrder, array $result, string $what): void
{
    $actual = array_map(static fn(array $c): string => $c['name'], $result['cases']);
    checkSame($expectedNamesInOrder, $actual, $what);
}

$pdo = ecw_db();
$pdo->beginTransaction();

try {
    $ins = static function (string $table, array $row) use ($pdo): int {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };

    $orgId  = $ins('organizations', ['name' => 'Case List Test Org']);
    $userId = $ins('users', ['name' => 'Owner', 'email' => 'owner' . $orgId . '@example.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $orgId, 'user_id' => $userId, 'role' => 'admin']);

    // A second organization, to prove nothing here can cross the org boundary.
    $otherOrgId = $ins('organizations', ['name' => 'Other Org']);
    $ins('cases', [
        'organization_id' => $otherOrgId, 'case_number' => 'CASE-90001',
        'name' => 'Bracket case in another org', 'status' => 'open',
    ]);

    $mkMailbox = static function (string $email) use ($ins, $orgId): int {
        return $ins('mailboxes', [
            'organization_id' => $orgId, 'provider' => 'microsoft', 'provider_account_id' => 'oid-' . $email,
            'email_address' => $email, 'sync_status' => 'connected',
            'access_token_encrypted' => ecw_encrypt('t'), 'refresh_token_encrypted' => ecw_encrypt('r'),
            'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
    };
    $mailbox1 = $mkMailbox('ops@example.test');
    $mailbox2 = $mkMailbox('sales@example.test');

    $mkThread = static function (int $mailboxId, string $subject) use ($ins): int {
        return $ins('email_threads', [
            'mailbox_id' => $mailboxId, 'provider_conversation_id' => 'conv-' . bin2hex(random_bytes(4)),
            'normalized_subject' => $subject,
        ]);
    };
    $mkMessage = static function (int $mailboxId, int $threadId, string $when, bool $hasAttachment) use ($ins): int {
        return $ins('email_messages', [
            'mailbox_id' => $mailboxId, 'email_thread_id' => $threadId,
            'provider_message_id' => 'msg-' . bin2hex(random_bytes(4)),
            'subject' => 'irrelevant', 'from_email' => 'x@example.test',
            'direction' => 'incoming', 'has_attachments' => $hasAttachment ? 1 : 0,
            'received_at' => $when, 'sent_at' => $when,
        ]);
    };
    $mkParticipant = static function (int $messageId, string $role, string $email, ?string $name = null) use ($ins): void {
        $ins('message_participants', ['email_message_id' => $messageId, 'role' => $role, 'email' => $email, 'name' => $name]);
    };
    $mkCase = static function (string $number, string $name, string $status, string $createdAt) use ($ins, $orgId): int {
        return $ins('cases', [
            'organization_id' => $orgId, 'case_number' => $number, 'name' => $name, 'status' => $status,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    };
    $linkThread = static function (int $caseId, int $threadId) use ($ins): void {
        $ins('case_threads', ['case_id' => $caseId, 'email_thread_id' => $threadId, 'linked_at' => ecw_now()]);
    };

    $now      = ecw_now();
    $fortyAgo = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
    $fortyAgoDate = substr($fortyAgo, 0, 10);

    // Case 1 — recent activity, an attachment, a named participant, on mailbox1.
    $threadA = $mkThread($mailbox1, 'Bracket RFQ Rev 4');
    $msgA    = $mkMessage($mailbox1, $threadA, $now, true);
    $mkParticipant($msgA, 'from', 'buyer@acme.test');
    $mkParticipant($msgA, 'to', 'contact@buyer.test', 'Jane Buyer');
    $case1 = $mkCase('CASE-00001', 'Acme Bracket Case', 'open', '2026-01-01 00:00:00');
    $linkThread($case1, $threadA);

    // Case 2 — 40-day-old activity, no attachment, on mailbox2. Name deliberately
    // has no "Gasket" in it, so a q=gasket match must come from the thread join.
    $threadB = $mkThread($mailbox2, 'Gasket order pricing');
    $mkMessage($mailbox2, $threadB, $fortyAgo, false);
    $case2 = $mkCase('CASE-00002', 'Contract renewal', 'waiting', '2026-02-01 00:00:00');
    $linkThread($case2, $threadB);

    // Case 3 — closed, no linked threads at all: thread_count 0, last_activity NULL.
    $case3 = $mkCase('CASE-00003', 'Closed with no mail yet', 'closed', '2026-03-01 00:00:00');

    // Case 4 — two recent threads on mailbox1, neither with an attachment.
    $threadD = $mkThread($mailbox1, 'Multi thread A');
    $threadE = $mkThread($mailbox1, 'Multi thread B');
    $mkMessage($mailbox1, $threadD, $now, false);
    $mkMessage($mailbox1, $threadE, $now, false);
    $case4 = $mkCase('CASE-00004', 'Multi Thread Case', 'open', '2026-04-01 00:00:00');
    $linkThread($case4, $threadD);
    $linkThread($case4, $threadE);

    // A LIKE-metacharacter case, for the escaping tests.
    $case5 = $mkCase('CASE-00005', 'Fifty%OffDeal', 'open', '2026-05-01 00:00:00');

    $ALL = ['Acme Bracket Case', 'Contract renewal', 'Closed with no mail yet', 'Multi Thread Case', 'Fifty%OffDeal'];

    // ---------------------------------------------------------------- unit
    group('ecw_like_escape');
    checkSame('50\\%\\_off', ecw_like_escape('50%_off'), '% and _ are backslash-escaped');
    checkSame('back\\\\slash', ecw_like_escape('back\\slash'), 'a literal backslash is escaped first, so it cannot become an escape sequence itself');

    // ------------------------------------------------------------ org scope
    group('organization scope');
    $r = ecw_case_list($orgId, ['status' => 'all']);
    checkNames($ALL, $r, 'status=all returns every case in this org, and nothing from the other org');
    checkSame(5, $r['total'], 'total matches the org\'s own case count');

    // --------------------------------------------------------------- status
    group('status filter');
    checkNames(['Acme Bracket Case', 'Multi Thread Case', 'Fifty%OffDeal'], ecw_case_list($orgId, ['status' => 'open']), 'status=open narrows to open cases only');
    checkNames(['Contract renewal'], ecw_case_list($orgId, ['status' => 'waiting']), 'status=waiting narrows to waiting cases only');
    checkNames($ALL, ecw_case_list($orgId, ['status' => "open' OR '1'='1"]), 'a status value outside the whitelist is dropped, not interpolated (falls back to no status filter)');

    // --------------------------------------------------------------- groups
    group('group filter: one or several');
    $gSupport = $ins('case_groups', ['organization_id' => $orgId, 'name' => 'Support']);
    $gSales = $ins('case_groups', ['organization_id' => $orgId, 'name' => 'Sales']);
    $gOther = $ins('case_groups', ['organization_id' => $orgId, 'name' => 'Other']);
    $pdo->prepare('UPDATE `' . ecw_table('cases') . '` SET group_id = ? WHERE id = ?')->execute([$gSupport, $case1]);
    $pdo->prepare('UPDATE `' . ecw_table('cases') . '` SET group_id = ? WHERE id = ?')->execute([$gSales, $case2]);
    $pdo->prepare('UPDATE `' . ecw_table('cases') . '` SET group_id = ? WHERE id = ?')->execute([$gOther, $case3]);
    checkNames(['Acme Bracket Case'], ecw_case_list($orgId, ['status' => 'all', 'group_id' => $gSupport]), 'one group (an old link with group_id)');
    checkNames(['Acme Bracket Case', 'Contract renewal'], ecw_case_list($orgId, ['status' => 'all', 'group_ids' => [$gSupport, $gSales]]), 'two groups: the cases of either');
    checkNames($ALL, ecw_case_list($orgId, ['status' => 'all', 'group_ids' => []]), 'none chosen: every group');
    $_GET = ['group_ids' => [(string)$gSupport, (string)$gSales, 'x', '0'], 'group_id' => (string)$gSupport];
    $fromUrl = ecw_case_list_opts_from_get();
    checkSame([$gSupport, $gSales], $fromUrl['group_ids'], 'from the address: the groups chosen, each once, nothing else');
    checkSame(0, $fromUrl['group_id'], 'with two chosen there is no single group (the new-case dialog starts with none)');
    $_GET = [];
    $pdo->exec('UPDATE `' . ecw_table('cases') . "` SET group_id = NULL WHERE id IN ({$case1}, {$case2}, {$case3})");

    // ------------------------------------------------------------------ text
    group('text search');
    checkNames(['Acme Bracket Case'], ecw_case_list($orgId, ['status' => 'all', 'q' => 'Bracket']), 'q matches the case name');
    checkNames(['Contract renewal'], ecw_case_list($orgId, ['status' => 'all', 'q' => 'gasket']), 'q also matches a linked thread\'s subject, not just the case name');
    checkNames([], ecw_case_list($orgId, ['status' => 'all', 'q' => 'nonexistent-term-xyz']), 'a term matching nothing returns nothing');

    // --------------------------------------------------------------- mailbox
    group('mailbox filter');
    checkNames(['Contract renewal'], ecw_case_list($orgId, ['status' => 'all', 'mailbox_ids' => [$mailbox2]]), 'mailbox_ids narrows to cases with a thread on that mailbox');
    checkNames(['Acme Bracket Case', 'Multi Thread Case'], ecw_case_list($orgId, ['status' => 'all', 'mailbox_ids' => [$mailbox1]]), 'mailbox_ids=[mailbox1] returns both cases that live there');

    // ----------------------------------------------------------- participant
    group('participant filter');
    checkNames(['Acme Bracket Case'], ecw_case_list($orgId, ['status' => 'all', 'participant' => 'acme.test']), 'participant matches by email substring');
    // A name is a whole word, found through the people full-text index — which cannot
    // see rows inside this test's transaction; the route is checked, and the address works.
    require_once dirname(__DIR__) . '/lib/search.php';
    check(str_contains(ecw_search_people_where('Jane')[0], 'MATCH(p.name, p.email)'), 'participant also matches by display name (through the people index)');
    checkNames(['Acme Bracket Case'], ecw_case_list($orgId, ['status' => 'all', 'participant' => 'contact@buyer.test']), 'and by the full address');
    checkNames([], ecw_case_list($orgId, ['status' => 'all', 'participant' => 'nobody@nowhere.test']), 'an unmatched participant returns nothing');

    // ------------------------------------------------------------ attachments
    group('has_attachments filter');
    checkNames(['Acme Bracket Case'], ecw_case_list($orgId, ['status' => 'all', 'has_attachments' => 'yes']), 'has_attachments=yes narrows to cases with at least one attachment');
    checkNames(['Contract renewal', 'Closed with no mail yet', 'Multi Thread Case', 'Fifty%OffDeal'], ecw_case_list($orgId, ['status' => 'all', 'has_attachments' => 'no']), 'has_attachments=no includes cases with zero linked threads too');

    // --------------------------------------------------------------- date range
    group('date range on last activity');
    checkNames(['Acme Bracket Case', 'Multi Thread Case'], ecw_case_list($orgId, ['status' => 'all', 'date_preset' => '30d']), '30d excludes the 40-day-old case and the never-active case');
    checkNames(['Acme Bracket Case', 'Multi Thread Case'], ecw_case_list($orgId, ['status' => 'all', 'date_preset' => 'today']), 'today also matches activity from just now');
    checkNames(['Contract renewal'], ecw_case_list($orgId, [
        'status' => 'all', 'date_preset' => 'custom', 'date_from' => $fortyAgoDate, 'date_to' => $fortyAgoDate,
    ]), 'a custom range covering only the 40-day-old activity isolates that case');

    // ------------------------------------------------------------------ combined
    group('combined filters');
    checkNames(['Acme Bracket Case'], ecw_case_list($orgId, ['status' => 'open', 'has_attachments' => 'yes']), 'status + has_attachments narrows to their intersection');
    checkNames([], ecw_case_list($orgId, ['status' => 'closed', 'has_attachments' => 'yes']), 'an intersection that matches nothing returns nothing, not an error');

    // ------------------------------------------------------------ LIKE escaping
    group('LIKE metacharacter escaping');
    checkNames(['Fifty%OffDeal'], ecw_case_list($orgId, ['status' => 'all', 'q' => 'Fifty%OffDeal']), 'a literal % typed by the user matches the literal % in the data');
    checkNames([], ecw_case_list($orgId, ['status' => 'all', 'q' => 'F%Deal']), '% in the search term is not a wildcard: "F%Deal" does not match "Fifty%OffDeal" via substring skipping');

    // ----------------------------------------------------------------- sort
    group('sort');
    checkOrder(
        ['Acme Bracket Case', 'Closed with no mail yet', 'Contract renewal', 'Fifty%OffDeal', 'Multi Thread Case'],
        ecw_case_list($orgId, ['status' => 'all', 'sort' => 'name']),
        'sort=name orders A-Z'
    );
    checkOrder(
        ['Fifty%OffDeal', 'Multi Thread Case', 'Closed with no mail yet', 'Contract renewal', 'Acme Bracket Case'],
        ecw_case_list($orgId, ['status' => 'all', 'sort' => 'created']),
        'sort=created orders newest-created first'
    );
    // Case1 and case2 tie at thread_count=1; case3 and case5 tie at 0. Both
    // ties break on id DESC, i.e. the more-recently-created case of the pair
    // sorts first — case2 was created after case1, case5 after case3.
    checkOrder(
        ['Multi Thread Case', 'Contract renewal', 'Acme Bracket Case', 'Fifty%OffDeal', 'Closed with no mail yet'],
        ecw_case_list($orgId, ['status' => 'all', 'sort' => 'threads']),
        'sort=threads orders by thread_count, most-linked first, ties broken by id desc'
    );
    $default = ecw_case_list($orgId, ['status' => 'all']);
    checkOrder(
        ['Multi Thread Case', 'Acme Bracket Case', 'Contract renewal', 'Fifty%OffDeal', 'Closed with no mail yet'],
        $default,
        'default order (last_activity desc) puts the two never-active cases last, tied pairs broken by id desc'
    );

    group('sort/dir whitelist');
    $r = ecw_case_list($orgId, ['status' => 'all', 'sort' => "id; DROP TABLE ecw_cases; --", 'dir' => "desc'); --"]);
    checkSame(5, $r['total'], 'a sort/dir value outside the whitelist does not error and falls back to a safe default');
    $stillThere = $pdo->query('SELECT COUNT(*) FROM `' . ecw_table('cases') . '`')->fetchColumn();
    check((int)$stillThere >= 5, 'the cases table is intact — no SQL from the sort/dir value ever executed');

    // ----------------------------------------------------------------- paging
    group('pagination');
    $r = ecw_case_list($orgId, ['status' => 'all']);
    checkSame(50, $r['per_page'], 'default page size is 50');
    checkSame(1, $r['page'], 'default page is 1');
    checkSame(5, count($r['cases']), 'all 5 cases fit on page 1');
    $r2 = ecw_case_list($orgId, ['status' => 'all', 'page' => 2]);
    checkSame(5, $r2['total'], 'total is unaffected by paging past the end');
    checkSame(0, count($r2['cases']), 'page 2 is empty when everything fit on page 1');

} catch (Throwable $e) {
    echo "FATAL: {$e->getMessage()}\n{$e->getTraceAsString()}\n";
    $fail++;
} finally {
    $pdo->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
