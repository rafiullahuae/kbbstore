/*
 * Lane RJ — ADMIN MOCKUPS for the proposed "Emails" menu and
 * "Growth & Marketing → Email Marketing", photographed INSIDE THE REAL ADMIN.
 *
 *   node tools/rj-admin-mockups.cjs http://127.0.0.1:<port>
 *
 * How: log in to the running rj preview (tools/rj-preview.sh), let the real
 * console build its real sidebar, then — in the browser only — add the
 * proposed sidebar rows and put a static mockup in #content. Nothing is
 * saved, no route exists, no file of the shop is changed. Every mockup carries
 * a "PROPOSAL" strip so a screenshot can never be mistaken for a built screen.
 *
 * Writes docs/rj-email-previews/admin/<name>-<1280|390>.png and a static
 * <name>.html (sidebar + content, scripts removed) next to admin-base.css,
 * which is the console's own first <style> block copied verbatim.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2];
const APP = path.resolve(__dirname, '..');
const OUT = path.join(APP, 'docs/rj-email-previews/admin');
const EMAILS = path.join(APP, 'docs/rj-email-previews');

/* ---------------------------------------------------------------- helpers */
function emailSrcdoc(rel) {
  let html = fs.readFileSync(path.join(EMAILS, rel), 'utf8');
  html = html.replace(/\.\.\/assets\/([a-z0-9-]+)\.png/g, (_, n) =>
    'data:image/png;base64,' + fs.readFileSync(path.join(EMAILS, 'assets', n + '.png')).toString('base64'));
  return html.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
}
const frame = (rel, h = 900, w = '100%') => `<iframe class="rjm-frame" style="height:${h}px;width:${w}" data-rel="../${rel}" srcdoc="${emailSrcdoc(rel)}"></iframe>`;
const tog = (on, lock = false) => `<span class="tog${on ? ' on' : ''}${lock ? ' lock' : ''}"></span>`;
const pill = (cls, t) => `<span class="pill ${cls}">${t}</span>`;
const proposal = (where) => `<div class="rjm-proposal"><b>PROPOSAL · static mockup</b> — nothing on this screen is built or saved. Where it lives: <b>${where}</b></div>`;
const field = (label, value, help = '', type = 'input') => `<label class="rjm-f"><span class="rjm-l">${label}</span>${type === 'area'
  ? `<textarea class="rjm-in" rows="4">${value}</textarea>` : type === 'select' ? `<span class="rjm-in rjm-sel">${value}</span>` : `<span class="rjm-in">${value}</span>`}${help ? `<span class="rjm-h">${help}</span>` : ''}</label>`;

const CSS = `
span.tog{display:inline-block;vertical-align:middle}
.rjm-proposal{background:#fff6e6;border:1px dashed #e0a43a;color:#7a4d08;border-radius:12px;padding:10px 14px;font-size:12.5px;margin-bottom:16px}
.rjm-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px}
.rjm-tab{font-size:13px;font-weight:600;padding:8px 14px;border-radius:99px;border:1px solid var(--border);background:var(--surface);color:var(--ink-2)}
.rjm-tab.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.rjm-grid{display:grid;gap:14px}
.rjm-g2{grid-template-columns:1fr 1fr}.rjm-g3{grid-template-columns:repeat(3,1fr)}.rjm-g4{grid-template-columns:repeat(4,1fr)}
.rjm-split{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.15fr);gap:16px;align-items:start}
.rjm-builder{display:grid;grid-template-columns:200px minmax(0,1fr) 270px;gap:14px;align-items:start}
.rjm-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh-s);padding:18px}
.rjm-card h3{font-size:14.5px;font-weight:700;margin:0 0 4px}.rjm-card p.d{font-size:12.5px;color:var(--ink-soft);margin:0 0 12px}
.rjm-f{display:block;margin:0 0 12px}.rjm-l{display:block;font-size:12px;font-weight:600;color:var(--ink-2);margin-bottom:5px}
.rjm-in{display:block;border:1px solid var(--border);border-radius:10px;padding:9px 11px;font-size:13px;background:#fff;color:var(--ink);min-height:18px;width:100%;box-sizing:border-box;font-family:inherit}
.rjm-sel{background-image:linear-gradient(45deg,transparent 50%,#8a93a6 50%),linear-gradient(135deg,#8a93a6 50%,transparent 50%);background-position:calc(100% - 16px) 50%,calc(100% - 11px) 50%;background-size:5px 5px;background-repeat:no-repeat}
.rjm-h{display:block;font-size:11.5px;color:var(--ink-faint);margin-top:4px}
.rjm-kpi{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:14px 16px}
.rjm-kpi b{display:block;font-size:22px;letter-spacing:-.02em}.rjm-kpi span{font-size:12px;color:var(--ink-soft)}
.rjm-tbl{width:100%;border-collapse:collapse}.rjm-tbl td,.rjm-tbl th{vertical-align:middle}
.rjm-tbl .sub{font-size:11.5px;color:var(--ink-faint)}
.rjm-grp td{background:var(--surface-2);font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-soft);padding:8px 12px}
.rjm-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.rjm-sp{flex:1}
.rjm-frame{border:1px solid var(--border);border-radius:14px;background:#fff;display:block}
.rjm-phone{width:390px;max-width:100%;margin:0 auto}
.rjm-dev{display:inline-flex;border:1px solid var(--border);border-radius:99px;overflow:hidden}
.rjm-dev span{font-size:12px;font-weight:600;padding:6px 12px;color:var(--ink-soft)}.rjm-dev span.on{background:var(--ink);color:#fff}
.rjm-tag{display:inline-block;font-size:11.5px;font-weight:600;background:var(--surface-3);border-radius:7px;padding:3px 8px;margin:0 4px 4px 0;color:var(--ink-2);font-family:var(--mono)}
.rjm-blocks .b{display:flex;align-items:center;gap:9px;border:1px solid var(--border);background:#fff;border-radius:10px;padding:9px 10px;margin-bottom:7px;font-size:12.5px;font-weight:600;cursor:grab}
.rjm-blocks .b i{width:24px;height:24px;border-radius:7px;background:var(--accent-soft);color:var(--accent-ink);display:grid;place-items:center;font-style:normal;font-size:12px}
.rjm-blocks .b.lock{opacity:.7}
.rjm-canvas{position:relative}
.rjm-sel-outline{position:absolute;left:24px;right:24px;border:2px solid #3f6fe0;border-radius:12px;pointer-events:none}
.rjm-sel-outline em{position:absolute;top:-24px;left:-2px;background:#3f6fe0;color:#fff;font-size:11px;font-style:normal;font-weight:700;padding:3px 8px;border-radius:7px 7px 0 0}
.rjm-rule{display:grid;grid-template-columns:1.2fr 1fr 1fr auto;gap:8px;align-items:center;margin-bottom:8px}
.rjm-x{width:30px;height:30px;border-radius:9px;border:1px solid var(--border);display:grid;place-items:center;color:var(--ink-soft)}
.rjm-count{background:linear-gradient(135deg,var(--accent-soft),#fff);border:1px solid var(--border);border-radius:var(--r);padding:16px}
.rjm-count b{font-size:30px;letter-spacing:-.03em}
.rjm-check li{list-style:none;margin:0 0 8px;font-size:13px}.rjm-check{padding:0;margin:0}
.rjm-dns{font-family:var(--mono);font-size:11.5px;background:var(--surface-2);border-radius:8px;padding:7px 9px;word-break:break-all;color:var(--ink-2)}
.rjm-sw{display:inline-block;width:26px;height:26px;border-radius:8px;border:1px solid rgba(0,0,0,.08);vertical-align:middle;margin-right:6px}
.rjm-inbox{border:1px solid var(--border);border-radius:12px;padding:12px 14px;background:#fff}
.rjm-inbox .from{font-weight:700;font-size:13.5px}.rjm-inbox .subj{font-size:13px;margin-top:2px}.rjm-inbox .pre{font-size:12.5px;color:var(--ink-faint)}
.rjm-bar{height:8px;border-radius:99px;background:var(--surface-3);overflow:hidden}.rjm-bar i{display:block;height:100%;background:var(--accent);border-radius:99px}
.rjm-scroll{overflow-x:auto}
@media(max-width:880px){.rjm-split,.rjm-builder,.rjm-g2,.rjm-g3{grid-template-columns:minmax(0,1fr)}.rjm-g4{grid-template-columns:1fr 1fr}.rjm-rule{grid-template-columns:1fr 1fr}.rjm-hide-sm{display:none}}
`;

