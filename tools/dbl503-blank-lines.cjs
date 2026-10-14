// 2.60.504: description spacing before/after at the live shop's 14px / 1.32 (single Enter 0, double Enter 18px).
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = 'docs/lane-co13-shots/blank-line-504/';
  for (const w of [1280, 390]) {
    for (const mode of ['before', 'after']) {
      const p = await b.newPage({ viewport: { width: w, height: 900 } });
      const errs = []; p.on('console', m => m.type() === 'error' && errs.push(m.text())); p.on('pageerror', e => errs.push(String(e)));
      await p.goto('http://127.0.0.1:11900/product/relief-sun-rice-probiotics-spf50/', { waitUntil: 'networkidle' });
      const r = await p.evaluate(([mode, w]) => {
        document.documentElement.style.setProperty('--pl-body-s', '14px'); document.documentElement.style.setProperty('--pl-body-lh', '1.32');
        if (mode === 'before') document.querySelectorAll('p.kbb-bl').forEach(e => { e.className = 'p2'; e.innerHTML = '<br>'; });
        const box = [...document.querySelectorAll('.details .dcontent')].find(e => e.offsetParent && e.textContent.includes('Made in Korea'));
        box.id = 'dblbox';
        box.classList.remove('clamp'); box.style.maxHeight = 'none';
        const ps = [...box.querySelectorAll('p')];
        const find = t => ps.find(x => x.textContent.trim().startsWith(t));
        const gap = (a, c) => Math.round(find(c).getBoundingClientRect().top - find(a).getBoundingClientRect().bottom);
        // Ink-ish gap: from one text line's box to the next.
        const lastLi = q => [...box.querySelectorAll('ul')][q].lastElementChild;
        box.scrollIntoView();
        return {
          madeToHero: gap('Made in Korea', 'Hero Ingredients'),
          listToHowTo: Math.round(find('How to Use').getBoundingClientRect().top - lastLi(1).getBoundingClientRect().bottom),
          headToList: Math.round(box.querySelector('ul').getBoundingClientRect().top - find('Key Benefits').getBoundingClientRect().bottom),
          fontSize: getComputedStyle(find('Made in Korea')).fontSize,
          boxHeight: Math.round(box.getBoundingClientRect().height),
          scrollWidth: document.documentElement.scrollWidth,
        };
      }, [mode, w]);
      const el = await p.$('#dblbox');
      await el.screenshot({ path: `${out}${w}-${mode}.png` });
      console.log(w, mode, JSON.stringify(r), 'errors:', JSON.stringify(errs));
      await p.close();
    }
  }
  await b.close();
})();
