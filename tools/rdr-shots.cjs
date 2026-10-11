// Lane RD: screenshots and measurements of the customer-reviews section designs.
//   sh tools/rdr-preview.sh [port]   then   node tools/rdr-shots.cjs <port>
//
// Loads the REAL homepage the preview serves (English at /, Arabic at /ar/),
// injects one design -- rendered through Blade from the preview database by
// tools/rdr-render.php, so every name, star and product in the shot is a row --
// under the banner, and photographs it. No shop file changes; the designs are
// not wired into any page. getBoundingClientRect / elementFromPoint here are the
// HARNESS measuring the page; the designs' own script measures nothing.
//
// Writes docs/lane-rd-shots/design-<L>-<w>.png (+ -moved for carousels, + -ar-),
// contact-1280.png, contact-390.png and measurements.json.
'use strict';
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));

const PORT = process.argv[2] || 12430;
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-rd-shots');
fs.mkdirSync(OUT, { recursive: true });
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const UA_M = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const LETTERS = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
const CAROUSEL = { a: 'track', b: 'spot', c: 'marquee', d: 'track' };
const costs = JSON.parse(fs.readFileSync(path.join(OUT, 'costs.json'), 'utf8'));
const frag = async (name) => (await fetch(`${BASE}/rdr/${name}.html`)).text();

function inject({ base, js, html }) {
  // Floating buttons (WhatsApp etc.) hidden in the shots only.
  for (const el of document.querySelectorAll('body *')) {
    const cs = getComputedStyle(el);
    if (cs.position === 'fixed' && el.getBoundingClientRect().top > innerHeight / 2) el.style.visibility = 'hidden';
  }
  const tpl = document.createElement('template');
  tpl.innerHTML = base + html;
  const nodes = [...tpl.content.children];
  const hero = document.querySelector('.kbb-home > section.sec');
  let at = hero;
  for (const n of nodes) {
    if (n.tagName === 'STYLE') document.head.appendChild(n);
    else { at.after(n); at = n; n.setAttribute('data-rdr', '1'); }
  }
  const s = document.createElement('script');
  s.textContent = js.replace(/^[\s\S]*?<script>/, '').replace(/<\/script>[\s\S]*$/, '');
  document.body.appendChild(s);
}

function measure() {
  const sec = document.querySelector('[data-rdr]');
  const r = sec.getBoundingClientRect();
  const hidden = (el) => el.closest('[inert],[aria-hidden="true"]') || getComputedStyle(el).visibility === 'hidden';
  const clipBox = (el) => {
    // the visible box: intersect with every clipping ancestor
    let b = el.getBoundingClientRect();
    let box = { l: b.left, t: b.top, r: b.right, b: b.bottom };
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      const cs = getComputedStyle(p);
      if (cs.overflowX !== 'visible' || cs.overflowY !== 'visible') {
        const q = p.getBoundingClientRect();
        box = { l: Math.max(box.l, q.left), t: Math.max(box.t, q.top), r: Math.min(box.r, q.right), b: Math.min(box.b, q.bottom) };
      }
    }
    return box;
  };
  const targets = [...sec.querySelectorAll('a[href], button')].filter((el) => el.getClientRects().length && !hidden(el));
  let checked = 0, skipped = 0;
  const fails = [];
  for (const el of targets) {
    const r0 = el.getBoundingClientRect();
    if (r0.top < innerHeight * 0.25 || r0.bottom > innerHeight * 0.7) window.scrollTo({ top: scrollY + (r0.top + r0.bottom) / 2 - innerHeight / 2, behavior: 'instant' });
    const b = clipBox(el);
    if (b.r - b.l < 4 || b.b - b.t < 4) { skipped++; continue; }
    const x = (b.l + b.r) / 2, y = (b.t + b.b) / 2;
    if (y < 0 || y > innerHeight || x < 0 || x > innerWidth) { skipped++; continue; }
    checked++;
    const hit = document.elementFromPoint(x, y);
    if (!(hit === el || el.contains(hit))) fails.push((el.getAttribute('aria-label') || el.textContent.trim()).slice(0, 30) + ' -> ' + (hit ? hit.className || hit.tagName : 'null'));
  }
  const imgs = [...sec.querySelectorAll('img')];
  return {
    vw: innerWidth,
    docScrollWidth: document.documentElement.scrollWidth,
    noHorizontalScroll: document.documentElement.scrollWidth <= innerWidth,
    sectionH: Math.round(r.height),
    dir: getComputedStyle(sec).direction,
    font: getComputedStyle(sec.querySelector('h2') || sec).fontFamily.split(',')[0],
    links: { checked, skipped, fails },
    imgs: imgs.length,
    imgsLazy: imgs.filter((i) => i.loading === 'lazy').length,
    imgsWithSrcset: imgs.filter((i) => i.srcset).length,
    imgCurrentSrc: imgs.slice(0, 2).map((i) => (i.currentSrc || '').replace(location.origin, '')),
    wrapScrollLeft: [...sec.querySelectorAll('.wrap')].map((w) => w.scrollLeft),
  };
}

