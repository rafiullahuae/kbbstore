# Lane CARD — the card he asked for, and one height everywhere

The owner's request, verbatim:

> *"i like this option, but i want to hide the brand name, category name by
> default. only name, rating (if any), pricing and cart buttons also make sure
> the grid must remain same heighted overall even if the name of the product is
> long. i need all equal height in desktop and mobile both. apply this
> everywhere."*

"This option" is **A · Showcase**, already the shipped default
(`App\Support\GridSkins::DEFAULT`). He is not asking for a different card; he is
asking for this one with two lines removed and the equal-height promise made
good. `docs/PG2-SHOWCASE-CARD.md` is the record of how the card was built.

And, the same week, about defaults in general:

> *"whatever i said, keep applying on the site, don't let me know that change
> from the backend, i have the options on backend, i want to apply such things
> directly to the site to save time."*

So the brand line and the category eyebrow are **off when the package lands**,
and both are still switches.

---

## 1 · Where the two controls are

**Appearance → Product styles → Card content → "Brand name"** and
**→ "Category label"**.

They are **not new**. They have been on that screen since Lane AD wired it up;
what moved is their shipped value, from on to off. A second pair of controls
somewhere else would be the "two answers to one question" that
`App\Services\ProductStyles`' own note calls the thing this shop keeps paying
for.

Two other paths answer questions this card raises:

* **Appearance → Product styles → Card content → "Stars and review count"** —
  left **on**, because he asked for "rating (if any)". It moved in one respect:
  the tile reads it in PHP now rather than only through a body class, so it
  works on every page (see §3).
* **Catalogue → Wishlist** — the heart, still **off**, so what the package
  actually draws is the button at the text column's full width. Measured: the
  button is 198.8px wide at 1280 with the module off and 142.8px with it on, and
  **the card height does not move** — 416.77/416.75 either way.

---

## 2 · The measurement, before and after

Chromium, `/opt/pw-browsers/chromium`, one booted preview, one fixture
(`tools/card-seed.php`), the shipped skin. `document.documentElement.scrollWidth`
equals `clientWidth` on every shot at every width — 320 at 320, 390 at 390, 1280
at 1280 — so nothing overflows anywhere.

### The defect: "equal height" was equal **by accident**

`.kbb-pgrid … .kbb-card{height:100%}` equalises a card against **the other cards
in its own row**. `.cp{margin-top:auto}` pins the price and the button to the
card's foot, so the buttons on one row line up. Neither says anything about the
row above. The rating row is drawn only for a product that has reviews, so a
grid row whose every product is unreviewed is **shorter than the rows around
it** — and nothing in the card noticed.

Lane PG2 measured twelve identical numbers and reported equal heights. Re-run on
this branch before a line was changed, that fixture still measures twelve
identical numbers — because under the shop's own ordering (`featured`, then
`products.name`) **every row of it happens to contain a reviewed product, at
both widths**. `tools/card-seed.php` carries that arithmetic in full and is
built to break it: five unreviewed products on one desktop row, two on each of
two phone rows.

| | before | after |
| --- | --- | --- |
| category page @1280 | **468.91 / 442.91 / 468.89** | 416.77 / 416.77 / 416.75 |
| category page @390 | **421.84 ×3, 395.84 ×2, 421.84** | 356.95 on all six rows |
| category page @320 | **five heights**: 360.84 · 387.59 · 400.48 · 413.59 · 427.23 | 321.95 |
| `/shop` @320 | **seven heights**: 311.84 · 325.48 · 338.59 · 351.48 · 352.23 · 364.59 · 378.23 | 321.95 |
| brand page @1280 | **432 / 475.19** | 418.36 |

**26px** is the gap between the tall and the short rows, every time, and it is
the rating row's 18px line plus its 8px margin exactly. At 320 two more rows
wrap and add their own variance: the brand line ("BEAUTY OF JOSEON" is 27.28px
of line, "MEDICUBE" is 13.64px) and a marked-down price (`.cp` was 59px against
32.25px on the tile beside it).

### The answer: `.cb` is a six-track grid

```
grid-template-rows:
    var(--sc-cat-slot)                                   the eyebrow
    calc(var(--sc-brand-slot) + var(--sc-name-slot))     the brand line + the name
    var(--sc-rate-slot)                                  the rating
    1fr                                                  the slack
    auto                                                 the price
    auto                                                 the button
```

