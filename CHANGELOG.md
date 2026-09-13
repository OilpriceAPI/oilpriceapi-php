# Changelog

## Unreleased

### Security

- Do not follow HTTP redirects. `CurlTransport` set
  `CURLOPT_FOLLOWLOCATION => true`, so a 302 from the configured API host
  delivered a body from a different origin that the SDK decoded and returned
  as an authoritative price. The #17 origin guard validates the URL the SDK
  sends and cannot see where the server points next. Redirects now raise
  `TransportException` naming the `Location`, the resolved effective URL is
  checked against the requested origin as a tripwire, and `composer.json`
  declares the `lib-curl >=7.58.0` floor the SDK relied on implicitly for
  stripping `Authorization` across an origin change. The production API does
  not redirect, so no supported call pattern changes.
- Reject raw API paths that would move the request off the configured base
  origin. The base URL and the caller-supplied path were concatenated, so a
  path such as `@evil.tld/v1/prices` turned the API host into URL userinfo and
  cURL delivered `Authorization: Token <key>` to `evil.tld`. Paths are now
  normalized to a single leading slash, scheme-relative references, absolute
  URLs, backslashes, userinfo and whitespace are rejected, and the resolved
  origin is compared against the configured base URL before the credential is
  attached. Explicit custom `$baseUrl` values are unaffected.

### Fixed

- Reject malformed price rows instead of reporting them as `$0.00`. A row
  without a usable code or a numeric price, an unparseable timestamp, and a
  missing or non-array `prices` field now raise `ApiException` across
  `latest()`, the historical period methods and `demoPrices()`. Legitimate
  zero and negative prices are preserved, and a genuinely empty `prices` list
  still returns an empty array.
- Stop retrying an exhausted durable quota. A rate-limit response carrying
  `MONTHLY_QUOTA_EXCEEDED`, `TRIAL_LIMIT_EXCEEDED`, `TRIAL_EXPIRED`,
  `EMAIL_CONFIRMATION_REQUIRED` or `DEMO_RATE_LIMIT_EXCEEDED` now fails fast
  instead of being retried against a limit that is already spent; burst and
  circuit-breaker limits are still retried.
- Never shorten `Retry-After`. A delay longer than the client's retry budget
  raises `RateLimitException` carrying the server's own delay instead of coming
  back early, and a negative delta-seconds value falls back to normal backoff
  rather than a zero-second hot retry.

## 2.1.2 (2026-08-11)

### Fixed

- Make the tested package match Packagist's GitHub distribution by excluding
  development-only workflows, tests, and validation tools through Git archive
  attributes. The clean-install gate now scans that exact archive shape and
  rejects a stale packaged SDK version.

## 2.1.1 (2026-08-11)

### Fixed

- Use the instrument-generic Brent futures path in every packaged raw-client
  example and guard future Composer surfaces against venue-path regressions.
- Pin CI actions, disable persisted checkout credentials, and keep
  authenticated production smoke credentials out of pull-request jobs.

## 2.1.0 (2026-07-19)

### Fixed

- Reject successful latest-price responses that contain no usable price.
- Preserve the production `source` field on the immutable `Price` DTO.
- Correct the no-code `latest()` documentation to reflect the production
  singleton response while retaining legacy list-envelope compatibility.

### Changed

- Publish an executable `OILPRICEAPI_KEY` first request with actionable missing
  configuration, 401, 403, and 429 recovery.
- Replace mutable product claims with links to the reviewed product-facts,
  pricing, catalog, and data-rights contracts.
- Add a Composer archive clean-install smoke, strict production first-request
  guard, and public-claim drift test.

## 2.0.0 (2026-07-03)

Ground-up rewrite of the PHP SDK.

### Added

- `OilPriceAPI\Client` with `latest()`, `pastDay()`, `pastWeek()`, `pastMonth()`, `pastYear()`, `demoPrices()`, and a `raw()` escape hatch for versioned GET endpoints.
- Keyless demo mode via `/v1/demo/prices`; helpful `AuthenticationException` (with signup URL) when keyed endpoints are called without a key.
- `OILPRICEAPI_KEY` environment variable fallback.
- Immutable `Price` DTO (`code`, `price`, `currency`, `updatedAt` as `DateTimeImmutable`, `change24h`, plus `name`/`unit`/`type`/`formatted`) with `toArray()`.
- Automatic retries with exponential backoff + jitter on 429/5xx, honoring `Retry-After`.
- Typed exceptions: `ApiException`, `AuthenticationException`, `RateLimitException`, `TransportException`.
- Zero runtime dependencies (`ext-curl` + `ext-json` only); PHP >= 8.1; strict types throughout.
- `HttpTransport` interface for dependency-free testing and custom HTTP stacks.
- PHPUnit suite (offline, mocked transport) and GitHub Actions CI across PHP 8.1/8.2/8.3.
