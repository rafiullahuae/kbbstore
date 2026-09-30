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
| Shipped and in the owner's hands | **2.60.325** — thirteen lanes, 155 files, 14 migrations, built at `3b372db` and verified file-by-file against that tree |
| Next package | **BLOCKED.** See *The one thing blocking the next package* below |
| Lanes running | **SEC**, **PLC**, **BG** |

### The one thing blocking the next package

Lane SEC's round 2 closed the second door on the admin-path leak: `/admin-api/*`
answered a signed-out **browser** with a 302 naming the owner's secret admin
path, and now answers a bare 404. Nine endpoints are reached by **navigating**
the browser rather than by `fetch`, so with an expired session they now land on
that 404 — and for the four that are `window.location.href`, the owner **loses
the console screen he was on**, which is worse than the login page it replaced.

**2.60.325 does not contain this** — it was built before the merge. No package
ships from this branch until it is fixed. It is Lane SEC's top priority and the
fix arrives as anchor/replacement blocks, because the file is the integrator's.

---

## Reading a brief

A brief says what to build and what has already been measured, never how to
write it. Where it names a number, that number was measured and the brief says
where; where it names a file and a line, that line was read. A lane that finds
the brief wrong says so and is right to — three briefs in this project have been
corrected by the lane that received them, and each correction is recorded in the
merge commit in the lane's own words rather than paraphrased.

---

## Lane SEC — the nine navigations, and two smaller things

**Owns:** `app/Support/GuestRedirect.php`, `app/Services/SetStockReconciler.php`,
`app/Services/Payments/StockClaim.php`, `tests/Feature/AdminPathNeverLeaksTest.php`,
`tests/Feature/ClaimSumsByRealShelfTest.php`, `docs/SEC-*`, `docs/sec-shots/`.

**1 · The nine navigations.** Five exports and the four order documents, all in
`resources/views/admin/app.blade.php`, which is the integrator's file — so the
console half is delivered as **anchor/replacement blocks** in the shape
`docs/BG-ADMIN-APP-BLOCKS.md` established: an exact anchor and an exact
replacement per block, each anchor verified to occur exactly once by count, plus
a table of what is red before and green after. That document applied without a
single adjustment and is the standard.

Four of the nine are invisible to any scan of the console, because the address
never appears in its source: the server builds them in
`Admin\InvoiceController::invoiceUrl()` and its siblings, and the console
receives them as data on the order row.

Two constraints that decide the shape, both to be measured rather than reasoned
about: `/admin-api/health` is `throttle:6,1` and will 429 on the fourth export in
a minute, so it is not a probe; and the comment at `olPrintDocs` records that a
popup opened from an async continuation is blocked by the browser, so a
`window.open` must happen inside the click.

**2 · The Catalog screen blames route wiring for any error, including the 401**
(`admin/app.blade.php:19323`). Not a regression from this work — pictured and
proved. `product-editor-screen.blade.php:1021` already has the one-line remedy.
The same sentence appears on 33 screens; say whether the other 32 branch
correctly or give a block each.

**3 · A guard for admin-api URLs the server builds**, so the next one cannot
hide the way those four did.

**Done when:** the expired-session frame is shot at 390 and 1280 for both an
export and an order document, with the console still on screen and the sign-in
message on it, and the blocks apply cleanly.

---

## Lane PLC — assertions that are true for the wrong reason, and the return leg

**Owns:** `app/Services/Checkout/PlacementState.php`,
`app/Http/Controllers/Store/CheckoutReturnController.php`,
`routes/checkout-return.php`, `resources/views/partials/checkout/*`,
`tests/Feature/CheckoutPlacingOverlayTest.php`,
`tests/Feature/NoticeBandsAreStyledTest.php`, `docs/PLC-overlay-shots/`.

**1 · The mutation that did not bite.** `toContain('Your bag is empty.')` passed
its mutation, because the cart **drawer** renders that exact sentence, period and
all, in `.empty-d` — two occurrences on the page. The assertion is well-formed,
so `ExpectationsThatCannotFailTest` structurally cannot see it; it simply does
not test what it claims. **This is the variadic trap's positive twin and it is
almost certainly not one instance.** Count them before fixing any: for each
`toContain('<literal>')` whose needle is a plain sentence or a short token rather
than markup, count how many times that literal occurs in the page the assertion
runs against. More than once means the assertion cannot distinguish the thing it
names from the thing it does not. The count is the interesting number; zero
closes it.

**2 · The provider sending the shopper back twice with different outcomes** — a
cancel followed by a late webhook that confirms, or the reverse. `PlacementState`
checks refused first because a reversed payment leaves `paid_at` set, and that
reasoning is right; the open question is what the **page** does when the row
changes underneath a shopper standing on it.

**3 · A second press of the restore button** — twice quickly, after it has
succeeded, and from a stale page in another tab. "The right thing once" includes
not releasing stock twice.

**Done when:** the count from 1 is reported, 2 and 3 are either fixed or pinned
with a paragraph saying why they were already right.

---

## Lane BG — the webfont, the last two white pages, and the audit as a test

**Owns:** `app/Services/PageWash.php`, `app/Support/BrandAccent.php`,
`app/Support/WebFonts.php`, `resources/views/partials/page-wash-css.blade.php`,
`resources/views/partials/shop-appearance-css.blade.php`,
`tests/Feature/OnePageBackgroundTest.php`,
`tests/Feature/StandaloneDocumentHeadTest.php`, `docs/BG-*`, `docs/bg-shots/`.

**1 · The webfont, and the arithmetic is already done** — in
`docs/BG-STANDALONE-DOCUMENTS.md`, and worth re-measuring rather than trusting.
The journal, an article and the review wall want weight **500**, which
`WebFonts` does not carry; the skin quiz also wants **300**; `/app` asks for
**Fraunces and Hanken Grotesk**, neither of which this shop has at all. Two
separable pieces and they stay separate. Lane PERF self-hosted these fonts to
cut a 4,369 ms critical path (mobile 76→86, desktop 88→99) — do not undo that
gain to fix a synthesised weight. Two font families are not downloaded into this
repo on a lane's own authority.

**2 · The journal and an article are still white.** Two routes were reported and
neither taken: load `kbb.css` there, or copy a ~40 KB data-URI gradient. A third
was not costed — extract the designed background into its own small stylesheet
that both the layout and the standalone documents link. Cost all three in bytes,
requests and render, take the cheapest honest one, and if all three are worse
than white, write the sentence that closes it for good.

**3 · Put the audit in `StandaloneDocumentHeadTest`**, taken at **moved**
settings rather than defaults — four of those emitters print nothing until the
owner touches something, so a table taken at defaults says they are all missing
and tells you nothing.

**Done when:** a seventh standalone document, or a new thing added to the
layout's head, fails the suite loudly.

---

## What is waiting on the owner, not on us

Nothing below is a lane's to decide, and three lanes' next round is shaped by the
first item.

- **Four design decisions**, all built, photographed and switched off: the
  product page (five whole designs), the product card (A live, B/C/D one setting
  away), the picture slider's look (four), and the site background (four). One
  page carries all four at 390 and 1280 with the measured numbers under each.
- **Apply 2.60.325**, then the two server-side jobs no package can do: cache
  headers and HSTS, and registering the domain with Apple Pay in Stripe.
  `docs/PERF-PAGESPEED.md` §5.
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
