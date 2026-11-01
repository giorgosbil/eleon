<?php declare(strict_types=1);
/**
 * Email Case Workspace — saved filters of the Cases list (saved_filters).
 *
 * A filter is the Cases list's address after "?" (status, view, group, owner, due…) under a
 * name. A person's own, or shared: every colleague sees a shared one. Its maker changes or
 * deletes it; an admin may change or delete any shared one.
 */

require_once __DIR__ . '/db.php';

const ECW_SAVED_FILTERS_MAX = 40;

/** The filters this person sees: their own and the shared ones. @return list<array> */
function ecw_saved_filters(int $orgId, int $userId): array
{
    $st = ecw_db()->prepare('SELECT f.id, f.name, f.query, f.shared, f.user_id, u.name AS owner_name FROM `' . ecw_table('saved_filters') . '` f
                               LEFT JOIN `' . ecw_table('users') . '` u ON u.id = f.user_id
                              WHERE f.organization_id = ? AND (f.user_id = ? OR f.shared = 1) ORDER BY f.shared, f.name');
    $st->execute([$orgId, $userId]);
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'query' => (string)$r['query'], 'shared' => (bool)$r['shared'],
        'mine' => (int)$r['user_id'] === $userId, 'owner_name' => (string)($r['owner_name'] ?? '')], $st->fetchAll());
}

/** The Cases list address of a filter, without the page number, in a fixed order. */
function ecw_saved_filter_query(string $query): string
{
    // Kept as the page writes it (group_ids[]=3 stays so), only without the page number and empty parts.
    $parts = array_filter(explode('&', ltrim(trim($query), '?')), static fn($p) => $p !== '' && !preg_match('/^(page|csrf)=/', $p) && !str_ends_with($p, '='));
    return implode('&', $parts);
}

/**
 * Save the current filters under a name (the same name again replaces it), or with $filterId
 * change that filter: its name, its filters, shared or not. @return int its id
 */
function ecw_saved_filter_save(int $orgId, int $userId, string $name, string $query, bool $shared, int $filterId = 0, bool $isAdmin = false): int
{
    $name = trim(mb_substr((string)preg_replace('/\s+/u', ' ', $name), 0, 80));
    if ($name === '') throw new InvalidArgumentException('Give the filter a name.');
    $query = ecw_saved_filter_query($query);
    if (strlen($query) > 2000) throw new InvalidArgumentException('That filter is too long to save.');
    $pdo = ecw_db();
    if ($filterId > 0) {
        $st = $pdo->prepare('SELECT user_id FROM `' . ecw_table('saved_filters') . '` WHERE id = ? AND organization_id = ? AND (user_id = ?' . ($isAdmin ? ' OR shared = 1' : '') . ')');
        $st->execute([$filterId, $orgId, $userId]);
        $owner = $st->fetchColumn();
        if ($owner === false) throw new InvalidArgumentException('That filter is not yours to change.');
        $clash = $pdo->prepare('SELECT 1 FROM `' . ecw_table('saved_filters') . '` WHERE organization_id = ? AND user_id = ? AND name = ? AND id <> ?');
        $clash->execute([$orgId, (int)$owner, $name, $filterId]);
        if ($clash->fetchColumn()) throw new InvalidArgumentException('There is already a saved filter called “' . $name . '”.');
        $pdo->prepare('UPDATE `' . ecw_table('saved_filters') . '` SET name = ?, query = ?, shared = ? WHERE id = ?')->execute([$name, $query, $shared ? 1 : 0, $filterId]);
        return $filterId;
    }
    $st = $pdo->prepare('SELECT id FROM `' . ecw_table('saved_filters') . '` WHERE organization_id = ? AND user_id = ? AND name = ?');
    $st->execute([$orgId, $userId, $name]);
    if (($id = $st->fetchColumn()) !== false) {
        $pdo->prepare('UPDATE `' . ecw_table('saved_filters') . '` SET query = ?, shared = ? WHERE id = ?')->execute([$query, $shared ? 1 : 0, (int)$id]);
        return (int)$id;
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM `' . ecw_table('saved_filters') . '` WHERE organization_id = ? AND user_id = ?');
    $count->execute([$orgId, $userId]);
    if ((int)$count->fetchColumn() >= ECW_SAVED_FILTERS_MAX) throw new InvalidArgumentException('You have ' . ECW_SAVED_FILTERS_MAX . ' saved filters: delete one first.');
    $pdo->prepare('INSERT INTO `' . ecw_table('saved_filters') . '` (organization_id, user_id, name, query, shared) VALUES (?, ?, ?, ?, ?)')
        ->execute([$orgId, $userId, $name, $query, $shared ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}

/** Delete a filter: one's own, or (an admin) a shared one. */
function ecw_saved_filter_delete(int $orgId, int $userId, int $filterId, bool $isAdmin): void
{
    $st = ecw_db()->prepare('DELETE FROM `' . ecw_table('saved_filters') . '` WHERE id = ? AND organization_id = ? AND (user_id = ?' . ($isAdmin ? ' OR shared = 1' : '') . ')');
    $st->execute([$filterId, $orgId, $userId]);
    if ($st->rowCount() === 0) throw new InvalidArgumentException('That filter is not yours to delete.');
}
