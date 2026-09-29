# Lane PG2 — the showcase card, four treatments, and the default that carries it

The owner's request, verbatim:

> "I need to match the product grid design to the attached one, same mean
> everything same. and the rating rows will be additional, that's it. apply this
> design on the whole website everywhere. exept cart and checkout pages. keep
> this design by default from backend. this will apply for desktop and mobile
> both. but first give me previews more improved to choose from."

The card he attached: a white card with a soft border and a generous radius; a
large **square** photograph filling the card's full width at the top on its own
pale background; then, left-aligned with comfortable padding, the product name
over two lines in near-black; under it the **struck original price in grey
followed by the sale price in pink** on one line; then a wide **uppercase ADD TO
CART in solid dusty rose with squared-off corners**, filling the text column,
with a **heart outline beside it**. Plenty of white space.

---

## 1 · The four treatments, and where to look at them

**`docs/pg2-card-shots/contact-sheet-1280.png` and `docs/pg2-card-shots/contact-sheet-390.png`**
are the two pictures to open first. Five columns each: *today's card* and then
the four treatments, at the size the browser drew them, from the same twelve
products on the same category page at the same width. The only difference
between the columns is `grid_skin`.

`row-sheet-1280.png` and `row-sheet-390.png` are the same five, each showing its
**whole catalogue row** — five columns on a desktop, two on a phone — which is
where the equal card heights and the rhythm of the row can be seen.

| | name in the picker | what it is | at a glance |
| --- | --- | --- | --- |
| **A** | Showcase — full-width button | faithful to the screenshot | bordered white card, full-width uppercase button, heart beside it |
| **B** | Showcase Compact — tighter | the same anatomy a step down, **and no card outline** | borderless, denser, rounded photograph |
| **C** | Showcase Row — price beside the button | the price and the button share the last line | split bottom row; the heart moves onto the photograph |
| **D** | Showcase Airy — more air, lighter button | no border, a soft shadow, an **outlined** button that fills on hover | the only one whose button is not solid |

**B was redrawn once.** With only its numbers changed it and A were the same
picture at thumbnail size, which is the note the owner has now sent back twice.
Dropping the card outline is what makes "tighter" legible at a glance — and it
is also what tighter actually wants, since the outline is most of the space a
dense grid loses.

**C is a desktop difference and says so.** At 390px a two-column row gives a
167px card and about 135px of text column; "AED 120 AED 94" beside an ADD TO
CART is 210px of content in it, and the first shot showed the button sitting on
top of the struck price. Below 700px treatment C is the stacked card the others
are, so at 390 C and A are the same layout.

### The measured numbers, in Chromium

`document.documentElement.scrollWidth` equals `clientWidth` on every shot — 390
at 390, 1280 at 1280 — so nothing overflows at either width.

| treatment | 1280: columns / card height / button | 390: columns / card height / button |
| --- | --- | --- |
| today (classic) | 5 / 405px / 211×34 | 2 / 341px / 151×34 |
| **A** showcase | 5 / 454px / 143×44 | 2 / 392px / 139×44 |
| **B** compact | 5 / 401px / 186×34 | 2 / 342px / 167×34 |
| **C** row | 5 / 406px / 122×38 | 2 / 377px / 143×38 |
| **D** airy | 5 / 472px / 131×46 | 2 / 408px / 131×46 |

Every tile's photograph frame measured exactly square at both widths on every
treatment. Every card in a row measured the same height — on treatment A, all
**twelve** tiles are 454px at 1280 and 392px at 390, including the one named
`Toner` and the one named *Ultra Hydrating Ceramide Barrier Repair Night Cream
With Panthenol and Squalane 100ml*, whose name boxes both measure 37px because
the clamp reserves two lines whether or not the text fills them.

`names-short-vs-long-1280.png` and `names-short-vs-long-390.png` are that pair
on its own — the last row of the fixture — which is the whole of "if the product
titles goes long, still the product grid height must remain equal and adjusted"
in one frame: **card 454 vs 454 at 1280 and 392 vs 392 at 390, name box 37 vs 37
at both**. One of the two has no reviews and the other has 96, so the same
picture also shows the rating row appearing and disappearing without moving the
button.

The raw numbers are in `docs/pg2-card-shots/measure-*.json`, one file per treatment.

---

## 2 · What was built

**Not a new grid system.** `resources/css/kbb/kbb-grid-skins.css` already drove
28 card templates off `.kbb-pgrid[data-skin="…"]`, and this is four more of
them plus a change of default. There is **no markup change at all**: the tile is
one shared template and `StorefrontEnglishUnchangedTest` compares bytes.

### The card is a two-row grid, and `display:contents` is why

