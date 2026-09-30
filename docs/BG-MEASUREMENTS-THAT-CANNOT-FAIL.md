# Two instruments that could only return one answer, and five assertions asked whether they could

*Lane BG, round 4. The audit the integrator asked for after round 3's ruler
turned out to be measuring the system font.*

## 1 · The pattern

Round 3 found a probe that could not distinguish **the thing it measured** from
**the thing that stood in for it**:

```js
font-family: Poppins, system-ui, sans-serif   // the ruler
```

On a page without Poppins this silently measures `system-ui` — and because it is
the same system font on every such page, it reports the *same plausible numbers*
across all of them. It printed 543.28px at weight 400 and 556.77px at 600 for
all eight storefront pages, four of which had no Poppins at all, and three
numbers derived from it reached a docblock and a commit message as fact.

The generalisation is not about fonts: **an instrument whose output looks the
same whether or not the thing exists is not an instrument.** This round looked
for others.

---

## 2 · The audit: one more, and it was worse

Every script under `tools/` and `tests/browser/` was swept. **Exactly one** other
measurement had the shape, and it was the headline answer of the script whose
entire subject is whether fonts arrive.

`tools/perf-fontcheck.cjs` reported:

```js
check: document.fonts.check('13px Poppins'),
```

which reads as "is Poppins available?" and is not that question. An unknown
family needs nothing loaded, so the answer is true. **Measured** in Chromium on
a page declaring zero faces — no stylesheet, no `@font-face`,
`document.fonts.size === 0`:

| call | returns |
|---|---|
| `document.fonts.check('13px Poppins')` | **true** |
| `document.fonts.check('13px Fraunces')` | **true** |
| `document.fonts.check('13px KbbNoSuchFamily12345')` | **true** |
| *the two-ruler probe on that same page* | **436.63 vs 436.63 — nothing rendered** |

So on the precise failure that script was written to catch — Lane PERF's own
story of `php -S` erroring four `@font-face` and the page falling back to the
system face — it still printed `check: true`. **It could only ever print true.**
The honest parts of the script (the per-face `status`, the `RESP` lines, the
`requestfailed` handler) were doing real work, and the one line presented as the
verdict was the one line that could not deliver one.

Nothing else matched. There is exactly one `document.fonts.check` in the
repository and it was that one; no other measuring probe sets a font-family with
a fallback list. `tools/bg-body-measure.cjs` reads computed `font-family`, but it
reports the **declared stack** for cascade work and does not claim to say which
face rendered.

### What was done: the fix is in the instrument

`tools/font-probe.cjs` is new and holds all three mechanics, with the story of
each:

1. **Two rulers.** The named family alone, and a family that cannot exist.
   Equal widths mean the family never rendered. One ruler cannot tell you this
   however it is written.
2. **An explicit `document.fonts.load()` per weight, before measuring.**
   `document.fonts.ready` only covers faces the page's own layout needed; a
   probe for a weight nothing on the page uses is not covered by it. This bit
   twice in one afternoon — `/cart/` and `/reviews/` read as outliers in a table
   where every other page agreed, and the hunt went looking for a cascade bug
   that did not exist. They are simply the two pages with no visible text at
   weight 500.
3. **A per-weight verdict**, not one boolean for the family.

Three scripts now require it: `bg-weight500.cjs`, `perf-fontcheck.cjs` and the
new `bg-sorina-options.cjs`.

**`perf-fontcheck.cjs` can now fail**, which is the whole point:

```
PERF_FAMILY=Poppins   … OK: Poppins rendered at every weight asked for   exit 0
PERF_FAMILY=Fraunces  … FAIL: Fraunces did not render at weight(s) 500, 600
                             -- the ruler matched the control exactly       exit 1
```

`tests/Feature/FontProbesCannotReportTheFallbackTest.php` guards it: no script
may call `document.fonts.check(`, the module must keep both mechanics, and the
three instruments must use it.

