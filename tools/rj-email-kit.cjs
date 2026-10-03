/*
 * Lane RJ — the PROPOSED email design system, as code, so every preview is
 * built from the same blocks and a change to one block changes every email.
 *
 * This is a PREVIEW KIT. It is not wired into the shop and nothing here is
 * sent. Phase 2 ports these blocks to Blade components (one per block) behind
 * the existing Mailables; the HTML they emit is the target.
 *
 * EMAIL-SAFE BY CONSTRUCTION
 *   - tables for layout, inline styles on every element, bgcolor attributes
 *     beside background styles (Outlook/Word reads the attribute);
 *   - 600px max (`width="600"` + `max-width:600px`), fluid below that, so a
 *     phone gets one column WITHOUT relying on media queries; the one media
 *     query only tightens padding and stacks two-column rows;
 *   - Outlook for Windows gets a fixed 600px ghost table (<!--[if mso]>), and
 *     buttons are padded table cells (Word drops padding on an <a>);
 *   - system font stacks only (no web fonts: Gmail strips @font-face);
 *   - a hidden preheader, `color-scheme` meta and a dark-mode block that Apple
 *     Mail / iOS Mail honour; Gmail's app applies its own inversion regardless.
 */

const P = {
  cream: '#FFF8F5', pinkSoft: '#FFF0F4', blush: '#FCE0E8', pink: '#E0567B', pinkDeep: '#C13E63',
  pinkInk: '#A82F53', ink: '#2A2228', ink2: '#5E545A', muted: '#8C828A', line: '#F0E4E9',
  green: '#2E9E6B', greenSoft: '#E6F5EE', amber: '#B86E12', amberSoft: '#FDF1E1', red: '#C0392B', redSoft: '#FCEBEA',
  white: '#FFFFFF', ink0: '#141013',
};
const SANS = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
const SERIF = "Georgia,'Times New Roman',Times,serif";

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const SHOP = 'https://extrabeauty.ae';

/* ------------------------------------------------------------------ shell */
function doc({ title, preheader, body, theme = 'A', dir = 'ltr' }) {
  const t = THEMES[theme];
  return `<!doctype html>
<html lang="en" dir="${dir}" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no,address=no,email=no,date=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>${esc(title)}</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><style>td,th,p,a,span,div{font-family:Arial,sans-serif!important}</style><![endif]-->
<style>
  body{margin:0!important;padding:0!important;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
  img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}
  a{text-decoration:none}
  @media only screen and (max-width:480px){
    .px{padding-left:18px!important;padding-right:18px!important}
    .stack{display:block!important;width:100%!important;max-width:100%!important;padding-left:0!important;padding-right:0!important}
    .stack-gap{padding-top:14px!important}
    .h1{font-size:25px!important;line-height:1.22!important}
    .hide-sm{display:none!important;max-height:0!important;overflow:hidden!important}
    .pimg{width:64px!important;height:64px!important}
    .gpad{padding:5px!important}
    .center-sm{text-align:center!important}
    .btn-full{display:block!important;width:100%!important}
  }
  @media (prefers-color-scheme:dark){
    .bg-outer{background:#17111A!important}
    .card{background:#211921!important}
    .soft{background:#2E2229!important}
    .ink{color:#F7EEF2!important}
    .ink2{color:#D7C8CF!important}
    .muted{color:#A898A0!important}
    .line{border-color:#3A2B33!important}
    .wm-ink{color:#F7EEF2!important}
  }
  [data-ogsc] .ink{color:#F7EEF2!important}
  [data-ogsb] .card{background:#211921!important}
</style>
</head>
<body class="bg-outer" style="margin:0;padding:0;background:${t.outer};" bgcolor="${t.outer}">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:${t.outer};opacity:0;">${esc(preheader)}&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" class="bg-outer" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="${t.outer}" style="width:100%;background:${t.outer};border-collapse:collapse;">
<tr><td align="center" style="padding:${t.outerPad};">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:collapse;">
${body}
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body>
</html>
`;
}

