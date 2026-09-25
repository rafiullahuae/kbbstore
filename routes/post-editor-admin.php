<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Content → Blog Posts → New article / Edit  (Lane J)
|------------------------------------------------------------------------------
|
| NOT WIRED YET. CLAUDE.md forbids this lane from editing routes/web.php, so
| this file ships unmounted and the integrator adds ONE line, inside the
| EXISTING admin-api group in routes/web.php — the group that already carries
| `auth:admin` and NoStoreAdminApi — immediately after the line that registers
| the read-only Blog Posts list, so the Journal's two halves sit together:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         Route::get('/posts', [\App\Http\Controllers\Admin\PostsApiController::class, 'index']);
|         // The other half of that screen: writing an article, not just listing
|         // one. Same group, for the same reason.
|         require __DIR__.'/post-editor-admin.php';
|         ...
|     });
|
| THE EXACT LINE TO ADD, at routes/web.php line 309 (directly under the existing
| `Route::get('/posts', …)`):
|
|     require __DIR__.'/post-editor-admin.php';
|
| THAT GROUP, AND NOTHING ELSE. Two of the routes below publish a page at the
| site root of this shop in a single request. `/api/*` in this application is
| unauthenticated BY DESIGN — CLAUDE.md says so and every case in
| tests/Feature/ApiSecurityTest.php leaked in production first — so mounting any
| of this there would hand the public the ability to publish an article on this
| shop's own domain, which is a spam vector with the shop's reputation behind
| it. PostEditorTest asserts that refusal on every route below for an anonymous
| caller, a signed-in storefront customer and a plain `web` user, reading the
| middleware back off the REGISTERED routes rather than trusting the harness.
|
| CAPABILITY. `posts.manage`, its own entry in App\Support\AdminCapabilities —
| owner, manager and editor, the same three roles `content.manage` carries, but
| separable. Publishing at the site root of the shop is a reasonable thing to
| want to narrow one day without narrowing the Mega Menu and the media library
| with it, and AdminCapabilityMapTest's coverage assertion means an unmapped
| route here would fail the suite rather than fall through to the closed
| owner-only default unnoticed.
|
| A `clear_caches_*` migration ships with this package
| (2026_12_28_000000_clear_caches_journal_editor.php). Routes, Blade and PHP
| classes all change here. Without it the compiled route cache on the live host
| knows none of these paths, and the failure is the quiet kind: the editor
| renders in full, the owner writes an article, sets its cover and its Arabic —
| and Save 404s, having thrown all of it away.
|
| Routes added:
|
|     GET  /admin-api/post-editor-bootstrap   blank form shape + journal address
|     GET  /admin-api/post-editor-load/{id}   one article, the editor's projection
|     POST /admin-api/post-editor-slug        the address a title would publish at
|     POST /admin-api/post-editor-create      create one article
|     POST /admin-api/post-editor-save/{id}   save an existing article
|
| WHY THE PATHS ARE FLAT AND PREFIXED, rather than /posts/{id}. routes/web.php
| already registers `GET /admin-api/posts`, and Laravel matches the first route
| registered: a path an existing wildcard covers is decided by where in a
| 700-line file somebody pasted a require, and the resulting symptom points
| nowhere near the cause — the Orders lane lost a release to exactly that
| (`/orders/list` matched `/orders/{id}` and reached a controller whose
| signature is `int $id`). `post-editor-` is the same shape Lane AO chose for
| the product editor and cannot collide whatever order the requires end up in.
|
| {id} is constrained to digits, so a stray path segment 404s rather than
| reaching a controller with a TypeError.
|
| WHY THERE IS NO SLUG ON THE SAVE ROUTE. An article's address is a live URL
| contract, `/{slug}/`, and it is set at create and not a field afterwards —
| the same rule ProductEditorApiController states for a product. Moving one is
| Store → SEO & Meta → Redirects, which writes the 301 that keeps the ranking.
|
| WHY THERE IS NO DELETE ROUTE, single or bulk. Deliberate, and not an omission
| to be quietly filled in. Taking an article off the shop is `status: draft`,
| which this editor does, and which leaves the row — and therefore the address —
| recoverable. A delete would free the slug for reuse, and an article's slug is
| the thing Google is holding.
|
| WHY THERE IS NO UPLOAD ROUTE. There is exactly one file-upload endpoint in
| this application — POST /admin-api/media/upload — and the cover box posts to
| it through the shared picker, the same way the product gallery and the brand
| logo do. What crosses the routes below is the URL that endpoint returned,
| never a file.
|
*/

use App\Http\Controllers\Admin\PostEditorApiController;
use Illuminate\Support\Facades\Route;

Route::get('/post-editor-bootstrap', [PostEditorApiController::class, 'bootstrap']);

Route::get('/post-editor-load/{id}', [PostEditorApiController::class, 'show'])
    ->whereNumber('id');

Route::post('/post-editor-slug', [PostEditorApiController::class, 'slug']);
Route::post('/post-editor-create', [PostEditorApiController::class, 'store']);

Route::post('/post-editor-save/{id}', [PostEditorApiController::class, 'save'])
    ->whereNumber('id');
