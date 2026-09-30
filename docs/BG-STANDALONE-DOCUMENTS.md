# The five storefront pages that carry their own `<head>`

`store/blog`, `store/post`, `store/review-wall`, `store/skin-quiz` and
`store/app` each carry their own `<html>`, their own `<head>` and their own
inline stylesheet. **None of them extends `layouts/store.blade.php` and none of
them loads `kbb.css`.** Anything the layout emits has therefore never reached
them, and nothing said so anywhere.

Five real URLs: `/skincare-guide/`, an article at the site root, `/reviews/`,
`/skin-quiz/` and `/app`.

---

## 1 · What the layout emits, and what each of the five had

Read off the **rendered page** — `curl` the head of each URL — not off the
Blade. Measured twice: once at the shipped settings, and once with the brand
colour, the site width and the page wash all moved, because four of these
emitters are silent until the owner touches something and a table taken at
defaults says they are all missing.

| what the layout puts in `<head>` | home / shop | blog | post | reviews | quiz | app |
|---|---|---|---|---|---|---|
| `<title>`, description, canonical, OG, Twitter, JSON-LD, hreflang, robots | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| analytics loaders | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| the Arabic face (`Cairo`) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| the page wash | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **the brand colour** | ✅ | ❌→✅ | ❌→✅ | ❌→✅ | ❌→✅ | ❌→✅ |
| **the site width** | ✅ | ✅ | ✅ | ❌→✅ | ❌→✅ | ❌→✅ |
| **the self-hosted Latin webfont** | ✅ | ❌→✅ | ❌→✅ | ❌→✅ | ❌→✅ | ❌ |
| **the designed page background** | ✅ | ❌→✅ | ❌→✅ | own | own | own |
| `set-appearance-css` | ✅ | — | — | — | — | — |
| the account-panel webfont | ✅ | — | — | — | — | — |
| `MarketingPixels::addToCart()` | ✅ | — | — | — | — | — |

✅ present · ❌ missing and it matters · ❌→✅ fixed since · `own` a designed
composition of its own, deliberately kept (§5) · — correctly absent, argued below

**The rows are now asserted rather than described** — see §7. The one remaining
❌ is `/app`'s webfont, and §3.5 argues for leaving it.

The SEO block, the analytics loaders and the Arabic face are all there because
earlier lanes found the same hole from their own side and filled it: the SEO
comes from each page's controller, and `partials/arabic-face.blade.php` is one
partial included by all five, which is the precedent this lane followed.

---

## 2 · The brand colour — fixed

`App\View\Composers\StoreComposer` is registered for **`layouts.store` and
nothing else**:

```php
View::composer('layouts.store', StoreComposer::class);
```

so `$kbbAccent` was not merely empty on the five — it was **never defined**. All
five hard-code the design default on their own `:root` and four of them use it:

| | `--pink:#E0567B` declared | uses of `var(--pink)` | uses of `var(--pink-deep)` |
|---|---|---|---|
| skin quiz | yes | **12** | **14** |
| review wall | yes | **8** | **5** |
| an article | yes | 1 | 6 |
| the journal | yes | 2 | 5 |
| `/app` | yes | 0 | 0 — a different palette entirely |

**So a shop that changed its brand colour changed `/shop/`, the home page, the
cart and the checkout, and the journal, an article, the review wall and the skin
quiz kept the old pink** — on every button, chip, star and heading. Nobody had
noticed.

**The fix.** `App\Support\BrandAccent` is the one writer; the composer reads it
and so does `resources/views/partials/shop-appearance-css.blade.php`, which
**six** documents include. The site-width block goes the same way — the journal
and an article each carried their own *copy* of the layout's block and the other
three had nothing.

**Zero bytes at the shipped settings.** All six heads are byte-identical before
and after, fetched from the same running server with the CSRF token masked. The
partial goes **last** in each head because the accent rule is `:root` and so is
each document's own `--pink`: they tie on specificity and source order is the
whole mechanism. `StandaloneDocumentHeadTest` pins that ordering on the rendered
page.

