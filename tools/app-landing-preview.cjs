/* Lane APL (previews only): three designs for the site-app landing page, drawn
   INSIDE the live shop (its real header and footer, the page body replaced in
   this browser only) and photographed at 390 and 1280. Nothing reaches the
   shop. Screens come from tools/app-landing-shots.cjs.
   node tools/app-landing-preview.cjs  ->  docs/app-landing/option-*.png */
const pw = require('playwright');
const fs = require('fs'), path = require('path');
const DIR = path.resolve(__dirname, '../docs/app-landing');
const SHOTS = path.join(DIR, 'shots');
const BASE = process.env.BASE || 'https://kbeautybliss.com';
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
const I = (n) => `/__apl/${n}.png`;

const ICON = {
  apple: '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M16.4 12.6c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.1-2.8.9-3.5.9-.7 0-1.8-.8-3-.8-1.5 0-3 .9-3.8 2.3-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.4 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7c1.3 0 2.1-1.1 2.8-2.3.9-1.3 1.3-2.6 1.3-2.6s-2.5-1-2.5-3.8zM14.2 5.8c.6-.8 1.1-1.9 1-3-.9 0-2.1.6-2.7 1.4-.6.7-1.1 1.8-1 2.9 1 .1 2.1-.5 2.7-1.3z"/></svg>',
  android: '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M7.2 9h9.6c.4 0 .7.3.7.7v7.1c0 .6-.5 1-1 1h-.8v2.4a1.2 1.2 0 01-2.4 0v-2.4h-2.6v2.4a1.2 1.2 0 01-2.4 0v-2.4h-.8c-.6 0-1-.4-1-1V9.7c0-.4.3-.7.7-.7zM4.6 9.2a1.2 1.2 0 011.2 1.2v5a1.2 1.2 0 01-2.4 0v-5c0-.7.5-1.2 1.2-1.2zm14.8 0a1.2 1.2 0 011.2 1.2v5a1.2 1.2 0 01-2.4 0v-5c0-.7.5-1.2 1.2-1.2zM15.3 3.1l1.1-1.6c.1-.1 0-.3-.1-.3-.1-.1-.3 0-.3.1L14.8 3c-.9-.4-1.8-.6-2.8-.6s-1.9.2-2.8.6L8.1 1.3c-.1-.1-.2-.2-.3-.1-.2.1-.2.2-.1.3l1.1 1.6C7.3 3.9 6.4 5.4 6.3 7.2c0 .2.1.3.3.3h10.8c.2 0 .3-.1.3-.3-.1-1.8-1-3.3-2.4-4.1zM9.6 5.6a.6.6 0 110-1.2.6.6 0 010 1.2zm4.8 0a.6.6 0 110-1.2.6.6 0 010 1.2z"/></svg>',
  bell: '<path d="M6 8a6 6 0 1112 0c0 7 3 8 3 8H3s3-1 3-8M10 21a2 2 0 004 0"/>',
  bolt: '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
  tag: '<path d="M20 12l-8 8-9-9V3h8zM7.5 7.5h.01"/>',
  lock: '<path d="M6 11h12v10H6zM8 11V7a4 4 0 018 0v4"/>',
  truck: '<path d="M2 6h11v10H2zM13 10h4l4 4v2h-8M6 19a2 2 0 100-4 2 2 0 000 4zM17 19a2 2 0 100-4 2 2 0 000 4z"/>',
  shield: '<path d="M12 2l8 3v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5zM9 12l2 2 4-4"/>',
  phone: '<path d="M7 2h10v20H7zM11 18h2"/>',
  card: '<path d="M2 6h20v12H2zM2 10h20"/>',
  heart: '<path d="M12 21s-8-5-8-11a4.5 4.5 0 018-3 4.5 4.5 0 018 3c0 6-8 11-8 11z"/>',
  feather: '<path d="M20 4c-8 0-14 6-14 14v2h2c8 0 14-6 14-14V4zM4 20L14 10"/>',
  check: '<path d="M5 12l5 5L20 7"/>',
};
const ic = (n) => `<svg class="apl-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICON[n]}</svg>`;
const phone = (n, cls = '') => `<div class="apl-ph ${cls}"><img src="${I(n)}" alt=""></div>`;
const btns = (dark) => `<div class="apl-btns${dark ? ' dark' : ''}"><a class="apl-store">${ICON.apple}<span><small>Free · 2 taps</small>Add to iPhone</span></a><a class="apl-store">${ICON.android}<span><small>Free · 1 tap</small>Install on Android</span></a></div>`;

