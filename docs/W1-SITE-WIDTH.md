# Lane W1 — one site width, and a grid that derives its own column count

The owner's request, verbatim:

> "site width max i need 1680 px, but it must be auto adjust in below width
> screens, and for mobile is fine. please make super strong options and features
> for this. the site should fit on any kind of device automatically, and on
> 1680px the grid products will show 1 column extra, and in low, one less and so
> on, give options to control also for the whole layout. please don't assume,
> give it to an empty lane."

He asked for this not to be guessed at, so **the real state was established
first and it was not what the brief said.** Everything below was measured in
real Chromium against a seeded shop, on two checkouts served side by side, at
seventeen viewport widths, in English and in Arabic.

---

## 1 · The real state, and the first correction

**The brief said 172 `max-width` declarations of 43 distinct values. That count
is wrong in a way that changes the whole job.** Extending the grep to the forms
this codebase also uses — `max-width: 900px` with a space, `calc()`, `clamp()`,
`ch`, `vw`, `min()` — brings the total to **217**. And of those 217:

| | count | distinct values |
| --- | --- | --- |
| **media-query breakpoints** (`@media (max-width: …)`) | **142** | 30 |
| **element widths** (a real `max-width` on a box) | **75** | 29 |

So two thirds of them are not widths at all, they are responsive breakpoints.
Of the 75 element widths, **fourteen are page containers** and **sixty-one are
measures**.

### The fourteen page containers, and the six values they disagreed on

| value | where | what it is |
| --- | --- | --- |
| **1400px** | `kbb.css` `.wrap` — **declared twice** | the generic page |
| ~~1200px~~ | `kbb.css:14` `.wrap` | **dead.** Same selector, same specificity, overridden 1,456 lines below. Anyone who read either line read a number the shop does not use. |
| **1352px** | `kbb.css` `.kbb-home .sec > .wrap` | the home page — the one page that matters most |
| **1240px** | `kbb-shop.css` `.wrap` | `/shop` and every category |
| **1180px** | `kbb-product.css` `.wrap`; `store/brands` `.brw` | a product page; the brand landing and every brand page |
| **1160px** | `store/blog` and `store/post` `.wrap` | the Journal and an article |
| **1080px** | `store/review-wall` `.page`; `store/routines` `.rtn-wrap` | the review wall; the routine builder |
| **1040px** | `kbb-cart` / `kbb-account` `.wrap`; `.co-grid` | cart, account, checkout |
| **1280px** | `header .wrap`, on `--hd-max` | the header — already a real setting |

### The sixty-one measures, which must NOT become 1680

A 720px article column, a 440px form, a 340px card, `62ch` of prose, `44ch` of
banner copy, `50ch` of section sub-heading, `85vw` of mobile drawer. **A measure
is not a site width.** Widening a paragraph to 1680px does not make the shop
wider, it makes it unreadable. They keep their own names in `kbb.css`
(`--measure-prose`, `--measure-article`, `--measure-form`, `--measure-card`,
`--measure-lede`) and nothing in this lane touches them.
`SiteWidthSystemTest > it keeps the reading and form widths off the site width`
is what stops the next sweep catching them.

### Where the product grid column count was decided — and it was SIX places, not four

The brief named four. Measuring the **rendered** count found two more, both
inside `kbb.css` itself, because **that sheet contains a second, complete copy
of `kbb-grid-skins.css`** — about 180 rules, including its own column ladder at
the very END of the file:

| # | selector | sheet | how the count was set |
| --- | --- | --- | --- |
| 1 | `.kbb-pgrid` | `kbb-grid-skins.css` | `repeat(4,1fr)` + `1180→3` + `900→2` |
| 2 | `.kbb-pgrid` | `kbb-grid-skins.css` | a second trio on `--kbb-cols` / `-t` / `-m` at 1181/1180/900 |
| 3 | `.kbb-pgrid` | **`kbb.css`, line ~1462** | `repeat(4,1fr)` + `1100→3` + `900→2` — **the one that actually won**, because it is last in the sheet every page loads |
| 4 | `.kbb-pgrid` | **`kbb.css`, line ~1130** | a fourth `repeat(2,1fr)` inside a phone block |
| 5 | `.grid[data-cols]` | `kbb-shop.css` | `repeat(2\|3\|4,1fr)` from the shopper's buttons + `1080→3` + `820→2` |
| 6 | `.rel` | `kbb-product.css` | `repeat(4,1fr)` + `880→2` |

