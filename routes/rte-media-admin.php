<?php

/*
|--------------------------------------------------------------------------
| Picture galleries in the admin's rich-text boxes (16 October 2026)
|--------------------------------------------------------------------------
|
| GET /admin-api/media/link-targets  products, categories, brands and articles
|     a gallery picture can link to, asked for once per visit by
|     admin/partials/rte-media.blade.php. Under media/**, so it needs
|     content.manage (App\Support\AdminCapabilities), like the Media Library.
|     A literal segment: /media/{media} only matches digits.
|
| Shipped with a clear_caches migration (CLAUDE.md: a route added to a cached
| route table does not exist until the cache is cleared).
*/

use App\Http\Controllers\Admin\RteLinkTargetsController;
use Illuminate\Support\Facades\Route;

Route::get('/media/link-targets', [RteLinkTargetsController::class, 'index']);
