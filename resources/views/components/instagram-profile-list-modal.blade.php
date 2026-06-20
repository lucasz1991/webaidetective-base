@props([
    'model',
    'title',
    'scan' => null,
    'items' => null,
    'stats' => [],
    'hasData' => null,
    'listType' => 'followers',
    'visibleLimit' => 50,
    'itemsPerPage' => 50,
])

@php
    $sourceItems = $items !== null ? collect($items) : ($scan?->items ?? collect());
    $visibleLimit = max(1, (int) $visibleLimit);
    $itemsPerPage = max(1, (int) $itemsPerPage);

    $rows = $sourceItems
        ->values()
        ->map(function ($item) use ($listType): ?array {
            $related = is_object($item) && method_exists($item, 'relationLoaded') && $item->relationLoaded('relatedInstagramProfile')
                ? $item->relatedInstagramProfile
                : null;
            $raw = is_object($item) && isset($item->raw_item) && is_array($item->raw_item) ? $item->raw_item : [];
            $username = ltrim(trim((string) (
                data_get($raw, 'username')
                ?? data_get($item, 'username')
                ?? data_get($item, 'username_snapshot')
                ?? $related?->username
                ?? ''
            )), '@');

            if ($username === '') {
                return null;
            }

            $displayName = trim((string) (
                data_get($raw, 'displayName')
                ?? data_get($item, 'displayName')
                ?? data_get($item, 'display_name_snapshot')
                ?? $related?->display_name
                ?? $related?->full_name
                ?? ''
            ));
            $itemStatus = (string) (data_get($item, 'itemStatus') ?? data_get($item, 'item_status') ?? 'observed');
            $passive = (bool) data_get($item, 'passive', false);
            $statusLabel = data_get($item, 'statusLabel') ?: match ($itemStatus) {
                'added' => 'Neu',
                'removed' => 'Entfernt',
                default => ($passive ? ($listType === 'followers' ? 'Passiver Follower' : 'Passiv gefolgt') : null),
            };
            $statusTone = data_get($item, 'statusTone') ?: match ($itemStatus) {
                'added' => 'emerald',
                'removed' => 'rose',
                default => ($passive ? 'sky' : 'slate'),
            };
            $dataStatus = $itemStatus === 'removed' ? 'removed' : 'active';
            $rowItem = is_array($item) ? $item : [
                ...$raw,
                'username' => $username,
                'displayName' => $displayName,
                'profileUrl' => data_get($raw, 'profileUrl') ?? data_get($item, 'profile_url_snapshot') ?? $related?->profile_url,
                'instagramProfileId' => $related?->id ?? data_get($item, 'related_instagram_profile_id'),
                'profileImagePath' => $related?->profile_image_path,
                'profileVisibility' => data_get($raw, 'profileVisibility') ?? $related?->profile_visibility,
                'isPrivate' => data_get($raw, 'isPrivate', $related?->is_private),
                'postsCount' => data_get($raw, 'postsCount') ?? $related?->posts_count,
                'followersCount' => data_get($raw, 'followersCount') ?? $related?->followers_count,
                'followingCount' => data_get($raw, 'followingCount') ?? $related?->following_count,
                'itemStatus' => $itemStatus,
                'statusLabel' => $statusLabel,
                'statusTone' => $statusTone,
            ];

            return [
                'item' => [
                    ...$rowItem,
                    'itemStatus' => $itemStatus,
                    'statusLabel' => $statusLabel,
                    'statusTone' => $statusTone,
                    'passive' => $passive,
                ],
                'username' => $username,
                'displayName' => $displayName,
                'itemStatus' => $itemStatus,
                'dataStatus' => $dataStatus,
                'added' => $itemStatus === 'added',
                'passive' => $passive,
                'statusLabel' => $statusLabel,
                'statusTone' => $statusTone,
                'name' => strtolower(trim($displayName.' '.$username)),
                'searchText' => strtolower(trim($username.' '.$displayName.' '.data_get($item, 'meta').' '.$statusLabel)),
                'statusSort' => match (true) {
                    $itemStatus === 'added' => 0,
                    $passive => 1,
                    $itemStatus === 'observed' => 2,
                    $itemStatus === 'removed' => 3,
                    default => 4,
                },
            ];
        })
        ->filter()
        ->values();
    $visibleRows = $rows->take($visibleLimit)->values();
    $hasMoreItems = $rows->count() > $visibleLimit;
    $activeItems = $rows->where('dataStatus', 'active')->values();
    $addedItems = $rows->where('itemStatus', 'added')->values();
    $passiveItems = $rows->where('passive', true)->values();
    $removedItems = $rows->where('dataStatus', 'removed')->values();
    $hasData = $hasData ?? ($scan || $rows->isNotEmpty());
    $sortRows = $visibleRows
        ->map(fn (array $row, int $index): array => [
            'index' => $index,
            'name' => $row['name'],
            'status' => $row['statusSort'],
        ]);
    $nameAscRanks = $sortRows
        ->sortBy([['name', 'asc'], ['index', 'asc']])
        ->values()
        ->mapWithKeys(fn (array $row, int $rank): array => [$row['index'] => $rank]);
    $nameDescRanks = $sortRows
        ->sortBy([['name', 'desc'], ['index', 'asc']])
        ->values()
        ->mapWithKeys(fn (array $row, int $rank): array => [$row['index'] => $rank]);
    $statusRanks = $sortRows
        ->sortBy([['status', 'asc'], ['name', 'asc'], ['index', 'asc']])
        ->values()
        ->mapWithKeys(fn (array $row, int $rank): array => [$row['index'] => $rank]);
