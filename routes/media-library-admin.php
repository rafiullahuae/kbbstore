<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Content → Media Library — Lane AX
|------------------------------------------------------------------------------
|
| The grid of everything uploaded, its search and filters, the detail panel and
| delete. The screen itself is
| resources/views/admin/partials/media-library-screen.blade.php.
|
| CLAUDE.md forbids this lane from editing routes/web.php, so the integrator
| wires it up. The note below is the record of where that one line belongs.
|
| INTEGRATOR — ONE LINE, and it must go INSIDE the existing admin-api group in
| routes/web.php: the group opened by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which itself sits inside `Route::middleware('auth:admin')`. Put it directly
| after the categories-brands require:
|
|     // Content → Media Library: the grid, its search and delete. Same guarded
|     // group as everything else under admin-api — see the note below on why
|     // that is load-bearing rather than tidy.
|     require __DIR__.'/media-library-admin.php';
|
| WHY THE GROUP MATTERS. GET /admin-api/media lists every asset the store owns,
| including anything uploaded to a draft product that has never been published.
| DELETE /admin-api/media/{media} removes a row AND unlinks the file from the
| public web root. Mounted outside auth:admin the first is an inventory of the
| store handed to anybody who asks and the second is a stranger deleting the
| shop's photographs. Not in routes/api.php for the same reason — CLAUDE.md:
| "/api/* is unauthenticated". tests/Feature/MediaLibraryTest.php asserts the
| guard from the REGISTERED routes, for anonymous, for a storefront customer
| and for a plain `web` user, so a future remount outside the group goes red.
|
| Resulting paths:
|
|     GET    /admin-api/media                 the grid: q, from, to, attached,
|                                             attached_q, page
|     POST   /admin-api/media/rescan          catalogue files on disk with no row
|     GET    /admin-api/media/{media}         one image and everywhere it is used
|     DELETE /admin-api/media/{media}         delete; refuses 409 while in use,
|                                             ?force=1 to override
|
| /media/rescan is NOT an upload path and adds none: it takes no file and no
| body, and only reads public/uploads for images the table has not catalogued
| yet. MediaUploadTest's "exactly one image upload endpoint" assertion stays
| true, and is the thing that would go red if that ever stopped being so.
|
| ORDERING AND SHADOWING. routes/web.php already registers
|
|     POST /admin-api/media/upload
|
| and that is the ONE upload endpoint in this application — the product gallery,
| brand logos, category images and the SEO share image all post to it, and
| MediaUploadTest pins that there is exactly one. Nothing here adds a second.
| The routes below cannot swallow it: the upload route is POST and these are GET
| and DELETE, and {media} is additionally constrained to [0-9]+ so the literal
| `upload` segment could not match it even if a method were ever added. Both
| belts, same trousers, deliberately — this is the exact shape that once made
| /orders/list unreachable behind /orders/{id}.
|
| The {media} binding resolves App\Models\Media by id, so an unknown id is a 404
| from the router rather than a null dereference in the controller.
|
*/

use App\Http\Controllers\Admin\MediaLibraryApiController;
use Illuminate\Support\Facades\Route;

Route::get('/media', [MediaLibraryApiController::class, 'index']);

// Before the {media} routes as a matter of habit; it could not be shadowed by
// them anyway, since they are constrained to [0-9]+ and this is POST.
Route::post('/media/rescan', [MediaLibraryApiController::class, 'rescan']);

Route::get('/media/{media}', [MediaLibraryApiController::class, 'show'])
    ->where('media', '[0-9]+');

Route::delete('/media/{media}', [MediaLibraryApiController::class, 'destroy'])
    ->where('media', '[0-9]+');
