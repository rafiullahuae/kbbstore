# Lane M · Phase 3 closed — one settings schema, and the last two `todo` rows

Phase 3's two remaining items, both done, with the evidence. Nothing here is
inferred from a status column: every claim below was established by running the
code and, where it is a claim about a screen, by driving the real console in
Chromium at 390px and 1280px.

---

## 1 · The per-module settings schema

### What was actually there

`ModuleSchema` already existed with three modules on it — `pay_ship_rules`,
`marketing_pixels`, `build_my_routine` — and its own header laid out the
migration path for the rest. So the item was not "build a schema". It was the
part the header called step 1, for fourteen more modules, and that part turned
out to be where the work was.

**Two things blocked it, and neither was visible from reading.**

**The schema could not express four controls the console draws.**
`normalise()` throws on an unknown type, and `TYPES` was missing `tags`
(HeaderSettings' trending words), `ids` (CartPage's recommendation rail), `skin`
(ProductStyles' card style) and `sections` (SectionDividers' section picker) —
**one use each**, which is exactly how they were missed. The header claimed the
vocabulary was "counted off the existing service schemas"; it had been counted
off the two migrated ones. `ModuleSchema::normalise(HeaderSettings::SCHEMA)` was
a fatal error.

**The seventeen modules did not merely describe their settings differently.
They answered differently.** Each module's own `cast()` was driven over an
adversarial corpus for every field it declares — 4,653 calls — and recorded
before a line was touched (`tests/Fixtures/module-cast-baseline.txt`). The
behaviour splits on **six axes**, and no two modules pick the same point on all
six:

| Axis | What differs | Who |
| --- | --- | --- |
| `max` | the text cap | 40 ProductLabels · 60 ProductStyles, AccountPanel · 120 HeaderSettings, CartPanel, MobileMenu, MobileHeader · 160 CartPage, SlimFooter · 240 NewsletterSettings |
| `blank` | an emptied box stores `''` or restores the shipped wording | `keep`: CartPage, CartPanel, Newsletter, ProductLabels, SlimFooter · `default`: AccountPanel, HeaderSettings, MobileMenu, ProductStyles |
| `invalid` | an unusable value falls back, or is refused | `default`: fourteen · `reject`: PayShipRules, MarketingPixels |
| `clamp` | a number outside its range is pulled to the bound, or refused | every `range`; never `money` |
| `hex` | `/^#[0-9a-fA-F]{6}$/` keeping the value, or `Color::isValidHex()` | `strict`: HeaderSettings, MobileMenu, ProductStyles · `repair`: the other five |
| `bool` | `(bool)`, or word-aware (`"off"` is false) | `cast`: ten · `words`: the three migrated earlier |

A single shared `cast()` would have re-cased every stored colour on three
screens, started accepting `#abc` where it had been refused, and flipped the
string `"off"` from true to false on ten modules. **So policy is declared, not
defaulted.** Each module states its own point on the six axes in its own
`POLICY` constant, and `ModuleSchema::DEFAULT_POLICY` is the strict end — a new
module has to ask for leniency in writing.

The last two axes were found by measurement, not by reading: the first draft
folded `colour` and `bool` into one arm each, and the equivalence run turned up
322 answers that had moved.

### What changed

- **`ModuleSchema`** gained the four missing types, the six policy axes, an
  `overrides` channel for an option set that lives in another registry, and one
  `cast()` that is now the single security boundary for every migrated module.
- **Ten modules** had their `cast()` body replaced by one delegation plus a
  `POLICY` constant: CartPanel, MobileHeader, NewsletterSettings,
  SectionDividers, ProductLabels, AccountPanel, MobileMenu, ProductStyles,
  HeaderSettings, SlimFooter.
- **Nine controllers** had the same byte-identical fifteen-line render loop
  replaced by one `ModuleSchema::tabs()` call.

**Not migrated, and why** — named rather than quietly skipped:

- `CartPage` and `CheckoutPage` have casts with rules of their own that are not
  policy points: CheckoutPage's `cleanTemplate()` refuses a digit in
  `rating_text` (a figure the owner typed beside the pay button), and CartPage's
  `money` clamps at zero rather than refusing. Both want their own round.
