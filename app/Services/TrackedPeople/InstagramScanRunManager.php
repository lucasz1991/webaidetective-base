<?php

namespace App\Services\TrackedPeople;

use App\Jobs\ResumeInstagramScanRunJob;
use App\Models\InstagramProfile;
use App\Models\InstagramScanRun;
use App\Models\TrackedPerson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InstagramScanRunManager
{
    public function __construct(
        private readonly TrackedPersonInstagramScanCoordinator $scanCoordinator,
        private readonly TrackedPersonInstagramWorkflowService $workflowService,
        private readonly TrackedPersonInstagramAnalysisService $analysisService,
        private readonly TrackedPersonInstagramPublicProfileScanService $publicProfileScanService,
        private readonly TrackedPersonInstagramSuggestionScanService $suggestionScanService,
        private readonly TrackedPersonInstagramProfileListScanService $profileListScanService,
        private readonly TrackedPersonInstagramPostScanService $postScanService,
        private readonly InstagramProfileScanService $profileScanService,
    ) {}

    public function manageOnce(int $staleAfterSeconds = 300, int $limit = 50): array
    {
        $scheduled = $this->scheduleInterruptedRuns($staleAfterSeconds, $limit);
        $dispatched = $this->dispatchDueRetries($limit);

        return [
            'scheduled' => $scheduled,
            'dispatched' => $dispatched,
        ];
    }

    public function scheduleInterruptedRuns(int $staleAfterSeconds = 300, int $limit = 50): int
    {
        $scheduled = 0;
        $threshold = now('UTC')->subSeconds(max(60, $staleAfterSeconds));

        InstagramScanRun::query()
            ->active()
            ->where(function ($query) use ($threshold): void {
                $query
                    ->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<=', $threshold)
                    ->orWhereNotNull('node_processes');
            })
            ->oldest('started_at')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (InstagramScanRun $run) use (&$scheduled, $staleAfterSeconds): void {
                $reason = $this->interruptedReason($run, $staleAfterSeconds);

                if ($reason === null) {
                    return;
                }

                $this->scheduleRetry($run, $reason);
                $scheduled++;
            });

        return $scheduled;
    }

    public function dispatchDueRetries(int $limit = 50): int
    {
        $dispatched = 0;

        InstagramScanRun::query()
            ->retryDue()
            ->oldest('next_retry_at')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (InstagramScanRun $run) use (&$dispatched): void {
                try {
                    if (config('queue.default') === 'sync') {
                        $this->resume((int) $run->id);
                    } else {
                        ResumeInstagramScanRunJob::dispatch((int) $run->id);
                    }

                    $dispatched++;
                } catch (\Throwable $exception) {
                    Log::warning('Faelliger Instagram-Scan-Retry konnte nicht gestartet werden.', [
                        'scan_run_id' => $run->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        return $dispatched;
    }

    public function resume(int $scanRunId): void
    {
        $run = InstagramScanRun::query()->whereKey($scanRunId)->first();

        if (! $run || in_array($run->status, [InstagramScanRun::STATUS_SUCCEEDED, InstagramScanRun::STATUS_CANCELLED], true)) {
            return;
        }

        if ($run->next_retry_at && $run->next_retry_at->isFuture()) {
            return;
        }

        $contextId = $this->contextIdForRun($run);

        if ($contextId === null) {
            $this->cancelUnresumableRun($run, 'Instagram-Scan kann nicht wiederaufgenommen werden, weil kein Scan-Kontext gespeichert ist.');

            return;
        }

        $this->scanCoordinator->prepareResume($contextId, (int) $run->id);
        $run->forceFill([
            'status' => InstagramScanRun::STATUS_QUEUED,
            'last_heartbeat_at' => now('UTC'),
        ])->save();

        try {
            $this->executeRun($run->fresh() ?: $run);
        } catch (\Throwable $exception) {
            $this->scheduleRetry($run->fresh() ?: $run, $exception->getMessage());
        }
    }

    public function scheduleRetry(InstagramScanRun $run, string $reason, int $delaySeconds = 300): void
    {
        $run = $run->fresh() ?: $run;

        if (in_array($run->status, [InstagramScanRun::STATUS_SUCCEEDED, InstagramScanRun::STATUS_CANCELLED], true)) {
            return;
        }

        if (
            $run->status === InstagramScanRun::STATUS_RETRY_SCHEDULED
            && $run->next_retry_at
            && $run->next_retry_at->isFuture()
        ) {
            return;
        }

        $nextRetryAt = now('UTC')->addSeconds(max(60, $delaySeconds));
        $run->forceFill([
            'status' => InstagramScanRun::STATUS_RETRY_SCHEDULED,
            'finished_at' => now('UTC'),
            'last_heartbeat_at' => now('UTC'),
            'next_retry_at' => $nextRetryAt,
            'last_error' => Str::limit($reason, 4000, ''),
            'resume_payload' => [
                ...(is_array($run->resume_payload) ? $run->resume_payload : []),
                'lastFailureAt' => now('UTC')->toIso8601String(),
                'nextRetryAt' => $nextRetryAt->toIso8601String(),
            ],
        ])->save();

        if (config('queue.default') !== 'sync') {
            try {
                ResumeInstagramScanRunJob::dispatch((int) $run->id)->delay($nextRetryAt);
            } catch (\Throwable $exception) {
                Log::warning('Instagram-Scan-Retry konnte nicht eingeplant werden.', [
                    'scan_run_id' => $run->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function executeRun(InstagramScanRun $run): void
    {
        $scanType = Str::lower((string) $run->scan_type);

        if (
            Str::startsWith($scanType, 'profile_')
            && ! ($scanType === 'profile_list' && $run->tracked_person_id)
        ) {
            $this->executeProfileRun($run, $scanType);

            return;
        }

        $trackedPerson = $run->tracked_person_id
            ? TrackedPerson::query()->whereKey($run->tracked_person_id)->first()
            : null;

        if (! $trackedPerson || ! $trackedPerson->instagram_username) {
            $this->cancelUnresumableRun($run, 'Instagram-Scan kann nicht wiederaufgenommen werden, weil die getrackte Person fehlt.');

            return;
        }

        match ($scanType) {
            'full' => $this->workflowService->runAnalysis($trackedPerson, true),
            'followers' => $this->analysisService->scanRelationshipList($trackedPerson, 'followers'),
            'following' => $this->analysisService->scanRelationshipList($trackedPerson, 'following'),
            'suggestions' => $this->workflowService->runSuggestionScan($trackedPerson),
            'suggestion_deepsearch' => $this->workflowService->runSuggestionDeepSearch($trackedPerson),
            'posts' => $this->workflowService->runPostScan(
                $trackedPerson,
                $trackedPerson->latestInstagramSnapshot()->first(),
            ),
            'public_connections' => $this->publicProfileScanService->scan($trackedPerson),
            'profile_list' => $this->resumeTrackedPersonProfileList($trackedPerson, $run),
            default => $this->workflowService->runAnalysis($trackedPerson, false),
        };
    }

    private function executeProfileRun(InstagramScanRun $run, string $scanType): void
    {
        $profile = $run->instagram_profile_id
            ? InstagramProfile::query()->whereKey($run->instagram_profile_id)->first()
            : null;
        $userId = (int) ($run->user_id ?? 0);

        if (! $profile || $userId <= 0) {
            $this->cancelUnresumableRun($run, 'Instagram-Profilscan kann nicht wiederaufgenommen werden, weil Profil oder Benutzer fehlt.');

            return;
        }

        match ($scanType) {
            'profile_full' => $this->profileScanService->scan($profile, $userId, true),
            'profile_list' => $this->profileListScanService->scan(
                null,
                $profile,
                null,
                $this->relationshipsForRun($run),
                $userId,
            ),
            'profile_posts' => $this->postScanService->scanProfile($profile, $userId),
            'profile_suggestions' => $this->suggestionScanService->scanProfile($profile, $userId),
            'profile_suggestion_deepsearch' => $this->suggestionScanService->scanProfileDeepSearch($profile, $userId),
            default => $this->profileScanService->scan($profile, $userId, false),
        };
    }

    private function resumeTrackedPersonProfileList(TrackedPerson $trackedPerson, InstagramScanRun $run): void
    {
        $profile = $run->instagram_profile_id
            ? InstagramProfile::query()->whereKey($run->instagram_profile_id)->first()
            : $trackedPerson->currentInstagramProfile;

        if (! $profile) {
            $this->cancelUnresumableRun($run, 'Profil-Listen-Scan kann nicht wiederaufgenommen werden, weil das Instagram-Profil fehlt.');

            return;
        }

        $this->profileListScanService->scan(
            $trackedPerson,
            $profile,
            null,
            $this->relationshipsForRun($run),
            (int) ($run->user_id ?: $trackedPerson->user_id),
        );
    }

    private function relationshipsForRun(InstagramScanRun $run): array
    {
        $relationships = data_get($run->resume_payload, 'retryRelationships');

        if (! is_array($relationships) || $relationships === []) {
            $relationships = data_get($run->resume_payload, 'metadata.relationships', []);
        }

        $relationships = collect(is_array($relationships) ? $relationships : [])
            ->map(fn ($relationship): string => Str::lower(trim((string) $relationship)))
            ->filter(fn (string $relationship): bool => in_array($relationship, ['followers', 'following'], true))
            ->unique()
            ->values()
            ->all();

        return $relationships !== [] ? $relationships : ['followers', 'following'];
    }

    private function interruptedReason(InstagramScanRun $run, int $staleAfterSeconds): ?string
    {
        $runningProcesses = collect($run->node_processes ?? [])
            ->filter(fn (mixed $process): bool => is_array($process))
            ->filter(fn (array $process): bool => (bool) ($process['running'] ?? true))
            ->values();

        if ($runningProcesses->isNotEmpty()) {
            $alivePids = $runningProcesses
                ->map(fn (array $process): int => (int) ($process['pid'] ?? 0))
                ->filter(fn (int $pid): bool => $pid > 0 && $this->scanCoordinator->processIsAlive($pid))
                ->values();

            if ($alivePids->isNotEmpty()) {
                return null;
            }

            $pids = $runningProcesses
                ->pluck('pid')
                ->filter()
                ->implode(', ');

            return 'Instagram-Scan wird wiederaufgenommen, weil kein registrierter Node-Prozess mehr laeuft. PIDs: '.$pids.'.';
        }

        $heartbeatAt = $run->last_heartbeat_at ?: $run->started_at ?: $run->created_at;

        if (! $heartbeatAt instanceof Carbon) {
            return 'Instagram-Scan wird wiederaufgenommen, weil kein Heartbeat gespeichert ist.';
        }

        if ($heartbeatAt->diffInSeconds(now('UTC')) < max(60, $staleAfterSeconds)) {
            return null;
        }

        return 'Instagram-Scan wird wiederaufgenommen, weil seit '.$staleAfterSeconds.' Sekunden kein Node-Prozess oder Heartbeat aktiv ist.';
    }

    private function contextIdForRun(InstagramScanRun $run): ?int
    {
        if ($run->scan_context_id !== null) {
            return (int) $run->scan_context_id;
        }

        if ($run->tracked_person_id) {
            return (int) $run->tracked_person_id;
        }

        if ($run->instagram_profile_id) {
            return -1 * (int) $run->instagram_profile_id;
        }

        return null;
    }

    private function cancelUnresumableRun(InstagramScanRun $run, string $reason): void
    {
        $run->forceFill([
            'status' => InstagramScanRun::STATUS_CANCELLED,
            'finished_at' => now('UTC'),
            'next_retry_at' => null,
            'last_error' => $reason,
            'resume_payload' => [
                ...(is_array($run->resume_payload) ? $run->resume_payload : []),
                'cancelledAt' => now('UTC')->toIso8601String(),
                'cancelReason' => $reason,
            ],
        ])->save();
    }
}
