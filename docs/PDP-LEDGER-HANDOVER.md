# Lane PDP2 · Ledger is the product page

> *"Ledger design is fine for mobile and desktop both. but don't end the page,
> this desgn + existing reviews section, and related products section and then
> footer. also in mobile you have used big bold font, whichi dont' want."*

He is answering `docs/PDP-PRODUCT-PAGE-DESIGNS.md`, which put five whole product
pages in front of him at `/admin-api/catalog/pdp-preview/{design}/{slug}`. He
picked **A · Ledger**, and under the rule he set on 30 September — *"whatever i
said, keep applying on the site"* — it is the page the shop serves now. There is
no switch and nothing to go and turn on.

**The mobile type is settled**, §4: re-measured in Outfit, C withdrawn on the
measurement, and the coordinator's answer on the rest was *"B ships and it stays
shipped."* A stays one declaration away in `docs/PDP-LEDGER-BLOCKS.md` Block 3 if
he ever wants it.

**Round 3 is §8: the bundle price block.** Pressing a bundle row left the price
block quoting the product's own markdown beside the tier's total — a struck
figure that lagged and, worse, a **−25% badge on a tier discounted 6%**. Fixed
in place, with the sticky bar and the schema.org offer settled by measurement
rather than assumed.

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
resources/views/store/product.blade.php       the markup, one region of it,
                                              plus the two data attributes (§8)
resources/css/kbb/kbb-product.css             the Ledger block, at the foot
resources/js/kbb/pdp.js                       ROUND 3 — setPrice(), §8
public/build/assets/kbb-product-<hash>.css    NEW — the built stylesheet
public/build/assets/app-<hash>.js             NEW — the built script, round 3
public/build/manifest.json                    MUST travel with both
tests/Feature/ProductPageLedgerTest.php       new
tests/Feature/ProductPriceBlockFollowsTierTest.php   ROUND 3 — new
tests/Feature/PriceDisplayTruthTest.php       two pins advanced, §5
tests/Feature/PerfDeliveryTest.php            one scan sharpened, §7b
tests/Support/EnglishRenderWalk.php           two paired approved rules
tools/pdp-seed.php  tools/pdp2-shots.cjs      the harness (not served)
tools/pdp2-tier-shots.cjs                     ROUND 3 — the before/after shots
tools/pdp2-settle-probe.cjs                   ROUND 3 — the sticky bar and the
                                              schema block, read from a browser
