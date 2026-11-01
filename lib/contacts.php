<?php declare(strict_types=1);
/**
 * Contacts and companies — built from the mail itself.
 *
 * A person is an address seen on email (ecw_email_addresses), a company is an
 * email domain. Personal mail providers (gmail.com, outlook.com…) are not
 * companies: those people stand alone. The workspace's own addresses are left
 * out. Everything counted (conversations, cases, last contact) is limited to
 * mail the viewer may see: with private mailboxes, their own mailboxes plus
 * conversations of cases they can see.
 *
 * Colleagues can add what mail does not say: a proper name, phone, job title,
 * notes — shared with the whole workspace.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/config.php';

const ECW_FREE_MAIL_DOMAINS = [
    'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'hotmail.gr', 'live.com', 'msn.com', 'yahoo.com', 'yahoo.gr', 'ymail.com',
    'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.de', 'web.de', 'mail.com', 'zoho.com',
    'yandex.com', 'otenet.gr', 'windowslive.com', 'outlook.gr', 'pm.me', 'hey.com', 'fastmail.com', 'tutanota.com',
];

function ecw_is_free_mail_domain(string $domain): bool
{
    return in_array(strtolower($domain), ECW_FREE_MAIL_DOMAINS, true);
}

/**
 * SQL limiting messages (alias m, mailbox alias mb) to what $userId may see.
 * Appends its params.
 */
function ecw_contacts_scope_sql(int $orgId, int $userId, array &$params): string
{
    $params[] = $orgId;
    $sql = 'mb.organization_id = ?';
    // Only the account chosen at the top (lib/account.php): two companies' contacts never mix.
    require_once __DIR__ . '/account.php';
    if (($accountId = ecw_active_account_id($orgId, $userId)) !== null) {
        $sql .= ' AND mb.id = ?';
        $params[] = $accountId;
    }
    if (ecw_private_mailboxes()) {
        require_once __DIR__ . '/shared_mailboxes.php';
        $inner = [];
        $caseSql = ecw_case_visible_sql('vc', $userId, $inner);
        $sql .= ' AND (' . ecw_mailbox_visible_sql('mb', $userId, $params) . ' OR m.email_thread_id IN (SELECT vct.email_thread_id FROM `' . ecw_table('case_threads') . '` vct JOIN `'
            . ecw_table('cases') . '` vc ON vc.id = vct.case_id WHERE vc.deleted_at IS NULL AND ' . $caseSql . '))';
        array_push($params, ...$inner);
    }
    return $sql;
}

