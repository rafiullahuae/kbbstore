# FG · the blocks for `resources/views/admin/app.blade.php`

Lane FG may not edit that file. Everything else in this lane is implemented;
these **six** blocks are the remainder, written so they can be applied
mechanically.

**Each block is an exact ANCHOR and an exact REPLACEMENT.** Every anchor occurs
**exactly once** in `resources/views/admin/app.blade.php` at the tip this branch
was cut from — verified by count, not by eye. Apply in any order; no anchor
overlaps another's replacement.

All six were applied to a scratch copy of the file, the console was driven in
real Chromium, and the result is the screenshots in `docs/stripe-connect-shots/`.
The scratch copy was then reverted; nothing in this branch touches
`app.blade.php`.

## What they do, in one line each

| # | Where | What |
| --- | --- | --- |
| 1 | `payStripePanel()` | renames the existing pane to `payStripeState()`, so a second half can go beside it |
| 2 | the end of the not-connected pane | the **Connect application panel**, the guide, the popup handling and the blocked-popup fallback — and gates the one-click button on `oauth_ready` |
| 3 | the `message` listener | records that the popup reported, so the closed-popup watcher does not fire as well |
| 4 | `bindPayments()` | points the one-click button at the new opener and binds the five new controls |
| 5 | `payStripeStatus()` | marks the pane as painted |
| 6 | `bindPayments()` | stops `bindPayments` re-entering `payStripeStatus` |

## Why all six, and what each one breaks on its own

**Blocks 1 and 2 are one edit in two parts.** Block 2's replacement closes the
function block 1 renamed. Applying 2 without 1 leaves `payStripePanel()`
defined twice and the second definition — which calls `payStripeState()`, a
function that would not exist — wins. The Stripe pane throws on first paint.

**Block 2 alone is the feature.** It is the box that stores a `ca_...` client id
and a platform secret key. Without it `oauth_available` is false on every
install, because nothing in the console can write one — which is why the
one-click button, written and shipped by an earlier lane, has never once
rendered on this shop.

**Blocks 5 and 6 are a bug fix this lane did not cause and cannot ship without.**
`payStripeStatus()` repaints the Stripe pane and then calls `bindPayments()`;
`bindPayments()` called `payStripeStatus()` straight back. That is an
unconditional cycle, and it re-read `/connect/status` for as long as the screen
was open. It was invisible while the pane was three lines of text — it cost a
request per round trip and painted the same three lines again.

It stopped being invisible the moment the pane grew a form. Each turn replaces
the pane's `innerHTML`, so a half-typed client id is wiped under the cursor and
the setup guide — fetched separately, resolving a beat later — lands in a
container that has already been thrown away. **The panel is unusable without
these two.** It was observed doing exactly this in Chromium before the fix; the
one-click panel could not be typed into.

**Block 3 without block 2** references `STRIPE_POPUP_DONE`, which block 2
declares. `var` hoists, so it does not throw — it silently assigns to a global
nothing reads, and the closed-popup watcher repaints over the message the
listener was about to explain.

**Block 4 without block 2** points `[data-payoauth]` at `payStripeOauth()`,
which block 2 defines. The one-click button throws on click.

## No route is added to `routes/web.php` by these blocks

`routes/payments-connect-platform.php` is required inside the existing
`admin-api` group, beside the file it extends — that mounting is in the header
of the route file itself and is the integrator's one edit outside this document:

```
    require __DIR__.'/payments-connect.php';

    require __DIR__.'/payments-connect-platform.php';
```

The package ships `2026_11_17_000000_clear_caches_stripe_platform_connect.php`.
On this host a route that is not in the compiled table does not exist, and a
stale compiled `app.blade.php` would paint the old panel — one with no way to
enter a client id — over the new endpoint.

---

## Block 1 · payStripePanel()

Renames the existing pane to payStripeState(), so a second half can be added beside it.

**Anchor** (occurs once):

```
  function payStripePanel(s){
    var a=s.account||{};
    if(s.connected){
```

**Replacement:**

```
  /* FG: the connected / not-connected half, unchanged. payStripePanel() below
     is now the whole pane and adds the Connect application half after it. */
  function payStripeState(s){
    var a=s.account||{};
    if(s.connected){
```

