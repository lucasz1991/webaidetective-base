<div class="space-y-4">
    @php
        $detailStatusClass = match ($detailStatusLevel ?? 'neutral') {
            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
            default => 'border-slate-200 bg-slate-50 text-slate-800',
        };
        $profileVisibility = $trackedPerson->latestInstagramSnapshot?->profile_visibility ?? 'unknown';
        $profileIsPublic = $profileVisibility === 'public';
        $profileIsPrivate = $profileVisibility === 'private';
        $profileVisibilityLabel = match ($profileVisibility) {
            'public' => 'Oeffentlich',
            'private' => 'Privat',
            default => 'Unbekannt',
        };
        $profileVisibilityClass = match ($profileVisibility) {
            'public' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'private' => 'bg-slate-100 text-slate-700 ring-slate-200',
            default => 'bg-amber-50 text-amber-800 ring-amber-200',
        };
    @endphp

    <div
        wire:loading.flex
        wire:target="analyzeInstagram,analyzeInstagramMini,scanInstagramFollowersList,scanInstagramFollowingList,scanInstagramSuggestions,scanInstagramPosts"
        class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-950/70 px-4"
    >
        <div class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-lg border border-white/20 bg-white p-5 text-center shadow-2xl">
            <div class="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-pink-200 border-t-pink-600"></div>
            <div class="mt-4 text-xs font-semibold uppercase tracking-wide text-pink-700" wire:stream="instagram-progress-phase">Start</div>
            <div class="mt-2 inline-flex max-w-full items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                <span class="shrink-0 uppercase tracking-wide text-slate-400">Scraper-Profil</span>
                <span class="min-w-0 truncate text-slate-900" wire:stream="instagram-progress-scraper-profile">wird ermittelt</span>
            </div>
            <h3 class="mt-2 text-lg font-bold text-slate-950">Instagram-Scan laeuft</h3>
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
                    <div class="h-full rounded-full bg-pink-600" style="width: 0%"></div>
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

    @if($detailStatus)
        <div class="rounded-lg border p-3 text-sm {{ $detailStatusClass }}">
            {{ $detailStatus }}
        </div>
    @endif

    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Scan starten</div>
            <span class="rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 {{ $profileVisibilityClass }}">{{ $profileVisibilityLabel }}</span>
        </div>
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            <button
                type="button"
                wire:click="analyzeInstagramMini"
                wire:loading.attr="disabled"
                wire:target="analyzeInstagramMini"
                class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="analyzeInstagramMini">Mini-Scan</span>
                <span wire:loading wire:target="analyzeInstagramMini">Mini-Scan laeuft...</span>
            </button>
            <button
                type="button"
                wire:click="analyzeInstagram"
                wire:loading.attr="disabled"
                wire:target="analyzeInstagram"
                @disabled(! $trackedPerson->instagram_username)
                class="inline-flex justify-center rounded-lg bg-pink-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-pink-700 disabled:cursor-wait disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="analyzeInstagram">Vollanalyse</span>
                <span wire:loading wire:target="analyzeInstagram">Vollanalyse laeuft...</span>
            </button>
            @if($profileIsPublic)
                <button
                    type="button"
                    wire:click="scanInstagramFollowersList"
                    wire:loading.attr="disabled"
                    wire:target="scanInstagramFollowersList"
                    @disabled(! $trackedPerson->instagram_username)
                    class="inline-flex justify-center rounded-lg border border-pink-200 bg-pink-50 px-3 py-2 text-sm font-semibold text-pink-700 shadow-sm hover:bg-pink-100 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="scanInstagramFollowersList">Follower scannen</span>
                    <span wire:loading wire:target="scanInstagramFollowersList">Follower-Scan laeuft...</span>
                </button>
                <button
                    type="button"
                    wire:click="scanInstagramFollowingList"
                    wire:loading.attr="disabled"
                    wire:target="scanInstagramFollowingList"
                    @disabled(! $trackedPerson->instagram_username)
                    class="inline-flex justify-center rounded-lg border border-pink-200 bg-pink-50 px-3 py-2 text-sm font-semibold text-pink-700 shadow-sm hover:bg-pink-100 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="scanInstagramFollowingList">Gefolgt scannen</span>
                    <span wire:loading wire:target="scanInstagramFollowingList">Gefolgt-Scan laeuft...</span>
                </button>
                <button
                    type="button"
                    wire:click="scanInstagramPosts"
                    wire:loading.attr="disabled"
                    wire:target="scanInstagramPosts"
                    @disabled(! $trackedPerson->instagram_username)
                    class="inline-flex justify-center rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-700 shadow-sm hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="scanInstagramPosts">Beitraege scannen</span>
                    <span wire:loading wire:target="scanInstagramPosts">Beitragsscan laeuft...</span>
                </button>
            @endif
            @if($profileIsPrivate)
                <button
                    type="button"
                    wire:click="scanInstagramSuggestions"
                    wire:loading.attr="disabled"
                    wire:target="scanInstagramSuggestions"
                    @disabled(! $trackedPerson->instagram_username)
                    class="inline-flex justify-center rounded-lg border border-fuchsia-200 bg-fuchsia-50 px-3 py-2 text-sm font-semibold text-fuchsia-700 shadow-sm hover:bg-fuchsia-100 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="scanInstagramSuggestions">Vorschlaege scannen</span>
                    <span wire:loading wire:target="scanInstagramSuggestions">Vorschlags-Scan laeuft...</span>
                </button>
            @endif
        </div>
    </div>
</div>
