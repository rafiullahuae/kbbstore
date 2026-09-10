@verbatim<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
@endverbatim
{!! $seo ?? '' !!}
@verbatim
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    /* KBB real tokens (never Sorina/Fraunces) */
    --cream:#FFF8F5; --pink-soft:#FFF0F4; --blush:#FCE0E8; --pink:#E0567B; --pink-deep:#C13E63; --pink-ink:#A82F53;
    --ink:#2A2228; --ink-2:#5E545A; --muted:#8C828A; --gold:#BE8E2E; --green:#2E9E6B; --line:rgba(42,34,40,.12); --card:#fff;
    --ease:cubic-bezier(.22,.61,.36,1);
    /* plugin-style review vars (KBB-toned) */
    --sr-accent:var(--pink); --sr-bg:var(--cream); --sr-card:#fff; --sr-ink:var(--ink); --sr-soft:var(--muted);
    --sr-gold:var(--gold); --sr-star-empty:#E7D2C9; --sr-radius:18px; --sr-cols:4;
  }
  *{box-sizing:border-box}
  body{margin:0;background:linear-gradient(180deg,#fff,var(--cream));color:var(--ink);font-family:"Poppins",sans-serif;-webkit-font-smoothing:antialiased}
  .page{max-width:1080px;margin:0 auto;padding:26px 18px 80px}

  /* top capsule (links to #sr) */
  .top-demo{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:14px 0 22px;border-bottom:1px solid var(--line);margin-bottom:24px}
  .td-brand{font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:var(--pink);font-weight:700}
  .td-title{font-size:20px;font-weight:600;margin:2px 0 0}
  .sr-capbar{display:inline-flex;align-items:center;gap:9px;padding:8px 16px 8px 9px;border-radius:30px;text-decoration:none;background:linear-gradient(135deg,#fff,var(--pink-soft));border:1px solid var(--line);box-shadow:0 12px 26px -16px rgba(193,62,99,.4);transition:transform .2s var(--ease);line-height:1;cursor:pointer}
  .sr-capbar:hover{transform:translateY(-2px)}
  .sr-cap-heart{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(150deg,var(--blush),var(--pink));color:#fff;font-size:14px}
  .sr-cap-stars{color:var(--gold);letter-spacing:1px;font-size:14px}
  .sr-cap-avg{font-size:16px;color:var(--ink);font-weight:700}
  .sr-cap-count{font-size:12.5px;color:var(--ink-2);font-weight:600}

  /* ===== review section (.sr) ===== */
  .sr{background:transparent}
  .sr-head{text-align:center;margin-bottom:20px}
  .sr-eyebrow{font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:var(--pink);font-weight:700}
  .sr-title{font-size:26px;font-weight:700;margin:6px 0 0;color:var(--sr-ink)}

  .sr-summary{display:grid;grid-template-columns:auto 1fr;gap:26px;align-items:center;background:var(--sr-card);border:1px solid var(--line);border-radius:var(--sr-radius);padding:20px 24px;margin-bottom:16px}
  @media(max-width:560px){.sr-summary{grid-template-columns:1fr;gap:14px;text-align:center}}
  .sr-score{text-align:center;min-width:120px}
  .sr-avg{font-size:46px;font-weight:800;line-height:1;color:var(--sr-ink)}
  .sr-avg-stars{color:var(--sr-gold);font-size:17px;letter-spacing:2px;margin:6px 0 2px}
  .sr-count{font-size:12.5px;color:var(--sr-soft);font-weight:600}
  .sr-bars{display:flex;flex-direction:column;gap:6px}
  .sr-bar{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--sr-soft)}
  .sr-bar .lab{width:30px;font-weight:600;color:var(--ink-2)}
  .sr-bar .track{flex:1;height:8px;border-radius:30px;background:var(--pink-soft);overflow:hidden}
  .sr-bar .fill{height:100%;border-radius:30px;background:linear-gradient(90deg,var(--blush),var(--pink))}
  .sr-bar .pct{width:34px;text-align:right;font-variant-numeric:tabular-nums}

  .sr-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:8px 0 16px}
  .sr-filters{display:flex;gap:8px;flex-wrap:wrap}
  .sr-chip{border:1px solid var(--line);background:#fff;border-radius:30px;padding:7px 14px;font-family:inherit;font-size:12.5px;font-weight:600;color:var(--ink-2);cursor:pointer;transition:.15s}
  .sr-chip:hover{border-color:var(--pink)}
  .sr-chip.on{background:var(--pink);border-color:var(--pink);color:#fff}
  .sr-write{border:none;background:var(--ink);color:#fff;border-radius:30px;padding:10px 20px;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer}
  .sr-write:hover{background:var(--pink-deep)}

  /* masonry grid */
  .sr-grid{column-count:var(--sr-cols);column-gap:14px}
  @media(max-width:1024px){.sr{--sr-cols:3}}
  @media(max-width:760px){.sr{--sr-cols:2}}
  @media(max-width:430px){.sr{--sr-cols:1}}
  .sr-card{break-inside:avoid;background:var(--sr-card);border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:14px;cursor:pointer;transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
  .sr-card:hover{transform:translateY(-3px);box-shadow:0 22px 40px -28px rgba(42,34,40,.5)}
  .sr-ct{display:flex;align-items:center;gap:10px}
  .sr-av{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:700;font-size:15px;flex:0 0 auto}
  .sr-cmeta{min-width:0}
  .sr-nm{font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
  .sr-verified{font-size:10px;font-weight:700;color:var(--green);border:1px solid var(--green);border-radius:10px;padding:0 6px;line-height:15px}
  .sr-cs{color:var(--sr-gold);font-size:12.5px;letter-spacing:.5px;margin-top:1px}
  .sr-cs .e{color:var(--sr-star-empty)}
  .sr-h{font-size:13.5px;font-weight:700;margin:9px 0 3px;color:var(--sr-ink)}
  .sr-tx{font-size:12.5px;line-height:1.55;color:var(--ink-2);display:-webkit-box;-webkit-line-clamp:5;-webkit-box-orient:vertical;overflow:hidden}
  .sr-pp{margin-top:10px}
  .sr-pp.one .sr-ph-wrap{display:block}
  .sr-pc{font-size:11px;color:var(--muted);font-weight:600;margin-bottom:5px}
  .sr-ph-row{display:flex;gap:5px}
  .sr-ph{flex:1;aspect-ratio:1;border-radius:9px;background-size:cover;background-position:center;position:relative;min-width:0}
  .sr-ph[data-more]:after{content:attr(data-more);position:absolute;inset:0;background:rgba(42,34,40,.55);color:#fff;display:grid;place-items:center;font-size:13px;font-weight:700;border-radius:9px}
  .sr-cf{display:flex;align-items:center;justify-content:space-between;margin-top:11px;font-size:11.5px;color:var(--muted)}
  .sr-help{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--line);border-radius:20px;padding:4px 10px;cursor:pointer;font-weight:600;background:#fff;transition:.15s}
  .sr-help:hover{border-color:var(--pink);color:var(--pink-deep)}
  .sr-help.voted{background:var(--pink-soft);border-color:var(--pink);color:var(--pink-deep)}

  .sr-more-wrap{text-align:center;margin-top:8px}
  .sr-more{border:1px solid var(--pink);background:#fff;color:var(--pink-deep);border-radius:30px;padding:11px 26px;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer}
  .sr-more:hover{background:var(--pink-soft)}

  /* modal + lightbox + sheet */
  .sr-modal,.sr-sheet-wrap,.sr-lb{position:fixed;inset:0;z-index:60;display:none}
  .sr-modal.on,.sr-sheet-wrap.on,.sr-lb.on{display:block}
  .sr-ov{position:absolute;inset:0;background:rgba(42,34,40,.5);backdrop-filter:blur(2px)}
  .sr-mbox{position:relative;max-width:560px;margin:6vh auto;background:#fff;border-radius:20px;padding:24px;max-height:88vh;overflow:auto;box-shadow:0 40px 80px -30px rgba(0,0,0,.6)}
  .sr-mx{position:absolute;top:14px;right:14px;width:34px;height:34px;border:none;border-radius:50%;background:var(--pink-soft);color:var(--pink-deep);font-size:18px;cursor:pointer}
  .sr-mgallery{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;margin-top:14px}
  .sr-mgallery .g{aspect-ratio:1;border-radius:10px;background-size:cover;background-position:center;cursor:pointer}
  .sr-lbimg{position:absolute;inset:0;margin:auto;width:min(86vw,620px);height:min(86vh,620px);border-radius:14px;background-size:cover;background-position:center;box-shadow:0 30px 70px -20px rgba(0,0,0,.7)}
  .sr-lbx{position:absolute;top:20px;right:24px;width:40px;height:40px;border:none;border-radius:50%;background:#fff;color:var(--ink);font-size:20px;cursor:pointer;z-index:2}

  /* write sheet */
  .sr-sheet{position:absolute;left:50%;bottom:0;transform:translateX(-50%) translateY(100%);width:min(560px,100%);background:#fff;border-radius:22px 22px 0 0;padding:22px 22px 28px;max-height:92vh;overflow:auto;transition:transform .35s var(--ease)}
  .sr-sheet-wrap.on .sr-sheet{transform:translateX(-50%) translateY(0)}
  .sr-sheet h3{margin:0 0 3px;font-size:18px}
  .sr-sheet .sub{margin:0 0 16px;font-size:12.5px;color:var(--muted)}
  .sr-pick{display:flex;gap:6px;margin-bottom:14px}
  .sr-pick span{font-size:30px;color:var(--sr-star-empty);cursor:pointer;line-height:1;transition:transform .1s}
  .sr-pick span:hover{transform:scale(1.12)}
  .sr-pick span.on{color:var(--sr-gold)}
  .sr-field{margin-bottom:12px}
  .sr-field label{display:block;font-size:12px;font-weight:700;margin-bottom:5px}
  .sr-field input[type=text],.sr-field input[type=email],.sr-field textarea{width:100%;font-family:inherit;font-size:13px;border:1px solid var(--line);border-radius:10px;padding:10px 12px;color:var(--ink);background:#fff}
  .sr-field textarea{resize:vertical;line-height:1.5}
  .sr-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:480px){.sr-two{grid-template-columns:1fr}}
  .sr-up{border:1.5px dashed var(--line);border-radius:12px;padding:16px;text-align:center;color:var(--muted);font-size:12.5px;cursor:pointer;background:var(--cream)}
  .sr-up:hover{border-color:var(--pink);color:var(--pink-deep)}
  .sr-pv{display:flex;gap:7px;flex-wrap:wrap;margin-top:9px}
  .sr-pvi{position:relative;width:54px;height:54px;border-radius:9px;background-size:cover;background-position:center}
  .sr-pvi button{position:absolute;top:-7px;right:-7px;width:20px;height:20px;border:none;border-radius:50%;background:var(--pink-deep);color:#fff;cursor:pointer;font-size:12px;line-height:1}
  .sr-cap{display:flex;align-items:center;gap:10px;background:var(--pink-soft);border-radius:10px;padding:10px 12px;margin-bottom:12px}
  .sr-cap .q{font-size:13px;font-weight:700;color:var(--pink-ink)}
  .sr-cap input{width:80px;font-family:inherit;font-size:13px;border:1px solid var(--line);border-radius:8px;padding:8px}
  .sr-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}
  .sr-submit{width:100%;border:none;background:var(--pink);color:#fff;border-radius:12px;padding:13px;font-family:inherit;font-weight:700;font-size:14px;cursor:pointer}
  .sr-submit:hover{background:var(--pink-deep)}
  .sr-msg{margin-top:11px;font-size:13px;font-weight:600;text-align:center}
  .sr-msg.ok{color:var(--green)} .sr-msg.bad{color:#E23A4E}
</style>
</head>
<body>
<div class="page">

  <!-- title-area capsule that links to #sr -->
  <div class="top-demo">
    <div>
      <div class="td-brand">Beauty of Joseon</div>
      <div class="td-title">Relief Sun Rice + Probiotics SPF50+</div>
    </div>
    <a class="sr-capbar" href="#sr" id="capjump">
      <span class="sr-cap-heart">&#10084;</span>
      <span class="sr-cap-stars" id="cap-stars">★★★★★</span>
      <span class="sr-cap-avg" id="cap-avg">4.9</span>
      <span class="sr-cap-count" id="cap-count">128 reviews</span>
    </a>
  </div>

  <!-- ===== reviews ===== -->
  <section class="sr" id="sr">
    <div class="sr-head">
      <div class="sr-eyebrow">Loved by you</div>
      <h2 class="sr-title">Customer Reviews</h2>
    </div>

    <div class="sr-summary">
      <div class="sr-score">
        <div class="sr-avg" id="s-avg">4.9</div>
        <div class="sr-avg-stars" id="s-stars">★★★★★</div>
        <div class="sr-count" id="s-count">Based on 128 reviews</div>
      </div>
      <div class="sr-bars" id="s-bars"></div>
    </div>

    <div class="sr-toolbar">
      <div class="sr-filters" id="s-filters">
        <button class="sr-chip on" data-f="all">All</button>
        <button class="sr-chip" data-f="5">5 ★</button>
        <button class="sr-chip" data-f="4">4 ★</button>
        <button class="sr-chip" data-f="photos">With photos</button>
      </div>
      <button class="sr-write" id="s-write">✎ Write a review</button>
    </div>

    <div class="sr-grid" id="s-grid"></div>
    <div class="sr-more-wrap"><button class="sr-more" id="s-more" style="display:none">Load more reviews</button></div>
  </section>
</div>

<!-- detail modal -->
<div class="sr-modal" id="modal">
  <div class="sr-ov" data-close></div>
  <div class="sr-mbox" id="modal-box"></div>
</div>
<!-- lightbox -->
<div class="sr-lb" id="lb">
  <div class="sr-ov" data-close-lb></div>
  <button class="sr-lbx" data-close-lb>×</button>
  <div class="sr-lbimg" id="lb-img"></div>
</div>
<!-- write sheet -->
<div class="sr-sheet-wrap" id="sheet">
  <div class="sr-ov" data-close-sheet></div>
  <div class="sr-sheet">
    <h3>Write a review</h3>
    <p class="sub">Share your experience — your review appears once approved.</p>
    <div class="sr-pick" id="pick">
      <span data-v="1">★</span><span data-v="2">★</span><span data-v="3">★</span><span data-v="4">★</span><span data-v="5">★</span>
    </div>
    <div class="sr-two">
      <div class="sr-field"><label>Name</label><input type="text" id="f-name" placeholder="Your name"></div>
      <div class="sr-field"><label>Email</label><input type="email" id="f-email" placeholder="you@email.com"></div>
    </div>
    <div class="sr-field"><label>Title</label><input type="text" id="f-title" placeholder="Sum it up"></div>
    <div class="sr-field"><label>Your review</label><textarea id="f-content" rows="4" placeholder="What did you love?"></textarea></div>
    <div class="sr-field">
      <label>Photos <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
      <div class="sr-up" id="f-up">📷 Tap to add photos</div>
      <div class="sr-pv" id="f-pv"></div>
    </div>
    <div class="sr-cap">
      <span class="q" id="cap-q">What is 3 + 4?</span>
      <input type="text" id="cap-a" inputmode="numeric" placeholder="?">
    </div>
    <input type="text" class="sr-hp" id="f-hp" tabindex="-1" autocomplete="off" placeholder="Leave blank">
    <button class="sr-submit" id="f-submit">Submit review</button>
    <div class="sr-msg" id="f-msg"></div>
  </div>
</div>

<script>
(function(){
  var INIT=4, STEP=8;
  function $(id){return document.getElementById(id);}
  function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
  var GRAD=['linear-gradient(135deg,#FCE0E8,#E0567B)','linear-gradient(135deg,#FFE9C7,#BE8E2E)','linear-gradient(135deg,#D7F0E2,#2E9E6B)','linear-gradient(135deg,#E5E0FF,#7A6BE0)','linear-gradient(135deg,#FFE0D6,#E2603A)','linear-gradient(135deg,#DDE9FF,#4F86C6)','linear-gradient(135deg,#FDE2F0,#D14F9A)'];
  var AVS=['linear-gradient(150deg,#E0567B,#C13E63)','linear-gradient(150deg,#BE8E2E,#9C7320)','linear-gradient(150deg,#2E9E6B,#1f7d52)','linear-gradient(150deg,#7A6BE0,#5a4fc0)','linear-gradient(150deg,#E2603A,#c44a28)'];
  function ph(i){return GRAD[i%GRAD.length];}
  function av(name){var c=name.charCodeAt(0)%AVS.length;return AVS[c];}

  // seeded reviews
  var REVIEWS=[
    {name:'Aisha M.',v:1,rate:5,date:'2 weeks ago',title:'Holy grail sunscreen',text:'No white cast at all and sits beautifully under makeup. Repurchasing for the third time — my skin has never looked better in the Dubai heat ♡',help:24,ph:2},
    {name:'Fatima K.',v:1,rate:5,date:'3 days ago',title:'So hydrating',text:'My skin drinks this up. Plumper and calmer within a week of using it morning and night.',help:12,ph:1},
    {name:'Maryam',v:0,rate:4,date:'1 month ago',title:'Calms redness',text:'Gentle and soothing. Took one star off only because the bottle is a little small for the price, but the formula is lovely.',help:11,ph:0},
    {name:'Sara A.',v:1,rate:5,date:'2 weeks ago',title:'Lovely all round',text:'The parcel and the little handwritten note made my whole week. Fast UAE delivery too! Product itself is gentle and effective.',help:8,ph:4},
    {name:'Noor',v:0,rate:5,date:'5 days ago',title:'Perfect for sensitive skin',text:'Finally something that does not sting. Lightweight and no shine by midday.',help:5,ph:0},
    {name:'Hessa',v:1,rate:5,date:'3 weeks ago',title:'New everyday SPF',text:'Lightweight, no shine, no eye irritation. This is my new everyday sunscreen, full stop.',help:17,ph:1},
    {name:'Layla',v:0,rate:4,date:'1 week ago',title:'Really good',text:'Does what it says. Absorbs fast and layers well under foundation.',help:3,ph:0},
    {name:'Reem',v:1,rate:5,date:'4 days ago',title:'Glass skin incoming',text:'Two weeks in and my texture is so much smoother. The glow is unreal.',help:9,ph:3},
    {name:'Mariam',v:0,rate:5,date:'2 months ago',title:'Worth the hype',text:'I was skeptical but this genuinely lives up to it. Repurchasing.',help:6,ph:0},
    {name:'Huda',v:1,rate:5,date:'6 days ago',title:'Gentle and effective',text:'No breakouts, no irritation, just happy calm skin. Love it.',help:4,ph:1},
    {name:'Dana',v:0,rate:3,date:'3 weeks ago',title:'Good but pricey',text:'Nice formula and texture, just wish it were a bit more affordable for daily use.',help:2,ph:0},
    {name:'Shaikha',v:1,rate:5,date:'1 week ago',title:'Obsessed',text:'The packaging, the formula, the delivery — everything was premium. Will buy again.',help:7,ph:2}
  ];

  // stats
  function computeStats(){
    var total=REVIEWS.length, sum=0, dist={5:0,4:0,3:0,2:0,1:0};
    REVIEWS.forEach(function(r){sum+=r.rate;dist[r.rate]++;});
    var avg=(sum/total);
    return {total:total, avg:avg, dist:dist};
  }
  function starRow(n,cls){return '★'.repeat(n)+'<span class="'+(cls||'e')+'">'+'★'.repeat(5-n)+'</span>';}

  var st=computeStats();
  $('s-avg').textContent=st.avg.toFixed(1);
  $('s-count').textContent='Based on '+st.total+' reviews';
  $('cap-avg').textContent=st.avg.toFixed(1);
  $('cap-count').textContent=st.total+' reviews';
  // bars
  $('s-bars').innerHTML=[5,4,3,2,1].map(function(n){
    var pct=Math.round((st.dist[n]/st.total)*100);
    return '<div class="sr-bar"><span class="lab">'+n+'★</span><span class="track"><span class="fill" style="width:'+pct+'%"></span></span><span class="pct">'+pct+'%</span></div>';
  }).join('');

  // render grid
  var filter='all', shown=INIT, voted={};
  function filtered(){
    return REVIEWS.filter(function(r){
      if(filter==='all')return true;
      if(filter==='photos')return r.ph>0;
      return r.rate===+filter;
    });
  }
  function cardHTML(r,idx){
    var photos='';
    if(r.ph>0){
      var thumbs=[];
      var show=Math.min(r.ph,4);
      for(var i=0;i<show;i++){
        var more=(i===3 && r.ph>4)?(' data-more="+'+(r.ph-4)+'"'):'';
        thumbs.push('<div class="sr-ph" style="background-image:'+ph(idx+i)+'"'+more+'></div>');
      }
      photos='<div class="sr-pp '+(r.ph===1?'one':'multi')+'"><div class="sr-pc">📷 '+r.ph+' photo'+(r.ph>1?'s':'')+'</div><div class="sr-ph-row">'+thumbs.join('')+'</div></div>';
    }
    return '<div class="sr-card" data-idx="'+idx+'" data-rating="'+r.rate+'" data-photos="'+r.ph+'">'+
      '<div class="sr-ct"><div class="sr-av" style="background:'+av(r.name)+'">'+esc(r.name[0])+'</div>'+
      '<div class="sr-cmeta"><div class="sr-nm">'+esc(r.name)+(r.v?'<span class="sr-verified">✓ Verified</span>':'')+'</div>'+
      '<div class="sr-cs">'+starRow(r.rate)+'</div></div></div>'+
      (r.title?'<div class="sr-h">'+esc(r.title)+'</div>':'')+
      '<div class="sr-tx">'+esc(r.text)+'</div>'+ photos +
      '<div class="sr-cf"><span>'+esc(r.date)+'</span>'+
      '<button class="sr-help'+(voted[idx]?' voted':'')+'" data-help="'+idx+'">👍 <span>'+(r.help+(voted[idx]?1:0))+'</span></button></div>'+
    '</div>';
  }
  function renderGrid(){
    var list=filtered();
    var slice=list.slice(0,shown);
    $('s-grid').innerHTML=slice.map(function(r){return cardHTML(r,REVIEWS.indexOf(r));}).join('');
    $('s-more').style.display = list.length>shown ? '' : 'none';
  }
  renderGrid();

  // filters
  $('s-filters').addEventListener('click',function(e){
    var c=e.target.closest('.sr-chip'); if(!c)return;
    document.querySelectorAll('.sr-chip').forEach(function(x){x.classList.remove('on');});
    c.classList.add('on'); filter=c.getAttribute('data-f'); shown=INIT; renderGrid();
  });
  $('s-more').addEventListener('click',function(){ shown+=STEP; renderGrid(); });

  // helpful + open modal (delegated)
  $('s-grid').addEventListener('click',function(e){
    var hb=e.target.closest('[data-help]');
    if(hb){ e.stopPropagation(); var i=+hb.getAttribute('data-help'); if(voted[i])return; voted[i]=true; renderGrid(); return; }
    var card=e.target.closest('.sr-card'); if(card) openModal(+card.getAttribute('data-idx'));
  });

  // modal
  function openModal(idx){
    var r=REVIEWS[idx];
    var gal='';
    if(r.ph>0){ var g=[]; for(var i=0;i<r.ph;i++){g.push('<div class="g" data-g="'+(idx+i)+'" style="background-image:'+ph(idx+i)+'"></div>');} gal='<div class="sr-mgallery">'+g.join('')+'</div>'; }
    $('modal-box').innerHTML='<button class="sr-mx" data-close>×</button>'+
      '<div class="sr-ct"><div class="sr-av" style="background:'+av(r.name)+'">'+esc(r.name[0])+'</div>'+
      '<div class="sr-cmeta"><div class="sr-nm">'+esc(r.name)+(r.v?'<span class="sr-verified">✓ Verified</span>':'')+'</div>'+
      '<div class="sr-cs">'+starRow(r.rate)+'</div></div></div>'+
      (r.title?'<div class="sr-h" style="font-size:16px;margin-top:14px">'+esc(r.title)+'</div>':'')+
      '<div class="sr-tx" style="-webkit-line-clamp:unset;font-size:13.5px;margin-top:6px">'+esc(r.text)+'</div>'+ gal +
      '<div class="sr-cf" style="margin-top:16px"><span>'+esc(r.date)+'</span><span>👍 '+(r.help+(voted[idx]?1:0))+' found this helpful</span></div>';
    $('modal').classList.add('on');
  }
  $('modal').addEventListener('click',function(e){
    if(e.target.closest('[data-close]')){ $('modal').classList.remove('on'); return; }
    var g=e.target.closest('[data-g]'); if(g){ openLB(g.getAttribute('data-g')); }
  });
  function openLB(i){ $('lb-img').style.backgroundImage=ph(+i); $('lb').classList.add('on'); }
  $('lb').addEventListener('click',function(e){ if(e.target.closest('[data-close-lb]')) $('lb').classList.remove('on'); });

  // ===== write sheet =====
  var pickRate=0;
  function openSheet(){ newCaptcha(); $('sheet').classList.add('on'); }
  function closeSheet(){ $('sheet').classList.remove('on'); }
  $('s-write').addEventListener('click',openSheet);
  $('sheet').addEventListener('click',function(e){ if(e.target.closest('[data-close-sheet]')) closeSheet(); });

  // star picker
  $('pick').addEventListener('click',function(e){ var s=e.target.closest('span[data-v]'); if(!s)return; pickRate=+s.getAttribute('data-v'); paintPick(); });
  $('pick').addEventListener('mousemove',function(e){ var s=e.target.closest('span[data-v]'); if(!s)return; paintPick(+s.getAttribute('data-v')); });
  $('pick').addEventListener('mouseleave',function(){ paintPick(); });
  function paintPick(hover){
    var v=hover||pickRate;
    [].forEach.call($('pick').children,function(s,i){ s.classList.toggle('on', i<v); });
  }

  // photo upload (simulated)
  var pvCount=0;
  $('f-up').addEventListener('click',function(){
    if(pvCount>=5){ return; }
    var idx=Math.floor(Math.random()*GRAD.length); pvCount++;
    var d=document.createElement('div'); d.className='sr-pvi'; d.style.backgroundImage=ph(idx);
    d.innerHTML='<button title="Remove">×</button>'; $('f-pv').appendChild(d);
  });
  $('f-pv').addEventListener('click',function(e){ if(e.target.closest('button')){ e.target.closest('.sr-pvi').remove(); pvCount--; } });

  // captcha
  var capSum=0;
  function newCaptcha(){ var a=1+Math.floor(Math.random()*8), b=1+Math.floor(Math.random()*8); capSum=a+b; $('cap-q').textContent='What is '+a+' + '+b+'?'; $('cap-a').value=''; }

  // submit
  $('f-submit').addEventListener('click',function(){
    var msg=$('f-msg'); msg.className='sr-msg';
    if($('f-hp').value){ msg.classList.add('bad'); msg.textContent='Spam detected.'; return; }      // honeypot
    if(!pickRate){ msg.classList.add('bad'); msg.textContent='Please choose a star rating.'; return; }
    if(!$('f-name').value.trim()){ msg.classList.add('bad'); msg.textContent='Please add your name.'; return; }
    if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test($('f-email').value)){ msg.classList.add('bad'); msg.textContent='Please enter a valid email.'; return; }
    if(!$('f-content').value.trim()){ msg.classList.add('bad'); msg.textContent='Please write your review.'; return; }
    if(parseInt($('cap-a').value,10)!==capSum){ msg.classList.add('bad'); msg.textContent='Captcha answer is incorrect.'; newCaptcha(); return; }
    msg.classList.add('ok'); msg.textContent='Thank you ♡ Your review is in and will appear once approved.';
    setTimeout(function(){ closeSheet(); pickRate=0; paintPick(); $('f-name').value=$('f-email').value=$('f-title').value=$('f-content').value=''; $('f-pv').innerHTML=''; pvCount=0; msg.textContent=''; }, 1800);
  });

  // smooth scroll capsule
  $('capjump').addEventListener('click',function(e){ e.preventDefault(); $('sr').scrollIntoView({behavior:'smooth'}); });
})();
</script>
</body>
</html>

@endverbatim
