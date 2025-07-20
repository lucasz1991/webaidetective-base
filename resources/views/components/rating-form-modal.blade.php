@props(['id' => null, 'maxWidth' => null])
@php
$id = $id ?? md5($attributes->wire('model'));
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
    '4xl' => 'sm:max-w-4xl',
    '5xl' => 'sm:max-w-5xl',
][$maxWidth ?? '5xl'];
@endphp
<div
    x-data="{ show: @entangle($attributes->wire('model')) }"
    x-show="show"
    id="{{ $id }}"
    class="jetstream-modal fixed inset-0 overflow-y-auto px-4 py-6  z-50 "
    style="display: none; z-index: 9999 !important;"
    {{ $attributes }} >
    <div x-show="show" class="fixed inset-0 transform transition-all"  
    x-trap.inert.noscroll="show"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0">
        <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
    </div>
    <div x-show="show" class="my-12 top-12 bg-white rounded-lg  shadow-xl transform transition-all container {{ $maxWidth }} mx-auto relative select-none"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
            <div class=" right-2 top-2 absolute">
                <button type="button" class="text-red-900 hover:text-red-700" @click="show = false">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        <div class="px-6 py-4">
            <div class="mt-4 text-sm text-gray-600">
                {{ $content }}
            </div>
        </div>
    </div>
</div>