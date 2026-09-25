# Articles at addresses the shop owns — the screen, and the address it redirects from

Lane U3, Phase 13 items 2 and 3. Written after reading the code rather than the
plan, because most of both items was already built and the round was better
spent on what was not.

## Where it sits in the admin

**Store → Import → "Articles at addresses this shop owns"**, opened at

```
GET /<admin path>/admin-api/import/article-addresses-page
```

beside the two Lane A already mounted, which answer the same question in the
two forms that are not a screen:

```
GET /<admin path>/admin-api/import/article-addresses        JSON
GET /<admin path>/admin-api/import/article-addresses.csv    the spreadsheet
```

The page links the CSV itself, relatively, so the download survives
`KBB_BASE_PATH`, the admin path having been moved, and the shop being served
from a subfolder.

**Needs one line wired by the integrator**, in the existing admin-api group in
`routes/web.php` — the one that already carries `auth:admin` and
`NoStoreAdminApi` — directly beneath the file it belongs with:

```php
require __DIR__.'/import-admin.php';
require __DIR__.'/import-articles-admin.php';
require __DIR__.'/import-articles-page.php';   // <- Lane U3
```

and one line in `resources/views/admin/app.blade.php`, which this lane does not
own, to put a card on the Import screen beside `gfHistoryCard()` and
`gdLiveProgressCard()`. Suggested, in their idiom:

```js
function u3ArticleAddressCard(){
  return '<div class="card pad">'
    +'<b style="font-size:14px">Articles this shop cannot serve</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:680px">'
    +'Every live article whose address the storefront itself already answers — /about/, /wishlist/, '
    +'/feed/ — with the URL it is indexed at today. Each one is a rename and a redirect in WordPress, '
    +'before you export again. Opening it writes nothing.</p>'
    +'<a href="'+impBase()+'/import/article-addresses-page" target="_blank" rel="noopener">'
    +'<button class="btn" style="margin-top:10px">Open the list</button></a></div>';
}
```

Until that card exists the page is reachable by URL and by nothing else. It is
the one part of this lane that could not be finished inside the files it owns.

`2027_01_03_000000_clear_caches_article_addresses_page.php` ships with the
route, per the convention in `CLAUDE.md`. The view cache matters here as much
as the route cache: the whole body of this route is a Blade template.

## Item 3 — what was already there, and what was not

`ReservedArticleReport` was built in full by Lane A and reads `posts.csv`
through `PostImporter::address()` — the importer's own decision, not a second
copy of `RESERVED_SLUGS`. It writes nothing. That part did not need rebuilding
and was not rebuilt.

Two things were missing.

### 1. Nothing could reach it

There was no screen. To find out which of his live articles this shop can never
serve the owner had to know the URL of an admin-api endpoint and read a JSON
body. The plan's wording is *"**Run a preview** — it writes nothing — and it
produces the list Lane GA asked the owner for"*, and a list is a thing you look
at. `resources/views/admin/article-addresses.blade.php` is that list: three
groups kept apart because the action differs, every row of each, with the
article's title, its WordPress slug, the address it wanted here, the live URL it
is indexed at, and what to do about it.

Server-rendered and standalone, for `MediaSideloadApiController::page()`'s two
reasons — no asset build step exists in this project, and a page read when a
migration is going wrong must not depend on the console bundle — plus one of its
own: `run()` reads a file once and answers, so there is nothing to poll and the
page needs no JavaScript to display itself. The only script is a substring
filter over rows already in the document.

### 2. The address it told him to redirect from could be the wrong one

This is the defect that would have cost something.

The report offered `wanted` — `/{slug}/` — and called it "the URL it wanted,
spelled the way the old site published it". It is not. It is **computed**, from
the slug, on the premise that articles live at the site root. That is true of
*this* shop; whether it is true of the old site depends on a WordPress
permalink setting this application cannot see. On `/blog/%postname%/` the row
said `/about/` while the address Google holds is
`https://kbeautybliss.com/blog/about/` — so the 301 the owner was told to write
would redirect an address nobody has ever requested, silently, on the one part
of a migration that cannot be redone after the old site is switched off.

So the live address is now **read, never derived**, from `permalinks.csv` —
WordPress's own `get_permalink()` for every row of the site, which `RedirectMap`
already calls the only source that can be right about a permalink structure this
application cannot see. It arrives in the export's "Addresses and pictures"
group and is already accepted by `ImportWorkspace` as a companion file.

| Column | What it is |
|---|---|
| `wanted` | the address the article asks for **on this shop** — computed, `/{slug}/`. It is what makes the row a collision. |
| `indexed_at` | the address **the old site published** — read from `permalinks.csv`, never derived. It is what the 301 is written **from**. |

Matched by WordPress id first and slug second (raw and percent-decoded), because
the id is what the exporter writes and a stale export is the realistic way the
two files disagree.