/* ------------------------------------------------------------------ screens */
const S = {};

S['e1-emails-overview'] = {
  crumb: 'Emails', title: 'Overview', nav: 'em-overview',
  html: () => `${proposal('Emails → Overview (new parent menu; replaces Store → Mail)')}
<div class="page-head"><h2>Emails</h2><p>Everything the shop sends, in one place: how it is sent, what customers receive at every order status, how it looks, and what went out.</p></div>
<div class="rjm-grid rjm-g4">
  <div class="rjm-kpi"><span>Sending through</span><b>Server mail</b>${pill('green', 'Last test: delivered 10:41')}</div>
  <div class="rjm-kpi"><span>Domain check · extrabeauty.ae</span><b>3 to check</b>${pill('amber', 'SPF · DKIM · DMARC')}</div>
  <div class="rjm-kpi"><span>Sent, last 7 days</span><b>142</b>${pill('green', '0 failed')}</div>
  <div class="rjm-kpi"><span>Customer emails switched on</span><b>11 of 15</b>${pill('grey', '4 new ones off')}</div>
</div>
<div class="sec-title">Where things are</div>
<div class="rjm-grid rjm-g2">
  <div class="rjm-card"><h3>Sending &amp; delivery</h3><p class="d">Server mail or SMTP, From and Reply-To, new-order alerts, the domain check (SPF / DKIM / DMARC) and Send a test. <b>Moved here from Store → Mail.</b></p><span class="btn sm">Open</span></div>
  <div class="rjm-card"><h3>Customer emails</h3><p class="d">One row per email and per order status — Processing, On hold, Shipped, Delivered, Cancelled, Refunded, Payment failed… On/off, edit wording, preview, send a test.</p><span class="btn sm">Open</span></div>
  <div class="rjm-card"><h3>Design &amp; branding</h3><p class="d">Logo, colours, corner style, footer, social links and the business address — once, for every email.</p><span class="btn sm ghost">Open</span></div>
  <div class="rjm-card"><h3>Sent mail</h3><p class="d">Every message and what the mail server answered, plus back-in-stock and basket reminders still waiting to go. <b>Moved here from Store → Mail.</b></p><span class="btn sm ghost">Open</span></div>
</div>
<div class="sec-title">Marketing email lives in Growth &amp; Marketing</div>
<div class="rjm-card"><p class="d" style="margin:0">Campaigns, the drag-and-drop builder and customer groups are under <b>Growth &amp; Marketing → Email Marketing</b>, so a newsletter can never be confused with an order email — and so marketing permissions stay separate from store settings.</p></div>`,
};

