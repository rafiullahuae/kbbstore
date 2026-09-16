/*
 * Lane DA — every admin screen, reached three ways.
 *
 * The defect this walks: clicking a sidebar row worked, arriving at the same
 * screen's URL did not. So each id is driven all three ways in one session —
 * sidebar click, ?go=<id>, #<id> — and the first 100 characters of #content are
 * reported for each. A fix is "the three agree"; anything else is a table that
 * shows which of the three disagrees and how.
 *
 * It also counts, per navigation:
 *
 *   writes   — every non-empty assignment to #content.innerHTML. This is what
 *              "rendered twice" means concretely, and it is measured rather
 *              than reasoned about because the failure mode the console has
 *              already shipped once is a control that got two handlers and
 *              stopped working.
 *   fetches  — every request the screen made. A screen mounted twice asks the
 *              server for the same thing twice, so a repeated URL is the other
 *              face of the same bug.
 *
 * Both are instrumented before any page script runs, by wrapping the innerHTML
 * setter on Element.prototype and window.fetch. The wrapper forwards to the
 * original in both cases, so nothing about the console's behaviour changes.
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_DL_BASE;
const EMAIL = process.env.KBB_DL_EMAIL;
const PASSWORD = process.env.KBB_DL_PASSWORD;
const CHROME = process.env.KBB_DL_CHROME;
const IDS = (process.env.KBB_DL_IDS || '').split(',').map(s => s.trim()).filter(Boolean);
const SETTLE = parseInt(process.env.KBB_DL_SETTLE || '2200', 10);

const out = { ok: false, error: null, rows: [] };

function clip(s) {
  return String(s || '').replace(/\s+/g, ' ').trim().slice(0, 100);
}

/* Instrumentation, installed before the console's own script parses. */
const INIT = `
  (function(){
    window.__writes = [];
    window.__fetches = [];
    var d = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
    Object.defineProperty(Element.prototype, 'innerHTML', {
      configurable: true,
      enumerable: d.enumerable,
      get: d.get,
      set: function(v){
        /* The empty assignment go() makes just before a renderer runs is a
           clear, not a paint, so it is not counted as a render. */
        if (this && this.id === 'content' && String(v).trim() !== '') {
          window.__writes.push(String(v).replace(/\\s+/g,' ').trim().slice(0, 90));
        }
        return d.set.call(this, v);
      }
    });
    var of = window.fetch;
    window.fetch = function(u){
      try { window.__fetches.push(String((u && u.url) || u)); } catch (e) {}
      return of.apply(this, arguments);
    };
  })();
`;

async function reset(page) {
  await page.evaluate(() => { window.__writes = []; window.__fetches = []; });
}

async function readState(page) {
  await page.waitForTimeout(SETTLE);
  return await page.evaluate(() => {
    const el = document.querySelector('#content');
    return {
      text: el ? el.innerText : '((no #content))',
      writes: (window.__writes || []).slice(),
      fetches: (window.__fetches || []).slice(),
      /* The marker the deep-link boot drops. If it is still here, nothing drew
         the screen and the placeholder is what the owner is looking at. */
      marker: !!document.querySelector('#content [data-kbb-deeplink]'),
    };
  });
}

function repeatedFetches(list) {
  const seen = {};
  const dupes = [];
  for (const u of list) {
    /* Only the console's own endpoints. Assets and third-party pings are not
       what "fetched twice" is about and some are legitimately repeated. */
    if (!/\/admin-api\//.test(u)) continue;
    seen[u] = (seen[u] || 0) + 1;
    if (seen[u] === 2) dupes.push(u);
  }
  return dupes;
}

function repeatedWrites(list) {
  const seen = {};
  const dupes = [];
  for (const w of list) {
    seen[w] = (seen[w] || 0) + 1;
    if (seen[w] === 2) dupes.push(w.slice(0, 60));
  }
  return dupes;
}

try {
  if (!BASE || !EMAIL || !PASSWORD || !CHROME) {
    throw new Error('KBB_DL_BASE, KBB_DL_EMAIL, KBB_DL_PASSWORD and KBB_DL_CHROME are all required');
  }

  const browser = await chromium.launch({
    executablePath: CHROME,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });

  /* newContext({viewport}), never setViewportSize: the latter does not take in
     this environment and leaves every measurement at the default width. */
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await context.addInitScript(INIT);

  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', e => pageErrors.push(String(e.message)));

  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[type=email], input[name=email]', EMAIL);
  await page.fill('input[type=password], input[name=password]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.waitForTimeout(1200);

  if (!/\/admin(\?|#|$)/.test(page.url())) {
    throw new Error('login did not reach the console, landed on ' + page.url());
  }

  for (const id of IDS) {
    const row = { id };

    /* ---------- the path the owner uses every day ---------- */
    await page.goto(BASE + '/admin', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(SETTLE);

    row.hasRow = await page.evaluate(
      i => !!document.querySelector('.side .nav-item[data-go="' + i + '"]'), id);

    if (row.hasRow) {
      await reset(page);
      await page.evaluate(i => document.querySelector('.side .nav-item[data-go="' + i + '"]').click(), id);
      const s = await readState(page);
      row.click = clip(s.text);
      row.clickWrites = s.writes.length;
      row.clickRepeatFetches = repeatedFetches(s.fetches);
    } else {
      row.click = '((no sidebar row))';
      row.clickWrites = null;
      row.clickRepeatFetches = [];
    }

    /* ---------- ?go=<id> ---------- */
    await page.goto('about:blank');
    await page.goto(BASE + '/admin?go=' + encodeURIComponent(id), { waitUntil: 'domcontentloaded' });
    const q = await readState(page);
    row.query = clip(q.text);
    row.queryWrites = q.writes.length;
    row.queryWriteHeads = q.writes.map(w => w.slice(0, 50));
    row.queryRepeatFetches = repeatedFetches(q.fetches);
    row.queryRepeatWrites = repeatedWrites(q.writes);
    row.queryMarkerLeft = q.marker;

    /* ---------- #<id> ---------- */
    await page.goto('about:blank');
    await page.goto(BASE + '/admin#' + encodeURIComponent(id), { waitUntil: 'domcontentloaded' });
    const h = await readState(page);
    row.hash = clip(h.text);
    row.hashWrites = h.writes.length;
    row.hashRepeatFetches = repeatedFetches(h.fetches);

    out.rows.push(row);
  }

  out.pageErrors = Array.from(new Set(pageErrors));
  out.ok = true;
  await browser.close();
} catch (e) {
  out.error = String((e && e.stack) || e);
}

process.stdout.write(JSON.stringify(out));
