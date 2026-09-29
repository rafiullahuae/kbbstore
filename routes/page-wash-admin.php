<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → Page background  (Lane BG)
|------------------------------------------------------------------------------
|
| The soft multi-colour wash behind the shop, and the four treatments the owner
| previews it with. App\Services\PageWash carries the schema, the arithmetic
| behind every colour, and the argument for each default.
|
| INTEGRATOR: this file is a `require`, the way routes/site-layout-admin.php
| already is. Add the last line below to routes/web.php, inside the SAME
| admin-api group, directly under the site-layout require that is already there:
|
|     require __DIR__.'/site-layout-admin.php';
|     require __DIR__.'/page-wash-admin.php';        <-- this file
|
| Resulting paths:
|
|     GET  /admin-api/page-wash    the nine fields in three tabs, plus the four
|                                  treatments and the stylesheet being sent now
|     POST /admin-api/page-wash    save
|
| THIS GROUP AND NOTHING ELSE — the one in routes/web.php that already carries
| `web`, `auth:admin` and NoStoreAdminApi. /api/* there is unauthenticated, and
| this POST writes colours and integers that are printed into a stylesheet on
| every page of the shop.
|
| CAPABILITY. `pagewash.manage`, held by owner, manager and editor: the same
| three that hold sitelayout.manage, cartpage.manage and checkoutpage.manage,
| and for the same reason — this is storefront appearance. Its own capability so
| that narrowing one never silently narrows another from a different file, and
| App\Support\AdminCapabilities::RULES fails closed, so an account without it is
| refused both verbs rather than being allowed the read.
|
| THERE IS NO PREVIEW ROUTE HERE, AND THAT IS THE DESIGN RATHER THAN AN OMISSION.
| The owner asked to see the wash "on our site". A route that re-rendered the
| homepage would be showing him a copy, and the two things this wash can break —
| a white card going translucent, text losing its background — live on the real
| pages, in other lanes' stylesheets, behind the real catalogue. So the preview
| is /, /shop/, /product/{slug}/, /cart/ and /skincare-guide/ THEMSELVES, with
| `?kbbwash=a|b|c|d` on them, honoured only for a request that carries an ADMIN
| SESSION. See PageWash::PREVIEW_PARAM for the three conditions in the order
| they are checked, and PageWashTest for the signed-out case asserted over HTTP.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2027_06_02_000000_clear_caches_page_wash.php.
*/

use App\Http\Controllers\Admin\PageWashApiController;
use Illuminate\Support\Facades\Route;

Route::get('/page-wash', [PageWashApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.page-wash');

Route::post('/page-wash', [PageWashApiController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.page-wash.save');
