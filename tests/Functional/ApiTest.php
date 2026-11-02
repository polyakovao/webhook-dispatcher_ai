<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Db;
use PHPUnit\Framework\TestCase;

/**
 * POST /events over HTTP. Needs the stack (docker compose up -d --build): requests go to
 * the app on :8080, rows are read from its Postgres on :5432.
 */
final class ApiTest extends TestCase
{
    private const OUTAGE_PORT = 18080;

    public function testAcceptsAnEventAndStoresItUnchanged(): void
    {
        $payload = '{"empty":{},"list":[1]}';
        [$code, $body] = $this->post("{\"url\":\"http://receiver:9000/ok\",\"type\":\"functional.test\",\"payload\":$payload}");

        $this->assertSame(202, $code, $body);
        $id = json_decode($body)->id ?? '';
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $id);

        $stmt = Db::pdo()->prepare('SELECT target_url, event_type, payload FROM events WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row, 'no row for the returned id');
        $this->assertSame('http://receiver:9000/ok', $row['target_url']);
        $this->assertSame('functional.test', $row['event_type']);
        $this->assertEquals(json_decode($payload), json_decode($row['payload']), '{} must stay {}');
    }

    public function testRejectsInvalidInputWith400AndStoresNothing(): void
    {
        $tag = uniqid('functional');
        $url = "http://receiver:9000/ok?$tag";
        $bodies = [
            'not JSON' => '{',
            'not an object' => '[1]',
            'no url' => '{"type":"t","payload":{}}',
            'ftp url' => "{\"url\":\"ftp://receiver/?$tag\",\"type\":\"t\",\"payload\":{}}",
            'empty type' => "{\"url\":\"$url\",\"type\":\"\",\"payload\":{}}",
            'payload not an object' => "{\"url\":\"$url\",\"type\":\"t\",\"payload\":[1]}",
        ];
        foreach ($bodies as $case => $body) {
            $this->assertSame(400, $this->post($body)[0], $case);
        }

        $stmt = Db::pdo()->prepare('SELECT count(*) FROM events WHERE target_url LIKE ?');
        $stmt->execute(["%?$tag"]);
        $this->assertSame(0, $stmt->fetchColumn());
    }

    public function testDatabaseOutageAnswers500WithoutDetails(): void
    {
        // a second API process, on the host, whose database is unreachable
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::OUTAGE_PORT, '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['DATABASE_URL' => 'postgres://127.0.0.1:1/none'] + getenv(),
        );
        try {
            $this->waitForPort(self::OUTAGE_PORT);
            [$code, $body] = $this->post(
                '{"url":"http://receiver:9000/ok","type":"t","payload":{}}',
                'http://127.0.0.1:' . self::OUTAGE_PORT,
            );
            $this->assertSame(500, $code);
            $this->assertSame('{"error":"internal error"}', $body);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /** @return array{int, string} HTTP status and body */
    private function post(string $body, string $base = 'http://localhost:8080'): array
    {
        $ch = curl_init("$base/events");
        curl_setopt_array($ch, [
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $res = curl_exec($ch);
        $this->assertNotFalse($res, "$base is not reachable (" . curl_error($ch) . '). Start the stack: docker compose up -d --build');

        return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $res];
    }

    private function waitForPort(int $port): void
    {
        for ($i = 0; $i < 50; $i++) {
            if ($socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }
        $this->fail("nothing listens on port $port");
    }
}
