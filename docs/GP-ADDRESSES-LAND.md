# The addresses group lands · and every old URL, checked by fetching

Lane GP. Two things, and the brief was right that they are the same thing.

The owner's sentence for the whole migration:

> *"i want everything super same, nothing should be disturbed, and will have
> all new urls as per our app."*

**The headline, measured against a running server and not counted out of a
table: 54 of the old site's 54 published addresses resolve after the import,
up from 39.** The fifteen that did not were every category archive on the shop.
The eight remaining rows in `permalinks.csv` carry no address at all, which is
the exporter answering a question rather than failing to: the `pa_brands`
taxonomy never had a public archive, so there is no old address to land.

And a second number that is not in the brief and turned out to matter as much:
**before this package, 15 of those 15 redirects landed on a non-canonical
address** — a 301 to a URL whose own `<link rel="canonical">` pointed somewhere
else. That is now 0 of 15. §5.

---

## 1. The headline

| | |
|---|---|
| new routes | **none** — §6 |
| new capability rules | **none**; `admin-api/import/**` and `admin-api/urls-media/**` are both `data.import` already, checked through `AdminCapabilities::forPath()` rather than by reading the table |
| new migrations | **none**, because no route was added |
| new tables, new columns | **none** |
| `ImportDriver` / the drive loop | **untouched** — Lane GO has it this round, and §2 is why nothing here needed to go near it |
| old URLs resolving, before | **39 of 54** (72.2%) |
| old URLs resolving, after | **54 of 54** (100%) |
| 301s landing on the canonical spelling, before | **0 of 15** |
| 301s landing on the canonical spelling, after | **15 of 15** |
| the `ask` bucket the owner works through by hand | **14 rows → 2**, and §4 is the defect that found |
| mutations | **17 written; 2 survived the first pass, both reported, both now red** — §7 |
| the whole suite | **4,709 green on SQLite, 4,715 green on MySQL**, `php -l` clean across `app database routes` |

---

## 2. Where the two files go, and why not somewhere else

`ImportWorkspace::COMPANIONS` — a second table beside `ENTITIES`, holding
`permalinks.csv` and `media.csv`. They are filed into the export directory
under their own literal names and read by the machinery that already reads
them.

### 2.1 What was actually wrong, which was two things and not one

Lane GM found the first and wrote it up (`docs/GM-IMPORT-ACCEPTS-ZIP.md`
§10.2): neither file is in `ImportWorkspace::ENTITIES`, so the *Addresses and
pictures* group unpacked and both members were refused one at a time with
*"Which export is this?"*. Nothing had regressed and a test pinned it. The owner
downloaded a group that told him twice it had not imported.

**The second one is worse and nobody had it.** `RedirectMap::fromPermalinks()`
has read this exact file shape since the day it was written, and
**nothing with a screen had ever handed it a file.** Its only caller was
`kbb:import-redirects --permalinks=…`, a command the owner cannot run because
there is no shell on this host — which is the same gap
`UrlsMediaApiController` was built to close, left open one level down inside
it. `status()`, `map()` and `redirects()` all called `propose()` with no
arguments.

So fixing only the refusal would have produced the quieter failure: the file
lands, the screen says "Uploaded", and the map is exactly what it was. That is
the shape this repository has paid for before, and it is why §7's M4 and M5
exist.

### 2.2 Why they are not entities

Three separate reasons, any one of which is enough:

* **They have no importer.** `ENTITIES` and `ImportRunner::entities()` are two
  hand-maintained lists that must agree, and the note on `seo` in
  `ImportWorkspace` records what it cost the last time they did not — every
  upload 500'd on an undefined key. Adding a name to one list with nothing
  behind it on the other is that drift, on purpose.
* **They are not rows this shop stores.** `permalinks.csv` is a statement about
  the *old* site's addresses and `media.csv` about the old site's uploads
  folder. Nothing in this schema holds either. An entity writes rows; these two
  answer questions.
* **The drive loop would have to step them.** `ImportDriver` walks the entity
  list one entity per HTTP request because 4,159 orders cannot be written in
  one request on a host whose `max_execution_time` cannot be discovered from
  inside PHP. Two entities that import nothing would be two steps that do
  nothing, in the loop the whole import is built around — and Lane GO is
  changing that loop this round. **Nothing in this package touches
  `ImportDriver`.**

### 2.3 Why they are in the export directory and not a folder of their own

Because `ImportRunner::reportUnreadFiles()` **already exempted
`permalinks.csv` there**, with a comment saying it is "a file read by the other
half of Phase 13". The directory was already the intended home; the file simply
had no way of getting into it.

`media.csv` needed the same exemption and did not have it, because it had never
been able to land. Without it, every preview run would have named the owner's
own picture index in the discard list he is asked to approve — the trap that
moved the row-count sidecars out of that folder in the first place. That
one-line addition to `ImportRunner` is the only change this lane makes outside
its own files, and §7's M13 is the mutation.

### 2.4 The rules the acceptance follows

| rule | why |
|---|---|
| **the dropdown wins** | "This file is the categories export" is a statement about this file and it wins for a companion exactly as it does for an entity |
| **an entity filename wins** | `companionFor()` asks `entityFromFilename()` first, so this change can only ever turn a refusal into an acceptance and can never turn one entity into another. The reachable collision is `products-permalinks.csv`, which both tables answer to — §7's M1 is the mutation that survived until that input was written |
| **the name says what, never the header** | `media.csv` and `products.csv` both carry a `url`-ish column; sniffing between them is the guess `resolveEntity()` refuses to make for the four ambiguous entities |
| **the same CSV gate** | `refuseNonText()` and `firstRow()` run before the companion branch, so a companion goes through the same "is this really a readable CSV" check, with the same sentences, as everything else |
| **a column guard, and it is not optional** | §2.5 |
| **removable, because it is uploadable** | a `permalinks.csv` from the wrong export silently changes every redirect this shop proposes, and "delete it off the server" is not an instruction anyone with no shell can follow |

