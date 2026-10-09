// Lane TS: the trust-strip design options, as data. ONE source for both the
// in-place screenshots (tools/ts-shoot.cjs injects html + css into the running
// preview) and the self-contained options page (tools/ts-build.cjs). Preview
// only: nothing here is wired into the shop until the owner picks a letter.
//
// Every variant is plain HTML + CSS. No JavaScript, no image, no font of its
// own (the shop already serves Outfit). Icons are 24-unit stroke icons drawn
// on whole/half-unit coordinates and stroked with vector-effect:
// non-scaling-stroke, so the line is 1.75 CSS px at every size the CSS draws
// them (22, 24 or 28 px) instead of scaling with the box.
'use strict';

const ICONS = {
  // badge-check: a scalloped seal with a tick
  auth: '<path d="M8.6 3.9a4 4 0 0 1 6.8 0 4 4 0 0 1 4.7 4.7 4 4 0 0 1 0 6.8 4 4 0 0 1-4.7 4.7 4 4 0 0 1-6.8 0 4 4 0 0 1-4.7-4.7 4 4 0 0 1 0-6.8 4 4 0 0 1 4.7-4.7Z"/><path d="m9 12 2 2 4-4"/>',
  // delivery van, with a speed line for "express"
  del: '<path d="M14 17V7a1 1 0 0 0-1-1H6"/><path d="M2 10h5M3 13.5h3"/><path d="M14 9h3.6a1 1 0 0 1 .8.4l2.4 3.2a1 1 0 0 1 .2.6V16a1 1 0 0 1-1 1h-1"/><path d="M9.5 17h5"/><circle cx="7.5" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
  // card
  pay: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/>',
  // heart-chat
  sup: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2.5 21.5Z"/><path d="M12 15.5 9 12.6a1.9 1.9 0 0 1 3-2.4 1.9 1.9 0 0 1 3 2.4Z"/>',
};

// Same four items and wording as the owner's screenshot. `sub` is used only by
// the above-footer family and is a PLACEHOLDER awaiting his approval.
const ITEMS = [
  { k: 'auth', a: '100%', b: 'Authentic', sub: 'Genuine products from Korea', big: '100%', rest: 'Authentic' },
  { k: 'del', a: 'Express UAE', b: 'Delivery', sub: '1–3 days, all over the UAE', big: 'Express', rest: 'UAE Delivery' },
  { k: 'pay', a: 'Tabby, Tamara', b: 'Card, COD', sub: 'Pay later, by card or on delivery', big: 'Tabby · Tamara', rest: 'Card, COD' },
  { k: 'sup', a: '24/7', b: 'Support', sub: 'Real people, day and night', big: '24/7', rest: 'Support' },
];

const svg = (k) => `<svg viewBox="0 0 24 24" aria-hidden="true">${ICONS[k]}</svg>`;
const SVG_CSS = (s) => `${s} svg{fill:none;stroke:currentColor;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round;vector-effect:non-scaling-stroke;display:block}${s} svg *{vector-effect:non-scaling-stroke}`;
const LBL = 'Why shop with us';

