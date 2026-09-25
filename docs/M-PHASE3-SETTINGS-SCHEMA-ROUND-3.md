# Lane M3 · the three `App\Support\*Settings` classes, and who is allowed to trust a stored row

Round 3 of the per-module settings schema. Round 1 migrated ten screens and
named eight that were still on hand-written casts. Round 2 took five of them and
named three blockers with a concrete reason for each. This round takes the three
`*Settings` classes — the first of those blockers — and settles the one finding
round 2 left standing on purpose.

Nothing here is inferred from a status column. Every claim about behaviour was
established by driving the code; every claim about a screen by driving Chromium
at 390px and 1280px; every mutation below was run and what it printed is quoted.

---

## 1 · Task 1 — the three classes, and the dialects that were the whole job

Round 2's note is the specification for this round:

> **ReviewSettings, CacheSettings, ReviewBadgeSettings** — label-less
> `[type, default, min, max]` schema, static `normalise()` rather than `cast()`,
> `enum`/`int` types rather than `select`/`range`, and a **third** boolean
> dialect (`'null'` is false to them, true to `ModuleSchema::castBool()`).

All of that is accurate. It is also **not the whole list**, and the part it
misses is the part that mattered.

### The instrument came first, and it had to be a second one

Round 1's 4,653-call fixture could not see these three at all: it reflects
`cast($key, $raw)` over thirteen service classes, and these have a **static**
`normalise($key, $value)`. So they were outside the instrument, which is exactly
why they could be named as a blocker and not measured.

They got their own recording, in the same shape and read by the same kind of
test — `tests/Support/SettingsCorpus.php` drives every field of every one of the
three over an adversarial corpus, and **345 calls were recorded off the parent
revision before a line of these three classes was touched**
(`tests/Fixtures/module-settings-baseline.txt`).

**The first corpus could not have caught the stated blocker even if these
classes had been in it.** Its bool inputs are
`[true, false, '1', '0', '', 'on', 'off', 'no', 1, 0, null]` — **the literal
string `'null'` is not among them**, and that one string is the entire
difference between the two dialects. The new corpus adds it in both cases, plus
`'true'`, `'FALSE'`, `'  off '`, `'yes'`, `'2'`, `0.0` and `'0.0'`.

### There was a third dialect on a second axis, and nobody had named it

Driving the corpus turned up a **third hex dialect** as well as the third
boolean one. `ReviewBadgeSettings::colour()` is not a corner of either arm
`ModuleSchema` already had:

| input | ReviewBadgeSettings | `hex => 'strict'` | `hex => 'repair'` |
| --- | --- | --- | --- |
| `#E23A4E` | `#E23A4E` | `#E23A4E` | `#E23A4E` |
| `#e23a4e` | `#E23A4E` | `#e23a4e` | `#E23A4E` |
| `e23a4e` | *default* | refused | `#E23A4E` |
| `#abc` | `#AABBCC` | refused | `#ABC` |
| `#ABC` | `#AABBCC` | refused | `#ABC` |
| ` #E23A4E ` | `#E23A4E` | refused | `#E23A4E` |

It requires the `#`, **expands** three-digit shorthand, and upper-cases. No two
of those three properties belong to the same existing arm.

**The expansion is the half that looks free and is not.** `#ABC` and `#AABBCC`
are the same colour to a browser, so folding it into `repair` costs nothing you
can see — and `ReviewBadgeSettings::activeTheme()`, two hundred lines below the
cast, decides which preset a shop is on by **string-comparing the stored six
digits** against each theme's. A shop on Classic would have started reading back
as **Custom** while its badge drew exactly as before: a screen telling its owner
something untrue about itself, in a place nobody would look for a colour bug.

That is round 1's argument for measuring, restated by a second case: the fold
looks free, and it breaks a screen two files away.

### What was declared, and what was not

**Two new values on existing axes, one new axis, and no folding.**

| Axis | New value | What it is | Who asks for it |
| --- | --- | --- | --- |
| `bool` | `words+null` | the word-aware list plus the literal `null` — what a value that was NULL comes back as once anything has exported it (`json_encode(null)` is `"null"`) | all three |
| `hex` | `expand` | requires the `#`, expands `#abc` → `#AABBCC`, upper-cases | ReviewBadgeSettings |
| `markup` **(new axis)** | `keep` / `strip` | `strip_tags()` before the trim and the cap | ReviewSettings' `sr_empty_text`, ReviewBadgeSettings' `review_badge_label` |

