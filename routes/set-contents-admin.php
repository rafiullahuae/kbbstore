<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → Set contents  (Lane SF)
|------------------------------------------------------------------------------
|
| Which of four drawings a set's product page uses for the block that names
| what is in the box, and a rendered picture of each so the owner picks from
| the page rather than from a description of it. App\Support\SetPanelDesign
| carries the four keys and the argument for the default.
|
| INTEGRATOR: this file is a `require`, the way routes/slim-footer-admin.php
| already is. Add ONE line to routes/web.php, inside the admin-api group, next
| to the other Appearance screens — directly under the slim-footer line is the
| natural spot:
|
|     require __DIR__.'/slim-footer-admin.php';
|     require __DIR__.'/set-contents-admin.php';          <-- this file
|
| Resulting paths:
|
|     GET  /admin-api/set-contents           the four designs and which is live
|     POST /admin-api/set-contents           store one of the four
|     POST /admin-api/set-contents/preview   one design, rendered, as HTML
|
| THAT GROUP AND NOTHING ELSE — the one that already carries `web`,
| `auth:admin` and NoStoreAdminApi. /api/* is unauthenticated on this shop and
| the POST above rewrites how every set page in the catalogue is drawn.
|
| CAPABILITY. `setcontents.manage`, held by owner, manager and editor: the same
| three that hold cartpage.manage, checkoutpage.manage and slimfooter.manage,
| and for the same reason — this is storefront appearance, it can take nothing
| down and it redirects no mail. Its own capability rather than sharing one, so
| that narrowing another screen's never silently narrows this one from a
| different file. App\Support\AdminCapabilities::RULES carries
|
|     ['*', 'admin-api/set-contents', 'setcontents.manage'],
|     ['*', 'admin-api/set-contents/**', 'setcontents.manage'],
|
| and the '/**' sibling is what covers /preview: the exact rule does not match
| a sub-path, which the cart page's own entry says in as many words. That map
| fails closed, so a missing rule is a 403 on a screen the owner cannot fix
| without a shell.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2027_04_20_000000_clear_caches_set_contents.php — which
| this release needs anyway, for the compiled views of the panel and its four
| new design partials.
*/

use App\Http\Controllers\Admin\SetContentsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/set-contents', [SetContentsApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.set-contents');

Route::post('/set-contents', [SetContentsApiController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.set-contents.save');

/*
 * The preview is its own throttle and a looser one: the screen asks for four
 * renders when it opens and four more each time the owner changes the set or
 * flips to Arabic, so 60 a minute would rate-limit ordinary use of the screen
 * after three or four clicks. It writes nothing.
 */
Route::post('/set-contents/preview', [SetContentsApiController::class, 'preview'])
    ->middleware('throttle:120,1')
    ->name('admin.set-contents.preview');