### 2.5 The column guard is the important half

Neither companion is imported, so neither produces a report anybody reads. A
`permalinks.csv` with no `wc_id` column does not fail — it makes
`fromPermalinks()` skip every row and propose nothing, which looks **exactly**
like not having uploaded it. That is the broken-filter shape CLAUDE.md names,
where `Api\ProductController`'s `status` filter matched no rows for months and
hid a 500 behind it.

So a companion must carry at least one alias from each required group, and the
refusal says what it would have cost:

> This does not look like the Old addresses export: it has no "wc_id" or "id"
> column. Without it this file would be read by the redirect map on Store →
> Import → Addresses & pictures and produce nothing at all, which looks exactly
> like not having uploaded it. Columns found: old_url, new_url

A header with **no data rows** is still accepted, for the reason `accept()`
already gives about a delta export: refusing it would make the owner delete a
file to prove there is nothing in it.

### 2.6 What `media.csv` is read FOR, since most of it is a second opinion

`MediaAudit` already answers "is this picture on this shop's disk" and
`MediaSideloader` already fetches the ones that are not, and **both derive their
work from the catalogue's own image columns, not from any file.** A second
opinion is not a reason to read a file, and this lane nearly left `media.csv`
as bytes on a disk.

One column is not a second opinion. `docs/GE-WP-EXPORTER.md` §6:

> `exists` is a `stat()` per row, so a file the media library names and the disk
> does not have is found **now**, while the old site is still up.

That is a fact about a machine this shop cannot reach. It matters because of
what the migration does next: the owner copies `wp-content/uploads` across by
FTP, or leaves the sideloader fetching. **A picture that was already gone on
WordPress will never arrive by either route.** Without this column he watches
`remote` refuse to reach zero with no way to tell "not copied across yet" from
"there is nothing to copy" — which is the difference between waiting and
re-photographing a product.

`App\Services\Import\MediaIndex` is that reader. Three properties:

* **Three answers, not two.** `null` means "this file says nothing about that
  picture", which is not "that picture was gone". Folding them together reports
  every photograph added since the export as missing at source. M10.
* **An unrecognised `exists` value reads as PRESENT.** The only thing this index
  is used for is telling the owner a picture will never arrive; saying that
  wrongly sends him to re-photograph something he already has. M11.
* **The key cuts at the uploads root.** One photograph is spelled three ways as
  the migration proceeds — the old absolute URL in the export, the same string
  in `products.image` after the import, and `/kbb-upgrade/wp-content/uploads/…`
  after `MediaRewrite::apply()`. The uploads root is the one part that does not
  move, which is the rule `MediaRewrite::uploadsRelative()` follows for the same
  reason. M12.

**It never writes.** `MediaRewrite` and `MediaSideloader` change rows and
neither consults this index, so a file the owner uploads cannot cause a picture
to be re-pointed or deleted.

---

## 3. Every old URL, checked by fetching

### 3.1 Method

A `php -S` preview, web root `gp-web-root`, whose **parent** holds `bootstrap/`
and `vendor/` (hard-linked, never symlinked — a symlinked `vendor` resolves
Composer's `$baseDir` back to the main repo and silently runs the other tree's
code), `SESSION_DRIVER=file`, its own SQLite database migrated and seeded from
`DatabaseSeeder`. §9 reproduces it.

**Nothing in the fixture is invented.** The old site standing up in that
database is the one this repository records:

* the fifteen category addresses are `LegacyCategoryUrls::PATHS`, copied off the
  live navigation by an earlier lane and corroborated by the plugin
  (`docs/GE-WP-EXPORTER.md` §5: `woocommerce_permalinks['category_base']` is the
  empty string, so `get_term_link()` returns the slug at the root);
* the five article slugs are `2026_09_14_160000_seed_phase9_post_url_redirects`,
  which records the owner confirming them;
* the product address shape is the one the owner pasted
  (`docs/GB-MEDIA-AND-REDIRECTS.md` §9);
* the brand rows carry an empty `permalink` and the plugin's own note, which is
  GE §5's answer and not a gap.

`permalinks.csv` was then generated in the exporter's own column order
(`type, wc_id, slug, permalink, status, source, note`) from that database, and
**uploaded through `ImportWorkspace::acceptUpload()` — the one door
`ImportApiController::upload()` calls and nothing else.** The browser run in §8
does the same upload through the actual screen, with a group zip.

Status codes and `Location` headers come from `curl`. "Resolves" means the final
status after following redirects is 200.

### 3.2 The count

| | rows | resolve before | resolve after |
|---|---:|---:|---:|
| products | 26 | 26 | 26 |
| journal articles | 5 | 5 | 5 |
| pages | 8 | 8 | 8 |
| **category archives** | **15** | **0** | **15** |
| brand archives | 8 | — no address was ever served — | |
| **total with an address** | **54** | **39 (72.2%)** | **54 (100%)** |

### 3.3 The fifteen, in all three states

The middle column is with the map's rows written and `CheckRedirects` as it
shipped; the right column is with §5's change applied.