S['e2-sending'] = {
  crumb: 'Emails', title: 'Sending & delivery', nav: 'em-sending',
  html: () => `${proposal('Emails → Sending & delivery')}
<div class="page-head"><h2>Sending &amp; delivery</h2><p>How email leaves the shop, who it is from, and whether inboxes will trust it.</p></div>
<div class="rjm-split">
<div>
  <div class="rjm-card"><h3>How email leaves this store</h3><p class="d">Unchanged from Store → Mail. Server mail needs nothing filled in.</p>
    ${field('Send using', "Use this server's mail (default)", 'Or: a dedicated SMTP server (Cloudways’ SMTP add-on, Google Workspace, Brevo, Amazon SES, Postmark…) — recommended for marketing volume.', 'select')}
    <div class="rjm-grid rjm-g2">${field('From name', 'K Beauty Bliss')}${field('From address', 'hello@extrabeauty.ae', 'Must be on extrabeauty.ae.')}</div>
    <div class="rjm-grid rjm-g2">${field('Reply-To', 'care@extrabeauty.ae', 'A mailbox somebody reads.')}${field('New-order alerts to', 'orders@extrabeauty.ae')}</div>
    <div class="rjm-row"><span class="rjm-sp"></span><span class="btn">Save changes</span></div>
  </div>
  <div class="sec-title">Send a test</div>
  <div class="rjm-card">${field('Send a test to', 'rafi@…', 'Pick which email: a plain test, or any customer email filled with your latest order.')}
    ${field('Which email', 'Order shipped — filled with order KBB-10427', '', 'select')}
    <div class="rjm-row"><span class="btn">Send test</span>${pill('green', 'Accepted by the mail server · 10:41')}</div></div>
</div>
<div>
  <div class="rjm-card"><h3>Will inboxes trust extrabeauty.ae?</h3><p class="d">Checked live with a DNS lookup when you press the button. Copy any missing record into Cloudflare / your DNS host.</p>
    <table class="rjm-tbl"><tr><th>Record</th><th>Status</th></tr>
    <tr><td><b>SPF</b><div class="sub">Who may send for the domain</div><div class="rjm-dns">v=spf1 include:[your mail server] ~all</div></td><td>${pill('grey', 'Not checked')}</td></tr>
    <tr><td><b>DKIM</b><div class="sub">Signature proving the mail is yours</div><div class="rjm-dns">[selector]._domainkey → key from your mail provider</div></td><td>${pill('grey', 'Not checked')}</td></tr>
    <tr><td><b>DMARC</b><div class="sub">What receivers do with failures</div><div class="rjm-dns">_dmarc → v=DMARC1; p=none; rua=mailto:dmarc@extrabeauty.ae</div></td><td>${pill('grey', 'Not checked')}</td></tr>
    <tr><td><b>MX</b><div class="sub">Where replies to care@ arrive</div></td><td>${pill('grey', 'Not checked')}</td></tr>
    </table><div class="rjm-row" style="margin-top:12px"><span class="btn ghost sm">Check now</span><span class="rjm-h">The admin reads public DNS only; it never changes it.</span></div></div>
  <div class="sec-title">Marketing sending speed</div>
  <div class="rjm-card">${field('Messages per minute', '60', 'Campaigns go out in slices so the server is never flooded. 1,000 customers ≈ 17 minutes.')}
  ${field('Daily cap', '2,000', 'A safety net while the domain builds reputation.')}</div>
</div></div>`,
};

const ROW = (name, when, on, last, extra = '', lock = false) => `<tr><td><b>${name}</b>${extra ? `<div class="sub">${extra}</div>` : ''}</td><td class="rjm-hide-sm">${when}</td><td>${tog(on, lock)}</td><td class="rjm-hide-sm">${last}</td><td style="white-space:nowrap"><span class="btn ghost sm">Edit</span> <span class="btn ghost sm rjm-hide-sm">Test</span></td></tr>`;
S['e3-customer-emails'] = {
  crumb: 'Emails', title: 'Customer emails', nav: 'em-customer',
  html: () => `${proposal('Emails → Customer emails')}
<div class="page-head"><h2>Customer emails</h2><p>One row per message. The order rows follow the store’s real order statuses. Switch any of them off; edit the words and preview them on a phone.</p></div>
<div class="rjm-card rjm-scroll" style="padding:6px 6px">
<table class="rjm-tbl"><tr><th>Email</th><th class="rjm-hide-sm">Sent when</th><th>On</th><th class="rjm-hide-sm">Last sent</th><th></th></tr>
<tr class="rjm-grp"><td colspan="5">Orders — to the customer</td></tr>
${ROW('Order confirmed', 'Order placed (Processing; Cash on delivery)', true, 'Today 09:41', 'Receipt · items with pictures · totals · address')}
${ROW('Complete your payment', 'Pending for 30 min (Tabby / Tamara / card left unpaid)', false, '—', 'NEW · status <code>pending</code>')}
${ROW('Order on hold', 'Status → On hold', false, '—', 'NEW · status <code>onhold</code> · prints your reason')}
${ROW('Order shipped', 'Status → Shipped', true, 'Today 08:12', 'Status <code>shipped</code> · tracking number if entered')}
${ROW('Order delivered', 'Status → Completed', false, '—', 'NEW · status <code>completed</code> · review request')}
${ROW('Order cancelled', 'Status → Cancelled', true, 'Yesterday', 'Status <code>cancelled</code>')}
${ROW('Refund sent', 'A refund settles (full or partial)', true, '28 Sep', 'Driven by the refund, not the <code>refunded</code> status')}
${ROW('Payment failed', 'Status → Failed after a hosted payment', false, '—', 'NEW · status <code>failed</code>')}
${ROW('Invoice', 'When you press “Email invoice” on an order', true, '27 Sep', 'PDF attached')}
<tr class="rjm-grp"><td colspan="5">To you</td></tr>
${ROW('New order alert', 'Order placed', true, 'Today 09:41', 'To orders@extrabeauty.ae')}
<tr class="rjm-grp"><td colspan="5">Account &amp; sign-up</td></tr>
${ROW('Password reset', 'Customer asks for one', true, '2 Oct', 'Always on — security', true)}
${ROW('Confirm email address', 'Account created', true, '1 Oct')}
${ROW('Account invite', 'You send one from Customers', true, '20 Sep')}
${ROW('Newsletter: confirm subscription', 'Homepage sign-up (double opt-in)', true, '30 Sep')}
${ROW('Skin quiz plan', 'Quiz completed with an email', true, '29 Sep')}
<tr class="rjm-grp"><td colspan="5">Shopping reminders</td></tr>
${ROW('Back in stock', 'A product they asked about returns', false, '—', 'Module off today')}
${ROW('Basket reminder', 'Basket left with an email', false, '—', 'Module off today')}
</table></div>
<div class="rjm-h" style="margin-top:8px">Draft is internal and never emails. “Refunded” typed as a status sends nothing — the refund email follows the money.</div>`,
};

