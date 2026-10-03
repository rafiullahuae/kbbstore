/*
 * Lane RJ — compose docs/rj-email-previews/OVERVIEW.html from the shots, and
 * photograph it as OVERVIEW.png (1200px wide) so the owner can review the
 * whole proposal on a phone in one scroll.
 *
 *   node tools/rj-overview.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const D = path.resolve(__dirname, '../docs/rj-email-previews');

const fig = (src, label, sub = '', h = 520) => `<figure><a href="${src}"><img src="${src}" style="height:${h}px" alt="${label}"></a><figcaption><b>${label}</b>${sub ? `<span>${sub}</span>` : ''}</figcaption></figure>`;

const AFTER = [
  ['01-order-confirmation', 'Order confirmed', 'processing · placed'],
  ['02-order-pending-payment', 'Complete your payment', 'pending · NEW'],
  ['03-order-on-hold', 'On hold', 'onhold · NEW'],
  ['04-order-shipped', 'Shipped', 'shipped'],
  ['05-order-delivered', 'Delivered', 'completed · NEW'],
  ['06-order-cancelled', 'Cancelled', 'cancelled'],
  ['07-order-refunded', 'Refund sent', 'refund settles'],
  ['08-order-payment-failed', 'Payment failed', 'failed · NEW'],
  ['09-new-order-alert', 'New order alert', 'to you'],
  ['10-order-invoice', 'Invoice', 'button on order'],
  ['11-back-in-stock', 'Back in stock', 'module'],
  ['12-cart-recovery', 'Basket reminder', 'module'],
  ['13-account-invite', 'Account invite', 'Customers'],
  ['14-newsletter-confirm', 'Newsletter confirm', 'double opt-in'],
  ['15-quiz-plan', 'Skin quiz plan', 'quiz'],
  ['16-password-reset', 'Password reset', 'account'],
  ['17-verify-email', 'Verify email', 'account'],
];
const BEFORE = [
  ['01-order-confirmation', 'Order confirmation'], ['03-order-shipped', 'Shipped'], ['04-order-cancelled', 'Cancelled'],
  ['08-cart-recovery', 'Basket reminder'], ['10-newsletter-confirm', 'Newsletter confirm'], ['12-password-reset', 'Password reset'],
];
const ADMIN = [
  ['e1-emails-overview', 'Emails → Overview'], ['e2-sending', 'Emails → Sending & delivery'], ['e3-customer-emails', 'Emails → Customer emails'],
  ['e4-template-editor', 'Emails → Customer emails → Edit'], ['e5-branding', 'Emails → Design & branding'], ['e6-sent-mail', 'Emails → Sent mail'],
  ['m1-campaigns', 'Email Marketing → Campaigns'], ['m2-builder', 'Email Marketing → Builder'], ['m3-groups', 'Email Marketing → Customer groups'],
  ['m4-review-send', 'Email Marketing → Review & send'], ['m5-report', 'Email Marketing → Report'],
];

const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Emails proposal</title>
<style>
:root{--ink:#2A2228;--ink2:#5E545A;--pink:#E0567B;--deep:#C13E63;--cream:#FFF8F5;--line:#F0E4E9}
body{margin:0;background:var(--cream);font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:var(--ink)}
main{max-width:1200px;margin:0 auto;padding:28px 16px 40px}
h1{font-family:Georgia,serif;font-size:34px;margin:0}h1 span{color:var(--deep)}
h2{font-size:13px;letter-spacing:.14em;text-transform:uppercase;color:var(--deep);margin:34px 0 4px}
p.lead{font-size:15px;color:var(--ink2);margin:6px 0 0;max-width:820px;line-height:1.55}
p.note{font-size:13.5px;color:var(--ink2);margin:2px 0 12px;line-height:1.5}
.g{display:grid;gap:14px}.g6{grid-template-columns:repeat(6,1fr)}.g3{grid-template-columns:repeat(3,1fr)}.g2{grid-template-columns:repeat(2,1fr)}
figure{margin:0;background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
figure img{display:block;width:100%;object-fit:cover;object-position:top}
figcaption{padding:8px 10px;font-size:12.5px;line-height:1.35}figcaption b{display:block}figcaption span{color:#8C828A;font-size:11.5px}
.pick{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.pick b{background:#fff;border:1px solid var(--line);border-radius:99px;padding:5px 12px;font-size:13px}
.pick b.on{background:var(--deep);color:#fff;border-color:var(--deep)}
@media(max-width:700px){.g6{grid-template-columns:repeat(2,1fr)}.g3,.g2{grid-template-columns:1fr}}
</style></head><body><main>
<h1>K-Beauty Bliss <span>emails</span> — first previews</h1>
<p class="lead">Lane RJ, Phase 1: audit, previews and plan. Nothing here is live. Read <b>docs/EMAILS-AUDIT.md</b> for what the shop sends today and <b>docs/EMAILS-PLAN.md</b> for how it gets built.</p>

<h2>1 · Today — what customers get now</h2>
<p class="note">Rendered through the shop’s real email code with a test order (3 products, coupon GLOW10, Tabby, Dubai Marina). Order emails are branded; seven others (basket, back-in-stock, invite, newsletter, quiz, password reset, verify) are plain black-and-white with no logo.</p>
<div class="g g6">${BEFORE.map(([f, l]) => fig(`before/shots/${f}-390.png`, l, 'today', 460)).join('')}</div>

<h2>2 · Pick a look — reply with a letter</h2>
<p class="note">The same order confirmation in three directions. <b>A</b> is the default and is used for every email below.</p>
<div class="pick"><b class="on">A · Blush editorial (default)</b><b>B · Bold pink</b><b>C · Minimal luxe</b></div><div style="height:12px"></div>
<div class="g g3">${['A', 'B', 'C'].map((t) => fig(`directions/shots/${t}-order-confirmation-600.png`, `Direction ${t}`, '600px · laptop / tablet', 900)).join('')}</div>
<div style="height:14px"></div>
<div class="g g6">${['A', 'B', 'C'].map((t) => fig(`directions/shots/${t}-order-confirmation-390.png`, `${t} on a phone`, '390px', 560)).join('')}${fig('directions/shots/A-order-confirmation-390-dark.png', 'A in dark mode', 'Apple / iOS Mail', 560)}</div>

<h2>3 · Proposed — every email, one per real order status</h2>
<p class="note">Status hero + progress tracker (Placed → Confirmed → On its way → Delivered), product pictures, totals, address, payment and a help box. Statuses are the shop’s real ones: pending, processing, onhold, shipped, completed, cancelled, refunded, failed (draft never emails). Four are new.</p>
<div class="g g6">${AFTER.map(([f, l, s]) => fig(`after/shots/${f}-390.png`, l, s, 520)).join('')}</div>

<h2>4 · Admin — new “Emails” menu</h2>
<p class="note">Store → Mail moves here and grows: sending, every customer email with on/off and an editor with live preview, design &amp; branding, sent mail.</p>
<div class="g g3">${fig('admin/m0-phone-menu-390.png', 'The menu (phone)', 'Emails after Store; Email Marketing in Growth & Marketing', 520)}${ADMIN.slice(0, 5).map(([f, l]) => fig(`admin/${f}-1280.png`, l, 'proposal', 520)).join('')}</div>

<h2>5 · Growth &amp; Marketing → Email Marketing</h2>
<p class="note">Campaigns, a drag-and-drop builder (header, hero image, text, buttons, product rows and grids, coupons, images, columns, footer with unsubscribe), customer groups by orders / spend / last order / never ordered / newsletter, schedule and send, and a report.</p>
<div class="g g3">${ADMIN.slice(6).map(([f, l]) => fig(`admin/${f}-1280.png`, l, 'proposal', 520)).join('')}${fig('marketing/shots/m1-campaign-autumn-glow-390.png', 'A campaign built from the blocks', 'phone', 520)}</div>
</main></body></html>`;

(async () => {
  fs.writeFileSync(path.join(D, 'OVERVIEW.html'), html);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await browser.newPage({ viewport: { width: 1200, height: 1000 }, deviceScaleFactor: 1 });
  await page.goto('file://' + path.join(D, 'OVERVIEW.html'), { waitUntil: 'load' });
  await page.waitForTimeout(500);
  await page.screenshot({ path: path.join(D, 'OVERVIEW.png'), fullPage: true });
  const h = await page.evaluate(() => document.documentElement.scrollHeight);
  console.log(JSON.stringify({ overview: 'OVERVIEW.png', width: 1200, height: h }));
  await browser.close();
})();
