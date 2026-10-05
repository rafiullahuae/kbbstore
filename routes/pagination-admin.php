<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Pagination  (Lane PG)
|------------------------------------------------------------------------------
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the guarded
| `admin-api` group, directly under the Category "Edit header" require:
|
|     require __DIR__.'/pagination-admin.php';      // Catalog → Pagination (Lane PG)
|
| tools/pg-wire.php writes it (docs/pg-wiring.json), checking its anchor first.
| Both endpoints carry `pagination.manage` through App\Support\
| AdminCapabilities (owner, manager, editor); a path missing from that map is
| owner-only, so a mistake here fails closed. CSRF is the web group's.
|
|     GET  /admin-api/pagination   the screen's data, once
|     POST /admin-api/pagination   save {on, overrides}
|
| A clear_caches migration ships with this file for the reason CLAUDE.md gives:
| database/migrations/2027_08_25_100000_clear_caches_listing_pagination.php.
*/

use App\Http\Controllers\Admin\ListingPaginationApiController;
use Illuminate\Support\Facades\Route;

Route::get('/pagination', [ListingPaginationApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.pagination');

Route::post('/pagination', [ListingPaginationApiController::class, 'save'])
    ->middleware('throttle:30,1')
    ->name('admin.pagination.save');
