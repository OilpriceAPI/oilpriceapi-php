<?php

declare(strict_types=1);

namespace OilPriceAPI\Exception;

/**
 * Thrown when a request is not authenticated (HTTP 401), or when a keyed
 * endpoint is called on a client constructed without an API key.
 *
 * Create or manage an API key at
 * https://www.oilpriceapi.com/auth/signup?utm_source=php-sdk
 */
final class AuthenticationException extends ApiException
{
    public const SIGNUP_URL = 'https://www.oilpriceapi.com/auth/signup?utm_source=php-sdk';

    /**
     * @param array<string, mixed> $responseBody
     */
    public function __construct(
        string $message = 'Invalid API key.',
        int $statusCode = 401,
        array $responseBody = [],
    ) {
        parent::__construct(
            rtrim($message, '. ') . '. Create or manage an API key at ' . self::SIGNUP_URL,
            $statusCode,
            $responseBody,
        );
    }
}
