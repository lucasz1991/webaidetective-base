
        <x-nav-link href="/tutor-dashboard" wire:navigate  :active="request()->is('/tutor-dashboard')">
                <svg class="w-5 max-md:w-6 aspect-square mr-1 max-md:mr-2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                    <rect x="4" y="4" width="16" height="16" rx="2" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M8 8h8M8 12h8M8 16h4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            Dashboard
        </x-nav-link>
        <x-nav-link href="/tutor-courses" wire:navigate  :active="request()->is('/tutor-courses')">
                <svg class="w-5 max-md:w-6 aspect-square mr-1 max-md:mr-2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l2.5 2.5M12 21a9 9 0 1 1 0-18 9 9 0 0 1 0 18Z"/>
                </svg>
            Meine Kurse 
        </x-nav-link>
        <x-nav-link href="/makeup-exam-create" wire:navigate  :active="request()->is('/makeup-exam-create')">
                <svg class="w-5 max-md:w-6 aspect-square mr-1 max-md:mr-2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                    <rect x="4" y="3" width="16" height="18" rx="2" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M8 7h8M8 11h8M8 15h4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M16 3v2M8 3v2" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            Kalender
        </x-nav-link>
        <x-nav-link href="/makeup-exam-create" wire:navigate  :active="request()->is('/makeup-exam-create')">
                <svg class="w-5 max-md:w-6 aspect-square mr-1 max-md:mr-2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                    <rect x="4" y="3" width="16" height="18" rx="2" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M8 7h8M8 11h8M8 15h4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M16 3v2M8 3v2" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            News
        </x-nav-link>
        
        @php
            $isActive = request()->is('aboutus', 'faqs', 'howto', 'contact');
        @endphp
        <div x-data="{ openaboutus: false }" @click.away="openaboutus = false"   class="relative md:px-1 pt-1 border-b  text-sm font-medium leading-5  focus:outline-none focus:text-gray-700 focus:border-gray-300 transition duration-150 ease-in-out {{ $isActive ? 'md:border-primary-500 text-gray-900' : 'text-gray-500 hover:text-gray-700 border-transparent' }}" >
            <div class="flex items-center cursor-pointer max-md:text-lg max-md:px-3" @click="openaboutus = !openaboutus">
                <svg class="w-5 max-md:w-6 aspect-square mr-1 max-md:mr-2 " aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                    <path stroke="currentColor" stroke-linecap="round" stroke-width="1.5" d="M16 19h4a1 1 0 0 0 1-1v-1a3 3 0 0 0-3-3h-2m-2.236-4a3 3 0 1 0 0-4M3 18v-1a3 3 0 0 1 3-3h4a3 3 0 0 1 3 3v1a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Zm8-10a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                </svg>
                    {{ __('Informationen') }}
                <svg class="w-4 h-4 ml-2  transition-all ease-in duration-200" :class="openaboutus ? 'transform rotate-180' : ''" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m19 9-7 7-7-7"/>
                </svg>
            </div>
            <div x-show="openaboutus" x-transition 
                x-cloak 
                class=" md:border-b md:border-gray-200 md:bg-white md:shadow-lg  max-md:mt-3 " 
                :class="isMobile ? 'relative   z-30' : 'fixed w-screen  z-10 overflow-hidden left-0 right-0 -top-[200%] opacity-0 transition-all duration-300 ease-in-out '"
                :style="!isMobile && openaboutus ? 'top: ' + navHeight + 'px; opacity:1;' : ''" 
                >
                <ul class=" max-md:space-y-4 max-md:pt-4 text-sm text-gray-500 hover:text-gray-700" :class="isMobile ? '' : 'py-4 container mx-auto flex flex-col md:justify-center md:flex-row md:space-x-8'">
                    <li >
                        <a  href="/faqs" wire:navigate  class='max-md:text-lg max-md:px-3 max-md:rounded-lg flex items-center md:px-4 py-2 hover:bg-gray-100'>
                            <svg class="w-5 max-md:w-6 aspect-square mr-1 max-md:mr-2 " aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.529 9.988a2.502 2.502 0 1 1 5 .191A2.441 2.441 0 0 1 12 12.582V14m-.01 3.008H12M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                            </svg>
                        FAQ's
                        </a>
                    </li>
                    <li >
                        <a  href="/contact" wire:navigate  class='max-md:text-lg max-md:px-3 max-md:rounded-lg flex items-center md:px-4 py-2 hover:bg-gray-100'>
                            <svg class="w-5 max-md:w-6 aspect-square mr-1 max-md:mr-2 " aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" stroke-linecap="round" stroke-width="1.5" d="m3.5 5.5 7.893 6.036a1 1 0 0 0 1.214 0L20.5 5.5M4 19h16a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1Z"/>
                            </svg>
                        Kontakt
                        </a>
                    </li>
                </ul>
            </div>
        </div>

