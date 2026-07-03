# Changelog

## 2.0.0 (2026-07-03)

Ground-up rewrite of the PHP SDK.

### Added

- `OilPriceAPI\Client` with `latest()`, `pastDay()`, `pastWeek()`, `pastMonth()`, `pastYear()`, `demoPrices()`, and the `raw()` escape hatch for any endpoint.
- Keyless demo mode via `/v1/demo/prices`; helpful `AuthenticationException` (with signup URL) when keyed endpoints are called without a key.
- `OILPRICEAPI_KEY` environment variable fallback.
- Immutable `Price` DTO (`code`, `price`, `currency`, `updatedAt` as `DateTimeImmutable`, `change24h`, plus `name`/`unit`/`type`/`formatted`) with `toArray()`.
- Automatic retries with exponential backoff + jitter on 429/5xx, honoring `Retry-After`.
- Typed exceptions: `ApiException`, `AuthenticationException`, `RateLimitException`, `TransportException`.
- Zero runtime dependencies (`ext-curl` + `ext-json` only); PHP >= 8.1; strict types throughout.
- `HttpTransport` interface for dependency-free testing and custom HTTP stacks.
- PHPUnit suite (offline, mocked transport) and GitHub Actions CI across PHP 8.1/8.2/8.3.
