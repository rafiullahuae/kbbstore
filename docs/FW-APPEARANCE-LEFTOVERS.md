# Appearance leftovers — the hero band's switches, the reordering verdict, and `site_title`

Lane FW, Phase 15. The sequel to `docs/FO-HOMEPAGE-INVENTORY.md` §6 and the
three items `docs/FR-HOMEPAGE-ORDER.md` measured and deliberately left.

Everything below was fetched and measured. Where an earlier document's costing
turns out to be wrong rather than merely incomplete, it is named as such.

---

# 1. The hero's visibility switch overrode two other sections. Repaired.

## FR's repair was right, and its costing was incomplete in one place

`docs/FR-HOMEPAGE-ORDER.md` wrote the repair out as a hypothesis:

> the band's wrapper takes the UNION of the three rows' visibility; the slider
> takes the hero's OWN `d-off`/`m-off`; `@unless ($sections->hidden('hero'))`
> becomes "all three hidden"; `@if (count($banners) > 0)` around the slider also
> needs `! $sections->hidden('hero')`; `$heroCarriesH1` is already correct and
> must not change.

**Verified, all five clauses, and applied.** The reasoning that makes it right
and not merely plausible is one sentence, and it is now in the service: a nested
section's own `d-off` can only ever SUBTRACT from what its host shows, because
`display:none` takes the subtree with it — so the wrapper has to be visible on a
device if ANY of the three is on for that device, and each of the three then
subtracts its own switch inside. Neither half works alone: the union by itself
makes the hero unhideable, the slider class by itself does not bring the other
two back. Both mutations were run and both go red (V1, V3 below).

**Where FR's costing is incomplete: it measured the wrong case as the headline.**
FR measured hero-desktop-off and reported that the two rows are hidden on
desktop with their own switches on. True, and photographed. But the *worse* case
is the hero off on BOTH devices, which FR's write-up mentions in half a sentence
("the same is true one step harder") and did not measure. There the `@unless`
fell through and **two sections the owner has explicitly switched ON are not on
the page at all** — not hidden by CSS, absent from the document. Measured:

| hero off on both, delivery + ticker ON | 1280 × 900 | 390 × 844 |
|---|---|---|
| hero band `<section>` | **not rendered** | **not rendered** |
| `.delivery` | **absent from the DOM** | **absent** |
| `.tick` | **absent from the DOM** | **absent** |

After the repair, the same configuration:

| | 1280 × 900 | 390 × 844 |
|---|---|---|
| band `<section class="sec ">` | `block`, painted, 1280 × 210 | `block`, painted, 390 × 180 |
| `#slider` | **not rendered** (it carries the `<h1>`) | **not rendered** |
| `.delivery` | `flex`, painted, 1203 × 43 | `flex`, painted, 366 × 67 |
| `.tick` | `block`, painted, 1203 × 45 | `block`, painted, 366 × 40 |
| `<h1>` | the quiet fallback, painted | the quiet fallback, painted |

`docs/fw-appearance-shots/03-after-hero-off-both-desktop.png`.

## Every combination that matters, before and after

Chromium 1194, `browser.newContext({viewport})`, `getComputedStyle` plus
`offsetParent` so that "computes to `flex`" and "is actually painted" are two
different answers rather than one. Preview: `php -S` in front of
`public-web-root/index.php`, `KBB_PUBLIC_PATH` at `public/` so `@vite` resolves
the built bundle, migrated and seeded SQLite (24 products, 8 brands).

| | | BEFORE | AFTER |
|---|---|---|---|
| **A** hero desktop-off, delivery + ticker on | band @1280 | `none`, not painted | `block`, painted |
| | `.delivery` @1280 | `flex`, **not painted** | `flex`, **painted** |
| | `.tick` @1280 | `block`, **not painted** | `block`, **painted** |
| | slider @1280 | `block`, not painted | `none`, not painted |
| | everything @390 | painted | painted, unchanged |
| **B** all three off, both devices | band | not rendered | not rendered |
| | quiet `<h1>` | painted | painted |
| **C** hero on, delivery + ticker off | band, slider | painted | **identical** |
| **D** hero off both, delivery + ticker on | band, `.delivery`, `.tick` | **all absent** | all painted |
| | slider | absent | absent |
| **E** hero mobile-off, delivery + ticker on | band @390 | `none`, not painted | `block`, painted |
| | slider @390 | `block`, not painted | `none`, not painted |
| **F** delivery desktop-off only | `.delivery` @1280 | `none` | `none` — **unchanged** |
| | band, `.tick` @1280 | painted | painted — **unchanged** |

