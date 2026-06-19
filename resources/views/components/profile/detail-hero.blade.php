@props([
    'id' => null,
])

<section
    @if($id) id="{{ $id }}" @endif
    {{ $attributes->class([
        'scroll-mt-4 rounded-3xl border border-slate-200 bg-white shadow-sm',
    ]) }}
>
    <div class="rounded-t-3xl bg-gradient-to-r from-rose-50 via-slate-50 to-slate-100 px-4 py-4 text-slate-950 sm:px-5">
        @isset($toolbar)
            <div class="relative z-30 mb-4 flex flex-wrap items-center justify-between gap-3">
                {{ $toolbar }}
            </div>
        @endisset

        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
                {{ $identity }}
            </div>

            @isset($metrics)
                <div class="w-full shrink-0 lg:w-[min(58vw,24rem)]">
                    <x-profile.detail-metrics>
                        {{ $metrics }}
                    </x-profile.detail-metrics>
                </div>
            @endisset
        </div>

        @isset($badges)
            <div class="mt-4 flex flex-wrap items-center gap-2">
                {{ $badges }}
            </div>
        @endisset
    </div>

    <div class="">
        {{ $slot }}
    </div>
</section>
