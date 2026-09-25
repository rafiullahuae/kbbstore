# Lane P1 · "Live editing of homepage sections" — what was already there, and the half that was not

Phase 15's last unchecked line. Nothing below is inferred from a status column:
every claim about behaviour was established by running the code, every claim
about a screen by driving Chromium at 390px and 1280px, and every mutation was
applied, run, and its output copied.

---

## 1 · What already exists, from the code

The brief's warning was right and worth repeating: **most of this item is
built**. Three lanes have been over this ground since Phase 15 opened, and the
page, the registry and the content screen are all real.

### The homepage is assembled from a registry, and the registry decides everything but the words

| | Where | What it decides |
| --- | --- | --- |
| The sections | `app/Services/HomepageSections.php` · `REGISTRY` | **17** sections, in template order: hero, delivery, ticker, categories, bundles, recommended, routine, quiz, brands, spotted, bestsellers, flash, blog, about, reviews, trust, newsletter |
| Whether one renders | `HomepageSections::hidden()` / `classFor()` | a `desktop` and a `mobile` flag each, applied as `d-off` / `m-off` CSS classes. Off for **both** and the section is not rendered at all, so it costs no queries either |
| Where one renders | `HomepageSections::orderStyle()` + `classFor()` | CSS `order` on a flex column, emitted inline by PHP. **Gated**: a shop on the template's own order emits no style element and no class at all |
| Which card template a grid uses | `skinFor()` → `App\Support\GridSkins::ALL` | 28 skins; four sections carry one |
| Two rows CSS cannot move | `HomepageSections::NESTED` | `delivery` and `ticker` are drawn **inside** the hero's `<section>`, so `order` on them is inert. `settle()` puts them back behind their host on every read, and the screen draws no arrows on them |
| The presets | `app/Services/HomepageLayouts.php` | Signature · Conversion · Editorial · Boutique, each writing order, visibility and skins in one move |
| Where the settings live | `settings.homepage_sections` (one JSON blob), `settings.homepage_layout` | written by `admin-api/homepage` |

`resources/views/store/home.blade.php` renders all 17 and calls
`$sections->classFor()` on every one, which is why ordering needed no per-section
edit.

### `HomepageContent` was already on `ModuleSchema`, and has been since Lane FO

`app/Services/HomepageContent.php` reads and writes through
`ModuleSchema::read()` / `::write()` / `::fields()` / `::tabs()`. It owns:

- `home_banners` — the hero slider, as a **repeater** of `SLIDE_SCHEMA`
  (10 ModuleSchema fields per slide), each slide cast field by field through
  `ModuleSchema::cast()`;
- `home_ticker` and `about_text` — flat fields on `SCHEMA` / `TABS`;
- `TOKENS` — `{brands}` and `{free_from}`, resolved from the counters the rest
  of the page already uses;
- `CSS` — the two gradient shapes, one definition read by the storefront **and**
  by the console's preview.

Round 2 of the settings-schema series put its flat copy under
`ModuleFrameworkGuardTest`. Round 3 fixed the unescaped `home_ticker` chip.
**There was nothing left to migrate here**, and this lane migrated nothing.

### What the admin already offers for the homepage, screen by screen

| Screen | Where it lives | What it already does |
| --- | --- | --- |
| **Appearance → Homepage** | `paintHomepage()` in `resources/views/admin/app.blade.php` | Demo-content switch · four layout presets with a wire-frame drawn from the settled order · 17 section rows, each with ↑/↓ (grouped into 15 blocks so the hero band moves whole), Desktop and Mobile switches, and a skin picker on the four grid sections · Discard / Save |
| **Appearance → Homepage content** | `resources/views/admin/partials/homepage-content-screen.blade.php` (676 lines) | Hero tab: add / remove / reorder slides, every control drawn from the server's ModuleSchema field payload, **a live per-slide preview that follows the keystroke** and is built from the same `CSS` string the storefront prints · Other wording tab: the ticker chip and the About paragraph · refusals reported per field |

So: the controls exist, the registry is real, the vocabulary is shared, and one
screen already previews live. **Four-fifths of the item was built.**