F is the case that proves the union did not go too far: a nested row's own
switch still subtracts. `document.documentElement.clientWidth === scrollWidth`
at both widths in all six (1280/1280, 390/390) — no horizontal overflow
anywhere.

## A shop that has never used these switches

Fetched and diffed, not reasoned about. Two renders of `/` differ only in the
CSRF token, so that is masked and the mask is proved sufficient by diffing two
renders of the same tree first. Then everything this lane had changed under
`app/` and `resources/` was stashed, the compiled views cleared, `/` fetched
from the tree as it stood, the change restored and `/` fetched again:

```
diff before.html after.html   →  no output
89,288 bytes, identical
```

**It took three attempts to get there, and both failures were the same byte.**
A Blade comment compiles to nothing and leaves the newline that followed it, so
a comment block written on its own lines above a directive adds one blank line
to the rendered page of every shop on earth. 89,288 against 89,289, one `>` at
line 322, twice. `store/home.blade.php` already carries three notes warning
about this shape and this lane was caught by it anyway; the two new comments now
end on the line their directive starts on, and say why.

`StorefrontEnglishUnchangedTest` stays green, so **`EnglishRenderWalk::BASE_COMMIT`
is not moved by this lane at all.** Nothing it renders is configured off.

## What this needed, and what it did not

- **No CSS.** `.d-off` and `.m-off` are already in the built bundle
  (`public/build/assets/kbb-DfF8vyzq.css`) and this change only moves which
  elements carry them. Nothing under `resources/css/**` or `resources/js/**` was
  touched, so nothing here ships inert pending a rebuild.
- **No route, so no `clear_caches_*` for the route table** — but
  `store/home.blade.php` changed, so the compiled VIEWS do have to be cleared
  and the package needs a `clear_caches_*` migration for that. Any existing one
  in the package covers it.
- **No console block.** `HomepageSections::NESTED_NOTE` is carried into the
  payload the screen paints from, so the row's sentence follows the code.

## The sentence on the row had to change, and that is not optional

`NESTED_NOTE` ended "…and is hidden on any device the hero itself is switched
off for". That was FR's honest description of the defect. Leaving it after the
repair would be the same fault pointing the other way — a screen telling the
owner a control does not work when it now does. It now reads:

> Drawn inside the hero band, so it moves with the hero and cannot be placed
> elsewhere on the page. Its own Desktop and Mobile switches still decide
> whether it shows.

`HomepageSectionOrderTest`'s §3 pinned the old clause with
`toContain('switched off for')`. That assertion is now the ABSENCE of the same
string, with `str_contains(...)->toBeFalse()` rather than `not->toContain()` —
the variadic shape `ExpectationsThatCannotFailTest` sweeps for. It is asserted
absent rather than merely no longer asserted present, because a note that
silently grew the clause back would otherwise be green.

## One thing measured that neither FO nor FR named

**With the hero switched off for one device only, the page has no PAINTED
`<h1>` on that device.** `$heroCarriesH1` is `! hidden('hero') && count($banners)`,
so with hero desktop-off the quiet fallback does not render and the real `<h1>`
is inside a slider that is now `display:none` at 1280px. Measured before this
lane and after it — **unchanged either way**, so it is a finding and not a
regression.

It is left alone on purpose. The invariant `$heroCarriesH1` actually guarantees
is "exactly one `<h1>` in the DOM, always", and that still holds in all six
configurations above (pinned by a test that walks them). Making it "exactly one
PAINTED `<h1>` per device" means rendering the quiet one as well with the
complementary visibility class, which puts **two** `<h1>`s in the document —
worse for a crawler than one that a stylesheet hides. The trade is the right way
round; it should just be known.

The slider's render condition is now `$heroCarriesH1` itself rather than a
second copy of `count($banners) > 0`, so the flag that decides the fallback and
the element carrying the real heading cannot drift into two or none.

---

# 2. Section reordering: route 1 was costed again, and refused again

## The decision

**Do not restructure `home.blade.php` into per-section partials.** Keep route 2,
the CSS `order` Lane FR shipped. The measurements are below and the strongest of
them is the first, because it removes the main argument FOR route 1.

## Correction: route 1 does NOT make all seventeen sections move