> **The guard strips comments before it looks**, and that is load-bearing rather
> than tidy. `KbbNoSuchFamily` and `document.fonts.load(` each occur **once in
> code and once in a comment** in `font-probe.cjs` — the comments explain why
> they are there. A scan that reads prose finds the explanation and passes on a
> file that has deleted the thing. Lane MY's SQL-dialect guard was bitten by
> exactly this; `docs/SUITE-DIALECT-NEEDLES.md` records it.

---

## 3 · The same question asked of assertions

Lane PLC measured the whole suite for needles that are present for a reason
unrelated to the code under test: 494 ambiguous sites across 151 files.
`tools/plc-needle-scan.sh` reproduces it. Five of those sites are in files this
lane owns.

**Each was settled the cheap way: blank the thing under test, and see whether
the assertion notices.**

| file : line | needle | occurs | blanking the thing under test | verdict |
|---|---|---|---|---|
| `PageWashTest:111` | `rgb(248,245,249)` | ×6 | repointed treatment b at another palette → **red** | honest |
| `PageWashTest:204` | `rgb(255,247,238)` | ×6 | changed the shipped palette's colours → **red** | honest |
| `PageWashTest:230` | `rgb(255,247,238)` | ×6 | *(same mutation)* → **red** | honest |
| `PageWashScreenTest:175` | `open = 'preview'` | ×2 | made the screen open on the colour tab → **GREEN** | **hiding something** |
| `PageWashScreenTest:184` | `previewParam` | ×3 | deleted the endpoint read → **GREEN** | **hiding something** |

**Two of five were actually hiding something.** The three colour needles are
flagged correctly by the scan's rule and are not defects: a palette colour
legitimately appears at six keyframe stops inside one treatment's CSS, and the
colour is unique to its palette, so the assertion does distinguish what it
names.

### The two that were not

**`open = 'preview'`** appears twice in `page-wash-screen.blade.php`: once as
the initialiser — which *is* the screen's opening tab, the thing under test —
and once at the bottom of `applyTabs()` as the fallback for an `open` that is no
longer in the tab list. Changing the initialiser to `open = 'colour'`, so the
screen opens on a tab of sliders that write to the shop — the exact thing the
case forbids, and the reason the owner asked to see the preview first — left the
file at **10 passed**. The fallback satisfied the needle.

It now reads the **first** assignment to `open` in the file and requires it to
be `'preview'`. The fallback cannot stand in for it, and a lane that adds an
earlier assignment has genuinely changed what the screen opens on, so matching
that one is correct rather than a loophole.

**`previewParam`** appears three times: the declaration, the assignment from the
endpoint, and the use. Only the middle one is the claim — the comment beside it
says the frames are built from the endpoint's own list "not from a hard-coded
set of paths that could drift from the shop's routes". Deleting
`previewParam = body.preview_param || 'kbbwash'` left the file at **10 passed**.

It now names that assignment.

**Both re-run after the fix**: the colour-tab mutation and the deleted-endpoint
mutation each go red, naming what broke.

### And the same discipline applied to this round's own new guard

`CONTROL_FAMILY` occurs three times in `font-probe.cjs` — the const, the export
and the use — so asserting it bare would have survived somebody replacing the
declaration's **value** with a family that does exist, which is the failure that
matters. The assertion names the declaration, and pointing the control at
`DejaVu Serif` goes red.

---

## 4 · What the class looks like, for the next lane

Three of this round's four discoveries were the same mistake wearing different
clothes:

- a **ruler** that reports the fallback's width as the font's;
- a **call** that returns true for a family that does not exist;
- an **assertion** whose needle is satisfied by a second occurrence that is not
  the thing under test;
- and, found while building the comparison in
  `docs/BG-SORINA-REVIEW-TYPE.md`, a **`scrollWidth`** on a block element
  reporting the container's width as the text's — four different typefaces all
  measured 868px and the swing came out as 0.0%, which looked like a finding
  ("the serif makes no difference") and was the container standing in for the
  text. A Range around the contents gives 205.92px .. 264.69px.

The test that separates all four is the same one: **make the thing under test
untrue, and see whether the measurement changes.** If it does not, the
measurement was never of that thing.
