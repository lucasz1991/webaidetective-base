@props([
    'posts',
    'title' => 'Instagram-Beitraege',
    'lastScanAt' => null,
    'emptyText' => 'Noch keine Instagram-Beitraege gespeichert.',
    'showHeader' => true,
])

@php
    $postCount = $posts instanceof \Illuminate\Support\Collection
        ? $posts->count()
        : collect($posts)->count();
@endphp

<section class="bg-white">

    @if($showHeader)
        <div class="flex justify-center border-b border-slate-200">
            <div class="-mb-px inline-flex items-center gap-2 border-t border-slate-950 px-6 py-3 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-950">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <rect x="4" y="4" width="6" height="6" stroke="currentColor" stroke-width="2"/>
                    <rect x="14" y="4" width="6" height="6" stroke="currentColor" stroke-width="2"/>
                    <rect x="4" y="14" width="6" height="6" stroke="currentColor" stroke-width="2"/>
                    <rect x="14" y="14" width="6" height="6" stroke="currentColor" stroke-width="2"/>
                </svg>
                Posts&nbsp;{{ number_format($postCount, 0, ',', '.') }}
            </div>
        </div>
    @endif

    @if($postCount > 0)
        <div class="mt-1 grid grid-cols-3 gap-0.5 sm:gap-1">
            @foreach($posts as $post)
                @php
                    $primaryMedia = $post->media->first();
                    $mediaUrl = $primaryMedia?->media_url;
                    $previewUrl = $primaryMedia?->preview_media_url ?: $post->thumbnail_storage_url;
                    $imageUrl = $primaryMedia?->media_type === 'video'
                        ? ($previewUrl ?: $mediaUrl)
                        : ($mediaUrl ?: $previewUrl);
                    $storedLikesCount = (int) ($post->stored_likes_count ?? 0);
                    $storedCommentsCount = (int) ($post->stored_comments_count ?? 0);
                    $likesCount = $post->likes_count !== null ? (int) $post->likes_count : null;
                    $commentsCount = $post->comments_count !== null ? (int) $post->comments_count : null;
                    $publishedAt = $post->published_at?->timezone(config('app.timezone'))->format('d.m.Y H:i');
                    $postLabel = trim(($post->shortcode ? 'Beitrag '.$post->shortcode : 'Instagram-Beitrag').' '.($publishedAt ? 'vom '.$publishedAt : ''));
                @endphp

                <article class="group relative aspect-square overflow-hidden bg-slate-100 text-white">
                    @if($imageUrl)
                        <img src="{{ $imageUrl }}" alt="{{ $postLabel }}" loading="lazy" class="h-full w-full object-cover transition duration-200 group-hover:scale-[1.02]">
                    @elseif($primaryMedia?->media_type === 'video' && $mediaUrl)
                        <video preload="metadata" playsinline muted class="h-full w-full bg-black object-cover">
                            <source src="{{ $mediaUrl }}" type="{{ $primaryMedia->mime_type ?: 'video/mp4' }}">
                        </video>
                    @else
                        <div class="flex h-full w-full items-center justify-center bg-slate-100 text-center text-[11px] font-semibold text-slate-500 sm:text-sm">
                            Keine Medienvorschau
                        </div>
                    @endif

                    @if($primaryMedia?->media_type === 'video' || (int) $post->media_count > 1)
                        <div class="pointer-events-none absolute right-2 top-2 z-30 flex items-center gap-1 text-white drop-shadow">
                            @if((int) $post->media_count > 1)
                                <span title="{{ number_format((int) $post->media_count, 0, ',', '.') }} Medien" aria-label="{{ number_format((int) $post->media_count, 0, ',', '.') }} Medien">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <rect x="7" y="5" width="12" height="12" rx="1.5" stroke="currentColor" stroke-width="2"/>
                                        <path d="M5 8v10a1 1 0 0 0 1 1h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                </span>
                            @endif
                            @if($primaryMedia?->media_type === 'video')
                                <span title="Video" aria-label="Video">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M8 5.5v13l10-6.5-10-6.5Z"/>
                                    </svg>
                                </span>
                            @endif
                        </div>
                    @endif

                    <button
                        type="button"
                        wire:click="openPostEngagementModal({{ $post->id }}, 'comments')"
                        class="absolute inset-0 z-10 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-white"
                        aria-label="{{ $postLabel }} oeffnen"
                    >
                        <span class="sr-only">{{ $postLabel }} oeffnen</span>
                    </button>

                    <div class="pointer-events-none absolute inset-0 z-20 flex items-center justify-center bg-black/55 opacity-0 transition duration-150 group-hover:opacity-100 group-focus-within:opacity-100">
                        <div class="pointer-events-auto flex items-center gap-5 text-sm font-bold sm:gap-7 sm:text-base">
                            <button
                                type="button"
                                wire:click="openPostEngagementModal({{ $post->id }}, 'likes')"
                                class="inline-flex items-center gap-1.5 rounded-md px-1 py-1 text-white outline-none focus-visible:ring-2 focus-visible:ring-white"
                                title="{{ $storedLikesCount > 0 ? number_format($storedLikesCount, 0, ',', '.').' Likes gespeichert' : 'Likes anzeigen' }}"
                                aria-label="Likes fuer {{ $postLabel }} anzeigen"
                            >
                                <svg class="h-5 w-5 fill-white" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M12 21s-7-4.4-9.2-8.7C.8 8.4 3.1 4.8 6.7 4.8c2 0 3.4 1 4.3 2.2.9-1.2 2.3-2.2 4.3-2.2 3.6 0 5.9 3.6 3.9 7.5C19 16.6 12 21 12 21Z"/>
                                </svg>
                                <span>{{ $likesCount !== null ? number_format($likesCount, 0, ',', '.') : '-' }}</span>
                            </button>

                            <button
                                type="button"
                                wire:click="openPostEngagementModal({{ $post->id }}, 'comments')"
                                class="inline-flex items-center gap-1.5 rounded-md px-1 py-1 text-white outline-none focus-visible:ring-2 focus-visible:ring-white"
                                title="{{ $storedCommentsCount > 0 ? number_format($storedCommentsCount, 0, ',', '.').' Kommentare gespeichert' : 'Kommentare anzeigen' }}"
                                aria-label="Kommentare fuer {{ $postLabel }} anzeigen"
                            >
                                <svg class="h-5 w-5 fill-white" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M20 4H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h3.8l3.4 3.1a1.2 1.2 0 0 0 1.6 0l3.4-3.1H20a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Z"/>
                                </svg>
                                <span>{{ $commentsCount !== null ? number_format($commentsCount, 0, ',', '.') : '-' }}</span>
                            </button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="mt-4 grid grid-cols-3 gap-0.5 sm:gap-1">
            <div class="aspect-square bg-slate-100"></div>
            <div class="flex aspect-square items-center justify-center bg-slate-50 px-3 text-center text-xs font-semibold text-slate-500 sm:text-sm">
                {{ $emptyText }}
            </div>
            <div class="aspect-square bg-slate-100"></div>
        </div>
    @endif
</section>
