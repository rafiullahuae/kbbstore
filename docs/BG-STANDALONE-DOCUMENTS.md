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
| **the brand colour** | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **the site width** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| **the self-hosted Latin webfont** | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `set-appearance-css` | ✅ | — | — | — | — | — |
| the account-panel webfont | ✅ | — | — | — | — | — |
| `MarketingPixels::addToCart()` | ✅ | — | — | — | — | — |

✅ present · ❌ missing and it matters · — correctly absent, argued below

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

## 3 · The self-hosted Latin webfont — **not fixed, and here is the arithmetic**

The layout serves Poppins from this shop (`App\Support\WebFonts`, Lane PERF).
All five documents still link `fonts.googleapis.com`, which is a render-blocking
third-party stylesheet plus one or two `preconnect`s on four pages a shopper
reads.

It is not converted here because the weights do not line up:

| | asks Google for | self-hosted `WebFonts` has | missing |
|---|---|---|---|
| the journal, an article | 400 500 600 700 | 400 600 700 800 | **500** |
| review wall | 400 500 600 700 800 | 400 600 700 800 | **500** |
| skin quiz | **300** 400 500 600 700 800 | 400 600 700 800 | **300, 500** |
| `/app` | Fraunces 400–700, Hanken Grotesk 400–700 | *neither family exists* | all of it |

Converting them silently drops those weights. The journal, an article and the
skin quiz each use `font-weight:500` in their own stylesheets (3, 2 and 3 rules
respectively), so the change would be visible.

> **`kbb.css` uses `font-weight:500` eight times and the shop already serves
> only 400/600/700/800**, so the storefront is already synthesising that weight.
> That makes converting these four *consistent with the shop* rather than worse
> than it — but it is still a typographic change to four pages, decided by
> whoever owns the font pipeline and its measurements, not by this lane.
>
> `/app` is a bigger job again: `WebFonts` has no Fraunces and no Hanken
> Grotesk, so that page needs two families added before it can move at all.

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

**The journal and an article stay white**, because the designed background lives
in `kbb.css` and those documents do not load it. Deleting their own
`background:var(--bg)` was tried and measured: `body` computes to
`rgba(0,0,0,0)` and the page still renders white off the canvas, so it buys
nothing and states less. Giving them the real background costs either loading
`kbb.css` on those documents or copying a ~40 KB data-URI gradient into each.
**Reported, not done.**

---

## 6 · What would close the rest of it

In the order a round should take them:

1. **Add Poppins 300 and 500 to `WebFonts`** (two subsets × two weights, six
   files) and convert the journal, an article, the review wall and the skin quiz
   off `fonts.googleapis.com`. One render-blocking third-party stylesheet and
   two `preconnect`s removed from four pages a shopper reads.
2. **Add Fraunces and Hanken Grotesk to `WebFonts`**, and convert `/app`.
3. **Decide whether the journal and an article should carry the shop's page
   background.** Either they load `kbb.css` — which is a large change with a
   real risk of moving their layout — or the designed background moves into a
   small partial the way the accent just did. The second is the smaller change
   and is what this lane would do next.
4. **Make `/reviews/` and `/skin-quiz/` read `--site-max`** if the owner wants
   his site-width slider to reach them. They carry the variable now and do not
   read it, so today it is inert there; making them read it moves two pages that
   currently work and is a decision, not a fix.
