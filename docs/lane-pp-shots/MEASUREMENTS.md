# Lane PP — the product page: squeeze, seam, and three proposals

Chromium `/opt/pw-browsers/chromium-1194/chrome-linux/chrome`, 390 and 1280,
`deviceScaleFactor: 2`. Every number below was read off a rendered page by
`tools/pp-measure.cjs`, `tools/pp-diagnose.cjs`, `tools/pp-layout-shots.cjs`,
`tools/pp-phone-shots.cjs` and `tools/pp-rtl-shots.cjs`. **Nothing in the page
measures anything** — the shipped product page contains no layout-measuring
script and this lane added none.

Boot the fixture with `sh tools/pp-preview.sh 8977` (seed: `tools/pp-seed.php`
— a 3-member set, a 12-member set, an unpriced set and an ordinary product with
a short description, plus Arabic and the mirror switched on).

---

## 1 · What is actually wrong with the page today

The owner's complaint — *"i don't like that much the product page"* — is real
and vague. Read off the shipped page rather than argued:

| # | Finding | Measured |
|---|---|---|
| 1 | **The gallery frame is 21% empty, and it sets the page's height.** `.gmain` is `aspect-ratio:1`. This catalogue's photographs are 4:5, so `object-fit:contain` paints 488×610 inside a 612×612 frame. | fill **79%** at 1280 and at 390. `.pdp` is 753px tall against a 681px buy column. |
| 2 | **Eleven blocks, one rhythm, no hierarchy.** Every block flush to the same start edge, all `text-align:start`. | gaps above, in order: 7, 11, 6, 16, 18, 9, 22, 6, 17, 14, 13. The *biggest* gap on the page is between the options and the stock line; one of the smallest is under the price. |
| 3 | **Three promises about delivery, in three styles, in two places.** `.deliver` above the button, `1–3 days fast delivery` again below it inside `.trust`, and `.paychips` repeating Tabby/Tamara which `.trust` already named. | 117 + 58 = **175px** at 390, of which 58px is a reprint. |
| 4 | **On a phone you scroll 1155px before you can buy.** | Add to cart at y=**1155** at 390 on a ~844px viewport. |
| 5 | **28% of the page below the fold is empty boxes.** Details set to the full 1240px measure (~200 characters a line) for two lines; reviews for a product with none. | 256 + 454 = **710px** of a 2541px page. |

Finding 5 lives in `partials/product-tabs.blade.php` and `partials/reviews.blade.php`
— **Lane PT's files this round**. Reported, not touched.

---

## 2 · Items 1–3, before and after

`before-*.png` / `after-*.png`. Nothing else on the page moved.

| Page | w | row h | list h | Add to cart y | seam gap | member prices | scrollWidth |
|---|---|---|---|---|---|---|---|
| set, 3 members | 390 | 74 → **46** | 320 → **230** | 1123 → **1049** | 0 → **18** | 3 → **0** | 390 → 390 |
| set, 3 members | 1280 | 78 → **52** | 308 → **224** | 687 → **619** | 0 → **18** | 3 → **0** | 1280 → 1280 |
| set, 12 members | 390 | 74 → **46** | 501 → **358** | 1304 → **1177** | 0 → **18** | 12 → **0** | 390 → 390 |
| set, 12 members | 1280 | 78 → **52** | 497 → **364** | 876 → **759** | 0 → **18** | 12 → **0** | 1280 → 1280 |
| ordinary product | 390 | — | — | 1137 → **1155** | 0 → **18** | — | 390 → 390 |
| ordinary product | 1280 | — | — | 619 → **637** | 0 → **18** | — | 1280 → 1280 |

**Height saved by the squeeze:** 84–90px on a 3-member set, 133–143px on a
12-member one. **Add to cart comes up** 68/74px at 3 members and 117/127px at
12 — *and that is after* the short description moved below the list and the
seam gained 18px, both of which push it down.

The ordinary product's Add to cart moves **down 18px**, at both widths. That is
item 3 and nothing else: it is the space he asked for.

### The seam (item 3)

`.bb-desc` in `kbb-product.css` has declared `margin-bottom:20px` all along and
**it had never applied once**. `resources/css/kbb/kbb.css` line 1752 declares

```css
.pdp .bb-desc{margin:12px 0 0}
```

— one class more specific, and a *shorthand*, so the bottom margin was reset to
zero on every product page in the shop. Measured before: **0px** at 390 and at
1280. The fix is `.pdp .bb-desc{margin-block:12px 18px}` in `kbb-product.css`,
which the `<head>` emits *after* `kbb.css` on this page.

### Where the short description sits (item 2)

| | before | after |
|---|---|---|
| set with members | above *What is in this set* | **below the footing line, above the stock line** |
| set with no members | above the buy form | **unchanged** |
| ordinary product | above the buy form | **unchanged, to the byte** |

`StorefrontEnglishUnchangedTest` is **green** — the pin does not move. See
commit *"Keep the English walk green instead of pinning it forward"*.

---

