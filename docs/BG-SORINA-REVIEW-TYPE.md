# The product-page review block asks for two fonts this shop has never loaded

*Lane BG, round 4. A decision for the owner, with the three answers costed.*

`resources/css/kbb/sorina-reviews.css` is inlined into **every product page** by
`ProductController::reviewsCss()`. Four of its rules name families that are
declared nowhere in this repository:

```css
.sr        { font-family:"Hanken Grotesk", -apple-system, …, Arial, sans-serif; }
.sr-title  { font-family:Fraunces, Georgia, serif; font-size:28px; font-weight:500; }
.sr-avg    { font-family:Fraunces, Georgia, serif; font-size:46px; font-weight:600; }
.sr-stitle { font-family:Fraunces, Georgia, serif; font-size:23px; font-weight:500; }
```

Measured on a real product page with `tools/font-probe.cjs` — two rulers, one in
the family alone and one in a family that cannot exist:

| family | rendered? | ruler 400/500/600/700 | control |
|---|---|---|---|
| Poppins | **yes** | 497.20 / 505.00 / 510.17 / 516.17 | 436.63 / 436.63 / 463.36 / 463.36 |
| Fraunces | **no** | 436.63 / 436.63 / 463.36 / 463.36 | *identical* |
| Hanken Grotesk | **no** | 436.63 / 436.63 / 463.36 / 463.36 | *identical* |

The only family the document declares is `Poppins`. So every one of those four
rules has always fallen through to whatever the **device** supplies.

**They were never chosen for this shop.** Both families arrived with the
third-party "Sorina" review template, and `store/review-wall.blade.php` says so
in its own stylesheet: `/* KBB real tokens (never Sorina/Fraunces) */` — the
review *wall* was deliberately re-skinned in the shop's own tokens and the
product-page block was not.

---

## What a shopper sees today: it depends on the device

`Fraunces, Georgia, serif` with neither of the first two loaded resolves to the
device's own serif. The same heading, `Customer Reviews` at 28px/500, measured
with a Range across the text:

| the serif the device supplies | width |
|---|---|
| FreeSerif | 205.92px |
| Nimbus Roman | 208.39px |
| Georgia *(as this container resolves it)* | 212.31px |
| DejaVu Serif — and the generic `serif` | 264.69px |

**A 28.5% swing, and none of them is Fraunces.** A Windows or Mac shopper gets
real Georgia; a Linux or Android one gets DejaVu Serif or similar. The block has
no single appearance today, and that is not a rendering detail — it is the
heading of the section that sells the product.

> **This container has no Georgia.** `fc-match Georgia` returns DejaVu Serif, so
> the option-A screenshots show a substitute rather than what a Windows shopper
> sees. That is the same caveat this lane recorded for `fonts.googleapis.com` in
> round 3, and the reason the table above is four rows rather than a claim about
> one.

---

## The three answers

### A — leave it. 0 bytes, 0 declarations

The block keeps its serif heading. It carries on looking different on different
devices, and different from every other heading on the page, which are Poppins
at weight 800.

`docs/bg-sorina/A-today-1280.png`, `A-today-390.png`, and the four
`A-serif-*.png` showing the same block in each serif a device might supply.

### B — the shop's own type. 0 bytes, 5 declarations

```css
.sr{font-family:var(--sans)}
.sr-title,.sr-avg,.sr-stitle{font-family:var(--sans)}
.sr-verified{padding:1px 4px}
```

`--sans` is the shop's own token, already on `:root` in `kbb.css`:
`"Poppins", system-ui, …` — the same stack as the product title, the price and
the tabs. **No new font file, no new request**, because those faces are already
on the page.

**The fifth declaration is the honest part, and the picture is what found it.**
With the four family rules alone the middle review card broke: Poppins is wider
than the Arial the block falls back to today, so `Fatima R.` plus the
`✓ Verified` badge no longer fitted on one line in a 207px card. The badge
wrapped, that card's name row went 16px → **36px**, its height 207px → **228px**,
and the three cards' stars stopped lining up. Nothing in the numbers said so;
the screenshot did.

Six candidate fixes, measured:

| candidate | name-row heights | stars aligned | card heights |
|---|---|---|---|
| four family rules alone | 16 / **36** / 16 | ✗ | 207 / **228** / 207 |
| `.sr-cmeta{flex-wrap:wrap}` | 16 / **36** / 16 | ✗ | 207 / 228 / 207 |
| `.sr-nm{flex-wrap:nowrap}` | 16 / **30** / 16 | ✗ | 207 / 221 / 207 |
| `.sr-cmeta` stacked to a column | 16 / **36** / 16 | ✗ | 207 / 228 / 207 |
| `.sr-nm{gap:4px}` | 16 / **34** / 16 | ✗ | 207 / 226 / 207 |
| **`.sr-verified{padding:1px 4px}`** | **16 / 16 / 16** | **✓** | **207 / 207 / 207** |

