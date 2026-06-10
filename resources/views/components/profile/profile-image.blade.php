@props([
    'src' => null,
    'alt' => 'Profilbild',
    'initial' => '?',
    'visibility' => 'unknown',
    'size' => 'md',
])

@php
    $sizeClass = match ($size) {
        'sm' => 'h-10 w-10 text-xs',
        'lg' => 'h-14 w-14 text-base',
        'xl' => 'h-20 w-20 text-xl',
        default => 'h-12 w-12 text-sm',
    };
    $ringClass = match ($visibility) {
        'public' => 'ring-2 ring-emerald-400 border-emerald-100',
        'private' => 'ring-2 ring-slate-400 border-slate-100',
        default => 'ring-2 ring-slate-300 border-slate-100',
    };
    $initial = strtoupper(substr((string) $initial, 0, 1) ?: '?');
@endphp

<div {{ $attributes->merge(['class' => $sizeClass.' shrink-0 overflow-hidden rounded-full border bg-slate-100 shadow-sm '.$ringClass]) }}>
    @if($src)
        <img src="{{ $src }}" alt="{{ $alt }}" class="h-full w-full object-cover">
    @else
        <div class="flex h-full w-full items-center justify-center font-bold text-slate-500">
            {{ $initial }}
        </div>
    @endif
</div>
