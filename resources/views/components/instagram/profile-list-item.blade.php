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

    $visibilityLabel = match ($visibility) {
        'public' => 'Oeffentlich',
        'private' => 'Privat',
        default => 'Unbekannt',
    };
    $visibilityClass = match ($visibility) {
        'public' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'private' => 'bg-slate-100 text-slate-700 ring-slate-200',
        default => 'bg-amber-50 text-amber-800 ring-amber-200',
    };
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
    $statusClass = match ($statusTone) {
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
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
@endphp

<div
    {{ $attributes->merge([
        'class' => 'flex items-center gap-3 rounded-xl border px-3 py-2 text-sm '.$toneClass,
        'data-profile-search' => e($searchText),
    ]) }}
>
    @if($imageUrl)
        <img src="{{ $imageUrl }}" alt="{{ $username !== '' ? '@'.$username : 'Instagram-Profilbild' }}" loading="lazy" class="h-10 w-10 shrink-0 rounded-full border border-slate-200 object-cover {{ $avatarToneClass }}">
    @else
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-200 text-xs font-bold {{ $avatarToneClass }}">{{ $initial }}</div>
    @endif

    <div class="flex min-w-0 flex-1 items-center gap-2">
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

        <div class="hidden shrink-0 items-center gap-1 lg:flex">
            @if($showVisibility)
                <span class="inline-flex rounded-md px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $visibilityClass }}">{{ $visibilityLabel }}</span>
            @endif
            <span class="inline-flex rounded-md px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $isTracked ? 'bg-sky-50 text-sky-700 ring-sky-200' : 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                {{ $isTracked ? 'Beobachtet' : 'Nicht beobachtet' }}
            </span>
            @if($statusLabel)
                <span class="inline-flex max-w-44 truncate rounded-md px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $statusClass }}">{{ $statusLabel }}</span>
            @endif
        </div>

        <div class="hidden shrink-0 items-center gap-2 whitespace-nowrap rounded-md border border-slate-200 bg-white/70 px-2 py-1 text-[11px] font-semibold text-slate-600 md:flex">
            <span title="Beitraege">P {{ $formatMetric($postsCount) }}</span>
            <span class="text-slate-300">/</span>
            <span title="Follower">F {{ $formatMetric($followersCount) }}</span>
            <span class="text-slate-300">/</span>
            <span title="Folgt">G {{ $formatMetric($followingCount) }}</span>
        </div>
    </div>

    @if($showAction)
        <div class="flex shrink-0 items-center gap-1">
            <x-ui.dropdown.anchor-dropdown
                align="right"
                width="auto"
                :offset="6"
                dropdown-classes=""
                content-classes="w-56 rounded-xl border border-slate-200 bg-white"
            >
                <x-slot name="trigger">
                    <button type="button" x-bind:aria-expanded="open" class="inline-flex h-8 items-center justify-center rounded-lg border border-slate-300 bg-white px-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        Profil
                        <span class="ml-1 text-slate-400">v</span>
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

            <x-ui.dropdown.anchor-dropdown
                align="right"
                width="auto"
                :offset="6"
                dropdown-classes=""
                content-classes="w-64 rounded-xl border border-slate-200 bg-white"
            >
                <x-slot name="trigger">
                    <button type="button" x-bind:aria-expanded="open" @disabled(! $profileId) class="inline-flex h-8 items-center justify-center rounded-lg border border-pink-200 bg-pink-50 px-2.5 text-xs font-semibold text-pink-700 hover:bg-pink-100 disabled:cursor-not-allowed disabled:opacity-50">
                        Scans
                        <span class="ml-1 text-pink-400">v</span>
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
