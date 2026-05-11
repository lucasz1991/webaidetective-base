<div class="w-full bg-slate-100 pb-16 pt-8" wire:loading.class="cursor-wait">
    <div class="container mx-auto space-y-6 px-5">
        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-slate-900">
                        Personen-Dashboard
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600">
                        Willkommen {{ $userData->name ?? 'zurueck' }}. Hier werden nur noch gespeicherte Personen, ihre Social-Media-Daten, Analysen und Historien verwaltet.
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gesamt</div>
                    <div class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($stats['total']) }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Mit Instagram</div>
                    <div class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($stats['with_instagram']) }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Beobachtet</div>
                    <div class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($stats['monitored']) }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Benachrichtigungen</div>
                    <div class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($stats['notifications_enabled']) }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Analysiert</div>
                    <div class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($stats['analyzed']) }}</div>
                </div>
            </div>
        </section>

        <livewire:user.tracked-people-manager />
    </div>
</div>