docs/…                                        this file, the blocks and the shots
```

**Round 3 is the first of these rounds to move JavaScript**, so the package
needs `public/build/assets/app-<hash>.js` as well as the stylesheet. Ship the
template without it and every row carries `data-was`/`data-off` that nothing
reads: the block goes on printing the wrong discount badge, with the fix
apparently applied.

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
| **pressing a bundle row or a size** (round 3) | the struck figure and the discount badge stayed at the product's own, so the 2-pack read **−25%** while its row read *Save 6%* | both follow the row — **~~AED 149~~ AED 140 −6%** — and a variation's block stops disagreeing with the row beneath it (§8) |

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

- **Round 3's before/after**, `tier-{before,after}-{bundle,variable}-row{n}-{390,1280}.png`
  — ten pictures and `MEASUREMENTS-TIER-{BEFORE,AFTER}.json`. Each is the buy
  column with one row pressed, shot as an ELEMENT rather than a clip rectangle:
  Playwright measures `clip` against the viewport on an ordinary screenshot and
  against the page on a full-page one, and pressing a row scrolls, so a
  page-coordinate clip came back as a picture of the trust block twenty rows
  further down. The site header and the sticky bar are made static for the shot
  — both would otherwise sit on top of the title once the column is scrolled
  into view — and the figures are read from `textContent`, which does not care.
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
- ~~**The bundle bars' struck price and discount badge do not follow the chosen
  tier.**~~ **Fixed this round — §8.** It was one round, as scoped.
- **A variable product's block opens on a RANGE while a row is already
  highlighted.** Measured on `pdp-variable-ampoule` as served: the 50ml row is
  the pre-selected one and prints `AED 99 / AED 79`, while the block above reads
  `AED 69 – AED 127`. The range is the server's headline for a variable product
  (`$kbbHeadline`) and predates this lane by a long way; the moment anything is
  pressed the block resolves to that row's own pair, which is round 3's change.
  Making the FIRST PAINT agree would mean the server rendering the pre-selected
  variation's price instead of the range — a change to what every variable
  product's tile-to-page journey says, which is the owner's call and not a
  defect to fix inside a bundle-price round. Named here rather than left for
  somebody to rediscover.


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

## 8 · The bundle price block — built

Scoped last round as one round; it was one round. **`BundleService` did not
change**, which was the open question and the answer.

### Where it sits in the admin

**Nowhere — there is no setting, and that is deliberate.** This is a defect
fixed in place, not a control. The rows it corrects are drawn by
**Store → Products → Quantity bundles** (the tier table) and by a variable
product's own variations; neither screen gains a field, and neither default
moves. Nothing on the page changes until a shopper presses a row.

### The defect, in one picture and four numbers

`docs/lane-pdp-shots/tier-before-bundle-row1-390.png` beside
`tier-after-bundle-row1-390.png`, on `pdp-heartleaf-toner` with the 2-pack
pressed:

| | struck | live | badge |
|---|---|---|---|
| the row says | AED 149 | AED 140 | Save 6% |
| **before** — the block said | **AED 99** | AED 140 | **−25%** |
| **after** — the block says | AED 149 | AED 140 | −6% |

The strike was untidy. **The badge was a false claim about money**: −25% printed
beside a tier discounted 6%, on the page where the shopper decides. That is the
defect; the strike is its sibling.

Every row, at 390 and at 1280, read out of the page by
`tools/pdp2-tier-shots.cjs` into
`docs/lane-pdp-shots/MEASUREMENTS-TIER-{BEFORE,AFTER}.json`:

| pressed | the row says | before | after |
|---|---|---|---|
| 1 unit | AED 74 | ~~99~~ **74** −25% | ~~99~~ **74** −25% (unchanged) |
| 2-pack | ~~149~~ 140 · Save 6% | ~~**99**~~ **140** **−25%** | ~~149~~ **140** −6% |
| 3-pack | ~~223~~ 198 · Best value | ~~**99**~~ **198** **−25%** | ~~223~~ **198** −11% |
| 50ml (variable) | ~~99~~ 79 · Save 20% | **AED 79**, flat | ~~99~~ **79** −20% |
| 100ml (variable) | ~~169~~ 127 · Best value | **AED 127**, flat | ~~169~~ **127** −25% |

The two variable rows are the reason the block is allowed to **create** the
spans. A variable parent carries no price of its own, so `isOnSale()` is false
on it and the server renders `.now` alone — the block and the row then disagreed
on one page even though nothing was false.

### Why the obvious fix is wrong, and it is the whole of the difficulty

Copying the row's own `<s>` breaks the row the page **opens on**.
`BundleService::forProduct()` computes `$was = $unit * $qty` where `$unit` is
**`effectivePrice()`, the sale price** — so the 1-unit tier has `was === total`,
`saved` of 0 and no struck figure at all. Take the row's pair literally and the
default view silently loses "AED 99 / −25%", the product's own markdown and the
one figure the page is really about.

The two "was" figures are different things and both are true: the **product's**
(`compareAtPrice()`, AED 99 — what it cost before the sale) and the **tier's**
(`qty × effectivePrice`, AED 149 — what two cost without the bundle discount).
A row with a saving of its own means the second; a row without one falls back to
the first.

### What changed, in three places

**`app/Services/BundleService.php` — nothing.**

**`resources/views/store/product.blade.php`** — two attributes per row, composed
inside the `@php` blocks that already compute the figures:

```php
$bHasOwn = $b['saved'] > 0;
$bWas = $bHasOwn ? Money::plain((int) $b['was'], $bdp) : ($onSale ? Money::plain($kbbWas, $kbbSaleDp) : '');
$bPct = $bHasOwn ? (int) $b['percent'] : ($onSale ? (int) $off : 0);
$bOff = $bPct > 0 ? \App\Support\Bidi::number('-' . $bPct . '%') : '';
```

`Money::plain()`, not `Money::format()`, because the value crosses into
JavaScript where markup would have to be trusted — the same reason `data-price`
beside it is already plain — and at the row's own `$bdp`, so the block cannot
quote one number at two widths. The badge TEXT is composed on the server because
`Bidi::number()` wraps it in isolates on `/ar`, which a string built as
`'-' + n + '%'` in the browser would not have.

**`resources/js/kbb/pdp.js`** — `setPrice()` takes the pair and a `mayCreate`
flag, updates `<s>` and `.off` where they exist, creates them in Ledger's order
where the caller allows it, and hides an empty one with `style.display` rather
than `[hidden]` — `kbb-product.css` gives both spans a `display` through a class
selector, which outranks the User-Agent rule behind the attribute. Both figures
go through `escapeHtml()` for the reason already written above `data-price`:
`currency_symbol` is a free-text setting and the HTML parser decodes the
attribute before JavaScript reads it (CLAUDE.md rule 5).

**The build** — `npx vite build`. `app-jOqRtoT7.js` → `app-BLfAa6WG.js`
(46.84 kB → 47.28 kB); the name moved because the content moved, which is what
`BuiltAssetNamesAreStableTest` exists to distinguish.

### The two things the coordinator asked be settled rather than assumed

**1 · The sticky bar does not go stale, and it is now protected from being
given the problem.** Measured before any change, `tools/pdp2-settle-probe.cjs`:
its `.now` already followed the tier (AED 74 → AED 140) and it carries
**neither** a strike **nor** a badge — `struck: null, off: null` before and
after. There is nothing there to lag. The real risk was the reverse: a fix that
created the two spans everywhere would have **handed** the bar the defect while
curing it upstairs. So `#stickyPrice` is called with `mayCreate: false`, and
`M28` — passing `true` — is red.

