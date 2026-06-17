@props([
    'model',
    'title',
    'scan' => null,
])

@php
    $items = $scan?->items ?? collect();
    $activeItems = $items->whereIn('item_status', ['observed', 'added'])->values();
    $addedItems = $items->where('item_status', 'added')->values();
    $removedItems = $items->where('item_status', 'removed')->values();
@endphp

<x-modal wire:model="{{ $model }}" maxWidth="3xl">
    <div wire:poll.5s.visible x-data="{ search: '', filter: 'active' }" class="flex max-h-[85vh] flex-col overflow-hidden">
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
                <div class="mb-4 flex flex-col gap-2 sm:flex-row">
                    <input
                        type="search"
                        x-model.debounce.150ms="search"
                        class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:ring-pink-500"
                        placeholder="{{ $title }} durchsuchen..."
                    >
                    <div class="flex flex-wrap gap-2">
                        <button type="button" x-on:click="filter = 'active'" class="rounded-lg border px-3 py-2 text-xs font-semibold" x-bind:class="filter === 'active' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700'">
                            Aktiv {{ number_format($activeItems->count()) }}
                        </button>
                        <button type="button" x-on:click="filter = 'added'" class="rounded-lg border px-3 py-2 text-xs font-semibold" x-bind:class="filter === 'added' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-emerald-200 bg-emerald-50 text-emerald-800'">
                            Neu {{ number_format($addedItems->count()) }}
                        </button>
                        <button type="button" x-on:click="filter = 'removed'" class="rounded-lg border px-3 py-2 text-xs font-semibold" x-bind:class="filter === 'removed' ? 'border-rose-600 bg-rose-600 text-white' : 'border-rose-200 bg-rose-50 text-rose-800'">
                            Entfernt {{ number_format($removedItems->count()) }}
                        </button>
                    </div>
                </div>

                <div class="space-y-2">
                    @forelse($items as $item)
                        @php
                            $related = $item->relatedInstagramProfile;
                            $searchText = strtolower(trim($item->username_snapshot.' '.$item->display_name_snapshot));
                            $active = in_array($item->item_status, ['observed', 'added'], true);
                        @endphp
                        <div
                            data-search="{{ e($searchText) }}"
                            data-status="{{ $active ? 'active' : $item->item_status }}"
                            x-show="(search === '' || $el.dataset.search.includes(search.toLowerCase())) && (filter === $el.dataset.status || (filter === 'added' && $el.dataset.status === 'active' && {{ $item->item_status === 'added' ? 'true' : 'false' }}))"
                            class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm"
                        >
                            <div class="min-w-0">
                                <div class="truncate font-semibold text-slate-900">{{ '@'.$item->username_snapshot }}</div>
                                @if($item->display_name_snapshot)
                                    <div class="truncate text-xs text-slate-500">{{ $item->display_name_snapshot }}</div>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span @class([
                                    'rounded-full px-2 py-1 text-[11px] font-semibold',
                                    'bg-emerald-50 text-emerald-700' => $item->item_status === 'added',
                                    'bg-rose-50 text-rose-700' => $item->item_status === 'removed',
                                    'bg-slate-100 text-slate-600' => $item->item_status === 'observed',
                                ])>{{ $item->item_status }}</span>
                                @if($related)
                                    <a href="{{ route('instagram-profiles.show', $related->id) }}" wire:navigate class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                        Detail
                                    </a>
                                @elseif($item->profile_url_snapshot)
                                    <a href="{{ $item->profile_url_snapshot }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                        Oeffnen
                                    </a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="rounded-lg border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">Der Scan enthaelt keine gespeicherten Eintraege.</p>
                    @endforelse
                </div>
            @else
                <p class="rounded-lg border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">Noch keine Liste gespeichert.</p>
            @endif
        </div>
    </div>
</x-modal>
