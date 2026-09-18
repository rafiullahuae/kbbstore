# Media and image paths · the URL redirect map

Lane GB. Phase 13's last open item, checked against a running server rather than
against the plan.

The plan says this item is **"blocked on the two URL decisions in Phase 9"**. It
is not, and it never was. Two separate things were true at once and neither was
what the plan described:

1. **The commands already existed.** `kbb:import-redirects` and
   `kbb:import-media` were built, tested and written up in
   `docs/IMPORT-RUNBOOK.md` §10 a round ago. **The owner cannot run either of
   them** — there is no shell on this host — so §10 is a page of commands for a
   person with no way to type one. That, not a URL decision, is why nothing has
   moved.

2. **The map that existed was aimed at the wrong addresses.** Every row it put
   in its `migrate` bucket was inert, and the addresses the old site really
   published had no row at all. Both halves are proved below by fetching, not by
   reading code.

---

## 1. What is true about the two URL decisions

Phase 9 has two URL items. Read them:

| Phase 9 item | State |
|---|---|
| `/brands/` | **`[x]` — decided in 2.60.109.** The owner confirmed `/korean-skincare-brands/` is the live address; `/brands/` 301s to it. |
| `/skincare-guide/` | **`[ ]` — open, and Lane GA has it this round.** |

So one of the "two decisions" has been made for some weeks, and the other is a
blog-permalink question. **Neither one is upstream of the category and image
work**, which is what this item is about. The plan line is stale in the same way
CLAUDE.md warns about; §9 of this document carries the replacement.

---

## 2. The finding, proved by fetching

### 2.1 What was shipped

`App\Services\Import\RedirectMap` says of itself:

> THE ONE RULE THE DATA ACTUALLY PROVES is the category one. WooCommerce sites
> commonly publish a category at its leaf slug — `/product-category/serums/`

and proposes, for every nested category, a redirect from
`/product-category/{leaf}/` to `/product-category/{nested/path}/`.

### 2.2 Both halves of that are wrong

**The source shape is not what kbeautybliss.com served.** `App\Support\LegacyCategoryUrls`,
written by a lane that had looked at the live site, says in as many words:

> kbeautybliss.com served its category archives at the site root — `/toners/`,
> `/sunscreens/`, `/cleansing-oils/` — because that is what its WooCommerce
> permalink settings produced

and lists fifteen of them, copied off the live navigation. The same shape is
corroborated twice more: `2026_09_09_040000_seed_kbeautybliss_menu` seeds the
live site's own menu and every category row in it is a flat root path, and
`2026_09_14_160000_seed_phase9_post_url_redirects` records **the owner
confirming** that articles live at the site root with no prefix. One permalink
setting, one shape, and the articles half of it is confirmed by him directly.

**And the rows it wrote could never have fired anyway.** `CheckRedirects` is
written as middleware and is **not registered as one** — its own doc comment
records that a redirect on a matched route still returned 200 that way, from
both `boot()` and `register()`. The live check is the
`NotFoundHttpException` closure in `AppServiceProvider`. So the redirects table
is consulted **only on a 404**, and `/product-category/serums/` does not 404:
`CategoryArchiveController` → `CategoryPath::resolve()` 301s it to the nested
path by itself.

### 2.3 The transcript

A preview server (`php -S`, `SESSION_DRIVER=file`, web root
`gb/public-web-root` whose parent holds `bootstrap/` and `vendor/`, vendor
hard-linked rather than symlinked), with a category `toners` nested under
`skincare`:

```
/product-category/toners/          301  http://…/product-category/skincare/toners
/toners/                           404
/toners                            404
/sunscreens/                       404
/shop/                             200
```

`/product-category/toners/` already 301s **with no redirect row in the database
at all.** A row was then written for exactly that source, pointing at
`/PROOF-INERT/`:

```
/product-category/toners/          301  http://…/product-category/skincare/toners
```

The row changed nothing. It is stored, it is enabled, it matches `getPathInfo()`
byte for byte, and it is never read. Meanwhile `/toners/` — the address Google
actually holds — 404s, and nothing proposed a redirect for it.

