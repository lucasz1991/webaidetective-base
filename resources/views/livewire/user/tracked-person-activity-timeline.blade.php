@php
    $toneClasses = [
        'amber' => [
            'badge' => 'bg-amber-50 text-amber-800 ring-amber-200',
            'marker' => 'bg-amber-500',
            'initial' => 'K',
        ],
        'emerald' => [
            'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'marker' => 'bg-emerald-500',
            'initial' => 'F',
        ],
        'indigo' => [
            'badge' => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
            'marker' => 'bg-indigo-500',
            'initial' => 'G',
        ],
        'pink' => [
            'badge' => 'bg-pink-50 text-pink-700 ring-pink-200',
            'marker' => 'bg-pink-500',
            'initial' => 'L',
        ],
        'rose' => [
            'badge' => 'bg-rose-50 text-rose-700 ring-rose-200',
            'marker' => 'bg-rose-500',
            'initial' => '!',
        ],
        'sky' => [
            'badge' => 'bg-sky-50 text-sky-700 ring-sky-200',
            'marker' => 'bg-sky-500',
            'initial' => 'P',
        ],
        'slate' => [
            'badge' => 'bg-slate-100 text-slate-700 ring-slate-200',
            'marker' => 'bg-slate-500',
            'initial' => 'A',
        ],
        'violet' => [
            'badge' => 'bg-violet-50 text-violet-700 ring-violet-200',
            'marker' => 'bg-violet-500',
            'initial' => 'B',
        ],
    ];
@endphp

<section wire:poll.visible.15000ms class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Aktivitaeten</p>
            <h2 class="mt-1 text-lg font-bold text-slate-950">Chronologische Timeline</h2>
            <p class="mt-1 text-sm text-slate-600">
                Likes, Kommentare, Listen-Aenderungen, Profil- und Beitragsdaten aus gespeicherten Instagram-Scans.
            </p>
        </div>
        <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
            {{ number_format($totalActivityCount, 0, ',', '.') }} Ereignisse
        </span>
    </div>

    @unless($trackedPerson)
        <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
            Fuer diese Person konnten keine Aktivitaeten geladen werden.
        </div>
    @else
        <div class="mt-4 flex gap-2 overflow-x-auto pb-1">
            @foreach($filterOptions as $filterOption)
                <button
                    type="button"
                    wire:click="setTypeFilter('{{ $filterOption['key'] }}')"
                    class="inline-flex shrink-0 items-center gap-2 rounded-2xl border px-3 py-2 text-xs font-bold transition {{ $typeFilter === $filterOption['key'] ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}"
                >
                    <span>{{ $filterOption['label'] }}</span>
                    <span class="rounded-full px-2 py-0.5 text-[11px] {{ $typeFilter === $filterOption['key'] ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-500' }}">
                        {{ number_format($filterOption['count'], 0, ',', '.') }}
                    </span>
                </button>
            @endforeach
        </div>

        @if($activities->isEmpty())
            <div class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-600">
                Noch keine passenden Aktivitaeten gefunden.
            </div>
        @else
            <div class="mt-5 flow-root">
                <ol class="-mb-6">
                    @foreach($activities as $activity)
                        @php
                            $classes = $toneClasses[$activity['tone']] ?? $toneClasses['slate'];
                        @endphp
                        <li wire:key="tracked-person-activity-{{ $activity['id'] }}" class="relative pb-6">
                            @unless($loop->last)
                                <span class="absolute left-5 top-10 -ml-px h-full w-0.5 bg-slate-200" aria-hidden="true"></span>
                            @endunless

                            <div class="relative flex gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl text-xs font-black text-white shadow-sm {{ $classes['marker'] }}">
                                    {{ $classes['initial'] }}
                                </div>

                                <article class="min-w-0 flex-1 rounded-2xl border border-slate-200 bg-slate-50/70 p-3">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h3 class="text-sm font-bold text-slate-950">{{ $activity['title'] }}</h3>
                                                <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $classes['badge'] }}">
                                                    {{ $activity['type_label'] }}
                                                </span>
                                            </div>
                                            @if($activity['summary'])
                                                <p class="mt-1 break-words text-sm leading-6 text-slate-700">{{ $activity['summary'] }}</p>
                                            @endif
                                            @if($activity['meta'])
                                                <p class="mt-1 text-xs font-semibold text-slate-500">{{ $activity['meta'] }}</p>
                                            @endif
                                        </div>

                                        <time class="shrink-0 text-right text-xs font-semibold text-slate-500" datetime="{{ $activity['date']->toIso8601String() }}">
                                            {{ $activity['date']->format('d.m.Y') }}<br>
                                            {{ $activity['date']->format('H:i') }}
                                        </time>
                                    </div>

                                    @if($activity['image_url'] || $activity['url'])
                                        <div class="mt-3 flex flex-wrap items-center gap-3">
                                            @if($activity['image_url'])
                                                <img
                                                    src="{{ $activity['image_url'] }}"
                                                    alt=""
                                                    class="h-12 w-12 rounded-xl border border-white bg-white object-cover shadow-sm"
                                                    loading="lazy"
                                                >
                                            @endif

                                            @if($activity['url'])
                                                <a
                                                    href="{{ $activity['url'] }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100"
                                                >
                                                    Quelle oeffnen
                                                </a>
                                            @endif
                                        </div>
                                    @endif
                                </article>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>

            @if($hasMoreActivities)
                <div class="mt-2 flex justify-center">
                    <button
                        type="button"
                        wire:click="loadMore"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60"
                    >
                        Mehr laden
                    </button>
                </div>
            @endif
        @endif
    @endunless
</section>
