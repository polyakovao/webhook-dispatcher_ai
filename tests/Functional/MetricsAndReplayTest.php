<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Db;
use PHPUnit\Framework\TestCase;

/**
 * GET /metrics (PRD X3) and POST /events/{id}/replay (PRD X2) over HTTP. Needs the stack, like ApiTest.
 * The worker keeps running meanwhile, so the checks hold whatever it does to the rows.
 */
final class MetricsAndReplayTest extends TestCase
{
    public function testMetricsCountEventsPerStatus(): void
    {
        $before = (int) Db::pdo()->query('SELECT count(*) FROM events')->fetchColumn();
        [$code, $body, $type] = $this->request('GET', '/metrics');
        $after = (int) Db::pdo()->query('SELECT count(*) FROM events')->fetchColumn();

        $this->assertSame(200, $code, $body);
        $this->assertStringStartsWith('text/plain', $type);
        $total = 0;
        foreach (['pending', 'retry', 'sent', 'dead'] as $status) {
            $this->assertSame(1, preg_match('/^webhook_events\{status="' . $status . '"\} (\d+)$/m', $body, $m), "no line for $status");
            $total += (int) $m[1];
        }
        // rows are never deleted, so the total lies between the counts taken before and after the request
        $this->assertGreaterThanOrEqual($before, $total);
        $this->assertLessThanOrEqual($after, $total);
    }

    public function testReplayMovesADeadEventBackToPending(): void
    {
        $id = Db::pdo()->query(
            "INSERT INTO events (target_url, event_type, payload, status, attempts)
             VALUES ('http://receiver:9000/ok', 'functional.replay', '{}', 'dead', 8) RETURNING id"
        )->fetchColumn();

        [$code, $body] = $this->request('POST', "/events/$id/replay");
        $this->assertSame(202, $code, $body);
        $this->assertSame($id, json_decode($body)->id ?? null);

        // the worker may deliver it at once: /ok makes it sent, and a success leaves attempts at 0
        $row = Db::pdo()->query("SELECT status, attempts FROM events WHERE id = '$id'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertContains($row['status'], ['pending', 'sent']);
        $this->assertSame(0, $row['attempts']);
    }

    public function testReplayAnswers404WithoutADeadEvent(): void
    {
        $pending = Db::pdo()->query(
            "INSERT INTO events (target_url, event_type, payload) VALUES ('http://receiver:9000/ok', 'functional.replay', '{}') RETURNING id"
        )->fetchColumn();

        $this->assertSame(404, $this->request('POST', "/events/$pending/replay")[0], 'an event that is not dead');
        $this->assertSame(404, $this->request('POST', '/events/00000000-0000-0000-0000-000000000000/replay')[0], 'no such event');
    }

    /** @return array{int, string, string} HTTP status, body and content type */
    private function request(string $method, string $path): array
    {
        $ch = curl_init("http://localhost:8080$path");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        $res = curl_exec($ch);
        $this->assertNotFalse($res, 'http://localhost:8080 is not reachable (' . curl_error($ch) . '). Start the stack: docker compose up -d --build');

        return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $res, (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
    }
}
