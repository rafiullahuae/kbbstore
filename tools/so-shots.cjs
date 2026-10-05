/*
 * Lane SO -- the evidence.
 *
 *   sh tools/so-preview.sh base  10580 /path/to/base-worktree      (before)
 *   sh tools/so-preview.sh after 10590 <base preview.sqlite>        (after)
 *   BEFORE=http://127.0.0.1:10580 AFTER=http://127.0.0.1:10590 node tools/so-shots.cjs
 *
 * 1. The same three pages on both, at 390 and 1280: a category (Toners), the
 *    Medicube brand page (with its banner) and /super-sale/. Product order is
 *    read from the HTML and compared, and the screenshots are pixel-diffed in a
 *    canvas: the rows that differ are reported, and drawn in a diff image.
 * 2. The owner's sequence in the real admin on AFTER: Catalog -> Reorder ->
 *    Brands -> Medicube, move a product, Save; then Categories -> Super Sale,
 *    a different order, Save. Then the Medicube page must be in Medicube's
 *    order and /super-sale/ in Super Sale's.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BEFORE = process.env.BEFORE;
const AFTER = process.env.AFTER;
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/so-shots';
const out = { pages: {}, reorder: {} };
fs.mkdirSync(OUT, { recursive: true });

const PAGES = { category: '/collections/toners/', brand: '/brands/medicube/', supersale: '/super-sale/' };
const WIDTHS = [390, 1280];

const slugs = (html) => [...new Set([...html.matchAll(/\/product\/([a-z0-9-]+)\//g)].map((m) => m[1]))]
  .filter((s) => /^(medicube|anua)-product-/.test(s));

/** Links in the page's own content (<main id="content">) that leave it for the shop or a filter. */
const awayLinks = (page) => page.evaluate(() => [...document.querySelectorAll('main#content a[href]')]
  .map((a) => a.getAttribute('href'))
  .filter((h) => /\/shop\/|[?&](cat|brand|filter_brands|price|sale|instock)=/.test(h)));

async function shoot(browser, base, tag, warm = true) {
  // A pass that only loads: the shop's /img-cache/ makes a picture's small
  // copy on its first request, so the first visit and every later one are
  // drawn from different files. Both shops are photographed warm.
  if (warm) await shoot(browser, base, tag + '-warm', false);
  const res = {};
  for (const [name, path] of Object.entries(PAGES)) {
    for (const w of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      const r = await page.goto(base + path, { waitUntil: 'networkidle' });
      await page.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' });
      // Every picture decoded before the shot: lazy images load on scroll, and a
      // tile caught half-loaded is a difference that has nothing to do with code.
      await page.evaluate(async () => {
        for (let y = 0; y < document.documentElement.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); }
        window.scrollTo(0, 0);
        await Promise.all([...document.images].map((i) => { i.loading = 'eager'; return i.decode().catch(() => {}); }));
      });
      await page.waitForTimeout(400);
      const html = await page.content();
      const file = `${OUT}/${tag}-${name}-${w}.png`;
      if (!warm && tag.endsWith('-warm')) { await ctx.close(); continue; }
      await page.screenshot({ path: file, fullPage: true });
      res[`${name}@${w}`] = {
        status: r.status(),
        order: slugs(await (await page.request.get(base + path)).text()),
        scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
        height: await page.evaluate(() => document.documentElement.scrollHeight),
        filterRail: (html.match(/class="filtercol"/g) || []).length,
        filterButtons: (html.match(/id="showFilters"|class="mobi-filter"/g) || []).length,
        awayLinks: await awayLinks(page),
        file,
      };
      await ctx.close();
    }
  }
  return res;
}

