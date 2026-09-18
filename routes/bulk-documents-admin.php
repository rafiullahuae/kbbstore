<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Bulk order documents — many orders, one printable page (Lane GC)
|------------------------------------------------------------------------------
|
| LIVE. routes/web.php requires this file inside the EXISTING admin-api group —
| the one already carrying `web` + `auth:admin` and NoStoreAdminApi — directly
| beneath invoices-admin.php, which serves the single-order half of the same four
| documents. Verified off the registered route rather than assumed: all four
| resolve carrying Authenticate:admin and NoStoreAdminApi.
|
| Resulting route:
|
|     GET /admin-api/orders-bulk-documents?type=<one of four>&ids=1,2,3
|
| THAT GROUP AND NOTHING ELSE, for the reason routes/invoices-admin.php gives at
| length and which is worse here by a factor of a hundred: this endpoint prints
| up to a hundred buyers' full names, street addresses, phone numbers and — on
| an invoice run — what each of them paid, on ONE page. /api/* in this app is
| unauthenticated by design, so mounting it there would publish the customer
| database a hundred rows at a time, formatted for printing. BulkDocumentsTest
| asserts the refusal for an anonymous caller, for a signed-in storefront
| customer and for a `web`-guard user, and reads the middleware back off the
| registered route rather than trusting the harness that mounted it.
|
| THE HEADER ABOVE WAS STRUCK WHEN IT STOPPED BEING TRUE, which is what
| tests/Feature/RouteFileHeadersTest.php is for: it fails by name if web.php
| requires this file while it still calls itself unmounted. Fourteen files were
| once announcing themselves as unmounted while serving live traffic, and this
| one was caught by that guard within a minute of being wired.
|
| A clear_caches_* migration ships with this package
| (2026_11_22_000000_clear_caches_bulk_documents.php). Without it the compiled
| route cache on the live host does not know this path, and the failure is the
| quiet kind CLAUDE.md warns about: the Print button appears on the Orders
| screen and opens a 404.
|
| Route added:
|
|     GET /admin-api/orders-bulk-documents?type=<one of four>&ids=1,2,3
|
| A SIBLING OF /orders, NOT A CHILD OF IT, and this is the trap
| routes/orders-admin.php documents. routes/web.php registers
| `GET /orders/{id}` with NO constraint on {id}, so a two-segment sibling such
| as /orders/bulk-documents is swallowed by it and which of the two answers
| depends on the order the require lines happen to sit in. The four single
| document routes escape that by being three segments deep. This one cannot be
| — there is no single order for it to hang under — so it takes the shape the
| existing bulk endpoints already use: /admin-api/orders-bulk-delete,
| /admin-api/orders-bulk-restore, /admin-api/orders-bulk-status, and now
| /admin-api/orders-bulk-documents. One segment, no collision, whatever order
| the requires end up in.
|
| GET, AND IT WRITES ON AN INVOICE RUN. Deliberate, and the same deliberate
| choice the single invoice route already made: first render is the only honest
| moment to mint an invoice number, because at checkout an abandoned order would
| burn one out of a sequence an accountant has to reconcile. It is safe to
| repeat — InvoiceNumbers::allocate() is idempotent per order and the UNIQUE
| index arbitrates between processes — so a reload, a prefetch or two admins
| printing the same batch still produce exactly one number per order. The other
| three types allocate nothing at all.
|
| NO whereNumber() HERE, and its absence is not an oversight. The single routes
| constrain {id} because their controllers are typed `int $id`; this one takes
| no route parameter. The ids arrive in the query string and are parsed by
| Services\Invoices\BulkDocumentSelection, which drops anything that is not a
| plain positive integer and refuses — before loading a row or allocating a
| number — an empty selection or one over the cap.
|
| THE CAPABILITY. AdminCapabilities::RULES maps this path to `invoices.view`,
| the same capability the four single documents carry, because it shows the same
| information. An admin route with no rule is owner-only and
| AdminCapabilityMapTest fails by name for it.
|
*/

use App\Http\Controllers\Admin\BulkDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/orders-bulk-documents', [BulkDocumentController::class, 'show']);
