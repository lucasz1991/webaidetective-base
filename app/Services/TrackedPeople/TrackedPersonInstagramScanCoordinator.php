<?php

namespace App\Services\TrackedPeople;

use App\Exceptions\TrackedPersonInstagramScanCancelledException;
use App\Jobs\ResumeInstagramScanRunJob;
use App\Models\InstagramScanRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class TrackedPersonInstagramScanCoordinator
{
    private ?bool $scanRunTableAvailable = null;

    public function begin(int $trackedPersonId, string $label, array $metadata = []): array
    {
        $this->cancelActive($trackedPersonId, 'Neuer Instagram-Scan wurde gestartet.');

        $generation = $this->nextGeneration($trackedPersonId);
        $gracefulStopFilePath = $this->gracefulStopFilePath($trackedPersonId, $generation);
        File::ensureDirectoryExists(dirname($gracefulStopFilePath));
        File::delete($gracefulStopFilePath);

        $context = [
            'trackedPersonId' => $trackedPersonId,
            'generation' => $generation,
            'label' => $label,
            'startedAt' => now()->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
            'processes' => [],
            'gracefulStopFilePath' => $gracefulStopFilePath,
            'gracefulStopRequested' => false,
        ];

        $scanRun = $this->startPersistentRun($trackedPersonId, $generation, $label, $metadata);

        if ($scanRun) {
            $context['scanRunId'] = (int) $scanRun->id;
        }

        Cache::put($this->activeKey($trackedPersonId), $context, now()->addHours(12));

        return $context;
    }

    public function finish(int $trackedPersonId, int $generation): void
    {
        $active = $this->active($trackedPersonId);

        if ((int) ($active['generation'] ?? 0) === $generation) {
            $this->deleteGracefulStopFile($active);
            Cache::forget($this->activeKey($trackedPersonId));
        }
    }

    public function completeFromResult(int $trackedPersonId, int $generation, mixed $result, string $fallbackMessage = 'Instagram-Scan abgeschlossen.'): void
    {
        if ($this->resultWasGracefullyStopped($result) || $this->resultWasCancelled($result)) {
            $this->markRunCancelled($trackedPersonId, $generation, $this->resultStatusMessage($result) ?: $fallbackMessage);

            return;
        }

        if ($this->resultNeedsRetry($result)) {
            $this->failForRetry(
                $trackedPersonId,
                $generation,
                $this->resultStatusMessage($result) ?: $fallbackMessage,
                300,
                ['result' => $this->summarizeResultForPayload($result)],
            );

            return;
        }

        $this->markRunSucceeded($trackedPersonId, $generation, $this->resultStatusMessage($result) ?: $fallbackMessage);
    }

    public function markRunSucceeded(int $trackedPersonId, int $generation, string $message = 'Instagram-Scan abgeschlossen.'): void
    {
        $this->updatePersistentRun($trackedPersonId, $generation, [
            'status' => InstagramScanRun::STATUS_SUCCEEDED,
            'finished_at' => now('UTC'),
            'next_retry_at' => null,
            'last_error' => null,
            'last_heartbeat_at' => now('UTC'),
            'resume_payload' => [
                'message' => $message,
                'completedAt' => now('UTC')->toIso8601String(),
            ],
        ]);
    }

    public function failForRetry(
        int $trackedPersonId,
        int $generation,
        string $error,
        int $delaySeconds = 300,
        array $payload = [],
    ): void {
        $active = $this->active($trackedPersonId);

        if (
            (int) ($active['generation'] ?? 0) === $generation
            && (bool) ($active['gracefulStopRequested'] ?? false)
        ) {
            $this->markRunCancelled($trackedPersonId, $generation, $active['gracefulStopReason'] ?? $error);

            return;
        }

        $run = $this->persistentRunForGeneration($trackedPersonId, $generation);

        if (! $run) {
            return;
        }

        if (in_array($run->status, [InstagramScanRun::STATUS_CANCELLED, InstagramScanRun::STATUS_SUCCEEDED], true)) {
            return;
        }

        $nextRetryAt = now('UTC')->addSeconds(max(60, $delaySeconds));
        $resumePayload = [
            ...(is_array($run->resume_payload) ? $run->resume_payload : []),
            ...$payload,
            'lastFailureAt' => now('UTC')->toIso8601String(),
            'nextRetryAt' => $nextRetryAt->toIso8601String(),
        ];

        $run->forceFill([
            'status' => InstagramScanRun::STATUS_RETRY_SCHEDULED,
            'finished_at' => now('UTC'),
            'last_heartbeat_at' => now('UTC'),
            'next_retry_at' => $nextRetryAt,
            'last_error' => Str::limit($error, 4000, ''),
            'resume_payload' => $resumePayload,
        ])->save();

        $this->dispatchResumeJob($run, $nextRetryAt);
    }

    public function markRunCancelled(int $trackedPersonId, int $generation, string $reason): void
    {
        $this->updatePersistentRun($trackedPersonId, $generation, [
            'status' => InstagramScanRun::STATUS_CANCELLED,
            'finished_at' => now('UTC'),
            'next_retry_at' => null,
            'last_error' => Str::limit($reason, 4000, ''),
            'last_heartbeat_at' => now('UTC'),
            'resume_payload' => [
                'message' => $reason,
                'cancelledAt' => now('UTC')->toIso8601String(),
            ],
        ]);
    }

    public function prepareResume(int $trackedPersonId, int $scanRunId): void
    {
        Cache::put($this->pendingResumeKey($trackedPersonId), $scanRunId, now()->addMinutes(10));
    }

    public function shouldCancel(int $trackedPersonId, int $generation): bool
    {
        return (int) Cache::get($this->generationKey($trackedPersonId), 0) !== $generation;
    }

    public function assertCurrent(int $trackedPersonId, int $generation): void
    {
        if ($this->shouldCancel($trackedPersonId, $generation)) {
            throw new TrackedPersonInstagramScanCancelledException(
                'Instagram-Scan wurde abgebrochen, weil fuer diese Person ein neuer Scan gestartet wurde.'
            );
        }
    }

    public function requestGracefulStop(int $trackedPersonId, string $reason): bool
    {
        $active = $this->active($trackedPersonId);
        $generation = (int) ($active['generation'] ?? 0);

        if ($generation <= 0) {
            return false;
        }

        $active['gracefulStopRequested'] = true;
        $active['gracefulStopReason'] = $reason;
        $active['gracefulStopRequestedAt'] = now()->toIso8601String();
        $active['gracefulStopFilePath'] = (string) (
            $active['gracefulStopFilePath'] ?? $this->gracefulStopFilePath($trackedPersonId, $generation)
        );

        Cache::put($this->activeKey($trackedPersonId), $active, now()->addHours(12));
        $this->writeGracefulStopFile($active, $reason);

        return true;
    }

    public function hasActiveScan(int $trackedPersonId): bool
    {
        $active = $this->activeState($trackedPersonId);

        if ((int) ($active['generation'] ?? 0) <= 0) {
            return false;
        }

        $processes = is_array($active['processes'] ?? null) ? $active['processes'] : [];

        if ($processes !== []) {
            return collect($processes)->contains(
                fn (array $process): bool => $this->processIsRunning((int) ($process['pid'] ?? 0)),
            );
        }

        $updatedAt = $active['updatedAt'] ?? $active['startedAt'] ?? null;

        if (! is_string($updatedAt) || $updatedAt === '') {
            return false;
        }

        try {
            return now()->diffInSeconds(Carbon::parse($updatedAt)) < 180;
        } catch (\Throwable) {
            return false;
        }
    }

    public function activeState(int $trackedPersonId): array
    {
        return $this->active($trackedPersonId);
    }

    public function isResponsive(int $trackedPersonId, int $staleAfterSeconds = 35): bool
    {
        $active = $this->active($trackedPersonId);
        $heartbeatAt = $active['lastProcessOutputAt'] ?? $active['updatedAt'] ?? null;

        if (! is_string($heartbeatAt) || $heartbeatAt === '') {
            return false;
        }

        try {
            return now()->diffInSeconds(Carbon::parse($heartbeatAt)) < max(15, $staleAfterSeconds);
        } catch (\Throwable) {
            return false;
        }
    }

    public function recordProcessOutput(int $trackedPersonId, int $generation): void
    {
        $active = $this->active($trackedPersonId);

        if ((int) ($active['generation'] ?? 0) !== $generation) {
            return;
        }

        $active['lastProcessOutputAt'] = now()->toIso8601String();
        $active['updatedAt'] = now()->toIso8601String();
        Cache::put($this->activeKey($trackedPersonId), $active, now()->addHours(12));

        $this->updatePersistentRun($trackedPersonId, $generation, [
            'last_process_output_at' => now('UTC'),
            'last_heartbeat_at' => now('UTC'),
        ]);
    }

    public function terminateUnresponsiveScan(int $trackedPersonId): void
    {
        $active = $this->active($trackedPersonId);

        foreach (($active['processes'] ?? []) as $process) {
            $this->terminateProcessTree((int) ($process['pid'] ?? 0));
        }

        $active['processes'] = [];
        $active['unresponsiveAt'] = now()->toIso8601String();
        $active['updatedAt'] = now()->toIso8601String();
        Cache::put($this->activeKey($trackedPersonId), $active, now()->addHours(12));

        $generation = (int) ($active['generation'] ?? 0);

        if ($generation > 0) {
            $this->failForRetry(
                $trackedPersonId,
                $generation,
                'Instagram-Scan wurde beendet, weil der Node-Prozess nicht mehr reagiert.',
            );
        }
    }

    public function touchActiveScan(int $trackedPersonId): void
    {
        $active = $this->active($trackedPersonId);

        if ((int) ($active['generation'] ?? 0) <= 0) {
            return;
        }

        $active['updatedAt'] = now()->toIso8601String();
        Cache::put($this->activeKey($trackedPersonId), $active, now()->addHours(12));

        $this->updatePersistentRun($trackedPersonId, (int) ($active['generation'] ?? 0), [
            'last_heartbeat_at' => now('UTC'),
        ]);
    }

    public function shouldStopGracefully(int $trackedPersonId, int $generation): bool
    {
        $active = $this->active($trackedPersonId);

        if ((int) ($active['generation'] ?? 0) !== $generation) {
            return false;
        }

        if ((bool) ($active['gracefulStopRequested'] ?? false)) {
            return true;
        }

        $filePath = (string) ($active['gracefulStopFilePath'] ?? '');

        return $filePath !== '' && File::exists($filePath);
    }

    public function gracefulStopReason(int $trackedPersonId, int $generation): ?string
    {
        $active = $this->active($trackedPersonId);

        if ((int) ($active['generation'] ?? 0) !== $generation) {
            return null;
        }

        $reason = $active['gracefulStopReason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public function registerProcess(int $trackedPersonId, int $generation, int $pid, string $label, array $metadata = []): void
    {
        if ($pid <= 0) {
            return;
        }

        $active = $this->active($trackedPersonId);

        if ((int) ($active['generation'] ?? 0) !== $generation) {
            return;
        }

        $processes = collect($active['processes'] ?? [])
            ->reject(fn (array $process): bool => (int) ($process['pid'] ?? 0) === $pid)
            ->push([
                'pid' => $pid,
                'label' => $label,
                'script' => $metadata['script'] ?? null,
                'command' => $metadata['command'] ?? null,
                'registeredAt' => now()->toIso8601String(),
            ])
            ->values()
            ->all();

        $active['processes'] = $processes;
        $active['lastProcessOutputAt'] = now()->toIso8601String();
        $active['updatedAt'] = now()->toIso8601String();
        Cache::put($this->activeKey($trackedPersonId), $active, now()->addHours(12));

        $this->storePersistentProcess($trackedPersonId, $generation, $pid, $label, $metadata);
    }

    public function unregisterProcess(int $trackedPersonId, int $generation, int $pid): void
    {
        $active = $this->active($trackedPersonId);

        if ((int) ($active['generation'] ?? 0) !== $generation) {
            return;
        }

        $active['processes'] = collect($active['processes'] ?? [])
            ->reject(fn (array $process): bool => (int) ($process['pid'] ?? 0) === $pid)
            ->values()
            ->all();
        $active['updatedAt'] = now()->toIso8601String();

        Cache::put($this->activeKey($trackedPersonId), $active, now()->addHours(12));

        $this->finishPersistentProcess($trackedPersonId, $generation, $pid);
    }

    public function cancelActive(int $trackedPersonId, string $reason): void
    {
        $active = $this->active($trackedPersonId);

        foreach (($active['processes'] ?? []) as $process) {
            $pid = (int) ($process['pid'] ?? 0);

            if ($pid <= 0) {
                continue;
            }

            $this->terminateProcessTree($pid);
        }

        $generation = (int) ($active['generation'] ?? 0);
        $pendingResumeRunId = (int) (Cache::get($this->pendingResumeKey($trackedPersonId)) ?: 0);
        $activeScanRunId = (int) ($active['scanRunId'] ?? 0);

        if ($generation > 0 && ($pendingResumeRunId <= 0 || $activeScanRunId !== $pendingResumeRunId)) {
            $this->markRunCancelled($trackedPersonId, $generation, $reason);
        }

        $this->deleteGracefulStopFile($active);
        Cache::put($this->generationKey($trackedPersonId), $this->nextGeneration($trackedPersonId), now()->addHours(12));
        Cache::put($this->cancelReasonKey($trackedPersonId), $reason, now()->addHour());
        Cache::forget($this->activeKey($trackedPersonId));
    }

    public function terminateProcessTree(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                (new Process(['taskkill', '/PID', (string) $pid, '/T', '/F']))->setTimeout(10)->run();

                return;
            }

            $pids = array_values(array_unique([...$this->descendantPids($pid), $pid]));

            foreach (array_reverse($pids) as $processId) {
                $this->sendSignal($processId, 15);
            }

            usleep(500000);

            foreach (array_reverse($pids) as $processId) {
                $this->sendSignal($processId, 9);
            }
        } catch (\Throwable $exception) {
            Log::debug('Instagram-Scan-Prozess konnte nicht vollstaendig beendet werden.', [
                'pid' => $pid,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function processIsAlive(int $pid): bool
    {
        return $this->processIsRunning($pid);
    }

    private function startPersistentRun(int $trackedPersonId, int $generation, string $label, array $metadata): ?InstagramScanRun
    {
        if (! $this->scanRunsAvailable()) {
            return null;
        }

        try {
            $now = now('UTC');
            $scanContextKey = $this->scanContextKey($trackedPersonId, $metadata);
            $pendingResumeRunId = (int) (Cache::pull($this->pendingResumeKey($trackedPersonId)) ?: 0);
            $run = $pendingResumeRunId > 0
                ? InstagramScanRun::query()->whereKey($pendingResumeRunId)->first()
                : null;

            $attributes = [
                'tracked_person_id' => $trackedPersonId > 0
                    ? $trackedPersonId
                    : $this->metadataInt($metadata, 'tracked_person_id'),
                'instagram_profile_id' => $this->metadataInt($metadata, 'instagram_profile_id'),
                'user_id' => $this->metadataInt($metadata, 'user_id'),
                'scan_context_id' => $trackedPersonId,
                'scan_context_key' => $scanContextKey,
                'generation' => $generation,
                'scan_type' => $this->normalizeScanType($label, $metadata),
                'label' => Str::limit($label, 160, ''),
                'target_username' => $this->metadataString($metadata, 'target_username'),
                'status' => InstagramScanRun::STATUS_RUNNING,
                'started_at' => $now,
                'finished_at' => null,
                'last_heartbeat_at' => $now,
                'last_process_output_at' => null,
                'next_retry_at' => null,
                'last_error' => null,
                'node_processes' => [],
                'resume_payload' => [
                    ...(is_array($run?->resume_payload) ? $run->resume_payload : []),
                    'metadata' => $metadata,
                    'resumedFromRunId' => $pendingResumeRunId > 0 ? $pendingResumeRunId : null,
                    'startedAt' => $now->toIso8601String(),
                ],
            ];

            if ($run && ! in_array($run->status, [InstagramScanRun::STATUS_SUCCEEDED, InstagramScanRun::STATUS_CANCELLED], true)) {
                $run->forceFill([
                    ...$attributes,
                    'attempt' => max(1, (int) $run->attempt) + 1,
                ])->save();

                return $run;
            }

            return InstagramScanRun::create([
                ...$attributes,
                'attempt' => 1,
            ]);
        } catch (\Throwable $exception) {
            Log::debug('Persistenter Instagram-Scan-Run konnte nicht gestartet werden.', [
                'tracked_person_id' => $trackedPersonId,
                'generation' => $generation,
                'label' => $label,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function updatePersistentRun(int $trackedPersonId, int $generation, array $attributes): void
    {
        $run = $this->persistentRunForGeneration($trackedPersonId, $generation);

        if (! $run) {
            return;
        }

        try {
            $run->forceFill($attributes)->save();
        } catch (\Throwable $exception) {
            Log::debug('Persistenter Instagram-Scan-Run konnte nicht aktualisiert werden.', [
                'scan_run_id' => $run->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function persistentRunForGeneration(int $trackedPersonId, int $generation): ?InstagramScanRun
    {
        if (! $this->scanRunsAvailable() || $generation <= 0) {
            return null;
        }

        try {
            $active = $this->active($trackedPersonId);
            $scanRunId = (int) ($active['scanRunId'] ?? 0);

            if ($scanRunId > 0) {
                $run = InstagramScanRun::query()->whereKey($scanRunId)->first();

                if ($run) {
                    return $run;
                }
            }

            return InstagramScanRun::query()
                ->where('scan_context_id', $trackedPersonId)
                ->where('generation', $generation)
                ->latest('id')
                ->first();
        } catch (\Throwable $exception) {
            Log::debug('Persistenter Instagram-Scan-Run konnte nicht gelesen werden.', [
                'tracked_person_id' => $trackedPersonId,
                'generation' => $generation,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function storePersistentProcess(int $trackedPersonId, int $generation, int $pid, string $label, array $metadata): void
    {
        $run = $this->persistentRunForGeneration($trackedPersonId, $generation);

        if (! $run) {
            return;
        }

        $processes = collect($run->node_processes ?? [])
            ->filter(fn (mixed $process): bool => is_array($process))
            ->reject(fn (array $process): bool => (int) ($process['pid'] ?? 0) === $pid)
            ->push([
                'pid' => $pid,
                'label' => $label,
                'script' => $metadata['script'] ?? null,
                'command' => $metadata['command'] ?? null,
                'running' => true,
                'registeredAt' => now('UTC')->toIso8601String(),
                'lastSeenAt' => now('UTC')->toIso8601String(),
            ])
            ->values()
            ->all();

        $run->forceFill([
            'status' => InstagramScanRun::STATUS_RUNNING,
            'node_processes' => $processes,
            'last_heartbeat_at' => now('UTC'),
            'last_process_output_at' => now('UTC'),
        ])->save();
    }

    private function finishPersistentProcess(int $trackedPersonId, int $generation, int $pid): void
    {
        $run = $this->persistentRunForGeneration($trackedPersonId, $generation);

        if (! $run) {
            return;
        }

        $processes = collect($run->node_processes ?? [])
            ->filter(fn (mixed $process): bool => is_array($process))
            ->map(function (array $process) use ($pid): array {
                if ((int) ($process['pid'] ?? 0) !== $pid) {
                    return $process;
                }

                return [
                    ...$process,
                    'running' => false,
                    'endedAt' => now('UTC')->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        $run->forceFill([
            'node_processes' => $processes,
            'last_heartbeat_at' => now('UTC'),
        ])->save();
    }

    private function dispatchResumeJob(InstagramScanRun $run, Carbon $nextRetryAt): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        try {
            ResumeInstagramScanRunJob::dispatch((int) $run->id)->delay($nextRetryAt);
        } catch (\Throwable $exception) {
            Log::warning('Instagram-Scan-Retry-Job konnte nicht eingeplant werden.', [
                'scan_run_id' => $run->id,
                'next_retry_at' => $nextRetryAt->toIso8601String(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function resultNeedsRetry(mixed $result): bool
    {
        if ($result instanceof Collection) {
            return $result->contains(fn (mixed $item): bool => $this->resultNeedsRetry($item));
        }

        if ($this->resultWasGracefullyStopped($result) || $this->resultWasCancelled($result)) {
            return false;
        }

        $statusLevel = $this->resultStatusLevel($result);

        if (in_array($statusLevel, ['error', 'failed'], true)) {
            return true;
        }

        if ($statusLevel === 'partial') {
            return true;
        }

        return $this->resultOkFlag($result) === false;
    }

    private function resultWasCancelled(mixed $result): bool
    {
        if ($result instanceof Collection) {
            return $result->contains(fn (mixed $item): bool => $this->resultWasCancelled($item));
        }

        return $this->resultStatusLevel($result) === 'cancelled';
    }

    private function resultWasGracefullyStopped(mixed $result): bool
    {
        if ($result instanceof Collection) {
            return $result->contains(fn (mixed $item): bool => $this->resultWasGracefullyStopped($item));
        }

        foreach (['gracefullyStopped', 'gracefully_stopped', 'raw_payload.gracefullyStopped', 'raw_payload.gracefully_stopped'] as $key) {
            if (data_get($result, $key) === true) {
                return true;
            }
        }

        return false;
    }

    private function resultStatusLevel(mixed $result): ?string
    {
        if ($result instanceof Collection) {
            $levels = $result
                ->map(fn (mixed $item): ?string => $this->resultStatusLevel($item))
                ->filter()
                ->values();

            if ($levels->contains(fn (string $level): bool => in_array($level, ['error', 'failed'], true))) {
                return 'error';
            }

            if ($levels->contains('partial')) {
                return 'partial';
            }

            if ($levels->contains('cancelled')) {
                return 'cancelled';
            }

            return $levels->contains('success') ? 'success' : null;
        }

        foreach ([
            'status_level',
            'statusLevel',
            'resolvedStatusLevel',
            'raw_payload.statusLevel',
            'raw_payload.status_level',
        ] as $key) {
            $value = data_get($result, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return Str::lower(trim((string) $value));
            }
        }

        return null;
    }

    private function resultStatusMessage(mixed $result): ?string
    {
        if ($result instanceof Collection) {
            return $result
                ->map(fn (mixed $item): ?string => $this->resultStatusMessage($item))
                ->filter()
                ->first();
        }

        foreach ([
            'status_message',
            'statusMessage',
            'resolvedStatusMessage',
            'raw_payload.statusMessage',
            'raw_payload.status_message',
        ] as $key) {
            $value = data_get($result, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return Str::limit(trim((string) $value), 4000, '');
            }
        }

        return null;
    }

    private function resultOkFlag(mixed $result): ?bool
    {
        foreach (['ok', 'raw_payload.ok'] as $key) {
            $value = data_get($result, $key);

            if (is_bool($value)) {
                return $value;
            }
        }

        return null;
    }

    private function summarizeResultForPayload(mixed $result): array
    {
        return [
            'statusLevel' => $this->resultStatusLevel($result),
            'statusMessage' => $this->resultStatusMessage($result),
            'gracefullyStopped' => $this->resultWasGracefullyStopped($result),
        ];
    }

    private function scanRunsAvailable(): bool
    {
        if ($this->scanRunTableAvailable !== null) {
            return $this->scanRunTableAvailable;
        }

        try {
            return $this->scanRunTableAvailable = Schema::hasTable('instagram_scan_runs');
        } catch (\Throwable) {
            return $this->scanRunTableAvailable = false;
        }
    }

    private function metadataString(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? $metadata[Str::camel($key)] ?? null;

        return is_scalar($value) && trim((string) $value) !== ''
            ? trim((string) $value)
            : null;
    }

    private function metadataInt(array $metadata, string $key): ?int
    {
        $value = $metadata[$key] ?? $metadata[Str::camel($key)] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function normalizeScanType(string $label, array $metadata): string
    {
        $scanType = $this->metadataString($metadata, 'scan_type');

        if ($scanType) {
            return Str::lower(Str::snake($scanType));
        }

        $label = Str::lower($label);

        return match (true) {
            Str::contains($label, ['vollanalyse']) => 'full',
            Str::contains($label, ['mini']) => 'mini',
            Str::contains($label, ['followerliste']) => 'followers',
            Str::contains($label, ['gefolgt']) => 'following',
            Str::contains($label, ['deepsearch']) => 'suggestion_deepsearch',
            Str::contains($label, ['vorschlaege']) => 'suggestions',
            Str::contains($label, ['beitrag']) => 'posts',
            Str::contains($label, ['public-profile']) => 'public_connections',
            Str::contains($label, ['profil-listen']) => 'profile_list',
            default => 'instagram_scan',
        };
    }

    private function scanContextKey(int $trackedPersonId, array $metadata): string
    {
        $scanContextKey = $this->metadataString($metadata, 'scan_context_key');

        if ($scanContextKey) {
            return $scanContextKey;
        }

        $trackedPersonMetadataId = $this->metadataInt($metadata, 'tracked_person_id');

        if ($trackedPersonId > 0 || $trackedPersonMetadataId) {
            return 'tracked-person:'.($trackedPersonId > 0 ? $trackedPersonId : $trackedPersonMetadataId);
        }

        $profileId = $this->metadataInt($metadata, 'instagram_profile_id');

        if ($profileId) {
            return 'instagram-profile:'.$profileId;
        }

        return 'scan-context:'.$trackedPersonId;
    }

    private function active(int $trackedPersonId): array
    {
        $active = Cache::get($this->activeKey($trackedPersonId), []);

        return is_array($active) ? $active : [];
    }

    private function nextGeneration(int $trackedPersonId): int
    {
        $current = (int) Cache::get($this->generationKey($trackedPersonId), 0);
        $next = $current + 1;

        Cache::put($this->generationKey($trackedPersonId), $next, now()->addHours(12));

        return $next;
    }

    private function descendantPids(int $pid): array
    {
        if ($pid <= 0 || PHP_OS_FAMILY === 'Windows') {
            return [];
        }

        $process = new Process(['pgrep', '-P', (string) $pid]);
        $process->setTimeout(5)->run();

        if (! $process->isSuccessful() && trim($process->getOutput()) === '') {
            return [];
        }

        $children = collect(preg_split('/\s+/', trim($process->getOutput())) ?: [])
            ->map(fn (string $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values()
            ->all();

        $descendants = $children;

        foreach ($children as $childPid) {
            array_push($descendants, ...$this->descendantPids($childPid));
        }

        return $descendants;
    }

    private function sendSignal(int $pid, int $signal): void
    {
        if ($pid <= 0) {
            return;
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, $signal);

            return;
        }

        (new Process(['kill', '-'.$signal, (string) $pid]))->setTimeout(5)->run();
    }

    private function processIsRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            if (! @posix_kill($pid, 0)) {
                return false;
            }

            $process = new Process(['ps', '-p', (string) $pid, '-o', 'stat=']);
            $process->setTimeout(5)->run();

            return ! $process->isSuccessful() || ! str_starts_with(trim($process->getOutput()), 'Z');
        }

        $process = new Process(['ps', '-p', (string) $pid, '-o', 'pid=']);
        $process->setTimeout(5)->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }

    private function writeGracefulStopFile(array $active, string $reason): void
    {
        $filePath = (string) ($active['gracefulStopFilePath'] ?? '');

        if ($filePath === '') {
            return;
        }

        try {
            File::ensureDirectoryExists(dirname($filePath));
            File::put($filePath, json_encode([
                'requestedAt' => now()->toIso8601String(),
                'reason' => $reason,
                'trackedPersonId' => (int) ($active['trackedPersonId'] ?? 0),
                'generation' => (int) ($active['generation'] ?? 0),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $exception) {
            Log::debug('Instagram-Scan-Stoppsignal konnte nicht geschrieben werden.', [
                'path' => $filePath,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function deleteGracefulStopFile(array $active): void
    {
        $filePath = (string) ($active['gracefulStopFilePath'] ?? '');

        if ($filePath === '') {
            return;
        }

        try {
            File::delete($filePath);
        } catch (\Throwable $exception) {
            Log::debug('Instagram-Scan-Stoppsignal konnte nicht geloescht werden.', [
                'path' => $filePath,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function gracefulStopFilePath(int $trackedPersonId, int $generation): string
    {
        return storage_path('app/tmp/instagram-scan-stop-'.$trackedPersonId.'-'.$generation.'.json');
    }

    private function activeKey(int $trackedPersonId): string
    {
        return 'tracked-person-instagram-active-scan:'.$trackedPersonId;
    }

    private function generationKey(int $trackedPersonId): string
    {
        return 'tracked-person-instagram-scan-generation:'.$trackedPersonId;
    }

    private function cancelReasonKey(int $trackedPersonId): string
    {
        return 'tracked-person-instagram-scan-cancel-reason:'.$trackedPersonId;
    }

    private function pendingResumeKey(int $trackedPersonId): string
    {
        return 'tracked-person-instagram-scan-pending-resume:'.$trackedPersonId;
    }
}
