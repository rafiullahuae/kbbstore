<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\NoStoreAdminApi;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Mounts routes/order-detail-admin.php (Lane PU) for the tests, under the exact
 * stack routes/web.php's admin-api group applies, the way UgcAdminRoutes does
 * for its file. CLAUDE.md: a lane that needs its routes before the integrator
 * mounts them registers the group in the test rather than asserting the
 * require is absent.
 *
 * IDEMPOTENT, and that matters twice: several cases call it, and once the
 * integrator adds the require to routes/web.php the routes are already there --
 * registering them a second time would make the name and action lookups
 * ambiguous. So it registers nothing when the first path is already routed.
 */
final class OrderDetailAdminRoutes
{
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public const PROBE = 'admin-api/orders/{id}/customer-orders';

    public static function wire(Application $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));

        // The `auth` alias is registered by the HTTP kernel's constructor.
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            if ($existing->uri() === self::PROBE) {
                return;
            }
        }

        // A CompiledRouteCollection does not match routes added at runtime.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/order-detail-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
