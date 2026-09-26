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
| One canonical path per product | **Have** — one canonical address, `/product/{slug}/`. **Not by a redirect; see the note below.** | **Structural weakness** — `/products/x` and `/collections/y/products/x` both serve; theme cards link the scoped form against the canonical | `/products/<handle>` observed; whether cards link `within: collection` is *unverified* |
| robots.txt | **Have**, generated, locale- and base-path aware, `SeoFilesController::robots()` (`:617`) | Generated; overridable via `robots.txt.liquid` | *unverified* |
| robots.txt agrees with page-level noindex | **Have, and enforced.** `Indexability::PRIVATE_PREFIXES` vs `SeoFilesController::ROBOTS_PRIVATE` (`:24`), with `SeoBilingualTest` asserting the two sets are equal | Disallows `/cart` `/checkout`; no matching `noindex` | *unverified* |
| Private/staging handling | **Have, and better reasoned.** `SiteHost::isPrivate()` ⇒ invite the crawl, answer `X-Robots-Tag: noindex` — because a disallowed URL is one whose noindex is never read (`:617` comment) | n/a (password page) | n/a |
| Redirect management | **Have.** Store → SEO & Meta → Redirects & 404s; `2026_10_05_000000_add_category_seo_and_redirects` | `/admin/redirects` + CSV | *unverified* |
| IndexNow | **Have.** `/{key}.txt` (`SeoFilesController::indexNowKeyFile`, `:513`), `Services\Seo\IndexNow` | App-only | *unverified* |
| `llms.txt` | **Have** (`:544`). Neither platform has this. | No | No |

**Net: we are ahead**, decisively on the duplicate-path issue.

### CORRECTION (Lane S8, 2026-09-26) — there is no 301, and the mechanism matters

This table said the slashless form *"301s onto it"*. **It does not.** Driven by
request against the running application:

```
GET /product/probe-prod   ->  200   canonical https://kb.test/product/probe-prod/
GET /product/probe-prod/  ->  200   canonical https://kb.test/product/probe-prod/
GET /shop                 ->  200   canonical https://kb.test/shop/
GET /shop/                ->  200   canonical https://kb.test/shop/
GET /cart                 ->  200   canonical https://kb.test/cart/
GET /cart/                ->  200   canonical https://kb.test/cart/
```

**Both forms answer 200 and neither redirects.** Laravel's router rtrims the
path before matching, so `/product/x` and `/product/x/` are the same route to
the same controller — there is nothing left for a redirect to redirect.

**The outcome this table claims is nevertheless correct, and the reason is the
canonical, not a redirect.** Both addresses declare the *same* `rel=canonical`
(the slashed form), and that is what consolidates them: Google follows the
canonical and indexes one address. So there is **nothing to build here** — this
is a documentation defect only, and it is written down because the named
mechanism is the thing a later lane would go looking for, fail to find, and
"fix" by adding a redirect that would then fire on a URL the site itself
advertises.

Pinned, not asserted in prose: `SeoPreviewsTest` drives all six requests above
and computes the verdict from the statuses and the canonicals it gets back, so
the day a redirect *is* added — or the day the two canonicals stop agreeing —
this file's claim goes red rather than going stale again.

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
| `FAQPage` | **BUILT, ships OFF** (round 5, `Services\Seo\FaqSchema`). Not for the retired rich result — for the machine-readable question/answer pair. Store → SEO & Meta → Settings · Sitemap & robots · "FAQ markup on content pages" | Theme/app | *unverified* |
| `LocalBusiness` address / hours / geo | **BUILT** (round 2, `Support\BusinessAddress`). `address`, `telephone`, `geo` and `openingHoursSpecification` merge into the one Organization node, with `geo`/hours gated to the two `org_type` values that are schema.org Places. **Nothing is published on this shop and that is correct** — it trades online only, with no shopfront | App | They have a Dubai shop (observed) |
| `VideoObject`, `HowTo` | Missing | Missing | — |

**Net: we are substantially ahead.**

**CORRECTION (Lane S8, 2026-09-26).** This paragraph used to read *"`FAQPage` is
a dead feature and should not be built. `LocalBusiness` is the one genuine gap in
this table."* Both halves are now out of date and the second was already out of
date when it was written:

- `LocalBusiness` **shipped in round 2** — five months before this sentence was
  last read — and it is not a gap on this shop at all. The owner has since
  stated it in his own words: *"we are open 24/7, we don't have any physical
  shop, we operate only online."* A shop with no premises has no `LocalBusiness`
  markup to publish, and `org_type` now ships at `OnlineStore` to say so.
