# Labels: plan (preview only, nothing built)

The owner asked on 15 Nov 2026 for a labels page where he can make as many labels as he wants (Best Seller, New Arrival, ...) and put each one on products picked by hand, by category, or both at once. He wants the product search to be fast and to find a wide range of products. He asked for previews before any development.

- **Preview:** `preview.html` (one self-contained file that works offline). Open it in a browser and click around. It has 300 sample products, and a checkbox scales them to 3,000.
- **Shots:** `shots/*-390.png` and `shots/*-1280.png`, taken in Chromium. Every section has no console errors, makes no network request, and has `scrollWidth` equal to the viewport width.

## What exists today (read, not changed)

- `App\Services\ProductLabels` is the automatic module `product_labels`. It is **off by default** and draws one badge, chosen in this order: sold out, sale, new, bestseller. Its screen is at Growth & Marketing → Product Labels. `components/product-card.blade.php` draws that badge in the card's top-start corner. When the module is off, the theme draws its own New/Bestseller pills in the top-start corner and the -N% pill in the top-end corner. Both are off by default under Appearance → Product styles. `partials/product-gallery.blade.php` draws the badge on the product page.
- `CardFragments` caches card HTML. Its signature covers the row, the clock (which includes "the label ProductLabels chooses"), the words and one settings hash. Custom labels must be added to that signature, or cached cards will show old labels.
- Existing admin product searches: `ProductTabsApiController::search`, `NewArrivalsApiController::search` and the Catalog list. Each one runs `LIKE '%term%'` over name, SKU and slug (the Catalog list also searches brand) and returns 20–30 rows. They do not support words in any order or Arabic names. The shared type-ahead UI is `admin/partials/product-picker.blade.php`. Arabic names already exist, normalised, in `search_texts_ar` (`ArabicSearch`). The shop's synonyms are in `SearchTerms::GROUPS`.

## Data

```
product_labels           id, name, name_ar, text, text_ar, bg, fg, shape (A|B|C|D), placement (cards|page|both),
                         starts_at, ends_at (nullable dates), priority (1–99), active, timestamps
product_label_targets    label_id, kind (product|category|exclude), target_id, with_children (bool, category only)
```

The database stores **rules, not resolved lists**. A label saved with "Sun care, including sub-categories" covers a sunscreen added to Sun care next week. Nobody has to edit the label again.

## How a label resolves to products

`(picked products) ∪ (products in ticked categories, plus their descendants via categories.path when with_children) − (excluded)`

Mode "products" ignores the category rows, and mode "categories" ignores the picks. Rows are kept when the mode changes, so switching back loses nothing.

## Shop pages add no queries

- On save, label, product or category-assignment changes, one builder runs two queries: active labels plus one join over `category_product`. It writes a single cache entry, `kbb.labels.v1`, holding `product_id → [label ids]` and the label definitions. A save **writes** the entry instead of just deleting it, so a shopper never hits a cold build.
- A shop page reads that one entry once per request, memoised like `SettingsRequestMemo` and never per card. Date windows and the two-per-card cap are worked out in PHP from data already loaded. The goal is **0 extra queries**, and `StorefrontQueryBudgetTest` stays unchanged. A brand page with 3 products and one with 40 must make the same number of queries, proven the way `BrandPageOwnerAsksTest` does.
- The `CardFragments` signature gets the ids of labels that are active now, so a label edit or a start/end date changes the cards on the next view.
- Before reporting, measure server ms, queries and settings-map reads for product, category and brand pages, before and after (the Speed freeze in CLAUDE.md). The labels add no new script, stylesheet or request. The badge CSS is about 1 KB added to `kbb.css`, with no `color-mix()` (soft-tag colours are precomputed hex) and no layout-measuring JavaScript.
- **No product carries a label until the first one is saved.** Until then the card HTML stays byte-identical, and `StorefrontEnglishUnchangedTest` should remain green.

## Admin search: an index sent once (chosen) vs a debounced endpoint

The search index is chosen. When the editor opens, it fetches `GET admin-api/labels/index` **once**. The response is about 3,000 rows of `[id, name, brand, sku, ar name, cat ids, price, 64px thumb]`, roughly 100 KB gzipped. It is cached on the server until a product, brand, category or translation is saved, and sent with an ETag so a repeat visit downloads nothing. After that, each keystroke runs in memory: every word has to match name, brand, SKU, Arabic name or category, in any order, using the shop's Arabic normalisation and synonyms. That took about 1 ms per keystroke at 300 products and 7–11 ms at 3,000 in Chromium here. The list draws 50 rows per page. Saving is one POST of rules. Category counts come from the same index, so the green total needs no request either.

Why not a debounced endpoint: `LIKE '%..%'` cannot use an index, any-order multi-word matching is awkward in SQL, and a Cloudways round trip on every pause would feel slower than this. If the catalogue grows past about 20,000 products, the same UI switches to one 250 ms-debounced request against an indexed search table.

**Security.** The new endpoints get their own capability, `labels.manage`, and fail closed. The colours are hex-validated through `ModuleSchema` (the same repair that `ProductLabels::POLICY` uses). Shape and placement are stored only as one of their own options. Badge text is escaped (max 22 characters). Label ids are checked against rows that exist. Labels are not exposed on `/api/*` beyond text and colours in an allowlist.

## Coexisting with the automatic badges (proposal E)

- Custom labels go in the **top-start** corner (top-right on /ar), stacked, at most two per card in priority order.
- The automatic **sale** pill keeps the **top-end** corner it uses today. **Sold out** always wins that corner.
- The automatic **New/Bestseller** pills give way when a product has a custom label, so a card never shows both "Bestseller" and "Best Seller".
- On the product page, labels go on the main photo and as chips under the title (placement "page" or "both").
- On a 390 px card, when the other corner has a pill, labels shorten with an ellipsis rather than overlap. Badge text is capped at 22 characters.

## Proposed admin path

**Catalog → Labels**, a new entry in `AdminNav` placed after Best Sellers. Its "Automatic badges →" tab links to the existing **Growth & Marketing → Product Labels** screen, which stays where it is (option K). This is wired by the integrator from a lane-owned routes file and screen partial.

## Questions for the owner (letters as in the preview)

1. **Look:** A pill, B ribbon, C corner sash or D soft tag. Or should each label pick its own shape (the editor already shows that control)?
2. **Next to automatic badges:** E custom first, sale stays opposite (recommended); F custom replaces every automatic badge; or G automatic first.
3. **How many per card:** H one, I up to two (recommended), or J up to three.
4. **Where in the admin:** K Catalog → Labels plus the existing automatic screen (recommended), or L one screen with two tabs.
5. Should "Best Seller" and "New Arrival" labels be able to **follow** the existing Catalog → Best Sellers / New Arrivals lists automatically, as a third source next to products and categories?
6. Should a label show on a **sold-out** product? The proposal is yes, with the Sold out pill in the other corner.
7. Should labels also show in **search suggestions and the mini cart**? The proposal is no: only cards and the product page.
