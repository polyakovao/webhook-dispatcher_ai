<?php

declare(strict_types=1);

// Delivery worker. Runs as its own process (see the `worker` service in
// docker-compose.yml), so you can scale it: docker compose up --scale worker=2

require __DIR__ . '/../vendor/autoload.php';

use App\Backoff; // phpcs:ignore
use App\Db;      // phpcs:ignore
use App\Signer;  // phpcs:ignore

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

fwrite(STDOUT, "worker started (stub, does nothing yet)\n");

// Env you will need: getenv('WEBHOOK_SECRET'), getenv('DATABASE_URL')
while ($running) {
    // TODO(candidate): in this loop
    //   1. claim due events safely (two workers must not take the same row),
    //   2. POST each one to target_url with a timeout and the signature headers
    //      (curl or file_get_contents with a stream context),
    //   3. classify the outcome (success / retryable / permanent),
    //   4. update status, attempts, next_attempt_at, last_error,
    //   5. sleep briefly when there is nothing to do.
    sleep(1);
}

fwrite(STDOUT, "worker stopped\n");