- `FAQPage` **was built in round 5 and ships off**, on an argument the three
  previous refusals did not weigh. The refusal was right about the rich result —
  Google stopped showing FAQ drop-downs on 7 May 2026 and nobody should be
  promised one. What it did not weigh is the question/answer *pair* as the unit
  an answer engine extracts, which an `<h3>` above a `<p>` is not. Read
  `SEO-ROUND-5-VERIFICATION.md` §4 before re-arguing it in either direction.

The remaining genuine absences in this table are `VideoObject` (now worth
something it was not, because the shop has a shoppable-video library) and
`HowTo`, which stays refused.

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
| Per-row SEO title/description | **Have on four of five.** `seo` json is on products, categories, brands, posts **and pages**, and all five are now READ — but only four can be EDITED. `pages` has no editor anywhere in the console. See the note below. | `global.title_tag` / `description_tag` | Presumed (platform) |
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

### CORRECTION (Lane S8, 2026-09-26) — `pages` has the column and no editor

This table said *"Per-row SEO title/description — Have. `seo` json on products,
categories, brands, posts, pages."* **True of the column, false of the
capability**, and the false half is the half an owner would act on: a finding
raised against a content page is not actionable anywhere in this software.

Where it actually stands, driven rather than read:

| Table | `seo` column | Read by the storefront | **Editable in the console** | Where |
|---|---|---|---|---|
| `products` | yes | yes | **yes** | Catalog → Products → the product → SEO |
| `categories` | yes | yes | **yes** | Catalog → Categories → the category |
| `brands` | yes | yes | **yes** | Catalog → Brands → the brand |
| `posts` | yes | yes | **yes** | Content → Blog Posts → the post |
| `pages` | yes | **yes, since round 5** | **NO — nothing can write it** | — |

The evidence for the last row is the absence of a writer, so it is stated as an
absence that was searched for rather than as an opinion:

- `Admin\PagesApiController` is the only Pages endpoint and it has exactly two
  methods, `store()` and `user()`, both `GET`, both listing. It selects
  `id, slug, title, status, updated_at` — **it does not even return the `seo`
  column**, so no screen could render a form over it.
- `routes/web.php` registers `GET /pages/store` and `GET /pages/user` and no
  other pages endpoint. There is no create, update or delete.
- `Services\Import\Entities\SeoImporter` writes `seo` on **products only**
  (`$context->apply($product, ['seo' => $merged])`). The WooCommerce import does
  not carry a Yoast override onto a content page.
- `grep -rn "'seo'" app/Http/Controllers/Admin/` returns product, category,
  brand and post writers, and nothing for `Page`.

**So `pages.seo` is `null` on every shipped row and there is no path by which it
could stop being null.** Round 5 was right to make the storefront read it — the
column being read by nothing was its own defect, and the audit was reporting
pages as deindexed on the strength of a value no screen could set — but reading
a column nothing writes buys the owner nothing on its own.

**What is actually missing is a page editor**, and it is a Content-module item
rather than an SEO one: a page needs a title, a body and a status editor before
it needs a meta description, and this shop has none of the four. Until it
exists, the honest statement of this capability is *"four of five tables"*.

Do not close this by adding an SEO-only form for pages. A five-field SEO panel
on a screen with no way to edit the page's own title or body would be the
strangest control in the console.

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
| Concern-led collections | **BUILT** (round 2, mounted; all eight enabled as of Lane S8). `/concern/{slug}/` for every concern in `Support\RoutineConcerns`, each with its own English copy, `CollectionPage` + `ItemList`, its own canonical and a sitemap entry. **A page does not exist until `MIN_PRODUCTS` products are tagged for it**, so all eight are 404 today — the one thing still outstanding is the owner's tagging at Catalog → Build my routine | **Observed:** `/collections/acne` |
| Brand landing pages | **Have**, 93 of them, `/korean-skincare-brands/{slug}/`, sitemap-listed | `/collections/<brand>` |
| Ingredient/concern editorial mapped onto collections | **Partly.** `/skincare-guide/` and the journal exist; the topical mapping does not | **Observed:** a sustained blog on routines, acne scars, Cica/Heartleaf, moisturiser selection |
| Local editorial citations | Unknown | **Observed:** Time Out Dubai |

**This is the real gap and almost none of it is an SEO-module feature.** It is
taxonomy, copy and outreach.

**CORRECTION (Lane S8).** The first row said Missing. The pages and all eight
sets of copy are built and merged; what is missing is the product tagging, which
is the owner's two or three hours at Catalog → Build my routine. That is a
genuinely different kind of "missing" and the distinction is the whole point of
this table: nobody needs to build anything for concern collections.

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

