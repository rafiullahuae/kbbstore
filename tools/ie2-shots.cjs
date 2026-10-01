/*
 * Lane IE2 — an upload this server refuses in one request, photographed going
 * through anyway.
 *
 * ▲ THE POINT IS THE NUMBER. A screenshot of a progress line proves nothing on
 *   its own, so this harness reads, from OUTSIDE the page:
 *
 *     · what the server says it takes in one request (/import/part/limits),
 *       and which of the two ini directives is imposing it;
 *     · the exact byte length of the file that is uploaded;
 *     · how many pieces it went in, and the size of each;
 *     · the row count the import screen ends up reporting.
 *
 *   A run where the file was never bigger than the ceiling is REFUSED rather
 *   than photographed: "the parts fit" satisfied by a file that needed no
 *   parts is the false green this whole lane is written against.
 *
 * ▲ NOTHING HERE MEASURES LAYOUT INSIDE THE PRODUCT. CLAUDE.md rule 4 forbids
 *   JavaScript in the shop that measures layout; measuring from a harness is
 *   how the claim is checked. scrollWidth is read here, in the browser this
 *   script drives, and reaches no package.
 *
 * Usage:  IE2_BASE=http://127.0.0.1:8941 node tools/ie2-shots.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.IE2_BASE || 'http://127.0.0.1:8941';
const OUT = process.env.IE2_OUT || path.join(__dirname, '..', 'docs', 'lane-ie2-shots');
const CHROME = process.env.IE2_CHROME || '/opt/pw-browsers/chromium';

fs.mkdirSync(OUT, { recursive: true });

const rows = [];

async function shoot(page, name, w) {
  await page.setViewportSize({ width: w, height: w === 390 ? 844 : 1000 });
  await page.waitForTimeout(500);

  const m = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    uploadMsg: (document.getElementById('impUploadMsg') || {}).textContent || '',
    banner: (document.querySelector('.impnote, .msg') || {}).textContent || '',
  }));

  await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });
  rows.push({ shot: `${name}-${w}`, width: w, ...m });
  console.log(JSON.stringify({ shot: `${name}-${w}`, ...m }));
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const page = await ctx.newPage();

  page.on('console', (m) => { if (m.type() === 'error') console.log(JSON.stringify({ consoleError: m.text() })); });
  page.on('pageerror', (e) => console.log(JSON.stringify({ pageError: String(e) })));

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);

  await page.goto(`${BASE}/admin?go=import`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1600);

  /* WHAT THIS SERVER ACTUALLY TAKES, asked of the server rather than assumed.
     Every number below is compared against this one. */
  const limits = await page.evaluate(async () => {
    const r = await fetch(window.impBase() + '/import/part/limits', {
      credentials: 'same-origin',
      headers: { 'X-XSRF-TOKEN': window.uToken(), Accept: 'application/json' },
    });
    return r.json();
  });

  console.log(JSON.stringify({ serverLimits: limits }));

  if (!limits || !limits.ok || !limits.limits || !limits.limits.part_bytes) {
    throw new Error('the part endpoints are not reachable in this preview — nothing to photograph');
  }

  const part = limits.limits.part_bytes;

  await shoot(page, 'import-screen-before', 1280);
  await shoot(page, 'import-screen-before', 390);
  await page.setViewportSize({ width: 1280, height: 1000 });

  /* A REAL products.csv, grown past the server's real ceiling. Built in the
     page because the file has to reach the screen's own <input type=file>
     handler exactly as a chosen file would. */
  const built = await page.evaluate((partBytes) => {
    let csv = 'wc_id,name,slug,sku,price,status\n';
    let n = 0;
    const target = Math.round(partBytes * 2.5);
    while (csv.length < target) {
      n++;
      csv += n + ',"Serum number ' + n + ' with a name long enough to weigh something",serum-'
        + n + ',SKU-' + n + ',49.00,publish\n';
    }
    window.__ie2file = new File([csv], 'products.csv', { type: 'text/csv' });
    return { bytes: csv.length, rows: n };
  }, part);

  console.log(JSON.stringify({ file: built, partBytes: part, expectedParts: Math.ceil(built.bytes / part) }));

  /* ▲ THE PREMISE, CHECKED RATHER THAN HOPED. */
  if (built.bytes <= part) {
    throw new Error(`the file is ${built.bytes} bytes and this server takes ${part} in one request — nothing would be sliced`);
  }

  /* Watch the pieces go, from outside: every request the page makes to the
     part endpoint, with its body size. This is the measurement that says the
     upload really was cut up, and it is taken from the network rather than
     from anything the page claims. */
  const pieces = [];
  page.on('request', (r) => {
    if (r.url().endsWith('/import/part')) {
      const post = r.postData();
      pieces.push(post ? post.length : null);
    }
  });

  const caught = [];
  page.on('response', async (r) => {
    if (r.url().endsWith('/import/part/finish')) {
      try { caught.push(await r.json()); } catch (e) { /* not json */ }
    }
  });

  /* Halfway through, so the progress line is on screen rather than gone. */
  const midway = page.waitForResponse(
    (r) => r.url().endsWith('/import/part') && pieces.length >= 1,
    { timeout: 60000 }
  );

  await page.evaluate(() => window.impUpload([window.__ie2file]));
  await midway.catch(() => {});
  await page.waitForTimeout(150);
  await shoot(page, 'uploading-in-pieces', 1280);

  await page.waitForFunction(() => {
    const box = document.getElementById('impUploadMsg');
    return box && !/sending in pieces|joining/i.test(box.textContent || '');
  }, { timeout: 120000 }).catch(() => {});

  await page.waitForTimeout(1200);

  await shoot(page, 'import-screen-after', 1280);
  await shoot(page, 'import-screen-after', 390);

  const result = caught[caught.length - 1] || null;

  const summary = {
    upload_max_filesize: limits.limits.per_file,
    post_max_size: limits.limits.per_request,
    capped_by: limits.limits.reason,
    part_bytes: part,
    file_bytes: built.bytes,
    file_rows: built.rows,
    pieces_sent: pieces.length,
    piece_body_sizes: pieces,
    largest_piece_body: pieces.length ? Math.max(...pieces.filter((n) => n !== null)) : null,
    accepted: result && result.accepted,
    rows_reported: result && result.accepted && result.accepted[0] && result.accepted[0].rows,
    bytes_reported: result && result.accepted && result.accepted[0] && result.accepted[0].bytes,
  };

  console.log(JSON.stringify({ summary }, null, 2));

  fs.writeFileSync(`${OUT}/ie2-measurements.json`, JSON.stringify({ summary, shots: rows }, null, 2));

  await browser.close();
})();
