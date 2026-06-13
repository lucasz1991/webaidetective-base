<?php

namespace App\Jobs;

use App\Exceptions\TrackedPersonInstagramScanCancelledException;
use App\Models\TrackedPerson;
use App\Services\TrackedPeople\TrackedPersonInstagramAnalysisService;
use App\Services\TrackedPeople\TrackedPersonInstagramPublicProfileScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunTrackedPersonInstagramToolScan implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $trackedPersonId,
        public readonly string $scanType,
        public readonly bool $sendNotifications = false,
    ) {}

    public function handle(): void
    {
        $trackedPerson = TrackedPerson::query()->find($this->trackedPersonId);

        if (! $trackedPerson || ! $trackedPerson->instagram_username) {
            return;
        }

        $trackedPerson->forceFill([
            'last_instagram_status_level' => 'partial',
            'last_instagram_status_message' => $this->label().' wurde vom AI-Assistenten gestartet.',
        ])->save();

        try {
            match ($this->normalizedScanType()) {
                'mini' => app(TrackedPersonInstagramWorkflowService::class)->runAnalysis($trackedPerson, false),
                'full' => app(TrackedPersonInstagramWorkflowService::class)->runAnalysis($trackedPerson, true),
                'followers' => app(TrackedPersonInstagramAnalysisService::class)->scanRelationshipList($trackedPerson, 'followers'),
                'following' => app(TrackedPersonInstagramAnalysisService::class)->scanRelationshipList($trackedPerson, 'following'),
                'suggestions' => app(TrackedPersonInstagramWorkflowService::class)->runSuggestionScan($trackedPerson),
                'suggestion_deepsearch' => app(TrackedPersonInstagramWorkflowService::class)->runSuggestionDeepSearch($trackedPerson),
                'posts' => app(TrackedPersonInstagramWorkflowService::class)->runPostScan(
                    $trackedPerson,
                    $trackedPerson->latestInstagramSnapshot()->first(),
                ),
                'public_connections' => app(TrackedPersonInstagramPublicProfileScanService::class)->scan($trackedPerson),
                default => throw new \InvalidArgumentException('Unbekannter Scan-Typ: '.$this->scanType),
            };
        } catch (TrackedPersonInstagramScanCancelledException) {
            $trackedPerson->markInstagramScanTerminal(
                'cancelled',
                $this->label().' wurde beendet, weil ein neuer Scan gestartet wurde.',
            );
        } catch (\Throwable $exception) {
            Log::warning('AI-ausgeloester Instagram-Scan fehlgeschlagen.', [
                'tracked_person_id' => $trackedPerson->id,
                'scan_type' => $this->scanType,
                'error' => $exception->getMessage(),
            ]);

            $trackedPerson->markInstagramScanTerminal(
                'error',
                $this->label().' fehlgeschlagen: '.$exception->getMessage(),
            );
        }
    }

    public function uniqueId(): string
    {
        return $this->trackedPersonId.':'.$this->normalizedScanType();
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
}