---

## Block 2 · the end of the not-connected pane

Gates the one-click button on oauth_ready and adds the Connect application panel, the guide, the popup handling and the blocked-popup fallback.

**Anchor** (occurs once):

```
      (s.oauth_available?'<button type="button" class="btn ghost" data-payoauth="1">Connect with Stripe (one click)</button>':'')+
      '<span class="echelp" id="pay_conn_msg" style="margin:0"></span></div></div>';
  }
```

**Replacement:**

```
      (s.oauth_ready
        ? '<button type="button" class="btn ghost" data-payoauth="1">Connect with Stripe (one click)</button>'
        : '')+
      '<span class="echelp" id="pay_conn_msg" style="margin:0"></span></div>'+
      /* Where a blocked popup writes its way out. Empty until it has to be. */
      '<div id="pay_conn_fallback"></div></div>';
  }

  /* The whole Stripe pane: what is connected, then how to switch on one click. */
  function payStripePanel(s){
    return payStripeState(s)+payStripePlatform(s);
  }

  /* ---------- the Connect application ----------
     WHY THIS PANEL EXISTS AT ALL. The one-click button is drawn from
     s.oauth_ready, and oauth_ready was false on every install because nothing
     in this console could store a client id. The button had been written,
     shipped and never once rendered. This is the box that fills it in.

     It renders no secret because it is given none: the status endpoint returns
     has_client_secret_* booleans and never a value, not even a masked one — a
     mask still discloses the length. The two password boxes are therefore
     always empty, and an empty box means "leave the stored one alone". */
  function payStripePlatform(s){
    var p=s.platform||{};
    var live=(p.mode!=='test');
    var idLive=p.client_id_live||'', idTest=p.client_id_test||'';
    var hasLive=!!p.has_client_secret_live, hasTest=!!p.has_client_secret_test;

    var pill = s.oauth_ready ? '<span class="pill green"><span class="d"></span>one click ready</span>'
             : (s.oauth_available ? '<span class="pill amber">half set up</span>'
                                  : '<span class="pill">not set up</span>');

    /* The sentence that distinguishes "you have not done this" from "you did
       it and half of it is missing". Only the second one is a mistake, and the
       panel used to say the same thing for both. */
    /* Already connected: there is no Connect button above to point at, and
       telling him to press one that is not on the screen is the small lie that
       makes an owner distrust the rest of the panel. */
    var say = s.connected
      ? (s.oauth_ready
          ? 'Set up. This shop is connected'+(s.link==='oauth'?' through one click':' with a pasted key')+
            ', and the one-click button is what you will see after a disconnect \u2014 nothing here has to be '+
            'entered again.'
          : 'This shop is connected'+(s.link==='oauth'?' through one click':' with a pasted key')+
            '. The Connect application is not fully set up, so after a disconnect the one-click button would not '+
            'be available until it is.')
      : s.oauth_ready
      ? (p.falls_back_to_merchant_key
          ? 'Ready, using this shop’s own stored Stripe key for the final step. That works while the shop stays '+
            'connected; saving the platform secret key below makes it work from a clean start too, which is the '+
            'state it is in after a disconnect.'
          : 'Ready. Press Connect with Stripe above and approve it in the window that opens.')
      : (s.oauth_available
          ? 'A Connect application id is saved but the '+(live?'Live':'Test')+' platform secret key is not, and '+
            'Stripe authenticates the last step with it. The button is held back on purpose: without that key the '+
            'window would open, you would grant this shop access to your Stripe account, and nothing would be saved.'
          : 'One-click Connect needs a Stripe Connect application registered in your own Stripe Dashboard. '+
            'WooCommerce hides this step because WooCommerce.com registers one for every shop that uses it, and '+
            'nobody has done that for this shop. Leave this empty and nothing is lost — pasting your secret '+
            'key above connects this shop just as fully.');

    return '<div class="ecopt wide" style="margin-top:14px;border-top:1px solid var(--line);padding-top:14px">'+
      '<div class="ecom"><div class="ecl"><label>Connect application (one-click setup)</label>'+pill+'</div>'+
      '<div class="echelp">'+say+'</div></div>'+
      '<div class="ecctl" style="display:block;width:100%">'+

      '<div class="row" style="gap:10px;align-items:flex-start;flex-wrap:wrap">'+
      '<div style="flex:1 1 240px"><div class="echelp" style="margin:0 0 4px">Test client id</div>'+
      '<input type="text" class="inp" id="pay_capp_id_test" spellcheck="false" placeholder="ca_…" '+
      'style="max-width:none" value="'+sesc(idTest)+'"></div>'+
      '<div style="flex:1 1 240px"><div class="echelp" style="margin:0 0 4px">Live client id</div>'+
      '<input type="text" class="inp" id="pay_capp_id_live" spellcheck="false" placeholder="ca_…" '+
      'style="max-width:none" value="'+sesc(idLive)+'"></div></div>'+

      '<div class="row" style="gap:10px;align-items:flex-start;flex-wrap:wrap;margin-top:8px">'+
      '<div style="flex:1 1 240px"><div class="echelp" style="margin:0 0 4px">Test platform secret key'+
      (hasTest?' — <b>stored</b>':'')+'</div>'+
      '<input type="password" class="inp" id="pay_capp_sec_test" autocomplete="new-password" spellcheck="false" '+
      'placeholder="'+(hasTest?'••• leave blank to keep':'sk_test_…')+'" style="max-width:none"></div>'+
      '<div style="flex:1 1 240px"><div class="echelp" style="margin:0 0 4px">Live platform secret key'+
      (hasLive?' — <b>stored</b>':'')+'</div>'+
      '<input type="password" class="inp" id="pay_capp_sec_live" autocomplete="new-password" spellcheck="false" '+
      'placeholder="'+(hasLive?'••• leave blank to keep':'sk_live_…')+'" style="max-width:none"></div></div>'+

      /* The return address, printed in full and never shortened. The web root
         on this host is a different directory from the application root and
         every route carries KBB_BASE_PATH, so this is NOT the bare domain plus
         the path in the route file. Owners shorten it; Stripe then refuses the
         authorize request with an error about redirect_uri and says nothing
         about why. */
      '<div class="echelp" style="margin-top:10px">Register this exact address in the Connect application’s '+
      'redirect URI list. Stripe matches it character for character.</div>'+
      '<div class="row" style="gap:8px;margin-top:4px">'+
      '<input type="text" class="inp" id="pay_capp_redirect" readonly data-payhook="1" style="max-width:none" '+
      'value="'+sesc(String(s.redirect_uri||''))+'">'+
      '<button type="button" class="btn ghost" data-payappcopy="1">Copy</button>'+
      '<span class="echelp" id="pay_capp_copied" style="margin:0"></span></div>'+

      '<div class="row" style="gap:8px;margin-top:10px">'+
      '<button type="button" class="btn" data-payappsave="1">Save Connect application</button>'+
      ((hasTest||hasLive)?'<button type="button" class="btn ghost" data-payappclear="1">Forget stored keys</button>':'')+
      '<button type="button" class="btn ghost" data-payrecheck="1">Re-check connection</button>'+
      '<span class="echelp" id="pay_capp_msg" style="margin:0"></span></div>'+

      '<div id="pay_capp_guide" style="margin-top:10px"></div>'+
      '</div></div>';
  }

  /* ---------- the setup guide ----------
     Fetched rather than inlined: the steps are App\Support\StripeConnectConsole
     and belong beside the code that knows what the flow needs, not in a string
     in this file that drifts from it. Every step names a MENU PATH and none
     carries a link, because no Stripe URL could be loaded and verified from the
     machine this was written on — and a wrong link in a credential-setup guide
     is the shape of a phishing page. */
  async function payStripeGuide(){
    var host=document.getElementById('pay_capp_guide');
    if(!host) return;
    var g;
    try{ g=(await api('/admin-api/payments/stripe/connect/platform')).guide; }
    catch(e){ host.innerHTML='<div class="echelp">The setup guide could not be loaded.</div>'; return; }
    if(!g){ host.innerHTML=''; return; }
    var steps=(g.steps||[]).map(function(st,i){
      return '<li style="margin:0 0 10px"><b>'+sesc(st.title)+'</b><div class="echelp" style="margin:2px 0 0">'+
        sesc(st.body)+'</div></li>';
    }).join('');
    host.innerHTML='<details class="tr-guide" style="border:1px solid var(--line);border-radius:10px;padding:10px 12px">'+
      '<summary style="cursor:pointer;font-weight:600">'+sesc(g.heading)+'</summary>'+
      '<div class="echelp" style="margin:8px 0 10px">'+sesc(g.intro||'')+'</div>'+
      '<ol style="margin:0 0 0 18px;padding:0">'+steps+'</ol>'+
      '<div class="echelp" style="margin-top:10px">'+sesc(g.closing||'')+'</div>'+
      '</details>';
  }

  async function payStripePlatformSave(clear){
    var msg=document.getElementById('pay_capp_msg');
    var idT=document.getElementById('pay_capp_id_test');
    var idL=document.getElementById('pay_capp_id_live');
    var secT=document.getElementById('pay_capp_sec_test');
    var secL=document.getElementById('pay_capp_sec_live');
    if(!idL) return;
    if(msg) msg.textContent='Saving…';
    var body={
      client_id: idL.value, client_id_test: idT?idT.value:'',
      client_secret: secL?secL.value:'', client_secret_test: secT?secT.value:''
    };
    if(clear){ body.clear_client_secret=true; body.clear_client_secret_test=true;
               body.client_secret=''; body.client_secret_test=''; }
    try{
      var r=await fetch(fixAdminApiUrl('/admin-api/payments/stripe/connect/application'),{
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
        body:JSON.stringify(body)
      });
      var d=await r.json();
      /* Cleared whatever the answer was. A refused save that left a live secret
         key sitting in a form field is a secret key sitting in the DOM. */
      if(secT) secT.value=''; if(secL) secL.value='';
      if(!d.ok){ if(msg) msg.textContent=d.error||'That could not be saved.'; return; }
      if(msg) msg.textContent='';
      await payStripeStatus();
    }catch(e){ if(msg) msg.textContent='Could not reach this site to save.'; }
  }

  /* ---------- the popup ----------
     Opened by the click itself. A round trip before window.open is what the
     browser's popup blocker is looking for, so the URL is ours and 302s to
     Stripe rather than being fetched first and navigated to second. */
  var STRIPE_POPUP=null, STRIPE_POPUP_TIMER=null, STRIPE_POPUP_DONE=false;

  function payStripeOauth(){
    var m=document.getElementById('pay_mode_stripe');
    var url=fixAdminApiUrl('/admin-api/payments/stripe/connect/start?mode='+
      encodeURIComponent(m?m.value:'test'));
    var msg=document.getElementById('pay_conn_msg');
    var fb=document.getElementById('pay_conn_fallback');
    if(fb) fb.innerHTML='';
    STRIPE_POPUP_DONE=false;

    var w=null;
    try{ w=window.open(url,'kbbstripe','width=620,height=760'); }catch(e){ w=null; }

    /* BLOCKED. window.open returns null, or an object that is already closed,
       or — in a couple of older browsers — something with no `closed` at all.
       All three are the same answer and none of them may leave a dead button. */
    if(!w || w.closed || typeof w.closed==='undefined'){
      payStripePopupBlocked(url);
      return;
    }

    STRIPE_POPUP=w;
    try{ w.focus(); }catch(e){}
    if(msg) msg.textContent='Finish in the Stripe window…';

    /* HE CLOSED IT. Nothing reaches this page when a popup is dismissed, so
       without this the screen would sit on "Finish in the Stripe window…" for
       ever. The answer is not guessed either: the server is asked what it now
       holds, because he may well have completed the flow and closed the window
       before it could report back. */
    if(STRIPE_POPUP_TIMER) clearInterval(STRIPE_POPUP_TIMER);
    STRIPE_POPUP_TIMER=setInterval(function(){
      var p=STRIPE_POPUP;
      if(!p) { clearInterval(STRIPE_POPUP_TIMER); STRIPE_POPUP_TIMER=null; return; }
      var shut=false;
      try{ shut=p.closed; }catch(e){ shut=true; }
      if(!shut) return;
      clearInterval(STRIPE_POPUP_TIMER); STRIPE_POPUP_TIMER=null; STRIPE_POPUP=null;
      if(STRIPE_POPUP_DONE) return;
      if(msg) msg.textContent='';
      renderPayments();
    },600);
  }

  /* The fallback, and it has to be something that WORKS rather than an
     apology. A link the owner clicks himself is a user gesture on an anchor,
     which no popup blocker stops. rel="opener" is load-bearing: target=_blank
     implies rel=noopener in every current browser, the callback page would
     find window.opener null, and the console would never be told the outcome —
     a tab that says "connected" over a screen that still says "not connected".
     Re-check covers even that. */
  function payStripePopupBlocked(url){
    var fb=document.getElementById('pay_conn_fallback');
    var msg=document.getElementById('pay_conn_msg');
    if(msg) msg.textContent='';
    if(!fb) return;
    fb.innerHTML='<div class="ecnote" style="margin-top:10px">'+
      '<b>Your browser blocked the Stripe window.</b>'+
      '<div class="echelp" style="margin-top:6px">Nothing has gone wrong and nothing has changed. '+
      'Open it with the link below, or allow pop-ups for this site and press Connect with Stripe again.</div>'+
      '<div class="row" style="gap:8px;margin-top:8px">'+
      '<a class="btn" rel="opener" target="_blank" href="'+sesc(url)+'">Open the Stripe window</a>'+
      '<button type="button" class="btn ghost" data-payrecheck="1">Re-check connection</button></div></div>';
    bindPayments();
  }
```

