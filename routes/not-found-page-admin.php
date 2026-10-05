<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Safety → 404 page  (Lane NF)
|------------------------------------------------------------------------------
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the guarded
| `admin-api` group, directly under the Catalog → Pagination require:
|
|     require __DIR__.'/not-found-page-admin.php';  // Safety → 404 page (Lane NF)
|
| tools/nf-wire.php writes it (docs/nf-wiring.json), checking its anchor first.
| Both endpoints carry `notfoundpage.manage` through App\Support\
| AdminCapabilities (owner, manager); a path missing from that map is
| owner-only, so a mistake here fails closed. CSRF is the web group's.
|
|     GET  /admin-api/not-found-page   the settings, the four designs, the sheet
|     POST /admin-api/not-found-page   save {config}
|
| A clear_caches migration ships with this file for the reason CLAUDE.md gives:
| database/migrations/2027_08_25_100400_clear_caches_not_found_page.php.
*/

use App\Http\Controllers\Admin\NotFoundPageApiController;
use Illuminate\Support\Facades\Route;

Route::get('/not-found-page', [NotFoundPageApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.notfoundpage');

Route::post('/not-found-page', [NotFoundPageApiController::class, 'save'])
    ->middleware('throttle:30,1')
    ->name('admin.notfoundpage.save');
