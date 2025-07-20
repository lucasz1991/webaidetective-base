<div class="">
    @if($courses->count())
        <div class="mb-4">
            <span class="inline-block bg-blue-100 text-blue-700 text-xs font-semibold px-3 py-1 rounded-full">
                {{ $courses->count() }} Kurs{{ $courses->count() === 1 ? '' : 'e' }}
            </span>
        </div>
    @endif
    <div class="grid gap-4">
        @forelse ($courses as $course)
            <a href="{{ route('tutor.courses.show', ['courseId' => $course->id]) }}" class="block bg-white border border-gray-200 rounded-lg p-4 shadow-sm hover:shadow-md transition hover:bg-gray-50">
                <div class="flex justify-between items-start">
                    <div>
                        <h3 class="text-md  text-blue-700">{{ $course->title }}</h3>
                        @php
                            $now = \Carbon\Carbon::now();
                            $start = \Carbon\Carbon::parse($course->start_time);
                            $end = \Carbon\Carbon::parse($course->end_time);
                            $isRunning = $now->between($start, $end);
                        @endphp
                        <p class="text-xs text-gray-500 mt-2">
                            <span class="inline-block {{ $isRunning ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-700' }} text-xs font-semibold px-3 py-1 rounded-full">
                                {{ $start->isoFormat('ll') }} –
                                {{ $end->isoFormat('ll') }}
                            </span>
                        </p>
                    </div>
                    <div class="text-sm text-gray-400">
                        <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full">#{{ $course->id }}</span>
                    </div>
                </div>
            </a>
        @empty
            <p class="text-sm text-gray-500 col-span-2">Aktuell sind dir keine Kurse zugewiesen.</p>
        @endforelse
    </div>
</div>
