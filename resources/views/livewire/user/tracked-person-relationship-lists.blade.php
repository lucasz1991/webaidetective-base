<div>
    @foreach([
        'showFollowersModal' => $followersListData,
        'showFollowingModal' => $followingListData,
    ] as $modalModel => $listData)
        @php
            $listType = $listData['listType'];
            $tone = $listType === 'followers' ? 'emerald' : 'sky';
            $relationshipList = $listData['relationshipList'];
            $stats = $listData['stats'];
            $controls = $listData['controls'];
            $visibilityCounts = $controls['visibilityCounts'];
            $profileCounts = $controls['profileCounts'];
            $visibilityOptions = [
                'all' => ['Alle', $visibilityCounts['all']],
                'public' => ['Oeffentlich', $visibilityCounts['public']],
                'private' => ['Privat', $visibilityCounts['private']],
                'unknown' => ['Unbekannt', $visibilityCounts['unknown']],
            ];
            $profileOptions = [
                'all' => ['Alle Profile', $profileCounts['all']],
                'tracked' => ['Beobachtet', $profileCounts['tracked']],
                'untracked' => ['Nicht beobachtet', $profileCounts['untracked']],
                'reconstructed' => ['Rekonstruiert', $profileCounts['reconstructed']],
                'passive' => ['Passiv', $profileCounts['passive']],
            ];
            $sortOptions = [
                'default' => 'Standard',
                'newest' => 'Neueste zuerst',
                'username' => 'Username A-Z',
                'visibility' => 'Oeffentlich zuerst',
                'followers' => 'Follower absteigend',
                'following' => 'Gefolgt absteigend',
                'posts' => 'Beitraege absteigend',
            ];
        @endphp

        <x-modal wire:model="{{ $modalModel }}" maxWidth="3xl">
            <div wire:poll.5s.visible x-data="{ search: '', showAdded: false, showScanRemoved: false, showCurrentRemoved: false, showHistory: false }" class="flex max-h-[calc(100vh-2rem)] flex-col overflow-hidden sm:max-h-[85vh]">
                <div class="flex flex-col gap-3 border-b border-slate-200 px-4 py-3 sm:px-5 sm:py-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">{{ $listData['title'] }}</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ number_format($stats['activeCount']) }} bekannt aktiv/ungeklaert
                            &middot; {{ number_format($stats['observedCount']) }} zuletzt gesehen
                            @if(data_get($relationshipList, 'maxItems'))
                                &middot; Limit {{ number_format((int) data_get($relationshipList, 'maxItems')) }}
                            @endif
                            @if($stats['currentlyRemovedCount'] > 0)
                                &middot; {{ number_format($stats['currentlyRemovedCount']) }} aktuell entfernt
                            @endif
                            @if($stats['removedHistoryCount'] > 0)
                                &middot; {{ number_format($stats['removedHistoryCount']) }} historisch entfernt
                            @endif
                            @if(data_get($relationshipList, 'searchAttempted'))
                                &middot; Suchlauf {{ number_format(collect(data_get($relationshipList, 'searchQueries', []))->count()) }} Abfragen
                            @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        @if($latestProfileIsPublic)
                            <button
                                type="button"
                                wire:click="$dispatch('scan-instagram-relationship-list', { relationship: '{{ $listType }}' })"
                                @disabled(! $trackedPerson->instagram_username)
                                class="inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50 {{ $listType === 'followers' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100' : 'border-sky-200 bg-sky-50 text-sky-700 hover:bg-sky-100' }}"
                            >
                                Liste scannen
                            </button>
                        @endif
                        <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Schliessen
                        </button>
                    </div>
                </div>

                <div class="overflow-y-auto p-4 sm:p-5">
                    <div class="mb-4">
                        <div class="flex gap-2">
                            <div class="min-w-0 flex-1">
                                <label class="sr-only" for="{{ $listType }}-search">{{ $listData['title'] }} durchsuchen</label>
                                <input
                                    id="{{ $listType }}-search"
                                    type="search"
                                    x-model.debounce.150ms="search"
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500"
                                    placeholder="{{ $listData['searchPlaceholder'] }}"
                                >
                            </div>

                            <x-ui.dropdown.anchor-dropdown
                                align="right"
                                width="auto"
                                :offset="8"
                                dropdown-classes=""
                                content-classes="w-56 rounded-xl border border-slate-200 bg-white p-2"
                            >
                                <x-slot name="trigger">
                                    <button
                                        type="button"
                                        x-bind:aria-expanded="open"
                                        title="Sortierung"
                                        aria-label="Sortierung"
                                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
                                    >
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M7 4v16M7 20l-3-3M7 20l3-3M17 4l3 3M17 4l-3 3M17 4v16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </button>
                                </x-slot>

                            <x-slot name="content">
                                <div class="space-y-1">
                                    @foreach($sortOptions as $sort => $label)
                                        <button
                                            type="button"
                                            wire:click="setRelationshipSort('{{ $listType }}', '{{ $sort }}')"
                                            x-on:click="open = false"
                                            class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold transition {{ $controls['sort'] === $sort ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50' }}"
                                        >
                                            <span>{{ $label }}</span>
                                            @if($controls['sort'] === $sort)
                                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                    <path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                            </x-slot>
                        </x-ui.dropdown.anchor-dropdown>

                        <x-ui.dropdown.anchor-dropdown
                            align="right"
                            width="auto"
                            :offset="8"
                            dropdown-classes=""
                            content-classes="w-64 rounded-xl border border-slate-200 bg-white p-2"
                        >
                            <x-slot name="trigger">
                                <button
                                    type="button"
                                    x-bind:aria-expanded="open"
                                    title="Sichtbarkeit filtern"
                                    aria-label="Sichtbarkeit filtern"
                                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <div class="space-y-1">
                                    @foreach($visibilityOptions as $filter => [$label, $count])
                                        <button
                                            type="button"
                                            wire:click="setRelationshipVisibilityFilter('{{ $listType }}', '{{ $filter }}')"
                                            x-on:click="open = false"
                                            class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold transition {{ $controls['visibilityFilter'] === $filter ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50' }}"
                                        >
                                            <span>{{ $label }}</span>
                                            <span class="rounded-md px-2 py-0.5 text-xs {{ $controls['visibilityFilter'] === $filter ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-600' }}">{{ number_format($count, 0, ',', '.') }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </x-slot>
                        </x-ui.dropdown.anchor-dropdown>

                        <x-ui.dropdown.anchor-dropdown
                            align="right"
                            width="auto"
                            :offset="8"
                            dropdown-classes=""
                            content-classes="w-64 rounded-xl border border-slate-200 bg-white p-2"
                        >
                            <x-slot name="trigger">
                                <button
                                    type="button"
                                    x-bind:aria-expanded="open"
                                    title="Profile filtern"
                                    aria-label="Profile filtern"
                                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM19 19c0-1.6-.9-3-2.2-3.6M17 5.2a3 3 0 0 1 0 5.6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <div class="space-y-1">
                                    @foreach($profileOptions as $filter => [$label, $count])
                                        <button
                                            type="button"
                                            wire:click="setRelationshipProfileFilter('{{ $listType }}', '{{ $filter }}')"
                                            x-on:click="open = false"
                                            class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold transition {{ $controls['profileFilter'] === $filter ? 'bg-indigo-600 text-white' : 'text-slate-700 hover:bg-slate-50' }}"
                                        >
                                            <span>{{ $label }}</span>
                                            <span class="rounded-md px-2 py-0.5 text-xs {{ $controls['profileFilter'] === $filter ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-600' }}">{{ number_format($count, 0, ',', '.') }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </x-slot>
                        </x-ui.dropdown.anchor-dropdown>

                        <x-ui.dropdown.anchor-dropdown
                            align="right"
                            width="auto"
                            :offset="8"
                            dropdown-classes=""
                            content-classes="w-64 rounded-xl border border-slate-200 bg-white p-2"
                        >
                            <x-slot name="trigger">
                                <button
                                    type="button"
                                    x-bind:aria-expanded="open"
                                    title="Listenabschnitte"
                                    aria-label="Listenabschnitte"
                                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <div class="space-y-1">
                                    @if($listData['addedItems']->isNotEmpty())
                                        <button type="button" x-on:click="showAdded = ! showAdded" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold text-emerald-800 transition hover:bg-emerald-50">
                                            <span>Neu</span>
                                            <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">{{ number_format($listData['addedItems']->count()) }}</span>
                                        </button>
                                    @endif
                                    @if($listData['scanRemovedItems']->isNotEmpty())
                                        <button type="button" x-on:click="showScanRemoved = ! showScanRemoved" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold text-rose-800 transition hover:bg-rose-50">
                                            <span>Entfernt</span>
                                            <span class="rounded-md bg-rose-50 px-2 py-0.5 text-xs text-rose-700">{{ number_format($listData['scanRemovedItems']->count()) }}</span>
                                        </button>
                                    @endif
                                    @if($listData['removedItems']->isNotEmpty())
                                        <button type="button" x-on:click="showCurrentRemoved = ! showCurrentRemoved" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold text-rose-800 transition hover:bg-rose-50">
                                            <span>Aktuell entfernt</span>
                                            <span class="rounded-md bg-rose-50 px-2 py-0.5 text-xs text-rose-700">{{ number_format($listData['removedItems']->count()) }}</span>
                                        </button>
                                    @endif
                                    @if($listData['removedHistoryItems']->isNotEmpty())
                                        <button type="button" x-on:click="showHistory = ! showHistory" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                                            <span>Historie</span>
                                            <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ number_format($listData['removedHistoryItems']->count()) }}</span>
                                        </button>
                                    @endif
                                    @if($listData['addedItems']->isEmpty() && $listData['scanRemovedItems']->isEmpty() && $listData['removedItems']->isEmpty() && $listData['removedHistoryItems']->isEmpty())
                                        <div class="px-3 py-2 text-sm font-semibold text-slate-500">Keine weiteren Abschnitte</div>
                                    @endif
                                </div>
                            </x-slot>
                        </x-ui.dropdown.anchor-dropdown>
                        </div>

                        <div class="mt-2 flex flex-wrap gap-2 text-xs font-semibold text-slate-500">
                            <span>{{ number_format($controls['filteredActiveCount'], 0, ',', '.') }} von {{ number_format($controls['totalActiveCount'], 0, ',', '.') }} aktiven Eintraegen nach Filter</span>
                            @if($controls['visibilityFilter'] !== 'all' || $controls['profileFilter'] !== 'all' || $controls['sort'] !== 'default')
                                <button
                                    type="button"
                                    wire:click="resetRelationshipControls('{{ $listType }}')"
                                    title="Filter und Sortierung zuruecksetzen"
                                    aria-label="Filter und Sortierung zuruecksetzen"
                                    class="inline-flex h-6 w-6 items-center justify-center rounded-md text-pink-700 hover:bg-pink-50 hover:text-pink-800"
                                >
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M3 12a9 9 0 1 0 3-6.7M3 4v6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                            @endif
                        </div>
                    </div>

                    @if($listData['addedItems']->isNotEmpty() || $listData['scanRemovedItems']->isNotEmpty())
                        <div class="mb-4 grid gap-3 ">
                            @if($listData['addedItems']->isNotEmpty())
                                <details x-bind:open="search !== '' || showAdded" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950">
                                    <summary class="cursor-pointer font-semibold">
                                        {{ number_format($listData['addedItems']->count()) }} neu hinzugefuegt
                                    </summary>
                                    <div class="mt-3 space-y-2">
                                        @foreach($listData['addedItems'] as $item)
                                            <x-instagram.profile-list-item
                                                :item="$item"
                                                tone="emerald"
                                                x-show="search === '' || $el.dataset.profileSearch.includes(search.toLowerCase())"
                                            />
                                        @endforeach
                                    </div>
                                </details>
                            @endif

                            @if($listData['scanRemovedItems']->isNotEmpty())
                                <details x-bind:open="search !== '' || showScanRemoved" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-950">
                                    <summary class="cursor-pointer font-semibold">
                                        {{ number_format($listData['scanRemovedItems']->count()) }} neu entfernt
                                    </summary>
                                    <div class="mt-3 space-y-2">
                                        @foreach($listData['scanRemovedItems'] as $item)
                                            <x-instagram.profile-list-item
                                                :item="$item"
                                                tone="rose"
                                                x-show="search === '' || $el.dataset.profileSearch.includes(search.toLowerCase())"
                                            />
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        </div>
                    @endif

                    @if(data_get($relationshipList, 'attempted') && ! data_get($relationshipList, 'complete') && (int) data_get($relationshipList, 'expectedCount', 0) > 0)
                        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                            Diese {{ $listData['title'] }} wurde von Instagram nicht vollstaendig ausgeliefert. Es wurden {{ number_format($stats['observedCount']) }} von {{ number_format((int) data_get($relationshipList, 'expectedCount')) }} sichtbaren Eintraegen gefunden; fehlende Eintraege bleiben deshalb als ungeklaert gespeichert und werden nicht als entfernt gewertet.
                            @if(data_get($relationshipList, 'searchAttempted'))
                                <div class="mt-1 text-xs">
                                    Suchlauf: {{ number_format(collect(data_get($relationshipList, 'searchQueries', []))->count()) }} Abfragen, Tiefe {{ (int) data_get($relationshipList, 'searchMaxDepth', 0) ?: 1 }}.
                                </div>
                            @endif
                        </div>
                    @endif

                    @if($listData['activeItems']->isNotEmpty() || $listData['removedItems']->isNotEmpty() || $listData['removedHistoryItems']->isNotEmpty())
                        @if($listData['removedItems']->isNotEmpty())
                            <details x-bind:open="search !== '' || showCurrentRemoved" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-950">
                                <summary class="cursor-pointer font-semibold">
                                    {{ number_format($listData['removedItems']->count()) }} aktuell entfernt
                                </summary>
                                <div class="mt-3 space-y-2">
                                    @foreach($listData['removedItems'] as $item)
                                        <x-instagram.profile-list-item
                                            :item="$item"
                                            tone="rose"
                                            x-show="search === '' || $el.dataset.profileSearch.includes(search.toLowerCase())"
                                        />
                                    @endforeach
                                </div>
                            </details>
                        @endif

                        @if($listData['removedHistoryItems']->isNotEmpty())
                            <details x-bind:open="search !== '' || showHistory" class="mb-4 mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-800">
                                <summary class="cursor-pointer font-semibold">
                                    {{ number_format($listData['removedHistoryItems']->count()) }} dauerhaft in der Entfernt-Historie
                                </summary>
                                <div class="mt-3 space-y-2">
                                    @foreach($listData['removedHistoryItems'] as $item)
                                        <x-instagram.profile-list-item
                                            :item="$item"
                                            x-show="search === '' || $el.dataset.profileSearch.includes(search.toLowerCase())"
                                        />
                                    @endforeach
                                </div>
                            </details>
                        @endif

                        @if($listData['activeItems']->isNotEmpty())
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <h4 class="text-sm font-bold text-slate-900">{{ $listData['activeTitle'] }}</h4>
                                <div class="text-xs font-semibold text-slate-500">
                                    {{ number_format($listData['visibleActiveCount'], 0, ',', '.') }} / {{ number_format($controls['filteredActiveCount'], 0, ',', '.') }}
                                </div>
                            </div>
                            <div class="space-y-2">
                                @foreach($listData['visibleActiveItems'] as $item)
                                    <x-instagram.profile-list-item
                                        :item="$item"
                                        :tone="$tone"
                                        x-show="search === '' || $el.dataset.profileSearch.includes(search.toLowerCase())"
                                    />
                                @endforeach
                            </div>
                            @if($listData['hasMoreActiveItems'])
                                <div
                                    wire:key="{{ $listType }}-relationship-load-more-{{ $listData['visibleLimit'] }}"
                                    x-intersect.full.once="$wire.loadMoreRelationshipList('{{ $listType }}')"
                                    class="mt-3 flex items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-3 text-xs font-semibold text-slate-500"
                                >
                                    <span class="h-4 w-4 animate-spin rounded-full border-2 border-slate-200 border-t-slate-500"></span>
                                    <span>Weitere {{ number_format($listData['itemsPerPage'], 0, ',', '.') }} laden</span>
                                </div>
                            @endif
                        @endif
                    @else
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                            @if($controls['totalActiveCount'] > 0 && $controls['filteredActiveCount'] === 0)
                                Keine aktiven Eintraege fuer die aktuellen Filter.
                            @else
                                {{ $listData['emptyText'] }}
                            @endif
                            @if(data_get($relationshipList, 'reason'))
                                Grund: {{ data_get($relationshipList, 'reason') }}
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </x-modal>
    @endforeach
</div>
