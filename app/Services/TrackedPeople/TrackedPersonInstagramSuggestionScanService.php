<?php

namespace App\Services\TrackedPeople;

use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramInferredConnection;
use App\Models\TrackedPersonInstagramSuggestionScan;
use App\Services\Billing\ScanCreditService;
use App\Services\Social\InstagramScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrackedPersonInstagramSuggestionScanService
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly TrackedPersonInstagramScanCoordinator $scanCoordinator,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
        private readonly ScanCreditService $scanCreditService,
    ) {
    }

    private ?array $activeScanControl = null;

    public function scan(
        TrackedPerson $trackedPerson,
        ?callable $progress = null,
        ?string $targetUsernameOverride = null,
    ): TrackedPersonInstagramSuggestionScan
    {
        $targetUsername = $this->scraper->normalizeInstagramUsername(
            $targetUsernameOverride ?: $trackedPerson->instagram_username,
        );

        if ($targetUsername === null) {
            throw new \RuntimeException('Fuer diese Person ist kein Instagram-Name hinterlegt.');
        }

        $scanControl = $this->scanCoordinator->begin(
            $trackedPerson->id,
            'Profilvorschlag-Verbindungsscan',
        );

        Cache::lock('tracked-person-instagram-suggestion-scan:'.$trackedPerson->id, 3600)->forceRelease();
        $lock = Cache::lock('tracked-person-instagram-suggestion-scan:'.$trackedPerson->id, 3600);

        if (! $lock->get()) {
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            throw new \RuntimeException('Fuer diese Person laeuft bereits ein Profilvorschlag-Verbindungsscan.');
        }

        $this->activeScanControl = $scanControl;

        try {
            return $this->scanWithLock($trackedPerson, $targetUsername, $progress);
        } finally {
            $lock->release();
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            $this->activeScanControl = null;
        }
    }

    private function scanWithLock(
        TrackedPerson $trackedPerson,
        string $targetUsername,
        ?callable $progress = null,
    ): TrackedPersonInstagramSuggestionScan {
        $this->profileRelationshipStore->syncTrackedPersonProfile($trackedPerson);

        $liveConnections = [];

        $this->reportProgress($progress, [
            'phase' => 'suggestions',
            'percent' => 1,
            'message' => 'Profilvorschlag-Verbindungsscan wird vorbereitet.',
            'foundSuggestions' => 0,
            'suggestionConnections' => [],
        ]);

        $payload = $this->scraper->scrape(
            $targetUsername,
            'suggestions',
            function (array $state) use ($trackedPerson, $targetUsername, $progress, &$liveConnections): void {
                if (array_key_exists('suggestionConnections', $state)) {
                    $liveConnections = $this->mergeSuggestionConnections(
                        $liveConnections,
                        $this->normalizeSuggestionConnections($state['suggestionConnections']),
                    );

                    if ($liveConnections !== []) {
                        $this->storeInferredSuggestionConnections(
                            $trackedPerson,
                            null,
                            $targetUsername,
                            $liveConnections,
                            ['progress' => true],
                            now('UTC'),
                        );
                    }
                }

                $this->reportProgress($progress, [
                    ...$state,
                    'phase' => 'suggestions',
                    'foundSuggestions' => count($liveConnections),
                    'suggestionConnections' => $liveConnections,
                ]);
            },
            $this->withActiveScanControl([
                'suggestionScanMaxItems' => 500,
                'suggestionCandidateMaxItems' => 300,
                'suggestionPublicListSearchMaxScrollRounds' => 140,
                'suggestionInlineMaxRounds' => 60,
                'suggestionDialogMaxRounds' => 100,
                'suggestionCandidateInlineMaxRounds' => 40,
                'suggestionCandidateDialogMaxRounds' => 70,
                'suggestionCandidateHistory' => $this->buildSuggestionCandidateHistory($trackedPerson),
            ]),
        );

        return $this->storeScan($trackedPerson, $targetUsername, $payload, $liveConnections);
    }

    private function storeScan(
        TrackedPerson $trackedPerson,
        string $targetUsername,
        array $payload,
        array $liveConnections,
    ): TrackedPersonInstagramSuggestionScan {
        $this->assertActiveScanCurrent();

        $payload = $this->normalizePayloadScreenshotPaths($payload);
        $scanPayload = $this->suggestionPayload($payload);
        $connections = $this->mergeSuggestionConnections(
            $liveConnections,
            $this->normalizeSuggestionConnections($payload['suggestionConnections'] ?? []),
            $this->normalizeSuggestionConnections($scanPayload['matches'] ?? []),
        );
        $analyzedAt = now('UTC');

        $scan = DB::transaction(function () use (
            $trackedPerson,
            $targetUsername,
            $payload,
            $scanPayload,
            $connections,
            $analyzedAt,
        ): TrackedPersonInstagramSuggestionScan {
            $scan = TrackedPersonInstagramSuggestionScan::create([
                'tracked_person_id' => $trackedPerson->id,
                'user_id' => $trackedPerson->user_id,
                'target_username' => $targetUsername,
                'status_level' => (string) ($payload['statusLevel'] ?? $scanPayload['statusLevel'] ?? 'unknown'),
                'status_message' => (string) ($payload['statusMessage'] ?? $scanPayload['statusMessage'] ?? 'Profilvorschlag-Verbindungsscan abgeschlossen.'),
                'suggestions_observed_count' => (int) ($scanPayload['observedCount'] ?? count($scanPayload['suggestions'] ?? [])),
                'suggestions_checked_count' => (int) ($scanPayload['checkedCount'] ?? 0),
                'suggestion_matches_count' => count($connections),
                'gracefully_stopped' => (bool) ($payload['gracefullyStopped'] ?? $scanPayload['gracefullyStopped'] ?? false),
                'raw_payload' => $payload,
                'analyzed_at' => $analyzedAt,
            ]);

            $this->storeInferredSuggestionConnections(
                $trackedPerson,
                $scan,
                $targetUsername,
                $connections,
                $payload,
                $analyzedAt,
            );

            return $scan;
        });

        $this->scanCreditService->charge(
            (int) $trackedPerson->user_id,
            $scan,
            $payload,
            'Instagram-Vorschlagsscan @'.$targetUsername,
        );

        return $scan;
    }

    private function storeInferredSuggestionConnections(
        TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSuggestionScan $scan,
        string $targetUsername,
        array $connections,
        array $payload,
        \Illuminate\Support\Carbon $seenAt,
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

            $sourceUsername = $this->scraper->normalizeInstagramUsername(
                (string) ($connection['sourceSuggestionUsername'] ?? $connection['sourcePublicUsername'] ?? $candidateUsername),
            ) ?? $candidateUsername;
            $candidateProfile = $this->profileRelationshipStore->ensureProfile($candidateUsername, [
                'display_name' => $this->nullableTrim($connection['displayName'] ?? null),
                'profile_url' => $this->nullableTrim($connection['profileUrl'] ?? null),
                'profile_image_url' => $this->nullableTrim($connection['profileImageUrl'] ?? $connection['profile_image_url'] ?? null),
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

    private function suggestionPayload(array $payload): array
    {
        $suggestionPayload = $payload['suggestionScan'] ?? data_get($payload, 'profile.suggestionScan', []);

        return is_array($suggestionPayload) ? $suggestionPayload : [];
    }

    private function buildSuggestionCandidateHistory(TrackedPerson $trackedPerson): array
    {
        $history = [];

        TrackedPersonInstagramInferredConnection::query()
            ->where('tracked_person_id', $trackedPerson->id)
            ->where('relationship_type', 'suggestion_connection')
            ->where('status', 'active')
            ->get(['candidate_username'])
            ->each(function (TrackedPersonInstagramInferredConnection $connection) use (&$history): void {
                $username = $this->scraper->normalizeInstagramUsername($connection->candidate_username);

                if ($username === null) {
                    return;
                }

                $history[$username] = [
                    ...($history[$username] ?? []),
                    'hasMatch' => true,
                    'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                    'permanentlyDismissed' => false,
                ];
            });

        $trackedPerson
            ->instagramSuggestionScans()
            ->latest('analyzed_at')
            ->limit(100)
            ->get()
            ->each(function (TrackedPersonInstagramSuggestionScan $scan) use (&$history): void {
                $payload = is_array($scan->raw_payload) ? $scan->raw_payload : [];
                $scanPayload = $this->suggestionPayload($payload);

                foreach ($this->normalizeSuggestionConnections($payload['suggestionConnections'] ?? []) as $connection) {
                    $username = $this->scraper->normalizeInstagramUsername($connection['username'] ?? null);

                    if ($username === null) {
                        continue;
                    }

                    $history[$username] = [
                        ...($history[$username] ?? []),
                        'hasMatch' => true,
                        'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                        'permanentlyDismissed' => false,
                    ];
                }

                foreach ($this->normalizeSuggestionConnections($scanPayload['matches'] ?? []) as $connection) {
                    $username = $this->scraper->normalizeInstagramUsername($connection['username'] ?? null);

                    if ($username === null) {
                        continue;
                    }

                    $history[$username] = [
                        ...($history[$username] ?? []),
                        'hasMatch' => true,
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

                    $hasCandidateMatch = (bool) ($candidate['targetFoundAsSuggestion'] ?? false)
                        || (bool) ($candidate['targetFoundInPublicLists'] ?? false)
                        || (bool) ($candidate['targetFoundInFollowers'] ?? false)
                        || (bool) ($candidate['targetFoundInFollowing'] ?? false);

                    if ($hasCandidateMatch) {
                        $history[$username] = [
                            ...($history[$username] ?? []),
                            'hasMatch' => true,
                            'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                            'permanentlyDismissed' => false,
                        ];

                        continue;
                    }

                    $checkMode = is_scalar($candidate['checkMode'] ?? null)
                        ? (string) $candidate['checkMode']
                        : 'profile-suggestions';
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
                    $history[$username] = [
                        ...($history[$username] ?? []),
                        'hasMatch' => false,
                        'noMatchChecks' => $noMatchChecks,
                        'permanentlyDismissed' => $noMatchChecks >= 2
                            || (bool) ($candidate['finalDismissedAfterSecondMiss'] ?? false),
                    ];
                }
            });

        return $history;
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
        $payload['message'] = (string) ($payload['message'] ?? 'Profilvorschlag-Verbindungsscan laeuft.');

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
}
