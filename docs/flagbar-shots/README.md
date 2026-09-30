# The flag bar loses its words on desktop — photographed

*Lane BG.*

> *"UAE's Authentic K-Beauty Store*
> *remove this from the desktop version."*

He quoted the **line**, not the bar. So on desktop the strip keeps its two
flags and loses the words; on phones **nothing changes**. `fb_desktop` is
untouched — turning that off would have taken the flags with it, which is the
other reading of the sentence.

Taken in Chromium against `tools/bg-preview.sh`, on the home page (where the
strip sits under the banner) and on `/shop/` (where it sits above the header),
at 390, 1280 and 1920.

```bash
sh tools/bg-preview.sh 8992
NODE_PATH=$PWD/node_modules SHOT_BASE=http://127.0.0.1:8992 \
  SHOT_LABEL=after node tools/bg-flagbar-shots.cjs
# the BEFORE is the same tree with the new control turned ON, which is what the
# shop drew until this release — so the pictures also prove the way back works
```

## The numbers

Identical on `/` and on `/shop/`, so one table serves both.

| | 390 | 1280 | 1920 |
|---|---|---|---|
| bar height, before | 30px | 30px | 30px |
| bar height, **after** | **30px** | **30px** | **30px** |
| `.kfb-tx` display, before | block | block | block |
| `.kfb-tx` display, **after** | **block** | **none** | **none** |
| flag x positions, before | 22, 347 | 22, 1237 | 142, 1757 |
| flag x positions, **after** | **22, 347** | **614, 645** | **934, 965** |
| gap between the flags, before | 304 | 1194 | 1594 |
| gap between the flags, **after** | **304** | **10** | **10** |
| `documentElement.scrollWidth`, before | 390 | 1280 | 1920 |
| `documentElement.scrollWidth`, **after** | **390** | **1280** | **1920** |

**The bar height does not move**, which is the number that matters most: it is
reserved in the stylesheet with `min-height:var(--kfb-h,30px)` so the header
below it cannot jump, and taking the words out had to leave that alone.
`scrollWidth` equals the viewport at all three widths, before and after.

**390 is byte-identical, not "looks the same".**

```
$ cmp docs/flagbar-shots/before-home-bar-390.png \
      docs/flagbar-shots/after-home-bar-390.png   # no output
$ cmp docs/flagbar-shots/before-shop-bar-390.png \
      docs/flagbar-shots/after-shop-bar-390.png   # no output
```

Two identical PNGs of the strip itself, on both pages. That is the proof the
change is scoped to desktop; the test asserts the same thing from the other
side by requiring the rule to be inside `@media (min-width:901px)`.

## The flags move, and the choice was made from a picture

`.kfb-tx` carries `margin-inline:auto`, and that auto margin was the **only**
thing holding the two flags apart — `.kfb-in` sets no justification, so it
falls back to `flex-start`. Take the words out and the flags go with them.
Three layouts, measured at 1920:

| | flag x | what it looks like | file |
|---|---|---|---|
| `flex-start` (what you get by doing nothing) | **142, 173** | both flags bunched in the top-left corner of a 1920px strip, 1585px of empty band after them | `alt-flagstart-1920.png` |
| `space-between` | 142, 1757 | one flag at each far edge, a 30px pink band empty between them | `alt-spacebetween-1920.png` |
| **`center`** — shipped | **934, 965** | the two countries together in the middle | `alt-centred-1920.png` |

**Being straight about it: none of the three is handsome at 1920.** A 30px
strip carrying two 21px flags and nothing else is a thin pink band with very
little in it, and that is a consequence of the instruction rather than of the
implementation — the words were most of what the bar had. `center` is the best
of the three because it reads as a deliberate pair rather than as something
that failed to load, and `flex-start` (the do-nothing option) is plainly wrong.

**If it reads as too empty, the honest fixes are his to choose**, and both are
one control away:

* `Appearance → Header → Flag bar → Show the wording on desktop` puts the line
  back, which is the state these BEFORE pictures show; or
* `Appearance → Header → Flag bar → Show it on desktop` takes the whole strip
  off desktop, which is the other reading of his sentence — the integrator has
  already told him that switch exists.

Nothing here guesses on his behalf: what shipped is exactly what he wrote.

## The control

**`Appearance → Header → Flag bar → Show the wording on desktop`**, directly
under **Wording**, shipped **off**.

It is a `bool` in `HeaderSettings::SCHEMA`, so the console drew it with no
edit — `hdField()` switches on `type` and names no setting.

## Why it is a class and not a server-side removal

Worth reading once, because the obvious implementation is wrong.

The strip is **one element in one document**. `flagBarClass()` puts both
`kfb-m` and `kfb-d` on it and two media queries decide which width sees it —
this shop serves the same bytes to a phone and a desktop, deliberately, so a
cached page stays correct on every device and so nothing has to sniff the user
agent.

So the words **cannot** be left out of the markup on desktop without also
leaving them out on phones, which is the requirement inverted. The cost is
named rather than hidden: **the line stays in the HTML source**, where a
crawler can read it. What it does not stay in is the **accessibility tree** —
`display:none` removes an element from it outright, so a desktop screen-reader
user hears the two flags and no stray sentence.

Emptying `fb_text` was the other tempting shortcut and is the same trap: that
string is shared by both widths, so it would have stripped the line from phones
too.
