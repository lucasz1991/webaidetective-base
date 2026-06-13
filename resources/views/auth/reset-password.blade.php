<x-app-layout>
    <x-authentication-card>
        @php
            $inputClass = 'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100';
            $labelClass = 'mb-1.5 block text-sm font-semibold text-slate-700';
        @endphp

        <div class="mx-auto max-w-md">
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Passwort aktualisieren</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Neues Passwort setzen</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Waehle ein neues Passwort fuer deinen SocialScope-Zugang.
                </p>
            </div>

            <x-validation-errors class="mb-4" />

            <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
                @csrf

                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <div>
                    <label for="email" class="{{ $labelClass }}">E-Mail</label>
                    <input id="email" class="{{ $inputClass }}" type="email" name="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="username" placeholder="name@example.com">
                </div>

                <div>
                    <label for="password" class="{{ $labelClass }}">Passwort</label>
                    <input id="password" class="{{ $inputClass }}" type="password" name="password" required autocomplete="new-password" placeholder="Neues Passwort">
                </div>

                <div>
                    <label for="password_confirmation" class="{{ $labelClass }}">Passwort bestaetigen</label>
                    <input id="password_confirmation" class="{{ $inputClass }}" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Passwort wiederholen">
                </div>

                <button type="submit" class="inline-flex h-11 w-full items-center justify-center rounded-lg bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Passwort zuruecksetzen
                </button>
            </form>
        </div>
    </x-authentication-card>
</x-app-layout>
