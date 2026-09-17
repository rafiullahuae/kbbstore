# Lane FS — the Arabic face on the five standalone documents, the signed numbers, and the arrowheads

Three items, all from `docs/rtl-audit.md` §9.2 / §11.8 / §11.10 and
`docs/rtl-standalone-documents.md` §2. Everything below was measured; the
numbers are in the tables, and where a measurement contradicts what is written
down elsewhere in these documents it is said plainly rather than quietly fixed.

**Method for all of it.** `php -S` in front of a copy of
`public-web-root/index.php` with a fall-through router, its own SQLite database,
Arabic and the mirrored layout switched on, admin session for `/app/`. Chromium
1194 at `/opt/pw-browsers/chromium-1194/chrome-linux/chrome`,
`browser.newContext({viewport})`. Cairo is served from Google's own bytes off
that same preview, because this sandbox's egress proxy breaks
`fonts.gstatic.com` for a capture browser — the same workaround §11.8 used.

---

## 1. Five pages had no Arabic-capable font

### What it was, reproduced before changing anything

Lane FK's table in `docs/rtl-standalone-documents.md` §2 is exactly right, and
was re-measured rather than taken on trust:

| URL | webfonts linked | `font-family` rules naming Cairo |
|---|---|---|
| `/ar/skincare-guide/` | Poppins | **0** |
| `/ar/<post>/` | Poppins | **0** |
| `/ar/skin-quiz/` | Poppins | **0** |
| `/ar/app/` | Fraunces, Hanken Grotesk | **0** |
| `/ar/reviews/` | Poppins | **0** |
| `/ar/shop/` (control) | Poppins, **Cairo**, Cormorant Garamond | **5** |

### The measurement, FL's method repeated

The same Arabic string at 40px, set in the page's own inherited stack, against a
control face declared from Cairo's real bytes. Each character's box is read back
and sorted by x, so what is compared is painted glyphs.

**One correction to the method itself.** The string must contain **no spaces**.
U+0020 is outside Google's `arabic` unicode-range, so a stack whose only family
is Cairo renders its spaces from the browser default while the page's own stack
renders them from whatever follows Poppins. That alone put the `/ar/shop/`
control 2.4px away from itself at 40px and would have been read as "the control
does not match either". With a space-free string the control matches to the
hundredth of a pixel, which is what made the rest of the table trustworthy.

| page | | 400 | 700 | 800 | elements on a Cairo-capable stack |
|---|---|---|---|---|---|
| `/ar/skincare-guide/` | before | 343.67 | 411.91 | 411.91 | 0 / 24 |
| | after | **337.61** | **379.48** | **393.86** | **24 / 24** |
| `/ar/<post>/` | before | 343.67 | 411.91 | 411.91 | 0 / 25 |
| | after | **337.61** | **379.48** | **393.86** | **25 / 25** |
| `/ar/skin-quiz/` | before | 343.67 | 411.91 | 411.91 | 0 / 11 |
| | after | **337.61** | **379.48** | **393.86** | **11 / 11** |
| `/ar/app/` | before | 343.67 | 411.91 | 411.91 | 0 / 366 |
| | after | **337.61** | **379.48** | **393.86** | **366 / 366** |
| `/ar/reviews/` | before | 343.67 | 343.67 | 343.67 | 0 / 12 |
| | after | **337.61** | **379.48** | **393.86** | **12 / 12** |
| Cairo, measured directly | | 337.61 | 379.48 | 393.86 | |
| `/ar/shop/` (control, already fixed) | | 337.61 | 379.48 | 393.86 | 418 / 418 |
| `/ar/` (control, already fixed) | | 337.61 | 379.48 | 393.86 | 608 / 608 |

Before, every one of the five matched neither the control nor each other at
different weights — `/ar/reviews/` returned the *same* width at 400, 700 and 800,
which is the signature of a fallback face with no weight axis at all. After, all
five match Cairo exactly at all three weights, and the three weights differ from
each other, so the variable font's axis is doing real work.

### How it is built, and the two constraints kept

`resources/views/partials/arabic-face.blade.php` is the only place that emits
the link and the stack, and `App\Support\ArabicFace` is the only place that
knows how to append. Each document passes its **own** Latin stack and its own
weight list.

- **Append, never substitute.** `ArabicFace::append()` splices at index 1 — after
  the first family, before the generic fallbacks — so there is no spelling of
  the argument that puts Cairo first. English fetches nothing extra: all seven
  English pages were fetched before and after and are **byte-identical**, which
  `StorefrontEnglishUnchangedTest` also pins.
