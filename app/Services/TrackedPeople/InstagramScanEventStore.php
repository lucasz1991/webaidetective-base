<?php

namespace App\Services\TrackedPeople;

use App\Models\InstagramScanEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InstagramScanEventStore
{
    private ?bool $ready = null;

    private array $throttle = [];

    public function started(
        string $scanType,
        ?Model $scan,
        mixed $instagramUsername,
        ?int $trackedPersonId,
        ?int $userId,
        string $message,
        array $payload = [],
    ): void {
        $this->record($scanType, $scan, $instagramUsername, $trackedPersonId, $userId, [
            ...$payload,
            'phase' => $payload['phase'] ?? 'started',
            'stage' => $payload['stage'] ?? 'scan-started',
            'statusLevel' => $payload['statusLevel'] ?? 'partial',
            'percent' => $payload['percent'] ?? 0,
            'message' => $message,
        ], force: true);
    }

    public function progress(
        string $scanType,
        ?Model $scan,
        mixed $instagramUsername,
        ?int $trackedPersonId,
        ?int $userId,
        array $state,
    ): void {
        $this->record($scanType, $scan, $instagramUsername, $trackedPersonId, $userId, $state);
    }

    public function finished(
        string $scanType,
        ?Model $scan,
        mixed $instagramUsername,
        ?int $trackedPersonId,
        ?int $userId,
        string $message,
        array $payload = [],
    ): void {
        $this->record($scanType, $scan, $instagramUsername, $trackedPersonId, $userId, [
            ...$payload,
            'phase' => $payload['phase'] ?? 'done',
            'stage' => $payload['stage'] ?? 'scan-finished',
            'statusLevel' => $payload['statusLevel'] ?? 'success',
            'percent' => $payload['percent'] ?? 100,
            'message' => $message,
        ], force: true);
    }

    public function failed(
        string $scanType,
        ?Model $scan,
        mixed $instagramUsername,
        ?int $trackedPersonId,
        ?int $userId,
        string $message,
        array $payload = [],
    ): void {
        $this->record($scanType, $scan, $instagramUsername, $trackedPersonId, $userId, [
            ...$payload,
            'phase' => $payload['phase'] ?? 'error',
            'stage' => $payload['stage'] ?? 'scan-failed',
            'statusLevel' => $payload['statusLevel'] ?? 'error',
            'percent' => $payload['percent'] ?? 100,
            'message' => $message,
        ], force: true);
    }

    public function record(
        string $scanType,
        ?Model $scan,
        mixed $instagramUsername,
        ?int $trackedPersonId,
        ?int $userId,
        array $state,
        bool $force = false,
    ): void {
        if (! $this->isReady()) {
            return;
        }

        $scanType = $this->normalizeScanType($scanType);
        $scanId = $scan?->getKey() ? (int) $scan->getKey() : null;
        $phase = $this->nullableString($state['phase'] ?? null);
        $stage = $this->nullableString($state['stage'] ?? null);
        $statusLevel = $this->nullableString($state['statusLevel'] ?? $state['status_level'] ?? null);
        $percent = is_numeric($state['percent'] ?? null)
            ? max(0, min(100, (int) $state['percent']))
            : null;
        $message = $this->nullableString($state['message'] ?? null);

        if (! $force && $this->shouldThrottle($scanType, $scanId, $phase, $stage, $percent, $message, $state)) {
            return;
        }

        InstagramScanEvent::create([
            'scan_type' => $scanType,
            'scan_id' => $scanId,
            'instagram_username' => $this->normalizeUsername($instagramUsername),
            'tracked_person_id' => $trackedPersonId,
            'user_id' => $userId,
            'phase' => $phase,
            'stage' => $stage,
            'status_level' => $statusLevel,
            'percent' => $percent,
            'message' => $message,
            'payload' => $this->summarizePayload($state),
            'occurred_at' => now('UTC'),
        ]);
    }

    private function shouldThrottle(
        string $scanType,
        ?int $scanId,
        ?string $phase,
        ?string $stage,
        ?int $percent,
        ?string $message,
        array $state,
    ): bool {
        $terminal = in_array($phase, ['done', 'error', 'failed'], true)
            || in_array($stage, ['scan-finished', 'scan-failed', 'scan-stop-requested'], true)
            || $percent === 100;

        if ($terminal) {
            return false;
        }

        $key = implode('|', [$scanType, (string) $scanId, (string) $phase, (string) $stage]);
        $signature = hash('sha1', implode('|', [
            (string) $percent,
            (string) $message,
            (string) ($state['loaded'] ?? ''),
            (string) ($state['expected'] ?? ''),
            (string) ($state['candidateUsername'] ?? ''),
            (string) ($state['foundFollowers'] ?? ''),
            (string) ($state['foundFollowing'] ?? ''),
            (string) ($state['observedSuggestionCount'] ?? ''),
        ]));
        $now = microtime(true);
        $last = $this->throttle[$key] ?? null;

        if ($last && $last['signature'] === $signature && ($now - $last['at']) < 5.0) {
            return true;
        }

        if ($last && ($now - $last['at']) < 3.0) {
            return true;
        }

        $this->throttle[$key] = [
            'signature' => $signature,
            'at' => $now,
        ];

        return false;
    }

    private function summarizePayload(array $state): array
    {
        $payload = [];

        foreach ([
            'loaded',
            'expected',
            'foundFollowers',
            'foundFollowing',
            'foundSuggestions',
            'observedSuggestionCount',
            'candidateUsername',
            'scraperProfileLabel',
            'scraperProfileLoginUsername',
            'scraperProfileId',
            'scraperProfileSwitchTarget',
            'stoppedForRateLimit',
            'gracefullyStopped',
            'liveScreenshotUrl',
            'rateLimitedCandidates',
        ] as $key) {
            if (array_key_exists($key, $state) && (is_scalar($state[$key]) || $state[$key] === null)) {
                $payload[$key] = $state[$key];
            }
        }

        foreach ([
            'relationshipItems',
            'relationshipItemsDelta',
            'suggestionConnections',
            'observedSuggestions',
            'inferredFollowers',
            'inferredFollowing',
        ] as $key) {
            if (is_array($state[$key] ?? null)) {
                $payload[$key.'Count'] = count($state[$key]);
            }
        }

        return $payload;
    }

    private function normalizeUsername(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $username = Str::lower(trim((string) $value));
        $username = preg_replace('/^https?:\/\/(www\.)?instagram\.com\//i', '', $username) ?? $username;
        $username = trim(ltrim($username, '@'), "/ \t\n\r\0\x0B");
        $username = preg_replace('/[?#].*$/', '', $username) ?? $username;

        return $username !== '' ? Str::limit($username, 64, '') : null;
    }

    private function normalizeScanType(string $scanType): string
    {
        $scanType = Str::of($scanType)->lower()->replaceMatches('/[^a-z0-9_-]+/', '_')->trim('_')->toString();

        return $scanType !== '' ? Str::limit($scanType, 60, '') : 'instagram_scan';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function isReady(): bool
    {
        return $this->ready ??= Schema::hasTable('instagram_scan_events');
    }
}
