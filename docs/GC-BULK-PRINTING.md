# Lane GC — Bulk printing (many orders, one document, one Print)

**Outcome: built.** Bulk printing did not exist — no bulk route, no multi-order
document, no select-many affordance for documents — and Lane FZ's note that it
did not exist is the one thing in the plan about order documents that was
accurate. The Orders screen already has a tick-box selection with a bulk bar on
it; what it did not have is anything that prints.

Selecting orders on the Orders screen and choosing **Print → Packing slips**
now opens one page holding one sheet per order, with a real page break between
them. One Print → Save as PDF gives one file with one order per page. The same
for delivery notes, dispatch labels (A6) and invoices.

---

## What I checked before writing anything

The brief says the plan has been wrong about "open" and "blocked" five times
this month, so this is what I established by reading, not by trusting:

| Claim | Verdict |
|---|---|
| No bulk document route exists | **True.** `grep -rn bulk` over `app routes resources tests database` returns bulk *status*, *delete*, *restore* (orders), *bulk-delete* (customers) and *review-bulk* — and nothing that prints. |
| No multi-order document exists | **True.** `resources/views/invoices/` held four single documents and one shell. |
| No select-many affordance exists | **False, and this is the useful correction.** `app.blade.php:11590` `olSelectionBar()` already draws a bulk bar over `OL.sel`, with `olSelectedIds()`, a select-all, and handlers for status / trash / restore. Bulk printing did **not** need a selection UI built; it needed one control added to a bar that was already there. |
| There is no PDF library and there cannot be one | **True**, re-checked: `composer.json` requires only `php`, `laravel/framework`, `laravel/tinker` (+ `mockery`/`pest` in dev), and `vendor/` is in `BuildPackage::NEVER_SHIP` and `UpdateGuard::FORBIDDEN_PREFIXES`. I invented no dependency. |
| `InvoiceNumbers::allocate()` is idempotent per order | **True**, and Lane FZ's note on the surviving mutant is right: `claim()`'s `whereNull('invoice_number')` plus the loop's `existing()` recovery is what enforces it, and the early return is a fast path. This lane depends on the guarantee, not on which half provides it. |

---

## What was built

| Piece | Path |
|---|---|
| Controller | `app/Http/Controllers/Admin/BulkDocumentController.php` |
| Selection / cap / parsing | `app/Services/Invoices/BulkDocumentSelection.php` |
| Refusal | `app/Services/Invoices/BulkDocumentRefused.php` |
| The bulk document | `resources/views/invoices/bulk.blade.php` |
| The refusal page | `resources/views/invoices/bulk-refused.blade.php` |
| Route (**unmounted** — see below) | `routes/bulk-documents-admin.php` |
| Cache-clearing migration | `database/migrations/2026_11_22_000000_clear_caches_bulk_documents.php` |
| Capability rule | `app/Support/AdminCapabilities.php` (one line, `invoices.view`) |
| Strings | `app/Services/Translation/InterfaceStrings.php` (ten keys under `invoice.bulk.*`) |
| Tests | `tests/Feature/BulkDocumentsTest.php` (20), `tests/Feature/BulkDocumentPreviewsTest.php` (1), `tests/Support/BulkDocumentAdminRoutes.php` |
| Previews, PDFs, screenshots | `docs/gc-bulk-shots/` |

One route:

```
GET /admin-api/orders-bulk-documents?type=<invoice|packing-slip|delivery-note|dispatch-label>&ids=1,2,3
```

### A refactor came first, and it is the part worth reviewing

The four single documents each carried their sheet body inline inside
`@section('sheet')`. A bulk document that re-typed that markup would have been
two copies of every packing slip in the shop, drifting apart the first time
anybody touched one. So each body moved into a partial that the single document
and the bulk document both include:

