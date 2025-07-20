@props(['retailSpace', 'shelfRental'])
@php
    $width = $retailSpace['dimensions']['width'];
    $height = $retailSpace['dimensions']['height'];
    $aspectRatio = round($width / $height, 2);
    $aspectWidth = round($width / $aspectRatio);
    $aspectHeight = round($height / $aspectRatio);
@endphp
<div  x-data="{
        open: false,
        isAnimating: false,
        target: null,
        originalStyle: '',
        rect: null,
        openLightbox() {
            if (this.isAnimating || this.open) return; 

            this.isAnimating = true;
            this.target = $refs.floorplan;
            this.originalStyle = this.target.style.cssText;
            this.rect = this.target.getBoundingClientRect();

            const padding = 16; 
            const maxWidth = Math.min(678, window.innerWidth - 2 * padding);
            const maxHeight = Math.min(0.7 * window.innerHeight, 678, window.innerHeight - 2 * padding); // Höhe auf 70vh begrenzen

            const centerX = window.innerWidth / 2;
            const centerY = window.innerHeight / 2;

            const targetX = centerX - maxWidth / 2;
            const targetY = centerY - maxHeight / 2;

            this.target.style.position = 'fixed';
            this.target.style.zIndex = '50';
            this.target.style.left = `${this.rect.left}px`;
            this.target.style.top = `${this.rect.top}px`;
            this.target.style.width = `${this.rect.width}px`;
            this.target.style.height = `${this.rect.height}px`;

            setTimeout(() => { 
                this.target.style.transition = 'all 0.5s ease';
                this.target.style.left = `${targetX}px`;
                this.target.style.top = `${targetY}px`;
                this.target.style.width = `${maxWidth}px`;
                this.target.style.height = `${maxHeight}px`;
                this.target.style.objectFit = 'contain'; // Für SVGs und Bilder
                this.isAnimating = false;
            }, 10);

            this.open = true;
        },
        closeLightbox() {
            if (this.isAnimating || !this.open) return; 

            this.isAnimating = true;
            this.target.style.transition = 'all 0.5s ease';
            this.target.style.transform = 'none';
            this.target.style.left = `${this.rect.left}px`;
            this.target.style.top = `${this.rect.top}px`;
            this.target.style.width = `${this.rect.width}px`;
            this.target.style.height = `${this.rect.height}px`;

            setTimeout(() => { 
                this.target.style.cssText = this.originalStyle; 
                this.open = false; 
                this.isAnimating = false;
            }, 300); 
        }
    }" 
    x-on:keydown.escape.prevent.stop="closeLightbox()"
    x-on:focusin.window="! $refs.floorplan.contains($event.target) && closeLightbox()" 
    class="min-w-28 max-w-36 md:w-80  relative">
        <div class="svg-placeholder absolute inset-0  w-full h-full">
            <svg class="w-6 h-6 text-gray-800 absolute opacity-50 z-30 right-1 top-1" :class="open ? 'hidden' : ''" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                <path stroke="currentColor" stroke-linecap="round" stroke-width="2" d="m21 21-3.5-3.5M10 7v6m-3-3h6m4 0a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/>
            </svg>
        </div>

            <svg x-ref="floorplan" id="floor-plan" xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 {{ $retailSpace['dimensions']['width'] }} {{ $retailSpace['dimensions']['height'] }}"
                class="w-full relative hover:bg-white border-3 border  rounded-lg transition-transform linear duration-300 "
                :class="open ? 'bg-white' : 'cursor-pointer hover:border-green-400'"
                @click="openLightbox()"
                style="background-image: url('{{ $retailSpace['backgroundimg']['url'] ?? '' }}');background-size: {{ $retailSpace['backgroundimg']['size'] ?? 'cover' }};background-repeat: no-repeat;background-position: center;"
                preserveAspectRatio="xMidYMid meet">
                <!-- Dynamische Elemente anzeigen -->
                @php
                    $highlightshelfX = null;
                    $highlightshelfY = null;
                @endphp
                @foreach($retailSpace['elements']['shelves'] as $shelf)
                    @php
                        $svgWidth = $retailSpace['dimensions']['width'];
                        $svgHeight = $retailSpace['dimensions']['height'];
                        $x = ($shelf['x'] / $svgWidth) * $svgWidth;
                        $y = ($shelf['y'] / $svgHeight) * $svgHeight;
                        $width = $shelf['width'];
                        $height = $shelf['height'];
                        $textX = $x + $width / 2; 
                        $textY = $y + $height / 2; 
                        if($shelfRental->shelf->floor_number == $shelf['text']){ $highlightshelfX = $textX; $highlightshelfY = $textY;};
                    @endphp
                    <g x="{{ $x }}" y="{{ $y }}" width="{{ $width }}" height="{{ $height }}" fill="{{ $shelf['color'] ?? '#4caf50' }}" data-type="shelf" data-id="{{ $shelf['element_id'] ?? null }}" data-text="{{ $shelf['text'] ?? 'Regal' }}" data-color="{{ $shelf['color'] ?? '#4caf50' }}">
                        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $width }}" height="{{ $height }}" fill="{{ $shelf['color'] ?? '#4caf50' }}"></rect>
                        <text x="{{ $textX }}" y="{{ $textY }}" font-family="Arial" font-size="1em" fill="#fff" text-anchor="middle" alignment-baseline="middle">
                            {{ $shelf['text'] ?? 'Regal' }}
                        </text>
                    </g>

                @endforeach

                @foreach($retailSpace['elements']['others'] as $element)
                    @php
                        $x = ($element['x'] / $svgWidth) * $svgWidth;
                        $y = ($element['y'] / $svgHeight) * $svgHeight;
                        $width = $element['width'];
                        $height = $element['height'];
                        $textX = $x + $width / 2; 
                        $textY = $y + $height / 2; 
                    @endphp
                    <g x="{{ $x }}" y="{{ $y }}" width="{{ $width }}" height="{{ $height }}" fill="{{ $element['color'] ?? '#f44336' }}" data-type="other" data-id="{{ $element['element_id'] ?? null }}" data-text="{{ $element['text'] ?? 'Eingang' }}" data-color="{{ $element['color'] ?? '#f44336' }}">
                        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $width }}" height="{{ $height }}" fill="{{ $element['color'] ?? '#f44336' }}"></rect>
                        <text x="{{ $textX }}" y="{{ $textY }}" font-family="Arial" font-size="1em" fill="#fff" text-anchor="middle" alignment-baseline="middle">
                            {{ $element['text'] ?? 'Eingang' }}
                        </text>
                    </g>
                @endforeach

                @if($highlightshelfX)
                    <circle class="animate-custom-pulse stroke-red-500 stroke-[20px] fill-transparent" r="75" cx="{{ $highlightshelfX }}" cy="{{ $highlightshelfY }}" />
                @endif
            </svg>
    <!-- Overlay -->
    <div x-ref="overlay" x-show="open" @click="closeLightbox" x-transition x-cloak class="fixed inset-0 bg-black bg-opacity-70 transition-all duration-600  z-40 flex justify-center items-center">
        <button class="absolute top-4 right-4 aspect-square bg-white text-black rounded-full px-4 py-2 shadow hover:bg-gray-200 focus:outline-none z-50">
            ✕
        </button>
    </div>
    <!-- Overlay -->
</div>