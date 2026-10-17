# Lane HT — Homepage "Product tabs" section: plan + previews

The owner: *"i need Tabs section on homepage like different tabs in one section,
like Best Sellers, Trending K-Beauty, New Arrivals etc. ... add new tabs with
title and products selection query via categories, brands etc and also manual
section, and also the sorting inside that section. also i need option as
carousel or grid ... give me multiple previews to choose from, for desktop and
mobile both."*

**This phase ships nothing.** No shop view, stylesheet, route, migration or
default changed. Previews: `docs/lane-ht/previews/`, built by `tools/ht-*`.

## 1. What already exists, so we reuse it instead of building it again

| Need | Already in the repo | File |
|---|---|---|
| A product section you can add several times, with a source, layout and order | **Grid sections** (Lane GS): table `grid_sections`, key `grid_<id>` added to Appearance → Homepage's order through `HomepageSections::registry()` | `app/Models/GridSection.php`, `app/Services/GridSections.php`, `partials/home/grid-section.blade.php`, `admin/partials/grid-sections-screen.blade.php`, `routes/grid-sections-admin.php` |
| Sources: best sellers, trending, newest, on sale, featured, one brand, one category (+ subcategories), manual pick in your own order, brands + categories mixed | `GridSection::SOURCES`, `GridSections::fetchPool()` / `applyQuery()` | same |
| Sorting | `ProductSource::SORTS` (best selling, trending this week, newest, price ↑, price ↓, on sale, featured, random-per-day), validated by `ProductSource::clean()` | `app/Support/ProductSource.php` |
| Carousel or grid **per device**, columns, cards-in-view with peek (2.3 etc.), arrows per device, laptop/phone counts from ONE query | `desktop_layout`/`mobile_layout`, `desktop_cols`/`mobile_cols`, `per_d`/`per_m`, `arrows_d`/`arrows_m`, `count`/`mobile_count`; CSS is `GridSections::css()` (calc() only, no measuring) | same |
| Tab switching (role=tablist, aria-selected, roving tabindex, ←/→ keys mirrored in RTL, hidden panels) | `[data-rp-tabs]` in **ymal.js**, already in the shop bundle and initialised on every page (product page's "More from brand / More category") | `resources/js/kbb/ymal.js`, `partials/product/recs-tabs.blade.php` |
| Carousel arrows that are right in RTL with no geometry read | `[data-ymal]` in ymal.js (IntersectionObserver for the ends; `[dir=rtl] .gs-arr svg` flip in kbb.css) | same |
| Card | `<x-product-card>` (srcset from `ImageVariants` built in) | `components/product-card.blade.php` |
| Arabic words for owner-typed text | `<key>_ar` pattern (`HomeArabic`) | `app/Support/HomeArabic.php` |

The previews use every one of these unchanged: the real layout, real card,
real `GridSections::css()`, real ymal.js. Only the tab-row looks are new.

## 2. Decision: Product tabs are a MODE of a Grid section, not a new section type

Add two nullable columns to `grid_sections`:

- `tabs` JSON — null/empty = the section is the ordinary grid it is today.
- `tab_style` string — one of the picked design's options (select stores one of its own options, else default).

Why not a new table/section type: a grid section already carries every layout
control the owner asked for, is already orderable / switchable per device on
Appearance → Homepage, already has an admin screen with a live preview iframe,
cache, flush, capability and seven tests (`GridSection*Test`). A new type would
copy all of that — the "two copies, four bugs" history in
`partials/home/grid.blade.php`. Why JSON for the tabs rather than a child
table: tabs are always read and written with their section, ≤ 6 of them, never
queried by content; that matches how `manual_ids` and `source_query` are
already stored on the same row.

**One tab** (cleaned on save; anything not in the allowlist is dropped):

```
{ title, title_ar,                      // ≤ 40 chars, plain text, printed escaped
  source:  bestsellers|trending|newest|onsale|featured|brand|category|manual|query,
  brand_id, category_id, children,      // ids checked with ProductSource::existing()
  query:   ProductSource::clean(...)    // brands[] ≤20, cats[] ≤20, sort, in-stock
  manual_ids[] ≤ 24,
  sort:    one of ProductSource::SORTS, or 'manual' for a hand-picked list
  view_all_url }                        // SafeUrl::href(); refused => no button
```

Every source becomes a **spec** string exactly as `GridSections::specKey()`
builds one today; a sort on a preset source is expressed as the `query`
source (`category X + sort price↑` = `query{cats:[X], sort:price_asc}`), so
`applyQuery()` serves every tab and no new SQL is written. Manual tabs keep
the owner's order, or are sorted in PHP over ≤ 24 rows (no query).
Tags (Product → tags exist) are NOT a source today; adding `t=` to
`ProductSource::spec()` only when non-empty keeps every existing spec string
byte-identical — offered as a follow-up, see questions.

Limits: ≤ 6 tabs per section; products per tab = the section's own
`count` / `mobile_count` (1–24, default 8 / 8), same number on every tab so
the row height never jumps when a tab changes.

## 3. How tabs switch: all tabs rendered once, zero requests

All panels are in the HTML; panel 1 open, the others `hidden`
(display:none). Tab buttons are `role=tab` inside `role=tablist`, panels
`role=tabpanel` with `aria-labelledby`. Switching is ymal.js's existing
`[data-rp-tabs]` code: **0 new bytes of JavaScript, 0 requests**.

Measured on the preview (Chromium, `tools/ht-shots.cjs`):

- Clicking tab 2 made **only image requests** — 3 (phone) to 5 (laptop),
  the newly visible cards' `img-cache/400` copies. No fetch/XHR, on all 14 runs.
  Hidden panels' lazy images are not fetched until opened.
- ArrowRight (ArrowLeft in Arabic) moves to the next tab and focuses it — 14/14.
- Every visible tab answers a real click at its centre (`elementFromPoint`);
  tabs past a phone's edge are reached by swiping the chip row.
- `scrollWidth == width` on all 14 shots; console errors: none from the page
  (one `favicon.ico` 404 from the bare preview webroot, first context only).

HTML weight (preview, real card with srcset): one panel of 8 cards =
**12.5 KB raw / 1.5 KB gzipped**; 5 tabs × 8 (option B) = **58 KB raw /
3.2 KB gzipped** for the whole section (the same products repeat across tabs
and compress well). Real catalogue names are longer: budget ~2 KB raw per card,
so the default 4 × 8 = 32 cards ≈ 65 KB raw / ~6–8 KB on the wire. A request
per tab instead would cost a round trip (150–400 ms on a phone) per click, a
new unauthenticated `/api` surface and a second cache — server-rendering wins.

Images: the first tab's first row follows `SiteLayout::aboveFoldCards()` only
if the section is first on screen (as grids do today); every other card is lazy.

## 4. Queries — flat in catalogue size, 0 when warm

- `GridSections::forHome()` already collects every published section's spec
  into `$wanted` and fetches each DISTINCT spec once. Tabs add their specs to
  the same map, so a tab and a grid section showing the same selection cost one
  query, and two tabs with the same spec cost one.
- Cold: **2 queries per distinct tab** (products + the brand eager-load),
  measured; a category tab with "include subcategories" adds one category-tree
  read (`categoryAndDescendants()`, uncached today: the build memoises it once
  per cold fill so N such tabs cost 1, not N). 4 tabs = 8 queries once.
- Warm: **0** — it lives inside the existing `kbb.home.gridsections.home`
  cache entry (TTL 600 s, flushed by every grid-section save, as today).
  Homepage budget in `StorefrontQueryBudgetTest` (ceiling 5) is untouched.
- Flat in catalogue: every query is `LIMIT count`; proved the way
  `GridSectionQueryCostTest` proves it (12 vs 60 products, same count).

## 5. Layouts

Per section, per device — the existing grid-section controls, no new ones:
Laptop layout (Grid | Carousel), Phone layout (Grid | Carousel), columns,
cards-in-view with peek, arrows laptop / phone, laptop count / phone count.
Grid = `.kbb-pgrid.gs-grid`; carousel = `gs-car-d` / `gs-car-m` scroll-snap
over one `calc()`. RTL: flex/inline axis + `inset-inline`, arrows flipped by
kbb.css; the previews show the Arabic carousel scrolled (scrollLeft −332/−949).

New CSS: only the chosen tab-row style, ~0.6–1 KB constant, appended to
`GridSections::css()` which is printed inline only when a grid section draws
(no new request, nothing render-blocking added). No `[dir]` selector needed.

## 6. Admin

**Appearance → Grid sections → [section] → Tabs** (new tab in the existing
editor): "Show as tabs" switch; a list of tabs (add / remove / ↑↓, max 6);
per tab: Title, Title (Arabic), Products from (the source select), the
brand / category / mixed picker and manual picker the screen already has,
Sort, View-all link. Layout, columns, counts, heading stay on the tabs they are
on now. **Appearance → Grid sections → + New → "Product tabs"** preset creates
one with Best Sellers / Trending K-Beauty / New Arrivals filled in.
Its order and Laptop/Phone switches are where every grid section's are:
Appearance → Homepage, and Homepage content → All sections.
Same endpoints and capability as grid sections (the Tabs payload is one more
field of `PUT /admin-api/grid-sections/{id}`), so no new route file and no
route-cache migration beyond the column migration's own `clear_caches_*`.

## 7. Security / validation

Titles plain text ≤ 40, escaped (`{{ }}`); source, sort, tab_style each a
select that stores one of its own options or the default; ids intersected with
existing rows; ≤ 6 tabs, ≤ 24 manual ids, ≤ 20 brands/cats; view-all through
`SafeUrl::href()`; panel ids `gs-<id>-t<n>` (integers only). Nothing printed raw
but constants. No `/api` change.

## 8. Tests that will lock it

- `GridSectionTabsShapeTest`: tablist/tab/tabpanel roles, one open panel,
  ids unique, no `<script>`, no measuring API, every card via `<x-product-card>`.
- `GridSectionTabsQueryCostTest`: 0 warm; cold = distinct specs × 2; same at
  12 and 60 products; tabs sharing a spec with a grid cost nothing extra.
- `GridSectionTabsValidationTest`: junk source/sort/style → default; 7th tab
  dropped; unknown ids dropped; `javascript:` view-all → no button; Arabic title
  only on /ar.
- `GridSectionTabsShipsOffTest` + `StorefrontEnglishUnchangedTest`: a section
  with `tabs` null prints exactly today's bytes; the home page is unchanged
  until a tabbed section exists (or is shipped on — see Q1).
- Browser (lane report, not CI): 390/1280 shots, click + keyboard + RTL, no
  console errors, prefetch still fires on product links, before/after ms,
  queries, HTML bytes, CLS for home/category/product/brand.

## 9. Risks and how they are avoided

- `store/home.blade.php` is untouched: tabbed sections ride the existing
  `@foreach ($gsRows …)` loop; the partial branches on `$section->tabs`.
- `grid-section.blade.php` is shared: the tabs branch is a separate
  `@include` so the non-tab path prints byte-identical output.
- Header / logo / payments / speed-frozen files: not touched.
- ymal.js is reused as is; its `data-rp-hint` path is only active when that
  attribute is printed, which tabs never print.
- Duplicate products across tabs: card has no element ids (checked: 0 duplicate
  ids on the preview page).
- Trending with no recent orders/views falls back to best-selling order (today's
  `orderTrending()`), so on a quiet week Trending and Best Sellers can look the
  same — the owner can switch that tab to a category or "random daily".

## 10. Previews (pick one letter for the tab row; layouts are per section anyway)

| | Tab row | Laptop | Phone |
|---|---|---|---|
| **A** | centred eyebrow + title, underlined text tabs | carousel, 5⅓ cards, arrows | same tabs swipe sideways, 2⅓ cards |
| **B** | left title, pill chips (filled when on) | grid 4 × 2 | chips swipe, grid 2 × 2 |
| **C** | centred segmented control (pink track, white thumb) | carousel 4½, arrows | segments wrap 2 × 2, 1½ cards + arrows |
| **D** | title left, text tabs right, rule under | grid 5 × 1 | title then pink chips row, carousel 2⅓ |
| **E** | the tabs ARE the headline (big words, dot under the active one) | carousel, arrows | big words swipe, 2⅓ cards |

Files: `<letter>-1280.png`, `<letter>-390.png`, `-tab2` (after clicking
tab 2), `-moved` (carousel after next / swipe); Arabic: `a-390-ar`,
`b-390-ar`, `d-390-ar`, `c-1280-ar` (+ their `-tab2` / `-moved`).
Rebuild: `sh tools/arf-preview.sh 9741 fresh`, then
`sh tools/arf-artisan.sh tinker --execute="require 'tools/ht-seed.php';"`,
`sh tools/ht-preview.sh 9751`, `node tools/ht-shots.cjs 9751`.

## 11. Questions for the owner

1. **Which letter** (A–E)? And should the other tab styles stay as a "Tab
   style" choice in the admin (≈ 0.6 KB CSS each), or only the one you pick?
2. **Does it replace** the separate Best Sellers and Trending rails, or sit
   beside them? (Same products twice on one page otherwise.) Where on the page?
3. Ship it **switched on** with Best Sellers / Trending K-Beauty / New Arrivals
   (your rule: what you ask for ships on), 8 products per tab?
4. Tags as a product source too, or are categories + brands + manual enough?
