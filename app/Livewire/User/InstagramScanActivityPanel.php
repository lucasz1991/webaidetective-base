<?php

namespace App\Livewire\User;

use App\Models\InstagramPostScan;
use App\Models\InstagramProfile;
use App\Models\InstagramProfileListScan;
use App\Models\InstagramProfileScan;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramPublicProfileScan;
use App\Models\TrackedPersonInstagramSuggestionScan;
use App\Services\TrackedPeople\TrackedPersonInstagramScanCoordinator;
use App\Services\TrackedPeople\TrackedPersonScanDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class InstagramScanActivityPanel extends Component
{
    public ?int $trackedPersonId = null;

    public ?int $instagramProfileId = null;

    public ?string $statusMessage = null;

    public string $statusLevel = 'neutral';

    public function mount(?int $trackedPersonId = null, ?int $instagramProfileId = null): void
    {
        $this->trackedPersonId = $trackedPersonId;
        $this->instagramProfileId = $instagramProfileId;
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="animate-pulse space-y-3">
                <div class="h-4 w-40 rounded bg-slate-200"></div>
                <div class="h-16 rounded-2xl bg-slate-100"></div>
            </div>
        </section>
        HTML;
    }

    public function requestStop(): void
    {
        $trackedPerson = $this->resolvedTrackedPerson();

        if (! $trackedPerson) {
            $this->setStatus('Fuer dieses Profil gibt es keinen stoppbaren beobachteten Scan.', 'partial');

            return;
        }

        $requested = app(TrackedPersonInstagramScanCoordinator::class)->requestGracefulStop(
            $trackedPerson->id,
            'Instagram-Scan wurde in der Oberflaeche beendet.',
        );

        $this->setStatus(
            $requested
                ? 'Stop wurde angefordert. Der aktuelle Zwischestand wird gespeichert.'
                : 'Fuer diese Person laeuft aktuell kein Instagram-Scan.',
            $requested ? 'partial' : 'neutral',
        );
    }

    public function cancelUnresponsive(): void
    {
        $trackedPerson = $this->resolvedTrackedPerson();

        if (! $trackedPerson) {
            $this->setStatus('Fuer dieses Profil gibt es keinen abbrechbaren beobachteten Scan.', 'partial');

            return;
        }

        app(TrackedPersonInstagramScanCoordinator::class)->cancelActive(
            $trackedPerson->id,
            'Instagram-Scan wurde in der Oberflaeche hart abgebrochen.',
        );

        $trackedPerson->markInstagramScanTerminal(
            'cancelled',
            'Instagram-Scan wurde abgebrochen. Bereits gespeicherte Zwischendaten bleiben erhalten.',
        );

        $this->setStatus('Scan wurde abgebrochen. Bereits gespeicherte Daten bleiben erhalten.', 'partial');
        $this->dispatch('tracked-person-refresh');
    }

    public function resumeSavedInstagramScan(string $scanType): void
    {
        if (! in_array($scanType, ['followers', 'following', 'suggestions', 'suggestion_deepsearch', 'public_connections'], true)) {
            $this->setStatus('Dieser Scan-Typ kann nicht fortgesetzt werden.', 'error');

            return;
        }

        $trackedPerson = $this->resolvedTrackedPerson();

        if (! $trackedPerson) {
            $this->setStatus('Zum Fortsetzen muss das Instagram-Profil als beobachtete Person verknuepft sein.', 'error');

            return;
        }

        app(TrackedPersonScanDispatcher::class)->dispatch((int) $trackedPerson->id, $scanType);

        $this->setStatus('Scan wird ab dem gespeicherten Datenstand fortgesetzt.', 'partial');
        $this->dispatch('tracked-person-refresh');
    }

    public function dismissSavedInstagramScan(string $source, int $scanId): void
    {
        $trackedPerson = $this->resolvedTrackedPerson();

        if (! $trackedPerson) {
            return;
        }

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

        $this->setStatus('Scan wurde beendet. Alle bisher erfassten Daten bleiben gespeichert.', 'partial');
        $this->dispatch('tracked-person-refresh');
    }

    public function render()
    {
        $trackedPerson = $this->resolvedTrackedPerson();
        $profile = $this->resolvedInstagramProfile();
        $activeScan = $trackedPerson ? $this->activeScan($trackedPerson) : null;
        $queuedScans = $trackedPerson ? $this->queuedScans($trackedPerson) : collect();
        $resumableScan = $trackedPerson ? $this->resumableInstagramScan($trackedPerson) : null;
        $plannedScan = $trackedPerson ? $this->plannedScan($trackedPerson) : null;
        $recentScans = $this->recentScans($trackedPerson, $profile);
        $shouldPoll = (bool) $activeScan || $queuedScans->isNotEmpty();

        return view('livewire.user.instagram-scan-activity-panel', [
            'trackedPerson' => $trackedPerson,
            'profile' => $profile,
            'activeScan' => $activeScan,
            'queuedScans' => $queuedScans,
            'resumableScan' => $resumableScan,
            'plannedScan' => $plannedScan,
            'recentScans' => $recentScans,
            'shouldPoll' => $shouldPoll,
            'statusClass' => $this->statusClass($this->statusLevel),
        ]);
    }

    private function resolvedTrackedPerson(): ?TrackedPerson
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        if ($this->trackedPersonId) {
            return $user->trackedPeople()
                ->whereKey($this->trackedPersonId)
                ->with('latestInstagramSnapshot')
                ->first();
        }

        $profile = $this->resolvedInstagramProfile();

        if (! $profile) {
            return null;
        }

        return $user->trackedPeople()
            ->where(function ($query) use ($profile): void {
                $query->where('current_instagram_profile_id', $profile->id)
                    ->orWhereRaw(
                        "LOWER(TRIM(LEADING '@' FROM instagram_username)) = ?",
                        [$profile->username],
                    );
            })
            ->with('latestInstagramSnapshot')
            ->first();
    }

    private function resolvedInstagramProfile(): ?InstagramProfile
    {
        if (! $this->instagramProfileId) {
            return $this->trackedPersonId
                ? $this->resolvedTrackedPerson()?->currentInstagramProfile
                : null;
        }

        $userId = (int) Auth::id();

        return InstagramProfile::query()
            ->whereKey($this->instagramProfileId)
            ->where(function ($query) use ($userId): void {
                $query
                    ->whereHas('trackedPersonLinks', fn ($links) => $links->where('user_id', $userId))
                    ->orWhereHas('publicProfileLinks', fn ($links) => $links->where('user_id', $userId))
                    ->orWhereHas('listScans', fn ($scans) => $scans->where('user_id', $userId))
                    ->orWhereHas('profileScans', fn ($scans) => $scans->where('user_id', $userId))
                    ->orWhereHas('postScans', fn ($scans) => $scans->where('user_id', $userId))
                    ->orWhereHas('suggestionScans', fn ($scans) => $scans->where('user_id', $userId));
            })
            ->first();
    }

    private function activeScan(TrackedPerson $trackedPerson): ?array
    {
        $coordinator = app(TrackedPersonInstagramScanCoordinator::class);
        $active = $coordinator->activeState((int) $trackedPerson->id);

        if (! $coordinator->hasActiveScan((int) $trackedPerson->id)) {
            return null;
        }

        $updatedAt = $this->parseDate($active['lastProcessOutputAt'] ?? $active['updatedAt'] ?? null);

        return [
            'label' => (string) ($active['label'] ?? 'Instagram-Scan'),
            'generation' => (int) ($active['generation'] ?? 0),
            'started_at' => $this->parseDate($active['startedAt'] ?? null),
            'updated_at' => $updatedAt,
            'is_responsive' => $coordinator->isResponsive((int) $trackedPerson->id),
            'graceful_stop_requested' => (bool) ($active['gracefulStopRequested'] ?? false),
            'processes' => collect($active['processes'] ?? [])->filter(fn ($process) => is_array($process))->values(),
        ];
    }

    private function queuedScans(TrackedPerson $trackedPerson): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('jobs')) {
            return collect();
        }

        return DB::table('jobs')
            ->where('payload', 'like', '%RunTrackedPersonInstagramToolScan%')
            ->where('payload', 'like', '%trackedPersonId%')
            ->where('payload', 'like', '%'.$trackedPerson->id.'%')
            ->orderBy('available_at')
            ->limit(5)
            ->get()
            ->map(fn ($job) => [
                'id' => (int) $job->id,
                'queue' => (string) $job->queue,
                'attempts' => (int) $job->attempts,
                'available_at' => Carbon::createFromTimestamp((int) $job->available_at),
            ]);
    }

    private function plannedScan(TrackedPerson $trackedPerson): ?array
    {
        if (! $trackedPerson->monitoring_enabled || ! $trackedPerson->last_instagram_analyzed_at) {
            return null;
        }

        $intervalMinutes = max(1, (int) ($trackedPerson->monitoring_interval_minutes ?: 60));
        $nextScanAt = $trackedPerson->last_instagram_analyzed_at->copy()->addMinutes($intervalMinutes);

        return [
            'label' => 'Dauerbeobachtung',
            'next_scan_at' => $nextScanAt,
            'interval_minutes' => $intervalMinutes,
            'is_due' => $nextScanAt->isPast(),
        ];
    }

    private function recentScans(?TrackedPerson $trackedPerson, ?InstagramProfile $profile): \Illuminate\Support\Collection
    {
        $rows = collect();
        $userId = (int) Auth::id();

        if ($trackedPerson) {
            $trackedPerson->instagramSnapshots()
                ->latest('analyzed_at')
                ->limit(3)
                ->get()
                ->each(fn ($scan) => $rows->push($this->scanRow('Profilscan', $scan->status_level, $scan->status_message, $scan->analyzed_at)));
        }

        $profileId = $profile?->id ?: $trackedPerson?->current_instagram_profile_id;

        if ($profileId) {
            InstagramProfileScan::query()
                ->where('user_id', $userId)
                ->where('instagram_profile_id', $profileId)
                ->latest('scanned_at')
                ->limit(3)
                ->get()
                ->each(fn ($scan) => $rows->push($this->scanRow('Profilscan', $scan->status_level, $scan->status_message, $scan->scanned_at)));

            InstagramProfileListScan::query()
                ->where('user_id', $userId)
                ->where('instagram_profile_id', $profileId)
                ->latest('scanned_at')
                ->limit(3)
                ->get()
                ->each(fn ($scan) => $rows->push($this->scanRow(
                    $scan->list_type === 'followers' ? 'Followerliste' : 'Gefolgt-Liste',
                    $scan->status_level,
                    $scan->status_message,
                    $scan->scanned_at,
                )));

            InstagramPostScan::query()
                ->where('user_id', $userId)
                ->where('instagram_profile_id', $profileId)
                ->latest('scanned_at')
                ->limit(3)
                ->get()
                ->each(fn ($scan) => $rows->push($this->scanRow('Beitragsscan', $scan->status_level, $scan->status_message, $scan->scanned_at)));

            TrackedPersonInstagramSuggestionScan::query()
                ->where('user_id', $userId)
                ->where('instagram_profile_id', $profileId)
                ->latest('analyzed_at')
                ->limit(3)
                ->get()
                ->each(fn ($scan) => $rows->push($this->scanRow('Vorschlaege', $scan->status_level, $scan->status_message, $scan->analyzed_at)));
        }

        return $rows
            ->filter(fn (array $row) => $row['date'])
            ->sortByDesc(fn (array $row) => $row['date']->getTimestamp())
            ->take(5)
            ->values();
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
            && ($latestListScan->gracefully_stopped || $latestListScan->rate_limited || ! $latestListScan->complete)
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

    private function scanRow(string $label, ?string $level, ?string $message, mixed $date): array
    {
        return [
            'label' => $label,
            'level' => $level ?: 'unknown',
            'message' => $message,
            'date' => $date,
            'class' => $this->badgeClass($level),
        ];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->timezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function setStatus(string $message, string $level): void
    {
        $this->statusMessage = $message;
        $this->statusLevel = $level;
    }

    private function statusClass(?string $level): string
    {
        return match ($level ?? 'neutral') {
            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
            default => 'border-slate-200 bg-slate-50 text-slate-800',
        };
    }

    private function badgeClass(?string $level): string
    {
        return match ($level ?? 'unknown') {
            'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'partial' => 'bg-amber-50 text-amber-800 ring-amber-200',
            'error', 'failed' => 'bg-rose-50 text-rose-700 ring-rose-200',
            'cancelled' => 'bg-slate-100 text-slate-700 ring-slate-200',
            default => 'bg-slate-50 text-slate-600 ring-slate-200',
        };
    }
}