/* A white card that holds a run of blocks; the theme decides its corners. */
function card(inner, theme = 'A', { top = true, bottom = true } = {}) {
  const t = THEMES[theme];
  const r = `${top ? t.radius : '0'} ${top ? t.radius : '0'} ${bottom ? t.radius : '0'} ${bottom ? t.radius : '0'}`;
  return `<tr><td class="card" bgcolor="${P.white}" style="background:${P.white};border-radius:${r};${t.cardBorder}">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
${inner}
</table></td></tr>`;
}
const gap = (h = 14) => `<tr><td style="height:${h}px;line-height:${h}px;font-size:0;">&nbsp;</td></tr>`;
const row = (html, pad = '0 32px') => `<tr><td class="px" style="padding:${pad};font-family:${SANS};">${html}</td></tr>`;

/* --------------------------------------------------------------- blocks */

/* The thin line above the card: a promise the store already makes on its
   masthead (email.layout.masthead_tagline), plus "view in browser". */
function topbar(text = 'Authentic K-beauty, curated for you', theme = 'A') {
  const t = THEMES[theme];
  return `<tr><td style="padding:0 6px 10px;font-family:${SANS};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td class="muted" style="font-size:11.5px;letter-spacing:.06em;color:${t.topInk};">${esc(text)}</td>
<td class="muted hide-sm" align="right" style="font-size:11.5px;color:${t.topInk};"><a href="#" style="color:${t.topInk};text-decoration:underline;">View in browser</a></td>
</tr></table></td></tr>`;
}

/* Logo slot. With a logo uploaded (Emails → Branding) the <img> goes here at
   up to 170px wide; without one, the storefront's own wordmark prints in text,
   which every client shows even with images blocked. */
function header(theme = 'A', { nav = true, logo = null } = {}) {
  const t = THEMES[theme];
  const mark = logo
    ? `<img src="${logo}" width="170" alt="K-Beauty Bliss" style="display:block;width:170px;max-width:170px;height:auto;margin:0 auto;">`
    : `<div class="wm-ink" style="font-family:${t.wordFont};font-size:${t.wordSize};font-weight:${t.wordWeight};letter-spacing:${t.wordTrack};color:${t.wordInk};">K-Beauty<span style="color:${t.wordAccent};">Bliss</span></div>`;
  const links = nav
    ? `<div style="margin-top:12px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;">
<a class="ink2" href="${SHOP}/shop/" style="color:${t.navInk};font-weight:600;">Shop</a><span style="color:${t.navDot};">&nbsp;&nbsp;&bull;&nbsp;&nbsp;</span><a class="ink2" href="${SHOP}/track-my-order/" style="color:${t.navInk};font-weight:600;">Track order</a><span style="color:${t.navDot};">&nbsp;&nbsp;&bull;&nbsp;&nbsp;</span><a class="ink2" href="${SHOP}/my-account/" style="color:${t.navInk};font-weight:600;">My account</a></div>`
    : '';
  return `<tr><td align="center" bgcolor="${t.headBg}" class="${t.headClass}" style="background:${t.headBg};padding:${t.headPad};border-radius:${t.radius} ${t.radius} 0 0;font-family:${SANS};">${mark}${links}</td></tr>
${t.headRule ? `<tr><td bgcolor="${t.headRule}" style="background:${t.headRule};height:3px;line-height:3px;font-size:0;">&nbsp;</td></tr>` : ''}`;
}

const ICONS = {
  heart: '&#10084;', check: '&#10003;', box: '&#128230;', truck: '&#128666;', gift: '&#127873;',
  pause: '&#10074;&#10074;', cross: '&#10005;', back: '&#8634;', card: '&#128179;', clock: '&#9719;',
  bell: '&#128276;', bag: '&#128717;', mail: '&#9993;', key: '&#128273;', spark: '&#10024;', star: '&#9733;',
};
const TONES = {
  pink: [P.pinkSoft, P.pinkDeep], green: [P.greenSoft, P.green], amber: [P.amberSoft, P.amber],
  red: [P.redSoft, P.red], ink: ['#F1EDEF', P.ink],
};

