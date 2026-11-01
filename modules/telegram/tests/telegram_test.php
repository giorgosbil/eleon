<?php declare(strict_types=1);
/**
 * Telegram (lib/telegram.php, lib/sync.php): connecting with a phone number,
 * the code and the 2-step password; the first sync; messages both ways, files,
 * edits, read ticks; new chats; privacy between people; case links.
 *
 *   php modules/telegram/tests/telegram_test.php
 *
 * Telegram is a scripted EcwTgClient (no network). Runs in a transaction that
 * is rolled back; files go to a temporary storage/telegram folder.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ECW_TESTING', true);
putenv('ECW_TELEGRAM_API_ID');
putenv('ECW_TELEGRAM_API_HASH');
require_once dirname(__DIR__) . '/lib/sync.php';
require_once dirname(__DIR__, 3) . '/lib/features.php';

$pass = 0; $fail = 0;
function group(string $n): void { echo "\n=== {$n} ===\n"; }
function check(bool $c, string $w, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok    {$w}\n"; } else { $fail++; echo "  FAIL  {$w}" . ($d !== '' ? " — {$d}" : '') . "\n"; } }
function throws(callable $f, string $class, string $contains, string $what): void
{
    try { $f(); check(false, $what, 'nothing thrown'); }
    catch (Throwable $e) { check($e instanceof $class && str_contains($e->getMessage(), $contains), $what, get_class($e) . ': ' . $e->getMessage()); }
}

/** Telegram, scripted: what it answers, and what it was asked. */
final class FakeTg implements EcwTgClient
{
    public array $calls = [];
    public array $queue = [];
    public ?string $password = null;
    public int $nextId = 900;
    public array $contactsList = [];
    public array $dialogsResult = ['dialogs' => [], 'messages' => [], 'users' => []];
    public array $historyResult = ['messages' => [], 'users' => []];
    public ?Throwable $failNext = null;
    private function call(string $what, ...$args) { $this->calls[] = [$what, ...$args]; if ($this->failNext) { $e = $this->failNext; $this->failNext = null; throw $e; } }
    public function sendCode(string $phone): string { $this->call('sendCode', $phone); return 'in the Telegram app on your phone'; }
    public function signIn(string $code): ?string { $this->call('signIn', $code); if ($code !== '12345') throw new RuntimeException('PHONE_CODE_INVALID'); return $this->password; }
    public function checkPassword(string $password): void { $this->call('checkPassword', $password); if ($password !== $this->password) throw new RuntimeException('PASSWORD_HASH_INVALID'); }
    public function me(): array { return ['id' => 4440001, 'first_name' => 'Giorgos', 'last_name' => 'B', 'username' => 'giorgos', 'phone' => '306900000001']; }
    public function updates(): array { $u = $this->queue; $this->queue = []; return $u; }
    public function user(int $peer): ?array { return ['id' => $peer, 'first_name' => 'Unknown ' . $peer]; }
    public function contacts(): array { return $this->contactsList; }
    public function dialogs(int $limit): array { $this->call('dialogs', $limit); return $this->dialogsResult; }
    public function history(int $peer, int $limit): array { $this->call('history', $peer); return $this->historyResult; }
    public function sendText(int $peer, string $text): int { $this->call('sendText', $peer, $text); return ++$this->nextId; }
    public function sendFile(int $peer, string $path, string $name, string $mime, string $caption, bool $asPhoto): int { $this->call('sendFile', $peer, basename($path), $name, $mime, $caption, $asPhoto); return ++$this->nextId; }
    public function download(array $media, string $path): void { $this->call('download', $media['_']); file_put_contents($path, 'FILE:' . $media['_']); }
    public function read(int $peer, int $maxId): void { $this->call('read', $peer, $maxId); }
    public function importContact(string $phone, string $first, string $last): ?array { $this->call('import', $phone); return $phone === '+306977777777' ? ['id' => 5557777, 'first_name' => $first, 'last_name' => $last, 'phone' => '306977777777'] : null; }
    public function logout(): void { $this->call('logout'); }
}