- `SecurityModule`, `HomepageContent`, `MailSettings`, `ReviewSettings`,
  `CacheSettings`, `ReviewBadgeSettings` and `SiteSearchApiController` were left
  alone: their schemas are read by screens this lane did not open, and the
  payload fixture does not cover them.

### The defect this found

`App\Support\Color::isValidHex()` accepts a hex **with or without** the leading
`#` — `/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i`. Four modules tested with it and then
stored `strtoupper($value)` unchanged:

| Module | Fields |
| --- | --- |
| `CartPanel` | `accent`, `checkout_bg`, `checkout_fg` |
| `MobileHeader` | `acct_in`, `acct_out`, `dv_colour`, `search_bg`, `search_icon`, `search_ph`, `search_text` |
| `NewsletterSettings` | `nl_bg_from`, `nl_bg_to`, `nl_btn_bg`, `nl_btn_fg`, `nl_note_colour` |
| `SectionDividers` | `colour` |

**Sixteen fields.** A colour posted as `e23a4e` was stored as `E23A4E`, which is
not a CSS colour. `SectionDividers::cssVariables()` emitted `--dv-col:E23A4E`;
CartPanel wrote its accent straight into a `background:`. The browser drops the
whole declaration — so the value saves, the admin redraws with it, and **the
shop does not change**.

