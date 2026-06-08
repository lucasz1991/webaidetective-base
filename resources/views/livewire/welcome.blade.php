@section('title', 'KI-Recherche und Social-Monitoring')

<div wire:loading.class="cursor-wait" class="bg-[#f7faf8] text-slate-950">
    <section class="relative min-h-[calc(100vh-4rem)] overflow-hidden bg-slate-950">
        <img
            src="{{ asset('site-images/bg-cover2.jpg') }}"
            alt="Digitale Rechercheoberflaeche"
            class="absolute inset-0 h-full w-full object-cover opacity-40"
        >
        <div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(2,6,23,.96),rgba(15,23,42,.76),rgba(15,23,42,.2))]"></div>

        <div class="relative mx-auto grid min-h-[calc(100vh-4rem)] max-w-7xl grid-cols-1 content-center gap-12 px-5 py-16 sm:px-8 lg:grid-cols-[1.02fr_.98fr] lg:py-20">
            <div class="max-w-3xl text-white">
                <div class="mb-6 inline-flex items-center gap-3 border border-white/15 bg-white/10 px-4 py-2 text-sm font-semibold text-emerald-100 backdrop-blur">
                    <span class="h-2 w-2 bg-emerald-300"></span>
                    Recherche, Monitoring und Beweissicherung in einem Workspace
                </div>

                <h1 class="text-5xl font-black leading-[1.02] tracking-normal sm:text-6xl lg:text-7xl">
                    webaiDetective
                </h1>
                <p class="mt-7 max-w-2xl text-lg leading-8 text-slate-200 sm:text-xl">
                    Finde relevante Web- und Social-Signale schneller, verknuepfe Profile nachvollziehbar und halte Veraenderungen automatisch im Blick.
                </p>

                <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                    @auth
                        <a href="{{ route('home') }}" class="inline-flex items-center justify-center bg-emerald-400 px-6 py-4 text-base font-bold text-slate-950 transition hover:bg-emerald-300">
                            Zum Dashboard
                            <svg class="ml-3 h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </a>
                    @else
                        <a href="{{ route('register') }}" class="inline-flex items-center justify-center bg-emerald-400 px-6 py-4 text-base font-bold text-slate-950 transition hover:bg-emerald-300">
                            Kostenlos starten
                            <svg class="ml-3 h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </a>
                    @endauth
                    <a href="{{ route('packages') }}" class="inline-flex items-center justify-center border border-white/25 bg-white/10 px-6 py-4 text-base font-bold text-white backdrop-blur transition hover:bg-white/20">
                        Pakete ansehen
                    </a>
                </div>

                <dl class="mt-12 grid max-w-2xl grid-cols-3 gap-4 border-y border-white/10 py-6">
                    <div>
                        <dt class="text-3xl font-black text-white">24/7</dt>
                        <dd class="mt-1 text-sm text-slate-300">Monitoring</dd>
                    </div>
                    <div>
                        <dt class="text-3xl font-black text-white">Graph</dt>
                        <dd class="mt-1 text-sm text-slate-300">Netzwerke</dd>
                    </div>
                    <div>
                        <dt class="text-3xl font-black text-white">KI</dt>
                        <dd class="mt-1 text-sm text-slate-300">Analyse</dd>
                    </div>
                </dl>
            </div>

            <div class="relative hidden lg:block">
                <div class="absolute -right-8 top-8 h-56 w-56 border border-amber-300/30"></div>
                <div class="relative border border-white/15 bg-slate-900/80 p-5 shadow-2xl shadow-slate-950/50 backdrop-blur">
                    <div class="flex items-center justify-between border-b border-white/10 pb-4">
                        <div>
                            <p class="text-sm font-bold text-white">Live Investigation</p>
                            <p class="text-xs text-slate-400">Fallakte #WAD-2048</p>
                        </div>
                        <span class="bg-emerald-400/15 px-3 py-1 text-xs font-bold text-emerald-200">Aktiv</span>
                    </div>

                    <div class="mt-5 grid grid-cols-[.8fr_1.2fr] gap-4">
                        <div class="space-y-3">
                            <div class="border border-white/10 bg-white/5 p-4">
                                <p class="text-xs uppercase tracking-[.18em] text-slate-400">Risiko</p>
                                <p class="mt-3 text-4xl font-black text-amber-300">72</p>
                                <div class="mt-4 h-2 bg-slate-700">
                                    <div class="h-2 w-[72%] bg-amber-300"></div>
                                </div>
                            </div>
                            <div class="border border-white/10 bg-white/5 p-4">
                                <p class="text-xs uppercase tracking-[.18em] text-slate-400">Neue Signale</p>
                                <p class="mt-3 text-4xl font-black text-white">18</p>
                                <p class="mt-2 text-xs text-emerald-200">seit dem letzten Scan</p>
                            </div>
                        </div>

                        <div class="border border-white/10 bg-white/5 p-4">
                            <div class="mb-4 flex items-center justify-between">
                                <p class="text-sm font-bold text-white">Profil-Netzwerk</p>
                                <p class="text-xs text-slate-400">43 Knoten</p>
                            </div>
                            <div class="relative h-64 overflow-hidden bg-slate-950/70">
                                <div class="absolute left-1/2 top-1/2 h-24 w-24 -translate-x-1/2 -translate-y-1/2 border border-emerald-300/60"></div>
                                <div class="absolute left-[42%] top-[43%] h-14 w-14 bg-emerald-300 shadow-lg shadow-emerald-400/30"></div>
                                <div class="absolute left-[18%] top-[24%] h-9 w-9 bg-sky-300"></div>
                                <div class="absolute right-[18%] top-[18%] h-11 w-11 bg-amber-300"></div>
                                <div class="absolute bottom-[18%] left-[24%] h-10 w-10 bg-rose-300"></div>
                                <div class="absolute bottom-[22%] right-[22%] h-8 w-8 bg-white"></div>
                                <svg class="absolute inset-0 h-full w-full" viewBox="0 0 320 256" fill="none" aria-hidden="true">
                                    <path d="M142 110 76 62M174 112 246 52M153 142 92 192M184 144 238 186" stroke="rgba(255,255,255,.35)" stroke-width="2"/>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-3">
                        <div class="bg-white p-3 text-slate-950">
                            <p class="text-xs font-bold text-slate-500">Quelle</p>
                            <p class="mt-1 font-black">Instagram</p>
                        </div>
                        <div class="bg-white p-3 text-slate-950">
                            <p class="text-xs font-bold text-slate-500">Status</p>
                            <p class="mt-1 font-black">Verifiziert</p>
                        </div>
                        <div class="bg-white p-3 text-slate-950">
                            <p class="text-xs font-bold text-slate-500">Export</p>
                            <p class="mt-1 font-black">PDF/CSV</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-white px-5 py-16 sm:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="grid gap-8 lg:grid-cols-[.8fr_1.2fr] lg:items-end">
                <div>
                    <p class="text-sm font-black uppercase tracking-[.2em] text-emerald-700">Warum webaiDetective</p>
                    <h2 class="mt-4 text-4xl font-black leading-tight text-slate-950 sm:text-5xl">Aus verstreuten Online-Spuren wird ein klares Lagebild.</h2>
                </div>
                <p class="max-w-3xl text-lg leading-8 text-slate-600">
                    Statt Screenshots, Tabellen und manuellem Nachhalten arbeitest du in einem strukturierten Workflow: Personen anlegen, Profile scannen, Beziehungen sichtbar machen, Aenderungen dokumentieren.
                </p>
            </div>

            <div class="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                <div class="border border-slate-200 bg-[#f8fbfa] p-6">
                    <div class="mb-6 flex h-11 w-11 items-center justify-center bg-emerald-100 text-emerald-800">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5h16M4 12h10M4 19h7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </div>
                    <h3 class="text-xl font-black text-slate-950">Recherche-Akten</h3>
                    <p class="mt-3 leading-7 text-slate-600">Alle Personen, Quellen, Treffer und Notizen landen sauber in einer Fallstruktur.</p>
                </div>
                <div class="border border-slate-200 bg-[#f8fbfa] p-6">
                    <div class="mb-6 flex h-11 w-11 items-center justify-center bg-sky-100 text-sky-800">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 8v8m-4-4h8M4 12a8 8 0 1 0 16 0 8 8 0 0 0-16 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </div>
                    <h3 class="text-xl font-black text-slate-950">Profil-Scans</h3>
                    <p class="mt-3 leading-7 text-slate-600">Oeffentliche Signale, Medien, Bio-Aenderungen und Verknuepfungen werden wieder auffindbar.</p>
                </div>
                <div class="border border-slate-200 bg-[#f8fbfa] p-6">
                    <div class="mb-6 flex h-11 w-11 items-center justify-center bg-amber-100 text-amber-800">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 18 18 6M8 6h10v10M5 7h2m-2 5h2m-2 5h2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <h3 class="text-xl font-black text-slate-950">Netzwerk-Graph</h3>
                    <p class="mt-3 leading-7 text-slate-600">Beziehungen zwischen Profilen, Kontakten und Hinweisen werden visuell nachvollziehbar.</p>
                </div>
                <div class="border border-slate-200 bg-[#f8fbfa] p-6">
                    <div class="mb-6 flex h-11 w-11 items-center justify-center bg-rose-100 text-rose-800">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 3h7l3 3v15H7V3Zm7 0v4h4M10 12h5M10 16h5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <h3 class="text-xl font-black text-slate-950">Reports</h3>
                    <p class="mt-3 leading-7 text-slate-600">Exportiere Ergebnisse mit Zeitpunkten, Quellenbezug und klarer Zusammenfassung.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="workflow" class="bg-slate-950 px-5 py-16 text-white sm:px-8">
        <div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-[.95fr_1.05fr] lg:items-center">
            <div>
                <p class="text-sm font-black uppercase tracking-[.2em] text-emerald-300">Workflow</p>
                <h2 class="mt-4 text-4xl font-black leading-tight sm:text-5xl">Vom ersten Hinweis bis zum belastbaren Report.</h2>
                <p class="mt-6 text-lg leading-8 text-slate-300">
                    webaiDetective ist fuer wiederkehrende Ermittlungsarbeit gebaut: weniger Copy-Paste, mehr Struktur, schnellere Entscheidungen.
                </p>
            </div>

            <div class="grid gap-4">
                <div class="grid grid-cols-[auto_1fr] gap-5 border border-white/10 bg-white/5 p-5">
                    <span class="flex h-10 w-10 items-center justify-center bg-emerald-300 font-black text-slate-950">1</span>
                    <div>
                        <h3 class="font-black">Zielperson oder Profil anlegen</h3>
                        <p class="mt-2 text-slate-300">Basisdaten, bekannte Accounts und Rechercheziele werden zentral gepflegt.</p>
                    </div>
                </div>
                <div class="grid grid-cols-[auto_1fr] gap-5 border border-white/10 bg-white/5 p-5">
                    <span class="flex h-10 w-10 items-center justify-center bg-sky-300 font-black text-slate-950">2</span>
                    <div>
                        <h3 class="font-black">Scans starten und beobachten</h3>
                        <p class="mt-2 text-slate-300">Neue Funde, Medien und Beziehungen werden chronologisch gespeichert.</p>
                    </div>
                </div>
                <div class="grid grid-cols-[auto_1fr] gap-5 border border-white/10 bg-white/5 p-5">
                    <span class="flex h-10 w-10 items-center justify-center bg-amber-300 font-black text-slate-950">3</span>
                    <div>
                        <h3 class="font-black">Zusammenhaenge pruefen</h3>
                        <p class="mt-2 text-slate-300">Netzwerkansichten und KI-Zusammenfassungen machen relevante Muster schneller sichtbar.</p>
                    </div>
                </div>
                <div class="grid grid-cols-[auto_1fr] gap-5 border border-white/10 bg-white/5 p-5">
                    <span class="flex h-10 w-10 items-center justify-center bg-rose-300 font-black text-slate-950">4</span>
                    <div>
                        <h3 class="font-black">Ergebnis sichern</h3>
                        <p class="mt-2 text-slate-300">Reports und Exporte liefern eine nachvollziehbare Grundlage fuer naechste Schritte.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-white px-5 py-16 sm:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="grid gap-5 md:grid-cols-3">
                <div class="border border-slate-200 p-7">
                    <p class="text-sm font-black uppercase tracking-[.18em] text-slate-500">Teams</p>
                    <h3 class="mt-3 text-2xl font-black text-slate-950">Private Ermittlungen</h3>
                    <p class="mt-4 leading-7 text-slate-600">Strukturierte Recherche fuer Mandate, Due-Diligence, Betrugsfaelle und Identitaetspruefungen.</p>
                </div>
                <div class="border border-slate-200 p-7">
                    <p class="text-sm font-black uppercase tracking-[.18em] text-slate-500">Creator & Marken</p>
                    <h3 class="mt-3 text-2xl font-black text-slate-950">Monitoring</h3>
                    <p class="mt-4 leading-7 text-slate-600">Aenderungen an oeffentlichen Profilen beobachten und relevante Signale priorisieren.</p>
                </div>
                <div class="border border-slate-200 p-7">
                    <p class="text-sm font-black uppercase tracking-[.18em] text-slate-500">Agenturen</p>
                    <h3 class="mt-3 text-2xl font-black text-slate-950">OSINT Workflows</h3>
                    <p class="mt-4 leading-7 text-slate-600">Mehrere Faelle parallel fuehren, Quellen sauber trennen und Ergebnisse exportieren.</p>
                </div>
            </div>

            <div class="mt-12 grid gap-8 bg-[#10251f] p-8 text-white md:grid-cols-[1fr_auto] md:items-center">
                <div>
                    <p class="text-sm font-black uppercase tracking-[.2em] text-emerald-300">Bereit fuer den ersten Fall?</p>
                    <h2 class="mt-3 text-3xl font-black">Starte mit einem Paket, das zu deinem Recherchevolumen passt.</h2>
                </div>
                <a href="{{ route('packages') }}" class="inline-flex items-center justify-center bg-white px-6 py-4 font-black text-slate-950 transition hover:bg-emerald-100">
                    Pakete vergleichen
                </a>
            </div>
        </div>
    </section>
</div>
