<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use OilPriceAPI\Client;
use OilPriceAPI\Exception\ApiException;
use OilPriceAPI\Price;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Descriptive fields must not be coerced into a plausible-looking string.
 *
 * `(string)` on anything but a string is a fabrication: an array becomes the
 * literal 'Array' (plus a PHP warning nobody reads), `true` becomes '1', and
 * the ISO numeric currency 978 becomes '978'. A mislabelled unit - barrel
 * where the payload said tonne - is the same harm class as a wrong number,
 * and it is harder to spot because the number next to it is right.
 *
 * `currency` is required for the same reason. Defaulting it to 'USD' labels a
 * EUR carbon price as dollars: unlike a $0.00 Brent quote, `78.40 USD` on an
 * EUA contract is entirely plausible, roughly 8% wrong, and flows into a model
 * undetected. The repo's own fixtures carry EU_CARBON_EUR, so the catalogue is
 * not USD-only.
 */
final class PriceFieldCoercionTest extends TestCase
{
    private const KEY = 'fixture_key_NOT_REAL_0123456789';

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function nonStringFields(): array
    {
        $fields = ['currency', 'unit', 'name', 'source', 'type', 'formatted'];
        $values = [
            'array' => ['EUR'],
            'nested array' => ['value' => 'EUR'],
            'true' => true,
            'false' => false,
            'int' => 978,
            'float' => 1.5,
        ];

        $cases = [];
        foreach ($fields as $field) {
            foreach ($values as $label => $value) {
                $cases[$field . ' as ' . $label] = [$field, $value];
            }
        }

        return $cases;
    }

    /**
     * @param mixed $value
     */
    #[DataProvider('nonStringFields')]
    public function testNonStringFieldIsRejectedRatherThanCoerced(string $field, $value): void
    {
        $row = [
            'code' => 'EU_CARBON_EUR',
            'price' => 78.40,
            'currency' => 'EUR',
            $field => $value,
        ];

        try {
            $price = Price::fromArray($row);
        } catch (ApiException $e) {
            $this->assertStringContainsString($field, $e->getMessage());
            $this->assertStringContainsString('EU_CARBON_EUR', $e->getMessage());

            return;
        }

        $this->fail(sprintf(
            'Field %s was coerced to %s instead of being rejected.',
            $field,
            var_export($price->{self::property($field)}, true),
        ));
    }

    /**
     * The exact case from the report: `currency: ["EUR"]` became the literal
     * string 'Array', so a euro-denominated carbon price claimed a currency
     * that does not exist.
     */
    public function testArrayCurrencyDoesNotBecomeTheLiteralStringArray(): void
    {
        try {
            $price = Price::fromArray([
                'code' => 'EU_CARBON_EUR',
                'price' => 78.40,
                'currency' => ['EUR'],
            ]);
        } catch (ApiException) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $this->fail('currency ["EUR"] became ' . var_export($price->currency, true));
    }

    /**
     * `currency` is required. A row that does not say what the number is
     * denominated in is not a usable price row.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rowsWithoutAUsableCurrency(): array
    {
        return [
            'absent' => [['code' => 'EU_CARBON_EUR', 'price' => 78.40]],
            'null' => [['code' => 'EU_CARBON_EUR', 'price' => 78.40, 'currency' => null]],
            'empty string' => [['code' => 'EU_CARBON_EUR', 'price' => 78.40, 'currency' => '']],
            'whitespace only' => [['code' => 'EU_CARBON_EUR', 'price' => 78.40, 'currency' => '   ']],
            'iso numeric code' => [['code' => 'EU_CARBON_EUR', 'price' => 78.40, 'currency' => 978]],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('rowsWithoutAUsableCurrency')]
    public function testCurrencyIsRequiredRatherThanDefaultedToUsd(array $row): void
    {
        try {
            $price = Price::fromArray($row);
        } catch (ApiException $e) {
            $this->assertStringContainsString('currency', $e->getMessage());

            return;
        }

        $this->fail(sprintf(
            'A row with no usable currency was labelled %s.',
            var_export($price->currency, true),
        ));
    }

    /**
     * The specific harm, stated end to end: a euro carbon price must never
     * reach a caller labelled USD.
     */
    public function testEuroCarbonPriceIsNeverLabelledUsd(): void
    {
        $transport = new MockTransport();
        $transport->queue(200, [
            'status' => 'success',
            'data' => ['code' => 'EU_CARBON_EUR', 'price' => 78.40],
        ]);
        $client = new Client(self::KEY, Client::DEFAULT_BASE_URL, 10.0, 0, $transport);

        try {
            $price = $client->latest('EU_CARBON_EUR');
        } catch (ApiException) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $this->fail(sprintf(
            'EU_CARBON_EUR at %s came back as %s.',
            is_array($price) ? 'list' : (string) $price->price,
            is_array($price) ? 'list' : var_export($price->currency, true),
        ));
    }