Plus `.brw-grid` on the brand landing, which **already** used
`repeat(auto-fill, minmax(min(var(--brw-min),100%),1fr))` — the one surface that
had it right, and the precedent this lane followed.

**They disagreed with each other at the same screen width.** Measured, before
anything changed:

| viewport | homepage rails | /shop |
| --- | --- | --- |
| 834px | **2** | **3** |
| 1180px | **3** | **4** |

Same shop, same screen, two answers, because two sheets had each picked their
own breakpoints.

### ▲ Two of the three column settings on Appearance → Product styles have never worked

`ProductStyles::cssVariables()` is the only writer of `--kbb-cols-t`,
`--kbb-gap`, `--kbb-radius` and `--kbb-ratio`, and it is called from **exactly
one place in this repo**: `resources/views/admin/app.blade.php`. So
**Columns · tablet**, **Gap between cards**, **Card roundness** and **Image
shape** have never moved a pixel of the storefront. `Columns · desktop` and
`Columns · phone` reached one grid of five, through
`components/product-grid.blade.php`'s inline style. See §7.

### The header, and its known defect

`docs/LANE-BRIEFS.md` round 2 records: *"The site header overflows to
`scrollWidth 1347` at 1280 on five pages — eleven seeded nav entries that do not
fit."* **That is already fixed and this lane did not fix it.** Measured on the
pre-change tree with **twelve** top-level menu entries, at all seventeen widths
and on eleven pages: `scrollWidth == clientWidth` everywhere, and the header
container is 1280px from 1280 upward. `resources/js/kbb/nav-fit.js` lowers
`--nav-scale` until the row fits, and `.mbar .wrap` is `flex-wrap: wrap` — both
landed after that note was written. **The note is stale and should be struck.**

**Does widening the site to 1680 make it better or worse? Neither, by default,
and better on request.** The header is capped by its own `--hd-max` (1280,
Appearance → Header → Content width, a slider whose range stops at 1600), so
the site width does not reach it at all: measured identical at every one of the
seventeen widths, before and after. The new **Header follows the site width**
switch, off by default, puts it on `--site-max` — which gives the nav 400px more
room at 1680 and lets `--nav-scale` return to 1. It can only improve the fit,
never worsen it, because the container only ever gets wider.

---

## 2 · What was built

### One width, expressed once

`resources/css/kbb/kbb.css` `:root` now carries the system, and every page
container references it:

```css
--site-max:1680px;
--site-gutter-min:22px;
--site-gutter-max:22px;
--site-gutter:clamp(var(--site-gutter-min),2.2vw,var(--site-gutter-max));

.wrap{max-width:var(--site-max);margin-inline:auto;padding-inline:var(--site-gutter)}
```

`min(100%, --site-max)` — which is what `max-width` on an auto-margin box is —
**is the whole of "1680 at the top and auto-adjusting below it"**. Below 1680
the screen wins and the container is the screen less its gutters; above it the
cap wins. There is no breakpoint to maintain and no width at which the answer is
undefined, which is what *"fits on any kind of device automatically"* has to
mean to be testable.

The gutter ships as a **constant 22px** — `clamp(22px, 2.2vw, 22px)` is 22px at
every width — because 22px is exactly what the generic `.wrap` already used. The
clamp is the mechanism; the owner's two sliders are its ends.

### The column count, derived from the row

One declaration, no media queries, in `kbb.css`:

```css
.kbb-pgrid,.rel,#grid{
  display:grid;
  gap:var(--kbb-gap);
  grid-template-columns:repeat(auto-fill,minmax(min(
      100%,
      calc((100% - (var(--kbb-cols-floor) - 1) * var(--kbb-gap)) / var(--kbb-cols-floor)),
      max(
          var(--kbb-tile),
          calc((100% - (var(--kbb-cols-cap) - 1) * var(--kbb-gap)) / var(--kbb-cols-cap))
      )
  ),1fr));
}
```

Three terms, innermost first:

- `max(--kbb-tile, cap-share)` — the track is at least the tile minimum, and at
  least a `--kbb-cols-cap`-th of the row, which is what stops `auto-fill`
  placing more columns than the cap.
- `min(…, floor-share, 100%)` — but never more than a `--kbb-cols-floor`-th of
  the row, which **guarantees** the floor. At 320px a 260px tile plus a 16px gap
  does not fit twice, and without this term the narrowest phone would drop to
  one full-width column.

