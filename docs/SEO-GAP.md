# SEO gap analysis — us vs Shopify vs kbeautyarabia.com

Lane S, 2026-09-24. Companion to `docs/SEO-COMPETITIVE.md`.

Every "ours" cell below names the file and line that proves it. I read the whole
surface before writing this: `app/Support/Seo.php` (1,680 lines),
`ProductSeo.php`, `YoastSeo.php`, `YoastTiers.php`, `Indexability.php`,
`Locale.php`, `Store\SeoFilesController`, `Services/Seo/SeoSettings.php`,
`Services/Import/Entities/SeoImporter.php`, and the docs listed in the brief.

**"Theirs" is what §3 of SEO-COMPETITIVE could OBSERVE.** Where egress blocked
it, the cell reads *unverified* and that is not a euphemism for "they don't have
it" — it means I did not look and neither should anyone building from this.

---

## The headline

**This shop's SEO module is not behind Shopify. On structured data it is well
ahead of a default Shopify theme.** The three-column table below has far more
"we have it, they don't" rows than the reverse.

The competitor is not out-ranking us on markup. Read `SEO-COMPETITIVE.md` §4.

---

## 1. Crawling and URLs

| Capability | Ours | Shopify | Theirs |
|---|---|---|---|
| One canonical path per product | **Have.** `/product/{slug}/`, single address, slashless form 301s onto it (`routes/web.php`) | **Structural weakness** — `/products/x` and `/collections/y/products/x` both serve; theme cards link the scoped form against the canonical | `/products/<handle>` observed; whether cards link `within: collection` is *unverified* |
| robots.txt | **Have**, generated, locale- and base-path aware, `SeoFilesController::robots()` (`:617`) | Generated; overridable via `robots.txt.liquid` | *unverified* |
| robots.txt agrees with page-level noindex | **Have, and enforced.** `Indexability::PRIVATE_PREFIXES` vs `SeoFilesController::ROBOTS_PRIVATE` (`:24`), with `SeoBilingualTest` asserting the two sets are equal | Disallows `/cart` `/checkout`; no matching `noindex` | *unverified* |
| Private/staging handling | **Have, and better reasoned.** `SiteHost::isPrivate()` ⇒ invite the crawl, answer `X-Robots-Tag: noindex` — because a disallowed URL is one whose noindex is never read (`:617` comment) | n/a (password page) | n/a |
| Redirect management | **Have.** Store → SEO & Meta → Redirects & 404s; `2026_10_05_000000_add_category_seo_and_redirects` | `/admin/redirects` + CSV | *unverified* |
| IndexNow | **Have.** `/{key}.txt` (`SeoFilesController::indexNowKeyFile`, `:513`), `Services\Seo\IndexNow` | App-only | *unverified* |
| `llms.txt` | **Have** (`:544`). Neither platform has this. | No | No |

**Net: we are ahead**, decisively on the duplicate-path issue.

---

## 2. sitemap.xml

| Capability | Ours | Shopify | Theirs |
|---|---|---|---|
| Automatic sitemap | **Have.** `SeoFilesController::sitemap()` (`:75`) | Yes | Yes (assumed from platform) |
| Products / categories / brands / articles / pages | **Have**, all five, each visibility-filtered | products / collections / pages / blogs | *unverified* |
| Excludes `noindex` rows | **Have**, for products, categories and brands (`isNoindex()`, `:65`) | **No** — Shopify's sitemap is not aware of per-row noindex | *unverified* |
| Excludes scheduled/unpublished | **Have.** `ProductVisibility::raw()` | Yes | — |
| Excludes thin pages | **Have.** `/reviews/` listed only when a real approved review exists | No | — |
| `lastmod` | **Have**, from `updated_at`, on every dynamic entry | Yes | — |
| hreflang alternates in the sitemap | **Have.** `xhtml:link` per cluster, `cluster()` (`:456`) | Via Markets | *unverified* |
| Sitemap **index** + sub-sitemaps | **Missing** — one flat file | **Have** (`sitemap_products_1.xml` …), splits ~5,000 | Have (platform) |
| **Product images** (`<image:image>`) | **NEW THIS LANE** — whole gallery, de-duplicated, off by default | **Partial** — featured image only | *unverified* |
| Chunked/bounded memory build | **Partly.** Products are one `->get()` of the whole visible set into an array of arrays. Fine at 671 rows; see §7 | n/a (platform) | n/a |

