<?php declare(strict_types=1);
/** Live personal Outlook calendar; independent calendar tokens keep mail scopes unchanged. */
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/mailbox.php';
require_once __DIR__ . '/crypto.php';

final class EcwCalendarConsentRequired extends RuntimeException {}

/** The calendar of the account chosen at the top (lib/account.php): its own Outlook sign-in. */
function ecw_calendar_mailbox(int $orgId, int $userId): ?array
{
    require_once __DIR__ . '/account.php';
    return ecw_active_microsoft_mailbox($orgId, $userId);
}

function ecw_calendar_token(array $mb): string
{
    $key = (int)$mb['id'];
    $cached = $_SESSION['ecw_calendar_tokens'][$key] ?? null;
    if ($cached && $cached['expires'] > time() + 120) return (string)ecw_decrypt($cached['token']);
    $refresh = ecw_decrypt($mb['refresh_token_encrypted'] ?? null);
    if (!$refresh) throw new EcwCalendarConsentRequired('Connect Outlook to use your calendar.');
    $provider = new MicrosoftGraphProvider(ecw_graph_config() + ['account_tenant' => $mb['provider_tenant_id'] ?? 'organizations']);
    try {
        $tokens = $provider->refreshTokensForScopes($refresh, ['offline_access', 'https://graph.microsoft.com/Calendars.ReadWrite']);
    } catch (GraphException $e) {
        if (in_array($e->graphCode, ['invalid_grant', 'consent_required', 'interaction_required', 'invalid_scope'], true)) {
            throw new EcwCalendarConsentRequired('Enable calendar access for your Outlook account.');
        }
        throw $e;
    }
    $_SESSION['ecw_calendar_tokens'][$key] = ['token' => ecw_encrypt($tokens['access_token']), 'expires' => strtotime($tokens['expires_at'] . ' UTC')];
    if (!empty($tokens['refresh_token'])) {
        ecw_db()->prepare('UPDATE `' . ecw_table('mailboxes') . "` SET refresh_token_encrypted = ? WHERE id = ? AND sync_status <> 'disconnected'")->execute([ecw_encrypt($tokens['refresh_token']), $key]);
    }
    return $tokens['access_token'];
}

function ecw_calendar_client(int $orgId, int $userId): GraphHttp
{
    $mb = ecw_calendar_mailbox($orgId, $userId);
    if (!$mb) {
        require_once __DIR__ . '/account.php';
        $active = ecw_active_account($orgId, $userId);
        throw new EcwCalendarConsentRequired($active !== null && count(ecw_account_choices($orgId, $userId)) > 1
            ? 'This account has no Outlook calendar of yours (a shared or Gmail mailbox). Choose one of your Outlook accounts at the top.'
            : 'Connect Outlook to use your calendar.');
    }
    return new GraphHttp(static fn(): string => ecw_calendar_token($mb));
}

function ecw_calendar_instant(string $value): DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) throw new InvalidArgumentException('Choose a valid date and time.');
    try { $date = new DateTimeImmutable($value); } catch (Exception $e) { throw new InvalidArgumentException('Choose a valid date and time.'); }
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException('Choose a valid date and time.');
    return $date;
}

function ecw_calendar_event(array $row): array
{
    $allDay = !empty($row['isAllDay']);
    return [
        'id' => $row['id'], 'title' => $row['subject'] ?: '(No title)',
        'start' => $allDay ? substr($row['start']['dateTime'], 0, 10) : $row['start']['dateTime'] . 'Z',
        'end' => $allDay ? substr($row['end']['dateTime'], 0, 10) : $row['end']['dateTime'] . 'Z',
        'allDay' => $allDay, 'location' => $row['location']['displayName'] ?? '',
        'notes' => $row['bodyPreview'] ?? '', 'busy' => $row['showAs'] ?? 'busy',
        'cancelled' => !empty($row['isCancelled']), 'recurring' => ($row['type'] ?? 'singleInstance') !== 'singleInstance',
        'editable' => !empty($row['isOrganizer']) && empty($row['attendees']) && ($row['type'] ?? 'singleInstance') === 'singleInstance',
        'meeting' => $row['onlineMeeting']['joinUrl'] ?? null, 'url' => $row['webLink'] ?? null,
    ];
}

