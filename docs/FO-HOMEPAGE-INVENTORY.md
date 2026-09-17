# What the homepage actually is

Lane FO, Phase 15. Measured against `resources/views/store/home.blade.php`,
`app/Http/Controllers/Store/HomeController.php`, `app/Services/HomepageSections.php`
and the settings the console can really write — not inferred from the screens.

Every claim below was checked by fetching the rendered page, not by reading the
template. Where a defect is reported, the reproduction is written out.

---

## 1. Where each section's words come from

There are **17 sections** in `HomepageSections::REGISTRY`. Two separate things
are configurable about each of them, and it is worth naming them apart because
the console only owns one:

| | what it decides | who owns it today |
|---|---|---|
| **Presence** | does the section render, on desktop, on mobile, with which grid skin | Appearance → Homepage. Works. |
| **Content** | what it *says* | see below |

Presence is in good shape and is not what this lane touched. Content is the
whole finding.

### Schema-driven content — 1 of 17 before this lane, 2 after

| Section | Schema | Screen |
|---|---|---|
| `newsletter` | `NewsletterSettings::SCHEMA` / `::TABS` — 14 fields, cast on the way in | Growth & Marketing → Newsletter, with a live preview |

One section, before this lane. That is the model the rest of Phase 15 is
measured against, and it is the model this lane copied rather than improved on:
`hero` joins it here, through `HomepageContent::SLIDE_SCHEMA`, drawn and cast by
the same `ModuleSchema` — and so do `about_text` and `home_ticker`, as flat
fields on the same screen. Two of seventeen, and three sentences elsewhere.

### Settings-driven but not schema-driven — 4 fields across 2 sections

Real settings, validated through `AdminController::SETTING_RULES`, written by
the generic settings endpoint, with `App\Support\TrustClaims` deciding that an
empty box means "say nothing":

| Section | Key | Default |
|---|---|---|
| `brands` | `home_brands_note` | "Korean brands, all sourced direct." |
| `trust` | `trust_authentic_title` | "100% original" |
| `trust` | `trust_authentic_text` | "Direct from brands and trusted suppliers" |
| `trust` | `trust_support_title` | "24/7 support" |

These are correct and were left alone. They are the pattern Lane DR
established and the one this lane followed for `about_text`.

### Counted from the database — 7 sections

**These groups are about an ASPECT of a section, not a partition of the
seventeen.** A rail can have its items counted and its heading hard-coded, and
several do; each section appears under every group that is true of it.

Nothing to edit and nothing that can be wrong: `categories`, `bundles`,
`recommended`, `bestsellers`, `flash`, `blog` and `reviews` are read off the
catalogue, as are the three figures in the About band. `delivery` and the
free-delivery figures in `ticker` and `trust` come from Store → Delivery &
Shipping and Store → Shipping through `DeliveryLine` and
`ShippingService::thresholdHere()`.

This half of the page has been repaired thoroughly by earlier lanes and the
comments in `home.blade.php` record each repair.

### Wording hard-coded as translation keys — 14 of the 17

Counted off the template rather than estimated: every `store.home.*` key
between one section marker and the next.

| Section | `store.home.*` keys | What is fixed |
|---|---|---|
| `quiz` | 20 | the kicker, heading, body, the question, its five options, the button; "5 questions" and "0 cost" are literals |
| `about` | 6 | the heading and the three stat labels — the figures are counted |
| `bundles` `recommended` `bestsellers` `flash` `blog` `reviews` `trust` | 5 each | headings, badges, subtitles, "see all" links; for `trust`, the payments card, both halves |
| `routine` `spotted` | 4 each | `routine` also has its six steps built in `HomeController` |
| `brands` | 3 | the heading and the link. The NOTE under it is the owner's |
| `hero` | 2 | the two slider arrows' labels only — everything a shopper reads is the owner's now |
| `categories` | 1 | the "n products" tally label |
| `delivery` `ticker` `newsletter` | 0 | entirely owner- or data-driven |

Seven of these are the rails above, whose ITEMS are counted and whose HEADINGS
are not. Keys make a sentence translatable and **not** editable: an owner cannot
change one, and a package is needed to.

