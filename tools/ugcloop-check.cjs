/*
 * Does the "2.5 second loop" column actually PLAY the clip?
 *
 *   sh tools/ugcloop-preview.sh 8975
 *   (copy an mp4 to <webroot>/uploads/ugc/clip-loopcheck.mp4 and a jpg beside it)
 *   node tools/ugcloop-check.cjs
 *
 * It signs in, opens Content -> All clips, opens the clip, presses step 2, and
 * then reports what is actually in the loop box: whether a <video> exists, is
 * attached to the document, where its rect sits relative to the box, and
 * whether currentTime is RISING -- because an element that exists, is on
 * screen and is paused looks identical to a still in a screenshot.
 *
 * Measuring here is not what CLAUDE.md rule 4 forbids: rule 4 is about shipped
 * JavaScript laying the page out. This is the instrument that checks it.
 */
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8975';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/ugcloop-shots';

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();

  const problems = [];
  page.on('console', (m) => { if (m.type() === 'error') problems.push('console: ' + m.text()); });
  page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await page.press('input[name=password]', 'Enter');
  await page.waitForLoadState('networkidle');

  // The console swaps #content itself; go() is how it changes screen.
  await page.evaluate(() => window.go && window.go('ugcvideo'));
  await page.waitForTimeout(3000);

  const listShot = await page.evaluate(() => ({
    title: (document.querySelector('#ptitle') || {}).textContent,
    cards: document.querySelectorAll('[data-ugs-edit],[data-ugs-open],.ugs-card,.ugs-tile,.ugs-row').length,
    html: (document.querySelector('#content') || {}).innerHTML ?
      (document.querySelector('#content').innerHTML.match(/data-ugs-[a-z]+/g) || []).slice(0, 12) : [],
  }));
  console.error('LIST STATE ' + JSON.stringify(listShot));

  const card = page.locator('[data-ugs-open]').first();
  console.error('open buttons: ' + (await page.locator('[data-ugs-open]').count()));
  await card.click({ force: true });
  await page.waitForTimeout(2500);

  console.error('AFTER OPEN ' + JSON.stringify(await page.evaluate(() => ({
    form: !!document.querySelector('#ugs-form'),
    steps: document.querySelectorAll('.ugs-step').length,
    onStep: (document.querySelector('.ugs-step.is-on') || {}).textContent,
    panels: document.querySelectorAll('#ugs-form > .ugs-panel').length,
    loopBoxes: document.querySelectorAll('.ugs-loop').length,
  }))));

  const step2 = page.locator('.ugs-step').nth(1);
  if (await step2.count()) { await step2.click({ force: true }).catch(() => {}); }
  await page.waitForTimeout(3000);

  console.error('AFTER STEP2 ' + JSON.stringify(await page.evaluate(() => ({
    onStep: (document.querySelector('.ugs-step.is-on') || {}).textContent,
    loopBoxes: document.querySelectorAll('.ugs-loop').length,
    videos: document.querySelectorAll('#ugs-form video').length,
  }))));

  const read = async () => page.evaluate(() => {
    const box = document.querySelector('.ugs-loop');
    const v = document.querySelector('.ugs-loop video');
    const img = document.querySelector('.ugs-loop img');
    const r = (el) => { if (!el) return null; const b = el.getBoundingClientRect();
      return { top: Math.round(b.top), left: Math.round(b.left), w: Math.round(b.width), h: Math.round(b.height) }; };
    return {
      boxFound: !!box,
      box: r(box),
      poster: r(img),
      videoFound: !!v,
      videoConnected: v ? v.isConnected : null,
      videoRect: r(v),
      src: v ? (v.currentSrc || v.getAttribute('src') || '') : null,
      paused: v ? v.paused : null,
      readyState: v ? v.readyState : null,
      networkState: v ? v.networkState : null,
      error: v && v.error ? v.error.code : null,
      currentTime: v ? v.currentTime : null,
      cssPosition: v ? getComputedStyle(v).position : null,
    };
  });

  const a = await read();
  await page.waitForTimeout(1500);
  const b = await read();

  const advancing = (a.currentTime !== null && b.currentTime !== null)
    ? (b.currentTime > a.currentTime) : null;

  console.log(JSON.stringify({
    first: a,
    after1500ms_currentTime: b.currentTime,
    ADVANCING: advancing,
    VERDICT: (!a.videoFound) ? 'NO <video> AT ALL — wireLoop did not mount'
      : (!a.videoConnected) ? 'video is DETACHED from the document'
      : (a.videoRect && a.box && a.videoRect.top !== a.box.top) ? 'video is OUTSIDE its box'
      : (a.error) ? ('video element reports MEDIA ERROR code ' + a.error)
      : (advancing === false) ? 'video is mounted and on screen but NOT PLAYING'
      : (advancing === true) ? 'PLAYING'
      : 'inconclusive',
    problems,
  }, null, 2));

  require('node:fs').mkdirSync(OUT, { recursive: true });
  await page.screenshot({ path: `${OUT}/step2-1440.png`, fullPage: false });
  await browser.close();
})();
