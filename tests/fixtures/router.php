<?php

declare(strict_types=1);

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$query = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY), $query);
if ($path !== '/v1/prices/latest' || ($query['by_code'] ?? null) !== 'BRENT_CRUDE_USD') {
    http_response_code(404);
    echo json_encode(['error' => 'fixture route not found'], JSON_THROW_ON_ERROR);
    return;
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
switch ($authorization) {
    case 'Token valid-smoke-key':
        echo json_encode([
            'status' => 'success',
            'data' => [
                'code' => 'BRENT_CRUDE_USD',
                'price' => 71.80,
                'currency' => 'USD',
                'unit' => 'barrel',
                'source' => 'market_reporting',
                'created_at' => '2026-07-19T12:00:00Z',
                'updated_at' => '2026-07-19T12:00:00Z',
            ],
        ], JSON_THROW_ON_ERROR);
        break;
    case 'Token invalid-smoke-key':
        http_response_code(401);
        echo json_encode(['error' => ['message' => 'invalid API key']], JSON_THROW_ON_ERROR);
        break;
    case 'Token locked-smoke-key':
        http_response_code(403);
        echo json_encode(['error' => ['message' => 'dataset not enabled']], JSON_THROW_ON_ERROR);
        break;
    case 'Token limited-smoke-key':
        header('Retry-After: 3');
        http_response_code(429);
        echo json_encode(['error' => ['message' => 'request limit reached']], JSON_THROW_ON_ERROR);
        break;
    default:
        http_response_code(401);
        echo json_encode(['error' => ['message' => 'missing API key']], JSON_THROW_ON_ERROR);
}