| old address | before the import | rows written | rows written + §5 |
|---|---|---|---|
| `/skincare/` | 404 | 301 → `/product-category/skincare` | 301 → `/product-category/skincare/` |
| `/face-cleansers/` | 404 | 301 → `/product-category/skincare/face-cleansers` | 301 → `/product-category/skincare/face-cleansers/` |
| `/cleansing-oils/` | 404 | 301 → `/product-category/skincare/face-cleansers/cleansing-oils` | 301 → `/product-category/skincare/face-cleansers/cleansing-oils/` |
| `/face-washes/` | 404 | 301 → `/product-category/skincare/face-cleansers/face-washes` | 301 → `/product-category/skincare/face-cleansers/face-washes/` |
| `/exfoliators/` | 404 | 301 → `/product-category/skincare/exfoliators` | 301 → `/product-category/skincare/exfoliators/` |
| `/toners/` | 404 | 301 → `/product-category/skincare/toners` | 301 → `/product-category/skincare/toners/` |
| `/face-serums/` | 404 | 301 → `/product-category/skincare/face-serums` | 301 → `/product-category/skincare/face-serums/` |
| `/eye-care/` | 404 | 301 → `/product-category/skincare/eye-care` | 301 → `/product-category/skincare/eye-care/` |
| `/face-masks/` | 404 | 301 → `/product-category/skincare/face-masks` | 301 → `/product-category/skincare/face-masks/` |
| `/moisturizers/` | 404 | 301 → `/product-category/skincare/moisturizers` | 301 → `/product-category/skincare/moisturizers/` |
| `/lip-care/` | 404 | 301 → `/product-category/skincare/lip-care` | 301 → `/product-category/skincare/lip-care/` |
| `/sunscreens/` | 404 | 301 → `/product-category/skincare/sunscreens` | 301 → `/product-category/skincare/sunscreens/` |
| `/hair-care/` | 404 | 301 → `/product-category/hair-care` | 301 → `/product-category/hair-care/` |
| `/skincare-sets/` | 404 | 301 → `/product-category/skincare-sets` | 301 → `/product-category/skincare-sets/` |
| `/beauty-devices/` | 404 | 301 → `/product-category/beauty-devices` | 301 → `/product-category/beauty-devices/` |

Every one of the fifteen is a `legacy-root-category` row **and** a `permalink`
row proposing the identical redirect to the identical destination; the map
collapses the pair and says so on the discarded one. So the file did not change
this particular number — and that is the most useful thing it did, because
**before it, that rule was an inference.** `RedirectMap`'s own comment kept
"corroborated" and "inferred" apart on the row and counted them separately, for
exactly this reason. `permalinks.csv` is the old site saying it directly, and
the agreement between the two is the check.

### 3.4 What the file changes that derivation could not

Derivation can only propose addresses built out of rows this shop already
carries. An address under a slug the new shop does not have is unreachable to
it, and that is the common case in a real migration: a category renamed, an
article re-slugged, a page merged into another. Asserted as a test rather than
claimed — `it feeds permalinks.csv to the redirect map, which had never been
handed one` writes a row for `/old-toner-aisle/`, an address no rule in the map
can derive, and shows it absent before the file and present after it.

---

## 4. The defect the file exposed the moment it landed

With `permalinks.csv` in place the `ask` bucket — the list Phase 13 has the
owner approve by hand — came back with **fourteen rows, thirteen of which were
noise, and the reason on every one of them was false**:

```
ASK — 14
   nothing in this shop carries post id 501 — either it was never imported, or …
   nothing in this shop carries post id 502 — …
   … five posts and eight pages …
   this address carries no path of its own — it identifies the page in its
     query string ("?p=4001"), which CheckRedirects cannot match
```

`RedirectMap::currentPathFor()` answered for products, categories and brands.
**It did not answer for a post or a page.** Every one of those thirteen
addresses was being served 200 by this shop at that exact moment.

Two costs, and the second is the one that matters:

* **The sentence was wrong.** A row whose reason is false is worse than a row
  missing.
* **The ask bucket is the owner's work list.** On the real export
  `permalinks.csv` carries one row per post and one per page — the blog is the
  largest post type after products — so his list of decisions would have been
  mostly rows needing no decision. `docs/FV-IMPORT-AT-VOLUME.md` §10 is explicit
  that a question list which is mostly noise is a question list nobody finishes.

Both tables key on `source_post_id`, which is what `PostImporter` matches on,
and the address is the site root with no prefix — not an inference: the Phase 9
seed records the owner confirming it for articles, and the seven WordPress pages
are literal root routes already.

```
ASK — 2
   nothing in this shop carries page id 308   (the WooCommerce shop page, which
                                               this shop serves from a route and
                                               not from a `pages` row)
   this address carries no path of its own — "?p=4001"
```

Fourteen down to two, and **both of the two are real questions.**

The rows that moved did not all move to `discard`. An article whose slug changed
on import — `SlugGuard` refusing a collision with a reserved slug is the real
case — now produces a `migrate` row with the old address and the new one, which
is a redirect that needed writing and that nothing in this repository could
previously propose. Pinned as `it writes a redirect for an article whose slug
changed on import`.

---

## 5. `CheckRedirects`, re-established rather than trusted — and the defect taken

> ▲ **§5.1 AND §5.2 BELOW ARE NO LONGER TRUE, AND ARE KEPT.** They were true
> when they were written and they are the measurement the fix was built on, so
> the refutation is attached to them rather than replacing them. `CheckRedirects`
> IS registered as middleware now; the table is read before the router on every
> storefront request. **§13 carries the fix, the before/after fetches and what
> it changes for `RedirectMap`.**

