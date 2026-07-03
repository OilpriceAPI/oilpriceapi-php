<?php

declare(strict_types=1);

namespace OilPriceAPI\Exception;

/**
 * Thrown when the API rate limit is exceeded (HTTP 429) and automatic
 * retries have been exhausted.
 *
 * Need more requests? Upgrade at
 * https://oilpriceapi.com/pricing?utm_source=php-sdk-limit
 */
final class RateLimitException extends ApiException
{
    public const UPGRADE_URL = 'https://oilpriceapi.com/pricing?utm_source=php-sdk-limit';

    /**
     * @param int|null             $retryAfter Seconds until the limit resets, if the API said
     * @param string|null          $limit      Plan limit as reported by rate-limit headers, if any
     * @param array<string, mixed> $responseBody
     */
    public function __construct(
        string $message = 'Rate limit exceeded.',
        public readonly ?int $retryAfter = null,
        public readonly ?string $limit = null,
        array $responseBody = [],
    ) {
        $suffix = '';
        if ($retryAfter !== null) {
            $suffix .= sprintf(' Retry after %d seconds.', $retryAfter);
        }
        if ($limit !== null) {
            $suffix .= sprintf(' Current plan limit: %s.', $limit);
        }

        parent::__construct(
            rtrim($message, '. ') . '.' . $suffix . ' Need a higher limit? Upgrade at ' . self::UPGRADE_URL,
            429,
            $responseBody,
        );
    }
}
