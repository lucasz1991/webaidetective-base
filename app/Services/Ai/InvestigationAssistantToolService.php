<?php

namespace App\Services\Ai;

use App\Jobs\RunTrackedPersonInstagramToolScan;
use App\Jobs\ScanInstagramProfileJob;
use App\Livewire\User\NetworkMap;
use App\Models\InstagramProfile;
use App\Models\InstagramProfileListScanItem;
use App\Models\InstagramProfileRelationship;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonPublicProfile;
use App\Services\TrackedPeople\InstagramProfileRelationshipStore;
use App\Services\TrackedPeople\TrackedPersonInstagramScanCoordinator;
use App\Services\TrackedPeople\TrackedPersonQuotaService;
use Illuminate\Support\Carbon;
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
            'Nutze Netzwerkdaten, Knotenanzahl, oeffentliche Kennzahlen und Scan-Alter, bevor du naechste Profile priorisierst.',
            'Speichere neue Kontaktkandidaten nur, wenn der Nutzer das beauftragt oder eine Datei/Nachricht eindeutig Kontakte zur Speicherung enthaelt.',
            'Wenn ein Tool ausgefuehrt wurde, fasse das Ergebnis und den naechsten sinnvollen Schritt zusammen.',
            'Nutze fuer Profilreferenzen immer den Instagram-Handle im Format @username, damit die Oberflaeche ein Profil-Badge anzeigen kann.',
            'Navigiere nur, wenn der Nutzer dich ausdruecklich darum bittet. Nutze dafuer navigate_app_page oder open_profile.',
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
            $this->tool('list_network_scan_candidates', 'Analysiere den gespeicherten Instagram-Graphen und liefere priorisierte Kandidaten fuer naechste Scans.', [
                'type' => 'object',
                'properties' => [
                    'tracked_person_id' => ['type' => 'integer', 'description' => 'Optional: Fokusperson fuer den Graphen.'],
                    'instagram_username' => ['type' => 'string', 'description' => 'Optional: Instagram-Handle der Fokusperson.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25],
                    'include_known_profiles' => ['type' => 'boolean'],
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
            $this->tool('save_contact_candidate', 'Speichere einen moeglichen Kontakt als bekannte oeffentliche Verbindung oder als beobachtete Person.', [
                'type' => 'object',
                'properties' => [
                    'source_tracked_person_id' => ['type' => 'integer', 'description' => 'Person, zu deren Netzwerk der Kontakt gehoert. Wenn leer, wird die Hauptperson genutzt.'],
                    'source_instagram_username' => ['type' => 'string'],
                    'candidate_instagram_username' => ['type' => 'string'],
                    'display_name' => ['type' => 'string'],
                    'relationship_type' => [
                        'type' => 'string',
                        'enum' => ['public_connection', 'follows_target', 'followed_by_target', 'mutual'],
                    ],
                    'notes' => ['type' => 'string'],
                    'promote_to_tracked_person' => ['type' => 'boolean'],
                    'scan_now' => ['type' => 'boolean'],
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
                        'enum' => ['mini', 'full', 'followers', 'following', 'suggestions', 'suggestion_deepsearch', 'posts', 'public_connections'],
                    ],
                    'reason' => ['type' => 'string'],
                ],
            ]),
            $this->tool('dispatch_network_profile_scan', 'Starte eine Profil-Vollanalyse fuer einen Kontakt/Kandidaten aus dem Instagram-Graphen.', [
                'type' => 'object',
                'properties' => [
                    'source_tracked_person_id' => ['type' => 'integer'],
                    'source_instagram_username' => ['type' => 'string'],
                    'candidate_instagram_username' => ['type' => 'string'],
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
            $this->tool('navigate_app_page', 'Navigiere auf ausdruecklichen Wunsch des Nutzers zu einer bekannten Seite der Anwendung.', [
                'type' => 'object',
                'properties' => [
                    'page' => [
                        'type' => 'string',
                        'enum' => ['dashboard', 'network', 'messages', 'howto', 'faqs', 'packages'],
                    ],
                ],
                'required' => ['page'],
            ]),
            $this->tool('open_profile', 'Oeffne auf ausdruecklichen Wunsch ein fuer den Nutzer zugaengliches Profil in der Anwendung.', [
                'type' => 'object',
                'properties' => [
                    'tracked_person_id' => ['type' => 'integer'],
                    'instagram_profile_id' => ['type' => 'integer'],
                    'instagram_username' => ['type' => 'string'],
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
            'list_network_scan_candidates' => $this->listNetworkScanCandidates($user, $arguments),
            'create_or_update_tracked_person' => $this->createOrUpdateTrackedPerson($user, $arguments),
            'save_contact_candidate' => $this->saveContactCandidate($user, $arguments),
            'configure_monitoring' => $this->configureMonitoring($user, $arguments),
            'dispatch_instagram_scan' => $this->dispatchInstagramScan($user, $arguments),
            'dispatch_network_profile_scan' => $this->dispatchNetworkProfileScan($user, $arguments),
            'stop_active_scan' => $this->stopActiveScan($user, $arguments),
            'navigate_app_page' => $this->navigateAppPage($arguments),
            'open_profile' => $this->openProfile($user, $arguments),
            default => $this->error('UNKNOWN_TOOL', 'Unbekanntes Tool: '.$name),
        };
    }

    public function profileReferences($user, string $text): array
    {
        if (! $user || ! preg_match_all('/(?<![\w.])@([a-zA-Z0-9._]{1,30})/', $text, $matches)) {
            return [];
        }

        return collect($matches[1] ?? [])
            ->map(fn (string $username): ?string => $this->normalizeUsername($username))
            ->filter()
            ->unique()
            ->take(8)
            ->map(function (string $username) use ($user): ?array {
                $trackedPerson = $this->resolveTrackedPerson($user, ['instagram_username' => $username]);

                if ($trackedPerson) {
                    $trackedPerson->loadMissing(['currentInstagramProfile', 'latestInstagramSnapshot']);
                    $instagramProfile = $trackedPerson->currentInstagramProfile;

                    return [
                        'type' => 'tracked_person',
                        'id' => (int) $trackedPerson->id,
                        'username' => $username,
                        'display_name' => $trackedPerson->display_name,
                        'image_url' => $trackedPerson->profile_image_url
                            ?: $instagramProfile?->profile_image_storage_url
                            ?: $trackedPerson->latestInstagramSnapshot?->profile_image_storage_url,
                        'url' => route('tracked-people.show', $trackedPerson->id),
                    ];
                }

                $instagramProfile = $this->accessibleInstagramProfile($user, username: $username);

                if (! $instagramProfile) {
                    return null;
                }

                return [
                    'type' => 'instagram_profile',
                    'id' => (int) $instagramProfile->id,
                    'username' => $username,
                    'display_name' => $instagramProfile->display_name ?: $instagramProfile->full_name ?: '@'.$username,
                    'image_url' => $instagramProfile->profile_image_storage_url,
                    'url' => route('instagram-profiles.show', $instagramProfile->id),
                ];
            })
            ->filter()
            ->values()
            ->all();
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

    public function contextualPageContext($user, array $uiContext): array
    {
        $context = [
            ...$this->conversationContext($user),
            'page' => [
                'route_name' => $uiContext['route_name'] ?? null,
                'path' => $uiContext['path'] ?? null,
                'title' => $uiContext['page_title'] ?? null,
            ],
            'network_map' => [
                'open' => (bool) ($uiContext['network_map_open'] ?? false),
                'fullscreen' => (bool) ($uiContext['network_map_fullscreen'] ?? false),
                'map_id' => $uiContext['network_map_id'] ?? null,
            ],
            'selected_item' => [
                'node_id' => $uiContext['selected_node_id'] ?? null,
                'node_type' => $uiContext['selected_node_type'] ?? null,
                'name' => $uiContext['selected_profile_name'] ?? null,
                'username' => $this->normalizeUsername($uiContext['selected_profile_username'] ?? null),
                'profile_preview_open' => (bool) ($uiContext['selected_profile_open'] ?? false),
            ],
        ];

        if (! $user) {
            return $context;
        }

        $trackedPersonId = (int) (
            $uiContext['tracked_person_id']
            ?? $uiContext['network_focus_tracked_person_id']
            ?? 0
        );
        $selectedUsername = $this->normalizeUsername($uiContext['selected_profile_username'] ?? null);

        if ($trackedPersonId > 0) {
            $profileContext = $this->getProfileContext($user, ['tracked_person_id' => $trackedPersonId]);

            if ($profileContext['ok'] ?? false) {
                $context['current_profile'] = $profileContext;
            }
        } elseif ($selectedUsername) {
            $trackedPerson = $this->resolveTrackedPerson($user, ['instagram_username' => $selectedUsername]);

            if ($trackedPerson) {
                $context['current_profile'] = $this->getProfileContext($user, [
                    'tracked_person_id' => $trackedPerson->id,
                ]);
            }
        }

        $instagramProfileId = (int) ($uiContext['instagram_profile_id'] ?? 0);
        $instagramProfile = $this->accessibleInstagramProfile($user, $instagramProfileId, $selectedUsername);

        if ($instagramProfile) {
            $context['current_instagram_profile'] = $this->instagramProfileContext($user, $instagramProfile);
        }

        return $context;
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
            'network' => $this->profileNetworkSummary($user, $person),
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

    private function listNetworkScanCandidates($user, array $arguments): array
    {
        $limit = max(1, min(25, (int) ($arguments['limit'] ?? 10)));
        $includeKnownProfiles = (bool) ($arguments['include_known_profiles'] ?? false);
        $focusPerson = $this->resolveTrackedPerson($user, $arguments);
        $knownProfileIds = $this->knownInstagramProfileIdsForUser($user);
        $candidateIds = $this->candidateInstagramProfileIdsForUser($user, $focusPerson);

        if ($candidateIds === []) {
            return [
                'ok' => true,
                'message' => 'Es sind noch keine auswertbaren Profilverbindungen im Graphen gespeichert.',
                'candidates' => [],
            ];
        }

        $trackedUsernames = $user->trackedPeople()
            ->whereNotNull('instagram_username')
            ->pluck('instagram_username')
            ->map(fn ($username) => $this->normalizeUsername($username))
            ->filter()
            ->values()
            ->all();

        $candidates = InstagramProfile::query()
            ->whereIn('id', $candidateIds)
            ->when(! $includeKnownProfiles, fn ($query) => $query->whereNotIn('id', $knownProfileIds))
            ->whereNotIn('username', $trackedUsernames)
            ->limit(250)
            ->get()
            ->map(fn (InstagramProfile $profile): array => $this->networkCandidateSummary($profile, $focusPerson))
            ->sortByDesc('priority_score')
            ->take($limit)
            ->values()
            ->all();

        return [
            'ok' => true,
            'focus_profile' => $focusPerson ? $this->trackedPersonSummary($focusPerson) : null,
            'scoring' => [
                'degree' => 'Mehr direkte gespeicherte Beziehungen erhoehen Prioritaet.',
                'public_counts' => 'Follower/Gefolgt/Posts geben grobe Reichweite und Datenmenge.',
                'scan_age' => 'Nie oder lange nicht gescannte Profile werden hoeher gewichtet.',
                'visibility' => 'Oeffentliche Profile sind fuer Listen-/Post-Scans wertvoller; private Profile eher fuer Suggestions.',
            ],
            'candidates' => $candidates,
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
            app(TrackedPersonQuotaService::class)->assertCanCreate($user);
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

    private function saveContactCandidate($user, array $arguments): array
    {
        $candidateUsername = $this->normalizeUsername($arguments['candidate_instagram_username'] ?? null);

        if (! $candidateUsername) {
            return $this->error('MISSING_CANDIDATE_HANDLE', 'Zum Speichern brauche ich den Instagram-Namen des Kontakts.');
        }

        $sourcePerson = $this->resolveSourceTrackedPerson($user, $arguments);
        $displayName = $this->nullableString($arguments['display_name'] ?? null);
        $relationshipType = $this->validRelationshipType((string) ($arguments['relationship_type'] ?? 'public_connection'));
        $notes = $this->nullableString($arguments['notes'] ?? null);
        $promoteToTrackedPerson = (bool) ($arguments['promote_to_tracked_person'] ?? false);
        $scanNow = (bool) ($arguments['scan_now'] ?? false);

        $profile = app(InstagramProfileRelationshipStore::class)->ensureProfile($candidateUsername, [
            'display_name' => $displayName,
            'profile_url' => 'https://www.instagram.com/'.$candidateUsername.'/',
        ]);

        if (! $profile) {
            return $this->error('PROFILE_STORE_UNAVAILABLE', 'Das Instagram-Profil konnte nicht im Graphen gespeichert werden.');
        }

        if ($promoteToTrackedPerson) {
            $trackedPerson = $this->findTrackedPersonByProfile($user, $profile)
                ?: $this->createTrackedPersonFromInstagramProfile($user, $profile, $displayName, $notes);

            app(InstagramProfileRelationshipStore::class)->syncTrackedPersonProfile($trackedPerson);
            $scanTracking = null;

            if ($scanNow) {
                $scanTracking = $this->queueTrackedPersonScan($user, $trackedPerson, 'full');
            }

            NetworkMap::forgetGraphCacheForUser((int) $user->id);
            NetworkMap::forgetGraphCacheForUser((int) $user->id, (int) $trackedPerson->id);

            return [
                'ok' => true,
                'message' => $scanNow
                    ? 'Kontakt wurde als beobachtete Person gespeichert und eine Instagram-Vollanalyse wurde gestartet.'
                    : 'Kontakt wurde als beobachtete Person gespeichert.',
                'profile' => $this->trackedPersonSummary($trackedPerson->fresh('latestInstagramSnapshot')),
                'scan_tracking' => $scanTracking,
            ];
        }

        if (! $sourcePerson) {
            return $this->error('SOURCE_PROFILE_REQUIRED', 'Zum Speichern als Kontakt brauche ich eine Fokusperson.');
        }

        $publicProfile = $sourcePerson->publicProfiles()->updateOrCreate(
            [
                'platform' => 'instagram',
                'username' => $candidateUsername,
            ],
            [
                'user_id' => $user->id,
                'instagram_profile_id' => $profile->id,
                'display_name' => $displayName ?: $profile->display_name ?: $profile->full_name,
                'relationship_type' => $relationshipType,
                'profile_url' => 'https://www.instagram.com/'.$candidateUsername.'/',
                'is_public' => $profile->profile_visibility === 'public' || $profile->is_private === false,
                'notes' => $notes,
            ],
        );

        app(InstagramProfileRelationshipStore::class)->syncPublicProfile($publicProfile);

        $scanTracking = $scanNow
            ? $this->queueNetworkProfileScan($user, $sourcePerson, $profile)
            : null;

        NetworkMap::forgetGraphCacheForUser((int) $user->id);
        NetworkMap::forgetGraphCacheForUser((int) $user->id, (int) $sourcePerson->id);

        return [
            'ok' => true,
            'message' => $scanNow
                ? 'Kontakt wurde gespeichert und eine Profil-Vollanalyse wurde gestartet.'
                : 'Kontakt wurde als bekannte oeffentliche Verbindung gespeichert.',
            'contact' => [
                'id' => (int) $publicProfile->id,
                'tracked_person_id' => (int) $sourcePerson->id,
                'instagram_profile_id' => (int) $profile->id,
                'username' => $candidateUsername,
                'display_name' => $publicProfile->display_name,
                'relationship_type' => $publicProfile->relationship_type,
            ],
            'scan_tracking' => $scanTracking,
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

        if (! in_array($scanType, ['mini', 'full', 'followers', 'following', 'suggestions', 'suggestion_deepsearch', 'posts', 'public_connections'], true)) {
            return $this->error('INVALID_SCAN_TYPE', 'Unbekannter Scan-Typ: '.$scanType);
        }

        $scanTracking = $this->queueTrackedPersonScan($user, $person, $scanType);

        $person->forceFill([
            'last_instagram_status_level' => 'partial',
            'last_instagram_status_message' => 'AI-Assistent hat '.$this->scanTypeLabel($scanType).' in die Warteschlange gestellt.',
        ])->save();

        return [
            'ok' => true,
            'message' => $this->scanTypeLabel($scanType).' wurde im Hintergrund gestartet.',
            'reason' => $arguments['reason'] ?? null,
            'profile' => $this->trackedPersonSummary($person->fresh('latestInstagramSnapshot')),
            'scan_tracking' => $scanTracking,
        ];
    }

    private function dispatchNetworkProfileScan($user, array $arguments): array
    {
        $candidateUsername = $this->normalizeUsername($arguments['candidate_instagram_username'] ?? null);

        if (! $candidateUsername) {
            return $this->error('MISSING_CANDIDATE_HANDLE', 'Zum Scannen brauche ich den Instagram-Namen des Kontaktprofils.');
        }

        $sourcePerson = $this->resolveSourceTrackedPerson($user, $arguments);

        if (! $sourcePerson) {
            return $this->error('SOURCE_PROFILE_REQUIRED', 'Zum Scannen eines Netzwerkkontakts brauche ich eine Fokusperson.');
        }

        $profile = app(InstagramProfileRelationshipStore::class)->ensureProfile($candidateUsername);

        if (! $profile) {
            return $this->error('PROFILE_STORE_UNAVAILABLE', 'Das Instagram-Profil konnte nicht im Graphen vorbereitet werden.');
        }

        $scanTracking = $this->queueNetworkProfileScan($user, $sourcePerson, $profile);

        return [
            'ok' => true,
            'message' => 'Profil-Vollanalyse fuer @'.$candidateUsername.' wurde im Hintergrund gestartet.',
            'reason' => $arguments['reason'] ?? null,
            'source_profile' => $this->trackedPersonSummary($sourcePerson->fresh('latestInstagramSnapshot')),
            'candidate' => $this->networkCandidateSummary($profile->fresh() ?: $profile, $sourcePerson),
            'scan_tracking' => $scanTracking,
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

    private function navigateAppPage(array $arguments): array
    {
        $page = (string) ($arguments['page'] ?? '');
        $routes = [
            'dashboard' => 'dashboard',
            'network' => 'network',
            'messages' => 'messages',
            'howto' => 'howto',
            'faqs' => 'faqs',
            'packages' => 'packages',
        ];

        if (! isset($routes[$page])) {
            return $this->error('INVALID_PAGE', 'Diese Seite kann ich nicht oeffnen.');
        }

        return [
            'ok' => true,
            'message' => 'Navigation wird ausgefuehrt.',
            'ui_action' => [
                'type' => 'navigate',
                'url' => route($routes[$page]),
            ],
        ];
    }

    private function openProfile($user, array $arguments): array
    {
        $trackedPerson = $this->resolveTrackedPerson($user, $arguments);

        if ($trackedPerson) {
            return [
                'ok' => true,
                'message' => 'Profil @'.($trackedPerson->instagram_username ?: $trackedPerson->display_name).' wird geoeffnet.',
                'ui_action' => [
                    'type' => 'navigate',
                    'url' => route('tracked-people.show', $trackedPerson->id),
                ],
            ];
        }

        $instagramProfile = $this->accessibleInstagramProfile(
            $user,
            (int) ($arguments['instagram_profile_id'] ?? 0),
            $this->normalizeUsername($arguments['instagram_username'] ?? null),
        );

        if (! $instagramProfile) {
            return $this->error('PROFILE_NOT_FOUND', 'Ich finde kein zugaengliches Profil mit diesen Angaben.');
        }

        return [
            'ok' => true,
            'message' => 'Profil @'.$instagramProfile->username.' wird geoeffnet.',
            'ui_action' => [
                'type' => 'navigate',
                'url' => route('instagram-profiles.show', $instagramProfile->id),
            ],
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

    private function profileNetworkSummary($user, TrackedPerson $person): array
    {
        $profile = $person->currentInstagramProfile
            ?: app(InstagramProfileRelationshipStore::class)->syncTrackedPersonProfile($person);

        if (! $profile) {
            return [
                'has_graph_profile' => false,
                'active_relationships' => 0,
                'scan_candidates' => [],
            ];
        }

        $activeSourceCount = InstagramProfileRelationship::query()
            ->where('source_instagram_profile_id', $profile->id)
            ->where('status', 'active')
            ->whereNull('removed_at')
            ->count();
        $activeRelatedCount = InstagramProfileRelationship::query()
            ->where('related_instagram_profile_id', $profile->id)
            ->where('status', 'active')
            ->whereNull('removed_at')
            ->count();

        $candidateIds = $this->candidateInstagramProfileIdsForUser($user, $person);
        $knownProfileIds = $this->knownInstagramProfileIdsForUser($user);

        $candidates = InstagramProfile::query()
            ->whereIn('id', $candidateIds)
            ->whereNotIn('id', $knownProfileIds)
            ->limit(80)
            ->get()
            ->map(fn (InstagramProfile $candidate): array => $this->networkCandidateSummary($candidate, $person))
            ->sortByDesc('priority_score')
            ->take(8)
            ->values()
            ->all();

        return [
            'has_graph_profile' => true,
            'instagram_profile_id' => (int) $profile->id,
            'active_outgoing_relationships' => $activeSourceCount,
            'active_incoming_relationships' => $activeRelatedCount,
            'active_relationships' => $activeSourceCount + $activeRelatedCount,
            'scan_candidates' => $candidates,
        ];
    }

    private function instagramProfileContext($user, InstagramProfile $profile): array
    {
        $userId = (int) $user->id;
        $outgoingQuery = $profile->sourceRelationships()
            ->where('status', 'active')
            ->whereNull('removed_at')
            ->whereHas('scanItems.listScan', fn ($scans) => $scans->where('user_id', $userId));
        $incomingQuery = $profile->relatedRelationships()
            ->where('status', 'active')
            ->whereNull('removed_at')
            ->whereHas('scanItems.listScan', fn ($scans) => $scans->where('user_id', $userId));

        $outgoing = (clone $outgoingQuery)
            ->with('relatedInstagramProfile')
            ->latest('last_seen_at')
            ->limit(20)
            ->get()
            ->map(fn (InstagramProfileRelationship $relationship): array => [
                'direction' => 'outgoing',
                'list_type' => $relationship->list_type,
                'last_seen_at' => optional($relationship->last_seen_at)->toDateTimeString(),
                'profile' => $this->compactInstagramProfileSummary($relationship->relatedInstagramProfile),
            ])
            ->values()
            ->all();
        $incoming = (clone $incomingQuery)
            ->with('sourceInstagramProfile')
            ->latest('last_seen_at')
            ->limit(20)
            ->get()
            ->map(fn (InstagramProfileRelationship $relationship): array => [
                'direction' => 'incoming',
                'list_type' => $relationship->list_type,
                'last_seen_at' => optional($relationship->last_seen_at)->toDateTimeString(),
                'profile' => $this->compactInstagramProfileSummary($relationship->sourceInstagramProfile),
            ])
            ->values()
            ->all();

        return [
            'profile' => [
                ...$this->compactInstagramProfileSummary($profile),
                'biography' => $profile->biography,
                'profile_url' => $profile->profile_url ?: 'https://www.instagram.com/'.$profile->username.'/',
                'last_status_level' => $profile->last_status_level,
                'last_status_message' => $profile->last_status_message,
                'last_scanned_at' => optional($profile->last_scanned_at)->toDateTimeString(),
            ],
            'network' => [
                'active_outgoing_relationships' => (clone $outgoingQuery)->count(),
                'active_incoming_relationships' => (clone $incomingQuery)->count(),
                'recent_outgoing_profiles' => $outgoing,
                'recent_incoming_profiles' => $incoming,
            ],
        ];
    }

    private function compactInstagramProfileSummary(?InstagramProfile $profile): ?array
    {
        if (! $profile) {
            return null;
        }

        return [
            'instagram_profile_id' => (int) $profile->id,
            'username' => $profile->username,
            'display_name' => $profile->display_name ?: $profile->full_name,
            'visibility' => $profile->profile_visibility ?: ($profile->is_private === true ? 'private' : 'unknown'),
            'followers_count' => $profile->followers_count,
            'following_count' => $profile->following_count,
            'posts_count' => $profile->posts_count,
        ];
    }

    private function accessibleInstagramProfile($user, int $profileId = 0, ?string $username = null): ?InstagramProfile
    {
        if ($profileId <= 0 && ! $username) {
            return null;
        }

        $userId = (int) $user->id;

        return InstagramProfile::query()
            ->when(
                $profileId > 0,
                fn ($query) => $query->whereKey($profileId),
                fn ($query) => $query->where('username', $username),
            )
            ->where(function ($query) use ($userId): void {
                $query
                    ->whereHas('trackedPersonLinks', fn ($links) => $links->where('user_id', $userId))
                    ->orWhereHas('publicProfileLinks', fn ($links) => $links->where('user_id', $userId))
                    ->orWhereHas('listScans', fn ($scans) => $scans->where('user_id', $userId))
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

    private function networkCandidateSummary(InstagramProfile $profile, ?TrackedPerson $focusPerson = null): array
    {
        $activeOutgoing = InstagramProfileRelationship::query()
            ->where('source_instagram_profile_id', $profile->id)
            ->where('status', 'active')
            ->whereNull('removed_at')
            ->count();
        $activeIncoming = InstagramProfileRelationship::query()
            ->where('related_instagram_profile_id', $profile->id)
            ->where('status', 'active')
            ->whereNull('removed_at')
            ->count();
        $latestListScan = $profile->listScans()->latest('scanned_at')->first();
        $lastScannedAt = $profile->last_scanned_at ?: $latestListScan?->scanned_at;
        $scanAgeDays = $lastScannedAt ? Carbon::parse($lastScannedAt)->diffInDays(now()) : null;
        $degree = $activeOutgoing + $activeIncoming;
        $followers = (int) ($profile->followers_count ?? 0);
        $following = (int) ($profile->following_count ?? 0);
        $posts = (int) ($profile->posts_count ?? 0);
        $visibility = $profile->profile_visibility ?: ($profile->is_private === true ? 'private' : 'unknown');
        $scanAgeScore = $scanAgeDays === null ? 35 : min(35, (int) floor($scanAgeDays / 3));
        $visibilityScore = match ($visibility) {
            'public' => 18,
            'private' => 8,
            default => 10,
        };
        $priorityScore = (int) round(
            min(60, $degree * 9)
            + min(35, log(max(1, $followers + 1), 10) * 8)
            + min(25, log(max(1, $following + 1), 10) * 6)
            + min(10, log(max(1, $posts + 1), 10) * 3)
            + $scanAgeScore
            + $visibilityScore
        );

        return [
            'instagram_profile_id' => (int) $profile->id,
            'username' => $profile->username,
            'display_name' => $profile->display_name ?: $profile->full_name,
            'profile_url' => $profile->profile_url ?: 'https://www.instagram.com/'.$profile->username.'/',
            'visibility' => $visibility,
            'followers_count' => $profile->followers_count,
            'following_count' => $profile->following_count,
            'posts_count' => $profile->posts_count,
            'active_outgoing_relationships' => $activeOutgoing,
            'active_incoming_relationships' => $activeIncoming,
            'degree' => $degree,
            'last_scanned_at' => optional($lastScannedAt)->toDateTimeString(),
            'scan_age_days' => $scanAgeDays,
            'last_status_level' => $profile->last_status_level,
            'last_status_message' => $profile->last_status_message,
            'priority_score' => $priorityScore,
            'recommended_next_action' => $this->recommendedNetworkAction($profile, $scanAgeDays, $degree, $focusPerson),
        ];
    }

    private function candidateInstagramProfileIdsForUser($user, ?TrackedPerson $focusPerson = null): array
    {
        $knownProfileIds = $this->knownInstagramProfileIdsForUser($user);
        $focusProfile = $focusPerson?->currentInstagramProfile
            ?: ($focusPerson ? app(InstagramProfileRelationshipStore::class)->syncTrackedPersonProfile($focusPerson) : null);

        $relationships = InstagramProfileRelationship::query()
            ->where('status', 'active')
            ->whereNull('removed_at')
            ->when($focusProfile, function ($query) use ($focusProfile) {
                $query->where(function ($inner) use ($focusProfile) {
                    $inner
                        ->where('source_instagram_profile_id', $focusProfile->id)
                        ->orWhere('related_instagram_profile_id', $focusProfile->id);
                });
            }, function ($query) use ($user, $knownProfileIds) {
                $query->where(function ($inner) use ($user, $knownProfileIds) {
                    $inner->whereHas('lastSeenScan', fn ($scan) => $scan->where('user_id', $user->id));

                    if ($knownProfileIds !== []) {
                        $inner
                            ->orWhereIn('source_instagram_profile_id', $knownProfileIds)
                            ->orWhereIn('related_instagram_profile_id', $knownProfileIds);
                    }
                });
            })
            ->latest('last_seen_at')
            ->limit(1500)
            ->get(['source_instagram_profile_id', 'related_instagram_profile_id']);

        $relationshipIds = $relationships
            ->flatMap(fn (InstagramProfileRelationship $relationship): array => [
                (int) $relationship->source_instagram_profile_id,
                (int) $relationship->related_instagram_profile_id,
            ])
            ->filter()
            ->values();

        $scanItemIds = InstagramProfileListScanItem::query()
            ->whereHas('listScan', fn ($scan) => $scan->where('user_id', $user->id))
            ->when($focusProfile, function ($query) use ($focusProfile) {
                $query->where(function ($inner) use ($focusProfile) {
                    $inner
                        ->where('source_instagram_profile_id', $focusProfile->id)
                        ->orWhere('related_instagram_profile_id', $focusProfile->id);
                });
            })
            ->latest('observed_at')
            ->limit(1500)
            ->get(['source_instagram_profile_id', 'related_instagram_profile_id'])
            ->flatMap(fn (InstagramProfileListScanItem $item): array => [
                (int) $item->source_instagram_profile_id,
                (int) $item->related_instagram_profile_id,
            ])
            ->filter()
            ->values();

        return $relationshipIds
            ->merge($scanItemIds)
            ->unique()
            ->reject(fn (int $id): bool => $focusProfile && $id === (int) $focusProfile->id)
            ->values()
            ->all();
    }

    private function knownInstagramProfileIdsForUser($user): array
    {
        $trackedProfileIds = $user->trackedPeople()
            ->whereNotNull('current_instagram_profile_id')
            ->pluck('current_instagram_profile_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->all();

        $publicProfileIds = TrackedPersonPublicProfile::query()
            ->where('user_id', $user->id)
            ->whereNotNull('instagram_profile_id')
            ->pluck('instagram_profile_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->all();

        return array_values(array_unique([...$trackedProfileIds, ...$publicProfileIds]));
    }

    private function recommendedNetworkAction(
        InstagramProfile $profile,
        ?int $scanAgeDays,
        int $degree,
        ?TrackedPerson $focusPerson = null,
    ): string {
        if ($profile->last_scanned_at === null && $scanAgeDays === null) {
            return 'Als Kontakt speichern und Profil-Vollanalyse starten.';
        }

        if ($scanAgeDays !== null && $scanAgeDays > 30) {
            return 'Profil erneut scannen, weil die letzten Daten aelter als 30 Tage sind.';
        }

        if ($profile->profile_visibility === 'private' || $profile->is_private === true) {
            return 'Bei Relevanz als Kontakt speichern; danach Suggestions ueber die Fokusperson pruefen.';
        }

        if ($degree >= 5) {
            return 'Hoch vernetzter Knoten: Profil-Vollanalyse priorisieren.';
        }

        return 'Als mittlere Prioritaet beobachten und bei Fallrelevanz scannen.';
    }

    private function resolveSourceTrackedPerson($user, array $arguments): ?TrackedPerson
    {
        $sourceArguments = [
            'tracked_person_id' => $arguments['source_tracked_person_id'] ?? $arguments['tracked_person_id'] ?? null,
            'instagram_username' => $arguments['source_instagram_username'] ?? $arguments['instagram_username'] ?? null,
        ];

        return $this->resolveTrackedPerson($user, $sourceArguments)
            ?: $user->trackedPeople()->orderByDesc('is_primary')->oldest('id')->first();
    }

    private function findTrackedPersonByProfile($user, InstagramProfile $profile): ?TrackedPerson
    {
        $username = $this->normalizeUsername($profile->username);

        return $user->trackedPeople()
            ->where(function ($query) use ($profile, $username) {
                $query->where('current_instagram_profile_id', $profile->id)
                    ->orWhereRaw('LOWER(TRIM(LEADING ? FROM instagram_username)) = ?', ['@', $username]);
            })
            ->first();
    }

    private function createTrackedPersonFromInstagramProfile($user, InstagramProfile $profile, ?string $displayName, ?string $notes): TrackedPerson
    {
        app(TrackedPersonQuotaService::class)->assertCanCreate($user);
        $name = trim((string) ($displayName ?: $profile->display_name ?: $profile->full_name ?: $profile->username));
        $parts = preg_split('/\s+/', $name, 2) ?: [];

        return $user->trackedPeople()->create([
            'first_name' => $parts[0] ?? 'Instagram',
            'last_name' => $parts[1] ?? '',
            'alias' => $name,
            'notes' => $notes,
            'instagram_username' => $profile->username,
            'current_instagram_profile_id' => $profile->id,
            'instagram_followers_count' => $profile->followers_count,
            'instagram_following_count' => $profile->following_count,
            'instagram_posts_count' => $profile->posts_count,
            'last_instagram_status_level' => $profile->last_status_level,
            'last_instagram_status_message' => $profile->last_status_message,
            'last_instagram_analyzed_at' => $profile->last_scanned_at,
            'is_primary' => ! $user->trackedPeople()->where('is_primary', true)->exists(),
        ]);
    }

    private function queueTrackedPersonScan($user, TrackedPerson $person, string $scanType): array
    {
        $token = (string) Str::uuid();
        $label = $this->scanTypeLabel($scanType);
        $tracking = app(InvestigationAssistantScanStatusStore::class)->start($token, [
            'user_id' => (int) $user->id,
            'tracked_person_id' => (int) $person->id,
            'instagram_username' => $person->instagram_username,
            'scan_type' => $scanType,
            'label' => $label,
            'message' => $label.' wurde in die Warteschlange gestellt.',
        ]);

        RunTrackedPersonInstagramToolScan::dispatch((int) $person->id, $scanType, false, $token);

        return $tracking;
    }

    private function queueNetworkProfileScan($user, TrackedPerson $sourcePerson, InstagramProfile $profile): array
    {
        $token = (string) Str::uuid();
        $label = 'Profil-Vollanalyse @'.$profile->username;
        $tracking = app(InvestigationAssistantScanStatusStore::class)->start($token, [
            'user_id' => (int) $user->id,
            'tracked_person_id' => (int) $sourcePerson->id,
            'instagram_profile_id' => (int) $profile->id,
            'instagram_username' => $profile->username,
            'scan_type' => 'network_profile',
            'label' => $label,
            'message' => $label.' wurde in die Warteschlange gestellt.',
        ]);

        $profile->forceFill([
            'last_status_level' => 'partial',
            'last_status_message' => 'AI-Assistent hat eine Profil-Vollanalyse in die Warteschlange gestellt.',
        ])->save();

        ScanInstagramProfileJob::dispatch(
            (int) $sourcePerson->id,
            (int) $profile->id,
            (int) $user->id,
            $token,
        );
        NetworkMap::forgetGraphCacheForUser((int) $user->id);
        NetworkMap::forgetGraphCacheForUser((int) $user->id, (int) $sourcePerson->id);

        return $tracking;
    }

    private function validRelationshipType(string $relationshipType): string
    {
        return in_array($relationshipType, ['public_connection', 'follows_target', 'followed_by_target', 'mutual'], true)
            ? $relationshipType
            : 'public_connection';
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
            'suggestions' => 'Vorschlaege-Scan',
            'suggestion_deepsearch' => 'Vorschlaege DeepSearch',
            'posts' => 'Beitragsscan',
            'public_connections' => 'Public-Profile-Verbindungsscan',
            default => 'Instagram-Scan',
        };
    }
}
