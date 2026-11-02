<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Db;

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'GET' && $path === '/health') {
    try {
        Db::pdo()->query('SELECT 1');
        echo json_encode(['status' => 'ok']);
    } catch (Throwable) {
        http_response_code(503);
        echo json_encode(['status' => 'db_unavailable']);
    }
    exit;
}

// Body: {"url": "...", "type": "order.created", "payload": {...}}
if ($method === 'POST' && $path === '/events') {
    // TODO(candidate): validate input, insert into `events`, return 202 {"id": "<uuid>"}.
    http_response_code(501);
    echo json_encode(['error' => 'not implemented']);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not found']);