`docs/FO-HOMEPAGE-INVENTORY.md` §3 calls route 1 "the only version that makes
all seventeen movable". `docs/FR-HOMEPAGE-ORDER.md` repeats it: "the only
version in which all seventeen move". **Both are wrong, and it matters, because
it is the whole case for a 365-line restructure of a contended file.**

The delivery strip and the promo ticker are not un-movable because `.kbb-home`
is not a flex container. They are un-movable because **they are `<div>`s inside
the hero's `<section>`, under its `.wrap`.** Looping partials in saved order
changes which partial is emitted when; it does not take those two `<div>`s out
of the hero's markup. Under route 1, exactly as under route 2, moving them means
first giving each its own `<section class="sec"><div class="wrap">` — and that
is the change FR itself costed as visibly different (the hero stops being one
band and becomes three stacked cards, because `.kbb-home .sec > .wrap` carries a
22px radius, a border and a shadow).

**That lift is independent of route 1 and route 2.** It can be done on top of
what is shipped today, for the same price, whenever somebody decides the hero
should be three cards. Route 1 buys nothing towards it.

So what route 1 actually buys is one thing: the corner ornament alternating by
visual order instead of source order.

## What that one thing is worth, measured

`.kbb-home .sec:nth-of-type(odd) > .wrap::before` — a 220 × 200px corner
artwork, `opacity:.5`, masked into the card's rounded top-right corner. A rule
250 lines further down the same file, `@media` at the phone breakpoint, sets
`display:none` on it: **it is desktop-only.** FR's reproduction stands — with
`['about']` first, visual #1 and #2 both carry it and #11 and #12 both carry
none.

## What route 1 costs, measured rather than quoted

`docs/FO-HOMEPAGE-INVENTORY.md` calls it "a 635-line rewrite". The file is now
**809 lines and 47,376 bytes**, of which **25,170 bytes — 53% — are Blade
comments**, leaving **365 non-blank lines of actual template** across 15
top-level `<section>` elements and 2 nested ones. So the restructure is smaller
than advertised in one sense and larger in another: fewer lines of markup to
move, far more prose that has to be routed to the right partial without losing
the reason it was written.

Three costs, in order of how much they decided this:

1. **Byte-identity stops being provable by inspection.** An `@include` emits the
   partial's rendered bytes, leading and trailing whitespace included, and the
   loop adds its own. Achieving "a shop that has never reordered renders a
   byte-identical homepage" means getting 16 files' edge whitespace exactly
   right, and it can only be checked by fetching and diffing. **This lane hit
   that exact failure twice in one afternoon on two one-line comment blocks**
   (89,288 against 89,289, above), and FR hit it once, and two other lanes
   before that. Route 1 multiplies a hazard that has a 100% hit rate in this
   file by sixteen, and re-arms it for every lane that later edits a partial.
2. **Contention.** 20 commits touched `store/home.blade.php` in the last 14
   days. A whole-file restructure conflicts with every one of them that is
   still in flight.
3. **The template is not as separable as the section list suggests.** Scanned
   rather than guessed: of the fifteen variables assigned in this file's nine
   `@php` blocks, four cross a section boundary — `$heroCarriesH1` and
   `$heroSliderClass` from the prelude into the hero, and `$homeDeliveryText`
   and `$homeFreeShip` from the hero into the delivery strip and the ticker.
   All four are inside the hero band, which travels as one unit either way, so
   the other eleven sections are genuinely self-contained. That is the one
   measurement that came out in route 1's favour, and it is not enough to buy a
   decoration.

**Verdict: a 50%-opacity desktop-only corner artwork that is sometimes two in a
row does not buy a restructure whose central promise — byte-identity — is the
one property this file has proved hardest to hold.**

## And the ornament cannot be repaired at this layer either — here is why

FR said "there is no honest patch at this layer". Confirmed, with the mechanism,
because the obvious workaround looks like it should work and does not:
`orderStyle()` already emits CSS inline from PHP, so it can reach any selector —
but the ornament is a `::before` whose `content` is only declared inside the
`:nth-of-type(odd)` rule. A pseudo-element with no `content` does not generate,
so an inline rule can **suppress** the ornament where source order puts it and
cannot **create** it where visual order wants it. Creating it means copying
eight declarations, a `mask-image`, an `[dir="rtl"]` mirror and a phone
suppression out of `kbb.css` into PHP, in two media contexts. Editing the
stylesheet instead ships inert until somebody rebuilds the bundle, which is the
whole reason FR emitted CSS from PHP in the first place.

