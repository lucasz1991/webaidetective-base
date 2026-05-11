@if (optional(Auth::user())->role === 'guest' || optional(Auth::user())->role === 'admin')
    <x-nav-link href="/user/dashboard" wire:navigate :active="request()->is('user/dashboard')">
        <svg class="mr-1 aspect-square w-5 max-md:mr-2 max-md:w-6" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 8.5 12 4l8 4.5m-14 0V18a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V8.5M9.5 20v-6h5v6"/>
        </svg>
        {{ __('Personen') }}
    </x-nav-link>
@endif
