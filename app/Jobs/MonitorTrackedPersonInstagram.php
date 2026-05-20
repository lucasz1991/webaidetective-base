<?php

namespace App\Jobs;

use App\Models\Mail;
use App\Models\TrackedPerson;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class MonitorTrackedPersonInstagram implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public function __construct(
        public readonly int $trackedPersonId,
        public readonly bool $force = false,
        public readonly bool $sendNotifications = true,
    ) {
    }

    public function handle(): void
    {
        $trackedPerson = TrackedPerson::query()
            ->with('user')
            ->find($this->trackedPersonId);

        if (! $trackedPerson || ! $trackedPerson->instagram_username) {
            return;
        }

        if (! $this->force && ! $trackedPerson->monitoring_enabled) {
            return;
        }

        try {
            $trackedPerson->forceFill([
                'last_instagram_status_level' => 'partial',
                'last_instagram_status_message' => 'Instagram-Mini-Scan laeuft im Hintergrund.',
            ])->save();

            $snapshot = $trackedPerson->analyzeInstagram();
        } catch (\Throwable $exception) {
            Log::warning('Monitoring fuer getrackte Person fehlgeschlagen.', [
                'tracked_person_id' => $trackedPerson->id,
                'instagram_username' => $trackedPerson->instagram_username,
                'error' => $exception->getMessage(),
            ]);

            $trackedPerson->forceFill([
                'last_instagram_status_level' => 'error',
                'last_instagram_status_message' => 'Instagram-Analyse fehlgeschlagen: '.$exception->getMessage(),
            ])->save();

            return;
        }

        if (! $this->sendNotifications || ! $trackedPerson->notify_social_changes || ! $snapshot->has_changes) {
            return;
        }

        $owner = $trackedPerson->user;

        if (! $owner) {
            return;
        }

        $subject = 'Aenderungen bei '.$trackedPerson->display_name;

        Mail::create([
            'type' => $this->resolveNotificationDeliveryType($trackedPerson),
            'from_user_id' => $owner->id,
            'content' => [
                'subject' => $subject,
                'header' => 'Automatische Beobachtung',
                'body' => $this->buildNotificationMessage($trackedPerson, $snapshot),
                'link' => route('messages'),
            ],
            'recipients' => [
                [
                    'user_id' => $owner->id,
                    'email' => $owner->email,
                    'status' => false,
                ],
            ],
        ]);
    }

    private function resolveNotificationDeliveryType(TrackedPerson $trackedPerson): string
    {
        $type = strtolower((string) ($trackedPerson->notification_delivery_type ?: 'both'));

        return in_array($type, ['message', 'mail', 'both'], true) ? $type : 'both';
    }

    private function buildNotificationMessage(TrackedPerson $trackedPerson, $snapshot): string
    {
        $changesMarkup = collect($snapshot->detected_changes ?? [])
            ->map(function (array $change) {
                $before = $this->formatChangeValue(Arr::get($change, 'before'));
                $after = $this->formatChangeValue(Arr::get($change, 'after'));
                $label = e((string) (Arr::get($change, 'label') ?: Arr::get($change, 'field', 'Aenderung')));

                return '<li><strong>'.$label.':</strong> '.$before.' &rarr; '.$after.'</li>';
            })
            ->implode('');

        $timestamp = optional($snapshot->analyzed_at)->format('d.m.Y H:i') ?: now()->format('d.m.Y H:i');
        $username = $trackedPerson->instagram_username ? '@'.e($trackedPerson->instagram_username) : 'ohne Instagram-Namen';

        return implode('', [
            '<p>Bei <strong>'.e($trackedPerson->display_name).'</strong> wurden waehrend der automatischen Dauerbeobachtung Aenderungen erkannt.</p>',
            '<p><strong>Profil:</strong> '.$username.'<br><strong>Analysezeit:</strong> '.e($timestamp).'</p>',
            '<ul>'.$changesMarkup.'</ul>',
            '<p><strong>Status:</strong> '.e((string) $snapshot->status_message).'</p>',
        ]);
    }

    private function formatChangeValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_numeric($value)) {
            return number_format((float) $value, 0, ',', '.');
        }

        if (is_string($value) && strlen($value) === 64 && preg_match('/^[a-f0-9]{64}$/i', $value)) {
            return 'Hash '.e(substr($value, 0, 12)).'...';
        }

        return e((string) $value);
    }
}