function ecw_calendar_events(GraphHttp $http, string $start, string $end): array
{
    $from = ecw_calendar_instant($start); $to = ecw_calendar_instant($end);
    if ($to <= $from || $to->getTimestamp() - $from->getTimestamp() > 63 * 86400) throw new InvalidArgumentException('Choose a range of up to 62 days.');
    $path = '/me/calendarView?' . http_build_query(['startDateTime' => $start, 'endDateTime' => $end, '$top' => 200, '$orderby' => 'start/dateTime', '$select' => 'id,subject,start,end,isAllDay,location,bodyPreview,showAs,isCancelled,type,isOrganizer,attendees,onlineMeeting,webLink']);
    $events = []; $pages = 0;
    do {
        $res = $http->request('GET', $path, null, ['Prefer: outlook.timezone="UTC"']);
        foreach ($res['body']['value'] ?? [] as $row) $events[] = ecw_calendar_event($row);
        $path = $res['body']['@odata.nextLink'] ?? null;
        if (++$pages >= 50 && $path) throw new RuntimeException('Too many events. Choose a shorter calendar view.');
    } while ($path);
    return $events;
}

function ecw_calendar_payload(array $input): array
{
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 255) throw new InvalidArgumentException('Enter a title of up to 255 characters.');
    $allDay = !empty($input['allDay']);
    if ($allDay) {
        $zone = (string)($input['timezone'] ?? 'UTC');
        if (!in_array($zone, DateTimeZone::listIdentifiers(), true) && $zone !== 'UTC') throw new InvalidArgumentException('Invalid timezone.');
        foreach (['start','end'] as $field) {
            $value = (string)($input[$field] ?? '');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Choose a valid date.');
            $times[$field] = ['dateTime' => $value . 'T00:00:00', 'timeZone' => $zone];
        }
        if ($times['end']['dateTime'] <= $times['start']['dateTime']) throw new InvalidArgumentException('End date must follow the start date.');
    } else {
        $from = ecw_calendar_instant((string)($input['start'] ?? '')); $to = ecw_calendar_instant((string)($input['end'] ?? ''));
        if ($to <= $from) throw new InvalidArgumentException('End time must follow the start time.');
        foreach (['start' => $from, 'end' => $to] as $field => $date) $times[$field] = ['dateTime' => $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'];
    }
    $location = trim((string)($input['location'] ?? '')); $notes = (string)($input['notes'] ?? '');
    if (mb_strlen($location) > 255 || mb_strlen($notes) > 10000) throw new InvalidArgumentException('Location or notes are too long.');
    $busy = (string)($input['busy'] ?? 'busy');
    if (!in_array($busy, ['free','tentative','busy','oof','workingElsewhere'], true)) throw new InvalidArgumentException('Invalid availability.');
    return ['subject' => $title, 'start' => $times['start'], 'end' => $times['end'], 'isAllDay' => $allDay, 'location' => ['displayName' => $location], 'body' => ['contentType' => 'text', 'content' => $notes], 'showAs' => $busy];
}

