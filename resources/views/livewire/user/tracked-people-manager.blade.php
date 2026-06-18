<div class="space-y-4" wire:loading.class="cursor-wait">
    @php
        $managerStatusClass = match ($managerStatusLevel ?? 'neutral') {
            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
            default => 'border-slate-200 bg-slate-50 text-slate-800',
        };
    @endphp

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-3 py-3 sm:px-5 sm:py-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold text-slate-950">Beobachtete Profile</h2>
                    <p class="mt-1 text-sm text-slate-500">Profile anlegen, filtern und Dauerbeobachtung steuern.</p>
                </div>
                <button
                    wire:click="toggleCreateForm"
                    class="inline-flex w-full sm:w-auto items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-indigo-600 to-violet-600 px-3 py-2 sm:px-4 sm:py-2.5 text-xs sm:text-sm font-semibold text-white shadow-sm transition hover:from-indigo-700 hover:to-violet-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
                    </svg>
                    <span>{{ $showCreateForm ? 'Formular schliessen' : 'Beobachtetes Profil anlegen' }}</span>
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
                        <h3 class="text-base font-bold text-slate-900">Neues beobachtetes Profil</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Diese bewusste Anlage aktiviert dauerhaftes Tracking und zaehlt gegen das Profil-Limit deines Tarifs. Ein normaler Instagram-Scan legt keine beobachtete Person an.
                        </p>
                        <button wire:click="createTrackedPerson" class="mt-4 w-full rounded-lg border border-indigo-200 bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                            Beobachtung anlegen
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </section>

    <livewire:user.tracked-people-list />
</div>


