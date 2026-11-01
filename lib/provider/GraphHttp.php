<?php declare(strict_types=1);
/**
 * Email Case Workspace — Microsoft Graph HTTP transport.
 *
 * curl only, no SDK, matching the rest of this repo.
 *
 * THE TEST SEAM
 * -------------
 * Every request funnels through request(). When a test transport is installed
 * it answers instead of curl, which is the only way the sync pipeline, the
 * 410-resync path, throttling and the dead-letter invariant can be exercised
 * without a live Azure tenant. The seam is gated twice — CLI only, and only
 * when ECW_TESTING is defined before this file loads — so a web request can
 * never be served by a scripted transport.
 *
 * THROTTLING
 * ----------
 * Graph answers 429 (and 503 under load) with Retry-After. Those are retried
 * once after sleeping, on both the real and the test path: a seam that skips
 * the backoff makes "is throttling retried or dropped?" untestable, which is
 * the one question the seam exists to answer.
 *
 * PASS-THROUGH STATUSES
 * ---------------------
 * 410 Gone on a delta round is not an error, it is an instruction: the sync
 * state expired and the folder needs a fresh round from the Location header.
 * Callers declare such statuses instead of catching exceptions by message.
 */

require_once __DIR__ . '/../log.php';

final class GraphHttp
{
    public const BASE          = 'https://graph.microsoft.com/v1.0';
    public const LOGIN_BASE    = 'https://login.microsoftonline.com';
    /**
     * A slow line (an airport, a phone): a page of 100 emails with their text can take more than
     * half a minute to arrive, so a request may take 90 s in all. A dead connection is still cut
     * quickly: 15 s to connect, and nothing at all for 30 s ends it (LOW_SPEED).
     */
    private const TIMEOUT      = 90;
    private const CONNECT_TIMEOUT = 15;
    private const STALL_SECONDS   = 30;
    private const MAX_RETRY_WAIT = 60;

    /** @var null|callable(string,string,?array,array,?string):array */
    private static $testTransport = null;
    /** @var null|callable(int):void */
    private static $testSleep = null;

    /** @var null|callable():string lazily supplies a bearer token */
    private $tokenSupplier;

    public function __construct(?callable $tokenSupplier = null)
    {
        $this->tokenSupplier = $tokenSupplier;
    }

    public static function setTestTransport(?callable $transport): void
    {
        if (PHP_SAPI !== 'cli' || !defined('ECW_TESTING')) {
            throw new RuntimeException('ecw: the Graph test transport is CLI-and-ECW_TESTING only');
        }
        self::$testTransport = $transport;
    }

    public static function setTestSleep(?callable $sleep): void
    {
        if (PHP_SAPI !== 'cli' || !defined('ECW_TESTING')) {
            throw new RuntimeException('ecw: the Graph test sleep hook is CLI-and-ECW_TESTING only');
        }
        self::$testSleep = $sleep;
    }

    public static function testTransport(): ?callable
    {
        if (PHP_SAPI !== 'cli' || !defined('ECW_TESTING')) {
            return null;
        }
        return self::$testTransport;
    }

    private function sleep(int $seconds): void
    {
        if (self::$testSleep !== null) {
            (self::$testSleep)($seconds);
            return;
        }
        sleep($seconds);
    }

    /**
     * @param array $passThrough statuses returned to the caller instead of throwing
     * @return array{status:int, body:array, headers:array<string,string>}
     */
    public function request(
        string $method,
        string $urlOrPath,
        ?array $json = null,
        array $extraHeaders = [],
        array $passThrough = [],
        bool $retry = true
    ): array {
        $url = str_starts_with($urlOrPath, 'http') ? $urlOrPath : self::BASE . $urlOrPath;

        $transport = self::testTransport();
        if ($transport !== null) {
            // The token is asked for exactly as in production, so tests see an
            // expired or revoked sign-in (refresh, needs_reauth) the way users do.
            if ($this->tokenSupplier !== null) ($this->tokenSupplier)();
            try {
                $res = $transport($method, $url, $json, $extraHeaders, null);
            } catch (GraphException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                // As below: a read that broke off is asked once more.
                if (!$retry || strtoupper($method) !== 'GET' || self::ranOutOfTime($e)) throw $e;
                $this->sleep(2);
                return $this->request($method, $url, $json, $extraHeaders, $passThrough, false);
            }
            return $this->finish($method, $url, $res, $passThrough, $retry, $json, $extraHeaders);
        }

        $headers = ['Accept: application/json'];
        if ($this->tokenSupplier !== null) {
            $headers[] = 'Authorization: Bearer ' . ($this->tokenSupplier)();
        }
        $headers = array_merge($headers, $extraHeaders);

        $payload = null;
        if ($json !== null) {
            $payload   = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        } elseif (in_array(strtoupper($method), ['POST', 'PATCH', 'PUT'], true)) {
            // Graph action endpoints (/send, /move) take no body. Without an
            // explicit empty one curl omits Content-Length and Graph answers
            // 411, then guesses form-encoding for the Content-Type — so pin both.
            $payload   = '';
            $headers[] = 'Content-Length: 0';
            $headers[] = 'Content-Type: application/json';
        }

        try {
            $res = $this->curl($method, $url, $payload, $headers);
        } catch (RuntimeException $e) {
            // A read that broke off on a bad line is asked once more (reading twice changes
            // nothing); a send or a change is not, as it may have arrived.
            // Ran out of time: not again at the same size (the sync asks in smaller pages instead).
            if (!$retry || strtoupper($method) !== 'GET' || self::ranOutOfTime($e)) throw $e;
            ecw_log('Graph GET broke off, trying once more: ' . $e->getMessage(), ['url' => $this->safeUrl($url)]);
            $this->sleep(2);
            return $this->request($method, $url, $json, $extraHeaders, $passThrough, false);
        }
        return $this->finish($method, $url, $res, $passThrough, $retry, $json, $extraHeaders);
    }

