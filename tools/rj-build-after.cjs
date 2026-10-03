/*
 * Lane RJ — build the PROPOSED ("after") emails from tools/rj-email-kit.cjs.
 *
 *   node tools/rj-build-after.cjs
 *
 * Round 1b, after the owner's decisions of 3 October 2026:
 *   - look A for every email; "View this email in your browser" at the very
 *     bottom; light pages (system fonts, JPEG pictures sized to their slot);
 *   - no "we are packing it" before payment: the receipt goes when the order
 *     is paid (or placed with cash on delivery), and an unpaid order gets
 *     "Complete your order" at 30 minutes and again at 24 hours, whatever the
 *     payment method;
 *   - the order number IS the tracking number; "Track your order" opens the
 *     shop's own order page on any device (a signed link);
 *   - footer: Dubai and Korea addresses (placeholders until he pastes them),
 *     WhatsApp +971 58 505 2611, info@kbeautybliss.com;
 *   - on hold is sent by hand from the order screen, not automatically.
 *
 * Writes:
 *   docs/rj-email-previews/assets/*.jpg      product pictures (128 for rows, 400 for
 *                                            grids) and one campaign banner — drawn
 *                                            here as stand-ins for real photos
 *   docs/rj-email-previews/after/NN-*.html   every transactional email
 *   docs/rj-email-previews/after/index.json  name, title, trigger, default, HTML bytes
 *   docs/rj-email-previews/marketing/*.html  three campaigns built from builder blocks
 *
 * The order is the same fixture tools/rj-seed.php puts in the database for the
 * "before" renders: KBB-10427, 2 × Anua toner, 1 × Beauty of Joseon serum,
 * 1 × COSRX cleanser, GLOW10, Tabby, Dubai Marina.
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
      : kind === 'jar'
        ? `<rect x="70" y="118" width="116" height="92" rx="18" fill="#fff" opacity=".95"/><rect x="64" y="96" width="128" height="30" rx="10" fill="${cap}"/>`
        : `<rect x="84" y="78" width="88" height="138" rx="20" fill="#fff" opacity=".95"/><rect x="104" y="48" width="48" height="34" rx="7" fill="${cap}"/>`;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 256 256" preserveAspectRatio="xMidYMid slice">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${a}"/><stop offset="1" stop-color="${b}"/></linearGradient></defs>
<rect width="256" height="256" fill="url(#g)"/><circle cx="210" cy="40" r="60" fill="#fff" opacity=".18"/>
${body}<rect x="98" y="150" width="60" height="30" rx="5" fill="${cap}" opacity=".16"/>
<text x="128" y="170" font-family="Helvetica,Arial,sans-serif" font-size="${label.length > 6 ? 12 : 15}" font-weight="700" text-anchor="middle" fill="${cap}">${label}</text></svg>`;
};
const PICS = {
  'p-anua': bottle('#E9F4E4', '#BFDDB2', '#4E8A47', 'ANUA'),
  'p-boj': bottle('#FFF1D9', '#F4CF94', '#A9741D', 'BOJ', 'dropper'),
  'p-cosrx': bottle('#E5EEFB', '#B7CDF0', '#2F5DA8', 'COSRX', 'tube'),
  'p-roundlab': bottle('#E3F3F6', '#A9D8E2', '#1F7C8F', 'ROUND'),
  'p-skin1004': bottle('#FDEBD9', '#F2C29A', '#A35A1F', '1004', 'dropper'),
  'p-laneige': bottle('#FCE3EC', '#F2A9C1', '#B2355F', 'LANEIGE', 'tube'),
  'p-medicube-pad': bottle('#FDE2EA', '#F5A3BC', '#D23C6E', 'MEDICUBE', 'jar'),
  'p-medicube-serum': bottle('#FBE7F0', '#E9B4CC', '#B03A77', 'MEDICUBE', 'dropper'),
  'p-medicube-mask': bottle('#F1E4F7', '#D3B3E6', '#7B4AA0', 'MEDICUBE', 'tube'),
  'p-medicube-cream': bottle('#FFF0DE', '#F7CFA1', '#C0742A', 'MEDICUBE', 'jar'),
};
const BANNER = `<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 1200 640">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FCE0E8"/><stop offset=".55" stop-color="#F7B7CA"/><stop offset="1" stop-color="#E0567B"/></linearGradient></defs>
<rect width="1200" height="640" fill="url(#g)"/><circle cx="980" cy="120" r="220" fill="#fff" opacity=".22"/><circle cx="160" cy="560" r="180" fill="#fff" opacity=".18"/>
<text x="90" y="250" font-family="Georgia,serif" font-size="40" fill="#A82F53" letter-spacing="6">AUTUMN GLOW EDIT</text>
<text x="86" y="350" font-family="Georgia,serif" font-size="96" font-weight="700" fill="#2A2228">Skin that</text>
<text x="86" y="450" font-family="Georgia,serif" font-size="96" font-weight="700" fill="#2A2228">glows back.</text>
<g transform="translate(760 210)"><rect x="0" y="80" width="130" height="250" rx="30" fill="#fff"/><rect x="34" y="30" width="62" height="56" rx="10" fill="#C13E63"/>
<rect x="170" y="140" width="110" height="190" rx="24" fill="#fff" opacity=".92"/><rect x="200" y="100" width="50" height="44" rx="8" fill="#E0567B"/></g></svg>`;

/* JPEG, sized to the slot at 2x: 128 for a 64px line-item picture, 400 for a
   200px grid card, 1200 for the 600px banner. Light pages were the brief. */