A missing element leaves its track **empty and still sized**. That is the whole
mechanism, and it is why **nothing was added to the markup**: an unreviewed
product still emits no rating row at all, which is what the owner asked for in
the round that built the tile. The `1fr` does what `margin-top:auto` did, so the
price and the button still sit on the card's foot.

Every slot is `calc()` over the same numbers the row itself is drawn with —
change `--sc-rate-fs` and the reservation follows it. **No measurement and no
script**: the two tests that forbid the element-measuring APIs by name still
pass, and nothing was added to `resources/js`.

**A row the owner switches off reserves nothing.** `.pc-nocat`, `.pc-nobrand`
and `.pc-norate` each zero their own slot, so "Stars and review count: off" is
not a 26px empty band.

### The two things that bit in the price row

Both were found in the **screenshot**, not in the code.

1. **`align-items:baseline` cost one pixel.** Baseline alignment offsets the
   struck original and the sale price to put their baselines on one line, and
   because the two are at different font sizes the union is taller than either:
   `.cp` measured **34px on a marked-down card and 33px on the plain one beside
   it**, which put three card heights back at 1280 (416.75 / 417.75 / 417.77).
   Centred instead, with `.cp`'s line-height declared as a **length** so the row
   does not follow the type inside it.

2. **`flex-wrap:nowrap` clipped the price the shopper pays.** At 320 the text
   column is 104px and "AED 260  AED 208" is 51 + 8 + 55. The first check said
   it fitted — `scrollWidth` is an integer and reported 55 for a 55.19px box —
   and the shot read **"AED 2…"**. A `Range` around the contents read 55.2:
   0.01px of overflow is all `text-overflow:ellipsis` needs. Below 380px the
   **struck original alone** drops from 12.5px to 10.5px and the gap from 8 to
   5, matched by `:has(.kbb-card-reg)` so a product at one price is untouched at
   every width and the sale price is untouched at every width. Measured with the
   same Range: 42.53 + 5 + 55.2 = **102.73 inside 104**, nothing ellipsised.

### The long name

`docs/card-shots/card-names-short-vs-long-320.png`, `-390.png` and `-1280.png`
are `Toner` beside *Ultra Hydrating Ceramide Barrier Repair Night Cream With
Panthenol and Squalane 100ml* — the same row at all three widths, one carrying
96 reviews and the other none, so the same frame shows the rating row appearing
and disappearing without moving the button.

| | Toner (5 chars, no reviews) | 85 chars, 96 reviews | 129 chars, no reviews |
| --- | --- | --- | --- |
| 320 | card 321.95, name box 36.95 | card 321.95, name box 36.95 | card 321.95, name box 36.95 |
| 390 | card 356.95, name box 36.95 | card 356.95, name box 36.95 | card 356.95, name box 36.95 |
| 1280 | card 416.75, name box 36.95 | card 416.75, name box 36.95 | card 416.77, name box 36.95 |

**Three, four and more lines' worth of name all measure 36.95px**, because
`-webkit-line-clamp` cuts at two and `height:calc(lines × line-height × 1em)`
reserves both whether or not the text fills them. Those two numbers are now
stated **once** — `.kbb-tile .kbb-card-nm` reads `--sc-name-lines` and
`--sc-name-lh` with its old literals as the fallbacks, so the other 31 skins
have byte-for-byte the box they had.

### The 0.02px at 1280, said plainly

Two grids report **418.25 and 418.27** rather than one number (now 416.75 and
416.77). It is subpixel rounding of a fractional track: a five-column row on a
1280 viewport gives 230.80px in four columns and 230.81px in the fifth, the
photograph is square, and a row that is not full takes the smaller figure. It
was **453.89 against 453.91** on the shipped card before this lane, so it is
unchanged in kind, it is a fiftieth of a pixel, and to a tenth of a pixel every
grid on the site is one height.

---

## 3 · Why the hiding is in PHP and not in CSS

**Because the CSS switch reaches three pages of the shop.** The seven `.pc-no*`
rules exist **only** in `resources/css/kbb/kbb-grid-skins.css`, and only `home`,
the wishlist and a collection `@vite` that sheet. `/shop`, every category
archive, every brand page, the search results and the product page's related
rail are styled from the **second, shorter copy of the skins inside `kbb.css`**,
which stops before those rules — measured, `grep -c pc-nobrand` is 1 in one file
and **0** in the other. So "Brand name: off" hid the brand on the homepage and
left it on `/shop`.

Ten more rules are missing from that copy for the same reason, and they are
**found and not fixed** — see §6.

