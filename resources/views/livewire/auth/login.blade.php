<x-layouts.auth-layout>
    <x-slot name="title">
    
    CBW Schulnetz
      
    </x-slot>
    <x-slot name="description">
        
      Hier finden Sie Infos zu Ihrem Qualiprogramm und Kontaktmöglichkeit zur CBW-Verwaltung
      
    </x-slot>
    <x-slot name="form">
      <div  class="mt-8 ">

        <form wire:submit.prevent="login">
        @csrf

        <div>
        <x-label for="email" value="E-Mail" />
        <x-input 
            id="email" 
            class="block mt-1 w-full" 
            type="email" 
            wire:model="email" 
            required 
            autofocus 
            autocomplete="username" 
        />
        <x-input-error for="email" class="mt-2" />
        </div>

        <div class="mt-4">
        <x-label for="password" value="Passwort" />
        <x-input 
            id="password" 
            class="block mt-1 w-full" 
            type="password" 
            wire:model="password" 
            required 
            autocomplete="current-password" 
        />
        <x-input-error for="password" class="mt-2" />
        </div>

        <div class="block mt-4">
        <label for="remember_me" class="inline-flex items-center mb-5 cursor-pointer">
            <input 
                id="remember_me" 
                name="remember" 
                type="checkbox" 
                wire:model="remember" 
                class="sr-only peer" 
            />
            <div class="relative w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
            <span class="ms-3 text-sm font-medium text-gray-900 dark:text-gray-300">Angemeldet bleiben</span>
        </label>
        </div>

        <div class="flex items-center justify-end mt-4">
        @if (Route::has('password.request'))
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('password.request') }}">
                Hast du dein Passwort vergessen?
            </a>
        @endif
        <div class="flex flex-wrap justify-end gap-3 ml-3 max-md:flex-col max-md:items-end flex-row-reverse">
          <x-button>
              Einloggen
          </x-button>
          <x-button class="ms-0 md:ms-4" href="{{ route('register') }}" wire:navigate>
              Registrieren
          </x-button>
        </div>
        </div>
        </form>

        </div>
    </x-slot>
</x-layouts.auth-layout>



