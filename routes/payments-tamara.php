<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Tamara gateway admin API — void, webhook registration, basket limits (Lane PG1)
|------------------------------------------------------------------------------
|
| NOT YET WIRED. Add ONE line to routes/web.php, inside the EXISTING `admin-api`
| group — the one that carries `auth:admin` and NoStoreAdminApi, beside the other
| payment requires:
|
|     require __DIR__.'/payments-tamara.php';
|
| Put it next to `require __DIR__.'/payments-settlement.php';`. It must come
| before web.php's GET-only Route::fallback, which every require in that group
| already does.
|
| IT MUST STAY INSIDE THAT GROUP, and this is not boilerplate. Mounted without
| `auth:admin`:
|
|   - POST /admin-api/orders/{id}/void is a way for a stranger to cancel the
|     payment plan behind every cancelled order in the shop;
|   - DELETE /admin-api/payments/tamara/webhook silently stops Tamara telling
|     this shop about declines, which is invisible until somebody audits
|     `pending` orders;
|   - POST /admin-api/payments/tamara/limits rewrites which baskets are offered
|     BNPL at all.
|
| Mounted in routes/api.php they would be worse still — everything there is
| unauthenticated by design.
|
| Resulting paths:
|
|     POST   /admin-api/orders/{id}/void              release the authorisation
|     GET    /admin-api/payments/tamara               webhook + limit state
|     POST   /admin-api/payments/tamara/webhook       register with Tamara
|     DELETE /admin-api/payments/tamara/webhook       remove the registration
|     POST   /admin-api/payments/tamara/limits        pull the basket limits
|
| CAPABILITIES. All five are mapped in App\Support\AdminCapabilities::RULES, and
| AdminCapabilityMapTest pins every one of them by name:
|
|     orders/{id}/void        -> orders.money      (the capture/refund family)
|     payments/tamara*        -> payments.manage   (the gateway-settings family)
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

use App\Http\Controllers\Admin\PaymentVoidController;
use App\Http\Controllers\Admin\TamaraAdminController;
use Illuminate\Support\Facades\Route;

Route::post('/orders/{id}/void', [PaymentVoidController::class, 'void'])
    ->where('id', '[0-9]+');

Route::get('/payments/tamara', [TamaraAdminController::class, 'show']);
Route::post('/payments/tamara/webhook', [TamaraAdminController::class, 'registerWebhook']);
Route::delete('/payments/tamara/webhook', [TamaraAdminController::class, 'unregisterWebhook']);
Route::post('/payments/tamara/limits', [TamaraAdminController::class, 'refreshLimits']);
