/* Lane HL2 (17 October 2026) -- PREVIEWS ONLY, round 2 of tools/hl-lang-previews.cjs.
   Nothing here ships: the header is frozen (CLAUDE.md), so every candidate is
   INJECTED into a rendered preview page in Chromium and photographed with the
   "PREVIEW ONLY" ribbon. No shop view, stylesheet, setting or test is touched.

     BASE=http://127.0.0.1:<port> NODE_PATH=$(npm root -g) node tools/hl2-previews.cjs

   against tools/arr-preview.sh (Arabic on, mirrored). Writes
   docs/lane-hl/round2/*.png and docs/lane-hl/round2/measurements.json.

   The owner's round-1 decisions this answers:
     E2  -- the "ع"/"EN" circle with the letter optically centred (ink box centre
            vs circle centre within ±0.5px, measured from a DPR-4 screenshot).
     H1  -- a capsule naming the language you switch TO ("العربية" / "English").
     H2  -- a capsule naming the CURRENT language ("English" / "العربية").
     D2  -- desktop "EN | ع" toggle enlarged to >=36 tall, >=40 wide per half.
     cart-heart -- a heart-only Wishlist tab, LAST (Cart · Browsed · ♥), with
            and without a count badge.

   The Arabic words shown on ENGLISH pages ("ع", "العربية") are inline SVG paths
   made by tools/hl2-glyphs.py from the shop's own Cairo 700, with the viewBox
   equal to the ink box. English pages do not load Cairo, so as text they fall
   back to whatever Arabic face the device has, and a pixel tweak tuned for one
   face is wrong in the next -- measured below ("portability"). The SVG is
   centred by construction on every device, needs no font request, and carries
   width/height attributes (header lock rule for inline <svg>).

   getBoundingClientRect/elementFromPoint are used here, in the HARNESS, to
   measure; nothing of it is shop JavaScript. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE || 'http://127.0.0.1:9540';
const OUT = path.resolve(__dirname, '../docs/lane-hl/round2');
fs.mkdirSync(OUT, { recursive: true });
const EXE = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

// tools/hl2-glyphs.py output, inlined (Cairo 700, viewBox = ink box, y down)
const GLYPHS = JSON.parse(fs.readFileSync(process.env.GLYPHS || path.resolve(__dirname, '../docs/lane-hl/round2/glyphs.json'), 'utf8'));
/* width/height may be given whole: preserveAspectRatio (xMidYMid meet) keeps the
   ink centred in the box. The box must differ from the 30px circle by an EVEN
   number (8x12, not 7x13): Chromium snaps a replaced element to whole pixels,
   and a 7px box centred in 30 starts at 11.5 and is drawn at 12 -- measured
   +0.5px on both axes before this. */
const svgWord = (txt, h, wFixed) => {
  const g = GLYPHS[txt]; const [, , vw, vh] = g.viewBox.split(' ').map(Number);
  const w = wFixed || Math.round((vw / vh) * h * 100) / 100;
  return `<svg class="hlp-svg" width="${w}" height="${h}" viewBox="${g.viewBox}" fill="currentColor" aria-hidden="true"><path d="${g.d}"/></svg>`;
};

const HEART_O = (s) => `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.2-9.4A5 5 0 0 1 12 6a5 5 0 0 1 9.2 5.1C19.5 15.9 12 20.5 12 20.5z"/></svg>`;
const HEART_F = (s) => `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.2-9.4A5 5 0 0 1 12 6a5 5 0 0 1 9.2 5.1C19.5 15.9 12 20.5 12 20.5z"/></svg>`;

/* The centring tweak for "EN" (Outfit, loaded on every page, so the number is
   the same on every device). Measured by this script's first pass and fed back
   here; the second pass proves it. Units: CSS px. */
const TWEAK = JSON.parse(process.env.TWEAK || '{"EN":[0,0],"English":[0,-0.04],"arText":[0.01,-0.058],"ع":[-0.004,-0.283]}');
// in em; tuned on the 10px capsule and the 15px/11px circle (H-wide at 12.5px is not tuned)
const tw = (k) => `.hlp .hlp-g[data-k="${k}"]{transform:translate(${TWEAK[k][0]}em,${TWEAK[k][1]}em)}`;