S['e4-template-editor'] = {
  crumb: 'Emails', title: 'Edit · Order shipped', nav: 'em-customer',
  html: () => `${proposal('Emails → Customer emails → Order shipped → Edit')}
<div class="rjm-row" style="margin-bottom:14px"><div class="page-head" style="margin:0"><h2>Order shipped</h2><p>Sent when an order’s status becomes Shipped.</p></div><span class="rjm-sp"></span><span class="rjm-tab on">English</span><span class="rjm-tab">العربية</span></div>
<div class="rjm-split">
<div>
 <div class="rjm-card"><h3>Words</h3><p class="d">Tap a tag to insert it. Anything you leave blank uses the built-in wording.</p>
  ${field('Subject', 'Your {store} order {order_number} is on its way')}
  ${field('Preview line (shown after the subject in the inbox)', 'It has left us and is with the courier.')}
  ${field('Headline', 'Your order is on its way')}
  ${field('Message', 'It has left us and is with the courier. Delivery in the UAE normally takes one to three working days from dispatch.', '', 'area')}
  <div><span class="rjm-tag">{first_name}</span><span class="rjm-tag">{order_number}</span><span class="rjm-tag">{store}</span><span class="rjm-tag">{total}</span><span class="rjm-tag">{tracking_number}</span><span class="rjm-tag">{courier}</span></div>
 </div>
 <div class="sec-title">Blocks in this email</div>
 <div class="rjm-card">
  ${[['Progress tracker', true], ['Tracking number box', true], ['Items with pictures', true], ['Totals', false], ['Delivery address & payment', true], ['Help box (WhatsApp · email · Instagram)', true], ['Signature', true]].map(([n, on]) => `<div class="rjm-row" style="padding:7px 0;border-bottom:1px solid var(--border-2)"><span style="font-size:13px;font-weight:600">${n}</span><span class="rjm-sp"></span>${tog(on)}</div>`).join('')}
  ${field('Button', 'Track my parcel → tracking link', '', 'select')}
 </div>
 <div class="rjm-row" style="margin-top:14px"><span class="btn ghost">Reset to default</span><span class="rjm-sp"></span><span class="btn ghost">Send test to me</span><span class="btn">Save</span></div>
</div>
<div><div class="rjm-row" style="margin-bottom:10px"><b style="font-size:13px">Live preview</b><span class="rjm-sp"></span><span class="rjm-dev"><span>Desktop</span><span class="on">Phone</span></span></div>
 <div class="rjm-phone">${frame('after/04-order-shipped.html', 1240)}</div></div>
</div>`,
};

S['e5-branding'] = {
  crumb: 'Emails', title: 'Design & branding', nav: 'em-brand',
  html: () => `${proposal('Emails → Design & branding')}
<div class="page-head"><h2>Design &amp; branding</h2><p>Set once; every customer email and every campaign template uses it.</p></div>
<div class="rjm-split"><div>
 <div class="rjm-card"><h3>Look</h3><p class="d">Pick the direction you approve from the previews (A, B or C); colours start from the shop’s own pinks.</p>
  <div class="rjm-grid rjm-g3">${['A · Blush editorial', 'B · Bold pink', 'C · Minimal luxe'].map((t, i) => `<div class="rjm-card" style="padding:12px;${i === 0 ? 'border:2px solid var(--accent)' : ''}"><b style="font-size:13px">${t}</b><div class="rjm-h">${i === 0 ? 'Default' : ''}</div></div>`).join('')}</div>
  <div style="height:12px"></div>
  ${field('Logo', '⬆ Upload PNG · 340×80 or larger · uses Business Details logo if blank', 'Wordmark “K-Beauty Bliss” is printed when no logo is set — it shows even with images blocked.')}
  <div class="rjm-f"><span class="rjm-l">Colours</span>
   <span class="rjm-sw" style="background:#E0567B"></span>Accent #E0567B &nbsp; <span class="rjm-sw" style="background:#C13E63"></span>Buttons #C13E63 &nbsp; <span class="rjm-sw" style="background:#FFF8F5"></span>Background #FFF8F5</div>
  ${field('Headline font', 'Georgia (elegant, every device has it)', 'Web fonts are not used: Gmail removes them.', 'select')}
 </div>
 <div class="sec-title">Footer</div>
 <div class="rjm-card">${field('Help box', 'WhatsApp +971 58 505 2611 · care@extrabeauty.ae · @kbeauty.bliss', 'From Store → Business Details unless you change them here.')}
  ${field('Signature', 'With love, | the K Beauty Bliss team')}
  ${field('Business postal address (required on marketing emails)', '[your trade licence address]', 'Printed in the footer of every campaign.')}</div>
</div>
<div><div class="rjm-row" style="margin-bottom:10px"><b style="font-size:13px">Preview</b><span class="rjm-sp"></span><span class="rjm-dev"><span class="on">Desktop</span><span>Phone</span></span></div>${frame('after/01-order-confirmation.html', 1180)}</div></div>`,
};

