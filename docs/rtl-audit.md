# T6 · RTL — audit and logical-property rewrite

**Status: both halves are done.** The mechanical half — 368 physical direction
declarations rewritten as logical ones — landed first and is described below.
The manual half, §11 onward, is the part that decides whether the Arabic shop can
be shown to a customer: it turns RTL on, looks at every surface in a browser, and
fixes the things a property rename cannot reach. 21 of the 57 declarations this
document recorded as deliberately physical turned out to be convertible once
their `transform` was flipped alongside them, and one row was added. Two more —
the `.mnav` in the two standalone blog layouts — were deferred at the time and
have since been converted by Lane FK (§11.1), so **23 of the 57 are converted,
34 are kept**, and with §11.6's added twin the kept-physical table below has
**35** rows. §13 is the declaration-by-declaration re-reading of those 34, and
of the three defects it found in surfaces no declaration reader can see.

**RTL is still shipped OFF.** `language_ar_enabled` and `language_rtl_enabled`
are both absent by default and nothing here seeds them.

CSS logical properties resolve to their physical equivalents in
`writing-mode: horizontal-tb; direction: ltr`. `margin-inline-start` *is*
`margin-left` on this site today. The value of doing it now is that it is the
entire body of work RTL needs later, it is provably inert today, and the
storefront stylesheets are contended — doing it in one pass with a guard beats
doing it piecemeal under a deadline.

---

## 1. The count

Read with a CSS declaration reader (`tests/Support/CssDirection`), not grep —
`margin-left` occurs both as a declaration and inside comments that explain why
a rule keeps `margin-left`, and a substring search cannot tell those apart.
Scope of the audit: every stylesheet under `resources/css/`, plus every
`<style>` block in every Blade view (37 views carry one).

| | declarations |
|---|---|
| Physical direction declarations found, whole repo | **678** |
| — in the storefront files this lane owns | **425** |
| — converted to logical, mechanical half | **368** |
| — converted to logical, manual half (§11) | **21** |
| — converted to logical, deferred pair taken later by Lane FK (§11.1) | **2** |
| — left physical on purpose, re-read declaration by declaration in §13 | **34** |
| — added on purpose: one `[dir="rtl"]` twin of a physical rule (§11.6) | **1** |
| — out of this lane's scope (admin, invoice, dead Laravel welcome page) | **253** |

368 + 21 + 2 + 34 = 425, and the table below has 35 rows — one more, because of
the twin §11.6 added. The floors
table above and the kept-physical table below are regenerated from the
stylesheets, so those are the numbers to trust if this paragraph ever drifts.

"Physical direction declaration" means: `margin-left/right`,
`padding-left/right`, `border-left/right(-color/-width/-style)`, the four
`border-*-*-radius` corners, `left`/`right` insets, `text-align: left|right`,
`float: left|right`, and `scroll-margin/padding-left|right`. It excludes
`text-align: center`, `float: none` (the only `float` in the storefront) and
`text-indent`, which is already start-relative and needs nothing.

### Converted, by file

The right-hand column is the total declarations the reader parses in that file.
It is there so the guard in `tests/Feature/RtlReadinessTest.php` can tell "this
file is clean" from "the reader silently read nothing", which is the failure
mode that makes a guard worthless.

<!-- rtl-audit:floors:begin -->
| file | logical direction declarations (floor) | total declarations parsed |
|---|---|---|
| `resources/css/kbb/kbb.css` | 190 | 5765 |
| `resources/css/kbb/kbb-shop.css` | 40 | 1248 |
| `resources/css/kbb/kbb-product.css` | 36 | 1341 |
| `resources/css/kbb/kbb-cart.css` | 5 | 300 |
| `resources/css/kbb/kbb-checkout.css` | 38 | 1339 |
| `resources/css/kbb/kbb-account.css` | 2 | 170 |
| `resources/css/kbb/kbb-banner.css` | 0 | 100 |
| `resources/css/kbb/kbb-grid-skins.css` | 15 | 570 |
| `resources/css/kbb/sorina-reviews.css` | 13 | 597 |
| `resources/views/layouts/store.blade.php` | 3 | 110 |
| `resources/views/store/app.blade.php` | 25 | 1402 |
| `resources/views/store/skin-quiz.blade.php` | 9 | 545 |
| `resources/views/store/blog.blade.php` | 3 | 224 |
| `resources/views/store/post.blade.php` | 2 | 223 |
| `resources/views/store/review-wall.blade.php` | 4 | 302 |
| `resources/views/store/checkout.blade.php` | 2 | 33 |
| `resources/views/store/checkout-success.blade.php` | 1 | 154 |
| `resources/views/store/account/order-detail.blade.php` | 5 | 154 |
| `resources/views/store/account/track.blade.php` | 1 | 63 |
<!-- rtl-audit:floors:end -->

`kbb-banner.css` had none to begin with; it is in scope so that a physical
declaration added to it later fails the guard.

---

## 2. What stays physical, and why

These 35 declarations are inside the converted files and are **deliberately not
converted** — 34 of the original 57, plus the one §11.6 added. (It was 57; §11
explains which 21 moved and why and which one was added, §11.1 the two Lane FK
took afterwards, and §13 re-reads what is left one declaration at a time.) Each one is either an idiom that is not about reading direction, or
one half of a pair whose other half has no logical form — and converting half of
a coordinated pair is worse than converting neither, because it breaks the layout
in RTL in a way nobody sees until RTL is switched on.

Each is marked in the source with an `RTL-PHYSICAL:` comment. This table is the
machine-readable copy: `RtlReadinessTest` asserts it matches the stylesheets
exactly, in both directions, so the document cannot drift from the code.

<!-- rtl-audit:physical:begin -->
| file | selector | declaration | why |
|---|---|---|---|
| `resources/css/kbb/kbb.css` | `.mega .mcol a:hover::before, .mega .mcol-link:hover::before` | `right: 0` | transition |
| `resources/css/kbb/kbb.css` | `[dir="rtl"] .mega .mcol a:hover::before, [dir="rtl"] .mega .mcol-link:hover::before` | `left: 0` | transition |
| `resources/css/kbb/kbb.css` | `.sdots` | `left: 50%` | centre |
| `resources/css/kbb/kbb.css` | `.toast` | `left: 50%` | centre |
| `resources/css/kbb/kbb.css` | `.kbb-pgrid[data-skin="editorial"] .cn:after` | `left: 50%` | centre |
| `resources/css/kbb/kbb.css` | `.acct::before` | `border-left: 1px solid var(--line-2)` | rotated |
| `resources/css/kbb/kbb-shop.css` | `.qv` | `left: 50%` | centre |
| `resources/css/kbb/kbb-shop.css` | `.toast` | `left: 50%` | centre |
| `resources/css/kbb/kbb-product.css` | `.hp` | `left: -9999px` | off-screen |
| `resources/css/kbb/kbb-product.css` | `.toast` | `left: 50%` | centre |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout #payment #place_order` | `left: -9999px` | off-screen |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-jade .ffill::after` | `right: 0` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-comet .fs-rider` | `right: -7px` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-cheer` | `right: 3px` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-cheer i` | `left: 0` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-emerald .fs-cheer::before, .kbb-checkout .fs-aurora .fs-cheer::before, .kbb-checkout .fs-comet .fs-cheer::before` | `left: -8px` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-mint .fs-cheer i` | `left: -3px` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-jade .fs-cheer` | `right: auto` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-jade .fs-cheer` | `left: 0` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-jade .fs-cheer i:nth-child(1)` | `left: 8%` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-jade .fs-cheer i:nth-child(2)` | `left: 20%` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-jade .fs-cheer i:nth-child(3)` | `left: 32%` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-jade .fs-cheer i:nth-child(4)` | `left: 45%` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-jade .fs-cheer i:nth-child(5)` | `left: 58%` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-jade .fs-cheer i:nth-child(6)` | `left: 70%` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-jade .fs-cheer i:nth-child(7)` | `left: 82%` | fill-bar |
| `resources/css/kbb/kbb-checkout.css` | `.kbb-checkout .fs-jade .fs-cheer i:nth-child(8)` | `left: 93%` | fill-bar |
| `resources/css/kbb/kbb-grid-skins.css` | `.kbb-pgrid[data-skin="editorial"] .cn:after` | `left: 50%` | centre |
| `resources/css/kbb/sorina-reviews.css` | `.sr-mcard` | `left: 50%` | centre |
| `resources/css/kbb/sorina-reviews.css` | `@media(min-width:760px) .sr-scard` | `left: 50%` | centre |
| `resources/css/kbb/sorina-reviews.css` | `@media(min-width:760px) .sr-scard` | `right: auto` | centre |
| `resources/css/kbb/sorina-reviews.css` | `.sr-hp` | `left: -9999px` | off-screen |
| `resources/views/layouts/store.blade.php` | `.qv-btn` | `left: 50%` | centre |
| `resources/views/store/app.blade.php` | `.toast` | `left: 50%` | centre |
| `resources/views/store/skin-quiz.blade.php` | `.toast` | `left: 50%` | centre |
<!-- rtl-audit:physical:end -->

