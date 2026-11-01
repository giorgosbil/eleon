<?php declare(strict_types=1);
/**
 * Email Case Workspace — signatures.
 *
 * Scoped per user, optionally per mailbox: one person who works a shared
 * support mailbox and their own needs a different sign-off in each. Resolution
 * is mailbox-specific first, then the user's default (mailbox_id NULL).
 *
 * The HTML is sanitized on SAVE, not on render. Storing raw markup and cleaning
 * it later means every future reader has to remember to clean it, and the one
 * that forgets is a stored XSS. Sanitizing once, at the boundary, makes the
 * stored value safe by construction — so the column can be rendered directly
 * and pasted into an outgoing mail without a second thought.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sanitize.php';

function ecw_signature_get(int $userId, ?int $mailboxId = null): ?array
{
    $st = ecw_db()->prepare(
        'SELECT * FROM `' . ecw_table('signatures') . '`
          WHERE user_id = ? AND IFNULL(mailbox_id, 0) = IFNULL(?, 0)'
    );
    $st->execute([$userId, $mailboxId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** Mailbox-specific if one exists, otherwise the user's default. */
function ecw_signature_resolve(int $userId, ?int $mailboxId): ?array
{
    if ($mailboxId !== null) {
        $specific = ecw_signature_get($userId, $mailboxId);
        if ($specific !== null) {
            return $specific;
        }
    }
    return ecw_signature_get($userId, null);
}

function ecw_signature_html_for(int $userId, ?int $mailboxId): string
{
    $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $prefs = json_decode((string)$st->fetchColumn(), true) ?: [];
    if (isset($prefs['signature_profiles'])) {
        $set = $prefs['signature_profiles'];
        $default = ecw_signature_default_for($set, $mailboxId);
        foreach ($set['profiles'] as $profile) if ($profile['id'] === $default) return ecw_sanitize_signature_html($profile['html']);
        return '';
    }
    $signature = ecw_signature_resolve($userId, $mailboxId);
    if ($signature === null) {
        return '';
    }

    $html = trim((string)($signature['signature_html'] ?? ''));
    if ($html !== '') {
        return ecw_sanitize_signature_html($html);   // compact legacy signatures too
    }

    return ecw_signature_from_fields($signature);
}

/**
 * Build a plain signature from the structured fields.
 *
 * The step lists display name / title / company / phone as separate fields, so
 * a user who fills those in and writes no HTML still gets a signature rather
 * than nothing.
 */
function ecw_signature_from_fields(array $signature): string
{
    $lines = array_values(array_filter([
        trim((string)($signature['display_name'] ?? '')),
        trim((string)($signature['title'] ?? '')),
        trim((string)($signature['company'] ?? '')),
        trim((string)($signature['phone'] ?? '')),
    ], static fn(string $v): bool => $v !== ''));

    if ($lines === []) {
        return '';
    }

    $escaped = array_map(static fn(string $l): string => htmlspecialchars($l, ENT_QUOTES, 'UTF-8'), $lines);
    $escaped[0] = '<strong>' . $escaped[0] . '</strong>';

    return '<div class="ecw-signature">' . implode('<br>', $escaped) . '</div>';
}

