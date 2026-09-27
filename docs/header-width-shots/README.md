# The header width, and the slider that could not win

> "along with the site width setting, make the header width setting too, or it
> should be auto set as per the site width, we have this option, but header
> remains still same width and nothing adjusting auto as per the set width of
> the site."

Two halves, and they had different answers.

## Half one: the header DOES follow the site width, and always did since 2.60.284

Measured in Chromium on `/shop/`, site width saved at **1200px**,
`header_follows` on:

| viewport | `--site-max` | `--hd-max` on `<header>` | `header .wrap` | page container |
|---|---|---|---|---|
| 1280 | 1200px | 1200px | **1200** | **1200** |
| 1680 | 1200px | 1200px | **1200** | **1200** |
| 1920 | 1200px | 1200px | **1200** | **1200** |

Identical at every width. Lane H1 fixed this and it shipped in **2.60.284**,
with the rebuilt `public/build/assets/kbb-7etO8kwI.css` and the manifest in the
same package — so a shop that applied .284 has it.

## Half two: the control he was actually dragging did nothing, and said nothing

`Appearance → Header → Bar → Content width` is read by
`HeaderSettings::maxWidthCss()` **only when `header_follows` is off**, and that
switch ships **on**. Its help text was the **empty string**.

So the path was: open the Header screen, find a slider labelled "Content width",
drag it, press Save, get **"Saved"**, and watch nothing move — with nothing on
the screen and nothing in the help to send him to the switch overruling it.

A control that cannot win is worse than a missing one. The missing control sends
you looking; this one tells you you have already done it.

### After

| | 390 | 1280 |
|---|---|---|
| row found | yes | yes |
| `.is-inert` | **true** | **true** |
| slider `disabled` | **true** | **true** |
| amber note shown | yes | yes |
| `scrollWidth − clientWidth` | 0 | 0 |
| page errors | none | none |

The slider is greyed and cannot be dragged, its help says which switch governs
it and where that switch lives, and an amber line under it says what the shop is
doing **right now**:

> Not in use — the header is following the site width. Turn that off on
> Appearance → Site layout → Page width to set the header's own width here.

**Faded rather than hidden**, deliberately: he came looking for this slider, and
it is still the right control once the other switch is off. Removing it would
leave him hunting.

`inert` is computed per request by `HeaderApiController`, not stored in the
schema, because whether a control is doing anything is a fact about the shop
right now — it flips the moment Site layout is saved. `HeaderWidthControlIsHonestTest`
pins both directions and four mutations are red, including "inert is permanent",
which would otherwise look like a fix and be a second dead control.

## Files

`header-content-width-{390,1280}.png` and `header-measurements.json`.