### The reasons in full

Counts are the current ones. Where a group shrank, the row that replaced it is in
§11.

**`centre` (13)** — `left: 50%` paired with `transform: translateX(-50%)`.
This is the centring idiom, not a statement about direction. `translateX` has no
logical equivalent, so converting the inset alone would resolve to `right: 50%`
in RTL while the transform still pulled the element left — moving it off centre
by its own width. Toasts, carousel dot rails, the quick-view button, the review
modal and the editorial card underline are all this. **Verified rather than
assumed** (§11.3): in a `dir="rtl"` document at 900px the toast spans
397.5..502.5, centre 450.0. All 13 stay exactly as they are, and
`RtlMirrorTest` now fails if a `[dir="rtl"]` rule is ever added to one of them.

**`fill-bar` (16)** — the checkout's free-shipping progress bar and its
celebration sprites. The fill is `linear-gradient(90deg, …)` with a width
transition, the shine and the comet rider ride on `translateX`, the burst
particles fly along `--tx` custom properties, and the track is trimmed with
`overflow-x: clip`. None of those has a logical form, and the sprite positions
are measured against the fill they sit on. All 16 stay physical — and §11.2
explains why the bar is nevertheless mirrored, by one rule on the track rather
than by converting any of them.

**`off-screen` (3)** — `left: -9999px` on the two spam honeypots and on
WooCommerce's real `#place_order` button. "Off the left edge" is a physical
trick for hiding something from sight while leaving it in the accessibility
tree; it is not a reading direction, so all three stay. **But which edge is safe
is not the same in both directions, and the note that used to be here had it
backwards** — see §11.4, which is a bug this lane found by measuring.

**`rotated` (1)** — the account-menu caret, `.acct::before`: two adjacent
borders on a square rotated 45°, and *which* two borders is what makes the tip
point up. Photographed in RTL (§11.5): the tip still points up, because the pair
is symmetric about the vertical axis. It must not flip. The corner sale ribbon
used to be in this group and is not any more — §11.5.

**`transition` (2)** — `.mega .mcol a::before` sets `inset: 0 100% 0 0` (a
physical shorthand) and animates it with `transition: right .18s ease`, which
names the physical property. The hover state's `right: 0` has to stay on the
property the transition is declared against — and so does its RTL twin, which is
the second row in this group. §11.6.

**`background-position` (0)** — the country `<select>` still draws its chevron
with `background-position`, which has no logical keyword, but the padding that
reserves room for it is now logical and the image moves to match. §11.7.

**`toggle` (0)**, **`masked` (0)** — both converted. §11.5.

**`off-canvas` (0)** — 14 of the 16 converted by §11.1 and the last two by Lane
FK, so this group is empty and no row of it survives in the table. What the two
stragglers were, and why they waited: `.mnav` in `store/blog.blade.php` and
`store/post.blade.php` — the same panel in two standalone layouts that hard-coded
`<html lang="en">` with no `dir`, so a `[dir="rtl"]` rule could not match in them
while converting them did change the English bytes of a shipped page. Lane FK
gave those views a real `<html lang>`/`<html dir>` and converted both in the same
change, inset and `translateX` together. §11.1 and §9.5.

## 3. Properties with no logical equivalent at all

Counted across the converted files, so that nobody goes looking for a logical
form that does not exist. None of these was touched.

| construct | occurrences | note |
|---|---|---|
| `box-shadow` x-offset | 185 | no logical form. Almost all are symmetric (`0 Npx …`); the asymmetric ones are `.mnav`'s `14px 0 40px` and the mobile pay bar's `0 -8px`. |
| `linear-gradient(<angle>)` | 92 | an angle is physical. A true RTL pass would mirror the horizontal ones; most here are vertical or decorative. |
| `transform: translateX()` / `translate()` | 77 | no logical form. This is the single biggest reason RTL needs a `[dir="rtl"]` override block and not just this rewrite. |
| `transform: rotate()` | 35 | mostly chevrons and keyframes. |
| `overflow-x` | 21 | there is `overflow-inline`, but it is not implemented anywhere. Left alone. |
| `text-shadow` x-offset | 10 | as `box-shadow`. |
| `mask-image` gradient angle | 8 | as `linear-gradient`. |
| `background-position: left/right` | 2 | no logical keyword. See the `<select>` chevron above. |

## 4. Already direction-relative — do not "fix" these

| construct | occurrences |
|---|---|
| `align-items: flex-start / flex-end` | 24 |
| `justify-content: flex-start / flex-end` | 9 |
| `align-self: flex-start / flex-end` | 8 |
| `flex-direction: *-reverse` | 1 |
| `text-indent` | 1 |

`flex-start` / `flex-end` follow the flex direction, which follows `direction`;
`row-reverse` reverses along the inline axis, which also follows `direction`;
`text-indent` is start-relative already. All of them are correct as written and
rewriting them to anything would be a change in behaviour, not a no-op.

`kbb.css` already used `margin-inline` in two places before this change
(`.kbb-home footer`, `.sbox`), so logical properties are not new to this
codebase.

---

## 5. Out of scope, and why

253 physical declarations were audited and **not** converted:

