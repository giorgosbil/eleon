<?php declare(strict_types=1);
/**
 * Email Case Workspace — reply templates (canned replies).
 *
 * A template is personal (user_id set) or shared with the organization
 * (user_id NULL). Anyone may create either; a shared template is changed only
 * by its creator or an admin. Bodies are sanitized with the composer rules on
 * save, so inserting one into the editor is as safe as typing it.
 *
 * Placeholders such as {{first_name}} are filled in by the composer at insert
 * time (web/assets/templates.js), because only it knows who the email is to.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sanitize.php';

const ECW_TEMPLATE_PLACEHOLDERS = [
    '{{first_name}}'  => "Recipient's first name",
    '{{name}}'        => "Recipient's full name",
    '{{my_name}}'     => 'Your name',
    '{{case_number}}' => 'Case number (when replying on a case)',
    '{{case_name}}'   => 'Case name',
    '{{date}}'        => "Today's date",
];

/** Shared templates, and the person's own ones of the account chosen at the top (lib/account.php; NULL = every account). */
function ecw_templates_list(int $orgId, int $userId): array
{
    require_once __DIR__ . '/account.php';
    $account = ecw_active_account_id($orgId, $userId);
    $st = ecw_db()->prepare(
        'SELECT t.id, t.name, t.subject, t.body_html, t.user_id, t.created_by, u.name AS creator
           FROM `' . ecw_table('templates') . '` t
           LEFT JOIN `' . ecw_table('users') . '` u ON u.id = t.created_by
          WHERE t.organization_id = ? AND (t.user_id IS NULL OR (t.user_id = ? AND (t.mailbox_id IS NULL OR t.mailbox_id <=> ?)))
          ORDER BY t.name'
    );
    $st->execute([$orgId, $userId, $account]);
    return array_map(static fn(array $r): array => [
        'id' => (int)$r['id'], 'name' => $r['name'], 'subject' => $r['subject'], 'body_html' => $r['body_html'],
        'shared' => $r['user_id'] === null, 'creator' => $r['creator'],
        'created_by' => $r['created_by'] === null ? null : (int)$r['created_by'],
    ], $st->fetchAll());
}

function ecw_template_save(int $orgId, int $userId, bool $isAdmin, array $data): int
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Enter a template name (up to 120 characters).');
    }
    $body = (string)($data['body_html'] ?? '');
    if (strlen($body) > 200000) {
        throw new InvalidArgumentException('The template is too large.');
    }
    $body = ecw_sanitize_compose_html($body);
    if (trim(strip_tags($body)) === '') {
        throw new InvalidArgumentException('Write the template text.');
    }
    $subject = trim((string)($data['subject'] ?? '')) ?: null;
    $owner = !empty($data['shared']) ? null : $userId;
    $id = (int)($data['id'] ?? 0);

    if ($id > 0) {
        $st = ecw_db()->prepare('SELECT user_id, created_by FROM `' . ecw_table('templates') . '` WHERE id = ? AND organization_id = ?');
        $st->execute([$id, $orgId]);
        $t = $st->fetch();
        $mayEdit = $t !== false && ((int)$t['user_id'] === $userId || ($t['user_id'] === null && ($isAdmin || (int)$t['created_by'] === $userId)));
        if (!$mayEdit) {
            throw new InvalidArgumentException('You cannot change this template.');
        }
        ecw_db()->prepare('UPDATE `' . ecw_table('templates') . '` SET name = ?, subject = ?, body_html = ?, user_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$name, $subject, $body, $owner, ecw_now(), $id]);
        return $id;
    }
    require_once __DIR__ . '/account.php';
    $account = $owner === null ? null : ecw_active_account_id($orgId, $userId);   // a personal template belongs to the chosen account
    ecw_db()->prepare('INSERT INTO `' . ecw_table('templates') . '` (organization_id, user_id, name, subject, body_html, created_by, created_at, mailbox_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$orgId, $owner, $name, $subject, $body, $userId, ecw_now(), $account]);
    return (int)ecw_db()->lastInsertId();
}

function ecw_template_delete(int $orgId, int $userId, bool $isAdmin, int $id): bool
{
    $st = ecw_db()->prepare('SELECT user_id, created_by FROM `' . ecw_table('templates') . '` WHERE id = ? AND organization_id = ?');
    $st->execute([$id, $orgId]);
    $t = $st->fetch();
    if ($t === false || !((int)$t['user_id'] === $userId || ($t['user_id'] === null && ($isAdmin || (int)$t['created_by'] === $userId)))) {
        return false;
    }
    ecw_db()->prepare('DELETE FROM `' . ecw_table('templates') . '` WHERE id = ?')->execute([$id]);
    return true;
}
