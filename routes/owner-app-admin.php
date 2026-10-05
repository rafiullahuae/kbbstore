<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Platform → Users & Roles → Owner app (Lane MAC)
|------------------------------------------------------------------------------
|
| Wired by tools/mac-wire.php: ONE line INSIDE the existing admin-api group (the
| one carrying `auth:admin` and NoStoreAdminApi), directly below the
| admin-roles.php require it sits beside:
|
|     require __DIR__.'/owner-app-admin.php';   // Users & Roles → Owner app: access, PINs, devices (Lane MAC)
|
| Every path maps to `ownerapp.manage` (AdminCapabilities::RULES), Full Admin
| only by default, failing closed for every other role.
|
|     GET    /admin-api/owner-app                          members, devices, sign-ins, settings, address
|     PUT    /admin-api/owner-app/members/{adminUserId}    access on/off, set a PIN, notifications
|     POST   /admin-api/owner-app/devices/{id}/revoke      sign a phone out for good
|     PUT    /admin-api/owner-app/settings                 idle time, low-stock line, loading-bar minutes
|     POST   /admin-api/owner-app/address                  a new secret address (the old one dies)
|     PUT    /admin-api/owner-app/security                 own host, lock-screen text (Lane SEC; Full Admin)
|
| "Unlock now" (both PIN ladders, including the admin-only lock) is
| PUT members/{id} with {unlock: true}.
*/

use App\Http\Controllers\Admin\OwnerAppAdminController;
use Illuminate\Support\Facades\Route;

Route::get('/owner-app', [OwnerAppAdminController::class, 'index']);
Route::put('/owner-app/members/{id}', [OwnerAppAdminController::class, 'member'])->whereNumber('id')->middleware('throttle:30,1,oa-admin-pin');
Route::post('/owner-app/devices/{id}/revoke', [OwnerAppAdminController::class, 'revoke'])->whereNumber('id');
Route::put('/owner-app/settings', [OwnerAppAdminController::class, 'settings']);
Route::post('/owner-app/address', [OwnerAppAdminController::class, 'address'])->middleware('throttle:10,1,oa-admin-address');
Route::put('/owner-app/security', [OwnerAppAdminController::class, 'security'])->middleware('throttle:10,1,oa-admin-security');

/*
|------------------------------------------------------------------------------
| Users & Roles → Owner app → Customise app (Lane OA4)
|------------------------------------------------------------------------------
|
|     GET    /admin-api/owner-app/ui    look, screens, functions; defaults; options
|     PUT    /admin-api/owner-app/ui    the whole card, validated (422 on a bad accent)
|
| Same capability as the rest of this file (ownerapp.manage via
| admin-api/owner-app/**). Shipped with 2027_08_25_100440_clear_caches_owner_app_ui.
*/
Route::get('/owner-app/ui', [\App\Http\Controllers\Admin\OwnerAppUiAdminController::class, 'show']);
Route::put('/owner-app/ui', [\App\Http\Controllers\Admin\OwnerAppUiAdminController::class, 'save'])->middleware('throttle:30,1,oa-admin-ui');
