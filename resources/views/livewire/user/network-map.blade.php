<div
    class="{{ $embedded ? 'bg-transparent' : 'min-h-screen bg-[#fafafa] pb-16' }}"
    data-network-map-root
    data-network-map-id="{{ $mapId }}"
    data-network-lazy="true"
    wire:init="prepareGraph"
    wire:loading.class="cursor-wait"
    x-data="{
        networkNode: { id: null, type: null, isKnownProfile: false },
        nodeMenu: { open: false, id: null, type: null, isKnownProfile: false, x: 0, y: 0 },
        setNetworkNode(event) {
            if (event.detail?.mapId && event.detail.mapId !== '{{ $mapId }}') {
                return;
            }

            this.networkNode = event.detail || { id: null, type: null, isKnownProfile: false };
        },
        openNodeMenu(event) {
            if (event.detail?.mapId && event.detail.mapId !== '{{ $mapId }}') {
                return;
            }

            this.nodeMenu = {
                open: true,
                id: event.detail.id,
                type: event.detail.type,
                isKnownProfile: event.detail.isKnownProfile,
                x: event.detail.x,
                y: event.detail.y,
            };
        },
        closeNodeMenu() {
            this.nodeMenu.open = false;
        }
    }"
    x-on:network-map-node-selected.window="setNetworkNode($event)"
    x-on:network-map-node-menu.window="openNodeMenu($event)"
    x-on:click.outside="closeNodeMenu()"
