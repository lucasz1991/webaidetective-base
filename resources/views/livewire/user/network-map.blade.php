<div
    class="{{ $embedded ? 'bg-transparent' : 'min-h-screen bg-[#fafafa] pb-16' }}"
    data-network-map-root
    data-network-map-id="{{ $mapId }}"
    data-network-filter-scope="{{ $contextTrackedPersonId ? 'person-'.$contextTrackedPersonId : 'global' }}"
    data-network-max-visible-profiles="100"
    data-network-lazy="true"
    wire:init="prepareGraph"
    wire:loading.class="cursor-wait"
    x-data="{
        mapFullscreen: false,
        filterPanelOpen: false,
        networkNode: { id: null, type: null, isKnownProfile: false },
        nodeMenu: { open: false, id: null, type: null, isKnownProfile: false, detailUrl: null, x: 0, y: 0 },
        openMap() {
            if (this.mapFullscreen) {
                return;
            }

            this.mapFullscreen = true;
            document.documentElement.classList.add('overflow-hidden');
            this.$nextTick(() => window.dispatchEvent(new CustomEvent('network-map-layout-refresh', { detail: { mapId: '{{ $mapId }}' } })));
        },
        closeMap() {
            if (!this.mapFullscreen) {
                return;
            }

            this.mapFullscreen = false;
            this.filterPanelOpen = false;
            this.closeNodeMenu();
            document.documentElement.classList.remove('overflow-hidden');
            this.$nextTick(() => window.dispatchEvent(new CustomEvent('network-map-layout-refresh', { detail: { mapId: '{{ $mapId }}' } })));
        },
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
                detailUrl: event.detail.detailUrl,
                x: event.detail.x,
                y: event.detail.y,
            };
        },
        closeNodeMenu() {
            this.nodeMenu.open = false;
        },
        openNode(event) {
            if (event.detail?.mapId && event.detail.mapId !== '{{ $mapId }}') {
                return;
            }

            if (event.detail?.type === 'person' && event.detail?.detailUrl) {
                window.location.href = event.detail.detailUrl;
                return;
            }

            if (event.detail?.id) {
                this.$wire.openProfilePreview(event.detail.id);
            }
        }
    }"
    x-on:network-map-node-selected.window="setNetworkNode($event)"
    x-on:network-map-node-menu.window="openNodeMenu($event)"
    x-on:network-map-open-node.window="openNode($event)"
    x-on:pointerdown.window="if (nodeMenu.open && !$event.target.closest('[data-network-node-menu]')) closeNodeMenu()"
    x-on:keydown.escape.window="if (mapFullscreen) closeMap()"
