<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

class InvestigationAssistantScanStatusStore
{
    private const CACHE_PREFIX = 'investigation-assistant-scan:';

    public function start(string $token, array $context): array
    {
        $status = [
            ...$context,
            'token' => $token,
            'status' => 'queued',
            'percent' => 0,
            'phase' => 'queued',
            'message' => (string) ($context['message'] ?? 'Scan wurde in die Warteschlange gestellt.'),
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];

        $this->put($token, $status);

        return $status;
    }

    public function progress(string $token, array $progress): array
    {
        return $this->update($token, [
            'status' => 'running',
            'percent' => max(1, min(99, (int) ($progress['percent'] ?? 1))),
            'phase' => (string) ($progress['phase'] ?? 'analysis'),
            'message' => (string) ($progress['message'] ?? 'Scan laeuft.'),
            'loaded' => $progress['loaded'] ?? null,
            'expected' => $progress['expected'] ?? null,
        ]);
    }

    public function complete(string $token, array $result = [], ?string $message = null): array
    {
        return $this->update($token, [
            'status' => 'completed',
            'percent' => 100,
            'phase' => 'done',
            'message' => $message ?: 'Scan abgeschlossen.',
            'result' => $result,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function pause(string $token, string $message, array $result = []): array
    {
        return $this->update($token, [
            'status' => 'paused',
            'phase' => 'paused',
            'message' => $message,
            'resumable' => true,
            'result' => $result,
            'paused_at' => now()->toIso8601String(),
        ]);
    }

    public function stopping(string $token, string $message): array
    {
        return $this->update($token, [
            'status' => 'stopping',
            'phase' => 'saving',
            'message' => $message,
            'stop_requested' => true,
        ]);
    }

    public function dismiss(string $token, string $message): array
    {
        return $this->update($token, [
            'status' => 'dismissed',
            'phase' => 'dismissed',
            'message' => $message,
            'resumable' => false,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function fail(string $token, string $message, array $result = []): array
    {
        return $this->update($token, [
            'status' => 'error',
            'phase' => 'error',
            'message' => $message,
            'result' => $result,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function cancel(string $token, string $message): array
    {
        return $this->update($token, [
            'status' => 'cancelled',
            'phase' => 'cancelled',
            'message' => $message,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function get(string $token): ?array
    {
        $status = Cache::get($this->key($token));

        return is_array($status) ? $status : null;
    }

    private function update(string $token, array $changes): array
    {
        $status = [
            ...($this->get($token) ?? ['token' => $token]),
            ...$changes,
            'updated_at' => now()->toIso8601String(),
        ];

        $this->put($token, $status);

        return $status;
    }

    private function put(string $token, array $status): void
    {
        Cache::put($this->key($token), $status, now()->addHours(12));
    }

    private function key(string $token): string
    {
        return self::CACHE_PREFIX.$token;
    }
}
