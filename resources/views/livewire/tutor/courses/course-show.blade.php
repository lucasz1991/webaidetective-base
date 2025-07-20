<div class="">
    <div class=" space-y-8 ">

        <!-- Titel und Zeitraum -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-2xl font-bold text-gray-800">{{ $course->title }}</h1>
            <div class="text-sm text-gray-500">
                {{ $course->start_time?->format('d.m.Y') }} – {{ $course->end_time?->format('d.m.Y') }}
            </div>
        </div>

        <!-- Kursinfos -->
        <div class="grid sm:grid-cols-2 gap-4 text-gray-700">
            <div>
                <p class="text-sm"><strong class="text-gray-600">Beschreibung:</strong></p>
                <p class="text-sm text-gray-600 mt-1">{{ $course->description ?: '—' }}</p>
            </div>
        </div>

        <!-- Teilnehmer -->
        <div>
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Teilnehmer ({{ $course->participants->count() }})</h2>
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-100">
                @if($course->participants->count())
                    <ul class="list-disc list-inside text-sm text-gray-700 space-y-1">
                        @foreach($course->participants as $participant)
                            <li>{{ $participant->name }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-500">Keine Teilnehmer zugewiesen.</p>
                @endif
            </div>
        </div>

        <!-- Unterrichtstage -->
        <div>
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Unterrichtstage</h2>
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-100">
                @if($course->days->count())
                    <ul class="list-disc list-inside text-sm text-gray-700 space-y-1">
                        @foreach($course->days as $day)
                            <li>{{ \Carbon\Carbon::parse($day->date)->format('d.m.Y') }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-500">Keine Termine vorhanden.</p>
                @endif
            </div>
        </div>

    </div>
</div>
