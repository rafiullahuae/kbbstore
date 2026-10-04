/*
 * Lane FT screenshots: Appearance -> Footer, four pages, each with its preview.
 *
 *   sh tools/ft-preview.sh            # boots the shop on 127.0.0.1:10030
 *   node tools/ft-shots.cjs 1280      # and again with 390
 *
 * For each page: the top of the page, one scrolled section, and the preview
 * card before and after one control is moved (nothing is saved). The numbers
 * that matter are printed as JSON: scrollWidth, the frame's scale, the footer's
 * height inside the frame and the value that moved.
 */
const { chromium } = require('playwright');

const BASE = process.env.FT_BASE || 'http://127.0.0.1:10030';
const OUT = process.env.FT_OUT || (__dirname + '/../docs/lane-ft-shots');
const CHROME = process.env.FT_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

/* One visible control per page, and the section to scroll to. */
const PLAN = {
  'site-d': { section: 'Bottom bar', key: 'site_c_bg', value: '#fff1f5', probe: 'footer.kft', prop: 'backgroundColor' },
  'site-m': { section: 'Big name', key: 'site_m_logo', value: true, probe: 'footer.kft .kft-logo', prop: 'display' },
  'bar-d': { section: 'Size & spacing', key: 'tone', value: 'ink', probe: 'footer.kbb-slimfoot', prop: 'backgroundColor' },
  'bar-m': { section: 'Text & marks', key: 'm_brand_size', value: 160, probe: 'footer.kbb-slimfoot .sf-brand b', prop: 'fontSize' },
};

(async () => {
  const width = +process.argv[2] || 1280;
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 1000 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);
  await page.evaluate(() => { try { localStorage.removeItem('kbb.footer.page'); } catch (e) {} window.go('slimfooter'); });
  await page.waitForSelector('.sfs-ptab');
  // The console's toast is not part of this screen; keep it out of the shots.
  await page.addStyleTag({ content: '#toast{display:none!important}' });

  const frameReady = async () => {
    await page.waitForFunction(() => {
      const f = document.querySelector('[data-sfs-frame]');
      const d = f && f.contentDocument;
      return d && d.querySelector('footer') && /Showing/.test((document.querySelector('[data-sfs-pvstate]') || {}).textContent || '');
    }, null, { timeout: 15000 });
    await page.waitForTimeout(500);
  };

  const measure = (probe, prop) => page.evaluate(([probe, prop]) => {
    const f = document.querySelector('[data-sfs-frame]');
    const d = f.contentDocument;
    const foot = d.querySelector('footer');
    const el = d.querySelector(probe);
    const card = f.getBoundingClientRect();
    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      frameShownWidth: Math.round(card.width), frameShownHeight: Math.round(card.height),
      frameScale: +(card.width / f.offsetWidth).toFixed(3),
      frameInnerWidth: d.documentElement.clientWidth,
      footerHeight: Math.round(foot.getBoundingClientRect().height),
      docHeight: d.documentElement.scrollHeight,
      probe: el ? getComputedStyle(el)[prop] : null,
    };
  }, [probe, prop]);

  const report = [];

  for (const key of Object.keys(PLAN)) {
    const plan = PLAN[key];
    await page.click(`[data-sfs-page="${key}"]`);
    await frameReady();
    await page.evaluate(() => { document.querySelector('.content').scrollTop = 0; });
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${OUT}/${key}-${width}-top.png` });

    const before = await measure(plan.probe, plan.prop);
    await page.locator('[data-sfs-pv]').screenshot({ path: `${OUT}/${key}-${width}-preview-before.png` });

    await page.evaluate(([k, v]) => {
      const el = document.querySelector(`[data-sfs-key="${k}"]`);
      if (el.type === 'checkbox') el.checked = !!v; else el.value = v;
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }, [plan.key, plan.value]);
    await page.waitForTimeout(400);
    await frameReady();
    const after = await measure(plan.probe, plan.prop);
    await page.locator('[data-sfs-pv]').screenshot({ path: `${OUT}/${key}-${width}-preview-after.png` });

    const state = await page.evaluate(() => (document.querySelector('[data-sfs-state]') || {}).textContent);

    // A scrolled section, with the unsaved change still showing.
    await page.evaluate((title) => {
      const h = [...document.querySelectorAll('.sfs-sec h3')].find(x => x.textContent === title);
      h.closest('section').scrollIntoView({ block: 'start' });
    }, plan.section);
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/${key}-${width}-section.png` });

    // Put it back without saving, so the next page starts clean.
    await page.click('[data-sfs-discard]');
    await page.waitForTimeout(300);

    report.push({ page: key, width, moved: `${plan.key} -> ${plan.value}`, state, before, after });
  }

  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
