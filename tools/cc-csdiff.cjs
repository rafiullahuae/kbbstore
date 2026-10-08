/* Lane CC: every element's full computed style (+ ::before/::after), the opening homepage on two previews
 * (head :8840, change :8860), page scripts stripped and animations paused on both sides.
 *   node tools/cc-csdiff.cjs <width> [path]   -> differingValues must be 0 */
// Every element's full computed style (+ ::before/::after), head vs new, landing request, page scripts stripped.
const { chromium } = require(require('path').join(__dirname, '..', 'node_modules', 'playwright'));
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const W = +(process.argv[2] || 1280), PATH = process.argv[3] || '/';
  const get = async (port) => { const ctx = await b.newContext({ viewport: { width: W, height: W < 800 ? 844 : 800 } });
    await ctx.route((u) => u.pathname === PATH, async (r) => { const res = await r.fetch({ headers: { ...r.request().headers(), 'sec-fetch-site': 'none' } }); r.fulfill({ response: res, body: (await res.text()).replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '') }); });
    await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, body: '' }));
    const p = await ctx.newPage(); await p.goto('http://127.0.0.1:' + port + PATH, { waitUntil: 'load' }); await p.waitForTimeout(500);
    // Running CSS animations (the grey loading shimmer, the ticker) are sampled at a different instant
    // on each side; stop them on BOTH so only the stylesheets are compared.
    await p.addStyleTag({ content: '*,*::before,*::after{animation-play-state:paused!important;animation-delay:0s!important;animation-duration:0s!important;transition:none!important}' });
    const r = await p.evaluate(() => { const out = []; for (const el of document.querySelectorAll('body *')) for (const ps of [null, '::before', '::after']) { const cs = getComputedStyle(el, ps); const o = {}; for (let i = 0; i < cs.length; i++) o[cs[i]] = cs.getPropertyValue(cs[i]); out.push({ id: el.tagName + '.' + (el.getAttribute('class') || '') + (ps || ''), o }); } return out; });
    const inline = (await p.content()).includes('kbb-css-inline'); await ctx.close(); return { r, inline }; };
  const A = await get(8840), B = await get(8860);
  let diffs = 0; const props = {};
  for (let i = 0; i < Math.max(A.r.length, B.r.length); i++) { const x = A.r[i], y = B.r[i]; if (!x || !y || x.id !== y.id) { console.log('STRUCT', i, x && x.id, y && y.id); diffs++; break; }
    for (const k of Object.keys(x.o)) if (x.o[k] !== y.o[k]) { diffs++; props[k] = (props[k] || 0) + 1; if (diffs < 4) console.log('DIFF', x.id, k, x.o[k].slice(0, 80), '|', y.o[k].slice(0, 80)); } }
  console.log(JSON.stringify({ w: W, path: PATH, headInline: A.inline, newInline: B.inline, boxes: A.r.length, differingValues: diffs, byProperty: props }));
  await b.close();
})();
