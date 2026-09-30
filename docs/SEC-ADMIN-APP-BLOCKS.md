# Lane SEC · the ten edits the integrator applies

`resources/views/admin/app.blade.php` is the integrator's file and this lane may
not edit it, so the console half of the download gate is delivered as
anchor/replacement blocks in the shape `docs/BG-ADMIN-APP-BLOCKS.md` established.

**Each block is an exact ANCHOR and an exact REPLACEMENT.** Every anchor occurs
**exactly once** in `resources/views/admin/app.blade.php` at the commit this
branch was cut from — verified by count, not by eye, by
`tools/sec-apply-blocks.py`, which refuses to touch anything if one of them does
not. `python3 tools/sec-apply-blocks.py --check` additionally proves that this
document and that script carry the **same strings**, character for character.
Apply in any order; no anchor overlaps another's replacement.

**There is no `routes/web.php` edit and no migration.** Nothing is mounted and
no route is added — the server half of this is already on the branch, inside
actions that already exist.

| # | Where | What |
| --- | --- | --- |
| 1 | in front of `api()` | the gate: probe, classify, and say so on the console |
| 2 | Orders → Export | ask before navigating the console away |
| 3 | Customers → Export | ask before navigating the console away |
| 4 | Reviews → Export | ask before navigating the console away |
| 5 | Catalog → Products → Export | ask before navigating the console away |
| 6 | Orders → bulk **Print** | tell the console after the tab opens |
| 7 | an order's four document buttons | tell the console after the tab opens |
| 8 | Payments → Connect with Stripe | tell the console, and close the dead popup |
| 9 | Catalog → Products, load failure | say the session ended, not "clear the route cache" |
| 10 | the top bar's **Sign out** | sign out of the console he is actually in |

---

## What this is for

An admin-guarded address that does not carry the secret admin path answers a
signed-out **browser** with a plain 404. It used to answer with a 302 that named
`admin_path` in a `Location` header to anyone who typed `admin-api`, which is
the leak this lane closed in round 2.

Eleven addresses in the console are reached by **navigating the browser at
them** rather than by fetch. For the four `window.location.href` sites the 404
**replaces the whole console**: the owner presses Export on an expired session
and the sidebar, the page title and the list he was standing on are gone.
Before, he landed on the admin login and signed back in. That is strictly worse
than what it replaced, and it is what blocks the package.

The brief named nine. **`DownloadNavigationGateTest` found two more**, by
reading the console for `window.open` and `location.href` instead of reading a
list: the Stripe Connect popup (block 8) and the Instagram OAuth popup. Neither
address appears in `ServerBuiltAdminUrlsTest`'s set, because neither is built by
the server — they are written in the console, one of them in a partial.

### ▲ ONE SITE IS NOT IN THIS DOCUMENT, DELIBERATELY

`resources/views/admin/partials/instagram-screen.blade.php` opens
`/admin-api/instagram/start` in a popup (`openPopup()`, called from the screen's
own click handler). It is **another lane's file, not the integrator's**, so a
block here would be the wrong instrument.

**Its server half is done and on this branch**: `/admin-api/instagram/start`
answers `?probe=1` as the first statement of its action and mints no state doing
it, so the console half is available whenever that lane next touches the file.
What is left is four lines after `var win = openPopup(...)` — probe the href,
and on a refusal `win.close()` and set the screen's own `banner` to say the
session ended.

It is **not** a one-line copy of block 8, which is why it is not attempted here:
`kbbTellIfDownloadRefused` lives inside app.blade.php's wiring IIFE and is not
in that partial's scope, and the screen's deliberate fallback — *a blocked popup
falls through to the anchor's `href` in this tab* — is a second navigation with
its own answer to give. Whoever owns that screen should write it, not this lane.

### Measured, both ways, on the same preview

`tools/sec-preview.sh` boots a shop whose admin lives at `/sec-console`;
`tools/sec-shots.cjs` signs in, **drops the session cookie**, and presses the
buttons. Nothing is stubbed and no response is intercepted. Numbers read out of
the document, in `docs/sec-shots/{before,after}-measurements.json`:

