/*
 * Lane RJ — compose docs/rj-email-previews/OVERVIEW-2.html (round 1b, after
 * the owner's decisions of 3 October 2026) and photograph it as OVERVIEW-2.png.
 *
 *   node tools/rj-overview.cjs
 *
 * Every email template, each labelled with what sends it and whether it is on,
 * then the admin mockups that changed in this round. Reads after/index.json and
 * marketing/index.json, which tools/rj-build-after.cjs writes, so a label can
 * never drift from the email it describes.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const D = path.resolve(__dirname, '../docs/rj-email-previews');
const after = JSON.parse(fs.readFileSync(path.join(D, 'after/index.json'), 'utf8'));
const mkt = JSON.parse(fs.readFileSync(path.join(D, 'marketing/index.json'), 'utf8'));

const title = (n) => n.replace(/^[a-z]?\d+-/, '').replace(/-/g, ' ').replace(/^./, (c) => c.toUpperCase());
const card = (src, name, trigger, state, href) => `<figure><a href="${href}"><img src="${src}" alt="${name}"></a>
<figcaption><b>${name}</b><span class="trig">${trigger}</span>${state ? `<span class="state">${state}</span>` : ''}</figcaption></figure>`;

const ADMIN = [
  ['e2-sending', 'Emails → Sending & delivery', 'Two choices only: this server’s mail, or Google Workspace (smtp.gmail.com, 587 TLS, app password). Send test.'],
  ['e3-customer-emails', 'Emails → Customer emails', 'Status emails on; on hold manual; two “Complete your order” reminders.'],
  ['e5-branding', 'Emails → Design & branding', 'Look A; logo, colours; Dubai + Korea addresses, WhatsApp, email — editable any time.'],
  ['o1-order-status', 'Store → Orders → an order', '“Email the customer” tick on every status change; “Send on-hold email” button.'],
  ['m3-groups', 'Email Marketing → Customer groups', 'Customers and Subscribers apart; total spent, orders, emirate, month / year / range, brands bought.'],
  ['m2-builder', 'Email Marketing → Builder', 'Product grid auto-filled with the group’s top brand (Medicube).'],
  ['m4-review-send', 'Email Marketing → Review & send', 'Send and schedule: Owner and Manager (Administrator) only.'],
];

const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Emails — round 2</title>
<style>
:root{--ink:#2A2228;--ink2:#5E545A;--muted:#8C828A;--deep:#C13E63;--cream:#FFF8F5;--line:#F0E4E9;--green:#2E9E6B;--amber:#B86E12}
body{margin:0;background:var(--cream);font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:var(--ink)}
main{max-width:1000px;margin:0 auto;padding:26px 16px 36px}
h1{font-family:Georgia,serif;font-size:30px;margin:0}h1 span{color:var(--deep)}
h2{font-size:12.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--deep);margin:30px 0 4px}
p{font-size:14px;color:var(--ink2);line-height:1.55;margin:6px 0 12px;max-width:760px}
.g{display:grid;gap:12px;grid-template-columns:repeat(4,minmax(0,1fr))}.g2{grid-template-columns:repeat(2,minmax(0,1fr))}
figure{margin:0;background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden}
figure img{display:block;width:100%;height:440px;object-fit:cover;object-position:top}
.g2 figure img{height:420px}
figcaption{padding:8px 10px 10px;font-size:12px;line-height:1.4}
figcaption b{display:block;font-size:13px}.trig{display:block;color:var(--ink2);margin-top:2px}
.state{display:inline-block;margin-top:5px;font-size:11px;font-weight:700;padding:1px 8px;border-radius:99px;background:#E6F5EE;color:var(--green)}
ul{margin:4px 0 0;padding-left:18px;font-size:13.5px;color:var(--ink2);line-height:1.6}
@media(max-width:700px){.g,.g2{grid-template-columns:repeat(2,minmax(0,1fr))}figure img{height:300px}}
</style></head><body><main>
<h1>K-Beauty Bliss <span>emails</span> — round 2</h1>
<p>Your decisions of 3 October, applied to the previews. Look A everywhere; “View this email in your browser” moved to the very bottom; no receipt before payment; two “Complete your order” reminders; order number = tracking number; Dubai and Korea addresses, WhatsApp and info@kbeautybliss.com in every footer. Nothing is live yet.</p>
<ul><li>Addresses show as <b>[Dubai address]</b> and <b>[Korea address — owner to paste]</b> until you type them in Emails → Design &amp; branding.</li>
<li>Medicube products and prices in the brand campaign are placeholders; the real email fills them from the catalogue.</li></ul>

<h2>Every customer email · ${Object.keys(after).length}</h2>
<p>Each card: the email, what sends it, and whether it is on. Tap a picture for the full email.</p>
<div class="g">${Object.entries(after).map(([n, v]) => card(`after/shots/${n}-390.jpg`, title(n), v.trigger, v.default, `after/${n}.html`)).join('')}</div>

<h2>Marketing campaigns · ${Object.keys(mkt).length}</h2>
<div class="g">${Object.entries(mkt).map(([n, v]) => card(`marketing/shots/${n}-390.jpg`, title(n), v.trigger, '', `marketing/${n}.html`)).join('')}</div>

<h2>Admin screens that changed</h2>
<div class="g g2">${ADMIN.map(([f, t, d]) => card(`admin/${f}-1280.jpg`, t, d, '', `admin/${f}.html`)).join('')}${card('admin/m0-phone-menu-390.jpg', 'The menu on a phone', 'Emails after Store; Email Marketing first in Growth &amp; Marketing.', '', 'admin/m0-phone-menu-390.jpg')}</div>
</main></body></html>`;

(async () => {
  fs.writeFileSync(path.join(D, 'OVERVIEW-2.html'), html);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await browser.newPage({ viewport: { width: 1000, height: 1000 }, deviceScaleFactor: 1 });
  await page.goto('file://' + path.join(D, 'OVERVIEW-2.html'), { waitUntil: 'load' });
  await page.waitForTimeout(500);
  await page.screenshot({ path: path.join(D, 'OVERVIEW-2.png'), fullPage: true });
  const m = await page.evaluate(() => ({ height: document.documentElement.scrollHeight, scrollWidth: document.documentElement.scrollWidth, broken: [...document.images].filter((i) => !i.naturalWidth).map((i) => i.src) }));
  console.log(JSON.stringify({ overview: 'OVERVIEW-2.png', width: 1000, ...m }));
  await browser.close();
})();
