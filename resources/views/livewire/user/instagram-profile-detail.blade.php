<div class="container mx-auto space-y-4">
    @php
        $isAdmin = auth()->user()?->role === 'admin';
        $visibility = $profile->profile_visibility ?: 'unknown';
        $visibilityLabel = match ($visibility) {
            'public' => 'Oeffentlich',
            'private' => 'Privat',
            default => 'Unbekannt',
        };
        $visibilityClass = match ($visibility) {
            'public' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'private' => 'bg-slate-100 text-slate-700 ring-slate-200',
            default => 'bg-amber-50 text-amber-800 ring-amber-200',
        };
        $profileImageFrameClass = match ($visibility) {
            'public' => 'border-emerald-400 ring-2 ring-emerald-200',
            'private' => 'border-slate-400 ring-2 ring-slate-200',
            default => 'border-amber-400 ring-2 ring-amber-200',
        };
        $profileStatusDotClass = match ($visibility) {
            'public' => 'bg-emerald-500',
            'private' => 'bg-slate-500',
            default => 'bg-amber-500',
        };
        $scanStatusLevel = $lastScanStatus['level'] ?: 'unknown';
        $scanStatusLabel = match ($scanStatusLevel) {
            'success' => 'Erfolgreich',
            'partial' => 'Teilweise',
            'error', 'failed' => 'Fehlgeschlagen',
            default => 'Unbekannt',
        };
        $scanStatusClass = match ($scanStatusLevel) {
            'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'partial' => 'bg-amber-50 text-amber-800 ring-amber-200',
            'error', 'failed' => 'bg-rose-50 text-rose-700 ring-rose-200',
            default => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
        $scanTextClass = match ($scanStatusLevel) {
            'success' => 'text-emerald-700',
            'partial' => 'text-amber-700',
            'error', 'failed' => 'text-rose-700',
            default => 'text-slate-700',
        };
    @endphp

    <div
        wire:loading.flex
        wire:target="analyzeInstagramMini,analyzeInstagram,scanInstagramFollowersList,scanInstagramFollowingList,scanInstagramSuggestions,scanInstagramSuggestionDeepSearch,scanInstagramPosts,scanInstagramProfileFromList,addAsTrackedPerson"
        class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-950/70 px-4"
    >
        <div class="w-full max-w-md rounded-3xl bg-white p-6 text-center shadow-2xl">
            <div class="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-pink-200 border-t-pink-600"></div>
            <h3 class="mt-4 text-lg font-bold text-slate-950">Instagram-Aktion laeuft</h3>
            <p class="mt-2 text-sm text-slate-600">Das Profil wird aktualisiert. Bitte das Fenster nicht schliessen.</p>
        </div>
    </div>

    <x-profile.detail-hero>
        <x-slot:toolbar>
            <a
                href="{{ url()->previous() }}"
                class="inline-flex h-9 items-center justify-center rounded-3xl border border-white/80 bg-white/85 px-4 text-xs font-semibold text-slate-950 shadow-sm backdrop-blur hover:bg-white"
            >
                Zurueck
            </a>

            <x-ui.dropdown.anchor-dropdown
                align="right"
                width="auto"
                :offset="8"
                dropdown-classes=""
                content-classes="w-[min(22rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white"
            >
                <x-slot name="trigger">
                    <button
                        type="button"
                        x-bind:aria-expanded="open"
                        class="inline-flex h-9 items-center justify-center rounded-3xl bg-slate-950 px-4 text-xs font-semibold text-white shadow-sm hover:bg-slate-800"
                    >
                        Optionen
                        <span class="ml-2 text-slate-300">▾</span>
                    </button>
                </x-slot>

                <x-slot name="content">
                    <div class="border-b border-slate-100 px-4 py-3">
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Optionen</div>
                        <div class="mt-1 text-xs text-slate-500">@{{ $profile->username }} · {{ $visibilityLabel }}</div>
                    </div>

                    <div class="max-h-[75vh] overflow-y-auto p-2">
                        <div class="px-3 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">Profil scannen</div>

                        <button
                            type="button"
                            @click="$dispatch('close')"
                            wire:click="analyzeInstagramMini"
                            wire:loading.attr="disabled"
                            wire:target="analyzeInstagramMini"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                        >
                            Mini-Scan
                            <span class="mt-0.5 block text-xs font-normal text-slate-500">
                                Profilwerte aktualisieren · ab {{ number_format($scanCostSummary['profile'], 0, ',', '.') }} Credits
                            </span>
                        </button>

                        <button
                            type="button"
                            @click="$dispatch('close')"
                            wire:click="analyzeInstagram"
                            wire:loading.attr="disabled"
                            wire:target="analyzeInstagram"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-pink-700 hover:bg-pink-50 disabled:opacity-50"
                        >
                            Vollanalyse
                            <span class="mt-0.5 block text-xs font-normal text-pink-500">
                                Ab {{ number_format($scanCostSummary['profile'], 0, ',', '.') }} Credits plus Folge-Scans
                            </span>
                        </button>

                        <div class="my-2 border-t border-slate-100"></div>
                        <div class="px-3 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">Listen & Inhalte</div>

                        <button
                            type="button"
                            @click="$dispatch('close')"
                            wire:click="scanInstagramFollowersList"
                            wire:loading.attr="disabled"
                            wire:target="scanInstagramFollowersList"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-sky-700 hover:bg-sky-50 disabled:opacity-50"
                        >
                            Followerliste scannen
                            <span class="mt-0.5 block text-xs font-normal text-sky-500">Ab {{ number_format($scanCostSummary['profile'], 0, ',', '.') }} Credits</span>
                        </button>

                        <button
                            type="button"
                            @click="$dispatch('close')"
                            wire:click="scanInstagramFollowingList"
                            wire:loading.attr="disabled"
                            wire:target="scanInstagramFollowingList"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-sky-700 hover:bg-sky-50 disabled:opacity-50"
                        >
                            Gefolgt-Liste scannen
                            <span class="mt-0.5 block text-xs font-normal text-sky-500">Ab {{ number_format($scanCostSummary['profile'], 0, ',', '.') }} Credits</span>
                        </button>

                        <button
                            type="button"
                            @click="$dispatch('close')"
                            wire:click="scanInstagramPosts"
                            wire:loading.attr="disabled"
                            wire:target="scanInstagramPosts"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-violet-700 hover:bg-violet-50 disabled:opacity-50"
                        >
                            Beitraege scannen
                            <span class="mt-0.5 block text-xs font-normal text-violet-500">Ab {{ number_format($scanCostSummary['post'], 0, ',', '.') }} Credits</span>
                        </button>

                        <button
                            type="button"
                            @click="$dispatch('close')"
                            wire:click="scanInstagramSuggestions"
                            wire:loading.attr="disabled"
                            wire:target="scanInstagramSuggestions"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-fuchsia-700 hover:bg-fuchsia-50 disabled:opacity-50"
                        >
                            Vorschlaege-Scan
                            <span class="mt-0.5 block text-xs font-normal text-fuchsia-500">
                                Direkte Vorschlaege · ab {{ number_format($scanCostSummary['profile'], 0, ',', '.') }} Credits
                            </span>
                        </button>

                        <button
                            type="button"
                            @click="$dispatch('close')"
                            wire:click="scanInstagramSuggestionDeepSearch"
                            wire:loading.attr="disabled"
                            wire:target="scanInstagramSuggestionDeepSearch"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-fuchsia-700 hover:bg-fuchsia-50 disabled:opacity-50"
                        >
                            Vorschlaege DeepSearch
                            <span class="mt-0.5 block text-xs font-normal text-fuchsia-500">
                                Vorschlaege und Listen · ab {{ number_format($scanCostSummary['profile'], 0, ',', '.') }} Credits
                            </span>
                        </button>

                        <div class="my-2 border-t border-slate-100"></div>
                        <div class="px-3 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">Aktionen</div>

                        <button
                            type="button"
                            @click="$dispatch('close')"
                            wire:click="$dispatch('open-instagram-scan-costs-modal')"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                        >
                            Scan-Kosten
                        </button>

                        @if($trackedPerson)
                            <a
                                href="{{ route('tracked-people.show', $trackedPerson->id) }}"
                                wire:navigate
                                @click="$dispatch('close')"
                                class="block rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                            >
                                Beobachtete Person oeffnen
                            </a>
                        @else
                            <button
                                type="button"
                                @click="$dispatch('close')"
                                wire:click="addAsTrackedPerson"
                                wire:loading.attr="disabled"
                                wire:target="addAsTrackedPerson"
                                class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-sky-700 hover:bg-sky-50 disabled:opacity-50"
                            >
                                Tracking aktivieren
                            </button>
                        @endif

                        <a
                            href="{{ $profile->profile_url ?: 'https://www.instagram.com/'.$profile->username.'/' }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            @click="$dispatch('close')"
                            class="block rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                        >
                            Instagram oeffnen
                        </a>

                        <button
                            type="button"
                            @click="$dispatch('close')"
                            wire:click="openListModal('followers')"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                        >
                            Follower-Liste ansehen
                        </button>

                        <button
                            type="button"
                            @click="$dispatch('close')"
                            wire:click="openListModal('following')"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                        >
                            Gefolgt-Liste ansehen
                        </button>
                    </div>
                </x-slot>
            </x-ui.dropdown.anchor-dropdown>
        </x-slot:toolbar>

        <x-slot:identity>
            <x-profile.detail-identity
                :src="$profile->profile_image_storage_url"
                :alt="$profile->display_handle"
                :initial="$profile->username ?: '?'"
                :title="$profile->display_handle"
                :subtitle="$profile->display_name ?: $profile->full_name"
                :biography="$profile->biography"
                :frame-class="$profileImageFrameClass"
                :status-dot-class="$profileStatusDotClass"
                :status-label="$visibilityLabel"
                :muted-image="$visibility === 'private'"
            />
        </x-slot:identity>

        <x-slot:metrics>
            <x-profile.detail-metric
                as="button"
                label="Follower"
                :value="is_numeric($profile->followers_count) ? number_format($profile->followers_count) : '-'"
                tone="pink"
                wire:click="openListModal('followers')"
                title="Followerliste oeffnen"
            >
                Liste oeffnen
            </x-profile.detail-metric>

            <x-profile.detail-metric
                as="button"
                label="Gefolgt"
                :value="is_numeric($profile->following_count) ? number_format($profile->following_count) : '-'"
                tone="sky"
                wire:click="openListModal('following')"
                title="Gefolgt-Liste oeffnen"
            >
                Liste oeffnen
            </x-profile.detail-metric>

            <x-profile.detail-metric
                label="Beitraege"
                :value="is_numeric($profile->posts_count) ? number_format($profile->posts_count) : '-'"
                tone="violet"
            >
                Profilmetriken
            </x-profile.detail-metric>
        </x-slot:metrics>

        <x-slot:badges>
            <span class="rounded-2xl px-3 py-1 text-xs font-bold ring-1 {{ $visibilityClass }}">
                {{ $visibilityLabel }}
            </span>
            <span class="rounded-2xl px-3 py-1 text-xs font-bold ring-1 {{ $scanStatusClass }}">
                Scan: {{ $scanStatusLabel }}
            </span>
            <span class="rounded-2xl bg-white/80 px-3 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                Letzter Scan: {{ $lastScanStatus['scannedAt']?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}
            </span>
        </x-slot:badges>

        <x-instagram.detail-status-alert :message="$detailStatus" :level="$detailStatusLevel" />
    </x-profile.detail-hero>

    @php
        $detailTabs = [
            'posts' => 'Posts',
            'connections' => 'Verbindungen',
        ];

        if ($isAdmin) {
            $detailTabs['analyses'] = 'Analysen';
        }
    @endphp

    <section id="profilinfos" class="scroll-mt-4 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <x-ui.accordion.tabs
            :tabs="$detailTabs"
            default="posts"
            :persist="false"
            collapse-at="sm"
        >
            <x-ui.accordion.tab-panel for="posts" panel-class="pt-4">
                <x-instagram-posts-gallery
                    :posts="$profile->posts"
                    title="Gespeicherte Beitraege"
                    :last-scan-at="$profile->postScans->first()?->scanned_at"
                    empty-text="Noch keine Beitraege gespeichert."
                />
            </x-ui.accordion.tab-panel>

            <x-ui.accordion.tab-panel for="connections" panel-class="pt-4">
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach([
                        'followers' => ['Followerliste', $latestFollowersScan, 'pink'],
                        'following' => ['Gefolgt-Liste', $latestFollowingScan, 'sky'],
                    ] as $listType => [$listTitle, $scan, $tone])
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-bold text-slate-950">{{ $listTitle }}</h3>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">
                                        @if($scan)
                                            {{ number_format((int) $scan->active_count, 0, ',', '.') }} aktiv
                                            &middot; {{ number_format((int) $scan->observed_count, 0, ',', '.') }} beobachtet
                                            &middot; {{ $scan->scanned_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}
                                        @else
                                            Noch keine Liste gespeichert.
                                        @endif
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="openListModal('{{ $listType }}')"
                                    class="shrink-0 rounded-xl border px-3 py-2 text-xs font-bold {{ $tone === 'pink' ? 'border-pink-200 bg-pink-50 text-pink-700 hover:bg-pink-100' : 'border-sky-200 bg-sky-50 text-sky-700 hover:bg-sky-100' }}"
                                >
                                    Oeffnen
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.accordion.tab-panel>

            @if($isAdmin)
                <x-ui.accordion.tab-panel for="analyses" panel-class="space-y-4 pt-4">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Letzter Scan</div>
                        <div class="mt-2 text-sm font-bold {{ $scanTextClass }}">{{ $lastScanStatus['type'] ?? 'Instagram-Scan' }} · {{ $scanStatusLabel }}</div>
                        @if($lastScanStatus['message'])
                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ $lastScanStatus['message'] }}</p>
                        @endif
                        <div class="mt-2 text-xs font-semibold text-slate-500">
                            {{ $lastScanStatus['scannedAt']?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}
                        </div>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <h3 class="text-sm font-bold text-slate-950">Profil-Scans</h3>
                            <div class="mt-3 space-y-2">
                                @forelse($profile->profileScans->take(6) as $scan)
                                    <div class="rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                        <div class="font-semibold text-slate-900">{{ $scan->status_message ?: $scan->status_level }}</div>
                                        <div class="mt-0.5">{{ $scan->scanned_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}</div>
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">Noch keine Profil-Scans gespeichert.</p>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <h3 class="text-sm font-bold text-slate-950">Weitere Scans</h3>
                            <div class="mt-3 space-y-2">
                                @foreach($profile->listScans->take(6) as $scan)
                                    <div class="rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                        <div class="font-semibold text-slate-900">{{ $scan->list_type === 'followers' ? 'Followerliste' : 'Gefolgt-Liste' }}</div>
                                        <div class="mt-0.5">{{ $scan->scanned_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }} · {{ $scan->status_message ?: $scan->status_level }}</div>
                                    </div>
                                @endforeach
                                @foreach($profile->postScans->take(4) as $scan)
                                    <div class="rounded-xl bg-violet-50 px-3 py-2 text-xs text-violet-800">
                                        <div class="font-semibold">Beitragsscan</div>
                                        <div class="mt-0.5">{{ $scan->scanned_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }} · {{ $scan->status_message ?: $scan->status_level }}</div>
                                    </div>
                                @endforeach
                                @if($profile->listScans->isEmpty() && $profile->postScans->isEmpty())
                                    <p class="text-sm text-slate-500">Noch keine Listen- oder Beitragsscans gespeichert.</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </x-ui.accordion.tab-panel>
            @endif
        </x-ui.accordion.tabs>
    </section>

    <livewire:user.instagram-scan-costs-modal
        :instagram-profile-id="$profile->id"
        :key="'instagram-scan-costs-profile-'.$profile->id"
    />

    <x-instagram-profile-list-modal
        model="showListModal"
        :title="$activeListType === 'followers' ? 'Followerliste' : 'Gefolgt-Liste'"
        :scan="$activeListType === 'followers' ? $latestFollowersScan : $latestFollowingScan"
    />

    <x-instagram-post-engagement-modal
        :selected-post="$selectedPost"
        :selected-post-id="$selectedPostId"
        :active-tab="$activePostEngagementType"
    />
</div>
