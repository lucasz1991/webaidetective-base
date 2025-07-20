<div class="bg-gray-100">
  <section id="makeup-exam" class="max-w-4xl mx-auto py-8 px-4">
    <h2 class="text-2xl font-bold mb-6">Antrag auf Nachprüfung</h2>

    <form x-data="{
      showDeleteUpload: false
    }" action="#" method="post" class="space-y-6 bg-white p-6 rounded shadow">

      <!-- Versteckte Felder -->
      <input type="hidden" name="tn_name" value="Müstermann, Mäx">
      <input type="hidden" name="tn_nummer" value="0000007">
      <input type="hidden" name="institut" value="Köln">
      <input type="hidden" name="email" value="mm@muster.com">
      <input type="hidden" name="send_date" value="28.05.2025 - 06:03">

      <div class="grid md:grid-cols-3 gap-4">
        <div><strong>Name:</strong> Müstermann, Mäx</div>
        <div><strong>Nummer:</strong> 0000007</div>
        <div class="text-right">
          <label for="klasse" class="block text-sm font-medium">Klasse</label>
          <input id="klasse" name="klasse" type="text" required maxlength="8"
                 class="mt-1 block w-full border-gray-300 rounded shadow-sm focus:ring-blue-500 focus:border-blue-500">
        </div>
      </div>

      <div>
        <p class="font-semibold mb-2">Ich beantrage gemäß Qualifizierungsordnung:</p>
        <div class="space-y-2">
          <label class="flex items-center">
            <input type="radio" name="wiederholung" value="wiederholung_1" required class="mr-2">
            Eine Nach-/Wiederholungsprüfung (20,00 €)
          </label>
          <label class="flex items-center">
            <input type="radio" name="wiederholung" value="wiederholung_2" required class="mr-2">
            Nachprüfung zur Ergebnisverbesserung (40,00 €)
          </label>
        </div>
      </div>

      <div class="grid md:grid-cols-2 gap-4">
        <div>
          <label for="nachKlTermin" class="block text-sm font-medium">Nachprüfungstermin</label>
          <select id="nachKlTermin" name="nachKlTermin" required
                  class="mt-1 block w-full border-gray-300 rounded shadow-sm focus:ring-blue-500 focus:border-blue-500">
            <option value="">Bitte Termin wählen</option>
            <option value="1749208500">06.06.2025 - 13:15</option>
            <option value="1750411800">20.06.2025 - 11:30</option>
            <option value="1751621400">04.07.2025 - 11:30</option>
            <option value="1752831000">18.07.2025 - 11:30</option>
            <option value="1754040600">01.08.2025 - 11:30</option>
          </select>
        </div>
        <div>
          <label for="nKlBaust" class="block text-sm font-medium">Baustein</label>
          <input id="nKlBaust" name="nKlBaust" type="text" maxlength="6" required placeholder="Baustein"
                 class="mt-1 block w-full border-gray-300 rounded shadow-sm">
        </div>
      </div>

      <div class="grid md:grid-cols-2 gap-4">
        <div>
          <label for="nKlDozent" class="block text-sm font-medium">Instruktor / Dozent</label>
          <input id="nKlDozent" name="nKlDozent" type="text" required placeholder="Dozent"
                 class="mt-1 block w-full border-gray-300 rounded shadow-sm">
        </div>
        <div>
          <label for="nKlOrig" class="block text-sm font-medium">Ursprüngliche Prüfung am</label>
          <input id="nKlOrig" name="nKlOrig" type="date" required
                 class="mt-1 block w-full border-gray-300 rounded shadow-sm">
        </div>
      </div>

      <div>
        <p class="font-semibold mb-2">Begründung</p>
        <div class="space-y-2">
          <label class="flex items-center">
            <input type="radio" name="grund" value="unter51" required class="mr-2">
            Ursprüngliche Prüfung unter 51 Punkte
          </label>
          <label class="flex items-center">
            <input type="radio" name="grund" value="krankMitAtest" required class="mr-2">
            Krankheit am Prüfungstag, <strong>mit Attest</strong>
          </label>
          <label class="flex items-center">
            <input type="radio" name="grund" value="krankOhneAtest" required class="mr-2">
            Krankheit am Prüfungstag, <strong>ohne Attest</strong>
          </label>
        </div>
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

      <div class="prose text-sm text-gray-600">
        <p>(*) Eine Nachprüfung ist kostenfrei, wenn ein Attest für den ursprünglichen Prüfungstag vorliegt.</p>
        <p>(**) Die Gebühr ist mit Abgabe der Anmeldung vor dem Nachprüfungstermin zu entrichten.</p>
      </div>

      <div class="text-center mt-6">
        <button type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded">
          Antrag auf Nachprüfung senden
        </button>
      </div>
    </form>
  </section>
</div>
