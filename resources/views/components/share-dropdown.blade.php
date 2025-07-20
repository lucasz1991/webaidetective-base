@props(['shelfRental' => null, 'product' => null])

<div x-data="{ open: false }" class="relative">
    <!-- Button zum Öffnen des Dropdowns -->
    <button @click="open = !open"
            class="flex items-center justify-center shadow p-2 text-sm font-medium text-gray-900 bg-white border border-gray-200 rounded-lg hover:bg-gray-100 hover:text-primary-700 focus:ring-4 focus:ring-gray-100">
        <svg class="w-5 aspect-square" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M49.54,44.34a7.81327,7.81327,0,0,0-6.59,3.61L21.69,35.67a7.797,7.797,0,0,0-.22-6.48l-.0011-.002L43.3233,16.57391A7.81769,7.81769,0,1,0,41.71,11.83a7.56556,7.56556,0,0,0,.61,3.01l.00128.00268L20.35907,27.519A7.837,7.837,0,1,0,20.69,37.4L42.09,49.76a7.69578,7.69578,0,0,0-.38,2.41,7.83,7.83,0,1,0,7.83-7.83Z"/>
        </svg>
    </button>

    <!-- Dropdown Menu -->
    <div x-show="open" x-cloak @click.away="open = false" class="absolute right-4 mt-2 w-48 bg-white border border-gray-200 rounded-lg shadow-lg z-40">
        <ul>
            <!-- WhatsApp Share -->
            <li>
                <a href="https://wa.me/?text={{ urlencode($product ? 'Produkt teilen: ' . $product->name . ' - ' . request()->fullUrl() : 'Regalvermietung teilen: ' . $shelfRental->name . ' - ' . request()->fullUrl()) }}" 
                   target="_blank"
                   class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                   <svg class="w-5 h-5 mr-1 " aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                                            <path fill="currentColor" stroke-width="1.5" fill-rule="evenodd" d="M12 4a8 8 0 0 0-6.895 12.06l.569.718-.697 2.359 2.32-.648.379.243A8 8 0 1 0 12 4ZM2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10a9.96 9.96 0 0 1-5.016-1.347l-4.948 1.382 1.426-4.829-.006-.007-.033-.055A9.958 9.958 0 0 1 2 12Z" clip-rule="evenodd"/>
                                                            <path fill="currentColor" stroke-width="1.5" d="M16.735 13.492c-.038-.018-1.497-.736-1.756-.83a1.008 1.008 0 0 0-.34-.075c-.196 0-.362.098-.49.291-.146.217-.587.732-.723.886-.018.02-.042.045-.057.045-.013 0-.239-.093-.307-.123-1.564-.68-2.751-2.313-2.914-2.589-.023-.04-.024-.057-.024-.057.005-.021.058-.074.085-.101.08-.079.166-.182.249-.283l.117-.14c.121-.14.175-.25.237-.375l.033-.066a.68.68 0 0 0-.02-.64c-.034-.069-.65-1.555-.715-1.711-.158-.377-.366-.552-.655-.552-.027 0 0 0-.112.005-.137.005-.883.104-1.213.311-.35.22-.94.924-.94 2.16 0 1.112.705 2.162 1.008 2.561l.041.06c1.161 1.695 2.608 2.951 4.074 3.537 1.412.564 2.081.63 2.461.63.16 0 .288-.013.4-.024l.072-.007c.488-.043 1.56-.599 1.804-1.276.192-.534.243-1.117.115-1.329-.088-.144-.239-.216-.43-.308Z"/>
                                                            </svg>
                    WhatsApp
                </a>
            </li>
            <!-- Instagram Share -->
            <li>
                <a href="https://www.instagram.com/sharer.php?u={{ urlencode(request()->fullUrl()) }}" 
                   target="_blank"
                   class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                   <svg class="w-5 h-5 mr-1 " aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                                            <path fill="currentColor" stroke-width="1.5" fill-rule="evenodd" d="M3 8a5 5 0 0 1 5-5h8a5 5 0 0 1 5 5v8a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8Zm5-3a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3h8a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3H8Zm7.597 2.214a1 1 0 0 1 1-1h.01a1 1 0 1 1 0 2h-.01a1 1 0 0 1-1-1ZM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm-5 3a5 5 0 1 1 10 0 5 5 0 0 1-10 0Z" clip-rule="evenodd"/>
                                                            </svg>
                    Instagram
                </a>
            </li>
            <!-- Facebook Share -->
            <li>
                <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode(request()->fullUrl()) }}" 
                   target="_blank"
                   class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                   <svg class="w-5 h-5 mr-1 " aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
                                                            <path fill-rule="evenodd" stroke-width="1.5" d="M13.135 6H15V3h-1.865a4.147 4.147 0 0 0-4.142 4.142V9H7v3h2v9.938h3V12h2.021l.592-3H12V6.591A.6.6 0 0 1 12.592 6h.543Z" clip-rule="evenodd"/>
                                                            </svg>
                    Facebook
                </a>
            </li>
            <!-- Copy Link -->
            <li>
                <a href="javascript:void(0)" 
                   @click="copyToClipboard('{{ request()->fullUrl() }}')"
                   class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                   <svg class="w-5 h-5 mr-1 " aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.213 9.787a3.391 3.391 0 0 0-4.795 0l-3.425 3.426a3.39 3.39 0 0 0 4.795 4.794l.321-.304m-.321-4.49a3.39 3.39 0 0 0 4.795 0l3.424-3.426a3.39 3.39 0 0 0-4.794-4.795l-1.028.961"/>
                                                        </svg>
                    Link kopieren
                </a>
            </li>
        </ul>
    </div>
</div>
