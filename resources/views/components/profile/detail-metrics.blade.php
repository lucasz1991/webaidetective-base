<div {{ $attributes->class(['w-full overflow-hidden rounded-3xl border border-white/80 bg-white/90 shadow-sm backdrop-blur']) }}>
    <div class="grid grid-cols-3 divide-x divide-slate-200 first:rounded-l-3xl last:rounded-r-3xl">
        {{ $slot }}
    </div>
</div>