`markup` is a **wording** rule and is documented in the class as one. What makes
these strings safe to print is Blade's escaping at the print site, not the
strip — see §4, where that distinction stopped being academic.

### And a seventh thing the axes now do: refuse a typo

Every axis is compared with `===` against a literal inside `cast()`, and every
comparison has an `else`. So `'bool' => 'Cast'` **did not fail**: it missed
`=== 'cast'` and fell through to the word-aware arm, and the module silently got
the dialect it had not asked for. With two values per axis that is a coin toss;
this round put a **third** value on two of the axes, which makes it a coin toss
the next reader cannot resolve by reading. `ModuleSchema::POLICY_VALUES` is
checked in `field()`, so a bad policy throws where the module declares it.

### Rule 1, proved rather than asserted

- **345 recorded calls. 345 byte-identical. Not one exemption**, and none was
  needed. `ModuleSettingsEquivalenceTest` requires that and nothing weaker; it
  has no allowed-difference list at all, deliberately, because this round fixes
  no defect inside these three and an exemption with no defect behind it is a
  blank cheque.
- **The other fixture is untouched.** `ModuleSchemaEquivalenceTest`'s 4,653
  calls still pass with its original 32 exemptions: the two new axis values and
  the new axis reach only the modules that declare them.
- **`ModuleScreenPayloadTest` gained the three screens.** Their before-state was
  recorded off the parent revision. None of the three carries `tabs` — they draw
  their own controls in their own partials — so every recorded key is compared
  **outright**, which is stricter than the tab walk, not weaker: one moved
  default fails it. Nothing moved.

---

## 2 · Task 2 — `coerceRead()` trusted what it read. It does not any more.

Round 2's one unfixed finding, left standing as *"a decision, not a cleanup"*:

> `ModuleSchema::coerceRead()` does not re-derive on the way out … `ReviewSettings`'
> own docblock argues the opposite policy in as many words … and the two disagree.

Two parts of this codebase held opposite positions on one question. **The
disagreement is gone, and it is settled on re-deriving.**

### The argument is what was measured, not what is tidier

"Trust it, because it went through `write()`" is only true of a row that went
through `write()`. A row reaches `settings` and `module_settings` by four paths
that do not: a hand-edit, an older build, a WordPress import, a restored backup.
So hostile rows were **planted by those paths** — straight into the tables — and
read back through each module's own public reader. Before the change:

| Module · field | declared type | planted | `all()` answered |
| --- | --- | --- | --- |
| `BuildMyRoutine.steps_mode` | `select` | `evil-not-an-option` | **`'evil-not-an-option'`** |
| `BuildMyRoutine.offer_scope` | `select` | `<script>alert(1)</script>` | **verbatim** |
| `BuildMyRoutine.offer_coupon` | `text` | 9,000 characters | **9,000 characters** (cap 5,000) |
| `PayShipRules.cod_min` | `money` | `-50000` | **`-50000`** |
| `PayShipRules.cod_max` | `money` | `'12.50'` | **`12`** |
| `PayShipRules.hide_paid_free` | `bool` | `'null'` | `true` — the one arm that already re-derived |

The first two are rule 5 of the project notes failing in its own words: *"a
select stores one of its own options or the default."* The last is worse than it
looks: `castInt()` **refuses** `'12.50'` on the way in and its own comment says
why — *"a hundredfold error that reads back as a plausible number"* — and the old
`coerceRead` reintroduced exactly that error on the way out.

Rule 5's other two lines survive the change unharmed and are worth stating,
because the answer had to defend itself against all three:

- *"a URL from a setting is scheme-checked before it becomes an `href`"* — no
  module on this schema declares a URL type; the check would be a `rule`, and
  `rule()` still refuses to replace a `colour` or a `select`.
- *"anything printed unescaped is a constant, never a setting"* — this is the one
  re-deriving **cannot** deliver, and §4 is what came of taking that seriously.

### What it costs, proved rather than argued

Re-deriving is only not a behaviour change if `cast()` is idempotent on
everything it will store. That is checked by driving the **real** round trip —
`write()` into the real tables, `read()` back out, every field of all three
modules that use `read()` — rather than by calling `cast()` twice in memory,
because the tables stringify on the way through and that is where an idempotence
claim would break. **A shop whose values came from its own screens reads back
byte-identical. The only rows that move are rows no screen could have produced.**

One rule had to be stated rather than left to be noticed: `cast()` answers `null`
for "will not store this", which `write()` turns into a reported rejection. A
reader has no such channel and a page must render, so **a refusal on the way out
is the module's declared default** — the value a shop that has saved nothing
already gets, which is the one answer that cannot itself be a surprise.

