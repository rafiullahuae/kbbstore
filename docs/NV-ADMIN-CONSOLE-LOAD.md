# The admin console's load, measured

Lane NAV, 29 September 2026. Written because the owner reported it with a
screenshot and the answer turned out to be arithmetic rather than opinion.

> "ALSO upon backend admin refresh, some pages don't show immidiately and it
> takes long to long, and then it shows like i have attached the screenshot,
> when i load the page, such options shows, but under some parent menus some
> sub menues don't show. like Appearance > as shown. please fix this bug of
> delay loads of some pages in admin panel."

Two complaints, two different causes, and neither is a slow endpoint.

---

## 1. The missing sub-menus

### The console is one document, and the sidebar is spread across all of it

`GET /admin` answers with a single HTML document. On the tree this lane started
from it was **3,425,404 bytes**. Inside it:

| | byte | % of the document |
|---|---:|---:|
| `buildNav()` runs — the sidebar is drawn with **56** rows | 731,657 | 21.4% |
| first screen partial registers its own row (Coupons) | 1,514,392 | 44.2% |
| Homepage content | 2,302,738 | 67.2% |
| Shoppable video | 3,039,826 | 88.7% |
| Instagram | 3,237,237 | 94.5% |
| Banners | 3,326,631 | 97.1% |
| **`Appearance → Set`** — the last of them | **3,388,358** | **98.9%** |

(Those figures are the tree this lane was cut from. After Lane OD's and Lane
FB's work merged in, the document is 3,439,696 bytes and the same four points
sit at 5.5%, 21.7%, 21.7% and 38.9% — the percentages are what matter and they
do not move. The guard test derives its thresholds from the document rather than
hard-coding a number, for exactly this reason.)

Twenty-one of the console's seventy-seven sidebar rows are not in `NAV`. Each
belongs to a screen that ships as its own partial, and a partial cannot call
`window.kbbAddNavEntry` before the block that defines it — so every one of those
scripts sits at the end of the document and registers its row when it runs.

**A row therefore does not exist until the byte that creates it has arrived.**
That is the whole bug. It is not a rendering fault and it is not a race; it is
the position of a line in a file.

### Reproduced

Chromium, cold, cache disabled, throttled to 2 Mbit/s with the CPU at one
quarter, against the store's real 2,419 products. Sidebar sampled every 150 ms:

```
   4,290 ms   56 rows   Appearance holds 10        <- the owner's screenshot
  14,554 ms   57 rows   Appearance holds 10
  15,031 ms   58 rows   Appearance holds 10
  16,170 ms   77 rows   Appearance holds 18
```

Appearance held ten rows for **11.9 seconds**: Homepage, Product styles, Mobile
Header, Section dividers, Login / Register panel, Header, Mobile menu, Product
page, Quantity bundles, Product grid — and not Homepage content, Banners, Cart
panel, Cart page, Checkout page, Footer, **Set** or Site layout. That is the
screenshot, item for item.

### The timeline: when each row appears

Sampled every 100ms from inside the page, same cold throttled load, recording
the first sample in which each row exists. DOMContentLoaded was 16,190ms before
and 16,303ms after — the document is the same size either way, which is the
point: nothing here is about the document arriving sooner.

**Before**

```
  3,323 ms  +56 rows
            Appearance   Homepage, Product styles, Mobile Header, Section dividers,
                         Login / Register panel, Header, Mobile menu, Product page,
                         Quantity bundles, Product grid
            Catalog      Catalog
            Platform     K-Beauty Bliss Theme, Users & Roles, Settings, Site address
            Store        16 rows, no New Order / Coupons / Gateway webhooks / Security
            Content      Blog Posts, HTML Blocks, Media Library
            + Safety, Pages, Growth & Marketing, Reviews, Translation, and the
              three top-level rows

 14,595 ms  +1   Content: Shoppable video
 15,111 ms  +1   Content: Instagram
 16,298 ms  +19  Appearance: Homepage content, Banners, Cart panel, Cart page,
                             Checkout page, Footer, Set, Site layout
                 Catalog:    Sets, Product tabs, Product editor, Build my routine,
                             Categories, Brands
                 Platform:   Cache
                 Store:      New Order, Coupons, Gateway webhooks, Security
```

Thirteen seconds between the first 56 rows and the last 19. `Appearance → Set`
is in that last batch, with Banners, Cart panel, Cart page, Checkout page,
Footer and Site layout — precisely the rows the owner's screenshot is missing.

**After**

```
  4,011 ms  +77 rows   every group, complete, in one sample
```

### The pictures