Pinned as a test in `tests/Feature/GbUrlsAndMediaTest.php`
("it proves a redirect row for an address the shop already moves is never read"
and its sibling).

### 2.4 After

Same server, after `php artisan kbb:import-redirects --write`:

```
/product-category/toners/          301  http://…/product-category/skincare/toners   (unchanged: the shop's own)
/toners/                           301  http://…/product-category/skincare/toners
/toners                            301  http://…/product-category/skincare/toners
/sunscreens/                       301  http://…/product-category/sunscreens
/shop/                             200                                             (untouched)

curl -L /toners/  →  final=200  url=…/product-category/skincare/toners
```

A second `--write` reports `0 new, 0 corrected, 14 already right`. `--rollback`
removes exactly its own 14 and `/toners/` goes back to 404.

---

## 3. What changed in the map

### `App\Services\Import\SourceReachability` — new

Answers one question per address: **will a stored redirect for this ever fire?**

| verdict | meaning | what the map does |
|---|---|---|
| `notfound` | nothing serves it; the 404 handler is reached | write the redirect |
| `moved` | the application already 301s it on its own | `discard`, with the destination named |
| `served` | a real page answers here | `ask` — moving it is a routing change, not a redirect |
| `unknown` | a parameterised route claims it and this cannot say what its controller will find | `ask`, stated as such |

It does **not** dispatch the request — re-entering the kernel once per proposal,
with another request's session and locale state, is not a thing to build. It
asks what the storefront asks, in the router's own order:

* `/product-category/…` goes through `CategoryPath::resolve()`, which is the
  exact call `CategoryArchiveController::show()` makes.
* everything else is matched against the **real route table**. A route with no
  parameters serves a page or does not exist. A route *with* parameters cannot
  be judged from its URI, so the three that can plausibly claim an address this
  map proposes — a post, a page, a product — are resolved against their own
  tables, and anything else answers `unknown`.

`unknown` is a real answer and is not folded into `notfound`. Folding it in
writes a row on a guess.

The route table is used rather than a hard-coded list of "addresses the shop
serves", because such a list is a filter that rots: `/routines` shipped this
month and `/subscribe` the month before.

### `RedirectMap::fromLegacyRootCategories()` — new rule

For every imported category, `/{slug}/` → `/product-category/{path}/`.

Evidence and inference are kept apart **on the row**:

* **corroborated** — the fifteen in `LegacyCategoryUrls::PATHS`, copied off the
  live navigation. The shape is not a guess for these.
* **inferred** — every other category. WooCommerce has one product-category base
  for the whole site, so a shop that served `/toners/` served `/serums/` too.
  The row says so in its own reason and the report counts the two separately.

Writing the inferred ones is the right call because the error is asymmetric, and
`SourceReachability` proves per row that these addresses 404 today. A redirect
written for an address the old site never served receives no traffic and costs
one row; an address the old site *did* serve and that carries no redirect loses
every visitor and every link still pointing at it.

**Both slash forms are proposed.** `CheckRedirects::findMatch()` compares
`source` against `getPathInfo()` with a plain equality, and `getPathInfo()` keeps
whatever the client sent, so `/toners` and `/toners/` are two different keys.
This follows the precedent `2026_09_14_160000_seed_phase9_post_url_redirects`
set for the five confirmed articles, in its own words: *"one extra row per
article is a much smaller price than a 404 on a URL somebody actually posted."*

### `RedirectMap::reachable()` — the guard

Applied to every `migrate` proposal, and to its destination. A `moved` source
becomes `discard` rather than `ask`, because "the shop already does this" is an
answer and not a question — `docs/FV-IMPORT-AT-VOLUME.md` §10 is explicit that a
question list which is mostly noise is a question list nobody finishes.

