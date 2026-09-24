# SEO build plan — what to build, in what order, and what not to build

Lane S, 2026-09-24. Reads on from `docs/SEO-COMPETITIVE.md` and `docs/SEO-GAP.md`.

Ranked by **ranking impact per unit of work**. The owner is paying for
judgement, so the last section is as important as the first: it says plainly
which of the things on the original wishlist are cargo cult, and why.

---

## Before the list: the uncomfortable finding

`SEO-GAP.md` concludes that **this shop's SEO module is ahead of a default
Shopify store**, decisively on structured data and auditing, and that the
competitor's advantage is **concern-led collections, a sustained ingredient/
concern blog, and local editorial links** — none of which is a module feature.

So the highest-impact items on this list are numbers 1 and 2, and **number 1 is
not code**. Anyone who skips it and builds items 3–8 will ship good work and
not move a ranking.

---

## Built this round

### ✅ SEO Audit — the whole indexable surface
**Admin path: Store → SEO & Meta → SEO Audit.**

`App\Support\SeoAudit`, `Admin\SeoAuditApiController`, `routes/seo-audit-admin.php`.

Ten checks over products, categories, brands, articles and pages — including the
two that no per-row check can produce: **duplicate titles across the set**, and
**canonical overrides pointing off-site**. Existing `Catalogue Audit` does three
checks on products only.

Test: `tests/Feature/IndexableSurfaceAuditTest.php` (16 tests). Risk: low — a
read-only endpoint behind `system.diagnostics`, owner-only, throttled 6/min.

### ✅ Product images in sitemap.xml
**Admin path: Store → SEO & Meta → Settings · Sitemap & robots · "Product images in sitemap". Ships OFF.**

Whole gallery, de-duplicated, featured image first, `<image:loc>` only (Google
withdrew support for `image:title`/`caption`/`license`). Shopify emits the
featured image only — this is where we go past them.

Test: same file. Risk: low; default-off keeps `/sitemap.xml` byte-identical.

---

## Built in round 2 (2.60.259)

Items 1, 2, 3 and 4 of the ranked list below. What each one turned out to
actually need is recorded there; this is the summary.

### ✅ `LocalBusiness` / `Store` markup with a real address — was item 2
**Admin path: Store → Business Details → Business · "Where the shop is".**

`App\Support\BusinessAddress`, `App\Support\OpeningHours`, eight settings,
merged into the EXISTING Organization node in `Seo::jsonLd()`.

Half an address is never published. `geo` and `openingHoursSpecification` are
gated on `org_type` being a Place (`Store`/`LocalBusiness`), because they are
invalid on `Organization` and `OnlineStore`. The telephone is `support_phone`,
already on that tab. Everything ships blank.

Test: `tests/Feature/LocalBusinessSchemaTest.php` (22 tests).

### ✅ Item 3 is this, and it needed no new file
The address went **into** the Organization node, not into a second one. That is
the whole of item 3 below, discharged — there is still nothing for a separate
`SeoGraph` to hold, and a test asserts the page carries exactly one node of
Organization type.

### ✅ Image `alt` coverage — was item 4, and half of it already existed
**Admin path: Store → SEO & Meta → SEO Audit**, new card "Product image with no
alt text". The fix for each row is **Catalog → Products → the product → Media**.

The ranking assumed a migration plus an editor change. Neither was needed:
`products.image_alts`, the per-row alt boxes in the product editor and
`Product::altFor()` all landed with Lane E. The real gap was that nothing told
the owner WHICH products still had none. So this round added the audit check
only.

New `SeoAudit::ADVISORY` keeps a finding where no page is broken from taking the
verdict headline away from one where something is.

Test: six new tests in `tests/Feature/IndexableSurfaceAuditTest.php`.

### ✅ Concern-led collections — was item 1, and the mapping already existed
**`/concern/acne/`**, `App\Support\ConcernCollections`,
`routes/concern-collections.php`.

**The big correction to item 1 below: there is no product-to-concern mapping to
invent.** `products.routine_concerns` has held exactly that since Lane FM,
written by **Catalog → Build my routine** against `App\Support\RoutineConcerns`
— the skin quiz's own eight concerns. These pages read that column, so tagging a
product for the routine builder tags it for this too.