`components/product-card.blade.php` reads `show_brand`, `show_category` and
`show_rating` itself and omits the markup. One decision, in PHP, on every page
that draws a tile and under every one of the 32 skins.

**These three and not all seven**, deliberately: they are the card's vertical
anatomy — each is a row that is either there or not — and the reservation and
the markup have to agree about what exists. The four that only change a line's
*content* (the was-price, the two badges, the button) are untouched.

**The eager load stays.** `->with('brand:id,name,slug')` is not removed and
`StorefrontQueryBudgetTest` does not move, because the relation is read by two
things that are not the brand line: `$seed`, which `Gradient::for()` hashes to
colour the placeholder of a product with no photograph, and
`Gradient::initials($brand ?: $name)`, the letters drawn on it — visible on the
`Centella Ampoule 100ml` tile of every shot, which reads **BO** for Beauty of
Joseon. And the line is a setting, so a shop that switches it back on would
otherwise lazy-load one query per card, which under
`Model::preventLazyLoading()` is not a slow page but an exception.

---

## 4 · "Apply this everywhere", proved page by page

Measured on this branch, after. `/cart` and `/checkout` are excluded by his
earlier instruction and that exclusion is asserted rather than remembered.

| page | grids | 320 | 390 | 1280 |
| --- | --- | --- | --- | --- |
| `/` — four home rails | 4 | 324.55 | 358.95 | 411.72 |
| `/` — a published grid section | 1 | 305.38 (6 of 10, carousel) | 336.08 | 411.72 |
| `/shop/` | 1 | 321.95 | 356.95 | 416.77 / 416.75 |
| a category archive | 1 | 321.95 | 356.95 | 416.77 / 416.75 |
| a brand page | 1 | 314.55 | 348.95 | 418.36 |
| search (`/shop/?s=…`) | 1 | 321.95 | 356.95 | 416.77 |
| the product page's related rail | 1 | 316.20 | 351.95 | 416.75 |
| `/cart` | **none** | — | — | — |
| `/my-wishlist` | none signed out | — | — | — |

Every figure is **one height for the whole grid**, and where two are printed
they are the 0.02px above.

**A fifth product grid turned up.**
`resources/views/partials/home/grid-section.blade.php` opens a `.kbb-pgrid`
through `class="{{ $gsClass }}"`, so `DefaultCardStyleTest`'s scanner — which
matches `class="[^"]*\bkbb-pgrid\b` — cannot see it and reports four. It is also
the **only** grid that does not make the tile a direct child of the row: every
card is wrapped in a `.gs-cell`, so `height:100%` on `.kbb-tile` resolves
against a different box there. Measured and uniform at all three widths.
`CardEqualHeightTest` counts the templates that draw the **tile** instead, which
is the question "everywhere" actually asks, and that list is six:

| template | the pages it draws |
| --- | --- |
| `store/shop.blade.php` | `/shop/`, every category archive, the search results |
| `components/product-grid.blade.php` | brand pages, the `[kbb_products]` shortcode |
| `partials/home/grid.blade.php` | the four homepage rails, curated collections, concern pages, the wishlist |
| `partials/home/grid-section.blade.php` | **a published grid section** (Appearance → Grid sections) |
| `store/product.blade.php` | the product page's related rail |
| `store/routines.blade.php` | **one tile per step, outside any grid** |

`/routines` draws a single tile per routine step and no `.kbb-pgrid` at all, so
"equal height across a grid" is not a question there — but the brand line and
the eyebrow are hidden on it, because the decision is in the tile's own PHP.
That page is 404 until Build my routine is switched on, which is why it is
asserted in the suite rather than photographed.

### The other three treatments share the anatomy

One height each, at both widths:

| treatment | 390 | 1280 |
| --- | --- | --- |
| **A** showcase | 356.95 | 416.77 / 416.75 |
| **B** compact | 304.25 | 364.06 / 364.05 |
| **C** row | 340.88 | 362.94 / 362.92 |
| **D** airy | 375.09 | 434.91 / 434.89 |

C needed the same reservations twice: its own desktop grid, and the phone block
where it stacks — which used to revert to a **flex column**, and would have left
C as the one phone card whose height still moved with the rating row.

