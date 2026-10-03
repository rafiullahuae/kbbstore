/*
 * Lane RJ — build the PROPOSED ("after") emails from tools/rj-email-kit.cjs.
 *
 *   node tools/rj-build-after.cjs
 *
 * Writes:
 *   docs/rj-email-previews/assets/*.png       product pictures + a campaign banner
 *                                             (drawn here; stand-ins for the shop's
 *                                             real product photos)
 *   docs/rj-email-previews/directions/{A,B,C}-order-confirmation.html
 *   docs/rj-email-previews/after/NN-*.html    every transactional email, direction A
 *   docs/rj-email-previews/marketing/*.html   two campaigns built from builder blocks
 *
 * The figures are the same fixture tools/rj-seed.php puts in the database for
 * the "before" renders, so before and after describe the SAME order:
 * KBB-10427, 2 × Anua toner, 1 × Beauty of Joseon serum, 1 × COSRX cleanser,
 * GLOW10, Tabby, Dubai Marina.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const K = require('./rj-email-kit.cjs');

const ROOT = path.resolve(__dirname, '../docs/rj-email-previews');
const ASSETS = path.join(ROOT, 'assets');

/* ---------------------------------------------------------------- pictures */
const bottle = (a, b, cap, label, kind = 'bottle') => {
  const body = kind === 'tube'
    ? `<path d="M96 54h64l-6 150a10 10 0 0 1-10 9h-32a10 10 0 0 1-10-9z" fill="#fff" opacity=".95"/><rect x="104" y="36" width="48" height="22" rx="4" fill="${cap}"/>`
    : kind === 'dropper'
      ? `<rect x="92" y="92" width="72" height="122" rx="16" fill="#fff" opacity=".95"/><rect x="112" y="56" width="32" height="40" rx="6" fill="${cap}"/><ellipse cx="128" cy="50" rx="14" ry="10" fill="${cap}"/>`
      : `<rect x="84" y="78" width="88" height="138" rx="20" fill="#fff" opacity=".95"/><rect x="104" y="48" width="48" height="34" rx="7" fill="${cap}"/>`;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${a}"/><stop offset="1" stop-color="${b}"/></linearGradient></defs>
<rect width="256" height="256" fill="url(#g)"/><circle cx="210" cy="40" r="60" fill="#fff" opacity=".18"/>
${body}<rect x="98" y="132" width="60" height="34" rx="5" fill="${cap}" opacity=".16"/>
<text x="128" y="155" font-family="Helvetica,Arial,sans-serif" font-size="15" font-weight="700" text-anchor="middle" fill="${cap}">${label}</text></svg>`;
};
const PICS = {
  'p-anua': bottle('#E9F4E4', '#BFDDB2', '#4E8A47', 'ANUA'),
  'p-boj': bottle('#FFF1D9', '#F4CF94', '#A9741D', 'BOJ', 'dropper'),
  'p-cosrx': bottle('#E5EEFB', '#B7CDF0', '#2F5DA8', 'COSRX', 'tube'),
  'p-roundlab': bottle('#E3F3F6', '#A9D8E2', '#1F7C8F', 'ROUND', 'bottle'),
  'p-skin1004': bottle('#FDEBD9', '#F2C29A', '#A35A1F', '1004', 'dropper'),
  'p-laneige': bottle('#FCE3EC', '#F2A9C1', '#B2355F', 'LANEIGE', 'tube'),
};
const BANNER = `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="640" viewBox="0 0 1200 640">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FCE0E8"/><stop offset=".55" stop-color="#F7B7CA"/><stop offset="1" stop-color="#E0567B"/></linearGradient></defs>
<rect width="1200" height="640" fill="url(#g)"/><circle cx="980" cy="120" r="220" fill="#fff" opacity=".22"/><circle cx="160" cy="560" r="180" fill="#fff" opacity=".18"/>
<text x="90" y="250" font-family="Georgia,serif" font-size="40" fill="#A82F53" letter-spacing="6">AUTUMN GLOW EDIT</text>
<text x="86" y="350" font-family="Georgia,serif" font-size="96" font-weight="700" fill="#2A2228">Skin that</text>
<text x="86" y="450" font-family="Georgia,serif" font-size="96" font-weight="700" fill="#2A2228">glows back.</text>
<g transform="translate(760 210)"><rect x="0" y="80" width="130" height="250" rx="30" fill="#fff"/><rect x="34" y="30" width="62" height="56" rx="10" fill="#C13E63"/>
<rect x="170" y="140" width="110" height="190" rx="24" fill="#fff" opacity=".92"/><rect x="200" y="100" width="50" height="44" rx="8" fill="#E0567B"/></g></svg>`;

async function drawAssets() {
  fs.mkdirSync(ASSETS, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await browser.newPage({ deviceScaleFactor: 1 });
  for (const [name, svg] of [...Object.entries(PICS), ['banner-autumn', BANNER]]) {
    const [w, h] = name.startsWith('banner') ? [1200, 640] : [256, 256];
    await page.setViewportSize({ width: w, height: h });
    await page.setContent(`<html><body style="margin:0">${svg}</body></html>`);
    await page.screenshot({ path: path.join(ASSETS, `${name}.png`), clip: { x: 0, y: 0, width: w, height: h } });
  }
  await browser.close();
}

/* ------------------------------------------------------------------- data */
const A = (n) => `../assets/${n}.png`;
const ORDER = { number: 'KBB-10427', placed: '2 Oct 2026', total: 'AED 319.95' };
const ITEMS = [
  { brand: 'Anua', name: 'Heartleaf 77% Soothing Toner 250ml', qty: 2, unit: 'AED 89.00', total: 'AED 178.00', img: A('p-anua') },
  { brand: 'Beauty of Joseon', name: 'Glow Deep Serum Rice + Alpha-Arbutin 30ml', qty: 1, unit: 'AED 115.00', total: 'AED 115.00', img: A('p-boj') },
  { brand: 'COSRX', name: 'Low pH Good Morning Gel Cleanser', variant: '150ml', qty: 1, unit: 'AED 62.50', total: 'AED 62.50', img: A('p-cosrx') },
];
const TOTALS = [['Subtotal', 'AED 355.50'], ['Coupon GLOW10 (10%)', '&minus; AED 35.55', true], ['Delivery', 'Free']];
const GRAND = ['Total', 'AED 319.95', 'Paid with Tabby'];
const ADDRESS = 'Aisha Khan<br>Apartment 1204, Marina Heights Tower<br>Al Marsa Street, Dubai Marina<br>Dubai, United Arab Emirates<br>+971 50 123 4567';
const INFO = [['Delivering to', ADDRESS], ['Delivery', 'Free UAE delivery<br><span style="color:#8C828A;">1&ndash;3 working days</span>', 'Payment', 'Tabby &mdash; pay in 4, interest-free']];
const WHY_ORDER = 'You are receiving this because an order was placed at extrabeauty.ae with this email address.';

/* One transactional email, assembled. */
function orderEmail({ theme = 'A', title, preheader, heroOpts, trackerAt = null, stopped = null, chipExtra = '', before = '', after = '', showItems = true, showTotals = true, showInfo = true, cta = null, why = WHY_ORDER, nav = true }) {
  const inner = [
    K.header(theme, { nav }),
    K.hero({ ...heroOpts, theme }),
    trackerAt !== null ? K.tracker(trackerAt, { stopped, theme }) : '',
    K.orderChip(ORDER, chipExtra, theme),
    before,
    showItems ? K.sectionTitle('Your items') + K.items(ITEMS) : '',
    showTotals ? K.totals(TOTALS, GRAND) : '',
    showInfo ? K.infoPair(...INFO) : '',
    cta ? K.button(cta[0], cta[1], { theme }) : '',
    cta && cta[2] ? K.para(cta[2], '12px 32px 0', 12.5) : '',
    after,
    K.help(theme),
    K.signoff(),
  ].join('\n');
  return K.doc({ title, preheader, theme, body: K.topbar(undefined, theme) + K.card(inner, theme) + K.footer({ why, theme }) });
}

const VIEW = ['View my order', 'https://extrabeauty.ae/my-account/orders/', 'Opens your order on any device &mdash; no sign-in needed for 30 days. <span style="color:#8C828A;">(Proposed: a signed link; today the button only works in the browser that placed the order.)</span>'];

/* ---------------------------------------------------------------- emails */
const EMAILS = {
  '01-order-confirmation': (t = 'A') => orderEmail({
    theme: t, title: 'Order confirmed', preheader: 'Thank you, Aisha — order KBB-10427 is confirmed and we are packing it with care.',
    heroOpts: { icon: 'heart', eyebrow: 'Order confirmed', title: 'Thank you, Aisha!', lead: 'Your order is in and we are packing it with care. Keep this email &mdash; it is your receipt.' },
    trackerAt: 1, cta: VIEW,
  }),
  '02-order-pending-payment': () => orderEmail({
    title: 'Complete your payment', preheader: 'Your order KBB-10427 is saved — finish paying with Tabby to confirm it.',
    heroOpts: { icon: 'clock', tone: 'amber', eyebrow: 'Payment not finished', title: 'Your basket is saved', lead: 'We have not received the payment for this order yet, so it is not confirmed. Finish paying with Tabby and we will start packing straight away.' },
    trackerAt: 0, cta: ['Complete payment', '#', 'If you already paid, ignore this &mdash; you will get a confirmation within minutes.'], showInfo: false,
  }),
  '03-order-on-hold': () => orderEmail({
    title: 'Order on hold', preheader: 'We have paused order KBB-10427 while we check one thing with you.',
    heroOpts: { icon: 'pause', tone: 'amber', eyebrow: 'On hold', title: 'We have paused your order', lead: 'Nothing is wrong with your items &mdash; we just need to confirm one detail before we can send it.' },
    trackerAt: 1, before: K.notice('<b>What we need:</b> [the reason you type when you put the order on hold &mdash; e.g. &ldquo;please confirm the building name for delivery&rdquo;]. Reply to this email or message us on WhatsApp and we will carry on right away.'),
    showTotals: false, cta: ['Reply on WhatsApp', 'https://wa.me/971585052611'],
  }),
  '04-order-shipped': () => orderEmail({
    title: 'Your order is on its way', preheader: 'Order KBB-10427 has left us and is with the courier.',
    heroOpts: { icon: 'truck', tone: 'pink', eyebrow: 'Shipped', title: 'Your order is on its way', lead: 'It has left us and is with the courier. Delivery in the UAE normally takes one to three working days from dispatch.' },
    trackerAt: 2,
    before: K.notice('<b>Courier:</b> [courier name] &nbsp;&middot;&nbsp; <b>Tracking:</b> <a href="#" style="color:#C13E63;font-weight:700;">[tracking number]</a><br><span style="font-size:12.5px;color:#5E545A;">Printed only when you enter a tracking number on the order &mdash; a field the order screen does not have yet (see the plan).</span>', 'pink'),
    showTotals: false, cta: ['Track my parcel', '#'],
  }),
  '05-order-delivered': () => orderEmail({
    title: 'Delivered', preheader: 'Order KBB-10427 is complete. We hope you love it — tell us how it went.',
    heroOpts: { icon: 'gift', tone: 'green', eyebrow: 'Delivered', title: 'Enjoy your new routine ✨', lead: 'Your order is complete. We would love to hear how it works for your skin &mdash; a two-line review helps other shoppers choose.' },
    trackerAt: 3, showTotals: false, showInfo: false,
    after: K.button('Review my products', '#', { ghost: true }) + K.para('<b>How to use them together:</b> cleanser (COSRX) &rarr; toner (Anua) &rarr; serum (Beauty of Joseon), morning and evening.', '22px 32px 0', 14),
  }),
  '06-order-cancelled': () => orderEmail({
    title: 'Order cancelled', preheader: 'Order KBB-10427 has been cancelled.',
    heroOpts: { icon: 'cross', tone: 'red', eyebrow: 'Cancelled', title: 'Your order has been cancelled', lead: 'This order has been cancelled and nothing further will be sent.' },
    trackerAt: 1, stopped: 'Cancelled',
    before: K.notice('Our records show <b>AED 319.95</b> paid on this order and no refund recorded against it yet. [Your sentence from Emails &rarr; Customer emails &rarr; Cancelled &rarr; &ldquo;What a paid customer is told about their money&rdquo;.]', 'red'),
    showInfo: false, cta: ['Shop again', 'https://extrabeauty.ae/shop/'],
  }),
  '07-order-refunded': () => orderEmail({
    title: 'Refund sent', preheader: 'We have sent AED 89.00 back to your Tabby account for order KBB-10427.',
    heroOpts: { icon: 'back', tone: 'green', eyebrow: 'Refund sent', title: 'Your refund is on its way', lead: 'We have sent <b>AED 89.00</b> back to the payment method you used. This is a partial refund &mdash; the rest of the order is unaffected.' },
    before: K.row(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;">
<tr><td class="ink2" style="font-family:${K.SANS};font-size:14px;color:#5E545A;padding:5px 0;">Refunded</td><td align="right" style="font-family:${K.SANS};font-size:18px;font-weight:800;color:#2E9E6B;">AED 89.00</td></tr>
<tr><td class="ink2" style="font-family:${K.SANS};font-size:14px;color:#5E545A;padding:5px 0;">Order total</td><td align="right" class="ink" style="font-family:${K.SANS};font-size:14px;color:#2A2228;">AED 319.95</td></tr>
<tr><td class="ink2" style="font-family:${K.SANS};font-size:14px;color:#5E545A;padding:5px 0;">Paid by</td><td align="right" class="ink" style="font-family:${K.SANS};font-size:14px;color:#2A2228;">Tabby</td></tr></table>`, '0 32px')
      + K.para('Refunds usually appear within five to ten working days, depending on your bank or Tabby. If it has not reached you in ten working days, reply with your order number and we will chase it.', '16px 32px 0', 14),
    showItems: false, showTotals: false, showInfo: false,
  }),
  '08-order-payment-failed': () => orderEmail({
    title: 'Payment did not go through', preheader: 'The payment for KBB-10427 was declined — your basket is saved.',
    heroOpts: { icon: 'card', tone: 'red', eyebrow: 'Payment unsuccessful', title: 'The payment did not go through', lead: 'Nothing was charged and the order was not placed. Your items are still in your basket if you would like to try again or choose another way to pay.' },
    trackerAt: 0, stopped: 'Not paid', showTotals: false, showInfo: false, cta: ['Return to my basket', 'https://extrabeauty.ae/cart/'],
  }),
  '09-new-order-alert': () => K.doc({
    title: 'New order', preheader: 'New order KBB-10427 — AED 319.95, Tabby, Dubai.',
    body: K.card([
      K.row(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="font-family:${K.SANS};"><div style="font-size:11.5px;letter-spacing:.14em;text-transform:uppercase;color:#2E9E6B;font-weight:800;">&#9679; New order</div><div class="ink" style="margin-top:6px;font-size:26px;font-weight:800;color:#2A2228;">KBB-10427 &middot; AED 319.95</div><div class="ink2" style="margin-top:4px;font-size:14px;color:#5E545A;">Aisha Khan &middot; Dubai &middot; Tabby (paid) &middot; 4 items &middot; coupon GLOW10</div></td></tr></table>`, '26px 32px 0'),
      K.button('Open in admin', '#', { align: 'left' }),
      K.sectionTitle('Items to pick'), K.items(ITEMS), K.totals(TOTALS, ['Total', 'AED 319.95']),
      K.infoPair(['Ship to', ADDRESS], ['Customer', 'aisha.khan@example.com<br>+971 50 123 4567<br><span style="color:#8C828A;">Returning customer &middot; 3rd order</span>', 'Delivery', 'Free UAE delivery']),
      K.gap(28),
    ].join('\n')) + K.footer({ why: 'Sent to the store&rsquo;s new-order address (Emails &rarr; Sending). Customers never see this message.' }),
  }),
  '10-order-invoice': () => orderEmail({
    title: 'Your invoice', preheader: 'Invoice 01000 for order KBB-10427 is attached as a PDF.',
    heroOpts: { icon: 'mail', tone: 'ink', eyebrow: 'Tax invoice', title: 'Your invoice is attached', lead: 'Invoice <b>01000</b> for order KBB-10427 is attached as a PDF for your records.' },
    chipExtra: '<div style="margin-top:12px;font-size:13px;color:#5E545A;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;">&#128206; Invoice-01000-KBB-10427.pdf &middot; 84 KB</div>',
    showInfo: false,
  }),
  '11-back-in-stock': () => K.doc({
    title: 'Back in stock', preheader: 'The Anua Heartleaf toner you asked about is back.',
    body: K.topbar() + K.card([
      K.header('A'), K.hero({ icon: 'bell', eyebrow: 'Back in stock', title: 'It’s back — and you asked first', lead: 'You asked us to tell you when this came back. It is in stock now, while it lasts.' }),
      K.productGrid([{ brand: 'Anua', name: 'Heartleaf 77% Soothing Toner 250ml', price: 'AED 89.00', img: A('p-anua') }], { cols: 1, cta: 'Shop it now' }),
      K.para('This is the one message you asked for &mdash; we will not email you about this product again.', '18px 32px 28px', 13),
    ].join('\n')) + K.footer({ why: 'You asked to be told when this product came back in stock.', unsubscribe: true }),
  }),
  '12-cart-recovery': () => K.doc({
    title: 'Your basket is waiting', preheader: 'Your basket is saved — pick up where you left off.',
    body: K.topbar() + K.card([
      K.header('A'), K.hero({ icon: 'bag', eyebrow: 'Still thinking?', title: 'Your basket is waiting for you', lead: 'Everything is saved &mdash; pick up where you left off.' }),
      K.items([ITEMS[0], { ...ITEMS[1], qty: 2, total: 'AED 230.00' }]),
      K.button('Return to my basket', 'https://extrabeauty.ae/cart/'),
      K.para('You asked us to remind you about this basket. If you have already ordered, thank you &mdash; please ignore this.', '18px 32px 28px', 13),
    ].join('\n')) + K.footer({ why: 'You left your email address on the basket page and asked for a reminder.', unsubscribe: true }),
  }),
  '13-account-invite': () => K.doc({
    title: 'Your account is ready', preheader: 'Set a password to see your orders and check out faster.',
    body: K.topbar() + K.card([
      K.header('A'), K.hero({ icon: 'key', eyebrow: 'New website', title: 'Your account is ready', lead: 'We have moved to a new website and kept your order history. Choose a password to sign in, see past orders and check out faster.' }),
      K.button('Set my password', '#'), K.para('The link works for 7 days and only for you.', '12px 32px 28px', 13),
    ].join('\n')) + K.footer({ why: 'You have shopped with K Beauty Bliss before, under this email address.' }),
  }),
  '14-newsletter-confirm': () => K.doc({
    title: 'Confirm your subscription', preheader: 'One tap to confirm — then you are on the list.',
    body: K.topbar() + K.card([
      K.header('A'), K.hero({ icon: 'spark', eyebrow: 'One last step', title: 'Confirm your subscription', lead: 'Somebody &mdash; we hope it was you &mdash; asked for K Beauty Bliss emails at this address. You are not on the list yet.' }),
      K.button('Yes, subscribe me', '#'), K.para('The link works for 14 days. If this was not you, ignore this message and nothing happens.', '14px 32px 28px', 13),
    ].join('\n')) + K.footer({ why: 'This address was typed into the newsletter form on extrabeauty.ae.' }),
  }),
  '15-quiz-plan': () => K.doc({
    title: 'Your skin plan', preheader: 'Your morning and evening steps, from the skin quiz.',
    body: K.topbar() + K.card([
      K.header('A'), K.hero({ icon: 'spark', eyebrow: 'Your skin quiz plan', title: 'Here is your plan, Aisha', lead: 'Combination skin &middot; working on dullness and dark spots. The steps, in the order they go on:' }),
      K.infoPair(['Morning', '1. Gentle cleanser<br>2. Hydrating toner<br>3. Vitamin C serum<br>4. Moisturiser<br>5. Sunscreen'], ['Evening', '1. Oil cleanser<br>2. Water cleanser<br>3. Toner<br>4. Brightening serum<br>5. Night cream']),
      K.button('Shop for dark spots', '#'),
      K.para('These are steps, not products &mdash; nothing has been chosen, reserved or charged.', '14px 32px 28px', 13),
    ].join('\n')) + K.footer({ why: 'This address was typed into the skin quiz and the form said we would email the plan. It has not been added to any list.' }),
  }),
  '16-password-reset': () => K.doc({
    title: 'Reset your password', preheader: 'Use this link within 60 minutes to choose a new password.',
    body: K.topbar() + K.card([
      K.header('A', { nav: false }), K.hero({ icon: 'key', tone: 'ink', eyebrow: 'Account security', title: 'Reset your password', lead: 'Somebody asked to reset the password on your account. If that was you, choose a new one below.' }),
      K.button('Set a new password', '#'),
      K.para('The link can be used once and expires in 60 minutes. Once you set a new password, your previous one &mdash; including the one from our old website &mdash; stops working.', '18px 32px 0', 13.5),
      K.notice('Did not ask for this? Ignore this email. Your password has not changed and nobody has been given access.', 'ink'),
      K.gap(28),
    ].join('\n')) + K.footer({ why: 'Sent because a password reset was requested for this address.' }),
  }),
  '17-verify-email': () => K.doc({
    title: 'Confirm your email', preheader: 'Confirm this address so we can send your receipts here.',
    body: K.topbar() + K.card([
      K.header('A', { nav: false }), K.hero({ icon: 'mail', tone: 'pink', eyebrow: 'Almost there', title: 'Confirm your email address', lead: 'Tap the button to confirm this is your address. The link works for 24 hours.' }),
      K.button('Confirm my email', '#'), K.gap(28),
    ].join('\n')) + K.footer({ why: 'Sent because this address was used to create an account at extrabeauty.ae.' }),
  }),
};