**Every length in it resolves against the grid, not the viewport**, and that is
the point rather than a detail.

### ▲ `auto-fill` versus container queries versus a `vw` ladder — weighed, and why `auto-fill` won

The brief asked for this to be defended. All three were built and measured.

**A `vw`-scaled tile** (`calc(100px + 10vw)`) reproduces today's counts best in
the middle of the range — and then **falls from five columns to four between
1680 and 2560**, because the viewport keeps growing while the container is
capped at `--site-max`. A wider screen showing *fewer* products is the exact
defect being removed. Rejected on measurement.

**Container query units** (`calc(100px + 12cqi)`) fix that — `cqi` is a percent
of the *container*, which stops growing at 1680 — and were the first draft.
Rejected on three grounds, in order of weight:

1. **They move more of the table.** A `cqi`-scaled tile moves **seven** cells of
   the seventeen-width table; a constant tile moves **four**. Rule 1 prefers the
   smaller diff.
2. **They need `container-type` on each grid's parent**, which means either
   `:has(> .kbb-pgrid)` (baseline Dec 2023) or a wrapper element in five
   templates — and a wrapper element is a markup change, which moves the English
   pin on five pages for nothing.
3. **They buy nothing here.** The count is *already* container-derived, by
   `100%` and `1fr`, in every browser since 2017. `cqi` would let the tile
   **scale** with the container; nothing in this design wants that.

**Browser support, stated plainly.** `repeat(auto-fill, minmax(min(…), 1fr))`
needs CSS Grid (2017) and `min()`/`max()` (Chrome 79, Safari 13.1, Firefox 75 —
spring 2020). Every browser that can render this shop today can render this
rule. Container queries would have cost the Safari 15 tail for no gain.

**What container queries ARE for, and where the next round should reach for
them:** changing a card's *internal* layout by the space that card has — a
horizontal card below 260px of tile, say. The 28 skins in
`kbb-grid-skins.css` do that job by other means today. If that changes,
`@container` is the right tool, and this paragraph is the note saying so.

### ▲ An exact pinned count is a RULE, never a custom property

The first draft pinned with `--kbb-count:4` plus `--kbb-track:minmax(0,1fr)`,
and undid it on phones with `--kbb-track:initial`. **On a custom property
`initial` is the guaranteed-invalid value, not "revert to the inherited
declaration."** So `var(--kbb-track)` substituted nothing,
`repeat(auto-fill, )` is invalid at computed-value time, and the whole
declaration became `grid-template-columns: none`: every product card in one
full-width column, on every phone.

A custom property **cannot be locally reverted to the value it inherits**, so
anything that must be *undone* at a breakpoint must not be one. Pins are
`grid-template-columns` rules inside `@media (min-width: 901px)`, and below that
width nothing matches.

### ▲ A `var()` inside a custom property resolves where it is DECLARED

The second draft declared the whole `minmax(…)` as `--kbb-track` on `:root`. A
`var()` inside a custom property's value is substituted **at the element where
the property is declared**, and the resolved token stream is what inherits — so
`:root`'s `--kbb-tile: 260px` was baked in and `kbb-shop.css`'s
`#grid{--kbb-tile:220px}` could not reach it. Measured: the shop listing
rendered **three** columns in a 1002px row where the arithmetic said four, with
`--kbb-tile` computing to `220px` on that very element. The expression is
inlined into the one rule that uses it instead.

### ▲ /shop needs its own tile minimum, and the arithmetic says so

At a 1280px viewport the homepage rails have a **1203px** row; `/shop` has
**1002px**, because a 250px filter rail and a 28px gap sit beside it. Both show
four columns today — 300px tiles there, 240px here — and that is a deliberate
difference: a dense listing wants narrower cards than a curated rail.

One tile minimum cannot serve both:

- four and **not five** in a 1203px row needs a tile **above 227.8px**;
- four and **not three** in a 1002px row needs a tile **at or below 227px**.

There is no number in both ranges. So there are two sliders: **260px** for the
skinnable grid and **220px** for the shop listing. That is the sidebar problem
stated as arithmetic, and it is also the reason a viewport breakpoint could
never have got this right.

### ▲ The gap is a variable, not a `gap`

