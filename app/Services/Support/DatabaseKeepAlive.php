<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseKeepAlive
{
    private static float $lastPingAt = 0.0;

    public static function ping(int $minIntervalSeconds = 25): bool
    {
        $now = microtime(true);

        if ($minIntervalSeconds > 0 && $now - self::$lastPingAt < $minIntervalSeconds) {
            return true;
        }

        try {
            DB::select('select 1');

            self::$lastPingAt = $now;

            return true;
        } catch (\Throwable $exception) {
            self::$lastPingAt = 0.0;

            return self::reconnect($exception);
        }
    }

    public static function reconnect(?\Throwable $previous = null, int $attempts = 5): bool
    {
        $attempts = max(1, $attempts);
        $lastException = $previous;
        self::$lastPingAt = 0.0;

        for ($attempt = 1; $attempt <= $attempts; $attempt += 1) {
            try {
                DB::disconnect();
                DB::purge();
                DB::reconnect();
                DB::connection()->getPdo();
                DB::select('select 1');

                self::$lastPingAt = microtime(true);

                if ($attempt > 1 || $previous) {
                    Log::info('Datenbankverbindung fuer Langzeitprozess wurde erneuert.', [
                        'attempt' => $attempt,
                        'previous' => $previous?->getMessage(),
                    ]);
                }

                return true;
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if ($attempt < $attempts) {
                    usleep(min(2_000_000, 250_000 * (2 ** ($attempt - 1))));
                }
            }
        }

        Log::warning('Datenbank-Keepalive konnte die Verbindung nicht erneuern.', [
            'attempts' => $attempts,
            'previous' => $previous?->getMessage(),
            'error' => $lastException?->getMessage(),
        ]);

        return false;
    }

    public static function ensureConnected(int $attempts = 5): void
    {
        try {
            DB::select('select 1');
            self::$lastPingAt = microtime(true);

            return;
        } catch (\Throwable $exception) {
            self::$lastPingAt = 0.0;

            if (self::reconnect($exception, $attempts)) {
                return;
            }
        }

        throw new \RuntimeException(
            'Die Datenbankverbindung konnte nach mehreren Versuchen nicht wiederhergestellt werden.'
        );
    }

    public static function run(callable $callback, int $attempts = 3): mixed
    {
        $attempts = max(1, $attempts);
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt += 1) {
            self::ensureConnected();

            try {
                return $callback();
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if ($attempt >= $attempts || ! self::isLostConnection($exception)) {
                    throw $exception;
                }

                self::$lastPingAt = 0.0;
                self::reconnect($exception);
            }
        }

        throw $lastException;
    }

    public static function transaction(callable $callback, int $attempts = 3): mixed
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
