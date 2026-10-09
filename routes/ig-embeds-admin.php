<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Content → Instagram embeds  (Lane IGE)
|------------------------------------------------------------------------------
|
| Paste Instagram post and reel addresses; the shop draws Instagram's own embed
| for each. No API, no login. App\Http\Controllers\Admin\InstagramEmbedsApiController
| carries the checks; App\Services\InstagramEmbeds the rules.
|
|     GET  /admin-api/ig-embeds          the list, the options, the preview kit
|     POST /admin-api/ig-embeds/parse    read a paste (stores nothing)
|     POST /admin-api/ig-embeds          save
|
| REQUIRED FROM routes/web.php BY THE INTEGRATOR (tools/ige-wire.php), inside
| the guarded admin-api group beside instagram-admin.php. CAPABILITY:
| `igembeds.manage` on all three, mapped in AdminCapabilities::RULES — the bare
| path and '/**', because '/**' does not match the bare prefix. An unmapped
| path would be owner-only, so a fourth endpoint added here later fails closed.
|
| Ships with database/migrations/2027_10_15_090100_clear_caches_instagram_embeds.php.
*/

use App\Http\Controllers\Admin\InstagramEmbedsApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/ig-embeds', [InstagramEmbedsApiController::class, 'show'])->name('admin.igembeds');
    Route::post('/ig-embeds/parse', [InstagramEmbedsApiController::class, 'parse'])->name('admin.igembeds.parse');
    Route::post('/ig-embeds', [InstagramEmbedsApiController::class, 'save'])->name('admin.igembeds.save');
});
