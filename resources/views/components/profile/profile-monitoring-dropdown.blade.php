@props([
    'trackedPerson',
    'onDetailPage' => false,
])

@php
    $intervalMinutes = max(1, (int) ($trackedPerson->monitoring_interval_minutes ?: 60));
    $lastAnalyzedAt = $trackedPerson->last_instagram_analyzed_at
        ? $trackedPerson->last_instagram_analyzed_at->copy()->timezone(config('app.timezone'))
        : null;
    $initialRemainingSeconds = 0;

    if ($trackedPerson->monitoring_enabled && $lastAnalyzedAt) {
        $nextScanAt = $lastAnalyzedAt->copy()->addMinutes($intervalMinutes);
        $initialRemainingSeconds = max(0, now(config('app.timezone'))->diffInSeconds($nextScanAt, false));
    }

    $intervalBadge = $intervalMinutes >= 1440
        ? floor($intervalMinutes / 1440).'d'
        : ($intervalMinutes >= 60 ? floor($intervalMinutes / 60).'h' : $intervalMinutes.'m');
    $monitoringIconClass = $trackedPerson->monitoring_enabled
        ? 'text-indigo-600 hover:bg-indigo-50'
        : 'text-slate-400 hover:bg-slate-50';
    $elementIdPrefix = $onDetailPage ? 'detail-' : 'list-';
    $monitoringIntervals = [
        15 => '15 Min.',
        30 => '30 Min.',
        60 => '1 Std.',
        120 => '2 Std.',
        360 => '6 Std.',
        720 => '12 Std.',
        1440 => '1 Tag',
        4320 => '3 Tage',
        10080 => '7 Tage',
    ];
@endphp

<div
    x-data="{
        remainingSeconds: {{ $initialRemainingSeconds }},
        countdownTimer: null,
        formatCountdown() {
            const seconds = Math.max(0, Number(this.remainingSeconds || 0));
            if (seconds >= 3600) {
                return Math.floor(seconds / 3600) + 'h';
            }
            if (seconds >= 60) {
                return Math.floor(seconds / 60) + 'm';
            }
            return seconds + 's';
        },
        startCountdown() {
            if (!{{ $trackedPerson->monitoring_enabled ? 'true' : 'false' }}) {
                return;
            }
            this.countdownTimer = setInterval(() => {
                if (this.remainingSeconds > 0) {
                    this.remainingSeconds -= 1;
                }
            }, 1000);
        }
    }"
    x-init="startCountdown()"
    x-on:close-monitoring-dropdown.window="$dispatch('close')"
>
    <x-ui.dropdown.anchor-dropdown
        align="right"
        width="auto"
        :offset="8"
        dropdown-classes=""
        content-classes="w-64 rounded-lg border border-slate-200 bg-white"
    >
        <x-slot name="trigger">
            <button
                type="button"
                x-on:click="$dispatch('monitoring-dropdown-opened')"
                x-bind:aria-expanded="open"
                class="relative inline-flex h-7 w-7 items-center justify-center rounded-full border border-transparent {{ $monitoringIconClass }} transition"
                title="Dauerbeobachtung"
                aria-label="Dauerbeobachtung anzeigen"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 8v5l3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M4 12a8 8 0 1 0 2.34-5.66" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M4 4v4h4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                @if($trackedPerson->monitoring_enabled)
                    <span class="absolute -bottom-1 -right-2 rounded-full border border-white bg-indigo-600 px-1 text-[8px] font-black leading-3 text-white shadow-sm">{{ $intervalBadge }}</span>
                    <span
                        class="absolute -top-1 -left-2 rounded-full border border-white bg-slate-900 px-1.5 text-[8px] font-bold leading-3 text-white shadow-sm"
                        x-text="formatCountdown()"
                    ></span>
                @endif
            </button>
        </x-slot>

        <x-slot name="content">
            <div class="p-3 text-left">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Dauerbeobachtung</div>
                        <div class="mt-1 text-sm font-bold {{ $trackedPerson->monitoring_enabled ? 'text-indigo-700' : 'text-slate-700' }}">
                            {{ $trackedPerson->monitoring_enabled ? 'Aktiv' : 'Inaktiv' }}
                        </div>
                    </div>
                    <x-ui.forms.toggle-button
                        id="monitoring-toggle-{{ $elementIdPrefix }}{{ $trackedPerson->id }}"
                        :checked="(bool) $trackedPerson->monitoring_enabled"
                        :change="'$wire.setMonitoringEnabled('.$trackedPerson->id.', $event.target.checked)'"
                    />
                </div>

                <div class="mt-3">
                    <label for="monitoring-interval-{{ $elementIdPrefix }}{{ $trackedPerson->id }}" class="mb-1 block text-xs font-bold text-slate-600">Takt</label>
                    <select
                        id="monitoring-interval-{{ $elementIdPrefix }}{{ $trackedPerson->id }}"
                        x-on:change.stop="$wire.setMonitoringInterval({{ $trackedPerson->id }}, Number($event.target.value))"
                        class="w-full rounded-md border border-slate-300 bg-white px-2 py-2 text-xs font-semibold text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    >
                        @foreach($monitoringIntervals as $minutes => $label)
                            <option value="{{ $minutes }}" @selected($intervalMinutes === $minutes)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="mt-1 text-[11px] leading-4 text-slate-500">
                        Eine Takt-Aenderung aktiviert die Dauerbeobachtung automatisch.
                    </div>
                </div>

                @if($onDetailPage)
                    <button
                        type="button"
                        x-on:click="$dispatch('close')"
                        wire:click="$set('showSettingsModal', true)"
                        class="mt-3 inline-flex w-full items-center justify-center rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
                    >
                        Weitere Einstellungen
                    </button>
                @else
                    <a
                        href="{{ route('tracked-people.show', $trackedPerson->id) }}"
                        wire:navigate
                        class="mt-3 inline-flex w-full items-center justify-center rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
                    >
                        Weitere Einstellungen
                    </a>
                @endif
            </div>
        </x-slot>
    </x-ui.dropdown.anchor-dropdown>
</div>
