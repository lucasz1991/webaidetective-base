<?php

namespace App\Livewire\User;

use App\Exceptions\TrackedPersonInstagramScanCancelledException;
use App\Models\InstagramPost;
use App\Models\InstagramProfile;
use App\Models\InstagramProfileListScan;
use App\Models\Setting;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramInferredConnection;
use App\Models\TrackedPersonInstagramMedia;
use App\Models\TrackedPersonInstagramSnapshot;
use App\Models\TrackedPersonInstagramPublicProfileScan;
use App\Models\TrackedPersonInstagramSuggestionScan;
use App\Services\TrackedPeople\InstagramProfileRelationshipStore;
use App\Services\TrackedPeople\InstagramProfileScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramAnalysisService;
use App\Services\TrackedPeople\TrackedPersonInstagramPostScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramProfileListScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramPublicProfileScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramScanCoordinator;
use App\Services\TrackedPeople\TrackedPersonInstagramSuggestionScanService;
use App\Services\TrackedPeople\TrackedPersonInstagramWorkflowService;
use App\Services\TrackedPeople\TrackedPersonScanDispatcher;
use App\Support\InstagramRelationshipListData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;

class TrackedPersonDetail extends Component
{
    public int $trackedPersonId;

    public bool $compact = false;

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

    public $monitoring_interval_minutes = 60;

    public $instagram_monitoring_scan_mode = 'mini';

    public $instagram_auto_scan_followers = true;

    public $instagram_auto_scan_following = true;

    public $instagram_auto_scan_posts = true;

    public $instagram_auto_scan_suggestions = true;

    public $instagram_auto_scan_on_changes = true;

    public $instagram_auto_scan_on_interval = false;

    public $instagram_auto_scan_min_interval_minutes = 60;

    public $instagram_auto_scan_count_change_threshold = 1;

    public $notify_social_changes = false;

    public $notify_instagram_changes = true;

    public $notify_tiktok_changes = true;

    public $notify_facebook_changes = true;

    public $notify_x_changes = true;

    public $notify_youtube_changes = true;

    public $notify_snapchat_changes = true;

    public bool $showSettingsModal = false;

    public bool $showDeleteConfirmationModal = false;

    public bool $showPostEngagementModal = false;

    public bool $showNetworkMap = false;

    public ?int $selectedPostId = null;

    public string $activePostEngagementType = 'comments';

    public $knownFactLabel = '';

    public $knownFactValue = '';

    public $knownFactSource = '';

    public $knownFactNotes = '';

    public $publicProfileTrackedPersonId = '';

    public $publicProfileRelationshipType = 'public_connection';

    public $manualPublicProfileUsername = '';

    public $detailStatus = null;

    public $detailStatusLevel = 'neutral';

    protected $listeners = [
        'tracked-person-refresh' => '$refresh',
        'tracked-person-run-instagram-analysis' => 'analyzeInstagram',
        'scan-instagram-relationship-list' => 'scanInstagramRelationshipList',
        'scan-instagram-profile-from-list' => 'scanInstagramProfileFromList',
    ];

    public function mount(int $trackedPersonId, bool $compact = false): void
    {
        $this->trackedPersonId = $trackedPersonId;
        $this->compact = $compact;
        $this->fillFormFromModel($this->resolveTrackedPerson());
    }

