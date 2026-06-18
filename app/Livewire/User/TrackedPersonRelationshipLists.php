<?php

namespace App\Livewire\User;

use App\Models\TrackedPerson;
use App\Support\InstagramRelationshipListData;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class TrackedPersonRelationshipLists extends Component
{
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
            $this->showFollowersModal = true;
        }

        if ($listType === 'following') {
            $this->showFollowingModal = true;
        }
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
            'followersListData' => $relationshipLists->forTrackedPerson($trackedPerson, 'followers'),
            'followingListData' => $relationshipLists->forTrackedPerson($trackedPerson, 'following'),
        ]);
    }

    private function resolveTrackedPerson(): TrackedPerson
    {
        return Auth::user()
            ->trackedPeople()
            ->whereKey($this->trackedPersonId)
            ->firstOrFail();
    }
}
