// 2.60.504: the phone menu's account box, visitor (E5) and signed in (E6), at 390 and 360.
const { chromium } = require('playwright');
const B = process.env.KBB_PREVIEW || 'http://127.0.0.1:11900';
const out = 'docs/lane-co13-shots/menu-account-504/';
async function shot(p, name) {
  await p.goto(B + '/', { waitUntil: 'networkidle' });
  await p.evaluate(() => document.getElementById('burger').click());
  await p.waitForTimeout(500);
  const r = await p.evaluate(() => {
    const body = document.getElementById('mmBody'); body.scrollTop = body.scrollHeight;
    const box = document.querySelector('.mm-acs, .mm-acx');
    const links = [...box.querySelectorAll('a,button')].map(a => {
      const b = a.getBoundingClientRect(); const hit = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
      return { t: (a.textContent.trim() || a.getAttribute('aria-label')), h: Math.round(b.height), clickable: a.contains(hit) };
    });
    return { box: box.className, height: Math.round(box.getBoundingClientRect().height), scrollWidth: document.documentElement.scrollWidth, links };
  });
  await p.waitForTimeout(150);
  await p.screenshot({ path: out + name + '.png' });
  return r;
}
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const w of [390, 360]) {
    const c = await b.newContext({ viewport: { width: w, height: 800 }, isMobile: true, hasTouch: true });
    const p = await c.newPage(); const errs = []; p.on('pageerror', e => errs.push(String(e)));
    p.on('console', m => m.type() === 'error' && errs.push(m.text()));
    console.log(w, 'guest', JSON.stringify(await shot(p, w + '-guest')), errs);
    await p.goto(B + '/my-account/', { waitUntil: 'networkidle' });
    await p.fill('input[name=email]', 'si-test@example.com'); await p.fill('input[name=password]', 'right-password-1');
    await Promise.all([p.waitForNavigation(), p.evaluate(() => document.querySelector('input[name=password]').form.requestSubmit())]);
    console.log(w, 'signed-in', JSON.stringify(await shot(p, w + '-signed-in')), errs);
    await c.close();
  }
  await b.close();
})();