---

## 3 · The self-hosted Latin webfont — **four of five fixed**

The layout serves Poppins from this shop (`App\Support\WebFonts`, Lane PERF).
All five documents linked `fonts.googleapis.com`; **four of them no longer do.**

### 3.1 · Why it could not be done last round, re-measured

The weights did not line up, and round 2 left this table behind:

| | asks Google for | self-hosted `WebFonts` had | missing |
|---|---|---|---|
| the journal, an article | 400 500 600 700 | 400 600 700 800 | **500** |
| review wall | 400 500 600 700 800 | 400 600 700 800 | **500** |
| skin quiz | **300** 400 500 600 700 800 | 400 600 700 800 | **300, 500** |
| `/app` | Fraunces 400–700, Hanken Grotesk 400–700 | *neither family exists* | all of it |

**The table is correct. The sentence under it was not**, and it is worth
recording how it was wrong, because it was wrong in a way that read as careful:

> `kbb.css` uses `font-weight:500` eight times and the shop already serves only
> 400/600/700/800, **so the storefront is already synthesising that weight.**

There is synthetic **bold**; there is no synthetic medium. CSS font matching for
a target of 500 tries 500, then weights **below** it in descending order, and
only then above — so with 400 and 600 present it picks **400 outright**. The
text was the regular face with no emboldening at all.

Measured in Chromium on a controlled page declaring exactly the old face set and
exactly the new one, on a 40px ruler reading `Hydrating Serum AED 149`:

| target weight | 400 | 500 | 600 | 700 | 800 |
|---|---|---|---|---|---|
| old face set (no 500) | 497.20 | **497.20** | 510.17 | 516.17 | 521.25 |
| with the real 500 | 497.20 | **505.00** | 510.17 | 516.17 | 521.25 |

500 sat **exactly** on 400, to the hundredth of a pixel. That is the proof it
was the 400 face and not a near miss.

**And the first attempt to measure this produced different numbers, from a
broken instrument.** A ruler set in `Poppins, system-ui, sans-serif` reports the
*system* font's widths on any page where Poppins is absent — and reports them
**identically on every such page**, which is what "543.28px on all eight pages"
meant and nobody read. The honest instrument sets the ruler in `Poppins` alone
and measures a second ruler in a family that cannot exist: equal widths mean the
family never rendered. Every number in this section is from the second kind.

### 3.2 · Weight 500 was a defect on the whole shop, not a prerequisite

Counted on the rendered page, elements whose computed `font-weight` is 500:

| | /shop/ | product | home | journal | article | quiz | cart | reviews |
|---|---|---|---|---|---|---|---|---|
| elements | 47 | 34 | 29 | 9 | 7 | 3 | 2 | 0 |
| of them visible | 29 | 31 | 27 | 9 | 7 | 3 | 0 | 0 |

**106 visible elements across eight pages** — prices, filter chips, "medium"
labels, nav links — every one of them rendering as regular. 57 rules in the
storefront's own stylesheets ask for weight 500 (22 in `kbb-shop.css`, 13 in
`kbb-product.css`, eight in `kbb.css`, the rest over cart, checkout, the grid
skins and the review block).

So 500 went into `WebFonts`, and that is a **fix to the whole shop** that
happened to unblock these four documents rather than a cost paid for them.

- **The files are Google's own.** Each of the three was fetched from the URL
  `css2` names and is **sha256-identical** to it: latin 7,748 bytes, latin-ext
  5,484, devanagari 39,084. `usWeightClass` reads 500 and the name table reads
  "Poppins Medium", against "Poppins" at 400 and "Poppins SemiBold" at 600.
- **It costs nothing on the critical path.** The latin file is 7,748 bytes —
  *smaller* than the 400 (7,884) and the 600 (8,000) already shipped — same
  origin, `display:swap`, and **not preloaded**:
  `WebFonts::NO_PRELOAD_WEIGHTS` keeps it out. **Four preloads before, four
  after**, so the 4,369 ms critical path Lane PERF cut is untouched.
  `PerfDeliveryTest` asserts both counts, and they move apart on purpose.

