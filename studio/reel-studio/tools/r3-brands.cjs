'use strict';
// KBB Studio · R3 · writes the two seed brand kits (brands/kbb, brands/planet-careers): logos as SVG + kit.json.
//   node tools/r3-brands.cjs
// KBB: the lotus is the shop's own mark (app/Support/LogoLockup.php paths, copied, not linked), shop colours from kbb.css.
// Planet Careers: an original mark designed here (planet + gold orbit + mortarboard + EU-style stars).
const fs = require('fs'), path = require('path');
const B = path.resolve(__dirname, '..', 'brands');
const w = (p, s) => { fs.mkdirSync(path.dirname(p), { recursive: true }); fs.writeFileSync(p, s); };

// ── KBB ──
const LOTUS = 'M154 103Q399 122 439 424Q194 343 154 103ZM521 2Q407 136 471 389Q640 185 521 2ZM548 387Q642 239 778 249Q738 393 548 387ZM2 481Q190 313 434 475Q189 595 2 481ZM534 456Q707 461 737 544Q613 589 534 456Z';
const K = { pink: '#C6395F', deep: '#A82F53', soft: '#FFF0F4', ink: '#2A2228', gold: '#BE8E2E' };
w(path.join(B, 'kbb/logo-mark.svg'), `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 780 582" width="780" height="582"><path fill="${K.pink}" d="${LOTUS}"/></svg>`);
w(path.join(B, 'kbb/logo-mark-white.svg'), `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 780 582" width="780" height="582"><path fill="#ffffff" d="${LOTUS}"/></svg>`);
const kbbFull = (fg, mark) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 300" width="1200" height="300"><g transform="translate(20 40) scale(0.38)"><path fill="${mark}" d="${LOTUS}"/></g><text x="340" y="182" font-family="'Playfair Display', Georgia, serif" font-weight="700" font-size="112" fill="${fg}">K-Beauty Bliss</text><text x="344" y="238" font-family="Poppins, Arial, sans-serif" font-weight="600" font-size="34" letter-spacing="9" fill="${mark}">KOREAN SKINCARE · UAE</text></svg>`;
w(path.join(B, 'kbb/logo.svg'), kbbFull(K.ink, K.pink));
w(path.join(B, 'kbb/logo-white.svg'), kbbFull('#ffffff', '#ffffff'));
const kbbKit = {
  v: 1, id: 'kbb', name: 'KBB', fullName: 'K-Beauty Bliss', industry: 'Beauty / K-beauty retail', industries: ['beauty', 'retail'],
  colors: { primary: K.pink, secondary: K.ink, accent: K.gold, text: '#ffffff', bg: '#000000', soft: K.soft, ink: K.ink },
  gradient: ['#C6395F', '#E8789A', '#FFF0F4'],
  fonts: {
    heading: { family: 'Playfair Display', fallback: 'Georgia, serif', files: [{ file: 'fonts/playfair-display.woff2', weight: '400 900' }] },
    body: { family: 'Poppins', fallback: 'Arial, sans-serif', files: [{ file: 'fonts/poppins-400.woff2', weight: 400 }, { file: 'fonts/poppins-600.woff2', weight: 600 }, { file: 'fonts/poppins-700.woff2', weight: 700 }] },
    arabic: { family: 'Cairo', fallback: 'Tahoma, sans-serif', files: [{ file: 'fonts/cairo-arabic.woff2', weight: '200 1000', unicodeRange: 'U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0898-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC' }, { file: 'fonts/cairo-latin.woff2', weight: '200 1000' }] },
  },
  logos: { mark: 'logo-mark.svg', full: 'logo.svg', light: 'logo-white.svg', markLight: 'logo-mark-white.svg', markPaths: { viewBox: [780, 582], paths: [{ d: LOTUS, fill: K.pink }] } },
  intro: { style: 'bloom', dur: 1.2, sfx: 'sparkle' }, outro: { style: 'reveal', dur: 1.5, sfx: 'ding' },
  endCard: { title: 'K-Beauty Bliss', sub: 'Authentic Korean skincare, delivered across the UAE', cta: ['Shop now at kbeautybliss.com', 'Link in bio', 'Order today'], ctaAr: ['تسوقي الآن', 'الرابط في البايو', 'اطلبي اليوم'], payments: ['pay/tabby-badge.svg', 'pay/tamara-badge.svg'], style: 'gradient' },
  contact: { website: 'kbeautybliss.com', phone: '', whatsapp: '', email: '', instagram: '', address: 'UAE' },
  contactLine: 'kbeautybliss.com',
  defaults: { captionStyle: 'soft-beauty', captionStyleAr: 'arabic-first', musicMoods: ['luxury', 'upbeat-pop', 'calm-spa'], favRecipes: ['kbb-unboxing', 'kbb-texture-demo', 'kbb-flash-sale', 'kbb-routine-steps'], aspect: '9:16' },
  motif: 'lotus',
  notes: 'Logo = the shop lotus (LogoLockup paths). Colours from the shop stylesheet (--pink #C6395F, --pink-ink #A82F53, --pink-soft #FFF0F4, --ink #2A2228, --gold #BE8E2E). Payment badges copied from Ad Studio for the end card. Edit contact details in the app.',
};
w(path.join(B, 'kbb/kit.json'), JSON.stringify(kbbKit, null, 1));

