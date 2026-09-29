/*
 * Lane PERF — WHEN does the browser ask for the LCP picture?
 *
 * ── WHY THIS EXISTS WHEN THERE IS ALREADY A LIGHTHOUSE RUN ──────────────────
 *
 * The owner's report puts 2,260 ms of his LCP into "Resource load delay" — the
 * time between the first byte of the document and the browser asking for the
 * picture. Nothing is wrong with the tag: "LCP request discovery" PASSES on his
 * shop, the image is in the initial document, it is not lazy and it already
 * carries fetchpriority="high". The delay is that the document is 51 KiB over a
 * 1,638 kb/s link and the <img> is two thirds of the way down it, so the
 * preload scanner does not reach the tag until the body has arrived.
 *
 * LIGHTHOUSE CANNOT SHOW THAT FIX LOCALLY, and it is worth writing down rather
 * than quietly reporting a number that does not move. Its default throttling is
 * SIMULATED: Lantern builds a dependency graph from the observed trace and
 * replays it at 150 ms RTT, and in that graph the document is ONE node — a
 * child request starts when the document node completes, whether it was named
 * in the first kilobyte or the last. On a localhost server the document really
 * arrives in ~280 ms, so the preload buys ~20 ms of observed time and Lantern
 * models away the rest.
 *
 * So this measures the thing itself, over a REAL throttled connection
 * (CDP Network.emulateNetworkConditions, the same 1,638 kb/s and 150 ms RTT
 * PSI uses): the wall-clock moment the LCP picture is requested, relative to
 * the moment the document was.
 *
 *   node tools/perf-lcp-discovery.cjs
 */
const { chromium } = require('playwright');

const BEFORE = process.env.PERF_BEFORE || 'http://127.0.0.1:8992';
const AFTER = process.env.PERF_AFTER || 'http://127.0.0.1:8991';

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    args: ['--ignore-certificate-errors'],
  });

  for (const [label, base] of [['before', BEFORE], ['after', AFTER]]) {
    const runs = [];

    for (let i = 0; i < 3; i++) {
      const ctx = await browser.newContext({ viewport: { width: 412, height: 823 } });
      const page = await ctx.newPage();
      const cdp = await ctx.newCDPSession(page);

      await cdp.send('Network.enable');
      await cdp.send('Network.emulateNetworkConditions', {
        offline: false,
        latency: 150,
        downloadThroughput: (1638.4 * 1024) / 8,
        uploadThroughput: (675 * 1024) / 8,
      });

      let documentAt = null;
      let posterAt = null;

      page.on('request', (r) => {
        const t = Date.now();
        if (documentAt === null && r.resourceType() === 'document') documentAt = t;
        if (posterAt === null && /posters\//.test(r.url())) posterAt = t;
      });

      await page.goto(base + '/', { waitUntil: 'load', timeout: 120000 });
      runs.push(posterAt === null ? null : posterAt - documentAt);
      await ctx.close();
    }

    const ok = runs.filter((r) => r !== null).sort((a, b) => a - b);
    console.log(label.padEnd(7), 'first request for the banner picture, ms after the document:',
      JSON.stringify(runs), 'median', ok[(ok.length - 1) >> 1]);
  }

  await browser.close();
})();