| Orders → Export, session dead | `consoleOnScreen` | `sidebarRows` | `tableRows` | what it said |
| --- | --- | --- | --- | --- |
| **before** | `false` | 0 | 0 | `404 NOT FOUND` — a blank page |
| **after** | `true` | 79 | 3 | **"Your session has ended"**, with **Sign in again** |

| An order's Invoice button, session dead | `consoleOnScreen` | tab opened | `modalOpen` |
| --- | --- | --- | --- |
| **before** | `true` | 1 | `false` — **nothing at all** |
| **after** | `true` | 1 | `true` — "You are signed out, so that document could not be opened." |

**Block 9, the Catalog list** — the same session killed, then the screen asked
to load its list again, which is what the owner's next filter or page click
already does. Read out of `#catBody .card`:

| | what the screen said |
| --- | --- |
| **before** | "The product list could not be loaded — api /admin-api/catalog-products-list?page=1&per_page=50 -> 401. **If this is a fresh deployment, the Catalog → Products routes may not be wired into routes/web.php yet.**" |
| **after** | "**Your session has ended, so this list could not be loaded.** Sign in again and it loads as it did." |

**Block 10, Sign out** — the same preview, the top-bar button pressed:

| | network | landed on | `/admin-api/stats` after |
| --- | --- | --- | --- |
| **before** | `POST /admin/logout` → **404**, `GET /admin/login` → **404** | a blank 404 | **200 — still signed in** |
| **after** | `POST /sec-console/logout` → **302** | the real login page | **401** |

`document.documentElement.scrollWidth` equals the viewport at both widths in
every after shot: **390 = 390** and **1280 = 1280**. No horizontal scroll.

Pictures, all at **390 and 1280**, in `docs/sec-shots/`:

```
{before,after}-orders-export-expired-{390,1280}.png     block 2
{before,after}-order-document-expired-{390,1280}.png    block 7
{before,after}-catalog-expired-{390,1280}.png           block 9
{before,after}-signout-landing-1280.png                 block 10
{before,after}-orders-live-*, -order-detail-live-*, -catalog-live-*   the healthy screens
```

### What the probe costs, measured rather than argued

The obvious repair is to fetch the file through `api()` and hand the browser a
blob URL. The call sites refuse it in a comment that predates all of this — *"the
file lands in Downloads instead of in memory"* — and all four CSV exports return
a `StreamedResponse`. Measured on this tree:

| | bytes | ms |
| --- | --- | --- |
| catalogue export, at the live shop's **3,025 products** | **619,968** (0.59 MB) | 645 |
| the probe that guards it | **11** | 35 |
| orders export, at 4,000 orders | 610,022 (0.58 MB) | 560 |

So the honest answer to *"is `catalog-products-export` megabytes?"* is **not
today** — 0.59 MB, and a blob of that would not be ruinous. The orders export is
**153 bytes per order**, which is the one that grows without bound as the shop
takes orders, but that is a projection and not a measurement and it is not what
this rests on.

**The case for the probe does not rest on memory: it rests on CLAUDE.md rule 1.**
A blob is a different behaviour from a streamed download, the comment at every
call site promises the streamed one, and 35 ms buys leaving a working thing
exactly as it is.

`/admin-api/health` was considered and rejected: it is `throttle:6,1`, so the
fourth export in a minute would be refused by the probe rather than by the
export.

### Where each control sits in the admin

| Block | Exact path |
| --- | --- |
| 2 | **Store → Orders → Export CSV** (top right of the list) |
| 3 | **Store → Customers → Export** |
| 4 | **Content → Reviews → Export** |
| 5 | **Catalog → Products → Export** |
| 6 | **Store → Orders → Print ▾** (the bulk bar, once rows are ticked) |
| 7 | **Store → Orders → <an order> → Invoice / Packing slip / Delivery note / Dispatch label** |
| 8 | **Store → Payments → Stripe → Connect with Stripe** |
| 9 | **Catalog → Products** (the message shown when the list will not load) |
| 10 | the **Sign out** button in the top bar, on every screen |

