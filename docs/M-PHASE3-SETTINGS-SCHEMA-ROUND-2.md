# Lane M2 · the modules Lane M named and did not take

Round 2 of the per-module settings schema. Lane M migrated ten screens and
listed eight that were still on hand-written casts, with a reason for each.
This round took **five** of them — four of the eight, plus MobileMenu's guard
gap — and is explicit about the three it did not and why.

Nothing here is inferred from a status column. Every claim about behaviour was
established by driving the code; every claim about a screen by driving Chromium
at 390px and 1280px.

---

## 1 · The sort that was the whole job

Lane M's own note is the specification for this round:

> `CartPage` and `CheckoutPage` have casts with rules of their own that are not
> policy points: CheckoutPage's `cleanTemplate()` refuses a digit in
> `rating_text` … and CartPage's `money` clamps at zero rather than refusing.
> Both want their own round.

So every behaviour in each `cast()` was sorted into two piles.

**Pile one — policy.** Expressible on the six axes `ModuleSchema::POLICY_KEYS`
already carries, and therefore moved onto the shared cast:

| Module | max | blank | invalid | clamp | hex | bool |
| --- | --- | --- | --- | --- | --- | --- |
| `CartPage` | 160 | keep | default | yes | *(no colour control)* | cast |
| `CheckoutPage` | 120 | default | default | yes | *(no colour control)* | cast |
| `SecurityModule` | — | — | default | yes | — | cast |

`SecurityModule` had **nothing** in pile two: its three arms were the plain
bool, the clamped range and the option-set select, byte for byte the same three
thirteen other modules carry. That is worth recording as a result rather than a
disappointment — it is the shared cast's own argument, measured.

**Pile two — real rules.** A constraint peculiar to one setting, which would be
wrong applied to any other. These are **kept, exactly, as their own validators**
— not flattened into an axis:

| Rule | Where it lives now | What it does |
| --- | --- | --- |
| A template may carry no digit of its own | `CheckoutPage::ratingTemplate()` → `cleanTemplate()`, named on the field by `CheckoutPage::overrides()` | `rating_text` sits beside the pay button; its `{rating}` and `{count}` are replaced with figures read from the reviews table, so a digit anywhere else is a rating the owner invented. Refused back to the shipped wording, never stripped. |
| Over-length is **refused**, not truncated | the same rule | The shared text arm cuts at `max`. Half a sentence beside the pay button is its own defect, so >120 characters falls back to the shipped line. **This is why `max` could not have carried it**, and it is the half a plausible migration would have lost in silence. |
| Money **clamps at zero** and never refuses | `CartPage::moneyFloor()`, named by `CartPage::overrides()` | ModuleSchema's `money` arm refuses anything that is not a run of digits — right for PayShipRules' Cash-on-delivery bounds. `sum_express` and `sum_service` have always answered `max(0, (int) $value)`, and a refusal where the shop previously stored something is a behaviour change on a control an owner has already used. |
| The rail's cap follows `MAX_REC` | `CartPage::overrides()`, `rec_ids` → `['cap' => self::MAX_REC]` | `ModuleSchema::castIds()` defaults to 24 and `MAX_REC` is 24, so the two agreed **by coincidence**. Now moving `MAX_REC` moves the cast with it. |

### `rule`, and the two arms it may never take

`ModuleSchema::rule()` is the new channel. A rule **replaces** the type arm —
it cannot run after it, because `cleanTemplate` has to see the untruncated value
to refuse it — which makes the rule itself the boundary for that field. So the
two arms rule 5 of the project notes names by hand are **closed to rules**: a
`colour` must reach a stylesheet as `#` + six digits, and a `select`/`skin`/
`sections` must hold one of its own options. Declaring a rule on either throws
at `normalise()` time rather than at render time.

## 2 · MobileMenu joins the guard

`ModuleFrameworkGuardTest` caught a module storing a setting with no control to
write it — *"cart_panel stores these with no control to write them: accent"* —
and its own note said MobileMenu was the one module with a `SCHEMA` that could
not be checked, because its groups were written inline in
`MobileMenuApiController::show()`.

