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
     * A row that does not carry a usable code and a numeric price is rejected
     * rather than defaulted: a manufactured $0.00 is indistinguishable from a
     * real quote once it leaves the SDK. Legitimate zero and negative prices
     * are preserved.
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
