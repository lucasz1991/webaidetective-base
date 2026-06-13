<x-layouts.auth-layout>
    <x-slot name="title">
        SocialScope Workspace erstellen
    </x-slot>

    <x-slot name="description">
        Lege deinen Zugang an und starte mit Profilscans, Netzwerk-Map, Monitoring und dem Investigation Copilot.
    </x-slot>

    <x-slot name="form">
        @php
            $inputClass = 'block w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-950 placeholder:font-normal placeholder:text-slate-400 transition focus:border-cyan-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-cyan-100';
            $labelClass = 'mb-2 block text-sm font-black text-slate-700';
            $termsUrl = Route::has('terms.show') ? route('terms.show') : (Route::has('terms') ? route('terms') : url('/termsandconditions'));
            $privacyUrl = Route::has('policy.show') ? route('policy.show') : (Route::has('privacypolicy') ? route('privacypolicy') : url('/privacypolicy'));
        @endphp

        <div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <span class="inline-flex rounded-full bg-cyan-50 px-3 py-1.5 text-xs font-black uppercase tracking-[.16em] text-cyan-800">Neuer Workspace</span>
                    <h2 class="mt-5 text-3xl font-black tracking-tight text-slate-950">Konto erstellen</h2>
                    <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-600">Zugangsdaten und persoenliche Angaben werden fuer dein SocialScope-Konto verwendet.</p>
                </div>
                <a href="{{ route('login') }}" wire:navigate class="shrink-0 text-sm font-black text-cyan-700 hover:text-cyan-900">Bereits registriert?</a>
            </div>

            <form wire:submit.prevent="register" class="mt-8 space-y-7">
                <section class="rounded-2xl border border-slate-200 p-5">
                    <div class="mb-5 flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-50 font-black text-sky-700">1</span>
                        <div>
                            <h3 class="font-black text-slate-950">Sicherer Zugang</h3>
                            <p class="text-xs text-slate-500">E-Mail, Benutzername und Passwort</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="email" class="{{ $labelClass }}">E-Mail-Adresse</label>
                            <input type="email" id="email" wire:model="email" class="{{ $inputClass }}" placeholder="name@example.com" autocomplete="email">
                            <x-input-error for="email" class="mt-2" />
                        </div>
                        <div>
                            <label for="username" class="{{ $labelClass }}">Benutzername</label>
                            <input type="text" id="username" wire:model="username" class="{{ $inputClass }}" placeholder="Dein Benutzername" autocomplete="username">
                            <x-input-error for="username" class="mt-2" />
                        </div>
                        <div>
                            <label for="password" class="{{ $labelClass }}">Passwort</label>
                            <input type="password" id="password" wire:model="password" class="{{ $inputClass }}" placeholder="Mindestens 10 Zeichen" autocomplete="new-password">
                            <p class="mt-2 text-xs text-slate-500">Mindestens 10 Zeichen, ein Grossbuchstabe und ein Sonderzeichen.</p>
                            <x-input-error for="password" class="mt-2" />
                        </div>
                        <div>
                            <label for="password_confirmation" class="{{ $labelClass }}">Passwort bestaetigen</label>
                            <input type="password" id="password_confirmation" wire:model="password_confirmation" class="{{ $inputClass }}" placeholder="Passwort wiederholen" autocomplete="new-password">
                            <x-input-error for="password_confirmation" class="mt-2" />
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 p-5">
                    <div class="mb-5 flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 font-black text-emerald-700">2</span>
                        <div>
                            <h3 class="font-black text-slate-950">Persoenliche Angaben</h3>
                            <p class="text-xs text-slate-500">Name und optionale Kontaktdaten</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="first_name" class="{{ $labelClass }}">Vorname</label>
                            <input type="text" id="first_name" wire:model="first_name" class="{{ $inputClass }}" placeholder="Vorname" autocomplete="given-name">
                            <x-input-error for="first_name" class="mt-2" />
                        </div>
                        <div>
                            <label for="last_name" class="{{ $labelClass }}">Nachname</label>
                            <input type="text" id="last_name" wire:model="last_name" class="{{ $inputClass }}" placeholder="Nachname" autocomplete="family-name">
                            <x-input-error for="last_name" class="mt-2" />
                        </div>
                        <div>
                            <label for="phone_number" class="{{ $labelClass }}">Telefonnummer <span class="font-semibold text-slate-400">(optional)</span></label>
                            <input type="tel" id="phone_number" wire:model="phone_number" class="{{ $inputClass }}" placeholder="+49 ..." autocomplete="tel">
                            <x-input-error for="phone_number" class="mt-2" />
                        </div>
                        <div>
                            <label for="street" class="{{ $labelClass }}">Strasse <span class="font-semibold text-slate-400">(optional)</span></label>
                            <input type="text" id="street" wire:model="street" class="{{ $inputClass }}" placeholder="Strasse und Hausnummer" autocomplete="street-address">
                            <x-input-error for="street" class="mt-2" />
                        </div>
                        <div>
                            <label for="city" class="{{ $labelClass }}">Stadt <span class="font-semibold text-slate-400">(optional)</span></label>
                            <input type="text" id="city" wire:model="city" class="{{ $inputClass }}" placeholder="Stadt" autocomplete="address-level2">
                            <x-input-error for="city" class="mt-2" />
                        </div>
                        <div class="grid grid-cols-[.75fr_1.25fr] gap-3">
                            <div>
                                <label for="postal_code" class="{{ $labelClass }}">PLZ</label>
                                <input type="text" id="postal_code" wire:model="postal_code" class="{{ $inputClass }}" placeholder="PLZ" autocomplete="postal-code">
                                <x-input-error for="postal_code" class="mt-2" />
                            </div>
                            <div>
                                <label for="country" class="{{ $labelClass }}">Land</label>
                                <input type="text" id="country" wire:model="country" class="{{ $inputClass }}" placeholder="Land" autocomplete="country-name">
                                <x-input-error for="country" class="mt-2" />
                            </div>
                        </div>
                    </div>
                </section>

                <label for="terms" class="flex cursor-pointer gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-cyan-200">
                    <span class="relative mt-0.5 inline-flex shrink-0 items-center">
                        <input wire:model="terms" id="terms" type="checkbox" class="peer sr-only">
                        <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-cyan-600 peer-focus:ring-4 peer-focus:ring-cyan-100"></span>
                        <span class="absolute left-1 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                    </span>
                    <span class="text-sm leading-6 text-slate-700">
                        Ich stimme den
                        <a href="{{ $termsUrl }}" class="font-black text-cyan-700 hover:text-cyan-900">Allgemeinen Geschaeftsbedingungen</a>
                        und der
                        <a href="{{ $privacyUrl }}" class="font-black text-cyan-700 hover:text-cyan-900">Datenschutzerklaerung</a>
                        zu.
                    </span>
                </label>
                <x-input-error for="terms" class="-mt-5" />

                <div class="flex flex-col gap-4 rounded-2xl bg-slate-950 p-5 text-white sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-black">Bereit fuer deinen ersten Scan?</p>
                        <p class="mt-1 text-xs leading-5 text-slate-300">Nach der Registrierung gelangst du direkt in deinen SocialScope Workspace.</p>
                    </div>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="register"
                        class="inline-flex h-12 shrink-0 items-center justify-center rounded-2xl bg-white px-6 text-sm font-black text-slate-950 transition hover:bg-cyan-50 disabled:cursor-wait disabled:opacity-70"
                    >
                        <span wire:loading.remove wire:target="register">Workspace erstellen</span>
                        <span wire:loading wire:target="register">Konto wird erstellt...</span>
                    </button>
                </div>
            </form>
        </div>
    </x-slot>
</x-layouts.auth-layout>
