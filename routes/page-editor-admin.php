<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Content → Pages → User pages → Edit  (Lane S9)
|------------------------------------------------------------------------------
|
| MOUNTED, inside the EXISTING admin-api group in routes/web.php — the group that
| already carries `auth:admin` and NoStoreAdminApi — immediately after the two
| lines registering the read-only Pages lists, so both halves of the Pages screen
| sit together. CLAUDE.md forbids a lane from editing routes/web.php, so the
| integrator adds the line; RouteFileHeadersTest fails a header that still calls
| a required file unmounted, so this paragraph is written as it will read once it
| is in. The shape it is added in:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         Route::get('/pages/store', [\App\Http\Controllers\Admin\PagesApiController::class, 'store']);
|         Route::get('/pages/user',  [\App\Http\Controllers\Admin\PagesApiController::class, 'user']);
|         // The other half of that screen: EDITING a content page, not just
|         // listing one. Same group, for the same reason.
|         require __DIR__.'/page-editor-admin.php';
|         ...
|     });
|
| THE EXACT LINE TO ADD, directly under the existing `/pages/user` route
| (routes/web.php:804):
|
|     require __DIR__.'/page-editor-admin.php';
|
| THAT GROUP, AND NOTHING ELSE. `POST /admin-api/page-editor-save/{id}` rewrites
| the body of a page the storefront prints UNESCAPED, at an address the footer
| links to from every page of the shop. `/api/*` in this application is
| unauthenticated BY DESIGN — CLAUDE.md says so and every case in
| tests/Feature/ApiSecurityTest.php leaked in production first — so mounting any
| of this there would hand the public the ability to rewrite this shop's privacy
| policy. ContentPageEditorTest asserts that refusal on every route below for an
| anonymous caller, a signed-in storefront customer and a plain `web` user,
| reading the middleware back off the REGISTERED routes rather than trusting the
| harness.
|
| CAPABILITY. `pages.manage`, its own entry in App\Support\AdminCapabilities —
| owner, manager and editor, the same three roles `content.manage` carries, and
| separable for the reason `posts.manage` is separable one screen over: rewriting
| the terms, the returns policy and the privacy policy of the shop is a stronger
| thing to hand out than the mega menu and the media library, and narrowing who
| may do it must not have to narrow those with it.
|
| READING the list of pages stays on `content.manage` — GET /admin-api/pages/*,
| already mapped — exactly as GET /admin-api/posts does. The list endpoint HERE
| is `pages.manage`, because it is the editor's own projection and it reports
| whether a row carries an SEO override.
|
| A `clear_caches_*` migration ships with this package
| (2027_02_24_000000_clear_caches_page_editor.php). Routes, an admin Blade
| partial and PHP classes all change. Without it the compiled route cache on the
| live host knows none of these paths, and the failure is the quiet kind: the
| editor renders in full, the owner rewrites the returns policy and its Arabic —
| and Save 404s, having thrown all of it away.
|
| Routes added:
|
|     GET  /admin-api/page-editor-bootstrap   form shape, and which slugs are routed
|     GET  /admin-api/page-editor-list        the editor's own list of content pages
|     GET  /admin-api/page-editor-load/{id}   one page, the editor's projection
|     POST /admin-api/page-editor-save/{id}   save an existing page
|
| THERE IS NO CREATE ROUTE AND NO DELETE ROUTE, and neither is an omission to be
| quietly filled in later. The seven content pages are seven LITERAL routes in
| routes/web.php carrying `->defaults('slug', …)`, and the site-root catch-all
| `/{slug}/` reaches PageController::post(), which queries `Post` only — so a
| page created at any other slug is a row no request can reach, and a page
| deleted takes a footer-linked, sitemap-submitted URL to a 404. The full
| argument, with the measurement behind it, is in the header of
| App\Http\Controllers\Admin\PageEditorApiController. Taking a page down is
| `status: draft`, which the save route does.
|
| WHY THE PATHS ARE FLAT AND PREFIXED, rather than /pages/{id}. routes/web.php
| already registers `GET /admin-api/pages/store` and `GET /admin-api/pages/user`,
| and AdminCapabilities maps `GET admin-api/pages/*` to `content.manage`. A write
| path under that prefix would be decided by where in a 900-line route file
| somebody pasted a require, and by which capability rule matched first — the
| Orders lane lost a release to exactly that (`/orders/list` matched
| `/orders/{id}` and reached a controller whose signature is `int $id`).
| `page-editor-` is the same shape Lane AO chose for the product editor and Lane
| J for the article editor, and it cannot collide whatever order the requires end
| up in.
|
| {id} is constrained to digits, so a stray path segment 404s rather than
| reaching a controller with a TypeError.
|
| WHY THERE IS NO SLUG ON THE SAVE ROUTE. A content page's address is named by
| its ROUTE, not by its row, so changing the slug would 404 the page at both
| addresses: the old route would look up a slug no row has, and the new slug has
| no route. save() refuses a posted slug with `prohibited` and a message that
| says that. Moving a page is Store → SEO & Meta → Redirects.
|
| WHY THERE IS NO UPLOAD ROUTE. There is exactly one file-upload endpoint in this
| application — POST /admin-api/media/upload — and the social-image box posts to
| it through the shared picker, the same way the product gallery and the article
| cover do. What crosses the routes below is the URL that endpoint returned,
| never a file.
|
*/

use App\Http\Controllers\Admin\PageEditorApiController;
use Illuminate\Support\Facades\Route;

Route::get('/page-editor-bootstrap', [PageEditorApiController::class, 'bootstrap']);
Route::get('/page-editor-list', [PageEditorApiController::class, 'index']);

Route::get('/page-editor-load/{id}', [PageEditorApiController::class, 'show'])
    ->whereNumber('id');

Route::post('/page-editor-save/{id}', [PageEditorApiController::class, 'save'])
    ->whereNumber('id');
