/*
 * Lane PP — the same pages mirrored on /ar.
 *
 * Everything this lane wrote uses logical properties and no [dir] rule, which
 * is a claim that has to be a photograph: the list's three tracks, the seam's
 * margin-block, and all three proposals. What is measured is
 * document.documentElement.scrollWidth -- an RTL page that overflows is the
 * failure this shot exists to catch -- plus which side of the row the
 * photograph is on, read off its x against the row's.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.PP_BASE || 'http://127.0.0.1:8977';
const OUT = process.env.PP_OUT || '/home/user/lane-pp/docs/lane-pp-shots';

const M = () => {
  const row = document.querySelector('.ksl-r');
  const ph = document.querySelector('.ksl-ph');
  const desc = document.querySelector('.bb-desc');
  const next = desc ? (() => {
    let n = desc.nextElementSibling;
    while (n && n.getBoundingClientRect().height === 0) n = n.nextElementSibling;
    if (n && n.tagName === 'FORM') n = [...n.children].find((c) => c.getBoundingClientRect().height > 0) || n;
    return n;
  })() : null;
  return {
    dir: document.documentElement.getAttribute('dir'),
    lang: document.documentElement.getAttribute('lang'),
    scrollWidth: document.documentElement.scrollWidth,
    viewport: document.documentElement.clientWidth,
    // In an RTL document the photograph must be on the RIGHT of its row.
    photoOnRight: row && ph
      ? Math.round(ph.getBoundingClientRect().right) > Math.round(row.getBoundingClientRect().left + row.getBoundingClientRect().width / 2)
      : null,
    descGap: desc && next
      ? Math.round(next.getBoundingClientRect().top - desc.getBoundingClientRect().bottom)
      : null,
    memberPrices: document.querySelectorAll('.ksl-pr').length,
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  const out = [];
  const cases = [
    ['ar-set3', 'lanepp-glow-starter-set', ''],
    ['ar-plain', 'lanepp-plain-moisturiser', ''],
    ['ar-focus-set3', 'lanepp-glow-starter-set', '?layout=focus'],
    ['ar-editorial-set3', 'lanepp-glow-starter-set', '?layout=editorial'],
    ['ar-compact-set3', 'lanepp-glow-starter-set', '?layout=compact'],
  ];
  for (const [name, slug, qs] of cases) {
    for (const w of [390, 1280]) {
      await p.setViewportSize({ width: w, height: 1400 });
      await p.goto(`${BASE}/ar/product/${slug}/${qs}`, { waitUntil: 'networkidle' });
      await p.waitForTimeout(300);
      const m = await p.evaluate(M);
      await p.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });
      console.log(JSON.stringify({ shot: `${name}-${w}`, ...m }));
      out.push({ shot: `${name}-${w}`, ...m });
    }
  }
  await b.close();
  fs.writeFileSync(`${OUT}/ar-measurements.json`, JSON.stringify(out, null, 2));
})();
