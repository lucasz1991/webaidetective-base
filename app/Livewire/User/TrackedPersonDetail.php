<?php

namespace App\Livewire\User;

use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramMedia;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TrackedPersonDetail extends Component
{
    public int $trackedPersonId;

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
    public $monitoring_enabled = false;
    public $notify_social_changes = false;
    public $notify_instagram_changes = true;
    public $notify_tiktok_changes = true;
    public $notify_facebook_changes = true;
    public $notify_x_changes = true;
    public $notify_youtube_changes = true;
    public $notify_snapchat_changes = true;

    public $knownFactLabel = '';
    public $knownFactValue = '';
    public $knownFactSource = '';
    public $knownFactNotes = '';
    public $publicProfilePlatform = 'instagram';
    public $publicProfileUsername = '';
    public $publicProfileDisplayName = '';
    public $publicProfileRelationshipType = 'public_connection';
    public $publicProfileUrl = '';
    public $publicProfileNotes = '';
    public $publicProfileIsPublic = true;

    public $detailStatus = null;
    public $detailStatusLevel = 'neutral';

    protected $listeners = [
        'tracked-person-refresh' => '$refresh',
    ];

    public function mount(int $trackedPersonId): void
    {
        $this->trackedPersonId = $trackedPersonId;
        $this->fillFormFromModel($this->resolveTrackedPerson());
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
            'monitoring_enabled' => ['boolean'],
            'notify_social_changes' => ['boolean'],
            'notify_instagram_changes' => ['boolean'],
            'notify_tiktok_changes' => ['boolean'],
            'notify_facebook_changes' => ['boolean'],
            'notify_x_changes' => ['boolean'],
            'notify_youtube_changes' => ['boolean'],
            'notify_snapchat_changes' => ['boolean'],
        ];
    }

    public function saveTrackedPerson(): void
    {
        $validated = $this->validate();
        $trackedPerson = $this->resolveTrackedPerson();

        $trackedPerson->update([
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
            'monitoring_enabled' => (bool) $this->monitoring_enabled,
            'notify_social_changes' => (bool) $this->notify_social_changes,
            'notify_instagram_changes' => (bool) $this->notify_instagram_changes,
            'notify_tiktok_changes' => (bool) $this->notify_tiktok_changes,
            'notify_facebook_changes' => (bool) $this->notify_facebook_changes,
            'notify_x_changes' => (bool) $this->notify_x_changes,
            'notify_youtube_changes' => (bool) $this->notify_youtube_changes,
            'notify_snapchat_changes' => (bool) $this->notify_snapchat_changes,
        ]);

        $freshPerson = $trackedPerson->fresh();
        $this->fillFormFromModel($freshPerson);
        $this->setDetailStatus('Personendaten wurden gespeichert.', 'success');
        $this->dispatch('tracked-person-refresh');
    }

    public function analyzeInstagram(): void
    {
        @set_time_limit(0);

        $trackedPerson = $this->resolveTrackedPerson();
        $progress = fn (array $state) => $this->streamInstagramProgress($state);

        try {
            $this->streamInstagramProgress([
                'phase' => 'start',
                'percent' => 1,
                'message' => 'Instagram-Analyse wird vorbereitet.',
            ]);

            $snapshot = $trackedPerson->analyzeInstagram($progress);
        } catch (\Throwable $exception) {
            $this->streamInstagramProgress([
                'phase' => 'error',
                'percent' => 100,
                'message' => 'Instagram-Analyse fehlgeschlagen.',
            ]);
            $this->setDetailStatus(
                'Instagram-Analyse fehlgeschlagen: '.$exception->getMessage(),
                'error',
            );

            return;
        }

        $this->fillFormFromModel($trackedPerson->fresh());
        $this->setDetailStatus(
            'Instagram-Analyse abgeschlossen: '.$snapshot->status_message,
            $snapshot->status_level === 'success' ? 'success' : ($snapshot->status_level === 'partial' ? 'partial' : 'error'),
        );
        $this->dispatch('tracked-person-refresh');
    }

    private function streamInstagramProgress(array $state): void
    {
        $percent = max(0, min(100, (int) ($state['percent'] ?? 0)));
        $phase = match ($state['phase'] ?? 'analysis') {
            'start' => 'Start',
            'profile' => 'Grunddaten',
            'followers' => 'Followerliste',
            'following' => 'Gefolgt-Liste',
            'saving' => 'Speichern',
            'done' => 'Fertig',
            'error' => 'Fehler',
            default => 'Analyse',
        };
        $message = (string) ($state['message'] ?? 'Instagram-Analyse laeuft.');

        $this->stream('instagram-progress-phase', e($phase), true);
        $this->stream('instagram-progress-message', e($message), true);
        $this->stream('instagram-progress-percent', $percent.'%', true);
        $this->stream(
            'instagram-progress-bar',
            '<div class="h-full rounded-full bg-pink-600 transition-all duration-300" style="width: '.$percent.'%"></div>',
            true,
        );
    }

    public function saveKnownFact(): void
    {
        $this->validate([
            'knownFactLabel' => ['required', 'string', 'max:255'],
            'knownFactValue' => ['required', 'string'],
            'knownFactSource' => ['nullable', 'string', 'max:255'],
            'knownFactNotes' => ['nullable', 'string'],
        ]);

        $trackedPerson = $this->resolveTrackedPerson();
        $trackedPerson->knownFacts()->create([
            'user_id' => Auth::id(),
            'label' => trim($this->knownFactLabel),
            'value' => trim($this->knownFactValue),
            'source' => $this->nullableTrim($this->knownFactSource),
            'notes' => $this->nullableTrim($this->knownFactNotes),
        ]);

        $this->reset([
            'knownFactLabel',
            'knownFactValue',
            'knownFactSource',
            'knownFactNotes',
        ]);

        $this->setDetailStatus('Bekannte Daten wurden gespeichert.', 'success');
        $this->dispatch('tracked-person-refresh');
    }

    public function savePublicProfile(): void
    {
        $validated = $this->validate([
            'publicProfilePlatform' => ['required', 'string', 'in:instagram,tiktok,facebook,x,youtube,snapchat,other'],
            'publicProfileUsername' => ['required', 'string', 'max:255'],
            'publicProfileDisplayName' => ['nullable', 'string', 'max:255'],
            'publicProfileRelationshipType' => ['required', 'string', 'in:follows_target,followed_by_target,mutual,public_connection'],
            'publicProfileUrl' => ['nullable', 'url', 'max:2048'],
            'publicProfileNotes' => ['nullable', 'string'],
            'publicProfileIsPublic' => ['boolean'],
        ]);

        $trackedPerson = $this->resolveTrackedPerson();
        $normalizedUsername = $this->normalizeHandle($validated['publicProfileUsername']);

        $publicProfile = $trackedPerson->publicProfiles()->firstOrNew([
            'platform' => $validated['publicProfilePlatform'],
            'username' => $normalizedUsername,
        ]);
        $wasExisting = $publicProfile->exists;

        $publicProfile->fill([
            'user_id' => Auth::id(),
            'display_name' => $this->nullableTrim($validated['publicProfileDisplayName'] ?? null),
            'relationship_type' => $validated['publicProfileRelationshipType'],
            'profile_url' => $this->nullableTrim($validated['publicProfileUrl'] ?? null),
            'notes' => $this->nullableTrim($validated['publicProfileNotes'] ?? null),
            'is_public' => (bool) $this->publicProfileIsPublic,
        ]);
        $publicProfile->save();

        $this->resetPublicProfileForm();
        $this->setDetailStatus(
            $wasExisting
                ? 'Bekanntes oeffentliches Profil wurde aktualisiert.'
                : 'Bekanntes oeffentliches Profil wurde gespeichert.',
            'success',
        );
        $this->dispatch('tracked-person-refresh');
    }

    public function deletePublicProfile(int $publicProfileId): void
    {
        $trackedPerson = $this->resolveTrackedPerson();

        $trackedPerson->publicProfiles()
            ->whereKey($publicProfileId)
            ->delete();

        $this->setDetailStatus('Oeffentliches Profil wurde entfernt.', 'success');
        $this->dispatch('tracked-person-refresh');
    }

    public function render()
    {
        $trackedPerson = $this->resolveTrackedPerson()
            ->load([
                'knownFacts' => fn ($query) => $query->latest(),
                'publicProfiles' => fn ($query) => $query->latest(),
                'latestInstagramSnapshot.media' => fn ($query) => $query->orderBy('sort_order'),
                'instagramSnapshots' => fn ($query) => $query
                    ->where('has_changes', true)
                    ->latest('analyzed_at')
                    ->limit(6),
            ]);
        $profileImageHistory = TrackedPersonInstagramMedia::query()
            ->with([
                'snapshot' => fn ($query) => $query->select('id', 'tracked_person_id', 'analyzed_at'),
            ])
            ->where('tracked_person_id', $trackedPerson->id)
            ->where('is_profile_image', true)
            ->whereNotNull('storage_path')
            ->latest('id')
            ->limit(50)
            ->get()
            ->unique('content_hash')
            ->take(12)
            ->values();

        return view('livewire.user.tracked-person-detail', [
            'trackedPerson' => $trackedPerson,
            'profileImageHistory' => $profileImageHistory,
        ]);
    }

    private function resolveTrackedPerson(): TrackedPerson
    {
        return Auth::user()
            ->trackedPeople()
            ->whereKey($this->trackedPersonId)
            ->firstOrFail();
    }

    private function fillFormFromModel(TrackedPerson $trackedPerson): void
    {
        $this->first_name = $trackedPerson->first_name;
        $this->last_name = $trackedPerson->last_name;
        $this->alias = $trackedPerson->alias ?? '';
        $this->date_of_birth = optional($trackedPerson->date_of_birth)->format('Y-m-d') ?? '';
        $this->city = $trackedPerson->city ?? '';
        $this->country = $trackedPerson->country ?? '';
        $this->notes = $trackedPerson->notes ?? '';
        $this->instagram_username = $trackedPerson->instagram_username ?? '';
        $this->tiktok_username = $trackedPerson->tiktok_username ?? '';
        $this->facebook_username = $trackedPerson->facebook_username ?? '';
        $this->x_username = $trackedPerson->x_username ?? '';
        $this->youtube_username = $trackedPerson->youtube_username ?? '';
        $this->snapchat_username = $trackedPerson->snapchat_username ?? '';
        $this->monitoring_enabled = (bool) $trackedPerson->monitoring_enabled;
        $this->notify_social_changes = (bool) $trackedPerson->notify_social_changes;
        $this->notify_instagram_changes = (bool) $trackedPerson->notify_instagram_changes;
        $this->notify_tiktok_changes = (bool) $trackedPerson->notify_tiktok_changes;
        $this->notify_facebook_changes = (bool) $trackedPerson->notify_facebook_changes;
        $this->notify_x_changes = (bool) $trackedPerson->notify_x_changes;
        $this->notify_youtube_changes = (bool) $trackedPerson->notify_youtube_changes;
        $this->notify_snapchat_changes = (bool) $trackedPerson->notify_snapchat_changes;
    }

    private function setDetailStatus(string $message, string $level): void
    {
        $this->detailStatus = $message;
        $this->detailStatusLevel = $level;
    }

    private function resetPublicProfileForm(): void
    {
        $this->publicProfilePlatform = 'instagram';
        $this->publicProfileUsername = '';
        $this->publicProfileDisplayName = '';
        $this->publicProfileRelationshipType = 'public_connection';
        $this->publicProfileUrl = '';
        $this->publicProfileNotes = '';
        $this->publicProfileIsPublic = true;
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