**The thin-page warning below is implemented as the design**: a concern page
does not exist — 404, and absent from the sitemap — until it has copy AND at
least `MIN_PRODUCTS` (3) live, in-stock tagged products. One route serves all
eight concerns, so adding one later is copy plus a slug, not a route.

**What the owner has to supply**, and it is the whole list:

1. For `acne`, which ships with its copy written: tag at least three live,
   in-stock products for **"Acne & blemishes"** under Catalog → Build my
   routine. The page then exists. Nothing else.
2. For the other seven concerns: the same tagging, plus one entry of English
   copy in `InterfaceStrings` and the slug added to `ConcernCollections::ENABLED`
   — three lines.

**Measure `acne` before shipping six more.** There is a test that states it.

Test: `tests/Feature/ConcernCollectionsTest.php` (14 tests).

---

## The ranked list

### 1. Concern-led collections and the content that feeds them — **not code**
**Impact: highest. Effort: ongoing. Owner decision required.**

`/collections/acne` is the single most instructive thing observed on the
competitor. "korean skincare for acne" is a query with buying intent;
"new in" is not.

We already have the machinery: `CollectionController` serves four curated
listings by key (`new-in`, `best-sellers`, `super-sale`,
`everything-under-54-aed`) and they are sitemap-listed and indexable. Adding
`acne`, `dryness`, `pigmentation`, `sensitivity`, `anti-ageing`, `pores` is
**configuration plus copy**, not a new subsystem — plus an editorial piece per
concern, interlinked, which is what the competitor has and we do not.

- Files: `CollectionController`, its key map, the header/mega-menu, the journal.
- Test: each new key answers 200, is indexable, appears in `/sitemap.xml`, and
  carries `CollectionPage`+`ItemList`.
- Risk: **thin pages.** A concern collection with four products and no copy is
  worse than no page. Ship one, with real copy, and measure it before shipping six.
- **Owner call:** this needs product-to-concern mapping that only the owner has.

### 2. `LocalBusiness` / `Store` markup with a real address
**Impact: high for UAE local queries. Effort: small. Worth it.**

`org_type` already offers `Store` and `LocalBusiness` (`AdminController::SETTING_RULES`),
but we emit no `address`, `openingHoursSpecification`, `telephone` or `geo`, so
selecting the type buys nothing. The competitor has a physical Dubai shop
(observed) and we are competing in a city where "near me" is a real query shape.

- Files: `app/Support/Seo.php` (**Lane E owns it this round**), plus the
  Business Details settings screen. Must be sequenced **after Lane E merges**.
- Test: a shop with an address emits `PostalAddress` inside the Organization
  node; a shop without one emits the node unchanged (rule 1).
- Risk: low. Guard: emit nothing unless the address is genuinely filled in — a
  half-filled `PostalAddress` is worse than none.

### 3. Fold this lane's graph work into `Seo`, and add `LocalBusiness` there
**Impact: medium (hygiene). Effort: small. After Lane E.**

The brief asked for a new `app/Support/SeoGraph.php` carrying `WebSite` +
`SearchAction`, `Organization`, `BreadcrumbList` and `FAQPage`.

**I did not build it, deliberately.** Three of those four already exist in
`Seo::jsonLd()` — `Organization` at `app/Support/Seo.php:830`, `WebSite` +
`SearchAction` at `:858`, `BreadcrumbList` at `:1349`. A second class emitting
them would put **two `Organization` nodes and two `WebSite` nodes on every
page** — which is precisely the duplicate-schema failure that
`SEO-COMPETITIVE.md` §1.4 identifies as the classic Shopify review-app bug. We
would have imported the competitor's worst defect on purpose.

The fourth, `FAQPage`, is dead — see "Not worth it" below.

So there is nothing for `SeoGraph` to hold. Item 2's `LocalBusiness` belongs
**in `Seo::jsonLd()` beside the Organization node it extends**, once Lane E
lands.

### 4. Image `alt` coverage, and an audit check for it
**Impact: medium — Google Images matters for beauty. Effort: medium.**