S['e6-sent-mail'] = {
  crumb: 'Emails', title: 'Sent mail', nav: 'em-log',
  html: () => `${proposal('Emails → Sent mail (moved from Store → Mail)')}
<div class="page-head"><h2>Sent mail</h2><p>Every message the shop tried to send and what the mail server said. Bodies are never stored.</p></div>
<div class="rjm-tabs"><span class="rjm-tab on">Everything</span><span class="rjm-tab">Failed</span><span class="rjm-tab">Orders</span><span class="rjm-tab">Account</span><span class="rjm-tab">Campaigns</span><span class="rjm-tab">Waiting to go out</span></div>
<div class="rjm-card rjm-scroll" style="padding:6px"><table class="rjm-tbl"><tr><th>When</th><th>Email</th><th>To</th><th>Result</th></tr>
${[['10:41', 'Test message', 'rafi@…', ['green', 'Accepted']], ['09:41', 'Order confirmed · KBB-10427', 'aisha.k…@example.com', ['green', 'Accepted']], ['09:41', 'New order alert · KBB-10427', 'orders@extrabeauty.ae', ['green', 'Accepted']], ['08:12', 'Order shipped · KBB-10419', 'mariam@…', ['green', 'Accepted']], ['Yesterday', 'Password reset', 'priya@…', ['green', 'Accepted']], ['Yesterday', 'Campaign · Autumn Glow Edit (196)', '196 customers', ['blue', '194 accepted · 2 refused']], ['28 Sep', 'Order cancelled · KBB-10388', 'sara@…', ['red', 'Refused: 550 mailbox unavailable']]].map(([w, e, t, [c, r]]) => `<tr><td>${w}</td><td><b>${e}</b></td><td>${t}</td><td>${pill(c, r)}</td></tr>`).join('')}
</table></div><div class="rjm-h" style="margin-top:8px">Sample rows. “Accepted” means the mail server took it; whether it reached the inbox or spam is decided later by the receiver.</div>`,
};

/* ----------------------------------------------------------- marketing */
const MKT_TABS = (on) => `<div class="rjm-tabs">${['Campaigns', 'Templates', 'Customer groups', 'Reports'].map((t) => `<span class="rjm-tab${t === on ? ' on' : ''}">${t}</span>`).join('')}</div>`;

S['m1-campaigns'] = {
  crumb: 'Growth & Marketing', title: 'Email Marketing', nav: 'em-mkt',
  html: () => `${proposal('Growth & Marketing → Email Marketing → Campaigns')}
<div class="rjm-row"><div class="page-head" style="margin:0"><h2>Email Marketing</h2><p>Design an email, choose who gets it, send now or later.</p></div><span class="rjm-sp"></span><span class="btn">+ New campaign</span></div>
<div style="height:14px"></div>${MKT_TABS('Campaigns')}
<div class="rjm-grid rjm-g4" style="margin-bottom:14px">
<div class="rjm-kpi"><span>Can be emailed</span><b>1,184</b><span>of 3,712 customers + 412 subscribers</span></div>
<div class="rjm-kpi"><span>Sent this month</span><b>2,410</b></div>
<div class="rjm-kpi"><span>Clicks (30 days)</span><b>6.1%</b></div>
<div class="rjm-kpi"><span>Orders from email (30 days)</span><b>AED 4,820</b></div></div>
<div class="rjm-card rjm-scroll" style="padding:6px"><table class="rjm-tbl"><tr><th>Campaign</th><th>Status</th><th class="rjm-hide-sm">Group</th><th class="rjm-hide-sm">Sent</th><th class="rjm-hide-sm">Clicks</th><th class="rjm-hide-sm">Orders</th></tr>
<tr><td><b>Autumn Glow Edit</b><div class="sub">Subject: Skin that glows back — 15% inside</div></td><td>${pill('green', 'Sent 1 Oct')}</td><td class="rjm-hide-sm">Repeat buyers</td><td class="rjm-hide-sm">196</td><td class="rjm-hide-sm">7.1%</td><td class="rjm-hide-sm">7 · AED 1,940</td></tr>
<tr><td><b>We miss you — 10% off</b><div class="sub">Subject: It has been a while, Aisha</div></td><td>${pill('blue', 'Sending 340 / 1,212')}<div class="rjm-bar" style="margin-top:6px"><i style="width:28%"></i></div></td><td class="rjm-hide-sm">Lapsed 90 days</td><td class="rjm-hide-sm">340</td><td class="rjm-hide-sm">—</td><td class="rjm-hide-sm">—</td></tr>
<tr><td><b>New in: Sun care</b></td><td>${pill('amber', 'Scheduled 8 Oct 19:00')}</td><td class="rjm-hide-sm">Newsletter subscribers</td><td class="rjm-hide-sm">—</td><td class="rjm-hide-sm">—</td><td class="rjm-hide-sm">—</td></tr>
<tr><td><b>Welcome first order</b></td><td>${pill('grey', 'Draft')}</td><td class="rjm-hide-sm">Never ordered</td><td class="rjm-hide-sm">—</td><td class="rjm-hide-sm">—</td><td class="rjm-hide-sm">—</td></tr>
</table></div><div class="rjm-h" style="margin-top:8px">Sample figures. Opens are not shown as a headline number: Apple Mail pre-loads images, so open rates are inflated and unreliable; clicks and orders are real.</div>`,
};

