<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| HTML Blocks admin API — Lane BC
|------------------------------------------------------------------------------
|
| NOT YET LOADED. CLAUDE.md forbids this lane from editing routes/web.php, so
| the integrator wires it up. One line, inside the EXISTING admin-api group in
| routes/web.php — the one opened at around line 263 by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(...)
|
| which itself sits inside `Route::middleware('auth:admin')`. Put it beside the
| brands-admin require, at around line 323:
|
|     require __DIR__.'/html-blocks-admin.php';
|
| IT MUST GO INSIDE THAT GROUP, and the reason is sharper here than for most
| lanes. `content` is HTML that store/page.blade.php and store/post.blade.php
| render UNESCAPED into the storefront. An unguarded POST to /admin-api/blocks
| is therefore not "a stranger editing some content" — it is stored
| cross-site scripting on every page that places the block, injected by
| anybody who can reach the URL. There is no per-route authorisation inside
| Admin\BlocksApiController; this mount is the whole guard.
|
| They are deliberately NOT in routes/api.php: everything there is
| unauthenticated by design (CLAUDE.md, "/api/* is unauthenticated"). Nothing
| about blocks is published under /api/* at all — the storefront needs no
| endpoint for them, because a block reaches a shopper already rendered into
| the page's HTML by App\Support\Shortcodes, server-side.
|
| Resulting paths:
|
|     GET    /admin-api/blocks              list, with a "used in" count each
|     POST   /admin-api/blocks              create
|     GET    /admin-api/blocks/{block}      one block, with its content
|     PUT    /admin-api/blocks/{block}      update
|     DELETE /admin-api/blocks/{block}      delete (refused while in use
|                                           unless ?force=1)
|
| The {block} constraint is [0-9]+ so a bad id is a 404 from the router rather
| than a model-binding failure further in, and so these cannot shadow a literal
| path segment added here later.
|
| A clear_caches migration ships with this package
| (2026_10_09_000001_clear_caches_html_blocks) because these routes are new and
| the server serves a COMPILED route cache — without it every path above is a
| 404 on the live host no matter how correctly this file is wired.
|
*/

use App\Http\Controllers\Admin\BlocksApiController;
use Illuminate\Support\Facades\Route;

Route::get('/blocks', [BlocksApiController::class, 'index']);
Route::post('/blocks', [BlocksApiController::class, 'store']);
Route::get('/blocks/{block}', [BlocksApiController::class, 'show'])->where('block', '[0-9]+');
Route::put('/blocks/{block}', [BlocksApiController::class, 'update'])->where('block', '[0-9]+');
Route::delete('/blocks/{block}', [BlocksApiController::class, 'destroy'])->where('block', '[0-9]+');