### And `ReviewSettings` no longer contradicts anything

Its docblock said the clamp was *"applied on READ as well as on write, so a row
hand-edited in the database … cannot put the storefront outside the range the
screen would allow."* That sentence was true of `ReviewSettings` and false of the
schema. It is now true of the schema, and the class says so at the line where a
reader will hit it.

---

## 3 · Mutations, actually run, with what each printed

Eight. Each was applied, the suite run, the output copied, the change reverted.

**1 · `ReviewSettings::POLICY['bool']` `words+null` → `words`** — the third
boolean dialect folded away. Two cases red, 10 calls moved:

    review_settings|sr_show_stars|bool|"null"|false   ==>   review_settings|sr_show_stars|bool|"null"|true
    review_settings|sr_show_stars|bool|"NULL"|false   ==>   review_settings|sr_show_stars|bool|"NULL"|true
    … sr_show_tabs, sr_show_date, sr_allow_submit, sr_allow_photos, the same both ways

**2 · `ReviewBadgeSettings::POLICY['hex']` `expand` → `repair`** — the third hex
dialect folded away. Two cases red, 4 calls moved:

    review_badge|review_badge_colour|colour|"e23a4e"|'#E8A33D'   ==>   …|'#E23A4E'
    review_badge|review_badge_colour|colour|"#abc"|'#AABBCC'     ==>   …|'#ABC'
    review_badge|review_badge_colour|colour|"abc"|'#E8A33D'      ==>   …|'#ABC'
    review_badge|review_badge_colour|colour|"#ABC"|'#AABBCC'     ==>   …|'#ABC'

**3 · `ModuleSchema::coerceRead()` put back to its `match` over `$field['type']`**
— the old trusting version. Two of three cases in
`ModuleReadDerivationTest` red:

    FAILED … it refuses a planted row…    -'fixed'    +'evil-not-an-option'
    FAILED … it hands a reader the de…    -'site'     +'nonsense'

The third stayed green, **correctly**: the round-trip case asserts that a value
saved through a screen comes back unchanged, and the old code satisfied that too.
That is the case measuring the COST of the change rather than its benefit, and a
mutation of the benefit must not move it.

**4 · `home.blade.php`'s `e($ownTicker)` reverted to `$ownTicker`** — §4's fix
removed:

    the homepage printed a setting unescaped — rule 5
    Failed asserting that true is false.

**5 · The `POLICY_VALUES` check in `field()` disabled** — one case red:

    FAILED … it refuses a policy value cast() would not recognise
    Failed asserting that exception of type "\InvalidArgumentException" is thrown

The other four stayed green, which is the point: the typo `'Cast'` silently
becomes `'words'` and nothing else notices.

**6 · `ReviewSettings::POLICY['markup']` `strip` → `keep`** — two cases red:

    review_settings|sr_empty_text|text|"<b>x</b>"|'x'   ==>   …|'<b>x</b>'
    review_settings|sr_empty_text|text|"<script>alert(1)</script>"|'alert(1)'   ==>   …|'<script>alert(1)</script>'

**7 · `ReviewBadgeSettings::POLICY['blank']` `default` → `keep`** — one case red:

    review_badge|review_badge_label|text|""|'{n} reviews'   ==>   review_badge|review_badge_label|text|""|''
    review_badge|review_badge_label|text|null|'{n} reviews' ==>   …|''

**8 · `ModuleSchema::describe()`'s `bool` arm reverted to `castBool($value)`** —
the flat, mode-less call it had before this round. One case red:

    a `cast` module describes 'off' as the words dialect does
    Failed asserting that two strings are identical.
    -'on'
    +'off'

The recorded-call test stayed green, correctly: `describe()` is not on any
module's cast path, which is precisely why the wrong answer had survived.

---

## 4 · What driving task 2 through the STOREFRONT found, which the schema cannot fix

Task 2 asked what happens when a hostile row is already in `settings`. Reading it
back through the reader gave the table in §2. Reading it back through the
**page** gave something else.

`resources/views/store/home.blade.php` builds the promo ticker as an array of
chips and prints them with `{!! $chip !!}`, because the free-delivery chip
carries a `<b>` of its own that has to render. Two of the three chips are safe by
construction — one is a translation string, one already calls `e()`. The third
was `'🎁 ' . $ownTicker`, where `$ownTicker` is `$settings->get('home_ticker')`.

**A `home_ticker` of `<img src=x onerror=alert(1)>` rendered into the homepage
verbatim.** Measured, in a rendered response, not reasoned from the source.

