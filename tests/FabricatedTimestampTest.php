<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use DateTimeInterface;
use OilPriceAPI\Client;
use OilPriceAPI\Exception\ApiException;
use OilPriceAPI\Price;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An observation timestamp must never be invented.
 *
 * `DateTimeImmutable::createFromFormat()` returns a perfectly usable object
 * for input it had to repair, and reports the repair only through the static
 * `getLastErrors()`. `new DateTimeImmutable()` is worse: it accepts relative
 * expressions such as 'now' and 'next friday' without any complaint at all.
 * Either path turns a corrupt field into a plausible date, which silently
 * misplaces a correct price in time - a failure a human cannot spot the way
 * they spot a $0.00 Brent quote.
 */
final class FabricatedTimestampTest extends TestCase
{
    /**
     * Values that must never become a timestamp.
     *
     * @return array<string, array{string}>
     */
    public static function fabricatedTimestamps(): array
    {
        return [
            // Reported by getLastErrors() only: rolls over to 2027-02-18.
            'impossible date and time' => ['2026-13-45T99:99:99Z'],
            'month 13' => ['2026-13-01T00:00:00Z'],
            'day 45' => ['2026-07-45T00:00:00Z'],
            'february 30th' => ['2026-02-30T00:00:00Z'],
            'february 29th in a common year' => ['2026-02-29T00:00:00Z'],
            'hour 99' => ['2026-07-19T99:00:00Z'],
            // Accepted silently by the DateTimeImmutable constructor.
            'relative next friday' => ['next friday'],
            'relative now' => ['now'],
            'relative tomorrow' => ['tomorrow'],
            'relative offset' => ['+1 week'],
            'relative yesterday noon' => ['yesterday noon'],
            'all zeroes' => ['0000-00-00'],
            'all zeroes with time' => ['0000-00-00 00:00:00'],
            // Leap seconds have no representation in PHP; accepting one is a
            // silent one-second shift into the following day.
            'leap second' => ['2026-06-30T23:59:60Z'],
            'leap second end of year' => ['2026-12-31T23:59:60Z'],
            // Padding and trailing data are repaired, not rejected, by default.
            'trailing junk' => ['2026-07-19T12:00:00Zjunk'],
            'leading whitespace' => ['  2026-07-19T12:00:00Z'],
            'trailing whitespace' => ['2026-07-19T12:00:00Z  '],
            // Not a timestamp at all.
            'unix epoch seconds' => ['1690000000'],
            'free text' => ['not-a-date'],
            'null string' => ['null'],
            'iso duration' => ['P1Y2M3D'],
            'timezone name only' => ['UTC'],
        ];
    }

    #[DataProvider('fabricatedTimestamps')]
    public function testFabricatedTimestampIsRejectedRatherThanInvented(string $timestamp): void
    {
        try {
            $price = Price::fromArray([
                'code' => 'BRENT_CRUDE_USD',
                'price' => 71.80,
                'currency' => 'USD',
                'created_at' => $timestamp,
            ]);
        } catch (ApiException $e) {
            $this->assertStringContainsString('BRENT_CRUDE_USD', $e->getMessage());

            return;
        }

        $this->fail(sprintf(
            'Timestamp %s was fabricated into %s instead of being rejected.',
            var_export($timestamp, true),
            var_export($price->updatedAt?->format(DateTimeInterface::ATOM), true),
        ));
    }

