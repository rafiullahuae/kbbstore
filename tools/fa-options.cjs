/*
 * Lane FA: the six footer "get the app" rows, A-F, as PREVIEW markup + CSS.
 *
 * Not shop code. tools/fa-shots.cjs draws each one into the REAL footer of a
 * booted preview (tools/fa-preview.sh) and photographs it; tools/fa-index.cjs
 * builds docs/fa-preview/index.html from the same source, so the shots and the
 * index cannot disagree. Whichever letter the owner picks becomes a Blade
 * partial + kbb.css rules in the build lane; nothing here ships as-is.
 *
 * Glyphs are drawn here (no logo files fetched): an apple, a little robot head
 * and a tablet. Every class is kfa- so nothing on the shop collides.
 * CSS only: no layout-measuring script, no request until the button is tapped.
 */

const G = {
  apple: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.4 12.7c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.2-2.8.9-3.5.9-.8 0-1.8-.8-3-.8-1.5 0-3 .9-3.8 2.3-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.4 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7c1.3 0 2-1.1 2.8-2.3.9-1.3 1.2-2.5 1.3-2.6-.1 0-2.5-.9-2.5-3.8ZM14.2 5.9c.6-.8 1.1-1.8 1-2.9-.9 0-2.1.6-2.7 1.4-.6.7-1.1 1.7-1 2.8 1 .1 2-.5 2.7-1.3Z"/></svg>',
  android: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.6 4.2a.5.5 0 0 1 .7.1l1.2 1.8a6.8 6.8 0 0 1 5 0l1.2-1.8a.5.5 0 1 1 .8.6l-1.1 1.7A6 6 0 0 1 18.5 11h-13a6 6 0 0 1 3.1-4.4L7.5 4.9a.5.5 0 0 1 .1-.7ZM9.6 8.2a.9.9 0 1 0 0 1.8.9.9 0 0 0 0-1.8Zm4.8 0a.9.9 0 1 0 0 1.8.9.9 0 0 0 0-1.8Z"/><path d="M5.5 12h13v6.2a1.8 1.8 0 0 1-1.8 1.8H16v2a1.2 1.2 0 0 1-2.4 0v-2h-3.2v2A1.2 1.2 0 0 1 8 22v-2h-.7a1.8 1.8 0 0 1-1.8-1.8ZM2.6 12.2a1.2 1.2 0 0 1 2.4 0v4.6a1.2 1.2 0 0 1-2.4 0Zm16.4 0a1.2 1.2 0 0 1 2.4 0v4.6a1.2 1.2 0 0 1-2.4 0Z"/></svg>',
  ipad: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><rect x="4.2" y="2.4" width="15.6" height="19.2" rx="2.6"/><circle cx="12" cy="18.6" r=".9" fill="currentColor" stroke="none"/></svg>',
  dl: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m-4.5-4.5L12 15l4.5-4.5M5 19.5h14"/></svg>',
  spark: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 1.5c.6 4.6 2.9 6.9 7.5 7.5-4.6.6-6.9 2.9-7.5 7.5-.6-4.6-2.9-6.9-7.5-7.5 4.6-.6 6.9-2.9 7.5-7.5Z"/></svg>',
};
const glyphs = (cls = 'kfa-ic') => `<span class="${cls}" aria-hidden="true"><i>${G.apple}</i><i>${G.android}</i><i>${G.ipad}</i></span>`;

/* Three lines per option, EN + AR. Nothing promises a price or a perk the shop
   does not have: the alerts lines describe the Site App notifications the shop
   already sends (Lane NT/PN: order updates, back-in-stock, price drop on a
   wished item, a forgotten bag) and hold only while those stay switched on. */
