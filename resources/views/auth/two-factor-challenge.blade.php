<x-app-layout>
    <x-authentication-card>
        @php
            $inputClass = 'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100';
        @endphp

        <div class="mx-auto max-w-md" x-data="{ recovery: false }">
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Zwei-Faktor-Code</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Zugriff bestaetigen</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600" x-show="! recovery">
                    Gib den Code aus deiner Authenticator-App ein.
                </p>
                <p class="mt-2 text-sm leading-6 text-slate-600" x-cloak x-show="recovery">
                    Gib einen deiner Wiederherstellungscodes ein.
                </p>
            </div>

            <x-validation-errors class="mb-4" />

            <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-5">
                @csrf

                <div x-show="! recovery">
                    <label for="code" class="mb-1.5 block text-sm font-semibold text-slate-700">Code</label>
                    <input id="code" class="{{ $inputClass }}" type="text" inputmode="numeric" name="code" autofocus x-ref="code" autocomplete="one-time-code" placeholder="123456">
                </div>

                <div x-cloak x-show="recovery">
                    <label for="recovery_code" class="mb-1.5 block text-sm font-semibold text-slate-700">Wiederherstellungscode</label>
                    <input id="recovery_code" class="{{ $inputClass }}" type="text" name="recovery_code" x-ref="recovery_code" autocomplete="one-time-code" placeholder="Recovery Code">
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <button
                        type="button"
                        class="text-left text-sm font-semibold text-teal-700 hover:text-teal-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
                        x-show="! recovery"
                        x-on:click="
                            recovery = true;
                            $nextTick(() => { $refs.recovery_code.focus() })
                        "
                    >
                        Wiederherstellungscode nutzen
                    </button>

                    <button
                        type="button"
                        class="text-left text-sm font-semibold text-teal-700 hover:text-teal-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
                        x-cloak
                        x-show="recovery"
                        x-on:click="
                            recovery = false;
                            $nextTick(() => { $refs.code.focus() })
                        "
                    >
                        Authenticator-Code nutzen
                    </button>

                    <button type="submit" class="inline-flex h-11 items-center justify-center rounded-lg bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Einloggen
                    </button>
                </div>
            </form>
        </div>
    </x-authentication-card>
</x-app-layout>
