@php
    $authTitle = trim((string) ($title ?? 'SocialScope'));
    $authDescription = trim((string) ($description ?? 'Dein Workspace fuer Social Intelligence, Monitoring und Netzwerkanalyse.'));
@endphp

<div class="relative min-h-[calc(100vh-4rem)] overflow-hidden bg-slate-50">
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute -left-32 top-10 h-96 w-96 rounded-full bg-sky-200/45 blur-3xl"></div>
        <div class="absolute right-0 top-1/3 h-[30rem] w-[30rem] rounded-full bg-emerald-200/35 blur-3xl"></div>
        <div class="absolute inset-0 bg-[linear-gradient(rgba(148,163,184,.08)_1px,transparent_1px),linear-gradient(90deg,rgba(148,163,184,.08)_1px,transparent_1px)] bg-[size:48px_48px]"></div>
    </div>

    <div class="relative mx-auto grid min-h-[calc(100vh-4rem)] max-w-7xl gap-8 px-4 py-8 sm:px-6 lg:grid-cols-[.88fr_1.12fr] lg:px-8 lg:py-12">
        <section class="flex flex-col justify-between rounded-[2rem] border border-white/80 bg-white/70 p-6 shadow-xl shadow-slate-200/50 backdrop-blur-xl sm:p-8 lg:p-10">
            <div>
                <a href="{{ route('welcome') }}" wire:navigate class="inline-flex rounded-2xl focus:outline-none focus:ring-4 focus:ring-cyan-100">
                    <x-authentication-card-logo />
                </a>

                <div class="mt-14 max-w-xl">
                    <span class="inline-flex rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1 text-xs font-black uppercase tracking-[.18em] text-cyan-800">
                        Sicherer Workspace
                    </span>
                    <h1 class="mt-5 text-4xl font-black leading-tight tracking-tight text-slate-950 sm:text-5xl">
                        {{ $authTitle }}
                    </h1>
                    <p class="mt-5 text-base leading-8 text-slate-600">
                        {{ $authDescription }}
                    </p>
                </div>
            </div>

            <div class="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                <div class="rounded-2xl border border-sky-100 bg-sky-50/80 p-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-white text-sky-700 shadow-sm">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4 18V9m5 9V5m5 13v-7m5 7V3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <p class="mt-4 font-black text-slate-900">Scans & Monitoring</p>
                    <p class="mt-1 text-sm leading-6 text-slate-600">Profile, Listen, Beitraege und Veraenderungen strukturiert beobachten.</p>
                </div>

                <div class="rounded-2xl border border-emerald-100 bg-emerald-50/80 p-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-white text-emerald-700 shadow-sm">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                            <circle cx="5" cy="6" r="2" stroke="currentColor" stroke-width="2"/>
                            <circle cx="19" cy="7" r="2" stroke="currentColor" stroke-width="2"/>
                            <path d="m7 7.5 2.5 2.5m5-1 2.7-1.2" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </div>
                    <p class="mt-4 font-black text-slate-900">Netzwerk & Copilot</p>
                    <p class="mt-1 text-sm leading-6 text-slate-600">Zusammenhaenge visualisieren und Ergebnisse mit AI schneller einordnen.</p>
                </div>
            </div>
        </section>

        <main class="flex items-center">
            <div class="w-full rounded-[2rem] border border-white/80 bg-white p-6 shadow-2xl shadow-slate-300/40 sm:p-8 lg:p-10">
                {{ $form ?? $slot }}
            </div>
        </main>
    </div>
</div>
