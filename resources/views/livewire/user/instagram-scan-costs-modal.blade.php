<div>
    <x-modal wire:model="showScanCostsModal" maxWidth="2xl">
        <div class="flex max-h-[calc(100vh-2rem)] flex-col overflow-hidden bg-white">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5 sm:py-4">
                <div>
                    <h3 class="text-lg font-bold text-slate-950">Scan-Kosten</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ $displayHandle }}</p>
                </div>
                <button
                    type="button"
                    x-on:click="$dispatch('close')"
                    class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                >
                    Schliessen
                </button>
            </div>

            <div class="overflow-y-auto p-4 sm:p-5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Profilscan</div>
                        <div class="mt-1 text-xl font-bold text-slate-950">{{ number_format($scanCostSummary['profile'], 0, ',', '.') }}</div>
                        <div class="text-xs text-slate-500">Credits ab Minimum</div>
                    </div>
                    <div class="rounded-2xl border border-violet-200 bg-violet-50 px-4 py-3">
                        <div class="text-[10px] font-bold uppercase tracking-wide text-violet-600">Beitraege</div>
                        <div class="mt-1 text-xl font-bold text-violet-950">{{ number_format($scanCostSummary['post'], 0, ',', '.') }}</div>
                        <div class="text-xs text-violet-700">inkl. Profilbasis</div>
                    </div>
                    <div class="rounded-2xl bg-slate-950 px-4 py-3 text-white">
                        <div class="text-[10px] font-bold uppercase tracking-wide text-slate-300">Verfuegbar</div>
                        <div class="mt-1 text-xl font-bold">
                            {{ number_format((int) (($creditWallet?->available_credits ?? 0) + ($creditWallet?->bonus_credits ?? 0)), 0, ',', '.') }}
                        </div>
                        <div class="text-xs text-slate-300">Credits</div>
                    </div>
                </div>

                <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 text-sm leading-6 text-slate-600">
                    Basis {{ number_format($scanCostSummary['base'], 0, ',', '.') }} +
                    {{ number_format($scanCostSummary['per_minute'], 0, ',', '.') }} Credits je angefangener Minute,
                    maximal {{ number_format($scanCostSummary['max_minutes'], 0, ',', '.') }} Minuten.
                    Downloads kosten zusaetzlich {{ number_format($scanCostSummary['media_download'], 0, ',', '.') }} Credits je Datei.
                </div>

                <div class="mt-5 border-t border-slate-200 pt-4">
                    <h4 class="text-sm font-bold text-slate-950">Letzte Kosten fuer {{ $displayHandle }}</h4>
                    <div class="mt-3 space-y-2">
                        @forelse($recentScanTransactions as $transaction)
                            <div class="flex items-center justify-between gap-4 rounded-2xl bg-slate-50 px-3 py-2 text-sm">
                                <div class="min-w-0">
                                    <div class="truncate font-semibold text-slate-800">{{ $transaction->description }}</div>
                                    <div class="text-xs text-slate-500">{{ $transaction->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</div>
                                </div>
                                <div class="shrink-0 font-bold text-rose-700">
                                    {{ number_format(abs((int) $transaction->amount), 0, ',', '.') }} Credits
                                </div>
                            </div>
                        @empty
                            <p class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-500">
                                Fuer dieses Profil wurden noch keine Scan-Kosten gebucht.
                            </p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </x-modal>
</div>