>
    @unless($embedded)
    <div class="border-b border-slate-200 bg-white">
        <div class="container mx-auto px-5 py-5">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <div class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-600 shadow-sm">
                        <span class="h-2 w-2 rounded-full bg-sky-500"></span>
                        Netzwerk
                    </div>

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
    @endunless

    <main class="{{ $embedded ? '' : 'container mx-auto px-5 py-6' }}">
        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-600">
                        <button type="button" data-network-filter="public" data-active-classes="border-sky-300 bg-sky-50 text-sky-800" data-inactive-classes="border-slate-200 bg-white text-slate-500" class="rounded-lg border px-3 py-1.5 transition" aria-pressed="true">
                            Bekannte Profile
                        </button>
                        <button type="button" data-network-filter="inferred" data-active-classes="border-pink-300 bg-pink-50 text-pink-800" data-inactive-classes="border-slate-200 bg-white text-slate-500" class="rounded-lg border px-3 py-1.5 transition" aria-pressed="true">
                            Rekonstruktionen
                        </button>
                        <button type="button" data-network-filter="tracked" data-active-classes="border-emerald-300 bg-emerald-50 text-emerald-800" data-inactive-classes="border-slate-200 bg-white text-slate-500" class="rounded-lg border px-3 py-1.5 transition" aria-pressed="true">
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
                        <div data-network-loading-panel class="absolute left-4 top-4 z-10 w-[min(420px,calc(100%-2rem))] rounded-lg border border-slate-200 bg-white/95 p-4 shadow-sm backdrop-blur">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-bold text-slate-950" data-network-build-label>Netzwerk wird vorbereitet</div>
                                    <div class="mt-1 text-xs leading-5 text-slate-500" data-network-build-text>Die gespeicherten Profile und Listen werden nachgeladen.</div>
                                </div>
                                <div class="h-2.5 w-2.5 rounded-full bg-sky-500" data-network-build-dot></div>
                            </div>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full w-0 rounded-full bg-sky-500 transition-all duration-200" data-network-progress-bar></div>
                            </div>
                            <div class="mt-2 text-xs font-semibold text-slate-500" data-network-progress-count>Warte auf Daten</div>
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
                        <div class="mt-4 space-y-2" x-show="networkNode.id && networkNode.type !== 'person'" x-cloak>
                            <button
                                type="button"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                                wire:loading.attr="disabled"
                                x-on:click="$wire.openProfilePreview(networkNode.id)"
                            >
                                Profil öffnen
                            </button>
                            <button
                                type="button"
                                class="w-full rounded-lg border border-pink-200 bg-pink-50 px-3 py-2 text-sm font-semibold text-pink-700 transition hover:bg-pink-100 disabled:opacity-50"
                                x-show="!networkNode.isKnownProfile"
                                wire:loading.attr="disabled"
                                x-on:click="$wire.addProfileAsKnown(networkNode.id)"
                            >
                                Als bekanntes Profil speichern
                            </button>
                            <button
                                type="button"
                                class="w-full rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-700 transition hover:bg-sky-100 disabled:opacity-50"
                                wire:loading.attr="disabled"
                                x-on:click="$wire.scanProfile(networkNode.id)"
                            >
                                Profil im Hintergrund scannen
                            </button>
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
                        <div class="flex items-center gap-2"><span class="h-2 w-8 rounded-full bg-indigo-600"></span> Profil-Relationships</div>
                        <div class="flex items-center gap-2"><span class="h-4 w-4 rounded-full bg-amber-400 ring-2 ring-amber-500"></span> Hauptperson</div>
                        <div class="flex items-center gap-2"><span class="h-4 w-4 rounded-full bg-slate-950"></span> Beobachtete Person</div>
                        <div class="flex items-center gap-2"><span class="h-4 w-4 rounded-full bg-pink-50 ring-1 ring-pink-300"></span> Rekonstruierter Kandidat</div>
                    </div>
                </div>
            </aside>
        </div>
    </main>

    <div
        x-cloak
        x-show="nodeMenu.open && nodeMenu.type !== 'person'"
        x-bind:style="`left: ${nodeMenu.x}px; top: ${nodeMenu.y}px`"
        class="fixed z-50 w-56 overflow-hidden rounded-lg border border-slate-200 bg-white text-sm shadow-xl"
    >
        <button type="button" class="block w-full px-3 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50" x-on:click="$wire.openProfilePreview(nodeMenu.id); closeNodeMenu()">
            Profil öffnen
        </button>
        <button type="button" class="block w-full px-3 py-2 text-left font-semibold text-pink-700 hover:bg-pink-50" x-show="!nodeMenu.isKnownProfile" x-on:click="$wire.addProfileAsKnown(nodeMenu.id); closeNodeMenu()">
            Als bekannt speichern
        </button>
        <button type="button" class="block w-full px-3 py-2 text-left font-semibold text-sky-700 hover:bg-sky-50" x-on:click="$wire.scanProfile(nodeMenu.id); closeNodeMenu()">
            Scan im Hintergrund starten
        </button>
    </div>

    <x-modal wire:model="showProfilePreviewModal" maxWidth="3xl">
        <x-slot name="title">
            Instagram-Profil
        </x-slot>

        <x-slot name="content">
            @if($profilePreview)
                <div class="space-y-4">
                    <div class="flex items-start gap-4">
                        <div class="h-16 w-16 shrink-0 overflow-hidden rounded-full bg-slate-100 ring-2 ring-slate-200">
                            @if($profilePreview['image_url'] ?? null)
                                <img src="{{ $profilePreview['image_url'] }}" alt="{{ $profilePreview['handle'] }}" class="h-full w-full object-cover">
                            @else
                                <div class="flex h-full w-full items-center justify-center text-xl font-bold text-slate-500">
                                    {{ strtoupper(substr(ltrim($profilePreview['username'] ?? '?', '@'), 0, 1)) }}
                                </div>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="break-words text-xl font-bold text-slate-950">{{ $profilePreview['display_name'] }}</h3>
                                @if($profilePreview['is_known_profile'])
                                    <span class="rounded-full bg-sky-100 px-2 py-1 text-xs font-semibold text-sky-800">bekannt</span>
                                @else
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">unbekannt</span>
                                @endif
                                <span class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                    {{ $profilePreview['visibility'] ?? 'unknown' }}
                                </span>
                            </div>
                            <div class="mt-1 text-sm font-semibold text-slate-500">{{ $profilePreview['handle'] }}</div>
                            @if($profilePreview['last_status_message'] ?? null)
                                <p class="mt-2 text-sm text-slate-600">{{ $profilePreview['last_status_message'] }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Follower</div>
                            <div class="mt-1 text-lg font-bold text-slate-950">{{ ($profilePreview['followers_count'] ?? null) !== null ? number_format($profilePreview['followers_count']) : '-' }}</div>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gefolgt</div>
                            <div class="mt-1 text-lg font-bold text-slate-950">{{ ($profilePreview['following_count'] ?? null) !== null ? number_format($profilePreview['following_count']) : '-' }}</div>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Posts</div>
                            <div class="mt-1 text-lg font-bold text-slate-950">{{ ($profilePreview['posts_count'] ?? null) !== null ? number_format($profilePreview['posts_count']) : '-' }}</div>
                        </div>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 bg-white p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Aktive Followerliste</div>
                            <div class="mt-1 text-lg font-bold text-slate-950">{{ number_format($profilePreview['active_followers_count'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-white p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Aktive Gefolgt-Liste</div>
                            <div class="mt-1 text-lg font-bold text-slate-950">{{ number_format($profilePreview['active_following_count'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-white p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Eingehende Verbindungen</div>
                            <div class="mt-1 text-lg font-bold text-slate-950">{{ number_format($profilePreview['known_incoming_count'] ?? 0) }}</div>
                        </div>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-white p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-bold text-slate-950">Scans</div>
                                <div class="mt-1 text-xs text-slate-500">
                                    Zuletzt gescannt: {{ $profilePreview['last_scanned_at'] ?? '-' }}
                                </div>
                            </div>
                            @if($profilePreview['profile_url'] ?? null)
                                <a href="{{ $profilePreview['profile_url'] }}" target="_blank" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Instagram öffnen
                                </a>
                            @endif
                        </div>

                        <div class="mt-3 space-y-2">
                            @forelse($profilePreview['list_scans'] ?? [] as $scan)
                                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-semibold text-slate-900">{{ $scan['list_type'] }}</span>
                                        <span>{{ $scan['scanned_at'] ?? '-' }}</span>
                                        <span class="rounded-full bg-white px-2 py-0.5 font-semibold">{{ $scan['status_level'] }}</span>
                                    </div>
                                    <div class="mt-1">
                                        Aktiv: {{ number_format($scan['active_count'] ?? 0) }} / beobachtet: {{ number_format($scan['observed_count'] ?? 0) }}
                                    </div>
                                    @if($scan['status_message'])
                                        <div class="mt-1">{{ $scan['status_message'] }}</div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-slate-500">Fuer dieses Profil sind noch keine gespeicherten Listenscans vorhanden.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endif
        </x-slot>

        <x-slot name="footer">
            <button type="button" wire:click="closeProfilePreview" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Schliessen
            </button>
            @if($profilePreview && ! ($profilePreview['is_known_profile'] ?? false))
                <button type="button" wire:click="addPreviewProfileAsKnown" wire:loading.attr="disabled" class="ml-3 rounded-lg border border-pink-200 bg-pink-50 px-4 py-2 text-sm font-semibold text-pink-700 hover:bg-pink-100 disabled:opacity-50">
                    Als bekannt speichern
                </button>
            @endif
            @if($profilePreview)
                <button type="button" wire:click="scanPreviewProfile" wire:loading.attr="disabled" class="ml-3 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50">
                    Scan starten
                </button>
            @endif
        </x-slot>
    </x-modal>
</div>
