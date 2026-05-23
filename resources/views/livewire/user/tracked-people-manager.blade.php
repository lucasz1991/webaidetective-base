<div class="space-y-5" wire:loading.class="cursor-wait" wire:poll.visible.10000ms>
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
        $instagramProfiles = $trackedPeople->filter(fn ($person) => filled($person->instagram_username));
        $monitoredProfiles = $instagramProfiles->filter(fn ($person) => $person->monitoring_enabled);
        $alertProfiles = $instagramProfiles->filter(fn ($person) => $person->notify_social_changes && $person->notify_instagram_changes);
    @endphp

    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-4 py-4 sm:px-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-bold text-slate-950">Instagram-Uebersicht</h2>
                    <p class="mt-1 text-sm text-slate-600">Profile, Kennzahlen, Scanstatus und Aenderungen im Instagram-Fokus.</p>
                </div>
                <button
                    wire:click="toggleCreateForm"
                    class="inline-flex items-center justify-center rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
                >
                    {{ $showCreateForm ? 'Formular schliessen' : 'Instagram-Profil erfassen' }}
                </button>
            </div>
        </div>

        <div class="grid gap-px bg-slate-200 sm:grid-cols-3">
            <div class="bg-white px-5 py-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Instagram-Profile</div>
                <div class="mt-1 text-2xl font-bold text-slate-950">{{ number_format($instagramProfiles->count()) }}</div>
            </div>
            <div class="bg-white px-5 py-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Dauerbeobachtung</div>
                <div class="mt-1 text-2xl font-bold text-slate-950">{{ number_format($monitoredProfiles->count()) }}</div>
            </div>
            <div class="bg-white px-5 py-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Benachrichtigungen</div>
                <div class="mt-1 text-2xl font-bold text-slate-950">{{ number_format($alertProfiles->count()) }}</div>
            </div>
        </div>

        @if($managerStatus)
            <div class="mx-4 mt-4 rounded-lg border p-3 text-sm sm:mx-5 {{ $managerStatusClass }}">
                {{ $managerStatus }}
            </div>
        @endif

        @if($showCreateForm)
            <div class="border-t border-slate-200 bg-slate-50 px-4 py-5 sm:px-5">
                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(260px,0.5fr)]">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="tracked-first-name" class="mb-1 block text-sm font-medium text-slate-700">Vorname</label>
                            <input id="tracked-first-name" type="text" wire:model.defer="first_name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500">
                            @error('first_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="tracked-last-name" class="mb-1 block text-sm font-medium text-slate-700">Nachname</label>
                            <input id="tracked-last-name" type="text" wire:model.defer="last_name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500">
                            @error('last_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="tracked-alias" class="mb-1 block text-sm font-medium text-slate-700">Alias</label>
                            <input id="tracked-alias" type="text" wire:model.defer="alias" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500">
                        </div>
                        <div>
                            <label for="tracked-instagram" class="mb-1 block text-sm font-medium text-slate-700">Instagram-Handle</label>
                            <input id="tracked-instagram" type="text" wire:model.defer="instagram_username" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500" placeholder="@username">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="tracked-notes" class="mb-1 block text-sm font-medium text-slate-700">Interne Notiz</label>
                            <textarea id="tracked-notes" wire:model.defer="notes" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500"></textarea>
                        </div>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                        <div class="h-1.5 w-20 rounded-full bg-gradient-to-r from-amber-400 via-rose-500 to-fuchsia-600"></div>
                        <h3 class="mt-4 text-base font-bold text-slate-950">Neuer Instagram-Datensatz</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Nach dem Speichern kannst du den Mini-Scan oder die Vollanalyse direkt im Detailfenster starten.
                        </p>
                        <button wire:click="createTrackedPerson" class="mt-4 w-full rounded-lg bg-gradient-to-r from-rose-500 to-fuchsia-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:from-rose-600 hover:to-fuchsia-700">
                            Profil speichern
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </section>

    <section class="grid gap-3">
        @forelse($trackedPeople as $trackedPerson)
            @php
                $isSelected = $selectedTrackedPersonId === $trackedPerson->id;
                $statusLevel = $trackedPerson->last_instagram_status_level ?: 'neutral';
                $statusLabel = match ($statusLevel) {
                    'success' => 'Aktuell',
                    'partial' => 'Teilweise',
                    'error' => 'Fehler',
                    default => 'Offen',
                };
                $statusBadgeClass = match ($statusLevel) {
                    'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                    'partial' => 'bg-amber-50 text-amber-800 ring-amber-200',
                    'error' => 'bg-rose-50 text-rose-700 ring-rose-200',
                    default => 'bg-slate-100 text-slate-600 ring-slate-200',
                };
                $lastInstagramAnalyzedAt = $trackedPerson->last_instagram_analyzed_at
                    ? $trackedPerson->last_instagram_analyzed_at->copy()->timezone(config('app.timezone'))
                    : null;
            @endphp

            <article wire:key="tracked-person-instagram-card-{{ $trackedPerson->id }}" class="rounded-lg border border-slate-200 bg-white shadow-sm transition {{ $isSelected && $showDetailModal ? 'ring-2 ring-pink-300' : 'hover:border-slate-300' }}">
                <div class="grid gap-4 p-4 lg:grid-cols-[minmax(260px,1.1fr)_minmax(0,1fr)_auto] lg:items-center">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="rounded-full bg-gradient-to-tr from-amber-400 via-rose-500 to-fuchsia-600 p-0.5">
                            <div class="h-14 w-14 overflow-hidden rounded-full border-2 border-white bg-slate-100">
                                @if($trackedPerson->profile_image_url)
                                    <img src="{{ $trackedPerson->profile_image_url }}" alt="{{ $trackedPerson->display_name }}" class="h-full w-full object-cover">
                                @else
                                    <div class="flex h-full w-full items-center justify-center text-[10px] font-semibold text-slate-500">IG</div>
                                @endif
                            </div>
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-base font-bold text-slate-950">{{ $trackedPerson->display_name }}</div>
                            <div class="mt-0.5 truncate text-sm text-slate-600">
                                {{ $trackedPerson->instagram_username ? '@'.$trackedPerson->instagram_username : 'Instagram-Handle fehlt' }}
                            </div>
                            @if($trackedPerson->last_instagram_status_message)
                                <div class="mt-1 truncate text-xs text-slate-500" title="{{ $trackedPerson->last_instagram_status_message }}">
                                    {{ $trackedPerson->last_instagram_status_message }}
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div>
                            <div class="text-sm font-bold text-slate-950">{{ $trackedPerson->instagram_posts_count !== null ? number_format($trackedPerson->instagram_posts_count) : '-' }}</div>
                            <div class="text-xs text-slate-500">Beitraege</div>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-slate-950">{{ $trackedPerson->instagram_followers_count !== null ? number_format($trackedPerson->instagram_followers_count) : '-' }}</div>
                            <div class="text-xs text-slate-500">Follower</div>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-slate-950">{{ $trackedPerson->instagram_following_count !== null ? number_format($trackedPerson->instagram_following_count) : '-' }}</div>
                            <div class="text-xs text-slate-500">Gefolgt</div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                        <span class="rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 {{ $statusBadgeClass }}">{{ $statusLabel }}</span>
                        @if($trackedPerson->monitoring_enabled)
                            <span class="rounded-lg bg-slate-950 px-2.5 py-1 text-xs font-semibold text-white">Live</span>
                        @endif
                        @if($lastInstagramAnalyzedAt)
                            <span class="text-xs text-slate-500" title="{{ $lastInstagramAnalyzedAt->format('d.m.Y H:i') }}">{{ $lastInstagramAnalyzedAt->diffForHumans() }}</span>
                        @endif
                        <button
                            type="button"
                            wire:click="selectTrackedPerson({{ $trackedPerson->id }})"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50"
                        >
                            Oeffnen
                        </button>
                    </div>
                </div>
            </article>
        @empty
            <div class="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">
                Noch keine Instagram-Profile gespeichert.
            </div>
        @endforelse
    </section>

    @if($showDetailModal && $selectedTrackedPersonId)
        <x-modal wire:model="showDetailModal" maxWidth="6xl">
            <div class="flex max-h-[92vh] flex-col overflow-hidden bg-[#fafafa]">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 sm:px-5">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Instagram-Profil</p>
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
                    <livewire:user.tracked-person-detail :tracked-person-id="$selectedTrackedPersonId" :key="'tracked-person-detail-modal-'.$selectedTrackedPersonId" />
                </div>
            </div>
        </x-modal>
    @endif
</div>
