<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Route;

/**
 * Mount routes/newsletter-public.php for a test.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the route file
 * ships unmounted with wiring instructions in its header and the integrator
 * adds the one-line require. That leaves the same gap MailRoutesTest was
 * written to close: without this, every test of the confirm and unsubscribe
 * flows would be testing a 404, and the first time anyone found out the flow
 * worked would be after it shipped.
 *
 * WHY THE `web` GROUP AND NOT A BARE REGISTRATION. The two POSTs are the acting
 * half of a GET-then-POST pair and are protected by @csrf. Registered without
 * the `web` middleware there is no session and no CSRF verification, so a test
 * asserting "the POST does the work" would pass while the shipped route — which
 * WILL carry that middleware — rejected the same request. Mounting it the way
 * routes/web.php will mount it is the whole point.
 *
 * Route::fallback() at routes/web.php:724 does not swallow these: Laravel's
 * RouteCollection::matchAgainstRoutes() partitions fallbacks out and tries them
 * only after every ordinary route, whenever they were registered.
 */
final class NewsletterRoutes
{
    public static function wire(): void
    {
        /*
         * The HTTP kernel first.
         *
         * The middleware aliases — 'throttle', and the 'web' group itself —
         * are registered on the router by the kernel's constructor and nowhere
         * else. MailRoutesTest's helper documents the same requirement and the
         * same failure mode: without this the pipeline resolves 'throttle' to
         * something that is not middleware and the test fails with a 500 that
         * looks like a bug in the lane rather than a missing alias.
         */
        app(\Illuminate\Contracts\Http\Kernel::class);

        Route::middleware('web')->group(base_path('routes/newsletter-public.php'));

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
}
