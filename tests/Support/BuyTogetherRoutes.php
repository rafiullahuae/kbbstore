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
 * Mounts routes/buy-together.php the way the integrator is told to. (Lane RB)
 *
 * CLAUDE.md forbids a lane from editing routes/web.php, so the two POSTs ship
 * in their own file with the require line in its header. This registers that
 * file under the storefront's `web` stack, which is exactly where the require
 * puts it, so the cart endpoint's validation, CSRF and throttle are tested
 * before the integrator wires it — rather than asserting the absence of the
 * require, which CLAUDE.md records going red the moment the wiring lands.
 *
 * Idempotent, and a no-op once routes/web.php requires the file itself.
 * Modelled on Tests\Support\UgcAdminRoutes, whose notes on the compiled route
 * collection and the reclaimed container apply here unchanged.
 */
final class BuyTogetherRoutes
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

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            if ($existing->uri() === 'api/cart/add-together') {
                return;
            }
        }

        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware('web')->group(base_path('routes/buy-together.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
