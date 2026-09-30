# The general sweep: probes that answer the same whether or not the thing is true

*Lane BG, round 5. The pattern generalised past fonts, and what it found.*

## The question asked of every probe

> Is there a state of the world in which this returns the same answer whether or
> not the thing under test is true?

Four instances were already known, all found by this lane: a width ruler set in
`Poppins, system-ui, sans-serif` reporting the system font's metrics as
Poppins'; `document.fonts.check()` answering true for families that do not
exist; the `display:swap` race measuring a weight the page had not fetched; and
`scrollWidth` on a block element reporting the container's width as the text's.

`tools/` (250 scripts) and `tests/browser/` (43) were swept for the general
shape.

---

## 1 · Clearly broken, fixed: absence collapsing into a healthy number

**`tools/ig-shots.cjs`**, the Instagram admin screen's measurement tool:

```js
contentOverflow: (document.querySelector('#content')?.scrollWidth ?? 0)
               - (document.querySelector('#content')?.clientWidth ?? 0),
```

Both halves fall back to `0` when `#content` is absent, so the subtraction is
`0 - 0`. **Measured in Chromium on four pages:**

| the page | `contentOverflow` |
|---|---|
| a screen that fits | **0** |
| a screen that OVERFLOWS | 600 |
| a screen that rendered nothing at all | **0** ← identical to "it fits" |
| a screen whose selector was renamed | **0** ← and this one *really did* overflow, by 600 |

So the instrument's failure mode was to report the **all-clear on the exact
defect it exists to catch**, and renaming the selector would have turned every
future run green without anybody touching the screen.

What makes it a clean case rather than a judgement call: **its own neighbours in
the same object already did it right.** `box()`, `px()`, `trackClass`,
`columns`, `gap` and `radius` all return `null` for an absent element; that one
line was the only one that did not.

**Fixed** — it now reports `contentPresent` and a `null` overflow when there is
nothing to measure, because `null` is not a width and cannot be read as a good
one. After the fix, the same four pages read `present=true/0`, `true/600`,
`false/null`, `false/null`.

`tests/Feature/MeasurementsCannotReportAbsenceAsZeroTest.php` guards it. It bans
**the pair** — two optional reads of the same quantity, both defaulted to `0`,
subtracted — and deliberately not `?? 0` in general, which is correct wherever
zero is the true answer for absence (`washBytes` is 0 precisely because the wash
emits no element when it has no bytes).

> `tools/ig-shots.cjs` is Lane IG's file. The change is one expression in a
> diagnostic tool and does not touch the screen it measures, but it is named
> here rather than assumed.

**This was the only instance of the arithmetic collapse in the repository** —
one site out of 293 scripts.

---

## 2 · Clearly broken, fixed: a verdict that could be redirected away

`tools/perf-fontcheck.cjs` gained the ability to fail in round 4. Round 5 asked
who would notice.

**Nothing runs it.** No CI step, no shell script, no npm script, no other tool —
CI runs `composer install`, `php -l` and pest, and no node tools at all. So the
exit code reaches a human and nothing else.

That makes *how* it fails more important, not less, because the first caller
anybody writes will be a one-liner and a one-liner is where an answer gets lost:

- The FAIL line printed to **stdout**, beside the JSON, so
  `node tools/perf-fontcheck.cjs URL > /dev/null` threw the verdict away and
  kept only an exit code nobody was checking. **Now on stderr** — measured: with
  stdout discarded the FAIL line still arrives and the exit is still 1.
- **It cannot silently pass on a measurement it never took.** Measured: pointed
  at an unreachable URL, `page.goto` rejects and node exits 1 rather than
  printing OK over an empty result.

Both properties are now pinned in
`tests/Feature/FontProbesCannotReportTheFallbackTest.php`, because both can be
removed by a refactor that looks like tidying.

---

## 3 · Arguable, named: two of seven pages cannot be pixel-compared at all

Round 4 found the skin quiz differing by 38,315 pixels between **two captures of
the unchanged page**. Round 5 asked how general that is. Two captures of each
storefront page, nothing changed between them:

| page | differing pixels | verdict |
|---|---|---|
| **home** | **2,936** of 1,152,000 (max delta 190) | **not comparable** |
| /shop/ | 0 | comparable |
| a product | 0 | comparable |
| the cart | 0 | comparable |
| the journal | 0 | comparable |
| **the skin quiz** | **53,253** (max delta 152) | **not comparable** |
| the review wall | 0 | comparable |

