@props([
    'src' => null,
    'alt' => 'Profilbild',
    'initial' => 'IG',
    'eyebrow' => 'Instagram-Profil',
    'title',
    'subtitle' => null,
    'biography' => null,
    'frameClass' => 'border-slate-200',
    'statusDotClass' => null,
    'statusLabel' => null,
    'mutedImage' => false,
])

@php
    $initial = strtoupper(substr((string) $initial, 0, 2) ?: 'IG');
@endphp

<div class="flex min-w-0 items-center gap-4">
    <div class="relative h-20 w-20 shrink-0">
        <div
            class="h-20 w-20 overflow-hidden rounded-full border-2 bg-slate-100 shadow-sm {{ $frameClass }}"
            @if($statusLabel) title="{{ $statusLabel }}" @endif
        >
            @if($src)
                <img
                    src="{{ $src }}"
                    alt="{{ $alt }}"
                    @class([
                        'h-full w-full object-cover',
                        'grayscale-[50%]' => $mutedImage,
                    ])
                >
            @else
                <div class="flex h-full w-full items-center justify-center text-sm font-semibold text-slate-500">
                    {{ $initial }}
                </div>
            @endif
        </div>

        @if($statusDotClass)
            <span
                class="absolute bottom-0 right-0 h-5 w-5 rounded-full border-2 border-white {{ $statusDotClass }}"
                @if($statusLabel) title="{{ $statusLabel }}" @endif
            ></span>
        @endif
    </div>

    <div class="min-w-0">
        <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-slate-500">{{ $eyebrow }}</p>
        <h1 class="mt-2 break-words text-2xl font-semibold tracking-tight text-slate-950">
            {{ $title }}
        </h1>
        @if($subtitle)
            <p class="mt-1 break-words text-sm font-semibold text-slate-500">{{ $subtitle }}</p>
        @endif
        @if($biography)
            <p class="mt-3 max-w-2xl whitespace-pre-line break-words text-sm leading-6 text-slate-700">{{ $biography }}</p>
        @endif
    </div>
</div>