Item "Built this round" ties pictures to pages in the sitemap; `alt` text is
what tells Google what is *in* them. I did not add an `alt` check to `SeoAudit`
because I did not find a per-image alt column while reading the schema —
`products.image` is a string and `products.images` is a json array of strings,
with nowhere to put one.

- Files: a migration to carry alt text per image, the product editor, the
  product template, `SeoAudit`.
- Test: an image without alt is reported; the rendered `<img>` carries it.
- Risk: medium — it is a schema change plus an editor change, and it touches the
  product editor, which **Lane E owns this round**.
- **Honest ranking:** worth doing, and bigger than it sounds. Do not start it in
  the same round as Lane E.

### 5. Sitemap index + sub-sitemaps
**Impact: low *now*. Effort: medium. Risk: real. — defer.**

Shopify splits into `sitemap_products_1.xml` etc. We serve one flat file.

`SeoFilesController::sitemap()` already carries a long, correct argument for
**not** splitting, and I agree with it: the limits are 50,000 URLs / 50MB and we
are at ~1,400 elements; splitting changes which *files exist*, so toggling
Arabic would make a child sitemap appear and disappear and Search Console would
report 404s on a sitemap it had already fetched; and a second machine-facing
address is a second thing to get wrong on a host where a new route needs a
migration to exist.

**Build it when the catalogue passes ~20,000 URLs and not before.** Until then
it is work that adds risk and buys nothing. The brief asked for it; my judgement
is that it is premature.

### 6. Chunked sitemap generation
**Impact: none today. Effort: small. — defer, but cheaper than item 5.**

The whole file is assembled in one string with every URL in one array first. At
671 products that is nothing. The brief's "4,000 rows" figure is ~8,000 elements
and still fine. This becomes worth doing at the same point item 5 does, and they
should be done together.

Measured: `/sitemap.xml` runs 20 queries against a budget of 24
(`StorefrontQueryBudgetTest`), unchanged by this lane's work.

### 7. A news/blog sitemap
**Impact: low. Effort: small. — probably not worth it.**

Google News sitemaps require inclusion in Google News, which a retail shop's
journal will not get. A general blog sitemap adds nothing that the articles'
existing `/sitemap.xml` entries do not already do. **Cargo cult unless the shop
is accepted into Google News**, which it will not be.

### 8. hreflang on the Arabic storefront — verify, do not rebuild
**Impact: n/a. Effort: verification only.**

The brief asked for `hreflang` link tags and `x-default` "on every storefront
page". **Already there**: `Seo::alternateLinks()` (`app/Support/Seo.php:591`)
emits reciprocal alternates plus `x-default`, matching the canonical's trailing
slash so no alternate points at a redirect; `SeoFilesController::cluster()` does
the same inside the sitemap. `SeoBilingualTest` covers it.

Nothing to build. If the competitor really has no Arabic pages (**unverified**
— see SEO-COMPETITIVE §6 item 3), then this is a standing advantage we already
hold and the right move is *content in Arabic*, which is item 1 again.

---

## Not worth it — say no to these

### ❌ `FAQPage` JSON-LD
On the brief. **Do not build.** Google restricted FAQ rich results to
authoritative government and health sites in August 2023 and **fully deprecated
them: they stopped appearing in Google Search on 7 May 2026**, with the Search
Console report gone in June 2026 and API support in August 2026. Building it in
September 2026 is writing code for a feature switched off four months ago.
Sources in `SEO-COMPETITIVE.md` §1.9.

*(The FAQ **content** still matters — for users, and as text an LLM surface can
quote. The **markup** buys nothing.)*

### ❌ A second JSON-LD emitter (`SeoGraph`) duplicating existing nodes
See item 3. It would ship the duplicate-schema bug on purpose.

### ❌ `JSON_UNESCAPED_SLASHES` on the JSON-LD encoder
The brief specifies `JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`.
`Seo::encodeJsonLd()` (`app/Support/Seo.php:270`) deliberately does **not** set
`JSON_UNESCAPED_SLASHES`, and its comment records why: with that flag on, an
`org_name` of `</script><script>alert(1)</script>` — an ordinary text box on the
SEO screen — executed on every page of the site. The `\/` escaping is the thing
keeping a `</script>` inside a string from closing the block. **The current
flags are correct. Do not "fix" them to match the brief.** Pinned by
`tests/Feature/SeoRenderTest.php:216`.

