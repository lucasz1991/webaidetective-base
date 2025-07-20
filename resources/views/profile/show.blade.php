
    <x-app-layout>
    @section('title')
        {{ __('Profil') }}
    @endsection
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Profil
            </h2>
        </x-slot>

        <div class=" pt-3 md:pt-12 bg-gray-100 antialiased" wire:loading.class="cursor-wait">
            <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
                @if (Laravel\Fortify\Features::canUpdateProfileInformation())
                    @livewire('profile.update-profile-information-form')

                    <x-section-border />
                @endif
                <!-- Customer Information Livewire-Komponente -->
                <div class="mt-10 sm:mt-0">
                    @livewire('profile.update-customer-information-form')
                </div>

                <x-section-border />
                @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::updatePasswords()))
                    <div class="mt-10 sm:mt-0">
                        @livewire('profile.update-password-form')
                    </div>

                    <x-section-border />
                @endif

                @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                    <div class="mt-10 sm:mt-0">
                        @livewire('profile.two-factor-authentication-form')
                    </div>

                    <x-section-border />
                @endif

                <div class="mt-10 sm:mt-0">
                    @livewire('profile.logout-other-browser-sessions-form')
                </div>


            </div>
        </div>
    </x-app-layout>