`ProductLabels` hit this, diagnosed it in a comment that is still in that file
("went into `style="background:E23A4E"` — not a colour, so the badge drew with
no background and its white text vanished"), and fixed **its own copy**. The
other four never got the fix, because there were four more copies of the same
three lines and nothing tied them together. That is the argument for the shared
cast, stated as a bug rather than as taste.

### Rule 1, proved rather than asserted

- **4,653 recorded cast calls. 4,621 byte-identical.** The 32 that moved are
  exactly the sixteen fields above on the two corpus inputs that had no `#`.
- `ModuleSchemaEquivalenceTest` requires that, and separately requires that
  every exempted call **used** to store something no browser accepts and **now**
  stores the same digits as a valid colour — so the exemption cannot widen to
  cover a value that was already working.
- A third test pins that `#E23A4E` reads back as `#E23A4E` on both hex policies.
  If that ever fails, the fix moved a working value and must be reverted.
- `ModuleScreenPayloadTest` replays the recorded payloads of all **14** module
  endpoints — **482 fields** — and permits exactly one difference: the additive
  `name` key, which the three previously-migrated screens already received and
  which nothing reads (every field renderer in `admin/app.blade.php` addresses
  `f.key`).

That payload test caught a real mistake. The first version of the migration
passed each module's validation `overrides()` into the render call too, which
put `GridSkins::ALL` and the homepage-section registry into `options`, where
both screens had always received `null` — and the divider registry's rows are
`[label, description, bool, skin]`, not the `value => label` shape a select
needs, so the screen would have drawn a picker out of nested arrays.

---

## 2 · `performance` — retired, not ported

**Re-verified by running, not by trusting the row.** The plugin's version
throttles the WordPress heartbeat API and dequeues WordPress asset bloat.
Neither exists here. What the row's own description promises is already done,
unconditionally, for every page:

- `loading="lazy"` in **7** storefront templates;
- **3** `preconnect` / `dns-prefetch` hints in `layouts/store.blade.php`.

A switch could therefore only turn those **off**, which is not a feature. So the
row stays and gets a new status, `inherent`, rendered as *"Already applied to
every page — nothing to switch"* **with no switch drawn at all** — the way
`screen` rows are drawn, and for the same reason.

**Why the row stays rather than being deleted.** `module_toggles` carries a
`performance` row on every seeded install and `ModuleRegistry::REGISTRY` is the
only thing the Modules screen iterates, so deleting it makes that stored value
invisible; and it leaves the screen silently shorter, so a reader who remembers
a Performance module concludes it was lost rather than answered.

It said `todo` — drawn as **"Not ported yet"**, a promise of unbuilt work — and
named `'Its own screen'` with no console route, drawn as **"screen not built
yet"**. Both told the owner something false about a module that is never going
to exist.

---

## 3 · `address_autocomplete` — built, and honestly not finished

**Two things are missing and neither is code:**

1. **A Google Cloud project, a Places API key and a billing account.** Places
   Autocomplete is metered and billed per session. Egress from this container is
   blocked, so **not one request to Google was made or could be made**.
2. **The owner's answer to a privacy question.** See below.

**What is built:** the gate, the key setting, the consent setting, the schema,
the checkout integration, and one seam — `AddressAutocomplete::scriptUrl()` —
where Google's origin is written, which is what the tests stand in for.

### Three locks, each tested alone

The module renders nothing and sends nothing unless **all three** are true: the
switch is on, a well-formed key is stored, and consent is `yes`. Each is tested
with the other two **open**, because a module that renders nothing with all
three shut tells you nothing about any of them.

It ships **off**, with consent `unanswered`, which behaves exactly like `no`.

### The owner's decision, surfaced as a question

Google's widget posts **every character** typed into the address field to
Google, as the shopper types, before any form is submitted and whether or not
the order is ever placed. That is the same class of decision this shop already
asks about marketing pixels, so it is asked the same way — as a control, and as
**the first control in its card, above the key**. Pasting a key and agreeing to
share customers' half-typed home addresses are different acts, and this keeps
them different.

`unanswered` is its own value rather than defaulting to `no` because the two
mean different things to the person reading the screen, and a question that
defaults to an answer is a question nobody is ever shown. The shop behaves
identically under both.

### The landmine, defused

`docs/FI-PHASE3-MODULE-INVENTORY.md` called this out in advance and in as many
words:

> **The same landmine is still armed for `address_autocomplete`.** It is seeded
> `true`, its registry default is `true`, and nothing reads it. Whoever ports it
> must ship an alignment migration in the same package, or that module comes on
> by itself on every existing store.

`2027_01_05_000001_align_address_autocomplete_module_toggle.php` is that
migration, and the registry default and the seeder both now read `false`.

It matters more here than it did for `inline_validation`. That one coming on by
itself would have coloured in a form. This one, when it runs, sends what
shoppers type to a third party — and while the key and consent locks would still
have held it shut, the switch would have read **ON** on Store → Modules, which
is the shop telling its owner something untrue about what it does with customer
data.

### What remains, for whoever picks it up

1. A real Places key, and a trial against **UAE addresses specifically** —
   Places coverage of building names, villa numbers and area names like "Al Reem
   Island" is thin, and an autocomplete that cannot complete what the shopper is
   typing is worse than a plain box.
2. The owner's answer to the consent question.

The dropdown's styling was already carried across:
`resources/css/kbb/kbb-checkout.css:494` styles `.pac-container` and `.pac-item`,
which are Google's own Places-widget class names.

---

## 4 · Admin paths

| What | Where |
| --- | --- |
| Both registry rows | **Store → Modules** |
| The privacy question and the API key | **Store → Ecommerce → Checkout → Address autocomplete** |
| The ten migrated module screens | Appearance → Cart panel, Login / Register panel, Section dividers, Mobile Header, Mobile menu, Product styles, Header, Product labels · Store → Ecommerce · Content → Newsletter |

---

## 5 · Measured, in Chromium, before and after

`tests/browser/lane-m-module-screens.mjs` produces both tables without being
edited between them — point it at a checkout of either revision.

**Control counts per screen, before → after.** Identical on every screen this
lane's schema migration touched, which is rule 1 made visible:

| Screen | 390px | 1280px |
| --- | --- | --- |
| Modules | 44 → 44 | 44 → 44 |
| Cart panel | 2 → 2 | 2 → 2 |
| Section dividers | 3 → 3 | 3 → 3 |
| Mobile Header | 10 → 10 | 10 → 10 |
| Newsletter | 5 → 5 | 5 → 5 |
| Login / Register panel | 8 → 8 | 8 → 8 |
| Product styles | 7 → 7 | 7 → 7 |
| Header | 13 → 13 | 13 → 13 |
| Product labels | 13 → 13 | 13 → 13 |
| **Ecommerce → Checkout** | **14 → 16** | **14 → 16** |

The only screen that moves is the one that gained the two new controls.

**`document.documentElement.scrollWidth` equals `innerWidth` on every screen at
both widths** — 390 and 1280, ten screens, before and after. No horizontal
overflow anywhere.

**The two rows, as rendered:**

| Row | Before | After |
| --- | --- | --- |
| Performance & Speed | "Not ported yet" · inert switch drawn · "Its own screen — not built yet" | "Already applied to every page — nothing to switch" · **no switch drawn** · "No settings" |
| Address autocomplete | "Not ported yet" · inert switch · old one-line description | off by default · **real switch** · what it needs, and an Open button reading **Store → Ecommerce → Checkout** |

---

## 6 · Out of lane — named, not fixed

- **`ModuleSchema::fields()` now emits `name` beside `key` on nine screens that
  did not receive it.** Additive, and nothing reads it — but it is a payload
  change and it is recorded here rather than buried.
- **`SqlDialectGuardTest > it drives or explicitly excuses…` fails on the
  integration branch before this lane touched anything**, on
  `admin-api/post-editor-load/{id}`. That is the article editor from an earlier
  round, not this work.
- **Seven schema-carrying services are still on their own `cast()`** — listed in
  §1. Each is a smaller version of this round.

  **Round 2 took five of them and is written up in
  `docs/M-PHASE3-SETTINGS-SCHEMA-ROUND-2.md`; round 3 took the last three of the
  eight — `docs/M-PHASE3-SETTINGS-SCHEMA-ROUND-3.md` — so this count is now ONE,
  `MailSettings`, and that round's §5 is the specification for it.** Two corrections to what is said
  above, both found by opening the files rather than by reading this page:
  `HomepageContent` was never on its own `cast()` — it already read and wrote
  through ModuleSchema and had simply not been added to the guard; and the last
  sentence is not true of the three `App\Support\*Settings` classes or of
  `MailSettings`, which are a different schema shape with a third boolean
  dialect and two types the schema has never had. Round 2 §4 names the blocker
  for each.

---

## 7 · Screenshots

`docs/m-module-shots/`, all captured in real Chromium against a seeded SQLite
database served through this worktree, by
`tests/browser/lane-m-module-screens.mjs`. `before-*` are the same script run
against the parent revision.

| File | What it shows |
| --- | --- |
| `before-perf-row-1280.png` / `after-perf-row-1280.png` | Store → Modules, the Performance row. Before: an inert switch, "Not ported yet", "Its own screen — not built yet". After: no switch at all, "Already applied to every page — nothing to switch", "No settings". |
| `before-perf-row-390.png` / `after-perf-row-390.png` | the same on a phone. |
| `before-addr-row-1280.png` / `after-addr-row-1280.png` | Store → Modules, the Address autocomplete row. Before: "Not ported yet" and an inert switch. After: a real switch, off by default, and an Open button reading **Store → Ecommerce → Checkout**. |
| `before-addr-row-390.png` / `after-addr-row-390.png` | the same on a phone. |
| `aa-card-1280.png` / `aa-card-390.png` | the new card on Store → Ecommerce → Checkout: the privacy question first, shipped at "Not decided yet — nothing is sent", with the API key below it. |
| `before-ecommerce-checkout-*.png` / `after-ecommerce-checkout-*.png` | the Checkout tab going from 14 controls to 16, at both widths. |
| `before-cartpanel-*.png` / `after-cartpanel-*.png` | Appearance → Cart panel, before and after its cast and render loop moved onto ModuleSchema — the screen the colour bug was live on. Identical, which is the point. |
| `before-dividers-1280.png` / `after-dividers-1280.png` | Appearance → Section dividers, likewise identical. |
| `before-modules-390.png` / `after-modules-390.png` | the whole Modules screen on a phone, both rows in context. |
