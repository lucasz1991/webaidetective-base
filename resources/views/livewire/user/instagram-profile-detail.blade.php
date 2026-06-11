<div class="container mx-auto space-y-5 px-5 py-6" x-data="{ actionsOpen: false, scansOpen: false }">
    @php
        $visibility = $profile->profile_visibility ?: 'unknown';
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
        $scanStatusLevel = $lastScanStatus['level'] ?: 'unknown';
        $scanStatusLabel = match ($scanStatusLevel) {
            'success' => 'Erfolgreich',
            'partial' => 'Teilweise',
            'error', 'failed' => 'Fehlgeschlagen',
            default => 'Unbekannt',
        };
        $scanStatusClass = match ($scanStatusLevel) {
            'success' => 'text-emerald-700',
            'partial' => 'text-amber-700',
            'error', 'failed' => 'text-rose-700',
            default => 'text-slate-600',
        };
    @endphp

    <div
        wire:loading.flex
        wire:target="analyzeInstagramMini,analyzeInstagram,scanInstagramFollowersList,scanInstagramFollowingList,scanInstagramSuggestions,scanInstagramPosts"
        class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-950/70 px-4"
    >
        <div class="w-full max-w-md rounded-lg bg-white p-6 text-center shadow-2xl">
            <div class="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-pink-200 border-t-pink-600"></div>
            <h3 class="mt-4 text-lg font-bold text-slate-950">Instagram-Scan laeuft</h3>
            <p class="mt-2 text-sm text-slate-600">Das Profil wird mit denselben Scan-Services wie eine beobachtete Person verarbeitet.</p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3">
        <a
            href="{{ url()->previous() }}"
            class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm hover:bg-slate-50"
        >
            Zurueck
        </a>

        <div class="flex items-center gap-2">
            <div class="relative">
            <button
                type="button"
                @click="scansOpen = ! scansOpen; actionsOpen = false"
                class="inline-flex items-center justify-center rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
            >
                Scans
                <span class="ml-2 text-slate-300">▾</span>
            </button>

            <div
                x-show="scansOpen"
                x-cloak
                @click.outside="scansOpen = false"
                class="absolute right-0 top-full z-30 mt-2 w-72 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl"
            >
                <div class="border-b border-slate-100 px-4 py-3">
                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Profil scannen</div>
                    <div class="mt-1 text-xs text-slate-500">{{ $visibilityLabel }}es Instagram-Profil</div>
                </div>
                <div class="flex flex-col p-2">
                    <button
                        type="button"
                        @click="scansOpen = false"
                        wire:click="analyzeInstagramMini"
                        wire:loading.attr="disabled"
                        wire:target="analyzeInstagramMini"
                        class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50 disabled:opacity-50"
                    >
                        Mini-Scan
                        <span class="mt-0.5 block text-xs font-normal text-slate-500">Profilwerte schnell aktualisieren</span>
                    </button>
                    <button
                        type="button"
                        @click="scansOpen = false"
                        wire:click="analyzeInstagram"
                        wire:loading.attr="disabled"
                        wire:target="analyzeInstagram"
                        class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-pink-700 hover:bg-pink-50 disabled:opacity-50"
                    >
                        Vollanalyse
                        <span class="mt-0.5 block text-xs font-normal text-pink-500">Profil und passende Folge-Scans</span>
                    </button>

                    @if($visibility === 'public')
                        <div class="my-1 border-t border-slate-100"></div>
                        <button
                            type="button"
                            @click="scansOpen = false"
                            wire:click="scanInstagramFollowersList"
                            wire:loading.attr="disabled"
                            wire:target="scanInstagramFollowersList"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-sky-700 hover:bg-sky-50 disabled:opacity-50"
                        >
                            Followerliste scannen
                        </button>
                        <button
                            type="button"
                            @click="scansOpen = false"
                            wire:click="scanInstagramFollowingList"
                            wire:loading.attr="disabled"
                            wire:target="scanInstagramFollowingList"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-sky-700 hover:bg-sky-50 disabled:opacity-50"
                        >
                            Gefolgt-Liste scannen
                        </button>
                        <button
                            type="button"
                            @click="scansOpen = false"
                            wire:click="scanInstagramPosts"
                            wire:loading.attr="disabled"
                            wire:target="scanInstagramPosts"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-violet-700 hover:bg-violet-50 disabled:opacity-50"
                        >
                            Beitraege scannen
                        </button>
                    @elseif($visibility === 'private')
                        <div class="my-1 border-t border-slate-100"></div>
                        <button
                            type="button"
                            @click="scansOpen = false"
                            wire:click="scanInstagramSuggestions"
                            wire:loading.attr="disabled"
                            wire:target="scanInstagramSuggestions"
                            class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-fuchsia-700 hover:bg-fuchsia-50 disabled:opacity-50"
                        >
                            Vorschlaege scannen
                            <span class="mt-0.5 block text-xs font-normal text-fuchsia-500">Verbindungen bei privaten Profilen ermitteln</span>
                        </button>
                    @endif
                </div>
            </div>
            </div>

            <div class="relative">
            <button
                type="button"
                @click="actionsOpen = ! actionsOpen; scansOpen = false"
                class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm hover:bg-slate-50"
            >
                Aktionen
                <span class="ml-2 text-slate-500">▾</span>
            </button>

            <div
                x-show="actionsOpen"
                x-cloak
                @click.outside="actionsOpen = false"
                class="absolute right-0 top-full z-20 mt-2 w-72 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl"
            >
                <div class="flex flex-col p-2">
                    @if($trackedPerson)
                        <a
                            href="{{ route('tracked-people.show', $trackedPerson->id) }}"
                            wire:navigate
                            @click="actionsOpen = false"
                            class="rounded-xl px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                        >
                            Beobachtete Person
                        </a>
                    @endif

                    <a
                        href="{{ $profile->profile_url ?: 'https://www.instagram.com/'.$profile->username.'/' }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        @click="actionsOpen = false"
                        class="rounded-xl px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                    >
                        Instagram oeffnen
                    </a>

                    <button
                        type="button"
                        @click="actionsOpen = false"
                        wire:click="openListModal('followers')"
                        class="w-full rounded-xl px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                    >
                        Follower-Liste ansehen
                    </button>
                    <button
                        type="button"
                        @click="actionsOpen = false"
                        wire:click="openListModal('following')"
                        class="w-full rounded-xl px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-50"
                    >
                        Gefolgt-Liste ansehen
                    </button>
                </div>
            </div>
            </div>
        </div>
    </div>

    @if($detailStatus)
        <div @class([
            'rounded-lg border p-3 text-sm',
            'border-emerald-200 bg-emerald-50 text-emerald-900' => $detailStatusLevel === 'success',
            'border-amber-200 bg-amber-50 text-amber-950' => $detailStatusLevel === 'partial',
            'border-rose-200 bg-rose-50 text-rose-900' => $detailStatusLevel === 'error',
        ])>
            {{ $detailStatus }}
        </div>
    @endif

    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-5 p-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex min-w-0 gap-4">
                <div class="h-24 w-24 shrink-0 overflow-hidden rounded-full border border-slate-200 bg-slate-100">
                    @if($profile->profile_image_storage_url)
                        <img src="{{ $profile->profile_image_storage_url }}" alt="{{ $profile->display_handle }}" class="h-full w-full object-cover">
                    @else
                        <div class="flex h-full w-full items-center justify-center text-2xl font-bold text-slate-500">
                            {{ strtoupper(substr($profile->username ?: '?', 0, 1)) }}
                        </div>
                    @endif
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="break-words text-2xl font-bold text-slate-950">
                            {{ $profile->display_name ?: $profile->full_name ?: $profile->display_handle }}
                        </h1>
                        <span class="rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 {{ $visibilityClass }}">{{ $visibilityLabel }}</span>
                        <span class="rounded-lg px-2.5 py-1 text-xs font-semibold {{ $trackedPerson ? 'bg-sky-100 text-sky-800' : 'bg-slate-100 text-slate-600' }}">
                            {{ $trackedPerson ? 'Beobachtetes Profil' : 'Nicht beobachtet' }}
                        </span>
                    </div>
                    <div class="mt-1 text-sm font-semibold text-slate-500">{{ $profile->display_handle }}</div>
                    @if($profile->biography)
                        <p class="mt-3 max-w-3xl whitespace-pre-line text-sm leading-6 text-slate-600">{{ $profile->biography }}</p>
                    @endif
                    @if($lastScanStatus['message'])
                        <p class="mt-3 text-sm text-slate-500">
                            <span class="font-semibold {{ $scanStatusClass }}">{{ $lastScanStatus['type'] }}:</span>
                            {{ $lastScanStatus['message'] }}
                        </p>
                    @endif
                </div>
            </div>

        </div> 

        <div class="grid grid-cols-2 gap-px bg-slate-200 sm:grid-cols-3 xl:grid-cols-5">
            <button
                type="button"
                wire:click="openListModal('followers')"
                class="bg-white px-5 py-4 text-left transition hover:bg-pink-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-pink-500"
                title="Followerliste oeffnen"
            >
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Follower</div>
                <div class="mt-1 text-lg font-bold text-slate-950">{{ is_numeric($profile->followers_count) ? number_format($profile->followers_count) : '-' }}</div>
                <div class="mt-1 text-xs font-semibold text-pink-700">Liste oeffnen</div>
            </button>
            <button
                type="button"
                wire:click="openListModal('following')"
                class="bg-white px-5 py-4 text-left transition hover:bg-pink-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-pink-500"
                title="Gefolgt-Liste oeffnen"
            >
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gefolgt</div>
                <div class="mt-1 text-lg font-bold text-slate-950">{{ is_numeric($profile->following_count) ? number_format($profile->following_count) : '-' }}</div>
                <div class="mt-1 text-xs font-semibold text-pink-700">Liste oeffnen</div>
            </button>
            <div class="bg-white px-5 py-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Beitraege</div>
                <div class="mt-1 text-lg font-bold text-slate-950">{{ is_numeric($profile->posts_count) ? number_format($profile->posts_count) : '-' }}</div>
            </div>
            <div class="bg-white px-5 py-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Letzter Scan</div>
                <div class="mt-1 text-lg font-bold text-slate-950">
                    {{ $lastScanStatus['scannedAt']?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}
                </div>
                <div class="mt-1 text-xs text-slate-500">{{ $lastScanStatus['type'] }}</div>
            </div>
            <div class="bg-white px-5 py-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Scanstatus</div>
                <div class="mt-1 text-lg font-bold {{ $scanStatusClass }}">{{ $scanStatusLabel }}</div>
                <div class="mt-1 truncate text-xs text-slate-500" title="{{ $lastScanStatus['message'] }}">{{ $lastScanStatus['message'] ?: 'Noch kein Scanstatus gespeichert.' }}</div>
            </div>
        </div>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-bold text-slate-950">Scans und gespeicherte Listen</h2>
                <p class="mt-1 text-sm text-slate-500">Alle Scan- und Listenaktionen findest du jetzt gesammelt oben rechts im Aktionsmenue.</p>
            </div>
            @if(! $trackedPerson)
                <span class="rounded-lg bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-200">
                    Beim ersten Scan wird das Profil automatisch als beobachtet angelegt.
                </span>
            @endif
        </div>
    </section>

    <div class="grid gap-5 xl:grid-cols-2">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-bold text-slate-950">Folgt diesen Profilen</h2>
            <div class="mt-4 max-h-80 space-y-2 overflow-y-auto pr-2">
                @forelse($profile->sourceRelationships as $relationship)
                    @php($related = $relationship->relatedInstagramProfile)
                    @if($related)
                        <a href="{{ route('instagram-profiles.show', $related->id) }}" wire:navigate class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
                            <span class="font-semibold text-slate-800">{{ $related->display_handle }}</span>
                            <span class="text-xs text-slate-500">{{ $relationship->list_type }}</span>
                        </a>
                    @endif
                @empty
                    <p class="text-sm text-slate-500">Keine aktiven ausgehenden Beziehungen gespeichert.</p>
                @endforelse
            </div>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm xl:order-last xl:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-bold text-slate-950">Gespeicherte Beitraege</h2>
            @if($profile->postScans->isNotEmpty())
                <span class="text-xs text-slate-500">
                    Letzter Scan: {{ $profile->postScans->first()->scanned_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}
                </span>
            @endif
        </div>
        <div class="mt-4 max-h-[42rem] overflow-y-auto pr-2">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @forelse($profile->posts as $post)
                @php($primaryMedia = $post->media->first())
                @php($mediaUrl = $primaryMedia?->media_url)
                @php($previewUrl = $primaryMedia?->preview_media_url ?: $post->thumbnail_storage_url)
                <article class="overflow-hidden rounded-lg border border-slate-200 bg-slate-50 transition hover:border-violet-300 hover:bg-violet-50">
                    @if($primaryMedia?->media_type === 'video' && $mediaUrl)
                        <video controls preload="metadata" playsinline poster="{{ $previewUrl }}" class="h-48 w-full bg-black object-contain">
                            <source src="{{ $mediaUrl }}" type="{{ $primaryMedia->mime_type ?: 'video/mp4' }}">
                        </video>
                    @elseif($mediaUrl || $previewUrl)
                        <a href="{{ $post->post_url }}" target="_blank" rel="noopener noreferrer" class="block">
                            <img src="{{ $mediaUrl ?: $previewUrl }}" alt="Instagram-Beitrag {{ $post->shortcode }}" loading="lazy" class="h-48 w-full object-cover">
                        </a>
                    @endif
                    <div class="p-3">
                        <div class="flex items-center justify-between gap-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <span>
                                {{ $post->media_type }}
                                @if($post->media_count > 1)
                                    · {{ number_format($post->media_count) }} Medien
                                @endif
                            </span>
                            <span>{{ $post->published_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}</span>
                        </div>
                        @if($post->caption)
                            <p class="mt-2 line-clamp-2 text-sm text-slate-700">{{ $post->caption }}</p>
                        @endif
                        <div class="mt-3 flex gap-4 text-sm font-semibold text-slate-800">
                            <span>{{ $post->likes_count !== null ? number_format($post->likes_count) : '-' }} Likes</span>
                            <span>{{ $post->comments_count !== null ? number_format($post->comments_count) : '-' }} Kommentare</span>
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            {{ number_format($post->metrics_count ?? 0) }} gespeicherte Messpunkte
                        </div>
                        <a href="{{ $post->post_url }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex text-xs font-semibold text-violet-700 hover:text-violet-900">
                            Auf Instagram öffnen
                        </a>
                    </div>
                </article>
                @empty
                    <p class="text-sm text-slate-500">Noch keine Beitraege gespeichert.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-bold text-slate-950">Wird von diesen Profilen referenziert</h2>
            <div class="mt-4 max-h-80 space-y-2 overflow-y-auto pr-2">
                @forelse($profile->relatedRelationships as $relationship)
                    @php($source = $relationship->sourceInstagramProfile)
                    @if($source)
                        <a href="{{ route('instagram-profiles.show', $source->id) }}" wire:navigate class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
                            <span class="font-semibold text-slate-800">{{ $source->display_handle }}</span>
                            <span class="text-xs text-slate-500">{{ $relationship->list_type }}</span>
                        </a>
                    @endif
                @empty
                    <p class="text-sm text-slate-500">Keine aktiven eingehenden Beziehungen gespeichert.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-base font-bold text-slate-950">Letzte Listenscans</h2>
        <div class="mt-4 max-h-96 overflow-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="sticky top-0 z-10 bg-white text-left text-xs font-semibold uppercase tracking-wide text-slate-500 shadow-sm">
                    <tr>
                        <th class="px-3 py-2">Liste</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Aktiv</th>
                        <th class="px-3 py-2">Beobachtet</th>
                        <th class="px-3 py-2">Zeitpunkt</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($profile->listScans as $scan)
                        <tr>
                            <td class="px-3 py-2 font-semibold text-slate-800">{{ $scan->list_type }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ $scan->status_level }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ number_format($scan->active_count) }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ number_format($scan->observed_count) }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ $scan->scanned_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-5 text-center text-slate-500">Noch keine Listenscans gespeichert.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <x-instagram-profile-list-modal
        model="showListModal"
        :title="$activeListType === 'followers' ? 'Followerliste' : 'Gefolgt-Liste'"
        :scan="$activeListType === 'followers' ? $latestFollowersScan : $latestFollowingScan"
    />
</div>
