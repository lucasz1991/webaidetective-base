@props(['status'])

@php
    $statusData = match ($status) {
        1 => ['text' => 'Bevorstehend', 'color' => 'text-yellow-500'],
        2 => ['text' => 'Aktiv', 'color' => 'text-green-500'],
        3 => ['text' => 'Abgelaufen', 'color' => 'text-orange-500'],
        4 => ['text' => 'Abgeschlossen', 'color' => 'text-green-500'],
        5 => ['text' => 'Bevorstehend', 'color' => 'text-blue-500'],
        6 => ['text' => 'Aktiv', 'color' => 'text-purple-500'],
        7 => ['text' => 'Storniert', 'color' => 'text-red-500'],
        8 => ['text' => 'Auszahlung beantragt', 'color' => 'text-green-500'],
        default => ['text' => 'Unbekannt', 'color' => 'text-gray-500'],
    };
@endphp

<span class="{{ $statusData['color'] }} font-semibold">
    {{ $statusData['text'] }}
</span>