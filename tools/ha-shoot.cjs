// Lane HA: full homepage at 1280 and 390, plus the measured numbers row 55
// asks for — each new section's height, cards per row (counted from the
// rendered cards' own top edges, read once after load, nothing resized),
// visible cards, and scrollWidth against the viewport.
const { chromium } = require('playwright');
(async () => {
  const base = process.argv[2] || 'http://127.0.0.1:9871';
  const out = process.argv[3] || 'docs/ha-shots';
  const tag = process.argv[4] || 'home';
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    const p = await b.newPage({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: 1 });
    await p.goto(base + '/', { waitUntil: 'networkidle' });
    // Scroll through so every lazy image loads before the full-page shot.
    await p.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 150)); } window.scrollTo(0, 0); });
    await p.waitForTimeout(1500);
    const m = await p.evaluate(() => {
      const rows = [];
      document.querySelectorAll('.kbb-home > section').forEach(s => {
        const cs = getComputedStyle(s);
        if (cs.display === 'none') return;
        const h2 = s.querySelector('h2');
        const r = s.getBoundingClientRect();
        const cards = [...s.querySelectorAll('.kbb-pgrid > *, .hs-brandlist > *, .hs-posts > *')].filter(c => getComputedStyle(c).display !== 'none');
        const tops = {};
        cards.forEach(c => { const t = Math.round(c.getBoundingClientRect().top); tops[t] = (tops[t] || 0) + 1; });
        rows.push({ cls: s.className.replace(/\s+/g, ' ').trim().slice(0, 60), h2: h2 ? h2.textContent.trim().slice(0, 50) : null,
          height: Math.round(r.height), visible: cards.length, perRow: Object.values(tops)[0] || 0, rows: Object.keys(tops).length });
      });
      return { scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
        h1: document.querySelectorAll('h1').length, pageHeight: document.documentElement.scrollHeight, sections: rows };
    });
    console.log(w, JSON.stringify(m, null, 1));
    await p.screenshot({ path: `${out}/${tag}-${w}.png`, fullPage: true });
    await p.close();
  }
  await b.close();
})();
// Per-section close-ups: node tools/ha-shoot.cjs <base> <out> sections
