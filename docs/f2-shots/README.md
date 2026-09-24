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