const BENEFITS = [
  ['bell', 'Order updates the moment they happen', 'Packed, shipped, out for delivery: a notification on your lock screen, no need to check your email.'],
  ['tag', 'First to know about offers', 'Price drops and back-in-stock alerts for the products you love, and app-only Super Sale reminders.'],
  ['bolt', 'Opens instantly', 'Straight from your home screen, full screen, no browser bars, no typing the address.'],
  ['feather', 'Takes almost no space', 'No 100 MB download. It updates itself, so you always have the newest version.'],
  ['heart', 'Your bag and wishlist, always there', 'Pick up exactly where you left off, on the bus or on the sofa.'],
  ['card', 'Pay your way', 'Apple Pay, Google Pay, cards, Tabby, Tamara or cash on delivery.'],
];
const TRUST = [
  ['shield', '100% authentic', 'Every product sourced from the brand or its official distributor.'],
  ['lock', 'Secure by design', 'The same encrypted shop as our website. Card payments go through Stripe; we never see or store your card number.'],
  ['phone', 'No extra permissions', 'No access to your photos, contacts or location. Notifications only if you say yes, and you can turn them off anytime.'],
  ['truck', '1–3 day UAE delivery', 'Free delivery over AED 199, with tracking right in the app.'],
];
const benefitGrid = (cls = '') => `<div class="apl-ben ${cls}">${BENEFITS.map(([i, t, d]) => `<div class="apl-b">${ic(i)}<h3>${t}</h3><p>${d}</p></div>`).join('')}</div>`;
const trustGrid = (cls = '') => `<div class="apl-trust ${cls}">${TRUST.map(([i, t, d]) => `<div class="apl-t">${ic(i)}<div><h3>${t}</h3><p>${d}</p></div></div>`).join('')}</div>`;
const steps = `<div class="apl-steps">
  <div class="apl-step"><h3>${ICON.android} Android</h3><ol><li>Tap <b>Install on Android</b></li><li>Tap <b>Install</b> on the box that appears. Done.</li></ol></div>
  <div class="apl-step"><h3>${ICON.apple} iPhone</h3><ol><li>Tap the <b>Share</b> button <span class="apl-share">⬆︎</span> at the bottom of Safari</li><li>Tap <b>Add to Home Screen</b>, then <b>Add</b>.</li></ol></div></div>`;
const faq = `<div class="apl-faq">
  <details open><summary>Is the app free?</summary><p>Yes. Free to install, free to use, and prices are the same as on the website.</p></details>
  <details><summary>Is it safe?</summary><p>It is our own shop, on our own secure address, kbeautybliss.com. It cannot read anything else on your phone.</p></details>
  <details><summary>How much space does it take?</summary><p>Almost none. There is no big download; it keeps itself up to date.</p></details>
  <details><summary>How do I remove it?</summary><p>Press and hold the icon and choose Remove, like any app. Nothing is left behind.</p></details></div>`;
const stats = `<div class="apl-stats"><div><b>700+</b><span>products</span></div><div><b>105</b><span>Korean brands</span></div><div><b>1–3</b><span>day UAE delivery</span></div><div><b>24/7</b><span>support</span></div></div>`;