/** Pixel diff of two PNGs in a canvas: differing rows grouped into bands, and a diff image. */
async function diff(browser, a, b, outFile) {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const A = 'data:image/png;base64,' + fs.readFileSync(a).toString('base64');
  const B = 'data:image/png;base64,' + fs.readFileSync(b).toString('base64');
  const r = await page.evaluate(async ([A, B]) => {
    const load = (src) => new Promise((ok) => { const i = new Image(); i.onload = () => ok(i); i.src = src; });
    const [ia, ib] = await Promise.all([load(A), load(B)]);
    const w = Math.max(ia.width, ib.width), h = Math.max(ia.height, ib.height);
    const px = (img) => { const c = new OffscreenCanvas(w, h); const x = c.getContext('2d'); x.fillStyle = '#000'; x.fillRect(0, 0, w, h); x.drawImage(img, 0, 0); return x.getImageData(0, 0, w, h).data; };
    const da = px(ia), db = px(ib);
    const out = new OffscreenCanvas(w, h); const ox = out.getContext('2d'); ox.drawImage(ib, 0, 0); ox.fillStyle = 'rgba(255,255,255,.75)'; ox.fillRect(0, 0, w, h);
    const od = ox.getImageData(0, 0, w, h);
    let n = 0; const rows = new Set(); let minX = w, maxX = -1;
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      const k = (y * w + x) * 4;
      if (da[k] !== db[k] || da[k + 1] !== db[k + 1] || da[k + 2] !== db[k + 2]) {
        n++; rows.add(y); minX = Math.min(minX, x); maxX = Math.max(maxX, x);
        od.data[k] = 230; od.data[k + 1] = 20; od.data[k + 2] = 60; od.data[k + 3] = 255;
      }
    }
    ox.putImageData(od, 0, 0);
    const bands = []; let s = null, p = null;
    [...rows].sort((x, y) => x - y).forEach((y) => { if (s === null) { s = p = y; } else if (y === p + 1) { p = y; } else { bands.push([s, p]); s = p = y; } });
    if (s !== null) bands.push([s, p]);
    const blob = await out.convertToBlob({ type: 'image/png' });
    const buf = new Uint8Array(await blob.arrayBuffer());
    let bin = ''; for (let i = 0; i < buf.length; i++) bin += String.fromCharCode(buf[i]);
    return { size: [ia.width, ia.height, ib.width, ib.height], differing: n, bands: bands.slice(0, 12), x: n ? [minX, maxX] : null, png: btoa(bin) };
  }, [A, B]);
  fs.writeFileSync(outFile, Buffer.from(r.png, 'base64'));
  delete r.png;
  await ctx.close();
  return r;
}

