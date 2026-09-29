# The "What is in this set" box — three treatments (Lane SPL)

The owner, 29 September 2026:

> "i want to redesign the products list box on the set product page. want nice
>  light background box type and inside a squeezed products list."

Three proposals, and they are three **objects**, not three shades of one tint —
the two rounds before this one came back with "all three options are exactly
same, what's this?", so the test applied to these was: cover the labels, shrink
them to a thumbnail, and see whether you can still name which is which.

|  | the object | its rhythm |
|---|---|---|
| **1 · Filled tint** | one soft cream panel, no border, no shadow | no row rules at all — white photo chips make the beat |
| **2 · White card** | a white card with a hairline, a soft shadow and a blush header strip | inset hairlines that stop short of the card's edge |
| **3 · Hanging photos** | a blush panel with the photographs hanging over its inline-start edge | no rules; the overhanging chips are the rhythm |

## What these files are

Each `t{n}-*.css` is the block of rules to **append to the end of the `@once`
`<style>` in `resources/views/partials/set-contents-panel.blade.php`** once the
owner names one. They are written as overrides at the *same specificity* as the
rules already in that block, so appended at the end they win by source order and
by nothing else — which is exactly how they are applied in the screenshots
(`tools/spl-box-shots.cjs` appends the file to `document.body`, the last place in
document order, because the panel's own `<style>` is emitted inline in the body
and a `<head>` tag would lose to it).

Nothing in this directory is loaded by the application. The shipped partial is
byte-identical on this branch; `StorefrontEnglishUnchangedTest` is the pin.

## Rules every treatment obeys

* **No physical direction property, no `[dir]` selector.** The row is three grid
  tracks on the inline axis and it stays that way; the overhang in treatment 3 is
  `margin-inline-start`, so it hangs off the right-hand edge on `/ar` from the
  same declaration. `tests/Feature/SetContentsBoxTreatmentsTest.php` reads every
  declaration with `Tests\Support\CssDirection` and fails on a physical one.
* **No script, no `@import`, no remote `url()`.** The page this lands in sizes
  with `calc()` and a media query, and two tests forbid the element-measuring
  APIs by name.
* **The squeeze is the same in all three**, so the owner is choosing the box and
  not the row height. See `docs/SET-BOX-SQUEEZE.md` for the measured table.
* **Legible and tappable is the floor**: the name never goes below 12.5px, the
  photograph never below 30px, and `a.ksl-nm` keeps the padding-block /
  negative-margin-block trick that buys 6px of hit area for zero row height.
