<div class="">
    <!-- Begrüßung als Card -->
    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
        <h1 class="text-xl  text-gray-800">Willkommen, Max Mustermann!</h1>
        <p class="text-gray-600 mt-1">Dein persönliches Dashboard mit allen Kursen und Teilnehmern.</p>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
        <!-- Linke Spalte: Begrüßung + Kurse -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Kursliste als Card -->
            <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
                <h2 class="text-xl  text-gray-700 mb-4">Aktuelle Kurse</h2>
                <livewire:tutor.courses.courses-list-preview />
                <div class="mt-4">
                    <a href="{{ route('tutor.courses') }}" wire:navigate class="text-blue-600 text-sm hover:underline">Alle Kurse ansehen →</a>
                </div>
            </div>
        </div>
        <!-- Rechte Spalte: Zusatzinfos als einzelne Cards -->
        <div class="space-y-6">
            <!-- Nachrichten -->
            <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
                <h3 class="text-lg  text-gray-700 mb-2">Nachrichten</h3>
                <!-- Nachrichtenliste -->
                @php
                    $receivedMessages = [];
                @endphp
                @forelse($receivedMessages as $message)
                    <div @click="modalOpen = true; open = false; selectedMessage = { subject: '{{ $message->subject }}', body: '{!! addslashes($message->message) !!}', createdAt: '{{ $message->created_at->diffForHumans() }}' }; $wire.setMessageStatus({{ $message->id }}); " 
                        class="flex items-center p-4 hover:bg-slate-50 cursor-pointer @if($message->status == 1) bg-blue-200 @endif">
                        <div class="block h-10 w-10 size-4 flex-none rounded-full">
                            <x-application-logo class="w-10" />
                        </div>
                        <div class="ml-4 flex-auto">    
                            <div class="font-medium">{{ $message->subject }}</div>
                            <div class="mt-1 text-slate-700">
                                {{ Str::limit(strip_tags($message->message), 40) }}
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-4 text-center text-gray-400">
                        Keine  Nachrichten
                    </div>
                @endforelse
                    <a href="{{ route('messages') }}" class="block text-sm text-blue-600 hover:underline mt-3">Alle Nachrichten ansehen →</a>
            </div>
            <!-- News -->
            <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
                <h3 class="text-lg  text-gray-700 mb-2">News</h3>
                <ul class="text-sm text-gray-700 space-y-1">
                    <li><strong>Neue Kursplattform</strong> startet im August</li>
                    <li><strong>Update:</strong> Uploads jetzt möglich</li>
                    <li><strong>Admin:</strong> Systemwartung am 20.07.</li>
                </ul>
                <a href="#" class="block text-sm text-blue-600 hover:underline mt-3">Weitere News →</a>
            </div> 
            <!-- Termine -->
            <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
                <h3 class="text-lg  text-gray-700 mb-2">Nächste Termine</h3>
                <ul class="text-sm text-gray-700 space-y-1">
                    <li><strong>15.07.:</strong> Mathe – Trigonometrie</li>
                    <li><strong>17.07.:</strong> Englisch B1 – Speaking</li>
                    <li><strong>18.07.:</strong> Physik – Prüfungsvorbereitung</li>
                </ul>
                <a href="#" class="block text-sm text-green-600 hover:underline mt-3">Zum Kalender →</a>
            </div>
        </div>
    </div>
</div>
