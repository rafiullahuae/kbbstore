{{--
    A DESIGN MOCK. This view is rendered by no controller and reachable at no
    URL: its own 60-brand menu links to /category?cat=…, a route this
    application does not register. ShopController serves the real category
    archive (routes/web.php, /product-category/{path}).

    IT ALSO CARRIED A THIRD ANALYTICS LOADER, removed here. A client-side
    block fetched /api/settings and, from the JSON, injected a gtag loader
    from `ga4_id` and a Meta loader from `meta_pixel` — a third GA emitter
    and a second Meta emitter, on top of the two in App\Support\Seo and
    App\Services\MarketingPixels, reading key names that belong to neither.
    It could not in fact have fired: SettingController::PUBLIC_KEYS has never
    published either key, so the fetch returned JSON with neither field in it.
    A loader that is one allowlist entry away from double-counting every page
    view is not worth keeping on a page nobody can load.

    App\Services\Analytics is the only thing that emits an analytics tag in
    this application now. If this mock is ever wired to a route, it gets its
    tags from there like every other page.
--}}
@verbatim<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
@endverbatim
{!! $seo ?? '' !!}
@verbatim
<meta name="description" id="metaDesc" content="Shop Korean beauty at K-Beauty Bliss.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#ffffff;--cream:#FFF8F5;--pink-soft:#FFF0F4;--blush:#FCE0E8;--pink:#E0567B;--pink-deep:#C13E63;--pink-ink:#A82F53;
    --ink:#2A2228;--ink-2:#5E545A;--muted:#8C828A;--line:rgba(42,34,40,.10);--line-2:rgba(42,34,40,.06);
    --sale:#E23A4E;--gold:#BE8E2E;--green:#2E9E6B;--r-s:8px;--r-m:14px;--r-l:20px;
    --sh-m:0 6px 20px rgba(42,34,40,.08);--sh-l:0 16px 40px rgba(42,34,40,.14);
    --sans:'Poppins',system-ui,sans-serif;--ease:cubic-bezier(.22,.61,.36,1)}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:var(--sans);color:var(--ink);background:var(--bg);font-size:14px;line-height:1.5}
  a{color:inherit}
  .wrap{max-width:1240px;margin:0 auto;padding:0 20px}
  /* header */
  .head{position:sticky;top:0;z-index:60;background:rgba(255,255,255,.92);backdrop-filter:blur(12px);border-bottom:1px solid var(--line-2)}
  .head-in{display:flex;align-items:center;gap:18px;height:68px}
  .logo{font-size:20px;font-weight:700;letter-spacing:-.02em;color:var(--ink)}
  .logo span{color:var(--pink)}
  .search-in{flex:1;max-width:520px;display:flex;align-items:center;gap:9px;background:var(--cream);border:1px solid var(--line);border-radius:12px;padding:10px 14px;color:var(--muted)}
  .search-in svg{width:18px;height:18px;flex-shrink:0}
  .search-in input{border:none;outline:none;background:none;flex:1;font-family:inherit;font-size:14px;color:var(--ink)}
  .tools{margin-left:auto;display:flex;gap:8px}
  .tool{position:relative;width:44px;height:44px;border-radius:12px;display:grid;place-items:center;border:none;background:none;color:var(--ink);cursor:pointer}
  .tool:hover{background:var(--pink-soft);color:var(--pink-deep)}
  .tool svg{width:22px;height:22px}
  .tool .ct{position:absolute;top:4px;right:4px;min-width:17px;height:17px;border-radius:99px;background:var(--pink);color:#fff;font-size:10px;font-weight:700;display:grid;place-items:center;padding:0 4px;border:2px solid #fff}
  /* hero */
  .hero{background:linear-gradient(135deg,var(--pink-soft),var(--cream));border-bottom:1px solid var(--line-2)}
  .hero-in{padding:40px 0 34px}
  .crumb{font-size:12.5px;color:var(--muted);margin-bottom:12px}
  .crumb a{text-decoration:none}.crumb a:hover{color:var(--pink-deep)}
  h1{font-size:34px;font-weight:700;letter-spacing:-.02em;margin-bottom:8px}
  .hero p{font-size:14px;color:var(--ink-2);max-width:640px}
  .hero .count{margin-top:12px;font-size:13px;color:var(--muted)}
  .subchips{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}
  .subchip{font-size:13px;font-weight:500;padding:8px 14px;border-radius:99px;background:#fff;border:1px solid var(--line);text-decoration:none;color:var(--ink-2)}
  .subchip:hover,.subchip.on{background:var(--pink);border-color:var(--pink);color:#fff}
  /* toolbar */
  .bar{display:flex;align-items:center;justify-content:space-between;padding:20px 0 6px;gap:12px;flex-wrap:wrap}
  .bar .n{font-size:13px;color:var(--muted)}
  .sortsel{border:1px solid var(--line);border-radius:10px;padding:9px 12px;font-family:inherit;font-size:13px;background:#fff;color:var(--ink);outline:none;cursor:pointer}
  /* grid */
  .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;padding:14px 0 50px}
  .pc{border:1px solid var(--line-2);border-radius:var(--r-l);overflow:hidden;background:#fff;transition:.2s var(--ease);display:flex;flex-direction:column}
  .pc:hover{box-shadow:var(--sh-m);transform:translateY(-2px)}
  .ph{aspect-ratio:1;position:relative;cursor:pointer;background:var(--cream)}
  .lbl{position:absolute;top:10px;font-size:10.5px;font-weight:700;color:#fff;padding:3px 9px;border-radius:99px}
  .lbl.tl{left:10px}.lbl.tr{right:10px}
  .heart{position:absolute;bottom:10px;right:10px;width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.9);border:none;display:grid;place-items:center;cursor:pointer;color:var(--ink-2)}
  .heart svg{width:17px;height:17px}.heart.on{color:var(--pink)}.heart.on svg{fill:var(--pink)}
  .cbody{padding:13px 14px 15px;display:flex;flex-direction:column;flex:1}
  .cbrand{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
  .cname{font-size:13.5px;font-weight:500;margin:3px 0 6px;text-decoration:none;color:var(--ink);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:38px}
  .cname:hover{color:var(--pink-deep)}
  .crate{font-size:12px;color:var(--muted);margin-bottom:6px}.crate .st{color:var(--gold);letter-spacing:1px}
  .cprice{font-size:15px;font-weight:600;color:var(--ink);margin-bottom:11px}
  .cprice s{color:var(--muted);font-weight:400;font-size:13px;margin-left:5px}
  .cprice .off{color:var(--sale);font-weight:600;font-size:12px;margin-left:5px}
  .cprice .from{font-size:11px;font-weight:500;color:var(--muted)}
  .addbtn{margin-top:auto;display:flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--pink);background:#fff;color:var(--pink-deep);font-family:inherit;font-size:13px;font-weight:600;padding:10px;border-radius:11px;cursor:pointer;text-decoration:none}
  .addbtn:hover{background:var(--pink);color:#fff}
  .addbtn svg{width:17px;height:17px}
  .empty{padding:60px 20px;text-align:center;color:var(--muted)}
  /* burger + drawer + nav (upgraded by generator) */
  .burger{display:none;width:44px;height:44px;border-radius:12px;place-items:center;color:var(--ink);background:none;border:none;cursor:pointer}
  .burger:hover{background:var(--pink-soft);color:var(--pink-deep)}
  .burger svg{width:24px;height:24px}
  .nav-links{display:flex;align-items:center;gap:20px}
  .nav-links a{color:var(--ink-2);text-decoration:none;font-size:14px;font-weight:500;white-space:nowrap}
  .nav-links a:hover{color:var(--pink-deep)}
  footer{background:var(--ink);color:#fff;padding:34px 0;margin-top:20px}
  footer .fin{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;font-size:13px;color:rgba(255,255,255,.75)}
  footer a{color:rgba(255,255,255,.75);text-decoration:none}footer a:hover{color:#fff}
  @media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}h1{font-size:26px}}
  @media(max-width:760px){.search-in{display:none}.head-in{gap:8px}.logo{font-size:17px}}

.catbar{position:sticky;top:68px;z-index:59;background:#fff;border-bottom:1px solid var(--line-2)}
.catbar-in{display:flex;align-items:center;gap:0;height:46px}
.cat{position:relative;display:flex;align-items:center;gap:4px;padding:0 11px;height:46px;font-size:13.5px;font-weight:500;color:var(--ink);text-decoration:none;white-space:nowrap;cursor:pointer;transition:.14s var(--ease)}
.cat:hover{color:var(--pink-deep)}
.cat .chev{width:12px;height:12px;transition:.2s var(--ease)}
.cat.has-mega:hover .chev{transform:rotate(180deg)}
.cat.sale{color:var(--sale);font-weight:700}
.cat.sale:hover{color:#b02a3b}
.mega{position:absolute;top:100%;left:0;background:#fff;border:1px solid var(--line);border-top:2px solid var(--pink);border-radius:0 0 14px 14px;box-shadow:var(--sh-l);padding:14px;display:none;z-index:120;animation:mfade .16s var(--ease)}
@keyframes mfade{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.cat.has-mega:hover .mega{display:block}
.mega-title{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.09em;color:var(--muted);padding:2px 10px 8px}
.mega a{display:block;padding:8px 10px;border-radius:8px;font-size:13px;font-weight:400;color:var(--ink-2);text-decoration:none;white-space:nowrap}
.mega a:hover{background:var(--pink-soft);color:var(--pink-deep)}
.mega-skin{min-width:220px}
.mega-all{color:var(--pink-deep)!important;font-weight:600!important;margin-top:4px;border-top:1px solid var(--line-2)}
.mega-brands{right:0;left:auto;grid-template-columns:170px 1fr;gap:12px;width:min(760px,86vw)}
.cat.has-mega:hover .mega-brands{display:grid}
.mega-brands .mcol-trend{border-right:1px solid var(--line-2);padding-right:8px}
.mega-brands .bgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:0 6px;max-height:56vh;overflow:auto}
.mega-brands .bgrid a{font-size:12.5px;padding:6px 8px}
.mnav{position:fixed;top:0;left:0;height:100%;width:300px;max-width:85vw;background:#fff;z-index:110;transform:translateX(-100%);transition:.3s var(--ease);display:flex;flex-direction:column;padding:20px;overflow-y:auto}
.mnav.on{transform:translateX(0)}
.mnav-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.mnav-x{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;font-size:20px;color:var(--ink-2);background:none;border:none;cursor:pointer}
.mnav a{display:block;padding:14px 6px;color:var(--ink);text-decoration:none;font-size:15px;font-weight:500;border-bottom:1px solid var(--line-2)}
.mnav a:hover{color:var(--pink-deep)}
.navov{position:fixed;inset:0;background:rgba(42,34,40,.42);z-index:105;opacity:0;visibility:hidden;transition:.3s var(--ease)}
.navov.on{opacity:1;visibility:visible}
.mnav details summary{list-style:none;cursor:pointer;padding:14px 6px;font-size:15px;font-weight:500;color:var(--ink);border-bottom:1px solid var(--line-2);display:flex;justify-content:space-between;align-items:center}
.mnav details summary::-webkit-details-marker{display:none}
.mnav details summary::after{content:'+';color:var(--muted);font-size:18px}
.mnav details[open] summary::after{content:'–'}
.mnav .msub a{padding:11px 6px 11px 18px;font-size:14px;font-weight:400}
.mnav .msale{color:var(--sale);font-weight:700}
@media(max-width:1024px){.catbar{display:none}.burger{display:grid}}

/* === desktop header redesign v2 (keeps all functionality) === */
.catbar{background:#fff;border-bottom:1px solid var(--line-2)}
.catbar-in{gap:3px;height:52px}
.cat{font-size:13px;font-weight:500;letter-spacing:.015em;padding:0 13px;height:52px;color:var(--ink-2)}
.cat:hover{color:var(--pink-deep)}
.cat::after{content:"";position:absolute;left:13px;right:13px;bottom:9px;height:2px;background:var(--pink);border-radius:2px;transform:scaleX(0);transform-origin:center;transition:transform .22s var(--ease)}
.cat:not(.sale):hover::after{transform:scaleX(1)}
.cat .chev{opacity:.55}
.cat.sale{color:var(--sale);font-weight:600;background:var(--pink-soft);border-radius:99px;height:30px;padding:0 14px;margin:0 6px;align-self:center;letter-spacing:.02em}
.cat.sale:hover{background:var(--sale);color:#fff}
.cat.sale::after{display:none}
.mega{border:1px solid var(--line-2);border-top:2px solid var(--pink);border-radius:0 0 16px 16px;box-shadow:0 22px 48px rgba(42,34,40,.15);padding:18px}
.mega-title{color:var(--pink-deep);letter-spacing:.1em}
.mega a{border-radius:10px}
.mega a:hover{background:var(--pink-soft);color:var(--pink-deep)}
.mega-brands{border-radius:0 0 16px 16px}
</style>
</head>
<body>
<header class="head"><div class="wrap head-in">
  <button class="burger" aria-label="Menu" onclick="document.getElementById('mnav').classList.add('on');document.getElementById('navov').classList.add('on')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
  <a class="logo" href="/" style="text-decoration:none">K-Beauty<span>Bliss</span></a>
  <label class="search-in"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg><input placeholder="Search products, brands…" onkeydown="if(event.key==='Enter'&&this.value.trim()){location.href='/shop?q='+encodeURIComponent(this.value.trim())}"></label>
  <div class="tools">
    <button class="tool"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5C2 12 5 14.5 12 21c7-6.5 7-7 7-7z"/></svg></button>
    <button class="tool"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M6 6 5 3H2"/></svg></button>
  </div>
</div></header>

<nav class="catbar"><div class="wrap catbar-in"><div class="cat has-mega">Skincare <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m6 9 6 6 6-6"/></svg><div class="mega mega-skin"><div class="mega-title">Shop Skincare</div><a href="/category?cat=Cleansing%20Oils">Cleansing Oils</a><a href="/category?cat=Face%20Washes">Face Washes</a><a href="/category?cat=Exfoliators">Exfoliators</a><a href="/category?cat=Toners">Toners</a><a href="/category?cat=Face%20Serums">Face Serums</a><a href="/category?cat=Eye%20Care">Eye Care</a><a href="/category?cat=Face%20Masks">Face Masks</a><a href="/category?cat=Moisturizers">Moisturizers</a><a href="/category?cat=Lip%20Care">Lip Care</a><a href="/category?cat=Sunscreens">Sunscreens</a><a href="/category?cat=Skincare" class="mega-all">View all Skincare →</a></div></div><a class="cat" href="/category?cat=Sunscreens">Sunscreens</a><a class="cat" href="/category?cat=Moisturizers">Moisturizers</a><a class="cat" href="/category?cat=Toners">Toners</a><a class="cat" href="/category?cat=Lip%20Care">Lip Care</a><a class="cat" href="/category?cat=Hair%20Care">Hair Care</a><a class="cat" href="/category?cat=Skincare%20Sets">Skincare Sets</a><a class="cat sale" href="/shop?sale=1">SUPER SALE</a><a class="cat" href="/category?cat=Beauty%20Devices">Beauty Devices</a><a class="cat" href="/shop?maxprice=54">Under 54 AED</a><div class="cat has-mega">Brands <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m6 9 6 6 6-6"/></svg><div class="mega mega-brands"><div class="mcol-trend"><div class="mega-title">Trending</div><a href="/category?brand=Anua">Anua</a><a href="/category?brand=Beauty%20of%20Joseon">Beauty of Joseon</a><a href="/category?brand=COSRX">COSRX</a><a href="/category?brand=Medicube">Medicube</a><a href="/category?brand=SKIN%201004">SKIN 1004</a><a href="/category?brand=LANEIGE">LANEIGE</a><a href="/category?brand=numbuzin">numbuzin</a><a href="/category?brand=Goodal">Goodal</a><a href="/category?brand=Axis-Y">Axis-Y</a><a href="/category?brand=SOME%20BY%20MI">SOME BY MI</a></div><div class="mcol-all"><div class="mega-title">All Brands</div><div class="bgrid"><a href="/category?brand=Abib">Abib</a><a href="/category?brand=Anua">Anua</a><a href="/category?brand=APLB">APLB</a><a href="/category?brand=Aquaphor">Aquaphor</a><a href="/category?brand=Axis-Y">Axis-Y</a><a href="/category?brand=A'PIEU">A'PIEU</a><a href="/category?brand=APRILSKIN">APRILSKIN</a><a href="/category?brand=Arencia">Arencia</a><a href="/category?brand=Aromatica">Aromatica</a><a href="/category?brand=Beauty%20of%20Joseon">Beauty of Joseon</a><a href="/category?brand=Beauty%20Works">Beauty Works</a><a href="/category?brand=B_LAB">B_LAB</a><a href="/category?brand=BANILA%20CO">BANILA CO</a><a href="/category?brand=Benton">Benton</a><a href="/category?brand=BIODANCE">BIODANCE</a><a href="/category?brand=Celimax">Celimax</a><a href="/category?brand=Centellian24">Centellian24</a><a href="/category?brand=COSRX">COSRX</a><a href="/category?brand=d'Alba">d'Alba</a><a href="/category?brand=Dr.Althea">Dr.Althea</a><a href="/category?brand=Dr.Reju-All">Dr.Reju-All</a><a href="/category?brand=Dear%20Klairs">Dear Klairs</a><a href="/category?brand=Dr.%20Ceuracle">Dr. Ceuracle</a><a href="/category?brand=Dr.G">Dr.G</a><a href="/category?brand=Dr.%20Jart%2B">Dr. Jart+</a><a href="/category?brand=Dr.Melaxin">Dr.Melaxin</a><a href="/category?brand=EQQUALBERRY">EQQUALBERRY</a><a href="/category?brand=ETUDE">ETUDE</a><a href="/category?brand=EUNYUL">EUNYUL</a><a href="/category?brand=Goodal">Goodal</a><a href="/category?brand=Haruharu%20Wonder">Haruharu Wonder</a><a href="/category?brand=Heimish">Heimish</a><a href="/category?brand=House%20of%20Hur">House of Hur</a><a href="/category?brand=ilso">ilso</a><a href="/category?brand=illiyoon">illiyoon</a><a href="/category?brand=I'm%20from">I'm from</a><a href="/category?brand=Innisfree">Innisfree</a><a href="/category?brand=Isntree">Isntree</a><a href="/category?brand=iUNIK">iUNIK</a><a href="/category?brand=JUMISO">JUMISO</a><a href="/category?brand=KAINE">KAINE</a><a href="/category?brand=LANEIGE">LANEIGE</a><a href="/category?brand=La%20Roche-Posay">La Roche-Posay</a><a href="/category?brand=Manyo">Manyo</a><a href="/category?brand=Medicube">Medicube</a><a href="/category?brand=Mary%26May">Mary&amp;May</a><a href="/category?brand=Mediheal">Mediheal</a><a href="/category?brand=Missha">Missha</a><a href="/category?brand=mixsoon">mixsoon</a><a href="/category?brand=numbuzin">numbuzin</a><a href="/category?brand=Ongredients">Ongredients</a><a href="/category?brand=Purito%20SEOUL">Purito SEOUL</a><a href="/category?brand=Pyunkang%20Yul">Pyunkang Yul</a><a href="/category?brand=ROUND%20LAB">ROUND LAB</a><a href="/category?brand=SKIN%201004">SKIN 1004</a><a href="/category?brand=Shiseido">Shiseido</a><a href="/category?brand=SKIN%26LAB">SKIN&amp;LAB</a><a href="/category?brand=Skin%20Food">Skin Food</a><a href="/category?brand=SOME%20BY%20MI">SOME BY MI</a><a href="/category?brand=TIA'M">TIA'M</a><a href="/category?brand=TIRTIR">TIRTIR</a><a href="/category?brand=TOCOBO">TOCOBO</a><a href="/category?brand=Torriden">Torriden</a><a href="/category?brand=TOSOWOONG">TOSOWOONG</a><a href="/category?brand=VT%20Cosmetics">VT Cosmetics</a></div><a href="/shop" class="mega-all">View all brands →</a></div></div></div><a class="cat" href="/blog">Blog</a></div></nav>

<!-- mobile nav drawer -->
<div class="navov" id="navov" onclick="this.classList.remove('on');document.getElementById('mnav').classList.remove('on')"></div>
<nav class="mnav" id="mnav">
  <div class="mnav-h"><a class="logo" href="/" style="text-decoration:none;font-size:19px">K-Beauty<span>Bliss</span></a><button class="mnav-x" onclick="document.getElementById('mnav').classList.remove('on');document.getElementById('navov').classList.remove('on')">✕</button></div>
  <a href="/">Home</a>
  <details class="mdrop"><summary>Skincare</summary><div class="msub"><a href="/category?cat=Cleansing%20Oils">Cleansing Oils</a><a href="/category?cat=Face%20Washes">Face Washes</a><a href="/category?cat=Exfoliators">Exfoliators</a><a href="/category?cat=Toners">Toners</a><a href="/category?cat=Face%20Serums">Face Serums</a><a href="/category?cat=Eye%20Care">Eye Care</a><a href="/category?cat=Face%20Masks">Face Masks</a><a href="/category?cat=Moisturizers">Moisturizers</a><a href="/category?cat=Lip%20Care">Lip Care</a><a href="/category?cat=Sunscreens">Sunscreens</a><a href="/category?cat=Skincare"><b>View all Skincare</b></a></div></details><a class="" href="/category?cat=Sunscreens">Sunscreens</a><a class="" href="/category?cat=Moisturizers">Moisturizers</a><a class="" href="/category?cat=Toners">Toners</a><a class="" href="/category?cat=Lip%20Care">Lip Care</a><a class="" href="/category?cat=Hair%20Care">Hair Care</a><a class="" href="/category?cat=Skincare%20Sets">Skincare Sets</a><a class="msale" href="/shop?sale=1">SUPER SALE</a><a class="" href="/category?cat=Beauty%20Devices">Beauty Devices</a><a class="" href="/shop?maxprice=54">Under 54 AED</a><details class="mdrop"><summary>Brands</summary><div class="msub"><a href="/category?brand=Abib">Abib</a><a href="/category?brand=Anua">Anua</a><a href="/category?brand=APLB">APLB</a><a href="/category?brand=Aquaphor">Aquaphor</a><a href="/category?brand=Axis-Y">Axis-Y</a><a href="/category?brand=A'PIEU">A'PIEU</a><a href="/category?brand=APRILSKIN">APRILSKIN</a><a href="/category?brand=Arencia">Arencia</a><a href="/category?brand=Aromatica">Aromatica</a><a href="/category?brand=Beauty%20of%20Joseon">Beauty of Joseon</a><a href="/category?brand=Beauty%20Works">Beauty Works</a><a href="/category?brand=B_LAB">B_LAB</a><a href="/category?brand=BANILA%20CO">BANILA CO</a><a href="/category?brand=Benton">Benton</a><a href="/category?brand=BIODANCE">BIODANCE</a><a href="/category?brand=Celimax">Celimax</a><a href="/category?brand=Centellian24">Centellian24</a><a href="/category?brand=COSRX">COSRX</a><a href="/category?brand=d'Alba">d'Alba</a><a href="/category?brand=Dr.Althea">Dr.Althea</a><a href="/category?brand=Dr.Reju-All">Dr.Reju-All</a><a href="/category?brand=Dear%20Klairs">Dear Klairs</a><a href="/category?brand=Dr.%20Ceuracle">Dr. Ceuracle</a><a href="/category?brand=Dr.G">Dr.G</a><a href="/category?brand=Dr.%20Jart%2B">Dr. Jart+</a><a href="/category?brand=Dr.Melaxin">Dr.Melaxin</a><a href="/category?brand=EQQUALBERRY">EQQUALBERRY</a><a href="/category?brand=ETUDE">ETUDE</a><a href="/category?brand=EUNYUL">EUNYUL</a><a href="/category?brand=Goodal">Goodal</a><a href="/category?brand=Haruharu%20Wonder">Haruharu Wonder</a><a href="/category?brand=Heimish">Heimish</a><a href="/category?brand=House%20of%20Hur">House of Hur</a><a href="/category?brand=ilso">ilso</a><a href="/category?brand=illiyoon">illiyoon</a><a href="/category?brand=I'm%20from">I'm from</a><a href="/category?brand=Innisfree">Innisfree</a><a href="/category?brand=Isntree">Isntree</a><a href="/category?brand=iUNIK">iUNIK</a><a href="/category?brand=JUMISO">JUMISO</a><a href="/category?brand=KAINE">KAINE</a><a href="/category?brand=LANEIGE">LANEIGE</a><a href="/category?brand=La%20Roche-Posay">La Roche-Posay</a><a href="/category?brand=Manyo">Manyo</a><a href="/category?brand=Medicube">Medicube</a><a href="/category?brand=Mary%26May">Mary&amp;May</a><a href="/category?brand=Mediheal">Mediheal</a><a href="/category?brand=Missha">Missha</a><a href="/category?brand=mixsoon">mixsoon</a><a href="/category?brand=numbuzin">numbuzin</a><a href="/category?brand=Ongredients">Ongredients</a><a href="/category?brand=Purito%20SEOUL">Purito SEOUL</a><a href="/category?brand=Pyunkang%20Yul">Pyunkang Yul</a><a href="/category?brand=ROUND%20LAB">ROUND LAB</a><a href="/category?brand=SKIN%201004">SKIN 1004</a><a href="/category?brand=Shiseido">Shiseido</a><a href="/category?brand=SKIN%26LAB">SKIN&amp;LAB</a><a href="/category?brand=Skin%20Food">Skin Food</a><a href="/category?brand=SOME%20BY%20MI">SOME BY MI</a><a href="/category?brand=TIA'M">TIA'M</a><a href="/category?brand=TIRTIR">TIRTIR</a><a href="/category?brand=TOCOBO">TOCOBO</a><a href="/category?brand=Torriden">Torriden</a><a href="/category?brand=TOSOWOONG">TOSOWOONG</a><a href="/category?brand=VT%20Cosmetics">VT Cosmetics</a></div></details><a class="" href="/blog">Blog</a>
</nav>

<section class="hero"><div class="wrap hero-in">
  <div class="crumb" id="crumb"><a href="/">Home</a> · <a href="/shop">Shop</a></div>
  <h1 id="title">Shop</h1>
  <p id="blurb"></p>
  <div class="count" id="count"></div>
  <div class="subchips" id="subchips"></div>
</div></section>

<div class="wrap">
  <div class="bar">
    <div class="n" id="resultN"></div>
    <select class="sortsel" id="sort">
      <option value="popular">Most popular</option>
      <option value="new">Newest</option>
      <option value="price-asc">Price: low to high</option>
      <option value="price-desc">Price: high to low</option>
      <option value="rating">Top rated</option>
    </select>
  </div>
  <div class="grid" id="grid"></div>
  <div class="empty" id="empty" style="display:none">No products here yet — check back soon.</div>
</div>

<footer><div class="wrap fin">
  <div>© K-Beauty Bliss · Authentic Korean beauty in the UAE</div>
  <div><a href="/shop">Shop</a> · <a href="/skin-quiz">Skin Quiz</a> · <a href="/">Home</a></div>
</div></footer>

<script>
  const $=s=>document.querySelector(s);
  const API='';
  const money=n=>'AED '+n;
  const esc=s=>{const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;};
  const slugify=s=>(s||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
  const P=new URLSearchParams(location.search);
  const MODE = P.get('brand') ? 'brand' : 'cat';
  const VALUE = P.get('brand') || P.get('cat') || '';
  let ALL=[], LIST=[], WISH=new Set();

  // fallback demo (offline)
  const SEED=[
    {brand:'Medicube',name:'AGE-R Booster Pro',slug:'medicube',category:'Beauty Devices',price_aed:499,sale_aed:399,rating:4.9,reviews:120,labels:[{t:'★ Bestseller',c:'#BE8E2E',pos:'tl'}],image:'',variants:[],wc_id:100},
    {brand:'MISSHA',name:'M Perfect Cover BB Cream',slug:'missha',category:'Makeup',price_aed:125,sale_aed:null,rating:4.8,reviews:64,labels:[],image:'',variants:[{name:'#21'}],wc_id:200}
  ];

  function labelFor(d){
    if(d.labels&&d.labels[0])return[d.labels[0].t,d.labels[0].c||'#E23A4E',d.labels[0].pos||'tr'];
    if(d.sale_aed!=null&&d.price_aed)return['-'+Math.round((1-d.sale_aed/d.price_aed)*100)+'%','#E23A4E','tr'];
    return null;
  }
  function stars(r){const f=Math.round(r);return '★★★★★'.slice(0,f)+'☆☆☆☆☆'.slice(0,5-f);}

  function card(d){
    const lb=labelFor(d);
    const vr=(d.variants&&d.variants.length)||0;
    const href='/product?slug='+encodeURIComponent(d.slug||slugify(d.name));
    const ph=d.image?`background:#fff url('${d.image}') center/contain no-repeat`:'';
    const rate=(d.reviews>0)?`<div class="crate"><span class="st">${stars(d.rating)}</span> ${d.rating} · ${d.reviews>999?(d.reviews/1000).toFixed(1)+'k':d.reviews}</div>`:'';
    const price=d.sale_aed!=null?`${money(d.sale_aed)} <s>${money(d.price_aed)}</s> <span class="off">-${Math.round((1-d.sale_aed/d.price_aed)*100)}%</span>`:money(d.price_aed);
    const cta=vr?`<a class="addbtn" href="${href}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg> Choose options</a>`
      :`<a class="addbtn" href="${href}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.2"/><circle cx="18" cy="20" r="1.2"/></svg> Add to cart</a>`;
    return `<div class="pc">
      <a class="ph" href="${href}" style="${ph}">${lb?`<span class="lbl ${lb[2]}" style="background:${lb[1]}">${esc(lb[0])}</span>`:''}</a>
      <div class="cbody">
        <div class="cbrand">${esc(d.brand)}</div>
        <a class="cname" href="${href}">${esc(d.name)}</a>
        ${rate}
        <div class="cprice">${vr?'<span class="from">from </span>':''}${price}</div>
        ${cta}
      </div></div>`;
  }

  function applySort(){
    const s=$('#sort').value;
    LIST=[...LIST].sort((a,b)=>{
      if(s==='price-asc')return (a.sale_aed??a.price_aed)-(b.sale_aed??b.price_aed);
      if(s==='price-desc')return (b.sale_aed??b.price_aed)-(a.sale_aed??a.price_aed);
      if(s==='rating')return (b.rating||0)-(a.rating||0);
      if(s==='new')return (b.wc_id||0)-(a.wc_id||0);
      return (b.reviews||0)-(a.reviews||0); // popular
    });
    renderGrid();
  }
  function renderGrid(){
    $('#grid').innerHTML=LIST.map(card).join('');
    $('#empty').style.display=LIST.length?'none':'block';
    $('#resultN').textContent=LIST.length+' product'+(LIST.length===1?'':'s');
  }

  function upsertMeta(attr,key,content){let m=document.head.querySelector(`meta[${attr}="${key}"]`);if(!m){m=document.createElement('meta');m.setAttribute(attr,key);document.head.appendChild(m);}m.setAttribute('content',content);}
  function fillMeta(){
    const isBrand=MODE==='brand';
    const name=VALUE||'Shop';
    const SITE='https://kbeautybliss.com';
    const path=VALUE?((isBrand?'/brand/':'/category/')+encodeURIComponent(VALUE)):'/shop';
    const url=SITE+path;
    document.title=`${name} · K-Beauty Bliss`;
    $('#title').textContent=name;
    $('#crumb').innerHTML=`<a href="/">Home</a> · <a href="/shop">Shop</a> · ${esc(name)}`;
    const blurb=isBrand
      ? `Explore ${esc(name)} at K-Beauty Bliss — authentic, delivered fast across the UAE.`
      : `Shop ${esc(name)} — curated Korean beauty picks, genuine products, fast UAE delivery.`;
    $('#blurb').textContent=blurb;
    const md=document.getElementById('metaDesc'); if(md)md.setAttribute('content',blurb);
    let c=document.head.querySelector('link[rel="canonical"]');if(!c){c=document.createElement('link');c.rel='canonical';document.head.appendChild(c);}c.href=url;
    upsertMeta('property','og:type','website');
    upsertMeta('property','og:title',name+' · K-Beauty Bliss');
    upsertMeta('property','og:description',blurb);
    upsertMeta('property','og:url',url);
    upsertMeta('name','twitter:card','summary');
    applyLD(name,url,isBrand);
  }
  function applyLD(name,url,isBrand){
    const SITE='https://kbeautybliss.com';
    const crumbs={"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[
      {"@type":"ListItem","position":1,"name":"Home","item":SITE+"/"},
      {"@type":"ListItem","position":2,"name":"Shop","item":SITE+"/shop"},
      {"@type":"ListItem","position":3,"name":name,"item":url}]};
    const items={"@context":"https://schema.org","@type":"ItemList","name":name,
      "itemListElement":LIST.slice(0,20).map((p,i)=>({"@type":"ListItem","position":i+1,"name":p.name,"url":SITE+"/product/"+encodeURIComponent(p.slug||slugify(p.name))}))};
    let s=document.getElementById('ld-graph');if(!s){s=document.createElement('script');s.type='application/ld+json';s.id='ld-graph';document.head.appendChild(s);}
    s.textContent=JSON.stringify([crumbs,items]);
  }

  function renderSubchips(){
    if(MODE!=='cat'||!VALUE){$('#subchips').innerHTML='';return;}
    // brands present within this category → quick filters
    const brands=[...new Set(LIST.map(p=>p.brand).filter(Boolean))].slice(0,8);
    if(brands.length<2){$('#subchips').innerHTML='';return;}
    $('#subchips').innerHTML='<a class="subchip on" href="/category?cat='+encodeURIComponent(VALUE)+'">All</a>'+
      brands.map(b=>`<a class="subchip" href="/category?brand=${encodeURIComponent(b)}">${esc(b)}</a>`).join('');
  }

  function build(){
    if(!VALUE){ LIST=ALL.slice(); }
    else if(MODE==='brand'){ LIST=ALL.filter(p=>(p.brand||'').toLowerCase()===VALUE.toLowerCase()); }
    else { LIST=ALL.filter(p=>(p.category||'').toLowerCase()===VALUE.toLowerCase()); }
    fillMeta();
    $('#count').textContent = LIST.length ? `${LIST.length} product${LIST.length===1?'':'s'} available` : '';
    renderSubchips();
    applySort();
  }

  (async()=>{
    try{
      const r=await fetch(API+'/api/products');
      if(r.ok){const d=await r.json(); if(Array.isArray(d)&&d.length){ALL=d;build();return;}}
      throw 0;
    }catch(e){ ALL=SEED; build(); }
  })();
  $('#sort').addEventListener('change',applySort);
</script>


</body>
</html>

@endverbatim
