<?php

namespace App\Services\Ai;

use App\Jobs\RunTrackedPersonInstagramToolScan;
use App\Models\TrackedPerson;
use App\Services\TrackedPeople\InstagramProfileRelationshipStore;
use App\Services\TrackedPeople\TrackedPersonInstagramScanCoordinator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvestigationAssistantToolService
{
    public function systemPrompt(): string
    {
        return trim(implode("\n", [
            'Du bist der webaiDetective Investigation Copilot.',
            'Sprich Deutsch, kurz, konkret und operativ. Nutze keine Spekulation als Fakt.',
            'Dein Ziel: Profile bewerten, sinnvolle naechste Scans planen, Monitoring steuern und Ergebnisse erklaeren.',
            'Du darfst Tools nutzen, um App-Kontext zu lesen oder erlaubte Aktionen auszufuehren.',
            'Starte kosten-/zeitintensive Scans nur, wenn der Nutzer es klar beauftragt oder bereits zustimmt. Sonst schlage den naechsten Schritt vor.',
            'Empfohlene Scan-Strategie: unbekanntes Profil zuerst Mini-Scan; oeffentliches Profil danach Follower/Gefolgt/Posts; privates Profil danach Suggestions; bei wichtigen Faellen Monitoring aktivieren.',
            'Wenn ein Tool ausgefuehrt wurde, fasse das Ergebnis und den naechsten sinnvollen Schritt zusammen.',
        ]));
    }

    public function tools(): array
    {
        return [
            $this->tool('list_tracked_people', 'Liste die beobachteten Personen des aktuellen Nutzers mit Status und letzten Instagram-Ergebnissen.', [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Optionaler Name oder Instagram-Handle zum Filtern.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                ],
            ]),
            $this->tool('get_profile_context', 'Lade Detailkontext, letzte Snapshots und Scan-Historie zu einer beobachteten Person.', [
                'type' => 'object',
                'properties' => [
                    'tracked_person_id' => ['type' => 'integer'],
                    'instagram_username' => ['type' => 'string'],
                ],
            ]),
            $this->tool('create_or_update_tracked_person', 'Lege eine beobachtete Person an oder aktualisiere Basisdaten und Instagram-Handle.', [
                'type' => 'object',
                'properties' => [
                    'tracked_person_id' => ['type' => 'integer'],
                    'first_name' => ['type' => 'string'],
                    'last_name' => ['type' => 'string'],
                    'alias' => ['type' => 'string'],
                    'instagram_username' => ['type' => 'string'],
                    'notes' => ['type' => 'string'],
                ],
            ]),
            $this->tool('configure_monitoring', 'Aktiviere oder deaktiviere Monitoring fuer eine beobachtete Person.', [
                'type' => 'object',
                'properties' => [
                    'tracked_person_id' => ['type' => 'integer'],
                    'instagram_username' => ['type' => 'string'],
                    'enabled' => ['type' => 'boolean'],
                    'interval_minutes' => ['type' => 'integer', 'minimum' => 15, 'maximum' => 10080],
                    'notify_changes' => ['type' => 'boolean'],
                ],
            ]),
            $this->tool('dispatch_instagram_scan', 'Starte einen Instagram-Scan im Hintergrund.', [
                'type' => 'object',
                'properties' => [
                    'tracked_person_id' => ['type' => 'integer'],
                    'instagram_username' => ['type' => 'string'],
                    'scan_type' => [
                        'type' => 'string',
                        'enum' => ['mini', 'full', 'followers', 'following', 'suggestions', 'posts', 'public_connections'],
                    ],
                    'reason' => ['type' => 'string'],
                ],
            ]),
            $this->tool('stop_active_scan', 'Fordere das Beenden eines laufenden Instagram-Scans fuer eine Person an.', [
                'type' => 'object',
                'properties' => [
                    'tracked_person_id' => ['type' => 'integer'],
                    'instagram_username' => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                ],
            ]),
        ];
    }

    public function execute(string $name, array $arguments, $user): array
    {
        if (! $user) {
            return $this->error('AUTH_REQUIRED', 'Bitte anmelden, damit ich Profile und Scans steuern kann.');
        }

        return match ($name) {
            'list_tracked_people' => $this->listTrackedPeople($user, $arguments),
            'get_profile_context' => $this->getProfileContext($user, $arguments),
            'create_or_update_tracked_person' => $this->createOrUpdateTrackedPerson($user, $arguments),
            'configure_monitoring' => $this->configureMonitoring($user, $arguments),
            'dispatch_instagram_scan' => $this->dispatchInstagramScan($user, $arguments),
            'stop_active_scan' => $this->stopActiveScan($user, $arguments),
            default => $this->error('UNKNOWN_TOOL', 'Unbekanntes Tool: '.$name),
        };
    }

    public function conversationContext($user): array
    {
        if (! $user) {
            return ['authenticated' => false];
        }

        $people = $user->trackedPeople()
            ->with('latestInstagramSnapshot')
            ->orderByRaw('instagram_username IS NULL')
            ->orderByDesc('last_instagram_analyzed_at')
            ->limit(8)
            ->get()
            ->map(fn (TrackedPerson $person): array => $this->trackedPersonSummary($person))
            ->values()
            ->all();

        return [
            'authenticated' => true,
            'user_id' => (int) $user->id,
            'tracked_people_count' => $user->trackedPeople()->count(),
            'recent_tracked_people' => $people,
        ];
    }

    private function listTrackedPeople($user, array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        $limit = max(1, min(20, (int) ($arguments['limit'] ?? 10)));

        $peopleQuery = $user->trackedPeople()->with('latestInstagramSnapshot');

        if ($query !== '') {
            $needle = Str::lower(ltrim($query, '@'));
            $peopleQuery->where(function ($builder) use ($needle) {
                $builder
                    ->whereRaw('LOWER(first_name) like ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(last_name) like ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(alias) like ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(instagram_username) like ?', ['%'.$needle.'%']);
            });
        }

        return [
            'ok' => true,
            'people' => $peopleQuery
                ->orderByRaw('instagram_username IS NULL')
                ->orderByDesc('last_instagram_analyzed_at')
                ->limit($limit)
                ->get()
                ->map(fn (TrackedPerson $person): array => $this->trackedPersonSummary($person))
                ->values()
                ->all(),
        ];
    }

    private function getProfileContext($user, array $arguments): array
    {
        $person = $this->resolveTrackedPerson($user, $arguments);

        if (! $person) {
            return $this->error('PROFILE_NOT_FOUND', 'Ich finde dieses Profil in deinen beobachteten Personen nicht.');
        }

        $person->load([
            'latestInstagramSnapshot',
            'instagramSnapshots' => fn ($query) => $query->latest('analyzed_at')->limit(5),
            'instagramSuggestionScans' => fn ($query) => $query->latest('analyzed_at')->limit(3),
            'instagramPostScans' => fn ($query) => $query->latest('scanned_at')->limit(3),
            'instagramInferredConnections' => fn ($query) => $query->latest('last_seen_at')->limit(10),
        ]);

        return [
            'ok' => true,
            'profile' => $this->trackedPersonSummary($person),
            'recent_snapshots' => $person->instagramSnapshots->map(fn ($snapshot): array => [
                'id' => (int) $snapshot->id,
                'status_level' => $snapshot->status_level,
                'status_message' => $snapshot->status_message,
                'profile_visibility' => $snapshot->profile_visibility,
                'has_changes' => (bool) $snapshot->has_changes,
                'detected_changes' => $snapshot->detected_changes ?? [],
                'analyzed_at' => optional($snapshot->analyzed_at)->toDateTimeString(),
            ])->values()->all(),
            'suggestion_scans' => $person->instagramSuggestionScans->map(fn ($scan): array => [
                'id' => (int) $scan->id,
                'status_level' => $scan->status_level,
                'matches' => (int) $scan->suggestion_matches_count,
                'checked' => (int) $scan->suggestions_checked_count,
                'analyzed_at' => optional($scan->analyzed_at)->toDateTimeString(),
            ])->values()->all(),
            'post_scans' => $person->instagramPostScans->map(fn ($scan): array => [
                'id' => (int) $scan->id,
                'status_level' => $scan->status_level,
                'observed' => (int) $scan->observed_count,
                'new' => (int) $scan->new_count,
                'updated' => (int) $scan->updated_count,
                'scanned_at' => optional($scan->scanned_at)->toDateTimeString(),
            ])->values()->all(),
            'inferred_connections_count' => $person->instagramInferredConnections->count(),
        ];
    }

    private function createOrUpdateTrackedPerson($user, array $arguments): array
    {
        $person = $this->resolveTrackedPerson($user, $arguments);
        $instagramUsername = $this->normalizeUsername($arguments['instagram_username'] ?? null);

        if (! $person && ! $instagramUsername) {
            return $this->error('MISSING_HANDLE', 'Zum Anlegen brauche ich mindestens einen Instagram-Namen.');
        }

        $payload = [
            'first_name' => trim((string) ($arguments['first_name'] ?? '')),
            'last_name' => trim((string) ($arguments['last_name'] ?? '')),
            'alias' => $this->nullableString($arguments['alias'] ?? null),
            'notes' => $this->nullableString($arguments['notes'] ?? null),
            'instagram_username' => $instagramUsername,
        ];

        $payload = array_filter($payload, fn ($value): bool => $value !== null && $value !== '');

        if (! $person) {
            $payload['first_name'] = $payload['first_name'] ?? 'Instagram';
            $payload['last_name'] = $payload['last_name'] ?? '@'.$instagramUsername;
            $payload['is_primary'] = ! $user->trackedPeople()->where('is_primary', true)->exists();

            $person = DB::transaction(fn () => $user->trackedPeople()->create($payload));
        } else {
            $person->update($payload);
        }

        app(InstagramProfileRelationshipStore::class)->syncTrackedPersonProfile($person->fresh());

        return [
            'ok' => true,
            'message' => 'Beobachtete Person wurde gespeichert.',
            'profile' => $this->trackedPersonSummary($person->fresh('latestInstagramSnapshot')),
        ];
    }

    private function configureMonitoring($user, array $arguments): array
    {
        $person = $this->resolveTrackedPerson($user, $arguments);

        if (! $person) {
            return $this->error('PROFILE_NOT_FOUND', 'Ich finde dieses Profil in deinen beobachteten Personen nicht.');
        }

        $enabled = (bool) ($arguments['enabled'] ?? true);
        $interval = max(15, min(10080, (int) ($arguments['interval_minutes'] ?? $person->monitoring_interval_minutes ?: 60)));
        $notifyChanges = array_key_exists('notify_changes', $arguments)
            ? (bool) $arguments['notify_changes']
            : (bool) $person->notify_social_changes;

        $person->update([
            'monitoring_enabled' => $enabled,
            'monitoring_interval_minutes' => $interval,
            'notify_social_changes' => $notifyChanges,
            'notify_instagram_changes' => $notifyChanges,
        ]);

        return [
            'ok' => true,
            'message' => $enabled
                ? 'Monitoring wurde aktiviert.'
                : 'Monitoring wurde deaktiviert.',
            'profile' => $this->trackedPersonSummary($person->fresh('latestInstagramSnapshot')),
        ];
    }

    private function dispatchInstagramScan($user, array $arguments): array
    {
        $person = $this->resolveTrackedPerson($user, $arguments);

        if (! $person) {
            return $this->error('PROFILE_NOT_FOUND', 'Ich finde dieses Profil in deinen beobachteten Personen nicht.');
        }

        if (! $person->instagram_username) {
            return $this->error('MISSING_HANDLE', 'Fuer diese Person ist kein Instagram-Name hinterlegt.');
        }

        $scanType = strtolower(trim((string) ($arguments['scan_type'] ?? 'mini')));

        if (! in_array($scanType, ['mini', 'full', 'followers', 'following', 'suggestions', 'posts', 'public_connections'], true)) {
            return $this->error('INVALID_SCAN_TYPE', 'Unbekannter Scan-Typ: '.$scanType);
        }

        RunTrackedPersonInstagramToolScan::dispatch((int) $person->id, $scanType, false);

        $person->forceFill([
            'last_instagram_status_level' => 'partial',
            'last_instagram_status_message' => 'AI-Assistent hat '.$this->scanTypeLabel($scanType).' in die Warteschlange gestellt.',
        ])->save();

        return [
            'ok' => true,
            'message' => $this->scanTypeLabel($scanType).' wurde im Hintergrund gestartet.',
            'reason' => $arguments['reason'] ?? null,
            'profile' => $this->trackedPersonSummary($person->fresh('latestInstagramSnapshot')),
        ];
    }

    private function stopActiveScan($user, array $arguments): array
    {
        $person = $this->resolveTrackedPerson($user, $arguments);

        if (! $person) {
            return $this->error('PROFILE_NOT_FOUND', 'Ich finde dieses Profil in deinen beobachteten Personen nicht.');
        }

        $requested = app(TrackedPersonInstagramScanCoordinator::class)->requestGracefulStop(
            $person->id,
            $arguments['reason'] ?? 'Scan wurde durch den AI-Assistenten beendet.',
        );

        return [
            'ok' => true,
            'stop_requested' => $requested,
            'message' => $requested
                ? 'Stop wurde angefordert. Der aktuelle Zwischestand wird gespeichert.'
                : 'Fuer diese Person laeuft aktuell kein registrierter Instagram-Scan.',
            'profile' => $this->trackedPersonSummary($person->fresh('latestInstagramSnapshot')),
        ];
    }

    private function resolveTrackedPerson($user, array $arguments): ?TrackedPerson
    {
        $id = (int) ($arguments['tracked_person_id'] ?? 0);

        if ($id > 0) {
            return $user->trackedPeople()->with('latestInstagramSnapshot')->whereKey($id)->first();
        }

        $username = $this->normalizeUsername($arguments['instagram_username'] ?? null);

        if (! $username) {
            return null;
        }

        return $user->trackedPeople()
            ->with('latestInstagramSnapshot')
            ->whereRaw('LOWER(TRIM(LEADING ? FROM instagram_username)) = ?', ['@', $username])
            ->first();
    }

    private function trackedPersonSummary(TrackedPerson $person): array
    {
        $snapshot = $person->latestInstagramSnapshot;

        return [
            'id' => (int) $person->id,
            'display_name' => $person->display_name,
            'instagram_username' => $person->instagram_username,
            'monitoring_enabled' => (bool) $person->monitoring_enabled,
            'monitoring_interval_minutes' => (int) ($person->monitoring_interval_minutes ?: 60),
            'last_status_level' => $person->last_instagram_status_level,
            'last_status_message' => $person->last_instagram_status_message,
            'last_analyzed_at' => optional($person->last_instagram_analyzed_at)->toDateTimeString(),
            'followers_count' => $person->instagram_followers_count,
            'following_count' => $person->instagram_following_count,
            'posts_count' => $person->instagram_posts_count,
            'profile_visibility' => $snapshot?->profile_visibility ?? 'unknown',
            'latest_snapshot_id' => $snapshot?->id,
            'latest_snapshot_has_changes' => (bool) ($snapshot?->has_changes ?? false),
        ];
    }

    private function tool(string $name, string $description, array $parameters): array
    {
        $parameters['additionalProperties'] = false;

        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ],
        ];
    }

    private function error(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    private function normalizeUsername(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $username = Str::lower(trim((string) $value));
        $username = preg_replace('/^https?:\/\/(www\.)?instagram\.com\//i', '', $username) ?? $username;
        $username = trim(ltrim($username, '@'), "/ \t\n\r\0\x0B");
        $username = preg_replace('/[?#].*$/', '', $username) ?? $username;

        return $username !== '' ? $username : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function scanTypeLabel(string $scanType): string
    {
        return match ($scanType) {
            'mini' => 'Instagram-Mini-Scan',
            'full' => 'Instagram-Vollanalyse',
            'followers' => 'Followerlisten-Scan',
            'following' => 'Gefolgt-Listen-Scan',
            'suggestions' => 'Vorschlag-Scan',
            'posts' => 'Beitragsscan',
            'public_connections' => 'Public-Profile-Verbindungsscan',
            default => 'Instagram-Scan',
        };
    }
}