**Nothing on the shop changes, and no setting is added.** These ten edits are
console behaviour on a failure path; a signed-in operator sees no difference at
all — and that is measured rather than claimed. The six healthy-screen shots are
**byte-identical** before and after, `md5sum` on the same tree:

```
orders-live-1280        e982cddd80acfa10fc444d9a62b34e09
orders-live-390         2014488ef96401475d423333c57ebeb7
order-detail-live-1280  3be00af1c9ebff8537f10002395750ce
order-detail-live-390   a071ebf38dceaf2c343761086485696e
catalog-live-1280       02b20d7367be340d0959f4873a5c5968
catalog-live-390        b214396dbcb2a2c54e779bb5276eaa0c
```

---

## What is red until these are applied, and what is green after

Counted in this lane's worktree, both ways, on the same tree.

| | `DownloadNavigationGateTest` |
| --- | --- |
| **before** (as this branch ships) | **9 failed, 1 passed** |
| **after** (all ten edits) | **10 passed, 49 assertions** |

Every one is the *finished-state* pin CLAUDE.md prescribes — an exact count,
never `->not->toContain` — so each goes green the moment the integrator does the
one thing this lane asked for, and stays a real guard afterwards:

```
DownloadNavigationGateTest > it declares the gate exactly once
DownloadNavigationGateTest > it asks before every navigation that would take the whole console away
DownloadNavigationGateTest > it tells the console after every popup it cannot wait in front of
DownloadNavigationGateTest > it leaves no navigation in the console unaccounted for
DownloadNavigationGateTest > it derives the sign-in address from where the browser already is, never from a setting
DownloadNavigationGateTest > it signs the owner out of the console he is actually standing in
DownloadNavigationGateTest > it tells the Catalog screen which failure it was, instead of blaming the route cache for all of them
DownloadNavigationGateTest > it lets no screen send the owner to clear a route cache over a dead session
DownloadNavigationGateTest > it keeps the handover document and the applied console in step
```

The one case not listed above — *it is the server that makes the literal
sign-out dead* — is green both ways on purpose: it asserts the SERVER fact that
turns block 10 from a tidiness complaint into a live session nobody ended.

**The whole suite on the branch as it ships: 9 failed, 22 skipped, 8,058 passed
(74,063 assertions), 854.93s** — and the nine are all of them
`DownloadNavigationGateTest`, by name, with nothing else in the suite red. So
the branch introduces no regression and the nine go green when these blocks
land.

> ▲ **Do not edit `resources/views/admin/app.blade.php` while a suite is
> running.** These cases read that file at the moment each one executes, not
> when the run starts. The first full run of this branch reported **0 failed**
> because the blocks happened to be applied to a scratch tree while the run was
> passing through them — a false green, produced by this lane against its own
> test, and the reason the figure above comes from a second run that touched
> nothing. It is the same class of failure as the shared scratchpad in
> CLAUDE.md: one file, two writers, and the collision looks like a pass.

---

`DownloadSessionProbeTest` (10) and `ServerBuiltAdminUrlsTest` (4) are green
both ways: they are the server half.

---

## Block 1 · the gate

**Anchor** (occurs once):

```
  async function api(url, opts){
```

**Replacement:**