function ecw_signature_save(int $organizationId, int $userId, ?int $mailboxId, array $data): int
{
    $pdo = ecw_db();

    $fields = [
        'organization_id' => $organizationId,
        'user_id'         => $userId,
        'mailbox_id'      => $mailboxId,
        'display_name'    => $data['display_name'] ?? null,
        'title'           => $data['title'] ?? null,
        'company'         => $data['company'] ?? null,
        'phone'           => $data['phone'] ?? null,
        'signature_html'  => ecw_sanitize_signature_html($data['signature_html'] ?? ''),
    ];

    $existing = ecw_signature_get($userId, $mailboxId);
    if ($existing !== null) {
        $set    = implode(', ', array_map(static fn($c) => "`{$c}` = ?", array_keys($fields)));
        $params = array_merge(array_values($fields), [(int)$existing['id']]);
        $pdo->prepare('UPDATE `' . ecw_table('signatures') . "` SET {$set} WHERE id = ?")->execute($params);
        return (int)$existing['id'];
    }

    $cols = array_keys($fields);
    $pdo->prepare('INSERT INTO `' . ecw_table('signatures') . '` (`' . implode('`,`', $cols) . '`) VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($fields));
    return (int)$pdo->lastInsertId();
}

/**
 * The default signature of one account (lib/account.php): each account keeps
 * its own (account_defaults), "" for none; an account never set uses the
 * person's general default.
 */
function ecw_signature_default_for(array $set, ?int $mailboxId): ?string
{
    if ($mailboxId !== null && array_key_exists((string)$mailboxId, (array)($set['account_defaults'] ?? []))) {
        $d = (string)$set['account_defaults'][(string)$mailboxId];
        return $d === '' ? null : $d;
    }
    return $set['default_id'] ?? null;
}

/** Named, personal signatures live alongside the user's existing preferences. With an account, default_id is that account's default. */
function ecw_signature_profiles(int $userId, ?int $accountId = null): array
{
    $set = ecw_signature_profiles_raw($userId);
    if ($accountId !== null) $set['default_id'] = ecw_signature_default_for($set, $accountId);
    unset($set['account_defaults']);
    return $set;
}

function ecw_signature_profiles_raw(int $userId): array
{
    $st = ecw_db()->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ?');
    $st->execute([$userId]);
    $prefs = json_decode((string)$st->fetchColumn(), true) ?: [];
    if (isset($prefs['signature_profiles'])) {
        $set = $prefs['signature_profiles'];
        foreach ($set['profiles'] as &$profile) $profile['html'] = ecw_sanitize_signature_html($profile['html']);
        return $set;
    }
    $legacy = ecw_signature_get($userId);
    $html = $legacy ? ($legacy['signature_html'] ?: ecw_signature_from_fields($legacy)) : '';
    return ['profiles' => $html ? [['id'=>'legacy','name'=>'My signature','html'=>$html]] : [], 'default_id'=>$html ? 'legacy' : null];
}

function ecw_signature_profiles_save(int $userId, array $data, ?int $accountId = null): array
{
    $pdo = ecw_db();
    $own = !$pdo->inTransaction();   // inside a caller's transaction (tests), join it
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT preferences_json FROM `' . ecw_table('users') . '` WHERE id = ? FOR UPDATE');
        $st->execute([$userId]);
        $prefs = json_decode((string)$st->fetchColumn(), true) ?: [];
        $set = $prefs['signature_profiles'] ?? ecw_signature_profiles_raw($userId);
        $id = (string)($data['id'] ?? '');
        $index = null;
        foreach ($set['profiles'] as $i=>$profile) if ($profile['id'] === $id) $index = $i;
        if ($id !== '' && $index === null) throw new InvalidArgumentException('Signature not found');
        if (($data['operation'] ?? '') === 'delete') {
            if ($index === null) throw new InvalidArgumentException('Choose a signature to delete');
            array_splice($set['profiles'], $index, 1);
            if ($set['default_id'] === $id) $set['default_id'] = null;
            foreach ((array)($set['account_defaults'] ?? []) as $acct => $d) if ($d === $id) $set['account_defaults'][$acct] = '';
        } else {
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 80) throw new InvalidArgumentException('Enter a signature name (up to 80 characters)');
            $html = (string)($data['html'] ?? '');
            // Pictures are inside it (data: URIs, at most 600 px wide each): 1.5 MB is several logos.
            if (strlen($html) > 1500000) throw new InvalidArgumentException('The signature is too large: make its pictures smaller or use fewer.');
            if ($index === null && count($set['profiles']) >= 20) throw new InvalidArgumentException('You can save up to 20 signatures');
            $id = $id ?: bin2hex(random_bytes(12));
            $entry = ['id'=>$id,'name'=>$name,'html'=>ecw_sanitize_signature_html($html)];
            if ($index === null) $set['profiles'][] = $entry; else $set['profiles'][$index] = $entry;
            if ($accountId !== null) {
                // Default for the account chosen at the top only.
                if (!empty($data['is_default'])) $set['account_defaults'][(string)$accountId] = $id;
                elseif (ecw_signature_default_for($set, $accountId) === $id) $set['account_defaults'][(string)$accountId] = '';
            } elseif (!empty($data['is_default'])) $set['default_id'] = $id;
            elseif ($set['default_id'] === $id) $set['default_id'] = null;
        }
        $prefs['signature_profiles'] = $set;
        $pdo->prepare('UPDATE `' . ecw_table('users') . '` SET preferences_json = ? WHERE id = ?')->execute([json_encode($prefs),$userId]);
        if ($own) $pdo->commit();
        $out = $set;
        if ($accountId !== null) $out['default_id'] = ecw_signature_default_for($set, $accountId);
        unset($out['account_defaults']);
        return $out + ['saved_id'=>$id];
    } catch (Throwable $e) { if ($own) $pdo->rollBack(); throw $e; }
}
