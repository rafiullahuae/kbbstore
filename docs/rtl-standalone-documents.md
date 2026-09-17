# Lane FK — the five documents that carry their own `<html>`, and what setting `dir` exposes

`resources/views/store/blog.blade.php`, `store/post.blade.php`,
`store/skin-quiz.blade.php`, `store/app.blade.php` and
`store/review-wall.blade.php` do not extend `layouts/store.blade.php`. Each is a
whole document with its own `<head>`, its own inline stylesheet and its own
webfont link, and each opened with a hard-coded `<html lang="en">` and no `dir`
at all.

So `/ar/skincare-guide/` served Arabic chrome, an Arabic canonical and a correct
hreflang set inside a document declaring itself English.

## What changed

Two attributes, on five lines, copied from `layouts/store.blade.php` and argued
out at length in `resources/views/invoices/document.blade.php`:

```blade
<html lang="{{ \App\Support\Locale::htmlLang() }}" dir="{{ \App\Support\Locale::direction() }}">
```

`dir` goes through `Locale::direction()` and **never through the language**.
Arabic can be switched on while the mirrored layout is still being built, and
`direction()` is the single place that knows which of the two states the shop is
in. This lane offers no second opinion on that contract; it applies it.

Measured on all six documents (the five above plus `/shop/` as the control),
in all three states:

| state | `/skincare-guide/` | `/ar/skincare-guide/` |
|---|---|---|
| Arabic off | `<html lang="en" dir="ltr">` | 404 — `/ar` does not exist |
| Arabic on, mirrored off | `<html lang="en" dir="ltr">` | `<html lang="ar" dir="ltr">` |
| Arabic on, mirrored on | `<html lang="en" dir="ltr">` | `<html lang="ar" dir="rtl">` |

Identical for `/skin-quiz/`, `/app/`, `/reviews/`, a post page and `/shop/`.
Pinned in `tests/Feature/StandaloneDocumentsDeclareTheirLanguageTest.php`, which
also sweeps every storefront view for a hard-coded `lang` or `dir`, or an
`<html>` that states no direction at all, so a sixth cannot arrive quietly.

## What setting `dir` exposes, reported and not papered over

### 1. Two physical `left` rules that will not mirror

Read with the repository's own declaration reader, `Tests\Support\CssDirection`,
not with grep:

| file | physical direction declarations left | logical | declarations parsed |
|---|---|---|---|
| `store/blog.blade.php` | 1 → **0** | 3 → 4 | 224 |
| `store/post.blade.php` | 1 → **0** | 2 → 3 | 223 |
| `store/skin-quiz.blade.php` | 1 | 9 | 545 |
| `store/app.blade.php` | 4 | 22 | 1400 |
| `store/review-wall.blade.php` | 0 | 4 | 302 |
| `layouts/store.blade.php` (control) | 1 | 3 | 104 |

Every one of the eight, named:

- `blog.blade.php` and `post.blade.php` — `.mnav { left: 0 }`. **Converted, and
  it was owed to this change.** `docs/rtl-audit.md` §11.4 deferred exactly these
  two and said why: "`[dir="rtl"]` cannot match there", because the documents
  declared no direction, so converting them bought nothing while moving a live
  page's English bytes and turning `StorefrontEnglishUnchangedTest` red. It
  asked for them to be done "when someone gives those views a real
  `<html dir>`, in one change that repins the guard once", which is this one.

  `left: 0` is `inset-inline-start: 0` now, **and** the transform is flipped
  under `[dir="rtl"]` — the second half matters more than the first. The panel
  hides itself with `translateX(-100%)`, which has no logical form, so a logical
  inset on its own would pin it to the reading edge under RTL while the
  transform went on pushing it the other way: off screen in English, ON screen
  in Arabic. Both halves are copied from `kbb.css`'s own `.mnav`, which is this
  same panel in the shared layout, override before `.mnav.on` so the open state
  still wins. The two rows are out of the audit's physical table, which its own
  paired guards in `RtlReadinessTest` require.
- `skin-quiz.blade.php` and `app.blade.php` — `.toast { left: 50% }`. Direction
  neutral: a centring rule paired with `translateX(-50%)`. The shared layout's
  own `.qv-btn { left: 50% }` is the same shape and was left physical by the RTL
  lane for the same reason.
