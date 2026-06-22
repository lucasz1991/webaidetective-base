<?php

namespace App\Jobs;

use App\Exceptions\TrackedPersonInstagramScanCancelledException;
use App\Models\TrackedPerson;
use App\Services\Ai\InvestigationAssistantScanStatusStore;
use App\Services\TrackedPeople\TrackedPersonInstagramAnalysisService;
use App\Services\TrackedPeople\TrackedPersonInstagramPublicProfileScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramScanCoordinator;
use App\Services\TrackedPeople\TrackedPersonInstagramWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RunTrackedPersonInstagramToolScan implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $uniqueFor = 3600;

    public ?string $assistantScanToken = null;

    public function __construct(
        public readonly int $trackedPersonId,
        public readonly string $scanType,
        public readonly bool $sendNotifications = false,
        ?string $assistantScanToken = null,
    ) {
        $this->assistantScanToken = $assistantScanToken;
    }

    public function handle(): void
    {
        $trackedPerson = TrackedPerson::query()->find($this->trackedPersonId);

        if (! $trackedPerson || ! $trackedPerson->instagram_username) {
            $this->failAssistantScan('Das zu scannende Instagram-Profil wurde nicht gefunden.');

            return;
        }

        $statusStore = app(InvestigationAssistantScanStatusStore::class);
        $progress = $this->assistantScanToken
            ? function (array $state) use ($statusStore): void {
                app(TrackedPersonInstagramScanCoordinator::class)->touchActiveScan($this->trackedPersonId);
                $statusStore->progress($this->assistantScanToken, $state);
            }
        : null;

        if ($this->assistantScanToken) {
            $statusStore->progress($this->assistantScanToken, [
                'percent' => 1,
                'phase' => 'starting',
                'message' => $this->label().' wurde gestartet.',
            ]);
        }

        $trackedPerson->forceFill([
            'last_instagram_status_level' => 'partial',
            'last_instagram_status_message' => $this->label().' wurde vom AI-Assistenten gestartet.',
        ])->save();

        try {
            $result = match ($this->normalizedScanType()) {
                'mini' => app(TrackedPersonInstagramWorkflowService::class)->runAnalysis($trackedPerson, false, $progress),
                'full' => app(TrackedPersonInstagramWorkflowService::class)->runAnalysis($trackedPerson, true, $progress),
                'followers' => app(TrackedPersonInstagramAnalysisService::class)->scanRelationshipList($trackedPerson, 'followers', $progress),
                'following' => app(TrackedPersonInstagramAnalysisService::class)->scanRelationshipList($trackedPerson, 'following', $progress),
                'suggestions' => app(TrackedPersonInstagramWorkflowService::class)->runSuggestionScan($trackedPerson, $progress),
                'suggestion_deepsearch' => app(TrackedPersonInstagramWorkflowService::class)->runSuggestionDeepSearch($trackedPerson, $progress),
                'posts' => app(TrackedPersonInstagramWorkflowService::class)->runPostScan(
                    $trackedPerson,
                    $trackedPerson->latestInstagramSnapshot()->first(),
                    $progress,
                ),
                'public_connections' => app(TrackedPersonInstagramPublicProfileScanService::class)->scan($trackedPerson, $progress),
                default => throw new \InvalidArgumentException('Unbekannter Scan-Typ: '.$this->scanType),
            };

            if ($this->assistantScanToken) {
                $freshPerson = $trackedPerson->fresh('latestInstagramSnapshot') ?: $trackedPerson;
                $statusResult = [
                    'tracked_person_id' => (int) $freshPerson->id,
                    'instagram_username' => $freshPerson->instagram_username,
                    'status_level' => $freshPerson->last_instagram_status_level,
                    'status_message' => $freshPerson->last_instagram_status_message,
                    'analyzed_at' => optional($freshPerson->last_instagram_analyzed_at)?->toIso8601String(),
                    'snapshot_id' => $freshPerson->latestInstagramSnapshot?->id,
                ];

                if ($this->resultIsResumable($result)) {
                    $statusStore->pause(
                        $this->assistantScanToken,
                        $this->label().' wurde unterbrochen. Der bisherige Datenstand wurde gespeichert.',
                        $statusResult,
                    );
                } else {
                    $statusStore->complete(
                        $this->assistantScanToken,
                        $statusResult,
                        $this->label().' wurde abgeschlossen.',
                    );
                }
            }
        } catch (TrackedPersonInstagramScanCancelledException) {
            $trackedPerson->markInstagramScanTerminal(
                'cancelled',
                $this->label().' wurde beendet, weil ein neuer Scan gestartet wurde.',
            );
            if ($this->assistantScanToken) {
                $statusStore->cancel(
                    $this->assistantScanToken,
                    $this->label().' wurde beendet, weil ein neuer Scan gestartet wurde.',
                );
            }
        } catch (\Throwable $exception) {
            Log::warning('AI-ausgeloester Instagram-Scan fehlgeschlagen.', [
                'tracked_person_id' => $trackedPerson->id,
                'scan_type' => $this->scanType,
                'error' => $exception->getMessage(),
            ]);

            $message = $this->label().' wurde unterbrochen; automatische Wiederaufnahme in ca. 5 Minuten geplant: '.$exception->getMessage();
            $trackedPerson->forceFill([
                'last_instagram_status_level' => 'partial',
                'last_instagram_status_message' => $message,
            ])->save();

            if ($this->assistantScanToken) {
                $statusStore->pause(
                    $this->assistantScanToken,
                    $message,
                    [
                        'tracked_person_id' => (int) $trackedPerson->id,
                        'instagram_username' => $trackedPerson->instagram_username,
                        'status_level' => 'partial',
                        'status_message' => $message,
                    ],
                );
            }
        }
    }

    public function uniqueId(): string
    {
        return implode(':', array_filter([
            $this->trackedPersonId,
            $this->normalizedScanType(),
            $this->assistantScanToken,
        ]));
    }

    private function normalizedScanType(): string
    {
        return strtolower(trim($this->scanType));
    }

    private function label(): string
    {
        return match ($this->normalizedScanType()) {
            'mini' => 'Instagram-Mini-Scan',
            'full' => 'Instagram-Vollanalyse',
            'followers' => 'Instagram-Followerlisten-Scan',
            'following' => 'Instagram-Gefolgt-Listen-Scan',
            'suggestions' => 'Instagram-Vorschlaege-Scan',
            'suggestion_deepsearch' => 'Instagram-Vorschlaege DeepSearch',
            'posts' => 'Instagram-Beitragsscan',
            'public_connections' => 'Public-Profile-Verbindungsscan',
            default => 'Instagram-Scan',
        };
    }

    private function resultIsResumable(mixed $result): bool
    {
        if ($result instanceof Collection) {
            return $result->contains(fn (mixed $item): bool => $this->resultIsResumable($item));
        }

        if (is_object($result)) {
            if ((bool) ($result->gracefully_stopped ?? false)) {
                return true;
            }

            if (in_array($result->status_level ?? null, ['cancelled', 'partial'], true)) {
                return $this->containsResumeSignal($result->raw_payload ?? []);
            }

            return false;
        }

        return is_array($result) && (
            in_array($result['resolvedStatusLevel'] ?? null, ['cancelled'], true)
            || $this->containsResumeSignal($result)
        );
    }

    private function containsResumeSignal(mixed $value): bool
    {
        if (is_object($value)) {
            return $this->resultIsResumable($value);
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (
                in_array($key, ['gracefullyStopped', 'gracefully_stopped', 'stoppedForRateLimit', 'isResumable'], true)
                && $item === true
            ) {
                return true;
            }

            if ((is_array($item) || is_object($item)) && $this->containsResumeSignal($item)) {
                return true;
            }
        }

        return false;
    }

    private function failAssistantScan(string $message): void
    {
        if (! $this->assistantScanToken) {
            return;
        }

        app(InvestigationAssistantScanStatusStore::class)->fail($this->assistantScanToken, $message);
    }
}
