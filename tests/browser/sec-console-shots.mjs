/**
 * Lane SEC round two — the console's own pictures.
 *
 * The shopper-facing half of closing the second door is invisible: a stranger
 * gets a 404 instead of a Location header. So the pictures that matter are the
 * CONSOLE's — that it still works, and what it does when the session behind it
 * has gone.
 *
 *   KBB_SEC_URL=http://127.0.0.1:8641 KBB_SEC_OUT=docs/sec-shots \
 *   node tests/browser/sec-console-shots.mjs
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.KBB_SEC_URL || 'http://127.0.0.1:8641';
const OUT = process.env.KBB_SEC_OUT || 'docs/sec-shots';
const ADMIN = process.env.KBB_SEC_ADMIN || 'mr-cool';
const CHROME = process.env.KBB_SEC_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

mkdirSync(OUT, { recursive: true });

const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
const measured = {};

for (const [w, h, tag] of [[1280, 1000, '1280'], [390, 844, '390']]) {
  const ctx = await b.newContext({ viewport: { width: w, height: h } });
  const p = await ctx.newPage();

  /* Every admin-api request the console makes, with the Accept header it sent
     and the status it got back. This is the measurement the whole design rests
     on, taken from the browser rather than from the source. */
  const calls = [];
  p.on('request', r => {
    if (r.url().includes('/admin-api/')) {
      calls.push({ url: r.url().replace(BASE, ''), accept: r.headers()['accept'] || '(none)' });
    }
  });
  p.on('response', r => {
    if (r.url().includes('/admin-api/')) {
      const hit = calls.find(c => BASE + c.url === r.url() && c.status === undefined);
      if (hit) hit.status = r.status();
    }
  });

  await p.goto(`${BASE}/${ADMIN}/login`, { waitUntil: 'networkidle' });
  await p.fill('input[type="email"]', 'sec@example.test');
  await p.fill('input[type="password"]', 'secret-secret');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type="submit"]')]);
  await p.waitForTimeout(2500);

  await p.screenshot({ path: `${OUT}/${tag}-console-01-signed-in.png` });
  measured[`${tag}.signed_in_at`] = p.url().replace(BASE, '');
  measured[`${tag}.admin_api_calls`] = calls.length;
  measured[`${tag}.all_expect_json`] = calls.every(c => /json/i.test(c.accept));
  measured[`${tag}.statuses`] = [...new Set(calls.map(c => c.status))];

  /* ── the session goes ─────────────────────────────────────────────────── */

  await ctx.clearCookies();
  calls.length = 0;

  /* Press something that reads from admin-api. The dashboard re-hydrates on a
     tab change, so switching to Catalog is the shopper-equivalent of "press
     something" rather than a URL typed by hand. */
  const went = await p.evaluate(() => {
    if (typeof window.go === 'function') { window.go('catalog'); return 'window.go'; }
    return null;
  });

  if (!went) { await p.reload({ waitUntil: 'networkidle' }); }
  await p.waitForTimeout(3500);
  measured[`${tag}.expiry_via`] = went || 'reload';

  await p.screenshot({ path: `${OUT}/${tag}-console-02-session-gone.png` });
  measured[`${tag}.after_expiry`] = calls.map(c => `${c.url} accept=${/json/i.test(c.accept) ? 'json' : c.accept} -> ${c.status}`).slice(0, 6);
  measured[`${tag}.expiry_statuses`] = [...new Set(calls.map(c => c.status))];

  /* ── and what a stranger gets at the same address ─────────────────────── */

  const stranger = await b.newContext({ viewport: { width: w, height: h } });
  const sp = await stranger.newPage();
  const r = await sp.goto(`${BASE}/admin-api/security`, { waitUntil: 'domcontentloaded' });
  await sp.screenshot({ path: `${OUT}/${tag}-console-03-stranger-gets-404.png` });
  measured[`${tag}.stranger`] = { status: r.status(), body_mentions_admin_path: (await sp.content()).includes(ADMIN) };
  await stranger.close();

  await ctx.close();
}

console.log(JSON.stringify(measured, null, 2));
await b.close();
