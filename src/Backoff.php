<?php

declare(strict_types=1);

namespace App;

final class Backoff
{
    public const BASE_DELAY_SECONDS = 60;
    public const MAX_DELAY_SECONDS = 6 * 60 * 60;

    /**
     * Seconds to wait before the next attempt.
     *
     * $attempt = number of failed attempts so far (1 for the first retry).
     * $rand is in [0, 1) and injectable so tests are deterministic; when null,
     * use mt_rand() / (mt_getrandmax() + 1).
     *
     *   delay = min(60 * 2^(attempt-1), 21600) * (0.5 + rand)
     *
     * TODO(candidate): implement.
     */
    public static function seconds(int $attempt, ?float $rand = null): float
    {
        throw new \RuntimeException('TODO: implement Backoff::seconds');
    }
}