```
  /* ══════════════════════════════════════════════════════════════════════════
     THE DOWNLOAD GATE                                             (Lane SEC)
     ══════════════════════════════════════════════════════════════════════════

     Eleven addresses in this console are reached by NAVIGATING the browser at
     them rather than by fetch: the four CSV exports, the bulk-documents page,
     the four order documents, and the two OAuth /start legs. An admin-api
     address that does not carry the secret admin path answers a signed-out
     browser with a plain 404, because the 302 it used to answer with named
     `admin_path` in a Location header to anyone who typed the prefix.

     So a navigation on a dead session used to land on the admin login, where
     the owner signed back in, and now lands on a blank 404. For the four
     `window.location.href` sites that takes THE WHOLE CONSOLE away with it and
     loses the list he was standing on -- strictly worse than what it replaced,
     and the reason this exists.

     ── SO THE BUTTON ASKS FIRST ──────────────────────────────────────────────

     `?probe=1` on the download's OWN address. App\Support\ExportProbe answers
     `{"ok":true}` and nothing else, as the first statement of the action, so no
     query is run and no OAuth state is minted -- and it passes through that
     action's own capability, because AdminCapabilities matches on the route's
     URI and a query string is not part of it. This therefore answers "may THIS
     operator run THIS download", which a shared liveness endpoint could not.
     `/admin-api/health` was considered and rejected: `throttle:6,1` would
     refuse the fourth export in a minute.

     ── AND NOT A BLOB, WHICH IS MEASURED AND NOT ASSERTED ────────────────────

     The obvious repair is to fetch the file through api() and hand over a blob
     URL. The call sites' own comment refuses it -- "the file lands in Downloads
     instead of in memory" -- and all four CSV exports return a StreamedResponse.
     Measured on this tree: at the live shop's 3,025 products the catalogue
     export is 619,968 bytes and 645 ms, and the probe that guards it is 11
     BYTES and 35 ms. The orders export measures 153 bytes per order, which is
     the one that grows without bound as the shop takes orders.

     SO THE HONEST ANSWER IS THAT 0.59 MB IS NOT RUINOUS, and the case for the
     probe does not rest on memory. It rests on CLAUDE.md rule 1: a blob is a
     different behaviour from a streamed download, the comment at every call
     site promises the streamed one, and 35 ms buys leaving a working thing
     exactly as it is.
     ══════════════════════════════════════════════════════════════════════════ */

  /* Where the sign-in page is. Derived off window.location.pathname, the way
     fixAdminApiUrl() already derives the api base -- the console is served AT
     the secret admin path, so the browser is standing on it already and reading
     it back discloses nothing. NEVER from a setting, and never interpolated
     into markup: it is assigned to location.href and nowhere else. */
  function kbbAdminLoginUrl(){
    return window.location.pathname.replace(/\/+$/,'') + '/login';
  }

  /* Ask, and classify. NEVER THROWS, so no caller needs a try. */
  async function kbbProbeDownload(url){
    var r;
    try{
      r = await fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'probe=1',
        {credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}});
    }catch(e){
      /* The request never reached a server. Reported as ITSELF and not as a
         dead session: "sign in again" is the wrong remedy for a dropped
         connection, and it is the one sentence that would send the owner to
         re-type his password over his own wifi. */
      return {verdict:'unreachable', status:0};
    }
    if(r.ok)                                 return {verdict:'ok',        status:r.status};
    if(r.status === 401 || r.status === 419)  return {verdict:'signedout', status:r.status};
    if(r.status === 403)                     return {verdict:'forbidden', status:r.status};
    return {verdict:'other', status:r.status};
  }

  /* What the console says, WITH THE CONSOLE STILL ON SCREEN. Every string here
     is a constant. `opened` is true for the window.open sites, where the tab is
     already up and the sentence has to be about the tab rather than about a
     download that never started. */
  function kbbSayDownloadRefused(answer, opened){
    if(answer.verdict === 'signedout'){
      openModal('<div class="modal-h"><b>Your session has ended</b>' +
        '<button class="x" onclick="closeModal()">✕</button></div>' +
        '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">' +
        (opened
          ? 'You are signed out, so that document could not be opened.'
          : 'You are signed out, so the download was not started — nothing was sent.') +
        '</p>' +
        '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">' +
        'Sign in again and this screen comes back exactly as it is, with your ' +
        'filters and ticks where you left them.</p>' +
        '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
        '<button class="btn ghost" onclick="closeModal()">Stay here</button>' +
        '<button class="btn" id="kbbSessionSignIn">Sign in again</button></div></div>');
      var go = document.getElementById('kbbSessionSignIn');
      if(go) go.onclick = function(){ window.location.href = kbbAdminLoginUrl(); };
      return;
    }
    if(answer.verdict === 'forbidden'){
      toast('Your account is not allowed to download this. Nothing was sent.', 'bad');
      return;
    }
    if(answer.verdict === 'unreachable'){
      toast('Could not reach the server, so the download was not started.', 'bad');
      return;
    }
    toast('The server refused that download (' + answer.status + '). Nothing was sent.', 'bad');
  }

  /* AWAIT THIS BEFORE a window.location.href. Nothing is navigated unless the
     answer is yes, which is what keeps the console on the screen. */
  async function kbbDownloadOk(url){
    var answer = await kbbProbeDownload(url);
    if(answer.verdict === 'ok') return true;
    kbbSayDownloadRefused(answer, false);
    return false;
  }

  /* AND THIS AFTER a window.open, which is the shape that cannot wait: a popup
     opened from an async continuation is blocked by the browser, so the tab has
     to be opened inside the click and the question asked behind it. `win` is
     the handle when there is one, and then the dead window is closed rather
     than left for the owner to find; window.open with 'noopener' returns none,
     so the four order documents are told after the fact and no more. */
  function kbbTellIfDownloadRefused(url, win){
    kbbProbeDownload(url).then(function(answer){
      if(answer.verdict === 'ok') return;
      if(win){ try{ win.close(); }catch(e){} }
      kbbSayDownloadRefused(answer, true);
    });
  }

  async function api(url, opts){
```