The one cheap honest option is to suppress the ornament entirely while a custom
order is in force — one rule, no rebuild, byte-neutral for the shipped order.
**Not taken**: removing a decoration from every shop that uses the arrows is a
design decision, and it is the owner's.

## Making the screen honest, which is what was asked for instead

Three things, and only the last needed writing:

1. **Lane FR's two console blocks are still appliable and still unapplied.**
   Checked against `resources/views/admin/app.blade.php` as it stands on this
   branch: neither anchor has drifted, and `HomepageSectionOrderTest`'s §5 says
   so on every run. They take the arrows off the two nested rows and put the
   sentence there instead. Nothing in this lane supersedes them — Block 1 prints
   `s.note`, so it picks up the rewritten `NESTED_NOTE` with no edit.
2. **The rows' Desktop/Mobile switches now do what the screen says**, which is
   §1 above, and is the larger half of "honest" on that screen.
3. **The Layouts wire-frame was drawing a page no preset produces. Fixed, in
   PHP.** FR found this and left it for "whoever owns the console"; it turned
   out not to need the console at all. `HomepageLayouts::summaries()['order']`
   read `$layout['sections']`, the sequence a preset ASKS for. Conversion asks
   for `hero, ticker, delivery`; Boutique puts the delivery strip tenth. Both
   are settled back behind the hero on every read, so two of the four cards
   showed a wire-frame of a page that has never existed. `summaries()` now takes
   its sequence through `payloadFor()` and the new
   `HomepageSections::settleKeys()` — the same two functions that decide what
   applying the preset does — so the preview is computed from the result and
   cannot disagree with it. The preset definitions are NOT rewritten, the
   section counts are unchanged, and `resources/views/admin/app.blade.php` is
   untouched.

| preset | wire-frame before | wire-frame now |
|---|---|---|
| Signature | Hero · Delivery · Ticker · … | unchanged |
| Conversion | Hero · **Ticker · Delivery** · Flash · … | Hero · **Delivery · Ticker** · Flash · … |
| Editorial | Hero · Delivery · Routine · … | unchanged |
| Boutique | Hero · **Categories** · Best sellers · … | Hero · **Delivery** · Categories · … |

---

# 3. `site_title` — read by the storefront, written by nothing

## Where it belongs, and the part FO did not name

FO reported it and said it belongs with the general settings rather than on an
Appearance screen. Verified: **Store → Business Details → Store identity**,
beside `store_name`, saved through the generic settings endpoint. One line in
`AdminController::SETTING_RULES` and one field on the screen.

What FO did not name is that **its two readers do not agree on what it means.**
`store/home.blade.php` prints it as a headline — the page's `<h1>` when the hero
slider is off or empty, defaulting to "K-Beauty Bliss — authentic Korean
skincare in the UAE". `store/review-wall.blade.php` prints it as a NAME, the
wordmark at the top of `/reviews`, defaulting to "K-Beauty Bliss". One box now
feeds both, and the box's help text says both, which is the honest version of a
single key: *"The heading the homepage shows when the hero slider is switched
off, and the name at the top of the review wall."*

**Not a third `store_name`, and the reasoning is in the code.** `store_name` is
the business — it signs the emails, heads the invoices, carries the footer
copyright and is the SEO layer's fallback. `seo_site_name` is the search
appearance and already falls back to `store_name`. This is the line the shop
puts at the top of its own page, and the shipped defaults are different strings
for exactly that reason. Collapsing them would put a tagline on every invoice or
strip it off the homepage; neither is a lane's decision.

## The empty box — and the trap is NOT where the brief expected it

`ConvertEmptyStringsToNull` does reach inside the posted `settings` array, so a
cleared field arrives at the controller as `null`. **It is harmless here**, and
that was measured rather than assumed: `checkSetting()`'s `text` branch does
`trim((string) $raw)`, so `null` lands as `''` and is stored. A test posts an
explicit `null` through the real endpoint to pin it.

**The trap is one step later, at the reader.** A cleared box stores `''` and does
not delete the row, and `SettingsService::get()` answers its default only when
the ROW IS ABSENT. So

```blade
{{ $settings->get('site_title', 'K-Beauty Bliss — authentic Korean skincare in the UAE') }}
```

