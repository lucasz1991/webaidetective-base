@props([
    'trackedPerson',
    'selected' => false,
])

@php
    $profileVisibility = $trackedPerson->latestInstagramSnapshot?->profile_visibility ?? 'unknown';
    $profileVisibilityLabel = match ($profileVisibility) {
        'public' => 'Oeffentlich',
        'private' => 'Privat',
        default => 'Unbekannt',
    };
    $profileVisibilityClass = match ($profileVisibility) {
        'public' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'private' => 'bg-slate-100 text-slate-700 ring-slate-200',
        default => 'bg-amber-50 text-amber-800 ring-amber-200',
    };

    $statusLevel = $trackedPerson->last_instagram_status_level ?: 'neutral';
    $lastAnalyzedAt = $trackedPerson->last_instagram_analyzed_at
        ? $trackedPerson->last_instagram_analyzed_at->copy()->timezone(config('app.timezone'))
        : null;
    $intervalMinutes = max(1, (int) ($trackedPerson->monitoring_interval_minutes ?: 60));
    $intervalBadge = $intervalMinutes >= 1440
        ? floor($intervalMinutes / 1440).'d'
        : ($intervalMinutes >= 60 ? floor($intervalMinutes / 60).'h' : $intervalMinutes.'m');
    $scanAgeMinutes = $lastAnalyzedAt ? $lastAnalyzedAt->diffInMinutes(now(config('app.timezone'))) : null;
    $isStale = $lastAnalyzedAt && $trackedPerson->monitoring_enabled && $scanAgeMinutes > ($intervalMinutes * 1.5);
    $statusLabel = match ($statusLevel) {
        'success' => $isStale ? 'Veraltet' : 'Aktuell',
        'partial' => 'Laeuft',
        'error' => 'Fehler',
        default => 'Offen',
    };
    $statusIconClass = match (true) {
        $statusLevel === 'error' => 'border-rose-200 bg-rose-50 text-rose-700',
        $statusLevel === 'partial' => 'border-amber-200 bg-amber-50 text-amber-800',
        $isStale => 'border-sky-200 bg-sky-50 text-sky-700',
        $statusLevel === 'success' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        default => 'border-slate-200 bg-slate-50 text-slate-500',
    };
    $monitoringIconClass = $trackedPerson->monitoring_enabled
        ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
        : 'border-slate-200 bg-slate-50 text-slate-500';
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
    $initial = strtoupper(substr(ltrim($trackedPerson->instagram_username ?: $trackedPerson->display_name ?: 'I', '@'), 0, 1));
@endphp

<article
    x-data="{ monitoringOpen: false, statusOpen: false }"
    x-on:keydown.escape.window="monitoringOpen = false; statusOpen = false"
    wire:key="tracked-person-instagram-card-{{ $trackedPerson->id }}"
    wire:click="selectTrackedPerson({{ $trackedPerson->id }})"
    wire:keydown.enter="selectTrackedPerson({{ $trackedPerson->id }})"
    wire:keydown.space.prevent="selectTrackedPerson({{ $trackedPerson->id }})"
    wire:loading.class="scale-[0.99] border-pink-300 bg-pink-50/40 ring-2 ring-pink-200"
    wire:target="selectTrackedPerson({{ $trackedPerson->id }})"
    role="button"
    tabindex="0"
    aria-label="Profil {{ $trackedPerson->display_name }} oeffnen"
    class="group relative cursor-pointer overflow-visible rounded-lg border border-slate-200 bg-white shadow-sm transition focus:outline-none focus:ring-2 focus:ring-pink-300 {{ $selected ? 'border-pink-300 ring-2 ring-pink-200' : 'hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md' }}"