const LINES = {
  A: [
    ['Your glow, one tap away.', 'إشراقتكِ على بُعد لمسة واحدة.'],
    ['K-beauty, right on your Home Screen.', 'الجمال الكوري على شاشتكِ الرئيسية.'],
    ['Your skincare shelf, in your pocket.', 'رفّ عنايتكِ بالبشرة في جيبكِ.'],
  ],
  B: [
    ['Your routine, one tap away. Get the app.', 'روتينكِ على بُعد لمسة. حمّلي التطبيق.'],
    ['Never miss a restock of your favourites.', 'لا تفوّتي عودة منتجاتكِ المفضّلة.'],
    ['Glow on the go. Add us to your Home Screen.', 'إشراقة أينما كنتِ. أضيفينا إلى شاشتكِ.'],
  ],
  C: [
    ['Restock & price-drop alerts, straight to your phone.', 'تنبيهات عودة المنتجات وانخفاض الأسعار على هاتفكِ.'],
    ['Follow your order from your Home Screen.', 'تابعي طلبكِ من شاشتكِ الرئيسية.'],
    ['Skin-first shopping, made for your phone.', 'تسوّق العناية بالبشرة، مصمَّم لهاتفكِ.'],
  ],
  D: [
    ['K-Beauty Bliss, in your pocket.', 'K-Beauty Bliss في جيبكِ.'],
    ['A 10-step routine deserves a 1-tap app.', 'روتين من ١٠ خطوات يستحق تطبيقاً بلمسة.'],
    ['Full screen, no browser bars. Just glow.', 'شاشة كاملة بلا أشرطة. إشراقة فقط.'],
  ],
  E: [
    ['The K-beauty edit, now an app.', 'مختارات الجمال الكوري، الآن تطبيق.'],
    ['Effortless. Elegant. On your Home Screen.', 'أناقة وسهولة، على شاشتكِ الرئيسية.'],
    ['Be the first to know when favourites return.', 'كوني أول من يعلم بعودة مفضّلاتكِ.'],
  ],
  F: [
    ['Glass skin? There’s an app for that.', 'بشرة زجاجية؟ لها تطبيق الآن.'],
    ['Give your phone a little glow-up.', 'امنحي هاتفكِ لمسة إشراق.'],
    ['Your skin’s new bestie lives on your Home Screen.', 'صديقة بشرتكِ الجديدة على شاشتكِ الرئيسية.'],
  ],
};

const T = {
  en: { install: 'Install', get: 'Get the app', plat: 'iPhone · iPad · Android', app: 'The K-Beauty Bliss app', free: 'Free · 1 tap', label: 'Get the K-Beauty Bliss app' },
  ar: { install: 'ثبّتي التطبيق', get: 'حمّلي التطبيق', plat: 'آيفون · آيباد · أندرويد', app: 'تطبيق K-Beauty Bliss', free: 'مجاني · لمسة واحدة', label: 'حمّلي تطبيق K-Beauty Bliss' },
};