```
resources/views/invoices/partials/sheet-invoice.blade.php
                                  sheet-packing-slip.blade.php
                                  sheet-delivery-note.blade.php
                                  sheet-dispatch-label.blade.php
                                  page-dispatch-label.blade.php    (the A6 @page rule)
                                  style-dispatch-label.blade.php   (the label's CSS)
```

**The five tracked previews under `docs/invoice-previews/` regenerate byte for
byte across this move** — that is the check that the single documents are
unchanged, and it took two measured facts to achieve:

- PHP eats one newline after a `?>`, so the newline that used to follow
  `@section('sheet')` was never in the output. Writing the directive and the
  include on separate lines *adds* a blank line to every preview.
- `Illuminate\View\Engines\PhpEngine::evaluatePath` returns
  `ltrim(ob_get_clean())`, so leading whitespace inside an included file is
  stripped before it is echoed. The partial cannot carry its own indent.

Hence the shape `@section('sheet')    @include('…')@endsection`, on one line,
with the four spaces on the parent side. Both facts are recorded beside the code
because the next person to tidy that line will otherwise rewrite five reviewed
files without meaning to.

---

## The page break — measured, not asserted

This is the whole feature and it is the one defect invisible in HTML: two orders
that share a sheet look perfect in a browser window and come out of the printer
as a packing slip with somebody else's address halfway down it.

`docs/gc-bulk-shots/` holds a four-order batch of each document, printed through
Chromium's own print pipeline (`--print-to-pdf`, the same route the owner's
Print dialog takes) with the geometry read back out of the PDF:

| Document | Pages | MediaBox (pt) | = | Intended |
|---|---|---|---|---|
| bulk-invoices | 4 | 594.96 × 841.92 | 209.9 × 297.2 mm | A4 ✓ |
| bulk-packing-slips | 4 | 594.96 × 841.92 | 209.9 × 297.2 mm | A4 ✓ |
| bulk-delivery-notes | 4 | 594.96 × 841.92 | 209.9 × 297.2 mm | A4 ✓ |
| bulk-dispatch-labels | 4 | 298.08 × 420 | 105.2 × 148.2 mm | A6 ✓ |

Four orders, four pages, no trailing blank. Exactly the numbers Lane FZ measured
for the single documents, so the bulk page box is genuinely the same one.

And `pdftotext` page by page, on all four files:

```
page 1: KBB-20501   page 2: KBB-20502   page 3: KBB-20503   page 4: KBB-20504
```

One order per page, in the order they were selected. Nothing straddles.

### The negative control: the rule is load-bearing

Deleting `.bulkdoc > .sheet { break-after: page; … }` from the generated
packing-slip page and re-printing it:

```
page 1: KBB-20501 KBB-20502     <- two orders on one sheet of paper
page 2: KBB-20503
page 3: KBB-20504
page 4: (the tail of KBB-20504, with no order number on it)
```

That is precisely the defect, reproduced. The rule is doing the work.

---

## Guards mutation-tested

Twenty mutations. **Eighteen went red. Two survived, and both are reported
rather than hidden.**

| # | Mutation | Result |
|---|---|---|
| 1 | Cap comparison `>` → `>=` | **RED** (1 failed) |
| 2 | Cap check removed entirely | **RED** (3 failed) |
| 3 | De-duplication of ids removed | **RED** (2 failed) |
| 4 | Allocate invoice numbers *before* the parse/cap checks | **RED** (1 failed) |
| 5 | Every document type allocates invoice numbers | **RED** (4 failed) |
| 6 | Page-break rule removed | **RED** (1 failed) |
| 7 | Last-sheet break exemption removed | **RED** (1 failed, but see below) |
| 8 | `auth:admin` dropped from the mounted stack | **RED** (2 failed) |
| 9 | Capability rule removed from `AdminCapabilities::RULES` | **RED** (1 failed) |
| 10 | Invoice sheets rendered outside `OrderLocale::render()` | **RED** (1 failed) |
| 11 | Ids sorted instead of kept in the asked-for order | **RED** (2 failed) |
| 12 | Missing ids silently dropped, nothing reported | **RED** (1 failed) |
| 13 | A6 page box lost on a label run | **RED** (1 failed) |
| 14 | `lbl` class lost on the inner sheets | **RED** (1 failed) |
| 15 | "None of those orders exist" refusal removed | **RED** (1 failed) |
| 16 | Trashed orders excluded from a batch | **RED** (1 failed) |
| 17 | Shared partial: SKU column heading changed | **GREEN — survived** |
| 18 | Refusal returns 200 instead of 400 | **RED** (3 failed) |
| 19 | Shared partial leaks a price onto the packing slip | **RED** (16 failed) |
| 20 | Shared dispatch-label partial names the contents | **RED** (2 failed) |
| B1 | *(browser)* Last-sheet break exemption removed, re-printed in Chromium | **GREEN — survived** |