const variants = [
  {
    id: 'H1', family: 'home', cls: 'kth1',
    title: 'Refined card',
    line: 'White card, pink icons in soft round chips, hairline dividers. Phone: one row of four, icon above text, like your screenshot. Laptop: icon beside text.',
    html: () => `<section class="kth1" aria-label="${LBL}"><ul>${ITEMS.map((i) => `<li><i>${svg(i.k)}</i><b>${i.a}<br>${i.b}</b></li>`).join('')}</ul></section>`,
    css: `.kth1{padding-block:0;--g:12px;max-width:var(--site-max,1680px);margin:14px auto;padding-inline:var(--g);font-family:Outfit,system-ui,sans-serif}
@media(min-width:681px){.kth1{--g:calc(12px + clamp(18px,2vw,28px))}}
.kth1 ul{list-style:none;margin:0;padding:12px 2px;display:flex;background:#fff;border:1px solid rgba(198,57,95,.16);border-radius:16px;box-shadow:0 1px 2px rgba(42,34,40,.04),0 8px 20px -14px rgba(198,57,95,.45)}
.kth1 li{flex:1 1 auto;display:flex;flex-direction:column;align-items:center;gap:7px;text-align:center;position:relative}
.kth1 li+li:before{content:"";position:absolute;inset-inline-start:0;top:10%;bottom:10%;width:1px;background:rgba(198,57,95,.18)}
.kth1 i{width:38px;height:38px;border-radius:50%;background:#FFF0F4;color:#C6395F;display:grid;place-items:center}
.kth1 svg{width:22px;height:22px}
.kth1 b{font-size:11.5px;line-height:1.25;font-weight:600;color:#2A2228;white-space:nowrap}
@media(max-width:359px){.kth1 b{font-size:10.5px}.kth1 i{width:34px;height:34px}}
@media(min-width:768px){.kth1{margin-block:18px}.kth1 ul{padding:18px 4px;border-radius:18px}.kth1 li{flex:1 1 0;flex-direction:row;justify-content:center;gap:12px;text-align:start}.kth1 i{width:48px;height:48px}.kth1 svg{width:26px;height:26px}.kth1 b{font-size:14.5px;line-height:1.3}}
${SVG_CSS('.kth1')}`,
  },
  {
    id: 'H2', family: 'home', cls: 'kth2',
    title: 'Pills',
    line: 'Compact single-line pills. Phone: one row that scrolls sideways (CSS only, snaps to each pill). Laptop: all four centred on one row.',
    html: () => `<section class="kth2" aria-label="${LBL}"><ul>${ITEMS.map((i) => `<li><i>${svg(i.k)}</i><b>${i.a} ${i.b}</b></li>`).join('')}</ul></section>`,
    css: `.kth2{padding-block:0;--g:12px;max-width:var(--site-max,1680px);margin:12px auto;font-family:Outfit,system-ui,sans-serif}
.kth2 ul{list-style:none;margin:0;padding:2px var(--g);display:flex;gap:8px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-padding-inline:var(--g);scrollbar-width:none;overscroll-behavior-x:contain}
@media(min-width:681px){.kth2{--g:calc(12px + clamp(18px,2vw,28px))}}
.kth2 ul::-webkit-scrollbar{display:none}
.kth2 li{flex:none;scroll-snap-align:start;display:flex;align-items:center;gap:8px;padding:5px 14px 5px 5px;border-radius:999px;background:#FFF0F4;border:1px solid rgba(198,57,95,.2)}
.kth2 li:first-child{margin-inline-start:auto}.kth2 li:last-child{margin-inline-end:auto}
.kth2 i{width:30px;height:30px;border-radius:50%;background:#fff;color:#C6395F;display:grid;place-items:center;box-shadow:0 1px 2px rgba(198,57,95,.18)}
.kth2 svg{width:18px;height:18px}
.kth2 b{font-size:13px;line-height:1;font-weight:600;color:#2A2228;white-space:nowrap}
@media(min-width:768px){.kth2{margin-block:20px}.kth2 ul{gap:14px}.kth2 li{padding:6px 18px 6px 6px}.kth2 i{width:36px;height:36px}.kth2 svg{width:22px;height:22px}.kth2 b{font-size:14.5px}}
${SVG_CSS('.kth2')}`,
  },
  {
    id: 'H3', family: 'home', cls: 'kth3',
    title: 'Bold pink band',
    line: 'Edge-to-edge pink band, white icons and white text, fine white dividers. Phone: one row of four, icon above text. Laptop: icon beside text.',
    html: () => `<section class="kth3" aria-label="${LBL}"><ul>${ITEMS.map((i) => `<li>${svg(i.k)}<b>${i.a}<br>${i.b}</b></li>`).join('')}</ul></section>`,
    css: `.kth3{padding-block:0;--g:12px;margin:12px 0;background:linear-gradient(100deg,#C6395F,#B8325A);color:#fff;font-family:Outfit,system-ui,sans-serif}
.kth3 ul{list-style:none;margin:0 auto;max-width:var(--site-max,1680px);padding:12px calc(var(--g) - 6px);display:flex}
@media(min-width:681px){.kth3{--g:calc(12px + clamp(18px,2vw,28px))}}
.kth3 li{flex:1 1 auto;display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;position:relative}
.kth3 li+li:before{content:"";position:absolute;inset-inline-start:0;top:8%;bottom:8%;width:1px;background:rgba(255,255,255,.3)}
.kth3 svg{width:24px;height:24px}
.kth3 b{font-size:11.5px;line-height:1.25;font-weight:600;white-space:nowrap;letter-spacing:.01em}
@media(max-width:359px){.kth3 b{font-size:10.5px}}
@media(min-width:768px){.kth3{margin-block:20px}.kth3 ul{padding-block:16px}.kth3 li{flex:1 1 0;flex-direction:row;justify-content:center;gap:12px;text-align:start}.kth3 svg{width:28px;height:28px}.kth3 b{font-size:14.5px;line-height:1.3}}
${SVG_CSS('.kth3')}`,
  },
  {
    id: 'H4', family: 'home', cls: 'kth4',
    title: 'Minimal grid',
    line: 'No box: bare pink line icons, a bold first line over a softer second line, hairlines only. Phone: 2×2 grid. Laptop: four columns.',
    html: () => `<section class="kth4" aria-label="${LBL}"><ul>${ITEMS.map((i) => `<li>${svg(i.k)}<p><b>${i.a}</b>${i.b}</p></li>`).join('')}</ul></section>`,
    css: `.kth4{padding-block:0;--g:12px;max-width:var(--site-max,1680px);margin:12px auto;padding-inline:var(--g);font-family:Outfit,system-ui,sans-serif}
@media(min-width:681px){.kth4{--g:calc(12px + clamp(18px,2vw,28px))}}
.kth4 ul{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:1fr 1fr;border-block:1px solid rgba(42,34,40,.1)}
.kth4 li{display:flex;align-items:center;gap:10px;padding:13px 8px;min-width:0}
.kth4 li:nth-child(even){border-inline-start:1px solid rgba(42,34,40,.1);padding-inline-start:14px}
.kth4 li:nth-child(n+3){border-top:1px solid rgba(42,34,40,.1)}
.kth4 svg{width:28px;height:28px;flex:none;color:#C6395F}
.kth4 p{margin:0;font-size:12.5px;line-height:1.3;color:#5E545A;font-weight:500}
.kth4 b{display:block;font-size:13.5px;font-weight:700;color:#2A2228}
@media(max-width:359px){.kth4 li{gap:8px;padding-inline:4px}.kth4 li:nth-child(even){padding-inline-start:10px}.kth4 svg{width:24px;height:24px}.kth4 b{font-size:12.5px}.kth4 p{font-size:11.5px}}
@media(min-width:768px){.kth4{margin-block:20px}.kth4 ul{grid-template-columns:repeat(4,1fr)}.kth4 li{justify-content:center;gap:14px;padding:18px 12px}.kth4 li:nth-child(n){border-top:0;padding-inline-start:12px}.kth4 li+li{border-inline-start:1px solid rgba(42,34,40,.1)}.kth4 p{font-size:14px}.kth4 b{font-size:15.5px}}
${SVG_CSS('.kth4')}`,
  },
  {
    id: 'F1', family: 'foot', cls: 'ktf1',
    title: 'Big icons with a sub-line',
    line: 'Warm cream section, large pink icons in white rings, title plus a short sub-line (sub-lines are placeholders for your approval). Phone: 2×2. Laptop: four columns.',
    html: () => `<section class="ktf1" aria-label="${LBL}"><ul>${ITEMS.map((i) => `<li><i>${svg(i.k)}</i><b>${i.a} ${i.b}</b><span>${i.sub}</span></li>`).join('')}</ul></section>`,
    css: `.ktf1{padding-block:0;background:#FFF8F5;border-top:1px solid rgba(198,57,95,.1);font-family:Outfit,system-ui,sans-serif}
.ktf1 ul{list-style:none;margin:0 auto;max-width:var(--site-max,1680px);padding:26px var(--site-gutter,16px);display:grid;grid-template-columns:1fr 1fr;gap:22px 12px}
.ktf1 li{display:flex;flex-direction:column;align-items:center;text-align:center;gap:4px;min-width:0}
.ktf1 i{width:56px;height:56px;border-radius:50%;background:#fff;border:1px solid rgba(198,57,95,.22);color:#C6395F;display:grid;place-items:center;margin-bottom:6px;box-shadow:0 6px 16px -10px rgba(198,57,95,.5)}
.ktf1 svg{width:28px;height:28px}
.ktf1 b{font-size:14px;line-height:1.25;font-weight:600;color:#2A2228;text-wrap:balance}
.ktf1 span{font-size:12.5px;line-height:1.4;color:#756C74;text-wrap:balance}
@media(min-width:768px){.ktf1 ul{grid-template-columns:repeat(4,1fr);padding-block:40px;gap:24px}.ktf1 i{width:72px;height:72px;margin-bottom:10px}.ktf1 svg{width:32px;height:32px}.ktf1 b{font-size:16.5px}.ktf1 span{font-size:14px}}
${SVG_CSS('.ktf1')}`,
  },
  {
    id: 'F2', family: 'foot', cls: 'ktf2',
    title: 'Soft gradient, big type',
    line: 'Full-width blush gradient band; each item leads with its key word set large in pink, the rest in small spaced capitals, a small icon above. Phone: 2×2. Laptop: four columns.',
    html: () => `<section class="ktf2" aria-label="${LBL}"><ul>${ITEMS.map((i) => `<li>${svg(i.k)}<b>${i.big}</b><span>${i.rest}</span></li>`).join('')}</ul></section>`,
    css: `.ktf2{padding-block:0;background:linear-gradient(115deg,#FFF0F4 0%,#FCE0E8 45%,#FFF8F5 100%);font-family:Outfit,system-ui,sans-serif}
.ktf2 ul{list-style:none;margin:0 auto;max-width:var(--site-max,1680px);padding:28px var(--site-gutter,16px);display:grid;grid-template-columns:1fr 1fr;gap:24px 14px}
.ktf2 li{display:flex;flex-direction:column;align-items:flex-start;min-width:0}
.ktf2 svg{width:24px;height:24px;color:#C6395F;margin-bottom:10px}
.ktf2 b{font-size:clamp(20px,6.4vw,30px);line-height:1.05;font-weight:700;letter-spacing:-.02em;color:#A82F53}
.ktf2 span{margin-top:6px;font-size:11px;line-height:1.3;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:#5E545A}
@media(min-width:768px){.ktf2 ul{grid-template-columns:repeat(4,1fr);padding-block:44px;gap:28px}.ktf2 li{padding-inline-start:22px;border-inline-start:1px solid rgba(168,47,83,.18)}.ktf2 svg{width:28px;height:28px;margin-bottom:14px}.ktf2 b{font-size:clamp(28px,2.6vw,40px)}.ktf2 span{font-size:12.5px}}
${SVG_CSS('.ktf2')}`,
  },
  {
    id: 'F3', family: 'foot', cls: 'ktf3',
    title: 'Ink band, gold-pink accents',
    line: 'Deep ink band with a thin gold-to-pink rule on top, gold line icons, white titles and soft rose sub-lines (placeholders). Phone: 2×2 with hairline grid. Laptop: four columns.',
    html: () => `<section class="ktf3" aria-label="${LBL}"><ul>${ITEMS.map((i) => `<li>${svg(i.k)}<p><b>${i.a} ${i.b}</b>${i.sub}</p></li>`).join('')}</ul></section>`,
    css: `.ktf3{padding-block:0;background:#2A2228;color:#fff;font-family:Outfit,system-ui,sans-serif;border-top:2px solid;border-image:linear-gradient(90deg,#BE8E2E,#E2B86A 30%,#E07A98 70%,#C6395F) 1}
.ktf3 ul{list-style:none;margin:0 auto;max-width:var(--site-max,1680px);padding:8px var(--site-gutter,16px);display:grid;grid-template-columns:1fr 1fr}
.ktf3 li{display:flex;flex-direction:column;gap:10px;padding:18px 10px;min-width:0}
.ktf3 li:nth-child(even){border-inline-start:1px solid rgba(255,255,255,.1);padding-inline-start:16px}
.ktf3 li:nth-child(n+3){border-top:1px solid rgba(255,255,255,.1)}
.ktf3 svg{width:28px;height:28px;color:#E2B86A}
.ktf3 p{margin:0;font-size:12.5px;line-height:1.4;color:#D9C6CD}
.ktf3 b{display:block;font-size:14px;font-weight:600;color:#fff;margin-bottom:2px;text-wrap:balance}
@media(min-width:768px){.ktf3 ul{grid-template-columns:repeat(4,1fr);padding-block:30px}.ktf3 li{flex-direction:row;align-items:flex-start;gap:14px;padding:6px 24px}.ktf3 li:nth-child(n){border-top:0}.ktf3 li+li{border-inline-start:1px solid rgba(255,255,255,.12)}.ktf3 svg{width:32px;height:32px;flex:none}.ktf3 p{font-size:14px}.ktf3 b{font-size:16px}}
${SVG_CSS('.ktf3')}`,
  },
];

for (const v of variants) {
  v.markup = v.html();
}

module.exports = { variants, ITEMS, ICONS };
