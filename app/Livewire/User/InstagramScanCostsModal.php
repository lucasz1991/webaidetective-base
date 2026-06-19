<?php

namespace App\Livewire\User;

use App\Models\CreditTransaction;
use App\Models\InstagramProfile;
use App\Models\Setting;
use App\Models\TrackedPerson;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class InstagramScanCostsModal extends Component
{
    public ?int $instagramProfileId = null;

    public ?int $trackedPersonId = null;

    public bool $showScanCostsModal = false;

    public function mount(?int $instagramProfileId = null, ?int $trackedPersonId = null): void
    {
        $this->instagramProfileId = $instagramProfileId;
        $this->trackedPersonId = $trackedPersonId;
    }

    #[On('open-instagram-scan-costs-modal')]
    public function open(): void
    {
        $this->showScanCostsModal = true;
    }

    public function render()
    {
        $user = Auth::user();
        $trackedPerson = $this->resolveTrackedPerson();
        $profile = $this->resolveProfile($trackedPerson);
        $username = ltrim((string) ($profile?->username ?: $trackedPerson?->instagram_username), '@');
        $displayHandle = $username !== '' ? '@'.$username : ($trackedPerson?->display_name ?: 'Instagram-Profil');

        return view('livewire.user.instagram-scan-costs-modal', [
            'profile' => $profile,
            'trackedPerson' => $trackedPerson,
            'displayHandle' => $displayHandle,
            'scanCostSummary' => $this->scanCostSummary(),
            'creditWallet' => $user?->creditWallet()->first(),
            'recentScanTransactions' => $this->recentScanTransactions($username),
        ]);
    }

    private function resolveTrackedPerson(): ?TrackedPerson
    {
        if (! $this->trackedPersonId) {
            return null;
        }

        $user = Auth::user();

        if (! $user) {
            return null;
        }

        return $user->trackedPeople()
            ->with('currentInstagramProfile')
            ->whereKey($this->trackedPersonId)
            ->first();
    }

    private function resolveProfile(?TrackedPerson $trackedPerson): ?InstagramProfile
    {
        if ($trackedPerson?->currentInstagramProfile) {
            return $trackedPerson->currentInstagramProfile;
        }

        if (! $this->instagramProfileId) {
            return null;
        }

        return $this->accessibleProfileQuery((int) Auth::id())
            ->whereKey($this->instagramProfileId)
            ->first();
    }

    private function recentScanTransactions(string $username)
    {
        if ($username === '') {
            return collect();
        }

        $query = CreditTransaction::query()
            ->where('user_id', Auth::id())
            ->where('type', CreditTransaction::TYPE_SCAN)
            ->latest()
            ->limit(10);

        $query->where('description', 'like', '%@'.$username.'%');

        return $query->get();
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
