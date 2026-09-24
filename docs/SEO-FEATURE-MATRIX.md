# The complete SEO feature list — us, Shopify, and kbeautyarabia.com

Lane S3, 24 September 2026. This is the single list the owner asked for:
*"the full report and list of what kbeautyarabia.com and shopify is doing. a
complete features list."*

It replaces nothing. `docs/SEO-COMPETITIVE.md`, `docs/SEO-GAP.md` and
`docs/SEO-BUILD-PLAN.md` are the working papers behind it and stay as they are.
This is the decision-ready version, and it is written to be read by the person
paying for the work rather than by the person doing it.

---

## Before the table: how much of this is evidence

**I could not open their website.** The container this runs in blocks outbound
web requests. Re-tested as my first action, verbatim:

```
$ curl -sS -o /dev/null -w '%{http_code}\n' https://kbeautyarabia.com/robots.txt
curl: (56) CONNECT tunnel failed, response 403
```

`WebFetch` is blocked the same way (`EGRESS_BLOCKED`). **Web search works.**
So everything below about the competitor comes from what Google has indexed of
them — the addresses, the titles and the descriptions Google is showing — and
never from their page source. I have not read one line of their HTML.

Every competitor cell is tagged:

- **OBSERVED** — it showed up in a search result. That is real evidence: an
  indexed URL proves the page exists at that address, and the title in the
  result is the title tag they wrote.
- **UNVERIFIED** — I could not check it, and the cell says what would settle it.
  Usually "one fetch of the page", which takes a minute the day someone with a
  browser does it.

A cell that says **nothing observable** means exactly that. It is not a hedge
and it is not a hint.

---

## The table

