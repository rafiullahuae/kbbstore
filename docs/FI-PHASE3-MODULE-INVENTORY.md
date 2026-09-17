# Lane FI · Phase 3's remaining ten, re-verified — and `inline_validation` ported

The Master Plan's §2, *"The 10 still to port, as at 2.56.1 — every one checked,
not assumed"*, is stale. It was written at 2.56.1; this branch is cut from
2.60.203, and several lanes have shipped module work in between. **Seven of the
ten are done.** This file is the corrected list, with the evidence for each, so
the integrator can merge it into the plan.

Every verdict below was established by **running the code** — flipping the
switch the owner would flip and fetching the page a shopper would fetch, against
a seeded SQLite database served through `public-web-root` at a real viewport.
Nothing here is inferred from a registry row, because "not built" meaning "built
and not wired" is a fault this project has a whole phase for.

---

## 1 · The corrected inventory

| # | Module | Plan said (2.56.1) | Verdict now | Evidence |
| --- | --- | --- | --- | --- |
| 1 | `abandoned_cart` | blocked on mail | **DONE** — `live`, off by default | Opt-in box present on `/cart/` with a basket and the owner's wording written; **0** occurrences with the switch off. `Services\CartRecovery` reads the key; `cart_recoveries` and `mail_deliveries` exist and `Services\OutboundTick` sends. |
| 2 | `back_in_stock` | blocked on mail | **DONE** — `live`, off by default | Notify-me form present under Add to cart on a sold-out product with the switch on; **0** with it off. `Services\StockAlerts` reads the key; `stock_alerts` table exists. |
| 3 | `legal_notice` | blocked on missing source (kbb-theme) | **DONE** (Lane EH) — `live`, on by default, renders nothing unwritten | Probe text appears twice on `/checkout/` with the switch on (desktop aside + mobile order block), **0** with it off. |
| 4 | `mega_menu` | done at 2.60.12 | **DONE, and the gate is now real** (2.60.199) | `/` is **89,066** bytes with it on and **71,683** with it off — the panels and the phone menu's sections genuinely come off. This is the row the brief flagged as "marked live while the panels rendered regardless"; that was true until 2.60.199 and is not true now. |
| 5 | `brands` | blocked on the `/brands/` URL decision | **DONE** (Lane EH, 2.60.109) — `live`, on by default | `/korean-skincare-brands/` returns **200** with the switch on and **404** with it off; the home page's brand links go from 2 to 0. |
| 6 | `seo_engine` | "a working form writing into a void" | **DONE** — `live`, on by default, and it reads its settings | **8** `og:` / `canonical` / `ld+json` matches in the home page head with it on, **0** with it off. `seo_home_title` set to a probe string appears **3 times** on the page, so the form is no longer writing into a void. |
| 7 | `product_sorting` | "a real build — a position field, a drag-reorder admin UI" | **DONE** — `live`, off by default, screen at Catalog → Reorder | `/shop/` returns a genuinely different product order with the switch on and off (first item changes). |
| 8 | **`inline_validation`** | blocked on missing source (kbb-theme) | **PORTED IN THIS LANE** — see §2 | |
| 9 | `address_autocomplete` | blocked on missing source **and** a Google Places key | **STILL NOT PORTED**, and it needs an owner decision, not a developer — see §3 | No `moduleEnabled('address_autocomplete')` call exists anywhere in `app/`, `resources/` or `routes/`. |
| 10 | `performance` | "doesn't port at all" | **STILL NOT PORTED, and the plan is right** — see §3 | No reader anywhere. The work it describes is already done unconditionally: 3 `preconnect`/`dns-prefetch` tags in `layouts/store.blade.php`, `loading="lazy"` in 7 storefront templates. |

### The one thing the old list got backwards

The plan's closing line — *"nothing here is safely buildable without either new
information from Rafi or a properly scoped session of its own"* — has been
overtaken by events in seven of ten cases. Two of the three "blocked on mail"
and "blocked on a missing source" groups turned out to be buildable the way
`legal_notice` was built: not by copying the plugin, which has nothing to copy,
but by implementing the module's *description* against what this app already
has.

