@verbatim<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
@endverbatim
{!! $seo ?? '' !!}
@verbatim
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Hanken+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ============ TOKENS ============ */
:root{
  --ink:#241E1C; --ink-2:#5B514C; --ink-3:#8B807A;
  --cream:#FBF6F0; --cream-2:#F4EAE0; --card:#FFFFFF;
  --blush:#F6DCDF; --blush-2:#FBEBED; --rose:#C36678; --rose-deep:#A8475C;
  --gold:#C19A45; --gold-2:#E7CF92;
  --line:rgba(36,30,28,.10); --line-2:rgba(36,30,28,.06);
  --good:#2E7D5B; --warn:#B4612A;
  --r-s:10px; --r-m:16px; --r-l:22px; --r-xl:28px;
  --sh-s:0 1px 2px rgba(36,30,28,.06), 0 4px 14px rgba(36,30,28,.05);
  --sh-m:0 8px 30px rgba(36,30,28,.10);
  --sh-l:0 18px 60px rgba(36,30,28,.18);
  --sans:"Hanken Grotesk", system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  --serif:"Fraunces", Georgia, "Times New Roman", serif;
  --header-h:62px; --topbar-h:34px;
  --ease:cubic-bezier(.22,.61,.36,1);
}
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{
  font-family:var(--sans); color:var(--ink); background:var(--cream);
  line-height:1.5; -webkit-font-smoothing:antialiased; overflow-x:hidden;
}
img{display:block;max-width:100%}
button{font:inherit;color:inherit;cursor:pointer;border:none;background:none}
a{color:inherit;text-decoration:none}
input,select,textarea{font:inherit;color:inherit}
::selection{background:var(--blush);color:var(--ink)}
.wrap{max-width:1240px;margin:0 auto;padding:0 18px}
@media(min-width:740px){.wrap{padding:0 28px}}

/* scrollbars on carousels */
.no-sb{scrollbar-width:none}
.no-sb::-webkit-scrollbar{display:none}

/* ============ TOP BAR + MARQUEE ============ */
.topbar{height:var(--topbar-h);background:var(--ink);color:#F4E9DE;font-size:12px;
  display:flex;align-items:center;overflow:hidden}
.marquee{display:flex;white-space:nowrap;will-change:transform;animation:slidex 26s linear infinite}
.marquee span{padding:0 26px;letter-spacing:.04em}
.marquee b{color:var(--gold-2);font-weight:600}
@keyframes slidex{from{transform:translateX(0)}to{transform:translateX(-50%)}}

/* ============ HEADER ============ */
header.app{position:sticky;top:0;z-index:60;background:rgba(251,246,240,.82);
  backdrop-filter:saturate(1.2) blur(14px);-webkit-backdrop-filter:saturate(1.2) blur(14px);
  border-bottom:1px solid var(--line-2)}
.hd{height:var(--header-h);display:flex;align-items:center;gap:14px}
.brand{font-family:var(--serif);font-weight:600;font-size:21px;letter-spacing:-.01em;
  line-height:1;display:flex;align-items:center;gap:9px;flex-shrink:0}
