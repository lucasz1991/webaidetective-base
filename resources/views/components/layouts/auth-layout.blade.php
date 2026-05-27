@php
    $authTitle = trim((string) ($title ?? 'webaiDetective'));
    $authDescription = trim((string) ($description ?? 'Sicherer Zugriff auf deinen gebuchten Monitoring-Service.'));
@endphp

<div class="relative overflow-hidden bg-slate-50">
    <div class="absolute inset-x-0 top-0 h-56 bg-white"></div>
    <img
        src="{{ asset('site-images/bg-cover2.jpg') }}"
        alt=""
        class="pointer-events-none absolute inset-x-0 top-0 h-72 w-full object-cover opacity-70"
    >
    <div class="absolute inset-x-0 top-0 h-72 bg-white/70"></div>

    <div class="relative mx-auto grid min-h-[calc(100vh-4rem)] max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:grid-cols-12 lg:px-8 lg:py-10">
        <section class="flex flex-col justify-between overflow-hidden rounded-lg border border-slate-200 bg-white/90 p-5 shadow-sm backdrop-blur lg:col-span-5 lg:min-h-[640px] lg:p-7">
            <div>
                <a href="{{ Route::has('login') ? route('login') : url('/') }}" wire:navigate class="inline-flex rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    <x-authentication-card-logo />
                </a>

                <div class="mt-10 max-w-xl">
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Kundenbereich</p>
                    <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                        {{ $authTitle }}
                    </h1>
                    <p class="mt-4 text-base leading-7 text-slate-600">
                        {{ $authDescription }}
                    </p>
                </div>
            </div>

            <div class="mt-8 rounded-lg border border-slate-200 bg-slate-950 p-5 text-white shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-200">Gebuchter Service</p>
                <h2 class="mt-2 text-xl font-bold tracking-tight">Professionelles Social Monitoring</h2>
                <p class="mt-3 text-sm leading-6 text-slate-300">
                    webaiDetective ist ein kostenpflichtiger Zugang fuer strukturierte Profilanalyse, Aenderungsverfolgung und nachvollziehbare Hinweise.
                </p>

                <div class="mt-5 space-y-3 text-sm">
                    <div class="flex gap-3">
                        <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-teal-300"></span>
                        <p class="text-slate-200">Freigeschalteter Kundenbereich nach Buchung oder Einladung</p>
                    </div>
                    <div class="flex gap-3">
                        <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-teal-300"></span>
                        <p class="text-slate-200">Klare Arbeitsoberflaeche fuer Monitoring und Auswertung</p>
                    </div>
                    <div class="flex gap-3">
                        <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-teal-300"></span>
                        <p class="text-slate-200">Zugriff nur mit persoenlichem Konto und sicherer Anmeldung</p>
                    </div>
                </div>
            </div>
        </section>

        <main class="flex items-center lg:col-span-7">
            <div class="w-full rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-7 lg:p-8">
                {{ $form ?? $slot }}
            </div>
        </main>
    </div>
</div>
