# Lane FX — brand directory structured data, and the two "blocked" identifiers

Companion to `docs/FX-YOAST-TIER-CENSUS.md`, which covers the Yoast tier
question and carries the integrator anchors. This file covers the rest, and the
**three corrections to the plan** that came out of it. Lane FX may not edit
`KBB-Master-Plan.md`, so they are recorded here for the integrator to merge.

---

## Correction 1 — GTIN is no longer blocked

Phase 12 (structured data expansion):

> **Still open: GTIN and variant-level offers** — genuinely blocked, no
> GTIN/barcode column exists anywhere in the schema and there's no existing
> source of truth for it

`products.gtin` exists. `2026_10_05_000000_add_product_editor_columns` added it
— indexed, `string(14)`, under a header that calls it "THE PRODUCT IDENTIFIER
GOOGLE ACTUALLY WANTS". `App\Support\Gtin` validates the mod-10 check digit,
`ProductEditorApiController` refuses a value that fails it, and the product
editor collects it.

What was missing was the last step: **nothing published it.** `App\Support\Seo`
now emits `gtin` on the Product node, **re-validated at the wire**, which is not
the same check twice over — the admin form is one of three ways a value reaches
that column and the other two (the importer, a hand-edited row) do not pass
through that controller. A GTIN is the field Google *matches products on*, so a
transposed pair of digits does not make a weaker listing, it attaches this
shop's price and stock to a different product. A value that fails its own
checksum is published as nothing.

The remaining source-of-truth question is answered in the census document: the
Yoast WooCommerce SEO add-on's export carries barcodes, and the importer is
currently unable even to see the column.

## Correction 2 — variant-level offers were never blocked at all

They were listed beside the GTIN line and inherited its verdict. But
`product_variants` has carried `price`, `sale_price`, `stock_status` and `sku`
since the **original** schema migration, and `store/product.blade.php` prints a
price on every option row (`<span class="vp">`, from
`ProductVariant::effectivePrice()`).

So the page showed a shopper three prices while the document told Google one —
the parent row's. The Product node now publishes an `AggregateOffer`
(`lowPrice` / `highPrice` / `offerCount`, plus a child `Offer` per option with
its own price, SKU and stock status) **whenever there are two or more options at
two or more distinct prices**. A simple product, and a variable product whose
options all cost the same, publish the single `Offer` they always published,
byte for byte.

Two details worth keeping:

* The range is computed on the **integer minor units** and formatted once by
  `Money::decimalString()`. A range built from the raw column publishes 8,900
  AED for an 89 AED option — the fils bug caught in 2.60.36.
* `itemCondition`, `shippingDetails`, `hasMerchantReturnPolicy` and
  `priceValidUntil` stay on the aggregate (they describe the shop, and are the
  same for every option). `priceSpecification` moves **down** into each child,
  because it is the only one of them that names a price — left on the aggregate
  it would state the parent's single figure beside a `lowPrice` and `highPrice`
  that disagree with it.

The **Schema Inspector** was updated in the same change, and that is not
optional tidying: its whole promise is that nothing shown on it can drift from
what ships. It built its own product context, so both new fields would have been
absent from the preview and present on the page. Its warning list also read
`offers.price` unconditionally, so every variable product would have been
reported as "Offer has no price set" over a node carrying two — a warning that
fires on correct output is how an owner learns to ignore the warnings.

**Still genuinely blocked:** per-variant GTINs. `product_variants` has no `gtin`
column. The migration and the reasoning for *not* shipping it yet are in
§5 of `docs/FX-YOAST-TIER-CENSUS.md`.

## Correction 3 — `KBB-SEO-Feature-List.md` does not exist

Phase 12 cites it twice ("Full writeup: `KBB-SEO-Feature-List.md`") and this
lane's brief names it as required reading. There is no such file at `c6c74be`,
in the repo root or under `docs/`, and `git log --diff-filter=D` finds no
deletion. The plan's Phase 12 text is the only surviving record of that audit.

---

## The A–Z brand directory

Lane FQ shipped `CollectionPage`/`ItemList` for the category archives, the brand
landing pages and the four curated listings, and deliberately left
`BrandController::index()` alone — it lists brands rather than products and
passed no SEO context at all, so giving it one changes its title and
description defaults.

**What it published before.** Fetched from a running preview at
`/korean-skincare-brands/` before anything was touched:

```
<title>            All brands | KBB
<meta description> Shop Korean skincare in the UAE — serums, creams,
                   moisturisers and beauty devices from Korean beauty brands.
og:type            website
JSON-LD            Organization, WebSite. Nothing else.
```

The description is the store-wide default — the identical sentence the homepage
and the cart publish — and is about products this page does not sell.

**The title is deliberately unchanged.** No `title` key, no `title_is_final`.
"All brands" is already the right two words; replacing a correct title on the
way past would be the same accident in the other direction. Pinned byte for byte
by a fetched-page assertion.

