<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The sample order — Safety → Demo Content → Sample order (Lane O)
|------------------------------------------------------------------------------
|
| MOUNTED, inside the EXISTING admin-api group in routes/web.php — the group
| that already carries `web`, `auth:admin` and NoStoreAdminApi — immediately
| after the invoices-admin.php require, which is the file whose four documents
| this one exists to make viewable. CLAUDE.md forbids a lane from editing
| routes/web.php, so this file shipped unmounted and the integrator added that
| line; RouteFileHeadersTest caught this paragraph still saying otherwise, which
| is the guard doing exactly its job.
|
| THAT GROUP, AND NOTHING ELSE. The POST below writes a row into `orders`, and
| the GET names an address and a phone number. /api/* in this application is
| unauthenticated by design, so mounting either there would be an anonymous
| write into the table this shop's money is counted from.
|
| A `clear_caches_*` migration ships with this package
| (2026_12_18_000000_clear_caches_sample_order.php). Without it the compiled
| route cache on the live host knows none of these paths and the failure is the
| quiet kind CLAUDE.md warns about: the card appears on Demo Content and every
| button on it 404s.
|
| Routes added:
|
|     GET    /admin-api/sample-order    is there one, and where are its documents
|     POST   /admin-api/sample-order    make one, in the language given
|     DELETE /admin-api/sample-order    remove it completely
|
| CAPABILITY. All three are mapped to `orders.sample` in
| App\Support\AdminCapabilities — owner only — by two rules, the exact path and
| its `/**` sibling, because neither matches the other and this screen may grow.
| EnforceAdminCapability refuses anything it cannot match, so a fourth endpoint
| added here without a rule fails closed rather than open.
|
| ONE PATH, THREE VERBS, and that is why there is no `/sample-order/create`.
| routes/orders-admin.php's header records the trap this shape avoids:
| `GET /orders/{id}` carries no constraint on {id}, so a sibling path can be
| swallowed by it depending on the order the requires happen to sit in. Nothing
| here nests under another route's wildcard at all.
|
*/

use App\Http\Controllers\Admin\SampleOrderController;
use Illuminate\Support\Facades\Route;

Route::get('/sample-order', [SampleOrderController::class, 'status']);
Route::post('/sample-order', [SampleOrderController::class, 'store']);
Route::delete('/sample-order', [SampleOrderController::class, 'destroy']);
