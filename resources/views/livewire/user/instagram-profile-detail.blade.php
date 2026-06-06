<div class="container mx-auto space-y-5 px-5 py-6">
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
    @endphp

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
                    @if($profile->last_status_message)
                        <p class="mt-3 text-sm text-slate-500">{{ $profile->last_status_message }}</p>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                @if($trackedPerson)
                    <a href="{{ route('tracked-people.show', $trackedPerson->id) }}" wire:navigate class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Beobachtete Person
                    </a>
                @endif
                <a href="{{ $profile->profile_url ?: 'https://www.instagram.com/'.$profile->username.'/' }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Instagram oeffnen
                </a>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-px bg-slate-200 sm:grid-cols-4">
            @foreach([
                ['Follower', $profile->followers_count],
                ['Gefolgt', $profile->following_count],
                ['Beitraege', $profile->posts_count],
                ['Letzter Scan', $profile->last_scanned_at?->timezone(config('app.timezone'))->format('d.m.Y H:i')],
            ] as [$label, $value])
                <div class="bg-white px-5 py-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</div>
                    <div class="mt-1 text-lg font-bold text-slate-950">
                        {{ is_numeric($value) ? number_format($value) : ($value ?: '-') }}
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <div class="grid gap-5 xl:grid-cols-2">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-bold text-slate-950">Folgt diesen Profilen</h2>
            <div class="mt-4 space-y-2">
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

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-bold text-slate-950">Wird von diesen Profilen referenziert</h2>
            <div class="mt-4 space-y-2">
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
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
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
</div>