---

## Block 2 · Orders export

**Anchor** (occurs once):

```
    if(exportBtn) exportBtn.onclick = function(){
      /* A normal navigation, not a fetch: the browser carries the same admin
         session cookie, the server refuses anyone without it, and the file
         lands in Downloads instead of in memory. */
      var qs = olParams(true);
      window.location.href = fixAdminApiUrl('/admin-api/orders-export') + (qs ? '?' + qs : '');
    };
```

**Replacement:**

```
    if(exportBtn) exportBtn.onclick = async function(){
      /* A normal navigation, not a fetch: the browser carries the same admin
         session cookie, the server refuses anyone without it, and the file
         lands in Downloads instead of in memory.

         AND THE GATE IS AWAITED IN FRONT OF IT. (Lane SEC) This line is a
         navigation of the WHOLE CONSOLE, so a dead session took the screen
         away and lost the filters and ticks on it. kbbDownloadOk() asks the
         export itself first and says so on the console instead. */
      var qs = olParams(true);
      var url = fixAdminApiUrl('/admin-api/orders-export') + (qs ? '?' + qs : '');
      if(!(await kbbDownloadOk(url))) return;
      window.location.href = url;
    };
```

---

## Block 3 · Customers export

**Anchor** (occurs once):

```
    if(exportBtn) exportBtn.onclick = function(){
      /* A normal navigation, not a fetch: the browser carries the same admin
         session cookie, the server refuses anyone without it, and the file
         lands in Downloads instead of in memory. */
      var qs = cuParams(true);
      window.location.href = fixAdminApiUrl('/admin-api/customers/export') + (qs ? '?' + qs : '');
    };
```

**Replacement:**

```
    if(exportBtn) exportBtn.onclick = async function(){
      /* A normal navigation, not a fetch: the browser carries the same admin
         session cookie, the server refuses anyone without it, and the file
         lands in Downloads instead of in memory.

         AND THE GATE IS AWAITED IN FRONT OF IT. (Lane SEC) This line is a
         navigation of the WHOLE CONSOLE, so a dead session took the screen
         away and lost the filters and ticks on it. kbbDownloadOk() asks the
         export itself first and says so on the console instead. */
      var qs = cuParams(true);
      var url = fixAdminApiUrl('/admin-api/customers/export') + (qs ? '?' + qs : '');
      if(!(await kbbDownloadOk(url))) return;
      window.location.href = url;
    };
```

---

## Block 5 · Catalogue export

**Anchor** (occurs once):

