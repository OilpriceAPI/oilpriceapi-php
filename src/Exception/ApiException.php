<?php

declare(strict_types=1);

namespace OilPriceAPI\Exception;

use RuntimeException;

/**
 * Base exception for all errors returned by the OilPriceAPI service.
 *
 * Catch this to handle every SDK error in one place:
 *
 *     try {
 *         $price = $client->latest('BRENT_CRUDE_USD');
 *     } catch (\OilPriceAPI\Exception\ApiException $e) {
 *         error_log($e->getMessage());
 *     }
 */
class ApiException extends RuntimeException
{
    /**
     * @param string               $message    Human-readable error message
     * @param int                  $statusCode HTTP status code (0 if not applicable)
     * @param array<string, mixed> $responseBody Decoded response body, if any
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $responseBody = [],
    ) {
        parent::__construct($message, $statusCode);
    }
}
