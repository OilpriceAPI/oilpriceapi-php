# OilPriceAPI PHP SDK

The official PHP client for source-timestamped oil, gas, refined-product,
futures, and related energy data from [OilPriceAPI](https://www.oilpriceapi.com).

[![Packagist Version](https://img.shields.io/packagist/v/oilpriceapi/oilpriceapi)](https://packagist.org/packages/oilpriceapi/oilpriceapi)
[![PHP Version](https://img.shields.io/packagist/dependency-v/oilpriceapi/oilpriceapi/php)](https://packagist.org/packages/oilpriceapi/oilpriceapi)
[![Tests](https://github.com/OilpriceAPI/oilpriceapi-php/actions/workflows/test.yml/badge.svg)](https://github.com/OilpriceAPI/oilpriceapi-php/actions/workflows/test.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

[Create an API key](https://www.oilpriceapi.com/auth/signup?utm_source=php-sdk) |
[Documentation](https://docs.oilpriceapi.com) |
[API explorer](https://api.oilpriceapi.com/swagger) |
[Pricing](https://www.oilpriceapi.com/pricing?utm_source=php-sdk-limit)

## Requirements

- PHP 8.1 or newer
- `ext-curl` and `ext-json`
- API base URL: `https://api.oilpriceapi.com`
- Auth header: `Authorization: Token YOUR_API_KEY`
- Environment variable used by the executable example: `OILPRICEAPI_KEY`

The package has no third-party runtime dependency.

## Install

```bash
composer require oilpriceapi/oilpriceapi
```

## First Request

The canonical authenticated first request is:

```text
GET /v1/prices/latest?by_code=BRENT_CRUDE_USD
```

Run the packaged, tested example:

```bash
export OILPRICEAPI_KEY="your-api-key"
php vendor/oilpriceapi/oilpriceapi/examples/quickstart.php
```

The same request in application code:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use OilPriceAPI\Client;

$client = new Client(); // reads OILPRICEAPI_KEY
$brent = $client->latest('BRENT_CRUDE_USD');

printf(
    "%s %.2f %s/%s as of %s (source: %s)\n",
    $brent->code,
    $brent->price,
    $brent->currency,
    $brent->unit ?? 'unknown',
    $brent->updatedAt?->format(DATE_ATOM) ?? 'unknown',
    $brent->source ?? 'unknown',
);
```

For missing configuration and actionable 401, 403, and 429 recovery, use the
exact source in [`examples/quickstart.php`](examples/quickstart.php). CI builds
a Composer ZIP, installs it into a clean project, and runs every recovery path
against fixtures.

## Latest Response Compatibility

Production returns a singleton `data` object for the canonical latest-price
request, so `latest('BRENT_CRUDE_USD')` returns one `Price`. The SDK retains
support for a legacy `data.prices[]` envelope, returned as a list, but rejects a
successful response that contains no usable price. Pass a commodity code when
the caller requires a predictable single `Price` result.

`Price` is immutable and exposes `code`, `price`, `currency`, `updatedAt`,
`source`, `change24h`, `name`, `unit`, `type`, and `formatted` when supplied by
the API.

## Demo Request

The demo endpoint does not require an API key:

```php
$client = new \OilPriceAPI\Client();
foreach ($client->demoPrices() as $price) {
    printf("%s %.2f %s/%s\n",
        $price->code,
        $price->price,
        $price->currency,
        $price->unit ?? 'unknown',
    );
}
```

Demo availability and limits are returned by the endpoint. Authenticated
dataset access and limits vary by plan, source, and account entitlement.

## Historical Prices

```php
$day = $client->pastDay('BRENT_CRUDE_USD');
$week = $client->pastWeek('BRENT_CRUDE_USD');
$month = $client->pastMonth('BRENT_CRUDE_USD');
$year = $client->pastYear('BRENT_CRUDE_USD');
```

Each method returns a list of immutable `Price` objects.

## Typed Errors

```php
use OilPriceAPI\Exception\ApiException;
use OilPriceAPI\Exception\AuthenticationException;
use OilPriceAPI\Exception\RateLimitException;

try {
    $brent = $client->latest('BRENT_CRUDE_USD');
} catch (AuthenticationException $error) {
    error_log('Replace OILPRICEAPI_KEY with an active key.');
} catch (RateLimitException $error) {
    error_log(sprintf('Retry after %d seconds.', $error->retryAfter ?? 0));
} catch (ApiException $error) {
    if (in_array($error->statusCode, [402, 403], true)) {
        error_log('Review dataset access at https://www.oilpriceapi.com/pricing');
    } else {
        error_log(sprintf('Request failed with HTTP %d.', $error->statusCode));
    }
}
```

All SDK exceptions extend `ApiException`. `RateLimitException` exposes
`retryAfter` and the server-reported `limit` when present.

## Retries and Timeouts

The client retries `429` and `5xx` responses with bounded exponential backoff
and honors `Retry-After`.

```php
$client = new \OilPriceAPI\Client(
    apiKey: getenv('OILPRICEAPI_KEY') ?: null,
    timeout: 10.0,
    maxRetries: 2,
);
```

For tests, `baseUrl`, `HttpTransport`, and the retry sleeper are injectable.
The executable example also reads `OILPRICEAPI_BASE_URL` when a fixture or
private compatible endpoint is required.

## Raw GET Escape Hatch

Use `raw()` for a versioned GET route that does not yet have a typed method:

```php
$curve = $client->raw()->get('/v1/futures/ice-brent/curve');
```

Availability varies by dataset, plan, source, and account entitlement. Review
[current access](https://www.oilpriceapi.com/pricing) rather than relying on a
plan claim copied into package metadata.

## Reviewed Product Facts

The versioned, reviewed contract is
[`product-facts.json`](https://api.oilpriceapi.com/product-facts.json). Mutable
offer, catalog, freshness, entitlement, and data-rights claims should link to
that contract instead of being duplicated in SDK documentation.

Current reviewed catalog wording: a broad catalog spanning crude oil, natural
gas, refined products, futures, marine fuels, carbon markets, metals, forex,
and selected energy-intelligence datasets. See the
[commodity catalog](https://www.oilpriceapi.com/commodities) for current
availability.

Source timestamps describe the values in each response. They do not imply one
sitewide update interval: refresh cadence varies by source, market hours,
dataset, and plan.

Standard plans provide API access, normalization, monitoring, and delivery;
they do not grant ownership of source data or unrestricted raw-data
redistribution rights. See the
[data usage policy](https://www.oilpriceapi.com/legal/data-usage).

## Verify This Repository

```bash
composer validate --strict
composer install
composer test
./scripts/clean-install-smoke.sh
```

The guarded production smoke requires `OILPRICEAPI_TEST_KEY`:

```bash
OILPRICEAPI_KEY="your-test-key" php examples/smoke.php
```

## Support

- [Documentation](https://docs.oilpriceapi.com)
- [API explorer](https://api.oilpriceapi.com/swagger)
- [Status](https://status.oilpriceapi.com)
- [GitHub issues](https://github.com/OilpriceAPI/oilpriceapi-php/issues)
- support@oilpriceapi.com

MIT licensed. See [LICENSE](LICENSE).
