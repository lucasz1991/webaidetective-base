<a onclick="window.history.back()"  
    wire:navigate  
    class="shadow transition-all duration-100 inline-flex items-center content-center px-2 py-1 text-sm border border-blue-300 bg-white text-gray-600 rounded-full aspect-square hover:bg-blue-200 cursor-pointer waves-effect"
            x-data="{ isClicked: false }" 
            @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
            style="transform:scale(1);"
            :style="isClicked ? 'transform:scale(0.7);' : ''"
    >
        <svg class="w-3 h-3" fill="currentColor" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M9.4 233.4c-12.5 12.5-12.5 32.8 0 45.3l160 160c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3L109.2 288 416 288c17.7 0 32-14.3 32-32s-14.3-32-32-32l-306.7 0L214.6 118.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0l-160 160z"/></svg>
</a>
