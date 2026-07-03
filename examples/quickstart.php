<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OilPriceAPI\Client;

// Reads the OILPRICEAPI_KEY environment variable automatically.
// Get a free key: https://oilpriceapi.com/auth/signup?utm_source=php-sdk
$client = new Client();

if ($client->hasApiKey()) {
    $brent = $client->latest('BRENT_CRUDE_USD');
    printf(
        "Brent: %s %.2f (as of %s)\n",
        $brent->currency,
        $brent->price,
        $brent->updatedAt?->format(DATE_ATOM) ?? 'n/a',
    );
} else {
    // No key? Demo mode still works (rate limited per IP).
    echo "No API key set - showing demo prices instead.\n";
    foreach ($client->demoPrices() as $price) {
        printf("%-20s %s %.2f\n", $price->code, $price->currency, $price->price);
    }
}
