const puppeteer = require('puppeteer');

const username = process.argv[2] || 'lcsxzs_zrs'; // Default username if none is provided

(async () => {
  const browser = await puppeteer.launch({
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox' 
    ],
  });

  const page = await browser.newPage();

  await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36');

  await page.goto(`https://www.instagram.com/${username}/`, {
    waitUntil: 'networkidle2',
    timeout: 30000
  });

  const html = await page.content();
  console.log(html);

  await browser.close();
})();
