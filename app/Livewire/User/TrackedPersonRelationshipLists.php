<?php

namespace App\Livewire\User;

use App\Models\TrackedPerson;
use App\Support\InstagramRelationshipListData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

class TrackedPersonRelationshipLists extends Component
{
    use WithoutUrlPagination;
    use WithPagination;

    private const LIST_ITEMS_PER_PAGE = 50;

    public int $trackedPersonId;

    public bool $showFollowersModal = false;

    public bool $showFollowingModal = false;

    public array $relationshipVisibilityFilters = [
        'followers' => 'all',
        'following' => 'all',
    ];

    public array $relationshipProfileFilters = [
        'followers' => 'all',
        'following' => 'all',
    ];

    public array $relationshipSorts = [
        'followers' => 'default',
        'following' => 'default',
    ];

    public function mount(int $trackedPersonId): void
    {
        $this->trackedPersonId = $trackedPersonId;
    }

    #[On('open-tracked-person-relationship-list')]
    public function openList(string $listType): void
    {
        if ($listType === 'followers') {
            $this->resetPage($this->relationshipPageName('followers'));
            $this->showFollowersModal = true;
        }

        if ($listType === 'following') {
            $this->resetPage($this->relationshipPageName('following'));
            $this->showFollowingModal = true;
        }
    }

    public function loadMoreRelationshipList(string $listType): void
    {
        if (! in_array($listType, ['followers', 'following'], true)) {
            return;
        }

        $this->nextPage($this->relationshipPageName($listType));
    }

    public function setRelationshipVisibilityFilter(string $listType, string $filter): void
    {
        if (! in_array($listType, ['followers', 'following'], true)) {
            return;
        }

        $this->relationshipVisibilityFilters[$listType] = in_array($filter, ['all', 'public', 'private', 'unknown'], true)
            ? $filter
            : 'all';

        $this->resetPage($this->relationshipPageName($listType));
    }

    public function setRelationshipProfileFilter(string $listType, string $filter): void
    {
        if (! in_array($listType, ['followers', 'following'], true)) {
            return;
        }

        $this->relationshipProfileFilters[$listType] = in_array($filter, ['all', 'tracked', 'untracked', 'reconstructed', 'passive'], true)
            ? $filter
            : 'all';

        $this->resetPage($this->relationshipPageName($listType));
    }

    public function setRelationshipSort(string $listType, string $sort): void
    {
        if (! in_array($listType, ['followers', 'following'], true)) {
            return;
        }

        $this->relationshipSorts[$listType] = in_array($sort, ['default', 'username', 'newest', 'followers', 'following', 'posts', 'visibility'], true)
            ? $sort
            : 'default';

        $this->resetPage($this->relationshipPageName($listType));
    }

    public function resetRelationshipControls(string $listType): void
    {
        if (! in_array($listType, ['followers', 'following'], true)) {
            return;
        }

        $this->relationshipVisibilityFilters[$listType] = 'all';
        $this->relationshipProfileFilters[$listType] = 'all';
        $this->relationshipSorts[$listType] = 'default';

        $this->resetPage($this->relationshipPageName($listType));
    }

    #[On('tracked-person-refresh')]
    public function refreshLists(): void
    {
    }

    public function render()
    {
        $relationshipLists = app(InstagramRelationshipListData::class);
        $trackedPerson = $this->resolveTrackedPerson()->loadMissing('latestInstagramSnapshot');
        $latestProfileVisibility = data_get($trackedPerson->latestInstagramSnapshot?->raw_payload, 'extractedProfile.profileVisibility');

        return view('livewire.user.tracked-person-relationship-lists', [
            'trackedPerson' => $trackedPerson,
            'latestProfileIsPublic' => $latestProfileVisibility === 'public',
            'followersListData' => $this->paginateRelationshipListData(
                $this->applyRelationshipControls($relationshipLists->forTrackedPerson($trackedPerson, 'followers')),
            ),
            'followingListData' => $this->paginateRelationshipListData(
                $this->applyRelationshipControls($relationshipLists->forTrackedPerson($trackedPerson, 'following')),
            ),
        ]);
    }

    private function applyRelationshipControls(array $listData): array
    {
        $listType = $listData['listType'];
        $originalActiveItems = $listData['activeItems'];

        foreach (['addedItems', 'activeItems', 'scanRemovedItems', 'removedItems', 'removedHistoryItems'] as $key) {
            $listData[$key] = $this->filterAndSortItems($listData[$key], $listType);
        }

        return [
            ...$listData,
            'controls' => [
                'visibilityFilter' => $this->relationshipVisibilityFilters[$listType] ?? 'all',
                'profileFilter' => $this->relationshipProfileFilters[$listType] ?? 'all',
                'sort' => $this->relationshipSorts[$listType] ?? 'default',
                'visibilityCounts' => $this->visibilityCounts($originalActiveItems),
                'profileCounts' => $this->profileCounts($originalActiveItems),
                'filteredActiveCount' => $listData['activeItems']->count(),
                'totalActiveCount' => $originalActiveItems->count(),
            ],
        ];
    }