const BLOCKS = [['▭', 'Mini header'], ['▣', 'Hero image'], ['H', 'Heading'], ['¶', 'Text'], ['⬭', 'Button'], ['▤', 'Product row'], ['▦', 'Product grid'], ['%', 'Coupon'], ['🖼', 'Image'], ['▥', 'Columns 2 / 3'], ['—', 'Divider'], ['↕', 'Spacer'], ['@', 'Social links'], ['▁', 'Mini footer + unsubscribe']];
S['m2-builder'] = {
  crumb: 'Growth & Marketing', title: 'Email Marketing · Builder', nav: 'em-mkt',
  html: () => `${proposal('Growth & Marketing → Email Marketing → Templates → Autumn Glow Edit')}
<div class="rjm-row" style="margin-bottom:14px"><b style="font-size:16px">Autumn Glow Edit</b>${pill('grey', 'Draft · saved 10:52')}<span class="rjm-sp"></span><span class="btn ghost sm">↶</span><span class="btn ghost sm">↷</span><span class="rjm-dev"><span class="on">Desktop</span><span>Phone</span></span><span class="btn ghost sm">Send test</span><span class="btn sm">Next: choose customers →</span></div>
<div class="rjm-builder">
 <div class="rjm-card rjm-blocks" style="padding:12px"><b style="font-size:12px;color:var(--ink-soft);letter-spacing:.08em;text-transform:uppercase">Drag a block</b><div style="height:8px"></div>${BLOCKS.map(([i, n], k) => `<div class="b${k === BLOCKS.length - 1 ? ' lock' : ''}"><i>${i}</i>${n}${k === BLOCKS.length - 1 ? ' 🔒' : ''}</div>`).join('')}
 <div class="rjm-h">The footer with Unsubscribe is always in every campaign and cannot be removed.</div></div>
 <div class="rjm-canvas">${frame('marketing/m1-campaign-autumn-glow.html', 1680)}<div class="rjm-sel-outline" style="top:536px;height:676px"><em>Product grid · 4 products</em></div></div>
 <div class="rjm-card" style="padding:14px"><h3>Product grid</h3><p class="d">Prices, pictures and links come from the catalogue when the email is sent.</p>
  ${field('Products', '🔍 Search products…')}
  ${['Round Lab Birch Juice Sunscreen', 'SKIN1004 Centella Ampoule', 'Laneige Lip Sleeping Mask', 'Beauty of Joseon Glow Deep Serum'].map((n) => `<div class="rjm-row" style="font-size:12.5px;padding:6px 0;border-bottom:1px solid var(--border-2);flex-wrap:nowrap"><span>⋮⋮</span><span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${n}</span><span class="rjm-x" style="flex-shrink:0">×</span></div>`).join('')}
  <div style="height:10px"></div>
  ${field('Or fill automatically', 'Best sellers · New in · From a category · Picked by hand', '', 'select')}
  <div class="rjm-grid rjm-g2">${field('Columns', '2', '', 'select')}${field('Button text', 'Shop now')}</div>
  <div class="rjm-row" style="font-size:13px;flex-wrap:nowrap">Show sale price<span class="rjm-sp"></span>${tog(true)}</div><div class="rjm-row" style="font-size:13px;flex-wrap:nowrap;margin-top:8px">Hide sold-out<span class="rjm-sp"></span>${tog(true)}</div>
 </div>
</div>`,
};

S['m3-groups'] = {
  crumb: 'Growth & Marketing', title: 'Email Marketing · Customer groups', nav: 'em-mkt',
  html: () => `${proposal('Growth & Marketing → Email Marketing → Customer groups')}
<div class="page-head"><h2>Customer groups</h2><p>Groups update themselves: a customer joins or leaves as they order.</p></div>${MKT_TABS('Customer groups')}
<div class="rjm-split"><div>
 <div class="rjm-card"><h3>Ready-made groups</h3><p class="d">Counts are live. “Can email” leaves out anyone who unsubscribed.</p>
 <table class="rjm-tbl"><tr><th>Group</th><th>Customers</th><th>Can email</th></tr>
 ${[['Never ordered', '1,920', '612'], ['One order only', '1,104', '381'], ['Repeat buyers (2+ orders)', '688', '233'], ['VIP — spent AED 1,000+', '142', '64'], ['Lapsed — no order in 90 days', '1,212', '402'], ['Newsletter subscribers', '412', '412']].map(([g, c, e]) => `<tr><td><b>${g}</b></td><td>${c}</td><td>${e}</td></tr>`).join('')}
 </table></div></div>
<div><div class="rjm-card"><div class="rjm-row"><h3 style="margin:0">New group</h3><span class="rjm-sp"></span>${field('', 'Match ALL of these', '', 'select')}</div>
 ${field('Name', 'Good customers gone quiet')}
 ${[['Number of orders', 'is at least', '2'], ['Total spent (AED)', 'is more than', '500'], ['Last order', 'before', '1 Jul 2026'], ['Email consent', 'is', 'Subscribed or bought before']].map(([a, b, c]) => `<div class="rjm-rule"><span class="rjm-in rjm-sel">${a}</span><span class="rjm-in rjm-sel">${b}</span><span class="rjm-in">${c}</span><span class="rjm-x">×</span></div>`).join('')}
 <span class="btn ghost sm">+ Add rule</span>
 <div class="rjm-h" style="margin-top:8px">Rules: number of orders · total spent · average order · last order date · first order date · never ordered · bought a product / brand / category · used a coupon · country / city · has an account · newsletter status.</div>
 <div style="height:12px"></div>
 <div class="rjm-count"><b>214</b> customers match · <b>196</b> can be emailed<div class="rjm-h">18 unsubscribed. Spend counts paid orders only, after refunds — the same figures as Store → Customers.</div></div>
 <div style="height:10px"></div><div class="rjm-row"><span class="btn ghost sm">See the 214</span><span class="rjm-sp"></span><span class="btn sm">Save group</span></div>
</div></div></div>`,
};

