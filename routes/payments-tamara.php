<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Tamara gateway admin API — webhook registration, basket limits, sweep (PG1)
|------------------------------------------------------------------------------
|
| MOUNTED. routes/web.php requires this file from inside the EXISTING `admin-api`
| group — the one that carries `auth:admin` and NoStoreAdminApi — beside the other
| payment requires, and before web.php's GET-only Route::fallback.
|
| THE RELEASE ROUTE THIS LANE WROTE IS NOT HERE ANY MORE. It moved to
| routes/payments-void.php, because the Tabby lane wrote the same endpoint in the
| same round and both files mounted would have registered
| POST /admin-api/orders/{id}/void twice — Laravel takes the last one, so the
| require order would have silently decided which controller method served every
| release in the shop. That file's header carries the reasoning; nothing about the
| endpoint was ever Tamara-specific, which is the other half of the argument.
|
| IT MUST STAY INSIDE THAT GROUP, and this is not boilerplate. Mounted without
| `auth:admin`:
|
|   - DELETE /admin-api/payments/tamara/webhook silently stops Tamara telling
|     this shop about declines, which is invisible until somebody audits
|     `pending` orders;
|   - POST /admin-api/payments/tamara/limits rewrites which baskets are offered
|     BNPL at all;
|   - POST /admin-api/payments/tamara/sweep asks Tamara about every pending
|     order in the window and marks the approved ones PAID. It names no order
|     and accepts no amount, so it cannot be pointed at one — but unguarded it
|     is still a way for a stranger to spend this shop's Tamara rate limit, and
|     it writes to orders.
|
| Mounted in routes/api.php they would be worse still — everything there is
| unauthenticated by design.
|
| Resulting paths:
|
|     GET    /admin-api/payments/tamara               webhook + limit state
|     POST   /admin-api/payments/tamara/webhook       register with Tamara
|     DELETE /admin-api/payments/tamara/webhook       remove the registration
|     POST   /admin-api/payments/tamara/limits        pull the basket limits
|     POST   /admin-api/payments/tamara/sweep         settle approvals whose
|                                                     notification never arrived
|
| CAPABILITIES. All five are mapped in App\Support\AdminCapabilities::RULES, and
| AdminCapabilityMapTest pins every one of them by name:
|
|     payments/tamara*        -> payments.manage   (the gateway-settings family)
|
| All four `payments/tamara*` paths are covered by the ONE `['*',
| 'admin-api/payments/tamara/*', 'payments.manage']` rule, sweep included, so
| adding a fifth needs no change to AdminCapabilities.
|
| An unmapped admin route is owner-only at runtime, so neither would have been
| open — but the map is what a reader and a test can see, and the note beside
| `platform.site_url` in that file explains why leaving a real route to the
| deny-by-default is the wrong side of that line.
|
| WHY THE VOID ROUTE IS HERE AND NOT IN payments-settlement.php: that file is
| already wired and mounted, and editing a live route file to add a sixth path
| costs the integrator a merge conflict with whatever else is touching it. This
| file is new, so its require line is a one-line addition that cannot conflict.
| The controller behind it names no gateway, so when Tabby gains the same
| capability the path serves it unchanged.
|
| WRITES BEFORE READS is not an issue here — `payments/tamara` and
| `payments/tamara/webhook` are different lengths and the single-segment `*` in
| AdminCapabilities never crosses a slash — but the DELETE is registered beside
| its POST deliberately, so a reader sees both verbs on one line of the map.
|
| A `clear_caches_*` migration ships with this package
| (2026_09_27_000100_clear_caches_tamara_gateway.php). Without it the compiled
| route cache on the live host knows none of these five paths and every click
| 404s while the buttons render perfectly — which has shipped twice on this
| project already.
|
*/

use App\Http\Controllers\Admin\TamaraAdminController;
use Illuminate\Support\Facades\Route;

Route::get('/payments/tamara', [TamaraAdminController::class, 'show']);
Route::post('/payments/tamara/webhook', [TamaraAdminController::class, 'registerWebhook']);
Route::delete('/payments/tamara/webhook', [TamaraAdminController::class, 'unregisterWebhook']);
Route::post('/payments/tamara/limits', [TamaraAdminController::class, 'refreshLimits']);
Route::post('/payments/tamara/sweep', [TamaraAdminController::class, 'sweep']);
