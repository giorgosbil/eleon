<?php declare(strict_types=1);
/**
 * Print one email conversation (Mail → Print), chat-style like the case
 * export, with its internal notes. Same access rule as opening the thread:
 * the mailbox must be readable by this user, or the thread shared through a
 * case they can see. Remote images are not loaded. Layout: lib/print.php.
 */
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/access.php';
require_once dirname(__DIR__) . '/lib/mail.php';
require_once dirname(__DIR__) . '/lib/notes.php';
require_once dirname(__DIR__) . '/lib/print.php';

$context   = ecw_require_login(); ecw_require_feature('export');
$orgId     = (int)$context['organization_id'];
$userId    = (int)$context['user']['id'];
$threadId  = (int)($_GET['thread'] ?? 0);
$mailboxId = (int)($_GET['mailbox'] ?? 0);
$mailbox   = $threadId > 0 && $mailboxId > 0 ? ecw_access_mailbox($orgId, $userId, $mailboxId, $threadId) : null;
$thread    = $mailbox ? ecw_thread_detail($mailboxId, $threadId, 'asc', false) : null;
if ($thread === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Conversation not found\n";
    exit;
}
$missing = [];
foreach ($thread['messages'] as $m) {
    if (!$m['removed'] && $m['body_state'] !== 'fetched') $missing[] = ['id' => $m['id'], 'mailbox_id' => $mailboxId];
}
if ($missing !== [] && ecw_print_fetch_missing($missing)) {
    $thread = ecw_thread_detail($mailboxId, $threadId, 'asc', false);
}
$withNotes = ($_GET['notes'] ?? '1') !== '0';
$addr = static fn(array $list): string => implode(', ', array_filter(array_map(static fn($r) => ($r['name'] ?? '') ?: ($r['email'] ?? ''), $list)));

$items = [];
foreach ($thread['messages'] as $m) {
    $items[] = [
        't' => strtotime($m['occurred_at'] . ' UTC'), 'kind' => $m['direction'] === 'outgoing' ? 'out' : 'in',
        'who' => ($m['from']['name'] ?? '') ?: ($m['from']['email'] ?? ''), 'to' => $addr($m['to']), 'cc' => $addr($m['cc']),
        'subject' => $m['subject'], 'html' => $m['body_html'], 'signature' => $m['signature_html'], 'removed' => $m['removed'],
        'attachments' => array_column(array_filter($m['attachments'], static fn($a) => empty($a['is_inline'])), 'filename'),
    ];
}
if ($withNotes) {
    foreach (ecw_thread_notes($threadId, $userId) as $n) {
        $items[] = ['t' => strtotime($n['created_at'] . ' UTC'), 'kind' => 'note', 'who' => $n['author']['name'] ?: $n['author']['email'],
            'html' => $n['html'], 'about' => $n['about'] ? $n['about']['from'] . '’s email' : null];
    }
}
usort($items, static fn($a, $b) => $a['t'] <=> $b['t']);

// In the zone of the person printing (lib/timezone.php), as on screen.
require_once dirname(__DIR__) . '/lib/timezone.php';
try { $tz = new DateTimeZone(ecw_tz_for_user(null, $orgId)); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
$stamp = static fn(int $t): string => (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('D j M Y, H:i');
$people = [];
foreach ($thread['messages'] as $m) foreach (array_merge([$m['from']], $m['to'], $m['cc']) as $p) {
    if (!empty($p['email'])) $people[strtolower($p['email'])] = ($p['name'] ?? '') ?: $p['email'];
}
$first = $items ? $items[0]['t'] : time(); $last = $items ? end($items)['t'] : time();

ecw_print_render([
    'title'      => $thread['subject'],
    'heading'    => $thread['subject'],
    'subheading' => $thread['case'] ? 'Case ' . $thread['case']['number'] . ' · ' . $thread['case']['name'] : null,
    'facts'      => [
        ['Mailbox', (string)$mailbox['email_address']], ['Messages', (string)count($thread['messages'])],
        ['From', $stamp($first)], ['To', $stamp($last)],
        ['People', implode(', ', array_slice(array_values($people), 0, 8)) . (count($people) > 8 ? ' …' : '')], ['', ''],
    ],
    'tz'     => $tz,
    'items'  => $items,
    'links'  => [['?thread=' . $threadId . '&mailbox=' . $mailboxId . '&notes=' . ($withNotes ? '0' : '1'), $withNotes ? 'Hide internal notes' : 'Include internal notes']],
    'footer' => 'Printed ' . $stamp(time()) . ' by ' . ($context['user']['name'] ?: $context['user']['email']) . ' · Case Workspace',
]);
