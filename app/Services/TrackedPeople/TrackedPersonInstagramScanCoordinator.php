<?php

namespace App\Services\TrackedPeople;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class TrackedPersonInstagramScanCoordinator
{
    public function begin(int $trackedPersonId, string $label): array
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

    public function shouldCancel(int $trackedPersonId, int $generation): bool
    {
        return (int) Cache::get($this->generationKey($trackedPersonId), 0) !== $generation;
    }

    public function assertCurrent(int $trackedPersonId, int $generation): void
    {
        if ($this->shouldCancel($trackedPersonId, $generation)) {
            throw new \App\Exceptions\TrackedPersonInstagramScanCancelledException(
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

    public function touchActiveScan(int $trackedPersonId): void
    {
        $active = $this->active($trackedPersonId);

        if ((int) ($active['generation'] ?? 0) <= 0) {
            return;
        }

        $active['updatedAt'] = now()->toIso8601String();
        Cache::put($this->activeKey($trackedPersonId), $active, now()->addHours(12));
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

    public function registerProcess(int $trackedPersonId, int $generation, int $pid, string $label): void
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
                'registeredAt' => now()->toIso8601String(),
            ])
            ->values()
            ->all();

        $active['processes'] = $processes;
        $active['updatedAt'] = now()->toIso8601String();
        Cache::put($this->activeKey($trackedPersonId), $active, now()->addHours(12));
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
}
