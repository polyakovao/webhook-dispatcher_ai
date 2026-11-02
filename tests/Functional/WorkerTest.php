<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;

/** bin/worker.php as a process. Needs no running stack. */
final class WorkerTest extends TestCase
{
    public function testRefusesToStartWithoutSecret(): void
    {
        $worker = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/worker.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['WEBHOOK_SECRET' => ''] + getenv(),
        );
        // a worker that starts anyway would loop forever, so give it 5 s
        $status = proc_get_status($worker);
        for ($i = 0; $i < 50 && $status['running']; $i++) {
            usleep(100_000);
            $status = proc_get_status($worker);
        }
        if ($status['running']) {
            proc_terminate($worker);
        }
        $stderr = stream_get_contents($pipes[2]);
        proc_close($worker);

        $this->assertFalse($status['running'], 'the worker kept running without WEBHOOK_SECRET');
        $this->assertSame(1, $status['exitcode']);
        $this->assertSame("WEBHOOK_SECRET is not set\n", $stderr);
    }
}