They are `MobileMenu::TABS` now, carried across verbatim — same five groups,
same order, same labels, same descriptions, same key order within each. The
endpoint still answers `fields` + `groups` rather than `tabs`: that is a
different contract with a different renderer, and swapping it would move a
screen this change is not about.

**Proved, not asserted.** `ModuleScreenPayloadTest` compares mobile-menu's
`fields` and `groups` **outright** — not loosely — so a single reordered key
fails it. It passes. And the guard now works for this module: adding an
`m2_orphan` bool to its SCHEMA and to no group fails with
*"mobile_menu stores these with no control to write them: m2_orphan"*, which
before this change could not have been said at all.

## 3 · The gap the guard found the moment it was switched on

`cart_page` stores `rec_ids` and no TABS entry names it. That is a **true
report of a real arrangement**: the Cart page screen draws a product picker for
the rail — search, a chosen list, arrows to reorder — because an id is not
something an owner can check by reading it, and the screen posts `rec_ids`
itself (`cart-page-screen.blade.php:532`).

Putting the key in TABS would draw a second, generic text box for the same value
beside the picker, which is the "same control twice" defect three lines further
down in that very test. So it is exempted — and **the exemption proves itself**:
a second test reads the named console file and requires that it really posts the
key. Pointing it at another screen fails with *"cart_page.rec_ids names a
console file that does not exist"*.

## 4 · What was NOT taken, and the concrete blocker for each

Three of the eight, named rather than quietly skipped:

- **`ReviewSettings`, `CacheSettings`, `ReviewBadgeSettings`** — their schema is
  `[type, default, min, max]` with **no label and no help**, they have a static
  `normalise()` rather than a `cast()`, their types are `enum`/`int` rather than
  `select`/`range`, and their bool dialect is a **third** one: `'null'` is false
  to them and true to `ModuleSchema::castBool()`. Migrating them means rewriting
  three schema constants and moving three screens' payloads. Each is its own
  round, and none of them is a smaller version of this one.

  **Taken in round 3 — `docs/M-PHASE3-SETTINGS-SCHEMA-ROUND-3.md`. Two
  corrections to the paragraph above, both from measurement:** there is a third
  dialect on a SECOND axis that this note does not mention —
  `ReviewBadgeSettings::colour()` requires the `#`, expands `#abc` to `#AABBCC`
  and upper-cases, which is none of `strict` or `repair` — and it is the one
  that would have cost something, because `activeTheme()` string-compares the
  stored six digits. And **the payloads did not move**: none of these three
  screens is rendered from a schema payload at all, so rewriting the constants
  changed nothing the screens receive. 345 recorded calls, 345 byte-identical,
  no exemption.
- **`MailSettings`** — two types the schema has never had (`secret`, routed to
  an encrypted credential store, and `choice`, which stores a key and returns a
  label), per-key `MAX_LENGTHS`, a merchant-address format check, and the
  literal `"-"` meaning "forget the stored password". It is not a schema
  migration; it is a rewrite of a screen that sends email.

`HomepageContent` was on the list and turned out to be **already** reading and
writing through `ModuleSchema` — it had simply never been added to the guard,
which is the whole difference between "uses the schema" and "is checked by it".
It is on the guard now, for its flat copy; `SLIDE_SCHEMA` stays out because a
slide's fields belong to the slide, not to the screen, and that class's own
comment says why a tab declaring them would be the screen stating something
untrue about itself.

## 5 · Rule 1, proved rather than asserted

- **The recorded-cast fixture is the instrument.** `cart_page`, `checkout_page`
  and `security` were already in Lane M's 4,653-call corpus, recorded before any
  of this was touched. **Not one new exemption was added**, and none was needed:
  all three answer every recorded call byte-identically.
- `ModuleScreenPayloadTest` gained the **Security** screen — the one screen of
  the five whose payload nothing pinned. Its before-state was recorded off the
  parent revision. 482 → **500** controls compared, and the only difference
  permitted is still the additive `name` key.