### The one thing that was not

**Both screens publish straight to the live shop, and neither can show you the
page.** Appearance → Homepage — seventeen rows, thirty arrows, thirty-four
switches, four skin pickers — had no preview of any kind. The only way to see
what an arrow or a device switch did was to press **Save changes**, which is
live on the shop real visitors are on, and then open the storefront.

The controls were never what "live editing" was missing here. The **look** was.

---

## 2 · What this lane built

### a · `POST /admin-api/homepage/preview` — the real homepage, from an arrangement nobody has saved

`App\Http\Controllers\Admin\HomepageApiController::preview()`. It validates the
posted rows through the **same** rules and the same payload builder `save()`
uses (both now call `sectionRules()` and `payloadFor()`), builds a
`HomepageSections::proposing()` reader over them, and renders
`store/home.blade.php` **through `HomeController` itself**.

Three properties, each pinned rather than promised:

1. **It is the page, not a drawing of the page.** A preview of the configuration
   the shop is already on is **byte-identical to `GET /`**, CSRF token aside.
   Nothing weaker would do: this console has already shipped one preview that
   drew a page that never existed — `hpWire()`, drawing each preset's *stored*
   sequence rather than the settled one — and a similarity assertion would have
   passed for that too.
2. **It equals what saving would produce.** The picture is rendered off an
   unsaved proposal; the same rows are then really saved and `/` fetched; the
   two documents match. Different code paths, different requests.
3. **It writes nothing.** The settings row is byte-identical afterwards, none of
   the four cache keys the two writing endpoints forget is touched, and the
   reader itself **throws** on `save()` — a preview instance that could also
   publish is the one failure this feature must not have.

**The request is swapped for the length of the render**, and that is the whole
trick. `layouts/store.blade.php` reads `request()->getPathInfo()` to decide
whether it is on the home page — the canonical URL, the SEO type and the
`noindex` flag all hang off it — and `partials/mobile-chrome.blade.php` marks
its home tab from `request()->path()`. Rendered from an admin-api URL without
the swap, the preview differs from the shop in its `<head>` and in one
highlighted tab. Both are put back in a `finally`.

### b · The section row gets a schema, so the preview and the save share one cast

`HomepageSections::SECTION_SCHEMA` declares the three controls a row carries —
`desktop` and `mobile` as `bool`, `skin` as ModuleSchema's `skin` type with
`GridSkins::ALL` supplied through the `overrides` channel — and
`SECTION_POLICY` states this screen's point on the axes in writing:

    ['bool' => 'cast', 'invalid' => 'default']

`all()`, `save()` and the preview now go through one `castRow()`. Before this
there were **four copies of three rules**: a pair of `(bool)` casts in `all()`,
the same pair in `save()`, and `GridSkins::exists($s) ? $s : $default` written
out twice. That is exactly the arrangement `docs/M-PHASE3-SETTINGS-SCHEMA.md` §1
measured the cost of, and it mattered more here because a **third** reader had
just arrived: the only thing that makes an unsaved preview worth looking at is
that it answers the way the save would, and two copies of the rules make that a
promise rather than a fact.

`order` is deliberately **not** in the schema. It is not a control — nothing on
the screen types it, the console posts a sequence and the server numbers it by
position, and `settle()` rewrites it on every read.

**Why `bool => 'cast'` and not the word-aware dialect.** `'off'` is TRUE to
`(bool)` and FALSE to `ModuleSchema::castBool()`. This table is written by a
browser's JSON and by the four import paths round 3 names, so a stored `'off'`
is not hypothetical, and reading it the other way switches a section off on a
shop that had it on. Mutation 4.

### c · The screen

`Appearance → Homepage`, a **Preview** card between the Layouts row and the
Sections list:

- **Preview this arrangement** renders the rows as they stand;
- **Desktop · 1280** / **Mobile · 390** give the document a real viewport of that
  width — `d-off` and `m-off` are media queries in the storefront's own
  stylesheet, so the only honest way to show what a device switch does is to let
  that stylesheet answer;
- the line under the picture stops claiming to be current the moment a row
  moves.

Four decisions worth naming:

