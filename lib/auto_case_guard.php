<?php declare(strict_types=1);
/**
 * Email Case Workspace — a case started by the app by itself, once, whichever copy of the app.
 *
 * Two PCs can run copies of the app on the same mailboxes, each with its own database (data
 * moved with DataPush / DataPull). Each copy saw a new support email as new and started its own
 * case from it: after a DataPull the same cases were there twice. A case the app starts by
 * itself (a group's words, the assistant, a rule, the "Create Case" folder) now marks the email
 * in Outlook with its case number, a hidden property on the email (MicrosoftGraphProvider
 * caseMarkOf / markCase), and every copy asks for that mark before starting one. The mailbox is
 * the one thing the copies share.
 *
 * Gmail mailboxes, and a mailbox Microsoft does not answer for: no mark, the case is started as
 * before (a missed duplicate is better than a missed case).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';

/** The provider id of a message and its mailbox's provider. @return array{0:?string, 1:string} */
function ecw_auto_case_message(int $messageId): array
{
    // Tests never reach the real Microsoft: only a scripted one (tests/support/FakeGraph.php).
    if (defined('ECW_TESTING')) {
        require_once __DIR__ . '/provider/GraphHttp.php';
        if (GraphHttp::testTransport() === null) return [null, ''];
    }
    $st = ecw_db()->prepare('SELECT m.provider_message_id, mb.provider FROM `' . ecw_table('email_messages') . '` m
                               JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id WHERE m.id = ?');
    $st->execute([$messageId]);
    $r = $st->fetch();
    return [$r ? (string)$r['provider_message_id'] : null, $r ? (string)$r['provider'] : ''];
}

/** The case number another copy of the app already started from this email, or null. */
function ecw_auto_case_started_elsewhere(int $mailboxId, int $messageId): ?string
{
    [$providerId, $provider] = ecw_auto_case_message($messageId);
    if ($providerId === null || $providerId === '' || $provider !== 'microsoft') return null;
    try {
        require_once __DIR__ . '/mailbox.php';
        $p = ecw_provider_for_mailbox($mailboxId);
        if (!method_exists($p, 'caseMarkOf')) return null;
        $number = $p->caseMarkOf($providerId);
    } catch (Throwable $e) {
        ecw_log('auto case: could not read the mark of message ' . $messageId . ': ' . $e->getMessage());
        return null;
    }
    // Marked: a case was started from it already, here (and undone) or by the other PC. The case
    // numbers of two copies are counted apart, so the number says nothing about which.
    return $number;
}

/** Mark the email with the case just started from it. Never fails the case. */
function ecw_auto_case_mark(int $mailboxId, int $messageId, int $caseId): void
{
    [$providerId, $provider] = ecw_auto_case_message($messageId);
    if ($providerId === null || $providerId === '' || $provider !== 'microsoft') return;
    try {
        $st = ecw_db()->prepare('SELECT case_number FROM `' . ecw_table('cases') . '` WHERE id = ?');
        $st->execute([$caseId]);
        $number = (string)$st->fetchColumn();
        require_once __DIR__ . '/mailbox.php';
        $p = ecw_provider_for_mailbox($mailboxId);
        if ($number !== '' && method_exists($p, 'markCase')) $p->markCase($providerId, $number);
    } catch (Throwable $e) {
        ecw_log('auto case: could not mark message ' . $messageId . ' with case ' . $caseId . ': ' . $e->getMessage());
    }
}