**The description is the page's own sentence** — the paragraph under the `<h1>`,
`trans_choice('store.brands.all_subtitle', …)`, counted from the same query the
tiles are drawn from. It is now built **once**, in the controller, and passed to
both the view and the meta tag, because two copies of one string is the defect
this repository keeps paying for. Three alternatives were considered and
rejected, and the reasoning is in `BrandController::indexSeoCtx()`'s docblock:
the store-wide default (wrong, and duplicated across three page types), a new
marketing sentence (needs a key another lane owns, an Arabic translation this
lane cannot write, and states something the page does not), and a list of brand
names (truncates to the first six alphabetically).

**The list** is an `ItemList` of `Brand` nodes, built by
`App\Support\BrandDirectorySchema` — a separate builder from `CollectionSchema`,
because that class's entire body is about the price field and a brand tile has
no price. The three things FQ got right are preserved and each is tested:

* **Prices are integer fils.** There is no money on this page at all, so nothing
  here can publish 12,600 for a 126 AED serum. `Seo`'s collection branch is now
  explicit that a non-`Product` row never gets an `offers` key.
* **A filtered or sorted view carries no list.** This page has no facets, no
  sort and no pagination — one ungrouped query, every brand drawn — so the
  canonical is always self-referencing and the list is always this page's own
  rows, at offset 0.
* **Item URLs go through `Seo::canonical()`.** Two tests, not one: with
  `site_url` already carrying `/kbb-upgrade` (the live host) the duplicate
  prefix has to be collapsed, and with `site_url` carrying only the host
  `Url::to()` is the only thing supplying it. Each configuration catches a
  different mistake, and a test on the default base path catches neither.

`Brand::url()` is deliberately not used: URL Contract U-05 keeps it pointing at
`/shop/?filter_brands={slug}`, and canonicalising the landing page to the
filtered listing tells Google the landing page does not exist.

---

## Phase 16 — what is left, established by running it

Every module in `ModuleRegistry`'s `marketing` group reports status `live`, and
each was switched on and exercised through a real preview rather than read:

| module | what running it showed |
|---|---|
| `marketing_pixels` | Real. Meta, GA4 and TikTok loaders plus `PageView`/`AddToCart` on the homepage and `ViewContent`/`view_item` on a product page. Values in **dirhams**, not fils, and every number `json_encode`d. |
| `back_in_stock` | Real. "Tell me when this is back" renders under a sold-out product's button once the module is on **and** the owner has written the form label. |
| `abandoned_cart` | Real. The opt-in tick box is gated on `item_count > 0`, so it renders on a non-empty cart only. |
| `newsletter`, `product_labels`, Meta & Facebook | Already `[x]` in the plan. |

**So Phase 16 has nothing unbuilt.** What it had was **no tests at all** —
`ls tests/Feature | grep -iE 'pixel|abandon|back.?in.?stock'` returned nothing —
and the surface with the most to lose is the pixels, because they are the only
place in the shop that reports money to somebody else's system. A pixel
reporting 12,600 for a 126 AED sale does not look broken anywhere: the tag
fires, the event lands, and the shop's return-on-ad-spend is out by two orders
of magnitude in the direction that makes it look like it is winning.

`tests/Feature/MarketingPixelMoneyTest.php` pins that, the sale price, the
module's off state, and script-breakout through a product name.

### A trap worth naming, which cost this lane a cycle

There are **two** enable mechanisms and only one of them works. A `settings` row
called `module_marketing_pixels`, or bare rows called `ga4_id` / `meta_id`, are
read by **nothing**: the toggle lives in `module_toggles` and the IDs live under
the module's own namespace via `SettingsService::moduleSetting()`. Writing them
by hand produces a shop where every screen looks configured and no tag renders.
`MarketingPixels::save()` and a `ModuleToggle` row are the only paths that work,
and the test uses both.

### What was not picked, and why

* **Abandoned-cart and back-in-stock end-to-end send tests.** Both go through
  `OutboundTick`, which is a deferred sweep on the tail of ordinary requests
  because the host has no cron — so an honest test has to drive two requests and
  a clock, and it belongs with whoever owns `app/Services/Outbound*`.
* **A per-network event matrix for the pixels.** TikTok fires `page`,
  `AddToCart` and `CompletePayment`, which matches its own registry
  description; checking each network's full event list is breadth over an
  already-correct surface, where the money assertion is depth over the part
  that can silently cost real money.
* **`MarketingPixels::viewContent()` reports `$product->name`, not `t('name')`.**
  Left alone on purpose. An Arabic page reporting the English name to GA4 is
  arguably *right* — one `item_name` per product rather than two — and changing
  it is an analytics decision for the owner, not a defect to fix quietly.

---

# Lane E — the four Phase 12 `[~]` items, re-checked against the code

