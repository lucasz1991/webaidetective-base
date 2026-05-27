<x-layouts.auth-layout>
    <x-slot name="title">
        Konto erstellen
    </x-slot>

    <x-slot name="description">
        Lege deinen webaiDetective-Zugang an und starte mit strukturiertem Profil-Monitoring, Analyseverlauf und Benachrichtigungen.
    </x-slot>

    <x-slot name="form">
        @php
            $inputClass = 'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100';
            $labelClass = 'mb-1.5 block text-sm font-semibold text-slate-700';
            $termsUrl = Route::has('terms.show') ? route('terms.show') : (Route::has('terms') ? route('terms') : url('/termsandconditions'));
            $privacyUrl = Route::has('policy.show') ? route('policy.show') : (Route::has('privacypolicy') ? route('privacypolicy') : url('/privacypolicy'));
        @endphp

        <div>
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Neuer Zugang</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Registrieren</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Erstelle dein Konto mit sicheren Zugangsdaten und deinen Kontaktdaten.
                </p>
            </div>

            <form wire:submit.prevent="register" class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label for="email" class="{{ $labelClass }}">E-Mail</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        wire:model="email"
                        value="{{ old('email') }}"
                        class="{{ $inputClass }}"
                        placeholder="name@example.com"
                        autocomplete="username"
                    >
                    <x-input-error for="email" class="mt-2" />
                </div>

                <div>
                    <label for="username" class="{{ $labelClass }}">Benutzername</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        wire:model="username"
                        value="{{ old('username') }}"
                        class="{{ $inputClass }}"
                        placeholder="Benutzername"
                        autocomplete="username"
                    >
                    <x-input-error for="username" class="mt-2" />
                </div>

                <div>
                    <label for="password" class="{{ $labelClass }}">Passwort</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        wire:model="password"
                        class="{{ $inputClass }}"
                        placeholder="Mindestens 10 Zeichen"
                        autocomplete="new-password"
                    >
                    <x-input-error for="password" class="mt-2" />
                </div>

                <div>
                    <label for="password_confirmation" class="{{ $labelClass }}">Passwort bestaetigen</label>
                    <input
                        type="password"
                        id="password_confirmation"
                        name="password_confirmation"
                        wire:model="password_confirmation"
                        class="{{ $inputClass }}"
                        placeholder="Passwort wiederholen"
                        autocomplete="new-password"
                    >
                    <x-input-error for="password_confirmation" class="mt-2" />
                </div>

                <div>
                    <label for="first_name" class="{{ $labelClass }}">Vorname</label>
                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        wire:model="first_name"
                        value="{{ old('first_name') }}"
                        class="{{ $inputClass }}"
                        placeholder="Vorname"
                        autocomplete="given-name"
                    >
                    <x-input-error for="first_name" class="mt-2" />
                </div>

                <div>
                    <label for="last_name" class="{{ $labelClass }}">Nachname</label>
                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        wire:model="last_name"
                        value="{{ old('last_name') }}"
                        class="{{ $inputClass }}"
                        placeholder="Nachname"
                        autocomplete="family-name"
                    >
                    <x-input-error for="last_name" class="mt-2" />
                </div>

                <div>
                    <label for="phone_number" class="{{ $labelClass }}">Telefonnummer</label>
                    <input
                        type="tel"
                        id="phone_number"
                        name="phone_number"
                        wire:model="phone_number"
                        value="{{ old('phone_number') }}"
                        class="{{ $inputClass }}"
                        placeholder="Telefonnummer"
                        autocomplete="tel"
                    >
                    <x-input-error for="phone_number" class="mt-2" />
                </div>

                <div>
                    <label for="street" class="{{ $labelClass }}">Strasse</label>
                    <input
                        type="text"
                        id="street"
                        name="street"
                        wire:model="street"
                        value="{{ old('street') }}"
                        class="{{ $inputClass }}"
                        placeholder="Strasse und Hausnummer"
                        autocomplete="street-address"
                    >
                    <x-input-error for="street" class="mt-2" />
                </div>

                <div>
                    <label for="city" class="{{ $labelClass }}">Stadt</label>
                    <input
                        type="text"
                        id="city"
                        name="city"
                        wire:model="city"
                        value="{{ old('city') }}"
                        class="{{ $inputClass }}"
                        placeholder="Stadt"
                        autocomplete="address-level2"
                    >
                    <x-input-error for="city" class="mt-2" />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="postal_code" class="{{ $labelClass }}">Postleitzahl</label>
                        <input
                            type="text"
                            id="postal_code"
                            name="postal_code"
                            wire:model="postal_code"
                            value="{{ old('postal_code') }}"
                            class="{{ $inputClass }}"
                            placeholder="PLZ"
                            autocomplete="postal-code"
                        >
                        <x-input-error for="postal_code" class="mt-2" />
                    </div>

                    <div>
                        <label for="country" class="{{ $labelClass }}">Land</label>
                        <input
                            type="text"
                            id="country"
                            name="country"
                            wire:model="country"
                            value="{{ old('country') }}"
                            class="{{ $inputClass }}"
                            placeholder="Land"
                            autocomplete="country-name"
                        >
                        <x-input-error for="country" class="mt-2" />
                    </div>
                </div>

                <div class="sm:col-span-2">
                    <label for="terms" class="flex cursor-pointer gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <span class="relative mt-0.5 inline-flex items-center">
                            <input wire:model="terms" id="terms" name="terms" type="checkbox" class="peer sr-only">
                            <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-teal-600 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-teal-500 peer-focus:ring-offset-2"></span>
                            <span class="absolute left-1 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                        </span>
                        <span class="text-sm leading-6 text-slate-700">
                            Ich stimme den
                            <a href="{{ $termsUrl }}" class="font-semibold text-teal-700 hover:text-teal-900">Allgemeinen Geschaeftsbedingungen</a>
                            und der
                            <a href="{{ $privacyUrl }}" class="font-semibold text-teal-700 hover:text-teal-900">Datenschutzerklaerung</a>
                            zu.
                        </span>
                    </label>
                    <x-input-error for="terms" class="mt-2" />
                </div>

                <div class="flex flex-col gap-3 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-slate-600">
                        Du hast schon ein Konto?
                        <a href="{{ route('login') }}" wire:navigate class="font-semibold text-teal-700 hover:text-teal-900">
                            Einloggen
                        </a>
                    </p>

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="register"
                        class="inline-flex h-11 items-center justify-center rounded-lg bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-70"
                    >
                        <span wire:loading.remove wire:target="register">Konto erstellen</span>
                        <span wire:loading wire:target="register">Konto wird erstellt...</span>
                    </button>
                </div>
            </form>
        </div>
    </x-slot>
</x-layouts.auth-layout>