All twenty were reverted; the tracked tree is clean and the previews regenerate
byte-identical.

### Mutation 17 survived: a label in the shared partial is pinned by nothing

Changing `{{ __('invoice.packing.col_sku') }}` to a literal broke **no test** —
not this lane's, and not Lane AE's or EK's either. It is a pre-existing coverage
gap that the extraction has now made visible: the single-document tests assert
what a sheet must *not* say (no money, no product names on a label) and never
assert a column heading.

It is not invisible, though: **the tracked previews catch it.** I verified this
rather than assuming it — with the mutation in place, `vendor/bin/pest
--filter=InvoicePreviews` still passes and leaves
`docs/invoice-previews/packing-slip.html` modified in `git status`. The repo's
own convention is that a dirty preview after a green run is the signal, so the
mutation is caught by review, not by an assertion.

My "the bulk sheet *is* the single sheet" test cannot catch it by construction:
it asserts the two are equal, and mutation 17 changes both. That equality is the
property worth pinning and I would not trade it for heading assertions.

### Browser mutation B1 survived: the last-sheet exemption is unverified

`.bulkdoc > .sheet:last-child { break-after: auto; page-break-after: auto; }`
exists to stop a batch ending with a blank page. Removing it and re-printing in
Chromium produced **four pages, not five** — Chromium already suppresses a
forced break at the end of the fragmentation flow, so on this engine the rule
is a no-op.

The test that pins it (mutation 7) therefore pins the *text of the rule*, not a
behaviour, and I have said so rather than letting the green tick imply more than
it proves. I kept the rule: WebKit and Gecko have historically emitted that
blank page, the shop prints from a Mac, and one CSS line is cheap insurance. But
nobody should believe it has been demonstrated to do anything here. Verifying it
needs a second engine, which this environment does not have.

---

## The invoice sequence, which was the sharp edge

Two properties, and neither is automatic:

**It must not mint numbers it throws away.** Every refusal — unknown type,
nothing selected, over the cap, ids that are not orders — is decided before a
single `Order` is loaded. `BulkDocumentSelection::from()` never touches the
database; `allocate()` runs only after the rows are in hand *and* after the
"none of these exist" refusal, so every number minted belongs to an order that
is certainly going onto the page. Mutation 4 (allocate first) and mutation 2
(no cap) both go red, and the cap test deliberately puts **real** order ids at
the front of an over-cap selection, so a cap check that ran after the load would
have had them in hand and could have allocated.

**It must not mint the same number twice.** Ids are de-duplicated before
anything is loaded (mutation 3 red), `allocate()` is idempotent per order, and
the UNIQUE index arbitrates between processes. Printing a batch twice leaves the
numbers unchanged — asserted.

**It is still a write on a GET**, exactly as the single invoice route is, and
for the reason that route's header gives. It is safe to repeat.

