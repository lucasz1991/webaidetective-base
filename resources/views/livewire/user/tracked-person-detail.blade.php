<div class="space-y-4" wire:poll.visible.4000ms>
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
        $relationshipSearchText = function ($item) {
            return \Illuminate\Support\Str::lower(trim(data_get($item, 'username', '').' '.data_get($item, 'displayName', '')));
        };
        $relationshipTimestamp = function ($item, array $keys = ['firstSeenAt', 'lastSeenAt', 'removedAt']) {
            foreach ($keys as $key) {
                $value = data_get($item, $key);

                if (! filled($value)) {
                    continue;
                }

                try {
                    return \Illuminate\Support\Carbon::parse($value)->timestamp;
                } catch (\Throwable) {
                    continue;
                }
            }

            return 0;
        };
        $sortRelationshipItemsNewest = function (\Illuminate\Support\Collection $items, array $keys = ['firstSeenAt', 'lastSeenAt', 'removedAt']) use ($relationshipTimestamp) {
            return $items
                ->values()
                ->sortByDesc(fn ($item, $index) => sprintf('%020d.%06d', $relationshipTimestamp($item, $keys), 999999 - $index))
                ->values();
        };
        $sortRelationshipActiveItems = function (\Illuminate\Support\Collection $items, \Illuminate\Support\Collection $addedItems) use ($relationshipTimestamp) {
            $addedUsernames = $addedItems
                ->pluck('username')
                ->filter()
                ->map(fn ($username) => \Illuminate\Support\Str::lower((string) $username))
                ->flip();

            return $items
                ->values()
                ->sortByDesc(function ($item, $index) use ($addedUsernames, $relationshipTimestamp) {
                    $username = \Illuminate\Support\Str::lower((string) data_get($item, 'username', ''));
                    $isAdded = $addedUsernames->has($username) ? 1 : 0;
                    $timestamp = $relationshipTimestamp($item, ['firstSeenAt', 'lastSeenAt', 'removedAt']);

                    return sprintf('%d.%020d.%06d', $isAdded, $timestamp, 999999 - $index);
                })
                ->values();
        };
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
        $latestFollowerAddedItems = $sortRelationshipItemsNewest($loadRelationshipItems($latestFollowersList, 'addedItems'));
        $latestFollowingAddedItems = $sortRelationshipItemsNewest($loadRelationshipItems($latestFollowingList, 'addedItems'));
        $latestFollowerItems = $sortRelationshipActiveItems($loadRelationshipItems($latestFollowersList), $latestFollowerAddedItems);
        $latestFollowingItems = $sortRelationshipActiveItems($loadRelationshipItems($latestFollowingList), $latestFollowingAddedItems);
        $latestFollowerScanRemovedItems = $sortRelationshipItemsNewest($loadRelationshipItems($latestFollowersList, 'removedItems'), ['removedAt', 'lastSeenAt', 'firstSeenAt']);
        $latestFollowingScanRemovedItems = $sortRelationshipItemsNewest($loadRelationshipItems($latestFollowingList, 'removedItems'), ['removedAt', 'lastSeenAt', 'firstSeenAt']);
        $latestFollowerRemovedItems = $sortRelationshipItemsNewest($loadRelationshipItems($latestFollowersList, 'currentlyRemovedItems'), ['removedAt', 'lastSeenAt', 'firstSeenAt']);
        $latestFollowingRemovedItems = $sortRelationshipItemsNewest($loadRelationshipItems($latestFollowingList, 'currentlyRemovedItems'), ['removedAt', 'lastSeenAt', 'firstSeenAt']);
        $latestFollowerRemovedHistoryItems = $sortRelationshipItemsNewest($loadRelationshipItems($latestFollowersList, 'removedHistoryItems'), ['removedAt', 'lastSeenAt', 'firstSeenAt']);
        $latestFollowingRemovedHistoryItems = $sortRelationshipItemsNewest($loadRelationshipItems($latestFollowingList, 'removedHistoryItems'), ['removedAt', 'lastSeenAt', 'firstSeenAt']);
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
        $inferredInstagramFollowers = $trackedPerson->instagramInferredConnections
            ->where('relationship_type', 'follows_target')
            ->unique('candidate_username')
            ->values();
        $inferredInstagramFollowing = $trackedPerson->instagramInferredConnections
            ->where('relationship_type', 'followed_by_target')
            ->unique('candidate_username')
            ->values();
        $latestScrapePhases = collect(data_get($latestSnapshot?->raw_payload, 'analysisPolicy.scrapePhases', []));
        $latestProfileVisibility = data_get($latestSnapshot?->raw_payload, 'extractedProfile.profileVisibility');
        $latestProfileVisibilityLabel = match ($latestProfileVisibility) {
            'public' => 'Oeffentlich',
            'private' => 'Privat',
            default => 'Unbekannt',
        };
        $countSourceLabels = [
            'body_text_preview' => 'sichtbarer Profiltext',
            'profile_dom' => 'sichtbarer Profil-DOM',
            'description_meta' => 'Meta-Beschreibung',
            'html_document' => 'HTML-Fallback',
            'html_profile_data' => 'Profil-Daten im HTML',
        ];
        $resolveCountSourceLabel = function ($source) use ($countSourceLabels) {
            return $source ? ($countSourceLabels[$source] ?? $source) : 'keine sichtbaren Werte';
        };
    @endphp

    <div
        wire:loading.flex
        wire:target="analyzeInstagram,analyzeInstagramMini,scanPublicProfileConnections"
        class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-950/70 px-4"
    >
        <div class="w-full max-w-md rounded-lg border border-white/20 bg-white p-6 text-center shadow-2xl">
            <div class="mx-auto rounded-full bg-gradient-to-tr from-amber-400 via-rose-500 to-fuchsia-600 p-1">
                <div class="h-10 w-10 animate-spin rounded-full border-4 border-white/70 border-t-slate-950 bg-white"></div>
            </div>
            <div class="mt-4 text-xs font-semibold uppercase tracking-wide text-pink-700" wire:stream="instagram-progress-phase">Start</div>
            <h3 class="mt-1 text-lg font-bold text-slate-950">Instagram-Scan laeuft</h3>
            <p class="mt-2 text-sm leading-6 text-slate-600" wire:stream="instagram-progress-message">
                Profil, Kennzahlen und Listen werden abgearbeitet.
            </p>
            <div class="mt-2 text-xs font-semibold text-slate-500" wire:stream="instagram-progress-live-counts"></div>
            <div class="mt-5">
                <div class="flex items-center justify-between text-xs font-semibold text-slate-500">
                    <span>Fortschritt</span>
                    <span wire:stream="instagram-progress-percent">0%</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200" wire:stream="instagram-progress-bar">
                    <div class="h-full rounded-full bg-gradient-to-r from-rose-500 to-fuchsia-600" style="width: 0%"></div>
                </div>
            </div>
        </div>
    </div>

    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="h-1.5 bg-gradient-to-r from-amber-400 via-rose-500 to-fuchsia-600"></div>
        <div class="p-4 sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <div class="h-24 w-24 shrink-0 rounded-full bg-gradient-to-tr from-amber-400 via-rose-500 to-fuchsia-600 p-1 sm:h-32 sm:w-32">
                    @if($trackedPerson->profile_image_url)
                        <img src="{{ $trackedPerson->profile_image_url }}" alt="{{ $trackedPerson->display_name }}" class="h-full w-full rounded-full border-4 border-white object-cover">
                    @else
                        <div class="flex h-full w-full items-center justify-center rounded-full border-4 border-white bg-slate-100 text-sm font-semibold text-slate-500">
                            IG
                        </div>
                    @endif
                </div>
                <div class="min-w-0">
                    <h2 class="break-words text-2xl font-bold text-slate-950">
                        {{ $trackedPerson->instagram_username ? '@'.$trackedPerson->instagram_username : $trackedPerson->display_name }}
                    </h2>
                    <div class="mt-1 text-sm font-semibold text-slate-700">{{ $trackedPerson->display_name }}</div>
                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600 sm:text-sm">
                        <span>Alias: {{ $trackedPerson->alias ?: '—' }}</span>
                        <span>Ort: {{ $trackedPerson->city ?: '—' }}</span>
                        <span>Land: {{ $trackedPerson->country ?: '—' }}</span>
                        <span>Geburt: {{ optional($trackedPerson->date_of_birth)->format('d.m.Y') ?: '—' }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-1.5 text-xs">
                        @if($trackedPerson->instagram_username)
                            <span class="rounded-lg bg-pink-50 px-3 py-1 font-semibold text-pink-700 ring-1 ring-pink-100">
                                Instagram
                            </span>
                        @endif
                        @if($trackedPerson->monitoring_enabled)
                            <span class="rounded-lg bg-slate-950 px-3 py-1 font-semibold text-white">
                                Dauerbeobachtung aktiv
                            </span>
                        @endif
                        @if($trackedPerson->notify_social_changes && $trackedPerson->notify_instagram_changes)
                            <span class="rounded-lg bg-sky-50 px-3 py-1 font-semibold text-sky-700 ring-1 ring-sky-100">
                                Benachrichtigungen aktiv
                            </span>
                        @endif
                        @if($trackedPerson->last_instagram_analyzed_at)
                            <span class="rounded-lg bg-slate-100 px-3 py-1 font-semibold text-slate-700">
                                {{ $trackedPerson->last_instagram_analyzed_at->copy()->timezone(config('app.timezone'))->diffForHumans() }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:justify-end">
                <button
                    wire:click="analyzeInstagramMini"
                    wire:loading.attr="disabled"
                    wire:target="analyzeInstagramMini"
                    class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50"
                >
                    <span wire:loading.remove wire:target="analyzeInstagramMini">Mini-Scan</span>
                    <span wire:loading wire:target="analyzeInstagramMini">Mini-Scan laeuft...</span>
                </button>
                <button
                    wire:click="analyzeInstagram"
                    wire:loading.attr="disabled"
                    wire:target="analyzeInstagram"
                    class="inline-flex justify-center rounded-lg bg-gradient-to-r from-rose-500 to-fuchsia-600 px-4 py-2 text-sm font-semibold text-white shadow hover:from-rose-600 hover:to-fuchsia-700"
                >
                    <span wire:loading.remove wire:target="analyzeInstagram">Instagram voll analysieren</span>
                    <span wire:loading wire:target="analyzeInstagram">Vollanalyse laeuft...</span>
                </button>
                <button
                    type="button"
                    wire:click="$set('showSettingsModal', true)"
                    class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                >
                    Einstellungen
                </button>
            </div> 
        </div>

        @if($detailStatus)
            <div class="mt-4 rounded-lg border p-3 text-sm {{ $detailStatusClass }}">
                {{ $detailStatus }}
            </div> 
        @endif
        </div>
    </section>

    <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-5">
        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Follower</div>
                <button
                    type="button"
                    wire:click="$set('showFollowersModal', true)"
                    class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    @disabled($latestFollowerItems->isEmpty() && $latestFollowerRemovedItems->isEmpty() && $latestFollowerRemovedHistoryItems->isEmpty())
                >
                    Liste
                </button>
            </div>
            <div class="mt-2 text-xl font-bold text-slate-900 sm:text-2xl">{{ $trackedPerson->instagram_followers_count !== null ? number_format($trackedPerson->instagram_followers_count) : '—' }}</div>
            <div class="mt-1 text-xs text-slate-500">{{ number_format($latestFollowerStats['activeCount']) }} bekannt aktiv/ungeklaert</div>
            <div class="mt-0.5 text-xs text-slate-500">
                {{ number_format($latestFollowerStats['observedCount']) }} zuletzt gesehen
                @if($latestFollowerStats['currentlyRemovedCount'] > 0)
                    &middot; {{ number_format($latestFollowerStats['currentlyRemovedCount']) }} aktuell entfernt
                @endif
                @if($latestFollowerStats['removedHistoryCount'] > 0)
                    &middot; {{ number_format($latestFollowerStats['removedHistoryCount']) }} historisch entfernt
                @endif
            </div>
            @if(data_get($latestFollowersList, 'attempted') && ! data_get($latestFollowersList, 'complete') && (int) data_get($latestFollowersList, 'expectedCount', 0) > 0)
                <div class="mt-1 text-xs font-semibold text-amber-700">
                    Scan unvollstaendig: {{ number_format($latestFollowerStats['observedCount']) }} von {{ number_format((int) data_get($latestFollowersList, 'expectedCount')) }}
                </div>
            @endif
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gefolgt</div>
                <button
                    type="button"
                    wire:click="$set('showFollowingModal', true)"
                    class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    @disabled($latestFollowingItems->isEmpty() && $latestFollowingRemovedItems->isEmpty() && $latestFollowingRemovedHistoryItems->isEmpty())
                >
                    Liste
                </button>
            </div>
            <div class="mt-2 text-xl font-bold text-slate-900 sm:text-2xl">{{ $trackedPerson->instagram_following_count !== null ? number_format($trackedPerson->instagram_following_count) : '—' }}</div>
            <div class="mt-1 text-xs text-slate-500">{{ number_format($latestFollowingStats['activeCount']) }} bekannt aktiv/ungeklaert</div>
            <div class="mt-0.5 text-xs text-slate-500">
                {{ number_format($latestFollowingStats['observedCount']) }} zuletzt gesehen
                @if($latestFollowingStats['currentlyRemovedCount'] > 0)
                    &middot; {{ number_format($latestFollowingStats['currentlyRemovedCount']) }} aktuell entfernt
                @endif
                @if($latestFollowingStats['removedHistoryCount'] > 0)
                    &middot; {{ number_format($latestFollowingStats['removedHistoryCount']) }} historisch entfernt
                @endif
            </div>
            @if(data_get($latestFollowingList, 'attempted') && ! data_get($latestFollowingList, 'complete') && (int) data_get($latestFollowingList, 'expectedCount', 0) > 0)
                <div class="mt-1 text-xs font-semibold text-amber-700">
                    Scan unvollstaendig: {{ number_format($latestFollowingStats['observedCount']) }} von {{ number_format((int) data_get($latestFollowingList, 'expectedCount')) }}
                </div>
            @endif
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Beitraege</div>
            <div class="mt-2 text-xl font-bold text-slate-900 sm:text-2xl">{{ $trackedPerson->instagram_posts_count !== null ? number_format($trackedPerson->instagram_posts_count) : '—' }}</div>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Instagram-Notizen</div>
            <div class="mt-2 text-xl font-bold text-slate-900 sm:text-2xl">{{ number_format($trackedPerson->knownFacts->count()) }}</div>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">IG-Verbindungen</div>
            <div class="mt-2 text-xl font-bold text-slate-900 sm:text-2xl">{{ number_format($trackedPerson->publicProfiles->where('platform', 'instagram')->count()) }}</div>
        </div>
    </section>

    <x-modal wire:model="showFollowersModal" maxWidth="3xl">
        <div x-data="{ search: '', showAdded: false, showScanRemoved: false, showCurrentRemoved: false, showHistory: false }" class="flex max-h-[calc(100vh-2rem)] flex-col overflow-hidden sm:max-h-[85vh]">
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
                            &middot; {{ number_format($latestFollowerStats['currentlyRemovedCount']) }} aktuell entfernt
                        @endif
                        @if($latestFollowerStats['removedHistoryCount'] > 0)
                            &middot; {{ number_format($latestFollowerStats['removedHistoryCount']) }} historisch entfernt
                        @endif
                        @if(data_get($latestFollowersList, 'searchAttempted'))
                            &middot; Suchlauf {{ number_format(collect(data_get($latestFollowersList, 'searchQueries', []))->count()) }} Abfragen
                        @endif
                    </p>
                </div>
                <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Schliessen
                </button>
            </div>

            <div class="overflow-y-auto p-4 sm:p-5">
                <div class="mb-4 flex flex-col gap-2 lg:flex-row">
                    <div class="min-w-0 flex-1">
                        <label class="sr-only" for="followers-search">Followerliste durchsuchen</label>
                        <input
                            id="followers-search"
                            type="search"
                            x-model.debounce.150ms="search"
                            class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500"
                            placeholder="Followerliste durchsuchen..."
                        >
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if($latestFollowerAddedItems->isNotEmpty())
                            <button type="button" x-on:click="showAdded = ! showAdded" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">
                                Neu {{ number_format($latestFollowerAddedItems->count()) }}
                            </button>
                        @endif
                        @if($latestFollowerScanRemovedItems->isNotEmpty())
                            <button type="button" x-on:click="showScanRemoved = ! showScanRemoved" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-800 hover:bg-rose-100">
                                Entfernt {{ number_format($latestFollowerScanRemovedItems->count()) }}
                            </button>
                        @endif
                        @if($latestFollowerRemovedItems->isNotEmpty())
                            <button type="button" x-on:click="showCurrentRemoved = ! showCurrentRemoved" class="rounded-xl border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-800 hover:bg-rose-50">
                                Aktuell entfernt {{ number_format($latestFollowerRemovedItems->count()) }}
                            </button>
                        @endif
                        @if($latestFollowerRemovedHistoryItems->isNotEmpty())
                            <button type="button" x-on:click="showHistory = ! showHistory" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                Historie {{ number_format($latestFollowerRemovedHistoryItems->count()) }}
                            </button>
                        @endif
                    </div>
                </div>

                @if($latestFollowerAddedItems->isNotEmpty() || $latestFollowerScanRemovedItems->isNotEmpty())
                    <div class="mb-4 grid gap-3 md:grid-cols-2">
                        @if($latestFollowerAddedItems->isNotEmpty())
                            <details x-bind:open="search !== '' || showAdded" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950">
                                <summary class="cursor-pointer font-semibold">
                                    {{ number_format($latestFollowerAddedItems->count()) }} neu hinzugefuegt
                                </summary>
                                <div class="mt-3 space-y-2">
                                    @foreach($latestFollowerAddedItems as $addedFollower)
                                        <div
                                            data-relationship-search="{{ e($relationshipSearchText($addedFollower)) }}"
                                            x-show="search === '' || $el.dataset.relationshipSearch.includes(search.toLowerCase())"
                                            class="flex items-center justify-between gap-3 rounded-xl border border-emerald-100 bg-white px-4 py-3 text-sm"
                                        >
                                            <div class="min-w-0">
                                                <div class="truncate font-semibold text-slate-900">{{ '@'.data_get($addedFollower, 'username') }}</div>
                                                @if(data_get($addedFollower, 'displayName'))
                                                    <div class="mt-0.5 truncate text-slate-500">{{ data_get($addedFollower, 'displayName') }}</div>
                                                @endif
                                            </div>
                                            @if(data_get($addedFollower, 'profileUrl'))
                                                <a href="{{ data_get($addedFollower, 'profileUrl') }}" target="_blank" class="shrink-0 rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50">
                                                    Oeffnen
                                                </a>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                        @if($latestFollowerScanRemovedItems->isNotEmpty())
                            <details x-bind:open="search !== '' || showScanRemoved" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-950">
                                <summary class="cursor-pointer font-semibold">
                                    {{ number_format($latestFollowerScanRemovedItems->count()) }} neu entfernt
                                </summary>
                                <div class="mt-3 space-y-2">
                                    @foreach($latestFollowerScanRemovedItems as $removedFollower)
                                        <div
                                            data-relationship-search="{{ e($relationshipSearchText($removedFollower)) }}"
                                            x-show="search === '' || $el.dataset.relationshipSearch.includes(search.toLowerCase())"
                                            class="flex items-center justify-between gap-3 rounded-xl border border-rose-100 bg-white px-4 py-3 text-sm"
                                        >
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
                    </div>
                @endif
                @if(data_get($latestFollowersList, 'attempted') && ! data_get($latestFollowersList, 'complete') && (int) data_get($latestFollowersList, 'expectedCount', 0) > 0)
                    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                        Diese Followerliste wurde von Instagram nicht vollstaendig ausgeliefert. Es wurden {{ number_format($latestFollowerStats['observedCount']) }} von {{ number_format((int) data_get($latestFollowersList, 'expectedCount')) }} sichtbaren Eintraegen gefunden; fehlende Eintraege bleiben deshalb als ungeklaert gespeichert und werden nicht als entfernt gewertet.
                        @if(data_get($latestFollowersList, 'searchAttempted'))
                            <div class="mt-1 text-xs">
                                Suchlauf: {{ number_format(collect(data_get($latestFollowersList, 'searchQueries', []))->count()) }} Abfragen, Tiefe {{ (int) data_get($latestFollowersList, 'searchMaxDepth', 0) ?: 1 }}.
                            </div>
                        @endif
                    </div>
                @endif
                
                @if($latestFollowerItems->isNotEmpty() || $latestFollowerRemovedItems->isNotEmpty() || $latestFollowerRemovedHistoryItems->isNotEmpty())
                @if($latestFollowerRemovedItems->isNotEmpty())
                    <details x-bind:open="search !== '' || showCurrentRemoved" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-950">
                        <summary class="cursor-pointer font-semibold">
                            {{ number_format($latestFollowerRemovedItems->count()) }} aktuell entfernt
                        </summary>
                        <div class="mt-3 space-y-2">
                            @foreach($latestFollowerRemovedItems as $removedFollower)
                                <div
                                    data-relationship-search="{{ e($relationshipSearchText($removedFollower)) }}"
                                    x-show="search === '' || $el.dataset.relationshipSearch.includes(search.toLowerCase())"
                                    class="flex items-center justify-between gap-3 rounded-xl border border-rose-100 bg-white px-4 py-3 text-sm"
                                >
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
                @if($latestFollowerRemovedHistoryItems->isNotEmpty())
                    <details x-bind:open="search !== '' || showHistory" class="mt-4 mb-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-800">
                        <summary class="cursor-pointer font-semibold">
                            {{ number_format($latestFollowerRemovedHistoryItems->count()) }} dauerhaft in der Entfernt-Historie
                        </summary>
                        <div class="mt-3 space-y-2">
                            @foreach($latestFollowerRemovedHistoryItems as $historyFollower)
                                <div
                                    data-relationship-search="{{ e($relationshipSearchText($historyFollower)) }}"
                                    x-show="search === '' || $el.dataset.relationshipSearch.includes(search.toLowerCase())"
                                    class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm"
                                >
                                    <div class="min-w-0">
                                        <div class="truncate font-semibold text-slate-900">{{ '@'.data_get($historyFollower, 'username') }}</div>
                                        @if(data_get($historyFollower, 'displayName'))
                                            <div class="mt-0.5 truncate text-slate-500">{{ data_get($historyFollower, 'displayName') }}</div>
                                        @endif
                                    </div>
                                    @if(data_get($historyFollower, 'profileUrl'))
                                        <a href="{{ data_get($historyFollower, 'profileUrl') }}" target="_blank" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                            Oeffnen
                                        </a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
                    @if($latestFollowerItems->isNotEmpty())
                        <h4 class="mb-2 text-sm font-bold text-slate-900">Aktive und ungeklaerte Follower</h4>
                        <div class="space-y-2">
                            @foreach($latestFollowerItems as $follower)
                                <div
                                    data-relationship-search="{{ e($relationshipSearchText($follower)) }}"
                                    x-show="search === '' || $el.dataset.relationshipSearch.includes(search.toLowerCase())"
                                    class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm"
                                >
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
    </x-modal>

    <x-modal wire:model="showFollowingModal" maxWidth="3xl">
        <div x-data="{ search: '', showAdded: false, showScanRemoved: false, showCurrentRemoved: false, showHistory: false }" class="flex max-h-[calc(100vh-2rem)] flex-col overflow-hidden sm:max-h-[85vh]">
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
                            &middot; {{ number_format($latestFollowingStats['currentlyRemovedCount']) }} aktuell entfernt
                        @endif
                        @if($latestFollowingStats['removedHistoryCount'] > 0)
                            &middot; {{ number_format($latestFollowingStats['removedHistoryCount']) }} historisch entfernt
                        @endif
                        @if(data_get($latestFollowingList, 'searchAttempted'))
                            &middot; Suchlauf {{ number_format(collect(data_get($latestFollowingList, 'searchQueries', []))->count()) }} Abfragen
                        @endif
                    </p>
                </div>
                <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Schliessen
                </button>
            </div>

            <div class="overflow-y-auto p-4 sm:p-5">
                <div class="mb-4 flex flex-col gap-2 lg:flex-row">
                    <div class="min-w-0 flex-1">
                        <label class="sr-only" for="following-search">Gefolgt-Liste durchsuchen</label>
                        <input
                            id="following-search"
                            type="search"
                            x-model.debounce.150ms="search"
                            class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500"
                            placeholder="Gefolgt-Liste durchsuchen..."
                        >
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if($latestFollowingAddedItems->isNotEmpty())
                            <button type="button" x-on:click="showAdded = ! showAdded" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">
                                Neu {{ number_format($latestFollowingAddedItems->count()) }}
                            </button>
                        @endif
                        @if($latestFollowingScanRemovedItems->isNotEmpty())
                            <button type="button" x-on:click="showScanRemoved = ! showScanRemoved" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-800 hover:bg-rose-100">
                                Entfernt {{ number_format($latestFollowingScanRemovedItems->count()) }}
                            </button>
                        @endif
                        @if($latestFollowingRemovedItems->isNotEmpty())
                            <button type="button" x-on:click="showCurrentRemoved = ! showCurrentRemoved" class="rounded-xl border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-800 hover:bg-rose-50">
                                Aktuell entfernt {{ number_format($latestFollowingRemovedItems->count()) }}
                            </button>
                        @endif
                        @if($latestFollowingRemovedHistoryItems->isNotEmpty())
                            <button type="button" x-on:click="showHistory = ! showHistory" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                Historie {{ number_format($latestFollowingRemovedHistoryItems->count()) }}
                            </button>
                        @endif
                    </div>
                </div>

                @if($latestFollowingAddedItems->isNotEmpty() || $latestFollowingScanRemovedItems->isNotEmpty())
                    <div class="mb-4 grid gap-3 md:grid-cols-2">
                        @if($latestFollowingAddedItems->isNotEmpty())
                            <details x-bind:open="search !== '' || showAdded" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950">
                                <summary class="cursor-pointer font-semibold">
                                    {{ number_format($latestFollowingAddedItems->count()) }} neu hinzugefuegt
                                </summary>
                                <div class="mt-3 space-y-2">
                                    @foreach($latestFollowingAddedItems as $addedProfile)
                                        <div
                                            data-relationship-search="{{ e($relationshipSearchText($addedProfile)) }}"
                                            x-show="search === '' || $el.dataset.relationshipSearch.includes(search.toLowerCase())"
                                            class="flex items-center justify-between gap-3 rounded-xl border border-emerald-100 bg-white px-4 py-3 text-sm"
                                        >
                                            <div class="min-w-0">
                                                <div class="truncate font-semibold text-slate-900">{{ '@'.data_get($addedProfile, 'username') }}</div>
                                                @if(data_get($addedProfile, 'displayName'))
                                                    <div class="mt-0.5 truncate text-slate-500">{{ data_get($addedProfile, 'displayName') }}</div>
                                                @endif
                                            </div>
                                            @if(data_get($addedProfile, 'profileUrl'))
                                                <a href="{{ data_get($addedProfile, 'profileUrl') }}" target="_blank" class="shrink-0 rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50">
                                                    Oeffnen
                                                </a>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                        @if($latestFollowingScanRemovedItems->isNotEmpty())
                            <details x-bind:open="search !== '' || showScanRemoved" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-950">
                                <summary class="cursor-pointer font-semibold">
                                    {{ number_format($latestFollowingScanRemovedItems->count()) }} neu entfernt
                                </summary>
                                <div class="mt-3 space-y-2">
                                    @foreach($latestFollowingScanRemovedItems as $removedProfile)
                                        <div
                                            data-relationship-search="{{ e($relationshipSearchText($removedProfile)) }}"
                                            x-show="search === '' || $el.dataset.relationshipSearch.includes(search.toLowerCase())"
                                            class="flex items-center justify-between gap-3 rounded-xl border border-rose-100 bg-white px-4 py-3 text-sm"
                                        >
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
                    </div>
                @endif
                @if(data_get($latestFollowingList, 'attempted') && ! data_get($latestFollowingList, 'complete') && (int) data_get($latestFollowingList, 'expectedCount', 0) > 0)
                    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                        Diese Gefolgt-Liste wurde von Instagram nicht vollstaendig ausgeliefert. Es wurden {{ number_format($latestFollowingStats['observedCount']) }} von {{ number_format((int) data_get($latestFollowingList, 'expectedCount')) }} sichtbaren Eintraegen gefunden; fehlende Eintraege bleiben deshalb als ungeklaert gespeichert und werden nicht als entfernt gewertet.
                        @if(data_get($latestFollowingList, 'searchAttempted'))
                            <div class="mt-1 text-xs">
                                Suchlauf: {{ number_format(collect(data_get($latestFollowingList, 'searchQueries', []))->count()) }} Abfragen, Tiefe {{ (int) data_get($latestFollowingList, 'searchMaxDepth', 0) ?: 1 }}.
                            </div>
                        @endif
                    </div>
                @endif

                @if($latestFollowingItems->isNotEmpty() || $latestFollowingRemovedItems->isNotEmpty() || $latestFollowingRemovedHistoryItems->isNotEmpty())
                @if($latestFollowingRemovedItems->isNotEmpty())
                    <details x-bind:open="search !== '' || showCurrentRemoved" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-950">
                        <summary class="cursor-pointer font-semibold">
                            {{ number_format($latestFollowingRemovedItems->count()) }} aktuell entfernt
                        </summary>
                        <div class="mt-3 space-y-2">
                            @foreach($latestFollowingRemovedItems as $removedProfile)
                                <div
                                    data-relationship-search="{{ e($relationshipSearchText($removedProfile)) }}"
                                    x-show="search === '' || $el.dataset.relationshipSearch.includes(search.toLowerCase())"
                                    class="flex items-center justify-between gap-3 rounded-xl border border-rose-100 bg-white px-4 py-3 text-sm"
                                >
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
                @if($latestFollowingRemovedHistoryItems->isNotEmpty())
                    <details x-bind:open="search !== '' || showHistory" class="mt-4 mb-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-800">
                        <summary class="cursor-pointer font-semibold">
                            {{ number_format($latestFollowingRemovedHistoryItems->count()) }} dauerhaft in der Entfernt-Historie
                        </summary>
                        <div class="mt-3 space-y-2">
                            @foreach($latestFollowingRemovedHistoryItems as $historyProfile)
                                <div
                                    data-relationship-search="{{ e($relationshipSearchText($historyProfile)) }}"
                                    x-show="search === '' || $el.dataset.relationshipSearch.includes(search.toLowerCase())"
                                    class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm"
                                >
                                    <div class="min-w-0">
                                        <div class="truncate font-semibold text-slate-900">{{ '@'.data_get($historyProfile, 'username') }}</div>
                                        @if(data_get($historyProfile, 'displayName'))
                                            <div class="mt-0.5 truncate text-slate-500">{{ data_get($historyProfile, 'displayName') }}</div>
                                        @endif
                                    </div>
                                    @if(data_get($historyProfile, 'profileUrl'))
                                        <a href="{{ data_get($historyProfile, 'profileUrl') }}" target="_blank" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                            Oeffnen
                                        </a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
                    @if($latestFollowingItems->isNotEmpty())
                        <h4 class="mb-2 text-sm font-bold text-slate-900">Aktive und ungeklaerte Gefolgt-Profile</h4>
                        <div class="space-y-2">
                            @foreach($latestFollowingItems as $followedProfile)
                                <div
                                    data-relationship-search="{{ e($relationshipSearchText($followedProfile)) }}"
                                    x-show="search === '' || $el.dataset.relationshipSearch.includes(search.toLowerCase())"
                                    class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm"
                                >
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
    </x-modal>

    <x-modal wire:model="showSettingsModal" maxWidth="2xl">
        <div class="flex max-h-[calc(100vh-2rem)] flex-col overflow-hidden">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5 sm:py-4">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Instagram-Einstellungen</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Profilhandle, Dauerbeobachtung und Instagram-Benachrichtigungen.
                    </p>
                </div>
                <button type="button" x-on:click="$dispatch('close')" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
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
                            <input type="text" wire:model.defer="instagram_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500" placeholder="@username">
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
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Zustellung</label>
                            <select wire:model.defer="notification_delivery_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                <option value="both">Interne Nachricht und E-Mail</option>
                                <option value="message">Nur interne Nachricht</option>
                                <option value="mail">Nur E-Mail</option>
                            </select>
                            @error('notification_delivery_type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="monitoring_enabled" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span class="font-medium">Dauerbeobachtung aktivieren</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_social_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span class="font-medium">Benachrichtigungen fuer Instagram-Aenderungen aktivieren</span>
                        </label>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Instagram-Kanal</div>
                    <div class="mt-2 grid gap-2">
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_instagram_changes" class="rounded border-slate-300 text-pink-600 focus:ring-pink-500">
                            <span>Instagram-Aenderungen melden</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 px-4 py-3 sm:flex-row sm:justify-end sm:px-5">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Abbrechen
                </button>
                <button
                    type="button"
                    wire:click="saveTrackedPerson"
                    wire:loading.attr="disabled"
                    wire:target="saveTrackedPerson"
                    class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Einstellungen speichern
                </button>
            </div>
        </div>
    </x-modal>

    <section class="grid gap-4 2xl:grid-cols-[minmax(0,1.05fr)_minmax(320px,0.8fr)]">
        <div class="space-y-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Instagram-Notizen</h3>
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
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">Bekannte Instagram-Profile</h3>
                        <p class="mt-1 text-sm text-slate-600">
                            Hier verknuepfst du andere bereits beobachtete Instagram-Profile, die mit diesem Profil eng verbunden sind.
                        </p>
                    </div>
                    <button
                        type="button"
                        wire:click="scanPublicProfileConnections"
                        wire:loading.attr="disabled"
                        wire:target="scanPublicProfileConnections"
                        @disabled($trackedPerson->publicProfiles->isEmpty() || ! $trackedPerson->instagram_username)
                        class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Listenverbindungen pruefen
                    </button>
                </div>

                <div class="mt-3 space-y-2">
                    @forelse($trackedPerson->publicProfiles as $publicProfile)
                        @php
                            $latestConnectionScan = $publicProfile->latestInstagramConnectionScan;
                            $connectionStatusClass = match ($latestConnectionScan?->status_level) {
                                'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                                'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
                                'error' => 'border-rose-200 bg-rose-50 text-rose-900',
                                default => 'border-slate-200 bg-white text-slate-600',
                            };
                        @endphp
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
                                    @if($latestConnectionScan)
                                        @php
                                            $latestInferredFollowerCount = count(data_get($latestConnectionScan->raw_payload, 'inferredFollowers', []));
                                            $latestInferredFollowingCount = count(data_get($latestConnectionScan->raw_payload, 'inferredFollowing', []));
                                        @endphp
                                        <div class="mt-3 rounded-xl border px-3 py-2 text-xs {{ $connectionStatusClass }}">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-semibold">Teilrekonstruktion</span>
                                                <span>{{ $latestConnectionScan->analyzed_at ? $latestConnectionScan->analyzed_at->timezone(config('app.timezone'))->format('d.m.Y H:i') : '-' }}</span>
                                            </div>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                @if($latestInferredFollowerCount > 0)
                                                    <span class="rounded-full bg-white/80 px-2 py-1 font-semibold">{{ $latestInferredFollowerCount }} moegliche Follower</span>
                                                @endif
                                                @if($latestInferredFollowingCount > 0)
                                                    <span class="rounded-full bg-white/80 px-2 py-1 font-semibold">{{ $latestInferredFollowingCount }} moeglich gefolgt</span>
                                                @endif
                                                @if($latestInferredFollowerCount === 0 && $latestInferredFollowingCount === 0)
                                                    <span class="rounded-full bg-white/80 px-2 py-1 font-semibold">Keine Treffer in Kandidatenlisten</span>
                                                @endif
                                            </div>
                                            <div class="mt-2 text-[11px]">
                                                Kandidaten: {{ data_get($latestConnectionScan->raw_payload, 'candidatesChecked', 0) }}
                                                / private oder gesperrte Profile: {{ data_get($latestConnectionScan->raw_payload, 'candidatesSkippedPrivate', 0) }}
                                                / Rate-Limit: {{ data_get($latestConnectionScan->raw_payload, 'candidatesRateLimited', 0) }}
                                            </div>
                                        </div>
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
                        <p class="text-sm text-slate-500">Bisher wurden noch keine beobachteten Instagram-Profile als Verbindung hinterlegt.</p>
                    @endforelse
                </div>

                <div class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Beobachtetes Profil</label>
                        <select wire:model.defer="publicProfileTrackedPersonId" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500">
                            <option value="">Profil auswaehlen</option>
                            @foreach($publicProfileCandidates as $candidate)
                                @php
                                    $candidateVisibility = data_get($candidate->latestInstagramSnapshot?->raw_payload, 'extractedProfile.profileVisibility');
                                    $candidateVisibilityLabel = match ($candidateVisibility) {
                                        'public' => 'oeffentlich',
                                        'private' => 'privat',
                                        default => 'unbekannt',
                                    };
                                @endphp
                                <option value="{{ $candidate->id }}">
                                    {{ '@'.$candidate->instagram_username }} - {{ $candidate->display_name }} ({{ $candidateVisibilityLabel }})
                                </option>
                            @endforeach
                        </select>
                        @error('publicProfileTrackedPersonId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        @if($publicProfileCandidates->isEmpty())
                            <p class="mt-2 text-xs text-amber-700">
                                Es gibt noch kein weiteres beobachtetes Instagram-Profil, das im letzten Scan als oeffentlich erkannt wurde.
                            </p>
                        @endif
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Beziehungsart</label>
                            <select wire:model.defer="publicProfileRelationshipType" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500">
                                <option value="follows_target">Folgt der Person</option>
                                <option value="followed_by_target">Wird von der Person gefolgt</option>
                                <option value="mutual">Gegenseitige Verbindung</option>
                                <option value="public_connection">Allgemeine oeffentliche Verbindung</option>
                            </select>
                            @error('publicProfileRelationshipType') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Quelle</div>
                            <div class="mt-1 font-semibold text-slate-900">Beobachtete Profile</div>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Notizen</label>
                        <textarea wire:model.defer="publicProfileNotes" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500" placeholder="Warum dieses beobachtete Profil relevant ist, z. B. enge Verbindung, gegenseitige Erwaehnungen oder bekannte Beziehung"></textarea>
                        @error('publicProfileNotes') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end">
                        <button wire:click="savePublicProfile" @disabled($publicProfileCandidates->isEmpty()) class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">
                            Verbindung speichern
                        </button>
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 class="text-sm font-bold text-slate-900">Analysierte Listenverbindungen</h4>
                        <span class="text-xs text-slate-500">Letzte 20 Scans</span>
                    </div>
                    <div class="mt-3 space-y-2">
                        @forelse($trackedPerson->instagramPublicProfileScans as $connectionScan)
                            @php
                                $scanInferredFollowerCount = count(data_get($connectionScan->raw_payload, 'inferredFollowers', []));
                                $scanInferredFollowingCount = count(data_get($connectionScan->raw_payload, 'inferredFollowing', []));
                                $scanStatusClass = match ($connectionScan->status_level) {
                                    'success' => 'border-emerald-200 bg-white text-emerald-900',
                                    'partial' => 'border-amber-200 bg-white text-amber-950',
                                    'error' => 'border-rose-200 bg-white text-rose-900',
                                    default => 'border-slate-200 bg-white text-slate-700',
                                };
                            @endphp
                            <div class="rounded-xl border px-3 py-2 text-xs {{ $scanStatusClass }}">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-slate-950">
                                            {{ $connectionScan->publicProfile?->display_name ?: '@'.$connectionScan->public_username }}
                                            <span class="font-normal text-slate-500">({{ '@'.$connectionScan->public_username }})</span>
                                        </div>
                                        <div class="mt-1">{{ $connectionScan->relation_label }}</div>
                                        @if($connectionScan->status_message)
                                            <div class="mt-1 text-slate-500">{{ $connectionScan->status_message }}</div>
                                        @endif
                                    </div>
                                    <div class="text-right text-slate-500">
                                        <div>{{ $connectionScan->analyzed_at ? $connectionScan->analyzed_at->timezone(config('app.timezone'))->format('d.m.Y H:i') : '-' }}</div>
                                        <div class="mt-1">
                                            Kandidaten {{ data_get($connectionScan->raw_payload, 'candidatesChecked', 0) }}
                                            / Treffer {{ $scanInferredFollowerCount + $scanInferredFollowingCount }}
                                            / Rate-Limit {{ data_get($connectionScan->raw_payload, 'candidatesRateLimited', 0) }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">Noch keine Public-Profile-Listenverbindungen analysiert.</p>
                        @endforelse
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 class="text-sm font-bold text-slate-900">Teilweise rekonstruierte private Listen</h4>
                        <span class="text-xs text-slate-500">
                            {{ $inferredInstagramFollowers->count() }} Follower / {{ $inferredInstagramFollowing->count() }} Gefolgt
                        </span>
                    </div>
                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Moegliche Follower des privaten Profils</div>
                            <div class="mt-3 space-y-2">
                                @forelse($inferredInstagramFollowers->take(40) as $connection)
                                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs">
                                        <div>
                                            <div class="font-semibold text-slate-900">{{ $connection->display_handle }}</div>
                                            @if($connection->candidate_display_name)
                                                <div class="text-slate-500">{{ $connection->candidate_display_name }}</div>
                                            @endif
                                            <div class="text-slate-500">Quelle: {{ '@'.$connection->source_public_username }}</div>
                                        </div>
                                        <div class="text-right text-slate-500">
                                            {{ $connection->last_seen_at ? $connection->last_seen_at->timezone(config('app.timezone'))->diffForHumans() : '-' }}
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">Noch keine moeglichen Follower ueber bekannte Profile gefunden.</p>
                                @endforelse
                            </div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Moeglich vom privaten Profil gefolgt</div>
                            <div class="mt-3 space-y-2">
                                @forelse($inferredInstagramFollowing->take(40) as $connection)
                                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs">
                                        <div>
                                            <div class="font-semibold text-slate-900">{{ $connection->display_handle }}</div>
                                            @if($connection->candidate_display_name)
                                                <div class="text-slate-500">{{ $connection->candidate_display_name }}</div>
                                            @endif
                                            <div class="text-slate-500">Quelle: {{ '@'.$connection->source_public_username }}</div>
                                        </div>
                                        <div class="text-right text-slate-500">
                                            {{ $connection->last_seen_at ? $connection->last_seen_at->timezone(config('app.timezone'))->diffForHumans() : '-' }}
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">Noch keine moeglich gefolgten Profile ueber bekannte Profile gefunden.</p>
                                @endforelse
                            </div>
                        </div>
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
                                Debug-Screenshot oeffnen
                            </a>
                        @endif
                    </div>

                    <div class="mt-3 grid gap-2 text-sm text-slate-700">
                        <p><span class="font-semibold">Profilname:</span> {{ $latestSnapshot->full_name ?: '—' }}</p>
                        <p><span class="font-semibold">Profilstatus:</span> {{ $latestProfileVisibilityLabel }}</p>
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
                                    @php
                                        $phaseScreenshotPath = data_get($phase, 'screenshotPath');
                                    @endphp
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
                                            @if($phaseScreenshotPath)
                                                | <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($phaseScreenshotPath) }}" target="_blank" class="font-semibold text-slate-700 underline decoration-slate-300 underline-offset-2">Screenshot</a>
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
