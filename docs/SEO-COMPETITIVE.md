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

---

# Part II — Lane S2 deep research

Lane S2, 2026-09-24, branch `lane/seo-research-deep`. Reads on from Part I.
**Research only — this lane changed no application code.**

## 7. Egress re-tested, and still shut

Re-tested at the start of this lane, because §6 is worth an hour the moment it
opens:

```
curl -s -o /dev/null -w "%{http_code}" --max-time 10 https://kbeautyarabia.com/   ->  000
curl -s -o /dev/null -w "%{http_code}" --max-time 10 https://www.google.com/       ->  000
WebFetch https://kbeautyarabia.com/  ->  {"error_type":"EGRESS_BLOCKED"}
```

**Still shut, and shut for everything — not a competitor-specific block.**
`example.com` and `google.com` fail identically, so this is the allow-list, not
a WAF. §6 remains entirely unexecuted and every item in it is still open.

`WebSearch` works. Everything in Part II comes from it. The same tagging
discipline applies: **OBSERVED** with a source, or **UNVERIFIED**.

---

## 8. The correction that matters most: **they have an Arabic site**

Part I §3 lists under UNVERIFIED: *"Whether they run Arabic at all… Every URL I
found is English. **This may be their biggest weakness**."* The lane brief
carried the same hypothesis and asked me to establish what I could.

**It is wrong. They have a full Arabic layer on the `ar.` subdomain.**

### OBSERVED — Arabic URLs, indexed, with Arabic SERP titles

