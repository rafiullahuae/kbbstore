# Lane FZ — Order documents (Invoice / Packing slip / Delivery note / Dispatch label)

**Outcome: nothing was built, because all of it already exists, works, and is
tested.** The plan item this lane was opened against is stale. This document is
the evidence for that, plus the gaps that are genuinely still open.

## The plan item, and why it is wrong

`KBB-Master-Plan.md` (Phase 12, the order-detail phase) carries:

```
- [ ] Real PDF generation for Invoice/Packing slip/Delivery note/Shipping Label/
  Dispatch Label — buttons are real, visible placeholders; wiring actual document
  generation is a separate, later piece of work
```

Every clause of that is out of date:

- The buttons are **not** placeholders. All four open real documents.
- Document generation is **not** "a separate, later piece of work". It was done
  by Lane AE (invoice, packing slip) and Lane EK (delivery note, dispatch
  label), and both are merged and live at HEAD.

This is the fourth time this month the plan has described built work as
unbuilt. The entry above sits a few lines below the `2.60.59` entry that
*describes* the placeholders, which is probably how it survived: the line was
true when written and nobody struck it when the lanes landed.

**Only the integrator can strike it** — CLAUDE.md forbids this lane from
editing the plan. The exact edit is at the end of this file.

## What actually exists at HEAD

| Piece | Path |
|---|---|
| Controller | `app/Http/Controllers/Admin/InvoiceController.php` |
| Document builder | `app/Services/Invoices/InvoiceDocument.php` (795 lines) |
| Invoice numbering | `app/Services/Invoices/InvoiceNumbers.php` |
| Routes | `routes/invoices-admin.php` (wired into `routes/web.php:435`) |
| Shared chrome / print CSS | `resources/views/invoices/document.blade.php` |
| The four documents | `resources/views/invoices/{invoice,packing-slip,delivery-note,shipping-label}.blade.php` |
| Barcode (no dependency) | `app/Support/Code128.php` + `resources/views/invoices/partials/barcode.blade.php` |
| Buttons | `resources/views/admin/app.blade.php:12538-12549` |
| URLs on the payload | `app/Http/Controllers/Admin/AdminOrderController.php:228-231` |

Routes, all `GET`, all `whereNumber('id')`, all inside the `web` +
`auth:admin` + `NoStoreAdminApi` admin-api group:

```
/admin-api/orders/{id}/invoice
/admin-api/orders/{id}/packing-slip
/admin-api/orders/{id}/delivery-note
/admin-api/orders/{id}/shipping-label
```

Both required `clear_caches_*` migrations are present
(`2026_10_01_000000_clear_caches_order_invoices.php` for the first pair,
`2026_11_10_000000_clear_caches_dispatch_documents.php` for the second).

## The PDF question, already answered the way this lane would have answered it

`composer.json` requires exactly `php`, `laravel/framework`, `laravel/tinker`
(and `mockery`/`pest` in dev). **There is no PDF library and there cannot be
one** — `vendor/` is on the never-ship list, so a new package would exist in
`composer.json` and be absent from the server.

The existing implementation already took the only available route: print-ready
HTML plus a print stylesheet, turned into a PDF by the browser's own
Print → Save as PDF. `document.blade.php` sets `@page { size: A4; margin: 14mm
14mm 16mm; }`; `shipping-label.blade.php` overrides it with `@page { size:
105mm 148mm; }` for A6 label stock; `@media print` hides the toolbar and sets
`break-inside: avoid` on line rows and note blocks.

**This is a real constraint and the owner should know it.** There is no
server-side PDF byte stream. The operator clicks a button, a page opens in a
new tab, and they press Print. The output is a genuine PDF, but it is produced
on their machine, not the host's — which also means no emailed PDF attachment
and no PDF stored against the order. The emailed invoice is HTML
(`app/Mail/OrderInvoice.php`), not an attachment.

### Proof, not assertion

I rendered all five preview files through Chromium's own print pipeline
(`--print-to-pdf`) and read the page geometry back out of the resulting PDFs:

