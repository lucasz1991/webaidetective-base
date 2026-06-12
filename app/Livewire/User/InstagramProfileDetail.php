<?php

namespace App\Livewire\User;

use App\Models\InstagramProfile;
use App\Models\TrackedPerson;
use App\Services\TrackedPeople\InstagramProfileRelationshipStore;
use App\Services\TrackedPeople\InstagramProfileScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramPostScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramProfileListScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramWorkflowService;
use App\Services\TrackedPeople\TrackedPersonQuotaService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class InstagramProfileDetail extends Component
{
    public int $instagramProfileId;

    public bool $showListModal = false;

    public string $activeListType = 'followers';

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
            'postScans' => fn ($query) => $query
                ->where('user_id', $userId)
                ->latest('scanned_at')
                ->limit(10),
            'posts' => fn ($query) => $query
                ->with('media')
                ->withCount('metrics')
                ->latest('published_at')
                ->latest('last_seen_at')
                ->limit(24),
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
        $latestSuggestionScan = $trackedPerson?->instagramSuggestionScans()
            ->latest('analyzed_at')
            ->first();
        $latestPostScan = $profile->postScans->first();
        $lastScanStatus = [
            'level' => $profile->last_status_level,
            'message' => $profile->last_status_message,
            'scannedAt' => $profile->last_scanned_at,
            'type' => 'Instagram-Scan',
        ];

        if (
            $latestSuggestionScan?->analyzed_at
            && (
                ! $lastScanStatus['scannedAt']
                || $latestSuggestionScan->analyzed_at->isAfter($lastScanStatus['scannedAt'])
            )
        ) {
            $lastScanStatus = [
                'level' => $latestSuggestionScan->status_level,
                'message' => $latestSuggestionScan->status_message,
                'scannedAt' => $latestSuggestionScan->analyzed_at,
                'type' => 'Vorschlagsscan',
            ];
        }

        if (
            $latestPostScan?->scanned_at
            && (
                ! $lastScanStatus['scannedAt']
                || $latestPostScan->scanned_at->isAfter($lastScanStatus['scannedAt'])
            )
        ) {
            $lastScanStatus = [
                'level' => $latestPostScan->status_level,
                'message' => $latestPostScan->status_message,
                'scannedAt' => $latestPostScan->scanned_at,
                'type' => 'Beitragsscan',
            ];
        }

        return view('livewire.user.instagram-profile-detail', [
            'profile' => $profile,
            'trackedPerson' => $trackedPerson,
            'latestFollowersScan' => $latestFollowersScan,
            'latestFollowingScan' => $latestFollowingScan,
            'lastScanStatus' => $lastScanStatus,
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

    public function openListModal(string $listType): void
    {
        if (! in_array($listType, ['followers', 'following'], true)) {
            return;
        }

        $this->activeListType = $listType;
        $this->showListModal = true;
    }

    public function scanInstagramSuggestions(): void
    {
        @set_time_limit(0);

        $profile = $this->resolveProfile();
        $trackedPerson = $this->findTrackedPerson($profile);

        if (! $trackedPerson) {
            $this->setStatus(
                'Vorschlagsscans sind zielpersonenbezogen. Lege das Profil zuerst ausdruecklich als beobachtetes Profil an.',
                'partial',
            );

            return;
        }

        try {
            $scan = app(TrackedPersonInstagramWorkflowService::class)
                ->runSuggestionScan($trackedPerson);
            $this->setStatus(
                'Vorschlagsscan abgeschlossen: '
                    .number_format($scan->suggestions_checked_count).' von '
                    .number_format($scan->suggestions_observed_count).' Vorschlaegen geprueft, '
                    .number_format($scan->suggestion_matches_count).' Verbindungen gefunden.',
                $scan->status_level === 'success' ? 'success' : 'partial',
            );
        } catch (\Throwable $exception) {
            $this->setStatus('Vorschlagsscan fehlgeschlagen: '.$exception->getMessage(), 'error');
        }
    }

    public function scanInstagramPosts(): void
    {
        @set_time_limit(0);

        $profile = $this->resolveProfile();

        try {
            $scan = app(TrackedPersonInstagramPostScanService::class)
                ->scanProfile($profile, (int) Auth::id());
            $this->setStatus(
                'Beitragsscan abgeschlossen: '
                    .number_format($scan->observed_count).' geprueft, '
                    .number_format($scan->new_count).' neu und '
                    .number_format($scan->updated_count).' aktualisiert.',
                $scan->status_level === 'success' ? 'success' : 'partial',
            );
        } catch (\Throwable $exception) {
            $this->setStatus('Beitragsscan fehlgeschlagen: '.$exception->getMessage(), 'error');
        }
    }

    private function runAnalysis(bool $fullScan): void
    {
        @set_time_limit(0);

        $profile = $this->resolveProfile();

        try {
            $result = app(InstagramProfileScanService::class)
                ->scan($profile, (int) Auth::id(), $fullScan);
            $this->setStatus($result['statusMessage'], $result['statusLevel']);
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

        $profile = $this->resolveProfile();
        $label = $relationship === 'followers' ? 'Followerliste' : 'Gefolgt-Liste';

        try {
            $scan = app(TrackedPersonInstagramProfileListScanService::class)
                ->scan(null, $profile, null, [$relationship], (int) Auth::id())
                ->first();
            $this->setStatus(
                $label.' gescannt: '.number_format((int) ($scan?->active_count ?? 0)).' aktive Eintraege.',
                $scan?->status_level === 'success' ? 'success' : 'partial',
            );
        } catch (\Throwable $exception) {
            $this->setStatus($label.'-Scan fehlgeschlagen: '.$exception->getMessage(), 'error');
        }
    }

    public function addAsTrackedPerson(): void
    {
        $profile = $this->resolveProfile();
        $trackedPerson = $this->findTrackedPerson($profile);

        if ($trackedPerson) {
            $this->setStatus('Dieses Profil wird bereits beobachtet.', 'partial');

            return;
        }

        $user = Auth::user();

        try {
            app(TrackedPersonQuotaService::class)->assertCanCreate($user);
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
            $this->setStatus('Profil wurde als beobachtetes Profil angelegt.', 'success');
        } catch (\Throwable $exception) {
            $this->setStatus('Profil konnte nicht beobachtet werden: '.$exception->getMessage(), 'error');
        }
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