### 3.3 · Weight 300 was dropped, and asked the same question first

`store/skin-quiz` was the only caller that requested it. The census found
**zero** elements at `font-weight:300` on seven of the eight pages and exactly
one on a product page, **invisible** (0×0). A target of 300 also resolves to the
400 face — 497.20px, the same number as 400 and 500 — so the quiz was asking
Google for a file that could not have changed a pixel. It stops asking.

### 3.4 · What actually changed for a shopper, measured both ways

`resources/views/partials/poppins-face.blade.php` is the one copy, included by
the journal, an article, the review wall and the skin quiz. Their `preconnect`
hints went with the link that needed them: **Google links per page went 1/1/3/2
→ 0/0/0/0**, and zero across all eight storefront pages.

The pixel numbers need two baselines, because **this container has no route to
`fonts.googleapis.com`**:

- *Against this container's own before* — those four pages rendered in the
  **system face** (both rulers agreed at every weight: 436.63px at 400, 463.36px
  at 600, against Poppins' 497.20 and 510.17). That is what a shopper on a
  network that cannot reach Google got, and the change there is large.
- *Against production* — the four documents **asked Google for 500 and got it**,
  so their type was already correct. Simulated by intercepting the `css2`
  request and fulfilling it from this repo's own faces, then comparing pixels:

| | differing pixels, 1280 | 390 | max channel delta | verdict |
|---|---|---|---|---|
| **review wall** | **0** of 1,152,000 | **0** | 0 | pixel-identical; only the request is gone |
| skin quiz | 38,315 | 21,779 | 89 | **not attributable** — see below |
| the journal | 166,786 (14.5%) | 41,238 | 42 | the background of §5, not the type |
| an article | 815,765 (70.8%) | 216,069 | 42 | the background of §5, not the type |

**The skin quiz cannot be pixel-compared at all**, and this is the kind of thing
that reads as a regression for a day: it carries
`animation:sheen 1.6s infinite`, so **the same page captured twice differs by
38,315 pixels** — the identical count. Two captures of one unchanged page is the
only measurement that establishes that, and byte-identity would not have shown
it either way, since PNG encoding here is not deterministic.

The journal and an article ARE deterministic (two captures, 0 differing pixels
and the same md5), so their numbers are real, and a max channel delta of 42 is a
background tint rather than reflowed text.

**And the type did not move at all.** Body height against the
production-equivalent baseline, same four documents:

| | before | after |
|---|---|---|
| the journal | 922 | **922** |
| an article | 1,346 | **1,346** |
| the review wall | 900 | **900** |
| the skin quiz | 900 | **900** |

Unchanged to the pixel on all four. In production these pages already had
Poppins at every weight they asked for, so self-hosting it changes **which
origin serves the file and nothing about the page**. The journal's and the
article's pixel deltas above are the background of §5 arriving, and nothing else.

### 3.5 · `/app` keeps its Google link, deliberately

**`/app` is admin-only.** `PageController::app()` is:

```php
if (! Auth::guard('admin')->check()) {
    abort(404);
}
```

Its SEO block sets `noindex`, its title is "App Preview", its description is "A
standalone preview of the K-Beauty Bliss shopping experience", and **no view in
the storefront links it** — `grep` finds zero references outside the route
table. Verified over HTTP: `/app` and `/app/` both answer **404** to a guest.

It is built on a different type system from the rest of the shop — `--sans:
"Hanken Grotesk"`, `--serif: "Fraunces"` — which came in with the "Sorina"
review template, not from a decision about this shop. So the answer to "does it
genuinely need two more families, or was it built against fonts nobody chose" is
**the second**.

**Recommended: leave it.** Self-hosting Fraunces (a variable font with an `opsz`
axis) and Hanken Grotesk at four weights, into this repository, to remove a
render-blocking request from a page **no shopper can reach**, is the wrong
trade — and converting it to Poppins instead would change how the owner's
preview mock looks, for nobody's benefit. This lane will not download two font
families on its own authority; if the owner wants `/app` to match the shop, that
is a design decision and it should be taken as one.

### 3.6 · What nobody had noticed: Fraunces on product pages

Found while answering the question above, **not fixed, and it is not a font
problem.** `sorina-reviews.css` is inlined into every product page by
`ProductController::reviewsCss()` and names both families:

```css
.sr       { font-family:"Hanken Grotesk", -apple-system, …; }
.sr-title { font-family:Fraunces, Georgia, serif; font-weight:500; }
.sr-avg   { font-family:Fraunces, Georgia, serif; font-weight:600; }
```

`layouts/store.blade.php` also names them for `html[lang="ar"]`. **Neither
family is loaded anywhere except `/app`.** Measured on a product page: the
`.sr` block is present, the only declared family on the document is `Poppins`,
so `.sr-title` renders in **Georgia** and `.sr` in the system sans — and always
has. The review wall already overrides them (`/* KBB real tokens (never
Sorina/Fraunces) */`).

Left alone on purpose: the fix is either to load two families or to restyle the
product review block in the shop's own type, and **both are visible changes to a
working page**, which is a decision for the owner and not a font-pipeline
cleanup. Reported here so the next lane finds it in a minute rather than a day.

### 3.7 · The one page whose type really did change: the home page

The home page does not include either new partial and is not one of the five
documents. The **only** input to it that this round changed is `WebFonts`
gaining the 500 face — so its numbers isolate that fix:

| | 1280 | 390 |
|---|---|---|
| body height | 8,587 → **8,587** | 10,513 → **10,522** (+9) |
| differing pixels | 2,015 of 1,152,000 (0.17%) | 1,403 of 329,160 (0.43%) |

**+9px at 390 is the fix, not a regression**: 27 visible elements on that page
ask for weight 500 and now get a face that is ~1.5% wider than the regular they
were being served, so one label wraps where it did not. `/shop/`, a product
page, the cart, the review wall and the skin quiz are **+0 at both widths**, and
the cart — which has two `font-weight:500` elements, both invisible — is the
control: **0 differing pixels** of 1,152,000, at both widths.

---

## 4 · What is correctly absent, and was checked rather than skipped

| | why it does not belong on these five |
|---|---|
| `set-appearance-css` | styles `.kset-*` only, and no set is rendered on any of them |
| the account-panel webfont | gated on a signed-in visitor **and** on the account menu; none of them draws the greeting it styles |
| `MarketingPixels::addToCart()` | a JavaScript helper for an add-to-cart button; there is no add-to-cart button on any of them |

---

## 5 · The page background: why two of the five keep their own

Settled with `getComputedStyle(document.body)` on ten URLs rather than by
reading the stylesheets — `docs/bg-shots/body-before.json` and
`body-after.json`.

**Before:**

| | `background-color` | `background-image` |
|---|---|---|
| home, cart, checkout, wishlist | `rgb(253,239,243)` | the botanical SVG + a four-stop gradient |
| **/shop/, a product page** | `rgb(255,255,255)` | **none** |
| the journal, an article | `rgb(255,255,255)` | none |
| the review wall | `rgb(255,248,245)` | its own cream fade to 520px |
| the skin quiz | transparent | three radial gradients in pink and lilac |

**`/shop/` and a product page are fixed.** `kbb-shop.css` and `kbb-product.css`
each carry a *base* rule — family, colour, line height — duplicated out of
`kbb.css`, and `background:var(--bg)` came with the copy; both are pushed onto
`@stack('styles')` **after** `kbb.css`, so the copy outranked the designed rule
on every shop, category, brand and product page. Nobody chose two backgrounds.

**Two of kbb.css's four `body` rules were dead and are gone**: the first
re-declared nothing the second did not restate (`font:` is a shorthand and
resets family *and* line-height), and the second's `background:#fff` could never
win against the designed rule 700 lines below it. Deleting both moves nothing —
only `/shop/` and the product page changed at all, on all ten URLs.

**The review wall and the skin quiz keep theirs**, and that is a decision rather
than an omission: those are compositions written out in named colours, and
flattening art direction is not the same act as removing a duplicated base rule.
`OnePageBackgroundTest` now fails a later lane that flattens them.

### The journal and an article are no longer white — **done, and costed first**

They were white because the designed background lives in `kbb.css` and those
documents do not load it. Round 2 reported it with an estimate — "either loading
`kbb.css` or copying a **~40 KB** data-URI gradient into each" — and the
estimate was **4× too high**, which mattered: it made the cheap option look
expensive enough to leave undone.

**What the designed background actually is**, measured rather than estimated —
two declarations, and nothing else:

| | raw | gzip -9 |
|---|---|---|
| `--bg-botanical` (the URL-encoded SVG) in `:root` | 9,130 B | — |
| the `body` rule that uses it | 303 B | — |
| **the whole block** | **9,446 B** | **1,103 B** |

URL-encoded SVG compresses **8.6:1**, so "40 KB of artwork" is ~1.1 KB on the
wire. The other two uses of the variable (`.kbb-home .about .im`,
`.kbb-home footer::before`) are home-page decorations and irrelevant here.

**The three routes, costed on the running preview:**

| | bytes on the wire | extra requests | risk |
|---|---|---|---|
| **1.** load `kbb.css` on both documents | 179,853 raw / **30,673 gzip** | **+1 render-blocking** | high — kbb.css carries base rules for `body`, headings and links that would land on top of each document's own inline stylesheet |
| **2.** copy the data URI into each document | +9,461 raw / **+1,093 gzip** | 0 | two copies that drift apart silently |
| **3.** one partial, included inline by both | +9,461 raw / **+1,093 gzip** | **0** | one copy; divergence held shut by a test |

Measured by splicing the block into the **served** `/blog/` document and
compressing both: 24,347 → 33,808 raw, 7,378 → 8,471 gzip.

**Route 3, and the deciding factor is the request rather than the bytes.** Route 1
is 28× the gzipped cost for one `body` rule *and* adds a round trip to two pages
that currently make none for CSS.

**Why the obvious form of route 3 was rejected.** "Extract it from `kbb.css` so
nothing is copied, and have the layout and both documents link it" is a
**regression for the forty pages that work**: they already fetch `kbb.css`, so
moving 1.1 KB out of it adds a second render-blocking request to every one of
them and takes back part of the 4,369 ms critical path Lane PERF cut. Inlining
it into the layout instead moves the bytes out of a cacheable stylesheet into
uncacheable HTML on every page, and moves the designed rule 1,400 lines earlier
in the cascade, where two other `body` rules in `kbb.css` are waiting for it.

So **`kbb.css` is not touched** — the working shop renders byte-identically —
and `resources/views/partials/page-background-css.blade.php` carries the one
copy for the two documents that could not reach it. The duplication is held shut
**mechanically**: `StandaloneDocumentHeadTest` extracts the declaration from
`kbb.css` and requires the partial to carry it byte for byte. Mutating one byte
of the data URI in **either** file is red, naming both.

**Where it goes in the head, and it is not last.** After that document's own
`<style>` (the rule it must beat is `body{background:var(--bg)}`, and `body`
ties with `body`), and **before** `partials/page-wash-css.blade.php` — the
opposite of what the accent asks for, deliberately: the wash is the owner's own
choice from Appearance → Page background and must win, which it only does by
coming later. That is the order `layouts/store.blade.php` already has.

**Measured after:** `background-color` `rgb(253,239,243)` with **5 layers** on
both, matching the home page, `/shop/`, a product page and the cart exactly.
`document.documentElement.scrollWidth` is 1280 at 1280 and 390 at 390 on all
eight pages — no horizontal overflow. The two designed compositions are
untouched: the review wall still measures `rgb(255,248,245)` with 2 layers and
the skin quiz still `rgba(0,0,0,0)` with 11.

This is a **visible change to two pages** and the only one in this round; every
other page's background is byte-identical.

---

## 6 · What is left

Items 1 and 3 of round 2's list are **done** (§3 and §5). What remains:

1. **`/app` and its two families — recommended NOT to do.** §3.5 has the
   measurement: admin-only, `noindex`, linked from nowhere, and built on a type
   system that arrived with a third-party template. Self-hosting Fraunces (a
   variable font with an `opsz` axis) and Hanken Grotesk for a page no shopper
   can reach is the wrong trade, and converting it to Poppins would change the
   owner's preview mock for nobody's benefit. **A decision for the owner, not a
   cleanup.**

2. **Fraunces and Hanken Grotesk on product pages** (§3.6). `sorina-reviews.css`
   names both for the review block on every product page and neither is ever
   loaded, so `.sr-title` renders in Georgia and always has. Either load two
   families or restyle the block in the shop's own type — both are visible
   changes to a working page, so **the owner decides**.

3. **Make `/reviews/` and `/skin-quiz/` read `--site-max`** if the owner wants
   his site-width slider to reach them. They carry the variable now and do not
   read it, so today it is inert there; making them read it moves two pages that
   currently work and is a decision, not a fix.

4. **Weight 300, if a design ever wants it.** Not shipped, and §3.3 says why:
   zero elements at `font-weight:300` on seven of eight pages, one invisible one
   on the eighth.

---

## 7 · The audit is now a test, not a table

§1 was prose for two rounds, and every hole it found had been open for months
**because nothing asserted it**. `StandaloneDocumentHeadTest` now carries it:

- **The table, at MOVED settings.** Four of these emitters print nothing until
  the owner touches something, so an audit taken at the shipped defaults finds
  the brand colour, the site width and the wash "missing" from all six documents
  and reports a clean bill of health. The test moves the brand colour, the site
  width, the wash and the analytics id first, and only then reads the page. Drop
  the brand-colour line and **six rows go red at once** — that mutation is the
  proof the distinction is load-bearing.
- **The Arabic face is read on the mirror.** It is gated on the *language*, so on
  `/shop/` its absence is the design and on `/ar/shop/` its absence is a defect.
  Same emitter, opposite verdict, decided by the URL — which is why it cannot be
  a row in the English table.
- **`/app` is fetched as an admin**, because it 404s a guest, and its one
  expected absence carries its reason in the assertion rather than in a document.
- **A seventh standalone document fails by name.** `sdhOwnHeadViews()` finds
  every view under `store/` that carries its own `<head>`.
- **A new thing in the layout's head fails by name.** Both shapes are read —
  `@include('partials.…')` *and* inline `<style id="kbb-…">` — because the two
  emitters that turned out to be missing from five documents were one of each.
  Everything found must be either shared with all six or listed as layout-only
  **with the reason**.

All thirteen mutations were run. See the commit body for what each one printed.

### 7.1 · One stale pin, advanced rather than deleted

The full suite found it, not the lane. `StandaloneDocumentsArabicFaceTest`
asserted for **all five** documents:

> And the document's own Latin link is still a Google one, which is what makes
> the `preconnect` hints in its `<head>` worth keeping.

True and load-bearing when Lane FS wrote it. Four of the five are off Google
now, so the only way to make that sentence true again is to **put four pages
back on a third-party origin** — which is the signature of a pin on the *old*
state rather than on a guarantee.

**Inverted, and the inverted form is stronger.** The four must carry **zero**
`css2` links *and* zero leftover `preconnect`/`dns-prefetch` to a Google font
origin; `/app/` must still carry its link, with the failure message pointing at
§3.5 if somebody converts it. That catches two regressions the old shape could
not: a document drifting back onto Google, and a connection hint left behind
pointing at an origin the page no longer fetches from.

**And the mutation note first written for it was wrong** — kept in the file,
because it is the easy mistake with an inverted assertion. "Delete the
`poppins-face` include" leaves it **green**: removing the self-hosted faces does
not put a Google link back. That direction is `StandaloneDocumentHeadTest`'s
include count; this case guards the other one.
