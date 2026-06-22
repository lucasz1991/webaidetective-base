# Instagram Browser Engine

Der Instagram-Scraper kann mit dem bisherigen Chrome/Puppeteer-Pfad oder mit
CloakBrowser gestartet werden.

## Konfiguration

In `.env`:

```dotenv
# Bisheriges Verhalten und sicherer Standard
INSTAGRAM_BROWSER_ENGINE=chrome

# CloakBrowser ohne automatischen Rueckfall
# INSTAGRAM_BROWSER_ENGINE=cloak

# CloakBrowser testen und bei einem technischen Startfehler Chrome verwenden
# INSTAGRAM_BROWSER_ENGINE=cloak-with-chrome-fallback

# Optional: CloakBrowser-Klicks, Tastatur und Scrollen menschlicher ausfuehren
INSTAGRAM_CLOAK_HUMANIZE=false
INSTAGRAM_CLOAK_HUMAN_PRESET=
```

Nach einer Aenderung der `.env` bei aktivem Laravel-Konfigurationscache:

```bash
php artisan config:clear
```

Jeder Scan schreibt die angeforderte und tatsaechlich verwendete Browser-Engine
in das Scraper-Debuglog und liefert `browserEngine` im Ergebnis-Payload.

## Installation

```bash
npm install
npx cloakbrowser install
```

`npx cloakbrowser install` laedt die Cloak-Chromium-Binary in den Cache des
ausfuehrenden Betriebssystembenutzers. Der PHP-/Queue-Prozess muss unter einem
Benutzer laufen, der auf diesen Cache zugreifen kann.

Puppeteers eigener Chrome wird bei `npm install` absichtlich nicht automatisch
heruntergeladen. Das verhindert unvollstaendige Puppeteer-Caches auf Plesk.
Falls der normale Chrome-Fallback auf einem neuen Server benoetigt wird, muss
Chrome dort separat installiert und sein Pfad gesetzt werden:

```dotenv
PUPPETEER_EXECUTABLE_PATH=/usr/bin/google-chrome
```

Alternativ kann Puppeteers Chrome einmal explizit installiert werden:

```bash
PUPPETEER_SKIP_DOWNLOAD=false npx puppeteer browsers install chrome
```

## Sofort zu Chrome zurueckkehren

```dotenv
INSTAGRAM_BROWSER_ENGINE=chrome
```

Der bestehende `puppeteer-extra`- und Stealth-Plugin-Pfad bleibt dabei
unveraendert erhalten.
