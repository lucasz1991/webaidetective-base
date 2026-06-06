<?php

namespace App\Livewire\User;

use App\Models\InstagramProfile;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class InstagramProfileDetail extends Component
{
    public int $instagramProfileId;

    public function mount(int $instagramProfileId): void
    {
        $this->instagramProfileId = $instagramProfileId;
        $this->resolveProfile();
    }

    public function render()
    {
        $profile = $this->resolveProfile();
        $userId = (int) Auth::id();
        $profile->load([
            'listScans' => fn ($query) => $query
                ->where('user_id', $userId)
                ->latest('scanned_at')
                ->limit(20),
            'sourceRelationships' => fn ($query) => $query
                ->where('status', 'active')
                ->whereNull('removed_at')
                ->whereHas('scanItems.listScan', fn ($scans) => $scans->where('user_id', $userId))
                ->with('relatedInstagramProfile')
                ->latest('last_seen_at'),
            'relatedRelationships' => fn ($query) => $query
                ->where('status', 'active')
                ->whereNull('removed_at')
                ->whereHas('scanItems.listScan', fn ($scans) => $scans->where('user_id', $userId))
                ->with('sourceInstagramProfile')
                ->latest('last_seen_at'),
        ]);

        $trackedPerson = Auth::user()
            ->trackedPeople()
            ->where(function ($query) use ($profile): void {
                $query->where('current_instagram_profile_id', $profile->id)
                    ->orWhereRaw(
                        "LOWER(TRIM(LEADING '@' FROM instagram_username)) = ?",
                        [$profile->username],
                    );
            })
            ->first();

        return view('livewire.user.instagram-profile-detail', [
            'profile' => $profile,
            'trackedPerson' => $trackedPerson,
        ])->layout('layouts.app');
    }

    private function resolveProfile(): InstagramProfile
    {
        $userId = (int) Auth::id();

        return InstagramProfile::query()
            ->whereKey($this->instagramProfileId)
            ->where(function ($query) use ($userId): void {
                $query
                    ->whereHas('trackedPersonLinks', fn ($links) => $links->where('user_id', $userId))
                    ->orWhereHas('publicProfileLinks', fn ($links) => $links->where('user_id', $userId))
                    ->orWhereHas('listScans', fn ($scans) => $scans->where('user_id', $userId))
                    ->orWhereHas('sourceRelationships.scanItems.listScan', fn ($scans) => $scans->where('user_id', $userId))
                    ->orWhereHas('relatedRelationships.scanItems.listScan', fn ($scans) => $scans->where('user_id', $userId))
                    ->orWhereHas(
                        'candidateInferredConnections.trackedPerson',
                        fn ($people) => $people->where('user_id', $userId),
                    )
                    ->orWhereHas(
                        'sourceInferredConnections.trackedPerson',
                        fn ($people) => $people->where('user_id', $userId),
                    );
            })
            ->firstOrFail();
    }
}
