# Lane briefs — the current round

**Three lanes at a time, and the releases are batched.** The owner set that
cadence deliberately after a stretch at five; `CLAUDE.md` carries the reasoning
and the numbers. Every brief below assumes `CLAUDE.md` in full and
**`## What every lane owes, every time`** in particular: nothing that already
works may change, every patch arrives with a picture at 390 and 1280, every
control names where it sits in the admin, no N+1s and no JavaScript that
measures layout, secure by construction, every fix ships with the test that goes
red without it, and take the whole task.

> ▲ **This file was two days and one whole round out of date on 30 September.**
> It described Lanes A–G from a round that had already shipped, and `CLAUDE.md`
> points a lane here for its assignment — so a lane reading it got somebody
> else's brief. The previous round's briefs are in git history
> (`e97be97` and before) and are not reproduced, because a brief for a lane that
> no longer exists is exactly what made this file misleading. **The integrator
> owns this file. A lane that has landed is deleted from it.**

---

## Where the branch stands

| | |
|---|---|
| Integration branch | `claude/kind-mayer-rpqesv` |
| Built and waiting, **in this order** | **2.60.325** (thirteen lanes) → **2.60.326** (five fixes, no default moved) → **2.60.327** (one file, fixing a defect .326 shipped) |
| Next package | Not blocked. The block that stood at .325 — nine navigated admin endpoints landing on a bare 404 — was cleared in .326 |
| Lanes running | **SEC**, **PLC**, **BG** |

### The standing task all three lanes share

Lane PLC measured the whole suite for assertions that are **true for the wrong
reason** — a needle present because something unrelated on the page renders it.
**6,958** `toContain` assertions instrumented at run time; **500** sites whose
needle is prose occurring 2+ times in its own haystack; **157** of those reading
like a sentence a shopper is shown; **151 files**.

It had to be measured at run time, and that is the interesting part: the needle
is fine, the haystack is fine, and the fault lives only in the relationship
between them while the test runs. No static scan can see it.

**And the answer to "how many were actually hiding something" is now known for
the first 140: one hundred and fourteen.** Not estimated — the scan records
WHERE EACH COPY SITS (the innermost open tag, or `@tag[attr]` when the copy is
inside an attribute value that has not closed yet), so the blanking test is
mechanical: same element means blanking it removes every copy and the assertion
goes red; different elements means the other copy stands and the assertion stays
green. The 24 that are not holes are lists with two rows in them, doing their
job.

Measured end to end rather than left as a proxy. Blank the product name out of
every card on the shop and `BuildMyRoutineTest` was **21 passed, 0 failed**; with
the repaired needles, **5 red**. Stop emitting `<meta name="description">`
entirely and `AdminProductWritePathTest` was **28 passed**; repaired, **1 red**.
Two shop-wide defects an owner would notice in a minute, and the suite could not
see either.

**Two tools, and the second is the one that makes this tractable:**
`tools/plc-needle-scan.sh` produces the list; **`tools/plc-needle-holes.php`**
runs the blanking test and groups candidates by context-pair, which turned 114
decisions into about 20. Adjudicate per pair, not per site.

Current figures on this tree: **496 ambiguous / 140 sentences / 114 holes**, of
which 26 are repaired and 88 remain — 70 of them singletons in other lanes'
files. **Every lane settles the rows in the files it owns.** Change the
**needle**, never what is asserted; a site that can only be fixed by changing
what is asserted gets named and left. Where a repeat is legitimate, record it in
`KBB_MULTI_RENDERED` with the reason. Report how many of yours were actually
hiding something — even if the answer is none, that is the number that says
whether this was worth doing.

Shapes already found, to know what you are looking for: an assertion satisfied by
the cart **drawer** rather than the cart line; one satisfied by an `aria-label`
rather than the visible text; one satisfied by **a CSS comment** that ships the
words to the page; a product name satisfied by the add-to-basket link's
`data-name`; and a `<title>` satisfied by **JSON-LD carrying the same words on
the same page**.

▲ **Run the scan with the database it derives, not one you set by hand.** Its
first version hardcoded `KBB_WP_DB=kbb_wp_plc`, and another lane ran it within
the hour — a lane that had not set the variable itself would have driven Lane
PLC's harness database from its own worktree. It derives from the worktree now.
This is the `KBB_WP_DB` landmine arriving through a shared tool rather than a
shared shell.

## Reading a brief

