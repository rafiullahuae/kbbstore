# Lane PG — one product card, five columns, hidden filters

The owner's request, verbatim:

> "i need the product grid this one, everywhere on the whole site inner pages by
> default. on shop, on category pages, on product pages etc, and if the product
> titles goes long, still the product grid height must remain equal and
> adjusted. by default 5 columns on desktop and 2 columns on mobile. and also on
> category pages. keep off the left filters hidden by default. and user can view
> the filters by the option. and if any product doesn't have any review, then
> the rating bar will not show on that product inside grid."

The card he pointed at is `docs/OWNER-GRID-REFERENCE.webp`.

---

## 1 · What was there

Five grids and **two complete cards**, doing the same job with different class
names and a different set of bugs fixed in each.

| grid | card | where |
| --- | --- | --- |
| `.kbb-pgrid` via `<x-product-grid>` | the skinned card | brand page, `[kbb_products]` |
| `.kbb-pgrid` via `partials/home/grid` | the skinned card | homepage rails, a category, the wishlist |
| `.grid` on `/shop` via `<x-product-card>` | the **other** card | the shop listing **and every category archive** |
| `.rel` on a product page | the **other** card | "you may also like" |
| `.brw-grid` on the brand landing | **neither** | it lists **brands**, not products |

**`.brw-grid` is the one that is not converted, and it is not a product grid at
all.** `store/brands.blade.php`'s directory tiles are `Brand` rows with a logo
and a count; there is no price, no rating, no Add to cart and no product. It is
left exactly as it was, including its own `--brw-min` auto-fill, which is the
precedent Lane W1 followed for everything else.

---

## 2 · Which card won, and why it lives where it does

**The owner picked the skinned card's LOOK**, so the markup is the skinned
card's: `.kbb-card` / `.kbb-card-thumb` / `.cb` / `.cn` / `.cp`, which are the
classes the 28 skins in `kbb-grid-skins.css` are written against. A card with
new class names would have silently unstyled every skin.

**It lives in `components/product-card.blade.php`** — the *other* file — for one
reason that decided it: three of the four product grids already call
`<x-product-card>`, and one of those three is the related rail in
`resources/views/store/product.blade.php`, **which belongs to Lane SF**. Putting
the one card in the tag those grids already use converts that rail without
editing a file this lane does not own, and leaves one template to change next
time instead of two that have to be kept in step.

`components/product-grid.blade.php` and `partials/home/grid.blade.php` are now
loops around `<x-product-card>`. Neither draws a card.

### What came across from the old /shop card, deliberately

The skinned card had none of these and `/shop` had all four. Dropping them to
"match the screenshot" would have been a regression in four working things
dressed up as a design change:

- the **wishlist heart** — Catalogue → Wishlist, off by default
- the **quick-view button** — Catalogue → Quick view, **on** by default
- **Product Labels** — Growth & Marketing → Product Labels. When that module is
  on it takes the badge over entirely, including deciding there should not be
  one, and `ProductLabelsRenderTest` still reads its `.lbl` off `/shop`.
- a **no-JS Add to cart** — a real `?add-to-cart=` link, not a `<span>`

### What the skinned card had wrong, fixed by being replaced

- it drew **five hollow stars and `(0)`** for every unreviewed product,
  unconditionally — it is on every tile of the owner's own reference screenshot
- `partials/home/grid` priced from `(int) $p->price`, which is **NULL on a
  variable parent**, so those tiles advertised **AED 0** — and bound a
  `data-kbb-add` beside it, so the basket took a line at AED 0 that the checkout
  would then collect
- its **NEW badge was gated on `! $p->review_count`** — "nobody has reviewed it"
  is not what new means. `created_at` inside 30 days is.

### ▲ The root is a `<div>`, and that was forced rather than chosen

The skinned card wrapped the whole tile in `<a class="kbb-card" href=…>`. It
cannot any more: the tile now holds a real `?add-to-cart=` link and two
`<button>`s, and **`<a>` inside `<a>` is the one nesting the HTML parser
actively breaks apart** — the browser would have hoisted the Add to cart button
out of the card. `<button>` inside `<a>` is invalid interactive content.

So the root is a `<div>`, the photograph and the name are real links, and the
rest of the tile is clickable through a stretched pseudo-element on the name
(`.cn::after{position:absolute;inset:0}`). No JavaScript, no measurement, same
behaviour. The photograph's link is `tabindex="-1"` so a 25-tile page does not
get 50 tab stops, and keeps its `alt` so it is still described and indexable.

### ▲ The eyebrow is a PROP, and that is what keeps the query count flat

`catLabel` is a caller's string, not something the card reads off
`$product->categories`. Reading the relation would have put `categories` in the
card's load contract and therefore in the eager load of `/shop`, **every
category archive**, the product page and `/routines` — one more query on four
pages that do not pay it today.

