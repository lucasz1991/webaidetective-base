<?php

namespace App\Services\TrackedPeople;

use App\Jobs\RunTrackedPersonInstagramToolScan;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class TrackedPersonScanDispatcher
{
    public function dispatch(
        int $trackedPersonId,
        string $scanType,
        bool $sendNotifications = false,
        ?string $assistantScanToken = null,
    ): void {
        $job = new RunTrackedPersonInstagramToolScan(
            $trackedPersonId,
            $scanType,
            $sendNotifications,
            $assistantScanToken,
        );

        if (config('queue.default') !== 'sync') {
            app(Dispatcher::class)->dispatch($job);

            return;
        }

        $job->onConnection('database')->onQueue('default');
        app(Dispatcher::class)->dispatch($job);
        $this->startDatabaseWorker();
    }

    private function startDatabaseWorker(): void
    {
        $phpBinary = trim((string) config('queue.worker_php_binary', 'php')) ?: 'php';
        $arguments = [
            escapeshellarg($phpBinary),
            escapeshellarg(base_path('artisan')),
            'queue:work',
            'database',
            '--queue=default',
            '--stop-when-empty',
            '--sleep=1',
            '--tries=1',
            '--timeout=0',
        ];

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start "" /B '.implode(' ', $arguments).' > NUL 2>&1';
            $launcher = new Process(['cmd', '/C', $command], base_path());
        } else {
            $command = 'nohup '.implode(' ', $arguments).' > /dev/null 2>&1 &';
            $launcher = new Process(['sh', '-lc', $command], base_path());
        }

        $launcher->setTimeout(5);
        $launcher->run();

        if (! $launcher->isSuccessful()) {
            Log::warning('Der Hintergrund-Queue-Worker konnte nicht gestartet werden.', [
                'exit_code' => $launcher->getExitCode(),
                'error' => trim($launcher->getErrorOutput()),
            ]);

            throw new \RuntimeException(
                'Der Scan wurde gespeichert, aber der Hintergrundprozess konnte nicht gestartet werden.',
            );
        }
    }
}
