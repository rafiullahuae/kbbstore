@verbatim<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@endverbatim
{!! $seo ?? '' !!}
{!! app(\App\Services\Analytics::class)->headTags() !!}
@verbatim
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#fff;--cream:#FFF8F5;--pink-soft:#FFF0F4;--blush:#FCE0E8;--pink:#E0567B;--pink-deep:#C13E63;
  --pink-ink:#A82F53;--ink:#2A2228;--ink-2:#5E545A;--muted:#8C828A;--line:rgba(42,34,40,.10);
  --line-2:rgba(42,34,40,.06);--gold:#BE8E2E;--green:#2E9E6B;--sale:#E23A4E;--lav:#8B5CF6;--coral:#F2884E;
  --sans:"Poppins",system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  --ease:cubic-bezier(.22,.61,.36,1);--sh-s:0 2px 12px rgba(42,34,40,.07);--sh-m:0 18px 40px -14px rgba(168,47,83,.34);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:var(--sans);color:var(--ink);min-height:100vh;-webkit-font-smoothing:antialiased;line-height:1.45;
  background:
   radial-gradient(55% 45% at 88% -6%,#FFD3E4,transparent 70%),
   radial-gradient(50% 42% at 4% 8%,#EBDEFB,transparent 70%),
   radial-gradient(55% 45% at 50% 112%,#FFE3CF,transparent 70%),
   linear-gradient(180deg,#FFF6FA,#FFF8F5);}
.deco{position:fixed;inset:0;overflow:hidden;pointer-events:none;z-index:0}
.blob{position:absolute;border-radius:50%;filter:blur(50px);opacity:.5}
.blob.b1{width:300px;height:300px;background:#FFC6DC;top:-60px;inset-inline-end:-40px}
.blob.b2{width:260px;height:260px;background:#D9C7Fb;bottom:6%;inset-inline-start:-60px}
.blob.b3{width:220px;height:220px;background:#FFE0C2;top:40%;inset-inline-end:-50px;opacity:.4}
button{font-family:inherit;cursor:pointer;border:0;background:none;color:inherit}
input,textarea{font-family:inherit}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;background:linear-gradient(120deg,var(--pink),var(--pink-deep));color:#fff;font-weight:600;font-size:13.5px;padding:12px 22px;border-radius:99px;transition:.18s var(--ease);box-shadow:0 6px 16px -6px rgba(193,62,99,.5)}
.btn:hover{transform:translateY(-1px);box-shadow:var(--sh-m)}
.btn:disabled{opacity:.4;cursor:not-allowed;transform:none;box-shadow:none}
.btn.ghost{background:#fff;color:var(--ink);border:1px solid var(--line);box-shadow:none}
.btn.ghost:hover{border-color:var(--pink);color:var(--pink-deep);background:#fff}
.btn.sm{padding:9px 15px;font-size:12.5px}
.btn.block{width:100%}
/* Phone shopper pass - Lane EA. This page draws its own buttons, so it gets
   none of the storefront's mobile tap-target rules: "Start the quiz" and every
   Next/Back after it were 40px tall at 360px. */
@media (max-width: 820px) { .btn{min-height:44px} }

.shell{position:relative;z-index:1;max-width:720px;margin:0 auto;padding:22px 18px 50px}
.qhead{display:flex;align-items:center;gap:10px;margin-bottom:12px}
/* font-size and font-weight are set here, so the heading that carries this
   class does not pick up the browser's 2em bold default. */
.brand{font-size:14px;font-weight:800;letter-spacing:-.01em;color:var(--pink-deep);margin:0}
.brand span{color:var(--ink);font-weight:600}
.qcount{margin-inline-start:auto;font-size:11.5px;font-weight:600;color:var(--muted)}
.pips{display:flex;gap:5px;margin-bottom:18px}
.pip{height:6px;flex:1;border-radius:99px;background:var(--blush);transition:.4s var(--ease);position:relative;overflow:hidden}
.pip.done,.pip.cur{background:linear-gradient(90deg,var(--pink),var(--coral) 50%,var(--lav))}
.pip.cur::after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.75),transparent);transform:translateX(-100%);animation:sheen 1.6s var(--ease) infinite}
@keyframes sheen{to{transform:translateX(100%)}}

.card{position:relative;background:#fff;border:1px solid var(--line);border-radius:24px;box-shadow:0 20px 50px -22px rgba(168,47,83,.28),var(--sh-s);padding:clamp(20px,3.2vw,34px);overflow:hidden;animation:rise .4s var(--ease)}
.card::before{content:"";position:absolute;top:0;inset-inline-start:0;inset-inline-end:0;height:4px;background:linear-gradient(90deg,var(--pink),var(--coral),var(--lav),var(--pink))}
@keyframes rise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

.qeye{display:inline-flex;align-items:center;gap:7px;font-size:10.5px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--pink-deep);margin-bottom:9px}
.qeye .dot{width:6px;height:6px;border-radius:50%;background:linear-gradient(120deg,var(--pink),var(--lav))}
.qttl{font-size:clamp(19px,2.5vw,23px);font-weight:700;letter-spacing:-.015em;line-height:1.2}
.qttl small{display:block;font-size:12.5px;font-weight:400;color:var(--muted);margin-top:4px;letter-spacing:0;line-height:1.45}
.grad{background:linear-gradient(100deg,var(--pink-deep),var(--lav) 55%,var(--pink));-webkit-background-clip:text;background-clip:text;color:transparent}

.opts{display:grid;gap:10px;margin-top:18px}
.opts.cols2{grid-template-columns:1fr 1fr}
.opts.cols3{grid-template-columns:1fr 1fr 1fr}
.opts.pills{display:flex;flex-wrap:wrap;gap:9px}
@media(max-width:560px){.opts.cols3{grid-template-columns:1fr 1fr}}
@media(max-width:440px){.opts.cols2{grid-template-columns:1fr}}
.opt{display:flex;align-items:center;gap:11px;text-align:start;border:1.5px solid var(--line);border-radius:14px;padding:12px 13px;background:#fff;transition:.15s var(--ease);position:relative;animation:pop .32s var(--ease) both}
@keyframes pop{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}
.opt:hover{transform:translateY(-2px);box-shadow:var(--sh-s)}
.opt.sel{border-color:var(--pink);background:linear-gradient(120deg,#fff,var(--pink-soft));box-shadow:0 0 0 3px rgba(224,86,123,.13)}
.opt .oic{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;flex-shrink:0}
.opt .oic svg{width:19px;height:19px}
.opt .ot{font-size:13px;font-weight:600;line-height:1.2}
.opt .od{font-size:10.5px;color:var(--muted);margin-top:1px}
.opt .check{position:absolute;top:10px;inset-inline-end:10px;width:18px;height:18px;border-radius:50%;border:1.5px solid var(--line);display:grid;place-items:center;opacity:0;transition:.15s}
.opt.sel .check{opacity:1;background:var(--pink);border-color:var(--pink);color:#fff}
.opt .check svg{width:12px;height:12px}
.opts.pills .opt{padding:10px 17px;border-radius:99px}
.opts.pills .opt.sel{background:linear-gradient(120deg,var(--pink),var(--pink-deep));border-color:var(--pink);color:#fff}
.opts.pills .check{display:none}

.nav{display:flex;align-items:center;gap:10px;margin-top:22px}
.nav .spacer{flex:1}
.back{display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:var(--ink-2);padding:8px 4px}
.back:hover{color:var(--pink-deep)}
.hint{font-size:11.5px;color:var(--muted)}

.gate-ic{width:50px;height:50px;border-radius:15px;background:linear-gradient(135deg,var(--blush),#EBDEFB);display:grid;place-items:center;color:var(--pink-deep);margin-bottom:14px}
.gate-ic svg{width:26px;height:26px}
.field{margin-top:12px}
.field label{display:block;font-size:11.5px;font-weight:600;color:var(--ink-2);margin-bottom:5px}
.field input,.field textarea{width:100%;border:1.5px solid var(--line);border-radius:12px;padding:12px 14px;font-size:13.5px;color:var(--ink);background:#fff;transition:.15s}
.field input:focus,.field textarea:focus{outline:none;border-color:var(--pink);box-shadow:0 0 0 3px rgba(224,86,123,.12)}
.field.err input{border-color:var(--sale)}
.field .msg{font-size:11px;color:var(--sale);margin-top:4px;display:none}
.field.err .msg{display:block}
.two{display:grid;grid-template-columns:1fr 1fr;gap:11px}
@media(max-width:440px){.two{grid-template-columns:1fr}}
.trust-row{display:flex;flex-wrap:wrap;gap:7px;margin-top:14px}
.trust-row span{font-size:11px;color:var(--ink-2);background:var(--cream);border:1px solid var(--line-2);border-radius:99px;padding:6px 11px;display:inline-flex;gap:5px;align-items:center}
.trust-row svg{width:12px;height:12px;color:var(--green)}

.res-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}
.res-chip{font-size:11px;font-weight:600;border-radius:99px;padding:5px 12px}
.avoid-note{margin-top:10px;font-size:11.5px;color:var(--ink-2);background:#FFF3E9;border:1px solid #FAD9BD;border-radius:11px;padding:9px 12px}
.avoid-note b{color:var(--coral)}
.routine{border:1px solid var(--line);border-radius:17px;overflow:hidden;margin-top:14px;background:#fff}
.rtabs{display:flex;gap:5px;background:var(--cream);border:1px solid var(--line);border-radius:99px;padding:5px;margin-top:16px}
.rtab{flex:1;font-size:11.5px;font-weight:600;color:var(--ink-2);padding:9px 6px;border-radius:99px;transition:.16s var(--ease);white-space:nowrap}
.rtab:hover{color:var(--pink-deep)}
.rtab.on{background:linear-gradient(120deg,var(--pink),var(--pink-deep));color:#fff;box-shadow:0 4px 12px -5px rgba(193,62,99,.55)}
#rpanel .routine{margin-top:12px;animation:rise .3s var(--ease)}
.rgrid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px}
@media(max-width:360px){.rgrid{grid-template-columns:1fr}}
.rcell{display:flex;align-items:center;gap:10px;border:1px solid var(--line-2);border-radius:12px;padding:8px 9px;background:#fff}
.rcw{min-width:0;flex:1}
.rcell .rthumb{width:36px;height:36px;border-radius:9px;font-size:11px}
.rcell .rpname{font-size:12px;font-weight:600;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rpb{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:2px}
.rcell .rpbrand{font-size:10px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rcell .rpprice{font-size:11.5px;font-weight:700;white-space:nowrap;flex-shrink:0}
.routine-h{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 16px;background:linear-gradient(120deg,var(--cream),var(--pink-soft));border-bottom:1px solid var(--line)}
.routine-h .rt{font-size:15px;font-weight:700;letter-spacing:-.01em}
.routine-h .rd{font-size:11.5px;color:var(--ink-2);margin-top:2px;max-width:360px;line-height:1.4}
.routine-h .rtag{font-size:9.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#fff;padding:4px 9px;border-radius:99px;white-space:nowrap}
.rprod{display:flex;align-items:center;gap:12px;padding:11px 16px;border-bottom:1px solid var(--line-2)}
.rthumb{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;font-weight:800;font-size:12px;flex-shrink:0}
.rstep{font-size:9.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--pink-deep)}
.rpname{font-size:12.5px;font-weight:600;line-height:1.25}
.rpbrand{font-size:10.5px;color:var(--muted)}
.rpprice{margin-inline-start:auto;font-size:12.5px;font-weight:700;white-space:nowrap}
.routine-f{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:13px 16px}
.routine-f .rsum{font-size:12.5px}
.routine-f .rsum b{font-size:17px}
.routine-f .rsum s{color:var(--muted);font-weight:400;margin-inline-start:5px;font-size:11px}
.save-pill{background:var(--green);color:#fff;font-size:9.5px;font-weight:700;padding:2px 8px;border-radius:99px;margin-inline-start:6px}

.expert{margin-top:16px;border:1.5px solid #E7D6FB;border-radius:17px;padding:18px;background:linear-gradient(135deg,#fff,#F3ECFD)}
.expert h3{font-size:15px;font-weight:700;display:flex;align-items:center;gap:9px}
.expert h3 .eic{width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,var(--lav),var(--pink));color:#fff;display:grid;place-items:center}
.expert h3 .eic svg{width:17px;height:17px}
.expert p{font-size:12px;color:var(--ink-2);margin:8px 0 12px;max-width:480px;line-height:1.45}
.expert .ex-sent{display:none;align-items:center;gap:11px;background:#fff;border:1px solid var(--green);border-radius:13px;padding:14px}
.expert.sent .ex-form{display:none}
.expert.sent .ex-sent{display:flex}
.ex-sent .ok{width:32px;height:32px;border-radius:50%;background:var(--green);color:#fff;display:grid;place-items:center;flex-shrink:0}

.beview{margin-top:18px;border:1px dashed var(--line);border-radius:14px;background:var(--cream);overflow:hidden}
.beview summary{cursor:pointer;list-style:none;padding:13px 16px;font-size:12.5px;font-weight:600;color:var(--ink-2);display:flex;align-items:center;gap:8px}
.beview summary::-webkit-details-marker{display:none}
.beview summary .lk{width:15px;height:15px;color:var(--pink-deep)}
.beview[open] summary{border-bottom:1px dashed var(--line)}
.beview pre{margin:0;padding:15px 16px;font-size:11px;line-height:1.65;color:var(--ink-2);overflow-x:auto;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap}
.beview .bk{color:var(--pink-deep)}

.start-perks{display:flex;flex-wrap:wrap;gap:9px;margin-top:18px}
.start-perks span{font-size:12px;font-weight:500;color:var(--ink-2);display:inline-flex;gap:6px;align-items:center}
.start-perks svg{width:16px;height:16px;color:var(--pink-deep)}

/* RTL-PHYSICAL: centring idiom (left:50% + translateX(-50%)). */
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(20px);background:var(--ink);color:#fff;font-size:13px;font-weight:500;padding:12px 19px;border-radius:99px;box-shadow:var(--sh-m);opacity:0;pointer-events:none;transition:.3s var(--ease);z-index:50}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.preview-flag{position:fixed;top:10px;inset-inline-start:10px;font-size:9.5px;font-weight:700;letter-spacing:.04em;color:var(--pink-ink);background:#fff;border:1px solid var(--blush);padding:4px 10px;border-radius:99px;z-index:40;box-shadow:var(--sh-s)}
</style>
</head>
<body>
<div class="deco"><div class="blob b1"></div><div class="blob b2"></div><div class="blob b3"></div></div>
<div class="preview-flag">PREVIEW · front-end only</div>
<div class="shell">
  <div class="qhead">
@endverbatim
    {{-- <h1>, not <div>. /skin-quiz served no heading of any level: every
         heading on this page is written by renderStart() and the step
         renderers into #stage, so they exist only after the script runs and
         none of them is in the served HTML. This is the one heading the page
         has server-side, so it is the one that carries the level. The .brand
         rule keeps the size and weight, so the header looks exactly as it did.

         The verbatim block is closed around this note and reopened after it on
         purpose. Everything from the top of this file to the footer is one
         verbatim region, and a Blade comment inside a verbatim region is not a
         comment at all — it is literal text, and it was being served to every
         visitor as part of the page. --}}
@verbatim
    <h1 class="brand">K-Beauty Bliss <span>· Skin Quiz</span></h1>
    <div class="qcount" id="qcount"></div>
  </div>
  <div class="pips" id="pips"></div>
  <div id="stage"></div>
</div>
<div class="toast" id="toast"></div>

<script>
const $=(s,r=document)=>r.querySelector(s);
const stage=$('#stage');
let stepIndex=0;
let RES=[];
const API='';
const state={skin:null,concerns:[],age:null,depth:null,budget:null,allergies:[],allergyNote:'',name:'',phone:'',email:'',expertMsg:'',expertSent:false,leadId:null};

/* pastel palette for option icons / chips */
const PAL=[['#FCE0E8','#C13E63'],['#FFE3D6','#C9602F'],['#FFF1CE','#A9802A'],['#E0F4E7','#2E9E6B'],['#E1ECFB','#3B73B8'],['#ECE2FB','#7C5CD8'],['#FBE0EF','#B23A80'],['#FCE6DB','#D0552E']];

const SKINS=[
  {v:'Dry',d:'Tight, flaky',ic:'<path d="M12 3c4 5 6 8 6 11a6 6 0 0 1-12 0c0-3 2-6 6-11z"/>'},
  {v:'Oily',d:'Shiny, large pores',ic:'<circle cx="12" cy="13" r="6"/><path d="M12 3v3"/>'},
  {v:'Combination',d:'Oily T-zone',ic:'<circle cx="12" cy="12" r="9"/><path d="M12 3v18"/>'},
  {v:'Sensitive',d:'Reacts easily',ic:'<path d="M12 21s-7-4.5-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 11c0 5.5-7 10-7 10z"/>'},
  {v:'Normal',d:'Balanced',ic:'<circle cx="12" cy="12" r="9"/><path d="M8 14c1.4 1.4 6.6 1.4 8 0"/>'},
  {v:'Not sure',d:'Help me out',ic:'<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 0 1 4.5 1.5c0 1.5-2 2-2 3M12 17h.01"/>'}
];
const CONCERNS=[
  {v:'Hydration',ic:'<path d="M12 3c4 5 6 8 6 11a6 6 0 0 1-12 0c0-3 2-6 6-11z"/>'},
  {v:'Dark spots & tone',ic:'<circle cx="12" cy="12" r="9"/><circle cx="9" cy="10" r="1.2"/><circle cx="15" cy="13" r="1.2"/>'},
  {v:'Acne & blemishes',ic:'<circle cx="12" cy="12" r="9"/><circle cx="9" cy="9" r="1"/><circle cx="14" cy="14" r="1.2"/>'},
  {v:'Fine lines & aging',ic:'<path d="M4 18c4-2 4-12 8-12s4 10 8 12"/>'},
  {v:'Redness & sensitivity',ic:'<path d="M12 21s-7-4.5-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 11c0 5.5-7 10-7 10z"/>'},
  {v:'Pores & oil',ic:'<circle cx="12" cy="12" r="9"/><circle cx="10" cy="9" r=".9"/><circle cx="14" cy="11" r=".9"/><circle cx="11" cy="14" r=".9"/>'},
  {v:'Dullness & glow',ic:'<circle cx="12" cy="12" r="4"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>'},
  {v:'Sun protection',ic:'<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2"/>'}
];
const AGES=['Under 18','18–24','25–34','35–44','45+'];
const DEPTHS=[{v:'Minimal',d:'2–3 steps'},{v:'Balanced',d:'4–5 steps'},{v:'Full ritual',d:'6+ steps'}];
const BUDGETS=[{v:'Best value',d:'Wallet-friendly'},{v:'Mid-range',d:'Smart quality'},{v:'Premium',d:'The best'},{v:'Mix it up',d:'Value + heroes'}];
const ALLERGENS=[
  {v:'Fragrance / parfum'},{v:'Essential oils'},{v:'Alcohol (drying)'},
  {v:'Nut oils'},{v:'Lanolin'},{v:'None / not sure'}
];

const POOL={
  cleanseOil:{b:'Anua',n:'Heartleaf Cleansing Oil',p:79},
  cleanseGel:{b:'COSRX',n:'Low pH Gel Cleanser',p:49},
  tonerSoothe:{b:'Anua',n:'Heartleaf 77 Toner',p:89},
  tonerHydra:{b:'Isntree',n:'Hyaluronic Acid Toner',p:79},
  serumHydra:{b:'Torriden',n:'Dive-In HA Serum',p:69},
  serumGlow:{b:'Beauty of Joseon',n:'Glow Serum',p:75},
  serumVitC:{b:'Numbuzin',n:'No.5 Vitamin C Serum',p:99},
  serumAcne:{b:'Some By Mi',n:'AHA-BHA-PHA Serum',p:89},
  serumBarrier:{b:'COSRX',n:'Snail 96 Mucin Essence',p:79},
  serumPDRN:{b:'Medicube',n:'PDRN Pink Collagen Serum',p:149},
  serumCica:{b:'SKIN1004',n:'Centella Ampoule',p:79},
  creamRich:{b:'Torriden',n:'Dive-In Cream',p:75},
  creamGel:{b:'COSRX',n:'Oil-Free Moisturizer',p:69},
  creamPDRN:{b:'Medicube',n:'PDRN Capsule Cream',p:129},
  spf:{b:'Beauty of Joseon',n:'Relief Sun SPF50+',p:65},
  mask:{b:'MEDIHEAL',n:'Glow Mask · 5 pack',p:55},
  eye:{b:'Medicube',n:'Age-R Eye Cream',p:99}
};
function thumb(b){return b.split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();}

function concernSerum(c){
  if(/Acne/.test(c))return POOL.serumAcne;
  if(/Dark spots/.test(c))return POOL.serumVitC;
  if(/aging/.test(c))return POOL.serumPDRN;
  if(/Redness/.test(c))return POOL.serumCica;
  if(/Pores/.test(c))return POOL.serumAcne;
  if(/Dullness/.test(c))return POOL.serumGlow;
  if(/Hydration/.test(c))return POOL.serumHydra;
  return POOL.serumGlow;
}
function recommend(){
  const oily=/Oily|Combination/.test(state.skin)||state.concerns.some(c=>/Pores|Acne/.test(c));
  const dry=/Dry|Sensitive/.test(state.skin)||state.concerns.includes('Hydration');
  const cleanse=oily?POOL.cleanseGel:POOL.cleanseOil;
  const toner=(/Sensitive|Redness/.test(state.skin)||state.concerns.includes('Redness & sensitivity'))?POOL.tonerSoothe:(dry?POOL.tonerHydra:POOL.tonerSoothe);
  const c1=state.concerns[0], c2=state.concerns[1];
  const treat1=c1?concernSerum(c1):POOL.serumGlow;
  let treat2=c2?concernSerum(c2):null; if(treat2&&treat2.n===treat1.n)treat2=null;
  const cream=dry?(state.concerns.includes('Fine lines & aging')?POOL.creamPDRN:POOL.creamRich):POOL.creamGel;
  const spf=POOL.spf;
  const essentials=[['Cleanse',cleanse],['Treat',treat1],['Moisturise',cream],['Protect',spf]];
  const glass=[['Cleanse',cleanse],['Tone',toner],['Treat',treat1]];
  if(treat2)glass.push(['Boost',treat2]);
  glass.push(['Moisturise',cream],['Protect',spf]);
  const targeted=[['Weekly',POOL.mask]];
  if(state.concerns.includes('Fine lines & aging'))targeted.push(['Eye',POOL.eye]);
  else if(treat2)targeted.push(['Target',treat2]);
  else targeted.push(['Barrier',POOL.serumBarrier]);
  return [
    {t:'Everyday Essentials',short:'Essentials',tag:'Start here',d:'The core 4 steps for 90% of your goals.',items:essentials},
    {t:'Glass-Skin Ritual',short:'Glass-Skin',tag:'Best results',d:'The full layering routine for that dewy glow.',items:glass},
    {t:'Targeted Boosters',short:'Boosters',tag:'Add-ons',d:'Extra treatments for your top concerns.',items:targeted}
  ];
}

const FLOW=['start','skin','concerns','age','depth','budget','allergy','contact','results'];
const QSTEPS=6;
function setProgress(){
  let html='';
  for(let p=1;p<=7;p++){const cls=stepIndex>p?'done':(stepIndex===p?'cur':'');html+=`<div class="pip ${cls}"></div>`;}
  $('#pips').innerHTML=html;
  const s=FLOW[stepIndex];let label='';
  if(s==='start')label='~1 min';
  else if(s==='results')label='Your plan ✨';
  else if(s==='contact')label='Last step';
  else label='Step '+stepIndex+' / '+QSTEPS;
  $('#qcount').textContent=label;
}
function go(i){stepIndex=i;render();}
function next(){go(stepIndex+1);}
function prev(){go(stepIndex-1);}

function render(){
  setProgress();
  const s=FLOW[stepIndex];
  if(s==='start')return renderStart();
  if(s==='skin')return renderSingle({key:'skin',eye:'Your skin',q:'What\'s your skin type?',sub:'Pick what sounds most like you.',opts:SKINS,cols:'cols3'});
  if(s==='concerns')return renderMulti();
  if(s==='age')return renderSingle({key:'age',eye:'About you',q:'Your age range?',sub:'Helps us pick the right actives.',opts:AGES.map(a=>({v:a})),cols:'pills'});
  if(s==='depth')return renderSingle({key:'depth',eye:'Your routine',q:'How many steps feel right?',sub:'We\'ll size it to your life.',opts:DEPTHS,cols:'cols3'});
  if(s==='budget')return renderSingle({key:'budget',eye:'Your routine',q:'Your budget vibe?',sub:'So picks feel right for you.',opts:BUDGETS,cols:'cols2'});
  if(s==='allergy')return renderAllergy();
  if(s==='contact')return renderContact();
  if(s==='results')return renderResults();
}
function optHTML(o,selected,i){
  const col=PAL[i%PAL.length];
  const ic=o.ic?`<span class="oic" style="background:${col[0]};color:${col[1]}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">${o.ic}</svg></span>`:'';
  const d=o.d?`<div class="od">${o.d}</div>`:'';
  return `<button class="opt${selected?' sel':''}" data-v="${o.v}">${ic}<span><span class="ot">${o.v}</span>${d}</span>
    <span class="check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span></button>`;
}
function applyStagger(){stage.querySelectorAll('.opt').forEach((b,i)=>b.style.animationDelay=(i*32)+'ms');}

function renderSingle({key,eye,q,sub,opts,cols}){
  stage.innerHTML=`<div class="card">
    <span class="qeye"><span class="dot"></span>${eye}</span>
    <h2 class="qttl">${q}<small>${sub}</small></h2>
    <div class="opts ${cols}">${opts.map((o,i)=>optHTML(o,state[key]===o.v,i)).join('')}</div>
    <div class="nav"><button class="back" onclick="prev()">‹ Back</button><div class="spacer"></div><span class="hint">Tap to continue</span></div>
  </div>`;
  applyStagger();
  stage.querySelectorAll('.opt').forEach(b=>b.onclick=()=>{
    state[key]=b.dataset.v;
    stage.querySelectorAll('.opt').forEach(x=>x.classList.remove('sel'));
    b.classList.add('sel');
    setTimeout(next,240);
  });
}
function renderMulti(){
  stage.innerHTML=`<div class="card">
    <span class="qeye"><span class="dot"></span>Your goals</span>
    <h2 class="qttl">What do you want to work on?<small>Choose up to 3.</small></h2>
    <div class="opts cols2">${CONCERNS.map((o,i)=>optHTML(o,state.concerns.includes(o.v),i)).join('')}</div>
    <div class="nav"><button class="back" onclick="prev()">‹ Back</button><div class="spacer"></div>
      <button class="btn" id="mNext" ${state.concerns.length?'':'disabled'} onclick="next()">Continue</button></div>
  </div>`;
  applyStagger();
  stage.querySelectorAll('.opt').forEach(b=>b.onclick=()=>{
    const v=b.dataset.v, i=state.concerns.indexOf(v);
    if(i>-1)state.concerns.splice(i,1);
    else{if(state.concerns.length>=3){toast('Pick up to 3 concerns');return;}state.concerns.push(v);}
    b.classList.toggle('sel');
    $('#mNext').disabled=!state.concerns.length;
  });
}
function renderAllergy(){
  stage.innerHTML=`<div class="card">
    <span class="qeye"><span class="dot"></span>Safety check</span>
    <h2 class="qttl">Any allergies or sensitivities?<small>So we steer clear of ingredients that don't agree with you. Optional — pick any that apply.</small></h2>
    <div class="opts cols2">${ALLERGENS.map((o,i)=>optHTML({v:o.v,ic:'<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.9a2 2 0 0 0 1.8-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>'},state.allergies.includes(o.v),i)).join('')}</div>
    <div class="field" style="margin-top:13px"><textarea id="iAllergyNote" rows="2" placeholder="Anything else we should avoid? (optional)">${state.allergyNote}</textarea></div>
    <div class="nav"><button class="back" onclick="prev()">‹ Back</button><div class="spacer"></div>
      <button class="btn" onclick="allergyNext()">Continue</button></div>
  </div>`;
  applyStagger();
  stage.querySelectorAll('.opt').forEach(b=>b.onclick=()=>{
    const v=b.dataset.v;
    if(/None/.test(v)) state.allergies=state.allergies.includes(v)?[]:[v];
    else{
      state.allergies=state.allergies.filter(x=>!/None/.test(x));
      const i=state.allergies.indexOf(v);
      if(i>-1)state.allergies.splice(i,1); else state.allergies.push(v);
    }
    stage.querySelectorAll('.opt').forEach(x=>x.classList.toggle('sel',state.allergies.includes(x.dataset.v)));
  });
}
function allergyNext(){state.allergyNote=$('#iAllergyNote').value.trim();next();}

function renderContact(){
  stage.innerHTML=`<div class="card">
    <span class="gate-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M22 6 12 13 2 6"/></svg></span>
    <span class="qeye"><span class="dot"></span>Almost there</span>
    <h2 class="qttl">Where do we send your routine?<small>We'll save your results &amp; email your plan. No spam, ever.</small></h2>
    <div class="field" id="fName"><label>Name</label><input id="iName" placeholder="e.g. Fatima" value="${state.name}"><div class="msg">Please enter your name</div></div>
    <div class="two">
      <div class="field" id="fPhone"><label>Phone (WhatsApp)</label><input id="iPhone" inputmode="tel" placeholder="+971 5X XXX XXXX" value="${state.phone}"><div class="msg">Enter a valid phone</div></div>
      <div class="field" id="fEmail"><label>Email</label><input id="iEmail" inputmode="email" placeholder="you@email.com" value="${state.email}"><div class="msg">Enter a valid email</div></div>
    </div>
    <div class="trust-row">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg> 10% off your first order</span>
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg> Free expert help</span>
    </div>
    <div class="nav"><button class="back" onclick="prev()">‹ Back</button><div class="spacer"></div>
      <button class="btn" onclick="submitContact()">See my routine →</button></div>
  </div>`;
}
async function submitContact(){
  const name=$('#iName').value.trim(), phone=$('#iPhone').value.trim(), email=$('#iEmail').value.trim();
  let ok=true;const setErr=(id,bad)=>{$('#'+id).classList.toggle('err',bad);if(bad)ok=false;};
  setErr('fName',name.length<2);
  setErr('fPhone',phone.replace(/[^\d]/g,'').length<7);
  setErr('fEmail',!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email));
  if(!ok)return;
  state.name=name;state.phone=phone;state.email=email;
  await ensureLead();                 // save the lead to the backend (offline: continues anyway)
  next();
}
async function ensureLead(){
  if(state.leadId) return state.leadId;
  try{
    const payload=buildPayload(); payload.consent=true; payload.source_url=location.pathname;
    const r=await fetch(API+'/api/quiz',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    if(r.ok){ const j=await r.json(); state.leadId=j.id||null; return state.leadId; }
  }catch(e){/* offline — preview still shows results */}
  return null;
}
function buildPayload(){
  return {submittedAt:new Date().toISOString(),skinType:state.skin,concerns:state.concerns.slice(),
    answers:{age:state.age,routineDepth:state.depth,budget:state.budget,allergies:state.allergies.slice(),allergyNote:state.allergyNote},
    contact:{name:state.name,phone:state.phone,email:state.email},
    recommendedRoutines:recommend().map(r=>{const total=r.items.reduce((s,it)=>s+it[1].p,0);const bundle=Math.round(total*0.85/5)*5;return {name:r.t,products:r.items.map(it=>it[1].b+' '+it[1].n),bundle_aed:bundle};}),
    expertRequest:state.expertSent?{requested:true,message:state.expertMsg}:{requested:false},status:'new'};
}
const TAGCOL=['#E0567B','#BE8E2E','#8B5CF6'];
function routinePanel(r,idx){
  const total=r.items.reduce((s,it)=>s+it[1].p,0);
  const bundle=Math.round(total*0.85/5)*5;
  const save=Math.round((1-bundle/total)*100);
  const cells=r.items.map(([step,p],j)=>{const col=PAL[(idx*2+j)%PAL.length];
    return `<div class="rcell"><span class="rthumb" style="background:${col[0]};color:${col[1]}">${thumb(p.b)}</span>
      <div class="rcw"><div class="rstep">${step}</div><div class="rpname">${p.n}</div>
        <div class="rpb"><span class="rpbrand">${p.b}</span><span class="rpprice">AED ${p.p}</span></div></div></div>`;}).join('');
  return `<div class="routine"><div class="routine-h"><div><div class="rt">${r.t}</div><div class="rd">${r.d}</div></div><span class="rtag" style="background:${TAGCOL[idx%3]}">${r.tag}</span></div>
    <div class="rgrid">${cells}</div>
    <div class="routine-f"><div class="rsum">Buy all ${r.items.length} · <b>AED ${bundle}</b><s>AED ${total}</s><span class="save-pill">SAVE ${save}%</span></div>
      <button class="btn sm" onclick="toast('Added ${r.items.length} products to bag')">Buy all</button></div></div>`;
}
function selRoutine(){}

function renderResults(){
  const routines=recommend();
  const chips=[state.skin,...state.concerns].map((c,i)=>{const col=PAL[i%PAL.length];return `<span class="res-chip" style="background:${col[0]};color:${col[1]}">${c}</span>`;}).join('');
  const avoid=state.allergies.filter(a=>!/None/.test(a));
  const avoidLine=(avoid.length||state.allergyNote)?`<div class="avoid-note"><b>Avoiding for you:</b> ${avoid.join(', ')}${avoid.length&&state.allergyNote?' · ':''}${state.allergyNote||''}</div>`:'';
  stage.innerHTML=`<div class="card">
    <span class="qeye"><span class="dot"></span>Your results</span>
    <h2 class="qttl"><span class="grad">${state.name}, here's your glow plan</span><small>Built for your skin &amp; the UAE climate · emailed to ${state.email}</small></h2>
    <div class="res-chips">${chips}</div>
    ${avoidLine}
    ${routines.map((r,i)=>routinePanel(r,i)).join('')}
    <div class="expert" id="expert">
      <div class="ex-form">
        <h3><span class="eic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 14a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M5 21c0-3.5 3-6 7-6s7 2.5 7 6"/></svg></span> Want a human to check it?</h3>
        <p>Send your quiz to a K-Beauty Bliss skin expert — we'll review your routine and message you on WhatsApp.</p>
        <div class="field"><textarea id="iMsg" rows="2" placeholder="Optional note (allergies, pregnancy, current products…)"></textarea></div>
        <div style="margin-top:12px"><button class="btn sm" onclick="sendExpert()">Send request to skin expert</button></div>
      </div>
      <div class="ex-sent"><span class="ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
        <div><div style="font-weight:700;font-size:13.5px">Request sent — talk soon!</div><div style="font-size:11.5px;color:var(--ink-2)">An expert will reach out on ${state.phone}.</div></div>
      </div>
    </div>
    <details class="beview"><summary><svg class="lk" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> What your store saves (backend preview)</summary><pre id="bePre"></pre></details>
    <div class="nav" style="justify-content:center"><button class="back" onclick="restart()">↺ Retake the quiz</button></div>
  </div>`;
  $('#bePre').innerHTML=prettyPayload(buildPayload());
}
async function sendExpert(){
  state.expertMsg=$('#iMsg').value.trim();state.expertSent=true;
  $('#expert').classList.add('sent');
  $('#bePre').innerHTML=prettyPayload(buildPayload());
  const id=await ensureLead();
  if(id){ try{ await fetch(API+'/api/quiz/'+id+'/expert-request',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:state.expertMsg})}); }catch(e){} }
  toast('Sent to a skin expert ✓');
}
function prettyPayload(o){return JSON.stringify(o,null,2).replace(/"([^"]+)":/g,'<span class="bk">"$1"</span>:');}
function renderStart(){
  stage.innerHTML=`<div class="card">
    <span class="qeye"><span class="dot"></span>1-minute skin quiz</span>
    <h2 class="qttl"><span class="grad">Find your glow.</span><small>A few quick questions → a personalised Korean routine, matched to your concerns and the UAE climate.</small></h2>
    <div class="start-perks">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg> ~1 minute</span>
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 2 2.9 6.3 6.9.7-5.1 4.6 1.4 6.8L12 17.8 5.9 20.4l1.4-6.8L2.2 9l6.9-.7z"/></svg> Personalised routines</span>
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 14a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM5 21c0-3.5 3-6 7-6s7 2.5 7 6"/></svg> Free expert help</span>
    </div>
    <div style="margin-top:20px"><button class="btn block" onclick="next()">Start the quiz →</button></div>
  </div>`;
}
function restart(){Object.assign(state,{skin:null,concerns:[],age:null,depth:null,budget:null,allergies:[],allergyNote:'',name:'',phone:'',email:'',expertMsg:'',expertSent:false});go(0);}
let toastT;
function toast(m){const t=$('#toast');t.textContent=m;t.classList.add('show');clearTimeout(toastT);toastT=setTimeout(()=>t.classList.remove('show'),2200);}
window.prev=prev;window.next=next;window.submitContact=submitContact;window.sendExpert=sendExpert;window.restart=restart;window.toast=toast;window.allergyNext=allergyNext;
render();
</script>
</body>
</html>

@endverbatim
