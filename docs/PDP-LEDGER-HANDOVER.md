# Lane PDP2 · Ledger is the product page

> *"Ledger design is fine for mobile and desktop both. but don't end the page,
> this desgn + existing reviews section, and related products section and then
> footer. also in mobile you have used big bold font, whichi dont' want."*

He is answering `docs/PDP-PRODUCT-PAGE-DESIGNS.md`, which put five whole product
pages in front of him at `/admin-api/catalog/pdp-preview/{design}/{slug}`. He
picked **A · Ledger**, and under the rule he set on 30 September — *"whatever i
said, keep applying on the site"* — it is the page the shop serves now. There is
no switch and nothing to go and turn on.

**The one thing still open is the mobile type**, §4. He asked to *see* options
for that, so it is a preview: three at 390px with the measured numbers under
each, and a two-line diff once he picks a letter.

---

## 0 · There is nothing for the integrator to wire

Stated rather than left out, so nobody goes looking.

| file | edit |
|---|---|
| `routes/web.php` | **none** — this lane adds no route |
| `resources/views/admin/app.blade.php` | **none** — this lane adds no admin screen |

Ledger is an edit to the product page's own template and its own stylesheet.
Every control it touches already existed and still reads its setting.

**And there is no new switch either.** The round before last put a `?layout=`
map inside `resources/views/store/product.blade.php` so three drawings could be
compared on the live catalogue; none was chosen and ~240 lines of dead CSS were
left for another lane to delete (`docs/PP-PRODUCT-PAGE-PROPOSALS.md` carries the
banner). Ledger is not built that way and is not behind a setting: he picked it
out of five, so it is the page. **Undoing it is a revert of one region of one
template and one block of one stylesheet** — both named in §1 — not a toggle to
go and find.

**What the package needs instead is in §1 and §2**, and §2 is the one that
silently does nothing if it is left out.

---

## 1 · What goes in the package

```
resources/views/store/product.blade.php      the markup, one region of it
resources/css/kbb/kbb-product.css            the Ledger block, at the foot
public/build/assets/kbb-product-<hash>.css  NEW — the built bundle
public/build/manifest.json                   MUST travel with it
tests/Feature/ProductPageLedgerTest.php       new
tests/Feature/PriceDisplayTruthTest.php      two pins advanced, §5
tests/Support/EnglishRenderWalk.php          the paired approved rule
tools/pdp-seed.php  tools/pdp2-shots.cjs     the harness (not served)
docs/…                                       this file, the blocks and the shots
```

**`manifest.json` is not optional and is the easiest file in this list to
forget.** `@vite('resources/css/kbb/kbb-product.css')` resolves the hashed name
out of the manifest, so a package that ships the new `kbb-product-<hash>.css` without
the manifest leaves the shop asking for `kbb-product-CJbrmkEW.css` — which is
still on disk, so there is no 404 and no error anywhere: the page simply renders
with yesterday's stylesheet and looks untouched. That is the shape of a defect
this project has already paid for once (`BuiltCssIsCurrentTest`'s docblock:
*"a control wired end to end in the source, shipped, and moving nothing"*).

The old `kbb-product-CJbrmkEW.css` may be deleted or left; nothing references it
once the manifest moves.

---

## 2 · The package needs a `clear_caches_*` migration

`resources/views/store/product.blade.php` is **compiled** on the server into
`storage/framework/views/`. Blade recompiles when the source file's mtime is
newer than the compiled copy's — and a zip applied through Store → Core Updates
does not guarantee that ordering. The convention in this repo is that a package
touching a Blade ships a `clear_caches_*` migration, and `update.json` must
**declare** it in `migrations` or `UpdateRunner::hasMigrations()` never looks at
the files and the migration is copied to the server and never run. That is
CLAUDE.md's first landmine, verbatim.

No route was added, so the route cache is not the reason — the view cache is.

---

## 3 · What the owner will see, and where

Nothing to find in the admin: open any product page.

| | before | after |
|---|---|---|
| the photograph, on a phone | rounded box with a 1px border, inside the gutter | **full-bleed square, no frame**, thumbnails floating on its bottom edge |
| the name and the price | stacked, price below the rating | **one row** — name left, struck price above the live one on the right |
| the name, at 390 | 25px / weight 600 / 2 lines / 64px | **19px / weight 500 / 2 lines / 52px** (§4) |
| the name, at 1280 | 25px / weight 600 | **30px / weight 500** |
| the rating | pink lozenge | **stars · 4.8 · a 3px hairline filled to the rating · the count** |
| the blurb | full length | **three line-boxes, a real fade, "Read more ↓"** |
| between blocks | boxes and tints | **hairline rules** |
| the trust lines | a cream card, two columns | **one line each, no ground** |
| the tabs, at 1280 | underline row | underline row, **closed tabs at 38%** |
| the tabs, **at 390** | **an accordion** | **the same row**, scrolling (it overflows by 187px), its right edge dissolving |
| the clamped tab body | a **white block** painted over the last two lines, on a page whose background is pink | a fade, by `mask-image`, correct on any ground |
| a sold-out Add to cart | **full brand pink**, and reads as pressable | grey and `not-allowed`, which is what the drawing he chose shows |
| a one-sentence blurb, or a one-line tab body | the fade ate the only line it had | painted flat — the fade now bites only text that is really being cut |