/* The status hero: one icon, one eyebrow, one headline, one sentence. */
function hero({ icon = 'heart', tone = 'pink', eyebrow, title, lead, theme = 'A' }) {
  const t = THEMES[theme];
  const [bg, fg] = TONES[tone];
  if (theme === 'B') {
    return `<tr><td align="center" bgcolor="${P.pink}" style="background:${P.pink};background-image:linear-gradient(160deg,${P.pink} 0%,${P.pinkDeep} 100%);padding:34px 28px 30px;font-family:${SANS};">
<div style="width:58px;height:58px;line-height:58px;border-radius:29px;background:${P.white};color:${P.pinkDeep};font-size:26px;margin:0 auto 14px;text-align:center;">${ICONS[icon]}</div>
<div style="font-size:11.5px;letter-spacing:.16em;text-transform:uppercase;color:#FFE3EB;font-weight:700;">${esc(eyebrow)}</div>
<div class="h1" style="margin-top:8px;font-size:30px;line-height:1.18;font-weight:800;color:${P.white};">${esc(title)}</div>
<div style="margin:12px auto 0;max-width:440px;font-size:15.5px;line-height:1.6;color:#FFF1F5;">${lead}</div>
</td></tr>`;
  }
  const iconCell = theme === 'C'
    ? `<div style="font-size:11.5px;letter-spacing:.2em;text-transform:uppercase;color:${P.pinkDeep};font-weight:700;">${esc(eyebrow)}</div>`
    : `<div style="width:54px;height:54px;line-height:54px;border-radius:27px;background:${bg};color:${fg};font-size:24px;margin:0 auto 14px;text-align:center;">${ICONS[icon]}</div>
<div style="font-size:11.5px;letter-spacing:.16em;text-transform:uppercase;color:${fg};font-weight:700;">${esc(eyebrow)}</div>`;
  return `<tr><td class="px" align="${theme === 'C' ? 'left' : 'center'}" style="padding:${theme === 'C' ? '38px 36px 6px' : '30px 32px 6px'};font-family:${SANS};">
${iconCell}
<div class="h1 ink" style="margin-top:8px;font-family:${t.headFont};font-size:${t.h1};line-height:1.2;font-weight:${t.h1Weight};color:${P.ink};letter-spacing:${t.h1Track};">${esc(title)}</div>
<div class="ink2" style="margin:12px ${theme === 'C' ? '0' : 'auto'} 0;max-width:460px;font-size:15.5px;line-height:1.6;color:${P.ink2};">${lead}</div>
</td></tr>`;
}

/* Placed → Confirmed → Shipped → Delivered. `at` is the index reached; a
   stopped order (cancelled / failed) draws the reached part and a cross. */
function tracker(at, { stopped = null, labels = ['Placed', 'Confirmed', 'On its way', 'Delivered'], theme = 'A' } = {}) {
  const done = stopped ? P.muted : (theme === 'C' ? P.ink : P.pink);
  const cells = labels.map((label, i) => {
    const reached = i <= at;
    const isStop = stopped && i === at + 1;
    const dotBg = isStop ? P.red : reached ? done : P.white;
    const dotBorder = isStop ? P.red : reached ? done : '#E4D6DC';
    const glyph = isStop ? '&#10005;' : reached ? '&#10003;' : '';
    const lab = isStop ? stopped : label;
    const labColor = isStop ? P.red : reached ? P.ink : P.muted;
    const left = i === 0 ? 'transparent' : (i <= at ? done : (isStop ? P.red : '#EADDE3'));
    const right = i === labels.length - 1 ? 'transparent' : (i < at ? done : '#EADDE3');
    return `<td width="25%" align="center" valign="top" style="width:25%;font-family:${SANS};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="50%" style="padding-top:11px;"><div style="height:3px;line-height:3px;font-size:0;background:${left};">&nbsp;</div></td>
<td width="26" style="width:26px;"><div style="width:22px;height:22px;line-height:22px;border-radius:13px;background:${dotBg};border:2px solid ${dotBorder};color:${P.white};font-size:12px;font-weight:700;text-align:center;">${glyph}</div></td>
<td width="50%" style="padding-top:11px;"><div style="height:3px;line-height:3px;font-size:0;background:${right};">&nbsp;</div></td>
</tr></table>
<div class="${reached ? 'ink' : 'muted'}" style="margin-top:7px;font-size:12px;line-height:1.3;font-weight:${reached || isStop ? 700 : 500};color:${labColor};">${esc(lab)}</div>
</td>`;
  }).join('');
  return `<tr><td class="px" style="padding:22px 24px 4px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>${cells}</tr></table></td></tr>`;
}

