<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Db;

header('Content-Type: application/json');

// Anything uncaught answers 500 without details (display_errors is on in the image); the trace goes to the log.
set_exception_handler(function (Throwable $e): void {
    error_log((string) $e);
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
});

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
    // Decode to objects, so a payload of {} is stored as {}, not [].
    $event = json_decode(file_get_contents('php://input'));
    $url = $event->url ?? null;
    $type = $event->type ?? null;
    $payload = $event->payload ?? null;

    $error = match (true) {
        !$event instanceof stdClass => 'body must be a JSON object',
        !is_string($url) || !preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)
            => 'url must be an absolute http or https URL',
        !is_string($type) || $type === '' => 'type must be a non-empty string',
        !$payload instanceof stdClass => 'payload must be a JSON object',
        default => null,
    };
    if ($error !== null) {
        http_response_code(400);
        echo json_encode(['error' => $error]);
        exit;
    }

    $stmt = Db::pdo()->prepare('INSERT INTO events (target_url, event_type, payload) VALUES (?, ?, ?) RETURNING id');
    $stmt->execute([$url, $type, json_encode($payload)]);
    http_response_code(202);
    echo json_encode(['id' => $stmt->fetchColumn()]);
    exit;
}

// Prometheus text format: events per status, with every status listed even when it is 0.
if ($method === 'GET' && $path === '/metrics') {
    $counts = Db::pdo()->query('SELECT status, count(*) FROM events GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    header('Content-Type: text/plain; version=0.0.4');
    echo "# HELP webhook_events Events by delivery status.\n# TYPE webhook_events gauge\n";
    foreach (['pending', 'retry', 'sent', 'dead'] as $status) {
        echo "webhook_events{status=\"$status\"} " . ($counts[$status] ?? 0) . "\n";
    }
    exit;
}

// A dead event goes back to pending with attempts 0, due now. The status check and the update are one statement.
if ($method === 'POST' && preg_match('#^/events/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/replay$#i', $path, $match)) {
    $stmt = Db::pdo()->prepare(<<<'SQL'
        UPDATE events SET status = 'pending', attempts = 0, last_error = NULL, next_attempt_at = now(), updated_at = now()
        WHERE id = ? AND status = 'dead'
        RETURNING id
        SQL);
    $stmt->execute([$match[1]]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        http_response_code(404);
        echo json_encode(['error' => 'no dead event with this id']);
        exit;
    }
    http_response_code(202);
    echo json_encode(['id' => $id]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not found']);
