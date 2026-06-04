<div class="min-h-screen bg-slate-50 pb-16" wire:loading.class="cursor-wait">
    <div class="border-b border-slate-200 bg-white">
        <div class="container mx-auto px-5 py-6">
            <h1 class="text-2xl font-bold tracking-tight text-slate-950">Admin-Konfiguration</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                Verwalte SaaS-Pakete, Limits und Credit-Kosten fuer die Base-Installation.
            </p>
        </div>
    </div>

    <main class="container mx-auto px-5 py-6">
        @livewire('admin.config.billing-settings')
    </main>
</div>
