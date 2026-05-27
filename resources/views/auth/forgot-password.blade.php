<x-app-layout>
    <x-authentication-card>
        @php
            $inputClass = 'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100';
        @endphp

        <div class="mx-auto max-w-md">
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Zugang wiederherstellen</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Reset-Link anfordern</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Gib deine E-Mail-Adresse ein. Wir senden dir einen Link zum Zuruecksetzen deines Passworts.
                </p>
            </div>

            @if (session('status'))
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            <x-validation-errors class="mb-4" />

            <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="email" class="mb-1.5 block text-sm font-semibold text-slate-700">E-Mail</label>
                    <input id="email" class="{{ $inputClass }}" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="name@example.com">
                </div>

                <button type="submit" class="inline-flex h-11 w-full items-center justify-center rounded-lg bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Link anfordern
                </button>
            </form>
        </div>
    </x-authentication-card>
</x-app-layout>
