<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Platform → Users & Roles — the Roles and Members tabs (Lane RL, plan row 53)
|------------------------------------------------------------------------------
|
| CLAUDE.md forbids a lane to edit routes/web.php, so the integrator wires this
| file. ONE line, INSIDE the existing admin-api group (the one carrying
| `auth:admin` and NoStoreAdminApi), directly below the four
| `Route::...('/users'...)` lines it sits beside:
|
|     require __DIR__.'/admin-roles.php';   // Platform → Users & Roles → Roles / Members (Lane RL)
|
| Inside that group because every one of these decides who can do what in the
| back office. Nothing here is on /api/*.
|
| Resulting paths and the capability AdminCapabilities::RULES maps each to —
| both Full-Admin-only by default, both failing closed for every other role:
|
|     GET    /admin-api/roles                    roles.manage
|     POST   /admin-api/roles                    roles.manage   new custom role
|     PUT    /admin-api/roles/{id}               roles.manage   rename / re-tick
|     POST   /admin-api/roles/{id}/restore       roles.manage   preset back to default
|     DELETE /admin-api/roles/{id}               roles.manage   custom, unused roles only
|     GET    /admin-api/roles/members            users.manage
|     POST   /admin-api/roles/members            users.manage   new account on a role
|     PUT    /admin-api/roles/members/{id}       users.manage   role, tweaks, title, password
|     DELETE /admin-api/roles/members/{id}       users.manage
|     POST   /admin-api/presence/beat            presence.view      heartbeat, ~15s while visible
|     POST   /admin-api/presence/take            presence.takeover  Take over a record
|     POST   /admin-api/presence/release         presence.view      the tab is leaving
|
| Shipped with database/migrations/2027_07_31_100100_clear_caches_admin_roles.php,
| because a route added to a cached route table does not exist until the cache
| is cleared (CLAUDE.md).
*/

use App\Http\Controllers\Admin\AdminRolesController;
use App\Http\Controllers\Admin\EditPresenceController;
use Illuminate\Support\Facades\Route;

Route::get('/roles/members', [AdminRolesController::class, 'members']);
Route::post('/roles/members', [AdminRolesController::class, 'createMember'])->middleware('throttle:30,1');
Route::put('/roles/members/{id}', [AdminRolesController::class, 'updateMember'])->whereNumber('id');
Route::delete('/roles/members/{id}', [AdminRolesController::class, 'deleteMember'])->whereNumber('id');

Route::get('/roles', [AdminRolesController::class, 'index']);
Route::post('/roles', [AdminRolesController::class, 'store'])->middleware('throttle:30,1');
Route::put('/roles/{id}', [AdminRolesController::class, 'update'])->whereNumber('id');
Route::post('/roles/{id}/restore', [AdminRolesController::class, 'restore'])->whereNumber('id');
Route::delete('/roles/{id}', [AdminRolesController::class, 'destroy'])->whereNumber('id');

// Edit presence (App\Support\EditPresence). The throttle keys on the IP here (the
// admin guard is not the default one), so it allows a small office: ~30 tabs.
Route::post('/presence/beat', [EditPresenceController::class, 'beat'])->middleware('throttle:120,1');
Route::post('/presence/take', [EditPresenceController::class, 'take'])->middleware('throttle:120,1');
Route::post('/presence/release', [EditPresenceController::class, 'release'])->middleware('throttle:120,1');
