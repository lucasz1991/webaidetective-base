@props([
    'item',
    'tone' => 'slate',
    'meta' => null,
    'statusLabel' => null,
    'statusTone' => 'slate',
    'showVisibility' => true,
    'actionLabel' => null,
    'actionUrl' => null,
    'showAction' => true,
    'navigate' => false,
    'compact' => false,
])

@php
    $userId = (int) \Illuminate\Support\Facades\Auth::id();
    $related = is_object($item) && method_exists($item, 'relationLoaded') && $item->relationLoaded('relatedInstagramProfile')
        ? $item->relatedInstagramProfile
        : null;

    $profile = $item instanceof \App\Models\InstagramProfile ? $item : $related;
    $raw = is_object($item) && isset($item->raw_item) && is_array($item->raw_item) ? $item->raw_item : [];
    $username = ltrim(trim((string) (
        data_get($raw, 'username')
        ?? data_get($item, 'username')
        ?? data_get($item, 'username_snapshot')
        ?? $profile?->username
        ?? ''
    )), '@');
    $displayName = trim((string) (
        data_get($raw, 'displayName')
        ?? data_get($item, 'displayName')
        ?? data_get($item, 'display_name_snapshot')
        ?? $profile?->display_name
        ?? $profile?->full_name
        ?? ''
    ));
    $meta = $meta ?: data_get($item, 'meta');
    $statusLabel = $statusLabel ?: data_get($item, 'statusLabel');
    $statusTone = data_get($item, 'statusTone', $statusTone);
    $profileId = (int) (data_get($item, 'instagramProfileId') ?? $profile?->id ?? 0);
    $profileUrl = $actionUrl ?: (
        data_get($raw, 'profileUrl')
        ?? data_get($item, 'profileUrl')
        ?? data_get($item, 'profile_url_snapshot')
        ?? $profile?->profile_url
        ?? ($username !== '' ? 'https://www.instagram.com/'.$username.'/' : null)
    );
    $detailUrl = $profileId > 0 ? route('instagram-profiles.show', $profileId) : null;
    $profileImagePath = data_get($item, 'profileImagePath') ?? data_get($item, 'profile_image_path') ?? $profile?->profile_image_path;
    $imageUrl = filled($profileImagePath) && \Illuminate\Support\Facades\Storage::disk('public')->exists($profileImagePath)
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($profileImagePath)
        : null;
    $visibility = strtolower((string) (
        data_get($raw, 'profileVisibility')
        ?? data_get($item, 'profileVisibility')
        ?? $profile?->profile_visibility
        ?? ''
    ));

    if (! in_array($visibility, ['public', 'private'], true)) {
        $isPrivate = data_get($raw, 'isPrivate', data_get($item, 'isPrivate', $profile?->is_private));
        $visibility = $isPrivate === true ? 'private' : ($isPrivate === false ? 'public' : 'unknown');
    }

    $toneClass = match ($tone) {
        'emerald' => 'border-emerald-100 bg-white',
        'rose' => 'border-rose-100 bg-white',
        'sky' => 'border-sky-100 bg-white',
        default => 'border-slate-200 bg-slate-50',
    };
    $avatarToneClass = match ($tone) {
        'emerald' => 'bg-emerald-50 text-emerald-700',
        'rose' => 'bg-rose-50 text-rose-700',
        'sky' => 'bg-sky-50 text-sky-700',
        default => 'bg-slate-100 text-slate-600',
    };
    $avatarRingClass = $visibility === 'public'
        ? 'border-emerald-300 ring-2 ring-emerald-200'
        : 'border-slate-200';
    $statusClass = match ($statusTone) {
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
    $postsCount = data_get($raw, 'postsCount')
        ?? data_get($item, 'postsCount')
        ?? data_get($item, 'posts_count')
        ?? $profile?->posts_count;
    $followersCount = data_get($raw, 'followersCount')
        ?? data_get($item, 'followersCount')
        ?? data_get($item, 'followers_count')
        ?? $profile?->followers_count;
    $followingCount = data_get($raw, 'followingCount')
        ?? data_get($item, 'followingCount')
        ?? data_get($item, 'following_count')
        ?? $profile?->following_count;
    $listScanStatuses = data_get($raw, 'listScanStatuses') ?? data_get($item, 'listScanStatuses') ?? [];

    if (! is_array($listScanStatuses) || $listScanStatuses === []) {
        $listScanStatuses = $profileId > 0
            ? \App\Support\InstagramListScanStatus::forProfile($profileId, $userId)
            : \App\Support\InstagramListScanStatus::defaultStatuses();
    }

    $followersListScanState = \App\Support\InstagramListScanStatus::normalizeState(
        data_get($raw, 'followersListScanState')
            ?? data_get($item, 'followersListScanState')
            ?? data_get($listScanStatuses, 'followers.state')
    );
    $followingListScanState = \App\Support\InstagramListScanStatus::normalizeState(
        data_get($raw, 'followingListScanState')
            ?? data_get($item, 'followingListScanState')
            ?? data_get($listScanStatuses, 'following.state')
    );
    $followersStatusState = \App\Support\InstagramListScanStatus::normalizeState(data_get($listScanStatuses, 'followers.state'));
    $followingStatusState = \App\Support\InstagramListScanStatus::normalizeState(data_get($listScanStatuses, 'following.state'));
    $scanStateTitle = fn (string $listType, string $state): string => match ($state) {
        'complete' => ($listType === 'followers' ? 'Followerliste' : 'Gefolgt-Liste').' vollstaendig gescannt',
        'partial' => ($listType === 'followers' ? 'Followerliste' : 'Gefolgt-Liste').' teilweise gescannt',
        default => ($listType === 'followers' ? 'Followerliste' : 'Gefolgt-Liste').' noch nicht gescannt',
    };
    $followersListScanTitle = $followersStatusState === $followersListScanState
        ? (data_get($listScanStatuses, 'followers.title') ?: $scanStateTitle('followers', $followersListScanState))
        : $scanStateTitle('followers', $followersListScanState);
    $followingListScanTitle = $followingStatusState === $followingListScanState
        ? (data_get($listScanStatuses, 'following.title') ?: $scanStateTitle('following', $followingListScanState))
        : $scanStateTitle('following', $followingListScanState);
    $scanMetricClass = fn (string $state): string => match ($state) {
        'complete' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'partial' => 'bg-amber-50 text-amber-800 ring-amber-200',
        default => 'bg-slate-50 text-slate-500 ring-slate-200',
    };
    $followersMetricClass = $scanMetricClass($followersListScanState);
    $followingMetricClass = $scanMetricClass($followingListScanState);
    $formatMetric = fn ($value): string => is_numeric($value) ? number_format((int) $value, 0, ',', '.') : '-';
    $trackedPersonId = (int) (data_get($item, 'trackedPersonId') ?? 0);

    if (! $trackedPersonId && $profile) {
        $trackedLink = method_exists($profile, 'relationLoaded') && $profile->relationLoaded('trackedPersonLinks')
            ? $profile->trackedPersonLinks
                ->where('user_id', $userId)
                ->whereNull('unlinked_at')
                ->first()
            : null;
        $trackedPersonId = (int) ($trackedLink?->tracked_person_id ?? 0);
    }

    $isTracked = (bool) (data_get($item, 'isTracked') ?? $trackedPersonId);
    $trackedProfileUrl = $trackedPersonId > 0 ? route('tracked-people.show', $trackedPersonId) : null;
    $initial = strtoupper(substr($username !== '' ? $username : '?', 0, 1));
    $searchText = strtolower(trim($username.' '.$displayName.' '.$meta.' '.$statusLabel.' '.($isTracked ? 'beobachtet' : 'nicht beobachtet')));
    $statusTitle = $statusLabel ?: 'Status';
@endphp

<div
    {{ $attributes->merge([
        'class' => 'flex items-center gap-3 rounded-xl border px-3 py-2 text-sm '.$toneClass,
        'data-profile-search' => e($searchText),
    ]) }}
>
    @if($showAction)
        <div class="min-w-0 flex-1">
            <x-ui.dropdown.anchor-dropdown
                align="left"
                width="auto"
                :offset="6"
                dropdown-classes=""
                content-classes="w-56 rounded-xl border border-slate-200 bg-white"
            >
                <x-slot name="trigger">
                    <button type="button" x-bind:aria-expanded="open" title="Profilaktionen" aria-label="Profilaktionen {{ $username !== '' ? '@'.$username : 'Instagram-Profil' }}" class="flex w-full min-w-0 items-center gap-3 rounded-lg text-left focus:outline-none focus:ring-2 focus:ring-slate-200">
                        @if($imageUrl)
                            <img src="{{ $imageUrl }}" alt="{{ $username !== '' ? '@'.$username : 'Instagram-Profilbild' }}" loading="lazy" class="h-10 w-10 shrink-0 rounded-full border object-cover {{ $avatarToneClass }} {{ $avatarRingClass }}">
                        @else
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border text-xs font-bold {{ $avatarToneClass }} {{ $avatarRingClass }}">{{ $initial }}</span>
                        @endif

                        <span class="min-w-0 flex-1">
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="truncate font-semibold text-slate-900">{{ $username !== '' ? '@'.$username : 'Unbekanntes Profil' }}</span>
                                @if($displayName !== '')
                                    <span class="hidden min-w-0 truncate text-xs text-slate-500 sm:inline">{{ $displayName }}</span>
                                @endif
                            </span>
                            @if($meta)
                                <span class="block truncate text-[11px] text-slate-500">{{ $meta }}</span>
                            @endif
                        </span>
                    </button>
                </x-slot>

                <x-slot name="content">
                    <div class="flex flex-col p-2 text-sm">
                        @if($detailUrl)
                            <a href="{{ $detailUrl }}" wire:navigate class="rounded-lg px-3 py-2 font-semibold text-slate-800 hover:bg-slate-50">
                                Profil im System
                            </a>
                        @endif
                        @if($trackedProfileUrl)
                            <a href="{{ $trackedProfileUrl }}" wire:navigate class="rounded-lg px-3 py-2 font-semibold text-sky-700 hover:bg-sky-50">
                                Beobachtete Person
                            </a>
                        @endif
                        @if($profileUrl)
                            <a href="{{ $profileUrl }}" target="_blank" rel="noopener noreferrer" class="rounded-lg px-3 py-2 font-semibold text-slate-800 hover:bg-slate-50">
                                Instagram oeffnen
                            </a>
                        @endif
                        @unless($detailUrl || $trackedProfileUrl || $profileUrl)
                            <div class="rounded-lg px-3 py-2 text-sm text-slate-500">Keine Profilaktion verfuegbar.</div>
                        @endunless
                    </div>
                </x-slot>
            </x-ui.dropdown.anchor-dropdown>
        </div>
    @else
        <div class="flex min-w-0 flex-1 items-center gap-3">
            @if($imageUrl)
                <img src="{{ $imageUrl }}" alt="{{ $username !== '' ? '@'.$username : 'Instagram-Profilbild' }}" loading="lazy" class="h-10 w-10 shrink-0 rounded-full border object-cover {{ $avatarToneClass }} {{ $avatarRingClass }}">
            @else
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border text-xs font-bold {{ $avatarToneClass }} {{ $avatarRingClass }}">{{ $initial }}</div>
            @endif

            <div class="min-w-0 flex-1">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="truncate font-semibold text-slate-900">{{ $username !== '' ? '@'.$username : 'Unbekanntes Profil' }}</span>
                    @if($displayName !== '')
                        <span class="hidden min-w-0 truncate text-xs text-slate-500 sm:inline">{{ $displayName }}</span>
                    @endif
                </div>
                @if($meta)
                    <div class="truncate text-[11px] text-slate-500">{{ $meta }}</div>
                @endif
            </div>
        </div>
    @endif

    <div class="flex shrink-0 items-center gap-1">
        <span title="{{ $isTracked ? 'Beobachtet' : 'Nicht beobachtet' }}" aria-label="{{ $isTracked ? 'Beobachtet' : 'Nicht beobachtet' }}" class="inline-flex h-6 w-6 items-center justify-center rounded-md ring-1 {{ $isTracked ? 'bg-sky-50 text-sky-700 ring-sky-200' : 'bg-slate-100 text-slate-500 ring-slate-200' }}">
            @if($isTracked)
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                </svg>
            @else
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M3 3l18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M10.6 6.2A10.5 10.5 0 0 1 12 6c6 0 9.5 6 9.5 6a17.5 17.5 0 0 1-2.8 3.4M6.2 6.8A17 17 0 0 0 2.5 12s3.5 6 9.5 6c1.3 0 2.5-.3 3.5-.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            @endif
        </span>
        @if($statusLabel)
            <span title="{{ $statusTitle }}" aria-label="{{ $statusTitle }}" class="inline-flex h-6 w-6 items-center justify-center rounded-md ring-1 {{ $statusClass }}">
                @if(str_contains(strtolower($statusLabel), 'entfernt') || str_contains(strtolower($statusLabel), 'removed'))
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                @elseif(str_contains(strtolower($statusLabel), 'neu') || str_contains(strtolower($statusLabel), 'added'))
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                @elseif(str_contains(strtolower($statusLabel), 'rekonstruiert'))
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M6 7a3 3 0 1 0 0 .1M18 17a3 3 0 1 0 0 .1M9 7h3a4 4 0 0 1 4 4v3M9 7l3-3M9 7l3 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                @elseif(str_contains(strtolower($statusLabel), 'passiv'))
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M5 12h11M13 8l4 4-4 4M6 6h3a9 9 0 0 1 9 9v3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                @else
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                @endif
            </span>
        @endif
    </div>

    <div class="hidden shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-slate-200 bg-white/70 px-2 py-1 text-[11px] font-semibold text-slate-600 md:flex">
        <span title="Beitraege" class="inline-flex items-center gap-1 rounded px-1 py-0.5">
            <svg class="h-3.5 w-3.5 text-violet-500" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="4" y="5" width="16" height="14" rx="2" stroke="currentColor" stroke-width="2"/>
                <path d="M8 9h8M8 13h5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            {{ $formatMetric($postsCount) }}
        </span>
        <span title="{{ $followersListScanTitle }}" aria-label="{{ $followersListScanTitle }}" class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 ring-1 {{ $followersMetricClass }}">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM19 19c0-1.6-.9-3-2.2-3.6M17 5.2a3 3 0 0 1 0 5.6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            {{ $formatMetric($followersCount) }}
        </span>
        <span title="{{ $followingListScanTitle }}" aria-label="{{ $followingListScanTitle }}" class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 ring-1 {{ $followingMetricClass }}">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M10 19c0-2.2-1.8-4-4-4m0 0a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM14 7h6M17 4v6M14 19h6M17 16l3 3-3 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            {{ $formatMetric($followingCount) }}
        </span>
    </div>

    @if($showAction)
        <div class="flex shrink-0 items-center gap-1">
            <x-ui.dropdown.anchor-dropdown
                align="right"
                width="auto"
                :offset="6"
                dropdown-classes=""
                content-classes="w-64 rounded-xl border border-slate-200 bg-white"
            >
                <x-slot name="trigger">
                    <button type="button" x-bind:aria-expanded="open" title="Scanaktionen" aria-label="Scanaktionen" @disabled(! $profileId) class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-pink-200 bg-pink-50 text-pink-700 hover:bg-pink-100 disabled:cursor-not-allowed disabled:opacity-50">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 3v3M12 18v3M3 12h3M18 12h3M6.6 6.6l2.1 2.1M15.3 15.3l2.1 2.1M17.4 6.6l-2.1 2.1M8.7 15.3l-2.1 2.1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </button>
                </x-slot>

                <x-slot name="content">
                    <div class="border-b border-slate-100 px-3 py-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                        {{ $username !== '' ? '@'.$username : 'Instagram-Profil' }}
                    </div>
                    <div class="flex flex-col p-2 text-sm">
                        @if($profileId)
                            @foreach([
                                'mini' => ['Mini-Scan', 'text-slate-800 hover:bg-slate-50'],
                                'full' => ['Vollanalyse', 'text-pink-700 hover:bg-pink-50'],
                                'followers' => ['Followerliste', 'text-emerald-700 hover:bg-emerald-50'],
                                'following' => ['Gefolgt-Liste', 'text-sky-700 hover:bg-sky-50'],
                                'posts' => ['Beitraege', 'text-violet-700 hover:bg-violet-50'],
                                'suggestions' => ['Vorschlaege', 'text-fuchsia-700 hover:bg-fuchsia-50'],
                                'suggestion_deepsearch' => ['Vorschlaege DeepSearch', 'text-fuchsia-700 hover:bg-fuchsia-50'],
                            ] as $scanType => [$scanLabel, $scanClass])
                                <button
                                    type="button"
                                    x-on:click="open = false"
                                    wire:click="$dispatch('scan-instagram-profile-from-list', { profileId: {{ $profileId }}, username: @js($username), scanType: '{{ $scanType }}' })"
                                    wire:loading.attr="disabled"
                                    class="rounded-lg px-3 py-2 text-left font-semibold {{ $scanClass }} disabled:opacity-50"
                                >
                                    {{ $scanLabel }} starten
                                </button>
                            @endforeach
                        @else
                            <div class="rounded-lg px-3 py-2 text-sm text-slate-500">
                                Fuer Scans muss das Profil zuerst im System gespeichert sein.
                            </div>
                        @endif
                    </div>
                </x-slot>
            </x-ui.dropdown.anchor-dropdown>
        </div>
    @endif
</div>
