<div class="pb-8 pt-3 md:py-12 bg-[#f8f2e8f2] antialiased" wire:loading.class="cursor-wait">
    <x-slot name="header">
        <h1 class="font-semibold text-2xl text-gray-800 leading-tight flex items-center">
            Sitemap
            <svg width="80px" class="aspect-square text-[#333] ml-10 inline opacity-30" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.529 9.988a2.502 2.502 0 1 1 5 .191A2.441 2.441 0 0 1 12 12.582V14m-.01 3.008H12M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            </svg>  
        </h1>
    </x-slot>

    <div class="max-w-7xl mx-auto px-5">
        <div class="bg-white shadow-lg rounded-lg p-6 md:p-10">
            <div class="sitemap-container">
                <!-- Sitemap Content -->
                <div class="space-y-6">
                    <ul class="list-disc pl-6 text-gray-600">
                        <li><a href="/home" class="text-indigo-600 hover:text-indigo-800">Home</a></li>
                        <li><a href="/about" class="text-indigo-600 hover:text-indigo-800">About Us</a></li>
                        <li><a href="/services" class="text-indigo-600 hover:text-indigo-800">Services</a></li>
                        <li><a href="/contact" class="text-indigo-600 hover:text-indigo-800">Contact</a></li>
                        <li><a href="/faq" class="text-indigo-600 hover:text-indigo-800">FAQ</a></li>
                        <!-- Weitere Links hier -->
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
