<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Registers Lane HB's two route files for a test, exactly as routes/web.php will
 * once the integrator requires them.                                (Lane HB)
 *
 * The same recipe as Tests\Support\UgcAdminRoutes, and for the reason CLAUDE.md
 * gives: a lane may not edit routes/web.php and must not assert that its own
 * require is ABSENT (that assertion goes red the moment it is wired). So the
 * tests register the group themselves, and are green on both sides of the
 * integrator's two-line change — wire() is idempotent and does nothing when the
 * routes are already there.
 */
final class SpottedRoutes
{
    public static function wire(Application $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));

        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();
        $public = false;
        $admin = false;

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            $public = $public || $existing->uri() === 'kbeautybliss-spotted';
            $admin = $admin || $existing->uri() === 'admin-api/spotted';
        }

        if ($public && $admin) {
            return;
        }

        // A compiled collection does not match routes added at runtime; a plain
        // one does (UgcAdminRoutes carries the measurement). And OURS GO FIRST:
        // routes/web.php ends in catch-all content routes, and the integrator's
        // require sits above them, so a route appended after them here would be
        // shadowed and answer 404 -- a test of something the shop never does.
        $previous = $router->getRoutes();
        $router->setRoutes(new RouteCollection());

        if (! $public) {
            RouteFacade::middleware('web')->group(base_path('routes/spotted.php'));
        }

        if (! $admin) {
            RouteFacade::middleware(UgcAdminRoutes::STACK)
                ->prefix('admin-api')
                ->group(base_path('routes/spotted-admin.php'));
        }

        foreach ($previous as $route) {
            $router->getRoutes()->add($route);
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
