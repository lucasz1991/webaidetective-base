<?php

namespace App\Livewire\User;

use App\Services\TrackedPeople\InstagramProfileRelationshipStore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TrackedPeopleManager extends Component
{
    public $first_name = '';
    public $last_name = '';
    public $alias = '';
    public $date_of_birth = '';
    public $city = '';
    public $country = '';
    public $notes = '';
    public $instagram_username = '';
    public $tiktok_username = '';
    public $facebook_username = '';
    public $x_username = '';
    public $youtube_username = '';
    public $snapchat_username = '';

    public ?int $selectedTrackedPersonId = null;
    public bool $showDetailModal = false;
    public bool $showCreateForm = false;
    public ?int $trackedPersonIdPendingDeletion = null;
    public bool $showDeleteConfirmationModal = false;
    public $managerStatus = null;
    public $managerStatusLevel = 'neutral';

    protected $listeners = [
        'tracked-person-refresh' => '$refresh',
    ];

    protected function rules(): array
    {
        return [
            'first_name'            => ['required', 'string', 'max:255'],
            'last_name'             => ['required', 'string', 'max:255'],
            'alias'                 => ['nullable', 'string', 'max:255'],
            'date_of_birth'         => ['nullable', 'date'],
            'city'                  => ['nullable', 'string', 'max:255'],
            'country'               => ['nullable', 'string', 'max:255'],
            'notes'                 => ['nullable', 'string'],
            'instagram_username'    => ['nullable', 'string', 'max:255'],
            'tiktok_username'       => ['nullable', 'string', 'max:255'],
            'facebook_username'     => ['nullable', 'string', 'max:255'],
            'x_username'            => ['nullable', 'string', 'max:255'],
            'youtube_username'      => ['nullable', 'string', 'max:255'],
            'snapchat_username'     => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
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

        $this->setManagerStatus(
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

        $this->setManagerStatus(
            'Dauerbeobachtung fuer "'.$trackedPerson->display_name.'" laeuft jetzt alle '.$this->formatMonitoringInterval($intervalMinutes).'.',
            'success',
        );
    }

    public function confirmTrackedPersonDeletion(int $trackedPersonId): void
    {
        $user = Auth::user();

        if (! $user || ! $user->trackedPeople()->whereKey($trackedPersonId)->exists()) {
            return;
        }

        $this->trackedPersonIdPendingDeletion = $trackedPersonId;
        $this->showDeleteConfirmationModal = true;
    }

    public function cancelTrackedPersonDeletion(): void
    {
        $this->trackedPersonIdPendingDeletion = null;
        $this->showDeleteConfirmationModal = false;
    }

    public function deleteTrackedPerson(): void
    {
        $user = Auth::user();

        if (! $user || ! $this->trackedPersonIdPendingDeletion) {
            $this->cancelTrackedPersonDeletion();

            return;
        }

        $trackedPerson = $user->trackedPeople()->whereKey($this->trackedPersonIdPendingDeletion)->first();

        if (! $trackedPerson) {
            $this->cancelTrackedPersonDeletion();

            return;
        }

        $displayName = $trackedPerson->display_name;
        $wasPrimary = (bool) $trackedPerson->is_primary;

        try {
            DB::transaction(function () use ($user, $trackedPerson, $wasPrimary): void {
                $trackedPerson->delete();

                if ($wasPrimary) {
                    $user->trackedPeople()
                        ->orderByRaw('instagram_username IS NULL')
                        ->orderByDesc('last_instagram_analyzed_at')
                        ->orderBy('instagram_username')
                        ->first()
                        ?->update(['is_primary' => true]);
                }
            });
        } catch (\Throwable $exception) {
            $this->setManagerStatus(
                'Person "'.$displayName.'" konnte nicht geloescht werden: '.$exception->getMessage(),
                'error',
            );

            return;
        }

        if ($this->selectedTrackedPersonId === $trackedPerson->id) {
            $this->selectedTrackedPersonId = null;
            $this->showDetailModal = false;
        }

        $this->cancelTrackedPersonDeletion();
        $this->setManagerStatus(
            'Person "'.$displayName.'" wurde geloescht.',
            'success',
        );
    }

    public function createTrackedPerson(): void
    {
        $validated = $this->validate();
        $user = Auth::user();
        $validated = [
            'first_name' => trim($validated['first_name']),
            'last_name' => trim($validated['last_name']),
            'alias' => $this->nullableTrim($validated['alias'] ?? null),
            'date_of_birth' => $this->nullableTrim($validated['date_of_birth'] ?? null),
            'city' => $this->nullableTrim($validated['city'] ?? null),
            'country' => $this->nullableTrim($validated['country'] ?? null),
            'notes' => $this->nullableTrim($validated['notes'] ?? null),
            'instagram_username' => $this->normalizeHandle($validated['instagram_username'] ?? null),
            'tiktok_username' => $this->normalizeHandle($validated['tiktok_username'] ?? null),
            'facebook_username' => $this->normalizeHandle($validated['facebook_username'] ?? null),
            'x_username' => $this->normalizeHandle($validated['x_username'] ?? null),
            'youtube_username' => $this->normalizeHandle($validated['youtube_username'] ?? null),
            'snapchat_username' => $this->normalizeHandle($validated['snapchat_username'] ?? null),
            'is_primary' => ! $user->trackedPeople()->where('is_primary', true)->exists(),
        ];

        $person = $user->trackedPeople()->create($validated);
        app(InstagramProfileRelationshipStore::class)->syncTrackedPersonProfile($person);

        $this->selectedTrackedPersonId = $person->id;
        $this->showDetailModal = true;
        $this->showCreateForm = false;
        $this->resetForm();
        $this->setManagerStatus(
            'Person "'.$person->display_name.'" wurde angelegt.',
            'success',
        );
    }

    public function render()
    {
        $user = Auth::user();
        $trackedPeople = $user
            ? $user->trackedPeople()
                ->with(['latestInstagramSnapshot', 'latestChangedInstagramSnapshot'])
                ->orderByRaw('instagram_username IS NULL')
                ->orderByDesc('last_instagram_analyzed_at')
                ->orderBy('instagram_username')
                ->get()
            : collect();

        if ($this->selectedTrackedPersonId && ! $trackedPeople->contains('id', $this->selectedTrackedPersonId)) {
            $this->selectedTrackedPersonId = null;
            $this->showDetailModal = false;
        }

        if ($trackedPeople->isEmpty()) {
            $this->selectedTrackedPersonId = null;
            $this->showDetailModal = false;
        }

        if ($this->selectedTrackedPersonId && $selectedTrackedPerson = $trackedPeople->firstWhere('id', $this->selectedTrackedPersonId)) {
            $selectedTrackedPerson->load([
                'instagramSnapshots' => fn ($query) => $query
                    ->where('has_changes', true)
                    ->latest('analyzed_at')
                    ->limit(10),
            ]);
        }

        return view('livewire.user.tracked-people-manager', [
            'trackedPeople' => $trackedPeople,
        ]);
    }

    private function resetForm(): void
    {
        $this->reset([
            'first_name',
            'last_name',
            'alias',
            'date_of_birth',
            'city',
            'country',
            'notes',
            'instagram_username',
            'tiktok_username',
            'facebook_username',
            'x_username',
            'youtube_username',
            'snapchat_username',
        ]);
    }

    private function setManagerStatus(string $message, string $level): void
    {
        $this->managerStatus = $message;
        $this->managerStatusLevel = $level;
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

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeHandle(?string $value): ?string
    {
        $value = $this->nullableTrim($value);

        return $value ? ltrim($value, '@') : null;
    }
}
