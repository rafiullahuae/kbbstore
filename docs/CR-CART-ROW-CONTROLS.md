# The cart page's two rows, and the four things Lane CR changed

*29 September 2026.*

The owner, with two screenshots of his live cart:

> "on the cart page.. for product rows and set rows. i need totally different
> controls like height spacing, padding etc. the products rows controls will be
> on the Cart Page under appearance as we have already, but make more controls
> of spacing etc. and for set rows, make controls that only controls the set
> rows. not the other products rows. also make it super easier the set control
> page, I have change the padding etc for desktop for set rows, but still the
> set name and the quanity button is hiding, and also the tiny popup also
> hiding inside the set row, it should show on top of and not hide anywhere."

## Where the controls sit

| What it shapes | Where |
|---|---|
| An ordinary product's row, laptop | **Appearance → Cart page → Product rows · spacing and size** |
| An ordinary product's row, phone | **Appearance → Cart page → Product rows · phone** |
| A SET's row, laptop | **Appearance → Set → Desktop → The set row on the cart page** |
| A SET's row, phone | **Appearance → Set → Mobile → The set row on the cart page** |

The four sliders that were already on **Appearance → Cart page → Product rows**
keep their card, renamed **Product rows · the squeezed layout**, because that is
what they are: `row_h`, `row_font`, `row_bold` and `qty_size` are `--cpg-*`
custom properties, and every rule that reads one lives in
`resources/views/store/cart-squeeze.blade.php`, which the CLASSIC layout does
not include. On the page this shop serves they save, report success and move
nothing. That is why the owner believed the cart page already had these
controls: the card was there and the effect was not.

## Why neither set can reach the other's row

    ordinary rows   .kbb-cartpage .items .ci:not(.ci-set)     (0,4,0)
    a set's row     .kbb-cartpage .items .ci.ci-set           (0,4,0)

`:not()` takes the specificity of its argument, so the two are exactly as strong
as each other — and **no element can match both**. Against the compiled sheet's
`.kbb-cartpage .ci` (0,2,0) and the squeezed sheet's
`.kbb-cartpage.cpg-squeeze .ci` (0,3,0) both win at every width, inside those
sheets' own media queries included. Two selectors that cannot match the same
element never argue, which is the property the owner asked for.

`ci-set` is put on the line by `resources/views/store/cart-inner.blade.php`,
from the `$kbbSet` it already computes for the fanned stack — so it costs no
query and there is no second definition of what a set is.

## The two defects, and what caused each

**The popup was cut off.** `.kbb-cartpage .items` carried `overflow:hidden` to
cut its own 13px corners, and `.kset-pop` is `position:absolute` off a
`position:relative` `.kset` inside that panel — so the clip reached it. A clip
does not shrink `getBoundingClientRect`, so the evidence is
`document.elementFromPoint` 8px inside the popup's own bottom edge, popup open
on the LAST row of a four-row basket:

| | popup overflows `.items` by | probe hit, before | after |
|---|---|---|---|
| 390px | 69px | `sum` | `kset-pop` |
| 1280px | 65px | `wrap` | `kset-pop` |

On the FIRST row the probe hit the popup before the change too, because a popup
that fits inside the panel was never clipped — which is why this read as
intermittent. The clip is gone and the corners are stated on the first and last
row instead. With the popup CLOSED the page renders 154 differing pixels at 390
and 11 at 1280, every one a single channel step — and two consecutive shots of
the SAME build differ by 150 the same way, so that is the harness's text
antialiasing and not the change.

**The set row's name and stepper were cut off**, and only on the squeezed
layout. `.kbb-cartpage.cpg-squeeze .ci` is `height:var(--cpg-row-h);
overflow:hidden` — right for an ordinary line and the whole point of that
layout. A SET line carries a block that height was never sized for, and `.cmid`
is `justify-content:center`, so the overflow lands **equally at both ends** and
the clip takes exactly the two things at the two ends: the name at the top, the
stepper at the bottom, leaving the circles, the button and the saving in the
middle. Measured with the owner's kind of change (set-row padding 30px):

| | row box | name | stepper |
|---|---|---|---|
| 390px | 446..542 | 405..444 — 41px above the row | 554..583 — 41px below |
| 1280px | 419..515 | 416..435 — 3px clipped | 489..518 — 3px clipped |