Not a defect on its own — a shipped sentence with a translation key is a
deliberate choice, and several of these were rewritten by earlier lanes
precisely so they state nothing unmeasured. It is listed because "the owner can
edit the homepage" is not true of these and should not be claimed.

---

## 2. Settings the homepage reads that **nothing writes**

This is the category this project keeps producing, and the homepage had four.
Searched across `app/`, `database/`, `resources/`, `routes/` and `tests/`: each
key below appears in the storefront template or controller and in **no**
`SETTING_RULES` entry, **no** module endpoint, **no** seeder and **no** `->set()`
call anywhere in the tree.

| Key | Read by | What that meant |
|---|---|---|
| `home_banners` | `HomeController` → the hero slider | **The largest thing on the page, carrying its only `<h1>`.** Three slides of marketing copy compiled into the controller were the shipped and only value of every install: unchangeable, unremovable, and printed unescaped. |
| `about_text` | `home.blade.php`, About band | The paragraph about the business. Same fault as `trust_authentic_text`, which has a box. |
| `home_ticker` | `home.blade.php`, promo ticker | Deliberately absent (see `2026_11_04_000000_clear_caches_storefront_claims`), so the chip never drew — but a section the owner can switch on could never say anything of its own either. |
| `site_title` | `home.blade.php` (the quiet `<h1>`), `review-wall.blade.php` | **Not fixed here.** It is site-wide rather than homepage content and belongs with the general settings, not on an Appearance screen. Reported, not built. |

The first three now have a screen — Appearance → Homepage content. `site_title`
is still open.

### What the hero was saying

Making the slides editable does not make them true, and three of the shipped
lines state things the shop measures elsewhere or not at all:

- **"93 brands · sourced direct"** — a figure. The brands strip forty lines
  below counts the real number off the catalogue (`$brandTotal`), and on the
  preview database that number is **8**.
- **"Free delivery across the UAE over AED 199"** — the threshold is owned by
  Store → Shipping, per zone, and production runs two zones with two different
  ones. The band and the ticker directly below this slide were repaired to read
  the real figure; the slide above them was still quoting a literal.
- **"Shop the Super Sale"** and **"Medicube · limited-time offer"** — a sale and
  an offer, advertised on every fresh install forever.

They are kept as the *defaults*, byte for byte, so applying the package changes
the storefront by nothing. What has changed is that they are now removable in
one click, which they were not. Whether to remove them is the owner's call and
this lane did not make it for them.

---

## 3. The defect the sections screen has: **order is saved and never read**

Appearance → Homepage has ↑/↓ buttons on every row, `HomepageSections::save()`
writes an `order` for each, `HomepageSections::all()` sorts by it, and the five
layout presets in `HomepageLayouts` set it. **`home.blade.php` renders the
seventeen sections in template order and consults `order` nowhere.** `.kbb-home`
is not a flex or grid container, so there is no CSS ordering either.

Reproduced, not inferred:

```php
// Put the newsletter, trust and reviews first; everything else after.
$sections->save($reordered);
SettingsService::forgetMemo();

array_slice(array_keys(app(HomepageSections::class)->all()), 0, 3);
//  => ['newsletter', 'trust', 'reviews']          the service agrees

$html = $this->get('/')->getContent();
strpos($html, 'id="slider"');   // 21360   the hero, still first
strpos($html, 'class="nl"');    // 64582   the newsletter, still last
```

So the arrows move rows on the screen, the payload saves, the screen reports
"Saved N sections — live now", and the shopper sees the same page. The layout
presets advertise "Presets that set order, visibility and grid styles in one
move"; two of those three are real.

**Not fixed in this lane, deliberately, and this is the one judgement call worth
arguing with.** The two honest fixes both cost more than they look:

1. **Restructure `home.blade.php` into one partial per section and loop in
   saved order.** Correct, and the only version that makes all seventeen
   movable. It is a 635-line rewrite of the hottest file in the repository —
   four lanes have edited it this week — and would conflict with all of them.
