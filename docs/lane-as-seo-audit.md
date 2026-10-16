# Lane AS: SEO audit of the Arabic storefront

Measured on 16 October 2026 against the Arabic-ON preview (`tools/arr-preview.sh`: Arabic on, mirrored on, the
Arabic sample catalogue published). Every page type was fetched in both languages with `tools/as-seo-audit.py`.
Every `/ar` URL in the sitemap (79 of them) was then swept for image alt text and the `<h1>` count. Raw output is in
`storage/as-logs/before.json` and `after.json`, which are local and not committed. The Google-result data (title,
description, length, canonical, robots) for every page in both languages is in
`docs/lane-as-shots/google-snippets.json`.

The preview has no product photographs (the fixture's photo folder is not in this checkout), so the pictures are
blank boxes in the screenshots. The `<img>` tags and their alt text are real.

## Summary

The Arabic SEO layer was already mostly right. `<html lang="ar" dir="rtl">`, the Arabic title and description, the
canonical pointing at its own `/ar` URL, reciprocal hreflang pairs (ar, en and x-default), Arabic JSON-LD with `/ar`
URLs, exactly one h1, the sitemap and robots.txt all passed. **Five gaps** were found, and all five are fixed
on the Arabic side only:

| # | Gap on `/ar` | What it looked like | Fix | Pinned by |
|---|---|---|---|---|
| 1 | **Stored English alt printed on Arabic pages** | Once Catalog → Image SEO had written its alt text, `/ar/product/...` described its photographs in English (for example "Anua Azelaic … by Anua"). Product cards, the gallery and the home grid all go through `Product::altFor()`. | On a non-default language `altFor()` returns the Arabic alt for the picture if one is stored. Otherwise it builds the automatic Arabic sentence from the Arabic product and brand names. It falls back to the stored English only when the product has no Arabic name. English never enters this branch. | `ArabicSeoLaneAsTest` cases 1 and 2. Mutation: disable the block and case 1 goes red (checked). |
| 2 | **Image SEO could write English only** | The screen said "English only: … shown on the Arabic shop too". | One run now writes both languages. Arabic alts go into `translations` (group `products`, field `image_alts`, published, holding a JSON map of URL → alt), built by `AltText::proposeArabic()` from the published Arabic names. Undo restores both languages, using `ar:`-prefixed entries in the existing `image_alt_changes` ledger, so no migration is needed. A product without an Arabic name gets no Arabic alt. The ALT tab preview shows the Arabic line read-only under each English one. Progress counts are not inflated by the new row. | Cases 3–7 |
| 3 | **No `og:locale`** (on either language) | WhatsApp, Facebook and LinkedIn read Arabic link cards as `en_US`. | Arabic pages print `og:locale=ar_AE` and `og:locale:alternate=en_AE`. The English head is unchanged, because an absent tag already means en_US. | Case 8 |
| 4 | **Brand CollectionPage name in Latin** | `/ar/brands/anua/` had the title, h1 and breadcrumb as "أنوا", but the JSON-LD `CollectionPage.name` was "Anua". | `$brand->t('name')`, which on English returns the column, so English is unchanged. | Case 9 |
| 5 | **A browser-translated Arabic page stays machine-translated** | This is the owner's ask. | See "Translation hand-off" below. | Cases 10–12, plus a Chromium run |

## Page by page (Arabic, after the fixes; 390 and 1280 give the same head)

| Page | lang/dir | Title (chars) | Description (chars) | Canonical | hreflang ar/en/x-default | og/twitter Arabic | og:locale | JSON-LD | h1 | robots | Image alts |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Home `/ar/` | ar/rtl ✓ | Arabic (71) | Arabic (171; the English is 182) | own /ar ✓ | ✓ reciprocal | ✓ | ar_AE ✓ (was missing) | OnlineStore, WebSite, 4× ItemList: Arabic names, /ar URLs ✓ | 1 | index,follow | 29/29 Arabic |
| Category | ✓ | Arabic (22) | Arabic (69) | ✓ | ✓ | ✓ | ✓ (was missing) | CollectionPage `inLanguage: ar`, Arabic items, BreadcrumbList Arabic + /ar ✓ | 1 | index,follow | 6/6 Arabic |
| Brand | ✓ | Arabic (20) | Arabic (131) | ✓ | ✓ | ✓ | ✓ (was missing) | CollectionPage **name now Arabic** (was "Anua") ✓ | 1 | index,follow | 5/5 Arabic |
| Product | ✓ | Arabic (65) | Arabic (112) | ✓ | ✓ | ✓, og:image:alt Arabic | ✓ (was missing) | Product: Arabic name, brand and description, /ar url, Offer (price, AED, InStock, VAT incl.), AggregateRating 4.8/4 ✓; BreadcrumbList Arabic ✓ | 1 | index,follow | 23/23 Arabic; **stored English alts now replaced** |
| Blog index | ✓ | Arabic (31) | Arabic (118) | ✓ | ✓ | ✓ | ✓ (was missing) | CollectionPage `ar` ✓ | 1 | index,follow | 2/2 Arabic (one "no alt" hit is inside an HTML comment, not a tag) |
| Blog post | ✓ | Arabic (62) | Arabic (67) | ✓ | ✓ | ✓ | ✓ (was missing) | Article `inLanguage: ar`, Arabic headline/author ✓ | 1 | index,follow | 2/2 Arabic; gallery pictures in the description carry what the Arabic body holds |
| Content page `/ar/about/` | ✓ | Arabic (22) | Arabic (105) | ✓ | ✓ | ✓ | ✓ (was missing) | Organization/WebSite ✓ | 1 | index,follow | none on page |
| Search `/ar/shop/?s=` | ✓ | "البحث: toner" (the shopper's own word) | Arabic | `/ar/shop/` | ✓ | ✓ | ✓ | – | 1 | index,follow + canonical to /ar/shop/ (same as English; not changed) | – |
| Cart, checkout, account | ✓ | Arabic | Arabic | own /ar | ✓ | ✓ | ✓ | – | 1 | **noindex, nofollow** ✓, and Disallow in robots.txt | – |
| 404 | ✓ | Arabic | Arabic | own URL | none ✓ | ✓ | ✓ | – | 1 | **noindex, nofollow** ✓ | Arabic |

The English pages, fetched side by side, were identical before and after this lane in every audited field
(`before.json` and `after.json` differ only in Arabic rows). `StorefrontEnglishUnchangedTest` pins the bytes.

### Site files

- **sitemap.xml**: 158 URLs, 79 English and 79 Arabic. Every English URL has its `/ar` twin. Each `<url>` carries
  the en, ar and x-default `xhtml:link` alternates and its images. Nothing private appears in it (no cart,
  checkout, account, wishlist, API or admin URLs). ✓
- **robots.txt**: `Allow: /`, and the private paths are disallowed in both languages (`/cart` and `/ar/cart`, and
  so on). Nothing blocks `/ar/`. ✓
- **Arabic preview link** (Arabic off, owner previewing): every page is `noindex, nofollow`, as designed. With
  Arabic ON the noindex is not printed, so there is no accidental noindex. ✓

### Looked at and deliberately left alone

- **`inLanguage` on Product**: schema.org `Product` has no `inLanguage` property (it belongs to CreativeWork). The
  page's language is stated by `<html lang>`, by hreflang, and by `inLanguage` on the CollectionPage, Article and
  WebSite nodes, which do carry it. Adding it to Product would produce a validator warning for no gain.
- **Search results are indexable, with the canonical pointing at /shop/**: this is the same on English. Changing
  it would move the English page, which the owner did not ask for. Worth a decision of its own.
- **Home description is 171 characters**: it is the translation of the English, which is 182. Google cuts at
  roughly 155–160, so both are trimmed the same way. This is the owner's copy (SEO → default description).
- **og:locale on English** (`en_AE`): not added, so that the English head stays byte-identical. An absent tag
  already means en_US to every reader.

## Translation hand-off ("browser translated the page → open the real English page")

**Design: client side only, no server redirect.**

- `resources/js/kbb/translate-away.js` is a step in the existing deferred module bundle (`app.js`), not a new
  script. On an **Arabic page with the switch on**, the server adds `"trEn":1` to `window.KBB`. English pages never
  get the flag.
- One `MutationObserver` on `<html>` watches the `class` and `lang` attributes. It uses no timers, polling or
  layout reads. It fires when Chrome adds `translated-ltr`/`translated-rtl`, or when any translator (Edge) changes
  `lang` away from `ar`. It then sets `kbb_lang=en` (180 days, `SameSite=Lax`, Secure on https) and calls
  `location.replace()` to the path of the page's own `<link rel="alternate" hreflang="en">` on the current
  origin. The current origin is used so a www / no-www difference cannot drop the cookie.
- **Remembered choice**: if an Arabic page loads with `kbb_lang=en` and the shopper arrived from **another site**
  (Google, WhatsApp, or a typed address, judged by the referrer), it hands over immediately.
- **Arabic wins**: when the cookie is set, any click on a link to an Arabic page (by `/ar` path, `hreflang`, or
  `lang`) clears the cookie first, so an explicit choice of Arabic is never overridden.
- **Never a loop**: the English page never runs the observer or the remembered hand-off.
- **Never crawlers**: there is no server redirect, so every bot gets the unchanged HTML. Bot user agents are
  skipped. Crawlers neither translate nor keep cookies, and a prefetch runs no script.
- **Admin**: Translation → Language settings → "Open English when the browser translates the page". It is ON when
  never saved, because the owner asked for it. Turning it off removes the flag, so the script does nothing.

Measured in Chromium (`node tools/as-translate.cjs`, results in `docs/lane-as-shots/translate-run.json`):

- Chrome marker (class + lang) → English: PASS
- Edge marker (lang only) → English: PASS
- English page with the marker does not move: PASS
- Remembered cookie → English on landing: PASS
- Clicking an Arabic link clears the cookie and the page stays Arabic: PASS
- Googlebot user agent is not redirected: PASS
- No console errors
- `scrollWidth` = viewport at 390 and 1280
