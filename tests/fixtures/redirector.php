<?php

declare(strict_types=1);

/**
 * Loopback stand-in for an API host that answers with a redirect.
 *
 * REDIRECT_TO is the origin to bounce to (a foreign one in the cross-origin
 * case, its own in the same-origin case); REDIRECT_STATUS picks the 3xx code.
 */
$to = (string) getenv('REDIRECT_TO');
$status = (int) (getenv('REDIRECT_STATUS') ?: '302');

header('Location: ' . $to . ($_SERVER['REQUEST_URI'] ?? '/'), true, $status);
header('Content-Type: application/json');

// A body on the redirect itself: if the SDK ever returned a 3xx body as data,
// this is what a caller would get.
echo json_encode([
    'status' => 'success',
    'data' => ['code' => 'REDIRECT_BODY', 'price' => 2, 'currency' => 'USD'],
], JSON_THROW_ON_ERROR);