All four are single frames from a cold, cache-disabled load at 2 Mbit/s with the
CPU at one quarter, captured over CDP so the screenshot does not queue behind
the page's own main thread. Appearance is forced open with a stylesheet injected
before any page script, so the group is visible from the moment it is built.

| | |
|---|---|
| `docs/nv-shots/1-before-desktop-1280-9000ms.png` | **before**, 1280px, 9.0s in. Appearance: Homepage, Product styles, Mobile Header, Section dividers, Login / Register panel, Header, Mobile menu, Product page, Quantity bundles, Product grid. Ten rows. This is the owner's screenshot. |
| `docs/nv-shots/2-after-desktop-1280-4500ms.png` | **after**, 1280px, 4.5s in. Appearance: all eighteen, Homepage content and Banners under Homepage, Cart panel / Cart page / Checkout page / Footer under Section dividers, Set after Product page, Site layout last. |
| `docs/nv-shots/3-before-mobile-390-9000ms.png` | **before**, 390px, drawer open, 9.0s in. Ten rows. |
| `docs/nv-shots/4-after-mobile-390-4500ms.png` | **after**, 390px, drawer open, 4.5s in. Eighteen. |
| `docs/nv-shots/5-before-desktop-1280-loading-the-latest-orders.png` | **before**, 1280px, 5.0s in — "Loading the latest orders…" under Recent activity, and the four tiles reading AED — and three dashes. The second half of the report. |

### The fix

`LATE_NAV` — one declaration beside `NAV`, rendered by the server, registered
immediately after `buildNav()`. Each partial's own `kbbAddNavEntry` call is then
a no-op: the function is keyed on the screen id and returns the row that is
already there. **No partial changed.** What a partial still contributes is the
only thing it alone can: its renderer.

```
BEFORE  first rows 3,391–3,474 ms   complete 15,994 / 16,010 / 16,114 ms
AFTER   first rows 3,493–3,609 ms   complete 3,493 /  3,522 /  3,609 ms
```

Three runs each. The sidebar is complete **12.5 seconds earlier**, and there is
no intermediate state at all — it goes from nothing to all seventy-seven rows in
one step.

The settled sidebar was captured from the browser before and after: 13 groups,
77 rows, same order, same labels, same icon markup, **byte-identical**.

### The order of `LATE_NAV` is not alphabetical, deliberately

`kbbAddNavEntry` inserts a row directly after the first of its `after` anchors
that is already in the group, so the order rows arrive in decides where they
land. `LATE_NAV` is in the order the console registers them in today: the three
that register while the document is still parsing first, then the eighteen that
wait for `DOMContentLoaded`, each in include order.

`setap` is the one that proves it matters. It names `cartpanel` first, but it
registers **before** cart-panel does, so it has always landed after
`productpage` instead. Sorting `LATE_NAV` moves the Set row four places up the
Appearance menu. `AdminSidebarIsCompleteAtBuildTest` simulates the placement
rules and fails on exactly that.

---

## 2. "Long to long", and "Loading the latest orders…"

### The endpoints are not slow

Measured server-side against 2,419 products, with the query log on:

| endpoint | queries | time | bytes | repeated query shapes |
|---|---:|---:|---:|---|
| `/admin-api/stats` | 16 | 47 ms | 646 | none |
| `/admin-api/products` | 5 | **175 ms** | **514,565** | none |
| `/admin-api/brands` | 2 | 9 ms | 2,539 | none |
| `/admin-api/categories` | 2 | 11 ms | 2,447 | none |
| `/admin-api/reviews/list?per_page=1` | 3 | 5 ms | 215 | none |
| `/admin-api/translations/settings` | 2 | 5 ms | 4,293 | one, ×2 |

**No N+1 anywhere.** 30 queries in total for a cold load, and the only repeated
shape is a `settings` lookup issued twice inside
`/admin-api/translations/settings`. `AdminController::products()` was already
eager-loading `brand` and `category`, with the comment recording the 1,300-query
version it replaced.

`AdminColdLoadQueryBudgetTest` now pins both halves the way
`StorefrontQueryBudgetTest` does for the shop — a ceiling per endpoint, and
flatness: ten products against fifty, asserting the counts are identical. The
admin had no budget at all before this, which is how the 1,300-query version of
`products()` shipped in the first place. Mutation: drop the eager load and
flatness reports 25 queries on 10 products against 105 on 50.

**Nothing is fetched twice.** Six requests on a cold load, all distinct. Walking
nine screens afterwards (`orders → catalog → settings → dash → orders → dash →
catalog → media → seo`) produced one request per screen visit and no duplicates
beyond the revisits themselves.

