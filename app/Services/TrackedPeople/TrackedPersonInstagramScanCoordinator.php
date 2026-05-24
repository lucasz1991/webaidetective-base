<?php

namespace App\Services\TrackedPeople;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class TrackedPersonInstagramScanCoordinator
{
    public function begin(int $trackedPersonId, string $label): array
    {
        $this->cancelActive($trackedPersonId, 'Neuer Instagram-Scan wurde gestartet.');

        $generation = $this->nextGeneration($trackedPersonId);
        $context = [
            'trackedPersonId' => $trackedPersonId,
            'generation' => $generation,
            'label' => $label,
            'startedAt' => now()->toIso8601String(),
            'processes' => [],
        ];

        Cache::put($this->activeKey($trackedPersonId), $context, now()->addHours(12));

        return $context;
    }

    public function finish(int $trackedPersonId, int $generation): void
    {
        $active = $this->active($trackedPersonId);

        if ((int) ($active['generation'] ?? 0) === $generation) {
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
