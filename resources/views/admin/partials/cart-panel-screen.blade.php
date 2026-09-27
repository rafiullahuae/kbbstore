{{--
    Appearance → Cart panel. (Lane: cart-panel)

    ── THIS COMMIT IS A MOVE AND NOTHING ELSE ───────────────────────────────

    renderCartPanel() and the `cpp-` style block below were lines inside
    resources/views/admin/app.blade.php. They are here, byte for byte, with only
    the four changes a move cannot avoid — listed at the bottom of this comment.
    The screen draws the same DOM, from the same endpoint, with the same classes,
    and no setting default moves. Shipped on its own so the diff reads as a move
    rather than as a rewrite hiding inside one.

    ── WHY IT MOVED ─────────────────────────────────────────────────────────

    The reason cart-page-screen.blade.php and checkout-page-screen.blade.php give:
    three lanes edit app.blade.php at once, and a 22,000-line Blade is where
    their conflicts happen. The cost of moving out is the cost those two pay —
    this file cannot reach app.blade.php's module-scoped constants — so it
    appends its own sidebar row through window.kbbAddNavEntry and WRAPS
    window.go rather than being named in the console's dispatcher.

    Pulled in at the very end of app.blade.php, after that file closes its raw
    block, so window.go, window.kbbAddNavEntry and toast() already exist by the
    time this runs. THE INCLUDE IS THE INTEGRATOR'S LINE, not this lane's —
    CLAUDE.md gives that file to him and this lane does not write it.

    ── WHAT HAPPENS TO THE COPY STILL IN app.blade.php ──────────────────────

    The wrapper below handles 'cartpanel' and returns WITHOUT calling the
    handler it replaced, so app.blade.php's renderCartPanel becomes unreachable
    the moment this file is included: dead code, for the integrator to delete in
    the same commit that adds the include. Leaving it there cannot draw a second
    screen. Nor can the console's built-in sidebar row become two — kbbAddNavEntry
    is keyed on the screen id and returns the row that is already there.

    ── THE FOUR CHANGES A MOVE CANNOT AVOID ─────────────────────────────────

    1. Everything is inside one IIFE, so `CP`, `CPTAB` and the cp* functions
       stop being console globals. Nothing outside this file called them.
    2. The retry button's onclick was `renderCartPanel()`, which needs a global.
       The function is published as window.kbbCartPanelScreen for that one
       attribute and nothing else reads it.
    3. addNavEntry() and the window.go wrapper are new, and are what replace the
       console's `cartpanel:renderCartPanel` dispatcher entry.
    4. The go wrapper writes #crumb and #ptitle itself, with the same two words
       app.blade.php's TITLES map holds for this screen: Appearance, Cart panel.

    Everything else — the `cpp-` rules, cpField, cpPreview, paintCartPanel,
    bindCartPanel, the fetch paths, the markup — is the original text.

    ── NO RAW-BLOCK DIRECTIVE MAY BE NAMED BELOW ────────────────────────────

    Not in the code and not in prose either. Blade pairs the first such opening
    directive it finds anywhere in the file — inside a comment included — with
    the next closing one, so writing the word swallows everything between them
    and serves this docblock to the browser as visible text.
--}}
@verbatim
<style>
/* Appearance · Cart panel preview. cpp- prefix; nothing else in this file uses it. */
.cpp{background:#fff;border:1px solid #e6e9ef;border-radius:12px;overflow:hidden;margin:0 auto;
     display:flex;flex-direction:column;max-width:100%}
