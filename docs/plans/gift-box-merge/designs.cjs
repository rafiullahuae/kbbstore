// The five "bonus gift inside the Free gifts box" designs (Lane GB, owner 10 Nov 2026;
// 11 Nov: "i wanted the additional gift product at the last, not as 1st"):
// "the additional free gift product should inside the gifts box with upper side live
// timer ... i don't want additional separate box for it as we have currently."
//
// 15 Nov: "in place of word bonus, i need the timer, and remove teh top bar timer row
// also" -- so there is NOTHING above the gifts row any more (the box's own title stays),
// and the live countdown sits where the BONUS tag/word was on the bonus gift. Expired
// (owner, 15 Nov: "if time end, it will removed completely from the box silently without
// anything showing behind like Ended etc."): the bonus gift, its timer and its name line
// leave the box with no trace -- no line, no pill, no gap. In the preview's ticking clock
// they fade (opacity/transform) and are then removed, so the space closes.
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

// Shared: the bonus tile in the photo row, and the bonus line in the opened list.
const tile = (d, cls, extra = '') => `<span class="fgb-t gbm-t ${cls}" data-gbm-bonus>${extra}<img src="${esc(d.img)}" alt="${esc(d.name)}" width="42" height="42" decoding="async">${d.qty > 1 ? `<b>×${d.qty}</b>` : ''}</span>`;
const listRow = (d) => `<div class="fgb-lr gbm-lr"><span class="fgb-t"><img src="${esc(d.img)}" alt="" width="28" height="28"></span><span class="fgb-ln"><small>Bonus · full size</small><span class="fgb-nm">${d.qty > 1 ? `<em>${d.qty}× </em>` : ''}${esc(d.name)}</span></span><span class="fgb-lv">FREE</span></div>`;

const BASE_CSS = `.gbm [data-gbm-bonus]{transition:opacity .4s ease,transform .4s ease}.gbm .gbm-out{opacity:0;transform:scale(.88)}.gbm .gbm-hd{transform-origin:12px 13px;animation:gbmhd 4s steps(8) infinite}@keyframes gbmhd{to{transform:rotate(360deg)}}
.gbm-clk{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-variant-numeric:tabular-nums;font-weight:800;letter-spacing:.03em;direction:ltr;unicode-bidi:isolate}
.gbm-lr .fgb-ln small{color:#a12d4f}
@media (prefers-reduced-motion:reduce){.gbm .gbm-hd,.gbm *{animation:none!important;transition:none!important}}`;

