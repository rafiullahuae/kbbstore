<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Pages → Page header  (Lane PH)
|------------------------------------------------------------------------------
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the guarded
| `admin-api` group, directly under the Page banners require:
|
|     require __DIR__.'/page-header-admin.php';     // Pages → Page header (Lane PH)
|
| tools/ph-wire.php writes it (and the console's four lines), checking every
| anchor first. All three endpoints carry `pageheader.manage` through
| App\Support\AdminCapabilities (owner, manager, editor); a path missing from
| that map is owner-only, so a mistake here fails closed. CSRF is the web
| group's, as for every admin-api write.
|
|     GET  /admin-api/page-header        the global look, every page's own look,
|                                        the page list and the controls
|     POST /admin-api/page-header        save all of it, all or nothing
|     POST /admin-api/page-header/apply  the storefront's "Edit header" panel:
|                                        {key, scope: page|global|inherit, bag}
|
| A clear_caches migration ships with this file for the reason CLAUDE.md gives:
| database/migrations/2027_08_14_100000_clear_caches_page_header.php.
*/

use App\Http\Controllers\Admin\PageHeaderApiController;
use Illuminate\Support\Facades\Route;

Route::get('/page-header', [PageHeaderApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.page-header');

Route::post('/page-header', [PageHeaderApiController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.page-header.save');

Route::post('/page-header/apply', [PageHeaderApiController::class, 'apply'])
    ->middleware('throttle:60,1')
    ->name('admin.page-header.apply');