**Net: we were at parity and are now ahead on images; behind on the index
split, which does not matter yet.** See `SEO-BUILD-PLAN.md` for why the split is
ranked low.

---

## 3. Structured data (JSON-LD)

This is the section that most contradicts the brief's assumptions. All of it is
`Seo::jsonLd()`, `app/Support/Seo.php:825`, encoded once by `encodeJsonLd()`
(`:270`).

| Node | Ours | Shopify | Theirs |
|---|---|---|---|
| `Organization` (+`logo`, `sameAs`) | **Have** (`:830`) | Theme/app | *unverified* |
| `WebSite` + `SearchAction` | **Have** (`:858`), and the `urlTemplate` is the parameter the shop actually filters on (`/shop/?s=`) — a bug already found and fixed here | Theme | *unverified* |
| `Product` | **Have** (`:893`) | Theme | *unverified* |
| `Offer` + `priceCurrency` + `availability` | **Have** (`:968`) | Theme | *unverified* |
| `itemCondition` | **Have** (`:983`) | Rare | *unverified* |
| **`valueAddedTaxIncluded`** on a `UnitPriceSpecification` | **Have** (`:1009`), driven by `TaxRule::addsToTotal()` | **Almost never** | *unverified* |
| `priceValidUntil`, only from a live sale window | **Have** (`:1030`) — and deliberately absent otherwise, because a stale one drops the price from the result | Often a naive +1 year | *unverified* |
| `gtin` (check-digit validated) | **Have** (`:950`), `Gtin::isValid()` | Via `barcode` | *unverified* |
| `sku` | **Have** | Yes | *unverified* |
| Multiple `image`s per product | **Have** (`:905`) | Featured only, typically | *unverified* |
| `AggregateRating` from real approved reviews | **Have** (`:1128`), both halves checked | **App** (Judge.me/Loox/…) | *unverified* |
| `shippingDetails` / `hasMerchantReturnPolicy` | **Have** (`:1061`), behind `enable_merchant`, off by default, and refuses to invent `returnMethod`/`returnFees` | App | *unverified* |
| Variant-level offers | **Have** (`:1100`ff) | Theme-dependent | *unverified* |
| `BreadcrumbList` | **Have** (`:1349`) | Theme | *unverified* |
| `CollectionPage` + `ItemList` | **Have** (`:1222`) | Rare | *unverified* |
| `Article` | **Have** (`:1145`) | Theme | *unverified* |
| **One coherent graph, one encoder** | **Have** — structurally cannot emit two competing `Product` nodes | **The classic Shopify failure**: review app injects a second `Product` | *unverified* |
| `FAQPage` | **Missing** | Theme/app | *unverified* |
| `LocalBusiness` address / hours / geo | **Missing** (`org_type` offers the type; no address is emitted) | App | They have a Dubai shop (observed) |
| `VideoObject`, `HowTo` | Missing | Missing | — |

**Net: we are substantially ahead.** `FAQPage` is a dead feature (Google
deprecated the rich result on 7 May 2026 — see SEO-COMPETITIVE §1.9) and should
not be built. `LocalBusiness` is the one genuine gap in this table.

### Escaping

`encodeJsonLd()` uses `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`
and its comment records the real incident: `JSON_UNESCAPED_SLASHES` was on, and
an `org_name` of `</script><script>alert(1)</script>` executed on every page.
**Note for the brief:** it asks for `JSON_UNESCAPED_SLASHES` in the flag set.
That is exactly the flag that caused the bug. The current flags are correct and
must not be "corrected" back.

---

## 4. Meta tags, Open Graph, hreflang

