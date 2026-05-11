<?php

namespace App\Livewire\User;

use App\Models\TrackedPerson;
use Illuminate\Support\Facades\Auth;
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
    public bool $showCreateForm = false;
    public $managerStatus = null;
    public $managerStatusLevel = 'neutral';

    protected $listeners = [
        'tracked-person-refresh' => '$refresh',
    ];

    public function mount(): void
    {
        $user = Auth::user();

        if ($user) {
            $this->selectedTrackedPersonId = $user->trackedPeople()->orderBy('last_name')->orderBy('first_name')->value('id');
        }
    }

    protected function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'instagram_username' => ['nullable', 'string', 'max:255'],
            'tiktok_username' => ['nullable', 'string', 'max:255'],
            'facebook_username' => ['nullable', 'string', 'max:255'],
            'x_username' => ['nullable', 'string', 'max:255'],
            'youtube_username' => ['nullable', 'string', 'max:255'],
            'snapchat_username' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
    }

    public function selectTrackedPerson(int $trackedPersonId): void
    {
        $this->selectedTrackedPersonId = $trackedPersonId;
    }

    public function createTrackedPerson(): void
    {
        $validated = $this->validate();
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
        ];

        $person = Auth::user()->trackedPeople()->create($validated);

        $this->selectedTrackedPersonId = $person->id;
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
                ->with('latestInstagramSnapshot')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
            : collect();

        if ($trackedPeople->isNotEmpty() && ! $trackedPeople->contains('id', $this->selectedTrackedPersonId)) {
            $this->selectedTrackedPersonId = $trackedPeople->first()->id;
        }

        if ($trackedPeople->isEmpty()) {
            $this->selectedTrackedPersonId = null;
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
