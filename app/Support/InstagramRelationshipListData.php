<?php

namespace App\Support;

use App\Models\InstagramProfile;
use App\Models\InstagramProfileListScan;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramInferredConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        $liveItems = $this->liveProfileListItems($trackedPerson, $listType);
        $reconstructedItems = $this->reconstructedSuggestionItems($trackedPerson, $listType);

        if ($liveItems->isNotEmpty()) {
            $activeItems = $this->sortActive(
                $activeItems
                    ->merge($liveItems)
                    ->unique(fn ($item): string => Str::lower((string) data_get($item, 'username', '')))
                    ->values(),
                $addedItems,
            );
        }

        if ($reconstructedItems->isNotEmpty()) {
            $activeItems = $this->sortActive(
                $activeItems
                    ->merge($reconstructedItems)
                    ->unique(fn ($item): string => Str::lower((string) data_get($item, 'username', '')))
                    ->values(),
                $addedItems,
            );
        }

        $scanRemovedItems = $this->sortNewest($this->loadItems($relationshipList, 'removedItems'), ['removedAt', 'lastSeenAt', 'firstSeenAt']);
        $removedItems = $this->sortNewest($this->loadItems($relationshipList, 'currentlyRemovedItems'), ['removedAt', 'lastSeenAt', 'firstSeenAt']);
        $removedHistoryItems = $this->sortNewest($this->loadItems($relationshipList, 'removedHistoryItems'), ['removedAt', 'lastSeenAt', 'firstSeenAt']);
        $profileIndex = $this->profileIndex(
            collect()
                ->merge($addedItems)
                ->merge($activeItems)
                ->merge($scanRemovedItems)
                ->merge($removedItems)
                ->merge($removedHistoryItems),
            $trackedPerson,
        );

        $addedItems = $this->enrichItems($addedItems, $profileIndex);
        $activeItems = $this->enrichItems($activeItems, $profileIndex);
        $scanRemovedItems = $this->enrichItems($scanRemovedItems, $profileIndex);
        $removedItems = $this->enrichItems($removedItems, $profileIndex);
        $removedHistoryItems = $this->enrichItems($removedHistoryItems, $profileIndex);
        $stats = $this->stats($relationshipList, $activeItems);
        $stats['activeCount'] = max($stats['activeCount'], $activeItems->count());
        $stats['observedCount'] = max($stats['observedCount'], $activeItems->count());

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
            'stats' => $stats,
            'available' => $activeItems->isNotEmpty()
                || $addedItems->isNotEmpty()
                || $scanRemovedItems->isNotEmpty()
                || $removedItems->isNotEmpty()
                || $removedHistoryItems->isNotEmpty(),
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

    private function liveProfileListItems(TrackedPerson $trackedPerson, string $listType): Collection
    {
        if (! $trackedPerson->current_instagram_profile_id || ! in_array($listType, ['followers', 'following'], true)) {
            return collect();
        }

        $scan = InstagramProfileListScan::query()
            ->where('instagram_profile_id', $trackedPerson->current_instagram_profile_id)
            ->where('list_type', $listType)
            ->where('scan_mode', 'profile_list_live')
            ->where(function ($query) use ($trackedPerson): void {
                $query->where('tracked_person_id', $trackedPerson->id)
                    ->orWhere('user_id', $trackedPerson->user_id);
            })
            ->latest('scanned_at')
            ->with(['items.relatedInstagramProfile'])
            ->first();

        if (! $scan) {
            return collect();
        }

        return $scan->items
            ->whereIn('item_status', ['observed', 'added'])
            ->map(function ($item): array {
                $raw = is_array($item->raw_item) ? $item->raw_item : [];
                $related = $item->relatedInstagramProfile;

                return [
                    ...$raw,
                    'username' => $raw['username'] ?? $item->username_snapshot,
                    'displayName' => $raw['displayName'] ?? $item->display_name_snapshot,
                    'profileUrl' => $raw['profileUrl'] ?? $item->profile_url_snapshot,
                    'instagramProfileId' => $related?->id,
                    'profileImagePath' => $related?->profile_image_path,
                    'profileVisibility' => $raw['profileVisibility'] ?? $related?->profile_visibility,
                    'isPrivate' => array_key_exists('isPrivate', $raw) ? $raw['isPrivate'] : $related?->is_private,
                    'postsCount' => $raw['postsCount'] ?? $related?->posts_count,
                    'followersCount' => $raw['followersCount'] ?? $related?->followers_count,
                    'followingCount' => $raw['followingCount'] ?? $related?->following_count,
                    'firstSeenAt' => $raw['firstSeenAt'] ?? $item->observed_at?->toIso8601String(),
                    'lastSeenAt' => $raw['lastSeenAt'] ?? $item->observed_at?->toIso8601String(),
                ];
            })
            ->filter(fn (array $item): bool => filled($item['username'] ?? null))
            ->values();
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

    private function profileIndex(Collection $items, TrackedPerson $trackedPerson): Collection
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
        $listScanStatuses = InstagramListScanStatus::forProfileIds($profileIds, (int) $trackedPerson->user_id);
        $trackedByProfileId = $trackedPerson->newQuery()
            ->where('user_id', $trackedPerson->user_id)
            ->whereIn('current_instagram_profile_id', $profileIds->all())
            ->get(['id', 'current_instagram_profile_id', 'instagram_username'])
            ->keyBy('current_instagram_profile_id');
        $trackedByUsername = $trackedPerson->newQuery()
            ->where('user_id', $trackedPerson->user_id)
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

}
