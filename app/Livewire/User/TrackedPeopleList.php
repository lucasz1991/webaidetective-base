<?php

namespace App\Livewire\User;

use App\Services\TrackedPeople\TrackedPersonQuotaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TrackedPeopleList extends Component
{
    public ?int $selectedTrackedPersonId = null;

    public bool $showDetailModal = false;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $sort = 'recent';

    public ?string $listStatus = null;

    public string $listStatusLevel = 'neutral';

    protected $listeners = [
        'tracked-person-refresh' => '$refresh',
        'tracked-person-list-open' => 'openTrackedPersonFromEvent',
    ];

    public function updatedSearch(): void
    {
        $this->search = trim($this->search);
    }

    public function setStatusFilter(string $filter): void
    {
        $this->statusFilter = in_array($filter, ['all', 'monitoring', 'needs_attention', 'no_scan'], true)
            ? $filter
            : 'all';
    }

    public function setSort(string $sort): void
    {
        $this->sort = in_array($sort, ['recent', 'name', 'followers'], true)
            ? $sort
            : 'recent';
    }

    public function openTrackedPersonFromEvent(int $trackedPersonId): void
    {
        $this->selectTrackedPerson($trackedPersonId);
    }

    public function selectTrackedPerson(int $trackedPersonId): void
    {
        $user = Auth::user();

        if (! $user || ! $user->trackedPeople()->whereKey($trackedPersonId)->exists()) {
            return;
        }

        $this->selectedTrackedPersonId = $trackedPersonId;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
    }

    public function setMonitoringEnabled(int $trackedPersonId, bool $enabled): void
    {
        $trackedPerson = $this->trackedPersonForCurrentUser($trackedPersonId);

        if (! $trackedPerson) {
            return;
        }

        $trackedPerson->update([
            'monitoring_enabled' => $enabled,
            'monitoring_interval_minutes' => max(15, (int) ($trackedPerson->monitoring_interval_minutes ?: 60)),
        ]);

        $this->setListStatus(
            $enabled
                ? 'Dauerbeobachtung fuer "'.$trackedPerson->display_name.'" wurde aktiviert.'
                : 'Dauerbeobachtung fuer "'.$trackedPerson->display_name.'" wurde deaktiviert.',
            'success',
        );
    }

    public function setMonitoringInterval(int $trackedPersonId, int $intervalMinutes): void
    {
        $trackedPerson = $this->trackedPersonForCurrentUser($trackedPersonId);

        if (! $trackedPerson) {
            return;
        }

        $allowedIntervals = [15, 30, 60, 120, 360, 720, 1440, 4320, 10080];
        $intervalMinutes = in_array($intervalMinutes, $allowedIntervals, true)
            ? $intervalMinutes
            : 60;

        $trackedPerson->update([
            'monitoring_enabled' => true,
            'monitoring_interval_minutes' => $intervalMinutes,
        ]);

        $this->setListStatus(
            'Dauerbeobachtung fuer "'.$trackedPerson->display_name.'" laeuft jetzt alle '.$this->formatMonitoringInterval($intervalMinutes).'.',
            'success',
        );
    }

    public function render()
    {
        $user = Auth::user();
        $trackedPeople = $user
            ? $user->trackedPeople()
                ->with(['latestInstagramSnapshot', 'latestChangedInstagramSnapshot'])
                ->when($this->search !== '', function (Builder $query): void {
                    $term = '%'.$this->search.'%';

                    $query->where(function (Builder $query) use ($term): void {
                        $query->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('alias', 'like', $term)
                            ->orWhere('instagram_username', 'like', $term);
                    });
                })
                ->when($this->statusFilter === 'monitoring', fn (Builder $query) => $query->where('monitoring_enabled', true))
                ->when($this->statusFilter === 'needs_attention', fn (Builder $query) => $query->whereIn('last_instagram_status_level', ['partial', 'error', 'cancelled']))
                ->when($this->statusFilter === 'no_scan', fn (Builder $query) => $query->whereNull('last_instagram_analyzed_at'))
                ->when($this->sort === 'name', fn (Builder $query) => $query
                    ->orderBy('last_name')
                    ->orderBy('first_name')
                    ->orderBy('instagram_username'))
                ->when($this->sort === 'followers', fn (Builder $query) => $query
                    ->orderByDesc('instagram_followers_count')
                    ->orderBy('instagram_username'))
                ->when($this->sort === 'recent', fn (Builder $query) => $query
                    ->orderByRaw('instagram_username IS NULL')
                    ->orderByDesc('last_instagram_analyzed_at')
                    ->orderBy('instagram_username'))
                ->get()
            : collect();

        $allTrackedPeople = $user
            ? $user->trackedPeople()
                ->select([
                    'id',
                    'monitoring_enabled',
                    'last_instagram_status_level',
                    'last_instagram_analyzed_at',
                ])
                ->get()
            : collect();

        if ($this->selectedTrackedPersonId && ! $allTrackedPeople->contains('id', $this->selectedTrackedPersonId)) {
            $this->selectedTrackedPersonId = null;
            $this->showDetailModal = false;
        }

        if ($this->selectedTrackedPersonId) {
            $selectedTrackedPerson = $user?->trackedPeople()
                ->with([
                    'currentInstagramProfile',
                    'latestInstagramSnapshot',
                    'latestChangedInstagramSnapshot',
                    'instagramSnapshots' => fn ($query) => $query
                        ->where('has_changes', true)
                        ->latest('analyzed_at')
                        ->limit(10),
                ])
                ->whereKey($this->selectedTrackedPersonId)
                ->first();
        } else {
            $selectedTrackedPerson = null;
        }

        return view('livewire.user.tracked-people-list', [
            'trackedPeople' => $trackedPeople,
            'selectedTrackedPerson' => $selectedTrackedPerson,
            'trackingLimit' => $user ? app(TrackedPersonQuotaService::class)->maxProfiles($user) : null,
            'stats' => [
                'total' => $allTrackedPeople->count(),
                'monitoring' => $allTrackedPeople->where('monitoring_enabled', true)->count(),
                'needsAttention' => $allTrackedPeople->whereIn('last_instagram_status_level', ['partial', 'error', 'cancelled'])->count(),
                'noScan' => $allTrackedPeople->whereNull('last_instagram_analyzed_at')->count(),
            ],
        ]);
    }

    private function setListStatus(string $message, string $level): void
    {
        $this->listStatus = $message;
        $this->listStatusLevel = $level;
    }

    private function trackedPersonForCurrentUser(int $trackedPersonId)
    {
        $user = Auth::user();

        return $user
            ? $user->trackedPeople()->whereKey($trackedPersonId)->first()
            : null;
    }

    private function formatMonitoringInterval(int $minutes): string
    {
        return match ($minutes) {
            15 => '15 Minuten',
            30 => '30 Minuten',
            60 => '1 Stunde',
            120 => '2 Stunden',
            360 => '6 Stunden',
            720 => '12 Stunden',
            1440 => '1 Tag',
            4320 => '3 Tage',
            10080 => '7 Tage',
            default => $minutes.' Minuten',
        };
    }
}