---

## 2 · What this lane ported: `inline_validation`

**"Live green/red validation as the customer types."** The plugin's own entry is
a settings link pointing at **kbb-theme**, which was never supplied — so this is
not a copy. What made it a port rather than an invention is that the *contract*
was already in the markup and nothing read it.
`resources/views/components/checkout/field.blade.php` writes WooCommerce's
`validate-required`, `validate-email`, `validate-state` and `validate-phone`
classes onto every checkout row, and that component's own header says so:

> "Nothing in this repo's CSS or JS reads `validate-*` or `required_field`, but
> they are part of the markup the live theme serves."

This module is that reader, and it writes back Woo's own `woocommerce-invalid`
and `woocommerce-validated`, not a second vocabulary for the same idea.

### Files

| File | What |
| --- | --- |
| `app/Support/InlineValidation.php` | the reader — the module gate and the three settings, `null` when off |
| `resources/views/partials/checkout/inline-validation.blade.php` | the style, the script and the config island; **renders the empty string when off** |
| `resources/views/store/checkout.blade.php` | one glued `@include` (see below) |
| `app/Http/Controllers/Admin/EcommerceApiController.php` | a "Live field validation" section and three fields on the Checkout tab |
| `app/Services/ModuleRegistry.php` | the row: `todo` → `live`, no screen → `Store → Ecommerce → Checkout`, default `true` → `false` |
| `app/Services/Translation/InterfaceStrings.php` | four keyed sentences |
| `database/seeders/ModuleSeeder.php` | `inline_validation` seeded `false` |
| `database/migrations/2026_11_14_000000_clear_caches_inline_validation.php` | the compiled checkout must go, or the switch saves and does nothing |
| `database/migrations/2026_11_14_000001_align_inline_validation_module_toggle.php` | turns off the row the seeder wrote `true` while nothing read it |
| `tests/Feature/ModuleInlineValidationTest.php` | 15 tests, each proven to fail without its change |
| `tests/browser/checkout-inline-validation.mjs` | the half that begins at the first keystroke — see §5a |

### Off means off, measured in bytes

`/checkout/` rendered with the module off is **byte-identical** to the same page
rendered from the template as it stood before this lane — the only differences
are the CSRF token and the `csrf` value inside `window.KBB`, which differ
between any two renders of the same file. `StorefrontEnglishUnchangedTest`
enforces the same thing from BASE_COMMIT and is green.

That took two corrections worth recording, because both would have shipped as
"off" while putting bytes on a live page:

1. **`@push` captures the newlines between its directives.** Writing the include
   across four lines pushed two blank lines onto the scripts stack whether or
   not the partial rendered anything.
2. **Blade's compiled `@include` ends in `?>`, and PHP eats the newline that
   follows a closing tag.** So an include glued to the *end* of an existing line
   silently removed a newline that used to be there. The include is therefore at
   the *start* of the Marketing Pixels line, where it is followed by `{!!`
   rather than by a newline, and the page is unchanged in both directions.

### The landmine this port defused

`ModuleSeeder` has always seeded `inline_validation => true`, copied from the
plugin along with the rest of the checkout group, while **nothing read the key
at all**. `moduleEnabled()` returns the stored row whenever one exists and only
falls back to the registry default when it does not — so adding the reader
without `2026_11_14_000001` would have switched live field marking **on**, on
the one form the shop is paid through, for every install the seeder has ever
run against, at the moment the package applied.

`2026_11_14_000001` is the mirror of `2026_11_10_000000` (mega_menu), pointed
the other way, and it differs from it in one respect: an **absent** row is left
absent, because absent already means off here.

