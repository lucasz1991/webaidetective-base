<x-nav-link href="/tutor/dashboard" wire:navigate :active="request()->is('tutor/dashboard')">
    <svg class="mr-1 aspect-square w-5 max-md:mr-2 max-md:w-6" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" stroke="currentColor" fill="none" viewBox="0 0 24 24">
        <rect x="4" y="4" width="16" height="16" rx="2" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M8 8h8M8 12h8M8 16h4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    Dashboard
</x-nav-link>

<x-nav-link href="/tutor/tutor-courses" wire:navigate :active="request()->is('tutor/tutor-courses')">
    <svg class="mr-1 aspect-square w-5 max-md:mr-2 max-md:w-6" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" stroke="currentColor" fill="none" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l2.5 2.5M12 21a9 9 0 1 1 0-18 9 9 0 0 1 0 18Z"/>
    </svg>
    Meine Kurse
</x-nav-link>