The heart has to sit **beside** the Add to cart button. In the markup it is
inside `.kbb-card-thumb` — the photograph's frame — because that is where the
other 28 skins want it, and the markup may not move.

So `.kbb-card-thumb` takes `display:contents` under this family. It generates no
box, so its children become items of the card's own grid and, more to the point,
it stops being a containing block: the heart's `position:absolute` then resolves
against `.kbb-card`, and `bottom:var(--sc-pad)` puts it on the same line as the
button — which sits on the card's bottom edge because `.cb` grows and the button
takes its last line.

What the thumb stopped doing, the photograph's own `<a>` does instead:
`.kbb-card-shot` carries the square frame, the pale background and the
`overflow:hidden` that clips the hover zoom. The badges, the quick-view button
and the heart are each given a `grid-area`, because an absolutely positioned
child of a grid whose containing block *is* that grid resolves its insets
against its grid area. No measurement, no script, no breakpoint.

**One thing the grid broke and this rule fixes.** `layouts/store.blade.php`
positions the quick-view pill with `left:50%; bottom:10px` and centres it with a
`translateX(-50%)`. Inside a grid area that stops working: an absolutely
positioned grid child resolves an `auto` offset to the edge of its grid area, so
`top:auto` became the photograph's top edge, the box was stretched between that
and `bottom:10px`, and the pill measured **231×231** — the whole photograph —
with its label floating at the top of it. Found by hovering a tile and measuring,
not by reading. The family sets all four insets to `auto` and places it with
`align-self:end; justify-self:center` instead, which is 97×27 at the
photograph's foot. `hover-quickview-1280.png` is the shot.

### Every control the owner already has still governs

The family reads his custom properties rather than painting over them:

| control | property | in the family |
| --- | --- | --- |
| Card roundness | `--kbb-radius` | the card's corner — so all four treatments are at its shipped 14px in these shots |
| Image shape | `--kbb-ratio` | the photograph's frame |
| Button background / text | `--kbb-cart-bg` / `--kbb-cart-fg` | A, B, C fill with it; D outlines with it and fills on hover |
| Price | `--kbb-price` | every price that is **not** marked down |
| Stars | `--kbb-star` | the rating row |
| Sale badge / New badge | `--kbb-sale` / `--kbb-new` | the two pills |

And **no rule in the family declares `display`** on `.kbb-card-brand`,
`.kbb-card-cat`, `.kbb-card-rate`, `.kbb-card-reg`, `.kbb-card-cart`,
`.kbb-badge-sale` or `.kbb-badge-new`. The seven `.pc-no*` switches under
**Appearance → Product styles → Card content** hide exactly those with
`display:none` at two class weights; a skin rule is three, so one `display` here
and a switch he already has stops working with no error anywhere.
`DefaultCardStyleTest` reads the family's rules and fails naming the control.

### The pink is on the sale price only

`.kbb-card-reg + .kbb-card-price` — an adjacent-sibling rule, so it can only
match a price with a struck original in front of it, which is the case his card
shows. A product that is not marked down keeps whatever **Appearance → Product
styles → Colour → Price** says.

### The rating row is an addition, not a removal

His card has none because that product has no reviews. This shop has drawn
nothing at all for an unreviewed product since the round that built the tile — a
behaviour he asked for in as many words then — and it still does: a reviewed
product draws the row in the new design and an unreviewed one draws no markup,
with the price and button still pinned to the card's foot so the row stays
level. Visible in `page-category-1280.png`: eight of the twelve have a rating
line and four have nothing where it would be.

---

## 3 · Where it sits in the admin

**Appearance → Product styles → Layout → Default card style.**

The four new entries are the last four in the picker, after the 28 that were
already there. Choosing one changes every product grid on the site at once.

Three other paths answer the questions this card raises:

* the heart — **Catalogue → Wishlist**. It ships **off**, so what applying the
  package actually draws is the button at the card's full width and **no heart
  at all**. The contact sheets are shot with it **on**, because the heart is
  part of the design he sent and he is choosing between designs.
  `panel-showcase-no-wishlist-1280.png` and `…-390.png` are the same card as it
  will arrive — measured, the button goes from 143px wide to 199px **at 1280**,
  which is the whole text column, and is 139px either way at 390 because the
  heart is on the photograph there regardless. **The card height does not move**:
  454px at 1280 and 392px at 390 with the module on or off. Neither picture is a compromise; `:has(.heart)` is
  what gives the heart its room, so the button takes the whole column when there
  is nothing to make room for. Turning the module on is one switch, and it is
  the owner's to throw — it is a whole module (its own pages, its own cookie,
  its own endpoint), not a decoration, which is why this lane did not throw it.
