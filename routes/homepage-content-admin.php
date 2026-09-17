<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → Homepage content  (Lane FO, Phase 15)
|------------------------------------------------------------------------------
|
| NOT MOUNTED YET. CLAUDE.md forbids this lane from editing routes/web.php, so
| these two routes ship in their own file. The integrator adds ONE line, inside
| the EXISTING admin-api group, directly under the three homepage routes that
| are already there (routes/web.php, around line 542):
|
|     Route::post('/homepage/layout', [\App\Http\Controllers\Admin\HomepageApiController::class, 'applyLayout']);
|     require __DIR__ . '/homepage-content-admin.php';
|
| When that line lands, this header must stop saying the routes are unmounted —
| tests/Feature/RouteFileHeadersTest.php fails any route file that web.php
| requires while the file still describes itself that way. Rewrite the sentence
| rather than quoting the old one: the guard reads the whole file, and a quoted
| claim is the claim as far as a regex is concerned.
|
| THAT GROUP AND NO OTHER. These routes WRITE the words on the shop's front
| page, including the page's only <h1>. /api/* in this app is unauthenticated by
| design, so mounting them there would let anyone on the internet rewrite the
| storefront's headline. The group routes/web.php already applies — `web`,
| `auth:admin`, NoStoreAdminApi — is the whole refusal.
|
| NO CAPABILITY RULE IS ADDED, and that is checked rather than assumed. These
| sit under `admin-api/homepage/`, which AdminCapabilities::RULES already maps
| with `['*', 'admin-api/homepage/**', 'content.manage']` — the write-before-read
| ordering CLAUDE.md asks for is already satisfied there by a single `*` rule
| covering both verbs. tests/Feature/HomepageContentEditorTest.php pins that the
| map resolves both of these to content.manage, so a later narrowing of that
| wildcard fails here instead of 403ing an owner on a host with no shell.
|
| AND THE ROUTE CACHE. The host runs a compiled route table and has no shell, so
| a route added is inert until the cache is cleared:
| database/migrations/2026_11_18_000003_clear_caches_homepage_content.php is the
| migration that does it, and it ships in the same package as this file.
*/

use App\Http\Controllers\Admin\HomepageApiController;
use Illuminate\Support\Facades\Route;

Route::get('/homepage/content', [HomepageApiController::class, 'content']);
Route::post('/homepage/content', [HomepageApiController::class, 'saveContent']);
