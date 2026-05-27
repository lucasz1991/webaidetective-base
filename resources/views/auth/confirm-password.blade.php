<x-guest-layout>
    <x-authentication-card>
        @php
            $inputClass = 'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100';
        @endphp

        <div class="mx-auto max-w-md">
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Sicherheitspruefung</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Passwort bestaetigen</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Bitte bestaetige dein Passwort, bevor du diesen geschuetzten Bereich weiter nutzt.
                </p>
            </div>

            <x-validation-errors class="mb-4" />

            <form method="POST" action="{{ route('password.confirm') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="password" class="mb-1.5 block text-sm font-semibold text-slate-700">Passwort</label>
                    <input id="password" class="{{ $inputClass }}" type="password" name="password" required autocomplete="current-password" autofocus placeholder="Dein Passwort">
                </div>

                <button type="submit" class="inline-flex h-11 w-full items-center justify-center rounded-lg bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Bestaetigen
                </button>
            </form>
        </div>
    </x-authentication-card>
</x-guest-layout>
