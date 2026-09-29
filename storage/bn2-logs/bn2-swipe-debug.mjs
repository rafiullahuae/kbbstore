import { chromium } from 'playwright';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const c = await b.newContext({ viewport: { width: 1280, height: 900 } });
const p = await c.newPage();
await p.goto('http://127.0.0.1:8731/', { waitUntil: 'networkidle' });
await p.locator('.kbbs').first().scrollIntoViewIfNeeded();
await p.waitForTimeout(400);
await p.evaluate(() => {
  window.__ev = [];
  const vp = document.querySelector('.kbbs-vp');
  ['pointerdown','pointermove','pointerup','pointercancel','dragstart','click'].forEach(t =>
    vp.addEventListener(t, (e) => window.__ev.push(t + ':' + Math.round(e.clientX || 0)), true));
});
const f = await p.locator('.kbbs-vp').boundingBox();
console.log('box', f, 'viewport', await p.evaluate(() => [innerWidth, innerHeight, scrollY]));
await p.mouse.move(f.x + f.width * 0.75, f.y + f.height / 2);
await p.mouse.down();
await p.mouse.move(f.x + f.width * 0.25, f.y + f.height / 2, { steps: 12 });
await p.mouse.up();
await p.waitForTimeout(600);
console.log('events', await p.evaluate(() => window.__ev));
console.log('i', await p.evaluate(() => document.querySelector('.kbbs-tr').style.getPropertyValue('--kbbs-i')));
await b.close();
