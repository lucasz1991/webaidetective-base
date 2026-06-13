<x-mail::message>
# Einladung zu {{ $invitation->team->name }}

Du wurdest eingeladen, dem SocialScope-Arbeitsbereich **{{ $invitation->team->name }}** beizutreten. Dort könnt ihr Recherchen, Profile und Erkenntnisse gemeinsam verwalten.

@if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
Falls du noch kein SocialScope-Konto hast, kannst du zuerst eines erstellen:

<x-mail::button :url="route('register')">
Konto erstellen
</x-mail::button>

Anschließend kannst du über diese E-Mail dem Arbeitsbereich beitreten.
@endif

<x-mail::button :url="$acceptUrl" color="success">
Einladung annehmen
</x-mail::button>

Wenn du diese Einladung nicht erwartet hast, kannst du die E-Mail einfach ignorieren.

Viele Grüße,<br>
dein SocialScope Team
</x-mail::message>