### What was actually slow: the request was not being made

`renderDash()` paints "Loading the latest orders…" and `hydrateDash()` replaces
it from `/admin-api/stats`, which answers in 47 ms. The only boot call to
`hydrateDash()` sits at the foot of the **second** script block — byte
**1,337,459** of 3,438,661, which is 38.9%. The browser had to download and
parse 1.3 MB before the dashboard asked for its own numbers.

The request now starts at byte **189,561** (5.5%) and `hydrateDash()` awaits that
promise instead of issuing its own.

```
BEFORE  request starts +5,510 ms   tiles fill 5,929 / 6,059 / 6,085 ms
AFTER   request starts +3,302 ms   tiles fill 5,663 / 5,720 / 5,800 ms
```

Only ~300 ms, and the reason is worth stating plainly: **the remaining wait is
the document, not the network.** `hydrateDash()` is the code that writes the
numbers into the page, and it cannot run until 38.9% of a 3.4 MB document has
been parsed. Moving the *request* earlier removes the round trip; moving the
*painting* earlier would mean restructuring the document, which is below.

### A sidebar row clicked during the load used to strand the console

Found while proving the fix, and **older than this lane**. Clicking `Store →
Orders` 4.6 s into a throttled load left the console reading

> Orders could not be loaded — the admin script did not finish starting up.
> Reload the page.

and it stayed there, because reloading reproduces it exactly — the address is
not the cause. `mountFrame()` paints that card for every `LIVE_RENDERED` id
until the wrapper that really draws it has been parsed, and Lane DA's replay is
armed for the boot navigation only, never for a click.

`kbbNavClick` now arms from the same three sets a link would (`LATE_NAV`'s rows,
`LIVE_RENDERED`, `LATE_RENDERED`) and reads two signals — `renderDash`'s
`#kbbDashWrap` and `frameStartupHTML`'s `#kbbFrameStartup`. Both mean *nothing
claimed this screen*. A screen that drew itself leaves neither and is never
drawn twice, which is the double render Lane DA measured on `rev-all`.

Verified in Chromium at 1.5 Mbit/s, CPU at one quarter, clicking at
`readyState === 'loading'`:

| clicked | before | after | API calls |
|---|---|---|---|
| Store → Orders | stranded on the startup card | Orders table | `orders-list` ×1 |
| Appearance → Set | no row to click | Set screen | its two endpoints ×1 each |
| Content → Media Library | the silent dashboard under "Media Library" | Media Library | — |
| Reviews → All Reviews | recovered by its own `cur` boot | unchanged, drawn once | — |

`rev-all` is in neither armed set, exactly as `AdminNavAndIdsTest` requires, and
still draws itself. The guard returns before touching it.

---

## 3. The compiled Blade cache, and why a package can apply and change nothing

Blade decides whether to recompile in `Compiler::isExpired()`, which is one
comparison:

```php
filemtime($source) >= filemtime($compiled)
```

A package arrives as a zip and is applied by unzipping it. **A zip stores each
file's mtime and `unzip` restores it**, so the new `app.blade.php` lands carrying
the mtime it had on the machine that built the package — routinely *older* than
the compiled view already on the server. The comparison then says the compiled
copy is current, Blade never recompiles, and the owner applies a package, sees
the version number move, and gets the old console.

Demonstrated rather than assumed: a source stamped 10 Sep under a compiled file
stamped 20 Sep is not recompiled.

This is already known here — `2027_05_09_000000_clear_caches_set_screen`
records it as *"on this host the compiled views outlive the files they came
from"* — and the mitigation is the `clear_caches_*` migration every package
ships. This package ships
`2027_05_10_000000_clear_caches_admin_sidebar`, and `migrations` in
`update.json` is what decides whether it runs at all, so build with
`php artisan kbb:package <version> --since=<ref>` and nothing else.

The compiled cache is **not** a performance problem: the 3.4 MB is produced by
executing an already-compiled PHP file, and the response is uncacheable anyway
(it carries the CSRF token and is behind auth — `Cache-Control: no-store,
private`).

---

## 4. Found and not fixed

Ranked by what it costs the owner.

