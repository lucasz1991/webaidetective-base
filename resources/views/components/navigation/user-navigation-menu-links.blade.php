@if (optional(Auth::user())->role === 'guest' || optional(Auth::user())->role === 'admin')
    <x-nav-link href="/user/dashboard" wire:navigate :active="request()->is('user/dashboard')">
        <svg class="mr-1 aspect-square w-5 max-md:mr-2 max-md:w-6" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 8.5 12 4l8 4.5m-14 0V18a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V8.5M9.5 20v-6h5v6"/>
        </svg>
        {{ __('Personen') }}
    </x-nav-link>
    <x-nav-link href="/user/network" wire:navigate :active="request()->is('user/network')">
        <svg class="mr-1 aspect-square w-5 max-md:mr-2 max-md:w-6" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7a3 3 0 1 1 2.83 4M16 7a3 3 0 1 0-2.83 4M6 18a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm12 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-9.1-1.55 6.2-2.9M8.9 13.55l6.2 2.9M10.7 8.15h2.6"/>
        </svg>
        {{ __('Netzwerk') }}
    </x-nav-link>
@endif
