<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Pages → Page banners  (Lane SS)
|------------------------------------------------------------------------------
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the guarded
| `admin-api` group, directly under the page editor's require:
|
|     require __DIR__.'/page-banners-admin.php';
|
| Both endpoints carry `pagebanners.manage` through
| App\Support\AdminCapabilities (owner, manager, editor). A path missing from
| that map is owner-only, so a mistake here fails closed.
|
|     GET  /admin-api/page-banners   the banner library, which page shows which,
|                                    and the Super Sale product source
|     POST /admin-api/page-banners   save all of it, all or nothing
|
| A clear_caches migration ships with this file for the reason CLAUDE.md gives:
| database/migrations/2027_08_13_100000_clear_caches_page_banners.php.
*/

use App\Http\Controllers\Admin\PageBannersApiController;
use Illuminate\Support\Facades\Route;

Route::get('/page-banners', [PageBannersApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.page-banners');

Route::post('/page-banners', [PageBannersApiController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.page-banners.save');
// 2.60.388: copy /super-sale/'s order from kbeautybliss.com (a fixed URL).
Route::post('/page-banners/super-sale-order', [PageBannersApiController::class, 'superSaleOrder'])
    ->middleware('throttle:60,1')
    ->name('admin.page-banners.super-sale-order');