function orderChip(o, extra = '', theme = 'A') {
  const t = THEMES[theme];
  return row(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="soft" bgcolor="${t.chipBg}" style="background:${t.chipBg};border-radius:${t.chipRadius};border:1px solid ${t.chipLine};">
<tr><td style="padding:14px 18px;font-family:${SANS};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td valign="top" style="font-size:13px;color:${P.ink2};"><span class="muted" style="font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:${P.muted};font-weight:700;">Order</span><br><span style="font-size:18px;font-weight:800;color:${P.pinkDeep};">${o.number}</span></td>
<td valign="top" style="font-size:13px;color:${P.ink2};"><span class="muted" style="font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:${P.muted};font-weight:700;">Placed</span><br><span class="ink" style="font-size:14px;font-weight:600;color:${P.ink};">${o.placed}</span></td>
<td valign="top" style="font-size:13px;color:${P.ink2};"><span class="muted" style="font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:${P.muted};font-weight:700;">Total</span><br><span class="ink" style="font-size:14px;font-weight:600;color:${P.ink};">${o.total}</span></td>
</tr></table>${extra}</td></tr></table>`, '18px 32px 0');
}

function sectionTitle(text, pad = '26px 32px 8px') {
  return row(`<div class="muted" style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:${P.muted};font-weight:700;">${esc(text)}</div>`, pad);
}

/* Product lines WITH pictures — today's order emails carry none. The picture
   is the product's own main image, resized to a 128px square at send time. */
function items(list, { showPrice = true } = {}) {
  const rows = list.map((it, i) => `<tr><td style="padding:12px 0;${i ? `border-top:1px solid ${P.line};` : ''}" class="line">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="76" valign="top" style="width:76px;"><img class="pimg" src="${it.img}" width="64" height="64" alt="${esc(it.name)}" style="display:block;width:64px;height:64px;border-radius:10px;background:${P.pinkSoft};"></td>
<td valign="top" style="font-family:${SANS};">
<div class="muted" style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:${P.pinkDeep};font-weight:700;">${esc(it.brand)}</div>
<div class="ink" style="margin-top:2px;font-size:14.5px;line-height:1.35;font-weight:600;color:${P.ink};">${esc(it.name)}</div>
<div class="muted" style="margin-top:3px;font-size:12.5px;color:${P.muted};">${it.variant ? esc(it.variant) + ' &middot; ' : ''}Qty ${it.qty}${showPrice && it.qty > 1 ? ` &middot; ${it.unit} each` : ''}</div>
</td>
${showPrice ? `<td width="96" align="right" valign="top" class="ink" style="width:96px;font-family:${SANS};font-size:14.5px;font-weight:700;color:${P.ink};white-space:nowrap;">${it.total}</td>` : ''}
</tr></table></td></tr>`).join('');
  return row(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">${rows}</table>`, '0 32px');
}

function totals(lines, grand) {
  const rows = lines.map(([k, v, accent]) => `<tr>
<td class="ink2" style="padding:5px 0;font-family:${SANS};font-size:14px;color:${P.ink2};">${k}</td>
<td align="right" style="padding:5px 0;font-family:${SANS};font-size:14px;color:${accent ? P.green : P.ink};font-weight:${accent ? 700 : 500};" class="${accent ? '' : 'ink'}">${v}</td></tr>`).join('');
  return row(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid ${P.line};" class="line">
<tr><td colspan="2" style="height:8px;font-size:0;line-height:8px;">&nbsp;</td></tr>${rows}
<tr><td colspan="2" style="padding-top:10px;border-bottom:1px solid ${P.line};" class="line"></td></tr>
<tr><td class="ink" style="padding:14px 0 4px;font-family:${SANS};font-size:16px;font-weight:800;color:${P.ink};">${grand[0]}</td>
<td align="right" style="padding:14px 0 4px;font-family:${SANS};font-size:20px;font-weight:800;color:${P.pinkDeep};">${grand[1]}</td></tr>
${grand[2] ? `<tr><td colspan="2" class="muted" align="right" style="font-family:${SANS};font-size:12px;color:${P.muted};">${grand[2]}</td></tr>` : ''}
</table>`, '6px 32px 0');
}

/* Two boxes side by side on a laptop, stacked on a phone. */
function infoPair(left, right) {
  const box = (title, html) => `<div class="muted" style="font-size:11px;letter-spacing:.13em;text-transform:uppercase;color:${P.muted};font-weight:700;margin-bottom:6px;">${esc(title)}</div><div class="ink" style="font-size:14px;line-height:1.6;color:${P.ink};">${html}</div>`;
  return row(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td class="stack" width="50%" valign="top" style="width:50%;padding-right:12px;font-family:${SANS};">${box(left[0], left[1])}${left[2] ? `<div style="height:14px"></div>${box(left[2], left[3])}` : ''}</td>
<td class="stack stack-gap" width="50%" valign="top" style="width:50%;padding-left:12px;font-family:${SANS};">${box(right[0], right[1])}${right[2] ? `<div style="height:14px"></div>${box(right[2], right[3])}` : ''}</td>
</tr></table>`, '24px 32px 0');
}

/* Bulletproof button: the cell carries the colour, so Outlook keeps it. */
function button(label, href = '#', { theme = 'A', align = 'center', ghost = false } = {}) {
  const t = THEMES[theme];
  const bg = ghost ? P.white : t.btnBg;
  const fg = ghost ? t.btnBg : P.white;
  return row(`<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="${align}" style="margin:0 ${align === 'center' ? 'auto' : '0'};">
<tr><td align="center" bgcolor="${bg}" style="background:${bg};border-radius:${t.btnRadius};${ghost ? `border:2px solid ${t.btnBg};` : ''}">
<a href="${href}" style="display:inline-block;padding:15px 34px;font-family:${SANS};font-size:15px;font-weight:700;color:${fg};text-decoration:none;letter-spacing:.02em;">${esc(label)}</a>
</td></tr></table>`, '26px 32px 0');
}

function para(html, pad = '18px 32px 0', size = 15) {
  return row(`<div class="ink2" style="font-size:${size}px;line-height:1.65;color:${P.ink2};">${html}</div>`, pad);
}

function notice(html, tone = 'amber') {
  const [bg, fg] = TONES[tone];
  return row(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="${bg}" style="background:${bg};border-radius:12px;border-left:4px solid ${fg};"><tr>
<td style="padding:14px 18px;font-family:${SANS};font-size:14px;line-height:1.6;color:${P.ink};" class="ink">${html}</td></tr></table>`, '20px 32px 0');
}

/* "We are here if you need us" — the store's real channels from Store → Mail. */
function help(theme = 'A') {
  const t = THEMES[theme];
  const ch = (bg, glyph, value, label, href) => `<td class="stack" valign="top" style="padding:6px 6px;font-family:${SANS};">
<a href="${href}" style="text-decoration:none;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="34" style="width:34px;"><div style="width:28px;height:28px;line-height:28px;border-radius:14px;background:${bg};color:${P.white};font-size:13px;font-weight:700;text-align:center;">${glyph}</div></td>
<td><div class="ink" style="font-size:13.5px;font-weight:700;color:${P.ink};">${value}</div><div class="muted" style="font-size:11.5px;color:${P.muted};">${label}</div></td>
</tr></table></a></td>`;
  return row(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="soft" bgcolor="${t.helpBg}" style="background:${t.helpBg};border-radius:${t.chipRadius};">
<tr><td style="padding:18px 18px 4px;font-family:${SANS};">
<div class="ink" style="font-size:15px;font-weight:800;color:${P.ink};">Questions? A real person answers.</div>
<div class="ink2" style="margin-top:3px;font-size:13px;line-height:1.55;color:${P.ink2};">About your order, or about what to use it with — just ask.</div></td></tr>
<tr><td style="padding:8px 12px 14px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
${ch(P.green, 'W', '+971 58 505 2611', 'WhatsApp', 'https://wa.me/971585052611')}
${ch(P.pinkDeep, '@', 'care@extrabeauty.ae', 'Email us', 'mailto:care@extrabeauty.ae')}
${ch(P.pink, 'IG', '@kbeauty.bliss', 'Instagram', 'https://www.instagram.com/kbeauty.bliss/')}
</tr></table></td></tr></table>`, '28px 32px 0');
}

function signoff(lines = ['With love,', 'the K Beauty Bliss team']) {
  return row(`<div class="ink" style="font-family:${SERIF};font-size:16px;line-height:1.55;color:${P.ink};font-style:italic;">${lines.map(esc).join('<br>')}</div>`, '26px 32px 30px');
}

/* Below the card. Transactional: why you got it. Marketing: the unsubscribe
   and the postal address the bulk-sender rules require. */
function footer({ why, unsubscribe = false, theme = 'A' }) {
  const t = THEMES[theme];
  return `<tr><td align="center" style="padding:22px 26px 6px;font-family:${SANS};font-size:12px;line-height:1.65;color:${t.footInk};" class="muted">
<div style="font-weight:700;letter-spacing:.06em;color:${t.footStrong};">K Beauty Bliss &middot; extrabeauty.ae</div>
<div style="margin-top:6px;">${why}</div>
${unsubscribe ? `<div style="margin-top:8px;"><a href="#unsubscribe" style="color:${t.footStrong};text-decoration:underline;font-weight:600;">Unsubscribe</a> &nbsp;&middot;&nbsp; <a href="#preferences" style="color:${t.footStrong};text-decoration:underline;">Email preferences</a></div>
<div style="margin-top:8px;">[Business postal address — set once in Emails → Branding]</div>` : ''}
<div style="margin-top:8px;"><a href="${SHOP}/" style="color:${t.footStrong};">Shop</a> &nbsp;&middot;&nbsp; <a href="${SHOP}/track-my-order/" style="color:${t.footStrong};">Track an order</a> &nbsp;&middot;&nbsp; <a href="${SHOP}/my-account/" style="color:${t.footStrong};">My account</a></div>
</td></tr>`;
}

/* ------------------------------------------------------------- marketing */
function productGrid(products, { cols = 2, cta = 'Shop now' } = {}) {
  const w = Math.floor(100 / cols);
  const cell = (p) => `<td class="gpad" width="${w}%" valign="top" style="width:${w}%;padding:8px;font-family:${SANS};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="soft" bgcolor="${P.cream}" style="background:${P.cream};border-radius:14px;"><tr><td style="padding:12px;" align="center">
<img src="${p.img}" width="200" alt="${esc(p.name)}" style="display:block;width:100%;max-width:200px;height:auto;border-radius:10px;">
<div class="muted" style="margin-top:10px;font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:${P.pinkDeep};font-weight:700;">${esc(p.brand)}</div>
<div class="ink" style="margin-top:3px;font-size:14px;line-height:1.35;font-weight:600;color:${P.ink};min-height:38px;">${esc(p.name)}</div>
<div style="margin-top:6px;font-size:15px;font-weight:800;color:${P.ink};" class="ink">${p.price}${p.was ? ` <span style="font-size:12.5px;font-weight:500;color:${P.muted};text-decoration:line-through;">${p.was}</span>` : ''}</div>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:10px auto 2px;"><tr><td bgcolor="${P.ink}" style="background:${P.ink};border-radius:99px;"><a href="#" style="display:inline-block;padding:8px 18px;font-size:12.5px;font-weight:700;color:${P.white};">${esc(cta)}</a></td></tr></table>
</td></tr></table></td>`;
  let rows = '';
  for (let i = 0; i < products.length; i += cols) rows += `<tr>${products.slice(i, i + cols).map(cell).join('')}</tr>`;
  return row(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">${rows}</table>`, '8px 24px 0');
}

function coupon({ code, line, expires }) {
  return row(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="${P.pinkSoft}" class="soft" style="background:${P.pinkSoft};border:2px dashed ${P.pink};border-radius:16px;">
<tr><td align="center" style="padding:22px 18px;font-family:${SANS};">
<div style="font-size:11.5px;letter-spacing:.16em;text-transform:uppercase;color:${P.pinkDeep};font-weight:700;">Your code</div>
<div style="margin-top:8px;font-family:'Courier New',Courier,monospace;font-size:28px;font-weight:700;letter-spacing:.14em;color:${P.ink};" class="ink">${esc(code)}</div>
<div class="ink2" style="margin-top:6px;font-size:14px;color:${P.ink2};">${line}</div>
<div class="muted" style="margin-top:4px;font-size:12px;color:${P.muted};">${expires}</div>
</td></tr></table>`, '22px 32px 0');
}

function heroImage(src, alt) {
  return `<tr><td style="padding:0;"><img src="${src}" width="600" alt="${esc(alt)}" style="display:block;width:100%;max-width:600px;height:auto;"></td></tr>`;
}

/* ----------------------------------------------------------------- themes */
const THEMES = {
  /* A — Blush editorial. The shop's own pinks, a Georgia headline for a
     magazine feel, soft cream ground, round 18px corners. DEFAULT. */
  A: {
    outer: P.cream, outerPad: '22px 10px 30px', radius: '18px', cardBorder: '',
    chipBg: P.cream, chipLine: P.line, chipRadius: '12px', helpBg: P.pinkSoft,
    headBg: P.pinkSoft, headClass: 'soft', headPad: '26px 24px 20px', headRule: P.pink,
    wordFont: SANS, wordSize: '26px', wordWeight: 800, wordTrack: '-.02em', wordInk: P.ink, wordAccent: P.pinkDeep,
    navInk: P.ink2, navDot: P.pink, headFont: SERIF, h1: '28px', h1Weight: 700, h1Track: '-.01em',
    btnBg: P.pinkDeep, btnRadius: '99px', topInk: P.muted, footInk: P.muted, footStrong: P.ink2,
  },
  /* B — Bold pink. A full-bleed gradient hero in the accent, white type,
     the most "celebration" of the three. */
  B: {
    outer: '#FCE9EF', outerPad: '22px 10px 30px', radius: '22px', cardBorder: '',
    chipBg: P.pinkSoft, chipLine: P.blush, chipRadius: '16px', helpBg: P.pinkSoft,
    headBg: P.white, headClass: 'card', headPad: '22px 24px 18px', headRule: null,
    wordFont: SANS, wordSize: '25px', wordWeight: 900, wordTrack: '-.03em', wordInk: P.ink, wordAccent: P.pink,
    navInk: P.ink2, navDot: P.pink, headFont: SANS, h1: '28px', h1Weight: 800, h1Track: '-.02em',
    btnBg: P.pink, btnRadius: '14px', topInk: '#B07A8A', footInk: '#A07C88', footStrong: P.pinkInk,
  },
  /* C — Minimal luxe. White on white, ink type, hairlines, pink only as an
     accent — closest to a premium skincare house. */
  C: {
    outer: '#F6F3F4', outerPad: '22px 10px 30px', radius: '4px', cardBorder: `border:1px solid #ECE4E8;`,
    chipBg: P.white, chipLine: '#E2D8DD', chipRadius: '2px', helpBg: '#F7F4F5',
    headBg: P.white, headClass: 'card', headPad: '30px 24px 22px', headRule: P.ink,
    wordFont: SERIF, wordSize: '27px', wordWeight: 400, wordTrack: '.04em', wordInk: P.ink, wordAccent: P.pinkDeep,
    navInk: P.ink, navDot: '#C9BBC2', headFont: SERIF, h1: '30px', h1Weight: 400, h1Track: '0',
    btnBg: P.ink, btnRadius: '2px', topInk: P.muted, footInk: P.muted, footStrong: P.ink,
  },
};

module.exports = {
  P, SANS, SERIF, THEMES, esc, doc, card, gap, row, topbar, header, hero, tracker, orderChip, sectionTitle,
  items, totals, infoPair, button, para, notice, help, signoff, footer, productGrid, coupon, heroImage,
};
