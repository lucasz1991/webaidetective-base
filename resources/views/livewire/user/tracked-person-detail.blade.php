<div class="space-y-4" wire:poll.visible.10000ms x-data="{ instagramListModal: null, settingsModal: false }" @keydown.escape.window="instagramListModal = null; settingsModal = false">
    @php
        $detailStatusClass = match ($detailStatusLevel ?? 'neutral') {
            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
            default => 'border-slate-200 bg-slate-50 text-slate-800',
        };
        $latestSnapshot = $trackedPerson->latestInstagramSnapshot;
        $latestCountSources = data_get($latestSnapshot?->raw_payload, 'extractedProfile.countSources', []);
        $latestCountWarnings = data_get($latestSnapshot?->raw_payload, 'extractedProfile.countWarnings', []);
        $latestDebugLogPath = data_get($latestSnapshot?->raw_payload, 'debugLogPath');
        $latestCookieDiagnostics = data_get($latestSnapshot?->raw_payload, 'cookieDiagnostics', []);
        $latestLoginDiagnostics = data_get($latestSnapshot?->raw_payload, 'loginDiagnostics', []);
        $latestFollowersList = data_get($latestSnapshot?->raw_payload, 'extractedProfile.followersList', []);
        $latestFollowingList = data_get($latestSnapshot?->raw_payload, 'extractedProfile.followingList', []);
        $loadRelationshipItems = function (array $relationshipList, string $key = 'items') {
            $items = collect(data_get($relationshipList, $key, []));
            $itemsPath = data_get($relationshipList, 'itemsPath');

            if ($items->isNotEmpty() || ! is_string($itemsPath) || $itemsPath === '') {
                return $items;
            }

            try {
                if (! \Illuminate\Support\Facades\Storage::disk('public')->exists($itemsPath)) {
                    return collect();
                }

                $decoded = json_decode(
                    \Illuminate\Support\Facades\Storage::disk('public')->get($itemsPath),
                    true,
                );

                return collect(data_get($decoded, $key, []));
            } catch (\Throwable) {
                return collect();
            }
        };
        $latestFollowerItems = $loadRelationshipItems($latestFollowersList);
        $latestFollowingItems = $loadRelationshipItems($latestFollowingList);
        $latestFollowerRemovedItems = $loadRelationshipItems($latestFollowersList, 'currentlyRemovedItems');
        $latestFollowingRemovedItems = $loadRelationshipItems($latestFollowingList, 'currentlyRemovedItems');
        $relationshipStats = function (array $relationshipList, \Illuminate\Support\Collection $items) {
            return [
                'activeCount' => (int) data_get($relationshipList, 'activeCount', data_get($relationshipList, 'count', $items->count())),
                'observedCount' => (int) data_get($relationshipList, 'observedCount', $items->count()),
                'allKnownCount' => (int) data_get($relationshipList, 'allKnownCount', data_get($relationshipList, 'knownCount', $items->count())),
                'currentlyRemovedCount' => (int) data_get($relationshipList, 'currentlyRemovedCount', 0),
                'removedHistoryCount' => (int) data_get($relationshipList, 'removedHistoryCount', 0),
            ];
        };
        $latestFollowerStats = $relationshipStats($latestFollowersList, $latestFollowerItems);
        $latestFollowingStats = $relationshipStats($latestFollowingList, $latestFollowingItems);
        $latestScrapePhases = collect(data_get($latestSnapshot?->raw_payload, 'analysisPolicy.scrapePhases', []));
        $countSourceLabels = [
            'body_text_preview' => 'sichtbarer Profiltext',
            'description_meta' => 'Meta-Beschreibung',
            'html_document' => 'HTML-Fallback',
        ];
        $resolveCountSourceLabel = function ($source) use ($countSourceLabels) {
            return $source ? ($countSourceLabels[$source] ?? $source) : 'keine sichtbaren Werte';
        };
    @endphp

    <div
        wire:loading.flex
        wire:target="analyzeInstagram"
        class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-950/60 px-4"
    >
        <div class="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-2xl">
            <div class="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-slate-200 border-t-pink-600"></div>
            <div class="mt-4 text-xs font-semibold uppercase tracking-wide text-pink-700" wire:stream="instagram-progress-phase">Start</div>
            <h3 class="mt-1 text-lg font-bold text-slate-900">Instagram-Analyse wird gestartet</h3>
            <p class="mt-2 text-sm leading-6 text-slate-600" wire:stream="instagram-progress-message">
                Der Auftrag wird an die Queue uebergeben. Der Status aktualisiert sich automatisch.
            </p>
            <div class="mt-5">
                <div class="flex items-center justify-between text-xs font-semibold text-slate-500">
                    <span>Fortschritt</span>
                    <span wire:stream="instagram-progress-percent">0%</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200" wire:stream="instagram-progress-bar">
                    <div class="h-full rounded-full bg-pink-600" style="width: 0%"></div>
                </div>
            </div>
            <p class="mt-4 text-xs leading-5 text-slate-500">
                Die eigentliche Analyse laeuft im Hintergrund wie die automatische Dauerbeobachtung.
            </p>
        </div>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <div class="h-20 w-20 shrink-0 overflow-hidden rounded-2xl bg-slate-100 sm:h-24 sm:w-24">
                    @if($trackedPerson->profile_image_url)
                        <img src="{{ $trackedPerson->profile_image_url }}" alt="{{ $trackedPerson->display_name }}" class="h-full w-full object-cover">
                    @else
                        <div class="flex h-full w-full items-center justify-center text-xs font-semibold text-slate-500">
                            Kein Bild
                        </div>
                    @endif
                </div>
                <div class="min-w-0">
                    <h2 class="break-words text-xl font-bold text-slate-900 sm:text-2xl">{{ $trackedPerson->display_name }}</h2>
                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600 sm:text-sm">
                        <span>Alias: {{ $trackedPerson->alias ?: '—' }}</span>
                        <span>Ort: {{ $trackedPerson->city ?: '—' }}</span>
                        <span>Land: {{ $trackedPerson->country ?: '—' }}</span>
                        <span>Geburt: {{ optional($trackedPerson->date_of_birth)->format('d.m.Y') ?: '—' }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-1.5 text-xs">
                        @if($trackedPerson->instagram_username)
                            <span class="rounded-full bg-pink-100 px-3 py-1 font-semibold text-pink-700">
                                Instagram: {{ '@'.$trackedPerson->instagram_username }}
                            </span>
                        @endif
                        @if($trackedPerson->monitoring_enabled)
                            <span class="rounded-full bg-indigo-100 px-3 py-1 font-semibold text-indigo-700">
                                Dauerbeobachtung aktiv
                            </span>
                        @endif
                        @if($trackedPerson->notify_social_changes)
                            <span class="rounded-full bg-sky-100 px-3 py-1 font-semibold text-sky-700">
                                Benachrichtigungen aktiv
                            </span>
                        @endif
                        @if($trackedPerson->last_instagram_analyzed_at)
                            <span class="rounded-full bg-slate-100 px-3 py-1 font-semibold text-slate-700">
                                Letzte Analyse: {{ $trackedPerson->last_instagram_analyzed_at->format('d.m.Y H:i') }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:justify-end">
                <button
                    wire:click="analyzeInstagram"
                    wire:loading.attr="disabled"
                    wire:target="analyzeInstagram"
                    class="inline-flex justify-center rounded-xl bg-pink-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-pink-700"
                >
                    <span wire:loading.remove wire:target="analyzeInstagram">Instagram analysieren</span>
                    <span wire:loading wire:target="analyzeInstagram">Analyse laeuft...</span>
                </button>
                <button
                    type="button"
                    @click="settingsModal = true"
                    class="inline-flex justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                >
                    Einstellungen
                </button>
            </div> 
        </div>

        @if($detailStatus)
            <div class="mt-4 rounded-xl border p-3 text-sm {{ $detailStatusClass }}">
                {{ $detailStatus }}
            </div> 
        @endif
    </section>

    <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-5">
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Follower</div>
                <button
                    type="button"
                    @click="instagramListModal = 'followers'"
                    class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    @disabled($latestFollowerItems->isEmpty())
                >
                    Liste
                </button>
            </div>
            <div class="mt-2 text-xl font-bold text-slate-900 sm:text-2xl">{{ $trackedPerson->instagram_followers_count !== null ? number_format($trackedPerson->instagram_followers_count) : '—' }}</div>
            <div class="mt-1 text-xs text-slate-500">{{ number_format($latestFollowerStats['activeCount']) }} bekannt aktiv/ungeklaert</div>
            <div class="mt-0.5 text-xs text-slate-500">
                {{ number_format($latestFollowerStats['observedCount']) }} zuletzt gesehen
                @if($latestFollowerStats['currentlyRemovedCount'] > 0)
                    &middot; {{ number_format($latestFollowerStats['currentlyRemovedCount']) }} entfernt archiviert
                @endif
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gefolgt</div>
                <button
                    type="button"
                    @click="instagramListModal = 'following'"
                    class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    @disabled($latestFollowingItems->isEmpty())
                >
                    Liste
                </button>
            </div>
            <div class="mt-2 text-xl font-bold text-slate-900 sm:text-2xl">{{ $trackedPerson->instagram_following_count !== null ? number_format($trackedPerson->instagram_following_count) : '—' }}</div>
            <div class="mt-1 text-xs text-slate-500">{{ number_format($latestFollowingStats['activeCount']) }} bekannt aktiv/ungeklaert</div>
            <div class="mt-0.5 text-xs text-slate-500">
                {{ number_format($latestFollowingStats['observedCount']) }} zuletzt gesehen
                @if($latestFollowingStats['currentlyRemovedCount'] > 0)
                    &middot; {{ number_format($latestFollowingStats['currentlyRemovedCount']) }} entfernt archiviert
                @endif
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Beitraege</div>
            <div class="mt-2 text-xl font-bold text-slate-900 sm:text-2xl">{{ $trackedPerson->instagram_posts_count !== null ? number_format($trackedPerson->instagram_posts_count) : '—' }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Bekannte Daten</div>
            <div class="mt-2 text-xl font-bold text-slate-900 sm:text-2xl">{{ number_format($trackedPerson->knownFacts->count()) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Oeffentliche Profile</div>
            <div class="mt-2 text-xl font-bold text-slate-900 sm:text-2xl">{{ number_format($trackedPerson->publicProfiles->count()) }}</div>
        </div>
    </section>

    <div
        x-cloak
        x-show="instagramListModal === 'followers'"
        x-transition.opacity
        class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/50 px-3 py-4 sm:items-center sm:px-4 sm:py-6"
        @click.self="instagramListModal = null"
    >
        <div class="flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl sm:max-h-[85vh]">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5 sm:py-4">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Followerliste</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ number_format($latestFollowerStats['activeCount']) }} bekannt aktiv/ungeklaert
                        &middot; {{ number_format($latestFollowerStats['observedCount']) }} zuletzt gesehen
                        @if(data_get($latestFollowersList, 'maxItems'))
                            &middot; Limit {{ number_format((int) data_get($latestFollowersList, 'maxItems')) }}
                        @endif
                        @if($latestFollowerStats['currentlyRemovedCount'] > 0)
                            &middot; {{ number_format($latestFollowerStats['currentlyRemovedCount']) }} entfernt archiviert
                        @endif
                    </p>
                </div>
                <button type="button" @click="instagramListModal = null" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Schliessen
                </button>
            </div>

            <div class="overflow-y-auto p-4 sm:p-5">
                @if((int) data_get($latestFollowersList, 'addedCount', 0) > 0 || (int) data_get($latestFollowersList, 'removedCount', 0) > 0)
                    <div class="mb-4 grid gap-3 md:grid-cols-2">
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-900">
                            <div class="font-semibold">{{ number_format((int) data_get($latestFollowersList, 'addedCount', 0)) }} hinzugefuegt</div>
                            <div class="mt-1 break-words">
                                {{ collect(data_get($latestFollowersList, 'addedPreview', []))->pluck('username')->map(fn ($username) => '@'.$username)->implode(', ') ?: 'Keine neuen Eintraege' }}
                            </div>
                        </div>
                        <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
                            <div class="font-semibold">{{ number_format((int) data_get($latestFollowersList, 'removedCount', 0)) }} entfernt</div>
                            <div class="mt-1 break-words">
                                {{ collect(data_get($latestFollowersList, 'removedPreview', []))->pluck('username')->map(fn ($username) => '@'.$username)->implode(', ') ?: 'Keine entfernten Eintraege' }}
                            </div>
                        </div>
                    </div>
                @endif

                @if($latestFollowerItems->isNotEmpty() || $latestFollowerRemovedItems->isNotEmpty())
                    @if($latestFollowerItems->isNotEmpty())
                        <div class="space-y-2">
                            @foreach($latestFollowerItems as $follower)
                                <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                                    <div class="min-w-0">
                                        <div class="truncate font-semibold text-slate-900">{{ '@'.data_get($follower, 'username') }}</div>
                                        @if(data_get($follower, 'displayName'))
                                            <div class="mt-0.5 truncate text-slate-500">{{ data_get($follower, 'displayName') }}</div>
                                        @endif
                                    </div>
                                    @if(data_get($follower, 'profileUrl'))
                                        <a href="{{ data_get($follower, 'profileUrl') }}" target="_blank" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-white">
                                            Oeffnen
                                        </a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if($latestFollowerRemovedItems->isNotEmpty())
                        <details class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-950">
                            <summary class="cursor-pointer font-semibold">
                                {{ number_format($latestFollowerRemovedItems->count()) }} entfernt archiviert
                            </summary>
                            <div class="mt-3 space-y-2">
                                @foreach($latestFollowerRemovedItems as $removedFollower)
                                    <div class="flex items-center justify-between gap-3 rounded-xl border border-rose-100 bg-white px-4 py-3 text-sm">
                                        <div class="min-w-0">
                                            <div class="truncate font-semibold text-slate-900">{{ '@'.data_get($removedFollower, 'username') }}</div>
                                            @if(data_get($removedFollower, 'displayName'))
                                                <div class="mt-0.5 truncate text-slate-500">{{ data_get($removedFollower, 'displayName') }}</div>
                                            @endif
                                        </div>
                                        @if(data_get($removedFollower, 'profileUrl'))
                                            <a href="{{ data_get($removedFollower, 'profileUrl') }}" target="_blank" class="shrink-0 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-50">
                                                Oeffnen
                                            </a>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                @else
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        Keine Followerliste gespeichert.
                        @if(data_get($latestFollowersList, 'reason'))
                            Grund: {{ data_get($latestFollowersList, 'reason') }}
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div
        x-cloak
        x-show="instagramListModal === 'following'"
        x-transition.opacity
        class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/50 px-3 py-4 sm:items-center sm:px-4 sm:py-6"
        @click.self="instagramListModal = null"
    >
        <div class="flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl sm:max-h-[85vh]">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5 sm:py-4">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Gefolgt-Liste</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ number_format($latestFollowingStats['activeCount']) }} bekannt aktiv/ungeklaert
                        &middot; {{ number_format($latestFollowingStats['observedCount']) }} zuletzt gesehen
                        @if(data_get($latestFollowingList, 'maxItems'))
                            &middot; Limit {{ number_format((int) data_get($latestFollowingList, 'maxItems')) }}
                        @endif
                        @if($latestFollowingStats['currentlyRemovedCount'] > 0)
                            &middot; {{ number_format($latestFollowingStats['currentlyRemovedCount']) }} entfernt archiviert
                        @endif
                    </p>
                </div>
                <button type="button" @click="instagramListModal = null" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Schliessen
                </button>
            </div>

            <div class="overflow-y-auto p-4 sm:p-5">
                @if((int) data_get($latestFollowingList, 'addedCount', 0) > 0 || (int) data_get($latestFollowingList, 'removedCount', 0) > 0)
                    <div class="mb-4 grid gap-3 md:grid-cols-2">
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-900">
                            <div class="font-semibold">{{ number_format((int) data_get($latestFollowingList, 'addedCount', 0)) }} hinzugefuegt</div>
                            <div class="mt-1 break-words">
                                {{ collect(data_get($latestFollowingList, 'addedPreview', []))->pluck('username')->map(fn ($username) => '@'.$username)->implode(', ') ?: 'Keine neuen Eintraege' }}
                            </div>
                        </div>
                        <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
                            <div class="font-semibold">{{ number_format((int) data_get($latestFollowingList, 'removedCount', 0)) }} entfernt</div>
                            <div class="mt-1 break-words">
                                {{ collect(data_get($latestFollowingList, 'removedPreview', []))->pluck('username')->map(fn ($username) => '@'.$username)->implode(', ') ?: 'Keine entfernten Eintraege' }}
                            </div>
                        </div>
                    </div>
                @endif

                @if($latestFollowingItems->isNotEmpty() || $latestFollowingRemovedItems->isNotEmpty())
                    @if($latestFollowingItems->isNotEmpty())
                        <div class="space-y-2">
                            @foreach($latestFollowingItems as $followedProfile)
                                <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                                    <div class="min-w-0">
                                        <div class="truncate font-semibold text-slate-900">{{ '@'.data_get($followedProfile, 'username') }}</div>
                                        @if(data_get($followedProfile, 'displayName'))
                                            <div class="mt-0.5 truncate text-slate-500">{{ data_get($followedProfile, 'displayName') }}</div>
                                        @endif
                                    </div>
                                    @if(data_get($followedProfile, 'profileUrl'))
                                        <a href="{{ data_get($followedProfile, 'profileUrl') }}" target="_blank" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-white">
                                            Oeffnen
                                        </a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if($latestFollowingRemovedItems->isNotEmpty())
                        <details class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-950">
                            <summary class="cursor-pointer font-semibold">
                                {{ number_format($latestFollowingRemovedItems->count()) }} entfernt archiviert
                            </summary>
                            <div class="mt-3 space-y-2">
                                @foreach($latestFollowingRemovedItems as $removedProfile)
                                    <div class="flex items-center justify-between gap-3 rounded-xl border border-rose-100 bg-white px-4 py-3 text-sm">
                                        <div class="min-w-0">
                                            <div class="truncate font-semibold text-slate-900">{{ '@'.data_get($removedProfile, 'username') }}</div>
                                            @if(data_get($removedProfile, 'displayName'))
                                                <div class="mt-0.5 truncate text-slate-500">{{ data_get($removedProfile, 'displayName') }}</div>
                                            @endif
                                        </div>
                                        @if(data_get($removedProfile, 'profileUrl'))
                                            <a href="{{ data_get($removedProfile, 'profileUrl') }}" target="_blank" class="shrink-0 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-50">
                                                Oeffnen
                                            </a>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                @else
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        Keine Gefolgt-Liste gespeichert.
                        @if(data_get($latestFollowingList, 'reason'))
                            Grund: {{ data_get($latestFollowingList, 'reason') }}
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div
        x-cloak
        x-show="settingsModal"
        x-transition.opacity
        class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/50 px-3 py-4 sm:items-center sm:px-4"
        @click.self="settingsModal = false"
    >
        <div class="flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5 sm:py-4">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Einstellungen</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Personendaten, Dauerbeobachtung und Social-Media-Benachrichtigungen.
                    </p>
                </div>
                <button type="button" @click="settingsModal = false" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Schliessen
                </button>
            </div>

            <div class="overflow-y-auto p-4 sm:p-5">
                <div>
                    <h4 class="text-sm font-bold text-slate-900">Personendaten</h4>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Vorname</label>
                            <input type="text" wire:model.defer="first_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('first_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Nachname</label>
                            <input type="text" wire:model.defer="last_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('last_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Alias</label>
                            <input type="text" wire:model.defer="alias" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Geburtsdatum</label>
                            <input type="date" wire:model.defer="date_of_birth" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Ort</label>
                            <input type="text" wire:model.defer="city" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Land</label>
                            <input type="text" wire:model.defer="country" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Instagram</label>
                            <input type="text" wire:model.defer="instagram_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">TikTok</label>
                            <input type="text" wire:model.defer="tiktok_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Facebook</label>
                            <input type="text" wire:model.defer="facebook_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">X / Twitter</label>
                            <input type="text" wire:model.defer="x_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">YouTube</label>
                            <input type="text" wire:model.defer="youtube_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Snapchat</label>
                            <input type="text" wire:model.defer="snapchat_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-slate-700">Notizen</label>
                            <textarea wire:model.defer="notes" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"></textarea>
                        </div>
                    </div>
                </div>

                <div class="mt-5 border-t border-slate-200 pt-4">
                    <h4 class="text-sm font-bold text-slate-900">Benachrichtigungen</h4>
                    <div class="mt-3 grid gap-3">
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="monitoring_enabled" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span class="font-medium">Dauerbeobachtung aktivieren</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_social_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span class="font-medium">Benachrichtigungen fuer Social-Media-Aenderungen aktivieren</span>
                        </label>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Kanaele</div>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_instagram_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>Instagram</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_tiktok_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>TikTok</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_facebook_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>Facebook</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_x_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>X / Twitter</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_youtube_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>YouTube</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_snapchat_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>Snapchat</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 px-4 py-3 sm:flex-row sm:justify-end sm:px-5">
                <button type="button" @click="settingsModal = false" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Abbrechen
                </button>
                <button
                    type="button"
                    wire:click="saveTrackedPerson"
                    wire:loading.attr="disabled"
                    wire:target="saveTrackedPerson"
                    @click="settingsModal = false"
                    class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Einstellungen speichern
                </button>
            </div>
        </div>
    </div>

    <section class="grid gap-4 2xl:grid-cols-[minmax(0,1.05fr)_minmax(320px,0.8fr)]">
        <div class="space-y-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Bekannte Daten</h3>
                <div class="mt-3 space-y-2">
                    @forelse($trackedPerson->knownFacts as $knownFact)
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <div class="flex items-center justify-between gap-3">
                                <div class="font-semibold text-slate-900">{{ $knownFact->label }}</div>
                                @if($knownFact->source)
                                    <span class="text-xs uppercase tracking-wide text-slate-500">{{ $knownFact->source }}</span>
                                @endif
                            </div>
                            <p class="mt-1 whitespace-pre-wrap">{{ $knownFact->value }}</p>
                            @if($knownFact->notes)
                                <p class="mt-2 text-xs text-slate-500">{{ $knownFact->notes }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Noch keine bekannten Daten hinterlegt.</p>
                    @endforelse
                </div>

                <div class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Bezeichnung</label>
                        <input type="text" wire:model.defer="knownFactLabel" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" placeholder="z. B. Wohnort">
                        @error('knownFactLabel') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Wert</label>
                        <textarea wire:model.defer="knownFactValue" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"></textarea>
                        @error('knownFactValue') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Quelle</label>
                        <input type="text" wire:model.defer="knownFactSource" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('knownFactSource') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Zusatznotiz</label>
                        <textarea wire:model.defer="knownFactNotes" rows="2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"></textarea>
                        @error('knownFactNotes') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end">
                        <button wire:click="saveKnownFact" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-slate-800">
                            Daten speichern
                        </button>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Bekannte oeffentliche Profile</h3>
                <p class="mt-1 text-sm text-slate-600">
                    Hier speicherst du oeffentlich sichtbare Profile, die nachweisbar mit dieser Person verbunden sind.
                </p>

                <div class="mt-3 space-y-2">
                    @forelse($trackedPerson->publicProfiles as $publicProfile)
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-semibold text-slate-900">
                                            {{ $publicProfile->display_name ?: $publicProfile->display_handle }}
                                        </span>
                                        <span class="rounded-full bg-white px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                            {{ $publicProfile->platform }}
                                        </span>
                                        <span class="rounded-full bg-sky-100 px-2 py-1 text-[11px] font-semibold text-sky-800">
                                            {{ $publicProfile->relationship_label }}
                                        </span>
                                        @if($publicProfile->is_public)
                                            <span class="rounded-full bg-emerald-100 px-2 py-1 text-[11px] font-semibold text-emerald-800">
                                                Oeffentlich bestaetigt
                                            </span>
                                        @endif
                                    </div>
                                    <div class="mt-1 text-slate-600">{{ $publicProfile->display_handle }}</div>
                                    @if($publicProfile->notes)
                                        <p class="mt-2 whitespace-pre-wrap text-xs text-slate-500">{{ $publicProfile->notes }}</p>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @if($publicProfile->resolved_profile_url)
                                        <a href="{{ $publicProfile->resolved_profile_url }}" target="_blank" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-white">
                                            Profil oeffnen
                                        </a>
                                    @endif
                                    <button wire:click="deletePublicProfile({{ $publicProfile->id }})" class="rounded-xl border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                        Entfernen
                                    </button>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Bisher wurden noch keine bekannten oeffentlichen Profile hinterlegt.</p>
                    @endforelse
                </div>

                <div class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Plattform</label>
                            <select wire:model.defer="publicProfilePlatform" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                <option value="instagram">Instagram</option>
                                <option value="tiktok">TikTok</option>
                                <option value="facebook">Facebook</option>
                                <option value="x">X / Twitter</option>
                                <option value="youtube">YouTube</option>
                                <option value="snapchat">Snapchat</option>
                                <option value="other">Andere Plattform</option>
                            </select>
                            @error('publicProfilePlatform') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Handle / Nutzername</label>
                            <input type="text" wire:model.defer="publicProfileUsername" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" placeholder="@profilname">
                            @error('publicProfileUsername') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Anzeigename</label>
                            <input type="text" wire:model.defer="publicProfileDisplayName" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" placeholder="Optionaler Klarname">
                            @error('publicProfileDisplayName') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Beziehungsart</label>
                            <select wire:model.defer="publicProfileRelationshipType" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                <option value="follows_target">Folgt der Person</option>
                                <option value="followed_by_target">Wird von der Person gefolgt</option>
                                <option value="mutual">Gegenseitige Verbindung</option>
                                <option value="public_connection">Allgemeine oeffentliche Verbindung</option>
                            </select>
                            @error('publicProfileRelationshipType') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Profil-URL</label>
                        <input type="url" wire:model.defer="publicProfileUrl" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" placeholder="Optional, falls du die exakte URL speichern willst">
                        @error('publicProfileUrl') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                        <input type="checkbox" wire:model.defer="publicProfileIsPublic" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span class="font-medium">Profil ist oeffentlich bestaetigt</span>
                    </label>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Notizen</label>
                        <textarea wire:model.defer="publicProfileNotes" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" placeholder="Warum dieses Profil relevant ist, z. B. gegenseitige Erwaehnungen oder bekannte Verbindungen"></textarea>
                        @error('publicProfileNotes') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end">
                        <button wire:click="savePublicProfile" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-slate-800">
                            Oeffentliches Profil speichern
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Letzte Instagram-Analyse</h3>

                @if($latestSnapshot)
                    @php
                        $snapshotStatusClass = match ($latestSnapshot->status_level ?? 'neutral') {
                            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
                            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
                            default => 'border-slate-200 bg-slate-50 text-slate-700',
                        };
                    @endphp

                    <div class="mt-3 rounded-xl border p-3 text-sm {{ $snapshotStatusClass }}">
                        <p class="font-semibold">{{ $latestSnapshot->status_message }}</p>
                        <p class="mt-1 text-xs">{{ optional($latestSnapshot->analyzed_at)->format('d.m.Y H:i') ?: '—' }}</p>
                        @if($latestSnapshot->screenshot_url)
                            <a href="{{ $latestSnapshot->screenshot_url }}" target="_blank" class="mt-3 inline-flex rounded-full border border-current px-3 py-1 text-xs font-semibold uppercase tracking-wide">
                                Snapshot oeffnen
                            </a>
                        @endif
                    </div>

                    <div class="mt-3 grid gap-2 text-sm text-slate-700">
                        <p><span class="font-semibold">Profilname:</span> {{ $latestSnapshot->full_name ?: '—' }}</p>
                        <p><span class="font-semibold">Bio:</span> {{ $latestSnapshot->biography ?: '—' }}</p>
                        <p><span class="font-semibold">Follower-Quelle:</span> {{ $resolveCountSourceLabel($latestCountSources['followers'] ?? null) }}</p>
                        <p><span class="font-semibold">Gefolgt-Quelle:</span> {{ $resolveCountSourceLabel($latestCountSources['following'] ?? null) }}</p>
                        <p><span class="font-semibold">Beitraege-Quelle:</span> {{ $resolveCountSourceLabel($latestCountSources['posts'] ?? null) }}</p>
                        <p><span class="font-semibold">Profilbild-Hash:</span> {{ $latestSnapshot->profile_image_hash ?: '—' }}</p>
                    </div>

                    @if($latestScrapePhases->isNotEmpty())
                        <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                            <h4 class="font-semibold text-slate-900">Scrape-Phasen</h4>
                            <div class="mt-2 grid gap-2">
                                @foreach($latestScrapePhases as $phase)
                                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white px-3 py-2">
                                        <span class="font-semibold">
                                            {{ match(data_get($phase, 'phase')) {
                                                'profile' => 'Grunddaten',
                                                'followers' => 'Followerliste',
                                                'following' => 'Gefolgt-Liste',
                                                default => data_get($phase, 'phase', 'Unbekannt'),
                                            } }}
                                        </span>
                                        <span class="text-slate-500">
                                            {{ data_get($phase, 'statusLevel', 'unknown') }}
                                            @if(data_get($phase, 'count') !== null)
                                                · {{ number_format((int) data_get($phase, 'count')) }} Eintraege
                                            @endif
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($latestDebugLogPath)
                        <p class="mt-3 break-all text-sm text-slate-700">
                            <span class="font-semibold">Debug-Log:</span> {{ $latestDebugLogPath }}
                        </p>
                    @endif

                    @if($latestCookieDiagnostics || $latestLoginDiagnostics)
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                                <h4 class="font-semibold text-slate-900">Cookie-Diagnose</h4>
                                <p class="mt-2">sessionid in Datei: {{ data_get($latestCookieDiagnostics, 'sessionCookieProvided') ? 'Ja' : 'Nein' }}</p>
                                <p>sessionid akzeptiert: {{ data_get($latestCookieDiagnostics, 'sessionCookieAccepted') ? 'Ja' : 'Nein' }}</p>
                                <p>sessionid nach Reload noch da: {{ data_get($latestCookieDiagnostics, 'sessionCookieRetained') ? 'Ja' : 'Nein' }}</p>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                                <h4 class="font-semibold text-slate-900">Login-Diagnose</h4>
                                <p class="mt-2">Auto-Login versucht: {{ data_get($latestLoginDiagnostics, 'attempted') ? 'Ja' : 'Nein' }}</p>
                                <p>Formular gefunden: {{ data_get($latestLoginDiagnostics, 'formDetected') ? 'Ja' : 'Nein' }}</p>
                                <p>Login erfolgreich: {{ data_get($latestLoginDiagnostics, 'success') ? 'Ja' : 'Nein' }}</p>
                                <p>sessionid nach Login: {{ data_get($latestLoginDiagnostics, 'sessionCookiePresent') ? 'Ja' : 'Nein' }}</p>
                            </div>
                        </div>
                    @endif

                    @if($latestCountWarnings)
                        <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                            <h4 class="font-semibold">Metrik-Hinweise</h4>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @foreach($latestCountWarnings as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($latestSnapshot->has_changes && $latestSnapshot->detected_changes)
                        <div class="mt-3 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-950">
                            <h4 class="font-semibold">Erkannte Aenderungen</h4>
                            <ul class="mt-2 space-y-2">
                                @foreach($latestSnapshot->detected_changes as $change)
                                    <li>
                                        <span class="font-semibold">{{ $change['label'] ?? $change['field'] }}:</span>
                                        <span>{{ filled($change['before'] ?? null) ? $change['before'] : '—' }}</span>
                                        <span>→</span>
                                        <span>{{ filled($change['after'] ?? null) ? $change['after'] : '—' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($latestSnapshot->profile_image_storage_url)
                        <div class="mt-3">
                            <h4 class="text-sm font-semibold text-slate-900">Gespeichertes Profilbild der letzten Analyse</h4>
                            <div class="mt-2">
                                <a href="{{ $latestSnapshot->profile_image_storage_url }}" target="_blank" class="block overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
                                    <img src="{{ $latestSnapshot->profile_image_storage_url }}" alt="Gespeichertes Profilbild" class="h-32 w-full object-cover">
                                </a>
                            </div>
                        </div>
                    @endif
                @else
                    <p class="mt-3 text-sm text-slate-500">Bisher wurde noch keine Instagram-Analyse gespeichert.</p>
                @endif
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Profilbild-Historie</h3>
                <p class="mt-1 text-sm text-slate-600">
                    Gespeichert werden nur eindeutig dem analysierten Profil zuordenbare Profilbilder, keine Vorschlagsbilder oder Bilder des eingeloggten Such-Profils.
                </p>

                @if($profileImageHistory->isNotEmpty())
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($profileImageHistory as $profileImage)
                            <a href="{{ $profileImage->storage_url }}" target="_blank" class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50 shadow-sm">
                                <img src="{{ $profileImage->storage_url }}" alt="Gespeichertes Profilbild" class="h-32 w-full object-cover">
                                <div class="border-t border-slate-200 px-3 py-2 text-xs text-slate-600">
                                    {{ optional($profileImage->snapshot?->analyzed_at)->format('d.m.Y H:i') ?: 'Unbekanntes Datum' }}
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="mt-3 text-sm text-slate-500">Bisher wurden noch keine Profilbilder in der Historie gespeichert.</p>
                @endif
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Analyse-Historie</h3>
                <div class="mt-3 space-y-2">
                    @forelse($trackedPerson->instagramSnapshots as $snapshot)
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-slate-900">{{ optional($snapshot->analyzed_at)->format('d.m.Y H:i') ?: '—' }}</div>
                                    <div class="mt-1">{{ $snapshot->status_message }}</div>
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ $snapshot->status_level }}
                                </div>
                            </div>
                            <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                                <div class="rounded-xl bg-white px-3 py-2">
                                    <div class="text-slate-500">Follower</div>
                                    <div class="mt-1 font-semibold text-slate-900">{{ $snapshot->followers_count !== null ? number_format($snapshot->followers_count) : '—' }}</div>
                                </div>
                                <div class="rounded-xl bg-white px-3 py-2">
                                    <div class="text-slate-500">Gefolgt</div>
                                    <div class="mt-1 font-semibold text-slate-900">{{ $snapshot->following_count !== null ? number_format($snapshot->following_count) : '—' }}</div>
                                </div>
                                <div class="rounded-xl bg-white px-3 py-2">
                                    <div class="text-slate-500">Beitraege</div>
                                    <div class="mt-1 font-semibold text-slate-900">{{ $snapshot->posts_count !== null ? number_format($snapshot->posts_count) : '—' }}</div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Noch keine Verlaufseintraege mit erkannten Aenderungen vorhanden.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </section>
</div>
