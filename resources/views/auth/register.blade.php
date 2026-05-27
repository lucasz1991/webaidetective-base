<x-app-layout>
    <x-authentication-card>
        @php
            $inputClass = 'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100';
            $labelClass = 'mb-1.5 block text-sm font-semibold text-slate-700';
            $termsUrl = Route::has('terms.show') ? route('terms.show') : (Route::has('terms') ? route('terms') : url('/termsandconditions'));
            $privacyUrl = Route::has('policy.show') ? route('policy.show') : (Route::has('privacypolicy') ? route('privacypolicy') : url('/privacypolicy'));
        @endphp

        <div class="mx-auto max-w-lg">
            <div class="mb-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Neuer Zugang</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Registrieren</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Erstelle dein webaiDetective-Konto mit sicheren Zugangsdaten.
                </p>
            </div>

            <x-validation-errors class="mb-4" />

            <form method="POST" action="{{ route('register') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="name" class="{{ $labelClass }}">Name</label>
                    <input id="name" class="{{ $inputClass }}" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" placeholder="Dein Name">
                </div>

                <div>
                    <label for="email" class="{{ $labelClass }}">E-Mail</label>
                    <input id="email" class="{{ $inputClass }}" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" placeholder="name@example.com">
                </div>

                <div>
                    <label for="password" class="{{ $labelClass }}">Passwort</label>
                    <input id="password" class="{{ $inputClass }}" type="password" name="password" required autocomplete="new-password" placeholder="Passwort">
                </div>

                <div>
                    <label for="password_confirmation" class="{{ $labelClass }}">Passwort bestaetigen</label>
                    <input id="password_confirmation" class="{{ $inputClass }}" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Passwort wiederholen">
                </div>

                @if (Laravel\Jetstream\Jetstream::hasTermsAndPrivacyPolicyFeature())
                    <div>
                        <label for="terms" class="flex cursor-pointer gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <span class="relative mt-0.5 inline-flex items-center">
                                <input name="terms" id="terms" type="checkbox" required class="peer sr-only">
                                <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-teal-600 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-teal-500 peer-focus:ring-offset-2"></span>
                                <span class="absolute left-1 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                            </span>
                                <span class="text-sm leading-6 text-slate-700">
                                {!! __('I agree to the :terms_of_service and :privacy_policy', [
                                    'terms_of_service' => '<a target="_blank" href="'.$termsUrl.'" class="font-semibold text-teal-700 hover:text-teal-900">'.__('Terms of Service').'</a>',
                                    'privacy_policy' => '<a target="_blank" href="'.$privacyUrl.'" class="font-semibold text-teal-700 hover:text-teal-900">'.__('Privacy Policy').'</a>',
                                ]) !!}
                            </span>
                        </label>
                    </div>
                @endif

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <a class="text-sm font-semibold text-teal-700 hover:text-teal-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2" href="{{ route('login') }}">
                        Schon registriert?
                    </a>

                    <button type="submit" class="inline-flex h-11 items-center justify-center rounded-lg bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Konto erstellen
                    </button>
                </div>
            </form>
        </div>
    </x-authentication-card>
</x-app-layout>
