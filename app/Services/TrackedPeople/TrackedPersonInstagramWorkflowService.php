<?php

namespace App\Services\TrackedPeople;

use App\Exceptions\TrackedPersonInstagramScanCancelledException;
use App\Models\InstagramProfileListScan;
use App\Models\InstagramPostScan;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramSnapshot;
use App\Models\TrackedPersonInstagramSuggestionScan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TrackedPersonInstagramWorkflowService
{
    public function __construct(
        private readonly TrackedPersonInstagramSuggestionScanService $suggestionScanService,
        private readonly TrackedPersonInstagramAnalysisService $analysisService,
        private readonly TrackedPersonInstagramPostScanService $postScanService,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
    ) {}

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
        $preferences = $trackedPerson->instagramScanPreferences();
        $visibility = $this->snapshotProfileVisibility($snapshot);
        $changedFields = $this->changedFields(
            $snapshot,
            (int) $preferences['auto_scan_count_change_threshold'],
        );
        $metricFields = array_values(array_intersect($changedFields, [
            'followers_count',
            'following_count',
            'posts_count',
        ]));

        if (
            $visibility === 'private'
            && $preferences['auto_scan_suggestions']
            && (
                $this->shouldTriggerFollowUpForField($preferences, $metricFields !== [])
                || ($fullScan && $preferences['auto_scan_on_changes'])
                || $this->followUpScanIsDue($trackedPerson, 'suggestions', $preferences)
            )
        ) {
            $recentSuggestionScan = $this->recentSuggestionScanWithinPreferenceWindow($trackedPerson, $preferences);

            if ($recentSuggestionScan) {
                $privateSuggestionScanMessage = ' Privates Profil erkannt; Vorschlaege-Scan uebersprungen, weil der letzte Lauf noch innerhalb des Mindestabstands liegt.';
                $followUpMessages[] = trim($privateSuggestionScanMessage);
            } else {
                if ($progress) {
                    $progress([
                        'phase' => 'suggestions',
                        'percent' => 1,
                        'message' => 'Privates Profil erkannt; Vorschlaege-Scan wird gestartet.',
                        'foundSuggestions' => 0,
                        'suggestionConnections' => [],
                    ]);
                }

                try {
                    $privateSuggestionScan = $this->runSuggestionScan(
                        $trackedPerson->fresh() ?: $trackedPerson,
                        $progress,
                    );
                    $privateSuggestionScanMessage = ' Privates Profil erkannt; Vorschlaege-Scan abgeschlossen mit '
                        .number_format((int) $privateSuggestionScan->suggestions_observed_count, 0, ',', '.')
                        .' gefundenen Vorschlaegen.';
                    $followUpMessages[] = trim($privateSuggestionScanMessage);
                } catch (TrackedPersonInstagramScanCancelledException $exception) {
                    $privateSuggestionScanFailed = true;
                    $privateSuggestionScanMessage = ' Privates Profil erkannt; Vorschlaege-Folgescan wurde beendet. Der erfolgreiche Basisscan bleibt gespeichert.';
                    $followUpFailures[] = trim($privateSuggestionScanMessage);
                } catch (\Throwable $exception) {
                    $privateSuggestionScanFailed = true;
                    $privateSuggestionScanMessage = ' Privates Profil erkannt; Vorschlaege-Scan fehlgeschlagen: '.$exception->getMessage();
                    $followUpFailures[] = trim($privateSuggestionScanMessage);
                }
            }
        }

        if (! $fullScan && $visibility === 'public') {
            foreach ([
                'followers_count' => ['relationship' => 'followers', 'preference' => 'auto_scan_followers', 'label' => 'Followerliste'],
                'following_count' => ['relationship' => 'following', 'preference' => 'auto_scan_following', 'label' => 'Gefolgt-Liste'],
            ] as $field => $config) {
                if (
                    ! $preferences[$config['preference']]
                    || ! $this->shouldRunMetricFollowUp($trackedPerson, $config['relationship'], $field, $metricFields, $preferences)
                ) {
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
                    $followUpFailures[] = $config['label'].'-Folgescan wurde beendet. Der erfolgreiche Basisscan bleibt gespeichert.';
                } catch (\Throwable $exception) {
                    $followUpFailures[] = $config['label'].' konnte nicht aktualisiert werden: '.$exception->getMessage();
                }
            }

            if (
                $preferences['auto_scan_posts']
                && $this->shouldRunMetricFollowUp($trackedPerson, 'posts', 'posts_count', $metricFields, $preferences)
            ) {
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
                    $followUpFailures[] = 'Beitrags-Folgescan wurde beendet. Der erfolgreiche Basisscan bleibt gespeichert.';
                } catch (\Throwable $exception) {
                    $followUpFailures[] = 'Beitragsscan fehlgeschlagen: '.$exception->getMessage();
                }
            }
        }

        if (
            $fullScan
            && $visibility === 'public'
            && $preferences['auto_scan_posts']
            && (
                $preferences['auto_scan_on_changes']
                || $this->followUpScanIsDue($trackedPerson, 'posts', $preferences)
            )
        ) {
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
                $followUpFailures[] = 'Beitrags-Folgescan wurde beendet. Der erfolgreiche Basisscan bleibt gespeichert.';
            } catch (\Throwable $exception) {
                $followUpFailures[] = 'Beitragsscan fehlgeschlagen: '.$exception->getMessage();
            }
        }

        try {
            $this->restoreBaseScanResult($trackedPerson, $snapshot);
        } catch (\Throwable $exception) {
            $followUpFailures[] = 'Basisscan wurde gespeichert, konnte aber nicht an alle verbundenen Profile synchronisiert werden: '.$exception->getMessage();
            Log::warning('Gespeichertes Instagram-Basisergebnis konnte nach Folge-Scans nicht vollstaendig synchronisiert werden.', [
                'tracked_person_id' => $trackedPerson->id,
                'snapshot_id' => $snapshot->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $resolvedStatusLevel = $snapshot->status_level === 'success'
            ? 'success'
            : (in_array($snapshot->status_level, ['partial', 'cancelled'], true)
                ? $snapshot->status_level
                : 'error');

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
                .($followUpFailures !== [] ? ' Folgescans teilweise fehlgeschlagen; Basisscan wurde gespeichert. '.implode(' ', $followUpFailures) : ''),
        ];
    }

    public function runSuggestionScan(
        TrackedPerson $trackedPerson,
        ?callable $progress = null,
    ): TrackedPersonInstagramSuggestionScan {
        return $this->suggestionScanService->scan($trackedPerson, $progress);
    }

    public function runSuggestionDeepSearch(
        TrackedPerson $trackedPerson,
        ?callable $progress = null,
    ): TrackedPersonInstagramSuggestionScan {
        return $this->suggestionScanService->scanDeepSearch($trackedPerson, $progress);
    }

    public function runPostScan(
        TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSnapshot $snapshot = null,
        ?callable $progress = null,
    ): InstagramPostScan {
        return $this->postScanService->scan($trackedPerson, $snapshot, $progress);
    }

    private function restoreBaseScanResult(
        TrackedPerson $trackedPerson,
        TrackedPersonInstagramSnapshot $snapshot,
    ): void {
        $snapshot = $snapshot->fresh() ?: $snapshot;
        $trackedPerson = $trackedPerson->fresh() ?: $trackedPerson;
        $trackedPersonUpdates = [
            'last_instagram_status_level' => $snapshot->status_level,
            'last_instagram_status_message' => $snapshot->status_message,
            'last_instagram_analyzed_at' => $snapshot->analyzed_at,
        ];

        foreach ([
            'instagram_followers_count' => $snapshot->followers_count,
            'instagram_following_count' => $snapshot->following_count,
            'instagram_posts_count' => $snapshot->posts_count,
            'instagram_profile_image_path' => $snapshot->profile_image_path,
            'instagram_profile_image_hash' => $snapshot->profile_image_hash,
            'profile_image_path' => $snapshot->profile_image_path,
            'profile_image_hash' => $snapshot->profile_image_hash,
        ] as $field => $value) {
            if ($value !== null && $value !== '') {
                $trackedPersonUpdates[$field] = $value;
            }
        }

        $trackedPerson->forceFill($trackedPersonUpdates)->save();

        $profile = $this->profileRelationshipStore->ensureProfile(
            $snapshot->instagram_username ?: $trackedPerson->instagram_username,
            [
                'display_name' => $snapshot->full_name ?: $trackedPerson->display_name,
                'full_name' => $snapshot->full_name,
                'biography' => $snapshot->biography,
                'profile_image_url' => $snapshot->profile_image_url,
                'profile_image_path' => $snapshot->profile_image_path,
                'profile_image_hash' => $snapshot->profile_image_hash,
                'followers_count' => $snapshot->followers_count,
                'following_count' => $snapshot->following_count,
                'posts_count' => $snapshot->posts_count,
                'last_status_level' => $snapshot->status_level,
                'last_status_message' => $snapshot->status_message,
                'last_scanned_at' => $snapshot->analyzed_at,
            ],
        );

        if ($profile) {
            $this->profileRelationshipStore->propagateProfileDataToLinkedTrackedPeople($profile);
        }
    }

    private function changedFields(TrackedPersonInstagramSnapshot $snapshot, int $minimumCountDelta = 1): array
    {
        return collect($snapshot->detected_changes ?? [])
            ->filter(fn ($change): bool => is_array($change) && is_string($change['field'] ?? null))
            ->filter(function (array $change) use ($minimumCountDelta): bool {
                $field = (string) $change['field'];

                if (! in_array($field, ['followers_count', 'following_count', 'posts_count'], true)) {
                    return true;
                }

                if (! is_numeric($change['before'] ?? null) || ! is_numeric($change['after'] ?? null)) {
                    return true;
                }

                return abs((int) $change['after'] - (int) $change['before']) >= max(1, $minimumCountDelta);
            })
            ->pluck('field')
            ->unique()
            ->values()
            ->all();
    }

    private function shouldRunMetricFollowUp(
        TrackedPerson $trackedPerson,
        string $scanType,
        string $field,
        array $metricFields,
        array $preferences,
    ): bool {
        return (
            $this->shouldTriggerFollowUpForField($preferences, in_array($field, $metricFields, true))
            || $this->followUpScanIsDue($trackedPerson, $scanType, $preferences)
        )
            && ! $this->recentFollowUpScanWithinPreferenceWindow($trackedPerson, $scanType, $preferences);
    }

    private function shouldTriggerFollowUpForField(array $preferences, bool $fieldChanged): bool
    {
        return (bool) $preferences['auto_scan_on_changes'] && $fieldChanged;
    }

    private function followUpScanIsDue(TrackedPerson $trackedPerson, string $scanType, array $preferences): bool
    {
        if (! (bool) $preferences['auto_scan_on_interval']) {
            return false;
        }

        return ! $this->recentFollowUpScanWithinPreferenceWindow($trackedPerson, $scanType, $preferences);
    }

    private function recentSuggestionScanWithinPreferenceWindow(
        TrackedPerson $trackedPerson,
        array $preferences,
    ): ?TrackedPersonInstagramSuggestionScan {
        $minutes = (int) $preferences['auto_scan_min_interval_minutes'];

        if ($minutes <= 0) {
            return null;
        }

        return $trackedPerson->instagramSuggestionScans()
            ->where('analyzed_at', '>=', Carbon::now('UTC')->subMinutes($minutes))
            ->latest('analyzed_at')
            ->get()
            ->first(fn (TrackedPersonInstagramSuggestionScan $scan): bool => (
                data_get($scan->raw_payload, 'operationMode') !== 'suggestion-connections'
            ));
    }

    private function recentFollowUpScanWithinPreferenceWindow(
        TrackedPerson $trackedPerson,
        string $scanType,
        array $preferences,
    ): bool {
        $minutes = (int) $preferences['auto_scan_min_interval_minutes'];

        if ($minutes <= 0) {
            return false;
        }

        $threshold = Carbon::now('UTC')->subMinutes($minutes);

        return match ($scanType) {
            'followers', 'following' => InstagramProfileListScan::query()
                ->where('tracked_person_id', $trackedPerson->id)
                ->where('user_id', $trackedPerson->user_id)
                ->where('list_type', $scanType)
                ->where('scanned_at', '>=', $threshold)
                ->exists(),
            'posts' => $trackedPerson->instagramPostScans()
                ->where('scanned_at', '>=', $threshold)
                ->exists(),
            'suggestions' => $this->recentSuggestionScanWithinPreferenceWindow($trackedPerson, $preferences) !== null,
            default => false,
        };
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