| Feature | What Shopify gives them out of the box | What kbeautyarabia.com is observably doing | What this shop does today |
|---|---|---|---|
| **URL structure** | `/products/x`, `/collections/y`, `/blogs/z`. The words `products` and `collections` are fixed and cannot be changed. | **OBSERVED** — standard Shopify shape throughout: `/collections/acne`, `/products/camellia-deep-collagen-firming-cream-50-ml`, `/pages/faqs`, `/blogs/k-beauty-blog/…`. | Our own shape, chosen by us: `/product/{slug}/`, `/product-category/{path}/`, `/brand/{slug}/`, `/concern/{slug}/`, articles at the site root. Nothing is dictated by a platform. |
| **Duplicate URL paths** | A known Shopify defect. Every product also answers at `/collections/{c}/products/{p}`, and the standard product card links to *that* version — so the shop's own internal links vote against its own canonical. | **UNVERIFIED** — they run four collection axes, so each product sits in many collections and the effect would be multiplied. Settled by one fetch of a collection page, reading whether product links carry the collection prefix. | **We do not have this defect.** One product, one address. `/shop/?cat=…` is a filter that points its canonical back at `/shop/`, so a facet never becomes a second page. |
| **Canonical tags** | Emitted automatically, pointing at the clean product URL. | **UNVERIFIED** — almost certainly present (it is automatic). Whether Google *honours* it is the open question, and only Search Console for their domain answers that. | Emitted on every page, and an operator can override one per product, category or brand. An override pointing at another domain or a downgraded `http://` is caught by the SEO Audit screen. |
| **sitemap.xml** | Generated and kept current automatically. Split into sub-sitemaps. Cannot be filtered or edited. | **OBSERVED, and this is a genuine defect of theirs** — `/collections/numbuzin.atom` is indexed. Shopify publishes an `.atom` feed beside every collection and they have not blocked it, so Google is holding feed URLs as if they were pages. Also indexed: `/collections/best-seller?page=34` and a URL carrying Shopify's internal search parameters (`?_pos=1&_psq=health&_ss=e&_v=1.0`). | One sitemap, currently ~1,400 URLs, and it is *filtered*: a page set to noindex is left out, which Shopify cannot do. Carries language alternates, and product images when the setting is on. A split into sub-sitemaps is the right move at ~20,000 URLs and wrong before that. |
| **robots.txt** | Editable since 2021, but it is a separate file that does not know what any page says about itself. | **OBSERVED by omission** — their `.atom` feeds are indexed, which means `robots.txt` is not blocking them. **UNVERIFIED** in its actual contents: one fetch settles it. | Generated from the same list the application uses to decide what is private, so the file and the pages cannot contradict each other. Served publicly cacheable with no cookie. |
| **Product & Offer markup** (the price and stock Google shows under a result) | Emitted by any modern theme. | **OBSERVED** — a price reached a search result for one of their products, which is what this markup is for. | Emitted, including price, currency, availability and condition. Variable products publish a real price range instead of "AED 0". Shipping and returns markup is built and **switched off**, waiting on confirmed terms. |
| **Breadcrumb markup** | Theme-dependent; the default theme emits it. | **UNVERIFIED** — nothing observable. One fetch of a product page settles it. | Emitted on products, categories, brands and articles. |
| **Organization markup** (who the business is) | Minimal by default. | **UNVERIFIED** — nothing observable. | Emitted, with logo, social profiles and site-search action. |
| **LocalBusiness markup** (a shop with an address) | Not standard. Usually an app or a theme edit. | **UNVERIFIED** whether they emit it — but their address is public and consistent: **OBSERVED** as *Unit 7, DREC, Al Manara Road, Al Quoz 1, Dubai*, phone *058 534 4111*, *hello@kbeautyarabia.com*. | Built and shipped **empty**. The boxes are at Store → Business Details → Business. It publishes nothing until the address is complete — half an address is worse than none. |
| **Article markup** (blog posts) | Emitted by the default blog template. | **OBSERVED** that the articles exist and are indexed; **UNVERIFIED** whether they carry the markup. | Emitted on every journal article. |
| **Review / rating stars** | Not built in. Every Shopify store buys an app (Judge.me, Loox, Okendo) for this. | **UNVERIFIED** — no star rating appeared in any result I saw, and their Facebook page reads "Not yet rated (0 reviews)". Settled by one fetch of a product page. | Built in, no app and no monthly fee. Star ratings are published only where the page actually shows them — which is the rule that keeps Google from issuing a penalty for it. |
| **hreflang / multi-language** | Automatic for a language subfolder. For a separate domain or subdomain Shopify's own documentation says hreflang is created automatically too. | **OBSERVED — they have a full Arabic site** at `ar.kbeautyarabia.com`: collections, products and translated blog articles, all indexed with Arabic titles. **UNVERIFIED**: whether the two halves point at each other correctly. One fetch of an Arabic page and its English twin settles it, and it is the single most valuable unanswered question here. | Arabic at `/ar/…`, a **subfolder on the same domain**, which shares the domain's authority where a separate subdomain does not. Full hreflang plus `x-default`, and the sitemap names every language of every page. |
| **Arabic in the URL itself** | Shopify has allowed translated slugs since 2023, entered by hand per item. The route word (`products`, `collections`) still cannot be translated. | **OBSERVED, and this corrects our earlier research.** Most of their Arabic pages sit at English addresses — but at least two are fully Arabic *including the route word*: `ar.kbeautyarabia.com/مجموعات/تيام` and `/منتجات/طقم-السفر-الأكثر-مبيعًا`. **UNVERIFIED** how widely. | We can serve Arabic addresses for anything, with no per-item typing. This is still an advantage. It is **no longer the advantage "nobody on Shopify can copy"** that our earlier note claimed. |
| **Meta title & description templates** | Set per item, with a store-wide fallback pattern. | **OBSERVED and it is rigidly templated**: every collection is "*{Name} Beauty Products*" — "Acne Beauty Products", "Serums Beauty Products", "Makeup Beauty Products", across dozens of pages. Their home page is the exception and is properly written. | Full template system with tokens, per-product and per-category overrides, and a Yoast importer for what was written on the old site. The SEO Audit screen flags a title too long, too short, duplicated, or a description missing or too thin. |
| **Image alt text** | A field per image. Nothing checks whether it is filled. | **UNVERIFIED** — nothing observable. | Every image already has usable alt text falling back to the product name, a real box per gallery image, and an audit card counting the products whose photographs still have no written alt. |
| **Core Web Vitals** (Google's speed score) | Shopify hosts it, which is a real advantage. Independent 2026 benchmarks still put the median Shopify store's mobile LCP at 2.26s against a 2.5s pass mark, and **only 48% of Shopify stores pass all three metrics on mobile**. | **UNVERIFIED** — needs a PageSpeed run against their URL, which is a two-minute job for anyone with a browser. | Measured and recorded in `docs/page-cost.md` and `docs/IMAGE-PIPELINE-AND-CACHE.md`. Page speed is sized with CSS rather than measured in JavaScript, deliberately, and two tests forbid the layout-measuring APIs by name. Query counts are a hard budget, not a hope. |
| **Page speed — the honest comparison** | Their floor is high and their ceiling is low: you cannot tune what you do not host, and every app they install adds script. | **UNVERIFIED** as a number. **OBSERVED** that they carry at least one third-party layer (Tabby payments) plus whatever their theme loads. | Ours is ours to fix. Shared hosting is the constraint, and caching headers for the crawl files are already in place. |
| **Redirects & 404 handling** | Built-in redirect table. A 404 is just a 404. | **UNVERIFIED** — nothing observable. | A redirect table with its own screen, a 404 log that shows what is actually breaking, and refusal of a redirect that points at itself. **The live gap is ours, not theirs — see "Where we genuinely lose" item 4.** |
| **Category / collection pages** | Unlimited, hand-built, each with its own title and text. | **OBSERVED — four separate ways in**: by concern, by skin type, by product type, by brand. Roughly 100+ brand collections indexed. | Categories, brands and collections all exist and rank-ready. The taxonomy is narrower: we run product type and brand well, and the other two axes are new. |
| **Concern-led collections** ("Korean skincare for acne") | Just a collection. The platform does nothing special. **This is merchandising, not a feature you can buy.** | **OBSERVED, complete, and this is the real gap**: seven concerns — acne, anti-ageing, enlarged pores/congestion, dry & dehydrated, pigment/uneven tone, redness/sensitivity, oily & blemishes — *plus* five skin types: combination, dry, normal, oily, sensitive. Twelve landing pages aimed at how people actually search. | The machinery shipped: `/concern/{slug}/` is live, the mapping exists, the pages are built. **They stay dark until products are tagged and intro copy is written.** Nothing is missing in code. |
| **Blog / editorial** | A blog engine. Empty until somebody writes. | **OBSERVED and sustained** — a real ingredient-led programme: "De-coding Common K-beauty Skincare Ingredients (Part 1)", "Top 5 Korean Skincare Ingredients and Their Benefits", "Tips for Treating Acne Scars and Pigmentation Using Korean Skincare", "How to Choose the Right K-Beauty Moisturizer for Your Skin Type", "Trending Now: The Hottest Korean Skincare Products of 2024". **And they are translated into Arabic.** | A journal, a skincare guide, and five articles. The engine is not the gap. The volume is. |
| **Internal linking** | Automatic navigation. Anything beyond that is theme work. | **OBSERVED structurally** — four collection axes means a product is reachable by four different routes, which is a lot of internal links pointing at each product without anyone writing one. | Menus, categories, brands, related products and the quiz/routine links. Article-to-collection-to-product linking is the piece that does not exist yet, because the articles and the tagged collections do not exist yet. |
| **Crawl hygiene** | Shopify publishes extra machine-readable addresses (`.atom` feeds, search parameter URLs) that stores routinely forget to block. | **OBSERVED, and they have forgotten** — an `.atom` feed indexed, a page-34 pagination URL indexed, a search-parameter URL indexed. Small, real, and entirely self-inflicted. | We publish no second machine-readable address per listing, so there is no equivalent rule for us to add. Noted so nobody "fixes" a problem we do not have. |
| **Auditing** | Nothing. Shopify ships no SEO audit. | **N/A** — a platform they cannot audit from inside. | Store → SEO & Meta → SEO Audit scans the whole indexable surface — duplicate titles, unsafe canonicals, missing descriptions and images, products with no identifier, photographs with no alt text, and as of this lane, old addresses with no redirect. **Nothing comparable exists on their side of the fence.** |

---

## 1. Where we already win

Each of these is checkable, and none of them is an opinion.

**One product, one address.** Shopify's `/collections/x/products/y` duplicate is
a structural defect of the platform, and their theme's own product cards link to
the duplicate rather than the canonical. We do not have the defect because we
chose our own URLs. *Evidence: our routing serves one path per product; the
`/shop/?cat=…` filter canonicalises back to `/shop/` rather than to itself.*

**A sitemap that tells the truth.** Ours leaves out a page that has been set to
noindex. Shopify's cannot — it submits pages to Google that the page itself
tells Google to ignore. Ours also carries language alternates and, optionally,
product images.

**Arabic on the same domain.** Their Arabic lives at `ar.kbeautyarabia.com`, a
subdomain, which search engines treat as a separate site for authority
purposes. Ours lives at `/ar/`, a subfolder, which shares everything the main
domain has earned. This is a structural advantage and it costs nothing to keep.

**Structured data well past a default Shopify store.** Product, Offer,
AggregateOffer, Breadcrumb, Organization, LocalBusiness (built, empty),
Article, CollectionPage, ItemList, AggregateRating, and merchant shipping and
returns (built, off). *Evidence: `app/Support/Seo.php` emits all of them; the
schema inspector screen shows them on any page.*

**Reviews without an app.** Every Shopify store of this size is paying a monthly
fee for review stars. Ours are built in, and — importantly — they are published
only on pages that actually display the rating, which is the rule that stops
Google issuing a manual penalty for review markup.

**We can see our own SEO. They cannot.** Store → SEO & Meta → SEO Audit has no
equivalent on Shopify at all. On the seeded demo catalogue it immediately found
24 products sharing one meta description — the kind of fault that is invisible
one page at a time.

**Crawl hygiene.** Their `.atom` feeds, paginated deep pages and internal search
URLs are in Google's index. Ours are not, because we do not publish them.

---

## 2. Where we genuinely lose, ranked

The previous lanes concluded that the competitor is ahead on concern-led
collections, a sustained ingredient blog, and local editorial citations, and
that **none of the three is a feature of an SEO module**. My research did not
contradict that. It strengthened it: their concern taxonomy turns out to be
bigger than previously mapped, and their blog turns out to be translated into
Arabic as well.

| # | Where we lose | Code, content, or neither |
|---|---|---|
| 1 | **Twelve landing pages we do not have** — seven concerns and five skin types. This is the whole ballgame: "korean skincare for acne" is someone reaching for a credit card. | **Content.** The code shipped. `/concern/acne/` answers the moment three products are tagged and the intro is written. |
| 2 | **A sustained ingredient blog, in two languages.** Five articles against a running programme, and theirs are translated into Arabic. | **Content.** The journal, the Arabic pipeline and the Article markup are all built and idle. |
| 3 | **Local editorial links and directory listings.** They are cited by UAE press and listed in directories. Links from other sites are the one ranking factor no amount of our own code can manufacture. | **Neither.** It is outreach — emails and listings, the owner's or a freelancer's time. |
| 4 | **Fifteen old addresses that will 404 the day we go live.** The WooCommerce site published its categories at the site root (`/skincare/`, `/skincare-sets/` — both still in Google today). This app serves them at `/product-category/…`. Forwarding the old domain preserves the path, so the old address lands on a 404 at the end of a 301 and its ranking is dropped instead of inherited. | **Neither — it is fifteen rows.** As of this lane the audit screen *reports* them, so they cannot go unnoticed. Running the WordPress import writes all fifteen for free; typing them takes about ten minutes. |
| 5 | **Arabic product and category text.** They have a fully translated Arabic storefront. Our Arabic machinery is complete and the catalogue text is not written. | **Content**, and it needs a human Arabic writer, not machine translation. |
| 6 | **An address on the business markup.** Their address is public and consistent everywhere. Ours is an empty box. | **Neither.** It is one form, once the owner supplies the address. |

**What is *not* on this list, and deliberately.** We do not lose on markup,
canonicals, sitemaps, robots, titles, hreflang, crawl hygiene or auditing. On
several of those we are clearly ahead. **No SEO module feature will close the
gap at the top of this table**, and a round that ships more markup while items
1–3 stay untouched will produce green tests and no movement in Google.

---

## 3. What the owner must decide or supply

Numbered, and sized honestly. Nothing in items 1–4 needs an engineer.

1. **Tag 30–45 products against the concern list.** ~2–3 hours, once, using the
   search terms already written up in `docs/SEO-CONCERN-MAPPING.md` §3. Done at
   Catalog → Build my routine. **This is the single biggest blocker in the whole
   plan** — nobody but the owner can judge which of our products treat acne.
2. **Decide: three concerns or six?** 5 minutes. Recommendation: start with
   three — acne, pigmentation, sensitivity/redness — and only widen once they
   rank. A concern page with four products ranks worse than no page at all.
3. **Three intro paragraphs of ~200 words, English and Arabic.** 2–3 hours for a
   writer, or a small freelance fee. Both SEO lanes refused to generate these
   and were right to: thin or machine-written copy on a concern page is the
   main risk in this entire programme. Drafts live in `docs/SEO-CONCERN-COPY.md`.
4. **Confirm the postal address, emirate, phone and opening hours.** 10 minutes.
   Search shows *Barsha Heights* and *+971 58 505 2611* for us — **confirm it,
   do not let a lane trust a search snippet.** Use one identical wording
   everywhere, because the business markup and the directory listings must match
   exactly. Goes in at Store → Business Details → Business.
5. **Write down the current shipping and returns terms.** 15 minutes. Search
   shows us advertising "1–3 days free delivery over 199 AED" and "free
   returns"; that is a snippet, not a policy. The markup is built and switched
   off. **A wrong delivery promise in a Google listing is worse than none** — it
   is a promise Google shows a shopper. For comparison, theirs reads: ships in
   1–2 business days, 7 days to request a return, they pay return shipping.
6. **Run the WordPress import's redirect step, or type fifteen rows.** 10–20
   minutes. Closes item 4 of the losses above. The new audit card lists exactly
   which fifteen and what each should point at.
7. **Approve a first six article topics.** 20 minutes to decide; the writing is
   the real cost. A proposed six is in `docs/SEO-BUILD-PLAN.md` item 6, each one
   mapped to a concern page so the article and the collection feed each other.
8. **Budget a human Arabic writer.** A decision, not a task. Arabic is roughly
   55% of UAE search and Gulf shoppers search in dialect rather than formal
   Arabic; machine translation is worse than nothing here.
9. **Decide who does the outreach**, and whether to pay for directory listings.
   Named targets with URLs are in `docs/SEO-COMPETITIVE.md` §12. Probably the
   best return per dirham in this document, and it is entirely people-time.
10. **Turn on Search Console for `kbeautybliss.com`.** 15 minutes, free, and the
    owner already owns the domain. **Nothing in any of these three documents
    contains a search-volume number, because this project has no keyword tool
    and no Search Console.** The concern list is well-evidenced for choosing
    which pages to build. It will not support a traffic forecast, and nobody
    should build a business case on one.
11. **One hour with a browser, the day someone has one.** Every UNVERIFIED cell
    above is a single page fetch: their Arabic hreflang first (it decides how
    hard the Arabic push is worth), then whether their product cards link to the
    duplicate collection URL, then a PageSpeed run.

---

## Two corrections to our own earlier research

Stated plainly, because both were written down as settled and both were wrong.

**1. "Arabic URLs are a structural advantage nobody on Shopify can copy" — withdrawn.**
`docs/SEO-BUILD-PLAN.md` item 2 said their Arabic pages must sit at English
slugs because Shopify cannot translate them. Shopify has allowed translated URL
slugs since July 2023, entered by hand per item, and the competitor is already
serving at least two addresses that are Arabic *including* the route word. The
advantage is now "we do it automatically and for everything, they do it by hand
and mostly have not" — real, smaller, and not structural.

**2. "A subdomain is the one Shopify setup where hreflang is not automatic" — doubtful.**
`docs/SEO-BUILD-PLAN.md` item 5 rests on that premise. Shopify's own
documentation, as surfaced in search, says hreflang is created automatically for
every international domain **or** subfolder. The premise may simply be out of
date. This does not change what to do — one fetch of their Arabic page still
answers it, and it is still the highest-value unanswered question — but nobody
should plan on their hreflang being broken.

---

## Sources

Competitor and platform observations, all 24 September 2026:

- [kbeautyarabia.com](https://kbeautyarabia.com/) · [/collections/acne](https://kbeautyarabia.com/collections/acne) · [/collections/hyperpigmentation](https://kbeautyarabia.com/collections/hyperpigmentation) · [/collections/anti-aging](https://kbeautyarabia.com/collections/anti-aging) · [/collections/serums](https://kbeautyarabia.com/collections/serums)
- [/collections/numbuzin.atom](https://kbeautyarabia.com/collections/numbuzin.atom) — the indexed feed
- [/blogs/k-beauty-blog](https://kbeautyarabia.com/blogs/k-beauty-blog) · [/pages/faqs](https://kbeautyarabia.com/pages/faqs) · [/pages/shipping-and-returns](https://kbeautyarabia.com/pages/shipping-and-returns)
- [ar.kbeautyarabia.com/products](https://ar.kbeautyarabia.com/products) · [an Arabic-slug collection](https://ar.kbeautyarabia.com/%D9%85%D8%AC%D9%85%D9%88%D8%B9%D8%A7%D8%AA/%D8%AA%D9%8A%D8%A7%D9%85) · [an Arabic-slug product](https://ar.kbeautyarabia.com/%D9%85%D9%86%D8%AA%D8%AC%D8%A7%D8%AA/%D8%B7%D9%82%D9%85-%D8%A7%D9%84%D8%B3%D9%81%D8%B1-%D8%A7%D9%84%D8%A3%D9%83%D8%AB%D8%B1-%D9%85%D8%A8%D9%8A%D8%B9%D9%8B%D8%A7) · [an Arabic blog article](https://ar.kbeautyarabia.com/blogs/k-beauty-blog/top-5-korean-skincare-ingredients-and-their-benefits)
- Shopify: [translating URL handles](https://changelog.shopify.com/posts/translate-resource-url-handles-for-different-languages) · [localization and translation](https://help.shopify.com/en/manual/international/localization-and-translation) · [hreflang in a theme](https://shopify.dev/docs/storefronts/themes/seo/hreflang) · [international domains](https://help.shopify.com/en/manual/international/managing-international-domains)
- Shopify speed benchmarks: [1Digital Agency, 2026](https://www.1digitalagency.com/blog/core-web-vitals-for-shopify-stores-2026-benchmarks-and-optimization-playbook-33932/) · [corewebvitals.io](https://www.corewebvitals.io/core-web-vitals/shopify-guide)
- Our own live site, for the legacy addresses still indexed: [kbeautybliss.com/skincare/](https://kbeautybliss.com/skincare/) · [kbeautybliss.com/skincare-sets/](https://kbeautybliss.com/skincare-sets/) · [kbeautybliss.com/product-category/skincare-sets/](https://kbeautybliss.com/product-category/skincare-sets/)
