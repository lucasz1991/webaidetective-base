<?php

namespace Tests\Unit;

use App\Services\Support\DatabaseKeepAlive;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseKeepAliveTest extends TestCase
{
    public function test_ping_and_guarded_operation_use_a_live_connection(): void
    {
        $this->assertTrue(DatabaseKeepAlive::ping(0));

        $value = DatabaseKeepAlive::run(
            fn (): int => (int) DB::selectOne('select 1 as connection_is_alive')->connection_is_alive,
        );

        $this->assertSame(1, $value);
    }

    public function test_mysql_gone_away_errors_are_recognized_as_lost_connections(): void
    {
        $exception = new \RuntimeException(
            'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'
        );

        $this->assertTrue(DatabaseKeepAlive::isLostConnection($exception));
    }

    public function test_guarded_operation_reconnects_and_retries_after_lost_connection(): void
    {
        $attempts = 0;

        $value = DatabaseKeepAlive::run(function () use (&$attempts): string {
            $attempts++;

            if ($attempts === 1) {
                throw new \RuntimeException(
                    'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'
                );
            }

            return 'recovered';
        });

        $this->assertSame(2, $attempts);
        $this->assertSame('recovered', $value);
        $this->assertTrue(DatabaseKeepAlive::ping(0));
    }
}
