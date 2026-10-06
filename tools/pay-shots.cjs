/*
 * Lane PY -- checkout payment-box design previews (PREVIEW ONLY).
 *
 *   sh tools/pay-preview.sh            # prints the port
 *   node tools/pay-shots.cjs <out-dir> <port> [ar]
 *
 * Drives the REAL checkout of the seeded shop. For each design it adds the
 * logo slot to every payment label (the markup Phase 2 would render from
 * Blade), loads tools/pay-designs.css and sets data-pay-design on
 * .kbb-checkout. "now" is the shop untouched. Logos are the official SVGs in
 * resources/payment-logos/, inlined with their gradient ids prefixed so two
 * logos on one page cannot share an id. Every number is read in the browser
 * after layout (a camera may measure; the shop may not).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const [out, port = '10880', ar] = process.argv.slice(2);
const BASE = 'http://127.0.0.1:' + port;
const P = ar ? '/ar' : '';
const APP = path.resolve(__dirname, '..');
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';
// .kbt-z is the shop's fixed side tab; it floats over the left edge of every element shot.
const CALM = '*,*::before,*::after{animation:none!important;transition:none!important}.kbt-z{visibility:hidden!important}';
const DESIGNS = ar ? ['now', 'a', 'b', 'c', 'd'] : ['now', 'a', 'b', 'c', 'd'];
const VIEWPORTS = ar ? [{ w: 390, h: 844 }] : [{ w: 390, h: 844 }, { w: 1280, h: 900 }];

const read = (f) => fs.readFileSync(path.join(APP, f), 'utf8').trim();
const prefix = (svg, p) => svg.replace(/id="([a-z]\d+)"/g, `id="${p}$1"`).replace(/url\(#([a-z]\d+)\)/g, `url(#${p}$1)`)
  .replace(/ width="[\d.]+" height="[\d.]+"/, '').replace('<svg ', '<svg aria-hidden="true" focusable="false" ');
// The card marks the shop already draws (App\Support\PaymentMarkArt), asked of PHP, not copied.
const marks = JSON.parse(require('child_process').execFileSync('php', ['-r',
  'require $argv[1]."/vendor/autoload.php"; echo json_encode(App\\Support\\PaymentMarkArt::marks());', APP]).toString());
const mark = (k) => marks[k].replace(/ style="[^"]*"/, '').replace(' role="img"', ' aria-hidden="true"');
const COD = '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2.2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 9.6v4.8M18 9.6v4.8"/></svg>';

const LOGOS = {
  tabby: `<span class="idle">${prefix(read('resources/payment-logos/tabby-badge.svg'), 'tbb')}</span><span class="sel">${prefix(read('resources/payment-logos/tabby-wordmark.svg'), 'tbw')}</span>`,
  tamara: `<span class="idle">${prefix(read(ar ? 'resources/payment-logos/tamara-badge-ar.svg' : 'resources/payment-logos/tamara-badge.svg'), 'tmb')}</span><span class="sel">${prefix(read(ar ? 'resources/payment-logos/tamara-wordmark-ar.svg' : 'resources/payment-logos/tamara-wordmark.svg'), 'tmw')}</span>`,
  stripe: mark('pay_visa') + mark('pay_mc'),
  cod: COD,
};
const Q = [1, 2, 3, 4].map((q) => prefix(read(`resources/payment-logos/tabby-installments/q${q}.svg`), 'q' + q));
const WHEN = ar ? ['اليوم', 'بعد شهر', 'بعد شهرين', 'بعد ٣ أشهر'] : ['Today', 'In 1 month', 'In 2 months', 'In 3 months'];
const CSS = fs.readFileSync(path.join(APP, 'tools/pay-designs.css'), 'utf8');

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const ids = JSON.parse(await (await fetch(BASE + '/pay-ids.json')).text());
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};

  for (const vp of VIEWPORTS) {
    const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, deviceScaleFactor: 2, userAgent: UA });
    const page = await ctx.newPage();
    await page.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.abort());
    await page.goto(BASE + P + '/', { waitUntil: 'domcontentloaded' });
    for (const id of ids) {
      await page.evaluate(async (pid) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
        body: JSON.stringify({ product_id: pid, quantity: 1 }) }), id);
    }

    for (const d of DESIGNS) {
      await page.goto(BASE + P + '/checkout', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(600);
      await page.addStyleTag({ content: CALM });
      if (d !== 'now') {
        await page.addStyleTag({ content: CSS });
        await page.evaluate(({ d, LOGOS, Q, WHEN }) => {
          document.querySelector('.kbb-checkout').setAttribute('data-pay-design', d);
          for (const li of document.querySelectorAll('li.wc_payment_method')) {
            const id = li.querySelector('input').value;
            const s = document.createElement('span');
            s.className = 'pay-logo pay-logo-' + id; s.setAttribute('aria-hidden', 'true'); s.innerHTML = LOGOS[id] || '';
            li.querySelector('label').appendChild(s);
          }
          if (d === 'd') {
            // Phase 2 renders this from $totalFils in PHP; the preview reads the
            // total the page printed so the amounts are this basket's.
            const t = document.querySelector('.js-total');
            const fils = Math.round(parseFloat((t ? t.textContent : '0').replace(/[^\d.]/g, '')) * 100);
            const q = Math.floor(fils / 4), first = fils - q * 3;
            const fmt = (f) => 'AED ' + (f / 100).toFixed(2);
            for (const id of ['tabby', 'tamara']) {
              const box = document.querySelector('.payment_box.payment_method_' + id);
              if (!box) continue;
              const plan = document.createElement('div'); plan.className = 'pay-plan';
              plan.innerHTML = [0, 1, 2, 3].map((i) => `<span><i>${id === 'tabby' ? Q[i] : ''}</i><b>${fmt(i ? q : first)}</b><small>${WHEN[i]}</small></span>`).join('');
              box.appendChild(plan);
            }
          }
        }, { d, LOGOS, Q, WHEN });
      }

      for (const pick of ['tabby', 'tamara']) {
        await page.check('#payment_method_' + pick, { force: true });
        await page.waitForTimeout(120);
        const key = `${d}-${pick}-${ar ? 'ar-' : ''}${vp.w}`;
        await (await page.$('.sec.pay')).screenshot({ path: path.join(out, key + '.png') });
        report[key] = await page.evaluate(() => {
          const r = (el) => el ? Math.round(el.getBoundingClientRect().height * 10) / 10 : null;
          return {
            scrollWidth: document.documentElement.scrollWidth,
            items: [...document.querySelectorAll('li.wc_payment_method')].map((li) => {
              const lab = li.querySelector('label'); const logo = li.querySelector('.pay-logo');
              const lr = logo && logo.getBoundingClientRect();
              const cs = getComputedStyle(lab);
              return { id: li.querySelector('input').value, li: r(li), label: r(lab), font: cs.fontSize + '/' + cs.fontWeight,
                color: cs.color, bg: getComputedStyle(li).backgroundImage !== 'none' ? getComputedStyle(li).backgroundImage.slice(0, 60) : getComputedStyle(li).backgroundColor,
                logo: lr ? `${Math.round(lr.width)}x${Math.round(lr.height)} @x${Math.round(lr.left)}` : null };
            }),
          };
        });
      }
      // Zoom on the logo row: the two BNPL headers, Tamara selected.
      if (vp.w === 390 && d !== 'now') {
        const a = await (await page.$('li.payment_method_tabby')).boundingBox();
        const b = await (await page.$('li.payment_method_tamara > label')).boundingBox();
        await page.screenshot({ path: path.join(out, `${d}-zoom-logos-${ar ? 'ar-' : ''}390.png`),
          clip: { x: a.x - 4, y: a.y - 4, width: a.width + 8, height: b.y + b.height - a.y + 8 } });
      }
    }
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(out, `measurements${ar ? '-ar' : ''}.json`), JSON.stringify(report, null, 1));
  console.log('shots written to', out);
})();