**The controls he already had all still work**, and each is pinned by a case in
`ProductPageLedgerTest`:

- Store → Ecommerce → Product page → **Review badges** — capsule / inline / both.
  The hairline is in *both* rating rows, so moving that control does not lose it.
- Catalog → Product page → **Sections** — reviews, tabs, short description,
  trust, bundles, buy-now, sticky bar. All still read.
- Appearance → Product styles → **Sticky Add to Cart** — untouched, still off.
- Store → Ecommerce → **Authenticity line**, Store → Delivery & Shipping →
  **Delivery lines** — untouched, still the only source of those two sentences.

**Four things below the buy column are exactly what they were**: the
frequently-bought strip, the details section, the reviews and the related grid.
`StorefrontEnglishUnchangedTest` compares them byte for byte on the product page
and on every other storefront page — see §5.

---

## 4 · The mobile type — HE PICKS ONE

> ### ▲ THE NUMBERS BELOW WERE MEASURED IN POPPINS, AND THE SHOP IS MOVING TO OUTFIT
>
> This branch was cut at `768a6e8`. `a29a9dc` — *"Outfit is the shop's typeface"*
> — landed on the integrator's branch afterwards and changes `--sans` in this very
> stylesheet, so **every line count and block height in this section and in §6b was
> read with Poppins on the page.** Outfit is a different face at the same nominal
> size: the *sizes* and *weights* below are what they say, and the *line counts*
> and *heights* may not be.
>
> **The decision is robust to that and the arithmetic is not.** What he objected to
> is weight 600 against the weight 500 he approved on the desktop, and that
> comparison does not depend on the face. What may move is whether a given name
> still comes out at two line-boxes.
>
> `sh storage/pdp-logs/round3.sh` re-takes the whole sheet in about four minutes,
> so **re-shoot `sheet-real-type-390.png` after the merge** and show him that one
> rather than this one.



> *"also in mobile you have used big bold font, whichi dont' want."*

**The picture: `docs/lane-pdp-shots/sheet-real-type-390.png`.** Three phones side
by side, same product, same moment, one scale, the measured numbers under each.

Measured on the preview he was complaining about: **21px at weight 600 over three
line-boxes, 82px tall**, beside a 22px/800 price. The desktop he approved in the
same sentence is **30px at weight 500** — so the *weight* is what differs between
the half he likes and the half he does not, and the third line is what makes the
block tall.

| | title | weight | line-boxes | height | price |
|---|---|---|---|---|---|
| the preview he saw | 21px | 600 | 3 | 82px | 22px / 800 |
| **A · weight only** | 21px | **500** | 2 | **56px** | 22px / 800 |
| **B · weight and one step down** — *shipped* | **19px** | **500** | 2 | **52px** | 22px / 800 |
| **C · price leads** | 17px | 500 | 2 | 48px | **23px / 700** |

B ships because it answers both halves of the complaint — not bold, and not big —
and because with the title quiet the price is already the loudest thing on the
phone, which is what a shop wants. A is the smallest possible change from what he
approved. C goes one step further and hands the emphasis to the price outright.

**If he picks A or C**, it is this one block in
`resources/css/kbb/kbb-product.css` and nothing else.

**Anchor** (occurs once):

```
.pdp .bb-title{font-size:19px;font-weight:500;line-height:1.36;letter-spacing:-.005em}
```

**Replacement for A:**

```
.pdp .bb-title{font-size:21px;font-weight:500;line-height:1.34;letter-spacing:-.005em}
```

**Replacement for C:**

```
.pdp .bb-title{font-size:17px;font-weight:500;line-height:1.42;letter-spacing:-.005em;color:var(--ink-2)}
```

C also needs the price raised. **Anchor** (occurs once):

```
.pdp .bb-head .bb-price .now{display:block;font-size:22px;font-weight:800;letter-spacing:-.02em}
```

**Replacement for C:**

```
.pdp .bb-head .bb-price .now{display:block;font-size:23px;font-weight:700;letter-spacing:-.02em}
```