> **The same landmine is still armed for `address_autocomplete`.** It is seeded
> `true`, its registry default is `true`, and nothing reads it. Whoever ports it
> must ship an alignment migration in the same package, or that module comes on
> by itself on every existing store. This is the single most important sentence
> in this file for the next lane.

### The three settings, and what each does to the page

| Key | Control | Proven by |
| --- | --- | --- |
| `checkout_validate_when` | `blur` (default) / `type` | typing `abc` into the email field without leaving it: `blur` → 0 marks, `type` → 1 invalid row + 1 hint |
| `checkout_validate_ok` | mark correct fields too (default on) | off → `validated: 0` while `invalid: 1` |
| `checkout_validate_hint` | say what is wrong (default on) | off → `hints: []` while the red mark stays |

Default is **`blur`**, not the plugin blurb's "as they type", because "as they
type" judges an email address wrong at the first letter and stays red for the
next twenty keystrokes. A field already marked still updates live as it is
corrected, so a correction is confirmed instantly and a first attempt is never
interrupted. Which one a shop wants is the owner's to choose, which is why it is
a control rather than a constant.

The module ships **off**, against the plugin's own checkout-group default. The
rule `seo_engine`, `brands` and the order emails each use is that the default is
measured against what this store does *without* the switch — and without it this
checkout marks nothing at all, so `true` would mean applying a package and
finding the payment form had started colouring itself in, chosen by nobody.

### Accessibility

A judged control carries `aria-invalid`; a hint is a real element joined to its
control by `aria-describedby` and announced with `role="status"` (polite, so it
does not interrupt typing). The tick and cross are `aria-hidden` — the same
information a second time. Colour is never the only signal.

---

## 3 · What is NOT ported, and what it would take

### `address_autocomplete` — **needs an owner decision, not a developer**

The theme's stylesheet already carries the dropdown's look:
`resources/css/kbb/kbb-checkout.css:494` styles `.pac-container` and `.pac-item`,
which are Google's own Places-widget class names. So the *styling* half was
carried across and only the script and the key are missing — this is closer to
finished than the plan implies.

What is missing is not code:

1. **A Google Cloud project, a Places API key and a billing account.** Places
   Autocomplete is metered and billed per session. Nobody has supplied one.
2. **A decision about sending shoppers' keystrokes to Google.** The widget posts
   every character typed into the address field to Google as the shopper types,
   before any form is submitted. On a store that already asks for consent about
   marketing pixels, this is the same class of decision and should be made the
   same way, not assumed.
3. **A decision about the UAE.** Places coverage of Emirati addresses —
   building names, villa numbers, area names like "Al Reem Island" — is thin, and
   an autocomplete that cannot complete the thing the shopper is typing is worse
   than a plain box. Worth a trial with a real key before committing.

**What a developer would then do**, in one session: an API-key field on
Store → Ecommerce → Checkout (`secret` type, so it is not echoed back), a gated
inline script loading `maps.googleapis.com/maps/api/js?libraries=places` only
when the module is on *and* a key is stored, `componentRestrictions: {country:
'ae'}`, and a `place_changed` handler that fills `billing_address_1`,
`billing_city` and `billing_state` from the returned components. Plus the
alignment migration in the warning above. Roughly the size of this lane's port.

### `performance` — **the plan is right; do not port it**

Re-checked rather than taken on trust. The plugin's version throttles the
WordPress heartbeat API and dequeues WordPress asset bloat; neither exists here
to strip out. What performance work makes sense in this app is already done,
unconditionally: three `preconnect` / `dns-prefetch` tags in
`layouts/store.blade.php`, and `loading="lazy"` across seven storefront
templates. A switch here could only make the shop *slower*, which is not a
feature.

**The row is nevertheless wrong and should be corrected.** It says `todo` —
which the Modules screen renders as "Not ported yet", i.e. a promise — and names
`'Its own screen'` with no console route, which renders as "Its own screen —
screen not built yet". Both tell the owner something false about a module that
is never going to exist. That is the identical pair of faults the `brands` and
`product_sorting` rows carried until they were corrected.

