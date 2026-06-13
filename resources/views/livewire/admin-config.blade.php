<div class="min-h-screen bg-slate-50 pb-16" wire:loading.class="cursor-wait">
    <div class="border-b border-slate-200 bg-white">
        <div class="container mx-auto px-5 py-6">
            <h1 class="text-2xl font-bold tracking-tight text-slate-950">Admin-Konfiguration</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                Verwalte SaaS-Pakete, Limits, Credit-Kosten und AI-Modelle fuer die Base-Installation.
            </p>
        </div>
    </div>

    <main class="container mx-auto px-5 py-6">
        <div class="mb-6 flex flex-wrap gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
            <button
                type="button"
                wire:click="$set('activeTab', 'billing')"
                @class([
                    'rounded-xl px-4 py-2.5 text-sm font-bold transition',
                    'bg-slate-950 text-white shadow-sm' => $activeTab === 'billing',
                    'text-slate-600 hover:bg-slate-100 hover:text-slate-950' => $activeTab !== 'billing',
                ])
            >
                Pakete & Credits
            </button>
            <button
                type="button"
                wire:click="$set('activeTab', 'audio')"
                @class([
                    'rounded-xl px-4 py-2.5 text-sm font-bold transition',
                    'bg-gradient-to-r from-sky-600 to-emerald-600 text-white shadow-sm' => $activeTab === 'audio',
                    'text-slate-600 hover:bg-slate-100 hover:text-slate-950' => $activeTab !== 'audio',
                ])
            >
                AI-Audio
            </button>
        </div>

        @if ($activeTab === 'audio')
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-black text-slate-950">Audio-Eingabe und Audio-Ausgabe</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        Hinterlege die Modell-IDs fuer Speech-to-Text und Text-to-Speech. Die Browser-Sprachfunktionen im Chat bleiben als Fallback verfuegbar.
                    </p>
                </div>

                <form wire:submit="saveAudioModels" class="space-y-6 p-6">
                    @if (session()->has('audio-models-saved'))
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                            {{ session('audio-models-saved') }}
                        </div>
                    @endif

                    <div>
                        <label for="audio-input-model" class="block text-sm font-bold text-slate-800">
                            Modell fuer Audio-Eingabe
                        </label>
                        <p class="mt-1 text-xs leading-5 text-slate-500">
                            Modell-ID fuer die Umwandlung gesprochener Sprache in Text.
                        </p>
                        <input
                            id="audio-input-model"
                            type="text"
                            wire:model="audioInputModel"
                            placeholder="z. B. whisper-1"
                            class="mt-2 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                        >
                        @error('audioInputModel')
                            <p class="mt-2 text-xs font-semibold text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="audio-output-model" class="block text-sm font-bold text-slate-800">
                            Modell fuer Audio-Ausgabe
                        </label>
                        <p class="mt-1 text-xs leading-5 text-slate-500">
                            Modell-ID fuer die Umwandlung der Assistentenantwort in Sprache.
                        </p>
                        <input
                            id="audio-output-model"
                            type="text"
                            wire:model="audioOutputModel"
                            placeholder="z. B. tts-1"
                            class="mt-2 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                        >
                        @error('audioOutputModel')
                            <p class="mt-2 text-xs font-semibold text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end">
                        <button
                            type="submit"
                            class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60"
                            wire:loading.attr="disabled"
                            wire:target="saveAudioModels"
                        >
                            <span wire:loading.remove wire:target="saveAudioModels">Audio-Modelle speichern</span>
                            <span wire:loading wire:target="saveAudioModels">Wird gespeichert...</span>
                        </button>
                    </div>
                </form>
            </section>
        @else
            @livewire('admin.config.billing-settings')
        @endif
    </main>
</div>
