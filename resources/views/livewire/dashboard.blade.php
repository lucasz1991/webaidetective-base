<div class="min-h-screen bg-[#fafafa] pb-16" wire:loading.class="cursor-wait">
    <div class="border-b border-slate-200 bg-white">
        <div class="container mx-auto px-5 py-5">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <div class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-600 shadow-sm">
                        <span class="h-2 w-2 rounded-full bg-gradient-to-r from-amber-400 via-rose-500 to-fuchsia-600"></span>
                        Instagram Monitoring
                    </div>
                    <h1 class="mt-4 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">
                        Instagram-Profile
                    </h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Ueberwache Profilstatus, Kennzahlen, Follower- und Gefolgt-Aenderungen an einem Ort.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Profile</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['with_instagram'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Beobachtet</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['monitored'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Alerts</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['notifications_enabled'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Analysiert</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($stats['analyzed'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <main class="container mx-auto px-5 py-6">
        <livewire:user.tracked-people-manager />
    </main>
</div>
