<div class="container mx-auto space-y-4" x-data="{ toasts: [] }" x-init="window.addEventListener('toast', e => { toasts.push(e.detail); setTimeout(() => toasts.shift(), 3000); })" x-cloak>
    <div class="fixed top-4 right-4 z-50 space-y-2">
        <template x-for="(t, i) in toasts" :key="i">
            <div x-text="t.message" :class="t.type === 'success' ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white'" class="rounded-lg px-4 py-2 shadow"></div>
        </template>
    </div>
    <div
        wire:loading.flex
        wire:target="analyzeInstagram,analyzeInstagramMini,scanInstagramFollowersList,scanInstagramFollowingList,scanInstagramRelationshipList,scanPublicProfileConnections,scanInstagramSuggestions,scanInstagramSuggestionDeepSearch,scanInstagramPosts"
        class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-950/70 px-4"
    >
        <div class="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-lg border border-white/20 bg-white p-6 text-center shadow-2xl">
            <div class="mx-auto rounded-full bg-gradient-to-tr from-amber-400 via-rose-500 to-fuchsia-600 p-1">
                <div class="h-10 w-10 animate-spin rounded-full border-4 border-white/70 border-t-slate-950 bg-white"></div>
            </div>
            <div class="mt-4 text-xs font-semibold uppercase tracking-wide text-pink-700" wire:stream="instagram-progress-phase">Start</div>
            <div class="mt-2 inline-flex max-w-full items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                <span class="shrink-0 uppercase tracking-wide text-slate-400">Scraper-Profil</span>
                <span class="min-w-0 truncate text-slate-900" wire:stream="instagram-progress-scraper-profile">wird ermittelt</span>
            </div>
            <h3 class="mt-1 text-lg font-bold text-slate-950">Instagram-Scan laeuft</h3>
            <p class="mt-2 text-sm leading-6 text-slate-600" wire:stream="instagram-progress-message">
                Profil, Kennzahlen oder einzelne Listen werden abgearbeitet.
            </p>
            <div wire:stream="instagram-progress-live-preview"></div>
            <div class="mt-2 text-xs font-semibold text-slate-500" wire:stream="instagram-progress-live-counts"></div>
            <div wire:stream="instagram-progress-connection-results"></div>
            <div class="mt-5">
                <div class="flex items-center justify-between text-xs font-semibold text-slate-500">
                    <span>Fortschritt</span>
                    <span wire:stream="instagram-progress-percent">0%</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200" wire:stream="instagram-progress-bar">
                    <div class="h-full rounded-full bg-gradient-to-r from-rose-500 to-fuchsia-600" style="width: 0%"></div>
                </div>
            </div>
            <div class="mt-5 flex justify-center">
                <button
                    type="button"
                    data-instagram-stop-url="{{ route('tracked-people.instagram.stop-scan', $trackedPerson) }}"
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
                        })
                            .then(() => {
                                button.querySelector('[data-stop-label]').textContent = 'Stop angefordert. Speichert...';
                            })
                            .catch(() => {
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
        </div>
    </div>

    <x-profile.detail-hero id="profil">
        <x-slot:toolbar>
                <a
                    href="{{ route('dashboard') }}"
                    wire:navigate
                    class="inline-flex h-9 items-center justify-center rounded-3xl border border-white/80 bg-white/85 px-4 text-xs font-semibold text-slate-950 shadow-sm backdrop-blur hover:bg-white"
                >
                    Zurueck
                </a>
                <div class="flex items-center gap-3">
                    <x-profile.profile-monitoring-dropdown
                        :tracked-person="$trackedPerson"
                        :on-detail-page="true"
                    />
                    <x-ui.dropdown.anchor-dropdown
                        align="right"
                        width="auto"
                        :offset="8"
                        dropdown-classes=""
                        content-classes="w-64 rounded-3xl border border-slate-200 bg-white"
                    >
                        <x-slot name="trigger">
                            <button
                                type="button"
                                x-bind:aria-expanded="open"
                                class="inline-flex h-9 items-center justify-center rounded-3xl border border-white/80 bg-white/85 px-4 text-xs font-semibold text-slate-950 shadow-sm backdrop-blur hover:bg-white"
                            >
                                Aktionen
                                <span class="ml-2 text-slate-500">▾</span>
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            <div class="flex flex-col p-2">
                                <button
                                    type="button"
                                    @click="$dispatch('close')"
                                    wire:click="confirmTrackedPersonDeletion"
                                    class="w-full rounded-3xl px-3 py-2 text-left text-sm font-semibold text-rose-700 hover:bg-rose-50"
                                >
                                    Person löschen
                                </button>
                                <button
                                    type="button"
                                    @click="$dispatch('close')"
                                    wire:click="analyzeInstagramMini"
                                    wire:loading.attr="disabled"
                                    wire:target="analyzeInstagramMini"
                                    @disabled(! $trackedPerson->instagram_username)
                                    class="w-full rounded-3xl px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Mini-Scan
                                </button>
                                <button
                                    type="button"
                                    @click="$dispatch('close')"
                                    wire:click="$set('showSettingsModal', true)"
                                    class="w-full rounded-3xl px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                                >
                                    Einstellungen
                                </button>
                                <button
                                    type="button"
                                    @click="$dispatch('close')"
                                    wire:click="scanInstagramSuggestions"
                                    wire:loading.attr="disabled"
                                    wire:target="scanInstagramSuggestions"
                                    @disabled(! $trackedPerson->instagram_username)
                                    class="w-full rounded-3xl px-3 py-2 text-left text-sm font-semibold text-fuchsia-700 hover:bg-fuchsia-50 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Vorschlaege scannen
                                </button>
                                <button
                                    type="button"
                                    @click="$dispatch('close')"
                                    wire:click="scanInstagramSuggestionDeepSearch"
                                    wire:loading.attr="disabled"
                                    wire:target="scanInstagramSuggestionDeepSearch"
                                    @disabled(! $trackedPerson->instagram_username)
                                    class="w-full rounded-3xl px-3 py-2 text-left text-sm font-semibold text-fuchsia-700 hover:bg-fuchsia-50 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Vorschlaege DeepSearch
                                </button>
                            </div>
                        </x-slot>
                    </x-ui.dropdown.anchor-dropdown>
                </div>
        </x-slot:toolbar>

        <x-slot:identity>
            <x-profile.detail-identity
                :src="$trackedPerson->profile_image_url"
                :alt="$trackedPerson->display_name"
                initial="IG"
                :title="$trackedPerson->instagram_username ? '@'.$trackedPerson->instagram_username : $trackedPerson->display_name"
                :frame-class="$profileImageFrameClass"
                :status-dot-class="$profileStatusDotClass"
                :status-label="$latestProfileVisibilityLabel"
                :muted-image="$latestProfileIsPrivate"
            />
        </x-slot:identity>

        <x-slot:metrics>
                        @if($latestFollowerListAvailable || $latestProfileIsPublic)
                            <x-profile.detail-metric
                                as="button"
                                label="Follower"
                                :value="$trackedPerson->instagram_followers_count !== null ? number_format($trackedPerson->instagram_followers_count) : '-'"
                                tone="emerald"
                                :muted="! $latestFollowerListAvailable"
                                wire:click="$dispatch('open-tracked-person-relationship-list', { listType: 'followers' })"
                                title="{{ $latestFollowerListAvailable ? 'Followerliste oeffnen' : 'Followerliste scannen' }}"
                            >
                                {{ $latestFollowerListAvailable ? 'Liste' : 'Keine Liste' }}
                            </x-profile.detail-metric>
                        @else
                            <x-profile.detail-metric
                                label="Follower"
                                :value="$trackedPerson->instagram_followers_count !== null ? number_format($trackedPerson->instagram_followers_count) : '-'"
                                :muted="true"
                            >
                                Keine Liste
                            </x-profile.detail-metric>
                        @endif

                        @if($latestFollowingListAvailable || $latestProfileIsPublic)
                            <x-profile.detail-metric
                                as="button"
                                label="Gefolgt"
                                :value="$trackedPerson->instagram_following_count !== null ? number_format($trackedPerson->instagram_following_count) : '-'"
                                tone="sky"
                                :muted="! $latestFollowingListAvailable"
                                wire:click="$dispatch('open-tracked-person-relationship-list', { listType: 'following' })"
                                title="{{ $latestFollowingListAvailable ? 'Gefolgt-Liste oeffnen' : 'Gefolgt-Liste scannen' }}"
                            >
                                {{ $latestFollowingListAvailable ? 'Liste' : 'Keine Liste' }}
                            </x-profile.detail-metric>
                        @else
                            <x-profile.detail-metric
                                label="Gefolgt"
                                :value="$trackedPerson->instagram_following_count !== null ? number_format($trackedPerson->instagram_following_count) : '-'"
                                :muted="true"
                            >
                                Keine Liste
                            </x-profile.detail-metric>
                        @endif

                        <x-profile.detail-metric
                            label="Beitraege"
                            :value="$trackedPerson->instagram_posts_count !== null ? number_format($trackedPerson->instagram_posts_count) : '-'"
                            tone="violet"
                        >
                            @if($latestProfileIsPublic)
                                <button
                                    type="button"
                                    wire:click="scanInstagramPosts"
                                    wire:loading.attr="disabled"
                                    wire:target="scanInstagramPosts"
                                    @disabled(! $trackedPerson->instagram_username)
                                    class="rounded-full bg-violet-50 px-2 py-0.5 text-[10px] text-violet-700 ring-1 ring-violet-200 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Scan
                                </button>
                            @else
                                Profilmetriken
                            @endif
                        </x-profile.detail-metric>
        </x-slot:metrics>

        <x-slot:badges>
            <span class="rounded-2xl px-3 py-1 text-xs font-semibold ring-1 {{ $instagramStatusBadgeClass }}">{{ $instagramStatusLabel }}</span>
            <span class="rounded-2xl px-3 py-1 text-xs font-semibold ring-1 {{ $latestProfileVisibilityBadgeClass }}">{{ $latestProfileVisibilityLabel }}</span>
            @if($trackedPerson->monitoring_enabled)
                <span class="rounded-2xl bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Live</span>
            @endif
        </x-slot:badges>

            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_280px]">
                <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
                    @if($trackedPerson->last_instagram_status_message)
                        <p class="rounded-3xl border border-slate-200 bg-white px-3 py-2 text-sm leading-6 text-slate-600">
                            {{ $trackedPerson->last_instagram_status_message }}
                        </p>
                    @endif

                    <div class="mt-4 flex flex-wrap gap-2 text-xs">
                        @if($trackedPerson->instagram_username)
                            <span class="rounded-2xl bg-pink-50 px-3 py-1 font-semibold text-pink-700 ring-1 ring-pink-100">Instagram</span>
                        @endif
                        @if($trackedPerson->monitoring_enabled)
                            <span class="rounded-2xl bg-slate-950 px-3 py-1 font-semibold text-white">Dauerbeobachtung aktiv</span>
                        @endif
                        @if($trackedPerson->notify_social_changes && $trackedPerson->notify_instagram_changes)
                            <span class="rounded-2xl bg-sky-50 px-3 py-1 font-semibold text-sky-700 ring-1 ring-sky-100">Benachrichtigungen aktiv</span>
                        @endif
                        @if($trackedPerson->last_instagram_analyzed_at)
                            <span class="rounded-2xl bg-slate-100 px-3 py-1 font-semibold text-slate-700">{{ $trackedPerson->last_instagram_analyzed_at->copy()->timezone(config('app.timezone'))->diffForHumans() }}</span>
                        @endif
                    </div>
                </div>

                <div class="grid gap-2 sm:grid-cols-1">
                    @if($trackedPerson->exists)
                        <button
                            type="button"
                            wire:click="analyzeInstagram"
                            wire:loading.attr="disabled"
                            wire:target="analyzeInstagram"
                            @disabled(! $trackedPerson->instagram_username)
                            class="inline-flex h-11 items-center justify-center rounded-3xl bg-gradient-to-r from-rose-500 to-fuchsia-600 px-4 text-sm font-semibold text-white shadow-sm hover:from-rose-600 hover:to-fuchsia-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="analyzeInstagram">Vollanalyse</span>
                            <span wire:loading wire:target="analyzeInstagram">Laeuft...</span>
                        </button>
                    @else
                        <div class="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                            Alle übrigen Aktionen sind jetzt im Menü oben rechts verfügbar.
                        </div>
                    @endif
                </div>
            </div>

            @if($detailStatus)
                <div class="mt-4 rounded-3xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm {{ $detailStatusClass }}">
                    {{ $detailStatus }}
                </div>
            @endif

    </x-profile.detail-hero>

    <livewire:user.instagram-scan-activity-panel
        :tracked-person-id="$trackedPerson->id"
        lazy
    />

    <livewire:user.tracked-person-relationship-lists
        :tracked-person-id="$trackedPerson->id"
        :key="'tracked-person-relationship-lists-'.$trackedPerson->id"
    />

    <x-modal wire:model="showSettingsModal" maxWidth="2xl">
        <div class="flex max-h-[calc(100vh-2rem)] flex-col overflow-hidden">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5 sm:py-4">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Instagram-Einstellungen</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Profilhandle, Dauerbeobachtung und Instagram-Benachrichtigungen.
                    </p>
                </div>
                <button type="button" x-on:click="$dispatch('close')" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Schliessen
                </button>
            </div>

            <div class="overflow-y-auto p-4 sm:p-5">
                <div>
                    <h4 class="text-sm font-bold text-slate-900">Personendaten</h4>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Vorname</label>
                            <input type="text" wire:model.defer="first_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('first_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Nachname</label>
                            <input type="text" wire:model.defer="last_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            @error('last_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Alias</label>
                            <input type="text" wire:model.defer="alias" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Geburtsdatum</label>
                            <input type="date" wire:model.defer="date_of_birth" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Ort</label>
                            <input type="text" wire:model.defer="city" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Land</label>
                            <input type="text" wire:model.defer="country" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Instagram</label>
                            <input type="text" wire:model.defer="instagram_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500" placeholder="@username">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-slate-700">Notizen</label>
                            <textarea wire:model.defer="notes" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"></textarea>
                        </div>
                    </div>
                </div>

                <div class="mt-5 border-t border-slate-200 pt-4">
                    <h4 class="text-sm font-bold text-slate-900">Benachrichtigungen</h4>
                    <div class="mt-3 grid gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Zustellung</label>
                            <select wire:model.defer="notification_delivery_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                <option value="both">Interne Nachricht und E-Mail</option>
                                <option value="message">Nur interne Nachricht</option>
                                <option value="mail">Nur E-Mail</option>
                            </select>
                            @error('notification_delivery_type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="monitoring_enabled" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span class="font-medium">Dauerbeobachtung aktivieren</span>
                        </label>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Scan-Intervall dieser Person</label>
                            <select wire:model.defer="monitoring_interval_minutes" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                <option value="1">Jede Minute</option>
                                <option value="5">Alle 5 Minuten</option>
                                <option value="10">Alle 10 Minuten</option>
                                <option value="15">Alle 15 Minuten</option>
                                <option value="30">Alle 30 Minuten</option>
                                <option value="60">Stuendlich</option>
                                <option value="180">Alle 3 Stunden</option>
                                <option value="360">Alle 6 Stunden</option>
                                <option value="720">Alle 12 Stunden</option>
                                <option value="1440">Taeglich</option>
                                <option value="10080">Woechentlich</option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Der Scheduler prueft jede Minute, ob dieses Profil wieder faellig ist.</p>
                            @error('monitoring_interval_minutes') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Automatischer Basis-Scan</label>
                            <select wire:model.defer="instagram_monitoring_scan_mode" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                <option value="mini">Mini-Scan</option>
                                <option value="full">Vollanalyse</option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Gilt fuer die Dauerbeobachtung dieser Person.</p>
                            @error('instagram_monitoring_scan_mode') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <div class="text-sm font-bold text-slate-900">Automatische Folge-Scans</div>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Diese Scans starten nach einem Basis-Scan nur, wenn die Regeln unten greifen. Manuelle Scans bleiben weiterhin moeglich.
                            </p>
                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    <input type="checkbox" wire:model.defer="instagram_auto_scan_followers" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                    <span>Followerliste</span>
                                </label>
                                <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    <input type="checkbox" wire:model.defer="instagram_auto_scan_following" class="rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                                    <span>Gefolgt-Liste</span>
                                </label>
                                <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    <input type="checkbox" wire:model.defer="instagram_auto_scan_posts" class="rounded border-slate-300 text-violet-600 focus:ring-violet-500">
                                    <span>Beitraege</span>
                                </label>
                                <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    <input type="checkbox" wire:model.defer="instagram_auto_scan_suggestions" class="rounded border-slate-300 text-fuchsia-600 focus:ring-fuchsia-500">
                                    <span>Vorschlaege</span>
                                </label>
                            </div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                <input type="checkbox" wire:model.defer="instagram_auto_scan_on_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                <span class="font-medium">Bei relevanten Aenderungen starten</span>
                            </label>
                            <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                <input type="checkbox" wire:model.defer="instagram_auto_scan_on_interval" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                <span class="font-medium">Nach Mindestabstand erneut starten</span>
                            </label>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Mindestabstand fuer Folge-Scans</label>
                                <input type="number" min="0" max="10080" step="1" wire:model.defer="instagram_auto_scan_min_interval_minutes" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                <p class="mt-1 text-xs text-slate-500">0 ignoriert den Abstand; 60 entspricht einer Stunde.</p>
                                @error('instagram_auto_scan_min_interval_minutes') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Aenderung ab Anzahl</label>
                                <input type="number" min="1" max="1000000" step="1" wire:model.defer="instagram_auto_scan_count_change_threshold" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                <p class="mt-1 text-xs text-slate-500">Bezieht sich auf Follower-, Gefolgt- und Beitragszaehler.</p>
                                @error('instagram_auto_scan_count_change_threshold') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_social_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span class="font-medium">Benachrichtigungen fuer Instagram-Aenderungen aktivieren</span>
                        </label>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Instagram-Kanal</div>
                    <div class="mt-2 grid gap-2">
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_instagram_changes" class="rounded border-slate-300 text-pink-600 focus:ring-pink-500">
                            <span>Instagram-Aenderungen melden</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 px-4 py-3 sm:flex-row sm:justify-end sm:px-5">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Abbrechen
                </button>
                <button
                    type="button"
                    wire:click="saveTrackedPerson"
                    wire:loading.attr="disabled"
                    wire:target="saveTrackedPerson"
                    class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Einstellungen speichern
                </button>
            </div>
        </div>
    </x-modal>

    <x-modal wire:model="showDeleteConfirmationModal" maxWidth="lg">
        <div class="overflow-hidden">
            <div class="border-b border-slate-200 px-4 py-3 sm:px-5 sm:py-4">
                <h3 class="text-lg font-bold text-slate-900">Person loeschen</h3>
                <p class="mt-1 text-sm text-slate-500">Diese Aktion kann nicht rueckgaengig gemacht werden.</p>
            </div>
            <div class="px-4 py-4 text-sm leading-6 text-slate-700 sm:px-5">
                <p>
                    Soll
                    <span class="font-semibold text-slate-900">{{ $trackedPerson->display_name }}</span>
                    wirklich geloescht werden?
                </p>
                <p class="mt-3">
                    Damit werden auch gespeicherte Instagram-Scans, Profile, Medien und Verknuepfungen dieser Person entfernt.
                </p>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
            <button
                type="button"
                wire:click="cancelTrackedPersonDeletion"
                class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
            >
                Abbrechen
            </button>
            <button
                type="button"
                wire:click="deleteTrackedPerson"
                wire:loading.attr="disabled"
                wire:target="deleteTrackedPerson"
                class="ml-3 inline-flex items-center justify-center rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-700 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="deleteTrackedPerson">Endgueltig loeschen</span>
                <span wire:loading wire:target="deleteTrackedPerson">Loesche...</span>
            </button>
            </div>
        </div>
    </x-modal>

    <section
        id="profilinfos"
        class="scroll-mt-4"
        x-data="{ networkMapRequested: @js($showNetworkMap) }"
        x-on:ui-tab-selected="
            if ($event.detail.tab === 'verbindungen' && !networkMapRequested) {
                networkMapRequested = true;
                $wire.loadNetworkMap();
            }
        "
    >
        <x-ui.accordion.tabs
            :tabs="[
                'analyse' => 'Analysen',
                'verbindungen' => 'Verbindungen',
            ]"
            default="analyse"
            :persist="false"
            collapse-at="sm"
        >
            <x-ui.accordion.tab-panel
                for="verbindungen"
                panel-class="scroll-mt-4 space-y-4 rounded-b-lg rounded-se-lg border border-blue-300 bg-white p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">Bekannte Instagram-Profile</h3>
                        <p class="mt-1 text-sm text-slate-600">
                            Hier verknuepfst du andere bereits beobachtete Instagram-Profile, die mit diesem Profil eng verbunden sind.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="scanInstagramSuggestions"
                            wire:loading.attr="disabled"
                            wire:target="scanInstagramSuggestions"
                            @disabled(! $trackedPerson->instagram_username)
                            title="Erfasst nur die direkten Vorschlaege des gesuchten Profils."
                            class="rounded-xl border border-pink-200 bg-white px-4 py-2 text-sm font-semibold text-pink-700 shadow-sm hover:bg-pink-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Vorschlaege-Scan
                        </button>
                        <button
                            type="button"
                            wire:click="scanInstagramSuggestionDeepSearch"
                            wire:loading.attr="disabled"
                            wire:target="scanInstagramSuggestionDeepSearch"
                            @disabled(! $trackedPerson->instagram_username)
                            title="Prueft Vorschlaege der Vorschlaege und bei oeffentlichen Kandidaten deren Follower- und Gefolgt-Listen."
                            class="rounded-xl border border-pink-200 bg-pink-50 px-4 py-2 text-sm font-semibold text-pink-700 shadow-sm hover:bg-pink-100 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Vorschlaege DeepSearch
                        </button>
                        <button
                            type="button"
                            wire:click="scanPublicProfileConnections"
                            wire:loading.attr="disabled"
                            wire:target="scanPublicProfileConnections"
                            @disabled($trackedPerson->publicProfiles->isEmpty() || ! $trackedPerson->instagram_username)
                            class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Listenverbindungen pruefen
                        </button>
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-white p-3">
                    @if($showNetworkMap)
                        <livewire:user.network-map
                            :tracked-person-id="$trackedPerson->id"
                            :embedded="true"
                            :key="'tracked-person-network-map-'.$trackedPerson->id"
                        />
                    @else
                        <div class="flex h-[420px] items-center justify-center rounded-lg border border-slate-200 bg-slate-100 px-4 text-center text-sm font-semibold text-slate-500">
                            Die Network Map wird beim Oeffnen dieses Tabs geladen.
                        </div>
                    @endif
                </div>

                <div class="mt-3 space-y-2">
                    @forelse($publicProfileRows as $publicProfileRow)
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-semibold text-slate-900">
                                            {{ $publicProfileRow->profile->display_name ?: $publicProfileRow->profile->display_handle }}
                                        </span>
                                        <span class="rounded-full bg-white px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                            {{ $publicProfileRow->profile->platform }}
                                        </span>
                                        <span class="rounded-full bg-sky-100 px-2 py-1 text-[11px] font-semibold text-sky-800">
                                            {{ $publicProfileRow->profile->relationship_label }}
                                        </span>
                                        @if($publicProfileRow->profile->is_public)
                                            <span class="rounded-full bg-emerald-100 px-2 py-1 text-[11px] font-semibold text-emerald-800">
                                                Oeffentlich bestaetigt
                                            </span>
                                        @endif
                                    </div>
                                    <div class="mt-1 text-slate-600">{{ $publicProfileRow->profile->display_handle }}</div>
                                    @if($publicProfileRow->profile->notes)
                                        <p class="mt-2 whitespace-pre-wrap text-xs text-slate-500">{{ $publicProfileRow->profile->notes }}</p>
                                    @endif
                                    @if($publicProfileRow->latestConnectionScan)
                                        <div class="mt-3 rounded-xl border px-3 py-2 text-xs {{ $publicProfileRow->connectionStatusClass }}">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-semibold">Teilrekonstruktion</span>
                                                <span>{{ $publicProfileRow->latestConnectionScan->analyzed_at ? $publicProfileRow->latestConnectionScan->analyzed_at->timezone(config('app.timezone'))->format('d.m.Y H:i') : '-' }}</span>
                                            </div>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                @if($publicProfileRow->latestConnectionSummary->inferredFollowerCount > 0)
                                                    <span class="rounded-full bg-white/80 px-2 py-1 font-semibold">{{ $publicProfileRow->latestConnectionSummary->inferredFollowerCount }} moegliche Follower</span>
                                                @endif
                                                @if($publicProfileRow->latestConnectionSummary->inferredFollowingCount > 0)
                                                    <span class="rounded-full bg-white/80 px-2 py-1 font-semibold">{{ $publicProfileRow->latestConnectionSummary->inferredFollowingCount }} moeglich gefolgt</span>
                                                @endif
                                                @if($publicProfileRow->latestConnectionSummary->inferredFollowerCount === 0 && $publicProfileRow->latestConnectionSummary->inferredFollowingCount === 0)
                                                    <span class="rounded-full bg-white/80 px-2 py-1 font-semibold">Keine Treffer in Kandidatenlisten</span>
                                                @endif
                                            </div>
                                            <div class="mt-2 text-[11px]">
                                                Kandidaten: {{ data_get($publicProfileRow->latestConnectionScan->raw_payload, 'candidatesChecked', 0) }}
                                                / private oder gesperrte Profile: {{ data_get($publicProfileRow->latestConnectionScan->raw_payload, 'candidatesSkippedPrivate', 0) }}
                                                / Rate-Limit: {{ data_get($publicProfileRow->latestConnectionScan->raw_payload, 'candidatesRateLimited', 0) }}
                                                / Fehler: {{ data_get($publicProfileRow->latestConnectionScan->raw_payload, 'candidatesFailed', 0) }}
                                                @if($publicProfileRow->latestConnectionSummary->screenshotUrl)
                                                    / <a href="{{ $publicProfileRow->latestConnectionSummary->screenshotUrl }}" target="_blank" class="font-semibold underline decoration-current/40 underline-offset-2">Screenshot</a>
                                                @endif
                                                @if($publicProfileRow->latestConnectionSummary->candidateErrorScreenshotUrl)
                                                    / <a href="{{ $publicProfileRow->latestConnectionSummary->candidateErrorScreenshotUrl }}" target="_blank" class="font-semibold underline decoration-current/40 underline-offset-2">Kandidatenfehler {{ $publicProfileRow->latestConnectionSummary->candidateErrorScreenshots->count() }}</a>
                                                @endif
                                            </div>
                                            @if($publicProfileRow->latestConnectionSummary->screenshots->isNotEmpty())
                                                <details class="mt-3 rounded-xl border border-white/70 bg-white/70 p-2">
                                                    <summary class="cursor-pointer text-[11px] font-semibold uppercase tracking-wide text-slate-700">
                                                        Screenshots anzeigen ({{ $publicProfileRow->latestConnectionSummary->screenshots->count() }})
                                                    </summary>
                                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                        @foreach($publicProfileRow->latestConnectionSummary->screenshots->take(8) as $screenshot)
                                                            <a href="{{ $screenshot['url'] }}" target="_blank" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                                                <img src="{{ $screenshot['url'] }}" alt="{{ $screenshot['label'] }}" class="h-32 w-full object-cover">
                                                                <div class="border-t border-slate-200 px-2 py-1.5 text-[11px] text-slate-600">
                                                                    <span class="font-semibold text-slate-800">{{ $screenshot['label'] }}</span>
                                                                    @if($screenshot['meta'])
                                                                        <span class="ml-1">{{ $screenshot['meta'] }}</span>
                                                                    @endif
                                                                </div>
                                                            </a>
                                                        @endforeach
                                                    </div>
                                                </details>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @if($publicProfileRow->profile->resolved_profile_url)
                                        <a href="{{ $publicProfileRow->profile->resolved_profile_url }}" target="_blank" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-white">
                                            Profil oeffnen
                                        </a>
                                    @endif
                                    <button wire:click="deletePublicProfile({{ $publicProfileRow->profile->id }})" class="rounded-xl border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                        Entfernen
                                    </button>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Bisher wurden noch keine beobachteten Instagram-Profile als Verbindung hinterlegt.</p>
                    @endforelse
                </div>

                <div class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Beobachtetes Profil</label>
                        @php($selectedProfilePickerRow = $profilePickerRows->firstWhere('value', (string) $publicProfileTrackedPersonId))

                        <div x-data="{ open: false }" class="relative">
                            <button
                                type="button"
                                @click="open = ! open"
                                class="flex w-full items-center justify-between rounded-xl border border-slate-300 bg-white px-3 py-2 text-left shadow-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    @if($selectedProfilePickerRow?->imageUrl)
                                        <img src="{{ $selectedProfilePickerRow->imageUrl }}" alt="{{ $selectedProfilePickerRow->title }}" class="h-10 w-10 rounded-full object-cover">
                                    @else
                                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-500">
                                            {{ strtoupper(substr(ltrim((string) ($selectedProfilePickerRow?->title ?? '@?'), '@'), 0, 1)) }}
                                        </div>
                                    @endif
                                    <div class="min-w-0">
                                        @if($selectedProfilePickerRow)
                                            <div class="truncate text-sm font-semibold text-slate-900">{{ $selectedProfilePickerRow->title }}</div>
                                            <div class="truncate text-xs text-slate-500">{{ $selectedProfilePickerRow->subtitle }}</div>
                                            <div class="truncate text-[11px] text-slate-400">{{ $selectedProfilePickerRow->meta }}</div>
                                        @else
                                            <div class="text-sm font-semibold text-slate-900">Profil auswaehlen</div>
                                            <div class="text-xs text-slate-500">Beobachtete oder rekonstruierte Profile</div>
                                        @endif
                                    </div>
                                </div>
                                <span class="ml-3 shrink-0 text-slate-400">▾</span>
                            </button>

                            <div
                                x-show="open"
                                x-cloak
                                @click.outside="open = false"
                                class="absolute z-20 mt-2 max-h-96 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl"
                            >
                                <div class="max-h-96 overflow-y-auto p-2">
                                    @if($profilePickerRows->isNotEmpty())
                                        <div class="px-2 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Auswahl</div>
                                        @foreach($profilePickerRows as $pickerRow)
                                            <div class="flex items-center gap-2 rounded-xl p-2 hover:bg-slate-50">
                                                <button
                                                    type="button"
                                                    @click="open = false"
                                                    wire:click="$set('publicProfileTrackedPersonId', '{{ $pickerRow->value }}')"
                                                    class="flex min-w-0 flex-1 items-center gap-3 text-left"
                                                >
                                                    @if($pickerRow->imageUrl)
                                                        <img src="{{ $pickerRow->imageUrl }}" alt="{{ $pickerRow->title }}" class="h-10 w-10 rounded-full object-cover">
                                                    @else
                                                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-500">
                                                            {{ strtoupper(substr(ltrim((string) $pickerRow->title, '@'), 0, 1)) }}
                                                        </div>
                                                    @endif
                                                    <div class="min-w-0">
                                                        <div class="truncate text-sm font-semibold text-slate-900">{{ $pickerRow->title }}</div>
                                                        <div class="truncate text-xs text-slate-500">{{ $pickerRow->subtitle }}</div>
                                                        <div class="truncate text-[11px] text-slate-400">{{ $pickerRow->meta }}</div>
                                                    </div>
                                                </button>

                                                @if($pickerRow->canDelete)
                                                    <button
                                                        type="button"
                                                        wire:click="deleteReconstructedProfileCandidate('{{ $pickerRow->deleteValue }}')"
                                                        wire:confirm="Rekonstruiertes Profil wirklich entfernen? Alle zugehörigen rekonstruierten Verbindungen werden gelöscht."
                                                        class="shrink-0 rounded-lg border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-50"
                                                    >
                                                        Löschen
                                                    </button>
                                                @endif
                                            </div>
                                        @endforeach
                                    @else
                                        <div class="p-3 text-sm text-slate-500">Keine Profile zur Auswahl vorhanden.</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @error('publicProfileTrackedPersonId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        @if($publicProfileCandidates->isEmpty() && $reconstructedProfileCandidates->isEmpty())
                            <p class="mt-2 text-xs text-amber-700">
                                Es gibt noch kein anderes beobachtetes Instagram-Profil als Vorschlag. Du kannst hier ein rekonstruertes oder frei eingetragenes Profil speichern.
                            </p>
                        @endif

                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Anderes Instagram-Profil</label>
                                <input type="text" wire:model.defer="manualPublicProfileUsername" placeholder="@username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500">
                                @error('manualPublicProfileUsername') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <p class="mt-2 text-xs text-slate-500">
                            Wähle ein vorgeschlagenes Profil aus dem Dropdown oder gib ein frei eintragbares Instagram-Profil ein. Die Verbindung wird über den einzigen Button unten gespeichert.
                        </p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-1 lg:grid-cols-1">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Beziehungsart</label>
                            <select wire:model.defer="publicProfileRelationshipType" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500">
                                <option value="close_friend">Enger Freund</option>
                                <option value="acquaintance">Bekannter</option>
                                <option value="family">Familienmitglied</option>
                                <option value="follows_target">Folgt der Person</option>
                                <option value="followed_by_target">Wird von der Person gefolgt</option>
                                <option value="mutual">Gegenseitige Verbindung</option>
                                <option value="public_connection">Allgemeine oeffentliche Verbindung</option>
                            </select>
                            @error('publicProfileRelationshipType') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button wire:click="savePublicProfile" @disabled(!$publicProfileTrackedPersonId && ! $manualPublicProfileUsername) class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">
                            Verbindung speichern
                        </button>
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 class="text-sm font-bold text-slate-900">Analysierte Listenverbindungen</h4>
                        <span class="text-xs text-slate-500">Letzte 20 Scans</span>
                    </div>
                    <div class="mt-3 space-y-2">
                        @forelse($connectionScanRows as $connectionScanRow)
                            <div class="rounded-xl border px-3 py-2 text-xs {{ $connectionScanRow->summary->statusClass }}">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-slate-950">
                                            {{ $connectionScanRow->scan->publicProfile?->display_name ?: '@'.$connectionScanRow->scan->public_username }}
                                            <span class="font-normal text-slate-500">({{ '@'.$connectionScanRow->scan->public_username }})</span>
                                        </div>
                                        <div class="mt-1">{{ $connectionScanRow->scan->relation_label }}</div>
                                        @if($connectionScanRow->scan->status_message)
                                            <div class="mt-1 text-slate-500">{{ $connectionScanRow->scan->status_message }}</div>
                                        @endif
                                    </div>
                                    <div class="text-right text-slate-500">
                                        <div>{{ $connectionScanRow->scan->analyzed_at ? $connectionScanRow->scan->analyzed_at->timezone(config('app.timezone'))->format('d.m.Y H:i') : '-' }}</div>
                                        <div class="mt-1">
                                            Kandidaten {{ data_get($connectionScanRow->scan->raw_payload, 'candidatesChecked', 0) }}
                                            / Treffer {{ $connectionScanRow->summary->inferredFollowerCount + $connectionScanRow->summary->inferredFollowingCount }}
                                            / Rate-Limit {{ data_get($connectionScanRow->scan->raw_payload, 'candidatesRateLimited', 0) }}
                                            / Fehler {{ data_get($connectionScanRow->scan->raw_payload, 'candidatesFailed', 0) }}
                                            @if($connectionScanRow->summary->screenshotUrl)
                                                / <a href="{{ $connectionScanRow->summary->screenshotUrl }}" target="_blank" class="font-semibold text-slate-700 underline decoration-slate-300 underline-offset-2">Screenshot</a>
                                            @endif
                                            @if($connectionScanRow->summary->candidateErrorScreenshotUrl)
                                                / <a href="{{ $connectionScanRow->summary->candidateErrorScreenshotUrl }}" target="_blank" class="font-semibold text-slate-700 underline decoration-slate-300 underline-offset-2">Kandidatenfehler {{ $connectionScanRow->summary->candidateErrorScreenshots->count() }}</a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                @if($connectionScanRow->scan->logs->isNotEmpty())
                                    <details class="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-2 text-[11px] text-slate-700">
                                        <summary class="cursor-pointer font-semibold text-slate-600">
                                            Scan-Logs anzeigen ({{ $connectionScanRow->scan->logs->count() }})
                                        </summary>
                                        <div class="mt-2 space-y-2">
                                            @foreach($connectionScanRow->scan->logs as $scanLog)
                                                <div class="rounded-md border border-slate-200 bg-white p-2">
                                                    <div class="font-semibold text-slate-800">
                                                        {{ $scanLog->message ?: 'Technischer Eintrag' }}
                                                    </div>
                                                    @if($scanLog->logged_at)
                                                        <div class="mt-0.5 text-slate-500">
                                                            {{ $scanLog->logged_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}
                                                            @if($scanLog->stage)
                                                                / {{ $scanLog->stage }}
                                                            @endif
                                                        </div>
                                                    @endif
                                                    @if($scanLog->detail)
                                                        <div class="mt-1 whitespace-pre-wrap break-words text-slate-600">
                                                            {{ \Illuminate\Support\Str::limit($scanLog->detail, 1200) }}
                                                        </div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                                @if($connectionScanRow->summary->screenshots->isNotEmpty())
                                    <details class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-2 text-left">
                                        <summary class="cursor-pointer text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                                            Screenshots anzeigen ({{ $connectionScanRow->summary->screenshots->count() }})
                                        </summary>
                                        <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                            @foreach($connectionScanRow->summary->screenshots->take(9) as $screenshot)
                                                <a href="{{ $screenshot['url'] }}" target="_blank" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                                    <img src="{{ $screenshot['url'] }}" alt="{{ $screenshot['label'] }}" class="h-28 w-full object-cover">
                                                    <div class="border-t border-slate-200 px-2 py-1.5 text-[11px] text-slate-600">
                                                        <span class="font-semibold text-slate-800">{{ $screenshot['label'] }}</span>
                                                        @if($screenshot['meta'])
                                                            <span class="ml-1">{{ $screenshot['meta'] }}</span>
                                                        @endif
                                                    </div>
                                                </a>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">Noch keine Public-Profile-Listenverbindungen analysiert.</p>
                        @endforelse
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 class="text-sm font-bold text-slate-900">Analysierte Profilvorschlaege</h4>
                        <span class="text-xs text-slate-500">Letzte 20 Scans</span>
                    </div>
                    <div class="mt-3 space-y-2">
                        @forelse($suggestionScanRows as $suggestionScanRow)
                            <div class="rounded-xl border px-3 py-2 text-xs {{ $suggestionScanRow->statusClass }}">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-slate-950">
                                            {{ $suggestionScanRow->scanTypeLabel }} · {{ '@'.$suggestionScanRow->scan->target_username }}
                                        </div>
                                        @if($suggestionScanRow->scan->status_message)
                                            <div class="mt-1 text-slate-500">{{ $suggestionScanRow->scan->status_message }}</div>
                                        @endif
                                    </div>
                                    <div class="text-right text-slate-500">
                                        <div>{{ $suggestionScanRow->scan->analyzed_at ? $suggestionScanRow->scan->analyzed_at->timezone(config('app.timezone'))->format('d.m.Y H:i') : '-' }}</div>
                                        <div class="mt-1">
                                            Vorschlaege {{ number_format($suggestionScanRow->scan->suggestions_observed_count, 0, ',', '.') }}
                                            / geprueft {{ number_format($suggestionScanRow->scan->suggestions_checked_count, 0, ',', '.') }}
                                            / Treffer {{ number_format($suggestionScanRow->scan->suggestion_matches_count, 0, ',', '.') }}
                                        </div>
                                    </div>
                                </div>
                                <details class="mt-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-slate-700">
                                    <summary class="cursor-pointer text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                        Vorschlags-Debug anzeigen
                                    </summary>
                                    <div class="mt-2 grid gap-2 lg:grid-cols-3">
                                        <div class="rounded-md bg-white p-2">
                                            <div class="font-semibold text-slate-900">Letzte Erkennung</div>
                                            <div class="mt-1 space-y-0.5 text-[11px] text-slate-600">
                                                <div>Runden: {{ number_format((int) data_get($suggestionScanRow->debug, 'rounds', 0), 0, ',', '.') }}</div>
                                                <div>Profil-Link-Kandidaten: {{ number_format((int) data_get($suggestionScanRow->debug, 'profileLinkCandidatesSeen', data_get($suggestionScanRow->payload, 'profileLinkCandidatesSeen', 0)), 0, ',', '.') }}</div>
                                                @if(is_array($suggestionScanRow->surfaceDebug) && $suggestionScanRow->surfaceDebug !== [])
                                                    <div>Oberflaeche vor Scan: {{ data_get($suggestionScanRow->surfaceDebug, 'bodyContainsSuggestionText') ? 'Vorschlagstext sichtbar' : 'kein Vorschlagstext' }}</div>
                                                    <div>Oberflaechen-Links: {{ number_format(count(data_get($suggestionScanRow->surfaceDebug, 'profileAnchorUsernames', [])), 0, ',', '.') }} / Alle ansehen Kandidaten {{ number_format(count(data_get($suggestionScanRow->surfaceDebug, 'seeAllCandidates', [])), 0, ',', '.') }}</div>
                                                @endif
                                                <div>Vorschlagstext im Body: {{ data_get($suggestionScanRow->lastDebug, 'bodyContainsSuggestionText') ? 'ja' : 'nein' }}</div>
                                                <div>Heading: {{ data_get($suggestionScanRow->lastDebug, 'headingFound') ? 'ja' : 'nein' }}{{ data_get($suggestionScanRow->lastDebug, 'headingText') ? ' - '.data_get($suggestionScanRow->lastDebug, 'headingText') : '' }}</div>
                                                <div>Scope: {{ data_get($suggestionScanRow->lastDebug, 'anchorScopeFound') ? 'ja' : 'nein' }}</div>
                                                <div>Links/Textfallback: {{ number_format((int) data_get($suggestionScanRow->lastDebug, 'fallbackAnchorsSeen', 0), 0, ',', '.') }} / {{ number_format((int) data_get($suggestionScanRow->lastDebug, 'textFallbackItemsSeen', 0), 0, ',', '.') }}</div>
                                                <div>Alle ansehen: {{ data_get($suggestionScanRow->lastDebug, 'seeAllClicked') ? 'geklickt' : 'nicht geklickt' }}{{ data_get($suggestionScanRow->lastDebug, 'seeAllReason') ? ' - '.data_get($suggestionScanRow->lastDebug, 'seeAllReason') : '' }}</div>
                                                <div>Dialog: {{ data_get($suggestionScanRow->lastDebug, 'dialogOpen') ? 'offen' : 'nicht offen' }} / Links {{ number_format((int) data_get($suggestionScanRow->lastDebug, 'dialogProfileLinkCount', 0), 0, ',', '.') }}</div>
                                                <div>Scroll: {{ data_get($suggestionScanRow->lastScroll, 'scrollMode', '-') }}{{ data_get($suggestionScanRow->lastScroll, 'scrollAdvanced') ? ' weiter' : ' kein Fortschritt' }}{{ data_get($suggestionScanRow->lastScroll, 'scrollAtEnd') ? ' / Ende' : '' }}</div>
                                                @if(data_get($suggestionScanRow->lastDebug, 'dialogTextPreview'))
                                                    <div class="text-slate-500">Dialogtext: {{ \Illuminate\Support\Str::limit((string) data_get($suggestionScanRow->lastDebug, 'dialogTextPreview'), 160) }}</div>
                                                @endif
                                                @if(data_get($suggestionScanRow->debug, 'error'))
                                                    <div class="text-rose-700">Fehler: {{ \Illuminate\Support\Str::limit((string) data_get($suggestionScanRow->debug, 'error'), 160) }}</div>
                                                @endif
                                                @if(data_get($suggestionScanRow->payload, 'rateLimitText'))
                                                    <div class="text-rose-700">Rate-Limit: {{ \Illuminate\Support\Str::limit((string) data_get($suggestionScanRow->payload, 'rateLimitText'), 160) }}</div>
                                                @endif
                                                @if(data_get($suggestionScanRow->lastDebug, 'liveScreenshotUrl') || data_get($suggestionScanRow->lastScroll, 'liveScreenshotUrl'))
                                                    <a href="{{ data_get($suggestionScanRow->lastDebug, 'liveScreenshotUrl') ?: data_get($suggestionScanRow->lastScroll, 'liveScreenshotUrl') }}" target="_blank" class="inline-flex text-[11px] font-semibold text-indigo-600 hover:text-indigo-800">
                                                        letzten Debug-Screenshot oeffnen
                                                    </a>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="rounded-md bg-white p-2">
                                            <div class="font-semibold text-slate-900">Erkannte Usernames</div>
                                            @if($suggestionScanRow->finalUsernames->isNotEmpty())
                                                <div class="mt-1 flex flex-wrap gap-1">
                                                    @foreach($suggestionScanRow->finalUsernames as $debugUsername)
                                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-700">{{ '@'.$debugUsername }}</span>
                                                    @endforeach
                                                </div>
                                            @else
                                                <div class="mt-1 text-[11px] text-slate-500">Keine Usernames im gespeicherten Debug gefunden.</div>
                                            @endif
                                        </div>
                                        <div class="rounded-md bg-white p-2">
                                            <div class="font-semibold text-slate-900">Beobachtete Vorschlaege</div>
                                            @if($suggestionScanRow->observedPreview->isNotEmpty())
                                                <div class="mt-1 max-h-32 space-y-1 overflow-y-auto pr-1">
                                                    @foreach($suggestionScanRow->observedPreview as $observedSuggestion)
                                                        <div class="rounded bg-slate-50 px-2 py-1 text-[11px]">
                                                            <span class="font-semibold text-slate-800">{{ '@'.($observedSuggestion['username'] ?? '') }}</span>
                                                            @if(! empty($observedSuggestion['skippedReason']))
                                                                <span class="text-slate-500"> - {{ $observedSuggestion['skippedReason'] }}</span>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @else
                                                <div class="mt-1 text-[11px] text-slate-500">Keine beobachteten Vorschlaege gespeichert.</div>
                                            @endif
                                        </div>
                                    </div>
                                    @if($suggestionScanRow->debugEvents->isNotEmpty())
                                        <div class="mt-2 rounded-md bg-white p-2">
                                            <div class="font-semibold text-slate-900">Letzte Debug-Runden</div>
                                            <div class="mt-1 space-y-1">
                                                @foreach($suggestionScanRow->debugEvents as $debugEvent)
                                                    <div class="rounded bg-slate-50 px-2 py-1 text-[11px] text-slate-600">
                                                        <span class="font-semibold text-slate-800">{{ $debugEvent['phase'] ?? '-' }} #{{ $debugEvent['round'] ?? '-' }}</span>
                                                        - Items {{ number_format((int) ($debugEvent['batchItemsFound'] ?? 0), 0, ',', '.') }}
                                                        - Heading {{ ! empty($debugEvent['headingFound']) ? 'ja' : 'nein' }}
                                                        - Scope {{ ! empty($debugEvent['anchorScopeFound']) ? 'ja' : 'nein' }}
                                                        - Links {{ number_format((int) ($debugEvent['fallbackAnchorsSeen'] ?? 0), 0, ',', '.') }}
                                                        - Text {{ number_format((int) ($debugEvent['textFallbackItemsSeen'] ?? 0), 0, ',', '.') }}
                                                        @if(! empty($debugEvent['usernames']) && is_array($debugEvent['usernames']))
                                                            - {{ implode(', ', array_map(fn ($username) => '@'.$username, array_slice($debugEvent['usernames'], 0, 8))) }}
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                    @if($suggestionScanRow->textSamples->isNotEmpty() || $suggestionScanRow->anchorSamples->isNotEmpty())
                                        <div class="mt-2 grid gap-2 lg:grid-cols-2">
                                            <div class="rounded-md bg-white p-2">
                                                <div class="font-semibold text-slate-900">Sichtbare Texte unter Vorschlaegen</div>
                                                <div class="mt-1 max-h-44 space-y-1 overflow-y-auto pr-1">
                                                    @forelse($suggestionScanRow->textSamples as $sample)
                                                        <div class="rounded bg-slate-50 px-2 py-1 text-[11px] text-slate-600">
                                                            <span class="font-semibold text-slate-800">{{ $sample['text'] ?? '-' }}</span>
                                                            @if(! empty($sample['normalizedUsername']))
                                                                <span class="text-slate-500"> -> {{ $sample['normalizedUsername'] }}</span>
                                                            @endif
                                                            <span class="text-slate-400"> ({{ $sample['tag'] ?? '?' }}, {{ $sample['left'] ?? '?' }}/{{ $sample['top'] ?? '?' }})</span>
                                                        </div>
                                                    @empty
                                                        <div class="text-[11px] text-slate-500">Keine sichtbaren Text-Samples gespeichert.</div>
                                                    @endforelse
                                                </div>
                                            </div>
                                            <div class="rounded-md bg-white p-2">
                                                <div class="font-semibold text-slate-900">Sichtbare Links unter Vorschlaegen</div>
                                                <div class="mt-1 max-h-44 space-y-1 overflow-y-auto pr-1">
                                                    @forelse($suggestionScanRow->anchorSamples as $sample)
                                                        <div class="rounded bg-slate-50 px-2 py-1 text-[11px] text-slate-600">
                                                            <span class="font-semibold text-slate-800">{{ $sample['parsedUsername'] ? '@'.$sample['parsedUsername'] : ($sample['text'] ?? '-') }}</span>
                                                            @if(! empty($sample['href']))
                                                                <span class="block truncate text-slate-400">{{ $sample['href'] }}</span>
                                                            @endif
                                                        </div>
                                                    @empty
                                                        <div class="text-[11px] text-slate-500">Keine sichtbaren Link-Samples gespeichert.</div>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                    @if($suggestionScanRow->scopeSamples->isNotEmpty())
                                        <div class="mt-2 rounded-md bg-white p-2">
                                            <div class="font-semibold text-slate-900">Moegliche Vorschlags-Container</div>
                                            <div class="mt-1 space-y-1">
                                                @foreach($suggestionScanRow->scopeSamples as $sample)
                                                    <div class="rounded bg-slate-50 px-2 py-1 text-[11px] text-slate-600">
                                                        <span class="font-semibold text-slate-800">
                                                            Links {{ number_format((int) ($sample['profileAnchorCount'] ?? 0), 0, ',', '.') }}
                                                            / {{ ! empty($sample['horizontalOverflow']) ? 'horizontal' : 'kein horizontal' }}
                                                            / {{ ! empty($sample['verticalOverflow']) ? 'vertikal' : 'kein vertikal' }}
                                                        </span>
                                                        <span class="block truncate text-slate-500">{{ $sample['textPreview'] ?? '' }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                    @if(is_array($suggestionScanRow->surfaceDebug) && $suggestionScanRow->surfaceDebug !== [])
                                        <div class="mt-2 rounded-md bg-white p-2">
                                            <div class="font-semibold text-slate-900">Oberflaeche vor dem Vorschlags-Collector</div>
                                            <div class="mt-1 space-y-1 text-[11px] text-slate-600">
                                                <div>URL: {{ data_get($suggestionScanRow->surfaceDebug, 'url', '-') }}</div>
                                                @if(data_get($suggestionScanRow->surfaceDebug, 'bodyTextPreview'))
                                                    <div class="rounded bg-slate-50 px-2 py-1">Body: {{ \Illuminate\Support\Str::limit((string) data_get($suggestionScanRow->surfaceDebug, 'bodyTextPreview'), 240) }}</div>
                                                @endif
                                                @if(collect(data_get($suggestionScanRow->surfaceDebug, 'profileAnchorUsernames', []))->isNotEmpty())
                                                    <div>Profil-Links: {{ collect(data_get($suggestionScanRow->surfaceDebug, 'profileAnchorUsernames', []))->take(20)->map(fn ($username) => '@'.$username)->implode(', ') }}</div>
                                                @endif
                                                @if(collect(data_get($suggestionScanRow->surfaceDebug, 'seeAllCandidates', []))->isNotEmpty())
                                                    <div>Alle-ansehen-Kandidaten: {{ collect(data_get($suggestionScanRow->surfaceDebug, 'seeAllCandidates', []))->take(8)->map(fn ($item) => ($item['text'] ?? '-').' @ '.($item['left'] ?? '?').'/'.($item['top'] ?? '?'))->implode(' | ') }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                </details>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">Noch keine Profilvorschlaege analysiert.</p>
                        @endforelse
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 class="text-sm font-bold text-slate-900">Teilweise rekonstruierte private Listen</h4>
                        <span class="text-xs text-slate-500">
                            {{ $inferredInstagramFollowers->count() }} Follower / {{ $inferredInstagramFollowing->count() }} Gefolgt / {{ $suggestionInstagramConnections->count() }} Vorschlaege
                        </span>
                    </div>
                    <div class="mt-3 grid gap-3 xl:grid-cols-3">
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Moegliche Follower des privaten Profils</div>
                            <div class="mt-3 max-h-80 space-y-2 overflow-y-auto pr-2">
                                @forelse($inferredInstagramFollowers->take(40) as $connection)
                                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs">
                                        <div>
                                            <div class="font-semibold text-slate-900">{{ $connection->display_handle }}</div>
                                            @if($connection->candidate_display_name)
                                                <div class="text-slate-500">{{ $connection->candidate_display_name }}</div>
                                            @endif
                                            <div class="text-slate-500">Quelle: {{ '@'.$connection->source_public_username }}</div>
                                        </div>
                                        <div class="text-right text-slate-500">
                                            {{ $connection->last_seen_at ? $connection->last_seen_at->timezone(config('app.timezone'))->diffForHumans() : '-' }}
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">Noch keine moeglichen Follower ueber bekannte Profile gefunden.</p>
                                @endforelse
                            </div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Moeglich vom privaten Profil gefolgt</div>
                            <div class="mt-3 max-h-80 space-y-2 overflow-y-auto pr-2">
                                @forelse($inferredInstagramFollowing->take(40) as $connection)
                                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs">
                                        <div>
                                            <div class="font-semibold text-slate-900">{{ $connection->display_handle }}</div>
                                            @if($connection->candidate_display_name)
                                                <div class="text-slate-500">{{ $connection->candidate_display_name }}</div>
                                            @endif
                                            <div class="text-slate-500">Quelle: {{ '@'.$connection->source_public_username }}</div>
                                        </div>
                                        <div class="text-right text-slate-500">
                                            {{ $connection->last_seen_at ? $connection->last_seen_at->timezone(config('app.timezone'))->diffForHumans() : '-' }}
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">Noch keine moeglich gefolgten Profile ueber bekannte Profile gefunden.</p>
                                @endforelse
                            </div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Vorschlag-Verbindungen</div>
                            <div class="mt-3 max-h-80 space-y-2 overflow-y-auto pr-2">
                                @forelse($suggestionInstagramConnections->take(40) as $connection)
                                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs">
                                        <div>
                                            <div class="font-semibold text-slate-900">{{ $connection->display_handle }}</div>
                                            @if($connection->candidate_display_name)
                                                <div class="text-slate-500">{{ $connection->candidate_display_name }}</div>
                                            @endif
                                            <div class="text-slate-500">Gefunden in Vorschlaegen bei {{ '@'.$connection->source_public_username }}</div>
                                        </div>
                                        <div class="text-right text-slate-500">
                                            {{ $connection->last_seen_at ? $connection->last_seen_at->timezone(config('app.timezone'))->diffForHumans() : '-' }}
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">Noch keine Vorschlag-Verbindungen gefunden.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </x-ui.accordion.tab-panel>

            <x-ui.accordion.tab-panel
                for="analyse"
                panel-class="scroll-mt-4 space-y-4 rounded-b-lg rounded-se-lg border border-blue-300 bg-white p-4"
            >
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Letzte Instagram-Analyse</h3>

                @if($latestSnapshot)
                    <div class="mt-3 rounded-xl border p-3 text-sm {{ $latestSnapshotStatusClass }}">
                        <p class="font-semibold">{{ $latestSnapshot->status_message }}</p>
                        <p class="mt-1 text-xs">{{ optional($latestSnapshot->analyzed_at)->format('d.m.Y H:i') ?: '—' }}</p>
                        @if($latestSnapshot->screenshot_url)
                            <a href="{{ $latestSnapshot->screenshot_url }}" target="_blank" class="mt-3 inline-flex rounded-full border border-current px-3 py-1 text-xs font-semibold uppercase tracking-wide">
                                Debug-Screenshot oeffnen
                            </a>
                        @endif
                    </div>

                    @if($latestSnapshotScreenshots->isNotEmpty())
                        <details class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-600">
                                Scan-Screenshots anzeigen ({{ $latestSnapshotScreenshots->count() }})
                            </summary>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                @foreach($latestSnapshotScreenshots as $screenshot)
                                    <a href="{{ $screenshot['url'] }}" target="_blank" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                                        <img src="{{ $screenshot['url'] }}" alt="{{ $screenshot['label'] }}" class="h-40 w-full object-cover">
                                        <div class="border-t border-slate-200 px-3 py-2 text-xs text-slate-600">
                                            <span class="font-semibold text-slate-900">{{ $screenshot['label'] }}</span>
                                            @if($screenshot['meta'])
                                                <span class="ml-1">{{ $screenshot['meta'] }}</span>
                                            @endif
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    <div class="mt-3 grid gap-2 text-sm text-slate-700">
                        <p><span class="font-semibold">Profilname:</span> {{ $latestSnapshot->full_name ?: '—' }}</p>
                        <p><span class="font-semibold">Profilstatus:</span> {{ $latestProfileVisibilityLabel }}</p>
                        <p><span class="font-semibold">Bio:</span> {{ $latestSnapshot->biography ?: '—' }}</p>
                        <p><span class="font-semibold">Follower-Quelle:</span> {{ $resolveCountSourceLabel($latestCountSources['followers'] ?? null) }}</p>
                        <p><span class="font-semibold">Gefolgt-Quelle:</span> {{ $resolveCountSourceLabel($latestCountSources['following'] ?? null) }}</p>
                        <p><span class="font-semibold">Beitraege-Quelle:</span> {{ $resolveCountSourceLabel($latestCountSources['posts'] ?? null) }}</p>
                        <p><span class="font-semibold">Profilbild-Hash:</span> {{ $latestSnapshot->profile_image_hash ?: '—' }}</p>
                    </div>

                    @if($latestScrapePhases->isNotEmpty())
                        <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                            <h4 class="font-semibold text-slate-900">Scrape-Phasen</h4>
                            <div class="mt-2 grid gap-2">
                                @foreach($latestScrapePhaseRows as $phase)
                                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white px-3 py-2">
                                        <span class="font-semibold">
                                            {{ $phase->label }}
                                        </span>
                                        <span class="text-slate-500">
                                            {{ data_get($phase, 'statusLevel', 'unknown') }}
                                            @if(data_get($phase, 'count') !== null)
                                                · {{ number_format((int) data_get($phase, 'count')) }} Eintraege
                                            @endif
                                            @if($phase->screenshotUrl)
                                                | <a href="{{ $phase->screenshotUrl }}" target="_blank" class="font-semibold text-slate-700 underline decoration-slate-300 underline-offset-2">Screenshot</a>
                                            @endif
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($latestDebugLogPath)
                        <p class="mt-3 break-all text-sm text-slate-700">
                            <span class="font-semibold">Debug-Log:</span> {{ $latestDebugLogPath }}
                        </p>
                    @endif

                    @if($latestCookieDiagnostics || $latestLoginDiagnostics)
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                                <h4 class="font-semibold text-slate-900">Cookie-Diagnose</h4>
                                <p class="mt-2">sessionid in Datei: {{ data_get($latestCookieDiagnostics, 'sessionCookieProvided') ? 'Ja' : 'Nein' }}</p>
                                <p>sessionid akzeptiert: {{ data_get($latestCookieDiagnostics, 'sessionCookieAccepted') ? 'Ja' : 'Nein' }}</p>
                                <p>sessionid nach Reload noch da: {{ data_get($latestCookieDiagnostics, 'sessionCookieRetained') ? 'Ja' : 'Nein' }}</p>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                                <h4 class="font-semibold text-slate-900">Login-Diagnose</h4>
                                <p class="mt-2">Auto-Login versucht: {{ data_get($latestLoginDiagnostics, 'attempted') ? 'Ja' : 'Nein' }}</p>
                                <p>Formular gefunden: {{ data_get($latestLoginDiagnostics, 'formDetected') ? 'Ja' : 'Nein' }}</p>
                                <p>Login erfolgreich: {{ data_get($latestLoginDiagnostics, 'success') ? 'Ja' : 'Nein' }}</p>
                                <p>sessionid nach Login: {{ data_get($latestLoginDiagnostics, 'sessionCookiePresent') ? 'Ja' : 'Nein' }}</p>
                            </div>
                        </div>
                    @endif

                    @if($latestCountWarnings)
                        <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                            <h4 class="font-semibold">Metrik-Hinweise</h4>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @foreach($latestCountWarnings as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($latestSnapshot->has_changes && $latestSnapshot->detected_changes)
                        <div class="mt-3 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-950">
                            <h4 class="font-semibold">Erkannte Aenderungen</h4>
                            <ul class="mt-2 space-y-2">
                                @foreach($latestDetectedChangeRows as $changeRow)
                                    <li>
                                        <span class="font-semibold">{{ $changeRow->label }}:</span>
                                        <span>{{ $changeRow->before }}</span>
                                        <span>-&gt;</span>
                                        <span>{{ $changeRow->after }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($latestSnapshot->profile_image_storage_url)
                        <div class="mt-3">
                            <h4 class="text-sm font-semibold text-slate-900">Gespeichertes Profilbild der letzten Analyse</h4>
                            <div class="mt-2">
                                <a href="{{ $latestSnapshot->profile_image_storage_url }}" target="_blank" class="block overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
                                    <img src="{{ $latestSnapshot->profile_image_storage_url }}" alt="Gespeichertes Profilbild" class="h-32 w-full object-cover">
                                </a>
                            </div>
                        </div>
                    @endif
                @else
                    <p class="mt-3 text-sm text-slate-500">Bisher wurde noch keine Instagram-Analyse gespeichert.</p>
                @endif
            </div>

            <x-instagram-posts-gallery
                :posts="$trackedPerson->currentInstagramProfile?->posts ?? collect()"
                title="Instagram-Beitraege"
                :last-scan-at="$trackedPerson->currentInstagramProfile?->postScans?->first()?->scanned_at"
                empty-text="Noch keine Instagram-Beitraege gespeichert."
            />

            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Profilbild-Historie</h3>
                <p class="mt-1 text-sm text-slate-600">
                    Gespeichert werden nur eindeutig dem analysierten Profil zuordenbare Profilbilder, keine Vorschlagsbilder oder Bilder des eingeloggten Such-Profils.
                </p>

                @if($profileImageHistory->isNotEmpty())
                    <div class="mt-3 max-h-96 overflow-y-auto pr-2">
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach($profileImageHistory as $profileImage)
                                <a href="{{ $profileImage->storage_url }}" target="_blank" class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50 shadow-sm">
                                    <img src="{{ $profileImage->storage_url }}" alt="Gespeichertes Profilbild" class="h-32 w-full object-cover">
                                    <div class="border-t border-slate-200 px-3 py-2 text-xs text-slate-600">
                                        {{ optional($profileImage->snapshot?->analyzed_at)->format('d.m.Y H:i') ?: 'Unbekanntes Datum' }}
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <p class="mt-3 text-sm text-slate-500">Bisher wurden noch keine Profilbilder in der Historie gespeichert.</p>
                @endif
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Analyse-Historie</h3>
                <div class="mt-3 max-h-[36rem] space-y-2 overflow-y-auto pr-2">
                    @forelse($historySnapshotRows as $historySnapshotRow)
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-slate-900">{{ optional($historySnapshotRow->snapshot->analyzed_at)->format('d.m.Y H:i') ?: '-' }}</div>
                                    <div class="mt-1">{{ $historySnapshotRow->snapshot->status_message }}</div>
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ $historySnapshotRow->snapshot->status_level }}
                                </div>
                            </div>
                            <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                                <div class="rounded-xl bg-white px-3 py-2">
                                    <div class="text-slate-500">Follower</div>
                                    <div class="mt-1 font-semibold text-slate-900">{{ $historySnapshotRow->snapshot->followers_count !== null ? number_format($historySnapshotRow->snapshot->followers_count) : '-' }}</div>
                                </div>
                                <div class="rounded-xl bg-white px-3 py-2">
                                    <div class="text-slate-500">Gefolgt</div>
                                    <div class="mt-1 font-semibold text-slate-900">{{ $historySnapshotRow->snapshot->following_count !== null ? number_format($historySnapshotRow->snapshot->following_count) : '-' }}</div>
                                </div>
                                <div class="rounded-xl bg-white px-3 py-2">
                                    <div class="text-slate-500">Beitraege</div>
                                    <div class="mt-1 font-semibold text-slate-900">{{ $historySnapshotRow->snapshot->posts_count !== null ? number_format($historySnapshotRow->snapshot->posts_count) : '-' }}</div>
                                </div>
                            </div>
                            @if($historySnapshotRow->screenshots->isNotEmpty())
                                <details class="mt-3 rounded-xl border border-slate-200 bg-white p-2">
                                    <summary class="cursor-pointer text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                                        Screenshots anzeigen ({{ $historySnapshotRow->screenshots->count() }})
                                    </summary>
                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                        @foreach($historySnapshotRow->screenshots->take(4) as $screenshot)
                                            <a href="{{ $screenshot['url'] }}" target="_blank" class="overflow-hidden rounded-lg border border-slate-200 bg-slate-50 shadow-sm">
                                                <img src="{{ $screenshot['url'] }}" alt="{{ $screenshot['label'] }}" class="h-28 w-full object-cover">
                                                <div class="border-t border-slate-200 px-2 py-1.5 text-[11px] text-slate-600">
                                                    <span class="font-semibold text-slate-800">{{ $screenshot['label'] }}</span>
                                                    @if($screenshot['meta'])
                                                        <span class="ml-1">{{ $screenshot['meta'] }}</span>
                                                    @endif
                                                </div>
                                            </a>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Noch keine Verlaufseintraege mit erkannten Aenderungen vorhanden.</p>
                    @endforelse
                </div>
            </div>
            </x-ui.accordion.tab-panel>
        </x-ui.accordion.tabs>
    </section>

    <x-instagram-post-engagement-modal
        :selected-post="$selectedPost"
        :selected-post-id="$selectedPostId"
        :active-tab="$activePostEngagementType"
    />
</div>
