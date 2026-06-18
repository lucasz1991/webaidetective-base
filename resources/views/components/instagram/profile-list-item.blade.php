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
    $profileUrl = $actionUrl ?: (
        data_get($raw, 'profileUrl')
        ?? data_get($item, 'profileUrl')
        ?? data_get($item, 'profile_url_snapshot')
        ?? $profile?->profile_url
    );
    $detailUrl = $profile?->id ? route('instagram-profiles.show', $profile->id) : null;
    $resolvedActionUrl = $detailUrl ?: $profileUrl;
    $resolvedActionLabel = $actionLabel ?: ($detailUrl ? 'Detail' : ($resolvedActionUrl ? 'Oeffnen' : null));
    $resolvedNavigate = $navigate || (bool) $detailUrl;
    $imageUrl = data_get($raw, 'profileImageUrl')
        ?? data_get($item, 'profileImageUrl')
        ?? data_get($item, 'imageUrl')
        ?? $profile?->profile_image_storage_url;
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
    $linkClass = match ($tone) {
        'emerald' => 'border-emerald-200 text-emerald-800 hover:bg-emerald-50',
        'rose' => 'border-rose-200 text-rose-800 hover:bg-rose-50',
        default => 'border-slate-300 text-slate-700 hover:bg-white',
    };
    $initial = strtoupper(substr($username !== '' ? $username : '?', 0, 1));
    $searchText = strtolower(trim($username.' '.$displayName.' '.$meta.' '.$statusLabel));
@endphp

<div
    {{ $attributes->merge([
        'class' => 'flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm '.$toneClass,
        'data-profile-search' => e($searchText),
    ]) }}
>
    @if($imageUrl)
        <img src="{{ $imageUrl }}" alt="{{ $username !== '' ? '@'.$username : 'Instagram-Profilbild' }}" loading="lazy" referrerpolicy="no-referrer" class="h-11 w-11 shrink-0 rounded-full border border-slate-200 object-cover {{ $avatarToneClass }}">
    @else
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-slate-200 text-xs font-bold {{ $avatarToneClass }}">{{ $initial }}</div>
    @endif

    <div class="min-w-0 flex-1">
        <div class="truncate font-semibold text-slate-900">{{ $username !== '' ? '@'.$username : 'Unbekanntes Profil' }}</div>
        @if($displayName !== '')
            <div class="mt-0.5 truncate text-slate-500">{{ $displayName }}</div>
        @endif
        @if($meta)
            <div class="mt-0.5 truncate text-xs text-slate-500">{{ $meta }}</div>
        @endif
        @if($showVisibility || $statusLabel)
            <div class="mt-1 flex flex-wrap gap-1.5">
                @if($showVisibility)
                    <span class="inline-flex rounded-lg px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $visibilityClass }}">{{ $visibilityLabel }}</span>
                @endif
                @if($statusLabel)
                    <span class="inline-flex rounded-lg px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $statusClass }}">{{ $statusLabel }}</span>
                @endif
            </div>
        @endif
    </div>

    @if($showAction && $resolvedActionUrl && $resolvedActionLabel)
        <a
            href="{{ $resolvedActionUrl }}"
            @if($resolvedNavigate) wire:navigate @else target="_blank" rel="noopener noreferrer" @endif
            class="shrink-0 rounded-lg border px-3 py-1.5 text-xs font-semibold {{ $linkClass }}"
        >
            {{ $resolvedActionLabel }}
        </a>
    @endif
</div>
