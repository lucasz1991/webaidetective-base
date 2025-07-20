<x-layouts.auth-layout>
    <x-slot name="title">
    Passwort vergessen
    </x-slot>
    <x-slot name="description">
    Du bist nur noch einen Schritt entfernt! Erstelle jetzt ein neues Passwort, um wieder vollen Zugriff auf dein Konto bei CBW Schulnetz zu erhalten. Schnell, sicher und unkompliziert – so kannst du direkt weitermachen und alle Funktionen der Plattform nutzen.
    </x-slot>
    <x-slot name="form">
             <div  class="mt-8 w-xl shrink-0">
                                <x-validation-errors class="mb-4" />

                   

                @if (session()->has('success'))
                    <div class="p-4 mb-4 text-sm text-green-700 bg-green-100 rounded-lg">
                        {{ session('success') }}
                    </div>
                @endif
                @if (session()->has('error'))
                    <div class="p-4 mb-4 text-sm text-red-700 bg-red-100 rounded-lg">
                        {{ session('error') }}
                    </div>
                @endif
                <form wire:submit.prevent="sendResetLink" class="space-y-4">
                    <div>
                                        <x-label for="email" value="E-Mail" />
                                        <x-input id="email" wire:model="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
                        @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <x-button class="">
                        Link anfordern
                    </x-button>
                </form>
            </div>
    </x-slot>
</x-layouts.auth-layout>