| Document | MediaBox (pt) | = | Intended |
|---|---|---|---|
| invoice | 594.96 × 841.92 | 209.9 × 297.2 mm | A4 ✓ |
| packing-slip | 594.96 × 841.92 | 209.9 × 297.2 mm | A4 ✓ |
| delivery-note | 594.96 × 841.92 | 209.9 × 297.2 mm | A4 ✓ |
| dispatch-label | 298.08 × 420 | 105.2 × 148.2 mm | A6 ✓ |
| dispatch-label-cod | 298.08 × 420 | 105.2 × 148.2 mm | A6 ✓ |

The `@page` rules genuinely drive the output; they are not decorative. PDFs and
PNGs are in `docs/fz-document-shots/`.

The invoice carries: business name, full address, phone, email, website, TRN,
a PAID badge, split BILL TO / DELIVER TO (the preview order deliberately ships
to someone other than the buyer), payment method, delivery service, currency,
per-line SKU and variant, subtotal, a named coupon discount, delivery, gift
wrapping, COD fee, total, order note, gift message and a footer. The COD
dispatch label prints **CASH ON DELIVERY — COLLECT AED 215.50** in a box, with
a Code128 barcode of the order number rendered as pure CSS bars.

## Data coverage — what the models do and do not carry

Asked to check VAT, TRN, the shop's own address, order lines, shipping address
and weights:

- **Shop identity and address** — present, and owner-editable. Settings →
  Business Details → Invoice tab (`app.blade.php:15799+`) exposes
  `invoice_business_name`, `invoice_address`, `invoice_phone`,
  `invoice_email`, `invoice_website`, `invoice_footer`. Blank fields print
  nothing rather than being guessed at.
- **TRN** — present, `invoice_trn`, ships blank.
- **VAT** — handled with real care. `InvoiceDocument` never asks the settings
  table for a tax rate; it reads what was recorded on the order
  (`App\Support\OrderTax`). An order that recorded no tax gets no tax figure —
  not a computed one, not a backfilled one.
- **The document's own name** is derived, not assumed: it reads **Tax Invoice**
  only when tax was really charged or is really contained **and** a TRN is
  entered; otherwise **Invoice**. `invoice_doctype` overrides verbatim. The
  code is explicit that whether a UAE shop may say "Tax Invoice" is the
  owner's accountant's question, not a developer's.
- **Order lines and shipping address** — present and printed.
- **Weights — genuinely absent.** There is no `weight` column on `products`,
  no dimensions, and nothing on `orders` or `order_items`. I checked the
  schema migration and every later `add_*` migration. **A courier label
  therefore cannot print a parcel weight, and nothing in the codebase could
  make it.** This is the one item from the brief's data list that is a real
  hole.

## Guards mutation-tested

I broke four things and watched the suite. Three went red:

| # | Mutation | Result |
|---|---|---|
| 1 | Drop the TRN half of the doctype guard (so a TRN-less shop prints "Tax Invoice") | **RED** — 2 failed |
| 2 | Let a `flat` ("printed only") tax basis count as charged tax | **RED** — 1 failed |
| 3 | Remove `auth:admin` from the mounted route stack | **RED** — 6 failed |
| 4 | Delete the early return in `InvoiceNumbers::allocate()` that reuses an existing number | **GREEN — survived** |

