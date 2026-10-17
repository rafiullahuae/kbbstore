/* Lane HL3 -- the header language switch, round 3, as a LIVE preview page.
   PREVIEW ONLY: nothing here ships. It renders the real home page (English and
   Arabic) from a preview server, adds the candidate switch, measures it against
   the header lock, and freezes each page into one self-contained HTML file
   (styles, fonts and small images inlined, scripts removed) that
   docs/lane-hl/round3/live.html shows in an iframe, so the owner can open it on
   his phone and his computer.

   The owner's round-3 brief (17 October 2026):
   - phone: the "switch TO" capsule (H1), thin -- the same height as the user
     and cart icons -- with the user and cart icons side by side and a little
     space between them and the switch;
   - computer: the round-1 "EN | ع" half-and-half pill he chose (D), a little
     wider overall, not the round-2 circles.

   Usage: BASE=http://127.0.0.1:9461 node tools/hl3-live.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE || 'http://127.0.0.1:9461';
const OUT = path.resolve(__dirname, '../docs/lane-hl/round3');
fs.mkdirSync(OUT, { recursive: true });
const GLYPHS = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../docs/lane-hl/round2/glyphs.json'), 'utf8'));
const svgWord = (txt, h) => {
  const g = GLYPHS[txt]; const [, , vw, vh] = g.viewBox.split(' ').map(Number);
  return `<svg class="hl3-svg" width="${Math.round((vw / vh) * h * 100) / 100}" height="${h}" viewBox="${g.viewBox}" fill="currentColor" aria-hidden="true"><path d="${g.d}"/></svg>`;
};

const CSS = `
.hl3{display:none}
@media (min-width:901px){
  /* D, the half-and-half pill he chose in round 1, a little wider. */
  .hl3-d{display:flex;align-items:center;height:30px;margin-inline-start:8px;border:1px solid #e9dfe3;border-radius:999px;overflow:hidden;font-size:12px;font-weight:700;white-space:nowrap;flex:none}
  .hl3-d>*{display:flex;align-items:center;justify-content:center;height:100%;min-width:44px;padding:0 14px;color:inherit;text-decoration:none}
  .hl3-d>span{background:var(--pink,#e0567b);color:#fff}
  .hl3-d>a:hover{background:#fbeff3}
  .hl3-d .hl3-svg{display:block;width:auto;height:13px}
}
@media (max-width:900px){
  header .hact a.ib[href*="my-wishlist"],header .hact .hl3-wish{display:none}
  /* the thin capsule: as tall as the user and cart glyphs (21px), set apart */
  /* the switch IS an icon box (.ib), so it takes the heart's exact size at every width and the logo cannot move */
  .hl3-m.ib{display:grid;place-items:center;overflow:visible;color:inherit;text-decoration:none}
  /* user and cart 8px closer; the 8px goes between the logo and the switch, so the row's width -- and the logo -- stay the same */
  .hl3-m.ib{margin-inline-start:8px}
  header .hact .ib-acct{margin-inline-end:-8px}
  .hl3-m>span{display:flex;align-items:center;justify-content:center;height:21px;box-sizing:border-box;padding:0 6px;border:1.5px solid currentColor;border-radius:999px;font-weight:700;font-size:10px;line-height:1;white-space:nowrap}
  .hl3-m .hl3-svg{display:block;width:auto;height:9px}
}`;

async function prepare(p, here) {
  const there = here === 'en' ? 'ar' : 'en';
  const href = here === 'en' ? '/ar/' : '/';
  await p.addStyleTag({ content: CSS });
  await p.evaluate(({ here, there, href, arWord, arGlyph }) => {
    const pair = ['en', 'ar'].map((c) => {
      const t = c === 'en' ? 'EN' : (here === 'ar' ? '<b style="font-weight:700">ع</b>' : arGlyph);
      return c === here ? `<span lang="${c}" aria-current="true">${t}</span>` : `<a data-go="${c}" href="${href}" lang="${c}" hreflang="${c}" aria-label="${c === 'en' ? 'English' : 'العربية'}">${t}</a>`;
    }).join('');
    const d = document.createElement('nav'); d.className = 'hl3 hl3-d'; d.setAttribute('aria-label', here === 'ar' ? 'اللغة' : 'Language'); d.innerHTML = pair;
    document.querySelector('header .sbox').after(d);
    const m = document.createElement('a'); m.className = 'ib hl3 hl3-m'; m.href = href; m.dataset.go = there; m.lang = there; m.hreflang = there;
    m.setAttribute('aria-label', there === 'ar' ? 'العربية' : 'English');
    m.innerHTML = `<span>${there === 'ar' ? arWord : 'English'}</span>`;
    const hact = document.querySelector('header .hact'); hact.insertBefore(m, hact.firstElementChild);
    // Arabic: the three-lines menu icon drawn from the right (the short middle
    // line ends on the inline-start side), as its own path -- no transform.
    if (here === 'ar') { const ln = document.querySelector('#burger svg.ln path'); if (ln && ln.getAttribute('d') === 'M3.5 6.5h17M3.5 12h11M3.5 17.5h17') ln.setAttribute('d', 'M20.5 6.5h-17M20.5 12h-11M20.5 17.5h-17'); }
  }, { here, there, href, arWord: svgWord('العربية', 9), arGlyph: svgWord('ع', 13) });
}

async function measure(b, here, w, withSwitch) {
  const p = await b.newPage({ viewport: { width: w, height: 800 } });
  await p.goto(BASE + (here === 'ar' ? '/ar/' : '/'), { waitUntil: 'networkidle' });
  if (withSwitch) await prepare(p, here);
  await p.waitForTimeout(150);
  const r = await p.evaluate(() => {
    const box = (s) => { const e = document.querySelector(s); if (!e) return null; const b = e.getBoundingClientRect(); return [Math.round(b.left * 10) / 10, Math.round(b.top * 10) / 10, Math.round(b.width * 10) / 10, Math.round(b.height * 10) / 10]; };
    const vis = (s) => { const e = document.querySelector(s); return e && getComputedStyle(e).display !== 'none' ? e : null; };
    const sw = vis('.hl3-m') || vis('.hl3-d');
    let hit = null, tap = null, pill = null;
    if (sw) {
      const a = sw.matches('a') ? sw : sw.querySelector('a'); const b = a.getBoundingClientRect(); tap = [Math.round(b.width), Math.round(b.height)];
      const e = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2); hit = !!(e && e.closest('[data-go]'));
      const ps = sw.matches('.hl3-m') ? sw.firstElementChild.getBoundingClientRect() : sw.getBoundingClientRect(); pill = [Math.round(ps.width * 10) / 10, Math.round(ps.height * 10) / 10];
    }
    const icons = [...document.querySelectorAll('header .hact > *')].filter((e) => getComputedStyle(e).display !== 'none' && !e.classList.contains('hl3')).map((e) => { const s = (e.querySelector('svg') || e).getBoundingClientRect(); return [Math.round(s.left), Math.round(s.width)]; });
    const hitIcon = (s) => { const e = document.querySelector(s); if (!e) return null; const b = e.getBoundingClientRect(); const h = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2); return !!(h && e.contains(h)); };
    const logoToSwitch = sw && sw.matches('.hl3-m') ? Math.round(Math.abs((document.documentElement.dir === 'rtl' ? document.querySelector('header .logo').getBoundingClientRect().left - sw.firstElementChild.getBoundingClientRect().right : sw.firstElementChild.getBoundingClientRect().left - document.querySelector('header .logo').getBoundingClientRect().right)) * 10) / 10 : null;
    return { acctHit: hitIcon('header .hact .ib-acct'), cartHit: hitIcon('header .hact a[href*="cart"]'), logoToSwitch, logo: box('header .logo'), lotus: box('header .logo .lgx-i'), search: box('header .sbox'), pill, tap, hit, icons, sw: document.documentElement.scrollWidth };
  });
  await p.close();
  return r;
}

async function freeze(b, here) {
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto(BASE + (here === 'ar' ? '/ar/' : '/'), { waitUntil: 'networkidle' });
  await prepare(p, here);
  const { html, fonts } = await p.evaluate(async () => {
    // The sheets' own text, in order: re-serialising cssRules drops any shorthand
    // that carries var() (Chrome writes "background-image: ;"), which blanked the
    // footer's pink strip in the first build of this preview.
    let css = '';
    for (const sh of document.styleSheets) {
      if (sh.href) {
        const t = await (await fetch(sh.href)).text();
        css += t.replace(/url\((['"]?)(?!data:|https?:|\/)([^'")]+)\1\)/g, (m, q, u) => 'url(' + new URL(u, sh.href).pathname + ')') + '\n';
      } else if (sh.ownerNode) css += sh.ownerNode.textContent + '\n';
    }
    // Only the header, the strips under it and the footer: the rest is not under review.
    const main = document.querySelector('main') || document.querySelector('#main');
    if (main) main.innerHTML = '<div style="padding:60px 16px;text-align:center;color:#8C828A;font:600 14px/1.5 Outfit,system-ui,sans-serif">The page content is left out of this preview. Only the header and the footer are being reviewed.</div>';
    document.querySelectorAll('script,noscript,link[rel=stylesheet],link[rel=preload],link[rel=modulepreload],link[rel=prefetch],style,iframe,[class*="kwa"],[class*="wa-float"],[class*="wa-bub"],.kbb-wa,[class^="kbw"],#kbbWaB').forEach((e) => e.remove());
    for (const img of document.querySelectorAll('img')) {
      img.removeAttribute('srcset'); img.removeAttribute('sizes'); img.loading = 'eager';
      try { const r = await fetch(img.currentSrc || img.src); const bl = await r.blob(); if (bl.size < 60000) { img.src = await new Promise((ok) => { const f = new FileReader(); f.onload = () => ok(f.result); f.readAsDataURL(bl); }); } else img.remove(); } catch (e) { img.remove(); }
    }
    // Mark the wishlist heart BEFORE the addresses go: the phone rule finds it by its address.
    document.querySelectorAll('header .hact a[href*="my-wishlist"]').forEach((a) => a.classList.add('hl3-wish'));
    document.querySelectorAll('a[href]').forEach((a) => { if (!a.dataset.go) a.setAttribute('href', '#'); });
    document.querySelectorAll('.kbb-lang a').forEach((a) => { a.dataset.go = a.getAttribute('lang'); });
    const fonts = [...css.matchAll(/url\(["']?(\/build\/[^"')]+\.woff2)["']?\)/g)].map((m) => m[1]);
    const st = document.createElement('style'); st.textContent = css; document.head.prepend(st);
    return { html: '<!doctype html>' + document.documentElement.outerHTML, fonts: [...new Set(fonts)] };
  });
  let out = html;
  for (const f of fonts) {
    const buf = Buffer.from(await (await fetch(BASE + f)).arrayBuffer());
    out = out.split(f).join('data:font/woff2;base64,' + buf.toString('base64'));
  }
  // Inside the preview a switch click asks the page around it to show the other language.
  out = out.replace('</body>', `<script>document.addEventListener('click',function(e){var a=e.target.closest('a');if(!a)return;e.preventDefault();if(a.dataset.go)parent.postMessage({hl3go:a.dataset.go},'*');});</script></body>`);
  await p.close();
  return out;
}

(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium' });
  const rows = [];
  for (const here of ['en', 'ar']) for (const w of [320, 360, 390, 430, 768, 900, 1024, 1280]) {
    rows.push({ here, w, before: await measure(b, here, w, false), after: await measure(b, here, w, true) });
  }
  fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(rows, null, 1));
  for (const here of ['en', 'ar']) fs.writeFileSync(path.join(OUT, `page-${here}.html`), await freeze(b, here));
  await b.close();
  for (const r of rows) {
    const s = (x) => x ? x.join('x') : '-';
    console.log(r.here, r.w, 'logo', s(r.before.logo?.slice(2)), '->', s(r.after.logo?.slice(2)), '| lotus', s(r.before.lotus?.slice(2)), '->', s(r.after.lotus?.slice(2)), '| search w', r.before.search?.[2], '->', r.after.search?.[2], 'top', r.before.search?.[1], '->', r.after.search?.[1], '| pill', s(r.after.pill), 'tap', s(r.after.tap), 'hit', r.after.hit, 'acct/cart hit', r.after.acctHit, r.after.cartHit, 'logo->switch', r.before.logoToSwitch, r.after.logoToSwitch, '| sw', r.after.sw, '| icons', JSON.stringify(r.after.icons));
  }
})();
