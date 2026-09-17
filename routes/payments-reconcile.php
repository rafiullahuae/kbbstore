<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Payment reconciliation — the provider's books against ours (Lane EJ)
|------------------------------------------------------------------------------
|
| MOUNTED. routes/web.php requires this file inside the `admin-api` group, the
| one carrying `auth:admin` and NoStoreAdminApi, beside the other payments
| requires. The paragraph below is why it belongs in that group and nowhere
| else — it is the reasoning behind the mount, not a request for one.
|
| It belongs immediately after
|
|     require __DIR__.'/payments-preflight.php';
|
| so the four payments files read in the order the screen uses them:
| credentials, settlement, preflight, reconcile.
|
| IT MUST GO INSIDE THAT GROUP AND NO FURTHER OUT. Nothing here moves a fil —
| every endpoint is a read except `acknowledge`, which writes one timestamp to
| `reconciliation_findings` — but what it RETURNS is a list of this shop's
| payments, order numbers, provider references and amounts, together with a
| precise description of where its money handling is currently weak. That is a
| worse thing to publish than the preflight next door, and routes/api.php is
| unauthenticated by design.
|
| Resulting paths:
|
|     GET  /admin-api/payments/reconcile                  what can be asked, and recent runs
|     POST /admin-api/payments/reconcile/start            open or resume a run
|     POST /admin-api/payments/reconcile/step             one bounded slice of work
|     GET  /admin-api/payments/reconcile/cod              the cash-on-delivery position
|     GET  /admin-api/payments/reconcile/{run}            progress on one run
|     GET  /admin-api/payments/reconcile/{run}/findings   the report
|     POST /admin-api/payments/reconcile/{run}/findings/{finding}/ack
|
| ORDER MATTERS HERE, and unusually it really does. Laravel matches in
| registration order, and `{run}` is constrained to digits — but `start`,
| `step` and `cod` are registered ABOVE it anyway rather than relying on that
| constraint alone. A `where()` that is later loosened by somebody adding a
| slug-shaped run key would silently turn POST /reconcile/start into
| "show me run 'start'", and the failure would be a 404 on a button that
| renders perfectly.
|
| THE CAPABILITY. These paths are not in App\Support\AdminCapabilities::RULES
| yet, and until they are they fall through to the no-match default, which is
| OWNER-ONLY. That is the correct resting place for them, so nothing is broken
| in the meantime — but the three explicit rules named in this lane's report
| should be added so the intent is written down rather than inherited from a
| fallback.
|
| A `clear_caches_*` migration ships with this package
| (2026_09_17_040000_clear_caches_payment_reconcile.php). Without it the
| compiled route cache on the live host knows none of these paths and every
| button on the screen 404s while rendering perfectly — which is precisely how
| two earlier packages on this project shipped inert.
|
|------------------------------------------------------------------------------
| THE ADMIN SCREEN THIS NEEDS
|------------------------------------------------------------------------------
|
| resources/views/admin/app.blade.php is owned by the integrator, so this lane
| has not touched it. The exact anchors and the exact replacement text are in
| this lane's report, and the shape is:
|
|   A panel at the BOTTOM of Store → Ecommerce → Payments, below the gateway
|   tab panes and OUTSIDE them, added in paintPayments(). Outside the panes
|   because reconciliation is about every gateway at once; inside one, it would
|   vanish whenever the operator was looking at a different tab.
|
|   The panel holds two date boxes (defaulting to the last fourteen days), a
|   "Check the books" button, a "Start over" button, and two output areas.
|
|   The button does NOT make one request. It POSTs /reconcile/start, then POSTs
|   /reconcile/step in a loop until the response says `done`, showing
|   `phases_done / phases_total` as it goes. That is not a style choice: this
|   host kills a long request and has no queue worker, so a run has to be many
|   short ones, exactly as Store → Import / Export already works. Each step
|   commits its own checkpoint, so closing the tab halfway loses nothing and
|   pressing the button again continues from where it stopped.
|
|   Then it GETs /reconcile/{run}/findings and renders each row as: the summary
|   sentence, the order number (clickable — renderOrderDetail(f.order_id) is
|   already a global on that page), the provider's reference, both amounts, and
|   an "I have dealt with this" button that POSTs the .../ack path.
|
|   THE WORDING ON THE BUTTON MATTERS. It says "Check the books", and the help
|   text says the run only looks. An owner who believes a button might mark
|   orders paid will not press it on a live shop, and this is a report whose
|   entire value is that it gets pressed.
|
|   Finally it GETs /reconcile/cod and renders that under its own heading, with
|   the `note` string printed verbatim. It must NOT be merged into the findings
|   list: cash on delivery has no second set of books, so its figures are one
|   sided, and the payload says so in words the screen is meant to show rather
|   than summarise.
|
*/

use App\Http\Controllers\Admin\PaymentReconciliationController;
use Illuminate\Support\Facades\Route;

Route::get('/payments/reconcile', [PaymentReconciliationController::class, 'index']);

Route::post('/payments/reconcile/start', [PaymentReconciliationController::class, 'start']);

Route::post('/payments/reconcile/step', [PaymentReconciliationController::class, 'step']);

Route::get('/payments/reconcile/cod', [PaymentReconciliationController::class, 'cashOnDelivery']);

Route::get('/payments/reconcile/{run}', [PaymentReconciliationController::class, 'status'])
    ->where('run', '[0-9]+');

Route::get('/payments/reconcile/{run}/findings', [PaymentReconciliationController::class, 'findings'])
    ->where('run', '[0-9]+');

Route::post('/payments/reconcile/{run}/findings/{finding}/ack', [PaymentReconciliationController::class, 'acknowledge'])
    ->where('run', '[0-9]+')
    ->where('finding', '[0-9]+');
