<x-layouts.auth-layout>
    <x-slot name="title">
        Neues Passwort
    </x-slot>

    <x-slot name="description">
        Vergib ein neues Passwort fuer deinen SocialScope-Zugang und kehre direkt in deinen Workspace zurueck.
    </x-slot>

    <x-slot name="form">
        @php
            $inputClass = 'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100';
        @endphp

        <div class="mx-auto max-w-md">
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Passwort aktualisieren</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Neues Passwort setzen</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Waehle ein starkes Passwort mit mindestens 10 Zeichen, Grossbuchstaben und Sonderzeichen.
                </p>
            </div>

            <x-validation-errors class="mb-4" />

            @if (session()->has('status'))
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            @if (session()->has('error'))
                <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                    {{ session('error') }}
                </div>
            @endif

            <form wire:submit.prevent="resetPassword" class="space-y-5">
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-semibold text-slate-700">E-Mail-Adresse</label>
                    <input
                        wire:model="email"
                        id="email"
                        type="email"
                        class="{{ $inputClass }}"
                        autocomplete="username"
                        placeholder="name@example.com"
                    >
                    @error('email') <span class="mt-2 block text-sm text-rose-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="password" class="mb-1.5 block text-sm font-semibold text-slate-700">Neues Passwort</label>
                    <input
                        wire:model="password"
                        id="password"
                        type="password"
                        class="{{ $inputClass }}"
                        autocomplete="new-password"
                        placeholder="Neues Passwort"
                    >
                    @error('password') <span class="mt-2 block text-sm text-rose-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="mb-1.5 block text-sm font-semibold text-slate-700">Passwort bestaetigen</label>
                    <input
                        wire:model="password_confirmation"
                        id="password_confirmation"
                        type="password"
                        class="{{ $inputClass }}"
                        autocomplete="new-password"
                        placeholder="Passwort wiederholen"
                    >
                </div>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="resetPassword"
                    class="inline-flex h-11 w-full items-center justify-center rounded-lg bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-70"
                >
                    <span wire:loading.remove wire:target="resetPassword">Passwort zuruecksetzen</span>
                    <span wire:loading wire:target="resetPassword">Passwort wird gesetzt...</span>
                </button>
            </form>
        </div>
    </x-slot>
</x-layouts.auth-layout>
