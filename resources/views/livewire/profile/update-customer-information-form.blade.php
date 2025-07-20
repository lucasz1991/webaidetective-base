<x-form-section submit="save">
    <x-slot name="title">
        {{ __('Kundeninformationen') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Aktualisiere alle Details deines Profils.') }}
    </x-slot>

    <x-slot name="form">
        <div class="col-span-6 grid grid-cols-1 md:grid-cols-2 gap-6 w-full">
            <!-- Vorname -->
            <div>
                <x-label for="first_name" value="{{ __('Vorname') }}" />
                <x-input id="first_name" type="text" class="mt-1 block w-full" wire:model="first_name" required autocomplete="given-name" />
                <x-input-error for="first_name" class="mt-2" />
            </div>

            <!-- Nachname -->
            <div>
                <x-label for="last_name" value="{{ __('Nachname') }}" />
                <x-input id="last_name" type="text" class="mt-1 block w-full" wire:model="last_name" required autocomplete="family-name" />
                <x-input-error for="last_name" class="mt-2" />
            </div>

            <!-- Telefonnummer -->
            <div>
                <x-label for="phone_number" value="{{ __('Telefonnummer') }}" />
                <x-input id="phone_number" type="text" class="mt-1 block w-full" wire:model="phone_number" autocomplete="tel" />
                <x-input-error for="phone_number" class="mt-2" />
            </div>

            <!-- Adresse -->
            <div>
                <x-label for="street" value="{{ __('Straße') }}" />
                <x-input id="street" type="text" class="mt-1 block w-full" wire:model="street" autocomplete="street-address" />
                <x-input-error for="street" class="mt-2" />
            </div>

            <!-- Stadt -->
            <div>
                <x-label for="city" value="{{ __('Stadt') }}" />
                <x-input id="city" type="text" class="mt-1 block w-full" wire:model="city" />
                <x-input-error for="city" class="mt-2" />
            </div>

            <!-- Bundesland -->
            <div>
                <x-label for="state" value="{{ __('Bundesland') }}" />
                <x-input id="state" type="text" class="mt-1 block w-full" wire:model="state" />
                <x-input-error for="state" class="mt-2" />
            </div>

            <!-- Postleitzahl -->
            <div>
                <x-label for="postal_code" value="{{ __('Postleitzahl') }}" />
                <x-input id="postal_code" type="text" class="mt-1 block w-full" wire:model="postal_code" />
                <x-input-error for="postal_code" class="mt-2" />
            </div>

            <!-- Land -->
            <div>
                <x-label for="country" value="{{ __('Land') }}" />
                <x-input id="country" type="text" class="mt-1 block w-full" wire:model="country" />
                <x-input-error for="country" class="mt-2" />
            </div>
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-action-message class="me-3" on="saved">
            {{ __('Gespeichert.') }}
        </x-action-message>

        <x-button wire:loading.attr="disabled">
            {{ __('Speichern') }}
        </x-button>
    </x-slot>
</x-form-section>
