<?php

declare(strict_types=1);

namespace OilPriceAPI;

use DateTimeImmutable;
use DateTimeInterface;
use OilPriceAPI\Exception\ApiException;

/**
 * Immutable price data transfer object.
 *
 * Example:
 *
 *     $price = $client->latest('BRENT_CRUDE_USD');
 *     echo $price->code;                                // BRENT_CRUDE_USD
 *     echo $price->price;                               // e.g. XX.XX
 *     echo $price->currency;                            // USD
 *     echo $price->updatedAt?->format(DATE_ATOM);       // 2026-01-01T12:00:00+00:00
 */
final class Price
{
    public function __construct(
        public readonly string $code,
        public readonly float $price,
        public readonly string $currency,
        public readonly ?DateTimeImmutable $updatedAt = null,
        public readonly ?float $change24h = null,
        public readonly ?string $name = null,
        public readonly ?string $unit = null,
        public readonly ?string $source = null,
        public readonly ?string $type = null,
        public readonly ?string $formatted = null,
    ) {
    }

    /**
     * Build a Price from a decoded API payload.
     *
     * Tolerates the field-name variations across endpoints:
     * `created_at`/`updated_at` for the timestamp and `change_24h`/
     * `change_percent_24h` for the 24h change.
     *
     * A row that does not carry a usable code, a numeric price and a currency
     * is rejected rather than defaulted: a manufactured $0.00 is
     * indistinguishable from a real quote once it leaves the SDK. Legitimate
     * zero and negative prices are preserved.
     *
     * `currency` is required. It used to default to 'USD', which labelled a
     * euro-denominated carbon price as dollars - and unlike a $0.00 Brent
     * quote, `78.40 USD` on an EUA contract is plausible, roughly 8% wrong,
     * and flows into a model undetected. The catalogue is not USD-only; the
     * repo's own fixtures carry EU_CARBON_EUR.
     *
     * The descriptive fields are read, not coerced. `(string)` on an array
     * produced the literal 'Array' plus a PHP warning, on `true` produced '1',
     * and on the ISO numeric currency 978 produced '978'. A mislabelled unit -
     * barrel where the payload said tonne - is the same harm class as a wrong
     * number, and harder to spot because the number beside it is right.
     *
     * @param array<string, mixed> $data
     *
     * @throws ApiException when a required field is missing, unparseable or of
     *                      the wrong type
     */
    public static function fromArray(array $data): self
    {
        if (
            !isset($data['code'])
            || !is_string($data['code'])
            || trim($data['code']) === ''
        ) {
            throw new ApiException('Price row is missing a usable commodity code.');
        }

        if (!array_key_exists('price', $data) || !is_numeric($data['price'])) {
            throw new ApiException(sprintf(
                'Price row for %s is missing a numeric price; refusing to report it as 0.',
                $data['code'],
            ));
        }

        $currency = $data['currency'] ?? null;
        if (!is_string($currency) || trim($currency) === '') {
            throw new ApiException(sprintf(
                'Price row for %s is missing a usable currency (got %s); refusing to label '
                . 'it USD by default, because a mislabelled currency is a plausible wrong '
                . 'number rather than an obvious one.',
                $data['code'],
                get_debug_type($currency),
            ));
        }

        $timestamp = $data['created_at'] ?? $data['updated_at'] ?? null;
        $updatedAt = null;
        if (is_string($timestamp) && $timestamp !== '') {
            $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $timestamp);
            if ($parsed === false) {
                try {
                    $parsed = new DateTimeImmutable($timestamp);
                } catch (\Exception) {
                    $parsed = null;
                }
            }
            if (!$parsed instanceof DateTimeImmutable) {
                throw new ApiException(sprintf(
                    'Price row for %s carries an unparseable timestamp.',
                    $data['code'],
                ));
            }
            $updatedAt = $parsed;
        }

        $change = $data['change_24h'] ?? $data['change_percent_24h'] ?? null;

        return new self(
            code: $data['code'],
            price: (float) $data['price'],
            // The only value this DTO adjusts: insignificant surrounding
            // whitespace, so `$price->currency === 'EUR'` behaves. Nothing
            // about the label changes.
            currency: trim($currency),
            updatedAt: $updatedAt,
            change24h: is_numeric($change) ? (float) $change : null,
            name: self::optionalString($data, 'name', $data['code']),
            unit: self::optionalString($data, 'unit', $data['code']),
            source: self::optionalString($data, 'source', $data['code']),
            type: self::optionalString($data, 'type', $data['code']),
            formatted: self::optionalString($data, 'formatted', $data['code']),
        );
    }

    /**
     * Read an optional descriptive field, or refuse it.
     *
     * Absent and explicitly null both mean "not provided". Anything that is
     * present but not a string is a malformed row, not something to cast: the
     * cast is what turned `['EUR']` into 'Array' and `978` into '978'.
     *
     * @param array<string, mixed> $data
     *
     * @throws ApiException when the field is present with a non-string value
     */
    private static function optionalString(array $data, string $key, string $code): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (!is_string($data[$key])) {
            throw new ApiException(sprintf(
                'Price row for %s carries a non-string %s (%s); refusing to coerce it into a '
                . 'label, because a wrong label is as costly as a wrong number.',
                $code,
                $key,
                get_debug_type($data[$key]),
            ));
        }

        return $data[$key];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'price' => $this->price,
            'currency' => $this->currency,
            'updated_at' => $this->updatedAt?->format(DateTimeInterface::ATOM),
            'change_24h' => $this->change24h,
            'name' => $this->name,
            'unit' => $this->unit,
            'source' => $this->source,
            'type' => $this->type,
            'formatted' => $this->formatted,
        ];
    }
}