**And C is the one treatment whose price has to wrap.** It gives its width to
the button: measured at 1280, five columns, the tile is 230.8px, `.cb` takes
14px of padding each side, the button is 121px and the column gap is 10 — which
leaves the price about **72px against the 114** a marked-down pair needs. That
is not a font-size away. `flex-wrap:nowrap` clipped it and the shot showed the
sale price running under the button on five of twelve tiles, so this one
treatment wraps and **reserves the second line**, which is the same answer as
everywhere else arrived at from the other end. `row-gap:0` is part of it and was
measured too: the family's `gap:8px` is both axes and only shows up once the
container wraps, so the two lines came to 48.5 against a 40.5 reservation and a
marked-down card was 8px taller than the plain one beside it (370.94 / 370.92 /
362.92 on the three rows). Below 700px C is the stacked card the others are, has
the whole text column, and gets the family's one-line row back.

### And when he switches the two lines back on

They are still reserved, so the card stays uniform: **357.61 at 320, 392.61 at
390, 452.42/452.41 at 1280**, one height per grid, with the eyebrow clamped to
one line and the brand clamped to one line.
`docs/card-shots/card-lines-on-*.png` is that pair.

---

## 5 · The pictures

All in `docs/card-shots/`, all Chromium, all with the measured numbers in
`storage/card-logs/evidence.txt`.

| file | what it shows |
| --- | --- |
| `card-after-category-320/390/1280.png` | **the shipped card** — name, rating where there is one, price, button |
| `card-after-shop-320/390/1280.png` | the same on the shop listing, 24 products |
| `card-names-short-vs-long-320/390/1280.png` | `Toner` beside the 85-character name, one with 96 reviews and one with none |
| `card-price-320-zoom.png` | one marked-down card at 320, at 3× — the price row in full |
| `card-lines-on-category-*.png`, `card-lines-on-shop-*.png` | both switches back **on**: the before, and the proof the controls still work |
| `card-after-wishlist-390/1280.png` | the same card with Catalogue → Wishlist on |
| `card-showcase-compact/row/airy-390/1280.png` | the other three treatments |
| `card-after-home/brand/related/search-390/1280.png` | the walk |
| `card-after-cart-390/1280.png` | the basket, with no product tile on it |
| `card-ar-category-390/1280.png` | the same card on `/ar` — mirrored, with Arabic words in it |
| `card-en-after-ar-390/1280.png` | English again from the same server, as the control |

### And the typeface change moves none of it

This branch was rebased onto the tip that carries Lane PLC's **Outfit for
Poppins**, `public/build` was rebuilt against the merged stylesheets, and every
shot above was taken again. **Every card height is identical to the figure it
was under Poppins** — 321.95 at 320, 356.95 at 390, 416.77/416.75 at 1280, and
the same for all four treatments — which is what a reservation built out of
line-height ratios and lengths should do, and is the measurement that says so.

The one number that did move is the one the narrow-screen rule is about: the
struck original at 10.5px is **41.83px** of Outfit against 42.53px of Poppins,
so 41.83 + 5 + 54.98 = **101.81 inside 104** and there is slightly more headroom
than there was.

### Arabic

The family is laid out on the logical axis and contains no `[dir]` selector, and
nothing this lane added changes that: the new declarations are `grid-row`,
`white-space`, `overflow`, `text-overflow`, `gap`, `line-height`, `font-size`
and two block-axis margins. The mirrored pair shows the grid reading right to
left, the name and the rating row right-aligned, and the struck original at the
row's start; the English control taken from the same server afterwards measures
**identically — 356.95 at 390 and 416.77/416.75 at 1280, one height either
way**.

The Arabic shots are taken **last**, because a shop with Arabic enabled renders
a language switcher in its header that a shop without it does not, and every
English panel above has to show the chrome this shop has today.

---

## 6 · Found and not fixed

**Ten more rules are missing from `kbb.css`'s copy of the skins**, and the seven
`.pc-no*` switches are only the loudest of them. Measured by grepping both
sheets for the same selector:

| rule | `kbb-grid-skins.css` | `kbb.css` |
| --- | --- | --- |
| `.kbb-pgrid .kbb-card{border-radius:var(--kbb-radius,…)}` | 1 | **0** |
| `.kbb-pgrid .kbb-card-thumb{aspect-ratio:var(--kbb-ratio,…)}` | 1 | **0** |
| `.kbb-pgrid .cn{-webkit-line-clamp:var(--kbb-name-lines,…)}` | 1 | **0** |
| `.kbb-pgrid .cn{min-height:calc(var(--kbb-name-min,0))}` | 1 | **0** |
| `.kbb-pgrid .kbb-badge-sale{background:var(--kbb-sale,…)}` | 1 | **0** |
| `.kbb-pgrid .kbb-cstar.on{color:var(--kbb-star,…)}` | 1 | **0** |
| `.kbb-pgrid .kbb-card-cart{background:var(--kbb-cart-bg,…)}` | 1 | **0** |
| `.kbb-pgrid:not([data-skin="horizontal"]) .kbb-card{height:100%}` | 1 | **0** |
| the seven `.pc-no*` rules | 1 each | **0** each |