---

## Block 3 · the window 'message' listener

Records that the popup reported back, so the closed-popup watcher does not also fire.

**Anchor** (occurs once):

```
  /* The popup reports back on this origin and closes itself. */
  window.addEventListener('message',function(e){
    if(e.origin!==window.location.origin) return;
    if(!e.data||e.data.source!=='kbb.stripe.connect') return;
    var r=e.data.result||{};
    renderPayments().then(function(){
      if(!r.ok && r.error) alert(r.error);
      else if((r.warnings||[]).length) alert(r.warnings.join('\n\n'));
    });
  });
```

**Replacement:**

```
  /* The popup reports back on this origin and closes itself.

     THE ORIGIN CHECK IS THE POINT. This listener is on window, so any frame or
     opened window can post to it; without the check, a page on another origin
     could hand this console a fabricated result and make the payments screen
     claim a connection that does not exist. It is compared against an ORIGIN
     and never a URL — postMessage compares scheme, host and port, and a path on
     the end makes every comparison fail silently. */
  window.addEventListener('message',function(e){
    if(e.origin!==window.location.origin) return;
    if(!e.data||e.data.source!=='kbb.stripe.connect') return;
    var r=e.data.result||{};
    /* FG: the popup HAS reported. The watcher in payStripeOauth() polls for it
       being closed and would otherwise repaint a second time a moment later,
       over the top of this one — and, on a failure, would clear the message
       this alert is about to explain. */
    STRIPE_POPUP_DONE=true;
    if(STRIPE_POPUP_TIMER){ clearInterval(STRIPE_POPUP_TIMER); STRIPE_POPUP_TIMER=null; }
    STRIPE_POPUP=null;
    renderPayments().then(function(){
      if(!r.ok && r.error) alert(r.error);
      else if((r.warnings||[]).length) alert(r.warnings.join('\n\n'));
    });
  });
```