// ── Planet Careers ──
const P = { navy: '#0b2545', navy2: '#13315c', teal: '#13a89e', gold: '#f2c14e', sky: '#e8f4f3' };
const star = (x, y, r) => { let s = ''; for (let i = 0; i < 10; i++) { const a = -Math.PI / 2 + i * Math.PI / 5, rr = i % 2 ? r * 0.42 : r; s += (i ? 'L' : 'M') + (x + rr * Math.cos(a)).toFixed(1) + ' ' + (y + rr * Math.sin(a)).toFixed(1); } return s + 'Z'; };
const cx = 96, cy = 112, rx = 90, ry = 27, rot = -18;
const orbitBack = `M${cx - rx} ${cy}A${rx} ${ry} 0 0 1 ${cx + rx} ${cy}`, orbitFront = `M${cx + rx} ${cy}A${rx} ${ry} 0 0 1 ${cx - rx} ${cy}`;
const CAP = `M60 52L96 37L132 52L96 67Z`, CAPBASE = `M74 60V76Q96 88 118 76V60L96 69Z`;
const STARS = [star(170, 66, 9), star(184, 92, 6.5), star(160, 42, 5.5)].join('');
const mark = (bg = null) => `<defs><linearGradient id="pcg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${P.teal}"/><stop offset=".55" stop-color="${P.navy2}"/><stop offset="1" stop-color="${P.navy}"/></linearGradient><clipPath id="pcp"><circle cx="${cx}" cy="${cy}" r="54"/></clipPath></defs>${bg ? `<rect width="200" height="200" rx="40" fill="${bg}"/>` : ''}<g transform="rotate(${rot} ${cx} ${cy})"><path d="${orbitBack}" fill="none" stroke="${P.gold}" stroke-width="7" stroke-linecap="round" opacity=".85"/></g><circle cx="${cx}" cy="${cy}" r="54" fill="url(#pcg)"/><g clip-path="url(#pcp)" fill="#ffffff" opacity=".14"><path d="M42 124Q70 112 96 122T150 118V170H42Z"/><path d="M50 92Q72 84 88 92T120 88" fill="none" stroke="#fff" stroke-width="5"/></g><g transform="rotate(${rot} ${cx} ${cy})"><path d="${orbitFront}" fill="none" stroke="${P.gold}" stroke-width="7" stroke-linecap="round"/><circle cx="${cx - 40}" cy="${cy + 24.3}" r="7" fill="${P.teal}" stroke="#ffffff" stroke-width="2.5"/></g><path d="${CAPBASE}" fill="${P.navy}" stroke="#ffffff" stroke-width="2"/><path d="${CAP}" fill="${P.navy}" stroke="#ffffff" stroke-width="2.5" stroke-linejoin="round"/><path d="M96 52L128 56V78" fill="none" stroke="${P.gold}" stroke-width="3" stroke-linecap="round"/><circle cx="128" cy="81" r="4" fill="${P.gold}"/><path d="${STARS}" fill="${P.gold}"/>`;
w(path.join(B, 'planet-careers/logo-mark.svg'), `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200">${mark()}</svg>`);
w(path.join(B, 'planet-careers/logo-app-icon.svg'), `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200">${mark(P.sky)}</svg>`);
const pcFull = (fg, fg2) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 220" width="1000" height="220"><g transform="translate(6 10)">${mark()}</g><text x="230" y="122" font-family="Montserrat, Inter, Arial, sans-serif" font-weight="800" font-size="92" letter-spacing="-1" fill="${fg}">Planet<tspan fill="${fg2}" font-weight="600"> Careers</tspan></text><text x="234" y="176" font-family="Montserrat, Inter, Arial, sans-serif" font-weight="700" font-size="28" letter-spacing="8" fill="${P.gold}">STUDY · WORK · SETTLE ABROAD</text></svg>`;
w(path.join(B, 'planet-careers/logo.svg'), pcFull(P.navy, P.teal));
w(path.join(B, 'planet-careers/logo-white.svg'), pcFull('#ffffff', '#7fe0d8'));
const pcKit = {
  v: 1, id: 'planet-careers', name: 'Planet Careers', fullName: 'Planet Careers', industry: 'Immigration & study-visa consultancy', industries: ['immigration', 'education', 'consultancy'],
  colors: { primary: P.navy, secondary: P.teal, accent: P.gold, text: '#ffffff', bg: P.navy, soft: P.sky, ink: '#0a1b30' },
  gradient: [P.navy, P.navy2, P.teal],
  fonts: {
    heading: { family: 'Montserrat', fallback: 'Arial, sans-serif', files: [{ file: 'fonts/montserrat.woff2', weight: '100 900' }] },
    body: { family: 'Inter', fallback: 'Arial, sans-serif', files: [{ file: 'fonts/inter.woff2', weight: '100 900' }] },
    arabic: { family: 'Cairo', fallback: 'Tahoma, sans-serif', files: [{ file: 'fonts/cairo-arabic.woff2', weight: '200 1000', unicodeRange: 'U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891, U+0898-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC' }, { file: 'fonts/cairo-latin.woff2', weight: '200 1000' }] },
  },
  logos: { mark: 'logo-mark.svg', full: 'logo.svg', light: 'logo-white.svg', appIcon: 'logo-app-icon.svg', markPaths: { viewBox: [200, 200], paths: [{ d: `M${cx} ${cy - 54}a54 54 0 1 0 0.01 0Z`, fill: P.teal }, { d: CAPBASE, fill: P.navy }, { d: CAP, fill: P.navy }, { d: STARS, fill: P.gold }], orbit: { cx, cy, rx, ry, rot, stroke: P.gold } } },
  intro: { style: 'orbit', dur: 1.4, sfx: 'whoosh' }, outro: { style: 'reveal', dur: 1.5, sfx: 'success-chime' },
  endCard: { title: 'Planet Careers', sub: 'Study visas · work permits · settling abroad', cta: ['Book your free consultation', 'DM us "VISA" to start', 'Call or WhatsApp us today'], ctaAr: ['احجز استشارتك المجانية', 'راسلنا كلمة "فيزا"', 'اتصل أو راسلنا واتساب اليوم'], style: 'gradient' },
  contact: { website: '', phone: '', whatsapp: '', email: '', instagram: '', address: 'UAE' },
  contactLine: '<edit: phone · WhatsApp · website>',
  defaults: { captionStyle: 'bold-outline', captionStyleAr: 'bilingual', musicMoods: ['corporate', 'inspiring', 'travel'], favRecipes: ['pc-study-visa-steps', 'pc-country-spotlight', 'pc-success-story', 'pc-appointment-cta'], aspect: '9:16' },
  motif: 'eu-stars',
  packs: { countries: 'countries/countries.json' },
  notes: 'Original mark (planet, gold orbit, mortarboard, stars) drawn for KBB Studio; replace logo files and colours when the final identity is ready. Country facts are placeholders: verify every visa/study/work rule on official sites before publishing.',
};
w(path.join(B, 'planet-careers/kit.json'), JSON.stringify(pcKit, null, 1));
console.log('kits written');
