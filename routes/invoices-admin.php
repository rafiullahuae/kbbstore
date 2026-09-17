<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Order invoices, packing slips, delivery notes and dispatch labels
|   (Lane AE; the delivery note and the label added by Lane EK)
|------------------------------------------------------------------------------
|
| WIRED. This file is required from routes/web.php (or routes/api.php) and
| its routes serve live traffic. The wiring note below is kept as the
| record of where that require belongs.
|
| CLAUDE.md forbade this lane from editing routes/web.php, so the
| file shipped unmounted and the integrator added ONE line, inside the EXISTING
| admin-api group in routes/web.php — the group that already carries `web` and
| `auth:admin` (opened at line 181) and NoStoreAdminApi (opened at line 253) —
| beside the other requires:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/invoices-admin.php';
|     });
|
| THAT GROUP, AND NOTHING ELSE. Both documents below print a buyer's full name,
| their street address, their phone number, what they bought and — on the
| invoice — what they paid. /api/* in this app is unauthenticated by design, so
| mounting either there would publish the customer database one order at a time,
| formatted for printing. InvoiceDocumentTest asserts the refusal on both routes
| for an anonymous caller, for a signed-in storefront customer and for a
| `web`-guard user, mounted exactly as this header describes.
|
| A `clear_caches_*` migration ships with this package
| (2026_10_01_000000_clear_caches_order_invoices.php). Without it the compiled
| route cache on the live host knows neither path, and the failure is the quiet
| kind: the button appears on the order screen and opens a 404.
|
| Routes added:
|
|     GET /admin-api/orders/{id}/invoice         the printable tax invoice
|     GET /admin-api/orders/{id}/packing-slip    the picking sheet, no prices
|     GET /admin-api/orders/{id}/delivery-note   the handover sheet, no prices
|     GET /admin-api/orders/{id}/shipping-label  an A6 address label
|
| THE SECOND clear_caches_* MIGRATION IS NOT OPTIONAL AND IS NOT A DUPLICATE.
| The first one shipped with the invoice and the packing slip. The compiled
| route cache on the live host is a file of the routes that existed when it was
| written, so it knows nothing of the two paths added here until it is rebuilt;
| 2026_11_10_000000_clear_caches_dispatch_documents.php is what rebuilds it. The
| failure without it is the quiet kind CLAUDE.md warns about — the buttons appear
| on the order screen and open a 404.
|
| WHY THEY NEST UNDER /orders/{id} WHEN routes/orders-admin.php DELIBERATELY
| DOES NOT.
|
| That file's header explains the trap: routes/web.php registers
| `GET /orders/{id}` with no constraint on {id}, so a sibling named
| `/orders/list` is swallowed by it and which one answers depends on the order
| the require lines happen to sit in. All four paths here are THREE segments —
| /orders/{id}/invoice — and `/orders/{id}` matches two. They cannot be
| swallowed by it whatever order the requires end up in, exactly as
| /orders/{id}/detail, /orders/{id}/refund and /orders/{id}/restore already
| are not. Both carry whereNumber('id') as well, so a non-numeric segment never
| reaches a controller typed `int $id`.
|
| WHAT IS DELIBERATELY ABSENT. There is no public, tokenised or signed invoice
| link, and there is no per-order download endpoint outside this group. See the
| InvoiceController header: a URL that carries its own authority is a credential
| living in a browser history and a referrer header, and the admin session is
| already the one answer to who may read an order.
|
| ALL FOUR ARE GET AND ALL FOUR ONLY READ, with one exception.
|
| These are GET routes that RENDER, but the invoice one also ALLOCATES this
| order's invoice number on first view (InvoiceNumbers::allocate, idempotent and
| race-safe). That is a write on a GET, which is worth saying out loud: it is
| the only moment an invoice number can honestly be minted — at checkout an
| abandoned order would burn one out of a sequence an accountant has to
| reconcile — and it is safe to repeat, so a browser prefetch, a reload or two
| admins opening the same order at once still produce exactly one number.
|
| The other three allocate nothing and write nothing at all. Printing a picking
| sheet, a delivery note or an address label is not issuing a financial
| document, and an order that is picked and then cancelled must not leave a gap
| in the invoice sequence behind it.
|
*/

use App\Http\Controllers\Admin\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/orders/{id}/invoice', [InvoiceController::class, 'invoice'])
    ->whereNumber('id');

Route::get('/orders/{id}/packing-slip', [InvoiceController::class, 'packingSlip'])
    ->whereNumber('id');

Route::get('/orders/{id}/delivery-note', [InvoiceController::class, 'deliveryNote'])
    ->whereNumber('id');

Route::get('/orders/{id}/shipping-label', [InvoiceController::class, 'shippingLabel'])
    ->whereNumber('id');