    /**
     * Raw bytes instead of JSON: a piece of a large Outlook attachment (an upload session's
     * address, which carries its own authorization: $bearer false) or a Gmail upload
     * (message/rfc822 inside multipart). Never retried here: the caller decides, as only it
     * knows whether sending the same bytes again is safe.
     *
     * @param array $passThrough statuses returned to the caller instead of throwing
     * @return array{status:int, body:array, headers:array<string,string>}
     */
    public function raw(string $method, string $url, string $body, string $contentType, array $extraHeaders = [], bool $bearer = true, array $passThrough = []): array
    {
        $headers = array_merge(['Content-Type: ' . $contentType], $extraHeaders);
        $transport = self::testTransport();
        if ($transport !== null) {
            if ($bearer && $this->tokenSupplier !== null) ($this->tokenSupplier)();
            $res = $transport($method, $url, null, $headers + ($bearer ? [] : ['no-bearer' => true]), $body);
            return $this->finish($method, $url, $res, $passThrough, false, null, []);
        }
        $headers[] = 'Accept: application/json';
        $headers[] = 'Content-Length: ' . strlen($body);
        if ($bearer && $this->tokenSupplier !== null) {
            $headers[] = 'Authorization: Bearer ' . ($this->tokenSupplier)();
        }
        $res = $this->curl($method, $url, $body, $headers);
        return $this->finish($method, $url, $res, $passThrough, false, null, []);
    }

    /** OAuth token endpoint: form-encoded, no bearer, different host. */
    public function form(string $url, array $fields): array
    {
        $transport = self::testTransport();
        if ($transport !== null) {
            $res = $transport('POST', $url, $fields, ['form' => true], null);
            return $this->finish('POST', $url, $res, [], false, $fields, []);
        }

        $res = $this->curl('POST', $url, http_build_query($fields), [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        return $this->finish('POST', $url, $res, [], false, null, []);
    }

    /** The request was too slow (time limit, or a stalled line), not cut. */
    private static function ranOutOfTime(Throwable $e): bool
    {
        return (bool)preg_match('/timed out|too slow/i', $e->getMessage());
    }

    private function curl(string $method, string $url, ?string $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME  => self::STALL_SECONDS,
            CURLOPT_HEADER         => true,
        ] + ($payload !== null ? [CURLOPT_POSTFIELDS => $payload] : []));

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hdrLen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new RuntimeException("ecw: Graph {$method} transport error: {$error}");
        }

        $rawHeaders = substr((string)$raw, 0, $hdrLen);
        $rawBody    = substr((string)$raw, $hdrLen);

        $headersOut = [];
        foreach (preg_split('/\R/', $rawHeaders) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headersOut[strtolower(trim($k))] = trim($v);
            }
        }

        $body = [];
        if (trim($rawBody) !== '') {
            $decoded = json_decode($rawBody, true);
            $body    = is_array($decoded) ? $decoded : ['raw' => $rawBody];
        }

        return ['status' => $status, 'body' => $body, 'headers' => $headersOut];
    }

    /** Shared response handling: retry, pass-through, error shaping. */
    private function finish(
        string $method,
        string $url,
        array $res,
        array $passThrough,
        bool $retry,
        ?array $json,
        array $extraHeaders
    ): array {
        $status  = (int)($res['status'] ?? 0);
        $body    = is_array($res['body'] ?? null) ? $res['body'] : [];
        $headers = [];
        foreach ((array)($res['headers'] ?? []) as $k => $v) {
            $headers[strtolower((string)$k)] = (string)$v;
        }

        if (($status === 429 || $status === 503) && $retry && !in_array($status, $passThrough, true)) {
            $wait = 5;
            if (isset($headers['retry-after']) && ctype_digit($headers['retry-after'])) {
                $wait = min((int)$headers['retry-after'], self::MAX_RETRY_WAIT);
            }
            ecw_log("throttled by Graph ({$status}), sleeping {$wait}s", ['url' => $this->safeUrl($url)]);
            $this->sleep($wait);
            return $this->request($method, $url, $json, $extraHeaders, $passThrough, false);
        }

        if ($status >= 400 && !in_array($status, $passThrough, true)) {
            $error = $body['error'] ?? 'unknown';
            $code = is_array($error) ? (string)($error['code'] ?? 'unknown') : (string)$error;
            // Provider descriptions can echo credentials. Retain only the error code.
            $msg = '';
            if (!preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $code)) { $code = 'provider_error'; }
            throw new GraphException(
                "ecw: Graph {$method} {$this->safeUrl($url)} -> HTTP {$status} {$code} {$msg}",
                $status,
                $code
            );
        }

        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    /** URLs carry $deltatoken / $skiptoken / code — never log them raw. */
    private function safeUrl(string $url): string
    {
        return ecw_redact($url);
    }
}

final class GraphException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $graphCode = '')
    {
        parent::__construct($message);
    }

    /** The token is dead and no refresh will fix it — the user must sign in again. */
    public function needsReauth(): bool
    {
        if ($this->status === 401) {
            return true;
        }
        return in_array($this->graphCode, [
            'InvalidAuthenticationToken', 'invalid_grant', 'AuthenticationError', 'CompactToken',
        ], true);
    }

    /** Worth retrying later rather than surfacing to the user. */
    public function isTransient(): bool
    {
        return in_array($this->status, [408, 429, 500, 502, 503, 504], true);
    }
}
