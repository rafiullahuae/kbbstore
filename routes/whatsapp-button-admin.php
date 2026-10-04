<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → WhatsApp button  (Lane WA)
|------------------------------------------------------------------------------
|
| The floating WhatsApp button: its design, size, the space from all four
| sides on phone and desktop, the link and the lines it sends, the capsule and
| the welcome bubble. App\Services\WhatsAppButton carries the schema, the link
| and every byte the storefront prints.
|
| INTEGRATOR: this file is a `require`, like routes/page-wash-admin.php. Add
| the last line below to routes/web.php, inside the SAME admin-api group,
| directly under the page-wash require that is already there:
|
|     require __DIR__.'/page-wash-admin.php';
|     require __DIR__.'/whatsapp-button-admin.php';      <-- this file
|
| Resulting paths:
|
|     GET  /admin-api/whatsapp-button    the fields in four tabs, plus what the
|                                        live preview is built from
|     POST /admin-api/whatsapp-button    save
|
| THAT GROUP AND NOTHING ELSE — it carries `web`, `auth:admin` and
| NoStoreAdminApi. This POST writes a link that becomes an `href` on every page
| of the shop.
|
| CAPABILITY. `wabutton.manage`, held by owner, manager and editor — the same
| three as every other Appearance screen — and its own, so narrowing one never
| narrows another. App\Support\AdminCapabilities fails closed.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships with
| database/migrations/2027_07_31_200000_clear_caches_whatsapp_button.php.
*/

use App\Http\Controllers\Admin\WhatsAppButtonApiController;
use Illuminate\Support\Facades\Route;

Route::get('/whatsapp-button', [WhatsAppButtonApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.whatsapp-button');

Route::post('/whatsapp-button', [WhatsAppButtonApiController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.whatsapp-button.save');
