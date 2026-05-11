<div class="space-y-6">
    @php
        $detailStatusClass = match ($detailStatusLevel ?? 'neutral') {
            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
            default => 'border-slate-200 bg-slate-50 text-slate-800',
        };
        $latestSnapshot = $trackedPerson->latestInstagramSnapshot;
        $latestCountSources = data_get($latestSnapshot?->raw_payload, 'extractedProfile.countSources', []);
        $latestCountWarnings = data_get($latestSnapshot?->raw_payload, 'extractedProfile.countWarnings', []);
        $countSourceLabels = [
            'body_text_preview' => 'sichtbarer Profiltext',
            'description_meta' => 'Meta-Beschreibung',
            'html_document' => 'HTML-Fallback',
        ];
        $resolveCountSourceLabel = function ($source) use ($countSourceLabels) {
            return $source ? ($countSourceLabels[$source] ?? $source) : 'keine sichtbaren Werte';
        };
    @endphp

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-4">
                <div class="h-24 w-24 overflow-hidden rounded-3xl bg-slate-100">
                    @if($trackedPerson->profile_image_url)
                        <img src="{{ $trackedPerson->profile_image_url }}" alt="{{ $trackedPerson->display_name }}" class="h-full w-full object-cover">
                    @else
                        <div class="flex h-full w-full items-center justify-center text-xs font-semibold text-slate-500">
                            Kein Bild
                        </div>
                    @endif
                </div>
                <div>
                    <h2 class="text-2xl font-bold text-slate-900">{{ $trackedPerson->display_name }}</h2>
                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600">
                        <span>Alias: {{ $trackedPerson->alias ?: '—' }}</span>
                        <span>Ort: {{ $trackedPerson->city ?: '—' }}</span>
                        <span>Land: {{ $trackedPerson->country ?: '—' }}</span>
                        <span>Geburt: {{ optional($trackedPerson->date_of_birth)->format('d.m.Y') ?: '—' }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        @if($trackedPerson->instagram_username)
                            <span class="rounded-full bg-pink-100 px-3 py-1 font-semibold text-pink-700">
                                Instagram: {{ '@'.$trackedPerson->instagram_username }}
                            </span>
                        @endif
                        @if($trackedPerson->monitoring_enabled)
                            <span class="rounded-full bg-indigo-100 px-3 py-1 font-semibold text-indigo-700">
                                Dauerbeobachtung aktiv
                            </span>
                        @endif
                        @if($trackedPerson->notify_social_changes)
                            <span class="rounded-full bg-sky-100 px-3 py-1 font-semibold text-sky-700">
                                Benachrichtigungen aktiv
                            </span>
                        @endif
                        @if($trackedPerson->last_instagram_analyzed_at)
                            <span class="rounded-full bg-slate-100 px-3 py-1 font-semibold text-slate-700">
                                Letzte Analyse: {{ $trackedPerson->last_instagram_analyzed_at->format('d.m.Y H:i') }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    wire:click="analyzeInstagram"
                    class="rounded-xl bg-pink-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-pink-700"
                >
                    Instagram analysieren
                </button>
                <button
                    wire:click="saveTrackedPerson"
                    class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-slate-800"
                >
                    Person speichern
                </button>
            </div>
        </div>

        @if($detailStatus)
            <div class="mt-5 rounded-2xl border p-4 text-sm {{ $detailStatusClass }}">
                {{ $detailStatus }}
            </div>
        @endif
    </section>

    <section class="grid gap-4 md:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Follower</div>
            <div class="mt-2 text-2xl font-bold text-slate-900">{{ $trackedPerson->instagram_followers_count !== null ? number_format($trackedPerson->instagram_followers_count) : '—' }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gefolgt</div>
            <div class="mt-2 text-2xl font-bold text-slate-900">{{ $trackedPerson->instagram_following_count !== null ? number_format($trackedPerson->instagram_following_count) : '—' }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Beitraege</div>
            <div class="mt-2 text-2xl font-bold text-slate-900">{{ $trackedPerson->instagram_posts_count !== null ? number_format($trackedPerson->instagram_posts_count) : '—' }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Bekannte Daten</div>
            <div class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($trackedPerson->knownFacts->count()) }}</div>
        </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
        <div class="space-y-6">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Personendaten</h3>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Vorname</label>
                        <input type="text" wire:model.defer="first_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('first_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Nachname</label>
                        <input type="text" wire:model.defer="last_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('last_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Alias</label>
                        <input type="text" wire:model.defer="alias" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Geburtsdatum</label>
                        <input type="date" wire:model.defer="date_of_birth" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Ort</label>
                        <input type="text" wire:model.defer="city" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Land</label>
                        <input type="text" wire:model.defer="country" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Instagram</label>
                        <input type="text" wire:model.defer="instagram_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">TikTok</label>
                        <input type="text" wire:model.defer="tiktok_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Facebook</label>
                        <input type="text" wire:model.defer="facebook_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">X / Twitter</label>
                        <input type="text" wire:model.defer="x_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">YouTube</label>
                        <input type="text" wire:model.defer="youtube_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Snapchat</label>
                        <input type="text" wire:model.defer="snapchat_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-slate-700">Notizen</label>
                        <textarea wire:model.defer="notes" rows="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"></textarea>
                    </div>
                </div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Bekannte Daten</h3>
                <div class="mt-4 space-y-3">
                    @forelse($trackedPerson->knownFacts as $knownFact)
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                            <div class="flex items-center justify-between gap-3">
                                <div class="font-semibold text-slate-900">{{ $knownFact->label }}</div>
                                @if($knownFact->source)
                                    <span class="text-xs uppercase tracking-wide text-slate-500">{{ $knownFact->source }}</span>
                                @endif
                            </div>
                            <p class="mt-1 whitespace-pre-wrap">{{ $knownFact->value }}</p>
                            @if($knownFact->notes)
                                <p class="mt-2 text-xs text-slate-500">{{ $knownFact->notes }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Noch keine bekannten Daten hinterlegt.</p>
                    @endforelse
                </div>

                <div class="mt-5 grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Bezeichnung</label>
                        <input type="text" wire:model.defer="knownFactLabel" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" placeholder="z. B. Wohnort">
                        @error('knownFactLabel') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Wert</label>
                        <textarea wire:model.defer="knownFactValue" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"></textarea>
                        @error('knownFactValue') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Quelle</label>
                        <input type="text" wire:model.defer="knownFactSource" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @error('knownFactSource') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Zusatznotiz</label>
                        <textarea wire:model.defer="knownFactNotes" rows="2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"></textarea>
                        @error('knownFactNotes') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end">
                        <button wire:click="saveKnownFact" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-slate-800">
                            Daten speichern
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Benachrichtigungen</h3>
                <p class="mt-1 text-sm text-slate-600">
                    Benachrichtigungen sind nur sinnvoll, wenn die Person dauerhaft beobachtet werden soll und oeffentlich sichtbare Aenderungen regelmaessig neu analysiert werden.
                </p>

                <div class="mt-4 space-y-3">
                    <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                        <input type="checkbox" wire:model.defer="monitoring_enabled" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span class="font-medium">Dauerbeobachtung fuer diese Person aktivieren</span>
                    </label>
                    <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                        <input type="checkbox" wire:model.defer="notify_social_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span class="font-medium">Benachrichtigungen fuer Social-Media-Aenderungen aktivieren</span>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_instagram_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>Instagram</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_tiktok_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>TikTok</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_facebook_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>Facebook</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_x_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>X / Twitter</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_youtube_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>YouTube</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="notify_snapchat_changes" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>Snapchat</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Letzte Instagram-Analyse</h3>

                @if($latestSnapshot)
                    @php
                        $snapshotStatusClass = match ($latestSnapshot->status_level ?? 'neutral') {
                            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                            'partial' => 'border-amber-200 bg-amber-50 text-amber-950',
                            'error' => 'border-rose-200 bg-rose-50 text-rose-900',
                            default => 'border-slate-200 bg-slate-50 text-slate-700',
                        };
                    @endphp

                    <div class="mt-4 rounded-2xl border p-4 text-sm {{ $snapshotStatusClass }}">
                        <p class="font-semibold">{{ $latestSnapshot->status_message }}</p>
                        <p class="mt-1 text-xs">{{ optional($latestSnapshot->analyzed_at)->format('d.m.Y H:i') ?: '—' }}</p>
                        @if($latestSnapshot->screenshot_url)
                            <a href="{{ $latestSnapshot->screenshot_url }}" target="_blank" class="mt-3 inline-flex rounded-full border border-current px-3 py-1 text-xs font-semibold uppercase tracking-wide">
                                Snapshot oeffnen
                            </a>
                        @endif
                    </div>

                    <div class="mt-4 grid gap-3 text-sm text-slate-700">
                        <p><span class="font-semibold">Profilname:</span> {{ $latestSnapshot->full_name ?: '—' }}</p>
                        <p><span class="font-semibold">Bio:</span> {{ $latestSnapshot->biography ?: '—' }}</p>
                        <p><span class="font-semibold">Follower-Quelle:</span> {{ $resolveCountSourceLabel($latestCountSources['followers'] ?? null) }}</p>
                        <p><span class="font-semibold">Gefolgt-Quelle:</span> {{ $resolveCountSourceLabel($latestCountSources['following'] ?? null) }}</p>
                        <p><span class="font-semibold">Beitraege-Quelle:</span> {{ $resolveCountSourceLabel($latestCountSources['posts'] ?? null) }}</p>
                        <p><span class="font-semibold">Profilbild-Hash:</span> {{ $latestSnapshot->profile_image_hash ?: '—' }}</p>
                    </div>

                    @if($latestCountWarnings)
                        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            <h4 class="font-semibold">Metrik-Hinweise</h4>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @foreach($latestCountWarnings as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($latestSnapshot->has_changes && $latestSnapshot->detected_changes)
                        <div class="mt-4 rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
                            <h4 class="font-semibold">Erkannte Aenderungen</h4>
                            <ul class="mt-2 space-y-2">
                                @foreach($latestSnapshot->detected_changes as $change)
                                    <li>
                                        <span class="font-semibold">{{ $change['label'] ?? $change['field'] }}:</span>
                                        <span>{{ filled($change['before'] ?? null) ? $change['before'] : '—' }}</span>
                                        <span>→</span>
                                        <span>{{ filled($change['after'] ?? null) ? $change['after'] : '—' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($latestSnapshot->media->isNotEmpty())
                        <div class="mt-4">
                            <h4 class="text-sm font-semibold text-slate-900">Gespeicherte Bilder der letzten Analyse</h4>
                            <div class="mt-3 grid grid-cols-2 gap-3">
                                @foreach($latestSnapshot->media as $media)
                                    @if($media->storage_url)
                                        <a href="{{ $media->storage_url }}" target="_blank" class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-100">
                                            <img src="{{ $media->storage_url }}" alt="Gespeichertes Medienbild" class="h-32 w-full object-cover">
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                @else
                    <p class="mt-4 text-sm text-slate-500">Bisher wurde noch keine Instagram-Analyse gespeichert.</p>
                @endif
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900">Analyse-Historie</h3>
                <div class="mt-4 space-y-3">
                    @forelse($trackedPerson->instagramSnapshots as $snapshot)
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-slate-900">{{ optional($snapshot->analyzed_at)->format('d.m.Y H:i') ?: '—' }}</div>
                                    <div class="mt-1">{{ $snapshot->status_message }}</div>
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ $snapshot->status_level }}
                                </div>
                            </div>
                            <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                                <div class="rounded-xl bg-white px-3 py-2">
                                    <div class="text-slate-500">Follower</div>
                                    <div class="mt-1 font-semibold text-slate-900">{{ $snapshot->followers_count !== null ? number_format($snapshot->followers_count) : '—' }}</div>
                                </div>
                                <div class="rounded-xl bg-white px-3 py-2">
                                    <div class="text-slate-500">Gefolgt</div>
                                    <div class="mt-1 font-semibold text-slate-900">{{ $snapshot->following_count !== null ? number_format($snapshot->following_count) : '—' }}</div>
                                </div>
                                <div class="rounded-xl bg-white px-3 py-2">
                                    <div class="text-slate-500">Beitraege</div>
                                    <div class="mt-1 font-semibold text-slate-900">{{ $snapshot->posts_count !== null ? number_format($snapshot->posts_count) : '—' }}</div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Noch keine Verlaufseintraege vorhanden.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </section>
</div>
