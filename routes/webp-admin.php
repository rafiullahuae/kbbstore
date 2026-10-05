<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Content → Media Library → WebP images  (Lane WP)
|------------------------------------------------------------------------------
|
| The settings and the bulk converter behind the Media Library's "WebP images"
| button. Conversion ON UPLOAD needs no route of its own: it runs inside the one
| upload endpoint, POST /admin-api/media/upload, and MediaUploadTest's "exactly
| one image upload endpoint" pin stays true — no URI here says "upload".
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the EXISTING
| admin-api group — the group that already carries `web`, `auth:admin` and
| NoStoreAdminApi — directly after the image-sizes require:
|
|     require __DIR__.'/image-sizes-admin.php';
|     require __DIR__.'/webp-admin.php';
|
| tools/wp-wire.php writes exactly that, after checking its anchor.
|
| THAT GROUP AND NOTHING ELSE. /api/* is unauthenticated, and /run rewrites
| image addresses across the catalogue while /remove-originals deletes files.
| AdminCapabilities maps admin-api/media/webp and admin-api/media/webp/** to
| `media.optimize` (owner, manager), ABOVE the media/** content.manage line, so
| an editor who can use the Media Library cannot start a shop-wide rewrite.
|
| NONE OF THESE CAN SHADOW, OR BE SHADOWED BY, THE MEDIA LIBRARY'S ROUTES.
| GET /media/{media} is constrained to [0-9]+, so `webp` cannot match it.
|
| Resulting paths:
|
|     GET  /admin-api/media/webp                    status, settings, the log
|     POST /admin-api/media/webp/settings           save the four settings
|     POST /admin-api/media/webp/plan               dry run, one batch; after=
|     POST /admin-api/media/webp/run                convert one batch
|     POST /admin-api/media/webp/restore            undo one batch; confirm=UNDO
|     POST /admin-api/media/webp/remove-originals   one batch; confirm=REMOVE
|
*/

use App\Http\Controllers\Admin\WebpApiController;
use Illuminate\Support\Facades\Route;

Route::get('/media/webp', [WebpApiController::class, 'status']);
Route::post('/media/webp/settings', [WebpApiController::class, 'settings']);
Route::post('/media/webp/plan', [WebpApiController::class, 'plan']);
Route::post('/media/webp/run', [WebpApiController::class, 'run']);
Route::post('/media/webp/restore', [WebpApiController::class, 'restore']);
Route::post('/media/webp/remove-originals', [WebpApiController::class, 'removeOriginals']);
