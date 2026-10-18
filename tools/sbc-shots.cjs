// Lane SBC: Shop by Concern on the real homepage (preview server), English and
// Arabic, 390 and 1280, plus the checks the brief asks for: tabs on one row,
// elementFromPoint on every tab / View all / cart-plus, a real tab switch that
// makes ZERO requests, the phone carousel scrolled (dots), a cart-plus that
// really adds to the basket, scrollWidth == width, console errors.
// Usage: node tools/sbc-shots.cjs http://127.0.0.1:9620 docs/lane-sbc <suffix> [admin]
const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const BASE = process.argv[2] || 'http://127.0.0.1:9620';
  const out = process.argv[3] || 'docs/lane-sbc';
  const sfx = process.argv[4] || 'default';
  const doAdmin = process.argv[5] === 'admin';
  fs.mkdirSync(out, { recursive: true });
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  // A desktop Chrome user agent: the shop's cart refuses a 'HeadlessChrome' one (bot guard).
  const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
  const report = {};

  // For the PICTURE only: fixed and sticky chrome (header, chat bubble) hidden so it
  // does not paint over the section in an element screenshot. Measurements above
  // and the click checks run on the page as shoppers get it.
  async function shot(page, el, file) {
    await page.evaluate(() => { for (const e of document.querySelectorAll('body *')) { const p = getComputedStyle(e).position; if ((p === 'fixed' || p === 'sticky') && !e.closest('#sbc')) { e.dataset.sbcHid = e.style.visibility || '-'; e.style.visibility = 'hidden'; } } });
    await el.screenshot({ path: file });
    await page.evaluate(() => { for (const e of document.querySelectorAll('[data-sbc-hid]')) { e.style.visibility = e.dataset.sbcHid === '-' ? '' : e.dataset.sbcHid; delete e.dataset.sbcHid; } });
  }

  async function shop(path, w, tag) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2, userAgent: UA });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(BASE, '')); });
    page.on('requestfailed', r => errors.push('failed ' + r.url().replace(BASE, '')));
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const sec = await page.$('#sbc');
    if (!sec) { console.log(tag, 'NO SECTION'); await ctx.close(); return; }
    await sec.scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    const m = await page.evaluate(() => {
      const s = document.getElementById('sbc');
      const tabs = [...s.querySelectorAll('.sbc-tab')];
      const pn = s.querySelector('.sbc-pn:not([hidden])');
      const hero = pn.querySelector('.sbc-hero');
      const it = pn.querySelector('.sbc-it');
      const r = el => { const b = el.getBoundingClientRect(); return [Math.round(b.width), Math.round(b.height)]; };
      return {
        prev: (s.previousElementSibling || {}).className, next: (s.nextElementSibling || {}).className,
        tabs: tabs.length, tabRows: new Set(tabs.map(t => Math.round(t.getBoundingClientRect().top))).size,
        tabsScrollW: s.querySelector('.sbc-tabs').scrollWidth, tabsClientW: s.querySelector('.sbc-tabs').clientWidth,
        section: r(s), hero: r(hero), card: r(it), cards: pn.querySelectorAll('.sbc-it').length,
        heroPhoto: !!hero.querySelector('.sbc-bgi'), heroImg: (hero.querySelector('.sbc-bgi') || {}).currentSrc || null,
        h2: s.querySelector('h2') ? s.querySelector('h2').textContent : null, h2Size: s.querySelector('h2') ? getComputedStyle(s.querySelector('h2')).fontSize : null,
        sub: !!s.querySelector('.sbc-head p'), headIcon: !!s.querySelector('.sbc-hi'),
        tip: pn.querySelector('.sbc-hero p') ? pn.querySelector('.sbc-hero p').textContent.slice(0, 60) : null,
        dots: pn.querySelectorAll('.sbc-dots i').length,
        docScrollW: document.documentElement.scrollWidth, innerW: window.innerWidth, dir: document.documentElement.dir,
      };
    });
    // elementFromPoint: every tab, View all and every visible cart-plus answer at their centre.
    m.hits = await page.evaluate(async () => {
      const s = document.getElementById('sbc');
      const pn = s.querySelector('.sbc-pn:not([hidden])');
      const check = async (el) => {
        el.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'instant' });
        await new Promise(r => setTimeout(r, 80));
        const b = el.getBoundingClientRect();
        const hit = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
        return !!hit && (hit === el || el.contains(hit));
      };
      const res = { tabs: [], viewAll: null, cart: [] };
      for (const t of s.querySelectorAll('.sbc-tab')) res.tabs.push(await check(t));
      const go = pn.querySelector('.sbc-go'); res.viewAll = go ? await check(go) : 'none';
      for (const c of pn.querySelectorAll('.sbc-cp')) {
        const b = c.getBoundingClientRect();
        if (b.right > 0 && b.left < innerWidth) res.cart.push(await check(c));
      }
      return { tabs: res.tabs.every(Boolean) + ' (' + res.tabs.length + ')', viewAll: res.viewAll, cart: res.cart.every(Boolean) + ' (' + res.cart.length + ' on screen)' };
    });
    await sec.scrollIntoViewIfNeeded();
    await page.evaluate(() => { document.querySelector('#sbc .sbc-tabs').scrollLeft = 0; for (const r of document.querySelectorAll('#sbc .sbc-rail')) r.scrollLeft = 0; });
    await page.waitForTimeout(300);
    await shot(page, sec, `${out}/${tag}-${sfx}.png`);

    if (sfx === 'default') {
      // Another tab, by a real click: count every request it causes.
      const reqs = [];
      const onReq = r => reqs.push(r.method() + ' ' + r.url().replace(BASE, ''));
      page.on('request', onReq);
      const tab = await page.$('#sbc .sbc-tab[data-i="2"]');
      await tab.scrollIntoViewIfNeeded();
      await tab.click();
      await page.waitForTimeout(600);
      page.off('request', onReq);
      // The panel's own lazy pictures may load once shown; anything else is a request the switch made.
      m.tabSwitch = await page.evaluate(() => ({ selected: document.querySelector('#sbc .sbc-tab[aria-selected=true]').dataset.i, shown: document.querySelector('#sbc .sbc-pn:not([hidden])').id }));
      m.tabSwitch.requests = reqs.filter(u => !/\.(png|jpe?g|webp|avif|gif)(\?|$)/.test(u));
      m.tabSwitch.pictureLoads = reqs.length - m.tabSwitch.requests.length;
      // Keyboard: ArrowRight moves on (ArrowLeft in Arabic).
      await page.keyboard.press(m.dir === 'rtl' ? 'ArrowLeft' : 'ArrowRight');
      m.tabSwitch.keyboard = await page.evaluate(() => document.activeElement.dataset.i + ' focused, selected ' + document.querySelector('#sbc .sbc-tab[aria-selected=true]').dataset.i);
      await page.keyboard.press(m.dir === 'rtl' ? 'ArrowRight' : 'ArrowLeft');
      await sec.scrollIntoViewIfNeeded();
      await page.waitForTimeout(300);
      await shot(page, sec, `${out}/${tag}-tab3.png`);

      if (w < 500) {
        // The phone carousel, scrolled: the dots follow.
        await page.evaluate(() => { const r = document.querySelector('#sbc .sbc-pn:not([hidden]) .sbc-rail'); r.scrollBy({ left: (document.documentElement.dir === 'rtl' ? -1 : 1) * r.clientWidth * 0.85, behavior: 'instant' }); });
        await page.waitForTimeout(700);
        m.carousel = await page.evaluate(() => { const p = document.querySelector('#sbc .sbc-pn:not([hidden])'); const r = p.querySelector('.sbc-rail'); return { scrollLeft: Math.round(r.scrollLeft), dotOn: [...p.querySelectorAll('.sbc-dots i')].findIndex(i => i.classList.contains('on')), snap: getComputedStyle(r).scrollSnapType }; });
        const rail = await page.$('#sbc .sbc-pn:not([hidden]) .sbc-rail');
        await rail.scrollIntoViewIfNeeded();
        await shot(page, sec, `${out}/${tag}-carousel.png`);
      }

      // The cart-plus really adds: the shop's own cart.js answers it.
      const cp = await page.$('#sbc .sbc-pn:not([hidden]) .sbc-cp[data-kbb-add]');
      if (cp) {
        await cp.scrollIntoViewIfNeeded();
        const [resp] = await Promise.all([
          page.waitForResponse(r => r.request().method() === 'POST' && /cart/.test(r.url()), { timeout: 10000 }).catch(() => null),
          cp.click(),
        ]);
        await page.waitForTimeout(900);
        let body = null;
        try { body = resp ? await resp.json() : null; } catch (e) { body = null; }
        m.addToCart = { url: resp ? resp.url().replace(BASE, '') : null, status: resp ? resp.status() : null, ok: body ? body.ok !== false : null,
          count: body ? (body.count ?? (body.cart && (body.cart.count ?? body.cart.item_count)) ?? null) : null, keys: body ? Object.keys(body).slice(0, 12) : null,
          drawerOpen: await page.evaluate(() => !!document.querySelector('.kc-open, .cart-open, [data-cart-open], #kbbCart.open, .drawer.open, .kc.on')) };
        await page.screenshot({ path: `${out}/${tag}-added.png` });
      }
    }
    m.consoleErrors = errors;
    report[tag + '-' + sfx] = m;
    console.log(tag, sfx, JSON.stringify(m));
    await ctx.close();
  }

  await shop('/', 1280, 'en-1280');
  await shop('/', 390, 'en-390');
  await shop('/ar/', 1280, 'ar-1280');
  await shop('/ar/', 390, 'ar-390');

  if (doAdmin) {
    for (const w of [1280, 390]) {
      const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1100 : 844 }, deviceScaleFactor: 1, userAgent: UA });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', e => errors.push(String(e)));
      page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
      await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
      await page.fill('input[name=email]', 'owner@preview.test');
      await page.fill('input[name=password]', 'preview-secret-1');
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
      await page.evaluate(() => window.go('hpcontent'));
      await page.waitForSelector('[data-hpc-tab="copy"]', { timeout: 20000 });
      await page.click('[data-hpc-tab="copy"]');
      await page.waitForSelector('[data-hpc-tab="sbc"]', { timeout: 20000 });
      await page.click('[data-hpc-tab="sbc"]');
      await page.waitForSelector('.ksb', { timeout: 20000 });
      await page.waitForTimeout(300);
      const am = await page.evaluate(() => ({ rows: document.querySelectorAll('.ksb-row').length, scrollW: document.documentElement.scrollWidth, innerW: innerWidth }));
      await page.screenshot({ path: `${out}/admin-tab-${w}.png`, fullPage: true });
      // Open the first tab's details: words, photo, products.
      await page.click('details[data-ksb-det="0"] > summary');
      await page.waitForTimeout(200);
      const det = await page.$('details[data-ksb-det="0"]');
      await det.screenshot({ path: `${out}/admin-tab-open-${w}.png` });
      if (w > 500) {
        // The editor working: Hydration's products → a hand-picked list, found by search.
        await page.click('details[data-ksb-det="1"] > summary');
        await page.selectOption('[data-ksb-f="src"][data-ksb-i="1"]', 'manual');
        await page.waitForTimeout(200);
        await page.fill('[data-ksb-find="1"]', 'snail');
        await page.waitForSelector('[data-ksb-res="1"] button', { timeout: 10000 });
        await page.click('[data-ksb-res="1"] button');
        await page.waitForTimeout(200);
        const row1 = JSON.parse(await page.$eval('.ksb input[type=hidden]', i => i.value))[1];
        am.edited = { src: row1.src, picks: row1.picks };
        const det1 = await page.$('details[data-ksb-det="1"]');
        await det1.screenshot({ path: `${out}/admin-tab-edited-${w}.png` });
        // All sections → Shop by Concern → Edit content, the popup.
        await page.click('[data-hpc-tab="all"]').catch(() => {});
        await page.waitForSelector('[data-hph-edit="sbc"]', { timeout: 20000 }).catch(() => {});
        const ed = await page.$('[data-hph-edit="sbc"]');
        if (ed) {
          await page.$eval('[data-hph-key="sbc"]', el => el.scrollIntoView({ block: 'center' })).catch(() => {});
          await page.waitForTimeout(200);
          await page.screenshot({ path: `${out}/admin-all-sections-${w}.png` });
          await page.click('[data-hph-edit="sbc"]');
          await page.waitForSelector('.hph-dlg', { timeout: 20000 }).catch(() => {});
          await page.waitForTimeout(500);
          await page.screenshot({ path: `${out}/admin-popup-${w}.png` });
        }
      }
      am.errors = errors;
      report['admin-' + w] = am;
      console.log('admin', w, JSON.stringify(am));
      await ctx.close();
    }
  }
  const file = `${out}/measurements-${sfx}.json`;
  fs.writeFileSync(file, JSON.stringify(report, null, 2));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
