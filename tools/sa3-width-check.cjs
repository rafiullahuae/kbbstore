/*
 * The width buttons mean what they say.                            (Lane SA3)
 *
 * The frame was `max-width:100%` inside a flex stage, so "Desktop · 1280"
 * pressed in a 430px column resolved the media query at 430px and drew the
 * PHONE branch under a label that said Desktop. This prints the frame's real
 * width and the branch its own document resolved, for each button.
 */
const { chromium } = require('playwright');
const BASE = process.env.SA_BASE || 'http://127.0.0.1:8993';
(async () => {
    const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
    const ctx = await b.newContext({ viewport: { width: 1280, height: 1200 } });
    const p = await ctx.newPage();
    await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await p.fill('input[name=email]', 'owner@preview.test');
    await p.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit]')]);
    await p.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await p.waitForTimeout(900);
    await p.evaluate(() => window.go('setap'));
    await p.waitForTimeout(2200);

    for (const w of [390, 760, 1280]) {
        await p.evaluate((x) => document.querySelector('[data-sap-w="' + x + '"]').click(), w);
        await p.waitForTimeout(1500);
        const f = p.frames().find(x => x !== p.mainFrame());
        const inner = await f.evaluate(() => ({
            innerWidth: window.innerWidth,
            // Which branch of SetAppearance::css()'s own media queries is live.
            setBoxPhoneBranch: window.matchMedia('(max-width:760px)').matches,
            listPhoneBranch: window.matchMedia('(max-width:480px)').matches,
            cartPhoneBranch: window.matchMedia('(max-width:600px)').matches,
        }));
        const box = await p.evaluate(() => {
            const r = document.querySelector('#sap-frame').getBoundingClientRect();
            const s = document.querySelector('.sap-stage').getBoundingClientRect();
            return { frame: Math.round(r.width), stage: Math.round(s.width) };
        });
        console.log(JSON.stringify({ button: w, ...box, ...inner }));
    }
    await b.close();
})();