The track's floor term divides the row by `var(--kbb-gap)`. Declare 18px as a
plain `gap` on `#grid` and the guarantee is computed with 16 while the browser
lays out with 18: at 320px that is two 138px tracks plus an 18px gap in a 292px
row — **2px of horizontal page scroll on the narrowest phone**, out of a
disagreement between two numbers that do not look related. Every surface sets
`--kbb-gap` and the shared rule reads it for both jobs.

### /shop's default became automatic, and that is a real change

`Facets::columns()` answers `'4'` whether or not `?cols` is in the URL, so
"four across" was both the shop's only setting and its default, and the two were
indistinguishable. `data-cols` is a **pin**, so emitting it unconditionally
pinned every visitor at four columns at every screen size including 1680 and
2560 — which is exactly what the owner asked to have removed.

The attribute is now emitted **only when the shopper has chosen**, and the
button highlight follows the same fact, because a highlighted "4" above a
five-column grid is the control lying about the page. A click still pins
instantly (`resources/js/kbb/shop.js` sets the attribute itself). Reloading
without `?cols` returns to automatic.

---

## 3 · The width table

Seventeen widths, both trees, real Chromium, reading each grid's computed
`grid-template-columns` — the resolved track list, not a breakpoint anybody
guessed at. Produced by `tests/browser/lane-w1-width-sweep.mjs`.

**`fits` is `document.documentElement.scrollWidth` against `clientWidth` across
fourteen pages** (`/`, `/shop/`, a category, a product, a brand page, the brand
landing, `/cart/`, `/checkout/`, the Journal, the review wall, a CMS page,
`/my-account/`, the quiz and the wishlist). `OK` means every one of the fourteen
fits.

| screen | page container | product columns | fits (scrollWidth vs clientWidth) |
| --- | --- | --- | --- |
| **320px** | 320 | rails 2 · shop 2 | see below → OK |
| **360px** | 360 | rails 2 · shop 2 | OK → OK |
| **390px** | 390 | rails 2 · shop 2 | OK → OK |
| **414px** | 414 | rails 2 · shop 2 | OK → OK |
| **480px** | 480 | rails 2 · shop 2 | OK → OK |
| **600px** | 600 | rails 2 · shop 2 | OK → OK |
| **768px** | 744 | rails 2 · shop **2 → 3** | OK → OK |
| **834px** | 810 | rails 2 · shop 3 | OK → OK |
| **1024px** | 1000 | rails 3 · shop 3 | OK → OK |
| **1180px** | 1156 | rails **3 → 4** · shop **4 → 3** | OK → OK |
| **1280px** | 1256 | rails 4 · shop 4 | OK → OK |
| **1366px** | 1342 | rails 4 · shop 4 | OK → OK |
| **1440px** | 1352 → 1416 | rails 4 · shop 4 | OK → OK |
| **1536px** | 1352 → 1512 | rails **4 → 5** · shop **4 → 5** | OK → OK |
| **1680px** | 1352 → 1656 | rails **4 → 5** · shop **4 → 5** | OK → OK |
| **1920px** | 1352 → 1680 | rails **4 → 5** · shop **4 → 5** | OK → OK |
| **2560px** | 1352 → 1680 | rails **4 → 5** · shop **4 → 5** | OK → OK |

RTL, the same widths:

| screen | ar container | ar product columns | ar fits |
| --- | --- | --- | --- |
| **320px** | 320 | rails 2 · shop 2 | see below → OK |
| **360px** | 360 | rails 2 · shop 2 | OK → OK |
| **390px** | 390 | rails 2 · shop 2 | OK → OK |
| **414px** | 414 | rails 2 · shop 2 | OK → OK |
| **480px** | 480 | rails 2 · shop 2 | OK → OK |
| **600px** | 600 | rails 2 · shop 2 | OK → OK |
| **768px** | 744 | rails 2 · shop **2 → 3** | OK → OK |
| **834px** | 810 | rails 2 · shop 3 | OK → OK |
| **1024px** | 1000 | rails 3 · shop 3 | OK → OK |
| **1180px** | 1156 | rails **3 → 4** · shop **4 → 3** | OK → OK |
| **1280px** | 1256 | rails 4 · shop 4 | OK → OK |
| **1366px** | 1342 | rails 4 · shop 4 | OK → OK |
| **1440px** | 1352 → 1416 | rails 4 · shop 4 | OK → OK |
| **1536px** | 1352 → 1512 | rails **4 → 5** · shop **4 → 5** | OK → OK |
| **1680px** | 1352 → 1656 | rails **4 → 5** · shop **4 → 5** | OK → OK |
| **1920px** | 1352 → 1680 | rails **4 → 5** · shop **4 → 5** | OK → OK |
| **2560px** | 1352 → 1680 | rails **4 → 5** · shop **4 → 5** | OK → OK |