const CSS = `
.hlp-ribbon{position:fixed;inset-inline:0;bottom:0;z-index:2147483647;background:#1d1d1f;color:#fff;font:600 12px/1.2 system-ui,sans-serif;padding:7px 12px;text-align:center;letter-spacing:.02em;pointer-events:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hlp-ribbon b{color:#ffb3c7}
header .hlp .hlp-svg{width:auto;height:auto;display:block}
${tw('EN')}${tw('English')}${tw('arText')}${tw('ع')}
.hlp{display:none}
@media (min-width:901px){
  /* round-1 D, for the before numbers */
  .hlp-dD{display:flex;align-items:center;height:30px;margin-inline-start:8px;border:1px solid #e9dfe3;border-radius:999px;overflow:hidden;font-size:12px;font-weight:700;white-space:nowrap}
  .hlp-dD>*{display:flex;align-items:center;height:100%;padding:0 10px;color:inherit;text-decoration:none}
  .hlp-dD>span{background:var(--pink,#e0567b);color:#fff}
  /* D2: same pill, same colours; 36 tall, each half >=40 wide, glyph centred */
  .hlp-dD2{display:flex;align-items:stretch;height:36px;margin-inline-start:8px;box-shadow:inset 0 0 0 1px #e9dfe3;border-radius:999px;font-size:12.5px;font-weight:700;line-height:1;white-space:nowrap;flex:none}
  .hlp-dD2>*{display:flex;align-items:center;justify-content:center;min-width:40px;padding:0 10px;border-radius:999px;color:inherit;text-decoration:none}
  .hlp-dD2>span{background:var(--pink,#e0567b);color:#fff}
  .hlp-dD2>a:hover{background:#fbeff3}
  .hlp-dD2 .hlp-svg{display:block}
}
@media (max-width:900px){
  html.hlp-nowish header .hact a.ib[href*="my-wishlist"]{display:none}
  /* round-1 E exactly, for the before numbers */
  .hlp-mE{display:grid;place-items:center;width:44px;height:44px;color:inherit;text-decoration:none;flex:none}
  .hlp-mE>.hlp-c{display:grid;place-items:center;width:30px;height:30px;border:1.5px solid currentColor;border-radius:999px;font-style:normal;font-weight:700;font-size:14px;line-height:1}
  .hlp-mE[lang=en]>.hlp-c{font-size:11px;letter-spacing:.03em}
  /* E2: flex centring, line-height 1, the glyph in its own box, a measured tweak */
  .hlp-mE2{display:grid;place-items:center;color:inherit;text-decoration:none;flex:none}
  .hlp-mE2>.hlp-c{display:flex;align-items:center;justify-content:center;width:30px;height:30px;box-sizing:border-box;border:1.5px solid currentColor;border-radius:999px;font-weight:700;line-height:1}
  .hlp-mE2 .hlp-g{display:block;line-height:1}
  .hlp-mE2[lang=en] .hlp-g{font-size:11px}
  .hlp-mE2[lang=ar] .hlp-g{font-size:15px}
  .hlp-mE2 .hlp-svg{display:block}
  /* H: a capsule in the same slot. Layout box = the heart's own box (.ib), so
     the logo cannot move; the pill is centred in it and may overhang the empty
     10px either side of the neighbouring 24px glyphs. */
  .hlp-mH{display:grid;place-items:center;color:inherit;text-decoration:none;flex:none;overflow:visible}
  .hlp-mH>.hlp-p{display:flex;align-items:center;justify-content:center;height:26px;box-sizing:border-box;padding:0 5px;border:1.5px solid currentColor;border-radius:999px;font-weight:700;font-size:10px;line-height:1;white-space:nowrap}
  .hlp-mH .hlp-svg{display:block}
  .hlp-mH .hlp-g{display:block;line-height:1}
  /* H-wide: the capsule at a comfortable size, its OWN width -- the logo pays */
  .hlp-mHw{display:grid;place-items:center;width:auto!important;padding-inline:4px;color:inherit;text-decoration:none;flex:none}
  .hlp-mHw>.hlp-p{display:flex;align-items:center;justify-content:center;height:30px;box-sizing:border-box;padding:0 11px;border:1.5px solid currentColor;border-radius:999px;font-weight:700;font-size:12.5px;line-height:1;white-space:nowrap}
  .hlp-mHw .hlp-svg{display:block}
}
/* the cart panel: a heart-only tab, last */
.kc-tab.hlp-ht{flex:0 0 auto;min-width:52px;padding-inline:12px}
.kc-tab.hlp-ht svg{display:block}
.kc-tab.hlp-ht:not(.on){color:var(--muted)}
.hlp-kw .empty-d svg{display:block;margin:0 auto 10px;color:var(--pink,#e0567b)}
.hlp-kw .hlp-wrm{display:grid;place-items:center;width:34px;height:34px;border-radius:999px;border:1px solid #f1d5de;color:var(--pink,#e0567b);background:#fff;margin-inline-end:6px}
.hlp-kw .kc-right{display:flex;flex-direction:row;align-items:center;gap:0}
`;

