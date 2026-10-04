/*
 * Lane BR — the evidence. Run against tools/br-preview.sh (port 10081 unless
 * BASE says otherwise). The preview is seeded the way the live shop was:
 * store name, SEO site name, organisation, email From name and the footer's big
 * name all "Extra Beauty", the three brand switches off.
 *
 *   1. BEFORE  home + product <head> excerpts (as text pictures), the footer,
 *              the order email, and Store -> SEO Keywords -> Brand name showing
 *              the dry run. 390 and 1280.
 *   2. The tool is used: "Replace with K-Beauty Bliss", then the three switches
 *      ticked and saved — exactly the owner's clicks.
 *   3. AFTER   the same surfaces again.
 *
 * Chromium 1194, browser.newContext({viewport}), deviceScaleFactor 1.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:10081';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-br-shots';
const numbers = {};

async function signIn(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
}

const measure = (page) => page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));

function headExcerpt(html) {
  const pick = (re) => (html.match(re) || []).join('\n');
  const lines = [
    (html.match(/<title>[^<]*<\/title>/) || [''])[0], // the document's; later ones are inside inline SVG
    pick(/<meta name="description"[^>]*>/g),
    pick(/<meta name="keywords"[^>]*>/g),
    pick(/<meta property="og:(site_name|title)"[^>]*>/g),
  ];
  const ld = [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)].map((m) => JSON.parse(m[1]));
  for (const n of ld) {
    if (['WebSite', 'OnlineStore', 'Organization'].includes(n['@type'])) {
      const keep = { '@type': n['@type'], name: n.name };
      if (n.alternateName) keep.alternateName = n.alternateName;
      lines.push('JSON-LD ' + JSON.stringify(keep, null, 1).replace(/\n\s*/g, ' '));
    }
    if (n['@type'] === 'Product') lines.push('JSON-LD Product keywords: ' + (n.keywords || '(none)'));
  }
  return lines.filter(Boolean).join('\n');
}