const D = {
  A: {
    name: 'A · Pink timer tag',
    line: 'No bar on top any more. The bonus is the LAST photo in the row, ringed in pink, and its pink tag, where BONUS was, now reads the live clock (14:52) with a hairline under the digits that shrinks as time runs out. ×2 stays on the corner; one line under the row names it. Under 2 minutes the ring and tag turn red.',
    css: `.gbA-t{margin-bottom:12px;box-shadow:0 0 0 2px #fff,0 0 0 3.5px #c6395f}.gbA-t b{background:#c6395f!important}
.gbA-tag{position:absolute;left:50%;bottom:-14px;transform:translateX(-50%);z-index:1;overflow:hidden;padding:1px 5px 2px;border-radius:5px;background:#c6395f;color:#fff;font-size:10.5px;font-style:normal;line-height:1.25;box-shadow:0 0 0 1.5px #fff;white-space:nowrap}.gbA-tag .gbm-clk{font-weight:800;letter-spacing:.02em}.gbA-ln{position:absolute;left:0;right:0;bottom:0;height:2px;background:rgba(255,255,255,.75);transform-origin:0 50%;transition:transform 1s linear}[dir=rtl] .gbA-ln{transform-origin:100% 50%}
.gbA-cap{margin-top:7px;font-size:11.5px;line-height:1.3;color:#2a2228;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gbA-cap b{color:#a12d4f}
.gbA.low .gbA-t{box-shadow:0 0 0 2px #fff,0 0 0 3.5px #d1213f}.gbA.low .gbA-tag,.gbA.low .gbA-t b{background:#d1213f!important}`,
    build: (d, s) => s === 'ended' ? {} : {
      tile: tile(d, 'gbA-t', `<i class="gbA-tag" role="timer" aria-label="Bonus gift held for"><span class="gbm-clk" data-gbm-clk>${clock(d.left)}</span><i class="gbA-ln" data-gbm-line style="transform:scaleX(${p(d).toFixed(3)})"></i></i>`),
      after: `<div class="gbA-cap" data-gbm-bonus><b>Bonus:</b> ${d.qty > 1 ? d.qty + '× ' : ''}${esc(d.name)} · full size</div>`,
    },
  },
  B: {
    name: 'B · Cream card, clock chip',
    line: 'No pill on the border any more. The regular gift photos come first; the bonus is the wide cream card at the foot of the box (photo, name), and its first chip, where "Bonus · Free" was, is now a dark stopwatch chip with the live clock, then Free · Full size · ×2.',
    css: `.gbB-card{display:grid;grid-template-columns:42px minmax(0,1fr);gap:9px;align-items:center;margin-top:8px;padding:7px 9px;border-radius:10px;background:#fffaf2;border:1px solid #f1e4cc}.gbB-card .fgb-t{width:42px!important;height:42px!important;border-color:#f1e4cc}.gbB-card .fgb-t b{background:#8a6a2c}.gbB-nm{min-width:0;display:grid;gap:4px}.gbB-nm b{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;font-size:12.5px;font-weight:800;line-height:1.25;max-height:2.5em}.gbB-ch{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.gbB-ch i{font-style:normal;font-size:10px;font-weight:800;border-radius:6px;padding:1px 6px;background:#fbf1de;color:#8a6a2c;white-space:nowrap;line-height:1.5}.gbB-ch i.k{display:inline-flex;align-items:center;gap:3px;padding:1px 7px 1px 5px;background:#2a2228;color:#fff;font-size:11px}.gbB-ch i.k svg{width:12px;height:12px}
.gbB.low .gbB-ch i.k{background:#d1213f}.gbB.low .gbB-card{border-color:#f3c3cb;background:#fff6f7}`,
    build: (d, s) => s === 'ended' ? {} : {
      mid: `<div class="gbB-card" data-gbm-bonus>${tile(d, 'gbB-t')}<span class="gbB-nm"><b>${esc(d.name)}</b><span class="gbB-ch"><i class="k" role="timer" aria-label="Bonus gift held for">${WATCH}<span class="gbm-clk" data-gbm-clk>${clock(d.left)}</span></i><i>Free</i><i>Full size</i><i>×${d.qty}</i></span></span></div>`,
    },
  },
  C: {
    name: 'C · Ringed photo, clock on the ring',
    line: 'No strip on top any more. The bonus photo ends the row inside a pink ring that shrinks as time runs out, and the live clock sits ON the foot of the ring as a small pill. ×2 on the corner; its name on one line under the row. Under 2 minutes the ring and the pill turn red.',
    css: `.gbC .fgb-th{align-items:center}.gbC-t{margin:5px 7px 14px 5px}.gbC-r{position:absolute;inset:-5px;border-radius:50%;pointer-events:none}.gbC-r::before{content:"";position:absolute;inset:0;border-radius:50%;border:2.5px solid #f6dbe2}.gbC-h{position:absolute;top:0;width:50%;height:100%;overflow:hidden}.gbC-hr{right:0}.gbC-hl{left:0}.gbC-h i{position:absolute;top:0;width:200%;height:100%;box-sizing:border-box;border-radius:50%;border:2.5px solid transparent;transition:transform 1s linear}.gbC-hr i{right:0;border-top-color:#c6395f;border-right-color:#c6395f}.gbC-hl i{left:0;border-bottom-color:#c6395f;border-left-color:#c6395f}.gbC-t.fgb-t,.gbC-t.fgb-t img{border-radius:50%!important}.gbC-t b{background:#c6395f!important;z-index:1}
.gbC-k{position:absolute;left:50%;bottom:-14px;transform:translateX(-50%);z-index:2;padding:0 5px;border-radius:999px;background:#fff;color:#c6395f;border:1.5px solid #c6395f;font-size:10.5px;font-style:normal;line-height:1.35;white-space:nowrap}.gbC-k .gbm-clk{font-weight:800}
.gbC-cap{margin-top:5px;font-size:11.5px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#2a2228}.gbC-cap b{color:#c6395f;font-weight:800}
.gbC.low .gbC-k{background:#d1213f;border-color:#d1213f;color:#fff}.gbC.low .gbC-hr i{border-top-color:#d1213f;border-right-color:#d1213f}.gbC.low .gbC-hl i{border-bottom-color:#d1213f;border-left-color:#d1213f}`,
    build: (d, s) => { if (s === 'ended') return {}; const [r, l] = ring(d); return {
      tile: tile(d, 'gbC-t', `<span class="gbC-r" aria-hidden="true"><span class="gbC-h gbC-hr"><i data-gbm-ring="r" style="transform:rotate(${r.toFixed(1)}deg)"></i></span><span class="gbC-h gbC-hl"><i data-gbm-ring="l" style="transform:rotate(${l.toFixed(1)}deg)"></i></span></span><i class="gbC-k" role="timer" aria-label="Bonus gift held for"><span class="gbm-clk" data-gbm-clk>${clock(d.left)}</span></i>`),
      after: `<div class="gbC-cap" data-gbm-bonus><b>+ Bonus</b> ${d.qty > 1 ? d.qty + '× ' : ''}${esc(d.name)}</div>`,
    }; },
  },
  D: {
    name: 'D · Two-part box, clock chip at the foot',
    line: 'No cream line under the title any more. The regular gifts stay exactly as today, then a dashed green divider and the bonus at the foot (photo, name), whose first chip is now a dark clock chip with the live time (it was "BONUS GIFT" + clock at the top), then Free · Full size · ×2.',
    css: `.gbD-f{margin:8px -11px -2px;padding:8px 11px 4px;background:#fffaf2;border-top:1px dashed #2e8f62}
.gbD-g{display:grid;grid-template-columns:42px minmax(0,1fr);gap:9px;align-items:center}.gbD-g .fgb-t{width:42px!important;height:42px!important;border-color:#f1e4cc}.gbD-g .fgb-t b{background:#8a6a2c}.gbD-g b.n{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;font-size:12.5px;font-weight:800;line-height:1.25;max-height:2.5em}.gbD-ch{display:flex;gap:4px;margin-top:4px;flex-wrap:wrap;align-items:center}.gbD-ch i{font-style:normal;font-size:10px;font-weight:800;border-radius:6px;padding:1px 6px;background:#fbf1de;color:#8a6a2c;white-space:nowrap;line-height:1.5}.gbD-ch i.k{display:inline-flex;align-items:center;gap:3px;padding:1px 7px 1px 5px;border-radius:8px;background:#2a2228;color:#fff;font-size:11px}.gbD-ch i.k svg{width:12px;height:12px}
.gbD.low .gbD-ch i.k{background:#d1213f}.gbD.low .gbD-f{background:#fff4f5}`,
    build: (d, s) => s === 'ended' ? {} : {
      mid: `<div class="gbD-f" data-gbm-bonus><div class="gbD-g">${tile(d, 'gbD-t')}<span style="min-width:0"><b class="n">${esc(d.name)}</b><span class="gbD-ch"><i class="k" role="timer" aria-label="Bonus gift held for">${WATCH}<span class="gbm-clk" data-gbm-clk>${clock(d.left)}</span></i><i>Free</i><i>Full size</i><i>×${d.qty}</i></span></span></div></div>`,
    },
  },
  E: {
    name: 'E · Gold frame, flip-digit tag',
    line: 'No flip-clock header any more. The bonus ends the photo row in a gold frame with a gold ×2, and a gold tag at the foot of the frame holds tiny dark flip digits (14:52) that slide in as they change. Its name on one line below.',
    css: `.gbE-t{margin-bottom:13px;border:2px solid #c9a36a!important}.gbE-t b{background:#b8862a!important}
.gbE-tag{position:absolute;left:50%;bottom:-15px;transform:translateX(-50%);z-index:1;display:inline-flex;align-items:center;gap:1px;padding:2px 3px;border-radius:5px;background:#c9a36a;box-shadow:0 0 0 1.5px #fff;direction:ltr;white-space:nowrap}.gbE-tag i{display:inline-grid;place-items:center;width:9px;height:13px;border-radius:2px;background:#2a2228;color:#fff;font:800 10px/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-style:normal;box-shadow:inset 0 -6.5px 0 rgba(255,255,255,.08)}.gbE-tag i.in{animation:gbEin .35s ease-out}.gbE-tag i.in2{animation:gbEin2 .35s ease-out}@keyframes gbEin{from{transform:translateY(-35%);opacity:0}}@keyframes gbEin2{from{transform:translateY(-35%);opacity:0}}.gbE-tag u{text-decoration:none;font:800 10px/1 ui-monospace,monospace;color:#2a2228;padding:0 .5px}
.gbE-cap{margin-top:6px;font-size:11.5px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gbE-cap b{color:#8a6a2c}
.gbE.low .gbE-tag i{background:#d1213f}.gbE.low .gbE-tag{background:#f3c3cb}`,
    build: (d, s) => s === 'ended' ? {} : {
      tile: tile(d, 'gbE-t', `<span class="gbE-tag" role="timer" aria-label="Bonus gift held for" data-gbm-flip>${[...clock(d.left)].map((c) => c === ':' ? '<u>:</u>' : `<i>${c}</i>`).join('')}</span>`),
      after: `<div class="gbE-cap" data-gbm-bonus><b>Bonus:</b> ${d.qty > 1 ? d.qty + '× ' : ''}${esc(d.name)} · full size</div>`,
    },
  },
};