/* ---------- candidates ---------- */
// li: {href, code (target lang), label (target name), here}
const circleGlyph = (li, svg) => li.code === 'ar'
  ? (svg ? svgWord('ع', 12, 8) : '<span class="hlp-g" data-k="ع">ع</span>')
  : '<span class="hlp-g" data-k="EN">EN</span>';
const word = (lang, h) => lang === 'ar' ? svgWord('العربية', h) : '<span class="hlp-g" data-k="English">English</span>';
const wordText = (lang) => lang === 'ar' ? '<span class="hlp-g" data-k="arText">العربية</span>' : '<span class="hlp-g" data-k="English">English</span>';
// Arabic text is real text on Arabic pages (Cairo is loaded there), an SVG on English ones
const capsuleWord = (lang, here, h) => (lang === 'ar' && here !== 'ar') ? word('ar', h) : wordText(lang);

const OPT = {
  E: { kind: 'mob', name: 'round-1 circle (before)', html: (li) => `<a class="ib hlp hlp-mE" data-hlp-sw href="${li.href}" lang="${li.code}" hreflang="${li.code}" aria-label="${li.label}"><span class="hlp-c">${li.code === 'ar' ? 'ع' : 'EN'}</span></a>` },
  E2t: { kind: 'mob', name: 'circle, centred text + tweak', html: (li) => `<a class="ib hlp hlp-mE2" data-hlp-sw href="${li.href}" lang="${li.code}" hreflang="${li.code}" aria-label="${li.label}"><span class="hlp-c">${circleGlyph(li, false)}</span></a>` },
  E2: { kind: 'mob', name: '"ع" / "EN" circle, letter centred', html: (li) => `<a class="ib hlp hlp-mE2" data-hlp-sw href="${li.href}" lang="${li.code}" hreflang="${li.code}" aria-label="${li.label}"><span class="hlp-c">${circleGlyph(li, true)}</span></a>` },
  H1: { kind: 'mob', name: 'capsule names the language you switch TO', html: (li) => `<a class="ib hlp hlp-mH" data-hlp-sw href="${li.href}" lang="${li.code}" hreflang="${li.code}" aria-label="${li.label}"><span class="hlp-p">${capsuleWord(li.code, li.here, 10)}</span></a>` },
  H2: { kind: 'mob', name: 'capsule names the CURRENT language', html: (li) => `<a class="ib hlp hlp-mH" data-hlp-sw href="${li.href}" lang="${li.code}" hreflang="${li.code}" aria-label="${li.label}"><span class="hlp-p" lang="${li.here}">${capsuleWord(li.here, li.here, 10)}</span></a>` },
  H1w: { kind: 'mob', name: 'capsule (switch TO) at its own width -- the logo pays', html: (li) => `<a class="ib hlp hlp-mHw" data-hlp-sw href="${li.href}" lang="${li.code}" hreflang="${li.code}" aria-label="${li.label}"><span class="hlp-p">${capsuleWord(li.code, li.here, 13.5)}</span></a>` },
  H2w: { kind: 'mob', name: 'capsule (CURRENT) at its own width -- the logo pays', html: (li) => `<a class="ib hlp hlp-mHw" data-hlp-sw href="${li.href}" lang="${li.code}" hreflang="${li.code}" aria-label="${li.label}"><span class="hlp-p" lang="${li.here}">${capsuleWord(li.here, li.here, 13.5)}</span></a>` },
  D: { kind: 'desk', name: 'round-1 toggle (before)', html: (li) => `<nav class="hlp hlp-dD" aria-label="Language">${pair(li, 'ع', false)}</nav>` },
  D2: { kind: 'desk', name: '"EN | ع" toggle after the search field, enlarged', html: (li) => `<nav class="hlp hlp-dD2" aria-label="${li.here === 'ar' ? 'اللغة' : 'Language'}">${pair(li, null, true)}</nav>` },
};
function pair(li, arTxt, svg) {
  return ['en', 'ar'].map((c) => {
    const t = c === 'en' ? 'EN' : (svg && li.here !== 'ar' ? svgWord('ع', 12, 8) : (arTxt || '<span class="hlp-g">ع</span>'));
    const lab = c === 'en' ? 'English' : 'العربية';
    return c === li.here ? `<span lang="${c}" aria-current="true" title="${lab}">${t}</span>` : `<a data-hlp-sw href="${li.href}" lang="${c}" hreflang="${c}" aria-label="${lab}">${t}</a>`;
  }).join('');
}