* the two pills — **Appearance → Product styles → Card content → Discount badge**
  and **→ New badge**. His card shows neither, because that product is neither
  new nor badged; the shop's are still drawn and still switchable.
* the brand line — **Appearance → Product styles → Card content → Brand name**.
  His card has none. It is left **on**, because it is on the shop today and rule
  1 says a lane changes the thing it was given and nothing else. One switch
  turns it off if he wants it gone.

---

## 4 · The default, and what "everywhere" turned out to mean

`GridSkins::DEFAULT` is `showcase` now. It was `classic`. That is a deliberate
default change, and CLAUDE.md rule 1's one exception — "a default the owner
asked for in as many words" — which is why it is in its own commit with the
change in the subject line.

**The default had five homes and a literal in three of them.** Moving one alone
is a shop where the homepage rails, the wishlist or a curated collection keeps
the old card while everything else moves:

| where | was | now |
| --- | --- | --- |
| `GridSkins::DEFAULT` | `classic` | `showcase` |
| `ProductStyles::SCHEMA['grid_skin']` | the literal `'classic'` | `GridSkins::DEFAULT` |
| `HomepageSections::REGISTRY` — bundles | `classic` | `GridSkins::DEFAULT` |
| — recommended | `soft` | `GridSkins::DEFAULT` |
| — bestsellers | `luxe` | `GridSkins::DEFAULT` |
| — flash sale | `ribbon` | `GridSkins::DEFAULT` |
| `partials/home/grid.blade.php` | `$skin ?? 'classic'` | `GridSkins::resolve($skin ?? null)` |
| `store/wishlist.blade.php` | `get('grid_skin', 'classic')` | `GridSkins::resolve(null)` |
| `store/collection.blade.php` | `get('grid_skin', 'classic')` | `GridSkins::resolve(null)` |

The four homepage rails are the interesting ones. They **choose their own** skin,
which is precisely what "used wherever a grid does not choose its own" excludes,
so the store-wide setting never reached them and the homepage carried four
different cards. A shop that has already saved a homepage keeps what it saved;
this moves a shop that has never opened **Appearance → Homepage**, and each
rail's own picker still offers all 32.

### To switch to B, C or D

One edit: `GridSkins::DEFAULT`. Everything above reads it, and
`DefaultCardStyleTest` fails if any of the nine stops agreeing. The approved rule
in `EnglishRenderWalk::approvedStorefrontChanges()` names the constant too, so
its hit count does not move either.

### Every `.kbb-pgrid` on the site, proved rather than assumed

Four templates open a product grid, and `DefaultCardStyleTest` scans every Blade
under `resources/views` (comments stripped — six files name `.kbb-pgrid` in
prose) to assert that these are all of them:

| template | the pages it draws |
| --- | --- |
| `store/shop.blade.php` | `/shop/` **and every category archive** — `CategoryArchiveController` delegates to `ShopController::index()` |
| `components/product-grid.blade.php` | brand pages, the `[kbb_products]` shortcode |
| `partials/home/grid.blade.php` | the four homepage rails, the curated collections, the concern pages, the wishlist |
| `store/product.blade.php` | the product page's related rail |

`.brw-grid` on `/brands/` is **not** a product grid: it lists brands, with no
price, no rating and no Add to cart. It is left exactly as it was.

The rendered proof is in the shots: `page-shop-*`, `page-category-*`,
`page-brand-*`, `page-product-*`, `page-home-*` all measure five columns at 1280
and two at 390 with the new card, and `page-cart-*` has no product grid on it at
all — which is the "exept cart and checkout pages" half of the sentence, also
asserted directly.

---

## 5 · Arabic

The card is laid out on the logical axis — `inset-inline-end` for the heart,
`margin-inline-end` for the button, `text-align:start` for the column — and the
family block contains **no `[dir]` selector**. `panel-ar-category-1280.png` and
`panel-ar-category-390.png` are the mirrored pair with Arabic *words* in them:
the heart on the left of the button, the eyebrow and the name right-aligned, the
struck price and the sale price in reading order, the pills on the left.
`panel-en-after-*.png` is the English pair taken from the same server
afterwards, and it measures identically — same columns, same 454px/392px card
heights, same button.

Measured at 1280, the same card in both directions:

| | Add to cart | heart |
| --- | --- | --- |
| English | x 39, 143 wide | x 194, 44 wide — **after** the button |
| Arabic | x 1098, 143 wide | x 1042, 44 wide — **before** the button |

Nothing was written twice to get that: it is one rule with `inset-inline-end`
and `margin-inline-end` in it.

---

## 6 · Found and not fixed