S['m4-review-send'] = {
  crumb: 'Growth & Marketing', title: 'Email Marketing · Review & send', nav: 'em-mkt',
  html: () => `${proposal('Growth & Marketing → Email Marketing → New campaign → Review & send')}
<div class="page-head"><h2>Review &amp; send · Autumn Glow Edit</h2><p>Step 3 of 3 — design ✓ · customers ✓ · send</p></div>
<div class="rjm-split"><div>
 <div class="rjm-card"><h3>In the inbox</h3><div class="rjm-inbox"><div class="from">K Beauty Bliss</div><div class="subj"><b>Skin that glows back — 15% inside</b></div><div class="pre">15% off the glow edit this week — your code is inside.</div></div>
 <div style="height:12px"></div>${field('Subject', 'Skin that glows back — 15% inside', '38 characters · fits on a phone')}${field('Preview line', '15% off the glow edit this week — your code is inside.')}</div>
 <div class="sec-title">Who</div>
 <div class="rjm-card"><div class="rjm-row"><b>Repeat buyers (2+ orders)</b><span class="rjm-sp"></span>${pill('green', '196 will receive it')}</div><div class="rjm-h">Left out automatically: 18 unsubscribed · 3 bounced before · 0 complained.</div></div>
 <div class="sec-title">When</div>
 <div class="rjm-card"><div class="rjm-row" style="gap:16px"><label style="font-size:13px"><input type="radio"> Send now</label><label style="font-size:13px"><input type="radio" checked> Schedule</label></div><div style="height:10px"></div>
  <div class="rjm-grid rjm-g2">${field('Date', 'Wed 8 Oct 2026')}${field('Time (Dubai)', '19:00')}</div>
  <div class="rjm-h">Goes out at 60 a minute (about 4 minutes). Scheduled sends need the one cron line in Emails → Sending; without it, keep this page open and it sends from here.</div></div>
</div>
<div><div class="rjm-card"><h3>Before it goes</h3><ul class="rjm-check">
 <li>✅ Unsubscribe link and one-click unsubscribe header</li><li>✅ Business address in the footer</li><li>✅ Test sent to rafi@… at 10:55</li><li>✅ Every product in stock and published</li><li>✅ Coupon GLOW15 exists and expires 12 Oct</li><li>⚠️ Domain check: DMARC not found — mail may land in spam (Emails → Sending)</li></ul>
 <div class="rjm-row" style="margin-top:12px"><span class="btn ghost">Send me a test</span><span class="rjm-sp"></span><span class="btn">Schedule for 8 Oct, 19:00</span></div></div>
 <div style="height:12px"></div><div class="rjm-phone">${frame('marketing/m1-campaign-autumn-glow.html', 760)}</div></div></div>`,
};

S['m5-report'] = {
  crumb: 'Growth & Marketing', title: 'Email Marketing · Report', nav: 'em-mkt',
  html: () => `${proposal('Growth & Marketing → Email Marketing → Reports → Autumn Glow Edit')}
<div class="page-head"><h2>Autumn Glow Edit</h2><p>Sent Wed 1 Oct, 19:00–19:04 · Repeat buyers · 196 recipients</p></div>${MKT_TABS('Reports')}
<div class="rjm-grid rjm-g4">
<div class="rjm-kpi"><span>Accepted by mail servers</span><b>194</b><span>2 refused (bad address)</span></div>
<div class="rjm-kpi"><span>Clicked</span><b>14 · 7.2%</b></div>
<div class="rjm-kpi"><span>Orders within 7 days</span><b>7 · AED 1,940</b></div>
<div class="rjm-kpi"><span>Unsubscribed</span><b>1</b><span>0 spam complaints reported</span></div></div>
<div class="sec-title">Most clicked</div>
<div class="rjm-card" style="padding:6px"><table class="rjm-tbl"><tr><th>Link</th><th>Clicks</th></tr>
<tr><td>Shop the Glow Edit (button)</td><td>8</td></tr><tr><td>Round Lab Birch Juice Sunscreen</td><td>4</td></tr><tr><td>SKIN1004 Centella Ampoule</td><td>2</td></tr></table></div>
<div class="rjm-h" style="margin-top:8px">Sample figures. Opens are shown only as “at least N” — Apple Mail privacy protection opens every message itself.</div>`,
};