const WIDTHS = [320, 360, 390, 430, 768, 900, 1024, 1280];

async function measure(page) {
  return page.evaluate(() => {
    const bx = (e) => { if (!e) return null; const r = e.getBoundingClientRect(); return { x: Math.round(r.x * 100) / 100, y: Math.round(r.y * 100) / 100, w: Math.round(r.width * 100) / 100, h: Math.round(r.height * 100) / 100 }; };
    const q = (s) => document.querySelector(s);
    const swEl = q('.hlp [data-hlp-sw], a.hlp[data-hlp-sw]');
    const sws = [...document.querySelectorAll('.hlp-dD2>*, .hlp-dD>*')];
    const hlp = q('.hlp');
    const shown = hlp && getComputedStyle(hlp).display !== 'none';
    const pill = q('.hlp .hlp-p, .hlp .hlp-c');
    const bad = []; let n = 0;
    document.querySelectorAll('header a, header button, header summary').forEach((e) => {
      const r = e.getBoundingClientRect();
      if (r.width < 2 || r.height < 2 || getComputedStyle(e).visibility === 'hidden') return;
      if (r.bottom <= 0 || r.top >= innerHeight || r.right <= 0 || r.left >= innerWidth) return;
      if (e.closest('.sugg, [hidden]')) return;
      n++;
      const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      if (!hit || !(hit === e || e.contains(hit))) bad.push((e.getAttribute('aria-label') || e.textContent.trim()).slice(0, 30) + ' <- ' + (hit ? hit.tagName + '.' + hit.className : 'null'));
    });
    // the pill's overhang: which part of it answers a tap
    let overhang = null;
    if (shown && pill && pill.classList.contains('hlp-p')) {
      const r = pill.getBoundingClientRect(), a = pill.closest('a').getBoundingClientRect();
      const probe = (x) => { const h = document.elementFromPoint(x, r.top + r.height / 2); return h && h.closest('a') === pill.closest('a'); };
      let own = 0, tot = 0;
      for (let x = Math.ceil(r.left) + 0.5; x < r.right; x += 1) { tot++; if (probe(x)) own++; }
      overhang = { pill: `${Math.round(r.width * 10) / 10}x${Math.round(r.height * 10) / 10}`, anchor: `${Math.round(a.width)}x${Math.round(a.height)}`, pillPxAnsweringItsOwnLink: `${own}/${tot}` };
    }
    // the neighbouring icons' glyphs: clearance to the pill
    let clearance = null;
    if (shown && pill) {
      const r = pill.getBoundingClientRect();
      const gl = [...document.querySelectorAll('header .hact .ib:not(.hlp) svg')].filter((s) => s.getBoundingClientRect().width > 0).map((s) => s.getBoundingClientRect());
      clearance = Math.round(Math.min(...gl.map((g) => g.right <= r.left ? r.left - g.right : g.left >= r.right ? g.left - r.right : -1)) * 10) / 10;
    }
    return { logo: bx(q('header .logo')), icon: bx(q('header .logo .lgx-i')), sbox: bx(q('header .sbox')), hin: bx(q('header .hin')),
      sw: shown && swEl ? bx(swEl) : null, halves: shown ? sws.map(bx) : [], pill: shown && pill ? bx(pill) : null, overhang, glyphClearance: clearance,
      scrollW: document.documentElement.scrollWidth, vw: innerWidth, clickable: n, notClickable: bad };
  });
}

async function inject(page, html, where, nowish, label) {
  await page.addStyleTag({ content: CSS });
  await page.evaluate(({ html, where, nowish, label }) => {
    if (nowish) document.documentElement.classList.add('hlp-nowish');
    const tmp = document.createElement('div'); tmp.innerHTML = html; const el = tmp.firstElementChild;
    if (where === 'after-sbox') document.querySelector('header .sbox').after(el);
    else document.querySelector('header .hact a.ib[href*="my-wishlist"]').before(el);
    const rb = document.createElement('div'); rb.className = 'hlp-ribbon'; rb.innerHTML = label; document.body.append(rb);
  }, { html, where, nowish, label });
  await page.evaluate(() => document.fonts.ready);
}
async function langInfo(page) {
  return page.evaluate(() => {
    const a = document.querySelector('.kbb-lang a');
    return { href: a.getAttribute('href'), code: a.getAttribute('lang'), label: a.textContent.trim(), here: document.documentElement.lang.startsWith('ar') ? 'ar' : 'en' };
  });
}