async function drawAssets() {
  fs.mkdirSync(ASSETS, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await browser.newPage({ deviceScaleFactor: 1 });
  const shoot = async (svg, w, h, out) => {
    await page.setViewportSize({ width: w, height: h });
    await page.setContent(`<html><body style="margin:0;width:${w}px;height:${h}px">${svg}</body></html>`);
    await page.screenshot({ path: path.join(ASSETS, out), type: 'jpeg', quality: 82, clip: { x: 0, y: 0, width: w, height: h } });
  };
  for (const [name, svg] of Object.entries(PICS)) {
    await shoot(svg, 128, 128, `${name}-128.jpg`);
    await shoot(svg, 400, 400, `${name}-400.jpg`);
  }
  await shoot(BANNER, 1200, 640, 'banner-autumn.jpg');
  await browser.close();
}

/* ------------------------------------------------------------------- data */
const S = (n) => `../assets/${n}-128.jpg`;
const L = (n) => `../assets/${n}-400.jpg`;
const ORDER = { number: 'KBB-10427', placed: '2 Oct 2026', total: 'AED 319.95' };
const ITEMS = [
  { brand: 'Anua', name: 'Heartleaf 77% Soothing Toner 250ml', qty: 2, unit: 'AED 89.00', total: 'AED 178.00', img: S('p-anua') },
  { brand: 'Beauty of Joseon', name: 'Glow Deep Serum Rice + Alpha-Arbutin 30ml', qty: 1, unit: 'AED 115.00', total: 'AED 115.00', img: S('p-boj') },
  { brand: 'COSRX', name: 'Low pH Good Morning Gel Cleanser', variant: '150ml', qty: 1, unit: 'AED 62.50', total: 'AED 62.50', img: S('p-cosrx') },
];
const TOTALS = [['Subtotal', 'AED 355.50'], ['Coupon GLOW10 (10%)', '&minus; AED 35.55', true], ['Delivery', 'Free']];
const GRAND = ['Total', 'AED 319.95', 'Paid with Tabby'];
const GRAND_DUE = ['Total to pay', 'AED 319.95', 'Not paid yet'];
const ADDRESS = 'Aisha Khan<br>Apartment 1204, Marina Heights Tower<br>Al Marsa Street, Dubai Marina<br>Dubai, United Arab Emirates<br>+971 50 123 4567';
const INFO = [['Delivering to', ADDRESS], ['Delivery', 'Free UAE delivery<br><span style="color:#8C828A;">1&ndash;3 working days</span>', 'Payment', 'Tabby &mdash; pay in 4, interest-free']];
const WHY_ORDER = 'You are receiving this because an order was placed at extrabeauty.ae with this email address.';

const TRACK = ['Track your order', 'https://extrabeauty.ae/order/KBB-10427/?sig=…',
  'Opens on any phone or computer &mdash; no sign-in needed. The page always shows the latest status.'];
const TRACK_NOTE = K.notice('<b>Your tracking number is your order number: KBB-10427.</b><br><span style="font-size:13px;color:#5E545A;">Follow it on our website &mdash; whatever we set (Shipped, Delivered) shows there straight away.</span>', 'pink');
const COMPLETE = ['Complete your order', 'https://extrabeauty.ae/order/KBB-10427/pay/?sig=…',
  'Opens your saved order on any device. Prefer another way to pay? Message us on WhatsApp and we will help.'];

function orderEmail({ title, preheader, heroOpts, trackerAt = null, stopped = null, labels, chipExtra = '', before = '', after = '', showItems = true, showTotals = true, grand = GRAND, showInfo = true, cta = null, why = WHY_ORDER }) {
  const inner = [
    K.header('A'),
    K.hero({ ...heroOpts, theme: 'A' }),
    trackerAt !== null ? K.tracker(trackerAt, { stopped, theme: 'A', ...(labels ? { labels } : {}) }) : '',
    K.orderChip(ORDER, chipExtra, 'A'),
    before,
    showItems ? K.sectionTitle('Your items') + K.items(ITEMS) : '',
    showTotals ? K.totals(TOTALS, grand) : '',
    showInfo ? K.infoPair(...INFO) : '',
    cta ? K.button(cta[0], cta[1]) : '',
    cta && cta[2] ? K.para(`<div style="text-align:center">${cta[2]}</div>`, '12px 32px 0', 12.5) : '',
    after,
    K.help('A'),
    K.signoff(),
  ].join('\n');
  return K.doc({ title, preheader, theme: 'A', body: K.topbar() + K.card(inner, 'A') + K.footer({ why }) });
}

const simple = (title, preheader, inner, why, unsubscribe = false, nav = true) =>
  K.doc({ title, preheader, body: K.topbar() + K.card([K.header('A', { nav }), ...inner].join('\n')) + K.footer({ why, unsubscribe }) });

const STEPS = ['Placed', 'Confirmed', 'Shipped', 'Delivered'];

/* name => [trigger shown to the owner, default state, builder] */
const EMAILS = {
  '01-order-confirmation': ['Order confirmed · when paid (card / Tabby / Tamara) or placed with cash on delivery → Processing', 'On', () => orderEmail({
    title: 'Order confirmed 🎉', preheader: 'Thank you, Aisha — payment received, order KBB-10427 is confirmed.',
    heroOpts: { icon: 'heart', eyebrow: 'Order confirmed', title: 'Thank you, Aisha! 🎉', lead: 'Your payment is in and your order is confirmed. We are packing it with care &mdash; keep this email, it is your receipt.' },
    trackerAt: 1, labels: STEPS, cta: TRACK,
  })],
  '02-complete-order-30min': ['Pending · 30 minutes after an unfinished order, any payment method', 'On', () => orderEmail({
    title: 'Complete your order 🛍️', preheader: 'Your order KBB-10427 is saved — one step left to confirm it.',
    heroOpts: { icon: 'bag', tone: 'amber', eyebrow: 'Order not complete', title: 'You are one step away 🛍️', lead: 'We saved your order, but the payment was not completed, so it is not confirmed yet. Everything is below &mdash; finish in one tap.' },
    trackerAt: 0, labels: STEPS, grand: GRAND_DUE, showInfo: false, cta: COMPLETE, before: K.promises(),
    after: K.para('<div style="text-align:center">Already paid? Ignore this &mdash; your confirmation is on its way.</div>', '10px 32px 0', 12.5),
  })],
  '03-complete-order-24h': ['Pending · 24 hours later, if still unfinished (last reminder)', 'On', () => orderEmail({
    title: 'Your order is still waiting ⏳', preheader: 'Last reminder: order KBB-10427 is saved but not confirmed.',
    heroOpts: { icon: 'clock', tone: 'red', eyebrow: 'Last reminder', title: 'Your order is still waiting for you ⏳', lead: 'Order KBB-10427 is saved but not paid, so we cannot send it yet. Popular items sell out quickly, so we cannot promise they will still be in stock later.' },
    trackerAt: 0, labels: STEPS, grand: GRAND_DUE, showInfo: false, cta: COMPLETE, before: K.promises(),
    after: K.para('<div style="text-align:center">This is the last reminder about this order.</div>', '10px 32px 0', 12.5),
  })],
  '04-order-on-hold': ['On hold · sent only by hand: “Send on-hold email” on the order screen', 'Off (manual)', () => orderEmail({
    title: 'Order on hold', preheader: 'We have paused order KBB-10427 while we check one thing with you.',
    heroOpts: { icon: 'pause', tone: 'amber', eyebrow: 'On hold', title: 'We have paused your order', lead: 'Nothing is wrong with your items &mdash; we just need to confirm one detail before we can send it.' },
    trackerAt: 1, labels: STEPS, before: K.notice('<b>What we need:</b> [the message you type when you press &ldquo;Send on-hold email&rdquo;]. Reply to this email or message us on WhatsApp and we will carry on right away.'),
    showTotals: false, cta: ['Reply on WhatsApp', 'https://wa.me/971585052611'],
  })],
  '05-order-shipped': ['Shipped · status → Shipped (untick “Email the customer” to skip)', 'On', () => orderEmail({
    title: 'Your order is on its way 🚚💨', preheader: 'Order KBB-10427 has shipped. Your order number is your tracking number.',
    heroOpts: { icon: 'truck', tone: 'pink', eyebrow: 'Shipped', title: 'Your order is on its way 🚚💨', lead: 'It has left us. Delivery in the UAE normally takes one to three working days from dispatch.' },
    trackerAt: 2, labels: STEPS, before: TRACK_NOTE, showTotals: false, cta: TRACK,
  })],
  '06-order-delivered': ['Delivered · status → Completed', 'On', () => orderEmail({
    title: 'Delivered ✨', preheader: 'Order KBB-10427 is delivered. We hope you love it.',
    heroOpts: { icon: 'gift', tone: 'green', eyebrow: 'Delivered', title: 'Enjoy your new routine ✨', lead: 'Your order is complete. Open it, try it, and enjoy the little extras we tucked in.' },
    trackerAt: 3, labels: STEPS, before: TRACK_NOTE, showTotals: false, showInfo: false,
    after: K.para('<b>How to use them together:</b> cleanser (COSRX) &rarr; toner (Anua) &rarr; serum (Beauty of Joseon), morning and evening.', '22px 32px 0', 14),
  })],
  '07-order-cancelled': ['Cancelled · status → Cancelled', 'On', () => orderEmail({
    title: 'Order cancelled', preheader: 'Order KBB-10427 has been cancelled.',
    heroOpts: { icon: 'cross', tone: 'red', eyebrow: 'Cancelled', title: 'Your order has been cancelled', lead: 'This order has been cancelled and nothing further will be sent.' },
    trackerAt: 1, stopped: 'Cancelled', labels: STEPS,
    before: K.notice('Our records show <b>AED 319.95</b> paid on this order and no refund recorded against it yet. [Your sentence from Emails &rarr; Customer emails &rarr; Cancelled &rarr; &ldquo;What a paid customer is told about their money&rdquo;.]', 'red'),
    showInfo: false, cta: ['Shop again', 'https://extrabeauty.ae/shop/'],
  })],
  '08-order-refunded': ['Refunded · when a refund is sent (full or part)', 'On', () => orderEmail({
    title: 'Refund sent', preheader: 'We have sent AED 89.00 back to your Tabby account for order KBB-10427.',
    heroOpts: { icon: 'back', tone: 'green', eyebrow: 'Refund sent', title: 'Your refund is on its way', lead: 'We have sent <b>AED 89.00</b> back to the payment method you used. This is a partial refund &mdash; the rest of the order is unaffected.' },
    before: K.row(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;">
<tr><td class="ink2" style="font-family:${K.SANS};font-size:14px;color:#5E545A;padding:5px 0;">Refunded</td><td align="right" style="font-family:${K.SANS};font-size:18px;font-weight:800;color:#2E9E6B;">AED 89.00</td></tr>
<tr><td class="ink2" style="font-family:${K.SANS};font-size:14px;color:#5E545A;padding:5px 0;">Order total</td><td align="right" class="ink" style="font-family:${K.SANS};font-size:14px;color:#2A2228;">AED 319.95</td></tr>
<tr><td class="ink2" style="font-family:${K.SANS};font-size:14px;color:#5E545A;padding:5px 0;">Paid by</td><td align="right" class="ink" style="font-family:${K.SANS};font-size:14px;color:#2A2228;">Tabby</td></tr></table>`, '0 32px')
      + K.para('Refunds usually appear within five to ten working days, depending on your bank or Tabby. If it has not reached you in ten working days, reply with your order number and we will chase it.', '16px 32px 0', 14),
    showItems: false, showTotals: false, showInfo: false,
  })],
  '09-order-payment-failed': ['Payment failed · status → Failed', 'On', () => orderEmail({
    title: 'Payment did not go through', preheader: 'The payment for KBB-10427 was declined — nothing was charged.',
    heroOpts: { icon: 'card', tone: 'red', eyebrow: 'Payment unsuccessful', title: 'The payment did not go through 😔', lead: 'Nothing was charged and the order is not confirmed. Your order is saved &mdash; try again or choose another way to pay.' },
    trackerAt: 0, stopped: 'Not paid', labels: STEPS, grand: GRAND_DUE, showInfo: false, cta: COMPLETE,
  })],
  '10-new-order-alert': ['To you · new order paid or placed (COD)', 'On', () => K.doc({
    title: 'New order', preheader: 'New order KBB-10427 — AED 319.95, Tabby, Dubai.',
    body: K.card([
      K.row(`<div style="font-family:${K.SANS};"><div style="font-size:11.5px;letter-spacing:.14em;text-transform:uppercase;color:#2E9E6B;font-weight:800;">&#9679; New order</div><div class="ink" style="margin-top:6px;font-size:26px;font-weight:800;color:#2A2228;">KBB-10427 &middot; AED 319.95</div><div class="ink2" style="margin-top:4px;font-size:14px;color:#5E545A;">Aisha Khan &middot; Dubai &middot; Tabby (paid) &middot; 4 items &middot; coupon GLOW10</div></div>`, '26px 32px 0'),
      K.button('Open in admin', '#', { align: 'left' }),
      K.sectionTitle('Items to pick'), K.items(ITEMS), K.totals(TOTALS, ['Total', 'AED 319.95']),
      K.infoPair(['Ship to', ADDRESS], ['Customer', 'aisha.khan@example.com<br>+971 50 123 4567<br><span style="color:#8C828A;">Returning customer &middot; 3rd order</span>', 'Delivery', 'Free UAE delivery']),
      K.gap(28),
    ].join('\n')) + K.footer({ why: 'Sent to the store&rsquo;s new-order address. Customers never see this message.' }),
  })],
  '11-order-invoice': ['Invoice · “Email invoice” button on an order', 'Manual', () => orderEmail({
    title: 'Your invoice', preheader: 'Invoice 01000 for order KBB-10427 is attached as a PDF.',
    heroOpts: { icon: 'mail', tone: 'ink', eyebrow: 'Tax invoice', title: 'Your invoice is attached', lead: 'Invoice <b>01000</b> for order KBB-10427 is attached as a PDF for your records.' },
    chipExtra: `<div style="margin-top:12px;font-size:13px;color:#5E545A;font-family:${K.SANS};">&#128206; Invoice-01000-KBB-10427.pdf</div>`,
    showInfo: false,
  })],
  '12-back-in-stock': ['Back in stock · a product they asked about returns (module)', 'Off until switched on', () => simple('Back in stock', 'The Anua Heartleaf toner you asked about is back.', [
    K.hero({ icon: 'bell', eyebrow: 'Back in stock', title: 'It is back — and you asked first', lead: 'You asked us to tell you when this came back. It is in stock now, while it lasts.' }),
    K.productGrid([{ brand: 'Anua', name: 'Heartleaf 77% Soothing Toner 250ml', price: 'AED 89.00', img: L('p-anua') }], { cols: 1, cta: 'Shop it now' }),
    K.para('This is the one message you asked for &mdash; we will not email you about this product again.', '18px 32px 28px', 13),
  ], 'You asked to be told when this product came back in stock.', true)],
  '13-basket-reminder': ['Basket reminder · basket left with an email (module)', 'Off until switched on', () => simple('Your basket is waiting', 'Your basket is saved — pick up where you left off.', [
    K.hero({ icon: 'bag', eyebrow: 'Still thinking?', title: 'Your basket is waiting for you', lead: 'Everything is saved &mdash; pick up where you left off.' }),
    K.items([ITEMS[0], { ...ITEMS[1], qty: 2, total: 'AED 230.00' }]),
    K.button('Return to my basket', 'https://extrabeauty.ae/cart/'),
    K.para('You asked us to remind you about this basket. If you have already ordered, thank you &mdash; please ignore this.', '18px 32px 28px', 13),
  ], 'You left your email address on the basket page and asked for a reminder.', true)],
  '14-account-invite': ['Account invite · sent from Customers → Send account invite', 'Manual', () => simple('Your account is ready', 'Set a password to see your orders and check out faster.', [
    K.hero({ icon: 'key', eyebrow: 'New website', title: 'Your account is ready', lead: 'We have moved to a new website and kept your order history. Choose a password to sign in, see past orders and check out faster.' }),
    K.button('Set my password', '#'), K.para('<div style="text-align:center">The link works for 7 days and only for you.</div>', '12px 32px 28px', 13),
  ], 'You have shopped with K Beauty Bliss before, under this email address.')],
  '15-newsletter-confirm': ['Newsletter · sign-up on the website (double opt-in)', 'On', () => simple('Confirm your subscription', 'One tap to confirm — then you are on the list.', [
    K.hero({ icon: 'spark', eyebrow: 'One last step', title: 'Confirm your subscription', lead: 'Somebody &mdash; we hope it was you &mdash; asked for K Beauty Bliss emails at this address. You are not on the list yet.' }),
    K.button('Yes, subscribe me', '#'), K.para('<div style="text-align:center">The link works for 14 days. If this was not you, ignore this message.</div>', '14px 32px 28px', 13),
  ], 'This address was typed into the newsletter form on extrabeauty.ae.')],
  '16-quiz-plan': ['Skin quiz · quiz finished with an email', 'On', () => simple('Your skin plan', 'Your morning and evening steps, from the skin quiz.', [
    K.hero({ icon: 'spark', eyebrow: 'Your skin quiz plan', title: 'Here is your plan, Aisha', lead: 'Combination skin &middot; working on dullness and dark spots. The steps, in the order they go on:' }),
    K.infoPair(['Morning', '1. Gentle cleanser<br>2. Hydrating toner<br>3. Vitamin C serum<br>4. Moisturiser<br>5. Sunscreen'], ['Evening', '1. Oil cleanser<br>2. Water cleanser<br>3. Toner<br>4. Brightening serum<br>5. Night cream']),
    K.button('Shop for dark spots', '#'),
    K.para('<div style="text-align:center">These are steps, not products &mdash; nothing has been chosen, reserved or charged.</div>', '14px 32px 28px', 13),
  ], 'This address was typed into the skin quiz. It has not been added to any list.')],
  '17-password-reset': ['Password reset · customer asks for one (always on)', 'Always on', () => simple('Reset your password', 'Use this link within 60 minutes to choose a new password.', [
    K.hero({ icon: 'key', tone: 'ink', eyebrow: 'Account security', title: 'Reset your password', lead: 'Somebody asked to reset the password on your account. If that was you, choose a new one below.' }),
    K.button('Set a new password', '#'),
    K.para('The link can be used once and expires in 60 minutes. Once you set a new password, your previous one &mdash; including the one from our old website &mdash; stops working.', '18px 32px 0', 13.5),
    K.notice('Did not ask for this? Ignore this email. Your password has not changed and nobody has been given access.', 'ink'),
    K.gap(28),
  ], 'Sent because a password reset was requested for this address.', false, false)],
  '18-verify-email': ['Verify email · account created', 'On', () => simple('Confirm your email', 'Confirm this address so we can send your receipts here.', [
    K.hero({ icon: 'mail', tone: 'pink', eyebrow: 'Almost there', title: 'Confirm your email address', lead: 'Tap the button to confirm this is your address. The link works for 24 hours.' }),
    K.button('Confirm my email', '#'), K.gap(28),
  ], 'Sent because this address was used to create an account at extrabeauty.ae.', false, false)],
  '19-feedback-request': ['Feedback · automatically 3 hours after the Delivered email', 'On', () => simple('How is your glow, Aisha? 💌', 'Three hours with your new K-beauty — tap a star for each product.', [
    K.hero({ icon: 'star', tone: 'pink', eyebrow: 'Your opinion matters', title: 'How is your glow, Aisha? 💌', lead: 'By now your order has arrived. One tap per product tells us &mdash; and other shoppers in the UAE &mdash; what is worth it.' }),
    K.sectionTitle('Tap a star for each product'),
    K.row(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">${ITEMS.map((p) => K.rateRow(p, 'https://extrabeauty.ae/product/' + p.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') + '/')).join('')}</table>`, '0 32px 0'),
    K.para('<div style="text-align:center">It takes ten seconds and opens the product page with your stars already chosen.</div>', '12px 32px 0', 12.5),
    K.notice('<b>Share your routine 📸</b> &mdash; post your shelfie and tag <b>@kbeauty.bliss</b>.', 'pink'),
    K.button('Write a review', '#'),
    K.para('<div style="text-align:center">Something not right? Reply to this email or WhatsApp us &mdash; a real person will sort it out.</div>', '14px 32px 0', 13),
    K.signoff(['Thank you for choosing us,', 'the K Beauty Bliss team']),
  ], WHY_ORDER)],
};

/* ---------------------------------------------------------------- marketing */
const GRID = [
  { brand: 'Round Lab', name: 'Birch Juice Moisturizing Sunscreen 50ml', price: 'AED 79.00', was: 'AED 95.00', img: L('p-roundlab') },
  { brand: 'SKIN1004', name: 'Madagascar Centella Ampoule 55ml', price: 'AED 72.00', img: L('p-skin1004') },
  { brand: 'Laneige', name: 'Lip Sleeping Mask Berry 20g', price: 'AED 85.00', img: L('p-laneige') },
  { brand: 'Beauty of Joseon', name: 'Glow Deep Serum Rice + Arbutin 30ml', price: 'AED 115.00', img: L('p-boj') },
];
/* Sample products and prices for the brand campaign — the real block fills
   from the catalogue at send time. */
const MEDICUBE = [
  { brand: 'Medicube', name: 'Zero Pore Pad 2.0 (70 pads)', price: 'AED [price]', img: L('p-medicube-pad') },
  { brand: 'Medicube', name: 'PDRN Pink Peptide Serum 30ml', price: 'AED [price]', img: L('p-medicube-serum') },
  { brand: 'Medicube', name: 'Collagen Night Wrapping Mask 75ml', price: 'AED [price]', img: L('p-medicube-mask') },
  { brand: 'Medicube', name: 'Deep Vita C Capsule Cream 55g', price: 'AED [price]', img: L('p-medicube-cream') },
];
const MARKETING = {
  'm1-campaign-autumn-glow': ['Campaign · to Subscribers', () => simple('Autumn Glow Edit', '15% off the glow edit this week — your code is inside.', [
    K.heroImage('../assets/banner-autumn.jpg', 'Autumn Glow Edit — skin that glows back'),
    K.row(`<div class="ink" style="font-family:${K.SERIF};font-size:26px;line-height:1.25;font-weight:700;color:#2A2228;text-align:center;">Hi Aisha, your glow edit is here</div>`, '28px 32px 0'),
    K.para('<div style="text-align:center">Four of this season&rsquo;s most-loved picks for dewy, even skin.</div>', '10px 40px 0'),
    K.productGrid(GRID, { cols: 2 }),
    K.coupon({ code: 'GLOW15', line: '15% off everything in the Glow Edit', expires: 'Ends Sunday 12 October, midnight' }),
    K.button('Shop the Glow Edit', '#'), K.gap(30),
  ], 'You are receiving this because you subscribed to K Beauty Bliss emails.', true)],
  'm2-campaign-we-miss-you': ['Campaign · to Customers, no order in 90 days', () => simple('We miss you', 'It has been a while — here is 10% off your next order.', [
    K.hero({ icon: 'heart', eyebrow: 'It has been a while', title: 'We saved you something', lead: 'Your skin has changed since your last order &mdash; so has our shelf. Here is 10% off to come and see.' }),
    K.coupon({ code: 'MISSYOU10', line: '10% off your next order', expires: 'Valid 14 days &middot; one use per customer' }),
    K.sectionTitle('New since your last visit', '26px 32px 0'),
    K.productGrid(GRID.slice(0, 2), { cols: 2 }),
    K.button('Come back and shop', '#'), K.gap(30),
  ], 'You are receiving this because you bought from K Beauty Bliss before.', true)],
  'm3-campaign-medicube-fans': ['Campaign · to group “Mostly bought Medicube” — product block auto-filled with that brand', () => simple('More Medicube for you', 'New Medicube picks, chosen because you love the brand.', [
    K.hero({ icon: 'spark', eyebrow: 'Picked for you', title: 'More Medicube, just for you', lead: 'You keep coming back to Medicube, so here is what is new and best-loved from the brand.' }),
    K.productGrid(MEDICUBE, { cols: 2 }),
    K.coupon({ code: 'MEDI10', line: '10% off Medicube this week', expires: '[expiry you set on the coupon]' }),
    K.button('Shop all Medicube', 'https://extrabeauty.ae/brand/medicube/'), K.gap(30),
  ], 'You are receiving this because you bought from K Beauty Bliss before.', true)],
};

(async () => {
  await drawAssets();
  for (const d of ['after', 'marketing']) {
    const dir = path.join(ROOT, d);
    fs.mkdirSync(dir, { recursive: true });
    for (const f of fs.readdirSync(dir)) if (f.endsWith('.html')) fs.unlinkSync(path.join(dir, f));
  }
  const index = {};
  for (const [name, [trigger, state, build]] of Object.entries(EMAILS)) {
    const html = build();
    fs.writeFileSync(path.join(ROOT, 'after', `${name}.html`), html);
    index[name] = { trigger, default: state, html_bytes: Buffer.byteLength(html) };
  }
  fs.writeFileSync(path.join(ROOT, 'after', 'index.json'), JSON.stringify(index, null, 2) + '\n');
  const mindex = {};
  for (const [name, [trigger, build]] of Object.entries(MARKETING)) {
    const html = build();
    fs.writeFileSync(path.join(ROOT, 'marketing', `${name}.html`), html);
    mindex[name] = { trigger, html_bytes: Buffer.byteLength(html) };
  }
  fs.writeFileSync(path.join(ROOT, 'marketing', 'index.json'), JSON.stringify(mindex, null, 2) + '\n');
  const max = Math.max(...Object.values(index).map((v) => v.html_bytes), ...Object.values(mindex).map((v) => v.html_bytes));
  console.log(`after: ${Object.keys(EMAILS).length}, marketing: ${Object.keys(MARKETING).length}, largest HTML ${max} bytes (Gmail clips at 102,400)`);
})();