### 5.1 The middleware is still not middleware

Re-checked rather than read off GB's note, and the check was made stronger:
**three** rows pointing at `/PROOF-INERT/`, all enabled, all matching
`getPathInfo()` byte for byte, for three different kinds of address.

| address | what it is | with a `/PROOF-INERT/` row in place |
|---|---|---|
| `/shop/` | a page the shop serves | **200** — the row is ignored |
| `/product-category/toners/` | an address the shop 301s by itself | **301 to `/product-category/skincare/toners`** — the shop's own destination, not the row's |
| `/product/medicube-…-duo/` | a product page | **200** — the row is ignored |
| `/nothing-here/` | nothing serves it | **404** (no row), and **301 to the row's target** once one is written |

`CheckRedirects` appears nowhere in `bootstrap/app.php`. `grep` finds it in one
file only outside its own: `AppServiceProvider`, inside the
`NotFoundHttpException` closure. **The redirects table is read from the 404
handler and from nowhere else.**

### 5.2 What that means for the rows `permalinks.csv` produces

It is the reason the map's numbers look the way they do, and it is good news
rather than bad:

* **67 of the 99 proposals are `discard`,** and 37 of those are `permalink` rows
  saying *"the old address and the new one are identical"*. Every product on the
  old site is one of those. A shop where most of the old URLs still answer is a
  shop that needs few redirects, and the largest bucket this map could have had
  does not exist — `docs/GB-MEDIA-AND-REDIRECTS.md` §9 settled that with the
  owner and this file corroborates it, 26 addresses at a time.
* **A row can be silently disabled later.** `routes/kbb-brands-blog.php` ends in
  a single-segment catch-all, and every address this map writes is a single root
  segment. Publish an article at a slug a category used to hold and that
  category's redirect stops firing — the article answers 200 and the row sits in
  the table looking correct. `SourceReachability` reports it as `served` at the
  next run and moves it to `ask` with the reason. **So the map is worth
  re-running after any package that adds root addresses**, and Lane GA's is one.
* **A query-string permalink can never be redirected.** `findMatch()` compares
  against `getPathInfo()`, which excludes the query string. `/?p=4001` is a real
  WordPress address for every post on the site and this shop cannot serve a
  redirect for it. It is reported as unreachable rather than written and quietly
  ineffective, and it is one of the two remaining `ask` rows. **Only the owner
  can close it**, with a rewrite rule in `.htaccess` — §10.

### 5.3 ▲ The trailing-slash defect: taken, and it was three defects

`docs/GB-MEDIA-AND-REDIRECTS.md` §6.4 measured this and deliberately left it,
with the patch ready, because it changes the destination of every 301 the site
serves. **This lane took it.** Three reasons, in order of weight:

1. **It is not a new convention — it is the table catching up with the routes.**
   `PageController::legacyPost()` already issues its 301s through
   `Url::redirect()`, for exactly these reasons, and shipped doing so. That made
   the redirects **table** the only producer of a 301 in this application still
   doing it the other way.
2. **It is the difference between the two halves of this lane's own brief.** "The
   old URL resolves" and "the old URL resolves at the address the page itself
   says is canonical" are not the same claim, and a lane whose job is the second
   one cannot report the first and stop.
3. **It is measurable, and GB's objection was that it had not been measured.**
   Both tables are below.

**And the first defect it fixes is not the one it was named for.** Measured on a
preview mounted at `/kbb-upgrade/` exactly as the real host mounts it:

| | old line | new line |
|---|---|---|
| root mount, `/toners/` | `…/product-category/skincare/toners` | `…/product-category/skincare/toners/` |
| root mount, `/ar/toners/` | `…/product-category/skincare/toners` | `…/ar/product-category/skincare/toners/` |
| base path, `/kbb-upgrade/toners/` | `…/kbb-upgrade/product-category/skincare/toners` | `…/kbb-upgrade/product-category/skincare/toners/` |
| base path, `/kbb-upgrade/ar/toners/` | `…/kbb-upgrade/product-category/skincare/toners` | `…/kbb-upgrade/ar/product-category/skincare/toners/` |

▲ **The base path was already right, and §6.4 implies it was not.** Laravel's
`UrlGenerator` builds on the request *root*, which on a subfolder mount already
carries `/kbb-upgrade`. The two real defects are the trailing slash and the
**language**: an Arabic reader following an old link matched the row and was
then dropped onto the English page. That correction is written into both source
files rather than only here.

**Why it matters and is not cosmetic.** The slash-less form answers 200, so
nothing looks broken — and its own canonical tag points at the slashed one:

```
GET /product-category/skincare/toners    200
  <link rel="canonical" href="…/product-category/skincare/toners/">
GET /product-category/skincare/toners/   200
  <link rel="canonical" href="…/product-category/skincare/toners/">
```

So every old URL on the site cost a crawler a 301 **and then** a canonical hop,
to an address the site does not consider its own. Fifteen of fifteen, which on
this shop is the whole redirects table.

**The cost, stated because it is real.** `Url::redirect()` builds on `APP_URL`
rather than on the request's host, so a wrong `APP_URL` sends every redirect to
the wrong host. That is already true of password resets, payment webhooks and
Stripe's callback, all of which go through the same helper — so it is a
precondition this shop already has, not a new one. It is worth the integrator
confirming `APP_URL` on the server before this package is applied.

### 5.4 The mutation that could not go red, which is the finding restated

