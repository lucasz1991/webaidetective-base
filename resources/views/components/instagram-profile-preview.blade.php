@props([
    'model',
    'profile' => [],
    'trackedPerson' => null,
    'detailRoute' => null,
])

@php
    $trackedPersonId = $trackedPerson?->id ?? data_get($profile, 'tracked_person_id');
    $username = $trackedPerson?->instagram_username ?? data_get($profile, 'username');
    $handle = $username ? '@'.ltrim($username, '@') : data_get($profile, 'handle', 'Instagram-Profil');
    $displayName = $trackedPerson?->display_name ?? data_get($profile, 'display_name', $handle);
    $imageUrl = $trackedPerson?->profile_image_url ?? data_get($profile, 'image_url');
    $visibility = $trackedPerson?->latestInstagramSnapshot?->profile_visibility ?? data_get($profile, 'visibility', 'unknown');
    $followers = $trackedPerson?->instagram_followers_count ?? data_get($profile, 'followers_count');
    $following = $trackedPerson?->instagram_following_count ?? data_get($profile, 'following_count');
    $posts = $trackedPerson?->instagram_posts_count ?? data_get($profile, 'posts_count');
    $lastStatusMessage = $trackedPerson?->last_instagram_status_message ?? data_get($profile, 'last_status_message');
    $lastScannedAt = $trackedPerson?->last_instagram_analyzed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i')
        ?? data_get($profile, 'last_scanned_at');
    $profileUrl = data_get($profile, 'profile_url')
        ?: ($username ? 'https://www.instagram.com/'.ltrim($username, '@').'/' : null);
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
@endphp

<x-modal wire:model="{{ $model }}" maxWidth="3xl">
    <div class="flex max-h-[92vh] flex-col overflow-hidden bg-white">
        <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5">
            <div class="min-w-0">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Instagram-Profilvorschau</div>
                <h3 class="mt-1 truncate text-lg font-bold text-slate-950">{{ $handle }}</h3>
            </div>
            <button type="button" x-on:click="$dispatch('close')" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Schliessen
            </button>
        </div>

        <div class="overflow-y-auto p-4 sm:p-5">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="h-20 w-20 shrink-0 overflow-hidden rounded-full border border-slate-200 bg-slate-100">
                    @if($imageUrl)
                        <img src="{{ $imageUrl }}" alt="{{ $handle }}" class="h-full w-full object-cover">
                    @else
                        <div class="flex h-full w-full items-center justify-center text-xl font-bold text-slate-500">
                            {{ strtoupper(substr(ltrim($username ?: '?', '@'), 0, 1)) }}
                        </div>
                    @endif
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h4 class="break-words text-xl font-bold text-slate-950">{{ $displayName }}</h4>
                        <span class="rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 {{ $visibilityClass }}">{{ $visibilityLabel }}</span>
                        <span class="rounded-lg px-2.5 py-1 text-xs font-semibold {{ $trackedPersonId ? 'bg-sky-100 text-sky-800' : 'bg-slate-100 text-slate-600' }}">
                            {{ $trackedPersonId ? 'Beobachtetes Profil' : (data_get($profile, 'is_known_profile') ? 'Bekanntes Profil' : 'Unbekanntes Profil') }}
                        </span>
                    </div>
                    <div class="mt-1 text-sm font-semibold text-slate-500">{{ $handle }}</div>
                    @if($lastStatusMessage)
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $lastStatusMessage }}</p>
                    @endif
                    <div class="mt-2 text-xs text-slate-500">
                        {{ $lastScannedAt ? 'Zuletzt gescannt: '.$lastScannedAt : 'Noch nicht gescannt.' }}
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-3 gap-2 text-center">
                @foreach([['Follower', $followers], ['Gefolgt', $following], ['Beitraege', $posts]] as [$label, $value])
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                        <div class="text-lg font-bold text-slate-950">{{ $value !== null ? number_format($value) : '-' }}</div>
                        <div class="text-xs text-slate-500">{{ $label }}</div>
                    </div>
                @endforeach
            </div>

            @if($trackedPersonId)
                <div class="mt-5">
                    <livewire:user.tracked-person-detail
                        :tracked-person-id="(int) $trackedPersonId"
                        :compact="true"
                        :key="'shared-instagram-profile-preview-'.$trackedPersonId"
                    />
                </div>
            @else
                <div class="mt-5">
                    {{ $actions ?? '' }}
                </div>
            @endif

            @if(data_get($profile, 'list_scans'))
                <div class="mt-5 rounded-lg border border-slate-200 bg-white p-3">
                    <div class="text-sm font-bold text-slate-950">Letzte Listenscans</div>
                    <div class="mt-3 space-y-2">
                        @foreach(data_get($profile, 'list_scans', []) as $scan)
                            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                <span class="font-semibold text-slate-900">{{ data_get($scan, 'list_type') }}</span>
                                <span class="ml-2">{{ data_get($scan, 'scanned_at', '-') }}</span>
                                <div class="mt-1">Aktiv: {{ number_format(data_get($scan, 'active_count', 0)) }} / beobachtet: {{ number_format(data_get($scan, 'observed_count', 0)) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
            @if($profileUrl)
                <a href="{{ $profileUrl }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Instagram oeffnen
                </a>
            @endif
            @if($detailRoute)
                <a href="{{ $detailRoute }}" wire:navigate class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Zur Detailseite
                </a>
            @endif
            <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Schliessen
            </button>
        </div>
    </div>
</x-modal>