>
    <div class="absolute inset-y-0 left-0 w-1 rounded-l-lg bg-gradient-to-b from-amber-400 via-rose-500 to-fuchsia-600"></div>

    <div
        wire:loading.flex
        wire:target="selectTrackedPerson({{ $trackedPerson->id }})"
        class="absolute inset-0 z-20 hidden items-center justify-center gap-3 rounded-lg bg-white/80 text-sm font-semibold text-slate-900 backdrop-blur-[1px]"
    >
        <span class="h-5 w-5 animate-spin rounded-full border-2 border-pink-200 border-t-pink-600"></span>
        <span>Profil wird geladen...</span>
    </div>

    <div class="absolute right-3 top-3 z-30 flex items-center gap-1.5" x-on:click.stop>
        <div class="relative">
            <button
                type="button"
                x-on:click="monitoringOpen = !monitoringOpen; statusOpen = false"
                class="relative inline-flex h-8 w-8 items-center justify-center rounded-full border {{ $monitoringIconClass }} transition hover:shadow-sm"
                title="Dauerbeobachtung"
                aria-label="Dauerbeobachtung anzeigen"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 8v5l3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M4 12a8 8 0 1 0 2.34-5.66" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M4 4v4h4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                @if($trackedPerson->monitoring_enabled)
                    <span class="absolute -bottom-1.5 -right-2 rounded-full border border-white bg-indigo-600 px-1 text-[9px] font-black leading-4 text-white shadow-sm">{{ $intervalBadge }}</span>
                @endif
            </button>
            <div
                x-show="monitoringOpen"
                x-cloak
                x-transition
                x-on:click.outside="monitoringOpen = false"
                class="absolute right-0 top-10 z-40 w-64 rounded-lg border border-slate-200 bg-white p-3 text-left shadow-xl"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Dauerbeobachtung</div>
                        <div class="mt-1 text-sm font-bold {{ $trackedPerson->monitoring_enabled ? 'text-indigo-700' : 'text-slate-700' }}">
                            {{ $trackedPerson->monitoring_enabled ? 'Aktiv' : 'Inaktiv' }}
                        </div>
                    </div>
                    <x-ui.forms.toggle-button
                        id="monitoring-toggle-{{ $trackedPerson->id }}"
                        :checked="(bool) $trackedPerson->monitoring_enabled"
                        :change="'$wire.setMonitoringEnabled('.$trackedPerson->id.', $event.target.checked)'"
                    />
                </div>

                <div class="mt-3">
                    <label for="monitoring-interval-{{ $trackedPerson->id }}" class="mb-1 block text-xs font-bold text-slate-600">Takt</label>
                    <select
                        id="monitoring-interval-{{ $trackedPerson->id }}"
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

                <a
                    href="{{ route('tracked-people.show', $trackedPerson->id) }}"
                    wire:navigate
                    class="mt-3 inline-flex w-full items-center justify-center rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
                >
                    Weitere Einstellungen
                </a>
            </div>
        </div>

        <div class="relative">
            <button
                type="button"
                x-on:click="statusOpen = !statusOpen; monitoringOpen = false"
                class="inline-flex h-8 w-8 items-center justify-center rounded-full border {{ $statusIconClass }} transition hover:shadow-sm"
                title="Scanstatus"
                aria-label="Scanstatus anzeigen"
            >
                @if($statusLevel === 'error')
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 9v4M12 17h.01M10.3 4.3 2.8 17.5A2 2 0 0 0 4.5 20h15a2 2 0 0 0 1.7-2.5L13.7 4.3a2 2 0 0 0-3.4 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                @elseif($statusLevel === 'success' && ! $isStale)
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                @elseif($statusLevel === 'partial')
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M3 12a9 9 0 0 0 9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".45"/>
                    </svg>
                @else
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/>
                    </svg>
                @endif
            </button>
            <div
                x-show="statusOpen"
                x-cloak
                x-transition
                x-on:click.outside="statusOpen = false"
                class="absolute right-0 top-10 z-40 w-72 rounded-lg border border-slate-200 bg-white p-3 text-left shadow-xl"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Scanstatus</div>
                        <div class="mt-2 text-sm font-bold text-slate-950">{{ $statusLabel }}</div>
                    </div>
                    <span class="rounded-md border px-2 py-1 text-[11px] font-bold {{ $statusIconClass }}">{{ $statusLevel }}</span>
                </div>
                <div class="mt-2 text-xs leading-5 text-slate-600">
                    @if($lastAnalyzedAt)
                        Zuletzt gescannt: <span title="{{ $lastAnalyzedAt->format('d.m.Y H:i') }}">{{ $lastAnalyzedAt->diffForHumans() }}</span>.
                    @else
                        Noch kein Scan vorhanden.
                    @endif
                </div>
                @if($trackedPerson->last_instagram_status_message)
                    <div class="mt-2 max-h-24 overflow-y-auto rounded-md bg-slate-50 p-2 text-xs leading-5 text-slate-700">
                        {{ $trackedPerson->last_instagram_status_message }}
                    </div>
                @endif
                <button
                    type="button"
                    wire:click.stop="selectTrackedPerson({{ $trackedPerson->id }})"
                    class="mt-3 inline-flex w-full items-center justify-center rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800"
                >
                    Statusdetails anzeigen
                </button>
            </div>
        </div>
    </div>

    <div class="grid gap-3 p-4 pl-5 pr-24 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
        <div class="flex min-w-0 items-center gap-3">
            <div class="h-12 w-12 shrink-0 overflow-hidden rounded-full border border-slate-200 bg-slate-100 shadow-sm">
                @if($trackedPerson->profile_image_url)
                    <img src="{{ $trackedPerson->profile_image_url }}" alt="{{ $trackedPerson->display_name }}" class="h-full w-full object-cover">
                @else
                    <div class="flex h-full w-full items-center justify-center text-sm font-bold text-slate-500">{{ $initial }}</div>
                @endif
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex min-w-0 items-center gap-2">
                    <div class="truncate text-base font-bold text-slate-950">{{ $trackedPerson->display_name }}</div>
                    <span class="shrink-0 rounded-md px-2 py-0.5 text-[10px] font-bold ring-1 {{ $profileVisibilityClass }}">{{ $profileVisibilityLabel }}</span>
                </div>
                <div class="mt-0.5 truncate text-sm font-semibold text-slate-600">
                    {{ $trackedPerson->instagram_username ? '@'.$trackedPerson->instagram_username : 'Instagram-Handle fehlt' }}
                </div>
                <div class="mt-1 truncate text-xs text-slate-500">
                    {{ $lastAnalyzedAt ? 'Scan '.$lastAnalyzedAt->diffForHumans() : 'Noch nicht analysiert' }}
                </div>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-1.5 text-center sm:w-52">
            <div class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5">
                <div class="text-sm font-black text-slate-950">{{ $trackedPerson->instagram_posts_count !== null ? number_format($trackedPerson->instagram_posts_count) : '-' }}</div>
                <div class="text-[10px] font-medium text-slate-500">Posts</div>
            </div>
            <div class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5">
                <div class="text-sm font-black text-slate-950">{{ $trackedPerson->instagram_followers_count !== null ? number_format($trackedPerson->instagram_followers_count) : '-' }}</div>
                <div class="text-[10px] font-medium text-slate-500">Follower</div>
            </div>
            <div class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5">
                <div class="text-sm font-black text-slate-950">{{ $trackedPerson->instagram_following_count !== null ? number_format($trackedPerson->instagram_following_count) : '-' }}</div>
                <div class="text-[10px] font-medium text-slate-500">Folgt</div>
            </div>
        </div>
    </div>
</article>
