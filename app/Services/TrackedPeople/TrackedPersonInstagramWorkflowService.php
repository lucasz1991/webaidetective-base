<?php

namespace App\Services\TrackedPeople;

use App\Exceptions\TrackedPersonInstagramScanCancelledException;
use App\Jobs\MonitorTrackedPersonInstagram;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramSnapshot;
use App\Models\TrackedPersonInstagramSuggestionScan;

class TrackedPersonInstagramWorkflowService
{
    public function __construct(
        private readonly TrackedPersonInstagramSuggestionScanService $suggestionScanService,
    ) {
    }

    /**
     * @return array{
     *     snapshot: TrackedPersonInstagramSnapshot,
     *     queuedFullScan: bool,
     *     privateSuggestionScan: TrackedPersonInstagramSuggestionScan|null,
     *     privateSuggestionScanFailed: bool,
     *     privateSuggestionScanMessage: string,
     *     resolvedStatusLevel: string,
     *     resolvedStatusMessage: string
     * }
     */
    public function runAnalysis(
        TrackedPerson $trackedPerson,
        bool $fullScan = false,
        ?callable $progress = null,
        bool $sendNotificationsForQueuedFullScan = true,
    ): array {
        $snapshot = $trackedPerson->analyzeInstagram($progress, $fullScan);
        $queuedFullScan = false;
        $privateSuggestionScan = null;
        $privateSuggestionScanFailed = false;
        $privateSuggestionScanMessage = '';

        if ($fullScan && $this->snapshotProfileVisibility($snapshot) === 'private') {
            if ($progress) {
                $progress([
                    'phase' => 'suggestions',
                    'percent' => 1,
                    'message' => 'Privates Profil erkannt; Profilvorschlag-Verbindungsscan wird gestartet.',
                    'foundSuggestions' => 0,
                    'suggestionConnections' => [],
                ]);
            }

            try {
                $privateSuggestionScan = $this->runSuggestionScan(
                    $trackedPerson->fresh() ?: $trackedPerson,
                    $progress,
                );
                $privateSuggestionScanMessage = ' Privates Profil erkannt; Vorschlag-Scan abgeschlossen mit '
                    .number_format((int) $privateSuggestionScan->suggestion_matches_count, 0, ',', '.')
                    .' gefundenen Vorschlag-Verbindungen.';
            } catch (TrackedPersonInstagramScanCancelledException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $privateSuggestionScanFailed = true;
                $privateSuggestionScanMessage = ' Privates Profil erkannt; Vorschlag-Scan fehlgeschlagen: '.$exception->getMessage();

                ($trackedPerson->fresh() ?: $trackedPerson)->forceFill([
                    'last_instagram_status_level' => 'partial',
                    'last_instagram_status_message' => 'Instagram-Analyse abgeschlossen; Vorschlag-Scan fehlgeschlagen: '.$exception->getMessage(),
                ])->save();
            }
        }

        if (! $fullScan && MonitorTrackedPersonInstagram::shouldRunFullScanAfterSnapshot($snapshot)) {
            $queuedFullScan = MonitorTrackedPersonInstagram::dispatchFullScanIfNotQueued(
                $trackedPerson->id,
                $sendNotificationsForQueuedFullScan,
            );

            if (! $queuedFullScan) {
                $trackedPerson->forceFill([
                    'last_instagram_status_level' => 'partial',
                    'last_instagram_status_message' => 'Instagram-Profil-/Listen-Aenderung erkannt; Instagram-Vollanalyse ist bereits eingereiht oder laeuft.',
                ])->save();
            }
        }

        $resolvedStatusLevel = $snapshot->status_level === 'success'
            ? 'success'
            : ($snapshot->status_level === 'partial' ? 'partial' : 'error');

        if ($privateSuggestionScanFailed || ($privateSuggestionScan && $privateSuggestionScan->status_level !== 'success')) {
            $resolvedStatusLevel = 'partial';
        }

        return [
            'snapshot' => $snapshot,
            'queuedFullScan' => $queuedFullScan,
            'privateSuggestionScan' => $privateSuggestionScan,
            'privateSuggestionScanFailed' => $privateSuggestionScanFailed,
            'privateSuggestionScanMessage' => $privateSuggestionScanMessage,
            'resolvedStatusLevel' => $resolvedStatusLevel,
            'resolvedStatusMessage' => ($fullScan ? 'Instagram-Analyse' : 'Instagram-Mini-Scan')
                .' abgeschlossen: '
                .$snapshot->status_message
                .$privateSuggestionScanMessage
                .($queuedFullScan ? ' Eine Vollanalyse wurde als Hintergrund-Job eingereiht.' : ''),
        ];
    }

    public function runSuggestionScan(
        TrackedPerson $trackedPerson,
        ?callable $progress = null,
    ): TrackedPersonInstagramSuggestionScan {
        return $this->suggestionScanService->scan($trackedPerson, $progress);
    }

    private function snapshotProfileVisibility(?TrackedPersonInstagramSnapshot $snapshot): string
    {
        if (in_array($snapshot?->profile_visibility, ['public', 'private'], true)) {
            return $snapshot->profile_visibility;
        }

        $visibility = data_get($snapshot?->raw_payload, 'extractedProfile.profileVisibility');

        if (in_array($visibility, ['public', 'private'], true)) {
            return $visibility;
        }

        return data_get($snapshot?->raw_payload, 'extractedProfile.isPrivate') === true
            ? 'private'
            : 'unknown';
    }
}
