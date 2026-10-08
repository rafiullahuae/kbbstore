/* Lane CC: loads in which the desktop mega-menu links paint unstyled, head vs change. JS=0 strips page scripts.
 *   node tools/cc-flash.cjs [N] */
// Lane CC: how often does the desktop mega-menu panel show in ANY painted frame of a fresh load?
const { chromium } = require(require('path').join(__dirname, '..', 'node_modules', 'playwright'));
const N = +(process.argv[2] || 10), JS = process.env.JS !== '0';
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const tool = await (await b.newContext()).newPage();
  const count = async (d) => tool.evaluate(async (d) => { const i = await new Promise((r) => { const m = new Image(); m.onload = () => r(m); m.src = 'data:image/png;base64,' + d; });
    const cv = document.createElement('canvas'); cv.width = i.width; cv.height = i.height; const g = cv.getContext('2d'); g.drawImage(i, 0, 0);
    const p = g.getImageData(720, 100, 240, 270).data; let n = 0; for (let k = 0; k < p.length; k += 4) if (p[k + 2] > 150 && p[k] < 90 && p[k + 1] < 90) n++; return n; }, d); // saturated blue = unstyled link
  for (const [lab, port] of [['head', 8840], ['new', 8860]]) { let hits = 0, framesHit = 0;
    for (let r = 0; r < N; r++) {
      const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
      await ctx.route(/\.(webp|jpe?g|png|gif|avif|mp4)(\?|$)/, (x) => x.abort());
      await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (x) => x.fulfill({ status: 200, body: '' }));
      if (!JS) await ctx.route((u) => u.pathname === '/', async (x) => { const res = await x.fetch({ headers: { ...x.request().headers(), 'sec-fetch-site': 'none' } }); x.fulfill({ response: res, body: (await res.text()).replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '') }); });
      const page = await ctx.newPage(); const cdp = await ctx.newCDPSession(page); const frames = [];
      cdp.on('Page.screencastFrame', async (f) => { frames.push(f.data); try { await cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }); } catch (e) {} });
      await cdp.send('Page.startScreencast', { format: 'png' });
      await page.goto('http://127.0.0.1:' + port + '/', { waitUntil: 'load' }); await page.waitForTimeout(800); await cdp.send('Page.stopScreencast');
      let hit = 0; for (const f of frames) if ((await count(f)) > 40) hit++;
      if (hit) { hits++; framesHit += hit; }
      await ctx.close();
    }
    console.log(lab, `JS=${JS ? 'on' : 'stripped'}`, `loads showing the unstyled mega-menu links: ${hits}/${N} (frames: ${framesHit})`);
  }
  await b.close();
})();
