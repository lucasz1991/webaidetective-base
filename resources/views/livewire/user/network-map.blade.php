<div
    class="{{ $embedded ? 'bg-transparent' : 'min-h-screen bg-[#fafafa] pb-16' }}"
    data-network-map-root
    data-network-map-id="{{ $mapId }}"
    data-network-filter-scope="{{ $contextTrackedPersonId ? 'person-'.$contextTrackedPersonId : 'global' }}"
    data-network-focus-tracked-person-id="{{ $contextTrackedPersonId ?: $primaryTrackedPersonId }}"
    data-network-max-visible-profiles="250"
    data-network-layout-mode="clusters"
    data-network-three-theme="dark"
    data-network-lazy="true"
    wire:loading.class="cursor-wait"
    x-data="{
        mapFullscreen: false,
        filterMenu: null,
        networkNode: { id: null, type: null, isKnownProfile: false },
        nodeMenu: { open: false, id: null, type: null, isKnownProfile: false, detailUrl: null, name: '', handle: '', x: 0, y: 0 },
        notifyAssistantContext() {
            window.dispatchEvent(new CustomEvent('assistant-network-context', {
                detail: {
                    mapId: '{{ $mapId }}',
                    open: true,
                    fullscreen: this.mapFullscreen,
                    focusTrackedPersonId: @js($contextTrackedPersonId ?: $primaryTrackedPersonId),
                },
            }));
        },
        openMap() {
            if (this.mapFullscreen) {
                return;
            }

            this.mapFullscreen = true;
            document.documentElement.classList.add('overflow-hidden');
            this.notifyAssistantContext();
            this.$nextTick(() => window.dispatchEvent(new CustomEvent('network-map-layout-refresh', { detail: { mapId: '{{ $mapId }}' } })));
        },
        closeMap() {
            if (!this.mapFullscreen) {
                return;
            }

            this.mapFullscreen = false;
            this.filterMenu = null;
            this.closeNodeMenu();
            document.documentElement.classList.remove('overflow-hidden');
            this.notifyAssistantContext();
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
                name: event.detail.name || event.detail.handle || event.detail.id,
                handle: event.detail.handle || '',
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
    x-init="notifyAssistantContext()"
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
                class="relative overflow-hidden rounded-lg border border-slate-200 bg-slate-100 shadow-sm"
                x-bind:class="mapFullscreen ? '!min-h-screen !rounded-none !border-0 !shadow-none' : ''"
            >
                <div x-show="mapFullscreen" x-cloak class="absolute left-3 top-3 z-30 flex max-w-[calc(100%-7.5rem)] flex-wrap items-start gap-2">
                    <div class="relative" x-on:click.outside="if (filterMenu === 'connections') filterMenu = null">
                        <button
                            type="button"
                            x-on:click="filterMenu = filterMenu === 'connections' ? null : 'connections'"
                            x-bind:aria-expanded="filterMenu === 'connections'"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-white/40 bg-white/75 text-slate-800 shadow-lg backdrop-blur-xl transition hover:bg-white"
                            title="Verbindungen"
                        >
                            <span class="sr-only">Verbindungen</span>
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M6.5 8.5h11M6.5 15.5h11M8 6l-3 2.5L8 11M16 13l3 2.5L16 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <div
                            x-show="filterMenu === 'connections'"
                            x-cloak
                            class="absolute left-0 top-full mt-2 w-[min(360px,calc(100vw-1.5rem))] rounded-lg border border-white/45 bg-white/80 p-3 shadow-2xl backdrop-blur-xl"
                        >
                            <div class="grid gap-2 text-xs font-semibold">
                                <button type="button" data-network-filter="public" data-active-classes="border-sky-300 bg-sky-50/90 text-sky-900" data-inactive-classes="border-slate-200 bg-white/55 text-slate-500" class="flex items-center justify-between rounded-lg border px-3 py-2 text-left shadow-sm transition" aria-pressed="true">
                                    <span class="inline-flex items-center gap-2">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 12h8M12 8v8M5 5h14v14H5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        Bekannte Profile
                                    </span>
                                    <span class="h-5 w-9 rounded-full border border-current/20 bg-current/10 p-0.5"><span class="block h-3.5 w-3.5 rounded-full bg-current"></span></span>
                                </button>
                                <button type="button" data-network-filter="inferred" data-active-classes="border-rose-300 bg-rose-50/90 text-rose-900" data-inactive-classes="border-slate-200 bg-white/55 text-slate-500" class="flex items-center justify-between rounded-lg border px-3 py-2 text-left shadow-sm transition" aria-pressed="true">
                                    <span class="inline-flex items-center gap-2">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v4M12 17v4M4.6 6.6l2.8 2.8M16.6 16.6l2.8 2.8M3 12h4M17 12h4M4.6 17.4l2.8-2.8M16.6 7.4l2.8-2.8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                        Rekonstruktionen und Vorschlaege
                                    </span>
                                    <span class="h-5 w-9 rounded-full border border-current/20 bg-current/10 p-0.5"><span class="block h-3.5 w-3.5 rounded-full bg-current"></span></span>
                                </button>
                                <button type="button" data-network-filter="tracked" data-active-classes="border-emerald-300 bg-emerald-50/90 text-emerald-900" data-inactive-classes="border-slate-200 bg-white/55 text-slate-500" class="flex items-center justify-between rounded-lg border px-3 py-2 text-left shadow-sm transition" aria-pressed="true">
                                    <span class="inline-flex items-center gap-2">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 8a4 4 0 1 0 0 8M17 8a4 4 0 1 1 0 8M9.5 12h5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                        Follower/Gefolgt
                                    </span>
                                    <span class="h-5 w-9 rounded-full border border-current/20 bg-current/10 p-0.5"><span class="block h-3.5 w-3.5 rounded-full bg-current"></span></span>
                                </button>
                                <button type="button" data-network-filter="direct" data-active-classes="border-slate-900 bg-slate-900 text-white" data-inactive-classes="border-slate-200 bg-white/55 text-slate-500" class="flex items-center justify-between rounded-lg border px-3 py-2 text-left shadow-sm transition" aria-pressed="false">
                                    <span class="inline-flex items-center gap-2">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        Nur direkt verbundene Profile
                                    </span>
                                    <span class="h-5 w-9 rounded-full border border-current/20 bg-current/10 p-0.5"><span class="block h-3.5 w-3.5 rounded-full bg-current"></span></span>
                                </button>
                            </div>
                            <div class="mt-3 border-t border-slate-200 pt-3 text-xs text-slate-600">
                                <div class="mb-2 font-bold uppercase tracking-wide text-slate-500">Farben</div>
                                <div class="grid gap-2">
                                    <div class="flex items-center gap-2"><span class="h-1.5 w-8 rounded-full bg-emerald-600"></span> Gegenseitiges Folgen</div>
                                    <div class="flex items-center gap-2"><span class="h-1.5 w-8 rounded-full bg-green-500"></span> Einseitiges Folgen</div>
                                    <div class="flex items-center gap-2"><span class="h-1.5 w-8 rounded-full bg-sky-600"></span> Bekannte Profil-Verknuepfung</div>
                                    <div class="flex items-center gap-2"><span class="h-1.5 w-8 rounded-full bg-violet-600"></span> Verknuepfung ueber gespeicherte Person</div>
                                    <div class="flex items-center gap-2"><span class="h-1.5 w-8 rounded-full bg-rose-600"></span> Rekonstruiert</div>
                                    <div class="flex items-center gap-2"><span class="h-1.5 w-8 rounded-full bg-amber-500"></span> Vorschlag</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="relative" x-on:click.outside="if (filterMenu === 'visibility') filterMenu = null">
                        <button
                            type="button"
                            x-on:click="filterMenu = filterMenu === 'visibility' ? null : 'visibility'"
                            x-bind:aria-expanded="filterMenu === 'visibility'"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-white/40 bg-white/75 text-slate-800 shadow-lg backdrop-blur-xl transition hover:bg-white"
                            title="Sichtbarkeit"
                        >
                            <span class="sr-only">Sichtbarkeit</span>
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </button>
                        <div
                            x-show="filterMenu === 'visibility'"
                            x-cloak
                            class="absolute left-0 top-full mt-2 grid w-[min(320px,calc(100vw-1.5rem))] gap-3 rounded-lg border border-white/45 bg-white/80 p-3 text-xs font-semibold text-slate-600 shadow-2xl backdrop-blur-xl"
                        >
                            <label class="grid gap-1.5">
                                <span>Minimale Verbindungen</span>
                                <select data-network-filter-min-degree class="rounded-lg border-slate-200 bg-white text-sm font-bold text-slate-900 focus:border-slate-400 focus:ring-slate-400">
                                    <option value="0">0</option>
                                    <option value="1">1</option>
                                    <option value="2" selected>2</option>
                                    <option value="3">3</option>
                                    <option value="4">4</option>
                                    <option value="5">5</option>
                                    <option value="8">8</option>
                                </select>
                            </label>
                            <label class="grid gap-1.5">
                                <span>Maximal sichtbare Profile</span>
                                <select data-network-filter-max-profiles class="rounded-lg border-slate-200 bg-white text-sm font-bold text-slate-900 focus:border-slate-400 focus:ring-slate-400">
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="150">150</option>
                                    <option value="250" selected>250</option>
                                </select>
                            </label>
                            <p class="leading-5 text-slate-500">Die Map lädt grundsätzlich höchstens 250 Profile. Profilbilder werden nur für die 50 engsten Kontakte geladen.</p>
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 leading-5">
                                <div data-network-visible-profiles-count>0 sichtbar</div>
                                <div>Effektives Minimum: <span data-network-effective-min-degree>0</span></div>
                            </div>
                        </div>
                    </div>

                    <div class="relative" x-on:click.outside="if (filterMenu === 'display') filterMenu = null">
                        <button
                            type="button"
                            x-on:click="filterMenu = filterMenu === 'display' ? null : 'display'"
                            x-bind:aria-expanded="filterMenu === 'display'"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-white/40 bg-white/75 text-slate-800 shadow-lg backdrop-blur-xl transition hover:bg-white"
                            title="Darstellung"
                        >
                            <span class="sr-only">Darstellung</span>
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M4 7h16M7 12h10M10 17h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                        <div
                            x-show="filterMenu === 'display'"
                            x-cloak
                            class="absolute left-0 top-full mt-2 grid w-[min(420px,calc(100vw-1.5rem))] gap-3 rounded-lg border border-white/45 bg-white/80 p-3 text-xs font-semibold text-slate-600 shadow-2xl backdrop-blur-xl"
                        >
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" data-network-view-mode="2d" data-active-classes="border-slate-900 bg-slate-900 text-white" data-inactive-classes="border-slate-200 bg-white/55 text-slate-500" class="inline-flex items-center justify-center gap-2 rounded-lg border px-3 py-2 shadow-sm transition" aria-pressed="true">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 6h16v12H4zM8 10h8M8 14h5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    2D
                                </button>
                                <button type="button" data-network-view-mode="3d" data-active-classes="border-indigo-400 bg-indigo-50/90 text-indigo-900" data-inactive-classes="border-slate-200 bg-white/55 text-slate-500" class="inline-flex items-center justify-center gap-2 rounded-lg border px-3 py-2 shadow-sm transition" aria-pressed="false">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M12 12 4 7.5M12 12l8-4.5M12 12v9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                    3D Prototyp
                                </button>
                            </div>
                            <div class="grid gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
                                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">3D-Hintergrund</div>
                                <div class="grid grid-cols-2 gap-2">
                                    <button type="button" data-network-three-theme="dark" data-active-classes="border-indigo-400 bg-slate-950 text-white" data-inactive-classes="border-slate-200 bg-white/55 text-slate-500" class="inline-flex items-center justify-center gap-2 rounded-lg border px-3 py-2 shadow-sm transition" aria-pressed="true">
                                        <span class="h-3 w-3 rounded-full bg-gradient-to-br from-slate-950 via-indigo-900 to-fuchsia-700 ring-1 ring-white/40"></span>
                                        Dunkel
                                    </button>
                                    <button type="button" data-network-three-theme="light" data-active-classes="border-sky-300 bg-sky-50/90 text-sky-900" data-inactive-classes="border-slate-200 bg-white/55 text-slate-500" class="inline-flex items-center justify-center gap-2 rounded-lg border px-3 py-2 shadow-sm transition" aria-pressed="false">
                                        <span class="h-3 w-3 rounded-full bg-gradient-to-br from-white via-sky-100 to-indigo-200 ring-1 ring-sky-200"></span>
                                        Hell
                                    </button>
                                </div>
                            </div>
                            <label class="grid gap-1.5">
                                <span>Anordnung</span>
                                <select data-network-layout-mode class="rounded-lg border-slate-200 bg-white text-sm font-bold text-slate-900 focus:border-slate-400 focus:ring-slate-400">
                                    <option value="clusters">Cluster</option>
                                    <option value="spiral">Spirale</option>
                                    <option value="radial">Radial</option>
                                    <option value="concentric">Ringe</option>
                                    <option value="grid">Raster</option>
                                </select>
                            </label>
                            <label class="grid grid-cols-[7.5rem_1fr_3.5rem] items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
                                <span>Abstand</span>
                                <input type="range" min="50" max="500" step="10" value="100" data-network-layout-spacing class="w-full accent-slate-900">
                                <span class="text-right text-slate-900" data-network-layout-spacing-value>100%</span>
                            </label>
                            <label class="grid grid-cols-[7.5rem_1fr_3.5rem] items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
                                <span>Icongroesse</span>
                                <input type="range" min="50" max="500" step="10" value="100" data-network-icon-scale class="w-full accent-slate-900">
                                <span class="text-right text-slate-900" data-network-icon-scale-value>100%</span>
                            </label>
                            <label class="grid grid-cols-[7.5rem_1fr_3.5rem] items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
                                <span>Unterschied</span>
                                <input type="range" min="0" max="400" step="10" value="100" data-network-size-variance class="w-full accent-slate-900">
                                <span class="text-right text-slate-900" data-network-size-variance-value>100%</span>
                            </label>
                            <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3">
                                <button type="button" data-network-action="zoom-out" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white/70 transition hover:bg-white" title="Rauszoomen">
                                    <span class="sr-only">Rauszoomen</span>
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                </button>
                                <button type="button" data-network-action="zoom-in" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white/70 transition hover:bg-white" title="Reinzoomen">
                                    <span class="sr-only">Reinzoomen</span>
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                </button>
                                <button type="button" data-network-action="fit" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white/70 transition hover:bg-white" title="Einpassen">
                                    <span class="sr-only">Einpassen</span>
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <button type="button" data-network-layout-reset class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white/70 transition hover:bg-white" title="Neu anordnen">
                                    <span class="sr-only">Neu anordnen</span>
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.35-5.65M20 4v6h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                            </div>
                            <div class="text-slate-500" data-network-layout-state>Nicht gespeichert</div>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    x-show="mapFullscreen"
                    x-cloak
                    x-on:click="closeMap()"
                    class="absolute right-3 top-3 z-30 inline-flex h-10 w-10 items-center justify-center rounded-lg border border-white/40 bg-white/75 text-slate-800 shadow-lg backdrop-blur-xl transition hover:bg-white"
                    title="Schliessen"
                >
                    <span class="sr-only">Schliessen</span>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>

                @if($trackedPeople->isEmpty())
                    <div class="p-8 text-sm text-slate-500">
                        Noch keine Personen vorhanden. Lege zuerst Personen an, damit das Netzwerk dargestellt werden kann.
                    </div>
                @else
                    <div
                        class="relative bg-slate-100"
                        x-bind:class="mapFullscreen ? 'h-screen min-h-0' : 'h-[420px] min-h-[420px] cursor-zoom-in'"
                        x-on:click="if (!mapFullscreen) openMap()"
                        wire:ignore
                    >
                        <div data-network-canvas class="absolute inset-0 bg-slate-100"></div>
                        <div data-network-3d-canvas class="pointer-events-auto absolute inset-0 hidden bg-slate-950"></div>
                        <div data-network-profile-overlays class="pointer-events-none absolute inset-0 z-[4]"></div>
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
                        <div class="flex items-center gap-2"><span class="h-2 w-8 rounded-full bg-violet-600"></span> Verknuepfung ueber gespeicherte Person</div>
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
        <div class="border-b border-slate-200 bg-slate-50 px-3 py-2">
            <div class="truncate font-bold text-slate-900" x-text="nodeMenu.name"></div>
            <div
                class="mt-0.5 truncate text-xs font-semibold text-slate-500"
                x-show="nodeMenu.handle && nodeMenu.handle !== nodeMenu.name"
                x-text="nodeMenu.handle"
            ></div>
        </div>
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
                        @if(! ($profilePreview['tracked_person_id'] ?? null))
                            <button
                                type="button"
                                wire:click="addPreviewProfileAsTrackedPerson"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-700 hover:bg-sky-100 disabled:opacity-50"
                            >
                                Als beobachtetes Profil anlegen
                            </button>
                        @endif
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
                        Scans aktualisieren das Profil ohne Tracking-Platz zu verbrauchen. Nur „Als beobachtetes Profil anlegen“ aktiviert dauerhaftes Tracking und zaehlt gegen dein Profil-Limit.
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
                            ?
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