2. **Make `.kbb-home` a flex column and emit `order:N` from
   `HomepageSections::classFor()`,** which all seventeen sections already call,
   so `home.blade.php` need not change at all. About six lines. **But
   `delivery` and `ticker` are not children of `.kbb-home`** — they are nested
   inside the hero `<section>`, where `order` is inert — so two of the
   seventeen would silently refuse to move. Shipping that would be adding a
   fifteen-of-seventeen control to a screen, which is the same defect class this
   document is cataloguing, one level along.

Option 2 plus marking those two as pinned-to-the-hero on the screen (no arrows,
a sentence saying they travel with the hero band) is the cheap correct answer.
It needs a block for `resources/views/admin/app.blade.php`'s `paintHomepage()`,
which is a different lane's surface from the one this lane was given.

---

## 4. Smaller things found on the way

- **`moduleEnabled('banners')` is read by nothing.** The module exists in
  `ModuleRegistry`, ships **off** in `ModuleSeeder`, and the hero renders
  whatever it says. Compare `brands`, whose storefront strip is gated on its
  module precisely so that switching it off leaves no trace. Left alone rather
  than wired up: making it true is a decision about whether a shop with the
  module off has a hero at all, and that is the owner's, not a lane's.
- **The `banners` row named a screen that had no banner control.** Its settings
  path said "Appearance → Homepage" — which owns sections and grid skins and
  has never carried a slide anywhere. Corrected to Appearance → Homepage
  content, with the route key filled in so the Open button works.
  `ModuleRegistrySettingsPathTest` now resolves the claim against the real
  screen.
- **Its description promised three features that do not exist here** — floating
  product pods, an offer badge, and scheduling — carried over verbatim from the
  KBB Modules plugin. Trimmed to what the screen does.
- **An empty hero rendered a frame.** With no slides the band still drew a
  coloured box, a previous arrow, a next arrow and a row of dots. It could not
  happen before, because the list was always the three shipped slides; deleting
  the last slide is a thing an owner can now do, so the slider is not rendered
  at all when the list is empty.
- **`ConvertEmptyStringsToNull` reaches this screen too.** A cleared box arrives
  as `null`, which `ModuleSchema::cast()` correctly refuses for a text field —
  so "clear a claim to remove it", the half of this feature that matters most,
  reported "refused" and left the sentence on the front page. Handled locally
  for `text` and `textarea` only (`HomepageContent::emptyIsEmpty()`), with the
  reasoning written beside it. It is the same root cause as the Store →
  Ecommerce 422 another lane is repairing at source; when that lands this
  becomes a no-op rather than a conflict.

---

## 5. Out of lane, and why it was taken anyway

- **`AdminConsoleScriptParsesTest` was a hand-kept list of seventeen paths and
  the console has twenty-one.** `admin/partials/translation-screens.blade.php`
  (four screens, 1,200 lines, the screen the owner turns Arabic on from),
  `arabic-boxes.blade.php` and `review-queue-badge.blade.php` had never been
  parsed. Found because this lane's new partial would have joined them.
  Widened to walk the directory — the same argument
  `StorefrontStringsAreKeyedTest` already makes in its own header — and proved
  to bite by breaking `translation-screens` and watching it fail there. All 21
  files parse.
- **`EnglishRenderWalk::BASE_COMMIT` moved forward.** It compares the storefront
  against itself at a pinned SHA with `resources/views` rolled back and the PHP
  left in the working tree, so for this lane its "before" was the OLD hero
  template echoing the NEW default raw — a page that has never existed. Not a
  byte of shopper-visible copy moved; the compensating pin is in
  `HomepageContentEditorTest`, which asserts the hero's exact rendered bytes.
  The constant's own header now also says what to CHECK — that the value is a
  commit `git archive` can resolve from the branch it is read on — because this
  lane rebased after pinning it and had to repin.

## 6. Still open

- **Section order** (§3), the largest one.
- **`site_title`** — read by the homepage and the review wall, written by
  nothing. Site-wide rather than homepage content, so it belongs with the
  general settings rather than on an Appearance screen.
- **`moduleEnabled('banners')`** — a switch nothing reads.
- **The hero's shipped claims** — "93 brands", the free-delivery figure and the
  Super Sale. Removable now; still the default until somebody decides.