The two callers that already have it loaded (the skinned grid) resolve the label
and pass it in; a category archive passes its own category; `/shop`, a search
and a brand filter pass null and the tile simply has no eyebrow.

`ComponentLoadContractTest` renders every caller with
`Model::preventLazyLoading()` on and is what holds this.

---

## 3 · Five columns on a desktop, two on a phone

**One number moved.** Lane W1 derived the count from
`repeat(auto-fill, minmax(min(…),1fr))` against the row each grid actually has,
with no breakpoint in it. That machinery is untouched; the **tile minimum** it
is derived from went `260px → 220px`, in the one place it is declared
(`:root{--kbb-tile}` in `kbb.css`, mirrored by `SiteLayout::SCHEMA['tile']`).

A row holds N tiles when `row >= N * (tile + gap) - gap`. At a 1280px screen a
page-width grid's row is 1203–1212px:

| tile | arithmetic | columns |
| --- | --- | --- |
| 260px | (1203 + 16) / 276 = 4.4 | four |
| **220px** | (1203 + 16) / 236 = **5.1** | **five** |

and 220 is inside the only window that gives five and not six there
(187.2 < tile ≤ 227.8). The phone's two columns are `--kbb-cols-floor`,
unchanged at 2, which holds them even where two 220px tiles do not fit
(2 × 220 + 16 = 456 > 346).

### The ladder, derived rather than declared

Row widths — subtract ~44px of gutter to read them as screen widths:

| row | columns | ≈ screen |
| --- | --- | --- |
| < 692px | **2** (the floor) | up to ~735 |
| ≥ 692px | 3 | ~736 |
| ≥ 928px | 4 | ~972 |
| ≥ 1164px | **5** | ~1208 — **1280 lands here** |
| ≥ 1400px | 6 | ~1444 |

**Six at 1680 is not a mistake.** *"on 1680px the grid products will show 1
column extra"* is the owner's earlier request and this is the same request one
step along: five at a desktop, one more on the big screen, and no further,
because the page container is capped at `--site-max`.

`/shop` keeps its own `--kbb-tile-shop` at 220 (it already was 220, for the
filter rail beside it). With the rail hidden by default its row is the full
1236px, so it lands on five at 1280 as well; with the rail open it is 4.

**Admin:** Appearance → Site layout → Product grid → **Smallest card** moves it
back. The preview table on that screen moved with it.

---

## 4 · A long name cannot make its row taller

`-webkit-line-clamp:2` cuts the name off and `height:calc(2 * 1.32em)` reserves
both lines whether or not the text fills them, so a one-word name and a
ninety-character name occupy the same box. The reservation is in `em`, which
resolves against the element's own font size, so it follows the skin rather than
being a pixel figure that stops being true under half of the 28.

The brand goes on its own line — which is what the reference shows, and is also
what keeps the name's budget constant: inline, a long brand would spend one of
the name's two lines.

**Nothing measures anything.** The tile's source contains no
`getBoundingClientRect`, `offsetHeight`, `clientHeight` or `getComputedStyle`,
and `OneProductTileTest` asserts that by name.

Measured, `/shop/?filter_brands=pg-titles`, a five-character name beside a
ninety-character one:

| viewport | columns | card heights | name box |
| --- | --- | --- | --- |
| 1280 | 5 | **366 / 366** | 34px / 34px |
| 390 | 2 | **302 / 302** | 34px / 34px |

---

## 5 · No reviews, no rating bar

`@if ($rc > 0)`, once, in the one card.

**The height it used to take is not reserved with an invisible row.** The tile
is a flex column, `.cb` grows and `.cp` takes `margin-top:auto`, so the price
and the Add to cart button sit on the tile's bottom edge whatever is above them
— a tile with a rating and one without line up, and so does one with a brand
beside one without.

Measured on a category archive holding both (`rated-vs-unrated-*`): 2 of 6 tiles
draw a `.kbb-card-rate`, and every tile in a row is the same height —
405/405/405/405/405 at 1280, 341/341 then 319/319/319/319 at 390.

---

## 6 · The filter rail starts hidden

On `/shop` **and on every category archive**, because
`CategoryArchiveController` delegates to `ShopController::index()` and both
render `store/shop.blade.php`.

**A cookie, not a class a script adds**, for two reasons:

- A preference that resets on every navigation is worse than no preference. A
  shopper who opens the filters, ticks a brand and lands on the filtered page
  would find them shut again, every time, on the one page whose whole purpose is
  filtering. The Hide and Show buttons write `kbb_filters` in the same click
  that toggles the class.
