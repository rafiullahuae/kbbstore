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
| `store/blog.blade.php` | 1 | 3 | 224 |
| `store/post.blade.php` | 1 | 2 | 223 |
| `store/skin-quiz.blade.php` | 1 | 9 | 545 |
| `store/app.blade.php` | 4 | 22 | 1400 |
| `store/review-wall.blade.php` | 0 | 4 | 302 |
| `layouts/store.blade.php` (control) | 1 | 3 | 104 |

Every one of the eight, named:

- `blog.blade.php` and `post.blade.php` — `.mnav { left: 0 }`. **This is the one
  that is wrong under RTL**: the mobile navigation sheet slides in from the left
  of the viewport on a mirrored page, where every other sheet on the site comes
  from the reading edge. Two declarations, one rule each, and the fix is
  `inset-inline-start`. Left alone deliberately: the inline stylesheets in these
  files belong to `lane/rtl-logical-properties`, whose audit
  (`docs/rtl-audit.md`) already counts them, and bolting one conversion on here
  would put this lane's fingerprints on another lane's floor numbers.
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
**Cairo**, "drawn as an Arabic face with a Latin companion", and says so at
length above the `<link>`. None of the five standalone documents ever got that
swap. Fetched with Arabic and the mirrored layout both on:

| URL | webfonts served |
|---|---|
| `/ar/skincare-guide/` | Poppins |
| `/ar/<post>/` | Poppins |
| `/ar/skin-quiz/` | Poppins |
| `/ar/app/` | Fraunces, Hanken Grotesk |
| `/ar/reviews/` | Poppins |
| `/ar/shop/` (control) | Poppins, **Cairo**, Cormorant Garamond |

Poppins, Fraunces and Hanken Grotesk have no Arabic coverage, so the browser
falls back per glyph to whatever system Arabic face it has. The page is
readable; it is not the shop's typeface, the weights do not match, and the line
heights the inline stylesheet sets were measured against Poppins.

**This predates the change and is not made worse by it** — the text was already
Arabic; only the declaration was wrong. But `lang="ar"` is the attribute a font
stack would key off, so the two belong together, and whoever owns the bilingual
typography should take these five with the layout. Not fixed here: adding a
webfont to five documents is a decision about page weight on the shop's
heaviest pages, not a correction.

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
