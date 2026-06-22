@props([
    'model',
    'profile' => [],
    'trackedPerson' => null,
    'detailRoute' => null,
])

@php
    $trackedPersonId = $trackedPerson?->id ?? data_get($profile, 'tracked_person_id');
    $currentInstagramProfile = $trackedPerson?->currentInstagramProfile;
    $username = $trackedPerson?->instagram_username ?? data_get($profile, 'username');
    $handle = $username ? '@'.ltrim($username, '@') : data_get($profile, 'handle', 'Instagram-Profil');
    $displayName = $trackedPerson?->display_name ?? data_get($profile, 'display_name', $handle);
    $visibility = $trackedPerson?->latestInstagramSnapshot?->profile_visibility ?? data_get($profile, 'visibility', 'unknown');
    $followers = $trackedPerson?->instagram_followers_count ?? data_get($profile, 'followers_count');
    $following = $trackedPerson?->instagram_following_count ?? data_get($profile, 'following_count');
    $posts = $trackedPerson?->instagram_posts_count ?? data_get($profile, 'posts_count');
    $instagramProfileId = $currentInstagramProfile?->id ?? data_get($profile, 'id');
    $lastStatusMessage = $trackedPerson?->last_instagram_status_message ?? data_get($profile, 'last_status_message');
    $lastScannedAt = $trackedPerson?->last_instagram_analyzed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i')
        ?? data_get($profile, 'last_scanned_at');
    $profileUrl = data_get($profile, 'profile_url')
        ?: ($username ? 'https://www.instagram.com/'.ltrim($username, '@').'/' : null);
    $profileImagePath = $currentInstagramProfile
        ? $currentInstagramProfile->profile_image_path
        : ($trackedPerson?->instagram_profile_image_path
            ?? $trackedPerson?->profile_image_path
            ?? data_get($profile, 'profile_image_path'));
    $profileImageUrl = $currentInstagramProfile?->profile_image_url
        ?? data_get($profile, 'profile_image_url');
    $statusLevel = $trackedPerson?->last_instagram_status_level ?? data_get($profile, 'last_status_level', 'neutral');
    $statusLabel = match ($statusLevel) {
        'success' => 'Aktuell',
        'partial' => 'Teilweise',
        'cancelled' => 'Beendet',
        'error' => 'Fehler',
        default => 'Offen',
    };
    $statusTone = match ($statusLevel) {
        'success' => 'emerald',
        'partial' => 'amber',
        'error' => 'rose',
        default => 'slate',
    };
    $previewListItem = [
        'username' => $username,
        'displayName' => $displayName,
        'profileUrl' => $profileUrl,
        'instagramProfileId' => $instagramProfileId,
        'profileImagePath' => $profileImagePath,
        'profileImageUrl' => $profileImageUrl,
        'profileVisibility' => $visibility,
        'isPrivate' => $visibility === 'private',
        'postsCount' => $posts,
        'followersCount' => $followers,
        'followingCount' => $following,
        'trackedPersonId' => $trackedPersonId,
        'isTracked' => (bool) $trackedPersonId,
    ];
    $listButtonClass = 'rounded-lg border px-3 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-inset';
    $listMetricValue = fn ($value): string => $value !== null ? number_format((int) $value, 0, ',', '.') : '-';
    $fallbackListScans = collect(data_get($profile, 'list_scans', []))
        ->whereIn('list_type', ['followers', 'following'])
        ->keyBy('list_type');
    $fallbackScanLabel = function (string $listType) use ($fallbackListScans): string {
        $scan = $fallbackListScans->get($listType);

        if (! $scan) {
            return 'Keine Liste';
        }

        return match (data_get($scan, 'status_level')) {
            'success' => 'Gescannt',
            'partial', 'rate_limited' => 'Teilweise',
            default => 'Liste',
        };
    };
    $hasActionDropdown = (bool) $trackedPersonId || (isset($actions) && $actions->isNotEmpty());
@endphp

