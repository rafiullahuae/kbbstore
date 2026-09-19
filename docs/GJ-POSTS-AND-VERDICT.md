# The Journal gets its articles, and the count check reaches a verdict on screen

Lane GJ. Two jobs that share one importer and one report.

1. **`posts.csv` had no importer.** Lane GA measured what that costs against a
   running server: the Journal's permalink structure is finished and serving
   nothing. There is now a `posts` entity, it is registered on `ImportRunner`
   and on Store → Import, and `/skincare-guide/` renders real articles for the
   first time. §§1–5.
2. **Phase 13's count verification never reached a verdict on the admin
   screen.** Lane GF found it and wrote it up rather than fixing it. It is
   fixed, the fix is *not* the one-liner GF proposed, and §7 says why that one
   would have been wrong on the second import. §§6–9.

---

## 1. Method

Two rigs, both on this worktree, `vendor/` **hard-linked** (a symlinked
`vendor` resolves Composer's `$baseDir` back to the main repo and silently
tests the other tree), `SESSION_DRIVER=file`, `KBB_PUBLIC_PATH` pointing at
`public-web-root` — a **different directory from the application root**, which
is the shape the live host has.

| | |
|---|---|
| Storefront | `php -S 127.0.0.1:8951` over `public-web-root/index.php`, its own SQLite database migrated and seeded from `DatabaseSeeder`, then `php artisan kbb:import --dir=tests/Fixtures/woo --only=posts`. |
| Admin | the same server, logged in through the real login form, the whole export uploaded and stepped through `/admin-api/import/step` — the owner's only route. |
| Browser | Chromium `/opt/pw-browsers/chromium-1194/chrome-linux/chrome`, driven with Playwright. Screenshots in `docs/gj-journal-shots/`. |
| Export | `tools/woo-volume-fixture/generate.php --products=120 --orders=260 --customers=180` — **4,057 rows across 10 files**, plus the four files nothing opens and the contract's `manifest.json`. |

`df -h /` was checked before every run, per CLAUDE.md: 18 GB free throughout,
so nothing here is the full-disk failure that looks like a transaction bug.

---

## 2. What was actually missing

`docs/GA-SKINCARE-GUIDE.md` §6 is exact and this lane found nothing to correct
in it:

* `database/seeders/` has no post seeder,
* `app/Services/Import/Entities/` had importers for brands, categories,
  products, coupons, customers, orders, order items, reviews and SEO, and
  **none for posts**,
* the admin Blog Posts screen is read-only by its own comment.

`docs/WP-EXPORT-CONTRACT.md` marks `posts.csv` as one of seven **gap** files:
written by Lane GE's plugin, opened by nothing. `tests/Fixtures/kbb-export/posts.csv`
— the plugin's own output, checked in by GE — has been sitting in the
repository unread since that lane merged.

---

## 3. `PostImporter`, and the decisions inside it

`app/Services/Import/Entities/PostImporter.php`. Matched on
`posts.source_post_id`, every write through `ImportContext::apply()`, so a
second pass reports `unchanged` from Eloquent's own dirty check and not from
the importer deciding it did nothing.

### 3.1 ▲ An article at a reserved first segment is REFUSED

This is the hazard GA asked to be settled **before** an importer was written,
and it is the one decision in this lane that costs the owner something.

Articles live at the site root — `/{slug}/` — which is the address space the
whole storefront shares. `PageController::slugPattern()` puts a negative
lookahead over `RESERVED_SLUGS` in front of the catch-all, so `/cart`,
`/checkout`, `/wishlist`, `/about`, `/feed` and forty more belong to the shop.
A live WordPress article slugged `about` is **an indexed URL this application
can never serve**, whatever the importer does with the row.

Three things could happen to such a row and only one of them is honest:

| | |
|---|---|
| import it at its own slug | a row in the database that no request can reach, while the report says `created`. The worst outcome available: nothing would say so until Search Console did. |
| import it at a changed slug | the shop then has an article at an address that was never indexed, and the indexed address still serves the shop's page. Silent, and it takes a decision away from the owner. |
| **refuse it, by name** | what it does. |

That follows `SlugGuard`'s own precedent for the same class of problem — *"two
real things want one slug and only one can have it. Always refused. Which one
keeps it is a content decision and the importer has no basis for making it."*