**When `permalinks.csv` has not been uploaded the column is empty and the page
says which file fills it.** A blank cell reads as "this article is not indexed",
which is the opposite of what it means — the same argument the report already
makes about a missing `posts.csv`.

A permalink that is not `http`/`https`, or carries no host, is dropped rather
than carried: the file is an upload and the page turns this value into an
`href`. The check is in `ReservedArticleReport::linkable()`, at the source, so
all three renderings inherit it rather than each template remembering.

The spreadsheet gains the column **appended**, as `live url (from
permalinks.csv)`, so every column already being read keeps its position.

## Item 2 — journal images, and the srcset decision

`MediaRewrite::COLUMNS` carries `posts.cover` and `DocumentMediaRewrite` rewrites
the `<img src>` and `<a href>` inside `posts.body`, idempotently — the host
filter plus the whole-value match — and refuses to re-point anything whose file
is not already under the web root (`ABSENT`). All of that was Lane A's and all of
it holds; `ImportRepointsWhatItFetchesTest` (25 tests) covers it and is green.

The open question this lane was asked to settle explicitly was **`srcset`**.

**Decision: not parsed, because nothing gets here to parse** — and the comment in
`DocumentMediaRewrite::TAGS` that said so has been corrected, because its
reasoning was wrong in a way that mattered. It claimed a stray `srcset` "will
read as MISSING on the audit". It would not: the audit reads through
`DocumentMediaRewrite::sources()`, the same list, so an `<img>` carrying both a
`src` and a `srcset` would have its `src` re-pointed, its `srcset` left naming
the old host, and the migration would report **remote → 0** while every retina
reader still loaded the article's pictures from a site about to go dark. That is
exactly the silent half-success the `<a href>` work was done to close, so "we do
not handle it" would not have been an answer.

It cannot arise. `RichText::ALLOWED['img']` is `src, alt, width, height, class,
loading`, and `RichText::attributes()` removes every attribute not on that list;
`source` is in `DROP_WHOLE` and `picture` is not an allowed element. **Both**
doors into `posts.body` run that one call — `PostImporter::settleBody()` on
import and `PostEditorApiController` on a hand-written article — so the
attribute is destroyed at the door.

`tests/Feature/ImportJournalSrcsetTest.php` is the tripwire on that premise. The
day `RichText` allows `srcset` — a reasonable thing for a later lane to want —
the carve-out becomes a real gap on the same commit, and those tests go red with
a message saying what to do about it. Proved by mutation: adding `'srcset'` to
`ALLOWED['img']` turns two of the three red.

`<a href>` was **not** touched. Lane A already takes an anchor that names a file
under an uploads root and deliberately leaves one that names a page, which is a
`RedirectMap` question. Duplicating or fighting that would have been the wrong
move; the reasoning in `sources()` is sound and is left alone.

## Measured, at 390px and 1280px

`node tools/u3-measure.cjs <url>`, which prints these and lists any element
whose content is wider than its box.

| | 390px | 1280px |
|---|---|---|
| `document.documentElement.clientWidth` | 390 | 1280 |
| `document.documentElement.scrollWidth` | **390** | **1280** |
| `scrollHeight` (11 articles, 9 listed) | 4521 | 2688 |
| `h1` font size | 21px | 21px |
| body font size | 14px | 14px |
| Download button | 287 × 38 | 287 × 38 |
| elements overflowing their box | none | none |

The first measurement was **543 at a 390px viewport**, and the cause is worth
recording because a rect-based probe finds nothing: no element's bounding box
exceeded 391px. The overflow was one unbreakable token — a percent-encoded
Arabic permalink — inside a paragraph whose box was already the right width.
`overflow-wrap:anywhere`, in CSS, at render time. Nothing measures layout in
JavaScript.

Under 720px the table stops being a table and each row becomes a labelled card,
by media query alone. Shots in `docs/u3-article-address-shots/`.

## Found and not fixed

**A `<picture>` block loses its `<img>` entirely on import.** `app/Support/
RichText.php` is not this lane's file, so this is pinned rather than fixed, in
`ImportJournalSrcsetTest`. libxml's HTML parser is HTML4 and does not know
`source` is a void element, so everything after `<source …>` is parsed as its
*child* — and `DROP_WHOLE` then removes the subtree with the `<img>` in it. The
only valid ordering inside `<picture>` is `<source>` before `<img>`, so such a
block arrives as nothing at all and the article silently loses the photograph.
`<picture>` with no `<source>` keeps its `<img>`, which is what isolates the
cause to the void-element parse rather than to `picture` being unknown. It does
not weaken the srcset argument in either direction — no address survives either
way, which is the safe direction — but it is silent media loss on import and
belongs to whoever owns `RichText`.
