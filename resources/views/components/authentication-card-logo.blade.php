@props(['showText' => true])

<div {{ $attributes->merge(['class' => 'inline-flex items-center gap-3']) }}>
    <x-application-mark class="h-11 w-11" />

    @if ($showText)
        <span class="leading-none">
            <span class="block text-lg font-black tracking-tight text-slate-950">SocialScope</span>
            <span class="mt-1.5 block text-[10px] font-bold uppercase tracking-[.2em] text-slate-500">Social Intelligence</span>
        </span>
    @endif
</div>