**2 · The schema.org offer cannot be fed a selected price.** It is built
server-side by `App\Support\Seo::render()` into `<head>`, long before the buy
column exists, and the script writes only into `#bbPrice` and `#stickyPrice`.
Measured after pressing the 2-pack: the offers array, `og:price` (74.25) and the
whole JSON-LD block were byte-identical, **5424 bytes both times**. The pest case
pins the structure rather than the observation — the structured data is emitted
before `id="bbPrice"` appears, `<head>` carries no copy of either id, the JSON
quotes the unit price and never the 2-pack's AED 140.85, and every `setPrice()`
call in the file is **enumerated**: `['bbPrice', 'stickyPrice']`, exactly, so a
later lane pointing the writer at a third element is red (M35) rather than
merely absent from a scan.

### And the badge is composed on the server, which `/ar` is the reason for

`App\Support\Bidi::number()` wraps a signed number in LRI … PDI so `-6%` does
not repaint as `6%-` inside a right-to-left paragraph. A string assembled in the
browser as `'-' + n + '%'` would carry no isolates, and the defect would be
**invisible to anybody reading the English page** — `Bidi::number()` returns the
token unchanged in the default locale, so no English byte moves. Hence
`data-off` holds the whole word `-6%` rather than the number 6, and the case that
pins it renders `/ar` with the mirror on.

### What pins it

`tests/Feature/ProductPriceBlockFollowsTierTest.php` — 9 cases, 78 assertions.

| mutation | |
|---|---|
| **M24** the naive fix: the row's own pair, copied literally | **RED** — and *only* `falls the 1-unit row back to the product own markdown` |
| **M25** the badge half of the same naive fix | **RED** |
| **M26** the tier stops handing over its pair (the defect as it shipped) | **RED**, 3 cases |
| **M27** a variation stops handing over its pair | **RED** |
| **M28** the sticky bar is allowed to grow a strike | **RED** |
| **M29** the block is forbidden from growing one | **RED** — the variable product |
| **M30 / M31** either figure crosses out of the attribute unescaped | **RED** |
| **M32** the strike is inserted below the live figure | **RED** |
| **M33** an empty span hidden with `[hidden]` instead of `display` | **RED** |
| **M34** the badge drops `Bidi::number()` and is built as a bare string | **RED** — on `/ar` only, which is the point |
| **M35** the price writer is pointed at a third element | **RED** — the schema case enumerates its two targets rather than scanning for an absence |

`tools/plc-needle-scan.sh` over the new file: **5 `toContain` needles, 0
ambiguous sites, 0 holes.** Every prose message in it goes through `tierHas` /
`tierLacks`, which wrap `str_contains`, because Pest's `toContain()` is variadic
— a message passed after the needle is asserted as a second needle, and
`->not->toContain($needle, $why)` can never fail at all. One assertion in the
first draft had exactly that shape and `ExpectationsThatCannotFailTest` is the
sweep that would have caught it.

