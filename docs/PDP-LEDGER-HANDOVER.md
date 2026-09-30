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
tests/Feature/PerfDeliveryTest.php           one scan sharpened, §7b
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

> **The "before" column was measured in Poppins and the "after" in Outfit**, because
> the typeface changed in the same round (`be383a1`). Where a number is a *count of
> lines* or a *height*, part of the difference is the face and not the design; §4
> separates the two on the one row where it matters, the product name.

| | before | after |
|---|---|---|
| the photograph, on a phone | rounded box with a 1px border, inside the gutter | **full-bleed square, no frame**, thumbnails floating on its bottom edge |
| the name and the price | stacked, price below the rating | **one row** — name left, struck price above the live one on the right |
| the name, at 390 | 25px / weight 600 (2 lines, 64px in Poppins) | **19px / weight 500** — 2 lines, 52px (§4) |
| the name, at 1280 | 25px / weight 600 | **30px / weight 500** |
| the rating | pink lozenge | **stars · 4.8 · a 3px hairline filled to the rating · the count** |
| the blurb | full length | **three line-boxes, a real fade, "Read more ↓"** |
| between blocks | boxes and tints | **hairline rules** |
| the trust lines | a cream card, two columns | **one line each, no ground** |
| the tabs, at 1280 | underline row | underline row, **closed tabs at 38%** |
| the tabs, **at 390** | **an accordion** | **the same row**, scrolling (it overflows by 136px), its right edge dissolving |
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

## 4 · The mobile type — and the question got smaller

> *"also in mobile you have used big bold font, whichi dont' want."*

**Two pictures, and the second is the one that decides it:**

| | |
|---|---|
| his everyday name, 34 characters | `docs/lane-pdp-shots/sheet-real-type-390.png` |
| **his longest real name, 82 characters** | `docs/lane-pdp-shots/sheet-real-type-long-390.png` |

Both drawn in **Outfit**, the shop's typeface since `be383a1`. The first version of
this sheet was drawn in Poppins and its numbers are withdrawn.

### ▲ HALF THE COMPLAINT WAS ANSWERED BY A CHANGE HE MADE FOR ANOTHER REASON

He was looking at **21px at weight 600** and it came out at **three line-boxes,
82px tall** — `MEASUREMENTS.json` → `ledger-390`, title box 244 × 82, in Poppins.

The same two declarations on today's page, in Outfit, measure **two line-boxes,
55px**. Column **P** of both sheets is exactly that: the fault, redrawn in the
typeface his shop now uses.

Two things moved and both push the same way. Outfit is narrower per character,
and Outfit's price box is narrower too — 73px against Poppins' 86px — so the
title track it leaves behind is 257px where it used to be 244px.

**So the "big" half of "big bold" is already gone from his everyday name, and the
"bold" half is not.** Weight 600 against the weight 500 he approved on the
desktop is the same gap it always was; no typeface fixes that.

### The four columns, measured

| | title | lines / height, **34-char name** | lines / height, **82-char name** | price |
|---|---|---|---|---|
| **P** · as he saw it | 21px / **600** | 2 / 55px | 5 / 136px | 22px/800, 73–106px |
| **A** · weight only | 21px / 500 | 2 / 56px | 5 / 141px | 22px/800, 73–106px |
| **B** · one step down — *ships* | **19px / 500** | 2 / 52px | **4 / 103px** | 22px/800, 73–106px |
| **C** · price leads | 17px / 500 | 2 / 48px | 4 / 97px | 23px/700, 75–109px |

`scrollWidth` is 390 against a 390 viewport in all eight.

### C is withdrawn, and the measurement is why

C's whole argument was that the price should lead. It does not: at 23px/700 the
price box is **75px** against B's **73px** at 22px/800 — two pixels, which is not
an argument, it is a rounding. What C actually buys is a 17px product name — 3.5px
larger than the blurb underneath it, which is 13.5px, so the name stops reading as
a heading at all. Keeping it on the sheet to make the answer look like a choice of
three would be keeping the shape and losing the point.

