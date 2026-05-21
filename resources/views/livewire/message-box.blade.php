<div class="w-full relative bg-cover bg-center py-20" wire:loading.class="cursor-wait">
    @section('title')
        {{ __('Nachrichten') }}
    @endsection

    <x-slot name="header">
        <h1 class="font-semibold text-2xl text-gray-800 leading-tight flex items-center">
            Nachrichten
            <svg xmlns="http://www.w3.org/2000/svg" width="80px" class="fill-[#000] ml-10 stroke-2 inline opacity-30" viewBox="0 0 512 512" stroke-width="106">
                <g>
                    <path d="M479.568,412.096H33.987c-15,0-27.209-12.209-27.209-27.209V130.003c0-15,12.209-27.209,27.209-27.209h445.581c15,0,27.209,12.209,27.209,27.209v255C506.661,399.886,494.568,412.096,479.568,412.096z M33.987,114.189c-8.721,0-15.814,7.093-15.814,15.814v255c0,8.721,7.093,15.814,15.814,15.814h445.581c8.721,0,15.814-7.093,15.814-15.814v-255c0-8.721-7.093-15.814-15.814-15.814H33.987z"/>
                    <path d="M256.894,300.933c-5.93,0-11.86-1.977-16.744-5.93l-41.977-33.14L16.313,118.491c-2.442-1.977-2.907-5.581-0.93-8.023c1.977-2.442,5.581-2.907,8.023-0.93l181.86,143.372l42.093,33.14c5.698,4.535,13.721,4.535,19.535,0l41.977-33.14l181.628-143.372c2.442-1.977,6.047-1.512,8.023,0.93c1.977,2.442,1.512,6.047-0.93,8.023l-181.86,143.372l-41.977,33.14C268.755,299.072,262.708,300.933,256.894,300.933z"/>
                </g>
            </svg>
        </h1>
    </x-slot>

    <div class="max-w-7xl mx-auto px-5">
        <div class="bg-white shadow-lg rounded-lg p-6">
            <div class="p-4 mb-4 text-sm text-green-800 rounded-lg bg-green-50" role="alert">
                <p>
                    <span class="text-lg font-medium">Du wirst hier ueber alle wichtigen Nachrichten informiert.</span><br>
                    Jede neue Nachricht, die dich betrifft, wird dir direkt angezeigt, damit du immer auf dem neuesten Stand bist.
                </p>
            </div>

            @if($messageBoxStatus)
                <div class="p-4 mb-4 text-sm rounded-lg {{ $messageBoxStatusLevel === 'error' ? 'bg-red-50 text-red-800' : 'bg-green-50 text-green-800' }}" role="alert">
                    {{ $messageBoxStatus }}
                </div>
            @endif

            <div class="mt-10 space-y-5">
                <div class="flex flex-col items-stretch justify-between gap-3 md:flex-row md:items-center">
                    <div class="w-full md:w-1/2">
                        <form class="flex items-center">
                            <label for="simple-search" class="sr-only">Search</label>
                            <div class="relative w-full">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <svg aria-hidden="true" class="w-5 h-5 text-gray-500" fill="currentColor" viewbox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <input type="text" id="simple-search" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 p-2" placeholder="Search">
                            </div>
                        </form>
                    </div>

                    @if($messages->total() > 0)
                        <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                            <button
                                type="button"
                                wire:click="markAllAsRead"
                                class="inline-flex justify-center rounded-lg border border-blue-200 bg-white px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50"
                            >
                                Alle als gelesen markieren
                            </button>
                            <button
                                type="button"
                                wire:click="deleteAllMessages"
                                onclick="return confirm('Alle Nachrichten wirklich loeschen?')"
                                class="inline-flex justify-center rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50"
                            >
                                Alle loeschen
                            </button>
                        </div>
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-left text-gray-500 table-fixed">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                            <tr>
                                <th scope="col" class="py-3 px-6 w-3/12">Betreff</th>
                                <th scope="col" class="py-3 px-6 w-4/12">Nachricht</th>
                                <th scope="col" class="py-3 px-6 w-2/12">Datum</th>
                                <th scope="col" class="py-3 px-6 w-3/12 text-right">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($messages as $message)
                                <tr class="border-b hover:bg-blue-50 cursor-pointer @if($message->status == 1) bg-blue-200 @endif" wire:click="showMessage({{ $message->id }})" wire:key="message-row-{{ $message->id }}">
                                    <th scope="row" class="px-4 py-3 font-medium text-gray-900 whitespace-nowrap truncate">
                                        {{ $message->subject }}
                                    </th>
                                    <td class="px-4 py-3">
                                        <span class="block truncate">{{ mb_substr(strip_tags($message->message), 0, 100) }}</span>
                                    </td>
                                    <td class="px-4 py-3">{{ $message->created_at->diffForHumans() }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center justify-end gap-2">
                                            <button
                                                type="button"
                                                wire:click.stop="showMessage({{ $message->id }})"
                                                class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                                            >
                                                Anzeigen
                                            </button>
                                            <button
                                                type="button"
                                                wire:click.stop="deleteMessage({{ $message->id }})"
                                                onclick="return confirm('Diese Nachricht wirklich loeschen?')"
                                                class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50"
                                            >
                                                Loeschen
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="border-b border-gray-200 px-6 py-4 truncate">
                                        Keine Nachrichten gefunden.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($messages->hasMorePages())
                    <div class="text-center mt-10" x-data="{ isClicked: false }" @click="isClicked = true; setTimeout(() => isClicked = false,100)">
                        <button :style="isClicked ? 'transform:scale(0.9)' : 'transform:scale(1)'" wire:click="loadMore" class="transition-all duration-100 transform py-2.5 px-5 me-2 mb-2 text-sm font-medium text-gray-900 focus:outline-none bg-white rounded-lg border border-gray-200 hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:ring-4 focus:ring-gray-100">
                            Weitere Nachrichten laden
                        </button>
                    </div>
                @endif
            </div>
        </div>

        <x-modal wire:model="showMessageModal" maxWidth="2xl">
            <div class="border border-gray-300 rounded-lg p-4 relative">
                <button type="button" x-on:click="$dispatch('close')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <div class="flex">
                    <span class="inline-block text-xs font-medium text-gray-700 mb-2 bg-green-100 px-2 py-1 rounded-full">
                        {{ $selectedMessage ? $selectedMessage->created_at->diffForHumans() : '' }}
                    </span>
                </div>
                <h3 class="text-xl font-semibold mb-4 border-b pb-2">
                    {{ $selectedMessage ? $selectedMessage->subject : '' }}
                </h3>
                <div class="my-6">
                    <p class="text-gray-800">{!! $selectedMessage ? $selectedMessage->message : '' !!}</p>
                </div>
            </div>

            <div class="flex justify-end mt-4 mb-2">
                <button type="button" x-on:click="$dispatch('close')" class="bg-green-300 hover:bg-green-400 text-white px-4 py-2 rounded-lg mr-2">
                    Schliessen
                </button>
            </div>
        </x-modal>
    </div>
</div>
