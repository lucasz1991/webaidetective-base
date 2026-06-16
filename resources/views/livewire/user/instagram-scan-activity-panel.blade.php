<section
    @if($shouldPoll) wire:poll.4000ms @endif
    class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Scan-Aktivitaet</p>
            <h2 class="mt-1 text-lg font-bold text-slate-950">Instagram-Scans</h2>
        </div>
        @if($shouldPoll)
            <span class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                Live
            </span>
        @endif
    </div>

    @if($statusMessage)
        <div class="mt-4 rounded-2xl border px-4 py-3 text-sm {{ $statusClass }}">
            {{ $statusMessage }}
        </div>
    @endif

    <div class="mt-4 grid gap-3">
        @if($activeScan)
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-950">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-bold">{{ $activeScan['label'] }} laeuft</div>
                        <p class="mt-1 text-xs leading-5 text-emerald-800">
                            Gestartet: {{ $activeScan['started_at']?->diffForHumans() ?: '-' }}
                            @if($activeScan['updated_at'])
                                &middot; Letztes Signal: {{ $activeScan['updated_at']->diffForHumans() }}
                            @endif
                        </p>
                        <p class="mt-1 text-xs font-semibold {{ $activeScan['is_responsive'] ? 'text-emerald-700' : 'text-amber-800' }}">
                            {{ $activeScan['is_responsive'] ? 'Scan reagiert.' : 'Kein frisches Signal. Abbruch ist moeglich.' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="requestStop"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center justify-center rounded-2xl border border-emerald-300 bg-white px-3 py-2 text-xs font-bold text-emerald-800 hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-60"
                        >
                            Beenden und speichern
                        </button>
                        @unless($activeScan['is_responsive'])
                            <button
                                type="button"
                                wire:click="cancelUnresponsive"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center justify-center rounded-2xl border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 disabled:cursor-wait disabled:opacity-60"
                            >
                                Hart abbrechen
                            </button>
                        @endunless
                    </div>
                </div>
                @if($activeScan['graceful_stop_requested'])
                    <div class="mt-3 rounded-xl border border-amber-200 bg-white px-3 py-2 text-xs font-semibold text-amber-900">
                        Stop wurde angefordert. Der Scan speichert den Zwischendstand.
                    </div>
                @endif
            </div>
        @endif

        @if($queuedScans->isNotEmpty())
            <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sky-950">
                <div class="text-sm font-bold">Geplante Queue-Scans</div>
                <div class="mt-2 space-y-2">
                    @foreach($queuedScans as $scan)
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white px-3 py-2 text-xs">
                            <span class="font-semibold">Job #{{ $scan['id'] }} &middot; {{ $scan['queue'] }}</span>
                            <span class="text-sky-700">bereit {{ $scan['available_at']->diffForHumans() }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if($plannedScan)
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-slate-800">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="text-sm font-bold">{{ $plannedScan['label'] }}</div>
                        <p class="mt-1 text-xs leading-5 text-slate-600">
                            Naechster Scan:
                            <span class="font-semibold text-slate-900">{{ $plannedScan['next_scan_at']->format('d.m.Y H:i') }}</span>
                            &middot; Takt: {{ $plannedScan['interval_minutes'] >= 60 ? floor($plannedScan['interval_minutes'] / 60).' Std.' : $plannedScan['interval_minutes'].' Min.' }}
                        </p>
                    </div>
                    @if($plannedScan['is_due'])
                        <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-200">faellig</span>
                    @endif
                </div>
            </div>
        @endif

        @if($resumableScan)
            <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-amber-950">
                <div class="text-sm font-bold">{{ $resumableScan['label'] }} ist wiederaufnehmbar</div>
                <p class="mt-1 text-xs leading-5">{{ $resumableScan['message'] }}</p>
                <p class="mt-1 text-xs font-semibold">{{ number_format($resumableScan['saved_count'], 0, ',', '.') }} Eintraege/Kandidaten sind bereits gespeichert.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        wire:click="resumeSavedInstagramScan('{{ $resumableScan['scan_type'] }}')"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-60"
                    >
                        Fortsetzen
                    </button>
                    <button
                        type="button"
                        wire:click="dismissSavedInstagramScan('{{ $resumableScan['source'] }}', {{ $resumableScan['id'] }})"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center rounded-2xl border border-amber-300 bg-white px-4 py-2 text-xs font-bold text-amber-900 hover:bg-amber-100 disabled:cursor-wait disabled:opacity-60"
                    >
                        Beenden, Daten behalten
                    </button>
                </div>
            </div>
        @endif

        @if(! $activeScan && $queuedScans->isEmpty() && ! $plannedScan && ! $resumableScan)
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                Keine laufenden, wartenden oder wiederaufnehmbaren Scans gefunden.
            </div>
        @endif
    </div>

    @if($recentScans->isNotEmpty())
        <div class="mt-5 border-t border-slate-200 pt-4">
            <h3 class="text-sm font-bold text-slate-950">Letzte Scan-Ergebnisse</h3>
            <div class="mt-3 space-y-2">
                @foreach($recentScans as $scan)
                    <div class="flex flex-wrap items-start justify-between gap-3 rounded-2xl bg-slate-50 px-3 py-2 text-sm">
                        <div class="min-w-0">
                            <div class="font-semibold text-slate-900">{{ $scan['label'] }}</div>
                            @if($scan['message'])
                                <div class="mt-0.5 line-clamp-2 text-xs leading-5 text-slate-600">{{ $scan['message'] }}</div>
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $scan['class'] }}">{{ $scan['level'] }}</span>
                            <span class="text-xs text-slate-500">{{ $scan['date']?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @unless($trackedPerson)
        <div class="mt-4 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs leading-5 text-slate-500">
            Resume und Stop sind nur fuer Instagram-Profile verfuegbar, die als beobachtete Person verknuepft sind.
        </div>
    @endunless
</section>