**It is visible in three places, which is the point.** The refusal names the
segment and the remedy; the row is written into the **discard list**, which is
the channel Phase 13 says the owner *approves* rather than merely reads, with
the article's title and its would-be address; and it raises the bucket's
`refused` count, so §6's arithmetic accounts for it.

```
line 4  [id=7003]  slug 'wishlist' is a first path segment this storefront already
        serves (PageController::RESERVED_SLUGS), so /wishlist/ is the shop's page and
        never this article. Importing it would write a row no request can reach.
        Either rename the article in WordPress and add a redirect from the old
        address, or say which of the two /wishlist/ should be — only the owner can
        settle that.
```

**The check is the router's own pattern, not a copy of the list.**
`preg_match('/^(?:'.PageController::slugPattern().')$/', $slug)` — so a slug is
refused if and only if the route would refuse it, the configurable admin path
(`KBB_ADMIN_PATH`) is folded in for free, and a lane that adds a route and a
reserved slug tomorrow moves this importer with it. A second copy of
`RESERVED_SLUGS` here would be a second copy to go stale.

> **▲ What the owner has to do about it.** GA asked for *"the full list of live
> article slugs"* and it still has not been supplied. Until it is, nobody knows
> whether this case is nought articles or six. The import now **tells him**: run
> the preview (`Store → Import`, "Look at what would happen"), which writes
> nothing and rolls itself back, and any article at a reserved address is in the
> refusal list with its title before a single row is written. That is the list
> GA asked for, produced by the export rather than from memory. For each one he
> decides: rename it in WordPress and add a redirect row from the old address,
> or say that the article should own the address and have the reservation
> lifted — which is a routing change and a different lane's file.

### 3.2 A slug of the wrong SHAPE is normalised, not refused

`My_Post`, `SPF_50_Every_Day`, and the percent-encoded `%d8%a7%d9%84…` that
WordPress writes for a non-Latin title are not reserved — nothing else owns
them — but `slugPattern()`'s character class cannot match them either. Refusing
those would lose the article for a reason the owner cannot act on, so they are
normalised, the article is published at an address that works, and the change
is reported as an **adjustment** with the before and the after:

```
slug: SPF_50_Every_Day  ->  spf-50-every-day
slug: العناية-بالبشرة    ->  skin-care-in-the-gulf-summer
```

The second line is a decision inside a decision. `Str::slug()` transliterates,
so the Arabic slug on its own comes back as `alaanay-balbshr` — a valid address
no human will ever recognise. A slug with **no Latin character in it at all** is
normalised from the TITLE instead; a slug that has letters or digits keeps being
the basis, because it carries information the title may not.

Either way the old address 404s and needs a redirect row. The adjustment says
so in as many words.

### 3.3 Only `type = post` is imported

`posts.csv` carries the blog AND the pages AND any other post type the shop has
— one file with a `type` column, by the exporter's own design (its stage class
uses a **deny**list precisely so a page-builder's post type is not dropped in
silence). This entity writes `posts`, which is the Journal and nothing else.

A WordPress `page` is **refused**, with its title and its content length in the
discard list. Importing pages is a decision and not a mapping: this application
already ships its own `/about/`, `/delivery/`, `/faqs/`, `/privacy-policy/` and
`/terms-and-conditions/`, their copy is byte-pinned by
`tests/Feature/StorefrontEnglishUnchangedTest`, and deciding which of two
`/about/` pages the shop serves is the owner's call.

**Refused rather than skipped, and that is not a style choice.** A row that
moves no tally and throws nothing is recorded by `ImportRunner` as
UNACCOUNTED — the alarm reserved for a row that vanished, which *fails the
command*. Spending it on a row the importer deliberately declined would make
the one alarm nobody may ignore fire on an ordinary import.

### 3.4 Nothing is published on the owner's behalf

`publish` → `published`. Everything else → `draft`, which keeps the row, keeps
the slug reserved against a later article stealing it, and serves nothing.

`future` is the one that would bite: `PageController::blog()` filters on
`status` and **not** on `published_at`, so a scheduled post imported as
published would be on `/skincare-guide/` the moment the import finished. It is
an adjustment (`status: future -> draft`); `draft -> draft` is not, because an
adjustment list with a no-op in it is a list that gets skimmed.

### 3.5 The body is cleaned, and the heading survives the cleaning

`store/post.blade.php` renders the body with `{!! !!}`. A WordPress export
carries whatever a plugin put in the post, so the body goes through
`RichText::clean()` for the same reason `ProductImporter` cleans a description.