A brief says what to build and what has already been measured, never how to
write it. Where it names a number, that number was measured and the brief says
where; where it names a file and a line, that line was read. A lane that finds
the brief wrong says so and is right to — three briefs in this project have been
corrected by the lane that received them, and each correction is recorded in the
merge commit in the lane's own words rather than paraphrased.

---

## Lane SEC — the Instagram pop-up, and a cache that leaks between runs

**Owns:** `app/Support/GuestRedirect.php`, `app/Support/ExportProbe.php`,
`app/Services/SetStockReconciler.php`, `app/Services/Payments/StockClaim.php`,
`resources/views/admin/partials/instagram-screen.blade.php`,
`tests/Feature/AdminPathNeverLeaksTest.php`,
`tests/Feature/DownloadNavigationGateTest.php`,
`tests/Feature/ServerBuiltAdminUrlsTest.php`, `docs/SEC-*`, `docs/sec-shots/`.

**1 · The Instagram pop-up's console half.** Its server half shipped; the console
half was specified and not written, because the partial belonged to another lane
and no such lane exists. Two things make it more than four lines:
`kbbTellIfDownloadRefused` is not in that partial's scope, and its deliberate
blocked-pop-up fallback follows the anchor's `href` **in this tab**, which is a
second navigation needing its own answer. `DownloadNavigationGateTest`'s pinned
set must be updated to match — that is now the thing that catches a miss.

**2 · `AdminPathService` memoises into `storage/framework/cache/data` inside the
worktree**, shared with every pest run in it. Pinning `KBB_ADMIN_PATH` in one
preview script was a workaround. Candidate answers, none obviously best: a
run-specific cache key, not writing the memo in the testing environment, or
`tests/bootstrap.php` giving each run its own cache path the way it already gives
each run its own SQLite file. Check whether the same fix covers `Setting::map()`'s
process-level static, which CLAUDE.md names as a trap in tests and queue workers,
or whether they are separate problems.

**3 · The needle rows in the files above.**

---

## Lane PLC — 157 shopper-facing sentences, and a shape worth hunting

**Owns:** `app/Services/Checkout/PlacementState.php`,
`app/Http/Controllers/Store/CheckoutReturnController.php`,
`routes/checkout-return.php`, `resources/views/partials/checkout/*`,
`tests/Feature/CheckoutPlacingOverlayTest.php`,
`tests/Feature/CheckoutRestoreBasketTest.php`,
`tests/Feature/NeedlesNameOneThingTest.php`, `tools/plc-needle-*`,
`docs/PLC-overlay-shots/`.

**1 · Work the 157 down.** Leaving the 492 sites outside this lane's files to
their owners was right for one round; it is not a plan, because those lanes are
working on other things. Take the shopper-facing ones — a duplicated `data-x`
attribute matters much less than a checkout sentence that is asserted and never
drawn. Change the **needle**, never the behaviour being asserted; a site that
looks like it asserts the wrong thing entirely gets named, not rewritten.

**2 · The same question one layer out.** `toContain` is one of several ways to
make this mistake. Check `assertSee`, `assertStringContainsString` and `toMatch`
for the same class of false green, and extend the existing scan rather than
writing a second one. If the suite barely uses them, one sentence closes it.

**3 · The discarded-return shape.** `restore()` assigned `moveTo()`'s answer to a
variable and never read it, with an unguarded write underneath — a guarded write
whose guard's answer is thrown away. CLAUDE.md's `UpdateRunner::recordManifest()`
landmine is the same family. Sweep the checkout and order paths for every call
whose return says *whether the write happened*, and whether the caller reads it.
Fix what is in the checkout path; name the rest.

---

## Lane BG — the review block, and the instrument generalised

**Owns:** `app/Services/PageWash.php`, `app/Support/BrandAccent.php`,
`app/Support/WebFonts.php`, `resources/views/partials/page-wash-css.blade.php`,
`resources/views/partials/shop-appearance-css.blade.php`,
`resources/css/kbb/sorina-reviews.css`, `tests/Feature/OnePageBackgroundTest.php`,
`tests/Feature/StandaloneDocumentHeadTest.php`, `tools/bg-*`, `docs/BG-*`,
`docs/bg-shots/`.

**1 · `sorina-reviews.css` names Fraunces and Hanken Grotesk for the review block
on every product page and neither family is ever loaded**, so those titles render
in Georgia and always have. Correctly the owner's decision — and that is a reason
to give him something to decide **between**, not a reason to leave it. Build the
choice: as it is today, in the shop's own type, and, only if the families are
genuinely worth it, properly loaded with the wire cost measured the way Poppins
500 was. Do not download a font family to make the third option exist.