    public function loadNetworkMap(): void
    {
        $this->showNetworkMap = true;
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
            'monitoring_interval_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'instagram_monitoring_scan_mode' => ['required', 'string', 'in:mini,full'],
            'instagram_auto_scan_followers' => ['boolean'],
            'instagram_auto_scan_following' => ['boolean'],
            'instagram_auto_scan_posts' => ['boolean'],
            'instagram_auto_scan_suggestions' => ['boolean'],
            'instagram_auto_scan_on_changes' => ['boolean'],
            'instagram_auto_scan_on_interval' => ['boolean'],
            'instagram_auto_scan_min_interval_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'instagram_auto_scan_count_change_threshold' => ['required', 'integer', 'min:1', 'max:1000000'],
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
            'monitoring_interval_minutes' => (int) $validated['monitoring_interval_minutes'],
            'instagram_scan_preferences' => TrackedPerson::normalizeInstagramScanPreferences([
                'monitoring_scan_mode' => $validated['instagram_monitoring_scan_mode'],
                'auto_scan_followers' => (bool) $this->instagram_auto_scan_followers,
                'auto_scan_following' => (bool) $this->instagram_auto_scan_following,
                'auto_scan_posts' => (bool) $this->instagram_auto_scan_posts,
                'auto_scan_suggestions' => (bool) $this->instagram_auto_scan_suggestions,
                'auto_scan_on_changes' => (bool) $this->instagram_auto_scan_on_changes,
                'auto_scan_on_interval' => (bool) $this->instagram_auto_scan_on_interval,
                'auto_scan_min_interval_minutes' => (int) $validated['instagram_auto_scan_min_interval_minutes'],
                'auto_scan_count_change_threshold' => (int) $validated['instagram_auto_scan_count_change_threshold'],
            ]),
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

    public function setMonitoringEnabled(int $trackedPersonId, bool $enabled): void
    {
        if ($trackedPersonId !== $this->trackedPersonId) {
            return;
        }

        $trackedPerson = $this->resolveTrackedPerson();
        $trackedPerson->update([
            'monitoring_enabled' => $enabled,
            'monitoring_interval_minutes' => max(15, (int) ($trackedPerson->monitoring_interval_minutes ?: 60)),
        ]);

        $this->fillFormFromModel($trackedPerson->fresh());
        $this->setDetailStatus(
            $enabled ? 'Dauerbeobachtung wurde aktiviert.' : 'Dauerbeobachtung wurde deaktiviert.',
            'success',
        );
    }

    public function setMonitoringInterval(int $trackedPersonId, int $intervalMinutes): void
    {
        if ($trackedPersonId !== $this->trackedPersonId) {
            return;
        }

        $allowedIntervals = [15, 30, 60, 120, 360, 720, 1440, 4320, 10080];
        $intervalMinutes = in_array($intervalMinutes, $allowedIntervals, true)
            ? $intervalMinutes
            : 60;

        $trackedPerson = $this->resolveTrackedPerson();
        $trackedPerson->update([
            'monitoring_enabled' => true,
            'monitoring_interval_minutes' => $intervalMinutes,
        ]);

        $this->fillFormFromModel($trackedPerson->fresh());
        $this->setDetailStatus('Dauerbeobachtungsintervall wurde aktualisiert.', 'success');
    }

    public function confirmTrackedPersonDeletion(): void
    {
        $this->showDeleteConfirmationModal = true;
    }

    public function cancelTrackedPersonDeletion(): void
    {
        $this->showDeleteConfirmationModal = false;
    }

    public function deleteTrackedPerson(): void
    {
        $user = Auth::user();

        if (! $user) {
            $this->cancelTrackedPersonDeletion();

            return;
        }

        $trackedPerson = $this->resolveTrackedPerson();
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
            $this->cancelTrackedPersonDeletion();
            $this->setDetailStatus(
                'Person "'.$displayName.'" konnte nicht geloescht werden: '.$exception->getMessage(),
                'error',
            );

            return;
        }

        $this->dispatch('tracked-person-refresh');
        $this->redirectRoute('dashboard', navigate: true);
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

    public function scanInstagramRelationshipList(string $relationship): void
    {
        if (! in_array($relationship, ['followers', 'following'], true)) {
            return;
        }

        $this->runInstagramRelationshipListScan($relationship);
    }

    public function scanInstagramProfileFromList(?int $profileId = null, ?string $username = null, string $scanType = 'mini'): void
    {
        @set_time_limit(0);

        $profile = $this->resolveAccessibleInstagramProfile($profileId, $username);

        if (! $profile) {
            $this->setDetailStatus('Das ausgewaehlte Instagram-Profil wurde nicht gefunden oder ist nicht freigegeben.', 'error');

            return;
        }

        try {
            [$message, $level] = match ($scanType) {
                'full' => $this->scanListInstagramProfileAnalysis($profile, true),
                'followers' => $this->scanListInstagramProfileRelationship($profile, 'followers'),
                'following' => $this->scanListInstagramProfileRelationship($profile, 'following'),
                'posts' => $this->scanListInstagramProfilePosts($profile),
                'suggestions' => $this->scanListInstagramProfileSuggestions($profile, false),
                'suggestion_deepsearch' => $this->scanListInstagramProfileSuggestions($profile, true),
                default => $this->scanListInstagramProfileAnalysis($profile, false),
            };

            $this->setDetailStatus($message, $level);
            $this->dispatch('tracked-person-refresh');
        } catch (\Throwable $exception) {
            $this->setDetailStatus('Scan fuer @'.$profile->username.' fehlgeschlagen: '.$exception->getMessage(), 'error');
        }
    }

    public function openPostEngagementModal(int $postId, string $type): void
    {
        if (! in_array($type, ['likes', 'comments'], true)) {
            return;
        }

        $trackedPerson = $this->resolveTrackedPerson();
        $profileId = $trackedPerson->current_instagram_profile_id;

        if (! $profileId) {
            return;
        }

        $postExists = InstagramPost::query()
            ->whereKey($postId)
            ->where('instagram_profile_id', $profileId)
            ->exists();

        if (! $postExists) {
            return;
        }

        $this->selectedPostId = $postId;
        $this->activePostEngagementType = $type;
        $this->showPostEngagementModal = true;
    }

    public function resumeSavedInstagramScan(string $scanType): void
    {
        if (! in_array($scanType, ['followers', 'following', 'suggestions', 'suggestion_deepsearch', 'public_connections'], true)) {
            $this->setDetailStatus('Dieser Scan-Typ kann nicht fortgesetzt werden.', 'error');

            return;
        }

        $trackedPerson = $this->resolveTrackedPerson();

        app(TrackedPersonScanDispatcher::class)->dispatch(
            (int) $trackedPerson->id,
            $scanType,
        );

        $this->setDetailStatus('Scan wird ab dem gespeicherten Datenstand fortgesetzt.', 'partial');
    }

    public function dismissSavedInstagramScan(string $source, int $scanId): void
    {
        $trackedPerson = $this->resolveTrackedPerson();
        $scan = match ($source) {
            'list' => InstagramProfileListScan::query()
                ->whereKey($scanId)
                ->where('tracked_person_id', $trackedPerson->id)
                ->where('user_id', Auth::id())
                ->first(),
            'suggestion' => TrackedPersonInstagramSuggestionScan::query()
                ->whereKey($scanId)
                ->where('tracked_person_id', $trackedPerson->id)
                ->where('user_id', Auth::id())
                ->first(),
            'public_connections' => TrackedPersonInstagramPublicProfileScan::query()
                ->whereKey($scanId)
                ->where('tracked_person_id', $trackedPerson->id)
                ->where('user_id', Auth::id())
                ->first(),
            default => null,
        };

        if (! $scan) {
            return;
        }

        $payload = is_array($scan->raw_payload) ? $scan->raw_payload : [];
        $scan->forceFill([
            'raw_payload' => [
                ...$payload,
                'isResumable' => false,
                'resumeDismissedAt' => now('UTC')->toIso8601String(),
            ],
        ])->save();

        $this->setDetailStatus('Scan beendet. Alle bisher erfassten Daten bleiben gespeichert.', 'partial');
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
            $this->markInstagramScanCancelled($trackedPerson, 'Public-Profile-Verbindungsscan wurde abgebrochen.');
            $this->streamInstagramProgress([
                'phase' => 'done',
                'percent' => 100,
                'message' => 'Vorheriger Scan wurde beendet, weil ein neuer Scan gestartet wurde.',
            ]);
            $this->setDetailStatus('Vorheriger Instagram-Scan wurde beendet, weil ein neuer Scan gestartet wurde.', 'partial');

            return;
        } catch (\Throwable $exception) {
            $trackedPerson->markInstagramScanTerminal(
                'error',
                'Public-Profile-Verbindungsscan fehlgeschlagen: '.$exception->getMessage(),
            );
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

    public function scanInstagramSuggestions(): void
    {
        $this->runInstagramSuggestionScan(false);
    }

    public function scanInstagramSuggestionConnections(): void
    {
        $this->scanInstagramSuggestionDeepSearch();
    }

    public function scanInstagramSuggestionDeepSearch(): void
    {
        $this->runInstagramSuggestionScan(true);
    }

    public function openProfilePreview(string $nodeId): mixed
    {
        $username = null;

        if (Str::startsWith($nodeId, 'profile-instagram-')) {
            $username = Str::after($nodeId, 'profile-instagram-');
        } elseif (Str::startsWith($nodeId, 'candidate-')) {
            $username = Str::after($nodeId, 'candidate-');
        }

        $username = Str::lower(ltrim(trim((string) $username), '@'));

        if ($username === '') {
            $this->setDetailStatus('Profil konnte aus der Network Map nicht geoeffnet werden.', 'error');

            return null;
        }

        $profile = InstagramProfile::query()
            ->where('username', $username)
            ->first()
            ?: app(InstagramProfileRelationshipStore::class)->ensureProfile($username);

        if (! $profile) {
            $this->setDetailStatus('Profil konnte aus der Network Map nicht gefunden werden.', 'error');

            return null;
        }

        return $this->redirectRoute(
            'instagram-profiles.show',
            ['instagramProfileId' => $profile->id],
            navigate: true,
        );
    }

    private function runInstagramSuggestionScan(bool $deepSearch): void
    {
        @set_time_limit(0);
        @ignore_user_abort(false);

        $trackedPerson = $this->resolveTrackedPerson();
        $scanLabel = $deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan';

        if (! $trackedPerson->instagram_username) {
            $this->setDetailStatus('Fuer diese Person ist kein Instagram-Name hinterlegt.', 'error');

            return;
        }

        $progress = fn (array $state) => $this->streamInstagramProgress($state);
        $this->cancelInstagramScanWhenClientDisconnects($trackedPerson->id);

        try {
            $this->streamInstagramProgress([
                'phase' => 'suggestions',
                'percent' => 1,
                'message' => $scanLabel.' wird vorbereitet.',
                'foundSuggestions' => 0,
                'suggestionConnections' => [],
                'observedSuggestionCount' => 0,
                'observedSuggestions' => [],
            ]);

            $workflow = app(TrackedPersonInstagramWorkflowService::class);
            $scan = $deepSearch
                ? $workflow->runSuggestionDeepSearch($trackedPerson, $progress)
                : $workflow->runSuggestionScan($trackedPerson, $progress);
        } catch (TrackedPersonInstagramScanCancelledException $exception) {
            $this->markInstagramScanCancelled($trackedPerson, $scanLabel.' wurde abgebrochen.');
            $this->streamInstagramProgress([
                'phase' => 'done',
                'percent' => 100,
                'message' => 'Vorheriger Scan wurde beendet, weil ein neuer Scan gestartet wurde.',
            ]);
            $this->setDetailStatus('Vorheriger Instagram-Scan wurde beendet, weil ein neuer Scan gestartet wurde.', 'partial');

            return;
        } catch (\Throwable $exception) {
            $trackedPerson->markInstagramScanTerminal(
                'error',
                $scanLabel.' fehlgeschlagen: '.$exception->getMessage(),
            );
            $this->streamInstagramProgress([
                'phase' => 'error',
                'percent' => 100,
                'message' => $scanLabel.' fehlgeschlagen.',
            ]);
            $this->setDetailStatus($scanLabel.' fehlgeschlagen: '.$exception->getMessage(), 'error');

            return;
        }

        $suggestionStatusMessage = trim((string) $scan->status_message);

        if ($suggestionStatusMessage === '') {
            $suggestionStatusMessage = ($scan->gracefully_stopped
                ? $scanLabel.' wurde beendet und gespeichert: '
                : $scanLabel.' abgeschlossen: ')
                .($deepSearch
                    ? number_format((int) $scan->suggestions_checked_count, 0, ',', '.')
                        .' Kandidaten geprueft, '
                        .number_format((int) $scan->suggestion_matches_count, 0, ',', '.')
                        .' Verbindungen gefunden.'
                    : number_format((int) $scan->suggestions_observed_count, 0, ',', '.')
                        .' Vorschlaege gefunden.');
        }

        $this->setDetailStatus(
            $suggestionStatusMessage,
            $scan->status_level === 'success' && ! $scan->gracefully_stopped ? 'success' : 'partial',
        );
        $this->dispatch('tracked-person-refresh');
    }

    public function scanInstagramPosts(): void
    {
        @set_time_limit(0);
        @ignore_user_abort(false);

        $trackedPerson = $this->resolveTrackedPerson()->loadMissing('latestInstagramSnapshot');

        if (! $trackedPerson->instagram_username) {
            $this->setDetailStatus('Fuer diese Person ist kein Instagram-Name hinterlegt.', 'error');

            return;
        }

        if (! $this->trackedPersonInstagramProfileIsPublic($trackedPerson)) {
            $this->setDetailStatus('Beitragsscans sind nur fuer oeffentliche Instagram-Profile verfuegbar.', 'partial');

            return;
        }

        $progress = fn (array $state) => $this->streamInstagramProgress($state);
        $this->cancelInstagramScanWhenClientDisconnects($trackedPerson->id);

        try {
            $scan = app(TrackedPersonInstagramWorkflowService::class)
                ->runPostScan($trackedPerson, $trackedPerson->latestInstagramSnapshot, $progress);
        } catch (TrackedPersonInstagramScanCancelledException) {
            $this->markInstagramScanCancelled($trackedPerson, 'Instagram-Beitragsscan wurde abgebrochen.');
            $this->setDetailStatus('Der Instagram-Beitragsscan wurde beendet.', 'partial');

            return;
        } catch (\Throwable $exception) {
            $trackedPerson->markInstagramScanTerminal(
                'error',
                'Instagram-Beitragsscan fehlgeschlagen: '.$exception->getMessage(),
            );
            $this->setDetailStatus('Instagram-Beitragsscan fehlgeschlagen: '.$exception->getMessage(), 'error');

            return;
        }

        $this->setDetailStatus(
            'Instagram-Beitragsscan abgeschlossen: '
                .number_format($scan->observed_count, 0, ',', '.').' geprueft, '
                .number_format($scan->new_count, 0, ',', '.').' neu und '
                .number_format($scan->updated_count, 0, ',', '.').' aktualisiert.',
            $scan->status_level === 'success' ? 'success' : 'partial',
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

            $runResult = app(TrackedPersonInstagramWorkflowService::class)->runAnalysis(
                $trackedPerson,
                $fullScan,
                $progress,
            );
        } catch (TrackedPersonInstagramScanCancelledException $exception) {
            $this->markInstagramScanCancelled(
                $trackedPerson,
                ($fullScan ? 'Instagram-Analyse' : 'Instagram-Mini-Scan').' wurde abgebrochen.',
            );
            $this->streamInstagramProgress([
                'phase' => 'done',
                'percent' => 100,
                'message' => 'Vorheriger Scan wurde beendet, weil ein neuer Scan gestartet wurde.',
            ]);
            $this->setDetailStatus('Vorheriger Instagram-Scan wurde beendet, weil ein neuer Scan gestartet wurde.', 'partial');

            return;
        } catch (\Throwable $exception) {
            $trackedPerson->markInstagramScanTerminal(
                'error',
                ($fullScan ? 'Instagram-Analyse' : 'Instagram-Mini-Scan').' fehlgeschlagen: '.$exception->getMessage(),
            );

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
        $this->fillFormFromModel($trackedPerson->fresh());
        $this->setDetailStatus($runResult['resolvedStatusMessage'], $runResult['resolvedStatusLevel']);
        $this->dispatch('tracked-person-refresh');
    }

    private function runInstagramRelationshipListScan(string $relationship): void
    {
        @set_time_limit(0);
        @ignore_user_abort(false);

        $trackedPerson = $this->resolveTrackedPerson()->loadMissing('latestInstagramSnapshot');
        $isFollowers = $relationship === 'followers';
        $label = $isFollowers ? 'Followerliste' : 'Gefolgt-Liste';

        if (! $trackedPerson->instagram_username) {
            $this->setDetailStatus('Fuer diese Person ist kein Instagram-Name hinterlegt.', 'error');

            return;
        }

        if (! $this->trackedPersonInstagramProfileIsPublic($trackedPerson)) {
            $this->setDetailStatus($label.'-Scans sind nur fuer als oeffentlich erkannte Instagram-Profile verfuegbar.', 'partial');

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
            $this->markInstagramScanCancelled($trackedPerson, $label.'-Scan wurde abgebrochen.');
            $this->streamInstagramProgress([
                'phase' => 'done',
                'percent' => 100,
                'message' => 'Vorheriger Scan wurde beendet, weil ein neuer Scan gestartet wurde.',
            ]);
            $this->setDetailStatus('Vorheriger Instagram-Scan wurde beendet, weil ein neuer Scan gestartet wurde.', 'partial');

            return;
        } catch (\Throwable $exception) {
            $trackedPerson->markInstagramScanTerminal(
                'error',
                $label.'-Scan fehlgeschlagen: '.$exception->getMessage(),
            );

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
            'suggestions' => 'Vorschlaege',
            'posts' => 'Beitraege',
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
        $foundSuggestions = $state['foundSuggestions'] ?? null;
        $observedSuggestionCount = $state['observedSuggestionCount'] ?? null;
        $knownSuggestionCount = $state['knownSuggestionCount'] ?? null;
        $skippedSuggestions = $state['skippedSuggestions'] ?? null;
        $liveCounts = '';

        if (
            $loaded !== null
            || $expected !== null
            || $foundFollowers !== null
            || $foundFollowing !== null
            || $foundSuggestions !== null
            || $observedSuggestionCount !== null
        ) {
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

            if ($foundSuggestions !== null) {
                $liveParts[] = 'Vorschlag-Verbindungen: '
                    .number_format((int) $foundSuggestions, 0, ',', '.');
            }

            if ($observedSuggestionCount !== null) {
                $suggestionCountText = 'Vorschlaege gesehen: '
                    .number_format((int) $observedSuggestionCount, 0, ',', '.');

                if ($knownSuggestionCount !== null || $skippedSuggestions !== null) {
                    $suggestionCountText .= ' (bekannt/uebersprungen: '
                        .number_format((int) max((int) $knownSuggestionCount, (int) $skippedSuggestions), 0, ',', '.')
                        .')';
                }

                $liveParts[] = $suggestionCountText;
            }

            $liveCounts = implode(' | ', $liveParts);
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

    private function markInstagramScanCancelled(TrackedPerson $trackedPerson, string $message): void
    {
        $trackedPerson->markInstagramScanTerminal('cancelled', $message);
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
        $hasSuggestions = array_key_exists('suggestionConnections', $state);
        $hasObservedSuggestions = array_key_exists('observedSuggestions', $state);

        if (! $hasFollowers && ! $hasFollowing && ! $hasSuggestions && ! $hasObservedSuggestions) {
            if (! in_array(($state['phase'] ?? null), ['public-connections', 'suggestions'], true)) {
                $this->stream('instagram-progress-connection-results', '', true);
            }

            return;
        }

        $followers = $this->normalizeProgressConnectionItems($state['inferredFollowers'] ?? []);
        $following = $this->normalizeProgressConnectionItems($state['inferredFollowing'] ?? []);
        $suggestions = $this->normalizeProgressConnectionItems($state['suggestionConnections'] ?? []);
        $observedSuggestions = $this->normalizeProgressSuggestionItems($state['observedSuggestions'] ?? []);

        $this->stream(
            'instagram-progress-connection-results',
            $this->renderProgressConnectionResults($followers, $following, $suggestions, $hasSuggestions, $observedSuggestions),
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
                'fromSuggestionScan' => (bool) ($item['fromSuggestionScan'] ?? false),
                'relationshipOriginLabel' => is_scalar($item['relationshipOriginLabel'] ?? null)
                    ? trim((string) $item['relationshipOriginLabel'])
                    : '',
            ];
        }

        return $normalizedItems;
    }

    private function normalizeProgressSuggestionItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalizedItems = [];

        foreach (array_slice($items, 0, 60) as $item) {
            if (! is_array($item) || ! is_scalar($item['username'] ?? null)) {
                continue;
            }

            $username = trim((string) $item['username']);

            if ($username === '') {
                continue;
            }

            $normalizedItems[] = [
                'username' => ltrim($username, '@'),
                'displayName' => is_scalar($item['displayName'] ?? null) ? trim((string) $item['displayName']) : '',
                'profileUrl' => is_scalar($item['profileUrl'] ?? null) ? trim((string) $item['profileUrl']) : '',
                'checked' => (bool) ($item['checked'] ?? false),
                'skipped' => (bool) ($item['skipped'] ?? false),
                'matched' => (bool) ($item['matched'] ?? false),
                'alreadyKnown' => (bool) ($item['alreadyKnown'] ?? false),
                'skippedReason' => is_scalar($item['skippedReason'] ?? null) ? trim((string) $item['skippedReason']) : '',
            ];
        }

        return $normalizedItems;
    }

    private function renderProgressConnectionResults(array $followers, array $following, array $suggestions = [], bool $showSuggestions = false, array $observedSuggestions = []): string
    {
        $showObservedSuggestions = $observedSuggestions !== [];
        $gridClass = $showSuggestions && $showObservedSuggestions
            ? 'lg:grid-cols-4'
            : (($showSuggestions || $showObservedSuggestions) ? 'lg:grid-cols-3' : 'sm:grid-cols-2');

        return '<div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-3 text-left">'
            .'<div class="flex items-center justify-between gap-3 text-xs">'
            .'<span class="font-semibold uppercase tracking-wide text-slate-500">Bisherige Treffer</span>'
            .'<span class="font-semibold text-slate-700">'
            .number_format(count($followers), 0, ',', '.').' Follower / '
            .number_format(count($following), 0, ',', '.').' Gefolgt'
            .($showSuggestions ? ' / '.number_format(count($suggestions), 0, ',', '.').' Vorschlaege' : '')
            .($showObservedSuggestions ? ' / '.number_format(count($observedSuggestions), 0, ',', '.').' gesehen' : '')
            .'</span>'
            .'</div>'
            .'<div class="mt-3 grid gap-3 '.$gridClass.'">'
            .$this->renderProgressConnectionList('Moegliche Follower', $followers)
            .$this->renderProgressConnectionList('Moeglich gefolgt', $following)
            .($showSuggestions ? $this->renderProgressConnectionList('Vorschlag-Verbindungen', $suggestions) : '')
            .($showObservedSuggestions ? $this->renderProgressSuggestionList('Gefundene Vorschlaege', $observedSuggestions) : '')
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
            $sourcePublicUsername = trim((string) ($item['sourcePublicUsername'] ?? ''));
            $relationshipOriginLabel = trim((string) ($item['relationshipOriginLabel'] ?? ''));
            $fromSuggestionScan = (bool) ($item['fromSuggestionScan'] ?? false);
            $meta = $sourcePublicUsername !== '' ? 'Quelle: @'.$sourcePublicUsername : null;
            $statusLabel = null;

            if ($fromSuggestionScan || $relationshipOriginLabel !== '') {
                $statusLabel = $relationshipOriginLabel !== '' ? $relationshipOriginLabel : 'Aus Vorschlaege-Scan';
            }

            $html .= $this->renderProgressProfileListItem($item, $meta, $statusLabel, 'amber');
        }

        if (count($items) > 20) {
            $html .= '<div class="px-2 py-1 text-[11px] font-semibold text-slate-500">+'
                .number_format(count($items) - 20, 0, ',', '.')
                .' weitere Treffer</div>';
        }

        return $html.'</div></div>';
    }

    private function renderProgressSuggestionList(string $label, array $items): string
    {
        $html = '<div class="rounded-lg border border-slate-200 bg-white p-2">'
            .'<div class="flex items-center justify-between gap-2 px-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">'
            .'<span>'.e($label).'</span>'
            .'<span>'.number_format(count($items), 0, ',', '.').'</span>'
            .'</div>';

        if ($items === []) {
            return $html.'<div class="mt-2 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">Noch keine Vorschlaege erkannt.</div></div>';
        }

        $html .= '<div class="mt-2 max-h-48 space-y-1 overflow-y-auto pr-1">';

        foreach (array_slice($items, 0, 24) as $item) {
            $status = $this->progressSuggestionStatusLabel($item);
            $statusTone = $status === 'Treffer' ? 'emerald' : ($status === 'Fehler' ? 'rose' : 'slate');

            $html .= $this->renderProgressProfileListItem($item, null, $status, $statusTone);
        }

        if (count($items) > 24) {
            $html .= '<div class="px-2 py-1 text-[11px] font-semibold text-slate-500">+'
                .number_format(count($items) - 24, 0, ',', '.')
                .' weitere Vorschlaege</div>';
        }

        return $html.'</div></div>';
    }

    private function renderProgressProfileListItem(array $item, ?string $meta = null, ?string $statusLabel = null, string $statusTone = 'slate'): string
    {
        return view('components.instagram.profile-list-item', [
            'item' => $item,
            'meta' => $meta,
            'statusLabel' => $statusLabel,
            'statusTone' => $statusTone,
            'showVisibility' => false,
            'showAction' => false,
            'actionLabel' => null,
            'compact' => true,
        ])->render();
    }

    private function progressSuggestionStatusLabel(array $item): string
    {
        if ((bool) ($item['matched'] ?? false)) {
            return 'Treffer';
        }

        if ((bool) ($item['alreadyKnown'] ?? false)) {
            return 'bekannt';
        }

        $reason = (string) ($item['skippedReason'] ?? '');

        return match ($reason) {
            'already-dismissed-no-match' => 'kein Treffer',
            'already-scanned-suggestion' => 'bereits gescannt',
            'candidate-error', 'candidate-navigation-error' => 'Fehler',
            default => (bool) ($item['checked'] ?? false)
                ? 'geprueft'
                : ((bool) ($item['skipped'] ?? false) ? 'uebersprungen' : 'gesehen'),
        };
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
            'publicProfileTrackedPersonId' => ['nullable', 'string'],
            'manualPublicProfileUsername' => ['nullable', 'string', 'max:255'],
            'publicProfileRelationshipType' => ['required', 'string', 'in:follows_target,followed_by_target,mutual,public_connection,close_friend,acquaintance,family'],
        ]);

        $trackedPerson = $this->resolveTrackedPerson();
        $selectedValue = trim((string) ($validated['publicProfileTrackedPersonId'] ?? ''));
        $manualUsername = $this->normalizeHandle($validated['manualPublicProfileUsername'] ?? null);

        if ($selectedValue === '' && ! $manualUsername) {
            $this->addError('publicProfileTrackedPersonId', 'Bitte ein Profil waehlen oder einen Instagram-Namen eintragen.');

            return;
        }

        $displayName = null;
        $profileUrl = null;
        $isPublic = false;

        if ($selectedValue !== '') {
            if (str_starts_with($selectedValue, 'reconstructed:')) {
                $username = ltrim(substr($selectedValue, strlen('reconstructed:')), '@');
                $displayName = null;
                $profileUrl = 'https://www.instagram.com/'.$username.'/';
                $isPublic = false;
            } else {
                $linkedTrackedPerson = Auth::user()
                    ->trackedPeople()
                    ->with('latestInstagramSnapshot')
                    ->whereKey((int) $selectedValue)
                    ->where('id', '!=', $trackedPerson->id)
                    ->whereNotNull('instagram_username')
                    ->first();

                if (! $linkedTrackedPerson) {
                    $this->addError('publicProfileTrackedPersonId', 'Bitte ein anderes beobachtetes Instagram-Profil auswaehlen.');

                    return;
                }

                $username = $this->normalizeHandle($linkedTrackedPerson->instagram_username);
                $displayName = $linkedTrackedPerson->display_name;
                $profileUrl = 'https://www.instagram.com/'.$username.'/';
                $visibility = data_get($linkedTrackedPerson->latestInstagramSnapshot?->raw_payload, 'extractedProfile.profileVisibility');
                $isPublic = $visibility === 'public';
            }
        } else {
            $username = $manualUsername;
            $profileUrl = 'https://www.instagram.com/'.$username.'/';
            $displayName = null;
            $isPublic = false;
        }

        $publicProfile = $trackedPerson->publicProfiles()->firstOrNew([
            'platform' => 'instagram',
            'username' => $username,
        ]);
        $wasExisting = $publicProfile->exists;

        $publicProfile->fill([
            'user_id' => Auth::id(),
            'display_name' => $displayName,
            'relationship_type' => $validated['publicProfileRelationshipType'],
            'profile_url' => $profileUrl,
            'is_public' => $isPublic,
        ]);
        $publicProfile->save();
        app(InstagramProfileRelationshipStore::class)->syncPublicProfile($publicProfile);

        $this->resetPublicProfileForm();
        $this->setDetailStatus(
            $wasExisting
                ? 'Bekannte Verbindung wurde aktualisiert.'
                : 'Bekannte Verbindung wurde gespeichert.',
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

    public function deleteReconstructedProfileCandidate(string $candidateUsername): void
    {
        $trackedPerson = $this->resolveTrackedPerson();
        $candidateUsername = ltrim($this->normalizeHandle($candidateUsername) ?? '', '@');

        if ($candidateUsername === '') {
            $this->setDetailStatus('Rekonstruiertes Profil konnte nicht entfernt werden.', 'error');

            return;
        }

        $deleted = TrackedPersonInstagramInferredConnection::query()
            ->where('tracked_person_id', $trackedPerson->id)
            ->where('candidate_username', $candidateUsername)
            ->delete();

        if ($deleted < 1) {
            $this->setDetailStatus('Es wurden keine rekonstruierten Verbindungen zum Entfernen gefunden.', 'partial');

            return;
        }

        if ($this->publicProfileTrackedPersonId === 'reconstructed:'.$candidateUsername) {
            $this->publicProfileTrackedPersonId = '';
        }

        $this->setDetailStatus('Rekonstruiertes Profil wurde entfernt.', 'success');
        $this->dispatch('tracked-person-refresh');
    }

    public function render()
    {
        if ($this->compact) {
            $trackedPerson = $this->resolveTrackedPerson()->load('latestInstagramSnapshot');

            return view('livewire.user.tracked-person-scan-controls', [
                'trackedPerson' => $trackedPerson,
                'resumableInstagramScan' => $this->resumableInstagramScan($trackedPerson),
            ]);
        }

        $trackedPerson = $this->resolveTrackedPerson()
            ->load([
                'knownFacts' => fn ($query) => $query->latest(),
                'publicProfiles' => fn ($query) => $query
                    ->where('platform', 'instagram')
                    ->with('latestInstagramConnectionScan')
                    ->latest(),
                'instagramPublicProfileScans' => fn ($query) => $query
                    ->with([
                        'publicProfile',
                        'logs' => fn ($logQuery) => $logQuery->latest('logged_at')->limit(3),
                    ])
                    ->latest('analyzed_at')
                    ->limit(20),
                'instagramSuggestionScans' => fn ($query) => $query
                    ->latest('analyzed_at')
                    ->limit(20),
                'instagramInferredConnections' => fn ($query) => $query
                    ->with(['publicProfile', 'candidateInstagramProfile'])
                    ->latest('last_seen_at')
                    ->limit(100),
                'currentInstagramProfile.postScans' => fn ($query) => $query
                    ->where('user_id', Auth::id())
                    ->latest('scanned_at')
                    ->limit(10),
                'currentInstagramProfile.posts' => fn ($query) => $query
                    ->with('media')
                    ->withCount([
                        'metrics',
                        'likes as stored_likes_count' => fn ($likes) => $likes->where('is_active', true),
                        'comments as stored_comments_count' => fn ($comments) => $comments->where('is_active', true),
                    ])
                    ->latest('published_at')
                    ->latest('last_seen_at')
                    ->limit(24),
                'latestInstagramSnapshot.media' => fn ($query) => $query->orderBy('sort_order'),
                'instagramSnapshots' => fn ($query) => $query
                    ->where('has_changes', true)
                    ->latest('analyzed_at')
                    ->limit(6),
            ]);
        $profileImageHistory = $this->loadGlobalInstagramProfileImageHistory($trackedPerson);
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
        $reconstructedProfileCandidates = TrackedPersonInstagramInferredConnection::query()
            ->where('tracked_person_id', $trackedPerson->id)
            ->whereIn('relationship_type', ['follows_target', 'followed_by_target', 'suggestion_connection'])
            ->with('candidateInstagramProfile')
            ->latest('last_seen_at')
            ->get(['candidate_username', 'candidate_display_name', 'relationship_type', 'last_seen_at', 'candidate_instagram_profile_id', 'source_public_username'])
            ->unique('candidate_username')
            ->values();
        $view = view('livewire.user.tracked-person-detail', [
            'trackedPerson' => $trackedPerson,
            'profileImageHistory' => $profileImageHistory,
            'publicProfileCandidates' => $publicProfileCandidates,
            'reconstructedProfileCandidates' => $reconstructedProfileCandidates,
            'profilePickerRows' => $this->profilePickerRows($publicProfileCandidates, $reconstructedProfileCandidates),
        ] + $this->instagramDetailViewData($trackedPerson, $publicProfileCandidates));

        if (request()->routeIs('tracked-people.show')) {
            return $view->layout('layouts.app');
        }

        return $view;
    }

    private function instagramDetailViewData(
        TrackedPerson $trackedPerson,
        Collection $publicProfileCandidates,
    ): array {
        $latestSnapshot = $trackedPerson->latestInstagramSnapshot;
        $latestCountSources = data_get($latestSnapshot?->raw_payload, 'extractedProfile.countSources', []);
        $latestCountWarnings = data_get($latestSnapshot?->raw_payload, 'extractedProfile.countWarnings', []);
        $latestDebugLogPath = data_get($latestSnapshot?->raw_payload, 'debugLogPath');
        $latestCookieDiagnostics = data_get($latestSnapshot?->raw_payload, 'cookieDiagnostics', []);
        $latestLoginDiagnostics = data_get($latestSnapshot?->raw_payload, 'loginDiagnostics', []);
        $relationshipListData = app(InstagramRelationshipListData::class);
        $followersListData = $relationshipListData->forTrackedPerson($trackedPerson, 'followers');
        $followingListData = $relationshipListData->forTrackedPerson($trackedPerson, 'following');
        $latestFollowersList = $followersListData['relationshipList'];
        $latestFollowingList = $followingListData['relationshipList'];
        $latestFollowerAddedItems = $followersListData['addedItems'];
        $latestFollowingAddedItems = $followingListData['addedItems'];
        $latestFollowerItems = $followersListData['activeItems'];
        $latestFollowingItems = $followingListData['activeItems'];
        $latestFollowerScanRemovedItems = $followersListData['scanRemovedItems'];
        $latestFollowingScanRemovedItems = $followingListData['scanRemovedItems'];
        $latestFollowerRemovedItems = $followersListData['removedItems'];
        $latestFollowingRemovedItems = $followingListData['removedItems'];
        $latestFollowerRemovedHistoryItems = $followersListData['removedHistoryItems'];
        $latestFollowingRemovedHistoryItems = $followingListData['removedHistoryItems'];
        $latestFollowerStats = $followersListData['stats'];
        $latestFollowingStats = $followingListData['stats'];
        $latestFollowerListAvailable = $followersListData['available'];
        $latestFollowingListAvailable = $followingListData['available'];

        $instagramStatusLevel = $trackedPerson->last_instagram_status_level ?: 'neutral';
        $latestProfileVisibility = data_get($latestSnapshot?->raw_payload, 'extractedProfile.profileVisibility');
        $latestScrapePhases = collect(data_get($latestSnapshot?->raw_payload, 'analysisPolicy.scrapePhases', []));
        $selectedPost = null;

        if ($this->showPostEngagementModal && $this->selectedPostId && $trackedPerson->current_instagram_profile_id) {
            $selectedPost = InstagramPost::query()
                ->whereKey($this->selectedPostId)
                ->where('instagram_profile_id', $trackedPerson->current_instagram_profile_id)
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

        return [
            'detailStatusClass' => $this->detailStatusClass($this->detailStatusLevel),
            'instagramStatusLevel' => $instagramStatusLevel,
            'instagramStatusLabel' => $this->instagramStatusLabel($instagramStatusLevel),
            'instagramStatusBadgeClass' => $this->instagramStatusBadgeClass($instagramStatusLevel),
            'isStandaloneDetailPage' => ! $this->compact,
            'latestSnapshot' => $latestSnapshot,
            'latestCountSources' => $latestCountSources,
            'latestCountWarnings' => $latestCountWarnings,
            'latestDebugLogPath' => $latestDebugLogPath,
            'latestCookieDiagnostics' => $latestCookieDiagnostics,
            'latestLoginDiagnostics' => $latestLoginDiagnostics,
            'latestFollowersList' => $latestFollowersList,
            'latestFollowingList' => $latestFollowingList,
            'latestFollowerAddedItems' => $latestFollowerAddedItems,
            'latestFollowingAddedItems' => $latestFollowingAddedItems,
            'latestFollowerItems' => $latestFollowerItems,
            'latestFollowingItems' => $latestFollowingItems,
            'latestFollowerScanRemovedItems' => $latestFollowerScanRemovedItems,
            'latestFollowingScanRemovedItems' => $latestFollowingScanRemovedItems,
            'latestFollowerRemovedItems' => $latestFollowerRemovedItems,
            'latestFollowingRemovedItems' => $latestFollowingRemovedItems,
            'latestFollowerRemovedHistoryItems' => $latestFollowerRemovedHistoryItems,
            'latestFollowingRemovedHistoryItems' => $latestFollowingRemovedHistoryItems,
            'latestFollowerStats' => $latestFollowerStats,
            'latestFollowingStats' => $latestFollowingStats,
            'latestFollowerListAvailable' => $latestFollowerListAvailable,
            'latestFollowingListAvailable' => $latestFollowingListAvailable,
            'inferredInstagramFollowers' => $trackedPerson->instagramInferredConnections
                ->where('relationship_type', 'follows_target')
                ->unique('candidate_username')
                ->values(),
            'inferredInstagramFollowing' => $trackedPerson->instagramInferredConnections
                ->where('relationship_type', 'followed_by_target')
                ->unique('candidate_username')
                ->values(),
            'suggestionInstagramConnections' => $trackedPerson->instagramInferredConnections
                ->where('relationship_type', 'suggestion_connection')
                ->unique('candidate_username')
                ->values(),
            'latestScrapePhases' => $latestScrapePhases,
            'latestProfileVisibility' => $latestProfileVisibility,
            'latestProfileVisibilityLabel' => $this->profileVisibilityLabel($latestProfileVisibility),
            'latestProfileVisibilityBadgeClass' => $this->profileVisibilityBadgeClass($latestProfileVisibility),
            'latestProfileIsPublic' => $latestProfileVisibility === 'public',
            'latestProfileIsPrivate' => $latestProfileVisibility === 'private',
            'profileImageFrameClass' => $this->profileImageFrameClass($latestProfileVisibility),
            'profileStatusDotClass' => $this->profileStatusDotClass($latestProfileVisibility),
            'resolveCountSourceLabel' => fn ($source): string => $this->resolveCountSourceLabel($source),
            'connectionScanScreenshots' => fn (array $payload): Collection => $this->connectionScanScreenshots($payload),
            'snapshotScreenshots' => fn ($instagramSnapshot): Collection => $this->snapshotScreenshots($instagramSnapshot),
            'publicProfileRows' => $this->publicProfileRows($trackedPerson),
            'publicProfileCandidateRows' => $this->publicProfileCandidateRows($publicProfileCandidates),
            'connectionScanRows' => $this->connectionScanRows($trackedPerson->instagramPublicProfileScans),
            'suggestionScanRows' => $this->suggestionScanRows($trackedPerson->instagramSuggestionScans),
            'latestSnapshotStatusClass' => $this->snapshotStatusClass($latestSnapshot?->status_level),
            'latestSnapshotScreenshots' => $this->snapshotScreenshots($latestSnapshot),
            'latestScrapePhaseRows' => $this->scrapePhaseRows($latestScrapePhases ?? collect()),
            'latestDetectedChangeRows' => $this->detectedChangeRows($latestSnapshot?->detected_changes ?? []),
            'currentInstagramPostRows' => $this->instagramPostRows($trackedPerson->currentInstagramProfile?->posts ?? collect()),
            'historySnapshotRows' => $this->historySnapshotRows($trackedPerson->instagramSnapshots ?? collect()),
            'resumableInstagramScan' => $this->resumableInstagramScan($trackedPerson),
            'selectedPost' => $selectedPost,
            'scanCostSummary' => $this->scanCostSummary(),
        ];
    }

    private function resumableInstagramScan(TrackedPerson $trackedPerson): ?array
    {
        $candidates = [];
        $latestListScan = InstagramProfileListScan::query()
            ->where('tracked_person_id', $trackedPerson->id)
            ->where('user_id', $trackedPerson->user_id)
            ->latest('scanned_at')
            ->first();

        if (
            $latestListScan
            && ! (bool) data_get($latestListScan->raw_payload, 'resumeDismissedAt')
            && (
                $latestListScan->gracefully_stopped
                || $latestListScan->rate_limited
                || ! $latestListScan->complete
            )
        ) {
            $candidates[] = [
                'source' => 'list',
                'id' => (int) $latestListScan->id,
                'scan_type' => $latestListScan->list_type,
                'label' => $latestListScan->list_type === 'followers' ? 'Followerlisten-Scan' : 'Gefolgt-Listen-Scan',
                'message' => $latestListScan->status_message ?: 'Der Listen-Scan wurde unterbrochen.',
                'saved_count' => (int) $latestListScan->observed_count,
                'date' => $latestListScan->scanned_at,
            ];
        }

        $latestSuggestionScan = TrackedPersonInstagramSuggestionScan::query()
            ->where('tracked_person_id', $trackedPerson->id)
            ->where('user_id', $trackedPerson->user_id)
            ->latest('analyzed_at')
            ->first();
        $suggestionPayload = is_array($latestSuggestionScan?->raw_payload) ? $latestSuggestionScan->raw_payload : [];
        $deepSearch = data_get($suggestionPayload, 'operationMode') === 'suggestion-connections';
        $suggestionsIncomplete = $deepSearch
            && $latestSuggestionScan
            && (int) $latestSuggestionScan->suggestions_observed_count > (int) $latestSuggestionScan->suggestions_checked_count;

        if (
            $latestSuggestionScan
            && ! (bool) data_get($suggestionPayload, 'resumeDismissedAt')
            && ($latestSuggestionScan->gracefully_stopped || $suggestionsIncomplete)
        ) {
            $candidates[] = [
                'source' => 'suggestion',
                'id' => (int) $latestSuggestionScan->id,
                'scan_type' => $deepSearch ? 'suggestion_deepsearch' : 'suggestions',
                'label' => $deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan',
                'message' => $latestSuggestionScan->status_message ?: 'Der Vorschlaege-Scan wurde unterbrochen.',
                'saved_count' => $deepSearch
                    ? (int) $latestSuggestionScan->suggestions_checked_count
                    : (int) $latestSuggestionScan->suggestions_observed_count,
                'date' => $latestSuggestionScan->analyzed_at,
            ];
        }

        $latestPublicScan = TrackedPersonInstagramPublicProfileScan::query()
            ->where('tracked_person_id', $trackedPerson->id)
            ->where('user_id', $trackedPerson->user_id)
            ->latest('analyzed_at')
            ->first();
        $publicPayload = is_array($latestPublicScan?->raw_payload) ? $latestPublicScan->raw_payload : [];

        if (
            $latestPublicScan
            && (bool) data_get($publicPayload, 'isResumable', false)
            && ! (bool) data_get($publicPayload, 'resumeDismissedAt')
        ) {
            $candidates[] = [
                'source' => 'public_connections',
                'id' => (int) $latestPublicScan->id,
                'scan_type' => 'public_connections',
                'label' => 'Public-Profile-Verbindungsscan',
                'message' => $latestPublicScan->status_message ?: 'Der Verbindungsscan wurde unterbrochen.',
                'saved_count' => (int) data_get($publicPayload, 'candidatesChecked', 0),
                'date' => $latestPublicScan->analyzed_at,
            ];
        }

        return collect($candidates)
            ->sortByDesc(fn (array $candidate) => optional($candidate['date'])->getTimestamp() ?? 0)
            ->first();
    }

    private function detailStatusClass(?string $level): string
    {
        return match ($level ?? 'neutral') {
            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
            default => 'border-slate-200 bg-slate-50 text-slate-800',
        };
    }

    private function instagramStatusLabel(string $level): string
    {
        return match ($level) {
            'success' => 'Aktuell',
            'partial' => 'Teilweise',
            'error' => 'Fehler',
            default => 'Offen',
        };
    }

    private function instagramStatusBadgeClass(string $level): string
    {
        return match ($level) {
            'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'partial' => 'bg-amber-50 text-amber-800 ring-amber-200',
            'error' => 'bg-rose-50 text-rose-700 ring-rose-200',
            default => 'bg-slate-100 text-slate-600 ring-slate-200',
        };
    }

    private function profileVisibilityLabel(?string $visibility): string
    {
        return match ($visibility) {
            'public' => 'Oeffentlich',
            'private' => 'Privat',
            default => 'Unbekannt',
        };
    }

    private function profileVisibilityBadgeClass(?string $visibility): string
    {
        return match ($visibility) {
            'public' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'private' => 'bg-slate-100 text-slate-700 ring-slate-200',
            default => 'bg-amber-50 text-amber-800 ring-amber-200',
        };
    }

    private function profileImageFrameClass(?string $visibility): string
    {
        return match ($visibility) {
            'public' => 'border-emerald-400 ring-4 ring-emerald-100',
            'private' => 'border-slate-300 ring-4 ring-slate-100',
            default => 'border-amber-300 ring-4 ring-amber-100',
        };
    }

    private function profileStatusDotClass(?string $visibility): string
    {
        return match ($visibility) {
            'public' => 'bg-emerald-500',
            'private' => 'bg-slate-400',
            default => 'bg-amber-400',
        };
    }

    private function resolveCountSourceLabel(mixed $source): string
    {
        $labels = [
            'body_text_preview' => 'sichtbarer Profiltext',
            'profile_dom' => 'sichtbarer Profil-DOM',
            'description_meta' => 'Meta-Beschreibung',
            'html_document' => 'HTML-Fallback',
            'html_profile_data' => 'Profil-Daten im HTML',
        ];

        return $source ? ($labels[$source] ?? (string) $source) : 'keine sichtbaren Werte';
    }

    private function screenshotUrl(string $path): string
    {
        return Storage::disk('public')->url($path);
    }

    private function connectionScanScreenshots(array $payload): Collection
    {
        $screenshots = collect();
        $mainScreenshotPath = data_get($payload, 'screenshotPath');

        if (is_string($mainScreenshotPath) && $mainScreenshotPath !== '') {
            $screenshots->push([
                'label' => 'Scan-Screenshot',
                'path' => $mainScreenshotPath,
                'url' => $this->screenshotUrl($mainScreenshotPath),
                'meta' => null,
            ]);
        }

        foreach (data_get($payload, 'candidateErrorScreenshots', []) as $entry) {
            $path = is_array($entry) ? data_get($entry, 'screenshotPath') : null;

            if (! is_string($path) || $path === '') {
                continue;
            }

            $screenshots->push([
                'label' => 'Kandidatenfehler',
                'path' => $path,
                'url' => $this->screenshotUrl($path),
                'meta' => trim('@'.data_get($entry, 'candidateUsername', '').' Versuch '.data_get($entry, 'attempt', '-')),
            ]);
        }

        foreach (data_get($payload, 'checkedPreview', []) as $connection) {
            foreach (data_get($connection, 'debugScreenshotPaths', []) as $path) {
                if (! is_string($path) || $path === '') {
                    continue;
                }

                $screenshots->push([
                    'label' => 'Kandidat',
                    'path' => $path,
                    'url' => $this->screenshotUrl($path),
                    'meta' => '@'.data_get($connection, 'username', '-'),
                ]);
            }
        }

        return $screenshots->unique('path')->values();
    }

    private function snapshotScreenshots(mixed $instagramSnapshot): Collection
    {
        $screenshots = collect();

        if (! $instagramSnapshot) {
            return $screenshots;
        }

        if (is_string($instagramSnapshot->screenshot_path) && $instagramSnapshot->screenshot_path !== '') {
            $screenshots->push([
                'label' => 'Debug-Screenshot',
                'path' => $instagramSnapshot->screenshot_path,
                'url' => $instagramSnapshot->screenshot_url,
                'meta' => null,
            ]);
        }

        foreach (data_get($instagramSnapshot->raw_payload, 'analysisPolicy.scrapePhases', []) as $phase) {
            $path = data_get($phase, 'screenshotPath');

            if (! is_string($path) || $path === '') {
                continue;
            }

            $screenshots->push([
                'label' => match (data_get($phase, 'phase')) {
                    'profile' => 'Grunddaten',
                    'followers' => 'Followerliste',
                    'following' => 'Gefolgt-Liste',
                    default => data_get($phase, 'phase', 'Phase'),
                },
                'path' => $path,
                'url' => $this->screenshotUrl($path),
                'meta' => data_get($phase, 'statusLevel'),
            ]);
        }

        return $screenshots->unique('path')->values();
    }

    private function publicProfileRows(TrackedPerson $trackedPerson): Collection
    {
        return $trackedPerson->publicProfiles
            ->map(function ($publicProfile) {
                $latestConnectionScan = $publicProfile->latestInstagramConnectionScan;

                return (object) [
                    'profile' => $publicProfile,
                    'latestConnectionScan' => $latestConnectionScan,
                    'connectionStatusClass' => $this->connectionStatusClass($latestConnectionScan?->status_level),
                    'latestConnectionSummary' => $latestConnectionScan
                        ? $this->connectionScanSummary($latestConnectionScan, true)
                        : null,
                ];
            })
            ->values();
    }

    private function publicProfileCandidateRows(Collection $publicProfileCandidates): Collection
    {
        return $publicProfileCandidates
            ->map(fn (TrackedPerson $candidate) => (object) [
                'candidate' => $candidate,
                'visibilityLabel' => match (data_get($candidate->latestInstagramSnapshot?->raw_payload, 'extractedProfile.profileVisibility')) {
                    'public' => 'oeffentlich',
                    'private' => 'privat',
                    default => 'unbekannt',
                },
            ])
            ->values();
    }

    private function profilePickerRows(Collection $publicProfileCandidates, Collection $reconstructedProfileCandidates): Collection
    {
        $trackedRows = $publicProfileCandidates
            ->map(function (TrackedPerson $candidate) {
                $visibility = match (data_get($candidate->latestInstagramSnapshot?->raw_payload, 'extractedProfile.profileVisibility')) {
                    'public' => 'oeffentlich',
                    'private' => 'privat',
                    default => 'unbekannt',
                };

                return (object) [
                    'type' => 'tracked',
                    'value' => (string) $candidate->id,
                    'title' => '@'.$candidate->instagram_username,
                    'subtitle' => $candidate->display_name ?: 'Beobachtetes Profil',
                    'meta' => 'Beobachtet · '.$visibility,
                    'imageUrl' => $candidate->profile_image_url,
                    'canDelete' => false,
                    'deleteValue' => null,
                ];
            });

        $reconstructedRows = $reconstructedProfileCandidates
            ->map(function ($candidate) {
                $profile = $candidate->candidateInstagramProfile;
                $visibility = match ($profile?->profile_visibility) {
                    'public' => 'oeffentlich',
                    'private' => 'privat',
                    default => 'unbekannt',
                };

                return (object) [
                    'type' => 'reconstructed',
                    'value' => 'reconstructed:'.ltrim((string) $candidate->candidate_username, '@'),
                    'title' => '@'.ltrim((string) $candidate->candidate_username, '@'),
                    'subtitle' => $candidate->candidate_display_name ?: ($profile?->display_name ?: 'Rekonstruiertes Profil'),
                    'meta' => 'Rekonstruiert · '.$visibility.($candidate->source_public_username ? ' · Quelle @'.ltrim((string) $candidate->source_public_username, '@') : ''),
                    'imageUrl' => $profile?->profile_image_storage_url,
                    'canDelete' => true,
                    'deleteValue' => ltrim((string) $candidate->candidate_username, '@'),
                ];
            });

        return $trackedRows
            ->concat($reconstructedRows)
            ->values();
    }

    private function reconstructedProfileCandidates(Collection $inferredConnections): Collection
    {
        return $inferredConnections
            ->whereIn('relationship_type', ['follows_target', 'followed_by_target', 'suggestion_connection'])
            ->unique('candidate_username')
            ->values();
    }

    private function connectionScanRows(Collection $connectionScans): Collection
    {
        return $connectionScans
            ->map(fn ($connectionScan) => (object) [
                'scan' => $connectionScan,
                'summary' => $this->connectionScanSummary($connectionScan),
            ])
            ->values();
    }

    private function suggestionScanRows(Collection $suggestionScans): Collection
    {
        return $suggestionScans
            ->map(function ($suggestionScan) {
                $rawPayload = is_array($suggestionScan->raw_payload) ? $suggestionScan->raw_payload : [];
                $scanPayload = data_get($rawPayload, 'suggestionScan', data_get($rawPayload, 'profile.suggestionScan', []));
                $debug = data_get($scanPayload, 'targetCollectionDebug', []);
                $debugEvents = collect(data_get($debug, 'events', []))
                    ->filter(fn ($event) => is_array($event))
                    ->take(-8)
                    ->values();
                $scrollEvents = collect(data_get($debug, 'scrollEvents', []))
                    ->filter(fn ($event) => is_array($event))
                    ->take(-8)
                    ->values();
                $lastDebug = $debugEvents->last();
                $lastScroll = $scrollEvents->last();

                return (object) [
                    'scan' => $suggestionScan,
                    'scanTypeLabel' => data_get($rawPayload, 'operationMode') === 'suggestion-connections'
                        ? 'Vorschlaege DeepSearch'
                        : 'Vorschlaege-Scan',
                    'statusClass' => $this->connectionScanStatusClass($suggestionScan->status_level),
                    'payload' => $scanPayload,
                    'debug' => $debug,
                    'debugEvents' => $debugEvents,
                    'scrollEvents' => $scrollEvents,
                    'finalUsernames' => collect(data_get($debug, 'finalUsernames', []))->filter()->take(30)->values(),
                    'surfaceDebug' => data_get($debug, 'surfaceBeforeCollection', []),
                    'observedPreview' => collect(data_get($scanPayload, 'observedSuggestions', []))
                        ->filter(fn ($item) => is_array($item) && filled($item['username'] ?? null))
                        ->take(30)
                        ->values(),
                    'lastDebug' => $lastDebug,
                    'lastScroll' => $lastScroll,
                    'textSamples' => collect(data_get($lastDebug, 'textSamples', []))->take(20),
                    'anchorSamples' => collect(data_get($lastDebug, 'anchorSamples', []))->take(20),
                    'scopeSamples' => collect(data_get($lastDebug, 'scopeSamples', []))->take(8),
                ];
            })
            ->values();
    }

    private function connectionScanSummary(mixed $scan, bool $highlightStatus = false): object
    {
        $payload = is_array($scan?->raw_payload) ? $scan->raw_payload : [];
        $candidateErrorScreenshots = collect(data_get($payload, 'candidateErrorScreenshots', []))
            ->filter(fn ($entry) => is_array($entry) && data_get($entry, 'screenshotPath'))
            ->values();
        $screenshotPath = data_get($payload, 'screenshotPath');
        $candidateErrorScreenshotPath = data_get($candidateErrorScreenshots->first(), 'screenshotPath');

        return (object) [
            'inferredFollowerCount' => count(data_get($payload, 'inferredFollowers', [])),
            'inferredFollowingCount' => count(data_get($payload, 'inferredFollowing', [])),
            'screenshotPath' => $screenshotPath,
            'screenshotUrl' => is_string($screenshotPath) && $screenshotPath !== '' ? $this->screenshotUrl($screenshotPath) : null,
            'candidateErrorScreenshots' => $candidateErrorScreenshots,
            'candidateErrorScreenshotPath' => $candidateErrorScreenshotPath,
            'candidateErrorScreenshotUrl' => is_string($candidateErrorScreenshotPath) && $candidateErrorScreenshotPath !== '' ? $this->screenshotUrl($candidateErrorScreenshotPath) : null,
            'screenshots' => $this->connectionScanScreenshots($payload),
            'statusClass' => $highlightStatus
                ? $this->connectionStatusClass($scan?->status_level)
                : $this->connectionScanStatusClass($scan?->status_level),
        ];
    }

    private function connectionStatusClass(?string $level): string
    {
        return match ($level) {
            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
            default => 'border-slate-200 bg-white text-slate-600',
        };
    }

    private function connectionScanStatusClass(?string $level): string
    {
        return match ($level) {
            'success' => 'border-emerald-200 bg-white text-emerald-900',
            'partial' => 'border-amber-200 bg-white text-amber-950',
            'error' => 'border-rose-200 bg-white text-rose-900',
            default => 'border-slate-200 bg-white text-slate-700',
        };
    }

    private function snapshotStatusClass(?string $level): string
    {
        return match ($level ?? 'neutral') {
            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
            default => 'border-slate-200 bg-slate-50 text-slate-700',
        };
    }

    private function scrapePhaseRows(Collection $scrapePhases): Collection
    {
        return $scrapePhases
            ->map(function ($phase) {
                $screenshotPath = data_get($phase, 'screenshotPath');

                return (object) [
                    'payload' => $phase,
                    'label' => match (data_get($phase, 'phase')) {
                        'profile' => 'Grunddaten',
                        'followers' => 'Followerliste',
                        'following' => 'Gefolgt-Liste',
                        default => data_get($phase, 'phase', 'Unbekannt'),
                    },
                    'statusLevel' => data_get($phase, 'statusLevel', 'unknown'),
                    'count' => data_get($phase, 'count'),
                    'screenshotPath' => $screenshotPath,
                    'screenshotUrl' => is_string($screenshotPath) && $screenshotPath !== '' ? $this->screenshotUrl($screenshotPath) : null,
                ];
            })
            ->values();
    }

    private function detectedChangeRows(mixed $detectedChanges): Collection
    {
        return collect($detectedChanges)
            ->map(fn ($change) => (object) [
                'change' => $change,
                'label' => $change['label'] ?? $change['field'],
                'before' => $this->formatSnapshotChangeValue($change, $change['before'] ?? null),
                'after' => $this->formatSnapshotChangeValue($change, $change['after'] ?? null),
            ])
            ->values();
    }

    private function formatSnapshotChangeValue(array $change, mixed $value): string
    {
        if (($change['field'] ?? null) === 'profile_visibility') {
            return $this->profileVisibilityLabel($value);
        }

        return filled($value) ? (string) $value : '-';
    }

    private function instagramPostRows(Collection $posts): Collection
    {
        return $posts
            ->map(function ($post) {
                $primaryMedia = $post->media->first();

                return (object) [
                    'post' => $post,
                    'primaryMedia' => $primaryMedia,
                    'mediaUrl' => $primaryMedia?->media_url,
                    'previewUrl' => $primaryMedia?->preview_media_url ?: $post->thumbnail_storage_url,
                ];
            })
            ->values();
    }

    private function historySnapshotRows(Collection $historySnapshots): Collection
    {
        return $historySnapshots
            ->map(fn ($historySnapshot) => (object) [
                'snapshot' => $historySnapshot,
                'screenshots' => $this->snapshotScreenshots($historySnapshot),
            ])
            ->values();
    }

    private function loadGlobalInstagramProfileImageHistory(TrackedPerson $trackedPerson): Collection
    {
        $instagramUsername = filled($trackedPerson->instagram_username)
            ? Str::lower(ltrim((string) $trackedPerson->instagram_username, '@'))
            : null;
        $instagramProfileId = $trackedPerson->current_instagram_profile_id
            ?: $trackedPerson->latestInstagramSnapshot?->instagram_profile_id;

        if (! $instagramProfileId && $instagramUsername) {
            $instagramProfileId = InstagramProfile::query()
                ->where('username', $instagramUsername)
                ->value('id');
        }

        $applySnapshotIdentity = function ($query) use ($instagramProfileId, $instagramUsername, $trackedPerson) {
            $query->where(function ($identityQuery) use ($instagramProfileId, $instagramUsername, $trackedPerson) {
                if ($instagramProfileId) {
                    $identityQuery->where('instagram_profile_id', $instagramProfileId);
                }

                if ($instagramUsername) {
                    $method = $instagramProfileId ? 'orWhereRaw' : 'whereRaw';
                    $identityQuery->{$method}('LOWER(instagram_username) = ?', [$instagramUsername]);
                    $identityQuery->orWhereHas(
                        'trackedPerson',
                        fn ($trackedPersonQuery) => $trackedPersonQuery->where('instagram_username', $instagramUsername),
                    );
                }

                if (! $instagramProfileId && ! $instagramUsername) {
                    $identityQuery->where('tracked_person_id', $trackedPerson->id);
                }
            });
        };

        $mediaRows = TrackedPersonInstagramMedia::query()
            ->with([
                'snapshot' => fn ($query) => $query->select('id', 'tracked_person_id', 'instagram_profile_id', 'instagram_username', 'analyzed_at'),
            ])
            ->whereHas('snapshot', $applySnapshotIdentity)
            ->where('is_profile_image', true)
            ->whereNotNull('storage_path')
            ->latest('id')
            ->limit(75)
            ->get()
            ->map(fn (TrackedPersonInstagramMedia $media): object => (object) [
                'storage_url' => $media->storage_url,
                'storage_path' => $media->storage_path,
                'content_hash' => $media->content_hash,
                'snapshot' => $media->snapshot,
                'analyzed_at' => $media->snapshot?->analyzed_at,
            ]);

        $snapshotQuery = TrackedPersonInstagramSnapshot::query();
        $applySnapshotIdentity($snapshotQuery);

        $snapshotRows = $snapshotQuery
            ->whereNotNull('profile_image_path')
            ->latest('analyzed_at')
            ->limit(75)
            ->get(['id', 'tracked_person_id', 'instagram_profile_id', 'instagram_username', 'profile_image_path', 'profile_image_hash', 'analyzed_at'])
            ->map(fn (TrackedPersonInstagramSnapshot $snapshot): object => (object) [
                'storage_url' => Storage::disk('public')->url($snapshot->profile_image_path),
                'storage_path' => $snapshot->profile_image_path,
                'content_hash' => $snapshot->profile_image_hash,
                'snapshot' => $snapshot,
                'analyzed_at' => $snapshot->analyzed_at,
            ]);

        return $mediaRows
            ->concat($snapshotRows)
            ->filter(fn (object $item): bool => filled($item->storage_url) && filled($item->storage_path))
            ->sortByDesc(fn (object $item): int => $item->analyzed_at?->getTimestamp() ?? 0)
            ->unique(fn (object $item): string => (string) ($item->content_hash ?: $item->storage_path))
            ->take(12)
            ->values();
    }

    private function scanListInstagramProfileAnalysis(InstagramProfile $profile, bool $fullScan): array
    {
        $result = app(InstagramProfileScanService::class)
            ->scan($profile, (int) Auth::id(), $fullScan);

        return [
            '@'.$profile->username.' '.($fullScan ? 'Vollanalyse' : 'Mini-Scan').': '.$result['statusMessage'],
            $result['statusLevel'],
        ];
    }

    private function scanListInstagramProfileRelationship(InstagramProfile $profile, string $relationship): array
    {
        $label = $relationship === 'followers' ? 'Followerliste' : 'Gefolgt-Liste';
        $scan = app(TrackedPersonInstagramProfileListScanService::class)
            ->scan(null, $profile, null, [$relationship], (int) Auth::id())
            ->first();

        return [
            '@'.$profile->username.' '.$label.' gescannt: '.number_format((int) ($scan?->active_count ?? 0), 0, ',', '.').' aktive Eintraege.',
            $scan?->status_level === 'success' ? 'success' : 'partial',
        ];
    }

    private function scanListInstagramProfilePosts(InstagramProfile $profile): array
    {
        $scan = app(TrackedPersonInstagramPostScanService::class)
            ->scanProfile($profile, (int) Auth::id());

        return [
            '@'.$profile->username.' Beitragsscan abgeschlossen: '
                .number_format($scan->observed_count, 0, ',', '.').' geprueft, '
                .number_format($scan->new_count, 0, ',', '.').' neu und '
                .number_format($scan->updated_count, 0, ',', '.').' aktualisiert.',
            $scan->status_level === 'success' ? 'success' : 'partial',
        ];
    }

    private function scanListInstagramProfileSuggestions(InstagramProfile $profile, bool $deepSearch): array
    {
        $trackedPerson = Auth::user()
            ->trackedPeople()
            ->where(function ($query) use ($profile): void {
                $query->where('current_instagram_profile_id', $profile->id)
                    ->orWhereRaw(
                        "LOWER(TRIM(LEADING '@' FROM instagram_username)) = ?",
                        [$profile->username],
                    );
            })
            ->first();

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

        return [
            '@'.$profile->username.' '.($deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan').' abgeschlossen: '
                .number_format($scan->suggestions_observed_count, 0, ',', '.').' Vorschlaege, '
                .number_format($scan->suggestion_matches_count, 0, ',', '.').' Verbindungen.',
            $scan->status_level === 'success' ? 'success' : 'partial',
        ];
    }

    private function resolveAccessibleInstagramProfile(?int $profileId = null, ?string $username = null): ?InstagramProfile
    {
        $userId = (int) Auth::id();
        $username = Str::lower(ltrim(trim((string) $username), '@'));

        if (! $profileId && $username === '') {
            return null;
        }

        return InstagramProfile::query()
            ->when($profileId, fn ($query) => $query->whereKey($profileId))
            ->when(! $profileId && $username !== '', fn ($query) => $query->where('username', $username))
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
            })
            ->first();
    }

    private function resolveTrackedPerson(): TrackedPerson
    {
        return Auth::user()
            ->trackedPeople()
            ->whereKey($this->trackedPersonId)
            ->firstOrFail();
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
        $this->monitoring_interval_minutes = max(1, (int) ($trackedPerson->monitoring_interval_minutes ?: 60));
        $instagramScanPreferences = $trackedPerson->instagramScanPreferences();
        $this->instagram_monitoring_scan_mode = $instagramScanPreferences['monitoring_scan_mode'];
        $this->instagram_auto_scan_followers = (bool) $instagramScanPreferences['auto_scan_followers'];
        $this->instagram_auto_scan_following = (bool) $instagramScanPreferences['auto_scan_following'];
        $this->instagram_auto_scan_posts = (bool) $instagramScanPreferences['auto_scan_posts'];
        $this->instagram_auto_scan_suggestions = (bool) $instagramScanPreferences['auto_scan_suggestions'];
        $this->instagram_auto_scan_on_changes = (bool) $instagramScanPreferences['auto_scan_on_changes'];
        $this->instagram_auto_scan_on_interval = (bool) $instagramScanPreferences['auto_scan_on_interval'];
        $this->instagram_auto_scan_min_interval_minutes = (int) $instagramScanPreferences['auto_scan_min_interval_minutes'];
        $this->instagram_auto_scan_count_change_threshold = (int) $instagramScanPreferences['auto_scan_count_change_threshold'];
        $this->notify_social_changes = (bool) $trackedPerson->notify_social_changes;
        $this->notify_instagram_changes = (bool) $trackedPerson->notify_instagram_changes;
        $this->notify_tiktok_changes = (bool) $trackedPerson->notify_tiktok_changes;
        $this->notify_facebook_changes = (bool) $trackedPerson->notify_facebook_changes;
        $this->notify_x_changes = (bool) $trackedPerson->notify_x_changes;
        $this->notify_youtube_changes = (bool) $trackedPerson->notify_youtube_changes;
        $this->notify_snapchat_changes = (bool) $trackedPerson->notify_snapchat_changes;
    }

    private function trackedPersonInstagramProfileIsPublic(TrackedPerson $trackedPerson): bool
    {
        return $this->trackedPersonInstagramProfileVisibility($trackedPerson) === 'public';
    }

    private function trackedPersonInstagramProfileIsPrivate(TrackedPerson $trackedPerson): bool
    {
        return $this->trackedPersonInstagramProfileVisibility($trackedPerson) === 'private';
    }

    private function trackedPersonInstagramProfileVisibility(TrackedPerson $trackedPerson): string
    {
        $snapshot = $trackedPerson->latestInstagramSnapshot;

        if ($snapshot) {
            return $snapshot->profile_visibility;
        }

        return 'unknown';
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
        $this->manualPublicProfileUsername = '';
    }

    public function saveManualPublicProfile(): void
    {
        $this->validate([
            'manualPublicProfileUsername' => ['required', 'string', 'max:255'],
            'publicProfileRelationshipType' => ['required', 'string', 'in:follows_target,followed_by_target,mutual,public_connection,close_friend,acquaintance,family'],
        ]);

        $trackedPerson = $this->resolveTrackedPerson();
        $username = $this->normalizeHandle($this->manualPublicProfileUsername);

        if (! $username) {
            $this->addError('manualPublicProfileUsername', 'Bitte einen gueltigen Instagram-Namen angeben.');

            return;
        }

        $publicProfile = $trackedPerson->publicProfiles()->firstOrNew([
            'platform' => 'instagram',
            'username' => $username,
        ]);

        $publicProfile->fill([
            'user_id' => Auth::id(),
            'display_name' => null,
            'relationship_type' => $this->publicProfileRelationshipType,
            'profile_url' => 'https://www.instagram.com/'.$username.'/',
            'is_public' => true,
        ]);

        $publicProfile->save();
        app(InstagramProfileRelationshipStore::class)->syncPublicProfile($publicProfile);

        $this->resetPublicProfileForm();
        $this->setDetailStatus('Manuelles Profil wurde gespeichert.', 'success');
        $this->dispatch('tracked-person-refresh');
        $this->dispatchBrowserEvent('toast', ['message' => 'Manuelles Profil wurde gespeichert.', 'type' => 'success']);
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
