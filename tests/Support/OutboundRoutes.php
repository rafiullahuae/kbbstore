<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Route;

/**
 * Mount routes/outbound-public.php and routes/outbound-admin.php for a test.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the route files
 * ship unmounted with wiring instructions in their headers and the integrator
 * adds the two one-line requires. That leaves the gap MailRoutesTest and
 * NewsletterRoutes were both written to close: without this, every test of the
 * notify-me form and the unsubscribe flow would be testing a 404, and the first
 * time anybody found out whether the flow worked would be after it shipped.
 *
 * WHY THE `web` GROUP AND NOT A BARE REGISTRATION. /cart/remind-me reads the
 * cart COOKIE, and the two POSTs on the opt-out are the acting half of a
 * GET-then-POST pair protected by @csrf. Registered without the `web`
 * middleware there is no session, no cookie and no CSRF verification, so a test
 * asserting "the POST does the work" would pass while the shipped route — which
 * WILL carry that middleware — rejected the same request. Mounting it the way
 * routes/web.php will mount it is the whole point.
 */
final class OutboundRoutes
{
    public static function wire(): void
    {
        /*
         * The HTTP kernel first. The middleware aliases — 'throttle', and the
         * 'web' and 'auth' groups — are registered on the router by the
         * kernel's constructor and nowhere else. Without this the pipeline
         * resolves 'throttle' to something that is not middleware and the test
         * fails with a 500 that looks like a bug in the lane.
         */
        app(\Illuminate\Contracts\Http\Kernel::class);

        Route::middleware('web')->group(base_path('routes/outbound-public.php'));

        /*
         * The admin half, mounted with the prefix and the guard routes/web.php
         * gives it, so an authorisation test here is testing the real thing.
         */
        Route::middleware(['web', 'auth:admin'])
            ->prefix('admin-api')
            ->group(base_path('routes/outbound-admin.php'));

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
}