**What I deliberately did not do:** refuse to invoice a cancelled, failed or
unpaid order. The single-order route does not, and a bulk action that quietly
applies a different policy from the button beside it puts two answers to "when
is an order invoiced" into one codebase. Instead the console asks for
confirmation before an invoice run and says the allocation cannot be undone.
**Whether an uninvoiceable status should be skipped is the owner's call** — see
the questions at the end.

---

## The cap: 100, and it is not a memory limit

I measured it rather than guessing. A hundred orders of ten lines each, rendered
as packing slips in one warm request:

| Orders | Time | Peak PHP memory above baseline | HTML |
|---|---|---|---|
| 1 | 3 ms | ~0 MB | 0.03 MB |
| 10 | 14 ms | ~0 MB | 0.16 MB |
| 25 | 31 ms | 2 MB | 0.40 MB |
| 50 | 60 ms | 2 MB | 0.80 MB |
| 100 | 127 ms | 8 MB | 1.59 MB |

The server would carry two hundred without noticing, and the query string at a
hundred ids is about 700 characters — nowhere near any limit.

**So the cap is the blast radius, not the cost.** On an invoice run one click
mints up to that many numbers out of a sequence an accountant reconciles, and
nothing in this admin can take one back. A hundred is roughly twice the biggest
batch this shop packs in a day: the real work is unaffected and a mis-click
costs half as much. It is deliberately **half** `OrdersApiController::BULK_MAX`
(200) — that one caps a single UPDATE over a list of ids, this one caps an
irreversible allocation and a hundred sheets of paper, and they should not be
kept equal for tidiness.

Raising it is one constant and a re-run of the table above.

## What happens to an order with no such document

There is no order that cannot produce all four documents — every one of them
renders from `orders`, `order_items` and `settings`, and an order with no
shipping address falls back to billing exactly as the single documents already
decided. So the real cases are:

- **An id that is not an order** (deleted for good since the list was loaded, or
  typed into the URL). It is skipped, the rest still print, and the page names
  it in a `.no-print` panel at the top: *"1 of the orders you picked could not
  be found … Order ids: 770001."* Nothing is minted for it.
- **Every id is a ghost.** A 400 page: *"None of those orders exist."*
- **A trashed order.** It prints. A soft-deleted order is still an order that
  was placed and may have been paid for, and its paperwork is a record — the
  same decision `InvoiceController::find()` already made. Asserted.
- **The same order ticked twice.** One sheet, not two. On an invoice run two
  sheets would carry one invoice number, which is the one thing an invoice may
  never be.

The skipped-orders panel is `.no-print` on purpose: **the printed sheets are
byte for byte what the single-order document prints**, so an operator who
reprints one order from the order screen gets the same paper. That equality is
asserted for all four types rather than described.

## Language

The invoice, and only the invoice, prints in the language the **order** was
placed in — because the customer already has that document in their inbox and
two sheets bearing one invoice number must not read differently. A batch can
hold Arabic and English orders at once, so each sheet is rendered inside its own
`OrderLocale::render()` and carries its own `lang`/`dir`, while the page around
them stays in the operator's language. `docs/gc-bulk-shots/bulk-invoices.html`
contains one Arabic sheet and three English ones; the matching
`bulk-packing-slips.html` contains none, because a picking list is read inside
the building.

Note that an Arabic sheet today is `lang="ar" dir="ltr"`. That is correct:
`Locale::direction()` answers from the shop's *second* switch, and the mirrored
layout is still being built. The test says so rather than pinning `rtl`, so it
starts reading `rtl` by itself the day another lane turns mirroring on.

---

## Changes the integrator must make — anchors verified by count

### 1. `routes/web.php` — mount the route file *(insert into the middle)*

One line inside the **existing** `admin-api` group, the one that already carries
`web` + `auth:admin` (opened at line 181) and `NoStoreAdminApi` (opened at line
253), beside the other `require`s and next to `invoices-admin.php`:

```php
require __DIR__.'/bulk-documents-admin.php';
```