**The owner's Product-styles colours do not reach `/shop`.** Measured in
Chromium on this branch's own preview: a price reads `rgb(193,62,99)` on a
category archive and `rgb(42,34,40)` on the homepage rails — the same setting,
two answers. `.kbb-pgrid .kbb-card-price{color:var(--kbb-price,…)}` and the
other six `--kbb-*` rules live in `kbb-grid-skins.css`, and the **second
complete copy of that sheet inside `kbb.css`** stops short of them. Only
`home`, `wishlist` and `collection` `@vite` the separate sheet, so `/shop`,
every category archive, every brand page and the related rail never reach the
controls at all: **Appearance → Product styles → Colour** moves the homepage and
not the shop.

That is not this lane's to unpick — it is ~180 duplicated rules and any one of
them may be the one a skin depends on, which is the reason the duplication note
already in `kbb.css` gives for leaving it. What this lane did do is make the
showcase family immune to it: the family block is written into **both** copies,
byte-identical, and names `--kbb-price` itself, so it is the same colour on
every page. `DefaultCardStyleTest` fails if the two copies drift.

**A second, smaller one.** `store/wishlist.blade.php` and
`store/collection.blade.php` read `grid_skin` straight off the settings service
rather than through `GridSkins::resolve()`, so a stored value naming no skin
rendered a grid with no card styling at all. Both go through the resolver now,
which falls back rather than rendering the bare markup.

---

## 7 · The harness

```sh
sh tools/pg2-evidence.sh            # everything below, in the right order
sh tools/pg2-preview.sh 8931        # boots a preview and seeds twelve products
sh tools/pg2-shoot.sh               # every treatment, both widths, one fixture
node tools/pg2-sheet.cjs            # assembles the four sheets
node tools/pg2-card-geometry.cjs    # one tile, part by part, hovered
node tools/pg2-names-shot.cjs       # the short-name / long-name pair
```

`tools/pg2-card-geometry.cjs` is the one that answers "is the card built the way
it looks", which is a different question from "which of these do I want" and the
one that caught the quick-view pill. It also checks the arithmetic the card
depends on: **button 143 + gap 12 + heart 44 = 199**, which is the text
column's content box (231 − 2×16) at 1280. If those stop adding up, one of them
is sitting on top of something.

`tools/pg2-shoot.sh` writes `grid_skin` between passes and re-renders the same
page, so the panels differ by the setting under test and by nothing else. A
sheet assembled from five separately-booted previews could differ by the fixture
as well and nobody could tell which.

`tools/pg2-preview.sh` kills whatever is listening on its port **by PID** before
it starts. That is not tidiness: `php -S` fails with "Address already in use" and
the script used to carry on, leaving the old server answering from a database
file that had just been deleted and recreated underneath it — a deleted inode is
still a readable, *empty* database, so `/shop` answered 200 with a grid of
nothing and `/collections/…/` answered 404. It reads exactly like a broken route
and cost this lane two passes. Never `pkill -f php`: CLAUDE.md records what a
pattern kill on this machine does to the other two lanes.

The shots harness measures `.kbb-card-shot` and not `.kbb-card-thumb`, because
the thumb is `display:contents` under this family and has no box at all — a
probe reading it reports `0x0` on precisely the skins it exists to check.

---

## 8 · For the integrator

One file this lane may not edit, one edit in it.

**`resources/views/admin/app.blade.php`** carries a copy of
`resources/css/kbb/admin-skin-preview.css`, generated from it, which is what
draws the swatches in the skin picker. Without the block below, the four new
entries in the picker draw the bare card rather than the family's look; nothing
breaks, and no test goes red, but the owner is choosing from four swatches that
look the same.

Insert the 19 lines appended to `resources/css/kbb/admin-skin-preview.css` by
this branch (everything from the comment `── THE SHOWCASE FAMILY, as the admin
swatch draws it ──` to the end of that file), each line prefixed exactly as the
rest of the generated block is, **immediately after this line** — it occurs
exactly once in the file:

```
.skinprev .kbb-card-cart{display:block;margin-top:7px;text-align:center;font-size:9.5px;font-weight:700;border-radius:7px;padding:6px;background:var(--kbb-cart-bg,#E0567B);color:var(--kbb-cart-fg,#fff)}
```

and **before** this one, which also occurs exactly once:

```
/* skin picker */
```

No route to mount: a skin is a CSS block and a row in `App\Support\GridSkins`.

**The package must carry `public/build/`.** `npx vite build` is manual here and
CI does not run it; the bundle in this branch is built and committed, and
`BuiltCssSelectorsAreCurrentTest` compares the selector sets in both directions.
