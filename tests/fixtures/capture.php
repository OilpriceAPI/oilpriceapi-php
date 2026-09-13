<?php

declare(strict_types=1);

/**
 * Loopback stand-in for a foreign origin. Records every inbound request so a
 * test can assert that the SDK never delivered one here.
 */
$log = getenv('CAPTURE_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
}

header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'data' => ['code' => 'CAPTURED', 'price' => 1.0, 'currency' => 'USD']], JSON_THROW_ON_ERROR);
