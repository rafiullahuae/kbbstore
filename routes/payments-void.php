<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Releasing an authorisation nobody captured — the endpoint, for every gateway
|------------------------------------------------------------------------------
|
| MOUNTED. routes/web.php requires this file from inside the EXISTING `admin-api`
| group, beside the other payment requires.
|
| WHY THIS FILE EXISTS AT ALL, which is the interesting part.
|
| Two lanes built these two routes in the same round, one for Tamara and one for
| Tabby, and registered them in their own gateway's route file. Both mounted, the
| shop would have registered `POST /admin-api/orders/{id}/void` TWICE — Laravel
| takes the last one, so whichever require came second would silently decide
| which controller method served every release in the shop, and the loser's tests
| would keep passing against a method nothing called. That is the "mounted twice"
| failure routes/RouteFileHeadersTest guards the header half of.
|
| It would also have been wrong in the ordinary way: this endpoint names no
| provider anywhere. PaymentVoidController resolves the order, PaymentVoider asks
| the registry whether that order's gateway implements VoidsAuthorisation, and
| Tamara and Tabby both do. Filing a provider-agnostic route under one provider's
| name is how the next gateway's author concludes they need a third copy.
|
| IT MUST STAY INSIDE THE `auth:admin` GROUP. Unguarded, POST here is a way for a
| stranger to cancel the payment plan behind every cancelled order in the shop,
| and GET is a way to enumerate which orders are holding money. Both are mapped in
| App\Support\AdminCapabilities to `orders.money`, the same capability as capture
| and refund; that file's own note explains why it is not a capability of its own
| and why the read is not `orders.view`.
|
|     GET  /admin-api/orders/{id}/void   can this hold be released, and why not
|                                        if not. Reads only, calls no provider.
|     POST /admin-api/orders/{id}/void   release it.
|
| The `clear_caches_*` migrations that clear the compiled route cache for these
| two paths ship with the two gateway packages
| (2026_09_27_000100_clear_caches_tamara_gateway.php and the Tabby void-tracking
| migration). Without one of them the live host's route cache knows neither path
| and both 404 while the button renders perfectly — which has shipped twice on
| this project already.
|
*/

use App\Http\Controllers\Admin\PaymentVoidController;
use Illuminate\Support\Facades\Route;

Route::get('/orders/{id}/void', [PaymentVoidController::class, 'show'])
    ->where('id', '[0-9]+');

Route::post('/orders/{id}/void', [PaymentVoidController::class, 'void'])
    ->where('id', '[0-9]+');