const CSS = {
  base: `
.kfa{font-family:var(--sans,"Outfit",system-ui,sans-serif);color:var(--ink,#2A2228);line-height:1.25}
.kfa *{box-sizing:border-box}
.kfa-in{display:flex;align-items:center;gap:12px;min-height:0}
.kfa-ic{display:inline-flex;align-items:center;gap:6px;flex:none}
.kfa-ic i{display:inline-flex;width:18px;height:18px}
.kfa-ic svg{width:100%;height:100%;display:block}
.kfa-tx{flex:1 1 auto;min-width:0;margin:0;display:flex;flex-direction:column;gap:1px}
.kfa-tx b{font-weight:600;font-size:13.5px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.kfa-tx small{font-size:11px;color:var(--muted,#756C74);letter-spacing:.01em}
.kfa-bt{flex:none;display:inline-flex;align-items:center;gap:6px;border:0;cursor:pointer;font:600 13px/1 var(--sans,"Outfit",sans-serif);white-space:nowrap;-webkit-tap-highlight-color:transparent}
.kfa-bt svg{width:15px;height:15px;flex:none}
.kfa-bt:focus-visible{outline:2px solid #2A2228;outline-offset:2px}
@media (display-mode:standalone){.kfa{display:none}}
@media (min-width:768px){.kfa-tx{flex-direction:row;align-items:baseline;gap:10px}.kfa-tx b{font-size:15px;-webkit-line-clamp:1}.kfa-tx small{font-size:12px}.kfa-ic i{width:20px;height:20px}.kfa-bt{font-size:14px}}
`,
  A: `
.kfa-A{background:var(--kft-bg,#fff);border-top:1px solid #F1E3E8}
.kfa-A .kfa-in{padding-block:9px}
.kfa-A .kfa-pill{display:flex;align-items:center;gap:10px;width:100%;background:var(--pink-soft,#FFF0F4);border-radius:999px;padding:5px 5px 5px 14px}
[dir=rtl] .kfa-A .kfa-pill{padding:5px 14px 5px 5px}
.kfa-A .kfa-ic{color:var(--pink,#C6395F)}
.kfa-A .kfa-ic i{width:16px;height:16px}
.kfa-A .kfa-bt{background:var(--pink,#C6395F);color:#fff;border-radius:999px;padding:9px 14px;box-shadow:0 4px 12px -6px rgba(198,57,95,.7)}
@media (min-width:768px){.kfa-A .kfa-in{padding-block:10px}.kfa-A .kfa-pill{max-width:760px;margin-inline:auto;padding:6px 6px 6px 20px;gap:14px}[dir=rtl] .kfa-A .kfa-pill{padding:6px 20px 6px 6px}.kfa-A .kfa-bt{padding:11px 20px}}
`,
  B: `
.kfa-B{background:linear-gradient(100deg,#FCE0E8 0%,#FFF0F4 45%,#FFF1E4 100%);border-top:1px solid #F6D3DD}
.kfa-B .kfa-in{padding-block:6px}
.kfa-B .kfa-ic{gap:4px}
.kfa-B .kfa-ic i{width:24px;height:24px;border-radius:50%;background:#fff;color:var(--pink-deep,#C13E63);padding:5px;box-shadow:0 2px 6px -3px rgba(193,62,99,.45)}
.kfa-B .kfa-tx b{color:#5A2638}
.kfa-B .kfa-bt{background:linear-gradient(135deg,#E0567B,#C13E63);color:#fff;border-radius:999px;padding:10px 15px;box-shadow:0 6px 16px -8px rgba(193,62,99,.9),inset 0 1px 0 rgba(255,255,255,.35)}
@media (min-width:768px){.kfa-B .kfa-in{padding-block:12px;gap:16px}.kfa-B .kfa-ic i{width:30px;height:30px;padding:6px}.kfa-B .kfa-bt{padding:12px 22px}}
`,
  C: `
.kfa-C{background:var(--cream,#FFF8F5);border-top:1px solid #F1E3E8}
.kfa-C .kfa-in{padding-block:4px}
.kfa-C .kfa-card{display:flex;align-items:center;gap:11px;width:100%;background:#fff;border:1px solid #F1E3E8;border-radius:14px;padding:3px 6px 3px 7px;box-shadow:0 6px 18px -14px rgba(42,34,40,.5)}
.kfa-C .kfa-ph{flex:none;width:22px;height:38px;border-radius:6px;background:#2A2228;padding:2.5px;position:relative}
.kfa-C .kfa-ph span{display:flex;align-items:center;justify-content:center;width:100%;height:100%;border-radius:4px;background:linear-gradient(160deg,#F7A8BF,#C6395F);color:#fff;font:800 9px/1 var(--sans,sans-serif);letter-spacing:-.04em}
.kfa-C .kfa-tx b{font-size:12.5px;line-height:1.2}
.kfa-C .kfa-tx small{font-size:10.5px}
.kfa-C .kfa-tx small{display:flex;align-items:center;gap:5px}
.kfa-C .kfa-ic{color:var(--ink-2,#5E545A);gap:4px}
.kfa-C .kfa-ic i{width:12px;height:12px}
.kfa-C .kfa-bt{background:#fff;color:var(--pink,#C6395F);border:1.5px solid var(--pink,#C6395F);border-radius:12px;padding:8px 12px}
@media (min-width:768px){.kfa-C .kfa-in{padding-block:6px}.kfa-C .kfa-card{max-width:820px;margin-inline:auto;padding:5px 8px 5px 10px;gap:14px}.kfa-C .kfa-ph{width:24px;height:40px}.kfa-C .kfa-tx small{font-size:12px}.kfa-C .kfa-tx{flex-direction:column;align-items:flex-start;gap:2px}.kfa-C .kfa-tx b{font-size:14.5px}.kfa-C .kfa-ic i{width:13px;height:13px}.kfa-C .kfa-bt{padding:10px 18px;background:var(--pink,#C6395F);color:#fff}}
`,
  D: `
.kfa-D{position:relative;isolation:isolate;margin-top:-30px;padding-bottom:12px}
.kfa-D .kfa-glass{display:flex;align-items:center;gap:10px;width:100%;padding:6px 6px 6px 14px;border-radius:20px;background:rgba(255,255,255,.55);-webkit-backdrop-filter:blur(12px) saturate(1.4);backdrop-filter:blur(12px) saturate(1.4);border:1px solid rgba(255,255,255,.9);box-shadow:0 10px 30px -14px rgba(193,62,99,.55),inset 0 1px 0 #fff}
[dir=rtl] .kfa-D .kfa-glass{padding:6px 14px 6px 6px}
.kfa-D .kfa-ic{color:#B23A5E}
.kfa-D .kfa-ic i{width:16px;height:16px}
.kfa-D .kfa-tx b{color:#4A1F30}
.kfa-D .kfa-tx small{color:#7A4A5B}
.kfa-D .kfa-tx small{display:none}
.kfa-D .kfa-bt{background:rgba(42,34,40,.88);color:#fff;border-radius:14px;padding:10px 14px}
@media (min-width:768px){.kfa-D .kfa-tx small{display:inline}.kfa-D{margin-top:-46px;padding-bottom:14px}.kfa-D .kfa-glass{max-width:780px;margin-inline:auto;padding:8px 8px 8px 22px;gap:14px}[dir=rtl] .kfa-D .kfa-glass{padding:8px 22px 8px 8px}.kfa-D .kfa-bt{padding:12px 20px}}
.kfa-D.kfa-below{margin-top:0;padding-block:7px;background:radial-gradient(40% 140% at 15% 50%,#F7A8BF 0%,transparent 70%),radial-gradient(40% 140% at 85% 50%,#FFD3DF 0%,transparent 70%),#FFF0F4}
`,
  E: `
.kfa-E{background:#2A2228;color:#FFF8F5}
.kfa-E .kfa-in{padding-block:8px}
.kfa-E .kfa-ic{color:#F4C2D0}
.kfa-E .kfa-ic i{width:16px;height:16px}
.kfa-E .kfa-tx b{color:#FFF8F5;font-weight:500}
.kfa-E .kfa-tx small{color:#CDB9A0;text-transform:uppercase;letter-spacing:.14em;font-size:9.5px}
[dir=rtl] .kfa-E .kfa-tx small{letter-spacing:0}
.kfa-E .kfa-bt{background:linear-gradient(135deg,#F6E2C3,#E9C49B);color:#2A2228;border-radius:999px;padding:9px 15px}
.kfa-E .kfa-sep{width:1px;align-self:stretch;margin-block:4px;background:rgba(255,248,245,.18);flex:none}
@media (min-width:768px){.kfa-E .kfa-in{padding-block:13px;gap:18px}.kfa-E .kfa-tx b{font-size:15.5px}.kfa-E .kfa-tx small{font-size:10.5px}.kfa-E .kfa-bt{padding:11px 22px}}
`,
  F: `
.kfa-F{position:relative;overflow:hidden;background:linear-gradient(90deg,#FFF0F4,#FFF8F5 50%,#F5ECFF);border-top:1px dashed #F2C4D2}
.kfa-F .kfa-in{padding-block:9px;position:relative}
.kfa-F .kfa-ic{gap:4px}
.kfa-F .kfa-ic i{width:26px;height:26px;border-radius:9px;padding:5px;color:#fff;transform:rotate(-6deg)}
.kfa-F .kfa-ic i:nth-child(1){background:#F27BA0}
.kfa-F .kfa-ic i:nth-child(2){background:#9FD3B4;color:#245C40;transform:rotate(4deg)}
.kfa-F .kfa-ic i:nth-child(3){background:#C9B3F0;color:#45307A;transform:rotate(-3deg)}
.kfa-F .kfa-tx b{color:#B02D58;font-weight:700}
.kfa-F .kfa-bt{position:relative;overflow:hidden;background:#E0567B;color:#fff;border-radius:999px;padding:10px 15px;box-shadow:0 4px 0 #B83A60}
.kfa-F .kfa-bt::after{content:'';position:absolute;inset:0;background:linear-gradient(100deg,transparent 30%,rgba(255,255,255,.55) 50%,transparent 70%);transform:translateX(-120%);animation:kfa-shine 2.6s ease-in-out 1s 3}
.kfa-F .kfa-sp{position:absolute;color:#F2A6BE;width:10px;height:10px;animation:kfa-tw 1.8s ease-in-out 4 alternate}
.kfa-F .kfa-sp svg{width:100%;height:100%;display:block}
.kfa-F .kfa-sp.s1{top:4px;inset-inline-start:40%}
.kfa-F .kfa-sp.s2{bottom:5px;inset-inline-start:58%;width:7px;height:7px;color:#C9B3F0;animation-delay:.6s}
.kfa-F .kfa-sp.s3{top:7px;inset-inline-end:118px;width:8px;height:8px;color:#E9C49B;animation-delay:1.1s}
@keyframes kfa-shine{to{transform:translateX(120%)}}
@keyframes kfa-tw{from{opacity:.25;transform:scale(.7)}to{opacity:1;transform:scale(1.1)}}
@media (prefers-reduced-motion:reduce){.kfa-F .kfa-bt::after,.kfa-F .kfa-sp{animation:none}}
@media (min-width:768px){.kfa-F .kfa-in{padding-block:12px;gap:16px}.kfa-F .kfa-ic i{width:30px;height:30px;padding:6px}.kfa-F .kfa-bt{padding:12px 22px}.kfa-F .kfa-sp.s3{inset-inline-end:180px}}
`,
};

