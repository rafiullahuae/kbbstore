# T6 · RTL — audit and logical-property rewrite

**Status: the mechanical half is done. RTL is not switched on and this change
does not switch it on.** Every conversion here is a no-op in a left-to-right
document, which is what makes it safe to land while the locale switch (Lane EP)
does not exist yet.

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
| — converted to logical | **368** |
| — left physical on purpose | **57** |
| — out of this lane's scope (admin, invoice, dead Laravel welcome page) | **253** |

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
| `resources/css/kbb/kbb.css` | 183 | 5756 |
| `resources/css/kbb/kbb-shop.css` | 34 | 1243 |
| `resources/css/kbb/kbb-product.css` | 32 | 1337 |
| `resources/css/kbb/kbb-cart.css` | 5 | 300 |
| `resources/css/kbb/kbb-checkout.css` | 35 | 1334 |
| `resources/css/kbb/kbb-account.css` | 2 | 170 |
| `resources/css/kbb/kbb-banner.css` | 0 | 100 |
| `resources/css/kbb/kbb-grid-skins.css` | 13 | 569 |
| `resources/css/kbb/sorina-reviews.css` | 11 | 595 |
| `resources/views/layouts/store.blade.php` | 3 | 104 |
| `resources/views/store/app.blade.php` | 22 | 1400 |
| `resources/views/store/skin-quiz.blade.php` | 9 | 571 |
| `resources/views/store/blog.blade.php` | 3 | 224 |
| `resources/views/store/post.blade.php` | 2 | 223 |
| `resources/views/store/review-wall.blade.php` | 4 | 302 |
| `resources/views/store/checkout.blade.php` | 2 | 33 |
| `resources/views/store/checkout-success.blade.php` | 1 | 154 |
| `resources/views/store/account/order-detail.blade.php` | 3 | 148 |
| `resources/views/store/account/track.blade.php` | 1 | 63 |
<!-- rtl-audit:floors:end -->

`kbb-banner.css` had none to begin with; it is in scope so that a physical
declaration added to it later fails the guard.

---

## 2. What stays physical, and why

These 57 declarations are inside the converted files and are **deliberately not
converted**. Each one is either an idiom that is not about reading direction, or
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
| `resources/css/kbb/kbb.css` | `.sdots` | `left: 50%` | centre |
| `resources/css/kbb/kbb.css` | `.drawer` | `right: 0` | off-canvas |
| `resources/css/kbb/kbb.css` | `.mnav` | `left: 0` | off-canvas |
| `resources/css/kbb/kbb.css` | `.msub` | `left: calc(var(--mw) - var(--sw)/2)` | off-canvas |
| `resources/css/kbb/kbb.css` | `.msub` | `border-left: 1px solid var(--line-2)` | off-canvas |
| `resources/css/kbb/kbb.css` | `.toast` | `left: 50%` | centre |
| `resources/css/kbb/kbb.css` | `.kbb-pgrid[data-skin="editorial"] .cn:after` | `left: 50%` | centre |
| `resources/css/kbb/kbb.css` | `.kbb-pgrid[data-skin="ribbon"] .kbb-badge-sale` | `right: -36px` | rotated |
| `resources/css/kbb/kbb.css` | `.kbb-pgrid[data-skin="ribbon"] .kbb-badge-sale` | `left: auto` | rotated |
| `resources/css/kbb/kbb.css` | `.kbb-home .sec:nth-of-type(odd) > .wrap::before` | `right: 0` | masked |
| `resources/css/kbb/kbb.css` | `.acct::before` | `border-left: 1px solid var(--line-2)` | rotated |
| `resources/css/kbb/kbb-shop.css` | `.mnav` | `left: 0` | off-canvas |
| `resources/css/kbb/kbb-shop.css` | `.tog::after` | `left: 2.5px` | toggle |
| `resources/css/kbb/kbb-shop.css` | `.qv` | `left: 50%` | centre |
| `resources/css/kbb/kbb-shop.css` | `.dw` | `right: 0` | off-canvas |
| `resources/css/kbb/kbb-shop.css` | `.dw.left` | `left: 0` | off-canvas |
| `resources/css/kbb/kbb-shop.css` | `.dw.left` | `right: auto` | off-canvas |
| `resources/css/kbb/kbb-shop.css` | `.toast` | `left: 50%` | centre |
| `resources/css/kbb/kbb-shop.css` | `@media(max-width:900px) .filtercol` | `left: 0` | off-canvas |
| `resources/css/kbb/kbb-product.css` | `.mnav` | `left: 0` | off-canvas |
| `resources/css/kbb/kbb-product.css` | `.hp` | `left: -9999px` | off-screen |
| `resources/css/kbb/kbb-product.css` | `.dw` | `right: 0` | off-canvas |
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
| `resources/css/kbb/kbb-checkout.css` | `#billing_country_field select.input-text` | `padding-right: 36px` | background-position |
| `resources/css/kbb/kbb-grid-skins.css` | `.kbb-pgrid[data-skin="editorial"] .cn:after` | `left: 50%` | centre |
| `resources/css/kbb/kbb-grid-skins.css` | `.kbb-pgrid[data-skin="ribbon"] .kbb-badge-sale` | `right: -36px` | rotated |
| `resources/css/kbb/kbb-grid-skins.css` | `.kbb-pgrid[data-skin="ribbon"] .kbb-badge-sale` | `left: auto` | rotated |
| `resources/css/kbb/sorina-reviews.css` | `.sr-mcard` | `left: 50%` | centre |
| `resources/css/kbb/sorina-reviews.css` | `@media(min-width:760px) .sr-scard` | `left: 50%` | centre |
| `resources/css/kbb/sorina-reviews.css` | `@media(min-width:760px) .sr-scard` | `right: auto` | centre |
| `resources/css/kbb/sorina-reviews.css` | `.sr-hp` | `left: -9999px` | off-screen |
| `resources/views/layouts/store.blade.php` | `.qv-btn` | `left: 50%` | centre |
| `resources/views/store/app.blade.php` | `.drawer` | `right: 0` | off-canvas |
| `resources/views/store/app.blade.php` | `.drawer.left` | `right: auto` | off-canvas |
| `resources/views/store/app.blade.php` | `.drawer.left` | `left: 0` | off-canvas |
| `resources/views/store/app.blade.php` | `.toast` | `left: 50%` | centre |
| `resources/views/store/skin-quiz.blade.php` | `.toast` | `left: 50%` | centre |
| `resources/views/store/blog.blade.php` | `.mnav` | `left: 0` | off-canvas |
| `resources/views/store/post.blade.php` | `.mnav` | `left: 0` | off-canvas |
<!-- rtl-audit:physical:end -->

