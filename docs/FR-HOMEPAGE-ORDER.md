# Section order — the two blocks for `resources/views/admin/app.blade.php`

Lane FR, Phase 15. The sequel to `docs/FO-HOMEPAGE-INVENTORY.md` §3.

Appearance → Homepage is painted by `paintHomepage()` inside
`resources/views/admin/app.blade.php`, which is 19,000+ lines and edited by
several lanes at once, so this lane did not touch it. Both edits are below as
**anchor → exact replacement**. `tests/Feature/HomepageSectionOrderTest.php`
reads the anchors out of this file and fails if either has stopped matching the
script verbatim, so a block that has gone stale is loud rather than silent.

Nothing else in the console changes. No new CSS: `.hpmove` already renders empty
on the Product page screen next door, and the note uses `.hpmain span`, which is
already styled.

**Neither block is required for the storefront half to be correct.** The server
settles a nested row back behind its host on every read, so an unpatched console
that posts `ticker` first is normalised rather than obeyed — it simply repaints
with the row back where it belongs. What the blocks add is the screen telling
the truth *before* the save instead of after it.

---

## Block 1 — `paintHomepage()` rows: no arrow on a row that cannot move

Two of the seventeen sections are drawn INSIDE another section's markup.
`store/home.blade.php` puts the delivery strip and the promo ticker inside the
hero's own `<section>`, under its `.wrap`, because the hero band is one visual
unit. Ordering is applied with CSS `order`, which moves flex CHILDREN, so a
declaration on either of those two is accepted by the browser and does nothing.

An ↑ on those rows would therefore be a control that moves a row on a screen and
nothing on the shop — which is the defect `docs/FO-HOMEPAGE-INVENTORY.md` was
written to catalogue, one level along. They get the sentence instead.

The rows are also grouped into blocks, so an arrow moves the hero band whole. A
bare row swap could put `categories` between `hero` and `delivery`; the server
settles that away on save, and the screen would have shown an order the shop
never renders.

<!-- ANCHOR-1 -->

**Anchor** (in `paintHomepage()`, line ≈3558):

```js
  const rows = HP.sections.map((s,i)=>`
    <div class="hprow${(!s.desktop&&!s.mobile)?' alloff':''}" data-i="${i}">
      <div class="hpmove">
        <button class="hpb" data-mv="-1" ${i===0?'disabled':''} aria-label="Move up">↑</button>
        <button class="hpb" data-mv="1" ${i===HP.sections.length-1?'disabled':''} aria-label="Move down">↓</button>
      </div>
      <div class="hpmain">
        <b>${escHtml(s.label)}</b>
        <span>${escHtml(s.description)}</span>
