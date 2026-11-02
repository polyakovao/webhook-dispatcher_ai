<?php

declare(strict_types=1);

// Delivery worker. Runs as its own process (see the `worker` service in
// docker-compose.yml), so you can scale it: docker compose up --scale worker=2

require __DIR__ . '/../vendor/autoload.php';

use App\Backoff;
use App\Db;
use App\Outcome;
use App\Signer;

$secret = (string) getenv('WEBHOOK_SECRET');
if ($secret === '') {
    fwrite(STDERR, "WEBHOOK_SECRET is not set\n");
    exit(1);
}

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

// Claim one due event and lease it for 60 s, longer than the 10 s HTTP timeout (docs/PRD.md, decision 2).
// No transaction stays open during the POST, and SKIP LOCKED lets two workers pass each other.
$claim = Db::pdo()->prepare(<<<'SQL'
    UPDATE events SET next_attempt_at = now() + interval '60 seconds'
    WHERE id = (
        SELECT id FROM events
        WHERE status IN ('pending', 'retry') AND next_attempt_at <= now()
        ORDER BY next_attempt_at
        LIMIT 1
        FOR UPDATE SKIP LOCKED
    )
    RETURNING *
    SQL);
$record = Db::pdo()->prepare(<<<'SQL'
    UPDATE events
    SET status = ?, attempts = ?, last_error = ?, next_attempt_at = now() + make_interval(secs => ?), updated_at = now()
    WHERE id = ?
    SQL);

fwrite(STDOUT, "worker started\n");

while ($running) {
    $claim->execute();
    $event = $claim->fetch(PDO::FETCH_ASSOC);
    if ($event === false) {
        sleep(1);
        continue;
    }

    // The id in the body and in X-Webhook-Id is the same on every attempt, so receivers can dedupe.
    $body = json_encode([
        'id' => $event['id'],
        'type' => $event['event_type'],
        'payload' => json_decode($event['payload']),
        'created_at' => (new DateTimeImmutable($event['created_at']))->format(DATE_ATOM),
    ]);
    $timestamp = (string) time();
    $ch = curl_init($event['target_url']);
    curl_setopt_array($ch, [
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "X-Webhook-Id: {$event['id']}",
            "X-Webhook-Timestamp: $timestamp",
            "X-Webhook-Signature: t=$timestamp,v1=" . Signer::sign($secret, $timestamp, $body),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    // false is a timeout or network error, even when a status line has already arrived.
    $code = curl_exec($ch) === false ? 0 : curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = match (true) {
        $code === 0 => curl_error($ch),
        $code >= 300 => "HTTP $code",
        default => null,
    };

    // attempts counts failed attempts, so a success leaves it as it is.
    $attempt = $event['attempts'] + 1;
    $status = Outcome::status($code, $attempt);
    $attempts = $status === 'sent' ? $event['attempts'] : $attempt;
    $delay = $status === 'retry' ? Backoff::seconds($attempt) : 0;
    $record->execute([$status, $attempts, $error, $delay, $event['id']]);
    fwrite(STDOUT, "{$event['id']} $status $attempts " . ($error ?? '-') . "\n");
}

fwrite(STDOUT, "worker stopped\n");
