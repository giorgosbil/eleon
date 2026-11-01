<?php declare(strict_types=1);
/**
 * A scripted Microsoft Graph, for tests.
 *
 * Routes are matched by method plus a regex on the URL, and each route holds a
 * queue of responses so a test can script "page 1, page 2, then the deltaLink".
 * Every call is recorded, which is how tests assert that a cursor was actually
 * followed rather than that the code merely did not crash.
 */
final class FakeGraph
{
    /** @var array<int,array{method:string,pattern:string,responses:array}> */
    private array $routes = [];
    /** @var array<int,array{method:string,url:string,json:?array,headers:array,raw:?string}> raw: bytes sent by GraphHttp::raw() */
    public array $calls = [];
    /** @var array<int,int> seconds passed to each sleep */
    public array $sleeps = [];

    public function on(string $method, string $pattern, array ...$responses): self
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'responses' => $responses];
        return $this;
    }

    public function install(): void
    {
        GraphHttp::setTestTransport(function (string $method, string $url, ?array $json, array $headers, ?string $raw): array {
            $this->calls[] = ['method' => strtoupper($method), 'url' => $url, 'json' => $json, 'headers' => $headers, 'raw' => $raw];

            // "Did another copy of the app send this already?" (sentWithKey): no, unless a test
            // scripts it with a route of its own that names EcwSendKey.
            $askedKey = str_contains(rawurldecode($url), 'EcwSendKey');
            // "Did another copy of the app start a case from this email?" (caseMarkOf) and marking
            // it (markCase): no mark, and marking works, unless a route names EcwAutoCase.
            $caseMark = str_contains(rawurldecode($url), 'EcwAutoCase') || str_contains((string)json_encode($json), 'EcwAutoCase');
            foreach ($this->routes as &$route) {
                if ($route['method'] !== strtoupper($method) || !preg_match($route['pattern'], $url)) {
                    continue;
                }
                if ($askedKey && !str_contains($route['pattern'], 'EcwSendKey')) {
                    continue;
                }
                if ($caseMark && !str_contains($route['pattern'], 'EcwAutoCase')) {
                    continue;
                }
                if ($route['responses'] === []) {
                    throw new RuntimeException("FakeGraph: route {$route['pattern']} ran out of responses");
                }
                // The last response repeats, so a test need not script every
                // identical poll.
                $response = count($route['responses']) > 1
                    ? array_shift($route['responses'])
                    : $route['responses'][0];
                return $response + ['status' => 200, 'body' => [], 'headers' => []];
            }

            if ($askedKey) return ['status' => 200, 'body' => ['value' => []], 'headers' => []];
            if ($caseMark) return ['status' => 200, 'body' => ['id' => 'm', 'singleValueExtendedProperties' => []], 'headers' => []];
            throw new RuntimeException("FakeGraph: no route for {$method} {$url}");
        });

        GraphHttp::setTestSleep(function (int $seconds): void {
            $this->sleeps[] = $seconds;
        });
    }

    public function callsMatching(string $pattern): array
    {
        return array_values(array_filter($this->calls, static fn($c) => (bool)preg_match($pattern, $c['url'])));
    }

    public static function uninstall(): void
    {
        GraphHttp::setTestTransport(null);
        GraphHttp::setTestSleep(null);
    }

    /** A Graph message resource, with only the fields a test cares about set. */
    public static function message(array $overrides = []): array
    {
        $id = $overrides['id'] ?? ('AAMk' . bin2hex(random_bytes(4)));
        return array_merge([
            'id'                   => $id,
            'internetMessageId'    => '<' . $id . '@example.com>',
            'conversationId'       => 'conv-' . $id,
            'subject'              => 'Test subject',
            'from'                 => ['emailAddress' => ['address' => 'john@customer.com', 'name' => 'John Smith']],
            'toRecipients'         => [['emailAddress' => ['address' => 'ops@example.com', 'name' => 'Ops']]],
            'ccRecipients'         => [],
            'sentDateTime'         => '2026-09-17T09:02:00Z',
            'receivedDateTime'     => '2026-09-17T09:02:00Z',
            'createdDateTime'      => '2026-09-17T09:02:00Z',
            'lastModifiedDateTime' => '2026-09-17T09:02:00Z',
            'isRead'               => false,
            'isDraft'              => false,
            'hasAttachments'       => false,
            'webLink'              => 'https://outlook.office.com/mail/id/' . $id,
        ], $overrides);
    }
}