But `RichText` does not allow `h1` and does not treat it as hostile, so it
**unwraps** it: cleaning a raw WordPress body straight off turns every
"Heading 1" block into ordinary paragraph text and flattens the article's
outline. `App\Support\BodyHeadings` exists because imported bodies carry `h1`s
and says in as many words that stripping is the wrong disposal here.

So the `h1` is demoted to `h2` **before** the allowlist runs. The heading
survives as a heading, the page still has exactly one `h1` (its own), and the
executable content still does not survive. Both halves are pinned, and the
second test proves the negative: `RichText::clean('<h1>Why twice</h1>…')` keeps
the words and loses the heading.

### 3.6 What else is reported rather than lost quietly

| | |
|---|---|
| categories and tags after the first | `posts.tag` holds one label and this shop has no post taxonomy. The first category wins, the rest are a **discard** with all of them listed. |
| `author_email`, `author_id`, `parent_id`, `position`, `comment_status`, `date_modified` | no column here reads them, so `ImportRunner`'s ignored-columns channel names all seven with the first value the file held for each. No new code. |
| HTML the allowlist removed | a **discard**, by length, like `ProductImporter`'s. |

---

## 4. It is drivable from Store → Import

`ImportWorkspace::ENTITIES` gained a `posts` entry beside the runner's, because
those two hand-maintained lists have to agree — registering `seo` on the runner
alone once made every upload 500 on an undefined key, and there is a test
pinning them against each other. The card reads **Journal articles** and its
help text states the reserved-address hazard in the place the owner is standing
when it matters:

> Your blog. The export writes every WordPress post type into this one file;
> only articles are imported, and a page or anything else in it is named in the
> report rather than written. An article is served from the site root, so one
> whose address this shop already owns — /about/, /wishlist/, /feed/ — is
> refused by name rather than written somewhere nothing can reach.

`posts` is registered **last**, and unlike `seo` that is not a dependency: an
article references nothing this import writes and could run first. It is last
because that array is the order the owner's browser walks it, and the catalogue
and the orders are what the shop cannot open without. Said out loud in both the
runner and the order test, because the assertion beside it says the order IS a
dependency graph and the next reader would look for the dependency.

---

## 5. The page that has never rendered a real article

Fetched against the running server, after importing `tests/Fixtures/woo/posts.csv`
through the real `ImportRunner`. The **before** column is measured, not quoted:
`PostImportTest` fetches `/skincare-guide/` and the article's own address before
it imports anything, so the comparison is between two states this suite has
seen.

| address | before this lane | now |
|---|---|---|
| `/skincare-guide/` | 200, an index with no cards | **200, four cards** |
| `/heartleaf-extract-transforming-k-beauty-skincare/` | 404 | **200** |
| `/spf-50-every-day/` (slug normalised) | 404 | **200** |
| `/skin-care-in-the-gulf-summer/` (was percent-encoded) | 404 | **200** |
| `/winter-edit-2027/` (scheduled) | 404 | 404 — imported as a draft |
| `/retinol-for-beginners/` (draft) | 404 | 404 — imported as a draft |
| `/wishlist/` | 302, the shop's own | **302, unchanged** — the article was refused |
| `/about/` | 200, the shop's own page | **200, unchanged** — the article was refused |

`sitemap.xml` picks the articles up with no change: `SeoFilesController` has
always listed `posts` where `status = 'published'`, against an empty table. The
half of GA's ▲3 that said `/skincare-guide/` is *"the same crawl budget spent on
an empty state"* stops being true the moment an export is imported.

| screenshot | what it shows |
|---|---|
| `1-journal-index-with-articles.png` | the index with four real cards, the tag filter built from imported labels |
| `2-article-served-at-the-site-root.png` | the article at `/{slug}/`, one `<h1>`, the demoted heading, the body |
| `3-normalised-slug-article.png` | the article whose slug was `SPF_50_Every_Day` |
| `4-reserved-slug-is-the-shops-own-page.png` | `/about/` still serving the shop's page, which is why the article was refused |

### ▲ 5.1 A finding the screenshots make unmissable

**Every image on those two pages is broken**, and it is not the fixture. The
cover and the inline `<img>` are hot-linked to `kbeautybliss.com`, exactly as
`ProductImporter` leaves a product photograph — and `MediaRewrite`, the thing
that repoints those URLs at the copied `uploads` folder, **does not know about
`posts`**:

```php
/** The four columns that hold an image URL, per App\Support\MediaUsage. */
private const COLUMNS = [
    [Product::class, 'products', 'image',  false],
    [Product::class, 'products', 'images', true],
    [Brand::class,   'brands',   'logo',   false],
    [Category::class,'categories','image', false],
];
```

So after a clean, fully verified import, **every photograph in the Journal is
hot-linked to WordPress** and `kbb:import-media-rewrite` will not move it. That
is the exact failure `MediaRewrite`'s own header describes: *"The pages look
perfect. They keep looking perfect right up to the day the old site is switched
off."*

**Not fixed here, and it is not a one-line add.** `posts.cover` is a whole-cell
URL and would slot into that list, but the images inside `posts.body` are
`<img src>` inside HTML, which that column model cannot express at all — it
rewrites cells, not documents. `MediaUsage` also drives the admin's "where is
this image used" screen, so widening it changes a screen this lane does not own.
**Requirement for whoever owns `app/Support/MediaUsage.php` and
`app/Services/Import/MediaRewrite.php`:** add `posts.cover` as a fifth column,
and decide separately whether body HTML gets a rewriter. CLAUDE.md: say so
rather than edit it.

### 5.2 `/api/*` was already safe for this, and it was checked

`/api/*` is unauthenticated and CLAUDE.md says every endpoint there is public.
This import puts two new kinds of value into `posts`: `source_post_id`, a
WordPress id, and rows whose `status` is `draft` — a scheduled article the owner
has not published. `Api\PostController` already excludes both by explicit
allowlist and an explicit `where('status', 'published')` on **both** its
endpoints, and `tests/Feature/ApiSecurityTest.php > it hides draft posts from
the feed and by slug` pins it. Nothing needed changing; it needed checking,
because an importer that starts writing drafts into a table whose feed did not
filter them would be a leak nobody would attribute to the importer.

---

## 6. The verdict on the screen — what was wrong

`docs/FV-IMPORT-AT-VOLUME.md` §7 built count-based verification because every
other figure in the report is the importer describing its own work:

> `created` is incremented by the code that created the row, `rejected` by the
> code that refused it. **A row read from the file and then neither written nor
> refused increments nothing**, and is invisible in a report whose every column
> is self-reported. The totals still look plausible.

Its fifth careful rule withholds the verdict on a resumed run:

> **A resumed run reports the count without a verdict.** It cannot know how many
> of the rows it did not re-read were refusals, so `read − refused` is not the
> number the table should hold.

The reason is right. The consequence, which Lane GF measured, is that the check
did not work where the owner uses it: `ImportDriver` steps **one entity per HTTP
request**, so every step after the first resumes, and every entity that takes
more than one step ended on `counted` — two numbers side by side and no verdict.
GF's screenshots show it seven times. At the owner's real volume that is every
entity, on the only route he has. **He had Phase 13's check in name only.**

`5-no-verdict-before-this-lane.png` reproduces it on this rig: `orders`,
`order lines` and `reviews` — the three entities that took more than one step —
all end on *"the two numbers are reported side by side rather than compared"*.

> The BEFORE screenshot was taken with one line of the finished code disabled
> (`$trusted = $this->resumedRows === 0;`) rather than off a pre-lane checkout,
> so the withheld sentence carries this lane's wording. What it is evidence of
> is the **shape** GF recorded: a resumed entity, two numbers, no verdict.

---

## 7. ▲ `rejected_rows` does NOT close it on its own, and the difference matters

GF's note says the fix is one line of arithmetic:

> `import_checkpoints` already carries `rejected_rows` cumulatively for the run,
> alongside `processed`. `processed − rejected_rows` is exactly the expected row
> count the resumed case says it cannot compute.

**That is true of a first import and false of every one after it**, and the
direction it fails in is the dangerous one.

`Checkpoint::open()` resets `processed` to zero when a FINISHED entity is run
again — which is not an edge case, it is the documented sequence: *a full
import, then a delta, then a cutover delta on the night, each from a NEW
export*. Until this lane it did **not** reset the four outcome counters beside
it. So on the second import:

```
pass A (full)    processed 671   created 669  rejected 2      <- finishes
pass B step 1    processed   0  -> 400        rejected 2 + 2 = 4
pass B step 2    resumes on processed=400, reads rejected_rows=4
                 truth is 2
```