`StorefrontEnglishUnchangedTest` carries a **paired** removal/insertion for the
row's **opening tag only** — both patterns close on `(?=<span class="vr")`, so
the label, both figures and the tag badge are still compared byte for byte on
both sides. What the pair excuses is the two attributes and the blank line the
`@php` block adds. `data-i`, `data-qty`, `data-price` and the `on`/`oos` classes
stopped being watched there and are asserted element by element in the new file
instead.

---

## 9 · Round 4 — the Layout screen, and the tabs that had nothing in them

Two requests, both verbatim:

> *"also i have control on the product page spacing between sections and
> elements etc. and fonts sizes control etc. pleas give me proper tabs for that
> on the product page > Layout."*

> *"also put some demo tabs on the product page, so i can see in action."*

### 9a · `Appearance → Product page` now has a tab strip

It was a flat list of seventeen on/off switches out of
`ProductSections::REGISTRY` and nothing else — checked before building, not
remembered. The switches are untouched and sit behind **Sections**; beside them
are four Layout tabs:

| tab | admin path | controls |
|---|---|---|
| Spacing · Page | `Appearance → Product page → Spacing · Page` | 5 |
| Spacing · Buy column | `Appearance → Product page → Spacing · Buy column` | 8 |
| Type · Buy column | `Appearance → Product page → Type · Buy column` | 12 |
| Type · Sections & tabs | `Appearance → Product page → Type · Sections & tabs` | 5 |

`App\Services\ProductLayout` is the schema, drawn through
`ModuleSchema::tabs()` — the same payload `Appearance → Product styles` and the
Newsletter screen already draw, so the console renders it with the renderer it
already has and nothing about the screen shape is new.

**No route was added.** The Layout half travels through the two
`/admin-api/product-page` paths that already exist, so `routes/web.php` is
untouched and **there is nothing for the integrator to wire**, exactly as §0
says of the rest of this lane.

**Sizes are stored in tenths of a pixel** (13.5px is `135`) and line heights in
hundredths, because `ModuleSchema`'s `range` is an integer and the page's own
type is 13.5 and 1.62. The console divides by the field's `scale` to draw the
label; the stored number is never shown.

#### Every control, where it sits, and what it ships at

`Appearance → Product page → **Spacing · Page**`

| control | ships at | what it moves |
|---|---|---|
| Space between page sections | **34px** | `.sec` padding — above and below Product details, Reviews, You may also like |
| Space under the photograph, on a phone | **22px** | `.pdp .buybox` top padding under 881px |
| Gap between gallery thumbnails | **8px** | the strip floating on the picture's bottom edge |
| Gap between the detail tabs | **22px** | between Description / Ingredients / How to use |
| Space under the detail tab row | **18px** | between the row and the text it opens |

`Appearance → Product page → **Spacing · Buy column**`

| control | ships at | what it moves |
|---|---|---|
| Space above the product name | **8px** | between the brand line and the name/price row |
| Gap between the name and the price | **16px** | the channel in that row |
| Space above the rating | **10px** | both rating rows, so the Review badges control cannot lose it |
| Space above each dividing line | **20px** | the air above every hairline seam |
| Space below each dividing line | **20px** | the air under it |
| Space above the trust lines | **22px** | 22 and not 20 — the sheet's own number |
| Space between the trust lines | **10px** | one trust line to the next |
| Space above the payment icons | **12px** | above the Tabby / Tamara / Visa row |

`Appearance → Product page → **Type · Buy column**`

| control | ships at | what it moves |
|---|---|---|
| Product name · phone | **19px** | round 2's option B |
| Product name · laptop | **30px** | above 881px |
| Product name weight | **Regular (500)** | both widths |
| Price | **22px** | the live figure |
| Price weight | **Heavy (800)** | |
| Struck-out price | **12.5px** | |
| Discount badge | **10px** | the "−25%" chip |
| VAT line | **11px** | wording stays Store → Ecommerce → Tax's |
| Rating line | **12px** | stars, score, count — and the capsule |
| Short description | **13.5px** | the blurb above the options |
| Short description line spacing | **1.62** | the three-line cap and the fade follow it |
| Trust lines | **12.5px** | |

