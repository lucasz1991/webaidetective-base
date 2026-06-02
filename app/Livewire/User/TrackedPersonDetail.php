<?php

namespace App\Livewire\User;

use App\Exceptions\TrackedPersonInstagramScanCancelledException;
use App\Jobs\MonitorTrackedPersonInstagram;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramMedia;
use App\Services\TrackedPeople\InstagramProfileRelationshipStore;
use App\Services\TrackedPeople\TrackedPersonInstagramAnalysisService;
use App\Services\TrackedPeople\TrackedPersonInstagramPublicProfileScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramScanCoordinator;
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
    public $notification_delivery_type = 'both';
    public $monitoring_enabled = false;
    public $notify_social_changes = false;
    public $notify_instagram_changes = true;
    public $notify_tiktok_changes = true;
    public $notify_facebook_changes = true;
    public $notify_x_changes = true;
    public $notify_youtube_changes = true;
    public $notify_snapchat_changes = true;
    public bool $showFollowersModal = false;
    public bool $showFollowingModal = false;
    public bool $showSettingsModal = false;

    public $knownFactLabel = '';
    public $knownFactValue = '';
    public $knownFactSource = '';
    public $knownFactNotes = '';
    public $publicProfileTrackedPersonId = '';
    public $publicProfileRelationshipType = 'public_connection';
    public $publicProfileNotes = '';

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
            'notification_delivery_type' => ['required', 'string', 'in:message,mail,both'],
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
            'notification_delivery_type' => $validated['notification_delivery_type'],
            'monitoring_enabled' => (bool) $this->monitoring_enabled,
            'notify_social_changes' => (bool) $this->notify_social_changes,
            'notify_instagram_changes' => (bool) $this->notify_instagram_changes,
            'notify_tiktok_changes' => (bool) $this->notify_tiktok_changes,
            'notify_facebook_changes' => (bool) $this->notify_facebook_changes,
            'notify_x_changes' => (bool) $this->notify_x_changes,
            'notify_youtube_changes' => (bool) $this->notify_youtube_changes,
            'notify_snapchat_changes' => (bool) $this->notify_snapchat_changes,
        ]);

        app(InstagramProfileRelationshipStore::class)->syncTrackedPersonProfile($trackedPerson->fresh());

        $freshPerson = $trackedPerson->fresh();
        $this->fillFormFromModel($freshPerson);
        $this->showSettingsModal = false;
        $this->setDetailStatus('Personendaten wurden gespeichert.', 'success');
        $this->dispatch('tracked-person-refresh');
    }

    public function analyzeInstagramMini(): void
    {
        $this->runInstagramAnalysis(false);
    }

    public function analyzeInstagram(): void
    {
        $this->runInstagramAnalysis(true);
    }

    public function scanInstagramFollowersList(): void
    {
        $this->runInstagramRelationshipListScan('followers');
    }

    public function scanInstagramFollowingList(): void
    {
        $this->runInstagramRelationshipListScan('following');
    }

    public function scanPublicProfileConnections(): void
    {
        @set_time_limit(0);
        @ignore_user_abort(false);

        $trackedPerson = $this->resolveTrackedPerson();

        if (! $trackedPerson->instagram_username) {
            $this->setDetailStatus('Fuer diese Person ist kein Instagram-Name hinterlegt.', 'error');

            return;
        }

        $progress = fn (array $state) => $this->streamInstagramProgress($state);
        $this->cancelInstagramScanWhenClientDisconnects($trackedPerson->id);

        try {
            $this->streamInstagramProgress([
                'phase' => 'public-connections',
                'percent' => 1,
                'message' => 'Public-Profile-Verbindungsscan wird vorbereitet.',
                'foundFollowers' => 0,
                'foundFollowing' => 0,
                'inferredFollowers' => [],
                'inferredFollowing' => [],
            ]);

            $scans = app(TrackedPersonInstagramPublicProfileScanService::class)->scan($trackedPerson, $progress);
        } catch (TrackedPersonInstagramScanCancelledException $exception) {
            $this->streamInstagramProgress([
                'phase' => 'done',
                'percent' => 100,
                'message' => 'Vorheriger Scan wurde beendet, weil ein neuer Scan gestartet wurde.',
            ]);
            $this->setDetailStatus('Vorheriger Instagram-Scan wurde beendet, weil ein neuer Scan gestartet wurde.', 'partial');

            return;
        } catch (\Throwable $exception) {
            $this->streamInstagramProgress([
                'phase' => 'error',
                'percent' => 100,
                'message' => 'Public-Profile-Verbindungsscan fehlgeschlagen.',
            ]);
            $this->setDetailStatus('Public-Profile-Verbindungsscan fehlgeschlagen: '.$exception->getMessage(), 'error');

            return;
        }

        $inferredFollowersCount = $scans->sum(fn ($scan) => count(data_get($scan->raw_payload, 'inferredFollowers', [])));
        $inferredFollowingCount = $scans->sum(fn ($scan) => count(data_get($scan->raw_payload, 'inferredFollowing', [])));
        $pausedForRateLimit = $scans->contains(fn ($scan) => (bool) data_get($scan->raw_payload, 'stoppedForRateLimit', false));
        $stoppedByUser = $scans->isEmpty() || $scans->contains(fn ($scan) => (bool) data_get($scan->raw_payload, 'gracefullyStopped', false));

        $this->setDetailStatus(
            ($stoppedByUser
                ? 'Public-Profile-Verbindungsscan wurde beendet; bisherige Treffer und Kandidatenfortschritt wurden gespeichert: '
                : ($pausedForRateLimit
                ? 'Public-Profile-Verbindungsscan wegen Instagram-Rate-Limit pausiert. Spaeter erneut starten, um ab dem gespeicherten Kandidatenstand fortzusetzen: '
                : 'Public-Profile-Verbindungsscan abgeschlossen: '))
            .$inferredFollowersCount.' moegliche Follower und '.$inferredFollowingCount.' moegliche Gefolgt-Profile gefunden.',
            $stoppedByUser || $pausedForRateLimit || $scans->contains(fn ($scan) => $scan->status_level === 'error') ? 'partial' : 'success',
        );
        $this->dispatch('tracked-person-refresh');
    }

    private function runInstagramAnalysis(bool $fullScan): void
    {
        @set_time_limit(0);
        @ignore_user_abort(false);

        $trackedPerson = $this->resolveTrackedPerson();

        if (! $trackedPerson->instagram_username) {
            $this->setDetailStatus('Fuer diese Person ist kein Instagram-Name hinterlegt.', 'error');

            return;
        }

        $progress = fn (array $state) => $this->streamInstagramProgress($state);
        $this->cancelInstagramScanWhenClientDisconnects($trackedPerson->id);

        $trackedPerson->forceFill([
            'last_instagram_status_level' => 'partial',
            'last_instagram_status_message' => $fullScan
                ? 'Vollstaendige Instagram-Analyse laeuft direkt in der Oberflaeche.'
                : 'Instagram-Mini-Scan laeuft direkt in der Oberflaeche.',
        ])->save();

        try {
            $this->streamInstagramProgress([
                'phase' => 'start',
                'percent' => 1,
                'message' => $fullScan
                    ? 'Vollstaendige Instagram-Analyse wird vorbereitet.'
                    : 'Instagram-Mini-Scan wird vorbereitet.',
            ]);

            $snapshot = $trackedPerson->analyzeInstagram($progress, $fullScan);
        } catch (TrackedPersonInstagramScanCancelledException $exception) {
            $this->streamInstagramProgress([
                'phase' => 'done',
                'percent' => 100,
                'message' => 'Vorheriger Scan wurde beendet, weil ein neuer Scan gestartet wurde.',
            ]);
            $this->setDetailStatus('Vorheriger Instagram-Scan wurde beendet, weil ein neuer Scan gestartet wurde.', 'partial');

            return;
        } catch (\Throwable $exception) {
            $trackedPerson->forceFill([
                'last_instagram_status_level' => 'error',
                'last_instagram_status_message' => ($fullScan ? 'Instagram-Analyse' : 'Instagram-Mini-Scan').' fehlgeschlagen: '.$exception->getMessage(),
            ])->save();

            $this->streamInstagramProgress([
                'phase' => 'error',
                'percent' => 100,
                'message' => ($fullScan ? 'Instagram-Analyse' : 'Instagram-Mini-Scan').' fehlgeschlagen.',
            ]);
            $this->setDetailStatus(
                ($fullScan ? 'Instagram-Analyse' : 'Instagram-Mini-Scan').' fehlgeschlagen: '.$exception->getMessage(),
                'error',
            );

            return;
        }

        $queuedFullScan = false;

        if (! $fullScan && MonitorTrackedPersonInstagram::shouldRunFullScanAfterSnapshot($snapshot)) {
            $queuedFullScan = MonitorTrackedPersonInstagram::dispatchFullScanIfNotQueued($trackedPerson->id, true);

            if (! $queuedFullScan) {
                $trackedPerson->forceFill([
                    'last_instagram_status_level' => 'partial',
                    'last_instagram_status_message' => 'Follower-/Gefolgt-Aenderung erkannt; Instagram-Vollanalyse ist bereits eingereiht oder laeuft.',
                ])->save();
            }
        }

        $this->fillFormFromModel($trackedPerson->fresh());
        $this->setDetailStatus(
            ($fullScan ? 'Instagram-Analyse' : 'Instagram-Mini-Scan').' abgeschlossen: '.$snapshot->status_message.($queuedFullScan ? ' Eine Vollanalyse wurde als Hintergrund-Job eingereiht.' : ''),
            $snapshot->status_level === 'success' ? 'success' : ($snapshot->status_level === 'partial' ? 'partial' : 'error'),
        );
        $this->dispatch('tracked-person-refresh');
    }

    private function runInstagramRelationshipListScan(string $relationship): void
    {
        @set_time_limit(0);
        @ignore_user_abort(false);

        $trackedPerson = $this->resolveTrackedPerson();
        $isFollowers = $relationship === 'followers';
        $label = $isFollowers ? 'Followerliste' : 'Gefolgt-Liste';

        if (! $trackedPerson->instagram_username) {
            $this->setDetailStatus('Fuer diese Person ist kein Instagram-Name hinterlegt.', 'error');

            return;
        }

        $progress = fn (array $state) => $this->streamInstagramProgress($state);
        $this->cancelInstagramScanWhenClientDisconnects($trackedPerson->id);

        $trackedPerson->forceFill([
            'last_instagram_status_level' => 'partial',
            'last_instagram_status_message' => $label.'-Scan laeuft direkt in der Oberflaeche.',
        ])->save();

        try {
            $this->streamInstagramProgress([
                'phase' => $relationship,
                'percent' => 1,
                'message' => $label.' wird vorbereitet.',
            ]);

            $snapshot = app(TrackedPersonInstagramAnalysisService::class)
                ->scanRelationshipList($trackedPerson, $relationship, $progress);
        } catch (TrackedPersonInstagramScanCancelledException $exception) {
            $this->streamInstagramProgress([
                'phase' => 'done',
                'percent' => 100,
                'message' => 'Vorheriger Scan wurde beendet, weil ein neuer Scan gestartet wurde.',
            ]);
            $this->setDetailStatus('Vorheriger Instagram-Scan wurde beendet, weil ein neuer Scan gestartet wurde.', 'partial');

            return;
        } catch (\Throwable $exception) {
            $trackedPerson->forceFill([
                'last_instagram_status_level' => 'error',
                'last_instagram_status_message' => $label.'-Scan fehlgeschlagen: '.$exception->getMessage(),
            ])->save();

            $this->streamInstagramProgress([
                'phase' => 'error',
                'percent' => 100,
                'message' => $label.'-Scan fehlgeschlagen.',
            ]);
            $this->setDetailStatus($label.'-Scan fehlgeschlagen: '.$exception->getMessage(), 'error');

            return;
        }

        $this->fillFormFromModel($trackedPerson->fresh());

        $payloadKey = $isFollowers ? 'followersList' : 'followingList';
        $relationshipList = data_get($snapshot->raw_payload, 'extractedProfile.'.$payloadKey, []);
        $activeCount = (int) data_get($relationshipList, 'activeCount', data_get($relationshipList, 'count', 0));
        $observedCount = (int) data_get($relationshipList, 'observedCount', 0);
        $rateLimited = (bool) data_get($relationshipList, 'rateLimited', false);
        $stoppedByUser = (bool) data_get($relationshipList, 'gracefullyStopped', false)
            || (bool) data_get($snapshot->raw_payload, 'gracefullyStopped', false);
        $available = (bool) data_get($relationshipList, 'available', false);

        $this->setDetailStatus(
            ($stoppedByUser ? $label.'-Scan wurde beendet und gespeichert: ' : $label.'-Scan abgeschlossen: ')
            .number_format($activeCount, 0, ',', '.')
            .' bekannte Eintraege, '
            .number_format($observedCount, 0, ',', '.')
            .' zuletzt gesehen.'
            .($stoppedByUser ? ' Der Scan kann spaeter erneut gestartet werden.' : '')
            .($rateLimited ? ' Instagram hat diese Liste per Rate-Limit blockiert; bisherige Eintraege bleiben erhalten.' : ''),
            $snapshot->status_level === 'success' && $available && ! $rateLimited && ! $stoppedByUser ? 'success' : 'partial',
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
            'public-connections' => 'Verbindungen',
            'saving' => 'Speichern',
            'done' => 'Fertig',
            'error' => 'Fehler',
            default => 'Analyse',
        };
        $message = (string) ($state['message'] ?? 'Instagram-Analyse laeuft.');
        $loaded = $state['loaded'] ?? null;
        $expected = $state['expected'] ?? null;
        $foundFollowers = $state['foundFollowers'] ?? null;
        $foundFollowing = $state['foundFollowing'] ?? null;
        $liveCounts = '';

        if ($loaded !== null || $expected !== null || $foundFollowers !== null || $foundFollowing !== null) {
            $liveParts = [];

            if ($loaded !== null && $expected !== null) {
                $liveParts[] = 'Geprueft: '
                    .number_format((int) $loaded, 0, ',', '.')
                    .' / '
                    .number_format((int) $expected, 0, ',', '.');
            }

            if ($foundFollowers !== null || $foundFollowing !== null) {
                $liveParts[] = 'Gefunden: '
                    .number_format((int) $foundFollowers, 0, ',', '.')
                    .' Follower / '
                    .number_format((int) $foundFollowing, 0, ',', '.')
                    .' Gefolgt';
            }

            $liveCounts = implode(' · ', $liveParts);
        }

        $this->stream('instagram-progress-phase', e($phase), true);
        $this->streamInstagramScraperProfile($state);
        $this->stream('instagram-progress-message', e($message), true);
        $this->streamInstagramLivePreview($state);
        $this->stream('instagram-progress-live-counts', e($liveCounts), true);
        $this->stream('instagram-progress-percent', $percent.'%', true);
        $this->stream(
            'instagram-progress-bar',
            '<div class="h-full rounded-full bg-pink-600 transition-all duration-300" style="width: '.$percent.'%"></div>',
            true,
        );
        $this->streamInstagramConnectionResults($state);
    }

    private function streamInstagramLivePreview(array $state): void
    {
        $url = is_scalar($state['liveScreenshotUrl'] ?? null)
            ? trim((string) $state['liveScreenshotUrl'])
            : '';

        if ($url === '') {
            return;
        }

        $this->stream(
            'instagram-progress-live-preview',
            '<div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-slate-100 text-left">'
            .'<div class="flex items-center justify-between border-b border-slate-200 bg-white px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">'
            .'<span>Browser-Vorschau</span>'
            .'<span>Live-Screenshot</span>'
            .'</div>'
            .'<img src="'.e($url).'" alt="Aktuelle Browser-Vorschau des Instagram-Scans" class="block aspect-video w-full bg-slate-100 object-contain">'
            .'</div>',
            true,
        );
    }

    private function streamInstagramScraperProfile(array $state): void
    {
        $label = trim((string) ($state['scraperProfileLabel'] ?? ''));
        $loginUsername = trim((string) ($state['scraperProfileLoginUsername'] ?? ''));
        $switchTarget = trim((string) ($state['scraperProfileSwitchTarget'] ?? ''));

        if ($label === '' && $switchTarget === '') {
            return;
        }

        $displayLabel = $switchTarget !== ''
            ? 'Wechselt zu '.$switchTarget
            : $label;

        if ($loginUsername !== '' && $switchTarget === '') {
            $displayLabel .= ' (@'.ltrim($loginUsername, '@').')';
        }

        $this->stream('instagram-progress-scraper-profile', e($displayLabel), true);
    }

    private function streamInstagramConnectionResults(array $state): void
    {
        $hasFollowers = array_key_exists('inferredFollowers', $state);
        $hasFollowing = array_key_exists('inferredFollowing', $state);

        if (! $hasFollowers && ! $hasFollowing) {
            if (($state['phase'] ?? null) !== 'public-connections') {
                $this->stream('instagram-progress-connection-results', '', true);
            }

            return;
        }

        $followers = $this->normalizeProgressConnectionItems($state['inferredFollowers'] ?? []);
        $following = $this->normalizeProgressConnectionItems($state['inferredFollowing'] ?? []);

        $this->stream(
            'instagram-progress-connection-results',
            $this->renderProgressConnectionResults($followers, $following),
            true,
        );
    }

    private function normalizeProgressConnectionItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalizedItems = [];

        foreach (array_slice($items, 0, 40) as $item) {
            if (! is_array($item) || ! is_scalar($item['username'] ?? null)) {
                continue;
            }

            $username = trim((string) $item['username']);

            if ($username === '') {
                continue;
            }

            $sourcePublicUsername = is_scalar($item['sourcePublicUsername'] ?? null)
                ? trim((string) $item['sourcePublicUsername'])
                : '';

            $normalizedItems[] = [
                'username' => ltrim($username, '@'),
                'displayName' => is_scalar($item['displayName'] ?? null) ? trim((string) $item['displayName']) : '',
                'profileUrl' => is_scalar($item['profileUrl'] ?? null) ? trim((string) $item['profileUrl']) : '',
                'sourcePublicUsername' => ltrim($sourcePublicUsername, '@'),
            ];
        }

        return $normalizedItems;
    }

    private function renderProgressConnectionResults(array $followers, array $following): string
    {
        return '<div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-3 text-left">'
            .'<div class="flex items-center justify-between gap-3 text-xs">'
            .'<span class="font-semibold uppercase tracking-wide text-slate-500">Bisherige Treffer</span>'
            .'<span class="font-semibold text-slate-700">'
            .number_format(count($followers), 0, ',', '.').' Follower / '
            .number_format(count($following), 0, ',', '.').' Gefolgt'
            .'</span>'
            .'</div>'
            .'<div class="mt-3 grid gap-3 sm:grid-cols-2">'
            .$this->renderProgressConnectionList('Moegliche Follower', $followers)
            .$this->renderProgressConnectionList('Moeglich gefolgt', $following)
            .'</div>'
            .'</div>';
    }

    private function renderProgressConnectionList(string $label, array $items): string
    {
        $html = '<div class="rounded-lg border border-slate-200 bg-white p-2">'
            .'<div class="flex items-center justify-between gap-2 px-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">'
            .'<span>'.e($label).'</span>'
            .'<span>'.number_format(count($items), 0, ',', '.').'</span>'
            .'</div>';

        if ($items === []) {
            return $html.'<div class="mt-2 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">Noch keine Treffer.</div></div>';
        }

        $html .= '<div class="mt-2 max-h-48 space-y-1 overflow-y-auto pr-1">';

        foreach (array_slice($items, 0, 20) as $item) {
            $username = e($item['username']);
            $displayName = trim((string) ($item['displayName'] ?? ''));
            $sourcePublicUsername = trim((string) ($item['sourcePublicUsername'] ?? ''));

            $html .= '<div class="rounded-md bg-slate-50 px-3 py-2 text-xs">'
                .'<div class="font-semibold text-slate-900">@'.$username.'</div>';

            if ($displayName !== '') {
                $html .= '<div class="truncate text-slate-500">'.e($displayName).'</div>';
            }

            if ($sourcePublicUsername !== '') {
                $html .= '<div class="mt-1 text-[11px] text-slate-500">Quelle: @'.e($sourcePublicUsername).'</div>';
            }

            $html .= '</div>';
        }

        if (count($items) > 20) {
            $html .= '<div class="px-2 py-1 text-[11px] font-semibold text-slate-500">+'
                .number_format(count($items) - 20, 0, ',', '.')
                .' weitere Treffer</div>';
        }

        return $html.'</div></div>';
    }

    private function cancelInstagramScanWhenClientDisconnects(int $trackedPersonId): void
    {
        static $registered = [];

        if (isset($registered[$trackedPersonId])) {
            return;
        }

        $registered[$trackedPersonId] = true;

        register_shutdown_function(static function () use ($trackedPersonId): void {
            if (! connection_aborted()) {
                return;
            }

            app(TrackedPersonInstagramScanCoordinator::class)->cancelActive(
                $trackedPersonId,
                'GUI-Verbindung wurde beendet.',
            );
        });
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
            'publicProfileTrackedPersonId' => ['required', 'integer'],
            'publicProfileRelationshipType' => ['required', 'string', 'in:follows_target,followed_by_target,mutual,public_connection'],
            'publicProfileNotes' => ['nullable', 'string'],
        ]);

        $trackedPerson = $this->resolveTrackedPerson();
        $linkedTrackedPerson = Auth::user()
            ->trackedPeople()
            ->with('latestInstagramSnapshot')
            ->whereKey((int) $validated['publicProfileTrackedPersonId'])
            ->where('id', '!=', $trackedPerson->id)
            ->whereNotNull('instagram_username')
            ->first();

        if (! $linkedTrackedPerson) {
            $this->addError('publicProfileTrackedPersonId', 'Bitte ein anderes beobachtetes Instagram-Profil auswaehlen.');

            return;
        }

        if (data_get($linkedTrackedPerson->latestInstagramSnapshot?->raw_payload, 'extractedProfile.profileVisibility') !== 'public') {
            $this->addError('publicProfileTrackedPersonId', 'Dieses beobachtete Profil wurde noch nicht als oeffentlich erkannt.');

            return;
        }

        $normalizedUsername = $this->normalizeHandle($linkedTrackedPerson->instagram_username);

        $publicProfile = $trackedPerson->publicProfiles()->firstOrNew([
            'platform' => 'instagram',
            'username' => $normalizedUsername,
        ]);
        $wasExisting = $publicProfile->exists;

        $publicProfile->fill([
            'user_id' => Auth::id(),
            'display_name' => $linkedTrackedPerson->display_name,
            'relationship_type' => $validated['publicProfileRelationshipType'],
            'profile_url' => 'https://www.instagram.com/'.$normalizedUsername.'/',
            'notes' => $this->nullableTrim($validated['publicProfileNotes'] ?? null),
            'is_public' => true,
        ]);
        $publicProfile->save();
        app(InstagramProfileRelationshipStore::class)->syncPublicProfile($publicProfile);

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
                'publicProfiles' => fn ($query) => $query
                    ->where('platform', 'instagram')
                    ->with('latestInstagramConnectionScan')
                    ->latest(),
                'instagramPublicProfileScans' => fn ($query) => $query
                    ->with('publicProfile')
                    ->latest('analyzed_at')
                    ->limit(20),
                'instagramInferredConnections' => fn ($query) => $query
                    ->with('publicProfile')
                    ->latest('last_seen_at')
                    ->limit(100),
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
        $publicProfileCandidates = Auth::user()
            ->trackedPeople()
            ->with('latestInstagramSnapshot')
            ->where('id', '!=', $trackedPerson->id)
            ->whereNotNull('instagram_username')
            ->orderBy('instagram_username')
            ->get()
            ->filter(fn (TrackedPerson $candidate): bool => data_get(
                $candidate->latestInstagramSnapshot?->raw_payload,
                'extractedProfile.profileVisibility',
            ) === 'public')
            ->values();

        return view('livewire.user.tracked-person-detail', [
            'trackedPerson' => $trackedPerson,
            'profileImageHistory' => $profileImageHistory,
            'publicProfileCandidates' => $publicProfileCandidates,
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
        $this->notification_delivery_type = in_array($trackedPerson->notification_delivery_type, ['message', 'mail', 'both'], true)
            ? $trackedPerson->notification_delivery_type
            : 'both';
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
        $this->publicProfileTrackedPersonId = '';
        $this->publicProfileRelationshipType = 'public_connection';
        $this->publicProfileNotes = '';
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
