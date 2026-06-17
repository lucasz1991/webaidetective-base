<?php

namespace App\Services\TrackedPeople;

use App\Models\InstagramProfile;
use App\Models\InstagramProfileListScan;
use App\Models\InstagramProfileListScanItem;
use App\Models\InstagramProfileRelationship;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramProfileLink;
use App\Models\TrackedPersonInstagramSnapshot;
use App\Models\TrackedPersonPublicProfile;
use App\Services\Social\InstagramProfileImageStorage;
use App\Services\Support\DatabaseKeepAlive;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstagramProfileRelationshipStore
{
    public function __construct(
        private readonly InstagramProfileImageStorage $profileImageStorage,
    ) {}

    private ?bool $ready = null;

    private array $columnCache = [];

    public function syncTrackedPersonProfile(TrackedPerson $trackedPerson, array $attributes = []): ?InstagramProfile
    {
        if (! $this->isReady()) {
            return null;
        }

        if (! filled($trackedPerson->instagram_username)) {
            $this->unlinkTrackedPersonCurrentProfile($trackedPerson);

            return null;
        }

        $profile = $this->ensureProfile($trackedPerson->instagram_username, [
            'display_name' => $trackedPerson->display_name,
            'profile_image_path' => $trackedPerson->instagram_profile_image_path ?: $trackedPerson->profile_image_path,
            'profile_image_hash' => $trackedPerson->instagram_profile_image_hash ?: $trackedPerson->profile_image_hash,
            'followers_count' => $trackedPerson->instagram_followers_count,
            'following_count' => $trackedPerson->instagram_following_count,
            'posts_count' => $trackedPerson->instagram_posts_count,
            'last_status_level' => $trackedPerson->last_instagram_status_level,
            'last_status_message' => $trackedPerson->last_instagram_status_message,
            'last_scanned_at' => $trackedPerson->last_instagram_analyzed_at,
            ...$attributes,
        ]);

        if (! $profile) {
            return null;
        }

        $this->linkTrackedPersonToProfile($trackedPerson, $profile);

        return $profile;
    }

    public function syncPublicProfile(TrackedPersonPublicProfile $publicProfile, array $attributes = []): ?InstagramProfile
    {
        if (! $this->isReady() || $publicProfile->platform !== 'instagram' || ! filled($publicProfile->username)) {
            return null;
        }

        $profile = $this->ensureProfile($publicProfile->username, [
            'display_name' => $publicProfile->display_name,
            'profile_url' => $publicProfile->resolved_profile_url,
            'profile_visibility' => $publicProfile->is_public ? 'public' : 'unknown',
            ...$attributes,
        ]);

        if (! $profile) {
            return null;
        }

        if (
            $this->hasColumn('tracked_person_public_profiles', 'instagram_profile_id')
            && (int) $publicProfile->instagram_profile_id !== (int) $profile->id
        ) {
            $publicProfile->forceFill(['instagram_profile_id' => $profile->id])->save();
        }

        return $profile;
    }

    public function syncProfileFromSnapshot(
        TrackedPerson $trackedPerson,
        TrackedPersonInstagramSnapshot $snapshot,
        array $extracted,
        array $payload = [],
        array $attemptInfo = [],
    ): ?InstagramProfile {
        if (! $this->isReady()) {
            return null;
        }

        $profileAttributes = [
            'display_name' => $snapshot->full_name ?: $trackedPerson->display_name,
            'full_name' => $snapshot->full_name,
            'biography' => $snapshot->biography,
            'profile_image_url' => $snapshot->profile_image_url,
            'profile_image_path' => $snapshot->profile_image_path ?: $trackedPerson->instagram_profile_image_path,
            'profile_image_hash' => $snapshot->profile_image_hash ?: $trackedPerson->instagram_profile_image_hash,
            'is_private' => $extracted['is_private'] ?? null,
            'profile_visibility' => $extracted['profile_visibility'] ?? null,
            'followers_count' => $snapshot->followers_count,
            'following_count' => $snapshot->following_count,
            'posts_count' => $snapshot->posts_count,
            'last_status_level' => $snapshot->status_level,
            'last_status_message' => $snapshot->status_message,
            'last_scanned_at' => $snapshot->analyzed_at,
            'raw_profile' => [
                'snapshot_id' => $snapshot->id,
                'count_sources' => $extracted['count_sources'] ?? [],
                'visible_counts_complete' => (bool) ($extracted['visible_counts_complete'] ?? false),
                'payload_status' => [
                    'ok' => (bool) ($payload['ok'] ?? false),
                    'status_level' => $payload['statusLevel'] ?? null,
                    'status_message' => $payload['statusMessage'] ?? null,
                ],
            ],
        ];

        if (! $this->snapshotScanCanUpdateProfileVisibility($snapshot, $payload, $attemptInfo)) {
            unset($profileAttributes['is_private'], $profileAttributes['profile_visibility']);
        }

        $profile = $this->ensureProfile($snapshot->instagram_username ?: $trackedPerson->instagram_username, $profileAttributes);

        if (! $profile) {
            return null;
        }

        $this->linkTrackedPersonToProfile($trackedPerson, $profile);

        if (
            $this->hasColumn('tracked_person_instagram_snapshots', 'instagram_profile_id')
            && (int) $snapshot->instagram_profile_id !== (int) $profile->id
        ) {
            $snapshot->forceFill(['instagram_profile_id' => $profile->id])->save();
        }

        $this->propagateProfileDataToLinkedTrackedPeople($profile, $trackedPerson);
        $this->storeRelationshipLists($profile, $trackedPerson, $snapshot, $extracted, $attemptInfo);

        return $profile;
    }

    public function profileColumnDataForInferredConnection(
        TrackedPersonPublicProfile $publicProfile,
        string $candidateUsername,
        array $candidateAttributes = [],
    ): array {
        if (
            ! $this->isReady()
            || ! $this->hasColumn('tracked_person_instagram_inferred_connections', 'source_public_instagram_profile_id')
            || ! $this->hasColumn('tracked_person_instagram_inferred_connections', 'candidate_instagram_profile_id')
        ) {
            return [];
        }

        $sourceProfile = $this->syncPublicProfile($publicProfile);
        $candidateProfile = $this->ensureProfile($candidateUsername, $candidateAttributes);

        return array_filter([
            'source_public_instagram_profile_id' => $sourceProfile?->id,
            'candidate_instagram_profile_id' => $candidateProfile?->id,
        ], static fn ($value): bool => $value !== null);
    }

    public function backfillSnapshotRelationshipLists(TrackedPersonInstagramSnapshot $snapshot): int
    {
        if (! $this->isReady()) {
            return 0;
        }

        $trackedPerson = $snapshot->trackedPerson;

        if (! $trackedPerson) {
            return 0;
        }

        $followersList = $this->storedRelationshipListFromSnapshot($snapshot, 'followersList');
        $followingList = $this->storedRelationshipListFromSnapshot($snapshot, 'followingList');

        if (
            ! (bool) ($followersList['attempted'] ?? false)
            && ! (bool) ($followingList['attempted'] ?? false)
        ) {
            return 0;
        }

        $before = InstagramProfileListScan::query()
            ->where('snapshot_id', $snapshot->id)
            ->count();
        $rawPayload = is_array($snapshot->raw_payload) ? $snapshot->raw_payload : [];

        $this->syncProfileFromSnapshot(
            $trackedPerson,
            $snapshot,
            [
                'full_name' => $snapshot->full_name,
                'biography' => $snapshot->biography,
                'is_private' => data_get($rawPayload, 'extractedProfile.isPrivate'),
                'profile_visibility' => data_get($rawPayload, 'extractedProfile.profileVisibility'),
                'count_sources' => data_get($rawPayload, 'extractedProfile.countSources', []),
                'visible_counts_complete' => (bool) data_get($rawPayload, 'extractedProfile.visibleCountsComplete', false),
                'followers_list' => $followersList,
                'following_list' => $followingList,
            ],
            $rawPayload,
            ['scan_mode' => 'backfill'],
        );

        $after = InstagramProfileListScan::query()
            ->where('snapshot_id', $snapshot->id)
            ->count();

        return max(0, $after - $before);
    }

    public function storeDirectRelationshipListScan(
        InstagramProfile $sourceProfile,
        ?TrackedPerson $trackedPerson,
        string $listType,
        array $relationshipList,
        array $payload = [],
        string $scanMode = 'profile_list',
        ?int $userId = null,
    ): ?InstagramProfileListScan {
        if (! $this->isReady() || ! in_array($listType, ['followers', 'following'], true)) {
            return null;
        }

        DatabaseKeepAlive::reconnect();

        $scannedAt = $this->parseTimestamp($payload['analyzedAt'] ?? null) ?: now('UTC');
        $scan = InstagramProfileListScan::create([
            'instagram_profile_id' => $sourceProfile->id,
            'tracked_person_id' => $trackedPerson?->id,
            'snapshot_id' => null,
            'user_id' => $trackedPerson?->user_id ?: $userId,
            'list_type' => $listType,
            'scan_mode' => $scanMode,
            'status_level' => $this->directRelationshipListStatusLevel($relationshipList, $payload),
            'status_message' => $this->directRelationshipListStatusMessage($relationshipList, $payload, $listType),
            'attempted' => (bool) ($relationshipList['attempted'] ?? false),
            'available' => (bool) ($relationshipList['available'] ?? false),
            'complete' => (bool) ($relationshipList['complete'] ?? false),
            'rate_limited' => (bool) ($relationshipList['rateLimited'] ?? false),
            'gracefully_stopped' => (bool) ($relationshipList['gracefullyStopped'] ?? data_get($payload, 'gracefullyStopped', false)),
            'expected_count' => $this->nullableInteger($relationshipList['expectedCount'] ?? null),
            'observed_count' => (int) ($relationshipList['observedCount'] ?? count($relationshipList['observedItems'] ?? [])),
            'active_count' => (int) ($relationshipList['activeCount'] ?? count($relationshipList['items'] ?? [])),
            'known_count' => (int) ($relationshipList['knownCount'] ?? count($relationshipList['items'] ?? [])),
            'added_count' => (int) ($relationshipList['addedCount'] ?? count($relationshipList['addedItems'] ?? [])),
            'removed_count' => (int) ($relationshipList['removedCount'] ?? count($relationshipList['removedItems'] ?? [])),
            'search_attempted' => (bool) ($relationshipList['searchAttempted'] ?? false),
            'search_rounds' => (int) ($relationshipList['searchRounds'] ?? 0),
            'raw_payload' => [
                ...$this->relationshipListScanPayload($relationshipList),
                'operation_mode' => $payload['operationMode'] ?? null,
                'screenshot_path' => $payload['screenshotPath'] ?? null,
                'html_path' => $payload['htmlPath'] ?? null,
                'status_level' => $payload['statusLevel'] ?? null,
                'status_message' => $payload['statusMessage'] ?? null,
            ],
            'scanned_at' => $scannedAt,
        ]);

        $addedUsernames = $this->usernameLookup($relationshipList['addedItems'] ?? []);

        foreach ($this->normalizeRelationshipItems($relationshipList['observedItems'] ?? $relationshipList['items'] ?? []) as $item) {
            $this->upsertObservedRelationship(
                $scan,
                $sourceProfile,
                $listType,
                $item,
                isset($addedUsernames[$item['username'] ?? '']) ? 'added' : 'observed',
            );
        }

        foreach ($this->normalizeRelationshipItems($relationshipList['removedItems'] ?? []) as $item) {
            $this->markRemovedRelationship($scan, $sourceProfile, $listType, $item);
        }

        return $scan;
    }

    public function syncObservedRelationshipPreview(
        InstagramProfile $sourceProfile,
        ?TrackedPerson $trackedPerson,
        string $listType,
        array $items,
        mixed $observedAt = null,
        array $evidence = [],
    ): int {
        if (! $this->isReady() || ! in_array($listType, ['followers', 'following', 'profile_suggestions'], true)) {
            return 0;
        }

        DatabaseKeepAlive::ping(15);

        $observedAt = $this->parseTimestamp($observedAt) ?: now('UTC');
        $normalizedItems = $this->normalizeRelationshipItems($items);
        $stored = 0;

        foreach ($normalizedItems as $item) {
            $relatedProfile = $this->ensureProfile($item['username'] ?? null, [
                'display_name' => $item['displayName'] ?? null,
                'profile_url' => $item['profileUrl'] ?? null,
                'profile_image_url' => $item['profileImageUrl'] ?? $item['profile_image_url'] ?? null,
                'is_private' => $item['isPrivate'] ?? null,
                'profile_visibility' => $item['profileVisibility'] ?? null,
                'followers_count' => $item['followersCount'] ?? null,
                'following_count' => $item['followingCount'] ?? null,
                'posts_count' => $item['postsCount'] ?? null,
            ]);

            if (! $relatedProfile) {
                continue;
            }

            $firstSeenAt = $this->parseTimestamp($item['firstSeenAt'] ?? null) ?: $observedAt;
            $lastSeenAt = $this->parseTimestamp($item['lastSeenAt'] ?? null) ?: $observedAt;
            $relationship = InstagramProfileRelationship::withTrashed()
                ->where('source_instagram_profile_id', $sourceProfile->id)
                ->where('related_instagram_profile_id', $relatedProfile->id)
                ->where('list_type', $listType)
                ->first();

            if ($relationship && $relationship->trashed()) {
                $relationship->restore();
            }

            if (! $relationship) {
                $relationship = new InstagramProfileRelationship([
                    'source_instagram_profile_id' => $sourceProfile->id,
                    'related_instagram_profile_id' => $relatedProfile->id,
                    'list_type' => $listType,
                    'first_seen_scan_id' => null,
                    'first_seen_at' => $firstSeenAt,
                ]);
            }

            $relationship->forceFill([
                'last_seen_scan_id' => null,
                'status' => 'active',
                'display_name_snapshot' => $item['displayName'] ?? null,
                'profile_url_snapshot' => $item['profileUrl'] ?? null,
                'first_seen_at' => $relationship->first_seen_at ?: $firstSeenAt,
                'last_seen_at' => $lastSeenAt,
                'removed_scan_id' => null,
                'removed_at' => null,
                'evidence' => [
                    'source' => $evidence['source'] ?? 'progress_preview',
                    'tracked_person_id' => $trackedPerson?->id,
                    'last_item' => $item,
                    'observed_at' => optional($observedAt)->toIso8601String(),
                    ...$evidence,
                ],
            ])->save();

            $stored++;
        }

        return $stored;
    }

    public function ensureProfile(mixed $username, array $attributes = []): ?InstagramProfile
    {
        if (! $this->isReady()) {
            return null;
        }

        $username = $this->normalizeUsername($username);

        if ($username === null) {
            return null;
        }

        $payload = $this->profilePayload($username, $attributes);
        $sourceImageUrl = $this->nullableTrim($payload['profile_image_url'] ?? $attributes['profile_image_url'] ?? null);
        $profile = InstagramProfile::withTrashed()
            ->where('username', $username)
            ->first();

        if ($profile) {
            if ($profile->trashed()) {
                $profile->restore();
            }

            if ($payload !== []) {
                $profile->forceFill($payload)->save();
            }

            $profile = $this->storeLocalProfileImageIfNeeded($profile->fresh(), $sourceImageUrl);

            return $profile->fresh();
        }

        $profile = InstagramProfile::create([
            'username' => $username,
            'profile_url' => 'https://www.instagram.com/'.$username.'/',
            ...$payload,
        ]);

        return $this->storeLocalProfileImageIfNeeded($profile, $sourceImageUrl)->fresh();
    }

    private function storeLocalProfileImageIfNeeded(InstagramProfile $profile, ?string $sourceImageUrl): InstagramProfile
    {
        $sourceImageUrl ??= $profile->profile_image_url;
        $hasLocalImage = filled($profile->profile_image_path)
            && Storage::disk('public')->exists($profile->profile_image_path);

        if (blank($sourceImageUrl) || $hasLocalImage) {
            return $profile;
        }

        $this->profileImageStorage->storeFromUrl($profile, $sourceImageUrl);

        return $profile->fresh();
    }

    private function linkTrackedPersonToProfile(TrackedPerson $trackedPerson, InstagramProfile $profile): void
    {
        if (! $this->isReady()) {
            return;
        }

        TrackedPersonInstagramProfileLink::query()
            ->where('tracked_person_id', $trackedPerson->id)
            ->where('instagram_profile_id', '!=', $profile->id)
            ->where('is_current', true)
            ->update([
                'is_current' => false,
                'unlinked_at' => now('UTC'),
            ]);

        $link = TrackedPersonInstagramProfileLink::withTrashed()->firstOrNew([
            'tracked_person_id' => $trackedPerson->id,
            'instagram_profile_id' => $profile->id,
            'relation_type' => 'observed',
        ]);

        if ($link->exists && $link->trashed()) {
            $link->restore();
        }

        $link->forceFill([
            'user_id' => $trackedPerson->user_id,
            'is_current' => true,
            'linked_at' => $link->linked_at ?: now('UTC'),
            'unlinked_at' => null,
        ])->save();

        if (
            $this->hasColumn('tracked_people', 'current_instagram_profile_id')
            && (int) $trackedPerson->current_instagram_profile_id !== (int) $profile->id
        ) {
            $trackedPerson->forceFill(['current_instagram_profile_id' => $profile->id])->save();
        }
    }

    public function propagateProfileDataToLinkedTrackedPeople(InstagramProfile $profile, ?TrackedPerson $scannedTrackedPerson = null): void
    {
        if (! $this->isReady() || ! $this->hasColumn('tracked_people', 'current_instagram_profile_id')) {
            return;
        }

        $updates = array_filter([
            'instagram_username' => $profile->username,
            'instagram_profile_image_path' => $profile->profile_image_path,
            'instagram_profile_image_hash' => $profile->profile_image_hash,
            'profile_image_path' => $profile->profile_image_path,
            'profile_image_hash' => $profile->profile_image_hash,
            'instagram_followers_count' => $profile->followers_count,
            'instagram_following_count' => $profile->following_count,
            'instagram_posts_count' => $profile->posts_count,
            'last_instagram_status_level' => $profile->last_status_level,
            'last_instagram_status_message' => $profile->last_status_message,
            'last_instagram_analyzed_at' => $profile->last_scanned_at,
            'updated_at' => now(),
        ], static fn ($value): bool => $value !== null);

        if ($updates === []) {
            return;
        }

        $linkedTrackedPersonIds = TrackedPersonInstagramProfileLink::query()
            ->where('instagram_profile_id', $profile->id)
            ->where('is_current', true)
            ->pluck('tracked_person_id')
            ->all();

        TrackedPerson::query()
            ->where(function ($query) use ($profile, $linkedTrackedPersonIds): void {
                $query->where('current_instagram_profile_id', $profile->id);

                if ($linkedTrackedPersonIds !== []) {
                    $query->orWhereIn('id', $linkedTrackedPersonIds);
                }
            })
            ->when($scannedTrackedPerson, fn ($query) => $query->whereKeyNot($scannedTrackedPerson->id))
            ->update($updates);
    }

    private function unlinkTrackedPersonCurrentProfile(TrackedPerson $trackedPerson): void
    {
        if (! $this->isReady()) {
            return;
        }

        TrackedPersonInstagramProfileLink::query()
            ->where('tracked_person_id', $trackedPerson->id)
            ->where('is_current', true)
            ->update([
                'is_current' => false,
                'unlinked_at' => now('UTC'),
            ]);

        if ($this->hasColumn('tracked_people', 'current_instagram_profile_id') && $trackedPerson->current_instagram_profile_id !== null) {
            $trackedPerson->forceFill(['current_instagram_profile_id' => null])->save();
        }
    }

    private function storeRelationshipLists(
        InstagramProfile $sourceProfile,
        TrackedPerson $trackedPerson,
        TrackedPersonInstagramSnapshot $snapshot,
        array $extracted,
        array $attemptInfo,
    ): void {
        if (! $this->isReady()) {
            return;
        }

        foreach ([
            'followers_list' => 'followers',
            'following_list' => 'following',
        ] as $extractedKey => $listType) {
            $relationshipList = $extracted[$extractedKey] ?? null;

            if (! is_array($relationshipList) || ! (bool) ($relationshipList['attempted'] ?? false)) {
                continue;
            }

            if (
                InstagramProfileListScan::query()
                    ->where('snapshot_id', $snapshot->id)
                    ->where('list_type', $listType)
                    ->exists()
            ) {
                continue;
            }

            $this->storeRelationshipListScan(
                $sourceProfile,
                $trackedPerson,
                $snapshot,
                $listType,
                $relationshipList,
                (string) ($attemptInfo['scan_mode'] ?? 'full'),
            );
        }
    }

    private function storeRelationshipListScan(
        InstagramProfile $sourceProfile,
        TrackedPerson $trackedPerson,
        TrackedPersonInstagramSnapshot $snapshot,
        string $listType,
        array $relationshipList,
        string $scanMode,
    ): InstagramProfileListScan {
        $scannedAt = $this->parseTimestamp($snapshot->analyzed_at) ?: now('UTC');
        $scan = InstagramProfileListScan::create([
            'instagram_profile_id' => $sourceProfile->id,
            'tracked_person_id' => $trackedPerson->id,
            'snapshot_id' => $snapshot->id,
            'user_id' => $trackedPerson->user_id,
            'list_type' => $listType,
            'scan_mode' => $scanMode,
            'status_level' => $this->relationshipListStatusLevel($relationshipList, $snapshot),
            'status_message' => $this->relationshipListStatusMessage($relationshipList, $snapshot, $listType),
            'attempted' => (bool) ($relationshipList['attempted'] ?? false),
            'available' => (bool) ($relationshipList['available'] ?? false),
            'complete' => (bool) ($relationshipList['complete'] ?? false),
            'rate_limited' => (bool) ($relationshipList['rateLimited'] ?? false),
            'gracefully_stopped' => (bool) ($relationshipList['gracefullyStopped'] ?? false),
            'expected_count' => $this->nullableInteger($relationshipList['expectedCount'] ?? null),
            'observed_count' => (int) ($relationshipList['observedCount'] ?? count($relationshipList['observedItems'] ?? [])),
            'active_count' => (int) ($relationshipList['activeCount'] ?? count($relationshipList['items'] ?? [])),
            'known_count' => (int) ($relationshipList['knownCount'] ?? count($relationshipList['items'] ?? [])),
            'added_count' => (int) ($relationshipList['addedCount'] ?? count($relationshipList['addedItems'] ?? [])),
            'removed_count' => (int) ($relationshipList['removedCount'] ?? count($relationshipList['removedItems'] ?? [])),
            'search_attempted' => (bool) ($relationshipList['searchAttempted'] ?? false),
            'search_rounds' => (int) ($relationshipList['searchRounds'] ?? 0),
            'raw_payload' => $this->relationshipListScanPayload($relationshipList),
            'scanned_at' => $scannedAt,
        ]);

        $addedUsernames = $this->usernameLookup($relationshipList['addedItems'] ?? []);

        foreach ($this->normalizeRelationshipItems($relationshipList['observedItems'] ?? $relationshipList['items'] ?? []) as $item) {
            $this->upsertObservedRelationship(
                $scan,
                $sourceProfile,
                $listType,
                $item,
                isset($addedUsernames[$item['username'] ?? '']) ? 'added' : 'observed',
            );
        }

        foreach ($this->normalizeRelationshipItems($relationshipList['removedItems'] ?? []) as $item) {
            $this->markRemovedRelationship($scan, $sourceProfile, $listType, $item);
        }

        return $scan;
    }

    private function upsertObservedRelationship(
        InstagramProfileListScan $scan,
        InstagramProfile $sourceProfile,
        string $listType,
        array $item,
        string $itemStatus,
    ): void {
        $relatedProfile = $this->ensureProfile($item['username'] ?? null, [
            'display_name' => $item['displayName'] ?? null,
            'profile_url' => $item['profileUrl'] ?? null,
            'profile_image_url' => $item['profileImageUrl'] ?? $item['profile_image_url'] ?? null,
            'is_private' => $item['isPrivate'] ?? null,
            'profile_visibility' => $item['profileVisibility'] ?? null,
            'followers_count' => $item['followersCount'] ?? null,
            'following_count' => $item['followingCount'] ?? null,
            'posts_count' => $item['postsCount'] ?? null,
        ]);

        if (! $relatedProfile) {
            return;
        }

        $firstSeenAt = $this->parseTimestamp($item['firstSeenAt'] ?? null) ?: $scan->scanned_at;
        $lastSeenAt = $this->parseTimestamp($item['lastSeenAt'] ?? null) ?: $scan->scanned_at;
        $relationship = InstagramProfileRelationship::withTrashed()
            ->where('source_instagram_profile_id', $sourceProfile->id)
            ->where('related_instagram_profile_id', $relatedProfile->id)
            ->where('list_type', $listType)
            ->first();

        if ($relationship && $relationship->trashed()) {
            $relationship->restore();
        }

        if (! $relationship) {
            $relationship = new InstagramProfileRelationship([
                'source_instagram_profile_id' => $sourceProfile->id,
                'related_instagram_profile_id' => $relatedProfile->id,
                'list_type' => $listType,
                'first_seen_scan_id' => $scan->id,
                'first_seen_at' => $firstSeenAt,
            ]);
        }

        $relationship->forceFill([
            'last_seen_scan_id' => $scan->id,
            'status' => 'active',
            'display_name_snapshot' => $item['displayName'] ?? null,
            'profile_url_snapshot' => $item['profileUrl'] ?? null,
            'first_seen_at' => $relationship->first_seen_at ?: $firstSeenAt,
            'last_seen_at' => $lastSeenAt,
            'removed_scan_id' => null,
            'removed_at' => null,
            'evidence' => [
                'last_list_scan_id' => $scan->id,
                'last_item_status' => $itemStatus,
                'last_item' => $item,
            ],
        ])->save();

        $this->createScanItem($scan, $relationship, $sourceProfile, $relatedProfile, $listType, $itemStatus, $item, $lastSeenAt);
    }

    private function markRemovedRelationship(
        InstagramProfileListScan $scan,
        InstagramProfile $sourceProfile,
        string $listType,
        array $item,
    ): void {
        $relatedProfile = $this->ensureProfile($item['username'] ?? null, [
            'display_name' => $item['displayName'] ?? null,
            'profile_url' => $item['profileUrl'] ?? null,
            'profile_image_url' => $item['profileImageUrl'] ?? $item['profile_image_url'] ?? null,
            'is_private' => $item['isPrivate'] ?? null,
            'profile_visibility' => $item['profileVisibility'] ?? null,
            'followers_count' => $item['followersCount'] ?? null,
            'following_count' => $item['followingCount'] ?? null,
            'posts_count' => $item['postsCount'] ?? null,
        ]);

        if (! $relatedProfile) {
            return;
        }

        $removedAt = $this->parseTimestamp($item['removedAt'] ?? null) ?: $scan->scanned_at;
        $relationship = InstagramProfileRelationship::withTrashed()
            ->where('source_instagram_profile_id', $sourceProfile->id)
            ->where('related_instagram_profile_id', $relatedProfile->id)
            ->where('list_type', $listType)
            ->first();

        if (! $relationship) {
            $relationship = new InstagramProfileRelationship([
                'source_instagram_profile_id' => $sourceProfile->id,
                'related_instagram_profile_id' => $relatedProfile->id,
                'list_type' => $listType,
                'first_seen_scan_id' => null,
                'first_seen_at' => $this->parseTimestamp($item['firstSeenAt'] ?? null),
            ]);
        }

        if ($relationship->trashed()) {
            $relationship->restore();
        }

        $relationship->forceFill([
            'removed_scan_id' => $scan->id,
            'status' => 'removed',
            'display_name_snapshot' => $item['displayName'] ?? null,
            'profile_url_snapshot' => $item['profileUrl'] ?? null,
            'last_seen_at' => $this->parseTimestamp($item['lastSeenAt'] ?? null) ?: $relationship->last_seen_at,
            'removed_at' => $removedAt,
            'evidence' => [
                'removed_list_scan_id' => $scan->id,
                'removed_item' => $item,
            ],
        ])->save();

        $this->createScanItem($scan, $relationship, $sourceProfile, $relatedProfile, $listType, 'removed', $item, $removedAt);
        $relationship->delete();
    }

    private function createScanItem(
        InstagramProfileListScan $scan,
        InstagramProfileRelationship $relationship,
        InstagramProfile $sourceProfile,
        InstagramProfile $relatedProfile,
        string $listType,
        string $itemStatus,
        array $item,
        ?Carbon $observedAt,
    ): void {
        InstagramProfileListScanItem::create([
            'list_scan_id' => $scan->id,
            'relationship_id' => $relationship->id,
            'source_instagram_profile_id' => $sourceProfile->id,
            'related_instagram_profile_id' => $relatedProfile->id,
            'list_type' => $listType,
            'item_status' => $itemStatus,
            'username_snapshot' => $item['username'],
            'display_name_snapshot' => $item['displayName'] ?? null,
            'profile_url_snapshot' => $item['profileUrl'] ?? null,
            'raw_item' => $item,
            'observed_at' => $observedAt ?: $scan->scanned_at,
        ]);
    }

    private function storedRelationshipListFromSnapshot(
        TrackedPersonInstagramSnapshot $snapshot,
        string $payloadKey,
    ): array {
        $rawPayload = is_array($snapshot->raw_payload) ? $snapshot->raw_payload : [];
        $summary = data_get($rawPayload, 'extractedProfile.'.$payloadKey, []);

        if (! is_array($summary) || $summary === []) {
            return [];
        }

        $decoded = [];
        $itemsPath = data_get($summary, 'itemsPath');

        if (is_string($itemsPath) && $itemsPath !== '' && Storage::disk('public')->exists($itemsPath)) {
            try {
                $decodedPayload = json_decode(Storage::disk('public')->get($itemsPath), true, flags: JSON_THROW_ON_ERROR);
                $decoded = is_array($decodedPayload) ? $decodedPayload : [];
            } catch (\Throwable) {
                $decoded = [];
            }
        }

        $relationshipList = array_replace($summary, $decoded);
        $relationshipList['attempted'] = (bool) ($summary['attempted'] ?? $decoded['attempted'] ?? false);
        $relationshipList['available'] = (bool) ($relationshipList['available'] ?? $relationshipList['attempted']);
        $relationshipList['complete'] = (bool) ($relationshipList['complete'] ?? false);

        foreach ([
            'items' => ['activeItems', 'itemsPreview'],
            'observedItems' => ['observedPreview', 'items'],
            'addedItems' => ['addedPreview'],
            'removedItems' => ['removedPreview'],
            'removedHistoryItems' => ['removedHistoryPreview'],
            'currentlyRemovedItems' => ['currentlyRemovedPreview'],
        ] as $targetKey => $fallbackKeys) {
            if (is_array($relationshipList[$targetKey] ?? null)) {
                continue;
            }

            foreach ($fallbackKeys as $fallbackKey) {
                if (is_array($relationshipList[$fallbackKey] ?? null)) {
                    $relationshipList[$targetKey] = $relationshipList[$fallbackKey];
                    break;
                }
            }

            $relationshipList[$targetKey] ??= [];
        }

        return $relationshipList;
    }

    private function profilePayload(string $username, array $attributes): array
    {
        $payload = [
            'display_name' => $this->nullableTrim($attributes['display_name'] ?? null),
            'full_name' => $this->nullableTrim($attributes['full_name'] ?? null),
            'biography' => $this->nullableTrim($attributes['biography'] ?? null),
            'profile_url' => $this->nullableTrim($attributes['profile_url'] ?? null) ?: 'https://www.instagram.com/'.$username.'/',
            'profile_image_url' => $this->nullableTrim($attributes['profile_image_url'] ?? null),
            'profile_image_path' => $this->nullableTrim($attributes['profile_image_path'] ?? null),
            'profile_image_hash' => $this->nullableTrim($attributes['profile_image_hash'] ?? null),
            'is_private' => array_key_exists('is_private', $attributes) ? $this->nullableBoolean($attributes['is_private']) : null,
            'profile_visibility' => $this->nullableTrim($attributes['profile_visibility'] ?? null),
            'followers_count' => $this->nullableInteger($attributes['followers_count'] ?? null),
            'following_count' => $this->nullableInteger($attributes['following_count'] ?? null),
            'posts_count' => $this->nullableInteger($attributes['posts_count'] ?? null),
            'last_status_level' => $this->nullableTrim($attributes['last_status_level'] ?? null),
            'last_status_message' => $this->nullableTrim($attributes['last_status_message'] ?? null),
            'last_scanned_at' => $this->parseTimestamp($attributes['last_scanned_at'] ?? null),
            'raw_profile' => $this->nullableArray($attributes['raw_profile'] ?? null),
        ];

        return array_filter($payload, static fn ($value): bool => $value !== null);
    }

    private function snapshotScanCanUpdateProfileVisibility(
        TrackedPersonInstagramSnapshot $snapshot,
        array $payload = [],
        array $attemptInfo = [],
    ): bool {
        $statusLevel = Str::lower(trim((string) ($snapshot->status_level ?: ($payload['statusLevel'] ?? ''))));

        if ($statusLevel !== 'success') {
            return false;
        }

        if (array_key_exists('ok', $payload) && ! (bool) $payload['ok']) {
            return false;
        }

        if ((bool) ($payload['gracefullyStopped'] ?? false) || (bool) ($attemptInfo['gracefully_stopped'] ?? false)) {
            return false;
        }

        foreach (($attemptInfo['phases'] ?? []) as $phase) {
            if (! is_array($phase)) {
                continue;
            }

            $phaseStatus = Str::lower(trim((string) ($phase['statusLevel'] ?? 'success')));

            if (
                $phaseStatus !== 'success'
                || (bool) ($phase['rateLimited'] ?? false)
                || (bool) ($phase['gracefullyStopped'] ?? false)
                || (bool) ($phase['listTemporarilyUnavailable'] ?? false)
            ) {
                return false;
            }
        }

        return true;
    }

    private function relationshipListScanPayload(array $relationshipList): array
    {
        return [
            'items_path' => $relationshipList['itemsPath'] ?? null,
            'reason' => $relationshipList['reason'] ?? null,
            'rate_limit_text' => $relationshipList['rateLimitText'] ?? null,
            'trimmed' => (bool) ($relationshipList['trimmed'] ?? false),
            'all_known_count' => (int) ($relationshipList['allKnownCount'] ?? 0),
            'removed_history_count' => (int) ($relationshipList['removedHistoryCount'] ?? 0),
            'currently_removed_count' => (int) ($relationshipList['currentlyRemovedCount'] ?? 0),
            'open_attempts' => (int) ($relationshipList['openAttempts'] ?? 0),
            'scroll_rounds' => (int) ($relationshipList['scrollRounds'] ?? 0),
            'search_input_available' => (bool) ($relationshipList['searchInputAvailable'] ?? false),
            'search_queries' => array_values(is_array($relationshipList['searchQueries'] ?? null) ? $relationshipList['searchQueries'] : []),
            'search_added_count' => (int) ($relationshipList['searchAddedCount'] ?? 0),
            'search_stop_reason' => $relationshipList['searchStopReason'] ?? null,
            'partitioned' => (bool) ($relationshipList['partitioned'] ?? false),
            'partition_threshold' => (int) ($relationshipList['partitionThreshold'] ?? 250),
            'partition_max_items' => (int) ($relationshipList['partitionMaxItems'] ?? 250),
        ];
    }

    private function relationshipListStatusLevel(array $relationshipList, TrackedPersonInstagramSnapshot $snapshot): string
    {
        if ((bool) ($relationshipList['rateLimited'] ?? false)) {
            return 'rate_limited';
        }

        if ((bool) ($relationshipList['gracefullyStopped'] ?? false)) {
            return 'partial';
        }

        if ((bool) ($relationshipList['available'] ?? false) || (int) ($relationshipList['observedCount'] ?? 0) > 0) {
            return (bool) ($relationshipList['complete'] ?? false) ? 'success' : 'partial';
        }

        return $snapshot->status_level ?: 'unknown';
    }

    private function relationshipListStatusMessage(
        array $relationshipList,
        TrackedPersonInstagramSnapshot $snapshot,
        string $listType,
    ): string {
        $label = $listType === 'followers' ? 'Followerliste' : 'Gefolgt-Liste';

        if ((bool) ($relationshipList['rateLimited'] ?? false)) {
            return $label.' durch Instagram Rate-Limit pausiert.';
        }

        if ((bool) ($relationshipList['gracefullyStopped'] ?? false)) {
            return $label.' wurde beendet; bisherige Eintraege wurden gespeichert.';
        }

        if ((bool) ($relationshipList['available'] ?? false)) {
            return $label.' gespeichert: '.number_format((int) ($relationshipList['observedCount'] ?? 0), 0, ',', '.').' direkt beobachtete Eintraege.';
        }

        return $snapshot->status_message ?: $label.' konnte nicht geladen werden.';
    }

    private function directRelationshipListStatusLevel(array $relationshipList, array $payload): string
    {
        if ((bool) ($relationshipList['rateLimited'] ?? false)) {
            return 'rate_limited';
        }

        if ((bool) ($relationshipList['gracefullyStopped'] ?? data_get($payload, 'gracefullyStopped', false))) {
            return 'partial';
        }

        if ((bool) ($relationshipList['available'] ?? false) || (int) ($relationshipList['observedCount'] ?? 0) > 0) {
            return (bool) ($relationshipList['complete'] ?? false) ? 'success' : 'partial';
        }

        $payloadStatus = $this->nullableTrim($payload['statusLevel'] ?? null);

        return $payloadStatus ?: 'unknown';
    }

    private function directRelationshipListStatusMessage(array $relationshipList, array $payload, string $listType): string
    {
        $label = $listType === 'followers' ? 'Followerliste' : 'Gefolgt-Liste';

        if ((bool) ($relationshipList['rateLimited'] ?? false)) {
            return $label.' durch Instagram Rate-Limit pausiert.';
        }

        if ((bool) ($relationshipList['gracefullyStopped'] ?? data_get($payload, 'gracefullyStopped', false))) {
            return $label.' wurde beendet; bisherige Eintraege wurden gespeichert.';
        }

        if ((bool) ($relationshipList['available'] ?? false)) {
            return $label.' gespeichert: '.number_format((int) ($relationshipList['observedCount'] ?? 0), 0, ',', '.').' direkt beobachtete Eintraege.';
        }

        return $this->nullableTrim($payload['statusMessage'] ?? null) ?: $label.' konnte nicht geladen werden.';
    }

    private function normalizeRelationshipItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->filter(fn ($item): bool => is_array($item) && filled($item['username'] ?? null))
            ->map(function (array $item): array {
                $username = $this->normalizeUsername($item['username'] ?? null);

                return array_filter([
                    'username' => $username,
                    'displayName' => $this->nullableTrim($item['displayName'] ?? null),
                    'profileUrl' => $this->nullableTrim($item['profileUrl'] ?? null) ?: ($username ? 'https://www.instagram.com/'.$username.'/' : null),
                    'profileImageUrl' => $this->nullableTrim($item['profileImageUrl'] ?? $item['profile_image_url'] ?? null),
                    'profileVisibility' => in_array(($item['profileVisibility'] ?? null), ['public', 'private', 'unknown'], true)
                        ? $item['profileVisibility']
                        : null,
                    'isPrivate' => is_bool($item['isPrivate'] ?? null) ? $item['isPrivate'] : null,
                    'postsCount' => $this->nullableInteger($item['postsCount'] ?? null),
                    'followersCount' => $this->nullableInteger($item['followersCount'] ?? null),
                    'followingCount' => $this->nullableInteger($item['followingCount'] ?? null),
                    'hoverCard' => is_array($item['hoverCard'] ?? null) ? $item['hoverCard'] : null,
                    'firstSeenAt' => $this->parseTimestamp($item['firstSeenAt'] ?? null)?->toIso8601String(),
                    'lastSeenAt' => $this->parseTimestamp($item['lastSeenAt'] ?? null)?->toIso8601String(),
                    'removedAt' => $this->parseTimestamp($item['removedAt'] ?? null)?->toIso8601String(),
                ], static fn ($value): bool => $value !== null);
            })
            ->filter(fn (array $item): bool => filled($item['username'] ?? null))
            ->unique('username')
            ->values()
            ->all();
    }

    private function usernameLookup(mixed $items): array
    {
        return collect($this->normalizeRelationshipItems($items))
            ->pluck('username')
            ->filter()
            ->mapWithKeys(static fn (string $username): array => [$username => true])
            ->all();
    }

    private function normalizeUsername(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $username = Str::lower(trim((string) $value));
        $username = preg_replace('/^https?:\/\/(www\.)?instagram\.com\//i', '', $username) ?? $username;
        $username = trim(ltrim($username, '@'), "/ \t\n\r\0\x0B");
        $username = preg_replace('/[?#].*$/', '', $username) ?? $username;

        if ($username === '' || ! preg_match('/^[a-z0-9._]+$/', $username)) {
            return null;
        }

        return $username;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->timezone('UTC');
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->timezone('UTC');
        }

        if (! filled($value) || is_array($value) || is_object($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->timezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool) $value;
    }

    private function nullableArray(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }

    private function isReady(): bool
    {
        return $this->ready ??= Schema::hasTable('instagram_profiles');
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table.'.'.$column;

        if (array_key_exists($key, $this->columnCache)) {
            return $this->columnCache[$key];
        }

        return $this->columnCache[$key] = Schema::hasColumn($table, $column);
    }
}
