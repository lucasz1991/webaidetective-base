<?php

namespace App\Services\TrackedPeople;

use App\Models\InstagramProfile;
use App\Models\InstagramProfileRelationship;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramInferredConnection;
use App\Models\TrackedPersonInstagramSuggestionScan;
use App\Services\Billing\ScanCreditService;
use App\Services\Social\InstagramScraper;
use App\Services\Support\DatabaseKeepAlive;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class TrackedPersonInstagramSuggestionScanService
{
    private const SUGGESTION_CANDIDATE_RECHECK_HOURS = 48;

    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly TrackedPersonInstagramScanCoordinator $scanCoordinator,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
        private readonly ScanCreditService $scanCreditService,
        private readonly InstagramScanPolicyService $scanPolicies,
        private readonly InstagramScanEventStore $scanEvents,
    ) {}

    private ?array $activeScanControl = null;

    public function scan(
        TrackedPerson $trackedPerson,
        ?callable $progress = null,
        ?string $targetUsernameOverride = null,
    ): TrackedPersonInstagramSuggestionScan {
        return $this->runScan($trackedPerson, $progress, $targetUsernameOverride, false);
    }

    public function scanDeepSearch(
        TrackedPerson $trackedPerson,
        ?callable $progress = null,
        ?string $targetUsernameOverride = null,
    ): TrackedPersonInstagramSuggestionScan {
        return $this->runScan($trackedPerson, $progress, $targetUsernameOverride, true);
    }

    public function scanProfile(
        InstagramProfile $profile,
        int $userId,
        ?callable $progress = null,
    ): TrackedPersonInstagramSuggestionScan {
        return $this->runProfileScan($profile, $userId, $progress, false);
    }

    public function scanProfileDeepSearch(
        InstagramProfile $profile,
        int $userId,
        ?callable $progress = null,
    ): TrackedPersonInstagramSuggestionScan {
        return $this->runProfileScan($profile, $userId, $progress, true);
    }

    private function runScan(
        TrackedPerson $trackedPerson,
        ?callable $progress,
        ?string $targetUsernameOverride,
        bool $deepSearch,
    ): TrackedPersonInstagramSuggestionScan {
        $targetUsername = $this->scraper->normalizeInstagramUsername(
            $targetUsernameOverride ?: $trackedPerson->instagram_username,
        );

        if ($targetUsername === null) {
            throw new \RuntimeException('Fuer diese Person ist kein Instagram-Name hinterlegt.');
        }

        $scanControl = $this->scanCoordinator->begin(
            $trackedPerson->id,
            $deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan',
        );

        Cache::lock('tracked-person-instagram-suggestion-scan:'.$trackedPerson->id, 3600)->forceRelease();
        $lock = Cache::lock('tracked-person-instagram-suggestion-scan:'.$trackedPerson->id, 3600);

        if (! $lock->get()) {
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            throw new \RuntimeException('Fuer diese Person laeuft bereits ein Vorschlaege-Scan.');
        }

        $this->activeScanControl = $scanControl;

        try {
            return $this->scanWithLock(
                $trackedPerson,
                $this->profileRelationshipStore->syncTrackedPersonProfile($trackedPerson),
                (int) $trackedPerson->user_id,
                $targetUsername,
                $progress,
                $deepSearch,
            );
        } finally {
            $lock->release();
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            $this->activeScanControl = null;
        }
    }

    private function runProfileScan(
        InstagramProfile $profile,
        int $userId,
        ?callable $progress,
        bool $deepSearch,
    ): TrackedPersonInstagramSuggestionScan {
        $targetUsername = $this->scraper->normalizeInstagramUsername($profile->username);

        if ($targetUsername === null || $userId <= 0) {
            throw new \RuntimeException('Das Instagram-Profil kann nicht gescannt werden.');
        }

        $scanContextId = -1 * (int) $profile->id;
        $scanControl = $this->scanCoordinator->begin(
            $scanContextId,
            $deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan',
        );
        $lockKey = 'instagram-profile-suggestion-scan:'.$targetUsername;
        Cache::lock($lockKey, 3600)->forceRelease();
        $lock = Cache::lock($lockKey, 3600);

        if (! $lock->get()) {
            $this->scanCoordinator->finish($scanContextId, (int) $scanControl['generation']);
            throw new \RuntimeException('Fuer dieses Profil laeuft bereits ein Vorschlaege-Scan.');
        }

        $this->activeScanControl = $scanControl;

        try {
            return $this->scanWithLock(
                null,
                $profile,
                $userId,
                $targetUsername,
                $progress,
                $deepSearch,
            );
        } finally {
            $lock->release();
            $this->scanCoordinator->finish($scanContextId, (int) $scanControl['generation']);
            $this->activeScanControl = null;
        }
    }

    private function scanWithLock(
        ?TrackedPerson $trackedPerson,
        ?InstagramProfile $profile,
        int $userId,
        string $targetUsername,
        ?callable $progress = null,
        bool $deepSearch = false,
    ): TrackedPersonInstagramSuggestionScan {
        DatabaseKeepAlive::ping();

        $profile ??= $this->profileRelationshipStore->ensureProfile($targetUsername);
        $deepSearchPolicy = $this->scanPolicies->for('suggestion_deep_search');
        $skipPreviouslyChecked = (bool) ($deepSearchPolicy['skip_previously_checked'] ?? true);
        $noMatchSkipAfter = max(1, min(100, (int) ($deepSearchPolicy['no_match_skip_after'] ?? 2)));
        $candidateRecheckHours = self::SUGGESTION_CANDIDATE_RECHECK_HOURS;

        $liveConnections = [];
        $liveInferredFollowers = [];
        $liveInferredFollowing = [];
        $liveBranchedConnections = [];
        $liveObservedSuggestions = [];
        $liveSuggestionDebug = [
            'events' => [],
            'scrollEvents' => [],
            'finalUsernames' => [],
        ];

        // Neu: deduplizierende Sets fuer inkrementelles Speichern waehrend des Scans
        $persistedObservedUsernames = [];
        $persistedConnectionUsernames = [];
        $persistedSuggestionEdges = [];

        $this->reportProgress($progress, [
            'phase' => 'suggestions',
            'percent' => 1,
            'message' => ($deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan').' wird vorbereitet.',
            'foundSuggestions' => 0,
            'suggestionConnections' => [],
            'observedSuggestionCount' => 0,
            'observedSuggestions' => [],
        ]);

        $deepSearchSeedProfiles = $deepSearch
            ? $this->buildDeepSearchSeedProfiles($trackedPerson, $profile, $targetUsername)
            : [];

        // Resume support: detect last incomplete scan and build a pending list
        $lastScan = $deepSearch
            ? $this->lastIncompleteSuggestionDeepSearchScanForContext($trackedPerson, $profile)
            : null;
        $resumePendingOnly = false;
        $pendingCandidates = [];
        if ($lastScan) {
            [$resumePendingOnly, $pendingCandidates] = $this->buildResumePendingFromLastScan($lastScan);
        }

        $progressScan = $this->startProgressScan(
            $trackedPerson,
            $profile,
            $userId,
            $targetUsername,
            $deepSearch,
        );

        try {
            $payload = $this->scraper->scrape(
                $targetUsername,
                $deepSearch ? 'suggestion-connections' : 'suggestions',
                function (array $state) use (
                    $trackedPerson,
                    $progressScan,
                    $targetUsername,
                    $progress,
                    $userId,
                    &$liveConnections,
                    &$liveInferredFollowers,
                    &$liveInferredFollowing,
                    &$liveBranchedConnections,
                    &$liveObservedSuggestions,
                    &$liveSuggestionDebug,
                    &$persistedObservedUsernames,
                    &$persistedConnectionUsernames,
                    &$persistedSuggestionEdges,
                ): void {
                    DatabaseKeepAlive::ping(15);

                    try {
                        $this->scanEvents->progress(
                            'tracked_person_instagram_suggestion_scan',
                            $progressScan->fresh() ?: $progressScan,
                            $targetUsername,
                            $trackedPerson?->id,
                            $userId,
                            [
                                'phase' => 'suggestions',
                                ...$state,
                            ],
                        );
                    } catch (\Throwable) {
                        // Event-Logging darf den Vorschlaege-Scan nicht abbrechen.
                    }

                    // Suggestion-Connections (Matches) zusammenfuehren
                    if (array_key_exists('suggestionConnections', $state)) {
                        $liveConnections = $this->mergeSuggestionConnections(
                            $liveConnections,
                            $this->normalizeSuggestionConnections($state['suggestionConnections']),
                        );
                        $liveInferredFollowers = $this->mergeSuggestionConnections(
                            $liveInferredFollowers,
                            $this->suggestionPublicListConnectionsForRelationship($liveConnections, 'follows_target'),
                        );
                        $liveInferredFollowing = $this->mergeSuggestionConnections(
                            $liveInferredFollowing,
                            $this->suggestionPublicListConnectionsForRelationship($liveConnections, 'followed_by_target'),
                        );

                        // Inkrementell: Inferred Connections und inverse Public-Listen speichern
                        if ($trackedPerson && $liveConnections !== []) {
                            try {
                                // Nur neue Kandidaten seit letztem Persist-Durchlauf speichern
                                $newConnections = array_values(array_filter($liveConnections, function (array $c) use (&$persistedConnectionUsernames): bool {
                                    $u = $this->scraper->normalizeInstagramUsername($c['username'] ?? null);
                                    if ($u === null) {
                                        return false;
                                    }
                                    if (isset($persistedConnectionUsernames[$u])) {
                                        return false;
                                    }
                                    $persistedConnectionUsernames[$u] = true;

                                    return true;
                                }));

                                if ($newConnections !== []) {
                                    $now = now('UTC');
                                    $this->storeInferredSuggestionConnections(
                                        $trackedPerson,
                                        null,
                                        $targetUsername,
                                        $newConnections,
                                        ['progress' => true],
                                        $now,
                                    );
                                    $this->storeInferredPublicListConnections(
                                        $trackedPerson,
                                        null,
                                        $targetUsername,
                                        $newConnections,
                                        $now,
                                    );
                                    // inverse Public-Listen (Followers/Following) sofort als Preview anlegen
                                    $this->storePublicListRelationships(
                                        $trackedPerson,
                                        null,
                                        $targetUsername,
                                        $newConnections,
                                        $now,
                                    );
                                }
                            } catch (\Throwable) {
                                // Fehler beim Live-Speichern duerfen den Scan nicht abbrechen
                            }
                        }
                    }

                    if (array_key_exists('suggestionBranchedConnections', $state)) {
                        $newBranches = $this->normalizeSuggestionBranchedConnections($state['suggestionBranchedConnections']);
                        $liveBranchedConnections = $this->mergeSuggestionBranchedConnections(
                            $liveBranchedConnections,
                            $newBranches,
                        );

                        if ($newBranches !== []) {
                            try {
                                $now = now('UTC');

                                foreach ($newBranches as $branch) {
                                    $this->storeSuggestionRelationshipItems(
                                        $trackedPerson,
                                        null,
                                        $branch['sourceUsername'],
                                        $branch['suggestionPreview'],
                                        $now,
                                        [
                                            'source' => 'suggestion_deepsearch_level2_live',
                                            'edge_source' => 'profile_suggestions_level2',
                                            'target_username' => $targetUsername,
                                            'via' => $branch['via'] ?? 'profile_suggestions_level2',
                                        ],
                                        $persistedSuggestionEdges,
                                        [
                                            'display_name' => $this->nullableTrim($branch['sourceDisplayName'] ?? null),
                                            'profile_url' => $this->nullableTrim($branch['sourceProfileUrl'] ?? null),
                                        ],
                                    );
                                }
                            } catch (\Throwable) {
                                // Live-Speichern der Stufe-2-Vorschlaege ist best-effort.
                            }
                        }
                    }

                    // Beobachtete Vorschlaege zusammenfuehren
                    if (array_key_exists('observedSuggestions', $state)) {
                        $liveObservedSuggestions = $this->mergeObservedSuggestionItems(
                            $liveObservedSuggestions,
                            $this->normalizeObservedSuggestionItems($state['observedSuggestions']),
                        );

                        // Inkrementell: neu beobachtete Vorschlaege sofort als Profile anlegen/aktualisieren
                        if ($liveObservedSuggestions !== []) {
                            try {
                                $newObserved = [];
                                foreach ($liveObservedSuggestions as $item) {
                                    $u = $this->scraper->normalizeInstagramUsername($item['username'] ?? null);
                                    if ($u === null || isset($persistedObservedUsernames[$u])) {
                                        continue;
                                    }
                                    $persistedObservedUsernames[$u] = true;
                                    $newObserved[] = $item;
                                }

                                if ($newObserved !== []) {
                                    $this->storeObservedSuggestionProfiles(
                                        $trackedPerson,
                                        [
                                            'suggestionScan' => [
                                                'observedSuggestions' => $newObserved,
                                            ],
                                        ],
                                        now('UTC'),
                                    );
                                    $this->storeSuggestionRelationshipItems(
                                        $trackedPerson,
                                        null,
                                        $targetUsername,
                                        $newObserved,
                                        now('UTC'),
                                        [
                                            'source' => 'suggestion_scan_observed_live',
                                            'edge_source' => 'target_profile_suggestions',
                                        ],
                                        $persistedSuggestionEdges,
                                    );
                                }
                            } catch (\Throwable) {
                                // Live-Speichern der beobachteten Vorschlaege ist best-effort
                            }
                        }

                        $liveSuggestionDebug['finalUsernames'] = collect($liveObservedSuggestions)
                            ->pluck('username')
                            ->filter()
                            ->take(120)
                            ->values()
                            ->all();
                    }

                    // Debug-Events einsammeln
                    if (is_array($state['suggestionCollectionDebug'] ?? null)) {
                        $debugEvent = $state['suggestionCollectionDebug'];
                        if (is_scalar($state['liveScreenshotUrl'] ?? null)) {
                            $debugEvent['liveScreenshotUrl'] = (string) $state['liveScreenshotUrl'];
                        }
                        if (is_array($debugEvent['surfaceBeforeCollection'] ?? null)) {
                            $liveSuggestionDebug['surfaceBeforeCollection'] = $debugEvent['surfaceBeforeCollection'];
                        }
                        $targetKey = ($debugEvent['type'] ?? null) === 'scroll' ? 'scrollEvents' : 'events';
                        $liveSuggestionDebug[$targetKey][] = $debugEvent;
                        $liveSuggestionDebug[$targetKey] = array_slice($liveSuggestionDebug[$targetKey], -80);
                    }

                    $observedSuggestionCount = max(
                        count($liveObservedSuggestions),
                        $this->countBranchedSuggestionItems($liveBranchedConnections),
                        (int) ($state['observedSuggestionCount'] ?? 0),
                    );

                    $this->reportProgress($progress, [
                        ...$state,
                        'phase' => 'suggestions',
                        'foundSuggestions' => count($liveConnections),
                        'inferredFollowers' => $liveInferredFollowers,
                        'inferredFollowing' => $liveInferredFollowing,
                        'suggestionConnections' => $liveConnections,
                        'observedSuggestionCount' => $observedSuggestionCount,
                        'observedSuggestions' => $liveObservedSuggestions,
                        'suggestionBranchedConnections' => $liveBranchedConnections,
                    ]);
                },
                $this->withActiveScanControl([
                    'suggestionDebug' => true,
                    // Vorschlagskandidaten werden per Username-History erst nach Ablauf der Recheck-Frist erneut geprueft.
                    ...($skipPreviouslyChecked
                        ? ['suggestionCandidateHistory' => $this->buildSuggestionCandidateHistory($trackedPerson, $profile, $noMatchSkipAfter, $candidateRecheckHours)]
                        : []),
                    'suggestionCandidateRecheckHours' => $candidateRecheckHours,
                    ...($deepSearch ? ['suggestionDeepSearchSeedProfiles' => $deepSearchSeedProfiles] : []),
                    // Resume-only: if last scan incomplete, pass pending list and a hint to prefer resume
                    ...($pendingCandidates !== [] ? [
                        'suggestionPendingCandidates' => $pendingCandidates,
                        'suggestionResumePendingOnly' => $resumePendingOnly,
                    ] : []),
                ]),
            );
        } catch (\Throwable $exception) {
            $scanLabel = $deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan';
            $errorMessage = $exception->getMessage();
            $observedCount = max(
                count($liveObservedSuggestions),
                $this->countBranchedSuggestionItems($liveBranchedConnections),
            );
            $hasPartialData = $observedCount > 0 || count($liveConnections) > 0;
            $rateLimited = str_contains($errorMessage, '429');
            $statusLevel = ($hasPartialData || $rateLimited) ? 'partial' : 'error';
            $message = $statusLevel === 'partial'
                ? $scanLabel.' wurde unterbrochen; bereits erkannte Vorschlaege wurden gespeichert.'
                    .($observedCount > 0 ? ' Sichtbar erkannt: '.number_format($observedCount, 0, ',', '.').'.' : '')
                : $scanLabel.' fehlgeschlagen: '.$errorMessage;
            $payload = [
                'ok' => false,
                'operationMode' => $deepSearch ? 'suggestion-connections' : 'suggestions',
                'statusLevel' => $statusLevel,
                'statusMessage' => $message,
                'error' => $errorMessage,
                'suggestionConnections' => $liveConnections,
                'suggestionScan' => [
                    'ok' => false,
                    'statusLevel' => $statusLevel,
                    'statusMessage' => $message,
                    'targetUsername' => $targetUsername,
                    'attempted' => true,
                    'available' => $observedCount > 0,
                    'observedCount' => $observedCount,
                    'checkedCount' => 0,
                    'matchCount' => count($liveConnections),
                    'rateLimited' => $rateLimited,
                    'rateLimitText' => $rateLimited ? $errorMessage : null,
                    'observedSuggestions' => $liveObservedSuggestions,
                    'matches' => $liveConnections,
                    'inferredFollowers' => $liveInferredFollowers,
                    'inferredFollowing' => $liveInferredFollowing,
                    'suggestionBranchedConnections' => $liveBranchedConnections,
                    'targetCollectionDebug' => [
                        ...$liveSuggestionDebug,
                        'error' => $errorMessage,
                        'interruptedWithPartialData' => $hasPartialData,
                    ],
                ],
            ];

            $this->reportProgress($progress, [
                'phase' => 'suggestions',
                'percent' => 100,
                'message' => $message,
                'foundSuggestions' => count($liveConnections),
                'inferredFollowers' => $liveInferredFollowers,
                'inferredFollowing' => $liveInferredFollowing,
                'suggestionConnections' => $liveConnections,
                'observedSuggestionCount' => max(count($liveObservedSuggestions), $this->countBranchedSuggestionItems($liveBranchedConnections)),
                'observedSuggestions' => $liveObservedSuggestions,
                'suggestionBranchedConnections' => $liveBranchedConnections,
            ]);

            if ($statusLevel === 'error') {
                $this->markProgressScanFailed($progressScan, $targetUsername, $trackedPerson?->id, $userId, $message, $exception);
            }
        }

        return $this->storeScan(
            $trackedPerson,
            $profile,
            $userId,
            $targetUsername,
            $payload,
            $liveConnections,
            $deepSearch,
            $progressScan,
        );
    }

    private function startProgressScan(
        ?TrackedPerson $trackedPerson,
        ?InstagramProfile $profile,
        int $userId,
        string $targetUsername,
        bool $deepSearch,
    ): TrackedPersonInstagramSuggestionScan {
        $now = now('UTC');
        $scan = TrackedPersonInstagramSuggestionScan::create([
            'tracked_person_id' => $trackedPerson?->id,
            'instagram_profile_id' => $profile?->id,
            'user_id' => $userId,
            'target_username' => $targetUsername,
            'status_level' => 'partial',
            'status_message' => ($deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan').' laeuft; Fortschritt wird gespeichert.',
            'suggestions_observed_count' => 0,
            'suggestions_checked_count' => 0,
            'suggestion_matches_count' => 0,
            'gracefully_stopped' => false,
            'raw_payload' => [
                'ok' => false,
                'progressStatus' => 'in_progress',
                'operationMode' => $deepSearch ? 'suggestion-connections' : 'suggestions',
                'targetUsername' => $targetUsername,
                'startedAt' => $now->toIso8601String(),
            ],
            'analyzed_at' => $now,
        ]);

        $this->scanEvents->started(
            'tracked_person_instagram_suggestion_scan',
            $scan,
            $targetUsername,
            $trackedPerson?->id,
            $userId,
            ($deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan').' @'.$targetUsername.' wurde gestartet.',
            [
                'phase' => 'suggestions',
                'percent' => 0,
            ],
        );

        return $scan;
    }

    private function markProgressScanFailed(
        TrackedPersonInstagramSuggestionScan $scan,
        string $targetUsername,
        ?int $trackedPersonId,
        int $userId,
        string $message,
        \Throwable $exception,
    ): void {
        $payload = is_array($scan->raw_payload) ? $scan->raw_payload : [];

        $scan->forceFill([
            'status_level' => 'error',
            'status_message' => $message,
            'raw_payload' => [
                ...$payload,
                'ok' => false,
                'progressStatus' => 'failed',
                'error' => $exception->getMessage(),
                'failedAt' => now('UTC')->toIso8601String(),
            ],
            'analyzed_at' => now('UTC'),
        ])->save();

        $this->scanEvents->failed(
            'tracked_person_instagram_suggestion_scan',
            $scan,
            $targetUsername,
            $trackedPersonId,
            $userId,
            $message,
            [
                'phase' => 'suggestions',
                'statusLevel' => 'error',
            ],
        );
    }

    private function storeScan(
        ?TrackedPerson $trackedPerson,
        ?InstagramProfile $profile,
        int $userId,
        string $targetUsername,
        array $payload,
        array $liveConnections,
        bool $deepSearch,
        ?TrackedPersonInstagramSuggestionScan $existingScan = null,
    ): TrackedPersonInstagramSuggestionScan {
        $this->assertActiveScanCurrent();
        DatabaseKeepAlive::ping(0);

        $payload = $this->normalizePayloadScreenshotPaths($payload);
        $scanPayload = $this->suggestionPayload($payload);
        $connections = $this->mergeSuggestionConnections(
            $liveConnections,
            $this->normalizeSuggestionConnections($payload['suggestionConnections'] ?? []),
            $this->normalizeSuggestionConnections($scanPayload['matches'] ?? []),
        );
        $inferredFollowersFromSuggestions = $this->suggestionPublicListConnectionsForRelationship($connections, 'follows_target');
        $inferredFollowingFromSuggestions = $this->suggestionPublicListConnectionsForRelationship($connections, 'followed_by_target');

        if ($inferredFollowersFromSuggestions !== []) {
            $payload['inferredFollowers'] = $this->mergeSuggestionConnections(
                $this->normalizeSuggestionConnections($payload['inferredFollowers'] ?? []),
                $inferredFollowersFromSuggestions,
            );
            $payload['suggestionScan'] = is_array($payload['suggestionScan'] ?? null) ? $payload['suggestionScan'] : [];
            $payload['suggestionScan']['inferredFollowers'] = $payload['inferredFollowers'];
        }

        if ($inferredFollowingFromSuggestions !== []) {
            $payload['inferredFollowing'] = $this->mergeSuggestionConnections(
                $this->normalizeSuggestionConnections($payload['inferredFollowing'] ?? []),
                $inferredFollowingFromSuggestions,
            );
            $payload['suggestionScan'] = is_array($payload['suggestionScan'] ?? null) ? $payload['suggestionScan'] : [];
            $payload['suggestionScan']['inferredFollowing'] = $payload['inferredFollowing'];
        }
        $analyzedAt = now('UTC');

        $scan = DatabaseKeepAlive::transaction(function () use (
            $trackedPerson,
            $profile,
            $userId,
            $targetUsername,
            $payload,
            $scanPayload,
            $connections,
            $analyzedAt,
            $deepSearch,
            $existingScan,
        ): TrackedPersonInstagramSuggestionScan {
            $scanData = [
                'tracked_person_id' => $trackedPerson?->id,
                'instagram_profile_id' => $profile?->id,
                'user_id' => $userId,
                'target_username' => $targetUsername,
                'status_level' => (string) ($payload['statusLevel'] ?? $scanPayload['statusLevel'] ?? 'unknown'),
                'status_message' => (string) ($payload['statusMessage'] ?? $scanPayload['statusMessage'] ?? (
                    $deepSearch ? 'Vorschlaege DeepSearch abgeschlossen.' : 'Vorschlaege-Scan abgeschlossen.'
                )),
                'suggestions_observed_count' => (int) ($scanPayload['observedCount'] ?? count($scanPayload['suggestions'] ?? [])),
                'suggestions_checked_count' => (int) ($scanPayload['checkedCount'] ?? 0),
                'suggestion_matches_count' => count($connections),
                'gracefully_stopped' => (bool) ($payload['gracefullyStopped'] ?? $scanPayload['gracefullyStopped'] ?? false),
                'raw_payload' => $payload,
                'analyzed_at' => $analyzedAt,
            ];

            $scan = $existingScan ?: new TrackedPersonInstagramSuggestionScan;
            $scan->forceFill($scanData)->save();

            // Endgueltig alle beobachteten Kandidaten dieses Laufs nochmals sicherstellen
            $this->storeObservedSuggestionProfiles($trackedPerson, $payload, $analyzedAt);
            $this->storeSuggestionRelationshipsFromPayload(
                $trackedPerson,
                $scan,
                $profile,
                $targetUsername,
                $payload,
                $analyzedAt,
            );
            if ($connections !== []) {
                if ($trackedPerson) {
                    $this->storeInferredSuggestionConnections(
                        $trackedPerson,
                        $scan,
                        $targetUsername,
                        $connections,
                        $payload,
                        $analyzedAt,
                    );
                    $this->storeInferredPublicListConnections(
                        $trackedPerson,
                        $scan,
                        $targetUsername,
                        $connections,
                        $analyzedAt,
                    );
                }
                $this->storePublicListRelationships(
                    $trackedPerson,
                    $scan,
                    $targetUsername,
                    $connections,
                    $analyzedAt,
                );
            }

            return $scan;
        });

        $this->scanCreditService->charge(
            $userId,
            $scan,
            $payload,
            'Instagram-'.($deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan').' @'.$targetUsername,
        );

        $this->scanEvents->finished(
            'tracked_person_instagram_suggestion_scan',
            $scan,
            $targetUsername,
            $trackedPerson?->id,
            $userId,
            $scan->status_message ?: (($deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan').' abgeschlossen.'),
            [
                'phase' => 'suggestions',
                'statusLevel' => $scan->status_level,
                'percent' => 100,
                'foundSuggestions' => $scan->suggestions_observed_count,
            ],
        );

        return $scan;
    }

    private function storeInferredSuggestionConnections(
        TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSuggestionScan $scan,
        string $targetUsername,
        array $connections,
        array $payload,
        Carbon $seenAt,
    ): void {
        if ($connections === []) {
            return;
        }

        $this->profileRelationshipStore->syncTrackedPersonProfile($trackedPerson);

        foreach ($connections as $connection) {
            $candidateUsername = $this->scraper->normalizeInstagramUsername((string) ($connection['username'] ?? ''));

            if ($candidateUsername === null) {
                continue;
            }

            if (! (bool) ($connection['targetFoundAsSuggestion'] ?? false)) {
                continue;
            }

            $sourceUsername = $this->scraper->normalizeInstagramUsername(
                (string) ($connection['sourceSuggestionUsername'] ?? $connection['sourcePublicUsername'] ?? $candidateUsername),
            ) ?? $candidateUsername;
            $candidateProfile = $this->profileRelationshipStore->ensureProfile($candidateUsername, [
                'display_name' => $this->nullableTrim($connection['displayName'] ?? null),
                'profile_url' => $this->nullableTrim($connection['profileUrl'] ?? null),
                'profile_image_url' => $this->nullableTrim($connection['profileImageUrl'] ?? $connection['profile_image_url'] ?? null),
                'is_private' => $this->normalizeSuggestionProfileIsPrivate($connection),
                'profile_visibility' => $this->normalizeSuggestionProfileVisibility($connection),
                'posts_count' => is_numeric($connection['postsCount'] ?? null) ? (int) $connection['postsCount'] : null,
                'followers_count' => is_numeric($connection['followersCount'] ?? null) ? (int) $connection['followersCount'] : null,
                'following_count' => is_numeric($connection['followingCount'] ?? null) ? (int) $connection['followingCount'] : null,
                'last_scanned_at' => $seenAt,
                'raw_profile' => [
                    'source' => 'suggestion_scan_match',
                    'tracked_person_id' => $trackedPerson->id,
                    'suggestion_scan_id' => $scan?->id,
                    'source_public_username' => $sourceUsername,
                ],
            ]);
            $existing = TrackedPersonInstagramInferredConnection::query()
                ->where('tracked_person_id', $trackedPerson->id)
                ->whereNull('public_profile_id')
                ->where('relationship_type', 'suggestion_connection')
                ->where('candidate_username', $candidateUsername)
                ->first();

            TrackedPersonInstagramInferredConnection::updateOrCreate(
                [
                    'tracked_person_id' => $trackedPerson->id,
                    'public_profile_id' => null,
                    'relationship_type' => 'suggestion_connection',
                    'candidate_username' => $candidateUsername,
                ],
                [
                    'scan_id' => null,
                    'user_id' => $trackedPerson->user_id,
                    'source_public_username' => $sourceUsername,
                    'candidate_display_name' => $this->nullableTrim($connection['displayName'] ?? null),
                    'candidate_profile_url' => $this->nullableTrim($connection['profileUrl'] ?? null)
                        ?: 'https://www.instagram.com/'.$candidateUsername.'/',
                    'source_lists' => $connection['sourceLists'] ?? ['profile_suggestions'],
                    'evidence' => [
                        ...$connection,
                        'targetUsername' => $targetUsername,
                        'suggestionScanId' => $scan?->id,
                        'rawScanStatus' => [
                            'statusLevel' => $payload['statusLevel'] ?? null,
                            'statusMessage' => $payload['statusMessage'] ?? null,
                        ],
                    ],
                    'status' => 'active',
                    'first_seen_at' => $existing?->first_seen_at ?: $seenAt,
                    'last_seen_at' => $seenAt,
                    ...$this->profileColumnData($candidateProfile?->id, $candidateProfile?->id),
                ],
            );
        }
    }

    private function storeInferredPublicListConnections(
        TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSuggestionScan $scan,
        string $targetUsername,
        array $connections,
        Carbon $seenAt,
    ): void {
        $targetUsername = $this->scraper->normalizeInstagramUsername($targetUsername) ?? $targetUsername;

        foreach ([
            'follows_target' => $this->suggestionPublicListConnectionsForRelationship($connections, 'follows_target'),
            'followed_by_target' => $this->suggestionPublicListConnectionsForRelationship($connections, 'followed_by_target'),
        ] as $relationshipType => $relationshipConnections) {
            foreach ($relationshipConnections as $connection) {
                $candidateUsername = $this->scraper->normalizeInstagramUsername((string) ($connection['username'] ?? ''));

                if ($candidateUsername === null) {
                    continue;
                }

                $candidateProfile = $this->profileRelationshipStore->ensureProfile($candidateUsername, [
                    'display_name' => $this->nullableTrim($connection['displayName'] ?? null),
                    'profile_url' => $this->nullableTrim($connection['profileUrl'] ?? null),
                    'profile_image_url' => $this->nullableTrim($connection['profileImageUrl'] ?? $connection['profile_image_url'] ?? null),
                    'is_private' => $this->normalizeSuggestionProfileIsPrivate($connection),
                    'profile_visibility' => $this->normalizeSuggestionProfileVisibility($connection),
                    'posts_count' => is_numeric($connection['postsCount'] ?? null) ? (int) $connection['postsCount'] : null,
                    'followers_count' => is_numeric($connection['followersCount'] ?? null) ? (int) $connection['followersCount'] : null,
                    'following_count' => is_numeric($connection['followingCount'] ?? null) ? (int) $connection['followingCount'] : null,
                    'last_scanned_at' => $seenAt,
                    'raw_profile' => [
                        'source' => 'suggestion_public_list_match',
                        'tracked_person_id' => $trackedPerson->id,
                        'suggestion_scan_id' => $scan?->id,
                        'target_username' => $targetUsername,
                    ],
                ]);
                $existing = TrackedPersonInstagramInferredConnection::query()
                    ->where('tracked_person_id', $trackedPerson->id)
                    ->whereNull('public_profile_id')
                    ->where('relationship_type', $relationshipType)
                    ->where('candidate_username', $candidateUsername)
                    ->first();

                TrackedPersonInstagramInferredConnection::updateOrCreate(
                    [
                        'tracked_person_id' => $trackedPerson->id,
                        'public_profile_id' => null,
                        'relationship_type' => $relationshipType,
                        'candidate_username' => $candidateUsername,
                    ],
                    [
                        'scan_id' => null,
                        'user_id' => $trackedPerson->user_id,
                        'source_public_username' => $candidateUsername,
                        'candidate_display_name' => $this->nullableTrim($connection['displayName'] ?? null),
                        'candidate_profile_url' => $this->nullableTrim($connection['profileUrl'] ?? null)
                            ?: 'https://www.instagram.com/'.$candidateUsername.'/',
                        'source_lists' => is_array($connection['sourceLists'] ?? null)
                            ? array_values(array_unique([
                                ...$connection['sourceLists'],
                                'suggestion_scan_public_lists',
                            ]))
                            : ['suggestion_scan_public_lists'],
                        'evidence' => [
                            ...$connection,
                            'targetUsername' => $targetUsername,
                            'suggestionScanId' => $scan?->id,
                            'fromSuggestionScan' => true,
                            'relationship_origin' => 'public_lists_from_suggestion_scan',
                            'relationship_origin_label' => 'Aus Vorschlaege-Scan ueber oeffentliche Liste bestaetigt',
                            'relationship_type' => $relationshipType,
                        ],
                        'status' => 'active',
                        'first_seen_at' => $existing?->first_seen_at ?: $seenAt,
                        'last_seen_at' => $seenAt,
                        ...$this->profileColumnData($candidateProfile?->id, $candidateProfile?->id),
                    ],
                );
            }
        }
    }

    private function suggestionPublicListConnectionsForRelationship(array $connections, string $relationshipType): array
    {
        $filtered = [];

        foreach ($this->normalizeSuggestionConnections($connections) as $connection) {
            $matchesRelationship = match ($relationshipType) {
                'follows_target' => (bool) ($connection['targetFoundInFollowing'] ?? false),
                'followed_by_target' => (bool) ($connection['targetFoundInFollowers'] ?? false),
                default => false,
            };

            if (! $matchesRelationship) {
                continue;
            }

            $filtered[] = [
                ...$connection,
                'fromSuggestionScan' => true,
                'relationshipOriginLabel' => 'Aus Vorschlaege-Scan',
                'relationshipType' => $relationshipType,
                'sourceLists' => array_values(array_unique([
                    ...($connection['sourceLists'] ?? []),
                    'suggestion_scan_public_lists',
                ])),
            ];
        }

        return $filtered;
    }

    private function storeObservedSuggestionProfiles(
        ?TrackedPerson $trackedPerson,
        array $payload,
        Carbon $seenAt,
    ): void {
        $scanPayload = $this->suggestionPayload($payload);
        $candidates = $this->mergeObservedSuggestionItems(
            $this->normalizeObservedSuggestionItems($scanPayload['observedSuggestions'] ?? []),
            $this->normalizeObservedSuggestionItems($scanPayload['checkedCandidates'] ?? []),
            $this->normalizeObservedSuggestionItems($scanPayload['matches'] ?? []),
            $this->normalizeObservedSuggestionItems($payload['suggestionConnections'] ?? []),
        );

        foreach ($candidates as $candidate) {
            $username = $this->scraper->normalizeInstagramUsername((string) ($candidate['username'] ?? ''));

            if ($username === null) {
                continue;
            }

            $this->profileRelationshipStore->ensureProfile($username, [
                'display_name' => $this->nullableTrim($candidate['displayName'] ?? null),
                'profile_url' => $this->nullableTrim($candidate['profileUrl'] ?? null),
                'profile_image_url' => $this->nullableTrim($candidate['profileImageUrl'] ?? $candidate['profile_image_url'] ?? null),
                'is_private' => $this->normalizeSuggestionProfileIsPrivate($candidate),
                'profile_visibility' => $this->normalizeSuggestionProfileVisibility($candidate),
                'posts_count' => is_numeric($candidate['postsCount'] ?? null) ? (int) $candidate['postsCount'] : null,
                'followers_count' => is_numeric($candidate['followersCount'] ?? null) ? (int) $candidate['followersCount'] : null,
                'following_count' => is_numeric($candidate['followingCount'] ?? null) ? (int) $candidate['followingCount'] : null,
                'last_scanned_at' => $seenAt,
                'raw_profile' => [
                    'source' => 'suggestion_scan_observed',
                    'tracked_person_id' => $trackedPerson?->id,
                    'matched' => (bool) ($candidate['matched'] ?? false),
                    'checked' => (bool) ($candidate['checked'] ?? false),
                    'skipped_reason' => $this->nullableTrim($candidate['skippedReason'] ?? null),
                ],
            ]);
        }
    }

    private function storeSuggestionRelationshipsFromPayload(
        ?TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSuggestionScan $scan,
        ?InstagramProfile $targetProfile,
        string $targetUsername,
        array $payload,
        Carbon $seenAt,
    ): void {
        $scanPayload = $this->suggestionPayload($payload);
        $persistedEdges = [];
        $targetProfile ??= $this->profileRelationshipStore->ensureProfile($targetUsername);
        $initialSuggestions = $this->mergeObservedSuggestionItems(
            $this->directTargetSuggestionItems($this->normalizeObservedSuggestionItems($scanPayload['observedSuggestions'] ?? [])),
            $this->directTargetSuggestionItems($this->normalizeObservedSuggestionItems($scanPayload['suggestions'] ?? [])),
            $this->directTargetSuggestionItems($this->normalizeObservedSuggestionItems($scanPayload['candidatesToCheck'] ?? [])),
            $this->directTargetSuggestionItems($this->normalizeObservedSuggestionItems($scanPayload['skippedCandidates'] ?? [])),
            $this->directTargetSuggestionItems($this->normalizeObservedSuggestionItems($scanPayload['checkedCandidates'] ?? [])),
            $this->directTargetSuggestionItems($this->normalizeObservedSuggestionItems($scanPayload['matches'] ?? [])),
        );

        if ($targetProfile) {
            $this->storeSuggestionRelationshipItems(
                $trackedPerson,
                $scan,
                $targetProfile->username,
                $initialSuggestions,
                $seenAt,
                [
                    'source' => 'suggestion_scan_observed',
                    'suggestion_scan_id' => $scan?->id,
                    'edge_source' => 'target_profile_suggestions',
                    'target_username' => $targetUsername,
                ],
                $persistedEdges,
            );
        }

        foreach ($this->suggestionPreviewBranches($scanPayload) as $branch) {
            $this->storeSuggestionRelationshipItems(
                $trackedPerson,
                $scan,
                $branch['sourceUsername'],
                $branch['items'],
                $seenAt,
                [
                    'source' => 'suggestion_scan_candidate_preview',
                    'suggestion_scan_id' => $scan?->id,
                    'edge_source' => $branch['edgeSource'],
                    'target_username' => $targetUsername,
                ],
                $persistedEdges,
                $branch['sourceAttributes'],
            );
        }
    }

    private function normalizeSuggestionBranchedConnections(mixed $branches): array
    {
        if (! is_array($branches)) {
            return [];
        }

        $normalized = [];

        foreach ($branches as $branch) {
            if (! is_array($branch) || ! is_scalar($branch['sourceUsername'] ?? null)) {
                continue;
            }

            $sourceUsername = $this->scraper->normalizeInstagramUsername((string) $branch['sourceUsername']);

            if ($sourceUsername === null) {
                continue;
            }

            $normalized[$sourceUsername] = [
                'sourceUsername' => $sourceUsername,
                'sourceProfileUrl' => $this->nullableTrim($branch['sourceProfileUrl'] ?? null)
                    ?: 'https://www.instagram.com/'.$sourceUsername.'/',
                'sourceDisplayName' => $this->nullableTrim($branch['sourceDisplayName'] ?? null),
                'targetUsername' => $this->scraper->normalizeInstagramUsername((string) ($branch['targetUsername'] ?? '')) ?: null,
                'via' => $this->nullableTrim($branch['via'] ?? null) ?: 'profile_suggestions_level2',
                'suggestionsObserved' => is_numeric($branch['suggestionsObserved'] ?? null) ? (int) $branch['suggestionsObserved'] : 0,
                'suggestionsAvailable' => (bool) ($branch['suggestionsAvailable'] ?? false),
                'suggestionsRateLimited' => (bool) ($branch['suggestionsRateLimited'] ?? false),
                'suggestionPreview' => $this->normalizeObservedSuggestionItems($branch['suggestionPreview'] ?? []),
            ];
        }

        return array_values($normalized);
    }

    private function mergeSuggestionBranchedConnections(array ...$branchGroups): array
    {
        $merged = [];

        foreach ($branchGroups as $branches) {
            foreach ($this->normalizeSuggestionBranchedConnections($branches) as $branch) {
                $sourceUsername = $branch['sourceUsername'];

                $merged[$sourceUsername] = [
                    ...($merged[$sourceUsername] ?? []),
                    ...$branch,
                    'suggestionPreview' => $this->mergeObservedSuggestionItems(
                        $merged[$sourceUsername]['suggestionPreview'] ?? [],
                        $branch['suggestionPreview'],
                    ),
                    'suggestionsObserved' => max(
                        (int) ($merged[$sourceUsername]['suggestionsObserved'] ?? 0),
                        (int) ($branch['suggestionsObserved'] ?? count($branch['suggestionPreview'])),
                        count($branch['suggestionPreview']),
                    ),
                ];
            }
        }

        return array_values($merged);
    }

    private function countBranchedSuggestionItems(array $branches): int
    {
        return collect($this->normalizeSuggestionBranchedConnections($branches))
            ->sum(fn (array $branch): int => count($branch['suggestionPreview'] ?? []));
    }

    private function directTargetSuggestionItems(array $items): array
    {
        return array_values(array_filter($items, function (array $item): bool {
            if ((bool) ($item['deepSearchBranch'] ?? false)) {
                return false;
            }

            if ((int) ($item['suggestionLevel'] ?? 0) >= 2) {
                return false;
            }

            if (in_array((string) ($item['checkMode'] ?? ''), ['profile-suggestions-level2', 'deepsearch-branch-history'], true)) {
                return false;
            }

            $sourceLists = is_array($item['sourceLists'] ?? null) ? $item['sourceLists'] : [];

            return ! in_array('profile_suggestions_level2', $sourceLists, true);
        }));
    }

    private function storeSuggestionRelationshipItems(
        ?TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSuggestionScan $scan,
        string $sourceUsername,
        array $items,
        Carbon $seenAt,
        array $evidence,
        array &$persistedEdges = [],
        array $sourceAttributes = [],
    ): void {
        $sourceUsername = $this->scraper->normalizeInstagramUsername($sourceUsername);

        if ($sourceUsername === null) {
            return;
        }

        $sourceProfile = $this->profileRelationshipStore->ensureProfile($sourceUsername, $sourceAttributes);

        if (! $sourceProfile) {
            return;
        }

        $filteredItems = [];

        foreach ($this->normalizeObservedSuggestionItems($items) as $item) {
            $username = $this->scraper->normalizeInstagramUsername($item['username'] ?? null);

            if ($username === null || $username === $sourceUsername) {
                continue;
            }

            $edgeKey = $sourceUsername.'>'.$username;

            if (isset($persistedEdges[$edgeKey])) {
                continue;
            }

            $persistedEdges[$edgeKey] = true;
            $filteredItems[] = $item;
        }

        if ($filteredItems === []) {
            return;
        }

        $this->profileRelationshipStore->syncObservedRelationshipPreview(
            $sourceProfile,
            $trackedPerson,
            'profile_suggestions',
            $filteredItems,
            $seenAt,
            [
                'suggestion_scan_id' => $scan?->id,
                'source_username' => $sourceUsername,
                ...$evidence,
            ],
        );
    }

    /**
     * @return array<int, array{sourceUsername:string, sourceAttributes:array<string, mixed>, items:array<int, array<string, mixed>>, edgeSource:string}>
     */
    private function suggestionPreviewBranches(array $scanPayload): array
    {
        $branches = [];
        $groups = [
            'checked_candidates' => $scanPayload['checkedCandidates'] ?? [],
            'matches' => $scanPayload['matches'] ?? [],
        ];

        foreach ($groups as $edgeSource => $items) {
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $sourceUsername = $this->scraper->normalizeInstagramUsername($item['username'] ?? null);
                $preview = $this->normalizeObservedSuggestionItems($item['suggestionPreview'] ?? []);

                if ($sourceUsername === null || $preview === []) {
                    continue;
                }

                $branches[] = [
                    'sourceUsername' => $sourceUsername,
                    'sourceAttributes' => $this->suggestionSourceAttributes($item),
                    'items' => $preview,
                    'edgeSource' => $edgeSource,
                ];
            }
        }

        $branchedConnections = $scanPayload['suggestionBranchedConnections'] ?? [];

        if (is_array($branchedConnections)) {
            foreach ($branchedConnections as $branch) {
                if (! is_array($branch)) {
                    continue;
                }

                $sourceUsername = $this->scraper->normalizeInstagramUsername($branch['sourceUsername'] ?? null);
                $preview = $this->normalizeObservedSuggestionItems($branch['suggestionPreview'] ?? []);

                if ($sourceUsername === null || $preview === []) {
                    continue;
                }

                $branches[] = [
                    'sourceUsername' => $sourceUsername,
                    'sourceAttributes' => [
                        'display_name' => $this->nullableTrim($branch['sourceDisplayName'] ?? null),
                        'profile_url' => $this->nullableTrim($branch['sourceProfileUrl'] ?? null),
                    ],
                    'items' => $preview,
                    'edgeSource' => 'suggestion_branches',
                ];
            }
        }

        return $branches;
    }

    private function suggestionSourceAttributes(array $item): array
    {
        return array_filter([
            'display_name' => $this->nullableTrim($item['displayName'] ?? null),
            'profile_url' => $this->nullableTrim($item['profileUrl'] ?? null),
            'profile_image_url' => $this->nullableTrim($item['profileImageUrl'] ?? $item['profile_image_url'] ?? null),
            'is_private' => $this->normalizeSuggestionProfileIsPrivate($item),
            'profile_visibility' => $this->normalizeSuggestionProfileVisibility($item),
            'posts_count' => is_numeric($item['postsCount'] ?? null) ? (int) $item['postsCount'] : null,
            'followers_count' => is_numeric($item['followersCount'] ?? null) ? (int) $item['followersCount'] : null,
            'following_count' => is_numeric($item['followingCount'] ?? null) ? (int) $item['followingCount'] : null,
        ], static fn ($value): bool => $value !== null);
    }

    private function storePublicListRelationships(
        ?TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSuggestionScan $scan,
        string $targetUsername,
        array $connections,
        Carbon $seenAt,
    ): void {
        $targetProfile = $this->profileRelationshipStore->ensureProfile($targetUsername);

        if (! $targetProfile) {
            return;
        }

        foreach ($connections as $connection) {
            $candidateUsername = $this->scraper->normalizeInstagramUsername((string) ($connection['username'] ?? ''));

            if ($candidateUsername === null) {
                continue;
            }

            $candidateItem = [[
                'username' => $candidateUsername,
                'displayName' => $this->nullableTrim($connection['displayName'] ?? null),
                'profileUrl' => $this->nullableTrim($connection['profileUrl'] ?? null)
                    ?: 'https://www.instagram.com/'.$candidateUsername.'/',
                'profileImageUrl' => $this->nullableTrim($connection['profileImageUrl'] ?? $connection['profile_image_url'] ?? null),
                'profileVisibility' => $this->normalizeSuggestionProfileVisibility($connection),
                'isPrivate' => $this->normalizeSuggestionProfileIsPrivate($connection),
                'postsCount' => is_numeric($connection['postsCount'] ?? null) ? (int) $connection['postsCount'] : null,
                'followersCount' => is_numeric($connection['followersCount'] ?? null) ? (int) $connection['followersCount'] : null,
                'followingCount' => is_numeric($connection['followingCount'] ?? null) ? (int) $connection['followingCount'] : null,
            ]];
            $evidence = [
                'source' => 'suggestion_public_list_inverse',
                'suggestion_scan_id' => $scan?->id,
                'candidate_username' => $candidateUsername,
                'reconstructed' => true,
                'relationship_origin' => 'reconstructed_from_public_suggestion_scan',
                'relationship_origin_label' => 'Rekonstruierte Follower-/Gefolgt-Beziehung aus Vorschlaege-Scan',
                'public_list_search' => is_array($connection['publicListSearch'] ?? null)
                    ? $connection['publicListSearch']
                    : [],
            ];

            if ((bool) ($connection['targetFoundInFollowing'] ?? false)) {
                $this->profileRelationshipStore->syncObservedRelationshipPreview(
                    $targetProfile,
                    $trackedPerson,
                    'followers',
                    $candidateItem,
                    $seenAt,
                    [
                        ...$evidence,
                        'inferred_from' => 'candidate_following_contains_target',
                    ],
                );
            }

            if ((bool) ($connection['targetFoundInFollowers'] ?? false)) {
                $this->profileRelationshipStore->syncObservedRelationshipPreview(
                    $targetProfile,
                    $trackedPerson,
                    'following',
                    $candidateItem,
                    $seenAt,
                    [
                        ...$evidence,
                        'inferred_from' => 'candidate_followers_contains_target',
                    ],
                );
            }
        }
    }

    private function suggestionPayload(array $payload): array
    {
        $suggestionPayload = $payload['suggestionScan'] ?? data_get($payload, 'profile.suggestionScan', []);

        return is_array($suggestionPayload) ? $suggestionPayload : [];
    }

    private function lastIncompleteSuggestionDeepSearchScanForContext(?TrackedPerson $trackedPerson, ?InstagramProfile $profile): ?TrackedPersonInstagramSuggestionScan
    {
        $query = TrackedPersonInstagramSuggestionScan::query()
            ->latest('analyzed_at')
            ->limit(20);

        if ($trackedPerson) {
            $query->where('tracked_person_id', $trackedPerson->id);
        } elseif ($profile) {
            $query->where('instagram_profile_id', $profile->id);
        } else {
            return null;
        }

        return $query->get()->first(function (TrackedPersonInstagramSuggestionScan $scan): bool {
            $payload = is_array($scan->raw_payload) ? $scan->raw_payload : [];
            $scanPayload = $this->suggestionPayload($payload);

            if (! $this->isSuggestionDeepSearchPayload($payload)) {
                return false;
            }

            return (bool) ($scan->gracefully_stopped ?? false)
                || in_array((string) ($scan->status_level ?? ''), ['partial', 'error'], true)
                || (bool) ($payload['gracefullyStopped'] ?? $scanPayload['gracefullyStopped'] ?? false)
                || (bool) ($payload['rateLimited'] ?? $scanPayload['rateLimited'] ?? false);
        });
    }

    private function isSuggestionDeepSearchPayload(array $payload): bool
    {
        $scanPayload = $this->suggestionPayload($payload);
        $scanType = (string) ($scanPayload['scanType'] ?? $payload['scanType'] ?? '');

        return $scanType === 'suggestion-deepsearch'
            || array_key_exists('suggestionBranchedConnections', $scanPayload)
            || array_key_exists('suggestionBranchedConnections', $payload);
    }

    private function buildDeepSearchSeedProfiles(?TrackedPerson $trackedPerson, ?InstagramProfile $profile, string $targetUsername): array
    {
        $seeds = [];
        $targetProfile = $profile ?: $this->profileRelationshipStore->ensureProfile($targetUsername);
        $relationshipListTypes = ['followers', 'following', 'profile_suggestions'];
        $profileColumns = 'id,username,display_name,profile_url,profile_image_url,is_private,profile_visibility,posts_count,followers_count,following_count,last_scanned_at';
        $addSeed = function (mixed $rawUsername, array $attributes = [], array|string|null $sourceLists = null) use (&$seeds): void {
            $username = $this->scraper->normalizeInstagramUsername(is_scalar($rawUsername) ? (string) $rawUsername : null);

            if ($username === null) {
                return;
            }

            $incomingSourceLists = is_array($sourceLists)
                ? $sourceLists
                : (is_string($sourceLists) && $sourceLists !== '' ? [$sourceLists] : []);
            $previousSourceLists = is_array($seeds[$username]['sourceLists'] ?? null)
                ? $seeds[$username]['sourceLists']
                : [];

            $seeds[$username] = [
                ...($seeds[$username] ?? []),
                ...array_filter($attributes, static fn ($value): bool => $value !== null && $value !== ''),
                'username' => $username,
                'profileUrl' => $this->nullableTrim($attributes['profileUrl'] ?? null)
                    ?: ($seeds[$username]['profileUrl'] ?? 'https://www.instagram.com/'.$username.'/'),
                'sourceLists' => array_values(array_unique(array_filter([
                    ...$previousSourceLists,
                    ...$incomingSourceLists,
                ], static fn ($value): bool => is_string($value) && $value !== ''))),
            ];
        };
        $profileAttributes = function (?InstagramProfile $profile, ?InstagramProfileRelationship $relationship = null): array {
            return [
                'displayName' => $this->nullableTrim($profile?->display_name ?? $relationship?->display_name_snapshot ?? null),
                'profileUrl' => $this->nullableTrim($profile?->profile_url ?? $relationship?->profile_url_snapshot ?? null),
                'profileImageUrl' => $this->nullableTrim($profile?->profile_image_url ?? null),
                'profileVisibility' => $profile?->profile_visibility,
                'isPrivate' => is_bool($profile?->is_private) ? $profile->is_private : null,
                'postsCount' => is_numeric($profile?->posts_count) ? (int) $profile->posts_count : null,
                'followersCount' => is_numeric($profile?->followers_count) ? (int) $profile->followers_count : null,
                'followingCount' => is_numeric($profile?->following_count) ? (int) $profile->following_count : null,
                'lastScannedAt' => $profile?->last_scanned_at?->toIso8601String(),
            ];
        };

        if ($targetProfile) {
            InstagramProfileRelationship::query()
                ->with('relatedInstagramProfile:'.$profileColumns)
                ->where('source_instagram_profile_id', $targetProfile->id)
                ->whereIn('list_type', $relationshipListTypes)
                ->where('status', 'active')
                ->latest('last_seen_at')
                ->limit(5000)
                ->get(['id', 'related_instagram_profile_id', 'list_type', 'display_name_snapshot', 'profile_url_snapshot', 'last_seen_at'])
                ->each(function (InstagramProfileRelationship $relationship) use ($addSeed, $profileAttributes): void {
                    $relatedProfile = $relationship->relatedInstagramProfile;

                    $addSeed(
                        $relatedProfile?->username,
                        $profileAttributes($relatedProfile, $relationship),
                        [$relationship->list_type, 'target_direct_'.$relationship->list_type],
                    );
                });

            InstagramProfileRelationship::query()
                ->with('sourceInstagramProfile:'.$profileColumns)
                ->where('related_instagram_profile_id', $targetProfile->id)
                ->whereIn('list_type', $relationshipListTypes)
                ->where('status', 'active')
                ->latest('last_seen_at')
                ->limit(5000)
                ->get(['id', 'source_instagram_profile_id', 'list_type', 'display_name_snapshot', 'profile_url_snapshot', 'last_seen_at'])
                ->each(function (InstagramProfileRelationship $relationship) use ($addSeed, $profileAttributes): void {
                    $sourceProfile = $relationship->sourceInstagramProfile;

                    $addSeed(
                        $sourceProfile?->username,
                        $profileAttributes($sourceProfile, $relationship),
                        [$relationship->list_type, 'inverse_'.$relationship->list_type],
                    );
                });
        }

        if ($trackedPerson) {
            TrackedPersonInstagramInferredConnection::query()
                ->with('candidateInstagramProfile:'.$profileColumns)
                ->where('tracked_person_id', $trackedPerson->id)
                ->whereIn('relationship_type', ['suggestion_connection', 'follows_target', 'followed_by_target'])
                ->where('status', 'active')
                ->latest('last_seen_at')
                ->limit(5000)
                ->get([
                    'candidate_username',
                    'candidate_instagram_profile_id',
                    'candidate_display_name',
                    'candidate_profile_url',
                    'relationship_type',
                    'source_lists',
                    'last_seen_at',
                ])
                ->each(function (TrackedPersonInstagramInferredConnection $connection) use ($addSeed, $profileAttributes): void {
                    $candidateProfile = $connection->candidateInstagramProfile;
                    $sourceLists = is_array($connection->source_lists) ? $connection->source_lists : [];

                    $addSeed(
                        $candidateProfile?->username ?: $connection->candidate_username,
                        [
                            ...$profileAttributes($candidateProfile),
                            'displayName' => $this->nullableTrim($candidateProfile?->display_name ?? $connection->candidate_display_name ?? null),
                            'profileUrl' => $this->nullableTrim($candidateProfile?->profile_url ?? $connection->candidate_profile_url ?? null),
                            'lastInferredAt' => $connection->last_seen_at?->toIso8601String(),
                        ],
                        [
                            ...$sourceLists,
                            'inferred_'.$connection->relationship_type,
                        ],
                    );
                });
        }

        $scanQuery = TrackedPersonInstagramSuggestionScan::query()
            ->latest('analyzed_at')
            ->limit(25);

        if ($trackedPerson) {
            $scanQuery->where('tracked_person_id', $trackedPerson->id);
        } elseif ($profile) {
            $scanQuery->where('instagram_profile_id', $profile->id);
        }

        $scanQuery->get()->each(function (TrackedPersonInstagramSuggestionScan $scan) use ($addSeed): void {
            $payload = is_array($scan->raw_payload) ? $scan->raw_payload : [];

            if ($this->isSuggestionDeepSearchPayload($payload)) {
                return;
            }

            $scanPayload = $this->suggestionPayload($payload);

            foreach ([
                $scanPayload['observedSuggestions'] ?? [],
                $scanPayload['suggestions'] ?? [],
                $scanPayload['candidatesToCheck'] ?? [],
                $scanPayload['checkedCandidates'] ?? [],
                $scanPayload['matches'] ?? [],
                $payload['suggestionConnections'] ?? [],
            ] as $items) {
                foreach ($this->normalizeObservedSuggestionItems($items) as $item) {
                    $addSeed($item['username'] ?? null, $item, ['previous_suggestion_scan']);
                }
            }
        });

        return array_values($seeds);
    }

    private function buildResumePendingFromLastScan(TrackedPersonInstagramSuggestionScan $lastScan): array
    {
        $payload = is_array($lastScan->raw_payload) ? $lastScan->raw_payload : [];

        if (filled(data_get($payload, 'resumeDismissedAt'))) {
            return [false, []];
        }

        $scanPayload = $this->suggestionPayload($payload);
        $observed = $this->normalizeObservedSuggestionItems($scanPayload['observedSuggestions'] ?? []);
        $retryableReasons = ['candidate-error', 'candidate-navigation-error', 'candidate-http-429'];
        $pendingByUsername = [];
        $completedByUsername = [];

        foreach ($scanPayload['checkedCandidates'] ?? [] as $candidate) {
            if (! is_array($candidate) || ! is_scalar($candidate['username'] ?? null)) {
                continue;
            }

            $username = $this->scraper->normalizeInstagramUsername((string) $candidate['username']);

            if ($username === null) {
                continue;
            }

            $skipped = (bool) ($candidate['skipped'] ?? false);
            $skippedReason = is_scalar($candidate['skippedReason'] ?? null) ? (string) $candidate['skippedReason'] : null;
            $isRetryable = $skipped && in_array($skippedReason, $retryableReasons, true);

            if ((bool) ($candidate['checked'] ?? false) || ($skipped && ! $isRetryable)) {
                $completedByUsername[$username] = true;
            }
        }

        $candidateQueue = $this->normalizeObservedSuggestionItems($scanPayload['candidatesToCheck'] ?? []);

        if ($candidateQueue !== []) {
            foreach ($candidateQueue as $candidate) {
                $username = $this->scraper->normalizeInstagramUsername($candidate['username'] ?? null);

                if ($username === null || isset($completedByUsername[$username])) {
                    continue;
                }

                $pendingByUsername[$username] = [
                    'username' => $username,
                    'profileUrl' => $this->nullableTrim($candidate['profileUrl'] ?? null) ?: 'https://www.instagram.com/'.$username.'/',
                ];
            }

            return [$pendingByUsername !== [], array_values($pendingByUsername)];
        }

        // Fallback fuer aeltere Payloads ohne candidatesToCheck: beobachtete, aber nicht erledigte Vorschlaege fortsetzen.
        $observedCount = (int) ($scanPayload['observedCount'] ?? count($observed));

        foreach ($observed as $item) {
            $username = $this->scraper->normalizeInstagramUsername($item['username'] ?? null);

            if ($username === null || isset($completedByUsername[$username])) {
                continue;
            }

            $checkedFlag = (bool) ($item['checked'] ?? false);
            $skippedReason = is_scalar($item['skippedReason'] ?? null) ? (string) $item['skippedReason'] : null;

            // Exclude already-saved matches or permanently dismissed non-matches
            if (in_array($skippedReason, ['already-saved-match', 'already-dismissed-no-match', 'already-known-suggestion', 'recently-checked-suggestion'], true)) {
                continue;
            }

            if (! $checkedFlag) {
                $pendingByUsername[$username] = [
                    'username' => $username,
                    'profileUrl' => $this->nullableTrim($item['profileUrl'] ?? null) ?: 'https://www.instagram.com/'.$username.'/',
                ];
            }
        }

        foreach ($scanPayload['checkedCandidates'] ?? [] as $candidate) {
            if (! is_array($candidate) || ! is_scalar($candidate['username'] ?? null)) {
                continue;
            }

            $username = $this->scraper->normalizeInstagramUsername((string) $candidate['username']);
            $skippedReason = is_scalar($candidate['skippedReason'] ?? null) ? (string) $candidate['skippedReason'] : null;

            if ($username === null || isset($completedByUsername[$username])) {
                continue;
            }

            if ((bool) ($candidate['skipped'] ?? false) && in_array($skippedReason, $retryableReasons, true)) {
                $pendingByUsername[$username] = [
                    'username' => $username,
                    'profileUrl' => $this->nullableTrim($candidate['profileUrl'] ?? null) ?: 'https://www.instagram.com/'.$username.'/',
                ];
            }
        }

        $pending = array_values($pendingByUsername);

        return [$observedCount > 0 && $pending !== [], $pending];
    }

    private function buildSuggestionCandidateHistory(
        ?TrackedPerson $trackedPerson,
        ?InstagramProfile $profile,
        int $noMatchSkipAfter,
        int $recheckHours = self::SUGGESTION_CANDIDATE_RECHECK_HOURS,
    ): array {
        $history = [];
        $recheckHours = max(1, $recheckHours);
        $recheckCutoff = now('UTC')->subHours($recheckHours);

        $normalizeUsername = function (mixed $rawUsername): ?string {
            return $this->scraper->normalizeInstagramUsername(is_scalar($rawUsername) ? (string) $rawUsername : null);
        };
        $touchHistory = function (mixed $rawUsername, array $attributes = []) use (&$history, $normalizeUsername): ?string {
            $username = $normalizeUsername($rawUsername);

            if ($username === null) {
                return null;
            }

            $history[$username] = [
                ...($history[$username] ?? []),
                'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                'permanentlyDismissed' => (bool) ($history[$username]['permanentlyDismissed'] ?? false),
                ...$attributes,
            ];

            return $username;
        };
        $markChecked = function (mixed $rawUsername, mixed $checkedAt = null) use (&$history, $touchHistory, $recheckHours, $recheckCutoff): ?string {
            $username = $touchHistory($rawUsername);

            if ($username === null) {
                return null;
            }

            $checkedAt = $this->parseSuggestionHistoryTimestamp($checkedAt) ?: now('UTC');
            $previousCheckedAt = $this->parseSuggestionHistoryTimestamp($history[$username]['lastCheckedAt'] ?? null);

            if ($previousCheckedAt === null || $checkedAt->greaterThan($previousCheckedAt)) {
                $history[$username]['lastCheckedAt'] = $checkedAt->toIso8601String();
                $history[$username]['recheckAfter'] = $checkedAt->copy()->addHours($recheckHours)->toIso8601String();
                $history[$username]['recentlyChecked'] = $checkedAt->greaterThanOrEqualTo($recheckCutoff);
            } else {
                $history[$username]['recentlyChecked'] = (bool) ($history[$username]['recentlyChecked'] ?? false)
                    || $previousCheckedAt->greaterThanOrEqualTo($recheckCutoff);
            }

            return $username;
        };
        $markSuggestionProfileScanned = function (mixed $rawUsername, mixed $scannedAt = null) use (&$history, $touchHistory, $recheckHours, $recheckCutoff): ?string {
            $username = $touchHistory($rawUsername);

            if ($username === null) {
                return null;
            }

            $scannedAt = $this->parseSuggestionHistoryTimestamp($scannedAt) ?: now('UTC');
            $previousScannedAt = $this->parseSuggestionHistoryTimestamp($history[$username]['lastSuggestionProfileScanAt'] ?? null);

            if ($previousScannedAt === null || $scannedAt->greaterThan($previousScannedAt)) {
                $history[$username]['lastSuggestionProfileScanAt'] = $scannedAt->toIso8601String();
                $history[$username]['suggestionProfileRecheckAfter'] = $scannedAt->copy()->addHours($recheckHours)->toIso8601String();
                $history[$username]['recentlySuggestionProfileScanned'] = $scannedAt->greaterThanOrEqualTo($recheckCutoff);
            } else {
                $history[$username]['recentlySuggestionProfileScanned'] = (bool) ($history[$username]['recentlySuggestionProfileScanned'] ?? false)
                    || $previousScannedAt->greaterThanOrEqualTo($recheckCutoff);
            }

            return $username;
        };

        if ($trackedPerson) {
            TrackedPersonInstagramInferredConnection::query()
                ->where('tracked_person_id', $trackedPerson->id)
                ->whereIn('relationship_type', ['suggestion_connection', 'follows_target', 'followed_by_target'])
                ->where('status', 'active')
                ->get(['candidate_username', 'relationship_type', 'last_seen_at'])
                ->each(function (TrackedPersonInstagramInferredConnection $connection) use (&$history, $markChecked): void {
                    $username = $markChecked($connection->candidate_username, $connection->last_seen_at);

                    if ($username === null) {
                        return;
                    }

                    $history[$username] = [
                        ...($history[$username] ?? []),
                        'hasMatch' => true,
                        'knownSuggestion' => (bool) (($history[$username]['knownSuggestion'] ?? false) || $connection->relationship_type === 'suggestion_connection'),
                        'knownProfile' => true,
                        'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                        'permanentlyDismissed' => false,
                    ];
                });
        }

        $profile ??= $trackedPerson
            ? $this->profileRelationshipStore->syncTrackedPersonProfile($trackedPerson)
            : null;

        if ($profile) {
            InstagramProfileRelationship::query()
                ->with('relatedInstagramProfile:id,username')
                ->where('source_instagram_profile_id', $profile->id)
                ->where('status', 'active')
                ->get(['id', 'related_instagram_profile_id', 'list_type'])
                ->each(function (InstagramProfileRelationship $relationship) use (&$history, $touchHistory): void {
                    $username = $touchHistory($relationship->relatedInstagramProfile?->username);

                    if ($username === null) {
                        return;
                    }

                    $history[$username] = [
                        ...($history[$username] ?? []),
                        'knownProfile' => true,
                        'knownSuggestion' => (bool) (($history[$username]['knownSuggestion'] ?? false) || $relationship->list_type === 'profile_suggestions'),
                        'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                        'permanentlyDismissed' => (bool) ($history[$username]['permanentlyDismissed'] ?? false),
                    ];
                });
        }

        $scanQuery = TrackedPersonInstagramSuggestionScan::query()
            ->latest('analyzed_at')
            ->limit(100);

        if ($trackedPerson) {
            $scanQuery->where('tracked_person_id', $trackedPerson->id);
        } elseif ($profile) {
            $scanQuery->where('instagram_profile_id', $profile->id);
        } else {
            return $history;
        }

        $scanQuery->get()
            ->each(function (TrackedPersonInstagramSuggestionScan $scan) use (&$history, $noMatchSkipAfter, $markChecked, $markSuggestionProfileScanned, $touchHistory, $recheckCutoff): void {
                $payload = is_array($scan->raw_payload) ? $scan->raw_payload : [];
                $scanPayload = $this->suggestionPayload($payload);
                $scanCheckedAt = $scan->analyzed_at ?: $scan->created_at;

                foreach ([
                    $scanPayload['observedSuggestions'] ?? [],
                    $scanPayload['suggestions'] ?? [],
                    $scanPayload['candidatesToCheck'] ?? [],
                    $scanPayload['skippedCandidates'] ?? [],
                ] as $items) {
                    foreach ($this->normalizeObservedSuggestionItems($items) as $item) {
                        $username = $touchHistory($item['username'] ?? null);

                        if ($username === null) {
                            continue;
                        }

                        $history[$username] = [
                            ...($history[$username] ?? []),
                            'knownSuggestion' => true,
                            'knownProfile' => true,
                            'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                            'permanentlyDismissed' => (bool) ($history[$username]['permanentlyDismissed'] ?? false),
                        ];

                        if ((bool) ($item['recentlyChecked'] ?? false) || filled($item['lastCheckedAt'] ?? null)) {
                            $markChecked($username, $item['lastCheckedAt'] ?? $scanCheckedAt);
                        }

                        if ((bool) ($item['recentlySuggestionProfileScanned'] ?? false) || filled($item['lastSuggestionProfileScanAt'] ?? null)) {
                            $markSuggestionProfileScanned($username, $item['lastSuggestionProfileScanAt'] ?? $scanCheckedAt);
                        }
                    }
                }

                foreach ($this->normalizeSuggestionConnections($payload['suggestionConnections'] ?? []) as $connection) {
                    $username = $markChecked($connection['username'] ?? null, $scanCheckedAt);

                    if ($username === null) {
                        continue;
                    }

                    $history[$username] = [
                        ...($history[$username] ?? []),
                        'hasMatch' => true,
                        'knownSuggestion' => true,
                        'knownProfile' => true,
                        'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                        'permanentlyDismissed' => false,
                    ];
                }

                foreach ($this->normalizeSuggestionConnections($scanPayload['matches'] ?? []) as $connection) {
                    $username = $markChecked($connection['username'] ?? null, $scanCheckedAt);

                    if ($username === null) {
                        continue;
                    }

                    $history[$username] = [
                        ...($history[$username] ?? []),
                        'hasMatch' => true,
                        'knownSuggestion' => true,
                        'knownProfile' => true,
                        'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                        'permanentlyDismissed' => false,
                    ];
                }

                foreach ($scanPayload['checkedCandidates'] ?? [] as $candidate) {
                    if (! is_array($candidate)) {
                        continue;
                    }

                    $rawUsername = $candidate['username'] ?? null;

                    if (! is_scalar($rawUsername)) {
                        continue;
                    }

                    $username = $this->scraper->normalizeInstagramUsername((string) $rawUsername);

                    if ($username === null || (bool) ($history[$username]['hasMatch'] ?? false)) {
                        continue;
                    }

                    $checkMode = is_scalar($candidate['checkMode'] ?? null)
                        ? (string) $candidate['checkMode']
                        : 'profile-suggestions';

                    if ($checkMode === 'profile-suggestions-level2') {
                        $markSuggestionProfileScanned($username, $scanCheckedAt);
                        $history[$username] = [
                            ...($history[$username] ?? []),
                            'knownProfile' => true,
                            'knownSuggestion' => (bool) ($history[$username]['knownSuggestion'] ?? false),
                            'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                            'permanentlyDismissed' => (bool) ($history[$username]['permanentlyDismissed'] ?? false),
                        ];

                        continue;
                    }

                    $markChecked($username, $scanCheckedAt);

                    $hasCandidateMatch = (bool) ($candidate['targetFoundAsSuggestion'] ?? false)
                        || (bool) ($candidate['targetFoundInPublicLists'] ?? false)
                        || (bool) ($candidate['targetFoundInFollowers'] ?? false)
                        || (bool) ($candidate['targetFoundInFollowing'] ?? false);

                    if ($hasCandidateMatch) {
                        $history[$username] = [
                            ...($history[$username] ?? []),
                            'hasMatch' => true,
                            'knownSuggestion' => true,
                            'knownProfile' => true,
                            'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                            'permanentlyDismissed' => false,
                        ];

                        continue;
                    }

                    $wasDefinitiveNoMatch = (bool) ($candidate['checked'] ?? false)
                        && ! filled($candidate['error'] ?? null)
                        && (
                            $checkMode === 'public-lists'
                                ? (bool) ($candidate['publicListSearchConclusive'] ?? false)
                                    && ! (bool) ($candidate['publicListRateLimited'] ?? false)
                                : (bool) ($candidate['suggestionsAvailable'] ?? false)
                                    && ! (bool) ($candidate['suggestionsRateLimited'] ?? false)
                        );

                    if (! $wasDefinitiveNoMatch) {
                        continue;
                    }

                    $noMatchChecks = (int) ($history[$username]['noMatchChecks'] ?? 0) + 1;
                    $recentlyChecked = $this->parseSuggestionHistoryTimestamp($history[$username]['lastCheckedAt'] ?? null)
                        ?->greaterThanOrEqualTo($recheckCutoff) ?? false;
                    $history[$username] = [
                        ...($history[$username] ?? []),
                        'hasMatch' => false,
                        'noMatchChecks' => $noMatchChecks,
                        'recentlyChecked' => $recentlyChecked,
                        'permanentlyDismissed' => $recentlyChecked && ($noMatchChecks >= $noMatchSkipAfter
                            || (
                                $noMatchSkipAfter <= 2
                                && (bool) ($candidate['finalDismissedAfterSecondMiss'] ?? false)
                            )),
                    ];
                }
            });

        return $history;
    }

    private function parseSuggestionHistoryTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->timezone('UTC');
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->timezone('UTC');
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->timezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeSuggestionConnections(mixed $connections): array
    {
        if (! is_array($connections)) {
            return [];
        }

        $normalized = [];

        foreach ($connections as $connection) {
            if (! is_array($connection) || ! is_scalar($connection['username'] ?? null)) {
                continue;
            }

            $username = $this->scraper->normalizeInstagramUsername((string) $connection['username']);

            if ($username === null) {
                continue;
            }

            $sourceUsername = $this->scraper->normalizeInstagramUsername(
                (string) ($connection['sourceSuggestionUsername'] ?? $connection['sourcePublicUsername'] ?? $username),
            ) ?? $username;
            $sourceLists = $this->normalizeSourceLists($connection, (bool) ($connection['targetFoundAsSuggestion'] ?? true));

            $normalized[$username] = [
                'username' => $username,
                'displayName' => $this->nullableTrim($connection['displayName'] ?? null),
                'profileUrl' => $this->nullableTrim($connection['profileUrl'] ?? null)
                    ?: 'https://www.instagram.com/'.$username.'/',
                'profileImageUrl' => $this->nullableTrim($connection['profileImageUrl'] ?? $connection['profile_image_url'] ?? null),
                'profileVisibility' => in_array(($connection['profileVisibility'] ?? null), ['public', 'private', 'unknown'], true)
                    ? $connection['profileVisibility']
                    : null,
                'isPrivate' => is_bool($connection['isPrivate'] ?? null) ? $connection['isPrivate'] : null,
                'postsCount' => is_numeric($connection['postsCount'] ?? null) ? (int) $connection['postsCount'] : null,
                'followersCount' => is_numeric($connection['followersCount'] ?? null) ? (int) $connection['followersCount'] : null,
                'followingCount' => is_numeric($connection['followingCount'] ?? null) ? (int) $connection['followingCount'] : null,
                'hoverCard' => is_array($connection['hoverCard'] ?? null) ? $connection['hoverCard'] : null,
                'sourceSuggestionUsername' => $sourceUsername,
                'sourcePublicUsername' => $sourceUsername,
                'targetFoundAsSuggestion' => (bool) ($connection['targetFoundAsSuggestion'] ?? true),
                'targetFoundInPublicLists' => (bool) ($connection['targetFoundInPublicLists'] ?? false),
                'targetFoundInFollowers' => (bool) ($connection['targetFoundInFollowers'] ?? false),
                'targetFoundInFollowing' => (bool) ($connection['targetFoundInFollowing'] ?? false),
                'sourceLists' => $sourceLists,
                'publicListSearch' => is_array($connection['publicListSearch'] ?? null)
                    ? $connection['publicListSearch']
                    : [],
                'suggestionPreview' => is_array($connection['suggestionPreview'] ?? null)
                    ? array_values(array_slice($connection['suggestionPreview'], 0, 12))
                    : [],
            ];
        }

        return array_values($normalized);
    }

    private function normalizeSourceLists(array $connection, bool $targetFoundAsSuggestion): array
    {
        $sourceLists = [];
        $rawSourceLists = $connection['sourceLists'] ?? $connection['source_lists'] ?? [];

        if (is_array($rawSourceLists)) {
            foreach ($rawSourceLists as $sourceList) {
                if (! is_scalar($sourceList)) {
                    continue;
                }

                $sourceList = $this->nullableTrim($sourceList);

                if ($sourceList !== null) {
                    $sourceLists[] = $sourceList;
                }
            }
        }

        if ($targetFoundAsSuggestion) {
            $sourceLists[] = 'profile_suggestions';
        }

        if ((bool) ($connection['targetFoundInFollowers'] ?? false)) {
            $sourceLists[] = 'public_profile_followers';
        }

        if ((bool) ($connection['targetFoundInFollowing'] ?? false)) {
            $sourceLists[] = 'public_profile_following';
        }

        return array_values(array_unique($sourceLists ?: ['profile_suggestions']));
    }

    private function mergeSuggestionConnections(array ...$connectionGroups): array
    {
        $merged = [];

        foreach ($connectionGroups as $connections) {
            foreach ($connections as $connection) {
                $username = $this->scraper->normalizeInstagramUsername((string) ($connection['username'] ?? ''));

                if ($username === null) {
                    continue;
                }

                $merged[$username] = [
                    ...($merged[$username] ?? []),
                    ...$connection,
                    'username' => $username,
                ];
            }
        }

        return array_values($merged);
    }

    private function normalizeObservedSuggestionItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! is_scalar($item['username'] ?? null)) {
                continue;
            }

            $username = $this->scraper->normalizeInstagramUsername((string) $item['username']);

            if ($username === null) {
                continue;
            }

            $skippedReason = $this->nullableTrim($item['skippedReason'] ?? null);
            $alreadyKnown = (bool) ($item['alreadyKnown'] ?? false)
                || in_array($skippedReason, ['already-saved-match', 'already-scanned-suggestion', 'already-known-suggestion', 'recently-checked-suggestion'], true)
                || (bool) ($item['previousTargetFoundAsSuggestion'] ?? false);

            $normalized[$username] = [
                'username' => $username,
                'displayName' => $this->nullableTrim($item['displayName'] ?? null),
                'profileUrl' => $this->nullableTrim($item['profileUrl'] ?? null)
                    ?: 'https://www.instagram.com/'.$username.'/',
                'profileImageUrl' => $this->nullableTrim($item['profileImageUrl'] ?? $item['profile_image_url'] ?? null),
                'profileVisibility' => in_array(($item['profileVisibility'] ?? null), ['public', 'private', 'unknown'], true)
                    ? $item['profileVisibility']
                    : null,
                'isPrivate' => is_bool($item['isPrivate'] ?? null) ? $item['isPrivate'] : null,
                'postsCount' => is_numeric($item['postsCount'] ?? null) ? (int) $item['postsCount'] : null,
                'followersCount' => is_numeric($item['followersCount'] ?? null) ? (int) $item['followersCount'] : null,
                'followingCount' => is_numeric($item['followingCount'] ?? null) ? (int) $item['followingCount'] : null,
                'sourceLists' => is_array($item['sourceLists'] ?? null)
                    ? array_values(array_filter(array_map(
                        static fn ($value): string => is_scalar($value) ? trim((string) $value) : '',
                        $item['sourceLists'],
                    )))
                    : [],
                'suggestionLevel' => is_numeric($item['suggestionLevel'] ?? null) ? (int) $item['suggestionLevel'] : null,
                'checkMode' => $this->nullableTrim($item['checkMode'] ?? null),
                'deepSearchBranch' => (bool) ($item['deepSearchBranch'] ?? false),
                'sourceSeedUsername' => $this->scraper->normalizeInstagramUsername($item['sourceSeedUsername'] ?? null),
                'checked' => array_key_exists('checked', $item) ? (bool) $item['checked'] : false,
                'skipped' => array_key_exists('skipped', $item) ? (bool) $item['skipped'] : false,
                'matched' => array_key_exists('matched', $item) ? (bool) $item['matched'] : false,
                'alreadyKnown' => $alreadyKnown,
                'dismissedFromSuggestions' => (bool) ($item['dismissedFromSuggestions'] ?? false),
                'skippedReason' => $skippedReason,
                'recentlyChecked' => (bool) ($item['recentlyChecked'] ?? false),
                'lastCheckedAt' => $this->nullableTrim($item['lastCheckedAt'] ?? null),
                'recheckAfter' => $this->nullableTrim($item['recheckAfter'] ?? null),
                'recentlySuggestionProfileScanned' => (bool) ($item['recentlySuggestionProfileScanned'] ?? false),
                'lastSuggestionProfileScanAt' => $this->nullableTrim($item['lastSuggestionProfileScanAt'] ?? null),
                'suggestionProfileRecheckAfter' => $this->nullableTrim($item['suggestionProfileRecheckAfter'] ?? null),
                'previousNoMatchChecks' => is_numeric($item['previousNoMatchChecks'] ?? null)
                    ? max(0, (int) $item['previousNoMatchChecks'])
                    : null,
            ];
        }

        return array_values($normalized);
    }

    private function mergeObservedSuggestionItems(array ...$itemGroups): array
    {
        $merged = [];

        foreach ($itemGroups as $items) {
            foreach ($items as $item) {
                $username = $this->scraper->normalizeInstagramUsername((string) ($item['username'] ?? ''));

                if ($username === null) {
                    continue;
                }

                $merged[$username] = [
                    ...($merged[$username] ?? []),
                    ...$item,
                    'username' => $username,
                    'alreadyKnown' => (bool) (($merged[$username]['alreadyKnown'] ?? false) || ($item['alreadyKnown'] ?? false)),
                    'checked' => (bool) (($merged[$username]['checked'] ?? false) || ($item['checked'] ?? false)),
                    'skipped' => (bool) (($merged[$username]['skipped'] ?? false) || ($item['skipped'] ?? false)),
                    'matched' => (bool) (($merged[$username]['matched'] ?? false) || ($item['matched'] ?? false)),
                    'recentlyChecked' => (bool) (($merged[$username]['recentlyChecked'] ?? false) || ($item['recentlyChecked'] ?? false)),
                    'recentlySuggestionProfileScanned' => (bool) (($merged[$username]['recentlySuggestionProfileScanned'] ?? false) || ($item['recentlySuggestionProfileScanned'] ?? false)),
                ];
            }
        }

        return array_values($merged);
    }

    private function normalizePayloadScreenshotPaths(array $payload): array
    {
        foreach (['screenshotPath'] as $key) {
            if (! is_scalar($payload[$key] ?? null)) {
                continue;
            }

            $resolved = $this->scraper->resolvePublicStoragePath((string) $payload[$key]);

            if ($resolved !== null) {
                $payload[$key] = $resolved;
            }
        }

        return $payload;
    }

    private function profileColumnData(?int $sourceProfileId, ?int $candidateProfileId): array
    {
        if (
            ! Schema::hasColumn('tracked_person_instagram_inferred_connections', 'source_public_instagram_profile_id')
            || ! Schema::hasColumn('tracked_person_instagram_inferred_connections', 'candidate_instagram_profile_id')
        ) {
            return [];
        }

        return array_filter([
            'source_public_instagram_profile_id' => $sourceProfileId,
            'candidate_instagram_profile_id' => $candidateProfileId,
        ], static fn ($value): bool => $value !== null);
    }

    private function reportProgress(?callable $progress, array $payload): void
    {
        $this->assertActiveScanCurrent();

        if (! $progress) {
            return;
        }

        $payload['phase'] = $payload['phase'] ?? 'suggestions';
        $payload['percent'] = max(0, min(100, (int) ($payload['percent'] ?? 0)));
        $payload['message'] = (string) ($payload['message'] ?? 'Vorschlaege-Scan laeuft.');

        $progress($payload);
    }

    private function withActiveScanControl(array $runtimeConfigOverrides = []): array
    {
        if ($this->activeScanControl === null) {
            return $runtimeConfigOverrides;
        }

        return [
            ...$runtimeConfigOverrides,
            '_scanControl' => $this->activeScanControl,
        ];
    }

    private function assertActiveScanCurrent(): void
    {
        if ($this->activeScanControl === null) {
            return;
        }

        $this->scanCoordinator->assertCurrent(
            (int) $this->activeScanControl['trackedPersonId'],
            (int) $this->activeScanControl['generation'],
        );
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeSuggestionProfileVisibility(array $item): ?string
    {
        $visibility = $this->nullableTrim($item['profileVisibility'] ?? $item['profile_visibility'] ?? null);

        if (in_array($visibility, ['public', 'private', 'unknown'], true)) {
            return $visibility;
        }

        $isPrivate = $this->normalizeSuggestionProfileIsPrivate($item);

        return $isPrivate === null ? null : ($isPrivate ? 'private' : 'public');
    }

    private function normalizeSuggestionProfileIsPrivate(array $item): ?bool
    {
        if (is_bool($item['isPrivate'] ?? null)) {
            return $item['isPrivate'];
        }

        if (is_bool($item['is_private'] ?? null)) {
            return $item['is_private'];
        }

        $visibility = $this->nullableTrim($item['profileVisibility'] ?? $item['profile_visibility'] ?? null);

        return match ($visibility) {
            'private' => true,
            'public' => false,
            default => null,
        };
    }
}
