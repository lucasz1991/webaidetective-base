<div class="space-y-4" wire:poll.visible.10000ms>
    @php
        $listStatusClass = match ($listStatusLevel ?? 'neutral') {
            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
            default => 'border-slate-200 bg-slate-50 text-slate-800',
        };
        $filterButtonClass = fn (string $filter): string => $statusFilter === $filter
            ? 'border-slate-900 bg-slate-900 text-white'
            : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50';
        $sortButtonClass = fn (string $value): string => $sort === $value
            ? 'border-indigo-600 bg-indigo-50 text-indigo-700'
            : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50';
    @endphp

    <section class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-600">
                    Beobachtete Profile:
                    <span class="text-slate-950">{{ number_format($stats['total']) }}</span>
                    @if($trackingLimit !== null)
                        / {{ number_format($trackingLimit) }}
                    @endif
                </div>
                <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs font-semibold text-slate-500">
                    <span>{{ number_format($stats['monitoring']) }} aktiv beobachtet</span>
                    <span>{{ number_format($stats['needsAttention']) }} mit Hinweis</span>
                    <span>{{ number_format($stats['noScan']) }} ohne Scan</span>
                </div>
            </div>

            <div class="flex min-w-0 flex-col gap-2 md:flex-row md:items-center">
                <label class="sr-only" for="tracked-people-search">Beobachtete Profile suchen</label>
                <input
                    id="tracked-people-search"
                    type="search"
                    wire:model.live.debounce.250ms="search"
                    class="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 md:w-64"
                    placeholder="Profile suchen..."
                >
                <div class="flex flex-wrap gap-1.5">
                    <button type="button" wire:click="setStatusFilter('all')" class="rounded-lg border px-3 py-2 text-xs font-bold {{ $filterButtonClass('all') }}">Alle</button>
                    <button type="button" wire:click="setStatusFilter('monitoring')" class="rounded-lg border px-3 py-2 text-xs font-bold {{ $filterButtonClass('monitoring') }}">Aktiv</button>
                    <button type="button" wire:click="setStatusFilter('needs_attention')" class="rounded-lg border px-3 py-2 text-xs font-bold {{ $filterButtonClass('needs_attention') }}">Hinweis</button>
                    <button type="button" wire:click="setStatusFilter('no_scan')" class="rounded-lg border px-3 py-2 text-xs font-bold {{ $filterButtonClass('no_scan') }}">Ohne Scan</button>
                </div>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-3">
            <span class="mr-1 text-xs font-bold uppercase tracking-wide text-slate-500">Sortierung</span>
            <button type="button" wire:click="setSort('recent')" class="rounded-lg border px-3 py-1.5 text-xs font-bold {{ $sortButtonClass('recent') }}">Aktualitaet</button>
            <button type="button" wire:click="setSort('name')" class="rounded-lg border px-3 py-1.5 text-xs font-bold {{ $sortButtonClass('name') }}">Name</button>
            <button type="button" wire:click="setSort('followers')" class="rounded-lg border px-3 py-1.5 text-xs font-bold {{ $sortButtonClass('followers') }}">Follower</button>
        </div>
    </section>

    @if($listStatus)
        <div class="rounded-lg border p-3 text-sm {{ $listStatusClass }}">
            {{ $listStatus }}
        </div>
    @endif

    <section class="grid gap-3 xl:grid-cols-2">
        @forelse($trackedPeople as $trackedPerson)
            <x-profile.lists.profile-list-item
                :tracked-person="$trackedPerson"
                :selected="$selectedTrackedPersonId === $trackedPerson->id && $showDetailModal"
            />
        @empty
            <div class="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600 xl:col-span-2">
                @if($search !== '' || $statusFilter !== 'all')
                    Keine beobachteten Profile passen zu den aktuellen Filtern.
                @else
                    Noch keine beobachteten Profile angelegt.
                @endif
            </div>
        @endforelse
    </section>

    @if($showDetailModal && $selectedTrackedPerson)
        <x-instagram-profile-preview
            model="showDetailModal"
            :tracked-person="$selectedTrackedPerson"
            :detail-route="route('tracked-people.show', $selectedTrackedPerson->id)"
        />
    @endif
</div>
