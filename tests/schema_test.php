<?php declare(strict_types=1);
/**
 * Step 01 conformance test — does the applied schema match docs/steps/01-data-model.md?
 *
 *   php tests/schema_test.php
 *
 * Part one checks shape: every table, every column the step listed, every
 * index the step listed. Part two checks behaviour, because a column list
 * proves nothing about whether the model actually holds up: it writes a real
 * organization -> mailbox -> folder -> thread -> message -> case -> case_thread
 * graph, asserts the invariants, and rolls back so the dev database is left
 * exactly as it was found.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/db.php';

$pass = 0;
$fail = 0;

function ok(string $what): void   { global $pass; $pass++; echo "  ok    {$what}\n"; }
function bad(string $what): void  { global $fail; $fail++; echo "  FAIL  {$what}\n"; }
function check(bool $cond, string $what): void { $cond ? ok($what) : bad($what); }

$pdo = ecw_db();
$db  = $pdo->query('SELECT DATABASE()')->fetchColumn();

// Counted BEFORE the fixture transaction opens. This used to assert zero, which
// was only ever true on an empty database — the moment a real mailbox was
// connected the test started failing for no reason of its own.
$organizationsBefore = (int)$pdo->query('SELECT COUNT(*) FROM `' . ecw_table('organizations') . '`')->fetchColumn();

/** column name => true, for one table */
function columnsOf(PDO $pdo, string $db, string $table): array
{
    $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $st->execute([$db, $table]);
    return array_flip($st->fetchAll(PDO::FETCH_COLUMN));
}

/** index name => [columns in order], for one table */
function indexesOf(PDO $pdo, string $db, string $table): array
{
    $st = $pdo->prepare(
        'SELECT INDEX_NAME, COLUMN_NAME, EXPRESSION, NON_UNIQUE FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX'
    );
    $st->execute([$db, $table]);
    $out = [];
    foreach ($st as $r) {
        $out[$r['INDEX_NAME']]['cols'][]  = $r['COLUMN_NAME'] ?? ('(' . $r['EXPRESSION'] . ')');
        $out[$r['INDEX_NAME']]['unique'] = ((int)$r['NON_UNIQUE'] === 0);
    }
    return $out;
}

/** does any index start with these columns, in order? */
function hasIndexPrefix(array $indexes, array $cols): bool
{
    foreach ($indexes as $ix) {
        if (array_slice($ix['cols'], 0, count($cols)) === $cols) {
            return true;
        }
    }
    return false;
}

echo "\n=== 1. tables ===\n";
foreach (ECW_T as $specName => $physical) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $st->execute([$db, $physical]);
    check((int)$st->fetchColumn() === 1, "{$specName} -> {$physical}");
}

echo "\n=== 2. columns (exactly as the step lists them) ===\n";
$specColumns = [
    'organizations'      => ['id','name','timezone','created_at','updated_at'],
    'users'              => ['id','name','email','password_hash','created_at','updated_at'],
    'organization_users' => ['id','organization_id','user_id','role','created_at'],
    'mailboxes'          => ['id','organization_id','connected_by_user_id','provider','provider_account_id','email_address','display_name','access_token_encrypted','refresh_token_encrypted','token_expires_at','sync_status','last_sync_at','created_at','updated_at'],
    'mail_folders'       => ['id','mailbox_id','provider_folder_id','parent_provider_folder_id','name','folder_type','is_system','is_selected_for_sync','delta_link_encrypted','last_sync_at','created_at','updated_at'],
    'email_addresses'    => ['id','organization_id','email','display_name','domain','created_at','updated_at'],
    'email_threads'      => ['id','mailbox_id','provider_conversation_id','normalized_subject','latest_message_at','message_count','created_at','updated_at'],
    'email_messages'     => ['id','mailbox_id','email_thread_id','mail_folder_id','provider_message_id','internet_message_id','in_reply_to_message_id','references_json','subject','from_email','from_name','to_json','cc_json','bcc_json','body_html','body_text','quoted_html','quoted_text','direction','is_read','has_attachments','sent_at','received_at','provider_created_at','provider_modified_at','created_at','updated_at'],
    'email_attachments'  => ['id','email_message_id','provider_attachment_id','filename','mime_type','size_bytes','is_inline','content_id','local_path','provider_download_state','created_at'],
    'cases'              => ['id','organization_id','case_number','name','status','owner_user_id','created_by_user_id','created_at','updated_at','closed_at'],
    'case_threads'       => ['id','case_id','email_thread_id','display_name','sort_order','linked_by_user_id','linked_at','created_at','updated_at'],
    'case_events'        => ['id','case_id','user_id','event_type','entity_type','entity_id','metadata_json','occurred_at','created_at'],
    'mailbox_sync_state' => ['id','mailbox_id','mail_folder_id','sync_type','cursor_encrypted','last_success_at','last_error','retry_count','updated_at'],
];
foreach ($specColumns as $specName => $cols) {
    $have    = columnsOf($pdo, $db, ecw_table($specName));
    $missing = array_values(array_filter($cols, static fn($c) => !isset($have[$c])));
    check($missing === [], "{$specName}: " . count($cols) . ' spec columns' . ($missing ? ' — MISSING ' . implode(', ', $missing) : ''));
}