That is rule 5 of the project notes in one line: *anything printed unescaped is a
constant, never a setting.* And the value reaches the table two ways — from
**Appearance → Homepage content → Other wording**, through `ModuleSchema::write()`,
which trims and caps and does not escape; and from any of the four import paths
in §2, which run no cast at all.

**Fixed with `e()` at the print site, not with a strip in the cast**, and the
distinction is the whole reason `markup`'s docblock says what it says: escaping
is a property of where a string is **printed**, and the same stored string is
legitimately handed to the admin screen as JSON in the same request. `e()` also
holds for a row that never went through the screen, which a cast cannot.

Rule 1: `e()` over a string with no HTML in it is the identity, so a shop with a
real ticker is byte-identical, and the shipped default is **no chip at all**.
Both halves are pinned, because "it is escaped now" is not the same claim as
"nothing moved".

---

## 5 · Task 3 — not attempted, and this is the specification for whoever does

`MailSettings` was to be taken **only if 1 and 2 were finished**. They are. It
was still not taken, and the reason is not time: round 2 called it *"a rewrite of
a screen that sends email, not a schema migration"* and reading it confirms that
rather than softening it.

`MailSettings::all()` **returns the LABEL where every other reader on this schema
returns the stored key** — `TRANSPORT_LABELS[canonicalTransport(...)]` — and
`canonicalTransport()` accepts the key, the label, or the empty string and
reduces all three. `ModuleSchema::read()` returns what is stored. Migrating
`all()` therefore moves what the Mail screen's `<select>` is given and what
`transport()` is compared against, on a screen whose failure mode is a shop that
stops sending order emails. It is its own round with its own before-and-after.

**And the `secret` type is the one to think hardest about, exactly as the brief
says.** What makes it hard is not encryption, it is that `secret` is the first
type whose *contract is about what may NOT come back*:

- `all()` **skips** `secret` outright — the password is not in the map at all,
  and `password()` goes to a separate credential store with its own docblock
  saying only `MailConfigurator` may call it.
- So a `secret` field cannot use `ModuleSchema::fields()` as it stands:
  `fields()` emits `'value' => $values[$key] ?? $f['default']` for **every**
  field, unconditionally. A schema that can express "encrypted credential" and
  still uses that loop is a schema that has been asked to print one and has
  answered.
- `"-"` meaning "forget the stored password" is a **third** state on a write —
  not a value, not an absence — which `write()`'s `array_key_exists` /
  `cast() === null` pair has no room for.

So the minimum `secret` has to add is: a type that `read()` refuses to read, that
`fields()` emits with **no** `value` key, and that `write()` routes somewhere
other than the two settings tables — and a test that fails if any of those three
ever emits the stored bytes. That is a change to the three methods every migrated
module goes through, which is why it belongs in a round that is not also moving
three other screens.

---

## 6 · Admin paths

| What | Where |
| --- | --- |
| The eleven review settings whose cast moved | **Store → Reviews → Review Settings** |
| The star colour, the count wording and the five presets | **Reviews → Badge Themes** |
| Where the rating appears and what is in it | **Reviews → Rating Capsule** (the same screen as Badge Themes, opened on its other tab) |
| The switch, the two max-ages and the pasted `.htaccess` | **Platform → Cache** |
| The promo ticker chip that is now escaped | **Appearance → Homepage content → Other wording** (*Promo ticker chip*) |
| The routine settings whose read is now re-derived | **Catalog → Build my routine** |
| The Cash-on-delivery bounds whose read is now re-derived | **Store → Ecommerce → Pay & ship rules → Rules** |

**No control was added, removed, renamed or moved.** Every path above is where it
already was, and no setting changed the value it ships at.

---

## 7 · Measured, in Chromium, before and after

`tests/browser/lane-m3-settings-screens.mjs` produces both tables without being
edited between them — point it at a checkout of either revision and change
`KBB_M3_TAG`. It reports the key each control edits **in document order**, not
just a count, because a rewritten schema constant is exactly the change that
keeps a count and reorders it.

**Controls per screen, summed across every tab, before → after:**

| Screen | 390px | 1280px |
| --- | --- | --- |
| Review Settings | 11 → 11 | 11 → 11 |
| Badge Themes / Rating Capsule | 6 → 6 | 6 → 6 |
| Cache | 3 → 3 | 3 → 3 |

Per tab, identical too — Badge Themes 1 · Rating capsule 5. The other two screens
have no tabs. **Every control key matched in order at both widths**, all three
screens, which is the assertion that a rewritten constant needed.

