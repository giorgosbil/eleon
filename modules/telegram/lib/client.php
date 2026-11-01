<?php declare(strict_types=1);
/**
 * The few Telegram calls the background process makes (lib/sync.php), behind
 * one interface: EcwTgMadeline does them with MadelineProto (a Telegram client
 * library, vendor/danog/madelineproto), the tests with a script.
 *
 * Messages, users and updates are Telegram's own (MTProto) arrays, as
 * MadelineProto returns them: a peer is a number (a person > 0, a group < 0).
 */

interface EcwTgClient
{
    /** Ask Telegram to send the login code. @return string where it went, in words */
    public function sendCode(string $phone): string;
    /** The code. @return ?string null when signed in, else the 2-step password hint ('' without one) */
    public function signIn(string $code): ?string;
    public function checkPassword(string $password): void;
    /** The signed-in user (id, first_name, last_name, username, phone). */
    public function me(): array;
    /** New updates since the last call (never waits). @return list<array> */
    public function updates(): array;
    /** A user's details, or null. */
    public function user(int $peer): ?array;
    /** @return list<array> the contacts' users */
    public function contacts(): array;
    /** @return array{dialogs: list<array>, messages: list<array>, users: list<array>} */
    public function dialogs(int $limit): array;
    /** @return array{messages: list<array>, users: list<array>} newest first */
    public function history(int $peer, int $limit): array;
    /** @return int the message id */
    public function sendText(int $peer, string $text): int;
    /** @return int the message id */
    public function sendFile(int $peer, string $path, string $name, string $mime, string $caption, bool $asPhoto): int;
    public function download(array $media, string $path): void;
    public function read(int $peer, int $maxId): void;
    /** Add a phone number to the contacts. @return ?array its user, null when the number is not on Telegram */
    public function importContact(string $phone, string $first, string $last): ?array;
    public function logout(): void;
}

/** EcwTgClient through MadelineProto. One per account; its session folder holds the sign-in. */
final class EcwTgMadeline implements EcwTgClient
{
    private \danog\MadelineProto\API $api;
    private int $offset = 0;

    public function __construct(string $sessionDir, int $apiId, string $apiHash)
    {
        $settings = new \danog\MadelineProto\Settings;
        $settings->getAppInfo()->setApiId($apiId)->setApiHash($apiHash)->setShowPrompt(false)
            ->setDeviceModel('Other Things')->setSystemVersion('Server')->setAppVersion('1.0');
        $settings->getLogger()->setType(\danog\MadelineProto\Logger::FILE_LOGGER)
            ->setExtra($sessionDir . '/madeline.log')->setLevel(\danog\MadelineProto\Logger::LEVEL_WARNING)->setMaxSize(2 * 1024 * 1024);
        $this->api = new \danog\MadelineProto\API($sessionDir . '/session.madeline', $settings);
    }

    public function loggedIn(): bool
    {
        return $this->api->getAuthorization() === \danog\MadelineProto\API::LOGGED_IN;
    }

    public function sendCode(string $phone): string
    {
        $sent = $this->api->phoneLogin($phone);
        return match ($sent['type']['_'] ?? '') {
            'auth.sentCodeTypeApp' => 'in the Telegram app on your phone (a message from “Telegram”)',
            'auth.sentCodeTypeSms', 'auth.sentCodeTypeSmsWord', 'auth.sentCodeTypeSmsPhrase' => 'by SMS',
            'auth.sentCodeTypeCall', 'auth.sentCodeTypeFlashCall', 'auth.sentCodeTypeMissedCall' => 'by a phone call',
            'auth.sentCodeTypeEmailCode' => 'to your login email',
            'auth.sentCodeTypeFragmentSms' => 'on fragment.com',
            default => 'in Telegram',
        };
    }

    public function signIn(string $code): ?string
    {
        $r = $this->api->completePhoneLogin($code);
        if (($r['_'] ?? '') === 'account.password') return (string)($r['hint'] ?? '');
        if (($r['_'] ?? '') === 'account.needSignup') throw new RuntimeException('This phone number has no Telegram account. Make one in the Telegram app first.');
        return null;
    }

    public function checkPassword(string $password): void
    {
        $this->api->complete2faLogin($password);
    }

    public function me(): array
    {
        return (array)$this->api->getSelf();
    }

    public function updates(): array
    {
        $out = [];
        foreach ($this->api->getUpdates(['offset' => $this->offset, 'timeout' => 0.0]) as $u) {
            $this->offset = (int)$u['update_id'] + 1;
            $out[] = $u['update'];
        }
        return $out;
    }

    public function user(int $peer): ?array
    {
        try {
            $info = $this->api->getInfo($peer);
        } catch (Throwable $e) {
            return null;
        }
        return isset($info['User']) ? (array)$info['User'] : null;
    }

    public function contacts(): array
    {
        return (array)($this->api->contacts->getContacts()['users'] ?? []);
    }

    public function dialogs(int $limit): array
    {
        $r = $this->api->messages->getDialogs(offset_peer: ['_' => 'inputPeerEmpty'], limit: $limit);
        return ['dialogs' => (array)($r['dialogs'] ?? []), 'messages' => (array)($r['messages'] ?? []), 'users' => (array)($r['users'] ?? [])];
    }

    public function history(int $peer, int $limit): array
    {
        $r = $this->api->messages->getHistory(peer: $peer, limit: $limit);
        return ['messages' => (array)($r['messages'] ?? []), 'users' => (array)($r['users'] ?? [])];
    }

    public function sendText(int $peer, string $text): int
    {
        return $this->api->sendMessage(peer: $peer, message: $text, noWebpage: false)->id;
    }

    public function sendFile(int $peer, string $path, string $name, string $mime, string $caption, bool $asPhoto): int
    {
        $file = new \danog\MadelineProto\LocalFile($path);
        return $asPhoto
            ? $this->api->sendPhoto(peer: $peer, file: $file, caption: $caption, fileName: $name)->id
            : $this->api->sendDocument(peer: $peer, file: $file, caption: $caption, fileName: $name, mimeType: $mime)->id;
    }

    public function download(array $media, string $path): void
    {
        $this->api->downloadToFile($media, $path);
    }

    public function read(int $peer, int $maxId): void
    {
        $this->api->messages->readHistory(peer: $peer, max_id: $maxId);
    }

    public function importContact(string $phone, string $first, string $last): ?array
    {
        $r = $this->api->contacts->importContacts(contacts: [['_' => 'inputPhoneContact', 'client_id' => random_int(1, PHP_INT_MAX), 'phone' => $phone, 'first_name' => $first, 'last_name' => $last]]);
        $u = $r['users'][0] ?? null;
        return is_array($u) ? $u : null;
    }

    public function logout(): void
    {
        $this->api->logout();
    }
}