@endphp

<x-modal wire:model="{{ $model }}" maxWidth="3xl">
    <div
        wire:poll.5s.visible
        x-data="{
            search: '',
            filter: 'active',
            sort: 'default',
            filterLabel() {
                return {
                    active: 'Aktiv',
                    passive: 'Passiv',
                    added: 'Neu',
                    removed: 'Entfernt',
                }[this.filter] || 'Filter';
            },
            sortLabel() {
                return {
                    default: 'Standard',
                    nameAsc: 'Name A-Z',
                    nameDesc: 'Name Z-A',
                    status: 'Status',
                }[this.sort] || 'Sortierung';
            },
            sortOrder(el) {
                return {
                    default: el.dataset.orderDefault,
                    nameAsc: el.dataset.orderNameAsc,
                    nameDesc: el.dataset.orderNameDesc,
                    status: el.dataset.orderStatus,
                }[this.sort] || el.dataset.orderDefault;
            },
            filterMatches(el) {
                if (this.filter === 'passive') {
                    return el.dataset.passive === 'true';
                }

                if (this.filter === 'added') {
                    return el.dataset.added === 'true';
                }

                return this.filter === el.dataset.status;
            },
        }"
        class="flex max-h-[85vh] flex-col overflow-hidden"
    >
        <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5">
            <div>
                <h3 class="text-lg font-bold text-slate-900">{{ $title }}</h3>
                <p class="mt-1 text-sm text-slate-500">
                    @if($hasData)
                        {{ number_format((int) data_get($stats, 'activeCount', $activeItems->count())) }} bekannt
                        &middot; {{ number_format((int) data_get($stats, 'observedCount', $activeItems->count())) }} beobachtet
                        @if($passiveItems->isNotEmpty())
                            &middot; {{ number_format($passiveItems->count()) }} passiv
                        @endif
                        @if($scan)
                            &middot; Scan {{ $scan->scanned_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}
                        @endif
                    @else
                        Noch keine Liste gespeichert.
                    @endif
                </p>
            </div>
            <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Schliessen
            </button>
        </div>

        <div class="overflow-y-auto p-4 sm:p-5">
            @if($hasData)
                <div class="mb-4 flex gap-2">
                    <input
                        type="search"
                        x-model.debounce.150ms="search"
                        class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:ring-pink-500"
                        placeholder="{{ $title }} durchsuchen..."
                    >
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
                                x-bind:title="'Sortierung: ' + sortLabel()"
                                x-bind:aria-label="'Sortierung: ' + sortLabel()"
                                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M7 4v16M7 20l-3-3M7 20l3-3M17 4l3 3M17 4l-3 3M17 4v16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            <div class="space-y-1">
                                <button type="button" x-on:click="sort = 'default'; open = false" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold transition" x-bind:class="sort === 'default' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50'">
                                    <span>Standard</span>
                                    <svg x-show="sort === 'default'" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                                <button type="button" x-on:click="sort = 'nameAsc'; open = false" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold transition" x-bind:class="sort === 'nameAsc' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50'">
                                    <span>Name A-Z</span>
                                    <svg x-show="sort === 'nameAsc'" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                                <button type="button" x-on:click="sort = 'nameDesc'; open = false" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold transition" x-bind:class="sort === 'nameDesc' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50'">
                                    <span>Name Z-A</span>
                                    <svg x-show="sort === 'nameDesc'" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                                <button type="button" x-on:click="sort = 'status'; open = false" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold transition" x-bind:class="sort === 'status' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50'">
                                    <span>Status</span>
                                    <svg x-show="sort === 'status'" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
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
                                x-bind:title="'Filter: ' + filterLabel()"
                                x-bind:aria-label="'Filter: ' + filterLabel()"
                                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M4 6h16M7 12h10M10 18h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            <div class="space-y-1">
                                <button type="button" x-on:click="filter = 'active'; open = false" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold transition" x-bind:class="filter === 'active' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50'">
                                    <span>Aktiv</span>
                                    <span class="rounded-md px-2 py-0.5 text-xs" x-bind:class="filter === 'active' ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-600'">{{ number_format($activeItems->count()) }}</span>
                                </button>
                                <button type="button" x-on:click="filter = 'passive'; open = false" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold transition" x-bind:class="filter === 'passive' ? 'bg-sky-600 text-white' : 'text-sky-800 hover:bg-sky-50'">
                                    <span>Passiv</span>
                                    <span class="rounded-md px-2 py-0.5 text-xs" x-bind:class="filter === 'passive' ? 'bg-white/15 text-white' : 'bg-sky-50 text-sky-700'">{{ number_format($passiveItems->count()) }}</span>
                                </button>
                                <button type="button" x-on:click="filter = 'added'; open = false" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold transition" x-bind:class="filter === 'added' ? 'bg-emerald-600 text-white' : 'text-emerald-800 hover:bg-emerald-50'">
                                    <span>Neu</span>
                                    <span class="rounded-md px-2 py-0.5 text-xs" x-bind:class="filter === 'added' ? 'bg-white/15 text-white' : 'bg-emerald-50 text-emerald-700'">{{ number_format($addedItems->count()) }}</span>
                                </button>
                                <button type="button" x-on:click="filter = 'removed'; open = false" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold transition" x-bind:class="filter === 'removed' ? 'bg-rose-600 text-white' : 'text-rose-800 hover:bg-rose-50'">
                                    <span>Entfernt</span>
                                    <span class="rounded-md px-2 py-0.5 text-xs" x-bind:class="filter === 'removed' ? 'bg-white/15 text-white' : 'bg-rose-50 text-rose-700'">{{ number_format($removedItems->count()) }}</span>
                                </button>
                            </div>
                        </x-slot>
                    </x-ui.dropdown.anchor-dropdown>
                </div>

                <div class="flex flex-col gap-2">
                    @forelse($visibleRows as $row)
                        @php
                            $defaultOrder = $loop->index;
                        @endphp
                        <x-instagram.profile-list-item
                            :item="$row['item']"
                            :status-label="$row['statusLabel']"
                            :status-tone="$row['statusTone']"
                            data-search="{{ e($row['searchText']) }}"
                            data-status="{{ $row['dataStatus'] }}"
                            data-passive="{{ $row['passive'] ? 'true' : 'false' }}"
                            data-added="{{ $row['added'] ? 'true' : 'false' }}"
                            data-order-default="{{ $defaultOrder }}"
                            data-order-name-asc="{{ $nameAscRanks[$defaultOrder] ?? $defaultOrder }}"
                            data-order-name-desc="{{ $nameDescRanks[$defaultOrder] ?? $defaultOrder }}"
                            data-order-status="{{ $statusRanks[$defaultOrder] ?? $defaultOrder }}"
                            x-bind:style="'order: ' + sortOrder($el)"
                            x-show="(search === '' || $el.dataset.search.includes(search.toLowerCase())) && filterMatches($el)"
                        />
                    @empty
                        <p class="rounded-lg border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">Der Scan enthaelt keine gespeicherten Eintraege.</p>
                    @endforelse
                </div>

                @if($hasMoreItems)
                    <div
                        wire:key="{{ $listType }}-instagram-profile-list-load-more-{{ $visibleLimit }}"
                        x-intersect.full.once="$wire.loadMoreInstagramProfileList('{{ $listType }}')"
                        class="mt-3 flex items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-3 text-xs font-semibold text-slate-500"
                    >
                        <span class="h-4 w-4 animate-spin rounded-full border-2 border-slate-200 border-t-slate-500"></span>
                        <span>Weitere {{ number_format($itemsPerPage, 0, ',', '.') }} laden</span>
                    </div>
                @endif
            @else
                <p class="rounded-lg border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">Noch keine Liste gespeichert.</p>
            @endif
        </div>
    </div>
</x-modal>