So **Card roundness, Image shape, Product name lines, the Discount-badge and
New-badge colours, the Stars colour and the button's two colours all move the
homepage and not `/shop`** — the same shape of defect
`docs/PG2-SHOWCASE-CARD.md` §6 recorded for the Price colour. This lane made
the showcase family immune to it (the family block is byte-identical in both
sheets and names the variables itself) and took the three switches its own work
depends on out of CSS entirely, but it did not unpick the duplication: it is
~180 rules, any one of which some skin may depend on, and copying the block
across would make eight controls live on four more pages in a release that was
asked for something else. It wants a round of its own.

**The Add to cart button was ellipsised at 320px, and another lane fixed it.**
Measured on this branch before it was rebased: "ADD TO CART" was **84.97px** of
text inside an **84px** content box (the button is 104px wide with
`padding:0 10px`), so it printed "ADD TO CA…" — one pixel over, and it printed
that before this lane as well. On the tip this branch now sits on, Lane PLC's
typeface change (Outfit for Poppins) takes the same words to **84.00px** and it
fits. Recorded rather than dropped, because the margin is a hundredth of a pixel
wide: anything that widens that label — a longer translation, a heavier weight,
a future typeface — brings the ellipsis straight back, and the fix is one line,
`padding:0 6px` below 380px.

**"Product name lines" does not govern this card's name box.**
`.kbb-tile .kbb-card-nm` clamps to two lines and reserves two, and `--kbb-name-
lines` (Appearance → Product styles → Card content) reaches only `.cn`, the link
that wraps it. Wiring the control through would be easy and would **break the
thing this release is about**: its shipped value is 0, which means "show the
whole name, however long", and an unclamped name is a card whose height depends
on its title. If he asks for it, the control has to become a *reservation* (one
to four lines, reserved) rather than a *limit*, and 0 has to stop meaning
unlimited on this card.

---

## 7 · For the integrator

**There is nothing to wire.** No route, no screen, no skin, no new setting —
`routes/web.php` and `resources/views/admin/app.blade.php` are untouched and
need no anchor/replacement block in the shape `docs/BG-ADMIN-APP-BLOCKS.md`
established. Checked rather than assumed:

* **no route** — this lane adds no controller and no endpoint; the two settings
  already post through `admin-api/product-styles`.
* **no screen** — "Brand name" and "Category label" are existing rows in
  `ProductStyles::TABS['content']`, which the screen renders from the schema.
* **no swatch** — `resources/css/kbb/admin-skin-preview.css` and its generated
  copy in `admin/app.blade.php` are an independent, simplified card and carry no
  `--sc-*` property; no skin was added, so `DefaultCardStyleTest`'s swatch case
  is unaffected. The live preview under Appearance → Product styles already
  builds its class list from the form values
  (`['show_brand|pc-nobrand', 'show_category|pc-nocat', …]`), so it follows the
  moved defaults on its own.

**Three things the package needs.**

1. **The `clear_caches` migration must run.**
   `database/migrations/2027_06_05_000000_clear_caches_card_lines_off.php`.
   `components/product-card.blade.php` is rewritten, and
   `storage/framework/views` keys a compiled view by its source path and decides
   staleness on file times — an unzip's timestamps are not reliably newer than
   what is on disk. The same migration deletes any stored `show_brand` /
   `show_category` row, which is what lets the moved default be seen on a shop
   that has ever pressed Save on that screen.

2. **The package must carry `public/build/`.** The equal heights are six grid
   tracks in `resources/css/kbb/kbb-grid-skins.css` and in the second copy of
   that sheet inside `kbb.css`. `npx vite build` is manual here and CI does not
   run it, so a package without the bundle ships the hiding and not the
   reservation: the two lines would come off and the rows of unreviewed products
   would still be 26px short. `BuiltCssSelectorsAreCurrentTest` is the guard.
   **Expect a conflict in `public/build` at merge time** — another lane is
   changing the typeface in parallel; rebuild from the merged sources.

3. **Build it with `php artisan kbb:package <version> --since=<ref>`.** The
   `migrations` flag in `update.json` is the only thing that decides whether the
   migration above runs at all, and this release is visible: a package that
   skipped it would ship a card that looks exactly as it did before.