Putting `CheckRedirects::handle()`'s redirect back to the bare target left the
whole suite green — **and would leave a running server green too, because the
method is never called.** That is the strongest possible statement of §5.1: a
mutation of the middleware is unobservable because nothing is upstream of it.

Rather than leave the line uncovered, `it makes the unregistered middleware copy
answer the same, so the two cannot drift` calls the method directly and asserts
its `Location` **against the handler's**, not against a literal. The class stays
as where the logic is defined, so the day somebody does register it the two
copies must already agree — and a change applied to one of them is what this
catches. M9 is red now.

### 5.5 A test that could not see the thing it was written to check

`SeoEngineToolsTest > it serves a stored redirect instead of the 404` asserted
`->assertRedirect('/shop/')` and **passed against a `Location` of
`http://localhost/shop`**. `assertRedirect()` builds its expected URL through the
same `UrlGenerator` the redirect went through, and that generator strips a
trailing slash — so both sides were stripped and the assertion was blind to the
difference it named.

Rewritten to read the raw header, with the reason on it. It is the only spelling
of that assertion that would go red if the behaviour stopped.

---

## 6. No new route, so no new rule and no cache migration — and it was checked

CLAUDE.md: a new admin route needs a rule in `AdminCapabilities::RULES` unless a
wildcard covers it, and any new route needs a `clear_caches_*` migration to be
reachable on a host with a compiled route cache.

**This lane added none.** The two files arrive at
`POST /admin-api/import/upload`, the endpoint that already existed and which
Lane GM already taught to take a zip; they are read by
`GET /admin-api/urls-media/status`, which Lane GB already built. Checked
**through the resolver** rather than by reading the table, so a rule shadowed by
an earlier wildcard shows up as the capability the earlier one grants:

```php
expect(AdminCapabilities::forPath('POST', 'admin-api/import/upload'))->toBe('data.import');
expect(AdminCapabilities::forPath('GET',  'admin-api/urls-media/status'))->toBe('data.import');
```

`it adds no route, so it needs no capability rule and no clear_caches migration`
pins the exact twelve routes the two route files register, so a later endpoint
cannot be added here without the assertion saying so.

Neither route file claims to be unmounted: both are required from
`routes/web.php` inside the existing `admin-api` group (lines 428 and 441), and
both headers already say LIVE.

---

## 7. Mutation testing

Every guard was broken, watched, and restored, against
`tests/Feature/GpAddressesLandTest.php` (25 tests) plus the three neighbouring
files it shares behaviour with — 122 tests, 534 assertions at baseline.

| # | mutation | result |
|---|---|---|
| M1 | `companionFor()` stops yielding to the entity filename table | **RED** (after §7.1) |
| M2 | `companionFor()` ignores the dropdown | **RED** |
| M3 | the companion column guard is removed | **RED** (3 failed) |
| M4 | `status()` stops feeding permalinks to the map | **RED** |
| M5 | `map.csv` and the write stop feeding permalinks | **RED** |
| M6 | the map stops resolving an article | **RED** (2 failed) |
| M7 | the map stops resolving a page | **RED** |
| M8 | the 404 handler goes back to the bare target | **RED** (2 failed) |
| M9 | the middleware copy goes back to the bare target | **RED** (after §5.4) |
| M10 | `MediaIndex` reads "not in the file" as "gone" | **RED** (2 failed) |
| M11 | `MediaIndex` reads an unknown `exists` value as gone | **RED** |
| M12 | `MediaIndex` keys on the whole path, not the uploads root | **RED** |
| M13 | `media.csv` stops being claimed in `reportUnreadFiles()` | **RED** |
| M14 | `gone_at_source` stops skipping pictures that have arrived | **RED** |
| M15 | `gone_at_source` claims a picture the file says nothing about | **RED** |
| M16 | the status payload stops naming the companions | **RED** (2 failed) |
| M17 | a companion can be uploaded but not removed | **RED** |

### 7.1 The two that survived the first pass, reported rather than hidden

**M1 — `companionFor()` stops yielding to the entity filename table.** Green,
because no entity file name contains `permalinks` or `media`: the guard was
correct and *unreached*, which is the shape this repository has paid for twice
(`Api\ProductController`'s dead status filter, and Lane GF's second
`mismatch === null`).

The input that reaches it is a name **both** tables answer to. Both lookups are
`str_contains` over a normalised basename — the entity table's own spelling
since before this lane — so `products-permalinks.csv` matches the `products`
stem and the `permalinks` stem at once. That is not a contrived string: it is
what a browser writes when the owner has renamed a download, and a shop that
filed it as a permalink file would have silently dropped the products export.
`it lets an entity keep a name a companion would also answer to` is the test;
M1 is red.

**M9 — the middleware copy.** Unobservable by construction. §5.4.

---

## 8. The browser run

`tests/browser/gp-addresses-land.mjs`, shots in `docs/gp-urls-shots/`. Nothing
in it posts to the API to make something happen: the upload goes through the
screen, with a **group zip** built exactly as Lane GL ships one.

```
signed in at: http://127.0.0.1:8971/admin
  shot: 01-import-screen-no-addresses
  shot: 02-map-without-permalinks
before — permalinks present: false | buckets migrate/ask/discard: 30 0 15 | gone at source: 0
  shot: 03-addresses-group-accepted
companions on the screen: permalinks.csv=62 rows media.csv=26 rows
  shot: 04-map-with-permalinks
after  — permalinks present: true | rows: 62 | buckets migrate/ask/discard: 30 2 67 | gone at source: 1
  shot: 05-redirects-written
written — create/unchanged: 0 30
GET /toners/ → 200 final url: http://127.0.0.1:8971/product-category/skincare/toners/
  canonical on the page: http://127.0.0.1:8971/product-category/skincare/toners/
  shot: 06-old-address-landed
```

