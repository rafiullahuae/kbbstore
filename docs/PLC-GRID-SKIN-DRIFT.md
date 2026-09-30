# The product card is written twice, and the two copies have drifted

**Lane PLC, round 6.** `resources/css/kbb/kbb-grid-skins.css` and
`resources/css/kbb/kbb.css` both carry the product grid's skin rules. This
records what the drift actually is, which copy the shop renders, and why the
answer was a pinning test rather than a de-duplication.

---

## 1 · The number in kbb.css's own comment was wrong, and the wrong shape

kbb.css opens the second copy with:

> *A SECOND, COMPLETE COPY OF kbb-grid-skins.css STARTS HERE — Lines below this
> point repeat ~180 rules that also live in resources/css/kbb/kbb-grid-skins.css*

"~180 rules" was written by eye. Parsed properly — brace-depth walk, at-rule
stack tracked, comments stripped, quotes honoured (`tests/Support/CssRules.php`,
driven by `tools/plc-css-diff.php` and `tools/plc-css-sides.php`):

| | |
|---|---|
| selectors the two copies **share** | **225** |
| of those, declarations that **disagree on a value** | **0** |
| selectors only `kbb-grid-skins.css` declares | **24** |
| selectors only `kbb.css` declares | **34** |
| **divergent selectors** | **58** |
| divergent **declarations** inside them | **151** |

So the drift is **58 selectors / 151 declarations, in BOTH directions** — not
180 rules missing from one side. And the half that matters most is the zero:
**over 225 shared selectors and 755 shared declarations, the two copies do not
disagree about a single value.** Source order cannot decide anything between
them today.

### The first attempt at this number was wrong by about forty, and why

The first cut of `tools/plc-css-sides.php` took kbb.css's copy region to be the
lines between its own banner comment and the next banner comment, and reported
**95** selectors present only there. That window also contains the page
background (`html::before`, the three gradients, the 300s keyframes), the
homepage section card and the product-page price — Lane BG's and Lane PDP2's
work, which merely *landed* between two banners.

**Where a rule sits in a file is not what makes it part of the card.** The
surface is named by the card's own selector vocabulary instead, which is stable
under any edit above or below it. A boundary chosen by position is a boundary
that silently moves — the CSS form of the fixed-width `substr()` window
`CLAUDE.md` names.

---

## 2 · Which copy the shop renders — measured, not read off the source order

`@vite(['resources/css/kbb/kbb.css', …])` sits at line 268 of
`layouts/store.blade.php` and `@stack('styles')` at 269, so a page that pushes
the skins sheet loads it *after* kbb.css. That is the hypothesis. The verdict is
the browser's, read off `document.styleSheets` on the running shop rather than
off the markup, because a `<link>` that 404s leaves no rules behind:

```
/      : kbb-GAXWQoUu.css, kbb-grid-skins-6jZzGHxc.css
/shop  : kbb-GAXWQoUu.css, kbb-shop-CExlr4on.css
```

`kbb-grid-skins.css` is pushed by exactly three storefront views —
`store/home.blade.php`, `store/wishlist.blade.php`, `store/collection.blade.php`.
**`/shop` does not load it at all.** kbb.css is linked from the layout, so it is
everywhere.

All **151** divergent declarations were then taken to that browser and read with
`getComputedStyle` on both pages (`tools/plc-css-measure-drift.cjs` — its probe
list is **generated from the parse**, not typed by hand, because a hand-written
probe list can only find what its author already suspected):

| | |
|---|---|
| render **differently** on the two pages | **10** |
| render the same on both | 45 |
| selector matches no element on a page (latent) | 96 |

The 96 are the `showcase` skins and the seven `.pc-no*` visibility toggles: real
divergence, but nothing on the shop carries those classes at today's defaults.

---

## 3 · The ten, and the two a shopper sees

| property | `/` (both sheets) | `/shop` (kbb.css only) |
|---|---|---|
| `.kbb-pgrid .cn { display }` | `flow-root` | `block` |
| `.kbb-pgrid .cn { -webkit-line-clamp }` | `99` | `none` |
| `.kbb-pgrid .cn { -webkit-box-orient }` | `vertical` | `horizontal` |
| `.kbb-pgrid .cn { overflow }` | `hidden` | `visible` |
| `.kbb-pgrid .kbb-badge-sale { background }` | `rgb(226, 59, 87)` | `linear-gradient(135deg, rgb(255,111,145), rgb(193,62,99))` |
| `.kbb-pgrid .kbb-badge-new { background }` | `rgb(31, 157, 85)` | `linear-gradient(135deg, rgb(28,195,106), rgb(18,150,90))` |
| `.kbb-pgrid .cp, .kbb-pgrid .kbb-card-rate { flex }` | `0 0 auto` | `0 1 auto` |

**The badges are different colours on the homepage and on /shop.** Flat crimson
on one, a pink gradient on the other; flat green against a green gradient. Both
pictured below.

