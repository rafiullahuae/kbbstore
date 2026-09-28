/*
 * Lane BP screenshots: Appearance → Banners, the buffered editor.
 *
 * Driven the way an owner drives it — sign in, open the screen, press Edit,
 * change several things WITHOUT saving, and photograph the state he is then
 * looking at. The owner's complaint was about what happened between his edits,
 * so a picture of a saved screen would prove nothing.
 *
 * Modelled on tools/bn-admin-shots.cjs and tools/bn-preview.sh.
 */
const { chromium } = require('playwright');

const BASE = process.env.BP_BASE || 'http://127.0.0.1:8974';
const OUT = process.env.BP_OUT || (__dirname + '/../docs/lane-bp-shots');

(async () => {
  const width = +process.argv[2];
  const browser = await chromium.launch({ executablePath: process.env.BP_CHROME });
  const ctx = await browser.newContext({ viewport: { width, height: 1300 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();

  // Confirm dialogs: the leave guard asks one, and a headless run has to answer.
  const asked = [];
  page.on('dialog', d => { asked.push(d.message()); d.dismiss(); });

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);

  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
  await page.evaluate(() => window.go('banners'));
  await page.waitForTimeout(1500);

  await page.screenshot({ path: `${OUT}/admin-list-${width}.png`, fullPage: true });

  // Open the second set — the one with a colour behind it and a green button,
  // so the colour controls in the picture have something in them.
  await page.click('.bns-set:nth-child(2) [data-bns-open]');
  await page.waitForTimeout(2000);

  const clean = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    sections: [...document.querySelectorAll('.bns-sec')].map(s => s.textContent.trim()),
    labels: [...document.querySelectorAll('.bns-lab')].map(l => l.textContent.trim()),
    switches: [...document.querySelectorAll('#bns-editor .bns-sw span')].map(l => l.textContent.trim()),
    speedHelp: (document.querySelector('[data-bns-out="speed_ms"]') || {}).textContent,
    saveButton: (document.querySelector('#bns-saveset') || {}).textContent,
    saveDisabled: (document.querySelector('#bns-saveset') || {}).disabled,
    marker: (document.querySelector('#bns-bar') || {}).textContent,
    cards: document.querySelectorAll('.bns-cd').length,
    frame: !!document.querySelector('.bns-frame'),
  }));

  console.log(JSON.stringify({ step: 'editor-clean', width, ...clean }, null, 2));
  await page.screenshot({ path: `${OUT}/admin-editor-clean-${width}.png`, fullPage: true });

  /* ── SEVERAL EDITS, NONE OF THEM SAVED ─────────────────────────────────── */
  await page.evaluate(() => {
    const fire = (el, type) => el.dispatchEvent(new Event(type, { bubbles: true }));

    const speed = document.querySelector('[data-bns-speed]');
    speed.value = String(Number(speed.max) - 2000);
    fire(speed, 'input');

    const radius = document.querySelector('[data-bns-set="card_radius"]');
    radius.value = '2';
    fire(radius, 'input');

    const title = document.querySelector('[data-bns-set="title_pos"]');
    title.value = 'over';
    fire(title, 'change');

    const heading = document.querySelector('[data-bns-card][data-bns-k="heading"]');
    heading.value = 'Typed, not saved';
    fire(heading, 'input');

    const colour = document.querySelector('[data-bns-colour="btn_bg"]');
    colour.value = '#7c3aed';
    fire(colour, 'input');
  });

  await page.waitForTimeout(1600);

  const dirty = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    marker: (document.querySelector('.bns-draft') || {}).textContent,
    saveButton: (document.querySelector('#bns-saveset') || {}).textContent,
    saveDisabled: (document.querySelector('#bns-saveset') || {}).disabled,
    footDirty: document.querySelector('#bns-foot').classList.contains('is-dirty'),
    speedHelp: (document.querySelector('[data-bns-out="speed_ms"]') || {}).textContent,
    // What the preview is drawing, read out of the frame — this is the half
    // that makes a buffered editor usable rather than merely safe.
    framed: (() => {
      const f = document.querySelector('.bns-frame');
      if (!f || !f.contentDocument) return null;
      const root = f.contentDocument.querySelector('.kbbn');
      const btn = f.contentDocument.querySelector('.kbbn-btn');
      return {
        cards: f.contentDocument.querySelectorAll('.kbbn-c').length,
        rootClass: root ? root.className : null,
        headings: [...f.contentDocument.querySelectorAll('.kbbn-h')].slice(0, 2).map(h => h.textContent),
        buttonBackground: btn ? getComputedStyle(btn).backgroundColor : null,
        cardRadius: (() => {
          const c = f.contentDocument.querySelector('.kbbn-c');
          return c ? getComputedStyle(c).borderRadius : null;
        })(),
      };
    })(),
  }));

  console.log(JSON.stringify({ step: 'editor-dirty', width, ...dirty }, null, 2));
  await page.screenshot({ path: `${OUT}/admin-editor-dirty-${width}.png`, fullPage: true });

  // The marker and the footer on their own, at a readable size.
  const bar = await page.$('.bns-draft');
  if (bar) await bar.screenshot({ path: `${OUT}/admin-unsaved-bar-${width}.png` });

  const foot = await page.$('#bns-foot');
  if (foot) await foot.screenshot({ path: `${OUT}/admin-save-footer-${width}.png` });

  /* ── AND THE GUARD: leaving with edits pending asks first ──────────────── */
  await page.evaluate(() => window.go('dash'));
  await page.waitForTimeout(700);

  const guarded = await page.evaluate(() => ({
    stillOnBanners: !!document.querySelector('#bns-editor'),
  }));

  console.log(JSON.stringify({ step: 'leave-guard', width, asked, ...guarded }, null, 2));

  /* ── NOW SAVE, and confirm the marker clears and the row really moved ──── */
  await page.click('#bns-saveset');
  await page.waitForTimeout(2200);

  const saved = await page.evaluate(() => ({
    marker: (document.querySelector('.bns-draft') || {}).textContent || '',
    saveButton: (document.querySelector('#bns-saveset') || {}).textContent,
    saveDisabled: (document.querySelector('#bns-saveset') || {}).disabled,
    state: (document.querySelector('#bns-state') || {}).textContent,
  }));

  console.log(JSON.stringify({ step: 'saved', width, ...saved }, null, 2));
  await page.screenshot({ path: `${OUT}/admin-editor-saved-${width}.png`, fullPage: true });

  await browser.close();
})();
