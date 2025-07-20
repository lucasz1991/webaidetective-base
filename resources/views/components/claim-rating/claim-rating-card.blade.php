<div class="bg-white p-4 rounded shadow-xl  border  border-gray-200 h-full" >
    @if(!str_contains(request()->path(), 'insurance'))
        <div>
            <div class="flex gap-4 overflow-hidden">
                <div class="w-14 flex-none ">
                    <div class=" w-min rounded-xl flex items-center justify-center text-white text-base  px-2 " style="background-color: {{ $rating->insurance->color ?? '#ccc' }};">
                        {{ strtoupper(substr( $rating->insurance->initials, 0 ,4)) }}
                    </div>
                </div>
                <div class="grow">
                    <h2 class="text-base break-words  truncate text-ellipsis">
                        {{ substr( $rating->insurance->name, 0 ,35) }}
                    </h2>
                </div>
            </div>
        </div>
        <hr class="my-2">
    @endif
    <div class="flex justify-between items-center">
        <x-insurance.insurance-rating-stars :score="$rating->rating_score" />
        <div class="text-sm text-gray-500">
             {{ \Carbon\Carbon::parse($rating->created_at)->format('d.m.Y') }}
        </div>
    </div>
    <div class="mt-2 text-gray-800 max-h-16 truncate text-ellipsis">
    {{ $rating->attachments['scorings']['ai_overall_comment'] ?? '' }}
    </div>
    <div class="mt-3">
        <a href="{{ route('review.show', $rating->id) }}" class="text-blue-600 hover:underline">
            mehr lesen …
        </a>

    </div>
</div>