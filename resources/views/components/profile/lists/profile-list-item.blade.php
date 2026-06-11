@props([
    'trackedPerson',
    'selected' => false,
])

@php
    $profileVisibility = $trackedPerson->latestInstagramSnapshot?->profile_visibility ?? 'unknown';
    $statusLevel = $trackedPerson->last_instagram_status_level ?: 'neutral';
    $lastAnalyzedAt = $trackedPerson->last_instagram_analyzed_at
        ? $trackedPerson->last_instagram_analyzed_at->copy()->timezone(config('app.timezone'))
        : null;
    $intervalMinutes = max(1, (int) ($trackedPerson->monitoring_interval_minutes ?: 60));
    $scanAgeMinutes = $lastAnalyzedAt ? $lastAnalyzedAt->diffInMinutes(now(config('app.timezone'))) : null;
    $isStale = $lastAnalyzedAt && $trackedPerson->monitoring_enabled && $scanAgeMinutes > ($intervalMinutes * 1.5);
    $statusLabel = match ($statusLevel) {
        'success' => $isStale ? 'Veraltet' : 'Aktuell',
        'partial' => 'Laeuft',
        'cancelled' => 'Beendet',
        'error' => 'Fehler',
        default => 'Offen',
    };
    $statusIconClass = match (true) {
        $statusLevel === 'error' => 'text-rose-600 hover:bg-rose-50',
        $statusLevel === 'partial' => 'text-amber-600 hover:bg-amber-50',
        $statusLevel === 'cancelled' => 'text-slate-600 hover:bg-slate-100',
        $isStale => 'text-sky-600 hover:bg-sky-50',
        $statusLevel === 'success' => 'text-emerald-600 hover:bg-emerald-50',
        default => 'text-slate-400 hover:bg-slate-50',
    };
    $statusBadgeClass = match (true) {
        $statusLevel === 'error' => 'border-rose-200 bg-rose-50 text-rose-700',
        $statusLevel === 'partial' => 'border-amber-200 bg-amber-50 text-amber-800',
        $statusLevel === 'cancelled' => 'border-slate-300 bg-slate-100 text-slate-700',
        $isStale => 'border-sky-200 bg-sky-50 text-sky-700',
        $statusLevel === 'success' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        default => 'border-slate-200 bg-slate-50 text-slate-500',
    };
    $initial = strtoupper(substr(ltrim($trackedPerson->instagram_username ?: $trackedPerson->display_name ?: 'I', '@'), 0, 1));
@endphp

<article
    x-data="{
        statusOpen: false,
    }"
    x-on:keydown.escape.window="statusOpen = false"
    x-on:monitoring-dropdown-opened="statusOpen = false"
    wire:key="tracked-person-instagram-card-{{ $trackedPerson->id }}"
    wire:click="selectTrackedPerson({{ $trackedPerson->id }})"
    wire:keydown.enter="selectTrackedPerson({{ $trackedPerson->id }})"
    wire:keydown.space.prevent="selectTrackedPerson({{ $trackedPerson->id }})"
    wire:loading.class="scale-[0.99] border-pink-300 bg-pink-50/40 ring-2 ring-pink-200"
    wire:target="selectTrackedPerson({{ $trackedPerson->id }})"
    role="button"
    tabindex="0"
    aria-label="Profil {{ $trackedPerson->display_name }} oeffnen"
    class="group relative cursor-pointer overflow-visible rounded-lg border border-slate-200 bg-white shadow-sm transition focus:outline-none focus:ring-2 focus:ring-pink-300 {{ $selected ? 'border-pink-300 ring-2 ring-pink-200' : 'hover:border-slate-300 hover:shadow-md' }}"
>
    <div
        wire:loading.flex
        wire:target="selectTrackedPerson({{ $trackedPerson->id }})"
        class="absolute inset-0 z-20 hidden items-center justify-center gap-3 rounded-lg bg-white/80 text-sm font-semibold text-slate-900 backdrop-blur-[1px]"
    >
        <span class="h-5 w-5 animate-spin rounded-full border-2 border-pink-200 border-t-pink-600"></span>
        <span>Profil wird geladen...</span>
    </div>

    <div class="absolute right-3 top-3 z-30 flex items-center gap-1" x-on:click.stop>
        <x-profile.profile-monitoring-dropdown :tracked-person="$trackedPerson" />

        <div class="relative">
            <button
                type="button"
                x-on:click="statusOpen = !statusOpen; if (statusOpen) $dispatch('close-monitoring-dropdown')"
                class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-transparent {{ $statusIconClass }} transition"
                title="Scanstatus"
                aria-label="Scanstatus anzeigen"
            >
                @if($statusLevel === 'error')
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 9v4M12 17h.01M10.3 4.3 2.8 17.5A2 2 0 0 0 4.5 20h15a2 2 0 0 0 1.7-2.5L13.7 4.3a2 2 0 0 0-3.4 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                @elseif($statusLevel === 'success' && ! $isStale)
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                @elseif($statusLevel === 'partial')
                    <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M3 12a9 9 0 0 0 9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".45"/>
                    </svg>
                @else
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
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
                    <span class="rounded-md border px-2 py-1 text-[11px] font-bold {{ $statusBadgeClass }}">{{ $statusLevel }}</span>
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

    <div class="grid gap-3 p-3.5 pr-20 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
        <div class="flex min-w-0 items-center gap-3">
            <x-profile.profile-image
                :src="$trackedPerson->profile_image_url"
                :alt="$trackedPerson->display_name"
                :initial="$initial"
                :visibility="$profileVisibility"
                size="md"
            />

            <div class="min-w-0 flex-1">
                <div class="truncate text-base font-bold text-slate-950">{{ $trackedPerson->display_name }}</div>
                <div class="mt-0.5 truncate text-sm font-semibold text-slate-600">
                    {{ $trackedPerson->instagram_username ? '@'.$trackedPerson->instagram_username : 'Instagram-Handle fehlt' }}
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