`processed − rejected_rows` then comes out **two too small**, the expected row
count is understated, and a table that is genuinely two rows short reads
**VERIFIED**. The check that exists to catch silently vanishing rows would
certify their absence. FV's own report says it: a verdict that is wrong is worse
than one that is absent.

`ImportDriver` already knew about the stale counters — it keeps a per-run
`baselines` map in `import_runs` and subtracts it before display, with a comment
saying why. That compensation is for the screen's numbers only; nothing carried
it into the arithmetic.

### What was done instead

**Two changes that have to agree with each other, plus a guard for the rows
already on the owner's server.**

**(a) `Checkpoint::open()` zeroes the four counters wherever it zeroes
`processed`.** They count outcomes among the `processed` rows; leaving them
behind makes them describe a pass that is over. This restores an invariant the
table's own documentation implies and which the verdict can rest on:

```
created + updated + unchanged + rejected  <=  processed
```

with the shortfall being the rows that moved nothing — which is precisely the
defect being hunted. (It also makes Lane GD's Migration Progress page truthful
on a second run, where it used to print `processed 400, created 1,067`.)

**(b) `ImportDriver::baselineFor()` returns zero for a finished entity.** The
baseline is computed *before* the runner opens the checkpoint, so with (a) in
place subtracting the pre-zero values as well would take the second pass's
numbers off their own baseline and show the owner nothing but noughts. The two
compensations are now one.

**(c) The invariant is CHECKED, and the verdict is still withheld when it
fails.** `Checkpoint::$resumedCountsTrusted` is false when the counters exceed
the offset, which is a shape (a) can no longer write — but which is sitting in
the owner's `import_checkpoints` right now, written by the code that shipped
before it. Detected, named, and the run falls back to exactly what FV built:

> …and the progress record for it carries outcome counts from an earlier,
> completed pass over the same entity, so it cannot say how many of the rows it
> did not re-read were refusals. The two numbers are reported side by side
> rather than compared.

**(d) `EntityReport::verification()` subtracts the whole file's refusals.**
`read − (this process's refusals + the earlier process's refusals)`. The second
number is not self-reported: it is a column committed inside the same
transaction as the rows it counts, by a process that has since exited, read back
off the database. That is the same property that makes `processed` trustworthy
enough to resume from.

Every number in the sentence adds up, which is not decoration — a resumed row is
counted as read AND as accounted for, so a row an earlier process refused is
already inside `accounted` and must not be added to the refused figure as well,
or the line would not balance:

```
verification — 2,514 read, 2,512 accounted for, 2 refused (2,400 of them
committed by an earlier run and not re-read; 37 of those were refused then,
so 39 refused against this file in all), 2,475 in the database.
```

`2,512 + 2 = 2,514`. `2,514 − 39 = 2,475`. Verified.

