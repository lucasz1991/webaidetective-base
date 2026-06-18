<?php

namespace App\Services\TrackedPeople;

use App\Models\InstagramProfile;
use App\Models\Mail;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramProfileLink;
use App\Models\TrackedPersonInstagramSnapshot;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InstagramProfileChangeNotificationService
{
    /**
     * @return array<int, int>
     */
    public function notifySnapshotChanges(TrackedPersonInstagramSnapshot $snapshot): array
    {
        $changes = collect($snapshot->detected_changes ?? [])
            ->filter(fn ($change): bool => is_array($change))
            ->values()
            ->all();

        if (! $snapshot->has_changes && $changes === []) {
            return [];
        }

        $snapshot->loadMissing(['trackedPerson', 'instagramProfile']);
        $profile = $snapshot->instagramProfile
            ?: $snapshot->trackedPerson?->currentInstagramProfile;
        $username = $this->normalizeUsername(
            $profile?->username
                ?: $snapshot->instagram_username
                ?: $snapshot->trackedPerson?->instagram_username,
        );

        if (! $profile && $username !== null) {
            $profile = InstagramProfile::query()->where('username', $username)->first();
        }

        return $this->notifyChanges(
            $profile,
            $username,
            $changes,
            (string) $snapshot->status_message,
            $snapshot->analyzed_at,
            'snapshot-'.$snapshot->id,
        );
    }

    /**
     * @return array<int, int>
     */
    public function notifyProfileChanges(
        InstagramProfile $profile,
        array $changes,
        ?string $statusMessage = null,
        mixed $analyzedAt = null,
        ?string $notificationSourceKey = null,
    ): array {
        $changes = collect($changes)
            ->filter(fn ($change): bool => is_array($change))
            ->values()
            ->all();

        if ($changes === []) {
            return [];
        }

        return $this->notifyChanges(
            $profile,
            $this->normalizeUsername($profile->username),
            $changes,
            $statusMessage ?: (string) $profile->last_status_message,
            $analyzedAt ?: $profile->last_scanned_at,
            $notificationSourceKey ?: 'profile-'.$profile->id.'-'.sha1(json_encode($changes)),
        );
    }

    /**
     * @return array<int, int>
     */
    private function notifyChanges(
        ?InstagramProfile $profile,
        ?string $username,
        array $changes,
        string $statusMessage,
        mixed $analyzedAt,
        string $notificationSourceKey,
    ): array {
        $trackedPeople = $this->observedPeopleForProfile($profile, $username);
        $notifiedTrackedPersonIds = [];

        foreach ($trackedPeople as $trackedPerson) {
            if (! $this->notificationsEnabled($trackedPerson)) {
                continue;
            }

            $notificationKey = 'instagram-change-notification:'.$notificationSourceKey.':'.$trackedPerson->id;

            if (Cache::has($notificationKey)) {
                continue;
            }

            $owner = $trackedPerson->user;

            if (! $owner) {
                Log::warning('Instagram-Aenderungsbenachrichtigung ohne Besitzer uebersprungen.', [
                    'tracked_person_id' => $trackedPerson->id,
                    'snapshot_id' => $snapshot->id,
                ]);

                continue;
            }

            Mail::create([
                'type' => $this->deliveryType($trackedPerson),
                'from_user_id' => $owner->id,
                'content' => [
                    'subject' => 'Aenderungen bei '.$trackedPerson->display_name,
                    'header' => 'Instagram-Beobachtung',
                    'body' => $this->notificationBody(
                        $trackedPerson,
                        $changes,
                        $statusMessage,
                        $analyzedAt,
                    ),
                    'link' => route('tracked-people.show', ['trackedPersonId' => $trackedPerson->id]),
                ],
                'recipients' => [[
                    'user_id' => $owner->id,
                    'email' => $owner->email,
                    'status' => false,
                ]],
            ]);

            Cache::put($notificationKey, true, now()->addDays(14));
            $notifiedTrackedPersonIds[] = (int) $trackedPerson->id;
        }

        return $notifiedTrackedPersonIds;
    }

    private function observedPeopleForProfile(?InstagramProfile $profile, ?string $username): Collection
    {
        $profileId = (int) ($profile?->id ?? 0);
        $linkedTrackedPersonIds = $profileId > 0
            ? TrackedPersonInstagramProfileLink::query()
                ->where('instagram_profile_id', $profileId)
                ->where('is_current', true)
                ->pluck('tracked_person_id')
                ->all()
            : [];

        return TrackedPerson::query()
            ->with('user')
            ->where(function ($query) use ($profileId, $linkedTrackedPersonIds, $username): void {
                if ($profileId > 0) {
                    $query->where('current_instagram_profile_id', $profileId);
                } else {
                    $query->whereRaw('1 = 0');
                }

                if ($linkedTrackedPersonIds !== []) {
                    $query->orWhereIn('id', $linkedTrackedPersonIds);
                }

                if ($username !== null) {
                    $query->orWhereRaw(
                        "LOWER(TRIM(REPLACE(instagram_username, '@', ''))) = ?",
                        [$username],
                    );
                }
            })
            ->get()
            ->unique('id')
            ->values();
    }

    private function notificationsEnabled(TrackedPerson $trackedPerson): bool
    {
        return (bool) $trackedPerson->notify_social_changes
            && (bool) $trackedPerson->notify_instagram_changes;
    }

    private function deliveryType(TrackedPerson $trackedPerson): string
    {
        $type = Str::lower((string) ($trackedPerson->notification_delivery_type ?: 'both'));

        return in_array($type, ['message', 'mail', 'both'], true) ? $type : 'both';
    }

    private function notificationBody(
        TrackedPerson $trackedPerson,
        array $changes,
        string $statusMessage,
        mixed $analyzedAt,
    ): string {
        $changesMarkup = collect($changes)
            ->map(function (array $change): string {
                $before = $this->formatValue(Arr::get($change, 'before'));
                $after = $this->formatValue(Arr::get($change, 'after'));
                $label = e((string) (Arr::get($change, 'label') ?: Arr::get($change, 'field', 'Aenderung')));

                return '<li><strong>'.$label.':</strong> '.$before.' &rarr; '.$after.'</li>';
            })
            ->implode('');
        $timestamp = $analyzedAt
            ? Carbon::parse($analyzedAt)->timezone(config('app.timezone'))->format('d.m.Y H:i')
            : now()->format('d.m.Y H:i');
        $username = $trackedPerson->instagram_username
            ? '@'.e($trackedPerson->instagram_username)
            : 'ohne Instagram-Namen';

        return implode('', [
            '<p>Bei <strong>'.e($trackedPerson->display_name).'</strong> wurden durch einen Instagram-Scan Aenderungen erkannt.</p>',
            '<p><strong>Profil:</strong> '.$username.'<br><strong>Analysezeit:</strong> '.e($timestamp).'</p>',
            '<ul>'.$changesMarkup.'</ul>',
            '<p><strong>Status:</strong> '.e($statusMessage).'</p>',
        ]);
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_numeric($value)) {
            return number_format((float) $value, 0, ',', '.');
        }

        if (is_string($value) && strlen($value) === 64 && preg_match('/^[a-f0-9]{64}$/i', $value)) {
            return 'Hash '.e(substr($value, 0, 12)).'...';
        }

        return e((string) $value);
    }

    private function normalizeUsername(mixed $username): ?string
    {
        if (! is_scalar($username)) {
            return null;
        }

        $username = Str::lower(ltrim(trim((string) $username), '@'));

        return $username !== '' ? $username : null;
    }
}
