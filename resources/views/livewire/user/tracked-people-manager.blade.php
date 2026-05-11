<div class="space-y-6" wire:loading.class="cursor-wait" wire:poll.visible.10000ms>
    @php
        $managerStatusClass = match ($managerStatusLevel ?? 'neutral') {
            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
            default => 'border-slate-200 bg-slate-50 text-slate-800',
        };
    @endphp

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-slate-900">Gespeicherte Personen</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Das Dashboard konzentriert sich nur noch auf Personen, ihre Social-Handles, Analysen und den gespeicherten Verlauf.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-600">
                    {{ $trackedPeople->count() }} Personen
                </div>
                <button
                    wire:click="toggleCreateForm"
                    class="rounded-full bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-slate-800"
                >
                    {{ $showCreateForm ? 'Formular schliessen' : 'Neue Person' }}
                </button>
            </div>
        </div>

        @if($managerStatus)
            <div class="mt-4 rounded-2xl border p-4 text-sm {{ $managerStatusClass }}">
                {{ $managerStatus }}
            </div>
        @endif

        @if($showCreateForm)
            <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <div class="mb-4">
                    <h3 class="text-lg font-bold text-slate-900">Person anlegen</h3>
                    <p class="mt-1 text-sm text-slate-600">
                        TikTok, Facebook, X, YouTube und Snapchat sind vorbereitet. Die Analyse ist aktuell fuer Instagram aktiv.
                    </p>
                </div>

                <div class="grid gap-4 lg:grid-cols-3">
                    <div>
                        <label for="tracked-first-name" class="mb-1 block text-sm font-medium text-slate-700">Vorname</label>
                        <input id="tracked-first-name" type="text" wire:model.defer="first_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('first_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="tracked-last-name" class="mb-1 block text-sm font-medium text-slate-700">Nachname</label>
                        <input id="tracked-last-name" type="text" wire:model.defer="last_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('last_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="tracked-alias" class="mb-1 block text-sm font-medium text-slate-700">Alias</label>
                        <input id="tracked-alias" type="text" wire:model.defer="alias" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="tracked-birth-date" class="mb-1 block text-sm font-medium text-slate-700">Geburtsdatum</label>
                        <input id="tracked-birth-date" type="date" wire:model.defer="date_of_birth" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="tracked-city" class="mb-1 block text-sm font-medium text-slate-700">Ort</label>
                        <input id="tracked-city" type="text" wire:model.defer="city" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="tracked-country" class="mb-1 block text-sm font-medium text-slate-700">Land</label>
                        <input id="tracked-country" type="text" wire:model.defer="country" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="tracked-instagram" class="mb-1 block text-sm font-medium text-slate-700">Instagram</label>
                        <input id="tracked-instagram" type="text" wire:model.defer="instagram_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" placeholder="@username">
                    </div>
                    <div>
                        <label for="tracked-tiktok" class="mb-1 block text-sm font-medium text-slate-700">TikTok</label>
                        <input id="tracked-tiktok" type="text" wire:model.defer="tiktok_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="tracked-facebook" class="mb-1 block text-sm font-medium text-slate-700">Facebook</label>
                        <input id="tracked-facebook" type="text" wire:model.defer="facebook_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="tracked-x" class="mb-1 block text-sm font-medium text-slate-700">X / Twitter</label>
                        <input id="tracked-x" type="text" wire:model.defer="x_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="tracked-youtube" class="mb-1 block text-sm font-medium text-slate-700">YouTube</label>
                        <input id="tracked-youtube" type="text" wire:model.defer="youtube_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="tracked-snapchat" class="mb-1 block text-sm font-medium text-slate-700">Snapchat</label>
                        <input id="tracked-snapchat" type="text" wire:model.defer="snapchat_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div class="lg:col-span-3">
                        <label for="tracked-notes" class="mb-1 block text-sm font-medium text-slate-700">Notizen</label>
                        <textarea id="tracked-notes" wire:model.defer="notes" rows="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"></textarea>
                    </div>
                </div>

                <div class="mt-5 flex justify-end">
                    <button wire:click="createTrackedPerson" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-blue-700">
                        Person speichern
                    </button>
                </div>
            </div>
        @endif
    </section>

    <section class="grid gap-6 xl:grid-cols-[320px_minmax(0,1fr)]">
        <aside class="space-y-3">
            @forelse($trackedPeople as $trackedPerson)
                @php
                    $isSelected = $selectedTrackedPersonId === $trackedPerson->id;
                    $statusLevel = $trackedPerson->last_instagram_status_level ?? 'neutral';
                    $statusBadgeClass = match ($statusLevel) {
                        'success' => 'bg-emerald-100 text-emerald-700',
                        'partial' => 'bg-amber-100 text-amber-800',
                        'error' => 'bg-rose-100 text-rose-700',
                        default => 'bg-slate-100 text-slate-600',
                    };
                @endphp

                <button
                    wire:click="selectTrackedPerson({{ $trackedPerson->id }})"
                    wire:key="tracked-person-list-{{ $trackedPerson->id }}"
                    class="w-full rounded-2xl border p-4 text-left shadow-sm transition {{ $isSelected ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white hover:border-slate-300' }}"
                >
                    <div class="flex items-center gap-3">
                        <div class="h-14 w-14 overflow-hidden rounded-2xl bg-slate-100">
                            @if($trackedPerson->profile_image_url)
                                <img src="{{ $trackedPerson->profile_image_url }}" alt="{{ $trackedPerson->display_name }}" class="h-full w-full object-cover">
                            @else
                                <div class="flex h-full w-full items-center justify-center text-xs font-semibold {{ $isSelected ? 'text-slate-400' : 'text-slate-500' }}">
                                    Kein Bild
                                </div>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-bold {{ $isSelected ? 'text-white' : 'text-slate-900' }}">
                                {{ $trackedPerson->display_name }}
                            </div>
                            <div class="mt-1 truncate text-xs {{ $isSelected ? 'text-slate-300' : 'text-slate-500' }}">
                                {{ $trackedPerson->instagram_username ? '@'.$trackedPerson->instagram_username : ($trackedPerson->alias ?: 'Ohne Instagram-Handle') }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded-full px-2 py-1 font-semibold {{ $isSelected ? 'bg-white/10 text-white' : $statusBadgeClass }}">
                            {{ $trackedPerson->last_instagram_status_level ?: 'nicht analysiert' }}
                        </span>
                        @if($trackedPerson->monitoring_enabled)
                            <span class="rounded-full px-2 py-1 font-semibold {{ $isSelected ? 'bg-white/10 text-white' : 'bg-indigo-100 text-indigo-700' }}">
                                Beobachtung aktiv
                            </span>
                        @endif
                        @if($trackedPerson->notify_social_changes)
                            <span class="rounded-full px-2 py-1 font-semibold {{ $isSelected ? 'bg-white/10 text-white' : 'bg-sky-100 text-sky-700' }}">
                                Benachrichtigungen aktiv
                            </span>
                        @endif
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                        <div class="rounded-xl {{ $isSelected ? 'bg-white/10' : 'bg-slate-50' }} px-2 py-2">
                            <div class="{{ $isSelected ? 'text-slate-300' : 'text-slate-500' }}">Follower</div>
                            <div class="mt-1 font-bold">{{ $trackedPerson->instagram_followers_count !== null ? number_format($trackedPerson->instagram_followers_count) : '—' }}</div>
                        </div>
                        <div class="rounded-xl {{ $isSelected ? 'bg-white/10' : 'bg-slate-50' }} px-2 py-2">
                            <div class="{{ $isSelected ? 'text-slate-300' : 'text-slate-500' }}">Gefolgt</div>
                            <div class="mt-1 font-bold">{{ $trackedPerson->instagram_following_count !== null ? number_format($trackedPerson->instagram_following_count) : '—' }}</div>
                        </div>
                        <div class="rounded-xl {{ $isSelected ? 'bg-white/10' : 'bg-slate-50' }} px-2 py-2">
                            <div class="{{ $isSelected ? 'text-slate-300' : 'text-slate-500' }}">Beitraege</div>
                            <div class="mt-1 font-bold">{{ $trackedPerson->instagram_posts_count !== null ? number_format($trackedPerson->instagram_posts_count) : '—' }}</div>
                        </div>
                    </div>
                </button>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-600">
                    Noch keine Personen gespeichert.
                </div>
            @endforelse
        </aside>

        <div>
            @if($selectedTrackedPersonId)
                <livewire:user.tracked-person-detail :tracked-person-id="$selectedTrackedPersonId" :key="'tracked-person-detail-'.$selectedTrackedPersonId" />
            @else
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">
                    Lege zuerst eine Person an, damit Details, Social-Handles und Analysen angezeigt werden koennen.
                </div>
            @endif
        </div>
    </section>
</div>