- **Gate on the language, not on `isRtl()`.** `Locale::current()`. There is a
  test that fails if the gate moves to the direction.

**One whitespace trap worth knowing about.** Blade compiles `@include` to a PHP
tag and **PHP swallows one newline immediately after `?>`**. Every natural
placement of the include therefore removed a newline from the *English*
document. The includes are glued to the end of the webfont `<link>` line and the
following line restores it; that is why they look cramped, and it is why English
comes back byte-identical.

### Weights, and what they cost

Re-measured against `fonts.googleapis.com` with a Chrome user agent. One arabic
WOFF2 in every case — `SLXV…QyyS4J0.woff2`, 30,896 bytes, sha256
`748022f50c427456…` — which is the same file §10 weighed:

| request | CSS | arabic files | bytes |
|---|---|---|---|
| `Cairo:wght@400;500;600;700` | 6,951 B | 1 | 30,896 |
| `Cairo:wght@400;500;600;700;800` | 8,689 B | 1 | 30,896 |
| `Cairo:wght@300;400;500;600;700;800` | 10,427 B | 1 | 30,896 |
| `Cairo:wght@400;600;700;800` (the shared layout's) | 6,951 B | 1 | 30,896 |

So a weight costs stylesheet bytes on Arabic pages and **no font bytes**, and
each document asks for the weights its own Latin link asks for rather than a
padded union. §10's "weight 800 costs nothing" is confirmed independently, and
so is its 6,952 B figure (6,951 here; the difference is one trailing newline).

**Google serves three files, not one.** §10's table says "1 file" for the arabic
subset and that is right, but the stylesheet declares three `@font-face` sets —
`arabic` (30,896 B), `latin` (33,820 B) and `latin-ext` (16,648 B). Only the
arabic one is ever fetched on these pages, because Cairo is never first in any
stack, so the page-weight figure in §10 stands. Worth writing down before
somebody measures the stylesheet and thinks the cost has tripled.

### One defect the measurement turned up on the way

`store/blog.blade.php` and `store/post.blade.php` give buttons no font at all.
A `<button>` does not inherit `font-family` — the UA stylesheet sets it — and
unlike the other three documents neither of these carries
`button{font-family:inherit}`. So `.chip` (the journal's tag filter, whose labels
*are* translated) and `.mnav-x` rendered in the UA's Arial while everything else
moved to Cairo: **22 of 24** text-bearing elements, not 24. The Arabic half is
fixed here, scoped to `html[lang="ar"]`. **The English half is not**: English
buttons on those two pages still render in Arial rather than Poppins, which is a
real defect of those two documents, and fixing it moves English bytes, which this
lane may not do. It belongs to whoever owns their typography.

---

## 2. `-30%` read as `30%-`

### The measurement, and the two things it corrects

Rendered in Chromium, each character's box read back and sorted by x. Logical
string `-30%`:

| | `<html dir=rtl>` alone | `dir=rtl` in Arabic text | `<html dir=ltr>` alone | `dir=ltr` in Arabic text |
|---|---|---|---|---|
| plain HYPHEN-MINUS | `30%-` | `%30-` | `-30%` | `%30-` |
| **U+2212 MINUS SIGN** | `30%−` | `%30−` | `−30%` | `%30−` |
| CSS `unicode-bidi:isolate` | `30%-` | `30%-` | `-30%` | `-30%` |
| CSS `unicode-bidi:plaintext` | `-30%` | `-30%` | `-30%` | `-30%` |
| **U+2066 … U+2069** | `-30%` | `-30%` | `-30%` | `-30%` |
| `<bdi>` | `-30%` | `-30%` | `-30%` | `-30%` |

1. **§11.10 offers U+2212 MINUS SIGN as an alternative to the isolate. It is not
   one.** U+2212 carries bidi class ES exactly as HYPHEN-MINUS does; it reorders
   identically and only its shape differs. That sentence in the audit is wrong
   and someone would have acted on it.
2. **The defect is not confined to the mirrored layout.** In an
   `<html dir="ltr">` document, `-30%` inside Arabic text still paints `%30-`,
   because an Arabic word opens a right-to-left run wherever it stands. So the
   isolate is gated on the **language**, never on `Locale::isRtl()` — the same
   conclusion the typeface reached, for a different reason and with its own
   measurement.
3. CSS `unicode-bidi: isolate` is **not** enough (the isolated run is still
   RTL). `plaintext` does work — but the storefront serves **built** CSS, so a
   stylesheet fix ships inert until somebody rebuilds. The fix is in the string.

**Wrap the number, never the label.** The isolate forces its contents
left-to-right, which is right for `-30%`, `+2` and `-AED 25.00` and **wrong** for
a label that is genuinely Arabic: measured, `⁦خصم 30%⁩` paints `30 مصخ%`. A whole
label that is Arabic in one language and English in another wants `<bdi>`, whose
default `dir=auto` picks the direction from the label's own first strong
character — measured correct for `-30% OFF`, for an Arabic label and for a bare
`-30%`. That is what the hand-off below recommends.

### What it actually looked like, in the real page

`/ar/product/relief-sun-rice-probiotics-spf50/` at 1440×1000, painted order read
back character by character:

| element | before | after |
|---|---|---|
| `.lbl` — the gallery's sale badge | `30%-` | **`-30%`** |
| `.fcontact` — the footer phone number | `2611 505 58 971+ 📞` | **`+971 58 505 2611 📞`** |
| `.hi .tx span` — the header support chip | `2611 505 58 971+` | **`+971 58 505 2611`** |
| `.off` — the product page's own ribbon | `30%-` | `30%-` — **handed off, Lane FP** |
| `.lbl` — `store.product_card.label_off` | `OFF 30%-` | `OFF 30%-` — **handed off, Lane FP** |

**The phone number was the find of the sweep, and it is worse than the ribbon.**
`+971 58 505 2611` does not merely move its `+`: the three space-separated groups
each become their own number run and are laid out right to left, so the whole
number paints **backwards** — `2611 505 58 971+`. It is on every page of the
Arabic shop, in the header chip, the footer contact line, the mobile menu and the
home page's trust card. Nobody had reported it; it came out of rendering the
Arabic storefront and reading the text nodes back, which is the sweep §11.10 asked
for.

### Where the isolate went

`App\Support\Bidi::number()` — a no-op on an English page, idempotent, and
documented with the measurement above.

| file | what |
|---|---|
| `app/Support/SupportContact.php` | `phone()`, the accessor this class already defines as *what a surface prints* (as against `whatsappDigits()`, which is what a link dials). Doing it here also reaches `store/home.blade.php`, which this lane may not edit. |
| `resources/views/partials/mobile-chrome.blade.php` | the mobile menu prints `whatsapp()`; isolated at the print site, so the dialled accessor stays clean |
| `resources/views/partials/product-gallery.blade.php` | the product gallery's `-30%` badge |
| `resources/views/partials/home/grid.blade.php` | the home grid's sale badge |
| `resources/views/components/product-grid.blade.php` | the collection grid's sale badge |
| `resources/views/partials/product-reviews.blade.php` | the `+2` more-photos overlay |
| `resources/views/partials/reviews.blade.php` | the same, in a `data-more` attribute |
| `resources/views/partials/checkout/thumbs.blade.php` | the `+3` more-items thumbnail |
| `resources/views/store/cart-inner.blade.php` | the discount row's `– AED 25.00` (EN DASH reorders too — measured `AED 25.00 –`) |
| `app/Services/Payments/GatewayRegistry.php` | every gateway's `fee_html`, `+AED 10.00` |
| `app/Http/Controllers/Store/CheckoutController.php` | the cash-on-delivery `fee_html` |

---

## 3. Directional glyphs — §9.2

### §9.2's good news, verified rather than inherited

Each glyph rendered twice, **centred in a fixed box** in a left-to-right and a
right-to-left container so that position cannot differ and only shape can, and
the two screenshots compared byte for byte:

| flips itself (Bidi_Mirrored) | does not flip (needs doing by hand) |
|---|---|
| U+203A `›` | U+2192 `→` |
| U+2039 `‹` | U+2190 `←` |
| U+00BB `»` | U+25B6 `▶` |
| U+003E `>` | U+2794 `➔` |
| | U+21A9 `↩` |

So §9.2 is **right** about `›` and the mobile-nav back button, and right that the
mega-menu caret needs nothing. What it does not say is that an *arrow* is not
mirrored by anything, and neither an `<svg>` path nor a background image is a
character at all.

### One thing §9.2 gets wrong

It names `.sarrow.prev` / `.sarrow.next` as an open item. **Nothing renders
them.** `.sarrow` survives only in `kbb.css`; no view, partial, component or
script in this repository emits the class. The home slider's arrows are `.sarr`,
and they are the characters `‹` and `›`, which mirror themselves — so that row of
§9.2 was closed before it was written. `DirectionalGlyphsTest` sweeps
`resources/views` and `resources/js` for the class, so if `.sarrow` ever gains
markup the note becomes live again and the test says so.

### What was mirrored

| where | glyph | fix |
|---|---|---|
| `resources/css/kbb/kbb.css` | `.mm-car`, the mobile mega-menu expand chevron, `<path d="M9 6l6 6-6 6">` | `[dir="rtl"] .mm-car{transform:scaleX(-1)}` |
| `resources/css/kbb/kbb-checkout.css` | `.co-tocart svg`, back-to-cart, `<path d="M15 18l-6-6 6-6">` | `[dir="rtl"] … {transform:scaleX(-1)}` |
| `resources/views/store/app.blade.php` | `.car-btn svg`, the carousel buttons | `[dir="rtl"] .car-btn svg{transform:scaleX(-1)}`, inline, so it ships with the view |
| `resources/views/store/checkout-success.blade.php` | the `→` in the "what happens next" circle | the direction chooses the glyph |
| `resources/js/kbb/mobile-nav.js` | `Shop all … →` in the sub-panel | `onward()`, read off `<html dir>` |
| `resources/js/kbb/search.js` | `View all results … →` in the suggest panel | `onward()` |

The mega-menu chevron's **open** state is deliberately untouched:
`.mm-node.on > .mm-par .mm-car` is (0,4,0) against the mirror's (0,2,0), so the
rotation still wins and the chevron still turns to point down, which reads the
same in both directions. There is a test that fails if anyone adds a
`[dir="rtl"]` twin for it, because the two would then compose and it would point
sideways.

**`Locale::isRtl()` here, and `Locale::current()` for the font — on purpose.** A
*face* belongs to the script, so Arabic needs it even while the mirrored layout
is off. An *arrow* belongs to the layout, and while the layout still reads left
to right, forward is still to the right.

---

## ⚠ THE BUNDLE HAS TO BE REBUILT, OR TWO OF THESE SHIP INERT

`resources/css/kbb/kbb.css`, `resources/css/kbb/kbb-checkout.css`,
`resources/js/kbb/mobile-nav.js` and `resources/js/kbb/search.js` are **built**
assets. The storefront serves the hashed files under `public/build`, and this
lane did not run `npx vite build` — CLAUDE.md and the lane brief both forbid it,
because it empties `public/build` and deletes tracked assets, which took every
product page down once.

Verified, so this is not a theoretical warning:

```
grep -c '\[dir="rtl"\] \.mm-car' resources/css/kbb/kbb.css        -> 1
grep -c '\[dir="rtl"\] \.mm-car' public/build/assets/kbb-*.css    -> 0
```

The inline rule in `store/app.blade.php` and everything in item 1 and item 2
ships with the views and needs no build. **The mobile-menu chevron, the
back-to-cart chevron and the two JavaScript arrows do not.** Commit `cb3c745`
("Rebuild the bundle, because two packages of RTL work were about to ship
inert") is the precedent for what has to happen next.

---

## Hand-offs: exact anchor and replacement

### Lane FP — `resources/views/components/product-card.blade.php`

The badge prints `store.product_card.label_off`, whose English default is
`-:percent% OFF`. The sign, the number and the percent sign are all inside the
**translatable** string, so an isolate around the number cannot reach them and an
isolate around the whole label would force a genuinely Arabic translation
left-to-right (measured: `⁦خصم 30%⁩` paints `30 مصخ%`).

`<bdi>` is the right instrument and it was measured on all three cases. Its
default `dir=auto` takes the direction from the label's own first strong
character: `-30% OFF` → `O` → left-to-right → paints `-30% OFF`; an Arabic
translation → Arabic → right-to-left → paints as Arabic should; a label with no
strong character at all → left-to-right, per the HTML `dir=auto` rule.

**Anchor** (line ~52–54):

```blade
                   . e(__('store.product_card.label_off', ['percent' => $off]))
```

**Replacement:**

```blade
                   . '<bdi>' . e(__('store.product_card.label_off', ['percent' => $off])) . '</bdi>'
```

This changes English bytes (`<bdi>` appears in the English page), so it needs the
English contract repinned with it. Measured: `-30% OFF` paints identically in an
English document with and without the `<bdi>`, so nothing a shopper sees moves.

### Lane FP — `resources/views/store/product.blade.php`

**Anchor** (line ~197):

```blade
            @if ($off)<span class="off">-{{ $off }}%</span>@endif
```

**Replacement:**

```blade
            @if ($off)<span class="off">{{ \App\Support\Bidi::number('-' . $off . '%') }}</span>@endif
```

`Bidi::number()` is a no-op on an English page, so English is unchanged byte for
byte and nothing has to be repinned.

### Lane FP — `resources/views/partials/quick-view.blade.php`

**Anchor** (line ~55):

```blade
        @if ($kbbQvOff >= 1)<span class="qv-off">-{{ $kbbQvOff }}%</span>@endif
```

**Replacement:**

```blade
        @if ($kbbQvOff >= 1)<span class="qv-off">{{ \App\Support\Bidi::number('-' . $kbbQvOff . '%') }}</span>@endif
```

`tests/Feature/SignedNumbersInRtlTest.php` lists both of these by the text they
print, and **fails when they stop printing it** — so the exception deletes itself
rather than outliving the defect.

---

## Found, and deliberately not fixed

- **English buttons on the journal views render in Arial**, not Poppins — see
  item 1. The Arabic half is fixed; the English half moves English bytes.
- **`/app/`'s carousel buttons scroll the wrong way in Arabic.** Their
  `onclick` handlers call `scrollBy({left:-460})` and `{left:460}` whatever the
  direction. The arrowheads are mirrored; the scroll logic is JavaScript on an
  admin-only preview page and is not a typography change.
- **`/app/`'s "View all →" strips** are hard-coded English inside a verbatim
  block where no Blade conditional reaches. `/app/` 404s to a shopper and
  `EnglishRenderWalk` excludes it for that reason. Named as the one exception in
  the directional-glyph sweep.
- **`store/skin-quiz.blade.php` prints `→` three times**, every one inside a
  translatable default (`store.quiz.js_see_routine`, `js_start_sub`,
  `js_start_cta`). The arrow is part of the sentence, so it belongs to whoever
  writes the Arabic sentence; what is left is the English fallback an
  untranslated Arabic page shows, which is the same gap as every other
  untranslated string on that page. Listed by key in the sweep, with a staleness
  check.
- **`App\Support\VatDisplay::…` returns `'+' . $rate . '% VAT added at
  checkout'`** — a hard-coded English sentence. Isolating the `+5%` is not enough:
  measured, the whole English sentence is laid out right-to-left in an Arabic
  page and reads `VAT added at checkout 5%+`. That is an untranslated-string
  problem, not a sign problem, and belongs to the translation lanes.
- **`Money::format()`'s own `'-'`** is left alone. Its output goes to invoices,
  emails and CSV exports as well as to pages, and a formatting control character
  has no business in those. The isolate is applied at the display sites instead.
- **`.beview pre` on the skin quiz** stays in `ui-monospace`. It is a
  backend-payload preview and monospace is the point, but its content can carry
  Arabic values, which will render in the system monospace face.
- **The `→` in `store/home.blade.php`** was checked and is only ever in comments;
  the slider arrows there are `‹`/`›` and mirror themselves. Nothing was needed,
  and the file belongs to Lane FR anyway.

## A note on file ownership

`resources/views/store/blog.blade.php` is Lane FQ's for SEO and schema. This
lane changed it because item 1 names `/ar/skincare-guide/` explicitly and it is
one of Lane FK's five documents. The change is confined to the webfont `<link>`
line in `<head>` and does not touch the `{!! $seo !!}` line or anything else FQ
works on. If the integrator would rather re-apply it by hand, the shape is the
same include shown in this document for the other four.

## A stray symlink in the shared scratchpad, which cost an hour

`/tmp/claude-0/…/scratchpad/kbb-upgrade-app` is a symlink another lane left
behind, pointing at `.claude/worktrees/agent-rtl-eq`. `public-web-root/index.php`
looks for the application in `<webroot>/../../kbb-upgrade-app` **before**
`<webroot>/../kbb-upgrade-app`, so any preview booted one level under the
scratchpad silently runs **another lane's checkout** while rendering this one's
views — the symptom was `Call to undefined method Money::decimalsToDistinguish()`
with a stack trace full of another worktree's `vendor/`. Boot previews at least
two levels down, or check `realpath()` of the candidates first. The symlink was
left in place, since another lane may still be using it.