echo "\n=== 3. indexes the step asks for ===\n";
$msgIx = indexesOf($pdo, $db, ecw_table('email_messages'));
foreach ([
    ['mailbox_id', 'received_at'],
    ['mailbox_id', 'sent_at'],
    ['email_thread_id', 'received_at'],
    ['internet_message_id'],
    ['from_email'],
    ['subject'],
] as $cols) {
    check(hasIndexPrefix($msgIx, $cols), 'email_messages (' . implode(', ', $cols) . ')');
}

echo "\n=== 4. uniqueness invariants ===\n";
$addrIx = indexesOf($pdo, $db, ecw_table('email_addresses'));
$uniqueAddr = false;
foreach ($addrIx as $ix) {
    if ($ix['unique'] && $ix['cols'] === ['organization_id', 'normalized_email']) { $uniqueAddr = true; }
}
check($uniqueAddr, 'email_addresses UNIQUE (organization_id, normalized_email)');

$ctIx = indexesOf($pdo, $db, ecw_table('case_threads'));
$uniqueThread = false;
foreach ($ctIx as $ix) {
    if ($ix['unique'] && $ix['cols'] === ['email_thread_id']) { $uniqueThread = true; }
}
check($uniqueThread, 'case_threads UNIQUE (email_thread_id) — a thread belongs to one case');

$msgUnique = false;
foreach ($msgIx as $ix) {
    if ($ix['unique'] && $ix['cols'] === ['mailbox_id', 'provider_message_id']) { $msgUnique = true; }
}
check($msgUnique, 'email_messages UNIQUE (mailbox_id, provider_message_id) — the sync upsert key');

$internetUnique = false;
foreach ($msgIx as $ix) {
    if ($ix['unique'] && in_array('internet_message_id', $ix['cols'], true)) { $internetUnique = true; }
}
check(!$internetUnique, 'email_messages internet_message_id NOT unique — self-sent mail lands twice in one mailbox');

echo "\n=== 5. the step's Important Database Rule ===\n";
$caseCols = columnsOf($pdo, $db, ecw_table('cases'));
check(!isset($caseCols['customer_thread_id']) && !isset($caseCols['supplier_thread_id']),
    'cases has no per-role thread columns — threads live in case_threads');

echo "\n=== 6. provider ids are case-sensitive ===\n";
// Scoped to string columns: the rule is about provider-issued IDENTIFIERS,
// which are text. A BIGINT foreign key that happens to share a name carries no
// collation at all, and asserting one of it fails for the wrong reason.
$st = $pdo->prepare(
    'SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE "ecw_%"
        AND DATA_TYPE IN ("varchar", "char")
        AND COLUMN_NAME IN ("provider_message_id","internet_message_id","in_reply_to_message_id",
                            "provider_conversation_id","provider_folder_id","parent_provider_folder_id",
                            "provider_account_id","provider_attachment_id")'
);
$st->execute([$db]);
foreach ($st as $r) {
    check($r['COLLATION_NAME'] === 'utf8mb4_bin', "{$r['TABLE_NAME']}.{$r['COLUMN_NAME']} is utf8mb4_bin");
}