```
    if(exportBtn) exportBtn.onclick = function(){
      /* A normal navigation, not a fetch: the browser carries the same admin
         session cookie, the server refuses anyone without it, and the file
         lands in Downloads instead of in memory. */
      var qs = cpParams(true);
      window.location.href = fixAdminApiUrl('/admin-api/catalog-products-export') + (qs ? '?' + qs : '');
    };
```

**Replacement:**

```
    if(exportBtn) exportBtn.onclick = async function(){
      /* A normal navigation, not a fetch: the browser carries the same admin
         session cookie, the server refuses anyone without it, and the file
         lands in Downloads instead of in memory.

         AND THE GATE IS AWAITED IN FRONT OF IT. (Lane SEC) This line is a
         navigation of the WHOLE CONSOLE, so a dead session took the screen
         away and lost the filters and ticks on it. kbbDownloadOk() asks the
         export itself first and says so on the console instead. */
      var qs = cpParams(true);
      var url = fixAdminApiUrl('/admin-api/catalog-products-export') + (qs ? '?' + qs : '');
      if(!(await kbbDownloadOk(url))) return;
      window.location.href = url;
    };
```

---

## Block 4 · Reviews export

**Anchor** (occurs once):

```
    if(exp) exp.onclick = function(){
      window.location.href = fixAdminApiUrl('/admin-api/reviews/export?' + rvParams(true));
    };
```

**Replacement:**

```
    if(exp) exp.onclick = async function(){
      /* A navigation of the WHOLE CONSOLE, gated first. (Lane SEC) The other
         four exports carry a comment saying why this is a navigation and not a
         fetch; this one never did, so it is said here: the browser carries the
         admin session cookie, the response is streamed, and the file lands in
         Downloads instead of in the tab's memory. */
      var url = fixAdminApiUrl('/admin-api/reviews/export?' + rvParams(true));
      if(!(await kbbDownloadOk(url))) return;
      window.location.href = url;
    };
```

---

## Block 6 · Bulk order documents

**Anchor** (occurs once):

```
    window.open(
      fixAdminApiUrl('/admin-api/orders-bulk-documents') +
        '?type=' + encodeURIComponent(type) + '&ids=' + ids.join(','),
      '_blank',
      'noopener'
    );
```

**Replacement:**

```
    /* Gated AFTER THE FACT, and that is the honest shape here. (Lane SEC)
       The comment above is the constraint: window.open has to happen inside
       the click, so nothing can be awaited in front of it. The tab opens,
       and the console -- which is still on the screen behind it -- is told
       whether it was ever going to work. 'noopener' returns no handle, so
       the dead tab cannot be closed from here and the modal says so. */
    var url = fixAdminApiUrl('/admin-api/orders-bulk-documents') +
      '?type=' + encodeURIComponent(type) + '&ids=' + ids.join(',');

    window.open(url, '_blank', 'noopener');
    kbbTellIfDownloadRefused(url);
```

---

## Block 7 · The four order documents

**Anchor** (occurs once):

```
        if (url) { window.open(url, '_blank', 'noopener'); return; }
```

**Replacement:**

```
        /* Gated after the fact, same constraint as the bulk documents above:
           the popup has to be opened inside the click. (Lane SEC) THE ADDRESS
           IS NOT WRITTEN IN THIS FILE -- it arrives on the payload from
           Admin\InvoiceController::invoiceUrl() and its three siblings, which
           is why no scan of the console found these four and why
           ServerBuiltAdminUrlsTest reads the SERVER for them instead. */
        if (url) {
          window.open(url, '_blank', 'noopener');
          kbbTellIfDownloadRefused(url);
          return;
        }
```

---

## Block 8 · Stripe Connect popup

**Anchor** (occurs once):

```
    try{ w=window.open(url,'kbbstripe','width=620,height=760'); }catch(e){ w=null; }
```

**Replacement:**

