<?php

namespace Tests\Unit;

use App\Support\AiConnectionLogger;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class AiConnectionLoggerTest extends TestCase
{
    public function test_it_does_not_write_when_connection_logging_is_disabled(): void
    {
        config()->set('ai.connection_log', false);
        Log::shouldReceive('channel')->never();

        app(AiConnectionLogger::class)->write('info', 'test');
    }

    public function test_it_writes_to_the_dedicated_channel_when_enabled(): void
    {
        config()->set('ai.connection_log', true);
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('log')
            ->once()
            ->with('info', 'test', ['connection_id' => 'abc']);
        Log::shouldReceive('channel')
            ->once()
            ->with('ai_connections')
            ->andReturn($channel);

        app(AiConnectionLogger::class)->write('info', 'test', [
            'connection_id' => 'abc',
        ]);
    }

    public function test_it_redacts_common_api_key_formats_from_excerpts(): void
    {
        $excerpt = app(AiConnectionLogger::class)->excerpt(
            'Authorization: Bearer secret-token and sk-or-v1-abcdefghijk',
        );

        $this->assertSame(
            'Authorization: Bearer [REDACTED] and [REDACTED_API_KEY]',
            $excerpt,
        );
    }

    public function test_logging_failures_do_not_interrupt_provider_requests(): void
    {
        config()->set('ai.connection_log', true);
        Log::shouldReceive('channel')
            ->once()
            ->andThrow(new \RuntimeException('Log path is not writable.'));

        app(AiConnectionLogger::class)->write('info', 'test');

        $this->addToAssertionCount(1);
    }
}
