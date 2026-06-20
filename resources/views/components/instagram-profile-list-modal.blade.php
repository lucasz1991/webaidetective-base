@props([
    'model',
    'title',
    'scan' => null,
    'listType' => 'followers',
    'visibleLimit' => 50,
    'itemsPerPage' => 50,
])

@php
    $items = $scan?->items ?? collect();
    $visibleLimit = max(1, (int) $visibleLimit);
    $itemsPerPage = max(1, (int) $itemsPerPage);
    $visibleItems = $items->take($visibleLimit)->values();
    $hasMoreItems = $items->count() > $visibleLimit;
    $activeItems = $items->whereIn('item_status', ['observed', 'added'])->values();
    $addedItems = $items->where('item_status', 'added')->values();
    $removedItems = $items->where('item_status', 'removed')->values();
    $sortRows = $visibleItems
        ->map(fn ($item, int $index): array => [
            'index' => $index,
            'name' => strtolower(trim(($item->display_name_snapshot ?: $item->username_snapshot).' '.$item->username_snapshot)),
            'status' => match ($item->item_status) {
                'added' => 0,
                'observed' => 1,
                'removed' => 2,
                default => 3,
            },
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
        }"
        class="flex max-h-[85vh] flex-col overflow-hidden"
    >
        <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5">
            <div>
                <h3 class="text-lg font-bold text-slate-900">{{ $title }}</h3>
                <p class="mt-1 text-sm text-slate-500">
                    @if($scan)
                        {{ number_format($scan->active_count) }} aktiv
                        &middot; {{ number_format($scan->observed_count) }} beobachtet
                        &middot; Scan {{ $scan->scanned_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}
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
            @if($scan)
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
                    @forelse($visibleItems as $item)
                        @php
                            $defaultOrder = $loop->index;
                            $searchText = strtolower(trim($item->username_snapshot.' '.$item->display_name_snapshot));
                            $active = in_array($item->item_status, ['observed', 'added'], true);
                            $statusTone = match ($item->item_status) {
                                'added' => 'emerald',
                                'removed' => 'rose',
                                default => 'slate',
                            };
                        @endphp
                        <x-instagram.profile-list-item
                            :item="$item"
                            :status-label="$item->item_status"
                            :status-tone="$statusTone"
                            data-search="{{ e($searchText) }}"
                            data-status="{{ $active ? 'active' : $item->item_status }}"
                            data-order-default="{{ $defaultOrder }}"
                            data-order-name-asc="{{ $nameAscRanks[$defaultOrder] ?? $defaultOrder }}"
                            data-order-name-desc="{{ $nameDescRanks[$defaultOrder] ?? $defaultOrder }}"
                            data-order-status="{{ $statusRanks[$defaultOrder] ?? $defaultOrder }}"
                            x-bind:style="'order: ' + sortOrder($el)"
                            x-show="(search === '' || $el.dataset.search.includes(search.toLowerCase())) && (filter === $el.dataset.status || (filter === 'added' && $el.dataset.status === 'active' && {{ $item->item_status === 'added' ? 'true' : 'false' }}))"
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
