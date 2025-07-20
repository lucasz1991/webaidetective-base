<nav class="fixed top-0 left-0 right-0 z-10 flex items-center bg-white   print:hidden  ">
    <div class="flex justify-between w-full">
        <div class="flex items-center topbar-brand">
            <div
                class="hidden lg:flex navbar-brand items-center justify-between shrink px-3 h-[70px]  border-r  bg-[#fbfaff] border-gray-50   shadow-none">
                <a href="/"
                    class="flex items-center text-lg flex-shrink-0 font-bold dark:text-white leading-[69px]">
                        <x-navigation.tutor-layout-application-icon class="inline-block w-12 aspect-square align-middle  " />
                    <span
                        class="hidden font-semibold  text-gray-700 align-middle lg:block  leading-[69px]">   
                        <img 
                            class="w-full" 
                            src="{{ asset('/site-images/cbw_logo_text.png') }}" 
                            alt="Logo Schriftzug">  
                    </span>
                </a>
            </div>
            <!-- Toggle Button (relativ zur Sidebar positioniert, sichtbar bei kleineren Bildschirmen) -->
            <button type="button"
                class=" border-b border-gray-300    group-data-[sidebar-size=sm]:border-[#e9e9ef]  text-gray-800  h-[70px] px-4  py-1 vertical-menu-btn text-16"
                id="vertical-menu-btn">
                <div class=" z-50 text-gray-600  burger-container">
                    <div class="burger-bar bar1"></div>
                    <div class="burger-bar bar2"></div>
                    <div class="burger-bar bar3"></div>
                </div>
            </button>
        </div>
        <div class="flex justify-between w-full items-center  pl-6  pr-6  border-b border-gray-300">
            <div>
                    <x-application-logo class="inline-block lg:hidden" />

            </div>
            <div class="flex items-center ">
                    
                    @auth
                        <!-- Settings Dropdown -->
                        <div class="ms-3 relative">
                            <x-dropdown align="" width="48">
                                <x-slot name="trigger">
                                    <button
                                        class="flex items-center space-x-2 text-sm border-2 border-transparent rounded-full focus:outline-none focus:border-gray-300 transition">
                                        <img class="h-8 w-8 rounded-full object-cover"
                                            src="{{ Auth::user()->profile_photo_url }}" alt="{{ Auth::user()->name }}" />
                                            <span class="hidden font-medium xl:block">{{ Auth::user()->name }}</span>
                                            <i class="hidden align-bottom mdi mdi-chevron-down xl:block"></i>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <div class="block px-4 py-2 text-xs text-gray-400">
                                        {{ __('Konto verwalten') }}
                                    </div>
                                    <x-dropdown-link href="{{ route('profile.show') }}">
                                    <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 9h3m-3 3h3m-3 3h3m-6 1c-.306-.613-.933-1-1.618-1H7.618c-.685 0-1.312.387-1.618 1M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm7 5a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/>
                                    </svg>

                                        {{ __('Profil') }}
                                    </x-dropdown-link>
                                    
                                    <div class="border-t border-gray-200"></div>
                                    <form method="POST" action="{{ route('logout') }}" x-data>
                                        @csrf
                                        <x-dropdown-link href="{{ route('logout') }}" @click.prevent="$root.submit();">
                                            <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m15 9-6 6m0-6 6 6m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                            </svg>

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
                                        <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 14v3m4-6V7a3 3 0 1 1 6 0v4M5 11h10a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1Z"/>
                                        </svg>

                                        {{ __('Anmelden') }}
                                    </x-dropdown-link>
                                    <div class="border-t border-gray-200"></div>
                                    <x-dropdown-link href="/register">
                                        <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                            <path stroke="currentColor" stroke-linecap="square" stroke-linejoin="round" stroke-width="1.5" d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"/>
                                        </svg>
                                        {{ __('Registrieren') }}
                                    </x-dropdown-link>
                                </x-slot>
                            </x-dropdown>
                        </div>
                    @endauth
                </div>
            </div>
        </div>
    </div>
</nav>
