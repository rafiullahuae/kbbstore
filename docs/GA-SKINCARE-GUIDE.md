# Lane GA — `/skincare-guide/`, established by fetching

**The short answer: the Phase 9 item is stale.** Every clause of it was true
once and none of it is true now. `/skincare-guide/{slug}/` is not what the
homepage builds and has not been since 2.60.109; `/blog` and `/post/{slug}`
are not what the router serves, they are two 301s; and the address the shop's
own pages publish, the address the sitemap submits, the address IndexNow pings
and the address in the canonical tag are all the same one. There is no
conflict left to resolve and no canonical to repoint.

What there is instead is three defects that only turn up if you fetch:
the two retired prefixes 301 to a near-miss of the canonical form and drop the
reader's language on the way (§5, fixable, fix attached), the Journal's own
page chrome links itself with root-absolute hrefs that leave the application
entirely under a base path (§7, not fixed, and §9 says why), and the whole
structure is currently serving an empty table with nothing in the repository
able to fill it (§6, which is what §10 puts to the owner).

Everything below was measured against a running server before anything was
written. Where a measurement contradicts `KBB-Master-Plan.md` it is said
plainly rather than quietly worked around.

---

## 1. The item, clause by clause

`KBB-Master-Plan.md` Phase 9, line 1289:

> `- [ ] ▲ /skincare-guide/ — a permalink structure, not one page: the homepage
> builds /skincare-guide/{slug}/, the router serves /blog and /post/{slug}`

| Clause | Verdict | What is actually there |
|---|---|---|
| "a permalink structure, not one page" | **wrong now** | `/skincare-guide/` is exactly one page: the Journal index. `PageController::blog()`, `routes/web.php:173`. Nothing is served beneath it. |
| "the homepage builds `/skincare-guide/{slug}/`" | **wrong now** | `resources/views/store/home.blade.php:569` builds `Url::to('/' . $post->slug . '/')`. So does every other producer in the tree — §4 is the full census, and it found no producer of the old shape anywhere, not one. |
| "the router serves `/blog` and `/post/{slug}`" | **wrong now** | Both are 301s (`routes/web.php:180-183`). `/blog` stopped serving the index when it moved to `/skincare-guide/` in 2.60.93; `/post/{slug}` stopped serving the article when articles moved to the site root in 2.60.109. |
| flagged ▲ (blocked / open) | **should be `[x]`** | The sibling item directly above it — `/brands/`, the same shape of URL conflict — was closed in 2.60.109 in the same release that closed this one. Only one of the two was ticked. |

`KBB-Master-Plan.md` line 2246 carries the same claim a second time, in the
risk register: "▲3 `/brands/` and `/skincare-guide/` still have no route".
Both have had a route since 2.60.93 and 2.60.109 respectively.

**This lane does not edit the plan** (CLAUDE.md). The integrator should tick
line 1289 and strike the `/skincare-guide/` half of ▲3.

---

## 2. Method