Header container (its own --hd-max, unchanged):

| screen | header before → after |
| --- | --- |
| 320px | 320 |
| 360px | 360 |
| 390px | 390 |
| 414px | 414 |
| 480px | 480 |
| 600px | 600 |
| 768px | 768 |
| 834px | 834 |
| 1024px | 1024 |
| 1180px | 1180 |
| 1280px | 1280 |
| 1366px | 1280 |
| 1440px | 1280 |
| 1536px | 1280 |
| 1680px | 1280 |
| 1920px | 1280 |
| 2560px | 1280 |

### The one `see below` in that table

`/product/{slug}` measured **+8px at 320px on the pre-change tree** —
`scrollWidth 328` against `clientWidth 320`, on every product page, in both
languages. Pre-existing. `.pdp` is one column below 880px, and a `1fr` track is
`minmax(auto, 1fr)` whose `auto` minimum cannot go below its content's
min-content width (308px, set inside `.gallery` and `.buybox`) — so the grid
refused to be narrower than 308 in a 280px box and dragged the page with it.

This lane made it **worse**, to +10px, by moving the side gutter from that
sheet's own 20px to the shared 22px. A pre-existing defect a lane touches is a
defect that lane owns, so it is **fixed**: `minmax(0,1fr)` measures 320 against
320 at 320, 360 and 390, in English and Arabic. Pinned by
`SiteWidthSystemTest > it fixes the product page overflow…`.

### Every cell of the column count that moved, and why

| width | surface | before → after | why |
| --- | --- | --- | --- |
| 768 | /shop, category | 2 → 3 | its old ladder forced 2 below 820px; the row is 724px, which holds three 220px tiles |
| 1024 | product page (related) | 4 → 3 | `.rel` declared `repeat(4,1fr)` above 880px, so 980px held four 230px tiles; three 314px tiles is the derived answer |
| 1180 | homepage rails, category | 3 → 4 | the winning ladder in `kbb.css` stepped at 1100; the row is 1109px, which holds four |
| 1180 | /shop | 4 → 3 | **the sidebar defect, fixed.** Its ladder keyed off the 1180 *viewport* while the grid had 858px — four 200px tiles. Three 275px tiles is what fits. |
| 1440 | product page, brand page | 4 → 5 | their containers grew to 1416 / 1436 |
| 1536 | all four | 4 → 5 | rows of 1456–1492px hold five |
| **1680** | **all four** | **4 → 5** | **the extra column the owner asked for** |
| 1920, 2560 | all four | 4 → 5 | the container is capped at 1680, so the answer stops moving — which is the `vw` defect avoided |

**Unchanged at 320, 360, 390, 414, 480, 600, 834, 1280 and 1366** — including
both widths this project screenshots.

### What the phone does, stated separately because the owner said "for mobile is fine"

Two columns at 320, 360, 390, 414, 480 and 600, before and after, in both
languages. The gap is 16px on the skinnable grid at every width, before and
after; 12px on `/shop` below 680px, 18px above, before and after. Nothing on a
phone moved.

---

## 4 · The English pin

`StorefrontEnglishUnchangedTest` compares the rendered bytes of every storefront
page against a pinned commit. **Six pages moved. Thirty-five did not.**

The width change itself is **invisible to this walk** — it is entirely in
`resources/css/kbb/*.css`, and the walk rolls `resources/views` back and renders.
So every byte that moved is a view change, and each one was read before the pin
was advanced:

| page | what moved | bytes |
| --- | --- | --- |
| `shop` | the column selector's default `class="on"` on the 4 button, and `data-cols="4"` on the grid — both now emitted only when the shopper has chosen | two attributes |
| `product-category/{path}` | the same page template, the same two attributes | two attributes |
| `korean-skincare-brands` | `.brw{max-width:1180px;margin:0 auto;padding:22px 18px 60px}` → the token, plus its comment | one declaration |
| `korean-skincare-brands/{slug}` | `<x-product-grid>` stopped emitting `style="--kbb-cols:4;--kbb-cols-m:2"`, which nothing reads any more | one attribute |
| `skincare-guide` | `.wrap{max-width:1160px;…}` → the token, plus its comment | one declaration |
| `{slug}` (an article) | the same declaration in `store/post.blade.php` | one declaration |

