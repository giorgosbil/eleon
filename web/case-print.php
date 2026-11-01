<?php declare(strict_types=1);
/**
 * Case export: one chronological, chat-style document of every email,
 * internal note and chat message linked to the case (modules), for printing or "Save as PDF". Same
 * access rule as the case page. Remote images are not loaded (privacy, and
 * printing them would fetch them from the sender). Layout: lib/print.php.
 */
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/timeline.php';
require_once dirname(__DIR__) . '/lib/notes.php';
require_once dirname(__DIR__) . '/lib/print.php';

$context = ecw_require_login(); ecw_require_feature('export');
$orgId   = (int)$context['organization_id'];
$userId  = (int)$context['user']['id'];
$caseId  = (int)($_GET['id'] ?? 0);
if (!ecw_case_visible($orgId, $userId, $caseId)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Case not found\n";
    exit;
}
require_once dirname(__DIR__) . '/lib/audit.php';
ecw_audit('case.export', 'case', $caseId);
$withNotes = ($_GET['notes'] ?? '1') !== '0';
$timeline  = ecw_case_timeline($orgId, $caseId, false);

// Messages whose content was never downloaded would print empty: fetch them first.
$missing = [];
foreach ($timeline['events'] as $e) {
    if (($e['type'] ?? '') === 'email' && !$e['bubble']['removed'] && $e['bubble']['body_state'] !== 'fetched') {
        $col = array_values(array_filter($timeline['columns'], static fn($c) => $c['thread_id'] === $e['thread_id']))[0] ?? null;
        if ($col) $missing[] = ['id' => $e['message_id'], 'mailbox_id' => $col['mailbox_id']];
    }
}
if ($missing !== [] && ecw_print_fetch_missing($missing)) {
    $timeline = ecw_case_timeline($orgId, $caseId, false);
}
$summary = $timeline['summary'];

$names = static function (?int $id): string {
    if ($id === null) return 'Unassigned';
    $st = ecw_db()->prepare('SELECT name, email FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ? (string)($u['name'] ?: $u['email']) : 'Unassigned';
};
$labels = [];
foreach ($timeline['columns'] as $c) $labels[$c['thread_id']] = $c['label'];
$several = count($labels) > 1;
$addr = static fn(array $list): string => implode(', ', array_filter(array_map(static fn($r) => ($r['name'] ?? '') ?: ($r['email'] ?? ''), $list)));

$items = [];
foreach ($timeline['events'] as $e) {
    if (($e['type'] ?? 'email') !== 'email') continue;   // notes are added below, only when included
    $b = $e['bubble'];
    $items[] = [
        't' => strtotime($e['occurred_at'] . ' UTC'), 'kind' => $b['direction'] === 'outgoing' ? 'out' : 'in',
        'who' => ($b['from']['name'] ?? '') ?: ($b['from']['email'] ?? ''), 'to' => $addr($b['to']), 'cc' => $addr($b['cc']),
        'subject' => $b['subject'], 'thread' => $several ? ($labels[$e['thread_id']] ?? '') : '',
        'html' => $b['body_html'], 'signature' => $b['signature_html'], 'removed' => $b['removed'],
        'attachments' => array_column($b['attachments'], 'filename'),
    ];
}
// Rows of module columns (chats linked to the case…): each item may say how it prints.
foreach ($timeline['events'] as $e) {
    if (($e['type'] ?? 'email') === 'email' || empty($e['items'])) continue;
    foreach ($e['items'] as $m) {
        if (!is_array($m) || !isset($m['print']['html'], $m['at'])) continue;
        $items[] = ['t' => strtotime((string)$m['at']), 'kind' => 'chat', 'label' => (string)($m['print']['label'] ?? ''), 'who' => (string)($m['print']['who'] ?? ''),
            'html' => (string)$m['print']['html'], 'thread' => $several ? ($labels[$e['thread_id']] ?? '') : ''];
    }
}
if ($withNotes) {
    foreach (ecw_case_notes($caseId, $userId) as $n) {
        $items[] = ['t' => strtotime($n['created_at'] . ' UTC'), 'kind' => 'note', 'who' => $n['author']['name'] ?: $n['author']['email'],
            'html' => $n['html'], 'thread' => $several && $n['thread_id'] ? ($labels[$n['thread_id']] ?? '') : '',
            'about' => $n['about'] ? $n['about']['from'] . '’s email' : null];
    }
}
usort($items, static fn($a, $b) => $a['t'] <=> $b['t']);
$tz = new DateTimeZone($summary['timezone'] ?: 'UTC');
$stamp = static fn(int $t): string => (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('D j M Y, H:i');

ecw_print_render([
    'title'   => $summary['number'] . ' ' . $summary['name'],
    'heading' => $summary['number'] . ' · ' . $summary['name'],
    'facts'   => [
        ['Status', ucfirst($summary['status'])], ['Group', $summary['sharing']['group']['name'] ?? '—'],
        ['Responsible', $names($summary['owner_user_id'])], ['Handled by', $names($summary['handler_user_id'] ?? null)],
        ['Created', $stamp(strtotime($summary['created_at'] . ' UTC'))], ['Conversations', (string)(int)$summary['thread_count']],
    ],
    'tz'      => $tz,
    'items'   => $items,
    'links'   => [['?id=' . $caseId . '&notes=' . ($withNotes ? '0' : '1'), $withNotes ? 'Hide internal notes' : 'Include internal notes']],
    'note'    => $timeline['truncated'] ? 'Only the first ' . (int)ECW_TIMELINE_MAX_EVENTS . ' emails are included.' : null,
    'footer'  => 'Exported ' . $stamp(time()) . ' by ' . ($context['user']['name'] ?: $context['user']['email']) . ' · Case Workspace',
]);