**This needs a decision the status field cannot currently express**, and it is
the integrator's or the owner's, not this lane's: either add a status meaning
*does not apply to this app* and render it as that, or remove the row. Removing
it is not free — `module_toggles` carries a `performance` row on every seeded
install, and `ModuleRegistry::REGISTRY` is what the screen iterates, so a
removed row becomes a value nobody can see. Adding a status is the smaller
change and matches how `screen` was added for the Media Library.

---

## 4 · Admin-shell blocks: **none, and that is the point**

`resources/views/admin/app.blade.php` is **untouched** by this lane, and there
is no block to apply.

The three controls are fields on `EcommerceApiController::schema()`, which the
console draws generically — one section entry and three field entries produce
three real controls with no change to the shell. This is the path
`legal_notice` established and that schema's own header calls "a deliberate step
towards the Phase 3 schema renderer". Confirmed by driving the real console in
Chromium: Store → Ecommerce → Checkout now shows **13** settings (was 10), with a
"Live field validation" card carrying a select and two switches.

**No new route either**, so the only migration that adds one is not needed:
`/admin-api/ecommerce` has existed for releases and
`AdminCapabilities::RULES` already carries `['*', 'admin-api/ecommerce',
'store.settings']` — the `*` covers the write as well as the read, which is the
writes-before-reads rule already satisfied. The package still ships
`2026_11_14_000000_clear_caches_inline_validation` because it adds a Blade
partial the checkout includes, and a stale compiled checkout would leave the new
switch saving and doing nothing.

---

## 5 · Out of lane — named, not fixed

### Store → Ecommerce refuses to save **any empty text box**

Reproduced against the real endpoint as a real owner:

```
POST /admin-api/ecommerce  {"settings":{"checkout_coupon":""}}            → 422 "“Suggested coupon code” is not a valid value."
POST /admin-api/ecommerce  {"settings":{"checkout_coupon":"GLOW"}}        → 200 {"ok":true,"saved":1}
POST /admin-api/ecommerce  {"settings":{"checkout_legal_text":""}}        → 422 "“Legal notice” is not a valid value."
POST /admin-api/ecommerce  {"settings":{"cart_recovery_optin_label":""}}  → 422 "“Opt-in text” is not a valid value."
POST /admin-api/ecommerce  {"settings":{"minicart_promo":""}}             → 422 "“Mini-cart promo line” is not a valid value."
```

**Cause.** Laravel's global `ConvertEmptyStringsToNull` middleware runs before
the controller, so an empty box arrives as `null`.
`EcommerceApiController::cast()`'s default arm is
`is_string($raw) && mb_strlen($raw) <= 2000 ? $raw : null`, which answers `null`
for `null`, and `save()` treats `null` as "not a valid value" and returns 422.
`ReviewSettingsApiController` documents this exact middleware trap in its own
header and works around it with a `nullable` rule; this controller never got the
same treatment.

**Why it matters more than it looks.** The console posts the whole tab at once,
and on a fresh store `checkout_coupon`, `checkout_coupon_text`,
`checkout_legal_text`, `minicart_promo`, `cart_coupon_text` and all four
`cart_recovery_*` boxes ship empty. So:

- the **Cart** and **Checkout** tabs cannot be saved at all on a store that has
  not filled in every text box on them;
- `legal_notice`'s documented "clear the box to say nothing" behaviour — pinned
  in `ModulePortsOnOffTest` at the PHP level — is **unreachable through the
  screen**;
- `abandoned_cart`'s four boxes cannot be filled in one at a time: writing the
  opt-in text and saving is refused on the still-empty subject.

`save()` writes as it goes and returns on the first failure, so a refusal also
leaves the tab half-applied — which the controller's own header already
acknowledges as pre-existing.

**The fix is one line** in `cast()`:

```php
default => $raw === null || (is_string($raw) && mb_strlen($raw) <= 2000) ? (string) $raw : null,
```

