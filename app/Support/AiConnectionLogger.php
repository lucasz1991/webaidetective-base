<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AiConnectionLogger
{
    public function enabled(): bool
    {
        return (bool) config('ai.connection_log', false);
    }

    public function connectionId(): string
    {
        return (string) Str::uuid();
    }

    public function write(string $level, string $message, array $context = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            Log::channel('ai_connections')->log($level, $message, $context);
        } catch (Throwable) {
            // Debug logging must not interrupt the provider request.
        }
    }

    public function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    public function excerpt(string $value, int $limit = 1000): string
    {
        $value = preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/\bsk-[A-Za-z0-9_-]{8,}\b/', '[REDACTED_API_KEY]', $value) ?? $value;

        return mb_substr($value, 0, $limit);
    }
}
