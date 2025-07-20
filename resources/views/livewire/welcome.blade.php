<div wire:loading.class="cursor-wait" class=" bg-gray-100">
    <section class="relative grid md:grid-cols-2 content-center overflow-hidden px-4 py-8 container mx-auto gap-6 md:gap-8 ">

        {{-- Teilnehmer-Infos --}}
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Teilnehmerdaten</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><strong>Name:</strong> {{ $teilnehmerDaten['teilnehmer']['name'] }}</div>
                <div><strong>Geburtsdatum:</strong> {{ $teilnehmerDaten['teilnehmer']['geburtsdatum'] }}</div>
                <div><strong>Teilnehmer-Nr:</strong> {{ $teilnehmerDaten['teilnehmer']['teilnehmerNr'] }}</div>
                <div><strong>Kunden-Nr:</strong> {{ $teilnehmerDaten['teilnehmer']['kundenNr'] }}</div>
                <div><strong>Stammklasse:</strong> {{ $teilnehmerDaten['teilnehmer']['stammklasse'] }}</div>
                <div><strong>Eignungstest:</strong> {{ $teilnehmerDaten['teilnehmer']['eignungstest'] }}</div>
            </div>
        </div>

        {{-- Maßnahme --}}
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Maßnahme</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><strong>Titel:</strong> {{ $teilnehmerDaten['massnahme']['titel'] }}</div>
                <div><strong>Zeitraum:</strong> {{ $teilnehmerDaten['massnahme']['zeitraum']['von'] }} – {{ $teilnehmerDaten['massnahme']['zeitraum']['bis'] }}</div>
                <div><strong>Bausteine:</strong> {{ $teilnehmerDaten['massnahme']['bausteine'] }}</div>
                <div><strong>Inhalte:</strong> {{ $teilnehmerDaten['massnahme']['inhalte'] }}</div>
            </div>
        </div>

        {{-- Vertrag --}}
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Vertrag</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><strong>Vertrag:</strong> {{ $teilnehmerDaten['vertrag']['vertrag'] }}</div>
                <div><strong>Kennung:</strong> {{ $teilnehmerDaten['vertrag']['kennung'] }}</div>
                <div><strong>Von:</strong> {{ $teilnehmerDaten['vertrag']['von'] }}</div>
                <div><strong>Bis:</strong> {{ $teilnehmerDaten['vertrag']['bis'] }}</div>
                <div><strong>Rechnungsnummer:</strong> {{ $teilnehmerDaten['vertrag']['rechnungsnummer'] }}</div>
                <div><strong>Abschlussdatum:</strong> {{ $teilnehmerDaten['vertrag']['abschlussdatum'] }}</div>
            </div>
        </div>

        {{-- Träger --}}
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Maßnahmenträger</h2>
            <div class="space-y-2">
                <div><strong>Institution:</strong> {{ $teilnehmerDaten['traeger']['institution'] }}</div>
                <div><strong>Ansprechpartner:</strong> {{ $teilnehmerDaten['traeger']['ansprechpartner'] }}</div>
                <div><strong>Adresse:</strong> {{ $teilnehmerDaten['traeger']['adresse'] }}</div>
            </div>
        </div>

        {{-- Bausteine --}}
        <div class="bg-white rounded-lg shadow-lg p-6 overflow-auto md:col-span-2">
            <h2 class="text-xl font-semibold mb-4">Bausteinübersicht</h2>
            <table class="min-w-full text-sm text-left border border-gray-200">
                <thead class="bg-gray-100 font-semibold">
                    <tr>
                        <th class="px-3 py-2">Block</th>
                        <th class="px-3 py-2">Abschnitt</th>
                        <th class="px-3 py-2">Beginn</th>
                        <th class="px-3 py-2">Ende</th>
                        <th class="px-3 py-2">Tage</th>
                        <th class="px-3 py-2">Baustein</th>
                        <th class="px-3 py-2">Schnitt</th>
                        <th class="px-3 py-2">Punkte</th>
                        <th class="px-3 py-2">Fehltage</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($teilnehmerDaten['bausteine'] as $item)
                        <tr class="border-t">
                            <td class="px-3 py-2">{{ $item['block'] }}</td>
                            <td class="px-3 py-2">{{ $item['abschnitt'] }}</td>
                            <td class="px-3 py-2">{{ $item['beginn'] }}</td>
                            <td class="px-3 py-2">{{ $item['ende'] }}</td>
                            <td class="px-3 py-2">{{ $item['tage'] }}</td>
                            <td class="px-3 py-2">{{ $item['baustein'] }}</td>
                            <td class="px-3 py-2">{{ $item['schnitt'] ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $item['punkte'] ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $item['fehltage'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Unterricht & Praktikum --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 md:col-span-2">
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4">Unterricht</h2>
                <ul class="space-y-1">
                    <li><strong>Tage:</strong> {{ $teilnehmerDaten['unterricht']['tage'] }}</li>
                    <li><strong>Einheiten:</strong> {{ $teilnehmerDaten['unterricht']['einheiten'] }}</li>
                    <li><strong>Note:</strong> {{ $teilnehmerDaten['unterricht']['note'] }}</li>
                    <li><strong>Schnitt:</strong> {{ $teilnehmerDaten['unterricht']['schnitt'] }}</li>
                    <li><strong>Punkte:</strong> {{ $teilnehmerDaten['unterricht']['punkte'] }}</li>
                    <li><strong>Fehltage:</strong> {{ $teilnehmerDaten['unterricht']['fehltage'] }}</li>
                </ul>
            </div>
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4">Praktikum</h2>
                <ul class="space-y-1">
                    <li><strong>Tage:</strong> {{ $teilnehmerDaten['praktikum']['tage'] }}</li>
                    <li><strong>Stunden:</strong> {{ $teilnehmerDaten['praktikum']['stunden'] }}</li>
                    <li><strong>Bemerkung:</strong> {{ $teilnehmerDaten['praktikum']['bemerkung'] ?? '—' }}</li>
                </ul>
            </div>
        </div>

    </section>
</div>