### ❌ Keyword-stuffed collection titles
The competitor's `{{ collection.title }} Beauty Products` template is *not* a
model to copy. It is unproofread — `/collections/all` is titled "Products Beauty
Products". Our `{title} {sep} {sitename}` is better, and per-row overrides
already exist for the cases that want something else.

### ❌ Chasing their app stack
We cannot see it (egress blocked), and the parts we would be copying —
Judge.me/Loox-style `AggregateRating` injection — we already do natively and
more safely, from one graph rather than two.

---

## Sequencing note for the integrator

- This lane's work touches `SeoFilesController`, `AdminCapabilities`,
  `AdminController::SETTING_RULES` and `resources/views/admin/app.blade.php`.
  It does **not** touch `app/Support/Seo.php`, `app/Services/Seo/**`,
  `SeoImporter`, the product-editor SEO panel, or `tests/Feature/*Seo*` —
  all Lane E's this round.
- `routes/seo-audit-admin.php` needs **one line** in `routes/web.php`, inside the
  existing `admin-api` group. The file's header says exactly where.
  `database/migrations/2026_12_11_000000_clear_caches_seo_audit.php` ships with it.
- Items 2 and 3 above are the ones to schedule **after Lane E merges**.

---

# Part II — the ranked queue

Lane S2, 2026-09-24. Research only; this lane shipped no code.

Part I ranked eight items and refused four. Part II re-ranks the whole thing
against `SEO-COMPETITIVE.md` Part II and `SEO-GAP.md` Part II, which changed two
of the inputs materially:

- **The competitor has an Arabic site** (`ar.kbeautyarabia.com`). Part I item 8's
  closing line — *"If the competitor really has no Arabic pages … this is a
  standing advantage we already hold"* — is no longer available. What survives
  is narrower and still real (item 4 below).
- **Their concern taxonomy is three times bigger than Part I saw**, and runs
  alongside a parallel skin-type axis. Item 1 gets bigger, not smaller.

## How to read the table

Every item says **CODE** or **CONTENT**. That split is the single most useful
thing in this document.

- **CODE** — a lane can build it from this document. Costs engineering time.
- **CONTENT** — no lane can build it. It is copy, photography, merchandising
  decisions or outreach, and it costs the owner's time or a freelancer's fee.

**Items 1, 2, 3 and 6 are the four highest-impact items and three of them are
CONTENT.** That is the finding, and it has not changed since Part I; it has only
got sharper. A round that ships items 5, 7 and 8 and skips 1–3 will produce
green suites and no ranking movement.

---

## The queue

### 1. Concern + skin-type collections — **CONTENT first, then small CODE**
**Impact: highest. Effort: large and ongoing. Blocked on the owner.**

`SEO-COMPETITIVE.md` §9.2 confirms `acne`, `anti-aging`, `hyperpigmentation` as
live competitor collections, with a seven-concern nav set claimed, plus a
skin-type axis (`oily-skin`, `sensitive-skin`, `combination-skin`,
`normal-skin`). `SEO-GAP.md` §10 cross-checks that against UAE trade coverage
and the two agree on acne, pigmentation, sensitivity/redness, pores and
anti-ageing.

**Proposed set, in priority order** — start with three, not nine:

1. `acne` — strongest evidence on both sides
2. `hyperpigmentation` / uneven tone
3. `sensitivity-redness` — the Cica/Heartleaf/Anua demand cluster
4. `dryness-dehydration` / "glass skin"
5. `pores`
6. `anti-ageing`
7. skin-type axis (`oily`, `dry`, `combination`, `sensitive`, `normal`) — second
   wave, only once the concern axis is proven

**Files:** `app/Http/Controllers/Store/CollectionController.php` (the key map at
`:49`, and the title/intro map at `:203`), `app/Http/Controllers/Store/PageController.php:62`,
`app/Http/Controllers/Store/SeoFilesController.php:188`, the mega-menu
(`MegaMenuApiController:287`ff), plus `lang/*/store.php` for titles and intros in
**both** locales. Four hardcoded lists must stay in step — see item 9.

