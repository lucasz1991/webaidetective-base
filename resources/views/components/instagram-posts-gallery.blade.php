@props([
    'posts',
    'title' => 'Instagram-Beitraege',
    'lastScanAt' => null,
    'emptyText' => 'Noch keine Instagram-Beitraege gespeichert.',
])

<section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-lg font-bold text-slate-900">{{ $title }}</h3>
        @if($lastScanAt)
            <span class="text-xs text-slate-500">
                Letzter Scan: {{ $lastScanAt->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
            </span>
        @endif
    </div>

    <div class="mt-3 max-h-[42rem] overflow-y-auto pr-2">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @forelse($posts as $post)
                @php
                    $primaryMedia = $post->media->first();
                    $mediaUrl = $primaryMedia?->media_url;
                    $previewUrl = $primaryMedia?->preview_media_url ?: $post->thumbnail_storage_url;
                    $storedLikesCount = $post->stored_likes_count ?? $post->likes_count ?? null;
                    $storedCommentsCount = $post->stored_comments_count ?? $post->comments_count ?? null;
                @endphp

                <article class="group overflow-hidden rounded-xl border border-slate-200 bg-slate-50 shadow-sm transition hover:border-violet-300 hover:bg-violet-50">
                    <button
                        type="button"
                        wire:click="openPostEngagementModal({{ $post->id }}, 'comments')"
                        class="block w-full text-left"
                        aria-label="Beitrag {{ $post->shortcode }} oeffnen"
                    >
                        @if($primaryMedia?->media_type === 'video' && $mediaUrl)
                            <video preload="metadata" playsinline poster="{{ $previewUrl }}" class="h-48 w-full bg-black object-contain">
                                <source src="{{ $mediaUrl }}" type="{{ $primaryMedia->mime_type ?: 'video/mp4' }}">
                            </video>
                        @elseif($mediaUrl || $previewUrl)
                            <img src="{{ $mediaUrl ?: $previewUrl }}" alt="Instagram-Beitrag {{ $post->shortcode }}" loading="lazy" class="h-48 w-full object-cover">
                        @else
                            <div class="flex h-48 w-full items-center justify-center bg-slate-100 text-sm font-semibold text-slate-500">
                                Keine Medienvorschau
                            </div>
                        @endif
                    </button>

                    <div class="p-3">
                        <div class="flex items-center justify-between gap-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <span>
                                {{ $post->media_type ?: 'post' }}
                                @if($post->media_count > 1)
                                    · {{ number_format($post->media_count) }} Medien
                                @endif
                            </span>
                            <span>{{ $post->published_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}</span>
                        </div>

                        @if($post->caption)
                            <button
                                type="button"
                                wire:click="openPostEngagementModal({{ $post->id }}, 'comments')"
                                class="mt-2 line-clamp-2 w-full text-left text-sm text-slate-700 hover:text-slate-950"
                            >
                                {{ $post->caption }}
                            </button>
                        @endif

                        <div class="mt-3 flex flex-wrap gap-2 text-sm font-semibold">
                            <button
                                type="button"
                                wire:click="openPostEngagementModal({{ $post->id }}, 'likes')"
                                class="rounded-lg border border-pink-200 bg-pink-50 px-2.5 py-1.5 text-pink-800 hover:bg-pink-100"
                            >
                                {{ $post->likes_count !== null ? number_format($post->likes_count) : '-' }} Likes
                                <span class="ml-1 text-xs font-normal text-pink-600">
                                    ({{ number_format((int) $storedLikesCount) }} gespeichert)
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="openPostEngagementModal({{ $post->id }}, 'comments')"
                                class="rounded-lg border border-violet-200 bg-violet-50 px-2.5 py-1.5 text-violet-800 hover:bg-violet-100"
                            >
                                {{ $post->comments_count !== null ? number_format($post->comments_count) : '-' }} Kommentare
                                <span class="ml-1 text-xs font-normal text-violet-600">
                                    ({{ number_format((int) $storedCommentsCount) }} gespeichert)
                                </span>
                            </button>
                        </div>

                        <div class="mt-1 text-xs text-slate-500">
                            {{ number_format($post->metrics_count ?? 0) }} gespeicherte Messpunkte
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                wire:click="openPostEngagementModal({{ $post->id }}, 'comments')"
                                class="text-xs font-semibold text-slate-700 hover:text-violet-800"
                            >
                                Details anzeigen
                            </button>
                            @if($post->post_url)
                                <a href="{{ $post->post_url }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-violet-700 hover:text-violet-900">
                                    Auf Instagram oeffnen
                                </a>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <p class="text-sm text-slate-500">{{ $emptyText }}</p>
            @endforelse
        </div>
    </div>
</section>
