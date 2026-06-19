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
            'success' => 'text-emerald-700',
            'partial' => 'text-amber-700',
            'error', 'failed' => 'text-rose-700',
            default => 'text-slate-600',
        };
    @endphp

    <div
        wire:loading.flex
        wire:target="analyzeInstagramMini,analyzeInstagram,scanInstagramFollowersList,scanInstagramFollowingList,scanInstagramSuggestions,scanInstagramSuggestionDeepSearch,scanInstagramPosts,scanInstagramProfileFromList"
        class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-950/70 px-4"
    >
        <div class="w-full max-w-md rounded-lg bg-white p-6 text-center shadow-2xl">
            <div class="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-pink-200 border-t-pink-600"></div>
            <h3 class="mt-4 text-lg font-bold text-slate-950">Instagram-Scan laeuft</h3>
            <p class="mt-2 text-sm text-slate-600">Der Scan aktualisiert dieses Instagram-Profil, ohne es automatisch als beobachtete Person anzulegen.</p>
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

        <div class="flex items-center gap-2">
            <x-ui.dropdown.anchor-dropdown
                align="right"
                width="auto"
                :offset="8"
                dropdown-classes=""
                content-classes="w-72 rounded-2xl border border-slate-200 bg-white"
            >
                <x-slot name="trigger">
                    <button
                        type="button"
                        x-bind:aria-expanded="open"
                        class="inline-flex h-9 items-center justify-center rounded-3xl bg-slate-950 px-4 text-xs font-semibold text-white shadow-sm hover:bg-slate-800"
                    >
                        Scans
                        <span class="ml-2 text-slate-300">▾</span>
                    </button>
                </x-slot>

                <x-slot name="content">
                    <div class="border-b border-slate-100 px-4 py-3">
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Profil scannen</div>
                        <div class="mt-1 text-xs text-slate-500">{{ $visibilityLabel }}es Instagram-Profil</div>
                    </div>
                    <div class="flex flex-col p-2">
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
                            Ab {{ number_format($scanCostSummary['profile'], 0, ',', '.') }} Credits plus ausgefuehrte Folge-Scans
                        </span>
                    </button>

                    <div class="my-1 border-t border-slate-100"></div>
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
                    <div class="my-1 border-t border-slate-100"></div>
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
                    </div>
                </x-slot>
            </x-ui.dropdown.anchor-dropdown>

            <x-ui.dropdown.anchor-dropdown
                align="right"
                width="auto"
                :offset="8"
                dropdown-classes=""
                content-classes="w-72 rounded-2xl border border-slate-200 bg-white"
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
                    @if($trackedPerson)
                        <a
                            href="{{ route('tracked-people.show', $trackedPerson->id) }}"
                            wire:navigate
                            @click="$dispatch('close')"
                            class="rounded-xl px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                        >
                            Beobachtete Person
                        </a>
                    @else
                        <button
                            type="button"
                            @click="$dispatch('close')"
                            wire:click="addAsTrackedPerson"
                            wire:loading.attr="disabled"
                            class="w-full rounded-xl px-3 py-2 text-left text-sm font-semibold text-sky-700 hover:bg-sky-50 disabled:opacity-50"
                        >
                            Als beobachtetes Profil anlegen
                        </button>
                    @endif

                    <a
                        href="{{ $profile->profile_url ?: 'https://www.instagram.com/'.$profile->username.'/' }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        @click="$dispatch('close')"
                        class="rounded-xl px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                    >
                        Instagram oeffnen
                    </a>

                    <button
                        type="button"
                        @click="$dispatch('close')"
                        wire:click="openListModal('followers')"
                        class="w-full rounded-xl px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                    >
                        Follower-Liste ansehen
                    </button>
                    <button
                        type="button"
                        @click="$dispatch('close')"
                        wire:click="openListModal('following')"
                        class="w-full rounded-xl px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                    >
                        Gefolgt-Liste ansehen
                    </button>
                    </div>
                </x-slot>
            </x-ui.dropdown.anchor-dropdown>
        </div>
    </x-slot:toolbar>
    
    <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
        @if($profile->biography)
            <p class="whitespace-pre-line text-sm leading-6 text-slate-600">{{ $profile->biography }}</p>
        @else
            <p class="text-sm leading-6 text-slate-500">Keine Biografie gespeichert.</p>
        @endif

        <div class="mt-4 flex flex-wrap gap-2 text-xs">
            <span class="rounded-2xl bg-slate-100 px-3 py-1 font-semibold text-slate-700">
                Letzter Scan: {{ $lastScanStatus['scannedAt']?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}
            </span>
        </div>
    </div>
        <x-slot:identity>
            <x-profile.detail-identity
                :src="$profile->profile_image_storage_url"
                :alt="$profile->display_handle"
                :initial="$profile->username ?: '?'"
                :title="$profile->display_handle"
                :subtitle="$profile->display_name ?: $profile->full_name"
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



        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_280px]">

            <div class="grid gap-2">
                <button
                    type="button"
                    wire:click="analyzeInstagram"
                    wire:loading.attr="disabled"
                    wire:target="analyzeInstagram"
                    class="inline-flex h-11 items-center justify-center rounded-3xl bg-gradient-to-r from-rose-500 to-fuchsia-600 px-4 text-sm font-semibold text-white shadow-sm hover:from-rose-600 hover:to-fuchsia-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="analyzeInstagram">Vollanalyse</span>
                    <span wire:loading wire:target="analyzeInstagram">Laeuft...</span>
                </button>
                <p class="text-center text-[11px] font-semibold text-slate-500">
                    Ab {{ number_format($scanCostSummary['profile'], 0, ',', '.') }} Credits plus Folge-Scans
                </p>
                @if($trackedPerson)
                    <a
                        href="{{ route('tracked-people.show', $trackedPerson->id) }}"
                        wire:navigate
                        class="inline-flex h-11 items-center justify-center rounded-3xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                    >
                        Beobachtete Person oeffnen
                    </a>
                @else
                    <button
                        type="button"
                        wire:click="addAsTrackedPerson"
                        wire:loading.attr="disabled"
                        wire:target="addAsTrackedPerson"
                        class="inline-flex h-11 items-center justify-center rounded-3xl border border-sky-200 bg-sky-50 px-4 text-sm font-semibold text-sky-700 hover:bg-sky-100 disabled:opacity-50"
                    >
                        Tracking aktivieren
                    </button>
                @endif
            </div>
        </div>
        
        @if($detailStatus)
        <div @class([
        'mt-4 rounded-3xl border px-4 py-3 text-sm',
        'border-emerald-200 bg-emerald-50 text-emerald-900' => $detailStatusLevel === 'success',
        'border-amber-200 bg-amber-50 text-amber-950' => $detailStatusLevel === 'partial',
                'border-rose-200 bg-rose-50 text-rose-900' => $detailStatusLevel === 'error',
                ])>
                {{ $detailStatus }}
            </div>
            @endif
        </x-profile.detail-hero>
        
        
        <div class="xl:order-last xl:col-span-2">
            <x-instagram-posts-gallery
                :posts="$profile->posts"
                title="Gespeicherte Beitraege"
                :last-scan-at="$profile->postScans->first()?->scanned_at"
                empty-text="Noch keine Beitraege gespeichert."
            />
        </div>

    <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Scan-Kosten</p>
                <h2 class="mt-1 text-lg font-bold text-slate-950">Credits werden fuer jeden Lauf gespeichert</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Basis {{ number_format($scanCostSummary['base'], 0, ',', '.') }} +
                    {{ number_format($scanCostSummary['per_minute'], 0, ',', '.') }} Credits je angefangener Minute,
                    maximal {{ number_format($scanCostSummary['max_minutes'], 0, ',', '.') }} Minuten.
                    Downloads kosten zusaetzlich {{ number_format($scanCostSummary['media_download'], 0, ',', '.') }} Credits je Datei.
                </p>
            </div>
            <div class="rounded-2xl bg-slate-950 px-4 py-3 text-white">
                <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-300">Verfuegbar</div>
                <div class="mt-1 text-xl font-bold">
                    {{ number_format((int) (($creditWallet?->available_credits ?? 0) + ($creditWallet?->bonus_credits ?? 0)), 0, ',', '.') }}
                </div>
                <div class="text-xs text-slate-300">Credits</div>
            </div>
        </div>


        <div class="mt-5 border-t border-slate-200 pt-4">
            <h3 class="text-sm font-bold text-slate-950">Letzte Kosten fuer {{ $profile->display_handle }}</h3>
            <div class="mt-3 space-y-2">
                @forelse($recentScanTransactions as $transaction)
                    <div class="flex items-center justify-between gap-4 rounded-2xl bg-slate-50 px-3 py-2 text-sm">
                        <div class="min-w-0">
                            <div class="truncate font-semibold text-slate-800">{{ $transaction->description }}</div>
                            <div class="text-xs text-slate-500">{{ $transaction->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</div>
                        </div>
                        <div class="shrink-0 font-bold text-rose-700">
                            {{ number_format(abs((int) $transaction->amount), 0, ',', '.') }} Credits
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Fuer dieses Profil wurden noch keine Scan-Kosten gebucht.</p>
                @endforelse
            </div>
        </div>
    </section>





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