## 3 · The three proposals

Preview only. `/product/<slug>/?layout=focus | editorial | compact`.
With no `layout` in the query string the element renders `<div class="pdp">`,
character for character what it rendered before.

| layout | page | w | page h | .pdp h | Add to cart y | frame | fill | title | scrollWidth |
|---|---|---|---|---|---|---|---|---|---|
| *shipped* | plain | 1280 | 2541 | 753 | 637 | 612×612 | 79% | 25px | 1280 |
| focus | plain | 1280 | 2518 | **728** | **521** | 525×656 | **99%** | 24px | 1280 |
| editorial | plain | 1280 | 2644 | 854 | 758 | 626×782 | **99%** | 38px | 1280 |
| compact | plain | 1280 | 2572 | 783 | **554** | 568×711 | **99%** | 21px | 1280 |
| *shipped* | set3 | 1280 | 2524 | 735 | 619 | 612×612 | 79% | 25px | 1280 |
| focus | set3 | 1280 | 2518 | 728 | **585** | 525×656 | **99%** | 24px | 1280 |
| editorial | set3 | 1280 | 2644 | 854 | 715 | 626×782 | **99%** | 38px | 1280 |
| compact | set3 | 1280 | 2572 | 783 | 604 | 568×711 | **99%** | 21px | 1280 |
| focus | plain | 390 | 3904 | 1344 | 1093 | 346×433 | 99% | 21px | 390 |
| editorial | plain | 390 | 3956 | 1395 | 1308 | 346×433 | 99% | 27px | 390 |
| compact | plain | 390 | 3754 | **1193** | 1149 | 346×433 | 99% | 19px | 390 |
| focus | set3 | 390 | 3816 | 1278 | 1110 | 346×433 | 99% | 21px | 390 |
| editorial | set3 | 390 | 3843 | 1305 | 1218 | 346×433 | 99% | 27px | 390 |
| compact | set3 | 390 | 3710 | **1172** | 1127 | 346×433 | 99% | 19px | 390 |

**The 390 rows above are captured in a 1400px-tall window**, because a full-page
screenshot needs one — which makes COMPACT's `max-block-size:44vh` inert. At a
real phone viewport (390×844, `phone844-*.png`) compact's frame is **297×371**,
not 346×433, and Add to cart is in the window at the moment a shopper is
choosing:

| at 390×844, scrolled to the options | Add to cart in the window? | at viewport y |
|---|---|---|
| shipped, plain | yes | 813 |
| focus, plain | yes | 703 |
| editorial, plain | **no** | 909 |
| compact, plain | yes | 746 |
| shipped, set3 | yes | 759 |
| focus, set3 | yes | 815 |
| editorial, set3 | **no** | 922 |
| compact, set3 | yes | **690** |

---

## 4 · Cost, and the mirror

| | |
|---|---|
| set page, 3 members | **10 queries** |
| set page, 12 members | **10 queries** — flat |
| ordinary product | **7 queries** |
| same page, `?layout=focus` / `editorial` / `compact` | **7 queries** each |

`ar-*.png`, `/ar`, both widths, shipped page and all three proposals:
`dir="rtl"`, the member photograph on the **right** of its row, the 18px seam
intact, no member prices, and `scrollWidth == viewport` (390 and 1280) in every
one. No `[dir]` rule was written — the list is three grid tracks along the
inline axis, and the seam is `margin-block`.

---

## 5 · Index of shots

```
before-{plain,set3,set12}-{390,1280}.png     items 1-3, before
after-{plain,set3,set12}-{390,1280}.png      items 1-3, after
layout-{focus,editorial,compact}-{plain,set3}-{390,1280}.png
phone844-{shipped,focus,editorial,compact}-{plain,set3}.png   real phone viewport
ar-{set3,plain,focus-set3,editorial-set3,compact-set3}-{390,1280}.png
*-measurements.json                          every number above, as captured
```

---

## 6 · One bug the proposals had, found by seeding the state that shows it

`partials/notify-me.blade.php` renders **outside** `</form>`, as a direct child
of `.buybox`, and only when the product cannot be bought. All three proposals
make `.buybox` a flex column — and **a flex child with no `order` takes 0**, so
"Email me when this is back" rendered *above the brand*, at the very top of the
buy column, on every out-of-stock product.

There is no way to see that without a product in that state, so
`tools/pp-seed.php` now seeds one and turns the `back_in_stock` module on.
`oos-{shipped,focus,editorial,compact}-1280.png`, and the visual order read back
off the page:

```
shipped    brand, title, price, vat, desc, form, notifyme, trust, chips
focus      brand, title, price, vat, form, notifyme, desc, trust, chips
editorial  brand, title, desc, price, vat, form, notifyme, trust, chips
compact    brand, title, price, vat, desc, form, notifyme, trust, chips
```

The `notifyme` rules carry `order` and nothing else: the partial has
`style="margin-top:14px"` inline, which beats any margin declared in a sheet, so
a spacing rule there would be a rule that never applies.
