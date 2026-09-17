/**
 * Appearance → Homepage content, driven in a real browser — Lane FO, Phase 15.
 *
 * WHAT THE PEST SUITE CANNOT SAY. HomepageContentEditorTest pins what the
 * SERVER does: that a payload saved through /admin-api/homepage/content reaches
 * the storefront, that a typed tag is escaped, that a javascript: link is
 * refused. It stops where the first keystroke begins. "Live editing" is a claim
 * about a SCREEN — that the preview follows the typing, that the sidebar row
 * registers itself, that the refusal is said out loud — and this is the half
 * that checks it.
 *
 * IT FOUND A DEFECT RATHER THAN CONFIRMING ONE. The first version of the screen
 * repainted #content on every keystroke, the way the Newsletter screen does.
 * In a browser that throws — "the node to be removed is no longer a child of
 * this node", raised by replacing the container while the focused input is
 * being blurred — and the screen was left half painted. Nothing in the Pest
 * suite could have seen it. The screen now redraws one preview per keystroke
 * and repaints only for structural changes.
 *
 * IT REPORTS AND SCREENSHOTS; IT DOES NOT ASSERT. It prints the hero's <h1>
 * before and after a change made entirely through the editor, the crumb and
 * title the console resolves for the new id, the text of the save banner, the
 * text of a REFUSAL banner, and the console's horizontal overflow at 390px.
 * The reader decides what the numbers mean. Output is in docs/fo-homepage-shots/.
 *
 * ── RUNNING IT ──────────────────────────────────────────────────────────────
 *
 * The three blocks in docs/FO-ADMIN-APP-BLOCKS.md must be applied to
 * resources/views/admin/app.blade.php, and the require line in the header of
 * routes/homepage-content-admin.php added to routes/web.php, or the screen has
 * no include and the endpoint 404s. Then:
 *
 *   KBB_FO_URL=http://127.0.0.1:8973 \
 *   KBB_FO_OUT=docs/fo-homepage-shots \
 *   KBB_FO_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/fo-homepage-content.mjs
 *
 * It signs in as fo@example.test / secret-secret, so seed an owner with those
 * credentials first. It WRITES to the shop it points at: it saves a hero over
 * whatever is there. Point it at a preview database, never at production.
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_FO_URL || 'http://127.0.0.1:8973';
const OUT  = process.env.KBB_FO_OUT || 'docs/fo-homepage-shots';
const CHROME = process.env.KBB_FO_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 } });
const p = await ctx.newPage();
p.on('console', m => { if (m.type() === 'error') console.log('  console error:', m.text()); });
p.on('pageerror', e => console.log('  page error:', e.message));

const shot = async (name, opts = {}) => {
  await p.screenshot({ path: `${OUT}/${name}.png`, ...opts });
  console.log('  shot:', name);
};

// ── the storefront, before ────────────────────────────────────────────────
await p.goto(`${BASE}/`, { waitUntil: 'networkidle' });
await shot('01-storefront-hero-before', { clip: { x: 0, y: 0, width: 1440, height: 760 } });
console.log('before, hero h1:', await p.locator('.sl h1').first().innerText());

// ── sign in ───────────────────────────────────────────────────────────────
await p.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
await p.fill('input[type="email"]', 'fo@example.test');
await p.fill('input[type="password"]', 'secret-secret');
await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type="submit"]')]);
console.log('signed in at:', p.url());

// ── the sidebar row registered itself ─────────────────────────────────────
await p.waitForSelector('#nav [data-go="hpcontent"]', { timeout: 10000 });
// Open the Appearance group the way the owner does, then use the row.
await p.locator('#nav .nav-group[data-sec="Appearance"] .nav-gh').click();
await p.waitForTimeout(300);
await p.locator('#nav [data-go="homepage"]').click();
await p.waitForTimeout(900);
await shot('02-admin-homepage-sections');

await p.locator('#nav [data-go="hpcontent"]').click();
await p.waitForSelector('.hpc-wrap', { timeout: 10000 });
await p.waitForTimeout(600);
console.log('crumb:', await p.locator('#crumb').innerText(), '/ title:', await p.locator('#ptitle').innerText());
await shot('03-admin-homepage-content-before', { fullPage: false });

// ── edit slide 1, and watch the preview follow the keystrokes ─────────────
/*
 * Addressed by the field's own data-hpc-set name, not by its visible label.
 * Playwright's hasText is CASE-INSENSITIVE, so { hasText: 'Headline' } also
 * matches the Eyebrow row -- whose help sentence reads "the small line above
 * the headline" -- and .first() then picks the wrong control. Found the hard
 * way; the name is unambiguous and is also what the screen itself keys on.
 */