async function shoot(page, file) {
  // The shop's sticky header would be stitched into a section taller than the
  // viewport; it is hidden for the photograph only and put back after.
  await page.evaluate(() => { for (const el of document.querySelectorAll('body *')) { const p = getComputedStyle(el).position; if ((p === 'sticky' || p === 'fixed') && !el.closest('[data-rdr]')) { el.dataset.rdrVis = el.style.opacity; el.style.opacity = '0'; } } });
  const el = await page.$('[data-rdr]');
  await el.screenshot({ path: path.join(OUT, file), animations: 'allow' });
  await page.evaluate(() => { for (const el of document.querySelectorAll('[data-rdr-vis]')) { el.style.opacity = el.dataset.rdrVis; delete el.dataset.rdrVis; } });
}

async function settle(page) {
  await page.evaluate(() => document.querySelector('[data-rdr]').scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(500);
  await page.evaluate(() => Promise.all([...document.querySelectorAll('[data-rdr] img')].filter((i) => i.getClientRects().length).map((i) => i.complete ? 0 : new Promise((res) => { i.onload = i.onerror = res; setTimeout(res, 3000); }))));
  await page.waitForTimeout(200);
}

async function open(br, { w, loc, letter, reduce = false }) {
  const mobile = w < 768;
  const ctx = await br.newContext({
    viewport: { width: w, height: mobile ? 844 : 900 }, deviceScaleFactor: mobile ? 2 : 1,
    userAgent: mobile ? UA_M : UA_D, isMobile: mobile, hasTouch: mobile, reducedMotion: reduce ? 'reduce' : 'no-preference',
  });
  const page = await ctx.newPage();
  const errors = { before: [], after: [] };
  let phase = 'before';
  const requests = [];
  page.on('console', (m) => { if (m.type() === 'error') errors[phase].push(m.text().slice(0, 160)); });
  page.on('pageerror', (e) => errors[phase].push('pageerror: ' + String(e).slice(0, 160)));
  page.on('request', (r) => { if (phase === 'after') requests.push(r.url().replace(BASE, '')); });
  page.on('response', (r) => { if (r.status() >= 400) errors[phase].push(r.status() + ' ' + r.url().replace(BASE, '')); });
  await page.goto(BASE + (loc === 'ar' ? '/ar/' : '/'), { waitUntil: 'load' });
  await page.waitForTimeout(300);
  const [base, js, html] = await Promise.all([frag('_base'), frag('_js'), frag(`${letter}.${loc}`)]);
  phase = 'after';
  await page.evaluate(inject, { base, js, html });
  return { ctx, page, errors, requests };
}

async function carouselMove(page, kind, mobile) {
  return page.evaluate(async ({ kind, mobile }) => {
    const sec = document.querySelector('[data-rdr]');
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    if (kind === 'track') {
      const t = sec.querySelector('[data-rs-track]');
      const before = t.scrollLeft;
      const btn = mobile ? sec.querySelectorAll('[data-rs-dot]')[2] : sec.querySelector('[data-rs-go=next]');
      btn.click();
      await wait(1100);
      const cur = [...sec.querySelectorAll('[data-rs-dot]')].findIndex((d) => d.getAttribute('aria-current') === 'true');
      return { moved: Math.abs(t.scrollLeft - before) > 20, scrollLeft: Math.round(t.scrollLeft), currentDot: cur, pageScrollX: scrollX };
    }
    if (kind === 'spot') {
      sec.querySelector('[data-rs-go=next]').click();
      await wait(900);
      const on = [...sec.querySelectorAll('[data-rs-slide]')].findIndex((s) => s.classList.contains('on'));
      return { moved: on === 1, activeSlide: on, stopped: sec.classList.contains('is-stopped') };
    }
    const mv = sec.querySelector('.rs-c-mv');
    const a = getComputedStyle(mv).transform;
    await wait(2500);
    const b = getComputedStyle(mv).transform;
    return { moved: a !== b, transformA: a, transformB: b };
  }, { kind, mobile });
}

(async () => {
  const br = await chromium.launch({ executablePath: EXE });
  const results = {};
  for (const loc of ['en', 'ar']) {
    for (const w of [1280, 390]) {
      for (const letter of LETTERS) {
        const L = letter.toUpperCase();
        const key = `${L}-${loc}-${w}`;
        const tag = loc === 'ar' ? `design-${L}-ar-${w}` : `design-${L}-${w}`;
        const { ctx, page, errors, requests } = await open(br, { w, loc, letter });
        await settle(page);
        const res = await page.evaluate(measure);
        await shoot(page, `${tag}.png`);
        const kind = CAROUSEL[letter];
        if (kind) {
          // Keyboard: Tab-reachable real buttons, Enter moves it.
          const kb = await page.evaluate(() => {
            const s = document.querySelector('[data-rdr]');
            const b = [...s.querySelectorAll('button')].filter((x) => x.getClientRects().length);
            return { buttons: b.length, allRealButtons: b.every((x) => x.tagName === 'BUTTON' && x.type === 'button' && (x.getAttribute('aria-label') || '').length > 0) };
          });
          res.keyboard = kb;
          res.move = await carouselMove(page, kind, w < 768);
          await page.waitForTimeout(250);
          await shoot(page, `${tag}-moved.png`);
          res.wrapScrollLeftAfter = await page.evaluate(() => [...document.querySelectorAll('[data-rdr] .wrap')].map((x) => x.scrollLeft));
          res.docScrollWidthAfter = await page.evaluate(() => document.documentElement.scrollWidth);
        }
        // A real click on "See all reviews" navigates.
        const all = await page.$('[data-rdr] a.rs-all, [data-rdr] .rs-f-ft a');
        if (all) {
          await all.scrollIntoViewIfNeeded();
          await Promise.all([page.waitForURL(/\/reviews\/$/, { timeout: 8000 }).catch(() => null), all.click()]);
          res.seeAllNavigates = page.url().replace(BASE, '');
        }
        res.consoleErrors = errors;
        res.requestsAfterInject = requests.filter((u) => !u.includes('/rdr/') && !/\/reviews\/$/.test(u));
        results[key] = res;
        console.log(key, JSON.stringify({ sw: res.docScrollWidth, h: res.sectionH, links: `${res.links.checked} ok-${res.links.checked - res.links.fails.length}`, fails: res.links.fails, move: res.move && res.move.moved, all: res.seeAllNavigates, err: errors.after.length }));
        await ctx.close();
      }
    }
  }

  // Behaviour of the moving designs, once each, at 1280 in English.
  const beh = {};
  {
    const { ctx, page } = await open(br, { w: 1280, loc: 'en', letter: 'b' });
    await settle(page);
    await page.mouse.move(5, 5);
    await page.waitForTimeout(6800);
    beh.spotlightAutoAdvances = await page.evaluate(() => [...document.querySelectorAll('[data-rdr] [data-rs-slide]')].findIndex((s) => s.classList.contains('on')));
    await page.hover('[data-rdr] blockquote >> visible=true');
    const before = beh.spotlightAutoAdvances;
    await page.waitForTimeout(7000);
    beh.spotlightPausedOnHover = (await page.evaluate(() => [...document.querySelectorAll('[data-rdr] [data-rs-slide]')].findIndex((s) => s.classList.contains('on')))) === before;
    await page.mouse.move(5, 5);
    await page.click('[data-rdr] [data-rs-pp]');
    await page.mouse.move(5, 5);
    await page.waitForTimeout(7000);
    beh.spotlightPauseButtonHolds = (await page.evaluate(() => [...document.querySelectorAll('[data-rdr] [data-rs-slide]')].findIndex((s) => s.classList.contains('on')))) === before;
    beh.pauseButtonPressed = await page.getAttribute('[data-rdr] [data-rs-pp]', 'aria-pressed');
    // Keyboard: Tab to "next", press Enter.
    await page.focus('[data-rdr] [data-rs-go=next]');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(800);
    beh.spotlightEnterOnNext = await page.evaluate(() => [...document.querySelectorAll('[data-rdr] [data-rs-slide]')].findIndex((s) => s.classList.contains('on')));
    beh.spotlightStoppedAfterPress = await page.evaluate(() => document.querySelector('[data-rdr]').classList.contains('is-stopped'));
    await ctx.close();
  }
  {
    const { ctx, page } = await open(br, { w: 1280, loc: 'en', letter: 'b', reduce: true });
    await settle(page);
    await page.mouse.move(5, 5);
    await page.waitForTimeout(7000);
    beh.spotlightReducedMotionStill = (await page.evaluate(() => [...document.querySelectorAll('[data-rdr] [data-rs-slide]')].findIndex((s) => s.classList.contains('on')))) === 0;
    await ctx.close();
  }
  {
    const { ctx, page } = await open(br, { w: 1280, loc: 'en', letter: 'c', reduce: true });
    await settle(page);
    beh.marqueeReducedMotion = await page.evaluate(() => getComputedStyle(document.querySelector('[data-rdr] .rs-c-mv')).animationName);
    await ctx.close();
  }
  {
    const { ctx, page } = await open(br, { w: 1280, loc: 'en', letter: 'c' });
    await settle(page);
    await page.hover('[data-rdr] .rs-c-row');
    const a = await page.evaluate(() => getComputedStyle(document.querySelector('[data-rdr] .rs-c-mv')).transform);
    await page.waitForTimeout(1500);
    const b = await page.evaluate(() => getComputedStyle(document.querySelector('[data-rdr] .rs-c-mv')).transform);
    beh.marqueePausedOnHover = a === b;
    await ctx.close();
  }
  for (const letter of ['a', 'd']) {
    const { ctx, page } = await open(br, { w: 1280, loc: 'en', letter });
    await settle(page);
    await page.focus('[data-rdr] [data-rs-go=next]');
    const s0 = await page.evaluate(() => document.querySelector('[data-rdr] [data-rs-track]').scrollLeft);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(1000);
    const s1 = await page.evaluate(() => document.querySelector('[data-rdr] [data-rs-track]').scrollLeft);
    await page.focus('[data-rdr] [data-rs-track]');
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(700);
    const s2 = await page.evaluate(() => document.querySelector('[data-rdr] [data-rs-track]').scrollLeft);
    beh[`${letter.toUpperCase()}keyboard`] = { enterOnNextMoved: s1 > s0, arrowKeyOnTrackMoved: s2 !== s1, s0, s1, s2 };
    await ctx.close();
  }
  console.log('behaviour', JSON.stringify(beh));

  // Contact sheets.
  for (const w of [1280, 390]) {
    const cols = w === 1280 ? 2 : 8;
    const cell = w === 1280 ? 760 : 300;
    const items = LETTERS.map((l) => {
      const L = l.toUpperCase();
      const c = costs.designs[l];
      return `<figure><figcaption><b>${L}</b> ${c.name}${c.carousel ? ' <i>carousel</i>' : ''}<span>${c.css_bytes + ' B CSS'}</span></figcaption><img src="file://${path.join(OUT, `design-${L}-${w}.png`)}"></figure>`;
    }).join('');
    const sheet = `<!doctype html><meta charset=utf-8><style>body{margin:0;padding:24px;background:#F4EFF1;font:14px system-ui,sans-serif;color:#2A2228}h1{font-size:18px;margin:0 0 16px}main{display:grid;grid-template-columns:repeat(${cols},${cell}px);gap:18px;align-items:start}figure{margin:0;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)}figcaption{display:flex;gap:8px;align-items:center;padding:10px 12px;border-bottom:1px solid #eee;font-size:13px}figcaption b{display:grid;place-items:center;width:24px;height:24px;border-radius:50%;background:#2A2228;color:#fff;font-size:12px}figcaption i{font-style:normal;font-size:11px;background:#FFF0F4;color:#A82F53;border-radius:99px;padding:2px 8px}figcaption span{margin-left:auto;color:#756C74;font-size:12px}img{display:block;width:100%;height:auto}</style><h1>Customer reviews section · designs A–H · ${w}px · real Blade partials, preview data</h1><main>${items}</main>`;
    const f = path.join(OUT, `contact-${w}.html`);
    fs.writeFileSync(f, sheet);
    const ctx = await br.newContext({ viewport: { width: 24 * 2 + cols * cell + (cols - 1) * 18, height: 800 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await page.goto('file://' + f, { waitUntil: 'load' });
    await page.screenshot({ path: path.join(OUT, `contact-${w}.png`), fullPage: true });
    await ctx.close();
    fs.unlinkSync(f);
  }

  fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify({ results, behaviour: beh }, null, 2) + '\n');
  await br.close();
})();