`Appearance → Product page → **Type · Sections & tabs**`

| control | ships at | what it moves |
|---|---|---|
| Section headings | **22px** | Product details, Reviews, You may also like |
| Section heading weight | **Medium (600)** | |
| Detail tab labels | **13.5px** | the words in the tab row |
| Detail tab text | **13.5px** | the body inside an opened tab |
| Detail tab line spacing | **1.7** | |

Thirty controls, thirty `--pl-*` custom properties, and every one of those
numbers is also the `var(--pl-x, <fallback>)` in `kbb-product.css`. The tab
strip also carries **Reset layout to defaults**, which fills the buffer with
these values and marks the bar dirty — it does not save by itself.

### 9b · ▲ Not one default moved, and that is the opposite of round 2

He asked for the CONTROLS. `ProductLayout::storefrontCss()` answers the **empty
string** while every value is at the number `kbb-product.css` already drew, and
`partials/product-layout-css.blade.php` then emits no element, no attribute and
no whitespace — so `StorefrontEnglishUnchangedTest` compares the product page
byte for byte and needs **no approved rule**.

The other half of that promise is the one only a test catches: every shipped
default is the same number as the `var(--pl-x, <fallback>)` the stylesheet falls
back to. If the two ever disagree, the day somebody moves ONE slider the other
twenty-nine values jump to numbers nobody chose. `ProductPageLayoutTest`
compares the two lists in both directions.

`docs/lane-pdp4-shots/` carries the measurement: the page measured on the commit
this branch started from and on this branch, at 390 and 1280, **DIFFERENCES: 0**
across eleven boxes and seventeen groups of computed styles.

### 9c · The detail tabs rendered one tab, on every demo product

`DemoCatalogueSeeder` set `short_description` and none of `description`,
`ingredients`, `how_to_use` — the three columns `ProductTabs` builds its
built-ins from. It drops an entry with an empty body and falls Description back
to the short description, so 24 of 24 demo products produced one tab with
nothing beside it. Demo content is off by default, so its top-up never ran.

The copy is `App\Support\DemoProductDetails` and has **two readers**: the seeder
(a fresh install) and `2027_06_15_000000_backfill_demo_product_details` (his
running shop, which a seeder edit would never have reached).

The backfill fills a column only where it is blank, and only on a row carrying
**all three** of the seeder's marks at once — `wc_id IS NULL`,
`sku LIKE 'DEMO-%'`, and the seeder's exact `short_description`. An imported
product carries a real `wc_id` and fails the first before the other two are
asked. The copy states no figure and no claim, per `DemoContent`'s own rule, and
says what it is in its last sentence.

### 9d · What the package needs on top of §1 and §2

```
app/Services/ProductLayout.php                          NEW
app/Support/DemoProductDetails.php                      NEW
app/Http/Controllers/Admin/ProductPageApiController.php  save() no longer
                                                        requires `sections`
resources/views/partials/product-layout-css.blade.php   NEW
resources/views/store/product.blade.php                 one @include
resources/views/admin/app.blade.php                     the tab strip
resources/css/kbb/kbb-product.css                       30 var() reads
database/seeders/DemoCatalogueSeeder.php
database/migrations/2027_06_15_000000_backfill_demo_product_details.php  NEW
database/migrations/2027_06_15_000100_clear_caches_product_page_layout.php  NEW
public/build/assets/kbb-product-<hash>.css              REBUILT
public/build/manifest.json                              MUST travel with it
tests/Feature/ProductPageLayoutTest.php                 NEW
tests/Feature/DemoProductTabsTest.php                   NEW
tests/Feature/ModuleFrameworkGuardTest.php              product_layout enrolled
tests/Feature/ProductPageLedgerTest.php                 two pins advanced
tests/Feature/ProductMobileLayoutTest.php               one pin advanced
tests/Feature/AdminNavAndIdsTest.php                    the screen registered
tools/pdp4-shots.cjs  docs/lane-pdp4-shots/             the harness and the shots
```

#### The five pins the round moved, and what each one now says

The full suite named five failures. Every one was a pin on a literal this round
turned into a variable, and each was ADVANCED rather than worked around — the
number it asserts is the same number, which is the whole point of the round.

