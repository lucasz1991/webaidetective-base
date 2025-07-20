<div class="">
    <x-slot name="header">
        <div class="grid grid-cols-1 pb-6">
            <div class="md:flex items-center justify-between px-[2px]">
                <h4 class="text-[18px] font-medium text-gray-800 mb-sm-0 grow  mb-2 md:mb-0">Meine Kurse</h4>
            </div>
        </div>
    </x-slot>
    <div class="container mx-auto">
        <livewire:tutor.courses.courses-list-preview />
    </div>
</div>
