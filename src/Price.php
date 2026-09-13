<?php

declare(strict_types=1);

namespace OilPriceAPI;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
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
    /**
     * The absolute timestamp spellings the API is allowed to speak.
     *
     * Every entry is anchored with '!' so unspecified fields reset to the
     * epoch rather than to "now", and each is tried with `getLastErrors()`
     * checked afterwards. Relative expressions ('now', 'next friday',
     * '+1 week') are deliberately absent: the `DateTimeImmutable` constructor
     * accepts them without any error at all, which is how a corrupt field
     * became a plausible observation time.
     *
     * @var list<string>
     */
    private const TIMESTAMP_FORMATS = [
        '!Y-m-d\\TH:i:sP',      // RFC 3339 / ATOM, including the 'Z' spelling
        '!Y-m-d\\TH:i:s.uP',    // RFC 3339 with fractional seconds
        '!Y-m-d\\TH:i:s',       // naive ISO 8601, read as UTC
        '!Y-m-d\\TH:i:s.u',
        '!Y-m-d H:i:sP',        // space separator (Postgres / Rails #to_s)
        '!Y-m-d H:i:s.uP',
        '!Y-m-d H:i:s',
        '!Y-m-d H:i:s.u',
        '!Y-m-d',               // date only
    ];

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
     * A row that does not carry a usable code and a numeric price is rejected
     * rather than defaulted: a manufactured $0.00 is indistinguishable from a
     * real quote once it leaves the SDK. Legitimate zero and negative prices
     * are preserved.
     *
     * The timestamp is held to the same standard. Only an unambiguous absolute
     * value is accepted ({@see self::TIMESTAMP_FORMATS}); anything PHP would
     * have to repair or interpret relative to "now" raises rather than
     * producing a plausible-looking date.
     *
     * @param array<string, mixed> $data
     *
     * @throws ApiException when a required field is missing or unparseable
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

        $timestamp = $data['created_at'] ?? $data['updated_at'] ?? null;
        $updatedAt = null;
        if (is_string($timestamp) && $timestamp !== '') {
            $updatedAt = self::parseTimestamp($timestamp, $data['code']);
        }

        $change = $data['change_24h'] ?? $data['change_percent_24h'] ?? null;

        return new self(
            code: $data['code'],
            price: (float) $data['price'],
            currency: (string) ($data['currency'] ?? 'USD'),
            updatedAt: $updatedAt,
            change24h: is_numeric($change) ? (float) $change : null,
            name: isset($data['name']) ? (string) $data['name'] : null,
            unit: isset($data['unit']) ? (string) $data['unit'] : null,
            source: isset($data['source']) ? (string) $data['source'] : null,
            type: isset($data['type']) ? (string) $data['type'] : null,
            formatted: isset($data['formatted']) ? (string) $data['formatted'] : null,
        );
    }

    /**
     * Parse an observation timestamp, or refuse it.
     *
     * PHP will happily hand back a usable object for input it had to repair:
     * `createFromFormat(ATOM, '2026-13-45T99:99:99Z')` returns
     * 2027-02-18T04:40:39Z and reports the repair only through the static
     * `getLastErrors()`. The constructor is looser still and accepts 'now',
     * 'next friday' and '+1 week' with no error at all. Both paths turn a
     * corrupt field into a plausible date, which misplaces a correct price in
     * time - a failure nobody spots the way they spot a $0.00 Brent quote.
     *
     * So: an explicit list of absolute formats, and a parse only counts when
     * `getLastErrors()` reports zero warnings AND zero errors.
     *
     * Leap seconds ('23:59:60') are rejected on purpose. PHP has no
     * representation for one and rolls it into the next minute, so accepting
     * it would be a silent one-second shift - the same fabrication in
     * miniature.
     *
     * @throws ApiException when the value is not an unambiguous absolute timestamp
     */
    private static function parseTimestamp(string $value, string $code): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        foreach (self::TIMESTAMP_FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, $utc);
            if (!$parsed instanceof DateTimeImmutable) {
                continue;
            }

            // getLastErrors() returns false when the parse was clean, and an
            // array of counts when PHP had to warn (rolled-over date, trailing
            // data) or error. Anything but a clean parse is a fabrication.
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            return $parsed;
        }

        throw new ApiException(sprintf(
            'Price row for %s carries an unparseable timestamp (%s); refusing to '
            . 'report a fabricated observation time. Expected an absolute '
            . 'timestamp such as 2026-07-19T12:00:00Z.',
            $code,
            var_export($value, true),
        ));
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
