<a  href="{{ route('booking') }}" wire:navigate  class="mt-3 transition-all duration-100 inline-flex items-center justify-center px-5 py-3 mr-3 text-base font-medium text-center text-white rounded-lg bg-red-400 hover:bg-red-500 "
                                            x-data="{ isClicked: false }" 
                                            @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
                                            style="transform:scale(1);"
                                            :style="isClicked ? 'transform:scale(0.9);' : ''"
                >
                Stand buchen
                <svg class="w-7  aspect-square ml-2 max-md:ml-2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 10h16m-8-3V4M7 7V4m10 3V4M5 20h14a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1Zm3-7h.01v.01H8V13Zm4 0h.01v.01H12V13Zm4 0h.01v.01H16V13Zm-8 4h.01v.01H8V17Zm4 0h.01v.01H12V17Zm4 0h.01v.01H16V17Z"/>
                            </svg>                </a>
                <a  href="/products" wire:navigate  class="mt-3 transition-all duration-100 inline-flex items-center justify-center px-5 py-3 text-base font-medium text-center text-gray-900 border border-gray-300 rounded-lg bg-white hover:bg-gray-200 focus:ring-4 focus:ring-gray-100 " x-data="{ isClicked: false }" 
                                            @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
                                            style="transform:scale(1);"
                                            :style="isClicked ? 'transform:scale(0.9);' : ''">
                    Produkte ansehen
                    <svg class="w-7  aspect-square ml-2 max-md:ml-2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                             <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.583 8.445h.01M10.86 19.71l-6.573-6.63a.993.993 0 0 1 0-1.4l7.329-7.394A.98.98 0 0 1 12.31 4l5.734.007A1.968 1.968 0 0 1 20 5.983v5.5a.992.992 0 0 1-.316.727l-7.44 7.5a.974.974 0 0 1-1.384.001Z"/>
                            </svg>                </a> 