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
 * Mounts routes/seo-back-office.php the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the two routes it
 * adds ship in their own file with the require line in that file's header.
 * Nothing dispatches to either controller until somebody else wires it up —
 * which would otherwise leave the capability guard, the `kind` refusal and the
 * "it writes nothing" property untested until a package shipped them.
 *
 * THIS IS THE ALTERNATIVE CLAUDE.md NAMES BY NAME. Its note on parallel work
 * says a lane that needs its routes registered before they are mounted should
 * "do it the way tests/Support/UgcAdminRoutes.php and HomepagePreviewRoutes do
 * — register the group in the test — rather than by asserting the absence of
 * the require". This file is that, for this lane. SeoBackOfficeWiringTest pins
 * the FINISHED state instead: the require present exactly once.
 *
 * ONE middleware() CALL, NOT TWO — the trap Tests\Support\CustomersAdminRoutes
 * documents at length: RouteRegistrar::middleware() REPLACES the pending
 * middleware rather than appending to it, so chaining two calls registers routes
 * carrying only the second, and a guard test written against that harness passes
 * against nothing. The whole stack goes in one array.
 *
 * Deliberately a sibling of the other Tests\Support route harnesses rather than
 * a shared base class: those files belong to other lanes and are not this one's
 * to change.
 */
final class SeoBackOfficeRoutes
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

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            // Idempotent: several cases in one file call this, and registering
            // the same two routes twice makes the name lookup ambiguous.
            if ($existing->uri() === 'admin-api/seo-preview') {
                return;
            }
        }

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
            ->group(base_path('routes/seo-back-office.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * Every route this lane's file added, so a test can assert over all of them
     * rather than a list it has to remember to keep up to date.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array($r->uri(), ['admin-api/seo-preview', 'admin-api/seo-tasks'], true))
            ->values()
            ->all();
    }

    /**
     * See the long note in tests/Pest.php: the migration set runs config:cache
     * and route:cache, each of which constructs a throwaway Application and
     * points the container, the facade root and Eloquent's connection resolver
     * at it. Nothing puts them back, so anything reaching for app() afterwards
     * can be talking to a discarded application.
     */
    private static function reclaimContainer(Application $app): void
    {
        Container::setInstance($app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));
    }
}