async function textShot(browser, width, text, file, label) {
  const ctx = await browser.newContext({ viewport: { width, height: 400 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const esc = (s) => s.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
  await page.setContent(`<!doctype html><meta charset="utf-8"><body style="margin:0;padding:16px;font:13px/1.55 ui-monospace,Menlo,monospace;background:#fff;color:#1f2328"><div style="font:600 14px system-ui;margin-bottom:8px">${esc(label)}</div><pre style="white-space:pre-wrap;word-break:break-word;margin:0;background:#f6f8fa;border:1px solid #d0d7de;border-radius:8px;padding:12px">${esc(text)}</pre></body>`);
  await page.screenshot({ path: `${OUT}/${file}`, fullPage: true });
  numbers[file] = await measure(page);
  await ctx.close();
}

async function surfaces(browser, tag) {
  const home = await (await fetch(BASE + '/')).text();
  const pdp = await (await fetch(BASE + '/product/pdp-heartleaf-toner/')).text();
  fs.writeFileSync(`${OUT}/${tag}-home-head.txt`, headExcerpt(home) + '\n');
  fs.writeFileSync(`${OUT}/${tag}-product-head.txt`, headExcerpt(pdp) + '\n');
  numbers[`${tag}-home-extra-beauty-count`] = (home.match(/extra ?beauty/gi) || []).length;
  numbers[`${tag}-product-extra-beauty-count`] = (pdp.match(/extra ?beauty/gi) || []).length;

  for (const width of [390, 1280]) {
    await textShot(browser, width, headExcerpt(home), `${tag}-home-head-${width}.png`, `Home page <head> — ${tag}`);
    await textShot(browser, width, headExcerpt(pdp), `${tag}-product-head-${width}.png`, `Product page <head> — ${tag}`);

    const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const footer = page.locator('footer').last();
    await footer.scrollIntoViewIfNeeded();
    await footer.screenshot({ path: `${OUT}/${tag}-footer-${width}.png` });
    numbers[`${tag}-footer-${width}`] = await measure(page);

    await signIn(page);
    await page.goto(BASE + '/admin-api/emails/preview?template=order_confirmation', { waitUntil: 'networkidle' });
    await page.screenshot({ path: `${OUT}/${tag}-email-${width}.png`, fullPage: true });
    numbers[`${tag}-email-${width}`] = await measure(page);
    numbers[`${tag}-email-extra-beauty-count`] = ((await page.content()).match(/extra ?beauty/gi) || []).length;
    await ctx.close();
  }
}

async function brandTab(browser, width, file) {
  const ctx = await browser.newContext({ viewport: { width, height: 1000 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  page.on('dialog', (d) => d.accept());
  await signIn(page);
  await page.evaluate(() => window.go('seokeywords'));
  await page.waitForSelector('[data-skw-tab="brand"]', { timeout: 15000 });
  await page.click('[data-skw-tab="brand"]');
  await page.waitForSelector('#skwBrTitles', { timeout: 15000 });
  await page.waitForTimeout(300);
  await page.locator('#content').screenshot({ path: `${OUT}/${file}-${width}.png` });
  numbers[`${file}-${width}`] = await measure(page);
  return { ctx, page };
}

async function extra(browser) {
  /*
   * PHASE=extra, after the main run. (1) The order email before/after, from
   * HTML rendered by KitSamples in the preview's own process with the store
   * name set to "Extra Beauty" and then renamed (the admin preview needs an
   * order and caches the brand per process). (2) The head excerpts again from
   * their .txt files, so a re-shoot never needs the BEFORE state back. (3) The
   * home and product heads after one keyword sync, which is when the
   * one-k-beauty-phrase rule shows in JSON-LD and the keywords tag.
   */
  for (const tag of ['before', 'after']) {
    const file = `storage/br-logs/email-${tag}.html`;
    if (!fs.existsSync(file)) continue;
    for (const width of [390, 1280]) {
      const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      await page.setContent(fs.readFileSync(file, 'utf8'), { waitUntil: 'load' });
      await page.screenshot({ path: `${OUT}/${tag}-email-${width}.png`, fullPage: true });
      numbers[`${tag}-email-${width}`] = await measure(page);
      await ctx.close();
    }
    numbers[`${tag}-email-extra-beauty-count`] = (fs.readFileSync(file, 'utf8').match(/extra ?beauty/gi) || []).length;
  }
  for (const tag of ['before', 'after']) {
    for (const what of ['home', 'product']) {
      const txt = `${OUT}/${tag}-${what}-head.txt`;
      if (!fs.existsSync(txt)) continue;
      const clean = fs.readFileSync(txt, 'utf8').split('\n').filter((l) => !/^<title>(tabby|tamara)<\/title>$/.test(l)).join('\n');
      fs.writeFileSync(txt, clean);
      for (const width of [390, 1280]) await textShot(browser, width, clean.trim(), `${tag}-${what}-head-${width}.png`, `${what === 'home' ? 'Home' : 'Product'} page <head> — ${tag}`);
    }
  }
  const home = await (await fetch(BASE + '/')).text();
  const pdp = await (await fetch(BASE + '/product/pdp-heartleaf-toner/')).text();
  fs.writeFileSync(`${OUT}/after-sync-home-head.txt`, headExcerpt(home) + '\n');
  fs.writeFileSync(`${OUT}/after-sync-product-head.txt`, headExcerpt(pdp) + '\n');
  for (const width of [390, 1280]) {
    await textShot(browser, width, headExcerpt(home), `after-sync-home-head-${width}.png`, 'Home page <head> — after the brand rename and one keyword sync');
    await textShot(browser, width, headExcerpt(pdp), `after-sync-product-head-${width}.png`, 'Product page <head> — after the brand rename and one keyword sync');
  }
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  if (process.env.PHASE === 'extra') {
    const prev = fs.existsSync(`${OUT}/numbers.json`) ? JSON.parse(fs.readFileSync(`${OUT}/numbers.json`, 'utf8')) : {};
    Object.assign(numbers, prev);
    await extra(browser);
    fs.writeFileSync(`${OUT}/numbers.json`, JSON.stringify(numbers, null, 1) + '\n');
    console.log('extra done');
    await browser.close();
    return;
  }

  await surfaces(browser, 'before');
  for (const w of [390]) { const { ctx } = await brandTab(browser, w, 'tool-dry-run'); await ctx.close(); }

  // The owner's clicks, at desktop width: dry run shown -> Replace -> switches on -> Save.
  const { ctx, page } = await brandTab(browser, 1280, 'tool-dry-run');
  await page.click('[data-skw-act="brand-replace"]');
  await page.waitForSelector('.skw-ok', { timeout: 15000 });
  for (const id of ['#skwBrTitles', '#skwBrAlt', '#skwBrOne']) await page.check(id);
  await page.click('[data-skw-act="brand-save"]');
  await page.waitForTimeout(800);
  await page.locator('#content').screenshot({ path: `${OUT}/tool-after-replace-1280.png` });
  await ctx.close();
  { const { ctx: c2 } = await brandTab(browser, 390, 'tool-after-replace'); await c2.close(); }

  await surfaces(browser, 'after');
  fs.writeFileSync(`${OUT}/numbers.json`, JSON.stringify(numbers, null, 1) + '\n');
  console.log(JSON.stringify(numbers));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
