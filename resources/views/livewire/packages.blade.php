@section('title', 'Pakete')

<div wire:loading.class="cursor-wait" class="bg-[#f7faf8] text-slate-950">
    <section class="relative overflow-hidden bg-slate-950 px-5 py-20 text-white sm:px-8">
        <img
            src="{{ asset('site-images/bg-cover2.jpg') }}"
            alt="Analysearbeitsplatz"
            class="absolute inset-0 h-full w-full object-cover opacity-25"
        >
        <div class="absolute inset-0 bg-slate-950/80"></div>
        <div class="relative mx-auto max-w-7xl">
            <p class="text-sm font-black uppercase tracking-[.22em] text-emerald-300">Pakete</p>
            <div class="mt-5 grid gap-8 lg:grid-cols-[.9fr_1.1fr] lg:items-end">
                <h1 class="text-5xl font-black leading-tight sm:text-6xl">Waehle dein Recherche-Setup.</h1>
                <p class="max-w-3xl text-lg leading-8 text-slate-300">
                    Starte klein, erweitere nach Fallvolumen und Teamgroesse. Alle Pakete enthalten strukturierte Akten, Profilverwaltung und sichere Workflows.
                </p>
            </div>
        </div>
    </section>

    <section class="px-5 py-16 sm:px-8">
        <div class="mx-auto grid max-w-7xl gap-5 lg:grid-cols-3">
            <article class="border border-slate-200 bg-white p-7 shadow-sm">
                <p class="text-sm font-black uppercase tracking-[.18em] text-emerald-700">Starter</p>
                <h2 class="mt-4 text-3xl font-black">49 EUR</h2>
                <p class="mt-1 text-slate-500">pro Monat</p>
                <p class="mt-5 leading-7 text-slate-600">Fuer Einzelpersonen, die wiederkehrende Web- und Social-Recherchen sauber dokumentieren wollen.</p>
                <a href="{{ route('register') }}" class="mt-7 inline-flex w-full items-center justify-center bg-slate-950 px-5 py-4 font-black text-white transition hover:bg-emerald-700">Starten</a>
                <ul class="mt-7 space-y-4 text-sm text-slate-700">
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> 3 aktive Recherche-Akten</li>
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> 150 Scan-Credits monatlich</li>
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> Basis-Monitoring fuer oeffentliche Profile</li>
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> PDF-Export</li>
                </ul>
            </article>

            <article class="relative border-2 border-emerald-500 bg-white p-7 shadow-xl shadow-emerald-900/10">
                <div class="absolute right-5 top-5 bg-emerald-100 px-3 py-1 text-xs font-black uppercase tracking-[.16em] text-emerald-800">Beliebt</div>
                <p class="text-sm font-black uppercase tracking-[.18em] text-emerald-700">Professional</p>
                <h2 class="mt-4 text-3xl font-black">149 EUR</h2>
                <p class="mt-1 text-slate-500">pro Monat</p>
                <p class="mt-5 leading-7 text-slate-600">Fuer aktive Ermittler, Agenturen und Teams mit mehreren parallelen Faellen.</p>
                <a href="{{ route('register') }}" class="mt-7 inline-flex w-full items-center justify-center bg-emerald-500 px-5 py-4 font-black text-slate-950 transition hover:bg-emerald-400">Professional starten</a>
                <ul class="mt-7 space-y-4 text-sm text-slate-700">
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> 25 aktive Recherche-Akten</li>
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> 1.000 Scan-Credits monatlich</li>
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> Netzwerk-Graph und Beziehungsanalyse</li>
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> Monitoring-Intervalle und Aenderungshistorie</li>
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> PDF- und CSV-Export</li>
                </ul>
            </article>

            <article class="border border-slate-200 bg-white p-7 shadow-sm">
                <p class="text-sm font-black uppercase tracking-[.18em] text-emerald-700">Agency</p>
                <h2 class="mt-4 text-3xl font-black">399 EUR</h2>
                <p class="mt-1 text-slate-500">pro Monat</p>
                <p class="mt-5 leading-7 text-slate-600">Fuer Organisationen mit hohem Recherchevolumen, mehreren Bearbeitern und Report-Pflichten.</p>
                <a href="{{ route('register') }}" class="mt-7 inline-flex w-full items-center justify-center bg-slate-950 px-5 py-4 font-black text-white transition hover:bg-emerald-700">Agency anfragen</a>
                <ul class="mt-7 space-y-4 text-sm text-slate-700">
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> 100 aktive Recherche-Akten</li>
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> 4.000 Scan-Credits monatlich</li>
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> Team-Rollen und Falluebergaben</li>
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> Priorisierte Verarbeitung</li>
                    <li class="flex gap-3"><span class="font-black text-emerald-700">✓</span> Individuelle Exportvorlagen</li>
                </ul>
            </article>
        </div>
    </section>

    <section class="bg-white px-5 py-16 sm:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="grid gap-8 lg:grid-cols-[.8fr_1.2fr]">
                <div>
                    <p class="text-sm font-black uppercase tracking-[.2em] text-emerald-700">Vergleich</p>
                    <h2 class="mt-4 text-4xl font-black leading-tight">Alle wichtigen Unterschiede auf einen Blick.</h2>
                </div>
                <div class="overflow-x-auto border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50 text-slate-700">
                            <tr>
                                <th class="px-5 py-4 font-black">Leistung</th>
                                <th class="px-5 py-4 font-black">Starter</th>
                                <th class="px-5 py-4 font-black">Professional</th>
                                <th class="px-5 py-4 font-black">Agency</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            <tr>
                                <td class="px-5 py-4 font-semibold">Recherche-Akten</td>
                                <td class="px-5 py-4">3</td>
                                <td class="px-5 py-4">25</td>
                                <td class="px-5 py-4">100</td>
                            </tr>
                            <tr>
                                <td class="px-5 py-4 font-semibold">Scan-Credits</td>
                                <td class="px-5 py-4">150</td>
                                <td class="px-5 py-4">1.000</td>
                                <td class="px-5 py-4">4.000</td>
                            </tr>
                            <tr>
                                <td class="px-5 py-4 font-semibold">Netzwerk-Graph</td>
                                <td class="px-5 py-4">Basis</td>
                                <td class="px-5 py-4">Voll</td>
                                <td class="px-5 py-4">Voll + Prioritaet</td>
                            </tr>
                            <tr>
                                <td class="px-5 py-4 font-semibold">Exporte</td>
                                <td class="px-5 py-4">PDF</td>
                                <td class="px-5 py-4">PDF, CSV</td>
                                <td class="px-5 py-4">PDF, CSV, Vorlage</td>
                            </tr>
                            <tr>
                                <td class="px-5 py-4 font-semibold">Support</td>
                                <td class="px-5 py-4">E-Mail</td>
                                <td class="px-5 py-4">Priorisiert</td>
                                <td class="px-5 py-4">Onboarding</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <section class="px-5 py-16 sm:px-8">
        <div class="mx-auto grid max-w-7xl gap-5 md:grid-cols-3">
            <div class="border border-slate-200 bg-white p-6">
                <h3 class="text-xl font-black">Credits flexibel erweitern</h3>
                <p class="mt-3 leading-7 text-slate-600">Wenn ein Fall mehr Tiefe braucht, koennen zusaetzliche Scan-Credits nachgebucht werden.</p>
            </div>
            <div class="border border-slate-200 bg-white p-6">
                <h3 class="text-xl font-black">Datenschutz im Fokus</h3>
                <p class="mt-3 leading-7 text-slate-600">Die Workflows sind auf nachvollziehbare Quellen, klare Aktenstruktur und kontrollierte Exporte ausgelegt.</p>
            </div>
            <div class="border border-slate-200 bg-white p-6">
                <h3 class="text-xl font-black">Enterprise moeglich</h3>
                <p class="mt-3 leading-7 text-slate-600">Fuer eigene Limits, dedizierte Infrastruktur oder besondere Compliance-Anforderungen.</p>
            </div>
        </div>

        <div class="mx-auto mt-12 grid max-w-7xl gap-8 bg-slate-950 p-8 text-white md:grid-cols-[1fr_auto] md:items-center">
            <div>
                <p class="text-sm font-black uppercase tracking-[.2em] text-emerald-300">Noch unsicher?</p>
                <h2 class="mt-3 text-3xl font-black">Starte mit Professional und passe dein Volumen nach dem ersten Monat an.</h2>
            </div>
            <a href="{{ route('register') }}" class="inline-flex items-center justify-center bg-emerald-400 px-6 py-4 font-black text-slate-950 transition hover:bg-emerald-300">
                Account erstellen
            </a>
        </div>
    </section>
</div>
