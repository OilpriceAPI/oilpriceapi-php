<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use DateTimeImmutable;
use OilPriceAPI\Client;
use OilPriceAPI\Exception\ApiException;
use OilPriceAPI\Exception\AuthenticationException;
use OilPriceAPI\Exception\RateLimitException;
use OilPriceAPI\Price;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private MockTransport $transport;

    /** @var list<float> */
    private array $sleeps = [];

    private string|false $originalEnvKey = false;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
        $this->sleeps = [];
        $this->originalEnvKey = getenv('OILPRICEAPI_KEY');
        putenv('OILPRICEAPI_KEY'); // ensure a clean slate for every test
    }

    protected function tearDown(): void
    {
        if ($this->originalEnvKey === false) {
            putenv('OILPRICEAPI_KEY');
        } else {
            putenv('OILPRICEAPI_KEY=' . $this->originalEnvKey);
        }
    }

    private function client(?string $apiKey = 'test_key', int $maxRetries = 3): Client
    {
        return new Client(
            apiKey: $apiKey,
            timeout: 10.0,
            maxRetries: $maxRetries,
            transport: $this->transport,
            sleeper: function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
    }

    // ---------------------------------------------------------------
    // Happy paths
    // ---------------------------------------------------------------

    public function testLatestByCodeReturnsSinglePrice(): void
    {
        $this->transport->queue(200, [
            'status' => 'success',
            'data' => [
                'code' => 'BRENT_CRUDE_USD',
                'price' => 71.23,
                'formatted' => '$71.23',
                'currency' => 'USD',
                'source' => 'market_reporting',
                'type' => 'spot_price',
                'created_at' => '2026-07-03T09:00:00+00:00',
            ],
        ]);

        $price = $this->client()->latest('BRENT_CRUDE_USD');

        $this->assertInstanceOf(Price::class, $price);
        $this->assertSame('BRENT_CRUDE_USD', $price->code);
        $this->assertSame(71.23, $price->price);
        $this->assertSame('USD', $price->currency);
        $this->assertSame('market_reporting', $price->source);
        $this->assertInstanceOf(DateTimeImmutable::class, $price->updatedAt);
        $this->assertSame('2026-07-03T09:00:00+00:00', $price->updatedAt->format(DATE_ATOM));

        $request = $this->transport->requests[0];
        $this->assertSame('GET', $request['method']);
        $this->assertSame(
            'https://api.oilpriceapi.com/v1/prices/latest?by_code=BRENT_CRUDE_USD',
            $request['url'],
        );
        $this->assertSame('Token test_key', $request['headers']['Authorization']);
        $this->assertSame('oilpriceapi-php/' . Client::VERSION, $request['headers']['User-Agent']);
    }

    public function testLatestWithoutCodeReturnsPriceList(): void
    {
        $this->transport->queue(200, [
            'status' => 'success',
            'data' => [
                'prices' => [
                    ['code' => 'BRENT_CRUDE_USD', 'price' => 71.23, 'currency' => 'USD'],
                    ['code' => 'WTI_USD', 'price' => 68.10, 'currency' => 'USD'],
                ],
            ],
        ]);

        $prices = $this->client()->latest();

        $this->assertIsArray($prices);
        $this->assertCount(2, $prices);
        $this->assertContainsOnlyInstancesOf(Price::class, $prices);
        $this->assertSame('WTI_USD', $prices[1]->code);
        $this->assertStringNotContainsString('by_code', $this->transport->requests[0]['url']);
    }

    public function testHistoricalPeriodEndpoints(): void
    {
        $envelope = static fn (): array => [
            'status' => 'success',
            'data' => [
                'prices' => [
                    ['price' => 70.00, 'created_at' => '2026-07-01T00:00:00Z', 'code' => 'BRENT_CRUDE_USD'],
                    ['price' => 71.00, 'created_at' => '2026-07-02T00:00:00Z', 'code' => 'BRENT_CRUDE_USD'],
                ],
            ],
        ];

        $client = $this->client();

        foreach (['pastDay' => 'past_day', 'pastWeek' => 'past_week', 'pastMonth' => 'past_month', 'pastYear' => 'past_year'] as $method => $path) {
            $this->transport->queue(200, $envelope());
            $prices = $client->{$method}('BRENT_CRUDE_USD');

            $this->assertCount(2, $prices, $method);
            $this->assertContainsOnlyInstancesOf(Price::class, $prices, $method);

            $url = $this->transport->requests[$this->transport->requestCount() - 1]['url'];
            $this->assertSame(
                'https://api.oilpriceapi.com/v1/prices/' . $path . '?by_code=BRENT_CRUDE_USD',
                $url,
                $method,
            );
        }
    }

    // ---------------------------------------------------------------
    // Demo mode (keyless)
    // ---------------------------------------------------------------

    public function testDemoPricesWorksWithoutApiKeyAndSendsNoAuthHeader(): void
    {
        $this->transport->queue(200, [
            'status' => 'success',
            'data' => [
                'prices' => [
                    ['code' => 'BRENT_CRUDE_USD', 'name' => 'Brent Crude', 'price' => 71.23, 'currency' => 'USD', 'unit' => 'barrel'],
                ],
                'meta' => ['demo_mode' => true, 'rate_limit' => '20/hour'],
            ],
        ]);

        $prices = $this->client(apiKey: null)->demoPrices();

        $this->assertCount(1, $prices);
        $this->assertSame('Brent Crude', $prices[0]->name);
        $this->assertSame('barrel', $prices[0]->unit);

        $request = $this->transport->requests[0];
        $this->assertSame('https://api.oilpriceapi.com/v1/demo/prices', $request['url']);
        $this->assertArrayNotHasKey('Authorization', $request['headers']);
    }

    public function testKeylessClientThrowsHelpfulErrorOnKeyedEndpoint(): void
    {
        try {
            $this->client(apiKey: null)->latest('BRENT_CRUDE_USD');
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertStringContainsString('No API key configured', $e->getMessage());
            $this->assertStringContainsString('https://www.oilpriceapi.com/auth/signup?utm_source=php-sdk', $e->getMessage());
            $this->assertStringContainsString('demoPrices', $e->getMessage());
        }

        $this->assertSame(0, $this->transport->requestCount(), 'no network call should be made without a key');
    }

    public function testApiKeyFallsBackToEnvironmentVariable(): void
    {
        putenv('OILPRICEAPI_KEY=env_key_123');

        $this->transport->queue(200, [
            'status' => 'success',
            'data' => ['code' => 'WTI_USD', 'price' => 68.10, 'currency' => 'USD'],
        ]);

        $price = $this->client(apiKey: null)->latest('WTI_USD');

        $this->assertSame('WTI_USD', $price->code);
        $this->assertSame('Token env_key_123', $this->transport->requests[0]['headers']['Authorization']);
    }

    // ---------------------------------------------------------------
    // Errors
    // ---------------------------------------------------------------

    public function testAuthenticationExceptionOn401IncludesSignupHint(): void
    {
        $this->transport->queue(401, ['status' => 'error', 'message' => 'Invalid Authorization token']);

        try {
            $this->client()->latest('BRENT_CRUDE_USD');
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertStringContainsString('Invalid Authorization token', $e->getMessage());
            $this->assertStringContainsString('https://www.oilpriceapi.com/auth/signup?utm_source=php-sdk', $e->getMessage());
        }

        $this->assertSame(1, $this->transport->requestCount(), '401 must not be retried');
    }

    public function testNestedProductionErrorEnvelopeIsSurfaced(): void
    {
        // Exact shape returned by the production API on 401.
        $this->transport->queue(401, [
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid API key. Include header: Authorization: Token YOUR_API_KEY',
                'status' => 401,
                'signup_url' => 'https://www.oilpriceapi.com/auth/signup',
                'demo_endpoint' => '/v1/demo/prices',
            ],
        ]);

        try {
            $this->client()->latest('BRENT_CRUDE_USD');
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertStringContainsString('Missing or invalid API key', $e->getMessage());
            $this->assertSame('UNAUTHORIZED', $e->responseBody['error']['code']);
        }
    }

    public function testClientErrorIsNotRetried(): void
    {
        $this->transport->queue(400, ['status' => 'error', 'message' => 'by_code is invalid']);

        try {
            $this->client()->latest('NOPE');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertStringContainsString('by_code is invalid', $e->getMessage());
        }

        $this->assertSame(1, $this->transport->requestCount());
        $this->assertSame([], $this->sleeps);
    }

    public function testUnexpectedEnvelopeShapeThrows(): void
    {
        $this->transport->queue(200, ['status' => 'weird']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unexpected response shape');

        $this->client()->latest('BRENT_CRUDE_USD');
    }

    public function testLatestRejectsSuccessWithoutUsablePrice(): void
    {
        $this->transport->queue(200, [
            'status' => 'success',
            'data' => [],
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unexpected latest price shape');

        $this->client()->latest('BRENT_CRUDE_USD');
    }

    public function testLatestRejectsEmptyLegacyPriceList(): void
    {
        $this->transport->queue(200, [
            'status' => 'success',
            'data' => ['prices' => []],
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unexpected latest price shape');

        $this->client()->latest();
    }

    // ---------------------------------------------------------------
    // Retries
    // ---------------------------------------------------------------

    public function testRetriesOn429AndHonorsRetryAfterHeader(): void
    {
        $this->transport
            ->queue(429, ['status' => 'error', 'message' => 'Rate limit exceeded'], ['Retry-After' => '7'])
            ->queue(200, [
                'status' => 'success',
                'data' => ['code' => 'BRENT_CRUDE_USD', 'price' => 71.23, 'currency' => 'USD'],
            ]);

        $price = $this->client()->latest('BRENT_CRUDE_USD');

        $this->assertSame(71.23, $price->price);
        $this->assertSame(2, $this->transport->requestCount());
        $this->assertSame([7.0], $this->sleeps, 'backoff must honor Retry-After exactly');
    }

    public function testRetriesOn500WithExponentialBackoff(): void
    {
        $this->transport
            ->queue(500, [])
            ->queue(502, [])
            ->queue(200, [
                'status' => 'success',
                'data' => ['code' => 'WTI_USD', 'price' => 68.10, 'currency' => 'USD'],
            ]);

        $price = $this->client()->latest('WTI_USD');

        $this->assertSame('WTI_USD', $price->code);
        $this->assertSame(3, $this->transport->requestCount());
        $this->assertCount(2, $this->sleeps);
        // attempt 0: base 0.5s, attempt 1: base 1.0s - each plus up to 50% jitter
        $this->assertGreaterThanOrEqual(0.5, $this->sleeps[0]);
        $this->assertLessThanOrEqual(0.75, $this->sleeps[0]);
        $this->assertGreaterThanOrEqual(1.0, $this->sleeps[1]);
        $this->assertLessThanOrEqual(1.5, $this->sleeps[1]);
    }

    public function testRateLimitExceptionAfterRetriesExhausted(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->transport->queue(
                429,
                ['status' => 'error', 'message' => 'Rate limit exceeded'],
                ['Retry-After' => '1', 'X-RateLimit-Limit' => '10000'],
            );
        }

        try {
            $this->client(maxRetries: 2)->latest('BRENT_CRUDE_USD');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertSame(1, $e->retryAfter);
            $this->assertSame('10000', $e->limit);
            $this->assertStringContainsString('https://www.oilpriceapi.com/pricing?utm_source=php-sdk-limit', $e->getMessage());
        }

        $this->assertSame(3, $this->transport->requestCount(), 'initial attempt + 2 retries');
    }

    // ---------------------------------------------------------------
    // Raw escape hatch
    // ---------------------------------------------------------------

    public function testRawEscapeHatchReachesAnyEndpoint(): void
    {
        $envelope = [
            'status' => 'success',
            'data' => [
                'contract' => 'ice-brent',
                'curve' => [
                    ['month' => '2026-08', 'price' => 71.50],
                    ['month' => '2026-09', 'price' => 71.10],
                ],
            ],
        ];
        $this->transport->queue(200, $envelope);

        $result = $this->client()->raw()->get('/v1/futures/ice-brent/curve', ['unit' => 'usd']);

        $this->assertSame($envelope, $result, 'raw() must return the full decoded envelope');
        $this->assertSame(
            'https://api.oilpriceapi.com/v1/futures/ice-brent/curve?unit=usd',
            $this->transport->requests[0]['url'],
        );
        $this->assertSame('Token test_key', $this->transport->requests[0]['headers']['Authorization']);
    }

    public function testRawInvalidJsonThrowsApiException(): void
    {
        $this->transport->queueRaw(200, '<html>not json</html>');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('invalid JSON');

        $this->client()->raw()->get('/v1/prices/latest');
    }

    // ---------------------------------------------------------------
    // Price DTO
    // ---------------------------------------------------------------

    public function testPriceToArrayRoundTrip(): void
    {
        $price = Price::fromArray([
            'code' => 'EU_CARBON_EUR',
            'price' => 88.00,
            'currency' => 'EUR',
            'created_at' => '2026-07-03T08:30:00+00:00',
            'change_24h' => -1.25,
            'unit' => 'tonne',
        ]);

        $this->assertSame(-1.25, $price->change24h);

        $array = $price->toArray();
        $this->assertSame('EU_CARBON_EUR', $array['code']);
        $this->assertSame(88.00, $array['price']);
        $this->assertSame('EUR', $array['currency']);
        $this->assertSame('2026-07-03T08:30:00+00:00', $array['updated_at']);
        $this->assertSame('tonne', $array['unit']);
    }
}
