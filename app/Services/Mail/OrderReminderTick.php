<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * The "Complete your order" reminders' heartbeat, for a host with no cron
 * (Lane RL).
 *
 * The scheduler (`kbb:order-reminders`, routes/console.php) is the exact
 * driver, and it needs one crontab line the owner may not have added. Without
 * it nothing would ever run, so this runs the same sweep after an ordinary
 * page request has been answered: at most once a minute, at most BUDGET
 * messages, never while a shopper waits.
 *
 * WHAT A REQUEST COSTS. One stat() of a marker file in storage/framework —
 * no query, no cache read. Only when the marker is a minute old does it take a
 * cache lock (Cache::add, atomic, so two simultaneous requests cannot both
 * win), touch the marker, and hand the sweep to app()->terminating(), which
 * runs after the response has gone to the browser. The pattern is
 * ShareImage::makeAfterResponse()'s and OutboundTick's.
 *
 * NOTHING HERE MAY THROW. It is attached to the end of every request in the
 * application, the checkout's included.
 */
class OrderReminderTick
{
    public const INTERVAL = 60;

    public const BUDGET = 10;

    public const LOCK_KEY = 'kbb.order-reminders.tick';

    public static function register(): void
    {
        Event::listen(RequestHandled::class, static function (): void {
            try {
                (new self)->onRequest();
            } catch (\Throwable) {
                // Deliberately silent: see the class header.
            }
        });
    }

    public static function markerPath(): string
    {
        return storage_path('framework/kbb-order-reminders.tick');
    }

    /** True when this request scheduled a sweep. */
    public function onRequest(): bool
    {
        $marker = self::markerPath();
        $last = @filemtime($marker);

        if ($last !== false && $last > time() - self::INTERVAL) {
            return false;
        }

        if (! Cache::add(self::LOCK_KEY, time(), self::INTERVAL)) {
            return false;
        }

        @touch($marker);

        // Once, even if the application object outlives this request (a test
        // client, a long-lived worker): terminating callbacks are never cleared.
        $done = false;

        app()->terminating(static function () use (&$done): void {
            if ($done) {
                return;
            }

            $done = true;

            try {
                app(OrderReminders::class)->sweep(self::BUDGET);
            } catch (\Throwable $e) {
                Log::warning('order reminder heartbeat failed', ['exception' => class_basename($e)]);
            }
        });

        return true;
    }
}