returns `''` for a shop that has cleared the field, and the homepage's only
`<h1>` becomes **empty** — an `<h1></h1>` on the front page of the shop, the
first time anybody uses the box this lane just added. It now reads
`get('site_title') ?: '…'`, which covers `''` and `null` alike and is
byte-identical when the row is absent. The review wall already used `?:`; that
was luck rather than design, and it is pinned now.

<!-- ANCHOR-1 -->

## Block 1 — the box, on Business Details → Store identity

**Anchor** (in the Business Details screen, line ≈15516):

```js
          bdField('set_store_name','Store name',
            '<input id="set_store_name" value="'+sesc(SETTINGS.store_name)+'">',
            'Shown in the browser tab, in emails and on invoices.')+
```

**Replace with:**

```js
          bdField('set_store_name','Store name',
            '<input id="set_store_name" value="'+sesc(SETTINGS.store_name)+'">',
            'Shown in the browser tab, in emails and on invoices.')+
          /* WHAT THE SITE CALLS ITSELF, as opposed to what the business is
             called. Read by store/home.blade.php — it is the page's <h1>
             whenever the hero slider is off or has no slides — and by the
             wordmark on the shareable review wall at /reviews. Until
             AdminController::SETTING_RULES gained the key, NOTHING in the
             application wrote it: both readers fell back to a literal, so the
             line looked configurable and was not.

             Its own box and not a second use of Store name: that one signs the
             emails, heads the invoices and carries the footer copyright, and
             the two ship as different strings on purpose. The placeholder is
             the shipped line, because clearing the box puts it back — the
             storefront reads with `?:`, so an empty value means "the line we
             ship" and never an empty heading. */
          bdField('set_site_title','Site title',
            '<input id="set_site_title" value="'+sesc(SETTINGS.site_title)+'" placeholder="K-Beauty Bliss \u2014 authentic Korean skincare in the UAE">',
            'The heading the homepage shows when the hero slider is switched off, and the name at the top of the review wall. Leave it empty for the shipped line.')+
```

<!-- ANCHOR-2 -->

## Block 2 — the Save payload

Without this the box paints, holds the stored value and saves nothing. The
endpoint answers ok either way, which is the standing failure mode of that
screen.

**Anchor** (line ≈15946):

```js
        store_name: sval('set_store_name'), currency: sval('set_currency'), vat_rate: sval('set_vat'),
        store_timezone: sval('set_store_timezone'),
```

**Replace with:**

```js
        store_name: sval('set_store_name'), currency: sval('set_currency'), vat_rate: sval('set_vat'),
        // Needs its line in AdminController::SETTING_RULES, which it has — the
        // standing warning at the top of that list is that a key without one
        // is dropped while the endpoint still answers ok.
        site_title: sval('set_site_title'),
        store_timezone: sval('set_store_timezone'),
```

## Both blocks were applied and parsed, not just written

Applied to a copy of `resources/views/admin/app.blade.php` by string match —
both anchors matched exactly once, +1,578 bytes — and all three `<script>`
blocks of the resulting file were extracted the way
`AdminConsoleScriptParsesTest` extracts them (Blade echoes and `@json()`
replaced with `null`) and passed `node --check`.

`tests/Feature/SiteTitleSettingTest.php` reads both anchors back out of this
file and fails if either has stopped matching the script verbatim, the same
arrangement `HomepageSectionOrderTest` uses for FR's two blocks. A stale block
is loud rather than silent.

Nothing else is needed: no `AdminCapabilities::RULES` entry (the settings
endpoint already exists), no route, no migration for the route table. The
compiled views do have to be cleared, because `store/home.blade.php` changed.

---

# Mutations

Every test in this lane's three new files was run against the unfixed tree and
against each mutation below, and the failures recorded. No guard survived its
own mutation. None of them uses the `->not->toContain($needle, $message)` shape
`ExpectationsThatCannotFailTest` sweeps for; where an absence is asserted it is
`expect(str_contains(...))->toBeFalse($message)`.

## The hero band — `tests/Feature/HomepageHeroBandVisibilityTest.php`, 13 tests

Whole unfixed tree: **8 of 13 red.** The five that stay green are the
byte-identity guard (§1, which passes before and after by design and says so),
the nested row's own switch, the hero alone, the one-`<h1>` walk and the quiet
fallback — all four of those are invariants that must survive the repair.

