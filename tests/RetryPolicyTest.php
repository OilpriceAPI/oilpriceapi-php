<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use OilPriceAPI\Client;
use OilPriceAPI\Exception\RateLimitException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Retry policy.
 *
 * An exhausted durable quota (monthly, daily, trial, demo) does not refill
 * within any backoff this client could sleep, so retrying it just burns three
 * more requests against a limit that is already spent. A Retry-After the
 * client shortens is worse than not retrying at all: the server said 120
 * seconds and the client came back after 30.
 */
final class RetryPolicyTest extends TestCase
{
    /** @var list<float> */
    private array $sleeps = [];
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
        $this->sleeps = [];
    }

    private function client(int $maxRetries = 3): Client
    {
        return new Client(
            'fixture_key_NOT_REAL',
            'https://api.oilpriceapi.com',
            10.0,
            $maxRetries,
            $this->transport,
            function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function durableQuotaBodies(): array
    {
        return [
            'monthly quota, top-level error_code' => [[
                'error' => 'Monthly request limit exceeded',
                'error_code' => 'MONTHLY_QUOTA_EXCEEDED',
                'message' => 'You have used all 10,000 requests for Free tier this month',
            ]],
            'trial limit, top-level error_code' => [[
                'error_code' => 'TRIAL_LIMIT_EXCEEDED',
                'message' => 'You have hit your trial request limit.',
            ]],
            'trial expired' => [['error_code' => 'TRIAL_EXPIRED']],
            'email confirmation required' => [['error_code' => 'EMAIL_CONFIRMATION_REQUIRED']],
            'demo daily limit, nested error.code' => [[
                'status' => 'fail',
                'error' => ['code' => 'DEMO_RATE_LIMIT_EXCEEDED', 'message' => 'Demo limit reached'],
            ]],
            'lowercase code' => [['error_code' => 'monthly_quota_exceeded']],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('durableQuotaBodies')]
    public function testExhaustedDurableQuotaIsNotRetried(array $body): void
    {
        // Queue enough responses that an unwanted retry shows up as a failed
        // assertion on the request count rather than an exhausted-queue error.
        for ($i = 0; $i < 4; $i++) {
            $this->transport->queue(429, $body, ['Retry-After' => '120']);
        }

        try {
            $this->client()->latest('BRENT_CRUDE_USD');
            $this->fail('Expected RateLimitException.');
        } catch (RateLimitException) {
            // expected
        }

        $this->assertSame(1, $this->transport->requestCount(), 'A spent durable quota must cost exactly one request.');
        $this->assertSame([], $this->sleeps, 'A spent durable quota must not be slept on.');
    }

    public function testRecoverableBurstLimitIsStillRetried(): void
    {
        $this->transport->queue(429, ['error_code' => 'RATE_LIMIT_EXCEEDED'], ['Retry-After' => '2']);
        $this->transport->queue(200, ['status' => 'success', 'data' => ['code' => 'BRENT_CRUDE_USD', 'price' => 71.8, 'currency' => 'USD']]);

        $price = $this->client()->latest('BRENT_CRUDE_USD');

        $this->assertSame(71.8, $price->price);
        $this->assertSame(2, $this->transport->requestCount());
        $this->assertSame([2.0], $this->sleeps);
    }

    public function testHourlyCircuitBreakerWithinBudgetIsRetried(): void
    {
        $this->transport->queue(429, ['error_code' => 'HOURLY_CIRCUIT_BREAKER_EXCEEDED'], ['Retry-After' => '5']);
        $this->transport->queue(200, ['status' => 'success', 'data' => ['code' => 'BRENT_CRUDE_USD', 'price' => 71.8, 'currency' => 'USD']]);

        $this->client()->latest('BRENT_CRUDE_USD');

        $this->assertSame(2, $this->transport->requestCount());
        $this->assertSame([5.0], $this->sleeps);
    }

    public function testRetryAfterLongerThanTheBudgetIsNotShortened(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->transport->queue(429, ['error_code' => 'RATE_LIMIT_EXCEEDED'], ['Retry-After' => '120']);
        }

        try {
            $this->client()->latest('BRENT_CRUDE_USD');
            $this->fail('Expected RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertSame(120, $e->retryAfter);
            $this->assertStringContainsString('120', $e->getMessage());
        }

        $this->assertSame(1, $this->transport->requestCount(), 'A 120s Retry-After must not produce a request after 30s.');
        $this->assertSame([], $this->sleeps);
    }

    public function testNegativeRetryAfterNeverProducesANegativeOrZeroHotRetry(): void
    {
        $this->transport->queue(429, ['error_code' => 'RATE_LIMIT_EXCEEDED'], ['Retry-After' => '-30']);
        $this->transport->queue(200, ['status' => 'success', 'data' => ['code' => 'BRENT_CRUDE_USD', 'price' => 71.8, 'currency' => 'USD']]);

        $this->client()->latest('BRENT_CRUDE_USD');

        $this->assertCount(1, $this->sleeps);
        $this->assertGreaterThan(0.0, $this->sleeps[0], 'A malformed negative Retry-After must fall back to backoff, not sleep 0.');
        $this->assertLessThanOrEqual(30.0, $this->sleeps[0]);
    }

    public function testNegativeRetryAfterIsNotReportedOnTheException(): void
    {
        $this->transport->queue(429, ['error_code' => 'MONTHLY_QUOTA_EXCEEDED'], ['Retry-After' => '-30']);

        try {
            $this->client(0)->latest('BRENT_CRUDE_USD');
            $this->fail('Expected RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertNull($e->retryAfter, 'A negative Retry-After is malformed, not a retry instruction.');
        }
    }

    public function testHttpDateRetryAfterInThePastRetriesImmediately(): void
    {
        $this->transport->queue(
            429,
            ['error_code' => 'RATE_LIMIT_EXCEEDED'],
            ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() - 600)],
        );
        $this->transport->queue(200, ['status' => 'success', 'data' => ['code' => 'BRENT_CRUDE_USD', 'price' => 71.8, 'currency' => 'USD']]);

        $this->client()->latest('BRENT_CRUDE_USD');

        $this->assertSame(2, $this->transport->requestCount());
        $this->assertSame([0.0], $this->sleeps);
    }

    public function testHttpDateRetryAfterBeyondTheBudgetIsNotShortened(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->transport->queue(
                429,
                ['error_code' => 'RATE_LIMIT_EXCEEDED'],
                ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 900)],
            );
        }

        $this->expectException(RateLimitException::class);

        try {
            $this->client()->latest('BRENT_CRUDE_USD');
        } finally {
            $this->assertSame(1, $this->transport->requestCount());
            $this->assertSame([], $this->sleeps);
        }
    }

    public function testUnparseableRetryAfterFallsBackToBackoff(): void
    {
        $this->transport->queue(429, ['error_code' => 'RATE_LIMIT_EXCEEDED'], ['Retry-After' => 'soon']);
        $this->transport->queue(200, ['status' => 'success', 'data' => ['code' => 'BRENT_CRUDE_USD', 'price' => 71.8, 'currency' => 'USD']]);

        $this->client()->latest('BRENT_CRUDE_USD');

        $this->assertCount(1, $this->sleeps);
        $this->assertGreaterThan(0.0, $this->sleeps[0]);
        $this->assertLessThanOrEqual(30.0, $this->sleeps[0]);
    }

    public function testMaxRetriesZeroSendsExactlyOneRequest(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->transport->queue(429, ['error_code' => 'RATE_LIMIT_EXCEEDED'], ['Retry-After' => '1']);
        }

        $this->expectException(RateLimitException::class);

        try {
            $this->client(0)->latest('BRENT_CRUDE_USD');
        } finally {
            $this->assertSame(1, $this->transport->requestCount());
            $this->assertSame([], $this->sleeps);
        }
    }

    public function testServerErrorsAreStillRetried(): void
    {
        $this->transport->queue(500, ['error' => 'boom']);
        $this->transport->queue(503, ['error' => 'boom']);
        $this->transport->queue(200, ['status' => 'success', 'data' => ['code' => 'BRENT_CRUDE_USD', 'price' => 71.8, 'currency' => 'USD']]);

        $this->client()->latest('BRENT_CRUDE_USD');

        $this->assertSame(3, $this->transport->requestCount());
        $this->assertCount(2, $this->sleeps);
    }

    public function testEveryRecordedSleepIsNonNegativeAndBounded(): void
    {
        $this->transport->queue(429, ['error_code' => 'RATE_LIMIT_EXCEEDED'], ['Retry-After' => '-1']);
        $this->transport->queue(500, []);
        $this->transport->queue(429, ['error_code' => 'RATE_LIMIT_EXCEEDED'], ['Retry-After' => '0']);
        $this->transport->queue(200, ['status' => 'success', 'data' => ['code' => 'BRENT_CRUDE_USD', 'price' => 71.8, 'currency' => 'USD']]);

        $this->client()->latest('BRENT_CRUDE_USD');

        foreach ($this->sleeps as $sleep) {
            $this->assertGreaterThanOrEqual(0.0, $sleep);
            $this->assertLessThanOrEqual(30.0, $sleep);
        }
    }
}
