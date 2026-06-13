<nav x-data="{ mobileOpen: false }" class="sticky top-0 z-50 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur" wire:loading.class="cursor-wait">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
            <div class="flex items-center gap-8">
                <a href="{{ route('welcome') }}" class="flex items-center gap-3">
                    <x-application-mark class="h-9 w-9" />
                    <span class="hidden text-lg font-black text-slate-950 sm:block">SocialScope</span>
                </a>

                <div class="hidden items-center gap-7 md:flex">
                    <x-nav-link href="{{ route('welcome') }}" wire:navigate :active="request()->routeIs('welcome')">
                        {{ __('Start') }}
                    </x-nav-link>
                    <x-nav-link href="{{ route('packages') }}" wire:navigate :active="request()->routeIs('packages')">
                        {{ __('Pakete') }}
                    </x-nav-link>
                    <a href="{{ route('welcome') }}#workflow" class="inline-flex items-center px-1 pt-1 text-sm font-medium leading-5 text-gray-500 transition hover:text-gray-700">
                        Workflow
                    </a>
                    @auth
                        <x-nav-link href="{{ route('home') }}" :active="request()->routeIs('dashboard') || request()->routeIs('network')">
                            {{ __('Dashboard') }}
                        </x-nav-link>
                    @endauth
                </div>
            </div>

            <div class="hidden items-center gap-3 md:flex">
                @auth
                    <a href="{{ route('messages') }}" wire:navigate class="relative inline-flex h-10 w-10 items-center justify-center border border-slate-200 text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700" aria-label="Nachrichten">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4 6h16v12H4V6Zm0 1 8 6 8-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        @if(count(Auth::user()->receivedUnreadMessages) >= 1)
                            <span class="absolute -right-2 -top-2 bg-rose-500 px-1.5 py-0.5 text-xs font-bold text-white">{{ count(Auth::user()->receivedUnreadMessages) }}</span>
                        @endif
                    </a>

                    <x-dropdown align="" width="48">
                        <x-slot name="trigger">
                            <button class="flex h-10 w-10 items-center justify-center overflow-hidden border border-slate-200 transition hover:border-emerald-300">
                                <img class="h-full w-full object-cover" src="{{ Auth::user()->profile_photo_url }}" alt="{{ Auth::user()->name }}" />
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
                @else
                    <a href="{{ route('login') }}" class="px-4 py-2 text-sm font-bold text-slate-700 transition hover:text-emerald-700">
                        Anmelden
                    </a>
                    <a href="{{ route('register') }}" class="bg-slate-950 px-5 py-3 text-sm font-black text-white transition hover:bg-emerald-700">
                        Kostenlos starten
                    </a>
                @endauth
            </div>

            <button type="button" class="inline-flex h-10 w-10 items-center justify-center border border-slate-200 text-slate-700 md:hidden" @click="mobileOpen = !mobileOpen" aria-label="Navigation">
                <svg x-show="!mobileOpen" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <svg x-cloak x-show="mobileOpen" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
    </div>

    <div x-cloak x-show="mobileOpen" x-collapse class="border-t border-slate-200 bg-white md:hidden">
        <div class="space-y-1 px-4 py-4">
            <a href="{{ route('welcome') }}" wire:navigate class="block px-3 py-3 font-bold text-slate-800">Start</a>
            <a href="{{ route('packages') }}" wire:navigate class="block px-3 py-3 font-bold text-slate-800">Pakete</a>
            <a href="{{ route('welcome') }}#workflow" class="block px-3 py-3 font-bold text-slate-800">Workflow</a>
            @auth
                <a href="{{ route('home') }}" class="block px-3 py-3 font-bold text-slate-800">Dashboard</a>
                <a href="{{ route('messages') }}" wire:navigate class="block px-3 py-3 font-bold text-slate-800">Nachrichten</a>
            @else
                <a href="{{ route('login') }}" class="block px-3 py-3 font-bold text-slate-800">Anmelden</a>
                <a href="{{ route('register') }}" class="mt-2 block bg-slate-950 px-3 py-3 text-center font-black text-white">Kostenlos starten</a>
            @endauth
        </div>
    </div>
</nav>