The last three lines are the whole lane in one fetch: an address the old site
published, followed in a real browser, landing on a real page **at the address
that page itself calls canonical.**

---

## 9. Changes for files this lane may not edit

`resources/views/admin/app.blade.php`. Every anchor below was checked by count
to occur **exactly once** in that file (`grep -cF`), and every block was applied
to a working copy, driven in the browser for §8's shots, and then reverted — so
these are blocks that have been seen to work, not blocks that ought to.

**Until they are applied, the feature works and is invisible.** The files are
accepted, the map reads them and the redirects are right; what is missing is the
owner being able to see that the two files landed, and which of them the map's
answer was built from.

### 9.1 Draw the two companion files on the files card

**Anchor** (occurs exactly once):

```js
    +'<div class="impgrid" style="margin-top:14px">'+cards+'</div>'
```

**Replacement:**

```js
    +'<div class="impgrid" style="margin-top:14px">'+cards+'</div>'
    +gpCompanionsStrip(s)
```

### 9.2 Name the two files the map's answer was built from

**Anchor** (occurs exactly once, inside `gbUrlsMediaCard()`):

```js
    +'<p style="font-size:12px;color:var(--ink-soft);margin:3px 0 0;max-width:680px">'+u.note+'</p>'
```

**Replacement:**

```js
    +'<p style="font-size:12px;color:var(--ink-soft);margin:3px 0 0;max-width:680px">'+u.note+'</p>'
    +gpSourceLine(gbUM.sources)
```

### 9.3 The two functions

**Anchor** (occurs exactly once):

```js
function gbUrlsMediaCard(){
```

**Replacement:**

```js
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

function gbUrlsMediaCard(){
```

The Remove button reuses the existing `.impforget` handler, which posts its
`data-e` to `/admin-api/import/forget` — and that endpoint now answers to a
companion key as well as to an entity and to `manifest`. No new wiring.

### 9.4 `KBB-Master-Plan.md` — for the integrator

Not edited here (CLAUDE.md). The plan's Phase 13 line for the URL map is already
ticked by Lane GB. What is new and belongs in the release note rather than the
plan: *the "Addresses and pictures" group now imports, the redirect map is built
from the addresses the old site really published rather than the ones this shop
can derive, and every redirect now lands on the canonical address in the
reader's own language.*

### 9.5 `docs/GB-MEDIA-AND-REDIRECTS.md` §6.4 — one correction

Not edited here; it is another lane's document. §6.4 says the patch is "offered,
deliberately not applied". **It is applied now**, and its third claim is wrong:
the base path was already correct, because `UrlGenerator` takes it from the
request root. §5.3 above has the measurement. The correction is also written
into `CheckRedirects` and `AppServiceProvider` themselves, so a reader who finds
the code before the document gets it either way.

---

## 10. What only the owner can settle

1. **`/?p=123` and `/?post_type=product&p=123`.** WordPress serves a plain
   permalink for every post on the site regardless of its settings, and this
   shop **cannot** redirect one: `CheckRedirects::findMatch()` compares against
   `getPathInfo()`, which excludes the query string entirely. A row for it would
   never match, so it is reported and not written. If those addresses carry real
   traffic the answer is a rewrite rule in the host's `.htaccess`, which is
   outside this application. It is one of the two rows left in `ask`.

2. **Confirm `APP_URL` on the server before this package is applied.** §5.3.
   Every redirect is now built from it. It is already what password resets and
   the payment webhooks are built from, so a wrong value is a problem the shop
   already has — but this package makes it visible on a page a shopper lands on.

3. **Upload the "Addresses and pictures" group, and upload it LAST.** The map
   resolves each old address against the rows this shop carries, so a
   `permalinks.csv` read before the catalogue is imported puts every row in the
   `ask` bucket with *"nothing in this shop carries product id 4021"*. Nothing
   wrong is written and re-running resolves them — but the first look is much
   more useful after the import than before it.

4. **Re-run the map after any package that adds a root address.** §5.2. A
   published article silently disables the redirect for a category at the same
   slug, and the map reports it as `served` at the next run rather than fixing
   it, because an article the owner published is a real page and a redirect is
   not entitled to take its address.

5. **Is `wp-content/uploads` copied across yet?** Unchanged from GB's question,
   with one addition: `media.csv` now names the pictures for which the answer
   will never be yes. Those need replacing, not moving.

6. **Brand archives.** Still open, and `permalinks.csv` is the thing that closes
   it: the plugin writes a row per brand term whose `permalink` is empty and
   whose note says WordPress returned no archive URL for that taxonomy. On the
   harness shop the answer is **no archive**, so no redirects are needed. The one
   real export settles it.

---

## 11. Files

