<?php

declare(strict_types=1);

namespace OilPriceAPI;

use Closure;
use OilPriceAPI\Exception\ApiException;
use OilPriceAPI\Exception\AuthenticationException;
use OilPriceAPI\Exception\RateLimitException;
use OilPriceAPI\Http\CurlTransport;
use OilPriceAPI\Http\HttpResponse;
use OilPriceAPI\Http\HttpTransport;

/**
 * OilPriceAPI client.
 *
 * Quick start:
 *
 *     $client = new \OilPriceAPI\Client('your_api_key'); // or set OILPRICEAPI_KEY
 *     $brent  = $client->latest('BRENT_CRUDE_USD');
 *     echo $brent->price; // e.g. XX.XX
 *
 * No API key? Demo mode works out of the box:
 *
 *     $client = new \OilPriceAPI\Client();
 *     $prices = $client->demoPrices();
 */
final class Client
{
    public const VERSION = '3.0.0';
    public const DEFAULT_BASE_URL = 'https://api.oilpriceapi.com';
    public const DEFAULT_TIMEOUT = 10.0;
    public const DEFAULT_MAX_RETRIES = 3;

    /** Maximum backoff sleep between retries, in seconds. */
    private const MAX_BACKOFF_SECONDS = 30.0;

    /**
     * The one endpoint family that answers without an API key. Matched on a
     * segment boundary - see {@see self::isDemoPath()} - never as a raw prefix.
     */
    private const DEMO_PATH_ROOT = '/v1/demo';

    /**
     * Error codes for limits that do not refill inside any backoff this client
     * could sleep. Retrying one of these cannot succeed - it only spends more
     * requests against a limit that is already exhausted. Compared
     * case-insensitively against `error_code`, `error.code` and `code`.
     *
     * @var list<string>
     */
    private const DURABLE_QUOTA_CODES = [
        'MONTHLY_QUOTA_EXCEEDED',
        'DAILY_QUOTA_EXCEEDED',
        'QUOTA_EXCEEDED',
        'TRIAL_LIMIT_EXCEEDED',
        'TRIAL_EXPIRED',
        'EMAIL_CONFIRMATION_REQUIRED',
        'DEMO_RATE_LIMIT_EXCEEDED',
    ];

    private readonly ?string $apiKey;
    private readonly string $baseUrl;
    private readonly HttpTransport $transport;
    /** @var Closure(float): void */
    private readonly Closure $sleeper;

