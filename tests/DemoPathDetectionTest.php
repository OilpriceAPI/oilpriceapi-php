<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use OilPriceAPI\Client;
use OilPriceAPI\Exception\AuthenticationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keyless demo mode is an exception carved out for exactly one endpoint
 * family: /v1/demo. A prefix match over the raw characters of the path
 * also catches paths that merely START with those characters but are
 * answered by a completely different, authenticated endpoint:
 *
 *   /v1/demographics            - a sibling endpoint, not demo at all
 *   /v1/demo/../prices/latest   - resolves server-side to /v1/prices/latest
 *
 * Sending those without `Authorization: Token <key>` earns a 401, which the
 * SDK then reports to the caller as "Invalid API key." - about a key that is
 * perfectly valid. The key must travel with every non-demo request.
 */
final class DemoPathDetectionTest extends TestCase
{
    private const KEY = 'live_key_123';

    private MockTransport $transport;

    private string|false $originalEnvKey = false;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
        $this->originalEnvKey = getenv('OILPRICEAPI_KEY');
        putenv('OILPRICEAPI_KEY');
    }

    protected function tearDown(): void
    {
        if ($this->originalEnvKey === false) {
            putenv('OILPRICEAPI_KEY');
        } else {
            putenv('OILPRICEAPI_KEY=' . $this->originalEnvKey);
        }
    }

    private function client(?string $apiKey = self::KEY): Client
    {
        return new Client(
            apiKey: $apiKey,
            timeout: 10.0,
            maxRetries: 0,
            transport: $this->transport,
            sleeper: static function (float $seconds): void {
            },
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonDemoPathProvider(): iterable
    {
        yield 'sibling endpoint sharing the prefix' => ['/v1/demographics'];
        yield 'prefix plus a suffix, no separator' => ['/v1/demo-prices'];
        yield 'dot segments that resolve away from demo' => ['/v1/demo/../prices/latest'];
        yield 'percent-encoded dot segments' => ['/v1/demo/%2e%2e/prices/latest'];
        yield 'single dot segment' => ['/v1/demo/./../prices/latest'];
    }

    #[DataProvider('nonDemoPathProvider')]
    public function testNonDemoPathsCarryTheApiKey(string $path): void
    {
        $this->transport->queue(200, ['status' => 'success', 'data' => []]);

        $this->client()->raw()->get($path);

        $request = $this->transport->requests[0];
        $this->assertArrayHasKey(
            'Authorization',
            $request['headers'],
            $path . ' is not the demo endpoint and must be sent with the API key.',
        );
        $this->assertSame('Token ' . self::KEY, $request['headers']['Authorization']);
    }

    #[DataProvider('nonDemoPathProvider')]
    public function testNonDemoPathsStillDemandAKey(string $path): void
    {
        $client = $this->client(apiKey: null);

        try {
            $client->raw()->get($path);
            $this->fail('Expected AuthenticationException for keyless ' . $path);
        } catch (AuthenticationException $e) {
            $this->assertStringContainsString('No API key configured', $e->getMessage());
        }

        $this->assertSame(0, $this->transport->requestCount(), 'No request should leave without a key.');
    }

    public function testDemoPricesStillWorksWithoutAKey(): void
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
        $request = $this->transport->requests[0];
        $this->assertSame('https://api.oilpriceapi.com/v1/demo/prices', $request['url']);
        $this->assertArrayNotHasKey('Authorization', $request['headers']);
    }

    public function testDemoPathStaysKeylessEvenWhenAKeyIsConfigured(): void
    {
        $this->transport->queue(200, ['status' => 'success', 'data' => []]);

        $this->client()->raw()->get('/v1/demo/prices');

        $this->assertArrayNotHasKey('Authorization', $this->transport->requests[0]['headers']);
    }

    public function testDemoPathWithAQueryStringStaysKeyless(): void
    {
        $this->transport->queue(200, ['status' => 'success', 'data' => []]);

        $this->client(apiKey: null)->raw()->get('/v1/demo/prices?by_code=BRENT_CRUDE_USD');

        $this->assertArrayNotHasKey('Authorization', $this->transport->requests[0]['headers']);
    }

    public function testBareDemoSegmentStaysKeyless(): void
    {
        $this->transport->queue(200, ['status' => 'success', 'data' => []]);

        $this->client(apiKey: null)->raw()->get('/v1/demo');

        $this->assertArrayNotHasKey('Authorization', $this->transport->requests[0]['headers']);
    }
}
