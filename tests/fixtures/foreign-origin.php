<?php

declare(strict_types=1);

/**
 * Loopback stand-in for the origin a redirect points at. Records every inbound
 * request so a test can assert the SDK never delivered one - and answers with
 * a well-formed, entirely fabricated price so a test can prove the SDK does
 * not hand it back as authoritative data.
 */
$log = getenv('FOREIGN_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
}

header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'data' => ['code' => 'PWNED', 'price' => 1, 'currency' => 'USD'],
], JSON_THROW_ON_ERROR);
