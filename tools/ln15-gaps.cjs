/* Lane LN15: the space under every heading in a product's Description tab, measured in Chromium.

   A saved product page (fetched from the live shop) is served back to the browser at its own
   address, with none of its scripts loaded and no picture fetched; the stylesheets and fonts are served from
   a build directory (the live shop's hashes are the repo's build, so the "before" is a copy of it). Optionally the Description body is swapped
   for another one (the render-time output of this lane's code, written by tools/ln15-after.php).

   ITEMS=list.json  [{ "slug": "...", "loc": "en", "page": "pages/en-<slug>.html", "body": "after/en-<slug>.html"? }]
   CSSDIR=public/build/assets   where /build/assets/* is served from (default: this build)
   SHOTS=dir PREFIX=before      also photograph the opened Description tab
   OUT=gaps.json node tools/ln15-gaps.cjs

   A "heading line" is a visual line whose every letter is bold (font-weight >= 600) or in an
   h2-h6, at most 80 characters (a heading that wraps counts once, from its last line); a bold
   label inside a bullet and the old shop's designed blocks (.kbb-eblock) are not headings. Its gap is the distance from the bottom of that line's text
   box to the top of the next line's text box. Inside one paragraph that distance is the
   line spacing's leftover (about 0-1px); everything above that is space added under the
   heading. Prints one row per page and width; writes every gap to OUT. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const ITEMS = JSON.parse(fs.readFileSync(process.env.ITEMS, 'utf8'));
const CSSDIR = path.resolve(process.env.CSSDIR || 'public/build/assets');
const SHOTS = process.env.SHOTS ? path.resolve(process.env.SHOTS) : null;
const PREFIX = process.env.PREFIX || 'before';
const WIDTHS = (process.env.WIDTHS || '390,1280').split(',').map(Number);
const OUT = process.env.OUT || 'gaps.json';
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });

function swapBody(html, body) {
  // the Description tab is panel 0 on the laptop and accordion item 0 on the phone
  const a = /(<div class="dtabpanel[^"]*" data-panel="0">\s*<div class="dcontent clamp">)([\s\S]*?)(<\/div>\s*<button class="readmore")/;
  const m = html.match(a);
  if (!m) throw new Error('no description panel');
  // the phone's accordion prints the same body: swap every copy of it, exactly
  return html.split(m[2]).join(body);
}

(async () => {
  const opts = { executablePath: '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome' };
  const browser = await chromium.launch(opts);
  const all = [];
  for (const it of ITEMS) for (const w of WIDTHS) {
    let html = fs.readFileSync(it.page, 'utf8');
    if (it.body) html = swapBody(html, fs.readFileSync(it.body, 'utf8'));
    const url = 'https://kbeautybliss.com' + (it.loc === 'ar' ? '/ar' : '') + '/product/' + it.slug + '/';
    const ctx = await browser.newContext({ viewport: { width: w, height: 1600 }, ignoreHTTPSErrors: true, javaScriptEnabled: true });
    const page = await ctx.newPage();
    await page.route('**/*', async (route) => {
      const r = route.request(), u = r.url(), t = r.resourceType();
      if (u === url) return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
      if (t === 'image' || t === 'script' || t === 'media' || r.method() !== 'GET') return route.abort();
      // every stylesheet and font the page uses is in the build; nothing goes to the network
      if (u.includes('/build/assets/')) {
        let f = path.join(CSSDIR, path.basename(new URL(u).pathname));
        // the live page names the live build's hash; a rebuilt stylesheet has a new one
        if (!fs.existsSync(f)) {
          const b = path.basename(f), stem = b.slice(0, b.lastIndexOf('-') + 1), ext = path.extname(b);
          const alt = fs.readdirSync(CSSDIR).find((x) => x.startsWith(stem) && x.endsWith(ext) && !x.slice(stem.length).includes('-'));
          if (alt) f = path.join(CSSDIR, alt);
        }
        if (fs.existsSync(f))return route.fulfill({ status: 200, contentType: f.endsWith('.css') ? 'text/css' : 'font/woff2', body: fs.readFileSync(f) });
      }
      return route.abort();
    });
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForLoadState('load', { timeout: 15000 }).catch(() => {});
    await page.addStyleTag({ content: '.dcontent.clamp{max-height:none!important;display:block!important;-webkit-line-clamp:unset!important;-webkit-mask-image:none!important;mask-image:none!important}.dcontent.clamp::after{display:none!important}.readmore{visibility:hidden!important}' });
    await page.evaluate(() => document.fonts.ready);
    const res = await page.evaluate(() => {
      const box = ['.macc-i.open .macc-b .dcontent', '.dtabpanel[data-panel="0"] .dcontent']
        .map((s) => document.querySelector(s)).find((e) => e && e.getClientRects().length && e.getBoundingClientRect().height > 0);
      if (!box) return null;
      box.setAttribute('data-ln15', '1');
      // every text fragment with its rectangles, in document order. A line remembers the block
      // it sits in, so a heading that wraps onto two lines is one heading, not two.
      const BLOCK = 'p,div,h1,h2,h3,h4,h5,h6,li,section,td,th,blockquote,figure,dd,dt';
      const lines = [];
      const tw = document.createTreeWalker(box, NodeFilter.SHOW_TEXT);
      for (let n; (n = tw.nextNode());) {
        if (!n.data.trim()) continue;
        const el = n.parentElement, cs = getComputedStyle(el);
        const bold = parseInt(cs.fontWeight, 10) >= 600 || !!el.closest('h2,h3,h4,h5,h6');
        const block = el.closest(BLOCK);
        // list-item labels ("Niacinamide:" in a bullet) and the old shop's designed blocks are not section headings
        const skip = !!el.closest('li') || !!el.closest('.kbb-eblock');
        const rg = document.createRange(); rg.selectNodeContents(n);
        for (const r of rg.getClientRects()) {
          if (r.width < 1) continue;
          const last = lines[lines.length - 1];
          // same visual line: overlapping vertically with the line being built
          if (last && r.top < last.bottom - 2 && r.bottom > last.top + 2) {
            last.top = Math.min(last.top, r.top); last.bottom = Math.max(last.bottom, r.bottom);
            last.bold = last.bold && bold; last.skip = last.skip || skip; last.text += n.data;
          } else lines.push({ top: r.top, bottom: r.bottom, bold, skip, block, text: n.data });
        }
      }
      const out = [];
      for (let i = 0; i + 1 < lines.length; i++) {
        const L = lines[i];
        if (!L.bold || L.skip) continue;
        // a wrapped heading: measured from its last line
        let j = i, text = L.text;
        while (j + 1 < lines.length && lines[j + 1].bold && lines[j + 1].block === L.block) { j++; text += ' ' + lines[j].text; }
        const t = text.trim();
        i = j;
        if (t.length > 80 || j + 1 >= lines.length) continue;
        out.push({ heading: t.slice(0, 40), gap: Math.round((lines[j + 1].top - lines[j].bottom) * 10) / 10, next: lines[j + 1].text.trim().slice(0, 30) });
      }
      // the ordinary line-to-line leftover inside a paragraph, for scale
      const inPara = [];
      for (let i = 0; i + 1 < lines.length; i++) if (!lines[i].bold) inPara.push(Math.round((lines[i + 1].top - lines[i].bottom) * 10) / 10);
      inPara.sort((a, b) => a - b);
      return { gaps: out, lineGap: inPara.length ? inPara[0] : null, scrollW: document.documentElement.scrollWidth, height: Math.round(box.getBoundingClientRect().height) };
    });
    if (res && SHOTS) {
      await page.evaluate(() => { for (const e of document.querySelectorAll('body *')) { const p = getComputedStyle(e).position; if (p === 'fixed' || p === 'sticky') e.style.visibility = 'hidden'; } });
      const el = await page.$('[data-ln15]');
      await el.screenshot({ path: `${SHOTS}/${PREFIX}-${it.loc}-${it.slug}-${w}.png` });
    }
    const g = res ? res.gaps.map((x) => x.gap) : [];
    console.log(`${it.loc} ${it.slug} ${w}`, res ? JSON.stringify({ gaps: g, lineGap: res.lineGap, h: res.height, scrollW: res.scrollW }) : 'NO BODY');
    all.push({ slug: it.slug, loc: it.loc, w, ...(res || {}) });
    await ctx.close();
  }
  fs.writeFileSync(OUT, JSON.stringify(all, null, 1));
  await browser.close();
})();