**And the product-name clamp is absent on /shop.** `display:-webkit-box`,
`-webkit-line-clamp:var(--kbb-name-lines,99)`, `-webkit-box-orient:vertical` and
`overflow:hidden` are declared only in `kbb-grid-skins.css`, so
**Appearance → Product styles → "Name lines"** moves the homepage, the wishlist
and a collection page, and does nothing at all on /shop or on a product page's
related rail.

That is a control that does not control what the owner would expect it to. It is
**named here and not fixed**, because fixing it repaints /shop.

### The pictures and the numbers

`docs/PLC-css-drift-shots/` — the `.kbb-pgrid` element itself at each width, at
`deviceScaleFactor: 2`, every tile scrolled into view first so the lazy images
are decoded.

| shot | page | width | SALE badge | NEW badge | name `display` / `line-clamp` / `overflow` | card height | `scrollWidth` |
|---|---|---|---|---|---|---|---|
| `home-390.png` | `/` | 390 | `rgb(226, 59, 87)` | `rgb(31, 157, 85)` | `flow-root` / `99` / `hidden` | 358.95px | 390 |
| `home-1280.png` | `/` | 1280 | `rgb(226, 59, 87)` | `rgb(31, 157, 85)` | `flow-root` / `99` / `hidden` | 411.72px | 1280 |
| `shop-390.png` | `/shop` | 390 | `linear-gradient(135deg, …)` | `linear-gradient(135deg, …)` | `block` / `none` / `visible` | 356.95px | 390 |
| `shop-1280.png` | `/shop` | 1280 | `linear-gradient(135deg, …)` | `linear-gradient(135deg, …)` | `block` / `none` / `visible` | 416.77px | 1280 |

`scrollWidth` equals the viewport at both widths on both pages: nothing here
introduces a horizontal scroll. The raw numbers are in
`docs/PLC-css-drift-shots/measured.json`.

---

## 4 · The fix chosen: the pinning test, not the de-duplication

`CLAUDE.md`: *nothing the owner did not ask about may change.* Merging the two
copies — in either direction — moves those ten declarations, and two of them
repaint every sale and new badge on /shop. **Nobody asked for that**, so it is
the owner's to decide and not a lane's to slip in under a tidying commit.

Duplication a test holds identical is already a pattern in this repository —
`partials/page-background-css.blade.php` and `StandaloneDocumentHeadTest`. What
is not acceptable is *silent* divergence. So the drift is recorded exactly and
held still, in **`tests/Feature/GridSkinCopiesTest.php`**:

1. **the divergence is pinned as a SET, not a count** — 24 selectors one way, 34
   the other, each by name. A count of 58 stays green after a lane removes one
   divergence and adds another;
2. **the two copies may never disagree about a selector they both declare** —
   zero today, and this is the case that matters, because that is the one where
   *which value the shopper gets depends on which page they are on*;
3. **the parser is itself asserted** — an at-rule context, a `url("data:…")`
   value carrying semicolons and `/*`, and a commented-out rule that must not
   become a rule;
4. **the three views that push the skins sheet are pinned at `1`, and the
   layout at `0`** — the finished state, per `CLAUDE.md`'s rule about never
   pinning an absence.

### Mutations, all four run

| change | result |
|---|---|
| add `.kbb-card{color:red}` to `kbb-grid-skins.css` | case 2 red — *"the two copies now declare the same selector with different values"* |
| delete `.kbb-gridhead{…}` from `kbb-grid-skins.css` | case 1 red, naming the four `.kbb-gridhead` selectors that left the set |
| make `CssRules::stripComments()` a no-op | case 3 red — `|/* .commented-out` becomes a rule |
| drop the `@vite` line from `store/collection.blade.php` | case 4 red, naming that file and `0 times, recorded as 1` |

### One more duplicate removed while doing this

The parser was written into `tools/plc-css-diff-lib.php` and a second copy of it
was about to go into the guard. Two copies of the thing whose whole job is to
find two copies that have drifted is not a joke worth shipping, so it lives once
in `tests/Support/CssRules.php` and the tools require it by path.

---

## 5 · What is left for the owner to decide

Both are one-line changes that **move pixels**, which is why neither was made:

- **the badge colours.** Adding `.kbb-pgrid .kbb-badge-sale{background:var(--kbb-sale,#E23B57)}`
  and its `-new` twin to kbb.css makes /shop match the homepage — the flat
  colours, which are also what **Appearance → Product styles → Sale badge / New
  badge** writes. Today those two colour pickers move the homepage only.
- **the name clamp.** Adding the four `.kbb-pgrid .cn` declarations to kbb.css
  makes **Appearance → Product styles → Name lines** work on /shop as it already
  does on the homepage.

Doing both is what "de-duplicate" would mean in practice, and it is a single
round's work once he has said which way the badges should go.