- **The frame is sandboxed without `allow-scripts`.** `allow-same-origin` alone
  lets the document load the shop's stylesheets, fonts and images and run not one
  line of script. The two flags that together defeat a sandbox are never both
  set, and this is a preview of layout rather than of behaviour.
- **The document is assigned, never interpolated.** Eighty-odd kilobytes of the
  shop's own HTML inside a JavaScript template literal is one backtick or one
  `${` away from terminating the literal and taking the whole screen's paint with
  it, and neither character is escaped by an attribute escaper. `paintHomepage()`
  sets `.srcdoc` on the element after the paint.
- **Freshness is derived, not flagged.** A `fresh` flag has to be cleared by
  every edit handler on the screen, and the one that forgets leaves the note
  claiming a stale picture is current. `hppvFresh()` compares what was rendered
  with what is on screen, so it cannot be forgotten and it gets "moved it and
  moved it back" right for nothing.
- **It is sized with `calc()` from one declared scale**, per rule 4. Nothing
  measures layout: `--hppv-s` is a literal per breakpoint and the frame's height
  is `calc(540px / var(--hppv-s))`, so the stage and the page inside it cannot
  come to disagree the way two hand-kept numbers would.

---

## 3 · Where it sits in the admin

| What | Where |
| --- | --- |
| The Preview card, the two device buttons and the button that draws it | **Appearance → Homepage → Preview** (between *Layouts* and *Sections*) |
| The rows it is a preview of | **Appearance → Homepage → Sections** |
| The words on the page, unchanged by this lane | **Appearance → Homepage content** |

**No control was added to, removed from, renamed or moved on the Sections list,
and no setting changed the value it ships at.** The preview card is additive and
writes nothing; a shop that never presses the button is byte-identical.

---

## 4 · Measured, in Chromium, before and after

`tests/browser/lane-p1-homepage-preview.mjs` produces both tables without being
edited between them — point it at a checkout of either revision and change
`KBB_P1_TAG`.

**Appearance → Homepage, before → after:**

| | 390px | 1280px |
| --- | --- | --- |
| section rows | 17 → 17 | 17 → 17 |
| ↑/↓ arrows | 30 → 30 | 30 → 30 |
| Desktop/Mobile switches | 34 → 34 | 34 → 34 |
| `document.documentElement.scrollWidth` | 390 → 390 | 1280 → 1280 |
| `window.innerWidth` | 390 | 1280 |
| Preview panels | **0 → 1** | **0 → 1** |

Thirty arrows for seventeen rows is correct and is Lane FR's work: `delivery`
and `ticker` are drawn inside the hero and get a sentence instead of arrows.

**`scrollWidth === innerWidth` at both widths, on arrival, after a preview is
drawn, and after switching the frame to Mobile and back** — no horizontal
overflow anywhere, which is the one thing a 1280px-wide document inside a 390px
column could plausibly have caused.

**The previewed document's own viewport, read from inside the frame:**

| device button | `frame.contentDocument.documentElement.clientWidth` |
| --- | --- |
| Desktop · 1280 | **1280** at both console widths |
| Mobile · 390 | **390** at both console widths |

So the page inside is laid out at a real device width rather than at the panel's,
at a console width of 390 as well as 1280. `sandbox` reads `allow-same-origin`
and nothing else.

**The line under the picture, verbatim, in the three states it has:**

> Nothing is saved by previewing, and nothing on the shop moves until you press Save changes. Laid out at 1280px and drawn reduced to fit this column.

> This is the arrangement below, rendered by the storefront itself — not a diagram of it. Nothing is saved; the shop still shows what it showed before. Laid out at 1280px and drawn reduced to fit this column.

> The rows below have changed since this picture was drawn. Press Preview again to catch it up. Laid out at 1280px and drawn reduced to fit this column.

### The shop itself did not move

Fetched rather than reasoned about. `/` was served from the preview shop, then
`app/Services/HomepageSections.php` was rolled back to its parent revision and
`/` fetched again:

    88,667 bytes both times
    diff  →  one line, the `csrf` field inside window.KBB

