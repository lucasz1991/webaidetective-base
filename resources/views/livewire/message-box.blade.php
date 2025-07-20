<div  class="w-full relative bg-cover bg-center py-20"  wire:loading.class="cursor-wait">
    @section('title')
        {{ __('Nachrichten') }}
    @endsection
    <x-slot name="header">
                    
                <h1 class="font-semibold text-2xl text-gray-800 leading-tight flex items-center">
                     Nachrichten 
                     <svg xmlns="http://www.w3.org/2000/svg" width="80px" class="fill-[#000] ml-10 stroke-2 inline opacity-30" viewBox="0 0 512 512" stroke-width="106">
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
                </h1>
        </x-slot>
    <div class="max-w-7xl mx-auto px-5">
        <div class="bg-white  shadow-lg rounded-lg p-6">
                    
            
        <div class="p-4 mb-4 text-sm text-green-800 rounded-lg bg-green-50" role="alert">
        <p><span class="text-lg font-medium">Du wirst hier über alle wichtigen Nachrichten informiert.</span><br> Jede neue Nachricht, die dich betrifft, wird dir direkt angezeigt, damit du immer auf dem neuesten Stand bist. Schau regelmäßig in dein Postfach, um keine wichtigen Updates zu verpassen.</p>
</div>
            <div class="mt-10 space-y-5">
            <div class="flex flex-col md:flex-row items-center justify-between space-y-3 md:space-y-0 ">
                <div class="w-full md:w-1/2">
                    <form class="flex items-center">
                        <label for="simple-search" class="sr-only">Search</label>
                        <div class="relative w-full">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                <svg aria-hidden="true" class="w-5 h-5 text-gray-500" fill="currentColor" viewbox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <input type="text" id="simple-search" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 p-2" placeholder="Search" required="">
                        </div>
                    </form>
                </div>
            </div>
            <div class="overflow-x-scroll">
                <table class="min-w-md text-sm text-left text-gray-500 table-fixed">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                        <tr>
                            <th scope="col" class="py-3 px-6 w-4/12">Betreff</th>
                            <th scope="col" class="py-3 px-6 w-5/12">Nachricht</th>
                            <th scope="col" class="py-3 px-6 w-2/12">Datum</th>
                            <th scope="col" class="py-3 px-6 w-1/12"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($messages as $message)

                            <tr class="border-b hover:bg-blue-50 cursor-pointer @if($message->status == 1) bg-blue-200 @endif" wire:click="showMessage({{ $message->id }})"  wire:key="{{ $message->id }}">
                                <th scope="row" class="px-4 py-3 font-medium text-gray-900 whitespace-nowrap  truncate">{{ $message->subject }}</th>
                                    <td class="px-4 py-3"
                                        x-data="{ 
                                            screenWidth: window.innerWidth ,
                                            maxLength: Math.max(40, Math.min(100, Math.floor( this.screenWidth / 10)))}"
                                            x-resize="screenWidth = $width">
                                        <span class="block truncate" >{{ mb_substr(strip_tags($message->message), 0, 100) }}</span>
                                    </td>
                                    <td class="px-4 py-3">{{ $message->created_at->diffForHumans() }}</td>
                                    <td x-data="{ open: false }" @click.away="open = false"
                                         class="px-3 py-3 flex items-center justify-end">
                                        <button @click.prevent="open = !open" class="inline-flex items-center p-0.5 text-sm font-medium text-center text-gray-500 hover:text-gray-800 rounded-lg focus:outline-none" type="button">
                                            <svg class="w-5 h-5" aria-hidden="true" fill="currentColor" viewbox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z" />
                                            </svg>
                                        </button>
                                        <div class="hidden z-10 w-44 bg-white rounded divide-y divide-gray-100 shadow">
                                            <ul class="py-1 text-sm text-gray-700" aria-labelledby="apple-imac-27-dropdown-button">
                                                <li>
                                                    <button @click.prevent="$wire.showMessage({{ $message->id }})" class="block py-2 px-4 hover:bg-gray-100">anzeigen</button>
                                                </li>
                                                
                                            </ul>
                                            <div class="py-1">
                                                <button @click.prevent="open = false"  class="block py-2 px-4 text-sm text-gray-700 hover:bg-gray-100">Löschen</button>
                                            </div>
                                        </div>
                                    </td>
                            </tr>
                        @empty
                            <tr class=" " >
                                <td class="border-b border-gray-200 px-6 py-4 truncate">Keine Nachrichten gefunden.</td>
                            </tr>
                        @endforelse


                       
                    </tbody>
                </table>
            </div>
                @if ($messages->hasMorePages())
                    <div class="text-center mt-10"
                    x-data="{ isClicked: false }" 
                    @click="isClicked = true; setTimeout(() => isClicked = false,100)">
                        <button :style="isClicked ? 'transform:scale(0.9)' : 'transform:scale(1)'" wire:click="loadMore" class=" transition-all duration-100 transform py-2.5 px-5 me-2 mb-2 text-sm font-medium text-gray-900 focus:outline-none bg-white rounded-lg border border-gray-200 hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:ring-4 focus:ring-gray-100">
                            Weitere Nachrichten laden
                        </button>
                    </div>
                @endif
            </div>









            
        </div>


        <!-- Modal zum ansehen der Nachricht-->
        <div 
            x-show="showMessageModal" x-cloak 
            x-data="{
                showMessageModal: @entangle('showMessageModal')
            }"
            x-init="() => { $watch('showMessageModal', value => { document.getElementById('main').classList.toggle('overflow-hidden', value); });}"
            class="fixed inset-0 p-6 flex items-center justify-center z-50 modal-container ">

            <div x-show="showMessageModal" class="fixed inset-0 transform" x-on:click="showMessageModal = false">
                <div class="absolute inset-0 bg-gray-900 opacity-75"></div>
            </div>

            <div x-show="showMessageModal" class="bg-white rounded-lg overflow-hidden transform sm:w-full sm:mx-auto max-w-2xl ">
                <div class="border border-gray-300 rounded-lg p-4 relative">
                    <button type="button" @click="showMessageModal = false; $selectedMessage = null;" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                    <div>
                        <div class="flex">
                            <span class="inline-block  text-xs font-medium text-gray-700 mb-2 bg-green-100 px-2 py-1 rounded-full">{{ $selectedMessage ? $selectedMessage->created_at->diffForHumans() : '' }}</span>
                        </div>

                    </div>
                    <h3 class="text-xl font-semibold mb-4 border-b pb-2">{{ $selectedMessage ? $selectedMessage->subject : '' }}</h3>
                    <div class="my-6">
                        <p class="text-gray-800">{!! $selectedMessage ? $selectedMessage->message  : '' !!}</p>
                    </div>
                </div>

                <div class="flex justify-end mt-4 mb-2">
                    <button type="button" @click="showMessageModal = false; $selectedMessage = null;" class="bg-green-300 hover:bg-green-400 text-white px-4 py-2 rounded-lg mr-2">Schließen</button>
                </div>
            </div>
        </div>
        
    </div>
</div>