<div>
    <x-modal wire:model="{{ $model }}" maxWidth="3xl">
        <div class="flex max-h-[92vh] flex-col overflow-hidden bg-white">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5">
                <div class="min-w-0">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Instagram-Profilvorschau</div>
                    <h3 class="mt-1 truncate text-lg font-bold text-slate-950">{{ $handle }}</h3>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if($hasActionDropdown)
                        <x-ui.dropdown.anchor-dropdown
                            align="right"
                            width="auto"
                            :offset="8"
                            dropdown-classes=""
                            content-classes="w-96 max-w-[calc(100vw-2rem)] rounded-xl border border-slate-200 bg-white"
                        >
                            <x-slot name="trigger">
                                <button type="button" x-bind:aria-expanded="open" title="Scanaktionen" aria-label="Scanaktionen" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-pink-200 bg-pink-50 text-pink-700 hover:bg-pink-100">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M12 3v3M12 18v3M3 12h3M18 12h3M6.6 6.6l2.1 2.1M15.3 15.3l2.1 2.1M17.4 6.6l-2.1 2.1M8.7 15.3l-2.1 2.1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <div class="max-h-[70vh] overflow-y-auto p-3 text-left">
                                    @if($trackedPersonId)
                                        <livewire:user.tracked-person-detail
                                            :tracked-person-id="(int) $trackedPersonId"
                                            :compact="true"
                                            :key="'shared-instagram-profile-preview-actions-'.$trackedPersonId"
                                        />
                                    @elseif(isset($actions) && $actions->isNotEmpty())
                                        {{ $actions }}
                                    @endif
                                </div>
                            </x-slot>
                        </x-ui.dropdown.anchor-dropdown>
                    @endif

                    <button type="button" x-on:click="$dispatch('close')" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Schliessen
                    </button>
                </div>
            </div>

            <div class="overflow-y-auto p-4 sm:p-5">
                <x-instagram.profile-list-item
                    :item="$previewListItem"
                    tone="sky"
                    :status-label="$statusLabel"
                    :status-tone="$statusTone"
                    :show-action="false"
                    :show-visibility="false"
                />

                @if($lastStatusMessage || $lastScannedAt)
                    <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        @if($lastStatusMessage)
                            <p class="leading-6">{{ $lastStatusMessage }}</p>
                        @endif
                        <div class="{{ $lastStatusMessage ? 'mt-1' : '' }} text-xs font-semibold text-slate-500">
                            {{ $lastScannedAt ? 'Zuletzt gescannt: '.$lastScannedAt : 'Noch nicht gescannt.' }}
                        </div>
                    </div>
                @endif

                <div class="mt-4 grid grid-cols-1 overflow-hidden rounded-lg border border-slate-200 bg-white sm:grid-cols-3">
                    @if($trackedPersonId)
                        <x-profile.detail-metric
                            as="button"
                            label="Follower"
                            :value="$listMetricValue($followers)"
                            tone="emerald"
                            wire:click="$dispatch('open-tracked-person-relationship-list', { listType: 'followers' })"
                            title="Followerliste oeffnen"
                        >
                            Liste
                        </x-profile.detail-metric>
                        <x-profile.detail-metric
                            as="button"
                            label="Gefolgt"
                            :value="$listMetricValue($following)"
                            tone="sky"
                            wire:click="$dispatch('open-tracked-person-relationship-list', { listType: 'following' })"
                            title="Gefolgt-Liste oeffnen"
                        >
                            Liste
                        </x-profile.detail-metric>
                    @else
                        <div class="{{ $listButtonClass }} border-slate-200 bg-slate-50 text-slate-700">
                            <div class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Follower</div>
                            <div class="mt-1 text-base font-bold text-slate-950 sm:text-xl">{{ $listMetricValue($followers) }}</div>
                            <div class="mt-1 text-[10px] font-semibold text-slate-500">{{ $fallbackScanLabel('followers') }}</div>
                        </div>
                        <div class="{{ $listButtonClass }} border-slate-200 bg-slate-50 text-slate-700">
                            <div class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Gefolgt</div>
                            <div class="mt-1 text-base font-bold text-slate-950 sm:text-xl">{{ $listMetricValue($following) }}</div>
                            <div class="mt-1 text-[10px] font-semibold text-slate-500">{{ $fallbackScanLabel('following') }}</div>
                        </div>
                    @endif
                    <x-profile.detail-metric
                        label="Beitraege"
                        :value="$listMetricValue($posts)"
                        tone="violet"
                    >
                        Profilmetriken
                    </x-profile.detail-metric>
                </div>

                @if(! $trackedPersonId && data_get($profile, 'list_scans'))
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

    @if($trackedPersonId)
        <livewire:user.tracked-person-relationship-lists
            :tracked-person-id="(int) $trackedPersonId"
            :key="'instagram-profile-preview-relationship-lists-'.$trackedPersonId"
        />
    @endif
</div>