### So the question is **A or B**, and it is one question

Both fix the weight, which is the half Outfit did not. They differ **only on long
names**:

- on his everyday name they are two line-boxes either way, 4px apart — nothing in
  it;
- on his longest real name **B is four lines and A is five**, 103px against
  141px. Thirty-eight pixels, and one fewer line, on the tail of his catalogue.

**B ships**, because the line it removes is removed on the products where the
complaint would recur and costs nothing on the products where it would not. **A
is the smaller change from what he approved** and is one declaration away —
`docs/PDP-LEDGER-BLOCKS.md` §3.

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

**Re-shot in Outfit** after `9de9044`. Chromium, 390x844 and 1280x900,
`deviceScaleFactor: 2`, on `tools/pdp-seed.php`'s fixture.
`document.documentElement.scrollWidth` equals the viewport on **every one of
these, in both languages**. Everything is in
`docs/lane-pdp-shots/MEASUREMENTS-REAL.json`.

> The full-page captures are stored at **1x** (a 390px shot is 390px wide — life
> size on the phone it is a picture of). The close-ups — the tab strip, the
> read-more, both type sheets, the notify form — are kept at 2x, where the detail
> is the point.

| case | slug | what it is there to answer | 390 title | 1280 title |
|---|---|---|---|---|
| toner | `pdp-heartleaf-toner` | on sale, 4 shots, 3 bundle bars, 5 reviews, 5 tabs | 19px / 2L / 52px | 30px / 1L / 37px |
| noreviews | `pdp-sold-out-serum` | **no reviews at all**, and **sold out** | 19px / 1L / 26px | 30px / 1L / 37px |
| many | `pdp-many-reviews-cream` | **96 reviews** | 19px / 2L / 52px | 30px / 2L / 74px |
| longname | `pdp-very-long-name-ampoule` | a 118-character name, and low stock | 19px / **5L** / 129px | 30px / 4L / 149px |
| set | `pdp-glow-ritual-set` | a **SET** — 0 bundle bars, 3 contents rows | 19px / 1L / 26px | 30px / 1L / 37px |
| variable | `pdp-variable-ampoule` | **real variations**, the first sold out | 19px / 2L / 52px | 30px / 2L / 74px |
| unbreakable | `pdp-unbreakable-name` | a name with **no break opportunity in it** | 19px / 3L / 77px | 30px / 3L / 112px |
| longest real | `pdp-longest-real-name` | **his own longest name**, 82 characters, at AED 2,450 | §4 | §4 |

Plus, on the same fixture:

- **The Arabic mirror**, `dir=rtl`, at both widths — `ar-real-*.png`. The tab row
  scrolls the other way (overflow 92px against 136px in English, the Arabic
  titles being shorter). One `[dir]` rule in the whole block and it is the edge
  mask, which has no logical form.

  **Measured rather than looked at**, on the rating hairline at 390:

  | | bar | filled part |
  |---|---|---|
  | English | x 117 → 161 | **117** → 159 — starts at the LEFT edge |
  | Arabic | x 229 → 273 | 231 → **273** — starts at the RIGHT edge |

  Same 42px of a 44px bar, from opposite ends, with no `[dir]` rule behind it.
- **The variable product's option strip.** The sold-out 30ml is struck through
  and tagged, the 50ml is the highlighted row, and the hidden `variation_id` is
  the 50ml's — the `$buyable` behaviour the shipped template already had.
- **The sticky Add to cart bar**, switched on: on screen at 390 (69px) and hidden
  at 1280, which is its `sticky_devices: phone` default, with `AED 74` in the bar
  matching `AED 74` in the block.
- **The notify-me form** on the sold-out product: 346x164 at 390, 582x146 at
  1280, under a grey disabled button.