It is **not applied here.** It changes the save semantics of nine fields across
five tabs that this lane did not add, in a file other lanes are working in. It
wants its own change with its own test, and that test should post an empty box
through the endpoint and then read the storefront — not assert the endpoint
returns 200.

### `ModuleRegistrySettingsPathTest`'s count moved

`expect($checked)->toBe(35)` → `36`, because `inline_validation`'s row now names
a screen where it named none. The comment above it records why.

---

## 5a · The browser report, on and off

`tests/browser/checkout-inline-validation.mjs`, following the convention of
`tests/browser/checkout-hidden-rows.mjs`: it reports, it does not judge, and
the same script produces both tables without being edited between them. The
Pest suite on this project has no browser in it, so this is the half that
begins at the first keystroke.

```
KBB_IV_URL=http://127.0.0.1:8941/checkout/ KBB_IV_CART=<kbb_cart cookie> \
KBB_IV_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
node tests/browser/checkout-inline-validation.mjs
```

**Module ON** (`island.on: true`):

| Case | Row classes after the interaction | aria-invalid | Hint |
| --- | --- | --- | --- |
| empty required address, left | `validate-required woocommerce-invalid` | true | Please fill this in. |
| optional phone, left empty | `validate-phone` | — | — |
| optional phone, a real number | `validate-phone woocommerce-validated` | — | — |
| optional phone, not a number | `validate-phone woocommerce-invalid` | true | Please enter a phone number, or leave this empty. |
| delivery notes, no `validate-*` of its own | `kbb-note` | — | — |
| email that is not an address | `validate-required validate-email woocommerce-invalid` | true | Please enter an email address, like you@email.com. |
| the same email, corrected **without leaving it** | `validate-required validate-email woocommerce-validated` | — | — |

**Module OFF** (`island.on: false`), same script, same keystrokes:

| Case | Row classes after the interaction | aria-invalid | Hint |
| --- | --- | --- | --- |
| *every one of the seven* | the `validate-*` classes the markup has always carried, and nothing else | — | — |

### Two defects this found, both of which looked right in the server output

1. **A row with no `validate-*` class was judged anyway.** Three rows on this
   checkout carry none — "Delivery notes" is one — and with no rule to break
   they came back clean and were given a green tick for being empty.
2. **A row whose only rule had no opinion got the same tick.** The optional
   phone carries `validate-phone`, and `phone` answers "no opinion" about an
   empty box; "no opinion" was being read as "approved".

Both are the shop congratulating a shopper for doing nothing, and both make the
tick beside a field they really did fill in mean less. A row is now marked good
only when a rule actually said yes, and a row that declares no rules is not
touched at all. Neither would have been caught by anything the server sends,
which is why the script exists.

---

## 6 · Screenshots

`docs/fi-module-shots/`, all captured in real Chromium against a seeded database
served through `public-web-root`:

| File | What it shows |
| --- | --- |
| `01-modules-screen-inline-validation-off.png` | Store → Modules. "Inline field validation — off by default" with a real switch in the off position and an Open button reading **Store → Ecommerce → Checkout**, directly beneath "Address autocomplete — **Not ported yet**" with its inert grey switch. The difference between the two rows is the whole of this lane. |
| `02-ecommerce-checkout-live-field-validation.png` | Store → Ecommerce → Checkout, the new "Live field validation" card: a select and two switches, drawn generically with no change to the admin shell. |
| `03-checkout-module-on.png` | `/checkout/` with the module on, mid-fill: `not-an-email` marked red with a cross and the hint beneath it, `Rafi Ullah` marked green with a tick. |
| `04-checkout-module-off.png` | the same page, the same keystrokes, module off — no marks, no hint, no style block. |
| `05-checkout-mark-correct-fields-off.png` | "Mark correct fields too" off: the fault is still marked, the correct field is not. |
| `06-checkout-say-what-is-wrong-off.png` | "Say what is wrong" off: both marks stay, the sentence goes. |
