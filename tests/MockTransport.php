<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use OilPriceAPI\Http\HttpResponse;
use OilPriceAPI\Http\HttpTransport;
use RuntimeException;

/**
 * Queue-based fake transport. No network access in tests.
 */
final class MockTransport implements HttpTransport
{
    /** @var list<HttpResponse> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, timeout: float}> */
    public array $requests = [];

    public function queue(int $statusCode, array $body = [], array $headers = []): self
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        $this->queue[] = new HttpResponse($statusCode, $normalized, json_encode($body, JSON_THROW_ON_ERROR));

        return $this;
    }

    public function queueRaw(int $statusCode, string $body, array $headers = []): self
    {
        $this->queue[] = new HttpResponse($statusCode, $headers, $body);

        return $this;
    }

    public function request(string $method, string $url, array $headers, float $timeout): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'timeout');

        $response = array_shift($this->queue);
        if ($response === null) {
            throw new RuntimeException('MockTransport queue exhausted for ' . $url);
        }

        return $response;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }
}
