<?php

namespace App\Livewire\User;

use App\Models\TrackedPerson;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class TrackedPersonScanOverview extends Component
{
    public int $trackedPersonId;

    #[Reactive]
    public ?string $detailStatus = null;

    #[Reactive]
    public string $detailStatusLevel = 'neutral';

    public function mount(int $trackedPersonId, ?string $detailStatus = null, string $detailStatusLevel = 'neutral'): void
    {
        $this->trackedPersonId = $trackedPersonId;
        $this->detailStatus = $detailStatus;
        $this->detailStatusLevel = $detailStatusLevel;
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.user.tracked-person-scan-overview', [
            'isAdmin' => $user?->role === 'admin',
            'trackedPerson' => $this->resolveTrackedPerson(),
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