echo "\n=== 7. behaviour, on real rows (rolled back) ===\n";
$pdo->beginTransaction();
try {
    $ins = static function (PDO $pdo, string $table, array $row): int {
        $cols = array_keys($row);
        $sql  = 'INSERT INTO `' . ecw_table($table) . '` (`' . implode('`,`', $cols) . '`) VALUES (' .
                implode(',', array_fill(0, count($cols), '?')) . ')';
        $pdo->prepare($sql)->execute(array_values($row));
        return (int)$pdo->lastInsertId();
    };

    $orgId  = $ins($pdo, 'organizations', ['name' => 'Test Org', 'timezone' => 'Europe/Berlin']);
    $userId = $ins($pdo, 'users', ['name' => 'Test User', 'email' => 'test+' . $orgId . '@example.com', 'password_hash' => password_hash('x', PASSWORD_DEFAULT)]);
    $mbId   = $ins($pdo, 'mailboxes', ['organization_id' => $orgId, 'connected_by_user_id' => $userId, 'provider' => 'microsoft', 'provider_account_id' => 'oid-' . $orgId, 'email_address' => 'ops@example.com']);
    $folder = $ins($pdo, 'mail_folders', ['mailbox_id' => $mbId, 'provider_folder_id' => 'AAMkAInbox', 'name' => 'Inbox', 'folder_type' => 'inbox', 'is_system' => 1, 'is_selected_for_sync' => 1]);
    $thread1 = $ins($pdo, 'email_threads', ['mailbox_id' => $mbId, 'provider_conversation_id' => 'conv-A', 'normalized_subject' => 'CMM X/Y axis problem']);
    $thread2 = $ins($pdo, 'email_threads', ['mailbox_id' => $mbId, 'provider_conversation_id' => 'conv-B', 'normalized_subject' => 'CMM X/Y axis problem']);

    // incoming: only received_at. outgoing: only sent_at. One axis must order both.
    $m1 = $ins($pdo, 'email_messages', ['mailbox_id' => $mbId, 'email_thread_id' => $thread1, 'mail_folder_id' => $folder, 'provider_message_id' => 'AAMkMsg1', 'internet_message_id' => '<a@customer.com>', 'direction' => 'incoming', 'from_email' => 'john@customer.com', 'received_at' => '2026-09-17 09:02:00', 'subject' => 'Machine still faulty']);
    $m2 = $ins($pdo, 'email_messages', ['mailbox_id' => $mbId, 'email_thread_id' => $thread2, 'provider_message_id' => 'AAMkMsg2', 'internet_message_id' => '<b@us.com>', 'direction' => 'outgoing', 'from_email' => 'ops@example.com', 'sent_at' => '2026-09-17 09:18:00', 'subject' => 'RE: axis']);

    $occ = $pdo->query('SELECT id, occurred_at FROM `' . ecw_table('email_messages') . '` WHERE id IN (' . $m1 . ',' . $m2 . ') ORDER BY occurred_at')->fetchAll();
    check($occ[0]['occurred_at'] === '2026-09-17 09:02:00' && $occ[1]['occurred_at'] === '2026-09-17 09:18:00',
        'occurred_at merges received_at and sent_at into one sort axis');

    // case_number uniqueness per org
    $caseId = $ins($pdo, 'cases', ['organization_id' => $orgId, 'case_number' => 'CASE-00142', 'name' => 'AXAKALIS — CMM X/Y Axis Problem', 'status' => 'open', 'owner_user_id' => $userId, 'created_by_user_id' => $userId]);
    try {
        $ins($pdo, 'cases', ['organization_id' => $orgId, 'case_number' => 'CASE-00142', 'name' => 'duplicate']);
        bad('duplicate case_number within an org is rejected');
    } catch (PDOException $e) {
        check(str_contains($e->getMessage(), '1062'), 'duplicate case_number within an org is rejected');
    }

    // unlimited columns: two threads, one case
    $ins($pdo, 'case_threads', ['case_id' => $caseId, 'email_thread_id' => $thread1, 'display_name' => 'Customer', 'sort_order' => 0, 'linked_by_user_id' => $userId]);
    $ins($pdo, 'case_threads', ['case_id' => $caseId, 'email_thread_id' => $thread2, 'display_name' => 'Hexagon Italy', 'sort_order' => 1, 'linked_by_user_id' => $userId]);
    $n = (int)$pdo->query('SELECT COUNT(*) FROM `' . ecw_table('case_threads') . '` WHERE case_id = ' . $caseId)->fetchColumn();
    check($n === 2, 'one case holds many thread columns');

    // a thread cannot be linked to a second case
    $case2 = $ins($pdo, 'cases', ['organization_id' => $orgId, 'case_number' => 'CASE-00143', 'name' => 'Other case']);
    try {
        $ins($pdo, 'case_threads', ['case_id' => $case2, 'email_thread_id' => $thread1]);
        bad('a thread cannot be linked to two cases');
    } catch (PDOException $e) {
        check(str_contains($e->getMessage(), '1062'), 'a thread cannot be linked to two cases');
    }

    // mailbox-wide sync rows (NULL folder) are still unique
    $ins($pdo, 'mailbox_sync_state', ['mailbox_id' => $mbId, 'mail_folder_id' => null, 'sync_type' => 'folders']);
    try {
        $ins($pdo, 'mailbox_sync_state', ['mailbox_id' => $mbId, 'mail_folder_id' => null, 'sync_type' => 'folders']);
        bad('a second mailbox-wide sync row (NULL folder) is rejected');
    } catch (PDOException $e) {
        check(str_contains($e->getMessage(), '1062'), 'a second mailbox-wide sync row (NULL folder) is rejected');
    }

    // the case timeline query: all linked threads on one chronological axis
    $timeline = $pdo->query(
        'SELECT ct.display_name, m.occurred_at, m.direction
           FROM `' . ecw_table('case_threads') . '` ct
           JOIN `' . ecw_table('email_messages') . '` m ON m.email_thread_id = ct.email_thread_id
          WHERE ct.case_id = ' . $caseId . ' AND ct.is_hidden = 0
          ORDER BY m.occurred_at ASC'
    )->fetchAll();
    check(count($timeline) === 2 && $timeline[0]['display_name'] === 'Customer' && $timeline[1]['display_name'] === 'Hexagon Italy',
        'case timeline returns every linked thread on one chronological axis');

    // deleting the org must take the whole graph with it
    $pdo->exec('DELETE FROM `' . ecw_table('organizations') . '` WHERE id = ' . $orgId);
    $left = 0;
    foreach (['mailboxes' => 'organization_id', 'mail_folders' => null, 'email_threads' => null, 'email_messages' => null, 'cases' => 'organization_id', 'case_threads' => null] as $t => $col) {
        $sql = $col
            ? 'SELECT COUNT(*) FROM `' . ecw_table($t) . '` WHERE ' . $col . ' = ' . $orgId
            : 'SELECT COUNT(*) FROM `' . ecw_table($t) . '` WHERE ' . ($t === 'case_threads' ? 'case_id' : 'mailbox_id') . ' IN (' . $mbId . ',' . $caseId . ',' . $case2 . ')';
        $left += (int)$pdo->query($sql)->fetchColumn();
    }
    check($left === 0, 'deleting an organization cascades the whole graph away');

} finally {
    $pdo->rollBack();
}

$after = (int)$pdo->query('SELECT COUNT(*) FROM `' . ecw_table('organizations') . '`')->fetchColumn();
check($after === $organizationsBefore, 'rollback left the database exactly as it was found');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
