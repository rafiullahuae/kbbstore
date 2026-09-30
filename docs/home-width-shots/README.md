# The home page's panels, widths and banner — photographed

*Lane BG. The owner's three sentences, and what each one measures as.*

> *"on homepage i want to remove the sections backgrounds by default, and if i
> need it for any section, i can put it myself., rest, any section i can make
> full width upto 1920x, give this option and it must be auto adjusted to the
> screen sizes below 1920px width, and by default, make the main images banner
> full width, and remove the corner radius etc."*

Taken in Chromium against `tools/bg-preview.sh`, at **320, 390, 1280, 1440,
1920 and 2200**. Every number below is read out of the document with
`getComputedStyle` and `getBoundingClientRect`; nothing is read off the CSS
source and nothing is claimed.

Reproduce:

```bash
sh tools/bg-preview.sh 8990
# the fixture has no banner pictures, so the homepage falls back to the hero
# and the banner cannot be photographed. Give it three at the shipped size:
php artisan tinker tools/release-330-banner-seed.php
# and point the homepage at the set (the module toggle is already on):
#   module_settings.cards_banner.set = 1
NODE_PATH=$PWD/node_modules SHOT_BASE=http://127.0.0.1:8990 \
  SHOT_LABEL=after node tools/bg-width-shots.cjs
NODE_PATH=$PWD/node_modules SHOT_BASE=http://127.0.0.1:8990 \
  BG_BASE=http://127.0.0.1:8990 node tools/bg-home-admin-shot.cjs
```

## The numbers

`before-measurements.json`, `after-measurements.json`, `after-cap-measurements.json`
and `optin-measurements.json` carry all of it. Read out:

| | 320 | 390 | 1280 | 1440 | 1920 | 2200 |
|---|---|---|---|---|---|---|
| `documentElement.scrollWidth`, before | 320 | 390 | 1280 | 1440 | 1920 | — |
| `documentElement.scrollWidth`, **after** | **320** | **390** | **1280** | **1440** | **1920** | **2200** |
| first section's `.wrap` width, before | 320 | 390 | 1256 | 1416 | 1680 | — |
| first section's `.wrap` width, **after** | **320** | **390** | **1280** | **1440** | **1920** | **1920** |
| banner frame width, before | 296 | 366 | 1203 | 1358 | 1622 | — |
| banner frame width, **after** | **320** | **390** | **1280** | **1440** | **1920** | **1920** |
| banner frame `left`, before | 12 | 12 | 39 | 41 | 149 | — |
| banner frame `left`, **after** | **0** | **0** | **0** | **0** | **0** | **140** (centred) |
| banner `border-radius`, before | 18px | 18px | 18px | 18px | 18px | — |
| banner `border-radius`, **after** | **0px** | **0px** | **0px** | **0px** | **0px** | **0px** |
| banner `box-shadow`, before | set | set | set | set | set | — |
| banner `box-shadow`, **after** | **none** | **none** | **none** | **none** | **none** | **none** |
| panel colour on `.sec > .wrap`, before | transparent | transparent | `rgba(255,255,255,.94)` | same | same | — |
| panel colour on `.sec > .wrap`, **after** | transparent | transparent | **transparent** | **transparent** | **transparent** | **transparent** |
| tinted section's gradient, before | none | none | set | set | set | — |
| tinted section's gradient, **after** | none | none | **none** | **none** | **none** | **none** |
| pictures that actually painted | 3/3 | 3/3 | 2/3 | 2/3 | 2/3 | 2/3 |

**`scrollWidth` equals the viewport at every width, including 1920 and 2200.**
That is the measurement the width option is really about: a full-width section
is the classic way a page grows a horizontal scrollbar, and the rule is written
`width:100%;max-width:1920px` rather than `min(100vw,1920px)` for exactly that
reason — `100vw` counts the desktop scrollbar.

**The cap holds.** At a 2200px viewport the section and the banner are both
**1920** wide and **centred** (`left: 140` = (2200−1920)/2), which is the
`margin-inline:auto` already on `.kbb-home .wrap`.

**The gap above the banner is 8px, not 41.** Measured at 1280 with the side
gutter removed and the block padding still on: `header.bottom` 94,
`.kbbs-vp.top` **135** — 33 of those 41 pixels were the wrap's own
`padding-top:clamp(22px,2.6vw,34px)`. That inset stops a picture reading as
edge to edge exactly as the radius did, so `bleed` takes it off too: **`.top`
is now 102, a gap of 8**, which is the section's own rhythm rather than the
banner's frame.

## The way back, photographed too

`optin-*.png` is the same page with three sections changed on
`Appearance → Homepage`:

| section | changed to | 390 | 1280 | 1920 |
|---|---|---|---|---|
| Newsletter | Background · panel | band on the `<section>` | card on the `.wrap`, `rgba(255,255,255,.94)`, radius 22px, border 1px | same |
| Top brands (tinted) | Background · panel | gradient band on the `<section>` | gradient card on the `.wrap` | same |
| Skin quiz | Width · full | 390 wide, 12px gutter kept | 1280 wide, 25.6px gutter kept | 1920 wide, 28px gutter kept |

The phone draws a `panel` section as a **full-width band on the `<section>`**
and the desktop as a **card on the `.wrap`** — which is what each width has
always drawn for a tinted section, and the reason the measurement script reads
both elements. A reading that only looked at the wrap would report "no
background" on a phone section that has one.

## The control

`admin-homepage-1280.png` and `admin-homepage-390.png`.

**`Appearance → Homepage → <section> → Background`** and
**`Appearance → Homepage → <section> → Section width`.**

Driven rather than posed: 20 rows, **18** carry the two selects, and the two
that do not are **Delivery strip** and **Promo ticker** — the sections drawn
inside the hero's own `<section>`, which have no wrapper for either rule to
reach. The banner row reads `background: off`, `width: bleed`, which is what
the shop draws. `scrollWidth` equals the viewport on the console at both widths.

## What the instrument nearly got wrong

**The tint is not a `background-color`.** It is a `linear-gradient`, so it
lives in `backgroundImage` and a reading of `backgroundColor` reports
`rgba(0,0,0,0)` on a section that is visibly pink. The first run of
`bg-width-shots.cjs` did exactly that and made the opt-in case look broken.
Same class as the two the 2.60.330 run recorded: a measurement that is true for
a reason unrelated to its subject.

**A width off an empty section is not a width.** Every row in the JSON carries
`children`, the count of elements inside the `.wrap`, because a section that
rendered nothing measures a perfectly plausible width and photographs as blank
page. Every section in these runs has at least one.

## One failure in this tree that is not this round's

`FontProbesCannotReportTheFallbackTest > it lets no instrument ask a question
that cannot answer false` is **red on the integrator tip**, before and after
this branch, and it names `tools/release-330-shots.cjs`:

```
release-330-shots.cjs asks document.fonts.check()
```

`document.fonts.check('13px X')` returns **true for a family that does not
exist anywhere**, so it cannot distinguish a font that loaded from one that was
never declared. The guard (`7259402`, "Five probes that could not fail") is an
ANCESTOR of the shots commit (`5c45373`) that introduced the call, so the
release-shots file was written against a rule already in the tree. The remedy
the test itself names is `probeFamily()` from `tools/font-probe.cjs`, which
measures a second ruler in a family that cannot exist.

`tools/release-330-shots.cjs` is the integrator's file and this lane has not
touched it. Reported rather than edited.
