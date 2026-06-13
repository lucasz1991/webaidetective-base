<x-layouts.auth-layout>
    <x-slot name="title">
        SocialScope
    </x-slot>

    <x-slot name="description">
        Sichere Anmeldung fuer deinen gebuchten Monitoring-Service.
    </x-slot>

    <x-slot name="form">
        <div class="space-y-6">
            {{ $slot }}
        </div>
    </x-slot>
</x-layouts.auth-layout>
