<?php

declare(strict_types=1);

namespace App;

final class Outcome
{
    public const MAX_ATTEMPTS = 8;

    /**
     * Event status after a delivery attempt: sent, retry or dead (BRIEF, "Outcome handling").
     *
     * $httpCode is 0 for a timeout or network error. $attempt is the number of this attempt,
     * so on failure it is also the number of failed attempts so far.
     * Redirects are not followed, so 3xx is permanent too (docs/PRD.md, decision 6).
     */
    public static function status(int $httpCode, int $attempt): string
    {
        if ($httpCode >= 200 && $httpCode < 300) {
            return 'sent';
        }
        $retryable = in_array($httpCode, [0, 408, 429], true) || $httpCode >= 500;

        return $retryable && $attempt < self::MAX_ATTEMPTS ? 'retry' : 'dead';
    }
}