const NAMES = {
  A: 'Minimal pill', B: 'Blush band', C: 'Card with a tiny phone', D: 'Frosted glass over the wordmark', E: 'Dark & elegant', F: 'Playful sparkle',
};

/** The row's markup for one option, language and line (0-2). */
function html(letter, lang = 'en', line = 0, extra = '') {
  const t = T[lang];
  const ln = LINES[letter][line][lang === 'ar' ? 1 : 0];
  const bt = (label, icon = true) => `<button class="kfa-bt" type="button">${icon ? G.dl : ''}<span>${label}</span></button>`;
  const open = `<div class="kfa kfa-${letter}${extra}" role="region" aria-label="${t.label}"><div class="kft-wrap kfa-in">`;
  const close = '</div></div>';
  switch (letter) {
    case 'A':
      return `${open}<div class="kfa-pill">${glyphs()}<p class="kfa-tx"><b>${ln}</b></p>${bt(t.install)}</div>${close}`;
    case 'B':
      return `${open}${glyphs()}<p class="kfa-tx"><b>${ln}</b><small>${t.plat}</small></p>${bt(t.get)}${close}`;
    case 'C':
      return `${open}<div class="kfa-card"><span class="kfa-ph" aria-hidden="true"><span>K</span></span><p class="kfa-tx"><b>${ln}</b><small>${glyphs()}${t.free}</small></p>${bt(t.install)}</div>${close}`;
    case 'D':
      return `${open}<div class="kfa-glass">${glyphs()}<p class="kfa-tx"><b>${ln}</b><small>${t.plat}</small></p>${bt(t.install, false)}</div>${close}`;
    case 'E':
      return `${open}${glyphs()}<span class="kfa-sep"></span><p class="kfa-tx"><small>${t.app}</small><b>${ln}</b></p>${bt(t.get, false)}${close}`;
    case 'F':
      return `${open}<span class="kfa-sp s1" aria-hidden="true">${G.spark}</span><span class="kfa-sp s2" aria-hidden="true">${G.spark}</span><span class="kfa-sp s3" aria-hidden="true">${G.spark}</span>${glyphs()}<p class="kfa-tx"><b>${ln}</b></p>${bt(t.install)}${close}`;
  }
  throw new Error('no option ' + letter);
}

