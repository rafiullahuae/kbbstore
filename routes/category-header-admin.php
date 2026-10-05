<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The category page's "Edit header" panel  (Lane CH)
|------------------------------------------------------------------------------
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the guarded
| `admin-api` group, directly under the Page header require:
|
|     require __DIR__.'/category-header-admin.php'; // Category "Edit header" panel (Lane CH)
|
| tools/ch-wire.php writes it, checking its anchor first. Both endpoints carry
| `categoryheader.manage` through App\Support\AdminCapabilities (owner,
| manager, editor); a path missing from that map is owner-only, so a mistake
| here fails closed. CSRF is the web group's, as for every admin-api write.
| Rate limits are per admin, in the controller (StorefrontAdminController::
| limit() says why not `throttle:`).
|
|     POST /admin-api/category-header/{id}/preview  the title header, unsaved
|     POST /admin-api/category-header/{id}          save: mode, fields, custom
|
| A clear_caches migration ships with this file for the reason CLAUDE.md gives:
| database/migrations/2027_08_23_100000_clear_caches_category_header.php.
*/

use App\Http\Controllers\Admin\CategoryHeaderApiController;
use Illuminate\Support\Facades\Route;

Route::post('/category-header/{id}/preview', [CategoryHeaderApiController::class, 'preview'])
    ->whereNumber('id')
    ->name('admin.category-header.preview');

Route::post('/category-header/{id}', [CategoryHeaderApiController::class, 'save'])
    ->whereNumber('id')
    ->name('admin.category-header.save');