**The causes differ, and neither is visible to the usual waits.** The home page
has live infinite animations (`miTiles` ×4, `sl` ×1); the quiz has none running
at all by then — its instability is *entrance* animations (`rise`, `pop`) still
in flight. `waitUntil: 'load'`, `networkidle` and `document.fonts.ready` all
return before either has settled.

11 of 145 scripts freeze animation before capturing. That is **not** called a
defect here: a screenshot taken for a human to look at is fine on an animated
page. It only bites when two captures are **compared**, and the cheap protection
is not to disable animation — which changes the page — but to **capture twice
before believing a diff.**

### And it corrects a number this lane published last round

Round 4 reported the home page's before/after as **2,015 differing pixels
(0.17%)** and attributed it to Poppins 500 rendering as medium.

**That attribution is not supportable.** The home page's own frame-to-frame
variance is 2,936 pixels — *larger than the difference that was attributed to
the change*. The signal cannot be separated from the noise, and the honest
statement is that the home page's pixel delta says nothing either way.

What does stand, because neither is a pixel comparison:

- body height **8,587 → 8,587** at 1280 and **10,513 → 10,522** at 390 — a
  layout measurement, reproducible, and the +9px is the real consequence;
- the census of **27 visible elements** at weight 500 on that page;
- the ruler: 497.20px against 505.00px, on a hidden probe that no animation
  touches.

The cart remains the clean control: **0 differing pixels**, and it has two
`font-weight:500` elements, both invisible.

---

## 4 · Arguable, named: absence reported as a plausible value

Not defects, but worth knowing where they are:

- **`tools/ug3-shots.cjs:108`** — `Number(content-length || 0)`, summed per
  file. A response served without a `Content-Length` (chunked) contributes **0
  bytes** to a byte total presented as fact. Correct for most static responses;
  silently low for any that are not.
- **The `?? null` reporters** (`bg-admin-shots.cjs`, `ig-shots.cjs`,
  `im-admin-shots.cjs` and others) print `null` for an absent element. These are
  **honest** — `null` is visibly not a measurement — and are listed only so the
  next sweep does not have to rediscover that they were considered.

## 5 · Checked and found sound

Worth recording, because "swept and found nothing" is only useful if it says
where it looked:

- **`tests/browser/admin-overflow.mjs`** (gates 4 pest files) already carries a
  `PROBE` mode that injects a deliberately over-wide div to prove it can still
  see an overflow at all — a positive control, and the discipline this whole
  round is about.
- **`tests/Feature/CheckoutHiddenRowsTest.php`** retries once and then **throws**
  if the probe's JSON does not come back with `ok`, and separately asserts its
  own fixture really produced the hidden rows, "so a page that stopped rendering
  the gift row would otherwise pass this test for the wrong reason".
- **`SiteLayoutColumnArithmeticTest`**'s monotonic loop compares counts that
  could both be zero, but two exact assertions above it (`toBe(… + 1)`) fail
  first if the arithmetic ever returns nothing.
- Reads of inherited computed properties (`color`, `fontSize`) in the shot tools
  are guarded with `? … : null` and report a specific element's real value,
  which is the right answer to the question they ask.

---

---

## 6 · The same question asked of the view and stylesheet layer

Lane PLC's `tools/plc-needle-scan.sh`, re-run on this tree with
`KBB_WP_DB=kbb_wp_bg` (green: 22 skipped, 8,1xx passed, **7,802 rows**, 468
ambiguous sites, 151 files).

The report groups by *test* file, and the rows record the haystack only as a
**length**. That length is a usable fingerprint: a `file_get_contents` haystack
is exactly the file's byte size, so matching it against the sizes of everything
under `resources/views/partials/` and `resources/css/` identifies the sites
whose subject is a partial or a stylesheet. **25 of them.**

Each was settled the cheap way — blank the thing under test, see whether the
assertion notices. **Three were hiding something.**