function ecw_calendar_save(GraphHttp $http, array $input): array
{
    $payload = ecw_calendar_payload($input);
    $id = (string)($input['id'] ?? '');
    if ($id !== '') {
        $path = '/me/events/' . rawurlencode($id);
        $existing = $http->request('GET', $path, null, ['Prefer: outlook.body-content-type="text"']);
        $row = $existing['body'];
        if (!ecw_calendar_event($row)['editable']) throw new InvalidArgumentException('Edit meetings and recurring events in Outlook.');
        // Preserve existing notes when the preview in the list was truncated.
        if (($input['notes'] ?? '') === ($row['bodyPreview'] ?? '')) unset($payload['body']);
        $res = $http->request('PATCH', $path, $payload, ['Prefer: outlook.timezone="UTC"']);
    } else {
        $transaction = (string)($input['transactionId'] ?? '');
        if (!preg_match('/^[a-f0-9-]{36}$/D', $transaction)) throw new InvalidArgumentException('Invalid event request. Reload and try again.');
        $payload['transactionId'] = $transaction;
        $res = $http->request('POST', '/me/events', $payload, ['Prefer: outlook.timezone="UTC"']);
    }
    return ecw_calendar_event($res['body']);
}

function ecw_calendar_delete(GraphHttp $http, string $id): void
{
    if ($id === '') throw new InvalidArgumentException('Choose an event.');
    $path = '/me/events/' . rawurlencode($id);
    $res = $http->request('GET', $path);
    if (!ecw_calendar_event($res['body'])['editable']) throw new InvalidArgumentException('Manage meetings and recurring events in Outlook.');
    $http->request('DELETE', $path);
}

/**
 * A Teams meeting in the user's Outlook calendar, for the link in an email
 * being written. Made without attendees first: only once Outlook has given it
 * a Teams link are the recipients added, which is when Outlook emails them the
 * invitation. A personal account or a tenant without Teams gets no link, and
 * the event is taken back.
 */
function ecw_calendar_teams_meeting(GraphHttp $http, array $input): array
{
    $title = trim((string)($input['title'] ?? '')) ?: 'Teams meeting';
    if (mb_strlen($title) > 255) throw new InvalidArgumentException('Enter a title of up to 255 characters.');
    $from = ecw_calendar_instant((string)($input['start'] ?? '')); $to = ecw_calendar_instant((string)($input['end'] ?? ''));
    if ($to <= $from || $to->getTimestamp() - $from->getTimestamp() > 86400) throw new InvalidArgumentException('Choose an end time after the start, on the same day.');
    $attendees = [];
    foreach ((array)($input['attendees'] ?? []) as $a) {
        $email = strtolower(trim((string)(is_array($a) ? ($a['email'] ?? '') : $a)));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($attendees[$email])) continue;
        $name = is_array($a) ? mb_substr(trim((string)($a['name'] ?? '')), 0, 255) : '';
        $attendees[$email] = ['emailAddress' => ['address' => $email, 'name' => $name !== '' ? $name : $email], 'type' => 'required'];
    }
    if (count($attendees) > 100) throw new InvalidArgumentException('Invite up to 100 people.');
    $transaction = (string)($input['transactionId'] ?? '');
    if (!preg_match('/^[a-f0-9-]{36}$/D', $transaction)) throw new InvalidArgumentException('Invalid meeting request. Reload and try again.');
    $utc = static fn(DateTimeImmutable $d): array => ['dateTime' => $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'];

    $res = $http->request('POST', '/me/events', [
        'subject' => $title, 'start' => $utc($from), 'end' => $utc($to), 'showAs' => 'busy',
        'isOnlineMeeting' => true, 'onlineMeetingProvider' => 'teamsForBusiness', 'transactionId' => $transaction,
    ], ['Prefer: outlook.timezone="UTC"'])['body'];
    $id = (string)($res['id'] ?? '');
    $join = $res['onlineMeeting']['joinUrl'] ?? null;
    if (!is_string($join) || !preg_match('#^https://#i', $join)) {
        if ($id !== '') $http->request('DELETE', '/me/events/' . rawurlencode($id), null, [], [404]);
        throw new RuntimeException('Outlook made no Teams link: Teams meetings need a work Microsoft 365 account with Teams.');
    }
    if ($attendees && $id !== '') {
        $http->request('PATCH', '/me/events/' . rawurlencode($id), ['attendees' => array_values($attendees)]);
    }
    return ['title' => $title, 'start' => $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        'end' => $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'join_url' => $join, 'invited' => count($attendees)];
}
