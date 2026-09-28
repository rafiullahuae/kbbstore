# The address scheme · what moved, why, and where every old address lands

Lane URL. Written 28 September 2026. It supersedes the "This shop" column of
`docs/SEO-URL-MAP.md` for the three families that moved; everything that
document says about what the OLD site served is unchanged and is still the
evidence this rests on.

---

## 1. The scheme

**Plural for a listing page, singular for a detail page.** That is the split
Google's own ecommerce URL guidance and the singular/plural search-intent
studies agree on: a shopper searching a category types the plural, a shopper
searching one item types the singular.

| Today | Becomes | Why |
|---|---|---|
| `/product-category/{path}/` | **`/collections/{path}/`** | a listing page → plural |
| *(nothing — a query string on `/shop/`)* | **`/brands/` and `/brands/{slug}/`** | a listing page → plural, and it did not exist |
| `/product/{slug}/` | **unchanged** | a detail page → singular is already right |
| `/skincare-guide/` + articles at the site root | **`/blog/` and `/blog/{slug}/`** | closes an open defect, §4 |

`App\Support\UrlScheme` is the one place any of those shapes is written down.
Ten different files used to spell `/product-category/` out; a scheme change then
has ten places to miss, and the one it misses is a canonical pointing at an
address the shop redirects away from — which is worse than not moving at all.

**The product catalogue does not move**, and that is the point of the research
rather than a convenience. It is the largest and most valuable set of addresses
this shop owns, and it needs no redirect at all. Sets are products and live
there too — no `/set/` prefix, because a set converted to a simple product would
otherwise have to change address for an internal bookkeeping change.

---

## 2. Every old address, and the ONE hop it costs

Measured against a running preview (`tools/url-preview.sh`), not asserted:

| Asked for | Status | Landed on | Hops |
|---|---|---|---|
| `/product-category/toners/` | 301 | `/collections/skincare/toners/` | 1 |
| `/toners/` (the flat WordPress form) | 301 | `/collections/skincare/toners/` | 1 |
| `/korean-skincare-brands/` | 301 | `/brands/` | 1 |
| `/korean-skincare-brands/round-lab/` | 301 | `/brands/round-lab/` | 1 |
| `/brand/round-lab/` | 301 | `/brands/round-lab/` | 1 |
| `/skincare-guide/` | 301 | `/blog/` | 1 |
| `/skincare-guide/{slug}/` | 301 | `/blog/{slug}/` | 1 |
| `/post/{slug}` | 301 | `/blog/{slug}/` | 1 |
| `/{slug}/` (the article's WordPress address) | 301 | `/blog/{slug}/` | 1 |
| `/product/dokdo-toner/` | 200 | — | 0 |

**One move, never two**, and the place it is easiest to get wrong is the
category. Pointing `/product-category/toners/` at `/collections/toners/` looks
right and chains: the archive would then 301 again onto the canonical nested
path, because `toners` has a parent. `CategoryArchiveController::show()`
RESOLVES the path first, so the destination is the final one.

A retired address that names nothing 404s rather than redirecting.
`/product-category/nothing-like-this/`, `/brand/no-such-brand/` and
`/no-such-article/` are all 404s — a blanket 301 from a retired base to its
replacement turns the whole old namespace into an unbounded space of soft 404s
wearing a 301.

---

## 3. What the import writes, and what it deliberately does not

Every retired address above is answered by a ROUTE, so the shop makes the hop
itself and follows a rename while doing it. `RedirectMap::reachable()` sees
that, compares the destination with its own, and puts the proposal in `discard`
with the reason on the row. This is the settled §13.7 decision, unchanged: a
written row restates a hop the application already makes, and unlike the
application's hop it rots — it goes on pointing at import-day's path after that
path has become a 404.

**The migrate bucket is therefore what no route can derive**, and that is the
whole of it:

- a category whose flat WordPress root address is not one of the fifteen in
  `LegacyCategoryUrls::PATHS` — on a real export, most of them;
- an article whose **slug changed on import** (a `SlugGuard` collision with a
  reserved slug is the real case) — the root address then names no published
  post, so `PageController::rootArticle()` 404s it and only a row can save it;
- a WordPress page;
- **every product, on a shop whose `product_base` was not `/product`.**

**Idempotent.** `diff()` compares each proposal against the stored row and
`updateOrCreate` keys on `source`, so a second run reports an empty `create` and
an empty `update` and writes nothing. Pinned in `UrlSchemeImportTest`.

**No chains.** Every migrate row's target is checked against the whole bucket's
sources, and against the three retired bases, so a rule that reached for a
literal instead of `UrlScheme` is a red test rather than a two-hop redirect.

---

## 4. `/blog/{slug}/` closes a defect, it does not merely rename one

An article used to be served at the site root, and
`PageController::RESERVED_SLUGS` owns the first segment there. A live article
slugged `about`, `feed`, `brands` or `blog` was an address this application is
structurally unable to serve: the import refuses those slugs and lists them for
the owner, and the list can only grow, because every route this shop ever adds
takes another word out of the article namespace.

Under `/blog/` the article namespace is the shop's own. The reserved list stops
eating into it, and the root form still 301s for everything Google holds.

---

## 5. `/brands/` is new work, not a rename

`Brand::url()` returned `/shop/?filter_brands={slug}` — a filtered listing,
which canonicalises to `/shop/` and is not an indexable page. So a brand archive
arriving from the old WordPress install was redirected onto a page that tells
Google the brand page does not exist, and "medicube uae" — exactly what people
type — had nothing to rank with.

`Brand::url()` is now the landing page. **URL Contract U-05 is untouched:** the
filterable, sortable, paginated product listing is still
`/shop/?filter_brands={slug}`, which moved to `Brand::filterUrl()` and is what
the mega menu, the shop's facets and the brand page's own "Shop all" button use.

The brand page costs **9 queries**, unchanged, and does not grow with the
brand's catalogue — `StorefrontQueryBudgetTest` measures it at the new address
against the same ceiling.

---

## 6. The two migrations, and the loop one of them prevents

`2027_04_05_000000_clear_caches_url_scheme` drops the compiled route table and
`CheckRedirects`' cached source index. Without it the owner applies the package
and every new address 404s while every old one goes on serving a page whose
canonical names an address the shop does not answer.

`..._000100_url_scheme_redirect_rows` is not tidying. `2026_09_14_160000_seed_
phase9_post_url_redirects` seeded ten rows shaped `/blog/{slug}/ → /{slug}/`,
from when `/blog/` was a dead prefix. `/blog/…` is the article's canonical
address now and `CheckRedirects` runs BEFORE the router, so the row sends the
canonical address to the site root and `rootArticle()` sends it straight back —
an infinite loop that `CheckRedirects::loops()` cannot see, because it walks the
TABLE and the second hop is a route. Five articles would have been unreachable
at their own address the moment the package applied.

The same migration collapses every stored row whose destination the scheme
moved, so nothing chains.

`..._000200_url_scheme_menu_items` repoints the seeded navigation. The header's
"Brands" item was seeded as `/korean-skincare-brands/`; left alone, every
visitor who clicks it pays a redirect on every page for ever.

---

## 7. The exporter needed nothing, and that was checked

`permalinks.csv` already carries the old address of every product, category,
post, page, tag, attribute term and brand, per row, as `get_permalink()` and
`get_term_link()` really answered it. `manifest.json` already carries
`permalink_structure` and `woocommerce_permalinks`, which is what makes the
product-base finding a reading rather than an assumption.

The scheme changed where this shop SERVES things. It changed nothing about what
the old site served, and a permalink export is a record of the second. No
version bump, no CHANGELOG entry. `UrlSchemeExporterUnchangedTest` asserts the
columns and the manifest keys, so "it already covers it" is checked rather than
claimed.
