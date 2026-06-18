<?php

namespace App\Livewire\User;

use App\Models\TrackedPerson;
use App\Support\InstagramRelationshipListData;
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
                $relationshipLists->forTrackedPerson($trackedPerson, 'followers'),
            ),
            'followingListData' => $this->paginateRelationshipListData(
                $relationshipLists->forTrackedPerson($trackedPerson, 'following'),
            ),
        ]);
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

    private function resolveTrackedPerson(): TrackedPerson
    {
        return Auth::user()
            ->trackedPeople()
            ->whereKey($this->trackedPersonId)
            ->firstOrFail();
    }
}