| File | |
|---|---|
| `app/Services/ImportConsole/ImportWorkspace.php` | `COMPANIONS` — the two files of the export that no importer steps, and the rules for taking one |
| `app/Services/Import/MediaIndex.php` | new — the one column of `media.csv` this shop cannot derive |
| `app/Http/Controllers/Admin/UrlsMediaApiController.php` | the map is built from `permalinks.csv`; the payload says which files it used and which pictures will never arrive |
| `app/Http/Controllers/Admin/ImportApiController.php` | one status payload for every endpoint, carrying the companions; Remove answers to a companion key |
| `app/Services/Import/RedirectMap.php` | resolves an article and a page, which is what turned a 14-row question list into a 2-row one |
| `app/Http/Middleware/CheckRedirects.php` | ▲ every 301 now lands on the canonical address, in the reader's language |
| `app/Providers/AppServiceProvider.php` | ▲ the copy that actually runs, same change |
| `app/Services/Import/ImportRunner.php` | `media.csv` is claimed, so it is not named in the discard list |
| `tests/Feature/GpAddressesLandTest.php` | new — 25 tests |
| `tests/Feature/GmImportAcceptsZipTest.php` | the finding it pinned is fixed; the test now asserts the acceptance, with a note on what it used to assert |
| `tests/Feature/SeoEngineToolsTest.php` | one assertion that could not see what it was written to check |
| `tests/browser/gp-addresses-land.mjs` | new — the screen, the zip, and one old address followed to its canonical page |
| `docs/gp-urls-shots/` | six shots |

---

## 12. Reproducing the fetches

```bash
git worktree add /home/user/kbb-wt/gp -b lane/addresses-group-lands
cd /home/user/kbb-wt/gp
git config user.email noreply@anthropic.com && git config user.name Claude
cp -al /home/user/kbbstore/vendor ./vendor    # hard links, NOT a symlink: a
                                              # symlinked vendor resolves
                                              # Composer's $baseDir back to the
                                              # main repo and runs that code

mkdir -p gp-web-root && cp public-web-root/index.php gp-web-root/
cp -al public/build gp-web-root/build         # public_path() is gp-web-root

export KBB_PUBLIC_PATH=$PWD/gp-web-root SESSION_DRIVER=file
export DB_DATABASE=$PWD/storage/gp/preview.sqlite APP_URL=http://127.0.0.1:8971
php artisan migrate --force && php artisan db:seed --force
# seed the old site's shape, then generate permalinks.csv from it — §3.1

php -S 127.0.0.1:8971 -t gp-web-root gp-web-root/router.php &

# before
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://127.0.0.1:8971/toners/

# the file arrives the way the screen sends it, then the map is driven the way
# POST /admin-api/urls-media/redirects drives it
# after
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://127.0.0.1:8971/toners/
curl -sL -o /dev/null -w '%{http_code} %{url_effective}\n' http://127.0.0.1:8971/toners/
```

For the base-path half, mount the front controller inside a `kbb-upgrade/`
directory and set `SCRIPT_NAME` to `/kbb-upgrade/index.php` in the router, which
is how the real host strips the prefix before `getPathInfo()` sees it. Serving
it at the root with `KBB_BASE_PATH` set does **not** reproduce the host: the
prefix is then part of `getPathInfo()` and no row matches at all.

---

## 13. ▲ The middleware is registered — the fix, and what it costs

§5.1 established that the redirects table was read from the 404 handler and
nowhere else, and §5.2 built the map's whole `reachable()` verdict on it. Both
were correct. **The consequence was the defect, not a design:** a redirect row
for an address the shop already answers could not fire, and the old shop has
five years of URLs.

### 13.1 Why the earlier registration failed, which is not why it looked like it did

`CheckRedirects`' own comment recorded that "a redirect on an existing, matched
route still returned 200 with that registration, both from `boot()` and from
`register()`", and read as evidence that registration cannot work. It is
evidence that **that** registration cannot work, and the reason is one line of
Laravel:

> Middleware in the `web` GROUP runs **after** the router has matched a route.

Which route matches has already been decided by then, so a group registration
can never pre-empt one. Only the global pipeline runs before routing.
`bootstrap/app.php` says exactly this, in as many words, about
`SetLocaleFromPath` — one file over, for the same reason.

### 13.2 Where it is registered

Two places, and the second is the one that reaches the server.

| file | line | reaches the live host? |
|---|---|---|
| `app/Providers/AppServiceProvider.php` | `$kernel->prependMiddleware(CheckRedirects::class)` | **yes** — `app/` ships in a package |
| `bootstrap/app.php` | `$middleware->append(CheckRedirects::class)` | no — `bootstrap/` is on `BuildPackage::NEVER_SHIP` |

The second is kept anyway: a host that was hand-edited must not end up with two
copies, and `Kernel::prependMiddleware()` does an `array_search` before it
unshifts, so both together register one. This is the `/ar` story repeated
deliberately — that feature 404'd on the owner's shop for a month because its
only registration was in a file no package can ship.

**Execution order is `CanonicalHost` → `SetLocaleFromPath` → `CheckRedirects`**,
and both halves are load-bearing. `CanonicalHost` first, because there is no
sense redirecting a path on a host the request is about to be forwarded off —
it folds the path correction into its own hop instead, through
`CheckRedirects::lookup()`. `SetLocaleFromPath` first, because
`redirects.source` carries no locale segment: an Arabic visitor following an
old link has to match the same row an English one does, and `Url::redirect()`
puts `/ar` back on the way out.

### 13.3 The before and after, fetched

Same fixture both times: a product, a category nested one level, and four
enabled rows pointing at `/PROOF-INERT/` — the same probe §5.1 used, one row
per kind of address. `Location` is reproduced verbatim.

**Before** — the registration lines removed, which is exactly the state this
repository was in:

| address | what it is | status | `Location` |
|---|---|---|---|
| `/shop/` | a page the shop serves | **200** | — |
| `/product/tx-serum/` | a product page | **200** | — |
| `/product-category/tx-toners/` | an address the shop 301s by itself | **301** | `/product-category/tx-skincare/tx-toners` |
| `/tx-never-existed/` | nothing serves it | **301** | `/PROOF-INERT/` |
| `/product-category/tx-skincare/` | a row pointing a page at itself | **200** | — |

