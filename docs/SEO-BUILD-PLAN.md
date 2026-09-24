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
