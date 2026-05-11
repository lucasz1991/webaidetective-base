# Instagram Session & Cookies Integration

Dein Scraper lädt jetzt automatisch deine Instagram-Session/Cookies und aktualisiert sie nach jedem Scrape!

## 🚀 Schnellstart

### 1️⃣ Firefox-Cookies exportieren

**Option A: Automatisch mit Node.js (empfohlen)**

Zuerst `sqlite3` installieren:
```bash
npm install sqlite3
```

Dann einmalig ausführen:
```bash
node resources/node/scraper/export-firefox-cookies.cjs
```

Das Script:
- Findet automatisch dein Firefox-Profil
- Liest die `cookies.sqlite` Datei aus
- Speichert die Instagram/Facebook-Cookies in `storage/app/cookies/instagram-cookies.json`

**Option B: Manuell via Browser-Extension**

1. Installiere die Extension "[Export Cookies](https://addons.mozilla.org/firefox/addon/export-cookies/)" in Firefox
2. Melde dich in Instagram an
3. Öffne die Extension → rechts oben speichern als JSON
4. Speichere die Datei unter: `storage/app/cookies/instagram-cookies.json`

### 2️⃣ Scraper verwenden

Der Scraper funktioniert nun wie gewohnt:

```bash
node scrape-instagram.cjs @username
```

Automatisch wird jetzt:
✅ Die gespeicherte Instagram-Session geladen  
✅ Deine eingeloggte Session genutzt  
✅ Nach dem Scrape die aktuellen Cookies gespeichert (Session bleibt frisch)

---

## 📁 Verzeichnis-Struktur

```
storage/app/
├── cookies/
│   └── instagram-cookies.json    ← Hier werden deine Cookies gespeichert
├── public/screenshots/instagram/
│   └── [username]/
│       ├── profile-page-*.html
│       └── profile-snapshot-*.png
└── tmp/
    └── ... (temp files)
```

---

## 🔍 Firefox Cookies Speicherort

Auf Windows findest du die Cookies hier:

```
C:\Users\[DEIN_BENUTZERNAME]\AppData\Roaming\Mozilla\Firefox\Profiles\[RANDOM_STRING].default-release\cookies.sqlite
```

**So öffnest du den Ordner schnell:**
1. Drücke `Win + R`
2. Gib ein: `%APPDATA%\Mozilla\Firefox\Profiles\`
3. Doppelklick auf den `.default-release` Ordner

---

## ✨ Was ändert sich?

### Früher (ohne Session)
- Scraper greift auf öffentlich sichtbare Instagram-Profile zu
- Private/gesperrte Profile nicht erreichbar
- Häufig Rate-Limit & Blockierungen

### Jetzt (mit Session)
- Nutzt deine eingeloggte Session
- Private Profile erreichbar (wenn du Zugriff hast)
- Höhere Success-Rate
- Session wird automatisch aktualisiert

---

## 🔄 Session aktualisieren

Wenn deine Session abgelaufen ist:

1. Öffne Firefox
2. Melde dich neu bei Instagram an
3. Führe aus:
   ```bash
   node resources/node/scraper/export-firefox-cookies.cjs
   ```
4. Fertig! Die gespeicherten Cookies sind aktuell

---

## 🛡️ Sicherheit

- Cookies werden lokal unter `storage/app/cookies/` gespeichert (nicht im Repo)
- Nur Instagram/Facebook-Cookies werden gespeichert
- Dein Passwort wird NICHT gespeichert, nur die Session
- Falls nötig: Sie müssen selbst in `.gitignore` ausgeschlossen werden

---

## 🐛 Troubleshooting

### "Keine Instagram-Cookies gefunden"
```
❌ Error: Keine Instagram/Facebook-Cookies gefunden.
```

**Lösung:**
1. Stelle sicher, dass du in Firefox bei Instagram angemeldet bist
2. Besuche mindestens einmal `instagram.com`
3. Stelle sicher, dass Cookies in Firefox aktiviert sind
4. Versuche es erneut

### "SQLite3 Fehler"
Falls du das Export-Script ohne `sqlite3` ausführst:

```bash
npm install sqlite3
```

Oder nutze die manuelle Export-Option mit der Browser-Extension.

### "Cookies werden nicht geladen"
- Überprüfe, ob die Datei existiert: `storage/app/cookies/instagram-cookies.json`
- Datei muss gültig JSON sein (mit Browser öffnen zum Prüfen)
- Versuche Cookies neu zu exportieren

---

## 📝 Logs

Der Scraper zeigt jetzt auch, ob Cookies geladen/gespeichert wurden:

```json
{
  "notes": [
    "Instagram-Cookies aus Speicher geladen.",
    "Aktualisierte Instagram-Cookies gespeichert."
  ]
}
```

---

✅ **Setup abgeschlossen!** Dein Scraper nutzt jetzt deine Instagram-Session!
