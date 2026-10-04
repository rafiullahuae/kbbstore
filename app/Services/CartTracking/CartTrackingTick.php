<?php

declare(strict_types=1);

namespace App\Services\CartTracking;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Cart Tracking's retention heartbeat.                             (Lane CT)
 *
 * The pattern App\Services\Mail\OrderReminderTick set: on a host that may
 * have no cron line, the sweep rides on the tail of ordinary requests. What a
 * request pays is ONE stat() of a marker file; only when the marker is older
 * than INTERVAL (six hours) does a request claim the lock, touch the marker
 * and run the sweep — and then AFTER its response has been sent
 * (app()->terminating), with a budget of BATCHES × 2,000 rows.
 *
 * `php artisan kbb:cart-tracking-prune` (daily on the scheduler, routes/
 * console.php) does the same job where cron exists; both touch the marker, so
 * whichever runs first holds the other off.
 */
final class CartTrackingTick
{
    public const INTERVAL = 21600;

    public const BATCHES = 5;

    public const LOCK_KEY = 'kbb.cart-tracking.tick';

    public static function register(): void
    {
        Event::listen(RequestHandled::class, static function (): void {
            try {
                (new self)->onRequest();
            } catch (\Throwable) {
            }
        });
    }

    public static function markerPath(): string
    {
        return storage_path('framework/kbb-cart-tracking.tick');
    }

    public function onRequest(): bool
    {
        $marker = self::markerPath();
        $last = @filemtime($marker);

        if ($last !== false && $last > time() - self::INTERVAL) {
            return false;
        }

        if (! Cache::add(self::LOCK_KEY, time(), 300)) {
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
                app(CartTrackingPrune::class)->run(self::BATCHES);
            } catch (\Throwable $e) {
                Log::warning('cart tracking retention failed', ['exception' => class_basename($e)]);
            }
        });

        return true;
    }
}
