<?php

declare(strict_types=1);

namespace App;

final class Signer
{
    /**
     * Hex HMAC-SHA256 of "{$timestamp}.{$body}" using $secret.
     * The caller builds the header: "t={$timestamp},v1={$hex}".
     *
     * TODO(candidate): implement.
     */
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        throw new \RuntimeException('TODO: implement Signer::sign');
    }
}
