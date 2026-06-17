<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseKeepAlive
{
    private static float $lastPingAt = 0.0;

    public static function ping(int $minIntervalSeconds = 25): void
    {
        $now = microtime(true);

        if ($minIntervalSeconds > 0 && $now - self::$lastPingAt < $minIntervalSeconds) {
            return;
        }

        self::$lastPingAt = $now;

        try {
            DB::select('select 1');
        } catch (\Throwable $exception) {
            self::reconnect($exception);
        }
    }

    public static function reconnect(?\Throwable $previous = null): void
    {
        try {
            DB::purge();
            DB::reconnect();
            DB::select('select 1');
            self::$lastPingAt = microtime(true);
        } catch (\Throwable $exception) {
            Log::warning('Datenbank-Keepalive konnte die Verbindung nicht erneuern.', [
                'previous' => $previous?->getMessage(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public static function run(callable $callback, int $attempts = 2): mixed
    {
        $attempts = max(1, $attempts);
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt += 1) {
            self::ping(0);

            try {
                return $callback();
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if ($attempt >= $attempts || ! self::isLostConnection($exception)) {
                    throw $exception;
                }

                self::reconnect($exception);
            }
        }

        throw $lastException;
    }

    public static function transaction(callable $callback, int $attempts = 2): mixed
    {
        return self::run(
            fn (): mixed => DB::transaction($callback),
            $attempts,
        );
    }

    public static function isLostConnection(\Throwable $exception): bool
    {
        do {
            $message = strtolower($exception->getMessage());

            foreach ([
                'server has gone away',
                'lost connection',
                'no connection to the server',
                'error while sending query packet',
                'connection refused',
                'connection reset',
                'broken pipe',
                'sqlstate[hy000] [2002]',
                'sqlstate[hy000]: general error: 2006',
                'sqlstate[hy000]: general error: 2013',
            ] as $needle) {
                if (str_contains($message, $needle)) {
                    return true;
                }
            }

            $exception = $exception->getPrevious();
        } while ($exception);

        return false;
    }
}