Then `npx vite build`, commit `public/build`, and move the two literals in
`ProductPageLedgerTest > it ships the quiet mobile title…` to match — the case
pins the shipped values by name precisely so this stays a two-line change with a
test that notices it.

**The desktop is not touched by any of the three.** `.pdp .bb-title` inside
`@media (min-width:881px)` is 30px/500 in all cases; he said the desktop is fine.

---

## 5 · What moved, and what did not

`StorefrontEnglishUnchangedTest` renders all thirty-nine storefront pages that
come out of a Blade template — 36 routes, plus the 404 and the two pages a
shopper only reaches with a basket — from the base commit and from this tree,
and compares them **byte for byte**. With this branch applied
it reports **one** difference, on **one** page, in **one** region:

```
product/{slug}   from <h1 class="bb-title"> to <form class="cart">
```

That region is excused by a **paired** rule —
`EnglishRenderWalk::approvedRemovals()['the shipped head of the product buy
column (Lane PDP2)']` and `approvedInsertions()['the Ledger head of the product
buy column (Lane PDP2)']` — each required to fire exactly once. Everything
outside it, on that page and on every other one, is still compared byte for byte:
the `<head>`, the header, the breadcrumb, the gallery, the whole cart form, the
bundle bars, the set contents panel, the stock line, the buy row, the trust
lines, the payment chips, the tabs, the reviews section, the related grid and the
footer.

**The cut gives up four things** — the product name, both price figures, the VAT
sentence and the review-badge label. `ProductPageLedgerTest > it prints the
product name, both price figures, the VAT line and the badge label` asserts each
of them against the element that prints it. A rule that cuts more than its own
element has to say what it stopped watching.

---

## 6 · The five previews are still mounted, and that is deliberate

`routes/web.php:706` already carries `require __DIR__.'/pdp-preview-admin.php';`,
so Catalog → Product page → Design previews still works and all five drawings are
still there. **Nothing in this branch removes them**, for two reasons: taking
them out means editing `routes/web.php`, which is the integrator's file, and the
owner may want to look at the other four again while he is choosing a type.

When they are to go, `docs/PDP-PRODUCT-PAGE-DESIGNS.md` carries the exact file
list. It is an integrator change because of that one line, and `EverythingIs-
MountedOnceTest` is what says the require and the file left together.

---

## 6b · What was checked at both widths, and on what

> The full-page captures are stored at **1x** (a 390px shot is 390px wide — life
> size on the phone it is a picture of) because two of them at 2x cost more than
> this whole round's tests. The close-ups — the tab strip, the read-more, the
> type sheet, the notify form — are kept at 2x, where the detail is the point.
> `before-toner-390.png` and `before-toner-1280.png` are the same product on the
> page as it was.

Chromium, 390x844 and 1280x900, `deviceScaleFactor: 2`, on
`tools/pdp-seed.php`'s fixture. `document.documentElement.scrollWidth` equals
the viewport on **every one of these, in both languages** — no horizontal page
scroll anywhere. Everything is in
`docs/lane-pdp-shots/MEASUREMENTS-REAL.json`.

| case | slug | what it is there to answer | 390 title | 1280 title |
|---|---|---|---|---|
| toner | `pdp-heartleaf-toner` | on sale, 4 shots, 3 bundle bars, 5 reviews, 5 tabs | 19px / 2 lines / 52px | 30px / 2 lines / 74px |
| noreviews | `pdp-sold-out-serum` | **no reviews at all**, and **sold out** | 19px / 2 / 52 | 30px / 1 / 37 |
| many | `pdp-many-reviews-cream` | **96 reviews** | 19px / 2 / 52 | 30px / 2 / 74 |
| longname | `pdp-very-long-name-ampoule` | a **118-character** name, and low stock | 19px / **5 / 129px** | 30px / 5 / 186px |
| set | `pdp-glow-ritual-set` | a **SET** — 0 bundle bars, 3 contents rows | 19px / 1 / 26 | 30px / 1 / 37 |
| variable | `pdp-variable-ampoule` | **real variations**, the first sold out | 19px / 3 / 77 | 30px / 2 / 74 |
| unbreakable | `pdp-unbreakable-name` | a 56-character name with **no break opportunity in it** | 19px / 3 / 77 | 30px / 3 / 112 |

Plus, on the same fixture:

- **The Arabic mirror of all seven**, `dir=rtl`, at both widths —
  `ar-real-*.png`. The tab row scrolls the other way (overflow 94px against 187px
  in English, the Arabic titles being shorter). One `[dir]` rule in the whole
  block and it is the edge mask, which has no logical form; everything else is
  `inline-size` / `margin-inline` / `border-block-start` and mirrors itself.

  **Measured rather than looked at**, on the rating hairline at 390:

  | | bar | filled part |
  |---|---|---|
  | English | x 117 → 161 | **117** → 159 — starts at the LEFT edge |
  | Arabic | x 229 → 273 | 231 → **273** — starts at the RIGHT edge |

  Same 42px of a 44px bar, from opposite ends, with no `[dir]` rule behind it.
  The price box is to the *left* of the title on `/ar` and to the *right* on the
  English page — the inline end in both.