    /**
     * Well-formed rows keep working, and the values survive untouched.
     */
    public function testWellFormedStringFieldsArePreserved(): void
    {
        $price = Price::fromArray([
            'code' => 'EU_CARBON_EUR',
            'price' => 78.40,
            'currency' => 'EUR',
            'unit' => 'tonne',
            'name' => 'EU Carbon Permits',
            'source' => 'market_reporting',
            'type' => 'spot',
            'formatted' => '€78.40',
        ]);

        $this->assertSame('EUR', $price->currency);
        $this->assertSame('tonne', $price->unit);
        $this->assertSame('EU Carbon Permits', $price->name);
        $this->assertSame('market_reporting', $price->source);
        $this->assertSame('spot', $price->type);
        $this->assertSame('€78.40', $price->formatted);
    }

    /**
     * The optional fields stay optional: absent and explicitly null are both
     * fine, and neither invents a value.
     *
     * @return array<string, array{string}>
     */
    public static function optionalFields(): array
    {
        return [
            'unit' => ['unit'],
            'name' => ['name'],
            'source' => ['source'],
            'type' => ['type'],
            'formatted' => ['formatted'],
        ];
    }

    #[DataProvider('optionalFields')]
    public function testOptionalFieldsMayBeAbsentOrNull(string $field): void
    {
        $base = ['code' => 'BRENT_CRUDE_USD', 'price' => 71.80, 'currency' => 'USD'];

        $absent = Price::fromArray($base);
        $this->assertNull($absent->{self::property($field)});

        $explicitNull = Price::fromArray($base + [$field => null]);
        $this->assertNull($explicitNull->{self::property($field)});
    }

    /**
     * Insignificant whitespace around a currency label is normalized rather
     * than passed through, so `$price->currency === 'EUR'` does what a caller
     * expects. This is the only value the DTO adjusts, and it changes no
     * meaning.
     */
    public function testCurrencyWhitespaceIsNormalized(): void
    {
        $price = Price::fromArray([
            'code' => 'EU_CARBON_EUR',
            'price' => 78.40,
            'currency' => "  EUR\n",
        ]);

        $this->assertSame('EUR', $price->currency);
    }

    /**
     * A coerced field must never have reached a caller through the client
     * either.
     */
    public function testClientRefusesARowWithACoercedUnit(): void
    {
        $transport = new MockTransport();
        $transport->queue(200, [
            'status' => 'success',
            'data' => [
                'code' => 'EU_CARBON_EUR',
                'price' => 78.40,
                'currency' => 'EUR',
                'unit' => ['tonne'],
            ],
        ]);
        $client = new Client(self::KEY, Client::DEFAULT_BASE_URL, 10.0, 0, $transport);

        $this->expectException(ApiException::class);

        $client->latest('EU_CARBON_EUR');
    }

    /**
     * No PHP warning is emitted on the way: the old `(string)` cast on an
     * array raised "Array to string conversion", which in a strict error
     * handler is an exception from library internals and in a lax one is a
     * line in a log nobody reads.
     */
    public function testNoArrayToStringWarningIsEmitted(): void
    {
        $seen = [];
        set_error_handler(static function (int $errno, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        try {
            Price::fromArray([
                'code' => 'EU_CARBON_EUR',
                'price' => 78.40,
                'currency' => ['EUR'],
            ]);
        } catch (ApiException) {
            // expected
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $seen, 'PHP warnings were raised: ' . implode('; ', $seen));
    }

    private static function property(string $field): string
    {
        return $field;
    }
}
