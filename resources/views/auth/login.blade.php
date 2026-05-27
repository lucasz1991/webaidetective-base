<x-app-layout>
    <x-authentication-card>
        @php
            $inputClass = 'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100';
        @endphp

        <div class="mx-auto max-w-md">
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Willkommen zurueck</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Einloggen</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Nutze deinen persoenlichen Service-Zugang fuer Monitoring, Analysen und Benachrichtigungen.
                </p>
            </div>

            <x-validation-errors class="mb-4" />

            @if (session('status'))
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="email" class="mb-1.5 block text-sm font-semibold text-slate-700">E-Mail</label>
                    <input id="email" class="{{ $inputClass }}" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="name@example.com">
                </div>

                <div>
                    <div class="mb-1.5 flex items-center justify-between gap-4">
                        <label for="password" class="block text-sm font-semibold text-slate-700">Passwort</label>
                        @if (Route::has('password.request'))
                            <a class="text-sm font-semibold text-teal-700 hover:text-teal-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2" href="{{ route('password.request') }}">
                                Vergessen?
                            </a>
                        @endif
                    </div>
                    <input id="password" class="{{ $inputClass }}" type="password" name="password" required autocomplete="current-password" placeholder="Dein Passwort">
                </div>

                <label for="remember_me" class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-3">
                    <span>
                        <span class="block text-sm font-semibold text-slate-800">Angemeldet bleiben</span>
                        <span class="block text-xs text-slate-500">Sitzung auf diesem Geraet merken</span>
                    </span>
                    <span class="relative inline-flex items-center">
                        <input id="remember_me" name="remember" type="checkbox" class="peer sr-only">
                        <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-teal-600 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-teal-500 peer-focus:ring-offset-2"></span>
                        <span class="absolute left-1 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                    </span>
                </label>

                <button type="submit" class="inline-flex h-11 w-full items-center justify-center rounded-lg bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Einloggen
                </button>
            </form>
        </div>
    </x-authentication-card>
</x-app-layout>
