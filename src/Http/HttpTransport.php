<?php

declare(strict_types=1);

namespace OilPriceAPI\Http;

use OilPriceAPI\Exception\TransportException;

/**
 * Minimal HTTP transport abstraction.
 *
 * The SDK ships with a cURL implementation ({@see CurlTransport}) and uses
 * this interface so tests (or exotic hosting environments) can substitute
 * their own transport without any third-party HTTP client dependency.
 */
interface HttpTransport
{
    /**
     * Execute an HTTP request and return the response.
     *
     * Implementations must NOT throw on non-2xx status codes - error mapping
     * is the client's responsibility. They should only throw
     * {@see TransportException} for network-level failures (DNS, connect,
     * timeout, TLS).
     *
     * @param string                $method  HTTP method (GET, POST, ...)
     * @param string                $url     Absolute URL including query string
     * @param array<string, string> $headers Request headers
     * @param float                 $timeout Total request timeout in seconds
     *
     * @throws TransportException on network-level failure
     */
    public function request(string $method, string $url, array $headers, float $timeout): HttpResponse;
}