The destination is checked for one failure only: a 301 whose target 404s is
worse than the 404 it replaces, because it tells a search engine the address was
replaced by nothing. That branch is **reachable** and is tested — a stale
`categories.path` (a row that missed `CategoryImporter::recomputeTree()`) names a
leaf that is not a category, and the destination built from it does not exist. A
branch no input can enter is the dead filter `Api\ProductController` already cost
this repository once, so it was checked rather than assumed.

### The old rule is annotated, not deleted

`fromCategoryNesting()` still runs and still derives the nested address
correctly. Its rows now land in `discard` with the reason on each one. The class
header carries a banner naming both of its bad premises and the measurement that
refuted each. The rule is how the two shapes are related, and a reader who
deletes it will re-derive it; a reader who finds it with its refutation attached
will not.

---

## 4. Media: every product photograph is hot-linked to WordPress

### 4.1 Confirmed, not assumed

`ProductImporter` copies the image column as a string — line 267 for `image`,
line 298-304 for the gallery. A WooCommerce product export writes that column as
a full URL on the site it came from. The checked-in fixture this repository pins
the importer against, `tests/Fixtures/woo/products.csv`, carries exactly that:

```
image  = https://kbeautybliss.com/wp-content/uploads/2019/03/ginseng-serum.jpg
images = https://kbeautybliss.com/…/ginseng-serum-2.jpg,https://…-3.jpg
```

So the answer to the question in the brief is **yes**: after a clean, fully
verified import, every product image on the new shop is served by WordPress and
dies the day it is switched off. `MediaAudit` counts them; nothing moved them.

### 4.2 ▲ And the count that was supposed to warn about it included the shop's own photographs

`MediaAudit::judge()` read "this URL has a host" as "this image is still on the
old site". But **every image picked from the Media Library is stored absolute**:
`MediaUploadController` writes `Media::urlFor($path)`, which for an admin upload
(`uploads/…`) builds `site_url . '/' . $path` — a full URL with a host — and
`ProductEditorApiController` stores whatever the editor sent straight into
`products.image`.

So on a shop that has never run a WooCommerce import at all, every photograph
the owner uploaded himself counted as "STILL ON THE OLD SITE". `remote` is the
number `docs/IMPORT-RUNBOOK.md` tells him to watch, and it was the number that
decides whether the migration is finished.