- Its fixture entry carries `tabs` **and nothing else**, deliberately: the
  endpoint also answers `report`, which holds the integrity check's `ran_at`, a
  wall-clock timestamp that differs between any two runs. Recording it would
  have made the test fail on the clock rather than on a change.

## 6 · Task 3 — driven, and nothing found

Lane M found the `isValidHex` defect by driving real values, not by reading; it
had survived five readings. So this round drove too.

- **Every field of every module on the schema** (16 modules) was cast over an
  adversarial corpus and the answer checked against the field's **own declared
  language** — a colour must come back as a CSS colour, a select as one of its
  own options, a range inside its bounds. **Zero findings.**
- **The three unmigrated classes with a `normalise()`** were driven the same
  way. `ReviewBadgeSettings::colour()` requires the `#` and expands 3-digit
  shorthand; `e23a4e` falls back to the default rather than being stored.
  **Zero findings.**
- **Eleven CSS-emitting modules** were driven end to end: a hostile value posted
  through each module's own `save()`, then `cssVariables()`/`bodyClass()` read
  back off a **fresh instance with the settings cache flushed** — so what is
  checked is what a later request renders. **Zero findings**, over 1,700
  emitter reads. That drive is kept as `tests/Feature/ModuleCssBoundaryTest.php`
  rather than thrown away.

### One thing the suite found in THIS work, and how it was answered

`StaticMemoIsolationTest` went red on it:

> these classes hold process-level state that survives a test and are neither
> reset nor exempt in Tests\Support\StaticMemos: App\Services\CartPage,
> App\Services\CheckoutPage, App\Services\SecurityModule

Each of the three had grown a `static $fields` memo, because a module's `cast()`
needs one field and `normalise()` builds them all — CartPage's `all()` would
otherwise pay 11,236 `field()` calls for one cart render.

It could have been answered with three exemptions: the memo is built from class
constants, so it genuinely cannot carry a seeded value across a test. It was
answered with **one memo and one registered reset** instead —
`ModuleSchema::normalised()` / `forgetNormalised()`. An exemption is a claim
somebody has to believe every time they read the list; a reset costs an array
assignment and is a fact. Every module gets the saving now, not the three that
happened to ask for it.

**One thing found and not fixed** — *settled in round 3, which re-derives on
read; see `docs/M-PHASE3-SETTINGS-SCHEMA-ROUND-3.md` §2 for what each of the
declared types actually answered when a hostile row was planted in the table,
and §4 for the unescaped setting that driving the same question through the
storefront turned up.* Named here rather than buried:
`ModuleSchema::coerceRead()` does not re-derive on the way out — it casts a
stored value to its PHP type and trusts it. Every migrated module happens to be
safe, because each one's `all()` runs the value back through `cast()`; but the
three services that use `ModuleSchema::read()` (`PayShipRules`,
`BuildMyRoutine`, `HomepageContent`) do not, so a row put there by a hand-edit,
an older build or a WordPress import is trusted. None of them declares a
`colour`, so nothing reaches a stylesheet today. `ReviewSettings`' own docblock
argues the opposite policy in as many words — *"applied on READ as well as on
write, so a row hand-edited in the database … cannot put the storefront outside
the range the screen would allow"* — and the two disagree. Changing it moves
read behaviour for three modules, which is a decision, not a cleanup.

## 7 · Admin paths

| What | Where |
| --- | --- |
| The cart page's layout, rows, rail, summary, docked rows, desktop and address popup | **Appearance → Cart page** |
| The rail's product picker that writes `rec_ids` | **Appearance → Cart page → Recommended** |
| The money fields whose floor-at-zero rule this round preserved | **Appearance → Cart page → Summary & trust** (*Express delivery charge*, *Service fee · fixed amount*) |
| The wording template whose no-digit rule this round preserved | **Store → Checkout page → Trust & reviews** |
| The five security bands | **Store → Security → Settings** (What is recorded · The verdict line · File integrity · Content security policy · Evidence & retention) |
| The five mobile-menu groups now in `TABS` | **Appearance → Mobile menu** (Panel · Top of the sheet · Rows · Open section · Foot of the sheet) |
| The flat homepage copy now under the guard | **Appearance → Homepage content → Other wording** |