---

## Block 4 · bindPayments()

Points the one-click button at the new opener and binds the five controls on the Connect application panel.

**Anchor** (occurs once):

```
    document.querySelectorAll('[data-payoauth]').forEach(function(b){
      b.onclick=function(){
        /* window.open on the click itself, or the popup is blocked. The URL is
           ours and 302s to Stripe, so there is no round trip in between. */
        var m=document.getElementById('pay_mode_stripe');
        window.open(fixAdminApiUrl('/admin-api/payments/stripe/connect/start?mode='+
          encodeURIComponent(m?m.value:'test')),'kbbstripe','width=620,height=760');
      };
    });
```

**Replacement:**

```
    document.querySelectorAll('[data-payoauth]').forEach(function(b){
      b.onclick=function(){ payStripeOauth(); };
    });
    document.querySelectorAll('[data-payappsave]').forEach(function(b){
      b.onclick=function(){ payStripePlatformSave(false); };
    });
    document.querySelectorAll('[data-payappclear]').forEach(function(b){
      b.onclick=function(){
        if(!confirm('Forget the stored platform secret keys?\n\nThe one-click button switches off until one is '+
          'saved again. Nothing that is already connected is disconnected, and no money is affected.')) return;
        payStripePlatformSave(true);
      };
    });
    /* The last resort, and it is bound on the panel AND inside the blocked-popup
       notice: the one case where nothing else can tell this screen what
       happened is the one where the browser refused to open the window that
       would have. Asking the server is always available and never wrong. */
    document.querySelectorAll('[data-payrecheck]').forEach(function(b){
      b.onclick=function(){ renderPayments(); };
    });
    document.querySelectorAll('[data-payappcopy]').forEach(function(b){
      b.onclick=async function(){
        var input=document.getElementById('pay_capp_redirect');
        if(!input) return;
        try{ await navigator.clipboard.writeText(input.value); }
        catch(err){ input.select(); try{ document.execCommand('copy'); }catch(e2){} }
        var ok=document.getElementById('pay_capp_copied');
        if(ok){ ok.textContent='Copied'; setTimeout(function(){ ok.textContent=''; },1800); }
      };
    });
    if(document.getElementById('pay_capp_guide')) payStripeGuide();
```