Fixed: the shop's own host (`site_url`, falling back to `app.url` — the same
pair `Media::urlFor()` uses, so the two cannot drift) is resolved to a path and
judged locally. Matching is on **host only**: scheme moves (http → https behind
the host's TLS proxy, which `AppServiceProvider` forces in production) and a
port does not make a file a different file. The site's own subfolder comes off
the path first, because `public_path()` **is** that subfolder on disk —
`/kbb-upgrade/uploads/x.png` is `public_path('uploads/x.png')`.

### 4.3 `App\Services\Import\MediaRewrite` — new

The half that was missing: something that can actually bring `remote` down.

It does **not** fetch. `MediaAudit`'s header gives the reasons and they still
hold: no shell, the old site may already be gone, and a half-succeeding
downloader leaves the owner worse off than a list. The owner copies
`wp-content/uploads` across by FTP the way every WordPress migration does, and
*then* this re-points the rows at the copy.

Three properties, each of them a rule that cost something to get wrong
elsewhere in this repository:

* **It never guesses which host is the old shop.** Run it bare and it lists
  every host the catalogue's images point at, with a count, marking the shop's
  own. Nothing is rewritten until one is named. Guessing "the most common host"
  is how a CDN, a supplier's photograph or a partner's banner gets rewritten
  into a local path that does not exist.
* **It only rewrites a row whose file is already on disk.** Rewriting first and
  copying afterwards turns a page that renders into a page of broken frames,
  with nothing on the shop to say which rows were touched.
* **It is exactly invertible, with no ledger table.** The transformation drops a
  scheme and a host and keeps the path byte for byte, so `restore()` puts the
  host back. A table recording what was done is a table that can disagree with
  the rows. A row an admin has since re-pointed is kept and named, the same rule
  `kbb:import-redirects --rollback` follows.

The value written is `Media::urlFor($relative)` — the application's own media URL
builder, and the single place that knows this shop has two upload roots that are
not interchangeable (`wp-content/uploads/` from the import, `uploads/` from an
admin upload). Nothing new is invented.

### 4.4 The base path, in the opposite direction from the redirects

`Media::urlFor()` for a `wp-content` path goes through `Url::media()` →
`Url::raw()`, which **adds** `KBB_BASE_PATH`. So a row is written as
`/kbb-upgrade/wp-content/uploads/…`.

That is deliberate and it is not the mistake `RedirectMap`'s header warns about.
The two columns are read in opposite ways:

| column | read by | prefix |
|---|---|---|
| `redirects.source` | compared against `getPathInfo()`, which **strips** the base path | must **never** carry it |
| `products.image` | printed straight into a `src` by `store/home.blade.php`, `partials/home/grid.blade.php` and the rest — no helper, no prefixing | must **always** carry it |

Both are pinned, in the same test file, so the pair cannot drift apart.

The cost is that a base-path change invalidates every stored image path — and
the host rewrite cannot fix it, because by then the rows carry no host for it to
filter on. `MediaRewrite::proposeRebase()` is the answer and is a separate entry
point on purpose: "take the pictures off WordPress" is a migration step done
once, naming a host; "the shop moved out of its subfolder" is maintenance,
names nothing, and must not be something a migration run can do by accident.

`uploadsRelative()` is what makes both survive: it cuts at the **uploads root**,
which does not move, rather than at whatever `Url::base()` returns today.
Anything under neither root — an address an admin typed by hand — is skipped
rather than "corrected".

---

## 5. ▲ The screen, because the owner cannot run any of this

`Admin\UrlsMediaApiController` + `routes/urls-media-admin.php` +
`2026_11_21_000000_clear_caches_urls_and_media.php`.

```
GET  /admin-api/urls-media/status     the whole picture, one call
GET  /admin-api/urls-media/map.csv    every proposed row, all three buckets
POST /admin-api/urls-media/redirects  {action: write|rollback}
POST /admin-api/urls-media/media      {action: preview|apply|restore|rebase|rebase-apply, hosts: []}
```

**Yes, Store → Import can drive it — and it does not need `ImportDriver`'s step
loop.** That machinery exists because 4,159 orders cannot be written in one
request on a host whose `max_execution_time` cannot be discovered from inside
PHP. Neither of these jobs is that shape:

* the URL map is O(categories) — **59** on the real export
  (`docs/FV-IMPORT-AT-VOLUME.md` §2) — a handful of queries plus one route match
  per proposal;
* the media audit is O(image references) — one `is_file()` each, about **2,600**
  on this catalogue, answered from the kernel's dentry cache.

A step loop for work of that size is machinery with nothing to do, and machinery
with nothing to do is where the next resume bug lives. What the controller does
instead is cap what it *sends back* (50 rows a bucket; the counts are always
complete), because a 4MB JSON response on a shared host is its own kind of
timeout. If the catalogue ever outgrows that, what changes is the controller —
both services are pure functions of the database and hold no state between
calls.

Nothing writes on a GET, for the reason `routes/import-admin.php` gives at
length. Every route is mounted inside the existing `admin-api` group that
carries `auth:admin` and `NoStoreAdminApi`, and
`tests/Feature/GbUrlsAndMediaTest.php` asserts a 401 on all four.

---

## 6. Changes for files this lane may not edit

Every anchor below was checked by count to occur **exactly once** in the file
named.

### 6.1 `routes/web.php` — mount the screen's endpoints

Anchor (inside the existing `admin-api` group — the one that already carries
`auth:admin` and `NoStoreAdminApi`; `RouteRegistrar::middleware()` *replaces*
rather than appends, so a fresh chained registration would silently drop the
guard):

```php
        require __DIR__.'/import-admin.php';
```

Replacement:

```php
        require __DIR__.'/import-admin.php';

        // Store → Import → "Addresses & pictures" (Lane GB). Same group and the
        // same reason: one of these endpoints writes the redirect rows that
        // move every visitor who lands on an address this shop does not serve,
        // and another rewrites every image path in the catalogue.
        require __DIR__.'/urls-media-admin.php';
```

### 6.2 `KBB-Master-Plan.md` — Phase 13

Anchor:

```markdown
- [ ] Media and image paths · URL redirect map — **needs the two URL decisions in Phase 9**
```

Replacement:

```markdown
- [x] **Media and image paths · URL redirect map — *this package*.** Not blocked
  by Phase 9, and never was: `/brands/` was settled in 2.60.109 and
  `/skincare-guide/` is a blog-permalink question that sits downstream of
  neither categories nor images. ▲ **The map that existed could not fire.**
  `CheckRedirects` is not registered as middleware, so the redirects table is
  consulted only from the 404 handler — and every row the shipped rule proposed
  was for `/product-category/{leaf}/`, which the archive controller already 301s
  itself. Proved against a running server with a deliberately wrong row in
  place: the row changed nothing. ▲ **And the addresses Google really holds had
  no row at all.** kbeautybliss.com served its categories flat at the site root
  (`LegacyCategoryUrls`, the seeded live navigation, and the owner's own
  confirmation of the same shape for articles); `/toners/` and `/sunscreens/`
  404'd. Both slash forms are now written, per the Phase 9 seed's precedent, and
  a reachability check demotes any row that could not fire. ▲ **Every product
  photograph is hot-linked to WordPress** — the importer copies the export's
  absolute URL — and the `remote` count that was meant to warn about it was
  counting the shop's own Media Library uploads, because every one of those is
  stored absolute too. `kbb:import-media-rewrite` re-points them once the
  uploads folder is across, refuses to touch a file that is not there, and is
  exactly reversible. **All of it is driven from Store → Import**, which is the
  whole point: the two commands the runbook documents have been unusable since
  the day they were written, because the owner has no shell. See
  `docs/GB-MEDIA-AND-REDIRECTS.md`
```

### 6.3 `resources/views/admin/app.blade.php` — the screen itself

Three anchors, each unique.

**(a) Draw the card.** Anchor:

```js
    +impRejectsCard(s)
    +impExportCard()
    +'</div>';
```

Replacement:

```js
    +impRejectsCard(s)
    +gbUrlsMediaCard()
    +impExportCard()
    +'</div>';
```

**(b) The card and its behaviour.** Anchor:

```js
/* ------------------------------------------------------------------- wiring */

function impWire(){
```

Replacement:

```js
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
    +'<div class="impgrid" style="margin-top:8px">'
    +'<div class="impfile"><b>'+u.buckets.migrate.count+'</b><span>redirects to write</span></div>'
    +'<div class="impfile"><b>'+u.buckets.ask.count+'</b><span>need your decision</span></div>'
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
    +'</div>';
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

  const rebase=$('#gbUMRebase');
  if(rebase) rebase.onclick=()=>gbUMPost('/urls-media/media',{action:'rebase-apply'},
    d=>d.rewritten?(d.rewritten+' picture path(s) re-spelled for this shop\'s folder.'):'Every picture path already matches this shop\'s folder.');
}

/* ------------------------------------------------------------------- wiring */

function impWire(){
```

**(c) Wire it.** Anchor:

```js
  const reset=$('#impReset');
  if(reset) reset.onclick=async()=>{
```

Replacement:

```js
  gbUMWire();

  const reset=$('#impReset');
  if(reset) reset.onclick=async()=>{
```

### 6.4 `app/Http/Middleware/CheckRedirects.php` — offered, deliberately not applied

Every redirect this table serves lands on an address **without its trailing
slash**. `redirect()` hands the target to Laravel's `UrlGenerator`, which strips
one — the same behaviour `docs/FQ-ROUTES.md` records for `route()`. So a row
stored as `/product-category/skincare/toners/` sends the visitor to
`/product-category/skincare/toners`, which answers 200 and is not the canonical
form U-01 defines. Measured; asserted as it really is in
`tests/Feature/GbUrlsAndMediaTest.php` rather than as it ought to be.

Anchor:

```php
        return redirect($redirect->target, $redirect->code);
```

Replacement:

```php
        return redirect(\App\Support\Url::redirect($redirect->target), $redirect->code);
```

`app/Providers/AppServiceProvider.php` carries the copy that **actually runs**,
and it needs the same change. Anchor (twenty spaces of indent, and unique):

```php
                    return redirect($redirect->target, $redirect->code);
```

Replacement:

```php
                    return redirect(\App\Support\Url::redirect($redirect->target), $redirect->code);
```

**Not applied by this lane, on purpose.** It changes the destination of every
redirect in the table, including the five Phase 9 article rows and anything an
admin has typed, and it is shared middleware two other lanes touch this round.
It is a one-line change with a blast radius the size of every 301 the site
serves, and it wants its own package and its own before/after fetches. The
damage meanwhile is one non-canonical landing page, not a 404.

### 6.5 `docs/IMPORT-RUNBOOK.md` §10 — the sentence that is now wrong

Anchor:

```markdown
### `kbb:import-media` — do the pictures exist?
```

Replacement:

```markdown
### `kbb:import-media` — do the pictures exist?

> **None of §10 is runnable by the owner.** There is no shell on this host.
> Everything below is now also on **Store → Import → "Addresses & pictures"**,
> which drives the same two services; these commands are for CI, for the
> integrator and for a rehearsal on a copy. See
> `docs/GB-MEDIA-AND-REDIRECTS.md`. Note also that `kbb:import-redirects` no
> longer proposes `/product-category/{leaf}/` rows — the shop already 301s those
> itself, so the rows could never fire — and proposes the root-flat addresses
> kbeautybliss.com really published instead.
```

---

## 7. Mutation testing

Every guard was broken, watched, and restored, against
`tests/Feature/GbUrlsAndMediaTest.php` (29 tests).

| # | mutation | result |
|---|---|---|
| M1 | `SourceReachability` stops reporting a category 301 as `moved` | **RED** (3 failed) |
| M2 | `reachable()` stops demoting a `served` source to `ask` | **RED** |
| M3 | the legacy-root rule stops emitting the slashless spelling | **RED** |
| M4 | `reachable()` stops demoting a `moved` source to `discard` | **RED** |
| M5 | `reachable()` stops checking the destination exists | **RED** |
| M6 | the legacy-root rule is removed altogether | **RED** (8 failed) |
| M7 | `MediaAudit` stops recognising this shop's own host | **RED** (2 failed) |
| M8 | `MediaAudit` stops taking the subfolder off an own-host URL | **RED** |
| M9 | `MediaRewrite` stops checking the file is on disk | **RED** |
| M10 | `uploadsRelative()` stops cutting the base path off | **RED** (2 failed) |
| M11 | `restore()` stops refusing a row somebody else re-pointed | **RED** |
| M12 | `MediaRewrite` rewrites every host, not only the named ones | **RED** |
| M13 | `uploadsRelative()` tries the short upload root first | **RED** (4 failed) |
| M14 | `proposeRebase()` touches paths under neither upload root | **RED** |
| M15 | a literal page route is called `served` without checking the `pages` table | **RED** |

**One mutation stayed green on the first pass and is reported rather than
hidden**, because that is where Lane FV's write-up was most useful. The
original `underWebRoot()` stripped `Url::base()`, and breaking it changed
nothing: in `propose()` the paths come from the OLD host and never carry this
shop's base, so the strip was doing no work there, and no test restored a row
that had been localised under a subfolder. Two things came out of chasing it:

* a **real defect in this lane's own design** — the class comment claimed that
  re-running `propose()` would correct a base-path change, and it would not,
  because by then the rows carry no host to filter on. That is what
  `proposeRebase()` is for;
* the strip was replaced by `uploadsRelative()`, which cuts at the uploads root
  instead, and which survives a base change that `Url::base()` cannot see.

Both are now covered (M10, M13, M14 above).

---

## 8. What was deliberately not done

* **No product redirects.** §9 is the question to the owner. The repository
  records the old site's URL shape for categories, articles and brand listings
  and says nothing at all about products. WooCommerce's default product base and
  this shop's U-01 are both `/product/{slug}/`, and `RedirectMap` reports that as
  a finding with its count — but a site that removed its *category* base plainly
  had non-default permalink settings, so "the product base is default" is an
  assumption, not evidence. Inventing a shape here would send the shop's highest-
  intent traffic to 404s, which is worse than no map.

* **No brand-archive redirects.** Unchanged from what `RedirectMap` already said,
  and now with one more piece of evidence: the seeded live navigation uses
  `/shop/?filter_brands={slug}` and `/korean-skincare-brands/`, which is U-05's
  shape already. Whether the old site *also* had `/brand/{slug}/` archives
  depends on which plugin it ran. §9.

* **No `CheckRedirects` change.** §6.4 says why, with the patch ready.

* **No downloader.** §4.3.

* **Nothing touching `routes/kbb-brands-blog.php` or the blog controllers** —
  Lane GA's this round. §10 covers the interaction.

* **No new table.** The map writes into `redirects`, which has existed since the
  original schema migration; the rewrite edits four columns that already exist.
  Reversibility is by construction, not by a ledger.

---

## 9. What only the owner can settle

1. ~~**What address did a PRODUCT live at on kbeautybliss.com?**~~ **ANSWERED BY
   THE OWNER — and the answer is the good one: there is nothing to do.** He
   pasted a live product address:

   ```
   https://kbeautybliss.com/product/medicube-vanilla-deodrant-and-body-mist-duo/
   ```

   So the product base was **kept**, unlike the category base, and it is the
   shape this application already serves. Verified rather than assumed, by
   fetching that exact slug through the real route table:

   | URL | Status | canonical |
   | --- | --- | --- |
   | `/product/medicube-…-duo/` | **200** | `…/product/medicube-…-duo/` (self) |
   | `/product/medicube-…-duo` (no slash) | **200** | the slashed form |
   | `/ar/product/medicube-…-duo/` | **200** | — |

   **All 671 product URLs already resolve, in both languages, and each
   self-canonicalises.** No row is needed, no `permalinks.csv` is needed, and
   the largest bucket this map might have had does not exist. This was the
   single biggest SEO risk left in the migration and it turned out to be
   nothing — which is only knowable because the address was checked instead of
   guessed.

2. **Did the old site have brand archive pages, and at what base?** `/brand/`,
   `/product-brand/`, something else, or none at all because brands were only
   ever a filter on the shop page. 93 rows hang on the answer, and inventing a
   base writes 93 redirects from an address that may never have existed.

3. **Approve the map's three buckets.** Phase 13 says he approves the discard
   list. `GET /admin-api/urls-media/map.csv` — the "Download the whole map"
   button — is every row with its reason, to open in a spreadsheet. The
   `migrate` bucket is what will be written; `ask` is what needs him.

4. **Is `wp-content/uploads` copied across yet?** Nothing re-points a picture
   until the file is on this host. `remote` on the screen is the number that says
   whether the shop still depends on WordPress, and it is the last thing to go to
   zero before the old site can be switched off.

5. **`/skincare-guide/`** — Lane GA's, named here only so this list is not read
   as complete.

---

## 10. The interaction with Lane GA, and with the storefront's own routes

Not editing `routes/kbb-brands-blog.php`, as instructed. The interaction is
worth writing down because it is the one way a row this map writes can go quiet
later.

That file ends in `/{slug}/` — the single-segment catch-all that serves a blog
post. **Every address this map writes is a single root segment**, so the
catch-all matches all of them. They work only because `PageController::post()`
throws a 404 for a slug with no published post, and the 404 handler is where the
redirect is read.

So:

* **Publishing an article at a slug a category used to hold silently disables
  that category's redirect.** The article wins — it answers 200 — and the row
  sits in the table looking correct with its `hits` counter frozen. This is
  pinned as a test ("it calls a root address served once a post is published at
  it") and `SourceReachability` reports it as `served` at the next run, which
  moves the row into the `ask` bucket with the reason. It cannot be prevented
  from here and should not be: an article the owner published is a real page and
  a redirect is not entitled to take its address.

* **If Lane GA moves articles off `/skincare-guide/{slug}/` onto the root**, the
  root namespace gets busier and the above gets more likely. Nothing needs to
  change in this lane's code — the reachability check already reads the `posts`
  table — but the map should be re-run after that package lands, because rows
  written before it may have become inert.

* **Collections and the storefront's own pages are safe.** `/super-sale/`,
  `/everything-under-54-aed/`, `/new-in/`, `/best-sellers/`,
  `/korean-skincare-brands/`, `/skincare-guide/`, `/shop/` and the policy pages
  all have their own parameterless routes, so `SourceReachability` answers
  `served` and any proposal for them lands in `ask`, never in a written row.
  Tested with a category deliberately slugged `shop`.

A parameterless route whose controller can still 404 is handled rather than
rounded off. The seven WordPress pages are literal routes carrying
`->defaults('slug', 'about')` and friends, and `PageController::show()` does
`firstOrFail()` on that slug — so `/about/` 404s if the `pages` row has been
deleted, while its route still matches. `SourceReachability` reads the slug out
of the route's own defaults and checks the table, which also means it cannot
drift from the route list the way a hard-coded list of seven slugs would.
Tested in both states, and mutation-tested (M15): the first draft stopped at the
route, which is a branch that can only be observed in one of the two states it
has to be right about.

What is still approximate: a parameterless route whose controller can 404 for
some *other* reason — a module gated off, say — reads as `served`. The error
direction is the safe one, a question instead of a written row.

---

## 11. Files

| File | |
|---|---|
| `app/Services/Import/SourceReachability.php` | new — will a redirect for this address ever fire? |
| `app/Services/Import/RedirectMap.php` | the legacy-root rule, the reachability pass, and both bad premises annotated |
| `app/Services/Import/MediaAudit.php` | this shop's own host is no longer "the old site" |
| `app/Services/Import/MediaRewrite.php` | new — take the photographs off WordPress, reversibly |
| `app/Console/Commands/ImportMediaRewrite.php` | new — `kbb:import-media-rewrite` |
| `app/Http/Controllers/Admin/UrlsMediaApiController.php` | new — the screen's four endpoints |
| `routes/urls-media-admin.php` | new — unmounted; §6.1 is the one line |
| `database/migrations/2026_11_21_000000_clear_caches_urls_and_media.php` | new — four routes ship, so the compiled route table has to go |
| `tests/Feature/GbUrlsAndMediaTest.php` | new — 29 tests |
| `tests/Support/UrlsMediaAdminRoutes.php` | new — mounts the route file the way §6.1 says to |
| `tests/Feature/ImportUrlAndMediaTest.php` | four tests moved onto the addresses that can actually fire, each with its own note on what it used to assert |

## 12. Reproducing the fetches

```bash
git worktree add /home/user/kbb-wt/gb -b lane/media-paths-and-redirects
cd /home/user/kbb-wt/gb
cp -al /home/user/kbbstore/vendor ./vendor    # hard links, NOT a symlink: a
                                              # symlinked vendor resolves
                                              # Composer's $baseDir back to the
                                              # main repo and runs that code
cp -al public/build public-web-root/build     # public_path() is public-web-root

php artisan migrate --force
# seed a nested category, then:
export KBB_PUBLIC_PATH=$PWD/public-web-root SESSION_DRIVER=file
php -S 127.0.0.1:8944 -t public-web-root router.php &

curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://127.0.0.1:8944/toners/
php artisan kbb:import-redirects            # look
php artisan kbb:import-redirects --write    # apply
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://127.0.0.1:8944/toners/

php artisan kbb:import-media                # do the pictures exist?
php artisan kbb:import-media-rewrite                            # which hosts?
php artisan kbb:import-media-rewrite --host=kbeautybliss.com --write
php artisan kbb:import-media                # remote: 0
```