// The one clock for the previews (the shop's own would replace the row's): one
// interval, stops at zero; sets text and transforms only.
const TICK = `(function(w,d){if(w.gbmT)return;w.gbmT=1;function f(s){var h=Math.floor(s/3600),m=Math.floor(s%3600/60),x=s%60;return(h?h+':':'')+(m<10?'0':'')+m+':'+(x<10?'0':'')+x}var iv=setInterval(function(){var bs=d.querySelectorAll('[data-gbm-left]'),live=0;for(var i=0;i<bs.length;i++){var b=bs[i];if(!b.gbmEnd)b.gbmEnd=Date.now()+(+b.getAttribute('data-gbm-left'))*1000;var s=Math.max(0,Math.round((b.gbmEnd-Date.now())/1000)),T=+b.getAttribute('data-gbm-total')||1,P=s/T,t=f(s);var c=b.querySelector('[data-gbm-clk]');if(c)c.textContent=t;var l=b.querySelector('[data-gbm-line]');if(l)l.style.transform='scaleX('+P.toFixed(3)+')';var a=P*360,r=b.querySelector('[data-gbm-ring=r]'),q=b.querySelector('[data-gbm-ring=l]');if(r)r.style.transform='rotate('+(Math.min(a,180)-135)+'deg)';if(q)q.style.transform='rotate('+(Math.max(a-180,0)-135)+'deg)';var fl=b.querySelector('[data-gbm-flip]');if(fl){var ds=fl.querySelectorAll('i'),ch=t.replace(/:/g,'');if(ds.length===ch.length)for(var k=0;k<ds.length;k++)if(ds[k].textContent!==ch[k]){ds[k].textContent=ch[k];var o=ds[k].className==='in'?'in2':'in';ds[k].className=o}}b.classList.toggle('low',s<=120);if(s<=0){b.removeAttribute('data-gbm-left');b.classList.remove('low');var g=b.querySelectorAll('[data-gbm-bonus]');for(var j=0;j<g.length;j++)g[j].classList.add('gbm-out');setTimeout(function(g){return function(){for(var j=0;j<g.length;j++)g[j].remove()}}(g),450)}else live++}if(!live)clearInterval(iv)},1000)})(window,document)`;

module.exports = { D, BASE_CSS, TICK, clock };
