
const $=(s,r=document)=>r.querySelector(s);
const $$=(s,r=document)=>[...r.querySelectorAll(s)];
/* width/height are PRESENTATION ATTRIBUTES, deliberately, not a style attribute.
   An <svg> with a viewBox and no size has no intrinsic dimensions, so inside a
   flex row it stretches to whatever is going spare -- which is how the "no
   longer editable" note on a completed order drew an info icon roughly 650px
   across. The admin CSS only sizes svg inside particular containers
   (.nav-item svg, .btn svg, .iconbtn svg and a dozen more), so every ic() used
   outside one of those was unsized; this one was simply the most visible.
   Presentation attributes sit below author CSS in the cascade, so all of those
   rules still win and nothing that was already sized moves. */
const ic=(p)=>`<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8">${p}</svg>`;
const I={
  dash:'<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
  modules:'<rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><path d="M17 13v8M13 17h8"/>',
  theme:'<circle cx="13.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="10.5" r="2.5"/><circle cx="8.5" cy="7.5" r="2.5"/><path d="M12 22a10 10 0 1 1 9-14c0 3-3 3-5 3s-3 2-2 4 1 3-2 3z"/>',
  users:'<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M17 11a3 3 0 0 0 0-6M19.5 20a5.5 5.5 0 0 0-3-5"/>',
  settings:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7.7 1.6 1.6 0 0 0-1.6 1.3H12a2 2 0 0 1-2-2 1.6 1.6 0 0 0-2.7-1.1 1.6 1.6 0 0 1-1.8.3 2 2 0 1 1-2.8-2.8 1.6 1.6 0 0 0 .7-2.7 1.6 1.6 0 0 0-1.3-1.6V12a2 2 0 0 1 2-2 1.6 1.6 0 0 0 1.1-2.7 1.6 1.6 0 0 1 .3-1.8 2 2 0 1 1 2.8-2.8 1.6 1.6 0 0 0 2.7.7H12a2 2 0 0 1 2 2 1.6 1.6 0 0 0 2.7 1.1 1.6 1.6 0 0 1 1.8-.3 2 2 0 1 1 2.8 2.8 1.6 1.6 0 0 0-.7 2.7 1.6 1.6 0 0 0 1.3 1.6V12a2 2 0 0 1-2 2 1.6 1.6 0 0 0-1.3.9z"/>',
  debug:'<rect x="5" y="8" width="14" height="12" rx="6"/><path d="M9 8V6a3 3 0 0 1 6 0v2M3 13h2M19 13h2M4 18l2-1M20 18l-2-1M4 8l2 1M20 8l-2 1"/>',
  sandbox:'<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4M3 17l9 4 9-4"/>',
  catalog:'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
  orders:'<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/>',
  cust:'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  mkt:'<path d="M3 11v2a1 1 0 0 0 1 1h2l4 4V6L6 10H4a1 1 0 0 0-1 1zM15 8a4 4 0 0 1 0 8M19 5a8 8 0 0 1 0 14"/>',
  content:'<path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
  check:'<path d="M20 6 9 17l-5-5"/>',
  /* The toast's failure glyph. A triangle rather than a cross: a cross reads as
     "dismiss" on a pill that is already tapping itself away. */
  alert:'<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4"/><path d="M12 17.5v.5"/>',
  rocket:'<path d="M5 13c-1.5 1.5-2 5-2 5s3.5-.5 5-2M9 15l-3-3M15 9l3-3M14.5 4.5C18 3 21 3 21 3s0 3-1.5 6.5C18 13 14 16 12 17l-5-5c1-2 4-6 7.5-7.5z"/><circle cx="14.5" cy="9.5" r="1.2"/>',
  bell:'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/>',
  shield:'<path d="M12 2 4 5v6c0 5 3.5 8.5 8 10 4.5-1.5 8-5 8-10V5z"/><path d="M9 12l2 2 4-4"/>',
  cash:'<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>',
  revenue:'<path d="M3 17l5-5 4 4 8-8M21 8h-4M21 8v4"/>',
  copy:'<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
};

/* ---------- admin base path ----------
   Read from the address bar rather than hard-coded, so changing the admin
   address in Core Updates moves every link here with it — no second place to
   keep in sync. */
const ADMIN_BASE = window.location.pathname.replace(/\/+$/, '');

/* ---------- LANE NAV · the dashboard asks for its numbers now, not at 39% ---
   THE OTHER HALF OF THE OWNER'S REPORT: "some pages don't show immidiately and
   it takes long to long", with "Loading the latest orders..." still spinning in
   his screenshot.

   That line is renderDash()'s placeholder and hydrateDash() replaces it from
   GET /admin-api/stats. The endpoint is not slow -- measured cold, 62.7ms. It
   was not being CALLED. hydrateDash() is defined in the second script block and
   its only boot call sits at the foot of that block, which on the applied
   console is byte 1,324,219 of 3,425,404: the browser must download and parse
   1.3 MB before the dashboard so much as asks for its own numbers, and at
   2 Mbit/s that is more than five seconds of a screen that is drawn but empty.

   The request is started HERE instead, at byte ~197,000, which is as early as
   the address of the endpoint is knowable. It is one GET with no side effects
   and the dashboard is the screen this console opens on, so it is never
   wasted; hydrateDash() below awaits this promise rather than issuing its own.

   IT CANNOT BREAK hydrateDash(). The promise is stored resolved-or-rejected and
   read exactly once -- a second hydrate (the order-status save re-hydrates)
   falls through to a fresh api() call, which is what every call after the first
   did before this existed. A rejection here is caught into `null` so an
   unhandled rejection can never reach the console, and hydrateDash() treats a
   null exactly as it treats a throw: it returns and leaves the screen alone.

   NOT A CACHE, and deliberately not one. It answers the FIRST paint only. */
window.__kbbStatsFirst = (function(){
  try{
    var url = ADMIN_BASE.replace(/\/[^\/]*$/, '') + '/admin-api/stats';
    return fetch(url, {credentials:'same-origin', headers:{'Accept':'application/json'}})
      .then(function(r){ return r.ok ? r.json() : null; })
      .catch(function(){ return null; });
  }catch(e){ return null; }
})();

/* ---------- nav ----------
   LANE AP. THE SIDEBAR IS SERVER-RENDERED, and this is the half that binds it.

   NAV and LATE_NAV used to live here as literals, and buildNav() drew #nav from
   them -- which it could only do once this 650 KB script block had arrived, so
   on a throttled cold load the sidebar was empty for its first 1.4 s and one
   row (#KBeautyBliss Spotted, in neither list) arrived at DOMContentLoaded.

   App\Support\AdminNav is now the ONE definition. It prints the complete
   <nav id="nav"> into the shell, filtered to what this account may open, and
   prints window.KBB_NAV beside it: the ids whose renderer ships late (for
   kbbNavClick's replay) and the ids withheld from this account (so a partial's
   own kbbAddNavEntry() cannot put one back). Nothing about a row -- its label,
   icon, group or position -- is restated here, so the two cannot drift.

   buildNav() keeps its name and its job of wiring the rows; it no longer draws
   them. A #nav that arrives empty is a server fault and says so. */
const KBB_NAV_DATA=window.KBB_NAV||{late:[],hidden:[]};
const LATE_NAV_IDS=new Set(KBB_NAV_DATA.late);
const NAV_HIDDEN=new Set(KBB_NAV_DATA.hidden);
function buildNav(){
  if(!$('#nav .nav-item')) console.error('buildNav: the server sent an empty #nav, so the sidebar has no rows. App\\Support\\AdminNav::html() renders it; check the console view still prints it.');
  $$('#nav .nav-item').forEach(b=>b.onclick=()=>kbbNavClick(b.dataset.go));
  $$('#nav .nav-gh').forEach(h=>h.onclick=()=>h.closest('.nav-group').classList.toggle('open'));
}
function syncNavOpen(id){
  $$('#nav .nav-group').forEach(g=>{
    const has=[...g.querySelectorAll('.nav-item')].some(b=>b.dataset.go===id);
    g.classList.toggle('open',has);
  });
}

/* ---------- one supported way to add a sidebar row ---------- */
/*
 * A screen that ships as its own partial cannot be listed in NAV: buildNav()
 * has already run by the time the partial loads, and NAV does not know the
 * partial exists. So each of them registers its own row afterwards.
 *
 * Five partials used to do that by hand, with five copies of the same four
 * steps — find an anchor row with `#nav [data-go="..."]`, build a <button>,
 * paste in the icon markup and the class name, insert the button — and, every
 * one of them, the same last line: `if (!anchor) return;`.
 *
 * That line is the reason this function exists. It means renaming ONE row in
 * NAV removes a DIFFERENT screen from the sidebar, silently: no error, no
 * warning, nothing anywhere. An owner cannot tell a screen that quietly left
 * the menu from a screen that never shipped, which is precisely the wrong
 * conclusion they drew about the coupon editor. Three of the five chains had
 * already drifted onto anchors that do not exist without anyone noticing,
 * because nothing says so when they do not resolve.
 *
 *   kbbAddNavEntry({screen, label, icon, group, after})
 *
 *   screen  the data-go id. Also the duplicate key: a second call for a row
 *           that is already in the sidebar adds nothing and returns the row
 *           that is already there. Partials register on DOMContentLoaded and
 *           again if they load after it, so that is the normal path, not a
 *           fault.
 *   label   the row's visible text.
 *   icon    inner SVG markup, the same paths NAV items carry. Drawn with ic(),
 *           so an injected row and a built-in row are the same markup rather
 *           than two drifting copies of it.
 *   group   the NAV section this row belongs in, named by its `sec`. The group
 *           is never CREATED here, only joined: a group invented at this point
 *           would be a second place that decides the sidebar's shape, and
 *           TITLES, the breadcrumbs and buildNav would all still be using the
 *           first one.
 *   after   preferred anchor id, or an array of them tried in order. Optional.
 *
 * Placement degrades, in this order, instead of giving up:
 *
 *   1. after the first `after` row that is inside `group` — the intended spot;
 *   2. at the end of `group` — right section, wrong position, no complaint;
 *   3. after the first `after` row wherever it turns out to be — wrong
 *      section, and it SAYS so;
 *   4. at the end of #nav — visible and wrong beats invisible, and it says so.
 *
 * Only steps 3 and 4 are failures, and both are loud: a console.error naming
 * the screen, the group it wanted and the anchors it tried. Returning quietly
 * is the one thing this function will not do, because that is the behaviour it
 * replaced. It returns null only when there is no sidebar to add to at all.
 */
function kbbAddNavEntry(opts){
  const o = opts || {};
  const screen = o.screen;

  if (!screen) {
    console.error('kbbAddNavEntry: called with no screen id, so no sidebar row was added.', o);
    return null;
  }

  // Withheld from this account on purpose: App\Support\AdminNav left the row
  // out because the role cannot open the screen. Not a failure, so no report
  // and no row -- false, not null, which stays "something went wrong".
  if (NAV_HIDDEN.has(screen)) return false;

  const nav = $('#nav');
  if (!nav) {
    console.error('kbbAddNavEntry: "' + screen + '" has no sidebar to join — this document has no #nav. The screen is still routable; it simply has no row.');
    return null;
  }

  // Already registered. Scoped to #nav on purpose: a screen may draw its own
  // [data-go] buttons inside #content, and those are links, not sidebar rows.
  const already = nav.querySelector('[data-go="' + screen + '"]');
  if (already) return already;

  const b = document.createElement('button');
  b.className = 'nav-item';
  b.dataset.go = screen;
  b.innerHTML = ic(o.icon || '') + '<span>' + (o.label || screen) + '</span>';
  b.onclick = () => kbbNavClick(screen);

  const sub = o.group ? nav.querySelector('.nav-group[data-sec="' + o.group + '"] .nav-sub') : null;
  const after = o.after == null ? [] : (Array.isArray(o.after) ? o.after : [o.after]);
  const find = (id) => nav.querySelector('[data-go="' + id + '"]');

  // 1. the intended position: an anchor that is in the group this row claims.
  //    A row that named a group only lands here if that group exists and holds
  //    the anchor. Accepting the anchor when the group has gone would be step 1
  //    quietly doing step 3's job, and step 3 is the one that reports.
  for (const id of after) {
    const a = find(id);
    if (!a || !a.parentNode) continue;
    if (o.group && !(sub && sub.contains(a))) continue;
    a.parentNode.insertBefore(b, a.nextSibling);
    return b;
  }

  // 2. the group itself. The anchor moved or was renamed; the section the
  //    row's own breadcrumb names is still there, so that is where it goes.
  if (sub) { sub.appendChild(b); return b; }

  // 3. the anchor wherever it actually ended up — wrong section, but next to
  //    something related and still reachable.
  for (const id of after) {
    const a = find(id);
    if (a && a.parentNode) {
      a.parentNode.insertBefore(b, a.nextSibling);
      console.error('kbbAddNavEntry: "' + screen + '" asked for the "' + o.group + '" group, which this sidebar does not have. The row is sitting next to "' + id + '" instead, in whatever section that is. Add the group to NAV or correct `group` where "' + screen + '" registers.');
      return b;
    }
  }

  // 4. last resort. Nothing it named exists; pin it to the end of the sidebar
  //    rather than drop it, and say exactly what could not be found.
  nav.appendChild(b);
  console.error('kbbAddNavEntry: "' + screen + '" could not be placed — no "' + o.group + '" group and none of its anchors (' + (after.join(', ') || 'none given') + ') are in the sidebar. The row is pinned to the bottom of #nav so the screen is still reachable. Something renamed a NAV row; fix `group`/`after` where "' + screen + '" registers.');
  return b;
}
window.kbbAddNavEntry = kbbAddNavEntry;

/* ---------- LANE NAV · clicking a row whose renderer has not arrived -------
   LATE_NAV puts twenty-one rows in the sidebar at buildNav(), which is 21.4
   per cent of the way through this document. Their renderers are still being
   parsed. So for the first few seconds of a cold load a row can be clicked
   before the partial that draws it exists -- a window this console never had
   before, because the row did not exist either.

   Clicked in that window, go() finds no entry in its dispatch object and ends
   `||renderDash`: THE DASHBOARD UNDER THE CLICKED SCREEN NAME, with no error.
   That is the exact defect LATE_RENDERED and the deep-link replay were built
   for, reached by a click instead of by an address, so it is answered the same
   way -- navigate now, and if nothing but the dashboard drew, navigate once
   more when the document is parsed and the renderer exists.

   THE TEST IS renderDash S OWN WRAPPER, not a marker and not a timer. A marker
   cannot tell the two cases apart here: go() clears #content before it draws,
   so a marker placed before the call is destroyed whichever screen wins, and
   one placed after survives a screen that drew itself correctly and would
   replay over it. Lane DA measured that double render on `rev-all`. #kbbDashWrap
   is present only when renderDash() painted, which is precisely the case that
   needs replaying, so a screen whose partial had already arrived is never
   replayed and never drawn twice.

   ONE QUEUED TARGET, CANCELLED BY THE NEXT CLICK. Every sidebar row routes
   through here, so a second click overwrites the first. The replay then checks
   `cur` as well: any other navigation -- an inline go() inside the dashboard,
   a deep-link replay, the address bar -- has moved it, and the queued target is
   dropped rather than yanking the owner off the screen he is now on.

   AFTER DOMContentLoaded THIS DOES NOTHING AT ALL. Every partial has run by
   then, so there is nothing left to wait for and no replay is ever armed; the
   call is one Set lookup and a readyState test. */
let kbbNavReplay=null;
let kbbNavReplayArmed=false;
/* LANE AP · a sidebar row whose screen is not in this console.
   App\Support\AdminNav can carry a row before the partial that draws it has
   merged (`pending`: Site App, Owner App). go() has no renderer for it and
   ends `||renderDash` -- the dashboard under the row's name, the silent
   failure this file keeps meeting. Once every partial has run, a row that
   still drew the dashboard is told so, in the row's own words. Read from the
   DOM row (label, group), written with textContent: nothing from data. */
function kbbNoScreen(id){
  if(id==='dash' || !$('#kbbDashWrap')) return;
  const row=$('#nav .nav-item[data-go="'+id+'"]'); if(!row) return;
  const label=(row.querySelector('span')||row).textContent;
  const g=row.closest('.nav-group'); const grp=g?g.dataset.sec:'';
  $('#crumb').textContent=grp; $('#ptitle').textContent=label;
  const wrap=document.createElement('div'); wrap.className='wrap';
  wrap.innerHTML='<div class="ph"><div class="pic">'+ic(I.modules)+'</div><h3></h3><p>The menu entry is in place; the screen arrives with its update package. Nothing is broken and nothing needs doing.</p></div>';
  wrap.querySelector('h3').textContent=label+' is not installed yet';
  const c=$('#content'); c.innerHTML=''; c.appendChild(wrap);
}
function kbbNavClick(id){
  kbbNavReplay=null;
  if(typeof window.go!=='function') return;
  window.go(id);
  if(document.readyState!=='loading'){ kbbNoScreen(id); return; }   /* every partial has run */
  if(id==='dash') return;
  /* The arming set is the deep link's, plus the rows declared above. Both
     answer the same question -- can the go() that exists right now draw this
     id at all -- and LIVE_RENDERED is the half this lane MEASURED rather than
     reasoned about: clicking Store -> Orders 4.6s into a throttled load left
     the console on "Orders could not be loaded ... Reload the page." for good,
     and reloading reproduces it, because the address is not the cause. That is
     Lane DA's defect reached by a click instead of by a link, and it is older
     than this lane. AdminNavAndIdsTest already keeps every id that draws itself
     after an await OUT of both sets, which is the rule this must not break. */
  if(!(LATE_NAV_IDS.has(id) || LIVE_RENDERED.has(id) || LATE_RENDERED.has(id))) return;
  if(!$('#kbbDashWrap') && !$('#kbbFrameStartup')) return;   /* a real screen drew it: nothing to replay */
  kbbNavReplay=id;
  if(kbbNavReplayArmed) return;
  kbbNavReplayArmed=true;
  document.addEventListener('DOMContentLoaded',function(){
    /* setTimeout for the same reason the deep-link replay uses one: this
       listener is registered before any partial is parsed, so it runs ahead of
       the very boots whose result it reads. A task queued from inside it runs
       after the lot. */
    setTimeout(function(){
      const want=kbbNavReplay;
      kbbNavReplay=null;
      if(!want) return;
      if(cur!==want) return;                       /* the owner has moved on */
      if(!$('#kbbDashWrap') && !$('#kbbFrameStartup')) return;   /* something drew it after all */
      try{ window.go(want); }catch(e){}
      kbbNoScreen(want);
    },0);
  });
}
window.kbbNavClick = kbbNavClick;

/* Breadcrumb and page title per screen: [group, title].

   The group here is the sidebar group the entry actually sits in, and the title
   is the entry's own label. When the two drift, the owner clicks one word and
   the page answers with another — which is the whole complaint this map is now
   pinned against in AdminNavAndIdsTest.

   `modules` used to be declared twice in this object: once as ['Platform',…]
   and again, later, as ['Store',…]. The second silently won, so anyone editing
   the first saw nothing change. One declaration now. */
const TITLES={'mkt-email':['Growth & Marketing','Marketing Emails'],dash:['Overview','Dashboard'],updates:['Platform','Core Updates'],theme:['Platform','K-Beauty Bliss Theme'],users:['Platform','Users & Roles'],settings:['Platform','Settings'],siteaddr:['Platform','Site address'],debug:['Safety','Debug & Monitor'],sandbox:['Safety','Sandbox & Deploy'],democontent:['Safety','Demo Content'],'notfoundpage':['Safety','404 page'],console:['Console','Console settings'],catalog:['Catalog','Catalog'],import:['Store','Store Import / Export'],newsletter:['Growth & Marketing','Newsletter'],labels:['Growth & Marketing','Product Labels'],pixels:['Growth & Marketing','Marketing Pixels'],meta:['Growth & Marketing','Meta & Facebook'],shopfilters:['Storefront','Shop Filters'],'tr-settings':['Translation','Language settings'],'tr-progress':['Translation','Progress'],'tr-strings':['Translation','Strings'],'tr-machine':['Translation','Machine translation'],'rev-all':['Reviews','All Reviews'],'rev-add':['Reviews','Bulk Tools'],'rev-likes':['Reviews','Bulk Tools'],'rev-assign':['Reviews','Assign / Duplicate'],'rev-io':['Reviews','Review Import / Export'],/* 'rev-capsule' has no sidebar row of its own any more — it and 'rev-badge'
   open the same screen, whose two tabs are the two questions those screens used
   to ask of one set of seven settings. The id stays routable for #rev-capsule
   and ?go=rev-capsule, and it names the screen it actually opens rather than a
   second one. */
'rev-badge':['Reviews','Rating Badge'],'rev-capsule':['Reviews','Rating Badge'],'rev-settings':['Reviews','Review Settings'],orders:['Store','Orders'],'store-settings':['Store','Business Details'],tax:['Store','Tax'],customers:['Store','Customers'],mail:['Emails','All mail settings'],'emails':['Emails','Overview'],'emails-sending':['Emails','Sending & delivery'],'emails-customer':['Emails','Customer emails'],'emails-edit':['Emails','Customer emails'],'emails-branding':['Emails','Design & branding'],'emails-sent':['Emails','Sent mail'],payments:['Store','Payments'],analytics:['Store','Analytics'],search:['Store','Site Search'],'quiz-leads':['Store','Quiz Leads'],'seo':['Store','SEO & Meta'],/* 'blog' has no sidebar row of its own any more — it
   and 'posts' open the same screen. The id stays routable for #blog and
   ?go=blog, and it names that screen honestly rather than a second one. */
'blog':['Content','Blog Posts'],'layout':['Appearance','Product grid'],'bundles':['Appearance','Quantity bundles'],'homepage':['Appearance','Homepage'],'hpcontent':['Appearance','Homepage content'],'productpage':['Appearance','Product page'],'mobilemenu':['Appearance','Mobile menu'],'header':['Appearance','Header'],'mobilehdr':['Appearance','Mobile Header'],'dividers':['Appearance','Section dividers'],'cartpanel':['Appearance','Cart panel'],'acctpanel':['Appearance','Login / Register panel'],'prodstyles':['Appearance','Product styles'],'modules':['Store','Modules'],'megamenu':['Store','Mega Menu'],'shipping':['Store','Delivery & Shipping'],'payship':['Store','Payment & Shipping Rules'],'ecommerce':['Store','Ecommerce'],'pages-store':['Pages','Store pages'],'pages-user':['Pages','User pages'],'pagebanners':['Pages','Page banners'],'pageheader':['Pages','Page header'],'siteapp':['App','Site App'],'posts':['Content','Blog Posts'],'htmlblocks':['Content','HTML Blocks'],'media':['Content','Media Library'],
/* Shoppable video and Instagram (Lanes V2/V3/V4/IG). Absent from this map
   entirely until now, which is why ?go=ugcvideo and #ugcvideo opened the
   dashboard: an id that is not in TITLES does not route at all. Each of the four
   is drawn by its own partial further down this file, so each is also in
   LATE_RENDERED — see the note there for the condition that makes arming one
   safe. Breadcrumbs match what each partial's own go() writes into #crumb and
   #ptitle, because two answers for one screen is how a heading ends up
   disagreeing with the page under it. */
'ugcsections':['Content','Shoppable video'],'ugcvideo':['Content','All clips'],'ugcstyle':['Appearance','Video rail'],'instagram':['Content','Instagram'],'sets':['Catalog','Sets'],'product-tabs':['Catalog','Product tabs'],'pagination':['Catalog','Pagination'],'ownerapp':['App','Owner App'],'banners':['Appearance','Banners'],'setap':['Appearance','Set'],
/* And the seven the new guard found alongside them, every one with a sidebar row
   the owner clicks every day and no deep link at all: a link to any of these
   opened the dashboard. Same fix, same condition, and the strings are copied
   from what each partial's own go() writes so the heading cannot depend on how
   the screen was reached. */
'spotted':['Appearance','#KBeautyBliss Spotted'],'wabutton':['Appearance','WhatsApp button'],'cache':['Platform','Cache'],'cartpage':['Appearance','Cart page'],'checkoutpage':['Appearance','Checkout page'],'routines':['Catalog','Build my routine'],'security':['Store','Security'],'paygw':['Store','Gateway webhooks'],'sitelayout':['Appearance','Site layout'],'slimfooter':['Appearance','Footer'],'gridsections':['Appearance','Grid sections'],'pagewash':['Appearance','Page background'],'searchterms':['Growth & Marketing','Search Terms'],'carttracking':['Growth & Marketing','Cart Tracking'],'seokeywords':['Store','SEO Keywords']};
let cur='dash';
/* `sub` is an optional sub-tab within the screen — only Catalog has them, and
   only the Modules screen passes one (product_sorting links to the Reorder
   tab, which is the screen it actually means). */
function go(id,sub){
  if(FRAME_SRC[id])return renderFrame(id);
  if(id.startsWith('p-'))return renderPlaceholder(id);
  if(id.startsWith('rev-'))return renderReviewFrame(id);
  cur=id;
  if(id==='catalog'){catTab=CAT_TABS.includes(sub)?sub:'products';catSel.clear();}
  if(id==='import')impStep=1;
  $$('.side .nav-item').forEach(b=>b.classList.toggle('on',b.dataset.go===id));syncNavOpen(id);
  const t=TITLES[id]||['Platform',id];$('#crumb').textContent=t[0];$('#ptitle').textContent=t[1];
  $('#content').innerHTML='';
  ({dash:renderDash,updates:renderUpdates,layout:renderLayout,bundles:renderBundles,homepage:renderHomepage,productpage:renderProductPage,mobilemenu:renderMobileMenu,header:renderHeader,search:renderSiteSearch,acctpanel:renderAcctPanel,dividers:renderDividers,mobilehdr:renderMobileHdr,prodstyles:renderProdStyles,newsletter:renderNewsletter,ecommerce:renderEcommerce,modules:renderModules,megamenu:renderMegaMenu,payship:renderPayShip,mail:renderMail,shipping:renderShipping,'pages-store':renderStorePages,'pages-user':renderUserPages,theme:renderTheme,users:renderUsers,settings:renderSettings,siteaddr:renderSiteAddress,debug:renderDebug,sandbox:renderSandbox,console:renderConsole,catalog:renderCatalog,import:renderImport,labels:renderLabels,pixels:renderPixels,meta:renderMeta,shopfilters:renderShopFilters,democontent:renderDemoContent}[id]||renderDash)();
  $('#content').scrollTop=0;$('#side').classList.remove('open');
}

/* ---------- Dashboard ----------
   THE FOURTH TILE IS NOT "Conversion", and used to say it was. It is paid
   orders / all orders: the share of orders that reach a real status rather than
   being cancelled, failed or left as a draft. Conversion is orders per SESSION
   and nothing in this application tracks sessions, so "Conversion 67%" was
   telling the owner that 67% of the people who visited their shop bought
   something. hydrateDash() fills it in under the new name.

   The explanation lives HERE rather than beside the tile because everything
   between this line and the end of the template literal below is inside a
   verbatim region: a Blade comment written in there is not a comment at all,
   it is rendered to the page as visible text. That is not hypothetical — it
   shipped to a screenshot in this lane before being caught. */
function renderDash(){
  $('#content').innerHTML=`<div class="wrap" id="kbbDashWrap">
    <div class="banner">${ic('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>')}<div>This is the <b>foundation</b>. Real numbers appear once your WooCommerce data is imported in <b>Phase 1</b> — the layout, modules, and safety tools below are live and clickable now.</div></div>
    <div class="kpis">
      ${kpi(I.revenue,'#15a85a','var(--accent-soft)','Revenue (30d)','AED —','Awaiting import')}
      ${kpi(I.orders,'#3f6fe0','var(--blue-soft)','Orders','—','2,419 to import')}
      ${kpi(I.cust,'#7b6cf0','var(--violet-soft)','Customers','—','with logins preserved')}
      ${kpi(I.orders,'#e0922f','var(--amber-soft)','Orders completed','—','of all orders placed')}
    </div>
    <div class="grid2" style="margin-top:16px">
      <!-- LANE DH. This card was headed "System health" over a green pill
           reading "All core OK" and six hrow() string literals, hydrated by
           nothing: an App server that was operational, a "Database (SQLite)"
           that was WAL and healthy on an install whose production database is
           MySQL, a Storefront API that was operational, and a Sandbox that was
           in sync with a sandbox this application does not have. Every one of
           them said the same thing on the morning every product page was
           500ing, because none of them was ever measured.

           It is the storefront health CHECK now, and it starts blank. Pressing
           Check now calls GET /admin-api/health, which renders all eight public
           pages in this process and reports what each returned; the rows and
           the pill are written from that answer and from nothing else. It does
           not run on load, because eight page renders is not the price of
           opening the dashboard - see the cost note in HealthApiController.

           The Payments and Email / SMTP rows are gone rather than rewritten.
           This check cannot see whether a gateway or a mail transport is
           configured, and a row that reports it anyway is the defect being
           removed here wearing a different label. Store -> Payments and Store
           -> Mail are the screens that really know. -->
      <div class="card pad">
        <div class="between"><b style="font-size:14px">Storefront health</b><span class="pill grey" id="shPill"><span class="d"></span>Not checked yet</span></div>
        <div id="shRows" style="margin-top:14px">
          <p style="font-size:12.5px;color:var(--ink-soft);line-height:1.55">Nothing has been checked yet. <b>Check now</b> opens all eight public pages of the shop — home, shop, a product, a category, cart, checkout, the journal and the review wall — and reports exactly what each one returns. Run it after every update.</p>
        </div>
        <div class="row" style="margin-top:14px;gap:9px;flex-wrap:wrap">
          <button class="btn sm" id="shRun" onclick="kbbHealthRun()">Check now</button>
          <button class="btn ghost sm" onclick="go('debug')">Open Debug & Monitor →</button>
        </div>
      </div>
      <div class="card pad">
        <b style="font-size:14px">Build progress</b>
        <div style="margin-top:14px;display:flex;flex-direction:column;gap:13px">
          ${prog('Phase 0 · Foundation',100,'In preview')}
          ${prog('Phase 1 · Catalog + Import',0,'Next')}
          ${prog('Phase 2 · Storefront',0,'')}
          ${prog('Phase 3 · Selling + Payments',0,'')}
        </div>
      </div>
    </div>
    <!-- LANE DD. This card was labelled "live feed" and its three rows were
         literals, each dated "just now" for ever: a foundation that had
         initialised, a monitor that was watching and a sandbox that was ready.
         hydrateDash() overwrites them with the store's real recent orders --
         but only when there ARE recent orders, and it returns early when
         /admin-api/stats cannot be reached at all. A quiet shop, or a broken
         endpoint, therefore left an owner reading three invented events under
         the word "live". The rows are the placeholder they always were now,
         and hydrateDash() writes the honest empty line when the store has no
         orders to show rather than leaving whatever was here. -->
    <div class="card pad" style="margin-top:16px">
      <div class="between"><b style="font-size:14px">Recent activity</b><span class="pill grey">orders</span></div>
      <div style="margin-top:8px">
        <p style="font-size:12.5px;color:var(--ink-soft);padding:8px 0">Loading the latest orders…</p>
      </div>
    </div>
  </div>`;
}
const kpi=(icon,col,bg,lbl,val,sub)=>`<div class="kpi"><div class="ic" style="background:${bg};color:${col}">${ic(icon)}</div><div class="lbl">${lbl}</div><div class="val">${val}</div><div class="sub">${sub}</div></div>`;
const hrow=(s,n,m)=>`<div class="hrow"><span class="hd ${s}"></span><b>${n}</b><small>${m}</small></div>`;
const prog=(n,pct,tag)=>`<div><div class="between" style="margin-bottom:6px"><span style="font-size:12.5px;font-weight:600">${n}</span>${tag?`<span class="pill ${pct===100?'green':'grey'}">${tag}</span>`:''}</div><div style="height:7px;background:var(--surface-3);border-radius:99px;overflow:hidden"><div style="height:100%;width:${pct}%;background:linear-gradient(90deg,var(--accent),var(--accent-strong));border-radius:99px;transition:.6s"></div></div></div>`;
/* LANE DD - act() built the three invented rows the Recent activity card
   used to carry, and had no other caller, so it went with them. See the
   note in renderDash(). */

/* ---------- Modules Manager ----------

   THE HARD-CODED MODULES ARRAY THAT USED TO SIT HERE IS GONE, along with the
   `modState` that went with it.

   Nothing read either one. The mock Modules screen they fed was deleted some
   releases ago -- the note further down, beside renderTheme, records why it had
   to go -- and the real screen is renderModules() above, backed by
   ModuleRegistry and /admin-api/modules. The array survived its only reader and
   then sat here as twenty-four lines of prose about this shop that nobody was
   maintaining, because nobody could see it.

   It is DELETED rather than corrected, and that is the point of this note. A
   lane reviewing it this round found two of its entries false -- it filed Meta &
   Facebook under a `built:true` group when renderMeta() says the feature is not
   installed and ModuleRegistry has no key for it, and it described Product
   Labels as offering per-product assignment and scheduling, which is precisely
   the richer mock that was removed for not existing. Those two were the ones
   somebody happened to look at. In the same array: "Invoices -- Invoice &
   packing PDFs", when this host cannot generate a PDF at all and the documents
   are print-ready HTML; "Debug & Monitor -- Error capture, health, AI reports",
   and there are no AI reports.

   Correcting three claims in a structure nothing renders would leave the rest
   to rot and leave the next reader believing the array is maintained. The
   console's real inventory of what exists is ModuleRegistry, and it is one
   place on purpose. */

/* ---------- Core Updates ----------
   Rendered inside the console like every other screen. The standalone page at
   /{admin}/updates stays as the fallback: if this bundle ever fails to load,
   you still need somewhere to install the update that fixes it. */
let UPD = null;

/* The API sits at <app root>/admin-api/updates, one level above the admin path,
   so it keeps working whatever the admin address is renamed to. */
function uBase(){
  return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/updates';
}

/* Read the CSRF cookie here rather than borrow the console's cookie() helper —
   that one is declared inside a later IIFE and is not in scope at this point in
   the file. Depending on it is what produced "cookie is not defined". */
function uToken(){
  const m = document.cookie.split('; ').find(c => c.indexOf('XSRF-TOKEN=') === 0);
  return m ? decodeURIComponent(m.split('=').slice(1).join('=')) : '';
}

async function uApi(path, opts){
  const o = Object.assign({credentials:'same-origin', headers:{}}, opts||{});
  o.headers['X-XSRF-TOKEN'] = uToken();
  o.headers['Accept'] = 'application/json';

  const url = uBase() + path;
  let r;
  try {
    r = await fetch(url, o);
  } catch (e) {
    return {ok:false, data:{errors:['Could not reach ' + url + ' — ' + e.message]}};
  }

  const text = await r.text();
  try {
    return {ok:r.ok, data:JSON.parse(text)};
  } catch (e) {
    // A non-JSON body means an error page, not an API response. Show the first
    // part of it rather than failing silently on a blank screen.
    return {ok:false, data:{errors:[
      'HTTP ' + r.status + ' from ' + url,
      text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300)
    ]}};
  }
}

async function renderUpdates(){
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Core Updates</h2>
    <p>Install patches and new features. Every package is verified, backed up and health-checked before it counts as applied.</p></div>
    <div class="card" style="padding:22px">Loading…</div></div>`;

  let res;
  try {
    res = await uApi('');
  } catch (e) {
    res = {ok:false, data:{errors:['Unexpected error: ' + e.message]}};
  }

  if(!res.ok || !res.data || !res.data.releases){
    const errs = (res.data && res.data.errors) || ['The updates endpoint did not return data.'];
    $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Core Updates</h2></div>
      <div class="card" style="padding:22px;border-color:#f0c2c2;background:#fdf3f3">
        <b>Could not load updates.</b>
        <ul style="margin:8px 0 0 18px">${errs.map(e=>`<li style="word-break:break-word">${e}</li>`).join('')}</ul>
        <p class="mdesc" style="margin-top:12px">The standalone page still works:
          <a href="${window.location.pathname.replace(/\/+$/,'')}/updates?fallback=1">open the fallback page</a>.</p>
      </div></div>`;
    return;
  }

  UPD = res.data;
  paintUpdates();
}

function paintUpdates(msg, err){
  const d = UPD;
  const p = d.pending;

  const banner = msg ? `<div class="card" style="padding:14px 18px;border-color:#b7e2c6;background:#f2fbf5;margin-bottom:16px">${escHtml(msg)}</div>` : '';
  const errors = err && err.length ? `<div class="card" style="padding:14px 18px;border-color:#f0c2c2;background:#fdf3f3;margin-bottom:16px">
      <b>Nothing was changed.</b><ul style="margin:8px 0 0 18px">${err.map(e=>`<li>${escHtml(e)}</li>`).join('')}</ul></div>` : '';

  const upload = p ? `
    <div class="card" style="padding:22px;border-color:#b7e2c6">
      <div class="between"><div><b style="font-size:15px">${escHtml(p.name)} ${escHtml(p.version)}</b>
        <div class="mdesc" style="margin-top:4px">${escHtml(p.notes||'')}</div></div>
        <span class="pill green">Ready to apply</span></div>
      <p style="margin:14px 0 6px"><b>${p.changes.length} files</b> will change.
        ${p.migrations ? 'This update also changes the database — a full dump is taken first.' : ''}</p>
      <details><summary style="cursor:pointer;color:#2563eb">Show every file</summary>
        <div style="max-height:220px;overflow:auto;background:#f8fafc;border-radius:8px;padding:10px;margin-top:8px;font:12px ui-monospace,monospace">
          ${p.changes.map(c=>`<div><span class="pill ${c.action==='add'?'green':'grey'}" style="font-size:9px;padding:1px 6px">${c.action}</span> ${c.path}</div>`).join('')}
        </div></details>
      <div style="background:#f8fafc;border-left:3px solid #16a34a;padding:10px 14px;margin:14px 0;font-size:13px">
        Every file being replaced is backed up first. Afterwards the site is checked over HTTP — if it does not answer correctly the update is undone automatically.
      </div>
      <div class="row" style="gap:8px;align-items:center;flex-wrap:wrap">
        <button class="btn primary" onclick="uApply()">Apply update</button>
        <button class="btn" onclick="uCancel()">Cancel</button>
      </div>
    </div>`
  : `
    <div class="card" style="padding:22px">
      <b style="font-size:15px">Upload an update</b>
      <p class="mdesc" style="margin:6px 0 14px">The package is checked first — file list, checksums, permitted paths. Nothing is written until you confirm.</p>
      <div class="row" style="gap:8px;align-items:center;flex-wrap:wrap">
        <input type="file" id="uFile" accept=".zip" class="inp">
        <button class="btn primary" onclick="uCheck()">Check package</button>
      </div>
    </div>`;

  const history = `
    <div class="sec-title">History</div>
    <div class="card" style="padding:0;overflow:hidden">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr>${['Version','Status','Files','When',''].map(h=>`<th style="text-align:left;padding:10px 14px;background:#f8fafc;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b">${h}</th>`).join('')}</tr></thead>
        <tbody>${d.releases.length ? d.releases.map(r=>`<tr style="border-top:1px solid #eef2f7">
          <td style="padding:10px 14px"><b>${escHtml(r.version)}</b><div class="mdesc">${escHtml(r.name)}</div>
            ${r.superseded_by?`<div class="mdesc" style="color:#b45309">⚠ Superseded by ${escHtml(r.superseded_by)} — see that version's notes</div>`:''}</td>
          <td style="padding:10px 14px"><span class="pill ${r.status==='applied'?'green':'grey'}">${escHtml(r.status.replace('_',' '))}</span>
            ${r.error?`<div class="mdesc" style="max-width:520px">${escHtml(r.error)}</div>`:''}</td>
          <td style="padding:10px 14px">${r.files}</td>
          <td style="padding:10px 14px" class="mdesc">${r.when||''}</td>
          <td style="padding:10px 14px">${r.has_archive?`<a href="${uBase()}/${r.id}/download" class="btn small">Download</a>`:''}</td></tr>`).join('')
          : `<tr><td colspan="5" style="padding:14px" class="mdesc">No updates yet.</td></tr>`}</tbody>
      </table>
    </div>`;

  const restore = `
    <div class="sec-title">Restore a previous version</div>
    <div class="card" style="padding:0;overflow:hidden">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <tbody>${d.backups.length ? d.backups.map(b=>`<tr style="border-top:1px solid #eef2f7">
          <td style="padding:10px 14px"><code>${b.id}</code><div class="mdesc">${b.created_at||''}</div></td>
          <td style="padding:10px 14px" class="mdesc">${b.replaced} files restored, ${b.added} removed${b.database?' · + database dump':''}</td>
          <td style="padding:10px 14px;text-align:right">
            <button class="btn" onclick="uRestore('${b.id}')">Restore</button></td></tr>`).join('')
          : `<tr><td style="padding:14px" class="mdesc">No backups yet — the first update creates one.</td></tr>`}</tbody>
      </table>
    </div>`;

  const path = `
    <div class="sec-title">Admin address</div>
    <div class="card" style="padding:22px">
      <p class="mdesc" style="margin-top:0">The console currently answers at <code>/${d.admin_path}</code>.
      Changing it makes the old address return 404 — not a redirect, which would only announce where it moved.</p>
      ${d.admin_path_locked
        ? `<div style="background:#fffaf0;border:1px solid #f0d391;border-radius:8px;padding:12px 15px;font-size:13px">
             <b>Locked by .env.</b> <code>KBB_ADMIN_PATH</code> is set there and always wins. Remove that line to manage it here.</div>`
        : `<div style="background:#f8fafc;border-left:3px solid #16a34a;padding:10px 14px;margin-bottom:14px;font-size:13px">
             A secret address stops automated scanners. It is not a security control — anyone who sees one admin link has it.
             Your password and the rate limiter are the real protection.<br><br>
             <b>If you ever lock yourself out</b>, set <code>KBB_ADMIN_PATH</code> in <code>.env</code>; it overrides whatever is stored here.
           </div>
           <div class="row" style="gap:8px;align-items:center;flex-wrap:wrap">
             <input type="text" id="uPath" value="${d.admin_path}" class="inp" style="width:220px">
             <input type="password" id="uPathPass" placeholder="Admin password" class="inp" style="width:200px">
             <button class="btn primary" onclick="uSavePath()">Change address</button>
           </div>`}
    </div>`;

  $('#content').innerHTML = `<div class="wrap">
    <div class="page-head"><h2>Core Updates</h2>
      <p>Version <b>${d.version}</b> · ${d.signing?.emergency
          ? `<span style="color:#b91c1c;font-weight:700">${escHtml(d.signing.label)}</span>`
          : (d.signing?.mode === 'required' || d.signed)
            ? escHtml(d.signing?.label || 'signed packages only')
            : `<span style="color:#b45309">${escHtml(d.signing?.label || 'unsigned packages accepted')}</span>`}${
        d.signing?.keys ? ` <span class="muted" style="font-size:12px">· trusted key ${escHtml((d.signing.fingerprints||[]).join(', '))}</span>` : ''}</p></div>
    ${banner}${errors}${upload}${history}${restore}${path}
  </div>`;
}

async function uCheck(){
  const f = $('#uFile').files[0];
  if(!f){ toast('Choose a .zip first'); return; }
  const fd = new FormData(); fd.append('package', f);
  toast('Checking package…');
  const r = await uApi('/check', {method:'POST', body:fd});
  const s = await uApi(''); UPD = s.data;
  paintUpdates(r.ok?'Package verified. Review the file list, then apply.':null, r.ok?null:(r.data?.errors||['Upload failed.']));
}

async function uApply(){
  toast('Applying — do not close this tab…');
  const r = await uApi('/apply', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({})});
  const s = await uApi(''); UPD = s.data;
  paintUpdates(r.data?.message||null, r.ok?null:(r.data?.errors||null));
}

async function uCancel(){
  await uApi('/cancel', {method:'POST'});
  const s = await uApi(''); UPD = s.data;
  paintUpdates('Package discarded.');
}

async function uRestore(id){
  const r = await uApi('/restore/'+encodeURIComponent(id), {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({})});
  const s = await uApi(''); UPD = s.data;
  paintUpdates(r.data?.message||null, r.ok?null:(r.data?.errors||null));
}

async function uSavePath(){
  const v = $('#uPath').value, pass = $('#uPathPass').value;
  if(!pass){ toast('Enter your password'); return; }
  const r = await uApi('/admin-path', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({admin_path:v, password:pass})});
  if(r.ok && r.data?.redirect){ toast(r.data.message); setTimeout(()=>{ window.location.href = r.data.redirect; }, 1200); return; }
  const s = await uApi(''); UPD = s.data;
  paintUpdates(null, r.data?.errors||['Could not change the address.']);
}


/* ---------- Pages ----------
   Split deliberately. Store pages are routes the application owns and cannot be
   deleted; user pages are rows in the pages table. Showing them in one list is
   how someone ends up trying to delete /checkout. */
async function pagesApi(path){
  const base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/pages';
  try{
    const r = await fetch(base + path, {credentials:'same-origin', headers:{Accept:'application/json'}});
    if(!r.ok) return null;
    return await r.json();
  }catch(e){ return null; }
}

function pagesTable(rows, system){
  if(!rows.length) return `<div class="card" style="padding:22px"><span class="mdesc">No pages yet.</span></div>`;
  return `<div class="card" style="padding:0;overflow:hidden">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead><tr>${['Page','Address', system?'What it does':'Status', ''].map(h=>`<th style="text-align:left;padding:10px 14px;background:#f8fafc;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b">${h}</th>`).join('')}</tr></thead>
      <tbody>${rows.map(p=>`<tr style="border-top:1px solid #eef2f7">
        <td style="padding:10px 14px"><b>${p.name}</b>${system?' <span class="pill grey" style="font-size:9px;padding:1px 7px">system</span>':''}</td>
        <td style="padding:10px 14px"><code>${p.path}</code></td>
        <td style="padding:10px 14px" class="mdesc">${system ? (p.note||'') : (p.status||'')}${!system&&p.updated?` · ${p.updated}`:''}</td>
        <td style="padding:10px 14px;text-align:right"><a class="btn small" href="${p.url}" target="_blank" rel="noopener">View</a></td>
      </tr>`).join('')}</tbody>
    </table></div>`;
}

async function renderStorePages(){
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Store pages</h2>
    <p>Built into the storefront. These addresses are fixed — they cannot be created or deleted here, only viewed.</p></div>
    <div class="card" style="padding:22px">Loading…</div></div>`;
  const d = await pagesApi('/store');
  if(!d){ $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load store pages.</div></div>`; return; }
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Store pages</h2>
    <p>Built into the storefront. These addresses are fixed — they cannot be created or deleted here, only viewed.</p></div>
    ${pagesTable(d.pages, true)}</div>`;
}

async function renderUserPages(){
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>User pages</h2>
    <p>Pages you create — About, Delivery, FAQs, Terms. Anything added here appears in this list.</p></div>
    <div class="card" style="padding:22px">Loading…</div></div>`;
  const d = await pagesApi('/user');
  if(!d){ $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load user pages.</div></div>`; return; }
  $('#content').innerHTML = `<div class="wrap"><div class="between" style="margin-bottom:16px">
      <div class="page-head" style="margin:0"><h2>User pages</h2><p>Pages you create. ${d.pages.length} so far.</p></div>
      <button class="btn" onclick="toast('Page editor arrives with the CMS in Phase 11')">${ic('<path d="M12 5v14M5 12h14"/>')} New page</button></div>
    ${pagesTable(d.pages, false)}</div>`;
}


/* ---------- Appearance · Product grid ----------------------- Lane GRID ------

   32 card templates, a live preview of the one that is picked, and the controls
   that decide what a card shows and how far apart cards sit.

   ── WHY EVERY SWATCH WAS A BLANK PINK RECTANGLE ─────────────────────────────

   Not a CSS failure and not a missing stylesheet. This screen NEVER RENDERED A
   PREVIEW AT ALL. It emitted

       <span class="skinsw-p" data-skin-preview="${s.key}"></span>

   — an empty span — and `data-skin-preview` appeared exactly ONCE in the whole
   repository: right there. Nothing selected it, nothing filled it. What the
   owner photographed was `.skinsw-p`'s own placeholder, a 52px block with
   `linear-gradient(135deg,#ffe3ec,#ffc6da)` behind it, 32 times over.

   The same 32 designs rendered as real cards in the Homepage grid-style popup
   because that call site does the two things this one did not:

       skinCard(k.key)          the storefront's own card markup
       <span class="skinprev">  the ONLY scope the card CSS is written against

   The admin console links no external stylesheet — the whole sheet is inline —
   so `.kbb-card` outside `.skinprev` is unstyled markup. Both halves are
   needed, and the swatch now carries both.

   ── WHERE THE CONTROLS CAME FROM, AND WHY NOT ONE OF THEM IS NEW ────────────

   The owner asked for "full controls like spacings, turn on off elements etc."
   Every one of them already existed, on two other screens:

       what a card shows   Appearance → Product styles → Card content
                           ProductStyles::SCHEMA — show_brand, show_category,
                           show_rating, show_was_price, show_discount, show_new,
                           show_cart
       spacing             Appearance → Site layout → Product grid
                           SiteLayout::SCHEMA — `gap` ("Gap between cards",
                           → --kbb-gap) and `tile` ("Smallest card")

   So this screen SURFACES them: the same keys, POSTed to the same two
   endpoints, validated by the same two schemas. It does not define a setting of
   its own. A second key for one question is the thing ProductStyles::SCHEMA's
   own header calls "what this shop keeps paying for", and `grid_gap` was
   DELETED from that schema for exactly this reason — the note there records
   that --kbb-gap is a term in the track arithmetic in kbb.css, so a duplicate
   gap slider would silently change the COLUMN COUNT too.

   ── THE PREVIEW IS BUILT, NOT STRING-SPLICED, AND NOTHING IS MEASURED ───────

   skinCard() returns one `.kbb-pgrid` wrapping one `.kbb-card`. A grid needs
   several, and the way NOT to get them is to re-emit the card markup here —
   that is a second copy to drift. So the card node is cloned. Column count and
   gap reach the grid as custom properties read by `.pgprev` in the sheet; no
   getBoundingClientRect, no offsetWidth, no clientWidth (rule 4).            */

let PGL = null;      /* the /admin-api/layout payload */
let PGS = {};        /* ProductStyles values, by key */
let PGSL = {};       /* SiteLayout values, by key */
/*
 * ── THE VALUES AS LOADED, SO A SAVE CAN SEND ONLY WHAT MOVED ────────────────
 *
 * Measured: pressing Save after switching ONE thing used to POST all seven
 * card-content keys and both spacing keys. Every one carried the value it
 * already had, so nothing on the shop changed -- and that is not good enough.
 * Writing a key at its current value turns "never saved, follows the default"
 * into "stored at 220", and a STORED ROW IS WHAT STOPS A NEW DEFAULT FROM
 * BEING SEEN. ProductStyles' own 2.60.330 migration exists to delete exactly
 * such rows. So a control the owner never touched leaves no row behind.
 */
let PGS0 = {}, PGSL0 = {};
let PGSKIN = '', PGCOLS = 4;
let PGDIRTY = { layout: false, styles: false, space: false };
let PGBOUND = false;

/* The eight Product-styles keys this screen surfaces, and the class each one
   turns into. Same pairs as psPreview(); same keys as ProductStyles::SCHEMA. */
const PG_CONTENT = [
  ['show_new',       'New badge',              'On products with no reviews yet.'],
  ['show_discount',  'Discount badge',         'The -30% flash on the photo.'],
  ['show_category',  'Category label',         'The small eyebrow above the name.'],
  ['show_brand',     'Brand name',             'The line above the product name.'],
  ['show_rating',    'Stars and review count', ''],
  ['show_was_price', 'Was price',              'The struck-through original.'],
  ['show_soldout',   'Sold-out label',         'A “Sold out” pill on the photo of an out-of-stock product, and “Sold out” on its button.'],
  ['show_cart',      'Add to cart button',     ''],
];
const PG_NOCLASS = {
  show_brand: 'pc-nobrand', show_category: 'pc-nocat', show_rating: 'pc-norate',
  show_was_price: 'pc-nowas', show_discount: 'pc-nodisc', show_new: 'pc-nonew',
  show_cart: 'pc-nocart',
  /* (Lane PX) No rule reads this class: the preview card is never sold out.
     It is here because this map is also the list of switches the panel saves. */
  show_soldout: 'pc-nosoldout',
};
/* key, label, min, max, step, unit — the same bounds SiteLayout::SCHEMA sets,
   so a value this screen offers is one that endpoint will store. */
const PG_SPACE = [
  ['gap',  'Gap between cards', 6,   32,  2,  'px',
   'The space between cards, across and down. The /shop listing and the related row keep their own 18px.'],
  ['tile', 'Smallest card',     120, 420, 10, 'px',
   'The column count is worked out from this and the width the grid really has. Smaller means more columns, sooner.'],
];

/* ── SPACE INSIDE EACH CARD ───────────────────────────────────── 2.60.371 ──
   The owner, 3 October, with a screenshot of this screen's Spacing card and
   arrows under the name, the price and the button: "i asked you several times
   to give me spacing controls for grid card, spacing between image, title,
   pricing, rating, add to cart … please give me on the grid page setting as
   marked." They existed — on Appearance → Product styles → Spacing & type,
   which is not where he looks. So this screen SURFACES them, the same way it
   surfaces the seven Card content switches: the same ProductStyles keys,
   POSTed to the same endpoint, bounded by the bounds that endpoint sends.
   Not a second copy of a setting. Each row is [phone key, desktop key, label,
   help]; the bounds come from the field itself (pgSpaceDef). */
const PG_CARD_SPACE = [
  ['card_pad_m',       'card_pad_d',       'Inside the card',          'The space around the text, at the sides and the bottom.'],
  ['card_gap_img_m',   'card_gap_img_d',   'Photo → first line',       'Between the photograph and the brand or the name.'],
  ['card_gap_brand_m', 'card_gap_brand_d', 'Brand → name',             'Under the brand line.'],
  ['card_gap_rate_m',  'card_gap_rate_d',  'Name → stars',             'Above the stars, on products that have reviews.'],
  ['card_gap_price_m', 'card_gap_price_d', 'Above the price',          'Between the name (or the stars) and the price.'],
  ['card_gap_cart_m',  'card_gap_cart_d',  'Price → Add to cart',      'Between the price and the button.'],
];
let PGSDEF = {};     /* ProductStyles range fields, by key: {min,max,step,def} */
/* 2.60.380, the owner: "give control to set the pricing font size, and cut
   price also ... separate controls for desktop and mobile". The same
   ProductStyles selects as Product styles → Spacing & type, surfaced here the
   way PG_CARD_SPACE surfaces the gaps. [phone key, desktop key, label, help] */
const PG_CARD_TYPE = [
  ['card_fs_price_m', 'card_fs_price_d', 'Price', 'The price, and the sale price beside a cut one.'],
  ['card_fs_reg_m',   'card_fs_reg_d',   'Cut price', 'The struck-through old price.'],
];
let PGSSEL = {};     /* ProductStyles select fields, by key: {opts:[value...], def} */
function pgSpaceDef(k){
  const f = PGSDEF[k];
  return f && Number.isFinite(f.min) && Number.isFinite(f.max) ? f : null;
}

/* ── EVERY VALUE THAT REACHES MARKUP OR A STYLE ATTRIBUTE IS CHECKED ─────────
   /admin-api is behind the console's auth, but rule 5 is "secure by
   construction, not by intention": a select stores one of its own options or
   the default, and nothing is printed unescaped that is not a constant.
   skinCard() interpolates its argument straight into `data-skin="…"` without
   escaping, so the skin is checked for MEMBERSHIP of the list the server sent
   rather than escaped. */
function pgSkinOk(k){ return !!(PGL && PGL.skins && PGL.skins.some(s => s.key === k)); }
function pgHex(v, fb){ return (typeof v === 'string' && /^#[0-9a-fA-F]{6}$/.test(v)) ? v : fb; }
function pgNum(v, min, max, fb){ const n = Number(v); return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : fb; }
function pgBool(v){ return v === true || v === 1 || v === '1'; }

/* ModuleSchema::tabs() nests fields under tabs; this screen wants them by key. */
function pgFlat(tabs){
  const o = {};
  (tabs || []).forEach(t => (t.fields || []).forEach(f => { o[f.key] = f.value; }));
  return o;
}

function pgApiRoot(){
  return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
}
function pgGet(url){
  return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(r => r.ok ? r.json() : null).catch(() => null);
}

/* THE PREVIEW. Repainted on a skin click, a toggle, a slider and the Columns
   select — everything the panel claims to be showing. */
function pgPaint(){
  const el = document.getElementById('pgPrev');
  if (!el) return;

  /* Element on/off, as the storefront spells it: the class sits on an ANCESTOR
     of the card, which `.pgprev.skinprev` is. */
  const off = Object.keys(PG_NOCLASS)
    .filter(k => k in PGS && !pgBool(PGS[k]))
    .map(k => PG_NOCLASS[k]);
  el.className = ('skinprev pgprev ' + off.join(' ')).trim();

  /* Custom properties only — the same ones psPreview() sets, plus the two this
     panel adds for the grid itself. Every one is range- or pattern-checked. */
  const ratio = PS_RATIO[PGS.image_ratio] || '1/1';
  el.setAttribute('style', [
    '--pg-cols:' + pgNum(PGCOLS, 1, 6, 4),
    '--pg-gap:' + pgNum(PGSL.gap, 6, 32, 16) + 'px',
    '--kbb-sale:' + pgHex(PGS.sale_colour, '#E23B57'),
    '--kbb-new:' + pgHex(PGS.new_colour, '#1F9D55'),
    '--kbb-price:' + pgHex(PGS.price_colour, '#2A2228'),
    '--kbb-star:' + pgHex(PGS.star_colour, '#E8A33D'),
    '--kbb-cart-bg:' + pgHex(PGS.cart_bg, '#E0567B'),
    '--kbb-cart-fg:' + pgHex(PGS.cart_fg, '#FFFFFF'),
    '--kbb-radius:' + pgNum(PGS.card_radius, 0, 26, 14) + 'px',
    '--kbb-ratio:' + ratio,
    '--kbb-name-lines:' + pgNum(PGS.name_lines, 0, 4, 0),
  ].join(';'));

  /* One card from skinCard(), then clones. Cloning rather than re-emitting the
     markup is what stops this preview drifting from the shop's card. */
  const skin = pgSkinOk(PGSKIN) ? PGSKIN : (PGL && PGL.current) || 'classic';
  const host = document.createElement('div');
  host.innerHTML = skinCard(skin);
  const grid = host.firstElementChild;
  const card = grid && grid.firstElementChild;
  el.textContent = '';
  if (!grid || !card) return;

  /* ONE CARD, AT A REAL CARD'S WIDTH (2.60.374). The owner: "please give me
     only one card preview on backend". A grid of eight 120px thumbnails made a
     4px move in a gap impossible to see; one card at shop size makes it plain.
     `--pg-cols:1` on the grid, and the frame capped at a shop card's width. */
  grid.style.setProperty('--pg-cols', '1');
  grid.style.maxWidth = '300px';
  grid.style.margin = '0 auto';
  el.appendChild(grid);

  /* Space inside each card, at the DESKTOP values — the same declarations
     ProductStyles::cardCss() writes for the shop, scoped to this preview. Only
     numbers reach the sheet, each clamped to its field's own bounds. */
  const n = k => { const f = pgSpaceDef(k); return f ? pgNum(PGS[k], f.min, f.max, f.def) : null; };
  const T = '#pgPrev .kbb-tile', r = [];
  const pad = n('card_pad_d'), img = n('card_gap_img_d'), br = n('card_gap_brand_d'),
        rt = n('card_gap_rate_d'), pr = n('card_gap_price_d'), ca = n('card_gap_cart_d');
  if (pad !== null) r.push(`${T} .cb{padding-inline:${pad}px;padding-bottom:${pad}px}`);
  if (img !== null) r.push(`${T} .cb{padding-top:${img}px}`);
  if (br !== null) r.push(`${T} .kbb-card-brand{margin-bottom:${br}px}`);
  if (rt !== null) r.push(`${T} .kbb-card-rate{margin-top:${rt}px}`);
  /* The same split ProductStyles::cardCss() makes: SPACE above the price and
     below it (margins), never padding inside a price capsule; the Showcase
     family keeps its padding and the button's top margin. */
  const SC = '#pgPrev .kbb-pgrid[data-skin^="showcase"] .kbb-tile', NS = '#pgPrev .kbb-pgrid:not([data-skin^="showcase"]) .kbb-tile';
  if (pr !== null) r.push(`${SC} .cp{padding-top:${pr}px;margin-top:0}${NS} .cp{margin-top:${pr}px}`);
  if (ca !== null) r.push(`${SC} .kbb-card-cart{margin-top:${ca}px}${NS} .cp{margin-bottom:${ca}px}${NS} .kbb-card-cart{margin-top:0}`);
  /* Price sizes, desktop: like cardCss(), only a MOVED value is printed, and
     only one of the select's own options. */
  const fs = k => { const f = PGSSEL[k]; const v = f ? String(PGS[k] ?? f.def) : ''; return f && v !== f.def && f.opts.includes(v) && /^\d+(\.\d+)?px$/.test(v) ? v : null; };
  const fp = fs('card_fs_price_d'), fr = fs('card_fs_reg_d');
  if (fp) r.push(`${T} .kbb-card-price{font-size:${fp}}`);
  if (fr) r.push(`#pgPrev .kbb-pgrid.kbb-pgrid[data-skin] .kbb-tile .cp .kbb-card-reg{font-size:${fr}}`);
  if (r.length) { const st = document.createElement('style'); st.textContent = r.join(''); el.appendChild(st); }
}

function pgDirty(on){
  if (on) { PGDIRTY[on] = true; }
  const m = document.getElementById('layoutMsg');
  if (m) { m.classList.remove('ok'); m.textContent = 'Unsaved changes'; }
}

async function renderLayout(){
  const root = pgApiRoot();
  const base = root + '/admin-api/layout';
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Product grid</h2><p>Loading…</p></div></div>`;

  let d;
  try { d = await (await fetch(base, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json(); }
  catch(e){ $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load layout settings.</div></div>`; return; }

  /* The two screens this one surfaces. A failure here is NOT fatal and NOT
     faked: the group is simply not drawn, so nothing offers a control that
     cannot be saved. */
  const [psd, sld] = await Promise.all([
    pgGet(root + '/admin-api/product-styles'),
    pgGet(root + '/admin-api/site-layout'),
  ]);

  PGL = d;
  PGS = psd ? pgFlat(psd.tabs) : {};
  PGSDEF = {};
  PGSSEL = {};
  (psd && psd.tabs || []).forEach(t => (t.fields || []).forEach(f => {
    const o = f.options || {};
    if (f.type === 'range') PGSDEF[f.key] = { min: +o.min, max: +o.max, step: +o.step || 1, def: +f.default };
    if (f.type === 'select') PGSSEL[f.key] = { opts: Array.isArray(o) ? o.map(x => (x && typeof x === 'object') ? String(x.value) : String(x)) : Object.keys(o), def: String(f.default) };
  }));
  PGSL = sld ? pgFlat(sld.tabs) : {};
  PGS0 = Object.assign({}, PGS);
  PGSL0 = Object.assign({}, PGSL);
  PGSKIN = pgSkinOk(d.current) ? d.current : (d.skins[0] && d.skins[0].key) || 'classic';
  PGCOLS = pgNum(d.columns, 1, 6, 4);
  PGDIRTY = { layout: false, styles: false, space: false };

  /* THE FIX: a real card, in the design the swatch names, inside `.skinprev`
     so the card CSS applies at all. `data-pgskin` rather than `data-skin`
     BECAUSE skinCard() emits `data-skin` itself — the old handler's
     `closest('[data-skin]')` and `querySelectorAll('[data-skin]')` would now
     match the previews as well as the buttons. */
  const swatches = d.skins.map(s => `<button class="skinsw${s.key === PGSKIN ? ' on' : ''}" type="button" data-pgskin="${escAttr(s.key)}" title="${escAttr(s.label)}">
      <span class="skinsw-p skinprev" data-skin-preview="${escAttr(s.key)}">${skinCard(s.key)}</span><span class="skinsw-l">${escHtml(s.label)}</span></button>`).join('');

  const codes = d.shortcodes.map(c=>`<tr style="border-top:1px solid #eef2f7">
      <td style="padding:8px 12px"><code style="background:#f1f5f9;padding:2px 6px;border-radius:5px">${c.code.replace(/</g,'&lt;')}</code>
      <button class="btn small" style="margin-left:8px" data-copy="${c.code.replace(/"/g,'&quot;')}">Copy</button></td>
      <td style="padding:8px 12px" class="mdesc">${c.desc}</td></tr>`).join('');

  const contentRows = psd ? PG_CONTENT.map(([k, label, help]) => `
      <div class="pgrow"><div class="pgrow-l"><b>${escHtml(label)}</b>${help ? `<span>${escHtml(help)}</span>` : ''}</div>
        <span class="ectog${pgBool(PGS[k]) ? ' on' : ''}" data-pgtog="${escAttr(k)}" role="switch"
              aria-checked="${pgBool(PGS[k])}" aria-label="${escAttr(label)}" tabindex="0"></span></div>`).join('') : '';

  const spaceRows = sld ? PG_SPACE.map(([k, label, min, max, step, unit, help]) => {
      const v = pgNum(PGSL[k], min, max, min);
      return `<div class="pgrow"><div class="pgrow-l"><b>${escHtml(label)}</b>${help ? `<span>${escHtml(help)}</span>` : ''}</div>
        <span class="pgrng"><input type="range" min="${min}" max="${max}" step="${step}" value="${v}"
          data-pgnum="${escAttr(k)}" aria-label="${escAttr(label)}"><i id="pgv-${escAttr(k)}">${v}${escHtml(unit)}</i></span></div>`;
    }).join('') : '';

  const cardSpaceRows = psd ? PG_CARD_SPACE.filter(([m, d]) => pgSpaceDef(m) && pgSpaceDef(d)).map(([m, d, label, help]) => {
      const one = (k, dev) => { const f = pgSpaceDef(k); const v = pgNum(PGS[k], f.min, f.max, f.def);
        return `<label class="pgdev"><em>${dev}</em><span class="pgrng"><input type="range" min="${f.min}" max="${f.max}" step="${f.step}" value="${v}"
          data-pgcs="${escAttr(k)}" aria-label="${escAttr(label + ' · ' + dev)}"><i id="pgv-${escAttr(k)}">${v}px</i></span></label>`; };
      return `<div class="pgrow pgrow-cs"><div class="pgrow-l"><b>${escHtml(label)}</b><span>${escHtml(help)}</span></div>
        <div class="pgdevs">${one(m, 'Phone')}${one(d, 'Desktop')}</div></div>`;
    }).join('') : '';

  const pgSel = k => { const f = PGSSEL[k]; if (!f) return null; const v = String(PGS[k] ?? f.def); return f.opts.includes(v) ? v : f.def; };
  const cardTypeRows = psd ? PG_CARD_TYPE.filter(([m, d]) => PGSSEL[m] && PGSSEL[d]).map(([m, d, label, help]) => {
      const one = (k, dev) => `<label class="pgdev"><em>${dev}</em><select data-pgct="${escAttr(k)}" aria-label="${escAttr(label + ' size · ' + dev)}" style="padding:5px 8px;border:1px solid #dbe3ec;border-radius:7px">${PGSSEL[k].opts.map(o => `<option value="${escAttr(o)}"${o === pgSel(k) ? ' selected' : ''}>${escHtml(o)}</option>`).join('')}</select></label>`;
      return `<div class="pgrow pgrow-cs"><div class="pgrow-l"><b>${escHtml(label)}</b><span>${escHtml(help)}</span></div>
        <div class="pgdevs">${one(m, 'Phone')}${one(d, 'Desktop')}</div></div>`;
    }).join('') : '';

  $('#content').innerHTML = `<div class="wrap">
    <div class="page-head"><h2>Product grid</h2><p>Pick the card template used across the shop, category pages and every <code>[kbb_products]</code> shortcode. The panel on the right is the design you have picked, with the settings below applied.</p></div>

    <div class="pgwrap">
      <div class="pgcol">
        <div class="card" style="padding:18px">
          <div class="between" style="margin-bottom:12px">
            <b style="font-size:13px">Skin</b>
            <label style="font-size:12px;color:#64748b">Columns
              <select id="gridCols" style="margin-left:6px;padding:4px 8px;border:1px solid #dbe3ec;border-radius:7px">
                ${[1,2,3,4,5,6].map(n=>`<option value="${n}"${n===PGCOLS?' selected':''}>${n}</option>`).join('')}
              </select></label>
          </div>
          <div class="skingrid" id="pgSkins">${swatches}</div>
        </div>

        ${psd ? `<div class="card" style="padding:18px;margin-top:16px">
          <b style="font-size:13px;display:block;margin-bottom:4px">What each card shows</b>
          <p class="pgwhere">Switch any part of the card off. These are the same seven settings as <b>Appearance → Product styles → Card content</b> — one setting each, shown in both places, not a second copy.</p>
          ${contentRows}</div>` : ''}

        ${sld ? `<div class="card" style="padding:18px;margin-top:16px">
          <b style="font-size:13px;display:block;margin-bottom:4px">Spacing</b>
          <p class="pgwhere">The same two settings as <b>Appearance → Site layout → Product grid</b>, where the rest of the grid arithmetic lives (never fewer than, never more than, and pinning an exact count).</p>
          ${spaceRows}</div>` : ''}

        ${cardSpaceRows ? `<div class="card" style="padding:18px;margin-top:16px" id="pgCardSpace">
          <b style="font-size:13px;display:block;margin-bottom:4px">Space inside each card</b>
          <p class="pgwhere">Photo, brand, name, stars, price and Add to cart — a phone and a desktop set apart. The same settings as <b>Appearance → Product styles → Spacing &amp; type</b>, shown in both places, not a second copy. Every product grid on the shop uses them. The preview shows the desktop values.</p>
          ${cardSpaceRows}</div>` : ''}

        ${cardTypeRows ? `<div class="card" style="padding:18px;margin-top:16px" id="pgCardType">
          <b style="font-size:13px;display:block;margin-bottom:4px">Price text size</b>
          <p class="pgwhere">The price and the cut (struck-through) price, a phone and a desktop set apart. The same settings as <b>Appearance → Product styles → Spacing &amp; type</b>. The preview shows the desktop sizes.</p>
          ${cardTypeRows}</div>` : ''}

        <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <button class="btn primary" id="saveLayout">Save</button>
          <span class="mdesc" id="layoutMsg"></span>
        </div>
      </div>

      <aside class="pgside">
        <div class="pgpv-in">
          <div class="pgpv-hd"><b>Preview</b><span id="pgPvName"></span></div>
          <div class="skinprev pgprev" id="pgPrev"></div>
        </div>
        <p class="pgpv-note">The card the shop draws, in the design selected on the left, with the switches and spacing below applied. Nothing here is saved until you press <b>Save</b>.</p>
      </aside>
    </div>

    <div class="page-head" style="margin-top:26px"><h2>Shortcodes</h2><p>Paste any of these into a page, post or HTML block.</p></div>
    <div class="card" style="padding:0;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead><tr><th style="text-align:left;padding:10px 12px;background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b">Shortcode</th>
      <th style="text-align:left;padding:10px 12px;background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b">What it does</th></tr></thead>
      <tbody>${codes}</tbody></table></div>
  </div>`;

  pgName();
  pgPaint();
  pgBind();
}

function pgName(){
  const el = document.getElementById('pgPvName');
  if (!el || !PGL) return;
  const s = (PGL.skins || []).find(k => k.key === PGSKIN);
  el.textContent = s ? s.label : PGSKIN;
}

/* BOUND ONCE. `#content` is re-filled rather than replaced when a screen
   changes, so a listener added per render is a listener that fires once per
   visit to this screen — the save would go out twice on the second visit. */
function pgBind(){
  if (PGBOUND) return;
  PGBOUND = true;
  const content = $('#content');

  content.addEventListener('click', async (e) => {
    if (!document.getElementById('pgPrev')) return;

    const sw = e.target.closest('[data-pgskin]');
    if (sw) {
      const k = sw.dataset.pgskin;
      if (!pgSkinOk(k)) return;
      PGSKIN = k;
      const host = document.getElementById('pgSkins');
      if (host) host.querySelectorAll('[data-pgskin]').forEach(b => b.classList.toggle('on', b === sw));
      pgName(); pgPaint(); pgDirty('layout');
      return;
    }

    const tg = e.target.closest('.ectog[data-pgtog]');
    if (tg) {
      const k = tg.dataset.pgtog;
      if (!(k in PG_NOCLASS)) return;
      const next = !pgBool(PGS[k]);
      PGS[k] = next;
      tg.classList.toggle('on', next);
      tg.setAttribute('aria-checked', String(next));
      pgPaint(); pgDirty('styles');
      return;
    }

    const cp = e.target.closest('[data-copy]');
    if (cp) { navigator.clipboard?.writeText(cp.dataset.copy); toast('Shortcode copied'); return; }

    if (e.target.id === 'saveLayout') await pgSave();
  });

  content.addEventListener('keydown', (e) => {
    const tg = e.target.closest?.('.ectog[data-pgtog]');
    if (tg && (e.key === ' ' || e.key === 'Enter')) { e.preventDefault(); tg.click(); }
  });

  content.addEventListener('change', (e) => {
    const ct = e.target.closest('[data-pgct]');
    if (!ct || !PGSSEL[ct.dataset.pgct]) return;
    if (!PGSSEL[ct.dataset.pgct].opts.includes(ct.value)) return;
    PGS[ct.dataset.pgct] = ct.value;
    pgPaint(); pgDirty('styles');
  });

  content.addEventListener('input', (e) => {
    const cs = e.target.closest('[data-pgcs]');
    if (cs) {
      const f = pgSpaceDef(cs.dataset.pgcs);
      if (!f) return;
      const v = pgNum(cs.value, f.min, f.max, f.def);
      PGS[cs.dataset.pgcs] = v;
      const out = document.getElementById('pgv-' + cs.dataset.pgcs);
      if (out) out.textContent = v + 'px';
      pgPaint(); pgDirty('styles');
      return;
    }
    const r = e.target.closest('[data-pgnum]');
    if (!r) return;
    const def = PG_SPACE.find(x => x[0] === r.dataset.pgnum);
    if (!def) return;
    const v = pgNum(r.value, def[2], def[3], def[2]);
    PGSL[def[0]] = v;
    const out = document.getElementById('pgv-' + def[0]);
    if (out) out.textContent = v + def[5];
    pgPaint(); pgDirty('space');
  });

  content.addEventListener('change', (e) => {
    if (e.target.id !== 'gridCols') return;
    PGCOLS = pgNum(e.target.value, 1, 6, 4);
    pgPaint(); pgDirty('layout');
  });
}

/* ONE Save, THREE endpoints, and only the ones that changed.
   Each group goes to the endpoint that owns its schema, so every value is cast
   and bounded by the same code the screen it came from uses. Nothing here
   invents a setting, so nothing here needs a capability of its own. */
async function pgSave(){
  const root = pgApiRoot();
  const msg = document.getElementById('layoutMsg');
  const post = (url, body) => fetch(url, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': uToken(), Accept: 'application/json' },
    body: JSON.stringify(body),
  }).then(r => r.json()).catch(() => ({ ok: false, error: 'Could not save.' }));

  const failed = [];
  try {
    if (PGDIRTY.layout) {
      const j = await post(root + '/admin-api/layout', { skin: PGSKIN, columns: PGCOLS });
      if (!j.ok) failed.push(j.error || 'card style');
    }
    if (PGDIRTY.styles) {
      const s = {};
      Object.keys(PG_NOCLASS).forEach(k => {
        if (k in PGS && pgBool(PGS[k]) !== pgBool(PGS0[k])) s[k] = pgBool(PGS[k]);
      });
      PG_CARD_SPACE.forEach(([m, d]) => [m, d].forEach(k => {
        const f = pgSpaceDef(k);
        if (!f || !(k in PGS)) return;
        const v = pgNum(PGS[k], f.min, f.max, f.def);
        if (v !== pgNum(PGS0[k], f.min, f.max, f.def)) s[k] = v;
      }));
      PG_CARD_TYPE.forEach(([m, d]) => [m, d].forEach(k => {
        const f = PGSSEL[k];
        if (!f || !(k in PGS) || !f.opts.includes(String(PGS[k]))) return;
        if (String(PGS[k]) !== String(PGS0[k] ?? f.def)) s[k] = String(PGS[k]);
      }));
      if (Object.keys(s).length) {
        const j = await post(root + '/admin-api/product-styles', { settings: s });
        if (!j.ok) failed.push(j.error || 'card content'); else Object.assign(PGS0, s);
      }
    }
    if (PGDIRTY.space) {
      const s = {};
      PG_SPACE.forEach(([k, , min, max]) => {
        if (!(k in PGSL)) return;
        const v = pgNum(PGSL[k], min, max, min);
        if (v !== pgNum(PGSL0[k], min, max, min)) s[k] = v;
      });
      if (Object.keys(s).length) {
        const j = await post(root + '/admin-api/site-layout', { settings: s });
        if (!j.ok) failed.push(j.error || 'spacing'); else Object.assign(PGSL0, s);
      }
    }
  } catch (err) { failed.push('Could not save.'); }

  if (!msg) return;
  if (failed.length) { msg.classList.remove('ok'); msg.textContent = failed.join(' · '); return; }
  PGDIRTY = { layout: false, styles: false, space: false };
  msg.classList.add('ok');
  msg.textContent = 'Saved — live on the storefront now.';
}


/* ---------- Appearance · Quantity bundles ----------
   Buy-more-save-more tiers, applied to every product. The preview shows the
   effect on a AED 55 product so a change can be judged before saving. */
async function renderBundles(){
  const base = window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/bundles';
  let d; try{ d = await (await fetch(base,{credentials:'same-origin',headers:{Accept:'application/json'}})).json(); }
  catch(e){ $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load bundle settings.</div></div>`; return; }

  const row = (t,i)=>`<tr data-row="${i}" style="border-top:1px solid #eef2f7">
      <td style="padding:8px 10px"><input type="number" min="1" max="99" value="${t.qty}" data-f="qty" style="width:70px;padding:6px 8px;border:1px solid #dbe3ec;border-radius:7px"></td>
      <td style="padding:8px 10px"><input type="number" min="0" max="90" step="0.5" value="${t.discount}" data-f="discount" style="width:80px;padding:6px 8px;border:1px solid #dbe3ec;border-radius:7px"> %</td>
      <td style="padding:8px 10px"><input value="${t.label.replace(/"/g,'&quot;')}" data-f="label" style="width:100%;padding:6px 8px;border:1px solid #dbe3ec;border-radius:7px"></td>
      <td style="padding:8px 10px"><input value="${(t.tag||'').replace(/"/g,'&quot;')}" data-f="tag" placeholder="Save {n}%" style="width:100%;padding:6px 8px;border:1px solid #dbe3ec;border-radius:7px"></td>
      <td style="padding:8px 10px;text-align:right"><button class="btn small" data-del="${i}">Remove</button></td></tr>`;

  const prev = (rows)=>rows.map(r=>`<tr style="border-top:1px solid #eef2f7">
      <td style="padding:7px 10px"><b>${r.label}</b> <span class="mdesc">×${r.qty}</span></td>
      <td style="padding:7px 10px">AED ${r.total}</td>
      <td style="padding:7px 10px" class="mdesc"><s>AED ${r.was}</s></td>
      <td style="padding:7px 10px;color:#1F7D52">saves AED ${r.saved}</td></tr>`).join('');

  $('#content').innerHTML = `<div class="wrap">
    <div class="page-head"><h2>Quantity bundles</h2><p>Offer a better rate for buying more of the same product. Applies to every product automatically — no per-product setup.</p></div>

    <div class="card" style="padding:18px">
      <label class="row" style="gap:8px;font-size:13.5px;cursor:pointer;margin-bottom:14px">
        <input type="checkbox" id="bEnabled" ${d.enabled?'checked':''}> Show bundle options on product pages
      </label>
      <div class="bscroll">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead><tr>${['Quantity','Discount','Label','Tag',''].map(h=>`<th style="text-align:left;padding:8px 10px;background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b">${h}</th>`).join('')}</tr></thead>
          <tbody id="bRows">${d.tiers.map(row).join('')}</tbody>
        </table>
      </div>
      <div style="margin-top:12px;display:flex;gap:10px;align-items:center">
        <button class="btn" id="bAdd">${ic('<path d="M12 5v14M5 12h14"/>')} Add tier</button>
        <button class="btn primary" id="bSave">Save</button>
        <span class="mdesc" id="bMsg"></span>
      </div>
      <p class="mdesc" style="margin-top:10px">Use <code>{n}</code> in a tag to insert the saving, e.g. <code>Save {n}%</code>.</p>
    </div>

    <div class="page-head" style="margin-top:24px"><h2>Preview</h2><p>On a AED 55 product.</p></div>
    <div class="card" style="padding:0;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:13px"><tbody id="bPrev">${prev(d.preview)}</tbody></table></div>
  </div>`;

  $('#content').addEventListener('click', async (e)=>{
    if(e.target.id==='bAdd'){
      const i = $('#bRows').children.length;
      $('#bRows').insertAdjacentHTML('beforeend', row({qty:i+1,discount:0,label:(i+1)+'-pack bundle',tag:'Save {n}%'}, i));
      return;
    }
    const del = e.target.closest('[data-del]');
    if(del){ del.closest('tr').remove(); return; }

    if(e.target.id==='bSave'){
      const tiers=[...$('#bRows').children].map(tr=>({
        qty:Number(tr.querySelector('[data-f=qty]').value),
        discount:Number(tr.querySelector('[data-f=discount]').value),
        label:tr.querySelector('[data-f=label]').value,
        tag:tr.querySelector('[data-f=tag]').value }));
      try{
        const r = await fetch(base,{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
          body:JSON.stringify({enabled:$('#bEnabled').checked, tiers})});
        const j = await r.json();
        $('#bMsg').textContent = j.ok ? 'Saved — live on the storefront now.' : (j.error||'Could not save.');
        if(j.ok && j.preview) $('#bPrev').innerHTML = prev(j.preview);
      }catch(err){ $('#bMsg').textContent='Could not save.'; }
    }
  });
}


/* ---------- Store · Ecommerce ----------
   Tabs on top, sections within, and a preview icon beside every name. The
   preview expands in place — never a popup, never a new page. */
let ECOM=null, ETAB=0, EDIRTY=false;

const EEYE='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>';

function ecomBase(){
  return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/ecommerce';
}

async function renderEcommerce(){
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Ecommerce</h2><p>Loading…</p></div></div>`;
  try{
    const r = await fetch(ecomBase(), {credentials:'same-origin', headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    ECOM = await r.json();
  }catch(e){
    $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load settings. <button class="btn small" onclick="renderEcommerce()">Retry</button></div></div>`;
    return;
  }
  ETAB=0; EDIRTY=false; paintEcom();
}

function ecField(f){
  const id='ec_'+f.name;
  const v=f.value;
  let ctl;
  if(f.type==='bool'){
    ctl = `<span class="ectog${v?' on':''}" data-ec="${f.name}" data-t="bool" role="switch" aria-checked="${v?'true':'false'}" tabindex="0"></span>`;
  }else if(f.type==='select'){
    ctl = `<select id="${id}" data-ec="${f.name}" data-t="select" class="inp">`
        + Object.entries(f.options||{}).map(([k,l])=>`<option value="${escAttr(k)}"${String(v)===k?' selected':''}>${escHtml(l)}</option>`).join('')
        + `</select>`;
  }else if(f.type==='textarea'){
    ctl = `<textarea id="${id}" data-ec="${f.name}" data-t="text" class="inp" rows="2" style="width:100%;max-width:520px">${escHtml(v??'')}</textarea>`;
  }else if(f.type==='colour'){
    ctl = `<input type="color" id="${id}" data-ec="${f.name}" data-t="colour" value="${escAttr(v||'#000000')}" class="ecclr">`;
  }else if(f.type==='int'||f.type==='money'){
    /* WHOLE DIRHAMS. A money field here is entered in FILS, so the step is a
       whole dirham and the legend says so IN FILS: the server refuses 750 with
       "enter 700 or 800", and a box that lets him type 750 teaches him the
       wrong unit. Saying "enter AED 7" beside a box that wants 700 would
       re-make the hundredfold error these rules exist to stop. */
    ctl = `<input type="number" min="0" step="${f.type==='money' ? '100' : '1'}" id="${id}" data-ec="${f.name}" data-t="${f.type}" value="${escAttr(v??0)}" class="inp" style="width:130px">`
        + (f.type==='money' ? ` <span class="ecunit">fils — whole dirhams, so 700 not 750</span>` : '');
  }else{
    ctl = `<input type="text" id="${id}" data-ec="${f.name}" data-t="text" value="${escAttr(v??'')}" class="inp" style="width:100%;max-width:440px">`;
  }
  const pv = ECOM.previews && ECOM.previews[f.preview];
  const wide = f.type==='text' || f.type==='textarea';
  return `<div class="ecopt${wide?' wide':''}${f.type==='bool'?' istog':''}">
      <div class="ecom"><div class="ecl"><label for="${id}">${escHtml(f.label)}</label>${pv?`<button class="eceye" title="Show what this changes">${EEYE}</button>`:''}</div>
      ${f.help?`<div class="echelp">${f.help}</div>`:''}</div>
      <div class="ecctl">${ctl}</div>
    </div>${pv?ecPreview(pv):''}`;
}

function ecPreview(pv){
  return `<div class="ecpv"><div class="ecpvi">
      <div class="ecpvc"><span class="d"></span>${escHtml(pv.caption||'Preview')}</div>
      <div class="ecpvs">${pv.stage||''}</div>
      ${(pv.legend||[]).map((l,i)=>`<div class="eclg"><span class="n">${i+1}</span><span>${l}</span></div>`).join('')}
    </div></div>`;
}

const ECIC={
 globe:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18"/></svg>',
 grid:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
 truck:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 6h13v10H2z"/><path d="M15 9h4l3 3.5V16h-7z"/><circle cx="6" cy="18" r="1.6"/><circle cx="18" cy="18" r="1.6"/></svg>',
 bag:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/></svg>',
 card:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
 phone:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>',
 pct:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m19 5-14 14"/><circle cx="7.5" cy="7.5" r="2"/><circle cx="16.5" cy="16.5" r="2"/></svg>',
 star:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9z"/></svg>',
 find:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg>',
 box:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/></svg>'
};

function paintEcom(){
  const t = ECOM.tabs[ETAB];
  $('#content').innerHTML = `<div class="wrap ecwrap">
    <div class="echd">
      <div class="row" style="align-items:flex-start;gap:16px;flex-wrap:wrap">
        <div><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Ecommerce</h2>
          <p class="mdesc" style="margin:0">Everything the storefront reads at runtime. Changes go live as soon as you save.</p></div>
        <div style="flex:1"></div>
        <div class="ecsearch"><input id="ecFind" placeholder="Search settings…" autocomplete="off"></div>
      </div>
      <div class="ectabs">${ECOM.tabs.map((x,i)=>
        `<button class="ectab${i===ETAB?' on':''}" data-ectab="${i}">${escHtml(x.label)}<span class="ct">${x.count}</span></button>`).join('')}</div>
      <p class="ectabs-hint">Every storefront setting is here, split by what it affects — one tab per area, and the number on each is how many settings it holds. Nothing is hidden on the tabs you are not looking at; use Search settings above to jump straight to one.</p>
    </div>

    <div class="ecbody">${t.sections.map(sec=>{
      const pv = ECOM.previews && ECOM.previews[sec.preview];
      return `<div class="ecsec">
        <div class="ecsech">
          <span class="ecic">${ECIC[sec.icon]||ECIC.box}</span>
          <div class="ecsect"><h3>${escHtml(sec.label)}</h3>${sec.desc?`<p>${escHtml(sec.desc)}</p>`:''}</div>
          <span class="ecsp"></span>
          ${pv?`<button class="eceye" title="Preview this section">${EEYE}</button>`:''}
        </div>
        ${pv?ecPreview(pv):''}
        <div class="ecsecb">${sec.fields.map(ecField).join('')}</div>
      </div>`;}).join('')}</div>

    <div class="ecsave">
      <span class="ecdirty" id="ecDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="ecDiscard">Discard</button>
      <button class="btn primary" id="ecSave">Save changes</button>
    </div>
  </div>`;
  if(EDIRTY) $('#ecDirty').style.visibility='visible';
}

function ecMarkDirty(){ EDIRTY=true; const d=$('#ecDirty'); if(d) d.style.visibility='visible'; }

function ecTogglePreview(btn){
  // A section eye opens the panel right after the header; an option eye opens
  // the panel that follows its row.
  const anchor = btn.closest('.ecsech') || btn.closest('.ecopt');
  const pv = anchor.nextElementSibling;
  if(!pv || !pv.classList.contains('ecpv')) return;
  const open = pv.classList.contains('on');
  document.querySelectorAll('.ecpv.on').forEach(p=>p.classList.remove('on'));
  document.querySelectorAll('.eceye.on').forEach(e=>e.classList.remove('on'));
  if(!open){ pv.classList.add('on'); btn.classList.add('on'); }
}

async function ecSaveNow(){
  const settings={};
  document.querySelectorAll('[data-ec]').forEach(el=>{
    settings[el.dataset.ec] = el.dataset.t==='bool' ? el.classList.contains('on') : el.value;
  });
  const msg=$('#ecDirty');
  try{
    const r = await fetch(ecomBase(), {method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({settings})});
    const j = await r.json();
    if(j.ok){
      EDIRTY=false;
      msg.style.visibility='visible'; msg.textContent='Saved — live on the storefront'; msg.classList.add('ok');
      // Keep the loaded copy in step so switching tabs does not revert.
      ECOM.tabs.forEach(t=>t.sections.forEach(s=>s.fields.forEach(f=>{
        if(f.name in settings) f.value = settings[f.name];
      })));
      setTimeout(()=>{ msg.classList.remove('ok'); msg.textContent='Unsaved changes'; msg.style.visibility='hidden'; }, 2600);
    }else{
      msg.style.visibility='visible'; msg.textContent = j.error || 'Could not save.';
    }
  }catch(e){
    msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.';
  }
}

let ECHOVER;
document.addEventListener('click', e=>{
  const tb=e.target.closest('[data-ectab]');
  if(tb){ ETAB=+tb.dataset.ectab; paintEcom(); return; }
  /* [data-ec], not a bare .ectog. The switch is a SHARED class: about fifteen
     screens render one, each with its own data-* key, and this document-level
     listener matched every one of them — so clicking a toggle anywhere in the
     admin ran this handler as well as the owning screen's.

     That was reported as harmless because the class still ends up right. It is
     not. Two defects were measured in Chromium at the tip, both caused by this
     listener running first on somebody else's control:

       1. #demotog on the Homepage screen decides its direction by reading its
          OWN class — `turningOn = !tog.classList.contains('on')`. This handler
          has already flipped it by then, so with demo content OFF, clicking to
          turn it on offered "Hide demo content?" and POSTed {enabled:false}.
          The toggle could not be switched on at all.
       2. Keyboard activation on Homepage and Product page was dead. The
          keydown handler below matched a bare .ectog too and called .click(),
          and each of those screens has its own keydown doing the same — so
          Space dispatched TWO clicks, the state flipped twice and nothing
          changed.

     Screens whose own handler re-renders or assigns the class from their own
     state object were genuinely unaffected, which is why this looked clean.
     Narrowing to the key this screen actually owns (ecSaveNow() reads
     [data-ec], and only the Ecommerce field renderer emits it) fixes both and
     leaves nothing else relying on the overlap — verified by clicking all 124
     toggles on every screen before and after. */
  const tg=e.target.closest('.ectog[data-ec]');
  if(tg){ tg.classList.toggle('on'); tg.setAttribute('aria-checked', tg.classList.contains('on')); ecMarkDirty(); return; }
  const ey=e.target.closest('.eceye');
  if(ey){ ecTogglePreview(ey); return; }
  if(e.target.id==='ecSave'){ ecSaveNow(); return; }
  if(e.target.id==='ecDiscard'){ renderEcommerce(); return; }
});
document.addEventListener('keydown', e=>{
  // Scoped to this screen's own toggles for the same reason as the click
  // handler above: Homepage and Product page each run their own keydown that
  // also calls .click(), so a bare .ectog here made Space fire twice and
  // cancel itself out. Every other screen that renders an .ectog either has
  // its own keydown or is mouse-only exactly as it was before.
  if(e.target.matches && e.target.matches('.ectog[data-ec]') && (e.key===' '||e.key==='Enter')){
    e.preventDefault(); e.target.click();
  }
});
document.addEventListener('mouseover', e=>{
  const ey=e.target.closest('.eceye'); if(!ey) return;
  clearTimeout(ECHOVER);
  ECHOVER=setTimeout(()=>{ if(!ey.classList.contains('on')) ecTogglePreview(ey); }, 260);
});
document.addEventListener('mouseout', e=>{ if(e.target.closest('.eceye')) clearTimeout(ECHOVER); });
document.addEventListener('input', e=>{
  if(e.target.id==='ecFind'){ ecFilter(e.target.value); return; }
  if(e.target.dataset && e.target.dataset.ec) ecMarkDirty();
});
document.addEventListener('change', e=>{ if(e.target.dataset && e.target.dataset.ec) ecMarkDirty(); });

function ecFilter(q){
  q=(q||'').trim().toLowerCase();
  document.querySelectorAll('.ecopt').forEach(o=>{
    const t=o.textContent.toLowerCase();
    o.style.display = !q || t.includes(q) ? '' : 'none';
  });
  document.querySelectorAll('.ecsec').forEach(s=>{
    const any=[...s.querySelectorAll('.ecopt')].some(o=>o.style.display!=='none');
    s.style.display = any ? '' : 'none';
  });
}

function escHtml(v){ return String(v??'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
function escAttr(v){ return String(v??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }


/* ---------- Appearance · Homepage ----------
   Every section can be switched off independently for desktop and mobile, and
   product sections carry their own grid skin. Order is drag-free: up and down
   arrows, which are far easier on a touch screen. */
let HP=null;

async function renderHomepage(){
  const base = window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/homepage';
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Homepage</h2><p>Loading…</p></div></div>`;
  try{
    const r = await fetch(base,{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    HP = await r.json();
  }catch(e){
    $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load homepage settings. <button class="btn small" onclick="renderHomepage()">Retry</button></div></div>`;
    return;
  }
  await loadDemo();
  paintHomepage(base);
  if(window.kbbDrafts) kbbDrafts.ready('homepage');
}


/* A wireframe of the layout, drawn from its real section order — not a stock
   thumbnail, so it always matches what applying it will do. */

/* ---------- demo content ----------
   A confirmation before either direction: switching it on changes what the
   storefront shows to real visitors, and switching it off is what someone
   worried about their catalogue will hesitate over. The dialog says plainly
   that nothing is stored either way. */
let DEMO = null;

async function loadDemo(){
  const base = window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/demo';
  try{ DEMO = await (await fetch(base,{credentials:'same-origin',headers:{Accept:'application/json'}})).json(); }
  catch(e){ DEMO = {enabled:false}; }
}

function demoConfirm(turningOn){
  return new Promise(resolve=>{
    const w=document.createElement('div');
    w.className='kdlg';
    w.innerHTML=`<div class="kdlg-s"></div><div class="kdlg-b" role="dialog" aria-modal="true">
        <h3>${turningOn?'Show demo content?':'Hide demo content?'}</h3>
        <p>${turningOn
          ? 'Sample products, brands, reviews and articles will appear in any homepage section that does not have enough real content yet. Visitors to the storefront will see them.'
          : 'Sample items will stop appearing. Sections without enough real content will simply show fewer items, or nothing.'}</p>
        <p class="kdlg-safe">Demo content is only ever displayed. Nothing is written to or removed from your catalogue, and your real products, reviews and articles are not touched either way.</p>
        <div class="kdlg-a"><button class="btn" data-no>Cancel</button>
          <button class="btn primary" data-yes>${turningOn?'Show demo content':'Hide demo content'}</button></div>
      </div>`;
    document.body.appendChild(w);
    requestAnimationFrame(()=>w.classList.add('on'));
    const done=v=>{w.classList.remove('on');setTimeout(()=>w.remove(),200);resolve(v)};
    w.querySelector('[data-no]').onclick=()=>done(false);
    w.querySelector('.kdlg-s').onclick=()=>done(false);
    w.querySelector('[data-yes]').onclick=()=>done(true);
    document.addEventListener('keydown',function esc(e){
      if(e.key==='Escape'){ document.removeEventListener('keydown',esc); done(false); }
    });
  });
}

document.addEventListener('click', async e=>{
  const tog=e.target.closest('#demotog'); if(!tog) return;
  const turningOn = !tog.classList.contains('on');
  if(!await demoConfirm(turningOn)) return;

  const base = window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/demo';
  try{
    const r = await fetch(base,{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({enabled:turningOn})});
    const j = await r.json();
    if(j.ok){
      DEMO.enabled=j.enabled;
      tog.classList.toggle('on', j.enabled);
      tog.setAttribute('aria-checked', j.enabled);
      const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.classList.add('ok');
        d.textContent = j.enabled ? 'Demo content shown — live now' : 'Demo content hidden — live now';
        setTimeout(()=>{d.classList.remove('ok');d.textContent='Unsaved changes';d.style.visibility='hidden';},2600);}
    }
  }catch(err){
    const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.textContent='Could not change demo content.';}
  }
});


/* A real card in the chosen skin, using the storefront's own markup so a
   preview cannot drift from what ships. */
function skinCard(skin, cartLabel){
  /* The same elements and classes as components/product-grid.blade.php.
     Element types matter: .cb, .cn, .cp and .kbb-card-thumb never set display,
     so building the preview from spans left them inline and the card collapsed. */
  return `<div class="kbb-pgrid" data-skin="${skin}">
    <a class="kbb-card kbb-tile" href="#" onclick="return false">
      <div class="kbb-card-thumb">
        <span class="kbb-card-ph"></span>
        <span class="kbb-badge kbb-badge-new">New</span>
        <span class="kbb-badge kbb-badge-sale">-30%</span>
      </div>
      <div class="cb">
        <div class="kbb-card-cat">Sun care</div>
        <div class="cn"><span class="kbb-card-brand">BEAUTY OF JOSEON</span><span class="kbb-card-nm">Relief Sun Rice + Probiotics SPF50+</span></div>
        <div class="kbb-card-rate"><span class="kbb-crate"><span class="kbb-cstar on">★</span><span class="kbb-cstar on">★</span><span class="kbb-cstar on">★</span><span class="kbb-cstar on">★</span><span class="kbb-cstar on">★</span></span> <span class="kbb-card-rc">(3204)</span></div>
        <div class="cp"><span class="kbb-card-reg">&#1583;.&#1573;102</span> <span class="kbb-card-price">&#1583;.&#1573;71</span></div>
        <span class="kbb-card-cart">${escHtml(cartLabel || 'Add to cart')}</span>
      </div>
    </a></div>`;
}

/* Opening a picker closes any other, so only one panel is ever on screen. */
document.addEventListener('click', e=>{
  const open=e.target.closest('[data-open]');
  if(open){
    const pop=document.querySelector(`[data-pop="${open.dataset.open}"]`);
    document.querySelectorAll('.skinpop.on').forEach(p=>{ if(p!==pop) p.classList.remove('on'); });
    pop.classList.toggle('on');
    return;
  }
  const pick=e.target.closest('[data-pickskin]');
  if(pick){
    const [i,key]=pick.dataset.pickskin.split('|');
    if(typeof HP!=='undefined' && HP.sections[i]){
      HP.sections[i].skin=key;
      const btn=document.querySelector(`[data-open="${i}"]`);
      if(btn) btn.firstChild.textContent=(HP.skins.find(s=>s.key===key)||{}).label||key;
      pick.closest('.skinpop').querySelectorAll('.skinopt').forEach(o=>o.classList.toggle('on',o===pick));
      pick.closest('.skinpop').classList.remove('on');
      hpPreviewStale();
      const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent='Unsaved changes';}
    }
    return;
  }
  if(!e.target.closest('.skinpick')) document.querySelectorAll('.skinpop.on').forEach(p=>p.classList.remove('on'));
});

/* ── Appearance · Homepage · a section's BACKGROUND and WIDTH ── Lane BG ──
 *
 * The owner: "on homepage i want to remove the sections backgrounds by
 * default, and if i need it for any section, i can put it myself", and "any
 * section i can make full width upto 1920x".
 *
 * Both ship applied — the panels are off and the picture banner is edge to
 * edge — so these two selects are how he PUTS ONE BACK, per section, which is
 * his own second clause. They sit under the grid-style picker on the same row,
 * because the question "what does this section look like" belongs next to the
 * section rather than on a screen of its own.
 *
 * THE OPTIONS COME FROM THE SERVER, never from a list written out here:
 * HP.backgrounds and HP.widths are App\Services\HomepageSections::BACKGROUNDS
 * and ::WIDTHS, sent by show(). A list in this file is the way a screen comes
 * to offer a token the storefront has stopped drawing.
 *
 * NOTHING IS DRAWN FOR A NESTED ROW. `delivery` and `ticker` are drawn inside
 * the hero's own <section> and have no wrapper of their own, so the server
 * sends null for both values and offers no control — the same answer
 * HomepageSections::sectionTabs() gives the live-edit screen. `movable` is
 * already the console's word for "this row is nested"; the null is what is
 * actually tested, because it is the value the storefront reads.
 */
function hpFrame(s, i){
  if (s.background == null && s.width == null) return '';

  /* EACH LABEL AND ITS SELECT IN ONE SPAN. Written as four flex children the
     row wrapped between the second label and its select — "Width" on one line
     and its dropdown on the next, which reads as a label for the box above it.
     The span is the unit that wraps. */
  const sel = (k, opts, val) => `<span class="hpf"><label>${k==='background'?'Background':'Width'}</label>
    <select data-hpf="${i}" data-k="${k}">${(opts||[]).map(o=>
      `<option value="${escAttr(o.key)}"${o.key===val?' selected':''}>${escHtml(o.label)}</option>`).join('')}</select></span>`;

  return `<div class="hpframe">
    ${s.background == null ? '' : sel('background', HP.backgrounds, s.background)}
    ${s.width == null ? '' : sel('width', HP.widths, s.width)}
  </div>`;
}

function hpWire(L){
  const BAR={'Hero slider':22,'Category circles':13,'Big savings bundles':17,'Recommended for you':17,
    'Best sellers':17,'Flash sale':17,'Build your routine':13,'Skin quiz':15,'Top brands':11,
    '#KBeautyBliss spotted':13,'Skincare guide':13,'About us':13,'Customer reviews':15,
    'Trust row':7,'Newsletter':11,'Promo ticker':5,'Delivery strip':5};
  const ACC={'Hero slider':'#E0567B','Skin quiz':'#3c4655','Flash sale':'#E23B57','Newsletter':'#1F7D52'};
  return `<div class="wire">${L.order.map(n=>
    `<i style="height:${BAR[n]||11}px;background:${ACC[n]||'#E9DDE3'}" title="${n}"></i>`).join('')}</div>`;
}

/* ── THE PREVIEW (Lane P1, Phase 15) ────────────────────────────────────────

   Appearance → Homepage published straight to the live shop: seventeen rows of
   switches, arrows and skin pickers, and no way to see any of it short of
   pressing Save and opening the storefront real visitors are on. This is that
   missing half — the REAL homepage, rendered from the arrangement currently on
   screen, by the storefront's own controller and template, with nothing saved.

   THE PICTURE SURVIVES AN ARROW PRESS. paintHomepage() repaints #content
   whole, so the last rendered document is kept here and re-emitted; what
   changes on an edit is the LINE UNDER IT, which stops claiming the picture is
   of the arrangement on screen the moment the two differ. A stale picture that
   says it is stale is useful; one that quietly is not is the fault this whole
   screen has been repairing for three rounds.

   IT IS NOT REFRESHED ON EVERY KEYSTROKE. Each preview is a full homepage
   render on the server; firing one per arrow press would make a page an owner
   is editing the most expensive thing on the host. The button is the trigger,
   and the note says when it is worth pressing again.

   THE FRAME IS SANDBOXED WITHOUT allow-scripts, DELIBERATELY. The storefront's
   own JavaScript has no business running inside the console, and this is a
   preview of LAYOUT: what renders, in what order, at which width. `sandbox`
   with only allow-same-origin lets the document load the shop's stylesheets,
   fonts and images and run not one line of script. The two flags that together
   defeat a sandbox — allow-scripts AND allow-same-origin — are never both set.

   AND THE PAGE INSIDE IS AT A REAL DEVICE WIDTH. `d-off` and `m-off` are media
   queries in the storefront's own stylesheet, so the only honest way to show
   what a Desktop or Mobile switch does is to hand the document a viewport of
   that width and let its CSS answer. Mobile is 390px, drawn at its true size;
   Desktop is 1280px, drawn reduced to fit the column by a declared scale. */
let HPPV = {html:'', dev:'desk', of:'', busy:false, err:''};

/*
 * WHAT THE PICTURE IS A PICTURE OF, as a string.
 *
 * Freshness is DERIVED and not flagged, and that is the difference between a
 * note that is right and a note that is right until somebody adds a fourth way
 * to edit a row. A flag has to be set by every edit handler on this screen —
 * the two device switches, the skin picker, the arrows, Apply layout, and
 * whatever the next lane adds — and the one that forgets leaves the screen
 * claiming a stale picture is current, which is the exact class of fault this
 * screen has spent three rounds removing. Comparing what was rendered with what
 * is on screen cannot be forgotten, and it gets "moved it and moved it back"
 * right for free.
 */
function hppvOf(){
  return HP ? JSON.stringify(HP.sections.map(s=>[s.key, !!s.desktop, !!s.mobile, s.skin||''])) : '';
}

function hppvFresh(){ return !!HPPV.html && HPPV.of === hppvOf(); }

function hpPreviewCard(){
  /* THE DOCUMENT IS ASSIGNED, NEVER INTERPOLATED. Eighty-odd kilobytes of the
     shop's own HTML inside a template literal is one backtick or one `${` away
     from terminating the literal and taking the whole screen's paint with it,
     and neither character is escaped by an attribute escaper. paintHomepage()
     sets .srcdoc on the element after the paint instead, where the value is a
     string and not source. */
  const stage = HPPV.html
    ? `<iframe class="hppv-frame" title="Homepage preview" sandbox="allow-same-origin"></iframe>`
    : `<div class="hppv-empty">Nothing rendered yet. Press <b>&nbsp;Preview this arrangement&nbsp;</b> to draw the homepage as the rows below it are now — the shop is not changed either way.</div>`;

  const note = HPPV.err
    ? `<p class="hppv-note is-bad">${escHtml(HPPV.err)}</p>`
    : `<p class="hppv-note" id="hppvNote">${escHtml(hppvNoteText())}</p>`;

  return `<div class="hppv">
    <div class="hppv-hd"><b>Preview</b>
      <span class="hppv-dev">
        <button type="button" data-hppv-dev="desk" class="${HPPV.dev==='desk'?'on':''}">Desktop · 1280</button>
        <button type="button" data-hppv-dev="mob" class="${HPPV.dev==='mob'?'on':''}">Mobile · 390</button>
      </span>
      <button class="btn small" id="hppvGo" ${HPPV.busy?'disabled':''}>${HPPV.busy?'Rendering…':'Preview this arrangement'}</button>
    </div>
    <div class="hppv-stage${HPPV.dev==='mob'?' is-mob':''}">${stage}</div>
    ${note}
  </div>`;
}

/** Ask the server to draw the rows as they stand. Writes nothing, saves nothing. */
async function hpPreviewNow(base){
  HPPV.busy = true; HPPV.err = '';
  paintHomepage(base);

  try{
    const r = await fetch(base+'/preview',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({sections:HP.sections.map(s=>({key:s.key,desktop:s.desktop,mobile:s.mobile,skin:s.skin,background:s.background,width:s.width}))})});

    if(!r.ok && r.status===404){
      /* Name the failure rather than saying "could not render". On this host a
         package that adds a route is inert until its cache-clearing migration
         runs, and that is by far the likeliest reason this one 404s. */
      HPPV.busy=false;
      HPPV.err='The preview route is not registered. The cache-clearing migration for this release may not have run — check Store → Core Updates.';
      paintHomepage(base); return;
    }

    const j = await r.json();
    HPPV.busy = false;

    if(j.ok){
      HPPV.html = j.html;
      /* The order the server SETTLED, not the order that was posted: a nested
         row is put back behind its host on read, so adopting the answer keeps
         the list under the picture agreeing with the picture. */
      if(j.sections) HP.sections = j.sections;
      // Recorded AFTER adopting the settled list, so a proposal the server
      // normalised does not read as stale the instant it is drawn.
      HPPV.of = hppvOf();
    }else{
      HPPV.err = j.error || 'The server refused to render that arrangement.';
    }
  }catch(e){
    HPPV.busy=false;
    HPPV.err='The preview did not complete: '+String(e && e.message || e);
  }

  paintHomepage(base);
}

/** What the line under the picture is entitled to say about it. */
function hppvNoteText(){
  const where = HPPV.dev==='mob'
    ? 'Drawn at 390px, its true size.'
    : 'Laid out at 1280px and drawn reduced to fit this column.';

  if (!HPPV.html) return 'Nothing is saved by previewing, and nothing on the shop moves until you press Save changes. ' + where;

  return (hppvFresh()
    ? 'This is the arrangement below, rendered by the storefront itself — not a diagram of it. Nothing is saved; the shop still shows what it showed before.'
    : 'The rows below have changed since this picture was drawn. Press Preview again to catch it up.') + ' ' + where;
}

/*
 * Repaint the LINE and nothing else.
 *
 * The arrows and Apply layout both repaint #content, which redraws the note
 * from hppvNoteText() anyway. The two device switches and the grid-skin picker
 * deliberately do NOT — repainting under a control somebody just used loses
 * their place — so those three patch the sentence in place, exactly the way
 * hpDirty() patches the save bar's own word two functions down.
 */
function hpPreviewStale(){
  const n = $('#hppvNote');
  if (n) n.textContent = hppvNoteText();
}

function paintHomepage(base){
  // A section the storefront draws INSIDE another one travels with its host:
  // the delivery strip and the promo ticker live in the hero's own <section>,
  // where CSS `order` is inert. The rows are grouped into blocks so an arrow
  // moves the hero band whole and can never land a row inside it — the server
  // settles such an order away on save, and a screen that showed it would be
  // reporting a position the shop does not have. `movable` and `note` are
  // derived by App\Services\HomepageSections from its NESTED constant, so this
  // cannot come to say something the template does not do.
  const HPB = [];
  HP.sections.forEach(s => { if (s.movable === false && HPB.length) HPB[HPB.length-1].push(s); else HPB.push([s]); });
  const hpBlock = s => HPB.findIndex(b => b.indexOf(s) > -1);

  const rows = HP.sections.map((s,i)=>`
    <div class="hprow${(!s.desktop&&!s.mobile)?' alloff':''}" data-i="${i}">
      <div class="hpmove">${s.movable === false ? '' : `
        <button class="hpb" data-mv="-1" ${hpBlock(s)===0?'disabled':''} aria-label="Move up">↑</button>
        <button class="hpb" data-mv="1" ${hpBlock(s)===HPB.length-1?'disabled':''} aria-label="Move down">↓</button>`}
      </div>
      <div class="hpmain">
        <b>${escHtml(s.label)}</b>
        <span>${escHtml(s.description)}</span>
        ${s.note ? `<span>${escHtml(s.note)}</span>` : ''}
        ${s.has_grid?`<div class="hpskin"><label>Grid style</label>
          <span class="skinpick">
            <button class="skinpick-b" type="button" data-open="${i}">${escHtml((HP.skins.find(k=>k.key===s.skin)||{}).label||s.skin)}<i>&#9662;</i></button>
            <span class="skinpop" data-pop="${i}">
              ${HP.skins.map(k=>`<button class="skinopt${k.key===s.skin?' on':''}" type="button" data-pickskin="${i}|${escAttr(k.key)}">
                <span class="skinprev">${skinCard(k.key)}</span><span class="skinopt-l">${escHtml(k.label)}</span></button>`).join('')}
            </span></span></div>`:''}
        ${hpFrame(s,i)}
      </div>
      <span class="ectog${s.desktop?' on':''}" data-tg="${i}" data-k="desktop" role="switch" aria-checked="${s.desktop}" tabindex="0"></span>
      <span class="ectog${s.mobile?' on':''}" data-tg="${i}" data-k="mobile" role="switch" aria-checked="${s.mobile}" tabindex="0"></span>
    </div>`).join('');

  $('#content').innerHTML = `<div class="wrap ecwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Homepage</h2>
      <p class="mdesc" style="margin:0">Switch any section off, per device. A section off for both is not rendered at all.</p></div>

    <div class="demorow" id="demorow">
      <div class="demo-ic">${DEMO && DEMO.enabled ? '👁' : '👁'}</div>
      <div class="demo-t"><b>Demo content</b>
        <span>Fills empty sections with sample products, brands, reviews and articles so the page can be judged before the catalogue is imported.</span>
        <span class="demo-safe">Displayed only — never saved. Real content always takes priority and is never modified. No demo figure reaches the storefront: ratings, review counts and the shop’s size are counted from real rows only, whether this is on or off.</span></div>
      <span class="ectog${DEMO && DEMO.enabled ? ' on' : ''}" id="demotog" role="switch" aria-checked="${DEMO && DEMO.enabled}" tabindex="0"></span>
    </div>

    <div class="hpsec-h">
      <div><b>Layouts</b><span>Presets that set order, visibility and grid styles in one move. Anything can be adjusted afterwards.</span></div>
    </div>
    <div class="hplayouts">${(HP.layouts||[]).map(L=>`
      <div class="hpl${L.key===HP.layout?' on':''}" data-layout="${escAttr(L.key)}">
        <div class="hpl-p">${hpWire(L)}</div>
        <b>${escHtml(L.name)}${L.key===HP.layout?'<i>Applied</i>':''}</b>
        <span class="bl">${escHtml(L.blurb)}</span>
        <span class="su">${escHtml(L.suits)}</span>
        <div class="hpl-m"><span>${L.count} sections</span>${L.off.length?`<span class="offl">${L.off.length} off</span>`:''}</div>
        <button class="btn small hpl-b">${L.key===HP.layout?'Re-apply':'Apply layout'}</button>
      </div>`).join('')}</div>

    ${hpPreviewCard()}

    <div class="hpsec-h" style="margin-top:22px">
      <div><b>Sections</b><span>Switch any section off, per device. A section off for both is not rendered at all.</span></div>
    </div>
    <div class="hphead"><span>Section</span><span>Desktop</span><span>Mobile</span></div>
    <div class="hplist">${rows}</div>

    <div class="ecsave">
      <span class="ecdirty" id="hpDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="hpDiscard">Discard</button>
      <button class="btn primary" id="hpSave">Save changes</button>
    </div>
  </div>`;

  // The preview document, assigned rather than interpolated — see the note in
  // hpPreviewCard(). Assigning it here rather than fetching again is what lets
  // the picture survive an arrow press.
  const hppvFrame = $('.hppv-frame');
  if (hppvFrame && HPPV.html) hppvFrame.srcdoc = HPPV.html;
}

function hpDirty(){ const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent='Unsaved changes';} }

async function hpSaveNow(base){
  const msg=$('#hpDirty');
  try{
    const r = await fetch(base,{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({sections:HP.sections.map(s=>({key:s.key,desktop:s.desktop,mobile:s.mobile,skin:s.skin,background:s.background,width:s.width}))})});
    const j = await r.json();
    if(j.ok){
      HP.sections = j.sections;
      if(window.kbbDrafts) kbbDrafts.saved('homepage');
      msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} sections — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600);
    }else{
      msg.style.visibility='visible'; msg.textContent = j.error || 'Could not save.';
    }
  }catch(e){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
}

document.addEventListener('click', e=>{
  if(!HP) return;
  const base = window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/homepage';

  const pvd=e.target.closest('[data-hppv-dev]');
  if(pvd){ HPPV.dev = pvd.dataset.hppvDev; paintHomepage(base); return; }
  if(e.target.id==='hppvGo'){ hpPreviewNow(base); return; }

  const tg=e.target.closest('[data-tg]');
  if(tg){
    const s=HP.sections[+tg.dataset.tg];
    s[tg.dataset.k]=!s[tg.dataset.k];
    tg.classList.toggle('on', s[tg.dataset.k]);
    tg.setAttribute('aria-checked', s[tg.dataset.k]);
    tg.closest('.hprow').classList.toggle('alloff', !s.desktop && !s.mobile);
    hpPreviewStale(); hpDirty(); return;
  }
  const mv=e.target.closest('[data-mv]');
  if(mv){
    // Moves a BLOCK. A host and the sections drawn inside it are one unit —
    // see the note in paintHomepage() above — so the hero band swaps with its
    // neighbour whole. Swapping bare rows put a section between a host and its
    // nested rows, which HomepageSections::all() settles away: the screen said
    // one order and the shop rendered another.
    const row=HP.sections[+mv.closest('.hprow').dataset.i];
    if(!row || row.movable===false) return;
    const B=[]; HP.sections.forEach(s=>{ if(s.movable===false && B.length) B[B.length-1].push(s); else B.push([s]); });
    const bi=B.findIndex(b=>b[0]===row), bj=bi+ +mv.dataset.mv;
    if(bi<0||bj<0||bj>=B.length) return;
    [B[bi],B[bj]]=[B[bj],B[bi]];
    HP.sections=[].concat.apply([],B);
    paintHomepage(base); hpDirty(); return;
  }
  const lay=e.target.closest('.hpl-b');
  if(lay){
    const key=lay.closest('[data-layout]').dataset.layout;
    lay.disabled=true; lay.textContent='Applying…';
    fetch(base+'/layout',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({layout:key})})
      .then(r=>r.json()).then(j=>{
        if(j.ok){ HP.sections=j.sections; HP.layout=j.layout; paintHomepage(base); if(window.kbbDrafts) kbbDrafts.saved('homepage');
          const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.classList.add('ok');d.textContent='Layout applied — live now';
            setTimeout(()=>{d.classList.remove('ok');d.textContent='Unsaved changes';d.style.visibility='hidden';},2600);} }
        else { lay.disabled=false; lay.textContent='Apply layout';
          const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.textContent=j.error||'Could not apply.';} }
      })
      .catch(()=>{ lay.disabled=false; lay.textContent='Apply layout';
        const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.textContent='Could not apply.';} });
    return;
  }
  if(e.target.id==='hpSave'){ hpSaveNow(base); return; }
  if(e.target.id==='hpDiscard'){ if(window.kbbDrafts) kbbDrafts.discarded('homepage'); renderHomepage(); return; }
});
document.addEventListener('change', e=>{
  const sel=e.target.closest('[data-skin]');
  if(sel && HP){ HP.sections[+sel.dataset.skin].skin=sel.value; hpDirty(); }

  /* Lane BG. hpPreviewStale() as well as hpDirty(), because these two CHANGE
     THE PICTURE: the preview card above the list would otherwise keep showing
     a homepage drawn before the panel came off, with nothing saying so. The
     grid-style picker already does both, four functions up. */
  const frm=e.target.closest('[data-hpf]');
  if(frm && HP){ HP.sections[+frm.dataset.hpf][frm.dataset.k]=frm.value; hpPreviewStale(); hpDirty(); }
});
document.addEventListener('keydown', e=>{
  if(e.target.dataset && e.target.dataset.tg && (e.key===' '||e.key==='Enter')){ e.preventDefault(); e.target.click(); }
});


/* ---------- Appearance · Product page ----------
   TWO HALVES. `PP.sections` is the module list this screen has always drawn --
   a switch per device, the same shape as the Homepage screen. `PP.layout` is
   round 4's addition: four tabs of spacing and type, in the ModuleSchema::tabs()
   payload Appearance -> Product styles already uses. Both arrive from one GET
   and go back through one POST; see the block above paintProductPage(). */
let PP = null;

function ppBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/product-page'; }

async function renderProductPage(){
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Product page</h2><p>Loading…</p></div></div>`;
  try{
    const r = await fetch(ppBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    PP = await r.json();
  }catch(e){
    $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load product page settings. <button class="btn small" onclick="renderProductPage()">Retry</button></div></div>`;
    return;
  }
  PPPV = (PP && PP.preview) ? PP.preview : {slug:null, url:null, props:{}};
  paintProductPage(true); if(window.kbbDrafts) kbbDrafts.ready('productpage');
}

/* ═════════════════════════════════════════════════════════════════════════
   Appearance → Product page — TWO HALVES, ONE SCREEN.        (Lane PDP2, R4)

     "also i have control on the product page spacing between sections and
      elements etc. and fonts sizes control etc. pleas give me proper tabs for
      that on the product page > Layout."

   What was here was a FLAT LIST of seventeen module switches and nothing else:
   no spacing control, no font-size control and no tab grouping. The switches
   are untouched and now sit behind a tab called Sections; beside them are the
   four Layout tabs the endpoint returns from App\Services\ProductLayout.

   THE SHAPE IS Appearance → Product styles', NOT A NEW ONE. `PP.layout` is the
   same `[{key,label,description,fields:[…]}]` payload `PS.tabs` is — both come
   out of ModuleSchema::tabs() — so the strip, the per-tab count, the card, the
   `.mmrow` fields and the save bar below are the ones eight other screens in
   this console already draw. A screen that invents its own shape is a screen
   the next reader has to learn separately.

   ▲ TENTHS. A `range` in this framework is an INTEGER, and the product page's
     own type is 13.5px, 12.5px and a 1.62 line height. ProductLayout stores
     those as 135, 125 and 162 and declares `scale` beside min/max/step; the
     readout below divides by it. The stored number is never shown, and nothing
     but that class and this screen ever reads it.

   ▲ THE TWO HALVES SAVE SEPARATELY, and that is the endpoint's contract rather
     than a convenience here: a POST carrying `layout` alone leaves the module
     switches alone, and a POST carrying `sections` alone leaves the thirty
     layout values alone. Each half is posted only when it has really been
     touched, so opening a tab and pressing Save cannot write the half he was
     not looking at.
   ═════════════════════════════════════════════════════════════════════════ */
let PPTAB='sections', PPDIRTY={sections:false,layout:false,also:false};


/* ═════════════════════════════════════════════════════════════════════════
   THE LIVE PREVIEW — PHONE AND LAPTOP, BOTH, AS HE MOVES A SLIDER.   (R5)

     "where's the preview on the product controls page? i need a proper
      preview of mobile and desktop both."

   Round 4 put thirty controls on this screen and no preview element of any
   kind. This is that gap.

   ── IT IS THE REAL PRODUCT PAGE, NOT A DRAWING OF ONE ───────────────────

   Each frame loads `/product/{slug}` — the shipped page, on a real product,
   with the real header, the real stylesheet and the real markup. A preview
   assembled here out of admin CSS would be a SECOND ANSWER to "what does a
   product page look like", and a second answer can be right while the page is
   wrong.

   ▲ AND NOT ONE OF THE FIVE DESIGN PREVIEWS UNDER
     `admin-api/catalog/pdp-preview/`, WHICH IS THE OBVIOUS CHOICE AND THE
     WRONG ONE. Those are candidates drawn in markup of their own — every
     class in them is `pv-…` — while the thirty properties are read by
     `.pdp .bb-title`, `.sec h2` and `.details .dtabbar` in kbb-product.css.
     Checked in the templates, not assumed: the one selector the five share
     with the shop is `.dcontent`. Framing a candidate would have drawn a
     handsome product page that answered ONE of the thirty controls and
     looked completely finished doing it.

   ── AND IT MOVES BEFORE ANYTHING IS SAVED ───────────────────────────────

   `var(--pl-x, <literal>)` is how kbb-product.css reads all thirty numbers,
   so the whole of "live" is writing those properties onto the frame's own
   <html>. Nothing is posted, nothing is stored, and pressing Discard puts the
   frames back by reloading them. Same-origin, so the frame's document is
   reachable; every access is wrapped because a frame that has not finished
   loading has no documentElement yet, and the `load` listener repaints it
   when it does.

   ── EVERY VALUE IS CHECKED BEFORE IT REACHES A STYLE OR AN src ──────────

   Rule 5, and this is a place it really bites: a custom-property value goes
   into a stylesheet, and an src goes into the browser's address bar.

     · the property NAME comes from ProductLayout::PROPS, sent by the
       endpoint, and is re-checked against /^--pl-[a-z0-9-]+$/ here;
     · a range VALUE is clamped to the field's own min and max and divided by
       its own scale — so it cannot be anything but digits and one dot;
     · a select VALUE must be one of the keys the schema sent, or the shipped
       default is used instead;
     · the URL must be this shop's own origin or a root-relative path, or no
       frame is drawn at all — which refuses `javascript:`, `data:` and any
       other host by construction rather than by looking for them.

   ── THE FORMATTER IS THE SERVER'S, EXPRESSED IN THE SCHEMA'S OWN TERMS ──

   `unit` and `scale` are already on every field — ppShow() above draws the
   readout with them. ppPvValue() uses the same two to build the CSS value, so
   this is not thirty hard-coded conversions: it is one rule, and
   ProductPagePreviewPanelTest reproduces that rule in PHP and demands it
   equals ProductLayout::vars() property for property.
   ═════════════════════════════════════════════════════════════════════════ */
let PPPV = {slug:null, url:null, props:{}};

/**
 * The page a frame may load, or '' when there is nothing safe to point at.
 *
 * ▲ SAME ORIGIN, CHECKED HERE AS WELL AS BUILT BY route() THERE. This string
 *   becomes an `src`, so it is treated as untrusted whatever produced it:
 *   either it starts at this shop's own origin, or it is a root-relative path,
 *   or no frame is drawn. That refuses `javascript:`, `data:` and any other
 *   host by construction rather than by looking for them. Rule 5.
 */
function ppPvSrc(){
  const u = PPPV && PPPV.url;
  if(typeof u !== 'string' || u === '') return '';
  if(u.indexOf(window.location.origin + '/') === 0) return u;
  if(/^\/[^\/]/.test(u)) return u;
  return '';
}

/** One field as the CSS value ProductLayout::vars() would print for it. */
function ppPvValue(f){
  const o = f.options || {};
  if(f.type === 'select'){
    const keys = Object.keys(o);
    return keys.indexOf(String(f.value)) >= 0 ? String(f.value) : String(f.default);
  }
  /* (Lane PV) A colour is `#` and three or six hex digits, upper-cased the
     way ModuleSchema's `repair` stores it, or the shipped default -- never a
     string that could leave its declaration. ProductLayout::hex() is the
     server's half of the same rule. */
  if(f.type === 'colour'){
    const c = String(f.value == null ? '' : f.value).trim().toUpperCase();
    return /^#(?:[0-9A-F]{3}|[0-9A-F]{6})$/.test(c) ? c : String(f.default).toUpperCase();
  }
  if(f.type !== 'range') return null;
  const sc = (+o.scale) || 1;
  let n = Number(f.value);
  if(!Number.isFinite(n)) n = Number(f.default);
  n = Math.min(+o.max, Math.max(+o.min, n));
  return String(n / sc) + (o.unit === 'px' ? 'px' : '');
}

/** [property, value] for every control, names taken from the server's map. */
function ppPvVars(){
  const props = (PPPV && PPPV.props) || {};
  const out = [];
  ppFields().forEach(f => {
    const prop = props[f.key];
    if(typeof prop !== 'string' || !/^--pl-[a-z0-9-]+$/.test(prop)) return;
    const v = ppPvValue(f);
    if(v !== null) out.push([prop, v]);
  });
  return out;
}

/** Write them onto both frames. Called on every input, and on every load. */
function ppPaintPreview(){
  const frames = $$('[data-ppframe]');
  if(!frames.length) return;
  const vars = ppPvVars();
  frames.forEach(fr => {
    let root = null;
    try{ root = fr.contentDocument && fr.contentDocument.documentElement; }catch(err){ root = null; }
    if(!root) return;
    vars.forEach(pair => root.style.setProperty(pair[0], pair[1]));
  });
}

/** Back to what is SAVED — after a save, and after Discard. */
function ppReloadPreview(){
  const src = ppPvSrc();
  if(!src) return;
  $$('[data-ppframe]').forEach(fr => { fr.setAttribute('src', src); });
}

/* The panel. Drawn ONCE per visit to this screen, by paintProductPage()'s
   shell arm, and never again while he is on it -- re-inserting an <iframe>
   reloads it, so a
   panel rebuilt on every tab click would fetch two product pages every time he
   moved between Spacing and Type. */
function ppPreviewPanel(){
  const src = ppPvSrc();

  if(!src) return `<aside class="ppside"><div class="ppv-in">
      <div class="ppv-hd"><b>Preview</b></div>
      <p class="ppv-empty">There is no visible product to draw yet. Add one under <b>Catalog → Products</b> and this panel will show it at both widths.</p>
    </div></aside>`;

  const pane = (kind, label, note) => `<div class="ppv-pane" data-ppv="${kind}">
      <p class="ppv-cap"><b>${label}</b><a href="${escAttr(src)}" target="_blank" rel="noopener">Full size ↗</a></p>
      <div class="ppv-vp"><div class="ppv-scale"><iframe data-ppframe="${kind}" title="${escAttr(note)}" src="${escAttr(src)}" loading="lazy" referrerpolicy="same-origin"></iframe></div></div>
    </div>`;

  return `<aside class="ppside"><div class="ppv-in">
      <div class="ppv-hd"><b>Preview</b><span>Live — moves as you do</span></div>
      <div class="ppv-pair">
        ${pane('desktop','Laptop · 1280px','Product page preview at 1280px')}
        ${pane('mobile','Phone · 390px','Product page preview at 390px')}
      </div>
      <p class="ppv-note">The real product page, at both widths, updating as you move a control — <b>nothing here is saved until you press Save changes</b>. Scroll inside either frame to reach the detail tabs and the sections under them. The <b>Sections</b> switches change which blocks render at all, so those appear here once you have saved.</p>
    </div></aside>`;
}

function ppTabs(){ return (PP && Array.isArray(PP.layout)) ? PP.layout : []; }
function ppLayoutTab(){ return ppTabs().find(t=>t.key===PPTAB) || null; }

/** Every layout field, flat — used by Save and by Reset. */
function ppFields(){ const out=[]; for(const t of ppTabs()) for(const f of t.fields) out.push(f); return out; }

/** The number the owner reads, from the number the schema stores. */
function ppShow(f){
  const sc=(f.options && +f.options.scale) || 1;
  const unit=(f.options && f.options.unit) || '';
  return (sc>1 ? String(+f.value/sc) : String(f.value)) + unit;
}

function ppField(f){
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${f.value}" data-pl="${escAttr(f.key)}">
        <i id="plv-${escAttr(f.key)}">${escHtml(ppShow(f))}</i></span></div>`; }
  if(f.type==='select')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-pl="${escAttr(f.key)}">${Object.entries(f.options||{}).map(([k,l])=>`<option value="${escAttr(k)}"${String(k)===String(f.value)?' selected':''}>${escHtml(l)}</option>`).join('')}</select></div>`;
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmcol"><input type="color" value="${escAttr(ppPvValue(f))}" data-pl="${escAttr(f.key)}"><code id="plv-${escAttr(f.key)}">${escHtml(ppPvValue(f))}</code></span></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
    <input type="text" value="${escAttr(String(f.value))}" data-pl="${escAttr(f.key)}"></div>`;
}

function paintProductPage(rebuild){
  const lt=ppLayoutTab();
  const strip=`<div class="ectabs">
    <button class="ectab${PPTAB==='sections'?' on':''}" data-pptab="sections">Sections<span class="ecn">${PP.sections.length}</span></button>
    ${ppTabs().map(t=>`<button class="ectab${t.key===PPTAB?' on':''}" data-pptab="${escAttr(t.key)}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}
    ${pyaTab()?`<button class="ectab${PPTAB==='ymal'?' on':''}" data-pptab="ymal">${escHtml(pyaTab().label)}<span class="ecn">${pyaFields().length}</span></button>`:''}
  </div>`;

  const body = PPTAB==='ymal' && pyaTab() ? pyaBody() : lt
    ? `<div class="mmcols"><div class="card mmcard">
         <div class="mmhd"><b>${escHtml(lt.label)}</b><span>${escHtml(lt.description)}</span></div>
         <div class="mmbody">${lt.fields.map(ppField).join('')}</div></div></div>`
    : `<div class="hphead"><span>Module</span><span>Desktop</span><span>Mobile</span></div>
       <div class="hplist">${PP.sections.map((s,i)=>`
      <div class="hprow${(!s.desktop&&!s.mobile)?' alloff':''}" data-i="${i}">
        <div class="hpmove"></div>
        <div class="hpmain"><b>${escHtml(s.label)}</b><span>${escHtml(s.description)}</span></div>
        <span class="ectog${s.desktop?' on':''}" data-pp="${i}" data-k="desktop" role="switch" aria-checked="${s.desktop}" tabindex="0"></span>
        <span class="ectog${s.mobile?' on':''}" data-pp="${i}" data-k="mobile" role="switch" aria-checked="${s.mobile}" tabindex="0"></span>
      </div>`).join('')}</div>`;

  /* ▲ THE SHELL IS WRITTEN ONCE PER VISIT, AND THE PREVIEW IS THE REASON.
       This function used to replace #content wholesale on every tab click.
       With two <iframe>s in it that is two product pages fetched every time he
       moves between Spacing and Type — and worse, a frame re-inserted into the
       document RELOADS, so the picture would flash back to the saved page and
       lose whatever he had just dragged. So the shell (the heading, the strip's
       host, the preview panel and the save bar) is built when the screen is
       entered, and a repaint refills only the three parts that really change.
       renderProductPage() passes `true` because it has just re-fetched. */
  if(rebuild || !$('#ppShell')){
    $('#content').innerHTML = `<div class="wrap ecwrap mmwrap" id="ppShell">
      <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Product page</h2>
        <p class="mdesc" style="margin:0" id="ppLede"></p></div>
      <div id="ppStrip"></div>
      <p class="ectabs-hint">Seven views of one product page, and they do different jobs. <b>Sections</b> switches a whole block of the page on or off, per device — that is the only tab that can make something disappear. The five Layout tabs move what is already there: <b>Photo &amp; badge</b> is the top of the page — the space above the photo, the thumbnails, the discount badge's colours; <b>Spacing</b> is the gaps, between the big blocks of the page and between the elements inside the buy column; <b>Type</b> is the sizes and weights. The preview shown with them is the real product page at both widths — a laptop and a phone, side by side — and it follows every slider as you drag it, before anything is saved; <b>Reset layout to defaults</b> puts them all back. <b>You may also like</b> is the carousel at the foot of the page: which products it suggests and how it moves.</p>
      <div class="ppwrap"><div class="ppcol" id="ppCol"></div>${ppPreviewPanel()}</div>
      <div class="ecsave">
        <span class="ecdirty" id="ppDirty" style="visibility:hidden">Unsaved changes</span>
        <span id="ppActs"></span>
        <button class="btn primary" id="ppSave">Save changes</button>
      </div></div>`;
    /* A frame has no documentElement until it has loaded, so the first paint
       would write thirty properties into nothing. This is the second paint. */
    $$('[data-ppframe]').forEach(fr => fr.addEventListener('load', ppPaintPreview));
  }

  $('#ppLede').textContent = PPTAB==='ymal'
    ? 'The carousel at the foot of every product page — which products it suggests, how many, and how it moves. Saved changes show in the preview after you press Save changes.'
    : lt
    ? 'Spacing and type for one product page. The preview is the real page at both widths — laptop and phone together — and follows every control as you move it.'
    : 'Switch any module off, per device. A module off for both is not rendered at all.';
  $('#ppStrip').innerHTML = strip;
  $('#ppCol').innerHTML = body;
  $('#ppActs').innerHTML = lt
    ? `<button class="btn" id="ppReset">Reset layout to defaults</button>`
    : `<button class="btn" id="ppDiscard">Discard</button>`;

  const dirty = $('#ppDirty');
  if(dirty){
    dirty.classList.remove('ok');
    dirty.textContent = 'Unsaved changes';
    dirty.style.visibility = (PPDIRTY.sections||PPDIRTY.layout||PPDIRTY.also) ? 'visible' : 'hidden';
  }

  $$('[data-pptab]').forEach(b=>b.onclick=()=>{ PPTAB=b.dataset.pptab; paintProductPage(); });
  ppPaintPreview();
}

function ppMarkDirty(half){
  PPDIRTY[half]=true;
  const d=$('#ppDirty'); if(d){ d.style.visibility='visible'; d.classList.remove('ok'); d.textContent='Unsaved changes'; }
}

document.addEventListener('input', e=>{
  const el=e.target.closest('[data-pl]');
  if(!el||!PP) return;
  const f=ppFields().find(x=>x.key===el.dataset.pl);
  if(!f) return;
  f.value = el.type==='range' ? +el.value : (el.type==='color' ? String(el.value).toUpperCase() : el.value);
  const out=$('#plv-'+f.key); if(out) out.textContent=ppShow(f);
  ppMarkDirty('layout');
  /* THE LIVE HALF. `input` and not `change`, so the picture moves while the
     handle is under his finger rather than when he lets go. */
  ppPaintPreview();
});
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-pl]');
  if(!el||!PP) return;
  const f=ppFields().find(x=>x.key===el.dataset.pl);
  if(!f) return;
  f.value=el.value; ppMarkDirty('layout'); ppPaintPreview();
});

document.addEventListener('click', async e=>{
  if(!PP) return;
  const tg=e.target.closest('[data-pp]');
  if(tg){
    const s=PP.sections[+tg.dataset.pp];
    s[tg.dataset.k]=!s[tg.dataset.k];
    tg.classList.toggle('on', s[tg.dataset.k]);
    tg.setAttribute('aria-checked', s[tg.dataset.k]);
    tg.closest('.hprow').classList.toggle('alloff', !s.desktop && !s.mobile);
    ppMarkDirty('sections');
    return;
  }
  if(e.target.id==='ppDiscard'){ PPDIRTY={sections:false,layout:false,also:false}; if(window.kbbDrafts) kbbDrafts.discarded('productpage'); renderProductPage(); return; }
  if(e.target.id==='ppReset'){
    /* The SHIPPED value of every layout field, which is the number the page
       renders with no <style> block at all. Not a save — it fills the buffer
       and the bar says so, exactly as Product styles' own Reset does. */
    ppFields().forEach(f=>{ f.value=f.default; });
    ppMarkDirty('layout'); paintProductPage(); return;
  }
  if(e.target.id!=='ppSave') return;

  const msg=$('#ppDirty');
  const payload={};
  if(PPDIRTY.sections) payload.sections=PP.sections.map(s=>({key:s.key,desktop:s.desktop,mobile:s.mobile}));
  if(PPDIRTY.layout){ payload.layout={}; ppFields().forEach(f=>{ payload.layout[f.key]=f.value; }); }
  if(PPDIRTY.also){ payload.also={}; pyaFields().forEach(f=>{ payload.also[f.key]=f.value; }); }

  if(!payload.sections && !payload.layout && !payload.also){
    if(msg){ msg.style.visibility='visible'; msg.textContent='Nothing has changed.'; }
    return;
  }

  try{
    const r=await fetch(ppBase(),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify(payload)});
    const j=await r.json();
    if(j.ok){
      PP.sections=j.sections; if(Array.isArray(j.layout)) PP.layout=j.layout; if(Array.isArray(j.also)) PP.also=j.also;
      PPDIRTY={sections:false,layout:false,also:false}; if(window.kbbDrafts) kbbDrafts.saved('productpage');
      paintProductPage();
      /* The Sections switches decide which BLOCKS render at all, and no custom
         property can show that — only the page itself can. So a save reloads
         both frames, which is also how the panel proves what was stored rather
         than what is merely on the screen. */
      ppReloadPreview();
      const m2=$('#ppDirty');
      if(m2){ m2.style.visibility='visible'; m2.classList.add('ok'); m2.textContent=`Saved ${j.saved} settings — live now`;
        setTimeout(()=>{m2.classList.remove('ok');m2.textContent='Unsaved changes';m2.style.visibility='hidden';},2600); }
    }else{ msg.style.visibility='visible'; msg.textContent=j.error||'Could not save.'; }
  }catch(err){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
});
document.addEventListener('keydown', e=>{
  if(e.target.dataset && e.target.dataset.pp && (e.key===' '||e.key==='Enter')){ e.preventDefault(); e.target.click(); }
});

/* ═════════════════════════════════════════════════════════════════════════
   Appearance → Product page → You may also like.               (Lane PS)

     "You may also like should be a slider on each product page, I need it
      carousel by suggesting products from the same brand and category mixed.
      Give us control to choose the products query what to show etc, or
      manual selection also."

   A SIXTH TAB, AND A THIRD HALF. `PP.also` is the same ModuleSchema::tabs()
   payload `PP.layout` is (App\Services\AlsoLikeSettings), posted back under
   its own `also` key — so saving the carousel never rewrites the module
   switches or the thirty layout values, and they never rewrite it.

   Its fields carry `data-pya`, NOT `data-pl`: the layout tabs' input handler
   looks every `data-pl` up in ppFields() and marks the LAYOUT half dirty, so
   sharing the attribute would have posted these keys to the layout half,
   which refuses them as unknown. Switches are `.ectog` like the Sections tab's
   own, so a bool reads the same here as everywhere else on this screen.
   ═════════════════════════════════════════════════════════════════════════ */
function pyaTab(){ return (PP && Array.isArray(PP.also) && PP.also[0]) ? PP.also[0] : null; }
function pyaFields(){ const t=pyaTab(); return t ? t.fields : []; }
function pyaField(f){
  const lbl=`<div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>`;
  if(f.type==='bool')
    return `<div class="mmrow">${lbl}<span class="ectog${f.value?' on':''}" data-pya-tog="${escAttr(f.key)}" role="switch" aria-checked="${f.value?'true':'false'}" aria-label="${escAttr(f.label)}" tabindex="0"></span></div>`;
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow">${lbl}<span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${escAttr(String(f.value))}" data-pya="${escAttr(f.key)}">
      <i id="pyav-${escAttr(f.key)}">${escHtml(String(f.value))}${escHtml(o.unit||'')}</i></span></div>`; }
  if(f.type==='select')
    return `<div class="mmrow">${lbl}<select data-pya="${escAttr(f.key)}">${Object.entries(f.options||{}).map(([k,l])=>`<option value="${escAttr(k)}"${String(k)===String(f.value)?' selected':''}>${escHtml(l)}</option>`).join('')}</select></div>`;
  const rtl = /_ar$/.test(f.key) ? ' dir="rtl" lang="ar"' : '';
  return `<div class="mmrow">${lbl}<input type="text" maxlength="60" value="${escAttr(String(f.value))}" data-pya="${escAttr(f.key)}"${rtl}></div>`;
}
function pyaBody(){
  const t=pyaTab();
  if(!t) return '';
  return `<div class="mmcols"><div class="card mmcard">
      <div class="mmhd"><b>${escHtml(t.label)}</b><span>${escHtml(t.description)}</span></div>
      <div class="mmbody">${t.fields.map(pyaField).join('')}</div></div>
    <p class="mdesc" style="margin-top:12px">Per product: open any product in <b>Catalog → Products</b> and use its <b>You may also like</b> panel to hand-pick products, and choose whether they come first or replace this rule. Showing it on a phone or a laptop only is the <b>You may also like</b> row on the <b>Sections</b> tab.</p></div>`;
}
document.addEventListener('input', e=>{
  const el=e.target.closest('[data-pya]');
  if(!el||!PP) return;
  const f=pyaFields().find(x=>x.key===el.dataset.pya);
  if(!f) return;
  f.value = el.type==='range' ? +el.value : el.value;
  const out=$('#pyav-'+f.key); if(out) out.textContent=String(f.value)+((f.options&&f.options.unit)||'');
  ppMarkDirty('also');
});
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-pya]');
  if(!el||!PP) return;
  const f=pyaFields().find(x=>x.key===el.dataset.pya);
  if(!f) return;
  f.value=el.value; ppMarkDirty('also');
});
document.addEventListener('click', e=>{
  const tg=e.target.closest('[data-pya-tog]');
  if(!tg||!PP) return;
  const f=pyaFields().find(x=>x.key===tg.dataset.pyaTog);
  if(!f) return;
  f.value=!f.value;
  tg.classList.toggle('on', f.value);
  tg.setAttribute('aria-checked', f.value?'true':'false');
  ppMarkDirty('also');
});
document.addEventListener('keydown', e=>{
  if(e.target.dataset && e.target.dataset.pyaTog && (e.key===' '||e.key==='Enter')){ e.preventDefault(); e.target.click(); }
});




/* ---------- Appearance · Product styles ----------
   Two halves: how cards look, and a builder that writes a shortcode for
   dropping a grid into any page. */
let PS=null, PSTAB='layout';

function psBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/product-styles'; }

async function renderProdStyles(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Product styles</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(psBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    PS=await r.json();
  }catch(e){
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">Could not load product styles. <button class="btn small" onclick="renderProdStyles()">Retry</button></div></div>`;
    return;
  }
  paintProdStyles(); if(window.kbbDrafts) kbbDrafts.ready('prodstyles');
}
function psGet(k){ for(const t of PS.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function psSet(k,v){ for(const t of PS.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function psField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-ps="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-ps="${f.key}">
        <i id="psv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='select')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <select data-ps="${f.key}">${Object.entries(f.options||{}).map(([k,l])=>`<option value="${k}"${k===v?' selected':''}>${escHtml(l)}</option>`).join('')}</select></div>`;
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-ps="${f.key}"><code>${v}</code></span></div>`;
  if(f.type==='skin')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="skinpick">
        <button class="skinpick-b" type="button" data-open="ps">${escHtml((PS.skins.find(k=>k.key===v)||{}).label||v)}<i>&#9662;</i></button>
        <span class="skinpop" data-pop="ps">${PS.skins.map(k=>`<button class="skinopt${k.key===v?' on':''}" type="button" data-psskin="${escAttr(k.key)}">
          <span class="skinprev">${skinCard(k.key)}</span><span class="skinopt-l">${escHtml(k.label)}</span></button>`).join('')}</span>
      </span></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
    <input type="text" value="${escAttr(String(v))}" data-ps="${f.key}"></div>`;
}

function paintProdStyles(){
  const tab=PS.tabs.find(t=>t.key===PSTAB)||PS.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Product styles</h2>
      <p class="mdesc" style="margin:0">How product cards look everywhere — grids, rails, search results and shortcodes.</p></div>
    <div class="ectabs">${PS.tabs.map(t=>`<button class="ectab${t.key===PSTAB?' on':''}" data-pstab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}
      <button class="ectab${PSTAB==='shortcode'?' on':''}" data-pstab="shortcode">Shortcode builder</button></div>
    ${PSTAB==='shortcode' ? psBuilder() : `
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(psField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in"><span class="skinprev" id="psPrev"></span></div>
        <p class="mmpv-note">Live preview${PSTAB==='spacing'?' · desktop values':''}</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="psDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="psReset">Reset to defaults</button>
      <button class="btn primary" id="psSave">Save changes</button>
    </div>`}
  </div>`;
  if(PSTAB!=='shortcode') psPreview();
  else psShortcode();
}

/* Image shape -> aspect-ratio, the SAME four pairs ProductStyles::cssVariables()
   matches on, because the preview and the shop must not disagree about what a
   word means. Lane AD wired that setting to the storefront this round; until
   then the preview emitted no --kbb-ratio at all and fell back to the rule at
   line 1730, which is why nobody noticed the two had drifted. An unknown value
   falls to 1/1 here exactly as `default` does there. */
const PS_RATIO={square:'1/1',portrait:'1/1.02',tall:'1/1.25',landscape:'1.2/1'};

function psPreview(){
  const el=$('#psPrev'); if(!el) return;
  el.className='skinprev ' + ['show_brand|pc-nobrand','show_category|pc-nocat','show_rating|pc-norate',
    'show_was_price|pc-nowas','show_discount|pc-nodisc','show_new|pc-nonew','show_cart|pc-nocart']
    .filter(p=>!psGet(p.split('|')[0])).map(p=>p.split('|')[1]).join(' ');
  el.setAttribute('style',`--kbb-sale:${psGet('sale_colour')};--kbb-new:${psGet('new_colour')};
    --kbb-price:${psGet('price_colour')};--kbb-star:${psGet('star_colour')};
    --kbb-cart-bg:${psGet('cart_bg')};--kbb-cart-fg:${psGet('cart_fg')};
    --kbb-radius:${psGet('card_radius')}px;--kbb-ratio:${PS_RATIO[psGet('image_ratio')] || '1/1'};--kbb-name-lines:${psGet('name_lines')}`);
  el.innerHTML=(PSTAB==='spacing' ? psSpaceCss() : '') + skinCard(psGet('grid_skin'));
}

/* ── Spacing & type, previewed at the DESKTOP values ─────────────── Lane PR ──
   On that tab only, the preview card is drawn with every desktop number on the
   tab — not just the moved ones, because this console's preview card has its
   own 12px padding and the shop's has 16, and a preview that showed a slider
   moving from the wrong starting point would be a preview that lies.
   ProductStyles::cardCss() is what the SHOP gets; this is its picture.
   Rule 5: a range is a number (+v, clamped to the field's own bounds) and a
   select must be one of the field's own option keys, or the line is skipped. */
function psSpaceCss(){
  const f=k=>{ for(const t of PS.tabs){ const x=t.fields.find(y=>y.key===k); if(x) return x; } return null; };
  const num=k=>{ const x=f(k); if(!x) return null; const o=x.options||{}; const n=+x.value;
    return Number.isFinite(n) ? Math.min(+o.max, Math.max(+o.min, n)) : null; };
  const opt=k=>{ const x=f(k); return x && x.options && Object.prototype.hasOwnProperty.call(x.options, String(x.value)) ? String(x.value) : null; };
  const T='#psPrev .kbb-tile', r=[];
  const pad=num('card_pad_d'), img=num('card_gap_img_d'), pr=num('card_gap_price_d'), ca=num('card_gap_cart_d');
  if(pad!==null) r.push(`${T} .cb{padding-inline:${pad}px;padding-bottom:${pad}px}`);
  if(img!==null) r.push(`${T} .cb{padding-top:${img}px}`);
  const SC='#psPrev .kbb-pgrid[data-skin^="showcase"] .kbb-tile', NS='#psPrev .kbb-pgrid:not([data-skin^="showcase"]) .kbb-tile';
  if(pr!==null) r.push(`${SC} .cp{padding-top:${pr}px;margin-top:0}${NS} .cp{margin-top:${pr}px}`);
  if(ca!==null) r.push(`${SC} .kbb-card-cart{margin-top:${ca}px}${NS} .cp{margin-bottom:${ca}px}${NS} .kbb-card-cart{margin-top:0}`);
  const br=num('card_gap_brand_d'), rt=num('card_gap_rate_d');
  if(br!==null) r.push(`${T} .kbb-card-brand{margin-bottom:${br}px}`);
  if(rt!==null) r.push(`${T} .kbb-card-rate{margin-top:${rt}px}`);
  [['card_fs_title_d','.kbb-card-nm','font-size'],['card_fs_price_d','.kbb-card-price','font-size'],
   ['card_fs_btn_d','.kbb-card-cart','font-size'],['card_fs_brand_d','.kbb-card-brand','font-size'],
   ['card_fw_title','.kbb-card-nm','font-weight'],['card_fw_price','.kbb-card-price','font-weight'],
   ['card_fw_sale','.kbb-card-reg+.kbb-card-price','font-weight'],['card_fw_btn','.kbb-card-cart','font-weight'],
   ['card_fw_brand','.kbb-card-brand','font-weight']]
    .forEach(([k,sel,prop])=>{ const v=opt(k); if(v!==null) r.push(`${T} ${sel}{${prop}:${v}}`); });
  return `<style>${r.join('')}</style>`;
}

/* ---- shortcode builder ---- */
const SC={source:'new',limit:8,columns:4,skin:'',category:'',brand:'',ids:'',title:'',link:'',orderby:''};
function psBuilder(){
  const opt=(v,l,cur)=>`<option value="${escAttr(v)}"${v===cur?' selected':''}>${escHtml(l)}</option>`;
  return `<div class="mmgrid"><div class="mmcols"><div class="card mmcard">
    <div class="mmhd"><b>Shortcode builder</b><span>Choose what to show, then paste the shortcode into any page or post.</span></div>
    <div class="mmbody">
      <div class="mmrow"><div class="mmlbl"><b>Products</b><span>Where the products come from.</span></div>
        <select data-sc="source">
          ${opt('new','Newest',SC.source)}${opt('bestsellers','Best sellers',SC.source)}${opt('sale','On sale',SC.source)}
          ${opt('featured','Featured',SC.source)}${opt('top_rated','Top rated',SC.source)}${opt('in_stock','In stock',SC.source)}
          ${opt('category','From a category',SC.source)}${opt('brand','From a brand',SC.source)}${opt('ids','Chosen by hand',SC.source)}
        </select></div>
      ${SC.source==='category'?`<div class="mmrow"><div class="mmlbl"><b>Category</b></div>
        <select data-sc="category">${PS.categories.map(c=>opt(c.slug,c.name,SC.category)).join('')}</select></div>`:''}
      ${SC.source==='brand'?`<div class="mmrow"><div class="mmlbl"><b>Brand</b></div>
        <select data-sc="brand">${PS.brands.map(b=>opt(b.slug,b.name,SC.brand)).join('')}</select></div>`:''}
      ${SC.source==='ids'?`<div class="mmrow"><div class="mmlbl"><b>Product ids</b><span>Comma separated. They keep this order.</span></div>
        <input type="text" data-sc="ids" value="${escAttr(SC.ids)}" placeholder="12, 47, 103"></div>`:''}
      <div class="mmrow"><div class="mmlbl"><b>How many</b></div>
        <span class="mmrange"><input type="range" min="2" max="24" value="${SC.limit}" data-sc="limit"><i id="scv-limit">${SC.limit}</i></span></div>
      <div class="mmrow"><div class="mmlbl"><b>Columns</b></div>
        <span class="mmrange"><input type="range" min="2" max="6" value="${SC.columns}" data-sc="columns"><i id="scv-columns">${SC.columns}</i></span></div>
      <div class="mmrow"><div class="mmlbl"><b>Card style</b><span>Leave as default to follow the setting above.</span></div>
        <select data-sc="skin">${opt('','Use the default',SC.skin)}${PS.skins.map(k=>opt(k.key,k.label,SC.skin)).join('')}</select></div>
      <div class="mmrow"><div class="mmlbl"><b>Heading</b><span>Optional, shown above the grid.</span></div>
        <input type="text" data-sc="title" value="${escAttr(SC.title)}" placeholder="Best sellers"></div>
      <div class="mmrow"><div class="mmlbl"><b>View all link</b><span>Optional.</span></div>
        <input type="text" data-sc="link" value="${escAttr(SC.link)}" placeholder="/shop/"></div>
    </div></div>

    <div class="card mmcard"><div class="mmhd"><b>Your shortcode</b><span>Paste this anywhere that accepts content.</span></div>
      <div class="mmbody"><code class="scout" id="scOut"></code>
        <button class="btn small" id="scCopy" style="margin-top:10px">Copy</button>
        <span id="scCopied" class="scok"></span></div></div>
  </div>
  <div class="mmpv"><div class="mmpv-in"><span class="skinprev" id="scPrev"></span></div>
    <p class="mmpv-note">Card style used</p></div></div>`;
}
function psShortcode(){
  const a=[];
  if(SC.source==='category') a.push(`category="${SC.category||PS.categories[0]?.slug||''}"`);
  else if(SC.source==='brand') a.push(`brand="${SC.brand||PS.brands[0]?.slug||''}"`);
  else if(SC.source==='ids') a.push(`ids="${SC.ids}"`);
  else a.push(`source="${SC.source}"`);
  a.push(`limit="${SC.limit}"`,`columns="${SC.columns}"`);
  if(SC.skin) a.push(`skin="${SC.skin}"`);
  if(SC.title) a.push(`title="${SC.title}"`);
  if(SC.link) a.push(`link="${SC.link}"`);
  const out=`[kbb_products ${a.join(' ')}]`;
  const el=$('#scOut'); if(el) el.textContent=out;
  const pv=$('#scPrev'); if(pv) pv.innerHTML=skinCard(SC.skin||psGet('grid_skin'));
}

document.addEventListener('input', e=>{
  const el=e.target.closest('[data-ps]');
  if(el&&PS){ let v=el.value; if(el.type==='range'){ v=+v;
      const o=$('#psv-'+el.dataset.ps); if(o){ let u=''; for(const t of PS.tabs){const f=t.fields.find(x=>x.key===el.dataset.ps); if(f&&f.options) u=f.options.unit||'';} o.textContent=v+u; } }
    if(el.type==='color'){ const c=el.nextElementSibling; if(c) c.textContent=v; }
    psSet(el.dataset.ps,v); psPreview(); const d=$('#psDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent='Unsaved changes';}
    return; }
  const sc=e.target.closest('[data-sc]');
  if(sc){ SC[sc.dataset.sc]= sc.type==='range' ? +sc.value : sc.value;
    if(sc.type==='range'){ const o=$('#scv-'+sc.dataset.sc); if(o) o.textContent=sc.value; }
    psShortcode(); }
});
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-ps]');
  if(el&&PS){ psSet(el.dataset.ps, el.value); psPreview(); const d=$('#psDirty'); if(d){d.style.visibility='visible';d.textContent='Unsaved changes';} return; }
  const sc=e.target.closest('select[data-sc]');
  if(sc){ SC[sc.dataset.sc]=sc.value; if(sc.dataset.sc==='source') paintProdStyles(); else psShortcode(); }
});
document.addEventListener('click', async e=>{
  if(!PS) return;
  const tb=e.target.closest('[data-pstab]');
  if(tb){ PSTAB=tb.dataset.pstab; paintProdStyles(); return; }
  const sk=e.target.closest('[data-psskin]');
  if(sk){ psSet('grid_skin', sk.dataset.psskin); paintProdStyles();
    const d=$('#psDirty'); if(d){d.style.visibility='visible';d.textContent='Unsaved changes';} return; }
  if(e.target.id==='scCopy'){
    const t=$('#scOut').textContent;
    try{ await navigator.clipboard.writeText(t); }catch(err){
      const ta=document.createElement('textarea'); ta.value=t; document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); ta.remove(); }
    const ok=$('#scCopied'); if(ok){ ok.textContent='Copied'; setTimeout(()=>ok.textContent='',1800); }
    return; }
  if(e.target.id==='psReset'){ PS.tabs.forEach(t=>t.fields.forEach(f=>f.value=f.default)); paintProdStyles(); return; }
  if(e.target.id!=='psSave') return;
  const payload={}; PS.tabs.forEach(t=>t.fields.forEach(f=>payload[f.key]=f.value));
  const msg=$('#psDirty');
  try{
    const r=await fetch(psBase(),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({settings:payload})});
    const j=await r.json();
    if(j.ok){ if(window.kbbDrafts) kbbDrafts.saved('prodstyles'); msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} settings — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600); }
    else { msg.style.visibility='visible'; msg.textContent=j.error||'Could not save.'; }
  }catch(err){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
});


/* ---- trending words ---- */
function hdWords(){ return String(hdGet('trending_words')||'').split(',').map(w=>w.trim()).filter(Boolean); }
function hdSetWords(list){
  const seen=new Set(), out=[];
  list.forEach(w=>{ w=String(w).trim().replace(/\s+/g,' ');
    if(w && w.length<=40 && !seen.has(w.toLowerCase())){ seen.add(w.toLowerCase()); out.push(w); } });
  hdSet('trending_words', out.slice(0,20).join(', '));
  paintHeader(); hdDirty();
}
document.addEventListener('click', e=>{
  if(!HD) return;
  const del=e.target.closest('[data-tagdel]');
  if(del){ const w=hdWords(); w.splice(+del.dataset.tagdel,1); hdSetWords(w); return; }
  const add=e.target.closest('[data-tagadd]');
  if(add){ hdSetWords(hdWords().concat(add.dataset.tagadd)); return; }
  if(e.target.id==='tagAdd'){
    const inp=$('#tagInput'); if(inp && inp.value.trim()){ hdSetWords(hdWords().concat(inp.value.split(','))); }
    return; }
});
document.addEventListener('keydown', e=>{
  if(e.target.id!=='tagInput') return;
  if(e.key==='Enter'){ e.preventDefault(); if(e.target.value.trim()) hdSetWords(hdWords().concat(e.target.value.split(','))); }
  // Backspace on an empty field removes the last word, as tag fields usually do.
  if(e.key==='Backspace' && e.target.value===''){ const w=hdWords(); w.pop(); hdSetWords(w); }
});



/* ---------- Growth & Marketing · Newsletter ----------
   Wording, messages and colours for the homepage signup, plus the list itself.
   Whether the block shows at all stays in Appearance → Homepage; two switches
   for one thing is how a setting ends up doing nothing. */
let NL=null, NLTAB='content';

function nlBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/newsletter'; }

async function renderNewsletter(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Newsletter</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(nlBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    NL=await r.json();
  }catch(e){
    /* Name the failure. The first version said only "could not load", which is
       the same sentence for a 404 from a stale route cache and a 500 from a
       query — two problems with nothing in common except the message. */
    const why = String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered. The cache-clearing migration for this release may not have run — check Platform → Core Updates.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log for the last entry.'
      : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the newsletter settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderNewsletter()">Retry</button></div></div>`;
    return;
  }
  paintNewsletter(); if(window.kbbDrafts) kbbDrafts.ready('newsletter');
}
function nlGet(k){ for(const t of NL.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function nlSet(k,v){ for(const t of NL.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function nlField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-nl="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmcol"><input type="color" value="${v}" data-nl="${f.key}"><code>${v}</code></span></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
    <input type="text" value="${escAttr(String(v))}" data-nl="${f.key}"></div>`;
}

function nlPreview(){
  const from=nlGet('nl_bg_from'), to=nlGet('nl_bg_to');
  return `<div class="nlprev" style="background:linear-gradient(140deg,${escAttr(from)},${escAttr(to)})">
    <div class="nlprev-k" style="color:${escAttr(nlGet('nl_note_colour'))}">${escHtml(nlGet('nl_eyebrow'))}</div>
    <div class="nlprev-h">${escHtml(nlGet('nl_heading'))}</div>
    <div class="nlprev-s">${escHtml(nlGet('nl_subheading'))}</div>
    <div class="nlprev-f"><span>${escHtml(nlGet('nl_placeholder'))}</span>
      <b style="background:${escAttr(nlGet('nl_btn_bg'))};color:${escAttr(nlGet('nl_btn_fg'))}">${escHtml(nlGet('nl_button'))}</b></div>
    <div class="nlprev-n" style="color:${escAttr(nlGet('nl_note_colour'))}">${escHtml(nlGet('nl_success'))}</div>
  </div>`;
}

function paintNewsletter(){
  const tab=NL.tabs.find(t=>t.key===NLTAB)||NL.tabs[0];
  const st=NL.stats||{total:0,week:0,latest:null};
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Newsletter</h2>
      <p class="mdesc" style="margin:0">Wording and colours for the signup panel, and the list it collects.
      Whether the panel appears is set in <b>Appearance → Homepage</b>.</p></div>
    ${st.ready===false ? `<div class="nlwarn">The subscriber list table has not been created yet, so counts and the CSV are unavailable.
      Everything on this screen still saves. Apply the latest update, or re-run migrations, and this will fill in.</div>` : `
    <div class="nlstats">
      <div class="nlstat"><b>${st.total}</b><span>on the list</span></div>
      <div class="nlstat"><b>${st.week}</b><span>this week</span></div>
      <div class="nlstat wide"><b>${st.latest?escHtml(st.latest):'—'}</b><span>most recent</span></div>
      <a class="btn small" href="${nlBase()}/export">Download CSV</a>
    </div>`}
    <div class="ectabs">${NL.tabs.map(t=>`<button class="ectab${t.key===NLTAB?' on':''}" data-nltab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(nlField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in">${nlPreview()}</div><p class="mmpv-note">Live preview</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="nlDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="nlSave">Save changes</button>
    </div>
  </div>`;
  bindNewsletter();
}

function nlDirty(){ const d=$('#nlDirty'); if(d) d.style.visibility='visible'; }

function bindNewsletter(){
  $$('[data-nltab]').forEach(b=>b.onclick=()=>{ NLTAB=b.dataset.nltab; paintNewsletter(); });

  $$('[data-nl]').forEach(el=>{
    const k=el.dataset.nl;
    if(el.classList.contains('ectog')){
      el.onclick=()=>{ const v=!el.classList.contains('on'); el.classList.toggle('on',v);
        el.setAttribute('aria-checked',String(v)); nlSet(k,v); nlDirty(); paintNewsletter(); };
      return;
    }
    el.oninput=()=>{ nlSet(k,el.value); nlDirty();
      /* Colour and text both feed the preview, so repaint rather than patching
         individual nodes — the panel is small and this cannot drift. */
      const active=document.activeElement===el?k:null;
      paintNewsletter();
      if(active){ const again=document.querySelector(`[data-nl="${active}"]`); if(again){ again.focus(); if(again.setSelectionRange && again.type==='text') again.setSelectionRange(again.value.length,again.value.length); } }
    };
  });

  const save=$('#nlSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of NL.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(nlBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      if(d.stats) NL.stats=d.stats;
      if(window.kbbDrafts) kbbDrafts.saved('newsletter'); $('#nlDirty').style.visibility='hidden';
      toast('Newsletter settings saved');
    }catch(e){ toast('Could not save: '+e.message,'bad'); }
    finally{ save.disabled=false; }
  };
}





/* ---------- Store · Modules ----------
   The 29 modules from KBB Modules v2.39.0, in the plugin's own eight groups with
   its names, descriptions and defaults carried across. Each is an on/off switch
   plus the devices it is allowed on — the per-device part is this app's addition.
   Where a module's settings already live on another screen, the row says so
   rather than duplicating the controls. */
let MD=null, MDQ='';

function mdBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/modules'; }

async function renderModules(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Modules</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(mdBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    MD=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the modules.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderModules()">Retry</button></div></div>`;
    return;
  }
  paintModules();
}
function mdFind(k){ for(const g of MD.groups){ const m=g.modules.find(x=>x.key===k); if(m) return m; } return null; }
function mdDirty(){ const d=$('#mdDirty'); if(d) d.style.visibility='visible'; }

let mdAutosaveTimer;
/**
 * The on/off switch saves itself the moment it's clicked, rather than
 * waiting on the page's own "Save changes" button. That button still
 * exists for the desktop/mobile scope choice below each module, but a
 * toggle that visually flips to "on" without actually being saved yet —
 * easy to miss, easy to walk away from — is exactly the kind of thing
 * that looks enabled right up until the next page load undoes it.
 * A short debounce lets a few quick toggles in a row become one request
 * instead of a flood of them, without changing what actually gets saved.
 */
function mdAutosave(key, on){
  clearTimeout(mdAutosaveTimer);
  mdAutosaveTimer = setTimeout(async () => {
    const modules={};
    MD.groups.forEach(g=>g.modules.forEach(m=>{ modules[m.key]={on:m.on,device:m.device}; }));
    try{
      const r=await fetch(mdBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({modules})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      if(d.counts) MD.counts=d.counts;
      toast((on ? 'Turned on: ' : 'Turned off: ') + (mdFind(key)?.name || key));
    }catch(e){
      // Roll the switch back rather than leave it showing "on" when it
      // isn't really saved — the exact gap this exists to close.
      const m=mdFind(key); if(m) m.on=!on;
      paintModules();
      toast('Could not save that: ' + e.message, 'bad');
    }
  }, 350);
}


/* The hover card.
   Each module names a surface and a strip of it. The wireframes below are the
   shapes of the real pages — header, homepage, product, cart panel, cart page,
   checkout, grid — with the named strip lit in pink. Modules that render nothing
   visible say so instead of lighting an arbitrary band, which would be a lie. */
const MD_SURFACE = {
  header:   ['Header',        ['top','nav','mid']],
  home:     ['Homepage',      ['top','mid','bottom']],
  product:  ['Product page',  ['top','mid','bottom']],
  grid:     ['Product cards', ['card','all']],
  drawer:   ['Cart panel',    ['top','mid','bottom']],
  cartpage: ['Cart page',     ['top','mid','bottom']],
  checkout: ['Checkout',      ['top','mid','bottom','aside']],
  site:     ['Every page',    ['all']],
};

function mdWire(surface, band){
  const lit = (b) => band === b || band === 'all' ? ' lit' : '';
  if (surface === 'grid') {
    return `<div class="mdw mdw-grid">
      ${[0,1,2,3].map(()=>`<span class="mdw-card${band==='card'||band==='all'?' lit':''}"><i></i><u></u></span>`).join('')}
    </div>`;
  }
  if (surface === 'site') {
    return `<div class="mdw mdw-page"><span class="mdw-hd"></span><span class="mdw-b"></span>
      <span class="mdw-b"></span><span class="mdw-b short"></span><span class="mdw-ft"></span>
      <b class="mdw-none">nothing visible</b></div>`;
  }
  if (surface === 'checkout') {
    return `<div class="mdw mdw-co">
      <span class="mdw-hd${lit('top')}"></span>
      <div class="mdw-cols">
        <div class="mdw-main"><span class="mdw-b${lit('mid')}"></span><span class="mdw-b${lit('mid')}"></span>
          <span class="mdw-b short${lit('mid')}"></span><span class="mdw-cta${lit('bottom')}"></span></div>
        <div class="mdw-aside${lit('aside')}"><i></i><i></i><i></i></div>
      </div></div>`;
  }
  if (surface === 'drawer') {
    return `<div class="mdw mdw-dr">
      <span class="mdw-tabs${lit('top')}"></span>
      <span class="mdw-b${lit('top')}"></span>
      <div class="mdw-list${lit('mid')}"><i></i><i></i><i></i></div>
      <span class="mdw-b short${lit('bottom')}"></span>
      <span class="mdw-cta${lit('bottom')}"></span></div>`;
  }
  if (surface === 'header') {
    return `<div class="mdw mdw-page">
      <span class="mdw-strip${lit('top')}"></span>
      <span class="mdw-hd${lit('mid')}"></span>
      <span class="mdw-nav${lit('nav')}"></span>
      <span class="mdw-b"></span><span class="mdw-b short"></span></div>`;
  }
  // homepage, product page and cart page share the same three-band shape
  return `<div class="mdw mdw-page">
    <span class="mdw-hd"></span>
    <span class="mdw-hero${lit('top')}"></span>
    <div class="mdw-mid${lit('mid')}"><i></i><i></i></div>
    <span class="mdw-b${lit('bottom')}"></span>
    <span class="mdw-ft${lit('bottom')}"></span></div>`;
}

function mdCard(m){
  const s = MD_SURFACE[m.surface] || ['', []];
  return `<span class="mdpop">
    <b class="mdpop-h">${escHtml(m.name)}</b>
    <span class="mdpop-s">${escHtml(s[0])}</span>
    ${mdWire(m.surface, m.band)}
    <span class="mdpop-t">${escHtml(m.where)}</span>
    ${m.route?`<span class="mdpop-l">Opens ${escHtml(m.screen)}</span>`
             :m.screen?`<span class="mdpop-l none">${escHtml(m.screen)} — screen not built yet</span>`:''}
  </span>`;
}

/* The settings line on each row.
   Three states, because a link that goes nowhere is worse than no link:
     - a console route exists  -> a real link straight to that screen
     - a screen is named but not built -> the path, greyed, saying so
     - no settings at all      -> says that
   The plugin puts sixteen modules' settings on their own page; those pages are
   the next block of Phase 3, and until they exist their rows say so. */
function mdSettings(m){
  if (m.route) {
    /* A route may name a sub-tab as "screen:tab" (product_sorting points at
       catalog:reorder). Without this the link lands on Catalog's Products tab
       and the owner has to know which of six tabs the module meant. */
    const parts=String(m.route).split(':'), rid=parts[0], rtab=parts[1]||'';
    return `<a class="mdlink go" href="#${escAttr(rid)}" onclick="go('${escAttr(rid)}'${rtab?`,'${escAttr(rtab)}'`:''});return false;">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
      ${escHtml(m.screen)}</a>`;
  }
  if (m.screen) return `<em class="mdlink soon">${escHtml(m.screen)} — not built yet</em>`;
  return '<em class="mdlink none">No settings</em>';
}

function mdRow(m){
  const q=MDQ.toLowerCase();
  if(q && !(m.name+' '+m.desc+' '+m.key).toLowerCase().includes(q)) return '';
  const devs=Object.keys(MD.devices).map(d=>`<option value="${escAttr(d)}"${d===m.device?' selected':''}>${escHtml(MD.devices[d])}</option>`).join('');
  /* A toggle is only offered where something actually reads it. The other two
     states show the switch's position but will not let it be moved — a control
     that appears to work and does nothing is worse than one that says why. */
  const live = m.status === 'live';
  /* 'screen' is a built admin screen listed here so the owner can see it exists
     and open it — Media Library and Reviews. It is NOT a switch and must not be
     labelled like one: 'elsewhere' renders "Switched in <screen>", and there is
     no switch on either of those screens to be switched in. See the long note
     on `screen` in App\Services\ModuleRegistry. */
  const note = m.status === 'elsewhere'
      ? `<i class="mdstat where">Switched in ${escHtml(m.screen)}</i>`
      : m.status === 'screen' ? '<i class="mdstat where">Always on — a screen, not a switch</i>'
      /* 'inherent': the work is already done on every page and a switch could
         only turn it off. Worded as an answer, not as a promise — this row read
         "Not ported yet" for as long as the thing it describes had been
         shipped. See the note on the `performance` row in ModuleRegistry. */
      : m.status === 'inherent' ? '<i class="mdstat where">Already applied to every page — nothing to switch</i>'
      : m.status === 'todo' ? '<i class="mdstat todo">Not ported yet</i>' : '';

  /* A `screen` row shows NO switch. The inert switch the other two non-live
     states use renders in the grey "off" position whatever m.on says, which
     beside the words "Always on" is a straight contradiction — the owner reads
     a control that looks switched off on a screen that is always there.
     Hidden rather than removed so the row still lines up with its neighbours. */
  const sw = (m.status === 'screen' || m.status === 'inherent')
    ? '<span class="ectog off" style="visibility:hidden" aria-hidden="true"></span>'
    : `<span class="ectog${m.on?' on':''}${live?'':' off'}"${live?` data-md="${escAttr(m.key)}" role="switch" aria-checked="${m.on}" tabindex="0"`:' aria-disabled="true"'}></span>`;

  return `<div class="mdrow${m.on?' on':''}${live?'':' inert'}">
    ${sw}
    <div class="mdlbl">
      <b>${escHtml(m.name)}</b>${m.default?'':'<i class="mdoff">off by default</i>'}${note}
      <span>${escHtml(m.desc)}</span>
      ${mdSettings(m)}
    </div>
    <span class="mdeye" tabindex="0" aria-label="Where this shows">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.6"/></svg>
      ${mdCard(m)}
    </span>
    <select class="mddev" data-mddev="${escAttr(m.key)}"${(m.on && m.status!=='screen' && m.status!=='inherent')?'':' disabled'}>${devs}</select>
  </div>`;
}

function paintModules(){
  const c=MD.counts;
  /* Two columns on a wide screen, one on a narrow one.
     The groups are dealt into the two columns here rather than left to CSS: the
     eight of them are very uneven — Checkout has eleven modules, three groups
     have one — so a plain two-column grid would leave one side half empty, and
     CSS multi-column would put an absolutely positioned hover card inside a
     fragmentation context, which is where popovers land in the wrong place.
     Dealt largest-first into whichever column is shorter, recounted on every
     repaint so filtering rebalances. */
  const cards=MD.groups.map(g=>{
    const rows=g.modules.map(mdRow).join('');
    if(!rows.trim()) return null;
    const on=g.modules.filter(m=>m.on).length;
    const shown=(rows.match(/class="mdrow/g)||[]).length;
    return {shown, html:`<div class="card mdcard">
      <div class="mmhd"><b>${escHtml(g.label)}</b>
        <span class="mdghd"><em>${on} of ${g.modules.length} on</em>
          <span class="mdgbtns">
            <button class="btn" data-mdgall="${escAttr(g.key)}">All on</button>
            <button class="btn" data-mdgnone="${escAttr(g.key)}">All off</button>
            <button class="btn" data-mdgdef="${escAttr(g.key)}">Defaults</button>
          </span></span></div>
      <div class="mdbody">${rows}</div></div>`};
  }).filter(Boolean);

  const col=[[],[]], height=[0,0];
  [...cards].sort((a,b)=>b.shown-a.shown).forEach(c=>{
    const i = height[0] <= height[1] ? 0 : 1;
    col[i].push(c); height[i] += c.shown + 2;   // +2 for the group heading
  });
  const body = cards.length
    ? `<div class="mdcols"><div class="mdcol">${col[0].map(c=>c.html).join('')}</div>
       <div class="mdcol">${col[1].map(c=>c.html).join('')}</div></div>`
    : '';

  $('#content').innerHTML=`<div class="wrap ecwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Modules</h2>
      <p class="mdesc" style="margin:0">Every feature is an independent switch — the storefront renders with all of them off.
      <b>${c.on} of ${c.total} on.</b></p></div>
    <div class="mdtools"><input type="search" id="mdSearch" placeholder="Filter modules…" value="${escAttr(MDQ)}">
      <button class="btn small" data-mdall="1">Turn all on</button>
      <button class="btn small" data-mdnone="1">Turn all off</button>
      <button class="btn small" data-mddef="1">Back to defaults</button></div>
    ${body || '<div class="card" style="padding:20px;font-size:12.5px;color:#7b8697">Nothing matches that filter.</div>'}
    <div class="ecsave">
      <span class="ecdirty" id="mdDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="mdSave">Save changes</button>
    </div>
  </div>`;
  bindModules();
}

function bindModules(){
  const search=$('#mdSearch');
  if(search) search.oninput=()=>{ MDQ=search.value; paintModules();
    const again=$('#mdSearch'); if(again){ again.focus(); again.setSelectionRange(again.value.length,again.value.length); } };

  $$('[data-md]').forEach(el=>el.onclick=()=>{
    const m=mdFind(el.dataset.md); if(!m) return;
    m.on=!m.on; paintModules();
    mdAutosave(m.key, m.on);
  });
  $$('[data-mddev]').forEach(el=>el.onchange=()=>{
    const m=mdFind(el.dataset.mddev); if(!m) return;
    m.device=el.value; mdDirty();
  });

  /* Bulk actions.
     Page-level ones ask first: they rewrite all thirty-one rows at once and
     there is no undo short of reloading the screen and losing anything else
     changed. Group-level ones act straight away — a handful of rows, and still
     nothing written until Save changes. */
  const apply=(fn,scope)=>{
    // Only the live ones: turning all on should not pretend to switch on
    // sixteen features that are not built.
    MD.groups.forEach(g=>{ if(!scope||g.key===scope) g.modules.filter(m=>m.status==='live').forEach(fn); });
    mdDirty(); paintModules();
  };

  const askThen=(title,body,label,fn)=>{
    openModal(`<div class="modal-h"><b>${escHtml(title)}</b>
        <button class="x" onclick="closeModal()">✕</button></div>
      <div class="modal-b"><p style="font-size:12.5px;color:var(--ink-soft);margin:0 0 4px">${escHtml(body)}</p>
        <p style="font-size:11.5px;color:var(--ink-soft);margin:0">Nothing is written until you press <b>Save changes</b>.</p>
        <div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">
          <button class="btn ghost" onclick="closeModal()">Cancel</button>
          <button class="btn" id="mdConfirm">${escHtml(label)}</button></div></div>`);
    $('#mdConfirm').onclick=()=>{ closeModal(); fn(); };
  };

  const count=MD.groups.reduce((n,g)=>n+g.modules.filter(m=>m.status==='live').length,0);
  const all=$('[data-mdall]');
  if(all) all.onclick=()=>askThen('Turn every module on',
    `The ${count} modules that are wired up will be switched on. The rest are left alone — they are either switched on another screen or not ported yet.`,
    'Turn all on', ()=>apply(m=>{m.on=true;}));
  const none=$('[data-mdnone]');
  if(none) none.onclick=()=>askThen('Turn every module off',
    `The ${count} modules that are wired up will be switched off. The storefront still renders — that is what a module is — but the free-shipping bar, promo lines and reassurance blocks will all go.`,
    'Turn all off', ()=>apply(m=>{m.on=false;}));
  const def=$('[data-mddef]');
  if(def) def.onclick=()=>askThen('Back to defaults',
    'Every module returns to the state the plugin ships it in, and every device choice returns to Everywhere.',
    'Restore defaults', ()=>apply(m=>{m.on=m.default;m.device='both';}));

  $$('[data-mdgall]').forEach(b=>b.onclick=()=>apply(m=>{m.on=true;}, b.dataset.mdgall));
  $$('[data-mdgnone]').forEach(b=>b.onclick=()=>apply(m=>{m.on=false;}, b.dataset.mdgnone));
  $$('[data-mdgdef]').forEach(b=>b.onclick=()=>apply(m=>{m.on=m.default;m.device='both';}, b.dataset.mdgdef));

  const save=$('#mdSave');
  if(save) save.onclick=async()=>{
    const modules={};
    MD.groups.forEach(g=>g.modules.forEach(m=>{ modules[m.key]={on:m.on,device:m.device}; }));
    save.disabled=true;
    try{
      const r=await fetch(mdBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({modules})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      if(d.counts) MD.counts=d.counts;
      $('#mdDirty').style.visibility='hidden';
      toast('Modules saved');
      paintModules();
    }catch(e){ toast('Could not save: '+e.message,'bad'); }
    finally{ save.disabled=false; }
  };
}

/* ---------- Appearance · Mobile Header ----------
   Spacing and the divider above the search field, phones only. The desktop
   header stays under Appearance → Header. */
let MH=null, MHTAB='spacing';

/* What the homepage sections are inset by, and so what "Match the page"
   means. MobileHeader::PAGE_INSET is the same number on the server. */
const MH_PAGE_INSET=12;

/* The storefront's own three marks come from KBB_HEADER_ICONS, which is
   declared further down this same script block, next to KBB_COUNTRY_NAMES.

   TWO REASONS IT IS NOT ALIASED TO A `const` HERE, both found by rendering the
   screen rather than by reading it.

   Everything from the top of this file to about line 8890 is inside a
   verbatim region, where Blade compiles nothing: an @json() written here is emitted
   as the literal characters `@json(...)`, which is not a wrong value but a
   syntax error, and it takes the whole admin script down rather than this one
   screen. So the data has to be declared outside the verbatim regions, the
   way KBB_COUNTRY_NAMES and KBB_PRESETS are -- since Lane AP, in their own
   small script just before this block, which keeps this block byte-for-byte
   static so it can be served as a cached file (App\Support\AdminConsoleAssets).

   And a `const MHICONS = KBB_HEADER_ICONS` at this point in the file would
   read it before the line that assigns it has run — `var` hoists the name, not
   the value, so both previews would have drawn `undefined`. Read inside the
   two functions instead, which run long after the script has. */

function mhBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/mobile-header'; }

async function renderMobileHdr(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Mobile Header</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(mhBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    MH=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the mobile header settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderMobileHdr()">Retry</button></div></div>`;
    return;
  }
  paintMobileHdr(); if(window.kbbDrafts) kbbDrafts.ready('mobilehdr');
}
function mhGet(k){ for(const t of MH.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function mhSet(k,v){ for(const t of MH.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function mhField(f){
  const v=f.value;
  /* Left and Right are meaningless while Match the page is on, so they dim
     rather than disappearing — the value stays visible. */
  const dim=(f.key==='pad_left'||f.key==='pad_right') && mhGet('match_page');
  const only=(f.key==='dv_length'&&mhGet('divider')!=='ticks')
          || (f.key==='dv_inset'&&['full','soft'].includes(mhGet('divider')))
          || (['dv_colour','dv_alpha','dv_width','dv_inset','dv_length'].includes(f.key)&&mhGet('divider')==='off');
  /* ONE class attribute, not two. This built ` class="dim"` and dropped it
     into a tag that already had `class="mmrow"`; an HTML parser keeps the
     first and discards the second, so .mmrow.dim has never matched anything
     and no row on this screen has ever actually dimmed. Which is half of the
     reported bug: Left and Right were meant to LOOK inert while "Match the
     page" was on, and instead looked exactly like every other live control
     while everything downstream threw their value away. */
  const cls=(dim||only)?' dim':'';
  if(f.type==='bool')
    return `<div class="mmrow${cls}"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-mh="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='select'){ const o=f.options||{};
    return `<div class="mmrow${cls}"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-mh="${f.key}">${Object.keys(o).map(k=>`<option value="${escAttr(k)}"${k===v?' selected':''}>${escHtml(o[k])}</option>`).join('')}</select></div>`; }
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow${cls}"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-mh="${f.key}">
        <i id="mhv-${f.key}">${mhReadout(f,v)}</i></span></div>`; }
  if(f.type==='colour')
    return `<div class="mmrow${cls}"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-mh="${f.key}"><code>${v}</code></span></div>`;
  return `<div class="mmrow${cls}"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
    <input type="text" value="${escAttr(String(v))}" data-mh="${f.key}"></div>`;
}

/* A zero on the text-size controls is not "0px", it is "leave it alone" —
   the service emits no custom property at all for it, so the stylesheet keeps
   the size it already had. Printing 0px would read as invisible text. */
function mhReadout(f,v){
  const o=f.options||{};
  return (o.zero && Number(v)===0) ? escHtml(o.zero) : (v+(o.unit||''));
}

function mhRgba(){
  const hex=String(mhGet('dv_colour')||'#2A2228').replace('#','');
  const n=hex.length===3?hex.split('').map(c=>c+c).join(''):hex;
  const r=parseInt(n.slice(0,2),16),g=parseInt(n.slice(2,4),16),b=parseInt(n.slice(4,6),16);
  return `rgba(${r},${g},${b},${(mhGet('dv_alpha')/100).toFixed(2)})`;
}

/* A phone at the chosen numbers, using the header's own class names so the
   screen and the storefront cannot drift. */
function mhPreview(){
  /* Straight from the values, with no second opinion about "Match the page".
     The screen used to decide it here as well as in the service, which is how
     Left and Right came to move a slider and nothing else. MobileHeader::all()
     reports what is actually in force and this draws that. */
  const l=mhGet('pad_left');
  const r=mhGet('pad_right');
  /* The two scale factors the stylesheet derives, over the same 44. Applied to
     preview-scale numbers so the mock moves the way the phone does. */
  const fit=mhGet('row_h')/44, sfit=mhGet('search_h')/44;
  const S=0.6, px=(n)=>(Math.round(n*S*10)/10)+'px';
  const logo=mhGet('size_logo')||22;
  const stext=(mhGet('size_search')||16)*Math.max(1,sfit);
  /* Zero is "Unchanged" for every size control, and MobileHeader::cssVariables()
     emits NOTHING for one left there -- the storefront keeps its own fallback.
     So the preview emits nothing either, and the CSS above carries the same
     fallback numbers the stylesheet does. A control at Unchanged that still
     wrote a value here would move the mock and not the phone. */
  const keep=(v,val)=>v?`--mhp-${val}:${px(v)};`:'';
  const marks=`--mhp-ib:${px(mhGet('row_h'))};--mhp-icon:${px(21*fit)};
    --mhp-logo:${px(logo*(mhGet('fit_text')?fit:1))};
    --mhp-acc:${mhGet('size_accent')?px(mhGet('size_accent')*(mhGet('fit_text')?fit:1)):'inherit'};
    --mhp-sh:${px(mhGet('search_h'))};--mhp-st:${px(stext)};
    ${keep(mhGet('size_ph'),'ph')}
    ${keep(mhGet('size_badge'),'badge')}${keep(mhGet('size_trend'),'trend')}
    --mhp-mag:${px(17*sfit)};--mhp-mark:${escAttr(mhGet('acct_out'))};
    --mhp-acctc:${escAttr(MHTAB==='icons'?mhGet('acct_in'):mhGet('acct_out'))};`;
  /* Drawn only when the storefront draws one. HeaderSettings' `trending_show`
     is off by default and lives on another screen, so the payload carries it;
     see MobileHeaderApiController::show(). */
  const trend=MH.context&&MH.context.trending
    ? `<div class="mhp-trend"><b>Trending</b><a>serum</a><a>sunscreen</a><a>cleanser</a></div>`
    : '';
  const vars=`--mh-l:${l}px;--mh-r:${r}px;--mh-t:${mhGet('pad_top')}px;--mh-b:${mhGet('pad_bottom')}px;
    --mh-gap:${mhGet('row_gap')}px;--mh-sgap:${mhGet('search_gap')}px;--mh-igap:${mhGet('item_gap')}px;
    --mh-dv:${mhRgba()};--mh-dvw:${mhGet('dv_width')}px;--mh-dvin:${mhGet('dv_inset')}px;--mh-dvlen:${mhGet('dv_length')}px;
    --mh-srad:${mhGet('search_full')?0:mhGet('search_radius')+'px'};--mh-spad:${mhGet('search_pad')}px;--mh-sbg:${escAttr(mhGet('search_bg'))};
    --mh-sicon:${escAttr(mhGet('search_icon'))};--mh-stext:${escAttr(mhGet('search_text'))};--mh-sph:${escAttr(mhGet('search_ph'))};${marks}`;
  const dv=mhGet('divider');
  const sf=(mhGet('search_full')?' mhp-sfull':'')+(mhGet('search_border')?'':' mhp-snb')
    +((mhGet('search_full')&&mhGet('search_align')==='field')?' mhp-skeep':'');
  return `<div class="mhp">
    <div class="mhp-hdr ${dv==='off'?'':'mhp-'+dv}${sf}" style="${vars}">
      <div class="mhp-wrap">
        <div class="mhp-in">
          <span class="mhp-bg"></span>
          <span class="mhp-logo">K-Beauty<b>Bliss</b></span>
          <span class="mhp-act"><i class="mhp-acct">${KBB_HEADER_ICONS.account}</i><i>${KBB_HEADER_ICONS.wishlist}<b>2</b></i><i>${KBB_HEADER_ICONS.cart}<b>3</b></i></span>
          <span class="mhp-sbox"><i class="mhp-mg"></i><em>Search 671 products…</em></span>${trend}
        </div>
      </div>
    </div>
    <div class="mhp-page"><div class="mhp-sec"><b>Best sellers</b><div class="mhp-grid"><u></u><u></u></div></div></div>
  </div>`;
}

function paintMobileHdr(){
  const tab=MH.tabs.find(t=>t.key===MHTAB)||MH.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Mobile Header</h2>
      <p class="mdesc" style="margin:0">Spacing and the divider above the search field, on phones only. The desktop header is set under <b>Appearance → Header</b>.</p></div>
    <div class="ectabs">${MH.tabs.map(t=>`<button class="ectab${t.key===MHTAB?' on':''}" data-mhtab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(mhField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in">${mhPreview()}</div><p class="mmpv-note">Live preview · phone width · account mark shown <b>${MHTAB==='icons'?'signed in':'signed out'}</b></p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="mhDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="mhSave">Save changes</button>
    </div>
  </div>`;
  bindMobileHdr();
}

function bindMobileHdr(){
  const dirty=()=>{ const d=$('#mhDirty'); if(d) d.style.visibility='visible'; };
  $$('[data-mhtab]').forEach(b=>b.onclick=()=>{ MHTAB=b.dataset.mhtab; paintMobileHdr(); });

  $$('[data-mh]').forEach(el=>{
    const k=el.dataset.mh;
    if(el.classList.contains('ectog')){
      el.onclick=()=>{ const v=!el.classList.contains('on'); el.classList.toggle('on',v);
        el.setAttribute('aria-checked',String(v)); mhSet(k,v);
        /* The other half of the same coupling, and the same reason: switching
           "Match the page" on is a decision about the two numbers, so they
           take the page's inset there and then instead of sitting underneath
           it disagreeing. MobileHeader::save() writes exactly this. */
        if(k==='match_page' && v){ mhSet('pad_left',MH_PAGE_INSET); mhSet('pad_right',MH_PAGE_INSET); }
        dirty(); paintMobileHdr(); };
      return;
    }
    if(el.tagName==='SELECT'){ el.onchange=()=>{ mhSet(k,el.value); dirty(); paintMobileHdr(); }; return; }
    if(el.type==='range'){
      el.oninput=()=>{ mhSet(k,Number(el.value)); dirty();
        /* Left and Right are the two the owner reported as dead. Dimming them
           while "Match the page" is on left them draggable, and everything
           downstream then discarded the number. Moving one now turns the
           toggle off, so the drag does what a drag looks like it does. The
           screen is patched in place rather than repainted because a repaint
           mid-drag destroys the slider under the finger. */
        if((k==='pad_left'||k==='pad_right') && mhGet('match_page')){
          mhSet('match_page',false);
          const t=$('[data-mh="match_page"]');
          if(t){ t.classList.remove('on'); t.setAttribute('aria-checked','false'); }
          $$('[data-mh="pad_left"],[data-mh="pad_right"]').forEach(x=>{
            const row=x.closest('.mmrow'); if(row) row.classList.remove('dim'); });
        }
        const b=$('#mhv-'+k); if(b){ const f=MH.tabs.flatMap(t=>t.fields).find(x=>x.key===k);
          b.textContent=mhReadout(f,el.value); }
        $('.mmpv-in').innerHTML=mhPreview(); };
      return;
    }
    el.oninput=()=>{ mhSet(k,el.value); dirty(); $('.mmpv-in').innerHTML=mhPreview();
      const c=el.parentElement.querySelector('code'); if(c) c.textContent=el.value; };
  });

  const save=$('#mhSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of MH.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(mhBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      if(window.kbbDrafts) kbbDrafts.saved('mobilehdr'); $('#mhDirty').style.visibility='hidden';
      toast('Mobile header saved');
    }catch(e){ toast('Could not save: '+e.message,'bad'); }
    finally{ save.disabled=false; }
  };
}

/* ---------- Appearance · Section dividers ----------
   Same generic renderer as the other settings screens, with one extra field
   type: a tick list of the homepage sections, used when Where is set to the
   chosen ones. */
let DV=null, DVTAB='style';
const DV_NAMES={ticks:'Corner ticks',hairline:'Fading hairline',petal:'Petal on a hairline',
  stitch:'Stitched dashes',drift:'Drifting petals',breathe:'Breathing hairline',gradient:'Drifting gradient edge'};

function dvBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/dividers'; }

async function renderDividers(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Section dividers</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(dvBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    DV=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the divider settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderDividers()">Retry</button></div></div>`;
    return;
  }
  paintDividers(); if(window.kbbDrafts) kbbDrafts.ready('dividers');
}
function dvGet(k){ for(const t of DV.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function dvSet(k,v){ for(const t of DV.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }
function dvPicked(){ return String(dvGet('sections')||'').split(',').filter(Boolean); }

function dvField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-dv="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='select'){
    const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-dv="${f.key}">${Object.keys(o).map(k=>`<option value="${escAttr(k)}"${k===v?' selected':''}>${escHtml(o[k])}</option>`).join('')}</select></div>`;
  }
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-dv="${f.key}">
        <i id="dvv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-dv="${f.key}"><code>${v}</code></span></div>`;
  if(f.type==='sections'){
    const on=dvPicked(), chosen=dvGet('scope')==='chosen';
    return `<div class="dvsec${chosen?'':' off'}">
      <div class="dvsec-hd"><b>${escHtml(f.label)}</b><span>${escHtml(f.help||'')}</span>
        <span class="dvsec-act"><button class="btn small" data-dvall="1">All</button><button class="btn small" data-dvnone="1">None</button></span></div>
      <div class="dvsec-grid">${DV.sections.map(sc=>`
        <label class="dvchk${on.includes(sc.key)?' on':''}"><input type="checkbox" data-dvsec="${escAttr(sc.key)}"${on.includes(sc.key)?' checked':''}>
          <span>${escHtml(sc.label)}</span></label>`).join('')}</div>
      ${chosen?'':'<p class="dvsec-note">Set <b>Where</b> to “Only above the sections ticked below” to use this list.</p>'}
    </div>`;
  }
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
    <input type="text" value="${escAttr(String(v))}" data-dv="${f.key}"></div>`;
}

/* Three stacked sections at the chosen style, drawn with the same measurements
   as the storefront so the screen cannot drift from the page. */
function dvPreview(){
  const style=dvGet('style'), random=style.indexOf('random')===0;
  const shown=random?DV.resolved:style;
  const vars=`--dv-col:${escAttr(dvGet('colour'))};--dv-len:${dvGet('length')}px;--dv-w:${dvGet('thickness')}px;--dv-in:${dvGet('inset')}px`;
  const band=(t)=>`<div class="dvp-sec"><div class="dvp-h">${escHtml(t)}</div>
      <div class="dvp-g"><i></i><i></i></div></div>`;

  return `<div class="dvp dvp-${escAttr(shown)}" style="${vars}">
      ${band('Big savings bundles')}${band('Best sellers')}${band('Flash sale')}
    </div>
    ${random?`<p class="dvp-note">Random is on. This visit landed on <b>${escHtml(DV_NAMES[shown]||shown)}</b>.</p>`:''}`;
}

function paintDividers(){
  const tab=DV.tabs.find(t=>t.key===DVTAB)||DV.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Section dividers</h2>
      <p class="mdesc" style="margin:0">The mark between homepage sections. Phone only unless you turn desktop on — sections still have their card frame up there.</p></div>
    <div class="ectabs">${DV.tabs.map(t=>`<button class="ectab${t.key===DVTAB?' on':''}" data-dvtab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(dvField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in">${dvPreview()}</div><p class="mmpv-note">Live preview · phone width</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="dvDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="dvSave">Save changes</button>
    </div>
  </div>`;
  bindDividers();
}

/* ================= Mega Menu ================= */

let MGM = null;
let MGM_MENUS = [];
let MGM_CURRENT_MENU_ID = null;

function mgmBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/mega-menu'; }

async function mgmApi(path, opts){
  opts = opts || {};
  opts.headers = Object.assign({'Accept':'application/json'}, opts.headers||{});
  if(opts.method && opts.method !== 'GET'){
    opts.headers['X-XSRF-TOKEN'] = uToken();
    opts.headers['Content-Type'] = 'application/json';
  }
  opts.credentials = 'same-origin';
  const r = await fetch(mgmBase() + path, opts);
  const data = await r.json().catch(() => ({}));
  return {ok: r.ok, status: r.status, data};
}

async function renderMegaMenu(menuId){
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Mega Menu</h2><p>Loading…</p></div></div>`;
  let detail = null;
  try{
    const menusResp = await mgmApi('/menus');
    if(!menusResp.ok) throw new Error(String(menusResp.status));
    MGM_MENUS = menusResp.data.menus || [];

    const wantId = menuId || MGM_CURRENT_MENU_ID || (MGM_MENUS[0] && MGM_MENUS[0].id);
    const qs = wantId ? ('?menu_id=' + wantId) : '';
    const r = await fetch(mgmBase() + qs, {credentials:'same-origin', headers:{Accept:'application/json'}});
    if(!r.ok){
      // The endpoint now returns the real exception message and file:line
      // on a 500, instead of a bare status code with nothing else to go on.
      const body = await r.json().catch(() => null);
      if(body && body.errors) detail = body;
      throw new Error(String(r.status));
    }
    MGM = await r.json();
    MGM_CURRENT_MENU_ID = MGM.menu_id;

    // The GET above may have just auto-created the very first menu — the
    // list fetched a moment earlier wouldn't know about it yet.
    if(! MGM_MENUS.some(m => m.id === MGM_CURRENT_MENU_ID)){
      const refreshed = await mgmApi('/menus');
      if(refreshed.ok) MGM_MENUS = refreshed.data.menus || [];
    }
  }catch(e){
    const why = String(e.message||e);
    const hint = detail
      ? (detail.errors[0] || 'The server errored.') + (detail.where ? ` (${detail.where})` : '')
      : why==='404'
        ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
        : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the menu.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)}</p>
      <button class="btn small" onclick="renderMegaMenu()">Retry</button></div></div>`;
    return;
  }
  paintMegaMenu();
}

const MGM_VIS_LABEL = {always: 'Everyone', guest: 'Signed out only', auth: 'Signed in only'};

/* A slim, real rendering of the top bar, using the site's own classes and
   CSS (kbb.css is already loaded in the admin shell for a couple of other
   previews) so this isn't a guess at what it'll look like — it's what it
   already looks like, just scoped to a small preview strip. */
function mgmPreview(){
  const top = (MGM.tree || []).slice(0, 6);
  return `<div class="mgmpv">
    <div class="mgmpv-label">Live preview</div>
    <div class="mgmpv-bar">
      ${top.map(i => `<span class="mgmpv-link" style="${i.highlight_color ? `background:${escAttr(i.highlight_color)};border-radius:7px;padding:3px 9px` : ''}">${escHtml(i.label)}${i.badge ? `<em>${escHtml(i.badge)}</em>` : ''}${(i.children||[]).length ? ' ▾' : ''}</span>`).join('')}
      ${!top.length ? '<span class="mgmpv-empty">Nothing added yet — showing the built-in fallback on the real site</span>' : ''}
    </div>
  </div>`;
}

function mgmSlotTags(m){
  const tags = [];
  if (m.show_desktop) tags.push('Desktop');
  if (m.show_mobile) tags.push('Mobile');
  if (m.show_footer) tags.push('Footer');
  return tags.map(t => `<span class="mgm-loctag">${t}</span>`).join('');
}

function mgmMenuBar(){
  return `<div class="mgm-menubar">
    ${MGM_MENUS.map(m => `<button class="mgm-menutab${m.id === MGM_CURRENT_MENU_ID ? ' on' : ''}" data-mgmswitch="${m.id}">
      ${escHtml(m.name)}
      ${mgmSlotTags(m)}
    </button>`).join('')}
    <button class="mgm-menutab mgm-menutab-new" id="mgmNewMenu">+ New menu</button>
    <button class="mgm-menutab mgm-menutab-new" id="mgmLoadDemo">✨ Load kbeautybliss.com menu</button>
    ${MGM_CURRENT_MENU_ID ? `<button class="mgm-menusettings" id="mgmMenuSettings" title="Rename or assign this menu">⚙ Menu settings</button>` : ''}
  </div>`;
}

function paintMegaMenu(){
  const tree = MGM.tree || [];
  const current = MGM_MENUS.find(m => m.id === MGM_CURRENT_MENU_ID);

  $('#content').innerHTML = `<div class="wrap mgm-wrap">
    <div class="page-head">
      <h2>Mega Menu</h2>
      <p>What shows in the header nav bar, and what drops down or opens as a mega panel underneath each item. Each column is a top-level item, with its sub-menus and links inside. Type a number or press the arrows to move anything, or drag any row and it slides into place. Click a name to edit it; ⋯ has Delete and Move to column. Changes take effect immediately.</p>
    </div>

    ${mgmMenuBar()}

    ${current && !current.show_desktop && !current.show_mobile && !current.show_footer ? `<div class="mgm-offbanner">
      <b>"${escHtml(current.name)}" isn't assigned anywhere yet.</b> It exists, but nothing on the site is showing it. Use <b>⚙ Menu settings</b> above to assign it to the desktop header, mobile menu, or footer.
    </div>` : ''}

    ${mgmPreview()}

    <div class="card mgm-card">
      <div class="mgmtree" id="mgmTree"></div>
      <button class="btn primary" id="mgmAddTop" style="margin-top:16px">+ Add top-level item</button>
    </div>
  </div>`;

  bindMegaMenu();
}

function bindMegaMenu(){
  $('#mgmAddTop').onclick = () => mgmOpenForm(null, 0);

  $$('[data-mgmswitch]').forEach(b => b.onclick = () => {
    const id = Number(b.dataset.mgmswitch);
    if(id === MGM_CURRENT_MENU_ID) return;
    renderMegaMenu(id);
  });

  $('#mgmNewMenu').onclick = () => mgmNewMenuPrompt();
  $('#mgmLoadDemo').onclick = () => mgmLoadDemoConfirm();

  const settingsBtn = $('#mgmMenuSettings');
  if(settingsBtn) settingsBtn.onclick = () => mgmMenuSettingsForm();

  // Lane MO: the column board — sort numbers, arrows, live drag, inline add,
  // the floating +. One move request per action; the tree changes locally.
  if(window.KBBMenuOrder) KBBMenuOrder.mount($('#mgmTree'), {
    tree: () => MGM.tree || [],
    setTree: t => { MGM.tree = t; },
    changed: () => { const pv = $('.mgmpv'); if(pv) pv.outerHTML = mgmPreview(); },
    menuId: () => MGM_CURRENT_MENU_ID,
    api: mgmApi,
    toast: (m, kind) => toast(m, kind),
    reload: () => renderMegaMenu(MGM_CURRENT_MENU_ID),
    edit: id => { const it = mgmFind(id); if(it) mgmOpenForm(it.parent_id ?? null, it._depth, it); },
    confirmDelete: label => mgmDeleteConfirm(label),
  });
}

/* Flat lookup with parent_id and depth annotated, since the tree from the
   server is nested but edit/reorder need to know both about a single node. */
function mgmFind(id, nodes, depth, parentId){
  nodes = nodes || MGM.tree; depth = depth || 0; parentId = parentId ?? null;
  for(const n of nodes){
    if(n.id === id) return Object.assign({}, n, {_depth: depth, parent_id: parentId});
    if(n.children && n.children.length){
      const hit = mgmFind(id, n.children, depth + 1, n.id);
      if(hit) return hit;
    }
  }
  return null;
}

/* ---------- Add / edit form ---------- */

/* T4b — the Arabic label box for a menu item.  (Lane EX)

   WHICH FIELDS GET A BOX IS THE SERVER'S ANSWER, NOT THIS SCREEN'S.
   MenuItem::$translatable is `label` and nothing else: `url` is deliberately
   left off it, because one menu item points at one page and the /ar prefix is
   what makes that page Arabic — a translated URL would be a second address to
   keep in step by hand. So this asks the payload rather than listing fields,
   and a column added to that allowlist grows a box here with no edit.

   MGM.translatable is the EMPTY shape, which is what lets the "Add item"
   dialog draw a box for an item that does not exist yet. Without it a menu
   could only be translated on a second visit, which is the "somewhere else,
   afterwards" the plan rules out. */
function mgmArabicLabel(editing){
  if(!window.KBBArabic) return '';

  const shape = (editing && editing.translations) || (typeof MGM !== 'undefined' && MGM && MGM.translatable);

  if(!shape || !shape[KBBArabic.locale]
     || !Object.prototype.hasOwnProperty.call(shape[KBBArabic.locale], 'label')) return '';

  return `<div style="padding:2px 0 11px;border-bottom:1px solid #f2f5f8">${KBBArabic.box({
    field: 'label',
    label: 'Label',
    prefill: (editing && editing.translations) || null,
    maxlength: 60,
    from: '#mgmfLabel'
  })}</div>`;
}

function mgmOpenForm(parentId, depth, editing){
  const kind = depth === 0 ? 'top-level item' : depth === 1 ? 'column' : 'link';
  const isEdit = !!editing;
  const color = editing?.highlight_color || '';
  const vis = editing?.visibility || 'always';

  openModal(`<div class="modal-b mgm-modal">
    <h3>${isEdit ? 'Edit' : 'Add'} ${kind}</h3>
    <div class="mmrow"><div class="mmlbl"><b>Label</b></div>
      <input type="text" id="mgmfLabel" maxlength="60" value="${escAttr(editing?.label || '')}" placeholder="e.g. Skincare"></div>
    ${mgmArabicLabel(editing)}
    <div class="mmrow"><div class="mmlbl"><b>Link</b><span>${depth < 2 ? 'Leave blank for a heading that only opens a panel.' : ''}</span></div>
      <input type="text" id="mgmfUrl" maxlength="255" value="${escAttr(editing?.url || '')}" placeholder="/collections/cleansers/"></div>
    ${depth === 0 ? `<div class="mmrow"><div class="mmlbl"><b>Badge</b><span>Optional — small pill next to the label, e.g. NEW</span></div>
      <input type="text" id="mgmfBadge" maxlength="20" value="${escAttr(editing?.badge || '')}" placeholder="NEW"></div>` : ''}
    ${depth === 2 ? `<div class="mmrow"><div class="mmlbl"><b>Icon</b><span>Optional — shown before the link</span></div>
      <span class="mgm-iconpick">
        <button type="button" class="mgm-iconbtn" id="mgmfIconBtn">${editing?.icon ? escHtml(editing.icon) : '<span class="mgm-iconph">＋</span>'}</button>
        <input type="hidden" id="mgmfIcon" value="${escAttr(editing?.icon || '')}">
      </span></div>` : ''}
    <div class="mmrow"><div class="mmlbl"><b>Highlight colour</b><span>Optional — a background tint to call this item out, e.g. for Sale</span></div>
      <span class="mgm-colorpick">
        <input type="color" id="mgmfColor" value="${color || '#E0567B'}">
        <button type="button" class="btn small" id="mgmfColorClear" ${color ? '' : 'style="display:none"'}>Clear</button>
        <input type="hidden" id="mgmfColorVal" value="${escAttr(color)}">
      </span></div>
    ${depth === 0 ? `<div class="mmrow"><div class="mmlbl"><b>Desktop columns</b><span>Only matters once this item has sub-items — auto splits into a new column roughly every 10, or set an exact count</span></div>
      <select id="mgmfColumns">
        <option value=""${!editing?.columns ? ' selected' : ''}>Auto</option>
        ${[1,2,3,4,5,6].map(n => `<option value="${n}"${editing?.columns===n ? ' selected' : ''}>${n} column${n===1?'':'s'}</option>`).join('')}
      </select></div>` : ''}
    <div class="mmrow"><div class="mmlbl"><b>Who sees it</b><span>Show or hide this item based on whether a shopper is signed in</span></div>
      <select id="mgmfVisibility">
        <option value="always"${vis==='always'?' selected':''}>Everyone</option>
        <option value="guest"${vis==='guest'?' selected':''}>Signed out only — e.g. "Sign In"</option>
        <option value="auth"${vis==='auth'?' selected':''}>Signed in only — e.g. "My Account"</option>
      </select></div>
    <div class="mmrow"><div class="mmlbl"><b>Open in a new tab</b><span>For links that leave the site</span></div>
      <span class="ectog${editing?.new_tab ? ' on' : ''}" id="mgmfNewTab" role="switch" aria-checked="${editing?.new_tab ? 'true' : 'false'}" tabindex="0"></span></div>
    <div class="kdlg-a"><button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn primary" id="mgmfSave">${isEdit ? 'Save' : 'Add'}</button></div>
  </div>`);

  /* Attaches the Translate button and reveals it ONLY if an API key is
     configured. With no key it is never shown, and typing the Arabic by hand
     works with no account and no bill — which is how the menu is actually
     going to be translated. */
  if(window.KBBArabic) KBBArabic.wire(document);

  const colorInput = $('#mgmfColor');
  const colorVal = $('#mgmfColorVal');
  const colorClear = $('#mgmfColorClear');
  if(color) colorVal.value = color;
  colorInput.oninput = () => { colorVal.value = colorInput.value; colorClear.style.display = ''; };
  colorClear.onclick = () => { colorVal.value = ''; colorClear.style.display = 'none'; };

  const iconBtn = $('#mgmfIconBtn');
  if(iconBtn) iconBtn.onclick = (e) => { e.preventDefault(); mgmIconPicker(iconBtn); };

  const newTabToggle = $('#mgmfNewTab');
  newTabToggle.onclick = () => {
    const on = !newTabToggle.classList.contains('on');
    newTabToggle.classList.toggle('on', on);
    newTabToggle.setAttribute('aria-checked', String(on));
  };

  $('#mgmfSave').onclick = async () => {
    const label = $('#mgmfLabel').value.trim();
    if(!label){ toast('Give it a label first.'); return; }

    const payload = {
      label,
      /* T4b — the Arabic travels with the ordinary save, in the same request
         as the English label and stored by the same button. A blank box is
         SENT rather than omitted: blank means "not translated yet" and has to
         reach the server to delete the row, which is the only reason the
         Translation screen's progress figure can be counted. */
      translations: window.KBBArabic ? KBBArabic.collect(document) : {},
      url: $('#mgmfUrl').value.trim() || null,
      badge: depth === 0 ? ($('#mgmfBadge').value.trim() || null) : null,
      icon: depth === 2 ? ($('#mgmfIcon').value.trim() || null) : null,
      highlight_color: colorVal.value || null,
      visibility: $('#mgmfVisibility').value,
      new_tab: newTabToggle.classList.contains('on'),
    };
    if(depth === 0){
      const colVal = $('#mgmfColumns').value;
      payload.columns = colVal ? Number(colVal) : null;
    }
    if(!isEdit){ payload.parent_id = parentId; payload.menu_id = MGM_CURRENT_MENU_ID; }

    const r = isEdit
      ? await mgmApi('/' + editing.id, {method:'POST', body: JSON.stringify(payload)})
      : await mgmApi('', {method:'POST', body: JSON.stringify(payload)});

    if(!r.ok){
      toast((r.data.errors && r.data.errors[0]) || 'Could not save that.', 'bad');
      return;
    }
    toast(isEdit ? 'Saved.' : 'Added.');
    closeModal();
    renderMegaMenu();
  };
}

function mgmLoadDemoConfirm(){
  const w = document.createElement('div');
  w.className = 'kdlg';
  w.innerHTML = `<div class="kdlg-s"></div><div class="kdlg-b" role="dialog" aria-modal="true">
    <h3>Load the kbeautybliss.com menu?</h3>
    <p>Creates a new menu with the real, current kbeautybliss.com navigation — same items, same order, brand links and all — and puts it live on both the desktop header and mobile menu right away. Whatever is currently showing there gets replaced; it isn't deleted, just no longer assigned anywhere.</p>
    <div class="kdlg-a"><button class="btn ghost" data-no>Cancel</button>
      <button class="btn primary" data-yes>Load it</button></div>
  </div>`;
  document.body.appendChild(w);
  requestAnimationFrame(() => w.classList.add('on'));
  const done = () => { w.classList.remove('on'); setTimeout(() => w.remove(), 200); };
  w.querySelector('[data-no]').onclick = done;
  w.querySelector('.kdlg-s').onclick = done;
  w.querySelector('[data-yes]').onclick = async () => {
    done();
    const r = await mgmApi('/demo', {method: 'POST'});
    if(!r.ok){ toast((r.data.errors && r.data.errors[0]) || 'Could not load the demo menu.', 'bad'); return; }
    toast('Loaded — live on desktop and mobile now.');
    renderMegaMenu(r.data.id);
  };
}

function mgmNewMenuPrompt(){
  openModal(`<div class="modal-b mgm-modal">
    <h3>New menu</h3>
    <div class="mmrow"><div class="mmlbl"><b>Name</b><span>Just for you to tell menus apart — not shown to shoppers</span></div>
      <input type="text" id="mgmNewName" maxlength="60" placeholder="e.g. Footer Links"></div>
    <div class="kdlg-a"><button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn primary" id="mgmNewSave">Create</button></div>
  </div>`);

  $('#mgmNewSave').onclick = async () => {
    const name = $('#mgmNewName').value.trim();
    if(!name){ toast('Give it a name first.'); return; }
    const r = await mgmApi('/menus', {method:'POST', body: JSON.stringify({name})});
    if(!r.ok){ toast((r.data.errors && r.data.errors[0]) || 'Could not create that.', 'bad'); return; }
    toast('Menu created.');
    closeModal();
    renderMegaMenu(r.data.id);
  };
}

function mgmMenuSettingsForm(){
  const current = MGM_MENUS.find(m => m.id === MGM_CURRENT_MENU_ID);
  if(!current) return;

  const slot = (field, label) => `<label class="mgm-slotbox">
    <input type="checkbox" id="mgmMs_${field}" ${current[field] ? 'checked' : ''}>
    <span>${label}</span>
  </label>`;

  openModal(`<div class="modal-b mgm-modal">
    <h3>Menu settings</h3>
    <div class="mmrow"><div class="mmlbl"><b>Name</b></div>
      <input type="text" id="mgmMsName" maxlength="60" value="${escAttr(current.name)}"></div>
    <div class="mmrow" style="align-items:flex-start">
      <div class="mmlbl"><b>Show this menu at</b><span>Leave everything unchecked to keep it built but not shown anywhere. Checking a box here takes that spot from whichever menu currently has it.</span></div>
      <div class="mgm-slotboxes">
        ${slot('show_desktop', 'Desktop header')}
        ${slot('show_mobile', 'Mobile menu')}
        ${slot('show_footer', 'Footer')}
      </div>
    </div>
    <div class="kdlg-a">
      ${MGM_MENUS.length > 1 ? `<button class="btn danger" id="mgmMsDelete" style="margin-right:6px">Delete menu</button>` : ''}
      <button class="btn ghost" id="mgmMsDuplicate" style="margin-right:auto">Duplicate</button>
      <button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn primary" id="mgmMsSave">Save</button>
    </div>
  </div>`);

  $('#mgmMsSave').onclick = async () => {
    const name = $('#mgmMsName').value.trim();
    if(!name){ toast('Give it a name first.'); return; }
    const payload = {
      name,
      show_desktop: $('#mgmMs_show_desktop').checked,
      show_mobile: $('#mgmMs_show_mobile').checked,
      show_footer: $('#mgmMs_show_footer').checked,
    };
    const r = await mgmApi('/menus/' + current.id, {method:'POST', body: JSON.stringify(payload)});
    if(!r.ok){ toast((r.data.errors && r.data.errors[0]) || 'Could not save that.', 'bad'); return; }
    toast('Menu settings saved.');
    closeModal();
    renderMegaMenu(current.id);
  };

  $('#mgmMsDuplicate').onclick = async () => {
    const r = await mgmApi('/menus/' + current.id + '/duplicate', {method:'POST'});
    if(!r.ok){ toast((r.data.errors && r.data.errors[0]) || 'Could not duplicate that.', 'bad'); return; }
    toast('Duplicated — not shown anywhere yet, assign it in Menu settings when ready.');
    closeModal();
    renderMegaMenu(r.data.id);
  };

  const delBtn = $('#mgmMsDelete');
  if(delBtn) delBtn.onclick = async () => {
    const ok = await mgmDeleteMenuConfirm(current.name);
    if(!ok) return;
    const r = await mgmApi('/menus/' + current.id + '/delete', {method:'POST'});
    if(!r.ok){ toast('Could not delete that.', 'bad'); return; }
    toast('Menu deleted.');
    closeModal();
    MGM_CURRENT_MENU_ID = null;
    renderMegaMenu();
  };
}

function mgmDeleteMenuConfirm(name){
  return new Promise(resolve => {
    const w = document.createElement('div');
    w.className = 'kdlg';
    w.innerHTML = `<div class="kdlg-s"></div><div class="kdlg-b" role="dialog" aria-modal="true">
      <h3>Delete the whole "${escHtml(name)}" menu?</h3>
      <p>Every item in it goes too. If it's currently assigned to the desktop header, mobile menu, or footer, that spot falls back to nothing.</p>
      <div class="kdlg-a"><button class="btn" data-no>Cancel</button>
        <button class="btn primary" data-yes>Delete menu</button></div>
    </div>`;
    document.body.appendChild(w);
    requestAnimationFrame(() => w.classList.add('on'));
    const done = v => { w.classList.remove('on'); setTimeout(() => w.remove(), 200); resolve(v); };
    w.querySelector('[data-no]').onclick = () => done(false);
    w.querySelector('.kdlg-s').onclick = () => done(false);
    w.querySelector('[data-yes]').onclick = () => done(true);
    document.addEventListener('keydown', function esc(e){
      if(e.key === 'Escape'){ document.removeEventListener('keydown', esc); done(false); }
    });
  });
}

/* Curated for a K-beauty storefront rather than a generic picker with
   thousands of irrelevant options — skincare/beauty first, then shopping
   and general-nav symbols an admin is actually likely to reach for here. */
const MGM_ICON_SET = [
  '🧴','💧','🧼','🌿','✨','🫧','💆','🧖','🩹','🌸','🧊','☀️','💄','🪞',
  '🎁','🔥','🏷️','💯','🆕','📦','🛍️','⭐','💖','🎉',
  '🏠','👤','❤️','🔍','🚚','💳',
];

function mgmIconPicker(anchorBtn){
  document.querySelectorAll('.mgm-iconpop').forEach(p => p.remove());

  const pop = document.createElement('div');
  pop.className = 'mgm-iconpop';
  pop.innerHTML = `
    <div class="mgm-icongrid">
      ${MGM_ICON_SET.map(e => `<button type="button" class="mgm-iconopt" data-icon="${escAttr(e)}">${e}</button>`).join('')}
    </div>
    <div class="mgm-iconcustom">
      <input type="text" id="mgmIconCustom" maxlength="10" placeholder="or type any emoji">
      <button type="button" class="btn small" id="mgmIconCustomUse">Use</button>
    </div>
    <button type="button" class="mgm-iconclear" id="mgmIconClearBtn">No icon</button>
  `;
  // Appended to <body>, not the button's own parent — that parent sits
  // inside .modal-b, and .modal has overflow:auto for long forms. A
  // position:absolute popover nested in there gets silently clipped at the
  // modal's edge instead of floating above it. Confirmed directly by
  // rendering it before shipping: the grid was cut off mid-row. Fixed
  // popovers positioned from the anchor's real screen coordinates avoid
  // that scrolling context entirely.
  document.body.appendChild(pop);
  const r = anchorBtn.getBoundingClientRect();
  pop.style.top = (r.bottom + 8) + 'px';
  pop.style.left = Math.min(r.left, window.innerWidth - 236 - 16) + 'px';

  const pick = (val) => {
    $('#mgmfIcon').value = val;
    anchorBtn.innerHTML = val ? escHtml(val) : '<span class="mgm-iconph">＋</span>';
    pop.remove();
  };

  pop.querySelectorAll('[data-icon]').forEach(b => b.onclick = () => pick(b.dataset.icon));
  $('#mgmIconClearBtn', pop).onclick = () => pick('');
  $('#mgmIconCustomUse', pop).onclick = () => pick($('#mgmIconCustom', pop).value.trim());

  const closeOnOutside = (e) => {
    if(!pop.contains(e.target) && e.target !== anchorBtn){
      pop.remove();
      document.removeEventListener('click', closeOnOutside, true);
    }
  };
  setTimeout(() => document.addEventListener('click', closeOnOutside, true), 0);
}

function mgmDeleteConfirm(label){
  return new Promise(resolve => {
    const w = document.createElement('div');
    w.className = 'kdlg';
    w.innerHTML = `<div class="kdlg-s"></div><div class="kdlg-b" role="dialog" aria-modal="true">
      <h3>Delete "${escHtml(label)}"?</h3>
      <p>Anything nested under it — columns, links — is deleted too. This can't be undone; you'd have to rebuild it.</p>
      <div class="kdlg-a"><button class="btn" data-no>Cancel</button>
        <button class="btn primary" data-yes>Delete</button></div>
    </div>`;
    document.body.appendChild(w);
    requestAnimationFrame(() => w.classList.add('on'));
    const done = v => { w.classList.remove('on'); setTimeout(() => w.remove(), 200); resolve(v); };
    w.querySelector('[data-no]').onclick = () => done(false);
    w.querySelector('.kdlg-s').onclick = () => done(false);
    w.querySelector('[data-yes]').onclick = () => done(true);
    document.addEventListener('keydown', function esc(e){
      if(e.key === 'Escape'){ document.removeEventListener('keydown', esc); done(false); }
    });
  });
}


function bindDividers(){
  const dirty=()=>{ const d=$('#dvDirty'); if(d) d.style.visibility='visible'; };

  $$('[data-dvtab]').forEach(b=>b.onclick=()=>{ DVTAB=b.dataset.dvtab; paintDividers(); });

  $$('[data-dv]').forEach(el=>{
    const k=el.dataset.dv;
    if(el.classList.contains('ectog')){
      el.onclick=()=>{ const v=!el.classList.contains('on'); el.classList.toggle('on',v);
        el.setAttribute('aria-checked',String(v)); dvSet(k,v); dirty(); paintDividers(); };
      return;
    }
    if(el.tagName==='SELECT'){ el.onchange=()=>{ dvSet(k,el.value); dirty(); paintDividers(); }; return; }
    if(el.type==='range'){
      el.oninput=()=>{ dvSet(k,Number(el.value)); dirty();
        const b=$('#dvv-'+k); if(b){ const f=DV.tabs.flatMap(t=>t.fields).find(x=>x.key===k);
          b.textContent=el.value+((f.options||{}).unit||''); }
        $('.mmpv-in').innerHTML=dvPreview(); };
      return;
    }
    el.oninput=()=>{ dvSet(k,el.value); dirty(); $('.mmpv-in').innerHTML=dvPreview();
      const c=el.parentElement.querySelector('code'); if(c) c.textContent=el.value; };
  });

  const write=(list)=>{ dvSet('sections',list.join(',')); dirty(); paintDividers(); };
  $$('[data-dvsec]').forEach(cb=>cb.onchange=()=>{
    const k=cb.dataset.dvsec, on=dvPicked();
    write(cb.checked ? [...new Set([...on,k])] : on.filter(x=>x!==k));
  });
  const all=$('[data-dvall]'); if(all) all.onclick=()=>write(DV.sections.map(s=>s.key));
  const none=$('[data-dvnone]'); if(none) none.onclick=()=>write([]);

  const save=$('#dvSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of DV.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(dvBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      if(window.kbbDrafts) kbbDrafts.saved('dividers'); $('#dvDirty').style.visibility='hidden';
      toast('Dividers saved');
    }catch(e){ toast('Could not save: '+e.message,'bad'); }
    finally{ save.disabled=false; }
  };
}


/* ---------- Appearance · Login / Register panel ---------- */
let AP=null, APTAB='welcome', APDEV='desktop';

function apBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/account-panel'; }

async function renderAcctPanel(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Login / Register panel</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(apBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    AP=await r.json();
  }catch(e){
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">Could not load the panel settings. <button class="btn small" onclick="renderAcctPanel()">Retry</button></div></div>`;
    return;
  }
  apPaint(); if(window.kbbDrafts) kbbDrafts.ready('acctpanel');
}
function apGet(k){ for(const t of AP.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function apSet(k,v){ for(const t of AP.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }
function apDirty(){ const d=$('#apDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent='Unsaved changes';} }

function apField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-ap="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-ap="${f.key}">
        <i id="apv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='select')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-ap="${f.key}">${Object.entries(f.options||{}).map(([k,l])=>`<option value="${k}"${k===v?' selected':''}>${escHtml(l)}</option>`).join('')}</select></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
    <input type="text" value="${escAttr(String(v))}" data-ap="${f.key}"></div>`;
}

function apPaint(){
  const tab=AP.tabs.find(t=>t.key===APTAB)||AP.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Login / Register panel</h2>
      <p class="mdesc" style="margin:0">The panel behind the account icon, and the welcome shown once after signing in.</p></div>
    <div class="ectabs">${AP.tabs.map(t=>`<button class="ectab${t.key===APTAB?' on':''}" data-aptab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(apField).join('')}</div></div></div>
      <div class="mmpv">
        <div class="apdev"><button class="apd${APDEV==='desktop'?' on':''}" data-apdev="desktop">Desktop</button>
          <button class="apd${APDEV==='phone'?' on':''}" data-apdev="phone">Phone</button></div>
        <div class="mmpv-in" id="apPrev"></div>
        <p class="mmpv-note">${APTAB==='forms'?'The sign-in page':'Signed in · the welcome then folds away'}</p>
        <button class="btn small" id="apReplay" style="margin-top:8px">Play again</button>
      </div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="apDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="apReset">Reset to defaults</button>
      <button class="btn primary" id="apSave">Save changes</button>
    </div></div>`;
  apPreview();
}

/* The panel drawn with the storefront's own class names. */

/* The sign-in form in the chosen style, drawn with the storefront's own
   class names so the two cannot drift. */
function apFormPreview(phone){
  const g=apGet;
  const cls='fs-'+g('form_style')+(g('form_icons')?'':' fs-noicons')+(g('form_strength')?'':' fs-nometer');
  const ico=(n)=>g('form_icons')?`<span class="lead">${APICONS[n]}</span>`:'';
  const fld=(label,n,type)=>`<div class="fld${g('form_icons')?' ico':''}">${ico(n)}
      <input type="${type||'text'}" placeholder=" "><label>${label}</label></div>`;
  $('#apPrev').innerHTML=`<div class="apform ${cls}${phone?' phone':''}" style="--fld-gap:${g('field_gap')}px">
    <div class="authcard">
      ${g('form_mark')?'<span class="mark">KB</span>':''}
      <h1>Welcome back</h1><p class="lede">Sign in to see your orders.</p>
      <div class="segs"><span class="seg on">Sign in</span><span class="seg">Create account</span></div>
      <div class="fgroup">${fld('Email address','mail','email')}${fld('Password','lock','password')}</div>
      <div class="row"><span class="chk"><span class="bx"></span>Stay signed in</span><a>Forgot password?</a></div>
      <button class="go">Sign in</button>
    </div></div>`;
}
const APICONS={
  mail:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 7 8.5 6 8.5-6"/></svg>',
  lock:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="4" y="10" width="16" height="10" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>',
  user:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20c0-3.6 3.4-5.6 7.5-5.6s7.5 2 7.5 5.6"/></svg>'};

function apPreview(){
  const g=apGet, phone=APDEV==='phone';
  if(APTAB==='forms'){ apFormPreview(phone); return; }
  const font=AP.fonts[g('welcome_font')]||AP.fonts.cormorant;
  const size=phone?g('welcome_size_m'):g('welcome_size');
  const vars=`--ap-w:${phone?250:g('panel_width')}px;--ap-r:${g('panel_radius')}px;
    --ap-font:${font.stack};--ap-weight:${font.weight};--ap-size:${size}px;--ap-hold:${g('welcome_hold')}ms`;
  const links=[['link_orders','Orders'],['link_wishlist','Wishlist'],['link_address','Addresses'],['link_track','Track my order']]
    .filter(([k])=>g(k)).map(([,l])=>`<span class="apit">${l}</span>`).join('');
  $('#apPrev').innerHTML=`<div class="apstage${phone?' phone':''}">
    <div class="apbar"><span class="aplg">K-Beauty<em>Bliss</em></span><span class="apic"></span></div>
    <div class="appanel ap-${g('welcome_style')}" style="${vars}" id="apPanelBox">
      <div class="aphead"><span class="apav">R</span><span class="apwho">
        <b class="${g('welcome_show')?'ap-greet ap-'+g('welcome_style'):''}">Rafi</b>
        ${g('welcome_show')
          ? `<span class="apsub"><span class="ap-kick">${escHtml(g('welcome_back'))}</span>
             ${g('show_email')?'<span class="ap-mail">rafi@kbeautybliss.com</span>':''}</span>`
          : (g('show_email')?'<span>rafi@kbeautybliss.com</span>':'')}
      </span></div>
      <span class="apit">My account</span>${links}
      <span class="apout">Sign out</span>
    </div></div>`;
}

document.addEventListener('input', e=>{
  const el=e.target.closest('[data-ap]'); if(!el||!AP) return;
  let v=el.value;
  if(el.type==='range'){ v=+v; const o=$('#apv-'+el.dataset.ap);
    if(o){ let u=''; for(const t of AP.tabs){const f=t.fields.find(x=>x.key===el.dataset.ap); if(f&&f.options) u=f.options.unit||'';} o.textContent=v+u; } }
  apSet(el.dataset.ap,v); apPreview(); apDirty();
});
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-ap]'); if(!el||!AP) return;
  apSet(el.dataset.ap, el.value); apPreview(); apDirty();
});
document.addEventListener('click', async e=>{
  if(!AP) return;
  const tb=e.target.closest('[data-aptab]'); if(tb){ APTAB=tb.dataset.aptab; apPaint(); return; }
  const dv=e.target.closest('[data-apdev]'); if(dv){ APDEV=dv.dataset.apdev; apPaint(); return; }
  const tg=e.target.closest('.ectog[data-ap]');
  if(tg){ const k=tg.dataset.ap, cur=apGet(k); apSet(k,!cur);
    tg.classList.toggle('on',!cur); tg.setAttribute('aria-checked',!cur); apPreview(); apDirty(); return; }
  if(e.target.id==='apReplay'){ apPreview(); return; }
  if(e.target.id==='apReset'){ AP.tabs.forEach(t=>t.fields.forEach(f=>f.value=f.default)); apPaint(); apDirty(); return; }
  if(e.target.id!=='apSave') return;
  const payload={}; AP.tabs.forEach(t=>t.fields.forEach(f=>payload[f.key]=f.value));
  const msg=$('#apDirty');
  try{
    const r=await fetch(apBase(),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({settings:payload})});
    const j=await r.json();
    if(j.ok){ if(window.kbbDrafts) kbbDrafts.saved('acctpanel'); msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} settings — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600); }
    else { msg.style.visibility='visible'; msg.textContent=j.error||'Could not save.'; }
  }catch(err){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
});

/* ---------- Appearance · Header ----------
   Tabs across the top because the header has seven distinct parts; one long
   column would bury the thing being looked for. */
let HD=null, HDTAB='bar';

function hdBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/header'; }

async function renderHeader(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Header</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(hdBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    HD=await r.json();
  }catch(e){
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">Could not load the header settings. <button class="btn small" onclick="renderHeader()">Retry</button></div></div>`;
    return;
  }
  paintHeader(); if(window.kbbDrafts) kbbDrafts.ready('header');
}

function hdField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-hd="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){ const o=f.options||{};
    /* A FIELD THE SHOP IS CURRENTLY IGNORING SAYS SO, AND CANNOT BE DRAGGED.
       `inert` comes from the endpoint, not from this file, because whether a
       control is doing anything is a fact about the shop right now and not
       about the field — see HeaderApiController::show(). Today the only one is
       the header's own Content width while "Header follows the site width" is
       on, which is the state it ships in: the owner dragged it, saved, was told
       "Saved", and the header did not move. A greyed slider with the reason
       under it is the whole fix; a note beside a slider that still slides is
       still a trap. */
    return `<div class="mmrow${f.inert?' is-inert':''}"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}${f.inert&&f.inert_why?`<span class="mminert">${escHtml(f.inert_why)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-hd="${f.key}"${f.inert?' disabled':''}>
        <i id="hdv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='select')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-hd="${f.key}">${Object.entries(f.options||{}).map(([k,l])=>`<option value="${k}"${k===v?' selected':''}>${escHtml(l)}</option>`).join('')}</select></div>`;
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-hd="${f.key}"><code>${v}</code></span></div>`;
  if(f.type==='tags'){
    const words=String(v||'').split(',').map(w=>w.trim()).filter(Boolean);
    const used=words.map(w=>w.toLowerCase());
    const spare=(HD.suggestions||[]).filter(x=>!used.includes(String(x).toLowerCase())).slice(0,14);
    return `<div class="mmrow tagrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <div class="tagbox">
        <div class="tags" id="tagList">${words.map((w,i)=>`<span class="tag">${escHtml(w)}<button type="button" data-tagdel="${i}" aria-label="Remove">&times;</button></span>`).join('') || '<span class="tagempty">No words yet</span>'}</div>
        <div class="tagadd"><input type="text" id="tagInput" placeholder="Type a word and press Enter" maxlength="40">
          <button class="btn small" type="button" id="tagAdd">Add</button></div>
        ${spare.length?`<div class="tagsug"><span>From your catalogue</span>${spare.map(x=>`<button type="button" class="sug" data-tagadd="${escAttr(x)}">${escHtml(x)}</button>`).join('')}</div>`:''}
      </div></div>`;
  }
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
    <input type="text" value="${escHtml(String(v))}" data-hd="${f.key}"></div>`;
}

function hdGet(k){ for(const t of HD.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }

function paintHeader(){
  const tab=HD.tabs.find(t=>t.key===HDTAB)||HD.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Header</h2>
      <p class="mdesc" style="margin:0">Every part of the header, grouped. The mobile menu itself has its own screen.</p></div>
    <div class="ectabs">${HD.tabs.map(t=>`<button class="ectab${t.key===HDTAB?' on':''}" data-hdtab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(hdField).join('')}</div>
      </div></div>
      <div class="mmpv"><div class="mmpv-in" id="hdPhone"></div><p class="mmpv-note">Live preview</p>
        <div id="hdHeightWrap" style="display:none">
          <div class="mmpv-in" style="margin-top:12px" id="hdHeights"></div>
          <p class="mmpv-note">Rows and their heights</p></div>
        <div id="hdPanelWrap" style="display:none">
          <div class="mmpv-in" style="margin-top:12px" id="hdPanel"></div>
          <p class="mmpv-note">Search panel, before typing</p></div>
      </div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="hdDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="hdReset">Reset to defaults</button>
      <button class="btn primary" id="hdSave">Save changes</button>
    </div></div>`;
  hdPreview();
  hdPanelPreview();
  hdHeightPreview();
}

/* The preview uses the storefront's own classes, so it cannot drift. */
function hdPreview(){
  const g=hdGet;
  const vars=`--hd-h:${g('bar_height')}px;--hd-bg:${g('bar_bg')};--hd-logo:${g('logo_size')}px;
    --hd-logo-c:${g('logo_colour')};--hd-logo-a:${g('logo_accent_col')};--hd-radius:${g('search_radius')}px;
    --hd-icon:${g('icon_size')}px;--hd-badge:${g('badge_bg')};--hd-nav:${g('nav_size')}px;--hd-gap:${g('nav_gap')}px;
    --hd-hot:${g('nav_hot_colour')};--hd-sup-bg:${g('support_icon_bg')};--hd-sup-fg:${g('support_icon_fg')};
    --mi-size:${g('menu_icon_size')}px;--mi-speed:${g('menu_icon_speed')}s;
    --mi-c1:${g('menu_icon_c1')};--mi-c2:${g('menu_icon_c2')};--mi-c3:${g('menu_icon_c3')};
    --fb-h:${g('fb_height')}px;--fb-size:${g('fb_size')}px;--fb-flag-h:${g('fb_flag_h')}px;
    --fb-bg:${g('fb_bg')};--fb-ink:${g('fb_ink')};--fb-border:${g('fb_border')}`;
  const icon=g('menu_icon');
  const fam = icon==='tiles'?'tiles' : icon==='dots9'?'dots9' : icon==='dots3'?'dots3' : 'bars';
  const inner = fam==='tiles' ? '<span class="s"></span>'.repeat(4)
              : fam==='dots9' ? '<span class="d"></span>'.repeat(9)
              : fam==='dots3' ? '<span class="d"></span>'.repeat(3)
              : '<span class="b"></span>'.repeat(3);
  const nav=['Brands','Skincare','Sunscreens','SUPER SALE','BLOG'];
  /* The two flags are CONSTANTS here, as they are in app/Support/FlagArt.php:
     emoji regional indicators render as boxed AE/KR on Windows, which is the
     one place this strip's claim must not break. Kept deliberately simple —
     this is a 21px mock, not the shipped artwork. */
  const fbFlags = {
    ae: '<svg viewBox="0 0 30 20" aria-hidden="true"><rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#00732f"/><rect y="13.33" width="30" height="6.67" fill="#000"/><rect width="7.5" height="20" fill="#ce1126"/></svg>',
    kr: '<svg viewBox="0 0 30 20" aria-hidden="true"><rect width="30" height="20" fill="#fff"/><circle cx="15" cy="10" r="5" fill="#cd2e3a"/><path d="M10 10a5 5 0 0 1 10 0 2.5 2.5 0 0 1-5 0 2.5 2.5 0 0 0-5 0z" fill="#0047a0"/></svg>',
  };
  const fbOn = g('fb_mobile') || g('fb_desktop');
  const fbText = String(g('fb_text') || '').trim() || "UAE's Authentic K-Beauty Store";

  $('#hdPhone').innerHTML=`<div class="hdpv" style="${vars}">
    ${fbOn?`<div class="hdpv-fb">${g('fb_flags')?fbFlags.ae:''}<span class="t${g('fb_pill')?' pill':''}">${escHtml(fbText)}</span>${g('fb_flags')?fbFlags.kr:''}</div>`:''}
    <div class="hdpv-bar${g('bar_border')?' bd':''}">
      <span class="kbbmi kbbmi-${icon}">${inner}</span>
      <span class="hdpv-logo">${escHtml(g('logo_text'))}<em>${escHtml(g('logo_accent'))}</em></span>
      <span class="hdpv-icons">${g('icon_account')?`<i>${KBB_HEADER_ICONS.account}</i>`:''}${g('icon_wishlist')?`<i>${KBB_HEADER_ICONS.wishlist}</i>`:''}${g('icon_cart')?`<i class="bg">${KBB_HEADER_ICONS.cart}</i>`:''}</span>
    </div>
    ${g('search_show')?`<div class="hdpv-srch"><span>${escHtml(String(g('search_text')).replace('{n}','671'))}</span></div>`:''}
    ${g('trending_show')?`<div class="hdpv-trend">${['Madeca','PDRN','Retinol'].slice(0,Math.max(1,Math.min(3,g('trending_limit')))).map(t=>`<span>${t}</span>`).join('')}</div>`:''}
    ${g('nav_show')?`<div class="hdpv-nav">${nav.map(n=>`<span class="${n==='SUPER SALE'?'hot':''}">${n}</span>`).join('')}</div>`:''}
    ${g('support_show')?`<div class="hdpv-sup"><i></i>${escHtml(g('support_label'))}</div>`:''}
  </div>`;
}


/* The search panel, drawn from the same settings so the choice can be seen
   rather than imagined. */

/* Both headers drawn at their configured heights, each row labelled, so the
   numbers can be judged as a shape rather than read as digits. */
function hdHeightPreview(){
  const wrap=$('#hdHeightWrap'); if(!wrap) return;
  if(HDTAB!=='bar'){ wrap.style.display='none'; return; }
  wrap.style.display='';
  const g=hdGet;
  const bar=g('bar_height'), nav=g('nav_height'), fld=g('field_height');
  const mbar=g('bar_height_mobile'), mfld=g('field_height_mobile');
  const dTotal=bar+nav+2, mRow=mfld+10, mTotal=mbar+mRow+2;
  $('#hdHeights').innerHTML=`
    <div class="hhblock">
      <div class="hhcap">Desktop <b>${dTotal}px</b></div>
      <div class="hh">
        <div class="hhrow" style="height:${bar}px"><span>Bar</span><i>${bar}px</i>
          <span class="hhfld" style="height:${fld}px">field ${fld}px</span></div>
        <div class="hhrow nav" style="height:${nav}px"><span>Categories</span><i>${nav}px</i></div>
      </div>
    </div>
    <div class="hhblock">
      <div class="hhcap">Phone <b>${mTotal}px</b></div>
      <div class="hh phone">
        <div class="hhrow" style="height:${mbar}px"><span>Bar</span><i>${mbar}px</i></div>
        <div class="hhrow srch" style="height:${mRow}px"><span>Search row</span><i>${mRow}px</i>
          <span class="hhfld" style="height:${mfld}px">field ${mfld}px</span></div>
      </div>
    </div>`;
}

function hdPanelPreview(){
  const wrap=$('#hdPanelWrap'); if(!wrap) return;
  if(HDTAB!=='search'){ wrap.style.display='none'; return; }
  wrap.style.display='';
  const layout=hdGet('search_panel');
  const words=String(hdGet('trending_words')||'').split(',').map(w=>w.trim()).filter(Boolean);
  const t=words.slice(0, hdGet('trending_limit'));
  const chips=a=>`<div class="pchips">${a.map(w=>`<span>${escHtml(w)}</span>`).join('')}</div>`;
  const right=`<div class="pgh">Trending</div>${chips(t)}<div class="pgh">Popular Brands</div>${chips(['Anua','COSRX','Medicube'])}`;
  const recent=['anua toner','sunscreen spf50','cosrx snail','pdrn serum','retinol']
    .slice(0, hdGet('search_recent_count')).map(x=>`<div class="prec">${escHtml(x)}</div>`).join('');
  const prods=Array.from({length:Math.min(3,hdGet('search_results_max'))},()=>'<div class="pprod"><i></i><span></span></div>').join('');
  let inner;
  if(layout==='wide-then-two') inner=`<div class="ppanel">${right}</div>`;
  else if(layout==='two-columns') inner=`<div class="ppanel two"><div><div class="pgh">Popular right now</div>${prods}</div><div>${right}</div></div>`;
  else inner=`<div class="ppanel two"><div><div class="pgh">Most searched this week</div>${recent}<div class="pgh">Popular right now</div>${prods}</div><div>${right}</div></div>`;
  $('#hdPanel').innerHTML=`<div class="pfield"><span>Search skincare, brands…</span></div>${inner}`;
}

function hdDirty(t){ const d=$('#hdDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent=t||'Unsaved changes';} }
function hdSet(k,v){ for(const t of HD.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

document.addEventListener('input', e=>{
  const el=e.target.closest('[data-hd]'); if(!el||!HD) return;
  let v=el.value;
  if(el.type==='range'){ v=+v; const out=$('#hdv-'+el.dataset.hd); if(out){
    let unit=''; for(const t of HD.tabs){const f=t.fields.find(x=>x.key===el.dataset.hd); if(f&&f.options) unit=f.options.unit||'';}
    out.textContent=v+unit; } }
  if(el.type==='color'){ const c=el.nextElementSibling; if(c) c.textContent=v; }
  hdSet(el.dataset.hd, v); hdPreview(); hdPanelPreview(); hdHeightPreview(); hdDirty();
});
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-hd]'); if(!el||!HD) return;
  hdSet(el.dataset.hd, el.value); hdPreview(); hdPanelPreview(); hdHeightPreview(); hdDirty();
});
document.addEventListener('click', async e=>{
  if(!HD) return;
  const tb=e.target.closest('[data-hdtab]');
  if(tb){ HDTAB=tb.dataset.hdtab; paintHeader(); return; }
  const tg=e.target.closest('.ectog[data-hd]');
  if(tg){ const k=tg.dataset.hd; const cur=hdGet(k); hdSet(k,!cur);
    tg.classList.toggle('on',!cur); tg.setAttribute('aria-checked',!cur); hdPreview(); hdPanelPreview(); hdHeightPreview(); hdDirty(); return; }
  if(e.target.id==='hdReset'){ HD.tabs.forEach(t=>t.fields.forEach(f=>f.value=f.default)); paintHeader(); hdDirty('Defaults restored — not saved yet'); return; }
  if(e.target.id!=='hdSave') return;
  const payload={}; HD.tabs.forEach(t=>t.fields.forEach(f=>payload[f.key]=f.value));
  const msg=$('#hdDirty');
  try{
    const r=await fetch(hdBase(),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({settings:payload})});
    const j=await r.json();
    if(j.ok){ if(window.kbbDrafts) kbbDrafts.saved('header'); msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} settings — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600); }
    else { msg.style.visibility='visible'; msg.textContent=j.error||'Could not save.'; }
  }catch(err){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
});

/* ---------- Store · Site Search ----------
   Same schema-driven approach as Header (ssField mirrors hdField), kept as
   its own set of functions rather than reusing the Header ones directly —
   both screens' fields would otherwise answer to the same data-hd attribute
   and the same global listeners, so a change made here would also trip
   Header's own preview and dirty-state logic. Storage is still the same
   HeaderSettings-backed fields; only the editing surface has moved. */
let SS=null, SSTAB='search';

function ssBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/site-search'; }

async function renderSiteSearch(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Site Search</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(ssBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    SS=await r.json();
  }catch(e){
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">Could not load search settings. <button class="btn small" onclick="renderSiteSearch()">Retry</button></div></div>`;
    return;
  }
  paintSiteSearch(); if(window.kbbDrafts) kbbDrafts.ready('search');
}

function ssField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-ss="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-ss="${f.key}">
        <i id="ssv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='select')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-ss="${f.key}">${Object.entries(f.options||{}).map(([k,l])=>`<option value="${k}"${k===v?' selected':''}>${escHtml(l)}</option>`).join('')}</select></div>`;
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-ss="${f.key}"><code>${v}</code></span></div>`;
  if(f.type==='tags'){
    const words=String(v||'').split(',').map(w=>w.trim()).filter(Boolean);
    return `<div class="mmrow tagrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <div class="tagbox">
        <div class="tags" id="ssTagList">${words.map((w,i)=>`<span class="tag">${escHtml(w)}<button type="button" data-sstagdel="${i}" aria-label="Remove">&times;</button></span>`).join('') || '<span class="tagempty">No words yet</span>'}</div>
        <div class="tagadd"><input type="text" id="ssTagInput" placeholder="Type a word and press Enter" maxlength="40">
          <button class="btn small" type="button" id="ssTagAdd">Add</button></div>
      </div></div>`;
  }
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
    <input type="text" value="${escHtml(String(v))}" data-ss="${f.key}"></div>`;
}

/* "Set shown first, by brand" — one row per brand that has a set fitting it
   (its own sets, or sets holding its products), from one grouped query on the
   server. 0 is "Follow the rule above". Every value printed here is escaped;
   the ids are integers the server sent and re-checks on save. */
function ssBrandSets(){
  const rows=SS.sets_by_brand||[];
  const head=`<div class="ssbrand"><b>Set shown first, by brand</b>
    <span>When a search names one of these brands, its chosen set is shown first, whatever “Which set comes first” says. Left on “Follow the rule above”, that brand uses the rule.</span>`;
  if(!rows.length) return head+`<div class="mmrow"><div class="mmlbl"><span>No brand has a visible set yet.</span></div></div></div>`;
  return head+rows.map(r=>`<div class="mmrow"><div class="mmlbl"><b>${escHtml(r.brand)}</b>
      <span class="ssbn">${r.count} ${r.count===1?'set fits':'sets fit'}</span></div>
      <select data-ssbrand="${+r.brand_id}" aria-label="Set shown first for ${escHtml(r.brand)}">
        <option value="0"${r.chosen?'':' selected'}>Follow the rule above</option>
        ${r.sets.map(x=>`<option value="${+x.id}"${+x.id===+r.chosen?' selected':''}>${escHtml(x.name)}</option>`).join('')}
      </select></div>`).join('')+`</div>`;
}
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-ssbrand]'); if(!el||!SS) return;
  const r=(SS.sets_by_brand||[]).find(x=>+x.brand_id===+el.dataset.ssbrand); if(r) r.chosen=+el.value;
  ssDirty();
});

function ssGet(k){ for(const t of SS.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function ssSet(k,v){ for(const t of SS.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function paintSiteSearch(){
  const tab=SS.tabs.find(t=>t.key===SSTAB)||SS.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Site Search</h2>
      <p class="mdesc" style="margin:0">The header search box, its suggestion panel, and how it matches a query.</p></div>
    <div class="ectabs">${SS.tabs.map(t=>`<button class="ectab${t.key===SSTAB?' on':''}" data-sstab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(ssField).join('')}${tab.key==='sets'?ssBrandSets():''}</div>
      </div></div>
      <div class="mmpv"><div class="mmpv-in" id="ssPanel"></div><p class="mmpv-note">Live preview — a sample match for "cream"</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="ssDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="ssReset">Reset to defaults</button>
      <button class="btn primary" id="ssSave">Save changes</button>
    </div></div>`;
  ssPreview();
}

/* A static mock of the suggestion panel — same markup and classes the real
   one uses, so this cannot drift from what a shopper actually sees. Only
   colour/shape settings visibly change it; the behavioural ones (limits,
   minimum characters) do not have a meaningful static preview and are left
   to speak for themselves through their labels and help text. */
function ssPreview(){
  const g=ssGet;
  const vars=`--pink:${g('search_style_accent')};--pink-deep:${g('search_style_accent_deep')};
    --line-2:${g('search_style_chip_bg')};--ink-2:${g('search_style_chip_text')}`;
  $('#ssPanel').innerHTML=`<div class="sugg on" style="position:static;box-shadow:none;${vars};border-radius:${g('search_radius')}px">
    <div class="colA">
      <div class="sgh">Products</div>
      <a class="sgi" style="pointer-events:none"><i style="background:linear-gradient(135deg,#ffd1e2,#ff9fc1)"></i>
        <span><b>Snail Mucin Cream</b><small>COSRX · <span class="price">89 AED</span></small></span></a>
      <a class="viewall" style="border-radius:${g('search_style_radius')}px;pointer-events:none">View all results <b>(12 found)</b> <i>→</i></a>
    </div>
    <div class="colB">
      <div class="sgh">Trending</div>
      <div class="chips">
        <span class="chip" style="pointer-events:none">Retinol</span>
        <span class="chip" style="pointer-events:none">Snail mucin</span>
        <span class="chip" style="pointer-events:none">Medicube</span>
      </div>
    </div>
  </div>`;
}

document.addEventListener('input', e=>{
  const el=e.target.closest('[data-ss]'); if(!el||!SS) return;
  let v=el.value;
  if(el.type==='range'){ v=+v; const out=$('#ssv-'+el.dataset.ss); if(out){
    let unit=''; for(const t of SS.tabs){const f=t.fields.find(x=>x.key===el.dataset.ss); if(f&&f.options) unit=f.options.unit||'';}
    out.textContent=v+unit; } }
  if(el.type==='color'){ const c=el.nextElementSibling; if(c) c.textContent=v; }
  ssSet(el.dataset.ss, v); ssPreview(); ssDirty();
});
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-ss]'); if(!el||!SS) return;
  ssSet(el.dataset.ss, el.value); ssPreview(); ssDirty();
});
function ssDirty(t){ const d=$('#ssDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent=t||'Unsaved changes';} }
document.addEventListener('click', async e=>{
  if(!SS) return;
  const tb=e.target.closest('[data-sstab]');
  if(tb){ SSTAB=tb.dataset.sstab; paintSiteSearch(); return; }
  const tg=e.target.closest('.ectog[data-ss]');
  if(tg){ const k=tg.dataset.ss; const cur=ssGet(k); ssSet(k,!cur);
    tg.classList.toggle('on',!cur); tg.setAttribute('aria-checked',!cur); ssPreview(); ssDirty(); return; }
  if(e.target.id==='ssTagAdd' || (e.target.id==='ssTagInput' && e.key==='Enter')){
    const inp=$('#ssTagInput'); const val=(inp?.value||'').trim(); if(!val) return;
    const cur=String(ssGet('trending_words')||'').split(',').map(w=>w.trim()).filter(Boolean);
    if(!cur.some(w=>w.toLowerCase()===val.toLowerCase())){ cur.push(val); ssSet('trending_words', cur.join(', ')); paintSiteSearch(); ssDirty(); }
    return;
  }
  const del=e.target.closest('[data-sstagdel]');
  if(del){ const idx=+del.dataset.sstagdel; const cur=String(ssGet('trending_words')||'').split(',').map(w=>w.trim()).filter(Boolean);
    cur.splice(idx,1); ssSet('trending_words', cur.join(', ')); paintSiteSearch(); ssDirty(); return; }
  if(e.target.id==='ssReset'){ SS.tabs.forEach(t=>t.fields.forEach(f=>f.value=f.default)); (SS.sets_by_brand||[]).forEach(r=>r.chosen=0); paintSiteSearch(); ssDirty('Defaults restored — not saved yet'); return; }
  if(e.target.id!=='ssSave') return;
  const payload={}; SS.tabs.forEach(t=>t.fields.forEach(f=>payload[f.key]=f.value));
  const body={settings:payload};
  if(Array.isArray(SS.sets_by_brand)){ const m={}; SS.sets_by_brand.forEach(r=>{ m[r.brand_id]=+r.chosen||0; }); body.sets_by_brand=m; }
  let msg=$('#ssDirty');
  try{
    const r=await fetch(ssBase(),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify(body)});
    const j=await r.json();
    /* The server's list is the truth after a save (a set unpublished since
       the screen loaded drops back to "Follow the rule above"). Repainting
       replaces #ssDirty, so the message is written to the new one. */
    if(j.ok&&Array.isArray(j.sets_by_brand)){ SS.sets_by_brand=j.sets_by_brand; if(SSTAB==='sets'){ paintSiteSearch(); msg=$('#ssDirty'); } }
    if(j.ok){ if(window.kbbDrafts) kbbDrafts.saved('search'); msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} settings — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600); }
    else { msg.style.visibility='visible'; msg.textContent=j.error||'Could not save.'; }
  }catch(err){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
});
document.addEventListener('keydown', e=>{
  if(e.target.id==='ssTagInput' && e.key==='Enter'){ e.preventDefault(); $('#ssTagAdd')?.click(); }
});

/* ---------- Appearance · Mobile menu ----------
   Controls on the left, a live phone on the right. Every change repaints the
   phone immediately, so the setting is judged against the result rather than
   against its label. */
let MM=null;

function mmBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/mobile-menu'; }

async function renderMobileMenu(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Mobile menu</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(mmBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    MM=await r.json();
  }catch(e){
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">Could not load the mobile menu settings. <button class="btn small" onclick="renderMobileMenu()">Retry</button></div></div>`;
    return;
  }
  paintMobileMenu(); if(window.kbbDrafts) kbbDrafts.ready('mobilemenu');
}

function mmVal(k){ const f=MM.fields.find(x=>x.key===k); return f?f.value:null; }
function mmField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-mm="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){
    const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-mm="${f.key}">
        <i id="mmv-${f.key}">${v}${o.unit||''}</i></span></div>`;
  }
  if(f.type==='select')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-mm="${f.key}">${Object.entries(f.options||{}).map(([k,l])=>`<option value="${k}"${k===v?' selected':''}>${escHtml(l)}</option>`).join('')}</select></div>`;
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-mm="${f.key}"><code>${v}</code></span></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
    <input type="text" value="${escHtml(String(v))}" data-mm="${f.key}"></div>`;
}

function paintMobileMenu(){
  const byKey={}; MM.fields.forEach(f=>byKey[f.key]=f);
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Mobile menu</h2>
      <p class="mdesc" style="margin:0">The sheet that slides up from the bottom on phones.</p></div>
    <div class="mmgrid">
      <div class="mmcols">
        ${MM.groups.map(g=>`<div class="card mmcard"><div class="mmhd"><b>${escHtml(g.label)}</b><span>${escHtml(g.description)}</span></div>
          <div class="mmbody">${g.fields.map(k=>byKey[k]?mmField(byKey[k]):'').join('')}</div></div>`).join('')}
      </div>
      <div class="mmpv"><div class="mmpv-in" id="mmPhone"></div>
        <p class="mmpv-note">Live preview</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="mmDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="mmReset">Reset to defaults</button>
      <button class="btn primary" id="mmSave">Save changes</button>
    </div></div>`;
  mmPreview();
}

/* The preview mirrors the storefront markup, so what is shown is what ships. */
function mmPreview(){
  const g=mmVal, cols=g('child_columns')==='1'?'1fr':'1fr 1fr';
  const pad={compact:'8px',regular:'10px',roomy:'12px'}[g('density')]||'8px';
  const size={compact:'13px',regular:'13.5px',roomy:'14px'}[g('density')]||'13px';
  const vars=`--mm-top:${100-g('height')}%;--mm-radius:${g('radius')}px;--mm-rule:${g('rule_colour')};
    --mm-rule-w:${g('rule_width')}px;--mm-card:${g('card_bg')};--mm-parent-bg:${g('parent_bg')};
    --mm-parent-fg:${g('parent_colour')};--mm-pad:${pad};--mm-size:${size};--mm-cols:${cols};
    --mm-scrim:rgba(42,34,40,${(g('scrim')/100).toFixed(2)})`;
  const kids=['Sunscreens','Exfoliators','Toners','Eye Care','Face Masks','Face Serums','Moisturizers','Cleansers'];
  $('#mmPhone').innerHTML=`
    <div class="pvphone">
      <div class="pvscrim"></div>
      <div class="pvmenu mm-card-${g('card_style')} mm-rule-${g('rule_position')}${g('show_counts')?'':' mm-nocounts'}" style="${vars}">
        ${g('show_grab')?'<div class="mm-grab"></div>':''}
        ${g('show_close')?'<button class="mm-x">&times;</button>':''}
        ${g('show_heading')?`<div class="mm-head"><b>${escHtml(g('heading_text'))}</b></div>`:''}
        ${g('show_search')?`<div class="mm-srch"><input placeholder="${escHtml(g('search_text'))}" readonly></div>`:''}
        <div class="mm-body">
          <div class="mm-node"><button class="mm-it mm-par">Brands<span class="mm-ct">65</span><span class="mm-car">›</span></button></div>
          <div class="mm-node on"><button class="mm-it mm-par">Skincare<span class="mm-ct">8</span><span class="mm-car">›</span></button>
            <div class="mm-kid"><div class="mm-c2">${kids.map(k=>`<a class="mm-si">${k}</a>`).join('')}</div></div></div>
          <a class="mm-it">Lip Care</a><a class="mm-it hot">SUPER SALE</a><a class="mm-it">Beauty Devices</a>
          ${g('show_account')?`<div class="mm-grp">${escHtml(g('account_label'))}</div><a class="mm-it">Sign in</a><a class="mm-it">Wishlist</a>`:''}
        </div>
        ${g('show_support')?`<div class="mm-foot"><a class="mm-wa">${escHtml(g('support_text'))} · +971 58 505 2611</a></div>`:''}
      </div>
    </div>`;
}

function mmDirty(t){ const d=$('#mmDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent=t||'Unsaved changes';} }

document.addEventListener('input', e=>{
  const el=e.target.closest('[data-mm]'); if(!el||!MM) return;
  const f=MM.fields.find(x=>x.key===el.dataset.mm); if(!f) return;
  f.value = f.type==='range' ? +el.value : el.value;
  if(f.type==='range'){ const o=f.options||{}; const out=$('#mmv-'+f.key); if(out) out.textContent=f.value+(o.unit||''); }
  if(f.type==='colour'){ const c=el.nextElementSibling; if(c) c.textContent=el.value; }
  mmPreview(); mmDirty();
});
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-mm]'); if(!el||!MM) return;
  const f=MM.fields.find(x=>x.key===el.dataset.mm); if(f){ f.value=el.value; mmPreview(); mmDirty(); }
});
document.addEventListener('click', async e=>{
  if(!MM) return;
  const tg=e.target.closest('.ectog[data-mm]');
  if(tg){ const f=MM.fields.find(x=>x.key===tg.dataset.mm);
    f.value=!f.value; tg.classList.toggle('on',f.value); tg.setAttribute('aria-checked',f.value);
    mmPreview(); mmDirty(); return; }
  if(e.target.id==='mmReset'){ MM.fields.forEach(f=>f.value=f.default); paintMobileMenu(); mmDirty('Defaults restored — not saved yet'); return; }
  if(e.target.id!=='mmSave') return;
  const payload={}; MM.fields.forEach(f=>payload[f.key]=f.value);
  const msg=$('#mmDirty');
  try{
    const r=await fetch(mmBase(),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({settings:payload})});
    const j=await r.json();
    if(j.ok){ if(window.kbbDrafts) kbbDrafts.saved('mobilemenu'); msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} settings — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600); }
    else { msg.style.visibility='visible'; msg.textContent=j.error||'Could not save.'; }
  }catch(err){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
});

/* The mock Modules screen that used to live here is gone.
   It rendered a hard-coded MODULES list with toggles held in a JavaScript
   variable — nothing was saved and nothing reached the storefront. The real
   one is `renderModules` further up, backed by ModuleRegistry and the
   /admin-api/modules endpoint.

   It mattered that this went: two functions of the same name in one file
   means the later definition wins, so while this was here the real screen
   was defined and then immediately overwritten. */

/* ---------- Theme ----------
   ===== LANE DJ ==============================================================

   EIGHT CARDS, EIGHT TOASTS - AND FIVE OF THE EIGHT WERE ALREADY BUILT.

   Every card here carried onclick="toast('<name> - builder opens in Phase 2')".
   The lane before this one left them deliberately, reasoning that promising a
   future phase is a milder claim than asserting a present fact. That reasoning
   is sound as far as it goes, and it does not go far enough: a promise that
   something will arrive in Phase 2 is still false when the thing arrived
   already and is two clicks away in the same sidebar. The owner pressed
   "Homepage" on his theme screen, was told to wait for Phase 2, and had a
   working Homepage editor the whole time. Same for the header, the mega menu,
   the product page and the cart panel.

   So this is a signpost now, the same shape Settings was given one lane ago,
   and every card goes where it says:

       Header            Appearance -> Header. Bar, logo, icons, navigation.
       Mega Menu         Store -> Mega Menu. The visual menu builder.
       Mobile Header     Appearance -> Mobile Header.
       Homepage          Appearance -> Homepage. Sections, rails, promos.
       Product page      Appearance -> Product page.
       Cart panel        Appearance -> Cart panel. The slide-out cart.
       Shop Filters      Storefront -> Shop Filters.

   Each was opened and driven before being linked, rather than assumed from the
   name of its render function.

   THE CAPTIONS CHANGED TOO, because three of them promised more than the
   screen behind them delivers. "Cart & Checkout" became "Cart panel": there is
   a designer for the slide-out cart and there is none for checkout, and one
   card covering both would have re-made this screen's original mistake one
   level down. "Shop & Filters" no longer says "AJAX grid, off-canvas filters,
   search" - that screen is an honest "not built yet" page which explains that
   the panel is fixed and that the catalogue is what moves it, so the caption
   now says that instead of describing a builder that does not exist.

   THE THREE THAT ARE NOT BUILT say so, rather than being given a card that
   goes somewhere almost-right. Typography, Colours and Performance have
   nothing behind them anywhere in this application: there is no font setting,
   no colour editor and no performance switch, in this console or out of it.
   The palette printed above them is read from nothing and cannot be edited
   here - those seven values are hard-coded in resources/css/kbb/*.css, which
   is precisely why there is no Colours screen to send anyone to. It is shown
   because it is true and useful to see, and it is labelled as fixed.

   ONE OF THOSE THREE WAS WRONG (Lane DN), AND WRONG IN THE EXPENSIVE
   DIRECTION. The Colours card denied that any colour editor existed behind
   this console, and the swatch band said all seven values belonged to the
   stylesheet and not to any setting. That is still true of six of the seven
   swatches. It was never true of the first one:

   `brand_accent` has been read by App\View\Composers\StoreComposer since
   the baseline and is emitted as --pink and --pink-deep over the whole
   storefront by layouts/store.blade.php, and #E0567B is only its DEFAULT.

   What was actually missing was the writer - no screen in this console posted
   the key - and a console that says a thing cannot be done is how a missing
   writer survives being noticed. Both halves moved together: the field is on
   Business Details, the rule is in AdminController::SETTING_RULES, and these
   two notes now say which one colour is editable and which six are not,
   rather than lumping all seven under "fixed".
   ========================================================================= */
function renderTheme(){
  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>K-Beauty Bliss Theme</h2><p>This is not a screen of its own. Your storefront&rsquo;s design is set on the screens that own each part of it, and this page is the index of where each part lives.</p></div>
    <div class="card pad" style="margin-bottom:18px">
      <div class="between"><b style="font-size:14px">Brand tokens</b><span class="pill grey">one is yours to set</span></div>
      <div class="swatches" style="margin-top:14px">
        ${['#E0567B|Rose','#C13E63|Deep','#A82F53|Ink rose','#FFF0F4|Soft','#FCE0E8|Blush','#BE8E2E|Gold','#2A2228|Ink'].map(s=>{const[c,n]=s.split('|');return `<div class="sw" style="background:${c}"><span>${n}</span></div>`}).join('')}
      </div>
      <p style="font-size:12px;color:var(--ink-soft);margin-top:12px;line-height:1.55">These are the colours your storefront actually uses. <b>Rose</b> is your accent colour and you can change it &mdash; it is the pink on buttons, prices, links and sale badges, and <b>Deep</b> is worked out from it, which is why the two are never set separately. Set it under <a href="#store-settings" onclick="go('store-settings');return false;">Business Details &rarr; Your brand colour</a>; the swatch above shows the colour this theme ships with. The other five are part of the theme&rsquo;s stylesheet rather than a setting, so they are shown here to be read, not changed &mdash; altering those is a code change.</p>
    </div>
    <div class="sec-title">Where your design is set</div>
    <div class="tcards">
      ${[['Header','header','The top bar, your logo, the icons beside it and the main navigation.','<path d="M3 5h18M3 5v4h18V5M7 13h10M7 17h6"/>'],
        ['Mega Menu','megamenu','The drop-down menu builder &mdash; columns, links, images and what each one points at.','<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M4 11h6v9H4zM14 11h6v4h-6z"/>'],
        ['Mobile Header','mobilehdr','The same bar as it appears on a phone: spacing, the search field and the divider under it.','<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M7 7h10"/>'],
        ['Homepage','homepage','The sections your front page is built from, what order they run in, and the promos inside them.','<rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/>'],
        ['Product page','productpage','How a single product is laid out &mdash; the gallery, the buy box and what sits under them.','<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h4"/>'],
        ['Cart panel','cartpanel','The slide-out cart: its size, what it lists, how it behaves and the wording inside it.','<circle cx="9" cy="20" r="1.5"/><circle cx="17" cy="20" r="1.5"/><path d="M2 3h3l2.5 13h10L20 7H6"/>'],
        ['Shop Filters','shopfilters','What decides the filter panel on your shop page. The panel itself is fixed; your catalogue is what moves it.','<path d="M3 5h18M6 12h12M10 19h4"/>']
      ].map(t=>`<div class="tcard" onclick="go('${t[1]}')"><div class="ti">${ic(t[3])}</div><b>${t[0]}</b><p>${t[2]}</p></div>`).join('')}
    </div>
    <div class="sec-title">Not built yet</div>
    <div class="card pad">
      <div style="display:flex;flex-direction:column;gap:14px">
        <div><b style="font-size:13px">Typography</b><p style="font-size:12px;color:var(--ink-soft);margin-top:4px;line-height:1.55">There is no font setting anywhere in this application. The storefront&rsquo;s typefaces, sizes and weights are part of its stylesheet, so changing them is a code change rather than something this console can offer.</p></div>
        <div><b style="font-size:13px">A full colour editor</b><p style="font-size:12px;color:var(--ink-soft);margin-top:4px;line-height:1.55">Your accent colour <i>is</i> editable &mdash; it is under <a href="#store-settings" onclick="go('store-settings');return false;">Business Details &rarr; Your brand colour</a>, and the palette above marks it. What does not exist is an editor for the rest of the palette: the soft pinks behind sections, the gold and the ink are fixed in the theme&rsquo;s stylesheet, so changing those is a code change.</p></div>
        <div><b style="font-size:13px">Performance</b><p style="font-size:12px;color:var(--ink-soft);margin-top:4px;line-height:1.55">Nothing in this application lets you switch parts of the storefront on or off to make it lighter. Your shop is no slower than it was &mdash; there was simply never anything behind this card.</p></div>
      </div>
    </div>
  </div>`;
}

/* ---------- Users ----------
   ===== LANE DJ ==============================================================

   THREE MEMBERS OF STAFF WHO DO NOT EXIST.

   What stood here drew a table of "Rafi / Owner / 2FA On", "Store Manager /
   Manager / 2FA Off" and "Support Agent / Support / Invited", built out of a
   urow() helper from seven string literals per row. It read nothing. The
   install has real rows in `admin_users` and a real role on each one, and none
   of the three names above was ever one of them. Beside them sat an "Invite
   user" button whose whole body was toast('Invite flow - Phase 0 build').

   That is worse here than on any other screen in this console. "Who can sign
   in to my shop" is a security question, and the answer given was three names
   somebody typed. An owner checking whether an ex-employee still had access
   would have been told about a "Support Agent" who was never issued an
   account, and reassured by a "2FA On" pill for a second factor this
   application does not have - there is no TOTP, no OTP column, no second
   factor anywhere in this codebase. The only other mention of 2FA in this file
   is a Phase 6 roadmap row that correctly lists it as something to build.

   IT WAS ALREADY DEAD, AND THAT IS THE DANGEROUS PART. The real screen has
   existed since the baseline: `window.renderUsers` in the live-wiring block
   below reads GET /admin-api/users and renders the actual accounts, their
   actual roles and emails, with no 2FA column at all. Because that assignment
   runs later than this declaration, it wins, and the table above was never
   what the owner saw - which is exactly why it survived: it is invisible until
   the day it isn't.

   It is one throw away from being visible. Everything from `window.render-
   Customers` to `window.renderUsers` lives in the second <script> block; a
   runtime error anywhere in that block before the assignment leaves THIS
   function standing, and go('users') then renders the fiction with nothing on
   the page to say so. Verified by injecting one throw at the head of that
   block: the console came back up, navigation still worked, and Users & Roles
   showed Store Manager, Support Agent and "2FA On".

   So the declaration is kept - removing the name outright would make the
   dispatch object in go() throw a ReferenceError while building, and take
   every OTHER screen down with it - and its body is now the same sentence the
   frame screens already use when the script did not finish starting up. The
   failure mode goes from "invents three colleagues" to "says it could not
   load". urow() went with the table; it had no other caller.
   ========================================================================= */
function renderUsers(){
  $('#content').innerHTML=frameStartupHTML('Users & Roles');
}

/* ---------- Settings ----------
   ===== LANE DH ==============================================================

   SIX CARDS, SIX TOASTS. Store details, Regional, Localisation, Notifications,
   Security and API & Keys each carried onclick="toast('... - Phase 0 build')".
   Every one of them opened nothing. A card captioned "Encrypted credentials
   store" that does nothing when pressed is not a placeholder: it is a screen
   telling the owner his settings live somewhere he can reach, and then not
   taking him there.

   BUILT, OR SAID PLAINLY? Said plainly - with one qualification that changes
   what the screen is for. Four of the six subjects DO exist in this console,
   under other names, fully wired:

       Store details   Store -> Business Details. Store name, currency, the
                       shop's time zone, delivery and COD fees, tax. Written
                       through PUT /admin-api/settings, which validates each
                       key against AdminController::SETTING_RULES.
       Regional        the same screen: currency, symbol, decimals, position,
                       time zone, and the Tax tab beside them.
       Notifications   Store -> Mail. The transport, the addresses mail is
                       sent from and replied to, and the delivery log.
       API & Keys      Store -> Payments. The Stripe, Tabby and Tamara
                       credentials, encrypted at rest.

   So this was never an unbuilt screen. It was a second front door to four
   built ones that nobody had connected, sitting one section above them in the
   same sidebar. It is a signpost now, and every row goes where it says.

   The other two are not built and say so, rather than being given a card that
   goes somewhere almost-right. There is no localisation feature in this
   application - no second language, no RTL switch, no translation store - and
   no security settings: sessions, password rules and login protection are
   framework defaults and are not editable from anywhere in this console. The
   nearest true thing to the Security card was Users & Roles, and pointing at
   it would be the wrong answer, because what that card offered is not there
   either.

   WHAT WAS REJECTED. Building six real panels here, on the grounds that four
   of the subjects have settings behind them already. That would be a second
   editor for keys a screen further down the sidebar already owns - two places
   writing store_name, two places holding the Stripe secret, and no way for the
   owner to know which one he last saved. The map is the honest version and it
   is also the smaller one.

   The precedent is renderSandbox() and renderMeta() directly: say what is true
   of this install, then hand over the screen that really does the job.
   ========================================================================= */
function renderSettings(){
  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>Settings</h2><p>This is not a screen of its own. The shop&rsquo;s configuration is kept on the screens that use it, and this page is the index of where each part lives.</p></div>
    <div class="sec-title">Where your settings are</div>
    <div class="tcards">
      ${[['Business Details','store-settings','Store name, currency and how prices are printed, the shop&rsquo;s time zone, delivery and cash-on-delivery fees.','<path d="M3 9l9-6 9 6v11a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>'],
        ['Tax','tax','The VAT rate, whether it is included in prices or added on top, and a rate per country. The Tax tab of Business Details.','<path d="M4 4h12l4 4v12H4z"/><path d="M8 10h8M8 14h5"/>'],
        ['Mail','mail','Who email is sent from, who a reply goes to, the transport it leaves through, and the log of what was sent.','<path d="M3 6h18v12H3z"/><path d="m3 7 9 6 9-6"/>'],
        ['Payments','payments','Your Stripe, Tabby and Tamara keys. Held encrypted; this is the only screen that can read or change them.','<path d="M21 2l-2 2m-7 7a5 5 0 1 1-7 7 5 5 0 0 1 7-7zM15 7l4 4"/>'],
        ['Delivery &amp; Shipping','shipping','What delivery costs, per country, and which countries the shop ships to at all.','<path d="M2 6h11v9H2z"/><path d="M13 9h4.5l3.5 3.5V15h-8z"/><circle cx="6" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>'],
        ['SEO &amp; Meta','seo','The site name, the titles and descriptions search engines read, verification tokens and your analytics ID.','<path d="M4 7h16M4 12h10M4 17h7"/><circle cx="18" cy="16" r="3"/><path d="m22 20-1.5-1.5"/>']
      ].map(t=>`<div class="tcard" onclick="go('${t[1]}')"><div class="ti">${ic(t[3])}</div><b>${t[0]}</b><p>${t[2]}</p></div>`).join('')}
    </div>
    <div class="sec-title">Not built yet</div>
    <div class="card pad">
      <div style="display:flex;flex-direction:column;gap:14px">
        <div><b style="font-size:13px">Localisation</b><p style="font-size:12px;color:var(--ink-soft);margin-top:4px;line-height:1.55">This shop is in English only. There is no second language, no right-to-left mode and no translation store anywhere in the application, so there is nothing here to switch on.</p></div>
        <div><b style="font-size:13px">Security settings</b><p style="font-size:12px;color:var(--ink-soft);margin-top:4px;line-height:1.55">Sessions, password rules and login protection are the framework&rsquo;s defaults and cannot be edited from this console &mdash; changing them is a code change, not a setting. Your shop is no less protected than it was: there is simply no screen for it, and there was never one behind this card.</p></div>
      </div>
    </div>
  </div>`;
}

/* ---------- Debug & Monitor ----------
   ===== LANE DH ==============================================================

   THIS WHOLE SCREEN WAS TYPED IN.

   The Service health card was six hrow() literals under an amber pill reading
   "2 need setup": an App server averaging 120ms, a Database doing 4ms reads in
   WAL mode, a Storefront API that was operational, and three amber rows for
   Stripe, Tabby / Tamara and SMTP. Not one was measured. The database line was
   also wrong about the engine - production is MySQL.

   Under it, an "Error console" headed "3 open" listed three errors that had
   never happened, each with an invented count and age, and each with a Report
   button. The Report button and the "Copy report for Claude" button both
   opened the same modal, which handed the owner a diagnostic naming a Stripe
   secret-key failure at modules/payments/api.js:48 - a file that does not
   exist in this application, describing a request for AED 549.78 that nobody
   ever made. Its Copy report button copied nothing: it closed the modal and
   raised a toast that said the report had been copied.

   So an owner whose product pages were all 500ing opened Debug & Monitor and
   was told the site was fine and that his only problem was a Stripe key.

   WHAT IS HERE NOW is the check that was already written and routed nowhere.
   app/Http/Controllers/Admin/HealthApiController.php renders all eight public
   pages in-process and reports each status, and for a failure the exception
   message and the file and line in this application that raised it. It is on
   GET /admin-api/health (routes/health-admin.php). The card below is that
   answer and nothing else, the report is built from that answer, and the copy
   button copies.

   NOTHING IS SAID ABOUT STRIPE, TABBY OR SMTP, here or on the dashboard. This
   check cannot see them. The real error log - the tail of
   storage/logs/laravel.log, which this screen never once offered - is one
   click away instead, because that is the thing that actually knows.

   err() went with the three errors it drew; it had no other caller.
   ========================================================================= */
function renderDebug(){
  go._cur='debug';
  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>Debug & Monitor</h2><p>Opens every public page of your shop from inside the server and tells you what each one returns. It is a check you run, not a monitor that watches &mdash; nothing here is measured until you press the button.</p></div>
    <div class="card pad" style="margin-bottom:16px">
      <div class="between"><b style="font-size:14px">Storefront health</b><span class="pill grey" id="shPill"><span class="d"></span>Not checked yet</span></div>
      <div id="shRows" style="margin-top:14px">
        <p style="font-size:12.5px;color:var(--ink-soft);line-height:1.55">Nothing has been checked yet. <b>Check now</b> loads the home page, the shop, one product, one category, the cart, the checkout, the journal and the review wall, and reports what each one returns. When a page fails you get the error and the file and line it came from.</p>
      </div>
      <div class="row" style="margin-top:14px;gap:9px;flex-wrap:wrap">
        <button class="btn sm" id="shRun" onclick="kbbHealthRun()">Check now</button>
        <button class="btn ghost sm" onclick="kbbOpenErrorLog()">Open the error log &rarr;</button>
      </div>
    </div>
    <div class="card pad" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
      <div style="flex:1;min-width:240px"><b style="font-size:13.5px">Found a bug on live?</b><div style="font-size:12px;color:var(--ink-soft);margin-top:3px">Run the check above, then copy its result and send it over. The report is that check's result and nothing more &mdash; if a page is failing, it carries the error and the line.</div></div>
      <button class="btn" onclick="reportModal()">${ic(I.copy)} Copy report for Claude</button>
    </div>
  </div>`;
}

/* The tail of storage/logs/laravel.log, on the route that has served it since
   before this screen existed. It sits one level inside the admin path, which is
   configurable, so the URL is built from where this page actually is rather
   than from a literal. Owner-only, like the health check: both hand back the
   same class of detail. */
function kbbHealthLogUrl(){
  return window.location.pathname.replace(/\/+$/,'') + '/kbb-health-log';
}
function kbbOpenErrorLog(){
  window.open(kbbHealthLogUrl(), '_blank', 'noopener');
}

/* The report the owner sends on. It is a transcript of the last check this
   browser ran -- no check, no report, because there is nothing to write down.
   Built as plain text so that what is on the screen and what lands on the
   clipboard are the same characters. */
function kbbHealthReportText(){
  const h = window.KBB_HEALTH;
  if(!h || (!h.data && !h.error)) return null;
  const lines = ['// KBB storefront health check'];
  lines.push('checked_at: ' + (h.at ? h.at.toISOString() : 'unknown'));
  if(h.error){
    lines.push('result: the check could not be run');
    lines.push('reason: ' + h.error);
    return lines.join('\n');
  }
  lines.push('pages_checked: ' + h.data.checked);
  lines.push('pages_failing: ' + h.data.failed);
  lines.push('');
  (h.data.results||[]).forEach(function(r){
    lines.push((r.ok ? 'ok   ' : 'FAIL ') + ('HTTP ' + r.status).padEnd(10) + ' ' + r.path);
    if(!r.ok && r.error) lines.push('       error: ' + r.error);
    if(!r.ok && r.where) lines.push('       where: ' + r.where);
  });
  return lines.join('\n');
}

function reportModal(){
  const text = kbbHealthReportText();
  if(text === null){
    openModal(`<div class="modal-h">${ic(I.copy)}<b>Nothing to report yet</b><button class="x" onclick="closeModal()">${ic('<path d="M18 6 6 18M6 6l12 12"/>')}</button></div>
    <div class="modal-b">
      <p style="font-size:12.5px;color:var(--ink-soft);line-height:1.6">There is no report until a check has been run. Press <b>Check now</b> on this screen or on the dashboard, then come back &mdash; the report is that check's result written out, so there is nothing honest to put in it beforehand.</p>
      <div class="row" style="margin-top:16px;justify-content:flex-end;gap:9px">
        <button class="btn ghost" onclick="closeModal()">Close</button>
        <button class="btn" onclick="closeModal();go('debug');kbbHealthRun()">Run the check</button>
      </div>
    </div>`);
    return;
  }
  openModal(`<div class="modal-h">${ic(I.copy)}<b>Storefront health report</b><button class="x" onclick="closeModal()">${ic('<path d="M18 6 6 18M6 6l12 12"/>')}</button></div>
  <div class="modal-b">
    <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:12px">The result of the last check on this screen. It carries no keys, no customer data and no passwords &mdash; only page addresses and the errors they returned.</p>
    <div class="report" id="shReport">${escHtml(text)}</div>
    <div class="row" style="margin-top:16px;justify-content:flex-end;gap:9px">
      <button class="btn ghost" onclick="closeModal()">Close</button>
      <button class="btn" onclick="kbbCopyReport()">${ic(I.copy)} Copy report</button>
    </div>
  </div>`);
}

/* It copies. The button this replaces closed the modal and raised "Report
   copied - paste it to Claude" without touching the clipboard, so the owner
   pasted whatever he had copied last. The toast is raised from the result now,
   and a refusal says so instead of claiming success. */
async function kbbCopyReport(){
  const text = kbbHealthReportText();
  if(text === null){ closeModal(); return; }
  let ok = false;
  try{
    if(navigator.clipboard && navigator.clipboard.writeText){
      await navigator.clipboard.writeText(text);
      ok = true;
    }
  }catch(e){ ok = false; }
  if(!ok){
    /* Clipboard access is refused outside a secure context and in some
       embedded previews. execCommand is the old path and still works there. */
    try{
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly','');
      ta.style.position='fixed'; ta.style.top='-1000px';
      document.body.appendChild(ta);
      ta.select();
      ok = document.execCommand('copy');
      document.body.removeChild(ta);
    }catch(e){ ok = false; }
  }
  closeModal();
  ok
    ? toast('Report copied')
    : toast('Could not copy — select the report text and copy it by hand', 'bad');
}

/* ---------- Sandbox & Deploy ----------
   ===== LANE DD ==============================================================

   THIS SCREEN DESCRIBED A RELEASE PROCESS THIS APPLICATION DOES NOT HAVE, and
   every number on it was typed in by hand.

   It showed two environment cards ("Clone of live data: yes"), five pre-flight
   checks all lit green with invented detail under each one ("8 tables ·
   non-destructive", "0 critical errors"), a diff summary of +8 tables and +34
   files, a Rollback button and a Deploy to Live button. None of it was
   measured. The checks were five string literals; the deploy handler was a
   1.1-second setTimeout that flipped a boolean in this file and reported
   "Deployed to Live ✓ · backup saved"; rollback flipped it back. The banner at
   the foot promised that every deploy takes a backup of the live database
   first and keeps the previous version for instant rollback, and nothing in
   this application had ever taken a database backup on that path, because
   there is no such path.

   The same restraint as Meta & Facebook further up: the one-line fix would
   have been to wire the buttons to something, and that would have been the
   wrong one. A shop owner reading "5 / 5 passed" before pressing a button
   labelled Deploy to Live is being told his release was checked. Making that
   sentence true is a release pipeline, not a screen.

   AND THE REAL ONE ALREADY EXISTS, one row further down the sidebar. Core
   Updates applies a signed package, records the release, keeps a restore point
   per apply and lists it for one-click restore — UpdateController, the
   `update_releases` table and the Restore list on that screen. This screen now
   says so and takes the owner there, rather than competing with it.

   deploy(), rollback(), the `deployed` flag and the chk() helper went with the
   buttons that called them; they had no other caller. The `window.deploy` /
   `window.rollback` exports went too, for the same reason.
   ========================================================================= */
function renderSandbox(){
  $('#content').innerHTML=`<div class="wrap"><div class="ph">
    <div class="pic">${ic(I.sandbox)}</div>
    <h3>There is no sandbox to deploy from</h3>
    <p>This store has one database and one set of files. Nothing you change in this console is staged anywhere first — a price, a coupon or a VAT rate is live the moment you save it, and the Live / Sandbox switch in the top bar only changes its own label.</p>
    <p>Code reaches this site as an update package, and that is where the checking, the restore point and the version history actually are: <b>Core Updates</b> verifies a package, records the release and keeps a restore point you can put back in one click.</p>
    <button class="btn" onclick="go('updates')">Core Updates →</button>
  </div></div>`;
}

/* ---------- placeholder (future modules) ---------- */
/* LANE DF. Lifted out of renderPlaceholder() so that something other than
   renderPlaceholder can ASK which `p-` ids are real. The deep-link boot needs
   exactly that question: go() routes every id beginning `p-` straight in here,
   and an id that is not in this map leaves `m` undefined, so the next line
   throws. Reached from a click that can only come from a card this console
   drew, that never happened. Reached from an ADDRESS, which anybody can type,
   it would take down the rest of the console's start-up with it — the boot
   block is not inside a try. So the boot admits a `p-` id only if it is a key
   here, and the map has to be visible to it to be asked. */
const PLACEHOLDERS={'p-catalog':['Catalog','products, categories, brands, attributes','P1'],'p-orders':['Orders','orders, refunds, invoices','P3'],'p-cust':['Customers','accounts, addresses, reviews','P3'],'p-mkt':['Growth & Marketing','coupons, loyalty, routines, video, WhatsApp','P2–P4'],'p-content':['Content & Pages','page builder, blog, forms','P5']};
function renderPlaceholder(id){
  const m=PLACEHOLDERS[id];$('#crumb').textContent='Store';$('#ptitle').textContent=m[0];
  $$('.side .nav-item').forEach(b=>b.classList.toggle('on',b.dataset.go===id));syncNavOpen(id);
  $('#content').innerHTML=`<div class="wrap"><div class="ph">
    <div class="pic">${ic(I.modules)}</div>
    <h3>${m[0]} isn't installed yet</h3>
    <p>This area is part of the plan and arrives in <b>Phase ${m[2]}</b> (${m[1]}). Every feature is a module you install and toggle from the Modules screen — that's the pluggable foundation we just built.</p>
    <button class="btn" onclick="go('modules')">Open Modules →</button>
  </div></div>`;
  $('#content').scrollTop=0;$('#side').classList.remove('open');
}

/* ===== LANE AV — the standalone .html screens ================================
   Every entry in FRAME_SRC and REV_SRC names a standalone HTML file that this
   repo does not ship and never has. Established, not assumed: none of the names
   is tracked in git on any branch, none is anywhere on this filesystem, and the
   Master Plan recorded the same finding at 2.60.71 — traced from the owner's own
   404 screenshot, taken inside the LIVE admin panel, of `kbb-admin-blog.html`.
   That screenshot is the one piece of direct evidence anybody has about the
   production web root, and it says the file is not there either.

   It is only one of the names, though. The web root is a different directory
   from the application root (bootstrap/app.php's usePublicPath), so this tree
   cannot see it and cannot prove the negative for the other eight. Nothing below
   assumes an answer; the two paths are each correct whichever way it falls.

   1. Screens a later window.go override re-renders for real (LIVE_RENDERED)
      must not draw a frame at all. Drawing one fired a request for a file that
      is not there and was overwritten a moment later: a guaranteed 404 on every
      single visit, for markup no one ever saw. They now get the honest message
      Lane AM wrote for 'rev-all', which is visible only in the case it describes
      — the live wiring failing to start — and costs no request at all.

   2. Screens with no live renderer ASK the server whether the file is there
      before deciding. Present: it is shown, which is the case where these files
      did survive in the production web root. Absent: the screen says so plainly
      rather than rendering an empty iframe. A blank screen reads as "the
      software is broken"; this cannot go blank either way.
   ========================================================================== */
/* 'rev-settings' is here for the same reason as the rest (Lane BB): it is
   rendered for real, by admin/partials/review-settings-screen.blade.php at the
   foot of this file. Without this entry mountFrame() would probe for
   kbb-admin-reviews-settings.html -- a file this repo has never shipped -- on
   every single visit, and paint the not-built card a moment before the live
   screen overwrote it.

   'htmlblocks' is here on the same grounds (Lane BC): it is drawn by
   admin/partials/html-blocks-screen.blade.php, which is included after this
   document's script and wraps window.go the way the other lane screens do. Its
   entry in FRAME_SRC points at kbb-admin-blocks.html, another file this repo
   has never shipped, so without this every visit fired a HEAD that could only
   404 and was then painted over by the real screen a moment later.

   'rev-add' and 'rev-likes' are here on the same grounds (Lane BD): both are
   drawn by admin/partials/review-bulk-screens.blade.php, included after this
   document's script, which wraps window.go the way the other lane screens do.
   Their REV_SRC entries point at kbb-admin-bulkadd.html and
   kbb-admin-bulklikes.html, two more files this repo has never shipped, so
   without these entries every visit fired a HEAD that could only 404 and was
   then painted over by the real screen a moment later.

   'rev-io', 'rev-badge', 'rev-capsule' and 'rev-assign' join them on exactly
   the same grounds (Lane BE). They are drawn by admin/partials/
   reviews-io-screen, review-badges-screen, review-capsule-screen and
   review-assign-screen, each included after this document's script and each
   wrapping window.go the way the other lane screens do. Their entries in
   REV_SRC point at kbb-admin-exportimport.html, kbb-admin-badgethemes.html,
   kbb-capsule-editor.html and kbb-admin-assign.html -- four more files this
   repo has never shipped -- so without this every visit fired a HEAD that could
   only 404 and was then painted over by the real screen a moment later.

   With Lane BD's two and Lane BE's four, NO id in REV_SRC still goes through
   mountFrame(): every one of the eight Reviews screens is rendered in this
   document now. The REV_SRC entries are kept rather than deleted so that
   nothing which reads that map -- goTab(), a bookmark, a later lane -- finds a
   hole where a Reviews id used to be.

   None of these ids is re-rendered by the override further down THIS file; all
   are live the same, which is why path 1 above applies to them. */
const LIVE_RENDERED=new Set(['orders','payments','analytics','seo','blog','posts','store-settings','quiz-leads','rev-settings','htmlblocks','rev-add','rev-likes','rev-io','rev-badge','rev-capsule','rev-assign']);

/* ===== LANE DF · the OTHER way a link misses its screen ====================
   LIVE_RENDERED above is the set of ids whose deep link landed on a CARD — the
   "could not be loaded" one, which at least admitted something was wrong. This
   set is the ids whose deep link landed on the DASHBOARD, under their own
   breadcrumb, saying nothing at all. The owner clicks Media Library in the
   sidebar and gets the Media Library; he follows a link to it and gets a page
   headed "Content · Media Library" whose body is the dashboard, with no error
   anywhere on it. There is nothing on that screen for him to report.

   The mechanism is one line of go(): its dispatch object ends `||renderDash`,
   so an id that is routable — in TITLES, with a sidebar row — but has no entry
   in that object silently becomes the dashboard. Both of these ids have a real
   renderer; it is simply installed LATER in the document than the boot that
   navigates to them. 'media' is drawn by admin/partials/media-library-screen,
   'tax' by the live-wiring script at the foot of this file, and neither exists
   yet a few lines after buildNav().

   WHY A SECOND SET RATHER THAN A WIDER TEST. The obvious shape is to ask "could
   the go() that just ran draw this at all?", and Lane DA wrote that, measured
   it and withdrew it. It also catches 'rev-all' and 'customers', which draw
   themselves from `cur` further down this file — and renderReviews() awaits
   rvLoad() BEFORE it paints, so the deep-link marker was still in place when
   the replay ran and All Reviews was drawn twice on two runs out of three. A
   screen that paints synchronously cannot lose that race; a screen that awaits
   first always can. Naming the set keeps every id in it one that has NO painter
   of its own to race — the replay is the only thing that will ever draw it.

   NOT A LIST SOMEONE HAS TO REMEMBER. AdminNavAndIdsTest derives this set from
   the file — every TITLES id that misses go()'s dispatch object, the two frame
   maps and the `p-` branch, less the ids that boot themselves from `cur` — and
   fails if this literal has drifted from it in either direction. Adding a
   screen to TITLES and forgetting to wire it up fails there, which is the whole
   point: the defect was silent, so the guard must not be. ===== */
/* The four Translation screens join this set for exactly the reason the note
   above gives. They are in TITLES, they have sidebar rows, and go()'s dispatch
   object has no entry for any of them — their renderer is installed LATER in
   the document, by admin/partials/translation-screens.blade.php, which does not
   exist yet a few lines after buildNav(). Without them here, ?go=tr-settings
   opens the DASHBOARD under the heading "Translation · Settings" with no error
   anywhere on the page: the owner follows a link to the screen he turns Arabic
   on from and is shown the dashboard instead, with nothing to report.

   They are safe to arm, which is the condition this set carries. Each of the
   four paints synchronously — window.go in that partial calls render() before
   it awaits anything — so the replay's marker inside #content is already
   destroyed by the time its task runs and nothing is drawn twice. That is the
   rule 'rev-all' failed, and the reason it is in neither armed set. */
/* The four shoppable-video and Instagram screens, for the same reason again and
   found the same way: ?go=ugcvideo and #ugcvideo opened the DASHBOARD under
   whatever breadcrumb, because none of the four ids was in TITLES at all, so
   nothing routed and go() fell through to renderDash. The screens had no
   shareable URL and no way in but a click, which is also why the lane that
   photographed them had to call window.go('ugcvideo') by hand.

   They are safe to arm on the condition this set carries: each of the four wraps
   window.go in its own partial and calls render() BEFORE load(), synchronously,
   so the replay's marker inside #content is already destroyed by the time its
   task runs and nothing is drawn twice. That is the rule 'rev-all' fails, which
   awaits rvLoad() before it paints and is in neither armed set. */
/* 'sets' — Catalog → Sets, added when Lane SET merged, by the same rule a
   fourth time. Its partial wraps window.go and calls render() before load(),
   synchronously, so the replay's marker is gone before the task runs. Without
   it ?go=sets and #sets open the DASHBOARD, which is the defect this set was
   written for and which ugcvideo, ugcsections, ugcstyle and instagram each hit
   before being armed. */
/* 'banners' — Appearance → Banners, added when Lane BN merged, on the same
   condition: its partial wraps window.go and calls render() before load(),
   synchronously. */
/* 'product-tabs' — Catalog → Product tabs, added when Lane PT merged, and
   caught by AdminNavAndIdsTest on the day it was wired rather than by a
   shopper: a screen absent from TITLES opens the DASHBOARD on ?go= and has no
   shareable URL at all, which is the same defect ugcvideo, ugcsections,
   ugcstyle, instagram and sets each hit before being armed. Safe to arm on this
   set's condition — its partial wraps window.go and calls render() before
   load(), synchronously, so the replay's marker inside #content is destroyed
   before the task runs. */
const LATE_RENDERED=new Set(['cartpanel','media','tax','tr-settings','tr-progress','tr-strings','tr-machine','hpcontent','ugcvideo','ugcsections','ugcstyle','instagram','sets','product-tabs','pagination','ownerapp','banners','setap','cache','cartpage','checkoutpage','routines','security','paygw','sitelayout','slimfooter','gridsections','pagewash','wabutton','searchterms','carttracking','seokeywords','pagebanners','emails','pageheader','emails-sending','emails-branding','emails-sent','emails-customer','emails-edit','siteapp','spotted','mkt-email','notfoundpage']);
const FRAME_PROBE=new Map();

/* One request per file per page load, shared by every later visit to the screen.
   A HEAD that the host refuses outright (405) is retried as a GET, so a server
   that only dislikes the method cannot be mistaken for a missing file. */
function frameExists(url){
  if(!FRAME_PROBE.has(url)){
    FRAME_PROBE.set(url,fetch(url,{method:'HEAD',credentials:'same-origin'})
      .then(r=>r.status===405?fetch(url,{credentials:'same-origin'}).then(r2=>r2.ok):r.ok)
      .catch(()=>false));
  }
  return FRAME_PROBE.get(url);
}

/* The 'isn't installed yet' card, in the shape renderPlaceholder already uses
   for p-content — one pattern for "not built", not a second one. */
/* What each unbuilt screen should actually say for itself.

   The owner hit this on Bulk Likes: the card said "isn't installed yet" and
   offered one button, Open Modules, and the Modules screen has no row for any
   of these. So the only action the card offered led somewhere that could not
   help, and the owner reported the Modules screen as broken — reasonably,
   because the card had just sent them there.

   A screen with nothing useful to say gets no button rather than a button to
   nowhere.

   The map is empty, and the mechanism is kept on purpose. It carried notes for
   Bulk Add and Bulk Likes while those were an open question; the owner has
   since decided to have them built, so a card saying they never would be is
   the wrong thing to show. The next screen that is deliberately not built gets
   its entry here rather than a button to a page that cannot help it. */
const NOT_BUILT_NOTE={};

function frameNotBuiltHTML(title,id){
  const note=NOT_BUILT_NOTE[id];
  if(note){
    return `<div class="wrap"><div class="ph">
      <div class="pic">${ic(I.modules)}</div>
      <h3>${escHtml(note.heading)}</h3>
      <p>${note.body}</p>
    </div></div>`;
  }
  return `<div class="wrap"><div class="ph">
    <div class="pic">${ic(I.modules)}</div>
    <h3>${escHtml(title)} isn't installed yet</h3>
    <p>This screen was drawn up as a standalone page that was never built, and no copy of it is installed on this server. Nothing is wrong with your store and nothing is missing from it — this one screen simply does not exist yet, and it is on the list.</p>
  </div></div>`;
}
/* Lane AM's wording for 'rev-all', reused verbatim so the two agree. */
function frameStartupHTML(title){
  /* The id is kbbNavClick's signal, exactly as #kbbDashWrap is. This card is
     painted only when mountFrame() had nothing else to draw with, which is the
     same "nothing claimed this screen" condition and wants the same replay.
     A constant, never a setting. */
  return `<div class="wrap" id="kbbFrameStartup"><p style="padding:24px;color:var(--ink-soft)">${escHtml(title)} could not be loaded — the admin script did not finish starting up. Reload the page.</p></div>`;
}

function mountFrame(id,src,title,query){
  const box=$('#content');
  if(LIVE_RENDERED.has(id)){box.innerHTML=frameStartupHTML(title);return;}
  const url=src+(query?('?'+query):'');
  /* Never empty, not even for the instant the probe is in flight, and true
     whichever way the probe lands. */
  box.innerHTML=`<div class="wrap"><p style="padding:24px;color:var(--ink-soft)">Checking for ${escHtml(title)}…</p></div>`;
  const giveUp=new Promise(r=>setTimeout(()=>r(false),4000));
  Promise.race([frameExists(url),giveUp]).then(ok=>{
    if(cur!==id)return;   // the owner moved on while we were asking
    box.innerHTML=ok
      ? `<iframe src="${escAttr(url)}" title="${escAttr(title)}" style="width:100%;height:calc(100vh - 116px);border:0;display:block;background:var(--bg)"></iframe>`
      : frameNotBuiltHTML(title,id);
  });
}

/* ---------- Store screens that load as standalone files (iframe) ---------- */
/* 'customers' is deliberately NOT in here any more — see the Lane T region.
   These entries load a standalone HTML file that this repo does not ship, so
   go('customers') from a bookmark used to render an iframe pointing at a 404
   before the live wiring replaced it. The Customers screen is rendered in this
   document now, so it does not need, and must not get, a frame. */
/* 'media' is deliberately NOT in here any more — see the Lane AX region at the
   foot of this file. Its entry named kbb-admin-media.html, a standalone file
   this repo does not ship, so go('media') drew the honest "isn't installed yet"
   card — which is the card the owner asked about. The Media Library is rendered
   in this document now by admin/partials/media-library-screen.blade.php, so it
   does not need, and must not get, a frame: leaving the entry here would let
   goTab('media') still fire a request for the missing file. */
const FRAME_SRC={'orders':'kbb-admin-orders.html','payments':'kbb-admin-payments.html','analytics':'kbb-admin-analytics.html','store-settings':'kbb-admin-settings.html','quiz-leads':'kbb-admin-quiz-leads.html','seo':'kbb-admin-seo.html','blog':'kbb-admin-blog.html','posts':'kbb-admin-blog.html','htmlblocks':'kbb-admin-blocks.html'};
function renderFrame(id,query){
  cur=id;
  const t=TITLES[id]||['Store',id];$('#crumb').textContent=t[0];$('#ptitle').textContent=t[1];
  $$('.side .nav-item').forEach(b=>b.classList.toggle('on',b.dataset.go===id));syncNavOpen(id);
  mountFrame(id,FRAME_SRC[id],t[1],query);
  $('#content').scrollTop=0;$('#side').classList.remove('open');
}
function goTab(id,tab){ if(FRAME_SRC[id])return renderFrame(id,'tab='+encodeURIComponent(tab)); return go(id); }
window.goTab=goTab;

/* ---------- Reviews module (screens load into the content area) ---------- */
const REV_SRC={'rev-all':'kbb-admin-reviews.html','rev-add':'kbb-admin-bulkadd.html','rev-likes':'kbb-admin-bulklikes.html','rev-assign':'kbb-admin-assign.html','rev-io':'kbb-admin-exportimport.html','rev-badge':'kbb-admin-badgethemes.html','rev-capsule':'kbb-capsule-editor.html','rev-settings':'kbb-admin-reviews-settings.html'};
function renderReviewFrame(id){
  cur=id;
  const t=TITLES[id]||['Reviews',id];$('#crumb').textContent=t[0];$('#ptitle').textContent=t[1];
  $$('.side .nav-item').forEach(b=>b.classList.toggle('on',b.dataset.go===id));syncNavOpen(id);
  /* ===== LANE AM — 'rev-all' is NOT a frame any more =====
     Every entry in REV_SRC names a standalone HTML file that THIS REPO DOES
     NOT SHIP, so each of these screens has always rendered an iframe pointing
     at a 404 — the same defect Lane T found on 'customers'. All Reviews is now
     rendered in this document by renderReviews() in the LANE AM region of the
     live-wiring script at the bottom of this file, so it must not get a frame:
     drawing one here would fire a request for a file that is not there and then
     be overwritten a moment later.

     What is left for it is an honest message, not a convincing one, for the
     case where the live-wiring script did not finish starting up. The other
     seven Reviews screens are still frames and still unbuilt; they are not this
     lane's to fix and they keep exactly the behaviour they had. ===== */
  if(id==='rev-all'){
    $('#content').innerHTML='<div class="wrap"><p style="padding:24px;color:var(--ink-soft)">Reviews could not be loaded — the admin script did not finish starting up. Reload the page.</p></div>';
    $('#content').scrollTop=0;$('#side').classList.remove('open');
    return;
  }
  mountFrame(id,REV_SRC[id],t[1]);
  $('#content').scrollTop=0;$('#side').classList.remove('open');
}

/* ===================== CATALOG ===================== */
const CAT_PRODUCTS=[
 ['Shark CryoGlow Under-Eye Cooling + LED Mask','Shark','SHK-CRYO','Beauty Devices',2450,null,8],
 ['Heartleaf 77% Soothing Toner','Anua','ANU-77T','Toners',89,null,142],
 ['Low pH Good Morning Gel Cleanser','COSRX','CSX-LPH','Cleansers',49,39,88],
 ['Advanced Snail 96 Mucin Power Essence','COSRX','CSX-S96','Essences',79,null,24],
 ['Glow Deep Serum Rice + Arbutin','Beauty of Joseon','BOJ-GLW','Serums',75,null,0],
 ['Relief Sun Rice + Probiotics SPF50+','Beauty of Joseon','BOJ-SPF','Sun Care',65,55,210],
 ['Dive-In Low Molecular HA Serum','Torriden','TOR-DHA','Serums',69,null,65],
 ['PDRN Pink Collagen Capsule Cream','Medicube','MED-PDRN','Moisturisers',129,99,12],
 ['No.5 Vitamin C Serum','Numbuzin','NMB-N5','Serums',99,null,47],
 ['AHA-BHA-PHA 30 Days Miracle Serum','Some By Mi','SBM-30D','Serums',89,null,5],
 ['Centella Ampoule','SKIN1004','SK1-CEN','Ampoules',79,null,91],
 ['Heartleaf Quercetinol Cleansing Oil','Anua','ANU-OIL','Cleansers',79,null,33],
 ['Collagen Night Wrapping Mask','Medicube','MED-NWM','Masks',99,null,18],
 ['Water-Fit Sun Serum SPF50+','SKIN1004','SK1-SUN','Sun Care',72,null,60],
 ['Hyaluronic Acid Watery Sun Gel','Isntree','ISN-SUN','Sun Care',79,null,0]
];
const CAT_DRAFT=new Set(['SK1-CEN','ISN-SUN']);
const CAT_CATEGORIES=[['Beauty Devices',11],['Cleansers',38],['Toners',26],['Essences',19],['Serums',64],['Ampoules',12],['Moisturisers',41],['Sun Care',23],['Masks',31],['Eye Care',9],['Sets & Bundles',15]];
const CAT_BRANDS=[['Anua',42],['COSRX',58],['Beauty of Joseon',37],['Medicube',29],['Torriden',18],['Numbuzin',21],['Some By Mi',24],['SKIN1004',16],['Isntree',14],['MEDIHEAL',26],['Round Lab',12],["Dr.Althea",9],['Shark',6]];
const PE_CATS=['Beauty Devices','Hair Care Silk','Hair Tools','Medicube','Makeup','Best Sellers','Under AED 54','Cleansers','Toners','Serums','Sun Care','Masks','Uncategorized'];
/* Still feeding the product editor's Variations panel (peBox, further down),
   which is a different preview and a different lane's to fix. The Attributes
   TAB no longer reads it — see the Lane N region. */
const CAT_ATTRS=[['Skin Type',['Dry','Oily','Combination','Sensitive','Normal']],['Concern',['Hydration','Brightening','Acne','Anti-aging','Soothing','Pores']],['Size',['30ml','50ml','100ml','150ml','200ml']],['Finish',['Dewy','Matte','Natural']]];
const TCOL=['#15a85a','#3f6fe0','#7b6cf0','#e0922f','#e0567b','#2bb3a3','#c13e63','#4b5a72'];
const tcol=s=>TCOL[[...s].reduce((a,c)=>a+c.charCodeAt(0),0)%TCOL.length];
const initials=s=>s.split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
const stockPill=n=>n===0?`<span class="pill red"><span class="d"></span>Out</span>`:n<=15?`<span class="pill amber"><span class="d"></span>Low · ${n}</span>`:`<span class="pill green"><span class="d"></span>${n}</span>`;

let catTab='products',catFilter='all',catSel=new Set();
let catSOopen=false,catPerPage=250;
/* Named once so go(id,sub) can validate a requested sub-tab against the same
   list the screen actually renders, rather than a second copy of it. */
const CAT_TABS=['products','categories','brands','attributes','inventory','reorder'];
function renderCatalog(){
  const tabs=CAT_TABS;
  const lbl={products:'Products',categories:'Categories',brands:'Brands',attributes:'Attributes',inventory:'Inventory',reorder:'Reorder'};
  $('#content').innerHTML=`<div class="wrap">
    <div class="between" style="margin-bottom:8px"><div class="page-head" style="margin:0"><h2>Catalog</h2><p>Your products and how they're organised. Reorder works inside any category.</p></div>
      <div class="row" style="gap:8px">${catTab==='products'?`<button class="btn" id="catAddProduct">${ic('<path d="M12 5v14M5 12h14"/>')} Add product</button>`:''}</div></div>
    <div class="subtabs">${tabs.map(t=>`<button class="subtab${t===catTab?' on':''}" data-t="${t}">${lbl[t]}</button>`).join('')}</div>
    <div id="catBody"></div></div>`;
  $$('#content .subtab').forEach(b=>b.onclick=()=>{catTab=b.dataset.t;renderCatalog();});
  /* "Add product" used to be an inline handler calling openProduct(-1), which
     reached the preview mock at the top of this file — a form whose every
     control was a toast and which never wrote anything. Lane AK then pointed it
     at a second, narrower create form, and Lane AT removed that form in favour
     of the one full editor. It now opens the product editor screen.

     Wired as a property rather than as an inline onclick because some sandboxed
     previews block inline on* entirely (see the CSP fallback at the foot of this
     file): a real listener works in both. */
  const addBtn=$('#content #catAddProduct');
  if(addBtn) addBtn.onclick=()=>{
    /* One create path: the editor's own create mode. Lane AT removed the
       narrower form that used to be the fallback here. */
    if(typeof window.peoNew==='function') { window.peoNew(); return; }
    if(typeof window.go==='function') { window.go('product-editor'); return; }
    toast('The product editor could not be loaded — reload the page.');
  };
  ({products:catProducts,categories:catCategories,brands:catBrands,attributes:catAttributes,inventory:catInventory,reorder:catReorder}[catTab])();
}
/* ===== Catalog → Products — the fallback only =====

   What used to be here was the whole Products tab: a list, a chip row and an
   Edit button whose entire implementation was a toast saying that product
   editing had not been built and that the list was real but editing was next.

   The real screen — sortable, filtered, inline-editable, with bulk actions and
   a CSV export — is window.catProducts in the LANE AF region of the
   live-wiring script at the bottom of this file. It has to live down there
   rather than here: api(), sesc(), fixAdminApiUrl() and cookie() are all
   declared inside that script's IIFE and are not reachable from this one.

   This declaration stays only so renderCatalog's dispatch table is never
   undefined, and what is left of it is an honest message rather than a
   convincing one. Same arrangement as window.catCategories and
   window.catAttributes below. ===== */
function catProducts(){
  var body=document.getElementById('catBody');
  if(body) body.innerHTML='<p style="padding:24px;color:var(--ink-soft)">Products could not be loaded.</p>';
}
function redirectsApiBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/redirects'; }
function schemaInspectApiBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/schema-inspect'; }
function catalogueAuditApiBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/catalogue-audit'; }
/* ===== LANE N · Catalog · Categories & Attributes fallbacks — BEGIN =====
   What used to sit here was the defect: eleven invented category rows out of
   CAT_CATEGORIES with slugs made up in JavaScript, and four invented
   attributes out of CAT_ATTRS — "Skin Type", "Concern", "Finish" — none of
   which exist in this database, behind buttons that raised a "(preview)"
   toast. Two screens that looked like they worked.

   The real ones are the window.catCategories / window.catAttributes
   assignments in the Lane N region of the live-wiring script at the bottom of
   this file, which replace these the same way window.catBrands replaces
   catBrands below. These declarations stay only so renderCatalog's dispatch
   table is never undefined, and what is left of them is an honest message —
   not a convincing one. ===== */
function catCategories(){ $('#catBody').innerHTML='<p style="padding:24px;color:var(--ink-soft)">Categories could not be loaded — the admin script did not finish starting up. Reload the page.</p>'; }
function catBrands(){
  $('#catBody').innerHTML=`<div class="between" style="margin-bottom:12px"><span class="pill grey">${CAT_BRANDS.length}+ brands</span><button class="btn sm" onclick="toast('Add brand (preview)')">${ic('<path d="M12 5v14M5 12h14"/>')} Add brand</button></div>
  <div class="mod-grid">${CAT_BRANDS.map(b=>`<div class="mod"><span class="pthumb" style="background:${tcol(b[0])};width:40px;height:40px">${initials(b[0])}</span><div><div class="mname">${b[0]}</div><div class="mdesc">${b[1]} products</div></div><div class="mod-r"><button class="btn ghost sm" onclick="toast('Edit brand (preview)')">Edit</button></div></div>`).join('')}</div>`;
}
function catAttributes(){ $('#catBody').innerHTML='<p style="padding:24px;color:var(--ink-soft)">Attributes could not be loaded — the admin script did not finish starting up. Reload the page.</p>'; }
/* ===== LANE N · Catalog · Categories & Attributes fallbacks — END ===== */
let invFilter='all',invSearch='',invDraft={};
const invStatus=q=>q===0?'out':q<=15?'low':'in';
const invDirty=()=>Object.keys(invDraft).filter(k=>invDraft[k]!==CAT_PRODUCTS[k][6]).length;
function invList(){let l=CAT_PRODUCTS.map((p,i)=>i);
  if(invSearch){const q=invSearch.toLowerCase();l=l.filter(i=>{const p=CAT_PRODUCTS[i];return p[0].toLowerCase().includes(q)||p[2].toLowerCase().includes(q)||p[1].toLowerCase().includes(q);});}
  if(invFilter!=='all')l=l.filter(i=>invStatus(invDraft[i]??CAT_PRODUCTS[i][6])===invFilter);
  return l;}
function renderInvSaveBar(){const bar=$('#invSaveBar');if(!bar)return;const n=invDirty();
  bar.innerHTML=n?`<div class="invsave"><span class="pill amber" style="font-size:11px"><span class="d"></span>${n}</span> unsaved change${n>1?'s':''} across the catalogue<div style="flex:1"></div><button class="btn ghost sm" onclick="invDiscard()">Discard</button><button class="btn" onclick="invSave()">${ic(I.check)} Save all changes</button></div>`:'';}
function invBulk(mode){const raw=$('#invSetVal').value;const v=parseInt(raw,10);if(isNaN(v)){toast('Enter a quantity first');return;}
  invList().slice(0,catPerPage).forEach(i=>{const base=invDraft[i]??CAT_PRODUCTS[i][6];invDraft[i]=mode==='set'?v:mode==='inc'?base+v:Math.max(0,base-v);});
  catInventory();toast('Applied to visible rows');}
function invSave(){Object.keys(invDraft).forEach(k=>{CAT_PRODUCTS[k][6]=invDraft[k];});const n=Object.keys(invDraft).length;invDraft={};catInventory();toast(`Saved ${n} product${n>1?'s':''} (preview)`);}
function invDiscard(){invDraft={};catInventory();toast('Changes discarded');}
function catInventory(){
  const low=CAT_PRODUCTS.filter(p=>p[6]>0&&p[6]<=15).length,out=CAT_PRODUCTS.filter(p=>p[6]===0).length;
  const list=invList(),page=list.slice(0,catPerPage);
  $('#catBody').innerHTML=`<p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:13px">Edit stock inline for any number of products, then commit everything with one <b>Save</b>. Built for large catalogues — search to narrow, bulk-set the visible rows, and only changed rows are sent to the server.</p>
  <div class="toolbar"><div class="search">${ic('<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/>')}<input id="invSearch" placeholder="Search by name, brand or SKU…" value="${invSearch}"></div>
    <div class="invbulk"><input class="inp" id="invSetVal" style="width:72px" placeholder="qty"><button class="btn ghost sm" onclick="invBulk('set')">Set visible</button><button class="btn ghost sm" onclick="invBulk('inc')">+ Add</button><button class="btn ghost sm" onclick="invBulk('dec')">− Sub</button></div></div>
  <div class="chips" style="margin-bottom:14px">${[['all','All'],['in','In stock'],['low','Low'],['out','Out']].map(c=>`<button class="chip${invFilter===c[0]?' on':''}" data-if="${c[0]}">${c[1]}</button>`).join('')}<span class="pill amber" style="margin-left:6px"><span class="d"></span>${low} low</span><span class="pill red"><span class="d"></span>${out} out</span></div>
  <div class="card" style="overflow:auto"><table><thead><tr><th>Product</th><th>SKU</th><th>Category</th><th>Current</th><th>New stock</th><th>Status</th></tr></thead><tbody>
  ${page.map(i=>{const p=CAT_PRODUCTS[i];const val=invDraft[i]??p[6];const dirty=invDraft[i]!==undefined&&invDraft[i]!==p[6];
    return `<tr class="${dirty?'invdirty':''}"><td><div class="row"><span class="pthumb" style="background:${tcol(p[1])};width:30px;height:30px;font-size:10px">${initials(p[1])}</span><b style="font-size:12.5px">${p[0]}</b></div></td>
    <td style="font-family:var(--mono);font-size:11px;color:var(--ink-soft)">${p[2]}</td><td>${p[3]}</td>
    <td style="color:var(--ink-soft)">${p[6]}</td>
    <td><input class="inp invq" data-i="${i}" style="width:84px;padding:6px 9px" value="${val}" inputmode="numeric"></td>
    <td class="invstat">${stockPill(val)}</td></tr>`;}).join('')}
  </tbody></table></div>
  <div class="pager"><span>Showing ${page.length} of ${list.length}${list.length>catPerPage?` · ${catPerPage}/page`:''}</span><button class="btn ghost sm" onclick="toast('Export stock CSV (preview)')">Export CSV</button></div>
  <div id="invSaveBar"></div>`;
  const s=$('#invSearch');s.oninput=e=>{const pos=e.target.selectionStart;invSearch=e.target.value;catInventory();const n=$('#invSearch');if(n){n.focus();n.setSelectionRange(pos,pos);}};
  $$('#catBody .chip[data-if]').forEach(c=>c.onclick=()=>{invFilter=c.dataset.if;catInventory();});
  $$('#catBody .invq').forEach(inp=>inp.oninput=()=>{const i=+inp.dataset.i;const v=inp.value===''?0:Math.max(0,parseInt(inp.value,10)||0);invDraft[i]=v;const tr=inp.closest('tr');tr.classList.toggle('invdirty',v!==CAT_PRODUCTS[i][6]);tr.querySelector('.invstat').innerHTML=stockPill(v);renderInvSaveBar();});
  renderInvSaveBar();
}
let reorderType='category',reorderScopes=null,reorderScopeId=null,reorderScopeName=null,reorderData=null,reorderPage=1,reorderSearch='',reorderLocal=null,reorderDirty=false,reorderPending=[],reorderSelected=new Set(),reorderBusy=false,reorderPerPage=+(localStorage.getItem('kbb_reorder_pp')||50),reorderPpCustomMode=false;
function reorderApiBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/catalog/reorder'; }
function reorderFmtMoney(n){ return n==null ? '' : 'AED '+(Math.round(n*100)/100).toLocaleString(); }

async function catReorder(){
  const body=$('#catBody');
  if(!reorderScopes){
    body.innerHTML=`<p style="padding:24px;color:var(--ink-soft)">Loading…</p>`;
    try{
      const r=await fetch(reorderApiBase()+'/scopes?type='+reorderType,{credentials:'same-origin',headers:{Accept:'application/json'}});
      reorderScopes=(await r.json()).scopes||[];
    }catch(e){ body.innerHTML=`<p style="padding:24px;color:var(--sale)">Could not load — ${escHtml(e.message)}</p>`; return; }
    if(!reorderScopes.length){ body.innerHTML=`<p style="padding:24px;color:var(--ink-soft)">Nothing with visible products yet.</p>`; return; }
    if(!reorderScopeId){
      const first=reorderScopes[0];
      reorderScopeId=first.id; reorderScopeName=first.name;
    }
  }
  await reorderLoadProducts();
}

/* LEAVING THIS PAGE OF THE LIST NO LONGER ASKS. (Lane PM)
   It used to be a confirm() -- "You have unsaved reorder changes on this page.
   Discard them?" -- on every page, scope, type and search change: the owner's
   "weired popup". The order he arranged is kept instead. It is written to
   Unfinished in the top bar (partials/unfinished-drafts.blade.php), where Open
   brings this exact page back with his order on it. The name is kept so the
   callers below read the same. */
function reorderConfirmDiscard(){
  if(reorderDirty && window.kbbDrafts) kbbDrafts.flush('reorder');
  return true;
}

function reorderScopeOptions(){
  if(reorderType==='brand') return reorderScopes.map(b=>`<option value="${b.id}"${b.id===reorderScopeId?' selected':''}>${escHtml(b.name)}</option>`).join('');
  const opts=[];
  reorderScopes.forEach(c=>{
    opts.push(`<option value="${c.id}"${c.id===reorderScopeId?' selected':''}>${escHtml(c.name)}</option>`);
    (c.children||[]).forEach(k=>opts.push(`<option value="${k.id}"${k.id===reorderScopeId?' selected':''}>&nbsp;&nbsp;&nbsp;&nbsp;↳ ${escHtml(k.name)}</option>`));
  });
  return opts.join('');
}

const REORDER_PP_PRESETS=[25,50,100,200];

async function reorderLoadProducts(){
  const body=$('#catBody');
  const listArea=$('#reListArea');
  if(listArea) listArea.innerHTML=`<p style="padding:24px;color:var(--ink-soft)">Loading…</p>`;
  else body.innerHTML=`<p style="padding:24px;color:var(--ink-soft)">Loading products…</p>`;
  try{
    const q=new URLSearchParams({page:reorderPage, search:reorderSearch, per_page:reorderPerPage});
    const r=await fetch(reorderApiBase()+'/'+reorderType+'/'+reorderScopeId+'/products?'+q,{credentials:'same-origin',headers:{Accept:'application/json'}});
    reorderData=await r.json();
  }catch(e){ body.innerHTML=`<p style="padding:24px;color:var(--sale)">Could not load products — ${escHtml(e.message)}</p>`; return; }
  reorderPerPage=reorderData.per_page;
  reorderLocal=reorderData.products.map(p=>({...p}));
  reorderDirty=false;
  reorderSelected.clear();
  reorderPaint();
  if(window.kbbDrafts) kbbDrafts.ready('reorder');
}

function reorderPaint(){
  const body=$('#catBody');
  const d=reorderData;
  const list=reorderLocal;
  const ppIsPreset=REORDER_PP_PRESETS.includes(reorderPerPage) && !reorderPpCustomMode;
  body.innerHTML=`
  <div class="toolbar" style="flex-wrap:wrap;gap:10px">
    <div class="row" style="gap:6px">
      <button class="chip${reorderType==='category'?' on':''}" id="reTypeCat">Categories</button>
      <button class="chip${reorderType==='brand'?' on':''}" id="reTypeBrand">Brands</button>
    </div>
    <div class="row" style="gap:8px"><span style="font-size:13px;font-weight:600">${reorderType==='brand'?'Brand':'Category'}</span>
      <select class="inp" id="reCat" style="min-width:190px">${reorderScopeOptions()}</select></div>
    <div class="search" style="min-width:180px"><input id="reSearch" placeholder="Find a product…" value="${escHtml(reorderSearch)}"></div>
    <div class="row" style="gap:6px"><span style="font-size:12.5px;color:var(--ink-soft)">Start from</span>
      <select class="inp" id="reAutoSort" style="width:140px">
        <option value="">Auto-sort…</option>
        <option value="bestselling">Best selling</option>
        <option value="newest">Newest</option>
        <option value="price">Price: low to high</option>
        <option value="name">Name A–Z</option>
      </select></div>
    <div style="flex:1"></div>
    <span style="font-size:12px;color:var(--ink-soft)">${d.total} products · page ${d.page} of ${d.last_page}</span>
  </div>
  <div class="toolbar" style="margin-top:2px">
    <span style="font-size:12.5px;color:var(--ink-soft)">Show</span>
    <select class="inp" id="rePerPage" style="width:100px">
      ${REORDER_PP_PRESETS.map(n=>`<option value="${n}"${n===reorderPerPage&&ppIsPreset?' selected':''}>${n} per page</option>`).join('')}
      <option value="custom"${ppIsPreset?'':' selected'}>Custom…</option>
    </select>
    ${ppIsPreset?'':`<input class="inp" id="rePerPageCustom" type="number" min="10" max="500" value="${reorderPerPage}" style="width:80px" placeholder="10–500">`}
    <span style="font-size:11px;color:var(--ink-soft)">10–500</span>
  </div>
  <p style="font-size:12px;color:var(--ink-soft);margin:6px 0 12px">
    This ${reorderType}'s own order — the order its page on the shop shows. Every category and every brand keeps its own: saving this one never moves another, even where they share products. A product added to it later joins at the end.
  </p>
  <div id="reBulkBar" style="${reorderSelected.size?'':'display:none'};background:var(--pink-soft,#fff0f4);border:1px solid var(--accent,#E0567B);border-radius:10px;padding:8px 14px;margin-bottom:10px;display:flex;align-items:center;gap:12px">
    <span style="font-size:12.5px;font-weight:600">${reorderSelected.size} selected</span>
    <button class="btn ghost sm" id="reBulkTop">Move to top of this page</button>
    <button class="btn ghost sm" id="reBulkClear">Clear selection</button>
  </div>
  <div id="reListArea">${reorderRenderList(list)}</div>
  <div class="pager" style="margin-top:14px">
    <span>Showing ${list.length ? ((d.page-1)*d.per_page+1) : 0}–${(d.page-1)*d.per_page+list.length} of ${d.total}</span>
    <div class="row" style="gap:8px;align-items:center">
      <button class="btn ghost sm" style="white-space:nowrap" ${d.page<=1?'disabled':''} id="rePrev">‹ Prev</button>
      <button class="btn ghost sm" style="white-space:nowrap" ${d.page>=d.last_page?'disabled':''} id="reNext">Next ›</button>
    </div>
  </div>
  ${reorderSaveBar()}`;

  $('#reTypeCat').onclick=()=>{ if(reorderType==='category'||!reorderConfirmDiscard()||!reorderDropPending())return; reorderType='category'; reorderScopes=null; reorderScopeId=null; reorderPage=1; reorderSearch=''; catReorder(); };
  $('#reTypeBrand').onclick=()=>{ if(reorderType==='brand'||!reorderConfirmDiscard()||!reorderDropPending())return; reorderType='brand'; reorderScopes=null; reorderScopeId=null; reorderPage=1; reorderSearch=''; catReorder(); };
  $('#reCat').onchange=e=>{ if(!reorderConfirmDiscard()||!reorderDropPending()){e.target.value=reorderScopeId;return;} reorderScopeId=+e.target.value;const opt=e.target.selectedOptions[0];reorderScopeName=opt.textContent.replace(/^[\s↳]+/,'');reorderPage=1;reorderSearch='';reorderLoadProducts(); };
  let searchT;$('#reSearch').oninput=e=>{ if(!reorderConfirmDiscard()){e.target.value=reorderSearch;return;} clearTimeout(searchT);const v=e.target.value;searchT=setTimeout(()=>{reorderSearch=v;reorderPage=1;reorderLoadProducts();},300); };
  $('#reAutoSort').onchange=async e=>{
    const by=e.target.value; if(!by) return;
    if(!confirm(`Re-sort the whole ${reorderType} (${d.total} products) by ${e.target.selectedOptions[0].textContent}? This saves immediately and can't be undone by a Save button — you can still fine-tune afterward.`)){e.target.value='';return;}
    reorderBusy=true;
    const r=await fetch(reorderApiBase()+'/'+reorderType+'/'+reorderScopeId+'/auto-sort',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},body:JSON.stringify({by})});
    const j=await r.json();
    reorderBusy=false;
    if(j.ok){toast(`Sorted ${j.sorted} products — fine-tune below`);reorderPage=1;reorderLoadProducts();}
    else{toast('Could not auto-sort.', 'bad');}
  };
  $('#rePerPage').onchange=e=>{
    if(!reorderConfirmDiscard()){ reorderPaint(); return; }
    if(e.target.value==='custom'){ reorderPpCustomMode=true; reorderPaint(); setTimeout(()=>$('#rePerPageCustom')?.focus(),0); return; }
    reorderPpCustomMode=false; reorderPerPage=+e.target.value; localStorage.setItem('kbb_reorder_pp', reorderPerPage); reorderPage=1; reorderLoadProducts();
  };
  const ppCustom=$('#rePerPageCustom');
  if(ppCustom){
    const applyCustom=()=>{
      let v=parseInt(ppCustom.value,10);
      if(!v || v<10) v=10; if(v>500) v=500;
      reorderPerPage=v; localStorage.setItem('kbb_reorder_pp', v); reorderPage=1;
      if(!reorderConfirmDiscard()) return;
      reorderLoadProducts();
    };
    ppCustom.onkeydown=e=>{ if(e.key==='Enter'){ applyCustom(); } };
    ppCustom.onblur=applyCustom;
  }
  $('#rePrev').onclick=()=>{ if(d.page>1 && reorderConfirmDiscard()){reorderPage--;reorderLoadProducts();} };
  $('#reNext').onclick=()=>{ if(d.page<d.last_page && reorderConfirmDiscard()){reorderPage++;reorderLoadProducts();} };
  const bulkTop=$('#reBulkTop'); if(bulkTop) bulkTop.onclick=reorderBulkTopOfPage;
  const bulkClear=$('#reBulkClear'); if(bulkClear) bulkClear.onclick=()=>{reorderSelected.clear();reorderPaint();};
  $('#reSave').onclick=reorderSave;
  const disc=$('#reDiscard'); if(disc) disc.onclick=reorderDiscard;
  $$('[data-rpend]').forEach(b=>b.onclick=()=>{ reorderPending=reorderPending.filter(m=>m.id!==+b.dataset.rpend); reorderPaint(); });
  reorderWireList();
}

/* THE SAVE BAR (2.60.402). The owner: "when re-order done, there must be SAVE
   button. should not apply the order/sorting directly." Save sat under the
   whole list and its pager -- below fifty rows -- so an order could be set
   and never saved, and the shop kept the old one. The bar now stays pinned to
   the bottom of the window while anything is unsaved, and a number typed for
   a place on ANOTHER page waits for Save too (it used to save at once). */
function reorderSaveBar(){
  const n=reorderPending.length, any=reorderDirty||n>0;
  const pend=n?`<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">${reorderPending.map(m=>`<span style="font-size:12px;background:var(--surface-2,#f6f6f8);border:1px solid var(--border);border-radius:99px;padding:3px 4px 3px 10px;display:inline-flex;align-items:center;gap:6px">${escHtml(m.name)} → #${m.to+1}<button class="btn ghost sm" data-rpend="${m.id}" aria-label="Cancel this move" style="padding:0 6px;min-height:0">×</button></span>`).join('')}</div>`:'';
  return `<div class="re-savebar" id="reSaveBar" style="position:sticky;bottom:0;z-index:5;margin-top:18px;padding:12px 14px;background:var(--surface,#fff);border:1px solid ${any?'var(--accent,#E0567B)':'var(--border)'};border-radius:12px;box-shadow:${any?'0 -6px 20px -12px rgba(0,0,0,.25)':'none'}">
    <div class="between" style="gap:10px;flex-wrap:wrap">
      <span style="font-size:13px;font-weight:600;color:${any?'var(--sale)':'var(--ink-soft)'}">${any?(reorderDirty?'Unsaved order on this page':'')+(reorderDirty&&n?' · ':'')+(n?n+' move'+(n===1?'':'s')+' to other pages':'')+' — the shop changes only when you press Save':'Saved — the shop shows this order'}</span>
      <div class="row" style="gap:8px"><button class="btn ghost" id="reDiscard" ${any?'':'disabled'}>Discard</button><button class="btn primary" id="reSave" ${any?'':'disabled'}>Save order</button></div>
    </div>${pend}</div>`;
}

/* Moves waiting for Save belong to one category or brand: switching away asks first. */
function reorderDropPending(){
  if(reorderPending.length && !confirm(reorderPending.length+' move'+(reorderPending.length===1?' is':'s are')+' waiting for Save. Discard '+(reorderPending.length===1?'it':'them')+'?')) return false;
  reorderPending=[];
  return true;
}

function reorderDiscard(){
  if(reorderBusy) return;
  reorderPending=[];
  reorderLoadProducts();
}

function reorderRenderList(list){
  if(!list.length) return reorderSearch
    ? `<p style="padding:24px;color:var(--ink-soft)">No products match "${escHtml(reorderSearch)}".</p>`
    : `<p style="padding:24px;color:var(--ink-soft)">No visible products here.</p>`;
  return `<div class="rlist" id="rlist">${list.map((p,i)=>`
    <div class="ritem" draggable="true" data-i="${i}" data-id="${p.id}">
      <span class="cbx${reorderSelected.has(p.id)?' on':''}" data-rsel="${p.id}">${ic(I.check)}</span>
      <span class="grip">${ic('<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>')}</span>
      <input class="inp" id="rerank-${p.id}" value="${(reorderData.page-1)*reorderData.per_page+i+1}" style="width:50px;text-align:center;padding:5px 4px;font-size:12px" data-rankinput="${p.id}">
      <span class="pthumb" style="background:${tcol(p.brand||p.name)};width:32px;height:32px;font-size:10px">${initials(p.brand||p.name)}</span>
      <div style="flex:1;min-width:0"><div class="pname" style="font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(p.name)}</div><div class="pbrand" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(p.brand||'')}${p.sku?' · '+escHtml(p.sku):''}</div></div>
      <div style="text-align:right;flex:0 0 88px">
        ${p.sale_price!=null
          ? `<div style="font-size:12.5px;font-weight:700;color:var(--sale)">${reorderFmtMoney(p.sale_price)}</div><div style="font-size:10.5px;color:var(--ink-soft);text-decoration:line-through">${reorderFmtMoney(p.price)}</div>`
          : `<div style="font-size:12.5px;font-weight:700">${reorderFmtMoney(p.price)}</div>`}
      </div>
      <div style="text-align:right;flex:0 0 76px;font-size:11px;color:var(--ink-soft)" title="Distinct orders this product has appeared in">${p.orders_count} order${p.orders_count===1?'':'s'}</div>
      <div class="mv" style="flex:0 0 auto;flex-direction:row;gap:4px">
        <button data-rup="${p.id}" title="One place up" aria-label="Move ${escHtml(p.name)} up one place">${ic('<path d="M12 19V5M5 12l7-7 7 7"/>')}</button>
        <button data-rdown="${p.id}" title="One place down" aria-label="Move ${escHtml(p.name)} down one place">${ic('<path d="M12 5v14M5 12l7 7 7-7"/>')}</button>
        <button data-rfirst="${p.id}" class="re-jump" title="To the top of the whole list" aria-label="Move ${escHtml(p.name)} to the top of the whole list">${ic('<path d="M5 4h14M12 20V9M6 15l6-6 6 6"/>')}</button>
        <button data-rlast="${p.id}" class="re-jump" title="To the bottom of the whole list" aria-label="Move ${escHtml(p.name)} to the bottom of the whole list">${ic('<path d="M5 20h14M12 4v11M6 9l6 6 6-6"/>')}</button>
      </div>
    </div>`).join('')}</div>`;
}

function reorderWireList(){
  $$('#rlist .cbx[data-rsel]').forEach(c=>c.onclick=()=>{
    const id=+c.dataset.rsel;
    reorderSelected.has(id)?reorderSelected.delete(id):reorderSelected.add(id);
    reorderPaint();
  });
  $$('#rlist [data-rankinput]').forEach(inp=>{
    inp.onkeydown=e=>{ if(e.key==='Enter'){ e.target.blur(); } };
    inp.onblur=e=>{
      const id=+e.target.dataset.rankinput;
      const rank=parseInt(e.target.value,10);
      if(!rank || rank<1){ e.target.value=e.target.defaultValue; return; }
      reorderJumpToRank(id, rank);
    };
  });
  /* Four arrows (2.60.404). The owner: "should be 4, 2 grey and 2 red, the
     red arrows will jump to top or bottom of the whole list. and grey will
     work as one row down or up." Grey: one place, across a page edge too.
     Red: rank 1 / the last rank of the WHOLE list. All of them wait for Save,
     through the same paths as the number box (reorderJumpToRank queues a
     move that lands on another page). */
  const pageStart=()=>(reorderData.page-1)*reorderData.per_page+1;
  const step=(id,d)=>{ const i=reorderLocal.findIndex(x=>x.id===id); if(i<0) return; const to=i+d;
    if(to>=0 && to<reorderLocal.length) reorderLocalMove(id,to);
    else { const rank=pageStart()+to; if(rank>=1 && rank<=reorderData.total) reorderJumpToRank(id,rank); } };
  $$('#rlist [data-rup]').forEach(b=>b.onclick=()=>step(+b.dataset.rup,-1));
  $$('#rlist [data-rdown]').forEach(b=>b.onclick=()=>step(+b.dataset.rdown,1));
  $$('#rlist [data-rfirst]').forEach(b=>b.onclick=()=>reorderJumpToRank(+b.dataset.rfirst,1));
  $$('#rlist [data-rlast]').forEach(b=>b.onclick=()=>reorderJumpToRank(+b.dataset.rlast,reorderData.total));
  let dragI=null;
  $$('#rlist .ritem').forEach(it=>{
    it.ondragstart=e=>{ if(e.target.closest('[data-rankinput],[data-rsel]')){e.preventDefault();return;} dragI=+it.dataset.i; };
    it.ondragover=e=>e.preventDefault();
    it.ondrop=()=>{
      const to=+it.dataset.i;
      if(dragI===null||dragI===to) return;
      const i=dragI; dragI=null;
      reorderLocalMove(reorderLocal[i].id, to);
    };
  });
}

function reorderLocalMove(productId, toIndex){
  const from=reorderLocal.findIndex(p=>p.id===productId);
  if(from===-1) return;
  const [moved]=reorderLocal.splice(from,1);
  reorderLocal.splice(Math.min(toIndex,reorderLocal.length),0,moved);
  reorderDirty=true;
  reorderPaint();
}

function reorderBulkTopOfPage(){
  if(!reorderSelected.size) return;
  const selected=reorderLocal.filter(p=>reorderSelected.has(p.id));
  const rest=reorderLocal.filter(p=>!reorderSelected.has(p.id));
  reorderLocal=[...selected,...rest];
  reorderDirty=true;
  reorderPaint();
}

async function reorderJumpToRank(productId, rank){
  const pageStart=(reorderData.page-1)*reorderData.per_page+1;
  const pageEnd=pageStart+reorderLocal.length-1;
  if(rank>=pageStart && rank<=pageEnd){
    reorderLocalMove(productId, rank-pageStart);
    return;
  }
  // Another page: queued, applied by Save (2.60.402) -- never on its own.
  const p=reorderLocal.find(x=>x.id===productId);
  reorderPending=reorderPending.filter(m=>m.id!==productId).concat([{id:productId, name:p?p.name:('#'+productId), to:Math.max(0,rank-1)}]);
  toast('Will move to #'+rank+' when you press Save');
  reorderPaint();
}

async function reorderSave(){
  if(reorderBusy || (!reorderDirty && !reorderPending.length)) return;
  reorderBusy=true;
  const btn=$('#reSave'); if(btn){btn.disabled=true;btn.textContent='Saving…';}
  const post=(path,body)=>fetch(reorderApiBase()+'/'+reorderType+'/'+reorderScopeId+path,{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},body:JSON.stringify(body)}).then(r=>r.json());
  try{
    // This page's order first, then each move to another page, in the order he made them.
    if(reorderDirty){
      const j=await post('/save-page',{page:reorderData.page, per_page:reorderData.per_page, product_ids:reorderLocal.map(p=>p.id)});
      if(!j.ok){ toast(j.message||'Could not save — reload and try again.', 'bad'); reorderBusy=false; reorderPaint(); return; }
      reorderDirty=false;
    }
    while(reorderPending.length){
      const m=reorderPending[0];
      const j=await post('/move',{product_id:m.id, to:m.to});
      if(!j.ok){ toast(j.message||('Could not move '+m.name+'.'), 'bad'); break; }
      reorderPending.shift();
    }
    if(!reorderPending.length){ toast('Saved — the shop now shows this order'); if(window.kbbDrafts) kbbDrafts.saved('reorder'); }
    reorderBusy=false;
    await reorderLoadProducts();
    return;
  }catch(e){ toast('Could not save — check your connection.','bad'); }
  reorderBusy=false;
  reorderPaint();
}
let pdTab='general',reyTab='misc',yoastTab='seo',pageTab='general',peCtx={};
const ICO={img:'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',ring:'<circle cx="12" cy="12" r="9"/>'};
const peBox=(title,body,open=true)=>`<div class="card pe-box${open?'':' col'}"><div class="pe-bh" onclick="this.parentElement.classList.toggle('col')">${title}<span class="chev">${ic('<path d="m6 9 6 6 6-6"/>')}</span></div><div class="pe-bb">${body}</div></div>`;
function wireCbx(s){$$(s+' .cbx:not([data-sel])').forEach(c=>c.onclick=()=>c.classList.toggle('on'));}
function wireChips(s){$$(s+' .tagchip').forEach(c=>c.onclick=()=>c.classList.toggle('on'));}
function wireMini(s){$$(s+' .minitabs').forEach(g=>$$('button',g).forEach(btn=>btn.onclick=()=>{$$('button',g).forEach(x=>x.classList.remove('on'));btn.classList.add('on');}));}
function descSample(n,b,cat){return `Introducing ${n}. ${b} brings clinically-tested Korean skincare technology, lightweight and formulated for the UAE climate.\n\nHow to Use\nStart with clean, dry skin. Apply an even layer and follow with the rest of your routine. Use morning and night for best results.\n\nGet the Perfect Fit\nResults may vary by skin type. Patch test before first use.`;}

function peProductData(){
  return `<div class="card pe-box">
    <div class="pe-pdhead"><b>Product data —</b><select class="inp"><option>Simple product</option><option>Variable product</option><option>Grouped product</option><option>External / Affiliate</option></select>
      <label class="pdchk"><span class="cbx">${ic(I.check)}</span> Virtual</label><label class="pdchk"><span class="cbx">${ic(I.check)}</span> Downloadable</label></div>
    <div class="pd-wrap"><div class="pd-tabs">${[['general','General'],['inventory','Inventory'],['shipping','Shipping'],['linked','Linked Products'],['attributes','Attributes'],['variations','Variations'],['advanced','Advanced'],['labels','Advanced label'],['facebook','Facebook']].map(t=>`<button class="pd-tab${t[0]===pdTab?' on':''}" data-pd="${t[0]}">${t[1]}</button>`).join('')}</div>
      <div class="pd-body" id="pdBody"></div></div></div>`;
}
function renderPDBody(){
  const b=$('#pdBody');if(!b)return;const{price,sale,sku,stock}=peCtx;let h='';
  if(pdTab==='general'){
    h=`<div class="g2"><div class="fld"><label>Regular price (AED)</label><input value="${price}"></div><div class="fld"><label>Sale price (AED)</label><input value="${sale||''}" placeholder="optional"></div></div>
    <div class="fld"><button class="lk" style="font-size:12px;color:var(--accent-ink);font-weight:600;background:none" onclick="toast('Schedule sale dates (preview)')">Schedule sale dates</button></div>
    <div class="g2"><div class="fld"><label>Tax status</label><select><option>Taxable</option><option>Shipping only</option><option>None</option></select></div><div class="fld"><label>Tax class</label><select><option>Standard (5% VAT)</option><option>Zero rate</option></select></div></div>`;
  } else if(pdTab==='inventory'){
    h=`<div class="g2"><div class="fld"><label>SKU</label><input value="${sku}"></div><div class="fld"><label>Stock status</label><select><option${stock>0?' selected':''}>In stock</option><option${stock===0?' selected':''}>Out of stock</option><option>On backorder</option></select></div></div>
    <div class="fld"><label class="row" style="gap:8px;font-weight:600;font-size:12.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Manage stock at product level</label></div>
    <div class="g2"><div class="fld"><label>Stock quantity</label><input value="${stock}"></div><div class="fld"><label>Low-stock threshold</label><input value="15"></div></div>
    <div class="g2"><div class="fld"><label>Backorders</label><select><option>Do not allow</option><option>Allow</option><option>Allow, notify</option></select></div><div class="fld"><label>Restrictions</label><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer;padding-top:9px"><span class="cbx">${ic(I.check)}</span> Sold individually</label></div></div>`;
  } else if(pdTab==='shipping'){
    h=`<div class="fld"><label>Weight (kg)</label><input placeholder="0.0"></div><div class="g2" style="grid-template-columns:1fr 1fr 1fr"><div class="fld"><label>Length</label><input></div><div class="fld"><label>Width</label><input></div><div class="fld"><label>Height</label><input></div></div><div class="fld" style="margin:0"><label>Shipping class</label><select><option>No class</option><option>Bulky</option><option>Fragile</option></select></div>`;
  } else if(pdTab==='linked'){
    h=`<div class="fld"><label>Upsells</label><input placeholder="Search products to upsell…"></div><div class="fld"><label>Cross-sells</label><input placeholder="Search products to cross-sell…"></div><p style="font-size:11.5px;color:var(--ink-soft)">Upsells show on the product page; cross-sells show in the cart.</p>`;
  } else if(pdTab==='attributes'){
    h=`<div style="display:flex;flex-direction:column;gap:10px">${CAT_ATTRS.slice(0,2).map(a=>`<div class="card" style="box-shadow:none;padding:12px"><div class="between"><b style="font-size:12.5px">${a[0]}</b><label class="row" style="gap:7px;font-size:11.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Used for variations</label></div><div class="tagchips" style="margin-top:9px">${a[1].map(t=>`<span class="tagchip on">${t}</span>`).join('')}</div></div>`).join('')}</div><button class="btn ghost sm" style="margin-top:12px" onclick="toast('Add attribute (preview)')">+ Add attribute</button>`;
  } else if(pdTab==='variations'){
    h=`<p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:12px">Generated from attributes marked “used for variations”.</p>${['30ml','50ml','100ml'].map((sz,i)=>`<div class="ritem" style="margin-bottom:8px"><b style="font-size:12px;width:54px">${sz}</b><div class="fld" style="margin:0;flex:1"><input value="${price?(+price+i*20):''}" placeholder="Price"></div><div class="fld" style="margin:0;width:84px"><input value="${stock}" placeholder="Stock"></div><button class="btn ghost sm" onclick="toast('Edit variation (preview)')">Edit</button></div>`).join('')}<button class="btn ghost sm" onclick="toast('Generate variations (preview)')">Generate variations</button>`;
  } else if(pdTab==='labels'){
    h=`<p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px">Global labels that match this product appear automatically. Force-add, skip, or add a one-off label just for this product.</p>
    <div class="dsec" style="margin-top:0">Auto-applied here</div>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px">${[['Eid Mega Sale','#1b9e77','🌙'],['Up to 30% Off','#d6455a','%']].map(l=>`<div class="ritem"><span class="lbl-card-thumb" style="width:30px;height:30px;font-size:12px;background:${l[1]}">${l[2]}</span><b style="font-size:12.5px">${l[0]}</b><span class="pill grey" style="margin-left:8px">auto</span><div style="flex:1"></div><label class="row" style="gap:7px;font-size:11.5px;cursor:pointer"><span class="cbx">${ic(I.check)}</span> Skip for this product</label></div>`).join('')}</div>
    <div class="dsec">Force-add a label</div>
    <div class="tagchips" style="margin-bottom:16px">${LABELS.map(l=>`<span class="tagchip">${l.ic} ${l.name}</span>`).join('')}</div>
    <button class="btn ghost sm" onclick="go('labels')">Manage all labels →</button>`;
  } else if(pdTab==='facebook'){
    h=`<label class="row" style="gap:8px;font-size:12.5px;cursor:pointer;margin-bottom:14px"><span class="cbx on">${ic(I.check)}</span> Sync this product to the Meta catalog</label>
    <div class="fld"><label>Facebook title (override)</label><input placeholder="Defaults to product name"></div>
    <div class="fld"><label>Facebook description (override)</label><textarea placeholder="Defaults to the short description…"></textarea></div>
    <div class="fld"><label>Catalog image (override)</label><div class="imgdrop">${ic(ICO.img)}<div style="margin-top:6px">Defaults to the featured image</div></div></div>
    <div class="g2"><div class="fld"><label>Google product category</label><input placeholder="e.g. Health & Beauty > Skin Care"></div><div class="fld"><label>Condition</label><select><option>New</option><option>Refurbished</option><option>Used</option></select></div></div>
    <div class="g2"><div class="fld"><label>Brand</label><input value="${peCtx.b||''}"></div><div class="fld"><label>GTIN / MPN</label><input placeholder="barcode or MPN"></div></div>
    <button class="pe-link" onclick="go('meta')">Open Meta &amp; Facebook settings →</button>`;
  } else {
    h=`<div class="fld"><label>Purchase note</label><textarea placeholder="Note emailed to the customer after purchase…"></textarea></div><div class="g2"><div class="fld"><label>Menu order</label><input value="0"></div><div class="fld"><label>Reviews</label><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer;padding-top:9px"><span class="cbx on">${ic(I.check)}</span> Enable reviews</label></div></div>`;
  }
  b.innerHTML=h;wireCbx('#pdBody');wireChips('#pdBody');
}
/* ---- product editor: Custom Tabs manager (fills CUSTOM_TABS on the PDP) ---- */
let PE_TABS=[
 {id:1,title:'How to use',scope:'product',enabled:true,content:'<p>Apply as the last step of your morning routine over face and neck. Reapply every 2 hours of sun exposure.</p>'},
 {id:2,title:'Shipping & returns',scope:'all',enabled:true,content:'<ul><li>Free UAE delivery over AED 100</li><li>Same-day dispatch before 4pm</li><li>Tabby, Tamara, card, Apple Pay or COD</li><li>14-day easy returns on unopened items</li></ul>'}
];
let peTabSeq=2, peTabEditing=null;
function peEsc(s){return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function peTabsBody(){
  const rows = PE_TABS.length ? PE_TABS.map((t,i)=>{
    const ed = peTabEditing===t.id;
    return `<div class="ritem" style="flex-wrap:wrap;${ed?'background:var(--surface-2)':''}">
      <b style="font-size:12.5px;flex:1;min-width:120px">${peEsc(t.title)||'Untitled tab'}</b>
      <span class="pill ${t.scope==='all'?'grey':'green'}">${t.scope==='all'?'All products':'This product'}</span>
      <button class="btn ghost sm" ${i===0?'disabled':''} onclick="peTabMove(${t.id},-1)">↑</button>
      <button class="btn ghost sm" ${i===PE_TABS.length-1?'disabled':''} onclick="peTabMove(${t.id},1)">↓</button>
      <button class="btn ghost sm" onclick="peTabToggle(${t.id})">${t.enabled?'On':'Off'}</button>
      <button class="btn ghost sm" onclick="peTabEdit(${t.id})">Edit</button>
      <button class="btn ghost sm" style="color:#b32d2e" onclick="peTabDel(${t.id})">Delete</button>
      ${ed?`<div style="flex-basis:100%;margin-top:10px">
        <div class="g2"><div class="fld"><label>Tab title</label><input id="peTabTitle" value="${peEsc(t.title)}" placeholder="e.g. How to use"></div>
        <div class="fld"><label>Show on</label><select id="peTabScope"><option value="product"${t.scope==='product'?' selected':''}>This product</option><option value="all"${t.scope==='all'?' selected':''}>All products (global)</option></select></div></div>
        <div class="fld" style="margin:0"><label>Content <span style="font-weight:400;color:var(--ink-soft)">— basic HTML ok</span></label><textarea id="peTabContent" rows="4">${peEsc(t.content)}</textarea></div>
        <div class="row" style="gap:8px;margin-top:10px"><button class="btn ghost sm" style="background:var(--accent);color:#fff;border-color:var(--accent)" onclick="peTabSaveRow(${t.id})">Save tab</button><button class="btn ghost sm" onclick="peTabCancel()">Cancel</button></div>
      </div>`:''}
    </div>`;
  }).join('') : `<p style="font-size:12px;color:var(--ink-soft)">No custom tabs yet — add one below.</p>`;
  return `<p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:12px">Description &amp; Ingredients are built in. Custom tabs appear after them on the product page. Set each to this product, or all products.</p>
   <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">${rows}</div>
   <button class="btn ghost sm" onclick="peTabAdd()">+ Add a custom tab</button>`;
}
function peTabMove(id,d){const i=PE_TABS.findIndex(t=>t.id===id),j=i+d;if(j<0||j>=PE_TABS.length)return;const x=PE_TABS.splice(i,1)[0];PE_TABS.splice(j,0,x);renderReyBody();}
function peTabToggle(id){const t=PE_TABS.find(t=>t.id===id);t.enabled=!t.enabled;renderReyBody();}
function peTabEdit(id){peTabEditing=(peTabEditing===id?null:id);renderReyBody();}
function peTabCancel(){peTabEditing=null;renderReyBody();}
function peTabDel(id){if(!confirm('Delete this tab?'))return;PE_TABS=PE_TABS.filter(t=>t.id!==id);if(peTabEditing===id)peTabEditing=null;renderReyBody();toast('Tab deleted');}
function peTabAdd(){const t={id:++peTabSeq,title:'New tab',scope:'product',enabled:true,content:'<p>Tab content…</p>'};PE_TABS.push(t);peTabEditing=t.id;renderReyBody();}
function peTabSaveRow(id){const t=PE_TABS.find(t=>t.id===id);if(!t)return;t.title=($('#peTabTitle').value||'').trim()||'Untitled tab';t.scope=$('#peTabScope').value;t.content=$('#peTabContent').value;peTabEditing=null;renderReyBody();toast('Tab saved');}
function reyPanel(){
  const tabs=[['misc','Misc.'],['cart','Add to cart'],['delivery','Estimated Delivery'],['threed','360 Image'],['badge','Badge'],['qty','Quantity Min/Max'],['catalog','Catalog Display'],['video','Video'],['tabs','Custom Tabs/Blocks'],['global','Global Sections']];
  return peBox('Product settings · KBB theme',`<div class="htabs">${tabs.map(t=>`<button class="htab${t[0]===reyTab?' on':''}" data-rey="${t[0]}">${t[1]}</button>`).join('')}</div><div id="reyBody"></div>`);
}
function renderReyBody(){
  const b=$('#reyBody');if(!b)return;let h='';
  if(reyTab==='misc'){
    h=`<div class="fld"><label>Product Info Block/Tab</label><select><option>— Select —</option><option>Ingredients</option><option>How to use</option><option>Shipping &amp; returns</option></select><p style="font-size:11px;color:var(--ink-soft);margin-top:5px">Shown after the product summary. Pre-defined in Customizer › Product Page.</p></div>
    <div class="fld"><label>Specifications Block/Tab</label><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Show specifications block</label></div>
    <div class="fld"><label>Evergreen Sale</label><div class="g2" style="grid-template-columns:1fr 1fr 1fr"><input placeholder="Duration (days)"><input placeholder="Starting from"><input placeholder="Repeat count"></div><p style="font-size:11px;color:var(--ink-soft);margin-top:5px">Permanent sale badge / countdown, regardless of the scheduled sale.</p></div>
    <div class="fld" style="margin:0"><label>“Buy Now” button</label><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx">${ic(I.check)}</span> Show a Buy Now button for this product</label></div>`;
  } else if(reyTab==='cart'){
    h=`<div class="fld"><label>Add-to-cart action</label><select><option>Default</option><option>Open slide-out cart</option><option>Go to checkout</option><option>Stay on page (AJAX)</option></select></div><div class="fld" style="margin:0"><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Enable sticky add-to-cart bar</label></div>`;
  } else if(reyTab==='delivery'){
    h=`<div class="g2"><div class="fld"><label>Min. days</label><input value="1"></div><div class="fld"><label>Max. days</label><input value="3"></div></div><div class="fld" style="margin:0"><label>Delivery note</label><input placeholder="e.g. Order before 4pm for same-day dispatch"></div>`;
  } else if(reyTab==='threed'){
    h=`<div class="imgdrop">${ic(ICO.img)}<div style="margin-top:6px">Upload a 360° image sequence (.zip)</div></div>`;
  } else if(reyTab==='badge'){
    h=`<div class="fld"><label>Custom badge text</label><input placeholder="e.g. MedSpa at home"></div><div class="fld" style="margin:0"><label>Badge style</label><select><option>Solid accent</option><option>Outline</option><option>Gradient</option></select></div>`;
  } else if(reyTab==='qty'){
    h=`<div class="g2"><div class="fld"><label>Minimum qty</label><input value="1"></div><div class="fld"><label>Maximum qty</label><input placeholder="no limit"></div></div><div class="fld" style="margin:0"><label>Step</label><input value="1"></div>`;
  } else if(reyTab==='catalog'){
    h=`<div class="fld" style="margin-bottom:9px"><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Show in Quick View</label></div><div class="fld" style="margin-bottom:9px"><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Show swatches on catalog card</label></div><div class="fld" style="margin:0"><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx">${ic(I.check)}</span> Hide from search</label></div>`;
  } else if(reyTab==='video'){
    h=`<div class="fld" style="margin:0"><label>Product video URL</label><input placeholder="YouTube, Vimeo or MP4 — plays on hover in catalog"></div>`;
  } else if(reyTab==='tabs'){
    h=peTabsBody();
  } else {
    h=`<div class="fld" style="margin:0"><label>Global section</label><select><option>None</option><option>K-Beauty trust badges</option><option>Ingredient glossary block</option><option>Reviews wall</option></select><p style="font-size:11px;color:var(--ink-soft);margin-top:5px">Insert a reusable section built in the KBB Page Builder.</p></div>`;
  }
  b.innerHTML=h;wireCbx('#reyBody');
}
function yoastPanel(){
  const tabs=[['seo','SEO'],['read','Readability'],['schema','Schema'],['social','Social']];
  return peBox('Yoast SEO',`<div class="htabs">${tabs.map(t=>`<button class="htab${t[0]===yoastTab?' on':''}" data-yo="${t[0]}">${t[1]}</button>`).join('')}</div><div id="yoastBody"></div>`);
}
function renderYoastBody(){
  const b=$('#yoastBody');if(!b)return;const{n,b:brand,cat,slug}=peCtx;let h='';
  if(yoastTab==='seo'){
    h=`<div class="fld"><label>Focus keyphrase</label><input placeholder="e.g. ${(brand||'korean')} ${(cat||'skincare').toLowerCase()}"><button class="pe-link" style="margin-top:6px" onclick="toast('Get related keyphrases (preview)')">Get related keyphrases</button></div>
    <div class="dsec" style="margin-top:2px">Search appearance</div>
    <div class="minitabs"><button class="on">Mobile result</button><button>Desktop result</button></div>
    <div class="seo-snip"><div class="u">kbeautybliss.com › product › ${slug}</div><div class="t">${n||'Product'} | K-Beauty Bliss | Korean Skincare</div><div class="d">Shop ${n||'this product'}${brand?' by '+brand:''} at K-Beauty Bliss — authentic Korean skincare, fast UAE delivery, pay with Tabby &amp; Tamara.</div></div>
    <div class="fld" style="margin-top:13px"><label>SEO title</label><div class="tagchips" style="margin-bottom:7px">${['Title','Page','Separator','Site title'].map(v=>`<span class="tagchip">${v}</span>`).join('')}</div><input value="${n?n+' | K-Beauty Bliss':''}"></div>
    <div class="fld"><label>Slug</label><input value="${slug}"></div>
    <div class="fld" style="margin:0"><label>Meta description</label><textarea placeholder="Write the snippet Google shows…">${n?'Shop '+n+' at K-Beauty Bliss — authentic Korean skincare with fast UAE delivery.':''}</textarea></div>
    <div class="checks" style="margin-top:14px"><div class="check"><div class="ci" style="background:#fdecd2;color:#b7791f">${ic(ICO.ring)}</div><b>SEO analysis</b><small>Add a focus keyphrase</small></div><div class="check"><div class="ci green">${ic(I.check)}</div><b>Readability</b><small>OK</small></div></div>`;
  } else if(yoastTab==='read'){
    h=`<div class="checks"><div class="check"><div class="ci green">${ic(I.check)}</div><b>Sentence length</b><small>OK</small></div><div class="check"><div class="ci green">${ic(I.check)}</div><b>Paragraph length</b><small>OK</small></div><div class="check"><div class="ci" style="background:#fdecd2;color:#b7791f">${ic(ICO.ring)}</div><b>Subheadings</b><small>Add a few more</small></div></div>`;
  } else if(yoastTab==='schema'){
    h=`<div class="g2"><div class="fld"><label>Page type</label><select><option>Item Page</option><option>Web Page</option></select></div><div class="fld"><label>Product schema</label><select><option>Product</option><option>None</option></select></div></div><p style="font-size:11.5px;color:var(--ink-soft);margin:0">Emits Product rich-result schema (price, availability, rating) for Google.</p>`;
  } else {
    h=`<div class="fld"><label>Facebook / OG title</label><input value="${n||''}"></div><div class="fld"><label>Description</label><textarea></textarea></div><div class="fld" style="margin:0"><label>Social share image</label><div class="imgdrop">${ic(ICO.img)}<div style="margin-top:6px">Upload a 1200×630 image</div></div></div>`;
  }
  b.innerHTML=h;wireCbx('#yoastBody');wireChips('#yoastBody');wireMini('#yoastBody');
}
function pagePanel(){
  const tabs=[['general','General'],['header','Header'],['footer','Footer'],['layout','Page layout'],['advanced','Advanced']];
  return peBox('Page Settings · KBB theme',`<div class="htabs">${tabs.map(t=>`<button class="htab${t[0]===pageTab?' on':''}" data-pg="${t[0]}">${t[1]}</button>`).join('')}</div><div id="pageBody"></div>`,false);
}
function renderPageBody(){
  const b=$('#pageBody');if(!b)return;let h='';
  if(pageTab==='general'){
    h=`<div class="g2"><div class="fld"><label>Title Display</label><select><option>Inherit</option><option>Show</option><option>Hide</option></select></div><div class="fld"><label>Page Cover</label><select><option>Inherit</option><option>Enable</option><option>Disable</option></select></div></div><div class="fld" style="margin:0"><label>Body CSS Classes</label><input placeholder="extra classes for the body tag"></div>`;
  } else if(pageTab==='header'){
    h=`<div class="g2"><div class="fld"><label>Header</label><select><option>Inherit</option><option>Transparent</option><option>Hidden</option></select></div><div class="fld"><label>Sticky</label><select><option>Inherit</option><option>On</option><option>Off</option></select></div></div>`;
  } else if(pageTab==='footer'){
    h=`<div class="fld" style="margin:0"><label>Footer</label><select><option>Inherit</option><option>Show</option><option>Hide</option></select></div>`;
  } else if(pageTab==='layout'){
    h=`<div class="g2"><div class="fld"><label>Content width</label><select><option>Inherit</option><option>Full width</option><option>Boxed</option></select></div><div class="fld"><label>Sidebar</label><select><option>None</option><option>Left</option><option>Right</option></select></div></div>`;
  } else {
    h=`<div class="fld" style="margin:0"><label>Custom CSS (this product)</label><textarea placeholder="/* scoped to this product page */"></textarea></div>`;
  }
  b.innerHTML=h;
}
function openProduct(idx){
  pdTab='general';reyTab='misc';yoastTab='seo';pageTab='general';
  const p=idx>=0?CAT_PRODUCTS[idx]:null;const[n,b,sku,cat,price,sale,stock]=p||['','','','Beauty Devices','','',''];
  const draft=p?CAT_DRAFT.has(sku):false;const slug=(n||'new-product').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
  peCtx={n,b,sku,cat,price,sale,stock,slug,draft};
  const cats=PE_CATS.includes(cat)?PE_CATS:[cat,...PE_CATS];
  $('#crumb').textContent='Catalog';$('#ptitle').textContent=p?'Edit product':'New product';
  $('#content').innerHTML=`<div class="wrap">
    <div class="pe-top"><button class="btn ghost sm" onclick="go('catalog')">${ic('<path d="m15 18-6-6 6-6"/>')} Catalog</button><div style="flex:1"></div>
      <span class="pill ${draft?'grey':'green'}">${draft?'Draft':'Published'}</span>
      <button class="btn ghost sm" onclick="toast('Open preview (preview)')">View product</button>
      <button class="btn" onclick="go('catalog');toast('${p?'Product updated (preview)':'Product created (preview)'}')">${p?'Update':'Publish'}</button></div>
    <div class="pe-grid">
      <div class="pe-main">
        <div><input class="pe-title" value="${n}" placeholder="Product name"><div class="pe-perma">Permalink: <span>kbeautybliss.com/product/${slug}</span><button class="lk" onclick="toast('Edit slug (preview)')">Edit</button></div>
          <button class="pe-builder" onclick="toast('Opens the KBB Page Builder (preview)')">${ic('<path d="m12 19 7-7 3 3-7 7-3-3z"/><path d="m18 13-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/>')} Edit with Page Builder</button></div>
        ${peBox('Product description',`<div class="rte"><div class="rte-sub"><button class="am" onclick="toast('Add Media (preview)')">＋ Add Media</button><button class="am" onclick="toast('Add Form (preview)')">＋ Form</button><div class="vc"><button class="on">Visual</button><button>Code</button></div></div><div class="rte-bar">${['Paragraph ▾','B','I','U','• List','1. List','❝','🔗','Align'].map(t=>`<button>${t}</button>`).join('')}</div><textarea class="rte-area" placeholder="Full product description…">${p?descSample(n,b,cat):''}</textarea><div class="wcount"><span>Word count: ${p?251:0}</span><span>Last edited Jun 23, 2026 at 3:33 pm</span></div></div>`)}
        ${peProductData()}
        ${peBox('Product short description',`<textarea class="rte-area" style="border:1px solid var(--border);border-radius:10px;min-height:74px" placeholder="Short summary shown near the title…">${p?'A MedSpa-inspired '+cat.toLowerCase().replace(/s$/,'')+' from '+b+' that combines clinically-tested technology to deliver visible results.':''}</textarea>`)}
        ${reyPanel()}
        ${yoastPanel()}
        ${pagePanel()}
        ${peBox('Revisions',`<div style="display:flex;flex-direction:column;gap:10px">${[['K Beauty Bliss','4 days ago · Jun 25, 2026','Autosave'],['Pak Web Idea','5 days ago · Jun 23, 2026','']].map(r=>`<div class="row" style="gap:10px;font-size:12.5px"><span class="pthumb" style="width:28px;height:28px;font-size:9px;background:${tcol(r[0])}">${initials(r[0])}</span><div><b>${r[0]}</b> <span style="color:var(--ink-soft)">${r[1]}</span> ${r[2]?`<span class="pill grey" style="font-size:9px;padding:1px 6px">${r[2]}</span>`:''}</div></div>`).join('')}</div>`,false)}
      </div>
      <div class="pe-side">
        ${peBox('Publish',`<button class="btn ghost block" onclick="toast('Open preview (preview)')">Preview changes</button>
          <div class="pubrow" style="margin-top:11px"><span>Status</span><b>${draft?'Draft':'Published'} <button class="pe-link" onclick="toast('Edit (preview)')">Edit</button></b></div>
          <div class="pubrow"><span>Visibility</span><b>Public <button class="pe-link" onclick="toast('Edit (preview)')">Edit</button></b></div>
          <div class="pubrow"><span>Revisions</span><b>2 <button class="pe-link" onclick="toast('Browse (preview)')">Browse</button></b></div>
          <div class="pubrow"><span>Published</span><b>Jun 23, 2026</b></div>
          <div class="pubrow"><span>SEO analysis</span><b style="color:var(--ink-soft);font-weight:600">Not available</b></div>
          <div class="pubrow"><span>Readability</span><b style="color:var(--accent-strong)">OK</b></div>
          <div class="pubrow" style="border:0"><span>Catalog visibility</span><b>Shop &amp; search</b></div>
          <div class="row" style="gap:8px;margin-top:12px;align-items:center"><button class="pe-link" onclick="toast('Copied to new draft (preview)')">Copy to draft</button><div style="flex:1"></div><button class="btn" onclick="go('catalog');toast('Updated (preview)')">${p?'Update':'Publish'}</button></div>`)}
        ${peBox('Product image',`<div class="featimg"><div class="ph">${p?initials(b||'KB'):'No image'}</div><div class="cap"><button class="pe-link" onclick="toast('Edit image (preview)')">Edit</button><button class="pe-link" style="color:#d6455a" onclick="toast('Removed (preview)')">Remove</button></div></div>`)}
        ${peBox('Product gallery',`<div class="gal">${(p?[1,2,3,4,5]:[]).map(i=>`<div class="g">IMG ${i}</div>`).join('')}<div class="g add" onclick="toast('Add gallery images (preview)')">＋ Add</div></div>`)}
        ${peBox('Product categories',`<div class="minitabs"><button class="on">All categories</button><button>Most Used</button></div><div class="catlist">${cats.map(c=>`<label class="catopt"><span class="cbx${c===cat?' on':''}">${ic(I.check)}</span> <span style="flex:1">${c}</span>${c===cat?'<span class="pill green" style="font-size:9px;padding:2px 7px">Primary</span>':''}</label>`).join('')}</div><button class="pe-link" style="margin-top:11px" onclick="toast('Add new category (preview)')">+ Add new category</button>`)}
        ${peBox('Product tags',`<div class="row" style="gap:6px"><input class="inp" style="flex:1" placeholder="Add tag"><button class="btn ghost sm" onclick="toast('Tag added (preview)')">Add</button></div><div class="tagchips" style="margin-top:10px">${['k-beauty','bestseller','led-mask'].map(t=>`<span class="tagchip on">${t}</span>`).join('')}</div><button class="pe-link" style="margin-top:9px" onclick="toast('Most used tags (preview)')">Choose from most used</button>`)}
        ${peBox('Post Attributes',`<div class="fld" style="margin:0"><label>Template</label><select><option>Default template</option><option>Full width</option><option>Landing</option></select></div>`,false)}
        ${peBox('Brands',`<div class="minitabs"><button class="on">All Brands</button><button>Most Used</button></div><select class="inp" style="width:100%">${CAT_BRANDS.map(x=>`<option${x[0]===b?' selected':''}>${x[0]}</option>`).join('')}</select><button class="pe-link" style="margin-top:11px" onclick="toast('Add new brand (preview)')">+ Add New Brand</button>`)}
        ${peBox('Product Subtitle · KBB',`<input class="inp" style="width:100%" placeholder="e.g. MedSpa-inspired cooling mask"><div class="fld" style="margin:12px 0 0"><label>Badge</label><select><option>None</option><option>Best seller</option><option>New</option><option>Sale</option></select></div>`,false)}
      </div>
    </div></div>`;
  $$('#content .pd-tab').forEach(t=>t.onclick=()=>{pdTab=t.dataset.pd;renderPDBody();});
  $$('#content .htab[data-rey]').forEach(t=>t.onclick=()=>{reyTab=t.dataset.rey;$$('#content .htab[data-rey]').forEach(x=>x.classList.toggle('on',x===t));renderReyBody();});
  $$('#content .htab[data-yo]').forEach(t=>t.onclick=()=>{yoastTab=t.dataset.yo;$$('#content .htab[data-yo]').forEach(x=>x.classList.toggle('on',x===t));renderYoastBody();});
  $$('#content .htab[data-pg]').forEach(t=>t.onclick=()=>{pageTab=t.dataset.pg;$$('#content .htab[data-pg]').forEach(x=>x.classList.toggle('on',x===t));renderPageBody();});
  renderPDBody();renderReyBody();renderYoastBody();renderPageBody();
  wireCbx('#content');wireChips('#content');wireMini('#content');
  $('#content').scrollTop=0;
}
function closeDrawer(){$('#drawerBg').classList.remove('on');$('#drawer').classList.remove('on');}

/* ===================== IMPORT / EXPORT ===================== */
/*
  Store → Import / Export. The screen that makes `php artisan kbb:import`
  reachable by someone who has no shell.

  THIS SCREEN DOES NOT IMPORT ANYTHING. Every row goes through the importer in
  app/Services/Import — the same mapping, the same refusals, the same
  idempotency — driven through /admin-api/import. What lives here is the part
  that cannot live in a command: the uploads, the plain-word explanation of the
  four decisions that are the owner's, and the loop that turns an import too
  long for one request into a sequence of short ones.

  THE LOOP IS THE WHOLE POINT, so it is worth stating what it does. Shared
  PHP-FPM kills long requests and there is no queue worker on this host, so the
  import is many small requests, each continuing from the checkpoint the last
  one committed. This browser loop:

    - measures how long each step really took and sizes the next one to land
      near IMP_STEP_TARGET_MS, because the host's real timeout is unknown and
      cannot be read from inside PHP. Halving on a slow step is how it finds the
      ceiling without being told what it is;

    - treats a FAILED request as a slice that was too big rather than as the end
      of the world: it halves and retries. A timeout costs the rows in the
      uncommitted batch and nothing else, because every committed batch advanced
      the checkpoint in the same transaction;

    - treats a 409 as "the last request is still running server-side" — which is
      exactly what a proxy timeout leaves behind, since the PHP process keeps
      going — and waits for it instead of starting a second one;

    - never auto-starts. A reload of a part-finished import shows what happened
      and a Continue button. Continuing is always safe; guessing that the owner
      wanted to continue is not.

  The preview is different and the screen says so in words: a dry run writes
  everything and rolls it back inside ONE transaction, which is the only way it
  can resolve an order's customer, so it cannot be continued across requests —
  only redone deeper. See ImportDriver's class comment.
*/
window.openProduct=openProduct;window.closeDrawer=closeDrawer;
window.clearSel=()=>{catSel.clear();catProducts();};

// The section router at the top of this file still assigns to this when the
// Import screen is opened. Kept so that line keeps working; nothing reads it.
let impStep=1;

/* How long one step should take. Comfortably inside any plausible
   max_execution_time, and long enough that a 20,000-row import is not 400
   round trips. */
const IMP_STEP_TARGET_MS=8000;

let impState=null;      // the last /import/status payload
let impRows=400;        // rows per step, adapted from what the server manages
let impRunning=false;   // the loop is going
let impFails=0;         // consecutive failed steps, for the backoff
let impMsg='';          // what to tell the owner about the last step
let impMsgKind='';      // '', 'warn', 'bad'
let impChoice=null;     // the owner's decisions, before a run pins them

function impBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'')+'/admin-api'; }

function impEsc(s){
  // Refusal reasons quote the refused cell, and a refused cell contains
  // whatever was in the owner's WooCommerce database. It is never HTML.
  return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function impNum(n){ return Number(n||0).toLocaleString('en-US'); }
function impBytes(b){ b=Number(b||0); return b<1024?b+' B':(b<1048576?Math.round(b/1024)+' KB':(b/1048576).toFixed(1)+' MB'); }

/* Never throws on an HTTP error: a 409 and a 500 mean different things to the
   loop and both have to be visible to it. */
async function impApi(path,opts){
  const o=Object.assign({credentials:'same-origin',headers:{}},opts||{});
  o.headers=Object.assign({'X-XSRF-TOKEN':uToken(),'Accept':'application/json'},o.headers);
  if(o.method&&o.method!=='GET'&&!(o.body instanceof FormData)) o.headers['Content-Type']='application/json';
  const r=await fetch(impBase()+path,o);
  const text=await r.text();
  let data=null;
  try{ data=JSON.parse(text); }
  catch(e){
    // A non-JSON body is an error page — a 419, a 504 from the proxy, a raw
    // 500 — not an API answer. Surfacing a snippet beats "something failed".
    return {status:r.status,data:null,raw:text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,200)};
  }
  return {status:r.status,data:data};
}

async function impRefresh(){
  const r=await impApi('/import/status');
  if(r.data&&r.data.ok){ impState=r.data; if(!impChoice) impChoice=Object.assign({},r.data.defaults); }
  return impState;
}

/* ------------------------------------------------------------------ render */

async function renderImport(){
  $('#content').innerHTML='<div class="wrap"><p style="padding:40px;color:var(--ink-soft)">Loading…</p></div>';
  impRunning=false; impMsg=''; impMsgKind='';
  try{ await impRefresh(); }
  catch(e){ $('#content').innerHTML='<div class="wrap"><div class="card pad">Could not reach the server.</div></div>'; return; }
  impPaint();
}

function impPaint(){
  const s=impState; if(!s) return;
  const run=s.run;
  const anyFile=s.files.some(f=>f.present);

  $('#content').innerHTML=impCss()+'<div class="wrap impwrap">'
    +'<div class="page-head"><h2>Store Import / Export</h2><p>Bring your WooCommerce store across — categories, brands, products, customers, orders and order lines. '
    +'Upload the exports, look at what <b>would</b> happen, fix anything it refuses, then import for real. '
    +'Nothing is written until you press Import.</p></div>'
    +impBanner(run)
    +impCleanupCard()
    +impAssumedCard(s)
    +impFilesCard(s)
    +(anyFile?impChoicesCard(s):'')
    +(anyFile?impPreviewCard(s):'')
    +(anyFile?impRunCard(s):'')
    +impRejectsCard(s)
    +gbUrlsMediaCard()
    +gdLiveProgressCard()
    +gfHistoryCard()
    +u3ArticleAddressCard()
    +impExportCard()
    +'</div>';

  impWire();
}

function impCss(){
  return '<style>'
  +'.impwrap .impgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:12px}'
  +'.impwrap .impfile{border:1px solid var(--border);border-radius:11px;padding:13px 14px;display:flex;flex-direction:column;gap:7px;min-width:0}'
  +'.impwrap .impfile.on{border-color:#9ED8BB;background:#F6FCF9}'
  +'.impwrap .impfile b{font-size:13px}'
  +'.impwrap .impfile p{font-size:11.5px;color:var(--ink-soft);margin:0;line-height:1.5}'
  +'.impwrap .impmeta{font-size:11.5px;color:var(--ink-soft);display:flex;gap:10px;flex-wrap:wrap;align-items:center}'
  +'.impwrap .impdrop{border:2px dashed var(--border);border-radius:12px;padding:22px 16px;text-align:center;cursor:pointer;background:var(--surface-2)}'
  +'.impwrap .impdrop.hot{border-color:var(--accent);background:var(--accent-soft)}'
  +'.impwrap .impdrop b{display:block;font-size:13.5px;margin-bottom:4px}'
  +'.impwrap .impdrop span{font-size:12px;color:var(--ink-soft)}'
  +'.impwrap .imptbl{width:100%;border-collapse:collapse;font-size:12.5px}'
  +'.impwrap .imptbl th{text-align:left;font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-faint);padding:6px 8px;border-bottom:1px solid var(--border);white-space:nowrap}'
  +'.impwrap .imptbl td{padding:7px 8px;border-bottom:1px solid var(--border);vertical-align:top}'
  +'.impwrap .imptbl td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}'
  +'.impwrap .impscroll{overflow-x:auto;-webkit-overflow-scrolling:touch}'
  /* On a phone these two tables stack into labelled blocks rather than scrolling
     sideways. The refusal REASON is the column that matters and it is the last
     one, so a sideways-scrolling table hides the only part worth reading. */
  +'@media(max-width:640px){'
  +'.impwrap .impstack thead{display:none}'
  +'.impwrap .impstack,.impwrap .impstack tbody,.impwrap .impstack tr,.impwrap .impstack td{display:block;width:auto}'
  +'.impwrap .impstack tr{border-bottom:1px solid var(--border);padding:9px 0}'
  +'.impwrap .impstack td{border:0;padding:2px 0;text-align:left}'
  +'.impwrap .impstack td.num{text-align:left}'
  +'.impwrap .impstack td[data-l]::before{content:attr(data-l) " ";color:var(--ink-faint);font-size:10.5px;'
  +'text-transform:uppercase;letter-spacing:.06em;font-weight:700}'
  +'}'
  +'.impwrap .impchoice{border:1px solid var(--border);border-radius:11px;padding:14px;display:flex;flex-direction:column;gap:9px;min-width:0}'
  +'.impwrap .impchoice b{font-size:13px}'
  +'.impwrap .impchoice p{font-size:12px;color:var(--ink-soft);margin:0;line-height:1.55}'
  +'.impwrap .impseg{display:flex;gap:6px;flex-wrap:wrap}'
  +'.impwrap .impseg button{flex:1 1 auto;min-width:110px;border:1px solid var(--border);background:var(--surface);border-radius:9px;padding:8px 10px;font:inherit;font-size:12px;cursor:pointer;color:var(--ink)}'
  +'.impwrap .impseg button.on{border-color:var(--accent);background:var(--accent-soft);font-weight:700}'
  +'.impwrap .impbanner{border-radius:11px;padding:13px 15px;margin-bottom:16px;font-size:12.5px;line-height:1.6;display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between}'
  +'.impwrap .impbanner.warn{background:#FFF8EC;border:1px solid #F5E1BC;color:#7A5410}'
  +'.impwrap .impbanner.bad{background:#FDF2F2;border:1px solid #F3C9C9;color:#96271F}'
  +'.impwrap .impbanner.good{background:#F0F9F4;border:1px solid #CFE9DB;color:#1F7D52}'
  +'.impwrap .impbanner .row{flex-shrink:0}'
  +'.impwrap .impnote{font-size:11.5px;color:var(--ink-soft);line-height:1.6;margin-top:6px}'
  +'.impwrap .impbar{height:7px;background:var(--surface-3);border-radius:99px;overflow:hidden;margin-top:6px}'
  +'.impwrap .impbar i{display:block;height:100%;background:linear-gradient(90deg,var(--accent),var(--accent-strong));border-radius:99px;transition:.35s var(--ease)}'
  +'.impwrap .impwhy{font-size:11.5px;color:var(--ink-soft);line-height:1.6;margin:10px 0 0}'
  +'@media(max-width:560px){.impwrap .impbanner{flex-direction:column;align-items:stretch}.impwrap .impbanner .row{width:100%}.impwrap .impbanner .btn{flex:1}.impwrap .impseg button{min-width:0}}'
  +'</style>';
}

/*
 * What the operator SAID was already in this shop, which the export plugin
 * could not check. docs/GK-EXPORT-GROUPS.md §5: the claim travels in the
 * manifest so it does not have to live in his memory, and this is the one
 * moment somebody is looking at the shop the claim is about.
 *
 * A notice, never a refusal. The export screen already refused to start with
 * an unanswered warning, which is where a refusal belongs; refusing here
 * would refuse the partial import this console exists to make possible.
 */
function impAssumedCard(s){
  const claims=((s.manifest&&s.manifest.groups&&s.manifest.groups.assumed_already_imported)||[]);
  if(!claims.length) return '';

  return '<div class="card pad" style="margin-bottom:16px;border-left:3px solid '
    +(claims.some(c=>c.severity==='loses')?'#96271F':'var(--ink-soft)')+'">'
    +'<b style="font-size:14px">Before you import — something this export assumes</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 10px">When this export was taken you '
    +'confirmed on the WordPress screen that these were already in this shop. The export plugin cannot '
    +'see this shop and did not check. If any of them is not true, read the note before importing.</p>'
    +claims.map(c=>'<div class="impmeta" style="margin-top:8px"><b>'+impEsc(c.needs)+'</b> was assumed to be '
      +'already imported when <b>'+impEsc(c.group)+'</b> was exported.'
      +(c.severity==='loses'?' <b style="color:#96271F">If it was not, rows will be lost and the report of '
        +'this run will not say so.</b>':'')
      +(c.claim?'<div style="margin-top:4px">'+impEsc(c.claim)+'</div>':'')+'</div>').join('')
    +'</div>';
}

function impBanner(run){
  if(impMsg){
    return '<div class="impbanner '+(impMsgKind||'warn')+'"><div>'+impEsc(impMsg)+'</div>'
      +(run&&run.status==='running'?'<div class="row"><button class="btn" id="impContinue">Continue</button></div>':'')+'</div>';
  }
  if(!run) return '';
  if(run.status==='running'){
    return '<div class="impbanner warn"><div><b>'+(run.mode==='preview'?'A preview':'An import')+' is part-way through.</b> '
      +'Nothing was lost — it carries on from the last batch that finished. Press Continue whenever you are ready.</div>'
      +'<div class="row"><button class="btn" id="impContinue">Continue</button><button class="btn ghost" id="impStop">Stop</button></div></div>';
  }
  if(run.status==='complete'){
    return '<div class="impbanner good"><div><b>'+(run.mode==='preview'?'Preview finished.':'Import finished.')+'</b> '
      +(run.mode==='preview'?'Nothing was written — read the table below, fix anything refused, then import for real.'
        :'Run it once more to prove it: everything should come back as “unchanged”.')+'</div></div>';
  }
  return '';
}

/* ---------------------------------------------------------------- 1. files */

function impFilesCard(s){
  const lim=s.limits;
  const cards=s.files.map(f=>
    '<div class="impfile'+(f.present?' on':'')+'">'
    +'<div class="between" style="align-items:flex-start"><b>'+impEsc(f.label)+'</b>'
    +(f.present
      ?'<span class="pill green">ready</span>'
      :'<span style="font-size:11px;color:#94A3B8;font-weight:600">not uploaded</span>')+'</div>'
    +'<p>'+impEsc(f.help)+'</p>'
    +(f.present
      ?'<div class="impmeta"><span>'+impNum(f.rows)+' rows</span><span>'+impBytes(f.bytes)+'</span>'
        +'<button class="btn ghost sm impforget" data-e="'+impEsc(f.entity)+'" style="margin-left:auto;padding:3px 9px;font-size:11px">Remove</button></div>'
      :'<div class="impmeta">expects <code style="font-family:var(--mono);font-size:11px">'+impEsc(f.file)+'</code></div>')
    +'</div>').join('');

  return '<div class="card pad" style="margin-bottom:16px">'
    +'<div class="between" style="margin-bottom:12px;flex-wrap:wrap;gap:10px"><div><b style="font-size:14px">1 · Your exports</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0">Upload as many as you have. You do not need all six — a file you leave out is simply not touched, '
    +'so a top-up of new orders on its own is a perfectly normal thing to run.</p></div>'
    /* Lane PJ-B: one button for what took the owner nine Remove presses. Shown only when there is a file
       to remove; files only, never anything already imported -- the confirm() says so. */
    +((s.files.some(f=>f.present)||(s.companions||[]).some(c=>c.present))
      ?'<button class="btn ghost sm" id="impForgetAll"'+(s.run&&s.run.status==='running'?' disabled title="Stop the import first"':'')
        +' style="padding:4px 11px;font-size:12px">Remove all files</button>'
      :'')
    +'</div>'
    +'<div class="impdrop" id="impDrop"><b>Drop your CSV files or a group\'s .zip here, or click to choose</b>'
    +'<span>Name them <code style="font-family:var(--mono)">categories.csv</code>, <code style="font-family:var(--mono)">brands.csv</code>, '
    +'<code style="font-family:var(--mono)">products.csv</code>, <code style="font-family:var(--mono)">customers.csv</code>, '
    +'<code style="font-family:var(--mono)">orders.csv</code>, <code style="font-family:var(--mono)">order_items.csv</code> and they sort themselves out.</span>'
    +'<input type="file" id="impFileInput" accept=".csv,text/csv,.zip,application/zip" multiple hidden></div>'
    +'<div class="impnote">This server accepts uploads up to <b>'+impEsc(lim.upload_max_filesize)+'</b> each ('
    +'form limit '+impEsc(lim.post_max_size)+'). Files are stored outside the website folder and are never reachable from the web. '
    +'If an order export is larger than that, split it — the importer carries on across files.</div>'
    +'<div class="impgrid" style="margin-top:14px">'+cards+'</div>'
    +gpCompanionsStrip(s)
    +'<div id="impUploadMsg"></div>'
    +'</div>';
}

/* -------------------------------------------------------------- 2. choices */

function impChoicesCard(s){
  const c=impChoice||s.defaults;
  const locked=s.run&&s.run.status==='running';
  const pinned=locked?s.run.options:c;
  const seg=(key,opts)=>'<div class="impseg">'+opts.map(o=>
      '<button class="impopt'+(String(pinned[key])===String(o[0])?' on':'')+'" data-k="'+key+'" data-v="'+impEsc(o[0])+'"'
      +(locked?' disabled style="opacity:.55;cursor:not-allowed"':'')+'>'+impEsc(o[1])+'</button>').join('')+'</div>';

  return '<div class="card pad" style="margin-bottom:16px">'
    +'<b style="font-size:14px">2 · Four decisions that are yours, not the importer\'s</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 14px">Each one is already set to what we recommend, so you can leave them alone. '
    +(locked?'<b>They are fixed for the run that is in progress</b> — stop it first to change them.':'')+'</p>'
    +'<div class="impgrid">'

    +'<div class="impchoice"><b>Orders placed without an account</b>'
    +'<p>Most people check out as guests. <b>Make a customer record</b> files those orders under the shopper\'s email, so they show up in '
    +'Customers and count towards what that person has spent — which is what this store already does when someone buys without signing in. '
    +'<b>Leave them unattached</b> keeps them as orders belonging to nobody, and they then sit outside every per-customer figure.</p>'
    +seg('guests',[['synthesise','Make a customer record'],['unlinked','Leave them unattached']])+'</div>'

    +'<div class="impchoice"><b>Which order number to keep</b>'
    +'<p><b>The number on the invoice</b> keeps what your customer already has on their paperwork. '
    +'<b>WooCommerce\'s internal id</b> is guaranteed never to clash — use it if the import tells you two orders share a number.</p>'
    +seg('order_number',[['number','The number on the invoice'],['id','WooCommerce\'s internal id']])+'</div>'

    +'<div class="impchoice"><b>What time zone your export\'s dates are in</b>'
    +'<p>WooCommerce writes order dates in your shop\'s own time zone. Getting this wrong shifts every order in the store by a few hours, '
    +'which quietly moves evening orders onto the next day and makes your daily sales disagree with WooCommerce. '
    +'Leave it on Dubai unless your WordPress was set to something else.</p>'
    +'<select class="inp" id="impTz"'+(locked?' disabled':'')+'>'
    +s.timezones.map(t=>'<option value="'+impEsc(t)+'"'+(pinned.timezone===t?' selected':'')+'>'+impEsc(t)+'</option>').join('')
    +'</select></div>'

    +'<div class="impchoice"><b>The sample catalogue is holding real names</b>'
    +'<p>This store was set up with a few placeholder brands and categories — <i>cosrx</i>, <i>beauty of joseon</i>, <i>cleansers</i>, '
    +'<i>toners</i>, <i>serums</i> — and those are real things you sell. Your genuine ones cannot use the same web address twice, '
    +'so by default they are refused and listed. Turn this on and the real WooCommerce record simply takes the placeholder\'s place. '
    +'A name held by a different genuine record is still refused either way — only you can decide that one.</p>'
    +seg('adopt_by_slug',[[true,'Take over the placeholders'],[false,'Refuse and list them']])+'</div>'

    +'</div>'
    +'<label class="impnote" style="display:flex;gap:8px;align-items:flex-start;margin-top:14px;cursor:pointer">'
    +'<input type="checkbox" id="impRestart" style="margin-top:2px"'+(locked?' disabled':'')+'>'
    +'<span><b>Start every file again from its first row.</b> Normally you do not want this — an interrupted import picks up where it stopped. '
    +'Use it when you have re-exported a file and want it re-read from the top. It is safe: rows already imported are simply re-presented and come back as “unchanged”.</span></label>'
    +'</div>';
}

/* -------------------------------------------------------------- 3. preview */

function impPreviewCard(s){
  const run=s.run, isPreview=run&&run.mode==='preview';
  const deepest=Math.max.apply(null,[1].concat(s.entities.filter(e=>e.present).map(e=>e.rows_total)));
  const depth=isPreview?Math.min(run.preview_limit,deepest):0;
  const pct=isPreview?Math.round(100*depth/deepest):0;

  return '<div class="card pad" style="margin-bottom:16px">'
    +'<div class="between" style="flex-wrap:wrap;gap:12px;align-items:flex-start">'
    +'<div><b style="font-size:14px">3 · Look before you write</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:640px">This does the entire import and then throws it away, so it can tell you exactly '
    +'what would happen — including every row it would refuse and why. <b>Nothing is saved.</b></p></div>'
    +'<div class="row" style="gap:8px">'
    +'<button class="btn ghost" id="impPreview"'+(impRunning?' disabled':'')+'>'+(isPreview&&run.status==='running'?'Continue preview':'Preview')+'</button>'
    +'</div></div>'
    +(isPreview?'<div class="impnote" style="margin-top:12px"><b>Checked the first '+impNum(depth)+' rows of each file</b>'
        +(run.status==='complete'?' — that is all of them.':' of '+impNum(deepest)+'.')
        +'<div class="impbar"><i style="width:'+pct+'%"></i></div>'
        +'<div style="margin-top:7px">A preview cannot be continued the way a real import can: it has to write everything and undo it in one go, '
        +'which is the only way it can work out which order belongs to which customer. So it reads deeper each time you press Continue, '
        +'starting from the top again. If it stops getting deeper, preview what it managed and import the rest for real — the real import '
        +'<i>does</i> carry on from where it stopped.</div></div>':'')
    +(isPreview?impResultTable(s,'Would create','Would update','Already identical','Would refuse'):'')
    +'</div>';
}

/* ------------------------------------------------------------------ 4. run */

function impRunCard(s){
  const run=s.run, isLive=run&&run.mode==='live';
  const busy=impRunning;

  const rows=s.entities.filter(e=>e.present).map(e=>{
    /* THE SERVER DECIDES WHETHER THERE IS A BAR. `percent` is null when the
       number would be a lie — no denominator, a manifest whose row count
       disagrees with the file on disk, or a checkpoint recorded against a file
       that has since been replaced. Dividing processed by rows_total here drew
       a confident bar in all three. Lane GF; docs/GF-IMPORT-REFINEMENT.md §3. */
    const pct=e.percent==null?null:e.percent;
    const state=!isLive?'waiting':(e.done_this_run?'done':(run.current_entity===e.entity?'importing…':(e.processed>0?'part way':'waiting')));
    return '<div style="margin-bottom:11px"><div class="between" style="margin-bottom:4px">'
      +'<span style="font-size:12.5px;font-weight:600">'+impEsc(e.label)+'</span>'
      +'<span style="font-size:11.5px;color:var(--ink-soft)">'+impNum(e.processed)+' of '+impNum(e.rows_total)+' · '+impEsc(state)+'</span></div>'
      +(pct==null?'<div style="font-size:11.5px;color:var(--ink-soft)">'
          +(e.rows_mismatch?impEsc(e.rows_mismatch.sentence)
            :(e.source_changed?'This file has changed since the run stopped, so there is no honest '
              +'progress bar for it — start it again from row one.'
              :'No row count for this file, so there is no progress bar.'))+'</div>'
        :'<div class="impbar"><i style="width:'+pct+'%"></i></div>')+'</div>';
  }).join('');

  return '<div class="card pad" style="margin-bottom:16px">'
    +'<div class="between" style="flex-wrap:wrap;gap:12px;align-items:flex-start">'
    +'<div><b style="font-size:14px">4 · Import for real</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:640px">Safe to run more than once. Every row is matched on its WooCommerce id, '
    +'so importing the same file twice updates rather than duplicates — and a second run reporting everything as “unchanged” is the best proof there is that it worked.</p></div>'
    +'<div class="row" style="gap:8px">'
    +(busy?'<button class="btn ghost" id="impPause">Pause</button>'
          :'<button class="btn" id="impRun">'+(isLive&&run.status==='running'?'Continue import':'Import')+'</button>')
    +(isLive&&run&&run.status==='running'?'<button class="btn ghost" id="impBackground">Keep importing in the background</button>':'')
    +'</div></div>'
    +'<div style="margin-top:14px">'+rows+'</div>'
    +'<div class="impnote">Your browser does this in small pieces, a few seconds at a time, so this shared server never has to hold one long request open. '
    +'Closing this tab stops it here — whatever had finished stays finished, and coming back offers to carry on from the row after the last one. '
    +'To have the server keep going with the tab closed, press “Keep importing in the background”.'
    +(busy?' <b>Working — about '+impNum(impRows)+' rows per piece.</b>':'')+'</div>'
    +(isLive?impResultTable(s,'Created','Updated','Unchanged','Refused'):'')
    +'<div class="row" style="justify-content:flex-end;margin-top:14px"><button class="btn ghost sm" id="impReset" style="color:#c0392b">Forget progress and start over</button></div>'
    +'</div>';
}

function impResultTable(s,a,b,c,d){
  const shown=s.entities.filter(e=>e.present&&(e.created||e.updated||e.unchanged||e.rejected||e.processed));
  if(!shown.length) return '';

  const notes=[];
  shown.forEach(e=>{ Object.keys(e.notes||{}).forEach(n=>notes.push([e.label,n,e.notes[n]])); });

  return '<div class="impscroll" style="margin-top:16px"><table class="imptbl impstack"><thead><tr><th>What</th>'
    +'<th style="text-align:right">'+impEsc(a)+'</th><th style="text-align:right">'+impEsc(b)+'</th>'
    +'<th style="text-align:right">'+impEsc(c)+'</th><th style="text-align:right">'+impEsc(d)+'</th></tr></thead><tbody>'
    +shown.map(e=>'<tr><td><b>'+impEsc(e.label)+'</b>'+(e.source_changed?' <span class="pill" style="background:#FDF2F2;color:#96271F">file changed</span>':'')+'</td>'
      +'<td class="num" data-l="'+impEsc(a)+'">'+impNum(e.created)+'</td>'
      +'<td class="num" data-l="'+impEsc(b)+'">'+impNum(e.updated)+'</td>'
      +'<td class="num" data-l="'+impEsc(c)+'">'+impNum(e.unchanged)+'</td>'
      +'<td class="num" data-l="'+impEsc(d)+'"'+(e.rejected?' style="color:#96271F;font-weight:700"':'')+'>'+impNum(e.rejected)+'</td></tr>').join('')
    +'</tbody></table></div>'
    +(notes.length?'<div class="impwhy"><b>Worth knowing</b><ul style="margin:6px 0 0;padding-left:18px">'
      +notes.map(n=>'<li>'+impEsc(n[0])+' — '+impEsc(n[1])+' <span style="color:var(--ink-faint)">('+impNum(n[2])+')</span></li>').join('')
      +'</ul></div>':'');
}

/* -------------------------------------------------------------- 5. refusals */

function impRejectsCard(s){
  const r=s.rejects;
  if(!r||!r.count) return '';
  const mode=(s.run&&s.run.mode)||'preview';

  return '<div class="card pad" style="margin-bottom:16px;border-color:#F3C9C9">'
    +'<div class="between" style="flex-wrap:wrap;gap:12px;align-items:flex-start">'
    +'<div><b style="font-size:14px;color:#96271F">'+impNum(r.count)+' rows were refused</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:640px">These are <b>not</b> in the database. Each one says what is wrong with it. '
    +'Fix them in your export and upload it again — the rows that did go in will just report as unchanged next time.</p></div>'
    +'<div class="row"><a class="btn ghost" href="'+impBase()+'/import/rejects?mode='+encodeURIComponent(mode)+'">Download all '+impNum(r.count)+' as CSV</a></div></div>'
    +'<div class="impscroll" style="margin-top:14px"><table class="imptbl impstack"><thead><tr><th>What</th><th>Line</th><th>Which row</th><th>Why it was refused</th></tr></thead><tbody>'
    +r.shown.map(x=>'<tr><td data-l="File"><b>'+impEsc(x.entity)+'</b></td><td class="num" data-l="Line">'+impEsc(x.line)+'</td>'
      +'<td data-l="Row" style="font-family:var(--mono);font-size:11px">'+impEsc(x.id)+'</td>'
      +'<td data-l="Why">'+impEsc(x.reason)+'</td></tr>').join('')
    +'</tbody></table></div>'
    +(r.truncated?'<div class="impnote">Showing the first '+impNum(r.shown.length)+'. The CSV has every one of them.</div>':'')
    +'</div>';
}

/* ---------------------------------------------------------------- 6. export */

function impExportCard(){
  return '<div class="card pad">'
    +'<b style="font-size:14px">Export</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:680px">'
    +'Two exports already exist and are the ones worth having: <b>Store → Customers</b> hands you the customer list as a CSV, filters and all, '
    +'and <b>Store → Orders</b> does the same for orders. Use those.</p>'
    +'<p class="impwhy" style="max-width:680px">A matching export of all six files — one that could be re-imported and come back identical — is not here on purpose. '
    +'Writing it means a second set of rules mapping every column back the other way, and a second set of rules is a second answer to what a row meant. '
    +'That belongs in its own piece of work with its own tests, not bolted onto this screen.</p>'
    +'</div>';
}

/* ---------------------------------------------- addresses & pictures (GB) */
/*
 * The half of the migration that has no rows, and therefore no row count to
 * tell the owner it went wrong. Two jobs:
 *
 *   URLs     the addresses Google already holds. A redirect only fires on an
 *            address that 404s — the table is read from the 404 handler alone —
 *            so the map's discard and ask buckets carry the reason per row.
 *
 *   PICTURES the import copies image URLs as strings, so after a clean import
 *            every photograph is still served by the old WordPress site and
 *            breaks the day it is switched off. This is where they come across.
 *
 * Its own state, loaded on demand: the card is at the bottom of a long screen
 * and the status call walks the whole catalogue, so it is not paid for by
 * someone who came here to upload a CSV.
 */
let gbUM=null, gbUMBusy=false, gbUMMsg='';

/* "Links to the old site" (Lane PT) -- a row of the card below. Loaded only
   when Preview is pressed: it reads every description that names the old
   host. POST /admin-api/urls-media/old-links, capability data.old_links. */
let ptOL=null, ptOLBusy=false, ptOLMsg='';

/* Store → Import → the live progress page (Lane GD).

   A LINK, NOT A PANEL. The page it points at is a standalone document served
   from /admin-api/urls-media/progress-page with no build step and no dependency
   on this bundle — deliberately, because its whole job is to be trustworthy at
   the moment something has gone wrong, and this file is the thing most likely
   to be what is broken. See MediaSideloadApiController::page(). */
function gfHistoryCard(){
  return '<div class="card pad">'
    +'<b style="font-size:14px">What has been imported</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:680px">'
    +'Every import this shop has run: which export it came from, when that export was taken off the old '
    +'site, and what each run created, updated, left alone or refused. It is kept even when you press '
    +'&ldquo;Forget progress and start over&rdquo;.</p>'
    +'<a href="'+impBase()+'/import/history-page" target="_blank" rel="noopener">'
    +'<button class="btn" style="margin-top:10px">Open the record</button></a></div>';
}

function u3ArticleAddressCard(){
  return '<div class="card pad">'
    +'<b style="font-size:14px">Articles this shop cannot serve</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:680px">'
    +'Every live article whose address the storefront itself already answers &mdash; /about/, /wishlist/, '
    +'/feed/ &mdash; with the URL it is indexed at today. Each one is a rename and a redirect in WordPress, '
    +'before you export again. Opening it writes nothing.</p>'
    +'<a href="'+impBase()+'/import/article-addresses-page" target="_blank" rel="noopener">'
    +'<button class="btn" style="margin-top:10px">Open the list</button></a></div>';
}

/* The pre-migration clean-up (routes/cleanup-admin.php) had a page and no way
   in: nothing in this console linked to it, and the owner was sent to look for
   a button that did not exist. FIRST on the page, because it is the step
   before uploading anything. The page itself lists, writes nothing until the
   owner types DELETE, and is owner-only (data.cleanup). */
function impCleanupCard(){
  return '<div class="card pad">'
    +'<b style="font-size:14px">Before you import &mdash; clean up this shop</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:680px">'
    +'Remove the test orders, demo products, placeholder categories and brands, and anything else this shop '
    +'holds that did not come from WordPress, before you import. It lists everything by name first and deletes '
    +'nothing until you tick it and type DELETE. Pages, menus, banners and videos are never listed.</p>'
    +'<a href="'+impBase()+'/cleanup/page" target="_blank" rel="noopener">'
    +'<button class="btn" style="margin-top:10px">Open the clean-up</button></a></div>';
}

function gdLiveProgressCard(){
  return '<div class="card pad">'
    +'<b style="font-size:14px">Pictures &amp; live progress</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:680px">'
    +'Fetch the product photographs off the old site, straight into this shop, and watch it happen. '
    +'The page keeps going until there are none left, tells you which ones failed and why, and says '
    +'plainly whether a run is going, finished, or stopped halfway.</p>'
    +'<a href="'+impBase()+'/urls-media/progress-page" target="_blank" rel="noopener">'
    +'<button class="btn" style="margin-top:10px">Open the live progress page</button></a></div>';
}

/* -------------------------------------- the addresses group (Lane GP) */
/*
 * permalinks.csv and media.csv: the two files of the export that no importer
 * steps. Store → Import used to refuse both BY NAME, so the owner downloaded
 * the "Addresses and pictures" group and was told twice it had not imported.
 * They are accepted now — but a file that lands silently is not much better
 * than one that is turned away, so these two blocks are where he sees them.
 */
function gpCompanionsStrip(s){
  const rows=(s.companions||[]);
  if(!rows.length) return '';

  return '<div class="impgrid" style="margin-top:10px">'+rows.map(c=>
    '<div class="impfile'+(c.present?' on':'')+'">'
    +'<div class="between" style="align-items:flex-start"><b>'+impEsc(c.label)+'</b>'
    +(c.present
      ?'<span class="pill green">ready</span>'
      :'<span style="font-size:11px;color:#94A3B8;font-weight:600">not uploaded</span>')+'</div>'
    +'<p>'+impEsc(c.help)+'</p>'
    +(c.present
      ?'<div class="impmeta"><span>'+impNum(c.rows)+' rows</span><span>'+impBytes(c.bytes)+'</span>'
        +'<button class="btn ghost sm impforget" data-e="'+impEsc(c.key)+'" style="margin-left:auto;padding:3px 9px;font-size:11px">Remove</button></div>'
      :'<div class="impmeta">expects <code style="font-family:var(--mono);font-size:11px">'+impEsc(c.file)+'</code></div>')
    +'<p style="font-size:11px;color:var(--ink-soft);margin:6px 0 0">Read by '+impEsc(c.read_by)+'. Not imported as rows.</p>'
    +'</div>').join('')+'</div>';
}

/*
 * Which of the two files this answer was built from, said on the screen for
 * the reason UrlsMediaApiController::sources() gives: without permalinks.csv
 * the map still draws a perfectly confident list — of addresses derived from
 * this shop's own rows — and nothing distinguishes it from one built on the
 * addresses the old site really published.
 */
function gpSourceLine(src){
  if(!src) return '';

  return ['permalinks','media'].map(k=>{
    const x=src[k]; if(!x) return '';
    return '<p style="font-size:12px;margin:6px 0 0;color:'+(x.present?'var(--ink-soft)':'#b45309')+'">'
      +'<code style="font-family:var(--mono);font-size:11px">'+impEsc(x.file)+'</code> — '+impEsc(x.note)+'</p>';
  }).join('');
}

/* ------------------------------------------ the ask bucket, answerable (Lane A) */
/*
 * WHAT THIS REPLACED. A tile reading "312 need your decision" and nothing to
 * press. The map re-derived the same 312 on every load, for ever, and the only
 * way to act on one was to retype the address on Store -> Redirects. Phase 13's
 * "Rafi approves any discard list" had been open since the map was written, not
 * for want of a screen but for want of anywhere to PUT an approval —
 * redirect_decisions is that place.
 *
 * GROUPED BY THE QUESTION, NOT LISTED BY THE ROW, and that is the whole design.
 * Those 312 rows are not 312 questions; they are about eight questions asked a
 * few hundred times each, and the answer to "this shop answers this address
 * today — should the old one win?" is one answer for every category in the
 * list. So each question gets a heading, a count, and two buttons that answer
 * all of it — with the rows underneath, folded away, for when he wants to check
 * one before saying yes to four hundred.
 *
 * A QUESTION THAT CANNOT BE ANSWERED YES DOES NOT DRAW A YES BUTTON. Three of
 * them have no destination at all — a category stranded in a parent cycle, a
 * permalink for a row that was never imported, an address whose identity is in
 * its query string — and a loop has one that leads back to itself. The server
 * refuses those too (RedirectMap::decidable); this is what stops him finding
 * out by pressing.
 *
 * NOTHING HERE IS FINAL. "Undo" clears the answers and the rows go back to
 * being questions, which is why an approval is stored rather than written
 * straight into `redirects` and forgotten.
 */
function gpQuestions(u){
  const qs=(u.questions||[]).filter(q=>q.asking||q.accepted||q.rejected);
  if(!qs.length) return '';

  return '<div style="margin-top:14px"><b style="font-size:13px">What this needs you to decide</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:3px 0 0;max-width:680px">'
    +'Each block is one question, asked about every address under it. Answer the block, or open it and '
    +'answer a single address. Nothing is written to the shop until you press '
    +'&ldquo;Write&nbsp;redirect(s)&rdquo; above.</p>'
    +qs.map(gpQuestionBlock).join('')
    +'</div>';
}

function gpQuestionBlock(q){
  const answered=q.accepted+q.rejected;

  return '<div class="impfile" style="margin-top:10px">'
    +'<div class="between" style="align-items:flex-start;gap:10px">'
      +'<b style="font-size:12.5px">'+impEsc(q.heading)+'</b>'
      +'<span style="font-size:11.5px;color:var(--ink-soft);white-space:nowrap">'+impNum(q.asking)+' address(es)</span>'
    +'</div>'
    +(q.stale
      ?'<p style="font-size:11.5px;color:#b45309;margin:0">'+impNum(q.stale)+' of these you had already answered — '
        +'what they point at has changed since, so they are being asked again.</p>'
      :'')
    +(q.decidable
      ?''
      :'<p style="font-size:11.5px;color:var(--ink-soft);margin:0">There is nothing to say yes to here: these '
        +'addresses have nowhere on this shop to go. Say no to stop being asked, and fix the cause '
        +'(re-import, or correct the category tree) if you want them redirected.</p>')
    +'<div class="impmeta" style="margin-top:2px">'
      +(q.asking&&q.decidable
        ?'<button class="btn primary sm gpQAll" data-q="'+impEsc(q.question)+'" data-a="accept" '
          +'style="padding:3px 9px;font-size:11.5px">Yes to all '+impNum(q.asking)+'</button>':'')
      // Ghost, not the same green pill as Yes. Two buttons that look identical
      // beside a question is how somebody says no to four hundred addresses
      // meaning to say yes.
      +(q.asking
        ?'<button class="btn ghost sm gpQAll" data-q="'+impEsc(q.question)+'" data-a="reject" '
          +'style="padding:3px 9px;font-size:11.5px">No to all '+impNum(q.asking)+'</button>':'')
      +(answered
        ?'<span style="margin-left:auto">answered '+impNum(answered)+' ('+impNum(q.accepted)+' yes, '
          +impNum(q.rejected)+' no) <button class="btn ghost sm gpQClear" data-q="'+impEsc(q.question)+'" '
          +'style="padding:3px 9px;font-size:11px">Undo</button></span>':'')
    +'</div>'
    +(q.rows.length?gpQuestionRows(q):'')
    // The closing tag is not optional and its absence is not a typo you get
    // away with: the browser nests the next block inside this one, so eight
    // questions render as eight levels of indentation on a phone.
    +'</div>';
}

function gpQuestionRows(q){
  return '<details style="margin-top:6px"><summary style="cursor:pointer;font-size:11.5px;color:#2563eb">'
    +'Show '+(q.asking>q.rows.length?('the first '+impNum(q.rows.length)+' of '+impNum(q.asking)):impNum(q.rows.length))
    +'</summary>'
    +'<div class="impscroll" style="margin-top:8px"><table class="imptbl impstack">'
    +'<thead><tr><th>What</th><th>Old address</th><th>Would go to</th><th>Why it is asking</th><th></th></tr></thead><tbody>'
    +q.rows.map(r=>'<tr>'
      +'<td data-l="What">'+impEsc(r.subject)+'</td>'
      +'<td data-l="Old" style="font-family:var(--mono);font-size:11px">'+impEsc(r.source)+'</td>'
      +'<td data-l="New" style="font-family:var(--mono);font-size:11px">'+(r.target?impEsc(r.target):'<span style="color:var(--ink-soft);font-family:inherit">nowhere</span>')+'</td>'
      +'<td data-l="Why">'+impEsc(r.reason)+'</td>'
      +'<td data-l="" style="white-space:nowrap">'
        +(q.decidable&&r.target?'<button class="btn primary sm gpQOne" data-s="'+impEsc(r.source)+'" data-a="accept" style="padding:2px 8px;font-size:11px">Yes</button> ':'')
        +'<button class="btn ghost sm gpQOne" data-s="'+impEsc(r.source)+'" data-a="reject" style="padding:2px 8px;font-size:11px">No</button>'
      +'</td></tr>').join('')
    +'</tbody></table></div></details>';
}

function gbUrlsMediaCard(){
  if(!gbUM) return '<div class="card pad">'
    +'<b style="font-size:14px">Addresses &amp; pictures</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:680px">'
    +'The two things a row count cannot see: the old addresses people still follow, and whether the product '
    +'photographs are yours yet or still being served by the old site.</p>'
    +'<button class="btn" id="gbUMLoad" style="margin-top:10px">Have a look</button></div>';

  const u=gbUM.urls, m=gbUM.media;
  const hosts=(m.hosts||[]).filter(h=>!h.own);

  return '<div class="card pad">'
    +'<b style="font-size:14px">Addresses &amp; pictures</b>'
    +(gbUMMsg?'<div class="impbanner" style="margin-top:9px">'+gbUMMsg+'</div>':'')

    +'<div style="margin-top:12px"><b>Old addresses</b></div>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:3px 0 0;max-width:680px">'+u.note+'</p>'
    +gpSourceLine(gbUM.sources)
    +'<div class="impgrid" style="margin-top:8px">'
    +'<div class="impfile"><b>'+u.buckets.migrate.count+'</b><span>redirects to write</span></div>'
    +'<div class="impfile"><b>'+u.buckets.ask.count+'</b><span>still asking</span>'
      +((u.answers&&(u.answers.accepted||u.answers.rejected))
        ?'<span style="font-size:11px">you have answered '+impNum(u.answers.accepted+u.answers.rejected)
          +' — '+impNum(u.answers.accepted)+' yes, '+impNum(u.answers.rejected)+' no</span>':'')
      +'</div>'
    +'<div class="impfile"><b>'+u.buckets.discard.count+'</b><span>nothing to do</span></div>'
    +'</div>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:8px 0 0">'
    +u.diff.create+' new, '+u.diff.update+' corrected, '+u.diff.unchanged+' already right'
    +(u.diff.conflict?', <b>'+u.diff.conflict+' refused</b> — you pointed those somewhere yourself and this will not overrule you':'')
    +'</p>'
    +'<div style="margin-top:9px;display:flex;gap:8px;flex-wrap:wrap">'
    +'<a class="btn" href="'+impBase()+'/urls-media/map.csv">Download the whole map</a>'
    +'<button class="btn primary" id="gbUMWrite"'+(gbUMBusy?' disabled':'')+'>Write '+u.diff.create+' redirect(s)</button>'
    +'<button class="btn" id="gbUMUndo"'+(gbUMBusy?' disabled':'')+'>Undo</button>'
    +'</div>'
    /* UNDER the Write button, not above it. The panel's own copy says "until
       you press Write redirect(s) above", and a screen whose words point the
       wrong way is a screen somebody follows into the wrong order. */
    +gpQuestions(u)

    +'<div style="margin-top:16px"><b>Pictures</b></div>'
    +'<div class="impgrid" style="margin-top:8px">'
    +'<div class="impfile"><b>'+m.summary.present+'</b><span>on this site</span></div>'
    +'<div class="impfile"><b>'+m.summary.missing+'</b><span>named but not there</span></div>'
    +'<div class="impfile"><b>'+m.summary.remote+'</b><span>still on another site</span></div>'
    +'</div>'
    +(m.summary.remote
      ? '<p class="impwhy" style="max-width:680px">These load today and stop the day that site is switched off. '
        +'Copy <code>wp-content/uploads</code> across first — nothing below will re-point a row whose file is not here yet.</p>'
        +'<div style="margin-top:9px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
        +hosts.map(h=>'<label style="font-size:12.5px"><input type="checkbox" class="gbUMHost" value="'+h.host+'"> '
          +h.host+' <span style="color:var(--ink-soft)">('+h.references+')</span></label>').join('')
        +'<button class="btn primary" id="gbUMMedia"'+(gbUMBusy?' disabled':'')+'>Bring these across</button>'
        +'<button class="btn" id="gbUMMediaUndo"'+(gbUMBusy?' disabled':'')+'>Undo</button>'
        +'</div>'
      : '<p class="impwhy" style="max-width:680px">Nothing is being served by another site. This is the state to be in before the old shop is switched off.</p>')
    +'<div style="margin-top:9px"><button class="btn" id="gbUMRebase"'+(gbUMBusy?' disabled':'')+'>The shop has moved folder — re-spell the picture paths</button></div>'
    +ptOldLinksRow()
    +'</div>';
}

/* "Links to the old site" (Lane PT). Every <a href> into kbeautybliss.com
   inside a product or set description, an article, an HTML block or a
   category/brand description, pointed at this shop's page for the same thing.
   The import does it by itself now; this is for what was imported before, and
   it can be undone. Everything printed here is escaped: the samples quote the
   owner's own copy and addresses. */
function ptOldLinksRow(){
  const head='<div style="margin-top:16px" id="ptOldLinks"><b>Links to the old site</b></div>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:3px 0 0;max-width:680px">'
    +'Links inside product and set descriptions and tabs, articles, HTML blocks and category and brand descriptions that still open '
    +'<b>kbeautybliss.com</b> — like “Get premium <u>Face Cleansers</u> at unbeatable prices” at the end of a cleanser. '
    +'Each is pointed at this shop’s page for the same thing; the words, and everything else in the copy, stay as they are. '
    +'Every import now does this by itself. This is for what was imported before.</p>'
    +(ptOLMsg?'<div class="impbanner" style="margin-top:9px">'+ptOLMsg+'</div>':'');

  if(!ptOL) return head
    +'<div style="margin-top:9px"><button class="btn" id="ptOLPreview"'+(ptOLBusy?' disabled':'')+'>Preview</button></div>';

  const sm=ptOL.summary||{documents:0,links:0,samples:[]};
  const ap=ptOL.applied||{documents:0,links:0};

  return head
    +'<div class="impgrid" style="margin-top:8px">'
    +'<div class="impfile"><b>'+impNum(sm.links)+'</b><span>links still going to the old site</span></div>'
    +'<div class="impfile"><b>'+impNum(sm.documents)+'</b><span>descriptions and articles they are in</span></div>'
    +'<div class="impfile"><b>'+impNum(ap.links)+'</b><span>already pointed here (can be undone)</span></div>'
    +'</div>'
    +(sm.samples&&sm.samples.length
      ? '<div class="tablewrap" style="margin-top:9px"><table class="t" id="ptOLSamples" style="font-size:12px">'
        +'<thead><tr><th>Where</th><th>Link text</th><th>Goes to now</th><th>Will go to</th></tr></thead><tbody>'
        +sm.samples.map(r=>'<tr>'
          +'<td data-l="Where">'+impEsc(r.where)+'</td>'
          +'<td data-l="Text">'+impEsc(r.text)+'</td>'
          +'<td data-l="Now" style="font-family:var(--mono);font-size:11px;word-break:break-all">'+impEsc(r.from)+'</td>'
          +'<td data-l="Will" style="font-family:var(--mono);font-size:11px;word-break:break-all">'+impEsc(r.to)+'</td>'
          +'</tr>').join('')
        +'</tbody></table></div>'
        +(sm.links>sm.samples.length?'<p style="font-size:11.5px;color:var(--ink-soft);margin:5px 0 0">Showing '+sm.samples.length+' of '+impNum(sm.links)+'.</p>':'')
      : '<p class="impwhy" style="max-width:680px">No link anywhere in the copy still goes to the old site.</p>')
    +'<div style="margin-top:9px;display:flex;gap:8px;flex-wrap:wrap">'
    +'<button class="btn primary" id="ptOLFix"'+(ptOLBusy||!sm.links?' disabled':'')+'>Fix these links</button>'
    +'<button class="btn" id="ptOLPreview"'+(ptOLBusy?' disabled':'')+'>Preview again</button>'
    +(ap.links?'<button class="btn" id="ptOLUndo"'+(ptOLBusy?' disabled':'')+'>Undo</button>':'')
    +'</div>';
}

async function ptOLPost(action,say){
  ptOLBusy=true; impPaint();
  const r=await impApi('/urls-media/old-links',{method:'POST',body:JSON.stringify({action:action})});
  ptOLBusy=false;
  if(r.data&&r.data.ok){
    ptOLMsg=say?say(r.data):'';
    if(action==='preview') ptOL=r.data;
    else{
      const p=await impApi('/urls-media/old-links',{method:'POST',body:JSON.stringify({action:'preview'})});
      if(p.data&&p.data.ok) ptOL=p.data;
    }
  } else {
    ptOLMsg=r.status===403
      ? 'Only the shop owner can change these links.'
      : 'That did not work.'+(r.raw?' '+impEsc(r.raw):'');
  }
  impPaint();
}

async function gbUMLoad(){
  gbUMBusy=true; impPaint();
  const r=await impApi('/urls-media/status');
  gbUMBusy=false;
  if(r.data&&r.data.ok){ gbUM=r.data; gbUMMsg=''; }
  else gbUMMsg='Could not read the addresses and pictures.'+(r.raw?' '+r.raw:'');
  impPaint();
}

async function gbUMPost(path,body,say){
  gbUMBusy=true; impPaint();
  const r=await impApi(path,{method:'POST',body:JSON.stringify(body)});
  gbUMBusy=false;
  gbUMMsg=(r.data&&r.data.ok)?say(r.data):('That did not work.'+(r.raw?' '+r.raw:''));
  await gbUMLoad();
}

function gbUMWire(){
  const load=$('#gbUMLoad'); if(load) load.onclick=gbUMLoad;

  const write=$('#gbUMWrite');
  if(write) write.onclick=()=>gbUMPost('/urls-media/redirects',{action:'write'},
    d=>d.written+' redirect(s) written'+(d.conflict?', '+d.conflict+' left alone because you had pointed them yourself':'')+'.');

  const undo=$('#gbUMUndo');
  if(undo) undo.onclick=()=>{
    if(!confirm('Remove the redirects this screen wrote?\n\nOnly those — anything you wrote yourself, or have since re-pointed, is kept.')) return;
    gbUMPost('/urls-media/redirects',{action:'rollback'},d=>d.removed+' redirect(s) removed.');
  };

  const media=$('#gbUMMedia');
  if(media) media.onclick=()=>{
    const hosts=Array.from(document.querySelectorAll('.gbUMHost:checked')).map(b=>b.value);
    if(!hosts.length){ gbUMMsg='Tick the site the pictures are coming from first.'; impPaint(); return; }
    gbUMPost('/urls-media/media',{action:'apply',hosts:hosts},
      d=>d.rewritten+' picture(s) are now served by this shop'+(d.summary.absent?', '+d.summary.absent+' left alone because the file is not here yet':'')+'.');
  };

  const mundo=$('#gbUMMediaUndo');
  if(mundo) mundo.onclick=()=>{
    const hosts=Array.from(document.querySelectorAll('.gbUMHost:checked')).map(b=>b.value);
    if(!hosts.length){ gbUMMsg='Tick the site to put the pictures back on.'; impPaint(); return; }
    gbUMPost('/urls-media/media',{action:'restore',hosts:hosts},d=>d.restored+' picture(s) put back.');
  };

  /*
   * The question buttons. Delegated over the rendered nodes rather than bound
   * by id, because there is one per question and one per row and impPaint()
   * rebuilds all of them on every answer.
   *
   * THE REQUEST NAMES ADDRESSES, NEVER DESTINATIONS. The server re-derives the
   * map and refuses an address it is not asking about, so the worst this can do
   * is approve something the shop was already proposing — see
   * UrlsMediaApiController::decisions().
   */
  document.querySelectorAll('.gpQAll').forEach(b=>{
    b.onclick=()=>{
      const yes=b.dataset.a==='accept';
      if(!confirm(yes
        ?'Say yes to every address under this question?\n\nNothing is written yet — they move into "redirects to write", and you press Write when you are ready.'
        :'Say no to every address under this question?\n\nThey stop being asked about. Undo puts them back.')) return;
      gbUMPost('/urls-media/decisions',{action:b.dataset.a,question:b.dataset.q},
        d=>d.recorded+' address(es) answered'+(d.refused.length?', '+d.refused.length+' could not be':'')+'.');
    };
  });

  document.querySelectorAll('.gpQOne').forEach(b=>{
    b.onclick=()=>gbUMPost('/urls-media/decisions',{action:b.dataset.a,sources:[b.dataset.s]},
      d=>d.recorded?('Answered '+b.dataset.s+'.'):('That could not be answered.'+(d.refused[0]?' '+d.refused[0]:'')));
  });

  document.querySelectorAll('.gpQClear').forEach(b=>{
    b.onclick=()=>{
      if(!confirm('Undo your answers to this question?\n\nThe addresses go back to being asked about. Redirects already written stay until you press Undo on the redirects themselves.')) return;
      gbUMPost('/urls-media/decisions',{action:'clear',question:b.dataset.q},
        d=>d.cleared+' answer(s) undone.');
    };
  });

  const olPreview=$('#ptOLPreview');
  if(olPreview) olPreview.onclick=()=>ptOLPost('preview',null);

  const olFix=$('#ptOLFix');
  if(olFix) olFix.onclick=()=>{
    if(!confirm('Point every link to the old site at this shop?\n\nOnly the address inside each link changes. Undo puts every one back.')) return;
    ptOLPost('apply',d=>impNum(d.links)+' link(s) in '+impNum(d.documents)+' description(s) and article(s) now go to this shop.');
  };

  const olUndo=$('#ptOLUndo');
  if(olUndo) olUndo.onclick=()=>{
    if(!confirm('Put the old-site links back?\n\nOnly links still exactly as this screen left them; anything edited since is kept. The next import fixes them again.')) return;
    ptOLPost('restore',d=>impNum(d.links)+' link(s) put back'+(d.kept?', '+impNum(d.kept)+' left alone because they were edited since':'')+'.');
  };

  const rebase=$('#gbUMRebase');
  if(rebase) rebase.onclick=()=>gbUMPost('/urls-media/media',{action:'rebase-apply'},
    d=>d.rewritten?(d.rewritten+' picture path(s) re-spelled for this shop\'s folder.'):'Every picture path already matches this shop\'s folder.');
}

/* ------------------------------------------------------------------- wiring */

function impWire(){
  const drop=$('#impDrop'), input=$('#impFileInput');
  if(drop&&input){
    drop.onclick=()=>input.click();
    drop.ondragover=e=>{e.preventDefault();drop.classList.add('hot');};
    drop.ondragleave=()=>drop.classList.remove('hot');
    drop.ondrop=e=>{e.preventDefault();drop.classList.remove('hot');impUpload(e.dataTransfer.files);};
    input.onchange=()=>impUpload(input.files);
  }

  document.querySelectorAll('.impforget').forEach(b=>{
    b.onclick=async()=>{
      b.disabled=true;
      await impApi('/import/forget',{method:'POST',body:JSON.stringify({entity:b.dataset.e})});
      await impRefresh(); impPaint();
    };
  });

  const forgetAll=$('#impForgetAll');
  if(forgetAll) forgetAll.onclick=async()=>{
    if(!confirm('Remove every uploaded file from this card?\n\nOnly the files go. Nothing you have already imported is touched — no product, order or customer.')) return;
    forgetAll.disabled=true;
    const r=await impApi('/import/forget',{method:'POST',body:JSON.stringify({entity:'all'})});
    if(r.status!==200){ impMsg=(r.data&&r.data.message)||'Those files could not be removed.'; impMsgKind='warn'; }
    await impRefresh(); impPaint();
  };

  document.querySelectorAll('.impopt').forEach(b=>{
    b.onclick=()=>{
      if(b.disabled) return;
      const v=b.dataset.v;
      impChoice[b.dataset.k]=(v==='true')?true:((v==='false')?false:v);
      impPaint();
    };
  });

  const tz=$('#impTz'); if(tz) tz.onchange=()=>{ impChoice.timezone=tz.value; };

  const preview=$('#impPreview');
  if(preview) preview.onclick=()=>impBegin('preview');

  const run=$('#impRun');
  if(run) run.onclick=()=>{
    if(!confirm('Import for real. This writes to your store.\n\nIt is safe to run again afterwards — rows are matched on their WooCommerce id, so nothing is duplicated. Continue?')) return;
    impBegin('live');
  };

  const cont=$('#impContinue'); if(cont) cont.onclick=()=>{ impMsg=''; impDrive(); };
  const pause=$('#impPause'); if(pause) pause.onclick=()=>{ impRunning=false; impPaint(); };
  /* ▲ "Stop" WAS DRAWN AND BOUND TO NOTHING. It sits on the SAME LINE of markup
     as Continue, which is bound immediately above, and beside Pause, which is
     bound on the line above this one -- so the one button in that banner that
     reaches the server was the one nobody wired. POST /admin-api/import/stop
     has been live and capability-mapped the whole time and was called only by
     the test suite. The background run has its own working Stop through
     /import/background-control; this is the FOREGROUND banner's, and a shopper
     import that had gone wrong could be paused in the browser but never put
     down on the server.

     It stops the browser loop FIRST and repaints from the status the server
     returns, rather than calling impDrive() -- driving again is what Continue
     means, and a Stop that resumes the run is worse than one that does nothing,
     because the owner believes the run is over. Found by Lane QA. */
  const stop=$('#impStop'); if(stop) stop.onclick=async()=>{
    impRunning=false;
    try{ await impApi('/import/stop',{method:'POST'}); }catch(e){}
    await impRefresh();
    impPaint();
  };

  /* Lane GO. Hands the run to the server and opens the live page, which is
     where Pause, Stop and the real bars then live. The browser loop is stopped
     first: leaving it going would be a second driver, and the chain's baton
     exists precisely so there is only ever one. A refusal is shown here rather
     than on the page, because a host that will not call itself means the owner
     stays on THIS screen and keeps pressing Continue. */
  const bg=$('#impBackground');
  if(bg) bg.onclick=async()=>{
    impRunning=false;
    const r=await impApi('/import/background',{method:'POST',body:'{}'});
    if(!r.data||r.data.ok===false){
      impMsg=(r.data&&r.data.message)||'Could not hand this over to the server.'; impMsgKind='bad'; impPaint(); return;
    }
    window.open(impBase()+'/import/background-page','_blank');
    impMsg='The server is carrying this on by itself. You can close this tab.'; impMsgKind=''; impPaint();
  };

  gbUMWire();

  const reset=$('#impReset');
  if(reset) reset.onclick=async()=>{
    if(!confirm('Forget how far the import got, so the next run reads every file from its first row?\n\nNothing already imported is deleted — those rows will simply be re-presented and reported as unchanged.')) return;
    impRunning=false;
    await impApi('/import/reset',{method:'POST'});
    await impRefresh(); impPaint();
  };
}

async function impUpload(fileList){
  const files=Array.prototype.slice.call(fileList||[]);
  if(!files.length) return;

  const box=$('#impUploadMsg');
  if(box) box.innerHTML='<div class="impnote">Uploading '+files.length+' file'+(files.length===1?'':'s')+'…</div>';

  const body=new FormData();
  files.forEach(f=>body.append('files[]',f));

  const r=await impApi('/import/upload',{method:'POST',body:body});

  if(!r.data){
    // No JSON at all: almost always the file being larger than the server's
    // own limit, which PHP refuses before any of our code runs.
    impMsg='The server would not take that upload. It is usually a file larger than the limit shown above. '+(r.raw||'');
    impMsgKind='bad';
    await impRefresh(); impPaint(); return;
  }

  if(r.data.status) impState=r.data.status;

  const refused=(r.data.refused||[]);
  if(refused.length){
    impMsg=refused.map(x=>x.message).join('  ·  ');
    impMsgKind='bad';
  }else{
    impMsg=''; impMsgKind='';
    toast('Uploaded');
  }

  impPaint();
}

async function impBegin(mode){
  const c=impChoice||impState.defaults;
  const restartEl=$('#impRestart');

  const r=await impApi('/import/start',{method:'POST',body:JSON.stringify({
    mode:mode,
    guests:c.guests,
    order_number:c.order_number,
    timezone:c.timezone,
    adopt_by_slug:!!c.adopt_by_slug,
    restart:!!(restartEl&&restartEl.checked),
    force:true
  })});

  if(!r.data||!r.data.ok){
    impMsg=(r.data&&r.data.message)||'Could not start.'; impMsgKind='bad'; impPaint(); return;
  }

  impState=r.data.status;
  impRows=impState.limits.default_step_rows;
  impFails=0; impMsg=''; impMsgKind='';
  impDrive();
}

/*
 * The loop. See the note at the top of this section for why it is shaped like
 * this; what follows is only the arithmetic.
 */
async function impDrive(){
  if(impRunning) return;
  impRunning=true; impFails=0;
  impPaint();

  while(impRunning){
    const t0=performance.now();
    let r;

    try{ r=await impApi('/import/step',{method:'POST',body:JSON.stringify({rows:impRows})}); }
    catch(e){ r={status:0,data:null,raw:String(e&&e.message||e)}; }

    const took=performance.now()-t0;

    // 409: the previous request is still running on the server. That is what a
    // proxy timeout leaves behind — the browser gave up, PHP did not — so the
    // only correct move is to wait for it, never to start a second one.
    if(r.status===409){
      impFails++;
      if(impFails>20){ impRunning=false; impMsg='Something else is still working on this import. Reload in a minute.'; impMsgKind='warn'; impPaint(); return; }
      impMsg='Waiting for the previous piece to finish on the server…'; impMsgKind='warn'; impPaint();
      await new Promise(res=>setTimeout(res,5000));
      continue;
    }

    if(!r.data||r.status>=500||r.status===0){
      // The slice was too big for this host, or the network dropped. Nothing is
      // lost: every batch that committed advanced the checkpoint with it. Halve
      // and try again, which is how this finds a host's real ceiling without
      // being told what it is.
      impFails++;
      if(impRows>impState.limits.min_step_rows&&impFails<=6){
        impRows=Math.max(impState.limits.min_step_rows,Math.floor(impRows/2));
        impMsg='That piece was too big for this server, so it is trying a smaller one ('+impNum(impRows)+' rows). Nothing was lost.';
        impMsgKind='warn'; impPaint();
        continue;
      }
      impRunning=false;
      impMsg='The server stopped responding. Nothing already imported was lost — press Continue to pick up where it stopped.'
        +(r.raw?' ('+r.raw+')':'');
      impMsgKind='bad';
      await impRefresh(); impPaint(); return;
    }

    impFails=0;
    if(r.data.status) impState=r.data.status;

    if(r.data.ok===false){
      impRunning=false;
      impMsg=r.data.message||'That piece could not be done.';
      impMsgKind=r.data.needs_restart?'warn':'bad';
      if(r.data.needs_restart){
        impMsg+='  Tick “Start every file again from its first row” above and press Import again.';
      }
      impPaint(); return;
    }

    // Aim the next piece at IMP_STEP_TARGET_MS. Grow slowly, shrink fast: being
    // half as quick as possible costs minutes, and being one step too greedy
    // costs a timeout.
    if(took<IMP_STEP_TARGET_MS*0.6) impRows=Math.min(impState.limits.max_step_rows,Math.ceil(impRows*1.6));
    else if(took>IMP_STEP_TARGET_MS*1.5) impRows=Math.max(impState.limits.min_step_rows,Math.floor(impRows/2));

    impMsg=''; impMsgKind='';
    impPaint();

    if(!impState.run||impState.run.status!=='running'){
      impRunning=false;
      impPaint();
      toast(impState.run&&impState.run.mode==='preview'?'Preview finished':'Import finished');
      return;
    }
  }

  impPaint();
}

/* ===================== PRODUCT LABELS ===================== */
const OCCASIONS=[['none','None'],['eid','Eid'],['ramadan','Ramadan'],['xmas','Christmas'],['ny','New Year'],['bf','Black Friday'],['summer','Summer Sale']];
const LABELS=[
 {name:'Eid Mega Sale',type:'image',occ:'eid',pos:'tl',size:'M',apply:'On-sale products',excl:'—',status:'Active',color:'#1b9e77',ic:'🌙'},
 {name:'Up to 30% Off',type:'text',occ:'none',pos:'tr',size:'S',apply:'Categories: Serums, Sun Care',excl:'2 products',status:'Active',color:'#d6455a',ic:'%'},
 {name:'Christmas Gift',type:'image',occ:'xmas',pos:'bl',size:'M',apply:'Category: Sets & Bundles',excl:'—',status:'Scheduled · Dec 1–26',color:'#c13e63',ic:'🎁'},
 {name:'Bestseller',type:'text',occ:'none',pos:'tr',size:'S',apply:'Best Sellers category',excl:'—',status:'Active',color:'#0f8f4b',ic:'★'}
];
const POSN={tl:'Top-left',tc:'Top',tr:'Top-right',ml:'Left',mc:'Center',mr:'Right',bl:'Bottom-left',bc:'Bottom',br:'Bottom-right'};
function posStyle(pos){const m='9px';return ({tl:`top:${m};left:${m}`,tc:`top:${m};left:50%;transform:translateX(-50%)`,tr:`top:${m};right:${m}`,ml:`top:50%;left:${m};transform:translateY(-50%)`,mc:`top:50%;left:50%;transform:translate(-50%,-50%)`,mr:`top:50%;right:${m};transform:translateY(-50%)`,bl:`bottom:${m};left:${m}`,bc:`bottom:${m};left:50%;transform:translateX(-50%)`,br:`bottom:${m};right:${m}`}[pos])||`top:${m};left:${m}`;}
function sizeStyle(s){return s==='L'?'font-size:12px;padding:6px 11px':s==='M'?'font-size:11px;padding:5px 9px':'font-size:10px;padding:4px 8px';}


/* ---------- Store · Delivery & Shipping ----------
   Edits the shipping_methods rows the storefront already reads. The zones and
   their countries are seeded and are not editable here yet — that is a larger
   screen and would have been guesswork; what was actually missing was any way
   to change the charge and the free-delivery threshold. */
let SH=null;

function shBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/shipping'; }
/* SHOWS A LEGACY RATE HONESTLY. Math.round() printed a stored 1,250-fil rate
   as "13" in a box the operator reads as dirhams — the screen said AED 13 while
   the shop charged AED 12.50, with nothing anywhere to say so. Every rate this
   screen can now SAVE is whole, so this only fires on a rate that predates the
   policy, which is exactly when he needs to see the real figure. */
function shMoney(fils){
  const v = Number(fils||0) / 100;
  return Number.isInteger(v) ? v : v.toFixed(2);
}

async function renderShipping(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Delivery &amp; Shipping</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(shBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    SH=await r.json();
    await loadExtended();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the delivery settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderShipping()">Retry</button></div></div>`;
    return;
  }
  paintShipping();
}
function shFind(id){ for(const z of SH.zones){ const m=z.methods.find(x=>x.id===id); if(m) return m; } return null; }

function shMethod(m){
  const free = m.type==='free_shipping';
  return `<div class="shrow${m.enabled?'':' off'}">
    <span class="ectog${m.enabled?' on':''}" data-shon="${m.id}" role="switch" aria-checked="${m.enabled}" tabindex="0"></span>
    <div class="shlbl">
      <input type="text" value="${escAttr(m.title)}" data-shtitle="${m.id}" maxlength="60">
      <span>${free?'Applies once the order reaches the amount below.':'Charged when free delivery does not apply.'}</span>
    </div>
    <span class="shamt">
      <i>${escHtml(SH.currency)}</i>
      ${free
        ? `<input type="number" min="0" step="1" value="${shMoney(m.min_amount)}" data-shmin="${m.id}">`
        : `<input type="number" min="0" step="1" value="${shMoney(m.cost)}" data-shcost="${m.id}">`}
      <u>${free?'and over':'per order'}</u>
    </span>
  </div>`;
}

/* Three baskets against the zone's own numbers, so the rule reads as a sentence
   rather than two figures to reconcile. */
function shPreview(z){
  const flat=z.methods.find(m=>m.type!=='free_shipping'&&m.enabled);
  const free=z.methods.find(m=>m.type==='free_shipping'&&m.enabled);
  const t=free?Number(free.min_amount||0):null;
  const c=flat?Number(flat.cost||0):0;
  const line=(fils)=>{
    const isFree = t!==null && fils>=t;
    return `<div class="shpv-r${isFree?' yes':''}"><b>${SH.currency} ${shMoney(fils)}</b>
      <span>${isFree?'Free delivery':(flat?`${SH.currency} ${shMoney(c)} delivery`:'No delivery method')}</span></div>`;
  };
  if(t===null && !flat) return '<p class="mmpv-note">No method is switched on for this zone, so nothing can be delivered to it.</p>';
  const at = t===null ? 20000 : t;
  return `<div class="shpv">${line(Math.max(0,at-100))}${line(at)}${line(at+50000)}</div>
    <p class="mmpv-note">${t===null?'No free-delivery method is on for this zone.':`Free from ${SH.currency} ${shMoney(t)}.`}</p>`;
}

let SHTAB='zones';

function shIntro(){
  const codes=[...new Set(SH.zones.flatMap(z=>z.locations))];
  return `<p class="mdesc" style="margin:0 0 10px">What delivery costs, and the order value at which it becomes free.
    <b>${codes.length?escHtml(codes.join(', ')):'Your'} delivery charges are set on the <u>Zones</u> tab below</b> —
    that is where UAE and the Gulf countries live today. <u>Extended</u> is only for adding countries
    beyond those.</p>`;
}

function paintShipping(){
  if(SHTAB==='extended'){ paintExtended(); return; }
  if(SHTAB==='gift'){ paintGift(); return; }
  if(SHTAB==='lines'){ paintDeliveryLines(); return; }
  $('#content').innerHTML=`<div class="wrap ecwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Delivery &amp; Shipping</h2>
      ${shIntro()}</div>
    ${shTabs()}
    ${SH.zones.map(z=>`<div class="card mdcard">
      <div class="mmhd"><b>${escHtml(z.name)}</b><span>${z.locations.length} ${z.locations.length===1?'country':'countries'} · ${escHtml(z.locations.join(', '))}</span></div>
      <div class="shgrid">
        <div class="shbody">${z.methods.map(shMethod).join('')}</div>
        <div class="shpv-wrap" id="shpv-${z.id}">${shPreview(z)}</div>
      </div></div>`).join('')}
    <div class="ecsave">
      <span class="ecdirty" id="shDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="shSave">Save changes</button>
    </div>
  </div>`;
  bindShipping();
}

function bindShTabs(){
  // Called from both bindShipping() and bindExtended(): whichever view is on
  // screen, its copy of the Zones/Extended buttons needs a handler, since
  // paintShipping() re-renders the tab strip fresh every time.
  $$('[data-shtab]').forEach(b=>b.onclick=()=>{ SHTAB=b.dataset.shtab; paintShipping(); });
}

function bindShipping(){
  bindShTabs();
  const dirty=()=>{ const d=$('#shDirty'); if(d) d.style.visibility='visible'; };
  const repaint=(id)=>{
    const z=SH.zones.find(z=>z.methods.some(m=>m.id===id));
    const el=$('#shpv-'+z.id); if(el) el.innerHTML=shPreview(z);
  };

  $$('[data-shon]').forEach(el=>el.onclick=()=>{
    const m=shFind(Number(el.dataset.shon)); if(!m) return;
    m.enabled=!m.enabled; dirty(); paintShipping();
  });
  $$('[data-shtitle]').forEach(el=>el.oninput=()=>{
    const m=shFind(Number(el.dataset.shtitle)); if(m){ m.title=el.value; dirty(); }
  });
  $$('[data-shcost]').forEach(el=>el.oninput=()=>{
    const id=Number(el.dataset.shcost), m=shFind(id);
    if(m){ m.cost=Math.max(0,Math.round(Number(el.value)||0))*100; dirty(); repaint(id); }
  });
  $$('[data-shmin]').forEach(el=>el.oninput=()=>{
    const id=Number(el.dataset.shmin), m=shFind(id);
    if(m){ m.min_amount=Math.max(0,Math.round(Number(el.value)||0))*100; dirty(); repaint(id); }
  });

  const save=$('#shSave');
  if(save) save.onclick=async()=>{
    const methods=[];
    SH.zones.forEach(z=>z.methods.forEach(m=>methods.push(
      {id:m.id,title:m.title,enabled:m.enabled,cost:m.cost,min_amount:m.min_amount})));
    save.disabled=true;
    try{
      const r=await fetch(shBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({methods})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#shDirty').style.visibility='hidden';
      toast('Delivery saved');
    }catch(e){ toast('Could not save: '+e.message,'bad'); }
    finally{ save.disabled=false; }
  };
}


/* ---------- Store · Delivery & Shipping · Extended ----------
   Per-country charges, a country list, and detection. Off by default; while off
   it changes nothing and the zone rules answer as they always have. */
let XD=null;

function xdBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/extended-delivery'; }
function shTabs(){
  // A short caption under the strip, not just the bare word "Zones" — the
  // one thing that was easy to skim past when looking for where the Gulf
  // charges live.
  const lineCount = (DLINES && DLINES.rows) ? DLINES.rows.length : 0;
  /* TWO OF THESE TABS WRITE ABOUT DELIVERY TIME, AND THEY ARE NOT THE SAME
     THING. Three separate reviews read them as one duplicated idea, so each
     hint now names the other one and says what the difference is:

       Delivery lines   a WHOLE SENTENCE, any country, shown under Place order
                        and on the home page, the product page and the order
                        confirmation.
       Extended         a DURATION of a few words, printed after the fixed words
                        "Arrives in" in the delivery options at checkout, only
                        for countries added on that tab — which never includes a
                        zone country, so the Gulf cannot have one at all.

     Neither replaces the other, and a country can carry both. The Delivery
     lines tab shows the Extended estimate beside the row when it does, so there
     is one place to read both sentences a shopper gets. */
  const hints = {
    zones: 'Your current delivery charges — including the Gulf countries — are the cards below.',
    gift: 'An optional gift-wrap tick at checkout, and what it costs.',
    lines: 'The sentence a shopper reads under Place order, written once per country. The short “Arrives in” wording for countries you added under Extended is separate, and is on that tab.',
    extended: 'Countries added here on top of your zones. Their “Arrives in” wording is a few words shown at checkout — the full delivery sentence is on the Delivery lines tab.'
  };
  return `<div class="ectabs">
    <button class="ectab${SHTAB==='zones'?' on':''}" data-shtab="zones">Zones</button>
    <button class="ectab${SHTAB==='extended'?' on':''}" data-shtab="extended">Extended${XD&&XD.on?'<span class="ecn">on</span>':''}</button>
    <button class="ectab${SHTAB==='gift'?' on':''}" data-shtab="gift">Gift wrapping${GIFT&&GIFT.gift_enabled==='1'?'<span class="ecn">on</span>':''}</button>
    <button class="ectab${SHTAB==='lines'?' on':''}" data-shtab="lines">Delivery lines${lineCount?'<span class="ecn">'+lineCount+'</span>':''}</button>
  </div>
  <p class="ectabs-hint">${hints[SHTAB]||hints.zones}</p>`;
}

/* ---------- Store · Delivery & Shipping · Gift wrapping ----------
 *
 * Lives here rather than on Business Details because it is a fulfilment
 * charge: it rides the same order total as delivery, and the field it
 * controls sits in the Delivery step of checkout.
 *
 * Self-contained on purpose. SETTINGS, sval() and loadSettings() belong to
 * the Business Details block, which is a different script scope -- reaching
 * for them from here throws a ReferenceError and takes the whole Delivery &
 * Shipping screen down with it. This block talks to /admin-api/settings
 * directly, the same way loadExtended() talks to its own endpoint.
 */
let GIFT=null;

/* Declared beside GIFT and XD rather than with the block that uses it, because
   shTabs() below reads its length for the tab's count badge and the three tab
   states belong in one place. */
let DLINES=null;

function giftBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/settings'; }

function giftCookie(name){
  var m = document.cookie.match(new RegExp('(^| )'+name+'=([^;]+)'));
  return m ? decodeURIComponent(m[2]) : '';
}

async function loadGift(){
  try{
    const r = await fetch(giftBase(), {credentials:'same-origin', headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    const d = await r.json();
    GIFT = d.settings || {};
  }catch(e){ GIFT = {}; }
  return GIFT;
}

async function paintGift(){
  if(!GIFT) await loadGift();

  // Read straight from the stored value -- no default guessed here. A
  // migration seeds the row, so this screen and the storefront are looking at
  // the same string. Guessing a default in two places is what made the tab
  // read Off above a checkout that was showing the tick.
  const on = GIFT.gift_enabled === '1';
  const fee = GIFT.gift_fee ? (parseInt(GIFT.gift_fee, 10) / 100) : 0;

  $('#content').innerHTML=`<div class="wrap ecwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Delivery &amp; Shipping</h2>
      ${shIntro()}</div>
    ${shTabs()}
    <div class="card mdcard">
      <div class="mmhd"><b>Gift wrapping</b><span>Shown in the Delivery step at checkout</span></div>
      <div style="padding:16px 18px 18px">
        <p class="mdesc" style="margin:0 0 14px">With this on, shoppers see a <b>This order is a gift</b> tick and a
          600-character message printed on the card. The fee below is added to the order total, alongside any
          cash-on-delivery fee. Set it to 0 to offer wrapping free.</p>
        <div class="g2">
          <div class="fld"><label>Offer gift wrapping</label>
            <select id="gf_on"><option value="0"${on?'':' selected'}>Off</option><option value="1"${on?' selected':''}>On</option></select>
          </div>
          <div class="fld"><label>Gift wrapping fee (AED)</label>
            <input id="gf_fee" type="number" step="1" min="0" value="${fee}">
          </div>
        </div>
      </div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="gfDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="gfSave">Save changes</button>
    </div>
  </div>`;

  bindShTabs();

  const dirty=()=>{ const d=document.getElementById('gfDirty'); if(d) d.style.visibility='visible'; };
  ['gf_on','gf_fee'].forEach(id=>{ const el=document.getElementById(id); if(el) el.onchange=dirty; });

  document.getElementById('gfSave').onclick=async function(){
    const val=id=>{ const el=document.getElementById(id); return el?el.value:''; };
    const payload={
      gift_enabled: val('gf_on'),
      // fils, like cod_fee and delivery_flat, so Money::format handles it and
      // no screen has to remember which unit this one uses.
      gift_fee: String(Math.round((parseFloat(val('gf_fee'))||0)*100))
    };
    try{
      const r = await fetch(giftBase(), {
        method:'PUT',
        credentials:'same-origin',
        headers:{
          'Accept':'application/json',
          'Content-Type':'application/json',
          'X-XSRF-TOKEN': giftCookie('XSRF-TOKEN')
        },
        body: JSON.stringify({settings: payload})
      });
      if(!r.ok) throw new Error(r.status);
      const res = await r.json().catch(()=>({}));
      // The endpoint answers ok even when it skipped every key, which is how
      // this screen reported success while writing nothing. Trust the counts.
      if(res.rejected && res.rejected.length){
        toast('Not saved: '+res.rejected.join(', ')+' \u2014 the server rejected these keys', 'bad');
        return;
      }
      if(res.saved === 0){ toast('Nothing was saved \u2014 check the server log'); return; }
      Object.assign(GIFT, payload);
      const d=document.getElementById('gfDirty'); if(d) d.style.visibility='hidden';
      toast('Gift wrapping saved');
      GIFT = null;
      paintGift();
    }catch(e){ toast('Save failed \u2014 check connection','bad'); }
  };
}

/* ---------- Store \u00b7 Delivery & Shipping \u00b7 Delivery lines ----------
 *
 * THE SCREEN THE CODE ALREADY PROMISED AND DID NOT HAVE.
 *
 * App\Support\DeliveryLine reads a setting called `delivery_texts` \u2014 a row per
 * country, each with the sentence shoppers there read under Place order \u2014 and
 * an explicit row wins for any country. That was the documented escape hatch
 * for the Gulf, and it had NO WRITER anywhere in the codebase: no form, no
 * seeder, no validation rule. The owner was told he could write a line for
 * Saudi Arabia and there was nowhere to type it.
 *
 * WHY IT LIVES HERE. It is a delivery promise about a destination, so it
 * belongs beside the charge for that destination, on the screen the owner
 * already opens to change what delivery costs \u2014 the same reasoning that put
 * Gift wrapping on this screen rather than on Business Details.
 *
 * Self-contained, for the reason the Gift block spells out: SETTINGS, sval()
 * and loadSettings() belong to the Business Details block, which is a different
 * script scope, and reaching for them from here throws a ReferenceError that
 * takes the whole Delivery & Shipping screen down with it.
 *
 * NOTHING IS SEEDED AND NOTHING IS SUGGESTED. No delivery window outside the
 * UAE has ever been measured, so this screen opens empty and every country goes
 * on behaving exactly as it does today until the owner types a sentence
 * himself. A helpful-looking placeholder reading "5\u20138 days to Saudi Arabia"
 * would be the untruth DeliveryLine's whole doc comment exists to prevent, and
 * a placeholder is one Tab key away from becoming a saved value.
 */
function dlBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/settings'; }

/* KBB_COUNTRY_NAMES, KBB_HEADER_ICONS and KBB_PRESETS are declared in the
   small script just before this block (Lane AP): they are the only Blade output
   this block had, and without them it is static, cacheable bytes. */

/* ---------- One-click presets for a per-country table (Lane CY) -----------
 *
 * THE OWNER'S REQUEST, and the shape of the answer. "i want you to make the
 * deliver lines automatic same in tax. like user just just click to select."
 * Two screens in this console ask him to fill a table with one row per country.
 * This is the bar that fills one of those tables from a set of suggestions —
 * all of them at once, or one country at a time — and it is written once for
 * both, so the delivery screen and the tax screen behave identically rather
 * than similarly.
 *
 * DELIBERATELY GENERIC AND DELIBERATELY DUMB. It knows nothing about delivery,
 * nothing about tax, and nothing about how either table is stored. It is handed
 * a group key, a way to ask what a country's row currently says, and a way to
 * put a value into one. A caller that has a table of country rows can use it;
 * everything that would differ between two such tables is a callback or lives
 * in CountryPresets on the PHP side.
 *
 * HOW THE TAX TAB USES IT, exactly:
 *
 *   1. Register a 'tax' group in App\Support\CountryPresets::GROUPS, with its
 *      own region, its own template and its own note.
 *   2. Add it to the KBB_PRESETS map emitted above.
 *   3. Render pstBarHtml('tax', {id:'taxPresets', filled:codeThatReturnsTheRowsValue})
 *      into the tab, and call pstBind('taxPresets', {...}) after painting.
 *
 * It does not need to touch this block, and this block does not need to know
 * the tax tab exists.
 *
 * IT FILLS THE FORM. IT DOES NOT SAVE. Every chip here is a suggestion: it
 * writes into the caller's in-memory rows and marks the screen dirty, and the
 * value reaches the database only when the owner presses the screen's own Save
 * button, exactly like a value he typed. That is not a nicety — the figures in
 * a delivery group are a PROMISE the shop then has to keep, and a screen that
 * committed one on a click would be making it on his behalf. The note under
 * the chips says so on its face.
 *
 * AUTOMATIC NAMES. A group's suggestion may be a sentence with {country} in it
 * — CountryTemplate's placeholder, the same convention as {rate} in the VAT
 * label. A chip normally fills in the FINISHED sentence, so the owner reads and
 * can edit the exact words that will print. The checkbox switches it to filling
 * in the sentence with the placeholder left in, for an owner who would rather
 * the wording follow whichever country the row is set to. Both are visible in
 * the box; neither is hidden behind a save.
 */

/* Which groups are currently filling in the placeholder form. Per group, so
   two tables on two screens do not share one checkbox. */
var PST_AUTO = {};

function pstGroup(key){ return KBB_PRESETS[key] || {key:key, label:'', note:'', template:'', rows:[]}; }

/* The value a chip for this country would write: the finished sentence, or the
   template with {country} still in it when the owner asked for that. */
function pstValue(key, row){
  return PST_AUTO[key] ? String(row.template||'') : String(row.value||'');
}

/* Which countries this bar would change, given what the table holds now.
   `filled` answers with the row's current text, or null when it has no row. */
function pstPending(key, filled){
  return pstGroup(key).rows.filter(function(row){
    var now = filled(row.code);
    return now === null || now === undefined || String(now) !== pstValue(key, row);
  });
}

function pstBarHtml(key, opts){
  var g = pstGroup(key), filled = opts.filled, id = opts.id;
  if(!g.rows.length) return '';

  var pending = pstPending(key, filled);
  var anyDynamic = g.rows.some(function(r){ return r.dynamic; });

  var chips = g.rows.map(function(row){
    var now = filled(row.code), want = pstValue(key, row);
    var has = !(now === null || now === undefined);
    var same = has && String(now) === want;
    /* The WHOLE sentence on the chip. The owner is agreeing to these words, so
       he reads them here rather than after they land in the box. */
    var body = '<b>'+escHtml(row.name)+'</b> <i>'+escHtml('“'+want+'”')+'</i>';
    if(same){
      /* "already in the box", not "already saved" — the two differ until he
         presses Save and the chip must not blur them. */
      return '<button type="button" class="pst-chip" aria-disabled="true" disabled '+
        'title="'+escAttr('Already in the box below for '+row.name)+'" '+
        'data-pst-code="'+escAttr(row.code)+'">✓ '+body+'</button>';
    }
    return '<button type="button" class="pst-chip" data-pst-code="'+escAttr(row.code)+'" '+
      'title="'+escAttr((has?'Replace the line for ':'Fill in ')+row.name)+'">'+
      (has?'↺ ':'+ ')+body+'</button>';
  }).join('');

  return '<div class="pst-bar" id="'+escAttr(id)+'" data-pst-group="'+escAttr(key)+'">'+
    '<div class="pst-hd"><span class="pst-t">'+escHtml(g.label)+'</span>'+
      '<button type="button" class="pst-all" data-pst-all="1"'+(pending.length?'':' disabled')+'>'+
        (pending.length ? 'Fill in all '+pending.length+' &rarr;' : 'All '+g.rows.length+' are in the boxes') +
      '</button></div>'+
    '<div class="pst-chips">'+chips+'</div>'+
    (anyDynamic
      ? '<label class="pst-auto"><input type="checkbox" data-pst-auto="1"'+(PST_AUTO[key]?' checked':'')+'>'+
        /* The words are ONE flex item. Left as bare text nodes beside the <b>,
           the label became three columns on a 390px phone and the sentence read
           down the page in pieces. */
        '<span>Keep the country name automatic — write <b>&#123;country&#125;</b> instead of the name, '+
        'so the sentence follows whichever country the row is set to.</span></label>'
      : '')+
    '<div class="pst-note">'+escHtml(g.note)+'</div>'+
  '</div>';
}

/* One delegated listener for the bar.
 *
 * opts.apply(code, value) puts one value into the caller's table. opts.repaint()
 * redraws whatever needs redrawing afterwards. Nothing here fetches, and
 * nothing here saves.
 *
 * opts.harvest() is called FIRST on every interaction, and a caller whose table
 * is a set of live inputs must supply it: the owner may have typed into a box
 * since the last paint, and a repaint that has not read those boxes back throws
 * his typing away. That is a real bug on the delivery screen, which is why it
 * is a hook here rather than each caller remembering. */
function pstBind(id, opts){
  var bar = document.getElementById(id);
  if(!bar) return;
  var key = bar.getAttribute('data-pst-group'), g = pstGroup(key);
  var harvest = function(){ if(opts.harvest) opts.harvest(); };

  bar.addEventListener('change', function(ev){
    var box = ev.target.closest('[data-pst-auto]');
    if(!box) return;
    harvest();
    /* Changes what the chips OFFER. It touches no row that is already in the
       table, so ticking it is not itself an edit. */
    PST_AUTO[key] = !!box.checked;
    opts.repaint();
  });

  bar.addEventListener('click', function(ev){
    if(ev.target.closest('[data-pst-all]')){
      harvest();
      var pending = pstPending(key, opts.filled);
      if(!pending.length) return;
      pending.forEach(function(row){ opts.apply(row.code, pstValue(key, row)); });
      opts.repaint();
      if(opts.done) opts.done(pending.length);
      return;
    }

    var chip = ev.target.closest('[data-pst-code]');
    if(!chip || chip.getAttribute('aria-disabled') === 'true') return;
    harvest();
    var code = chip.getAttribute('data-pst-code');
    var row = g.rows.filter(function(r){ return r.code === code; })[0];
    if(!row) return;
    opts.apply(code, pstValue(key, row));
    opts.repaint();
    if(opts.done) opts.done(1);
  });
}

async function loadDeliveryLines(){
  let raw = [], storeCountry = '';
  try{
    const r = await fetch(dlBase(), {credentials:'same-origin', headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    const d = await r.json();
    raw = (d.settings || {}).delivery_texts;
    // Read, not assumed: the empty state names the shop's own country, and this
    // shop is only the UAE by default.
    storeCountry = String((d.settings || {}).store_country || '').toUpperCase();
  }catch(e){ raw = []; }

  /* GET /admin-api/settings answers from Setting::map(), which hands back the
     stored column VERBATIM \u2014 it does not json-decode the way SettingsService
     does for the storefront. So this value arrives as a JSON string, and would
     arrive as an array if that ever changes. Both are handled rather than one
     being assumed, because the wrong guess here is a screen that silently
     shows no rows above a database that has several. */
  if(typeof raw === 'string'){
    try{ raw = JSON.parse(raw); }catch(e){ raw = []; }
  }

  DLINES = {
    storeCountry: storeCountry,
    rows: Array.isArray(raw) ? raw.filter(r=>r && typeof r === 'object').map(r=>({
      country: String(r.country||'').toUpperCase(),
      text: String(r.text||'')
    })) : []
  };

  return DLINES;
}

function dlCountrySelect(current){
  const codes = Object.keys(KBB_COUNTRY_NAMES).sort((a,b)=>KBB_COUNTRY_NAMES[a].localeCompare(KBB_COUNTRY_NAMES[b]));
  let seen = false;
  /* The NAME first and the code after it, because the owner is choosing
     "Saudi Arabia", not "SA" \u2014 the code is there to confirm what was stored,
     not to be read for meaning. */
  let out = codes.map(c=>{
    if(c===current) seen = true;
    return '<option value="'+c+'"'+(c===current?' selected':'')+'>'+escHtml(KBB_COUNTRY_NAMES[c])+' ('+c+')</option>';
  }).join('');
  /* A code already stored that is no longer on the list keeps its place rather
     than silently becoming the first country in the alphabet when the row is
     saved again. */
  if(!seen && current) out = '<option value="'+escAttr(current)+'" selected>'+escHtml(current)+'</option>' + out;
  return out;
}

/* The finished sentence for a row whose text carries {country} — the same
   substitution App\Support\CountryTemplate::fill() does on the storefront, so
   the words under the box are the words the shopper reads. Spelled here rather
   than fetched because this runs on every keystroke. */
function dlPreview(row){
  const text = String(row.text||'');
  if(text.indexOf('{country}') === -1) return '';
  const name = KBB_COUNTRY_NAMES[row.country] || row.country;
  return `<div class="dl-prev" data-dl-prev>Shoppers there read: <b>${escHtml(text.split('{country}').join(name))}</b></div>`;
}

/* This screen's half of the preset bar, and the only part of it that knows
   what a delivery line is. `filled` answers with the row's current sentence, or
   null when the country has no row at all — which is how a chip tells "fill
   this in" from "replace what is there" from "already says this". */
function dlFilled(code){
  const row = DLINES.rows.filter(r => r.country === code)[0];
  return row ? String(row.text) : null;
}

function dlPresetBar(){
  return pstBarHtml('delivery', {id:'dlPresets', filled:dlFilled});
}

/* THE OTHER PER-COUNTRY DELIVERY WORDING, SHOWN WHERE IT CAN BE READ BESIDE
   THIS ONE.

   `delivery_countries.eta` is the "Arrives in …" wording on the Extended tab.
   It is NOT this sentence and cannot be merged with it: it is a DURATION of a
   few words completing a fixed phrase in the delivery options, capped at 40
   characters, only alive while Extended delivery is on, and only available for
   countries no zone covers — the Extended tab refuses a zone country outright,
   which is why the Gulf, the whole reason this screen exists, can never have
   one.

   What WAS wrong is that neither tab admitted the other existed, so three
   separate reviews read two fields as one duplicate. A shopper in a country
   that has both reads both, on one page, one under the delivery options and one
   under Place order. This is the line that lets the owner read them together
   before that happens.

   XD is already loaded whenever this screen paints — renderShipping() awaits
   loadExtended() before any tab is drawn — so this costs no request. It is
   read defensively all the same: a shop with Extended off, or with the table
   missing, must not take the Delivery lines tab down with it. */
function dlExtendedEta(code){
  if(!XD || !XD.on || !Array.isArray(XD.rows)) return '';
  const row = XD.rows.filter(r => r && String(r.code||'').toUpperCase() === String(code||'').toUpperCase() && r.enabled)[0];
  return row ? String(row.eta||'').trim() : '';
}

function dlExtendedNote(row){
  const eta = dlExtendedEta(row.country);
  if(!eta) return '';
  return `<div class="dl-prev" data-dl-eta>Extended delivery also tells shoppers here: <b>Arrives in ${escHtml(eta)}</b> — edit that on the <u>Extended</u> tab.</div>`;
}

function dlRow(row, i){
  return `<div class="dl-row" data-dl-row="${i}">
    <select data-dl-country="${i}">${dlCountrySelect(row.country)}</select>
    <input type="text" data-dl-text="${i}" maxlength="1000" value="${escAttr(row.text)}"
           placeholder="What shoppers in this country are told">
    <button type="button" class="dl-x" data-dl-remove="${i}" aria-label="Remove this country">&times;</button>
    ${dlPreview(row)}
    ${dlExtendedNote(row)}
  </div>`;
}

async function paintDeliveryLines(){
  if(!DLINES) await loadDeliveryLines();

  const rows = DLINES.rows;

  $('#content').innerHTML=`<div class="wrap ecwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Delivery &amp; Shipping</h2>
      ${shIntro()}</div>
    ${shTabs()}
    <div class="card mdcard">
      <div class="mmhd"><b>Delivery lines by country</b><span>Shown under Place order, and on the home page</span></div>
      <div style="padding:16px 18px 18px">
        <p class="mdesc" style="margin:0 0 14px">Write one sentence per country and shoppers there see that
          sentence instead of the general one. A country with no line here is told <b>nothing at all</b> about
          delivery time \u2014 which is deliberate, and better than telling someone in Riyadh about delivery in the UAE.</p>
        ${dlPresetBar()}
        ${rows.length ? `<div class="dl-head"><span>Country</span><span>What shoppers there are told</span><span></span></div>
        <div id="dlRows">${rows.map(dlRow).join('')}</div>`
        : `<div class="dl-empty" id="dlRows">No country has a line of its own yet, so the general delivery line is
             used in ${escHtml(KBB_COUNTRY_NAMES[DLINES.storeCountry] || 'your own country')} and nothing is said anywhere else.</div>`}
        <div style="margin-top:13px"><button type="button" class="btn ghost" id="dlAdd" style="font-size:12px;padding:7px 12px">+ Add a country</button></div>
        <div class="dl-note"><b>The general line</b> \u2014 the one used where no country has its own \u2014 is
          <b>Ecommerce &rarr; Delivery &rarr; Delivery message</b>. It is only ever shown to shoppers in your own
          store country, because it names that country. Leave a line here empty to say nothing in that country at all.</div>
      </div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="dlDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="dlSave">Save changes</button>
    </div>
  </div>`;

  bindShTabs();

  const dirty=()=>{ const d=document.getElementById('dlDirty'); if(d) d.style.visibility='visible'; };

  /* Read the DOM back into DLINES before any repaint, so typing in a row and
     then adding or removing another does not throw the typing away. That is
     the whole reason this screen keeps its state in one place rather than
     reading the inputs only at Save. */
  const harvest=()=>{
    $$('[data-dl-row]').forEach(el=>{
      const i = Number(el.dataset.dlRow);
      if(!DLINES.rows[i]) return;
      const sel = el.querySelector('[data-dl-country]'), txt = el.querySelector('[data-dl-text]');
      if(sel) DLINES.rows[i].country = sel.value;
      if(txt) DLINES.rows[i].text = txt.value;
    });
  };

  /* The preset bar. It writes into DLINES.rows and marks the screen dirty, and
     that is ALL it does — the values go nowhere until dlSave runs, exactly as
     if they had been typed. harvest first, or a sentence half-typed into
     another row is lost by the repaint. */
  pstBind('dlPresets', {
    filled: dlFilled,
    harvest: harvest,
    apply: (code, value) => {
      const row = DLINES.rows.filter(r => r.country === code)[0];
      if(row) row.text = value; else DLINES.rows.push({country: code, text: value});
    },
    repaint: () => { paintDeliveryLines(); },
    done: (n) => {
      dirty();
      toast(n === 1 ? 'Filled in — press Save changes to publish it'
                    : 'Filled in ' + n + ' countries — press Save changes to publish them');
    }
  });

  /* The line under the box, refreshed as he types rather than at blur — it is
     the only place the finished sentence appears for a row that carries the
     placeholder, and a preview that lags a keystroke behind is worse than
     none. The row itself is not repainted: that would move the caret. */
  const repreview=(el)=>{
    const wrap = el.closest('[data-dl-row]');
    if(!wrap) return;
    const i = Number(wrap.dataset.dlRow);
    if(!DLINES.rows[i]) return;
    const html = dlPreview(DLINES.rows[i]);
    const now = wrap.querySelector('[data-dl-prev]');
    if(now) now.outerHTML = html;
    else if(html) wrap.insertAdjacentHTML('beforeend', html);
  };

  $$('[data-dl-country],[data-dl-text]').forEach(el=>{
    el.oninput = ()=>{ dirty(); harvest(); repreview(el); };
    el.onchange = ()=>{ dirty(); harvest(); repreview(el); };
  });

  $$('[data-dl-remove]').forEach(b=>b.onclick=()=>{
    harvest();
    DLINES.rows.splice(Number(b.dataset.dlRemove), 1);
    dirty();
    paintDeliveryLines();
    const d=document.getElementById('dlDirty'); if(d) d.style.visibility='visible';
  });

  document.getElementById('dlAdd').onclick=()=>{
    harvest();
    /* The first country not already spoken for, so adding two rows in a row
       cannot produce two rows for the same country \u2014 which the server refuses
       rather than silently merging. */
    const taken = DLINES.rows.map(r=>r.country);
    const next = Object.keys(KBB_COUNTRY_NAMES).sort((a,b)=>KBB_COUNTRY_NAMES[a].localeCompare(KBB_COUNTRY_NAMES[b]))
      .find(c=>taken.indexOf(c) === -1);
    if(!next){ toast('Every country already has a line.'); return; }
    DLINES.rows.push({country: next, text: ''});
    paintDeliveryLines();
    const d=document.getElementById('dlDirty'); if(d) d.style.visibility='visible';
  };

  document.getElementById('dlSave').onclick=async function(){
    harvest();

    const taken = {};
    for(const row of DLINES.rows){
      if(taken[row.country]){
        toast('Two rows for ' + (KBB_COUNTRY_NAMES[row.country]||row.country) + ' \u2014 each country can have one line.');
        return;
      }
      taken[row.country] = true;
    }

    try{
      const r = await fetch(dlBase(), {
        method:'PUT',
        credentials:'same-origin',
        headers:{
          'Accept':'application/json',
          'Content-Type':'application/json',
          'X-XSRF-TOKEN': giftCookie('XSRF-TOKEN')
        },
        body: JSON.stringify({settings: {delivery_texts: DLINES.rows}})
      });
      const res = await r.json().catch(()=>({}));

      /* The server names the field it refused; showing that instead of a
         generic failure is the difference between fixing a row and guessing. */
      if(!r.ok){
        toast(res.message || res.error || 'Not saved \u2014 check the lines above.', 'bad');
        return;
      }
      /* SAVE REPORTS SUCCESS EVEN WHEN IT SKIPPED EVERY KEY \u2014 the failure mode
         AdminController::SETTING_RULES warns about in its own comment, and the
         one this key would have hit before it was added to that list. The
         counts are the only trustworthy part of the answer, so they are what is
         checked, exactly as the Gift tab checks them. */
      if(res.rejected && res.rejected.length){
        toast('Not saved: '+res.rejected.join(', ')+' \u2014 the server rejected these keys', 'bad');
        return;
      }
      if(res.saved === 0){ toast('Nothing was saved \u2014 check the server log'); return; }

      const d=document.getElementById('dlDirty'); if(d) d.style.visibility='hidden';
      toast('Delivery lines saved');
      DLINES = null;
      paintDeliveryLines();
    }catch(e){ toast('Save failed \u2014 check connection','bad'); }
  };
}

async function loadExtended(){
  const r=await fetch(xdBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
  if(!r.ok) throw new Error(r.status);
  XD=await r.json();
  // The table can be legitimately absent — a migration that has not run yet.
  // Say so plainly rather than let the tab behave as if nothing is wrong.
  if(XD.ready===false) toast('Extended delivery: its database table is missing. Re-apply the last update, or check the server log.');
}

function xdName(c){ return (XD.names||{})[c] || c; }
/* Same as shMoney() above: a legacy per-country rate is shown as it is rather
   than rounded into a figure the shop does not charge. */
function xdMoney(f){
  const v = Number(f||0) / 100;
  return Number.isInteger(v) ? v : v.toFixed(2);
}
function xdChosen(){ return XD.rows.map(r=>r.code); }

function xdPick(){
  const chosen=xdChosen();
  const q=(XD._q||'').toLowerCase();
  const codes=Object.keys(XD.names)
    .filter(c=>!q || xdName(c).toLowerCase().includes(q) || c.toLowerCase()===q)
    .sort((a,b)=>xdName(a).localeCompare(xdName(b)));
  return `<div class="cpick">
    <div class="cpick-hd">
      <input type="search" id="xdSearch" placeholder="Search ${Object.keys(XD.names).length} countries…" value="${escAttr(XD._q||'')}">
      <span class="chips">${Object.keys(XD.regions).map(r=>`<button class="chip" data-xdreg="${escAttr(r)}">${escHtml(r)}</button>`).join('')}
        <button class="chip" data-xdclear="1">Clear all</button></span>
    </div>
    <div class="cpick-b">${codes.map(c=>`
      <label class="cchk${chosen.includes(c)?' on':''}"><input type="checkbox" data-xdc="${c}"${chosen.includes(c)?' checked':''}>
        <span>${escHtml(xdName(c))}</span></label>`).join('')}</div>
  </div>`;
}

function xdRow(r,i){
  return `<div class="crow">
    <span class="ectog${r.enabled?' on':''}" data-xdon="${i}" role="switch" aria-checked="${r.enabled}" tabindex="0"></span>
    <span class="cname">${escHtml(xdName(r.code))} <em>${escHtml(r.code)}</em></span>
    <span class="cfield"><i>Charge</i><input type="number" min="0" step="1" value="${xdMoney(r.charge)}" data-xdcharge="${i}"></span>
    <span class="cfield"><i>Free from</i><input type="number" min="0" step="1" placeholder="never" value="${r.free_from===null?'':xdMoney(r.free_from)}" data-xdfree="${i}"></span>
    <span class="cfield"><i>Arrives in</i><input type="text" class="wide" maxlength="40" value="${escAttr(r.eta||'')}" data-xdeta="${i}"></span>
    <button class="crm" data-xdrm="${i}" title="Remove">✕</button>
  </div>`;
}

function paintExtended(){
  const on=XD.on;
  $('#content').innerHTML=`<div class="wrap ecwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Delivery &amp; Shipping</h2>
      ${shIntro()}</div>
    ${shTabs()}

    <div class="card mdcard">
      <div class="mmhd"><b>Extended delivery</b><span>Your ${XD.zoneCount||0} zone ${XD.zoneCount===1?'country always delivers':'countries always deliver'} — this adds more</span></div>
      <div class="mmbody">
        <div class="mmrow"><div class="mmlbl"><b>Deliver to more countries</b>
          <span>Adds the countries below on top of your zones. Your zones are never affected — turning this off just removes the extra countries, nothing else.</span></div>
          <span class="ectog${on?' on':''}" data-xdmain="1" role="switch" aria-checked="${on}" tabindex="0"></span></div>
        <div class="mmrow"><div class="mmlbl"><b>Detect the shopper's country</b>
          <span>Pre-selects it at checkout, for your zone countries as well as any added here. A saved address always wins, and once the shopper picks one nothing overrides it.</span></div>
          <span class="ectog${XD.detect?' on':''}" data-xddetect="1" role="switch" aria-checked="${XD.detect}" tabindex="0"></span></div>
        <div class="mmrow${on?'':' dim'}"><div class="mmlbl"><b>List countries you do not deliver to</b>
          <span>Off keeps the list short. On shows them so a shopper learns why, rather than not finding their country.</span></div>
          <span class="ectog${XD.show_all?' on':''}" data-xdshowall="1" role="switch" aria-checked="${XD.show_all}" tabindex="0"></span></div>
      </div>
    </div>

    <div class="card mdcard${on?'':' dim'}">
      <div class="mmhd"><b>Additional countries</b><span>${XD.rows.length} chosen · your zone countries are not listed here</span></div>
      <div class="mmbody">${xdPick()}</div>
    </div>

    <div class="card mdcard${on?'':' dim'}">
      <div class="mmhd"><b>Charges</b><span>Leave “free from” empty for no free delivery</span></div>
      <div class="mmbody">
        ${XD.rows.length ? XD.rows.map(xdRow).join('')
          : '<p class="mmpv-note" style="padding:14px 0">No additional countries chosen yet. Tick some above.</p>'}
        ${XD.rows.length>1 ? '<div style="display:flex;gap:8px;margin-top:12px"><button class="btn small" data-xdcopy="1">Copy first row\'s charges to all</button></div>' : ''}
      </div>
    </div>

    <div class="ecsave">
      <span class="ecdirty" id="xdDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="xdSave">Save changes</button>
    </div>
  </div>`;
  bindExtended();
}

function bindExtended(){
  bindShTabs();
  const dirty=()=>{ const d=$('#xdDirty'); if(d) d.style.visibility='visible'; };

  const flag=(sel,key)=>{ const el=$(sel); if(el) el.onclick=()=>{ XD[key]=!XD[key]; dirty(); paintExtended(); }; };
  flag('[data-xdmain]','on'); flag('[data-xddetect]','detect'); flag('[data-xdshowall]','show_all');

  const search=$('#xdSearch');
  if(search) search.oninput=()=>{ XD._q=search.value; paintExtended();
    const again=$('#xdSearch'); if(again){ again.focus(); again.setSelectionRange(again.value.length,again.value.length); } };

  $$('[data-xdc]').forEach(cb=>cb.onchange=()=>{
    const c=cb.dataset.xdc;
    if(cb.checked){ if(!xdChosen().includes(c)) XD.rows.push({code:c,enabled:true,charge:0,free_from:null,eta:''}); }
    else XD.rows=XD.rows.filter(r=>r.code!==c);
    dirty(); paintExtended();
  });
  $$('[data-xdreg]').forEach(b=>b.onclick=()=>{
    (XD.regions[b.dataset.xdreg]||[]).forEach(c=>{
      if(XD.names[c] && !xdChosen().includes(c)) XD.rows.push({code:c,enabled:true,charge:0,free_from:null,eta:''});
    });
    dirty(); paintExtended();
  });
  const clear=$('[data-xdclear]');
  if(clear) clear.onclick=()=>{ XD.rows=[]; dirty(); paintExtended(); };

  $$('[data-xdon]').forEach(el=>el.onclick=()=>{ const r=XD.rows[+el.dataset.xdon]; r.enabled=!r.enabled; dirty(); paintExtended(); });
  $$('[data-xdrm]').forEach(el=>el.onclick=()=>{ XD.rows.splice(+el.dataset.xdrm,1); dirty(); paintExtended(); });
  $$('[data-xdcharge]').forEach(el=>el.oninput=()=>{ XD.rows[+el.dataset.xdcharge].charge=Math.max(0,Math.round(Number(el.value)||0))*100; dirty(); });
  $$('[data-xdfree]').forEach(el=>el.oninput=()=>{
    const r=XD.rows[+el.dataset.xdfree];
    r.free_from = el.value.trim()==='' ? null : Math.max(0,Math.round(Number(el.value)||0))*100;
    dirty();
  });
  $$('[data-xdeta]').forEach(el=>el.oninput=()=>{ XD.rows[+el.dataset.xdeta].eta=el.value; dirty(); });

  const copy=$('[data-xdcopy]');
  if(copy) copy.onclick=()=>{
    if(!XD.rows.length) return;
    const first=XD.rows[0];
    XD.rows.forEach(r=>{ r.charge=first.charge; r.free_from=first.free_from; r.eta=first.eta; });
    dirty(); paintExtended(); toast('Charges copied to every country');
  };

  const save=$('#xdSave');
  if(save) save.onclick=async()=>{
    save.disabled=true;
    try{
      const r=await fetch(xdBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({on:XD.on,detect:XD.detect,show_all:XD.show_all,
          rows:XD.rows.map(r=>({code:r.code,enabled:r.enabled,charge:r.charge,free_from:r.free_from,eta:r.eta||''}))})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#xdDirty').style.visibility='hidden';
      toast('Extended delivery saved');
    }catch(e){ toast('Could not save: '+e.message,'bad'); }
    finally{ save.disabled=false; }
  };
}

/* ---------- Store · Payment & Shipping Rules ----------
   Ported from the plugin's pay_ship_rules module. Two rules; the second edits a
   setting that already existed rather than a new key of its own. */
let PSR=null;

function psrBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/pay-ship-rules'; }

async function renderPayShip(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Payment &amp; Shipping Rules</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(psrBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    PSR=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the rules.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderPayShip()">Retry</button></div></div>`;
    return;
  }
  paintPayShip();
}
function psrGet(k){ for(const t of PSR.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function psrSet(k,v){ for(const t of PSR.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

/* Amounts are held in fils everywhere in this app but nobody thinks in fils, so
   the field shows and accepts whole currency and converts on the way in and out. */
function psrField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-ps="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='money')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="psmoney"><i>${escHtml(PSR.currency)}</i>
        <input type="number" min="0" step="1" value="${(Number(v)/100) % 1 === 0 ? Number(v)/100 : (Number(v)/100).toFixed(2)}" data-ps="${f.key}"></span></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
    <input type="text" value="${escAttr(String(v))}" data-ps="${f.key}"></div>`;
}

/* Three order values against the window, so the effect is visible rather than
   something to work out from two numbers. */
function psrPreview(){
  const min=Number(psrGet('cod_min')), max=Number(psrGet('cod_max'));
  /* TWO DECIMALS, AND NOT FOR TIDINESS. This rounded to whole dirhams, so a
     rule whose floor is 9950 fils previewed as "AED 100 — Cash on delivery
     hidden" and actually fires at AED 99.50. The preview disagreed with the
     rule it exists to preview, which is the one thing it may never do: an
     operator reads this row to decide whether the window he has typed is the
     window he meant. Number()||0 because psrGet can hand back an empty box. */
  const money=(f)=>`${PSR.currency} ${(Math.round(Number(f)||0)/100).toFixed(2)}`;
  const row=(fils)=>{
    const blocked=(min>0&&fils<min)||(max>0&&fils>max);
    return `<div class="psrow${blocked?' no':' yes'}">
      <b>${money(fils)}</b><span>${blocked?'Cash on delivery hidden':'Cash on delivery offered'}</span></div>`;
  };
  const mid = max>0 ? Math.round((Math.max(min,0)+max)/2) : Math.max(min,0)+20000;
  return `<div class="pspv">
      ${row(Math.max(0,min>0?min-1000:1000))}
      ${row(mid)}
      ${row(max>0?max+1000:mid+50000)}
    </div>
    <p class="mmpv-note">${min===0&&max===0?'No limits set — Cash on delivery is always offered.':'Zero means no limit at that end.'}</p>`;
}

function paintPayShip(){
  const tab=PSR.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Payment &amp; Shipping Rules</h2>
      <p class="mdesc" style="margin:0">Limit Cash on delivery by order value, and offer only free delivery when it applies.</p></div>
    ${PSR.module_on?'':`<div class="nlwarn">This module is off, so these rules are not applied.
      Turn <b>Payment &amp; Shipping Rules</b> on under <a href="#modules" onclick="go('modules');return false;">Store → Modules</a>.</div>`}
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(psrField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in">${psrPreview()}</div><p class="mmpv-note">Live preview</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="psDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="psSave">Save changes</button>
    </div>
  </div>`;
  bindPayShip();
}

function bindPayShip(){
  const dirty=()=>{ const d=$('#psDirty'); if(d) d.style.visibility='visible'; };
  $$('[data-ps]').forEach(el=>{
    const k=el.dataset.ps;
    if(el.classList.contains('ectog')){
      el.onclick=()=>{ const v=!el.classList.contains('on'); el.classList.toggle('on',v);
        el.setAttribute('aria-checked',String(v)); psrSet(k,v); dirty(); paintPayShip(); };
      return;
    }
    el.oninput=()=>{ psrSet(k, Math.max(0, Math.round(Number(el.value)||0)) * 100); dirty();
      $('.mmpv-in').innerHTML=psrPreview(); };
  });
  const save=$('#psSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of PSR.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(psrBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#psDirty').style.visibility='hidden';
      toast('Rules saved');
    }catch(e){ toast('Could not save: '+e.message,'bad'); }
    finally{ save.disabled=false; }
  };
}


/* ---------- Growth & Marketing · Marketing Pixels ----------
   Ported from the plugin's marketing_pixels module: Meta, GA4 and TikTok IDs.
   Each fires independently once its ID is filled in — the module switch is a
   gate in front of them, not itself a source of any event. Separate from the
   existing "Meta & Facebook" screen, which is design-preview work for a much
   larger Conversions-API/catalog-feed feature and not this module. */
let MP=null;

function mpBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/marketing-pixels'; }

async function renderPixels(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Marketing Pixels</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(mpBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    MP=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the pixel settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderPixels()">Retry</button></div></div>`;
    return;
  }
  paintPixels();
}
function mpGet(k){ for(const t of MP.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return ''; }
function mpSet(k,v){ for(const t of MP.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function mpField(f){
  // .ecopt.wide is the Ecommerce screen's own layout for exactly this shape —
  // a full-width text field with a help sentence long enough to wrap. Reused
  // rather than the compact .mmrow (built for a short toggle or a narrow
  // number field), which put the input on the same line as multi-line help
  // text and the two overlapped.
  return `<div class="ecopt wide">
    <div class="ecom"><div class="ecl"><label>${escHtml(f.label)}</label></div>
      ${f.help?`<div class="echelp">${escHtml(f.help)}</div>`:''}</div>
    <div class="ecctl"><input type="text" class="inp" placeholder="${f.key==='ga4_id'?'G-XXXXXXXXXX':f.key==='tiktok_id'?'e.g. CABC123…':'e.g. 1234567890'}"
           value="${escAttr(f.value)}" data-mp="${f.key}" maxlength="60"></div></div>`;
}

function paintPixels(){
  const tab=MP.tabs[0];
  const anySet = tab.fields.some(f=>f.value);
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Marketing Pixels</h2>
      <p class="mdesc" style="margin:0">Meta, Google (GA4) and TikTok tags with standard e-commerce events.</p></div>
    ${MP.module_on?'':`<div class="nlwarn">This module is off, so none of these tags load, even for a filled-in ID.
      Turn <b>Marketing Pixels</b> on under <a href="#modules" onclick="go('modules');return false;">Store → Modules</a>.</div>`}
    ${MP.module_on && !anySet ? '<div class="nlwarn">On, but no ID is filled in below yet — nothing fires until at least one is.</div>' : ''}
    <div class="mmcard">
      <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
      <div class="mmbody">${tab.fields.map(mpField).join('')}</div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="mpDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="mpSave">Save changes</button>
    </div>
  </div>`;
  bindPixels();
}

function bindPixels(){
  const dirty=()=>{ const d=$('#mpDirty'); if(d) d.style.visibility='visible'; };
  $$('[data-mp]').forEach(el=>el.oninput=()=>{ mpSet(el.dataset.mp, el.value); dirty(); });

  const save=$('#mpSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of MP.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(mpBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#mpDirty').style.visibility='hidden';
      toast('Pixels saved');
      paintPixels();
    }catch(e){ toast('Could not save: '+e.message,'bad'); }
    finally{ save.disabled=false; }
  };
}

/* ---------- Growth & Marketing · Product Labels ----------
   Ported from the plugin's product_labels module. Four automatic badges — sold
   out, sale, new, bestseller — with one showing at a time in that order.

   This replaces a mock that described a richer feature than the module has:
   image badges assigned per product or category, scheduled, several stacked on
   one thumbnail. None of that existed; it was a hard-coded array with no API
   behind it. The richer version is worth building, but it is a separate item —
   shipping the plugin's behaviour first means the screen now tells the truth. */
let PL=null;

function plBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/product-labels'; }

async function renderLabels(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Product Labels</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(plBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    PL=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the label settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderLabels()">Retry</button></div></div>`;
    return;
  }
  paintLabels();
}
function plGet(k){ for(const t of PL.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function plSet(k,v){ for(const t of PL.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function plField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-pl="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-pl="${f.key}">
        <i id="plv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-pl="${f.key}"><code>${v}</code></span></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
    <input type="text" value="${escAttr(String(v))}" data-pl="${f.key}" maxlength="40"></div>`;
}

/* Four cards at the current text and colour, in the order the badge is chosen. */
function plPreview(){
  const card=(on,text,colour,caption)=>`<div class="plc${on?'':' off'}">
      <div class="plc-im">${on?`<span class="lbl" style="background:${escAttr(colour)}">${escHtml(text)}</span>`:''}</div>
      <div class="plc-cap">${escHtml(caption)}</div></div>`;
  const sale=String(plGet('sale_text')).replace('{off}','30');
  return `<div class="plgrid">
      ${card(plGet('oos_on'), plGet('oos_text'), plGet('oos_color'), 'Sold out')}
      ${card(plGet('sale_on'), sale, plGet('sale_color'), 'On sale, 30% off')}
      ${card(plGet('new_on'), plGet('new_text'), plGet('new_color'), `Added in the last ${plGet('new_days')} days`)}
      ${card(plGet('feat_on'), plGet('feat_text'), plGet('feat_color'), 'Marked featured')}
    </div>
    <p class="mmpv-note">One badge shows at a time, in this order.</p>`;
}

function paintLabels(){
  const tab=PL.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Product Labels</h2>
      <p class="mdesc" style="margin:0">Sale, New, Sold-out and Bestseller badges on product cards and the product page.</p></div>
    ${PL.module_on?'':`<div class="nlwarn">This module is off, so none of these badges show and the theme's own are used instead.
      Turn <b>Product Labels</b> on under <a href="#modules" onclick="go('modules');return false;">Store → Modules</a>.</div>`}
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(plField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in">${plPreview()}</div><p class="mmpv-note">Live preview</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="plDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="plSave">Save changes</button>
    </div>
  </div>`;
  bindLabels();
}

function bindLabels(){
  const dirty=()=>{ const d=$('#plDirty'); if(d) d.style.visibility='visible'; };
  $$('[data-pl]').forEach(el=>{
    const k=el.dataset.pl;
    if(el.classList.contains('ectog')){
      el.onclick=()=>{ const v=!el.classList.contains('on'); el.classList.toggle('on',v);
        el.setAttribute('aria-checked',String(v)); plSet(k,v); dirty(); paintLabels(); };
      return;
    }
    if(el.type==='range'){
      el.oninput=()=>{ plSet(k,Number(el.value)); dirty();
        const b=$('#plv-'+k); if(b){ const f=PL.tabs.flatMap(t=>t.fields).find(x=>x.key===k);
          b.textContent=el.value+((f.options||{}).unit||''); }
        $('.mmpv-in').innerHTML=plPreview(); };
      return;
    }
    el.oninput=()=>{ plSet(k,el.value); dirty(); $('.mmpv-in').innerHTML=plPreview();
      const c=el.parentElement.querySelector('code'); if(c) c.textContent=el.value; };
  });
  const save=$('#plSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of PL.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(plBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#plDirty').style.visibility='hidden';
      toast('Labels saved');
    }catch(e){ toast('Could not save: '+e.message,'bad'); }
    finally{ save.disabled=false; }
  };
}
function lblSync(){const chip=$('#lblChip');if(!chip)return;const el=$('#lblName');const nm=(el&&el.value)||'Label';chip.style.cssText=posStyle(lblPos)+';'+sizeStyle(lblSize)+';background:'+(window.lblColor||'#15a85a');chip.textContent=(window.lblIc||'🏷️')+' '+nm;}

/* ===================== META & FACEBOOK ===================== */
function renderMeta(){
  /* ===== LANE AV =============================================================
     This screen rendered nothing at all: it called peCard() five times and
     peCard is defined nowhere in this repo, so it threw before the innerHTML
     assignment ever ran.

     Defining peCard would have been the one-line fix and would have been the
     wrong one. What the five cards contained was invented: "Products in feed:
     642" (this store has ~2,400 products and no feed), a Feed URL
     — kbeautybliss.com/feed/meta-catalog.xml — that no route serves, "Status:
     Ready" for a connection that does not exist, a masked Access token that was
     literally a string of bullet characters, and a "Sync catalog now" button
     whose entire action was a toast reading "(preview)". Every input was
     unbound: there is no Meta settings key, no /admin-api endpoint and no
     catalog-feed code anywhere in this tree. The fix would have replaced a blank
     screen with a confident one telling a shop owner fabricated numbers about
     his own business, which is worse than blank, not better.

     So it says what is true. The Meta integration is not built. When somebody
     builds it, this function is where it goes.
     ========================================================================= */
  $('#content').innerHTML=`<div class="wrap"><div class="ph">
    <div class="pic">${ic(I.modules)}</div>
    <h3>Meta &amp; Facebook isn't installed yet</h3>
    <p>Pixel tracking, the Conversions API and the Facebook &amp; Instagram product feed are planned, but none of them is built yet — so there is nothing here to switch on and nothing is being sent to Meta. Your store is unaffected.</p>
    <button class="btn" onclick="go('pixels')">Marketing Pixels →</button>
  </div></div>`;
}
window.lblSync=lblSync;

/* ===================== SHOP FILTERS =====================
   ===== LANE DH ==============================================================

   THIS SCREEN SAVED NOTHING AND SAID IT HAD.

   Its whole configuration was SFCFG, a `let` in this file: the order of the
   groups, which were switched on, whether each showed everything or a
   hand-picked list, a sticky-panel toggle, a product-count toggle and a
   default column count. The Save changes button was
   onclick="toast('Shop filters saved (preview)')". There is no shop-filters
   endpoint, no shop-filters setting and no migration for one. An owner could
   switch Brand off, reorder the panel, hand-pick eleven categories, press Save,
   read that it had saved, reload the console and find every one of those
   choices gone - and the storefront had never differed by a pixel either way.

   BUILT, OR SAID PLAINLY? Said plainly, and this was the close one. The test
   was whether a real backing store existed and Save was the only missing
   piece. It does not, and Save is not:

     - The storefront panel is FIXED MARKUP. resources/views/store/shop.blade.php
       renders four groups unconditionally - Category, Brand, Price, Offers -
       and reads no configuration of any kind. Nothing anywhere consumes a
       filter setting, because there is none to consume.

     - This screen offered a FIFTH group, "Skin concern", over seven values
       typed into SF_CONCERNS. The shop cannot filter by concern and has no
       concern taxonomy: the only "concerns" column in the schema is free text
       on a quiz submission. That group could not have been made to work by
       saving anything.

     - "Default grid columns" is not a stored setting either. Facets::columns()
       reads ?cols= off the query string and defaults to 4; there is nowhere to
       save a default to.

     - "Sticky panel" and "Show product counts" describe how the panel already
       behaves, unconditionally. Counts are always printed and the panel is
       always sticky.

   So wiring the Save button would have meant writing a settings key, an
   endpoint, a reader on the shop page, a per-group allowlist the sidebar query
   knows nothing about, and a skin-concern taxonomy. That is a feature. Wiring
   Save alone would have been worse than what was there: today the toast at
   least says "(preview)", whereas a Save that stored JSON nothing reads would
   turn Brand off in the console and leave Brand on the shop, with the console
   insisting it had been saved - the same lie, one layer further down, and
   harder to find.

   WHAT THE SCREEN SAYS INSTEAD is what really decides that panel today, which
   is not nothing: which categories and brands appear, and in what order, comes
   straight from the catalogue. ShopController orders both lists by the curated
   `position` the Catalog reorder writes, then by size, and shows only entries
   that have visible products. That is a real lever the owner has and did not
   know about, and it was being hidden behind a screen pretending to be a
   different one.

   SFCFG, SF_LABELS, SF_MANUAL, SF_CONCERNS, SF_ITEMS, renderSFGroups() and
   renderSFPrev() went with the screen that drew them; they had no other
   caller. The .sf* rules in the stylesheet are left alone deliberately - they
   are dead now, but the stylesheet is the most contended block in this file and
   a purely cosmetic edit there is not worth the merge.
   ========================================================================= */
/* ══════════════════════════════════════════ Settings → Site address (Phase 0)
 *
 * Four controls, and the screen's job is to make the safe thing obvious.
 *
 * The main address is what every link, every redirect and every canonical tag
 * is built from. The old addresses are forwarded to it. And "keep this install
 * out of Google" is a switch, not something inferred from the domain, because a
 * staging site on a domain of its own IS canonical for that domain -- the
 * inference would be wrong for exactly the sites that need it.
 *
 * A wrong main address cannot lock the owner out: only hosts on the forward
 * list are ever redirected. See App\Support\SiteHost.
 */
let saState=null, saBusy=false, saMsg='', saCheck=null, saLoading=false;

/*
 * saLoading is not a nicety. renderSiteAddress() calls saLoad() when saState is
 * null, and saLoad() calls go() which calls renderSiteAddress() again -- so a
 * failed fetch leaves saState null and that pair spins forever, hammering the
 * endpoint. The flag makes the second entry a no-op, and a failure puts a
 * readable message on the screen instead of nothing.
 */
async function saLoad(){
  if(saLoading) return;
  saLoading=true;
  const r=await impApi('/site-address');
  saLoading=false;

  if(r.data&&r.data.ok){ saState=r.data; }
  else {
    saState={ok:false,canonical_host:'',aliases:'',redirect_enabled:false,visibility:'public',
      current_host:location.hostname,derived_aliases:[],verdict:'canonical'};
    saMsg='Could not load this screen. Status '+(r.status||'unknown')+'. Reload the page to try again.';
  }
  go('siteaddr');
}

function renderSiteAddress(){
  if(!saState){ $('#content').innerHTML='<div class="wrap"><div class="card pad">Loading…</div></div>'; saLoad(); return; }

  const s=saState;
  const priv=s.visibility==='private';

  $('#content').innerHTML='<div class="wrap"><div class="card pad" style="margin-bottom:16px">'
    +'<b style="font-size:14px">Which address is this shop\'s real one</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:720px">'
    +'Every link, every redirect and the canonical tag search engines read are built from this. '
    +'You are viewing the shop right now on <code style="font-family:var(--mono)">'+impEsc(s.current_host)+'</code>.</p>'
    +(saMsg?'<div class="impbanner" style="margin-top:10px">'+impEsc(saMsg)+'</div>':'')

    +'<div style="margin-top:14px;max-width:520px">'
    +'<label style="font-size:12px;font-weight:600">Main address</label>'
    +'<input id="saHost" class="inp" value="'+impEsc(s.canonical_host)+'" placeholder="extrabeauty.ae" '
    +'style="width:100%;margin-top:4px;font-family:var(--mono)">'
    +'<p style="font-size:11px;color:var(--ink-soft);margin:4px 0 0">No https://, no trailing slash. '
    +'Leave empty and nothing on this screen does anything.</p>'
    +'<button class="btn ghost sm" id="saCheckBtn" style="margin-top:8px">Check this address answers</button>'
    +(saCheck?'<p style="font-size:12px;margin:6px 0 0;color:'+(saCheck.reachable?'var(--ink-soft)':'#b45309')+'">'
      +impEsc(saCheck.reason)+'</p>':'')
    +'</div>'

    +'<div style="margin-top:16px;max-width:520px">'
    +'<label style="font-size:12px;font-weight:600">Old addresses to forward here</label>'
    +'<textarea id="saAliases" class="inp" rows="3" placeholder="kbeautybliss.com" '
    +'style="width:100%;margin-top:4px;font-family:var(--mono)">'+impEsc(s.aliases)+'</textarea>'
    +'<p style="font-size:11px;color:var(--ink-soft);margin:4px 0 0">One per line. '
    +'The www / non-www pair of your main address is handled automatically — you do not need to type it.'
    +(s.derived_aliases&&s.derived_aliases.length
      ?'<br>Forwarding now: <code style="font-family:var(--mono);font-size:11px">'
        +s.derived_aliases.map(impEsc).join('</code>, <code style="font-family:var(--mono);font-size:11px">')+'</code>'
      :'')
    +'</p>'
    +'<label class="row" style="margin-top:10px;gap:8px;align-items:flex-start">'
    +'<input type="checkbox" id="saRedirect"'+(s.redirect_enabled?' checked':'')+'>'
    +'<span style="font-size:12px"><b>Forward these, permanently</b><br>'
    +'<span style="color:var(--ink-soft)">Off until you switch it on. Only the addresses listed above are '
    +'ever forwarded, so a typo here can never make this shop unreachable.</span></span></label>'
    +'</div>'

    +'<div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--line);max-width:640px">'
    +'<label class="row" style="gap:8px;align-items:flex-start">'
    +'<input type="checkbox" id="saPrivate"'+(priv?' checked':'')+'>'
    +'<span style="font-size:12px"><b>Keep this install out of Google</b><br>'
    +'<span style="color:var(--ink-soft)">For a staging copy or a console — not for the live shop. '
    +'Every page, image and file answers <code style="font-family:var(--mono);font-size:11px">noindex</code>, '
    +'and this install stops announcing its pages to search engines.</span></span></label>'
    +(priv?'<div class="impbanner warn" style="margin-top:10px;font-size:12px">'
      +'<b>This install is currently hidden from search engines.</b> If this is your live shop, switch it off.'
      +'</div>':'')
    +'<p style="font-size:11px;color:var(--ink-soft);margin:10px 0 0">'
    +'▲ For a staging site the stronger answer is a password on the whole folder — in cPanel that is '
    +'<b>Directory Privacy</b>. A crawler never gets past it. This switch is the second-best protection, '
    +'for when you cannot do that.</p>'
    +'</div>'

    +'<div class="row" style="margin-top:16px;gap:8px">'
    +'<button class="btn" id="saSave"'+(saBusy?' disabled':'')+'>'+(saBusy?'Saving…':'Save')+'</button>'
    +'</div>'
    +'</div></div>';

  saWire();
}

function saWire(){
  const btn=document.getElementById('saSave');
  if(btn) btn.onclick=async()=>{
    /*
     * READ THE FIELDS FIRST, THEN REDRAW. go() replaces #content wholesale, so
     * redrawing to show "Saving…" destroys these four inputs and rebuilds them
     * from saState -- which is still the state from BEFORE the edit. Reading
     * them afterwards therefore posts the old values back and the save is a
     * silent no-op that answers 200 and says "Saved."
     *
     * That is exactly what it did, and it looked like a backend bug: the POST
     * returned ok with an empty canonical_host. It was this line ordering.
     */
    const payload={
      canonical_host:(document.getElementById('saHost')||{}).value||'',
      aliases:(document.getElementById('saAliases')||{}).value||'',
      redirect_enabled:!!(document.getElementById('saRedirect')||{}).checked,
      visibility:(document.getElementById('saPrivate')||{}).checked?'private':'public',
    };

    saBusy=true; saMsg=''; go('siteaddr');
    const r=await impApi('/site-address',{method:'POST',body:JSON.stringify(payload)});
    saBusy=false;
    if(r.data&&r.data.ok){ saState=r.data; saMsg='Saved.'; }
    else { saMsg=(r.data&&r.data.errors)?Object.values(r.data.errors).join(' '):'That did not save.'; }
    go('siteaddr');
  };

  const chk=document.getElementById('saCheckBtn');
  if(chk) chk.onclick=async()=>{
    // Same ordering trap as the save button above: read, then redraw.
    const host=(document.getElementById('saHost')||{}).value||'';
    saCheck={reachable:true,reason:'Checking…'}; go('siteaddr');
    const r=await impApi('/site-address/check',{method:'POST',body:JSON.stringify({host:host})});
    saCheck=r.data||{reachable:false,reason:'The check could not run.'};
    go('siteaddr');
  };
}

function renderShopFilters(){
  $('#content').innerHTML=`<div class="wrap">
    <div class="ph">
      <div class="pic">${ic(I.modules)}</div>
      <h3>Shop Filters isn&rsquo;t built yet</h3>
      <p>This screen let you reorder the storefront&rsquo;s filter panel, switch groups off and hand-pick what each one lists. None of it was ever saved and none of it ever reached the shop &mdash; the Save button only showed a message. Rather than leave a button that lies, the screen says so.</p>
      <p>The filter panel on <b>/shop/</b> is fixed: Category, Brand, Price and Offers, in that order, on every visit.</p>
    </div>
    <div class="card pad" style="margin-top:18px">
      <b style="font-size:14px">What does change it today</b>
      <p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px;line-height:1.6">The Category and Brand groups are built from your catalogue, so the catalogue is where they are controlled:</p>
      <ul style="font-size:12.5px;color:var(--ink-soft);margin:10px 0 0 18px;line-height:1.7">
        <li>A category or brand appears in the panel only while it has products that are on sale to the public. Empty ones are left out on their own.</li>
        <li>The order is the one you set in <b>Catalog</b> &mdash; reordering categories or brands there moves them in the shop&rsquo;s filter panel too. Anything you have not reordered falls back to biggest first for categories, A&ndash;Z for brands.</li>
        <li>The panel lists up to 30 categories and 40 brands.</li>
      </ul>
      <button class="btn" style="margin-top:16px" onclick="go('catalog')">Catalog &rarr;</button>
    </div>
  </div>`;
}
window.renderShopFilters=renderShopFilters;

/* ---------- modal + toast + env ---------- */
function openModal(html){$('#modal').innerHTML=html;$('#modalBg').classList.add('on');}
function closeModal(){$('#modalBg').classList.remove('on');}
$('#modalBg').onclick=e=>{if(e.target===$('#modalBg'))closeModal();};
$('#drawerBg').onclick=closeDrawer;
/*
 * Clear every cache this shop keeps, from the top bar of any screen.
 *
 * POSTs target=all to the endpoint Platform -> Cache already uses, so there is
 * one implementation and not two that can drift. The button disables itself
 * while the request is in flight -- a double tap is harmless but the second
 * purge rebuilds what the first one just rebuilt, which is a slow page for no
 * reason -- and its icon spins so the wait reads as work.
 */
let kbbPurging = false;
async function kbbPurge(btn){
  if (kbbPurging) return;
  kbbPurging = true;
  btn.disabled = true;
  btn.classList.add('spin');
  try {
    /*
     * THE FULL /admin-api/ PATH, and it is not optional.
     *
     * fixAdminApiUrl() rewrites a URL only when it STARTS WITH '/admin-api/' --
     * it prefixes the console's own directory, because the admin lives at a
     * path the owner chooses. Anything else is passed through untouched, so
     * '/cache/clear' was fetched from the site root and answered 404: the
     * button reported "api not found" while the endpoint it wanted was sitting
     * there working.
     *
     * The cache SCREEN does not hit this because it carries its own api()
     * helper that prepends '/admin-api' itself. Two helpers with the same name
     * and different contracts, one file apart.
     */
    const r = await api('/admin-api/cache/clear', {method:'POST', body: JSON.stringify({target:'all'})});
    // The endpoint answers {ok, target, ran:[…], compiled:{…}} -- `ran` names
    // what it actually did, which is worth saying: "Caches cleared" on a run
    // that silently did nothing looks identical to one that worked.
    const n = (r && Array.isArray(r.ran)) ? r.ran.length : 0;
    toast(n ? 'Cleared ' + n + ' cache' + (n === 1 ? '' : 's') + '.' : 'Caches cleared.');
  } catch (e) {
    toast(e && e.message ? e.message : 'Could not clear the caches.', 'bad');
  } finally {
    kbbPurging = false;
    btn.disabled = false;
    btn.classList.remove('spin');
  }
}
/* ── A FAILURE MAY NOT WEAR A SUCCESS TICK ─────────────────────────────────
   Reported by the owner with a screenshot of the clip editor: a black pill
   reading "✓ That file was not accepted." The tick was not a mistake at that
   call site — toast() rendered I.check for EVERY message it was ever given,
   success and failure alike, and `.toast svg` painted it mint green. Every
   error in this console has been announcing itself with a green tick since the
   toast was written.

   It is worse than cosmetic on the screens that matter here. "Could not save"
   under a green tick is the one message an operator glances at and reads as
   done, and the two screens where that is most expensive — Payments and the
   order screen's capture and refund — are full of them.

   `kind` defaults to the tick, so not one of the 240-odd existing calls changes
   behaviour by being left alone. 'bad' is the only other value that does
   anything, and every simple catch handler in this file now passes it. */
let toastT;function toast(m,kind){const t=$('#toast');const bad=kind==='bad';
  t.className='toast'+(bad?' bad':'');
  t.innerHTML=ic(bad?I.alert:I.check)+'<span>'+m+'</span>';
  t.classList.add('show');clearTimeout(toastT);toastT=setTimeout(()=>t.classList.remove('show'),bad?4200:2400);}
$$('#envtog button').forEach(b=>b.onclick=()=>{
  $$('#envtog button').forEach(x=>x.classList.remove('on'));b.classList.add('on');
  document.body.dataset.env=b.dataset.e;
  /* LANE DD — "Switched to Sandbox" was a report of something that did not
     happen. The line above is the whole of what this button does: it sets an
     attribute on <body> that only the warning strip's CSS reads. There is one
     database and every screen writes to it whichever side is lit. */
  toast(b.dataset.e==='sandbox'
    ? 'Label only — you are still editing the live shop'
    : 'Label only — you were already editing the live shop');
});
/* LANE DD — window.deploy and window.rollback are gone with the two buttons
   that were their only callers. See the note above renderSandbox(). */
window.go=go;window.toast=toast;window.reportModal=reportModal;window.closeModal=closeModal;
/* ── A FILE DROPPED ANYWHERE ELSE MUST NOT NAVIGATE THE CONSOLE AWAY ────────
   Found by Lane P2 in Chromium, and it is data loss rather than a nuisance: a
   file let go two pixels outside a drop zone — on the arrange toolbar, on the
   padding between two media cards — is handled by the BROWSER, which navigates
   to it. The half-filled product form goes with it, unsaved.

   Every zone the console has now swallows its own drops, but a zone can only
   cover the pixels it occupies, and the gaps between them belong to nobody.
   This is the floor under all of them.

   ONLY FILE DRAGS. The predicate is the kit's `carriesFiles`, repeated here for
   one reason: this runs at the foot of the boot, before any partial has
   defined window.kbbUpload, and a floor that waits for a later include is not a
   floor. The two are pinned equal by test rather than left to agree by luck.

   preventDefault on `dragover` is also what MAKES a drop fire at all, so this
   helps the real zones rather than competing with them: their own handlers run
   on their own elements and are unaffected — preventDefault stops the browser's
   default, not other listeners. */
(function(){
  function draggingFiles(e){
    var t = e.dataTransfer && e.dataTransfer.types;
    if(!t) return false;
    for(var i=0;i<t.length;i++){ if(String(t[i]).toLowerCase()==='files') return true; }
    return false;
  }
  ['dragover','drop'].forEach(function(name){
    document.addEventListener(name, function(e){
      if(draggingFiles(e)) e.preventDefault();
    }, false);
  });
})();
/* LANE DH - the two Debug & Monitor handlers that are named in an inline
   onclick. kbbHealthRun is exported where it is defined, in the live-wiring
   block below, because that is where api() is. */
window.kbbOpenErrorLog=kbbOpenErrorLog;window.kbbCopyReport=kbbCopyReport;

/* ---------- console settings + theme ---------- */
/* id, name, gradient, bg, surface, accent, line, ink, accentSoft */
const THEMES=[
  ['aurora','Aurora Green','linear-gradient(135deg,#15a85a,#0f8f4b)','#f6f7fb','#ffffff','#15a85a','#e6e9f2','#101729','#e7f7ee'],
  ['indigo','Indigo','linear-gradient(135deg,#4f63e0,#3a4cc4)','#f6f7fb','#ffffff','#4f63e0','#e6e9f2','#101729','#ebedfd'],
  ['rose','Rose','linear-gradient(135deg,#e0567b,#c13e63)','#fbf7f8','#ffffff','#e0567b','#ede2e6','#2a2228','#fce6ee'],
  ['slate','Slate','linear-gradient(135deg,#4b5a72,#374255)','#f5f6f8','#ffffff','#4b5a72','#e5e8ee','#101729','#eef1f6'],
  ['midnight','Midnight','linear-gradient(135deg,#1b2235,#0c1120)','#0c1120','#141a2b','#22c06c','#28324b','#eef1f9','#10301f']
];
let theme='aurora';
let consolePrefs={density:'comfortable',landing:'dash',perpage:'50',lang:'en',reduce:false};
function setTheme(t){
  theme=t;
  if(t==='aurora')document.documentElement.removeAttribute('data-theme');
  else document.documentElement.dataset.theme=t;
  toast('Console theme: '+THEMES.find(x=>x[0]===t)[1]);
  if(cur==='console')$$('#content .theme-card').forEach(c=>c.classList.toggle('on',c.dataset.t===t));
}
function tpv(th){const[,,,bg,sf,ac,ln,ink,as]=th;
  return `<div class="tpv" style="background:${bg};border-color:${ln}">
    <div class="tpv-side" style="background:${sf};border-color:${ln}">
      <div class="tpv-logo" style="background:${ac}"></div>
      <div class="tpv-line" style="background:${as};width:80%"></div>
      <div class="tpv-line" style="background:${ln};width:64%"></div>
      <div class="tpv-line" style="background:${ln};width:72%"></div></div>
    <div class="tpv-main"><div class="tpv-bar" style="background:${ac}"></div>
      <div class="tpv-card2" style="background:${sf};border-color:${ln}"></div></div></div>`;
}
const segHTML=(opts,cur,pref)=>`<div class="seg" data-pref="${pref}">${opts.map(o=>`<button data-v="${o[0]}" class="${o[0]===cur?'on':''}">${o[1]}</button>`).join('')}</div>`;
const optHTML=(o,cur)=>Object.entries(o).map(([v,l])=>`<option value="${v}"${v===cur?' selected':''}>${l}</option>`).join('');
/* ---------- Demo Content ---------- */
const DEMO_CONTENT_TYPES=[
  ['orders','Demo Orders','Sample orders across every status — processing, shipped, refunded — so you can try the order detail page, refunds, and status changes on something real.','<path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 01-8 0"/>','#4F46E5','#EEF2FF'],
  ['customers','Demo Customers','Sample customer accounts, for testing account pages, the customer history panel, and order lookups.','<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>','#2563EB','#EFF6FF'],
  ['products','Demo Products','Sample products with brands, categories and pricing already filled in — including a few on sale.','<path d="M21 8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/>','#059669','#ECFDF5'],
  ['pages','Demo Pages','A few sample static pages — About, Shipping, Returns — to preview the page layout before writing the real ones.','<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>','#7C3AED','#F5F3FF'],
  ['posts','Demo Blog Posts','Sample Journal articles, so the blog is not empty while you plan out real content.','<path d="M4 22h16a2 2 0 002-2V4a2 2 0 00-2-2H8a2 2 0 00-2 2v16a2 2 0 01-2 2zm0 0a2 2 0 01-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8M15 18h-5M10 6h8v4h-8z"/>','#D97706','#FFFBEB'],
  ['reviews','Demo Reviews','Sample product reviews at a mix of ratings, for testing the review moderation queue and star display.','<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>','#E0567B','#FDF2F6'],
  ['menu','Demo Mega Menu','A ready-made navigation menu with brand and category dropdowns already wired up and set live.','<path d="M3 12h18M3 6h18M3 18h18"/>','#0891B2','#ECFEFF'],
  ['routines','Demo Routine Products','Five products, one for each routine step, so every routine on Catalog \u2192 Build my routine fills and you can see the page before tagging your own. They carry no concerns, so they cannot publish a concern landing page.','<path d="M4 6h10"/><path d="M4 12h16"/><path d="M4 18h7"/><circle cx="18" cy="6" r="2"/><circle cx="15" cy="18" r="2"/>','#15A85A','#ECFDF3'],
  /* THIS SCREEN KEEPS ITS OWN LIST, which is the trap: adding a type to
     DemoContentController::TYPES makes it importable and leaves it INVISIBLE
     here, so the owner sees no card and concludes the feature does not exist.
     That is exactly what happened with `videos`. DemoContentTypesAreDrawnTest
     now pins the two lists equal in both directions. */
  ['videos','Demo Shoppable Video','Two video sections and six clips, with products from your own catalogue tagged on them and one clip deliberately in both sections. The clips are published and carry a real file, so a rail built from them really loops. Nothing appears on the shop until you switch the module on and place a shortcode.','<path d="M3 4.5h18v15H3z"/><path d="m10 9.5 5 2.5-5 2.5z"/>','#7C3AED','#F5F3FF'],
];
/**
 * Computes the admin-api base the same way pApiBase() does — from the
 * current page's own path, not a hardcoded leading slash. A hardcoded
 * '/admin-api/...' resolves against the domain root; on a subdirectory
 * deployment (the live site runs at easywebsol.com/kbb-upgrade/) that
 * silently points at a URL with no matching route at all, which is
 * exactly the "route could not be found" this shape of bug produces.
 */
function dcApiBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api'; }
async function dcApi(path, opts){
  var o = Object.assign({credentials:'same-origin', headers:{}}, opts||{});
  o.headers = Object.assign({'X-XSRF-TOKEN':uToken(), 'Accept':'application/json'}, o.headers);
  var r = await fetch(dcApiBase()+path, o);
  var text = await r.text();
  var data;
  try{ data = JSON.parse(text); }
  catch(e){
    // A non-JSON body means an error page (a 419 CSRF page, a 404, a raw
    // 500), not a real API response. Surface a short, real snippet instead
    // of a silent "could not import" that hides what actually happened.
    throw new Error('HTTP '+r.status+': '+text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,160));
  }
  if(!r.ok || data.ok===false) throw new Error(data.message||('HTTP '+r.status));
  return data;
}
async function renderDemoContent(){
  $('#content').innerHTML='<div class="wrap"><p style="padding:40px;color:var(--ink-soft)">Loading…</p></div>';
  var counts={};
  try{ counts=(await dcApi('/demo-content')).counts||{}; }catch(e){}
  /* Asked separately from the counts above, and a failure here must not take
     the Demo Content screen down with it: on a build where the integrator has
     not yet wired routes/sample-order-admin.php this 404s, and the seven cards
     below have nothing to do with that. soCardHtml() renders its "create one"
     state from null, so the screen degrades to exactly what it was. */
  var sample=null;
  try{ sample=await dcApi('/sample-order'); }catch(e){}

  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>Demo Content</h2><p>Sample data so you can try every part of the admin without needing real customer information yet. Import what you need, remove it whenever you are ready to go live.</p></div>

    <div class="card pad" style="margin:18px 0 22px;background:linear-gradient(120deg,#FFF8EC,#FFFBF5);border-color:#F5E1BC">
      <div class="between" style="flex-wrap:wrap;gap:14px">
        <div style="display:flex;gap:12px;align-items:flex-start">
          <div style="color:#B36A0E;flex-shrink:0;margin-top:2px">${ic('<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>')}</div>
          <div>
            <b style="font-size:13.5px">This is sample data, clearly separate from anything real</b>
            <p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:520px">Every demo record is tracked, so removing it never touches your real orders, customers, or products. Safe to import and remove as many times as you like.</p>
          </div>
        </div>
        <!-- flex-shrink:0 plus the default min-width:auto meant this pair of
             buttons held a hard 437px, which the wrapping .between above could
             not help with: .between wraps its CHILDREN, and this row is one
             child. Its own min-content is 222px — the wider button alone — so
             letting it wrap and shrink is all that is needed, and the two
             buttons stack on a phone instead of running off the card. -->
        <div class="row" style="gap:10px;flex-wrap:wrap;justify-content:flex-end">
          <button class="btn ghost" id="dcRemoveAll" style="border-color:#c0392b;color:#c0392b;gap:7px">${ic('<path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z"/>')} Remove All Demo Content</button>
          <button class="btn" id="dcImportAll" style="gap:7px">${ic('<path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/>')} Import All Demo Content</button>
        </div>
      </div>
    </div>

    <div class="sec-title">Sample order</div>
    <div id="soCard">${soCardHtml(sample)}</div>

    <div class="sec-title">Demo content</div>
    <div class="dcgrid" id="dcGrid">${DEMO_CONTENT_TYPES.map(t=>dcCard(t,counts[t[0]]||0)).join('')}</div>
  </div>`;

  wireDemoContent();
  wireSampleOrder();
}

/* ---------- Sample order (Safety -> Demo Content -> Sample order) ----------

   ONE ORDER THE OWNER CAN LOOK AT, AND THROW AWAY.

   Four documents in this shop can only be read by opening a real order -- the
   invoice, the packing slip, the delivery note and the order emails -- so a
   store that has not taken its first order has no way to see any of them, and
   no way to check them again after the next change. This card makes one and
   removes it.

   IT IS A CARD ON THIS SCREEN AND NOT A SCREEN OF ITS OWN, because "sample data
   I can add and remove" is what this screen already is, and the owner should
   not have to learn a second place for the same idea.

   THE LANGUAGE SELECT IS THE POINT OF IT, NOT A GARNISH. `orders.locale` is
   what decides the language of the invoice, the order emails and the delivery
   note, while the packing slip deliberately stays in the operator's. That split
   is worth seeing rather than being told about, so the order can be made in
   either language and the two printed side by side.

   NOTHING HAPPENS UNTIL THE BUTTON IS PRESSED. This card renders, reads whether
   a sample order exists, and otherwise changes nothing about the shop -- which
   is what CLAUDE.md's rule 1 asks of a new control that ships switched off. */

function soCardHtml(sample){
  var langs=(sample&&sample.locales)||[{code:'en',name:'English',native:'English'}];
  var order=(sample&&sample.order)||null;
  var opts=langs.map(function(l){
    return '<option value="'+escAttr(l.code)+'">'+escHtml(l.name)+(l.native&&l.native!==l.name?(' \u00b7 '+l.native):'')+'</option>';
  }).join('');

  var body=order
    ? `<div class="card pad" style="background:#FFF8EC;border-color:#F5E1BC;margin-bottom:14px">
         <div class="between" style="flex-wrap:wrap;gap:12px">
           <div>
             <b style="font-size:13.5px">${escHtml(order.order_number)}</b>
             <p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0">
               ${order.lines} lines \u00b7 AED ${(order.total_fils/100).toFixed(2)} \u00b7 ${escHtml(order.status)} \u00b7 documents render in <b>${escHtml(order.locale_name)}</b>
             </p>
           </div>
           <button class="btn ghost sm" id="soRemove" style="color:#c0392b;gap:6px">${ic('<path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z"/>')} Delete sample order</button>
         </div>
         <div class="row" style="gap:8px;flex-wrap:wrap;margin-top:12px">
           <a class="btn ghost sm" target="_blank" rel="noopener" href="${escAttr(order.links.invoice)}">Invoice</a>
           <a class="btn ghost sm" target="_blank" rel="noopener" href="${escAttr(order.links.packing_slip)}">Packing slip</a>
           <a class="btn ghost sm" target="_blank" rel="noopener" href="${escAttr(order.links.delivery_note)}">Delivery note</a>
           <a class="btn ghost sm" target="_blank" rel="noopener" href="${escAttr(order.links.shipping_label)}">Dispatch label</a>
           <button class="btn ghost sm" id="soOpenOrders">Orders screen</button>
         </div>
       </div>`
    : '';

  return `<div class="card pad" style="display:flex;flex-direction:column;gap:14px">
    ${body}
    <div>
      <b style="font-size:14px">${order?'Replace the sample order':'Create a sample order'}</b>
      <p style="font-size:12px;color:var(--ink-soft);margin:6px 0 0;line-height:1.55">
        One realistic order &mdash; three lines including a product with a chosen shade, a Dubai delivery address, a delivery method, a card payment and a coupon &mdash; so you can open its invoice, packing slip and delivery note and see what they actually look like.
        It is marked <b>SAMPLE</b> on every screen and on every document, it is left out of every money figure in the back office, it never emails anybody and it never touches stock.
        ${order?'Creating a new one replaces the one above.':''}
      </p>
    </div>
    <div class="row" style="gap:10px;flex-wrap:wrap;align-items:center">
      <label style="font-size:12px;color:var(--ink-soft)" for="soLocale">Documents in</label>
      <select id="soLocale" style="min-width:170px">${opts}</select>
      <button class="btn sm" id="soCreate" style="gap:6px">${ic('<path d="M12 5v14M5 12h14"/>')} ${order?'Replace sample order':'Create sample order'}</button>
    </div>
  </div>`;
}

function wireSampleOrder(){
  var create=document.getElementById('soCreate');
  if(create) create.onclick=async function(){
    var sel=document.getElementById('soLocale');
    create.disabled=true;
    try{
      await dcApi('/sample-order',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({locale:sel?sel.value:'en'}),
      });
      toast('Sample order created');
      renderDemoContent();
    }catch(e){ toast('Could not create the sample order: '+e.message,'bad'); create.disabled=false; }
  };

  var remove=document.getElementById('soRemove');
  if(remove) remove.onclick=async function(){
    if(!confirm('Delete the sample order? It is removed completely, not moved to the trash.'))return;
    remove.disabled=true;
    try{
      await dcApi('/sample-order',{method:'DELETE'});
      toast('Sample order deleted');
      renderDemoContent();
    }catch(e){ toast('Could not delete the sample order: '+e.message,'bad'); remove.disabled=false; }
  };

  var open=document.getElementById('soOpenOrders');
  if(open) open.onclick=function(){ go('orders'); };
}
function dcCard([key,title,desc,icon,color,tint],count){
  var imported=count>0;
  var status=imported
    ? `<span style="display:inline-flex;align-items:center;gap:5px;color:#0EA968;font-size:11.5px;font-weight:700" class="dcokicon">${ic('<path d="M20 6L9 17l-5-5"/>')} ${count} imported</span>`
    : `<span style="color:#94A3B8;font-size:11.5px;font-weight:600">Not imported yet</span>`;
  return `<div class="card pad dccard" data-type="${key}" style="display:flex;flex-direction:column;gap:14px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start">
      <div class="dciconbox" style="width:42px;height:42px;border-radius:11px;background:${tint};color:${color};display:flex;align-items:center;justify-content:center">${ic(icon)}</div>
      <span class="dcstatus">${status}</span>
    </div>
    <div><b style="font-size:14px">${title}</b><p style="font-size:12px;color:var(--ink-soft);margin:6px 0 0;line-height:1.55">${desc}</p></div>
    <div class="row" style="gap:8px;margin-top:auto;padding-top:4px">
      <button class="btn ghost sm dcimport" style="flex:1;gap:6px">${ic('<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>')} Import</button>
      <button class="btn ghost sm dcremove" style="color:#c0392b;gap:6px" ${imported?'':'disabled style="opacity:.4;cursor:not-allowed"'}>${ic('<path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z"/>')} Remove</button>
    </div>
  </div>`;
}
function wireDemoContent(){
  document.querySelectorAll('.dccard').forEach(card=>{
    var type=card.dataset.type;
    card.querySelector('.dcimport').onclick=async function(btn){
      var b=card.querySelector('.dcimport');
      b.disabled=true;
      try{
        var r=await dcApi('/demo-content/'+type+'/import',{method:'POST'});
        toast(r.already?'Already imported':'Imported');
        renderDemoContent();
      }catch(e){ toast('Could not import: '+e.message,'bad'); b.disabled=false; }
    };
    card.querySelector('.dcremove').onclick=async function(){
      if(card.querySelector('.dcremove').disabled)return;
      if(!confirm('Remove this demo content? This cannot be undone.'))return;
      try{
        await dcApi('/demo-content/'+type+'/remove',{method:'POST'});
        toast('Removed');
        renderDemoContent();
      }catch(e){ toast('Could not remove: '+e.message,'bad'); }
    };
  });
  document.getElementById('dcImportAll').onclick=async function(){
    this.disabled=true;
    try{ await dcApi('/demo-content/import-all',{method:'POST'}); toast('All demo content imported'); renderDemoContent(); }
    catch(e){ toast('Could not import all: '+e.message,'bad'); this.disabled=false; }
  };
  document.getElementById('dcRemoveAll').onclick=async function(){
    if(!confirm('Remove ALL demo content? This cannot be undone.'))return;
    this.disabled=true;
    try{ await dcApi('/demo-content/remove-all',{method:'POST'}); toast('All demo content removed'); renderDemoContent(); }
    catch(e){ toast('Could not remove all: '+e.message,'bad'); this.disabled=false; }
  };
}

function renderConsole(){
  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>Console</h2><p>Preferences for this admin console — yours and your team's. Separate from store settings; new console options will keep landing here.</p></div>
    <div class="sec-title">Appearance · theme</div>
    <div class="theme-grid">
      ${THEMES.map(t=>`<div class="theme-card${t[0]===theme?' on':''}" data-t="${t[0]}">${tpv(t)}<div class="tcb"><span class="popsw" style="background:${t[2]}"></span><b>${t[1]}</b><span class="ck2">${ic(I.check)}</span></div></div>`).join('')}
    </div>
    <div class="sec-title">Console preferences</div>
    <div class="card pad">
      <div class="pref"><div class="pl"><b>Sidebar density</b><small>Spacing of the navigation</small></div>${segHTML([['comfortable','Comfortable'],['compact','Compact']],consolePrefs.density,'density')}</div>
      <div class="pref"><div class="pl"><b>Default landing page</b><small>Where the console opens</small></div><select class="inp" data-pref="landing">${optHTML({dash:'Dashboard',modules:'Modules',debug:'Debug & Monitor',sandbox:'Sandbox & Deploy'},consolePrefs.landing)}</select></div>
      <div class="pref"><div class="pl"><b>Rows per page</b><small>For lists & tables</small></div><select class="inp" data-pref="perpage">${optHTML({'25':'25','50':'50','100':'100'},consolePrefs.perpage)}</select></div>
      <div class="pref"><div class="pl"><b>Console language</b><small>Admin interface language</small></div><select class="inp" data-pref="lang">${optHTML({en:'English',ar:'العربية (RTL)'},consolePrefs.lang)}</select></div>
      <div class="pref"><div class="pl"><b>Reduced motion</b><small>Minimise animations</small></div><div class="tog${consolePrefs.reduce?' on':''}" data-pref="reduce"></div></div>
    </div>
    <div class="banner" style="margin-top:18px">${ic('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>')}<div>These are console-only preferences. Storefront design lives in <b>K-Beauty Bliss Theme</b>; store configuration lives in <b>Settings</b>.</div></div>
  </div>`;
  $$('#content .theme-card').forEach(c=>c.onclick=()=>setTheme(c.dataset.t));
  $$('#content .seg button').forEach(b=>b.onclick=()=>{const sg=b.closest('.seg');$$('button',sg).forEach(x=>x.classList.remove('on'));b.classList.add('on');consolePrefs[sg.dataset.pref]=b.dataset.v;toast('Saved (preview)');});
  $$('#content .tog[data-pref]').forEach(t=>t.onclick=()=>{t.classList.toggle('on');consolePrefs[t.dataset.pref]=t.classList.contains('on');toast('Saved (preview)');});
  $$('#content select.inp').forEach(s=>s.onchange=()=>{consolePrefs[s.dataset.pref]=s.value;toast('Saved (preview)');});
}

/* ===== LANE J · Store · Mail — BEGIN =========================================
   Self-contained. Nothing above or below this marker is referenced except the
   shared helpers ($, $$, escHtml, escAttr, toast, uToken) and the three
   registry lines noted in the PR body.

   The screen exists for one question — can this server send email — so the
   answer is the loudest thing on it, and it is never softened. A failed send
   prints the transport's own words verbatim, because "535 Incorrect
   authentication data" and "Connection could not be established" send the
   owner to two completely different places and a tidy "Could not send" sends
   them nowhere.

   The password box renders EMPTY always. The API does not return the stored
   value (it cannot — it is encrypted in mail_credentials and show() sends an
   empty string with has_value), so there is nothing to render, and blank on
   save means unchanged. */
let MAILCFG=null;

function mailBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/mail'; }

async function renderMail(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Mail</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(mailBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    MAILCFG=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the mail settings.</b>
      <p class="mlf-muted" style="margin:6px 0 12px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderMail()">Retry</button></div></div>`;
    return;
  }
  paintMail();
}

/* ---------------------------------------------------------------------------
   HOW THE SCREEN IS ORGANISED (LANE CJ).

   It was one flat list of fourteen boxes under a single heading, each with a
   paragraph of explanation beneath it, and every colour on it written as a hex
   literal. The list is now four titled bands, each saying in one line when you
   would touch it, with fields that belong together sharing a row and therefore
   a width — the standard the Coupons editor sets, applied here.

   THE GROUPS ARE A DISPLAY ORDER AND NOTHING ELSE. The server decides which
   fields exist (MailSettings::SCHEMA) and this table only says where each one
   is drawn. That matters because of the failure mode this whole screen is
   afraid of: a field that disappears from the form does not throw — it is
   simply absent from collect(), and the next Save writes a blank over whatever
   was stored. So mailSections() below renders the leftovers too. Add a key to
   SCHEMA and name it in no group and it still appears, in its own band at the
   foot, rather than silently vanishing. The test for that is in
   MailAdminScreenTest.

   WHERE THE LONG EXPLANATION WENT. Only one of the server's help strings is a
   genuine paragraph: mail_transport's, which is four sentences about which of
   the three sending modes to pick. That is not help for a box, it is the
   subject of the band — so the band uses it as its description and the field
   under it carries no help line at all. The text is still the server's, read
   from f.help; it is not duplicated here and cannot drift out of step with it.
--------------------------------------------------------------------------- */
const MAIL_SECTIONS=[
  { title:'How email leaves this store', tab:'Sending method',
    /* Description comes from mail_transport's own help — see above. */
    descFrom:'mail_transport',
    rows:[['mail_transport']] },

  { title:'Mail server', tab:'Mail server (SMTP)',
    desc:'Only needed if you picked the dedicated-SMTP option above. Every value here comes from your hosting control panel.',
    rows:[['mail_host','mail_port'],
          ['mail_username','mail_password'],
          ['mail_encryption','mail_timeout']] },

  { title:'Who the message comes from', tab:'Sender & alerts',
    desc:'The name and address customers see on everything the shop sends, and the inbox your own new-order alerts go to.',
    rows:[['mail_from_address','mail_from_name'],
          ['mail_merchant_address']] },

  { title:'What customers see at the foot', tab:'Footer',
    desc:'Printed under every order email. Leave any of them blank and the storefront’s own details are used instead.',
    rows:[['mail_support_email','mail_support_whatsapp'],
          ['mail_support_instagram','mail_signature']] },
];

function mailControl(f){
  if(f.options)
    return `<select class="mlf-select" id="${escAttr('mlf_'+f.key)}" data-mail="${escAttr(f.key)}">${
      f.options.map(o=>`<option value="${escAttr(o)}"${o===f.value?' selected':''}>${escHtml(o)}</option>`).join('')}</select>`;
  if(f.type==='secret')
    return `<input class="mlf-input" type="password" autocomplete="new-password" id="${escAttr('mlf_'+f.key)}" data-mail="${escAttr(f.key)}"
      placeholder="${f.has_value?'Stored — leave blank to keep it':'Not set'}">`;
  return `<input class="mlf-input" type="text" value="${escAttr(String(f.value??''))}" id="${escAttr('mlf_'+f.key)}" data-mail="${escAttr(f.key)}">`;
}

/* `showHelp` is false only for the field whose help has become its band's
   description, so the same sentence is never printed twice. */
function mailField(f,showHelp){
  const id=escAttr('mlf_'+f.key);
  const help=(showHelp===false||!f.help) ? '' : `<div class="mlf-help">${escHtml(f.help)}</div>`;
  return `<div class="mlf-field">
    <label class="mlf-label" for="${id}">${escHtml(f.label)}</label>
    ${mailControl(f)}
    ${help}
  </div>`;
}

/* Lane EK — the bands as a list, [{title, html}], so paintMail() can put each
   in its own tab (the owner, 4 Oct: "proper tabs not just throw the
   content"). mailSections() is still the same joined HTML: every key drawn,
   once, including the catch-all band (MailScreenDesignTest runs it). */
function mailSections(fields){
  return mailSectionList(fields).map(b=>b.html).join('');
}

function mailSectionList(fields){
  const byKey={}; fields.forEach(f=>{ byKey[f.key]=f; });
  const used={};
  const out=[];

  MAIL_SECTIONS.forEach(sec=>{
    const rows=sec.rows.map(keys=>{
      const cells=keys.filter(k=>byKey[k]).map(k=>{
        used[k]=true;
        return mailField(byKey[k], k!==sec.descFrom);
      });
      return cells.length ? `<div class="mlf-grid">${cells.join('')}</div>` : '';
    }).filter(Boolean);

    if(!rows.length) return;

    const desc = sec.descFrom && byKey[sec.descFrom] && byKey[sec.descFrom].help
      ? byKey[sec.descFrom].help
      : (sec.desc||'');

    out.push({title:sec.tab||sec.title, html:`<section class="mlf-sec">
      <div class="mlf-sec-h"><div class="mlf-sec-t">${escHtml(sec.title)}</div>${
        desc?`<div class="mlf-sec-d">${escHtml(desc)}</div>`:''}</div>
      ${rows.join('')}
    </section>`});
  });

  /* Anything SCHEMA declares that no group above names. Never dropped: a field
     missing from the form is a setting the next Save blanks. */
  const rest=fields.filter(f=>!used[f.key]);
  if(rest.length){
    out.push({title:'More settings', html:`<section class="mlf-sec">
      <div class="mlf-sec-h"><div class="mlf-sec-t">Other settings</div>
        <div class="mlf-sec-d">Added to this store after this screen was laid out. They save exactly like the rest.</div></div>
      ${rest.map(f=>`<div class="mlf-grid">${mailField(f,true)}</div>`).join('')}
    </section>`});
  }

  return out;
}

/* The outcome of the last test, kept server-side so it outlives the tab that
   pressed the button. */
function mailLastTest(){
  const t=MAILCFG.last_test;
  if(!t) return `<p class="mlf-muted">No test has ever been run on this server.</p>`;
  const when=(()=>{ try{ return new Date(t.at).toLocaleString(); }catch(e){ return t.at; } })();
  return `<div class="mlf-result ${t.ok?'is-ok':'is-bad'}">
    <b>${t.ok?'Last test succeeded':'Last test failed'}</b>
    <div class="mlf-result-when">${escHtml(when)} → ${escHtml(String(t.to||''))}</div>
    <div class="mlf-result-msg">${escHtml(String(t.message||''))}</div>
    ${t.message_id ? `<div class="mlf-result-when">Message ID: ${escHtml(String(t.message_id))}</div>` : ''}
  </div>`;
}

function paintMail(){
  const warn = MAILCFG.configured ? '' :
    `<div class="banner" style="margin-bottom:14px"><div>This store cannot send email yet. Still needed: <b>${
      escHtml(MAILCFG.missing.join(', '))}</b>. Password reset, email verification and newsletter confirmation stay off until a test-send succeeds.</div></div>`;
  const logNote = MAILCFG.transport==='log'
    ? `<div class="banner" style="margin-bottom:14px"><div><b>Nothing is being sent.</b> Mail is going to the Laravel log. Set <b>Send using</b> to <code>smtp</code> for real delivery.</div></div>`
    : '';

  /* Lane EK — TABS. One tab per settings band, then the four panels that were
     stacked under them. Markup only: the shared tab component
     (admin/partials/kbb-tabs) supplies the clicks and the arrow keys, and every
     panel stays in the page, hidden or not — so the one Save below still
     collect()s every field, exactly as it always has. The tab this browser
     last used (or ?tab=) opens first. */
  const bands=mailSectionList(MAILCFG.fields);
  const mlTabs=bands.map((b,i)=>['b'+i,b.title]).concat([['status','Status emails'],['test','Send a test'],['waiting','Waiting to go out'],['sent','Sent mail']]);
  let mlCur=mlTabs[0][0];
  try{
    const q=new URLSearchParams(window.location.search).get('tab'), m=localStorage.getItem('kbbtab:mail');
    const want=q||m; if(want && mlTabs.some(t=>t[0]===want)) mlCur=want;
  }catch(e){}
  const mlPanel=(id,inner)=>`<div class="kbb-tabpanel kbt-panel" role="tabpanel" data-kbt-panel="mail" data-kbt-id="${id}" id="kbtp_mail_${id}" aria-labelledby="kbt_mail_${id}" tabindex="0"${id===mlCur?'':' hidden'}>${inner}</div>`;
  const mlSave=`<div class="ecsave">
      <span class="ecdirty" id="mlDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="mlSave">Save changes</button>
    </div>`;

  $('#content').innerHTML=`<div class="wrap mlf-wrap">
    <div class="page-head"><h2>Mail</h2><p>The mailbox this store sends from. Settings come from the hosting control panel; the password is stored encrypted and is never shown again.</p></div>
    ${warn}${logNote}
    <div class="ectabs kbb-tabs kbt" role="tablist" data-kbt="mail" aria-label="Mail settings">${mlTabs.map(t=>`<button type="button" class="ectab kbb-tab kbt-tab${t[0]===mlCur?' on':''}" role="tab" id="kbt_mail_${t[0]}" data-kbt-tab="${t[0]}" aria-controls="kbtp_mail_${t[0]}" aria-selected="${t[0]===mlCur?'true':'false'}" tabindex="${t[0]===mlCur?'0':'-1'}">${escHtml(t[1])}</button>`).join('')}</div>

    ${bands.map((b,i)=>mlPanel('b'+i,`<div class="card mlf-card">${b.html}</div>${mlSave.replace('id="mlDirty"',i?'data-ml-dirty':'id="mlDirty" data-ml-dirty').replace('id="mlSave"',i?'data-ml-save':'id="mlSave" data-ml-save')}`)).join('')}

    ${mlPanel('status',`<div class="card mlf-card">
      <div class="mlf-sec-h" style="margin-bottom:10px">
        <div class="mlf-sec-t">Order status emails</div>
        <div class="mlf-sec-d">Which status changes email the customer automatically. You can still override this on any single order, from the order&rsquo;s own page.</div>
      </div>
      <div id="mlStatusEmails"><p class="mlf-muted">Loading…</p></div>
    </div>`)}

    ${mlPanel('test',`<div class="card mlf-card">
      <div class="mlf-grid">
        <div class="mlf-field">
          <label class="mlf-label" for="mlTo">Send a test message to</label>
          <input class="mlf-input" type="email" id="mlTo" placeholder="you@example.com">
          <div class="mlf-help">Save first. The result below is what the mail server actually said, not a queued job.</div>
        </div>
      </div>
      <div style="margin-top:12px"><button class="btn primary" id="mlTest">Send test message</button></div>
      <div id="mlResult" style="margin-top:14px">${mailLastTest()}</div>
    </div>`)}

    ${mlPanel('waiting',`<div class="card mlf-card">
      <div class="mlf-sec-h" style="margin-bottom:10px">
        <div class="mlf-sec-d">Back-in-stock alerts and basket reminders that this store owes somebody. The two features are off until you switch them on, and this panel says which of the things they need is still missing rather than showing you an empty list that looks healthy.</div>
      </div>
      <div id="mlBacklog"><p class="mlf-muted">Loading…</p></div>
    </div>`)}

    ${mlPanel('sent',`<div class="card mlf-card">
      <div class="mlf-sec-h" style="margin-bottom:10px">
        <div class="mlf-sec-d">Every message this store has tried to send, and what the mail server said back. When a customer says an email never arrived, this is where the answer is. Bodies are never stored — a reset or confirmation message carries a live link.</div>
      </div>
      <div class="mlf-grid" style="margin-bottom:10px">
        <div class="mlf-field">
          <label class="mlf-label" for="mlLogFilter">Show</label>
          <select class="mlf-input" id="mlLogFilter">
            <option value="failed">Only the ones that failed</option>
            <option value="all">Everything</option>
            <option value="sent">Only the ones that were accepted</option>
          </select>
        </div>
      </div>
      <div id="mlLog"><p class="mlf-muted">Loading…</p></div>
    </div>`)}
  </div>`;
  bindMail();
  /* Every settings tab carries the same Save (it saves every field, as the one
     button always has) and the same "Unsaved changes" note. */
  $$('#content [data-ml-save]').forEach(b=>{ if(b.id!=='mlSave') b.onclick=()=>{ const m=$('#mlSave'); if(m) m.click(); }; });
  $$('#content [data-mail]').forEach(el=>el.addEventListener('input',()=>$$('#content [data-ml-dirty]').forEach(d=>{ d.style.visibility='visible'; })));
  loadStatusEmails();
  loadMailLog();
  loadOutboundBacklog();
  /* The filter has to do something. A select that changes nothing is the
     control-with-no-writer this console has shipped before; bound here rather
     than inline so the handler cannot outlive the element it reads. */
  var mlLogSel=$('#mlLogFilter');
  if(mlLogSel) mlLogSel.addEventListener('change', loadMailLog);
}

/* ---------------------------------------------------------------------------
   What is owed and has not gone.

   FETCHED SEPARATELY from the settings half and from the delivery log, for the
   third time on this screen and for the same reason: a store whose SMTP is
   half-configured is exactly the store whose owner needs to read this, so a
   failure in one half must not black out the other two.

   EVERY WORD OF THE EXPLANATION COMES FROM THE SERVER. The five states, the
   sweep interval, the per-sweep budget and the paragraph about how sending is
   triggered are all resolved in App\Services\OutboundBacklog and sent in the
   payload. None of it is restated here. That is not ceremony -- this host has
   no scheduler, messages go out on the tail of ordinary page views, and a
   sentence written into this file explaining that would be wrong the first time
   somebody changed OutboundTick::INTERVAL, on the one panel whose entire job is
   to explain why nothing has been sent yet.
--------------------------------------------------------------------------- */
function outboundBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/outbound'; }

/* The same formatting the delivery log below uses, so two panels on one screen
   do not print the same instant two different ways. */
function mlWhen(iso){ try{ return new Date(iso).toLocaleString(); }catch(err){ return String(iso||''); } }

function mlBacklogRow(title, state, lines){
  /* The five states, in the order OutboundBacklog::state() decides them. A
     feature that is off is off whatever else is unset, and telling an owner to
     write a subject line for something he has not switched on is the noise that
     teaches him to ignore a panel like this one. */
  var look = {
    off:         ['var(--ink-faint)', 'Switched off',        'Nothing is being collected and nothing will be sent.'],
    unwritten:   ['var(--amber)',          'No message written',  'It is switched on and collecting, but there is no wording to send, so nothing goes out.'],
    unscheduled: ['var(--amber)',          'No schedule set',     'It is switched on with wording, but no times are set, so nothing is ever due.'],
    due:         ['var(--green)',          'Ready to send',       'These are owed now and will go out on the next sweep.'],
    waiting:     ['var(--green)',          'On, nothing owed',    'Working. Nothing is owed at this moment.']
  }[state] || ['var(--ink-faint)', state, ''];

  return '<div style="padding:12px 0;border-bottom:1px solid var(--line-2,var(--border))">'+
    '<div class="between" style="align-items:baseline">'+
      '<span style="font-size:13px;font-weight:700">'+escHtml(title)+'</span>'+
      '<span style="font-size:11px;font-weight:700;color:'+look[0]+'">'+escHtml(look[1])+'</span>'+
    '</div>'+
    (look[2]?'<div class="mlf-help" style="margin-top:2px">'+escHtml(look[2])+'</div>':'')+
    '<div class="mlf-help" style="margin-top:6px">'+lines.map(escHtml).join(' &middot; ')+'</div>'+
  '</div>';
}

async function loadOutboundBacklog(){
  const host=$('#mlBacklog');
  if(!host) return;

  let d;
  try{
    const r=await fetch(outboundBase()+'/backlog',{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    d=await r.json();
  }catch(e){
    /* Says which panel failed. "Could not load" on a screen with three
       independently fetched panels sends the reader to the wrong one. */
    host.innerHTML='<p class="mlf-muted">The waiting list could not be loaded. The delivery log below is unaffected.</p>';
    return;
  }

  var stock=d.stock||{}, cart=d.cart||{};

  /* "at least", not an exact figure, once the count hits the ceiling.
     OutboundBacklog bounds the due query at 500 deliberately -- it is a join,
     and this is a screen rather than a sweep -- so printing 500 as though it
     were the total would be the panel stating a number it does not have. */
  function due(n){ return (n>=500? 'at least 500':String(n))+' owed now'; }

  var stockLines=[due(stock.due||0), (stock.pending||0)+' waiting for a product to come back'];
  if(stock.oldest_due_at){ stockLines.push('oldest owed since '+mlWhen(stock.oldest_due_at)); }

  var cartLines=[due(cart.due||0), (cart.live||0)+' baskets being followed'];
  var hrs=cart.schedule_hours||[];
  cartLines.push(hrs.length? ('sent after '+hrs.join(', ')+' hours') : 'no times set');

  host.innerHTML =
    mlBacklogRow('Back-in-stock alerts', stock.state, stockLines)+
    mlBacklogRow('Basket reminders', cart.state, cartLines)+
    '<div style="padding:12px 0 2px">'+
      '<div class="mlf-help">'+escHtml(d.trigger||'')+'</div>'+
      '<div class="mlf-help" style="margin-top:4px">'+
        (d.last_swept_at
          ? 'Last sweep '+escHtml(mlWhen(d.last_swept_at))+'.'
          : 'No sweep has run yet on this store.')+
      '</div>'+
      '<div style="margin-top:12px"><button class="btn small" id="mlSweep">Send what is owed now</button></div>'+
      /* The button's own caption, not a tooltip: an owner who thinks a button
         might send twice will not press it, and this button exists precisely to
         answer "is any of this actually working" on a shop too quiet to trigger
         a sweep by itself. Pressing it twice cannot send twice -- every send
         goes through the same claim the tick uses. */
      '<div class="mlf-help" style="margin-top:6px">Sends only what is already owed. Nothing is sent twice, however many times you press it.</div>'+
      '<div id="mlSweepResult" style="margin-top:10px"></div>'+
    '</div>';

  var btn=$('#mlSweep');
  if(btn) btn.onclick=async function(){
    btn.disabled=true; var was=btn.textContent; btn.textContent='Sending…';
    var out=$('#mlSweepResult');
    try{
      const rs=await fetch(outboundBase()+'/sweep',{
        method:'POST',
        credentials:'same-origin',
        headers:{Accept:'application/json','X-XSRF-TOKEN':uToken()}
      });
      if(!rs.ok) throw new Error(rs.status);
      var r=await rs.json();
      var sent=(r&&r.sent)||{};
      var n=(sent.stock||0)+(sent.cart||0);
      if(out) out.innerHTML='<p class="mlf-muted">'+(n
        ? escHtml(n+' message'+(n===1?'':'s')+' sent. Check the delivery log below for what the mail server said.')
        : 'Nothing was owed, so nothing was sent.')+'</p>';
    }catch(e){
      if(out) out.innerHTML='<p class="mlf-muted">The sweep could not be run.</p>';
    }
    btn.disabled=false; btn.textContent=was;
    /* Both panels, because a sweep changes what each of them shows. */
    loadOutboundBacklog(); loadMailLog();
  };
}

/* ---------------------------------------------------------------------------
   The delivery record.

   FETCHED SEPARATELY, for the same reason the status list above is: a store
   whose SMTP is half-configured is exactly the store whose owner needs to read
   this, so it must not be blacked out by a failure in the settings half.

   DEFAULTS TO "failed". This screen exists for one question -- "the customer
   says it never arrived" -- and a list that opens on two hundred successes
   makes the owner hunt for the one row he came for.
--------------------------------------------------------------------------- */
async function loadMailLog(){
  const host=$('#mlLog');
  if(!host) return;
  const sel=$('#mlLogFilter');
  const status=(sel && sel.value) ? sel.value : 'failed';
  try{
    const r=await fetch(mailBase()+'/log?status='+encodeURIComponent(status)+'&limit=50',
      {credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok){ host.innerHTML=`<p class="mlf-muted">The delivery record could not be loaded (${escHtml(String(r.status))}).</p>`; return; }
    const d=await r.json();
    const rows=(d && d.entries) || [];
    const c=(d && d.counts) || {};
    const head=`<div class="mlf-help" style="margin-bottom:8px"><b>${escHtml(String(c.sent||0))}</b> accepted by the mail server, <b>${escHtml(String(c.failed||0))}</b> refused, out of <b>${escHtml(String(c.total||0))}</b> recorded.</div>`;
    if(!rows.length){
      host.innerHTML=head+`<p class="mlf-muted">${status==='failed'?'Nothing has failed. That is the answer you want here.':'Nothing recorded yet.'}</p>`;
      return;
    }
    host.innerHTML=head+`<div class="an-scroll"><table class="an-table"><thead><tr>
        <th>When</th><th>Kind</th><th>To</th><th>Subject</th><th>Result</th></tr></thead><tbody>`+
      rows.map(function(e){
        var when=(function(){ try{ return new Date(e.at).toLocaleString(); }catch(err){ return e.at; } })();
        var ok=(e.status==='sent');
        var detail = ok
          ? (e.message_id ? '<div class="mlf-help">'+escHtml(String(e.message_id))+'</div>' : '')
          : '<div class="mlf-help">'+escHtml(String(e.error||'No reason was recorded.'))+'</div>';
        return '<tr><td>'+escHtml(String(when))+'</td>'+
          '<td>'+escHtml(String(e.kind||''))+'</td>'+
          '<td>'+escHtml(String(e.recipient||''))+'</td>'+
          '<td>'+escHtml(String(e.subject||''))+'</td>'+
          '<td><b style="color:var(--'+(ok?'ok':'sale')+',currentColor)">'+(ok?'Accepted':'Refused')+'</b>'+detail+'</td></tr>';
      }).join('')+`</tbody></table></div>`;
  }catch(e){
    host.innerHTML=`<p class="mlf-muted">The delivery record could not be loaded.</p>`;
  }
}

/* ---------------------------------------------------------------------------
   Which status changes email the customer.

   FETCHED SEPARATELY FROM THE REST OF THIS SCREEN, on purpose. The mail
   settings above answer "can this server send at all"; a failure there has to
   be loud and stop the page. This list answers a narrower question, and a store
   whose SMTP is half-configured still needs to be able to read and change it.
   Failing independently means one broken half does not black out the other.

   EVERY STATUS IS LISTED, INCLUDING THE ONES THAT CANNOT EMAIL. The store has
   nine statuses and a customer message was written for two of them. Hiding the
   other seven would leave the owner looking for Processing and concluding the
   screen was incomplete; showing them as dead tick boxes would be the toggle
   that silently does nothing, which this project has shipped three times. So
   they are listed, disabled, each with the server's own reason beside it.

   The tick writes immediately rather than joining the Save button above. It is
   one boolean with an endpoint of its own, there is nothing to batch it with,
   and a checkbox that needs a second press elsewhere to mean anything is how
   settings get lost.
--------------------------------------------------------------------------- */
async function loadStatusEmails(){
  const host=$('#mlStatusEmails');
  if(!host) return;
  try{
    const r=await fetch(mailBase()+'/status-emails',{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    paintStatusEmails((await r.json()).statuses||[]);
  }catch(e){
    const why=String(e.message||e);
    host.innerHTML=`<p class="mlf-muted">Could not load the status list. ${
      why==='404' ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.' : ''
      } <code>${escHtml(why)}</code></p>`;
  }
}

function paintStatusEmails(rows){
  const host=$('#mlStatusEmails');
  if(!host) return;
  host.innerHTML=rows.map(row=>`
    <label class="mlf-opt" style="cursor:${row.supported?'pointer':'default'}">
      <input type="checkbox" data-status-email="${escAttr(row.status)}"${
        row.enabled?' checked':''}${row.supported?'':' disabled'}>
      <div>
        <span class="mlf-opt-t">${escHtml(row.label)}</span>
        <span class="mlf-opt-d">${escHtml(
          row.supported
            ? 'Emails the customer when an order moves to this status.'
            : row.reason
        )}</span>
      </div>
    </label>`).join('');

  host.querySelectorAll('[data-status-email]').forEach(box=>{
    box.onchange=async function(){
      const status=box.getAttribute('data-status-email');
      const wanted=box.checked;
      try{
        const r=await fetch(mailBase()+'/status-emails',{
          method:'POST', credentials:'same-origin',
          headers:{'Content-Type':'application/json',Accept:'application/json',
                   'X-XSRF-TOKEN':uToken()},
          body:JSON.stringify({status:status,enabled:wanted}),
        });
        const body=await r.json();
        if(!r.ok||!body.ok) throw new Error(body.message||r.status);
        // Repainted from the server's answer rather than left as the browser
        // drew it: the tick has to reflect what was actually stored.
        paintStatusEmails(body.statuses||[]);
        toast(wanted?'Customers will be emailed on '+status:'Customers will not be emailed on '+status);
      }catch(e){
        box.checked=!wanted;
        toast(String(e.message||'Could not save that.'), 'bad');
      }
    };
  });
}

function bindMail(){
  const dirty=()=>{ const d=$('#mlDirty'); if(d) d.style.visibility='visible'; };
  $$('#content [data-mail]').forEach(el=>{ el.oninput=dirty; el.onchange=dirty; });

  const collect=()=>{
    const out={};
    $$('#content [data-mail]').forEach(el=>{
      // A blank password box means "unchanged", so it is not sent at all —
      // sending '' would be harmless today but only because the service
      // happens to treat it that way. Do not rely on that from here.
      if(el.type==='password' && el.value==='') return;
      out[el.dataset.mail]=el.value;
    });
    return out;
  };

  const save=$('#mlSave');
  if(save) save.onclick=async()=>{
    save.disabled=true;
    try{
      const r=await fetch(mailBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings:collect()})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      toast('Mail settings saved');
      renderMail();
    }catch(e){ toast('Could not save: '+e.message,'bad'); }
    finally{ save.disabled=false; }
  };

  const test=$('#mlTest');
  if(test) test.onclick=async()=>{
    const to=($('#mlTo').value||'').trim();
    if(!to){ toast('Enter an address to send to'); return; }
    const box=$('#mlResult');
    test.disabled=true;
    box.innerHTML=`<p class="mlf-muted">Connecting to the mail server…</p>`;
    try{
      const r=await fetch(mailBase()+'/test',{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({to})});
      if(r.status===429){
        box.innerHTML=`<div class="mlf-result is-bad"><b>Too many attempts</b>
          <div class="mlf-result-msg">The test-send is rate limited. Wait a minute and try again.</div></div>`;
        return;
      }
      const d=await r.json();
      // Verbatim. A summary here would throw away the only useful part.
      box.innerHTML=`<div class="mlf-result ${d.ok?'is-ok':'is-bad'}">
        <b>${d.ok?(d.status==='sent'?'The mail server accepted it':'Written to the log — nothing sent'):'Send failed'}</b>
        <div class="mlf-result-msg">${escHtml(String(d.message||''))}</div>
        ${d.error?`<div class="mlf-result-err"><code>${escHtml(String(d.error))}</code></div>`:''}
      </div>`;
    }catch(e){
      box.innerHTML=`<div class="mlf-result is-bad"><b>The request did not complete</b>
        <div class="mlf-result-msg">${escHtml(String(e.message||e))}</div></div>`;
    }
    finally{ test.disabled=false; }
  };
}
/* ===== LANE J · Store · Mail — END ========================================= */

$('.side-pin .nav-item').onclick=()=>go('console');
buildNav();
/* Every row -- the partial-contributed ones included -- is already in #nav:
   App\Support\AdminNav rendered it into the shell. kbbAddNavEntry() is keyed
   on the screen id, so each partial's own call further down returns that row
   and adds nothing. */

/* ===== LANE DA · deep links · BEGIN ========================================
   Deep link: /{admin}?go=updates or /{admin}#updates opens that panel directly.
   This is how the retired standalone page hands over — it redirects here rather
   than rendering a second copy of the same screen.

   WHAT WAS WRONG, AND FOR HOW MANY SCREENS.

   This block runs HERE, a few lines after buildNav(), thousands of lines before
   the end of the document. Sixteen of this console's screens are not drawn by
   the go() that exists at this point: they are drawn by a LATER wrapper around
   window.go — the one in the second script below, and one more per screen
   partial included after it. None of those exists yet when this runs, which is
   why mountFrame() paints frameStartupHTML for every id in LIVE_RENDERED
   instead of reaching for a standalone file that was never shipped.

   So a deep link landed on "could not be loaded — the admin script did not
   finish starting up. Reload the page." That sentence was written for a real
   case and it was the wrong sentence for this one: the script had not finished
   starting up YET, rather than failed to, and reloading reproduces it exactly,
   because the address is the cause. Clicking the same row in the sidebar has
   always worked — by the time anyone can click, every wrapper is installed.
   That is the whole difference between the two, and it is why this was reported
   as "links are broken" rather than "screens are broken".

   WHAT THIS DOES NOW.

   The immediate go(target) is unchanged and still happens first, so the first
   paint, `cur`, the sidebar highlight and the breadcrumb are exactly what they
   were. That matters: ten screens already fixed this for themselves and each
   keys off one of those three — 'rev-all' tests `cur`, Review Settings tests
   which sidebar row is marked, Rating Badge tests the address — so any change
   to what this leaves behind would break them.

   What is added is a SECOND, CONDITIONAL pass, for the case where the
   navigation above painted the startup card instead of a screen. A marker is
   dropped inside #content and one replay is queued for after the document is
   parsed. Any real render replaces #content's children, so the first thing that
   draws destroys the marker — that is the whole test, and it needs no
   cooperation from the screens themselves.

   Marker gone: a screen claimed the address, there is nothing to do, and it
   rendered exactly once. That is the path the nine LIVE_RENDERED screens with
   their own bootIfCurrent() take.

   Marker still there: nothing drew the screen, the startup card is what the
   owner is looking at, so go() is called ONE more time — through the chain as
   it now stands, complete. That is the fix, and it is the same few lines for
   all sixteen.

   WHY THE ARMING TEST IS LIVE_RENDERED AND NOT SOMETHING WIDER. It has to name
   the case where the card was painted, and LIVE_RENDERED is exactly that case:
   go() sends every one of those ids through renderFrame or renderReviewFrame
   into mountFrame, which paints the card for them and only for them.

   A wider test was written first and withdrawn on the evidence. It asked "could
   the go() that just ran draw this screen at all?", which also catches ids that
   fall off the end of go()'s dispatch table and get `||renderDash` — the
   DASHBOARD under the right breadcrumb, with no error at all. 'media' is the
   live example and it is a real defect, reported rather than fixed here. It
   also caught 'rev-all' and 'customers', and those two broke it: both already
   boot themselves, and both paint only AFTER an await. Measured in Chromium,
   ?go=rev-all painted the All Reviews screen twice on two runs out of three —
   the replay fired while the screen's own load was still in flight, because the
   marker had not been overwritten yet. A screen that draws itself synchronously
   cannot lose that race and a screen that awaits first always can, so the fix
   is not to widen the window but to stay inside the set where the card, and
   therefore the absence of any other claim, is the honest reading.

   All sixteen ids here are safe on that test: seven have no boot of their own,
   and the other nine paint synchronously before they load.

   WHY THE PLACEHOLDER IS NOT DELETED. It was written for a real case: a partial
   that fails to parse never installs its wrapper, and the owner has to be told
   rather than shown a blank panel. That case is now the ONLY one it describes.
   The replay runs, the complete chain still has no renderer for the id, and
   go() falls back through renderFrame to the same card — true this time. It is
   a static string with no request and no handler behind it, so re-setting it
   costs nothing and mounts nothing.

   WHY NOT DEFER THE FIRST go() INSTEAD, which was the obvious shape. Because
   those ten existing boots run on DOMContentLoaded or during parsing, which is
   after any point this block could defer to. Deferring the first navigation
   would not stop them; it would only make them fire against a console that had
   not navigated yet, and then the replay would draw each of those screens a
   second time. Replaying LAST, and only where nothing has drawn, is the one
   order in which the existing ten and the missing seven do not collide.

   WHY NOT DELETE THOSE TEN COPIES and centralise. It is the tidier end state
   and it is not this change: they live in seven files other lanes are editing
   right now, and each reads a slightly different signal for its own reason
   (Rating Badge reads the address precisely because 'rev-capsule' has no
   sidebar row to read). They are redundant after this, not wrong, and a screen
   added to LIVE_RENDERED tomorrow needs none of them. */
(function(){
  const q = new URLSearchParams(window.location.search).get('go');
  const h = (window.location.hash || '').replace('#', '');
  /* THE FIRST SEGMENT IS THE SCREEN, and reading the whole thing was a live
     bug. Payments writes `#payments/<gateway>` from its own tab bar, so that
     is the address in the owner's bar after he touches a gateway — and
     TITLES['payments/stripe'] is undefined, so `asked` named no screen and the
     replay fell through to the DASHBOARD. Bookmarking or reloading the screen
     you were on took you somewhere else, silently.

     Splitting here fixes it without teaching this boot anything about
     gateways: `payments` routes, and the screen reads the rest of the hash
     itself (payHashTab), which is the one channel the nine window.go wrappers
     leave alone. Catalog takes its tab the same way, through go()'s own
     `sub`, which it already validates against CAT_TABS. */
  const askedPath = String(q || h);
  const asked = askedPath.split('/')[0];
  const askedSub = askedPath.split('/')[1] || '';
  /* TITLES is the console's own list of addressable screens; PLACEHOLDERS is
     the five `p-` ids, which are a real destination with a real card and were
     reachable by clicking inside the console but not by link. Anything else
     falls back to the dashboard, which is the honest answer to an address that
     names no screen. */
  const target = asked && (TITLES[asked] || PLACEHOLDERS[asked]) ? asked : 'dash';

  go(target, askedSub);

  /* LIVE_RENDERED: the ids mountFrame() answers with frameStartupHTML.
     LATE_RENDERED: the ids go() answers with the DASHBOARD, because its
     dispatch object has no entry for them and ends `||renderDash`. Two
     different wrong screens, one replay — and see the note on LATE_RENDERED
     for why neither set may contain an id that draws itself after an await. */
  if(!LIVE_RENDERED.has(target) && !LATE_RENDERED.has(target)) return;

  /* Inside #content, never on it: a dataset attribute on #content itself would
     survive innerHTML and report every screen as undrawn for ever. A child
     element does not — it is removed by the first thing that paints. */
  const box=$('#content');
  if(!box) return;
  box.insertAdjacentHTML('beforeend','<i data-kbb-deeplink hidden></i>');

  let done=false;
  function replay(){
    if(done) return;
    done=true;
    if(!document.querySelector('#content [data-kbb-deeplink]')) return;   // drawn already
    try{ if(typeof window.go==='function') window.go(target, askedSub); }catch(e){}
  }

  /* setTimeout from inside the listener, not the listener itself. This block
     registers before any partial is parsed, so its DOMContentLoaded handler
     runs FIRST of all of them — ahead of the very bootIfCurrent()s whose result
     it exists to read. A task queued from inside it runs after the lot. */
  function arm(){ setTimeout(replay,0); }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',arm);
  else arm();
})();
/* ===== LANE DA · deep links · END ========================================== */


/* ===== LANE CJ · Admin · keyboard-operable tick boxes — BEGIN ===============
   Every tick box in this console is a <span class="cbx"> with a click handler.
   A span is not focusable and does not answer the keyboard, so before this
   block ran, NOBODY WITHOUT A MOUSE COULD CHANGE ANY OF THESE SETTINGS — the
   column pickers, the bulk-select columns, the SEO switches, the redirect
   enable flags, the product-editor panes. Forty-eight of them across a dozen
   screens.

   WHY THE SPANS STAYED SPANS. A real <input type="checkbox"> is the more
   correct control and it is what the Coupons screen uses for new work. It was
   not the right change HERE, and the reason is the payload, not the markup:
   every reader of these controls asks `el.classList.contains('on')` and every
   writer calls `el.classList.toggle('on')` — including the save handlers that
   build the SEO settings body, the column-visibility maps and the bulk
   selection sets. Swapping the element means rewriting all forty-eight call
   sites plus the `.cbx.on` rules and the `[data-olcol]`-style selectors, in the
   single most contended file in the repo, to change something the user cannot
   see. A dropped field there does not throw — it silently blanks a stored
   setting on the next save. So the element, the ids, the data attributes, the
   class-based state and every save handler are untouched, and the missing half
   — the keyboard — is added once, here, for all of them.

   WHAT "PROPERLY" MEANS, AND WHY role= ALONE WAS REFUSED BEFORE. A previous
   lane found this and deliberately did not add role="checkbox" on its own,
   because a role without key handling makes the DOM promise something untrue.
   The promise is only kept if all four arrive together, so all four are here:
     * focusable            — tabindex="0"
     * operable             — Space and Enter, below
     * truthfully described — aria-checked, kept in step by a MutationObserver
                              rather than set once; screens toggle the class
                              from their own code and from whole re-renders,
                              and an aria-checked that only this block updated
                              would drift out of step with the box on screen
     * visible when focused — .cbx:focus-visible, up with the .cbx rules

   ACTIVATION GOES THROUGH .click(). Not a private toggle path: the handlers
   this console already binds are `el.onclick`, and delegated listeners watch
   for real clicks on #content. Dispatching a click makes the keyboard and the
   mouse the same code path, so they cannot drift apart later.

   THE DOUBLE-FIRE TRAP, CHECKED. The .ectog keydown a few thousand lines up
   carries a scar: a global keydown that calls .click() on a control which also
   has its own keydown fires twice, the state flips and flips back, and the
   control is dead. That is why that one is narrowed to .ectog[data-ec]. It is
   safe to be broad here for the opposite reason — .cbx has no keydown handler
   anywhere in this console (that is the bug), so this is the only one. If a
   screen ever adds its own, it must narrow this selector the same way.

   NAMING. A <span> inside a <label> is not named by that label: labels name
   form controls, and this is not one. So the name is taken from the wrapping
   label's text where there is one, from the title where the markup already
   set one, and from a fixed phrase for the row/select-all boxes in tables,
   which have no text of their own.

   SCOPE NOTE, REPORTED NOT FIXED: clicking the WORDS beside one of these does
   nothing either, for the same reason — the label has no control to forward to.
   That is a separate change to forty-odd call sites and is not in this lane.
--------------------------------------------------------------------------- */
(function(){
  var SEL = '.cbx';

  function tidy(s){
    return (s || '').replace(/\s+/g, ' ').trim();
  }

  /* The words a screen has already put beside the box. Two shapes exist and a
     third will: the older screens wrap the box and its words in one <label>,
     and the ones rebuilt to the Coupons standard put the box and a sibling
     <div> holding a title and some help inside a row. So this does not hard-
     code either -- it climbs a few levels, stopping the moment it reaches a
     node holding more than one tick box (past that point the text belongs to a
     list, not to this box), and prefers a dedicated title element over the
     whole row so the help paragraph does not end up in the name. */
  var TITLE_SEL = '.sm-opt-t, .ce-opt-t, .mlf-opt-t, .bd-opt-t, b, strong';

  function labelText(el){
    var host = el.closest('label, .catopt');
    if (host) {
      var direct = tidy(host.textContent);
      if (direct) return direct;
    }

    var node = el.parentElement;
    for (var hops = 0; node && hops < 3; hops++) {
      if (node.querySelectorAll('.cbx').length > 1) break;

      var title = node.querySelector(TITLE_SEL);
      var t = title ? tidy(title.textContent) : '';
      if (t) return t;

      t = tidy(node.textContent);
      if (t && t.length <= 120) return t;

      node = node.parentElement;
    }

    return '';
  }

  function nameFor(el){
    // Already named by the markup; do not overrule it.
    if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby')) return '';
    if (el.getAttribute('title')) return '';

    /* The boxes inside a table come FIRST, before any attempt to read words off
       the page, because there are no words to read: a select-all sits alone in
       a <th> and a row tick sits alone in a <td>. Left to the climb below,
       select-all reaches the header row -- whose only tick box it is -- and
       comes back named "ProductSKUBrandStatusStockPriceCategories...", which is
       measurably worse than no name at all. */
    if (el.id === 'olAll' || el.id === 'cuAll' || el.id === 'cplAll') {
      return 'Select every row on this page';
    }
    if (el.hasAttribute('data-olsel') || el.hasAttribute('data-cusel') ||
        el.hasAttribute('data-rvsel') || el.hasAttribute('data-cpsel') ||
        el.hasAttribute('data-rsel')) {
      return 'Select this row';
    }

    return labelText(el);
  }

  /* The state the box is actually in, not the state we last set. */
  function sync(el){
    el.setAttribute('aria-checked', el.classList.contains('on') ? 'true' : 'false');
  }

  function enhance(el){
    if (!el.hasAttribute('role')) el.setAttribute('role', 'checkbox');
    if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '0');
    var n = nameFor(el);
    if (n) el.setAttribute('aria-label', n);
    sync(el);
  }

  function enhanceWithin(node){
    if (!node || node.nodeType !== 1) return;
    if (node.matches && node.matches(SEL)) enhance(node);
    if (node.querySelectorAll) {
      var found = node.querySelectorAll(SEL), i;
      for (i = 0; i < found.length; i++) enhance(found[i]);
    }
  }

  /* Screens are re-rendered by replacing innerHTML wholesale, so newly drawn
     boxes have to be picked up as they appear rather than once at load. */
  var mo = new MutationObserver(function(records){
    for (var i = 0; i < records.length; i++) {
      var r = records[i];
      if (r.type === 'attributes') {
        if (r.target.matches && r.target.matches(SEL)) sync(r.target);
        continue;
      }
      for (var j = 0; j < r.addedNodes.length; j++) enhanceWithin(r.addedNodes[j]);
    }
  });

  function start(){
    enhanceWithin(document.body);
    mo.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['class'],
    });
  }

  document.addEventListener('keydown', function(e){
    if (e.defaultPrevented) return;
    var box = e.target;
    if (!box || !box.matches || !box.matches(SEL)) return;
    if (e.key !== ' ' && e.key !== 'Spacebar' && e.key !== 'Enter') return;
    // Space scrolls the page and Enter submits a form if we let them through.
    e.preventDefault();
    box.click();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
/* ===== LANE CJ · Admin · keyboard-operable tick boxes — END ================ */

/* ===== LANE CR · Admin · the words beside a tick box work it — BEGIN ========
   The other half of the block above. LANE CJ gave every <span class="cbx"> a
   focus stop, a role, Space and Enter. It left one thing open, and said so in
   its own SCOPE NOTE: clicking the WORDS next to a box still does nothing.

   WHY NOTHING HAPPENS. A <label> forwards a click to the form control it
   labels. These boxes are not form controls -- they are spans -- so the label
   has nothing to forward to and the click dies on the text. Only the 18px box
   itself responds. Every other tick box anyone has ever used works the other
   way round, so this reads as the setting being broken rather than as a small
   target, and the settings behind these are real: the SEO switches, the column
   pickers, the bulk-select rows, the product-editor panes.

   WHY NOT A REAL <input>. Same answer as the block above, and it is worth
   repeating because it is the tempting fix. Every reader of these controls
   asks classList.contains('on') and every writer calls classList.toggle('on'),
   including the save handlers that build the SEO settings body and the
   column-visibility maps. Swapping the element means rewriting those call
   sites in the most contended file in the repo, and a dropped field there does
   not throw -- it silently blanks a stored setting on the next save. The
   markup, the ids, the data attributes and every save handler stay untouched;
   the missing behaviour is added once, here, for all of them.

   BOTH SHAPES, NOT ONE. There are two, and a fix that knows only one leaves
   half the console still broken while looking finished:
     * the older screens WRAP the box and its words in a single <label>
       (.pdchk, .catopt, .so-col, and the bare `class="row"` labels)
     * smOpt() puts them in SIBLINGS -- <div class="sm-opt"><span class="cbx">
       </span><div><span class="sm-opt-t">...</span></div></div> -- with no
       <label> anywhere in it
   HOST below names both. A third shape must be added to it; nothing here
   guesses by climbing the tree, because a container picked by guesswork makes
   a stray click somewhere in a card toggle a setting the user never aimed at.

   THE DOUBLE-FIRE TRAP. A global handler that calls .click() on a control that
   also has its own handler fires twice: the state flips and flips straight
   back, and the control goes dead. The .ectog scar a few thousand lines up is
   exactly that, which is why that one is narrowed to .ectog[data-ec]. Here the
   click that must NOT be answered is the one that already landed on the box --
   or on a link, or a button, or a field inside the same row. SKIP names those,
   and the box itself is in that list. The synthetic click we dispatch re-enters
   this same listener with the box as its target, matches SKIP, and stops: one
   click on the words is one transition, never two.

   A LABEL THAT ALREADY WORKS IS LEFT ALONE. If the host <label> has a real
   control (a `for=`, or a wrapped <input>), the browser is already forwarding
   to it -- host.control is how the DOM says so. Answering as well would be the
   double fire again, in its native form.
--------------------------------------------------------------------------- */
(function(){
  var BOX = '.cbx';

  /* The containers that count as "this box and its words". Deliberately an
     explicit list -- see BOTH SHAPES above. */
  var HOST = 'label, .sm-opt';

  /* Clicks that already mean something else. The box is in here too: it has
     its own handler and has just run it. */
  var SKIP = 'a[href], button, input, select, textarea, summary,' +
             '[role="button"], [role="switch"], [role="link"], [role="tab"], .cbx';

  document.addEventListener('click', function(e){
    if (e.defaultPrevented) return;

    var t = e.target;
    if (!t || !t.closest) return;

    var host = t.closest(HOST);
    if (!host) return;

    // Already a working label: the browser forwards it. Do not answer twice.
    if (host.tagName === 'LABEL' && host.control) return;

    /* Exactly one box, or we cannot know which one the words belong to. A row
       carrying two is a list, and a list has no single setting to flip. */
    var boxes = host.querySelectorAll(BOX);
    if (boxes.length !== 1) return;

    // The click landed on something that acts for itself -- the box included.
    var own = t.closest(SKIP);
    if (own && host.contains(own)) return;

    boxes[0].click();
  });
})();
/* ===== LANE CR · Admin · the words beside a tick box work it — END ========== */
