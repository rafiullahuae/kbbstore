<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Content → Media Library → phone-sized copies  (Lane DK)
|------------------------------------------------------------------------------
|
| Two endpoints behind the Media Library's "Make phone-sized copies" button: how
| much of the catalogue still needs smaller copies of its photographs, and one
| bounded batch of the work. App\Http\Controllers\Admin\ImageSizesApiController
| carries the reasoning for why the work is done in batches from a screen rather
| than by a command or a worker.
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the EXISTING
| admin-api group — the group that already carries `web`, `auth:admin` and
| NoStoreAdminApi — beside the other requires, after the media library's own:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/media-library-admin.php';
|         require __DIR__.'/image-sizes-admin.php';
|     });
|
| CLAUDE.md forbids this lane from editing routes/web.php, so the file ships for
| the integrator to add that one line. The paragraph above describes where the
| require belongs rather than claiming anything about whether it is there yet,
| so it stays true on both sides of that edit — RouteFileHeadersTest reads this
| file whole and a route file that web.php requires may not go on describing
| itself as unmounted.
|
| Resulting paths:
|
|     GET  /admin-api/media/image-sizes        what is done, what is left, and
|                                              whether this PHP can resize at all
|     POST /admin-api/media/image-sizes/run    one batch; body: after=<cursor>
|
| THAT GROUP, AND NOTHING ELSE. /api/* in this app is unauthenticated by design,
| and POST .../run writes files into the public web root and spends real CPU
| doing it. Outside auth:admin that is a stranger with a loop, filling the
| owner's disk from a host that charges him for it. The capability map already
| covers both paths through its `['*', 'admin-api/media/**', 'content.manage']`
| entry, which is the other reason they are named under /media rather than at a
| prefix of their own.
|
| NEITHER CAN SHADOW THE MEDIA LIBRARY'S ROUTES, and none of its can shadow
| these. routes/media-library-admin.php registers GET /media/{media} constrained
| to [0-9]+, so the literal segment `image-sizes` cannot match it whichever file
| is required first; its DELETE and its POST /media/rescan are different methods
| or different literals. This is the same belt-and-trousers check that file
| already makes against POST /media/upload, and for the same reason: /orders/list
| was once unreachable behind /orders/{id} in this repository.
|
| These add no upload path. Neither takes a file; /run reads what the catalogue
| already points at and writes only into public/img-cache. MediaUploadTest's
| "exactly one image upload endpoint" assertion stays true.
|
*/

use App\Http\Controllers\Admin\ImageSizesApiController;
use Illuminate\Support\Facades\Route;

Route::get('/media/image-sizes', [ImageSizesApiController::class, 'status']);

Route::post('/media/image-sizes/run', [ImageSizesApiController::class, 'run']);
