<?php

declare(strict_types=1);

namespace OilPriceAPI\Http;

use OilPriceAPI\Exception\TransportException;

/**
 * Default HTTP transport built on ext-curl.
 *
 * Zero third-party dependencies so the SDK runs anywhere PHP does,
 * including shared hosting and WordPress environments.
 */
final class CurlTransport implements HttpTransport
{
    public function request(string $method, string $url, array $headers, float $timeout): HttpResponse
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(min($timeout, 10.0) * 1000),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);

            throw new TransportException(
                sprintf('HTTP request to %s failed: %s (cURL error %d)', $url, $error, $errno)
            );
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        // Note: no curl_close() - it has been a no-op since PHP 8.0 and is
        // deprecated as of PHP 8.5; the handle is freed when it goes out of scope.
        return new HttpResponse($statusCode, $responseHeaders, (string) $body);
    }
}