- `app.blade.php` — `.drawer { right: 0 }`, `.drawer.left { right: auto }`,
  `.drawer.left { left: 0 }`. A deliberately named left/right pair: the class is
  called `.drawer.left` and the markup chooses a side by name, so these describe
  a physical side on purpose and are not candidates for conversion.

Nothing else in the five documents carries a physical direction.

### 2. The larger gap: no Arabic-capable webfont on any of the five

`layouts/store.blade.php` swaps the typeface on an Arabic page — it loads
**Cairo**, "drawn as an Arabic face with a Latin companion", and since T6's
manual half (`docs/rtl-audit.md` §11.8) it also **appends** Cairo to the
`font-family` stack inside the same `@if ($kbbLocale !== DEFAULT)`, which is
what makes the face actually render rather than merely download. None of the
five standalone documents got either half. Fetched with Arabic and the mirrored
layout both on:

| URL | webfonts linked | `font-family` rules naming Cairo |
|---|---|---|
| `/ar/skincare-guide/` | Poppins | 0 |
| `/ar/<post>/` | Poppins | 0 |
| `/ar/skin-quiz/` | Poppins | 0 |
| `/ar/app/` | Fraunces, Hanken Grotesk | 0 |
| `/ar/reviews/` | Poppins | 0 |
| `/ar/shop/` (control) | Poppins, **Cairo**, Cormorant Garamond | **5** |

Poppins, Fraunces and Hanken Grotesk have no Arabic coverage, so the browser
falls back per glyph to whatever system Arabic face it has. The page is
readable; it is not the shop's typeface, the weights do not match, and the line
heights the inline stylesheet sets were measured against Poppins.

**This predates the change and is not made worse by it** — the text was already
Arabic; only the declaration was wrong.

**It is a named hand-back to T6.** §11.8 records the Cairo fix as covering "the
Arabic home, shop, product, cart and checkout pages", and says what it left:
"the blog views from §9.5, which are not bilingual at all". They are bilingual
now — that is what §9.5 was waiting for and what this change did — so the
sentence that excused them no longer holds. The repair is §11.8's own two lines
(the `<link>` and the appended `font-family`, appended and never substituted so
the English page is unchanged byte for byte) applied inside each document's own
`<head>`.

Not done here, and the reason is not squeamishness: §11.8's fix was accepted on
the strength of a per-codepoint rendering measurement (960 → 1800 elements on a
Cairo-capable stack, at 479.05 vs 537.88 for the two weights). Repeating that
apparatus on five more documents is T6's work with T6's instruments, and adding
a webfont by eye to the shop's heaviest pages without it is how a page-weight
regression gets in.

> **DONE — Lane FS.** The apparatus was repeated rather than skipped. All five
> documents now link and NAME Cairo, through one partial
> (`resources/views/partials/arabic-face.blade.php`) so that the gate and the
> append rule exist once rather than five times. Measured the way §11.8
> measured: before, each of the five matched neither its own stack nor Cairo
> — `/ar/reviews/` returned the *same* width at 400, 700 and 800, the signature
> of a fallback face with no weight axis; after, all five match Cairo to the
> hundredth of a pixel at all three weights, and every text-bearing element on
> every one of them is on a Cairo-capable stack (0/24 → 24/24, 0/25 → 25/25,
> 0/11 → 11/11, 0/366 → 366/366, 0/12 → 12/12). English is byte-identical on all
> seven pages. Tables, method and the one correction to the method — the test
> string must contain no spaces, because U+0020 is outside Google's `arabic`
> unicode-range — are in `docs/FS-ARABIC-TYPOGRAPHY.md`, and the contract is
> pinned by `tests/Feature/StandaloneDocumentsArabicFaceTest.php`.

### 3. `[dir="rtl"]` rules: none, anywhere

None of the five inline stylesheets carries a single `[dir="rtl"]` selector, and
neither does the shared one — the mirrored layout is delivered by logical
properties throughout, so there is no per-document RTL stylesheet any of these
five could be said to be missing.

---

## Files this lane may not edit

Nothing is owed to `routes/web.php` (no route added, so no `clear_caches_*`
migration either), `bootstrap/app.php`, or `resources/views/admin/app.blade.php`
— its Mail-screen note that "a blank password box means unchanged, so it is not
sent at all" stays true, and nothing in it describes the empty-box refusal this
lane removed. `KBB-Master-Plan.md` and `KBB-Progress-Dashboard.html` are the
integrator's.
