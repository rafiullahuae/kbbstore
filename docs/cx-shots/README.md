# Lane CX — the pictures, and the numbers under them

Chromium, through Playwright, over the REAL pages: each one rendered by this
branch's own controllers through the test client, written to a throwaway web
root beside a copy of `public/build`, and served by `php -S`. Nothing here is a
mock-up of the markup.

Two viewports throughout: **390** (phone) and **1280** (desktop). Section 2 is
the printed documents, which are A4 and are measured under `@media print`
instead; both are stated where they are used.

---

## 1. The free-delivery bloom — `bloom-*.png`

The squeeze cart page's progress bar. `AED 1000` threshold, so the three fill
states are a AED 4 basket (0%), a AED 450 basket (45%) and a AED 1200 basket
(100%).

`bloom-before-*` is the same page with the pre-fix stylesheet — Arabic only,
because English never moved.

### What the layout engine measured

`document.documentElement.scrollWidth` is **390** at 390 and **1280** at 1280 in
every one of the twelve shots, English and Arabic: no horizontal overflow at any
fill.

| shot | dir | fill spans | bloom sits at | animation |
|---|---|---|---|---|
| en-045 @390 | ltr | 29.00 → 178.39 | inline-end of fill (x 178.39) | `cpgbloom` |
| en-045 @1280 | ltr | 853.00 → 1008.69 | inline-end of fill (x 1008.69) | `cpgbloom` |
| en-100 @1280 | ltr | 853.00 → 1199.00 | inline-end of fill (x 1199.00) | `cpgbloom` |
| ar-045 @390 | rtl | 211.61 → 361.00 | **x ≈ 211.61**, the leading edge | `cpgbloomrtl` |
| ar-045 @1280 | rtl | 271.31 → 427.00 | **x ≈ 271.31**, the leading edge | `cpgbloomrtl` |
| ar-100 @1280 | rtl | 81.00 → 427.00 | **x ≈ 81.00**, the leading edge | `cpgbloomrtl` |

At 0% the Blade's `cpg-flat` class hides both pseudo-elements: computed
`display: none` in both directions, at both widths.

### What it looked like before

Same Arabic pages, pre-fix stylesheet — `right: 0` plus `translate(50%, -50%)`:

| shot | fill's leading edge | bloom sat at | error |
|---|---|---|---|
| ar-045 @390 | 211.61 | 361.00 (+13 from the translate) | **149 px** |
| ar-045 @1280 | 271.31 | 427.00 (+13) | **156 px** |
| ar-100 @390 | 29.00 | 361.00 (+13) | **332 px** |

— a glowing dot at the far end of the track, which is the end that means "you
have it", on a bar that says the shopper is AED 550 short.

---

## 2. The printed invoice — `invoice-print-*.png`

The **shipped** documents — `docs/invoice-previews/invoice.html` and
`packing-slip.html`, the files the suite regenerates — rendered under
`@media print` and then put through Chromium's own `page.pdf()`, which is the
same path the operator's Ctrl+P takes. All four produced a real PDF (`%PDF-`
header, 60-67 KB).

The right-hand column is each sheet with `dir` forced to `rtl`. Neither document
turns round today: `Locale::direction()` answers from the shop's second switch
and the mirrored layout ships off. Forcing it is how you see the day it is
switched on, which is the whole subject.

| | `dir=ltr` | `dir=rtl` (forced) |
|---|---|---|
| `.fact` divider | border-right 1px, left 0 | border-left 1px, right 0 |
| `.fact:last-child` | none either side | none either side |
| `lines thead th` | text-align start | text-align start |
| `lines td:first-child` | padding-left 0, right 8 | padding-right 0, left 8 |
| `lines td:last-child` | padding-right 0, left 8 | padding-left 0, right 8 |
| `totals td.num` | padding-left 26px | padding-right 26px |
| `.label` letter-spacing | 1.1px | normal |
| `.doctype` letter-spacing | 0.88px | normal |

The `dir=ltr` column is exactly what the physical rules produced before the
conversion — rule 1 measured rather than asserted.

`invoice-print-forced-rtl.png` is the right-hand column above. `invoice-print-ar.png`
is a REAL Arabic order — `orders.locale = 'ar'`, both language switches on, the
shipped Arabic strings published — rendered through
`/admin-api/orders/{id}/invoice`, so its furniture is Arabic as well as
mirrored. That is the sheet an Arabic customer would actually be sent.

### The barcode, which must not mirror

Measured on the packing slip, which is the sheet that carries it:

| | `.bc` direction | bars | first bar x | last bar x |
|---|---|---|---|---|
| `dir=ltr` | ltr | 37 | 12.84 | 163.41 |
| `dir=rtl` (forced) | **ltr** | 37 | 1114.59 | 1265.16 |

Ascending in both: the container moves to the other side of the page with the
layout, the symbol inside it does not turn round.