**Mutation 4 survived, and I believe it is an equivalent mutant rather than a
coverage hole.** `claim()` updates `whereNull('invoice_number')`, so on an
already-invoiced order the UPDATE affects zero rows, `claim()` returns false,
and the loop's own `existing()` recovery returns the number that is already
there. The early return is a fast path; the loop is what actually enforces
idempotency. `InvoiceNumberingTest:139` ("gives one order one number however
many times it is asked") passes either way, correctly.

I am reporting it rather than hiding it because I cannot rule out that a
future refactor of `claim()` would turn it into a live bug with no test
watching. It is a one-line note, not an action item.

All four mutations were reverted; the tracked tree is clean.

## Test results

- `vendor/bin/pest --filter='Invoice|Dispatch'` — **158 passed**
- `KBB_TEST_DB=kbb_fz vendor/bin/pest -c phpunit-mysql.xml --filter='Invoice|Dispatch'` — **158 passed**
- `find app database routes -name '*.php' | xargs -n1 php -l` — clean
- Full SQLite suite — **4321 passed, 21 skipped, 1 failed**

The one failure is **`Tests\Feature\ImportAtVolumeTest > it resumes onto…`**,
`SQLSTATE[HY000]: General error: 1 no such savepoint: trans3` in
`ImportRunner.php`. It is **pre-existing and unrelated to this lane** — I
reproduced it in the untouched main repo at the same HEAD. Nested-transaction
behaviour on SQLite; it belongs to whoever owns the importer.

The preview files in `docs/invoice-previews/` regenerate byte-identical
(`git status` clean after a full run), so they are deterministic.

## What I chose not to do, and why

**I did not build a second set of documents.** The brief told me to build
print-ready HTML with a print stylesheet if no PDF library was installed. That
is precisely what already exists. Building it again would have repeated the
exact mistake CLAUDE.md records at `2.60.59` — a lane duplicating an existing
backend, adding a redundant column, and having to revert every change. The
most useful thing this lane can return is the correction, not a rewrite.

**I did not add a weight column.** It touches `products`, the product editor,
the importer and the label. That is at least three other lanes' territory and
an owner decision about whether he wants to maintain per-product weights at
all.

**I did not add bulk printing.** It does not exist (no bulk route, no
multi-order document). It is a real feature request, not a defect.

## For the owner to settle

1. **Is browser-print acceptable as the permanent answer?** Server-side PDF
   bytes need a Composer package, which needs shell access on the host. If he
   wants PDFs attached to emails or stored against orders, that is a hosting
   decision before it is a code decision.
2. **Does he want per-product weights?** Without them a courier label can
   never carry a parcel weight. Some couriers require it on the label.
3. **What should the invoice call itself?** `invoice_trn` and
   `invoice_doctype` both ship blank, so today every invoice reads "Invoice".
   "Tax Invoice" / "Simplified Tax Invoice" are legally meaningful in the UAE.
   This is an accountant's question and the code correctly refuses to guess.
4. **Bulk printing** — worth it, or does he print one order at a time?

## The plan edit, for the integrator

CLAUDE.md forbids this lane from touching `KBB-Master-Plan.md`. The anchor
below occurs **exactly once** in the file (verified with `grep -c`).

Anchor:

```
- [ ] Real PDF generation for Invoice/Packing slip/Delivery note/Shipping Label/
  Dispatch Label — buttons are real, visible placeholders; wiring actual document
  generation is a separate, later piece of work
```

Replacement:

```
- [x] **Real documents for Invoice/Packing slip/Delivery note/Dispatch label** —
  shipped by Lane AE (invoice, packing slip) and Lane EK (delivery note,
  dispatch label); all four buttons on the order screen open real documents.
  Verified end to end by Lane FZ, which was opened to build this and found it
  already done: routes under the admin-api guard, both clear_caches_*
  migrations present, 158 tests green on SQLite and MySQL, three of four
  guard mutations red. There is no PDF *library* and cannot be one — vendor/
  never ships — so the documents are print-ready HTML with @page rules that
  the browser's Print → Save as PDF turns into a real PDF: confirmed by
  rendering, A4 at 594.96x841.92pt and the A6 label at 298.08x420pt. The
  consequence to know is that there is no server-side PDF byte stream, so no
  PDF email attachment and none stored against an order. See
  docs/FZ-ORDER-DOCUMENTS.md — *2.60.216*
- [ ] Parcel weight on the dispatch label — blocked for real: no `weight`
  column exists on `products` or anywhere else in the schema
- [ ] Bulk printing (pick many orders, print all their packing slips)
```
