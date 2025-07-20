<div class="bg-gray-100">
    <section id="absence-form" class="max-w-4xl mx-auto py-8 px-4">
        <h2 class="text-2xl font-bold mb-6">Fehlzeit entschuldigen</h2>

        <form x-data="{
            fehltag: false,
            abw_grund: '',
            showGrundBox: false,
            showDeleteUpload: false
        }" action="#" method="post" class="space-y-6 bg-white p-6 rounded shadow">

            <!-- Versteckte Felder -->
            <input type="hidden" name="tn_name" value="Müstermann, Mäx">
            <input type="hidden" name="tn_nummer" value="0000007">
            <input type="hidden" name="institut" value="Köln">
            <input type="hidden" name="email" value="mm@muster.com">
            <input type="hidden" name="send_date" value="28.05.2025 - 06:02">

            <div class="grid md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <label class="font-medium">Name:</label>
                    <p class="text-gray-700 font-semibold">Müstermann, Mäx</p>
                </div>
                <div>
                    <label for="klasse" class="block text-sm font-medium">Klasse</label>
                    <input id="klasse" name="klasse" type="text" required maxlength="8" placeholder="Klasse"
                        class="mt-1 block w-full border-gray-300 rounded shadow-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>

            <div class="grid md:grid-cols-3 gap-4 items-end">
                <div>
                    <label class="inline-flex items-center space-x-2">
                        <input type="checkbox" x-model="fehltag" name="fehltag" value="JA"
                            class="text-blue-600 border-gray-300 rounded">
                        <span>Ganztägig gefehlt</span>
                    </label>
                </div>
                <div class="md:col-span-2">
                    <label for="fehlDatum" class="block text-sm font-medium">Datum</label>
                    <input id="fehlDatum" name="fehlDatum" type="date" required
                        class="mt-1 block w-full border-gray-300 rounded shadow-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label for="fehlUhrGek" class="block text-sm font-medium">Später gekommen (Uhrzeit)</label>
                    <input id="fehlUhrGek" name="fehlUhrGek" type="time" min="08:00" max="23:00"
                        class="mt-1 block w-full border-gray-300 rounded shadow-sm">
                </div>
                <div>
                    <label for="fehlUhrGeg" class="block text-sm font-medium">Früher gegangen (Uhrzeit)</label>
                    <input id="fehlUhrGeg" name="fehlUhrGeg" type="time" min="08:00" max="23:00"
                        class="mt-1 block w-full border-gray-300 rounded shadow-sm">
                </div>
            </div>

            <div class="space-y-2">
                <label class="font-medium block">Grund der Fehlzeit</label>
                <div class="flex items-center space-x-4">
                    <label class="inline-flex items-center">
                        <input type="radio" name="abw_grund" value="abw_wichtig" required @change="showGrundBox = true"
                            class="text-blue-600 border-gray-300">
                        <span class="ml-2">Mit wichtigem Grund</span>
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" name="abw_grund" value="abw_unwichtig" required @change="showGrundBox = false"
                            class="text-blue-600 border-gray-300">
                        <span class="ml-2">Ohne wichtigen Grund</span>
                    </label>
                </div>

                <div x-show="showGrundBox" class="mt-2">
                    <label for="grund_item" class="block text-sm font-medium">Grund auswählen</label>
                    <select name="grund_item" id="grund_item"
                        class="mt-1 block w-full border-gray-300 rounded shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        <option value="">?</option>
                        <option>Wohnungswechsel</option>
                        <option>Krankheit</option>
                        <option>Eheschließung des Teilnehmers / eines Kindes</option>
                        <option>Ehejubiläum des Teilnehmers, der Eltern oder Schwiegereltern</option>
                        <option>Schwere Erkrankungen des Ehegatten oder eines Kindes</option>
                        <option>Niederkunft der Ehefrau</option>
                        <option>Tod des Ehegatten, eines Kindes, eines Eltern- oder Schwiegerelternteils</option>
                        <option>Wahrnehmung amtlicher Termine</option>
                        <option>Ausübung öffentlicher Ehrenämter</option>
                        <option>Religiöse Feste</option>
                        <option>Katastrophenschutz-Einsätze</option>
                    </select>
                </div>
            </div>

            <div>
                <label for="begruendung" class="block text-sm font-medium">Sonstige Begründung</label>
                <textarea id="begruendung" name="begruendung" maxlength="400"
                    placeholder="max. 400 Zeichen"
                    class="mt-1 block w-full border-gray-300 rounded shadow-sm focus:ring-blue-500 focus:border-blue-500"></textarea>
            </div>

            <div class="border-t pt-4">
                <label class="block text-sm font-medium mb-2">Anlage (jpg, png, gif, pdf)</label>
                <div class="flex items-center space-x-4">
                    <button type="button" class="upload-btn bg-gray-100 border px-4 py-2 rounded text-sm">Anlage hinzufügen</button>
                    <span id="upload_datei" class="text-gray-500">Keine Anlage hinzugefügt</span>
                    <span x-show="showDeleteUpload" id="del_upload">
                        <a href="#" class="text-red-600 text-sm">[ löschen ]</a>
                    </span>
                </div>
            </div>

            <div class="text-center">
                <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded">
                    Fehlzeit absenden</button>
            </div>
        </form>
    </section>
</div>