**Test:** each new key answers 200 in both locales, is indexable, appears in
`/sitemap.xml` with its `xhtml:link` alternates, carries `CollectionPage` +
`ItemList`, and **has at least N products** (see risk). Mutation note: remove the
key from the `SeoFilesController` list and the sitemap assertion goes red.

**Risk: thin pages, and it is the main risk in this document.** `SEO-GAP.md` §11
sets the working spec: 150–300 words of real intro copy, a floor of **8–12
products**, outbound links to the matching article and the 2–3 leading brands.
A concern page with four products and a keyword paragraph ranks worse than no
page and dilutes the rest.

**Owner must supply:**
- **A product-to-concern mapping.** Which of the ~671 products belong under
  acne, under pigmentation, under sensitivity. Nobody else can do this — it is
  a merchandising judgement about the actual catalogue. **This is the single
  biggest blocker in the entire plan.**
- **150–300 words of intro copy per concern, in English and Arabic.**
- A decision on whether to launch three or six.

**Rule-1 note:** these are new pages, so nothing that already works changes.
`Storefront-EnglishUnchangedTest` should stay green throughout; if it goes red,
a shared template was edited and that is an accident, not this item.

---

### 2. Arabic editorial and Arabic concern copy — **CONTENT**
**Impact: very high. Effort: large. Blocked on the owner.**

Previously ranked as "verify, do not rebuild" on the assumption the competitor
had no Arabic. **They do**, including translated blog articles
(`SEO-COMPETITIVE.md` §8). This moves from a non-item to near the top.

The machinery is entirely built — `/ar/` routing, `Seo::alternateLinks()`,
`x-default`, sitemap alternates, the translation pipeline
(`docs/fn-translation-at-scale.md`, `docs/fp-storefront-reads-translations.md`).
**There is nothing to build. There is everything to write.**

The advantage that survives §8 is worth stating because it is what to build the
Arabic push on:

- **`/ar/` is a subfolder and shares domain authority; `ar.` is a subdomain and
  does not.** Shopify's own guidance ([help.shopify.com](https://help.shopify.com/en/manual/markets/seo)).
- **Their Arabic URLs carry English slugs** — `/blogs/k-beauty-blog/top-5-korean-skincare-ingredients-and-their-benefits`
  with an Arabic title — and Shopify cannot translate `/collections/` or
  `/blogs/`. **We can serve Arabic slugs.** That is a structural advantage
  nobody on Shopify can copy.

