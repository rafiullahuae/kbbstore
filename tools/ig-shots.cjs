/*
 * Instagram Profile — the pictures and the numbers. Lane IG, Phase 21.
 *
 *   node tools/ig-shots.cjs shop  <outDir> <label>   the section, both widths
 *   node tools/ig-shots.cjs page  <outDir>            the whole homepage
 *   node tools/ig-shots.cjs tap   <outDir>            the lightbox
 *   node tools/ig-shots.cjs admin <outDir>            Content -> Instagram
 *
 * Chromium at deviceScaleFactor 2, which is what the rest of docs/*-shots was
 * taken at. Each invocation writes <label>.json beside its pictures and the shell
 * loop in tools/ig-shots.sh merges them.
 *
 * ── THE SETTING IS CHANGED FROM OUTSIDE, NOT FROM A QUERY STRING ────────────
 *
 * The first draft of this camera asked for `/?ig_layout=rail`, which would have
 * meant the shipped page reading a layout out of the URL. That is a setting taken
 * from a request — rule 5's "a select stores one of its own options or the default"
 * pointed at the thing that decides what to render — and it would have been in the
 * shop forever so that a screenshot was easier to take. The shell loop writes the
 * real module setting between shots instead.
 *
 * `document.documentElement.scrollWidth` is reported beside every shot, because a
 * horizontal overflow is the one layout fault a screenshot hides: the picture is
 * cropped to the viewport and looks fine.
 *
 * ── AND THE MEASURING HAPPENS HERE, NOT ON THE SHOP ─────────────────────────
 *
 * Rule 4 forbids JavaScript that measures layout IN THE SHIPPED PAGE. A camera
 * that reports the numbers is the opposite of that: it is how the calc() answers
 * get checked without the page ever asking the browser for a size.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8951';
const EXEC = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const WIDTHS = [[390, 1500], [1280, 1400]];

async function measure(page) {
  return page.evaluate(() => {
    const box = (el) => el ? { w: Math.round(el.getBoundingClientRect().width), h: Math.round(el.getBoundingClientRect().height) } : null;
    const px = (el, p) => el ? Math.round(parseFloat(getComputedStyle(el)[p]) * 10) / 10 : null;
    const sec = document.querySelector('.igp');
    const track = document.querySelector('.igp-t');
    const cells = [...document.querySelectorAll('.igp-c')];
    const first = cells[0];
    return {
      viewport: document.documentElement.clientWidth,
      pageScrollWidth: document.documentElement.scrollWidth,
      horizontalOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      sectionClass: track ? track.className : null,
      section: box(sec),
      tiles: cells.length,
      tile: box(first),
      tileAspect: first ? Math.round((first.getBoundingClientRect().width / first.getBoundingClientRect().height) * 100) / 100 : null,
      columns: track ? getComputedStyle(track).gridTemplateColumns : null,
      gap: track ? px(track, 'columnGap') : null,
      radius: first ? px(first, 'borderTopLeftRadius') : null,
      trackScrollWidth: track ? track.scrollWidth : null,
      trackClientWidth: track ? track.clientWidth : null,
      railOverflows: track ? track.scrollWidth > track.clientWidth : null,
      headingFontSize: px(document.querySelector('.igp-h h2'), 'fontSize'),
      profileBox: box(document.querySelector('.igp-p')),
      avatar: box(document.querySelector('.igp-p img')),
      followText: (document.querySelector('.igp-pn span') || {}).textContent?.trim() ?? null,
      followButton: (document.querySelector('.igp-pf') || {}).textContent?.trim() ?? null,
      countsDrawn: document.querySelectorAll('.igp-m').length,
      firstCount: (document.querySelector('.igp-m') || {}).innerText?.replace(/\n/g, ' ') ?? null,
      playBadges: document.querySelectorAll('.igp-c.is-video').length,
      albumMarks: document.querySelectorAll('.igp-c.is-album').length,
      stretchedLinks: document.querySelectorAll('.igp-c > .igp-lk').length,
      imgDimensioned: [...document.querySelectorAll('.igp-c > img')].every(i => i.getAttribute('width') && i.getAttribute('height')),
      imgLazy: [...document.querySelectorAll('.igp-c > img')].every(i => i.getAttribute('loading') === 'lazy'),
      iframesAtLoad: document.querySelectorAll('iframe').length,
    };
  });
}

(async () => {
  const [mode, outDir, label] = process.argv.slice(2);
  fs.mkdirSync(outDir, { recursive: true });

  const browser = await chromium.launch({ executablePath: EXEC });
  const report = {};

  if (mode === 'shop') {
    for (const [w, h] of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
      const page = await ctx.newPage();
      /* Third-party requests are COUNTED rather than assumed. The section must make
         ZERO of them at page load, which is the whole of its performance claim:
         every thumbnail is a local file and the embed iframe is created on tap. */
      const thirdParty = [];
      page.on('request', r => { if (!r.url().startsWith(BASE)) thirdParty.push(r.url()); });

      await page.goto(BASE + '/', { waitUntil: 'networkidle' });
      await page.evaluate(() => document.querySelector('.igp')?.scrollIntoView({ block: 'center' }));
      await page.waitForTimeout(500);

      const m = await measure(page);
      m.thirdPartyRequests = thirdParty.length;
      m.thirdPartyHosts = [...new Set(thirdParty.map(u => { try { return new URL(u).host; } catch (e) { return u; } }))];
      report[`${label}-${w}`] = m;

      const el = await page.$('.igp');
      if (el) await el.screenshot({ path: `${outDir}/${label}-${w}.png` });
      await ctx.close();
    }
  }

  if (mode === 'page') {
    for (const [w, h] of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
      const page = await ctx.newPage();
      await page.goto(BASE + '/', { waitUntil: 'networkidle' });
      await page.evaluate(() => document.querySelector('.igp')?.scrollIntoView({ block: 'center' }));
      await page.waitForTimeout(500);
      report[`homepage-${w}`] = await measure(page);
      await page.screenshot({ path: `${outDir}/homepage-in-place-${w}.png` });
      await ctx.close();
    }
  }

  if (mode === 'tap') {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 1500 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.evaluate(() => document.querySelector('.igp')?.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(400);
    const before = await page.evaluate(() => document.querySelectorAll('iframe').length);
    await page.evaluate(() => document.querySelector('.igp-c > .igp-lk')?.click());
    await page.waitForTimeout(700);
    report['lightbox-390'] = {
      iframesBeforeTap: before,
      iframesAfterTap: await page.evaluate(() => document.querySelectorAll('iframe').length),
      src: await page.evaluate(() => document.querySelector('.igp-box iframe')?.getAttribute('src') ?? null),
      open: await page.evaluate(() => !!document.querySelector('.igp-box.is-open')),
      sandbox: await page.evaluate(() => document.querySelector('.igp-box iframe')?.getAttribute('sandbox') ?? null),
      referrerPolicy: await page.evaluate(() => document.querySelector('.igp-box iframe')?.getAttribute('referrerpolicy') ?? null),
    };
    await page.screenshot({ path: `${outDir}/lightbox-390.png` });
    await ctx.close();
  }

  if (mode === 'admin') {
    for (const [w, h] of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
      const page = await ctx.newPage();
      await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
      await page.fill('input[name=email]', 'owner@preview.test');
      await page.fill('input[name=password]', 'preview-secret-1');
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button[type=submit], input[type=submit]'),
      ]);
      await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
      await page.waitForTimeout(900);
      await page.evaluate(() => window.go('instagram'));
      await page.waitForTimeout(2000);

      report[`admin-${label||'fresh'}-${w}`] = await page.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        pageScrollWidth: document.documentElement.scrollWidth,
        horizontalOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        contentScrollWidth: document.querySelector('#content')?.scrollWidth ?? null,
        contentClientWidth: document.querySelector('#content')?.clientWidth ?? null,
        crumb: document.querySelector('#crumb')?.textContent ?? null,
        title: document.querySelector('#ptitle')?.textContent ?? null,
        sidebarRows: [...document.querySelectorAll('.side .nav-item')].filter(b => b.dataset.go === 'instagram').length,
        stepCount: document.querySelectorAll('.igs-step').length,
        stepStates: [...document.querySelectorAll('.igs-step')].map(s => s.className.replace('igs-step', '').trim() || 'todo'),
        redirectUri: document.querySelector('[data-igs-uri]')?.textContent ?? null,
        buttons: [...document.querySelectorAll('.igs-btn')].map(b => b.textContent.trim()),
        configureGreyedOut: !!document.querySelector('.igs-btn.is-off'),
        secretType: document.querySelector('[data-igs-secret]')?.type ?? null,
        secretPlaceholder: document.querySelector('[data-igs-secret]')?.placeholder ?? null,
        secretValueLength: (document.querySelector('[data-igs-secret]')?.value ?? '').length,
        appIdShown: document.querySelector('[data-igs-appid]')?.value ?? null,
        tabs: [...document.querySelectorAll('.igs-tab')].map(t => t.textContent.trim()),
        fieldCount: document.querySelectorAll('.igs-f').length,
        profileReadback: document.querySelector('.igs-me b')?.textContent ?? null,
        expiryNote: document.querySelector('.igs-note.is-good, .igs-note.is-warm, .igs-note.is-bad')?.innerText?.slice(0, 120) ?? null,
        /* The whole page's source, checked for the two values that must never be in
           it. A camera is the last place these could still appear. */
        leaksSecret: document.documentElement.outerHTML.includes('abcdef0123456789abcdef0123456789'),
      }));

      await page.screenshot({ path: `${outDir}/admin-connection-${label||"fresh"}-${w}.png`, fullPage: true });

      await page.evaluate(() => document.querySelector('[data-igs-tab="tile"]')?.click());
      await page.waitForTimeout(500);
      await page.screenshot({ path: `${outDir}/admin-tile-${label||"fresh"}-${w}.png`, fullPage: true });
      await ctx.close();
    }
  }

  fs.writeFileSync(`${outDir}/part-${mode}-${label || 'x'}.json`, JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