---

## 8 · The harness

```sh
sh tools/card-evidence.sh            # everything below, in the right order
sh tools/card-preview.sh 8930        # boots a preview and seeds the fixture
sh tools/card-walk.sh <port>         # every page with a grid, at 320/390/1280
node tools/card-measure.cjs <path> [widths]
node tools/card-shots.cjs <name> <path> <widths> [grid|full]
node tools/card-names-shot.cjs       # the short-name / long-name pair
php artisan tinker tools/card-set.php   # KBB_SHOW_BRAND / KBB_SHOW_CAT / KBB_SHOW_RATE / KBB_SKIN
```

`tools/card-preview.sh` is `tools/pg2-preview.sh` with its own port range, its
own directory and its own seed; every check in it is that script's and is kept
verbatim, because each one was paid for.

Three things this harness learned on its own, all of them recorded in the files
themselves:

* **The port is read back, never assumed.** `card-preview.sh` walks up from the
  port it is asked for — thirty-nine preview scripts share this repository and a
  collision is the design. The first evidence run asked for 8995, the preview
  came up on **8996**, and the whole run photographed whatever was on 8995:
  `/shop` answered 200 with twenty-four branded tiles, the fixture's own
  collection answered 404, and the shots looked completely finished. The
  preview's own two probes cannot catch that, because they check the server the
  *script* started.
* **The setters write to the preview's database.**
  `php artisan tinker tools/card-set.php` run with no environment reads `.env`
  and points at `database/testing.sqlite`, so the first version deleted settings
  rows out of the suite's database and left every panel showing the state
  before it. `CACHE_STORE=file` matters as much as the database: the server
  reads that file cache.
* **A tile with no box is not a tile of a different height.**
  `grid-section.blade.php` hides the cards past `mobile_count` with
  `display:none`, and a hidden element's `getBoundingClientRect()` is all zeros
  — they arrived as "height 0" tiles in a row at y=0 and the summary read
  `0, 336.08  ◀ NOT EQUAL` on a grid that is uniform.

And one that belongs to the measurement rather than the harness: **`scrollWidth`
lies by rounding.** It reported 55 for a 55.19px box holding 55.2px of words and
said the price fitted; the screenshot said "AED 2…". A `Range` around the
element's contents is the honest instrument, and `tools/card-measure.cjs` prints
card heights to a tenth of a pixel as well as raw for the same reason.

---

## 9 · One more thing this cost, and it was not a pixel

`components/product-card.blade.php` reads three of that screen's keys, and it
runs **once per tile**. `ProductStyles::all()` walks its whole schema through
`SettingsService::get()`, and every one of those is a `Cache::rememberForever`
— so a 24-product `/shop` page was doing **24 × 29 = 696 cache reads** for a set
of values that cannot change inside one request.

Thirty sequential renders of `/shop` on this branch's own preview, three passes
each way:

| | pass 1 | pass 2 | pass 3 |
| --- | --- | --- | --- |
| a fresh `all()` per tile | 6799 ms | 5576 ms | 6315 ms |
| the values resolved once | 3595 ms | 3811 ms | 3363 ms |

About **87ms on a page that takes ~120**. `StorefrontQueryBudgetTest` would
never have seen it: it counts queries, and this costs none — which is the whole
of rule 4's "measured rather than asserted".

`ProductStyles` is bound **`scoped`** now, beside `CartService`,
`SettingsService` and `VariantPricing`, and for the reasons recorded there: a
singleton would survive between requests on a queue worker, and a process-level
static is CLAUDE.md's `Setting::map()` trap — it would survive
`forgetScopedInstances()` and flatter every test that moves a setting and
re-renders. `save()` drops the memo, so a screen that writes and reads back in
one request is told the truth.

**The staleness is not theoretical.** The migration case in
`CardEqualHeightTest` went red the moment the memo existed: it had called
`SettingsService::forgetMemo()` and not `forgetScopedInstances()`, so it read a
value from before the migration it had just run. Both resets now, like every
other helper in that file. A test that pokes the database directly has to do
what a request does.

---

# Round three — "everywhere", enumerated instead of assumed

§4 above proved the card page by page across **eight URLs**. That is a claim
about the pages somebody thought of, and "apply this everywhere" is a bigger
claim than that. This round enumerated the surfaces instead: every template that
draws a product tile, then every controller that reaches one. **Seven more
surfaces turned up, and one of them had no stylesheet at all.**

