<?php

namespace App\Livewire\User;

use App\Models\InstagramProfile;
use App\Models\TrackedPerson;
use App\Services\TrackedPeople\InstagramProfileRelationshipStore;
use App\Services\TrackedPeople\TrackedPersonInstagramAnalysisService;
use App\Services\TrackedPeople\TrackedPersonInstagramWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class InstagramProfileDetail extends Component
{
    public int $instagramProfileId;

    public bool $showFollowersModal = false;

    public bool $showFollowingModal = false;

    public ?string $detailStatus = null;

    public string $detailStatusLevel = 'neutral';

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
        $latestFollowersScan = $profile->listScans()
            ->where('user_id', $userId)
            ->where('list_type', 'followers')
            ->latest('scanned_at')
            ->with(['items.relatedInstagramProfile'])
            ->first();
        $latestFollowingScan = $profile->listScans()
            ->where('user_id', $userId)
            ->where('list_type', 'following')
            ->latest('scanned_at')
            ->with(['items.relatedInstagramProfile'])
            ->first();

        $trackedPerson = $this->findTrackedPerson($profile);

        return view('livewire.user.instagram-profile-detail', [
            'profile' => $profile,
            'trackedPerson' => $trackedPerson,
            'latestFollowersScan' => $latestFollowersScan,
            'latestFollowingScan' => $latestFollowingScan,
        ])->layout('layouts.app');
    }

    public function analyzeInstagramMini(): void
    {
        $this->runAnalysis(false);
    }

    public function analyzeInstagram(): void
    {
        $this->runAnalysis(true);
    }

    public function scanInstagramFollowersList(): void
    {
        $this->runRelationshipListScan('followers');
    }

    public function scanInstagramFollowingList(): void
    {
        $this->runRelationshipListScan('following');
    }

    public function scanInstagramSuggestions(): void
    {
        @set_time_limit(0);

        $trackedPerson = $this->resolveOrCreateTrackedPerson();

        try {
            $scan = app(TrackedPersonInstagramWorkflowService::class)
                ->runSuggestionScan($trackedPerson);
            $this->setStatus(
                'Vorschlagsscan abgeschlossen: '.number_format($scan->suggestion_matches_count).' Verbindungen gefunden.',
                $scan->status_level === 'success' ? 'success' : 'partial',
            );
        } catch (\Throwable $exception) {
            $this->setStatus('Vorschlagsscan fehlgeschlagen: '.$exception->getMessage(), 'error');
        }
    }

    private function runAnalysis(bool $fullScan): void
    {
        @set_time_limit(0);

        $trackedPerson = $this->resolveOrCreateTrackedPerson();

        try {
            $result = app(TrackedPersonInstagramWorkflowService::class)
                ->runAnalysis($trackedPerson, $fullScan, null, true);
            $this->setStatus($result['resolvedStatusMessage'], $result['resolvedStatusLevel']);
        } catch (\Throwable $exception) {
            $this->setStatus(
                ($fullScan ? 'Vollanalyse' : 'Mini-Scan').' fehlgeschlagen: '.$exception->getMessage(),
                'error',
            );
        }
    }

    private function runRelationshipListScan(string $relationship): void
    {
        @set_time_limit(0);

        $trackedPerson = $this->resolveOrCreateTrackedPerson();
        $label = $relationship === 'followers' ? 'Followerliste' : 'Gefolgt-Liste';

        try {
            $snapshot = app(TrackedPersonInstagramAnalysisService::class)
                ->scanRelationshipList($trackedPerson, $relationship);
            $payloadKey = $relationship === 'followers' ? 'followersList' : 'followingList';
            $list = data_get($snapshot->raw_payload, 'extractedProfile.'.$payloadKey, []);
            $this->setStatus(
                $label.' gescannt: '.number_format((int) data_get($list, 'activeCount', 0)).' aktive Eintraege.',
                $snapshot->status_level === 'success' ? 'success' : 'partial',
            );
        } catch (\Throwable $exception) {
            $this->setStatus($label.'-Scan fehlgeschlagen: '.$exception->getMessage(), 'error');
        }
    }

    private function resolveOrCreateTrackedPerson(): TrackedPerson
    {
        $profile = $this->resolveProfile();
        $trackedPerson = $this->findTrackedPerson($profile);

        if ($trackedPerson) {
            return $trackedPerson;
        }

        $user = Auth::user();
        $displayName = trim((string) ($profile->display_name ?: $profile->full_name ?: $profile->username));
        $nameParts = preg_split('/\s+/', $displayName, 2) ?: [];
        $trackedPerson = $user->trackedPeople()->create([
            'first_name' => $nameParts[0] ?? $profile->username,
            'last_name' => $nameParts[1] ?? '',
            'alias' => $displayName,
            'instagram_username' => $profile->username,
            'current_instagram_profile_id' => $profile->id,
            'is_primary' => ! $user->trackedPeople()->where('is_primary', true)->exists(),
        ]);
        app(InstagramProfileRelationshipStore::class)->syncTrackedPersonProfile($trackedPerson);

        return $trackedPerson->fresh();
    }

    private function findTrackedPerson(InstagramProfile $profile): ?TrackedPerson
    {
        return Auth::user()
            ->trackedPeople()
            ->where(function ($query) use ($profile): void {
                $query->where('current_instagram_profile_id', $profile->id)
                    ->orWhereRaw(
                        "LOWER(TRIM(LEADING '@' FROM instagram_username)) = ?",
                        [$profile->username],
                    );
            })
            ->first();
    }

    private function setStatus(string $message, string $level): void
    {
        $this->detailStatus = $message;
        $this->detailStatusLevel = $level;
        $this->dispatch('refresh-user-navigation-menu');
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
