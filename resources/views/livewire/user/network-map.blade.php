<section class="{{ $embedded ? '' : 'mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8' }}">
    <div
        class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
        data-network-map-root
        data-network-map-id="{{ $mapId }}"
        data-network-filter-scope="{{ $embedded && $this->contextTrackedPersonId ? 'person-'.$this->contextTrackedPersonId : 'user-global' }}"
        data-network-max-visible-profiles="250"
        data-network-lazy="true"
        wire:init="prepareGraph"
        wire:loading.class="cursor-wait"
        x-data="{
            networkNode: { id: null, type: null, isKnownProfile: false },
            nodeMenu: { open: false, id: null, type: null, isKnownProfile: false, detailUrl: null, x: 0, y: 0 },
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
        x-on:click.outside="closeNodeMenu()"
    >
        <script type="application/json" data-network-map-payload>{"nodes":[],"edges":[]}</script>

        <div class="border-b border-slate-200 bg-white px-5 py-5">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-bold uppercase tracking-wide text-sky-700">
                        <span class="h-2 w-2 rounded-full bg-sky-500"></span>
                        Netzwerkmap
                    </div>
                    <h2 class="mt-3 text-2xl font-bold text-slate-950">Beziehungsnetzwerk</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Die Map visualisiert bekannte Profile, beobachtete Personen und rekonstruierte Verbindungen.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Personen</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['people'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Knoten</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['nodes'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Kanten</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['edges'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">System</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['systemWide'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="px-5 py-6">
            @if($trackedPeople->isEmpty())
                <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-sm text-slate-600">
                    Noch keine beobachteten Personen vorhanden.
                </div>
            @else
                <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-600">
                                <button type="button" data-network-view-mode="2d" data-active-classes="border-sky-300 bg-sky-50 text-sky-800" data-inactive-classes="border-slate-200 bg-white text-slate-500" class="rounded-lg border px-3 py-1.5 transition" aria-pressed="true">
                                    2D
                                </button>
                                <button type="button" data-network-view-mode="3d" data-active-classes="border-violet-300 bg-violet-50 text-violet-800" data-inactive-classes="border-slate-200 bg-white text-slate-500" class="rounded-lg border px-3 py-1.5 transition" aria-pressed="false">
                                    3D
                                </button>
                                <button type="button" data-network-filter="tracked" data-active-classes="border-emerald-300 bg-emerald-50 text-emerald-800" data-inactive-classes="border-slate-200 bg-white text-slate-500" class="rounded-lg border px-3 py-1.5 transition" aria-pressed="true">
                                    Beziehungen
                                </button>
                                <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-slate-600">
                                    <span>Min.</span>
                                    <select data-network-filter-min-degree class="border-0 bg-transparent p-0 text-xs font-bold text-slate-900 focus:ring-0">
                                        <option value="0">0</option>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="8">8</option>
                                    </select>
                                </label>
                                <label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-slate-600">
                                    <span>Max.</span>
                                    <select data-network-filter-max-profiles class="border-0 bg-transparent p-0 text-xs font-bold text-slate-900 focus:ring-0">
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                        <option value="150">150</option>
                                        <option value="250" selected>250</option>
                                    </select>
                                </label>
                            </div>

                            <div class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                                <button type="button" data-network-action="zoom-out" class="rounded-lg border border-slate-200 px-3 py-1.5 transition hover:bg-slate-50">-</button>
                                <button type="button" data-network-action="zoom-in" class="rounded-lg border border-slate-200 px-3 py-1.5 transition hover:bg-slate-50">+</button>
                                <button type="button" data-network-action="fit" class="rounded-lg border border-slate-200 px-3 py-1.5 transition hover:bg-slate-50">Reset</button>
                            </div>
                        </div>

                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="grid gap-3 text-xs text-slate-600 md:grid-cols-4">
                                <label class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                    <div class="mb-1 font-semibold text-slate-700">Layout</div>
                                    <select data-network-layout-mode class="w-full border-0 bg-transparent p-0 text-xs font-bold text-slate-900 focus:ring-0">
                                        <option value="clusters">Cluster</option>
                                        <option value="spiral">Spirale</option>
                                        <option value="radial">Radial</option>
                                        <option value="concentric">Konzentrisch</option>
                                        <option value="grid">Raster</option>
                                    </select>
                                </label>
                                <label class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                    <div class="mb-1 flex items-center justify-between gap-2 font-semibold text-slate-700">
                                        <span>Icons</span>
                                        <span data-network-icon-scale-value>100%</span>
                                    </div>
                                    <input type="range" min="50" max="500" value="100" data-network-icon-scale class="w-full">
                                </label>
                                <label class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                    <div class="mb-1 flex items-center justify-between gap-2 font-semibold text-slate-700">
                                        <span>Streuung</span>
                                        <span data-network-size-variance-value>100%</span>
                                    </div>
                                    <input type="range" min="0" max="400" value="100" data-network-size-variance class="w-full">
                                </label>
                                <label class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                    <div class="mb-1 flex items-center justify-between gap-2 font-semibold text-slate-700">
                                        <span>Abstand</span>
                                        <span data-network-layout-spacing-value>100%</span>
                                    </div>
                                    <input type="range" min="50" max="500" value="100" data-network-layout-spacing class="w-full">
                                </label>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                                <div>
                                    Sichtbar: <span data-network-visible-profiles-count>0</span>, Filter: <span data-network-effective-min-degree>0</span>
                                </div>
                                <div data-network-layout-state></div>
                                <button type="button" data-network-layout-reset class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-600 hover:bg-slate-50">
                                    Layout zuruecksetzen
                                </button>
                            </div>
                        </div>

                        <div class="relative h-[680px] min-h-[540px] bg-slate-50" wire:ignore>
                            <div data-network-canvas class="absolute inset-0"></div>
                            <div data-network-3d-canvas class="absolute inset-0 hidden"></div>
                            <div data-network-profile-overlays class="pointer-events-none absolute inset-0 z-10"></div>
                            <div data-network-public-badges class="pointer-events-none absolute inset-0 z-10"></div>

                            <div data-network-loading-panel class="absolute left-4 top-4 z-20 w-[min(420px,calc(100%-2rem))] rounded-xl border border-slate-200 bg-white/95 p-4 shadow-sm backdrop-blur">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-bold text-slate-950" data-network-build-label>Netzwerk wird vorbereitet</div>
                                        <div class="mt-1 text-xs leading-5 text-slate-500" data-network-build-text>Die gespeicherten Profile werden geladen.</div>
                                    </div>
                                    <div class="h-2.5 w-2.5 rounded-full bg-sky-500" data-network-build-dot></div>
                                </div>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full w-0 rounded-full bg-sky-500 transition-all duration-200" data-network-progress-bar></div>
                                </div>
                                <div class="mt-2 text-xs font-semibold text-slate-500" data-network-progress-count>Warte auf Daten</div>
                            </div>
                        </div>
                    </section>

                    <aside class="space-y-4">
                        @if(! $embedded)
                            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Hauptperson</h2>
                                <div class="mt-3 space-y-2">
                                    @foreach($trackedPeople as $person)
                                        <button
                                            type="button"
                                            wire:click="setPrimaryTrackedPerson({{ $person->id }})"
                                            class="flex w-full items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left text-sm transition {{ (int) $primaryTrackedPersonId === (int) $person->id ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}"
                                        >
                                            <span class="min-w-0 truncate font-semibold">{{ $person->display_name }}</span>
                                            @if((int) $primaryTrackedPersonId === (int) $person->id)
                                                <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">aktiv</span>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Auswahl</h2>
                            <p data-network-detail-empty class="mt-3 text-sm leading-6 text-slate-600">
                                Waehle einen Knoten im Netzwerk aus, um Details und direkte Verbindungen zu sehen.
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
                                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                                        x-on:click="$wire.openProfilePreview(networkNode.id)"
                                    >
                                        Profil oeffnen
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Verbundene Knoten</h2>
                            <div class="mt-3 space-y-2" data-network-connected-list>
                                <p class="text-sm text-slate-500">Keine direkte Auswahl.</p>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Legende</h2>
                            <div class="mt-3 space-y-2 text-sm text-slate-600">
                                <div class="flex items-center gap-2"><span class="h-2 w-8 rounded-full bg-emerald-600"></span> Gespeicherte Beziehungen</div>
                                <div class="flex items-center gap-2"><span class="h-2 w-8 rounded-full bg-rose-600"></span> Rekonstruierte Verbindung</div>
                                <div class="flex items-center gap-2"><span class="h-2 w-8 rounded-full bg-amber-500"></span> Vorschlag</div>
                                <div class="flex items-center gap-2"><span class="h-4 w-4 rounded-full bg-amber-400 ring-2 ring-amber-500"></span> Hauptperson</div>
                                <div class="flex items-center gap-2"><span class="h-4 w-4 rounded-full bg-slate-950"></span> Beobachtete Person</div>
                            </div>
                        </div>
                    </aside>
                </div>
            @endif
        </div>

        <div
            x-cloak
            x-show="nodeMenu.open"
            x-bind:style="`left: ${nodeMenu.x}px; top: ${nodeMenu.y}px`"
            class="fixed z-50 w-56 overflow-hidden rounded-lg border border-slate-200 bg-white text-sm shadow-xl"
        >
            <button type="button" class="block w-full px-3 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50" x-show="nodeMenu.type === 'person' && nodeMenu.detailUrl" x-on:click="window.location.href = nodeMenu.detailUrl; closeNodeMenu()">
                Person oeffnen
            </button>
            <button type="button" class="block w-full px-3 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50" x-show="nodeMenu.type !== 'person'" x-on:click="$wire.openProfilePreview(nodeMenu.id); closeNodeMenu()">
                Profil oeffnen
            </button>
        </div>
    </div>

    <x-modal wire:model="showProfilePreviewModal" maxWidth="3xl">
        <div class="flex max-h-[calc(100vh-2rem)] flex-col overflow-hidden sm:max-h-[85vh]">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5 sm:py-4">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Instagram-Profil</h3>
                    <p class="mt-1 text-sm text-slate-500">Profilvorschau aus der Netzwerkmap.</p>
                </div>
                <button type="button" wire:click="closeProfilePreview" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Schliessen
                </button>
            </div>

            <div class="overflow-y-auto px-4 py-4 sm:px-5">
                @if($profilePreview)
                    <div class="space-y-4">
                        <div class="flex items-start gap-4">
                            <div class="h-16 w-16 shrink-0 overflow-hidden rounded-full bg-slate-100 ring-2 ring-slate-200">
                                @if($profilePreview['image_url'] ?? null)
                                    <img src="{{ $profilePreview['image_url'] }}" alt="{{ $profilePreview['handle'] ?? 'Profil' }}" class="h-full w-full object-cover">
                                @else
                                    <div class="flex h-full w-full items-center justify-center text-xl font-bold text-slate-500">
                                        {{ strtoupper(substr(ltrim($profilePreview['username'] ?? '?', '@'), 0, 1)) }}
                                    </div>
                                @endif
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="break-words text-xl font-bold text-slate-950">{{ $profilePreview['display_name'] ?? 'Instagram-Profil' }}</h3>
                                    <span class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                        {{ $profilePreview['visibility'] ?? 'unknown' }}
                                    </span>
                                </div>
                                <div class="mt-1 text-sm font-semibold text-slate-500">{{ $profilePreview['handle'] ?? '' }}</div>
                                @if($profilePreview['last_status_message'] ?? null)
                                    <p class="mt-2 text-sm text-slate-600">{{ $profilePreview['last_status_message'] }}</p>
                                @elseif($profilePreview['biography'] ?? null)
                                    <p class="mt-2 text-sm text-slate-600">{{ $profilePreview['biography'] }}</p>
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

                        @if($profilePreview['profile_url'] ?? null)
                            <a href="{{ $profilePreview['profile_url'] }}" target="_blank" class="inline-flex rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                Instagram oeffnen
                            </a>
                        @endif
                    </div>
                @else
                    <p class="text-sm text-slate-500">Keine Profildaten geladen.</p>
                @endif
            </div>
        </div>
    </x-modal>
</section>
