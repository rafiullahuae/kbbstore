/*
 * "Read less" on the product page, before and after.         (Lane PI-A, item 9)
 *
 *   PIA_BASE=http://127.0.0.1:9400 PIA_OUT=storage/pia-logs/shots/readless-after \
 *     NODE_PATH=/home/user/kbbstore/node_modules node tools/pia-readless-shots.cjs
 *
 * Needs the tools/pia-seed.php product. At 390 and 1280: open the long
 * description, scroll so its "Read less" button sits near the bottom of the
 * screen -- where a shopper who has read to the end is -- press it, and record
 * where the page comes to rest. Run it on a SHORT description too (PIA_SLUG):
 * there the text is still on screen after the collapse and nothing may move. This harness measures with
 * getBoundingClientRect; the shop's own code does not.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PIA_BASE || 'http://127.0.0.1:9400';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, process.env.PIA_OUT || 'storage/pia-logs/shots/readless');
const SLUG = process.env.PIA_SLUG || 'pia-pdrn-glow-booster-set';

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const measure = {};

    for (const [w, h] of [[390, 844], [1280, 900]]) {
        const page = await (await browser.newContext({ viewport: { width: w, height: h } })).newPage();
        await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });

        const button = page.locator('#details .dtabpanel.on .readmore');
        await button.scrollIntoViewIfNeeded();
        await button.click();
        await page.waitForTimeout(200);

        // Where a shopper who read to the end is: the button near the bottom.
        await page.evaluate((vh) => {
            const b = document.querySelector('#details .dtabpanel.on .readmore');
            window.scrollTo(0, b.getBoundingClientRect().top + window.scrollY - vh + 80);
        }, h);
        await page.waitForTimeout(300);

        const read = () => page.evaluate(() => {
            const d = document.getElementById('details').getBoundingClientRect();
            const hd = document.querySelector('header').getBoundingClientRect();
            return {
                scrollY: Math.round(window.scrollY),
                detailsTop: Math.round(d.top),
                detailsBottom: Math.round(d.bottom),
                headerBottom: Math.round(hd.bottom),
                detailsTopVisibleBelowHeader: d.top >= hd.bottom - 1 && d.top < window.innerHeight,
                buttonText: document.querySelector('#details .dtabpanel.on .readmore').textContent,
            };
        });

        const before = await read();
        await page.screenshot({ path: `${OUT}/before-press-${w}.png` });

        await button.click();
        await page.waitForTimeout(1200);

        const after = await read();
        await page.screenshot({ path: `${OUT}/after-press-${w}.png` });

        measure[w] = { beforePress: before, afterPress: after, scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth) };
    }

    fs.writeFileSync(`${OUT}/measure.json`, JSON.stringify(measure, null, 2));
    console.log(JSON.stringify(measure, null, 2));
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