const CSS = `
#content .apl{--p:#C6395F;--pd:#A3304F;--ink:#2A2228;--mu:#756C74;--cr:#FFF8F5;--ln:#EADFE3;font-family:Outfit,system-ui,sans-serif;color:var(--ink)}
.apl *{box-sizing:border-box}
.apl h1,.apl h2,.apl h3{margin:0;text-wrap:balance}
.apl p{margin:0}
.apl-w{max-width:1180px;margin:0 auto;padding:0 18px}
.apl-ic{width:26px;height:26px;color:var(--p);flex:none}
.apl-ph{width:230px;aspect-ratio:390/844;border-radius:34px;border:7px solid #1C1619;overflow:hidden;background:#fff;box-shadow:0 30px 60px -24px rgba(42,34,40,.45),0 0 0 1px rgba(255,255,255,.4) inset;flex:none;position:relative}
.apl-ph{padding-top:26px}
.apl-ph img{width:100%;height:100%;object-fit:cover;object-position:top;display:block}
.apl-ph::before{content:"";position:absolute;top:7px;left:50%;width:74px;height:20px;margin-left:-37px;border-radius:12px;background:#1C1619;z-index:2}
.apl-btns{display:flex;gap:10px;flex-wrap:wrap}
.apl-store{display:inline-flex;align-items:center;gap:10px;background:var(--ink);color:#fff!important;border-radius:14px;padding:10px 18px 10px 14px;text-decoration:none;min-width:190px;cursor:pointer}
.apl-store span{display:grid;line-height:1.1;font-weight:600;font-size:17px}
.apl-store small{font-size:11px;font-weight:400;opacity:.8}
.apl-btns.dark .apl-store{background:#fff;color:var(--ink)!important}
.apl-chips{display:flex;gap:8px;flex-wrap:wrap}
.apl-chip{display:inline-flex;gap:6px;align-items:center;font-size:13px;font-weight:500;background:#fff;border:1px solid var(--ln);border-radius:99px;padding:6px 12px}
.apl-chip .apl-ic{width:16px;height:16px}
.apl-sec{padding:56px 0}
.apl-h2{font-size:clamp(24px,3.4vw,36px);font-weight:700;letter-spacing:-.01em;margin-bottom:8px}
.apl-lead{color:var(--mu);font-size:16px;max-width:60ch;margin-bottom:26px}
.apl-ben{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.apl-b{background:#fff;border:1px solid var(--ln);border-radius:18px;padding:20px;display:grid;gap:8px;align-content:start}
.apl-b h3{font-size:17px;font-weight:600}
.apl-b p,.apl-t p{color:var(--mu);font-size:14.5px;line-height:1.5}
.apl-trust{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.apl-t{display:flex;gap:14px;align-items:flex-start}
.apl-t h3{font-size:17px;font-weight:600;margin-bottom:3px}
.apl-steps{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.apl-step{background:#fff;border:1px solid var(--ln);border-radius:18px;padding:20px}
.apl-step h3{display:flex;gap:8px;align-items:center;font-size:18px;margin-bottom:10px}
.apl-step ol{margin:0;padding-inline-start:20px;display:grid;gap:8px;font-size:15px}
.apl-share{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:6px;background:var(--cr);border:1px solid var(--ln);font-size:12px}
.apl-faq{display:grid;gap:10px;max-width:760px}
.apl-faq details{background:#fff;border:1px solid var(--ln);border-radius:14px;padding:14px 18px}
.apl-faq summary{font-weight:600;cursor:pointer}
.apl-faq p{color:var(--mu);margin-top:8px}
.apl-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.apl-stats div{display:grid;text-align:center}
.apl-stats b{font-size:28px;font-weight:700}
.apl-stats span{font-size:13px;opacity:.8}
.apl-strip{display:flex;gap:18px;overflow-x:auto;padding:10px 4px 26px;scroll-snap-type:x mandatory}
.apl-strip figure{margin:0;display:grid;gap:10px;justify-items:center;scroll-snap-align:center}
.apl-strip figcaption{font-size:14px;font-weight:600}
.apl-sticky{position:fixed;inset-inline:12px;bottom:12px;z-index:60;display:none;align-items:center;gap:10px;background:var(--ink);color:#fff;border-radius:18px;padding:10px 10px 10px 14px;box-shadow:0 18px 40px -16px rgba(0,0,0,.5)}
.apl-sticky img{width:40px;height:40px;border-radius:10px;background:#fff;object-fit:contain}
.apl-sticky div{flex:1;line-height:1.2}
.apl-sticky small{display:block;opacity:.75;font-size:12px}
.apl-sticky a{background:var(--p);color:#fff;border-radius:12px;padding:10px 16px;font-weight:600}
@media (max-width:900px){
  .apl-ben{grid-template-columns:1fr 1fr;gap:10px}
  .apl-b{padding:14px}
  .apl-b h3{font-size:15px}.apl-b p{font-size:13px}
  .apl-trust,.apl-steps{grid-template-columns:1fr}
  .apl-stats{grid-template-columns:1fr 1fr;row-gap:18px}
  .apl-sec{padding:38px 0}
  .apl-store{min-width:0;flex:1}
  .apl-sticky{display:flex}
  .apl-ph{width:200px}
}
/* ── A: Glow hero ── */
.aplA .hero{background:radial-gradient(120% 90% at 85% 10%,#FFE1EA 0,transparent 60%),linear-gradient(180deg,var(--cr),#fff);padding:46px 0 30px;overflow:hidden}
.aplA .hero .apl-w{display:grid;grid-template-columns:1.05fr 1fr;gap:30px;align-items:center}
.aplA .eyebrow{display:inline-flex;gap:8px;align-items:center;font-size:13px;font-weight:600;color:var(--pd);background:#fff;border:1px solid var(--ln);border-radius:99px;padding:6px 12px;margin-bottom:14px}
.aplA h1{font-size:clamp(34px,5.4vw,60px);font-weight:700;line-height:1.02;letter-spacing:-.02em;margin-bottom:14px}
.aplA h1 em{font-style:normal;color:var(--p)}
.aplA .hero p.sub{font-size:18px;color:var(--mu);max-width:44ch;margin-bottom:22px}
.aplA .hero .apl-btns{margin-bottom:18px}
.aplA .fan{position:relative;height:520px}
.aplA .fan .apl-ph{position:absolute;top:30px}
.aplA .fan .l{left:4%;transform:rotate(-8deg) scale(.86);z-index:1}
.aplA .fan .c{left:50%;margin-left:-115px;top:0;z-index:3}
.aplA .fan .r{right:4%;transform:rotate(8deg) scale(.86);z-index:1}
@media (max-width:900px){.aplA .hero .apl-w{grid-template-columns:1fr}.aplA .fan{height:420px}.aplA .fan .l{left:-6%}.aplA .fan .r{right:-6%}.aplA .fan .c{margin-left:-100px}}
/* ── B: Plum premium ── */
.aplB .hero{background:radial-gradient(90% 80% at 80% 20%,#5B1F38 0,transparent 60%),#25131C;color:#fff;padding:56px 0 0;overflow:hidden}
.aplB .hero .apl-w{display:grid;grid-template-columns:1.1fr .9fr;gap:30px;align-items:end}
.aplB h1{font-size:clamp(34px,5vw,58px);font-weight:700;line-height:1.04;letter-spacing:-.02em;margin-bottom:14px}
.aplB h1 em{font-style:normal;color:#FF9DB8}
.aplB .hero p.sub{font-size:18px;opacity:.82;max-width:46ch;margin-bottom:24px}
.aplB .qr{display:flex;gap:14px;align-items:center;margin-top:22px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);border-radius:18px;padding:12px;width:max-content;max-width:100%}
.aplB .qr img{width:96px;height:96px;border-radius:10px;background:#fff;padding:5px}
.aplB .qr b{display:block;font-size:16px}
.aplB .qr span{font-size:13px;opacity:.75}
.aplB .hero .apl-stats{margin:34px 0 46px;color:#fff}
.aplB .hero .phwrap{display:flex;justify-content:center;align-self:end}
.aplB .hero .apl-ph{width:270px;border-color:#000;border-bottom:0;border-radius:40px 40px 0 0;aspect-ratio:390/700;box-shadow:0 0 120px rgba(255,120,160,.35)}
.aplB .rowf{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:center;padding:34px 0}
.aplB .rowf:nth-child(even) .ph{order:2}
.aplB .rowf .ph{display:flex;justify-content:center;background:var(--cr);border-radius:28px;padding:30px 0}
.aplB .rowf h3{font-size:clamp(22px,3vw,32px);font-weight:700;margin-bottom:10px}
.aplB .rowf p{color:var(--mu);font-size:16px;max-width:42ch}
.aplB .rowf .n{font-size:13px;font-weight:600;letter-spacing:.08em;color:var(--p);text-transform:uppercase;margin-bottom:8px;display:block}
.aplB .safe{background:#25131C;color:#fff;border-radius:28px;padding:34px}
.aplB .safe .apl-t p{color:rgba(255,255,255,.75)}
.aplB .safe .apl-ic{color:#FF9DB8}
@media (max-width:900px){.aplB .hero .apl-w{grid-template-columns:1fr}.aplB .qr{display:none}.aplB .hero .apl-ph{width:230px}.aplB .rowf{grid-template-columns:1fr;gap:16px}.aplB .rowf:nth-child(even) .ph{order:0}.aplB .safe{padding:22px}}
/* ── C: Clean comparison ── */
.aplC .hero{padding:40px 0 10px;text-align:center}
.aplC .badge{width:84px;height:84px;border-radius:22px;margin:0 auto 16px;display:grid;place-items:center;background:linear-gradient(145deg,#fff,var(--cr));border:1px solid var(--ln);box-shadow:0 14px 30px -14px rgba(198,57,95,.45)}
.aplC .badge img{width:62px;height:62px;object-fit:contain}
.aplC h1{font-size:clamp(32px,5vw,54px);font-weight:700;letter-spacing:-.02em;line-height:1.05;margin-bottom:12px}
.aplC h1 em{font-style:normal;background:linear-gradient(90deg,var(--pd),#E8638A);-webkit-background-clip:text;background-clip:text;color:transparent}
.aplC .hero p.sub{font-size:17px;color:var(--mu);max-width:52ch;margin:0 auto 22px}
.aplC .hero .apl-btns,.aplC .hero .apl-chips{justify-content:center}
.aplC .hero .apl-chips{margin-top:16px}
.aplC .trio{display:flex;justify-content:center;gap:18px;margin-top:34px}
.aplC .trio .apl-ph:nth-child(2){transform:translateY(-22px)}
.aplC table{width:100%;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid var(--ln);border-radius:18px;overflow:hidden;font-size:15px}
.aplC th,.aplC td{padding:13px 16px;text-align:start;border-bottom:1px solid var(--ln)}
.aplC tr:last-child td{border-bottom:0}
.aplC th{background:var(--cr);font-weight:600}
.aplC td.y{color:#1F9D55;font-weight:600}
.aplC td.n{color:var(--mu)}
.aplC .twocol{display:grid;grid-template-columns:1fr 1fr;gap:28px;align-items:start}
@media (max-width:900px){.aplC .trio .apl-ph{width:150px}.aplC .trio .apl-ph:nth-child(1),.aplC .trio .apl-ph:nth-child(3){display:none}.aplC .twocol{grid-template-columns:1fr}.aplC th,.aplC td{padding:11px 10px;font-size:14px}}
`;