**Not one byte of shopper-visible copy moved on any of them.** Four of the six
are inside an inline `<style>`; two are attributes on a control.

### ▲ The whitespace that nearly cost a pin advance for nothing

Written the obvious way — each directive on its own line — the new
`<style id="kbb-layout">` block added **four blank lines to the `<head>` of
thirty-five storefront pages**, and the walk reported all thirty-five. The
whitespace was the entire diff, for a block that emits nothing at all on a shop
at its defaults. Advancing the pin there would have been advancing it for
nothing and would have buried the six real changes in thirty-five fake ones.

Two mechanics bring it to zero, and they are commented at the site:

- PHP eats a newline immediately after `?>`, so a raw-PHP block and a
  conditional that both **close at the end of a line** contribute nothing;
- the opening directive **shares a line** with the comment above it and with the
  `<style>` tag it guards, so no newline is left outside the branch.

Verified: with all ten settings at their shipped values the storefront receives
**zero extra bytes**, on every page.

---

## 5 · The settings

**Admin path: `Appearance → Site layout`.** Two tabs.

### Tab · Page width

| control | ships at | what it is |
| --- | --- | --- |
| **Site width** | **1680px** ▲ | the widest the page ever gets. Range 1040–2400, step 20. |
| **Side gutter** | 22px | the narrow end of the gutter. Range 8–48. |
| **Side gutter · wide screens** | 22px | the wide end. Equal to the one above means a constant gutter, which is how it ships. |
| **Header follows the site width** | off | on, the header drops its own 1280px `--hd-max` and takes `--site-max`. |

### Tab · Product grid

| control | ships at | what it is |
| --- | --- | --- |
| **Smallest card** | 260px | the column count is worked out from this and the width the grid actually has. |
| **Smallest card · shop listing** | 220px | the `/shop` and category listing has a filter rail beside it, so its row is narrower at the same screen size. See §2. |
| **Never fewer than** | 2 columns | held even when the cards would be narrower than this allows — what keeps two cards on a 320px phone. |
| **Never more than** | 8 columns | a ceiling on the automatic answer. Eight is more than the smallest card will ever allow, so it is inert until lowered. |
| **Gap between cards** | 16px | the skinnable grid. `/shop` and the related row keep their own 18px. |
| **Or pin an exact count** | Automatic | overrides the automatic answer above 900px. `/shop` always obeys the shopper's own 2/3/4 buttons instead. |

**▲ `Site width` at 1680px is the one deliberate default change in this lane**,
and the owner asked for it in as many words. It is called out in the commit
message and in the migration's docblock rather than buried. **Every other
setting ships at the value the page already had**, so applying the package moves
nothing else until a slider moves — and while all ten are at their shipped
values the storefront is handed no stylesheet at all.

### What this screen does NOT govern, and why

| | its own setting | default |
| --- | --- | --- |
| the cart page | `CartPage::d_max` → `--cpg-d-max` | 1200px |
| the checkout | `CheckoutPage::d_max` → `--cop-d-max` | 1040px |
| the slim footer | `SlimFooter::max_w` → `--sf-max` | 1240px |
| the review wall | `.page`, no setting | 1080px |
| an article's text | `article`, no setting | 720px |
| the quiz | `.shell`, no setting | 720px |

All three of the first group already have a width slider on their own screen,
and all three are pages asking for money: a narrow single-column ledger there is
a decision about conversion, not a stale number. Folding them in would have
widened the checkout to 1680px on every shop that applies the package. The last
three are reading and form measures. `SiteWidthSystemTest` pins all six.

### The table on the screen is the deliverable

The owner asked for the shop to fit every device, and the only way to show that
is to show every device — so the screen's preview is the **same seventeen widths
as §3**, with the container width and both column counts at each, recomputed as
a slider moves and before anything is saved. The 1680 row is highlighted.

**It is arithmetic, not measurement.** Nothing on that screen reads
`getBoundingClientRect` or `offsetWidth`; it runs the same three expressions the
stylesheet runs, on numbers. Rule 4 forbids JavaScript that measures layout on
the shop, and the reason it gives — that a rendered-once CSS answer beats a
scripted one — is why the preview must not measure a mock either: it would be
showing the mock's layout, not the shop's. `SiteLayoutScreenTest` asserts those
four API names appear nowhere in the screen's code, and
`SiteLayoutColumnArithmeticTest` pins the copied expressions by their exact text
against the PHP that reproduces Chromium's answer.