.brand .mark{width:30px;height:30px;border-radius:50%;flex-shrink:0;
  background:radial-gradient(circle at 32% 30%, #fff 0 16%, var(--blush) 30%, var(--rose) 78%);
  box-shadow:inset 0 0 0 1.5px rgba(255,255,255,.5), var(--sh-s)}
.brand small{display:block;font-family:var(--sans);font-weight:600;font-size:8.5px;
  letter-spacing:.34em;color:var(--rose);text-transform:uppercase;margin-top:3px}
.nav{display:none;gap:3px;margin-left:6px}
@media(min-width:980px){.nav{display:flex}}
.nav a{font-size:14px;font-weight:500;color:var(--ink-2);padding:8px 11px;border-radius:99px;
  transition:.18s var(--ease)}
.nav a:hover{color:var(--ink);background:var(--blush-2)}
.nav a.sale{color:var(--rose-deep);font-weight:700}
.hd-actions{margin-left:auto;display:flex;align-items:center;gap:4px}
.iconbtn{width:42px;height:42px;border-radius:50%;display:grid;place-items:center;position:relative;
  color:var(--ink);transition:.16s var(--ease)}
.iconbtn:hover{background:var(--blush-2)}
.iconbtn svg{width:21px;height:21px}
.count{position:absolute;top:4px;right:4px;min-width:17px;height:17px;border-radius:99px;
  background:var(--rose-deep);color:#fff;font-size:10px;font-weight:700;display:grid;place-items:center;
  padding:0 4px;transform:scale(0);transition:.2s var(--ease)}
.count.on{transform:scale(1)}
.searchpill{display:none}
@media(min-width:740px){.searchpill{display:flex;align-items:center;gap:8px;flex:1;max-width:340px;
  margin-left:8px;background:var(--card);border:1px solid var(--line);border-radius:99px;
  padding:9px 14px;color:var(--ink-3);font-size:14px;transition:.16s var(--ease)}
  .searchpill:hover{border-color:var(--rose);color:var(--ink-2)}}
.searchpill svg{width:17px;height:17px}

/* ============ BUTTONS ============ */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:600;
  font-size:14px;padding:12px 20px;border-radius:99px;transition:.18s var(--ease);
  background:var(--ink);color:#fff;white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:var(--sh-m)}
.btn:active{transform:translateY(0)}
.btn.rose{background:var(--rose-deep)}
.btn.block{width:100%}
.btn.lg{padding:15px 24px;font-size:15px}
.btn.ghost{background:var(--card);color:var(--ink);border:1px solid var(--line)}
.btn.ghost:hover{border-color:var(--rose);box-shadow:var(--sh-s)}
.btn[disabled]{opacity:.45;pointer-events:none}

/* ============ BADGES ============ */
.badge{font-size:11px;font-weight:700;padding:4px 9px;border-radius:99px;letter-spacing:.02em;
  display:inline-flex;align-items:center;gap:4px}
.badge.disc{background:var(--ink);color:#fff}
.badge.sale{background:var(--blush);color:var(--rose-deep)}
.badge.new{background:#E8F1EC;color:var(--good)}
.badge.gold{background:linear-gradient(120deg,#F6E7BE,#E7CF92);color:#6B5418}

/* ============ VIEW TRANSITIONS ============ */
#app{min-height:60vh}
.view{animation:viewin .42s var(--ease)}
@keyframes viewin{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

/* ============ SECTION HEADERS ============ */
.sec{padding:34px 0}
.sec-hd{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:18px}
.sec-hd h2{font-family:var(--serif);font-weight:600;font-size:clamp(22px,3.4vw,30px);letter-spacing:-.015em;line-height:1.05}
.sec-hd .sub{color:var(--ink-2);font-size:14px;margin-top:6px;max-width:50ch}
.eyebrow{font-size:11.5px;font-weight:700;letter-spacing:.22em;text-transform:uppercase;color:var(--rose);margin-bottom:9px}
.viewall{font-size:13px;font-weight:600;color:var(--ink-2);display:inline-flex;align-items:center;gap:5px;flex-shrink:0}
.viewall:hover{color:var(--rose-deep)}

/* ============ HERO ============ */
.hero{position:relative;border-radius:var(--r-xl);overflow:hidden;margin-top:18px;
  min-height:380px;display:grid}
.hslide{grid-area:1/1;display:grid;align-content:center;gap:18px;padding:44px clamp(24px,5vw,64px);
  opacity:0;transition:opacity .8s var(--ease);pointer-events:none;position:relative}
.hslide.on{opacity:1;pointer-events:auto}
.hslide h1{font-family:var(--serif);font-weight:600;font-size:clamp(30px,5.4vw,52px);
  letter-spacing:-.02em;line-height:1.02;max-width:16ch}
.hslide p{font-size:clamp(15px,2vw,18px);max-width:42ch;color:var(--ink-2)}
.hslide .row{display:flex;gap:12px;flex-wrap:wrap;margin-top:4px}
.h-glow{position:absolute;inset:0;z-index:-1}
.hero-dots{position:absolute;bottom:18px;left:clamp(24px,5vw,64px);display:flex;gap:7px;z-index:3}
.hero-dots i{width:8px;height:8px;border-radius:99px;background:rgba(36,30,28,.22);cursor:pointer;transition:.2s}
.hero-dots i.on{width:26px;background:var(--rose-deep)}
.hero-pay{position:absolute;right:clamp(20px,4vw,40px);bottom:20px;display:flex;gap:7px;z-index:3;flex-wrap:wrap;max-width:50%;justify-content:flex-end}
.paychip{background:rgba(255,255,255,.78);backdrop-filter:blur(6px);border-radius:8px;padding:5px 9px;
  font-size:10.5px;font-weight:700;color:var(--ink-2);box-shadow:var(--sh-s)}
@media(max-width:640px){.hero-pay{display:none}}

/* ============ QUICK TILES ============ */
.tiles{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-top:26px}
@media(max-width:860px){.tiles{grid-template-columns:repeat(3,1fr)}}
@media(max-width:460px){.tiles{grid-template-columns:repeat(2,1fr);gap:9px}}
.tile{position:relative;border-radius:var(--r-m);overflow:hidden;aspect-ratio:1/1;
  display:grid;place-items:center;text-align:center;padding:12px;
  box-shadow:var(--sh-s);transition:.2s var(--ease);cursor:pointer}
.tile:hover{transform:translateY(-3px);box-shadow:var(--sh-m)}
.tile b{font-family:var(--serif);font-weight:600;font-size:15px;line-height:1.1;z-index:2}
.tile small{font-size:11px;font-weight:600;color:var(--ink-2);z-index:2;margin-top:3px}
.tile .ic{font-size:26px;z-index:2;margin-bottom:4px}

/* ============ CAROUSEL ============ */
.car-wrap{position:relative}
.car{display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;padding:4px 2px 8px;scroll-behavior:smooth}
.car>*{scroll-snap-align:start;flex:0 0 auto}
.car-btn{position:absolute;top:38%;width:40px;height:40px;border-radius:50%;background:var(--card);
  box-shadow:var(--sh-m);display:none;place-items:center;z-index:4;transition:.16s}
@media(min-width:980px){.car-wrap:hover .car-btn{display:grid}}
.car-btn:hover{background:var(--ink);color:#fff}
.car-btn.l{left:-14px}.car-btn.r{right:-14px}
.car-btn svg{width:20px;height:20px}

/* ============ PRODUCT CARD ============ */
.pcard{width:210px;background:var(--card);border-radius:var(--r-m);overflow:hidden;
  box-shadow:var(--sh-s);transition:.22s var(--ease);position:relative;display:flex;flex-direction:column}
.grid .pcard{width:auto}
.pcard:hover{transform:translateY(-4px);box-shadow:var(--sh-m)}
.pcard .ph{position:relative;aspect-ratio:1/1;background:var(--cream-2);overflow:hidden}
.pcard .ph img{width:100%;height:100%;object-fit:cover;transition:.5s var(--ease)}
.pcard:hover .ph img{transform:scale(1.06)}
.pcard .ph .fallback{position:absolute;inset:0;display:grid;place-items:center;
  font-family:var(--serif);font-size:40px;color:var(--rose);
  background:radial-gradient(circle at 40% 35%,#fff,var(--blush-2) 70%,var(--blush))}
.pcard .topbadges{position:absolute;top:9px;left:9px;display:flex;flex-direction:column;gap:6px;z-index:2}
.wish{position:absolute;top:9px;right:9px;width:34px;height:34px;border-radius:50%;
  background:rgba(255,255,255,.86);backdrop-filter:blur(4px);display:grid;place-items:center;z-index:2;
  transition:.16s;box-shadow:var(--sh-s)}
.wish:hover{background:#fff;transform:scale(1.08)}
.wish svg{width:18px;height:18px;stroke:var(--ink);fill:none;transition:.16s}
.wish.on svg{fill:var(--rose-deep);stroke:var(--rose-deep)}
.pcard .pb{padding:12px 13px 14px;display:flex;flex-direction:column;gap:6px;flex:1}
.pcard .pbrand{font-size:10.5px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--rose)}
.pcard .pname{font-size:13.5px;font-weight:500;line-height:1.32;color:var(--ink);
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:35px}
.pcard .stars{display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--ink-3)}
.stars .s{color:var(--gold);letter-spacing:1px}
.pcard .price{display:flex;align-items:baseline;gap:8px;margin-top:auto;flex-wrap:wrap}
.price .now{font-weight:700;font-size:16px}
.price .was{font-size:12.5px;color:var(--ink-3);text-decoration:line-through}
.pcard .add{margin-top:9px;width:100%;border:1px solid var(--line);border-radius:99px;
  padding:9px;font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:7px;
  transition:.16s var(--ease)}
.pcard .add:hover{background:var(--ink);color:#fff;border-color:var(--ink)}
.pcard .add svg{width:16px;height:16px}
.pcard .add.done{background:var(--good);border-color:var(--good);color:#fff}

/* ============ BUILD ROUTINE ============ */
.routine{display:grid;grid-template-columns:repeat(6,1fr);gap:12px}
@media(max-width:760px){.routine{grid-template-columns:repeat(3,1fr)}}
.rstep{background:var(--card);border-radius:var(--r-m);padding:18px 12px;text-align:center;
  box-shadow:var(--sh-s);transition:.2s var(--ease);cursor:pointer}
.rstep:hover{transform:translateY(-3px);box-shadow:var(--sh-m)}
.rstep .n{font-family:var(--serif);font-size:13px;color:var(--rose);font-weight:600}
.rstep .ic{font-size:30px;margin:8px 0}
.rstep b{font-size:13px;font-weight:600;display:block}

/* ============ BRANDS ROW ============ */
.brandrow{display:flex;flex-wrap:wrap;gap:10px}
.bchip{padding:11px 20px;border-radius:99px;background:var(--card);box-shadow:var(--sh-s);
  font-weight:600;font-size:14px;transition:.18s var(--ease);cursor:pointer}
.bchip:hover{background:var(--ink);color:#fff;transform:translateY(-2px)}

/* ============ PROMO BANNERS ============ */
.promos{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:760px){.promos{grid-template-columns:1fr}}
.promo{border-radius:var(--r-l);padding:34px;min-height:230px;display:grid;align-content:center;gap:12px;
  position:relative;overflow:hidden;cursor:pointer;box-shadow:var(--sh-s)}
.promo h3{font-family:var(--serif);font-weight:600;font-size:26px;letter-spacing:-.01em;max-width:14ch}
.promo p{font-size:14px;color:var(--ink-2);max-width:34ch}
.promo .btn{justify-self:start;margin-top:4px}

/* ============ SHOP / FILTERS ============ */
.shop{display:grid;grid-template-columns:248px 1fr;gap:28px;align-items:start;padding-top:24px}
@media(max-width:920px){.shop{grid-template-columns:1fr}}
.filters{position:sticky;top:calc(var(--header-h) + 14px);background:var(--card);border-radius:var(--r-l);
  padding:20px;box-shadow:var(--sh-s)}
@media(max-width:920px){.filters{display:none}}
.fgroup{padding:14px 0;border-bottom:1px solid var(--line-2)}
.fgroup:first-child{padding-top:0}
.fgroup:last-child{border-bottom:none;padding-bottom:0}
.fgroup h4{font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-3);margin-bottom:11px}
.fopt{display:flex;align-items:center;gap:9px;padding:5px 0;font-size:14px;color:var(--ink-2);cursor:pointer}
.fopt:hover{color:var(--ink)}
.fopt input{accent-color:var(--rose-deep);width:16px;height:16px}
.fopt .c{margin-left:auto;font-size:12px;color:var(--ink-3)}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
@media(max-width:1100px){.grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:720px){.grid{grid-template-columns:repeat(2,1fr);gap:11px}}
.shop-top{display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.shop-top h1{font-family:var(--serif);font-weight:600;font-size:clamp(24px,4vw,34px);letter-spacing:-.02em}
.shop-top .cnt{color:var(--ink-3);font-size:14px}
.sortsel,.mfilter{margin-left:auto;border:1px solid var(--line);border-radius:99px;padding:10px 16px;
  font-size:13px;font-weight:600;background:var(--card);cursor:pointer}
.mfilter{display:none;align-items:center;gap:7px}
@media(max-width:920px){.mfilter{display:inline-flex}.sortsel{margin-left:0}}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.chip{background:var(--blush-2);color:var(--rose-deep);font-size:12.5px;font-weight:600;padding:6px 12px;
  border-radius:99px;display:inline-flex;align-items:center;gap:6px;cursor:pointer}
.chip:hover{background:var(--blush)}
.chip b{font-size:14px;line-height:1}

/* ============ PDP ============ */
.crumb{font-size:13px;color:var(--ink-3);padding:18px 0 6px;display:flex;gap:7px;flex-wrap:wrap;align-items:center}
.crumb a:hover{color:var(--rose-deep)}
.crumb .sep{opacity:.5}
.pdp{display:grid;grid-template-columns:1fr 1fr;gap:42px;padding:8px 0 10px;align-items:start}
@media(max-width:880px){.pdp{grid-template-columns:1fr;gap:24px}}
.gallery{display:grid;grid-template-columns:64px 1fr;gap:12px;position:sticky;top:calc(var(--header-h) + 16px)}
@media(max-width:880px){.gallery{position:static;grid-template-columns:1fr}}
.thumbs{display:flex;flex-direction:column;gap:9px}
@media(max-width:880px){.thumbs{flex-direction:row;order:2;overflow-x:auto}}
.thumb{width:64px;height:64px;border-radius:12px;overflow:hidden;border:2px solid transparent;
  background:var(--cream-2);flex-shrink:0;cursor:pointer;transition:.16s}
.thumb img{width:100%;height:100%;object-fit:cover}
.thumb.on{border-color:var(--rose-deep)}
.gmain{border-radius:var(--r-l);overflow:hidden;background:var(--cream-2);aspect-ratio:1/1;position:relative}
.gmain img{width:100%;height:100%;object-fit:cover}
.gmain .fallback{position:absolute;inset:0;display:grid;place-items:center;font-family:var(--serif);
  font-size:90px;color:var(--rose);background:radial-gradient(circle at 40% 35%,#fff,var(--blush-2) 70%,var(--blush))}
.pinfo h1{font-family:var(--serif);font-weight:600;font-size:clamp(24px,3.6vw,33px);letter-spacing:-.015em;line-height:1.08;margin:8px 0}
.pinfo .pbrand{font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--rose)}
.rrow{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-2)}
.rrow .lbl{color:var(--good);font-weight:700}
.pprice{display:flex;align-items:baseline;gap:12px;margin:16px 0;flex-wrap:wrap}
.pprice .now{font-size:30px;font-weight:700}
.pprice .was{font-size:17px;color:var(--ink-3);text-decoration:line-through}
.pdesc{color:var(--ink-2);font-size:15px;line-height:1.6;max-width:52ch}
.deliv{display:flex;align-items:center;gap:11px;background:var(--blush-2);border-radius:var(--r-m);
  padding:13px 15px;margin:18px 0;font-size:13.5px;color:var(--ink-2)}
.deliv .ic{font-size:22px}
.deliv b{color:var(--ink)}
.qtyrow{display:flex;gap:12px;align-items:stretch;margin:8px 0 14px;flex-wrap:wrap}
.qty{display:flex;align-items:center;border:1px solid var(--line);border-radius:99px;overflow:hidden;background:var(--card)}
.qty button{width:44px;height:48px;font-size:18px;font-weight:600;color:var(--ink-2);transition:.14s}
.qty button:hover{background:var(--blush-2);color:var(--ink)}
.qty span{min-width:36px;text-align:center;font-weight:700}
.qtyrow .btn{flex:1;min-width:180px}
.coderow{display:flex;align-items:center;gap:10px;border:1px dashed var(--rose);border-radius:var(--r-m);
  padding:11px 15px;font-size:13.5px;color:var(--rose-deep);background:#fff;margin-bottom:16px}
.coderow b{font-weight:800;letter-spacing:.04em}
.trust4{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:18px}
@media(max-width:520px){.trust4{grid-template-columns:repeat(2,1fr)}}
.tcell{text-align:center;padding:14px 8px;background:var(--card);border-radius:var(--r-m);box-shadow:var(--sh-s)}
.tcell .ic{font-size:22px}
.tcell b{display:block;font-size:12px;font-weight:700;margin-top:5px}
.tcell small{font-size:10.5px;color:var(--ink-3)}

/* PDP tabs */
.tabs{display:flex;gap:6px;border-bottom:1px solid var(--line);margin:38px 0 0;overflow-x:auto}
.tab{padding:13px 16px;font-size:14px;font-weight:600;color:var(--ink-3);border-bottom:2px solid transparent;
  white-space:nowrap;transition:.16s}
.tab.on{color:var(--ink);border-color:var(--rose-deep)}
.tabpane{padding:24px 0;font-size:15px;line-height:1.7;color:var(--ink-2);max-width:760px}
.tabpane h4{font-family:var(--serif);font-size:18px;color:var(--ink);margin:18px 0 8px}
.tabpane ul{padding-left:20px;display:flex;flex-direction:column;gap:6px}

/* reviews */
.rev-sum{display:grid;grid-template-columns:170px 1fr;gap:30px;align-items:center;
  background:var(--card);border-radius:var(--r-l);padding:24px;box-shadow:var(--sh-s);margin-bottom:20px}
@media(max-width:560px){.rev-sum{grid-template-columns:1fr;gap:16px;text-align:center}}
.rev-big{font-family:var(--serif);font-size:52px;font-weight:600;line-height:1}
.rev-bars{display:flex;flex-direction:column;gap:7px}
.rbar{display:flex;align-items:center;gap:10px;font-size:12.5px;color:var(--ink-2)}
.rbar .track{flex:1;height:7px;border-radius:99px;background:var(--cream-2);overflow:hidden}
.rbar .fill{height:100%;background:var(--gold);border-radius:99px}
.review{padding:18px 0;border-bottom:1px solid var(--line-2)}
.review .top{display:flex;align-items:center;gap:9px;margin-bottom:7px}
.av{width:36px;height:36px;border-radius:50%;background:var(--blush);color:var(--rose-deep);
  display:grid;place-items:center;font-weight:700;font-size:14px}
.vbadge{font-size:11px;color:var(--good);font-weight:700}
.review p{color:var(--ink-2);font-size:14.5px}
.review .help{margin-top:8px;font-size:12px;color:var(--ink-3)}

/* ============ DRAWER (cart/wishlist/filters/search) ============ */
.scrim{position:fixed;inset:0;background:rgba(36,30,28,.42);backdrop-filter:blur(2px);z-index:90;
  opacity:0;pointer-events:none;transition:.3s var(--ease)}
.scrim.on{opacity:1;pointer-events:auto}
.drawer{position:fixed;top:0;right:0;height:100dvh;width:min(420px,100vw);background:var(--cream);z-index:100;
  display:flex;flex-direction:column;transform:translateX(100%);transition:transform .38s var(--ease);box-shadow:var(--sh-l)}
.drawer.on{transform:none}
.drawer.left{right:auto;left:0;transform:translateX(-100%)}
.drawer.left.on{transform:none}
.dr-hd{display:flex;align-items:center;gap:10px;padding:18px 20px;border-bottom:1px solid var(--line)}
.dr-hd h3{font-family:var(--serif);font-weight:600;font-size:20px}
.dr-hd .x{margin-left:auto;width:38px;height:38px;border-radius:50%;display:grid;place-items:center;transition:.14s}
.dr-hd .x:hover{background:var(--blush-2)}
.dr-body{flex:1;overflow-y:auto;padding:18px 20px}
.dr-foot{padding:18px 20px;border-top:1px solid var(--line);background:var(--card)}

/* free shipping meter */
.ship-meter{background:var(--card);border-radius:var(--r-m);padding:13px 15px;margin-bottom:16px;box-shadow:var(--sh-s)}
.ship-meter p{font-size:13px;color:var(--ink-2);margin-bottom:8px}
.ship-meter b{color:var(--rose-deep)}
.meter{height:8px;border-radius:99px;background:var(--cream-2);overflow:hidden}
.meter i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--rose),var(--gold));
  transition:width .5s var(--ease)}

.citem{display:grid;grid-template-columns:72px 1fr auto;gap:12px;padding:14px 0;border-bottom:1px solid var(--line-2)}
.citem .ci-img{width:72px;height:72px;border-radius:12px;overflow:hidden;background:var(--cream-2)}
.citem .ci-img img{width:100%;height:100%;object-fit:cover}
.citem .ci-name{font-size:13.5px;font-weight:500;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.citem .ci-brand{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--rose)}
.citem .ci-price{font-weight:700;font-size:14px;margin-top:4px}
.citem .ci-qty{display:flex;align-items:center;gap:8px;margin-top:6px}
.citem .ci-qty button{width:26px;height:26px;border-radius:50%;border:1px solid var(--line);font-weight:700;color:var(--ink-2)}
.citem .ci-qty button:hover{background:var(--blush-2)}
.citem .rm{font-size:11.5px;color:var(--ink-3);text-decoration:underline;align-self:start}
.citem .rm:hover{color:var(--rose-deep)}
.coupon{display:flex;gap:8px;margin:14px 0}
.coupon input{flex:1;border:1px solid var(--line);border-radius:99px;padding:11px 16px;font-size:13.5px;background:var(--card)}
.coupon input:focus{outline:none;border-color:var(--rose)}
.coupon button{padding:11px 18px;border-radius:99px;background:var(--ink);color:#fff;font-weight:600;font-size:13px}
.sumrow{display:flex;justify-content:space-between;font-size:14px;color:var(--ink-2);padding:5px 0}
.sumrow.tot{font-size:18px;font-weight:700;color:var(--ink);padding-top:12px;margin-top:6px;border-top:1px solid var(--line)}
.sumrow .disc{color:var(--good);font-weight:600}
.empty{text-align:center;padding:60px 20px;color:var(--ink-3)}
.empty .ic{font-size:48px;margin-bottom:12px}
.empty b{display:block;color:var(--ink);font-size:17px;font-family:var(--serif);margin-bottom:6px}

/* history strip in cart */
.hist-strip{margin-top:20px}
.hist-strip h4{font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-3);margin-bottom:10px}
.hist-row{display:flex;gap:10px;overflow-x:auto;padding-bottom:4px}
.hmini{flex:0 0 84px;cursor:pointer}
.hmini .him{width:84px;height:84px;border-radius:12px;overflow:hidden;background:var(--cream-2);box-shadow:var(--sh-s)}
.hmini .him img{width:100%;height:100%;object-fit:cover}
.hmini small{display:block;font-size:11px;font-weight:700;margin-top:5px}

/* ============ SEARCH OVERLAY ============ */
.search-ov{position:fixed;inset:0;z-index:110;background:rgba(251,246,240,.97);backdrop-filter:blur(10px);
  opacity:0;pointer-events:none;transition:.26s var(--ease);display:flex;flex-direction:column}
.search-ov.on{opacity:1;pointer-events:auto}
.search-ov .bar{display:flex;align-items:center;gap:12px;padding:20px 18px;border-bottom:1px solid var(--line);max-width:760px;margin:0 auto;width:100%}
.search-ov input{flex:1;border:none;background:none;font-size:22px;font-family:var(--serif);font-weight:500}
.search-ov input:focus{outline:none}
.search-ov .body{flex:1;overflow-y:auto;max-width:760px;margin:0 auto;width:100%;padding:22px 18px}
.trend{display:flex;flex-wrap:wrap;gap:9px;margin-top:12px}
.trend .t{background:var(--card);border:1px solid var(--line);border-radius:99px;padding:9px 16px;font-size:13.5px;font-weight:600;cursor:pointer;transition:.16s}
.trend .t:hover{background:var(--ink);color:#fff;border-color:var(--ink)}
.sresult{display:flex;align-items:center;gap:14px;padding:11px 0;border-bottom:1px solid var(--line-2);cursor:pointer}
.sresult img{width:54px;height:54px;border-radius:10px;object-fit:cover;background:var(--cream-2)}
.sresult .n{font-weight:500;font-size:14.5px}
.sresult .b{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--rose)}
.sresult .p{margin-left:auto;font-weight:700}
.lbl-sm{font-size:11.5px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--ink-3)}

/* ============ CHECKOUT ============ */
.checkout{display:grid;grid-template-columns:1fr 400px;gap:36px;padding:26px 0 60px;align-items:start}
@media(max-width:900px){.checkout{grid-template-columns:1fr}}
.cform-sec{background:var(--card);border-radius:var(--r-l);padding:24px;box-shadow:var(--sh-s);margin-bottom:18px}
.cform-hd{display:flex;align-items:center;gap:10px;margin-bottom:18px}
.cform-hd .n{width:28px;height:28px;border-radius:50%;background:var(--ink);color:#fff;display:grid;place-items:center;font-size:13px;font-weight:700}
.cform-hd h3{font-family:var(--serif);font-weight:600;font-size:19px}
.fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.field{display:flex;flex-direction:column;gap:6px}
.field.full{grid-column:1/-1}
.field label{font-size:12.5px;font-weight:600;color:var(--ink-2)}
.field input,.field select{border:1px solid var(--line);border-radius:12px;padding:12px 14px;font-size:14.5px;background:var(--cream);transition:.14s}
.field input:focus,.field select:focus{outline:none;border-color:var(--rose);background:#fff}
.payopt{display:flex;align-items:center;gap:13px;border:1.5px solid var(--line);border-radius:14px;padding:15px;cursor:pointer;margin-bottom:10px;transition:.16s}
.payopt:hover{border-color:var(--rose)}
.payopt.on{border-color:var(--rose-deep);background:var(--blush-2)}
.payopt .radio{width:20px;height:20px;border-radius:50%;border:2px solid var(--line);flex-shrink:0;display:grid;place-items:center;transition:.16s}
.payopt.on .radio{border-color:var(--rose-deep)}
.payopt.on .radio::after{content:"";width:10px;height:10px;border-radius:50%;background:var(--rose-deep)}
.payopt .pi{font-weight:700;font-size:14.5px;display:flex;align-items:center;gap:8px}
.payopt .pd{font-size:12px;color:var(--ink-3);margin-top:2px}
.paylogo{font-size:11px;font-weight:800;padding:3px 8px;border-radius:6px;letter-spacing:.02em}
.pl-card{background:#1A1F71;color:#fff}.pl-tabby{background:#3BD6BC;color:#04302a}
.pl-tamara{background:#1B1145;color:#fff}.pl-cod{background:var(--cream-2);color:var(--ink-2)}
.split{font-size:12px;color:var(--ink-2);background:var(--cream);border-radius:10px;padding:9px 12px;margin-top:8px;display:none}
.payopt.on .split.show{display:block}
.osummary{position:sticky;top:calc(var(--header-h) + 16px);background:var(--card);border-radius:var(--r-l);padding:22px;box-shadow:var(--sh-s)}
.osummary h3{font-family:var(--serif);font-weight:600;font-size:19px;margin-bottom:14px}
.oitem{display:grid;grid-template-columns:48px 1fr auto;gap:11px;padding:9px 0;font-size:13px;align-items:center}
.oitem img{width:48px;height:48px;border-radius:9px;object-fit:cover;background:var(--cream-2)}
.oitem .q{position:relative}
.oitem .qn{position:absolute;top:-6px;right:-6px;background:var(--ink);color:#fff;font-size:10px;font-weight:700;width:18px;height:18px;border-radius:50%;display:grid;place-items:center}

/* success */
.success{text-align:center;padding:70px 20px;max-width:520px;margin:0 auto}
.success .ring{width:84px;height:84px;border-radius:50%;background:var(--good);color:#fff;display:grid;place-items:center;margin:0 auto 22px;font-size:40px;animation:pop .5s var(--ease)}
@keyframes pop{from{transform:scale(0)}to{transform:scale(1)}}
.success h1{font-family:var(--serif);font-weight:600;font-size:30px;margin-bottom:10px}
.success p{color:var(--ink-2);margin-bottom:8px}
.success .ord{font-weight:700;color:var(--ink)}

/* ============ BOTTOM NAV (mobile) ============ */
.bnav{position:fixed;bottom:0;left:0;right:0;z-index:70;background:rgba(251,246,240,.92);
  backdrop-filter:blur(14px);border-top:1px solid var(--line);display:flex;
  padding:6px 4px calc(6px + env(safe-area-inset-bottom));}
@media(min-width:980px){.bnav{display:none}}
.bnav button{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;padding:7px;
  font-size:10.5px;font-weight:600;color:var(--ink-3);position:relative}
.bnav button.on{color:var(--rose-deep)}
.bnav svg{width:22px;height:22px}
.bnav .count{top:0;right:50%;margin-right:-22px}
body{padding-bottom:0}
@media(max-width:979px){body{padding-bottom:64px}}

/* ============ FOOTER ============ */
footer{background:var(--ink);color:#D9CEC4;margin-top:40px;padding:46px 0 30px}
footer .fgrid{display:grid;grid-template-columns:1.5fr 1fr 1fr 1fr;gap:30px}
@media(max-width:760px){footer .fgrid{grid-template-columns:1fr 1fr}}
footer h5{font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#fff;margin-bottom:14px}
footer a{display:block;font-size:13.5px;padding:5px 0;color:#C3B6AB;transition:.14s}
footer a:hover{color:#fff}
footer .fbrand{font-family:var(--serif);font-size:22px;color:#fff;margin-bottom:10px}
footer .fbrand small{font-family:var(--sans);font-size:8.5px;letter-spacing:.34em;color:var(--gold-2);display:block;margin-top:6px;text-transform:uppercase}
footer p{font-size:13px;line-height:1.6;max-width:34ch}
.paystrip{display:flex;gap:7px;flex-wrap:wrap;margin-top:16px}
.fbot{border-top:1px solid rgba(255,255,255,.12);margin-top:34px;padding-top:20px;display:flex;
  justify-content:space-between;gap:14px;flex-wrap:wrap;font-size:12px;color:#9C9088}

/* WhatsApp float */
.wa{position:fixed;right:16px;bottom:78px;z-index:65;width:54px;height:54px;border-radius:50%;
  background:#25D366;display:grid;place-items:center;box-shadow:var(--sh-m);transition:.18s}
@media(min-width:980px){.wa{bottom:22px}}
.wa:hover{transform:scale(1.08)}
.wa svg{width:28px;height:28px;fill:#fff}

/* toast */
.toast{position:fixed;left:50%;bottom:90px;transform:translate(-50%,20px);z-index:130;
  background:var(--ink);color:#fff;padding:13px 20px;border-radius:99px;font-size:13.5px;font-weight:600;
  box-shadow:var(--sh-l);opacity:0;pointer-events:none;transition:.28s var(--ease);display:flex;align-items:center;gap:9px}
.toast.on{opacity:1;transform:translate(-50%,0)}
@media(min-width:980px){.toast{bottom:24px}}

/* skeleton */
.sk{background:linear-gradient(100deg,var(--cream-2) 30%,#fff 50%,var(--cream-2) 70%);
  background-size:200% 100%;animation:shimmer 1.2s infinite;border-radius:8px}
@keyframes shimmer{from{background-position:200% 0}to{background-position:-200% 0}}

@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
.hidden{display:none!important}
</style>
</head>
<body>

<!-- TOP MARQUEE -->
<div class="topbar">
  <div class="marquee" id="marq"></div>
</div>

<!-- HEADER -->
<header class="app">
  <div class="wrap hd">
    <a class="brand" href="#" onclick="go('home');return false">
      <span class="mark"></span>
      <span>K-Beauty Bliss<small>Authentic · UAE</small></span>
    </a>
    <button class="searchpill" onclick="openSearch()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg>
      Search 50+ Korean brands…
    </button>
    <nav class="nav" id="nav"></nav>
    <div class="hd-actions">
      <button class="iconbtn" onclick="openSearch()" aria-label="Search" style="display:none" id="mSearch">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg>
      </button>
      <button class="iconbtn" onclick="openWish()" aria-label="Wishlist">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7.5-4.6-10-9.3C.5 8.5 2 5 5.5 5 7.6 5 9 6.3 12 9c3-2.7 4.4-4 6.5-4C22 5 23.5 8.5 22 11.7 19.5 16.4 12 21 12 21z"/></svg>
        <span class="count" id="wishCount">0</span>
      </button>
      <button class="iconbtn" onclick="openCart()" aria-label="Cart">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12L5 3H2"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/></svg>
        <span class="count" id="cartCount">0</span>
      </button>
    </div>
  </div>
</header>

<div id="app"></div>

<!-- FOOTER -->
<footer>
  <div class="wrap">
    <div class="fgrid">
      <div>
        <div class="fbrand">K-Beauty Bliss<small>Authentic K-Beauty · Dubai</small></div>
        <p>Bringing the best of Korean beauty to skincare lovers across the UAE. 100% authentic, sourced directly from brands. ♡</p>
        <div class="paystrip" id="footPay"></div>
      </div>
      <div>
        <h5>Shop</h5>
        <a href="#" onclick="go('shop',{cat:'Serums'});return false">Serums</a>
        <a href="#" onclick="go('shop',{cat:'Sunscreens'});return false">Sunscreens</a>
        <a href="#" onclick="go('shop',{cat:'Moisturizers'});return false">Moisturizers</a>
        <a href="#" onclick="go('shop',{tag:'set'});return false">Skincare Sets</a>
        <a href="#" onclick="go('shop',{tag:'sale'});return false">Super Sale</a>
      </div>
      <div>
        <h5>Customer Care</h5>
        <a href="#" onclick="toast('Demo page');return false">Shipping & Delivery</a>
        <a href="#" onclick="toast('Demo page');return false">Returns</a>
        <a href="#" onclick="toast('Demo page');return false">Order Tracking</a>
        <a href="#" onclick="toast('Demo page');return false">FAQs</a>
        <a href="#" onclick="toast('Demo page');return false">Contact</a>
      </div>
      <div>
        <h5>Reach Us</h5>
        <a href="#" onclick="return false">+971 58 505 2611</a>
        <a href="#" onclick="return false">info@kbeautybliss.com</a>
        <a href="#" onclick="return false">WhatsApp · 24/7</a>
        <a href="#" onclick="return false">Instagram · TikTok · FB</a>
      </div>
    </div>
    <div class="fbot">
      <span>© 2026 K-Beauty Bliss UAE — standalone app prototype</span>
      <span>🔒 All transactions secure & encrypted</span>
    </div>
  </div>
</footer>

<!-- SCRIM + DRAWERS -->
<div class="scrim" id="scrim" onclick="closeAll()"></div>

<aside class="drawer" id="cartDrawer">
  <div class="dr-hd">
    <h3>Your Bag</h3>
    <button class="x" onclick="closeAll()">✕</button>
  </div>
  <div class="dr-body" id="cartBody"></div>
  <div class="dr-foot" id="cartFoot"></div>
</aside>

<aside class="drawer" id="wishDrawer">
  <div class="dr-hd">
    <h3>Wishlist</h3>
    <button class="x" onclick="closeAll()">✕</button>
  </div>
  <div class="dr-body" id="wishBody"></div>
</aside>

<!-- SEARCH OVERLAY -->
<div class="search-ov" id="searchOv">
  <div class="bar">
    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg>
    <input id="searchInput" placeholder="Search products, brands, concerns…" oninput="renderSearch()">
    <button class="x iconbtn" onclick="closeSearch()">✕</button>
  </div>
  <div class="body" id="searchBody"></div>
</div>

<!-- BOTTOM NAV -->
<nav class="bnav" id="bnav"></nav>

<!-- WHATSAPP -->
<a class="wa" href="#" onclick="toast('Opens WhatsApp chat');return false" aria-label="WhatsApp">
  <svg viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.748-.985zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.247-.694.247-1.289.173-1.413z"/></svg>
</a>

<div class="toast" id="toast"></div>

<script>
/* =====================================================================
   DATA  (real KBB products + brands + categories)
===================================================================== */
const U='https://kbeautybliss.com/wp-content/uploads/';
const FALL='__FALLBACK__';
const PRODUCTS=[
 {id:1,slug:'skin-1004-centella-ampoule',brand:'SKIN 1004',cat:'Serums',name:'Madagascar Centella Asiatica 100 Ampoule 100ml',orig:153,price:113,rating:5,reviews:128,tags:['bestseller','sale'],
  img:U+'2024/10/SKIN-1004-–-Madagascar-Centella-Asiatica-100-Ampoule-100ml-1-400x400.webp',
  gallery:[U+'2024/10/SKIN-1004-–-Madagascar-Centella-Asiatica-100-Ampoule-100ml-1-800x779.webp',U+'2024/10/8809576260601-8.webp',U+'2024/10/8809576260601-10.webp',U+'2024/10/8809576260601-16.webp',U+'2024/10/skin1004-ampoule-serum-centella-ampoule-38409088401654_600x.webp'],
  desc:'Suitable for all skin types, this ampoule repairs damaged skin while regulating moisture and oil balance. Centella Asiatica extract strengthens the skin barrier for healthier-looking skin.',
  ingredients:'Water, Glycerin, Butylene Glycol, Centella Asiatica Extract, 1,2-Hexanediol, Cellulose Gum, Ethylhexylglycerin.',
  how:'After cleansing, apply 2–3 drops on face. Pat gently for better absorption.',size:'100ml'},
 {id:2,slug:'cosrx-snail-kit',brand:'COSRX',cat:'Skincare Sets',name:'All About Snail Kit',orig:160,price:134,rating:4.8,reviews:54,tags:['set','sale'],img:U+'2024/10/71FF6ImjA4L._AC_SX679_-400x400.webp'},
 {id:3,slug:'anua-5-step-set',brand:'ANUA',cat:'Skincare Sets',name:'All in 1 Glow Essentials : 5-Step Skincare Set',orig:815,price:564,rating:4.9,reviews:33,tags:['set','sale','new'],img:U+'2024/10/ANUA-5-Step-Skincare-Routine-Set-400x400.jpg'},
 {id:4,slug:'anua-heartleaf-trial-kit',brand:'ANUA',cat:'Skincare Sets',name:'Heartleaf Soothing Trial Kit',orig:159,price:120,rating:4.7,reviews:41,tags:['set','sale'],img:U+'2024/10/Anua-–-Heartleaf-Soothing-Trial-Kit-1-400x400.webp'},
 {id:5,slug:'medicube-pdrn-glow-set',brand:'Medicube',cat:'Skincare Sets',name:'PDRN Glow Booster Set (Pink Edition)',orig:1399,price:1142,rating:5,reviews:18,tags:['set','sale','bestseller'],img:U+'2025/07/PDRN-Glow-booster-set-400x400.webp'},
 {id:6,slug:'medicube-pdrn-capsule-cream',brand:'Medicube',cat:'Moisturizers',name:'PDRN Pink Collagen Capsule Cream',orig:188,price:126,rating:4.8,reviews:96,tags:['sale','bestseller'],img:U+'2025/05/medicube-PDRN-Pink-Collagen-Capsule-Cream-400x400.webp'},
 {id:7,slug:'boj-glow-serum',brand:'Beauty of Joseon',cat:'Serums',name:'Glow Serum Propolis + Niacinamide – 30ml',orig:140,price:97,rating:5,reviews:212,tags:['sale','bestseller'],img:U+'2024/10/Beauty-Of-Joseon-Glow-Serum-Propolis-Niacinamide-400x400.webp'},
 {id:8,slug:'goodal-vita-c-serum',brand:'Goodal',cat:'Serums',name:'Green Tangerine Vita C Dark Spot Care Serum',orig:140,price:84,rating:4.5,reviews:167,tags:['sale','bestseller'],img:U+'2024/10/Goodal-–-Green-Tangerine-Vita-C-Dark-Spot-Care-Serum-400x400.webp'},
 {id:9,slug:'axis-y-dark-spot-serum',brand:'AXIS-Y',cat:'Serums',name:'Dark Spot Correcting Glow Serum',orig:179,price:90,rating:5,reviews:143,tags:['sale','bestseller'],img:U+'2024/10/AXIS-Y-Dark-Spot-Correcting-Glow-Serum-400x400.webp'},
 {id:10,slug:'anua-quercetinol-foam',brand:'ANUA',cat:'Cleansers',name:'Heartleaf Quercetinol Pore Deep Cleansing Foam – 150ml',orig:160,price:97,rating:4.67,reviews:88,tags:['sale'],img:U+'2024/10/Anua-–-Heartleaf-Quercetinol-Pore-Deep-400x400.webp'},
 {id:11,slug:'cosrx-snail-96-essence',brand:'COSRX',cat:'Serums',name:'Advanced Snail 96 Mucin Power Essence – 100ml',orig:137,price:113,rating:5,reviews:301,tags:['sale','bestseller'],img:U+'2024/10/COSRX-Advanced-Snail-96-Mucin-Power-Essence-–-100ml-400x400.webp'},
 {id:12,slug:'skin-1004-sun-serum',brand:'SKIN 1004',cat:'Sunscreens',name:'Madagascar Centella Hyalu-Cica Water-Fit Sun Serum 50ml',orig:147,price:109,rating:5,reviews:74,tags:['sale','new'],img:U+'2024/10/SKIN-1004-Madagascar-Centella-Hyalu-Cica-Water-Fit-Sun-Serum-50ml-400x400.webp'},
 {id:13,slug:'shiseido-fino-hair-mask',brand:'Shiseido',cat:'Hair Care',name:'Fino Premium Touch Hair Mask',orig:153,price:53,rating:5,reviews:189,tags:['sale','under54','bestseller'],img:U+'2024/10/61mhN18ClsL-400x400.webp'},
 {id:14,slug:'cosrx-snail-92-cream',brand:'COSRX',cat:'Moisturizers',name:'Advanced Snail 92 All In One Cream 100ml',orig:136,price:97,rating:5,reviews:121,tags:['sale'],img:U+'2024/10/COSRX-Advanced-Snail-92-All-In-One-Cream-100ml-400x400.webp'},
 {id:15,slug:'skin-1004-cleansing-oil',brand:'SKIN 1004',cat:'Cleansers',name:'Madagascar Centella Light Cleansing Oil – 200ml',orig:144,price:109,rating:5,reviews:97,tags:['sale'],img:U+'2024/10/SKIN-1004-–-Madagascar-Centella-Light-Cleansing-Oil-–-200ml-400x400.webp'},
 {id:16,slug:'mixsoon-bean-essence',brand:'Mixsoon',cat:'Serums',name:'Bean Essence – 50ml',orig:111,price:57,rating:5,reviews:64,tags:['sale'],img:U+'2024/10/Mixsoon-–-Bean-Essence-–-50ml-1-400x400.webp'},
 {id:17,slug:'round-lab-birch-sunscreen',brand:'ROUND LAB',cat:'Sunscreens',name:'Birch Juice Moisturizing Sunscreen 50ml',orig:150,price:119,rating:5,reviews:156,tags:['sale','bestseller'],img:U+'2024/10/ROUND-LAB-Birch-Juice-Moisturizing-Sunscreen-50ml-400x400.webp'},
 {id:18,slug:'axis-y-eye-serum',brand:'AXIS-Y',cat:'Eye Care',name:'Vegan Collagen Eye Serum',orig:140,price:109,rating:4.6,reviews:38,tags:['sale','new'],img:U+'2024/10/AXIS-Y-Vegan-Collagen-Eye-Serum-400x400.webp'},
 {id:19,slug:'celimax-retinal-shot',brand:'celimax',cat:'Serums',name:'The Vita-A Retinal Shot Tightening Booster',orig:253,price:111,rating:4.8,reviews:52,tags:['sale','new'],img:U+'2025/08/celimax-The-Vita-A-Retinal-Shot-Tightening-Booster-400x400.webp'},
 {id:20,slug:'medicube-txa-cream',brand:'Medicube',cat:'Moisturizers',name:'TXA Niacinamide Capsule Cream',orig:165,price:134,rating:4.7,reviews:29,tags:['sale','new'],img:U+'2025/07/canvas-products-400x400.webp'},
 {id:21,slug:'haruharu-black-rice-toner',brand:'Haruharu Wonder',cat:'Toners',name:'Black Rice Hyaluronic Toner – 150ml',orig:113,price:53,rating:4.9,reviews:110,tags:['sale','under54','bestseller'],img:U+'2024/11/Haruharu-WONDER-–-Black-Rice-Hyaluronic-Toner-–-150ml-400x400.webp'},
 {id:22,slug:'dr-althea-345-cream',brand:'Dr.Althea',cat:'Moisturizers',name:'345 Relief Cream',orig:178,price:123,rating:4.8,reviews:71,tags:['sale'],img:U+'2024/10/Dr.Althea-345-Relief-Cream-400x400.webp'},
 {id:23,slug:'medicube-collagen-set',brand:'Medicube',cat:'Skincare Sets',name:'Collagen Booster Set',orig:1299,price:1142,rating:5,reviews:22,tags:['set','sale'],img:U+'2024/11/Medicube-set-black-400x400.webp'},
 {id:24,slug:'medicube-acne-peeling-shot',brand:'Medicube',cat:'Moisturizers',name:'Red Acne Body Peeling Shot',orig:138,price:116,rating:4.6,reviews:45,tags:['sale','new'],img:U+'2025/09/medicube-Red-Acne-Body-Peeling-Shot-400x400.webp'}
];
const CATS=['Cleansers','Toners','Serums','Moisturizers','Sunscreens','Eye Care','Hair Care','Skincare Sets'];
const BRANDS=[...new Set(PRODUCTS.map(p=>p.brand))];
const TRENDING=['PDRN','Retinol','Dark spots','Centella','Snail Mucin','Vitamin C','Sunscreen','Medicube','Anua','COSRX'];
const SAMPLE_REVIEWS=[
 {n:'Olivia R.',r:5,t:'Skincare savior',body:'Leaves skin nourished, soothed and radiant. Highly recommended — this is a repurchase for me.',help:26},
 {n:'Houda A.',r:5,t:'Effective & affordable',body:'Works amazingly for dry and sensitive skin and helped my fine lines a lot.',help:14},
 {n:'Jenifer L.',r:4,t:'Gentle daily use',body:'Really effective and it doesn’t irritate my skin at all. Will buy again.',help:9}
];

/* =====================================================================
   STATE
===================================================================== */
const state={ route:{view:'home',params:{}}, cart:[], wishlist:new Set(), history:[], coupon:null, pdpTab:'desc', pay:'card' };
const COUPONS={ GLOW30:{type:'pct',val:30,label:'GLOW30 · 30% off'}, KBB10:{type:'pct',val:10,label:'KBB10 · 10% off'} };
const FREE_SHIP=199, FLAT_SHIP=18;

/* =====================================================================
   HELPERS
===================================================================== */
const $=s=>document.querySelector(s);
const money=n=>'AED '+Math.round(n).toLocaleString('en-US');
const pct=p=>Math.round((1-p.price/p.orig)*100);
const byId=id=>PRODUCTS.find(p=>p.id===id);
const enc=u=>u===FALL?'':encodeURI(u);
function stars(r){const f=Math.round(r);return '<span class="s">'+'★'.repeat(f)+'☆'.repeat(5-f)+'</span>'}
function imgTag(src,cls,initial){
  if(!src) return `<div class="fallback">${initial||'♡'}</div>`;
  return `<img src="${enc(src)}" loading="lazy" alt="" onerror="this.style.display='none';this.insertAdjacentHTML('afterend','<div class=&quot;fallback&quot;>${initial||'♡'}</div>')">`;
}
function cartQty(){return state.cart.reduce((a,b)=>a+b.qty,0)}
function cartSub(){return state.cart.reduce((a,b)=>a+byId(b.id).price*b.qty,0)}
function discount(){const s=cartSub();if(!state.coupon)return 0;const c=COUPONS[state.coupon];return c?s*c.val/100:0}
function shipping(){const s=cartSub()-discount();return s>=FREE_SHIP||s<=0?0:FLAT_SHIP}
function grand(){return Math.max(0,cartSub()-discount())+shipping()}

/* =====================================================================
   ROUTER
===================================================================== */
function go(view,params={}){
  state.route={view,params}; closeAll();
  window.scrollTo({top:0,behavior:'instant'});
  render(); syncBottomNav();
}
function render(){
  const a=$('#app'); a.innerHTML='';
  const v=document.createElement('div'); v.className='view';
  if(state.route.view==='home') v.innerHTML=viewHome();
  else if(state.route.view==='shop') v.innerHTML=viewShop(state.route.params);
  else if(state.route.view==='product') v.innerHTML=viewProduct(state.route.params.id);
  else if(state.route.view==='checkout') v.innerHTML=viewCheckout();
  else if(state.route.view==='success') v.innerHTML=viewSuccess(state.route.params);
  a.appendChild(v);
  if(state.route.view==='home') initHero();
}

/* =====================================================================
   PRODUCT CARD
===================================================================== */
function card(p){
  const wished=state.wishlist.has(p.id);
  const inCart=state.cart.find(c=>c.id===p.id);
  return `<article class="pcard">
    <div class="ph" onclick="openProduct(${p.id})">
      <div class="topbadges">
        <span class="badge disc">−${pct(p)}%</span>
        ${p.tags.includes('new')?'<span class="badge new">NEW</span>':''}
      </div>
      <button class="wish ${wished?'on':''}" onclick="event.stopPropagation();toggleWish(${p.id},this)" aria-label="Wishlist">
        <svg viewBox="0 0 24 24" stroke-width="2"><path d="M12 21s-7.5-4.6-10-9.3C.5 8.5 2 5 5.5 5 7.6 5 9 6.3 12 9c3-2.7 4.4-4 6.5-4C22 5 23.5 8.5 22 11.7 19.5 16.4 12 21 12 21z"/></svg>
      </button>
      ${imgTag(p.img,'','♡')}
    </div>
    <div class="pb">
      <div class="pbrand">${p.brand}</div>
      <div class="pname" onclick="openProduct(${p.id})">${p.name}</div>
      <div class="stars">${stars(p.rating)} <span>${p.rating} · ${p.reviews}</span></div>
      <div class="price"><span class="now">${money(p.price)}</span><span class="was">${money(p.orig)}</span></div>
      <button class="add ${inCart?'done':''}" onclick="addToCart(${p.id},1,this)">
        ${inCart?'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg> In bag'
        :'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg> Add to bag'}
      </button>
    </div>
  </article>`;
}
function carousel(list){
  return `<div class="car-wrap">
    <button class="car-btn l" onclick="this.parentNode.querySelector('.car').scrollBy({left:-460,behavior:'smooth'})"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg></button>
    <div class="car no-sb">${list.map(card).join('')}</div>
    <button class="car-btn r" onclick="this.parentNode.querySelector('.car').scrollBy({left:460,behavior:'smooth'})"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></button>
  </div>`;
}

/* =====================================================================
   HOME
===================================================================== */
const HERO=[
 {bg:'linear-gradient(120deg,#FBEBED,#F6DCDF 55%,#EFC9CE)',eye:'Anniversary Event',h:'Glow into the season with 30% off',p:'Use code GLOW30 at checkout on bundles, best-sellers & devices. 1–3 day delivery across the UAE.',cta:{label:'Shop the sale',fn:"go('shop',{tag:'sale'})"},cta2:{label:'Best sellers',fn:"go('shop',{tag:'bestseller'})"}},
 {bg:'linear-gradient(120deg,#F4EAE0,#FBF6F0 50%,#F6DCDF)',eye:'Build Your Routine',h:'Authentic K-Beauty, curated for UAE skin',p:'50+ Korean brands. 100% original, sourced directly. Free delivery over AED 199.',cta:{label:'Start shopping',fn:"go('shop',{})"},cta2:{label:'Shop serums',fn:"go('shop',{cat:'Serums'})"}},
 {bg:'linear-gradient(120deg,#EFE3D4,#FBF1E2 50%,#E7CF92)',eye:'PDRN · Capsule Cream',h:'The Medicube glow, now in stock',p:'Pink collagen, PDRN boosters & Age-R devices loved across Dubai.',cta:{label:'Shop Medicube',fn:"go('shop',{brand:'Medicube'})"},cta2:{label:'New arrivals',fn:"go('shop',{tag:'new'})"}}
];
const TILES=[
 {ic:'🏷️',b:'Super Sale',s:'Up to 65% off',go:"go('shop',{tag:'sale'})"},
 {ic:'⭐',b:'Best Sellers',s:'Most loved',go:"go('shop',{tag:'bestseller'})"},
 {ic:'✨',b:'New Arrivals',s:'Just landed',go:"go('shop',{tag:'new'})"},
 {ic:'💸',b:'Under 54 AED',s:'Little treats',go:"go('shop',{tag:'under54'})"},
 {ic:'🧴',b:'Skincare Sets',s:'Save more',go:"go('shop',{tag:'set'})"},
 {ic:'☀️',b:'Suncare',s:'SPF heroes',go:"go('shop',{cat:'Sunscreens'})"},
 {ic:'🎭',b:'Masks',s:'Glow boost',go:"go('shop',{cat:'Skincare Sets'})"},
 {ic:'🧼',b:'Cleansing',s:'Fresh start',go:"go('shop',{cat:'Cleansers'})"},
 {ic:'💧',b:'Serums',s:'Targeted care',go:"go('shop',{cat:'Serums'})"},
 {ic:'🔋',b:'Devices',s:'Pro at home',go:"go('shop',{brand:'Medicube'})"}
];
const ROUTINE=[['1','🧴','Cleanse','Cleansers'],['2','💦','Tone','Toners'],['3','💧','Serum','Serums'],['4','🌸','Moisturize','Moisturizers'],['5','☀️','Protect','Sunscreens'],['6','👁️','Eye Care','Eye Care']];

function viewHome(){
  const sale=PRODUCTS.filter(p=>p.tags.includes('sale')).slice(0,10);
  const sets=PRODUCTS.filter(p=>p.tags.includes('set'));
  const best=PRODUCTS.filter(p=>p.tags.includes('bestseller'));
  const recommend=[...PRODUCTS].sort(()=>.5-Math.random()).slice(0,10);
  return `
  <div class="wrap">
    <section class="hero" id="hero">
      ${HERO.map((s,i)=>`<div class="hslide ${i===0?'on':''}" data-i="${i}">
        <div class="h-glow" style="background:${s.bg}"></div>
        <div class="eyebrow">${s.eye}</div>
        <h1>${s.h}</h1><p>${s.p}</p>
        <div class="row"><button class="btn rose lg" onclick="${s.cta.fn}">${s.cta.label}</button>
        <button class="btn ghost lg" onclick="${s.cta2.fn}">${s.cta2.label}</button></div>
      </div>`).join('')}
      <div class="hero-dots" id="heroDots">${HERO.map((_,i)=>`<i class="${i===0?'on':''}" onclick="setHero(${i})"></i>`).join('')}</div>
      <div class="hero-pay"><span class="paychip">VISA</span><span class="paychip">Mastercard</span><span class="paychip">Tabby</span><span class="paychip">Tamara</span><span class="paychip">Apple&nbsp;Pay</span><span class="paychip">COD</span></div>
    </section>

    <div class="tiles">
      ${TILES.map(t=>`<div class="tile" style="background:radial-gradient(circle at 50% 18%,#fff,var(--blush-2))" onclick="${t.go}"><div class="ic">${t.ic}</div><b>${t.b}</b><small>${t.s}</small></div>`).join('')}
    </div>
  </div>

  <div class="wrap"><section class="sec">
    <div class="sec-hd"><div><div class="eyebrow">Save together</div><h2>Big Savings Bundles</h2></div>
      <a class="viewall" href="#" onclick="go('shop',{tag:'set'});return false">View all →</a></div>
    ${carousel(sets)}
  </section></div>

  <div class="wrap"><section class="sec">
    <div class="sec-hd"><div><h2>Recommended for you</h2><div class="sub">Handpicked K-Beauty essentials for glowing, healthy skin.</div></div>
      <a class="viewall" href="#" onclick="go('shop',{});return false">Shop all →</a></div>
    ${carousel(recommend)}
  </section></div>

  <div class="wrap"><section class="sec">
    <div class="sec-hd"><div><div class="eyebrow">Step by step</div><h2>Build your routine</h2></div></div>
    <div class="routine">
      ${ROUTINE.map(r=>`<div class="rstep" onclick="go('shop',{cat:'${r[3]}'})"><div class="n">Step ${r[0]}</div><div class="ic">${r[1]}</div><b>${r[2]}</b></div>`).join('')}
    </div>
  </section></div>

  <div class="wrap"><section class="sec">
    <div class="sec-hd"><div><h2>Top brands</h2></div><a class="viewall" href="#" onclick="go('shop',{});return false">All brands →</a></div>
    <div class="brandrow">${BRANDS.map(b=>`<div class="bchip" onclick="go('shop',{brand:'${b}'})">${b}</div>`).join('')}</div>
  </section></div>

  <div class="wrap"><section class="sec">
    <div class="promos">
      <div class="promo" style="background:linear-gradient(120deg,#F6DCDF,#FBEBED)" onclick="go('shop',{cat:'Sunscreens'})">
        <div class="eyebrow">Embrace the sunshine</div><h3>SPF heroes for UAE summers</h3>
        <p>Lightweight, no white cast, dermatologist-loved Korean sunscreens.</p><button class="btn">Shop suncare</button></div>
      <div class="promo" style="background:linear-gradient(120deg,#F4EAE0,#E7CF92)" onclick="go('shop',{tag:'bestseller'})">
        <div class="eyebrow">Top K-Beauty picks</div><h3>Cult favourites & hidden gems</h3>
        <p>The most-loved products our customers can’t stop reordering.</p><button class="btn">Shop best sellers</button></div>
    </div>
  </section></div>

  <div class="wrap"><section class="sec">
    <div class="sec-hd"><div><div class="eyebrow">Flash sale · up to 65% off</div><h2>Best sellers</h2></div>
      <a class="viewall" href="#" onclick="go('shop',{tag:'bestseller'});return false">View all →</a></div>
    ${carousel(best)}
  </section></div>
  `;
}
let heroTimer=null,heroIdx=0;
function initHero(){
  clearInterval(heroTimer);heroIdx=0;
  heroTimer=setInterval(()=>setHero((heroIdx+1)%HERO.length),5200);
}
function setHero(i){
  heroIdx=i;
  document.querySelectorAll('#hero .hslide').forEach(s=>s.classList.toggle('on',+s.dataset.i===i));
  document.querySelectorAll('#heroDots i').forEach((d,di)=>d.classList.toggle('on',di===i));
}

/* =====================================================================
   SHOP
===================================================================== */
function filterProducts(params){
  let list=[...PRODUCTS];
  if(params.cat) list=list.filter(p=>p.cat===params.cat);
  if(params.brand) list=list.filter(p=>p.brand===params.brand);
  if(params.tag) list=list.filter(p=>p.tags.includes(params.tag));
  if(params.q){const q=params.q.toLowerCase();list=list.filter(p=>(p.name+p.brand+p.cat).toLowerCase().includes(q));}
  if(params._brands&&params._brands.length) list=list.filter(p=>params._brands.includes(p.brand));
  if(params._cats&&params._cats.length) list=list.filter(p=>params._cats.includes(p.cat));
  if(params._sale) list=list.filter(p=>p.tags.includes('sale'));
  const s=params._sort;
  if(s==='low') list.sort((a,b)=>a.price-b.price);
  else if(s==='high') list.sort((a,b)=>b.price-a.price);
  else if(s==='disc') list.sort((a,b)=>pct(b)-pct(a));
  else if(s==='rating') list.sort((a,b)=>b.rating-a.rating);
  return list;
}
function shopTitle(p){
  if(p.q) return `“${p.q}”`;
  if(p.brand) return p.brand;
  if(p.cat) return p.cat;
  if(p.tag==='sale') return 'Super Sale';
  if(p.tag==='bestseller') return 'Best Sellers';
  if(p.tag==='new') return 'New Arrivals';
  if(p.tag==='under54') return 'Everything under AED 54';
  if(p.tag==='set') return 'Skincare Sets';
  return 'Shop all';
}
function viewShop(params){
  const list=filterProducts(params);
  const activeChips=[];
  if(params.brand)activeChips.push(['Brand: '+params.brand,"go('shop',{})"]);
  if(params.cat)activeChips.push(['Category: '+params.cat,"go('shop',{})"]);
  if(params.tag)activeChips.push([shopTitle({tag:params.tag}),"go('shop',{})"]);
  return `<div class="wrap">
    <div class="crumb"><a href="#" onclick="go('home');return false">Home</a><span class="sep">›</span><span>Shop</span><span class="sep">›</span>${shopTitle(params)}</div>
    <div class="shop">
      <aside class="filters">
        <div class="fgroup"><h4>Category</h4>
          ${CATS.map(c=>`<label class="fopt"><input type="radio" name="fcat" ${params.cat===c?'checked':''} onchange="go('shop',{cat:'${c}'})">${c}<span class="c">${PRODUCTS.filter(p=>p.cat===c).length}</span></label>`).join('')}
        </div>
        <div class="fgroup"><h4>Brand</h4>
          ${BRANDS.map(b=>`<label class="fopt"><input type="radio" name="fbrand" ${params.brand===b?'checked':''} onchange="go('shop',{brand:'${b}'})">${b}<span class="c">${PRODUCTS.filter(p=>p.brand===b).length}</span></label>`).join('')}
        </div>
        <div class="fgroup"><h4>Offers</h4>
          <label class="fopt"><input type="checkbox" ${params.tag==='sale'?'checked':''} onchange="go('shop',{tag:this.checked?'sale':undefined})">On sale only</label>
          <label class="fopt"><input type="checkbox" ${params.tag==='bestseller'?'checked':''} onchange="go('shop',{tag:this.checked?'bestseller':undefined})">Best sellers</label>
          <label class="fopt"><input type="checkbox" ${params.tag==='new'?'checked':''} onchange="go('shop',{tag:this.checked?'new':undefined})">New arrivals</label>
        </div>
        <div class="fgroup"><button class="btn ghost block" onclick="go('shop',{})">Clear all filters</button></div>
      </aside>
      <div>
        <div class="shop-top">
          <h1>${shopTitle(params)}</h1><span class="cnt">${list.length} products</span>
          <button class="mfilter" onclick="toast('Filters open as a bottom sheet on mobile in production')">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M7 12h10M10 18h4"/></svg>Filter</button>
          <select class="sortsel" onchange="reSort(this.value)">
            <option value="">Sort: Featured</option><option value="low">Price: low to high</option>
            <option value="high">Price: high to low</option><option value="disc">Biggest discount</option>
            <option value="rating">Top rated</option>
          </select>
        </div>
        ${activeChips.length?`<div class="chips">${activeChips.map(c=>`<span class="chip" onclick="${c[1]}">${c[0]} <b>✕</b></span>`).join('')}</div>`:''}
        ${list.length?`<div class="grid">${list.map(card).join('')}</div>`
          :`<div class="empty"><div class="ic">🔍</div><b>No products match</b>Try clearing a filter or browsing all products.<br><br><button class="btn rose" onclick="go('shop',{})">Browse all</button></div>`}
      </div>
    </div>
  </div>`;
}
function reSort(v){const p={...state.route.params,_sort:v};state.route.params=p;render();}

/* =====================================================================
   PRODUCT DETAIL
===================================================================== */
function openProduct(id){pushHistory(id);go('product',{id});}
function viewProduct(id){
  const p=byId(id); if(!p) return '<div class="wrap"><div class="empty">Product not found</div></div>';
  const gal=p.gallery&&p.gallery.length?p.gallery:[p.img];
  const related=PRODUCTS.filter(x=>x.cat===p.cat&&x.id!==p.id).slice(0,8);
  if(related.length<4) PRODUCTS.filter(x=>x.id!==p.id&&!related.includes(x)).slice(0,8-related.length).forEach(x=>related.push(x));
  const wished=state.wishlist.has(p.id);
  const inCart=state.cart.find(c=>c.id===p.id);
  const rb=[5,4,3,2,1].map(st=>{const share=st===Math.round(p.rating)?72:st===5?60:st===4?22:st<3?2:8;return {st,share}});
  return `<div class="wrap">
    <div class="crumb"><a href="#" onclick="go('home');return false">Home</a><span class="sep">›</span>
      <a href="#" onclick="go('shop',{cat:'${p.cat}'});return false">${p.cat}</a><span class="sep">›</span><span>${p.brand}</span></div>
    <div class="pdp">
      <div class="gallery">
        <div class="thumbs" id="thumbs">${gal.map((g,i)=>`<div class="thumb ${i===0?'on':''}" onclick="swapMain(${i})">${imgTag(g,'',p.brand[0])}</div>`).join('')}</div>
        <div class="gmain" id="gmain">${imgTag(gal[0],'',p.brand[0])}</div>
      </div>
      <div class="pinfo">
        <div class="pbrand">${p.brand}</div>
        <h1>${p.name}</h1>
        <div class="rrow">${stars(p.rating)} <b style="color:var(--ink)">${p.rating}</b> <span class="lbl">Excellent</span> · <a href="#reviews" onclick="setTab('rev');return false" style="text-decoration:underline">${p.reviews} reviews</a></div>
        <div class="pprice"><span class="now">${money(p.price)}</span><span class="was">${money(p.orig)}</span><span class="badge disc">−${pct(p)}%</span></div>
        <p class="pdesc">${p.desc||'A K-Beauty favourite, 100% authentic and sourced directly from the brand. Loved by skincare enthusiasts across the UAE.'}</p>
        <div class="deliv"><span class="ic">🚚</span><div><b>Express 1–3 day delivery</b> all over UAE · Free over AED 199</div></div>
        <div class="coderow"><span>🎁</span><div>Extra 30% off — code <b>GLOW30</b> at checkout</div></div>
        <div class="qtyrow">
          <div class="qty"><button onclick="pdpQty(-1)">−</button><span id="pdpQ">1</span><button onclick="pdpQty(1)">+</button></div>
          <button class="btn rose lg" id="pdpAdd" onclick="addFromPdp(${p.id})">${inCart?'Update bag':'Add to bag — '+money(p.price)}</button>
        </div>
        <button class="btn ghost block" onclick="toggleWish(${p.id})" id="pdpWish">${wished?'♥ Saved to wishlist':'♡ Add to wishlist'}</button>
        <div class="trust4">
          <div class="tcell"><div class="ic">🚚</div><b>Fast UAE shipping</b><small>1–3 days</small></div>
          <div class="tcell"><div class="ic">🔒</div><b>Secure payments</b><small>Tabby · Tamara · COD</small></div>
          <div class="tcell"><div class="ic">✅</div><b>100% original</b><small>Direct from Korea</small></div>
          <div class="tcell"><div class="ic">💬</div><b>24/7 support</b><small>WhatsApp</small></div>
        </div>
      </div>
    </div>

    <div class="tabs" id="tabs">
      <button class="tab on" data-t="desc" onclick="setTab('desc')">Description</button>
      <button class="tab" data-t="ing" onclick="setTab('ing')">Ingredients</button>
      <button class="tab" data-t="how" onclick="setTab('how')">How to use</button>
      <button class="tab" data-t="rev" onclick="setTab('rev')" id="revTab">Reviews · ${p.reviews}</button>
    </div>
    <div id="tabContent"></div>

    <section class="sec">
      <div class="sec-hd"><div><div class="eyebrow">You may also like</div><h2>Related products</h2></div></div>
      ${carousel(related)}
    </section>
    ${state.history.length>1?`<section class="sec">
      <div class="sec-hd"><div><div class="eyebrow">Pick up where you left off</div><h2>Recently viewed</h2></div></div>
      ${carousel(state.history.map(byId).filter(Boolean).slice(0,10))}
    </section>`:''}
  </div>`;
}
function tabHTML(p){
  if(state.pdpTab==='desc') return `<div class="tabpane"><p>${p.desc||''}</p>
    <h4>Why you'll love it</h4><ul><li>Repairs & strengthens the skin barrier</li><li>Lightweight, fast-absorbing formula</li><li>Suitable for all skin types</li><li>Made in Korea · 100% authentic</li></ul>
    <h4>Size</h4><p>${p.size||'See product label'}</p></div>`;
  if(state.pdpTab==='ing') return `<div class="tabpane"><h4>Major ingredients</h4><p>${p.ingredients||'Full INCI list available on the product packaging. Sourced and verified directly from the brand.'}</p></div>`;
  if(state.pdpTab==='how') return `<div class="tabpane"><h4>How to use</h4><p>${p.how||'Apply to cleansed skin as part of your routine, morning and/or evening. Patch test recommended for sensitive skin.'}</p></div>`;
  const rb=[5,4,3,2,1].map(st=>({st,share:st===Math.round(p.rating)?70:st===5?58:st===4?20:5}));
  return `<div class="tabpane" style="max-width:820px">
    <div class="rev-sum">
      <div style="text-align:center"><div class="rev-big">${p.rating}</div><div class="stars" style="justify-content:center">${stars(p.rating)}</div><div style="font-size:12px;color:var(--ink-3);margin-top:6px">${p.reviews} reviews</div></div>
      <div class="rev-bars">${rb.map(b=>`<div class="rbar"><span>${b.st}★</span><div class="track"><div class="fill" style="width:${b.share}%"></div></div></div>`).join('')}</div>
    </div>
    <button class="btn ghost" onclick="toast('Review form — name, rating, photos (up to 5)')">✎ Write a review</button>
    <div style="margin-top:18px">${SAMPLE_REVIEWS.map(r=>`<div class="review">
      <div class="top"><div class="av">${r.n[0]}</div><div><b>${r.n}</b> <span class="vbadge">✓ Verified</span><div class="stars" style="font-size:12px">${stars(r.r)}</div></div></div>
      <b style="font-size:14px">${r.t}.</b> <p>${r.body}</p><div class="help">👍 Helpful · ${r.help}</div></div>`).join('')}</div>
  </div>`;
}
function setTab(t){state.pdpTab=t;document.querySelectorAll('#tabs .tab').forEach(b=>b.classList.toggle('on',b.dataset.t===t));$('#tabContent').innerHTML=tabHTML(byId(state.route.params.id));if(t==='rev')$('#tabContent').scrollIntoView({behavior:'smooth',block:'start'});}
let pdpQ=1;
function pdpQty(d){pdpQ=Math.max(1,pdpQ+d);$('#pdpQ').textContent=pdpQ;const p=byId(state.route.params.id);$('#pdpAdd').textContent='Add to bag — '+money(p.price*pdpQ);}
function addFromPdp(id){addToCart(id,pdpQ);openCart();}
function swapMain(i){const p=byId(state.route.params.id);const gal=p.gallery&&p.gallery.length?p.gallery:[p.img];$('#gmain').innerHTML=imgTag(gal[i],'',p.brand[0]);document.querySelectorAll('#thumbs .thumb').forEach((t,ti)=>t.classList.toggle('on',ti===i));}

/* =====================================================================
   CART / WISHLIST / HISTORY ACTIONS
===================================================================== */
function addToCart(id,qty=1,btn){
  const ex=state.cart.find(c=>c.id===id);
  if(ex) ex.qty+=qty; else state.cart.push({id,qty});
  syncCounts(); toast('Added to bag ♡');
  if(btn){btn.classList.add('done');btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg> In bag';}
  renderCart();
}
function changeQty(id,d){const it=state.cart.find(c=>c.id===id);if(!it)return;it.qty+=d;if(it.qty<=0)state.cart=state.cart.filter(c=>c.id!==id);syncCounts();renderCart();}
function removeItem(id){state.cart=state.cart.filter(c=>c.id!==id);syncCounts();renderCart();toast('Removed from bag');}
function toggleWish(id,btn){
  if(state.wishlist.has(id)){state.wishlist.delete(id);toast('Removed from wishlist');}
  else{state.wishlist.add(id);toast('Saved to wishlist ♥');}
  if(btn)btn.classList.toggle('on',state.wishlist.has(id));
  syncCounts();
  if(state.route.view==='product'){const w=$('#pdpWish');if(w)w.textContent=state.wishlist.has(id)?'♥ Saved to wishlist':'♡ Add to wishlist';}
  if($('#wishDrawer').classList.contains('on'))renderWish();
}
function pushHistory(id){state.history=[id,...state.history.filter(x=>x!==id)].slice(0,12);}
function syncCounts(){
  const cq=cartQty(),wq=state.wishlist.size;
  const cc=$('#cartCount'),wc=$('#wishCount');
  cc.textContent=cq;cc.classList.toggle('on',cq>0);
  wc.textContent=wq;wc.classList.toggle('on',wq>0);
  syncBottomNav();
}

/* =====================================================================
   CART DRAWER
===================================================================== */
function renderCart(){
  const body=$('#cartBody'),foot=$('#cartFoot');
  if(!state.cart.length){
    body.innerHTML=`<div class="empty"><div class="ic">🛍️</div><b>Your bag is empty</b>Discover authentic K-Beauty to start your glow.<br><br><button class="btn rose" onclick="closeAll();go('shop',{})">Shop now</button></div>${histStrip()}`;
    foot.innerHTML='';return;
  }
  const sub=cartSub(),need=Math.max(0,FREE_SHIP-(sub-discount())),prog=Math.min(100,(sub-discount())/FREE_SHIP*100);
  body.innerHTML=`
    <div class="ship-meter">
      ${need>0?`<p>You're <b>${money(need)}</b> away from free delivery</p>`:`<p>🎉 You've unlocked <b>free UAE delivery!</b></p>`}
      <div class="meter"><i style="width:${prog}%"></i></div>
    </div>
    ${state.cart.map(it=>{const p=byId(it.id);return `<div class="citem">
      <div class="ci-img" onclick="closeAll();openProduct(${p.id})">${imgTag(p.img,'',p.brand[0])}</div>
      <div><div class="ci-brand">${p.brand}</div><div class="ci-name">${p.name}</div><div class="ci-price">${money(p.price*it.qty)}</div>
        <div class="ci-qty"><button onclick="changeQty(${p.id},-1)">−</button><b>${it.qty}</b><button onclick="changeQty(${p.id},1)">+</button></div></div>
      <button class="rm" onclick="removeItem(${p.id})">Remove</button>
    </div>`}).join('')}
    ${histStrip()}`;
  const c=state.coupon?COUPONS[state.coupon]:null;
  foot.innerHTML=`
    <div class="coupon">
      <input id="couponIn" placeholder="Promo code (try GLOW30)" value="${state.coupon||''}">
      <button onclick="applyCoupon()">${state.coupon?'Applied':'Apply'}</button>
    </div>
    <div class="sumrow"><span>Subtotal</span><span>${money(sub)}</span></div>
    ${c?`<div class="sumrow"><span class="disc">${c.label}</span><span class="disc">−${money(discount())}</span></div>`:''}
    <div class="sumrow"><span>Delivery</span><span>${shipping()===0?'Free':money(FLAT_SHIP)}</span></div>
    <div class="sumrow tot"><span>Total</span><span>${money(grand())}</span></div>
    <button class="btn rose block lg" style="margin-top:14px" onclick="closeAll();go('checkout')">Checkout · ${money(grand())}</button>
    <button class="btn ghost block" style="margin-top:9px" onclick="closeAll();go('shop',{})">Continue shopping</button>`;
}
function histStrip(){
  const items=state.history.map(byId).filter(Boolean);
  if(!items.length) return '';
  return `<div class="hist-strip"><h4>Recently viewed</h4><div class="hist-row no-sb">
    ${items.map(p=>`<div class="hmini" onclick="closeAll();openProduct(${p.id})"><div class="him">${imgTag(p.img,'',p.brand[0])}</div><small>${money(p.price)}</small></div>`).join('')}
  </div></div>`;
}
function applyCoupon(){
  const v=($('#couponIn').value||'').trim().toUpperCase();
  if(!v){state.coupon=null;renderCart();return;}
  if(COUPONS[v]){state.coupon=v;toast('🎉 '+COUPONS[v].label+' applied');}
  else{state.coupon=null;toast('Invalid code — try GLOW30');}
  renderCart();
}

/* =====================================================================
   WISHLIST DRAWER
===================================================================== */
function renderWish(){
  const body=$('#wishBody');
  const items=[...state.wishlist].map(byId).filter(Boolean);
  if(!items.length){body.innerHTML=`<div class="empty"><div class="ic">♡</div><b>No saved items yet</b>Tap the heart on any product to save it here.</div>`;return;}
  body.innerHTML=items.map(p=>`<div class="citem">
    <div class="ci-img" onclick="closeAll();openProduct(${p.id})">${imgTag(p.img,'',p.brand[0])}</div>
    <div><div class="ci-brand">${p.brand}</div><div class="ci-name">${p.name}</div><div class="ci-price">${money(p.price)}</div>
      <button class="btn rose" style="margin-top:8px;padding:8px 16px;font-size:12.5px" onclick="addToCart(${p.id},1)">Add to bag</button></div>
    <button class="rm" onclick="toggleWish(${p.id})">Remove</button>
  </div>`).join('');
}

/* =====================================================================
   CHECKOUT
===================================================================== */
const EMIRATES=['Dubai','Abu Dhabi','Sharjah','Ajman','Ras Al Khaimah','Umm Al Quwain','Fujairah'];
function viewCheckout(){
  if(!state.cart.length) return `<div class="wrap"><div class="empty" style="padding:90px 20px"><div class="ic">🛍️</div><b>Your bag is empty</b>Add a few products to check out.<br><br><button class="btn rose" onclick="go('shop',{})">Shop now</button></div></div>`;
  const sub=cartSub();
  const splitT=(grand()/4);
  return `<div class="wrap">
    <div class="crumb"><a href="#" onclick="go('home');return false">Home</a><span class="sep">›</span><span>Checkout</span></div>
    <div class="checkout">
      <div>
        <div class="cform-sec">
          <div class="cform-hd"><div class="n">1</div><h3>Contact & delivery</h3></div>
          <div class="fields">
            <div class="field full"><label>Full name</label><input placeholder="Your name"></div>
            <div class="field"><label>Email</label><input placeholder="you@email.com" type="email"></div>
            <div class="field"><label>Phone</label><input placeholder="+971 …" type="tel"></div>
            <div class="field"><label>Emirate</label><select>${EMIRATES.map(e=>`<option>${e}</option>`).join('')}</select></div>
            <div class="field"><label>Area / district</label><input placeholder="e.g. Marina"></div>
            <div class="field full"><label>Address</label><input placeholder="Building, street, villa/apartment no."></div>
          </div>
        </div>
        <div class="cform-sec">
          <div class="cform-hd"><div class="n">2</div><h3>Payment</h3></div>
          ${payOpt('card','Card',`<span class="paylogo pl-card">VISA</span><span class="paylogo pl-card">MC</span>`,'Visa, Mastercard & Apple Pay · secured by Stripe','')}
          ${payOpt('tabby','Tabby — pay in 4',`<span class="paylogo pl-tabby">tabby</span>`,'4 interest-free payments. No fees.',`4 × ${money(splitT)} — interest-free`)}
          ${payOpt('tamara','Tamara — split or pay later',`<span class="paylogo pl-tamara">tamara</span>`,'Split in up to 4, or pay in 30 days.',`4 × ${money(splitT)} — Sharia-compliant`)}
          ${payOpt('cod','Cash on delivery',`<span class="paylogo pl-cod">COD</span>`,'Pay cash when your order arrives.','')}
        </div>
      </div>
      <div class="osummary">
        <h3>Order summary</h3>
        ${state.cart.map(it=>{const p=byId(it.id);return `<div class="oitem"><div class="q"><img src="${enc(p.img)}" onerror="this.style.visibility='hidden'"><span class="qn">${it.qty}</span></div><div>${p.brand} — ${p.name}</div><div style="font-weight:700">${money(p.price*it.qty)}</div></div>`}).join('')}
        <div style="height:10px"></div>
        <div class="coupon" style="margin:14px 0">
          <input id="coCoupon" placeholder="Promo code" value="${state.coupon||''}">
          <button onclick="state.coupon=($('#coCoupon').value||'').trim().toUpperCase();render();syncCounts()">Apply</button>
        </div>
        <div class="sumrow"><span>Subtotal</span><span>${money(sub)}</span></div>
        ${state.coupon&&COUPONS[state.coupon]?`<div class="sumrow"><span class="disc">${COUPONS[state.coupon].label}</span><span class="disc">−${money(discount())}</span></div>`:''}
        <div class="sumrow"><span>Delivery</span><span>${shipping()===0?'Free':money(FLAT_SHIP)}</span></div>
        <div class="sumrow tot"><span>Total</span><span>${money(grand())}</span></div>
        <button class="btn rose block lg" style="margin-top:16px" onclick="placeOrder()">Place order · ${money(grand())}</button>
        <p style="font-size:11.5px;color:var(--ink-3);text-align:center;margin-top:10px">🔒 Secure & encrypted · 1–3 day UAE delivery</p>
      </div>
    </div>
  </div>`;
}
function payOpt(id,title,logos,desc,split){
  const on=state.pay===id;
  return `<div class="payopt ${on?'on':''}" onclick="state.pay='${id}';render()">
    <div class="radio"></div>
    <div style="flex:1"><div class="pi">${title} ${logos}</div><div class="pd">${desc}</div>
      ${split?`<div class="split ${on?'show':''}">${split}</div>`:''}</div>
  </div>`;
}
function placeOrder(){
  const total=grand();const ord='KBB'+Math.floor(100000+Math.random()*899999);
  state.cart=[];state.coupon=null;syncCounts();
  go('success',{ord,total,pay:state.pay});
}
function viewSuccess(p){
  const payName={card:'Card (Stripe)',tabby:'Tabby',tamara:'Tamara',cod:'Cash on delivery'}[p.pay]||'Card';
  return `<div class="wrap"><div class="success">
    <div class="ring">✓</div>
    <h1>Order confirmed!</h1>
    <p>Thank you — your K-Beauty order is on its way.</p>
    <p>Order <span class="ord">#${p.ord}</span> · ${money(p.total)} · ${payName}</p>
    <p style="margin-top:6px">📦 Estimated delivery: 1–3 days across the UAE</p>
    <div style="display:flex;gap:12px;justify-content:center;margin-top:24px;flex-wrap:wrap">
      <button class="btn rose" onclick="toast('Order tracking — demo')">Track order</button>
      <button class="btn ghost" onclick="go('home')">Back to home</button>
    </div>
  </div></div>`;
}

/* =====================================================================
   SEARCH
===================================================================== */
function openSearch(){$('#searchOv').classList.add('on');setTimeout(()=>$('#searchInput').focus(),120);renderSearch();}
function closeSearch(){$('#searchOv').classList.remove('on');}
function renderSearch(){
  const q=($('#searchInput')?.value||'').trim();
  const body=$('#searchBody');
  if(!q){
    body.innerHTML=`<div class="lbl-sm">Trending searches</div>
      <div class="trend">${TRENDING.map(t=>`<span class="t" onclick="searchPick('${t}')">${t}</span>`).join('')}</div>
      <div class="lbl-sm" style="margin-top:26px">Popular right now</div>
      <div style="margin-top:10px">${PRODUCTS.filter(p=>p.tags.includes('bestseller')).slice(0,5).map(sRow).join('')}</div>`;
    return;
  }
  const res=filterProducts({q});
  body.innerHTML=res.length?`<div class="lbl-sm">${res.length} results for “${q}”</div><div style="margin-top:10px">${res.map(sRow).join('')}</div>`
    :`<div class="empty"><div class="ic">🔍</div><b>No matches for “${q}”</b>Try a brand or concern like “PDRN” or “dark spots”.</div>`;
}
function sRow(p){return `<div class="sresult" onclick="closeSearch();openProduct(${p.id})">
  <img src="${enc(p.img)}" onerror="this.style.visibility='hidden'"><div><div class="b">${p.brand}</div><div class="n">${p.name}</div></div><div class="p">${money(p.price)}</div></div>`;}
function searchPick(t){$('#searchInput').value=t;renderSearch();}

/* =====================================================================
   DRAWERS / OVERLAYS CONTROL
===================================================================== */
function openCart(){renderCart();$('#scrim').classList.add('on');$('#cartDrawer').classList.add('on');document.body.style.overflow='hidden';}
function openWish(){renderWish();$('#scrim').classList.add('on');$('#wishDrawer').classList.add('on');document.body.style.overflow='hidden';}
function closeAll(){$('#scrim').classList.remove('on');$('#cartDrawer').classList.remove('on');$('#wishDrawer').classList.remove('on');document.body.style.overflow='';}

/* =====================================================================
   TOAST
===================================================================== */
let toastT=null;
function toast(msg){const t=$('#toast');t.innerHTML='<span>✦</span> '+msg;t.classList.add('on');clearTimeout(toastT);toastT=setTimeout(()=>t.classList.remove('on'),2000);}

/* =====================================================================
   NAV + BOTTOM NAV
===================================================================== */
function buildNav(){
  const items=[['Brands',"go('shop',{})"],['Serums',"go('shop',{cat:'Serums'})"],['Sunscreens',"go('shop',{cat:'Sunscreens'})"],['Sets',"go('shop',{tag:'set'})"],['Devices',"go('shop',{brand:'Medicube'})"],['Under 54',"go('shop',{tag:'under54'})"]];
  $('#nav').innerHTML=items.map(i=>`<a href="#" onclick="${i[1]};return false">${i[0]}</a>`).join('')+`<a href="#" class="sale" onclick="go('shop',{tag:'sale'});return false">SUPER SALE</a>`;
  $('#footPay').innerHTML=['VISA','Mastercard','Tabby','Tamara','Apple Pay','COD'].map(x=>`<span class="paychip" style="background:rgba(255,255,255,.1);color:#E7D9CC">${x}</span>`).join('');
}
function syncBottomNav(){
  const v=state.route.view, cq=cartQty();
  const items=[
    ['home','Home','<path d="M3 11 12 3l9 8M5 10v10h14V10"/>',()=>go('home'),false],
    ['shop','Shop','<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/>',()=>go('shop',{}),false],
    ['wish','Wishlist','<path d="M12 21s-7.5-4.6-10-9.3C.5 8.5 2 5 5.5 5 7.6 5 9 6.3 12 9c3-2.7 4.4-4 6.5-4C22 5 23.5 8.5 22 11.7 19.5 16.4 12 21 12 21z"/>',()=>openWish(),false],
    ['cart','Bag','<path d="M6 6h15l-1.5 9h-12L5 3H2"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/>',()=>openCart(),true]
  ];
  $('#bnav').innerHTML=items.map(i=>`<button class="${v===i[0]?'on':''}" onclick="(${i[3]})()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${i[2]}</svg>${i[1]}
    ${i[4]&&cq>0?`<span class="count on" style="position:absolute;top:2px;right:50%;margin-right:-24px">${cq}</span>`:''}</button>`).join('');
}

/* =====================================================================
   BOOT
===================================================================== */
buildNav();render();syncCounts();syncBottomNav();
// reveal mobile search icon
const mq=window.matchMedia('(max-width:739px)');
function chkMobile(){$('#mSearch').style.display=mq.matches?'grid':'none';}
mq.addEventListener('change',chkMobile);chkMobile();
window.go=go;window.openProduct=openProduct;
</script>
</body>
</html>

@endverbatim