const chips = `<div class="apl-chips"><span class="apl-chip">${ic('shield')}100% authentic</span><span class="apl-chip">${ic('lock')}Secure checkout</span><span class="apl-chip">${ic('truck')}1–3 day UAE delivery</span></div>`;
const sticky = `<div class="apl-sticky"><img src="/site-app/icons/icon-192.png" alt=""><div><b>K-Beauty Bliss app</b><small>Free · opens instantly</small></div><a>Install</a></div>`;

const OPTIONS = {
  A: `<div class="apl aplA">
   <section class="hero"><div class="apl-w"><div>
     <span class="eyebrow">${ic('phone').replace('apl-ic', 'apl-ic" style="width:16px;height:16px')}New · the K-Beauty Bliss app</span>
     <h1>Your K-beauty shop, <em>live in your phone.</em></h1>
     <p class="sub">Install in seconds, free. Order alerts, first access to offers, and your bag always one tap away.</p>
     ${btns()}${chips}</div>
     <div class="fan">${phone('bestsellers', 'l')}${phone('home', 'c')}${phone('product', 'r')}</div></div></section>
   <section class="apl-sec"><div class="apl-w"><h2 class="apl-h2">Why our customers install it</h2><p class="apl-lead">Everything you love about the website, made faster and more personal.</p>${benefitGrid()}</div></section>
   <section class="apl-sec" style="background:var(--cr)"><div class="apl-w"><h2 class="apl-h2">See it on your screen</h2><p class="apl-lead">These are real screens from the app.</p>
     <div class="apl-strip">${[['home', 'Home'], ['search', 'Instant search'], ['product', 'Product page'], ['product-buy', 'Pay your way'], ['track', 'Track your order'], ['brands', '105 brands']].map(([n, c]) => `<figure>${phone(n)}<figcaption>${c}</figcaption></figure>`).join('')}</div></div></section>
   <section class="apl-sec"><div class="apl-w"><h2 class="apl-h2">Safe, secure, and yours</h2><p class="apl-lead">The app is our own shop on our own secure address. It asks for nothing it does not need.</p>${trustGrid()}</div></section>
   <section class="apl-sec" style="background:var(--cr)"><div class="apl-w"><h2 class="apl-h2">Install in seconds</h2><p class="apl-lead">No App Store account, no password, no big download.</p>${steps}</div></section>
   <section class="apl-sec"><div class="apl-w"><h2 class="apl-h2">Questions</h2>${faq}<div style="margin-top:26px">${btns()}</div></div></section>${sticky}</div>`,

  B: `<div class="apl aplB">
   <section class="hero"><div class="apl-w"><div>
     <h1>K-Beauty Bliss, <em>now an app.</em></h1>
     <p class="sub">Authentic Korean skincare, delivered across the UAE in 1–3 days. Now one tap from your home screen, with alerts the moment your order moves.</p>
     ${btns(true)}
     <div class="qr"><img src="${I('qr')}" alt=""><div><b>On a computer?</b><span>Scan with your phone camera to install.</span></div></div>
     ${stats}</div>
     <div class="phwrap">${phone('home')}</div></div></section>
   <section class="apl-sec"><div class="apl-w">
     <div class="rowf"><div class="ph">${phone('home')}</div><div><span class="n">Shop</span><h3>The whole shop, one tap away</h3><p>Opens full screen from your home screen, faster than the website, with your bag and wishlist waiting for you.</p></div></div>
     <div class="rowf"><div class="ph">${phone('search')}</div><div><span class="n">Find</span><h3>Find anything as you type</h3><p>Search 700+ products and 105 brands. Results appear with the first letters, even with a spelling mistake.</p></div></div>
     <div class="rowf"><div class="ph">${phone('product-buy')}</div><div><span class="n">Pay</span><h3>Pay the way you like</h3><p>Apple Pay, Google Pay, cards, Tabby and Tamara in 4 payments, or cash on delivery. Free delivery over AED 199.</p></div></div>
     <div class="rowf"><div class="ph">${phone('track')}</div><div><span class="n">Track</span><h3>Know where your order is</h3><p>Get a notification when it ships and when it is on the way. Track any order in one tap.</p></div></div>
   </div></section>
   <section class="apl-sec" style="padding-top:0"><div class="apl-w"><div class="safe"><h2 class="apl-h2">Shop with total peace of mind</h2><p class="apl-lead" style="color:rgba(255,255,255,.75)">Installing the app changes nothing about your safety. It is the same secure shop.</p>${trustGrid()}</div></div></section>
   <section class="apl-sec" style="background:var(--cr)"><div class="apl-w"><h2 class="apl-h2">Install in seconds</h2><p class="apl-lead">Free, and you can remove it anytime.</p>${steps}<div style="margin-top:24px">${btns()}</div></div></section>${sticky}</div>`,

  C: `<div class="apl aplC">
   <section class="hero"><div class="apl-w">
     <div class="badge"><img src="/site-app/icons/icon-192.png" alt=""></div>
     <h1>Get the app. <em>Get the glow.</em></h1>
     <p class="sub">The K-Beauty Bliss app is free, takes almost no space, and puts order alerts, offers and your bag right on your home screen.</p>
     ${btns()}${chips}
     <div class="trio">${phone('bestsellers')}${phone('home')}${phone('product')}</div></div></section>
   <section class="apl-sec" style="background:var(--cr)"><div class="apl-w twocol"><div><h2 class="apl-h2">App or website?</h2><p class="apl-lead">Same products, same prices. The app simply makes it easier.</p>
     <table><tr><th></th><th>App</th><th>Website</th></tr>
     <tr><td>Opens from your home screen</td><td class="y">✓</td><td class="n">—</td></tr>
     <tr><td>Order and delivery alerts</td><td class="y">✓</td><td class="n">Email only</td></tr>
     <tr><td>Price-drop and back-in-stock alerts</td><td class="y">✓</td><td class="n">—</td></tr>
     <tr><td>Full screen, no browser bars</td><td class="y">✓</td><td class="n">—</td></tr>
     <tr><td>Stays signed in</td><td class="y">✓</td><td class="n">Sometimes</td></tr>
     <tr><td>Space on your phone</td><td class="y">Almost none</td><td class="n">—</td></tr></table></div>
     <div><h2 class="apl-h2">Safe and secure</h2><p class="apl-lead">Our own shop, our own secure address.</p>${trustGrid('one')}</div></div></section>
   <section class="apl-sec"><div class="apl-w"><h2 class="apl-h2">What you get</h2><p class="apl-lead">Small things that make shopping K-beauty easier.</p>${benefitGrid()}</div></section>
   <section class="apl-sec" style="background:var(--cr)"><div class="apl-w"><h2 class="apl-h2">Install in seconds</h2><p class="apl-lead">Choose your phone.</p>${steps}</div></section>
   <section class="apl-sec"><div class="apl-w"><h2 class="apl-h2">Questions</h2>${faq}</div></section>${sticky}</div>`,
};