### The reasons in full

**`centre` (13)** — `left: 50%` paired with `transform: translateX(-50%)`.
This is the centring idiom, not a statement about direction. `translateX` has no
logical equivalent, so converting the inset alone would resolve to `right: 50%`
in RTL while the transform still pulled the element left — moving it off centre
by its own width. Toasts, carousel dot rails, the quick-view button, the review
modal and the editorial card underline are all this.

**`off-canvas` (16)** — a panel parked off-screen by `transform: translateX(±100%)`
and anchored by a physical inset. `.drawer`, `.mnav`, `.msub`, `.dw`, `.dw.left`
and `.filtercol`. Converting the inset flips which edge the panel is anchored to
without flipping the direction it slides from, so in RTL the panel would sit
*on* screen in its closed state. `.msub`'s `border-left` is the same panel's
visible edge and belongs with it. These are the rules that need a `[dir="rtl"]`
block overriding the transform when RTL is switched on — that is switch work, not
rewrite work.

**`fill-bar` (16)** — the checkout's free-shipping progress bar and its
celebration sprites. The fill is `linear-gradient(90deg, …)` with a width
transition; the shine and the comet rider ride on `translateX`; the burst
particles fly along `--tx` custom properties; the track is trimmed with
`overflow-x: clip`. None of those has a logical form, and the sprite positions
are measured against the fill they sit on. The whole block stays physical as one
unit, which is a cleaner boundary than converting the insets and leaving the
sprites behind.

**`off-screen` (3)** — `left: -9999px` on the two spam honeypots and on
WooCommerce's real `#place_order` button. "Off the left edge" is a physical
trick for hiding something from sight while leaving it in the accessibility
tree; it is not a reading direction. `inset-inline-start: -9999px` would park it
off the *right* edge in RTL, where it can extend the scrollable area.

**`rotated` (5)** — geometry fixed by a `rotate()`. The corner sale ribbon is
`right: -36px` + `rotate(45deg)`; flipping the inset without flipping the
rotation puts the ribbon across the wrong corner still pointing the old way. The
account-menu caret is two adjacent borders on a square rotated 45°, and *which*
two borders is what makes the tip point up.

**`toggle` (1)** — the filter switch knob rests at `left: 2.5px` and moves with
`.tog.on::after { transform: translateX(17px) }`. Both halves move together or
neither does.

**`transition` (1)** — `.mega .mcol a::before` sets `inset: 0 100% 0 0` (a
physical shorthand) and animates it with `transition: right .18s ease`, which
names the physical property. The hover state's `right: 0` has to stay on the
property the transition is declared against.

**`masked` (1)** — the alternating section corner artwork is pinned by a
physical `border-radius` shorthand and clipped by `mask-image:
linear-gradient(230deg, …)`. Neither has a logical form.

**`background-position` (1)** — the country `<select>` draws its chevron with
`background-position: right 12px center` and reserves room for it with
`padding-right: 36px`. `background-position` has no logical keyword, so the
padding must stay on whichever side the image is on.

---

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

This change does not make the site work in RTL. It removes the mechanical part.
What is left is genuinely directional and belongs with the switch:

1. **A `[dir="rtl"]` override block for the 77 `translateX` transforms** — the
   off-canvas panels above all. Each needs its sign flipped.
2. **Directional glyphs.** `.sarrow.prev` / `.sarrow.next`, `.car-btn.l` /
   `.car-btn.r`, the mega-menu carets and the breadcrumb `›`. Their *positions*
   now flip correctly; the SVG inside them does not, so a chevron would point the
   wrong way. A `[dir="rtl"] .sarrow svg { transform: scaleX(-1) }` handles the
   ones that mean "previous/next"; anything that means "back" should not flip.
3. **Class names that name a side** — `.dw.left`, `.drawer.left`, `.car-btn.l`,
   `.car-btn.r`, `.kc-right`, `.cright`, `.gright`. Harmless, but they will read
   as lies in RTL. Renaming them is a separate, noisier diff and was deliberately
   not mixed into this one.
4. **Horizontal `linear-gradient` angles** (92 of them) where the gradient
   carries meaning rather than decoration — the free-shipping fill in particular.
5. **`<html dir>` and `lang`** — Lane EP's, and **already landed**: the
   bilingual foundation on the base branch sets `dir="{{ $kbbDir }}"` from
   `App\Support\Locale::direction()`, gated on the `language_rtl_enabled`
   setting. The switch this rewrite was waiting for now exists; what is
   missing is items 1–4 above, which is the right-to-left stylesheet itself.

---

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
