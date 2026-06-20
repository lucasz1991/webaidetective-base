<?php

namespace App\Support;

use App\Models\InstagramProfile;
use App\Models\InstagramProfileListScan;
use App\Models\InstagramProfileRelationship;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramInferredConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstagramRelationshipListData
{
    public function forTrackedPerson(TrackedPerson $trackedPerson, string $listType): array
    {
        $listType = $listType === 'following' ? 'following' : 'followers';
        $payloadKey = $listType === 'followers' ? 'followersList' : 'followingList';
        $relationshipList = data_get($trackedPerson->latestInstagramSnapshot?->raw_payload, 'extractedProfile.'.$payloadKey, []);

        $addedItems = $this->sortNewest($this->loadItems($relationshipList, 'addedItems'));
        $activeItems = $this->sortActive($this->loadItems($relationshipList), $addedItems);
        $profileListData = $this->profileListScanDataForTrackedPerson($trackedPerson, $listType);
        $reconstructedItems = $this->reconstructedSuggestionItems($trackedPerson, $listType);
        $passiveItems = $this->passiveRelationshipItems($trackedPerson, $listType);

        if ($profileListData['addedItems']->isNotEmpty()) {
            $addedItems = $this->sortNewest($this->mergeItemsByUsername($addedItems, $profileListData['addedItems']));
        }

        if ($profileListData['activeItems']->isNotEmpty()) {
            $activeItems = $this->sortActive($this->mergeItemsByUsername($activeItems, $profileListData['activeItems']), $addedItems);
        }

        if ($reconstructedItems->isNotEmpty()) {
            $activeItems = $this->sortActive($this->mergeItemsByUsername($activeItems, $reconstructedItems), $addedItems);
        }

        if ($passiveItems->isNotEmpty()) {
            $activeItems = $this->sortActive($this->mergeItemsByUsername($activeItems, $passiveItems), $addedItems);
        }

        $scanRemovedItems = $this->sortNewest(
            $this->mergeItemsByUsername($this->loadItems($relationshipList, 'removedItems'), $profileListData['scanRemovedItems']),
            ['removedAt', 'lastSeenAt', 'firstSeenAt', 'observedAt'],
        );
        $removedItems = $this->sortNewest($this->loadItems($relationshipList, 'currentlyRemovedItems'), ['removedAt', 'lastSeenAt', 'firstSeenAt']);
        $removedHistoryItems = $this->sortNewest($this->loadItems($relationshipList, 'removedHistoryItems'), ['removedAt', 'lastSeenAt', 'firstSeenAt']);
        $profileIndex = $this->profileIndex(
            collect()
                ->merge($addedItems)
                ->merge($activeItems)
                ->merge($passiveItems)
                ->merge($scanRemovedItems)
                ->merge($removedItems)
                ->merge($removedHistoryItems),
            (int) $trackedPerson->user_id,
        );

        $addedItems = $this->enrichItems($addedItems, $profileIndex);
        $activeItems = $this->enrichItems($activeItems, $profileIndex);
        $reconstructedItems = $this->enrichItems($reconstructedItems, $profileIndex);
        $passiveItems = $this->enrichItems($passiveItems, $profileIndex);
        $scanRemovedItems = $this->enrichItems($scanRemovedItems, $profileIndex);
        $removedItems = $this->enrichItems($removedItems, $profileIndex);
        $removedHistoryItems = $this->enrichItems($removedHistoryItems, $profileIndex);
        $stats = $this->stats($relationshipList, $activeItems);
        $stats['activeCount'] = max($stats['activeCount'], $activeItems->count());
        $stats['observedCount'] = max($stats['observedCount'], $activeItems->count());
        $stats['allKnownCount'] = max($stats['allKnownCount'], $activeItems->count());

        return [
            'listType' => $listType,
            'title' => $listType === 'followers' ? 'Followerliste' : 'Gefolgt-Liste',
            'activeTitle' => $listType === 'followers' ? 'Aktive und ungeklaerte Follower' : 'Aktive und ungeklaerte Gefolgt-Profile',
            'searchPlaceholder' => ($listType === 'followers' ? 'Followerliste' : 'Gefolgt-Liste').' durchsuchen...',
            'emptyText' => 'Keine '.($listType === 'followers' ? 'Followerliste' : 'Gefolgt-Liste').' gespeichert.',
            'relationshipList' => is_array($relationshipList) ? $relationshipList : [],
            'addedItems' => $addedItems,
            'activeItems' => $activeItems,
            'scanRemovedItems' => $scanRemovedItems,
            'removedItems' => $removedItems,
            'removedHistoryItems' => $removedHistoryItems,
            'reconstructedItems' => $reconstructedItems,
            'passiveItems' => $passiveItems,
            'modalItems' => $this->modalItems($activeItems, $scanRemovedItems, $removedItems, $removedHistoryItems),
            'scan' => $profileListData['scan'],
            'stats' => $stats,
            'available' => $activeItems->isNotEmpty()
                || $addedItems->isNotEmpty()
                || $scanRemovedItems->isNotEmpty()
                || $removedItems->isNotEmpty()
                || $removedHistoryItems->isNotEmpty(),
        ];
    }

    public function forInstagramProfile(InstagramProfile $profile, int $userId, string $listType, ?TrackedPerson $trackedPerson = null): array
    {
        $listType = $listType === 'following' ? 'following' : 'followers';
        $profileListData = $this->profileListScanData($profile, $userId, $listType, $trackedPerson?->id);
        $addedItems = $profileListData['addedItems'];
        $activeItems = $profileListData['activeItems'];
        $scanRemovedItems = $profileListData['scanRemovedItems'];
        $removedItems = collect();
        $removedHistoryItems = collect();
        $reconstructedItems = $trackedPerson
            ? $this->reconstructedSuggestionItems($trackedPerson, $listType)
            : collect();
        $passiveItems = $this->passiveRelationshipItemsForTarget(
            $this->targetProfileIdsForProfile($profile),
            $this->normalizeUsername($profile->username),
            $userId,
            $trackedPerson?->id,
            $listType,
        );

        if ($reconstructedItems->isNotEmpty()) {
            $activeItems = $this->sortActive($this->mergeItemsByUsername($activeItems, $reconstructedItems), $addedItems);
        }

        if ($passiveItems->isNotEmpty()) {
            $activeItems = $this->sortActive($this->mergeItemsByUsername($activeItems, $passiveItems), $addedItems);
        }

        $relationshipList = $profileListData['relationshipList'];
        $profileIndex = $this->profileIndex(
            collect()
                ->merge($addedItems)
                ->merge($activeItems)
                ->merge($passiveItems)
                ->merge($scanRemovedItems),
            $userId,
        );

        $addedItems = $this->enrichItems($addedItems, $profileIndex);
        $activeItems = $this->enrichItems($activeItems, $profileIndex);
        $reconstructedItems = $this->enrichItems($reconstructedItems, $profileIndex);
        $passiveItems = $this->enrichItems($passiveItems, $profileIndex);
        $scanRemovedItems = $this->enrichItems($scanRemovedItems, $profileIndex);
        $stats = $this->stats($relationshipList, $activeItems);
        $stats['activeCount'] = max($stats['activeCount'], $activeItems->count());
        $stats['observedCount'] = max($stats['observedCount'], $activeItems->count());
        $stats['allKnownCount'] = max($stats['allKnownCount'], $activeItems->count());

        return [
            'listType' => $listType,
            'title' => $listType === 'followers' ? 'Followerliste' : 'Gefolgt-Liste',
            'relationshipList' => $relationshipList,
            'addedItems' => $addedItems,
            'activeItems' => $activeItems,
            'scanRemovedItems' => $scanRemovedItems,
            'removedItems' => $removedItems,
            'removedHistoryItems' => $removedHistoryItems,
            'reconstructedItems' => $reconstructedItems,
            'passiveItems' => $passiveItems,
            'modalItems' => $this->modalItems($activeItems, $scanRemovedItems, $removedItems, $removedHistoryItems),
            'scan' => $profileListData['scan'],
            'stats' => $stats,
            'available' => $activeItems->isNotEmpty()
                || $addedItems->isNotEmpty()
                || $scanRemovedItems->isNotEmpty(),
        ];
    }

    private function loadItems(mixed $relationshipList, string $key = 'items'): Collection
    {
        if (! is_array($relationshipList)) {
            return collect();
        }

        $items = collect(data_get($relationshipList, $key, []));
        $itemsPath = data_get($relationshipList, 'itemsPath');

        if ($items->isNotEmpty() || ! is_string($itemsPath) || $itemsPath === '') {
            return $items;
        }

        try {
            if (! Storage::disk('public')->exists($itemsPath)) {
                return collect();
            }

            $decoded = json_decode(Storage::disk('public')->get($itemsPath), true);

            return collect(data_get($decoded, $key, []));
        } catch (\Throwable) {
            return collect();
        }
    }

    private function profileListScanDataForTrackedPerson(TrackedPerson $trackedPerson, string $listType): array
    {
        $profile = $this->targetProfileForTrackedPerson($trackedPerson);

        if (! $profile) {
            return $this->emptyProfileListScanData();
        }

        return $this->profileListScanData($profile, (int) $trackedPerson->user_id, $listType, (int) $trackedPerson->id);
    }

    private function profileListScanData(InstagramProfile $profile, int $userId, string $listType, ?int $trackedPersonId = null): array
    {
        $scan = $this->latestProfileListScan($profile, $userId, $listType, $trackedPersonId);

        if (! $scan) {
            return $this->emptyProfileListScanData();
        }

        $items = $this->profileListScanItems($scan);
        $addedItems = $this->sortNewest(
            $items->where('itemStatus', 'added')->values(),
            ['firstSeenAt', 'lastSeenAt', 'observedAt'],
        );
        $activeItems = $this->sortActive(
            $items->filter(fn (array $item): bool => in_array($item['itemStatus'] ?? null, ['observed', 'added'], true))->values(),
            $addedItems,
        );
        $scanRemovedItems = $this->sortNewest(
            $items->where('itemStatus', 'removed')->values(),
            ['removedAt', 'lastSeenAt', 'firstSeenAt', 'observedAt'],
        );

        return [
            'scan' => $scan,
            'relationshipList' => $this->relationshipListFromScan($scan),
            'addedItems' => $addedItems,
            'activeItems' => $activeItems,
            'scanRemovedItems' => $scanRemovedItems,
        ];
    }

    private function latestProfileListScan(InstagramProfile $profile, int $userId, string $listType, ?int $trackedPersonId = null): ?InstagramProfileListScan
    {
        $username = $this->normalizeUsername($profile->username);

        return InstagramProfileListScan::query()
            ->where(function ($query) use ($profile, $username): void {
                $query->where('instagram_profile_id', $profile->id);

                if ($username !== null && Schema::hasColumn('instagram_profile_list_scans', 'instagram_username')) {
                    $query->orWhere('instagram_username', $username);
                }
            })
            ->where('list_type', $listType)
            ->where(function ($query) use ($userId, $trackedPersonId): void {
                $query->where('user_id', $userId);

                if ($trackedPersonId) {
                    $query->orWhere('tracked_person_id', $trackedPersonId);
                }
            })
            ->with(['items.relatedInstagramProfile'])
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();
    }

    private function profileListScanItems(InstagramProfileListScan $scan): Collection
    {
        return $scan->items
            ->map(function ($item): array {
                $raw = is_array($item->raw_item) ? $item->raw_item : [];
                $related = $item->relatedInstagramProfile;
                $itemStatus = (string) ($item->item_status ?: 'observed');

                return [
                    ...$raw,
                    'username' => $raw['username'] ?? $item->username_snapshot,
                    'displayName' => $raw['displayName'] ?? $item->display_name_snapshot,
                    'profileUrl' => $raw['profileUrl'] ?? $item->profile_url_snapshot,
                    'instagramProfileId' => $related?->id ?: $item->related_instagram_profile_id,
                    'profileImagePath' => $related?->profile_image_path,
                    'profileVisibility' => $raw['profileVisibility'] ?? $related?->profile_visibility,
                    'isPrivate' => array_key_exists('isPrivate', $raw) ? $raw['isPrivate'] : $related?->is_private,
                    'postsCount' => $raw['postsCount'] ?? $related?->posts_count,
                    'followersCount' => $raw['followersCount'] ?? $related?->followers_count,
                    'followingCount' => $raw['followingCount'] ?? $related?->following_count,
                    'firstSeenAt' => $raw['firstSeenAt'] ?? $item->observed_at?->toIso8601String(),
                    'lastSeenAt' => $raw['lastSeenAt'] ?? $item->observed_at?->toIso8601String(),
                    'observedAt' => $item->observed_at?->toIso8601String(),
                    'itemStatus' => $itemStatus,
                    'statusLabel' => match ($itemStatus) {
                        'added' => 'Neu',
                        'removed' => 'Entfernt',
                        default => null,
                    },
                    'statusTone' => match ($itemStatus) {
                        'added' => 'emerald',
                        'removed' => 'rose',
                        default => 'slate',
                    },
                ];
            })
            ->filter(fn (array $item): bool => filled($item['username'] ?? null))
            ->values();
    }

    private function relationshipListFromScan(InstagramProfileListScan $scan): array
    {
        $payload = is_array($scan->raw_payload) ? $scan->raw_payload : [];

        return [
            ...$payload,
            'attempted' => (bool) $scan->attempted,
            'available' => (bool) $scan->available,
            'complete' => (bool) $scan->complete,
            'rateLimited' => (bool) $scan->rate_limited,
            'gracefullyStopped' => (bool) $scan->gracefully_stopped,
            'expectedCount' => $scan->expected_count,
            'observedCount' => (int) $scan->observed_count,
            'activeCount' => (int) $scan->active_count,
            'knownCount' => (int) $scan->known_count,
            'addedCount' => (int) $scan->added_count,
            'removedCount' => (int) $scan->removed_count,
            'scanId' => $scan->id,
            'scannedAt' => $scan->scanned_at?->toIso8601String(),
        ];
    }

    private function emptyProfileListScanData(): array
    {
        return [
            'scan' => null,
            'relationshipList' => [],
            'addedItems' => collect(),
            'activeItems' => collect(),
            'scanRemovedItems' => collect(),
        ];
    }

    private function passiveRelationshipItems(TrackedPerson $trackedPerson, string $listType): Collection
    {
        $targetProfileIds = $this->targetProfileIds($trackedPerson);

        if ($targetProfileIds->isEmpty() || ! in_array($listType, ['followers', 'following'], true)) {
            return collect();
        }

        $targetUsername = $this->normalizeUsername($trackedPerson->instagram_username)
            ?: $this->normalizeUsername(InstagramProfile::withTrashed()
                ->whereKey($targetProfileIds->first())
                ->value('username'));

        return $this->passiveRelationshipItemsForTarget(
            $targetProfileIds,
            $targetUsername,
            (int) $trackedPerson->user_id,
            (int) $trackedPerson->id,
            $listType,
        );
    }

    private function passiveRelationshipItemsForTarget(
        Collection $targetProfileIds,
        ?string $targetUsername,
        int $userId,
        ?int $trackedPersonId,
        string $listType,
    ): Collection {
        if ($targetProfileIds->isEmpty() || ! in_array($listType, ['followers', 'following'], true)) {
            return collect();
        }

        $sourceListType = $listType === 'followers' ? 'following' : 'followers';
        $targetHandle = $targetUsername ? '@'.$targetUsername : 'Zielprofil';

        return InstagramProfileRelationship::query()
            ->whereIn('related_instagram_profile_id', $targetProfileIds->all())
            ->whereNotIn('source_instagram_profile_id', $targetProfileIds->all())
            ->where('list_type', $sourceListType)
            ->where('status', 'active')
            ->whereNull('removed_at')
            ->where(fn ($query) => $this->scopeRelationshipToUser($query, $userId, $trackedPersonId))
            ->with('sourceInstagramProfile')
            ->latest('last_seen_at')
            ->get()
            ->map(function (InstagramProfileRelationship $relationship) use ($listType, $sourceListType, $targetHandle): ?array {
                $profile = $relationship->sourceInstagramProfile;
                $username = ltrim((string) $profile?->username, '@');

                if ($username === '') {
                    return null;
                }

                $candidateHandle = '@'.$username;
                $meta = $listType === 'followers'
                    ? 'Passiv erkannt: '.$candidateHandle.' folgt '.$targetHandle
                    : 'Passiv erkannt: '.$targetHandle.' folgt '.$candidateHandle;

                return [
                    'username' => $username,
                    'displayName' => $profile?->display_name ?: $profile?->full_name,
                    'profileUrl' => $profile?->profile_url,
                    'instagramProfileId' => $profile?->id,
                    'profileImagePath' => $profile?->profile_image_path,
                    'profileVisibility' => $profile?->profile_visibility,
                    'isPrivate' => $profile?->is_private,
                    'postsCount' => $profile?->posts_count,
                    'followersCount' => $profile?->followers_count,
                    'followingCount' => $profile?->following_count,
                    'firstSeenAt' => $relationship->first_seen_at?->toIso8601String(),
                    'lastSeenAt' => $relationship->last_seen_at?->toIso8601String(),
                    'meta' => $meta,
                    'statusLabel' => $listType === 'followers' ? 'Passiver Follower' : 'Passiv gefolgt',
                    'statusTone' => 'sky',
                    'itemStatus' => 'observed',
                    'passive' => true,
                    'passiveSourceListType' => $sourceListType,
                    'passiveRelationshipId' => $relationship->id,
                ];
            })
            ->filter()
            ->unique(fn (array $item): string => Str::lower((string) $item['username']))
            ->values();
    }

    private function targetProfileForTrackedPerson(TrackedPerson $trackedPerson): ?InstagramProfile
    {
        $targetProfileIds = $this->targetProfileIds($trackedPerson);

        if ($targetProfileIds->isEmpty()) {
            return null;
        }

        return InstagramProfile::withTrashed()
            ->whereKey($targetProfileIds->first())
            ->first();
    }

    private function targetProfileIds(TrackedPerson $trackedPerson): Collection
    {
        $ids = collect([(int) $trackedPerson->current_instagram_profile_id])
            ->filter(fn (int $id): bool => $id > 0);
        $username = $this->normalizeUsername($trackedPerson->instagram_username);

        if ($username !== null) {
            $ids = $ids->merge(
                InstagramProfile::withTrashed()
                    ->where('username', $username)
                    ->pluck('id'),
            );
        }

        return $ids
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    private function targetProfileIdsForProfile(InstagramProfile $profile): Collection
    {
        $ids = collect([(int) $profile->id])
            ->filter(fn (int $id): bool => $id > 0);
        $username = $this->normalizeUsername($profile->username);

        if ($username !== null) {
            $ids = $ids->merge(
                InstagramProfile::withTrashed()
                    ->where('username', $username)
                    ->pluck('id'),
            );
        }

        return $ids
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    private function scopeRelationshipToUser($query, int $userId, ?int $trackedPersonId = null): void
    {
        $query
            ->whereHas('lastSeenScan', fn ($scan) => $scan->where('user_id', $userId))
            ->orWhereHas('firstSeenScan', fn ($scan) => $scan->where('user_id', $userId))
            ->orWhereHas('scanItems.listScan', fn ($scan) => $scan->where('user_id', $userId))
            ->orWhere('evidence->user_id', $userId);

        if ($trackedPersonId) {
            $query->orWhere('evidence->tracked_person_id', $trackedPersonId);
        }
    }

    private function reconstructedSuggestionItems(TrackedPerson $trackedPerson, string $listType): Collection
    {
        $relationshipType = $listType === 'followers' ? 'follows_target' : 'followed_by_target';

        return TrackedPersonInstagramInferredConnection::query()
            ->where('tracked_person_id', $trackedPerson->id)
            ->where('user_id', $trackedPerson->user_id)
            ->where('relationship_type', $relationshipType)
            ->where('status', 'active')
            ->with('candidateInstagramProfile')
            ->latest('last_seen_at')
            ->get()
            ->filter(function (TrackedPersonInstagramInferredConnection $connection): bool {
                $sourceLists = is_array($connection->source_lists) ? $connection->source_lists : [];

                return (bool) data_get($connection->evidence, 'fromSuggestionScan', false)
                    || filled(data_get($connection->evidence, 'suggestionScanId'))
                    || in_array('suggestion_scan_public_lists', $sourceLists, true);
            })
            ->unique(fn (TrackedPersonInstagramInferredConnection $connection): string => Str::lower((string) $connection->candidate_username))
            ->map(function (TrackedPersonInstagramInferredConnection $connection) use ($relationshipType): array {
                $profile = $connection->candidateInstagramProfile;
                $sourceUsername = ltrim((string) $connection->source_public_username, '@');
                $originLabel = data_get($connection->evidence, 'relationship_origin_label');
                $meta = filled($originLabel)
                    ? (string) $originLabel
                    : ($sourceUsername !== '' ? 'Rekonstruiert aus Vorschlaege-Scan ueber @'.$sourceUsername : 'Rekonstruiert aus Vorschlaege-Scan');

                return [
                    'username' => $connection->candidate_username,
                    'displayName' => $connection->candidate_display_name ?: $profile?->display_name,
                    'profileUrl' => $connection->candidate_profile_url ?: $profile?->profile_url,
                    'instagramProfileId' => $profile?->id,
                    'profileImagePath' => $profile?->profile_image_path,
                    'profileVisibility' => $profile?->profile_visibility,
                    'isPrivate' => $profile?->is_private,
                    'postsCount' => $profile?->posts_count,
                    'followersCount' => $profile?->followers_count,
                    'followingCount' => $profile?->following_count,
                    'firstSeenAt' => $connection->first_seen_at?->toIso8601String(),
                    'lastSeenAt' => $connection->last_seen_at?->toIso8601String(),
                    'meta' => $meta,
                    'statusLabel' => $relationshipType === 'follows_target' ? 'Rekonstruierter Follower' : 'Rekonstruiert gefolgt',
                    'statusTone' => 'amber',
                    'reconstructed' => true,
                ];
            })
            ->values();
    }

    private function profileIndex(Collection $items, int $userId): Collection
    {
        $usernames = $items
            ->map(fn ($item): string => Str::lower(ltrim(trim((string) data_get($item, 'username', data_get($item, 'username_snapshot', ''))), '@')))
            ->filter()
            ->unique()
            ->values();

        if ($usernames->isEmpty()) {
            return collect();
        }

        $profiles = InstagramProfile::withTrashed()
            ->whereIn('username', $usernames->all())
            ->get([
                'id',
                'username',
                'display_name',
                'full_name',
                'profile_url',
                'profile_image_path',
                'is_private',
                'profile_visibility',
                'posts_count',
                'followers_count',
                'following_count',
            ])
            ->keyBy(fn (InstagramProfile $profile): string => Str::lower((string) $profile->username));

        $profileIds = $profiles->pluck('id')->filter()->values();
        $listScanStatuses = InstagramListScanStatus::forProfileIds($profileIds, $userId);
        $trackedByProfileId = TrackedPerson::query()
            ->where('user_id', $userId)
            ->whereIn('current_instagram_profile_id', $profileIds->all())
            ->get(['id', 'current_instagram_profile_id', 'instagram_username'])
            ->keyBy('current_instagram_profile_id');
        $trackedByUsername = TrackedPerson::query()
            ->where('user_id', $userId)
            ->whereIn('instagram_username', $usernames->all())
            ->get(['id', 'current_instagram_profile_id', 'instagram_username'])
            ->keyBy(fn (TrackedPerson $person): string => Str::lower(ltrim((string) $person->instagram_username, '@')));

        return $profiles->map(function (InstagramProfile $profile) use ($trackedByProfileId, $trackedByUsername, $listScanStatuses): array {
            $username = Str::lower((string) $profile->username);
            $tracked = $trackedByProfileId->get($profile->id) ?: $trackedByUsername->get($username);

            return [
                'id' => $profile->id,
                'username' => $profile->username,
                'displayName' => $profile->display_name ?: $profile->full_name,
                'profileUrl' => $profile->profile_url,
                'profileImagePath' => $profile->profile_image_path,
                'profileVisibility' => $profile->profile_visibility,
                'isPrivate' => $profile->is_private,
                'postsCount' => $profile->posts_count,
                'followersCount' => $profile->followers_count,
                'followingCount' => $profile->following_count,
                'trackedPersonId' => $tracked?->id,
                'isTracked' => (bool) $tracked,
                'listScanStatuses' => $listScanStatuses[(int) $profile->id] ?? InstagramListScanStatus::defaultStatuses(),
            ];
        });
    }

    private function enrichItems(Collection $items, Collection $profileIndex): Collection
    {
        return $items
            ->map(function ($item) use ($profileIndex) {
                $username = Str::lower(ltrim(trim((string) data_get($item, 'username', data_get($item, 'username_snapshot', ''))), '@'));
                $profile = $profileIndex->get($username);

                if (! $profile) {
                    return $item;
                }

                $raw = is_array($item) ? $item : (array) $item;

                return [
                    ...$raw,
                    'username' => $raw['username'] ?? $profile['username'],
                    'displayName' => $raw['displayName'] ?? $profile['displayName'],
                    'profileUrl' => $raw['profileUrl'] ?? $profile['profileUrl'],
                    'instagramProfileId' => $raw['instagramProfileId'] ?? $profile['id'],
                    'profileImagePath' => $profile['profileImagePath'],
                    'profileVisibility' => $raw['profileVisibility'] ?? $profile['profileVisibility'],
                    'isPrivate' => array_key_exists('isPrivate', $raw) ? $raw['isPrivate'] : $profile['isPrivate'],
                    'postsCount' => $raw['postsCount'] ?? $profile['postsCount'],
                    'followersCount' => $raw['followersCount'] ?? $profile['followersCount'],
                    'followingCount' => $raw['followingCount'] ?? $profile['followingCount'],
                    'trackedPersonId' => $raw['trackedPersonId'] ?? $profile['trackedPersonId'],
                    'isTracked' => $raw['isTracked'] ?? $profile['isTracked'],
                    'listScanStatuses' => $raw['listScanStatuses'] ?? $profile['listScanStatuses'],
                ];
            })
            ->values();
    }

    private function mergeItemsByUsername(Collection $baseItems, Collection $incomingItems): Collection
    {
        $merged = [];
        $missingUsernameIndex = 0;

        foreach ($baseItems->toBase()->concat($incomingItems->toBase()) as $item) {
            $raw = is_array($item) ? $item : (array) $item;
            $username = $this->normalizeUsername(data_get($raw, 'username', data_get($raw, 'username_snapshot', '')));
            $key = $username ?: '__missing_username_'.(++$missingUsernameIndex);

            if (! isset($merged[$key])) {
                $merged[$key] = $raw;

                continue;
            }

            $overlay = array_filter($raw, static fn ($value): bool => $value !== null && $value !== '');
            $mergedItem = [
                ...$merged[$key],
                ...$overlay,
            ];

            foreach (['isTracked', 'passive', 'reconstructed'] as $flag) {
                if (array_key_exists($flag, $merged[$key]) || array_key_exists($flag, $raw)) {
                    $mergedItem[$flag] = (bool) ($merged[$key][$flag] ?? false) || (bool) ($raw[$flag] ?? false);
                }
            }

            $merged[$key] = $mergedItem;
        }

        return collect(array_values($merged));
    }

    private function modalItems(Collection $activeItems, Collection ...$otherItemGroups): Collection
    {
        $items = collect()->merge($activeItems);

        foreach ($otherItemGroups as $itemGroup) {
            $items = $items->merge($itemGroup);
        }

        return $items->values();
    }

    private function stats(mixed $relationshipList, Collection $items): array
    {
        $relationshipList = is_array($relationshipList) ? $relationshipList : [];

        return [
            'activeCount' => (int) data_get($relationshipList, 'activeCount', data_get($relationshipList, 'count', $items->count())),
            'observedCount' => (int) data_get($relationshipList, 'observedCount', $items->count()),
            'allKnownCount' => (int) data_get($relationshipList, 'allKnownCount', data_get($relationshipList, 'knownCount', $items->count())),
            'currentlyRemovedCount' => (int) data_get($relationshipList, 'currentlyRemovedCount', 0),
            'removedHistoryCount' => (int) data_get($relationshipList, 'removedHistoryCount', 0),
        ];
    }

    private function sortNewest(Collection $items, array $keys = ['firstSeenAt', 'lastSeenAt', 'removedAt']): Collection
    {
        return $items
            ->values()
            ->sortByDesc(fn ($item, $index) => sprintf('%020d.%06d', $this->timestamp($item, $keys), 999999 - $index))
            ->values();
    }

    private function sortActive(Collection $items, Collection $addedItems): Collection
    {
        $addedUsernames = $addedItems
            ->pluck('username')
            ->filter()
            ->map(fn ($username) => Str::lower((string) $username))
            ->flip();

        return $items
            ->values()
            ->sortByDesc(function ($item, $index) use ($addedUsernames) {
                $username = Str::lower((string) data_get($item, 'username', ''));
                $isAdded = $addedUsernames->has($username) ? 1 : 0;
                $timestamp = $this->timestamp($item, ['firstSeenAt', 'lastSeenAt', 'removedAt']);

                return sprintf('%d.%020d.%06d', $isAdded, $timestamp, 999999 - $index);
            })
            ->values();
    }

    private function timestamp(mixed $item, array $keys = ['firstSeenAt', 'lastSeenAt', 'removedAt']): int
    {
        foreach ($keys as $key) {
            $value = data_get($item, $key);

            if (! filled($value)) {
                continue;
            }

            try {
                return Carbon::parse($value)->timestamp;
            } catch (\Throwable) {
                continue;
            }
        }

        return 0;
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

        return $username !== '' ? $username : null;
    }
}