## 10 · The five that were invisible, and why

Five of the seven are 404 or empty until a gate is opened, and
`tools/card-measure.cjs` prints **"no product grid on this page"** for a 404 —
with no warning marker, which reads exactly like a pass.

| surface | why round two never saw it |
| --- | --- |
| `/new-in`, `/best-sellers`, `/super-sale`, `/everything-under-54-aed` | `CollectionController::COLLECTIONS` keys them `new-in`, `best-sellers`, `super-sale`, `under-54`, which reads like `/collections/<key>/`. **`routes/web.php` mounts each at its own top-level path.** A walk that guessed the URL got four 404s. |
| `/concern/<slug>/` | 404 until `MIN_PRODUCTS` (3) products are **tagged** for the concern |
| `/routines/<slug>` | needs the module on **and** `routine_role` set — the concern tag alone leaves every step unfilled and not one tile on the page |
| `/my-wishlist` | the guest page is the **empty state**; round two recorded "none signed out" and moved on |

`tools/card-surfaces.php` opens all five. Nothing it switches on is a shipped
default — every one of those modules is off in the package and stays off.

## 11 · Measured, after — one height per grid at every width

| surface | 320 | 390 | 1280 |
| --- | --- | --- | --- |
| `/new-in` (24 tiles) | 323.95 | 358.95 | 411.72 |
| `/best-sellers` (24) | 323.95 | 358.95 | 411.72 |
| `/super-sale` (10) | 323.95 | 358.95 | 411.72 |
| `/everything-under-54-aed` (3) | 323.95 | 358.95 | 411.72 |
| `/concern/hydration/` (4) | 323.95 | 358.95 | 411.72 |
| `/routines/hydration/` (4 step tiles) | 369.09 | 439.09 | 338.30 |
| `/my-wishlist`, five products (**one** reviewed) | 323.95 | 358.95 | 411.72 |

Brand rows **0**, eyebrow rows **0**, on every one. The wishlist line is the
interesting one: one tile of the five carries a rating row and the other four do
not, which is the exact shape that was 26px short before this lane, and it is
one height.

The **Arabic** side of every surface above measures **identically** to its
English counterpart at all three widths, including the FBT strip.

## 12 · The defect: Frequently Bought Together had no CSS

`partials/fbt.blade.php` draws a product tile per bundle item and is the one
product-tile surface outside the cart that never went through
`<x-product-card>`. Its own header says why nobody noticed:

> *"The plugin ships its own CSS for this block, so no theme stylesheet is
> involved."*

True of the WordPress original, where the KBB Modules plugin styled it. **The
plugin's CSS was never ported.** `grep -rl 'kbb-fbt'` across the repo returns
the Blade, the JS and `ImageVariants` — and **no stylesheet**. So in this app
the block had no rules whatsoever: a `<label>` is inline, so each item was as
tall as its own name wrapped to, the 90px thumbnails floated out of their items,
and the checkbox, the name and the price ran together as one paragraph.

| | before | after |
| --- | --- | --- |
| `.kbb-fbt-item` @320 | **18 / 81 / 156 / 210** — four heights | 175.94 |
| @390 | **18 / 60 / 81 / 114** — four heights | 175.94 |
| @1280 | **18 / 114** — two heights | 175.94 |

The 18px item is the one whose name fitted on a single line.
`docs/card-shots/fbt-before-390.png` and `fbt-after-390.png` are the pair, and
`fbt-ar-390.png` is the mirrored Arabic.

**It lives in `resources/css/kbb/kbb-product.css`** — that sheet and no other,
because the product page is the only page that loads it and the only page the
strip appears on. That also keeps it clear of the `kbb.css`/`kbb-grid-skins.css`
duplication §6 records. The mechanism is the card family's own rather than a
second one: `--fbt-name-slot` is a `calc()` over the numbers the name row is
drawn with, `.kbb-fbt-item` spends it in `grid-template-rows`, and
`-webkit-line-clamp` reads the same `--fbt-name-lines` the track reserves, so
the clamp and the reservation cannot drift.

**What it draws is what he asked for and no more**: picture, name, price. No
brand line and no eyebrow — it never had either. No rating and **no per-item
cart button**, because a bundle item is not separately buyable: the block has
one button and a checkbox per item, which is what makes it a bundle. That is
the module's semantics, not a card style.

**No default moved.** The FBT module is off in the package and stays off; this
is what it renders as for a shop that has turned it on.

## 13 · The harness hid two of these, and now cannot

