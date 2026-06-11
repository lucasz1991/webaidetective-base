<?php

namespace App\Services\TrackedPeople;

use App\Exceptions\TrackedPersonInstagramScanCancelledException;
use App\Models\InstagramPostScan;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramSnapshot;
use App\Models\TrackedPersonInstagramSuggestionScan;
use Illuminate\Support\Carbon;

class TrackedPersonInstagramWorkflowService
{
    public function __construct(
        private readonly TrackedPersonInstagramSuggestionScanService $suggestionScanService,
        private readonly TrackedPersonInstagramAnalysisService $analysisService,
        private readonly TrackedPersonInstagramPostScanService $postScanService,
    ) {
    }

    /**
     * @return array{
     *     snapshot: TrackedPersonInstagramSnapshot,
     *     privateSuggestionScan: TrackedPersonInstagramSuggestionScan|null,
     *     privateSuggestionScanFailed: bool,
     *     privateSuggestionScanMessage: string,
     *     relationshipScans: array<string, TrackedPersonInstagramSnapshot>,
     *     postScan: InstagramPostScan|null,
     *     followUpFailures: array<int, string>,
     *     resolvedStatusLevel: string,
     *     resolvedStatusMessage: string
     * }
     */
    public function runAnalysis(
        TrackedPerson $trackedPerson,
        bool $fullScan = false,
        ?callable $progress = null,
    ): array {
        $snapshot = $trackedPerson->analyzeInstagram($progress, $fullScan);
        $privateSuggestionScan = null;
        $privateSuggestionScanFailed = false;
        $privateSuggestionScanMessage = '';
        $relationshipScans = [];
        $postScan = null;
        $followUpFailures = [];
        $followUpMessages = [];
        $visibility = $this->snapshotProfileVisibility($snapshot);
        $changedFields = $this->changedFields($snapshot);
        $metricFields = array_values(array_intersect($changedFields, [
            'followers_count',
            'following_count',
            'posts_count',
        ]));

        if (
            $visibility === 'private'
            && (
                $fullScan
                || (! $fullScan && $metricFields !== [])
            )
        ) {
            $recentSuggestionScan = ! $fullScan
                ? $this->recentSuggestionScanWithinLastHour($trackedPerson)
                : null;

            if ($recentSuggestionScan) {
                $privateSuggestionScanMessage = ' Privates Profil erkannt; Vorschlag-Scan uebersprungen, weil der letzte Vorschlag-Scan erst vor weniger als 60 Minuten lief.';
                $followUpMessages[] = trim($privateSuggestionScanMessage);
            } else {
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
                    $followUpMessages[] = trim($privateSuggestionScanMessage);
                } catch (TrackedPersonInstagramScanCancelledException $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    $privateSuggestionScanFailed = true;
                    $privateSuggestionScanMessage = ' Privates Profil erkannt; Vorschlag-Scan fehlgeschlagen: '.$exception->getMessage();
                    $followUpFailures[] = trim($privateSuggestionScanMessage);

                    ($trackedPerson->fresh() ?: $trackedPerson)->forceFill([
                        'last_instagram_status_level' => 'partial',
                        'last_instagram_status_message' => 'Instagram-Analyse abgeschlossen; Vorschlag-Scan fehlgeschlagen: '.$exception->getMessage(),
                    ])->save();
                }
            }
        }