.cpp-tabs{display:flex;align-items:center;gap:4px;padding:0 6px;border-bottom:1px solid #eef1f6}
.cpp-tabs b{flex:1;text-align:center;font-size:11px;font-weight:800;padding:9px 4px;border-bottom:2px solid}
.cpp-tabs i{flex:1;text-align:center;font-size:11px;font-weight:700;color:#9aa3b0;font-style:normal;padding:9px 4px}
.cpp-tabs u{color:#9aa3b0;text-decoration:none;font-size:11px;padding:0 4px}
.cpp-ship{padding:8px var(--pad);border-bottom:1px solid #eef1f6;font-size:10.5px;color:#5c6675}
.cpp-bar{height:5px;border-radius:5px;background:#f0e2e8;overflow:hidden;margin-top:5px}
.cpp-bar div{height:100%;width:100%}
.cpp-body{padding:6px var(--pad);max-height:230px;overflow:auto}
.cpp-item{display:flex;gap:8px;align-items:center;padding:var(--rowpad) 0;border-bottom:1px solid #f2f4f8}
.cpp-item:last-child{border-bottom:0}
.cpp-th{width:var(--thumb);height:var(--thumb);border-radius:8px;flex:none;display:grid;place-items:center;
        color:#fff;font-weight:700;font-size:9px}
.cpp-mid{flex:1;min-width:0}
.cpp-nm{font-size:var(--nm);font-weight:600;line-height:1.25;margin-bottom:4px;
        display:-webkit-box;-webkit-line-clamp:var(--lines);-webkit-box-orient:vertical;overflow:hidden}
.cpp-qty{display:inline-flex;align-items:center;border:1px solid #e6e9ef;border-radius:7px;overflow:hidden}
.cpp-qty span,.cpp-qty b{width:var(--step);height:var(--step);display:grid;place-items:center;font-size:10.5px;font-weight:600}
.cpp-right{text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex:none}
.cpp-rm{color:#c3b3bb;font-size:11px}
.cpp-pr{font-size:11px;font-weight:800;color:var(--acc)}
.cpp-promo{padding:7px var(--pad);background:#fff0f4;font-size:10px;color:#5e545a}
.cpp-foot{border-top:1px solid #eef1f6;padding:10px var(--pad)}
.cpp-sum{display:flex;justify-content:space-between;font-size:12px;font-weight:700;margin-bottom:8px}
.cpp-btns{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.cpp-btns a{text-align:center;padding:8px;border-radius:99px;font-size:10.5px;font-weight:700;
            border:1px solid #e6e9ef;color:#1d2430}
</style>

<script>
/*
 * Appearance · Cart panel, moved out of app.blade.php unchanged.
 *
 * The IIFE is the move's only structural change: these were console globals and
 * nothing outside this screen ever called one.
 */
(function () {
  'use strict';

  var SCREEN = 'cartpanel';

  /* ---------- Appearance · Cart panel ----------
     Same shape as the other settings screens: the server sends tabs and fields,
     this renders them generically, so adding a setting to the service is all it
     takes to have a control appear here. */
  let CP=null, CPTAB='size';

  function cpBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/cart-panel'; }

  async function renderCartPanel(){
    $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Cart panel</h2><p>Loading…</p></div></div>`;
    try{
      const r=await fetch(cpBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
      if(!r.ok) throw new Error(r.status);
      CP=await r.json();
    }catch(e){
      const why=String(e.message||e);
      const hint = why==='404'
        ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
        : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
      $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
        <b>Could not load the cart panel settings.</b>
        <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
        <button class="btn small" onclick="window.kbbCartPanelScreen()">Retry</button></div></div>`;
      return;
    }
    paintCartPanel();
  }
  function cpGet(k){ for(const t of CP.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
  function cpSet(k,v){ for(const t of CP.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

  function cpField(f){
    const v=f.value;
    if(f.type==='bool')
      return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
        <span class="ectog${v?' on':''}" data-cp="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
    if(f.type==='range'){ const o=f.options||{};
      return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
        <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-cp="${f.key}">
          <i id="cpv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
    if(f.type==='colour')
      return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
        <span class="mmcol"><input type="color" value="${v}" data-cp="${f.key}"><code>${v}</code></span></div>`;
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <input type="text" value="${escAttr(String(v))}" data-cp="${f.key}"></div>`;
  }

  /* A real panel at the chosen size and density, using the drawer's own class
     names so the preview and the storefront cannot drift apart. */
  function cpPreview(){
    const on = (k)=>cpGet(k)!==false;
    const line = (init,name,price,grad)=>`<div class="cpp-item">
        ${on('show_thumb')?`<div class="cpp-th" style="background:${grad}">${init}</div>`:''}
        <div class="cpp-mid"><div class="cpp-nm">${escHtml(name)}</div>
          ${on('show_qty')?`<div class="cpp-qty"><span>−</span><b>2</b><span>+</span></div>`:''}</div>
        <div class="cpp-right">${on('show_remove')?`<span class="cpp-rm">✕</span>`:''}
          ${on('show_price')?`<div class="cpp-pr">${price} د.إ</div>`:''}</div>
      </div>`;

    const acc = cpGet('accent'), cta = cpGet('checkout_bg'), ctaFg = cpGet('checkout_fg');

    return `<div class="cpp" style="width:${Math.round(cpGet('panel_width')*0.62)}px;
        --pad:${cpGet('list_pad')}px;--rowpad:${cpGet('row_pad')}px;--thumb:${cpGet('thumb_size')}px;
        --nm:${cpGet('name_size')}px;--lines:${cpGet('name_lines')};--step:${cpGet('stepper_size')}px;--acc:${acc}">
      <div class="cpp-tabs"><b style="color:${escAttr(acc)};border-color:${escAttr(acc)}">${escHtml(cpGet('txt_tab_cart'))} 4</b>${on('show_browsed')?`<i>${escHtml(cpGet('txt_tab_browsed'))}</i>`:''}<u>✕</u></div>
      ${on('show_ship_bar')?`<div class="cpp-ship">${escHtml(cpGet('txt_ship_done'))}<div class="cpp-bar"><div style="background:${escAttr(acc)}"></div></div></div>`:''}
      <div class="cpp-body">
        ${line('I','Age-R Booster Pro Device','80','linear-gradient(140deg,#F6C6A0,#E89B6C)')}
        ${line('RL','Hyaluronic Acid Watery Sun Gel that runs to a second line','133','linear-gradient(140deg,#F4A6B8,#E0567B)')}
        ${line('M','Cellmazing Fit Serum','299','linear-gradient(140deg,#A8D0F0,#5F9BD4)')}
        ${line('S','Ceramide Daily Moisturiser','329','linear-gradient(140deg,#C4B5F0,#8B6FD4)')}
      </div>
      ${on('show_promo')?`<div class="cpp-promo">🎁 Spend 199 for free delivery</div>`:''}
      <div class="cpp-foot"><div class="cpp-sum"><span>${escHtml(cpGet('txt_subtotal'))}</span><span>841 د.إ</span></div>
        <div class="cpp-btns"><a>${escHtml(cpGet('txt_btn_cart'))}</a><a style="background:${escAttr(cta)};color:${escAttr(ctaFg)};border-color:${escAttr(cta)}">${escHtml(cpGet('txt_btn_checkout'))}</a></div></div>
    </div>`;
  }

  function paintCartPanel(){
    const tab=CP.tabs.find(t=>t.key===CPTAB)||CP.tabs[0];
    $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
      <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Cart panel</h2>
        <p class="mdesc" style="margin:0">The slide-out bag: how wide it is, how tightly the lines pack, and what each line shows.</p></div>
      <div class="ectabs">${CP.tabs.map(t=>`<button class="ectab${t.key===CPTAB?' on':''}" data-cptab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
      <div class="mmgrid">
        <div class="mmcols"><div class="card mmcard">
          <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
          <div class="mmbody">${tab.fields.map(cpField).join('')}</div></div></div>
        <div class="mmpv"><div class="mmpv-in">${cpPreview()}</div><p class="mmpv-note">Live preview · desktop width, shown smaller</p></div>
      </div>
      <div class="ecsave">
        <span class="ecdirty" id="cpDirty" style="visibility:hidden">Unsaved changes</span>
        <button class="btn primary" id="cpSave">Save changes</button>
      </div>
    </div>`;
    bindCartPanel();
  }

  function bindCartPanel(){
    $$('[data-cptab]').forEach(b=>b.onclick=()=>{ CPTAB=b.dataset.cptab; paintCartPanel(); });

    $$('[data-cp]').forEach(el=>{
      const k=el.dataset.cp;
      const dirty=()=>{ const d=$('#cpDirty'); if(d) d.style.visibility='visible'; };

      if(el.classList.contains('ectog')){
        el.onclick=()=>{ const v=!el.classList.contains('on'); el.classList.toggle('on',v);
          el.setAttribute('aria-checked',String(v)); cpSet(k,v); dirty(); paintCartPanel(); };
        return;
      }
      if(el.type==='range'){
        /* Repaint the preview on every drag but leave the slider alone, so the
           thumb does not jump out from under the pointer mid-drag. */
        el.oninput=()=>{ cpSet(k,Number(el.value)); dirty();
          const badge=$('#cpv-'+k); if(badge){ const f=CP.tabs.flatMap(t=>t.fields).find(x=>x.key===k);
            badge.textContent=el.value+((f.options||{}).unit||''); }
          $('.mmpv-in').innerHTML=cpPreview(); };
        return;
      }
      el.oninput=()=>{ cpSet(k,el.value); dirty(); $('.mmpv-in').innerHTML=cpPreview();
        const code=el.parentElement.querySelector('code'); if(code) code.textContent=el.value; };
    });

    const save=$('#cpSave');
    if(save) save.onclick=async()=>{
      const settings={};
      for(const t of CP.tabs) for(const f of t.fields) settings[f.key]=f.value;
      save.disabled=true;
      try{
        const r=await fetch(cpBase(),{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
          body:JSON.stringify({settings})});
        const d=await r.json();
        if(!r.ok||!d.ok) throw new Error(d.error||r.status);
        $('#cpDirty').style.visibility='hidden';
        toast('Cart panel saved');
      }catch(e){ toast('Could not save: '+e.message,'bad'); }
      finally{ save.disabled=false; }
    };
  }

  /* ---------------------------------------------------------- sidebar row */
  /* The console already carries a built-in `cartpanel` row in its NAV array, so
     this call finds it and returns it — kbbAddNavEntry is keyed on the screen
     id. It is here anyway, and not as a formality: the integrator deletes the
     dead copy in app.blade.php, and if that deletion ever takes the NAV entry
     with it this row is what keeps the screen in the sidebar. */
  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Cart panel',
      icon: '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/>',
      group: 'Appearance',
      after: ['dividers', 'mobilehdr', 'prodstyles']
    });
  }

  /* ------------------------------------------------------------ the route */
  /* WRAPPED, NOT REPLACED. Nine partials do this, and one that forgot to call
     the previous handler would black out every screen registered before it. */
  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Cart panel';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    renderCartPanel();
    return undefined;
  };

  /* The retry button in the error card is an inline onclick, which needs a
     name on window. One export, read by that one attribute. */
  window.kbbCartPanelScreen = renderCartPanel;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