const box = (field) => p.locator('[data-hpc-set="0.' + field + '"]');

await box('kicker').fill('Our own eyebrow line');
await p.waitForTimeout(150);
await box('heading').fill('Authentic Korean skincare\nchosen for the UAE');
await p.waitForTimeout(150);
await box('text').fill('Every product original. Free returns within 14 days.');
await p.waitForTimeout(150);
await box('button').fill('Browse the shop');
await p.waitForTimeout(150);
await box('bg_from').fill('#1F7D52');
await box('bg_mid').fill('#2A6A50');
await box('bg_to').fill('#14402F');
await p.waitForTimeout(400);
await shot('04-admin-live-preview-follows-typing');

// ── save ──────────────────────────────────────────────────────────────────
await p.click('[data-hpc-save]');
await p.waitForSelector('.hpc-note', { timeout: 10000 });
await p.evaluate(() => document.querySelector('#content').scrollTo(0, 0));
await p.evaluate(() => window.scrollTo(0, 0));
await p.waitForTimeout(500);
console.log('after save:', (await p.locator('.hpc-note').first().innerText()).replace(/\s+/g, ' ').slice(0, 160));
await shot('05-admin-saved');

// ── a refusal, said out loud ──────────────────────────────────────────────
await box('url').fill('javascript:alert(1)');
await p.waitForTimeout(200);
await p.click('[data-hpc-save]');
await p.waitForTimeout(900);
await p.evaluate(() => document.querySelector('#content').scrollTo(0, 0));
await p.evaluate(() => window.scrollTo(0, 0));
await p.waitForTimeout(300);
console.log('refusal:', (await p.locator('.hpc-note').first().innerText()).replace(/\s+/g, ' ').slice(0, 220));
await shot('06-admin-refused-link');

// ── the other-wording tab ─────────────────────────────────────────────────
await p.click('[data-hpc-tab="copy"]');
await p.waitForTimeout(400);
await shot('07-admin-other-wording');

// ── the storefront, after ─────────────────────────────────────────────────
await p.goto(`${BASE}/`, { waitUntil: 'networkidle' });
await shot('08-storefront-hero-after', { clip: { x: 0, y: 0, width: 1440, height: 760 } });
console.log('after, hero h1:', await p.locator('.sl h1').first().innerText());
console.log('after, hero href:', await p.locator('a.sl').first().getAttribute('href'));

// ── a phone, because the owner reviews on one ─────────────────────────────
const phone = await b.newContext({ viewport: { width: 390, height: 900 } });
const pp = await phone.newPage();
await pp.goto(`${BASE}/`, { waitUntil: 'networkidle' });
await pp.screenshot({ path: `${OUT}/09-storefront-phone-after.png`, clip: { x: 0, y: 0, width: 390, height: 780 } });
console.log('  shot: 09-storefront-phone-after');

/* And the CONSOLE on a phone, because the owner reviews on one and the house
   rule is that nothing may be wider than its column at 390px. */
const ap = await phone.newPage();
await ap.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
await ap.fill('input[type="email"]', 'fo@example.test');
await ap.fill('input[type="password"]', 'secret-secret');
await Promise.all([ap.waitForNavigation({ waitUntil: 'networkidle' }), ap.click('button[type="submit"]')]);
await ap.waitForSelector('#nav [data-go="hpcontent"]', { timeout: 10000 });
await ap.evaluate(() => window.go('hpcontent'));
await ap.waitForSelector('.hpc-wrap', { timeout: 10000 });
await ap.waitForTimeout(700);
const overflow = await ap.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
console.log('phone console horizontal overflow (px):', overflow);
await ap.screenshot({ path: `${OUT}/10-admin-phone.png`, clip: { x: 0, y: 0, width: 390, height: 900 } });
console.log('  shot: 10-admin-phone');

await b.close();
console.log('done');