/* ---------------------------------------------------------------- marketing */
const GRID = [
  { brand: 'Round Lab', name: 'Birch Juice Moisturizing Sunscreen 50ml', price: 'AED 79.00', was: 'AED 95.00', img: A('p-roundlab') },
  { brand: 'SKIN1004', name: 'Madagascar Centella Ampoule 55ml', price: 'AED 72.00', img: A('p-skin1004') },
  { brand: 'Laneige', name: 'Lip Sleeping Mask Berry 20g', price: 'AED 85.00', img: A('p-laneige') },
  { brand: 'Beauty of Joseon', name: 'Glow Deep Serum Rice + Arbutin 30ml', price: 'AED 115.00', img: A('p-boj') },
];
const MARKETING = {
  'm1-campaign-autumn-glow': () => K.doc({
    title: 'Autumn Glow Edit', preheader: '15% off the glow edit this week — your code is inside.',
    body: K.topbar('Authentic K-beauty, curated for you') + K.card([
      K.header('A'), K.heroImage('../assets/banner-autumn.png', 'Autumn Glow Edit — skin that glows back'),
      K.row(`<div class="ink" style="font-family:${K.SERIF};font-size:26px;line-height:1.25;font-weight:700;color:#2A2228;text-align:center;">Hi Aisha, your glow edit is here</div>`, '28px 32px 0'),
      K.para('<div style="text-align:center">Four of this season&rsquo;s most-loved picks for dewy, even skin &mdash; chosen for the UAE heat.</div>', '10px 40px 0'),
      K.productGrid(GRID, { cols: 2 }),
      K.coupon({ code: 'GLOW15', line: '15% off everything in the Glow Edit', expires: 'Ends Sunday 12 October, midnight' }),
      K.button('Shop the Glow Edit', '#'),
      K.gap(30),
    ].join('\n')) + K.footer({ why: 'You are receiving this because you subscribed to K Beauty Bliss emails or bought from us before.', unsubscribe: true }),
  }),
  'm2-campaign-we-miss-you': () => K.doc({
    title: 'We miss you', preheader: 'It has been a while — here is 10% off your next order.',
    body: K.topbar() + K.card([
      K.header('A'), K.hero({ icon: 'heart', eyebrow: 'It has been a while', title: 'We saved you something', lead: 'Your skin has changed since your last order &mdash; so has our shelf. Here is 10% off to come and see.' }),
      K.coupon({ code: 'MISSYOU10', line: '10% off your next order', expires: 'Valid 14 days &middot; one use per customer' }),
      K.sectionTitle('New since your last visit', '26px 32px 0'),
      K.productGrid(GRID.slice(0, 2), { cols: 2 }),
      K.button('Come back and shop', '#'), K.gap(30),
    ].join('\n')) + K.footer({ why: 'You are receiving this because you bought from K Beauty Bliss before.', unsubscribe: true }),
  }),
};

(async () => {
  await drawAssets();
  for (const d of ['after', 'directions', 'marketing']) fs.mkdirSync(path.join(ROOT, d), { recursive: true });
  for (const [name, build] of Object.entries(EMAILS)) fs.writeFileSync(path.join(ROOT, 'after', `${name}.html`), build());
  for (const t of ['A', 'B', 'C']) fs.writeFileSync(path.join(ROOT, 'directions', `${t}-order-confirmation.html`), EMAILS['01-order-confirmation'](t));
  for (const [name, build] of Object.entries(MARKETING)) fs.writeFileSync(path.join(ROOT, 'marketing', `${name}.html`), build());
  console.log(`after: ${Object.keys(EMAILS).length}, directions: 3, marketing: ${Object.keys(MARKETING).length}`);
})();
