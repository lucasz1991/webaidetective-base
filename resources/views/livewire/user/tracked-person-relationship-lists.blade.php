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
                    <div class="mb-4 flex flex-col gap-2 lg:flex-row">
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
                        <div class="flex flex-wrap gap-2">
                            @if($listData['addedItems']->isNotEmpty())
                                <button type="button" x-on:click="showAdded = ! showAdded" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">
                                    Neu {{ number_format($listData['addedItems']->count()) }}
                                </button>
                            @endif
                            @if($listData['scanRemovedItems']->isNotEmpty())
                                <button type="button" x-on:click="showScanRemoved = ! showScanRemoved" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-800 hover:bg-rose-100">
                                    Entfernt {{ number_format($listData['scanRemovedItems']->count()) }}
                                </button>
                            @endif
                            @if($listData['removedItems']->isNotEmpty())
                                <button type="button" x-on:click="showCurrentRemoved = ! showCurrentRemoved" class="rounded-xl border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-800 hover:bg-rose-50">
                                    Aktuell entfernt {{ number_format($listData['removedItems']->count()) }}
                                </button>
                            @endif
                            @if($listData['removedHistoryItems']->isNotEmpty())
                                <button type="button" x-on:click="showHistory = ! showHistory" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Historie {{ number_format($listData['removedHistoryItems']->count()) }}
                                </button>
                            @endif
                        </div>
                    </div>

                    @if($listData['addedItems']->isNotEmpty() || $listData['scanRemovedItems']->isNotEmpty())
                        <div class="mb-4 grid gap-3 md:grid-cols-2">
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
                            <h4 class="mb-2 text-sm font-bold text-slate-900">{{ $listData['activeTitle'] }}</h4>
                            <div class="space-y-2">
                                @foreach($listData['activeItems'] as $item)
                                    <x-instagram.profile-list-item
                                        :item="$item"
                                        :tone="$tone"
                                        x-show="search === '' || $el.dataset.profileSearch.includes(search.toLowerCase())"
                                    />
                                @endforeach
                            </div>
                        @endif
                    @else
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                            {{ $listData['emptyText'] }}
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