```
    try{ w=window.open(url,'kbbstripe','width=620,height=760'); }catch(e){ w=null; }

    /* A TENTH NAVIGATION, and the one that can be cleaned up. (Lane SEC)
       This address is admin-guarded, so an expired session shows a blank 404
       in the popup with nothing said on the console behind it. Unlike the
       order documents this one KEEPS THE HANDLE, so the dead window is
       closed rather than left for the owner to find. Asked after the open
       for the reason the comment above gives: a round trip in front of
       window.open is what the popup blocker is looking for. */
    if(w) kbbTellIfDownloadRefused(url, w);
```

---

## Block 9 · The Catalog list blames route wiring for a 401

**Anchor** (occurs once):

```
    }catch(e){
      CP.err = e && e.message ? e.message : 'unknown error';
      body.innerHTML = '<div class="card pad"><p style="color:var(--sale,#c0392b);font-size:13px">' +
        'The product list could not be loaded — ' + sesc(CP.err) + '</p>' +
        '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">If this is a fresh deployment, the ' +
        'Catalog → Products routes may not be wired into routes/web.php yet.</p></div>';
      return;
    }
```

**Replacement:**

```
    }catch(e){
      /* SAY WHICH FAILURE IT WAS. (Lane SEC) This screen answered every
         refusal with "the routes may not be wired into routes/web.php yet",
         which for an EXPIRED SESSION sends the owner to Store -> Cache to
         clear a route cache that is perfectly fine, while the one thing that
         would fix it -- signing in again -- is never mentioned. The route
         sentence is kept for the fault it was written for, and is now shown
         only for that fault. Worded the way
         admin/partials/product-editor-screen.blade.php already words it. */
      CP.err = e && e.message ? e.message : 'unknown error';

      if(e && (e.status === 401 || e.status === 419)){
        body.innerHTML = '<div class="card pad"><p style="color:var(--sale,#c0392b);font-size:13px">' +
          'Your session has ended, so this list could not be loaded.</p>' +
          '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">' +
          'Sign in again and it loads as it did.</p></div>';
        return;
      }

      body.innerHTML = '<div class="card pad"><p style="color:var(--sale,#c0392b);font-size:13px">' +
        'The product list could not be loaded — ' + sesc(CP.err) + '</p>' +
        (e && e.status === 404
          ? '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">If this is a fresh deployment, the ' +
            'Catalog → Products routes may not be wired into routes/web.php yet.</p>'
          : '') + '</div>';
      return;
    }
```

---

## Block 10 · Sign out did not sign anybody out

**Anchor** (occurs once):

```
      b.onclick=async function(){
        try{ await fetch('/admin/logout',{method:'POST',headers:{'X-XSRF-TOKEN':cookie('XSRF-TOKEN')},credentials:'same-origin'}); }catch(e){}
        location.href='/admin/login';
      };
```

**Replacement:**

```
      b.onclick=async function(){
        /* THE ADMIN PATH IS NOT ALWAYS "admin". (Lane SEC) Both addresses
           below were literals, and routes/web.php answers `/admin/{any?}`
           with abort(404) the moment the owner moves his admin path -- which
           is the point of the setting, and a control he has on Store -> Core
           Updates. So this button posted to a 404, SWALLOWED IT, and then
           navigated to a second 404.

           MEASURED, on a preview whose admin lives at /sec-console:
           POST /admin/logout -> 404, GET /admin/login -> 404, and
           /admin-api/stats answered 200 immediately afterwards. He pressed
           Sign out, was shown a blank 404, AND WAS STILL SIGNED IN -- on a
           shared machine that is the whole of the damage.

           Derived off window.location.pathname, the way fixAdminApiUrl()
           already derives the api base: this console is served AT the admin
           path, so the browser is standing on it and nothing is disclosed by
           reading it back. Never from a setting. */
        var base=window.location.pathname.replace(/\/+$/,'');
        try{ await fetch(base+'/logout',{method:'POST',headers:{'X-XSRF-TOKEN':cookie('XSRF-TOKEN')},credentials:'same-origin'}); }catch(e){}
        location.href=base+'/login';
      };
```

---

