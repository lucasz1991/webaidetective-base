<x-layouts.auth-layout>
    <x-slot name="title">
        Willkommen zurueck
    </x-slot>

    <x-slot name="description">
        Melde dich bei SocialScope an und arbeite direkt mit deinen Profilen, Scans, Netzwerken und AI-Auswertungen weiter.
    </x-slot>

    <x-slot name="form">
        @php
            $inputClass = 'block w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-950 placeholder:font-normal placeholder:text-slate-400 transition focus:border-cyan-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-cyan-100';
        @endphp

        <div class="mx-auto max-w-md">
            <div>
                <span class="inline-flex rounded-full bg-cyan-50 px-3 py-1.5 text-xs font-black uppercase tracking-[.16em] text-cyan-800">
                    SocialScope Login
                </span>
                <h2 class="mt-5 text-3xl font-black tracking-tight text-slate-950">In deinen Workspace einloggen</h2>
                <p class="mt-3 text-sm leading-7 text-slate-600">
                    Greife auf laufende Monitorings, gespeicherte Netzwerkdaten und deine letzten Copilot-Auswertungen zu.
                </p>
            </div>

            <form wire:submit.prevent="login" class="mt-8 space-y-5">
                @csrf

                <div>
                    <label for="email" class="mb-2 block text-sm font-black text-slate-700">E-Mail-Adresse</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4 6h16v12H4V6Zm0 1 8 6 8-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <input
                            id="email"
                            class="{{ $inputClass }} pl-12"
                            type="email"
                            wire:model="email"
                            required
                            autofocus
                            autocomplete="username"
                            placeholder="name@example.com"
                        >
                    </div>
                    <x-input-error for="email" class="mt-2" />
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between gap-4">
                        <label for="password" class="block text-sm font-black text-slate-700">Passwort</label>
                        @if (Route::has('password.request'))
                            <a class="text-sm font-black text-cyan-700 transition hover:text-cyan-900" href="{{ route('password.request') }}" wire:navigate>
                                Passwort vergessen?
                            </a>
                        @endif
                    </div>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                        <input
                            id="password"
                            class="{{ $inputClass }} pl-12"
                            type="password"
                            wire:model="password"
                            required
                            autocomplete="current-password"
                            placeholder="Dein Passwort"
                        >
                    </div>
                    <x-input-error for="password" class="mt-2" />
                </div>

                <label for="remember_me" class="flex cursor-pointer items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3.5 transition hover:border-cyan-200 hover:bg-cyan-50/40">
                    <span>
                        <span class="block text-sm font-black text-slate-800">Angemeldet bleiben</span>
                        <span class="mt-0.5 block text-xs text-slate-500">Nur auf deinem persoenlichen Geraet aktivieren</span>
                    </span>
                    <span class="relative inline-flex items-center">
                        <input id="remember_me" name="remember" type="checkbox" wire:model="remember" class="peer sr-only">
                        <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-cyan-600 peer-focus:ring-4 peer-focus:ring-cyan-100"></span>
                        <span class="absolute left-1 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                    </span>
                </label>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="login"
                    class="inline-flex h-12 w-full items-center justify-center rounded-2xl bg-slate-950 px-5 text-sm font-black text-white shadow-xl shadow-slate-900/15 transition hover:-translate-y-0.5 hover:bg-cyan-700 focus:outline-none focus:ring-4 focus:ring-cyan-100 disabled:cursor-wait disabled:opacity-70"
                >
                    <span wire:loading.remove wire:target="login">Workspace oeffnen</span>
                    <span wire:loading wire:target="login" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                        </svg>
                        Zugang wird geprueft
                    </span>
                </button>

                <div class="rounded-2xl bg-slate-50 px-4 py-3 text-center text-sm text-slate-600">
                    Noch kein SocialScope-Konto?
                    <a href="{{ route('register') }}" wire:navigate class="font-black text-cyan-700 hover:text-cyan-900">
                        Jetzt registrieren
                    </a>
                </div>
            </form>
        </div>
    </x-slot>
</x-layouts.auth-layout>