`StorefrontEnglishUnchangedTest` re-proves the same thing from the other
direction, across every storefront page, and stays green.
**`EnglishRenderWalk::BASE_COMMIT` is not moved by this lane.**

---

## 5 · Mutations, actually run, with what each printed

Eleven. Each was applied, `HomepagePreviewTest` and `HomepageSectionSchemaTest`
run together (27 passed at baseline), the output copied, the change reverted.

| | Mutation | Result |
| --- | --- | --- |
| 1 | `renderHome()` drops `getSchemeAndHttpHost()` | **1 failed** — *it writes its asset tags against the host the console is really served from* |
| 2 | the Request swap removed entirely | **2 failed** — *renders the shop's own homepage byte for byte*, *draws the arrangement that has not been saved* |
| 3 | `castRow()` uses `array_key_exists()` instead of `??` | **2 failed** — *answers every value on read exactly as the hand-written coercion did*, *stores every value on write…* |
| 4 | `SECTION_POLICY['bool']` `cast` → `words` | **3 failed** — both equivalence cases plus *picks the plain boolean dialect in writing* |
| 5 | `SECTION_POLICY['invalid']` `default` → `reject` | **5 failed**, including *draws a hostile grid skin as the section's own default* |
| 6 | `all()` ignores the proposal | **4 failed** — every case that asserts the preview draws what was posted |
| 7 | a preview reader may write after all | **1 failed** — *refuses to write through the reader a preview is built on* |
| 8 | the skin is read off the row instead of through the cast | **6 failed**, including the byte-identity case |
| 9 | `hppvFresh()` always says fresh | **1 failed** — *derives whether the picture is current rather than being told* |
| 10 | the frame gains `allow-scripts` | **1 failed** — *sandboxes the frame without allowing it to run the shop's scripts* |
| 11 | the desktop scale step `.24` → `.25` | **27 passed — correctly.** No test asserts the scale, because this suite has no browser and a number nothing can check is a number that would only ever fail for the wrong reason. The scale is measured in §4 instead |

**Mutation 3 is the one that found a live defect rather than confirming one.**
Both readers this lane replaced wrote `$row['desktop'] ?? true`, so a stored
`null` has always meant **shown**. The obvious migration — `array_key_exists()`
plus the field default — hands `cast()` a literal null, and `(bool) null` is
false. Thirty-four rows went dark in that test's own output before `castRow()`
was written with `??`.

**Mutation 1 is the second.** It was not a mutation first: the preview really did
write `http://localhost/build/...` on a shop served from `127.0.0.1:8977`, which
Chromium reported as three `ERR_CONNECTION_REFUSED` and drew as the homepage in
Times New Roman on a white page. It is invisible to a suite whose `APP_URL` and
request host are both `localhost`, which is why the test for it names a host of
its own.

---

## 6 · What the integrator has to do

**One require line**, in `routes/web.php`, inside the admin-api group, directly
under the three homepage routes already there — the header of
`routes/homepage-preview-admin.php` carries it verbatim:

```php
require __DIR__.'/homepage-preview-admin.php';
```

**And one `clear_caches_*` migration in the package**, per CLAUDE.md: for the new
route, and for the compiled view of `resources/views/admin/app.blade.php`, which
this lane also changed. One migration covers both. Without it the Preview button
posts to a 404 — which the screen's own error banner names in as many words,
rather than saying "could not render".

`App\Support\AdminCapabilities::RULES` needs **no** new entry:
`['*', 'admin-api/homepage/**', 'content.manage']` already governs the path, and
that is the right capability on its own merits, since seeing the page you are
about to publish is not a lesser act than publishing it. Both halves are pinned.

---

## 7 · Screenshots

`docs/p1-preview-shots/`, captured in real Chromium against a migrated and
seeded SQLite database served through this worktree.

