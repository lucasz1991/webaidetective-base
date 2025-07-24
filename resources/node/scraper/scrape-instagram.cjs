const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

const username = process.argv[2] || 'msdxrya'; 

(async () => {
  const browser = await puppeteer.launch({
    headless: true,
    args: [
      '--no-sandbox'
    ],
  });

  const page = await browser.newPage();

  await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36');

  await page.goto(`https://www.instagram.com/lxcxs_zrs/`, {
    waitUntil: 'networkidle2',
    timeout: 30000
  });

  const html = await page.content();
  console.log(html);

  await browser.close();
})();