/* The iPhone/iPad instruction sheet (what the button opens on Safari) and the
   desktop QR panel, both preview-only illustrations. The QR is a DRAWN
   PLACEHOLDER (no QR encoder on this box and no new dependency); the real one
   would be printed server-side once and cached. */
const SHEET_CSS = `
.kfa-back{position:fixed;inset:0;z-index:2147483001;background:rgba(42,34,40,.42);display:flex;align-items:flex-end;justify-content:center;font-family:var(--sans,"Outfit",sans-serif)}
.kfa-sheet{width:100%;max-width:440px;background:#fff;border-radius:22px 22px 0 0;padding:18px 20px 22px;color:#2A2228;box-shadow:0 -10px 40px -10px rgba(42,34,40,.4)}
.kfa-sheet .hd{display:flex;align-items:center;gap:12px}
.kfa-sheet .ico{width:48px;height:48px;border-radius:12px;background:linear-gradient(160deg,#F7A8BF,#C6395F);color:#fff;display:flex;align-items:center;justify-content:center;font:800 22px/1 var(--sans,sans-serif);flex:none}
.kfa-sheet h3{margin:0;font-size:17px}
.kfa-sheet p.sub{margin:2px 0 0;font-size:13px;color:#756C74}
.kfa-sheet ol{list-style:none;margin:16px 0 0;padding:0;display:grid;gap:10px}
.kfa-sheet li{display:flex;align-items:center;gap:12px;background:#FFF8F5;border-radius:14px;padding:10px 12px;font-size:14px}
.kfa-sheet li .n{width:24px;height:24px;border-radius:50%;background:#C6395F;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex:none}
.kfa-sheet li .g{width:30px;height:30px;border-radius:8px;background:#fff;border:1px solid #F1E3E8;color:#0A84FF;display:flex;align-items:center;justify-content:center;flex:none}
.kfa-sheet li .g svg{width:18px;height:18px}
.kfa-sheet li small{display:block;color:#756C74;font-size:12px}
.kfa-sheet .ok{margin-top:16px;width:100%;border:0;border-radius:14px;background:#2A2228;color:#fff;font:600 15px/1 var(--sans,sans-serif);padding:14px}
.kfa-sheet .arrow{display:block;margin:12px auto 0;width:28px;height:28px;color:#C6395F;animation:kfa-bob 1.2s ease-in-out 3 alternate}
@keyframes kfa-bob{to{transform:translateY(6px)}}
.kfa-qr{position:fixed;z-index:2147483001;inset-inline-end:40px;bottom:80px;width:300px;background:#fff;border-radius:18px;padding:16px;box-shadow:0 24px 60px -22px rgba(42,34,40,.45);border:1px solid #F1E3E8;font-family:var(--sans,"Outfit",sans-serif);color:#2A2228;text-align:center}
.kfa-qr h3{margin:0 0 4px;font-size:16px}.kfa-qr p{margin:0 0 12px;font-size:12.5px;color:#756C74}
.kfa-qr .code{width:168px;height:168px;margin:0 auto;display:block}
.kfa-qr .tag{display:inline-block;margin-top:10px;font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:#C6395F;background:#FFF0F4;border-radius:99px;padding:4px 10px}
`;
const share = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M8 7l4-4 4 4"/><path d="M8.5 10.5H6.5a1.5 1.5 0 0 0-1.5 1.5v7.5A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V12a1.5 1.5 0 0 0-1.5-1.5h-2"/></svg>';
const plus = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><rect x="4" y="4" width="16" height="16" rx="4"/><path d="M12 8.5v7M8.5 12h7"/></svg>';
function sheet(lang = 'en') {
  const ar = lang === 'ar';
  const s = ar
    ? { h: 'أضيفي K-Beauty Bliss إلى شاشتكِ', sub: 'خطوتان فقط في Safari', s1: 'اضغطي زر <b>المشاركة</b>', s1s: 'في iOS 26: اضغطي ••• أولاً ثم مشاركة', s2: 'اختاري <b>إضافة إلى الشاشة الرئيسية</b>', s2s: 'ثم «إضافة» — يفتح كتطبيق بشاشة كاملة', ok: 'حسناً' }
    : { h: 'Add K-Beauty Bliss to your Home Screen', sub: 'Two quick steps in Safari', s1: 'Tap the <b>Share</b> button', s1s: 'On iOS 26: tap ••• first, then Share', s2: 'Choose <b>Add to Home Screen</b>', s2s: 'Then “Add”. It opens full screen, like an app', ok: 'Got it' };
  return `<div class="kfa-back"><div class="kfa-sheet" role="dialog" aria-modal="true"><div class="hd"><span class="ico">K</span><div><h3>${s.h}</h3><p class="sub">${s.sub}</p></div></div><ol><li><span class="n">1</span><span class="g">${share}</span><span>${s.s1}<small>${s.s1s}</small></span></li><li><span class="n">2</span><span class="g">${plus}</span><span>${s.s2}<small>${s.s2s}</small></span></li></ol><button class="ok" type="button">${s.ok}</button><span class="arrow" aria-hidden="true">${G.dl}</span></div></div>`;
}
function qrPlaceholder() {
  // A drawn QR-LOOKING pattern, deterministic, clearly labelled as a mock.
  let cells = '', seed = 7;
  const rnd = () => (seed = (seed * 9301 + 49297) % 233280) / 233280;
  const N = 25, finder = (x, y) => (x < 7 && y < 7) || (x > N - 8 && y < 7) || (x < 7 && y > N - 8);
  for (let y = 0; y < N; y++) for (let x = 0; x < N; x++) if (!finder(x, y) && rnd() > 0.52) cells += `<rect x="${x}" y="${y}" width="1" height="1"/>`;
  const f = (x, y) => `<rect x="${x}" y="${y}" width="7" height="7" rx="1.6"/><rect x="${x + 1}" y="${y + 1}" width="5" height="5" rx="1.1" fill="#fff"/><rect x="${x + 2}" y="${y + 2}" width="3" height="3" rx=".8"/>`;
  return `<svg class="code" viewBox="-1 -1 27 27" fill="#2A2228">${cells}${f(0, 0)}${f(N - 7, 0)}${f(0, N - 7)}<rect x="10" y="10" width="5" height="5" rx="1.4" fill="#C6395F"/></svg>`;
}
function qr() {
  return `<div class="kfa-qr" role="dialog"><h3>Get the app on your phone</h3><p>Scan with your iPhone or Android camera. It opens the shop, then tap Install.</p>${qrPlaceholder()}<span class="tag">QR shown as a mock</span></div>`;
}

module.exports = { G, LINES, T, CSS, NAMES, html, sheet, qr, SHEET_CSS, LETTERS: ['A', 'B', 'C', 'D', 'E', 'F'] };
