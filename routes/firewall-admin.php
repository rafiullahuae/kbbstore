<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Security → Firewall  (Lane FW)
|------------------------------------------------------------------------------
|
| Required from routes/security-admin.php, so it sits in the same admin-api
| group (web + auth:admin + NoStoreAdminApi) as the Security screen, and
| routes/web.php does not change.
|
| Resulting paths (all under /admin-api):
|
|   GET  security/firewall               the screen                firewall.view
|   GET  security/firewall/live          the live view, refreshed  firewall.view
|   POST security/firewall               mode, scope, limits       firewall.manage
|   POST security/firewall/countries     country rules             firewall.manage
|   POST security/firewall/bots          good-bot switches         firewall.manage
|   POST security/firewall/allow         always allow (+ my IP)    firewall.manage
|   POST security/firewall/allow/remove                            firewall.manage
|   POST security/firewall/unban                                   firewall.manage
|   POST security/firewall/data          download country / bot data (6/min)
|
| CAPABILITIES: both owner-only, mapped in App\Support\AdminCapabilities ABOVE
| the `admin-api/security/**` wildcard (first match wins), so neither falls to
| security.view. A compiled route table hides these until
| 2027_10_13_100100_clear_caches_firewall.php has run.
*/

use App\Http\Controllers\Admin\FirewallApiController;
use Illuminate\Support\Facades\Route;

Route::get('/security/firewall', [FirewallApiController::class, 'show'])->middleware('throttle:60,1,fw');
Route::get('/security/firewall/live', [FirewallApiController::class, 'liveView'])->middleware('throttle:60,1,fw');
Route::post('/security/firewall', [FirewallApiController::class, 'save'])->middleware('throttle:60,1,fw');
Route::post('/security/firewall/countries', [FirewallApiController::class, 'countriesSave'])->middleware('throttle:60,1,fw');
Route::post('/security/firewall/bots', [FirewallApiController::class, 'botsSave'])->middleware('throttle:60,1,fw');
Route::post('/security/firewall/allow', [FirewallApiController::class, 'allowAdd'])->middleware('throttle:60,1,fw');
Route::post('/security/firewall/allow/remove', [FirewallApiController::class, 'allowRemove'])->middleware('throttle:60,1,fw');
Route::post('/security/firewall/unban', [FirewallApiController::class, 'unban'])->middleware('throttle:60,1,fw');
Route::post('/security/firewall/data', [FirewallApiController::class, 'refreshData'])->middleware('throttle:6,1,fw-data');
