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
 * Mounts routes/urls-media-admin.php the way the integrator is told to.
 *
 * A deliberate sibling of Tests\Support\ImportAdminRoutes, copied rather than
 * shared: that file belongs to another lane and its STACK constant is that
 * lane's statement about its own endpoints. Two lanes asserting the same thing
 * about two different route files is not duplication worth removing — a shared
 * base class means one lane's edit silently changes what another lane's guard
 * test is testing.
 *
 * ONE middleware() CALL, NOT TWO, for the reason that file states at length:
 * RouteRegistrar::middleware() REPLACES the pending middleware rather than
 * appending, so a chained pair registers routes carrying only the second one —
 * and a guard test written against such a harness passes against nothing.
 */
final class UrlsMediaAdminRoutes
{
    /** The exact stack routes/web.php's admin-api group applies. */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        Container::setInstance($app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));

        // Resolve the kernel first: the `auth` middleware ALIAS is registered
        // on the router by Kernel::syncMiddlewareToRouter() and nowhere else.
        // Without it the pipeline resolves the AuthManager and the request dies
        // with a 500 — still a refusal, so a 401 assertion would pass for the
        // wrong reason.
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        // The migration set runs route:cache, so the router is serving a
        // CompiledRouteCollection, and routes added to one of those are never
        // matched. Copying into a plain RouteCollection is what makes a route
        // registered at runtime actually dispatch.
        $kept = new RouteCollection;

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/urls-media-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * Every route this lane's file added, so a test can assert over all of them
     * rather than over a list it has to remember to keep up to date.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/urls-media/'))
            ->values()
            ->all();
    }
}