**The headline: three of the four were already done, and the plan's `[~]` marks
are stale.** This lane's brief opened "`products` carries no SEO columns at
all", which is the opposite of what the plan text at that very line now says.
Verified in the code rather than read off the plan:

| Item | Plan line | State found | Left |
|---|---|---|---|
| Per-product SEO storage | 1782 | **Done.** `products.seo` is in the Phase 0 schema; the editor's *Search appearance* panel writes it through `ProductSeo::normalise()`; `Store\ProductController::show()` reads it | nothing |
| Yoast importer | 1806 | **Done.** `SeoImporter` is entity #N on `ImportRunner` (line 164), not a side script | the tier question, which is the owner's |
| Structured data | 1812 | **Done.** The named remainder — category pages on plain `website` — is gone; they publish `CollectionPage` + `ItemList` | nothing |
| Image pipeline · cache | 1824 | **Done in code, off by default.** `CacheHeaders` is appended to the `web` group by `AppServiceProvider`; `cache.headers_enabled` ships `false` | one manual `.htaccess` placement by the owner |

Measured on a real preview, every page type, JSON-LD parsed rather than grepped:

```
home       Organization, WebSite(+SearchAction)
shop       Organization, WebSite, CollectionPage, BreadcrumbList
category   Organization, WebSite, CollectionPage, BreadcrumbList
product    Organization, WebSite, Product(+Offer), BreadcrumbList
brands A–Z Organization, WebSite, CollectionPage, BreadcrumbList
cart       Organization, WebSite
```

Cache headers, measured with the switch off (how it ships) and on:

```
off   /  /shop/  /cart/  /my-account   no-cache, private          (unchanged)
on    /  /shop/                        max-age=0, must-revalidate, no-cache, private
on    /cart/  /my-account              ... + no-store
```

So the switch is real and it is not "built, never wired up". It stays off.

## The defect this lane actually found: `%%title%%` was deleted, not resolved

**Yoast's shipped default title template for a product is
`%%title%% %%sep%% %%sitename%%`**, so it is what most of a real export carries.
`SeoImporter` stores it verbatim — correctly; expanding tokens at import time
would freeze this store's name into every row.

`App\Support\Seo::titleOf()`'s `title_is_final` branch rendered that template
with `sep`, `sitename` and `page` and **not** `title`, and
`TitleTemplate::render()` DELETES a token it is not given. Measured on a real
product page through a real HTTP request, before and after:

```
before   <title>K-Beauty Bliss</title>
         <meta property="og:title"  content="K-Beauty Bliss">
         <meta name="twitter:title" content="K-Beauty Bliss">

after    <title>Heartleaf Quercetinol Pore Deep Cleansing Foam | K-Beauty Bliss</title>
         og:title and twitter:title likewise
```

Those three tags are the **only** lines that differ between the two documents;
the body is byte-identical. Importing the owner's export would have published
the same six words as the title of all 671 product pages — and as the share-card
title — while the import reported every row imported successfully. It is silent
on the shop, because the page itself renders perfectly either way.

It could not be fixed by adding `'title' => $rawTitle` to that array: in that
branch `$rawTitle` **is** the template, so that substitutes the template into
itself. `%%title%%` in Yoast means the *post* title — here the product's own
name — which only the caller knows. So the caller passes it as `title_token`,
and the branch is byte-identical to before when it is absent. Only
`Store\ProductController` passes it, so no other page's title can move.

**Still physical, and deliberately not changed:** brand, category and page SEO
overrides go through the same branch and still delete `%%title%%`. Nothing
writes a Yoast template into those columns — `SeoImporter` resolves its row to a
`Product` by `wc_id` and rejects anything else — so the only way to get one
there is to type it by hand. Fixing it means each of those controllers passing
its own natural title, which is four more files across two other lanes' areas
for a case no import can produce. Named here rather than half-done.

## The test that was missing, and why it was the expensive kind of missing

`tests/Feature/YoastImportReachesTheHeadTest.php`. Before it,
`YoastSeoImportTest` asserted what the importer writes to the model and issued
**no HTTP request**; `ProductSeoTest` asserted the rendered `<head>` and **never
ran the importer**. Each half was green over a join that was broken — which is
exactly the shape of the two defects this feature has already shipped (the
`seo_json`/`seo` column split, and the `seo_title`/`title` key rename). Both
were live while both halves had passing tests.

## Not fixed, and not this lane's to decide

**A variable product's `products.price` is NULL and the tile reads AED 0.**
`Product::effectivePrice()` ends `return (int) $this->price`, and `(int) null`
is `0` — so the tile prints AED 0 and the product sorts first by price.
`App\Support\Seo` no longer publishes `{"price":"0.00"}` (it publishes a real
`AggregateOffer`), so the document is right while the tile is wrong. Backfilling
the column breaks import idempotency unless `ProductImporter` stops writing null
over it in the same change, and `ProductImporter` is Lane A's. **Reported, not
decided.**
