const { chromium } = require('playwright');
const base = '.kft-bot .kbb-lang{font-size:12.5px}@media (max-width:900px){.kft-bot .kbb-lang{flex-basis:100%;justify-content:center;margin-block:2px}}';
const opts = {
  'now': '',
  'P1': base + '.kft-bot .kbb-lang{gap:0;border:1px solid #EADDE2;border-radius:99px;padding:3px;background:#fff}.kft-bot .kbb-lang>*{padding:4px 14px;border-radius:99px;line-height:1.2;text-decoration:none!important;color:inherit}.kft-bot .kbb-lang>[aria-current]{background:#C13E63;color:#fff;font-weight:700}@media (max-width:900px){.kft-bot .kbb-lang{flex-basis:auto;margin-inline:calc(50% - 90px)}}',
  'P2': base + '.kft-bot .kbb-lang>[aria-current]{display:none}.kft-bot .kbb-lang>a{display:inline-flex;align-items:center;gap:6px;border:1px solid #EADDE2;border-radius:99px;padding:6px 14px;background:#fff;text-decoration:none;font-weight:700;color:var(--ink)}.kft-bot .kbb-lang>a::before{content:"";inline-size:14px;block-size:14px;background:currentColor;-webkit-mask:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27black%27 stroke-width=%272%27%3E%3Ccircle cx=%2712%27 cy=%2712%27 r=%279%27/%3E%3Cpath d=%27M3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18%27/%3E%3C/svg%3E") center/contain no-repeat;mask:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27black%27 stroke-width=%272%27%3E%3Ccircle cx=%2712%27 cy=%2712%27 r=%279%27/%3E%3Cpath d=%27M3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18%27/%3E%3C/svg%3E") center/contain no-repeat}',
  'P3': base + '.kft-bot .kbb-lang{gap:6px}.kft-bot .kbb-lang>*{border:1px solid #EADDE2;border-radius:99px;padding:4px 14px;background:#fff;text-decoration:none!important;font-weight:700;color:var(--ink);line-height:1.2}.kft-bot .kbb-lang>[aria-current]{background:#FCE0E8;border-color:#F3B9CA;color:#C13E63}',
};
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }); const out = [];
 for (const [k, css] of Object.entries(opts)) for (const [path, tag] of [['/', 'en'], ['/ar/', 'ar']]) for (const w of [390, 1280]) {
  if (k === 'now' && tag === 'ar') continue;
  const p = await b.newPage({ viewport: { width: w, height: 900 } });
  await p.goto('http://127.0.0.1:9461' + path, { waitUntil: 'networkidle' });
  await p.addStyleTag({ content: css + '.kbb-wa,[class*="wa-float"],[class*="wa-bubble"],.kwa{display:none!important}' });
  const bot = p.locator('.kft-bot'); await bot.scrollIntoViewIfNeeded(); await p.waitForTimeout(200);
  const r = await bot.boundingBox();
  const link = p.locator('.kft-bot .kbb-lang a'); const lb = await link.boundingBox();
  const hit = await p.evaluate(([x, y]) => { const e = document.elementFromPoint(x, y); return e && e.closest('a') ? e.closest('a').getAttribute('href') : null; }, [lb.x + lb.width / 2, lb.y + lb.height / 2]);
  await p.screenshot({ path: `docs/lane-fls/${k}-${w}${tag === 'ar' ? '-ar' : ''}.png`, clip: { x: 0, y: Math.max(0, r.y - 30), width: w, height: r.height + 40 } });
  out.push({ k, tag, w, tap: [Math.round(lb.width), Math.round(lb.height)], hit, sw: await p.evaluate(() => document.documentElement.scrollWidth) });
  await p.close(); }
 console.log(JSON.stringify(out)); await b.close(); })();
