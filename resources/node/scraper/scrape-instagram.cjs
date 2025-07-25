const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

const username = process.argv[2] || 'default_username'; // Default username if none is provided

(async () => {
  const browser = await puppeteer.launch({
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--headless=new',
      '--proxy-server=http://127.0.0.1:8118'
    ],
  });

  const page = await browser.newPage();

  await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36');
  // Set screen size to mobile.
  await page.setViewport({width: 1375, height: 712});
  //
  await page.goto(`https://www.instagram.com/${username}/`, {
    timeout: 5000
  });




  const fs = require('fs');
  const path = require('path');


  // Zielpfad vorbereiten
  const basePath = path.join(__dirname, '../../../storage/app/screenshots', username);
 const screenshotPath = path.join(basePath, 'profile-screenshot-' + Date.now() + '.png');

  // Ordner erstellen, falls nicht vorhanden
  fs.mkdirSync(basePath, { recursive: true });

  // Screenshot machen
  await page.screenshot({
    path: screenshotPath,
    fullPage: true
  });

  console.log('Screenshot gespeichert unter:', screenshotPath);


  const html = await page.content();
  console.log(html);

  await browser.close();
})();