---

## Block 5 · payStripeStatus()

Marks the pane as painted, which is what breaks the repaint loop.

**Anchor** (occurs once):

```
  async function payStripeStatus(){
    var host=document.getElementById('pay_conn_stripe');
    if(!host) return;
    try{ STRIPE_CONN=await api('/admin-api/payments/stripe/connect/status'); }
    catch(e){ host.innerHTML='<div class="ecom"><div class="echelp">Could not read the Stripe connection.</div></div>'; return; }
    host.innerHTML=payStripePanel(STRIPE_CONN);
    bindPayments();
  }
```

**Replacement:**

```
  /* FG: `painted` is the whole of the loop fix; see block 6.
     payStripeStatus() repaints the pane and then calls bindPayments(), and
     bindPayments() called payStripeStatus() back — an unconditional cycle that
     re-read the status endpoint for as long as the screen was open. It was
     invisible while the pane was three lines of text: it cost a request every
     round trip and painted the same three lines again.

     It stopped being invisible the moment the pane grew a form. Each turn of
     the cycle replaces the pane's innerHTML, so a half-typed client id was
     wiped under the cursor, and the guide — which is fetched separately and
     resolves a beat later — landed in a container that had already been
     thrown away. The panel could not be used at all.

     The flag is set on the HOST, which renderPayments() recreates from
     scratch, so leaving the screen and coming back still re-reads the truth.
     It is only the SECOND call within one painted pane that is refused. */
  async function payStripeStatus(){
    var host=document.getElementById('pay_conn_stripe');
    if(!host) return;
    try{ STRIPE_CONN=await api('/admin-api/payments/stripe/connect/status'); }
    catch(e){ host.innerHTML='<div class="ecom"><div class="echelp">Could not read the Stripe connection.</div></div>'; return; }
    host.innerHTML=payStripePanel(STRIPE_CONN);
    host.dataset.painted='1';
    bindPayments();
  }
```

---

## Block 6 · bindPayments()

Stops bindPayments re-entering payStripeStatus, closing the loop.

**Anchor** (occurs once):

```
    if(document.getElementById('pay_conn_stripe')) payStripeStatus();
```

**Replacement:**

```
    /* FG: once per painted pane, not once per bind. bindPayments() is called
       BY payStripeStatus() at the end of every repaint, so this line called it
       straight back — see the note on payStripeStatus() for what that cost. */
    var connHost=document.getElementById('pay_conn_stripe');
    if(connHost && !connHost.dataset.painted) payStripeStatus();
```

---
