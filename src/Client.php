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
    public const VERSION = '2.1.0';
    public const DEFAULT_BASE_URL = 'https://api.oilpriceapi.com';
    public const DEFAULT_TIMEOUT = 10.0;
    public const DEFAULT_MAX_RETRIES = 3;

    /** Maximum backoff sleep between retries, in seconds. */
    private const MAX_BACKOFF_SECONDS = 30.0;

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
                fn (mixed $price): Price => $this->latestPriceOrFail($price, $body),
                array_values($data['prices']),
            );
        }

        return $this->latestPriceOrFail($data, $body);
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
        $prices = is_array($data['prices'] ?? null) ? $data['prices'] : [];

        return array_map(Price::fromArray(...), array_values($prices));
    }

    /**
     * Escape hatch: call a versioned GET endpoint and get its decoded envelope.
     *
     *     $curve = $client->raw()->get('/v1/futures/ice-brent/curve');
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
        $body = $this->request('/v1/prices/' . $period, $params);
        $data = $this->dataOrFail($body, '/v1/prices/' . $period);
        $prices = is_array($data['prices'] ?? null) ? $data['prices'] : [];

        return array_map(Price::fromArray(...), array_values($prices));
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
     * @param mixed                $data
     * @param array<string, mixed> $body
     */
    private function latestPriceOrFail(mixed $data, array $body): Price
    {
        if (
            !is_array($data)
            || !isset($data['code'])
            || !is_string($data['code'])
            || trim($data['code']) === ''
            || !array_key_exists('price', $data)
            || !is_numeric($data['price'])
        ) {
            throw new ApiException('Unexpected latest price shape from /v1/prices/latest.', 200, $body);
        }

        return Price::fromArray($data);
    }

    /**
     * Perform a GET request with retries and typed error mapping.
     *
     * @param array<string, scalar> $params
     *
     * @return array<string, mixed> Decoded JSON body
     */
    private function request(string $path, array $params): array
    {
        $isDemo = str_starts_with($path, '/v1/demo');

        if (!$isDemo && $this->apiKey === null) {
            throw new AuthenticationException(
                'No API key configured. Pass one to the Client constructor or set the '
                . 'OILPRICEAPI_KEY environment variable. (Keyless demo mode is available '
                . 'via $client->demoPrices().)',
                0,
            );
        }

        $url = $this->baseUrl . $path;
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
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

            if (!$this->isRetryable($response->statusCode) || $attempt === $attempts - 1) {
                break;
            }

            ($this->sleeper)($this->backoffDelay($attempt, $response));
        }

        assert($response instanceof HttpResponse);

        return $this->handleResponse($response, $path);
    }

    private function isRetryable(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * Exponential backoff with full jitter, honoring Retry-After when present.
     */
    private function backoffDelay(int $attempt, HttpResponse $response): float
    {
        $retryAfter = $this->parseRetryAfter($response);
        if ($retryAfter !== null) {
            return min((float) $retryAfter, self::MAX_BACKOFF_SECONDS);
        }

        $base = min(0.5 * (2 ** $attempt), self::MAX_BACKOFF_SECONDS);
        $jitter = mt_rand(0, 1000) / 1000 * ($base / 2);

        return min($base + $jitter, self::MAX_BACKOFF_SECONDS);
    }

    private function parseRetryAfter(HttpResponse $response): ?int
    {
        $value = $response->header('Retry-After');
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
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
