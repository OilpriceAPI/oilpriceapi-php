<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use OilPriceAPI\Client;
use OilPriceAPI\Exception\ApiException;
use OilPriceAPI\Price;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A price row that cannot be parsed must raise, never become $0.00.
 *
 * A fabricated zero is indistinguishable from a real quote: the caller has no
 * way to detect it, so it propagates into pricing, invoices and models as if
 * the market had printed it.
 */
final class MalformedPriceRowTest extends TestCase
{
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
    }

    private function client(?string $key = 'fixture_key_NOT_REAL'): Client
    {
        return new Client($key, 'https://api.oilpriceapi.com', 10.0, 0, $this->transport);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedRows(): array
    {
        return [
            'empty row' => [[]],
            'missing price' => [['code' => 'BRENT_CRUDE_USD']],
            'null price' => [['code' => 'BRENT_CRUDE_USD', 'price' => null]],
            'non-numeric price' => [['code' => 'BRENT_CRUDE_USD', 'price' => 'not-a-number']],
            'empty string price' => [['code' => 'BRENT_CRUDE_USD', 'price' => '']],
            'array price' => [['code' => 'BRENT_CRUDE_USD', 'price' => ['value' => 70.0]]],
            'missing code' => [['price' => 70.0]],
            'empty code' => [['code' => '   ', 'price' => 70.0]],
            'non-string code' => [['code' => 123, 'price' => 70.0]],
            'malformed timestamp' => [['code' => 'BRENT_CRUDE_USD', 'price' => 70.0, 'created_at' => 'not-a-date']],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('malformedRows')]
    public function testHistoricalRejectsMalformedRow(array $row): void
    {
        $this->transport->queue(200, ['status' => 'success', 'data' => ['prices' => [$row]]]);

        $this->expectException(ApiException::class);
        $this->client()->pastDay('BRENT_CRUDE_USD');
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('malformedRows')]
    public function testDemoRejectsMalformedRow(array $row): void
    {
        $this->transport->queue(200, ['status' => 'success', 'data' => ['prices' => [$row]]]);

        $this->expectException(ApiException::class);
        $this->client(null)->demoPrices();
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('malformedRows')]
    public function testLatestRejectsMalformedRow(array $row): void
    {
        $this->transport->queue(200, ['status' => 'success', 'data' => $row]);

        $this->expectException(ApiException::class);
        $this->client()->latest('BRENT_CRUDE_USD');
    }

    public function testHistoricalNeverManufacturesAZeroPrice(): void
    {
        $this->transport->queue(200, ['status' => 'success', 'data' => ['prices' => [new \stdClass()]]]);

        try {
            $prices = $this->client()->pastDay('BRENT_CRUDE_USD');
            $this->fail(sprintf(
                'An unparseable price row was returned as data: %s',
                json_encode(array_map(static fn (Price $p): array => $p->toArray(), $prices)),
            ));
        } catch (ApiException $e) {
            $this->assertStringContainsString('/v1/prices/past_day', $e->getMessage());
        }
    }

    public function testNonArrayPricesFieldIsNotAnEmptySuccessfulList(): void
    {
        $this->transport->queue(200, ['status' => 'success', 'data' => ['prices' => 'garbage']]);

        $this->expectException(ApiException::class);
        $this->client()->pastWeek('BRENT_CRUDE_USD');
    }

    public function testMissingPricesFieldIsNotAnEmptySuccessfulList(): void
    {
        $this->transport->queue(200, ['status' => 'success', 'data' => ['note' => 'no prices key']]);

        $this->expectException(ApiException::class);
        $this->client()->pastMonth('BRENT_CRUDE_USD');
    }

    public function testMissingPricesFieldOnDemoIsNotAnEmptySuccessfulList(): void
    {
        $this->transport->queue(200, ['status' => 'success', 'data' => ['note' => 'no prices key']]);

        $this->expectException(ApiException::class);
        $this->client(null)->demoPrices();
    }

    public function testLegitimatelyEmptyListIsStillAnEmptyList(): void
    {
        $this->transport->queue(200, ['status' => 'success', 'data' => ['prices' => []]]);

        $this->assertSame([], $this->client()->pastYear('BRENT_CRUDE_USD'));
    }

    /**
     * @return array<string, array{float|int|string}>
     */
    public static function legitimateNumericPrices(): array
    {
        return [
            'zero' => [0],
            'zero float' => [0.0],
            'zero string' => ['0.00'],
            'negative' => [-37.63],
            'numeric string' => ['71.80'],
        ];
    }

    #[DataProvider('legitimateNumericPrices')]
    public function testLegitimateNumericPricesArePreserved(float|int|string $price): void
    {
        $this->transport->queue(200, [
            'status' => 'success',
            'data' => ['prices' => [['code' => 'WTI_USD', 'price' => $price, 'created_at' => '2026-04-20T00:00:00Z']]],
        ]);

        $prices = $this->client()->pastDay('WTI_USD');

        $this->assertCount(1, $prices);
        $this->assertSame((float) $price, $prices[0]->price);
    }

    public function testPriceFromArrayDoesNotInventAZeroPrice(): void
    {
        $this->expectException(ApiException::class);
        Price::fromArray(['code' => 'BRENT_CRUDE_USD']);
    }
}
