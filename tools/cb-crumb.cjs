/*
 * Lane CB: is the breadcrumb fully visible and clickable on the first try?
 * For each page and width: the trail's box, the top of whatever follows it,
 * and elementFromPoint() at the centre of every link in the trail (and of
 * the trail itself). Run against tools/cb-preview.sh:
 *     node tools/cb-crumb.cjs http://127.0.0.1:10734 [shots-dir] [tag]
 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.argv[2] || 'http://127.0.0.1:10734';
const OUT = process.argv[3] ? path.resolve(process.argv[3]) : null;
const TAG = process.argv[4] || 'after';
const PAGES = (process.env.CB_PAGES || '/brands/anua/,/brands/plainbr4/,/collections/cbbanner/,/collections/cbplain/,/shop/,/ar/brands/anua/,/ar/collections/cbbanner/').split(',');
(async () => {
    const browser = await chromium.launch();
    let bad = 0;
    for (const w of [390, 1280]) {
        const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: 1 });
        const page = await ctx.newPage();
        const errors = [];
        page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
        page.on('pageerror', (e) => errors.push(String(e)));
        for (const p of PAGES) {
            errors.length = 0;
            await page.goto(BASE + p, { waitUntil: 'load' });
            await page.waitForTimeout(250);
            const m = await page.evaluate(() => {
                const c = document.querySelector('.crumb, .brw-crumb, .rtn-crumb');
                if (!c || getComputedStyle(c).display === 'none') return { none: true };
                const r = c.getBoundingClientRect();
                const pts = [...c.querySelectorAll('a, b, span[aria-current]')].concat([c]).map((el) => {
                    const b = el.getBoundingClientRect();
                    const hit = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
                    return { t: (el.textContent || '').trim().slice(0, 18), ok: !!hit && (el === hit || el.contains(hit) || c === hit), hit: hit ? (hit.className || hit.tagName).toString().slice(0, 40) : 'null' };
                });
                let next = c.parentElement.nextElementSibling; let n = c.nextElementSibling;
                const follow = n || next;
                const fr = follow ? follow.getBoundingClientRect() : null;
                const media = document.querySelector('.brw-ph__media, .kbb-th, .kbb-banner, .cbx-ph__media');
                const mr = media ? media.getBoundingClientRect() : null;
                return { top: Math.round(r.top), bottom: Math.round(r.bottom), h: Math.round(r.height), pt: getComputedStyle(c).paddingTop, pb: getComputedStyle(c).paddingBottom,
                    mediaTop: mr ? Math.round(mr.top) : null, overlap: mr ? Math.round(r.bottom - mr.top) : null, pts, sw: document.documentElement.scrollWidth };
            });
            const fails = m.pts ? m.pts.filter((x) => !x.ok) : [];
            bad += fails.length;
            console.log(`${w} ${p} | ${m.none ? 'crumb hidden' : `crumb ${m.top}-${m.bottom} (pad ${m.pt}/${m.pb}) banner top ${m.mediaTop} overlap ${m.overlap}px | hits ${m.pts.map((x) => (x.ok ? 'ok' : 'BLOCKED by ' + x.hit) + ':' + x.t).join(', ')}`} | sw ${m.sw} | console errors ${errors.length}${errors.length ? ' ' + errors.join(' ; ').slice(0, 200) : ''}`);
            if (OUT) await page.screenshot({ path: path.join(OUT, `${TAG}-crumb-${p.replace(/\W+/g, '-').replace(/^-|-$/g, '')}-${w}.png`), clip: { x: 0, y: 0, width: w, height: w < 600 ? 520 : 560 } });
        }
        await ctx.close();
    }
    await browser.close();
    console.log(bad ? `BLOCKED POINTS: ${bad}` : 'every crumb point answers');
})();
