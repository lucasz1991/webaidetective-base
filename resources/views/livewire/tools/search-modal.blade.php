<div x-data="{ openSearchMenu: @entangle('openSearchMenu') , parentheight: $persist(112).using(sessionStorage), iconheight: $persist(38).using(sessionStorage) }" @keydown.window.escape="openSearchMenu = false"  class="h-full">
    <!-- Such-Icon -->
    <div 
        x-ref="searchIconContainer"
        @click.prevent="() => { openSearchMenu = !openSearchMenu; }"  
        class="h-full w-16 relative "
        x-init="$nextTick(() => { 
            parentheight = $el.offsetHeight;
        })"
        x-cloak 
    >
        <div 
            class="flex pt-2 px-2 mr-8 rounded-t-full transition-all duration-300 absolute h-min bg-gray-100 pb-1.5"
            :class="openSearchMenu ? 'text-secondary bg-gray-200 border-t border-x border-gray-300 translate-y-[1px] rounded-b-0 !bottom-0 !pb-0' : 'text-gray-500 translate-y-0 rounded-b-full'"
            x-init="$nextTick(() => { 
                iconheight = $el.offsetHeight; 
            })"
            style="bottom: 50px; opacity: 0;"
            :style="'bottom: ' + ((parentheight / 2)-(iconheight / 2)) + 'px; opacity:1;'"
        >
            <svg 
                xmlns="http://www.w3.org/2000/svg"
                class="h-6 w-6 hover:text-gray-700 cursor-pointer transition-all duration-300" 
                fill="none"
                viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 18a8 8 0 100-16 8 8 0 000 16zm8-2l4 4"/>
            </svg>
        </div>
    </div>
    <template x-teleport="#megamenu">
        <!-- Such-Modal -->
        <div id="Search-menü" class="relative z-20">
            
            <!-- Overlay -->
            <div 
                x-show="openSearchMenu"
                @click="() => { openSearchMenu = !openSearchMenu; }"
                x-transition.opacity
                class="fixed h-full w-full bg-black bg-opacity-40"
                x-cloak
            ></div>
            <div x-trap.inert.noscroll="openSearchMenu"
                :class="openSearchMenu ? 'translate-y-0' : 'translate-y-[-100vh]'"
                class="fixed  bg-gray-200 w-full  px-3 py-3 md:py-6 md:px-8 border-b border-gray-300 shadow-lg transition-all duration-300 ease-in-out">
                    


                <div class="container mx-auto">
                    <div class=""> 
                        <div class="flex relative" x-data="{ openSelectSearchTypeDropdown: $persist(false).using(sessionStorage) }" @click.away="openSelectSearchTypeDropdown = false">
                            <!-- Dropdown-Button -->
                            <button 
                                type="button" 
                                @click.prevent="openSelectSearchTypeDropdown = !openSelectSearchTypeDropdown" 
                                class="shrink-0 z-20 inline-flex items-center py-2.5 px-4 text-sm font-medium text-white bg-primary-800 border border-primary-700 rounded-s-lg hover:bg-primary-700 focus:outline-none"
                            >
                                @if($searchType == 'insurances')
                                    Versicher.
                                @elseif($searchType == 'types')
                                    Branchen
                                @elseif($searchType == 'infos')
                                    Info's
                                @else
                                    Alles
                                @endif
                                <svg class="w-2.5 h-2.5 ms-2.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 4 4 4-4"/>
                                </svg>
                            </button>

                            <!-- Dropdown-Menü -->
                            <div 
                                x-show="openSelectSearchTypeDropdown" 
                                x-transition:enter="transition ease-out duration-100" 
                                x-transition:enter-start="opacity-0 scale-95" 
                                x-transition:enter-end="opacity-100 scale-100" 
                                x-transition:leave="transition ease-in duration-75" 
                                x-transition:leave-start="opacity-100 scale-100" 
                                x-transition:leave-end="opacity-0 scale-95"
                                class="absolute top-full left-0 mt-1 z-30 bg-white border border-gray-200 rounded-lg shadow-md w-44"
                                x-cloak
                            >
                                <ul class="py-2 text-sm text-gray-700 ">
                                    @if($searchType != 'all')
                                        <li>
                                            <button type="button" wire:click="selectSearchType('all')" @click.prevent="openSelectSearchTypeDropdown = false"  class="w-full px-4 py-2 text-left text-primary-600 bg-gray-100 hover:bg-gray-200 ">Alle Kategorien</button>
                                        </li>
                                    @endif
                                    @if($searchType != 'infos')
                                        <li>
                                            <button type="button" wire:click="selectSearchType('infos')" @click.prevent="openSelectSearchTypeDropdown = false" class="w-full px-4 py-2 text-left hover:bg-gray-100 ">Informationen</button>
                                        </li>
                                    @endif
                                </ul>
                            </div>

                            <!-- Suchfeld -->
                            <div class="relative w-full z-10">
                                <input 
                                    type="search" 
                                    id="search-dropdown" 
                                    wire:model.live="query" 
                                    class="block p-2.5 w-full text-base  bg-white text-gray-900 bg-gray-50 rounded-e-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500" 
                                    placeholder="Suchen..." 
                                    required 
                                />
                            </div>
                        </div>
                    </div>
                    @if(count($resultsInfos))
                        <ul class=" max-h-[60vh] overflow-y-auto divide-y transition-all duration-300 ease-in-out divide-gray-200 p-2 mt-4 ">
                            

                            @if(count($resultsInfos))
                                <li class="py-2  mt-4 rounded">
                                    <h3 class="text-base font-bold uppercase text-gray-400 ">Informationen</h3>
                                </li>
                                @foreach($resultsInfos as $info)
                                    <li class="pl-3 py-2 cursor-pointer hover:bg-gray-100 px-2 rounded"
                                        wire:click="selectResult({{ $info['id'] }} , 'infos')">
                                        {{ $info['title'] }}
                                    </li>
                                @endforeach
                            @endif
                        </ul>
                    @elseif(strlen($query) >= 2)
                        <div class="flex items-center justify-center h-32">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M10 2a8 8 0 100 16 8 8 0 000-16zm1 11H9v-2h2v2zm0-4H9V7h2v2z"/>
                            </svg>
                            <span class="text-gray-400 text-sm">Keine Ergebnisse gefunden</span>
                        </div>
                    @endif    
                </div>
            </div>
            
        </div>
    </template>
</div>