- **The read-more**, clicked for real and operated by keyboard: the blurb goes
  86px → 240px, the mask comes off and the label disappears.
- **The fourth tab, opened by a real click** at both widths: exactly one panel
  visible and it is the fourth.

---

## 6c · The breadcrumb overflow, re-measured in Outfit — and it does not fire on his shop

The guard shipped in this lane (`.crumb{overflow-wrap:anywhere}` plus
`overflow-wrap:anywhere` on `.bb-title`) was written after the seventh fixture
scrolled the page sideways at 390. Re-measured on the merged tree:

| | scrollWidth at 390 |
|---|---|
| `pdp-unbreakable-name`, guards **on** | **390** |
| the same, guards **off** | **675** |

So the guard still does exactly what it did (675 in Outfit against 672 in
Poppins — a single unbroken token is far wider than the viewport in either face,
so the narrower type buys nothing here).

**But it does not fire on anything he sells, and that is the number that matters.**
Every one of the 22 names in `storage/catalog/products.json` — the real catalogue
import — was written into the breadcrumb and the title in turn, with the guards
**off**:

```
guards ON   0 of 22 overflow
guards OFF  0 of 22 overflow
```

Measured one character at a time, the thresholds are:

| | an unbroken run overflows at |
|---|---|
| the **title** (the 224–257px track) | **24 characters** |
| the **breadcrumb** (the full 346px line) | 36 characters |

and the longest unbroken run in his whole catalogue is **11 characters**
(`PA+++(50ml)`, in the Missha BB cream). **Thirteen characters of headroom on the
tighter of the two.**

So: the guard is correct, it costs one declaration each, and it is already in —
but it is insurance, not a fault he is about to hit. It fires on an import that
loses its spaces, a compound, or a run-together model code; none of those is in
the catalogue today. `storage/pdp-logs/crumb-probe.cjs` and
`crumb-threshold.cjs` are the two probes.

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
- **The breadcrumb / title wrap guards are insurance, not a live fault** — §6c
  has the measurement: 0 of his 22 real names overflow with the guards off, and
  the longest unbroken run he sells is 11 characters against a threshold of 24.
  The guards are in and they cost one declaration each; nothing more is owed.
- **The set contents panel is the one box left on the page.** Lane SF drew it
  from the owner's own marked-up screenshot and he answered *"i think this is
  already applied, skip it"*. CLAUDE.md rule 1's surviving half — nothing he did
  not ask about may change — says it stays.
- **The bundle bars' struck price and discount badge do not follow the chosen
  tier.** Scoped below — it is **one round**, and the estimate is in §8.


---

## 7b · One test sharpened, because a comment made it red

The stylesheet note recording *"re-measured after the shop moved from Poppins to
Outfit"* contains the word **Poppins**, and
`PerfDeliveryTest > it names one family for Latin text` scans every stylesheet
for that string **without stripping comments**. It went red on a sheet whose
every declaration says Outfit.

Its own claim is *"these still **ask for** Poppins"*. A comment asks for nothing:
the minifier removes it and no browser sees it, and the failure the case exists
to catch is a `font-family` falling through to `system-ui`, which is invisible in
review because it looks like a design choice. So the scan now strips CSS, Blade
and HTML comments first.

**Not a loosening, and both directions were run:**

| | |
|---|---|
| **M22** `Poppins` back in a real declaration in `kbb.css` | **RED**, and it names the file |
| **M23** the same string inside a CSS comment (a control) | **GREEN** — the behaviour, not a hole |

`storage/pdp-logs/mutate.py` now carries an expected outcome per mutation, so a
deliberate control cannot be read as a failure in the log.

---

## 8 · The bundle price block, scoped

Asked for as an estimate rather than a fix. **One round**, and the shape is
below.

### What it does now, read from the page and not from the template

`storage/pdp-logs/bundle-probe.cjs` presses each row on
`pdp-heartleaf-toner` and reads the block back:

