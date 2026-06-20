@props([
    'selectedPost' => null,
    'selectedPostId' => null,
    'activeTab' => 'comments',
    'model' => 'showPostEngagementModal',
])

<x-modal wire:model="{{ $model }}" maxWidth="6xl">
    <div
        wire:key="post-engagement-{{ $selectedPostId ?: 'none' }}"
        x-data="{ tab: @js($activeTab), search: '' }"
        class="flex max-h-[calc(100vh-2rem)] flex-col overflow-hidden bg-white sm:max-h-[88vh]"
    >
        <div class="flex flex-col gap-3 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-start sm:justify-between sm:px-5 sm:py-4">
            <div>
                <h3 class="text-lg font-bold text-slate-900">Beitragsdetails</h3>
                <p class="mt-1 text-sm text-slate-500">
                    @if($selectedPost)
                        Beitrag {{ $selectedPost->shortcode }}
                        &middot; {{ number_format($selectedPost->likes->count()) }} Likes gespeichert
                        &middot; {{ number_format($selectedPost->comments->count()) }} Kommentare gespeichert
                    @else
                        Kein Beitrag ausgewaehlt.
                    @endif
                </p>
            </div>
            <button
                type="button"
                x-on:click="$dispatch('close')"
                class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            >
                Schliessen
            </button>
        </div>

        @if($selectedPost)
            @php
                $primaryMedia = $selectedPost->media->first();
                $mediaUrl = $primaryMedia?->media_url;
                $previewUrl = $primaryMedia?->preview_media_url ?: $selectedPost->thumbnail_storage_url;
            @endphp

            <div class="grid min-h-0 flex-1 lg:grid-cols-[minmax(0,1.05fr)_minmax(22rem,0.95fr)]">
                <div class="flex min-h-[18rem] items-center justify-center bg-slate-950">
                    @if($primaryMedia?->media_type === 'video' && $mediaUrl)
                        <video controls preload="metadata" playsinline poster="{{ $previewUrl }}" class="max-h-[70vh] w-full object-contain">
                            <source src="{{ $mediaUrl }}" type="{{ $primaryMedia->mime_type ?: 'video/mp4' }}">
                        </video>
                    @elseif($mediaUrl || $previewUrl)
                        <img src="{{ $mediaUrl ?: $previewUrl }}" alt="Instagram-Beitrag {{ $selectedPost->shortcode }}" class="max-h-[70vh] w-full object-contain">
                    @else
                        <div class="text-sm font-semibold text-slate-300">Keine Medienvorschau gespeichert.</div>
                    @endif
                </div>

                <div class="flex min-h-0 flex-col border-t border-slate-200 lg:border-l lg:border-t-0">
                    <div class="border-b border-slate-200 px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <span>{{ $selectedPost->media_type ?: 'post' }}</span>
                            @if($selectedPost->media_count > 1)
                                <span>&middot; {{ number_format($selectedPost->media_count) }} Medien</span>
                            @endif
                            <span>&middot; {{ $selectedPost->published_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '-' }}</span>
                        </div>
                        @if($selectedPost->caption)
                            <p class="mt-2 max-h-36 overflow-y-auto whitespace-pre-line text-sm leading-6 text-slate-800">{{ $selectedPost->caption }}</p>
                        @endif
                        @if($selectedPost->post_url)
                            <a href="{{ $selectedPost->post_url }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex text-xs font-semibold text-violet-700 hover:text-violet-900">
                                Auf Instagram oeffnen
                            </a>
                        @endif
                    </div>

                    <div class="border-b border-slate-200 px-4 py-3">
                        <div class="flex flex-col gap-3">
                            <div class="flex rounded-xl bg-slate-100 p-1">
                                <button
                                    type="button"
                                    x-on:click="tab = 'likes'"
                                    x-bind:class="tab === 'likes' ? 'bg-white text-pink-700 shadow-sm' : 'text-slate-600'"
                                    class="flex-1 rounded-lg px-3 py-2 text-sm font-semibold"
                                >
                                    Likes {{ number_format($selectedPost->likes->count()) }}
                                </button>
                                <button
                                    type="button"
                                    x-on:click="tab = 'comments'"
                                    x-bind:class="tab === 'comments' ? 'bg-white text-violet-700 shadow-sm' : 'text-slate-600'"
                                    class="flex-1 rounded-lg px-3 py-2 text-sm font-semibold"
                                >
                                    Kommentare {{ number_format($selectedPost->comments->count()) }}
                                </button>
                            </div>
                            <input
                                type="search"
                                x-model.debounce.150ms="search"
                                class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-violet-500 focus:outline-none focus:ring-1 focus:ring-violet-500"
                                placeholder="Nutzer oder Kommentar durchsuchen..."
                            >
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-4">
                        <div x-show="tab === 'likes'" class="space-y-2">
                            @forelse($selectedPost->likes as $like)
                                @php($likeSearch = strtolower(trim(($like->username ?? '').' '.($like->full_name ?? ''))))
                                <div
                                    x-show="search === '' || @js($likeSearch).includes(search.toLowerCase())"
                                    @class([
                                        'flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-3',
                                        'opacity-70' => ! $like->is_active,
                                    ])
                                >
                                    @if($like->profile_image_url)
                                        <img src="{{ $like->profile_image_url }}" alt="" class="h-10 w-10 rounded-full object-cover">
                                    @else
                                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-pink-100 font-bold text-pink-700">
                                            {{ strtoupper(substr($like->username ?: '?', 0, 1)) }}
                                        </div>
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <a
                                            href="https://www.instagram.com/{{ $like->username }}/"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="font-semibold text-slate-900 hover:text-pink-700"
                                        >
                                            {{ $like->username ? '@'.$like->username : $like->instagram_user_id }}
                                        </a>
                                        @if($like->full_name)
                                            <div class="truncate text-sm text-slate-500">{{ $like->full_name }}</div>
                                        @endif
                                    </div>
                                    <div class="shrink-0 text-right">
                                        @unless($like->is_active)
                                            <div class="mb-1 rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 ring-1 ring-rose-200">
                                                entfernt
                                            </div>
                                        @endunless
                                        <span class="text-xs text-slate-400">{{ ($like->removed_at ?: $like->last_seen_at)?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm text-slate-500">Noch keine einzelnen Likes gespeichert.</p>
                            @endforelse
                        </div>

                        <div x-show="tab === 'comments'" class="space-y-3">
                            @forelse($selectedPost->comments as $comment)
                                @php($commentSearch = strtolower(trim(($comment->username ?? '').' '.($comment->full_name ?? '').' '.$comment->comment_text)))
                                <div
                                    x-show="search === '' || @js($commentSearch).includes(search.toLowerCase())"
                                    @class([
                                        'rounded-xl border border-slate-200 bg-white p-3',
                                        'ml-6 border-l-4 border-l-violet-300' => $comment->parent_comment_id,
                                        'opacity-70' => ! $comment->is_active,
                                    ])
                                >
                                    <div class="flex items-start gap-3">
                                        @if($comment->profile_image_url)
                                            <img src="{{ $comment->profile_image_url }}" alt="" class="h-10 w-10 rounded-full object-cover">
                                        @else
                                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-violet-100 font-bold text-violet-700">
                                                {{ strtoupper(substr($comment->username ?: '?', 0, 1)) }}
                                            </div>
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <a
                                                    href="https://www.instagram.com/{{ $comment->username }}/"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="font-semibold text-slate-900 hover:text-violet-700"
                                                >
                                                    {{ $comment->username ? '@'.$comment->username : $comment->instagram_user_id }}
                                                </a>
                                                @if($comment->published_at)
                                                    <span class="text-xs text-slate-400">{{ $comment->published_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                                                @endif
                                                @unless($comment->is_active)
                                                    <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 ring-1 ring-rose-200">
                                                        entfernt
                                                    </span>
                                                @endunless
                                            </div>
                                            <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $comment->comment_text }}</p>
                                            @if($comment->likes_count !== null)
                                                <div class="mt-1 text-xs font-semibold text-pink-700">{{ number_format($comment->likes_count) }} Likes</div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm text-slate-500">Noch keine einzelnen Kommentare gespeichert.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-modal>
