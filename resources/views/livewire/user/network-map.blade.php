<div
    class="min-h-screen bg-[#fafafa] pb-16"
    data-network-map-root
    data-network-lazy="true"
    wire:init="prepareGraph"
    wire:loading.class="cursor-wait"
>
    <div class="border-b border-slate-200 bg-white">
        <div class="container mx-auto px-5 py-5">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <div class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-600 shadow-sm">
                        <span class="h-2 w-2 rounded-full bg-sky-500"></span>
                        Netzwerk
                    </div>
                    <h1 class="mt-4 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">
                        Personen-Netzwerk
                    </h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Grafische Sicht auf beobachtete Personen, bekannte Profile und rekonstruierte Instagram-Verbindungen.
                    </p>
                    @if($trackedPeople->isNotEmpty())
                        <div class="mt-4 flex max-w-sm flex-col gap-1">
                            <label for="network-primary-person" class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Hauptperson
                            </label>
                            <select
                                id="network-primary-person"
                                wire:change="setPrimaryTrackedPerson($event.target.value)"
                                class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500"
                            >
                                @foreach($trackedPeople as $trackedPerson)
                                    <option value="{{ $trackedPerson->id }}" @selected($primaryTrackedPersonId === $trackedPerson->id)>
                                        {{ $trackedPerson->display_name }}{{ $trackedPerson->instagram_username ? ' (@'.$trackedPerson->instagram_username.')' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
                <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
                    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Personen</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['people']) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Knoten</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['nodes']) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Kanten</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['edges']) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rekonstruiert</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['inferred']) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Listen</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['trackedList']) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <main class="container mx-auto px-5 py-6">
        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-600">
                        <button
                            type="button"
                            data-network-filter="public"
                            data-active-classes="border-sky-300 bg-sky-50 text-sky-800"
                            data-inactive-classes="border-slate-200 bg-white text-slate-500"
                            class="rounded-lg border px-3 py-1.5 transition"
                            aria-pressed="true"
                        >
                            Bekannte Profile
                        </button>
                        <button
                            type="button"
                            data-network-filter="inferred"
                            data-active-classes="border-pink-300 bg-pink-50 text-pink-800"
                            data-inactive-classes="border-slate-200 bg-white text-slate-500"
                            class="rounded-lg border px-3 py-1.5 transition"
                            aria-pressed="true"
                        >
                            Rekonstruktionen
                        </button>
                        <button
                            type="button"
                            data-network-filter="tracked"
                            data-active-classes="border-emerald-300 bg-emerald-50 text-emerald-800"
                            data-inactive-classes="border-slate-200 bg-white text-slate-500"
                            class="rounded-lg border px-3 py-1.5 transition"
                            aria-pressed="true"
                        >
                            Follower/Gefolgt
                        </button>
                    </div>
                    <div class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                        <button type="button" data-network-action="zoom-out" class="rounded-lg border border-slate-200 px-3 py-1.5 transition hover:bg-slate-50">-</button>
                        <button type="button" data-network-action="zoom-in" class="rounded-lg border border-slate-200 px-3 py-1.5 transition hover:bg-slate-50">+</button>
                        <button type="button" data-network-action="fit" class="rounded-lg border border-slate-200 px-3 py-1.5 transition hover:bg-slate-50">Reset</button>
                    </div>
                </div>

                @if($trackedPeople->isEmpty())
                    <div class="p-8 text-sm text-slate-500">
                        Noch keine Personen vorhanden. Lege zuerst Personen an, damit das Netzwerk dargestellt werden kann.
                    </div>
                @else
                    <div class="relative h-[640px] min-h-[520px] bg-slate-50" wire:ignore>
                        <div data-network-canvas class="absolute inset-0"></div>
                        <div
                            data-network-loading-panel
                            class="absolute left-4 top-4 z-10 w-[min(420px,calc(100%-2rem))] rounded-lg border border-slate-200 bg-white/95 p-4 shadow-sm backdrop-blur"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-bold text-slate-950" data-network-build-label>
                                        Netzwerk wird vorbereitet
                                    </div>
                                    <div class="mt-1 text-xs leading-5 text-slate-500" data-network-build-text>
                                        Die gespeicherten Profile und Listen werden nachgeladen.
                                    </div>
                                </div>
                                <div class="h-2.5 w-2.5 rounded-full bg-sky-500" data-network-build-dot></div>
                            </div>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full w-0 rounded-full bg-sky-500 transition-all duration-200" data-network-progress-bar></div>
                            </div>
                            <div class="mt-2 text-xs font-semibold text-slate-500" data-network-progress-count>
                                Warte auf Daten
                            </div>
                        </div>
                    </div>
                @endif
            </section>

            <aside class="space-y-4">
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Auswahl</h2>
                    <p data-network-detail-empty class="mt-3 text-sm leading-6 text-slate-600">
                        Waehle einen Knoten im Netzwerk aus, um direkte Verknuepfungen zu sehen.
                    </p>
                    <div data-network-detail class="mt-3 hidden">
                        <div class="flex items-start gap-3">
                            <div data-network-detail-avatar class="h-12 w-12 shrink-0"></div>
                            <div class="min-w-0">
                                <div class="break-words text-lg font-bold text-slate-950" data-network-detail-label></div>
                                <div class="mt-1 break-words text-sm font-semibold text-slate-500" data-network-detail-handle></div>
                            </div>
                        </div>
                        <p class="mt-3 text-sm leading-6 text-slate-600" data-network-detail-text></p>
                        <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Direkte Kanten</div>
                            <div class="mt-1 text-2xl font-bold text-slate-950" data-network-detail-edge-count>0</div>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Verbundene Knoten</h2>
                    <div class="mt-3 space-y-2" data-network-connected-list>
                        <p class="text-sm text-slate-500">Keine direkte Auswahl.</p>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Legende</h2>
                    <div class="mt-3 space-y-2 text-sm text-slate-600">
                        <div class="flex items-center gap-2"><span class="h-2 w-8 rounded-full bg-sky-600"></span> Bekannte Profil-Verknuepfung</div>
                        <div class="flex items-center gap-2"><span class="h-2 w-8 rounded-full bg-pink-600"></span> Rekonstruierte Listenverbindung</div>
                        <div class="flex items-center gap-2"><span class="h-2 w-8 rounded-full bg-emerald-600"></span> Gespeicherte Follower-/Gefolgt-Listen</div>
                        <div class="flex items-center gap-2"><span class="h-4 w-4 rounded-full bg-amber-400 ring-2 ring-amber-500"></span> Hauptperson</div>
                        <div class="flex items-center gap-2"><span class="h-4 w-4 rounded-full bg-slate-950"></span> Beobachtete Person</div>
                        <div class="flex items-center gap-2"><span class="h-4 w-4 rounded-full bg-pink-50 ring-1 ring-pink-300"></span> Rekonstruierter Kandidat</div>
                    </div>
                </div>
            </aside>
        </div>
    </main>
</div>