- It has to be decided on the **server**. A script that adds the class after the
  document loads paints the 250px sidebar and then takes it away, which is a
  layout shift on the largest block of the page. The class is in the `<body>`
  tag as it is sent.

`kbb_filters` joins `kbb_tz` in `encryptCookies(except:)` — `EncryptCookies`
drops anything without Laravel's envelope, so a cookie a browser writes arrives
as nothing at all. It is safe to exempt in the same narrow way: the value is
**compared against one string and never printed**, nothing is authorised, priced
or filtered on it, and a visitor who forges it has done no more than press the
button beside it. Anything that is not the single value `open` — no cookie, a
stale one, a forged one — is the shipped default.

**The way back in is not a whisper.** `#showFilters` was pink text on a
pink-tinted pill, a quiet secondary control beside the sort box. It is the
page's one solid button now — `background:var(--pink)`, white text, first in the
toolbar, `min-height:40px` — and it carries the number of filters currently
applied, as does the phone's Filters button. On a phone nothing else moved: the
rail there is an off-canvas sheet behind a button that was always visible.

---

## 7 · The gutter defect this lane found and owns

`.shop` declared `padding:22px 0 60px`. The `0` is a **horizontal** padding, and
`kbb-shop.css` loads after `kbb.css`, so the shorthand beat
`.wrap{padding-inline:var(--site-gutter)}`. Measured at 1280 on a category
archive: `.wrap.shop` computed `padding-left: 0`, the grid ran 0..1280 while the
`<h1>` above it started at x=22, and the first and last tiles sat flush against
the edges of the window with the page's own heading inset from them.

Pre-existing. This lane owns it because this lane made it obvious: with the rail
shown, the thing flush to the left edge was the rail, and nobody reads a
sidebar's gutter. `padding-block` sets the two the rule is about and leaves the
inline pair to the site gutter.

---

## 8 · The evidence

Real Chromium, `docs/lane-pg-shots/`, numbers in `measurements.json`, produced by
`tools/pg-grid-shots.cjs`. `cols` is read off the grid's computed
`grid-template-columns` — the resolved track list, not a breakpoint anybody
guessed at. Card heights are `getBoundingClientRect().height`, per tile, in DOM
order; a row's worth are equal in every single shot.

| shot | vw | scrollWidth / clientWidth | cols | `.wrap` pad | card heights (first six) | rated / tiles |
| --- | --- | --- | --- | --- | --- | --- |
| shop-390 | 390 | 390 / 390 | 2 | 16px | 324, 324, 302, 302, 302, 302 | 12 / 25 |
| shop-1280 | 1280 | 1280 / 1280 | **5** | 22px | 388 × 6 | 12 / 25 |
| category-390 | 390 | 390 / 390 | 2 | 16px | 341, 341, 319, 319, 319, 319 | 2 / 6 |
| category-1280 | 1280 | 1280 / 1280 | **5** | 22px | 405, 405, 405, 405, 405, 383 | 2 / 6 |
| category-filters-open-1280 | 1280 | 1280 / 1280 | 4 | 22px | 398, 398, 398, 398, 376, 376 | 2 / 6 |
| category-filters-open-390 | 390 | 390 / 390 | 2 | 16px | 341, 341, 319, 319, 319, 319 | 2 / 6 |
| brand-390 | 390 | 390 / 390 | 2 | 12px | 338, 338, 315 | 2 / 3 |
| brand-1280 | 1280 | 1280 / 1280 | **5** | 22px | 411, 411, 411 | 2 / 3 |
| home-rail-390 | 390 | 390 / 390 | 2 | 12px | 345, 345, 322, 322, 345, 345 | 4 / 8 |
| home-rail-1280 | 1280 | 1280 / 1280 | **5** | 22px | 403 × 6 | 4 / 8 |
| wishlist-390 | 390 | 390 / 390 | 2 | 12px | 322, 322, 345, 345 | 2 / 4 |
| wishlist-1280 | 1280 | 1280 / 1280 | **5** | 22px | 403 × 6 | 2 / 6 |
| product-related-1280 | 1280 | 1280 / 1280 | **5** | 22px | 388, 388, 388, 388 | 1 / 4 |
| product-related-390 | 390 | 390 / 390 | 2 | 12px | 297, 297, 319, 319 | 1 / 4 |
| titles-short-vs-long-1280 | 1280 | 1280 / 1280 | **5** | 22px | **366, 366** | 0 / 2 |
| titles-short-vs-long-390 | 390 | 390 / 390 | 2 | 16px | **302, 302** | 0 / 2 |
| rated-vs-unrated-1280 | 1280 | 1280 / 1280 | **5** | 22px | 405, 405, 405, 405, 405, 383 | 2 / 6 |
| rated-vs-unrated-390 | 390 | 390 / 390 | 2 | 16px | 341, 341, 319, 319, 319, 319 | 2 / 6 |
| ar-shop-1280 | 1280 | 1280 / 1280 | **5** | 22px | 388 × 6 | 12 / 25 |
| ar-shop-390 | 390 | 390 / 390 | 2 | 16px | 324, 324, 302, 302, 302, 302 | 12 / 25 |
| ar-category-filters-open-1280 | 1280 | 1280 / 1280 | 4 | 22px | 398, 398, 398, 398, 376, 376 | 2 / 6 |