`php -S` in front of a copy of `public-web-root/index.php`, booted from a
directory whose parent holds `bootstrap/` and `vendor/`, with `vendor` copied
(hard-linked, not symlinked — a symlinked `vendor` resolves Composer's
`$baseDir` back to the main repo and silently runs the other tree's code),
`SESSION_DRIVER=file`, its own SQLite database migrated and seeded from
`DatabaseSeeder`, and the five articles the owner confirmed live inserted into
`posts` by hand, because nothing in this repository can put them there (§6).

Status code and `Location` are read from the response headers; the canonical is
read out of the returned HTML. Chromium was available and was not used: every
question in this lane is about a header or a `<head>` tag, not about layout, and
a rendering engine would have added nothing but a screenshot.

**One thing could not be measured.** `https://kbeautybliss.com/…` is refused by
this sandbox's egress proxy (`connect_rejected`, organization policy), so the
live WordPress addresses could not be re-verified first-hand. What is asserted
about them below comes from repository evidence, and §10 says exactly how strong
that evidence is.

---

## 3. What each address answers today — measured, before any change

Base `http://127.0.0.1:8731`, one published article at
`heartleaf-extract-transforming-k-beauty-skincare` (written `{slug}` below).

| URL | Status | `Location` | `<link rel=canonical>` |
|---|---|---|---|
| `/skincare-guide/` | **200** | — | `…/skincare-guide/` |
| `/skincare-guide` | 200 | — | `…/skincare-guide/` |
| `/{slug}/` | **200** | — | `…/{slug}/` |
| `/{slug}` | 200 | — | `…/{slug}/` |
| `/skincare-guide/{slug}/` | **301** | `…/{slug}/` | — |
| `/skincare-guide/{slug}` | 301 | `…/{slug}/` | — |
| `/skincare-guide/no-such-post/` | 404 | — | — |
| `/blog` | 301 | `…/skincare-guide` ← **no trailing slash** | — |
| `/blog/` | 301 | `…/skincare-guide` | — |
| `/blog/{slug}/` | 301 | `…/{slug}` ← **no trailing slash** | — |
| `/post/{slug}` | 301 | `…/{slug}` ← **no trailing slash** | — |
| `/post/` , `/post` | 301 | `…/skincare-guide` | — |
| `/no-such-post/` | 404 | — | — |

With Arabic switched on (`language_ar_enabled`):

| URL | Status | `Location` | Canonical |
|---|---|---|---|
| `/ar/skincare-guide/` | 200 | — | `…/ar/skincare-guide/` |
| `/ar/{slug}/` | 200 | — | `…/ar/{slug}/` |
| `/ar/skincare-guide/{slug}/` | 301 | `…/ar/{slug}/` ← keeps Arabic | — |
| `/ar/blog` | 301 | `…/skincare-guide` ← **English** | — |
| `/ar/post/{slug}` | 301 | `…/{slug}` ← **English** | — |
| `/ar/blog/{slug}/` | 301 | `…/{slug}` ← **English** | — |

**So: `/blog` and `/post/{slug}` do not disagree with `/skincare-guide/` about
canonical. They do not publish a canonical at all, because they do not serve a
page.** The question the item poses — which of the two addresses is the shop's
own — was answered in 2.60.109 and the code has agreed with itself ever since.

Also measured, and worth having on the record because it is the shape of the
site-wide convention these two redirects are the exception to: `/shop` answers
200 with canonical `/shop/`, `/product/{slug}` answers 200 with canonical
`/product/{slug}/`, and `/brands/` 301s to `/korean-skincare-brands/` **with**
the trailing slash. Slash-less forms are served, not redirected, and the
canonical does the consolidating. The two journal redirects are the only ones
that 301 somewhere the canonical says is not the address.

---

## 4. Every producer of the address — the full census

Asked for as "find every producer of it, not just the one the plan names". This
is all of them, by grep over `app`, `resources`, `routes`, `database`, `tests`
and the admin console, cross-checked against the rendered output of the running
server.

### Producers of the Journal INDEX, `/skincare-guide/` — 13, all correct

| Where | What it builds |
|---|---|
| `resources/views/store/home.blade.php:409` | "the routine" section link |
| `resources/views/store/home.blade.php:566` | Journal rail heading link |
| `resources/views/store/post.blade.php:168` | article breadcrumb |
| `resources/views/store/post.blade.php:209` | article "back to the Journal" |
| `app/Http/Controllers/Store/PageController.php:336,339` | index `Seo::render` url + breadcrumb |
| `app/Http/Controllers/Store/PageController.php:425` | article breadcrumb (JSON-LD) |
| `app/Http/Controllers/Store/SeoFilesController.php:147` | `sitemap.xml` |
| `app/Http/Controllers/Store/SeoFilesController.php:571,608` | `llms.txt`, English and Arabic |
| `app/Http/Controllers/Admin/MegaMenuApiController.php:290` | mega-menu seed |
| `app/Http/Controllers/Admin/PagesApiController.php:38` | admin Pages registry |
| `app/Http/Controllers/Admin/HealthApiController.php:74` | health check |
| `app/Services/MenuDemo.php:320` | demo nav |
| `database/migrations/2026_09_09_040000`, `…_070000` | `menu_items` rows |

### Producers of the ARTICLE address — 9, all at the site root

| Where | What it builds |
|---|---|
| `resources/views/store/home.blade.php:569` | homepage Journal rail card |
| `resources/views/store/blog.blade.php:174` | index card |
| `resources/views/store/post.blade.php:221` | "more from the Journal" card |
| `app/Http/Controllers/Store/PageController.php:391` | article canonical |
| `app/Http/Controllers/Store/PageController.php:426` | article breadcrumb leaf |
| `app/Http/Controllers/Store/SeoFilesController.php:359` | `sitemap.xml` |
| `app/Providers/AppServiceProvider.php:231` | IndexNow submit on save |
| `app/Providers/AppServiceProvider.php:359` | auto-created redirect on a slug change |
| `resources/views/admin/app.blade.php:11125` | admin Blog Posts "Preview" button |

### Producers of `/skincare-guide/{slug}/` — none

Eight files mention the string. Every one of them is a comment recording that
the form is retired, plus the one route that 301s it
(`routes/kbb-brands-blog.php:108`). Nothing constructs it. Confirmed by grep
and confirmed again by fetching `/` and `/skincare-guide/` and reading every
`href` in the returned HTML.

No mail template links an article at all — `resources/views/mail` has no
reference to `posts` or to a slug — so there is no emailed copy of the old
address in circulation from this application.

---

## 5. The two defects the fetching turned up, and the fix

`routes/web.php:180-183` builds both Locations with `redirect()->route(...)`.
Laravel trims the trailing slash off a registered URI, and `route()` knows
nothing about the `/ar` segment. So:

1. **The Location is not the canonical form.** `/blog` → `/skincare-guide`,
   `/post/{slug}` → `/{slug}`. The slash-less form answers 200 and
   self-canonicalises to the slashed one, so this costs a crawler one extra hop
   and costs a shopper nothing. It is the smaller half. It is also exactly the
   defect the sitemap was corrected for twice (`SeoFilesController`, the `/shop`
   and `/brands/` entries): do not hand a search engine a URL it is then sent
   away from.
2. **The reader's language is dropped.** `/ar/blog` and `/ar/post/{slug}` land
   on the English page. This is the half a person notices, and it is not
   theoretical — `/ar/skincare-guide/{slug}/`, the sibling redirect ten lines
   away, keeps the reader in Arabic, because `PageController::legacyPost` builds
   its Location with `Url::redirect()` instead.

`App\Support\Url::redirect()` is the house answer to both halves and is already
what `/checkout/pending`, `BrandController::legacyIndex` and
`PageController::legacyPost` use.

### The edit — anchor and replacement

`routes/web.php` is on this lane's do-not-edit list, so this is written out
rather than made. **The anchor occurs exactly once in the file** (verified by
count at `201d913`; `Route::get('/blog'` and `Route::get('/post/{slug?}'` each
occur once as well).

ANCHOR — six lines, `routes/web.php:178-183`:

```php
// The Laravel-era addresses, kept as 301s so existing links and anything
// already indexed survive.
Route::get('/blog', fn () => redirect()->route('blog', [], 301));
Route::get('/post/{slug?}', fn (string $slug = '') => $slug === ''
    ? redirect()->route('blog', [], 301)
    : redirect()->route('post', ['slug' => $slug], 301));
```

REPLACEMENT — five lines, in place:

```php
// The Laravel-era addresses, kept as 301s so existing links and anything
// already indexed survive. Lane GA: the Location they emit has to be the
// canonical form (trailing slash, current language), which is what
// Url::redirect() returns and what route() cannot.
require __DIR__ . '/kbb-journal-legacy.php';
```

**In place**, which is above the `require` of `routes/kbb-brands-blog.php` at
the end of the file. That file ends in the single-segment root catch-all;
`blog` and `post` are both in `PageController::RESERVED_SLUGS` so the catch-all
cannot swallow them, but the reservation is the second guard and registration
order is the first, and both are meant to hold.

Ships with:

* `routes/kbb-journal-legacy.php` — the two corrected registrations, with the
  same anchor and replacement repeated in its header so the file explains
  itself to whoever opens it next.
* `database/migrations/2026_11_21_000000_clear_caches_journal_legacy_redirects.php`
  — the host serves a compiled route table and has no shell (CLAUDE.md).

### Measured after applying it, same server, same data

| URL | Before | After |
|---|---|---|
| `/blog` | 301 → `…/skincare-guide` | 301 → `…/skincare-guide/` |
| `/blog/` | 301 → `…/skincare-guide` | 301 → `…/skincare-guide/` |
| `/post/{slug}` | 301 → `…/{slug}` | 301 → `…/{slug}/` |
| `/post/{slug}/` | 301 → `…/{slug}` | 301 → `…/{slug}/` |
| `/post`, `/post/` | 301 → `…/skincare-guide` | 301 → `…/skincare-guide/` |
| `/post/no-such-post` | 301 → `…/no-such-post` → 404 | **404** |
| `/ar/blog` | 301 → `…/skincare-guide` | 301 → `…/ar/skincare-guide/` |
| `/ar/post/{slug}` | 301 → `…/{slug}` | 301 → `…/ar/{slug}/` |
| `/skincare-guide/{slug}/` | 301 → `…/{slug}/` | unchanged |
| `/ar/skincare-guide/{slug}/` | 301 → `…/ar/{slug}/` | unchanged |
| `/skincare-guide/` | 200, self-canonical | unchanged |
| `/{slug}/` | 200, self-canonical | unchanged |

And with `KBB_BASE_PATH=/kbb-upgrade` and an `APP_URL` that already carries it —
the configuration that produced `/kbb-upgrade/kbb-upgrade/cart` once before, and
the reason `Url::redirect()` exists:

| URL | `Location` |
|---|---|
| `/blog` | `http://127.0.0.1:8731/kbb-upgrade/skincare-guide/` |
| `/post/{slug}` | `http://127.0.0.1:8731/kbb-upgrade/{slug}/` |
| `/skincare-guide/{slug}/` | `http://127.0.0.1:8731/kbb-upgrade/{slug}/` |

The base path appears exactly once in each.

One behaviour change, stated plainly rather than buried: `/post/unknown-slug`
was a 301 to `/unknown-slug` and then a 404; it is now a 404 at
`/post/unknown-slug`. Both end at 404, and neither path matches a redirect row
(auto-created rows are stored as `/old/` → `/new/`, with slashes, and neither
404 is spelled that way), so nothing is lost and the site stops advertising a
301 to a page that does not exist.

### The third redirect, which this lane did not fix

`/blog/{slug}/` — the five rows
`2026_09_14_160000_seed_phase9_post_url_redirects` seeds — still 301s to
`/{slug}` without the slash, and still drops `/ar`. The rows are right; they
store `/{slug}/`. The loss happens where the row is dispatched:

* `app/Http/Middleware/CheckRedirects.php:37`
* `app/Providers/AppServiceProvider.php:156` (the copy that actually runs, on
  the 404 handler)

both call `redirect($redirect->target, $redirect->code)`, and plain `redirect()`
on a relative path goes through `UrlGenerator::to()`, which trims the slash.

**Requirement written down rather than made.** `Url::redirect($target)` would
fix both halves for every redirect row in the table at once, which is precisely
why this lane is not the one to make the change: it is shared dispatch for
product-slug redirects, importer-written rows and anything an admin types into
the Redirects screen, and `Url::redirect()` re-localises, so a row pointing at
`/wp-content/uploads/…` would start being served `/ar/wp-content/uploads/…` to
an Arabic reader, which is a 404. Whoever owns that file should take it with
the exemption for non-localisable paths that `Locale::localisable()` already
knows how to express. CLAUDE.md: say so rather than edit it.

---

## 6. What the `posts` table actually holds — and this is the real gap

**Nothing, and there is no supported way to put anything in it.**

* `database/seeders/` has no post seeder. `DatabaseSeeder` calls settings,
  modules, shipping, tax, payment providers, the demo catalogue and demo
  reviews. Not posts.
* `app/Services/Import/Entities/` has importers for brands, categories,
  products, coupons, customers, orders, order items, reviews and SEO.
  **There is no post importer.** `wp_posts` is named once in the whole
  importer, in a comment in `Row.php` about column naming.
* The admin's Blog Posts screen is read-only by its own admission
  (`resources/views/admin/app.blade.php:11100` — "Read-only for now … a full
  editor is separate, larger scope"). It lists and it links Preview. It cannot
  create.
* `KBB-Master-Plan.md` already records Blog and Posts among the six screens
  with no backend. That part of the plan is right.

So on a freshly migrated shop — which is what the live deployment is — the
permalink structure this item is about is complete, correct, and empty:

* `/skincare-guide/` renders the index with no cards,
* every `/{slug}/` is a 404,
* the homepage Journal rail hides itself (`@if ($posts->isNotEmpty())`) unless
  demo content is switched on, in which case it draws three stand-in cards from
  `DemoContent::posts()` **linking to `/{slug}/` addresses that 404**, because
  the stand-ins are plain objects and not rows,
* and `sitemap.xml` submits `/skincare-guide/` unconditionally
  (`SeoFilesController:147`, no emptiness check) — which is the same crawl
  budget spent on an empty state that the `/reviews/` entry four lines above it
  was corrected for in 2.60.192.

The five slugs in `2026_09_14_160000_seed_phase9_post_url_redirects` are seeded
as redirect *sources*. Nothing creates the articles they point at.

This lane did not add a post importer, a seeder or an editor: each is a lane's
worth of work and none of them is a permalink. It is written down here because
it is the thing that actually stands between this structure and a working
Journal, and because an item that reads "`/skincare-guide/` — open" invites
somebody to go and repoint a URL that is already right.

---

## 7. A third defect, found on the way, not fixed

`resources/views/store/blog.blade.php` and `resources/views/store/post.blade.php`
do not use `layouts/store.blade.php` — they are two of the standalone
storefront templates with chrome of their own (`Seo.php:535` lists them). That
chrome links the Journal with a **bare root-absolute href**, five times:

| File | Line | Link |
|---|---|---|
| `store/post.blade.php` | 152 | `<a href="/blog" class="on">` header nav |
| `store/post.blade.php` | 163 | `<a href="/blog">` breadcrumb strip |
| `store/post.blade.php` | 245 | `<a href="/blog">` footer |
| `store/blog.blade.php` | 148 | `<a href="/blog" class="on">` header nav |
| `store/blog.blade.php` | 159 | `<a href="/blog">` breadcrumb strip |

Two things are wrong with each, and the second is the serious one:

1. It is an internal link to a 301. Every article page tells a crawler the
   Journal lives at `/blog`; the Journal answers "no it does not" three times
   per page.
2. **It does not go through `Url::to()`, so it carries neither the base path
   nor the language.** On the live host, mounted at `/kbb-upgrade`, `href="/blog"`
   resolves to `easywebsol.com/blog` — outside the application altogether. The
   "Journal" link in the header of every article is a dead link there. This is
   the same failure the plan's ▲3 records as "the desktop nav drops the base
   path", still present in these two templates.

Measured, not inferred. The same preview booted with
`KBB_BASE_PATH=/kbb-upgrade`, counting `href`s in the returned HTML:

| Page | `href="/blog"` | correctly prefixed Journal link |
|---|---|---|
| `/kbb-upgrade/{slug}/` (an article) | **3** | 2 × `href="/kbb-upgrade/skincare-guide/"` |
| `/kbb-upgrade/skincare-guide/` (the index) | **2** | 1 × `…/kbb-upgrade/skincare-guide/` |

The prefixed links on the same pages are the ones that go through `Url::to()`.
The five that do not are the five above, and on the live mount each of them
points outside the application.

The same lines do it for `/shop`, `/skin-quiz` and `/` as well, which belong to
whoever owns that chrome rather than to this lane.

The fix is mechanical — `href="{{ \App\Support\Url::to('/skincare-guide/') }}"` —
and this lane did not make it. §9.

---

## 8. Mutation testing — thirteen mutations, eleven red, two survivors reported

Every guard added by this lane was broken on purpose, the suite watched go red,
and the break restored. The harness re-synced the tree from the worktree
between each one, so no mutation could leak into the next.

| # | Mutation | Result |
|---|---|---|
| 1 | `legacyPost` drops the trailing slash from its Location | **RED** 4 failed |
| 2 | `legacyPost` builds the Location with `route()` instead of `Url::redirect()` | **RED** 4 failed |
| 3 | `legacyPost` stops checking the article exists | **RED** 2 failed |
| 4 | the Journal index links articles under `/skincare-guide/` | **RED** 1 failed |
| 5 | the homepage rail links articles under `/skincare-guide/` | **RED** 1 failed |
| 6 | the sitemap submits articles under `/skincare-guide/` | **RED** 1 failed |
| 7 | the Journal index canonicalises to `/blog/` | **RED** 1 failed |
| 8 | an article canonicalises under the index | **RED** 1 failed |
| 9 | `routes/kbb-journal-legacy.php` goes back to `route()` | **RED** 2 failed |
| 10 | the wiring helper stops dropping web.php's `post/{slug?}` | **GREEN — survived** |
| 11 | the wiring helper stops dropping web.php's `blog` | **GREEN — survived** |
| 12 | `web.php` sends `/blog` somewhere that is not the Journal | **RED** 1 failed |
| 13 | `web.php` sends `/post/{slug}` to the index instead of the article | **RED** 1 failed |

### The two that survived, and what they mean

Mutations 10 and 11 remove entries from `JournalLegacyRoutes::SUPERSEDED` — the
list that makes the test suite see `routes/web.php` as it will look *after* §5's
edit. Removing either leaves every test passing. That is reported rather than
tidied away, and the reason is worth having written down because it corrects a
claim this repository makes in two places.

`Tests\Support\Phase9Routes`, the precedent this helper is modelled on, states
that "Laravel matches the first route registered for a URI". For two routes
whose URIs *differ*, that is the effect. For two registrations of the **same**
method and URI it is not: `RouteCollection::addToCollections()` keys both of its
lookups on `method . domain . uri`, so a second registration of `post/{slug?}`
replaces the first and the **last** one wins. Since this lane's replacement is
spelled exactly like the line it replaces, requiring the new file is enough on
its own and the drop list changes nothing.

Directly evidenced, not reasoned from the source: with `post/{slug?}` left in
place, `/post/{slug}` still 301'd to the slashed form — which only the new
registration emits.

The list stays anyway, for two reasons that are not "it is a guard", because it
is not one and is no longer described as one: it is what `web.php` actually
looks like after the edit, which is the state these tests claim to describe;
and it is the only thing keeping them honest if the replacement is ever spelled
differently from the line it replaces, at which point last-wins stops applying
and registration order is all there is.

It also means the integrator has a cheaper option than §5 if they want one —
requiring `kbb-journal-legacy.php` *after* the two old lines, leaving them in
place, would work today. §5's replacement is still the right edit: two dead
registrations that are silently overridden by a file further down is exactly
the kind of thing that survives until someone reorders a require.

### Vacuous assertions

`expect(...)->not->toContain($needle, $message)` passes whatever the page says,
because `toContain` is variadic and reads the message as a second needle. No
assertion in this lane uses that shape. The census assertions return the
offending strings through `array_unique`/`array_values` and compare with
`toBe([])`, so a failure names them; the one negative string check uses
`str_contains(...)` and `toBeFalse()`. `tests/Feature/PostUrlTest.php`, which
this lane read closely, uses `->not->toContain($needle)` with a single argument
throughout, which is the sound form.

---

## 9. What was deliberately not done

* **`routes/web.php`, `KBB-Master-Plan.md`, `KBB-Progress-Dashboard.html`,
  `bootstrap/app.php`, `resources/views/admin/app.blade.php`** — untouched, as
  required. The one change needed in `web.php` is §5's anchor and replacement.
* **The five hard-coded `/blog` links in §7.** Both of those templates are
  byte-pinned by `StorefrontEnglishUnchangedTest` — `skincare-guide` and
  `{slug}` are both `render => true` in `EnglishRenderWalk::expectations()` —
  and an `href` change is a byte change, so fixing them **forces `BASE_COMMIT`
  to be moved**. The brief for this lane says to prefer an inline comment over
  repinning, and there is no inline comment that changes an `href`. So the
  finding is written up with the exact replacement instead, to be taken by
  whoever is already moving that pin, or as a deliberate step of its own. It is
  not urgent in the way it looks: the links are wrong on staging and on the
  live mount, and correct at a domain root.
* **`CheckRedirects` / the 404 handler's `redirect($target)`** — §5's last
  subsection. Shared dispatch, a fix there changes every redirect row in the
  shop, and it can regress non-localisable targets. Written down as a
  requirement.
* **`app/Services/Import/**`** — not touched, not read for changes. Another
  lane owns it this round. §6's finding (there is no post importer) is an
  observation about what is absent, and the requirement it implies is stated
  here rather than built there.
* **The sitemap's unconditional `/skincare-guide/` entry** (§6). One line, and
  it would go red in `SeoBilingualTest`, `MachineFacingClaimsTest` and
  `StorefrontRouteWalkTest`, none of which seed a post. Changing three other
  lanes' expectations to gate a sitemap entry is not worth it while the deeper
  question — whether the Journal is meant to have content at all yet — is
  unanswered. §10.
* **A post importer, a post seeder or an admin editor.** Each is its own lane.
* **Repinning `BASE_COMMIT`.** Not done, not needed: nothing in this lane
  changes a rendered storefront byte.

---

## 10. The question for the owner

Only one thing in this lane could not be settled from the repository, and it is
not the canonical — that is settled, twice over:

* `2026_09_09_040000_seed_kbeautybliss_menu` states it was "pulled directly
  from the live site", and it links Blog to `/skincare-guide/`. That same seed
  is a faithful transcript of live WordPress URLs in a way that is independently
  provable: it also carried fourteen flat category addresses (`/toners/`,
  `/sunscreens/`, …) that this application has never served, which is why
  `2026_11_07_000000_repoint_menu_category_urls` had to exist. A seed that
  transcribed the live site accurately enough to import its mistakes is good
  evidence for the one entry in it that is not a mistake.
* 2.60.109 records the owner confirming articles live at the site root, with
  five slugs named.

So the question is not "which address is the shop's own". It is:

> **How do the live site's articles get into this application — and are there
> more than the five whose slugs we hold?**
>
> The Journal's permalink structure is finished and serving an empty table.
> There is no post importer, no post seeder and no way to write a post in the
> admin (§6). Three answers are possible and they are not equivalent:
>
> 1. **A post importer is wanted** — then it needs the full list of live
>    article slugs, because articles now live at the site root and
>    `PageController::RESERVED_SLUGS` refuses a first segment the storefront
>    already owns. A live article slugged `about`, `wishlist`, `contact-us` or
>    `feed` is an indexed URL that this application will never serve, and
>    nobody will notice until Search Console does. **That list is the thing
>    only the owner can supply, and it should be supplied before the importer
>    is written, not after.**
> 2. **The articles are to be re-typed** — then the admin's Blog Posts screen
>    needs an editor, and it is worth knowing that before somebody builds the
>    importer.
> 3. **The Journal ships empty for now** — then `/skincare-guide/` should come
>    out of `sitemap.xml` until it has content, the same way `/reviews/` already
>    does when the review wall is empty, and the homepage's demo Journal rail
>    should stop linking three article addresses that 404.

Whichever it is, it does not block this item. The permalink structure is
correct today and §5's fix makes the last two redirects agree with it.
