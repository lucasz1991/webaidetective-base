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
}