/** The workspace's own addresses and domains (left out of contacts). @return array{emails:string[], domains:string[]} */
function ecw_contacts_own(int $orgId): array
{
    static $cache = [];
    if (isset($cache[$orgId])) return $cache[$orgId];
    $st = ecw_db()->prepare('SELECT LOWER(email_address) FROM `' . ecw_table('mailboxes') . '` WHERE organization_id = ?
                             UNION SELECT LOWER(u.email) FROM `' . ecw_table('users') . '` u JOIN `' . ecw_table('organization_users') . '` ou ON ou.user_id = u.id WHERE ou.organization_id = ?');
    $st->execute([$orgId, $orgId]);
    $emails = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
    $domains = array_values(array_unique(array_filter(array_map(static fn($e) => substr((string)$e, (int)strrpos((string)$e, '@') + 1), $emails),
        static fn($d) => $d !== '' && !ecw_is_free_mail_domain($d))));
    return $cache[$orgId] = ['emails' => $emails, 'domains' => $domains];
}

/** "a,b,c" placeholders and values for an IN list ('' when empty → a never-true list). */
function ecw_in_list(array $values, array &$params): string
{
    if ($values === []) return "''";
    array_push($params, ...$values);
    return implode(',', array_fill(0, count($values), '?'));
}

// ------------------------------------------------------------------ lists

/** Companies (email domains) the viewer has mail with, newest contact first. */
function ecw_companies_list(int $orgId, int $userId, string $q = '', int $limit = 150): array
{
    $own = ecw_contacts_own($orgId);
    $params = [];
    $scope = ecw_contacts_scope_sql($orgId, $userId, $params);
    $params[] = $orgId;
    $free = ecw_in_list(ECW_FREE_MAIL_DOMAINS, $params);
    $mine = ecw_in_list($own['domains'], $params);
    $search = '';
    if (($q = trim($q)) !== '') {
        $search = ' AND (a.domain LIKE ? OR co.name LIKE ?)';
        array_push($params, '%' . $q . '%', '%' . $q . '%');
    }
    $st = ecw_db()->prepare(
        'SELECT a.domain, co.name, COUNT(DISTINCT a.normalized_email) AS people, COUNT(DISTINCT m.email_thread_id) AS conversations, MAX(m.occurred_at) AS last_at
           FROM `' . ecw_table('message_participants') . '` p
           JOIN `' . ecw_table('email_messages') . '` m ON m.id = p.email_message_id
           JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id
           JOIN `' . ecw_table('email_addresses') . '` a ON a.organization_id = mb.organization_id AND a.normalized_email = p.email
           LEFT JOIN `' . ecw_table('companies') . '` co ON co.organization_id = a.organization_id AND co.domain = a.domain
          WHERE ' . $scope . ' AND a.organization_id = ? AND m.provider_removed_at IS NULL
            AND a.domain NOT IN (' . $free . ') AND a.domain NOT IN (' . $mine . ')' . $search . '
          GROUP BY a.domain, co.name ORDER BY last_at DESC LIMIT ' . max(1, min(500, $limit))
    );
    $st->execute($params);
    return array_map(static fn($r) => ['domain' => $r['domain'], 'name' => $r['name'] ?: ecw_company_guess_name((string)$r['domain']),
        'named' => $r['name'] !== null && $r['name'] !== '', 'people' => (int)$r['people'], 'conversations' => (int)$r['conversations'], 'last_at' => $r['last_at']], $st->fetchAll());
}

/** "acme-tools.co.uk" → "Acme Tools", until someone types the real name. */
function ecw_company_guess_name(string $domain): string
{
    $parts = explode('.', strtolower($domain));
    // Drop the ending (.ai, .gr, .com…) and a second-level one (.co.uk, .com.gr), keep the name before it.
    if (count($parts) > 1) array_pop($parts);
    if (count($parts) > 1 && in_array(end($parts), ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'gr'], true)) array_pop($parts);
    $base = (string)end($parts);
    return ucwords(str_replace(['-', '_'], ' ', $base));
}

/** People the viewer has mail with (optionally of one company), newest contact first. */
function ecw_people_list(int $orgId, int $userId, string $q = '', ?string $domain = null, int $limit = 200): array
{
    $own = ecw_contacts_own($orgId);
    $params = [];
    $scope = ecw_contacts_scope_sql($orgId, $userId, $params);
    $params[] = $orgId;
    $mine = ecw_in_list($own['emails'], $params);
    $extra = '';
    if ($domain !== null) { $extra .= ' AND a.domain = ?'; $params[] = strtolower($domain); }
    if (($q = trim($q)) !== '') {
        $extra .= ' AND (a.normalized_email LIKE ? OR a.display_name LIKE ? OR a.edited_name LIKE ?)';
        array_push($params, '%' . mb_strtolower($q) . '%', '%' . $q . '%', '%' . $q . '%');
    }
    $st = ecw_db()->prepare(
        'SELECT a.normalized_email AS email, COALESCE(NULLIF(a.edited_name, \'\'), a.display_name) AS name, a.domain, a.job_title, a.phone,
                COUNT(DISTINCT m.email_thread_id) AS conversations, MAX(m.occurred_at) AS last_at
           FROM `' . ecw_table('message_participants') . '` p
           JOIN `' . ecw_table('email_messages') . '` m ON m.id = p.email_message_id
           JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id
           JOIN `' . ecw_table('email_addresses') . '` a ON a.organization_id = mb.organization_id AND a.normalized_email = p.email
          WHERE ' . $scope . ' AND a.organization_id = ? AND m.provider_removed_at IS NULL AND a.normalized_email NOT IN (' . $mine . ')' . $extra . '
          GROUP BY a.normalized_email, a.edited_name, a.display_name, a.domain, a.job_title, a.phone ORDER BY last_at DESC LIMIT ' . max(1, min(500, $limit))
    );
    $st->execute($params);
    return array_map(static fn($r) => ['email' => $r['email'], 'name' => (string)($r['name'] ?? ''), 'domain' => $r['domain'],
        'job_title' => $r['job_title'], 'phone' => $r['phone'], 'company' => !ecw_is_free_mail_domain((string)$r['domain']),
        'conversations' => (int)$r['conversations'], 'last_at' => $r['last_at']], $st->fetchAll());
}

// ------------------------------------------------------------------ details

/**
 * Conversations, cases and counts for a set of addresses (a person, or a company's people).
 * @param string $where SQL on participants alias p (e.g. "p.email = ?"), with $whereParams
 */