**2 · The instrument, generalised.** `tools/bg-weight500.cjs` is the only honest
font-measuring instrument in this repo, and it exists because the previous one
silently reported the fallback: a ruler set in `Poppins, system-ui, sans-serif`
reports the **system** font's widths wherever Poppins is absent, identically on
every such page — which is how three fabricated numbers reached a docblock as
fact. Find whether any other probe in `tools/` or `tests/browser/` has the same
shape: a measurement that cannot distinguish the thing it measures from the thing
that stood in for it. And the `display:swap` race — probing a weight nothing on
the page uses measures the fallback — belongs in the instrument, not in one
script.

**3 · The needle rows in the files above.** One of the 500 was satisfied by a CSS
comment, which is the shape a lane shipping inline `<style>` into six documents
should worry about most.

## What is waiting on the owner, not on us

Nothing below is a lane's to decide, and three lanes' next round is shaped by the
first item.

- **Four design decisions**, all built, photographed and switched off: the
  product page (five whole designs), the product card (A live, B/C/D one setting
  away), the picture slider's look (four), and the site background (four). One
  page carries all four at 390 and 1280 with the measured numbers under each.
- **Apply .325, .326 and .327, in that order**, then the two server-side jobs no
  package can do: cache headers and HSTS, and registering the domain with Apple
  Pay in Stripe. `docs/PERF-PAGESPEED.md` §5.
- **Which version the server is actually running.** Recorded nowhere; one command
  answers it now that the shop has a shell. Until somebody runs it, every package
  built "on top of" the live state is a guess.
- **The review block's fonts** — see Lane BG above.
- **Darkening the brand pink, or not.** Accessibility is stuck at 96 on 108
  contrast failures, and `--muted` (3.14) and `--pink` (3.08) are already below
  AA against today's background. The Page background screen shows it beside the
  sliders.
- **Reconnect Stripe**, and one real test-mode payment.

---

## Found and not fixed, carried into the next round

Each of these was found by a lane that named it precisely and declined to widen
its own scope, which is the behaviour this project wants.

- **`StockClaim::perShelf()`** — fixed. Kept here one round as the example: it
  was reported in SEC round 1 as *"worth its own lane"*, handed back in round 2,
  and came out **cheaper** (six lines, 19 statements → 4) rather than needing the
  query budget raised.
- **The self-hosted webfont does not reach the five standalone documents** — with
  Lane BG this round, arithmetic above.
- **`kbb.css` still has dead declarations** beyond the two `body` rules already
  removed; nobody has swept it.
- **The five standalone documents still do not extend the layout.** Making them
  do so is a bigger change than any single lane has been given, and would move
  markup on five pages that currently work.
- **`ImageVariants::rootRelative()` affects every caller holding a stored
  column** — audited for the two banner partials and nowhere else.
- **`WIDTHS` stops at 800**, so a high-density phone takes the original for any
  frame wider than 400 CSS pixels. Closing it means a 600w entry and re-running
  the batch on every variant set in the shop, which is the owner's call.
  `docs/PERF-PAGESPEED.md` §6.

---

## The five things that will go wrong if they are not read first

Each has already cost this project a round, and all five are in `CLAUDE.md` with
the measurements.

1. **`KBB_WP_DB` belongs on every run, including the plain one.** Two exporter
   tests reach one shared MySQL database and a plain `vendor/bin/pest` collides
   with any other lane's harness. It reads exactly like flake.
2. **Never `pkill` by pattern.** Three lanes run at once; matching
   `php vendor/bin/pest` kills the other two mid-suite.
3. **The session scratchpad is shared.** Write logs inside your own worktree. A
   generic filename there is how one lane comes to report another's numbers, and
   it does not error — it looks like flake in a third lane.
4. **Check `df -h /` before debugging any intermittent database error.** A full
   disk looks exactly like a savepoint bug, and the tell is that the row it
   blames moves between runs.
5. **Do not pin that your own work is not wired up yet.** Pin the finished state
   — `substr_count(...) === 1` — which is green in the lane's worktree *and*
   after the integrator wires it.

And two more that have each cost a round since that list was written:

6. **`StorefrontEnglishUnchangedTest` compares rendered HTML**, so it
   structurally cannot see a stylesheet-only change. Pick the instrument that can
   see what you changed.
7. **PNG encoding is not deterministic.** Byte-identity proves two pictures are
   the same; byte-*difference* proves nothing — two captures of one page differed
   by six bytes and zero pixels. Compare pixels above a delta threshold.