At 390 both were entirely outside the painted box. It is also why more padding
did not help: the set screen's padding wins on specificity but never touches
`height`, so it is less room inside the same 96px.

**And the same basket, the same settings, the built stylesheet swapped under
it** — `docs/CR-cart-shots/ab-before-*` and `ab-after-*`, which is the honest
comparison because the table above and the one before it were measured on two
different baskets:

| | set row | the set's name | the set's stepper | an ordinary row |
|---|---|---|---|---|
| 390 before | 96px | 21px above the row's top | 19px below its bottom | 96px |
| 390 after | 157px | nothing clipped | nothing clipped | 96px |
| 1280 before | 96px | 1px clipped | — | 96px |
| 1280 after | 158px | nothing clipped | nothing clipped | 96px |

How much is cut depends on how many lines the name takes, which is why the
earlier table reads 41px and this one 21px — one basket wraps the name onto a
second line and the other does not. What does not depend on anything is that the
row was 96px with more than 96px in it, and that the ordinary rows either side
never move.

`min-height` replaces `height` for `.ci-set` and for nothing else, with
`overflow:visible` beside it. The dense layout keeps its density — every
ordinary row is still exactly 96px — and the one row with an extra block is as
tall as what is in it: 167px at 390 and 163px at 1280 with his padding, 122px at
1280 at shipped defaults.

## No control can put that state back

The height control on **both** screens is a MINIMUM. There is no `height`, no
`overflow`, no `display` and no `visibility` among the 42 new controls, and the
smallest value any type size can take is legible rather than zero.
`CartRowControlsAreSeparateTest` walks every one of them to both ends of its
range and asserts it — the bad state is unreachable rather than discouraged,
which is the shape the set box's overhang control was given for the same reason.

## Applying the package moves nothing

Every default was copied off `resources/css/kbb/kbb-cart.css` declaration by
declaration, laptop and phone. `CartPage::rowCss()` and
`SetAppearance::storefrontCss()` both answer the EMPTY STRING while all of them
are still at it, so a shop that applies this and touches nothing gains no rule
and no byte on any cart page.

**The one exception, stated rather than buried:** a shop on the **Squeezed**
layout with a set in the basket now sees that set's row grow from 96px to as
tall as its contents. That is the bug fix; the 96px version was hiding the name
and the stepper.

## For the integrator

* **No `routes/web.php` edit and no `admin/app.blade.php` edit.** Both screens
  were already mounted. The one new include is in
  `resources/views/store/cart.blade.php`, which this lane owns, and
  `CartRowControlsAreSeparateTest` pins it at exactly one.
* **No new route, so no `clear_caches_*` migration is needed** for routing. The
  new settings are ordinary `settings` rows written through the existing
  admin API.
* **`public/build` was rebuilt and carried.** `npx vite build` is manual here
  and CI does not build assets. Only the cart stylesheet's hash moved —
  `kbb-cart-C_8pHcYp.css` → `kbb-cart-K0T8xTU5.css`, plus `manifest.json`.
  `app-jOqRtoT7.js` is untouched, which is what `BuiltAssetNamesAreStableTest`
  exists to keep true. The package must carry the new file **and** the
  manifest; the old file can be left on the server, where nothing references it.

## Pictures

`docs/CR-cart-shots/`, all at 390 and 1280 unless said otherwise:

| File | What it shows |
|---|---|
| `pop-before-last-*` / `pop-after-last-*` | the popup open on the LAST row, cut off and then not |
| `pop-after-first-*` | the same popup on the FIRST row, which was always fine |
| `setrow-before-clip-*` / `setrow-after-clip-*` | the squeezed set row losing and keeping its name and stepper |
| `ab-before-*` / `ab-after-*` | the same, on ONE basket with only the built stylesheet swapped |
| `rowcontrols-*` | both control sets moved at once: ordinary rows 120px with a 16px name, the set row 150px with a 17px name |
| `en-*` / `ar-*` | the cart in both languages with the popup OPEN on the last row |
| `cartpage-folded-*` | Appearance → Cart page → Product rows · spacing and size |
| `setap-folded-*` / `setap-open-*` | the Set screen's cart section, folded to 4 controls and opened to 13 |
| `setap-select-1440.png` | "where the circles sit", which is the screen's first select |

`document.documentElement.scrollWidth` is 390 at 390 and 1280 at 1280 in every
one of them, in both languages, with the popup open and closed.
