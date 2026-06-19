<?php

namespace App\Livewire\User;

use App\Models\InstagramPost;
use App\Models\InstagramProfile;
use App\Models\Setting;
use App\Models\TrackedPerson;
use App\Services\TrackedPeople\TrackedPersonInstagramSuggestionScanService;
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
    private const LIST_ITEMS_PER_PAGE = 50;

    public int $instagramProfileId;

    public bool $showListModal = false;

    public string $activeListType = 'followers';

    public array $listModalPages = [
        'followers' => 1,
        'following' => 1,
    ];

    public bool $showPostEngagementModal = false;

    public ?int $selectedPostId = null;

    public string $activePostEngagementType = 'likes';

    public ?string $detailStatus = null;

    public string $detailStatusLevel = 'neutral';

    protected $listeners = [
        'scan-instagram-profile-from-list' => 'scanInstagramProfileFromList',
    ];

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
            'profileScans' => fn ($query) => $query
                ->where('user_id', $userId)
                ->latest('scanned_at')
                ->limit(10),
            'suggestionScans' => fn ($query) => $query
                ->where('user_id', $userId)
                ->latest('analyzed_at')
                ->limit(10),
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
                ->withCount([
                    'metrics',
                    'likes as stored_likes_count' => fn ($likes) => $likes->where('is_active', true),
                    'comments as stored_comments_count' => fn ($comments) => $comments->where('is_active', true),
                ])
                ->latest('published_at')
                ->latest('last_seen_at')
                ->limit(24),
        ]);
        $latestFollowersScan = $profile->listScans()
            ->where('user_id', $userId)
            ->where('list_type', 'followers')
            ->latest('scanned_at')
            ->with([
                'items.relatedInstagramProfile.trackedPersonLinks' => fn ($links) => $links
                    ->where('user_id', $userId)
                    ->whereNull('unlinked_at'),
            ])
            ->first();
        $latestFollowingScan = $profile->listScans()
            ->where('user_id', $userId)
            ->where('list_type', 'following')
            ->latest('scanned_at')
            ->with([
                'items.relatedInstagramProfile.trackedPersonLinks' => fn ($links) => $links
                    ->where('user_id', $userId)
                    ->whereNull('unlinked_at'),
            ])
            ->first();
        $selectedPost = null;

        if ($this->showPostEngagementModal && $this->selectedPostId) {
            $selectedPost = $profile->posts()
                ->whereKey($this->selectedPostId)
                ->with([
                    'media',
                    'likes' => fn ($query) => $query
                        ->where('is_active', true)
                        ->orderBy('username'),
                    'comments' => fn ($query) => $query
                        ->where('is_active', true)
                        ->orderByDesc('published_at'),
                ])
                ->first();
        }

        $trackedPerson = $this->findTrackedPerson($profile);
        $latestSuggestionScan = $profile->suggestionScans->first();
        $latestTrackedSuggestionScan = $trackedPerson?->instagramSuggestionScans()
            ->latest('analyzed_at')
            ->first();

        if (
            $latestTrackedSuggestionScan?->analyzed_at
            && (
                ! $latestSuggestionScan?->analyzed_at
                || $latestTrackedSuggestionScan->analyzed_at->isAfter($latestSuggestionScan->analyzed_at)
            )
        ) {
            $latestSuggestionScan = $latestTrackedSuggestionScan;
        }
        $latestPostScan = $profile->postScans->first();
        $latestProfileScan = $profile->profileScans->first();
        $lastScanStatus = [
            'level' => $latestProfileScan?->status_level ?: $profile->last_status_level,
            'message' => $latestProfileScan?->status_message ?: $profile->last_status_message,
            'scannedAt' => $latestProfileScan?->scanned_at ?: $profile->last_scanned_at,
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
            'scanCostSummary' => $this->scanCostSummary(),
            'listModalVisibleLimit' => $this->listModalVisibleLimit($this->activeListType),
            'listModalItemsPerPage' => self::LIST_ITEMS_PER_PAGE,
            'selectedPost' => $selectedPost,
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
        $this->listModalPages[$listType] = 1;
        $this->showListModal = true;
    }

    public function loadMoreInstagramProfileList(string $listType): void
    {
        if (! in_array($listType, ['followers', 'following'], true)) {
            return;
        }

        $this->listModalPages[$listType] = max(1, (int) ($this->listModalPages[$listType] ?? 1)) + 1;
    }

    public function openPostEngagementModal(int $postId, string $type): void
    {
        if (! in_array($type, ['likes', 'comments'], true)) {
            return;
        }

        $profile = $this->resolveProfile();
        $postExists = InstagramPost::query()
            ->whereKey($postId)
            ->where('instagram_profile_id', $profile->id)
            ->exists();

        if (! $postExists) {
            return;
        }

        $this->selectedPostId = $postId;
        $this->activePostEngagementType = $type;
        $this->showPostEngagementModal = true;
    }

    public function scanInstagramSuggestions(): void
    {
        $this->runSuggestionScan(false);
    }

    public function scanInstagramSuggestionDeepSearch(): void
    {
        $this->runSuggestionScan(true);
    }

    private function runSuggestionScan(bool $deepSearch, ?InstagramProfile $profile = null): void
    {
        @set_time_limit(0);

        $profile ??= $this->resolveProfile();
        $trackedPerson = $this->findTrackedPerson($profile);
        $scanLabel = $deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan';
        $usedCreditsBefore = $this->usedCredits();

        try {
            if ($trackedPerson) {
                $workflow = app(TrackedPersonInstagramWorkflowService::class);
                $scan = $deepSearch
                    ? $workflow->runSuggestionDeepSearch($trackedPerson)
                    : $workflow->runSuggestionScan($trackedPerson);
            } else {
                $service = app(TrackedPersonInstagramSuggestionScanService::class);
                $scan = $deepSearch
                    ? $service->scanProfileDeepSearch($profile, (int) Auth::id())
                    : $service->scanProfile($profile, (int) Auth::id());
            }
            $this->setStatus(
                ($deepSearch
                    ? 'Vorschlaege DeepSearch abgeschlossen: '
                        .number_format($scan->suggestions_checked_count).' von '
                        .number_format($scan->suggestions_observed_count).' Vorschlaegen geprueft, '
                        .number_format($scan->suggestion_matches_count).' Verbindungen gefunden.'
                    : 'Vorschlaege-Scan abgeschlossen: '
                        .number_format($scan->suggestions_observed_count).' Vorschlaege gefunden.')
                    .$this->costSuffix($usedCreditsBefore),
                $scan->status_level === 'success' ? 'success' : 'partial',
            );
        } catch (\Throwable $exception) {
            $this->setStatus(
                $scanLabel.' fehlgeschlagen: '.$exception->getMessage().$this->costSuffix($usedCreditsBefore),
                'error',
            );
        }
    }

    public function scanInstagramPosts(): void
    {
        $this->runPostScan();
    }

    public function scanInstagramProfileFromList(?int $profileId = null, ?string $username = null, string $scanType = 'mini'): void
    {
        @set_time_limit(0);

        $profile = $this->resolveListProfile($profileId, $username);

        if (! $profile) {
            $this->setStatus('Das ausgewaehlte Instagram-Profil wurde nicht gefunden oder ist nicht freigegeben.', 'error');

            return;
        }

        match ($scanType) {
            'full' => $this->runAnalysis(true, $profile),
            'followers' => $this->runRelationshipListScan('followers', $profile),
            'following' => $this->runRelationshipListScan('following', $profile),
            'posts' => $this->runPostScan($profile),
            'suggestions' => $this->runSuggestionScan(false, $profile),
            'suggestion_deepsearch' => $this->runSuggestionScan(true, $profile),
            default => $this->runAnalysis(false, $profile),
        };
    }

    private function runPostScan(?InstagramProfile $profile = null): void
    {
        @set_time_limit(0);

        $profile ??= $this->resolveProfile();
        $usedCreditsBefore = $this->usedCredits();

        try {
            $scan = app(TrackedPersonInstagramPostScanService::class)
                ->scanProfile($profile, (int) Auth::id());
            $this->setStatus(
                'Beitragsscan abgeschlossen: '
                    .number_format($scan->observed_count).' geprueft, '
                    .number_format($scan->new_count).' neu und '
                    .number_format($scan->updated_count).' aktualisiert.'
                    .$this->costSuffix($usedCreditsBefore),
                $scan->status_level === 'success' ? 'success' : 'partial',
            );
        } catch (\Throwable $exception) {
            $this->setStatus(
                'Beitragsscan fehlgeschlagen: '.$exception->getMessage().$this->costSuffix($usedCreditsBefore),
                'error',
            );
        }
    }

    private function runAnalysis(bool $fullScan, ?InstagramProfile $profile = null): void
    {
        @set_time_limit(0);

        $profile ??= $this->resolveProfile();
        $usedCreditsBefore = $this->usedCredits();

        try {
            $result = app(InstagramProfileScanService::class)
                ->scan($profile, (int) Auth::id(), $fullScan);
            $this->setStatus(
                $result['statusMessage'].$this->costSuffix($usedCreditsBefore),
                $result['statusLevel'],
            );
        } catch (\Throwable $exception) {
            $this->setStatus(
                ($fullScan ? 'Vollanalyse' : 'Mini-Scan').' fehlgeschlagen: '
                    .$exception->getMessage()
                    .$this->costSuffix($usedCreditsBefore),
                'error',
            );
        }
    }

    private function runRelationshipListScan(string $relationship, ?InstagramProfile $profile = null): void
    {
        @set_time_limit(0);

        $profile ??= $this->resolveProfile();
        $label = $relationship === 'followers' ? 'Followerliste' : 'Gefolgt-Liste';
        $usedCreditsBefore = $this->usedCredits();

        try {
            $scan = app(TrackedPersonInstagramProfileListScanService::class)
                ->scan(null, $profile, null, [$relationship], (int) Auth::id())
                ->first();
            $this->setStatus(
                $label.' gescannt: '.number_format((int) ($scan?->active_count ?? 0))
                    .' aktive Eintraege.'
                    .$this->costSuffix($usedCreditsBefore),
                $scan?->status_level === 'success' ? 'success' : 'partial',
            );
        } catch (\Throwable $exception) {
            $this->setStatus(
                $label.'-Scan fehlgeschlagen: '.$exception->getMessage().$this->costSuffix($usedCreditsBefore),
                'error',
            );
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

        return $this->accessibleProfileQuery($userId)
            ->whereKey($this->instagramProfileId)
            ->firstOrFail();
    }

    private function resolveListProfile(?int $profileId = null, ?string $username = null): ?InstagramProfile
    {
        $userId = (int) Auth::id();
        $username = strtolower(ltrim(trim((string) $username), '@'));

        if (! $profileId && $username === '') {
            return null;
        }

        return $this->accessibleProfileQuery($userId)
            ->when($profileId, fn ($query) => $query->whereKey($profileId))
            ->when(! $profileId && $username !== '', fn ($query) => $query->where('username', $username))
            ->first();
    }

    private function accessibleProfileQuery(int $userId)
    {
        return InstagramProfile::query()
            ->where(function ($query) use ($userId): void {
                $query
                    ->whereHas('trackedPersonLinks', fn ($links) => $links->where('user_id', $userId))
                    ->orWhereHas('publicProfileLinks', fn ($links) => $links->where('user_id', $userId))
                    ->orWhereHas('listScans', fn ($scans) => $scans->where('user_id', $userId))
                    ->orWhereHas('profileScans', fn ($scans) => $scans->where('user_id', $userId))
                    ->orWhereHas('postScans', fn ($scans) => $scans->where('user_id', $userId))
                    ->orWhereHas('suggestionScans', fn ($scans) => $scans->where('user_id', $userId))
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
            });
    }

    private function usedCredits(): int
    {
        return (int) (Auth::user()->creditWallet()->value('used_credits') ?? 0);
    }

    private function costSuffix(int $usedCreditsBefore): string
    {
        $chargedCredits = max(0, $this->usedCredits() - $usedCreditsBefore);

        return ' Kosten: '.number_format($chargedCredits, 0, ',', '.').' Credits.';
    }

    private function listModalVisibleLimit(string $listType): int
    {
        $page = max(1, (int) ($this->listModalPages[$listType] ?? 1));

        return $page * self::LIST_ITEMS_PER_PAGE;
    }

    private function scanCostSummary(): array
    {
        $settings = Setting::getValue('billing', 'credit_costs');
        $settings = is_array($settings) ? $settings : [];
        $base = max(0, (int) ($settings['scan_base_credit'] ?? 1));
        $profile = max(0, (int) ($settings['profile_scan'] ?? 1));
        $post = max(0, (int) ($settings['post_scan'] ?? 3));
        $minimum = max(0, (int) ($settings['scan_minimum_credits'] ?? 1));
        $perMinute = max(0, (int) ($settings['scan_credit_per_minute'] ?? 2));

        return [
            'base' => $base,
            'per_minute' => $perMinute,
            'max_minutes' => max(1, (int) ($settings['scan_max_billable_minutes'] ?? 30)),
            'profile' => max($minimum, $base + $profile),
            'post' => max($minimum, $base + $profile + $post),
            'media_download' => max(0, (int) ($settings['media_download_per_file'] ?? 5)),
        ];
    }
}
