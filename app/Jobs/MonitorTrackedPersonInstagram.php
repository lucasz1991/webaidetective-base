<?php

namespace App\Jobs;

use App\Exceptions\TrackedPersonInstagramScanCancelledException;
use App\Models\Mail;
use App\Models\TrackedPerson;
use App\Services\TrackedPeople\TrackedPersonInstagramWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class MonitorTrackedPersonInstagram implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $trackedPersonId,
        public readonly bool $force = false,
        public readonly bool $sendNotifications = true,
        public readonly bool $fullScan = false,
    ) {
    }

    public function handle(): void
    {
        $this->run();
    }

    private function run(): void
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
            $fullScan = $this->isFullScan();
            $scanLabel = $fullScan ? 'Instagram-Vollanalyse' : 'Instagram-Mini-Scan';

            $trackedPerson->forceFill([
                'last_instagram_status_level' => 'partial',
                'last_instagram_status_message' => $scanLabel.' laeuft im Hintergrund.',
            ])->save();

            $runResult = app(TrackedPersonInstagramWorkflowService::class)->runAnalysis(
                $trackedPerson,
                $fullScan,
                null,
            );
            $snapshot = $runResult['snapshot'];
        } catch (TrackedPersonInstagramScanCancelledException $exception) {
            $trackedPerson->markInstagramScanTerminal(
                'cancelled',
                'Instagram-Scan wurde abgebrochen, weil ein neuer Scan gestartet wurde.',
            );

            Log::info('Instagram-Monitoring-Scan wurde beendet, weil ein neuer Scan fuer dieselbe Person gestartet wurde.', [
                'tracked_person_id' => $trackedPerson->id,
                'instagram_username' => $trackedPerson->instagram_username,
                'full_scan' => $this->isFullScan(),
            ]);

            return;
        } catch (\Throwable $exception) {
            if (str_contains($exception->getMessage(), 'laeuft bereits eine Instagram-Analyse')) {
                Log::info('Monitoring fuer getrackte Person uebersprungen, weil bereits eine Instagram-Analyse laeuft.', [
                    'tracked_person_id' => $trackedPerson->id,
                    'instagram_username' => $trackedPerson->instagram_username,
                    'full_scan' => $this->isFullScan(),
                ]);

                return;
            }

            Log::warning('Monitoring fuer getrackte Person fehlgeschlagen.', [
                'tracked_person_id' => $trackedPerson->id,
                'instagram_username' => $trackedPerson->instagram_username,
                'full_scan' => $this->isFullScan(),
                'error' => $exception->getMessage(),
            ]);

            $trackedPerson->markInstagramScanTerminal(
                'error',
                ($this->isFullScan() ? 'Instagram-Vollanalyse' : 'Instagram-Mini-Scan').' fehlgeschlagen: '.$exception->getMessage(),
            );

            return;
        }

        $privateSuggestionScan = $runResult['privateSuggestionScan'] ?? null;
        $privateSuggestionScanFailed = (bool) ($runResult['privateSuggestionScanFailed'] ?? false);
        $privateSuggestionScanMessage = (string) ($runResult['privateSuggestionScanMessage'] ?? '');
        $relationshipScans = $runResult['relationshipScans'] ?? [];
        $postScan = $runResult['postScan'] ?? null;
        $followUpFailures = $runResult['followUpFailures'] ?? [];

        if ($privateSuggestionScan) {
            Log::info('Privates Instagram-Profil mit relevanter Aenderung erkannt; Vorschlag-Verbindungsscan wurde ausgefuehrt.', [
                'tracked_person_id' => $trackedPerson->id,
                'instagram_username' => $trackedPerson->instagram_username,
                'snapshot_id' => $snapshot?->id,
                'suggestion_scan_id' => $privateSuggestionScan->id,
            ]);
        }

        if ($privateSuggestionScanFailed) {
            Log::warning('Vorschlag-Verbindungsscan nach privater Instagram-Aenderung fehlgeschlagen.', [
                'tracked_person_id' => $trackedPerson->id,
                'instagram_username' => $trackedPerson->instagram_username,
                'snapshot_id' => $snapshot?->id,
                'message' => $privateSuggestionScanMessage,
            ]);
        }

        if (! $this->isFullScan() && ($relationshipScans !== [] || $postScan || $privateSuggestionScan || $followUpFailures !== [])) {
            Log::info('Instagram-Kennzahlenaenderung erkannt; passende Folge-Scans wurden nach dem Mini-Scan ausgefuehrt.', [
                'tracked_person_id' => $trackedPerson->id,
                'instagram_username' => $trackedPerson->instagram_username,
                'relationship_scans' => array_keys($relationshipScans),
                'post_scan_id' => $postScan?->id,
                'suggestion_scan_id' => $privateSuggestionScan?->id,
                'follow_up_failures' => $followUpFailures,
            ]);
        }

        if (! $this->sendNotifications || ! $this->snapshotHasNotificationChanges($snapshot)) {
            Log::info('Instagram-Monitoring-Scan abgeschlossen; keine Benachrichtigung verschickt.', [
                'tracked_person_id' => $trackedPerson->id,
                'instagram_username' => $trackedPerson->instagram_username,
                'snapshot_id' => $snapshot->id,
                'scan_mode' => data_get($snapshot->raw_payload, 'analysisPolicy.scanMode'),
                'send_notifications' => $this->sendNotifications,
                'has_changes' => (bool) $snapshot->has_changes,
                'detected_changes_count' => count($snapshot->detected_changes ?? []),
            ]);

            return;
        }

        $notifiedTrackedPersonIds = $this->notifyLinkedTrackedPeople($trackedPerson, $snapshot);

        Log::info('Instagram-Aenderungsbenachrichtigung wurde erstellt.', [
            'tracked_person_id' => $trackedPerson->id,
            'instagram_username' => $trackedPerson->instagram_username,
            'snapshot_id' => $snapshot->id,
            'notified_tracked_person_ids' => $notifiedTrackedPersonIds,
        ]);
    }

    private function notifyLinkedTrackedPeople(TrackedPerson $scannedTrackedPerson, $snapshot): array
    {
        $trackedPeople = $this->linkedTrackedPeopleForNotification($scannedTrackedPerson);
        $notifiedTrackedPersonIds = [];

        foreach ($trackedPeople as $trackedPerson) {
            if (! $this->shouldSendInstagramNotification($trackedPerson, $snapshot)) {
                continue;
            }

            $owner = $trackedPerson->user;

            if (! $owner) {
                Log::warning('Instagram-Benachrichtigung konnte nicht erstellt werden, weil kein Besitzer gefunden wurde.', [
                    'tracked_person_id' => $trackedPerson->id,
                    'instagram_username' => $trackedPerson->instagram_username,
                    'snapshot_id' => $snapshot->id,
                ]);

                continue;
            }

            Mail::create([
                'type' => $this->resolveNotificationDeliveryType($trackedPerson),
                'from_user_id' => $owner->id,
                'content' => [
                    'subject' => 'Aenderungen bei '.$trackedPerson->display_name,
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

            $notifiedTrackedPersonIds[] = (int) $trackedPerson->id;
        }

        return $notifiedTrackedPersonIds;
    }

    private function linkedTrackedPeopleForNotification(TrackedPerson $trackedPerson)
    {
        $profileId = (int) ($trackedPerson->current_instagram_profile_id ?? 0);

        if ($profileId > 0) {
            return TrackedPerson::query()
                ->with('user')
                ->where('current_instagram_profile_id', $profileId)
                ->get();
        }

        return TrackedPerson::query()
            ->with('user')
            ->whereRaw("LOWER(TRIM(LEADING '@' FROM instagram_username)) = ?", [strtolower(ltrim((string) $trackedPerson->instagram_username, '@'))])
            ->get();
    }

    private function resolveNotificationDeliveryType(TrackedPerson $trackedPerson): string
    {
        $type = strtolower((string) ($trackedPerson->notification_delivery_type ?: 'both'));

        return in_array($type, ['message', 'mail', 'both'], true) ? $type : 'both';
    }

    public function uniqueId(): string
    {
        return 'tracked-person:'.$this->trackedPersonId.':'.($this->isFullScan() ? 'full' : 'mini');
    }

    private function isFullScan(): bool
    {
        return isset($this->fullScan) && $this->fullScan;
    }

    private function shouldSendInstagramNotification(TrackedPerson $trackedPerson, $snapshot): bool
    {
        return $this->sendNotifications
            && (bool) $trackedPerson->notify_social_changes
            && (bool) $trackedPerson->notify_instagram_changes
            && $this->snapshotHasNotificationChanges($snapshot);
    }

    private function notificationSkipReasons(TrackedPerson $trackedPerson, $snapshot): array
    {
        $reasons = [];

        if (! $this->sendNotifications) {
            $reasons[] = 'send_notifications_disabled';
        }

        if (! (bool) $trackedPerson->notify_social_changes) {
            $reasons[] = 'social_notifications_disabled';
        }

        if (! (bool) $trackedPerson->notify_instagram_changes) {
            $reasons[] = 'instagram_notifications_disabled';
        }

        if (! $this->snapshotHasNotificationChanges($snapshot)) {
            $reasons[] = 'no_snapshot_changes';
        }

        return $reasons;
    }

    private function snapshotHasNotificationChanges($snapshot): bool
    {
        return (bool) $snapshot->has_changes || count($snapshot->detected_changes ?? []) > 0;
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