/** Run every queued job of an account, as the background process does. */
function drain(FakeTg $c, int $accountId): void
{
    while ($job = ecw_tg_sync_next_job($accountId)) {
        $st = ecw_db()->prepare('SELECT * FROM ecw_tg_accounts WHERE id = ?');
        $st->execute([$accountId]);
        ecw_tg_sync_job($c, $st->fetch(), $job);
    }
}
function account(int $id): array { $st = ecw_db()->prepare('SELECT * FROM ecw_tg_accounts WHERE id = ?'); $st->execute([$id]); return $st->fetch(); }

$pdo = ecw_db();
$pdo->beginTransaction();
try {
    $ins = static function (string $t, array $row) use ($pdo): int { $c = array_keys($row); $pdo->prepare('INSERT INTO `' . ecw_table($t) . '` (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); return (int)$pdo->lastInsertId(); };
    $org = $ins('organizations', ['name' => 'Telegram shop']);
    $me = $ins('users', ['name' => 'Giorgos', 'email' => 'g' . $org . '@shop.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $me, 'role' => 'admin']);
    $colleague = $ins('users', ['name' => 'Kostas', 'email' => 'k' . $org . '@shop.test', 'password_hash' => 'x']);
    $ins('organization_users', ['organization_id' => $org, 'user_id' => $colleague, 'role' => 'member']);
    $hash = str_repeat('ab12', 8);
    $tg = new FakeTg;

    group('connecting');
    check(ecw_tg_status($me)['status'] === 'none', 'not connected to begin with');
    throws(fn() => ecw_tg_login_start($org, $me, '6900000001', '123', $hash), InvalidArgumentException::class, 'country code', 'a number without its country code is refused');
    throws(fn() => ecw_tg_login_start($org, $me, '+30 690 000 0001'), InvalidArgumentException::class, 'my.telegram.org', 'without an api_id (here or from the server) it says where to get one');
    throws(fn() => ecw_tg_login_start($org, $me, '+30 690 000 0001', '123', 'nothex'), InvalidArgumentException::class, 'api_hash', 'an api_hash that cannot be one is refused');
    $s = ecw_tg_login_start($org, $me, '+30 690 000 0001', '123456', strtoupper($hash));
    $acc = (int)ecw_tg_account($me)['id'];
    check($s['status'] === 'new' && $s['busy'] && $s['phone'] === '+306900000001', 'the phone number is taken; the code is being asked for');
    check(!str_contains(json_encode(account($acc)), $hash), 'the api_hash is stored encrypted');
    drain($tg, $acc);
    $s = ecw_tg_status($me);
    check($tg->calls[0] === ['sendCode', '+306900000001'] && $s['status'] === 'code' && str_contains($s['hint'], 'Telegram app'), 'Telegram sends the code, and the tab says where it went');
    throws(fn() => ecw_tg_login_code($me, 'abc'), InvalidArgumentException::class, 'code', 'a code needs digits');
    ecw_tg_login_code($me, '11111');
    drain($tg, $acc);
    $s = ecw_tg_status($me);
    check($s['status'] === 'code' && str_contains((string)$s['error'], 'not right'), 'a wrong code: try again, in plain words');
    $tg->password = 'my hint';
    ecw_tg_login_code($me, '12 345');
    drain($tg, $acc);
    check(ecw_tg_status($me)['status'] === 'password' && ecw_tg_status($me)['hint'] === 'my hint', 'with 2-step verification it asks for the password, with its hint');
    $tg->dialogsResult = [
        'dialogs' => [['_' => 'dialog', 'peer' => 5550001, 'top_message' => 70, 'unread_count' => 2, 'read_outbox_max_id' => 69], ['_' => 'dialog', 'peer' => -100123, 'top_message' => 5, 'unread_count' => 9]],
        'messages' => [['_' => 'message', 'id' => 70, 'peer_id' => 5550001, 'out' => false, 'date' => time() - 60, 'message' => 'Is my pump ready?'], ['_' => 'message', 'id' => 5, 'peer_id' => -100123, 'date' => time(), 'message' => 'group chat']],
        'users' => [['_' => 'user', 'id' => 5550001, 'first_name' => 'Maria', 'last_name' => 'P', 'username' => 'maria_p', 'phone' => '306911111111']],
    ];
    $tg->contactsList = [['id' => 5550001, 'first_name' => 'Maria', 'last_name' => 'P', 'username' => 'maria_p', 'phone' => '306911111111'], ['id' => 5550002, 'first_name' => 'Nikos', 'phone' => '306922222222']];
    ecw_tg_login_password($me, 'my hint');
    $raw = (string)$pdo->query("SELECT payload_enc FROM ecw_tg_jobs WHERE account_id = {$acc} AND type = 'password'")->fetchColumn();
    check($raw !== '' && !str_contains($raw, 'my hint'), 'the password waits encrypted');
    drain($tg, $acc);
    $s = ecw_tg_status($me);
    check($s['status'] === 'ready' && $s['name'] === 'Giorgos B' && $s['synced'], 'connected: who they are, and the chats were loaded');
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_tg_jobs WHERE account_id = {$acc} AND payload_enc IS NOT NULL")->fetchColumn() === 0, 'codes and passwords are not kept after use');

    group('the first sync');
    $chats = ecw_tg_chats($acc);
    check(count($chats) === 1 && $chats[0]['title'] === 'Maria P' && $chats[0]['unread'] === 2 && $chats[0]['preview'] === 'Is my pump ready?', 'private chats with their last message and unread count; groups are left out');
    check(count(ecw_tg_contacts($acc)) === 2 && ecw_tg_contacts($acc, 'nik')[0]['name'] === 'Nikos' && count(ecw_tg_contacts($acc, '6922')) === 1, 'the contacts are there, searchable by name and phone');
    $chatId = $chats[0]['id'];

    group('privacy');
    throws(fn() => ecw_tg_messages(ecw_tg_account($me), 999999), InvalidArgumentException::class, 'does not exist', 'a chat id that is not theirs is not found');
    check(ecw_tg_account($colleague) === null && ecw_tg_status($colleague)['status'] === 'none', "a colleague does not see someone else's Telegram");
    $colAcc = $ins('tg_accounts', ['organization_id' => $org, 'user_id' => $colleague, 'status' => 'ready']);
    throws(fn() => ecw_tg_messages(ecw_tg_account($colleague), $chatId), InvalidArgumentException::class, 'does not exist', "…even with their own Telegram connected, they cannot open this person's chat");
    throws(fn() => ecw_tg_media($colAcc, 1), InvalidArgumentException::class, 'does not exist', "…nor its files");

    group('opening a chat');
    $tg->historyResult = ['messages' => [
        ['_' => 'message', 'id' => 70, 'peer_id' => 5550001, 'out' => false, 'date' => time() - 60, 'message' => 'Is my pump ready?'],
        ['_' => 'message', 'id' => 69, 'peer_id' => 5550001, 'out' => true, 'date' => time() - 120, 'message' => 'Hello Maria'],
        ['_' => 'message', 'id' => 68, 'peer_id' => 5550001, 'out' => false, 'date' => time() - 180, 'message' => '', 'media' => ['_' => 'messageMediaPhoto', 'photo' => ['id' => 1]]],
        ['_' => 'messageService', 'id' => 67, 'peer_id' => 5550001, 'date' => time() - 200],
    ], 'users' => []];
    $r = ecw_tg_messages(ecw_tg_account($me), $chatId);
    check($r['chat']['loading'] && count($r['messages']) === 1, 'the first time, the chat shows what is known and asks Telegram for its history');
    $tg->calls = [];
    drain($tg, $acc);
    $r = ecw_tg_messages(ecw_tg_account($me), $chatId);
    check(!$r['chat']['loading'] && count($r['messages']) === 3 && $r['messages'][0]['kind'] === 'image' && $r['messages'][0]['has_media'], 'the history is loaded once, the photo downloaded, service messages left out');
    check($r['messages'][1]['status'] === 'read' && $r['messages'][1]['sender'] === 'In Telegram', 'their read ticks show, and messages written in Telegram itself say so');
    check(in_array(['read', 5550001, 70], $tg->calls, true) && (int)$pdo->query("SELECT unread FROM ecw_tg_chats WHERE id = {$chatId}")->fetchColumn() === 0, 'opening it marks it read here and on the phone');
    $f = ecw_tg_media($acc, $r['messages'][0]['id']);
    check(file_get_contents($f['path']) === 'FILE:messageMediaPhoto' && $f['mime'] === 'image/jpeg', 'the photo is served from this computer');

    group('sending');
    $acct = ecw_tg_account($me);
    throws(fn() => ecw_tg_send($acct, $chatId, $me, '   '), InvalidArgumentException::class, 'Write a message', 'an empty message is not sent');
    $sent = ecw_tg_send($acct, $chatId, $me, "  It is ready.\nCome by tomorrow. ");
    check($pdo->query("SELECT status FROM ecw_tg_messages WHERE id = {$sent['id']}")->fetchColumn() === 'sending', 'a message shows at once, as sending');
    $tg->calls = [];
    drain($tg, $acc);
    check($tg->calls[0] === ['sendText', 5550001, "It is ready.\nCome by tomorrow."], 'the background process sends it to the right person');
    $row = $pdo->query("SELECT * FROM ecw_tg_messages WHERE id = {$sent['id']}")->fetch();
    check($row['status'] === 'sent' && (int)$row['tg_message_id'] === 901 && (int)$row['sent_by_user_id'] === $me, '…then it is sent, with its Telegram id');
    $tg->queue = [['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 901, 'peer_id' => 5550001, 'out' => true, 'date' => time(), 'message' => "It is ready.\nCome by tomorrow."]]];
    foreach ($tg->updates() as $u) ecw_tg_sync_update($tg, $acct, $u);
    check((int)$pdo->query("SELECT COUNT(*) FROM ecw_tg_messages WHERE chat_id = {$chatId} AND tg_message_id = 901")->fetchColumn() === 1, 'Telegram reporting it back does not double it');
    $tmp = tempnam(sys_get_temp_dir(), 'tg');
    file_put_contents($tmp, "%PDF-1.4 offer");
    $doc = ecw_tg_send($acct, $chatId, $me, 'Our offer', ['tmp' => $tmp, 'name' => '../Offer 17.pdf', 'size' => filesize($tmp)]);
    $tg->calls = [];
    drain($tg, $acc);
    $call = $tg->calls[0];
    check($call[0] === 'sendFile' && $call[3] === 'Offer 17.pdf' && $call[5] === 'Our offer' && $call[6] === false, 'a file goes with its name and caption (as a file, not a photo)');
    check(($pdo->query("SELECT status FROM ecw_tg_messages WHERE id = {$doc['id']}")->fetchColumn()) === 'sent', '…and is sent');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $pic = tempnam(sys_get_temp_dir(), 'tg');
    file_put_contents($pic, $png);
    $photo = ecw_tg_send($acct, $chatId, $me, '', ['tmp' => $pic, 'name' => 'screenshot.png', 'size' => strlen($png)]);
    $shown = array_values(array_filter(ecw_tg_messages($acct, $chatId)['messages'], static fn($m) => $m['id'] === $photo['id']))[0] ?? [];
    check(($shown['has_media'] ?? false) && $shown['kind'] === 'image' && $shown['direction'] === 'out', 'a photo I send shows in the chat at once, as a photo');
    $tg->calls = [];
    drain($tg, $acc);
    check(($tg->calls[0][0] ?? '') === 'sendFile' && ($tg->calls[0][6] ?? null) === true, '…goes to Telegram as a photo');
    $f = ecw_tg_media($acc, $photo['id']);
    check(file_get_contents($f['path']) === $png && $f['mime'] === 'image/png', '…and stays visible after it is sent (kept here)');
    @unlink($pic);
    throws(fn() => ecw_tg_send($acct, $chatId, $me, '', ['tmp' => $tmp, 'name' => 'big.bin', 'size' => ECW_TG_MAX_UPLOAD + 1]), InvalidArgumentException::class, '8 MB', 'files over 8 MB are refused, in plain words');
    $refused = ecw_tg_send($acct, $chatId, $me, 'Hello?');
    $e = new RuntimeException('USER_IS_BLOCKED');
    $tg->failNext = $e;
    drain($tg, $acc);
    $row = $pdo->query("SELECT status, error FROM ecw_tg_messages WHERE id = {$refused['id']}")->fetch();
    check($row['status'] === 'failed' && str_contains((string)$row['error'], 'blocked'), 'a message Telegram refuses is marked not sent, with the reason');
    check(ecw_tg_status($me)['status'] === 'ready', '…and the account stays connected');

    group('arriving');
    $before = (int)$pdo->query("SELECT unread FROM ecw_tg_chats WHERE id = {$chatId}")->fetchColumn();
    $tg->queue = [
        ['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 950, 'peer_id' => 5550001, 'out' => false, 'date' => time(), 'message' => 'Thanks!']],
        ['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 951, 'peer_id' => 5550001, 'out' => false, 'date' => time(), 'message' => '', 'media' => ['_' => 'messageMediaDocument', 'document' => ['id' => 9, 'mime_type' => 'audio/ogg', 'size' => 4000, 'attributes' => [['_' => 'documentAttributeAudio', 'voice' => true]]]]]],
        ['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 952, 'peer_id' => 5550001, 'out' => false, 'date' => time(), 'message' => 'specs', 'media' => ['_' => 'messageMediaDocument', 'document' => ['id' => 10, 'mime_type' => 'application/zip', 'size' => 50 * 1024 * 1024, 'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => 'big.zip']]]]]],
        ['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 3, 'peer_id' => 7770001, 'out' => false, 'date' => time(), 'message' => 'New person here']],
        ['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 4, 'peer_id' => -100999, 'out' => false, 'date' => time(), 'message' => 'group spam']],
        ['_' => 'updateEditMessage', 'message' => ['_' => 'message', 'id' => 950, 'peer_id' => 5550001, 'out' => false, 'date' => time(), 'message' => 'Thanks a lot!']],
        ['_' => 'updateReadHistoryOutbox', 'peer' => 5550001, 'max_id' => 901],
    ];
    $tg->calls = [];
    foreach ($tg->updates() as $u) ecw_tg_sync_update($tg, $acct, $u);
    check((int)$pdo->query("SELECT unread FROM ecw_tg_chats WHERE id = {$chatId}")->fetchColumn() === $before + 3, 'new messages count as unread');
    check($pdo->query("SELECT body FROM ecw_tg_messages WHERE chat_id = {$chatId} AND tg_message_id = 950")->fetchColumn() === 'Thanks a lot!', 'an edit changes the message');
    $voice = $pdo->query("SELECT * FROM ecw_tg_messages WHERE chat_id = {$chatId} AND tg_message_id = 951")->fetch();
    check($voice['kind'] === 'audio' && $voice['file_path'] !== null, 'a voice message is downloaded');
    $big = $pdo->query("SELECT * FROM ecw_tg_messages WHERE chat_id = {$chatId} AND tg_message_id = 952")->fetch();
    check($big['file_path'] === null && str_contains((string)$big['body'], 'open it in Telegram') && str_contains((string)$big['body'], 'big.zip'), 'a file over 20 MB is not downloaded; it says to open it in Telegram');
    check(count(ecw_tg_chats($acc)) === 2 && ecw_tg_chats($acc)[0]['title'] === 'Unknown 7770001', 'someone new writing starts a new chat (groups never do)');
    $r = ecw_tg_messages(ecw_tg_account($me), $chatId);
    $byTg = array_column($r['messages'], null, 'body');
    check(($byTg["It is ready.\nCome by tomorrow."]['status'] ?? '') === 'read', 'their "read" report gives our message ✓✓');
    $tg->queue = [['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 960, 'peer_id' => 5550001, 'out' => true, 'date' => time() + 5, 'message' => 'From my phone']]];
    foreach ($tg->updates() as $u) ecw_tg_sync_update($tg, $acct, $u);
    $last = ecw_tg_chats($acc)[0];
    check($last['id'] === $chatId && $last['preview'] === 'You: From my phone' && $last['unread'] === 0, 'what they write on the phone shows here too, and the chat counts as read');

    group('new chats');
    $c2 = ecw_tg_open_contact($acc, 5550002);
    check(ecw_tg_chat($acc, $c2)['name'] === 'Nikos', 'a chat starts with a contact');
    throws(fn() => ecw_tg_open_contact($acc, 123), InvalidArgumentException::class, 'does not exist', '…only with their own contacts');
    throws(fn() => ecw_tg_new_number($acct, '+30 697 777 7777', ''), InvalidArgumentException::class, 'first name', 'a new number needs a name for the contact');
    $job = ecw_tg_new_number($acct, '+30 697 777 7777', 'Eleni');
    drain($tg, $acc);
    $j = ecw_tg_job($me, $job);
    check($j['status'] === 'done' && ecw_tg_chat($acc, (int)$j['result']['chat_id'])['name'] === 'Eleni', 'a phone number not in the contacts is added, and the chat opens');
    $job = ecw_tg_new_number($acct, '+30 690 123 4567', 'Nobody');
    drain($tg, $acc);
    check(ecw_tg_job($me, $job)['status'] === 'failed' && str_contains((string)ecw_tg_job($me, $job)['error'], 'not on Telegram'), 'a number that is not on Telegram is said plainly');
    throws(fn() => ecw_tg_job($colleague, $job), InvalidArgumentException::class, 'does not exist', "a colleague cannot read this person's requests");

    // Linking messages to a case is the chatlink module's: modules/chatlink/tests.

    group('signing out');
    check(ecw_tg_sync_signed_out(new RuntimeException('AUTH_KEY_UNREGISTERED')) && !ecw_tg_sync_signed_out(new RuntimeException('FLOOD_WAIT_30')), 'Telegram ending the sign-in is recognised');
    check(str_contains(ecw_tg_sync_error(new RuntimeException('FLOOD_WAIT_120')), '2 min'), 'waiting times are said in minutes');
    $s = ecw_tg_disconnect($org, $me);
    $tg->calls = [];
    drain($tg, $acc);
    check($s['status'] === 'logged_out' && $tg->calls === [['logout']], 'disconnecting signs this app out of Telegram');
    check(count(ecw_tg_chats($acc)) === 4, 'the chats stay readable');
    throws(fn() => ecw_tg_ready_account($me), EcwTelegramNotConnected::class, 'not connected', 'sending needs connecting again');
    ecw_tg_login_start($org, $me, '+30 690 000 0002');
    check(count(ecw_tg_chats($acc)) === 0 && count(ecw_tg_contacts($acc)) === 0, "another number: the old number's chats and contacts go");

    group('another computer');
    $pdo->prepare('UPDATE ecw_tg_accounts SET machine = ?, status = \'ready\' WHERE id = ?')->execute([str_repeat('f', 32), $acc]);
    $acct = ecw_tg_account($me);
    check(!ecw_tg_here($acct) && !ecw_tg_status($me)['here'], 'a sign-in held by another computer is recognised');
    $c3 = ecw_tg_chat_upsert($acc, 5550003, ['name' => 'X']);
    throws(fn() => ecw_tg_send($acct, (int)$c3['id'], $me, 'hi'), InvalidArgumentException::class, 'another computer', '…and this one does not try to send with it');

    group('the module in the core (lib/modules.php)');
    check(isset(ecw_modules()['telegram']) && isset(ecw_feature_catalog()['telegram']), 'the module and its feature are found');
    check(in_array('telegram', ecw_packages()['pro']['features'], true) && !in_array('telegram', ecw_packages()['free']['features'], true), 'it is in Pro and above, not in Free');
    check(ecw_table('tg_accounts') === 'ecw_tg_accounts' && ecw_table('tg_jobs') === 'ecw_tg_jobs', 'its tables resolve');
    check(in_array('20261018_ecw_055_telegram.sql', array_map('basename', ecw_migration_files()), true), "its migration runs with the core's");
    $send = ecw_module_api_action('tg_send');
    check(($send['feature'] ?? null) === 'telegram' && !empty($send['post']) && empty(ecw_module_api_action('tg_media')['post']), 'sending is a POST action behind the feature; files are GET');
    check(ecw_module_page_file('telegram', 'telegram') !== null && ecw_module_page_file('telegram', 'webhook') === null, 'its tab opens; there is no public webhook');
    check(ecw_hook_first('case.event_label', 'TELEGRAM_LINKED', ['title' => 'Maria']) === 'linked a Telegram chat (Maria)', 'its case events read as words');
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    check(class_exists(\danog\MadelineProto\API::class) && is_subclass_of(EcwTgMadeline::class, EcwTgClient::class), 'the Telegram client library is installed');
} catch (Throwable $e) {
    $fail++; echo '  FAIL  unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Files the test made (media and outgoing) of the rolled-back accounts.
    foreach (['media', 'out'] as $sub) foreach (glob(ecw_tg_dir($sub) . '/' . ($acc ?? 0) . '/*') ?: [] as $f) @unlink($f);
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
