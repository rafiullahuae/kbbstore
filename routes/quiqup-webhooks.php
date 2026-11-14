<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Quiqup webhook receiver (Lane QQB, QUIQUP-PLAN §5.2)
|------------------------------------------------------------------------------
|
| Mounted by the integrator (2.60.665) with ONE line in routes/api.php, beside
| payments-webhooks.php -- the api group
| carries no CSRF (Quiqup is a server with no session) and already applies
| SecurityHeaders:
|
|     require __DIR__.'/quiqup-webhooks.php';
|
| Live URL (empty base path on kbeautybliss.com), shown with a Copy button in
| Store -> Quiqup -> Connection:
|
|     POST https://kbeautybliss.com/api/shipping/quiqup/webhook/{url secret}
|
| The path contains "webhook", so Firewall and BlockGate already exempt it.
| throttle:600,1 -- a 429 is something Quiqup retries, so the throttle never
| loses an event outright. The 2028_02_10_100200_clear_caches_quiqup_foundation
| migration ships with the package that adds this file.
*/

use App\Http\Controllers\Quiqup\QuiqupWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/shipping/quiqup/webhook/{secret}', [QuiqupWebhookController::class, 'handle'])
    ->where('secret', '[A-Za-z0-9_-]{32,64}')
    ->middleware('throttle:600,1')
    ->name('quiqup.webhook');
