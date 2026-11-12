// Black Friday templates for the Super Sale banner: previews (owner, 12 Nov 2026:
// "you didn't build the Black Friday template for Super sale page banner. please
// give me multiple designs to choose from."). Each is drawn ON the real banner
// (the 11.11 layout, which carries the big-number slot) by one stylesheet and its
// words -- exactly what a template is in App\Support\SaleTemplates.
const R = '.kbb-ssb.ssb-t-m1.bf';
const vars = (o) => Object.entries(o).map(([k, v]) => `--ssb-${k}-cm:${v};--ssb-${k}-cd:${v}`).join(';');
module.exports = {
  A: {
    name: 'A · Black & Gold Foil',
    line: 'Deep black with a warm gold glow, the title in gold foil, a thin gold frame line; the classic luxury Black Friday.',
    words: { tag: 'THE BIGGEST SALE OF THE YEAR', num: '', title: 'BLACK FRIDAY' },
    css: `${R}{${vars({ title: '#F1CF7A', tag: '#E7D3A1', pay: '#FFFFFF', clabel: '#FFFFFF', cvalue: '#1A1408' })}}
${R} .ssb-in{background:radial-gradient(55% 90% at 78% 35%,rgba(233,196,106,.28),transparent 62%),radial-gradient(40% 70% at 10% 100%,rgba(233,196,106,.16),transparent 60%),linear-gradient(120deg,#050505 0%,#14110b 55%,#241b0c 100%)}
${R} .ssb-hero::before{background:linear-gradient(90deg,transparent,rgba(241,207,122,.75),transparent) 0 calc(100% - 10px)/100% 1px no-repeat,radial-gradient(circle,#F1CF7A 0 1.2px,transparent 2px) 0 0/52px 52px;opacity:.55}
${R} .ssb-super{background:linear-gradient(180deg,#FFF1C2 0%,#F1CF7A 45%,#B8862E 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;text-shadow:none;filter:drop-shadow(0 calc(var(--u)*.3) 0 rgba(0,0,0,.6))}
${R} .ssb-tag{letter-spacing:.18em}
${R} .ssb-model::before{background:radial-gradient(60% 60% at 50% 45%,rgba(241,207,122,.55),rgba(184,134,46,.15) 60%,transparent 72%)}
${R} .ssb-coupon b{border-color:#F1CF7A;background:#F1CF7A}
${R} .ssb-gifts{background:#fff;border-top-color:#F1CF7A}`,
  },
  B: {
    name: 'B · Neon Night 27.11',
    line: 'Black night with the shop pink as neon: the date 27.11 glowing big beside the title, pink sparkle dots; the 11.11 look, in black.',
    words: { tag: '', num: '27.11', title: 'BLACK FRIDAY' },
    css: `${R}{${vars({ title: '#FFFFFF', pay: '#FFFFFF', clabel: '#FFFFFF', cvalue: '#E8306F', num: '#FF4F9A' })}}
${R} .ssb-in{background:radial-gradient(55% 90% at 80% 30%,rgba(255,61,139,.42),transparent 62%),radial-gradient(45% 80% at 5% 110%,rgba(255,61,139,.22),transparent 60%),linear-gradient(120deg,#000 0%,#0d0a10 55%,#1b0a16 100%)}
${R} .ssb-hero::before{background:radial-gradient(circle,#ff6fae 0 1.5px,transparent 2.5px) 0 0/46px 46px,radial-gradient(circle,#fff 0 1px,transparent 2px) 23px 23px/46px 46px;opacity:.4}
${R} .ssb-super{text-shadow:0 0 calc(var(--u)*1.4) rgba(255,255,255,.35)}
${R} .ssb-num{color:#FF4F9A;text-shadow:0 0 calc(var(--u)*.6) #ff4f9a,0 0 calc(var(--u)*2.4) rgba(255,79,154,.85),0 0 calc(var(--u)*5) rgba(255,79,154,.5)}
${R} .ssb-coupon b{border-color:#ff4f9a}
${R} .ssb-model::before{background:radial-gradient(60% 60% at 50% 45%,rgba(255,79,154,.55),rgba(255,79,154,.12) 60%,transparent 72%)}
${R} .ssb-gifts{background:#fff}`,
  },
  C: {
    name: 'C · Red Tag',
    line: 'Black with bold red diagonal stripes behind the picture and a red "SALE" block as the big word; loud, high-street Black Friday.',
    words: { tag: 'LIMITED TIME DEALS', num: 'SALE', title: 'BLACK FRIDAY' },
    css: `${R}{${vars({ title: '#FFFFFF', tag: '#FF5A5F', pay: '#FFFFFF', clabel: '#FFFFFF', cvalue: '#D7192F', num: '#FFFFFF' })}}
${R} .ssb-in{background:repeating-linear-gradient(-55deg,rgba(215,25,47,.0) 0 26px,rgba(215,25,47,.22) 26px 40px) 62% 0/60% 100% no-repeat,linear-gradient(120deg,#070707 0%,#121212 60%,#1d0b0d 100%)}
${R} .ssb-hero::before{background:none}
${R} .ssb-super{text-shadow:calc(var(--u)*.35) calc(var(--u)*.35) 0 #D7192F}
${R}[dir=rtl] .ssb-super{text-shadow:calc(var(--u)*-.35) calc(var(--u)*.35) 0 #D7192F}
${R} .ssb-num{width:max-content;max-width:100%;background:#D7192F;color:#fff;padding:0 .14em .04em;border-radius:.08em;transform:rotate(-4deg);text-shadow:none;box-shadow:0 calc(var(--u)*.6) calc(var(--u)*1.6) rgba(215,25,47,.45)}
${R} .ssb-tag{letter-spacing:.16em;font-weight:800}
${R} .ssb-coupon b{border-color:#D7192F}
${R} .ssb-model::before{background:radial-gradient(60% 60% at 50% 45%,rgba(215,25,47,.45),transparent 72%)}
${R} .ssb-gifts{background:#fff;border-top-color:#D7192F}
@media (max-width:900px){${R} .ssb-num{font-size:calc(var(--u)*7.4);transform:rotate(-3deg)}}`,
  },
  D: {
    name: 'D · Noir Blush',
    line: 'Black melting into deep plum with the shop blush pink and rose gold: Black Friday that still looks like K-Beauty Bliss.',
    words: { tag: 'GLOW FOR LESS', num: '', title: 'BLACK FRIDAY' },
    css: `${R}{${vars({ title: '#F7B6C8', tag: '#EBC7A8', pay: '#FFFFFF', clabel: '#FFFFFF', cvalue: '#C6395F' })}}
${R} .ssb-in{background:radial-gradient(60% 95% at 80% 30%,rgba(247,182,200,.32),transparent 62%),linear-gradient(120deg,#0b0709 0%,#24121c 55%,#3a1630 100%)}
${R} .ssb-hero::before{background:radial-gradient(circle,#F7B6C8 0 1.2px,transparent 2px) 0 0/40px 40px;opacity:.35}
${R} .ssb-super{text-shadow:0 0 calc(var(--u)*1.6) rgba(247,182,200,.45)}
${R} .ssb-tag{letter-spacing:.2em}
${R} .ssb-coupon b{border-color:#EBC7A8;background:#fff6f8}
${R} .ssb-model::before{left:5%;right:5%;height:84%;border:calc(var(--u)*.3) solid #EBC7A8;border-bottom:0;background:radial-gradient(60% 60% at 50% 42%,rgba(247,182,200,.45),transparent 75%)}
${R} .ssb-gifts{background:#fff}`,
  },
  E: {
    name: 'E · Monochrome Bold',
    line: 'Pure black and white: a giant outlined "BLACK FRIDAY" watermark across the back, white title, white coupon box; sharp and minimal.',
    words: { tag: 'THE SALE YOU WAITED FOR', num: '', title: 'BLACK FRIDAY' },
    css: `${R}{${vars({ title: '#FFFFFF', tag: '#BDBDBD', pay: '#FFFFFF', clabel: '#FFFFFF', cvalue: '#000000' })}}
${R} .ssb-in{background:linear-gradient(120deg,#000 0%,#0e0e0e 60%,#1a1a1a 100%)}
${R} .ssb-hero{overflow:hidden}
${R} .ssb-hero::before{content:"BLACK FRIDAY BLACK FRIDAY";background:none;opacity:1;color:transparent;-webkit-text-stroke:1px rgba(255,255,255,.12);font-weight:900;font-size:calc(var(--u)*17);line-height:1;white-space:nowrap;display:flex;align-items:center;letter-spacing:-.02em}
${R} .ssb-super{letter-spacing:.02em}
${R} .ssb-tag{letter-spacing:.22em}
${R} .ssb-coupon b{border-color:#fff;background:#fff}
${R} .ssb-model::before{background:radial-gradient(60% 60% at 50% 45%,rgba(255,255,255,.22),transparent 72%)}
${R} .ssb-gifts{background:#fff;border-top-color:#000}`,
  },
};