*Rows that fired: one of five. `SUM(hits)` = 1.*

**After:**

| address | what it is | status | `Location` |
|---|---|---|---|
| `/shop/` | a page the shop serves | **301** | `/PROOF-INERT/` |
| `/product/tx-serum/` | a product page | **301** | `/PROOF-INERT/` |
| `/product-category/tx-toners/` | an address the shop 301s by itself | **301** | `/PROOF-INERT/` |
| `/tx-never-existed/` | nothing serves it | **301** | `/PROOF-INERT/` |
| `/product-category/tx-skincare/` | a row pointing a page at itself | **200** | — |

*Rows that fired: four of five. `SUM(hits)` = 4. The fifth is the loop, refused
on purpose — see §13.5.*

### 13.4 What it costs every page of the shop

A middleware that queries on every request is exactly where a query budget gets
blown, and the answer is "no row" on every address the shop actually serves. So
the set of enabled `redirects.source` values is cached whole
(`CheckRedirects::INDEX_KEY`) and consulted in memory; the table is only touched
once the path is known to be claimed.

| | queries against `redirects` |
|---|---|
| first storefront page after a package (cold index) | **1** |
| three warm storefront pages no row claims | **0** |
| a request that is actually redirected | **2** (the row, and the hit counter) |

`StorefrontQueryBudgetTest` is unchanged and green. The eviction is not a TTL:
`Redirect::saved` and `Redirect::deleted` are hooked in
`AppServiceProvider::boot()`, so an edit on Store → Redirects is live on the
next request exactly as it was before the index existed.

**`Redirect::booted()` was tried first and does not hold**, and the reason is
worth carrying: Eloquent runs `booted()` once per PROCESS (`Model::$booted` is
static and keyed by class) while the event dispatcher is replaced with every
application instance. `Redirect` is first booted by the Phase 9 seed migration,
so in any process that outlives one application the listener is attached to a
dispatcher nothing dispatches to. Measured: a row created after a page had been
rendered was absent from the index and the address it named went on serving its
own page. Under PHP-FPM it would have worked — invisible exactly where it is
checked.

### 13.5 The loop, which is what this change makes possible

While the table was read only on a 404, a row pointing an address at itself
turned one 404 into another. Registered as middleware it is a page the shop was
serving a moment ago, now bouncing for ever. `RedirectMap` line 425 already
refuses to WRITE one; rows from before it did are in the owner's database now,
so the refusal happens at read time as well.

`CheckRedirects::loops()` walks the chain and refuses a cycle of any length, not
only a self-reference — `/a/ → /b/ → /a/` is reachable by hand on
Store → Redirects and neither row points at itself. It is free in the case that
matters: a self-pointing row is caught with no query at all, and a target no
other row claims stops at the cached index, also with no query. Only a genuine
chain costs a SELECT per hop, and only on a request that is being redirected
rather than rendered. An honest chain still resolves — the guard refuses cycles
and nothing else.

### 13.6 The two readers cannot drift

`CanonicalHost` consulted the same table on the same column with its own copy of
the query, which was survivable only while `CheckRedirects` was dead code. Both
now run on every request, and a difference between them is a redirect that fires
on the canonical host and not on an alias — or a loop guarded on one and not the
other. `CanonicalHost::targetFor()` calls `CheckRedirects::lookup()`;
`RedirectMiddlewareTest` asserts the two against **each other** rather than
against a literal.

Not `findMatch()`: that gates on `isMethod('GET')` and `CanonicalHost` forwards
HEAD as well, so going through it would silently stop folding the path
correction into a HEAD request's one hop.

### 13.7 What this changes for `RedirectMap` — for Lane A

`app/Services/Import/**` is another lane's this round, so this is reported and
not done. The premise under these has moved:

- **`RedirectMap` lines 36, 239 and 277, and `SourceReachability`'s whole class
  comment** say the table is 404-only and that an address which does not 404 can
  never be redirected by a row. That is no longer true.
- **`reachable()` demotes every `served` proposal to `discard`** with "the shop
  already does this". Those rows are now perfectly capable of firing, so the
  demotion is throwing away exactly the redirects this fix was for — the
  category-nesting rule Lane GB called INERT is the whole `migrate` bucket of
  that rule. This is the one that needs a decision, not just an edit: some of
  those discards are still right (an address the shop already 301s to the same
  destination needs no row), and some are now wrong.
- **`RedirectMap` line 425 and line 536** — the two `source === target`
  discards — are still right, and are now the *write-time* half of a guard that
  also exists at read time.
- **Line 84 and line 495** — query-string permalinks are still unreachable.
  `getPathInfo()` excludes the query string wherever it is read from, and that
  has not changed.

### 13.8 The `clear_caches` migration, and why it is not optional here

`database/migrations/2026_12_11_000000_clear_caches_redirect_middleware.php`.
No new route — and it still has to run. `route:cache` compiles the router's
**middleware stack** into `bootstrap/cache/routes-*.php`, and `config:cache`
freezes the rest of the boot; `warm_caches_2_60_4` writes both from inside the
migration set, so any host that has ever run the set has them. A package adding
a global middleware to such a host adds it to a file nothing reads: the code
lands, the class is never called, and nothing in any log says so. The migration
also forgets `CheckRedirects::INDEX_KEY`, so applying a package can never leave
a stale index behind.
