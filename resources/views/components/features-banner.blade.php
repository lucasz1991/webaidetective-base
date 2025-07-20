<section class="py-12 bg-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center">
            <h2 class="text-2xl font-bold text-gray-800">Warum MiniFinds?</h2>
            <p class="mt-4 text-gray-600">Entdecke die Vorteile unseres Marktplatzes</p>
        </div>
        <div class="mt-10 grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="text-center bg-white shadow p-6">
                <img src="{{ asset('site-images/1.jpg') }}" alt="Bequem" class="mx-auto w-full mb-4">
                <h3 class="text-lg font-semibold text-gray-800">Bequem und stressfrei</h3>
                <p class="mt-2 text-gray-600">Bring deine Artikel vorbei und richte dein Regal ein. Um den Rest kümmern wir uns.</p>
            </div>
            <div class="text-center bg-white shadow p-6">
                <img src="{{ asset('site-images/3.jpg') }}" alt="Nachhaltig" class="mx-auto w-full mb-4">
                <h3 class="text-lg font-semibold text-gray-800">Nachhaltig</h3>
                <p class="mt-2 text-gray-600">Gib gut erhaltener Kinderkleidung und Spielzeug eine neue Chance.</p>
            </div>
            <div class="text-center bg-white shadow p-6">
                <img src="{{ asset('site-images/2.jpg') }}" alt="Transparent" class="mx-auto w-full mb-4">
                <h3 class="text-lg font-semibold text-gray-800">Transparent</h3>
                <p class="mt-2 text-gray-600">Behalte jederzeit den Überblick über deine Verkäufe und Einnahmen.</p>
            </div>
        </div>
        <div class="text-center mt-8">
            <x-button  href="/howto" wire:navigate >
                So funktioniert's
            </x-button>
        </div>
    </div>
</section>