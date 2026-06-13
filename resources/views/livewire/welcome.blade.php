@section('title', 'Social Intelligence und Profil-Monitoring')

<div wire:loading.class="cursor-wait" class="overflow-hidden bg-white text-slate-950">
    <section class="relative border-b border-slate-100 bg-gradient-to-b from-sky-50 via-white to-white">
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute -left-40 top-10 h-[32rem] w-[32rem] rounded-full bg-cyan-200/40 blur-3xl"></div>
            <div class="absolute right-0 top-24 h-[28rem] w-[28rem] rounded-full bg-emerald-200/35 blur-3xl"></div>
            <div class="absolute inset-0 bg-[linear-gradient(rgba(148,163,184,.08)_1px,transparent_1px),linear-gradient(90deg,rgba(148,163,184,.08)_1px,transparent_1px)] bg-[size:56px_56px]"></div>
        </div>

        <div class="relative mx-auto grid max-w-7xl gap-14 px-5 py-20 sm:px-8 lg:grid-cols-[.9fr_1.1fr] lg:items-center lg:py-28">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full border border-cyan-200 bg-white/80 px-4 py-2 text-xs font-black uppercase tracking-[.16em] text-cyan-800 shadow-sm backdrop-blur">
                    <span class="relative flex h-2 w-2">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </span>
                    Social Intelligence Workspace
                </div>

                <h1 class="mt-7 max-w-3xl text-5xl font-black leading-[1.02] tracking-tight text-slate-950 sm:text-6xl lg:text-7xl">
                    Social Signals.
                    <span class="bg-gradient-to-r from-sky-600 via-cyan-600 to-emerald-600 bg-clip-text text-transparent">Klar verbunden.</span>
                </h1>

                <p class="mt-7 max-w-2xl text-lg leading-8 text-slate-600 sm:text-xl">
                    SocialScope verbindet Profilscans, Netzwerk-Map, Monitoring und einen handlungsfaehigen AI-Copilot in einem strukturierten Recherche-Workspace.
                </p>

                <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                    @auth
                        <a href="{{ route('home') }}" class="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-6 py-4 font-black text-white shadow-xl shadow-slate-900/15 transition hover:-translate-y-0.5 hover:bg-cyan-700">
                            Workspace oeffnen
                            <svg class="ml-3 h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </a>
                    @else
                        <a href="{{ route('register') }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-6 py-4 font-black text-white shadow-xl shadow-slate-900/15 transition hover:-translate-y-0.5 hover:bg-cyan-700">
                            Kostenlos starten
                            <svg class="ml-3 h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </a>
                    @endauth
                    <a href="{{ route('packages') }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl border border-slate-300 bg-white/80 px-6 py-4 font-black text-slate-800 shadow-sm backdrop-blur transition hover:border-cyan-300 hover:bg-cyan-50">
                        Pakete vergleichen
                    </a>
                </div>

                <div class="mt-10 flex flex-wrap gap-x-6 gap-y-3 text-sm font-bold text-slate-600">
                    <span class="inline-flex items-center gap-2"><span class="text-emerald-600">✓</span> Mini- und Vollscans</span>
                    <span class="inline-flex items-center gap-2"><span class="text-emerald-600">✓</span> Echte Scanfortschritte</span>
                    <span class="inline-flex items-center gap-2"><span class="text-emerald-600">✓</span> AI-gestuetzte Auswertung</span>
                </div>
            </div>

            <div class="relative">
                <div class="absolute -inset-5 rounded-[2.5rem] bg-gradient-to-br from-cyan-200/50 to-emerald-200/40 blur-2xl"></div>
                <div class="relative overflow-hidden rounded-[2rem] border border-white bg-white/90 p-4 shadow-2xl shadow-slate-300/50 backdrop-blur sm:p-6">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-3">
                            <x-application-mark class="h-10 w-10" />
                            <div>
                                <p class="font-black text-slate-900">Investigation Overview</p>
                                <p class="text-xs text-slate-500">Profil @northstar.media</p>
                            </div>
                        </div>
                        <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-black text-emerald-700 ring-1 ring-emerald-200">Monitoring aktiv</span>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-[1.1fr_.9fr]">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-black uppercase tracking-[.16em] text-slate-400">Network Map</p>
                                    <p class="mt-1 font-black text-slate-900">Direkte und erkannte Beziehungen</p>
                                </div>
                                <span class="rounded-lg bg-white px-2.5 py-1 text-xs font-bold text-slate-500 shadow-sm">86 Knoten</span>
                            </div>

                            <div class="relative mt-4 h-64 overflow-hidden rounded-xl bg-white">
                                <svg class="absolute inset-0 h-full w-full" viewBox="0 0 420 260" fill="none" aria-hidden="true">
                                    <path d="M205 128 86 63M205 128 334 55M205 128 82 203M205 128 338 195M86 63 42 132M334 55 377 126M82 203 42 132M338 195 377 126" stroke="#cbd5e1" stroke-width="2"/>
                                    <path d="M205 128 286 126" stroke="#10b981" stroke-width="4"/>
                                    <path d="M205 128 149 49" stroke="#7c3aed" stroke-width="3"/>
                                </svg>
                                <span class="absolute left-[45%] top-[40%] flex h-16 w-16 items-center justify-center rounded-full bg-slate-950 text-xs font-black text-white shadow-xl ring-8 ring-cyan-100">Focus</span>
                                <span class="absolute left-[14%] top-[17%] h-11 w-11 rounded-full bg-sky-500 ring-4 ring-sky-100"></span>
                                <span class="absolute right-[13%] top-[14%] h-12 w-12 rounded-full bg-emerald-500 ring-4 ring-emerald-100"></span>
                                <span class="absolute bottom-[14%] left-[14%] h-10 w-10 rounded-full bg-amber-400 ring-4 ring-amber-100"></span>
                                <span class="absolute bottom-[16%] right-[12%] h-11 w-11 rounded-full bg-violet-500 ring-4 ring-violet-100"></span>
                                <span class="absolute left-[66%] top-[42%] h-9 w-9 rounded-full bg-rose-400 ring-4 ring-rose-100"></span>
                                <span class="absolute left-[32%] top-[11%] h-8 w-8 rounded-full bg-cyan-400 ring-4 ring-cyan-100"></span>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div class="rounded-2xl border border-cyan-100 bg-cyan-50 p-4">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-black uppercase tracking-[.16em] text-cyan-700">Vollscan</p>
                                    <span class="font-black text-cyan-800">74%</span>
                                </div>
                                <p class="mt-3 text-sm font-black text-slate-900">Gefolgt-Liste wird analysiert</p>
                                <div class="mt-4 h-2 overflow-hidden rounded-full bg-white">
                                    <div class="h-full w-[74%] rounded-full bg-gradient-to-r from-sky-500 to-emerald-500"></div>
                                </div>
                                <p class="mt-2 text-xs text-slate-500">368 von 496 Profilen geladen</p>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                <p class="text-xs font-black uppercase tracking-[.16em] text-slate-400">Copilot Hinweis</p>
                                <p class="mt-3 text-sm leading-6 text-slate-700">Drei neue Kontaktkandidaten verbinden das Fokusprofil mit einem bereits bekannten Cluster.</p>
                                <div class="mt-4 flex -space-x-2">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-sky-100 text-xs font-black text-sky-700 ring-2 ring-white">AM</span>
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-100 text-xs font-black text-emerald-700 ring-2 ring-white">LK</span>
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-violet-100 text-xs font-black text-violet-700 ring-2 ring-white">NS</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="rounded-2xl border border-slate-200 bg-white p-3 text-center shadow-sm">
                                    <p class="text-2xl font-black text-slate-950">12</p>
                                    <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-slate-400">Neue Signale</p>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-white p-3 text-center shadow-sm">
                                    <p class="text-2xl font-black text-slate-950">4</p>
                                    <p class="mt-1 text-[10px] font-bold uppercase tracking-wide text-slate-400">Aenderungen</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="features" class="px-5 py-20 sm:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="mx-auto max-w-3xl text-center">
                <p class="text-sm font-black uppercase tracking-[.2em] text-cyan-700">Ein Workspace statt Einzelloesungen</p>
                <h2 class="mt-4 text-4xl font-black tracking-tight text-slate-950 sm:text-5xl">Alles, was du fuer ein klares Social-Lagebild brauchst.</h2>
                <p class="mt-5 text-lg leading-8 text-slate-600">SocialScope sammelt nicht nur Daten. Es ordnet Profile, Verlauf, Beziehungen und AI-Erkenntnisse in einem nachvollziehbaren Workflow.</p>
            </div>

            <div class="mt-14 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                @foreach([
                    ['bg-sky-50 text-sky-700', 'hover:border-sky-200', 'Profil- und Listenscans', 'Mini- und Vollanalysen erfassen Profilstatus, Follower-/Gefolgt-Listen und Beitraege mit sichtbarem Fortschritt.', 'M4 5h16M4 12h10M4 19h7'],
                    ['bg-emerald-50 text-emerald-700', 'hover:border-emerald-200', 'Interaktive Network Map', 'Direkte, gegenseitige, bekannte und rekonstruierte Beziehungen werden mit eigener Evidenz und Richtung dargestellt.', 'M12 12 5 6m7 6 7-5m-7 5 6 6M5 6v11m14-10v11'],
                    ['bg-violet-50 text-violet-700', 'hover:border-violet-200', 'Investigation Copilot', 'Der AI-Copilot liest Kontext, startet erlaubte Tools, zeigt Scanfortschritt und reagiert auf neue Ergebnisse.', 'M12 3 4 7v6c0 4.5 3.4 7.4 8 8 4.6-.6 8-3.5 8-8V7l-8-4Zm-3 9h6m-3-3v6'],
                    ['bg-amber-50 text-amber-700', 'hover:border-amber-200', 'Vorschlaege & DeepSearch', 'Vorschlagsflaechen und Kontaktkandidaten koennen gezielt untersucht und als Netzwerkhinweise gespeichert werden.', 'M5 12h14M12 5v14M7 7l10 10m0-10L7 17'],
                    ['bg-rose-50 text-rose-700', 'hover:border-rose-200', 'Monitoring & Historie', 'Individuelle Intervalle, Statusmeldungen und gespeicherte Snapshots machen Veraenderungen zeitlich nachvollziehbar.', 'M4 12a8 8 0 1 0 8-8m0 0v8l5 3'],
                    ['bg-cyan-50 text-cyan-700', 'hover:border-cyan-200', 'Profilreferenzen im Chat', 'Erwaehnte Profile erscheinen als visuelle Badges und lassen sich direkt aus der AI-Auswertung heraus oeffnen.', 'M8 7a4 4 0 1 0 8 0M5 21v-2a7 7 0 0 1 14 0v2'],
                ] as [$iconClass, $hoverClass, $title, $description, $path])
                    <article class="group rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-slate-200/60 {{ $hoverClass }}">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl {{ $iconClass }}">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="{{ $path }}" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h3 class="mt-6 text-xl font-black text-slate-950">{{ $title }}</h3>
                        <p class="mt-3 leading-7 text-slate-600">{{ $description }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section id="workflow" class="border-y border-slate-100 bg-slate-50 px-5 py-20 sm:px-8">
        <div class="mx-auto grid max-w-7xl gap-12 lg:grid-cols-[.8fr_1.2fr] lg:items-center">
            <div>
                <p class="text-sm font-black uppercase tracking-[.2em] text-emerald-700">Einfacher Workflow</p>
                <h2 class="mt-4 text-4xl font-black tracking-tight text-slate-950 sm:text-5xl">Vom ersten Handle zur belastbaren Netzwerkansicht.</h2>
                <p class="mt-6 text-lg leading-8 text-slate-600">Du entscheidest, welches Profil relevant ist. SocialScope strukturiert Scans, Fortschritt, Beziehungen und naechste Schritte.</p>
                <a href="{{ route('register') }}" wire:navigate class="mt-8 inline-flex items-center rounded-2xl bg-white px-5 py-3.5 font-black text-slate-900 shadow-sm ring-1 ring-slate-200 transition hover:ring-cyan-300">
                    Workspace einrichten
                    <svg class="ml-2 h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </a>
            </div>

            <div class="grid gap-4">
                @foreach([
                    ['01', 'Profil anlegen', 'Fokusperson, Instagram-Handle und bekannte Profile zentral erfassen.', 'bg-sky-50 text-sky-700'],
                    ['02', 'Passenden Scan starten', 'Mini-, Voll-, Listen-, Beitrags- oder Vorschlagsscan gezielt auswaehlen.', 'bg-cyan-50 text-cyan-700'],
                    ['03', 'Netzwerk verstehen', 'Direkte Beziehungen von gemeinsamen Hinweisen und Vorschlagsverbindungen unterscheiden.', 'bg-emerald-50 text-emerald-700'],
                    ['04', 'Copilot einbeziehen', 'Neue Ergebnisse zusammenfassen, Profile oeffnen und naechste Untersuchungen priorisieren.', 'bg-violet-50 text-violet-700'],
                ] as [$number, $title, $description, $numberClass])
                    <div class="grid grid-cols-[auto_1fr] gap-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl font-black {{ $numberClass }}">{{ $number }}</span>
                        <div>
                            <h3 class="font-black text-slate-950">{{ $title }}</h3>
                            <p class="mt-2 leading-7 text-slate-600">{{ $description }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="px-5 py-20 sm:px-8">
        <div class="mx-auto max-w-7xl overflow-hidden rounded-[2rem] bg-slate-950 px-6 py-12 text-white shadow-2xl shadow-slate-300 sm:px-10 lg:grid lg:grid-cols-[1fr_auto] lg:items-center lg:gap-10">
            <div>
                <p class="text-sm font-black uppercase tracking-[.2em] text-cyan-300">Bereit fuer mehr Durchblick?</p>
                <h2 class="mt-4 max-w-3xl text-3xl font-black tracking-tight sm:text-4xl">Starte deinen SocialScope Workspace und bringe Struktur in Profile, Signale und Netzwerke.</h2>
            </div>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row lg:mt-0 lg:flex-col">
                <a href="{{ route('register') }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl bg-white px-6 py-4 font-black text-slate-950 transition hover:bg-cyan-50">Kostenlos starten</a>
                <a href="{{ route('packages') }}" wire:navigate class="inline-flex items-center justify-center rounded-2xl border border-white/20 px-6 py-4 font-black text-white transition hover:bg-white/10">Pakete ansehen</a>
            </div>
        </div>
    </section>
</div>
