<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → Set  (Lane: SA)
|------------------------------------------------------------------------------
|
| The screen behind the owner's brief — "I need the full controls of everything
| like spacing, fonts, elements turn on off etc etc. every single details. for
| mobile and desktop both separate tabs. Under Appearance → Set → desktop /
| mobile." App\Services\SetAppearance carries the schema and the argument for
| every default.
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the EXISTING
| admin-api group — the group that already carries `web`, `auth:admin` and
| NoStoreAdminApi — beside the other requires:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/set-appearance-admin.php';
|     });
|
| CLAUDE.md forbids this lane from editing routes/web.php, so the file ships for
| the integrator to add that one line.
|
| Resulting paths:
|
|     GET  /admin-api/set-appearance          every field, in the two tabs
|     POST /admin-api/set-appearance          save
|     POST /admin-api/set-appearance/preview  render the real set partial from
|                                             the values the owner has TYPED,
|                                             writing nothing
|
| THAT GROUP AND NOTHING ELSE. /api/* here is unauthenticated, and the POST
| rewrites what every shopper sees in the cart drawer, on the cart page and in
| the checkout summary.
|
| CAPABILITY. `setappearance.manage`, held by owner, manager and editor — the
| same three that hold `cartpage.manage`, `checkoutpage.manage` and
| `slimfooter.manage`, and for the same reason: this is storefront appearance,
| nothing here can take the shop down or redirect its mail, and making an editor
| an owner to move a slider is how capabilities stop being used. It is a
| capability of its own rather than a reuse of cartpage.manage so that narrowing
| one never silently narrows the other from a different file.
|
| The '/**' line is a SIBLING of the exact one above it and not a parent:
| matching is by pattern, and 'admin-api/set-appearance' does not match
| 'admin-api/set-appearance/preview', so both are needed. A '*' rule and not a
| GET/POST split, because the preview is a POST that reads — there is no reading
| half here a narrower role should reach without the writing half.
|
| THROTTLES on the route. The preview fires on a debounce while a slider is
| dragged, and a debounce is a promise made by the browser: the ceiling is the
| one made by the server. It renders a Blade and touches the catalogue once, so
| it gets a higher allowance than the save and a real one all the same.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2026_12_20_000001_clear_caches_set_appearance.php.
*/

use App\Http\Controllers\Admin\SetAppearanceApiController;
use Illuminate\Support\Facades\Route;

Route::get('/set-appearance', [SetAppearanceApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.set-appearance');

Route::post('/set-appearance', [SetAppearanceApiController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.set-appearance.save');

Route::post('/set-appearance/preview', [SetAppearanceApiController::class, 'preview'])
    ->middleware('throttle:180,1')
    ->name('admin.set-appearance.preview');