| test | was | now | mutation re-run |
|---|---|---|---|
| `ProductPageLedgerTest > ships the quiet mobile title` | `.pdp .bb-title{font-size:19px;font-weight:500` | `…{font-size:var(--pl-title-m,19px);font-weight:var(--pl-title-w,500)` | `font-weight:600` back → **RED**; `21px` back → **RED**, in both files |
| `ProductPageLedgerTest > wraps the blurb in a checkbox` | `transparent calc(3 * 1.62em + 20px)` | `transparent calc(3 * var(--pl-desc-lh,1.62) * 1em + var(--pl-rule-pad,20px))` | as above |
| `ProductMobileLayoutTest > leaves the related block on the same gutter` | `toContain('.sec{padding:34px0;')` + a negative naming one wrong value | the whole declaration read out and compared | 20px inline → **RED**; rule deleted → **RED**; fallback drifted to 40px → **RED** in two files |
| `AdminNavAndIdsTest > lists every tab strip in the file` | — | `paintProductPage` in `TABBED_SCREENS`, with an `ectabs-hint` under the strip | drop the registration → **RED** |
| `ReviewBadgeParityTest > defines the capsule in exactly the files it knows` | — | `ProductLayout`'s comment no longer writes the capsule selector as a rule | write it as a rule again → **RED** |

