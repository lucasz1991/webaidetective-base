<nav x-data="{ focused: false }" class="bg-white border-b border-gray-100 shadow-lg"  wire:loading.class="cursor-wait">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center">
                <!-- Logo -->
                <div class="shrink-0 flex items-center h-full py-2">
                    <a href="/" class="h-full flex items-center">
                        <x-application-mark class="block h-9 w-auto" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
                    <!-- Gäste-Spezifische Navigation -->
                            <x-nav-link href="/" wire:navigate  :active="request()->is('/')">
                                {{ __('Home') }}
                            </x-nav-link>
                            @auth
        
                                <!-- Kunden-Spezifische Navigation -->
                                @if (optional(Auth::user())->role === 'guest')
                                    <x-nav-link href="/dashboard" wire:navigate  :active="request()->is('dashboard')">
                                        {{ __('Dashboard') }}
                                    </x-nav-link>
                                @endif
                                
                            @endauth
                            <x-nav-link href="/products" wire:navigate  :active="request()->is('products')">
                                {{ __('Produkte') }}
                            </x-nav-link>
                            <x-nav-link href="/booking"  :active="request()->is('booking')">
                                {{ __('Stand buchen') }}
                            </x-nav-link>
                </div>
            </div>

            <div class="flex items-center space-x-4">
            @if (!request()->routeIs('products'))
                <!-- Search Bar -->
                <div x-data="{ focused: false }" @click.away="focused = false" class="relative">
                    <form action="/search" method="GET"
                        class="flex items-center border border-gray-300 rounded-full overflow-hidden transition-all duration-300"
                        :class="focused ? 'w-[300px] border-gray-500' : 'w-[40px] border-gray-300'">
                        <input type="text" name="query" placeholder="Suchen..."
                            class="w-full px-4 py-2 text-sm focus:outline-none bg-transparent border-none outline-none"
                            x-ref="searchInput" @click="focused = true" :class="focused ? 'block' : 'hidden'" />
                        <button type="button" @click="focused = true; $refs.searchInput.focus()"
                            class="flex items-center justify-center w-[40px] h-[40px] text-gray-400 hover:text-gray-500">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 192.904 192.904" class="h-5 w-5">
                                <path d="m190.707 180.101-47.078-47.077c11.702-14.072 18.752-32.142 18.752-51.831C162.381 36.423 125.959 0 81.191 0 36.422 0 0 36.423 0 81.193c0 44.767 36.422 81.187 81.191 81.187 19.688 0 37.759-7.049 51.831-18.751l47.079 47.078a7.474 7.474 0 0 0 5.303 2.197 7.498 7.498 0 0 0 5.303-12.803zM15 81.193C15 44.694 44.693 15 81.191 15c36.497 0 66.189 29.694 66.189 66.193 0 36.496-29.692 66.187-66.189 66.187C44.693 147.38 15 117.689 15 81.193z"></path>
                            </svg>
                        </button>
                    </form>
                </div>
            @endif
              <!-- Likes and Inbox Buttons -->
                <div class="flex items-center space-x-6">
                    <!-- Likes Button -->
                    @if (optional(Auth::user())->role === 'guest')
                        <a  href="{{ route('liked.products') }}"  wire:navigate   class="block">
                            <span class="relative">
                                <svg xmlns="http://www.w3.org/2000/svg" width="23px" class="cursor-pointer fill-gray-800 inline" viewBox="0 0 512 512" stroke-width="1">
                                    <g id="Layer_20">
                                        <path d="M397.254,60.047C349.52,49.268,290.07,62.347,256,113.182c-34.07-50.835-93.52-63.9-141.254-53.135   
                                        C60.405,72.295,5.57,118.771,5.57,194.978c0,139.283,235.088,267.75,245.096,273.151c3.329,1.795,7.338,1.795,10.667,0   
                                        c10.008-5.401,245.096-133.867,245.096-273.151C506.43,118.771,451.595,72.295,397.254,60.047z 
                                        M256,445.364   
                                        C221.151,425.504,28.044,310.14,28.044,194.978c0-68.17,49.367-103.483,91.647-113.012c8.981-2.003,18.156-3.008,27.358-2.996   
                                        c42.374-1.183,81.322,23.172,98.809,61.787c2.671,5.602,9.377,7.978,14.979,5.307c2.325-1.109,4.199-2.982,5.307-5.307   
                                        c27.627-58.139,85.25-67.997,126.166-58.768c42.28,9.506,91.647,44.827,91.647,112.989C483.957,310.14,290.849,425.504,256,445.364   
                                        z"/>
                                    </g>
                                </svg>
                                <!-- Anzahl der geliketen Produkte anzeigen -->
                                @if(Auth::check() && Auth::user()->likedProducts->count() > 0)
                                    <span class="absolute right-[-9px] -ml-1 top-[-5px] rounded-full bg-red-500 px-1 py-0 text-xs text-white">
                                        {{ Auth::user()->likedProducts->count() }}
                                    </span>
                                @endif
                            </span>
                        </a>
                    @endif

                    <!-- Inbox Button -->
                    @auth
                        <a href="{{ route('messages') }}"  wire:navigate  class="block">
                            <span class="relative">
                                <svg xmlns="http://www.w3.org/2000/svg" width="25px" class="cursor-pointer fill-gray-800 inline" viewBox="0 0 512 512" fill="currentColor" stroke-width="2">
                                    <g>
                                        <g>
                                            <g>
                                                <g>
                                                    <path d="M479.568,412.096H33.987c-15,0-27.209-12.209-27.209-27.209V130.003c0-15,12.209-27.209,27.209-27.209h445.581      
                                                    c15,0,27.209,12.209,27.209,27.209v255C506.661,399.886,494.568,412.096,479.568,412.096z 
                                                    M33.987,114.189      
                                                    c-8.721,0-15.814,7.093-15.814,15.814v255c0,8.721,7.093,15.814,15.814,15.814h445.581c8.721,0,15.814-7.093,15.814-15.814v-255      
                                                    c0-8.721-7.093-15.814-15.814-15.814C479.568,114.189,33.987,114.189,33.987,114.189z"/>
                                                </g>
                                                <g>
                                                    <path d="M256.894,300.933c-5.93,0-11.86-1.977-16.744-5.93l-41.977-33.14L16.313,118.491c-2.442-1.977-2.907-5.581-0.93-8.023      
                                                    c1.977-2.442,5.581-2.907,8.023-0.93l181.86,143.372l42.093,33.14c5.698,4.535,13.721,4.535,19.535,0l41.977-33.14      
                                                    l181.628-143.372c2.442-1.977,6.047-1.512,8.023,0.93c1.977,2.442,1.512,6.047-0.93,8.023l-181.86,143.372l-41.977,33.14      
                                                    C268.755,299.072,262.708,300.933,256.894,300.933z"/>
                                                </g>
                                            </g>
                                        </g>
                                    </g>
                                </svg>
                                @if(count(Auth::user()->receivedUnreadMessages) >= 1)
                                 <span class="absolute right-[-9px] -ml-1 top-[-5px] rounded-full bg-red-500 px-1 py-0 text-xs text-white">{{count(Auth::user()->receivedUnreadMessages)}}</span>
                                @endif
                            </span>
                        </a>
                    @endauth
                </div>



                @auth
                    <!-- Settings Dropdown -->
                    <div class="ms-3 relative">
                        <x-dropdown align="" width="48">
                            <x-slot name="trigger">
                                <button
                                    class="flex text-sm border-2 border-transparent rounded-full focus:outline-none focus:border-gray-300 transition">
                                    <img class="h-8 w-8 rounded-full object-cover"
                                        src="{{ Auth::user()->profile_photo_url }}" alt="{{ Auth::user()->name }}" />
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="block px-4 py-2 text-xs text-gray-400">
                                    {{ __('Konto verwalten') }}
                                </div>
                                <x-dropdown-link href="{{ route('profile.show') }}">
                                    {{ __('Profil') }}
                                </x-dropdown-link>
                                
                                <div class="border-t border-gray-200"></div>
                                <form method="POST" action="{{ route('logout') }}" x-data>
                                    @csrf
                                    <x-dropdown-link href="{{ route('logout') }}" @click.prevent="$root.submit();">
                                        {{ __('Abmelden') }}
                                    </x-dropdown-link>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    </div>
                @else
                    <!-- Guest Dropdown -->
                    <div class="ms-3 relative">
                        <x-dropdown align="" width="48">
                            <x-slot name="trigger">
                                <button
                                    class="flex items-center justify-center w-10 h-10 bg-gray-100 text-gray-700 rounded-full hover:bg-gray-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="w-5 h-5" viewBox="0 0 512 512">
                                        <path
                                        d="M337.711 241.3a16 16 0 0 0-11.461 3.988c-18.739 16.561-43.688 25.682-70.25 25.682s-51.511-9.121-70.25-25.683a16.007 16.007 0 0 0-11.461-3.988c-78.926 4.274-140.752 63.672-140.752 135.224v107.152C33.537 499.293 46.9 512 63.332 512h385.336c16.429 0 29.8-12.707 29.8-28.325V376.523c-.005-71.552-61.831-130.95-140.757-135.223zM446.463 480H65.537V376.523c0-52.739 45.359-96.888 104.351-102.8C193.75 292.63 224.055 302.97 256 302.97s62.25-10.34 86.112-29.245c58.992 5.91 104.351 50.059 104.351 102.8zM256 234.375a117.188 117.188 0 1 0-117.188-117.187A117.32 117.32 0 0 0 256 234.375zM256 32a85.188 85.188 0 1 1-85.188 85.188A85.284 85.284 0 0 1 256 32z"
                                        data-original="#000000"></path>
                                    </svg>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link href="/login">
                                    {{ __('Anmelden') }}
                                </x-dropdown-link>
                                <div class="border-t border-gray-200"></div>
                                <x-dropdown-link href="/register">
                                    {{ __('Registrieren') }}
                                </x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    </div>
                @endauth
            </div>
        </div>
    </div>
</nav>