1. **The document is 3,438,661 bytes and 28.7% of it is JavaScript block
   comments** — 982,329 bytes of them, plus 34,076 bytes of line comments. Over
   a million bytes of prose shipped to the browser on every admin page load.
   Gzipped the whole document is **989,458 bytes, a 71% saving**, so the single
   largest available win is compression at the web server, and it costs nothing
   in this repository. **Check whether it is already on**:

   ```
   curl -H 'Accept-Encoding: gzip' -sI https://extrabeauty.ae/<admin-path> | grep -i content-encoding
   ```

   If that prints nothing, turning on gzip for `text/html` at the Cloudways
   application level is worth roughly 10 seconds on a 2 Mbit/s load. Stripping
   the comments from the *rendered output* would be worth a further 30%, but it
   needs a real JavaScript tokenizer — a regex over 20,000 lines of admin JS
   will corrupt a string containing `/*` or a URL containing `//` — and a
   tokenizer bug here is a console that does not open at all. Not attempted.

2. **`/admin-api/products` is 514 KB and 175 ms, fetched on every admin page
   load, for a screen that is usually not open.** `loadCatalog()` runs at 38.9%
   of the document, so those 514 KB are downloaded *in parallel with the
   remaining 61% of the console*, taking about a quarter of the pipe for 4.3
   seconds at 2 Mbit/s.

   It cannot simply be deferred, and the reason is worth knowing: **the Catalog
   screen falls back to a hard-coded mock array** (`const CAT_PRODUCTS=[...]`,
   real brand names, invented rows) until `loadCatalog()` replaces it.
   Deferring the fetch widens the window in which the owner can be shown
   invented products, which is worse than the delay. The honest fixes are to
   paginate the endpoint, or to make the Catalog screen start empty rather than
   fictional. Both are somebody's decision, not this lane's.

3. **The sidebar could appear at about 1 second instead of 3.5.** `buildNav()`
   is *called* at 21.4% of the document although everything it needs exists by
   about 6%; the 15% in between is screen renderers. Moving the call up is not
   a one-line change: `go()` closes over `FRAME_SRC`, `PLACEHOLDERS`,
   `LIVE_RENDERED` and `CAT_PRODUCTS`, which are `const` and therefore in the
   temporal dead zone until their declarations are reached — a row clicked in
   that window would throw `ReferenceError` instead of navigating. Worth doing,
   with the click guard extended to cover it; not worth doing in the same
   change as this one.

4. **Six lane worktrees were live at once** (`lane-bn2`, `lane-cr`, `lane-fb`,
   `lane-gs`, `lane-nav`, `lane-od`, `lane-pg2` — seven with this one), each
   carrying its own copied `vendor/`, and `df -h /` read **119 MB free, 100%
   used** mid-session. That is the exact condition behind the
   `ImportAtVolumeTest` "no such savepoint" flake in `CLAUDE.md`, and the
   cadence `CLAUDE.md` sets is three lanes. Not this lane's worktrees to
   remove.

---

## 4b. A note for the next lane that adds an admin screen

A screen that ships as its own partial still registers its own row exactly as
before — `kbbAddNavEntry({screen, label, icon, group, after})`, unchanged, and
still the one supported way. **Add the same row to `LATE_NAV` as well**, at the
position in the array where you want it to land. If you do not,
`AdminSidebarIsCompleteAtBuildTest` fails by name and tells you so: your row
would otherwise not exist until your partial has been parsed, which on this
document can be the last one per cent of it.

`docs/T1B-ADMIN-APP-BLOCKS.md` is untouched and still correct: the four
Translation rows are in `NAV`, not in `LATE_NAV`, and `LATE_RENDERED` — which is
about the *renderer*, not the row — is exactly as it was.

## 5. Where it sits in the admin

Nothing new to click. The change is to the console shell itself, so it shows up
as:

- every sidebar row present the moment the menu is drawn — most visibly
  **Appearance → Set**, **Appearance → Banners**, **Appearance → Site layout**,
  **Catalog → Sets**, **Catalog → Product tabs**, **Store → Gateway webhooks**
  and **Store → Security**, which were the last to arrive;
- a row clicked while the page is still arriving opening its own screen instead
  of the Dashboard or the "could not be loaded" card;
- **Overview → Dashboard** filling its four tiles sooner.

## 6. How the measurements were taken

Chromium 141 (`/opt/pw-browsers/chromium-1194`), driven by Playwright, one fresh
context per run, `Network.setCacheDisabled`, `Network.emulateNetworkConditions`
at 2 Mbit/s and 120 ms latency, `Emulation.setCPUThrottlingRate` at 4. The
sidebar is sampled from inside the page every 150 ms; screenshots are taken over
CDP so they do not queue behind the page's own main thread. Byte offsets are
`strpos` into the rendered document, not into the Blade source. Query counts come
from `DB::enableQueryLog()` around a real request through the HTTP kernel.