| | Mutation | Caught by |
|---|---|---|
| V1 | the band takes `classFor('hero')` — the hero's own — instead of `bandClassFor()` | 3: desktop, mobile, and the hero-off-both case |
| V2 | `@unless` back to `hidden('hero')` | 1: "draws the band for the delivery strip alone" |
| V3 | the slider carries no visibility class | 2: desktop and mobile — the hero becomes unhideable |
| V4 | the slider's condition back to `count($banners) > 0` | 3: the hero-off-both case, the one-`<h1>` walk, and the quiet fallback (two `<h1>`s) |
| V5 | `bandVisibility()` intersects instead of unioning | 7 — nearly the whole file |
| V6 | `deviceClassFor()` also emits the order class and the divider mark | 1: "gives the slider the hero's two visibility flags and nothing else" |
| V7 | `bandClassFor()` drops the order class and the divider mark | 2: the ordering/divider pairing and the service-level comparison |
| V8 | `NESTED_NOTE` left saying the hero overrides the switches | 1: the note test |

V6 initially survived and the file was wrong, not the mutation: with the shipped
order and the hero first, the order class and the divider mark are both empty,
so the mutation was a no-op in every case the file covered. A test that moves
the hero down the page and turns the divider on first was added, and it catches
it — and would also catch an inert `kbb-ord-` class being emitted onto an
element that is not a child of `.kbb-home`, which is this project's own defect
class.

## `site_title` — `tests/Feature/SiteTitleSettingTest.php`, 8 tests

| | Mutation | Caught by |
|---|---|---|
| S1 | `site_title` is off `SETTING_RULES` (the state before this lane) | 7 of 8 |
| S2 | the homepage reader uses `get()`'s default argument again | 1: "puts the shipped line back … not an empty h1" |
| S3 | the review wall reader uses `get()`'s default argument | 1: the review-wall case |

S3 is worth noting: the review wall's `?:` predates this lane and was unpinned.
It is load-bearing and it is pinned now.

## The Layouts preview — `tests/Feature/HomepageLayoutPreviewTest.php`, 5 tests

| | Mutation | Caught by |
|---|---|---|
| L1 | `summaries()` reads the preset's stored sequence again | 2: the sweep and the two named presets |
| L2 | `settleKeys()` keeps a nested key where it was found | 4 |
| L3 | `summaries()` filters on `off` instead of the payload it just built | 3, including the section-count pin |

---

# Found, measured, and deliberately NOT changed

- **The review wall's wordmark should probably read `store_name`, not
  `site_title`.** It is the shop's name in that position — `$shopName` is what
  the template calls the variable. Switching it is a one-word edit and a CHANGED
  PAGE: the walk's seed writes `store_name` as "K Beauty Bliss" while the
  literal here is "K-Beauty Bliss", so it would move bytes on a byte-pinned
  storefront page and cost a `BASE_COMMIT` repin for a hyphen. Named here so it
  is not found a third time.
- **No PAINTED `<h1>` on a device the hero is switched off for.** §1, last
  subsection. Pre-existing, unchanged, and the alternative is worse.
- **The corner ornament still follows source order.** §2. Only a DOM reorder or
  a CSS rebuild fixes it, and the suppress-it-entirely option is a design call.
- **Lifting the delivery strip and the ticker out of the hero band** remains
  costed in `docs/FR-HOMEPAGE-ORDER.md` and remains the right eventual shape.
  This lane's §1 removes the argument that it has to land together with the
  visibility repair: the switches work now, nested or not.
- **`moduleEnabled('banners')` is still read by nothing** (FO §4). Untouched.

---

# For the integrator

- Branch `lane/appearance-leftovers`. Merged, not rebased — nothing here repins
  `EnglishRenderWalk::BASE_COMMIT`, but the rule stands.
- `tests/Feature/HomepageSectionOrderTest.php` is Lane FR's file and one
  assertion in it was inverted, because the sentence it pinned describes
  behaviour this lane removed. §1 explains it; the change is nine lines of
  comment and one expectation.
- Two console blocks above, for `resources/views/admin/app.blade.php`, which no
  lane may edit. FR's two blocks in `docs/FR-HOMEPAGE-ORDER.md` are still
  appliable and still owed.
- `resources/css/**` and `resources/js/**` are untouched, so **nothing in this
  lane needs an asset rebuild.** `.d-off` and `.m-off` were already in the built
  bundle; the change only moves which elements carry them.
