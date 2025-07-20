<div 
    x-data="{
        showFilters: false,
        init() {
            this.$watch('showFilters', value => {
                if (value && this.$refs.filterPanel) {
                    this.$nextTick(() => {
                        this.$refs.filterPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                }
            });
        }
    }"
    x-init="init()"
    {{ $attributes->merge(['class' => '']) }}
>
    <div class="container mx-auto p-4 pb-8">
        <div class="mb-4 max-xl:flex max-xl:justify-end">
            <button @click="showFilters = !showFilters" class="text-sm text-blue-600 hover:underline p-2 rounded-full bg-gray-200 mr-3 flex items-center justify-center shadow-xl shadow-gray-900/5 border border-gray-300">
                <svg :class="{ 'xl:rotate-180 max-xl:rotate-0': !showFilters, 'max-xl:rotate-180 xl:rotate-0': showFilters }"
                    xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-600 transform transition-all  mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                <svg class="h-4 w-4 text-gray-500" xmlns="http://www.w3.org/2000/svg"  fill="currentColor" viewBox="0 0 40.2299 36.2069"><path  d="M0,6.0345V2.0115H7.8921v4.023Zm12.0742,0V2.0115H40.2299v4.023ZM0,20.115V16.092H27.88961v4.023Zm32.6158,0V16.092h7.6141v4.023ZM0,34.1955v-4.023H18.02461v4.023Zm22.39561,0v-4.023H40.2299v4.023Z"/><circle  cx="10.0575" cy="4.023" r="4.023"/><circle  cx="30.1724" cy="18.1035" r="4.023"/><path  d="M20.115,28.161a4.023,4.023,0,1,1-4.023,4.023A4.0229,4.0229,0,0,1,20.115,28.161Z"/></svg>                           
            </button>
        </div>
        <div class="xl:grid xl:grid-cols-12 xl:gap-6">
            
            <div x-show="showFilters"  x-cloak class="filter-sidebar xl:col-span-2 max-xl:absolute max-xl:right-4 z-10">
                <div x-show="showFilters" x-transition class="max-xl:fixed xl:hidden inset-0 transform transition-all" x-on:click="showFilters = false">
                    <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
                </div>
                <div x-show="showFilters" x-transition x-ref="filterPanel" class="relative flex  w-full max-w-[20rem] flex-col rounded-xl bg-white bg-clip-border border border-gray-300 p-2 text-gray-700 shadow-xl shadow-gray-900/5  z-20">
                    {{ $filters }}
                </div>
            </div>
            <div class="filter-sidebar" :class="showFilters ? 'xl:col-span-10' : 'xl:col-span-12'"  x-cloak x-transition>
                {{ $listContent }}
            </div>
        </div>
    </div>
</div>




