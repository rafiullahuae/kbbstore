<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → #KBeautyBliss Spotted  (Lane HB)
|------------------------------------------------------------------------------
|
| The hand-picked Instagram posts for the homepage carousel and the
| /kbeautybliss-spotted/ page, and the section's settings.
| App\Http\Controllers\Admin\SpottedApiController carries the checks.
|
| Resulting paths, all under the existing admin-api prefix:
|
|     GET    /admin-api/spotted               the posts and the settings tabs
|     POST   /admin-api/spotted/settings      save the settings
|     POST   /admin-api/spotted/posts         add a post
|     POST   /admin-api/spotted/posts/{id}    change a post
|     DELETE /admin-api/spotted/posts/{id}    remove a post
|     POST   /admin-api/spotted/order         the list's new order
|     GET    /admin-api/spotted/products      the product picker's search
|     GET    /admin-api/spotted/instagram     (Lane SG) every synced IG post
|     POST   /admin-api/spotted/instagram     (Lane SG) the page's selection
|
| THIS FILE IS REQUIRED FROM routes/web.php BY THE INTEGRATOR, inside the
| existing admin-api group — the one that already carries `web`, `auth:admin`
| and NoStoreAdminApi — beside the instagram-admin.php require:
|
|     require __DIR__.'/spotted-admin.php';
|
| /api/* is unauthenticated (CLAUDE.md); nothing here may live there.
|
| CAPABILITY. `spotted.manage` (owner, manager, editor) on every path —
| App\Support\AdminCapabilities::RULES maps both `admin-api/spotted` and
| `admin-api/spotted/**`. Its own capability, so narrowing it never narrows
| another screen.
|
| The screen is resources/views/admin/partials/spotted-screen.blade.php, which
| the integrator includes ONCE at the end of resources/views/admin/app.blade.php
| beside the slim-footer screen's include:
|
|     @include('admin.partials.spotted-screen')
|
| It registers its own sidebar entry (Appearance → #KBeautyBliss Spotted).
*/

use App\Http\Controllers\Admin\SpottedApiController;
use App\Http\Controllers\Admin\SpottedInstagramApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:120,1')->group(function () {
    Route::get('/spotted', [SpottedApiController::class, 'show'])->name('admin.spotted');
    Route::post('/spotted/settings', [SpottedApiController::class, 'saveSettings'])->name('admin.spotted.settings');
    Route::post('/spotted/posts', [SpottedApiController::class, 'store'])->name('admin.spotted.store');
    Route::post('/spotted/posts/{id}', [SpottedApiController::class, 'update'])->whereNumber('id')->name('admin.spotted.update');
    Route::delete('/spotted/posts/{id}', [SpottedApiController::class, 'destroy'])->whereNumber('id')->name('admin.spotted.destroy');
    Route::post('/spotted/order', [SpottedApiController::class, 'order'])->name('admin.spotted.order');
    Route::get('/spotted/products', [SpottedApiController::class, 'products'])->name('admin.spotted.products');

    // (Lane SG) From Instagram: the synced posts and the page's selection.
    // Capability spotted.instagram (its own RULES line, above spotted/**).
    Route::get('/spotted/instagram', [SpottedInstagramApiController::class, 'show'])->name('admin.spotted.instagram');
    Route::post('/spotted/instagram', [SpottedInstagramApiController::class, 'save'])->name('admin.spotted.instagram.save');
});