    /**
     * @param string|null   $apiKey     API key; falls back to the OILPRICEAPI_KEY
     *                                  environment variable. May be omitted entirely
     *                                  for keyless demo mode ({@see demoPrices()}).
     * @param string        $baseUrl    Override the API base URL (rarely needed)
     * @param float         $timeout    Per-request timeout in seconds (default 10)
     * @param int           $maxRetries Retries on 429/5xx with exponential backoff (default 3)
     * @param HttpTransport|null $transport Custom transport (used by tests); defaults to cURL
     * @param callable|null $sleeper    Injectable sleep function for tests; fn (float $seconds): void
     */
    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        private readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
        ?HttpTransport $transport = null,
        ?callable $sleeper = null,
    ) {
        $envKey = getenv('OILPRICEAPI_KEY');
        $key = $apiKey ?? ($envKey !== false ? $envKey : null);
        $this->apiKey = ($key !== null && trim($key) !== '') ? trim($key) : null;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? new CurlTransport();
        $this->sleeper = $sleeper !== null
            ? $sleeper(...)
            : static function (float $seconds): void {
                usleep((int) round($seconds * 1_000_000));
            };
    }

    /**
     * Whether this client was constructed with an API key.
     */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== null;
    }

    /**
     * Get the latest price(s).
     *
     * With a commodity code, returns a single {@see Price}:
     *
     *     $brent = $client->latest('BRENT_CRUDE_USD');
     *
     * Without one, production returns the default latest price. Legacy API
     * responses containing a `prices` array are retained as a list for
     * backward compatibility. Pass a code for a predictable single result.
     *
     * @return Price|list<Price>
     */
    public function latest(?string $byCode = null): Price|array
    {
        $params = $byCode !== null ? ['by_code' => $byCode] : [];
        $body = $this->request('/v1/prices/latest', $params);
        $data = $this->dataOrFail($body, '/v1/prices/latest');

        if (isset($data['prices']) && is_array($data['prices'])) {
            if ($data['prices'] === []) {
                throw new ApiException('Unexpected latest price shape from /v1/prices/latest.', 200, $body);
            }

            return array_map(
                fn (mixed $price): Price => $this->priceOrFail($price, $body, '/v1/prices/latest'),
                array_values($data['prices']),
            );
        }

        return $this->priceOrFail($data, $body, '/v1/prices/latest');
    }

    /**
     * Prices from the past 24 hours.
     *
     * @return list<Price>
     */
    public function pastDay(?string $byCode = null): array
    {
        return $this->historical('past_day', $byCode);
    }

    /**
     * Prices from the past week.
     *
     * @return list<Price>
     */
    public function pastWeek(?string $byCode = null): array
    {
        return $this->historical('past_week', $byCode);
    }

    /**
     * Prices from the past month.
     *
     * @return list<Price>
     */
    public function pastMonth(?string $byCode = null): array
    {
        return $this->historical('past_month', $byCode);
    }

    /**
     * Prices from the past year.
     *
     * @return list<Price>
     */
    public function pastYear(?string $byCode = null): array
    {
        return $this->historical('past_year', $byCode);
    }

    /**
     * Demo prices - works WITHOUT an API key (rate limited per IP).
     *
     * @return list<Price>
     */
    public function demoPrices(): array
    {
        $body = $this->request('/v1/demo/prices', []);
        $data = $this->dataOrFail($body, '/v1/demo/prices');

        return $this->priceListOrFail($data, $body, '/v1/demo/prices');
    }

    /**
     * Escape hatch: call a versioned GET endpoint and get its decoded envelope.
     *
     *     $curve = $client->raw()->get('/v1/futures/brent/curve');
     */
    public function raw(): RawClient
    {
        return new RawClient(fn (string $path, array $params): array => $this->request($path, $params));
    }

    /**
     * @return list<Price>
     */
    private function historical(string $period, ?string $byCode): array
    {
        $params = $byCode !== null ? ['by_code' => $byCode] : [];
        $path = '/v1/prices/' . $period;
        $body = $this->request($path, $params);
        $data = $this->dataOrFail($body, $path);

        return $this->priceListOrFail($data, $body, $path);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function dataOrFail(array $body, string $path): array
    {
        if (($body['status'] ?? null) !== 'success' || !is_array($body['data'] ?? null)) {
            throw new ApiException(
                sprintf('Unexpected response shape from %s.', $path),
                200,
                $body,
            );
        }

        return $body['data'];
    }

    /**
     * Decode a list-returning endpoint.
     *
     * A missing or non-array `prices` field is a malformed envelope, not an
     * empty result: returning [] for it reports "no data" for a response the
     * SDK simply failed to understand. An actual empty list stays empty.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $body
     *
     * @return list<Price>
     */
    private function priceListOrFail(array $data, array $body, string $path): array
    {
        if (!array_key_exists('prices', $data) || !is_array($data['prices'])) {
            throw new ApiException(
                sprintf('Unexpected response shape from %s: no price list in the envelope.', $path),
                200,
                $body,
            );
        }

        return array_map(
            fn (mixed $row): Price => $this->priceOrFail($row, $body, $path),
            array_values($data['prices']),
        );
    }

    /**
     * The single validated price-row boundary shared by latest, history and demo.
     *
     * @param array<string, mixed> $body
     */
    private function priceOrFail(mixed $row, array $body, string $path): Price
    {
        if (is_array($row)) {
            try {
                return Price::fromArray($row);
            } catch (ApiException $e) {
                throw new ApiException($this->malformedRowMessage($path, $e->getMessage()), 200, $body);
            }
        }

        throw new ApiException($this->malformedRowMessage($path, 'Price row is not an object.'), 200, $body);
    }

    private function malformedRowMessage(string $path, string $detail): string
    {
        if ($path === '/v1/prices/latest') {
            return 'Unexpected latest price shape from /v1/prices/latest. ' . $detail;
        }

        return sprintf('Unexpected price row in the response from %s. %s', $path, $detail);
    }

    /**
     * Perform a GET request with retries and typed error mapping.
     *
     * @param array<string, scalar> $params
     *
     * @return array<string, mixed> Decoded JSON body
     */
    private function request(string $rawPath, array $params): array
    {
        $path = $this->normalizeApiPath($rawPath);
        $isDemo = self::isDemoPath($path);

        if (!$isDemo && $this->apiKey === null) {
            throw new AuthenticationException(
                'No API key configured. Pass one to the Client constructor or set the '
                . 'OILPRICEAPI_KEY environment variable. (Keyless demo mode is available '
                . 'via $client->demoPrices().)',
                0,
            );
        }

        $url = $this->baseUrl . $path;
        $this->assertSameOrigin($url, $rawPath);
        if ($params !== []) {
            $url .= (str_contains($path, '?') ? '&' : '?') . http_build_query($params);
        }

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'oilpriceapi-php/' . self::VERSION,
        ];
        if (!$isDemo && $this->apiKey !== null) {
            $headers['Authorization'] = 'Token ' . $this->apiKey;
        }

        $attempts = max(1, $this->maxRetries + 1);
        $response = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $response = $this->transport->request('GET', $url, $headers, $this->timeout);

            if (!$this->isRetryable($response) || $attempt === $attempts - 1) {
                break;
            }

            $delay = $this->retryDelay($attempt, $response);
            if ($delay === null) {
                // The server asked us to wait longer than this client's budget.
                // Coming back early would be a second refusal, so stop and let
                // the caller schedule the retry with the guidance on the
                // exception.
                break;
            }

            ($this->sleeper)($delay);
        }

        assert($response instanceof HttpResponse);

        return $this->handleResponse($response, $path);
    }

    /**
     * Normalize a caller-supplied API path to a single leading slash and reject
     * anything that could graft a new authority onto the configured base URL.
     *
     * The base URL and the path are concatenated, so a path that does not start
     * with '/' can turn the base host into URL userinfo
     * ('@evil.tld/v1/prices' resolves to host 'evil.tld' while the SDK still
     * attaches 'Authorization: Token <key>'). Reject those before the
     * credential is ever assembled.
     */
    /**
     * Is this path served by keyless demo mode?
     *
     * Matched on a segment boundary: exactly self::DEMO_PATH_ROOT, or a path
     * below it. A raw prefix match also swallowed paths that merely start with
     * the same characters but are answered by an authenticated endpoint -
     * /v1/demographics, /v1/demo-prices - which then went out with no
     * Authorization header, came back 401, and were reported to the caller as
     * "Invalid API key." about a key that was perfectly valid (#23).
     *
     * Dot segments are never demo. The server resolves them, so the path we
     * inspect is not the endpoint that answers: /v1/demo/../prices/latest is
     * really /v1/prices/latest and needs the key. Percent-encoded forms count,
     * because some servers decode before resolving. Erring toward "not demo"
     * is the safe direction - the worst case is sending a valid key to our own
     * origin (already guarded by assertSameOrigin), rather than withholding it.
     */
    private static function isDemoPath(string $path): bool
    {
        // Only the part before the query/fragment names the endpoint.
        $bare = substr($path, 0, strcspn($path, '?#'));

        foreach (explode('/', $bare) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..') {
                return false;
            }
        }

        return $bare === self::DEMO_PATH_ROOT
            || str_starts_with($bare, self::DEMO_PATH_ROOT . '/');
    }

    private function normalizeApiPath(string $path): string
    {
        if ($path === '') {
            throw new ApiException('API path must not be empty; use a path such as /v1/prices/latest.');
        }

        if (preg_match('/[\x00-\x20\x7f]/', $path) === 1) {
            throw $this->offOriginPath($path, 'whitespace and control characters are not allowed');
        }

        if (str_contains($path, '\\')) {
            throw $this->offOriginPath($path, 'backslashes are normalized to slashes by URL parsers');
        }

        // Everything before the query/fragment decides the authority.
        $bare = substr($path, 0, strcspn($path, '?#'));

        if (str_starts_with($bare, '//')) {
            throw $this->offOriginPath($path, 'scheme-relative references change the host');
        }

        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $bare) === 1) {
            throw $this->offOriginPath($path, 'absolute URLs are not accepted; pass a path such as /v1/prices/latest');
        }

        if (!str_starts_with($path, '/')) {
            $firstSegment = substr($bare, 0, strcspn($bare, '/'));
            if (str_contains($firstSegment, '@')) {
                throw $this->offOriginPath($path, 'userinfo would replace the API host');
            }

            $path = '/' . $path;
        }

        return $path;
    }

    /**
     * Defense in depth: the resolved URL must keep the configured origin.
     */
    private function assertSameOrigin(string $url, string $rawPath): void
    {
        $base = parse_url($this->baseUrl);
        $resolved = parse_url($url);

        if (!is_array($base) || !is_array($resolved)) {
            throw $this->offOriginPath($rawPath, 'the resolved URL could not be parsed');
        }

        if (self::originOf($base) !== self::originOf($resolved)) {
            throw $this->offOriginPath($rawPath, 'the resolved origin differs from the configured base URL');
        }
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function originOf(array $parts): string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $defaultPorts = ['http' => 80, 'https' => 443];
        $port = $parts['port'] ?? ($defaultPorts[$scheme] ?? null);
        // Any userinfo at all is a mismatch: the configured base URL carries none.
        $userInfo = isset($parts['user']) || isset($parts['pass']) ? 'userinfo@' : '';

        return $scheme . '://' . $userInfo . $host . ':' . ($port === null ? '' : (string) $port);
    }

    private function offOriginPath(string $path, string $reason): ApiException
    {
        return new ApiException(sprintf(
            'Refusing to send the API key to a different origin: path %s is rejected because %s. '
            . 'Pass an API path (for example /v1/prices/latest); use the $baseUrl constructor '
            . 'argument for an intentional proxy or test server.',
            var_export($path, true),
            $reason,
        ));
    }

    private function isRetryable(HttpResponse $response): bool
    {
        if ($response->statusCode >= 500) {
            return true;
        }

        return $response->statusCode === 429 && !$this->isDurableQuotaExhausted($response);
    }

    /**
     * Whether a 429 reports a limit that will not refill inside a retry window.
     */
    private function isDurableQuotaExhausted(HttpResponse $response): bool
    {
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return false;
        }

        $candidates = [
            $decoded['error_code'] ?? null,
            $decoded['code'] ?? null,
            is_array($decoded['error'] ?? null) ? ($decoded['error']['code'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array(strtoupper(trim($candidate)), self::DURABLE_QUOTA_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delay before the next attempt, or null when the server's minimum delay
     * exceeds this client's budget and the request must not be retried.
     *
     * Exponential backoff with full jitter when the server gave no usable
     * instruction; the server's own Retry-After wins when it did.
     */
    private function retryDelay(int $attempt, HttpResponse $response): ?float
    {
        $retryAfter = $this->parseRetryAfter($response);
        if ($retryAfter !== null) {
            if ($retryAfter > self::MAX_BACKOFF_SECONDS) {
                return null;
            }

            return (float) $retryAfter;
        }

        $base = min(0.5 * (2 ** $attempt), self::MAX_BACKOFF_SECONDS);
        $jitter = mt_rand(0, 1000) / 1000 * ($base / 2);

        return min($base + $jitter, self::MAX_BACKOFF_SECONDS);
    }

    /**
     * Retry-After in seconds, or null when absent or malformed.
     *
     * A negative delta-seconds value is malformed, not an instruction to retry
     * immediately, so it is discarded in favour of normal backoff. An HTTP-date
     * already in the past does mean "now", and becomes 0.
     */
    private function parseRetryAfter(HttpResponse $response): ?int
    {
        $value = $response->header('Retry-After');
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $seconds = (int) $value;

            return $seconds < 0 ? null : $seconds;
        }

        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return max(0, $timestamp - time());
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(HttpResponse $response, string $path): array
    {
        $decoded = json_decode($response->body, true);
        $body = is_array($decoded) ? $decoded : [];

        if ($response->statusCode === 401) {
            throw new AuthenticationException(
                $this->errorMessage($body, 'Invalid API key'),
                401,
                $body,
            );
        }

        if ($response->statusCode === 429) {
            throw new RateLimitException(
                $this->errorMessage($body, 'Rate limit exceeded'),
                $this->parseRetryAfter($response),
                $response->header('X-RateLimit-Limit'),
                $body,
            );
        }

        if ($response->statusCode === 403) {
            throw new ApiException(
                $this->errorMessage($body, sprintf('Access to %s is not included in your plan', $path))
                . ' Review https://www.oilpriceapi.com/pricing?utm_source=php-sdk-limit for current access options.',
                403,
                $body,
            );
        }

        if ($response->statusCode >= 400) {
            throw new ApiException(
                sprintf('API request to %s failed: %s', $path, $this->errorMessage($body, 'HTTP ' . $response->statusCode)),
                $response->statusCode,
                $body,
            );
        }

        if (!is_array($decoded)) {
            throw new ApiException(
                sprintf('API returned invalid JSON from %s.', $path),
                $response->statusCode,
            );
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function errorMessage(array $body, string $fallback): string
    {
        // Production error envelope: {"error": {"code": ..., "message": ...}}
        if (isset($body['error']['message']) && is_string($body['error']['message']) && $body['error']['message'] !== '') {
            return $body['error']['message'];
        }

        foreach (['message', 'error', 'detail'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                return $body[$key];
            }
        }

        if (isset($body['data']['message']) && is_string($body['data']['message'])) {
            return $body['data']['message'];
        }

        return $fallback;
    }
}
