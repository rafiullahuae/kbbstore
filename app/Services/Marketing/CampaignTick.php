<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Email Marketing's heartbeat — Lane EK.
 *
 * The shop runs on a shared host with no queue worker, and until somebody adds
 * the one cron line, no scheduler either. A web request is the only thing that
 * runs PHP. So, exactly like App\Services\Mail\OrderReminderTick (whose header
 * is the long version of this argument): at the end of a request, if the
 * marker file is older than INTERVAL seconds, touch it and — after the response
 * has gone, so no shopper waits for SMTP — send the next batch.
 *
 * What a request pays when nothing is due: one filemtime(). What a due tick
 * pays when no campaign is sending or scheduled: one indexed query. The batch
 * itself is CampaignSender::sweep(), which claims each recipient row before it
 * sends to it, takes a cache lock so two ticks cannot sweep at once, respects
 * the per-day cap, and stops after its time budget — so a slow mail server
 * cannot turn one shopper's page view into a 60-second PHP process.
 *
 * With the cron line installed (`kbb:campaigns-send` every minute, see
 * routes/console.php) both run; the claim makes that harmless.
 *
 * tests/Pest.php touches the marker before every test, for the reason it gives
 * for the order-reminder heartbeat: a sweep fired by the wall clock in a random
 * test would add queries to tests that count them.
 */
class CampaignTick
{
    public const INTERVAL = 20;

    public static function register(): void
    {
        Event::listen(RequestHandled::class, static function (): void {
            try {
                (new self)->onRequest();
            } catch (\Throwable) {
                // Deliberately silent: a heartbeat must never fail a page.
            }
        });
    }

    public static function markerPath(): string
    {
        return storage_path('framework/kbb-campaigns.tick');
    }

    public function onRequest(): bool
    {
        $marker = self::markerPath();
        $last = @filemtime($marker);

        if ($last !== false && $last > time() - self::INTERVAL) {
            return false;
        }

        @touch($marker);

        $done = false;

        app()->terminating(static function () use (&$done): void {
            if ($done) {
                return;
            }

            $done = true;

            try {
                app(CampaignSender::class)->sweep();
            } catch (\Throwable $e) {
                Log::warning('campaign heartbeat failed', ['exception' => class_basename($e)]);
            }
        });

        return true;
    }
}