        if (! $fullScan && $visibility === 'public') {
            foreach ([
                'followers_count' => ['relationship' => 'followers', 'label' => 'Followerliste'],
                'following_count' => ['relationship' => 'following', 'label' => 'Gefolgt-Liste'],
            ] as $field => $config) {
                if (! in_array($field, $metricFields, true)) {
                    continue;
                }

                try {
                    $relationshipScans[$config['relationship']] = $this->analysisService->scanRelationshipList(
                        $trackedPerson->fresh() ?: $trackedPerson,
                        $config['relationship'],
                        $progress,
                    );
                    $followUpMessages[] = $config['label'].' wurde wegen einer geaenderten Kennzahl aktualisiert.';
                } catch (TrackedPersonInstagramScanCancelledException $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    $followUpFailures[] = $config['label'].' konnte nicht aktualisiert werden: '.$exception->getMessage();
                }
            }

            if (in_array('posts_count', $metricFields, true)) {
                try {
                    $postScan = $this->runPostScan(
                        $trackedPerson->fresh() ?: $trackedPerson,
                        $snapshot,
                        $progress,
                    );
                    $followUpMessages[] = 'Beitragsscan: '
                        .number_format($postScan->observed_count, 0, ',', '.').' Beitraege geprueft, '
                        .number_format($postScan->new_count, 0, ',', '.').' neu und '
                        .number_format($postScan->updated_count, 0, ',', '.').' aktualisiert.';
                } catch (TrackedPersonInstagramScanCancelledException $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    $followUpFailures[] = 'Beitragsscan fehlgeschlagen: '.$exception->getMessage();
                }
            }
        }

        if ($fullScan && $visibility === 'public') {
            try {
                $postScan = $this->runPostScan(
                    $trackedPerson->fresh() ?: $trackedPerson,
                    $snapshot,
                    $progress,
                );
                $followUpMessages[] = 'Beitragsscan: '
                    .number_format($postScan->observed_count, 0, ',', '.').' Beitraege geprueft, '
                    .number_format($postScan->new_count, 0, ',', '.').' neu und '
                    .number_format($postScan->updated_count, 0, ',', '.').' aktualisiert.';
            } catch (TrackedPersonInstagramScanCancelledException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $followUpFailures[] = 'Beitragsscan fehlgeschlagen: '.$exception->getMessage();
            }
        }

        $resolvedStatusLevel = $snapshot->status_level === 'success'
            ? 'success'
            : (in_array($snapshot->status_level, ['partial', 'cancelled'], true)
                ? $snapshot->status_level
                : 'error');

        if (
            $privateSuggestionScanFailed
            || ($privateSuggestionScan && $privateSuggestionScan->status_level !== 'success')
            || ($postScan && $postScan->status_level !== 'success')
            || $followUpFailures !== []
        ) {
            $resolvedStatusLevel = 'partial';
        }

        return [
            'snapshot' => $snapshot,
            'privateSuggestionScan' => $privateSuggestionScan,
            'privateSuggestionScanFailed' => $privateSuggestionScanFailed,
            'privateSuggestionScanMessage' => $privateSuggestionScanMessage,
            'relationshipScans' => $relationshipScans,
            'postScan' => $postScan,
            'followUpFailures' => $followUpFailures,
            'resolvedStatusLevel' => $resolvedStatusLevel,
            'resolvedStatusMessage' => ($fullScan ? 'Instagram-Analyse' : 'Instagram-Mini-Scan')
                .' abgeschlossen: '
                .$snapshot->status_message
                .($followUpMessages !== [] ? ' '.implode(' ', $followUpMessages) : '')
                .($followUpFailures !== [] ? ' '.implode(' ', $followUpFailures) : ''),
        ];
    }

    public function runSuggestionScan(
        TrackedPerson $trackedPerson,
        ?callable $progress = null,
    ): TrackedPersonInstagramSuggestionScan {
        return $this->suggestionScanService->scan($trackedPerson, $progress);
    }

    public function runPostScan(
        TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSnapshot $snapshot = null,
        ?callable $progress = null,
    ): InstagramPostScan {
        return $this->postScanService->scan($trackedPerson, $snapshot, $progress);
    }

    private function changedFields(TrackedPersonInstagramSnapshot $snapshot): array
    {
        return collect($snapshot->detected_changes ?? [])
            ->filter(fn ($change): bool => is_array($change) && is_string($change['field'] ?? null))
            ->pluck('field')
            ->unique()
            ->values()
            ->all();
    }

    private function recentSuggestionScanWithinLastHour(TrackedPerson $trackedPerson): ?TrackedPersonInstagramSuggestionScan
    {
        return $trackedPerson->instagramSuggestionScans()
            ->where('analyzed_at', '>=', Carbon::now('UTC')->subHour())
            ->latest('analyzed_at')
            ->first();
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
