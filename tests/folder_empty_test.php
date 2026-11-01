<?php declare(strict_types=1);
/**
 * "Empty folder" at Outlook (MicrosoftGraphProvider::deleteMany): 20 deletes per Graph $batch
 * call instead of one call per email; gone (404) counts as done, throttled (429) is tried again,
 * and when the batch call itself fails it falls back to one by one.
 *
 *   php tests/folder_empty_test.php
 *
 * A scripted Graph (tests/support/FakeGraph.php); nothing reaches Microsoft.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
require_once dirname(__DIR__) . '/lib/mailbox.php';
require_once __DIR__ . '/support/FakeGraph.php';

$pass = 0; $fail = 0;
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }

try {
    $provider = new MicrosoftGraphProvider(ecw_graph_config());
    $ids = array_map(static fn($i) => 'MSG-' . $i, range(1, 45));
    // Every batch answers each request: the first id of a batch is already gone (404), the second throttled.
    $fake = new FakeGraph();
    $answer = static function (int $from, int $n): array {
        $r = [];
        for ($i = 0; $i < $n; $i++) $r[] = ['id' => (string)$i, 'status' => $i === 0 ? 404 : ($i === 1 ? 429 : 204)];
        return ['body' => ['responses' => $r]];
    };
    $fake->on('POST', '#/\$batch$#', $answer(0, 20), $answer(20, 20), $answer(40, 5));
    $fake->install();
    $r = $provider->deleteMany($ids);
    $batches = $fake->callsMatching('#/\$batch$#');
    check(count($batches) === 3 && count($fake->calls) === 3, '45 emails: 3 calls to Outlook, not 45', (string)count($fake->calls));
    check(count($batches[0]['json']['requests']) === 20 && $batches[0]['json']['requests'][2]['method'] === 'DELETE'
        && $batches[0]['json']['requests'][2]['url'] === '/me/messages/MSG-3', 'each call holds 20 deletes of the right emails');
    check(count($r['done']) === 42 && in_array('MSG-1', $r['done'], true), 'deleted, and already gone, count as done');
    check($r['retry'] === ['MSG-2', 'MSG-22', 'MSG-42'] && $r['failed'] === [], 'throttled ones come back to try again', json_encode($r['retry']));
    FakeGraph::uninstall();

    // Junk Email: permanentDelete in the batch.
    $fake = new FakeGraph();
    $fake->on('POST', '#/\$batch$#', ['body' => ['responses' => [['id' => '0', 'status' => 204]]]]);
    $fake->install();
    $r = $provider->deleteMany(['J-1'], true);
    $req = $fake->calls[0]['json']['requests'][0];
    check($req['method'] === 'POST' && str_ends_with($req['url'], '/messages/J-1/permanentDelete') && $r['done'] === ['J-1'], 'Junk Email deletes for good in the batch too');
    FakeGraph::uninstall();

    // The batch call fails: one by one, like before.
    $fake = new FakeGraph();
    $fake->on('POST', '#/\$batch$#', ['status' => 400, 'body' => ['error' => ['code' => 'BadRequest', 'message' => 'no']]]);
    $fake->on('DELETE', '#/me/messages/#', ['status' => 204]);
    $fake->install();
    $r = $provider->deleteMany(['A', 'B']);
    check(count($fake->callsMatching('#/me/messages/(A|B)$#')) === 2 && $r['done'] === ['A', 'B'], 'when the batch call fails, each email is deleted on its own');
    FakeGraph::uninstall();

    // A whole conversation moved (Archive, back to Inbox, Undo): one $batch call, the new ids by old id.
    $fake = new FakeGraph();
    $fake->on('POST', '#/\$batch$#', ['body' => ['responses' => [['id' => '1', 'status' => 201, 'body' => ['id' => 'NEW-B']], ['id' => '0', 'status' => 201, 'body' => ['id' => 'NEW-A']], ['id' => '2', 'status' => 429]]]]);
    $fake->on('POST', '#/messages/C/move$#', ['status' => 201, 'body' => ['id' => 'NEW-C']]);
    $fake->install();
    $r = $provider->moveMany(['A', 'B', 'C'], 'inbox-folder');
    $req = $fake->calls[0]['json']['requests'][0] ?? [];
    check(count($fake->callsMatching('#/\$batch$#')) === 1 && $req['method'] === 'POST' && str_ends_with($req['url'], '/messages/A/move') && $req['body']['destinationId'] === 'inbox-folder', 'moving a conversation: one call to Outlook');
    check($r['moved'] === ['B' => 'NEW-B', 'A' => 'NEW-A', 'C' => 'NEW-C'] && $r['failed'] === [], 'each email gets its new id, a throttled one is moved on its own', json_encode($r));
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    FakeGraph::uninstall();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