function ecw_contacts_activity(int $orgId, int $userId, string $where, array $whereParams): array
{
    $params = [];
    $scope = ecw_contacts_scope_sql($orgId, $userId, $params);
    $base = ' FROM `' . ecw_table('message_participants') . '` p
              JOIN `' . ecw_table('email_messages') . '` m ON m.id = p.email_message_id
              JOIN `' . ecw_table('mailboxes') . '` mb ON mb.id = m.mailbox_id
             WHERE ' . $scope . ' AND m.provider_removed_at IS NULL AND (' . $where . ')';
    $params = array_merge($params, $whereParams);

    $st = ecw_db()->prepare('SELECT COUNT(DISTINCT CASE WHEN p.role = \'from\' THEN m.id END) AS received,
                                    COUNT(DISTINCT CASE WHEN p.role <> \'from\' AND m.direction = \'outgoing\' THEN m.id END) AS sent,
                                    MIN(m.occurred_at) AS first_at, MAX(m.occurred_at) AS last_at,
                                    MAX(CASE WHEN p.role = \'from\' THEN m.occurred_at END) AS last_from_them,
                                    MAX(CASE WHEN m.direction = \'outgoing\' THEN m.occurred_at END) AS last_to_them' . $base);
    $st->execute($params);
    $stats = $st->fetch() ?: [];

    $st = ecw_db()->prepare('SELECT m.email_thread_id AS id, m.mailbox_id, MAX(m.occurred_at) AS last_at, COUNT(DISTINCT m.id) AS emails' . $base
        . ' GROUP BY m.email_thread_id, m.mailbox_id ORDER BY last_at DESC LIMIT 40');
    $st->execute($params);
    $threads = $st->fetchAll();
    $ids = array_map(static fn($t) => (int)$t['id'], $threads);
    $subjects = [];
    $caseOf = [];
    if ($ids !== []) {
        $in = implode(',', $ids);
        foreach (ecw_db()->query('SELECT t.id, COALESCE(NULLIF(lm.subject, \'\'), t.normalized_subject) AS subject FROM `' . ecw_table('email_threads') . '` t
                                   LEFT JOIN `' . ecw_table('email_messages') . "` lm ON lm.id = t.latest_message_id WHERE t.id IN ({$in})") as $r) {
            $subjects[(int)$r['id']] = (string)$r['subject'];
        }
        $vis = [];
        $visSql = ecw_case_visible_sql('c', $userId, $vis);
        $cs = ecw_db()->prepare('SELECT ct.email_thread_id, c.id, c.case_number, c.name, c.status FROM `' . ecw_table('case_threads') . '` ct
                                   JOIN `' . ecw_table('cases') . "` c ON c.id = ct.case_id
                                  WHERE ct.email_thread_id IN ({$in}) AND c.organization_id = ? AND c.deleted_at IS NULL AND " . $visSql);
        $cs->execute(array_merge([$orgId], $vis));
        foreach ($cs->fetchAll() as $r) $caseOf[(int)$r['email_thread_id']] = ['id' => (int)$r['id'], 'number' => $r['case_number'], 'name' => $r['name'], 'status' => $r['status']];
    }
    $conversations = array_map(static fn($t) => ['id' => (int)$t['id'], 'mailbox_id' => (int)$t['mailbox_id'], 'subject' => $subjects[(int)$t['id']] ?? '(no subject)',
        'last_at' => $t['last_at'], 'emails' => (int)$t['emails'], 'case' => $caseOf[(int)$t['id']] ?? null], $threads);
    $cases = [];
    foreach ($caseOf as $c) $cases[$c['id']] = $c;

    return [
        'stats' => ['received' => (int)($stats['received'] ?? 0), 'sent' => (int)($stats['sent'] ?? 0), 'first_at' => $stats['first_at'] ?? null,
            'last_at' => $stats['last_at'] ?? null, 'last_from_them' => $stats['last_from_them'] ?? null, 'last_to_them' => $stats['last_to_them'] ?? null,
            'open_cases' => count(array_filter($cases, static fn($c) => $c['status'] !== 'closed'))],
        'conversations' => $conversations,
        'cases' => array_values($cases),
    ];
}

function ecw_company_detail(int $orgId, int $userId, string $domain): ?array
{
    $domain = strtolower(trim($domain));
    if ($domain === '' || ecw_is_free_mail_domain($domain)) return null;
    $people = ecw_people_list($orgId, $userId, '', $domain);
    if ($people === []) return null;   // nothing this viewer may see
    $st = ecw_db()->prepare('SELECT name, phone, website, notes, updated_at FROM `' . ecw_table('companies') . '` WHERE organization_id = ? AND domain = ?');
    $st->execute([$orgId, $domain]);
    $co = $st->fetch() ?: [];
    $emails = array_column($people, 'email');
    $ph = implode(',', array_fill(0, count($emails), '?'));
    return [
        'domain' => $domain, 'name' => ($co['name'] ?? '') ?: ecw_company_guess_name($domain), 'named' => !empty($co['name']),
        'phone' => $co['phone'] ?? null, 'website' => $co['website'] ?? null, 'notes' => $co['notes'] ?? null,
        'people' => $people,
    ] + ecw_contacts_activity($orgId, $userId, 'p.email IN (' . $ph . ')', $emails);
}

function ecw_person_detail(int $orgId, int $userId, string $email): ?array
{
    $email = strtolower(trim($email));
    $st = ecw_db()->prepare('SELECT normalized_email, display_name, edited_name, phone, job_title, notes, domain FROM `' . ecw_table('email_addresses') . '` WHERE organization_id = ? AND normalized_email = ?');
    $st->execute([$orgId, $email]);
    $a = $st->fetch();
    if (!$a) return null;
    $activity = ecw_contacts_activity($orgId, $userId, 'p.email = ?', [$email]);
    if ($activity['conversations'] === []) return null;   // nothing this viewer may see
    $free = ecw_is_free_mail_domain((string)$a['domain']);
    return [
        'email' => $email, 'name' => (string)(($a['edited_name'] ?? '') ?: ($a['display_name'] ?? '')), 'mail_name' => $a['display_name'],
        'phone' => $a['phone'], 'job_title' => $a['job_title'], 'notes' => $a['notes'],
        'company' => $free ? null : ['domain' => $a['domain'], 'name' => ecw_company_name($orgId, (string)$a['domain'])],
    ] + $activity;
}

function ecw_company_name(int $orgId, string $domain): string
{
    $st = ecw_db()->prepare('SELECT name FROM `' . ecw_table('companies') . '` WHERE organization_id = ? AND domain = ?');
    $st->execute([$orgId, $domain]);
    return (string)($st->fetchColumn() ?: ecw_company_guess_name($domain));
}

// ------------------------------------------------------------------ editing

function ecw_company_save(int $orgId, int $userId, string $domain, array $data): void
{
    $domain = strtolower(trim($domain));
    if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) || ecw_is_free_mail_domain($domain)) throw new InvalidArgumentException('That is not a company domain.');
    $clean = static fn($v, int $max) => ($v = trim((string)$v)) === '' ? null : mb_substr($v, 0, $max);
    $website = $clean($data['website'] ?? '', 255);
    if ($website !== null && !preg_match('#^https?://#i', $website)) $website = 'https://' . $website;
    if ($website !== null && !filter_var($website, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('The website address does not look right.');
    ecw_db()->prepare('INSERT INTO `' . ecw_table('companies') . '` (organization_id, domain, name, phone, website, notes, edited_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE name = VALUES(name), phone = VALUES(phone), website = VALUES(website), notes = VALUES(notes), edited_by_user_id = VALUES(edited_by_user_id)')
        ->execute([$orgId, $domain, $clean($data['name'] ?? '', 255), $clean($data['phone'] ?? '', 60), $website, $clean($data['notes'] ?? '', 5000), $userId]);
}

function ecw_person_save(int $orgId, int $userId, string $email, array $data): void
{
    $clean = static fn($v, int $max) => ($v = trim((string)$v)) === '' ? null : mb_substr($v, 0, $max);
    $st = ecw_db()->prepare('UPDATE `' . ecw_table('email_addresses') . '` SET edited_name = ?, phone = ?, job_title = ?, notes = ?, edited_by_user_id = ? WHERE organization_id = ? AND normalized_email = ?');
    $st->execute([$clean($data['name'] ?? '', 255), $clean($data['phone'] ?? '', 60), $clean($data['job_title'] ?? '', 120), $clean($data['notes'] ?? '', 5000), $userId, $orgId, strtolower(trim($email))]);
    if ($st->rowCount() === 0) {
        $chk = ecw_db()->prepare('SELECT 1 FROM `' . ecw_table('email_addresses') . '` WHERE organization_id = ? AND normalized_email = ?');
        $chk->execute([$orgId, strtolower(trim($email))]);
        if (!$chk->fetchColumn()) throw new InvalidArgumentException('That person is not in your mail.');
    }
}