---

## 6 · Screenshots

`docs/w1-width-shots/`, all real Chromium against a seeded shop served through
these two checkouts.

| file | what it shows |
| --- | --- |
| `before-home-{390,1280,1680}.png` / `after-home-*` | the home page. 1680: container 1352 → 1656, rails four cards → **five**. 390 and 1280 identical. |
| `before-shop-*` / `after-shop-*` | `/shop`. 1680: container 1240 → 1680, four → **five**, and no button highlighted until the shopper picks one. |
| `before-product-category-sunscreens-*` / `after-*` | a category, same template. |
| `before-product-1025-dokdo-toner-*` / `after-*` | a product page. 1680: 1180 → 1680, related row four → **five**. |
| `before-korean-skincare-brands-anua-*` / `after-*` | a brand page. 1680: `.brw` 1180 → 1680, four → **five**. |
| `after-ar-ar-{390,1280,1680}.png`, `after-ar-ar-shop-*` | the mirrored layout at the same three widths. |
| `after-admin-site-layout-{390,1280}.png` | **Appearance → Site layout**, the Page width tab with the seventeen-width table. The "before" is that no such screen exists. |
| `after-admin-site-layout-grid-{390,1280}.png` | the Product grid tab, same two widths. |

Measured on the admin screen: `document.documentElement.scrollWidth == clientWidth`
at 390 **and** `#content.scrollWidth == #content.clientWidth` at 390 — which is
the only number that sees an admin overflow, per
`AdminMobileOverflowTest`'s own note. Seventeen table rows, two tabs, the 1680
row reading `1636px · 5 · 5`.

---

## 7 · Found, and NOT fixed

- **`ProductStyles::cssVariables()` is dead code on the storefront.** It is
  called from `resources/views/admin/app.blade.php` and nowhere else, so
  `--kbb-cols-t`, `--kbb-gap`, `--kbb-radius` and `--kbb-ratio` never reach a
  shopper: **Columns · tablet**, **Gap between cards**, **Card roundness** and
  **Image shape** on Appearance → Product styles have never moved a pixel of the
  shop. Not fixed because `app/Services/ProductStyles.php` is not this lane's
  file, and because emitting that string on `<body>` would move the English pin
  on every page of the shop for a change that renders identically today. It is a
  small, self-contained round: emit it in the layout the way
  `cardVariables()` already is, and repin.

- **`kbb.css` contains a second, complete copy of `kbb-grid-skins.css`** — about
  180 rules, duplicated. Only the four **column counts** in it were removed,
  because a count in six places cannot be controlled from one. The rest is card
  styling and any one rule may be the one a skin depends on; unpicking it is its
  own round with its own before/after.

- **`docs/LANE-BRIEFS.md` round 2's header-overflow note is stale.** The header
  fits at 1280 with twelve top-level entries, measured at all seventeen widths on
  eleven pages. `nav-fit.js` and `flex-wrap: wrap` fixed it. The line should be
  struck.

- **`resources/js/kbb/nav-fit.js` measures layout in JavaScript**
  (`getBoundingClientRect`, `clientWidth`) — which rule 4 forbids. It is
  pre-existing, it is the header's fitter, and replacing it is a lane of its own.
  Named here so the exemption is recorded rather than assumed.

- **Nine non-product grids still use fixed counts** (`.prow`, `.journal`,
  `.brandgrid`, `.ugc`, `.revs`, `.blog`, `.trust .g`, `.astats`, `.mgrid`).
  The owner asked about the product grid; a four-up row of trust badges is not a
  listing. They could join the system in a later round.

- **`/my-account/` has no container my sweep recognises** — signed out it draws
  `.acw`, which is not `.wrap`-shaped. It fits at every width, so nothing is
  broken; the table reports `?` for its container rather than guessing.

---

## 8 · What a second round would take

1. Emit `ProductStyles::cssVariables()` on the storefront, so the four dead
   controls above start working. One layout line, one pin advance.
2. A per-tier column pin, if the owner wants a different exact count on a phone
   than on a desktop. The schema has one pin; a trio is a small extension.
3. `@container` on the card itself, if a skin should change its internal layout
   by the space that card has.
4. Unpick the duplicated 180 rules in `kbb.css`.