| File | What it shows |
| --- | --- |
| `p1-before-homepage-390.png` / `p1-before-homepage-1280.png` | Appearance → Homepage on the parent revision, on arrival. **The 390px pair is byte-identical between the two revisions** (`md5 afbd74be…`, both), because the top of the screen is untouched and the card is below the fold there — which is rule 1 stated as a picture rather than as a claim |
| `p1-after-homepage-390.png` / `p1-after-homepage-1280.png` | the same screen with the Preview card in place, before anything is rendered |
| `p1-before-sections-390.png` / `-1280.png` vs `p1-after-sections-*.png` | scrolled to the *Sections* header, which is where the difference is: on the parent revision the layouts run straight into the section list; after, the empty Preview card and its line sit between them. This is the pair to read at 390px |
| `p1-after-preview-desktop-card-390.png` / `-1280.png` | the card after **Preview this arrangement**: the real storefront homepage, laid out at 1280px, reduced to fit the column |
| `p1-after-preview-mobile-card-390.png` / `-1280.png` | the same arrangement with **Mobile · 390** pressed — the shop's phone layout at its true size, unscaled |
| `p1-after-preview-stale-card-390.png` / `-1280.png` | after one Desktop switch is toggled: the picture is unchanged and the line under it now says the rows have moved |
| `p1-after-preview-desktop-390.png` etc. | the same states in the console's full viewport, for the overflow numbers in §4 |

---

## 8 · Found, and deliberately NOT fixed

- **The preview is rendered in the admin's own session, so the storefront header
  draws the signed-in account panel.** Measured, not assumed: fetched
  anonymously the two documents differ in the header and the mobile menu. It is
  not wrong — it is what the person pressing the button sees when they open the
  shop in the tab next door — but it means the preview is not what a *stranger*
  gets. Rendering it as a guest means a second guard decision about what the
  preview may see, and that is its own round.
- **The preview is always English.** `Locale::segment()` answers the default
  locale under an admin request, so a shop with Arabic switched on cannot preview
  its Arabic homepage. The fix is a locale on the request the render happens
  under; it interacts with `Locale::localisable()` keeping the console off the
  `/ar` prefix, and it is not this lane's surface.
- **The corner ornament still does not follow visual order.** Lane FR measured
  and costed this: `.kbb-home .sec:nth-of-type(odd)` counts SOURCE order and CSS
  `order` does not change source order. The preview now makes it **visible**
  rather than fixing it, which is an improvement on its own terms — the owner can
  see the alternation break before publishing. Only a DOM reorder fixes it.
- **`moduleEnabled('banners')` is still read by nothing** — `docs/FO-HOMEPAGE-INVENTORY.md`
  §6's third open item, untouched here.

## 9 · What a second round would take

1. **The same preview on Appearance → Homepage content.** The hero preview there
   is at console scale and shows one slide; the endpoint this lane added is one
   overlay away from drawing the whole page with unsaved slides. The blocker is
   named rather than guessed: `home_ticker` is read by
   `store/home.blade.php` through `$settings->get()`, not through
   `HomepageContent`, so a content overlay has to reach `SettingsService` —
   whose `map()` memoises in a process-level static — or the preview would
   silently ignore one of the screen's own boxes. That is a decision about
   `SettingsService`, not a feature.
2. **Preview as a guest, and preview in Arabic** — §8's first two, which are the
   same change twice: the request the render happens under needs a declared
   identity and a declared locale rather than inheriting the console's.
3. **The visibility repair `docs/FR-HOMEPAGE-ORDER.md` costed and Lane FW
   half-took.** With the preview in place it is now checkable by looking, which
   is what made it expensive to ship half-proved before.
4. **Lifting `delivery` and `ticker` out of the hero**, which is the only version
   in which all seventeen sections are movable and `nth-of-type` stays
   meaningful. Still a design decision rather than a refactor, and still costed
   in `docs/FR-HOMEPAGE-ORDER.md`.

---

## 10 · Suite

    KBB_WP_DB=kbb_wp_p1 vendor/bin/pest --compact

**5,925 passed · 26 skipped · 0 failed**, 50,303 assertions, 354.16s. Exit 0.

`KBB_WP_DB` named per lane and `KBB_TEST_DB` left unset, which is what CLAUDE.md
prescribes for the default suite and is load-bearing rather than decorative:
`GeWpExporterTest` and `GnExportScreenTest` run here and reach the shared MySQL
harness, which two lanes tear down under each other.
