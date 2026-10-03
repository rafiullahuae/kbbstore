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
  html = html.replace(/\.\.\/assets\/([a-z0-9-]+)\.(png|jpg)/g, (_, n, ext) =>
    `data:image/${ext === 'jpg' ? 'jpeg' : 'png'};base64,` + fs.readFileSync(path.join(EMAILS, 'assets', `${n}.${ext}`)).toString('base64'));
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
.rjm-choice{padding:14px;cursor:pointer}.rjm-choice.on{border:2px solid var(--accent);background:var(--accent-soft)}
.rjm-radio{width:16px;height:16px;border-radius:50%;border:2px solid var(--border);display:inline-block;flex-shrink:0}.rjm-radio.on{border:5px solid var(--accent)}
.rjm-gws{border:1px dashed var(--border);border-radius:var(--r-sm);padding:12px;opacity:.75}
.rjm-tick{display:flex;gap:9px;align-items:flex-start;margin:4px 0 0;font-size:13px}.rjm-tick .rjm-h{display:block}
.rjm-cb{width:18px;height:18px;border-radius:5px;border:1.5px solid var(--border);display:grid;place-items:center;font-size:12px;color:#fff;flex-shrink:0;margin-top:1px}.rjm-cb.on{background:var(--accent);border-color:var(--accent)}
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
  html: () => `${proposal('Emails → Sending & delivery (owner, 3 Oct: server mail or Google Workspace — nothing external)')}
<div class="page-head"><h2>Sending &amp; delivery</h2><p>How email leaves the shop and who it is from. Two ways, both already yours.</p></div>
<div class="rjm-split">
<div>
  <div class="rjm-card"><h3>Send using</h3><p class="d">Pick one. You can switch at any time; press Send test after switching.</p>
    <div class="rjm-grid rjm-g2">
      <div class="rjm-card rjm-choice on"><div class="rjm-row"><span class="rjm-radio on"></span><b>This server&rsquo;s mail</b>${pill('green', 'In use')}</div><p class="d" style="margin:6px 0 0">Sends from the website&rsquo;s own server. Nothing to fill in.</p></div>
      <div class="rjm-card rjm-choice"><div class="rjm-row"><span class="rjm-radio"></span><b>Google Workspace (Gmail SMTP)</b></div><p class="d" style="margin:6px 0 0">Sends through your Google business mailbox. Usually lands in the inbox more reliably.</p></div>
    </div>
    <div style="height:14px"></div>
    <div class="rjm-gws"><div class="rjm-l" style="margin-bottom:8px">Google Workspace settings <span class="rjm-h" style="display:inline">· shown when that option is picked</span></div>
      <div class="rjm-grid rjm-g2">${field('SMTP host', 'smtp.gmail.com')}${field('Port &amp; security', '587 · TLS', '', 'select')}</div>
      <div class="rjm-grid rjm-g2">${field('Google account (username)', 'info@kbeautybliss.com')}${field('App password', '•••• •••• •••• ••••', 'Google Account → Security → 2-Step Verification → App passwords. Not your normal password. Stored encrypted.')}</div>
      <div class="rjm-h">Google allows about 2,000 messages a day per Workspace user. Campaigns larger than that are spread over days automatically.</div>
    </div>
  </div>
  <div class="sec-title">Who it is from</div>
  <div class="rjm-card"><div class="rjm-grid rjm-g2">${field('From name', 'K Beauty Bliss')}${field('From address', 'info@kbeautybliss.com', 'With Google, this must be the Google account above or one of its aliases.')}</div>
    <div class="rjm-grid rjm-g2">${field('Replies go to', 'info@kbeautybliss.com')}${field('New-order alerts to', 'info@kbeautybliss.com')}</div>
    <div class="rjm-row"><span class="rjm-sp"></span><span class="btn">Save changes</span></div></div>
</div>
<div>
  <div class="rjm-card"><h3>Send a test</h3><p class="d">Sends through whichever option is picked, and shows what the mail server answered.</p>
    ${field('Send a test to', 'rafi@…')}
    ${field('Which email', 'Order shipped — filled with your latest order', '', 'select')}
    <div class="rjm-row"><span class="btn">Send test</span>${pill('green', 'Accepted by the mail server · 10:41')}</div></div>
  <div class="sec-title">Will inboxes trust the domain?</div>
  <div class="rjm-card"><p class="d">A read-only DNS check for the From domain. It never changes anything.</p>
    <table class="rjm-tbl"><tr><th>Record</th><th>Status</th></tr>
    <tr><td><b>SPF</b><div class="rjm-dns">v=spf1 include:_spf.google.com ~all <span class="sub">(+ the server, if you keep server mail)</span></div></td><td>${pill('grey', 'Not checked')}</td></tr>
    <tr><td><b>DKIM</b><div class="rjm-dns">google._domainkey → from Google Admin → Gmail → Authenticate email</div></td><td>${pill('grey', 'Not checked')}</td></tr>
    <tr><td><b>DMARC</b><div class="rjm-dns">_dmarc → v=DMARC1; p=none; rua=mailto:info@kbeautybliss.com</div></td><td>${pill('grey', 'Not checked')}</td></tr>
    </table><div class="rjm-row" style="margin-top:12px"><span class="btn ghost sm">Check now</span></div></div>
</div></div>`,
};