- **The variable product's option strip.** The sold-out 30ml is struck through
  and tagged, the 50ml is the highlighted row, and the hidden `variation_id` is
  the 50ml's — the `$buyable` behaviour the shipped template already had, still
  intact under Ledger. `real-variable-390.png`.
- **The sticky Add to cart bar**, switched on: on screen at 390 (69px tall) and
  hidden at 1280, which is its `sticky_devices: phone` default, with `AED 74`
  in the bar matching `AED 74` in the price block. `real-sticky-390.png`.
- **The notify-me form** on the sold-out product, module on and the prose
  written: 346x186 at 390, 582x150 at 1280, under a grey disabled button.
  `real-notify-390.png`.
- **The read-more**, clicked for real and operated by keyboard: the blurb goes
  86px → 240px, the mask comes off and the label disappears, on an ordinary
  product and on a set. `real-readmore-closed-390.png` / `-open-390.png`.
- **The fourth tab, opened by a real click** at 390 and at 1280: exactly one
  panel visible and it is the fourth. `real-toner-tabs-open3-390.png`.

**And one defect the seventh fixture found that nobody was looking for.** With a
name that has no space and no hyphen in it, the page scrolled sideways at 390 —
`scrollWidth` 437 against a 390 viewport. Two separate causes, both measured by
hiding one element at a time:

1. the **title**, whose track `minmax(0,1fr)` deliberately lets shrink below its
   content, and which a grid does not clip — 672 without `overflow-wrap:anywhere`
   and 390 with it;
2. the **breadcrumb**, which prints the same name unguarded and overflows as a
   TEXT NODE, so no element's bounding box reports it. Hiding `.crumb` alone
   brought 437 back to 390 while hiding the title, the tab row, the reviews and
   the related grid each changed nothing.

The breadcrumb one is older than this lane and had nothing to do with Ledger.
`.crumb` is declared in `kbb-product.css`, so the one declaration that fixes it
reaches the product page and nothing else.

*And the first version of that fixture proved the opposite*: it was named
`Anua-Heartleaf-77-Percent-…`, and a hyphen is a break opportunity whatever
`overflow-wrap` says — so it wrapped perfectly well with both guards off and
measured 390 either way. A fixture that cannot exercise the rule it exists for is
the same defect as an assertion that cannot fail.

---

## 7 · Found and not fixed

- **A sold-out product still shows live bundle bars and an "Order within 10h 49m
  for delivery by …" line.** Both predate this lane and neither is in the brief;
  `docs/lane-pdp-shots/real-noreviews-390.png` shows them under the greyed-out
  Sold out button.
- **"Read more ↓" is printed under a blurb that is not being cut off.** With the
  fade corrected the short blurb is painted flat, so the label opens a cap that
  was not biting and nothing visibly happens. Whether a given blurb runs past
  three lines is a LAYOUT question, and CLAUDE.md rule 4 forbids asking it in the
  browser; the server could guess from the character count, but a guess that came
  out wrong would hide the control on a blurb that IS clipped, which is the worse
  of the two. Left as it is — the shipped tab panel's own "Read more" has always
  behaved the same way.
- **The set contents panel is the one box left on the page.** Lane SF drew it
  from the owner's own marked-up screenshot and he answered *"i think this is
  already applied, skip it"*. CLAUDE.md rule 1's surviving half — nothing he did
  not ask about may change — says it stays.
- **The bundle bars' struck price does not follow the chosen tier.** Pick the
  2-pack and `.now` becomes AED 140 while the `<s>` still quotes AED 99, the
  parent's regular price — "was 99, now 140". `pdp.js:setPrice()` writes `.now`
  and nothing else, by design and with a comment saying so, and that is exactly
  as wrong as it was before Ledger; what changed is the arrangement, from
  *live then struck, side by side* to *struck above live*.

  **Not fixed here, and the reason is that the obvious fix is wrong.** Copying
  the row's own `<s>` into the block breaks the row that has none: the 1-unit
  bundle carries `saved = 0` and prints no struck figure, so the page would lose
  "AED 99 / −25%" on the tier it opens with. A correct fix has to know the
  difference between *this tier has no markdown* and *this tier is the headline*,
  which is a change to `BundleService`'s output and to `pdp.js`, in a file this
  lane has no reason to open. Flagged for whoever owns the bundle strip.
