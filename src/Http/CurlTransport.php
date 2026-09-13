<?php

declare(strict_types=1);

namespace OilPriceAPI\Http;

use OilPriceAPI\Exception\TransportException;

/**
 * Default HTTP transport built on ext-curl.
 *
 * Zero third-party dependencies so the SDK runs anywhere PHP does,
 * including shared hosting and WordPress environments.
 *
 * Redirects are not followed. The client validates the origin of the URL it
 * sends; a `Location` header is the server choosing a different one after the
 * fact, which is outside that guard. Following one let a 302 from the API host
 * return a body from somewhere else as an authoritative price, and on libcurl
 * older than 7.58.0 carried `Authorization: Token <key>` along with it. The
 * production API does not redirect, so nothing legitimate is lost.
 */
final class CurlTransport implements HttpTransport
{
    public function request(string $method, string $url, array $headers, float $timeout): HttpResponse
    {
        if ($url === '' || $method === '') {
            throw new TransportException(
                'A transport request needs a non-empty HTTP method and URL; '
                . 'got method ' . var_export($method, true) . ' and URL ' . var_export($url, true) . '.',
            );
        }

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
            // Do not follow redirects: see the class docblock. The credential
            // must not leave the origin the client already validated.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
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
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        // Tripwire, not the control. With redirect following off this can only
        // differ if someone re-enables it or a future libcurl changes its
        // default, and in either case the body must not be returned.
        if ($effectiveUrl !== '' && !self::sameOrigin($url, $effectiveUrl)) {
            throw new TransportException(sprintf(
                'Refusing the response from %s: the request to %s was redirected to a different '
                . 'origin, and data from another origin is not this API\'s data.',
                $effectiveUrl,
                $url,
            ));
        }

        if ($statusCode >= 300 && $statusCode < 400) {
            throw new TransportException(sprintf(
                'HTTP request to %s was answered with a %d redirect to %s. This SDK does not '
                . 'follow redirects, because a redirect moves the request - and the API key - '
                . 'to an origin the client never validated. Point $baseUrl at the final URL if '
                . 'the redirect is intentional.',
                $url,
                $statusCode,
                $responseHeaders['location'] ?? '(no Location header)',
            ));
        }

        // Note: no curl_close() - it has been a no-op since PHP 8.0 and is
        // deprecated as of PHP 8.5; the handle is freed when it goes out of scope.
        return new HttpResponse($statusCode, $responseHeaders, (string) $body);
    }

    /**
     * Scheme, host and effective port must all match; userinfo on either side
     * is a mismatch, because the URL the client built carries none.
     */
    private static function sameOrigin(string $a, string $b): bool
    {
        $left = parse_url($a);
        $right = parse_url($b);

        if (!is_array($left) || !is_array($right)) {
            return false;
        }

        return self::originOf($left) === self::originOf($right);
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function originOf(array $parts): string
    {
        $scheme = self::lowerPart($parts, 'scheme');
        $host = self::lowerPart($parts, 'host');
        $defaultPorts = ['http' => 80, 'https' => 443];
        $port = $parts['port'] ?? ($defaultPorts[$scheme] ?? null);
        $userInfo = isset($parts['user']) || isset($parts['pass']) ? 'userinfo@' : '';

        return $scheme . '://' . $userInfo . $host . ':' . (is_int($port) ? (string) $port : '');
    }

    /**
     * A parse_url() part, lowercased. A non-string part is an origin we cannot
     * name, which must not compare equal to one we can - hence '' rather than
     * a cast that would turn null, 0 or false into something plausible.
     *
     * @param array<string, mixed> $parts
     */
    private static function lowerPart(array $parts, string $key): string
    {
        $value = $parts[$key] ?? null;

        return is_string($value) ? strtolower($value) : '';
    }
}
