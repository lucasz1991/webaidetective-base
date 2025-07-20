<x-layouts.auth-layout>
    <x-slot name="title">
    Neues Passwort
    </x-slot>
    <x-slot name="description">
    Du bist nur noch einen Schritt entfernt! Erstelle jetzt ein neues Passwort, um wieder vollen Zugriff auf dein Konto bei CBW Schulnetz zu erhalten. Schnell, sicher und unkompliziert – so kannst du direkt weitermachen und alle Funktionen der Plattform nutzen.
    </x-slot>
    <x-slot name="form">
    <div  class="mt-8 w-xl shrink-0">
                                <x-validation-errors class="mb-4" />
                                @if (session()->has('status'))
                                    <div class="mb-4 text-green-600 text-sm font-semibold">
                                        {{ session('status') }}
                                    </div>
                                @endif

                    <form wire:submit.prevent="resetPassword">
                        <div class="mb-4">
                            <label for="email" class="block text-sm font-medium text-gray-700">E-Mail-Adresse</label>
                            <input wire:model="email" id="email" type="email" class="mt-1 block w-full rounded border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            @error('email') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-4">
                            <label for="password" class="block text-sm font-medium text-gray-700">Neues Passwort</label>
                            <input wire:model="password" id="password" type="password" class="mt-1 block w-full rounded border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            @error('password') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-6">
                            <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Passwort bestätigen</label>
                            <input wire:model="password_confirmation" id="password_confirmation" type="password" class="mt-1 block w-full rounded border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <x-button class="">
                            Passwort zurücksetzen
                        </x-button>
                    </form>
            </div>
    </x-slot>
</x-layouts.auth-layout>

