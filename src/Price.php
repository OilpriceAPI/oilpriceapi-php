<?php

declare(strict_types=1);

namespace OilPriceAPI;

use DateTimeImmutable;
use DateTimeInterface;

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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
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
            $updatedAt = $parsed ?: null;
        }

        $change = $data['change_24h'] ?? $data['change_percent_24h'] ?? null;

        return new self(
            code: (string) ($data['code'] ?? ''),
            price: (float) ($data['price'] ?? 0.0),
            currency: (string) ($data['currency'] ?? 'USD'),
            updatedAt: $updatedAt,
            change24h: is_numeric($change) ? (float) $change : null,
            name: isset($data['name']) ? (string) $data['name'] : null,
            unit: isset($data['unit']) ? (string) $data['unit'] : null,
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
            'type' => $this->type,
            'formatted' => $this->formatted,
        ];
    }
}