    /**
     * Absolute timestamps the production API actually speaks must keep working,
     * and must keep their exact instant - including the offset.
     *
     * @return array<string, array{string, string}>
     */
    public static function genuineTimestamps(): array
    {
        return [
            'atom with Z' => ['2026-07-19T12:00:00Z', '2026-07-19T12:00:00+00:00'],
            'atom lowercase z' => ['2026-07-19T12:00:00z', '2026-07-19T12:00:00+00:00'],
            'atom with numeric offset' => ['2026-07-19T12:00:00+00:00', '2026-07-19T12:00:00+00:00'],
            'atom with compact offset' => ['2026-07-19T12:00:00+0000', '2026-07-19T12:00:00+00:00'],
            'atom with negative offset' => ['2026-07-19T12:00:00-05:00', '2026-07-19T12:00:00-05:00'],
            'atom with positive offset' => ['2026-07-19T12:00:00+05:30', '2026-07-19T12:00:00+05:30'],
            'milliseconds' => ['2026-07-19T12:00:00.123Z', '2026-07-19T12:00:00+00:00'],
            'microseconds' => ['2026-07-19T12:00:00.123456Z', '2026-07-19T12:00:00+00:00'],
            'naive iso read as utc' => ['2026-07-19T12:00:00', '2026-07-19T12:00:00+00:00'],
            'space separator' => ['2026-07-19 12:00:00', '2026-07-19T12:00:00+00:00'],
            'space separator with offset' => ['2026-07-19 12:00:00+02:00', '2026-07-19T12:00:00+02:00'],
            'date only' => ['2026-07-19', '2026-07-19T00:00:00+00:00'],
            'leap day in a leap year' => ['2024-02-29T00:00:00Z', '2024-02-29T00:00:00+00:00'],
            'end of year' => ['2026-12-31T23:59:59Z', '2026-12-31T23:59:59+00:00'],
        ];
    }

    #[DataProvider('genuineTimestamps')]
    public function testGenuineTimestampsAreParsedExactly(string $timestamp, string $expected): void
    {
        $price = Price::fromArray([
            'code' => 'BRENT_CRUDE_USD',
            'price' => 71.80,
            'currency' => 'USD',
            'created_at' => $timestamp,
        ]);

        $this->assertNotNull($price->updatedAt);
        $this->assertSame($expected, $price->updatedAt->format(DateTimeInterface::ATOM));
    }

    /**
     * A naive timestamp is read as UTC, not as the host's local timezone: the
     * same payload must not describe a different instant on a server in
     * Chicago than on one in London.
     */
    public function testNaiveTimestampDoesNotDependOnTheHostTimezone(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('America/Chicago');

        try {
            $price = Price::fromArray([
                'code' => 'BRENT_CRUDE_USD',
                'price' => 71.80,
                'currency' => 'USD',
                'created_at' => '2026-07-19 12:00:00',
            ]);

            $this->assertNotNull($price->updatedAt);
            $this->assertSame(
                '2026-07-19T12:00:00+00:00',
                $price->updatedAt->format(DateTimeInterface::ATOM),
            );
        } finally {
            date_default_timezone_set($original);
        }
    }

    /**
     * A row with no timestamp at all is still valid: updatedAt is nullable.
     */
    public function testAbsentTimestampStaysNull(): void
    {
        $price = Price::fromArray([
            'code' => 'BRENT_CRUDE_USD',
            'price' => 71.80,
            'currency' => 'USD',
        ]);

        $this->assertNull($price->updatedAt);
    }

    /**
     * `updated_at` is the documented fallback when `created_at` is absent, and
     * it must be held to the same standard.
     */
    public function testUpdatedAtFallbackIsValidatedToo(): void
    {
        $this->expectException(ApiException::class);

        Price::fromArray([
            'code' => 'BRENT_CRUDE_USD',
            'price' => 71.80,
            'currency' => 'USD',
            'updated_at' => 'next friday',
        ]);
    }

    /**
     * The whole point: a fabricated timestamp must not reach a caller through
     * the client, on a response that is otherwise a perfectly good price.
     */
    public function testClientRefusesARowWithAFabricatedTimestamp(): void
    {
        $transport = new MockTransport();
        $transport->queue(200, [
            'status' => 'success',
            'data' => [
                'code' => 'BRENT_CRUDE_USD',
                'price' => 71.80,
                'currency' => 'USD',
                'created_at' => '2026-13-45T99:99:99Z',
            ],
        ]);
        $client = new Client('fixture_key_NOT_REAL_0123456789', Client::DEFAULT_BASE_URL, 10.0, 0, $transport);

        $this->expectException(ApiException::class);

        $client->latest('BRENT_CRUDE_USD');
    }
}