* **`tools/card-measure.cjs` found grids by a hardcoded selector list** —
  `.kbb-pgrid, #grid, .rel` — and printed "no product grid on this page" for
  anything else. `/routines/<concern>` draws a tile **per step** and no
  `.kbb-pgrid` at all, so a page carrying four real product tiles measured as a
  page with none. It starts from every tile that exists and climbs to its grid
  now, marks an ungridded group **UNGRIDDED**, prints the page-wide **tile
  count even when it is zero** (a height claim over no cards is vacuous), and
  reports the two product tiles that are **NOT the shared card** by name.
* **`tools/card-shots.cjs` takes `sel:<selector>`**, so a block that is not a
  `.kbb-pgrid` can be photographed. Without it the script clipped to the
  product page's **related rail** and captioned it with the FBT's numbers — a
  picture of the wrong block with the right figures under it.
* **`tools/card-wishlist.cjs` clicks the hearts rather than forging the
  cookie.** `kbb_wishlist` is **not** in `bootstrap/app.php`'s
  `encryptCookies(except:)`, so a cookie written from outside the app decrypts
  to nothing and the page renders empty anyway — silently, which is the worst
  way for a harness to be wrong.
* **`tools/card-walk.sh` walks fourteen URLs** instead of eight.

## 14 · The cart's recommended rail, named rather than fixed

`store/cart-inner.blade.php` draws its own `.cpg-card` — image, name, price, a
`+` on the picture — for the **recommended rail**, and it is the second product
tile on this shop that is not the shared card. It is **out of scope**: the owner
excluded the cart and the checkout by name, and `CardEqualHeightTest` §5 pins
that exclusion. Two further gates mean it was not measurable here either — it
renders only under the **squeeze** cart layout *and* on a basket with something
in it. Recorded so it is a known fact rather than a later surprise; the measurer
now names it the moment it appears.

## 15 · The wishlist heart — root cause, and what is NOT the cause

The owner, this round:

> *"also in the propose products grid, there was wishlist heart icon beside the
> add to cart button, i can not see that, plz make and apply."*

That reads like a lane deleted it. **It was never deleted.** Checked in the code
before anything was written:

1. **The heart is on the card today.** `components/product-card.blade.php` draws
   `<button class="heart" data-kbb-wish=…>`, wrapped in `@if ($kbbWishlist)`.
2. **It is designed to sit beside the Add to cart button**, and does.
   `kbb-grid-skins.css` says so in as many words — *"The heart has to sit BESIDE
   the Add to cart button"* — and gives `.kbb-card-thumb` `display:contents` so
   the heart's `position:absolute` resolves against the card.
3. **No commit has ever touched it.** `git log -S 'data-kbb-wish' --all` on that
   file returns **exactly one** commit: the 2.60.41 baseline. The three commits
   of the round that hid the brand and category lines do not touch the heart
   line at all.

**The cause is that Catalogue → Wishlist is a module that ships OFF**, and every
caller reads it as `moduleEnabled('wishlist', false)`. The heart has been markup
behind a switch nobody turned on.

Measured with the module on, same fixture, same server:

| | 320 | 390 | 1280 |
| --- | --- | --- | --- |
| `/shop/` card height, heart **off** | 321.95 | 356.95 | 416.75 / 416.77 |
| `/shop/` card height, heart **on** | 321.95 | 356.95 | 416.75 / 416.77 |
| Add to cart button width | 104px | 139px | 142.8px |

**Identical.** The heart sits in a grid area the card already reserves, so it
costs no track: the button gets narrower and the card does not get taller.
`docs/card-shots/heart-on-shop-*.png` is the picture.

**And one thing the pictures show that the code reading did not.** At **1280**
the heart is a square outline button immediately to the right of Add to cart —
exactly what he described. At **390 and 320 it is not**: it sits on the bottom
corner of the **photograph** instead, because the family has a `@media
(max-width:700px)` arm that moves it there. So "beside the add to cart button"
is true on a desktop and false on a phone, and he asked for both.

**Neither half shipped in this round**, and the reason is not technical: the
change is a write to the module toggle, and this environment's permission guard
declined it (*Feature Flag Writes*). Both halves are specified and measured
above and want a decision rather than more investigation:

* switch **Catalogue → Wishlist** on as a shipped default (CLAUDE.md's ▲
  reversal — he asked for it, so it ships on, and the control stays where it is);
* and move the heart beside the button **under 700px too**, which is the phone
  arm of the family and the only part that needs new CSS.
