<div class="space-y-6" wire:loading.class="cursor-wait" wire:poll.visible.10000ms>
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

    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
                <h3 class="text-base font-bold text-slate-900">Personenliste</h3>
                <p class="mt-1 text-sm text-slate-600">Details und Analysen werden per Klick im Modal geoeffnet.</p>
            </div>
        </div>

        @if($trackedPeople->isEmpty())
            <div class="p-6">
                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-600">
                    Noch keine Personen gespeichert.
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-5 py-3">Person</th>
                            <th scope="col" class="px-5 py-3">Instagram</th>
                            <th scope="col" class="px-5 py-3">Status</th>
                            <th scope="col" class="px-5 py-3">Zuletzt aktualisiert</th>
                            <th scope="col" class="px-5 py-3 text-right">Aktion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach($trackedPeople as $trackedPerson)
                            @php
                                $isSelected = $selectedTrackedPersonId === $trackedPerson->id;
                                $statusLevel = $trackedPerson->last_instagram_status_level ?: 'neutral';
                                $statusLabel = match ($statusLevel) {
                                    'success' => 'Erfolgreich',
                                    'partial' => 'Teilweise',
                                    'error' => 'Fehler',
                                    default => 'Nicht analysiert',
                                };
                                $statusBadgeClass = match ($statusLevel) {
                                    'success' => 'bg-emerald-100 text-emerald-700 ring-emerald-200',
                                    'partial' => 'bg-amber-100 text-amber-800 ring-amber-200',
                                    'error' => 'bg-rose-100 text-rose-700 ring-rose-200',
                                    default => 'bg-slate-100 text-slate-600 ring-slate-200',
                                };
                                $secondaryText = $trackedPerson->alias ?: trim(collect([$trackedPerson->city, $trackedPerson->country])->filter()->implode(', '));
                                $secondaryText = $secondaryText !== '' ? $secondaryText : 'Keine Zusatzdaten';
                            @endphp

                            <tr wire:key="tracked-person-list-{{ $trackedPerson->id }}" class="transition {{ $isSelected && $showDetailModal ? 'bg-slate-50' : 'hover:bg-slate-50' }}">
                                <td class="px-5 py-4">
                                    <div class="flex min-w-56 items-center gap-3">
                                        <div class="h-10 w-10 shrink-0 overflow-hidden rounded-xl bg-slate-100">
                                            @if($trackedPerson->profile_image_url)
                                                <img src="{{ $trackedPerson->profile_image_url }}" alt="{{ $trackedPerson->display_name }}" class="h-full w-full object-cover">
                                            @else
                                                <div class="flex h-full w-full items-center justify-center text-[10px] font-semibold text-slate-500">
                                                    Kein Bild
                                                </div>
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            <div class="truncate font-semibold text-slate-900">{{ $trackedPerson->display_name }}</div>
                                            <div class="mt-0.5 truncate text-xs text-slate-500">{{ $secondaryText }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-700">
                                    {{ $trackedPerson->instagram_username ? '@'.$trackedPerson->instagram_username : '-' }}
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $statusBadgeClass }}">
                                            {{ $statusLabel }}
                                        </span>
                                        @if($trackedPerson->monitoring_enabled)
                                            <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-100">
                                                Beobachtung
                                            </span>
                                        @endif
                                    </div>
                                    @if($trackedPerson->last_instagram_status_message)
                                        <div class="mt-1 max-w-xs truncate text-xs text-slate-500" title="{{ $trackedPerson->last_instagram_status_message }}">
                                            {{ $trackedPerson->last_instagram_status_message }}
                                        </div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-700">
                                    @if($trackedPerson->last_instagram_analyzed_at)
                                        @php
                                            $lastInstagramAnalyzedAt = $trackedPerson->last_instagram_analyzed_at->copy()->timezone(config('app.timezone'));
                                        @endphp
                                        <span title="{{ $lastInstagramAnalyzedAt->format('d.m.Y H:i') }}">
                                            {{ $lastInstagramAnalyzedAt->diffForHumans() }}
                                        </span>
                                    @else
                                        Noch nicht analysiert
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <button
                                        type="button"
                                        wire:click="selectTrackedPerson({{ $trackedPerson->id }})"
                                        class="rounded-full border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:border-slate-400 hover:bg-slate-50"
                                    >
                                        Details
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if($showDetailModal && $selectedTrackedPersonId)
        <x-modal wire:model="showDetailModal" maxWidth="6xl">
            <div class="flex max-h-[92vh] flex-col overflow-hidden bg-slate-50">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 sm:px-5">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Personendetails</p>
                        <h3 class="truncate text-lg font-bold text-slate-900">
                            {{ $selectedTrackedPerson?->display_name ?? 'Person' }}
                        </h3>
                    </div>
                    <button
                        type="button"
                        x-on:click="$dispatch('close')"
                        class="rounded-full border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-400 hover:bg-slate-50"
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
