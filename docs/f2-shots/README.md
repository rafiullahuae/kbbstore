# Lane F round 2 — pictures, and the numbers beside them

Chromium (Playwright, `chromium-1194`), a real HTTP server, a migrated SQLite
database with Arabic and the mirrored layout both switched on.

## 1. The cart badge on `/app` — `before/` against this directory

`store/app.blade.php`'s `syncBottomNav()` drew the bottom-nav cart badge with an
inline `style="…;right:50%;margin-right:-24px"`. An inline declaration beats an
author rule of any specificity, so it also overrode that document's own
`.bnav .count{inset-inline-end:50%}` — which had therefore never applied.

Measured as the badge's centre against its button's centre, so "which side" is a
number rather than an impression:

| | width | `<html>` | `.bnav` | badge centre − button centre |
| --- | ---: | --- | --- | ---: |
| **before** | 390 | `lang="en" dir="ltr"` | flex | **+15.5px** |
| **before** | 390 | `lang="ar" dir="rtl"` | flex | **+1.0px** ← sitting on top of the bag icon |
| **after** | 390 | `lang="en" dir="ltr"` | flex | **+15.5px** |
| **after** | 390 | `lang="ar" dir="rtl"` | flex | **−15.5px** ← mirrored |
| before = after | 1280 | both | `display:none` | not drawn |

`+1.0px` is the defect: both `left:47.75px` and `right:47.75px` resolved at once
(the inline physical pair against the stylesheet's logical pair), so the badge
was stretched across the button's centre instead of sitting at one corner.

`scrollWidth`/`clientWidth` is `445/390` at 390px and `1280/1280` at 1280px, in
both directions, **before and after** — the phone-width overflow is pre-existing
on this admin-only preview page and is untouched here.

### English did not move, and that is a checksum rather than an opinion

| file | before vs after |
| --- | --- |
| `app-en-390.png` | **byte-identical** |
| `app-en-1280.png` | **byte-identical** |
| `app-ar-1280.png` | **byte-identical** |
| `app-ar-390.png` | **differs** — the one pixel region this change is for |

## 2. The order line name — `invoice-*` against `packing-slip-*`

One order (`KBB-F2-AR`, `orders.locale = 'ar'`), one line, two documents:

| document | `<html>` | the line reads |
| --- | --- | --- |
| invoice, 390 and 1280 | `lang="ar" dir="rtl"` | `تونر الأرز المرطب اليومي` |
| packing slip, 390 and 1280 | `lang="en" dir="ltr"` | `Rice Daily Moisturizing Toner 150ml` |

`scrollWidth == clientWidth` at both widths on both documents — no horizontal
scroll.

The invoice's furniture renders its English defaults in these shots because this
fixture database has no `ui` translations seeded; those strings are `__()` calls
and are the interface lane's data, not this change. What this change moved is
the line name, and it is the only thing being claimed here.

## 3. The Journal, verified rather than taken on trust — `journal-*`

Item 1 of this lane's round-2 brief was "give these standalone layouts the real
`lang` and `dir` the rest of the shop has". **That had already landed on the
trunk** — Lane FK gave all five standalone documents
`Locale::htmlLang()`/`Locale::direction()` and Lane FS gave them Cairo. Rather
than cite those two documents, this round loaded the pages:

| page | `<html>` | computed `direction` | Cairo on the `<h1>` | `<h1>` |
| --- | --- | --- | :---: | --- |
| `/skincare-guide/` | `lang="en" dir="ltr"` | `ltr` | no | Skincare tips & the K-beauty edit |
| `/ar/skincare-guide/` | `lang="ar" dir="rtl"` | **rtl** | **yes** | Skincare tips & the K-beauty edit |
| `/f2-journal/` | `lang="en" dir="ltr"` | `ltr` | no | How to layer a K-beauty routine |
| `/ar/f2-journal/` | `lang="ar" dir="rtl"` | **rtl** | **yes** | **كيف ترتّب روتين العناية الكوري** |

`scrollWidth == clientWidth` at 390 and at 1280 on all four — no horizontal
page scroll in either direction.

The article's own words are the Arabic row (`posts.title`, `posts.body` through
`t()`), the header is mirrored and the mobile nav opens from the reading edge.

**What is still English on the Arabic Journal page, and why it is not this
change:** the breadcrumb ("Home / Journal"), the back link, the tag chip and the
footer. Those are `__()` interface strings and this fixture database has no `ui`
translations seeded, so they render their English defaults — which is the
documented fallback, not a defect in these views. The same is true of the
invoice furniture in §2.
