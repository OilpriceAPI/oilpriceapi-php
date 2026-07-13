<?php

declare(strict_types=1);

/**
 * Live smoke test used by CI when OILPRICEAPI_TEST_KEY is configured.
 * Exits non-zero on failure.
 */

require __DIR__ . '/../vendor/autoload.php';

use OilPriceAPI\Client;
use OilPriceAPI\Exception\ApiException;

$client = new Client();

if (!$client->hasApiKey()) {
    fwrite(STDERR, "smoke: no API key available\n");
    exit(1);
}

$brent = $client->latest('BRENT_CRUDE_USD');

if ($brent->code !== 'BRENT_CRUDE_USD' || $brent->price <= 0.0) {
    fwrite(STDERR, "smoke: unexpected latest price payload\n");
    exit(1);
}

try {
    $week = $client->pastWeek('BRENT_CRUDE_USD');

    if ($week === []) {
        fwrite(STDERR, "smoke: past_week returned no prices\n");
        exit(1);
    }

    echo "smoke: OK (latest + past_week)\n";
} catch (ApiException $e) {
    // Historical data is a plan entitlement, not an SDK feature. If the
    // CI key's plan doesn't include it (402/403), the SDK still proved
    // auth + transport + parsing via latest() above, so don't fail CI.
    if ($e->statusCode === 402 || $e->statusCode === 403) {
        echo "smoke: OK (latest; past_week skipped - CI key plan lacks historical access)\n";
        exit(0);
    }

    throw $e;
}
