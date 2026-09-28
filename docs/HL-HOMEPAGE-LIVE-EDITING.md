# Lane HL — editing a homepage section from the preview

Phase 15's last unticked line: *"Live editing of homepage sections, reusing the
settings schemas."* Both halves already existed and nothing joined them. This
lane is the join, and nothing else: no new setting, no new writer, no new
vocabulary for describing a control.

**Where it sits:** `Appearance → Homepage content → Live preview`.
(The console's sidebar: **Appearance** group → **Homepage content**, directly
under **Homepage**; then the third tab, beside *Hero slider* and *Other
wording*.)

---

## 1 · What was missing, and it was never a control

| Half | Where it already was |
| --- | --- |
| The **picture** | `POST /admin-api/homepage/preview` renders the real storefront homepage from an arrangement nobody has saved — Lane P1, `docs/P1-HOMEPAGE-LIVE-EDITING.md` |
| The **controls** | `HomepageSections::SECTION_SCHEMA` + `SECTION_POLICY` — the ModuleSchema description of the three controls a section carries, the same triple every other settings screen is drawn from |

What nobody could do was point at a section **in** the picture and get **that**
section's controls. Appearance → Homepage lists nineteen rows of switches with
no picture; the preview card shows a picture with no way into a row.

## 2 · What was built

- **`POST /admin-api/homepage/live`** (`routes/homepage-live-admin.php`) renders
  the same document `renderHome()` already rendered, from a reader built with
  `annotate: true`, and hands back each section's controls as
  `ModuleSchema::tabs()` emits them.
- **`HomepageSections::SELECT_CLASS`** (`kbb-pvsec`) is appended by
  `frameClass()` — the one method all nineteen sections already class through —
  and **only on an annotating proposal**. The shop never carries it. Neither
  does `/homepage/preview`, whose document is still byte-identical to `GET /`.
- **`HomepageSections::sectionTabs()`** draws one section's controls from
  `SECTION_SCHEMA` / `SECTION_TABS` / `SECTION_POLICY` and the same `overrides`
  the cast uses, with the grid control dropped for the fifteen sections that
  have no product grid.
- **The Live preview tab** in
  `resources/views/admin/partials/homepage-content-screen.blade.php`: the
  picture, a click target on every section in it, a row of section names for the
  keyboard, and the selected section's controls beside it. A control change
  redraws the picture and never reloads the page.

### It ships inert

No setting is added and no default moves. The three controls are the three
`HomepageSections` has always stored, and **Save posts to
`POST /admin-api/homepage`** — the endpoint that has always written them.
Applying the package changes nothing on the shop until somebody moves a control
here and presses Save.

## 3 · Selection is a class, not a measurement

Rule 4 forbids JavaScript that measures layout. The server marks each section
wrapper; the console puts one stylesheet inside the frame and toggles one class:

```
.kbb-pvsec:hover  { outline: 2px dashed … }   /* what can be picked */
.kbb-pvsel        { outline: 3px solid  … }   /* what is picked     */
a,button,input,…  { pointer-events: none }     /* the picture is not a shop */
```

The frame is sandboxed **without** `allow-scripts`, exactly as the preview card
next door is; `allow-same-origin` is what lets the console reach in to outline a
section, and the two flags that together defeat a sandbox are never both set.
The stage is sized with `calc(520px / var(--hpe-s))` from a declared scale per
breakpoint — nothing asks an element how big it is.

## 4 · The words are linked to, not repeated

`HomepageApiController::WORDS` says which section's wording lives on which tab
of the same screen — the hero's slides, the ticker's chip, the About paragraph.
Selecting one of those three offers **Edit the wording**, which switches tab and
focuses the box. It does **not** draw a second box for a setting that already
has one: `copyTab()` on this screen states the rule — *one sentence with two
boxes is a sentence that changes depending on which box you touched last*.

The map is held to `HomepageContent::SCHEMA` and `::TABS` in both directions by
a test, so it cannot become the fourth thing on this screen that goes stale.

## 5 · Measured, in Chromium

Driven the way an owner drives it: sign in, open the screen, press the tab,
**click a section in the picture**. `tools/hl-preview.sh` + `tools/hl-shots.cjs`.