| Capability | Ours | Shopify | Theirs |
|---|---|---|---|
| Title template with tokens | **Have.** `Seo::titleOf()` (`:380`), `{title}{sep}{sitename}{page}` | Theme + per-row override | Observed: mechanical `{collection} Beauty Products`, unproofread |
| Per-row SEO title/description | **Have.** `seo` json on products, categories, brands, posts, pages; `ProductSeo::PUBLISHED_KEYS` | `global.title_tag` / `description_tag` | Presumed (platform) |
| Per-row **canonical** override | **Have** | **No** | No |
| Per-row **noindex** | **Have** | No (app) | No |
| `meta robots` | **Have** (`:145`) | Theme | *unverified* |
| Canonical, absolute, base-path aware | **Have.** `Seo::canonical()` (`:511`) | Yes | *unverified* |
| Open Graph | **Have** (`:176`ff) | Theme | *unverified* |
| `product:price:amount` / `availability` | **Have** (`:188`) | Rare | *unverified* |
| Twitter card | **Have** (`:211`) | Theme | *unverified* |
| **hreflang + `x-default`, reciprocal** | **Have.** `Seo::alternateLinks()` (`:591`); trailing slash matched to the canonical so no alternate points at a redirect | Markets, automatic | **Unverified — every URL I found is English.** Possibly their biggest weakness; unconfirmed |
| Verification tags (Google/Bing/Pinterest/Baidu) | **Have**, all four (`:168`), driven off `SETTING_RULES` by `SeoVerificationTagsTest` | Google/Bing only | *unverified* |
| Yoast import (tiers, leftovers) | **Have.** `YoastSeo.php`, `YoastTiers.php`, `SeoImporter`, `docs/FX-YOAST-TIER-CENSUS.md` | n/a | n/a |

**Net: ahead**, with hreflang at parity.

---

## 5. Auditing and tooling

| Capability | Ours | Shopify | Theirs |
|---|---|---|---|
| Schema Inspector | **Have.** Store → SEO & Meta → Schema Inspector | No | — |
| Catalogue Audit (3 product checks) | **Have.** `CatalogueAuditApiController` | No | — |
| **Whole-surface SEO Audit** | **NEW THIS LANE.** `App\Support\SeoAudit` — duplicate titles, duplicate descriptions, unsafe canonicals, title length, missing/thin descriptions, missing images, products with no identifier, orphan products; across products, categories, brands, articles and pages | No | — |
| Storefront health check | **Have.** `HealthApiController` | No | — |

**Net: far ahead.** Shopify merchants buy a third-party app for a fraction of
this.

---

## 6. Content and taxonomy — **where we are genuinely behind**

| Capability | Ours | Theirs |
|---|---|---|
| Concern-led collections (`/acne`, `/pigmentation`, `/dryness`) | **Missing.** Our listings are merchandising (`new-in`, `best-sellers`, `super-sale`, `everything-under-54-aed`); categories are a product taxonomy | **Observed:** `/collections/acne` |
| Brand landing pages | **Have**, 93 of them, `/korean-skincare-brands/{slug}/`, sitemap-listed | `/collections/<brand>` |
| Ingredient/concern editorial mapped onto collections | **Partly.** `/skincare-guide/` and the journal exist; the topical mapping does not | **Observed:** a sustained blog on routines, acne scars, Cica/Heartleaf, moisturiser selection |
| Local editorial citations | Unknown | **Observed:** Time Out Dubai |

**This is the real gap and none of it is an SEO-module feature.** It is
taxonomy, copy and outreach.

---

## 7. Performance

- `StorefrontQueryBudgetTest` pins `/sitemap.xml` at **24 queries** (measured
  20, budgeted 24 — the file's own comment records that twelve of eighteen were
  schema introspection). Green after this lane's change; the image columns ride
  on the query that already runs.
- `SeoFilesController::sitemap()` builds the **whole file in one string** and
  holds every URL in one array first. At 671 products × 2 locales ≈ 1,400
  `<url>` elements this is trivial. At the 4,000-row figure in the brief it is
  roughly 8,000 elements and still fine; it becomes worth changing somewhere
  past ~20,000. **Ranked low deliberately** — see the build plan.
- `SeoAudit` chunks products at 500 and keeps counts rather than rows, so its
  working set is bounded by *distinct titles*, not by catalogue size.
- No JavaScript measures layout anywhere in this lane's work.

---

## 8. Summary scorecard

| Area | Verdict |
|---|---|
| URLs / canonicals | **Ahead** — we lack Shopify's duplicate-path defect entirely |
| robots.txt | **Ahead** — ours agrees with page-level noindex, theirs cannot |
| sitemap.xml | **Ahead** on filtering and hreflang; **behind** on the index split (does not matter at this size); **ahead** on images as of this lane |
| Structured data | **Well ahead** of a default Shopify theme |
| Meta / OG / hreflang | **Ahead** |
| Auditing | **Far ahead** |
| **Content & taxonomy** | **Behind — and this is why they out-rank us** |
| Off-site authority | **Behind**, and outside this module |

Two genuine code gaps remain: **`LocalBusiness` markup** and the **sitemap index
split**. Everything else on the brief's wishlist either already exists or should
not be built.