| site | needle | × | blanking the thing under test | verdict |
|---|---|---|---|---|
| `CheckoutPlacingOverlayTest:876` | `  }, true);` | 3 | demoted the `[data-place]` listener to bubble phase → **29 passed** | **hiding** |
| `FlagBarTest:436` | `min-height:var(--kfb-h,30px)` | 2 | ran the test's **own** written mutation → **18 passed** | **hiding** |
| `CheckoutScreenFieldTypesTest:253` | `.logo{font-size:18px;flex:1;text-align:center}` | 2 | deleted the bare rule in the media query → **16 passed** | **hiding** |
| the other 22 | — | 2–38 | red, or the claim is genuinely "it is present" | honest |

**Each fix was verified twice**: red on the defect, and **green on a control** —
demoting a *different* capture listener, dropping the *media-query* copy of
`min-height`, deleting the `.kbb-home` copy of the `.logo` rule. A needle that
goes red on everything is not narrower, it is broken differently.

### The three

**`  }, true);` — capture phase, and it matters for money.** That string occurs
three times in `placing-overlay.blade.php`: the Escape keydown listener, the
`[data-place]` click listener, and one below it. The assertion ran against the
whole file, so any of them satisfied it. The comment above that listener records
that **`checkout.js` answers the same selector with a delegated bubble
listener**, and capture is what makes the overlay run first and stop it.
Demoted, both run and the old form post survives the overlay — the double-submit
the file exists to prevent. The two assertions immediately above it had already
cut `$handler` out of the source for exactly this reason; this one had dropped
back to the whole file.

**`min-height:var(--kfb-h,30px)` — the test's own mutation note was false.** The
docblock says "MUTATION: drop `min-height` from `.kfb` and this is red". Dropped
exactly that, and the file stayed at 18 passed: the declaration is repeated in
the `@media` block below, and that copy satisfied the needle. The rule is now
cut out of the stylesheet first and the declaration looked for inside it, so the
note describes what happens again.

**`.logo{font-size:18px;flex:1;text-align:center}` — satisfied by a selector
that cannot match.** Two occurrences in `kbb.css`: the bare `.logo` inside
`@media(max-width:900px)`, which the case is explicitly about ("It is the SITE
header's mobile layout"), and `.kbb-home .logo{…}`, whose selector **never
matches on the checkout**. Deleting the one the test means left it green on the
strength of one that is irrelevant to the claim.

> That case's own comment states this round's lesson independently, and was
> written before it: *"A `getBoundingClientRect()` reports 20 and calls it
> aligned; a Range over the contents reports 47.2 and does not."* Same
> discovery, same week, from the opposite direction — which is the argument for
> writing the reason down next to the assertion.

### What the honest 22 look like

Worth recording, because the useful half of a sweep is what it cleared:

- **negative assertions carry the weight** — `SlimFooterTest:653` pairs its
  `toContain` with `not->toContain` of the form it replaced, and a negative
  assertion on a green suite has a count of exactly 0;
- **regexes scoped to one rule** — `BrandEditorAndPageBannerTest:541` matches
  `/\.kbb-banner--tint \.kbb-banner__inner\{[^}]*aspect-ratio/`, which cannot
  drift to another rule;
- **explicit duplication guards** — `CheckoutPageSpacingTest:534` asserts each
  rule is present *and* "not in two places, which is how they would drift apart
  again";
- **needles derived from the constant they couple to** —
  `CheckoutPageSpacingTest:219` builds its needle from
  `CheckoutPage::MOBILE_MAX`, so the stylesheet and the service cannot disagree;
- and several where the claim really is just "this class is in the file that
  owns it", for which a count of 38 is not ambiguity.

### A note on the scanner this lane wrote first

Before using the scan output, this lane wrote a PHP scanner that paired every
needle in a test with every resource file that test reads. It reported **50**
ambiguous sites — and it could not tell *"asserted against this file"* from
*"mentioned in the same test"*, so `EmailBrandingTest` appeared to assert
`display:flex` against `kbb.css`. It is the round's own pattern one more time,
in the instrument built to find the pattern. The haystack-length fingerprint
above replaced it, and 25 is the number that survives verification.

---

## The shape, for the next lane

Five instances now, and the same test separates all of them:

> **Make the thing under test untrue, and see whether the measurement changes.**
> If it does not, the measurement was never of that thing.

The forms it has taken here: a ruler with a fallback list; a call that returns
true for what does not exist; a geometry read off the wrong node; two absent
values subtracted into a healthy zero; and a comparison against a page that will
not hold still.
