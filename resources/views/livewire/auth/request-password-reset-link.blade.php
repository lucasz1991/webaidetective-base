<x-layouts.auth-layout>
    <x-slot name="title">
        Passwort vergessen
    </x-slot>

    <x-slot name="description">
        Fordere einen sicheren Link an und setze dein SocialScope-Passwort in wenigen Schritten zurueck.
    </x-slot>

    <x-slot name="form">
        @php
            $inputClass = 'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100';
        @endphp

        <div class="mx-auto max-w-md">
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Zugang wiederherstellen</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Reset-Link anfordern</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Gib die E-Mail-Adresse deines Kontos ein. Wir senden dir einen Link zum Zuruecksetzen.
                </p>
            </div>

            <x-validation-errors class="mb-4" />

            @if (session()->has('success'))
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            @if (session()->has('error'))
                <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                    {{ session('error') }}
                </div>
            @endif

            <form wire:submit.prevent="sendResetLink" class="space-y-5">
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-semibold text-slate-700">E-Mail</label>
                    <input
                        id="email"
                        wire:model="email"
                        class="{{ $inputClass }}"
                        type="email"
                        name="email"
                        :value="old('email')"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="name@example.com"
                    >
                    @error('email') <span class="mt-2 block text-sm text-rose-600">{{ $message }}</span> @enderror
                </div>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="sendResetLink"
                    class="inline-flex h-11 w-full items-center justify-center rounded-lg bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-70"
                >
                    <span wire:loading.remove wire:target="sendResetLink">Link anfordern</span>
                    <span wire:loading wire:target="sendResetLink">Link wird gesendet...</span>
                </button>

                <p class="text-center text-sm text-slate-600">
                    Passwort wieder da?
                    <a href="{{ route('login') }}" wire:navigate class="font-semibold text-teal-700 hover:text-teal-900">Einloggen</a>
                </p>
            </form>
        </div>
    </x-slot>
</x-layouts.auth-layout>