**Owner must supply:** Arabic copy — concern intros, article translations, and a
decision on Arabic slugs (see item 7). Arabic is ~55% of UAE Google searches and
carries a trust signal English does not
([23hublab.com](https://23hublab.com/arabic-vs-english-search-behavior-in-saudi-arabia/),
[salla.com](https://salla.com/en/blog/marketing-in-tech/search-engines/the-gcc-seo-blueprint-how-to-rank-in-arabic-and-english/)).

**Risk:** machine-translated Arabic is worse than no Arabic. Gulf product search
skews to dialect over Modern Standard Arabic
([cnabke.com](https://www.cnabke.com/en/blogs/arabic-keyword-strategy-middle-east-buying-intent.html)).
Budget for a human.

---

### 3. Local editorial links and directory citations — **CONTENT / outreach**
**Impact: high. Effort: medium. Entirely the owner's.**

Part I identified this and named one target. `SEO-COMPETITIVE.md` §12 names the
rest with URLs: [Time Out Dubai](https://www.timeoutdubai.com/kids-shopping/best-k-beauty-stores-in-dubai-2026)
(already links the competitor), [MyBayut Arabic](https://www.bayut.com/mybayut/ar/%D9%85%D8%AD%D9%84%D8%A7%D8%AA-%D9%85%D8%B3%D8%AA%D8%AD%D8%B6%D8%B1%D8%A7%D8%AA-%D8%AA%D8%AC%D9%85%D9%8A%D9%84-%D9%83%D9%88%D8%B1%D9%8A%D8%A9-%D8%AF%D8%A8%D9%8A/),
[The Zenith Magazine](https://thezenithmagazine.com/6-korean-skincare-products-in-dubai/),
[Health Magazine AE](https://healthmagazine.ae/press_release/dubai-welcomes-the-ultimate-k-beauty-experience-with-the-grand-opening-of-k-beauty-on-dubai/),
plus [Yello.ae](https://www.yello.ae/) and [GetListedUAE](https://www.getlisteduae.com/).

The competitor is in directories — their NAP is visible on
[Publicity Marketplace](https://www.publicitymarketplace.com/K-Beauty%20Arabia)
(`SEO-COMPETITIVE.md` §13).

**Owner must supply:** the outreach itself, and a single canonical NAP string
used identically everywhere (full address with emirate/area, `+971-…` format —
[intersmart.ae](https://intersmart.ae/blog/best-business-directories-in-the-uae/)).
**Do this before item 4**, so the markup and the citations agree from day one.

**Zero code. Probably the best return per dirham in this document.**

---

### 4. `LocalBusiness` / `Store` markup with a real address — **CODE**
**Impact: high for UAE local queries. Effort: small. Ready to build.**

Unchanged from Part I item 2 and still the best pure-code item on the list.
`org_type` already offers `Store` and `LocalBusiness`
(`AdminController.php:1865`) but no `address`, `openingHoursSpecification`,
`telephone` or `geo` is emitted, so choosing the type buys nothing today.

**Files:** `app/Support/Seo.php` (beside the Organization node at `:830`), new
keys in `AdminController::SETTING_RULES` (~`:1863`), the Business Details
settings screen.

**Note for whoever builds it:** `routes/site-address-admin.php` exists, but its
header comment is about *"switching the shop out of every search index"*, which
reads as the site's **web** address (host), not a postal one. **Confirm before
assuming a postal-address field already exists.** Do not reuse that screen
without reading `SiteAddressApiController`.

**Test:** a shop with a complete address emits `PostalAddress` inside the
Organization node; a shop with an empty or partial address emits the node
**byte-identical to today**. Mutation note: fill one field of three and the
"partial emits nothing" assertion goes red.

**Risk: low, with one guard that matters.** Emit nothing unless the address is
genuinely complete — a half-filled `PostalAddress` is worse than none. Ships at
today's value (empty), per rule 1, so applying the package moves nothing.

**Owner must supply:** the real postal address, emirate, postcode, telephone,
opening hours, and lat/long if a `geo` node is wanted. Search shows **Barsha
Heights** and **+971 58 505 2611** for us
([kbeautybliss.com](https://kbeautybliss.com/)) — **the owner must confirm these
rather than a lane trusting a SERP snippet.** They must match item 3's NAP
exactly.

---

### 5. Verify the competitor's hreflang the moment egress opens — **research**
**Impact: decides how hard to push item 2. Effort: one hour. Blocked on egress.**

`SEO-COMPETITIVE.md` §8 establishes that they are on the one Shopify
configuration — **a subdomain** — where hreflang is *not* emitted automatically
and must be hand-rolled or bought as an app
([help.shopify.com](https://help.shopify.com/en/manual/markets/customizations/domains-and-languages)).
Several of their **English-path** collection URLs also surface with **Arabic
titles**, which is one of the shapes an hreflang problem takes.

**This is now the top item in `SEO-COMPETITIVE.md` §6**, ahead of the JSON-LD
questions. If their hreflang is broken, their Arabic layer is worth far less
than it looks and item 2 is worth far more.

**One fetch of `ar.kbeautyarabia.com` and one of the English equivalent answers
it.** Nothing else in this plan is blocked on it.

---

### 6. Ingredient/concern article cluster mapped onto the collections — **CONTENT**
**Impact: high. Effort: large and ongoing. Blocked on the owner.**

`SEO-GAP.md` §12 gives the anatomy: pillar plus 8–15 cluster articles,
bidirectional linking, query-shaped anchor text, `Article` schema (**already
emitted**, `app/Support/Seo.php:1145`). Our pillar exists — `/skincare-guide/`
and the journal. The **mapping** does not.

**Proposed first six**, chosen where the demand evidence and the competitor's
own programme agree (`SEO-GAP.md` §10):

1. Cica / Centella / Heartleaf for redness → `sensitivity-redness`
2. Snail mucin → hydration / barrier
3. Niacinamide and vitamin C for pigmentation → `hyperpigmentation`
4. BHA/salicylic and gentle acne care → `acne`
5. "Glass skin", what it means and how to build it → dryness/hydration
6. Korean sunscreen for the Gulf climate → `sun-care`

Each links **down** to its concern collection and to 2–3 products, and **up** to
the pillar with anchor text containing the pillar term. Each ships in Arabic
(item 2).

**Owner must supply:** the article topics (this list is a proposal, not a
decision) and the articles themselves. **The numbers in `SEO-GAP.md` §12 are
practitioner convention, not Google documentation — do not put a word count in a
test.**

---

### 7. Arabic slugs — **CODE, but decide before building**
**Impact: medium, and it is the one thing the competitor structurally cannot
copy. Effort: medium-large. Risk: real.**

Their Arabic articles sit at English handles under English `/blogs/` and
`/collections/` segments, because Shopify cannot do otherwise
(`SEO-COMPETITIVE.md` §1.5, §8). Our routing is ours.

**Before anyone writes a line:** this touches canonicals, `alternateLinks()`,
the sitemap's `xhtml:link` clusters, the redirects table and every existing
Arabic URL already in the index. Changing a live URL costs the redirect and a
re-crawl. **Scoped to *new* pages only — the concern collections of item 1 and
the articles of item 6 — it is far cheaper and carries most of the benefit.**

**Recommendation: do not retrofit existing URLs. Ship Arabic slugs on new pages
only, and only after items 1 and 6 have content to put at them.** Sequence it
last among the code items.

**Owner must supply:** the Arabic slug for each new page, and a decision on
transliteration vs translation.

---

### 8. Check the `/skincare-sets/` duplicate on the live site — **investigation**
**Impact: unknown until looked at. Effort: 20 minutes. Nobody is doing it.**

`SEO-COMPETITIVE.md` §14: both
[`/skincare-sets/`](https://kbeautybliss.com/skincare-sets/) and
[`/product-category/skincare-sets/`](https://kbeautybliss.com/product-category/skincare-sets/)
are indexed with near-identical titles differing only by year ("…for Women in
2024" / "…in 2025"). That is the duplicate-content class Part I correctly says
this Laravel port does not have — **observed on the legacy WooCommerce site.**

It may already be answered by the port's routing or by the redirects table
(Store → SEO & Meta → Redirects & 404s). **I did not verify which, and it should
not be guessed at.** One person, one look: does the port serve both paths, and
does the redirect table already cover it?

**No code until that question is answered.** If the port serves one path only,
this is a redirect row, not a change.

---

### 9. A test that the four collection-key lists cannot drift — **CODE**
**Impact: low for ranking, high for not shipping a bug. Effort: small.**

Item 1 must add each new key in at least four places: `CollectionController`'s
key map (`:49`), its title/intro map (`:203`),
`PageController.php:62`, and `SeoFilesController.php:188`. **Four hardcoded
lists that must agree, with nothing enforcing it.** Miss the last one and the
page works perfectly and never enters the sitemap — a silent failure that no
screenshot catches.

**Test:** the key sets are equal. This is exactly the shape of
`SeoBilingualTest`'s existing assertion that `Indexability::PRIVATE_PREFIXES`
equals `SeoFilesController::ROBOTS_PRIVATE`, so there is a pattern to copy.
Mutation note: drop one key from the `SeoFilesController` list and it goes red.

**Build this with item 1, in the same package, before the keys multiply.**

---

### 10. Merchant listing: shipping and returns — **CODE gated on the owner**
**Impact: medium. Effort: none — it already exists. Blocked on confirmation.**

`Seo::jsonLd()` already emits `shippingDetails` and `hasMerchantReturnPolicy`
behind `enable_merchant` (`app/Support/Seo.php:1079`), off by default, and
correctly refuses to invent `returnMethod`/`returnFees`.

Search shows our live site advertising *"1–3 days free delivery on orders over
199 AED"* and *"free returns"* ([kbeautybliss.com](https://kbeautybliss.com/)).
**That is a SERP snippet, not a policy document.** If those terms are current,
switching `enable_merchant` on is a settings change with real rich-result value.

**Owner must supply:** written confirmation of the current shipping threshold,
cost, delivery window, return window and who pays return postage. **A wrong
shipping figure in a merchant listing is worse than no markup** — it is a
promise Google shows to a shopper.

**Rule-1 note:** the setting already exists at `off`. Turning it on is the
owner's decision, not a lane's default.

---

## Below the line — real, and not now

**11. Image `alt` coverage + an audit check** (Part I item 4). Unchanged and
still worth doing; needs a schema change for per-image alt text and touches the
product editor. Medium effort, medium impact — Google Images matters for beauty.

**12. Sitemap index split + chunked generation** (Part I items 5 and 6).
Unchanged: **build at ~20,000 URLs, not before.** We are near 1,400. Part I's
argument is correct and Part II found nothing against it.

**13. `robots.txt` — nothing to do.** Their indexed `.atom` feeds
(`SEO-COMPETITIVE.md` §10) are a Shopify housekeeping failure. We serve no
second machine-readable address per listing, so there is no equivalent rule for
us to add. **Noted so nobody adds a disallow for a problem we do not have.**

---

## Still "not worth it" — Part I's refusals all stand

Part I refused `FAQPage` JSON-LD, a second `SeoGraph` emitter,
`JSON_UNESCAPED_SLASHES` on the encoder, keyword-stuffed collection titles, and
chasing the competitor's app stack. **Part II found no evidence against any of
them and reaffirms all five.** The reasoning is in Part I and in
`SEO-COMPETITIVE.md` §1.9; it has not aged in a day.

Two more to add:

**❌ Copying their skin-type collections *before* the concern ones.** They run
both axes. Concern queries carry buying intent ("korean skincare for acne");
skin-type queries are closer to research. Both are worth having eventually;
doing skin type first spends the thin-page risk budget on the weaker axis.

**❌ Promising traffic numbers from this research.** `SEO-GAP.md` §10 is
explicit: **there are no search-volume figures in this document for any term in
either language**, because this project has no keyword tool and no Search
Console for any domain. The concern list is well-evidenced for *choosing pages*.
It will not support a forecast, and nobody should build a business case on it.
The cheap honest fix is **Search Console on `kbeautybliss.com`**, which the owner
already owns.

---

## What the owner must supply — the whole list, in one place

Development on items 1, 2, 3, 6, 7 and 10 **cannot start** without these.

| # | Item | What is needed |
|---|---|---|
| 1 | Concern collections | **Product-to-concern mapping** for the catalogue — *the biggest single blocker in this plan* |
| 2 | Concern collections | 150–300 words of intro copy per concern, **English + Arabic** |
| 3 | Concern collections | Go/no-go on three concerns vs six |
| 4 | `LocalBusiness` | Confirmed postal address, emirate, postcode, telephone, opening hours, optional lat/long |
| 5 | Directories | One canonical NAP string, used identically everywhere |
| 6 | Article cluster | Approved topic list (six proposed in item 6) and the articles |
| 7 | Arabic | Budget for a **human** Arabic writer — not machine translation |
| 8 | Arabic slugs | Decision: transliterate or translate; new pages only or retrofit |
| 9 | Merchant listing | Written confirmation of current shipping and returns terms |
| 10 | Outreach | Who does it, and a decision on the paid-directory tier |

## Sequencing for the integrator

- **Buildable today, no owner input:** items 4 (after confirming the address
  screen), 9, and the investigation in 8. That is roughly one lane's round.
- **Blocked on the owner:** 1, 2, 3, 6, 7, 10 — which is most of the impact.
- **Blocked on egress:** 5, and all of `SEO-COMPETITIVE.md` §6.
- Item 9 ships **with** item 1, in the same package.
- Item 7 sequences **after** items 1 and 6 have content.
- Item 3 should precede item 4 so markup and citations agree from day one.