```

**Replace with:**

```js
  // A section the storefront draws INSIDE another one travels with its host:
  // the delivery strip and the promo ticker live in the hero's own <section>,
  // where CSS `order` is inert. The rows are grouped into blocks so an arrow
  // moves the hero band whole and can never land a row inside it — the server
  // settles such an order away on save, and a screen that showed it would be
  // reporting a position the shop does not have. `movable` and `note` are
  // derived by App\Services\HomepageSections from its NESTED constant, so this
  // cannot come to say something the template does not do.
  const HPB = [];
  HP.sections.forEach(s => { if (s.movable === false && HPB.length) HPB[HPB.length-1].push(s); else HPB.push([s]); });
  const hpBlock = s => HPB.findIndex(b => b.indexOf(s) > -1);

  const rows = HP.sections.map((s,i)=>`
    <div class="hprow${(!s.desktop&&!s.mobile)?' alloff':''}" data-i="${i}">
      <div class="hpmove">${s.movable === false ? '' : `
        <button class="hpb" data-mv="-1" ${hpBlock(s)===0?'disabled':''} aria-label="Move up">↑</button>
        <button class="hpb" data-mv="1" ${hpBlock(s)===HPB.length-1?'disabled':''} aria-label="Move down">↓</button>`}
      </div>
      <div class="hpmain">
        <b>${escHtml(s.label)}</b>
        <span>${escHtml(s.description)}</span>
        ${s.note ? `<span>${escHtml(s.note)}</span>` : ''}
```

---

## Block 2 — the `data-mv` handler moves a block, not a row

Without this the arrows still work, but they can separate a host from the
sections nested in it. The server puts them back on save, so the owner would see
the list jump after pressing Save.

<!-- ANCHOR-2 -->

**Anchor** (line ≈3650):

```js
  const mv=e.target.closest('[data-mv]');
  if(mv){
    const i=+mv.closest('.hprow').dataset.i, j=i+ +mv.dataset.mv;
    if(j<0||j>=HP.sections.length) return;
    [HP.sections[i],HP.sections[j]]=[HP.sections[j],HP.sections[i]];
    paintHomepage(base); hpDirty(); return;
  }
```

**Replace with:**

```js
  const mv=e.target.closest('[data-mv]');
  if(mv){
    // Moves a BLOCK. A host and the sections drawn inside it are one unit —
    // see the note in paintHomepage() above — so the hero band swaps with its
    // neighbour whole. Swapping bare rows put a section between a host and its
    // nested rows, which HomepageSections::all() settles away: the screen said
    // one order and the shop rendered another.
    const row=HP.sections[+mv.closest('.hprow').dataset.i];
    if(!row || row.movable===false) return;
    const B=[]; HP.sections.forEach(s=>{ if(s.movable===false && B.length) B[B.length-1].push(s); else B.push([s]); });
    const bi=B.findIndex(b=>b[0]===row), bj=bi+ +mv.dataset.mv;
    if(bi<0||bj<0||bj>=B.length) return;
    [B[bi],B[bj]]=[B[bj],B[bi]];
    HP.sections=[].concat.apply([],B);
    paintHomepage(base); hpDirty(); return;
  }
```

---

## Both blocks were applied and exercised, not just written

Applied to a copy of `resources/views/admin/app.blade.php` by string match — both
anchors matched first time, +1,495 bytes — and the resulting `paintHomepage()`
region was checked to contain no Blade echo and to parse as JavaScript
(`node --check`). The grouping logic was then run against a stub of the
seventeen rows:

```
blocks: 15
ticker up      -> refused
categories up  -> categories, hero, delivery, ticker, bundles, …
hero down      -> categories, bundles, hero, delivery, ticker, …
newsletter up  -> …, reviews, newsletter, trust
```

Fifteen blocks for seventeen rows; a nested row refuses; the hero band moves
whole and nothing lands inside it.

## What is NOT asked for

- **No CSS.** `.hpmove` with no children is already how the Product page screen
  draws a row with no arrows (line ≈3713), and the note is a second
  `.hpmain span`, which is styled.
- **No change to the save payload.** `hpSaveNow()` already posts
  `{key, desktop, mobile, skin}` per row in list order, and the server numbers
  by position. `movable` and `note` are read-only.
- **No `AdminCapabilities::RULES` entry, no route, no migration.** Nothing new
  is mounted. This lane adds no route, so — unusually for this repo — no
  `clear_caches_*` migration is owed for the route table. The compiled VIEWS do
  have to be cleared, because `store/home.blade.php` changed; any existing
  `clear_caches_*` in the package covers it.

## And the one line the layouts screen should stop promising

`HomepageLayouts::LAYOUTS['conversion']` orders `hero, ticker, delivery` and
`editorial` and `boutique` put the ticker seventeenth. All three are positions
the hero's markup cannot produce. They are now NORMALISED on read rather than
silently ignored — the preset still applies, the ticker sits behind the hero —
and no preset has to change. It is worth knowing that the wire preview
`hpWire()` draws the preset's stored sequence, so those three previews show the
ticker where it will not be. Correcting that means drawing from the settled
order; it is left for whoever owns the console, and named here so it is not
found twice.

---

# What was measured

## The order now reaches the page

Preview: `php -S` in front of `public-web-root/index.php` with a fall-through
router, `KBB_PUBLIC_PATH` pointed at `public/` so `@vite` resolves the built
bundle, `SESSION_DRIVER=file`, a migrated and seeded SQLite database (24
products, 8 brands). Chromium 1194, `newContext({viewport})`.

Saved order `['newsletter','trust','reviews', …]`, then
`getBoundingClientRect().top` for every direct `<section>` child of `.kbb-home`:

| | 1280 × 900 | 390 × 844 |
|---|---|---|
| newsletter (`order:0`) | top 94 | top 127 |
| trust (`order:1`) | 469 | 493 |
| hero (`order:3`) | 680 | 779 |
| categories (`order:6`) | 1330 | 1358 |
| … through about (`order:16`) | 6589 | 7769 |

Visual order equals saved order at both widths. `document.documentElement`
`clientWidth === scrollWidth` at both (1280/1280, 390/390) — the flex column
introduces no horizontal overflow. Every section measures the full container
width, as it did under block layout, because a column flex item stretches by
default. `position:sticky` inside `.kbb-home`: **zero elements**, so the one
layout hazard that flex genuinely changes does not arise on this page. The
sticky header is `.head`, outside `.kbb-home`.

`.kbb-home .sec` uses padding and no margins, so there was no margin collapsing
to lose.

## A shop that has not touched the screen

Fetched, not reasoned about. Two renders of `/` differ only in the CSRF token,
so that is masked; then the three changed files were stashed, the compiled views
cleared, and `/` fetched again from the tree as it stood:

```
diff before.html after.html   →  no output
88,718 bytes, identical
```

`StorefrontEnglishUnchangedTest` re-proves the same thing from the other
direction and stays green, so **`EnglishRenderWalk::BASE_COMMIT` is NOT moved by
the ordering half of this lane.**

## The one thing route 2 costs that FO's costing did not name

`.kbb-home .sec:nth-of-type(odd) > .wrap::before` draws a corner ornament on
alternate sections (desktop only; the phone rule hides it). **`nth-of-type`
counts SOURCE order, and CSS `order` does not change source order**, so after a
reorder the ornament stays attached to the same sections and therefore lands at
arbitrary visual positions.

Measured, at 1280px, reading `getComputedStyle(wrap, '::before')`:

- Order `['newsletter','trust','reviews']` first — alternation **survives**, by
  luck: newsletter is source #13 (odd) and trust #12 (even), so the parities
  happen to line up.
- Order `['about']` first — alternation **breaks**: visual #1 and #2 both carry
  the ornament, and visual #11 and #12 both carry none.

It is cosmetic, desktop-only, and 50% opacity. It is reported rather than
patched because there is no honest patch at this layer: CSS has no selector for
"nth item in flex order", and PHP cannot compute the source index either —
whether the journal rail and the review wall render depends on there being an
article or a review, which `HomepageSections` has no business knowing.

**This is the one place FO's §3 costing is incomplete rather than wrong**, and
it is an argument FOR option 1 rather than against it: restructuring into
partials and looping in saved order is the only version in which `nth-of-type`
stays meaningful. It does not change the verdict — a 635-line rewrite of a file
four lanes edited this week still costs more than a decoration that is sometimes
two-in-a-row — but the next lane to weigh option 1 should have it in the column.

## Mutations — the ordering half

Each was applied to the tree, `tests/Feature/HomepageSectionOrderTest.php` run,
and the failures recorded.

| | Mutation | Caught by |
|---|---|---|
| M1 | `home.blade.php` drops `{!! $sections->orderStyle() !!}` | 3 tests: the flex-column rule, the class↔rule pairing, and the newsletter-first reproduction |
| M2 | `classFor()` never adds the ordering class | 3 tests: the pairing, the reproduction, and "keeps the visibility and divider classes" |
| M3 | `all()` stops calling `settle()` | 2 tests: "refuses to show a nested section anywhere the page will not draw it" and the preset normalisation |
| M4 | `orderStyle()` emits a rule for `delivery` and `ticker` too | 2 tests: the fifteen-rule count and "never emits … for a section CSS order cannot move" |
| M5 | `orderIsDefault()` always false | 2 tests: "emits nothing at all" and "emits nothing again once Signature is re-applied" — i.e. the byte-identity promise |
| M6 | nested rows reported as `movable: true` | 2 tests: the pairing (it then looks for a class that is correctly absent) and "marks the delivery strip and the ticker as not movable" |
| M7 | the divider's "first section" reads `REGISTRY` again instead of the saved order | 1 test: "lets the divider setting mean the section that is now at the top" |

## Mutations — the hero's figures

`tests/Feature/HeroClaimsAreCountedTest.php` and
`tests/Feature/HomepageContentEditorTest.php` run together.

| | Mutation | Caught by |
|---|---|---|
| H1 | `slides()` stops substituting | 8 tests |
| H2 | an unresolvable token keeps its line instead of dropping it | 2: the nought-brands case and "drops the sentence, and only that sentence" |
| H3 | a brand count of nought is treated as an answer | 1: "says nothing rather than nought" |
| H4 | `Money::format()` (markup) instead of `Money::plain()` | 3, including "prints no markup" |
| H5 | substitution moved into `editable()`, so the editor gets the figure | 1: "hands the editor the token and not the figure" |
| H6 | `HomeController` counts the brands itself again | 1: "counts the brands once for the page, not once per reader" |
| H7 | the `93 brands` literal comes back as the default | 6 |

No guard in either file survived its own mutation, and none uses the
`->not->toContain($needle, $message)` shape `ExpectationsThatCannotFailTest`
sweeps for.

---

# The hero's figures

## What changed

| Claim | Before | Now |
|---|---|---|
| `93 brands · sourced direct` | a literal, forty lines above a strip that counted 8 | `{brands} brands · sourced direct`, resolved from the cache key the strip's own tally uses |
| `…over AED 199` | a literal, one slide above a band and a ticker already repaired to read Store → Shipping | `{free_from}`, resolved through `ShippingService::thresholdHere()` |
| `Shop the Super Sale` | a sale advertised on every fresh install | **unchanged.** Not derivable — nothing in this application knows whether a sale is running. Left as the owner's editable default, as FO left it. |
| `Medicube · limited-time offer` | an offer advertised on every fresh install | **unchanged**, same reason |

`HomeController`'s own header records `max($brandTotal, 93)` being removed from
the About band and the shop filters for exactly this reason. The hero was where
that figure survived.

## What the storefront does differently

- A shop on the shipped threshold renders the same FIGURE it always did:
  `thresholdHere()` answers AED 199 for a UAE visitor. What has changed is that
  raising it on Store → Shipping now moves the hero. **One byte of the third
  slide does move regardless**: the two sentences are separated by a newline
  rather than a space now, because the token has to be able to take its own
  sentence with it and the drop is line-by-line. HTML collapses a newline inside
  a `<p>` to a space, so the rendered banner is identical to the eye and one
  byte different to a diff. It is named here because this repository byte-pins
  storefront English and the next reader deserves to know which byte and why.
- A shop with **no** free-shipping rule for the visitor's country loses that one
  sentence from the third slide. "Split any order into four." survives — the
  token drops its LINE, which is why the default was split across two. This is
  the rule the delivery band and the ticker inside the same hero already follow.
- A catalogue with **no** brands loses the second slide's eyebrow rather than
  printing "0 brands · sourced direct".
- `{brands}` on the preview database resolves to 8, so the eyebrow and the strip
  below it now agree. That is a deliberate copy change and the reason
  `HomepageContentEditorTest`'s byte pin was updated — it now reads
  `Brand::query()->count()` off the database rather than quoting a constant.

`EnglishRenderWalk` rolls `resources/views` back and leaves the PHP in the
working tree, so both sides of that comparison see the new defaults and it does
not move. **`BASE_COMMIT` is therefore not repinned by this lane either.** The
compensating pin is `HomepageContentEditorTest`'s §7, which asserts the hero's
exact rendered bytes — the same arrangement FO set up.

## An optional third console block, not written

`payload()` now carries `tokens` (what each one means) and `figures` (what each
stands at right now). The screen's live preview renders the raw `{brands}` the
owner typed. Substituting `figures` into the preview — and printing `tokens`
under the two boxes — would be a small edit to
`resources/views/admin/partials/homepage-content-screen.blade.php`, which is
Lane FO's surface rather than this one's. The payload is ready for it; the block
is not written because guessing at another lane's screen is how two screens come
to disagree.

---

# Found, measured, and deliberately NOT fixed

## The delivery strip and the ticker have Desktop/Mobile switches that the hero overrides

`docs/FO-HOMEPAGE-INVENTORY.md` §1 says "Presence is in good shape and is not
what this lane touched." **It is not, for three of the seventeen rows**, and this
is the one place that document's costing turns out to be wrong rather than
merely incomplete.

`store/home.blade.php` draws the hero band as one element:

```blade
<section class="sec {{ $sections->classFor('hero') }}" style="padding-top:14px"><div class="wrap">
```

…and the delivery strip and the promo ticker are `<div>`s inside it. So the
band carries the HERO's `d-off` / `m-off`, and `.d-off{display:none !important}`
takes the whole band with it.

Measured in Chromium, hero Desktop off, delivery and ticker Desktop **on**:

| | 1280px | 390px |
|---|---|---|
| band `<section class="sec d-off">` | `display:none` | `display:block` |
| `.delivery` inside it | `flex` | `flex` |
| `.tick` inside it | `block` | `block` |

Both inner sections compute to a visible display and neither is on the page,
because their parent is not. The same is true one step harder for
`$sections->hidden('hero')` — off on BOTH devices — which drops the whole
`@unless` and takes two sections that are switched on with it.

**The screen now says so** (`HomepageSections::NESTED_NOTE`, pinned by
`HomepageSectionOrderTest`), because a row must not offer a control that is
silently overridden. **It is not repaired**, and the repair is a separate piece
of work from ordering:

- the band's wrapper takes the UNION of the three rows' visibility — visible on
  desktop when ANY of hero, delivery or ticker is on for desktop;
- the slider `<div class="slider" id="slider">` takes the hero's OWN `d-off` /
  `m-off`, which it does not carry today;
- `@unless ($sections->hidden('hero'))` becomes "all three hidden";
- `@if (count($banners) > 0)` around the slider also needs
  `! $sections->hidden('hero')`;
- `$heroCarriesH1` is already correct and must not change.

All of it is byte-neutral for a shop with the three rows on, which is every shop
that has not used those switches. It was left out of this lane because it is a
visibility change in the hero block of the hottest file in the repository, and
shipping it half-proved beside a fully-proved ordering change would be the worse
trade.

## Lifting the delivery strip and the ticker out of the hero, costed

`HomepageSections::NESTED` points here for this, so here it is. Making those two
genuinely movable means giving each its own `<section class="sec">` as a sibling
under `.kbb-home`, at which point they are flex children and `order` works on
all seventeen.

It is not free and it is not small:

- **It moves rendered bytes for every shop on the shipped layout.** The two are
  `<div>`s inside the hero's `.wrap` today; as siblings they need a `.sec` and a
  `.wrap` of their own, which is new markup, new whitespace and — because
  `.kbb-home .sec` carries `clamp(9px,1.1vw,14px)` of block padding and
  `.sec > .wrap` a card frame with 22px radius, a border and a shadow — a
  visibly different band. The hero stops being one unit and becomes three
  stacked cards. That is a design decision, not a refactor.
- **It changes `nth-of-type` for every section after it**, so the corner
  ornament's alternation shifts for the whole page even on a shop that never
  reorders anything.
- **It interacts with the visibility defect below.** Once they are siblings,
  their own Desktop/Mobile switches start working, which is the right outcome —
  but it means the two changes want to land together rather than separately.

Which is the same shape of answer FO reached about option 1, and the reason this
lane shipped the CSS route with the two rows named rather than the restructure.

## Two more, smaller

- **`hpWire()` previews the preset's stored sequence**, so the three presets
  whose ticker position is now normalised draw a wire-frame that disagrees with
  what applying them produces. Named in the console blocks above.
- **The corner ornament does not follow visual order** (measured above). Only a
  DOM reorder fixes it.