| | 390px | 1280px |
| --- | --- | --- |
| `document.documentElement.scrollWidth` | **390** | **1280** |
| viewport | 390 | 1280 |
| selection hooks in the rendered page | 15 | 15 |
| section names in the row | 19 | 19 |

| State | Panel heading | Controls drawn |
| --- | --- | --- |
| nothing selected | *Nothing selected* | — |
| clicked the category circles in the picture | **Category circles** | Show on desktop · Show on mobile |
| pressed **Show on desktop** | **Category circles** | the picture redrew: that section's class went `sec dv …` → `sec d-off dv …`, the outline stayed on it, and the bar said *Unsaved changes* |
| picked **Big savings bundles** | **Big savings bundles** | Show on desktop · Show on mobile · **Grid style** (28 options, *Classic card*) |
| picked **Hero slider** | **Hero slider** | Show on desktop · Show on mobile + *"the wording … is edited on the **Hero slider** tab of this screen, where it already has one box and one writer"* |

Screenshots in `docs/lane-hl-shots/`, both widths:

| File | What it shows |
| --- | --- |
| `hl-1-screen-*.png` | the screen as it opens, before the new tab is touched |
| `hl-2-preview-*.png` | the Live preview tab, nothing selected |
| `hl-3-selected-*.png` | a section selected **by clicking it in the picture** |
| `hl-4-controls-*.png` | its controls in use — the picture redrawn from them |
| `hl-5-panel-*.png` | the panel and the names row, scrolled into view |
| `hl-6-sections-*.png` | the row of section names |
| `hl-7-grid-section-*.png` | a section that has a grid: the Grid style picker |
| `hl-8-hero-words-*.png` | the hero: its wording linked to, not repeated |

## 6 · The mutations

Every case in `tests/Feature/HomepageLiveEditTest.php` was run against a
mutation. Baseline for this file is **21 passed, 1 failed** — the failure is the
route-wiring pin, which is red until the integrator adds the require line and
green forever after.

| Mutation | Goes red |
| --- | --- |
| `live()` passes `false` to `proposing()` | *it marks every section in the picture with the hook the screen selects on* |
| `proposing()` defaults `$annotate` to `true` | *it never marks the shop itself, or the preview that promises to be byte-identical to it* |
| annotated `frameClass()` drops the divider class | *it draws the same document the plain preview draws, hook aside* |
| `sectionTabs()` stops dropping `skin` for a grid-less section | *it draws no grid control for a section that has no grid* |
| `sectionTabs()` loses its `overrides` | six cases, including *it draws a hostile grid skin as the section's own default* |
| the controller sends `'tabs' => []` | *it hands back each section's controls from its own schema* |
| `WORDS` names a setting key that is not in the schema | *it joins a section to its wording without growing a second box for it* |
| one POST loses its `X-XSRF-TOKEN` header | *it signs every write it makes with the token this console issues* |
| a fourth `fetch` appears in the live block | *it talks to three endpoints and every one of them already existed* |

## 7 · Security

`AdminCapabilities::RULES` needs **no** new entry: it already carries
`['*', 'admin-api/homepage/**', 'content.manage']`, and `**` matches everything
beneath it. A prefix of its own would fall through to the closed owner-only
default. Both halves are pinned — that the rule really reaches
`admin-api/homepage/live`, and that a signed-out request gets 401. An unknown
section key is a 422 through the same `payloadFor()` the save uses, and a
hostile grid skin is cast to the section's own default by `SECTION_SCHEMA`.

Nothing was added under `/api/*`.

## 8 · Found, and deliberately NOT fixed

- **Everything Lane P1 listed in its own §8 is still true** — the preview draws
  in the admin's own session, it is always English, and the corner ornament
  still counts source order. Same document, same limits.
- **A section switched off for both devices is not in the picture at all**,
  because the storefront does not render it. That is correct — the picture is
  the page — and it is why the row of section names exists: it is the way to
  reach a section that is off, and the way to select one with a keyboard.
- **The wording is edited one click away rather than in the panel.** See §4. If
  the owner would rather have the ticker's box inside the section panel, that is
  a decision, not a defect, and it is his to make.