The winner keeps the badge's 10px type and gives up 2px of padding either side.
Card heights return to **207px — the same 207px option A has**.

Block height 564.11px (A) → 578.11px (B); `scrollWidth` 1280 at 1280 and 390 at
390 in both, so nothing overflows. `docs/bg-sorina/B-shop-type-1280.png`,
`B-shop-type-390.png`.

**Option B only became clean this round.** `.sr-title` and `.sr-stitle` are
weight 500 and `.sr-avg` is 600. Until Poppins 500 was added (round 4's sibling
task), a target of 500 resolved to the 400 face, so moving these rules to
Poppins would have quietly rendered the headings at regular.

### C — load Fraunces and Hanken Grotesk properly. **102,008 bytes**

**Not built, and not recommended.** Measured from Google's own API without
fetching a single font file — the stylesheet was read and each font URL was
asked for its `Content-Length`:

| | subset | file | bytes |
|---|---|---|---|
| Fraunces (variable, `opsz` 9..144) | latin | one file covering 500 and 600 | **67,304** |
| Hanken Grotesk (variable) | latin | one file covering 400, 500 and 700 | **34,704** |
| | | **latin total** | **102,008** |

*(With `latin-ext`, `vietnamese` and `cyrillic-ext` the full set is 211,672
bytes. woff2 is already compressed, so these are wire costs.)*

Set against what the **whole shop** currently runs on:

> The entire self-hosted Poppins set — five weights across `latin` and
> `latin-ext`, ten files — is **66,848 bytes**.
>
> **Fraunces' latin file alone (67,304 B) is larger than that.** The pair is
> **1.53×** the whole shop's typography, added to serve one block at the bottom
> of the product page.

That is the measurement; the recommendation follows from it. Lane PERF
self-hosted Poppins to cut a 4,369 ms critical path, and 102 KB of new font
files for a section a shopper reaches after scrolling past the gallery, the
price and the tabs spends a large part of that win on a heading.

**This lane will not download two font families on its own authority**, which is
why option C has no screenshot: building the picture would mean doing the thing
the decision is about.

---

## Recommendation

**B.** It is the only one of the three that makes the block look the same for
every shopper, it costs no bytes and no requests, and it ends with the review
heading set in the same typeface as every other heading on the page.

A is defensible if the owner *likes* the serif heading — but then C is the
honest way to have it, at 102 KB, because A is not "a serif heading", it is
"whichever serif the device happens to have".

**Nothing has been changed.** `sorina-reviews.css` is untouched and every
product page renders exactly what it rendered before. The block below is what
to apply if the owner picks B.

### If the owner picks B — the block to apply

Append to `resources/css/kbb/sorina-reviews.css`. It needs
`npx vite build` and a committed `public/build` (CI does not build assets, and
`BuiltCssSelectorsAreCurrentTest` is the guard).

```css
/* ── The review block in the shop's own type. ───────────── (Lane BG, round 4)
   Replaces "Hanken Grotesk"/Fraunces, neither of which this shop has ever
   loaded, so both rules fell through to whatever serif and sans the device
   supplied -- a 28.5% swing in heading width across four common serifs.
   --sans is the shop's own token in kbb.css. No new font file, no new request.
   The badge padding is not decoration: without it "Fatima R. ✓ Verified" wraps
   in a 207px card and the three cards' stars stop lining up.
   docs/BG-SORINA-REVIEW-TYPE.md has the six candidates and the measurements. */
.sr{font-family:var(--sans)}
.sr-title,.sr-avg,.sr-stitle{font-family:var(--sans)}
.sr-verified{padding:1px 4px}
```

### The block was applied and measured, not just written

Every number for option B above comes from CSS injected at capture time. That
proves the design, not the patch — so the three declarations were also appended
to `sorina-reviews.css` for real, measured, and reverted:

| | injected | appended for real |
|---|---|---|
| `.sr-title` family | Poppins, system-ui, … | **Poppins, system-ui, …** |
| `.sr-title` text width | 261.28px | **261.28px** |
| name-row heights | 16 / 16 / 16 | **16 / 16 / 16** |
| card heights | 207 / 207 / 207 | **207 / 207 / 207** |
| block height | 578.11px | **578.11px** |
| `scrollWidth` @1280 | 1280 | **1280** |

`sorina-reviews.css` is back to its committed state — `git diff` on it is empty.

## Reproducing any of this

```bash
sh tools/bg-preview.sh 8990
BG_BASE=http://127.0.0.1:8990 node tools/bg-sorina-options.cjs
```
