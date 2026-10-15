<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Customers → (a customer) → Edit email / phone  (Lane CE)
|------------------------------------------------------------------------------
|
| Required ONCE, from the end of routes/customers-admin.php, which is itself
| required inside the admin-api group in routes/web.php (auth:admin,
| NoStoreAdminApi, the web group's CSRF). So no line in routes/web.php is
| needed, and these routes can never be mounted outside that group by
| accident: they rewrite a customer's sign-in address and send mail.
|
|     GET  /admin-api/customers/{id}/contact              the dialog's data
|     POST /admin-api/customers/{id}/contact              save the correction
|     POST /admin-api/customers/{id}/resend-confirmation  re-send one receipt
|
| All three are `customers.edit` in AdminCapabilities::RULES (owner, manager).
|
| EACH WRITE HAS ITS OWN NAMED LIMITER. An unnamed `throttle:N,1` shares one
| counter with every other unnamed throttle on the same signature, so a busy
| screen elsewhere would eat this one's allowance (routes/checkout-line.php,
| 'checkout-coupon', is where that was paid for). The resend is the tighter of
| the two: it sends mail to a customer.
|
*/

use App\Http\Controllers\Admin\CustomerContactController;
use Illuminate\Support\Facades\Route;

Route::get('/customers/{id}/contact', [CustomerContactController::class, 'show'])
    ->whereNumber('id');

Route::post('/customers/{id}/contact', [CustomerContactController::class, 'update'])
    ->whereNumber('id')
    ->middleware('throttle:30,1,customer-contact');

Route::post('/customers/{id}/resend-confirmation', [CustomerContactController::class, 'resend'])
    ->whereNumber('id')
    ->middleware('throttle:6,1,customer-resend');
