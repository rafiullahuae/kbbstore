@verbatim<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
@endverbatim
{!! $seo ?? '' !!}
@verbatim
<meta name="description" id="metaDesc" content="Skincare tips, ingredient guides and the K-beauty edit — from K-Beauty Bliss.">
<link rel="canonical" href="https://kbeautybliss.com/blog">
<meta property="og:type" content="website">
<meta property="og:title" content="The Glow Journal · K-Beauty Bliss">
<meta property="og:description" content="Skincare tips, ingredient guides and the K-beauty edit.">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#fff;--cream:#FFF8F5;--pink-soft:#FFF0F4;--blush:#FCE0E8;--pink:#E0567B;--pink-deep:#C13E63;
    --ink:#2A2228;--ink-2:#5E545A;--muted:#8C828A;--line:rgba(42,34,40,.10);--line-2:rgba(42,34,40,.06);
    --r-m:14px;--r-l:20px;--sh-m:0 6px 20px rgba(42,34,40,.08);--sans:'Poppins',system-ui,sans-serif;--ease:cubic-bezier(.22,.61,.36,1)}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:var(--sans);color:var(--ink);background:var(--bg);font-size:14px;line-height:1.5}
  a{color:inherit}
  .wrap{max-width:1160px;margin:0 auto;padding:0 20px}
  .head{position:sticky;top:0;z-index:60;background:rgba(255,255,255,.92);backdrop-filter:blur(12px);border-bottom:1px solid var(--line-2)}
  .head-in{display:flex;align-items:center;gap:18px;height:68px}
  .logo{font-size:20px;font-weight:700;letter-spacing:-.02em;text-decoration:none}.logo span{color:var(--pink)}
  .nav-links{display:flex;gap:20px;margin-left:6px}
  .nav-links a{color:var(--ink-2);text-decoration:none;font-size:14px;font-weight:500}
  .nav-links a:hover,.nav-links a.on{color:var(--pink-deep)}
  .tools{margin-left:auto;display:flex;gap:8px}
  .tool{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;border:none;background:none;color:var(--ink);cursor:pointer;text-decoration:none}
  .tool:hover{background:var(--pink-soft);color:var(--pink-deep)}.tool svg{width:22px;height:22px}
  .burger{display:none;width:44px;height:44px;border-radius:12px;place-items:center;color:var(--ink);background:none;border:none;cursor:pointer}
  .burger svg{width:24px;height:24px}
  .hero{background:linear-gradient(135deg,var(--pink-soft),var(--cream));border-bottom:1px solid var(--line-2)}
  .hero-in{padding:46px 0 38px;text-align:center}
  .hero .ey{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.14em;color:var(--pink-deep)}
  h1{font-size:38px;font-weight:700;letter-spacing:-.02em;margin:8px 0 6px}
  .hero p{color:var(--ink-2);max-width:560px;margin:0 auto}
  .chips{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin:22px 0 4px}
  .chip{font-size:13px;font-weight:500;padding:8px 15px;border-radius:99px;background:#fff;border:1px solid var(--line);cursor:pointer;color:var(--ink-2)}
  .chip:hover,.chip.on{background:var(--pink);border-color:var(--pink);color:#fff}
  .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;padding:34px 0 56px}
  .post{border:1px solid var(--line-2);border-radius:var(--r-l);overflow:hidden;text-decoration:none;color:var(--ink);transition:.2s var(--ease);display:flex;flex-direction:column;background:#fff}
  .post:hover{box-shadow:var(--sh-m);transform:translateY(-3px)}
  .cover{aspect-ratio:16/10;background:var(--cream);position:relative;display:grid;place-items:center;font-size:40px}
  .cover .ptag{position:absolute;top:12px;left:12px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:#fff;color:var(--pink-deep);padding:4px 10px;border-radius:99px}
  .pbody{padding:18px 18px 20px;display:flex;flex-direction:column;flex:1}
  .pdate{font-size:11.5px;color:var(--muted);margin-bottom:7px}
  .ptitle{font-size:17px;font-weight:600;line-height:1.32;margin-bottom:8px}
  .pex{font-size:13px;color:var(--ink-2);line-height:1.5;flex:1}
  .pmore{margin-top:12px;font-size:13px;font-weight:600;color:var(--pink-deep)}
  .empty{padding:60px;text-align:center;color:var(--muted);grid-column:1/-1}
  footer{background:var(--ink);color:#fff;padding:34px 0;margin-top:10px}
  footer .fin{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;font-size:13px;color:rgba(255,255,255,.75)}
  footer a{color:rgba(255,255,255,.75);text-decoration:none}footer a:hover{color:#fff}
  .navov{position:fixed;inset:0;background:rgba(42,34,40,.42);z-index:105;opacity:0;visibility:hidden;transition:.3s}
  .navov.on{opacity:1;visibility:visible}
  .mnav{position:fixed;top:0;left:0;height:100%;width:290px;max-width:85vw;background:#fff;z-index:110;transform:translateX(-100%);transition:.3s var(--ease);padding:20px;display:flex;flex-direction:column}
  .mnav.on{transform:none}
  .mnav a{padding:14px 6px;text-decoration:none;font-weight:500;border-bottom:1px solid var(--line-2)}
  .mnav-x{align-self:flex-end;font-size:20px;background:none;border:none;color:var(--ink-2);cursor:pointer;margin-bottom:6px}
  @media(max-width:900px){.grid{grid-template-columns:1fr}h1{font-size:29px}.nav-links{display:none}.burger{display:grid}}
</style>
</head>
<body>
<header class="head"><div class="wrap head-in">
  <button class="burger" onclick="document.getElementById('mnav').classList.add('on');document.getElementById('navov').classList.add('on')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
  <a class="logo" href="/">K-Beauty<span>Bliss</span></a>
  <nav class="nav-links">
    <a href="/shop">Shop</a>
    <a href="/skin-quiz">Skin Quiz</a>
    <a href="/blog" class="on">Journal</a>
  </nav>
  <div class="tools">
    <a class="tool" href="/shop"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg></a>
    <a class="tool" href="/shop"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M6 6 5 3H2"/></svg></a>
  </div>
</div></header>

<div class="navov" id="navov" onclick="this.classList.remove('on');document.getElementById('mnav').classList.remove('on')"></div>
<nav class="mnav" id="mnav">
  <button class="mnav-x" onclick="document.getElementById('mnav').classList.remove('on');document.getElementById('navov').classList.remove('on')">✕</button>
  <a href="/">Home</a><a href="/shop">Shop</a><a href="/skin-quiz">Skin Quiz</a><a href="/blog">Journal</a>
</nav>

<section class="hero"><div class="wrap hero-in">
  <div class="ey">The Glow Journal</div>
  <h1>Skincare tips &amp; the K-beauty edit</h1>
  <p>Honest guides on routines, ingredients and sun care — written for the UAE.</p>
  <div class="chips" id="chips"></div>
</div></section>

<div class="wrap"><div class="grid" id="grid"></div></div>

<footer><div class="wrap fin">
  <div>© K-Beauty Bliss · Authentic Korean beauty in the UAE</div>
  <div><a href="/shop">Shop</a> · <a href="/skin-quiz">Skin Quiz</a> · <a href="/">Home</a></div>
</div></footer>

<script>
  const $=s=>document.querySelector(s);
  const API='';
  const esc=s=>{const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;};
  const fmt=iso=>{const d=new Date(iso);return isNaN(d)?'':d.toLocaleDateString('en-GB',{day:'numeric',month:'long',year:'numeric'});};
  let POSTS=[], tag='All';
  const SEED=[
    {slug:'ten-step-routine-simplified-uae-heat',title:'The 10-step routine, simplified for UAE heat',tag:'Routine',cover:'linear-gradient(135deg,#FFF0F4,#FCE0E8)',excerpt:'You don’t need ten bottles to glow in Dubai humidity. Here’s the lightweight version that actually works.',created_at:'2026-06-29'},
    {slug:'centella-vs-cica-what-calms-skin',title:'Centella vs. Cica: what actually calms skin',tag:'Ingredients',cover:'linear-gradient(135deg,#e6f7ef,#cdeede)',excerpt:'They sound different but come from the same plant. Here’s what soothes redness.',created_at:'2026-06-24'},
    {slug:'why-korean-sunscreens-win-daily-wear',title:'Why Korean sunscreens win for daily wear',tag:'SPF',cover:'linear-gradient(135deg,#ece7ff,#d7ccff)',excerpt:'No white cast, no greasy film, and a finish you’ll actually want to reapply.',created_at:'2026-06-19'}
  ];
  const EMO={Routine:'✍️',Ingredients:'🌿',SPF:'☀️',News:'📰'};
  function coverStyle(c){return /gradient|#|url/.test(c||'')?`background:${c}`:`background:linear-gradient(135deg,#FFF0F4,#FCE0E8)`;}
  function render(){
    const tags=['All',...[...new Set(POSTS.map(p=>p.tag).filter(Boolean))]];
    $('#chips').innerHTML=tags.map(t=>`<button class="chip${t===tag?' on':''}" onclick="setTag('${esc(t)}')">${esc(t)}</button>`).join('');
    const list=tag==='All'?POSTS:POSTS.filter(p=>p.tag===tag);
    $('#grid').innerHTML=list.length?list.map(p=>`
      <a class="post" href="/post?slug=${encodeURIComponent(p.slug)}">
        <div class="cover" style="${coverStyle(p.cover)}">${/url\(|http/.test(p.cover||'')?'':(EMO[p.tag]||'✨')}${p.tag?`<span class="ptag">${esc(p.tag)}</span>`:''}</div>
        <div class="pbody">
          <div class="pdate">${fmt(p.created_at)}</div>
          <div class="ptitle">${esc(p.title)}</div>
          <div class="pex">${esc(p.excerpt||'')}</div>
          <div class="pmore">Read more →</div>
        </div>
      </a>`).join(''):'<div class="empty">No articles yet — check back soon.</div>';
  }
  function setTag(t){tag=t;render();}
  window.setTag=setTag;
  (async()=>{
    try{const r=await fetch(API+'/api/posts');if(r.ok){const d=await r.json();if(Array.isArray(d)&&d.length){POSTS=d;render();return;}}throw 0;}
    catch(e){POSTS=SEED;render();}
  })();
</script>
</body>
</html>

@endverbatim
