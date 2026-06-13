@guest
<nav x-data="{ open: false }" class="sticky top-0 z-50 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <a href="{{ route('welcome') }}" wire:navigate class="flex items-center gap-3">
            <x-application-mark class="h-10 w-10" />
            <span class="text-lg font-black tracking-tight text-slate-950">SocialScope</span>
        </a>

        <div class="hidden items-center gap-8 md:flex">
            <a href="{{ route('welcome') }}#features" class="text-sm font-bold text-slate-600 transition hover:text-cyan-700">Funktionen</a>
            <a href="{{ route('welcome') }}#workflow" class="text-sm font-bold text-slate-600 transition hover:text-cyan-700">Workflow</a>
            <a href="{{ route('packages') }}" wire:navigate class="text-sm font-bold text-slate-600 transition hover:text-cyan-700">Pakete</a>
        </div>

        <div class="hidden items-center gap-3 md:flex">
            <a href="{{ route('login') }}" wire:navigate class="rounded-xl px-4 py-2.5 text-sm font-black text-slate-700 transition hover:bg-slate-100">
                Anmelden
            </a>
            <a href="{{ route('register') }}" wire:navigate class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-black text-white shadow-lg shadow-slate-900/10 transition hover:bg-cyan-700">
                Kostenlos starten
            </a>
        </div>

        <button
            type="button"
            x-on:click="open = !open"
            class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-700 md:hidden"
            aria-label="Navigation oeffnen"
        >
            <svg x-show="!open" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <svg x-cloak x-show="open" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </button>
    </div>

    <div x-cloak x-show="open" x-collapse class="border-t border-slate-200 bg-white px-4 py-4 md:hidden">
        <div class="grid gap-1">
            <a href="{{ route('welcome') }}#features" class="rounded-xl px-3 py-3 font-bold text-slate-700">Funktionen</a>
            <a href="{{ route('welcome') }}#workflow" class="rounded-xl px-3 py-3 font-bold text-slate-700">Workflow</a>
            <a href="{{ route('packages') }}" wire:navigate class="rounded-xl px-3 py-3 font-bold text-slate-700">Pakete</a>
            <a href="{{ route('login') }}" wire:navigate class="rounded-xl px-3 py-3 font-bold text-slate-700">Anmelden</a>
            <a href="{{ route('register') }}" wire:navigate class="mt-2 rounded-xl bg-slate-950 px-4 py-3 text-center font-black text-white">Kostenlos starten</a>
        </div>
    </div>
</nav>
@else
<div
    wire:poll.15000ms="refreshNavigationData"
    x-data="{
        isMobileMenuOpen: false,
        navHeight: $persist(113).using(sessionStorage),
        isMobile: false,
        scrollTop: 0,
        lastScrollTop: 0,
        showNav: true,
        notificationAudio: null,
        notificationSoundUrl: @js(asset('sounds/notification.wav')),
        soundListenerCleanup: null,

        init() {
            this.$nextTick(() => {
                this.isMobile = window.innerWidth <= 768;
                this.measureNavHeight();
            });

            this.soundListenerCleanup = Livewire.on('playNotificationSound', () => {
                this.playNotificationSound();
            });
        },

        destroy() {
            this.soundListenerCleanup?.();
        },

        handleScroll() {
            this.scrollTop = window.scrollY;

            const scrollingDown = this.scrollTop > this.lastScrollTop;
            const scrollingUp = this.scrollTop < this.lastScrollTop;

            if (scrollingDown && this.scrollTop > 120) {
                this.$dispatch('navhide');
                this.showNav = false;
            } else if ((scrollingUp && (this.lastScrollTop - this.scrollTop) >= 30) || this.scrollTop < 120) {
                this.showNav = true;
            }

            this.lastScrollTop = this.scrollTop;
        },

        handleResize() {
            this.$nextTick(() => {
                this.isMobile = window.innerWidth <= 768;
                this.isMobileMenuOpen = false;
                this.measureNavHeight();
            });
        },

        measureNavHeight() {
            const nav = this.$refs.nav || this.$el.querySelector('[data-user-navigation]');

            if (!nav) return;

            const measuredHeight = nav.offsetHeight;

            if (measuredHeight > 0) {
                this.navHeight = measuredHeight;
            }
        },

        playNotificationSound() {
            if (!this.notificationAudio) {
                this.notificationAudio = new Audio(this.notificationSoundUrl);
                this.notificationAudio.preload = 'auto';
            }

            this.notificationAudio.currentTime = 0;
            this.notificationAudio.play().catch(err => {
                console.log('[Sound] Benachrichtigungston konnte nicht abgespielt werden:', err.message);
            });
        }
    }"
    x-on:scroll.window="handleScroll()"
    x-resize="handleResize()"
    x-on:click.outside="isMobileMenuOpen = false"
