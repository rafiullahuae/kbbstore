const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8941';

(async () => {
  const [out, w, h] = process.argv.slice(2);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: +w, height: +h }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  await page.goto(BASE + '/skin-quiz', { waitUntil: 'networkidle' });

  // Drive the quiz the way a shopper does: the state is the script's own, and
  // renderResults() is what the last step calls.
  await page.evaluate(() => {
    Object.assign(state, {
      skin: 'Oily',
      concerns: ['Acne & blemishes', 'Hydration'],
      age: '25-34', depth: '4-5 steps', budget: 'Mid',
      allergies: [], allergyNote: '',
      name: 'Aisha', phone: '+971500000000', email: 'aisha@example.test',
    });
    renderResults();
  });
  await page.waitForTimeout(600);

  const m = await page.evaluate(() => {
    const link = document.querySelector('.rtnlink');
    const a = link?.querySelector('a');
    const px = (el, p) => el ? Math.round(parseFloat(getComputedStyle(el)[p])) : null;
    return {
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      concernTablePresent: typeof window.KBB_CONCERN_PAGES !== 'undefined',
      concernTable: window.KBB_CONCERN_PAGES ?? null,
      routineTablePresent: typeof window.KBB_ROUTINES !== 'undefined',
      handoffBlockPresent: !!link,
      handoffLead: link?.querySelector('.rl')?.textContent ?? null,
      handoffCta: a?.textContent?.trim() ?? null,
      handoffHref: a?.getAttribute('href') ?? null,
      handoffBlockWidth: link ? Math.round(link.getBoundingClientRect().width) : null,
      ctaFontSize: px(a, 'fontSize'),
      leadFontSize: px(link?.querySelector('.rl'), 'fontSize'),
    };
  });
  console.log(JSON.stringify(m, null, 1));

  const link = await page.$('.rtnlink');
  if (link) await link.scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);
  await page.screenshot({ path: out, fullPage: false });
  await browser.close();
})();
