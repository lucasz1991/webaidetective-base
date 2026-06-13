@section('title', 'Pakete und Credits')

<div wire:loading.class="cursor-wait" class="overflow-hidden bg-white text-slate-950">
    <section class="relative border-b border-slate-100 bg-gradient-to-b from-cyan-50 via-white to-white px-5 py-20 sm:px-8">
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute left-1/4 top-0 h-72 w-72 rounded-full bg-sky-200/40 blur-3xl"></div>
            <div class="absolute right-1/4 top-10 h-72 w-72 rounded-full bg-emerald-200/35 blur-3xl"></div>
        </div>
        <div class="relative mx-auto max-w-4xl text-center">
            <span class="inline-flex rounded-full border border-cyan-200 bg-white px-4 py-2 text-xs font-black uppercase tracking-[.18em] text-cyan-800 shadow-sm">
                SocialScope Pakete
            </span>
            <h1 class="mt-6 text-5xl font-black tracking-tight text-slate-950 sm:text-6xl">Der passende Umfang fuer dein Netzwerk.</h1>
            <p class="mx-auto mt-6 max-w-3xl text-lg leading-8 text-slate-600">
                Jeder Plan kombiniert Profilverwaltung, Scan-Credits, Monitoring und gespeicherte Historie. Hoehere Pakete erweitern Volumen, Geschwindigkeit und Zusammenarbeit.
            </p>
        </div>
    </section>

    <section class="px-5 py-16 sm:px-8">
        <div class="mx-auto grid max-w-7xl gap-6 lg:grid-cols-3">
            @foreach($plans as $plan)
                <article class="relative flex flex-col rounded-[2rem] border bg-white p-7 transition hover:-translate-y-1 hover:shadow-2xl hover:shadow-slate-200/70 {{ $plan['featured'] ? 'border-cyan-400 shadow-xl shadow-cyan-100/60 ring-4 ring-cyan-50' : 'border-slate-200 shadow-sm' }}">
                    @if($plan['featured'])
                        <span class="absolute right-6 top-6 rounded-full bg-cyan-100 px-3 py-1 text-[10px] font-black uppercase tracking-[.16em] text-cyan-800">Empfohlen</span>
                    @endif

                    <div>
                        <p class="text-sm font-black uppercase tracking-[.18em] {{ $plan['featured'] ? 'text-cyan-700' : 'text-slate-500' }}">{{ $plan['name'] }}</p>
                        <div class="mt-5 flex items-end gap-2">
                            @if($plan['price'] !== null)
                                <span class="text-5xl font-black tracking-tight text-slate-950">{{ number_format($plan['price'], 0, ',', '.') }} EUR</span>
                                <span class="pb-1.5 text-sm font-semibold text-slate-500">/ Monat</span>
                            @else
                                <span class="text-4xl font-black tracking-tight text-slate-950">Individuell</span>
                            @endif
                        </div>
                        <p class="mt-5 min-h-[5.25rem] leading-7 text-slate-600">{{ $plan['description'] }}</p>
                    </div>

                    <div class="mt-6 grid grid-cols-2 gap-3">
                        <div class="rounded-2xl bg-slate-50 p-3">
                            <p class="text-xl font-black text-slate-950">{{ number_format($plan['max_profiles'], 0, ',', '.') }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-slate-400">Profile</p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-3">
                            <p class="text-xl font-black text-slate-950">{{ number_format($plan['monthly_credits'], 0, ',', '.') }}</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-slate-400">Credits / Monat</p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-3">
                            <p class="text-xl font-black text-slate-950">{{ number_format($plan['max_history_days'], 0, ',', '.') }} Tage</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-slate-400">Historie</p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-3">
                            <p class="text-xl font-black text-slate-950">{{ $plan['scan_frequency_minutes'] }} Min.</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-slate-400">Minimaler Takt</p>
                        </div>
                    </div>

                    <a href="{{ route('register') }}" wire:navigate class="mt-7 inline-flex w-full items-center justify-center rounded-2xl px-5 py-4 font-black transition {{ $plan['featured'] ? 'bg-cyan-600 text-white shadow-lg shadow-cyan-600/20 hover:bg-cyan-700' : 'bg-slate-950 text-white hover:bg-slate-800' }}">
                        {{ $plan['name'] }} starten
                    </a>

                    <div class="mt-7 border-t border-slate-100 pt-6">
                        <p class="text-xs font-black uppercase tracking-[.16em] text-slate-400">Enthalten</p>
                        <ul class="mt-4 space-y-3 text-sm text-slate-700">
                            <li class="flex gap-3"><span class="font-black text-emerald-600">✓</span> {{ number_format($plan['max_users'], 0, ',', '.') }} {{ $plan['max_users'] === 1 ? 'Benutzer' : 'Benutzer' }}</li>
                            @foreach($plan['features'] as $feature)
                                <li class="flex gap-3"><span class="font-black text-emerald-600">✓</span> <span>{{ $feature }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="border-y border-slate-100 bg-slate-50 px-5 py-20 sm:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="grid gap-10 lg:grid-cols-[.75fr_1.25fr] lg:items-start">
                <div>
                    <p class="text-sm font-black uppercase tracking-[.2em] text-cyan-700">In jedem Workspace</p>
                    <h2 class="mt-4 text-4xl font-black tracking-tight text-slate-950">Die Kernfunktionen wachsen mit deinem Bedarf.</h2>
                    <p class="mt-5 text-lg leading-8 text-slate-600">Credits steuern die rechenintensiven Scans. Deine strukturierte Arbeitsoberflaeche, Profile und gespeicherten Zusammenhaenge bleiben der zentrale Ausgangspunkt.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach([
                        ['Profilscans', 'Mini-, Voll-, Listen-, Beitrags- und Vorschlagsscans mit Live-Fortschritt.', 'bg-sky-50 text-sky-700'],
                        ['Network Map', 'Richtungsbezogene Beziehungen, bekannte Profile und rekonstruierte Hinweise.', 'bg-emerald-50 text-emerald-700'],
                        ['AI-Copilot', 'Kontextbezogene Analyse, Tool-Steuerung und automatische Reaktion auf Scanergebnisse.', 'bg-violet-50 text-violet-700'],
                        ['Monitoring', 'Individuelle Intervalle, Statusverlauf und Benachrichtigungen bei relevanten Aenderungen.', 'bg-amber-50 text-amber-700'],
                    ] as [$title, $description, $classes])
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <span class="inline-flex rounded-xl px-3 py-1.5 text-xs font-black {{ $classes }}">{{ $title }}</span>
                            <p class="mt-4 leading-7 text-slate-600">{{ $description }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="px-5 py-20 sm:px-8">
        <div class="mx-auto grid max-w-7xl gap-5 md:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-xl font-black text-slate-950">Credits nachvollziehbar</h3>
                <p class="mt-3 leading-7 text-slate-600">Scanverbrauch wird im Konto sichtbar und kann dem jeweiligen Analysevorgang zugeordnet werden.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-xl font-black text-slate-950">Planwerte zentral gepflegt</h3>
                <p class="mt-3 leading-7 text-slate-600">Limits und Funktionen dieser Seite werden aus den hinterlegten SocialScope-Plaenen geladen.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-xl font-black text-slate-950">Skalierbarer Einstieg</h3>
                <p class="mt-3 leading-7 text-slate-600">Beginne mit deinem aktuellen Profilvolumen und wechsle bei groesseren Netzwerken in den passenden Plan.</p>
            </div>
        </div>

        <div class="mx-auto mt-12 grid max-w-7xl gap-8 rounded-[2rem] bg-slate-950 p-8 text-white md:grid-cols-[1fr_auto] md:items-center sm:p-10">
            <div>
                <p class="text-sm font-black uppercase tracking-[.2em] text-cyan-300">SocialScope testen</p>
                <h2 class="mt-3 text-3xl font-black tracking-tight">Erstelle deinen Workspace und starte mit dem ersten Profil.</h2>
            </div>
            <a href="{{ route('register') }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl bg-white px-6 py-4 font-black text-slate-950 transition hover:bg-cyan-50">
                Account erstellen
            </a>
        </div>
    </section>
</div>