/* Ink box of the glyph(s) inside `sel`, vs the centre of `sel`'s border box.
   The ring is made transparent for the shot (its width stays, so nothing
   moves), the shot is decoded on a canvas, and a pixel counts as ink when it is
   darker than the background by half the text/background contrast. */
async function inkOffset(page, sel, decoder) {
  const box = await page.evaluate((sel) => {
    const e = document.querySelector(sel); e.dataset.hlpOldBc = e.style.borderColor; e.style.borderColor = 'transparent';
    const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height };
  }, sel);
  const dpr = await page.evaluate(() => devicePixelRatio);
  const cx = Math.floor(box.x), cy = Math.floor(box.y), cw = Math.ceil(box.x + box.w) - cx, ch = Math.ceil(box.y + box.h) - cy;
  const png = await page.screenshot({ clip: { x: cx, y: cy, width: cw, height: ch } });
  await page.evaluate((sel) => { const e = document.querySelector(sel); e.style.borderColor = e.dataset.hlpOldBc; }, sel);
  const ink = await decoder.evaluate(async (b64) => {
    const img = await createImageBitmap(await (await fetch('data:image/png;base64,' + b64)).blob());
    const c = new OffscreenCanvas(img.width, img.height); const g = c.getContext('2d'); g.drawImage(img, 0, 0);
    const d = g.getImageData(0, 0, img.width, img.height).data;
    const lum = (i) => 0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2];
    // background = brightest of the four corners; ink = the darkest pixel; threshold halfway
    const corners = [0, (img.width - 1) * 4, (img.height - 1) * img.width * 4, (img.height * img.width - 1) * 4].map(lum);
    const bg = Math.max(...corners); let mn = 255;
    for (let i = 0; i < d.length; i += 4) mn = Math.min(mn, lum(i));
    const th = (bg + mn) / 2; let x0 = 1e9, y0 = 1e9, x1 = -1, y1 = -1;
    for (let y = 0; y < img.height; y++) for (let x = 0; x < img.width; x++) if (lum((y * img.width + x) * 4) < th) { x0 = Math.min(x0, x); y0 = Math.min(y0, y); x1 = Math.max(x1, x); y1 = Math.max(y1, y); }
    return { x0, y0, x1, y1, W: img.width, H: img.height };
  }, png.toString('base64'));
  const inkCx = cx + (ink.x0 + ink.x1 + 1) / 2 / dpr, inkCy = cy + (ink.y0 + ink.y1 + 1) / 2 / dpr;
  const r2 = (v) => Math.round(v * 100) / 100;
  return { dx: r2(inkCx - (box.x + box.w / 2)), dy: r2(inkCy - (box.y + box.h / 2)), ink: `${r2((ink.x1 - ink.x0 + 1) / dpr)}x${r2((ink.y1 - ink.y0 + 1) / dpr)}`, dpr };
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const decoderCtx = await browser.newContext(); const decoder = await decoderCtx.newPage();
  const R = { tweakUsed: TWEAK, lock: {}, centring: {}, portability: {}, clicks: {}, cart: {} };
  const open = async (w, p, dpr) => {
    const ctx = await browser.newContext({ viewport: { width: w, height: w > 900 ? 720 : 760 }, deviceScaleFactor: dpr || (w > 900 ? 1 : 2), isMobile: w <= 430, hasTouch: w <= 430 });
    const page = await ctx.newPage(); const errs = [];
    page.on('pageerror', (e) => errs.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errs.push(m.text()); });
    await page.goto(BASE + p, { waitUntil: 'networkidle' });
    return { ctx, page, errs };
  };
  const PAGES = ['/', '/ar/'];
  const only = (process.env.ONLY || 'centring,lock,shots,clicks,cart').split(',');

  // 1. centring: before (round-1 E), E2 text+tweak, E2 (svg on EN pages), and the capsules' text
  if (only.includes('centring')) {
    for (const p of PAGES) for (const w of [360, 390, 430]) for (const dpr of [2, 3, 4]) for (const k of ['E', 'E2t', 'E2', 'H1', 'H2']) {
      const o = await open(w, p, dpr); const li = await langInfo(o.page);
      await inject(o.page, OPT[k].html(li), 'wish-slot', true, 'x');
      R.centring[`${k}|${w}|${p}|dpr${dpr}`] = await inkOffset(o.page, k.startsWith('E') ? '.hlp .hlp-c' : '.hlp .hlp-p', decoder);
      await o.ctx.close();
    }
    // portability of a TEXT tweak for "ع" on an English page: the same CSS in other Arabic faces
    const cairo = fs.readdirSync(path.resolve(__dirname, '../public/build/assets')).find((f) => /^cairo-arabic-.*\.woff2$/.test(f));
    for (const face of ['(device fallback here: DejaVu Sans)', 'Cairo', 'FreeSerif']) {
      const o = await open(390, '/', 4); const li = await langInfo(o.page);
      await inject(o.page, OPT.E2t.html(li), 'wish-slot', true, 'x');
      if (face === 'Cairo') await o.page.addStyleTag({ content: `@font-face{font-family:HlpCairo;src:url(/build/assets/${cairo}) format("woff2");font-weight:200 1000}.hlp-mE2 .hlp-g{font-family:HlpCairo!important}` });
      if (face === 'FreeSerif') await o.page.addStyleTag({ content: '.hlp-mE2 .hlp-g{font-family:FreeSerif!important}' });
      await o.page.evaluate(() => document.fonts.ready); await o.page.waitForTimeout(200);
      R.portability[`E2t ع text, ${face}`] = await inkOffset(o.page, '.hlp .hlp-c', decoder);
      await o.ctx.close();
    }
  }

  // 2. the lock at every width, before vs after, both languages; and the shots
  if (only.includes('lock')) {
    for (const p of PAGES) for (const w of WIDTHS) {
      const base = await open(w, p); const before = await measure(base.page); await base.ctx.close();
      for (const k of ['D', 'D2', 'E2', 'H1', 'H2', 'H1w', 'H2w']) {
        const opt = OPT[k]; const o = await open(w, p); const li = await langInfo(o.page);
        await inject(o.page, opt.html(li), opt.kind === 'desk' ? 'after-sbox' : 'wish-slot', opt.kind === 'mob', `PREVIEW ONLY &middot; Option <b>${k}</b> &middot; ${opt.name}`);
        const a = await measure(o.page); const same = (x, y) => JSON.stringify(x) === JSON.stringify(y);
        R.lock[`${k}|${w}|${p}`] = {
          logoSame: same(before.logo, a.logo) && same(before.icon, a.icon), logo: a.logo && `${a.logo.w}x${a.logo.h}`, logoBefore: before.logo && `${before.logo.w}x${before.logo.h}`,
          lotus: a.icon && `${a.icon.w}x${a.icon.h}`, lotusBefore: before.icon && `${before.icon.w}x${before.icon.h}`,
          searchRowSame: !!(before.sbox && a.sbox && before.sbox.y === a.sbox.y && before.sbox.h === a.sbox.h), sboxW: `${before.sbox?.w}->${a.sbox?.w}`,
          hinH: `${before.hin.h}->${a.hin.h}`, scrollOk: a.scrollW === a.vw, sw: a.sw, halves: a.halves.map((h) => `${h.w}x${h.h}`), pill: a.pill && `${a.pill.w}x${a.pill.h}`,
          overhang: a.overhang, glyphClearance: a.glyphClearance, clickable: a.clickable, notClickable: a.notClickable, errors: o.errs,
        };
        const sfx = p === '/ar/' ? '-ar' : '';
        const shotMob = opt.kind === 'mob' && ((k === 'E2' && [360, 390, 430].includes(w)) || (/^H[12]w?$/.test(k) && [320, 360, 390, 430].includes(w)));
        const shotDesk = k === 'D2' && [1024, 1280].includes(w);
        if (only.includes('shots') && (shotMob || shotDesk)) {
          await o.page.evaluate((t) => { const r = document.querySelector('.hlp-ribbon'); r.style.bottom = 'auto'; r.style.top = t + 'px'; }, shotDesk ? 232 : 272);
          const name = k.endsWith('w') ? `${k.slice(0, 2)}-${w}-wide` : `${k}-${w}`;
          await o.page.screenshot({ path: `${OUT}/${name}${sfx}.png`, clip: { x: 0, y: 0, width: w, height: shotDesk ? 260 : 300 } });
        }
        await o.ctx.close();
      }
    }
  }

  // 3. zoom crops: before | after at 4x, with the circle's centre lines
  if (only.includes('shots')) for (const p of PAGES) {
    const sfx = p === '/ar/' ? '-ar' : '';
    const crops = [];
    for (const k of ['E', 'E2']) {
      const o = await open(390, p, 4); const li = await langInfo(o.page);
      await inject(o.page, OPT[k].html(li), 'wish-slot', true, 'x');
      const off = await inkOffset(o.page, '.hlp .hlp-c', decoder);
      await o.page.evaluate(() => {
        const c = document.querySelector('.hlp .hlp-c'); const r = c.getBoundingClientRect();
        for (const [l, t, w, h] of [[r.left + r.width / 2 - 0.125, r.top - 4, 0.25, r.height + 8], [r.left - 4, r.top + r.height / 2 - 0.125, r.width + 8, 0.25]]) {
          const d = document.createElement('div'); d.style.cssText = `position:fixed;left:${l}px;top:${t}px;width:${w}px;height:${h}px;background:#16a34a;z-index:99999;pointer-events:none`; document.body.append(d);
        }
      });
      const r = await o.page.evaluate(() => { const r = document.querySelector('.hlp .hlp-c').getBoundingClientRect(); return { x: r.x, y: r.y }; });
      crops.push({ k, off, b64: (await o.page.screenshot({ clip: { x: Math.floor(r.x) - 7, y: Math.floor(r.y) - 7, width: 44, height: 44 } })).toString('base64') });
      await o.ctx.close();
    }
    const z = await browser.newContext({ viewport: { width: 760, height: 470 }, deviceScaleFactor: 1 }); const zp = await z.newPage();
    await zp.setContent(`<body style="margin:0;font:600 15px system-ui;background:#fff;color:#1d1d1f">
      <div style="display:flex;gap:24px;padding:16px">${crops.map((c) => `<figure style="margin:0"><img src="data:image/png;base64,${c.b64}" width="352" height="352" style="image-rendering:pixelated;border:1px solid #ddd;display:block"><figcaption style="padding-top:8px">${c.k === 'E' ? 'Before (round 1)' : 'After (E2)'} &middot; ink centre off by x ${c.off.dx}, y ${c.off.dy} px</figcaption></figure>`).join('')}</div>
      <div style="position:fixed;inset-inline:0;bottom:0;background:#1d1d1f;color:#fff;font:600 12px system-ui;padding:7px 12px;text-align:center">PREVIEW ONLY &middot; <b style="color:#ffb3c7">E2</b> &middot; 4x zoom at 390 (DPR 4), green lines = circle centre &middot; ${p === '/ar/' ? 'Arabic page ("EN")' : 'English page ("ع")'}</div></body>`);
    await zp.screenshot({ path: `${OUT}/E2-zoom${sfx}.png` }); await z.close();
  }

  // 4. real clicks
  if (only.includes('clicks')) for (const p of PAGES) for (const k of ['E2', 'H1', 'H2', 'D2']) {
    const w = OPT[k].kind === 'desk' ? 1280 : 390; const o = await open(w, p); const li = await langInfo(o.page);
    await inject(o.page, OPT[k].html(li), OPT[k].kind === 'desk' ? 'after-sbox' : 'wish-slot', OPT[k].kind === 'mob', 'x');
    await Promise.all([o.page.waitForURL((u) => u.pathname !== p, { timeout: 15000 }), o.page.click('.hlp [data-hlp-sw], a.hlp[data-hlp-sw]')]);
    R.clicks[`${k}${p === '/ar/' ? '-ar' : ''}`] = `${p} -> ${new URL(o.page.url()).pathname}`; await o.ctx.close();
  }

  // 5. the cart panel at 390: Cart · Browsed · ♥ (heart last), badge / no badge
  if (only.includes('cart')) {
    const products = (await (await fetch(BASE + '/')).text()).match(/\/product\/[a-z0-9-]+\//g).filter((v, i, a) => a.indexOf(v) === i).slice(0, 4);
    for (const p of PAGES) {
      const sfx = p === '/ar/' ? '-ar' : ''; const ar = p === '/ar/';
      const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
      const page = await ctx.newPage();
      for (const pr of products) await page.goto(BASE + (ar ? '/ar' : '') + pr, { waitUntil: 'networkidle' });
      await page.goto(BASE + p, { waitUntil: 'networkidle' });
      await page.evaluate(async () => {
        await new Promise((r) => setTimeout(r, 2500));
        const id = Number(document.querySelector('[data-kbb-add]')?.dataset.kbbAdd || document.querySelector('[data-add]')?.dataset.add || 1);
        await fetch(`${window.KBB.routes.cartApi}/add`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json', 'X-KBB-Hm': String(Math.round(performance.now())) }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
        if (window.kbbRefreshCart) await window.kbbRefreshCart();
      });
      await page.addStyleTag({ content: CSS });
      await page.click('header [data-kbb-cart]'); await page.waitForTimeout(900);
      for (const badge of ['badge', 'nobadge']) for (const state of ['empty', 'filled']) {
        await page.evaluate(({ badge, state, ar, HO, HF, HS }) => {
          document.querySelectorAll('.hlp-ht,#hlpKw,.hlp-ribbon').forEach((e) => e.remove());
          const tabs = document.querySelector('.kc-tabs'); const bTab = tabs.querySelector('[data-kctab=browsed]');
          const browsed = document.getElementById('kcBrowsed'); const rows = [...browsed.querySelectorAll('.kc-item')].slice(0, 3);
          const n = state === 'filled' ? rows.length : 0;
          const t = document.createElement('button'); t.type = 'button'; t.className = 'kc-tab on hlp-ht';
          t.setAttribute('aria-label', (ar ? 'المفضلة' : 'Wishlist') + (n ? ` (${n})` : ''));
          // heart outline when the list is empty, filled when it holds something
          t.innerHTML = (n ? HF : HO) + (badge === 'badge' ? ` <span class="n">${n}</span>` : '');
          tabs.querySelectorAll('.kc-tab').forEach((x) => x.classList.remove('on'));
          bTab.after(t);
          const pane = document.createElement('div'); pane.id = 'hlpKw'; pane.className = 'hlp-kw';
          if (n) {
            const body = document.createElement('div'); body.className = 'dbody';
            for (const r of rows) { const c = r.cloneNode(true); const rm = document.createElement('span'); rm.className = 'hlp-wrm'; rm.innerHTML = HS; c.querySelector('.kc-right').prepend(rm); body.append(c); }
            pane.append(body);
          } else pane.innerHTML = `<div class="dbody"><div class="empty-d" style="padding:30px 20px"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 20.5s-7.5-4.6-9.2-9.4A5 5 0 0 1 12 6a5 5 0 0 1 9.2 5.1C19.5 15.9 12 20.5 12 20.5z"/></svg>${ar ? 'قائمة المفضلة فارغة' : 'Your wishlist is empty'}<br>${ar ? 'اضغطي ♥ على أي منتج لحفظه هنا.' : 'Tap ♥ on any product to save it here.'}</div></div>`;
          document.getElementById('kcCart').style.display = 'none'; browsed.style.display = 'none'; browsed.after(pane);
          const rb = document.createElement('div'); rb.className = 'hlp-ribbon';
          rb.innerHTML = `PREVIEW ONLY &middot; Cart panel &middot; heart tab last, <b>${badge === 'badge' ? 'with count' : 'no count'}</b> &middot; ${state}`;
          document.body.append(rb);
        }, { badge, state, ar, HO: HEART_O(18), HF: HEART_F(18), HS: HEART_F(14) });
        R.cart[`${badge}-${state}${sfx}`] = await page.evaluate(() => {
          const t = [...document.querySelectorAll('.kc-tabs .kc-tab')].map((e) => { const r = e.getBoundingClientRect(); const h = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return `${(e.getAttribute('aria-label') || e.textContent.trim().replace(/\s+/g, ' '))}@x${Math.round(r.x)}:${Math.round(r.width)}x${Math.round(r.height)}${h === e || e.contains(h) ? '' : ' NOT-HIT'}`; });
          const x = document.querySelector('.kc-tabs .kc-x').getBoundingClientRect();
          return { tabs: t, close: `${Math.round(x.width)}x${Math.round(x.height)}`, scrollW: document.documentElement.scrollWidth };
        });
        await page.screenshot({ path: `${OUT}/cart-heart-${badge}-${state}-390${sfx}.png` });
      }
      await ctx.close();
    }
  }
  const f = `${OUT}/measurements.json`;
  const prev = fs.existsSync(f) ? JSON.parse(fs.readFileSync(f, 'utf8')) : {};
  for (const k of Object.keys(R)) if (k === 'tweakUsed' || Object.keys(R[k]).length) prev[k] = R[k];
  fs.writeFileSync(f, JSON.stringify(prev, null, 1));
  await browser.close(); console.log('done');
})();
