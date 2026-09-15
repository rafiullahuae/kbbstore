<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Payments admin API — Phase 11 (Lane D)
|------------------------------------------------------------------------------
|
| WIRED. The integrator mounted this from routes/web.php, inside the EXISTING
| admin-api group — the one that already carries `auth:admin` and
| NoStoreAdminApi, beside the pay-ship-rules pair:
|
|     require __DIR__.'/payments-admin.php';
|
| It must stay inside that group, and nothing may be added to this file that
| would be mounted anywhere else. These routes read and write live payment
| credentials, so they need the admin session guard; mounted anywhere else they
| would be a public endpoint for configuring the store's Stripe keys.
|
| They are deliberately NOT in routes/api.php: everything there is
| unauthenticated by design.
|
| Resulting paths, matching the pay-ship-rules convention:
|
|     GET  /admin-api/payments
|     POST /admin-api/payments
|
| GET never returns a secret value — secret fields come back empty with a
| `has_value` flag. POST treats a blank secret as "unchanged", so saving the
| screen without retyping the keys does not wipe them.
|
*/

use App\Http\Controllers\Admin\PaymentsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/payments', [PaymentsApiController::class, 'show']);
Route::post('/payments', [PaymentsApiController::class, 'save']);
