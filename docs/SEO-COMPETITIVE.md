# SEO — the competitor, the platform, and what is actually true

Lane S. Written 2026-09-24 against `lane/seo-research`.

The owner's brief: *"kbeautyarabia.com is coming everywhere in google on top,
they are based on shopify, also i need a strong research what things they use
etc and also shopify. i need the same exact things with better improvement
features in our seo module, I want it super strong."*

---

## 0. Read this first — how much of this document is evidence

**This container's egress proxy blocks all outbound HTTP.** Verified, not
assumed: `curl https://kbeautyarabia.com/robots.txt` and `curl
https://example.com/` both fail identically with `curl: (56) CONNECT tunnel
failed, response 403`. `WebFetch` fails the same way. **`WebSearch` works.**

So every statement below about kbeautyarabia.com is drawn from *search results*
— indexed URLs, titles and snippets — and never from their HTML. I have not
seen one byte of their markup.

Each claim about them is tagged:

- **OBSERVED** — it appeared in a search result, with the source named.
- **UNVERIFIED** — plausible, and I could not check it. Do not build against it.

§6 is the checklist of what to re-run the moment egress opens.

---

## 1. What a Shopify store gets for free

This is the platform baseline. It is what kbeautyarabia.com has without anyone
there having decided anything.

### 1.1 URLs, and the duplicate-path problem

