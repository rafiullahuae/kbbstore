// The five "bonus gift inside the Free gifts box" designs (Lane GB, owner 10 Nov 2026):
// "the additional free gift product should inside the gifts box with upper side live
// timer ... i don't want additional separate box for it as we have currently."
//
// Each design is CSS plus a build(d, state) that returns the pieces injected into the
// REAL Free gifts box (partials/free-gifts/box.blade.php) -- the box itself, its
// legend, wording line, photo row and urgency line stay exactly as the shop draws
// them. state: 'live' (14:52), 'low' (01:38, red under 2 minutes), 'ended'.
// Motion is transform/opacity only: the clock's text, a scaleX line, a ring made of
// two rotating halves, a stopwatch hand, digits sliding in. Nothing is measured.

const GIFT = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></svg>';
const WATCH = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M10 2h4M12 2v3"/><path class="gbm-hd" d="M12 13V9"/></svg>';
const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const clock = (s) => { const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60; return (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(x).padStart(2, '0'); };
const p = (d) => Math.max(0, Math.min(1, d.left / Math.max(1, d.total)));
const ring = (d) => { const t = p(d) * 360; return [Math.min(t, 180) - 135, Math.max(t - 180, 0) - 135]; };
const ENDED = 'Your bonus gift has expired';
const ENDED_IC = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';

// Shared: the bonus tile in the photo row, and the bonus line in the opened list.
const tile = (d, cls, extra = '') => `<span class="fgb-t gbm-t ${cls}">${extra}<img src="${esc(d.img)}" alt="${esc(d.name)}" width="42" height="42" decoding="async">${d.qty > 1 ? `<b>×${d.qty}</b>` : ''}</span>`;
const listRow = (d) => `<div class="fgb-lr gbm-lr"><span class="fgb-t"><img src="${esc(d.img)}" alt="" width="28" height="28"></span><span class="fgb-ln"><small>Bonus · full size</small><span class="fgb-nm">${d.qty > 1 ? `<em>${d.qty}× </em>` : ''}${esc(d.name)}</span></span><span class="fgb-lv">FREE</span></div>`;
const endedLine = (cls) => `<div class="gbm-x ${cls}" role="status">${ENDED_IC}<span>${ENDED}</span></div>`;

const BASE_CSS = `.gbm .gbm-hd{transform-origin:12px 13px;animation:gbmhd 4s steps(8) infinite}@keyframes gbmhd{to{transform:rotate(360deg)}}
.gbm-x{display:flex;align-items:center;gap:6px;margin:5px 0 1px;padding:6px 9px;border-radius:8px;background:#f5f2f3;color:#756c74;font-size:11.5px;font-weight:700;line-height:1.3}.gbm-x svg{flex:none}
.gbm-clk{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-variant-numeric:tabular-nums;font-weight:800;letter-spacing:.03em;direction:ltr;unicode-bidi:isolate}
.gbm-lr .fgb-ln small{color:#a12d4f}
@media (prefers-reduced-motion:reduce){.gbm .gbm-hd,.gbm *{animation:none!important;transition:none!important}}`;

const D = {
  A: {
    name: 'A · Timer bar on top',
    line: 'A slim pink bar under the title, "Your bonus gift is held for 14:52", with a line along its foot that shrinks as time runs; the bonus is the first photo in the row, ringed in pink, with a BONUS tag and ×2, and one line under the row names it.',
    css: `.gbA-bar{position:relative;display:flex;align-items:center;gap:6px;margin:5px 0 1px;padding:5px 9px 7px;border-radius:8px;background:#fff1f4;color:#a12d4f;font-size:11.5px;font-weight:700;line-height:1.3;overflow:hidden}.gbA-bar svg{flex:none}.gbA-bar>span{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gbA-bar .gbm-clk{flex:none;font-size:13px;color:#2a2228}.gbA-ln{position:absolute;left:0;right:0;bottom:0;height:3px;background:#c6395f;transform-origin:0 50%;transition:transform 1s linear}[dir=rtl] .gbA-ln{transform-origin:100% 50%}
.gbA .fgb-th{padding-bottom:5px}.gbA-t{box-shadow:0 0 0 2px #fff,0 0 0 3.5px #c6395f}.gbA-t b{background:#c6395f!important}.gbA-tag{position:absolute;left:50%;bottom:-8px;transform:translateX(-50%);z-index:1;padding:1px 5px;border-radius:4px;background:#c6395f;color:#fff;font-size:8px;font-weight:800;letter-spacing:.06em;line-height:1.35}
.gbA-cap{margin-top:7px;font-size:11.5px;line-height:1.3;color:#2a2228;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gbA-cap b{color:#a12d4f}
.gbA.low .gbA-bar{background:#fde8ea;color:#d1213f}.gbA.low .gbA-bar .gbm-clk{color:#d1213f}.gbA.low .gbA-ln{background:#d1213f}`,
    build: (d, s) => s === 'ended' ? { top: endedLine('gbA-x') } : {
      top: `<div class="gbA-bar" role="timer">${WATCH}<span>Your bonus gift is held for</span><span class="gbm-clk" data-gbm-clk>${clock(d.left)}</span><i class="gbA-ln" data-gbm-line style="transform:scaleX(${p(d).toFixed(3)})"></i></div>`,
      tile: tile(d, 'gbA-t', '<i class="gbA-tag">BONUS</i>'),
      after: `<div class="gbA-cap"><b>Bonus:</b> ${d.qty > 1 ? d.qty + '× ' : ''}${esc(d.name)} · full size</div>`,
    },
  },
  B: {
    name: 'B · Timer pill on the border',
    line: 'A dark stopwatch pill sits ON the green border, opposite "Free gifts for you", like a second label; inside, the bonus is a wide cream first card (photo, name, Free · Full size · ×2), with the regular gift photos below it.',
    css: `fieldset.gbB{position:relative}.gbB-pill{position:absolute;top:-12px;inset-inline-end:9px;display:inline-flex;align-items:center;gap:4px;padding:3px 8px 3px 6px;border-radius:999px;background:#2a2228;color:#fff;font-size:12px;line-height:1.2;box-shadow:0 0 0 3px var(--fg-surface,#fff)}.gbB-pill .gbm-clk{font-size:12.5px}
.gbB-card{display:grid;grid-template-columns:42px minmax(0,1fr);gap:9px;align-items:center;margin-top:8px;padding:7px 9px;border-radius:10px;background:#fffaf2;border:1px solid #f1e4cc}.gbB-card .fgb-t{width:42px!important;height:42px!important;border-color:#f1e4cc}.gbB-card .fgb-t b{background:#8a6a2c}.gbB-nm{min-width:0;display:grid;gap:3px}.gbB-nm b{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;font-size:12.5px;font-weight:800;line-height:1.25;max-height:2.5em}.gbB-ch{display:flex;gap:4px;flex-wrap:wrap}.gbB-ch i{font-style:normal;font-size:9.5px;font-weight:800;border-radius:6px;padding:1px 6px;background:#fbf1de;color:#8a6a2c;white-space:nowrap}.gbB-ch i.g{background:#8a6a2c;color:#fffaf2}
.gbB.low .gbB-pill{background:#d1213f}.gbB.low .gbB-card{border-color:#f3c3cb}
.gbB-pill.gbB-off{background:#9a9298}`,
    build: (d, s) => s === 'ended' ? { legendSide: `<span class="gbB-pill gbB-off">${ENDED_IC}<span>Ended</span></span>`, top: endedLine('gbB-x') } : {
      legendSide: `<span class="gbB-pill" role="timer">${WATCH}<span class="gbm-clk" data-gbm-clk>${clock(d.left)}</span></span>`,
      mid: `<div class="gbB-card">${tile(d, 'gbB-t')}<span class="gbB-nm"><b>${esc(d.name)}</b><span class="gbB-ch"><i class="g">Bonus · Free</i><i>Full size</i><i>×${d.qty}</i></span></span></div>`,
    },
  },
  C: {
    name: 'C · Countdown strip + ringed photo',
    line: 'A charcoal countdown strip inside the box above the photos ("Bonus gift held · 14:52"); the bonus photo leads the row inside a pink ring that shrinks as time runs out, with ×2, and its name on one line under the row.',
    css: `.gbC-s{display:flex;align-items:center;gap:7px;margin:5px 0 1px;padding:5px 9px;border-radius:8px;background:#2a2228;color:#fff;font-size:10.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;line-height:1.3}.gbC-s>span{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gbC-s .gbm-clk{flex:none;font-size:14px;letter-spacing:.04em}
.gbC .fgb-th{align-items:center}.gbC-t{margin:5px 7px 5px 5px}.gbC-r{position:absolute;inset:-5px;border-radius:50%;pointer-events:none}.gbC-r::before{content:"";position:absolute;inset:0;border-radius:50%;border:2.5px solid #f6dbe2}.gbC-h{position:absolute;top:0;width:50%;height:100%;overflow:hidden}.gbC-hr{right:0}.gbC-hl{left:0}.gbC-h i{position:absolute;top:0;width:200%;height:100%;box-sizing:border-box;border-radius:50%;border:2.5px solid transparent;transition:transform 1s linear}.gbC-hr i{right:0;border-top-color:#c6395f;border-right-color:#c6395f}.gbC-hl i{left:0;border-bottom-color:#c6395f;border-left-color:#c6395f}.gbC-t.fgb-t,.gbC-t.fgb-t img{border-radius:50%!important}.gbC-t b{background:#c6395f!important;z-index:1}
.gbC-cap{margin-top:5px;font-size:11.5px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#2a2228}.gbC-cap b{color:#c6395f;font-weight:800}
.gbC.low .gbC-s{background:#d1213f}.gbC.low .gbC-hr i{border-top-color:#d1213f;border-right-color:#d1213f}.gbC.low .gbC-hl i{border-bottom-color:#d1213f;border-left-color:#d1213f}`,
    build: (d, s) => { if (s === 'ended') return { top: endedLine('gbC-x') }; const [r, l] = ring(d); return {
      top: `<div class="gbC-s" role="timer">${WATCH}<span>Bonus gift held for you</span><span class="gbm-clk" data-gbm-clk>${clock(d.left)}</span></div>`,
      tile: tile(d, 'gbC-t', `<span class="gbC-r" aria-hidden="true"><span class="gbC-h gbC-hr"><i data-gbm-ring="r" style="transform:rotate(${r.toFixed(1)}deg)"></i></span><span class="gbC-h gbC-hl"><i data-gbm-ring="l" style="transform:rotate(${l.toFixed(1)}deg)"></i></span></span>`),
      after: `<div class="gbC-cap"><b>+ Bonus</b> ${d.qty > 1 ? d.qty + '× ' : ''}${esc(d.name)}</div>`,
    }; },
  },
  D: {
    name: 'D · Two-part box',
    line: 'One outline, two parts: a cream band right under the title ("BONUS GIFT" and a dark clock chip, then the photo, name and Free · Full size · ×2 chips), a dashed green divider, then the regular gifts exactly as today.',
    css: `.gbD-b{margin:4px -11px 0;padding:7px 11px 9px;background:#fffaf2;border-top:1px solid #f1e4cc;border-bottom:1px dashed #2e8f62}.gbD-h{display:flex;align-items:center;gap:6px;color:#8a6a2c;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}.gbD-h>span:not(.gbD-k){flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gbD-k{display:inline-flex;align-items:center;gap:4px;padding:3px 7px 3px 5px;border-radius:8px;background:#2a2228;color:#fff;font-size:10px;letter-spacing:.02em;text-transform:none}.gbD-k .gbm-clk{font-size:13px}
.gbD-g{display:grid;grid-template-columns:42px minmax(0,1fr);gap:9px;align-items:center;margin-top:6px}.gbD-g .fgb-t{width:42px!important;height:42px!important;border-color:#f1e4cc}.gbD-g .fgb-t b{background:#8a6a2c}.gbD-g b.n{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;font-size:12.5px;font-weight:800;line-height:1.25;max-height:2.5em}.gbD-ch{display:flex;gap:4px;margin-top:3px}.gbD-ch i{font-style:normal;font-size:9.5px;font-weight:800;border-radius:6px;padding:1px 6px;background:#fbf1de;color:#8a6a2c;white-space:nowrap}.gbD-ch i.g{background:#8a6a2c;color:#fffaf2}
.gbD .fgb-w{margin-top:7px}.gbD.low .gbD-k{background:#d1213f}.gbD.low .gbD-b{background:#fff4f5}
.gbD-x.gbm-x{margin:4px -11px 0;border-radius:0;border-bottom:1px dashed #2e8f62;background:#faf8f8}`,
    build: (d, s) => s === 'ended' ? { top: endedLine('gbD-x') } : {
      top: `<div class="gbD-b"><div class="gbD-h">${GIFT}<span>Bonus gift</span><span class="gbD-k" role="timer">${WATCH}<span class="gbm-clk" data-gbm-clk>${clock(d.left)}</span></span></div><div class="gbD-g">${tile(d, 'gbD-t')}<span style="min-width:0"><b class="n">${esc(d.name)}</b><span class="gbD-ch"><i class="g">Free</i><i>Full size</i><i>×${d.qty}</i></span></span></div></div>`,
    },
  },
  E: {
    name: 'E · Flip-clock header',
    line: 'Centred under the title: "BONUS GIFT RESERVED FOR" over a small flip-clock (each digit its own dark tile, sliding in as it changes); the bonus leads the photo row in a gold frame with a gold ×2, its name on one line below.',
    css: `.gbE-h{margin:6px 0 1px;text-align:center}.gbE-l{display:block;font-size:9.5px;font-weight:800;letter-spacing:.09em;color:#8a6a2c;text-transform:uppercase}.gbE-d{display:inline-flex;align-items:center;gap:2px;margin-top:3px;direction:ltr}.gbE-d i{display:inline-grid;place-items:center;width:17px;height:23px;border-radius:5px;background:#2a2228;color:#fff;font:800 14px/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-style:normal;box-shadow:inset 0 -11.5px 0 rgba(255,255,255,.07)}.gbE-d i.in{animation:gbEin .35s ease-out}.gbE-d i.in2{animation:gbEin2 .35s ease-out}@keyframes gbEin{from{transform:translateY(-35%);opacity:0}}@keyframes gbEin2{from{transform:translateY(-35%);opacity:0}}.gbE-d u{text-decoration:none;font-weight:800;color:#2a2228;padding:0 1px}
.gbE-t{border:2px solid #c9a36a!important}.gbE-t b{background:#b8862a!important}.gbE-cap{margin-top:6px;font-size:11.5px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gbE-cap b{color:#8a6a2c}
.gbE.low .gbE-d i{background:#d1213f}.gbE.low .gbE-l{color:#d1213f}`,
    build: (d, s) => s === 'ended' ? { top: endedLine('gbE-x') } : {
      top: `<div class="gbE-h" role="timer"><span class="gbE-l">Bonus gift reserved for</span><span class="gbE-d" data-gbm-flip>${[...clock(d.left)].map((c) => c === ':' ? '<u>:</u>' : `<i>${c}</i>`).join('')}</span></div>`,
      tile: tile(d, 'gbE-t'),
      after: `<div class="gbE-cap"><b>Bonus:</b> ${d.qty > 1 ? d.qty + '× ' : ''}${esc(d.name)} · full size</div>`,
    },
  },
};

// The one clock for the previews (the shop's own would replace the row's): one
// interval, stops at zero; sets text and transforms only.
const TICK = `(function(w,d){if(w.gbmT)return;w.gbmT=1;function f(s){var h=Math.floor(s/3600),m=Math.floor(s%3600/60),x=s%60;return(h?h+':':'')+(m<10?'0':'')+m+':'+(x<10?'0':'')+x}var iv=setInterval(function(){var bs=d.querySelectorAll('[data-gbm-left]'),live=0;for(var i=0;i<bs.length;i++){var b=bs[i];if(!b.gbmEnd)b.gbmEnd=Date.now()+(+b.getAttribute('data-gbm-left'))*1000;var s=Math.max(0,Math.round((b.gbmEnd-Date.now())/1000)),T=+b.getAttribute('data-gbm-total')||1,P=s/T,t=f(s);var c=b.querySelector('[data-gbm-clk]');if(c)c.textContent=t;var l=b.querySelector('[data-gbm-line]');if(l)l.style.transform='scaleX('+P.toFixed(3)+')';var a=P*360,r=b.querySelector('[data-gbm-ring=r]'),q=b.querySelector('[data-gbm-ring=l]');if(r)r.style.transform='rotate('+(Math.min(a,180)-135)+'deg)';if(q)q.style.transform='rotate('+(Math.max(a-180,0)-135)+'deg)';var fl=b.querySelector('[data-gbm-flip]');if(fl){var ds=fl.querySelectorAll('i'),ch=t.replace(/:/g,'');if(ds.length===ch.length)for(var k=0;k<ds.length;k++)if(ds[k].textContent!==ch[k]){ds[k].textContent=ch[k];var o=ds[k].className==='in'?'in2':'in';ds[k].className=o}}b.classList.toggle('low',s<=120);if(s<=0){b.removeAttribute('data-gbm-left');b.classList.add('gbm-ended')}else live++}if(!live)clearInterval(iv)},1000)})(window,document)`;

module.exports = { D, BASE_CSS, TICK, clock, ENDED };
