# T6 manual half — the three states, and the components that needed a picture

`docs/rtl-audit.md` §11 is the write-up. This directory is the evidence.

## The three states

`<state>-<viewport>-<surface>.jpg`, where state is one of:

| state | `language_ar_enabled` | `language_rtl_enabled` | URL |
|---|---|---|---|
| `english` | off | off | `/` |
| `arabic-ltr` | **on** | off | `/ar/` |
| `arabic-rtl` | **on** | **on** | `/ar/` |

The middle one is not padding. "Arabic on, RTL off" is a state the owner can
choose from the Translation console — the console warns him about it and does not
prevent it, because `Locale::direction()` deliberately keeps the two switches
apart. It has to not be broken, and the Arabic typeface has to load in it, which
is why the face is gated on the language and not on `Locale::isRtl()`.

Surfaces are the ones the manual half is about: the cart drawer open, the mobile
nav, its sub-menu, the filter column, the shop drawers, and the home, product and
checkout pages at both viewports.

`english-*` is the proof that nothing moved: every one of these is
byte-identical to the same shot taken against the branch point, except for three
noted in §11.9 — one live countdown timer that differs between two captures of
the *same* tree, and two at 3 and 4 pixels with a maximum channel delta of 2 out
of 255. The stronger proof is not a picture at all: 9,146 computed-geometry rows
across 9 pages × 2 viewports, identical.

## The components

`component-<name>-<dir>[-before|-after].jpg` — cropped at deviceScaleFactor 3
from a bench page that loads the real stylesheets, because these are small and a
full-page shot hides them.

| component | what the `-before` shot shows |
|---|---|
| `ribbon` | the corner sale ribbon still across the top-**right** corner of a mirrored card grid |
| `toggle` | the filter switch knob resting at the left, i.e. unmirrored |
| `fillbar` | the free-delivery fill growing leftward while the pulse dot and the comet sit at the far right — the anchored end |
| `select-chevron` | the country select's chevron on the right with no room reserved for it |
| `acct-caret` | **no before/after**: this one was verified and deliberately left alone. The panel moves with its logical inset and the caret tip still points up, because the two borders that make it are symmetric about the vertical axis |
| `mega-hover` | the mega-menu hover panel, both directions. The end state is identical; only the 0.18s wipe ran the wrong way, which a still cannot show — that one was measured mid-flight instead |

## Reproducing

```bash
php -S with a Vite manifest pointing at the SOURCE stylesheets, not the hashes
under public/build — those predate this change and would compare the same bytes
to themselves.
```

Chromium 1194 at `/opt/pw-browsers/chromium-1194/chrome-linux/chrome`,
`browser.newContext({viewport})` — not `page.setViewportSize`, which has produced
wrong-sized shots on this box — `deviceScaleFactor: 1`, `reducedMotion:
'reduce'`, animations and transitions frozen by an injected stylesheet. Panels
are opened by adding the class the site's own JS adds, so a closed drawer and an
open one are both photographable. Two units are put in the bag first so cart and
checkout render real content instead of redirecting.

JPEG q70 rather than PNG, as `docs/rtl-shots/` already chose: 46 full-size PNGs
come to far more than this repo should carry, and JPEG encoding is deterministic
so identical source pixels still give identical files.
