@props(['showText' => true])

<div {{ $attributes->merge(['class' => 'inline-flex items-center gap-3']) }}>
    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white shadow-sm">
        <img
            class="h-8 w-8 object-contain"
            src="{{ asset('site-images/logo-icon.png') }}"
            alt="webaiDetective Logo"
        >
    </span>

    @if ($showText)
        <span class="leading-none">
            <span class="block text-base font-bold tracking-tight text-slate-950">webaiDetective</span>
            <span class="mt-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Social Intelligence</span>
        </span>
    @endif
</div>
