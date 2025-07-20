<a href="/insurance/{{ $insurance->id }}" wire:navigate class="block">
    <div class="bg-white rounded-lg border border-gray-200 shadow  transition-shadow duration-300 p-4 flex flex-col justify-between h-full  hover:shadow-lg ">
        <div class="flex gap-4 mb-4">
            <div class="size-14 flex-none ">
                <div class=" w-min rounded-xl flex items-center justify-center text-white text-base font-bold px-2" style="background-color: {{ $insurance->color ?? '#ccc' }};">
                    {{ strtoupper(substr( $insurance->initials, 0 ,4)) }}
                </div>
            </div>
            <div class="grow">
                <h2 class="text-xl break-words font-semibold mb-2">
                    {{ substr( $insurance->name, 0 ,25) }}
                </h2>
            </div>
        </div>
        <div class="flex items-center justify-between">
            <div>
                @if($insurance->claim_ratings_count > 0)
                    <x-insurance.insurance-rating-stars :score="$insurance->claim_ratings_avg_rating_score" />
                @else
                    <span class="text-gray-500">Keine Bewertungen</span>
                @endif
            </div>
            <div>
                    <span class="font-sm text-gray-700 p-1 px-2 bg-slate-100 rounded-lg">Ø Dauer: {{ $insurance->avgRatingDuration() ?? 29 }} Tage</span>
            </div>
        </div>
    </div>
</a>
