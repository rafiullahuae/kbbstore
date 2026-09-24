/**
 * Store → Import → Addresses & pictures → "What this needs you to decide".
 *
 * Lane A, round 2. The suite proves the rules; this proves the thing the owner
 * actually does — sign in, open the card, read the questions, answer one block,
 * and watch the counts move. At 390px and at 1280px, because the ask bucket is
 * a table and a table is where a phone layout breaks.
 *
 * ── RUNNING IT ──────────────────────────────────────────────────────────────
 *
 *   KBB_A_URL=http://127.0.0.1:8977 \
 *   KBB_A_OUT=docs/lane-a-questions-shots \
 *   KBB_A_CHROME=/usr/bin/chromium-browser \
 *   node tests/browser/lane-a-address-questions.mjs
 *
 * It signs in as qa@example.test / secret-secret and RECORDS ANSWERS in the
 * shop it points at. Point it at a preview database, never at production.
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_A_URL || 'http://127.0.0.1:8977';
const OUT = process.env.KBB_A_OUT || 'docs/lane-a-questions-shots';
const CHROME = process.env.KBB_A_CHROME || '/usr/bin/chromium-browser';

const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });

const open = async (width, height) => {
  const ctx = await b.newContext({ viewport: { width, height } });
  const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error') console.log('  console error:', m.text()); });
  p.on('pageerror', e => console.log('  page error:', e.message));

  await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await p.fill('input[type="email"]', 'qa@example.test');
  await p.fill('input[type="password"]', 'secret-secret');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type="submit"]')]);

  /*
   * The screen is reached through the panel's own router, go('import'), not by
   * clicking the sidebar: at 390px the sidebar is a drawer and the click is a
   * test of the drawer rather than of this card. go() is what the sidebar
   * itself calls.
   */
  await p.waitForSelector('#nav', { timeout: 20000 });
  await p.evaluate(() => window.go('import'));
  await p.waitForSelector('#impDrop', { timeout: 20000 });
  await p.waitForTimeout(800);

  await p.locator('#gbUMLoad').scrollIntoViewIfNeeded();
  await p.locator('#gbUMLoad').click({ force: true });
  await p.waitForSelector('#gbUMWrite', { timeout: 30000 });
  await p.waitForTimeout(500);

  return { ctx, p };
};

/** The numbers the brief asks for, measured rather than asserted. */
const measure = async p => p.evaluate(() => {
  const block = document.querySelector('.gpQAll');
  const card = block ? block.closest('.impfile') : null;
  const box = card ? card.getBoundingClientRect() : null;

  return {
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    questionBlocks: document.querySelectorAll('.gpQAll[data-a="reject"]').length,
    yesButtons: document.querySelectorAll('.gpQAll[data-a="accept"]').length,
    firstBlockHeight: box ? Math.round(box.height) : null,
    firstBlockWidth: box ? Math.round(box.width) : null,
    headingFontSize: card ? getComputedStyle(card.querySelector('b')).fontSize : null,
  };
});

for (const [name, width, height] of [['phone', 390, 900], ['desktop', 1280, 1000]]) {
  const { ctx, p } = await open(width, height);

  await p.locator('.gpQAll').first().scrollIntoViewIfNeeded();
  await p.waitForTimeout(300);
  await p.screenshot({ path: `${OUT}/1-${name}-questions-before.png`, fullPage: true });
  console.log(`${name} before:`, JSON.stringify(await measure(p)));

  // One question opened, so the rows behind the buttons are visible too.
  await p.locator('details summary').first().click({ force: true });
  await p.waitForTimeout(300);
  await p.screenshot({ path: `${OUT}/2-${name}-question-opened.png`, fullPage: true });
  console.log(`${name} opened:`, JSON.stringify(await measure(p)));

  // Answer a whole question, through the screen, with the confirm accepted.
  p.once('dialog', d => d.accept());
  await p.locator('.gpQAll[data-a="accept"]').first().click({ force: true });
  await p.waitForTimeout(2500);
  await p.locator('.impfile', { hasText: 'redirects to write' }).first().scrollIntoViewIfNeeded();
  await p.waitForTimeout(400);
  await p.screenshot({ path: `${OUT}/3-${name}-answered.png`, fullPage: true });

  const after = await p.evaluate(async () => {
    const r = (await window.impApi('/urls-media/status')).data;
    return {
      buckets: {
        migrate: r.urls.buckets.migrate.count,
        ask: r.urls.buckets.ask.count,
        discard: r.urls.buckets.discard.count,
      },
      answers: r.urls.answers,
      questions: r.urls.questions.map(q => `${q.question}:${q.asking}asking/${q.accepted}yes/${q.rejected}no`),
    };
  });

  console.log(`${name} after answering:`, JSON.stringify(after));
  console.log(`${name} measured:`, JSON.stringify(await measure(p)));

  /*
   * Put it back, so the next viewport starts from the same shop. Through the
   * panel's own impApi(), which carries the XSRF header — a bare fetch() is
   * answered 419 by VerifyCsrfToken, which is the framework doing its job and
   * not something to route around.
   */
  await p.evaluate(async () => {
    for (const q of ['still-answers', 'already-redirects', 'no-target', 'target-missing', 'cannot-tell']) {
      await window.impApi('/urls-media/decisions', {
        method: 'POST',
        body: JSON.stringify({ action: 'clear', question: q }),
      });
    }
  });

  await ctx.close();
}

await b.close();
console.log('done');
