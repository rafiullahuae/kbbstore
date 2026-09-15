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
 * Mounts routes/import-admin.php the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the import
 * routes ship in their own file with the require line in the header. Nothing
 * dispatches to the controller until someone wires it up, which leaves the gap
 * every lane before this one hit: a mistake in the guard would not surface until
 * a package shipped an anonymous endpoint that writes a file to the server and
 * then rewrites the catalogue from it.
 *
 * ONE middleware() CALL, NOT TWO. RouteRegistrar::middleware() REPLACES the
 * pending middleware rather than appending to it, so
 *
 *     RouteFacade::middleware('web')->middleware('auth:admin')->group(...)
 *
 * registers routes carrying `auth:admin` and NOT `web` — or, with the order
 * reversed, no guard at all while still reading as though it asked for one. A
 * guard test written against such a harness passes against nothing. The whole
 * stack goes in one array below, and the tests read the middleware back off the
 * registered routes rather than trusting this file.
 *
 * A deliberate sibling of Tests\Support\CustomersAdminRoutes rather than a
 * shared base class: that file belongs to another lane and is not this one's to
 * change.
 */
final class ImportAdminRoutes
{
    /** The exact stack routes/web.php's admin-api group applies. */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        /*
         * Resolve the HTTP kernel first, or the guard is not what it appears.
         * The `auth` middleware ALIAS is registered on the router by
         * Kernel::syncMiddlewareToRouter(), which runs in the kernel's
         * constructor and nowhere else; without it the pipeline resolves the
         * AuthManager instead and the request dies with a 500 — still a
         * refusal, so a 401 assertion would pass for the wrong reason.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        // The router is serving a CompiledRouteCollection here — the migration
        // set runs route:cache — and routes added to one of those are never
        // matched. Copying into a plain RouteCollection first is what makes a
        // route registered at runtime actually dispatch.
        $kept = new RouteCollection;

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/import-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * See the long note in tests/Pest.php: the migration set runs config:cache
     * and route:cache, each of which constructs a throwaway Application and
     * points the container, the facade root and Eloquent's connection resolver
     * at it. Nothing puts them back.
     */
    private static function reclaimContainer(Application $app): void
    {
        Container::setInstance($app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));
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
            ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/import/'))
            ->filter(fn ($r) => str_contains((string) $r->getAction('controller'), 'ImportApiController'))
            ->values()
            ->all();
    }
}