| area | declarations | why not |
|---|---|---|
| `resources/views/admin/**` + `resources/css/kbb/admin-skin-preview.css` | 223 | The admin panel is a single-locale operator tool; nothing in the plan asks for an Arabic admin. `resources/views/admin/app.blade.php` (153 of the 223) is off-limits to this lane, and its skin-preview block is *generated from* `admin-skin-preview.css` — converting the source without the generated copy would put the two out of step. Several `lane/admin-*` branches are live in this file set. |
| `resources/views/invoices/document.blade.php` | 12 | The printable invoice/packing slip, owned by `lane/printed-documents`. A printed document's column alignment is a typographic decision, not a reading-direction one, and it should be made by whoever owns that lane. |
| `resources/views/welcome.blade.php` | 18 | Laravel's stock welcome page. No route resolves to it (`grep -rn "view('welcome"` over `app/` and `routes/` finds nothing); its 18 declarations are a minified Tailwind dump on one line. Converting dead vendored CSS buys nothing. |
| `resources/views/emails/**` | 0 | Nothing to convert — but recorded here because it is the one place where a future conversion would be **wrong**. Email clients (Outlook's Word renderer in particular) do not support logical properties, so the email layouts must stay physical whatever happens to the storefront. |

---

## 6. Browser support, checked rather than assumed

| property | floor | verdict |
|---|---|---|
| `margin-inline-*`, `padding-inline-*` | Chrome 87, Firefox 66, Safari 14.1 | safe |
| `border-inline-*` (+ `-color`/`-width`/`-style`) | Chrome 87, Firefox 66, Safari 14.1 | safe |
| `inset-inline-start` / `inset-inline-end` | Chrome 87, Firefox 63, Safari 14.1 | safe |
| `text-align: start` / `end` | Chrome 1, Firefox 1, Safari 3.1 | safe |
| `border-start-start-radius` and friends | Chrome 89, Firefox 66, Safari 15 | used, twice — see below |
| `float: inline-start` / `inline-end` | **Chrome 118** (Oct 2023) | **not used** |

Two decisions come out of that table.

`float: inline-start` was rejected: Chrome only shipped it in 118, which is a
support floor this store has no reason to take on for two declarations. It is
moot anyway — the only `float` in the storefront stylesheets is `float: none`,
which carries no direction.

`border-end-start-radius` / `border-end-end-radius` were used, in exactly one
place: the selected payment method in `kbb-checkout.css`, which squares off its
two bottom corners to join the panel below it. Safari 15 is September 2021, so
the floor is fine. It is worth being honest that this particular pair is
*symmetric* (both are `0`), so it buys nothing for RTL and only costs the
support floor. It was converted for consistency with the guard rather than for
effect; if that floor ever matters, these two are the first to revert.

---

## 7. What it cost, measured

Logical property names are longer than physical ones, so this rewrite makes the
stylesheets bigger. Measured with `gzip -9` on the current tree against
`7315120`:

| | raw | gzipped |
|---|---|---|
| All 8 converted stylesheets, as committed (including the new `RTL-PHYSICAL:` comments) | +6,830 B | +1,771 B |
| The same, with CSS comments stripped — what a minifier ships | +3,077 B (+0.96%) | **+171 B (+0.28%)** |
| `kbb.css` alone, comments stripped — this is the one on every page | +1,837 B | **+77 B** |

77 gzipped bytes per page view is the whole cost of the change.

---

## 8. Render check

The acceptance bar was byte-identical rendering in LTR, so that is what was
measured rather than eyeballed.

Two `php -S` instances were run side by side against the same seeded SQLite
database — port 8942 from a detached worktree at `7315120`, port 8941 from this
branch — each with its own web root, a router script that falls through to real
files, and a Vite manifest pointing at the **source** stylesheets rather than the
stale hashes under `public/build` (which predate this change and would have made
the comparison vacuous). Chromium 1194 via `browser.newContext({viewport})`,
`deviceScaleFactor: 1`, `reducedMotion: 'reduce'`, animations and transitions
frozen by an injected stylesheet, full-page screenshots.

**Result: no difference attributable to this change.** The measurement took
three passes to state honestly, and the intermediate results are the difference
between a measurement and a claim.

An interleaved capture — before and after for each page back to back, within the
same second — gives **22 of 22 pairs byte-identical**, across 11 pages (home,
category grid, shop, product, brands, wishlist, quiz, reviews, account, cart,
checkout) × 2 viewports (1440×1000 and 390×844), with two items in the bag so
cart and checkout render real content. Those are the pairs in `docs/rtl-shots/`.

But that run is not reliably repeatable, so on its own it would be a lucky run
rather than a result. Repeating the interleaved capture gives 19 to 22 identical
pairs, with a different handful flaking each time. **The control settles it:**
capture before-vs-**before** — the same tree against itself, same harness, same
interleaving — and the flake rate is identical.

| run | identical | the pairs that differed |
|---|---|---|
| cross 1 (before vs after) | 19/22 | `desktop-brands` 313 px Δ5, `mobile-home` 12 px Δ3, `mobile-product` 12 px Δ3 |
| cross 2 (before vs after) | 19/22 | `mobile-account` 18 px Δ15, `mobile-brands` 17 px Δ3, `mobile-shop` 12 px Δ3 |
| cross 3 (before vs after) | 19/22 | `desktop-brands` 313 px Δ5, `mobile-product` 12 px Δ3, `mobile-shop` 15 px Δ3 |
| **control 1 (before vs before)** | **19/22** | `mobile-brands` 12 px Δ3, `mobile-home` 17 px Δ3, `mobile-shop` 15 px Δ3 |
| **control 2 (before vs before)** | **19/22** | `desktop-brands` 313 px Δ5, `mobile-home` 12 px Δ3, `mobile-wishlist` 12 px Δ3 |
| **control 3 (before vs before)** | **19/22** | `desktop-brands` 313 px Δ5, `mobile-account` 18 px Δ15, `mobile-shop` 12 px Δ3 |

Same rate, same magnitudes, a different set of pages each run, and both of the
largest signatures — `desktop-brands` at 313 px and `mobile-account` at Δ15 —
occur in the **control**, where the two stylesheets are byte-identical. Δ is the
maximum single-channel difference out of 255; Δ3 is invisible. This is Chromium
rasterisation, not a layout change.

Two specific flakes were chased to their cause rather than waved at:

- **`desktop-product`** differs by ~1,700 px between two runs of the *same*
  tree. The PDP renders a live "order within 6h 10m" delivery countdown, which
  ticks. Interleaving the captures removes it, and a pair captured back to back
  is pixel-identical at both viewports.
- **`desktop-brands`**, the 313-px one, was attributed directly: swapping *only*
  `kbb.css` between the two trees and re-shooting gave pixel-identical output in
  all three directions (pure-before, before-CSS-in-after-tree,
  after-CSS-in-after-tree).

**Stronger than the screenshots**, and the evidence to trust if a future run
flakes: computed geometry was dumped for every element and every
`::before`/`::after` on all 22 page/viewport combinations — position and size to
three decimal places, plus all four margins, paddings, border widths and colours,
both insets, `text-align`, `float` and all four corner radii. ~10,000 nodes.
**Identical everywhere**, with two normalisations that are not differences:

- `getComputedStyle().textAlign` echoes the *specified* keyword, so it reads
  `start` where it used to read `left`. The used value is the same — which is
  the whole premise of the rewrite.
- The `margin: 0 auto` readback on `.wrap` flaps between `0px` and the used value
  *between two runs of the same tree*, so it is a Chromium readback race against
  layout, not a difference. `getBoundingClientRect()` for the same element is
  identical to three decimal places in every run.

### The cascade rule this all depends on

The rewrite is only inert if logical and physical longhands for the same box
side resolve together in the cascade, in source order. That was verified in the
capture browser rather than assumed:

| declaration | computed |
|---|---|
| `border:1px solid A; border-inline-start:3px solid B` | `border-left: 3px B`, `border-right: 1px A` |
| `margin-inline-start:20px; margin:5px` | `margin-left: 5px` (later physical shorthand wins) |
| `margin:5px; margin-inline-start:20px` | `margin-left: 20px` (later logical longhand wins) |

Relative source order decides, exactly as it does between two physical
longhands. Every conversion in this change replaced a property name in place and
moved nothing, so order is preserved by construction — and a scan confirmed no
rule in the converted files puts a logical longhand before a physical shorthand
that would reset the same side.

### And the diff really is only renames

"Order is preserved by construction" is worth checking rather than asserting,
because it is the assumption the whole change rests on. Each of the 18 changed
files was taken, the property renames reversed mechanically
(`margin-inline-start` → `margin-left`, `text-align:start` → `text-align:left`,
and so on), and the `RTL-PHYSICAL:` comment blocks removed.

**All 18 come back byte-identical to `7315120`.** Nothing was reformatted,
reordered, re-indented or tidied; no selector moved; no value changed. The diff
is exactly a list of single-property renames plus the comments explaining what
was left alone.

---

## 9. What RTL still needs after this

*Written before the manual half. Kept, with each item's current state marked,
because the list is still the map — §11 is what happened to items 1 and 4.*

1. ~~**A `[dir="rtl"]` override block for the 77 `translateX` transforms** — the
   off-canvas panels above all. Each needs its sign flipped.~~ **DONE for every
   transform that carries a direction** — §11.1, §11.2, §11.5. Not every one of
   the 77 needed flipping: most are `translateY`, a `translate(-50%,…)` centring
   pair, or a keyframe that reads the same either way.
2. **Directional glyphs — STILL OPEN, and out of this lane.** `.sarrow.prev` /
   `.sarrow.next`, `.car-btn.l` / `.car-btn.r` and the hero carousel arrows.
   Their *positions* flip correctly; the SVG inside them does not. The
   mega-menu caret and the mobile-nav `›` turned out **not** to need anything:
   `›` (U+203A) is in the Unicode bidi mirroring set and renders as `‹` in an
   RTL run on its own, photographed in `docs/rtl-shots/manual/`.
3. **Class names that name a side** — `.dw.left`, `.drawer.left`, `.car-btn.l`,
   `.car-btn.r`, `.kc-right`, `.cright`, `.gright`. Still open, still
   deliberately not mixed in. They now mirror correctly in spite of their names;
   renaming them is a separate, noisier diff.
4. ~~**Horizontal `linear-gradient` angles** (92 of them) where the gradient
   carries meaning rather than decoration — the free-shipping fill in
   particular.~~ **The free-shipping fill is DONE** (§11.2), by mirroring its
   track rather than by respelling the angle. The other 91 are decorative
   backgrounds and panel washes and were left alone on purpose: a decorative
   gradient that mirrors is not more correct, it is just a different picture.
5. **`<html dir>` and `lang`** — Lane EP's, and **already landed** on the
   storefront layout. **But not everywhere**, and this is a finding of the
   manual half: `store/blog.blade.php`, `store/post.blade.php` and
   `store/app.blade.php` are standalone layouts that hard-code
   `<html lang="en">` with no `dir`. `/ar/skincare-guide/` serves an
   English-tagged, left-to-right page with no Arabic face. Their `.mnav` rules
   were converted anyway so the copies do not diverge, but nothing in §11
   reaches those pages until they get a real `<html lang>`/`<html dir>`. Out of
   this lane; it belongs to whoever owns those views.

## 10. The Arabic face: what shipped, and two measured notes on it

**Lane EP shipped this while T6 was in flight, and shipped it well.**
`resources/views/layouts/store.blade.php` now carries a Cairo stylesheet behind
`@if ($kbbLocale !== \App\Support\Locale::DEFAULT)`, so the English page is
unchanged byte for byte. That gate is on the *language*, not on
`Locale::isRtl()`, which is the subtle half and is right: `direction()` returns
`ltr` for Arabic while `language_rtl_enabled` is off, and Arabic words need
Arabic glyphs in that state too.

So this section is no longer a recommendation. It is the measurement this lane
did anyway, and two things in it are worth acting on.

> **Correction from the manual half (§11.8).** "Shipped it well" was about the
> `<link>`, and the `<link>` is right. But no font stack in the storefront ever
> named Cairo, so the face was downloaded by nothing and every Arabic word still
> rendered in the device fallback — the exact defect the link was added to fix.
> Note 1 below has also been actioned: `;800` is in the request. Read §11.8 for
> what the measurement looks like now.

### What the pages pay, measured

Downloaded from `fonts.gstatic.com` with a Chrome user agent (the API serves
WOFF2 only to a client that claims to support it):

| | stylesheet | font bytes | requests |
|---|---|---|---|
| `Poppins:wght@400;600;700;800` latin — every page today | 4,792 B | 31,524 B (4 files) | 5 |
| `Cairo:wght@400;600;700` arabic — Arabic pages, as shipped | 5,214 B | **30,896 B (1 file)** | 2 |

One file for three weights because Google serves Cairo's **variable** font at
that request: all three `@font-face` blocks point at the same URL. Good outcome,
and worth knowing about before anyone "optimises" it into three static weights —
a single static weight is 13,292 B, so three would be ~40 KB in three requests.

Tajawal, the plan's other candidate, is 27,404 B in **three** requests for
`400;700;800` and has no 600 at all. Cairo is 3,492 B larger and two requests
cheaper, on a host with no CDN in front of it. Cairo is the right call.

### Note 1 — weight 800 is missing, and adding it is free

The request is `wght@400;600;700`. The storefront styles text at
`font-weight:800` in **79 declarations**, including the wordmark
(`.logo`, `.kbb-checkout .co-head .logo`), the checkout's `h1.co-h`, the payment
logos and the price totals. On an Arabic page those currently fall back to the
nearest declared face, 700, so the brand mark and the order total lose a weight
step against the English page.

Adding `;800` costs **nothing in font bytes** — measured, not assumed:

| request | unique arabic files | bytes | SHA-256 (first 16) |
|---|---|---|---|
| `Cairo:wght@400;600;700` | 1 | 30,896 | `748022f50c427456` |
| `Cairo:wght@400;600;700;800` | 1 | 30,896 | `748022f50c427456` |

Byte-identical file, because it is the same variable font either way. The only
growth is the stylesheet, 5,214 B → 6,952 B uncompressed, on Arabic pages only.
The one-character edit is in this lane's report.

### Note 2 — self-hosting would remove a render-blocking request

As shipped, an Arabic page makes a second blocking `fonts.googleapis.com`
request before first paint. The account-panel font block further down that same
`<head>` exists *because* a second blocking Google Fonts stylesheet was judged
not worth one request on a guest page — the same argument applies here, and on
an Arabic page it is the page's own body font rather than a greeting nobody can
see.

Self-hosting the one 30,896-byte WOFF2 removes that request and the
`fonts.googleapis.com` round trip; `fonts.gstatic.com` is already opened for
Poppins, so no origin is saved either way — the win is the blocking stylesheet.

```css
@font-face {
  font-family: 'Cairo';
  font-style: normal;
  font-weight: 200 1000;          /* one variable file covers the whole axis */
  font-display: swap;             /* as Poppins. `optional` would drop the face
                                     on a slow connection, which on an Arabic
                                     page means the wrong script's fallback
                                     metrics for the whole session. */
  src: url('/fonts/cairo-arabic-var.woff2') format('woff2-variations');
  unicode-range: U+0600-06FF, U+0750-077F, U+0870-088E, U+0890-0891,
                 U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011,
                 U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC;
}
```

This is **not** done here, and the reason is not squeamishness: the file has to
land in the *web root*, which on this host is a different directory from the
application root. Committing a `<link>` to a file no package deploys would give
every Arabic page a 404, which is worse than one blocking request. It is a
packaging step, and it should be taken deliberately.

Two smaller findings from the same measurement, recorded so nobody re-derives
them:

- **Subsetting further is not worth it.** Cutting Google's `arabic` subset down
  to `U+0600-06FF` — dropping the presentation forms `U+FB50-FDFF` and the
  mathematical alphanumerics `U+1EE00-1EEF1`, which a storefront never uses —
  gives 27,688 B. That is 3,208 B, 10.4%, for a `pyftsubset` step in a project
  whose asset build is already manual. Ship Google's subset as it comes.
- **Append, never substitute.** `font-family: Poppins, Cairo, system-ui,
  sans-serif`. Poppins has no Arabic glyphs and Cairo's Latin is not the brand
  face, so per-codepoint selection is exactly what is wanted — and it is what
  keeps the English page rendering from Poppins with Cairo never requested.

---

## 11. The manual half — what a browser showed, and what was changed

Everything above this line was provably inert in LTR by construction. This
section is not: it is the part that needed RTL switched on and every affected
surface looked at, at 1440×1000 and 390×844, in three states — English, Arabic
with RTL off, Arabic with RTL on. Shots in `docs/rtl-shots/manual/`.

**The method.** `php -S` against a seeded SQLite database with a Vite manifest
pointing at the *source* stylesheets (the committed hashes under `public/build`
predate this change and would have made every comparison vacuous). Chromium
1194, `browser.newContext({viewport})`, `deviceScaleFactor: 1`,
`reducedMotion: 'reduce'`, animations and transitions frozen by an injected
stylesheet. Panels are opened by adding the class the site's own JS adds, so a
closed drawer and an open one are both photographable.

**The mechanism, throughout: `[dir="rtl"]` overrides and logical properties,
never a change to the default.** Proof in §11.9.

### 11.1 off-canvas (16) — the group that decides whether the shop is usable

Photographed first, because "would be visibly wrong" deserved a picture rather
than a prediction. With RTL on and nothing else changed:

| surface | what it did |
|---|---|
| cart drawer (`.drawer`) | slid in from the **right** while the cart icon sat at the top **left** |
| mobile nav (`.mnav`) | slid in from the **left** while the burger sat at the top **right** |
| sub-menu (`.msub`) | hung off the mobile nav's outer edge, half off screen |
| filter column (`.filtercol`) | opened from the **left**, trigger on the right |
| shop/product drawers (`.dw`, `.dw.left`) | both on the wrong side |

Two of the 16 were not fixed here, and **have since been fixed by Lane FK**.
`.mnav` in `store/blog.blade.php` and `store/post.blade.php` is the same panel
in two standalone layouts that hard-coded `<html lang="en">` with no `dir`
attribute — `/ar/skincare-guide/` served an English-tagged, left-to-right page
(§9.5). `[dir="rtl"]` could not match there, so the conversion bought nothing
while changing the rendered English bytes of a live page and turning
`StorefrontEnglishUnchangedTest` red. This section asked for it to be done "when
someone gives those views a real `<html dir>`, in one change that repins the
guard once".

Lane FK gave all five standalone documents `Locale::htmlLang()` and
`Locale::direction()` (see `docs/rtl-standalone-documents.md`) and converted
these two in the same change: `left: 0` is `inset-inline-start: 0`, and because
`translateX` has no logical form the panel's hidden position is flipped under
`[dir="rtl"]` exactly as `kbb.css`'s own `.mnav` does it — without which the
logical inset alone would have pushed the panel INTO the Arabic viewport
instead of out of it. Their rows are gone from the table above, which is what
the paired guards in `RtlReadinessTest` require.

Not "on screen when closed" — the audit's prediction was for converting the
inset *alone*, which is exactly why the previous lane did not. Left untouched
they were consistently, visibly mirrored the wrong way.

**What was done.** The inset was converted to `inset-inline-*` **and** the
closed-state `translateX` given a `[dir="rtl"]` twin with the opposite sign.
Both halves, together, which is the thing the audit said had to happen. So all
16 move out of the kept-physical table; `.msub`'s `border-left` becomes
`border-inline-start` with them, and `.mnav`'s `box-shadow: 14px 0 40px` — which
has no logical form and is the panel's own edge shadow falling across the page —
is negated in the same override.

**The cascade trap, which this lane fell into first.** `[dir="rtl"] .drawer` and
`.drawer.on` both score (0,2,0). Source order decides. Written below the `.on`
rule, the override re-applies the closed transform to an *open* drawer and the
panel never appears in Arabic — while every declaration-level test passes. Every
override is therefore placed directly under the rule it mirrors and **above** the
`.on` rule, and `RtlMirrorTest` fails if one ever moves below it.

### 11.2 fill-bar (16) — it mirrors, and that was not a choice

The brief asked whether the free-delivery bar should mirror. Measurement answers
it: **`.ffill` is an ordinary in-flow block**, so the moment `<html dir>` is
`rtl` it starts at the track's right edge and grows leftward with no CSS change
at all. A 45% fill in a track at `[556, 876]` sits at `[732, 876]`.

So the fill mirrors whether anyone decides it should or not, and what was left
behind was everything pinned to it. The jade pulse dot and the comet rider are
positioned against the fill's *leading* edge; both sat at the far right, i.e. at
the anchored end of a bar now growing away from them. **The comet was flying
backwards** — measured at `[866.8, 883]` with the leading edge at 732.

**What was done — one rule, not sixteen.**

```css
[dir="rtl"] .kbb-checkout .ftrack{direction:ltr;transform:scaleX(-1)}
```

`direction: ltr` puts the track back into the coordinate system its 16
declarations were written for; `scaleX(-1)` mirrors the finished picture. After:
rider at `[725, 741.2]`, centred on the leading edge, as in LTR. The gradient,
the shine, the stripes and the burst all mirror with it.

All 16 declarations stay physical and stay in the table. This is the cleaner
boundary the audit already argued for, taken one step further: the unit that
mirrors is the track.

It is safe **only because `.ftrack` contains no text** — its children are
`.ffill` and the `.fs-cheer` particles, which are stars, sparkles and coloured
rectangles. The "AED 40 to go" label is in `.freebar`, a sibling. Do not extend
this rule upward. `overflow-x: clip` stays on `.freebar`, so the mobile
horizontal-scroll fix documented in that file still covers the mirrored burst.

### 11.3 centre (13) — verified, and deliberately untouched

`left: 50%` + `translateX(-50%)` was checked rather than assumed, because the
brief asked for that specifically. In a `dir="rtl"` document at 900px wide the
toast spans `397.5..502.5`; centre 450.0, which is exactly half of 900.

Correct in both directions, because `left: 50%` is unambiguous and `translateX`
is physical in both. **All 13 unchanged**, and `RtlMirrorTest` now fails if a
`[dir="rtl"]` rule is ever added to any selector this document records as
`centre`.

### 11.4 off-screen (3) — the note in this document was backwards

The old text said `inset-inline-start: -9999px` "would park it off the right edge
in RTL, where it can extend the scrollable area". Measured, it is the other way
round. In a right-to-left document the scrollable overflow region extends to the
**left**:

| | `left:-9999px` | `[dir="rtl"]` fixed |
|---|---|---|
| `documentElement.scrollWidth` | 10899 | 900 |
| `clientWidth` | 900 | 900 |
| `scrollLeft` range | −9999 … 0 | 0 … 0 |

So with RTL on, the two honeypots and WooCommerce's hidden `#place_order` gave
an Arabic shopper ten thousand pixels of blank page to scroll into — the same
class of defect as the burst particles the checkout already had a comment about.

`left: -9999px` stays as the LTR default (it is correct there, and it is the
idiom). Each gains:

```css
[dir="rtl"] .hp{inset-inline-start:-9999px;inset-inline-end:auto}
```

which in RTL resolves to `right: -9999px; left: auto` — the side that is
discarded. Logical spelling, so no new physical declaration and no new table row.

### 11.5 rotated (5) — one flips, one must not, and a picture of each

**The corner sale ribbon (4 declarations, two files).** In RTL it stayed across
the top-**right** corner of a card grid that had mirrored around it. Converted:
`inset-inline-end: -36px; inset-inline-start: auto` plus
`[dir="rtl"] … {transform: rotate(-45deg)}`. It now crosses the top-left corner
with the text still running readably. `scaleX(-1)` was rejected here — unlike the
corner artwork, this element carries text. Moves out of the table.

**The account-menu caret, `.acct::before` (1 declaration).** `border-left` +
`border-top` on a square rotated 45°. Photographed with the panel forced open in
RTL: the panel moves to the other side with its logical inset and **the tip still
points up**, because the two borders are symmetric about the vertical axis.
Flipping either one would point it sideways. **Unchanged**, and it is the only
row left in the `rotated` group.

**`masked` (1), the alternating section corner artwork.** `right: 0` plus a
physical `border-radius` shorthand plus a `linear-gradient(230deg)` mask — three
direction-carrying values that would have to be kept in step by hand. Converted
the inset and mirrored the rendered result instead:
`[dir="rtl"] … {transform: scaleX(-1)}`. One declaration, and it cannot drift.
Safe because it is artwork with no text in it. Moves out of the table.

**`toggle` (1), the filter switch.** In RTL the knob rested at the left and moved
right — the LTR behaviour, unmirrored. Now `inset-inline-start: 2.5px` with
`[dir="rtl"] .tog.on::after{transform:translateX(-17px)}`: rest at the inline
start, travel to the inline end, in both directions. Moves out of the table.

### 11.6 transition (1 → 2) — the one row this lane added

`.mega .mcol a::before` sets `inset: 0 100% 0 0` and animates it with
`transition: right .18s ease`. In RTL that still pins the wipe to the physical
left and sweeps it rightward — backwards for a row of right-to-left text, and it
does animate, so nothing looks broken; it just runs the wrong way.

Two spellings were tried and both animate correctly in Chromium (measured
mid-flight: half width at half the duration). The logical one —
`transition: inset-inline-end` — works, and was **rejected**: it only reads
correctly if you also notice that the base rule's `:hover{right:0}` happens to be
a no-op in RTL. The physical twin says what it does:

```css
[dir="rtl"] .mega .mcol a::before{inset:0 0 0 100%;transition:left .18s ease}
[dir="rtl"] .mega .mcol a:hover::before{left:0}
```

That `left: 0` is a physical direction declaration, so it is **added to the
kept-physical table** with the reason `transition`. It is the only row this lane
added, and the guard pins it exactly as it pins the other 34.

### 11.7 background-position (1) — the padding moves, the image follows

`background-position: right 12px center` with `padding-right: 36px`. In RTL the
select right-aligns its text and the chevron stayed on the right with no room
reserved for it. `padding-right` becomes `padding-inline-end` (moves out of the
table) and the image is moved to match:

```css
[dir="rtl"] #billing_country_field select.input-text{background-position:left 12px center}
```

`background-position` is not a property the reader tracks, so this adds no row.
The pair has to name the same side or the chevron sits on the country name.

### 11.8 The Arabic face: linked, and never used

§10 recorded that Lane EP shipped the Cairo `<link>` "and shipped it well",
including weight 800. The link is right and the gating is right. **The face was
never rendering**, because nothing named it: `--sans` is
`"Poppins",system-ui,…` in all four stylesheets that define it, `kbb.css` sets
`body{font:400 14px/1.6 Poppins,system-ui,sans-serif}` with the shorthand, and
the product card name, the badges and the add-to-cart button hard-code
`'Poppins',sans-serif`. A browser fetches a face when something uses it.

Measured with Cairo served from Google's own bytes (the capture browser cannot
reach `fonts.gstatic.com` through this sandbox's proxy), the same Arabic string
at 40px, page stack versus Cairo:

| | page stack | Cairo | |
|---|---|---|---|
| weight 400 | 496.53 | 479.05 | before — matches neither: system fallback |
| weight 800 | 595.63 | 537.88 | before |
| weight 400 | **479.05** | 479.05 | after |
| weight 800 | **537.88** | 537.88 | after |

Fixed by appending — never substituting — Cairo after the Latin face, in one
`<style>` block emitted inside the existing `@if ($kbbLocale !== DEFAULT)`, so an
English page is unchanged byte for byte and per-codepoint selection still gives
Latin to Poppins. A sweep of every text-bearing element across the Arabic home,
shop, product, cart and checkout pages went from **960 to 1800** on a
Cairo-capable stack; what is left is `<title>`/`<script>`/`<style>`, which render
nothing, and the blog views from §9.5, which are not bilingual at all.

Independently confirmed while doing it: `Cairo:wght@400;600;700;800` returns
6,952 B of CSS and **one** 30,896-byte variable WOFF2 shared by all four weights
— so §10's "weight 800 costs nothing" is right, and the two weights do render
differently (479.05 vs 537.88), so it is buying something.

### 11.9 Proof that English did not move

Two independent proofs, because screenshots on this box flake.

**Computed geometry, which cannot flake.** Every element and every
`::before`/`::after` on 9 pages × 2 viewports, with every off-canvas panel
force-opened so the changed rules are exercised: position and size to three
decimals, all four margins, paddings, border widths, colours and styles, both
insets, `text-align`, `float`, all four corner radii, **`transform`**,
**`background-position`** and `direction`. **9,200 nodes, 0 differences.** The
last three properties are the ones this lane actually changes, and the earlier
render check in §8 did not capture them.

**Structural, which cannot flake either.** Two mechanical checks over the diff:

1. every rule selector this lane added is scoped to `[dir="rtl"]` — **0
   exceptions**, so none of them can match in a left-to-right document;
2. with those rules and all CSS comments removed and the logical property names
   renamed back, **all 9 changed files come back byte-identical** to the branch
   point.

**Screenshots, for corroboration.** 25 surfaces × before/after in English: 22
byte-identical. The three that differed were `desktop-product` (max channel delta
221 — the live "order within 6h 10m" countdown, which differs by the same amount
between two captures of the *same* tree, exactly as §8 found) and
`mobile-checkout` and `mobile-shop`, at **3 and 4 pixels, max delta 2 of 255**.

### 11.10 What still needs the owner, or another lane

- **`-30%` reads as `30%-` on the sale ribbon in Arabic.** Not CSS: the Unicode
  bidi algorithm reorders a leading hyphen-minus to the trailing side of an RTL
  run. The fix belongs to whoever formats that label — wrap the number in an LTR
  isolate. Photographed.
- **The blog, article and `/app` views are not bilingual** — §9.5.
- **Directional glyphs in the carousel and slider arrows** — §9.2.
- **No mirrored logo or photograph was found** that would need an owner's
  decision. The wordmark is text (`K-Beauty` + `Bliss`), the hero and card images
  are `<img>`/`background-image` and are never transformed, and the only
  `scaleX(-1)` this lane introduces is on a decorative corner gradient and on a
  progress track that contains no text or photography.

---

## 12. Lane FS — the three things §9.2, §11.8 and §11.10 left, and three corrections

Full record, with every table and every method, in `docs/FS-ARABIC-TYPOGRAPHY.md`.
This section is the part that corrects what is written above it, because a wrong
sentence in this document is worth more to fix than a right one is to add.

### 12.1 §11.10 is wrong about U+2212 MINUS SIGN

§11.10 says the `-30%` ribbon can be fixed by wrapping the number in an LTR
isolate "or use U+2212 MINUS SIGN". **U+2212 does not fix it.** It carries bidi
class ES exactly as HYPHEN-MINUS does, so it is resolved from the surrounding run
in exactly the same way. Rendered in Chromium and read back character by
character, logical `-30%` in an RTL run:

| | alone | inside Arabic text |
|---|---|---|
| HYPHEN-MINUS | `30%-` | `%30-` |
| **U+2212 MINUS SIGN** | `30%−` | `%30−` |
| U+2066 … U+2069 | `-30%` | `-30%` |
| `<bdi>` | `-30%` | `-30%` |

Only the shape differs. The isolate is the fix, and it is the one that shipped.

### 12.2 The bidi defect is not confined to the mirrored layout

In an `<html dir="ltr">` document — Arabic with the mirrored layout switched off,
which this shop supports — `-30%` sitting inside Arabic text **still** paints
`%30-`, because an Arabic word opens a right-to-left run wherever it stands. So
the isolate is gated on the LANGUAGE, not on `Locale::isRtl()`. The arrowheads in
12.4 are gated the other way round, and deliberately: a face belongs to the
script, an arrow belongs to the layout.

### 12.3 §9.2's `.sarrow` row is stale, and its `›` claim is confirmed

`.sarrow.prev` / `.sarrow.next` are listed as open directional glyphs. **Nothing
renders them** — the class survives only in `kbb.css`, and no view, partial,
component or script emits it. The home slider's arrows are `.sarr`, and they are
the characters `‹` and `›`.

§9.2's good news is confirmed rather than inherited. Each glyph was rendered
centred in a fixed box in both directions — so position cannot differ and only
shape can — and the two screenshots compared byte for byte:

| flips itself | does not |
|---|---|
| U+203A `›`, U+2039 `‹`, U+00BB `»`, U+003E `>` | U+2192 `→`, U+2190 `←`, U+25B6 `▶`, U+2794 `➔`, U+21A9 `↩` |

So the mobile-nav `›` and the mega-menu caret need nothing, exactly as §9.2 says.
An arrow needs everything, and so does any `<svg>` path.

### 12.4 What was done

- **§11.8's hand-off, finished.** The five standalone documents link and NAME
  Cairo. Measured the way §11.8 measured: all five went from matching neither the
  page stack nor Cairo to matching Cairo to the hundredth of a pixel at 400, 700
  and 800, and from 0 to 100% of their text-bearing elements on a Cairo-capable
  stack. English is byte-identical on all seven pages.
- **§11.10's ribbon, and the sweep it asked for.** `App\Support\Bidi::number()`,
  a no-op in English. The sweep found something bigger than the ribbon: the
  shop's phone number, `+971 58 505 2611`, paints **backwards** on every Arabic
  page — `2611 505 58 971+` — because each space-separated group becomes its own
  number run.
- **§9.2's arrowheads.** Three `<svg>` chevrons mirrored under `[dir="rtl"]`, two
  JavaScript arrows and one Blade arrow chosen by the direction.

### 12.5 Two of those ship inert until the bundle is rebuilt

`kbb.css`, `kbb-checkout.css`, `mobile-nav.js` and `search.js` are BUILT assets
and this lane did not run `npx vite build`. Verified rather than assumed: the new
`[dir="rtl"] .mm-car` rule is in the source once and in
`public/build/assets/kbb-*.css` zero times. The mobile-menu chevron, the
back-to-cart chevron and the two arrows need the rebuild that `cb3c745` did for
the manual half; everything else in Lane FS ships with the views.

---

## 13. Lane G — the 34 re-read one declaration at a time, and what that turned up

The brief was the 57: confirm each one, convert what can be converted, re-mark
what cannot. 23 were already converted by the time this lane ran (§11's 21 and
Lane FK's 2), so the work was the remaining **34**, and the finding is that all
34 are correctly physical **as declarations** — while three surfaces that no
declaration reader can see were wrong anyway. Those three are the change.

### 13.1 The verdict on each group

| group | kept | verdict, and how it was reached |
|---|---|---|
| `fill-bar` | 16 | Unchanged, and **not touched at all**: they are in `kbb-checkout.css`, which is live under the checkout lane this round. §11.2 already mirrors the whole track with one rule, so nothing here needs a second opinion. |
| `centre` | 13 | Re-read declaration by declaration. Every one is `left:50%` paired with `translateX(-50%)` **in the same block**, which is the centring idiom and not a direction. Confirmed in the browser as well: the home rail measures 12..378 in a 390px viewport in **both** directions, dots centred on 195. Settled, not converted. **But see 13.3** — one of them leaks onto a component that never asked for it. |
| `off-screen` | 3 | Unchanged. §11.4's `[dir="rtl"]` twins park them on the discarded edge; `document.documentElement.scrollWidth` is 390 on every RTL page shot in `docs/rtl-shots/lane-g/`, so the ten-thousand-pixel scroll that defect used to open is gone and has not come back. |
| `rotated` | 1 | Unchanged. `.acct::before` is two adjacent borders on a square rotated 45°, symmetric about the vertical axis. |
| `transition` | 2 | Unchanged — one of them is the row §11.6 added, which is why the table has 35 rows and the original 57 gave up only 34. |

**16 of the 34 are in a file this lane must not edit.** `kbb-checkout.css` is
the checkout/cart lane's this round, so every `fill-bar` row above was read and
left alone. None of them needs a change; if that ever stops being true it is a
change to coordinate, not to make.

### 13.2 What a declaration reader cannot see, and why it matters here

An `RTL-PHYSICAL:` comment describes a stylesheet. Three things outrank a
stylesheet, and each one hid a real defect:

1. **An inline `style` attribute in a Blade view.** It beats every rule,
   including one inside a media query; only `!important` gets past it.
2. **An inline `style.transform` written by JavaScript**, rewritten on every
   click, which no stylesheet can override at all.
3. **A shorter selector in the same file** that still matches, carrying a
   `transform` the longer rule never asked for.

All three were found by the same instrument: load a page, dump the geometry of
every block box, set `document.documentElement.dir = 'rtl'`, dump it again, and
flag every box whose mirror is not where the mirror should be. Same page, same
text, same fonts — only the direction changes, so a mismatch is a mirroring
defect and not a translation-length artefact. Six pages × two viewports,
**2,881 block boxes**; before the fixes, five roots failed; after, one, and that
one is dismissed below.

### 13.3 The three defects, measured before and after

**A · The product page's discount badge did not mirror, and landed on the
wishlist heart.** `partials/product-gallery.blade.php` places it with
`style="top:14px;left:14px"` in three branches. Everything around it is logical
— `.gwish` next to it is `inset-inline-end:14px` — but an inline declaration
outranks the stylesheet, so T6 could not reach this one.

| /ar/product/… at 390px | `.gwish` | the badge |
|---|---|---|
| English | 311..355 | 35..85.9 |
| Arabic, before | 35..79 | 35..85.9 — **printed on top of the heart** |
| Arabic, after | 35..79 | 304.1..355 — the exact mirror of English |

Fixed in `kbb-product.css` with `[dir="rtl"] .gmain .lbl{inset-inline-start:14px
!important;inset-inline-end:auto!important}`. Logical spelling, so it adds no
physical declaration and no table row; `!important` only because of the inline
style. Pictures: `fix-badge-BEFORE-rtl-390.jpg`, `fix-badge-rtl-390.jpg`,
`fix-badge-ltr-390.jpg`.

**B · The home hero went blank in Arabic on the first ▸.**
`resources/js/kbb/home.js:29` advances the track with
`style.transform = translateX(-index*100%)`. In an RTL flex row the slides queue
to the LEFT of the first one, so that negative translation carries them further
away instead of into the frame. Measured at 390px, percentage of each slide
inside the frame after one click of ▸:

| | slide 1 | slide 2 | slide 3 |
|---|---|---|---|
| English | 0 | **100** | 0 |
| Arabic, before | 0 | **0** | 0 — the hero is empty |
| Arabic, after | 0 | **100** | 0 |

Fixed by giving the track back the coordinate system its arithmetic assumes —
the same move §11.2 made on `.ftrack` — and handing each slide its own
direction: `[dir="rtl"] .kbb-home .slides{direction:ltr}` +
`[dir="rtl"] .kbb-home .sl{direction:rtl}`. Safe here and not on `.freebar`
because the text lives one level down, inside `.sl`. **Travel still runs
left-to-right in Arabic**; reversing it is a sign flip in `home.js`, which is
not this lane's file — see 13.5. Pictures: `fix-slider-BEFORE-rtl-390.jpg`,
`fix-slider-rtl-390.jpg`, `fix-slider-rtl-1280.jpg` and the two English ones.

**C · The home slider's dots were displaced by half their own width — in
English too.** This one is the centring idiom's only genuine casualty and it
predates T6. `.sdots` (the theme's rail, dots are `<button>`) centres with
`left:50%` + `translateX(-50%)`. `.kbb-home .sdots` is a different component
(dots are `<i>`) which centres with both insets 0 and `justify-content:center`
and declares no transform — so the shorter selector's `translateX(-50%)` still
matched.

| English home page | the rail | first dot |
|---|---|---|
| 390px, before | -171..195 | -14..8 — **half of it off the phone's left edge** |
| 390px, after | 12..378 | 169..191 |
| 1280px, before | -562.8..640 | dots at x ≈ 12, 41, 56 |
| 1280px, after | 38.6..1241.4 | dots at x ≈ 614, 643, 658 — centred on 640 |

One declaration: `transform:none` on `.kbb-home .sdots`. It is wrong in both
directions, so the fix is not scoped to one — **this is the single deliberate
change to the English page in this lane**, and it is called out in the commit
rather than buried. Pictures: `fix-dots-BEFORE-ltr-390.jpg`,
`fix-dots-BEFORE-ltr-1280.jpg` and their `-after` counterparts.

### 13.4 A fourth, fixed because the mechanism is the same

`partials/drawers.blade.php` writes `style="margin-left:auto"` on the mobile
nav's ✕. `margin-left` is physical, so in RTL it absorbs the free space on the
wrong side. Nothing looks wrong at 390px **because there is no free space to
absorb** — 191.2 (wordmark) + 10 (gap) + 32 (button) + 32 (padding) = 265.2,
which is the panel — but at 1280 the same button measured 94.44px away from its
mirror position. One shortened wordmark and the phone inherits that. Fixed with
`[dir="rtl"] .mnav-h .x{margin-inline-start:auto!important;margin-inline-end:0
!important}`: after it, 996..1028 at 1280, the exact mirror of English's
252..284. Picture: `fix-mnav-x-rtl-1280.jpg`.

### 13.5 Found and NOT fixed

- **The Arabic hero still travels left-to-right.** 13.3 B makes the carousel
  work; making ▸ pull the next slide in from the reading direction's side is a
  sign flip in `resources/js/kbb/home.js`, gated on `Locale::isRtl()`. That file
  is not this lane's. When it is done, delete
  `[dir="rtl"] .kbb-home .slides` with it — `RtlMirrorTest` fails the moment
  both exist, which is deliberate.
- **The burger icon's four tiles do not reverse.** `.kbbmi-tiles .s` are four
  identical 9×9 squares at `translate(±6px,±6px)`; the group mirrors, the tiles
  keep their DOM order. They differ only in animation phase, so the colour wave
  runs the same way physically in both directions. Visually indistinguishable at
  rest; recorded so the next sweep does not re-derive it.
- **`store/app.blade.php` carries an inline `right:50%;margin-right:-24px`** on
  the cart-count badge and is excluded from the sweep on purpose: that view
  hard-codes `<html lang="en">` with no `dir`, so no `[dir="rtl"]` rule can
  match in it. It belongs with §9.5's hand-off, not here.
- **16 `fill-bar` declarations in `kbb-checkout.css` were read and not touched**
  — that file is another lane's this round (13.1).

### 13.6 Proof that English did not move, except where it meant to

Same method as §11.9, run against the built bundles rather than the sources:
the pre-change bundle from `HEAD` served on one port, this branch's on another,
same application, same database, same seeded catalogue.

**Computed geometry, which cannot flake.** Every element and every
`::before`/`::after` on 9 pages × 2 viewports, with every off-canvas panel
force-opened, comparing position and size to three decimals plus all four
margins, paddings, border widths, both insets, `text-align`, `float`, all four
corner radii, `transform`, `background-position`, `direction`, `display` and
`position`. **7,770 nodes, 8 differing rows**, and all 8 are the home page's dot
rail and its three dots at the two viewports — the one change 13.3 C meant to
make. Every other node on every other page is identical, including the four
panels this lane's rules touch.

**The mirror sweep, after.** The five failing roots are down to one (13.5's
burger tiles) plus the slider's two off-screen slides, which now queue on the
same side in both directions — that is what `direction:ltr` on the track means,
and it is the point of the fix rather than a side effect.

**Every drawer photographed CLOSED in RTL at 390px**, which is the specific way
this change breaks: `docs/rtl-shots/lane-g/rtl-390-drawers-closed-*.jpg`, with
the English shot of the same page beside it. The numbers under each:

| page | `.drawer` | `.mnav` | `.filtercol` | `scrollWidth` |
|---|---|---|---|---|
| `/ar/` | -300.3..0 | 403.3..668.4 | — | 390 |
| `/ar/shop/` | -300.3..0 | 390..690 | 390..690 | 390 |
| `/ar/product/…` | -300.3..0 | 390..690 | — | 390 |
| `/ar/cart/` | -300.3..0 | 403.3..668.4 | — | 390 |

Every one of them entirely outside the viewport, on the mirror of the side it
parks on in English, and `scrollWidth` equal to `clientWidth` on all four — a
panel parked off the wrong edge in RTL would show up as both.
