/*
 * Lane S8 — the Rich product results card on Store → SEO & Meta → Settings,
 * which is where the free-delivery promise came from.
 *
 * IT REPORTS, IT DOES NOT JUDGE, like every other harness here. The claim this
 * supports is a claim about a rendered control: the Shipping cost box used to
 * arrive holding `0`, and `0` in that box was published to Google as free
 * delivery to the whole UAE. It now arrives EMPTY, with a placeholder saying so.
 *
 * WHAT IT ANSWERS: the id, the value, the placeholder and the help line of every
 * control in the card, plus the count of controls on the whole Settings tab and
 * their ids in document order — because a count cannot see a reordering, and this
 * lane changed a select's option labels as well as a box's value.
 *
 * Run it the way tests/browser/lane-s8-concern-pages.mjs documents, with
 * tests/browser/preview-router.php as the php -S router and an admin user:
 *
 *   S8_EMAIL=s8@example.com S8_PASSWORD=secret-secret-s8 \
 *     node tests/browser/lane-s8-merchant-card.mjs
 */
import { chromium } from 'playwright';
import fs from 'fs';

const BASE = process.env.S8_BASE || 'http://127.0.0.1:8912';
const OUT = process.env.S8_OUT || 'docs/s8-seo-shots';
const EMAIL = process.env.S8_EMAIL;
const PASSWORD = process.env.S8_PASSWORD;

fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({
  executablePath: process.env.S8_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--no-sandbox'],
});

for (const width of [390, 1280]) {
  const page = await browser.newPage({ viewport: { width, height: width === 390 ? 844 : 1000 } });

  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.fill('input[type="email"], input[name="email"]', EMAIL);
  await page.fill('input[type="password"], input[name="password"]', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForTimeout(3000);

  // Not ?go=<id>: nine partials wrap window.go AFTER boot, so the URL is read
  // before the wrappers exist and ?go= quietly draws the Dashboard.
  // lane-s7-seo-back-office.mjs records the same trap.
  await page.evaluate(() => window.go('seo'));
  await page.waitForTimeout(2000);
  await page.click('.subtab[data-st="settings"]').catch(() => {});
  await page.waitForTimeout(2500);

  const m = await page.evaluate(() => {
    const ids = ['seo_merchant_cbx', 'seo_merch_cond', 'seo_merch_country', 'seo_merch_cost',
      'seo_merch_freeover', 'seo_merch_returns', 'seo_merch_returndays', 'seo_org_type'];
    const field = (id) => {
      const el = document.getElementById(id);
      if (!el) return null;
      const wrap = el.closest('.sm-field') || el.parentElement;
      const hint = wrap ? wrap.querySelector('.sm-help, .sm-hint, .hint, small') : null;
      return {
        id,
        tag: el.tagName.toLowerCase(),
        value: 'value' in el ? String(el.value) : '(n/a)',
        placeholder: el.getAttribute('placeholder') || '',
        options: el.tagName === 'SELECT' ? [...el.options].map(o => `${o.value}=${o.textContent}`) : null,
        hint: hint ? hint.textContent.trim() : '',
        // innerHTML too: the help lines interpolate raw HTML (smField does
        // string concatenation, exactly as bdField does), so a <b> in one is a
        // rendered element rather than four printed characters. textContent
        // alone cannot tell the two apart.
        hintHtml: hint ? hint.innerHTML.trim() : '',
      };
    };
    const content = document.getElementById('content');
    return {
      fields: ids.map(field).filter(Boolean),
      controlCount: content ? content.querySelectorAll('input,select,textarea').length : -1,
      contentScrollWidth: content ? content.scrollWidth : -1,
      contentClientWidth: content ? content.clientWidth : -1,
      docScrollWidth: document.documentElement.scrollWidth,
      docClientWidth: document.documentElement.clientWidth,
    };
  });

  const card = page.locator('#seo_merch_cost').first();
  if (await card.count()) {
    await card.scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);
  }
  await page.screenshot({ path: `${OUT}/admin-merchant-card-${width}.png` });

  console.log(`\n── width ${width} ────────────────────────────────────────────`);
  console.log(`controls on the Settings tab: ${m.controlCount}`);
  console.log(`#content scrollWidth/clientWidth: ${m.contentScrollWidth}/${m.contentClientWidth}`);
  console.log(`document scrollWidth/clientWidth: ${m.docScrollWidth}/${m.docClientWidth}`);
  for (const f of m.fields) {
    console.log(`  ${f.id.padEnd(22)} ${f.tag.padEnd(8)} value=${JSON.stringify(f.value).padEnd(24)} placeholder=${JSON.stringify(f.placeholder)}`);
    if (f.options) console.log(`      options: ${f.options.join(' | ')}`);
    if (f.hint) console.log(`      hint: ${f.hint}`);
    if (f.hintHtml && f.hintHtml !== f.hint) console.log(`      hintHtml: ${f.hintHtml}`);
  }

  await page.close();
}

await browser.close();
