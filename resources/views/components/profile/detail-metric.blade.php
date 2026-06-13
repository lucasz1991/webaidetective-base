@props([
    'as' => 'div',
    'label',
    'value' => '-',
    'tone' => 'slate',
    'muted' => false,
])

@php
    $interactiveClass = match ($tone) {
        'emerald' => 'hover:bg-emerald-50 focus:ring-emerald-300',
        'sky' => 'hover:bg-sky-50 focus:ring-sky-300',
        'pink' => 'hover:bg-pink-50 focus:ring-pink-300',
        'violet' => 'hover:bg-violet-50 focus:ring-violet-300',
        default => 'hover:bg-slate-50 focus:ring-slate-300',
    };
    $hintClass = match ($tone) {
        'emerald' => 'text-emerald-700',
        'sky' => 'text-sky-700',
        'pink' => 'text-pink-700',
        'violet' => 'text-violet-700',
        default => 'text-slate-500',
    };
    $baseClass = 'min-w-0 px-3 py-3 text-left';
    $mutedClass = $muted ? ' opacity-60 grayscale-[50%]' : '';
@endphp

@if($as === 'button')
    <button
        type="button"
        {{ $attributes->class([$baseClass.' transition focus:outline-none focus:ring-2 focus:ring-inset '.$interactiveClass.$mutedClass]) }}
    >
        <div class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $label }}</div>
        <div class="mt-1 truncate text-base font-bold text-slate-950 sm:text-xl">{{ $value }}</div>
        @if($slot->isNotEmpty())
            <div class="mt-1 text-[10px] font-semibold {{ $muted ? 'text-slate-500' : $hintClass }}">
                {{ $slot }}
            </div>
        @endif
    </button>
@else
    <div {{ $attributes->class([$baseClass.$mutedClass]) }}>
        <div class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $label }}</div>
        <div class="mt-1 truncate text-base font-bold text-slate-950 sm:text-xl">{{ $value }}</div>
        @if($slot->isNotEmpty())
            <div class="mt-1 text-[10px] font-semibold {{ $muted ? 'text-slate-500' : $hintClass }}">
                {{ $slot }}
            </div>
        @endif
    </div>
@endif