**`document.documentElement.scrollWidth` equals `innerWidth` on every screen at
both widths** — 390 and 1280, three screens, before and after. No horizontal
overflow anywhere.

**Five of the six screenshots are byte-identical between the two revisions**
(same md5, same file size). The sixth, `m3-before-revbadge-390` vs
`m3-after-revbadge-390`, differs by **33 pixels** in one bounding box, x 33–330,
y 273–312 — which is the live badge preview's star row, average and count.

**That is run-to-run noise and not this change, and it was proved rather than
assumed.** The parent revision was captured a SECOND time with nothing altered:
`m3-before-revbadge-390-rerun.png` differs from the first parent capture by the
same 33 pixels, and is **byte-identical to the after capture**. A picture that
differs from itself cannot be evidence about a diff, and saying so is cheaper
than explaining a difference that is not there.

---

## 8 · Screenshots

`docs/m-module-shots/m3-*`, captured in real Chromium against a seeded SQLite
database served through this worktree.

| File | What it shows |
| --- | --- |
| `m3-before-revsettings-*.png` / `m3-after-revsettings-*.png` | Store → Reviews → Review Settings, before and after its schema constant was rewritten and its cast moved onto ModuleSchema. Byte-identical at both widths, which is the point. |
| `m3-before-revbadge-*.png` / `m3-after-revbadge-*.png` | Reviews → Badge Themes, both tabs. Byte-identical at 1280; at 390 see §7. |
| `m3-before-revbadge-390-rerun.png` | the PARENT revision captured a second time, to show the 33 pixels above are the preview and not the diff. |
| `m3-before-cache-*.png` / `m3-after-cache-*.png` | Platform → Cache, likewise byte-identical. |

---

## 9 · Suite

    KBB_WP_DB=kbb_wp_m3 vendor/bin/pest --compact

**5,866 passed · 25 skipped · 0 failed**, 44,144 assertions, 345.74s. Exit 0.

`KBB_TEST_DB` deliberately UNSET on this configuration — it is a file path there,
not a database name — and `KBB_WP_DB` named per lane, which is what CLAUDE.md
prescribes and is load-bearing rather than decorative: two exporter tests on the
default suite reach MySQL and drop every table they use.

---

## 10 · Corrections to the two documents before this one

Both found by opening files rather than by reading the pages, which is what round
2 did to round 1 and is worth keeping up.

- **Round 2 §4 names one new dialect. There are two.** *"their bool dialect is a
  **third** one"* is right, and it is not the only axis with a third value:
  `ReviewBadgeSettings::colour()` is a third HEX dialect — hash required,
  shorthand expanded, upper-cased — which no reading of the three files had
  turned up and which the corpus found on its first run. §1 has the table.
- **Round 2 §4's *"migrating them … moves three screens' payloads"* did not
  happen, and the reason is worth writing down rather than claiming a win.** The
  payloads did not move because none of these three screens is rendered from a
  schema payload at all: each draws its own controls in its own partial and
  receives a flat `settings` map. The constants were rewritten; what the screens
  receive was not. The prediction was reasonable and the measurement says
  otherwise, which is the only reason we record predictions.
- **Round 1 §6's *"Seven schema-carrying services are still on their own
  `cast()`"* is down to one.** Round 2 took five and corrected the count for
  `HomepageContent`; this round takes the last three of the eight. Only
  `MailSettings` is left, and §5 says what it needs.

---

## 11 · Out of lane — named, not fixed

- **`resources/views/store/home.blade.php` is not this lane's file.** It was
  edited anyway, for one call to `e()`, because the defect in §4 is a live
  unescaped setting on the storefront homepage and the escape has to be at the
  print site. It is one expression plus a comment; the integrator should see it.
- **The three API controllers were edited**, which is the same thing rounds 1
  and 2 did when a module's render loop moved. Only
  `ReviewSettingsApiController` changed code — its rule builder destructured the
  old positional schema — and the rules it builds are the same keys, bounds and
  messages read from a different place. `ReviewBadgeApiController` and
  `CacheApiController` needed no change at all.
- **Nothing else was found and left.** `ModuleSchema::describe()` was about to
  be on this list — it called `castBool($value)` with no mode, so it described a
  stored `'off'` as "off" for the ten modules whose cast reads it as **true**, and
  would have described `'null'` as "on" for the three this round put on
  `words+null`. It is a helper used by the guard and by no screen, so nothing was
  wrong on a page; it was fixed rather than named, because "used by nothing today"
  is how a wrong answer survives to the first screen that asks for it. §3
  mutation 8.