That group and nothing else. This endpoint prints up to a hundred buyers' names,
street addresses, phone numbers and order values on one page; `/api/*` in this
app is unauthenticated by design.

**Then strike the "NOT WIRED YET" paragraph at the top of
`routes/bulk-documents-admin.php`**, or `RouteFileHeadersTest` fails by name.

### 2. `resources/views/admin/app.blade.php` — three blocks

CLAUDE.md forbids this lane from editing that file. Each anchor below was
verified with an exact-string count of **1** against the file at this commit,
and all three blocks were applied to a scratch copy, node-syntax-checked, and
run through the admin test suite (`--filter='Admin|Console|Screen|Orders'`,
**1227 passed, 9 skipped**) before being reverted. The JavaScript was also run
in Node against stubs: it produced
`/kbb-upgrade/admin-api/orders-bulk-documents?type=packing-slip&ids=3,7` for two
ticked orders, opened no tab for an invoice run until the dialog was confirmed,
and opened no tab at all over the cap.

---

#### Block A — the Print menu in the selection bar *(INSERT INTO THE MIDDLE of the anchor)*

The anchor is three consecutive lines of `olSelectionBar()` (around line 11593).
The replacement **keeps all three and appends after them**, before the
`trashView` ternary that follows.

Anchor (occurs exactly once):

```js
      '<b style="font-size:12.5px">' + selected.length + ' selected</b>' +
      '<button class="btn ghost sm" id="olClearSel">Clear</button>' +
      '<div style="flex:1"></div>' +
```

Replacement:

```js
      '<b style="font-size:12.5px">' + selected.length + ' selected</b>' +
      '<button class="btn ghost sm" id="olClearSel">Clear</button>' +
      '<div style="flex:1"></div>' +
      /* BULK DOCUMENTS (Lane GC). Before the trashView ternary on purpose, so
         it is offered in the trash view too: a soft-deleted order is still an
         order that was placed and may have been paid for, and the server prints
         one withTrashed() exactly as the single documents do. */
      '<select class="inp" id="olBulkPrint" style="width:auto;min-width:150px">' +
        '<option value="">Print&hellip;</option>' +
        OL_DOCS.map(function(d){ return '<option value="' + d[0] + '">' + d[1] + '</option>'; }).join('') +
      '</select>' +
```

#### Block B — the handler in `olBind()` *(APPEND to the anchor)*

Anchor (occurs exactly once, around line 11827):

```js
    var bulkDelete = byId('olBulkDelete');
    if(bulkDelete) bulkDelete.onclick = function(){ olConfirmDelete(olSelectedIds()); };
```

Replacement — the anchor unchanged, then a blank line and:

```js
    var bulkPrint = byId('olBulkPrint');
    if(bulkPrint) bulkPrint.onchange = function(e){
      var type = e.target.value;
      /* Reset first: the select is a menu, not a setting, and leaving it on
         "Invoices" would make the next Print look like it had already been
         chosen. Also lets the same document be picked twice in a row. */
      e.target.value = '';
      if(type) olConfirmDocs(olSelectedIds(), type);
    };
```

#### Block C — the functions *(APPEND to the anchor)*

Anchor (occurs exactly once, around line 11731):

```js
  function olSelectedIds(){
    return Object.keys(OL.sel).filter(function(k){ return OL.sel[k]; }).map(Number);
  }
```

Replacement — the anchor unchanged, then:

```js

  /* -------- bulk documents: many orders, one printable page (Lane GC) -------- */

  /* The four documents, in the order a packing bench wants them: the two that
     go on and in the parcel first, the invoice last because it is the one that
     issues a number. */
  var OL_DOCS = [
    ['packing-slip', 'Packing slips'],
    ['dispatch-label', 'Dispatch labels'],
    ['delivery-note', 'Delivery notes'],
    ['invoice', 'Invoices']
  ];

  /* Services\Invoices\BulkDocumentSelection::MAX. Checked here as well as on
     the server so the operator is told in a dialog rather than in a new tab
     that turns out to hold a refusal. The server is still the one that decides:
     it refuses over the cap before it loads an order or issues a number. */
  var OL_DOC_MAX = 100;

  /* A NEW TAB, NOT A FETCH. The page is a document for the browser to print,
     the browser carries the same admin session cookie, and leaving the Orders
     screen behind means the operator's ticks are still there when they come
     back for the next document. Same reasoning as the Export button below.

     window.open MUST HAPPEN IN THE CLICK. A browser blocks a popup opened from
     an async continuation, so this is called straight from the change handler,
     or straight from the confirm button's own click - never after an await. */
  function olPrintDocs(ids, type){
    if(!ids.length) return;

    window.open(
      fixAdminApiUrl('/admin-api/orders-bulk-documents') +
        '?type=' + encodeURIComponent(type) + '&ids=' + ids.join(','),
      '_blank',
      'noopener'
    );
  }

  function olDocLabel(type){
    return (OL_DOCS.filter(function(d){ return d[0] === type; })[0] || [type, type])[1];
  }

  /**
   * Three of the four just print. The invoice asks first.
   *
   * Not because printing is dangerous, but because an invoice number is: the
   * server issues one to every selected order that has none, out of a sequence
   * an accountant reconciles, and nothing in this admin can take one back. The
   * other three documents allocate nothing at all and open straight away -
   * asking about a picking list would only train the operator to click through
   * the dialog that matters.
   */
  function olConfirmDocs(ids, type){
    if(!ids.length) return;

    if(ids.length > OL_DOC_MAX){
      openModal('<div class="modal-h"><b>Too many at once</b><button class="x" onclick="closeModal()">&#10005;</button></div>' +
        '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">You picked <b>' + ids.length +
        '</b> orders, and one document holds at most <b>' + OL_DOC_MAX + '</b>.</p>' +
        '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">Nothing has been printed and no invoice numbers have been issued. ' +
        'Print them in batches of ' + OL_DOC_MAX + ' or fewer - your ticks are still here.</p>' +
        '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
        '<button class="btn" onclick="closeModal()">Close</button></div></div>');
      return;
    }

    if(type !== 'invoice'){ olPrintDocs(ids, type); return; }

    openModal('<div class="modal-h"><b>Print invoices</b><button class="x" onclick="closeModal()">&#10005;</button></div>' +
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">Print invoices for <b>' + ids.length +
      '</b> order' + (ids.length === 1 ? '' : 's') + '?</p>' +
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">Any of them that has never been invoiced is given its invoice number now, ' +
      'exactly as opening one invoice from the order screen does. That cannot be undone from here, so pick the orders you are really invoicing. ' +
      'Orders that already have a number keep it.</p>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
      '<button class="btn ghost" onclick="closeModal()">Cancel</button>' +
      '<button class="btn" id="olDocsYes">Print invoices</button></div></div>');

    var yes = document.getElementById('olDocsYes');
    if(yes) yes.onclick = function(){ closeModal(); olPrintDocs(ids, 'invoice'); };
  }
```

`olDocLabel()` is defined and not yet called. It is the one piece of this block
that exists for the next change rather than for this one — the dialog headings
are literal today, and a fifth document would want them built from the map. If
the integrator prefers no unused function, deleting it is safe.

### 3. `KBB-Master-Plan.md` *(replace)*

CLAUDE.md forbids this lane from editing the plan. Lane FZ's document proposed
adding this line; if the integrator has already added it, the anchor is:

```
- [ ] Bulk printing (pick many orders, print all their packing slips)
```

Replacement:

```
- [x] **Bulk printing — many orders, one document, one Print** — Orders screen,
  tick the orders, Print → Packing slips / Dispatch labels / Delivery notes /
  Invoices. One HTML page holding one sheet per order with a real page break
  between them, so one Print → Save as PDF gives one file with one order per
  page; confirmed by printing a four-order batch of each through Chromium and
  reading the geometry back (4 pages each, A4 594.96x841.92pt, the label run A6
  298.08x420pt, one order per page). Capped at 100 per document — the blast
  radius of an irreversible invoice allocation, not a memory limit; 100 orders
  cost 127 ms and 8 MB. The four sheet bodies moved into partials the single
  documents and the bulk one both include, and the five tracked previews
  regenerate byte for byte across that move. See docs/GC-BULK-PRINTING.md —
  *2.60.217*
```

If that line is not in the plan yet, the whole block above is an addition rather
than a replacement.

---

## Test results

- `vendor/bin/pest` — **4385 passed, 21 skipped, 0 failed**
- `KBB_TEST_DB=kbb_gc vendor/bin/pest -c phpunit-mysql.xml` — **4391 passed, 15 skipped, 0 failed**
- `find app database routes -name '*.php' -print0 | xargs -0 -n1 php -l` — clean
- `docs/invoice-previews/` regenerates byte-identical; `git status` clean after a full run

Lane FZ reported one pre-existing failure (`ImportAtVolumeTest`, `no such
savepoint: trans3`). **It did not occur in either suite here.** That is
consistent with the brief's warning: it is a disk-pressure artefact, and `df -h
/` reported 19 G free before, during and after this lane's work. The worktree's
`vendor/` is hard-linked, so it cost nothing.

---

## What I chose not to do, and why

**No ZIP, and no server-side PDF.** "Download" on this host means Save as PDF in
the browser's own print dialog — there is no PDF library and there cannot be
one. A ZIP would need somewhere to build it and would hand the operator twenty
files to open one at a time, which is the problem rather than the fix.

**No per-order page in an `<iframe>`.** A browser prints the frame it can see;
iframes do not paginate into the parent's page flow. The only shape where one
Print produces one correct file is one document with real page breaks.

**No "Print all matching this filter".** The console sends ids, and an operator
who ticks them can see what they ticked. "Everything matching the current
filter" on an *invoice* run is a click that could issue several hundred numbers
against a set the operator never looked at. If the owner wants it, it should be
a separate control with its own dialog, not a checkbox.

**No printed sheet counter.** The "Sheet 3 of 12" caption is on screen only, so
the printed sheets stay byte for byte identical to the single-order document. A
counter on the paper would make a bulk packing slip differ from a reprinted one.
The print dialog's own page count already tells the operator whether twelve
sheets came out. Say so if he wants it on the paper.

**No invoice-status policy.** See above: bulk invoices exactly what the single
button invoices.

**No change to the orders list payload.** The invoice confirmation dialog would
be better if it could say "9 of these 12 already have an invoice number, 3 will
be issued one" — but `invoice_number` is not in `OrdersApiController`'s list
response and that file belongs to another lane. It is a small addition there and
a two-line change here.

---

## For the owner to settle

1. **Is 100 per document right?** It is the blast radius of an irreversible
   invoice allocation, not a technical limit — the server would carry 200
   comfortably. If he routinely packs more than a hundred parcels in a run, say
   so and it is one constant.
2. **Should a bulk invoice run skip orders that are cancelled, failed or
   refunded?** Today it invoices exactly what the single button invoices, and
   deliberately so. Skipping them is a real accounting preference and it is his,
   not a developer's.
3. **Does he want the sheet counter printed on the paper?** Today "Sheet 3 of
   12" is on screen only, so a bulk sheet and a reprinted single sheet are
   identical. Printing it would break that equality and would help a packer
   check nothing was lost.
4. **Does he want the invoice dialog to count what it will issue?** That needs
   `invoice_number` added to the orders-list payload (another lane's file).
5. Lane FZ's four questions are all still open and unaffected by this work:
   browser-print as the permanent answer, per-product weights, what the invoice
   calls itself, and — now answered — whether bulk printing was worth it.
