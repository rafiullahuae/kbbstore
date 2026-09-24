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
 * Mounts routes/sample-order-admin.php the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the routes ship
 * in their own file with the require line in the header, and nothing dispatches
 * to SampleOrderController until somebody else wires it up. That leaves the gap
 * Tests\Support\InvoiceAdminRoutes was written for and this file copies the
 * answer to: a mistake in the guard would not surface until a package shipped
 * an endpoint that WRITES A ROW INTO `orders` — the table this shop's money is
 * counted from — to whoever asked.
 *
 * ONE middleware() CALL, NOT TWO, for the reason InvoiceAdminRoutes sets out at
 * length: RouteRegistrar::middleware() REPLACES the pending middleware rather
 * than appending, so two chained calls register routes carrying the second
 * stack and not the first, and a guard test written against that harness passes
 * against nothing. SampleOrderTest reads the middleware back off the REGISTERED
 * routes rather than trusting this file's intent.
 *
 * A sibling of InvoiceAdminRoutes rather than a shared base class, for the
 * reason that file gives about OrdersAdminRoutes: it belongs to another lane
 * and is not this one's to change.
 */
final class SampleOrderAdminRoutes
{
    /** The exact stack routes/web.php's admin-api group applies. */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        /*
         * Resolve the HTTP kernel first, or the guard is not what it appears:
         * the `auth` middleware ALIAS is registered on the router by
         * Kernel::syncMiddlewareToRouter(), which runs in the kernel's
         * constructor and nowhere else. Without it the pipeline resolves the
         * AuthManager and the request dies with a 500 — still a refusal, so a
         * guard test would pass for the wrong reason.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        // The router is serving a CompiledRouteCollection here — the migration
        // set runs route:cache — and routes added to one of those are not
        // matched. Copying into a plain RouteCollection first is what makes a
        // route registered at runtime actually dispatch.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/sample-order-admin.php'));

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
            ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/sample-order'))
            ->values()
            ->all();
    }
}