/* ------------------------------------------------------------- sidebar */
const ICON_MAIL = '<path d="M3 6h18v12H3z"/><path d="m3 7 9 6 9-6"/>';
const svg = (p) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${p}</svg>`;
const NAV_EMAILS = [
  ['em-overview', 'Overview', '<path d="M4 4h7v7H4zM13 4h7v4h-7zM13 10h7v10h-7zM4 13h7v7H4z"/>'],
  ['em-sending', 'Sending & delivery', '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/>'],
  ['em-customer', 'Customer emails', ICON_MAIL],
  ['em-brand', 'Design & branding', '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><path d="M12 2a10 10 0 1 0 0 20c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1.1.9-2 2-2h2.4A5.6 5.6 0 0 0 22 9.8C22 5.5 17.5 2 12 2z"/>'],
  ['em-log', 'Sent mail', '<path d="M3 12h4l3 8 4-16 3 8h4"/>'],
];

async function decorate(page, active) {
  await page.evaluate(({ items, active, mailIcon }) => {
    const nav = document.querySelector('#nav');
    // 1. Store → Mail is removed: its contents move to Emails.
    nav.querySelector('.nav-item[data-go="mail"]')?.remove();
    // 2. The new parent menu, directly after Store.
    const store = nav.querySelector('.nav-group[data-sec="Store"]');
    const g = document.createElement('div');
    g.className = 'nav-group open';
    g.dataset.sec = 'Emails';
    const chev = '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m9 6 6 6-6 6"/></svg>';
    g.innerHTML = `<button class="nav-gh"><span class="gh-name">Emails</span><span class="pill amber" style="font-size:9.5px;padding:1px 7px">new</span>${chev}</button><div class="nav-sub">${items.map(([id, n, ic]) => `<button class="nav-item${id === active ? ' on' : ''}" data-go="${id}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${ic}</svg><span>${n}</span></button>`).join('')}</div>`;
    store.after(g);
    // 3. Growth & Marketing → Email Marketing, first row of that group.
    const gm = nav.querySelector('.nav-group[data-sec="Growth & Marketing"] .nav-sub');
    const b = document.createElement('button');
    b.className = 'nav-item' + (active === 'em-mkt' ? ' on' : '');
    b.dataset.go = 'em-mkt';
    b.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">${mailIcon}<path d="M17 3l1 2 2 1-2 1-1 2-1-2-2-1 2-1z"/></svg><span>Email Marketing</span><span class="tag">new</span>`;
    gm.prepend(b);
    nav.querySelectorAll('.nav-item.on').forEach((n) => { if (n.dataset.go !== active) n.classList.remove('on'); });
    nav.querySelectorAll('.nav-group').forEach((x) => x.classList.toggle('open', x.dataset.sec === 'Emails' || (active === 'em-mkt' && x.dataset.sec === 'Growth & Marketing')));
    if (active === 'em-mkt') nav.querySelector('.nav-group[data-sec="Growth & Marketing"]').classList.add('open');
  }, { items: NAV_EMAILS, active, mailIcon: ICON_MAIL });
}

const GROW = 'html,body{height:auto!important}.app{height:auto!important;min-height:100vh}.content{overflow:visible!important}';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const blade = fs.readFileSync(path.join(APP, 'resources/views/admin/app.blade.php'), 'utf8');
  fs.writeFileSync(path.join(OUT, 'admin-base.css'), /<style>([\s\S]*?)<\/style>/.exec(blade)[1]);

  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);

  for (const [name, s] of Object.entries(S)) {
    await page.setViewportSize({ width: 1280, height: 1000 });
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    await decorate(page, s.nav);
    const html = s.html();
    await page.evaluate(({ html, crumb, title, css }) => {
      document.querySelector('#crumb').textContent = crumb;
      document.querySelector('#ptitle').textContent = title;
      document.querySelector('#content').innerHTML = `<style>${css}</style><div class="wrap">${html}</div>`;
    }, { html, crumb: s.crumb, title: s.title, css: CSS });
    await page.addStyleTag({ content: GROW });
    for (const w of [1280, 390]) {
      await page.setViewportSize({ width: w, height: 1000 });
      await page.waitForTimeout(500);
      const m = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, height: document.documentElement.scrollHeight }));
      await page.screenshot({ path: path.join(OUT, `${name}-${w}.png`), fullPage: true });
      console.log(JSON.stringify({ shot: `${name}-${w}`, ...m, overflow: m.scrollWidth > w }));
    }
    /* Static copy: the console's own CSS + this screen's DOM, no scripts. */
    /* In the static copy the preview iframes point at the committed email files
       rather than carrying them inline, which keeps each file small. */
    const app = (await page.evaluate(() => document.querySelector('.app').outerHTML))
      .replace(/ srcdoc="[^"]*"/g, '').replace(/ data-rel="/g, ' src="');
    fs.writeFileSync(path.join(OUT, `${name}.html`), `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${s.title} — proposal</title><link rel="stylesheet" href="admin-base.css"><style>${GROW}</style></head><body>${app}</body></html>\n`);
  }

  /* The phone: the sidebar open, showing the new menu. */
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  await decorate(page, 'em-customer');
  await page.evaluate(() => {
    document.querySelector('#side').classList.add('open');
    document.querySelector('.nav-group[data-sec="Growth & Marketing"]').classList.add('open');
    document.querySelector('.nav-group[data-sec="Emails"]').scrollIntoView({ block: 'start' });
  });
  await page.waitForTimeout(500);
  await page.screenshot({ path: path.join(OUT, 'm0-phone-menu-390.png') });
  await page.setViewportSize({ width: 1280, height: 1000 });
  await page.evaluate(() => document.querySelector('#side').classList.remove('open'));
  const side = await page.$('#side');
  await page.evaluate(() => document.querySelector('.nav-group[data-sec="Emails"]').scrollIntoView({ block: 'start' }));
  await side.screenshot({ path: path.join(OUT, 'm0-sidebar-proposed.png') });
  console.log('done');
  await browser.close();
})();
