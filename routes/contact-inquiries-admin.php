<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Inquiries  (Lane CT)
|------------------------------------------------------------------------------
|
| Required from routes/web.php INSIDE the guarded admin-api group (`web`,
| `auth:admin`, NoStoreAdminApi), beside the Quiz Leads line:
|
|     require __DIR__.'/contact-inquiries-admin.php';   // Store → Inquiries (Lane CT)
|
| An inquiry carries a visitor's name, email address and phone number, so
| nothing here may live under /api/*. AdminCapabilities maps every path:
|
|     GET    /admin-api/inquiries                 inquiries.view    the list
|     POST   /admin-api/inquiries/{id}/read       inquiries.view    mark read / unread
|     DELETE /admin-api/inquiries/{id}            inquiries.manage  delete
|     GET    /admin-api/inquiries/settings        inquiries.manage  Contact page settings
|     POST   /admin-api/inquiries/settings        inquiries.manage  save them
|
| A clear_caches_* migration (2027_10_15_120200) ships with this file.
*/

use App\Http\Controllers\Admin\ContactInquiriesApiController;
use Illuminate\Support\Facades\Route;

Route::get('/inquiries/settings', [ContactInquiriesApiController::class, 'settings'])
    ->name('admin.inquiries.settings');
Route::post('/inquiries/settings', [ContactInquiriesApiController::class, 'saveSettings'])
    ->middleware('throttle:30,1')
    ->name('admin.inquiries.settings.save');
Route::get('/inquiries', [ContactInquiriesApiController::class, 'index'])
    ->middleware('throttle:120,1')
    ->name('admin.inquiries');
Route::post('/inquiries/{id}/read', [ContactInquiriesApiController::class, 'read'])
    ->whereNumber('id')
    ->middleware('throttle:120,1')
    ->name('admin.inquiries.read');
Route::delete('/inquiries/{id}', [ContactInquiriesApiController::class, 'destroy'])
    ->whereNumber('id')
    ->middleware('throttle:60,1')
    ->name('admin.inquiries.delete');
