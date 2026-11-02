<?php

declare(strict_types=1);

namespace Tests;

use App\Backoff;
use App\Signer;
use PHPUnit\Framework\TestCase;

final class LibTest extends TestCase
{
    public function testFirstRetryWaitsBaseDelayWhenJitterFactorIsOne(): void
    {
        $this->assertEqualsWithDelta(60, Backoff::seconds(1, 0.5), 1e-9);
    }

    public function testDelayDoublesEachAttempt(): void
    {
        $this->assertEqualsWithDelta(120, Backoff::seconds(2, 0.5), 1e-9);
        $this->assertEqualsWithDelta(240, Backoff::seconds(3, 0.5), 1e-9);
        $this->assertEqualsWithDelta(480, Backoff::seconds(4, 0.5), 1e-9);
    }

    public function testDelayIsCappedAtSixHoursBeforeJitter(): void
    {
        $this->assertEqualsWithDelta(21600, Backoff::seconds(30, 0.5), 1e-9);
    }

    public function testJitterSpansHalfToOneAndAHalfTimesTheDelay(): void
    {
        $this->assertEqualsWithDelta(30, Backoff::seconds(1, 0.0), 1e-9);
        $this->assertEqualsWithDelta(89.99994, Backoff::seconds(1, 0.999999), 1e-3);
    }

    public function testSignProducesKnownHmacSha256HexVector(): void
    {
        $body = '{"id":"evt_1","type":"order.created"}';
        $this->assertSame(
            'b4299661e5387b192e6a17c961991dce11e3adb10aabc368d96f3b7a148387e3',
            Signer::sign('whsec_test', '1700000000', $body)
        );
    }
}