(async () => {
  const b = await pw.chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const ctx = await b.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    await ctx.route('**/__apl/*.png', (r) => { const n = r.request().url().split('/').pop(); r.fulfill({ path: path.join(SHOTS, n), contentType: 'image/png' }); });
    const pg = await ctx.newPage();
    await pg.goto(BASE + '/about/', { waitUntil: 'networkidle', timeout: 90000 });
    const icon = await pg.evaluate(() => { const l = document.querySelector('link[rel="apple-touch-icon"]'); return l ? l.getAttribute('href') : ''; });
    for (const L of Object.keys(OPTIONS)) {
      await pg.evaluate(({ html, css, icon }) => {
        let s = document.getElementById('apl-css'); if (!s) { s = document.createElement('style'); s.id = 'apl-css'; document.head.appendChild(s); } s.textContent = css;
        document.querySelector('main#content').innerHTML = html.split('/site-app/icons/icon-192.png').join(icon || '/site-app/icons/icon-192.png');
        document.querySelectorAll('.kbw,#kbbWa').forEach((n) => { n.style.display = 'none'; });
        window.scrollTo(0, 0);
      }, { html: OPTIONS[L], css: CSS, icon });
      await pg.waitForTimeout(1500);
      await pg.evaluate(() => Promise.all([...document.images].filter((i) => !i.complete).map((i) => new Promise((r) => { i.onload = i.onerror = r; }))));
      await pg.screenshot({ path: path.join(DIR, `option-${L}-${w}.png`), fullPage: true });
      console.log('ok', L, w, await pg.evaluate(() => document.documentElement.scrollWidth));
    }
    await ctx.close();
  }
  await b.close();
})();