async function reorder(browser) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(AFTER + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  // Catalog -> Catalog -> Reorder, the way the sidebar gets there.
  await page.evaluate(() => window.go('catalog', 'reorder'));
  await page.waitForSelector('#reTypeBrand', { timeout: 20000 });

  const names = () => page.$$eval('#rlist .ritem .pname', (els) => els.map((e) => e.textContent.trim()));
  const pick = async (label) => {
    const v = await page.$eval('#reCat', (s, l) => [...s.options].find((o) => o.textContent.replace(/^[\s↳]+/, '').trim() === l).value, label);
    await page.selectOption('#reCat', v);
    await page.waitForTimeout(800);
  };
  const toTop = async (name) => {
    const id = await page.$eval('#rlist', (l, n) => [...l.querySelectorAll('.ritem')].find((r) => r.querySelector('.pname').textContent.trim() === n).dataset.id, name);
    await page.click(`[data-rfirst="${id}"]`);
    await page.waitForTimeout(200);
  };
  const save = async () => { await page.click('#reSave'); await page.waitForTimeout(1200); };

  // 1. Medicube brand: H, then F, to the top.
  await page.click('#reTypeBrand');
  await page.waitForTimeout(900);
  await pick('Medicube');
  out.reorder.brandBefore = await names();
  await toTop('Medicube Product F');
  await toTop('Medicube Product H');
  await page.screenshot({ path: `${OUT}/admin-reorder-medicube-unsaved.png` });
  await save();
  out.reorder.brandSaved = await names();
  await page.screenshot({ path: `${OUT}/admin-reorder-medicube-saved.png` });

  // 2. Super Sale category: a totally different order of the shared products.
  await page.click('#reTypeCat');
  await page.waitForTimeout(900);
  await pick('Super Sale');
  out.reorder.saleBefore = await names();
  for (const n of ['Medicube Product A', 'Medicube Product D', 'Anua Product Z', 'Medicube Product C']) await toTop(n);
  await save();
  out.reorder.saleSaved = await names();
  await page.screenshot({ path: `${OUT}/admin-reorder-supersale-saved.png` });

  // 3. The shop.
  const slugOf = (n) => n.toLowerCase().replace(/ /g, '-');
  const brandHtml = await (await page.request.get(AFTER + '/brands/medicube/')).text();
  const saleHtml = await (await page.request.get(AFTER + '/super-sale/')).text();
  out.reorder.brandPage = slugs(brandHtml);
  out.reorder.saleCategoryPage = slugs(await (await page.request.get(AFTER + '/collections/super-sale/')).text());
  out.reorder.superSalePage = slugs(saleHtml);
  // Compared on this seed's own products: the preview also carries the demo
  // catalogue's three Medicube products, which the shop lists under the same
  // brand but slugs() does not collect.
  const mine = (list) => list.map(slugOf).filter((s) => /^(medicube|anua)-product-/.test(s));
  out.reorder.brandPageMatchesBrandSave = JSON.stringify(out.reorder.brandPage) === JSON.stringify(mine(out.reorder.brandSaved));
  out.reorder.superSaleMatchesSaleSave = JSON.stringify(out.reorder.superSalePage) === JSON.stringify(mine(out.reorder.saleSaved));
  out.reorder.saleCategoryMatchesSaleSave = JSON.stringify(out.reorder.saleCategoryPage) === JSON.stringify(mine(out.reorder.saleSaved));
  for (const w of WIDTHS) {
    const c = await browser.newContext({ viewport: { width: w, height: 900 } });
    const p = await c.newPage();
    for (const [name, path] of [['brand', '/brands/medicube/'], ['supersale', '/super-sale/']]) {
      await p.goto(AFTER + path, { waitUntil: 'networkidle' });
      await p.screenshot({ path: `${OUT}/reordered-${name}-${w}.png`, fullPage: true });
    }
    await c.close();
  }
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  if (BEFORE) out.pages.before = await shoot(browser, BEFORE, 'before');
  if (AFTER) out.pages.after = await shoot(browser, AFTER, 'after');
  if (BEFORE && AFTER) {
    // THE CONTROL: the base shop shot a second time. Whatever differs between
    // two renders of the SAME code (the category header's random light-box
    // colour, a blinking caret) is noise, and is reported as such.
    out.pages.control = await shoot(browser, BEFORE, 'control');
    out.control = {};
    for (const name of Object.keys(PAGES)) for (const w of WIDTHS) {
      const k = `${name}@${w}`;
      out.control[k] = await diff(browser, out.pages.before[k].file, out.pages.control[k].file, `${OUT}/control-diff-${name}-${w}.png`);
    }
    out.diff = {};
    for (const name of Object.keys(PAGES)) for (const w of WIDTHS) {
      const k = `${name}@${w}`;
      out.diff[k] = await diff(browser, out.pages.before[k].file, out.pages.after[k].file, `${OUT}/diff-${name}-${w}.png`);
      out.diff[k].sameOrder = JSON.stringify(out.pages.before[k].order) === JSON.stringify(out.pages.after[k].order);
    }
  }
  fs.writeFileSync(`${OUT}/numbers.json`, JSON.stringify(out, null, 1));
  if (AFTER && process.env.REORDER !== '0') await reorder(browser);
  await browser.close();
  fs.writeFileSync(`${OUT}/numbers.json`, JSON.stringify(out, null, 1));
  console.log(JSON.stringify(out, null, 1));
})();
