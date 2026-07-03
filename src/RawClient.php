<?php

declare(strict_types=1);

namespace OilPriceAPI;

use Closure;

/**
 * Escape hatch for endpoints the SDK does not model explicitly.
 *
 * Returns the full decoded JSON envelope as an associative array, so ANY
 * OilPriceAPI endpoint is reachable without waiting for an SDK release:
 *
 *     $curve = $client->raw()->get('/v1/futures/ice-brent/curve');
 *     foreach ($curve['data']['contracts'] ?? [] as $contract) {
 *         // ...
 *     }
 */
final class RawClient
{
    /**
     * @param Closure(string, array<string, scalar>): array<string, mixed> $requester
     *
     * @internal constructed by {@see Client::raw()}
     */
    public function __construct(private readonly Closure $requester)
    {
    }

    /**
     * Perform a GET request against any API path.
     *
     * @param string                $path   API path, e.g. '/v1/futures/ice-brent/curve'
     * @param array<string, scalar> $params Query string parameters
     *
     * @return array<string, mixed> Full decoded JSON response (envelope included)
     */
    public function get(string $path, array $params = []): array
    {
        return ($this->requester)($path, $params);
    }
}
