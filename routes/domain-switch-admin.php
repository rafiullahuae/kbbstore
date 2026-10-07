<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Platform → Domain switch  (Lane DW)
|------------------------------------------------------------------------------
|
| Required by routes/web.php from inside the admin-api group (the integrator
| adds the line; tools/dw-wire.php applies docs/dw-wiring.json):
|
|     require __DIR__.'/domain-switch-admin.php';
|
| Resulting routes, inside `auth:admin` and NoStoreAdminApi:
|
|     GET  /admin-api/domain-switch              every step's state
|     GET  /admin-api/domain-switch/readiness    the domain-check, time-boxed (?offset=)
|     GET  /admin-api/domain-switch/pictures     picture coverage
|     POST /admin-api/domain-switch/run          {action: one of the buttons}
|
| Capability `platform.domain_switch` (AdminCapabilities::RULES), owner-only
| and failing closed; the controller ALSO refuses anyone who is not a Full
| Admin -- see its class comment for why both.
|
| FLAT PATHS, NO ROUTE PARAMETERS: the button arrives in the body and is checked
| against DomainSwitchApiController::ACTIONS, the rule urls-media-admin.php and
| media-sideload-admin.php state. Nothing is chained onto these routes:
| RouteRegistrar::middleware() replaces rather than appends.
|
| Ships with 2027_10_07_210100_clear_caches_domain_switch.php, for the compiled
| route table and the cached role map.
|
*/

use App\Http\Controllers\Admin\DomainSwitchApiController;
use Illuminate\Support\Facades\Route;

Route::get('/domain-switch', [DomainSwitchApiController::class, 'show'])->name('admin.domain-switch');
Route::get('/domain-switch/readiness', [DomainSwitchApiController::class, 'readiness'])->name('admin.domain-switch.readiness');
Route::get('/domain-switch/pictures', [DomainSwitchApiController::class, 'pictures'])->name('admin.domain-switch.pictures');
Route::post('/domain-switch/run', [DomainSwitchApiController::class, 'run'])->name('admin.domain-switch.run');