const ROW = (name, when, on, last, extra = '', lock = false, manual = false) => `<tr><td><b>${name}</b>${extra ? `<div class="sub">${extra}</div>` : ''}</td><td class="rjm-hide-sm">${when}</td><td>${manual ? pill('amber', 'Manual') : tog(on, lock)}</td><td class="rjm-hide-sm">${last}</td><td style="white-space:nowrap"><span class="btn ghost sm">Edit</span> <span class="btn ghost sm rjm-hide-sm">Test</span></td></tr>`;
S['e3-customer-emails'] = {
  crumb: 'Emails', title: 'Customer emails', nav: 'em-customer',
  html: () => `${proposal('Emails → Customer emails')}
<div class="page-head"><h2>Customer emails</h2><p>One row per message, following the shop&rsquo;s real order statuses. Switch any of them off, edit the words, preview on a phone. Any single status change can still skip its email: untick &ldquo;Email the customer&rdquo; on the order.</p></div>
<div class="rjm-card rjm-scroll" style="padding:6px 6px">
<table class="rjm-tbl"><tr><th>Email</th><th class="rjm-hide-sm">Sent when</th><th>On</th><th class="rjm-hide-sm">Last sent</th><th></th></tr>
<tr class="rjm-grp"><td colspan="5">Orders — to the customer</td></tr>
${ROW('Order confirmed', 'Paid (card, Tabby, Tamara) or placed with cash on delivery → Processing', true, 'Today 09:41', 'Never before payment')}
${ROW('Complete your order · 1', 'Still unpaid 30 minutes after it was started, any payment method', true, '—', 'NEW · status <code>pending</code>')}
${ROW('Complete your order · 2', 'Still unpaid after 24 hours — the last reminder', true, '—', 'NEW · status <code>pending</code>')}
${ROW('Order on hold', '“Send on-hold email” button on the order', false, '—', 'NEW · status <code>onhold</code> · you type the reason', false, true)}
${ROW('Order shipped', 'Status → Shipped', true, 'Today 08:12', 'Order number = tracking number · Track your order link')}
${ROW('Order delivered', 'Status → Completed', true, '—', 'NEW · status <code>completed</code>')}
${ROW('Order cancelled', 'Status → Cancelled', true, 'Yesterday', 'Status <code>cancelled</code>')}
${ROW('Refund sent', 'A refund is sent (full or part)', true, '28 Sep', 'Follows the money, not the <code>refunded</code> word')}
${ROW('Payment failed', 'Status → Failed', true, '—', 'NEW · status <code>failed</code>')}
${ROW('Invoice', '“Email invoice” button on the order', true, '27 Sep', 'PDF attached', false, true)}
<tr class="rjm-grp"><td colspan="5">To you</td></tr>
${ROW('New order alert', 'Order paid, or placed with cash on delivery', true, 'Today 09:41', 'To info@kbeautybliss.com')}
<tr class="rjm-grp"><td colspan="5">Account &amp; sign-up</td></tr>
${ROW('Password reset', 'Customer asks for one', true, '2 Oct', 'Always on — security', true)}
${ROW('Confirm email address', 'Account created', true, '1 Oct')}
${ROW('Account invite', 'You send one from Customers', true, '20 Sep', '', false, true)}
${ROW('Newsletter: confirm subscription', 'Website sign-up (double opt-in)', true, '30 Sep')}
${ROW('Skin quiz plan', 'Quiz finished with an email', true, '29 Sep')}
<tr class="rjm-grp"><td colspan="5">Shopping reminders</td></tr>
${ROW('Back in stock', 'A product they asked about returns', false, '—', 'Module off today')}
${ROW('Basket reminder', 'Basket left with an email', false, '—', 'Module off today')}
</table></div>
<div class="rjm-h" style="margin-top:8px">Draft never emails. Defaults follow the owner&rsquo;s choices of 3 October: status emails on, on hold sent by hand.</div>`,
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
 <div class="rjm-phone">${frame('after/05-order-shipped.html', 1240)}</div></div>
</div>`,
};

S['e5-branding'] = {
  crumb: 'Emails', title: 'Design & branding', nav: 'em-brand',
  html: () => `${proposal('Emails → Design & branding')}
<div class="page-head"><h2>Design &amp; branding</h2><p>Set once; every customer email and every campaign uses it. Change any of it at any time.</p></div>
<div class="rjm-split"><div>
 <div class="rjm-card"><h3>Look</h3><p class="d">Blush editorial (A) — your choice of 3 October, used by every email.</p>
  ${field('Logo', '⬆ Upload PNG or JPG · at least 340×80', 'Blank: the “K-Beauty Bliss” wordmark prints in text, which shows even when pictures are blocked.')}
  <div class="rjm-f"><span class="rjm-l">Colours</span>
   <div class="rjm-row"><span><span class="rjm-sw" style="background:#E0567B"></span>Accent</span><span><span class="rjm-sw" style="background:#C13E63"></span>Buttons</span><span><span class="rjm-sw" style="background:#FFF8F5"></span>Background</span><span><span class="rjm-sw" style="background:#2A2228"></span>Text</span></div></div>
 </div>
 <div class="sec-title">Footer of every email</div>
 <div class="rjm-card">
  ${field('Dubai address', '[Dubai address]', 'Filled from Store → Business Details (street, city) when those are set.', 'area')}
  ${field('Korea address', '[Korea address — owner to paste]', '', 'area')}
  <div class="rjm-grid rjm-g2">${field('WhatsApp', '+971 58 505 2611')}${field('Email', 'info@kbeautybliss.com')}</div>
  ${field('Instagram', '@kbeauty.bliss')}
  ${field('Signature', 'With love, | the K Beauty Bliss team')}
  <div class="rjm-row"><span class="rjm-sp"></span><span class="btn ghost">Send test</span><span class="btn">Save</span></div>
 </div>
</div>
<div><div class="rjm-row" style="margin-bottom:10px"><b style="font-size:13px">Preview</b><span class="rjm-sp"></span><span class="rjm-dev"><span>Desktop</span><span class="on">Phone</span></span></div><div class="rjm-phone">${frame('after/01-order-confirmation.html', 1300)}</div></div></div>`,
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

const BLOCKS = [['▭', 'Mini header'], ['▣', 'Hero image'], ['H', 'Heading'], ['¶', 'Text'], ['⬭', 'Button'], ['▤', 'Product row'], ['▦', 'Product grid'], ['%', 'Coupon'], ['🖼', 'Image'], ['▥', 'Columns 2 / 3'], ['—', 'Divider'], ['↕', 'Spacer'], ['@', 'Social links'], ['▁', 'Footer + unsubscribe']];
S['m2-builder'] = {
  crumb: 'Growth & Marketing', title: 'Email Marketing · Builder', nav: 'em-mkt',
  html: () => `${proposal('Growth & Marketing → Email Marketing → Templates → More Medicube for you')}
<div class="rjm-row" style="margin-bottom:14px"><b style="font-size:16px">More Medicube for you</b>${pill('grey', 'Draft · saved 10:52')}<span class="rjm-sp"></span><span class="btn ghost sm">↶</span><span class="btn ghost sm">↷</span><span class="rjm-dev"><span class="on">Desktop</span><span>Phone</span></span><span class="btn ghost sm">Send test</span><span class="btn sm">Next: choose customers →</span></div>
<div class="rjm-builder">
 <div class="rjm-card rjm-blocks" style="padding:12px"><b style="font-size:12px;color:var(--ink-soft);letter-spacing:.08em;text-transform:uppercase">Drag a block</b><div style="height:8px"></div>${BLOCKS.map(([i, n], k) => `<div class="b${k === BLOCKS.length - 1 ? ' lock' : ''}"><i>${i}</i>${n}${k === BLOCKS.length - 1 ? ' 🔒' : ''}</div>`).join('')}
 <div class="rjm-h">The footer (addresses, WhatsApp, email, Unsubscribe) is in every campaign and cannot be removed.</div></div>
 <div class="rjm-canvas">${frame('marketing/m3-campaign-medicube-fans.html', 1620)}<div class="rjm-sel-outline" style="top:418px;height:676px"><em>Product grid · auto-filled</em></div></div>
 <div class="rjm-card" style="padding:14px"><h3>Product grid</h3><p class="d">Pictures, prices and links come from the catalogue when each email is sent. Sold-out products are skipped.</p>
  ${field('Fill with', 'This group’s top brand (auto)', 'For “Mostly bought Medicube” that is Medicube. Each group gets its own brand.', 'select')}
  <div class="rjm-count" style="padding:10px 12px;margin-bottom:12px"><b style="font-size:15px">Medicube</b><div class="rjm-h">Top brand of the chosen group · 4 best-selling in-stock products</div></div>
  <div class="rjm-grid rjm-g2">${field('How many', '4', '', 'select')}${field('Columns', '2', '', 'select')}</div>
  ${field('Order by', 'Best sellers', '', 'select')}
  ${field('Button text', 'Shop now')}
  <div class="rjm-row" style="font-size:13px;flex-wrap:nowrap">Show sale price<span class="rjm-sp"></span>${tog(true)}</div>
  <div class="rjm-h" style="margin-top:10px">Other fills: picked by hand · a brand · a category · new in · best sellers.</div>
 </div>
</div>`,
};

const RULE = (a, b, c) => `<div class="rjm-rule"><span class="rjm-in rjm-sel">${a}</span><span class="rjm-in rjm-sel">${b}</span><span class="rjm-in">${c}</span><span class="rjm-x">×</span></div>`;
S['m3-groups'] = {
  crumb: 'Growth & Marketing', title: 'Email Marketing · Customer groups', nav: 'em-mkt',
  html: () => `${proposal('Growth & Marketing → Email Marketing → Customer groups')}
<div class="page-head"><h2>Customer groups</h2><p>Customers (people who ordered or have an account) and Subscribers (newsletter sign-ups) are kept as two separate lists. Groups update themselves as people order.</p></div>${MKT_TABS('Customer groups')}
<div class="rjm-tabs"><span class="rjm-tab on">Customers · 3,712</span><span class="rjm-tab">Subscribers · 412</span></div>
<div class="rjm-split"><div>
 <div class="rjm-card"><div class="rjm-row"><h3 style="margin:0">New group</h3><span class="rjm-sp"></span><span class="rjm-in rjm-sel" style="width:auto">Match ALL rules</span></div><div style="height:10px"></div>
 ${field('Name', 'Mostly bought Medicube · Dubai · 2026')}
 ${RULE('Brands bought', 'mostly', 'Medicube')}
 ${RULE('Emirate', 'is any of', 'Dubai, Abu Dhabi, Sharjah')}
 ${RULE('Order date', 'in year', '2026')}
 ${RULE('Total spent (AED)', 'at least', '500')}
 ${RULE('Number of orders', 'at least', '2')}
 <span class="btn ghost sm">+ Add rule</span>
 <div class="rjm-h" style="margin-top:10px"><b>Rules:</b> total spent · number of orders · emirate / region (Dubai, Abu Dhabi, Sharjah, Ajman, Ras Al Khaimah, Fujairah, Umm Al Quwain, outside UAE) · order date by month, by year or a custom date range · brands bought (“mostly” = the brand with the biggest share of what they spent; or “ever bought”) · never ordered · newsletter subscribed.</div>
 <div style="height:12px"></div>
 <div class="rjm-count"><b>86</b> customers match · <b>79</b> can be emailed<div class="rjm-h">7 unsubscribed. Spend counts paid orders only, after refunds. Emirate comes from the delivery address. Sample figures.</div></div>
 <div style="height:10px"></div><div class="rjm-row"><span class="btn ghost sm">See the 86</span><span class="rjm-sp"></span><span class="btn sm">Save group</span></div>
 </div></div>
<div>
 <div class="rjm-card"><h3>Order date</h3><p class="d">Pick one way to set the period.</p>
  <div class="rjm-tabs" style="margin-bottom:10px"><span class="rjm-tab">Month</span><span class="rjm-tab on">Year</span><span class="rjm-tab">Custom range</span></div>
  <div class="rjm-grid rjm-g2">${field('Month', 'September 2026', '', 'select')}${field('Year', '2026', '', 'select')}</div>
  <div class="rjm-grid rjm-g2">${field('From', '1 Jun 2026')}${field('To', '30 Sep 2026')}</div></div>
 <div style="height:14px"></div>
 <div class="rjm-card"><h3>Saved groups</h3>
 <table class="rjm-tbl"><tr><th>Group</th><th>List</th><th>Can email</th></tr>
 ${[['Mostly bought Medicube', 'Customers', '214'], ['Mostly bought COSRX', 'Customers', '168'], ['Abu Dhabi · spent AED 1,000+', 'Customers', '41'], ['Ordered in Ramadan 2026 (custom range)', 'Customers', '302'], ['Never ordered', 'Customers', '612'], ['All confirmed subscribers', 'Subscribers', '412']].map(([g, l, e]) => `<tr><td><b>${g}</b></td><td>${l}</td><td>${e}</td></tr>`).join('')}
 </table><div class="rjm-h" style="margin-top:6px">Sample figures.</div></div>
</div></div>`,
};

S['m4-review-send'] = {
  crumb: 'Growth & Marketing', title: 'Email Marketing · Review & send', nav: 'em-mkt',
  html: () => `${proposal('Growth & Marketing → Email Marketing → New campaign → Review & send')}
<div class="page-head"><h2>Review &amp; send · More Medicube for you</h2><p>Step 3 of 3 — design ✓ · customers ✓ · send</p></div>
<div class="rjm-split"><div>
 <div class="rjm-card"><h3>In the inbox</h3><div class="rjm-inbox"><div class="from">K Beauty Bliss</div><div class="subj"><b>More Medicube, just for you</b></div><div class="pre">New Medicube picks, chosen because you love the brand.</div></div>
 <div style="height:12px"></div>${field('Subject', 'More Medicube, just for you', '27 characters · fits on a phone')}${field('Preview line', 'New Medicube picks, chosen because you love the brand.')}</div>
 <div class="sec-title">Who</div>
 <div class="rjm-card"><div class="rjm-row"><b>Mostly bought Medicube</b>${pill('grey', 'Customers')}<span class="rjm-sp"></span>${pill('green', '214 will receive it')}</div><div class="rjm-h">Left out automatically: unsubscribed, and addresses that bounced before.</div></div>
 <div class="sec-title">When</div>
 <div class="rjm-card"><div class="rjm-row" style="gap:16px"><label style="font-size:13px"><input type="radio"> Send now</label><label style="font-size:13px"><input type="radio" checked> Schedule</label></div><div style="height:10px"></div>
  <div class="rjm-grid rjm-g2">${field('Date', 'Wed 8 Oct 2026')}${field('Time (Dubai)', '19:00')}</div>
  <div class="rjm-h">Goes out at 60 a minute. Scheduled sends need the one cron line; without it, keep this page open and it sends from here.</div></div>
</div>
<div><div class="rjm-card"><h3>Before it goes</h3><ul class="rjm-check">
 <li>✅ Unsubscribe link and one-click unsubscribe</li><li>✅ Dubai and Korea addresses in the footer</li><li>✅ Test sent to rafi@… at 10:55</li><li>✅ Products filled: Medicube, 4 in stock</li><li>✅ Coupon MEDI10 exists</li></ul>
 <div class="rjm-row" style="margin-top:12px"><span class="btn ghost">Send me a test</span><span class="rjm-sp"></span><span class="btn">Schedule for 8 Oct, 19:00</span></div>
 <div class="rjm-h" style="margin-top:10px">🔒 Only <b>Owner and Manager (Administrator)</b> can send or schedule a campaign.</div></div>
 <div style="height:12px"></div><div class="rjm-phone">${frame('marketing/m3-campaign-medicube-fans.html', 760)}</div></div></div>`,
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

S['o1-order-status'] = {
  crumb: 'Store', title: 'Orders · KBB-10427', nav: 'orders',
  html: () => `${proposal('Store → Orders → an order → Status (the tick exists today; the on-hold button and new defaults are proposed)')}
<div class="page-head"><h2>Order KBB-10427</h2><p>Aisha Khan · Dubai · AED 319.95 · Tabby (paid)</p></div>
<div class="rjm-split"><div>
 <div class="rjm-card"><h3>Status</h3>
  ${field('Change status to', 'Shipped', '', 'select')}
  <label class="rjm-tick"><span class="rjm-cb on">✓</span><span><b>Email the customer about this change</b><span class="rjm-h">On by default for Shipped. Untick to change the status quietly, just this once.</span></span></label>
  <div class="rjm-row" style="margin-top:12px"><span class="rjm-sp"></span><span class="btn">Update order</span></div>
 </div>
 <div style="height:14px"></div>
 <div class="rjm-card"><h3>When the status is On hold</h3><p class="d">On hold never emails by itself. Send it by hand when you need something from the customer.</p>
  ${field('Change status to', 'On hold', '', 'select')}
  <label class="rjm-tick"><span class="rjm-cb"></span><span><b>Email the customer about this change</b><span class="rjm-h">Off for On hold — use the button below instead.</span></span></label>
  ${field('What you need from the customer', 'Please confirm the building name for delivery.', 'Printed in the on-hold email.', 'area')}
  <div class="rjm-row"><span class="btn ghost">Preview</span><span class="rjm-sp"></span><span class="btn">Send on-hold email</span></div>
 </div>
</div>
<div class="rjm-card"><h3>What the tick does, per status</h3>
 <table class="rjm-tbl"><tr><th>Status</th><th>Tick starts</th></tr>
 ${[['Processing', 'ticked (receipt)'], ['Shipped', 'ticked'], ['Completed (delivered)', 'ticked'], ['Cancelled', 'ticked'], ['Refunded', 'ticked — refund email follows the money'], ['Failed', 'ticked'], ['On hold', 'unticked — manual button'], ['Pending', 'reminders at 30 min and 24 h, automatic'], ['Draft', 'no email']].map(([a, b]) => `<tr><td><b>${a}</b></td><td>${b}</td></tr>`).join('')}
 </table><div class="rjm-h" style="margin-top:8px">The same tick is on the bulk status change in Store → Orders.</div></div>
</div>`,
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
  for (const f of fs.readdirSync(OUT)) if (f.endsWith('.png')) fs.unlinkSync(path.join(OUT, f));
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
      await page.screenshot({ path: path.join(OUT, `${name}-${w}.jpg`), fullPage: true, type: 'jpeg', quality: 80 });
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
  await page.screenshot({ path: path.join(OUT, 'm0-phone-menu-390.jpg'), type: 'jpeg', quality: 80 });
  await page.setViewportSize({ width: 1280, height: 1000 });
  await page.evaluate(() => document.querySelector('#side').classList.remove('open'));
  const side = await page.$('#side');
  await page.evaluate(() => document.querySelector('.nav-group[data-sec="Emails"]').scrollIntoView({ block: 'start' }));
  await side.screenshot({ path: path.join(OUT, 'm0-sidebar-proposed.jpg'), type: 'jpeg', quality: 80 });
  console.log('done');
  await browser.close();
})();
