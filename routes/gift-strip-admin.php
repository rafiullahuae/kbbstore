<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance -> Free gifts strip  (Lane GST)
|------------------------------------------------------------------------------
|
| The thin free-gifts bar under the homepage's main banner: its design (S1-S5,
| picked from a list with a live preview), words EN + AR, link, products and
| their real heights, and the "Prepare cut-outs" step. App\Support\GiftStrip
| carries the schema and the reasons.
|
| Wire it inside the admin-api group of routes/web.php -- the one that already
| carries `web`, `auth:admin` and NoStoreAdminApi -- next to its siblings:
|
|     require __DIR__.'/gift-strip-admin.php';
|
| Resulting paths:
|
|     GET  /admin-api/gift-strip            everything the screen draws
|     POST /admin-api/gift-strip            save (and rebuild the snapshot)
|     GET  /admin-api/gift-strip/products   "Pick products" search
|     POST /admin-api/gift-strip/cutout     make one product's cut-out
|
| CAPABILITY. `giftstrip.manage` (owner, manager, editor -- storefront
| appearance), mapped in AdminCapabilities for the bare path AND its children:
| '/**' does not match the bare path. A path left off that map is owner-only.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships with
| database/migrations/2028_01_12_100100_clear_caches_gift_strip.php.
*/

use App\Http\Controllers\Admin\GiftStripApiController;
use Illuminate\Support\Facades\Route;

Route::get('/gift-strip', [GiftStripApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.gift-strip');

Route::post('/gift-strip', [GiftStripApiController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.gift-strip.save');

// Debounced on the screen (300 ms, 2 characters); never on a timer.
Route::get('/gift-strip/products', [GiftStripApiController::class, 'products'])
    ->middleware('throttle:120,1')
    ->name('admin.gift-strip.products');

// One product per request, sequentially, ~1-2 s of GD each: 30 a minute is
// more than "Prepare cut-outs" for every gift needs, and caps the CPU.
Route::post('/gift-strip/cutout', [GiftStripApiController::class, 'cutout'])
    ->middleware('throttle:30,1')
    ->name('admin.gift-strip.cutout');