Shopify serves every product at two addresses: the clean `/products/<handle>`
and the collection-scoped `/collections/<collection>/products/<handle>`. The
canonical tag points at the clean one by default. The trap is that the standard
Liquid product-card snippet links with `product.url | within: collection`, so
**every collection page and every related-products block casts an internal link
vote for the scoped URL against the canonical**. Google frequently resolves that
conflict against the declared canonical and reports it as *"Duplicate, Google
chose different canonical than user"*.
([amsive.com](https://www.amsive.com/insights/seo/resolving-shopify-duplicate-content-between-collection-product-pages/),
[get-ryze.ai](https://www.get-ryze.ai/blog/shopify-canonical-urls-explained-for-collection-and-product-pages),
[shaheryarahmed.com](https://shaheryarahmed.com/blog/shopify-duplicate-content-products-collections))

The fixed path vocabulary — `/products/`, `/collections/`, `/pages/`, `/blogs/`
— is not editable, and Shopify Markets cannot translate those segments even when
it can translate the handle after them.
([easyappsecom.com](https://easyappsecom.com/guides/shopify-multilingual-guide))

**This is the single biggest structural weakness of the platform the competitor
is on, and we do not have it.** Our products live at exactly one address,
`/product/{slug}/`, with a trailing-slash redirect onto it.

### 1.2 sitemap.xml

Automatic and not editable. `/sitemap.xml` is a **sitemap index**, not a flat
list, pointing at `sitemap_products_1.xml`, `sitemap_collections_1.xml`,
`sitemap_pages_1.xml` and `sitemap_blogs_1.xml`, splitting at roughly 5,000 URLs
per child file.
([help.shopify.com](https://help.shopify.com/en/manual/promoting-marketing/seo/find-site-map),
[gofishdigital.com](https://gofishdigital.com/blog/shopify-xml-sitemaps/),
[logeix.com](https://logeix.com/shopify-seo/xml-sitemap))

On images the sources conflict and the conflict is itself the finding: the
product sitemap carries **one `<image:image>` per product — the featured image
only** — which is why the long-running Shopify community request is "how to
include *all* product images" and why several SEO vendors describe Shopify as
having "no image sitemap".
([community.shopify.com](https://community.shopify.com/c/shopify-design/product-xml-sitemap-file-how-to-include-all-product-images/m-p/1341156),
[seowill.com](https://seowill.com/blogs/seo-blog/xml-sitemaps-for-shopify-stores-how-to-find-and-submit))

**That gap is exploitable and this lane has built into it** — see
`docs/SEO-BUILD-PLAN.md` item 2.

### 1.3 robots.txt

Generated, with `Disallow: /cart`, `Disallow: /checkout`, `Disallow: /admin` and
a `Sitemap:` line. Overridable since 2021 by adding `templates/robots.txt.liquid`
to the theme. Shopify explicitly does not support edits to it.
([shopify.dev](https://shopify.dev/docs/storefronts/themes/seo/robots-txt),
[help.shopify.com](https://help.shopify.com/en/manual/promoting-marketing/seo/editing-robots-txt))

Note the difference in reasoning from ours. `SeoFilesController::robots()`
carries a long argument for why `Disallow` is the *wrong* tool for keeping a
page out of the index — a URL Google may not crawl is a URL whose `noindex` it
can never read. Shopify disallows `/cart` and `/checkout` and does not emit a
`noindex` on them; we do both, and `Indexability::PRIVATE_PREFIXES` exists so
the two files cannot disagree.

### 1.4 Structured data

**Shopify emits none of it at the platform level.** Product, Offer,
BreadcrumbList and Organization markup come from **the theme** (Dawn and most
Online Store 2.0 themes ship Product + Offer + BreadcrumbList) or from an app.
That means it varies store to store, it is lost on a theme change, and it is the
single most common thing a Shopify SEO consultant is hired to repair.

`AggregateRating` and `Review` in particular almost always come from a **review
app**: Judge.me, Loox, Okendo, Yotpo or Stamped all inject their own JSON-LD.
The characteristic failure is that the app injects a **second `Product` node**
rather than merging into the theme's, producing duplicate schema that Google
rejects.
([coreppc.com](https://coreppc.com/shopify/judge-me-vs-loox-vs-okendo/),
[get-ryze.ai](https://www.get-ryze.ai/blog/review-and-rating-schema-for-shopify-without-breaking-rich-results),
[shopifyranked.com](https://shopifyranked.com/shopify-schema/aggregate-rating/))

**We emit all of it from one place** — `App\Support\Seo::jsonLd()` builds
Organization, WebSite+SearchAction, Product (with Offer, AggregateRating, GTIN,
shipping, returns and variant offers), Article, CollectionPage+ItemList and
BreadcrumbList as one coherent set, escaped through one encoder. There is
structurally no way for us to emit two competing `Product` nodes. That is an
advantage over the competitor's platform, not a gap.

### 1.5 hreflang and Markets

Automatic, on by default, driven by the market/language configuration; Translate
& Adapt can translate meta title, meta description and the handle, but not the
`/products/` and `/collections/` path segments.
([help.shopify.com](https://help.shopify.com/en/manual/markets/seo),
[analyzify.com](https://analyzify.com/hub/shopify-markets-seo),
[transcy.io](https://transcy.io/blog/shopify-translate-and-adapt-review/))

We already emit reciprocal `hreflang` plus `x-default` on every storefront page
(`Seo::alternateLinks()`, `app/Support/Seo.php:591`) and `xhtml:link` alternates
inside `/sitemap.xml` (`SeoFilesController::cluster()`). We are at parity here,
and our sitemap's alternate handling is arguably better documented than theirs.

### 1.6 Images and Core Web Vitals

Shopify's CDN resizes on demand from URL parameters; `image_tag` / `image_url`
build `srcset` and `sizes`; Online Store 2.0 themes ship native `loading="lazy"`.
The recurring real-world defect is themes lazy-loading the **hero/LCP** image,
which hurts the metric it was meant to help, and omitting `width`/`height`,
which causes CLS.
([kaspianfuad.com](https://kaspianfuad.com/blog/shopify-image-url-filter-reference/),
[get-ryze.ai](https://www.get-ryze.ai/blog/how-to-lazy-load-shopify-product-images-the-right-way),
[corewebvitals.io](https://www.corewebvitals.io/core-web-vitals/shopify-guide))

Ours is `docs/IMAGE-PIPELINE-AND-CACHE.md`. Not compared in detail here — that
is Lane G/image territory, not this lane's.

### 1.7 Redirects and 404s

`/admin/redirects` with CSV bulk import is a genuinely good Shopify feature and
merchants use it heavily after a replatform.

We have this: Store → SEO & Meta → **Redirects & 404s**, backed by the redirects
table added in `2026_10_05_000000_add_category_seo_and_redirects`. Parity.

### 1.8 The metafield-driven SEO title/description

Every product, collection, page and article has an editable "search engine
listing" title and description, stored as `global.title_tag` /
`global.description_tag`. Ours is the `seo` json column on `products`,
`categories`, `brands`, `posts` and `pages` (`{title, desc, og_image, canonical,
noindex}` — `ProductSeo::PUBLISHED_KEYS`). Parity, and ours additionally carries
a per-row canonical and noindex, which Shopify's does not.

### 1.9 FAQPage — **do not build this**

The brief asked for `FAQPage` JSON-LD. It should not be built.

Google restricted FAQ rich results to authoritative government and health sites
in **August 2023**, and then **fully deprecated them: FAQ rich results stopped
appearing in Google Search on 7 May 2026**, with the Search Console report
removed in June 2026 and API support in August 2026.
([searchengineland.com](https://searchengineland.com/faq-schema-rise-fall-seo-today-463993),
[searchenginejournal.com](https://www.searchenginejournal.com/google-drops-faq-rich-results-from-search/574429/),
[getpassionfruit.com](https://www.getpassionfruit.com/blog/what-changed-with-google-drops-faq-rich-results-and-what-to-do-now))

Building `FAQPage` markup in September 2026 is writing code for a feature that
was switched off four months ago. It is the clearest cargo-cult item on the
list and it is **not built**. See `docs/SEO-BUILD-PLAN.md` §"Not worth it".

---

## 2. What ranks a UAE K-beauty store

- **Arabic/English hreflang with `x-default`.** We have it.
- **AED in `Offer.priceCurrency`, and whether VAT is inside the price.** We have
  both — and the `valueAddedTaxIncluded` on a `UnitPriceSpecification` is
  something almost no Shopify theme emits. The UAE has a 5% VAT, so a price
  published without that flag is a price that may be 5% wrong in the result.
- **Merchant listing terms — shipping cost and return window on the Offer.**
  We have them, behind `enable_merchant`, off by default.
- **Review snippets.** We emit `AggregateRating` from real approved reviews.
  Their platform gets this from an app (§1.4).
- **Concern-based collections** (`/collections/acne`) — the long-tail pattern.
  This is where the competitor is visibly ahead; see §3.
- **LocalBusiness / Store markup.** They have a physical Dubai shop
  (OBSERVED, [timeoutdubai.com](https://www.timeoutdubai.com/kids-shopping/best-k-beauty-stores-in-dubai-2026)).
  Our `org_type` setting already offers `Store` and `LocalBusiness` as options
  (`AdminController::SETTING_RULES`), but we emit no address, opening hours or
  geo, which is what actually earns a local pack placement.

---

## 3. kbeautyarabia.com — what I can actually establish

### OBSERVED — URL structure

Every path below appeared as an indexed URL in search results:

| URL | What it tells us |
|---|---|
| `/collections` | collection index |
| `/collections/all` | Shopify's catch-all collection |
| `/collections/makeup`, `/haircare`, `/sale`, `/best-seller` | category + merchandising collections |
| `/collections/acne` | **concern-based collection** |
| `/collections/k-pharmacy-1` | note the `-1` suffix — a Shopify handle-collision artifact |
| `/collections/cosrx` | **brands are collections**, not a separate route |
| `/collections/vendors?q=K-Beauty+Arabia` | **Shopify-native vendor route** |
| `/products/the-hyaluronic-acid-3-serum-20-ml` | product at the clean root path |
| `/pages/faqs` | Shopify page route |
| `/blogs/k-beauty-blog` and `/blogs/k-beauty-blog/<handle>` | Shopify blog route |
| `/search` | Shopify search route |

Sources: [collections](https://kbeautyarabia.com/collections),
[acne](https://kbeautyarabia.com/collections/acne),
[cosrx](https://kbeautyarabia.com/collections/cosrx),
[vendors](https://kbeautyarabia.com/collections/vendors?q=K-Beauty+Arabia),
[product](https://kbeautyarabia.com/products/the-hyaluronic-acid-3-serum-20-ml),
[faqs](https://kbeautyarabia.com/pages/faqs),
[blog](https://kbeautyarabia.com/blogs/k-beauty-blog).

**`/collections/vendors?q=` is conclusive.** That route exists only on Shopify.
The owner's "they are based on shopify" is confirmed, not assumed.

### OBSERVED — their collection title template

Indexed titles read: *"Makeup Beauty Products"*, *"Haircare Beauty Products"*,
*"Acne Beauty Products"*, *"Sale Beauty Products"*, *"K-Pharmacy Beauty
Products"*, *"CosRx Beauty Products"*, *"Products Beauty Products"* (on
`/collections/all` — a template misfire they have not noticed).

That is a mechanical `{{ collection.title }} Beauty Products` pattern applied to
every collection. It is worth knowing two things about it: it is **keyword-led
and site-name-free**, which is the opposite of our `{title} {sep} {sitename}`
default; and it is **unedited**, which is why `/collections/all` is titled
"Products Beauty Products". They are not hand-writing collection titles. This is
beatable with ordinary effort.

### OBSERVED — the concern-collection pattern is the real lesson

`/collections/acne` is the finding the owner should act on. They have turned a
skin *concern* into a landing page. Our nearest equivalents are
`/product-category/...` (a product taxonomy) and the four curated listings
(`new-in`, `best-sellers`, `super-sale`, `everything-under-54-aed`) — all
merchandising, none of them concern-led. "korean skincare for acne" is a query
with intent; "new in" is not.

This is a **content and taxonomy** gap, not a code gap. See the build plan.

### OBSERVED — content depth

Indexed articles include "How to Build a Customized Korean Skincare Routine for
Your Skin Type", "Korean Beauty Secrets: Tips for Achieving Flawless Skin",
"Trending Now: The Hottest Korean Skincare Products of 2024", plus pieces on
acne scars and pigmentation, choosing a moisturiser, and Cica/Heartleaf
ingredients. ([blog](https://kbeautyarabia.com/blogs/k-beauty-blog))

Ingredient- and concern-led, informational, mapped onto the collections. That is
a deliberate topical cluster.

### OBSERVED — presence and citations

- Instagram [@kbeautyarabia](https://www.instagram.com/kbeautyarabia/) and
  [Facebook](https://www.facebook.com/kbeautyarabia/).
- Listed in [Time Out Dubai's "7 best K-Beauty shops in Dubai"](https://www.timeoutdubai.com/kids-shopping/best-k-beauty-stores-in-dubai-2026)
  — a high-authority local editorial citation.
- A press release on
  [IssueWire](https://www.issuewire.com/discover-the-magic-of-k-beauty-with-kbeautyarabiacom-your-one-stop-shop-for-korean-beauty-products-in-the-middle-east-1764348539956690).
- Positioning claims "UAE'S #1 K-Beauty Shop", 100% authentic, physical Dubai
  store, 10% off first order.
- **Not** on Trustpilot; Facebook shows 0 reviews.

A one-line reading: a chunk of their ranking is **local editorial links and
brand presence**, which is off-site and cannot be answered with markup.

### OBSERVED — a price reached a search result

A search for their COSRX serum surfaced AED 116 for
`/products/the-hyaluronic-acid-3-serum-20-ml`. A price being visible in search
data is *consistent with* Product/Offer markup; it does not prove it, because a
price also appears in ordinary body copy.
**Status: the price is OBSERVED; the markup behind it is UNVERIFIED.**

### UNVERIFIED — everything below. Do not build against any of it.

- Which review app they run, if any, and whether it emits `AggregateRating`.
- Whether their theme emits Product/Offer/BreadcrumbList JSON-LD at all.
- Whether they run Arabic at all, and therefore whether they emit `hreflang`.
  (Every URL I found is English. **This may be their biggest weakness** — an
  Arabic-language competitor in the UAE market with no Arabic pages — but I
  cannot confirm the absence of something from search results alone.)
- Their `robots.txt`, whether `robots.txt.liquid` is overridden.
- Whether their product cards link `within: collection` (§1.1) and whether
  Search Console is therefore reporting duplicate canonicals against them.
- Their meta descriptions, their `og:` tags, their image `alt` text.
- Their theme, their app stack, their Core Web Vitals.
- Their true indexed page count.
- Whether they hold `LocalBusiness` markup for the Dubai store.

---

## 4. The honest summary

The competitor is on a platform with a **known structural duplicate-content
weakness** (§1.1) that we do not have, with **no platform-level structured
data** (§1.4) where we have a single coherent graph, and with **machine-generated
collection titles they have not proofread** (§3).

They are ahead on three things, and only one of them is code:

1. **Concern-led collections** (`/collections/acne`) — taxonomy and content.
2. **A real, sustained ingredient/concern blog** mapped onto those collections.
3. **Local editorial citations** (Time Out Dubai) and social presence.

None of those three is an SEO-module feature. The module is not why they rank.

That does not make the module irrelevant — a technically weak site cannot
convert good content into rankings — but the owner should hear it plainly: **we
are not losing on markup.** See `docs/SEO-GAP.md` for where we genuinely are
behind, and `docs/SEO-BUILD-PLAN.md` for what is worth building versus what is
cargo cult.

---

## 5. Sources

All URLs cited inline above. The competitor's own pages are cited as *indexed
URLs observed in search results*, never as pages I fetched.

---

## 6. What I could not verify, and what it would take

**Every item here needs one thing: outbound HTTP from this container.** With
egress open, all of it is roughly an hour's work.

| # | Question | How to answer it |
|---|---|---|
| 1 | Do they emit Product/Offer JSON-LD, and with what fields? | `curl https://kbeautyarabia.com/products/the-hyaluronic-acid-3-serum-20-ml` and read the `ld+json` blocks |
| 2 | Do they have `AggregateRating`, and from which app? | same fetch; look for judge.me / loox / okendo / yotpo / stamped asset hosts |
| 3 | Do they run Arabic, and do they emit `hreflang`? | same fetch; look for `rel="alternate" hreflang` and an `/ar` or `?locale=ar` path |
| 4 | Do they emit `LocalBusiness` for the Dubai store? | fetch the homepage and `/pages/contact` |
| 5 | Is their `robots.txt` the Shopify default or an override? | `curl https://kbeautyarabia.com/robots.txt` |
| 6 | Their real sitemap shape and URL counts | `curl https://kbeautyarabia.com/sitemap.xml`, then each child |
| 7 | Do their product images carry `<image:image>`? | read `sitemap_products_1.xml` |
| 8 | Do their collection cards link `within: collection`? | fetch `/collections/acne`, grep hrefs for `/collections/acne/products/` |
| 9 | Their canonical on a scoped product URL | fetch `/collections/acne/products/<handle>` and read `rel=canonical` |
| 10 | Their meta title/description on a product and a collection | any fetch |
| 11 | Their `og:`/Twitter card coverage | any fetch |
| 12 | Their image `alt` coverage | fetch a collection page |
| 13 | Their Core Web Vitals | PageSpeed Insights API |
| 14 | Their indexed page count | not answerable by fetch either — needs a rank tracker or Search Console, neither of which we have for their domain. **This one stays unanswerable.** |
| 15 | Their true collection taxonomy in full | `/sitemap_collections_1.xml` |

Items 1–13 and 15 are a fetch away. Item 14 is not answerable by this project at
all and should not be promised.