▲ **One of those five was a false green about to happen and is now not.**
`ProductMobileLayoutTest`'s pair — `toContain('.sec{padding:34px0;')` and
`not->toContain('.sec{padding:34px20px')` — would have gone green with `.sec`'s
horizontal padding set to ANY value, and green again with the rule deleted
outright, the moment the declaration stopped beginning with `34px`. Both of
those mutations were run against the new form and both are red.

▲ **And one was this lane's own fault, caught by somebody else's guard.**
`ReviewBadgeParityTest` sweeps `resources/` **and `app/`** for the capsule class
followed by an opening brace, because a third file defining it is a third way
for the shop to disagree with the admin preview. `ProductLayout`'s schema
comment named the two rules it drives in CSS notation, braces and all — and to
that regex a comment written as a rule is a rule. It is rewritten without them;
so is the explanation of why, whose own first draft quoted the pattern and
tripped the same sweep on the next run.

`update.json` must declare **both** migrations in `migrations`, and the manifest
warning in §1 applies unchanged: ship the new stylesheet without the manifest
and the page asks for the old hash, which is still on disk — no 404, no error,
and every slider on the new screen saves, reports success and moves nothing.

### 9e · Found and not fixed

- **The preview fixture adds two GLOBAL product tabs** — "Shipping & returns"
  and "Authenticity" — from `tools/pdp-seed.php`, not from a migration. His shop
  does not have them. They were deleted from the preview database before the
  before/after pass so the pictures show his own state; nothing in the branch
  touches them.
- **`.dcontent.clamp`'s 104px cap and its 76px fade stop are still literals.**
  The tab body's size and line spacing are controls now, so a large enough
  setting will show fewer lines before "Read more" than a small one. Expressing
  the cap in line-boxes instead would have changed the rendered number at the
  shipped values by a fraction of a pixel — 4.53 × 1.7 × 13.5 is 103.96, not 104
  — and this round may not move the page by any amount. If the cap is ever to
  follow the type, it wants its own control in px, which is one field.
- **The five design previews are still mounted**, per §6, and they do not read
  these variables: `store/pdp-preview/_layout.blade.php` loads
  `kbb-product.css` but not this partial, so every `var()` in it falls back and
  the five drawings are exactly what they were.

## 10 · Round 5 — the preview the thirty controls never had

Verbatim, and it is a gap this lane let through:

> *"where's the preview on the product controls page? i need a proper preview
> of mobile and desktop both."*

Round 4 (§9) put **thirty controls** on `Appearance → Product page` and
`renderProductPage()` emitted **no preview element of any kind** — checked in
the source, not remembered. Thirty sliders, and the only way to find out what a
number did was to save it onto the shop.

### 10a · Where it is, and what it shows

**`Appearance → Product page`** — on every tab, beside the controls: a sticky
right-hand panel headed **Preview**, holding **two frames side by side**:

| pane | viewport | how it is drawn |
|---|---|---|
| **Laptop · 1280px** | 1280 × 2400 | scaled ×0.31 inside a 372px window |
| **Phone · 390px** | 390 × 2600 | scaled ×0.46 inside a 372px window |

Both at once, and that is the point rather than a convenience: `title_m` is
19px and `title_d` is 30px, so a single-width preview with a toggle on it would
let him set a laptop size that ruins the phone and find out on the shop. Below
1180px the panel drops under the controls; below 760px the pair stacks and both
shrink. Neither pane is ever hidden.

Each frame carries a **Full size ↗** link to the same page in a new tab.

### 10b · ▲ It frames `/product/{slug}`, and NOT one of the five design previews

The obvious URL was `admin-api/catalog/pdp-preview/ledger/{slug}` — the design
he chose, already built, already gated — and §9e even reported it as the thing
to fix. **It was the wrong answer, and the harness caught it.** The five
candidates are drawn in markup of their own: every class in `ledger`,
`dossier`, `counter`, `deck`, `marquee` and in `pdp-preview/parts/` is `pv-…`,
while the thirty properties are read by `.pdp .bb-title`, `.sec h2` and
`.details .dtabbar` in `kbb-product.css`. The first run photographed exactly
that: two frames with a complete, handsome product page in them, and
**twenty-nine of the thirty controls moving nothing**. The one selector the
five share with the shop is `.dcontent`.

So the panel frames the **shipped page**, through `Product::url()` (URL
contract U-01, trailing slash and base prefix). `ProductPagePreviewPanelTest`
fetches `preview.url` itself and asserts the response carries `bb-title`,
`bb-price`, `bb-desc` and `dtabbar` — pointing it back at the candidate reds
five expectations.

`store/pdp-preview/_layout.blade.php` **did** gain
`@include('partials.product-layout-css')`, which is the §9e gap closed; the
file's own comment now records honestly that it moves exactly one rule
(`.dcontent`) in those five drafts, and why that is still worth doing.

### 10c · How "live" works, and what makes the two formatters agree

`var(--pl-x, <literal>)` is how the stylesheet reads all thirty numbers, so the
whole of *live* is writing those properties onto each frame's own `<html>` from
the console — same origin, wrapped, repainted on the frame's `load` too. No
POST, nothing stored; **Reset layout to defaults** and **Discard** both put the
picture back, and a successful **Save** reloads both frames (the Sections
switches decide which blocks render at all, and no custom property can show
that).

That means **two formatters for the same thirty numbers**, which is two places
to drift. The split that makes them checkable:

- `ProductLayout::PROPS` — schema key → property name, **one table**, used by
  `css()` and sent to the console in the endpoint's new `preview.props`.
- `ProductLayout::vars()` — the thirty `property => value` pairs; `css()` is now
  an implode of it.
- the console builds its value from the field's own `unit` and `scale`, which
  `ModuleSchema` already sends it (`ppShow()` draws the readout with the same
  two).

`ProductPagePreviewPanelTest` reproduces the console's rule in PHP and demands
it equals `vars()` **property for property**, on the shipped values and on a
moved set. Deleting one key from `PROPS` or renaming it reds two cases.

### 10d · Rule 4, and the one CSS trick it needed

Nothing measures a rectangle. A 1280px page in a 400px panel is a **constant
scale** per pane (`--ppv-s`) with every dimension derived by `calc()`; the test
sweeps the region for `getBoundingClientRect`, `offsetWidth`, `offsetHeight`,
`clientWidth`, `clientHeight` and `getComputedStyle` and finds none.

▲ `transform:scale()` does not change an element's **layout** size, so a
1280×2400 iframe still occupies 1280×2400 in its parent — the panel would have
scrolled 2400px of nothing to reach content that visually ends at 744.
`.ppv-scale` is the scaled box: it carries the multiplied width and height and
positions the iframe absolutely inside it, so `.ppv-vp` scrolls exactly as far
as there is picture. The frame is `pointer-events:none` so a click cannot
navigate it away; the wheel and the scrollbar belong to `.ppv-vp`, which is not
inert.

### 10e · The shell is built once, and that is the preview's doing

`paintProductPage()` used to replace `#content` wholesale on every tab click.
With two iframes in it that is two product pages fetched per click — and worse,
**re-inserting an iframe reloads it**, so the picture would flash back to the
saved page and lose whatever he had just dragged. It now takes a `rebuild`
argument: the shell (heading, strip host, preview panel, save bar) is written
when the screen is entered, and a repaint refills only the strip, the control
column and the action button.

### 10f · Measured

`docs/lane-pdp5-shots/` — screenshots at 1280 and 390, and `preview.json` with
the computed sizes read **out of both frames** by the harness:

| control moved | laptop frame | phone frame |
|---|---|---|
| — (shipped) | title 30px, `.sec` 34px, tab 13.5px | title 19px, `.sec` 34px, tab 13.5px |
| `title_m` 190 → 280 | title **30px** (unchanged) | title **19 → 28px** |
| `title_d` 300 → 440 | title **30 → 44px** | title **28px** (unchanged) |
| `sec_pad` 34 → 90 | `.sec` **34 → 90px** | `.sec` **34 → 90px** |
| `tab_s` 135 → 220 | tab **13.5 → 22px** | tab **13.5 → 22px** |

`document.documentElement.scrollWidth` is **1280 at 1280** and **390 at 390** in
every one of those states.

### 10f½ · ▲ The near-miss, and the test that now catches it

While `preview()` was being rewritten, the edit that replaced it matched from
its docblock **to the end of the class** and took `save()` with it. `php -l`
was clean. The screen loaded, both frames drew the product page, every slider
moved the picture — and pressing **Save changes** answered

```
500  Call to undefined method …ProductPageApiController::save()
```

with the console showing *"Could not save."* **Eleven cases in the new test
file were green through all of it**, because a preview is read-only and not one
of them POSTed. It was found by driving the real screen in a browser, and the
full suite named it four times over (`ProductPageLayoutTest` ×3 and
`StorefrontRouteWalkTest`, which reports the route pointing at a method that
does not exist).

`ProductPagePreviewPanelTest > it still saves both halves` is the case that
would have caught it first: it POSTs a layout value, asserts `saved => 1`, and
then asserts the number really reaches `/product/{slug}`. Deleting `save()`
reds that one case and leaves the other eleven green — which is the whole
reason it is written.

### 10g · Nothing on the shop moved

This is an admin preview. `ProductLayout::storefrontCss()` still answers the
empty string while every value is shipped, `partials/product-layout-css`
still emits no element, and `StorefrontEnglishUnchangedTest` still needs no
approved rule. The narrower statement it rests on is pinned here too, along
with the other half — that once he saves one, `--pl-title-m:27.5px` really is
on the page.

### 10g½ · The one pin this round moved

| test | was | now | mutation re-run |
|---|---|---|---|
| `ProductPageLayoutTest > it wires the Layout tabs into the console exactly once` | `substr_count($console, 'function paintProductPage(){')` is 1 | `…'function paintProductPage('` is 1 | declare a second `function paintProductPage(` → **RED**; restore → green |

**Advanced, not worked around.** The signature gained an argument —
`paintProductPage(rebuild)` — because the shell is now written once per visit
and a repaint refills only the parts that change. The count the case is really
about (one renderer, not zero and not two) is unchanged; the needle is the name
and the opening paren rather than the empty list.

### 10h · What the package needs

```
app/Services/ProductLayout.php                          PROPS + vars()
app/Http/Controllers/Admin/ProductPageApiController.php  show() answers `preview`
resources/views/admin/app.blade.php                     the panel + its CSS
resources/views/store/pdp-preview/_layout.blade.php     one @include
database/migrations/2027_06_20_000000_clear_caches_product_page_preview.php  NEW
tests/Feature/ProductPagePreviewPanelTest.php           NEW
tools/pdp5-shots.cjs  docs/lane-pdp5-shots/             the harness and the shots
```

**No stylesheet and no schema change.** Nothing under `resources/css` or
`resources/js` is touched, so `public/build` is unchanged and §1's manifest
warning does not apply to this package. `update.json` still declares the one
migration in `migrations`.

▲ **The `clear_caches` migration is not optional here.** The panel and its
stylesheet are both **inline** in `app.blade.php`, so there is no hashed asset
to bust: a stale compiled view serves yesterday's screen — thirty sliders and
no preview, the exact complaint — while the endpoint underneath answers
perfectly and the package reports as applied.
