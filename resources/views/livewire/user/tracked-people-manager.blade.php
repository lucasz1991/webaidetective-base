<div class="space-y-4" wire:loading.class="cursor-wait" wire:poll.visible.10000ms>
    @php
        $managerStatusClass = match ($managerStatusLevel ?? 'neutral') {
            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
            default => 'border-slate-200 bg-slate-50 text-slate-800',
        };
        $selectedTrackedPerson = $selectedTrackedPersonId
            ? $trackedPeople->firstWhere('id', $selectedTrackedPersonId)
            : null;
    @endphp

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-3 py-3 sm:px-5 sm:py-4">
            <div class="flex justify-end">
                <button
                    wire:click="toggleCreateForm"
                    class="inline-flex w-full sm:w-auto items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-indigo-600 to-violet-600 px-3 py-2 sm:px-4 sm:py-2.5 text-xs sm:text-sm font-semibold text-white shadow-sm transition hover:from-indigo-700 hover:to-violet-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
                    </svg>
                    <span>{{ $showCreateForm ? 'Formular schliessen' : 'Instagram-Profil erfassen' }}</span>
                </button>
            </div>
        </div>

        @if($managerStatus)
            <div class="mx-4 mt-4 rounded-lg border p-3 text-sm sm:mx-5 {{ $managerStatusClass }}">
                {{ $managerStatus }}
            </div>
        @endif

        @if($showCreateForm)
            <div class="border-t border-slate-200 bg-slate-50/70 px-4 py-5 sm:px-5">
                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(260px,0.5fr)]">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="tracked-first-name" class="mb-1 block text-sm font-medium text-slate-700">Vorname</label>
                            <input id="tracked-first-name" type="text" wire:model.defer="first_name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            @error('first_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="tracked-last-name" class="mb-1 block text-sm font-medium text-slate-700">Nachname</label>
                            <input id="tracked-last-name" type="text" wire:model.defer="last_name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            @error('last_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="tracked-alias" class="mb-1 block text-sm font-medium text-slate-700">Alias</label>
                            <input id="tracked-alias" type="text" wire:model.defer="alias" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="tracked-instagram" class="mb-1 block text-sm font-medium text-slate-700">Instagram-Handle</label>
                            <input id="tracked-instagram" type="text" wire:model.defer="instagram_username" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" placeholder="@username">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="tracked-notes" class="mb-1 block text-sm font-medium text-slate-700">Interne Notiz</label>
                            <textarea id="tracked-notes" wire:model.defer="notes" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                        </div>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                        <h3 class="text-base font-bold text-slate-900">Neuer Instagram-Datensatz</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Nach dem Speichern kannst du den Mini-Scan oder die Vollanalyse direkt auf der Detailseite starten.
                        </p>
                        <button wire:click="createTrackedPerson" class="mt-4 w-full rounded-lg border border-indigo-200 bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                            Profil speichern
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </section>

    <section class="grid gap-3 xl:grid-cols-2">
        @forelse($trackedPeople as $trackedPerson)
            <x-profile.lists.profile-list-item
                :tracked-person="$trackedPerson"
                :selected="$selectedTrackedPersonId === $trackedPerson->id && $showDetailModal"
            />
        @empty
            <div class="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">
                Noch keine Instagram-Profile gespeichert.
            </div>
        @endforelse
    </section>

    @if($showDetailModal && $selectedTrackedPerson)
        <x-instagram-profile-preview
            model="showDetailModal"
            :tracked-person="$selectedTrackedPerson"
            :detail-route="route('tracked-people.show', $selectedTrackedPerson->id)"
        />
    @endif

    @if(false && $showDetailModal && $selectedTrackedPersonId)
        <x-modal wire:model="showDetailModal" maxWidth="2xl">
            @php
                $selectedStatusLevel = $selectedTrackedPerson?->last_instagram_status_level ?: 'neutral';
                $selectedStatusLabel = match ($selectedStatusLevel) {
                    'success' => 'Aktuell',
                    'partial' => 'Teilweise',
                    'error' => 'Fehler',
                    default => 'Offen',
                };
                $selectedStatusBadgeClass = match ($selectedStatusLevel) {
                    'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                    'partial' => 'bg-amber-50 text-amber-800 ring-amber-200',
                    'error' => 'bg-rose-50 text-rose-700 ring-rose-200',
                    default => 'bg-slate-100 text-slate-600 ring-slate-200',
                };
                $selectedLastInstagramAnalyzedAt = $selectedTrackedPerson?->last_instagram_analyzed_at
                    ? $selectedTrackedPerson->last_instagram_analyzed_at->copy()->timezone(config('app.timezone'))
                    : null;
                $selectedProfileVisibility = $selectedTrackedPerson?->latestInstagramSnapshot?->profile_visibility ?? 'unknown';
                $selectedProfileVisibilityLabel = match ($selectedProfileVisibility) {
                    'public' => 'Oeffentlich',
                    'private' => 'Privat',
                    default => 'Unbekannt',
                };
                $selectedProfileVisibilityClass = match ($selectedProfileVisibility) {
                    'public' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                    'private' => 'bg-slate-100 text-slate-700 ring-slate-200',
                    default => 'bg-amber-50 text-amber-800 ring-amber-200',
                };
                $selectedProfileChangeFields = ['profile_image_hash', 'followers_count', 'following_count', 'posts_count', 'profile_visibility'];
                $selectedRecentChangeSnapshots = $selectedTrackedPerson && $selectedTrackedPerson->relationLoaded('instagramSnapshots')
                    ? $selectedTrackedPerson->instagramSnapshots
                    : collect();
                $selectedLatestChangeSnapshot = $selectedRecentChangeSnapshots
                    ->first(fn ($snapshot) => collect($snapshot->detected_changes ?? [])
                        ->contains(fn ($change) => in_array($change['field'] ?? null, $selectedProfileChangeFields, true)))
                    ?? $selectedTrackedPerson?->latestChangedInstagramSnapshot;
                $selectedLatestChange = collect($selectedLatestChangeSnapshot?->detected_changes ?? [])
                    ->first(fn ($change) => in_array($change['field'] ?? null, $selectedProfileChangeFields, true));
                $selectedLatestChangeAnalyzedAt = $selectedLatestChangeSnapshot?->analyzed_at
                    ? $selectedLatestChangeSnapshot->analyzed_at->copy()->timezone(config('app.timezone'))
                    : null;
            @endphp

            <div class="flex max-h-[92vh] flex-col overflow-hidden bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 sm:px-5">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Kurzansicht</p>
                        <h3 class="truncate text-lg font-bold text-slate-950">
                            {{ $selectedTrackedPerson?->instagram_username ? '@'.$selectedTrackedPerson->instagram_username : ($selectedTrackedPerson?->display_name ?? 'Profil') }}
                        </h3>
                    </div>
                    <button
                        type="button"
                        x-on:click="$dispatch('close')"
                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                    >
                        Schliessen
                    </button>
                </div>

                <div class="overflow-y-auto p-4 sm:p-5">
                    @if($selectedTrackedPerson)
                        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(260px,0.72fr)] lg:items-start">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                                <div class="h-20 w-20 shrink-0 overflow-hidden rounded-full border border-slate-200 bg-slate-100 shadow-sm">
                                    @if($selectedTrackedPerson->profile_image_url)
                                        <img src="{{ $selectedTrackedPerson->profile_image_url }}" alt="{{ $selectedTrackedPerson->display_name }}" class="h-full w-full object-cover">
                                    @else
                                        <div class="flex h-full w-full items-center justify-center text-xs font-semibold text-slate-500">IG</div>
                                    @endif
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="truncate text-xl font-bold text-slate-950">{{ $selectedTrackedPerson->display_name }}</h4>
                                        <span class="rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 {{ $selectedStatusBadgeClass }}">{{ $selectedStatusLabel }}</span>
                                        <span class="rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 {{ $selectedProfileVisibilityClass }}">{{ $selectedProfileVisibilityLabel }}</span>
                                    </div>

                                    <div class="mt-1 text-sm text-slate-600">
                                        {{ $selectedTrackedPerson->instagram_username ? '@'.$selectedTrackedPerson->instagram_username : 'Instagram-Handle fehlt' }}
                                    </div>

                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                                    <div class="text-lg font-bold text-slate-950">{{ $selectedTrackedPerson->instagram_posts_count !== null ? number_format($selectedTrackedPerson->instagram_posts_count) : '-' }}</div>
                                    <div class="text-xs text-slate-500">Beitraege</div>
                                </div>
                                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                                    <div class="text-lg font-bold text-slate-950">{{ $selectedTrackedPerson->instagram_followers_count !== null ? number_format($selectedTrackedPerson->instagram_followers_count) : '-' }}</div>
                                    <div class="text-xs text-slate-500">Follower</div>
                                </div>
                                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                                    <div class="text-lg font-bold text-slate-950">{{ $selectedTrackedPerson->instagram_following_count !== null ? number_format($selectedTrackedPerson->instagram_following_count) : '-' }}</div>
                                    <div class="text-xs text-slate-500">Gefolgt</div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 text-sm text-slate-600">
                            @if($selectedLastInstagramAnalyzedAt)
                                Zuletzt analysiert: <span title="{{ $selectedLastInstagramAnalyzedAt->format('d.m.Y H:i') }}">{{ $selectedLastInstagramAnalyzedAt->diffForHumans() }}</span>
                            @else
                                Noch nicht analysiert.
                            @endif
                        </div>

                        @if($selectedLatestChange)
                            <div class="mt-4 rounded-lg border border-sky-200 bg-sky-50 px-3 py-3 text-sm text-sky-950">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-sky-700">Zuletzt erkannte Aenderung</div>
                                    @if($selectedLatestChangeAnalyzedAt)
                                        <div class="text-xs font-semibold text-sky-800" title="{{ $selectedLatestChangeAnalyzedAt->format('d.m.Y H:i') }}">
                                            {{ $selectedLatestChangeAnalyzedAt->diffForHumans() }}
                                        </div>
                                    @endif
                                </div>
                                <div class="mt-2">
                                    <span class="font-semibold">{{ $selectedLatestChange['label'] ?? $selectedLatestChange['field'] ?? 'Aenderung' }}:</span>
                                    @if(($selectedLatestChange['field'] ?? null) === 'profile_image_hash')
                                        <span>Profilbild wurde aktualisiert.</span>
                                    @elseif(($selectedLatestChange['field'] ?? null) === 'profile_visibility')
                                        <span>{{ match ($selectedLatestChange['before'] ?? null) { 'public' => 'Oeffentlich', 'private' => 'Privat', default => 'Unbekannt' } }}</span>
                                        <span class="mx-1">-&gt;</span>
                                        <span>{{ match ($selectedLatestChange['after'] ?? null) { 'public' => 'Oeffentlich', 'private' => 'Privat', default => 'Unbekannt' } }}</span>
                                    @else
                                        <span>{{ filled($selectedLatestChange['before'] ?? null) ? number_format((int) $selectedLatestChange['before']) : '-' }}</span>
                                        <span class="mx-1">-&gt;</span>
                                        <span>{{ filled($selectedLatestChange['after'] ?? null) ? number_format((int) $selectedLatestChange['after']) : '-' }}</span>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div class="mt-5">
                            <livewire:user.tracked-person-detail
                                :tracked-person-id="$selectedTrackedPerson->id"
                                :compact="true"
                                :key="'tracked-person-scan-controls-'.$selectedTrackedPerson->id"
                            />
                        </div>

                        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <button
                                type="button"
                                x-on:click="$dispatch('close')"
                                class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                            >
                                Schliessen
                            </button>
                            <a
                                href="{{ route('tracked-people.show', $selectedTrackedPerson->id) }}"
                                wire:navigate
                                class="inline-flex items-center justify-center rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
                            >
                                Zur Detailseite
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </x-modal>
    @endif
</div>


