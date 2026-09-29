const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args:['--ignore-certificate-errors'] });
  const rows = [];
  for (const w of [360, 390, 412, 519, 520, 600, 767, 768, 900, 1023, 1024, 1280, 1350, 1440, 1680, 1920, 2560]) {
    const ctx = await b.newContext({ viewport: { width: w, height: 900 } });
    const p = await ctx.newPage();
    await p.goto('http://127.0.0.1:8991/', { waitUntil: 'domcontentloaded' });
    await p.addStyleTag({ content: '*,*::before,*::after{animation:none !important}' });
    rows.push(await p.evaluate((vw) => {
      const vp = document.querySelector('.kbbn-vp');
      const c = document.querySelector('.kbbn-c');
      const wrap = vp ? vp.closest('.wrap') : null;
      const cs = vp ? getComputedStyle(vp) : null;
      const inner = vp ? vp.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight) : null;
      return {
        vw,
        innerWidth: window.innerWidth,
        wrapContent: wrap ? +(wrap.clientWidth - parseFloat(getComputedStyle(wrap).paddingLeft) * 2).toFixed(2) : null,
        cqi: inner === null ? null : +inner.toFixed(2),
        card: c ? +c.getBoundingClientRect().width.toFixed(2) : null,
        per: cs ? cs.getPropertyValue('--kbbn-per').trim() : null,
        peek: cs ? cs.getPropertyValue('--kbbn-peek').trim() : null,
        gap: cs ? cs.getPropertyValue('--kbbn-gap').trim() : null,
        gutter: cs ? getComputedStyle(document.documentElement).getPropertyValue('--site-gutter').trim() : null,
      };
    }, w));
    await ctx.close();
  }
  await b.close();
  console.log(JSON.stringify(rows, null, 0).replace(/},/g, '},\n'));
})();
