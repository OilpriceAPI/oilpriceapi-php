# Changelog

## 3.0.1 - 2026-09-13

### Fixed

- Keyless demo mode now matches the demo endpoint on a path-segment boundary
  (#23). It used a raw prefix match, `str_starts_with($path, '/v1/demo')`, so
  any path whose characters merely began that way was treated as a demo call
  and sent without the `Authorization` header.

### Internal

- PHPStan level 9 now runs in CI against `src/`, with the 14 errors it reported
  fixed and no baseline (#24).

## 3.0.0 - 2026-09-13

### Breaking changes

- `Price::fromArray()` is no longer total. It is public, documented, and
  callable directly, and it now throws `ApiException` for payloads the 2.x
  line accepted silently.

  **What throws now that did not before:**

  | Row | 2.x behaviour | 3.0 behaviour |
  | --- | --- | --- |
  | no `code`, or a blank or non-string `code` | `code` became `''` | `ApiException` |
  | no `price`, or a non-numeric `price` | `price` became `0.0` | `ApiException` |
  | no `currency` | `currency` became `'USD'` | `ApiException` |
  | a non-string `currency` | `['EUR']` became `'Array'`, `978` became `'978'` | `ApiException` |
  | a non-string `unit`, `name`, `source`, `type` or `formatted` | cast, so `true` became `'1'` | `ApiException` |
  | a `created_at`/`updated_at` PHP had to repair | `2026-13-45T99:99:99Z` became `2027-02-18T04:40:39Z` | `ApiException` |
  | a relative `created_at`/`updated_at` | `now`, `next friday`, `+1 week` all became dates | `ApiException` |

  A legitimate zero or negative price is still preserved; a genuinely empty
  `prices` list still returns an empty array; a row with no timestamp field at
  all is still valid and leaves `updatedAt` null; and an absent or explicitly
  null `unit`, `name`, `source`, `type` or `formatted` still means "not
  provided" rather than an error.

  Two deliberate calls inside the timestamp rule, called out because they
  reject input a reader might expect to pass: a leap second (`23:59:60`) is
  rejected rather than rolled silently into the next minute, and a naive
  timestamp is read as UTC rather than as the host's local timezone.

  **What callers should do:** the methods on `Client` (`latest()`, the
  historical period methods, `demoPrices()`) already surfaced malformed data
  as `ApiException`, so a caller that catches `ApiException` around API calls
  needs no change. A caller that invokes `Price::fromArray()` on its own
  payloads must now wrap it:

  ```php
  try {
      $price = \OilPriceAPI\Price::fromArray($row);
  } catch (\OilPriceAPI\Exception\ApiException $e) {
      // The row was malformed. Previously you received a Price carrying
      // manufactured values - $0.00, an empty code, or an invented date.
      // Handle or skip the row; do not retry, the payload will not change.
  }
  ```

  A caller that relied on the manufactured values - reading `$price->price`
  as `0.0` to mean "no data", say - must switch to catching the exception.
  There is no opt-out, by design: the manufactured values were
  indistinguishable from real quotes once they left the SDK.

- **Redirects are no longer followed.** A `$baseUrl` whose host answers with a
  3xx now raises `TransportException` instead of being followed silently.
  `https://api.oilpriceapi.com` does not redirect, so this affects only a
  custom base URL; point it at the final URL. Details under Security below.

- **New platform requirement: `lib-curl >= 7.58.0`.** `composer.json` accepted
  any libcurl. 7.58.0 is where libcurl began stripping `Authorization` across
  an origin change, which the SDK depended on without saying so. Composer will
  now refuse to install on an older host rather than leaving the credential
  exposed.

- The version jumps 2.1.2 to 3.0.0 with no 2.2.0 in between. The changes above
  shipped to `main` labelled as patches; this corrects the label rather than
  re-releasing the code.

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

### Changed

- **Breaking:** `currency` is required on a price row. `Price::fromArray()`
  defaulted it to `USD`, which labelled a euro-denominated carbon price as
  dollars. A `$0.00` Brent quote is obviously broken and a human catches it;
  `78.40 USD` on an EUA contract is plausible, roughly 8% wrong, and flows into
  a model undetected. The catalogue is not USD-only - the repo's own fixtures
  carry `EU_CARBON_EUR`.

### Fixed

- Reject fabricated observation timestamps. `Price::fromArray()` parsed the
  `created_at`/`updated_at` field without ever consulting
  `DateTimeImmutable::getLastErrors()`, so PHP silently repaired impossible
  values into plausible dates - `2026-13-45T99:99:99Z` became
  `2027-02-18T04:40:39Z` - and the tolerant constructor fallback accepted
  relative expressions such as `now`, `next friday` and `+1 week`. Timestamps
  are now matched against an explicit list of absolute formats and accepted
  only on a parse that reports zero warnings and zero errors; anything else
  raises `ApiException`. Naive timestamps are read as UTC rather than as the
  host's local timezone, and leap seconds are rejected rather than rolled
  silently into the next minute.
- Read the descriptive fields instead of casting them. `currency`, `unit`,
  `name`, `source`, `type` and `formatted` were unchecked `(string)` casts, so
  `currency: ["EUR"]` became the literal `'Array'` plus a PHP
  "Array to string conversion" warning, `true` became `'1'`, and the ISO
  numeric currency `978` became `'978'`. A present-but-non-string value now
  raises `ApiException` naming the field and its actual type; absent and null
  still mean "not provided". Surrounding whitespace on `currency` is
  normalized so `$price->currency === 'EUR'` behaves.
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
