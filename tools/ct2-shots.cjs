/* Lane CT2: the contact page's cards and the page editor's Contact cards card.
   node tools/ct2-shots.cjs <base> <outdir>
   Measures what the rules ask for: scrollWidth, each card's box and font
   sizes, a real click on every card (elementFromPoint at its centre), console
   errors, scripts and requests -- at 320 / 390 / 1280, English and Arabic. */
const { chromium } = require('playwright');
const [BASE, OUT] = process.argv.slice(2);
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const UA_DESK = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const ctx = (b, W) => b.newContext({ viewport: { width: W, height: W < 900 ? 844 : 900 }, deviceScaleFactor: W < 900 ? 2 : 1, userAgent: W < 900 ? UA_PHONE : UA_DESK, isMobile: W < 900, hasTouch: W < 900 });

async function shop(b, W, path, tag) {
    const c = await ctx(b, W);
    const p = await c.newPage();
    const errors = []; const reqs = [];
    p.on('pageerror', (e) => errors.push(String(e)));
    p.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    p.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(BASE, '')); });
    p.on('request', (r) => reqs.push(r.url().replace(BASE, '')));
    const res = await p.goto(BASE + path, { waitUntil: 'networkidle' });
    const m = await p.evaluate(() => {
        const cards = [...document.querySelectorAll('.ctc-card')];
        return {
            sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
            scripts: document.querySelectorAll('script[src]').length,
            cards: cards.map((a) => {
                const r = a.getBoundingClientRect();
                const hit = document.elementFromPoint(r.left + r.width / 2, r.top + Math.min(r.height / 2, 300));
                return { k: a.dataset.ct, x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height),
                    href: a.getAttribute('href'), clickable: !!(hit && a.contains(hit)),
                    h3: getComputedStyle(a.querySelector('h3')).fontSize, val: a.querySelector('.ctc-val') ? getComputedStyle(a.querySelector('.ctc-val')).fontSize : null };
            }),
        };
    });
    const sec = await p.$('section.ctc-top');
    if (sec) await sec.screenshot({ path: `${OUT}/contact-cards-${tag}-${W}.png` });
    await p.screenshot({ path: `${OUT}/contact-page-${tag}-${W}.png` });
    console.log(JSON.stringify({ tag, W, status: res.status(), ...m, requests: reqs.length, errors }));
    await c.close();
}

async function admin(b, W, drive) {
    const c = await b.newContext({ viewport: { width: W, height: 1800 }, deviceScaleFactor: W < 900 ? 2 : 1, userAgent: W < 900 ? UA_PHONE : UA_DESK, isMobile: W < 900, hasTouch: W < 900 });
    const p = await c.newPage();
    const errors = [];
    p.on('pageerror', (e) => errors.push(String(e)));
    p.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    p.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(BASE, '')); });
    await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await p.fill('input[name=email]', 'owner@preview.test');
    await p.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit], input[type=submit]')]);
    await p.evaluate(() => window.go && window.go('pages-user'));
    await p.waitForSelector('[data-pg-edit]');
    const id = await p.evaluate(async () => {
        const base = location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api';
        const j = await (await fetch(base + '/page-editor-list', { headers: { Accept: 'application/json' } })).json();
        return (j.pages.find((x) => x.slug === 'contact-us') || {}).id;
    });
    await p.click(`[data-pg-edit="${id}"]`);
    await p.waitForSelector('#pg-cc');
    await p.evaluate(() => { const d = document.querySelector('#pg-cc details[data-cc-open="1"]'); if (d) d.open = true; });
    const card = await p.$('#pg-cc');
    await card.screenshot({ path: `${OUT}/editor-contact-cards-${W}.png` });
    const m = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
        rows: [...document.querySelectorAll('#pg-cc .pg-cc-name')].map((e) => e.firstChild.textContent),
        ig: { title: document.querySelector('#pg-cc-1-title').value, value: document.querySelector('#pg-cc-1-value').value, link: document.querySelector('#pg-cc-1-link').value } }));
    console.log(JSON.stringify({ admin: W, ...m, errors }));

    if (drive) {
        // Add a card, fill it, move it to the top, show the phone card, hide
        // nothing, Save -- the editor end to end, through its own buttons.
        const n = m.rows.length;
        await p.click('[data-cc-add]');
        console.log('added', n);
        await p.fill(`#pg-cc-${n}-title`, 'Visit us');
        await p.fill(`#pg-cc-${n}-note`, 'Our Dubai showroom');
        await p.fill(`#pg-cc-${n}-value`, 'Al Quoz 1, Dubai');
        await p.fill(`#pg-cc-${n}-action`, 'Get directions');
        await p.fill(`#pg-cc-${n}-link`, 'https://maps.google.com/?q=K+Beauty+Bliss');
        await p.selectOption(`#pg-cc-${n}-icon`, 'location');
        await p.check('#pg-cc input[data-cc-i="3"][data-cc-f="on"]');
        console.log('filled');
        await card.screenshot({ path: `${OUT}/editor-contact-cards-added-${W}.png` }).catch(() => {});
        await p.click('#pg-save');
        await p.waitForFunction(() => /Saved/.test((document.querySelector('.pg-note') || {}).textContent || ''));
        const note = await p.textContent('.pg-note');
        console.log('saved', note);
        await (await p.$('#pg-cc')).screenshot({ path: `${OUT}/editor-contact-cards-saved-${W}.png` });
        // An unsafe link is refused with the reason on screen.
        await p.evaluate(() => { const d = document.querySelector('#pg-cc details[data-cc-open="1"]'); if (d) d.open = true; });
        await p.fill('#pg-cc-1-link', 'javascript:alert(1)');
        await p.click('#pg-save');
        await p.waitForFunction(() => /link must start/.test((document.querySelector('.pg-note') || {}).textContent || ''));
        const refused = await p.textContent('.pg-note');
        await p.screenshot({ path: `${OUT}/editor-unsafe-link-refused-${W}.png` });
        console.log(JSON.stringify({ drive: W, note, refused, errors }));
    }
    await c.close();
}

(async () => {
    const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    const phase = process.env.CT2_PHASE || 'three';
    if (phase === 'three') {
        for (const W of [320, 390, 1280]) { await shop(b, W, '/contact-us/', 'en-3'); await shop(b, W, '/ar/contact-us/', 'ar-3'); }
        for (const W of [390, 1280]) await admin(b, W, false);
    } else if (phase === 'drive') {
        await admin(b, 1280, true);
    } else if (phase === 'four') {
        // Store -> Inquiries switches the phone card off again: four cards.
        const c = await b.newContext(); const p = await c.newPage();
        await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
        await p.fill('input[name=email]', 'owner@preview.test');
        await p.fill('input[name=password]', 'preview-secret-1');
        await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit], input[type=submit]')]);
        const out = await p.evaluate(async () => {
            const base = location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api';
            const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
            const cur = await (await fetch(base + '/inquiries/settings', { headers: { Accept: 'application/json' } })).json();
            const r = await fetch(base + '/inquiries/settings', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
                body: JSON.stringify({ config: Object.assign({}, cur.config, { phone: false }) }) });
            return r.status;
        });
        console.log('inquiries save', out);
        await c.close();
        for (const W of [320, 390, 1280]) { await shop(b, W, '/contact-us/', 'en-4'); await shop(b, W, '/ar/contact-us/', 'ar-4'); }
    } else {
        for (const W of [320, 390, 1280]) { await shop(b, W, '/contact-us/', 'en-' + phase); await shop(b, W, '/ar/contact-us/', 'ar-' + phase); }
    }
    await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