---

# Part II — the content gap, specified

Lane S2, 2026-09-24. Part I established *that* the gap is content and taxonomy.
This part establishes *what closing it actually requires*, because "write a blog"
is not a specification anyone can build or budget.

Sourcing rule is unchanged: **OBSERVED** with a URL, or **UNVERIFIED**.

---

## 9. Correction to Part I §4: hreflang is not a gap in our favour any more

Part I's row reads: *"hreflang + `x-default` … **Theirs: Unverified — every URL I
found is English. Possibly their biggest weakness.**"*

`SEO-COMPETITIVE.md` §8 disproves it. They run `ar.kbeautyarabia.com` with
Arabic products, collections and translated articles. **Replace that cell with:**

| Capability | Ours | Theirs |
|---|---|---|
| Arabic layer exists | **Have**, `/ar/` | **Have**, `ar.` subdomain — OBSERVED |
| Arabic URL shape | **`/ar/` subfolder**, shares domain authority | **`ar.` subdomain** — weaker on this point, per Shopify's own guidance ([help.shopify.com](https://help.shopify.com/en/manual/markets/seo)) |
| hreflang automatic on that shape? | n/a — ours is hand-built and tested (`SeoBilingualTest`) | **Subdomains are the branch where Shopify does NOT emit hreflang automatically** ([help.shopify.com](https://help.shopify.com/en/manual/markets/customizations/domains-and-languages)) — so theirs is manual or an app. **UNVERIFIED whether it is correct.** |
| Arabic slugs in the URL | **Possible for us** — our routing is ours | **Structurally impossible** — `/collections/`, `/blogs/` fixed; their live Arabic article URL carries the English handle — OBSERVED |
| Arabic *editorial* | **Missing** — see §12 | **Have** — translated articles OBSERVED |

**Net: parity on existence, ahead on URL shape, behind on Arabic content.**
The last row is the one that matters and it is not a code gap either.

---

## 10. What a UAE K-beauty shopper actually searches for

The brief asked for evidence rather than plausibility. Here is what is citable,
and here is where the evidence stops.

### OBSERVED — concerns and ingredients with named demand

| Concern / theme | Evidence |
|---|---|
| **Glass skin / hydration** | "Korean-inspired *glass-skin* facials … rising demand across Dubai through 2026" ([globenewswire](https://www.globenewswire.com/news-release/2026/08/12/3343355/0/en/rising-demand-in-dubai-for-korean-glass-skin-treatment-and-korean-facials-as-diagnostic-led-skin-health-gains-ground-in-2026-with-treatments-from-aed-650-at-aire-md-by-casa-aire-we.html)); a UAE retailer's 2026 guide is built entirely around the term ([glamsecret.ae](https://glamsecret.ae/blogs/buy-korean-skincare-in-uae-the-ultimate-2026-guide-to-glass-skin/buy-korean-skincare-in-uae-the-ultimate-2026-guide-to-glass-skin)) |
| **Acne + barrier repair** | COSRX named as among the most-searched K-beauty brands in the UAE, on "gentle acne care, snail mucin hydration, and skin barrier repair" ([dubaiwholesalestore.com](https://www.dubaiwholesalestore.com/blogs/news/korean-skincare-trends-to-watch-in-2026-insights-from-2025-sales)) |
| **Sensitivity / redness** | Anua named a top brand for sensitive skin and calming redness; "Heartleaf collection extremely popular in Dubai and GCC" (same source) |
| **Pores / anti-ageing / PDRN** | Medicube named as viral in the UAE for "pore care, collagen products, PDRN skincare, and anti-aging routines" (same source) |
| **Cica / Centella / Heartleaf** | Named as the go-to calming ingredients, with "huge wholesale demand" for Anua Heartleaf 77 Toner (same source); the competitor's own 2024 trends article leads on Cica and Heartleaf ([kbeautyarabia](https://kbeautyarabia.com/blogs/k-beauty-blog/trending-now-the-hottest-korean-skincare-products-of-2024)) |
| **Snail mucin** | Recurs across K-beauty ingredient coverage as a lead ingredient term ([ulta.com](https://www.ulta.com/discover/beauty-education/what-is-snail-mucin), [skinsider.co.uk](https://skinsider.co.uk/blog/everything-you-need-to-know-about-snail-mucin-the-kbeauty-skincare-wonder/)) |
| **Market is growing** | UAE Korean-cosmetics market ~USD 190.5m (2020) → ~USD 350m by 2026, ~8% CAGR ([thinkpositive.ae](https://thinkpositive.ae/consumer-insights-for-skincare-brands-in-the-uae/)) |
| **Regional trend context** | Beautyworld Middle East 2025 trend reporting ([beautymatter.com](https://beautymatter.com/articles/top-6-trends-spotted-at-bwme-2025)); Middle East beauty trend analysis ([greyb.com](https://greyb.com/blog/middle-east-beauty-trends)) |

### The independent cross-check

The competitor's taxonomy was built by people with their own analytics, and it
lands on **acne, anti-ageing, hyperpigmentation**, plus a stated seven-concern
nav set (`SEO-COMPETITIVE.md` §9.2). Two independent sources — trade/press
coverage and a competitor's revealed merchandising — agree on acne,
pigmentation, sensitivity/redness, pores and anti-ageing. **That agreement is
the strongest evidence available without a keyword tool**, and it is what the
concern list in the build plan is built from.

### UNVERIFIED — and this is a real limit, not a hedge

**I have no search-volume figures for any of these terms, in either language.**
None. Search volume needs Keyword Planner, Ahrefs, Semrush or Search Console,
and this project has none of them for any domain. Everything above is *evidence
that a term has commercial attention in this market*; **none of it is evidence
of how many people type it per month, or of how hard it is to rank for.**

So: the concern list is well-founded for *choosing which pages to build*. It is
**not** a basis for promising traffic numbers, and nobody should write a
forecast off it. If the owner wants volumes, the cheapest honest route is
**Google Search Console on kbeautybliss.com**, which he already owns and which
gives real impressions for terms the site already surfaces on.

---

## 11. What a collection page needs in order to rank rather than be thin

Part I ranked concern collections first and flagged "thin pages" as the risk.
This is what the risk actually is.

### The consensus, and its honest status

- *"The most common reason category pages don't rank is that they contain no
  rankable content. Google cannot determine buyer intent, keyword relevance, or
  topical authority from a grid alone."*
  ([aiadvantageagency.com](https://aiadvantageagency.com/ecommerce-category-page-seo/),
  [wpconsults.com](https://www.wpconsults.com/category-page-seo-content/))
- *"Do not leave the page as a basic price list; add value with helpful text,
  buying tips, or FAQs."*
  ([digitalapplied.com](https://www.digitalapplied.com/blog/ecommerce-seo-product-category-page-guide-2026))
- Scrutiny of ecommerce category pages has increased since early 2023; thin,
  duplicated or bulk-generated category copy is treated worse, structured and
  genuinely informative copy better.
  ([megantic.com.au](https://www.megantic.com.au/blog/google-algorithm-impacting-category-pages/))
- Word-count thresholds circulate — "below 200 words is often classified as
  thin", "150–300", "200–400" — and **the sources that quote them also say no
  published measurement sits behind any of it.**
  ([1digitalagency.com](https://www.1digitalagency.com/blog/how-to-optimize-e-commerce-category-pages-a-2026-playbook/),
  [keytomic.com](https://keytomic.com/blog/ecommerce-category-page-seo-best-practices))

**Status: OBSERVED as industry consensus; UNVERIFIED as Google's own
documentation.** I did not find a Google Search Central page stating a word
count for category pages, and I do not believe one exists. Treat the numbers as
a working floor that practitioners agree on, not as a rule Google published.
The defensible part of the consensus is the *qualitative* claim — a grid with no
prose gives the ranking system nothing to read — and that part is uncontested.

### The working specification

For each concern collection, before it ships:

1. **150–300 words of genuine intro copy** answering the concern, above or
   beside the grid — what the concern is, what ingredient classes address it,
   how to choose. Not a keyword paragraph.
2. **A real product set.** Part I's warning stands: a concern collection with
   four products is worse than no page. **Set a floor of 8–12 products and do
   not publish a concern that cannot meet it.**
3. **Outbound internal links** to the matching article and to the 2–3 brands
   that lead on that concern.
4. **`CollectionPage` + `ItemList`** — we already emit this
   (`app/Support/Seo.php:1222`), so it is free.
5. **A title that is not the template.** Ours is `{title}{sep}{sitename}`;
   a concern page wants an intent-shaped title. Per-row SEO overrides already
   exist (`ProductSeo::PUBLISHED_KEYS`) so this needs no code.
6. **Arabic copy at the same time, or a deliberate decision not to.** Shipping a
   concern page in English only puts an untranslated page into a cluster that
   `Seo::alternateLinks()` will advertise as having an Arabic alternate.

**Items 4 and 5 are already built. Items 1, 2, 3 and 6 are the owner's copy and
merchandising.** That ratio is the whole finding of this document.

---

## 12. What a sustained ingredient blog looks like at the article level

The brief asked for length, structure, internal linking and schema. Consensus
across current guidance:

| Dimension | What the sources say |
|---|---|
| **Architecture** | Pillar + cluster: a pillar page links to supporting posts, supporting posts link back, related posts link to each other. **8–15 cluster articles per pillar.** ([digitalapplied.com](https://www.digitalapplied.com/blog/seo-content-clusters-2026-topic-authority-guide), [w3era.com](https://www.w3era.com/blog/seo/pillar-page-strategy-guide/)) |
| **Pillar length** | 3,000–5,000 words (some sources 3,000–10,000), with a 100–200 word summary of each subtopic and the cluster link near the top of that section ([whitehat-seo.co.uk](https://whitehat-seo.co.uk/blog/blog-posts-pillar-pages-landing-pages), [searchsavvy.in](https://searchsavvy.in/content-clusters-and-pillar-pages-the-ultimate-guide/)) |
| **Internal links** | 2–5 contextual links per 1,000 words; total page links under ~150; important pages within 3 clicks of home; every cluster page links back to the pillar with anchor text containing the pillar's target term ([digitalapplied.com](https://www.digitalapplied.com/blog/internal-linking-strategy-2026-large-site-architecture-guide), [upwardengine.com](https://upwardengine.com/blog/internal-linking-best-practices-seo/)) |
| **Anchor text** | Mirror the query — "best Korean serum for acne scars", not "click here" ([bizaigpt.com](https://bizaigpt.com/blog/seo-content-cluster-ecommerce-guide)) |
| **Schema** | `Article` on every post — **we already emit it** (`app/Support/Seo.php:1145`) |
| **Retrieval framing** | Current guidance emphasises writing in extractable, self-contained chunks with cited sources, for AI/answer surfaces as well as ranking ([typeflo.io](https://typeflo.io/blog/seo-best-practices-for-blogs)) |

**Caveat, and it is the same one as §11:** these figures are practitioner
consensus, repeated widely, not Google documentation. The *structural* claims
(bidirectional linking, one topic per page, query-shaped anchors) are safe. The
*numeric* ones (3,000–5,000 words, 8–15 clusters) are conventions. **Do not put
them in a test.**

### Mapped onto this shop

Our pillar already exists: **`/skincare-guide/`** (`docs/GA-SKINCARE-GUIDE.md`)
and the journal. What does not exist is the **mapping** — cluster articles tied
to concern collections, linking both ways, in both languages. That is Part I §6
restated with a shape.

The competitor's programme, read against this table
(`SEO-COMPETITIVE.md` §11): roughly 8–10 articles, ingredient- and concern-led,
mapped onto their collection axes, **translated into Arabic**, with visible
housekeeping slips (two articles on skin type). **They are executing a
recognisable version of this and we are not.** They are also not executing it
especially well, which is the encouraging half.

---

## 13. Additions to the scorecard

| Area | Part I verdict | Part II revision |
|---|---|---|
| Arabic / hreflang | "Ahead; theirs possibly absent" | **Parity on existence. Ahead on URL shape (`/ar/` subfolder vs `ar.` subdomain). Behind on Arabic content.** |
| Concern taxonomy | "Behind — they have `/collections/acne`" | **Further behind than stated.** They run a concern axis *and* a skin-type axis; three concern handles confirmed, seven claimed (`SEO-COMPETITIVE.md` §9) |
| Crawl hygiene | not assessed | **Ahead.** Their `.atom` collection feeds are indexed (`SEO-COMPETITIVE.md` §10); we serve no second machine address per listing |
| Competitive set | one competitor | **Wider.** Four UAE mass retailers run Arabic K-beauty landing pages; at least one specialist (`crescitebeauty.com`) is bilingual (`SEO-COMPETITIVE.md` §12) |
| Off-site authority | "Behind, outside this module" | **Unchanged, now with named targets** (`SEO-COMPETITIVE.md` §12) |
| Our own duplicates | "we lack Shopify's duplicate-path defect" | **True of this Laravel port; a duplicate pair is visible in the index on the legacy site** (`SEO-COMPETITIVE.md` §14). Needs one person to check, not a code change |

**The headline of Part I survives intact: we are not losing on markup.** Part II
tightens why — the gap is a concern/skin-type taxonomy, a translated ingredient
blog, and local links, and all three are content the owner commissions rather
than code a lane ships.
