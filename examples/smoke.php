<?php

declare(strict_types=1);

/**
 * Live smoke test used by CI when OILPRICEAPI_TEST_KEY is configured.
 * Exits non-zero on failure.
 */

require __DIR__ . '/../vendor/autoload.php';

use OilPriceAPI\Client;

$client = new Client();

if (!$client->hasApiKey()) {
    fwrite(STDERR, "smoke: no API key available\n");
    exit(1);
}

$brent = $client->latest('BRENT_CRUDE_USD');

if (
    $brent->code !== 'BRENT_CRUDE_USD'
    || $brent->price <= 0.0
    || $brent->source === null
    || $brent->updatedAt === null
) {
    fwrite(STDERR, "smoke: unexpected latest price payload\n");
    exit(1);
}

echo "smoke: OK (canonical latest price)\n";