| pressed | the row itself says | the block says | |
|---|---|---|---|
| 1 unit | AED 74 | ~~AED 99~~ **AED 74** −25% | correct |
| 2-pack bundle | ~~AED 149~~ AED 140 · Save 6% | ~~AED 99~~ **AED 140** −25% | **wrong twice** |
| 3-pack bundle | ~~AED 223~~ AED 198 · Best value | ~~AED 99~~ **AED 198** −25% | **wrong twice** |

So it is not only the strike. The **badge is wrong too**, and it is the worse of
the two: "−25%" is a discount claim, and on the 2-pack the real saving is 6%.
The row and the block contradict each other in one eyeful — the same "two
renderings of one number must not be quoted two ways" rule this template already
carries, one level up.

`pdp.js`' `setPrice()` writes `.now` and nothing else, deliberately and with a
comment saying so. It predates Ledger; what Ledger changed is the arrangement,
from *live then struck, side by side* to *struck above live*.

### Why the obvious fix is wrong, precisely

Copying the row's own `<s>` into the block breaks the row it opens on.
`BundleService::forProduct()` computes `$was = $unit * $qty` where `$unit` is
**`effectivePrice()`, the sale price** — so for the 1-unit tier `was === total`,
`saved` is 0 and the row prints no struck figure at all. Take the row's pair
literally and the default view loses "AED 99 / −25%", which is the product's own
markdown and the one figure the page is really about.

The two "was" figures are different things and both are true:

- the **product's** — `compareAtPrice()`, AED 99: what it cost before the sale;
- the **tier's** — `qty × effectivePrice`, AED 149: what two cost without the
  bundle discount.

A correct fix has to know which one a given row means.

### What each file would have to do

**`app/Services/BundleService.php` — nothing.** It already returns `was`,
`saved` and `percent` per tier. That was the open question in the first pass and
the answer is that there is no service change at all.

**`resources/views/store/product.blade.php`** — two attributes per row, in the
two loops that already compute the figures:

- the bundle loop: `data-was` = `$b['was']` at `$bdp` and `data-off` =
  `$b['percent']`, **except on the tier whose `saved` is 0**, where they are the
  product's own `$kbbWas` / `$off` — which is exactly what the block renders
  server-side, so the default view is unchanged to the byte;
- the variant loop: `data-was` = `$vreg` when `$vsale < $vreg`, `data-off` =
  `$voff`, and empty otherwise.

Both go inside the `@php` blocks that are already there, so no directive gains a
line of its own and `StorefrontEnglishUnchangedTest` sees two attributes, not a
reflow.

**`resources/js/kbb/pdp.js`** — `setPrice()` takes the three figures instead of
one and writes or removes `<s>` and `.off` alongside `.now`. It must create them
when they are absent, which is the same reason it already creates `.now`, and it
must escape them the way `data-price` is escaped — the `currency_symbol` is a
free-text SETTING and that file already carries the note about it (CLAUDE.md
rule 5).

**The build** — `npx vite build`; `app-*.js` moves because its content really
moves, which is what `BuiltAssetNamesAreStableTest` is there to distinguish from
a stylesheet dragging the name along.

### What would pin it

- a pest case that each row emits `data-was`/`data-off` equal to what that row
  itself prints, **and that the 1-unit row carries the product's own pair** —
  which is the assertion the naive fix fails, so it is the one worth writing
  first;
- a harness shot: press the 2-pack, read `<s>`, `.now` and `.off`, expect
  AED 149 / AED 140 / −6%. `bundle-probe.cjs` already does the reading.

### Out of scope, and it is what would make it two rounds

- **the sticky bar**, which carries only a `.now` today and would need the same
  treatment and its own shots;
- **the schema.org offer block**, which quotes one price and must not follow a
  UI selection — a crawler reading a price the shopper picked is a worse defect
  than the one being fixed.

Leave both alone and it is one round.
