<div class="container mx-auto space-y-4">
    @php
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



        @if($detailStatus)
            <div @class([
                'mt-4 rounded-3xl border px-4 py-3 text-sm',
                'border-emerald-200 bg-emerald-50 text-emerald-900' => $detailStatusLevel === 'success',
                'border-amber-200 bg-amber-50 text-amber-950' => $detailStatusLevel === 'partial',
                'border-rose-200 bg-rose-50 text-rose-900' => $detailStatusLevel === 'error',
                'border-slate-200 bg-slate-50 text-slate-800' => ! in_array($detailStatusLevel, ['success', 'partial', 'error'], true),
            ])>
                {{ $detailStatus }}
            </div>
        @endif
    </x-profile.detail-hero>

    <x-instagram-posts-gallery
        :posts="$profile->posts"
        title="Gespeicherte Beitraege"
        :last-scan-at="$profile->postScans->first()?->scanned_at"
        empty-text="Noch keine Beitraege gespeichert."
    />

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
