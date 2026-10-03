<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| /kbeautybliss-spotted/ — the hand-picked Instagram grid  (Lane HB)
|------------------------------------------------------------------------------
|
| Master plan row 55, item 4. One public GET, no session-dependent content.
|
| THIS FILE IS REQUIRED FROM routes/web.php BY THE INTEGRATOR, among the
| storefront routes and BEFORE the editable content pages, with exactly:
|
|     require __DIR__.'/spotted.php';
|
| and tests/Support/EnglishRenderWalk::expectations() already lists
| 'kbeautybliss-spotted' whenever this route is registered (it reads the router),
| so the walk's "every storefront GET route has an entry" check holds on both
| sides of the require.
|
| The admin endpoints are a separate file, routes/spotted-admin.php, because
| they belong inside the guarded admin-api group and this one must not.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships with
| database/migrations/2027_07_27_100200_clear_caches_spotted_and_footer.php.
*/

use App\Http\Controllers\Store\SpottedController;
use Illuminate\Support\Facades\Route;

Route::get('/kbeautybliss-spotted', SpottedController::class)->name('spotted.page');
