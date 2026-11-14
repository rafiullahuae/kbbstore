<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Registers routes/quiqup-timeline.php inside the web group exactly as the
 * integrator will mount it (top level of routes/web.php), for the tests -- the
 * QuiqupRoutes / UgcAdminRoutes pattern. Idempotent, and it never asserts
 * that routes/web.php does NOT require the file: that would go red the day
 * the integrator wires it.
 */
final class QuiqupTimelineRoutes
{
    public static function wire(Application $app): void
    {
        $app->make(\Illuminate\Contracts\Http\Kernel::class);
        $router = $app->make('router');

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            if ($existing->uri() === 'track-my-order/live') {
                return;
            }
        }

        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);
        RouteFacade::middleware('web')->group(base_path('routes/quiqup-timeline.php'));
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