**Two refusal figures, and the console's table prints the other one.**
`verification()` now returns `rejected` (this invocation's) and
`refused_in_file` (the whole file's, across every invocation). They differ only
on a resumed run, and each carries one of the two subtractions:
`accounted + rejected = read` catches a row read and then neither written nor
refused; `read − refused_in_file = in_database` catches a row that is simply
missing. `kbb:import`'s per-bucket table prints the second, because that is the
column a reader checks against the database column beside it — printing this
process's figure there would make the subtraction fail to reach the database
count on every resumed run, for a reason a table has no room to explain. The
sentence in the notes spells out both. Both are pinned.

---

## 8. Driven through the screen, 26 steps

The whole export uploaded and stepped through `/admin-api/import/step` as the
logged-in owner, 200 rows a step. `orders` took 2 steps, `order lines` 4,
`reviews` 13 — the three that used to end on `counted`.

```
categories    59 read,    56 accounted for,  3 refused,    56 in the database.
brands        93 read,    85 accounted for,  8 refused,    85 in the database.
products     120 read,   118 accounted for,  2 refused,   118 in the database.
coupons       40 read,    40 accounted for,  0 refused,    40 in the database.
customers    181 read,   180 accounted for,  1 refused,   180 in the database.
orders       260 read,   260 accounted for,  0 refused (200 of them committed by an
             earlier run and not re-read; 0 of those were refused then, so 0 refused
             against this file in all), 260 in the database.
order-items  655 read,   655 accounted for,  0 refused (600 …), 655 in the database.
reviews    2,514 read, 2,512 accounted for,  2 refused (2,400 …; 37 of those were
             refused then, so 39 refused against this file in all), 2,475 in the database.
seo          120 read,   118 accounted for,  2 refused — this entity writes onto rows
             another entity owns, so there is no table of its own to count.
posts         15 read,     9 accounted for,  6 refused,     9 in the database.
```

`6-verdict-reached-on-every-entity.png` against
`5-no-verdict-before-this-lane.png` is the whole change: ten verdicts where
three entities had none.

The `posts` line is the export's own hazards, at the density the generator now
writes them: of 15 rows, 3 are at reserved addresses, 3 are pages, and the
remaining 9 are articles — 3 of which had unservable slugs and were normalised.

---

## 9. Tests, and what changed under them

### 9.1 The FV assertion that moved

`ImportCountVerificationTest > it counts a resumed row as read, so a continued
import does not report a shortfall` asserted `verdict === 'counted'`.

**The property it was protecting** is in its own comment: a resumed run must not
raise a **false shortfall**, because *"comparing them anyway would raise a
shortfall on every resumed import that refused anything, and on shared hosting
every import is resumed"*.

**How it is protected now.** That test's own first slice refuses a row — pinned
explicitly, so the fixture cannot quietly stop carrying one — which makes it
exactly the case FV feared. It no longer raises a shortfall, because the
expected count subtracts the earlier slice's refusals too. Three new tests
carry the rest of the property:

| test | what it holds |
|---|---|
| `would claim a shortfall on a resumed run if the earlier refusals were not subtracted` | the mutation written down: drop `resumedRejected` and the same correct import reports *"1 row is missing from the database"*. The number is load-bearing, not decorative. |
| `catches a row that went missing in a run this process never saw` | delete a row the first slice imported; the resumed run reports `discrepancy`, which the withheld verdict could never do. **This is the check FV built, working across a resume for the first time.** |
| `withholds the verdict when the progress record carries an earlier pass's counts` | the narrow case `rejected_rows` still cannot be believed; verdict `counted`, `hasDiscrepancy()` false. FV's fallback kept, not deleted. |

Two more pin the machinery: `zeroes the outcome counters wherever it zeroes the
offset` (the invariant, on the table) and `hands back no resumed refusals for a
checkpoint that is at row zero` (Checkpoint's contract, asserted directly
because no import can reach that shape any more and the property still has to
hold).

### 9.2 `AdminImportScreenTest > it walks the entities in the importer's own order`

Hard-coded list; `posts` appended, with the reason it is last written beside it.
The properties it protects — customers before orders, `seo` after products, and
the two lists agreeing — are all untouched.

### 9.3 `AdminImportScreenTest`, one test added

`it shows the second import's own numbers, not the first import's added to
them`. Nothing covered the driver's baseline for a re-run, which is how mutation
M11 survived the first pass: removing the branch left the whole file green.

### 9.4 `GeWpExporterTest`, two assertions narrowed

`it imports the plugin export cleanly` and the idempotency test both asserted
`geRejections($report) === []`. GE's own `posts.csv` fixture carries a WordPress
**page**, so opening the file for the first time produced one refusal.

**The property they protect** is that the plugin's output imports with nothing
refused — an export this shop cannot read is not an export. **It is still
protected**, and narrowed by ROW rather than by entity or by reason:
`geDeclinedByDesign()` names exactly `posts line 3 (id=7002)`, everything else
must still be `[]`, and the decline itself is now asserted — if `PostImporter`
stopped refusing the page, or started importing WordPress pages over this shop's
`/about/`, the excused list would go on being empty and the new assertion goes
red instead. The article in the same file is asserted to have landed, which is
the round trip the file was written for.

### 9.5 `StorefrontEnglishUnchangedTest` — `BASE_COMMIT` did not have to move

The brief asked whether importing posts changes what this test sees. **It does
not, and the reason is not luck**: the test seeds its own article
(`EnglishRenderWalk::seed()` creates `walk-article`) and renders the old views
and the new views against **the same database in the same process**. This lane
changed no Blade, so both sides are identical bytes.

Mutation-proved anyway, because a pin nobody has watched fail is a pin nobody
knows is live: replacing `{{ __('store.journal.eyebrow') }}` in
`store/blog.blade.php` with literal copy turns it red (`1 failed, 2 passed`),
restored green.

### 9.6 Mutation testing — every guard, and the two that did not die first time

Each mutation applied to the source, the named suite run, then restored.

| # | mutation | result |
|---|---|---|
| M1 | `$trusted = $this->resumedRows === 0;` (the pre-fix behaviour) | **3 failed** |
| M2 | `$trusted = true;` — trust the counters unconditionally | **1 failed** |
| M3 | `$refusedInFile = $rejected;` — this process's refusals only | **3 failed** |
| M4 | do not zero the counters when the offset is zeroed | **1 failed** |
| M5 | hand back the checkpoint's refusals even at offset zero | **survived**, then **1 failed** — see below |
| M6 | the invariant admits counters larger than the offset | **1 failed** |
| M7 | no reserved-slug check at all | **4 failed** |
| M8 | clean the body before demoting its `h1` | **1 failed** |
| M9 | import every post type as an article | **4 failed** |
| M10 | a scheduled post arrives published | **1 failed** |
| M11 | the driver keeps its old baseline for a finished entity | **survived**, then **1 failed** — see below |
| M12 | Checkpoint reports the counters trustworthy at offset zero | **1 failed** |
| M13 | literal copy in `store/blog.blade.php` | **1 failed** (the byte pin) |

**M5 and M11 survived the first pass and are reported rather than hidden.**

* **M5** — `Checkpoint::resumedRejected` returning the raw `rejected_rows` at
  offset zero. No end-to-end test could tell: `ImportRunner` asks for the value
  only when the offset is above zero, so a wrong answer there is invisible. A
  guard no test can distinguish is a guard that will be deleted, so the property
  is now asserted on the class directly, against a hand-forged legacy row. M5
  and M12 both go red.
* **M11** — `ImportDriver::baselineFor()`'s new finished-entity branch. Nothing
  in the repository drove an entity to completion through the screen and then
  ran it again, which is the only sequence that can see it. §9.3 is that test.

### 9.7 Runs

Both engines, both on the final tree:

```
vendor/bin/pest
  Tests: 4,562 passed, 21 skipped, 0 failed (32,950 assertions)

KBB_TEST_DB=kbb_gj vendor/bin/pest -c phpunit-mysql.xml
  Tests: 4,568 passed, 15 skipped, 0 failed (33,276 assertions)

find app database routes -name '*.php' -print0 | xargs -0 -n1 php -l
  clean; and the same over tests/ and tools/
```

The MySQL run matters more than usual here. The change in §7 is arithmetic over
integer columns of `import_checkpoints`, and the two engines disagree about
enough (collation, JSON key order, integer coercion) that this repository keeps
a parity job for it.

Tests on file-based SQLite, never `:memory:` — CLAUDE.md says why and it is not
negotiable here either.

---

## 10. For the integrator

### 10.1 The entity registration — anchor + replacement, verified by count

Three lanes register an entity this round (GH, GI and this one), so this is
handed back as text rather than relied on as a merge.

**`app/Services/Import/ImportRunner.php`**, the import — anchor **verified:
exactly 1 occurrence** at `10ad3fe`:

```php
use App\Services\Import\Entities\OrderItemImporter;
```

becomes

```php
use App\Services\Import\Entities\OrderItemImporter;
use App\Services\Import\Entities\PostImporter;
```

and the registration — anchor **verified: exactly 1 occurrence** at `10ad3fe`:

```php
            new SeoImporter,
        ];
    }
```

becomes the same three lines with `new PostImporter,` and its comment inserted
before the `];`. The comment is in the committed file; it says that `posts` is
last and that this is **not** a dependency.

**`app/Services/ImportConsole/ImportWorkspace.php`** — anchor **verified:
exactly 1 occurrence** at `10ad3fe`:

```php
            'help' => 'Your Yoast post-meta, matched to products by their WooCommerce id. Run it after products, and it never overwrites a title or description you have typed here.',
        ],
    ];
```

with the `'posts' => [...]` block inserted before the closing `];`. **Both lists
must move together**: `AdminImportScreenTest` fails if they disagree, which is
the failure that once cost a package.

`tests/Feature/AdminImportScreenTest.php`'s hard-coded order list needs the same
addition; the three lanes' entries go in runner order.

### 10.2 Files this lane touched that other lanes may be in

| file | what changed | why it is not mine alone |
|---|---|---|
| `app/Services/ImportConsole/ImportDriver.php` | `baselineFor()` returns zero for a finished entity | one half of §7's pair; the other half is in `Checkpoint`. It **must** land with it — either alone is wrong. |
| `app/Services/ImportConsole/ImportWorkspace.php` | the `posts` entry | GH and GI add entries here too |
| `tools/woo-volume-fixture/generate.php` | a `posts()` writer | additive; scales off the catalogue like the unread files |
| `docs/IMPORT-RUNBOOK.md` | one row in the entity table | the table is missing `seo` as well — worth a sweep |
| `tests/Feature/GeWpExporterTest.php` | §9.4 | GE's file |

### 10.3 Left for someone else

1. **`MediaRewrite` / `MediaUsage` do not know about `posts`** (§5.1). Every
   Journal photograph is hot-linked to the old site after a clean import, and
   the tool that fixes that for products will not touch it. `posts.cover` is a
   one-line add; the `<img>` tags inside `posts.body` are not.
2. **WordPress pages are refused** (§3.3). The content is named in the discard
   list and is not in the shop. If the owner wants them, a `pages` entity is the
   next file, and the first thing it has to settle is what happens to
   `/about/`.
3. **The list of live article slugs** GA asked for. §3.1 says how the import now
   produces it instead of waiting for it, but the owner still has to read it and
   decide.
4. **The admin Blog Posts screen is still read-only.** An import is not an
   editor: an article refused for its address cannot be fixed inside this shop,
   only in WordPress and re-exported.

---

## 11. Reproducing this

```bash
git worktree add /home/user/kbb-wt/gj -b lane/import-posts-and-verdict
cd /home/user/kbb-wt/gj
cp -al /home/user/kbbstore/vendor ./vendor     # hard links, NOT a symlink
cp -al public/build public-web-root/build

df -h /                                        # before anything: CLAUDE.md
php artisan migrate --force && php artisan db:seed --force
export KBB_PUBLIC_PATH=$PWD/public-web-root SESSION_DRIVER=file
php artisan kbb:import --dir=tests/Fixtures/woo --only=posts
php -S 127.0.0.1:8951 -t public-web-root <router> &
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8951/skincare-guide/
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8951/heartleaf-extract-transforming-k-beauty-skincare/
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8951/wishlist/     # 302, the shop's

php tools/woo-volume-fixture/generate.php /tmp/gj-export \
    --products=120 --orders=260 --customers=180
# upload the ten files + manifest.json through /admin-api/import/upload,
# POST /admin-api/import/start, then POST /admin-api/import/step until complete.

vendor/bin/pest tests/Feature/PostImportTest.php
vendor/bin/pest tests/Feature/ImportCountVerificationTest.php
vendor/bin/pest tests/Feature/AdminImportScreenTest.php
vendor/bin/pest tests/Feature/GeWpExporterTest.php
vendor/bin/pest tests/Feature/StorefrontEnglishUnchangedTest.php

vendor/bin/pest
KBB_TEST_DB=kbb_gj vendor/bin/pest -c phpunit-mysql.xml
find app database routes -name '*.php' -print0 | xargs -0 -n1 php -l
```

### What shipped

| file | what it is |
|---|---|
| `app/Services/Import/Entities/PostImporter.php` | the entity; §3 |
| `app/Services/Import/ImportRunner.php` | the registration, and the resumed refusals handed to the report |
| `app/Services/ImportConsole/ImportWorkspace.php` | the Journal articles card |
| `app/Services/Import/Checkpoint.php` | the counters zeroed with the offset; `resumedRejected`; `resumedCountsTrusted` |
| `app/Services/Import/EntityReport.php` | the verdict a resumed run can now reach, and when it still cannot |
| `app/Services/ImportConsole/ImportDriver.php` | the other half of the baseline change |
| `app/Console/Commands/ImportWooCommerce.php` | `posts` in the `--only` help |
| `tests/Fixtures/woo/posts.csv` | twelve rows, every hazard §3 decides |
| `tools/woo-volume-fixture/generate.php` | `posts.csv` at volume, with the same hazards at a fixed rate |
| `tests/Feature/PostImportTest.php` | 13 tests |
| `tests/Feature/ImportCountVerificationTest.php` | FV's file: one assertion moved, five tests added |
| `tests/Feature/AdminImportScreenTest.php` | the order list, plus the second-run counters |
| `tests/Feature/GeWpExporterTest.php` | §9.4 |
| `docs/IMPORT-RUNBOOK.md` | one row |
| `docs/gj-journal-shots/` | six screenshots |