>
    <nav
        x-ref="nav"
        data-user-navigation
        :style="(!showNav && !isMobileMenuOpen) ? 'margin-top: -' + navHeight + 'px' : 'margin-top: 0px'"
        class="fixed z-30 w-screen bg-white transition-all duration-300 ease-in-out"
        wire:loading.class="cursor-wait"
    >
             <div class="w-full border-b border-gray-300 px-3 md:px-8">

                 <!-- Primary Navigation Menu -->
                 <div class="container mx-auto flex justify-between items-center ">
                        <div class="flex-none flex items-center h-full py-2 max-md:order-1" @click="$dispatch('navhide')">
                             <a href="{{ \App\Providers\RouteServiceProvider::home() }}" wire:navigate class="flex h-full items-center gap-3">
                                 <x-application-mark class="h-10 w-10" />
                                 <span class="hidden text-lg font-black tracking-tight text-slate-950 sm:block">SocialScope</span>
                             </a>
                         </div>
                         <div class="flex items-center space-x-4 max-md:order-3 md:order-2  flex-none" @click="$dispatch('navhide')">
                             <!-- Inbox Buttons -->
                             <div class="flex items-center space-x-6 mr-2">
                                 @if (Auth::check() && $currentUrl !== url('/messages'))
                                 <div class="relative" x-data="{ open: false, modalOpen: false, selectedMessage: null  }">
                                     <!-- Button zum Öffnen des Popups -->
                                     <button @click="open = !open" class="block">
                                         <span class="relative">
                                             <svg xmlns="http://www.w3.org/2000/svg" width="30px" class="fill-[#333] hover:fill-[#077bff] stroke-2 inline" viewBox="0 0 512 512" stroke-width="106">
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
                                                                 l181.628-143.372c2.442-1.977,6.047-1.512,8.023,0.93c1.977-2.442,1.512,6.047-0.93,8.023l-181.86,143.372l-41.977,33.14      
                                                                 C268.755,299.072,262.708,300.933,256.894,300.933z"/>
                                                             </g>
                                                         </g>
                                                     </g>
                                                 </g>
                                             </svg>
                                             @if($unreadMessagesCount >= 1)
                                                 <span class="absolute right-[-9px] -ml-1 top-[-5px] rounded-full bg-red-400 px-1.5 py-0.2 text-xs text-white">
                                                     {{ $unreadMessagesCount }}
                                                 </span>
                                             @endif
                                         </span>
                                     </button>
                                     <!-- Popup -->
                                     <div 
                                         x-show="open" 
                                         x-cloak
                                         class="absolute md:p-4 right-0 md:mt-2 md:w-[24.5rem] max-md:fixed max-md:inset-0 max-md:w-full max-md:top-0 max-md:flex max-md:items-center max-md:justify-center max-md:bg-black max-md:bg-opacity-50 max-md:z-50"
                                         x-transition>
                                        <div x-on:click.outside="open = false" class="relative max-w-full max-md:pt-10 divide-y divide-slate-400/20 rounded-lg bg-white text-[0.8125rem]/5 text-slate-900 ring-1 shadow-xl shadow-black/5 ring-slate-700/10 z-50">
                                                     <button type="button" @click="open = false; selectedMessage = null;" class="md:hidden absolute top-2 right-2 text-gray-400 hover:text-gray-600">
                                                         <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                             <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                         </svg>
                                                     </button>
                                             <!-- Nachrichtenliste -->
                                             @forelse($receivedMessages as $message)
                                             <div 
                                                 @click="modalOpen = true; open = false; selectedMessage = { subject: '{{ $message->subject }}', body: '{!! addslashes($message->message) !!}', createdAt: '{{ $message->created_at->diffForHumans() }}' }; $wire.setMessageStatus({{ $message->id }}); " 
                                                 class="flex items-center p-4 hover:bg-slate-50 cursor-pointer @if($message->status == 1) bg-blue-200 @endif">
                                                 <div class="block h-10 w-10 size-4 flex-none rounded-full">
                                                     <x-application-mark class="h-10 w-10" />
                                                 </div>
                                                 <div class="ml-4 flex-auto">    
                                                     <div class="font-medium">{{ $message->subject }}</div>
                                                     <div class="mt-1 text-slate-700">
                                                         {{ Str::limit(strip_tags($message->message), 40) }}
                                                     </div>
                                                 </div>
                                             </div>
                                             @empty
                                             <div class="p-4 text-center text-slate-700">
                                                 Keine  Nachrichten
                                             </div>
                                             @endforelse
                                             <!-- "Alle ansehen"-Button -->
                                             <div class="p-4">
                                                 <a href="{{ route('messages') }}" 
                                                     class="pointer-events-auto rounded-md px-4 py-2 text-center font-medium ring-1 shadow-xs ring-slate-700/10 hover:bg-slate-50 block">
                                                     Alle Nachrichten ansehen
                                                 </a>
                                             </div>
                                         </div>
                                     </div>
                                     <!-- Modal -->
                                     <div 
                                         x-show="modalOpen" 
                                         x-cloak
                                         class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 z-50"
                                         x-transition:enter="transition ease-out duration-200"
                                             x-transition:enter-start="opacity-0"
                                             x-transition:enter-end="opacity-100"
                                             x-transition:leave="transition ease-in duration-200"
                                             x-transition:leave-start="opacity-100"
                                             x-transition:leave-end="opacity-0">
                                        <div x-on:click.outside="modalOpen = false" class="relative w-[90%] max-w-md rounded-lg bg-white p-6 shadow-lg">
                                             <div>
                                                 <button type="button" @click="modalOpen = false; selectedMessage = null;" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
                                                     <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                         <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                     </svg>
                                                 </button>
                                                 <div>
                                                     <div class="flex">
                                                         <span class="inline-block  text-xs font-medium text-gray-700 mb-2 bg-green-100 px-2 py-1 rounded-full" x-text="selectedMessage?.createdAt"></span>
                                                     </div>
                                                 </div>
                                                 <h3 class="text-xl font-semibold mb-4 border-b pb-2" x-text="selectedMessage?.subject"></h3>
                                                 <div class="my-6">
                                                     <p class="text-gray-800" x-html="selectedMessage?.body"></p>
                                                 </div>
                                                 </div>
                                                 <div class="flex justify-end mt-4">
                                                     <button type="button" @click="modalOpen = false; isClicked = true; setTimeout(() => isClicked = false, 100)" 
                                                     x-data="{ isClicked: false }" 
                                                     :style="isClicked ? 'transform:scale(0.7);' : 'transform:scale(1);'"
                                                     class="transition-all duration-100 py-2.5 px-5  text-sm font-medium text-gray-900 focus:outline-none bg-white rounded-lg border border-gray-200 hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:ring-4 focus:ring-gray-100 ">Schließen</button>
                                                 </div>
                                         </div>
                                     </div>
                                </div>
                                @endif

                                @if (Auth::check())
                                <div
                                    class="relative hidden md:block"
                                    x-data="{ open: false, closeTimer: null }"
                                    @mouseenter="if (closeTimer) clearTimeout(closeTimer); open = true"
                                    @mouseleave="closeTimer = setTimeout(() => open = false, 120)"
                                >
                                    <button
                                        type="button"
                                        class="block rounded-full p-1 transition hover:bg-slate-100"
                                        @focus="open = true"
                                        @blur="closeTimer = setTimeout(() => open = false, 120)"
                                    >
                                        <span class="relative flex items-center justify-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" class="{{ $subscriptionSummary['icon_classes'] ?? 'text-slate-400' }}">
                                                <path d="M12 3l2.35 4.76 5.25.76-3.8 3.7.9 5.23L12 15.9l-4.7 2.55.9-5.23-3.8-3.7 5.25-.76L12 3Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                            <span class="absolute -bottom-1 -right-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold ring-1 {{ $subscriptionSummary['status_classes'] ?? 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                                {{ $subscriptionSummary['has_subscription'] ? 'Pro' : 'Free' }}
                                            </span>
                                        </span>
                                    </button>

                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-transition
                                        class="absolute right-0 mt-2 w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl"
                                    >
                                        <div class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                                            <div class="flex items-center justify-between gap-3">
                                                <div>
                                                    <div class="text-sm font-bold text-slate-900">{{ $subscriptionSummary['plan_name'] ?? 'Free' }}</div>
                                                    <div class="mt-1 text-xs text-slate-500">Abo und Credits</div>
                                                </div>
                                                <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $subscriptionSummary['status_classes'] ?? 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                                    {{ $subscriptionSummary['status_label'] ?? 'Kein Abo' }}
                                                </span>
                                            </div>
                                        </div>

                                        <div class="space-y-3 p-4 text-sm text-slate-700">
                                            <div class="grid grid-cols-2 gap-3">
                                                <div class="rounded-lg border border-slate-200 bg-white p-3">
                                                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Verfuegbar</div>
                                                    <div class="mt-1 text-lg font-bold text-slate-950">{{ number_format($subscriptionSummary['available_credits'] ?? 0, 0, ',', '.') }}</div>
                                                </div>
                                                <div class="rounded-lg border border-slate-200 bg-white p-3">
                                                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Monatlich</div>
                                                    <div class="mt-1 text-lg font-bold text-slate-950">{{ number_format($subscriptionSummary['monthly_credits'] ?? 0, 0, ',', '.') }}</div>
                                                </div>
                                            </div>

                                            <div class="rounded-lg border border-slate-200 bg-white p-3">
                                                <div class="flex items-center justify-between gap-3">
                                                    <div>
                                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Scan-Verbrauch</div>
                                                        <div class="mt-1 text-sm font-bold text-slate-950">{{ $subscriptionSummary['scan_usage_label'] ?? '0 Credits genutzt' }}</div>
                                                    </div>
                                                    <div class="text-xs font-semibold text-slate-500">{{ $subscriptionSummary['scan_usage_percent'] ?? 0 }}%</div>
                                                </div>
                                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                                    <div
                                                        class="h-full rounded-full bg-pink-600 transition-all"
                                                        style="width: {{ $subscriptionSummary['scan_usage_percent'] ?? 0 }}%;"
                                                    ></div>
                                                </div>
                                            </div>

                                            <div class="space-y-2 text-xs text-slate-600">
                                                <div class="flex items-center justify-between">
                                                    <span>Bonus-Credits</span>
                                                    <span class="font-semibold text-slate-900">{{ number_format($subscriptionSummary['bonus_credits'] ?? 0, 0, ',', '.') }}</span>
                                                </div>
                                                <div class="flex items-center justify-between">
                                                    <span>Reserviert</span>
                                                    <span class="font-semibold text-slate-900">{{ number_format($subscriptionSummary['reserved_credits'] ?? 0, 0, ',', '.') }}</span>
                                                </div>
                                                <div class="flex items-center justify-between">
                                                    <span>Verbraucht</span>
                                                    <span class="font-semibold text-slate-900">{{ number_format($subscriptionSummary['used_credits'] ?? 0, 0, ',', '.') }}</span>
                                                </div>
                                                @if(!empty($subscriptionSummary['ends_at']))
                                                <div class="flex items-center justify-between">
                                                    <span>Laeuft bis</span>
                                                    <span class="font-semibold text-slate-900">{{ $subscriptionSummary['ends_at'] }}</span>
                                                </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                @endif
                            </div>



                            <div class="hidden md:block">
             
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
                             
                            <a class="inline-flex items-center p-2  md:hidden focus:outline-none"
                                @click="isMobileMenuOpen = !isMobileMenuOpen; $dispatch('navhide')">
                                 <div class=" z-50  text-sm text-gray-500 rounded-lg hover:bg-gray-100  burger-container "
                                        :class="isMobileMenuOpen ? 'is-open' : ''" >
                                      <div class="burger-bar bar1"></div>
                                      <div class="burger-bar bar2"></div>
                                      <div class="burger-bar bar3"></div>
                                 </div>
                                 <span class="sr-only">Öffnen Hauptmenü</span>
                            </a>
                         </div>
                         <!-- Navigation Links -->
                         <div x-show="isMobileMenuOpen || !isMobile" 
                                 x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="opacity-0"
                                 x-transition:enter-end="opacity-100"
                                 x-transition:leave="transition ease-in duration-200"
                                 x-transition:leave-start="opacity-100 "
                                 x-transition:leave-end="opacity-0"
                                 :style="isMobile ? 'top: ' + navHeight + 'px; height: calc(100vh - ' + navHeight + 'px);' : ''"
                                 :class="isMobileMenuOpen ? 'max-md:inset-0  max-md:bg-black max-md:bg-opacity-50 max-md:z-30' : ''"   
                                 @click="$dispatch('navhide')"
                                 x-cloak   class="max-md:order-3 md:order-1 max-md:fixed  md:grow md:flex md:justify-center" >
                                 
                                 <div @click.prevent="isMobileMenuOpen = true" 
                                         :class="isMobileMenuOpen ? 'max-md:translate-x-0' : 'max-md:translate-x-full'"    
                                         :style="isMobile ? 'height: calc(100vh - ' + navHeight + 'px);' : ''"   
                                         x-cloak  class="grid  content-between transition-transform  ease-out duration-400  max-md:bg-white  max-md:right-0 max-md:h-full max-md:fixed max-md:overflow-y-auto max-md:py-5 max-md:px-3  max-md:border-r max-md:border-gray-200">
                                     <div  class="md:space-x-8 max-md:block   max-md:space-y-4 md:-my-px md:mx-4 max-md:gap-3 md:flex  w-max  mx-auto" >
                                        @if (optional(Auth::user())->role === 'guest')
                                            <x-navigation.user-navigation-menu-links />   
                                        @elseif (optional(Auth::user())->role === 'tutor')
                                            <x-navigation.tutor-navigation-menu-links />
                                        @endif
                                            <div class="md:hidden block mt-6">
                                                <div class="border-t border-gray-200 mb-6"></div>
                                                @auth
                                                    <div class="mx-4 mb-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                                        <div class="flex items-center justify-between gap-3">
                                                            <div>
                                                                <div class="text-sm font-bold text-slate-900">{{ $subscriptionSummary['plan_name'] ?? 'Free' }}</div>
                                                                <div class="mt-1 text-xs text-slate-500">Abo und Credits</div>
                                                            </div>
                                                            <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $subscriptionSummary['status_classes'] ?? 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                                                {{ $subscriptionSummary['status_label'] ?? 'Kein Abo' }}
                                                            </span>
                                                        </div>
                                                        <div class="mt-4 grid grid-cols-2 gap-3 text-xs text-slate-600">
                                                            <div class="rounded-lg bg-white p-3">
                                                                <div class="font-semibold uppercase tracking-wide text-slate-500">Verfuegbar</div>
                                                                <div class="mt-1 text-base font-bold text-slate-950">{{ number_format($subscriptionSummary['available_credits'] ?? 0, 0, ',', '.') }}</div>
                                                            </div>
                                                            <div class="rounded-lg bg-white p-3">
                                                                <div class="font-semibold uppercase tracking-wide text-slate-500">Monatlich</div>
                                                                <div class="mt-1 text-base font-bold text-slate-950">{{ number_format($subscriptionSummary['monthly_credits'] ?? 0, 0, ',', '.') }}</div>
                                                            </div>
                                                        </div>
                                                        <div class="mt-3 rounded-lg bg-white p-3 text-xs text-slate-600">
                                                            <div class="flex items-center justify-between gap-3">
                                                                <div>
                                                                    <div class="font-semibold uppercase tracking-wide text-slate-500">Scan-Verbrauch</div>
                                                                    <div class="mt-1 text-sm font-bold text-slate-950">{{ $subscriptionSummary['scan_usage_label'] ?? '0 Credits genutzt' }}</div>
                                                                </div>
                                                                <div class="font-semibold text-slate-500">{{ $subscriptionSummary['scan_usage_percent'] ?? 0 }}%</div>
                                                            </div>
                                                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                                                <div
                                                                    class="h-full rounded-full bg-pink-600 transition-all"
                                                                    style="width: {{ $subscriptionSummary['scan_usage_percent'] ?? 0 }}%;"
                                                                ></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="block px-4 py-2 text-xs text-gray-400">
                                                        {{ __('Konto verwalten') }}
                                                    </div>
                                                    <x-nav-link href="{{ route('profile.show') }}">
                                                        <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 9h3m-3 3h3m-3 3h3m-6 1c-.306-.613-.933-1-1.618-1H7.618c-.685 0-1.312.387-1.618 1M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm7 5a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/>
                                                        </svg>
                                                        {{ __('Profil') }}
                                                    </x-nav-link>
                                                    <form method="POST" action="{{ route('logout') }}" x-data>
                                                        @csrf
                                                        <x-nav-link href="{{ route('logout') }}" @click.prevent="$root.submit();">
                                                            <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m15 9-6 6m0-6 6 6m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                                            </svg>
                                                            {{ __('Abmelden') }}
                                                        </x-nav-link>
                                                    </form>
                                                @else
                                                    <x-nav-link href="/login" wire:navigate >
                                                        <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 14v3m4-6V7a3 3 0 1 1 6 0v4M5 11h10a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1Z"/>
                                                        </svg>
                                                        {{ __('Anmelden') }}
                                                    </x-nav-link>
                                                    <x-nav-link href="/register" wire:navigate >
                                                        <svg class="w-5 h-5  mr-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                                            <path stroke="currentColor" stroke-linecap="square" stroke-linejoin="round" stroke-width="1.5" d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"/>
                                                        </svg>
                                                        {{ __('Registrieren') }}
                                                    </x-nav-link>
                                                @endauth
                                            </div>
                                     </div>
                                     <div class="md:hidden max-md:flex self-end  bottom-0 left-0 justify-center p-4 pb-0 space-x-4 w-full bg-white  z-20 border-t border-gray-200">
                                         <ul class=" flex space-x-5">
                                             <li>
                                             <a href='' target="_blank">
                                                 <svg xmlns="http://www.w3.org/2000/svg" fill="none" class="fill-gray-300 hover:fill-gray-500 w-10 h-10"
                                                 viewBox="0 0 24 24">
                                                 <path fill-rule="evenodd"
                                                     d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7v-7h-2v-3h2V8.5A3.5 3.5 0 0 1 15.5 5H18v3h-2a1 1 0 0 0-1 1v2h3v3h-3v7h4a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"
                                                     clip-rule="evenodd" />
                                                 </svg>
                                                 <span class="sr-only">Facebook Link</span>
                                             </a>
                                             </li>
                                             <li>
                                             <a href='' target="_blank">
                                                 <svg xmlns="http://www.w3.org/2000/svg" fill="none"
                                                 class="fill-gray-300 hover:fill-gray-500 w-10 h-10" viewBox="0 0 24 24">
                                                 <path
                                                     d="M12 9.3a2.7 2.7 0 1 0 0 5.4 2.7 2.7 0 0 0 0-5.4Zm0-1.8a4.5 4.5 0 1 1 0 9 4.5 4.5 0 0 1 0-9Zm5.85-.225a1.125 1.125 0 1 1-2.25 0 1.125 1.125 0 0 1 2.25 0ZM12 4.8c-2.227 0-2.59.006-3.626.052-.706.034-1.18.128-1.618.299a2.59 2.59 0 0 0-.972.633 2.601 2.601 0 0 0-.634.972c-.17.44-.265.913-.298 1.618C4.805 9.367 4.8 9.714 4.8 12c0 2.227.006 2.59.052 3.626.034.705.128 1.18.298 1.617.153.392.333.674.632.972.303.303.585.484.972.633.445.172.918.267 1.62.3.993.047 1.34.052 3.626.052 2.227 0 2.59-.006 3.626-.052.704-.034 1.178-.128 1.617-.298.39-.152.674-.333.972-.632.304-.303.485-.585.634-.972.171-.444.266-.918.299-1.62.047-.993.052-1.34.052-3.626 0-2.227-.006-2.59-.052-3.626-.034-.704-.128-1.18-.299-1.618a2.619 2.619 0 0 0-.633-.972 2.595 2.595 0 0 0-.972-.634c-.44-.17-.914-.265-1.618-.298-.993-.047-1.34-.052-3.626-.052ZM12 3c2.445 0 2.75.009 3.71.054.958.045 1.61.195 2.185.419A4.388 4.388 0 0 1 19.49 4.51c.457.45.812.994 1.038 1.595.222.573.373 1.227.418 2.185.042.96.054 1.265.054 3.71 0 2.445-.009 2.75-.054 3.71-.045.958-.196 1.61-.419 2.185a4.395 4.395 0 0 1-1.037 1.595 4.44 4.44 0 0 1-1.595 1.038c-.573.222-1.227.373-2.185.418-.96.042-1.265.054-3.71.054-2.445 0-2.75-.009-3.71-.054-.958-.045-1.61-.196-2.185-.419A4.402 4.402 0 0 1 4.51 19.49a4.414 4.414 0 0 1-1.037-1.595c-.224-.573-.374-1.227-.419-2.185C3.012 14.75 3 14.445 3 12c0-2.445.009-2.75.054-3.71s.195-1.61.419-2.185A4.392 4.392 0 0 1 4.51 4.51c.45-.458.994-.812 1.595-1.037.574-.224 1.226-.374 2.185-.419C9.25 3.012 9.555 3 12 3Z" />
                                                 </svg>
                                                 <span class="sr-only">Instagram Link</span>
                                             </a>
                                             </li>
                                         </ul>
                                     </div>
                                 </div>
                             </div>
                </div>
    </nav>
    <div :style="'height: ' + navHeight + 'px'" class="min-h-12 transition-all duration-300 ease-in-out md:min-h-[4rem]"></div>
    <div id="megamenu" class="transition-all duration-200 ease-in-out"></div>
</div>
@endguest
