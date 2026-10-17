const { chromium } = require('playwright');
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }); const found = {};
 const p0 = await b.newPage(); await p0.goto(process.env.BASE + '/ar/', { waitUntil: 'networkidle' });
 const links = await p0.evaluate(() => { const pick = (re) => { const a = [...document.querySelectorAll('a[href]')].map(a => a.getAttribute('href')).find(h => re.test(h)); return a || null; }; return [pick(/\/ar\/product\//), pick(/\/ar\/collections\//), pick(/\/ar\/brand/), pick(/\/ar\/blog\/[^/]+\//)].filter(Boolean); });
 await p0.close();
 const pages = ['/ar/', '/ar/shop/', '/ar/cart/', '/ar/blog/', '/ar/my-account/', '/ar/track-my-order/', ...links];
 for (const path of pages) for (const w of [390, 1280]) {
  const p = await b.newPage({ viewport: { width: w, height: 900 } });
  try { await p.goto(process.env.BASE + path, { waitUntil: 'networkidle', timeout: 30000 }); } catch (e) { await p.close(); continue; }
  try { await p.click('#burger', { timeout: 1500 }); await p.waitForTimeout(400); } catch (e) {}
  const r = await p.evaluate(() => { const out = []; const ar = /[؀-ۿ]/;
    for (const el of document.querySelectorAll('body *')) { const own = [...el.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent).join('').trim(); if (!own || !ar.test(own)) continue;
      const ls = getComputedStyle(el).letterSpacing; if (ls === 'normal' || parseFloat(ls) === 0) continue;
      const path = []; let e = el; for (let i = 0; i < 3 && e && e !== document.body; i++, e = e.parentElement) path.unshift(e.tagName.toLowerCase() + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).slice(0, 2).join('.') : ''));
      out.push([path.join(' > '), ls, own.slice(0, 30)]); } return out; });
  for (const [sel, ls, t] of r) { const k = sel + ' | ' + ls; if (!found[k]) found[k] = [t, path + '@' + w]; }
  await p.close(); }
 for (const [k, v] of Object.entries(found)) console.log(k, ' :: ', v.join(' :: '));
 await b.close(); })();
