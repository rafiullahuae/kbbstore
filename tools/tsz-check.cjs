/* Lane TS: checks the type-size preview in docs/type-preview/.
     PREVIEW=http://127.0.0.1:PORT node tools/tsz-check.cjs   (PREVIEW serves docs/type-preview/)
   1. fidelity: each snapshot, opened on its own at its width, against the
      screenshot of the live render tools/tsz-snap.cjs took (storage/ts-logs/
      ref-<page>-<w>.png) -- differing pixels counted in the browser;
   2. defaults: inside the preview, at default values, every tagged element's
      computed size equals the size it was measured at (the override is inert);
   3. each control, on each device: moved to 125%, ONLY its own group's
      elements change size, and the other device's frames do not change at all;
      the before/after of its most common element is recorded;
   4. Overall at 110%: every text element x1.1, icons untouched;
   5. console errors on the preview page; screenshots of the tool at 1280/390.
   Writes storage/ts-logs/check.json and docs/type-preview/shots/*.png. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const PREVIEW = process.env.PREVIEW;
const LOGS = path.resolve(__dirname, '../storage/ts-logs');
const SHOTS = path.resolve(__dirname, '../docs/type-preview/shots');
fs.mkdirSync(SHOTS, { recursive: true });

async function diff(page, a, b) {
  return page.evaluate(async ([a, b]) => {
    const load = (src) => new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = no; i.src = src; });
    const [ia, ib] = await Promise.all([load(a), load(b)]);
    const w = Math.min(ia.width, ib.width), h = Math.min(ia.height, ib.height);
    const px = (im) => { const c = document.createElement('canvas'); c.width = w; c.height = h; const x = c.getContext('2d'); x.drawImage(im, 0, 0); return x.getImageData(0, 0, w, h).data; };
    const da = px(ia), db = px(ib);
    let n = 0; for (let i = 0; i < da.length; i += 4) if (Math.abs(da[i] - db[i]) + Math.abs(da[i + 1] - db[i + 1]) + Math.abs(da[i + 2] - db[i + 2]) > 24) n++;
    return { w, h, sizeA: [ia.width, ia.height], sizeB: [ib.width, ib.height], differing: n, share: +(n / (w * h) * 100).toFixed(3) };
  }, [a, b]);
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = { fidelity: [], defaults: [], controls: [], overall: [], errors: [], requests: [] };

  // 1. fidelity
  for (const [pg, w] of [['panel', 1280], ['cart', 1280], ['checkout', 1280], ['panel', 390], ['cart', 390], ['checkout', 390]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 1, isMobile: w === 390, hasTouch: w === 390 });
    const p = await ctx.newPage();
    await p.goto(`${PREVIEW}/snap/${pg}-${w === 390 ? 'm' : 'd'}.html`, { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(400);
    const shot = path.join(LOGS, `snap-${pg}-${w}.png`);
    await p.screenshot({ path: shot });
    const ref = 'data:image/png;base64,' + fs.readFileSync(path.join(LOGS, `ref-${pg}-${w}.png`)).toString('base64');
    const mine = 'data:image/png;base64,' + fs.readFileSync(shot).toString('base64');
    out.fidelity.push({ page: pg, w, ...(await diff(p, ref, mine)) });
    await ctx.close();
  }

  // 2-5. the tool
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') out.errors.push(m.type() + ': ' + m.text()); });
  page.on('pageerror', (e) => out.errors.push(String(e)));
  page.on('request', (r) => { if (!r.url().startsWith(PREVIEW)) out.requests.push(r.url()); });
  await page.goto(PREVIEW + '/index.html', { waitUntil: 'load' });
  await page.evaluate(() => { try { localStorage.clear(); } catch (e) {} });
  await page.reload({ waitUntil: 'load' });
  await page.waitForFunction(() => document.documentElement.getAttribute('data-ready') === '1', null, { timeout: 30000 });

  const sizes = () => page.evaluate(() => {
    const r = {};
    document.querySelectorAll('#frames iframe').forEach((f) => {
      const key = f.getAttribute('src').replace('snap/', '').replace('.html', '');
      const d = f.contentDocument, w = d.defaultView;
      r[key] = [...d.querySelectorAll('[data-ts]')].map((el) => [el.getAttribute('data-ts'), parseFloat(w.getComputedStyle(el).fontSize), parseFloat(el.style.getPropertyValue('--ts-b')),
        (el.className && el.className.baseVal === undefined ? String(el.className).split(' ')[0] : el.tagName.toLowerCase()) || el.tagName.toLowerCase(),
        [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim()) ? el.textContent.trim().slice(0, 30) : '']);
    });
    return r;
  });

  const base = await sizes();
  for (const [k, list] of Object.entries(base)) {
    const off = list.filter(([g, now, b]) => Math.abs(now - b) > 0.01);
    out.defaults.push({ frame: k, elements: list.length, offAtDefault: off.length, sample: off.slice(0, 3) });
  }

  const groups = await page.evaluate(() => [...document.querySelectorAll('#ctls .ctl')].map((c) => c.dataset.g));
  for (const dev of ['d', 'm']) {
    await page.click(`#devSeg button[data-dev="${dev}"]`);
    for (const g of groups) {
      const row = page.locator(`#ctls .ctl[data-g="${g}"]`);
      const nb = row.locator('input[type=number]');
      if (await nb.isDisabled()) { out.controls.push({ dev, g, skipped: 'not on these pages' }); continue; }
      const def = Number(await nb.inputValue());
      const ov = g === 'text' || g === 'space';
      const to = ov ? 10 : Math.round(def * 1.25 * 2) / 2;
      await nb.fill(String(to)); await nb.dispatchEvent('change');
      await page.waitForTimeout(50);
      const after = await sizes();
      let changedOwn = 0, changedOther = [], otherDevice = 0, example = null, wrongRatio = 0;
      for (const [k, list] of Object.entries(after)) {
        const kdev = k.split('-')[1];
        list.forEach(([grp, now, b, cls, txt], i) => {
          const was = base[k][i][1];
          const moved = Math.abs(now - was) > 0.01;
          if (kdev !== dev) { if (moved) otherDevice++; return; }
          if (ov) {
            const want = grp === 'x' ? was : was * 1.1;
            if (Math.abs(now - want) > 0.02) wrongRatio++;
            if (moved) changedOwn++;
            return;
          }
          if (grp === g) {
            if (Math.abs(now - b * to / def) > 0.02) wrongRatio++;
            if (moved) changedOwn++;
            if (!example && txt && Math.abs(was - def) < 0.01) example = { frame: k, el: cls, text: txt, before: was, after: now };
          } else if (moved) changedOther.push([k, grp, cls, txt, was, now]);
        });
      }
      const note = await row.locator('.now').textContent();
      out.controls.push({ dev, g, now: note, set: to, changedOwn, wrongRatio, changedOtherGroups: changedOther.length, otherSample: changedOther.slice(0, 3), otherDeviceChanged: otherDevice, example });
      if (ov) out.overall.push({ dev, changedOwn, wrongRatio, otherDevice });
      if (g === 'heading' && dev === 'd') await page.screenshot({ path: path.join(SHOTS, 'tool-1440-heading-125.png') });
      await row.locator('.rst').click();
      await page.waitForTimeout(30);
    }
  }
  /* Spacing on its own (lock off) and everything at +25%: fonts must not move
     with Spacing alone, boxes must; and no page may get wider than its frame. */
  const geo = () => page.evaluate(() => {
    const r = {};
    document.querySelectorAll('#frames iframe').forEach((f) => {
      const key = f.getAttribute('src').replace('snap/', '').replace('.html', '');
      const d = f.contentDocument, w = d.defaultView;
      const pick = (sel) => { const e = d.querySelector(sel); if (!e) return null; const b = e.getBoundingClientRect(), cs = w.getComputedStyle(e); return { h: +b.height.toFixed(1), w: +b.width.toFixed(1), pt: cs.paddingTop, fs: cs.fontSize }; };
      r[key] = { sw: d.documentElement.scrollWidth, vw: w.innerWidth, field: pick('#billing_phone'), place: pick('button.place'), cartBtn: pick('#cartPage a.cobtn'), panelBtn: pick('#cart .kc-btns a'), thumb: pick('#cart .kc-th') || pick('#cartPage .cth') || pick('.co-items .cth'), step: pick('.kc-qty button') || pick('.ci .qty button') };
    });
    return r;
  });
  await page.click('#tallChk');
  out.spacing = {};
  for (const dev of ['d', 'm']) {
    await page.click(`#devSeg button[data-dev="${dev}"]`);
    const g0 = await geo();
    await page.click('#lockChk'); // unlink
    const sp = page.locator('#ctls .ctl[data-g="space"] input[type=number]');
    await sp.fill('25'); await sp.dispatchEvent('change'); await page.waitForTimeout(80);
    const fontsAfterSpaceOnly = await sizes();
    let fontsMoved = 0;
    for (const [k, list] of Object.entries(fontsAfterSpaceOnly)) list.forEach(([g, now], i) => { if (Math.abs(now - base[k][i][1]) > 0.01) fontsMoved++; });
    const g1 = await geo();
    await page.click('#lockChk'); // link again (Spacing comes back to Text), then Text +25: both at +25
    const tx = page.locator('#ctls .ctl[data-g="text"] input[type=number]');
    await tx.fill('25'); await tx.dispatchEvent('change'); await page.waitForTimeout(80);
    out.spaceAfterLinkedText = await sp.inputValue();
    await page.locator('.fr:not(.off) .fr-box').first().screenshot({ path: path.join(SHOTS, `frame-plus25-${dev}.png`) });
    const g2 = await geo();
    const tv = await page.locator('#ctls .ctl[data-g="text"] input[type=number]').inputValue();
    out.spacing[dev] = { fontsMovedBySpacingAlone: fontsMoved, textAfterRelink: tv, before: g0, spacing25: g1, both25: g2 };
    await page.click('#resetAll');
    await page.waitForTimeout(50);
  }
  await page.click('#tallChk');
  const back = await sizes();
  out.resetClean = Object.entries(back).every(([k, list]) => list.every(([g, now], i) => Math.abs(now - base[k][i][1]) < 0.01));

  // copy values + memory
  await page.click('#devSeg button[data-dev="m"]');
  const mi = page.locator('#ctls .ctl[data-g="input"] input[type=number]');
  await mi.fill('15'); await mi.dispatchEvent('change');
  const warnOn = await page.locator('#ctls .ctl[data-g="input"] .warn').isVisible();
  await page.click('#copyBtn');
  out.copied = await page.locator('#out').inputValue();
  out.mobileFloorWarning = warnOn;
  await page.reload({ waitUntil: 'load' });
  await page.waitForFunction(() => document.documentElement.getAttribute('data-ready') === '1');
  out.remembered = await page.locator('#ctls .ctl[data-g="input"] input[type=number]').inputValue();
  await page.click('#resetAll');

  // pictures of the tool
  for (const [pg, dev] of [['checkout', 'd'], ['all', 'd'], ['cart', 'm'], ['panel', 'm']]) {
    await page.click(`#devSeg button[data-dev="${dev}"]`); await page.click(`#pageSeg button[data-page="${pg}"]`);
    await page.waitForTimeout(150);
    await page.screenshot({ path: path.join(SHOTS, `tool-1440-${pg}-${dev}.png`) });
  }
  // before / after, frame only
  const setv = async (g, v) => { const nb = page.locator(`#ctls .ctl[data-g="${g}"] input[type=number]`); await nb.fill(String(v)); await nb.dispatchEvent('change'); };
  await page.click('#tallChk');
  for (const [dev, pg, moves] of [['m', 'checkout', [['heading', 17], ['ph', 15.5], ['input', 17], ['label', 12]]], ['d', 'cart', [['text', 10]]], ['m', 'panel', [['names', 13], ['prices', 14], ['buttons', 13.5]]]]) {
    await page.click(`#devSeg button[data-dev="${dev}"]`); await page.click(`#pageSeg button[data-page="${pg}"]`);
    await page.waitForTimeout(150);
    const fr = page.locator('.fr:not(.off) .fr-box').first();
    await fr.screenshot({ path: path.join(SHOTS, `frame-${pg}-${dev}-before.png`) });
    for (const [g, v] of moves) await setv(g, v);
    await page.waitForTimeout(150);
    await fr.screenshot({ path: path.join(SHOTS, `frame-${pg}-${dev}-after.png`) });
    await page.click('#resetAll');
  }
  await page.click('#tallChk');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.click('#pageSeg button[data-page="checkout"]');
  await page.waitForTimeout(150);
  out.toolScrollWidth390 = await page.evaluate(() => document.documentElement.scrollWidth);
  await page.screenshot({ path: path.join(SHOTS, 'tool-390.png'), fullPage: false });
  await ctx.close();
  await browser.close();
  fs.writeFileSync(path.join(LOGS, 'check.json'), JSON.stringify(out, null, 1));
  console.log(JSON.stringify({ fidelity: out.fidelity, defaults: out.defaults.map((d) => [d.frame, d.elements, d.offAtDefault]), errors: out.errors, requests: out.requests, resetClean: out.resetClean, spacing: Object.fromEntries(Object.entries(out.spacing).map(([d, v]) => [d, { fontsMovedBySpacingAlone: v.fontsMovedBySpacingAlone, textAfterRelink: v.textAfterRelink, sw: Object.fromEntries(Object.entries(v.both25).map(([k, x]) => [k, x.sw + '/' + x.vw])) }])), copied: out.copied, remembered: out.remembered, warn: out.mobileFloorWarning, sw390: out.toolScrollWidth390 }, null, 0));
  for (const c of out.controls) console.log(JSON.stringify(c));
})().catch((e) => { console.error(e); process.exit(1); });
