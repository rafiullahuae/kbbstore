/*
 * The imported product's tab strip, photographed.            (Lane PI-A, item 5)
 *
 *   PIA_BASE=http://127.0.0.1:9400 PIA_OUT=storage/pia-logs/shots/tabs-after \
 *     NODE_PATH=/home/user/kbbstore/node_modules node tools/pia-tabs-shots.cjs
 *
 * Needs a preview from tools/bg-preview.sh into which the plugin's fixture export
 * (tests/Fixtures/kbb-export) has been imported with `php artisan kbb:import`.
 * The Ginseng Serum, wc_id 4021, is the product whose WordPress page carried a
 * "Major Ingredients" and a "How to Use" tab.
 *
 * At 390 and 1280: the details block, then each extra tab opened; and the
 * numbers -- the tab titles drawn, any script/handler in the panels, scrollWidth.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PIA_BASE || 'http://127.0.0.1:9400';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, process.env.PIA_OUT || 'storage/pia-logs/shots/tabs');
const SLUG = process.env.PIA_SLUG || 'serum-4021';

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const measure = {};

    for (const [w, h] of [[390, 844], [1280, 900]]) {
        const page = await (await browser.newContext({ viewport: { width: w, height: h } })).newPage();
        await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });

        const details = page.locator('#details');
        await details.scrollIntoViewIfNeeded();

        const m = (measure[w] = await page.evaluate(() => ({
            tabs: [...document.querySelectorAll('#details .dtab')].map((b) => b.textContent.trim()),
            scripts: document.querySelectorAll('#details script').length,
            handlers: [...document.querySelectorAll('#details *')].filter((n) => [...n.attributes].some((a) => /^on/i.test(a.name))).length,
            scrollWidth: document.documentElement.scrollWidth,
        })));

        await details.screenshot({ path: `${OUT}/tabs-${w}.png` });

        for (let i = 1; i < m.tabs.length; i++) {
            await page.locator(`#details .dtab[data-i="${i}"]`).click();
            await page.waitForTimeout(150);
            const more = page.locator('#details .dtabpanel.on .readmore');
            if (await more.isVisible()) await more.click();
            await page.waitForTimeout(150);
            m[`panel${i}`] = await page.locator('#details .dtabpanel.on .dcontent').innerHTML();
            await details.screenshot({ path: `${OUT}/tab-${i}-${w}.png` });
        }
    }

    fs.writeFileSync(`${OUT}/measure.json`, JSON.stringify(measure, null, 2));
    console.log(JSON.stringify(measure, null, 2));
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