>
    <div
        wire:loading.flex
        wire:target="scanPreviewProfile,scanProfileInGui"
        class="fixed inset-0 z-[10000] hidden items-center justify-center bg-slate-950/70 px-4"
    >
        <div class="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-lg border border-white/20 bg-white p-6 text-center shadow-2xl">
            <div class="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-pink-200 border-t-pink-600"></div>
            <div class="mt-4 text-xs font-semibold uppercase tracking-wide text-pink-700" wire:stream="network-map-scan-phase">Start</div>
            <h3 class="mt-2 text-lg font-bold text-slate-950">Instagram-Scan laeuft</h3>
            <p class="mt-2 text-sm leading-6 text-slate-600" wire:stream="network-map-scan-message">
                Das ausgewaehlte Profil wird direkt in der Oberflaeche gescannt.
            </p>
            <div wire:stream="network-map-scan-live-preview"></div>
            <div class="mt-2 text-xs font-semibold text-slate-500" wire:stream="network-map-scan-live-counts"></div>
            <div class="mt-5">
                <div class="flex items-center justify-between text-xs font-semibold text-slate-500">
                    <span>Fortschritt</span>
                    <span wire:stream="network-map-scan-percent">0%</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200" wire:stream="network-map-scan-bar">
                    <div class="h-full rounded-full bg-pink-600" style="width: 0%"></div>
                </div>
            </div>
            @if($primaryTrackedPersonId)
                <div class="mt-5 flex justify-center">
                    <button
                        type="button"
                        data-instagram-stop-url="{{ route('tracked-people.instagram.stop-scan', ['trackedPerson' => $primaryTrackedPersonId]) }}"
                        onclick="
                            const button = this;
                            if (button.dataset.stopping === '1') return;
                            button.dataset.stopping = '1';
                            button.disabled = true;
                            button.querySelector('[data-stop-label]').textContent = 'Stop wird angefordert...';
                            fetch(button.dataset.instagramStopUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '',
                                },
                            }).then(() => {
                                button.querySelector('[data-stop-label]').textContent = 'Stop angefordert. Speichert...';
                            }).catch(() => {
                                button.dataset.stopping = '0';
                                button.disabled = false;
                                button.querySelector('[data-stop-label]').textContent = 'Stop erneut versuchen';
                            });
                        "
                        class="inline-flex items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 shadow-sm hover:bg-rose-100 disabled:cursor-wait disabled:opacity-70"
                    >
                        <span data-stop-label>Scan beenden und speichern</span>
                    </button>
                </div>
            @endif
        </div>
    </div>

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

                <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3 xl:grid-cols-6">
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
                    <div class="rounded-lg border border-violet-200 bg-violet-50 px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-violet-700">Systemweit</div>
                        <div class="mt-1 text-xl font-bold text-violet-950">{{ number_format($stats['systemWide'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endunless

    <main class="{{ $embedded ? '' : 'container mx-auto px-5 py-6' }}">
        <div
            class="grid"
            x-bind:class="mapFullscreen
                ? 'fixed inset-0 z-50 h-screen w-screen overflow-hidden bg-white'
                : 'grid-cols-1 gap-4'"
        >
            <section
                class="relative overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"
                x-bind:class="mapFullscreen ? '!min-h-screen !rounded-none !border-0 !shadow-none' : ''"
            >
                <div x-show="mapFullscreen" x-cloak class="absolute left-3 top-3 z-30">
                    <button
                        type="button"
                        x-on:click="filterPanelOpen = ! filterPanelOpen"
                        x-bind:aria-expanded="filterPanelOpen"
                        class="rounded-lg border border-slate-200 bg-white/95 px-4 py-2 text-sm font-semibold text-slate-700 shadow-md backdrop-blur transition hover:bg-white"
                    >
                        Filter
                    </button>
                </div>

                <button
                    type="button"
                    x-show="mapFullscreen"
                    x-cloak
                    x-on:click="closeMap()"
                    class="absolute right-3 top-3 z-30 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 shadow-md transition hover:bg-slate-50"
                >
                    Schliessen
                </button>

                <div
                    x-show="mapFullscreen && filterPanelOpen"
                    x-cloak
                    x-on:click.outside="filterPanelOpen = false"
                    class="absolute left-3 top-14 z-20 flex max-h-[calc(100vh-4.5rem)] w-[min(440px,calc(100%-1.5rem))] flex-col gap-3 overflow-y-auto rounded-lg border border-slate-200 bg-white/95 p-3 shadow-xl backdrop-blur-md"
                >
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
                        <button type="button" data-network-filter="direct" data-active-classes="border-slate-900 bg-slate-900 text-white" data-inactive-classes="border-slate-200 bg-white text-slate-500" class="rounded-lg border px-3 py-1.5 transition" aria-pressed="false">
                            Direkt verbunden
                        </button>
                        <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-slate-600">
                            <span>Min. Verbindungen</span>
                            <select data-network-filter-min-degree class="border-0 bg-transparent p-0 text-xs font-bold text-slate-900 focus:ring-0">
                                <option value="0">0</option>
                                <option value="1">1</option>
                                <option value="2" selected>2</option>
                                <option value="3">3</option>
                                <option value="4">4</option>
                                <option value="5">5</option>
                                <option value="8">8</option>
                            </select>
                        </label>
                        <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-slate-600">
                            <span>Max. Profile</span>
                            <select data-network-filter-max-profiles class="border-0 bg-transparent p-0 text-xs font-bold text-slate-900 focus:ring-0">
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100" selected>100</option>
                                <option value="150">150</option>
                                <option value="250">250</option>
                                <option value="500">500</option>
                                <option value="0">Alle</option>
                            </select>
                        </label>
                        <span class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-slate-600">
                            <span data-network-visible-profiles-count>0 sichtbar</span>
                            <span class="text-slate-400">|</span>
                            <span>effektiv min: <span data-network-effective-min-degree>0</span></span>
                        </span>
                    </div>
                    <div class="flex items-center gap-2 border-t border-slate-200 pt-3 text-xs font-semibold text-slate-600">
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
                    <div
                        class="relative bg-slate-50"
                        x-bind:class="mapFullscreen ? 'h-screen min-h-0' : 'h-[420px] min-h-[420px] cursor-zoom-in'"
                        x-on:click="if (!mapFullscreen) openMap()"
                        wire:ignore
                    >
                        <div data-network-canvas class="absolute inset-0"></div>
                        <div data-network-public-badges class="pointer-events-none absolute inset-0 z-[4]"></div>
                        <div
                            x-show="!mapFullscreen"
                            class="absolute inset-0 z-[5] flex items-center justify-center bg-slate-950/10 backdrop-blur-[1px]"
                        >
                            <div class="rounded-full border border-white/70 bg-white/90 px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm">
                                Karte anklicken, um Vollbild und Steuerung zu oeffnen
                            </div>
                        </div>
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

            <aside class="hidden">
                <div class="border-b border-slate-200 p-4">
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
                                <div class="mt-2" data-network-detail-visibility></div>
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

                <div class="border-b border-slate-200 p-4">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Verbundene Knoten</h2>
                    <div class="mt-3 space-y-2" data-network-connected-list>
                        <p class="text-sm text-slate-500">Keine direkte Auswahl.</p>
                    </div>
                </div>

                <div class="p-4">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Legende</h2>
                    <div class="mt-3 space-y-2 text-sm text-slate-600">
                        <div class="flex items-center gap-2"><span class="h-2 w-8 rounded-full bg-sky-600"></span> Bekannte Profil-Verknuepfung</div>
                        <div class="flex items-center gap-2"><span class="h-2 w-8 rounded-full bg-pink-600"></span> Rekonstruierte Listenverbindung</div>
                        <div class="flex items-center gap-2"><span class="h-2 w-8 rounded-full bg-emerald-600"></span> Gespeicherte Follower-/Gefolgt-Listen</div>
                        <div class="flex items-center gap-2"><span class="h-2 w-8 rounded-full bg-indigo-600"></span> Profil-Relationships</div>
                        <div class="flex items-center gap-2"><span class="h-4 w-4 rounded-full bg-amber-400 ring-2 ring-amber-500"></span> Hauptperson</div>
                        <div class="flex items-center gap-2"><span class="h-4 w-4 rounded-full bg-slate-950"></span> Beobachtete Person</div>
                        <div class="flex items-center gap-2"><span class="h-4 w-4 rounded-full border border-slate-300 bg-slate-50"></span> Rekonstruierter Kandidat</div>
                        <div class="flex items-center gap-2"><span class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-emerald-500 text-[10px] font-black text-white ring-2 ring-white">&#10003;</span> Als oeffentlich erkannt</div>
                    </div>
                </div>
            </aside>
        </div>

    </main>

    <div
        x-cloak
        x-show="nodeMenu.open"
        x-bind:style="`left: ${nodeMenu.x}px; top: ${nodeMenu.y}px`"
        data-network-node-menu
        class="fixed z-50 w-56 overflow-hidden rounded-lg border border-slate-200 bg-white text-sm shadow-xl"
    >
        <button type="button" class="block w-full px-3 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50" x-show="nodeMenu.type === 'person' && nodeMenu.detailUrl" x-on:click="window.location.href = nodeMenu.detailUrl; closeNodeMenu()">
            Person öffnen
        </button>
        <button type="button" class="block w-full px-3 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50" x-show="nodeMenu.type !== 'person'" x-on:click="$wire.openProfilePreview(nodeMenu.id); closeNodeMenu()">
            Profil öffnen
        </button>
        <button type="button" class="block w-full px-3 py-2 text-left font-semibold text-pink-700 hover:bg-pink-50" x-show="nodeMenu.type !== 'person' && !nodeMenu.isKnownProfile" x-on:click="$wire.addProfileAsKnown(nodeMenu.id); closeNodeMenu()">
            Als bekannt speichern
        </button>
        <button type="button" class="block w-full px-3 py-2 text-left font-semibold text-sky-700 hover:bg-sky-50" x-show="nodeMenu.type !== 'person'" x-on:click="$wire.scanProfile(nodeMenu.id); closeNodeMenu()">
            Scan im Hintergrund starten
        </button>
    </div>

    @if($showProfilePreviewModal && $profilePreview)
        <x-instagram-profile-preview
            model="showProfilePreviewModal"
            :profile="$profilePreview"
            :detail-route="$profilePreview['detail_url'] ?? null"
        >
            <x-slot:actions>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Profilaktionen</div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        <button
                            type="button"
                            wire:click="addPreviewProfileAsTrackedPerson"
                            wire:loading.attr="disabled"
                            class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-700 hover:bg-sky-100 disabled:opacity-50"
                        >
                            Als beobachtetes Profil anlegen
                        </button>
                        @if(! ($profilePreview['is_known_profile'] ?? false))
                            <button
                                type="button"
                                wire:click="addPreviewProfileAsKnown"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-pink-200 bg-pink-50 px-3 py-2 text-sm font-semibold text-pink-700 hover:bg-pink-100 disabled:opacity-50"
                            >
                                Als bekannt speichern
                            </button>
                        @endif
                        <button
                            type="button"
                            wire:click="scanPreviewProfile"
                            wire:loading.attr="disabled"
                            wire:target="scanPreviewProfile"
                            class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="scanPreviewProfile">Vollanalyse direkt</span>
                            <span wire:loading wire:target="scanPreviewProfile">Analyse laeuft...</span>
                        </button>
                        <button
                            type="button"
                            wire:click="scanPreviewProfileInBackground"
                            wire:loading.attr="disabled"
                            class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        >
                            Vollanalyse im Hintergrund
                        </button>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">
                        Nach dem Anlegen als beobachtetes Profil stehen Mini-Scan, Vollanalyse und profilspezifische Listenaktionen direkt in diesem Modal bereit.
                    </p>
                </div>
            </x-slot:actions>
        </x-instagram-profile-preview>
    @endif

    @if(false && $showProfilePreviewModal)
        <div class="fixed inset-0 z-[60] flex h-screen w-screen flex-col overflow-hidden bg-white">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5 sm:py-4">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Instagram-Profil</h3>
                    <p class="mt-1 text-sm text-slate-500">Profilvorschau und Scan-Aktionen aus der Network Map.</p>
                </div>
                <button type="button" wire:click="closeProfilePreview" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Schliessen
                </button>
            </div>

            <div class="overflow-y-auto px-4 py-4 sm:px-5">
            @if($profilePreview)
                <div class="space-y-4">
                    <div class="flex items-start gap-4">
                        <div class="h-16 w-16 shrink-0 overflow-hidden rounded-full border border-slate-200 bg-slate-100">
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
                                <span @class([
                                    'rounded-full px-2 py-1 text-xs font-semibold ring-1',
                                    'bg-emerald-50 text-emerald-700 ring-emerald-200' => ($profilePreview['visibility'] ?? null) === 'public',
                                    'bg-slate-100 text-slate-700 ring-slate-200' => ($profilePreview['visibility'] ?? null) === 'private',
                                    'bg-amber-50 text-amber-800 ring-amber-200' => ! in_array(($profilePreview['visibility'] ?? null), ['public', 'private'], true),
                                ])>
                                    {{ match ($profilePreview['visibility'] ?? null) { 'public' => 'Oeffentlich', 'private' => 'Privat', default => 'Unbekannt' } }}
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
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
                <button type="button" wire:click="closeProfilePreview" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Schliessen
                </button>
                @if($profilePreview && ! ($profilePreview['is_known_profile'] ?? false))
                    <button type="button" wire:click="addPreviewProfileAsKnown" wire:loading.attr="disabled" class="rounded-lg border border-pink-200 bg-pink-50 px-4 py-2 text-sm font-semibold text-pink-700 hover:bg-pink-100 disabled:opacity-50">
                        Als bekannt speichern
                    </button>
                @endif
                @if($profilePreview)
                    <div x-data="{ scanMenuOpen: false }" class="relative inline-flex">
                        <button
                            type="button"
                            wire:click="scanPreviewProfile"
                            wire:loading.attr="disabled"
                            wire:target="scanPreviewProfile,scanPreviewProfileInBackground"
                            class="rounded-l-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="scanPreviewProfile">Scan starten</span>
                            <span wire:loading wire:target="scanPreviewProfile">Scan laeuft...</span>
                        </button>
                        <button
                            type="button"
                            x-on:click="scanMenuOpen = ! scanMenuOpen"
                            class="rounded-r-lg border-l border-slate-700 bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                            aria-label="Scan-Optionen"
                        >
                            ▾
                        </button>
                        <div
                            x-cloak
                            x-show="scanMenuOpen"
                            x-on:click.outside="scanMenuOpen = false"
                            class="absolute bottom-full right-0 z-50 mb-2 w-56 overflow-hidden rounded-lg border border-slate-200 bg-white text-sm shadow-xl"
                        >
                            <button
                                type="button"
                                wire:click="scanPreviewProfileInBackground"
                                wire:loading.attr="disabled"
                                x-on:click="scanMenuOpen = false"
                                class="block w-full px-3 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                            >
                                Im Hintergrund starten
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
