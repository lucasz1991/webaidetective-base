<x-app-layout>
    <x-authentication-card>
        <div class="mx-auto max-w-lg">
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">E-Mail-Verifizierung</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Postfach pruefen</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Bevor du SocialScope vollstaendig nutzt, bestaetige bitte deine E-Mail-Adresse ueber den Link, den wir dir gesendet haben.
                </p>
            </div>

            @if (session('status') == 'verification-link-sent')
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                    Ein neuer Bestaetigungslink wurde an deine E-Mail-Adresse gesendet.
                </div>
            @endif

            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <form method="POST" action="{{ route('verification.send') }}">
                    @csrf

                    <button type="submit" class="inline-flex h-11 items-center justify-center rounded-lg bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        E-Mail erneut senden
                    </button>
                </form>

                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <a
                        href="{{ route('profile.show') }}"
                        class="font-semibold text-teal-700 hover:text-teal-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
                    >
                        Profil bearbeiten
                    </a>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <button type="submit" class="font-semibold text-slate-600 hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                            Abmelden
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </x-authentication-card>
</x-app-layout>
