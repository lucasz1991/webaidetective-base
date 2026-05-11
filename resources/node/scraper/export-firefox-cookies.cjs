/**
 * Firefox Cookies zu JSON exportieren
 * 
 * Usage: node export-firefox-cookies.cjs
 * 
 * Die Cookies werden in storage/app/cookies/instagram-cookies.json gespeichert
 */

const fs = require('fs');
const path = require('path');
const os = require('os');

// SQLite3 optional - nur mit npm install sqlite3
let sqlite3;
try {
  sqlite3 = require('sqlite3');
} catch (e) {
  sqlite3 = null;
}

function getFirefoxProfilePath() {
  const username = os.userInfo().username;
  const profilesPath = path.join(
    process.env.APPDATA || os.homedir(),
    'Mozilla',
    'Firefox',
    'Profiles'
  );

  if (!fs.existsSync(profilesPath)) {
    throw new Error(`Firefox-Profil nicht gefunden: ${profilesPath}`);
  }

  // Suche nach .default-release Profil
  const profiles = fs.readdirSync(profilesPath);
  const defaultProfile = profiles.find(p => p.includes('default-release'));

  if (!defaultProfile) {
    throw new Error(`Kein Firefox-Defaultprofil gefunden in: ${profilesPath}`);
  }

  return path.join(profilesPath, defaultProfile);
}

async function exportCookiesWithSqlite(firefoxProfilePath) {
  return new Promise((resolve, reject) => {
    const cookiesDb = path.join(firefoxProfilePath, 'cookies.sqlite');

    if (!fs.existsSync(cookiesDb)) {
      reject(new Error(`cookies.sqlite nicht gefunden: ${cookiesDb}`));
      return;
    }

    // Kopiere die SQLite-Datei, da sie von Firefox gesperrt sein könnte
    const tempDb = path.join(os.tmpdir(), `cookies-${Date.now()}.sqlite`);
    fs.copyFileSync(cookiesDb, tempDb);

    const db = new sqlite3.Database(tempDb, sqlite3.OPEN_READONLY, (err) => {
      if (err) {
        reject(err);
        return;
      }

      db.all(
        "SELECT name, value, host, path, expiry, secure, httpOnly FROM moz_cookies WHERE host LIKE '%instagram%' OR host LIKE '%facebook%'",
        (err, rows) => {
          db.close();
          fs.unlinkSync(tempDb);

          if (err) {
            reject(err);
            return;
          }

          const cookies = (rows || []).map(row => ({
            name: row.name,
            value: row.value,
            domain: row.host.startsWith('.') ? row.host : '.' + row.host,
            path: row.path || '/',
            expires: row.expiry || undefined,
            secure: row.secure === 1,
            httpOnly: row.httpOnly === 1,
            sameSite: 'Lax'
          }));

          resolve(cookies);
        }
      );
    });
  });
}

// Alternative Methode ohne SQLite (nur als Fallback)
function exportCookiesFallback() {
  console.warn('\n⚠️  SQLite3 nicht installiert. Bitte firefox-cookies manuell exportieren:\n');
  console.log('1. Öffne Firefox');
  console.log('2. Drücke F12 → Storage → Cookies → instagram.com');
  console.log('3. Exportiere die Cookies als JSON (oder nutze Browser-Extensions wie "Export Cookies")\n');
  
  return {
    manual: true,
    exportPath: path.join(__dirname, '../../../storage/app/cookies/instagram-cookies.json'),
  };
}

async function main() {
  try {
    console.log('🔍 Firefox-Cookies werden exportiert...\n');

    const firefoxProfilePath = getFirefoxProfilePath();
    console.log(`✓ Firefox-Profil gefunden: ${firefoxProfilePath}\n`);

    let cookies;

    if (sqlite3) {
      try {
        cookies = await exportCookiesWithSqlite(firefoxProfilePath);
        
        if (cookies.length === 0) {
          console.warn('⚠️  Keine Instagram/Facebook-Cookies gefunden.');
          console.log('\nStelle sicher, dass:');
          console.log('1. Du in Firefox bei Instagram angemeldet bist');
          console.log('2. Du die Seite mindestens einmal besucht hast');
          console.log('3. Cookies in Firefox aktiviert sind\n');
          process.exit(1);
        }
      } catch (err) {
        console.error('❌ Fehler beim SQLite-Export:', err.message);
        console.log('\nFallback-Methode wird verwendet...\n');
        const fallback = exportCookiesFallback();
        console.log(`📍 Speicherort: ${fallback.exportPath}`);
        process.exit(1);
      }
    } else {
      const fallback = exportCookiesFallback();
      console.log(`📍 Speicherort: ${fallback.exportPath}`);
      process.exit(1);
    }

    // Speichere die Cookies
    const cookiesDir = path.join(__dirname, '../../../storage/app/cookies');
    fs.mkdirSync(cookiesDir, { recursive: true });

    const cookiesPath = path.join(cookiesDir, 'instagram-cookies.json');
    fs.writeFileSync(cookiesPath, JSON.stringify(cookies, null, 2), 'utf8');

    console.log(`✅ ${cookies.length} Cookies erfolgreich exportiert!\n`);
    console.log(`📍 Gespeichert: ${cookiesPath}\n`);
    console.log('Die Cookies werden jetzt automatisch beim Scraping verwendet und aktualisiert.\n');

  } catch (error) {
    console.error('❌ Fehler:', error.message);
    console.log('\nManuelle Alternative:');
    console.log('1. Firefox öffnen und in Instagram einloggen');
    console.log('2. Browser-Extension "Export Cookies" instalieren');
    console.log('3. Cookies als JSON exports in storage/app/cookies/instagram-cookies.json speichern');
    process.exit(1);
  }
}

main();
