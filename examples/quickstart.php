<?php

declare(strict_types=1);

use OilPriceAPI\Client;
use OilPriceAPI\Exception\ApiException;
use OilPriceAPI\Exception\AuthenticationException;
use OilPriceAPI\Exception\RateLimitException;

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    dirname(__DIR__, 3) . '/autoload.php',
];
$autoload = null;
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "Composer autoloader not found; install oilpriceapi/oilpriceapi first.\n");
    exit(2);
}
require $autoload;

$apiKey = getenv('OILPRICEAPI_KEY');
if ($apiKey === false || trim($apiKey) === '') {
    fwrite(STDERR, "OILPRICEAPI_KEY is required; create a key at https://www.oilpriceapi.com/auth/signup\n");
    exit(2);
}

$baseUrl = getenv('OILPRICEAPI_BASE_URL');
$client = new Client(
    apiKey: $apiKey,
    baseUrl: $baseUrl !== false && $baseUrl !== '' ? $baseUrl : Client::DEFAULT_BASE_URL,
    maxRetries: 0,
);

try {
    $brent = $client->latest('BRENT_CRUDE_USD');
} catch (AuthenticationException) {
    fwrite(STDERR, "Authentication failed; replace OILPRICEAPI_KEY with an active key.\n");
    exit(1);
} catch (RateLimitException $error) {
    $retry = $error->retryAfter !== null ? sprintf(' Retry after %d seconds.', $error->retryAfter) : '';
    fwrite(STDERR, 'Request limit reached.' . $retry . " Review https://www.oilpriceapi.com/pricing\n");
    exit(1);
} catch (ApiException $error) {
    if (in_array($error->statusCode, [402, 403], true)) {
        fwrite(STDERR, "This account cannot access the requested dataset; review https://www.oilpriceapi.com/pricing\n");
    } else {
        fwrite(STDERR, sprintf("Latest-price request failed (HTTP %d).\n", $error->statusCode));
    }
    exit(1);
}

printf(
    "%s %.2f %s/%s as of %s (source: %s)\n",
    $brent->code,
    $brent->price,
    $brent->currency,
    $brent->unit ?? 'unknown',
    $brent->updatedAt?->format(DATE_ATOM) ?? 'unknown',
    $brent->source ?? 'unknown',
);
