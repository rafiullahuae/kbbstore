# 2.60.330, photographed

The five things this release ships are all things the owner asked for, so all
five get a picture. Taken on the MERGED tree at `63b7555`, in Chromium, at 1280,
390 and — where the grid was worst — 320.

Reproduce:

```bash
sh tools/bg-preview.sh 8971
# the fixture has no banner pictures, so the homepage falls back to the hero and
# the new banner cannot be photographed. Give it three at the shipped size:
php artisan tinker tools/release-330-banner-seed.php
NODE_PATH=$PWD/node_modules SHOT_BASE=http://127.0.0.1:8971 node tools/release-330-shots.cjs
```

## What the pictures show, and the numbers under them

`measurements.json` carries all of it. Read out:

| | 1280 | 390 | 320 |
|---|---|---|---|
| typeface resolved | Outfit | Outfit | Outfit |
| Outfit actually rendered, all 4 weights | true | true | true |
| background gradient layers | 1 | 1 | 1 |
| background layer `position` | fixed | fixed | fixed |
| shift cycle | 300s | 300s | 300s |
| a drawing in the background | **false** | **false** | **false** |
| distinct card heights, `/shop/` | **1** (417) | **1** (357) | **1** (322) |
| distinct card heights, home | **1** (412) | **1** (359) | — |
| `documentElement.scrollWidth` | 1280 | 390 | 320 |

One height per page at every width, including 320, on a real catalogue of 24
products with names of every length. That is the claim the previous round could
not make: it had three heights on desktop, five on a phone and seven at 320.

## Two things the instrument got wrong first, recorded because they will recur

**The background is not on `body`.** It paints on three FIXED PSEUDO-ELEMENT
layers — `html::before` and `body::before`/`::after` — with `body` itself set to
`background-image:none`. The first version of this script read
`getComputedStyle(document.body).backgroundImage` and reported **0 layers, no
gradient**, which made a working feature look broken and nearly had the release
held. Read the layers.

**An `<img>` element is not a picture.** The preview boots with no `APP_URL`, so
Laravel's `asset()` builds `http://localhost/...` for every upload and the
browser asks a host that is not the preview server. The first run counted three
banner images and photographed three broken-image icons. `naturalWidth > 0` is
the question that cannot be answered by a tag existing; the script now routes
those requests at the preview and counts what actually painted.

**And the font measurement was made with a question that cannot answer no.**
The first version asked `document.fonts.check('400 14px Outfit')` and reported
**"Outfit loaded: true"** as a fact. That call returns `true` for a family that
does not exist anywhere — measured on this very page, `document.fonts.check(
'400 14px NoSuchFaceZZQ')` answers **true**. So the reading was worthless, and
`FontProbesCannotReportTheFallbackTest` failed the release by name over it,
which is exactly its job.

Re-measured with `probeFamily()` from `tools/font-probe.cjs`, which measures a
second ruler in a family that cannot exist and calls the font rendered only when
the two widths differ:

| asked for | rendered | widths at 400 / 700 |
|---|---|---|
| **Outfit** | **true** | 456.55 / 472.20 |
| Poppins | **false** | 436.63 / 463.36 |
| a family that cannot exist | **false** | 436.63 / 463.36 |

**The conclusion held — Outfit really is rendering at all four weights — but the
evidence for it did not**, and the two are not the same thing. Poppins answering
`false` is the independent check that the typeface swap is complete rather than
layered.

All three are the "instrument lying" class CLAUDE.md names: a measurement that
is true for a reason unrelated to its subject.
