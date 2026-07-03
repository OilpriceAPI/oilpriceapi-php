<?php

declare(strict_types=1);

namespace OilPriceAPI\Http;

/**
 * Immutable value object representing an HTTP response.
 */
final class HttpResponse
{
    /**
     * @param int                   $statusCode HTTP status code
     * @param array<string, string> $headers    Response headers, keys lowercased
     * @param string                $body       Raw response body
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * Case-insensitive header lookup.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