| Arabic URL | Indexed title |
|---|---|
| [`ar.kbeautyarabia.com/products`](https://ar.kbeautyarabia.com/products) | المجموعات - كيه بيوتي أرابيا - K-Beauty Arabia |
| [`ar.kbeautyarabia.com/collections/kaja`](https://ar.kbeautyarabia.com/collections/kaja) | منتجات التجميل كاجا |
| [`ar.kbeautyarabia.com/blogs/k-beauty-blog/top-5-korean-skincare-ingredients-and-their-benefits`](https://ar.kbeautyarabia.com/blogs/k-beauty-blog/top-5-korean-skincare-ingredients-and-their-benefits) | أفضل 5 مكونات كورية للعناية بالبشرة وفوائدها |
| [`ar.kbeautyarabia.com/blogs/k-beauty-blog/trending-now-the-hottest-korean-skincare-products-of-2024`](https://ar.kbeautyarabia.com/blogs/k-beauty-blog/trending-now-the-hottest-korean-skincare-products-of-2024) | الأكثر رواجاً الآن: أفضل منتجات العناية بالبشرة الكورية لعام … |

This is not a stray translated page. It is **the catalogue, the collections and
the blog**, all three. The last two rows are the same articles as the English
blog, translated — so their editorial is translated too, not just their product
data.

Separately, several **English-path** URLs surface with **Arabic titles** in
results — [`/collections/face`](https://kbeautyarabia.com/collections/face)
(منتجات تجميل الوجه), [`/collections/value-sets`](https://kbeautyarabia.com/collections/value-sets)
(مجموعة منتجات تجميل قيّمة), [`/collections/goodal`](https://kbeautyarabia.com/collections/goodal)
(منتجات جودال للتجميل), [`/collections/body-care`](https://kbeautyarabia.com/collections/body-care)
(منتجات العناية بالجسم والتجميل), [`/collections/normal-skin`](https://kbeautyarabia.com/collections/normal-skin)
(منتجات تجميل للبشرة العادية), [`/collections/frontpage`](https://kbeautyarabia.com/collections/frontpage)
(الصفحة الرئيسية Beauty Products), [`/collections/kaine`](https://kbeautyarabia.com/collections/kaine)
(منتجات كاين للتجميل).

**Read that carefully, because it cuts two ways.** It is firm evidence the
Arabic layer is real and indexed. It is *also* consistent with Google having
trouble keeping the two language versions apart — an Arabic title rendered
against an English URL is one of the shapes an hreflang problem takes. I cannot
tell which from search results alone, and the difference is important, so:

- **OBSERVED:** an Arabic layer exists at `ar.kbeautyarabia.com`, covering
  products, collections and blog articles.
- **OBSERVED:** English-path collection URLs surface with Arabic titles.
- **UNVERIFIED:** whether they emit correct reciprocal `hreflang`, and whether
  the mixed titles are an hreflang defect or ordinary SERP localisation.

### Why the second half is worth chasing

Shopify's own documentation splits here, and the split lands exactly on their
configuration. Hreflang is **automatic for subfolder markets** (`/ar/`), but for
**country-specific domains and subdomains it "require[s] manual implementation
or a third-party app."**
([help.shopify.com](https://help.shopify.com/en/manual/markets/customizations/domains-and-languages),
[get-ryze.ai](https://www.get-ryze.ai/blog/how-to-implement-hreflang-on-shopify-multi-region-stores),
[1digitalagency.com](https://www.1digitalagency.com/blog/how-to-correctly-implement-hreflang-with-shopify-markets-with-code-examples/))

They are on a **subdomain**. So they are on the branch of Shopify's own
behaviour where hreflang is *not* automatic and somebody has to have done it by
hand or bought an app for it. That does not prove they got it wrong. It does
mean **this is the highest-value single thing in §6 to check the moment egress
opens**, and it moves to the top of that list.

Two further things follow from the subdomain choice, both citable:

- **Subfolders share domain authority with the primary domain; subdomains are
  weaker on this point.** ([help.shopify.com](https://help.shopify.com/en/manual/markets/seo),
  [analyzify.com](https://analyzify.com/hub/shopify-markets-seo))
  **We serve Arabic at `/ar/`** — a subfolder — per `app/Support/Locale.php:12`.
  So our URL shape for Arabic is the better of the two, by their platform
  vendor's own guidance.
- **Their Arabic URLs keep the English handle.** The Arabic article above is
  `…/blogs/k-beauty-blog/top-5-korean-skincare-ingredients-and-their-benefits`
  — Arabic title, English slug, English `/blogs/` and `/collections/` segments.
  That is Part I §1.5's platform limitation showing up in production.

### What this does to the "Arabic is our advantage" thesis

It narrows it sharply, and it does not kill it. Stated precisely:

| Claim | Status |
|---|---|
| "They have no Arabic, we would be alone" | **False.** Disproved above. |
| "Our Arabic URL shape is better than theirs" | **Supported** — `/ar/` subfolder vs `ar.` subdomain, on Shopify's own guidance |
| "We can serve Arabic slugs and they structurally cannot" | **Supported** — their live Arabic URLs carry English handles and fixed English path segments |
| "Their hreflang may be hand-rolled and therefore may be broken" | **UNVERIFIED and worth one fetch** |

**The honest version for the owner: Arabic is not a free win any more. It is
still a winnable fight, on URL shape and slug language rather than on
existence.** Anyone who was about to build on "the competitor has no Arabic"
should stop and read this section first.

---

## 9. Their collection taxonomy, mapped

Part I observed nine collection handles. Search surfaces far more. **Every row
below appeared as an indexed URL in a search result**, except where the cell
says otherwise.

### 9.1 By skin type — a whole axis Part I did not see

| Handle | Indexed title |
|---|---|
| [`/collections/oily-skin`](https://kbeautyarabia.com/collections/oily-skin) | Oily Skin Beauty Products |
| [`/collections/sensitive-skin`](https://kbeautyarabia.com/collections/sensitive-skin) | Sensitive Skin Beauty Products |
| [`/collections/combination-skin`](https://kbeautyarabia.com/collections/combination-skin) | Combination Skin Beauty Products |
| [`/collections/normal-skin`](https://kbeautyarabia.com/collections/normal-skin) | منتجات تجميل للبشرة العادية |

`/collections/dry-skin` is named in search-engine summaries of their collections
index but I did not capture it as an indexed URL of its own.
**Status: the skin-type axis is OBSERVED; `dry-skin` as a specific handle is
UNVERIFIED.**

### 9.2 By concern — the axis the whole gap analysis turns on

| Handle | Indexed title |
|---|---|
| [`/collections/acne`](https://kbeautyarabia.com/collections/acne) | Acne Beauty Products |
| [`/collections/anti-aging`](https://kbeautyarabia.com/collections/anti-aging) | Anti-aging Beauty Products |
| [`/collections/hyperpigmentation`](https://kbeautyarabia.com/collections/hyperpigmentation) | Hyperpigmentation Beauty Products |

Search summaries of their collections index consistently list a **seven-concern
set**: *Acne, Anti-aging, Enlarged Pores/Congestion, Dry & Dehydrated,
Pigment/Uneven Tone, Redness/Sensitivity, Oily & Blemishes*. That phrasing
recurred across four independent searches, which is good evidence the nav labels
are real — but **I captured indexed URLs for only three of the seven.**

- **OBSERVED:** `acne`, `anti-aging`, `hyperpigmentation` exist as collections.
- **UNVERIFIED:** the handles for the other four. Do not write them into a
  redirect map or a competitive table as fact.

**This is three times the concern surface Part I credited them with, and it sits
alongside a parallel skin-type axis.** Part I's §4 conclusion — that
concern-led collections are their real advantage — is not weakened by the deeper
map. It is considerably strengthened.

### 9.3 By product type

[`/collections/cleansers`](https://kbeautyarabia.com/collections/cleansers),
[`/collections/toners`](https://kbeautyarabia.com/collections/toners),
[`/collections/sun-care`](https://kbeautyarabia.com/collections/sun-care),
[`/collections/body-care`](https://kbeautyarabia.com/collections/body-care),
[`/collections/face`](https://kbeautyarabia.com/collections/face),
[`/collections/value-sets`](https://kbeautyarabia.com/collections/value-sets),
[`/collections/makeup`](https://kbeautyarabia.com/collections/makeup),
[`/collections/k-pharmacy-1`](https://kbeautyarabia.com/collections/k-pharmacy-1).

Summaries name a longer list — essences/ampoules, moisturizers, serums, eye
care, exfoliants, lip care, masks, mists/sprays — **UNVERIFIED as handles.**

### 9.4 By brand

Brands are collections (Part I got this right). Newly observed as indexed URLs:
[`cosrx`](https://kbeautyarabia.com/collections/cosrx),
[`kaine`](https://kbeautyarabia.com/collections/kaine),
[`fully`](https://kbeautyarabia.com/collections/fully),
[`sulwhasoo`](https://kbeautyarabia.com/collections/sulwhasoo),
[`celimax`](https://kbeautyarabia.com/collections/celimax),
[`abib`](https://kbeautyarabia.com/collections/abib),
[`axis-y`](https://kbeautyarabia.com/collections/axis-y),
[`roundlab`](https://kbeautyarabia.com/collections/roundlab),
[`skin-1004`](https://kbeautyarabia.com/collections/skin-1004),
[`i-m-from`](https://kbeautyarabia.com/collections/i-m-from),
[`ongredients`](https://kbeautyarabia.com/collections/ongredients),
[`medicube`](https://kbeautyarabia.com/collections/medicube),
[`meditherapy`](https://kbeautyarabia.com/collections/meditherapy),
[`goodal`](https://kbeautyarabia.com/collections/goodal),
[`numbuzin`](https://kbeautyarabia.com/collections/numbuzin.atom) (see §10),
[`kaja`](https://ar.kbeautyarabia.com/collections/kaja) (Arabic).

Note the handle artifacts: `skin-1004` for "Skin1004", `i-m-from` for "I'm
from", `k-pharmacy-1` with a collision suffix. Shopify handle generation,
unproofread — consistent with Part I §3's reading of their title template.

### 9.5 Other routes

[`/pages/about-us`](https://kbeautyarabia.com/pages/about-us) ("Our Story"),
[`/pages/brands`](https://kbeautyarabia.com/pages/brands),
[`/pages/faqs`](https://kbeautyarabia.com/pages/faqs),
[`/collections/frontpage`](https://kbeautyarabia.com/collections/frontpage),
[`/collections/best-seller`](https://kbeautyarabia.com/collections/best-seller)
and a separately-named "Best Sellers" both appear in their brand/collection
listing — **two collections for one idea**, which is the kind of internal
duplicate that splits link equity. **Status: the pair is OBSERVED in a
collections listing; I did not capture two distinct indexed URLs, so treat the
duplication as strongly indicated rather than proven.**

---

## 10. A genuine technical defect on their side: indexed `.atom` feeds

A search returned this as an indexed result:

> **K-Beauty Arabia** — [`https://kbeautyarabia.com/collections/numbuzin.atom`](https://kbeautyarabia.com/collections/numbuzin.atom)

**OBSERVED, and it is a real finding.** Shopify serves an Atom feed at
`<collection>.atom` for every collection. It is machine output: the same product
set as the HTML collection, in XML, at a second address. Having one **in the
index** means:

- a duplicate of the collection's content at a URL nobody should land on;
- crawl budget spent on feeds across (at minimum) every brand collection;
- a result a user can actually reach, which renders as raw XML.

The standard fix is a `robots.txt` rule, and Shopify's default `robots.txt`
does **not** disallow `.atom` — which is why this is a known, recurring Shopify
housekeeping item and why an override via `templates/robots.txt.liquid` exists
(Part I §1.3). They evidently have not done it.

**We do not have this class of defect**, because we do not serve a second
machine-readable address per listing. Worth one line in the gap table and
nothing more — it is their problem to fix, not a feature for us to build.

---

## 11. Their blog, article by article

Part I found five article topics. Confirmed indexed article URLs:

| URL | Title |
|---|---|
| [`/blogs/k-beauty-blog`](https://kbeautyarabia.com/blogs/k-beauty-blog) | K-Beauty Blog (index) |
| [`…/trending-now-the-hottest-korean-skincare-products-of-2024`](https://kbeautyarabia.com/blogs/k-beauty-blog/trending-now-the-hottest-korean-skincare-products-of-2024) | Trending Now: The Hottest Korean Skincare Products of 2024 |
| [`…/korean-beauty-secrets-tips-for-achieving-flawless-skin`](https://kbeautyarabia.com/blogs/k-beauty-blog/korean-beauty-secrets-tips-for-achieving-flawless-skin) | Korean Beauty Secrets: Tips for Achieving Flawless Skin |
| [`…/effective-tips-for-treating-acne-scars-and-pigmentation-in-korean-skincare`](https://kbeautyarabia.com/blogs/k-beauty-blog/effective-tips-for-treating-acne-scars-and-pigmentation-in-korean-skincare) | Tips for Treating Acne Scars and Pigmentation Using Korean Skincare |
| [`…/skin-type-guide`](https://kbeautyarabia.com/blogs/k-beauty-blog/skin-type-guide) | Skin Type Guide |
| [`…/skincare-guide-by-skin-type`](https://kbeautyarabia.com/blogs/k-beauty-blog/skincare-guide-by-skin-type) | Skincare Guide by Skin Type |
| [`…/top-5-korean-skincare-ingredients-and-their-benefits`](https://ar.kbeautyarabia.com/blogs/k-beauty-blog/top-5-korean-skincare-ingredients-and-their-benefits) | Top 5 Korean Skincare Ingredients and Their Benefits (captured on the **Arabic** host) |

Also named in snippets, **UNVERIFIED as URLs**: "How to Build a Customized
Korean Skincare Routine for Your Skin Type", "How to Choose the Right K-Beauty
Moisturizer for Your Skin Type", "Achieving Clearer Skin: 6 Effective Tips for
Those with Acne-Prone Skin".

### The pattern worth copying

Note **`skin-type-guide` and `skincare-guide-by-skin-type`** — two articles on
one topic. Combined with `best-seller`/`Best Sellers` (§9.5), their content
housekeeping is not tight.

But the shape of the programme is the lesson, and it is a deliberate one:

1. **Every article maps onto a collection axis they actually sell.** Skin-type
   articles ↔ skin-type collections. Acne-scar and pigmentation articles ↔
   `/collections/acne` and `/collections/hyperpigmentation`. Ingredient articles
   ↔ the brands that lead on those ingredients.
2. **Ingredient-led, not product-led.** "Top 5 Korean Skincare Ingredients",
   Cica/Heartleaf, snail mucin. These answer a question rather than sell a SKU,
   which is what earns the informational query.
3. **Translated, not English-only** (§8).

**That is a topical cluster in the textbook sense, and it is what Part I §4 item
2 was pointing at.** Section 13 below establishes what the textbook actually
says the cluster should look like.

---

## 12. Off-site: the competitive set is much larger than one competitor

The brief framed this as us versus kbeautyarabia.com. Search does not support
that framing. **OBSERVED** — UAE/Gulf sites ranking for Korean-skincare queries:

**Specialist K-beauty retailers:**
[koreanskincarearabia.com](https://koreanskincarearabia.com/) ("Authentic
K-Beauty Direct from Seoul to UAE" — note the near-identical brand name),
[crescitebeauty.com](https://crescitebeauty.com/collections/korean-beauty)
(**bilingual — serves [`/ar/collections/…`](https://crescitebeauty.com/ar/collections/top-korean-skincare-beauty-brands-in-dubai-100-authentic-k-beauty-online?page=4)**),
[lamisebeauty.com](https://www.lamisebeauty.com/),
[beautykoreadubai.com](https://beautykoreadubai.com/),
[8blissbeauty.com](https://8blissbeauty.com/),
[morefromkorea.com](https://morefromkorea.com/),
[glamsecret.ae](https://glamsecret.ae/blogs/buy-korean-skincare-in-uae-the-ultimate-2026-guide-to-glass-skin/buy-korean-skincare-in-uae-the-ultimate-2026-guide-to-glass-skin),
[bloomha.com](https://bloomha.com/best-korean-skincare-brands-available-in-uae-2026-updated-list/).

**Mass retailers with an Arabic K-beauty landing page — the real threat:**
[Noon](https://www.noon.com/uae-ar/korean-beauty-store-ae/),
[Centrepoint](https://www.centrepointstores.com/ae/ar/c/beautyandpersonalcare-kbeauty-skin),
[Faces](https://www.faces.ae/ar/korean-skincare),
[Bin Sina Pharmacy](https://www.binsina.ae/ar/brand/korean-beauty.html).

**Two things follow.**

First, **`crescitebeauty.com` is bilingual too.** So is every mass retailer in
that list. §8's narrowing holds: Arabic is table stakes in this market, not a
moat.

Second, **the mass retailers are the ones with domain authority**, and they all
have an Arabic K-beauty page. A specialist cannot out-authority Noon. It can
out-*specific* it — which is the concern-collection argument again, and it is
the only version of this fight a small shop wins.

### Link targets, named

The brief asked for UAE/Gulf publications and directories that actually link to
beauty retailers. **OBSERVED, each one is a real page that lists retailers:**

| Target | Evidence |
|---|---|
| [Time Out Dubai — "7 best K-Beauty shops in Dubai"](https://www.timeoutdubai.com/kids-shopping/best-k-beauty-stores-in-dubai-2026) | Already links the competitor (Part I). Highest-authority target found. |
| [MyBayut (Bayut) — Arabic, "محلات مستحضرات تجميل كورية في دبي"](https://www.bayut.com/mybayut/ar/%D9%85%D8%AD%D9%84%D8%A7%D8%AA-%D9%85%D8%B3%D8%AA%D8%AD%D8%B6%D8%B1%D8%A7%D8%AA-%D8%AA%D8%AC%D9%85%D9%8A%D9%84-%D9%83%D9%88%D8%B1%D9%8A%D8%A9-%D8%AF%D8%A8%D9%8A/) | **Arabic-language** Dubai shop roundup. Bayut is a top-tier UAE property portal with a large editorial arm. Not currently listing us. |
| [The Zenith Magazine — "Top 6 Korean Skincare Products in Dubai"](https://thezenithmagazine.com/6-korean-skincare-products-in-dubai/) | Product/retailer roundup |
| [Health Magazine AE — press release section](https://healthmagazine.ae/press_release/dubai-welcomes-the-ultimate-k-beauty-experience-with-the-grand-opening-of-k-beauty-on-dubai/) | Accepts UAE beauty press releases |
| [Publicity Marketplace](https://www.publicitymarketplace.com/K-Beauty%20Arabia) | Where the competitor's NAP is listed (§13) |
| [Yello.ae](https://www.yello.ae/), [GetListedUAE](https://www.getlisteduae.com/), [LaunchDub directory](https://www.launchdub.ai/directory) | UAE business directories, free listings, NAP citations |

General UAE-citation guidance: NAP consistency across directories, full address
with emirate/street/area, `+971-4-xxxxxxx` phone format.
([intersmart.ae](https://intersmart.ae/blog/best-business-directories-in-the-uae/),
[digitalarabia.ae](https://www.digitalarabia.ae/local-business-listing-sites-in-uae),
[seolinkworld.com](https://seolinkworld.com/uae-business-listing-sites/))

**All of this is outreach, not code.** It belongs to the owner, and it is
probably the highest-leverage unglamorous work available.

---

## 13. Their NAP, observed from a third party

[Publicity Marketplace](https://www.publicitymarketplace.com/K-Beauty%20Arabia)
lists K-Beauty Arabia at **Unit 7, DREC, Al Manara Road, Al Quoz 1, Dubai, UAE**,
phone **058 534 4111**.

**OBSERVED — but from a third-party directory, not from them.** Two readings,
both useful:

1. Al Quoz 1 is a warehouse/light-industrial district. Part I called this a
   "physical Dubai shop" on the strength of the Time Out listing; the address is
   more consistent with a **warehouse/fulfilment unit** than a mall storefront.
   The distinction matters for how much `LocalBusiness` markup is really worth
   to *them*, and it should not be overstated for us either.
2. **They are in directories.** That is the citation-building behaviour §12
   describes, visible in the wild.

**UNVERIFIED:** whether that address is current, whether it is retail or
fulfilment, and whether they emit `LocalBusiness` markup for it (§6 item 4).

---

## 14. What search says about our own site

Not asked for, and it fell out of the searches, and it is actionable, so it is
here. **OBSERVED** — `kbeautybliss.com` URLs indexed:

[`/`](https://kbeautybliss.com/) ("K-Beauty Bliss UAE | #1 Shop Authentic Korean
Beauty Products Online"), [`/about/`](https://kbeautybliss.com/about/),
[`/shop/`](https://kbeautybliss.com/shop/),
[`/skincare/`](https://kbeautybliss.com/skincare/),
[`/korean-skincare-brands/`](https://kbeautybliss.com/korean-skincare-brands/),
[`/skincare-sets/`](https://kbeautybliss.com/skincare-sets/) — *"Best Korean
Skin Care Sets for Women in **2024**"* — and
[`/product-category/skincare-sets/`](https://kbeautybliss.com/product-category/skincare-sets/)
— *"Best Korean Skin Care Sets for Women in **2025**"*.

**Two findings, and the second is a live defect.**

1. **Our titles are already better than theirs.** "K-Beauty Bliss UAE | #1 Shop
   Authentic Korean Beauty Products Online" against their "Products Beauty
   Products". Part I §3's judgement that their template is beatable with
   ordinary effort is confirmed from the other side.
2. **`/skincare-sets/` and `/product-category/skincare-sets/` are both indexed,
   with near-identical titles differing only by year.** That is our own
   duplicate-content problem — the same *class* of problem Part I §1.1 credits
   us with not having. It is on the legacy WooCommerce site rather than this
   Laravel port, so it may already be answered by the port's routing or by the
   redirects table; **I did not verify which, and it needs one person to look.**
   It is in the build plan as a checked item, not a code change.

Also observed on the live site: delivery *"1–3 days free delivery on orders over
199 AED"*, *"free returns"*, Barsha Heights, `+971 58 505 2611`,
`info@kbeautybliss.com`, 50+ brands, Instagram 53K followers
([instagram.com/kbeauty.bliss](https://www.instagram.com/kbeauty.bliss/),
[facebook.com/kbeautyblissuae](https://www.facebook.com/kbeautyblissuae/)).

**The delivery and returns strings matter beyond trivia:** `Seo::jsonLd()`
already supports `shippingDetails` and `hasMerchantReturnPolicy` behind
`enable_merchant` (off by default), and those two sentences are most of what
that markup needs. The owner must confirm them as current before anything is
published as structured data — a wrong shipping threshold in a merchant listing
is worse than no markup. See the build plan.

---

## 15. Sources for Part II

All cited inline. Competitor URLs are cited as **indexed URLs observed in search
results**; no page on any competitor domain was fetched, because egress is shut
(§7).

---

## 16. Addendum to §6 — revised priority, and two items answered

§6 is Lane S's checklist of what needs egress. It is left as written. Part II
changes the order it should be worked in and retires two rows.

**Work §6 in this order when egress opens:**

1. **§6 item 3 — hreflang and the Arabic layer.** Promoted to first. §8
   establishes they run `ar.kbeautyarabia.com`, which is the one Shopify
   configuration where hreflang is **not** automatic, and several English-path
   URLs surface with Arabic titles. Fetch `ar.kbeautyarabia.com/products` and its
   English equivalent, read `rel="alternate" hreflang` on both, check
   reciprocity and `x-default`. **This single answer decides how hard to push
   the Arabic work** — see `SEO-BUILD-PLAN.md` item 5.
2. **§6 items 8 and 9** — the `within: collection` question and the canonical on
   a scoped product URL. Now higher value, because §9 shows they run four
   collection axes, so a product sits in many collections and the scoped-URL
   vote is multiplied.
3. **§6 item 15 — the full collection taxonomy.** §9 maps most of it from search,
   but four of the seven claimed concern handles are still UNVERIFIED and
   `sitemap_collections_1.xml` settles them in one fetch.
4. Everything else in §6, unchanged.

**Two rows can be answered or retired now:**

- **§6 item 3 is partly answered.** "Do they run Arabic?" — **yes, OBSERVED**
  (§8). Only the hreflang half remains open.
- **§6 item 4 (LocalBusiness) has a cheaper first step.** Their NAP is already
  visible on a third-party directory (§13), so the address is known without a
  fetch; only whether they *emit the markup* still needs one.

**§6 item 14 (their true indexed page count) remains unanswerable** and Part II
found nothing to change that. It needs a rank tracker or Search Console for
their domain and this project has neither. It should stay off every list.

**One new row for §6:**

| # | Question | How to answer it |
|---|---|---|
| 16 | Is `.atom` disallowed in their `robots.txt`, and how many feeds are indexed? | `curl https://kbeautyarabia.com/robots.txt` and grep for `.atom`; see §10 |
