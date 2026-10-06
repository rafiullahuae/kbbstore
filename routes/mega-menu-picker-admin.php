<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Mega Menu → Add items  (Lane MX)
|------------------------------------------------------------------------------
| HOW THIS FILE IS MOUNTED. One line inside the guarded `admin-api` group in
| routes/web.php, beside the other mega-menu routes:
|
|     require __DIR__.'/mega-menu-picker-admin.php';
|
| CLAUDE.md forbids this lane from editing routes/web.php; docs/mx-wiring.json
| carries the edit and tools/mx-wire.php applies it. The paths sit under
| admin-api/mega-menu/**, so AdminCapabilities already maps both verbs to the
| menu editor's capability (content.manage) — no new capability entry, and
| fail-closed for every role that cannot edit the menu.
|
| Neither path can be shadowed by the existing `/mega-menu/{item}` POST: that
| route is registered with an unconstrained {item}, so `pick` must be matched
| first — which is why the require goes BEFORE the mega-menu block.
*/

use App\Http\Controllers\Admin\MegaMenuPickerController;
use Illuminate\Support\Facades\Route;

Route::get('/mega-menu/sources', [MegaMenuPickerController::class, 'sources']);
Route::post('/mega-menu/pick', [MegaMenuPickerController::class, 'pick']);
