<div class="relative bg-white shadow  cursor-pointer hover:-translate-y-2 transition-all relative snap-center block ">  
    <a href="{{ route('product.show', $product->id) }}" wire:navigate  class="h-full flex flex-wrap"  wire:key="{{ $product->id }}">
                    <!-- Bild mit Ladeanimation -->
        <div class="overflow-hidden mx-auto  w-full ">
            <!-- Spinner -->
            <div x-data="{ loading: true }" x-init="setTimeout(() => loading = false, 500)" class="relative w-full aspect-square bg-gray-100 shadow   overflow-hidden">
                <div x-show="loading" class="absolute inset-0 flex items-center justify-center bg-gray-200">
                    <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-blue-500"></div>
                </div>
                <!-- Produktbild -->
                <img 
                    src="{{ url($product->getImageUrl(0,'m')) }}" 
                    alt="{{ $product->name }}" 
                    class="object-cover w-full  aspect-square z-50"
                    @load.window="loading = false"      
                    loading="lazy"
                >
            </div>
        </div>
        <div class="w-full p-2 md:p-3 lg:p-5 grid  grid-cols-1 place-content-between" >
            <div class="max-sm:block">
                <div class=" text-right rtl:text-left flex" style="width: max-content;">
                    <p class="text-sm text-gray-600  mb-2">
                        <span class="bg-green-100 text-green-800  font-medium pr-1 pl-1 py-0.5 rounded  border border-green-400"> {{ $product->shelfRental->shelf->floor_number ?? '???' }}</span>
                    </p>
                    <p class="text-xs tracking-tighter text-gray-600 decoration-indigo-500">
                        @if ($product->shelfRental && $product->shelfRental->rental_end)
                            @php
                                $rentalEnd = \Carbon\Carbon::parse($product->shelfRental->rental_end)->setTime(17, 0); // Mietende auf 16:00 Uhr setzen
                                $now = \Carbon\Carbon::now();
                                $remainingDays = $now->diffInDays($rentalEnd, false);
                                $remainingHours = $now->diffInHours($rentalEnd, false); // Gesamte verbleibende Stunden
                            @endphp
                            @if ($remainingDays > 0)
                                <span class="ml-2">
                                    Noch {{ $remainingDays }} Tag(e)
                                </span>
                            @elseif ($remainingDays === 0 && $remainingHours > 0)
                                <span class="text-red-600 ml-2">
                                    Noch {{ $remainingHours }} Stunde(n)
                                </span>
                            @else
                                <span class="text-red-600 ml-2">Nicht mehr verfügbar</span>
                            @endif
                        @else
                            <span class="text-gray-500 ml-2">Keine Angaben</span>
                        @endif
                    </p>
                </div>
                <h3 class=" max-md:order-2 text-md md:text-lg leading-normal font-extrabold text-gray-800 text-pretty break-anywhere md:break-words">{{ $product->name }}</h3>
                <!-- Flexbox für Kategorie und Größe -->
                <div class="flex flex-wrap gap-2 mt-2">
                    @if(!empty($product->category))
                        <span class="text-xs bg-gray-100 text-gray-800 font-medium px-2 py-0.5 rounded-full border border-gray-300">
                            {{ $product->category }}
                        </span>
                    @endif
                    @if(!empty($product->size))
                        <span class="text-xs bg-gray-100 text-gray-800 font-medium px-2 py-0.5 rounded-full border border-gray-300">
                            Gr.: {{ $product->size }}
                        </span>
                    @endif
                </div>
            </div>
            <div class="mt-2 text-pretty">
                <p class="text-gray-600 text-sm truncate "> {{ Str::limit($product->description, 50) }}</p>
            </div>
            <x-product-price :product="$product" />
        </div>
    </a>
    <!-- Wishlist Icon -->
    <div  
        @auth 
            wire:click="toggleLikedProduct({{ $product->id }})"
        @else 
        @click.prevent="Livewire.dispatch('redirectLoginWishlist')"
        @endauth
        x-data="{ isClicked: false }" 
        :class="{
            'bg-gray-100 hover:bg-red-100': !{{ auth()->check() && auth()->user()->likedProducts->contains($product->id) ? 'true' : 'false' }},
            'bg-red-400': {{ auth()->check() && auth()->user()->likedProducts->contains($product->id) ? 'true' : 'false' }}
        }"
        class="w-8 h-8 flex items-center justify-center {{ auth()->check() && auth()->user()->likedProducts->contains($product->id) ? 'border-red-300' : 'border-gray-300' }}  left-auto top-3 right-3 shadow-lg border rounded-full cursor-pointer absolute  transition-all duration-100 transform z-60"
        @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
        style="transform:scale(1);"
        :style="isClicked ? 'transform:scale(0.7);' : ''"
    >
        <div>
            <svg xmlns="http://www.w3.org/2000/svg" width="22px" height="22px" class="transition-colors duration-300  {{ auth()->check() && auth()->user()->likedProducts->contains($product->id) ? 'fill-white' : 'fill-red-400' }} hover:fill-red-800" viewBox="0 0 24 24">
                <path stroke-linecap="round" fill="{{ auth()->check() && auth()->user()->likedProducts->contains($product->id) ? '#ffffff' : '#a8a7a7' }}" stroke-linejoin="round" stroke-width="3" d="M12.01 6.001C6.5 1 1 8 5.782 13.001L12.011 20l6.23-7C23 8 17.5 1 12.01 6.002Z"></path>
            </svg>
            
        </div>
    </div>
</div>
