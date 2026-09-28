<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Sets → Stock · When a set is sold  (Lane SP)
|------------------------------------------------------------------------------
|
| One setting, two options, shipped at the value this shop already behaves as.
| The question — does selling a set take one of each member off the shelf? — has
| been put to the owner twice without an answer, so it is BUILT and SWITCHED OFF
| rather than decided here. App\Services\StockSetRule carries the argument both
| ways and the reason the default is what it is.
|
|     GET   /admin-api/set-stock     what the switch is set to
|     POST  /admin-api/set-stock     set it
|
| ── WHAT THE INTEGRATOR MUST WIRE ───────────────────────────────────────────
|
| ONE LINE, inside the EXISTING admin-api group in routes/web.php — the one
| opened by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which itself sits inside the `Route::middleware('auth:admin')` group. Put it
| immediately after the sets require:
|
|     // Catalog → Sets → Stock. The one switch that decides whether selling a
|     // set takes its members off the shelf. Ships at today's behaviour.
|     require __DIR__.'/sp-set-stock-admin.php';
|
| IT MUST GO INSIDE THAT GROUP. A POST here changes how every order this shop
| places moves inventory. Mounted anywhere else it is a public endpoint for
| emptying the owner's shelves.
|
| tests/Feature/SetStockWiredTest.php pins the FINISHED state — that
| routes/web.php requires this file EXACTLY ONCE. Zero is "built, never wired
| up", which is the shape this repository keeps finding; two registers both
| routes twice. It does NOT assert the absence of the require, which is the
| assertion CLAUDE.md records as having cost this project three round trips.
|
| ── CAPABILITY. ONE, AND IT IS NEW ──────────────────────────────────────────
|
|   `sets.stock`   read and write the rule.
|
| DELIBERATELY NOT `sets.manage`. Creating and repricing sets is a catalogue
| act; deciding that selling one empties three other shelves is an inventory
| act, and it can oversell or refuse sales without anything on the Sets screen
| changing. The two are mapped separately so narrowing one never narrows the
| other from another file with nothing to notice.
|
| It fails closed with no code here: an admin route App\Support\AdminCapabilities
| ::RULES does not recognise resolves to null and EnforceAdminCapability turns
| null into 403 for everyone but the owner.
|
| ── THE CLEAR-CACHES MIGRATION ──────────────────────────────────────────────
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2027_04_02_000100_clear_caches_set_stock.php.
*/

use App\Http\Controllers\Admin\SetStockApiController;
use Illuminate\Support\Facades\Route;

Route::get('/set-stock', [SetStockApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.sets.stock.show');

Route::post('/set-stock', [SetStockApiController::class, 'save'])
    ->middleware('throttle:30,1')
    ->name('admin.sets.stock.save');