`scrollWidth == clientWidth` on every row: nothing overflows at either width, in
either direction.

**RTL.** `/ar` with `language_rtl_enabled` on: the filter rail moves to the
right, the NEW pill to the top-right and the −N% to the top-left, the heart to
the bottom-left, and the −N% itself is isolated by `Bidi::number()` so the sign
stays on the number. `SignedNumbersInRtlTest`'s hand-off list is **empty** now —
the old card's badge was the one entry on it.

### Query counts, per page, warm

One `DB::listen` for the whole run (they accumulate; re-registering per page
makes the Nth page report N times its real count):

| page | queries |
| --- | --- |
| `/` | 1 |
| `/shop/` | 4 |
| `/collections/{cat}/` | 5 |
| `/brands/{slug}/` | 5 |
| `/my-wishlist/` | 1 |
| `/product/{slug}/` | 9 |

`StorefrontQueryBudgetTest` is the instrument that matters and it is unchanged:
every ceiling holds and the flatness cases — the same page against 24 products
and against 84 — still report identical counts.

---

## 9 · For the integrator

### `.rel` on the product page, which belongs to Lane SF

`resources/views/store/product.blade.php` already renders the one card, because
it renders `<x-product-card>` and that is the card. **Nothing has to change for
it to work** — the screenshots above are of the rail as it renders today.

One line is still worth changing, and only Lane SF's owner can make it. Line 538:

```
    <div class="{{ $modules->classFor('related') }} rel" id="related">@foreach ($related as $item)<x-product-card :product="$item" />@endforeach</div>
```

to

```
    <div class="{{ $modules->classFor('related') }} rel kbb-pgrid" data-skin="{{ \App\Support\GridSkins::resolve(null) }}" id="related">@foreach ($related as $item)<x-product-card :product="$item" />@endforeach</div>
```

What it buys: the related rail then answers to Appearance → Product styles →
Grid skin like every other grid. What it does **not** change: the column count
(`.rel` is already named in the shared track rule in `kbb.css`), the equal
heights or the name clamp (both hang off `.kbb-tile`, which the card carries
itself), or the query count.

Keep it on ONE line — a Blade directive on a line of its own contributes its
indentation and its newline to the rendered page, and
`StorefrontEnglishUnchangedTest` compares bytes.

### The admin skin preview, which lives in a file this lane may not edit

`resources/views/admin/app.blade.php`'s `skinCard()` builds the preview card for
Appearance → Product styles, and its own comment says it uses *"the storefront's
own markup so a preview cannot drift from what ships"*. It has now drifted: it
still builds the pre-PG tile, so the preview does not get the two-line name
clamp or the equal-height column, and it shows a rating row on a card it also
calls new.

**The minimum that stops the drift is two edits, both on the one line each.**

```
      <a class="kbb-card" href="#" onclick="return false">
```
to
```
      <a class="kbb-card kbb-tile" href="#" onclick="return false">
```

and

```
        <div class="cn"><span class="kbb-card-brand">BEAUTY OF JOSEON</span> Relief Sun Rice + Probiotics SPF50+</div>
```
to
```
        <div class="cn"><span class="kbb-card-brand">BEAUTY OF JOSEON</span><span class="kbb-card-nm">Relief Sun Rice + Probiotics SPF50+</span></div>
```

That is enough: `.kbb-tile` is what every rule this lane added hangs off, and
`.kbb-card-nm` is the element the clamp reserves two lines of. The preview's
root can stay an `<a>` — the reason the real tile could not is the no-JS
`?add-to-cart=` link and the two buttons inside it, and the preview has neither.

`.skinprev .pc-norate .kbb-card-rate`, `.pc-nobrand .kbb-card-brand` and the
rest of the preview's own toggles are unaffected; they key on classes that did
not move.

### Nothing else to wire

This lane added no route, no admin screen and no partial. `routes/web.php`,
`resources/views/admin/app.blade.php`, `KBB-Master-Plan.md` and
`KBB-Progress-Dashboard.html` are untouched.

### The English pin

`EnglishRenderWalk::BASE_COMMIT` was advanced to this lane's own commit with the
thirty-three pages that moved written at it, grouped by why, and the eight that
did not named as the control.
