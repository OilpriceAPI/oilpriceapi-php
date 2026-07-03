# OilPriceAPI — PHP SDK

> **Real-time oil, gas, LNG, carbon and fuel prices in your PHP app in under 60 seconds** — one class, zero dependencies, works everywhere PHP does (including shared hosting and WordPress).

[![Packagist Version](https://img.shields.io/packagist/v/oilpriceapi/oilpriceapi)](https://packagist.org/packages/oilpriceapi/oilpriceapi)
[![Downloads](https://img.shields.io/packagist/dt/oilpriceapi/oilpriceapi)](https://packagist.org/packages/oilpriceapi/oilpriceapi)
[![PHP Version](https://img.shields.io/packagist/dependency-v/oilpriceapi/oilpriceapi/php)](https://packagist.org/packages/oilpriceapi/oilpriceapi)
[![Tests](https://github.com/OilpriceAPI/oilpriceapi-php/actions/workflows/test.yml/badge.svg)](https://github.com/OilpriceAPI/oilpriceapi-php/actions/workflows/test.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

**[Get a Free API Key](https://oilpriceapi.com/auth/signup?utm_source=php-sdk)** · **[Documentation](https://docs.oilpriceapi.com)** · **[Pricing](https://oilpriceapi.com/pricing?utm_source=php-sdk-limit)** · **[Live demo, no key needed ↓](#try-it-without-an-api-key-demo-mode)**

The official PHP SDK for [OilPriceAPI](https://oilpriceapi.com), the commodity price API behind fintech dashboards, fleet & logistics tools, maritime compliance platforms and energy analytics products — serving **2M+ API requests every month**.

- **Zero dependencies** — only `ext-curl` and `ext-json` (bundled with virtually every PHP install). No Guzzle, no framework, no conflicts with your host's packages.
- **PHP 8.1+**, strict types, immutable `Price` DTOs.
- **Resilient** — automatic retries with exponential backoff + jitter on 429/5xx, honors `Retry-After`.
- **Typed errors** — `AuthenticationException`, `RateLimitException`, `ApiException`.
- **Demo mode** — try it without an API key.
- **Escape hatch** — `$client->raw()->get(...)` reaches any endpoint, present or future.

## What can you get?

110+ commodities across the energy complex. The ones our customers poll the most:

| Code              | What it is                 | Typical use                                 |
| ----------------- | -------------------------- | ------------------------------------------- |
| `BRENT_CRUDE_USD` | Brent crude (global)       | dashboards, market context, deal models     |
| `WTI_USD`         | WTI crude (US)             | trading tools, macro models                 |
| `NATURAL_GAS_USD` | Henry Hub natural gas      | energy analytics, procurement               |
| `DUTCH_TTF_EUR`   | TTF gas (Europe)           | European energy, LNG analytics              |
| `JKM_LNG_USD`     | JKM LNG (Asia)             | LNG trading & shipping                      |
| `EU_CARBON_EUR`   | EU ETS carbon allowances   | CBAM reporting, maritime compliance, ESG    |
| `DIESEL_USD`      | Diesel (Gulf Coast)        | fleet fuel-surcharge calculators, logistics |
| `JET_FUEL_USD`    | Jet fuel                   | aviation ops & charter pricing              |
| `VLSFO_USD`       | Marine bunker fuel (0.5%S) | voyage costing, bunker procurement          |
| `GOLD_USD`        | Gold                       | macro & portfolio context                   |

## Install

```bash
composer require oilpriceapi/oilpriceapi
```

## Quick start

```php
use OilPriceAPI\Client;

$client = new Client('your_api_key'); // or set OILPRICEAPI_KEY env var
$brent  = $client->latest('BRENT_CRUDE_USD');
echo $brent->price; // e.g. XX.XX (USD per barrel)
```

`latest()` without a code returns every commodity on your plan as a list of `Price` objects.

## No composer? Plain PHP

No SDK, no packages — this is the whole integration with nothing but PHP's built-in cURL:

```php
<?php
// Latest Brent price from OilPriceAPI - plain PHP, no libraries needed.
$apiKey = getenv('OILPRICEAPI_KEY') ?: 'your_api_key_here';

$ch = curl_init('https://api.oilpriceapi.com/v1/prices/latest?by_code=BRENT_CRUDE_USD');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Token ' . $apiKey,
        'Accept: application/json',
    ],
]);
$response = curl_exec($ch);

$json = json_decode($response, true);
echo $json['data']['price'] ?? 'No price returned'; // e.g. XX.XX
```

## Try it without an API key (demo mode)

The client works out of the box — no signup required — via the demo endpoint (rate limited per IP, free-tier commodities only):

```php
$client = new \OilPriceAPI\Client(); // no key

foreach ($client->demoPrices() as $price) {
    printf("%s: %s %.2f\n", $price->code, $price->currency, $price->price);
}
```

Calling a keyed endpoint without a key throws an `AuthenticationException` that tells you exactly where to [get a free key](https://oilpriceapi.com/auth/signup?utm_source=php-sdk).

## Historical prices

```php
$day   = $client->pastDay('BRENT_CRUDE_USD');   // last 24 hours
$week  = $client->pastWeek('BRENT_CRUDE_USD');
$month = $client->pastMonth('BRENT_CRUDE_USD');
$year  = $client->pastYear('BRENT_CRUDE_USD');

foreach ($week as $price) {
    echo $price->updatedAt?->format('Y-m-d H:i'), ' -> ', $price->price, PHP_EOL;
}
```

Each method returns a `list<OilPriceAPI\Price>` — an immutable DTO with `code`, `price` (float), `currency`, `updatedAt` (`DateTimeImmutable|null`), `change24h` (`float|null`), plus `name`, `unit`, `type`, `formatted` where the API provides them, and a `toArray()` helper.

## Beyond oil — gas, LNG, carbon & fuels

OilPriceAPI is not just crude. The same client covers the energy complex that maritime compliance, fleet & logistics, LNG analytics and CBAM reporting teams need:

```php
// EU ETS carbon allowances (EUR/tonne) - CBAM & maritime compliance
$eua = $client->latest('EU_CARBON_EUR');
echo $eua->price; // e.g. XX.XX EUR/tonne

// Diesel - fleet & logistics fuel-surcharge calculations
$diesel = $client->latest('DIESEL_USD');

// Dutch TTF natural gas futures curve - LNG & gas analytics
$ttf = $client->raw()->get('/v1/futures/ttf-gas/curve');

// ICE Brent futures curve via the same escape hatch
$curve = $client->raw()->get('/v1/futures/ice-brent/curve');
```

> Futures endpoints require a plan with futures access — see [pricing](https://oilpriceapi.com/pricing?utm_source=php-sdk-limit).

## Any endpoint: the `raw()` escape hatch

New endpoints ship in the API before they ship in the SDK. `raw()` gives you the full decoded JSON envelope for any path:

```php
$response = $client->raw()->get('/v1/futures/ice-brent/curve', ['unit' => 'usd']);
// ['status' => 'success', 'data' => [...]]
```

## Error handling

```php
use OilPriceAPI\Exception\ApiException;
use OilPriceAPI\Exception\AuthenticationException;
use OilPriceAPI\Exception\RateLimitException;

try {
    $price = $client->latest('BRENT_CRUDE_USD');
} catch (AuthenticationException $e) {
    // 401 or missing key - message includes the signup URL
} catch (RateLimitException $e) {
    // 429 after retries - $e->retryAfter (seconds), $e->limit,
    // message includes the upgrade URL
} catch (ApiException $e) {
    // everything else - $e->statusCode, $e->responseBody
}
```

All exceptions extend `ApiException`, so a single `catch` handles everything.

## Retries & timeouts

Requests that hit `429` or `5xx` are retried automatically (default: 3 retries) with exponential backoff plus jitter. If the API sends a `Retry-After` header, it is honored exactly. Everything is configurable:

```php
$client = new \OilPriceAPI\Client(
    apiKey: 'your_api_key',
    timeout: 10.0,     // seconds per request (default 10)
    maxRetries: 3,     // retries on 429/5xx (default 3)
);
```

## WordPress

The SDK has no dependencies to collide with other plugins, so it drops straight into themes and plugins — `composer require` it, or copy `src/` and load it with any PSR-4 autoloader. Prefer no code at all? Use the official [OilPriceAPI WordPress plugin](https://github.com/OilpriceAPI/oilpriceapi-wordpress-plugin) for ready-made price widgets and shortcodes.

## The whole OilPriceAPI toolbox

Same data, every stack:

| Tool                                                                                | Install                                        |
| ----------------------------------------------------------------------------------- | ---------------------------------------------- |
| [Python SDK](https://github.com/OilpriceAPI/python-sdk)                             | `pip install oilpriceapi`                      |
| [Node/TypeScript SDK](https://github.com/OilpriceAPI/oilpriceapi-node)              | `npm install oilpriceapi`                      |
| [Go SDK](https://github.com/OilpriceAPI/oilpriceapi-go)                             | `go get github.com/OilpriceAPI/oilpriceapi-go` |
| [MCP server](https://github.com/OilpriceAPI/mcp-server) (Claude, Cursor, AI agents) | `npx -y oilpriceapi-mcp`                       |
| [WordPress plugin](https://github.com/OilpriceAPI/oilpriceapi-wordpress-plugin)     | wordpress.org, no code                         |

## Testing

```bash
composer install
composer test
```

The test suite is fully offline — HTTP is mocked through the `OilPriceAPI\Http\HttpTransport` interface, which you can also implement to route the SDK through your own HTTP stack.

## License

MIT — see [LICENSE](LICENSE).
