<?php

declare(strict_types=1);

namespace App;

final class Db
{
    /** Lazily connects using DATABASE_URL (postgres://user:pass@host:port/db). */
    public static function pdo(): \PDO
    {
        static $pdo = null;

        if ($pdo === null) {
            $url = parse_url(
                getenv('DATABASE_URL') ?: 'postgres://dispatcher:dispatcher@localhost:5432/dispatcher'
            );
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $url['host'] ?? 'localhost',
                $url['port'] ?? 5432,
                ltrim($url['path'] ?? '', '/')
            );
            $pdo = new \PDO($dsn, $url['user'] ?? null, $url['pass'] ?? null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 2,
            ]);
        }

        return $pdo;
    }
}