    private function paginateRelationshipListData(array $listData): array
    {
        $page = max(1, (int) $this->getPage($this->relationshipPageName($listData['listType'])));
        $visibleLimit = $page * self::LIST_ITEMS_PER_PAGE;
        $activeItems = $listData['activeItems'];

        return [
            ...$listData,
            'visibleActiveItems' => $activeItems->take($visibleLimit),
            'visibleActiveCount' => min($activeItems->count(), $visibleLimit),
            'visibleLimit' => $visibleLimit,
            'itemsPerPage' => self::LIST_ITEMS_PER_PAGE,
            'hasMoreActiveItems' => $activeItems->count() > $visibleLimit,
        ];
    }

    private function relationshipPageName(string $listType): string
    {
        return ($listType === 'following' ? 'following' : 'followers').'RelationshipPage';
    }

    private function filterAndSortItems(Collection $items, string $listType): Collection
    {
        $visibilityFilter = $this->relationshipVisibilityFilters[$listType] ?? 'all';
        $profileFilter = $this->relationshipProfileFilters[$listType] ?? 'all';
        $sort = $this->relationshipSorts[$listType] ?? 'default';

        $items = $items
            ->filter(function ($item) use ($visibilityFilter, $profileFilter): bool {
                if ($visibilityFilter !== 'all' && $this->itemVisibility($item) !== $visibilityFilter) {
                    return false;
                }

                return match ($profileFilter) {
                    'tracked' => (bool) data_get($item, 'isTracked'),
                    'untracked' => ! (bool) data_get($item, 'isTracked'),
                    'reconstructed' => (bool) data_get($item, 'reconstructed'),
                    'passive' => (bool) data_get($item, 'passive'),
                    default => true,
                };
            })
            ->values();

        return match ($sort) {
            'username' => $items->sortBy(fn ($item): string => strtolower((string) data_get($item, 'username', data_get($item, 'username_snapshot', ''))))->values(),
            'newest' => $items->sortByDesc(fn ($item): int => $this->itemTimestamp($item))->values(),
            'followers' => $items->sortByDesc(fn ($item): int => (int) (data_get($item, 'followersCount') ?? data_get($item, 'followers_count') ?? -1))->values(),
            'following' => $items->sortByDesc(fn ($item): int => (int) (data_get($item, 'followingCount') ?? data_get($item, 'following_count') ?? -1))->values(),
            'posts' => $items->sortByDesc(fn ($item): int => (int) (data_get($item, 'postsCount') ?? data_get($item, 'posts_count') ?? -1))->values(),
            'visibility' => $items->sortBy(fn ($item): string => match ($this->itemVisibility($item)) {
                'public' => '0',
                'private' => '1',
                default => '2',
            }.strtolower((string) data_get($item, 'username', data_get($item, 'username_snapshot', ''))))->values(),
            default => $items,
        };
    }

    private function visibilityCounts(Collection $items): array
    {
        return [
            'all' => $items->count(),
            'public' => $items->filter(fn ($item): bool => $this->itemVisibility($item) === 'public')->count(),
            'private' => $items->filter(fn ($item): bool => $this->itemVisibility($item) === 'private')->count(),
            'unknown' => $items->filter(fn ($item): bool => $this->itemVisibility($item) === 'unknown')->count(),
        ];
    }

    private function profileCounts(Collection $items): array
    {
        return [
            'all' => $items->count(),
            'tracked' => $items->filter(fn ($item): bool => (bool) data_get($item, 'isTracked'))->count(),
            'untracked' => $items->filter(fn ($item): bool => ! (bool) data_get($item, 'isTracked'))->count(),
            'reconstructed' => $items->filter(fn ($item): bool => (bool) data_get($item, 'reconstructed'))->count(),
            'passive' => $items->filter(fn ($item): bool => (bool) data_get($item, 'passive'))->count(),
        ];
    }

    private function itemVisibility(mixed $item): string
    {
        $visibility = strtolower((string) (
            data_get($item, 'profileVisibility')
            ?? data_get($item, 'profile_visibility')
            ?? ''
        ));

        if (in_array($visibility, ['public', 'private'], true)) {
            return $visibility;
        }

        $isPrivate = data_get($item, 'isPrivate', data_get($item, 'is_private'));

        return $isPrivate === true ? 'private' : ($isPrivate === false ? 'public' : 'unknown');
    }

    private function itemTimestamp(mixed $item): int
    {
        foreach (['firstSeenAt', 'lastSeenAt', 'removedAt', 'observed_at', 'created_at'] as $key) {
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

    private function resolveTrackedPerson(): TrackedPerson
    {
        return Auth::user()
            ->trackedPeople()
            ->whereKey($this->trackedPersonId)
            ->firstOrFail();
    }
}