**No control was added, removed, renamed or moved.** Every path above is where
it already was.

## 8 · Measured, in Chromium, before and after

`tests/browser/lane-m2-module-screens.mjs` produces both tables without being
edited between them — point it at a checkout of either revision.

**Controls per screen, summed across every tab, before → after:**

| Screen | 390px | 1280px |
| --- | --- | --- |
| Cart page | 104 → 104 | 104 → 104 |
| Checkout page | 104 → 104 | 104 → 104 |
| Security | 18 → 18 | 18 → 18 |
| Homepage content | 32 → 32 | 32 → 32 |
| Mobile menu | 26 → 26 | 26 → 26 |

Per tab, identical too — Cart page 2 · 4 · 12 · 25 · 14 · 18 · 29; Checkout page
12 · 7 · 7 · 15 · 9 · 7 · 8 · 15 · 7 · 3 · 14; Security 4 · 3 · 3 · 4 · 4;
Homepage content 30 · 2. A per-screen count taken on arrival would only have
counted the first tab, which is why the script clicks through all of them.

**`document.documentElement.scrollWidth` equals `innerWidth` on every screen at
both widths** — 390 and 1280, five screens, before and after. No horizontal
overflow anywhere.

**Nine of the ten screenshots are byte-identical between the two revisions**
(same md5, same file size). The tenth, `security-1280`, differs because the
audit trail above the settings gained one *"Signed in"* row between the two
runs — the browser signed in again — which moves the page down by one row. The
settings half of that screenshot is pixel-identical.

## 9 · Screenshots

`docs/m-module-shots/m2-*`, captured in real Chromium against a seeded SQLite
database served through this worktree.

| File | What it shows |
| --- | --- |
| `m2-before-cartpage-*.png` / `m2-after-cartpage-*.png` | Appearance → Cart page, before and after its cast and its render loop moved onto ModuleSchema. Byte-identical, which is the point. |
| `m2-before-checkoutpage-*.png` / `m2-after-checkoutpage-*.png` | Store → Checkout page, likewise. |
| `m2-before-security-*.png` / `m2-after-security-*.png` | Store → Security. The settings half is pixel-identical; the trail above it grew one sign-in row between runs. |
| `m2-before-hpcontent-*.png` / `m2-after-hpcontent-*.png` | Appearance → Homepage content, which only joined the guard. |
| `m2-before-mobilemenu-*.png` / `m2-after-mobilemenu-*.png` | Appearance → Mobile menu, whose groups moved out of the controller into `TABS`. |

## 10 · Suite

    KBB_WP_DB=kbb_wp_m2 vendor/bin/pest --compact

**5,814 passed · 41 skipped · 0 failed**, 43,292 assertions, 332.87s. Exit 0.

The lane-named `KBB_WP_DB` is what CLAUDE.md prescribes and it is load-bearing
here rather than decorative. An earlier run of plain `vendor/bin/pest`, with two
other lanes running theirs at the same time, failed
`GqMigrationCensusTest > it classifies every meta key` with

    Table 'kbb_ge_wp.wp_options' doesn't exist

which is precisely the collision those notes record: the WordPress-exporter
harness builds its own MySQL database and DROPS every table it uses, so two
lanes tear it down under each other. Re-run alone it is 12 passed, 1 skipped.
Nothing in this round touches the exporter.

Naming the database also moves sixteen MySQL-dependent tests from passed to
skipped, because the lane-named database is not the one they are seeded into —
so the two figures are not directly comparable, and both are given rather than
the flattering one.

That same earlier run also failed `StaticMemoIsolationTest`, which was this
round's own defect and is fixed above rather than excused.
